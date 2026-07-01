---
phase: 24-session-revocation-ui
plan: 01
subsystem: auth
tags: [session-revocation, wp-cli, capability-check, rate-limiting, brain-monkey]

# Dependency graph
requires: []
provides:
  - "Sudo_Session::revoke_all_active_sessions(exclude_user_id = 0): int — shared current-site bulk revoke, LIVE-session enumeration via _wp_sudo_expires > time() meta_query, operator-self exclusion"
  - "Sudo_Session::is_session_live(user_id): bool — shared browser-independent liveness predicate"
  - "Admin::revoke_session_core(target, revoker, reason): array — factored per-user revocation guard sequence (cap -> self_target -> target_expired -> rate_limited -> success)"
  - "WP-CLI wp sudo revoke --all now routes through the shared Sudo_Session method"
affects: [24-session-revocation-ui plan 02, 24-session-revocation-ui plan 03]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Cap-first guard ordering to prevent info-disclosure leaks (capless callers never learn target liveness)"
    - "Outcome-struct return value from a private core helper, framing (wp_send_json_*) kept in the public caller"
    - "Shared static predicate (is_session_live) consumed by both a revocation core and (in a later plan) a row-action visibility gate, avoiding duplicated time() comparisons"

key-files:
  created: []
  modified:
    - includes/class-sudo-session.php
    - includes/class-cli-command.php
    - includes/class-admin.php
    - tests/Unit/SudoSessionTest.php
    - tests/Unit/CliCommandTest.php
    - tests/Unit/AdminTest.php

key-decisions:
  - "revoke_all_active_sessions() enumerates via a _wp_sudo_expires > time() (NUMERIC) meta_query, not the old meta-key-presence form, so revoke-all acts on exactly the live set the 'Sudo Active (N)' count advertises — a deliberate behavior change to the CLI (it no longer also clears stale expired meta as a side effect; that's lazy-cleanup's job, not revoke-all's)"
  - "revoke_session_core() check order is cap -> self_target -> target_expired -> rate_limited -> success, with cap checked first so a capless caller can never distinguish target_expired from no_cap"
  - "target_expired consumes no rate slot and calls no deactivate(), keeping it observably distinct from success and narrowing (not closing) the render-to-click race"
  - "The bulk revoke-all enumerator stays gate-free (no cap/rate logic in Sudo_Session) so CLI can call it un-gated; UI revoke-all's own cap+rate wrapper is explicitly deferred to plan 02"

requirements-completed: [REVK-02, REVK-03, REVK-05]

# Metrics
duration: ~25min
completed: 2026-06-30
---

# Phase 24 Plan 01: Shared Session-Revocation Foundation Summary

**Promoted the CLI's private bulk-revoke enumerator into a shared `Sudo_Session::revoke_all_active_sessions()` keyed off a live-session meta_query, added a shared `is_session_live()` predicate, and factored the AJAX revoke-session handler's guard sequence into a single `revoke_session_core()` with a cap-first ordering that prevents leaking target session state to unauthorized callers.**

## Performance

- **Tasks:** 2 of 3 (Task 1, the mandatory Pre-Implementation Design Review, was already complete before this execution segment)
- **Files modified:** 6

## Accomplishments

- `Sudo_Session::revoke_all_active_sessions(int $exclude_user_id = 0): int` — current-site-scoped, enumerates only LIVE sessions (`_wp_sudo_expires > time()`, NUMERIC compare), deactivates each, excludes the operator's own session post-enumeration, returns the count.
- `Sudo_Session::is_session_live(int $user_id): bool` — single shared browser-independent liveness predicate (`_wp_sudo_expires > time()`), distinct from the cookie-bound `is_active()`.
- WP-CLI `wp sudo revoke --all` rewired to call `Sudo_Session::revoke_all_active_sessions()`; the private duplicate enumerator in `CLI_Command` was deleted.
- `Admin::revoke_session_core(int $target_user_id, int $revoker_user_id, string $reason): array` — factored guard sequence (cap -> self-target -> target-liveness -> rate-limit -> deactivate -> audit hook) returning an outcome struct (`no_cap`, `self_target`, `target_expired`, `rate_limited`, `success`); `handle_revoke_session()` now delegates to it and maps outcomes to `wp_send_json_*` responses, including a new distinct message for `target_expired` ("That user no longer has an active sudo session.").

## Files Created/Modified

- `includes/class-sudo-session.php` — added `is_session_live()` and `revoke_all_active_sessions()` in a new "Bulk / shared revocation helpers" section.
- `includes/class-cli-command.php` — removed the private `revoke_all_active_sessions()` enumerator; `revoke()` now calls `Sudo_Session::revoke_all_active_sessions()`.
- `includes/class-admin.php` — extracted `revoke_session_core()` private helper; rewrote `handle_revoke_session()` to verify the nonce, call the core, and map outcomes to AJAX responses (added `self_target`/`target_expired` branches alongside the existing `no_cap`/`rate_limited`/`success`).
- `tests/Unit/SudoSessionTest.php` — added 3 `is_session_live()` cases (live/expired/absent) and 3 `revoke_all_active_sessions()` cases (live-meta_query enumeration + deactivate/count, operator exclusion, empty-set zero-count).
- `tests/Unit/CliCommandTest.php` — updated `test_revoke_all_deactivates_all_users_with_sessions` to assert the new `_wp_sudo_expires > time()` meta_query args instead of the old meta_key-presence form; the 2 `wp_sudo_deactivated` fires for the live set stay asserted.
- `tests/Unit/AdminTest.php` — added 6 `revoke_session_core()` cases via `ReflectionMethod` (no_cap with liveness never consulted, self_target, target_expired with no deactivate/no rate slot, rate_limited, success with audit-hook reason-tag passthrough, per-call rate-slot accounting); updated `test_handle_revoke_session_fires_session_revoked_hook` and `test_handle_revoke_session_blocks_when_rate_limit_exceeded` to stub a live, non-self target now that the core checks liveness and self-target before the rate limit.

## Decisions Made

See `key-decisions` in frontmatter. All decisions were already resolved by the design review captured in the revised 24-01-PLAN.md before this execution segment began; no new architectural decisions were made during Task 2/3 implementation.

## Deviations from Plan

None — Task 2 and Task 3 were implemented exactly as specified in the revised 24-01-PLAN.md interface sketches and behavior/action blocks, including the design-review-driven ordering (cap first), the single `$reason` param (no separate `$surface`), and the gate-free bulk enumerator.

One existing test (`test_handle_revoke_session_blocks_when_rate_limit_exceeded`) required updating beyond what the plan's behavior block explicitly called out: with the new cap -> self_target -> target_expired -> rate_limited ordering, the test's default target/revoker user IDs (both 0) would now hit `self_target` before reaching the rate-limit branch it intends to test. Added a distinct `user_id` POST value and a live-target `get_user_meta` stub so the test continues to exercise the rate-limit path it was written for. This is a direct, in-scope consequence of the plan's own ordering requirement (Rule 1 — bug/test-correctness fix, not a deviation in approach).

## Issues Encountered

None.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- Plan 02 (UI wiring: Users-list row action, revoke-all admin-post handler with its own cap/rate wrapper around `Sudo_Session::revoke_all_active_sessions()`, row-action visibility gated by `is_session_live()`) can now consume the shared contracts landed here directly — no scavenger hunt needed.
- Plan 03 (consolidating the orphaned AJAX action into a single admin-post entry point, removing the Access-tab "Revoke Session" button) can build on `revoke_session_core()` as-is.
- **Commit status:** No commits were created during this execution segment per orchestrator instruction — all changes (6 task files plus this SUMMARY.md) are left as modified/untracked files in the working tree for the orchestrator to stage by pathspec, run through the pre-commit reviewer, and commit per task.

---
*Phase: 24-session-revocation-ui*
*Completed: 2026-06-30*
