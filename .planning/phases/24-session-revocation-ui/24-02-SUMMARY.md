---
phase: 24-session-revocation-ui
plan: 02
subsystem: auth
tags: [session-revocation, users-list, admin-notices, wp_admin_notice, brain-monkey]

# Dependency graph
requires:
  - phase: 24-session-revocation-ui plan 01
    provides: "Sudo_Session::is_session_live(), Sudo_Session::revoke_all_active_sessions(exclude_user_id), Admin::revoke_session_core() cap-first guard sequence"
provides:
  - "Admin::user_row_actions(): Users-list 'Revoke sudo session' row action, visible only for the active-session set (Sudo_Session::is_session_live()), never on the operator's own row, absent without the revoke_wp_sudo_sessions cap"
  - "Per-user admin_post_ handler: nonce + cap + operator-sudo-gate -> revoke_session_core(reason='users_list_row_action') -> distinct redirect result code per outcome (no_cap / no-operator-session / self_target / target_expired / success)"
  - "Users-list revoke-all button (restrict_manage_users), absent at active-count 0 or without the cap"
  - "Revoke-all interstitial confirm screen stating the exact current-site active-session count (_n()), Confirm (nonced POST) + Cancel"
  - "Revoke-all perform handler: nonce + cap + operator-sudo-gate -> Sudo_Session::revoke_all_active_sessions(operator) -> exactly ONE rate slot consumed per batch -> single wp_sudo_session_revoked fire (target 0, reason 'revoke_all_ui') -> count-aware redirect"
  - "Admin::select_revoke_result_notice(code, count): centralized code -> [type, message] map covering every result code the handlers emit; null for unknown/absent codes"
  - "Admin::render_revoke_result_notice(): admin_notices callback restricted to users.php, renders via wp_admin_notice() with dismissible + correct role/aria-live (alert/assertive for errors, status/polite for success)"
affects: [24-session-revocation-ui plan 03]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Row-action visibility and admin_post_ handler share the same liveness predicate (Sudo_Session::is_session_live()) as the existing 'Sudo Active (N)' count, so the row gate and the count enumeration never drift"
    - "Cap+rate wrapper for revoke-all lives in the UI perform handler, not in the gate-free Sudo_Session enumerator — keeps CLI's un-gated bulk call intact while giving the UI path its own guardrails (design-review objection C)"
    - "Result codes are produced once by the handlers and consumed once by a centralized notice-selection map, so every code a handler can emit is guaranteed to render a distinct, non-silent notice (REVK-05)"
    - "Large TDD tasks split into a feat commit + a following test commit only to satisfy the pre-commit size gate; test-first discipline (RED before GREEN, watched failing) was still followed in the working tree"

key-files:
  created: []
  modified:
    - includes/class-admin.php
    - tests/Unit/AdminTest.php
    - psalm-baseline.xml

key-decisions:
  - "Task 1 row-action visibility gate order: bail if !wp_sudo_can(cap); bail if row user is the operator; bail if !Sudo_Session::is_session_live(target) — the shared predicate from plan 01, not an inline '> time()' check"
  - "The row action is shown even when the operator has no active sudo session of their own (clicking then fails with the distinct no-operator-session message) — visibility and actionability are deliberately decoupled per REVK-05"
  - "Revoke-all's cap+rate wrapper is implemented in the UI perform handler (process_revoke_all_perform / handle_revoke_all_perform), not in Sudo_Session::revoke_all_active_sessions() — confirms design-review objection C: the bulk enumerator stays gate-free so WP-CLI can keep calling it un-gated"
  - "Revoke-all consumes exactly ONE rate slot per perform (not one per revoked session) — the whole batch counts as a single rate-limited action"
  - "A target that expires mid-batch during revoke-all is silently skipped by the enumeration (not an error); revoke-all's empty/drained set is the natural empty-state (count 0 -> button absent) — the target_expired outcome is a per-user-path concern (Task 1), not a revoke-all error path"
  - "Task 2 was split into a feat commit (9a2c459) and a following test commit (862593a) solely to satisfy the pre-commit size gate; TDD discipline (tests written and watched failing first) was maintained in the working tree despite the commit-order split"
  - "select_revoke_result_notice() returns null for unknown/absent result codes rather than a generic fallback message, so a code with no handler-produced meaning cannot render a fabricated notice"

requirements-completed: [REVK-01, REVK-02, REVK-03, REVK-05]

# Metrics
duration: ~50min
completed: 2026-06-30
---

# Phase 24 Plan 02: Session Revocation UI Summary

**Users-list "Revoke sudo session" row action, a count-gated revoke-all button with an interstitial confirm screen, and a centralized wp_admin_notice() result-code map delivering three distinct blocked-path messages plus success — all consuming the plan-01 shared core with no reimplemented liveness or guard logic.**

## Performance

- **Duration:** ~50 min
- **Tasks:** 3 of 3
- **Files modified:** 3 (includes/class-admin.php, tests/Unit/AdminTest.php, psalm-baseline.xml)

## Accomplishments

- `Admin::user_row_actions()` — "Revoke sudo session" row action on the Users list, gated on `Sudo_Session::is_session_live()` (not the cookie-bound `is_active()`), absent without the cap, never on the operator's own row, present even when the operator has no active sudo session of their own.
- Per-user `admin_post_` handler — nonce + cap + operator-sudo-gate, delegates to `Admin::revoke_session_core()` with the `users_list_row_action` reason tag, maps every core outcome (`no_cap` / `self_target` / `target_expired` / `rate_limited` / `success`) to a distinct redirect result code, including the render-to-click race where the target's session expires before the click lands (no `deactivate()`, no rate slot consumed).
- `render_revoke_all_button()` — absent when the current-site active-session count is 0 or the operator lacks the cap; otherwise a nonced link to the confirm screen, rendered via `restrict_manage_users`.
- `render_revoke_all_confirm_screen()` — nonce + cap gated interstitial stating the exact current-site active-session count via `_n()`, with a primary Confirm (nonced POST) and a Cancel link back to `users.php`.
- `handle_revoke_all_perform()` / `process_revoke_all_perform()` — nonce + cap + operator-sudo-gate, consumes exactly ONE rate slot per perform (not per revoked session), calls `Sudo_Session::revoke_all_active_sessions( $operator_id )` with the operator excluded, fires `wp_sudo_session_revoked` once (target `0`, reason `revoke_all_ui`), redirects with a count-aware success result; an empty live-set redirects as success with count 0.
- `Admin::select_revoke_result_notice( $code, $count )` — centralized code-to-`[type, message]` map covering every result code the Task 1/2 handlers emit (no-cap, no-operator-session, self_target, target-already-expired, rate_limited, success incl. revoke-all count), returning `null` for unknown/absent codes so nothing renders a fabricated notice.
- `Admin::render_revoke_result_notice()` — `admin_notices` callback restricted to `users.php`, sanitizes the result/count query args, renders via `wp_admin_notice()` with `is-dismissible` and correct assistive-tech semantics (`role="alert"`/`aria-live="assertive"` for errors, `role="status"`/`aria-live="polite"` for success).

## Task Commits

Each task was committed atomically (Task 2 split across two commits to satisfy the pre-commit size gate):

1. **Task 1: Row-action visibility gate + per-user admin_post_ handler** - `ca558ee` (feat) — 9 new AdminTest cases (4 visibility, 5 handler incl. the target_expired race); 914 tests green.
2. **Task 2: Revoke-all button + interstitial confirm + perform** - `9a2c459` (feat, production code) + `862593a` (test, 13 AdminTest cases split off the same task for the size gate) — 927 tests green.
3. **Task 3: Three distinct blocked-path messages + success via wp_admin_notice()** - `7845883` (feat) — 16 new AdminTest cases incl. a data-provider for the mapping, a distinctness assertion, and the null/no-notice + off-screen paths; one pre-existing hook-count test updated to `twice()` for the new `admin_notices` hook; 943 tests green.

**Plan metadata:** (pending — orchestrator commits this SUMMARY.md and related docs separately)

_Note: All three tasks were `tdd="true"`; RED (failing tests, watched fail) was completed in the working tree before each GREEN commit above, per the project's test-blocking-commit-gate convention (see CLAUDE.md's "Test-blocking commit gates" TDD note) — the pre-commit reviewer hook re-runs the full suite, so a standalone RED commit is not possible and is not attempted._

## Files Created/Modified

- `includes/class-admin.php` — added `user_row_actions()` (visibility filter), the per-user `admin_post_` handler, `render_revoke_all_button()`, `render_revoke_all_confirm_screen()`, `handle_revoke_all_perform()`/`process_revoke_all_perform()`, `select_revoke_result_notice()`, and `render_revoke_result_notice()` (admin_notices callback).
- `tests/Unit/AdminTest.php` — added 38 new test cases across the three tasks (9 + 13 + 16), covering row-action visibility, per-user handler outcome mapping incl. the target-expired race, revoke-all button/confirm/perform incl. one-rate-slot accounting and operator exclusion, and the centralized notice-selection map incl. distinctness and null-for-unknown-code assertions.
- `psalm-baseline.xml` — +5 entries total (2 in `ca558ee`, 3 in `9a2c459`) for the established `wp_sudo_can` namespace false-positive suppression pattern; `composer analyse` fails without them, matching the pattern already used elsewhere in the codebase.

## Decisions Made

See `key-decisions` in frontmatter — all decisions were resolved by the plan's interface contract and the plan-01 design review (objection C: gate-free enumerator, cap+rate wrapper lives in the UI caller). No new architectural decisions were made during execution; the Task 2 commit split (feat then test) was a mechanical pre-commit-gate accommodation, not a design decision, and TDD's test-first discipline was preserved in the working tree.

## Deviations from Plan

None — all three tasks were implemented exactly as specified in the 24-02-PLAN.md behavior/action blocks: the row-action visibility gate order, the per-user handler's outcome-to-result-code mapping (including the target-expired race), the revoke-all button/confirm/perform sequence with its one-rate-slot accounting and mid-batch-skip semantics, and the centralized notice-selection map covering every produced result code.

The only variance from a strict one-task/one-commit mapping was mechanical: Task 2's production code (`9a2c459`) and its tests (`862593a`) landed as two commits instead of one, solely because the combined diff exceeded the pre-commit size gate. This did not affect TDD discipline (tests were written and watched failing before the implementation, in the working tree) and both commits are commits made as normal execution, not deviation-rule auto-fixes.

## Issues Encountered

None.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- Plan 03 (removing the Access-tab "Revoke Session" button and the orphaned AJAX action, consolidating on `Admin::revoke_session_core()`) can proceed directly: the Users-list surfaces built here are now the sole UI path for session revocation, so the Access-tab button and its AJAX handler are confirmed redundant and safe to remove.
- All REVK-01/02/03/05 success criteria for the UI surface are satisfied: row-action visibility keyed on the live-session set, revoke-all reachable via UI with a confirm step and count-gated absence, every guardrail (cap, rate limit, audit hook) enforced on every path, and every blocked/success path rendering a distinct, non-silent, actionable notice.
- Full gate green at each commit: `composer test` (943 tests at completion), `composer analyse` (PHPStan level 6), `composer lint`, and Psalm all clean; Pre-Commit Reviewer approved each of the 4 commits before it was made.
- **Commit status:** All 4 task commits (`ca558ee`, `9a2c459`, `862593a`, `7845883`) already exist in the working tree's history — this plan's code is fully committed. Only this SUMMARY.md and related `.planning/` doc updates (STATE.md, ROADMAP.md, REQUIREMENTS.md) remain for the orchestrator to stage and commit as a single docs commit.

---
*Phase: 24-session-revocation-ui*
*Completed: 2026-06-30*

## Self-Check: PASSED

- Commits found: ca558ee, 9a2c459, 862593a, 7845883
- Files found: includes/class-admin.php, tests/Unit/AdminTest.php, psalm-baseline.xml
- Methods verified present in includes/class-admin.php: user_row_actions, render_revoke_all_button, render_revoke_all_confirm_screen, process_revoke_all_perform, select_revoke_result_notice, render_revoke_result_notice
