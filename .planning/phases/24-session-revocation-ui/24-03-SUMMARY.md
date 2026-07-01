---
phase: 24-session-revocation-ui
plan: 03
subsystem: auth
tags: [session-revocation, access-tab, ajax-removal, brain-monkey]

# Dependency graph
requires:
  - phase: 24-session-revocation-ui plan 01
    provides: "Admin::revoke_session_core() cap-first guard sequence"
  - phase: 24-session-revocation-ui plan 02
    provides: "Users-list row action + admin_post_ handler as the replacement single UI entry point for session revocation"
provides:
  - "Access-tab capability-holder table with the 'Revoke Session' button/column removed; only the capability-revoke control and Grant Capability form remain"
  - "Removal of the orphaned wp_ajax_handle_revoke_session registration, the AJAX_REVOKE_SESSION const, the handle_revoke_session() AJAX wrapper, the revokeSessionAction/sessionRevoked localized-script keys, and the dead wp-sudo-revoke-session JS click handler"
  - "Users-list admin_post_ handler (plan 02) confirmed as the sole UI session-revocation entry point"
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Consolidation-only removal task: delete the superseded UI/AJAX surface and its dead JS/PHP symbols while keeping the shared core (revoke_session_core()) and both admin-post handlers untouched"
    - "Grep-verified zero-live-caller check performed before deleting a symbol, rather than leaving a guarded/unreachable branch behind"

key-files:
  created: []
  modified:
    - includes/class-admin.php
    - admin/js/wp-sudo-admin.js
    - tests/Unit/AdminTest.php

key-decisions:
  - "Removed the entire Access-tab session-revoke <td>/header cell rather than hiding it, since capability administration and session revocation are now permanently separate UI surfaces per REVK-04"
  - "Deleted handle_revoke_session(), AJAX_REVOKE_SESSION, and the wp_ajax_ registration outright (no deprecation shim) after grep confirmed zero remaining callers, per the project's no-test-environment-shims/no-dead-code convention"
  - "Kept revoke_session_core() and both admin-post handlers (per-user row action, revoke-all) as the only two live revocation entry points"
  - "Task 3 (full gate + manual UI verification) executed on the AUTOMATED gate only; manual browser-based verification of REVK-01..05 was explicitly deferred by user choice ('Proceed on automated') to a future browser-capable session — see Deviations/Issues below"

requirements-completed: [REVK-04]

# Metrics
duration: unspecified (automated segment only)
completed: 2026-07-01
---

# Phase 24 Plan 03: Access-Tab Cleanup & AJAX Consolidation Summary

**Removed the Access-tab's redundant "Revoke Session" button/column and deleted the now-orphaned `wp_ajax_handle_revoke_session` registration, AJAX wrapper, and dead JS handler, leaving the plan-02 admin-post path as the single session-revocation entry point — full automated gate green (941 tests, PHPStan L6 + Psalm, PHPCS clean); manual browser UI verification is deferred.**

## Performance

- **Tasks:** 2 of 3 code/removal tasks complete (Task 1, Task 2); Task 3 (checkpoint:human-verify) completed its automated half only
- **Files modified:** 3 (includes/class-admin.php, admin/js/wp-sudo-admin.js, tests/Unit/AdminTest.php)

## Accomplishments

- Access-tab capability-holder table no longer renders a "Revoke Session" button or column; the capability "Revoke" control and the Grant Capability form are untouched.
- Removed the orphaned `add_action( 'wp_ajax_' . self::AJAX_REVOKE_SESSION, ... )` registration, the `AJAX_REVOKE_SESSION` constant, the `handle_revoke_session()` AJAX wrapper method, the `wp_localize_script` keys `revokeSessionAction` and `sessionRevoked`, and the dead `wp-sudo-revoke-session` click handler in `admin/js/wp-sudo-admin.js`.
- Kept `revoke_session_core()` (plan 01) and both admin-post handlers (plan 02: per-user row action, revoke-all) as the sole live revocation code paths — grep-verified zero remaining callers of the removed symbols before deletion.
- Ran the full automated gate: `composer test` (941 tests, 2831 assertions), `composer analyse` (PHPStan level 6 + Psalm clean), `composer lint` clean, and `composer verify:metrics` confirmed in sync (`docs/current-metrics.md` updated in commit `af07dc1`). Confirmed no `WP_SUDO_VERSION` / `readme.txt` Stable tag bump occurred (v4.5 is a GSD milestone, not a product release, per STATE.md Key Decisions).

## Task Commits

Each code task was committed atomically:

1. **Task 1: Remove Access-tab "Revoke Session" button (REVK-04)** - `c40ddde` (feat/refactor) — removed the button/column from the capability-holder table render path; capability-revoke control and Grant Capability form left intact.
2. **Task 2: Remove orphaned wp_ajax_handle_revoke_session + dead symbols; consolidate entry point** - `4fc0203` (refactor) — removed the AJAX registration, `AJAX_REVOKE_SESSION` const, `handle_revoke_session()`, the `revokeSessionAction`/`sessionRevoked` localized-script keys, and the dead `wp-sudo-revoke-session` JS click handler; `revoke_session_core()` and both admin-post handlers kept as the live path.
3. **Task 3: Full gate + manual UI verification (checkpoint:human-verify)** — automated gate portion complete and green (composer test/analyse/lint/verify:metrics); manual browser-based UI verification (REVK-01..05 on a Studio/wp-env multisite) DEFERRED — see "Outstanding: Manual UI Verification" below.

**Plan metadata:** (pending — orchestrator commits this SUMMARY.md and related docs separately)

## Files Created/Modified

- `includes/class-admin.php` — removed the Access-tab session-revoke button `<td>` and its header cell from the capability-holder table render; removed the `wp_ajax_` registration, `AJAX_REVOKE_SESSION` const, and `handle_revoke_session()` method; removed the `revokeSessionAction`/`sessionRevoked` keys from the localized script data.
- `admin/js/wp-sudo-admin.js` — removed the now-dead `wp-sudo-revoke-session` click handler (its only consumer, the Access-tab button, no longer exists).
- `tests/Unit/AdminTest.php` — updated/removed assertions tied to the removed button markup and the AJAX registration (inverted `test_register_adds_revoke_session_ajax_hook` to assert absence, per the plan's interface contract); cap/rate/audit assertions for the live consolidated path were preserved (protecting REVK-03).

## Decisions Made

See `key-decisions` in frontmatter. All decisions follow directly from the plan's interface contract (exact removal sites verified against the live codebase) and the plan 01/02 design review already resolved before this plan began — no new architectural decisions were made during Task 1/2 execution.

The one decision made during this execution segment itself: proceeding past Task 3's checkpoint on the automated gate alone, with manual UI verification explicitly deferred by user choice rather than performed in this session.

## Deviations from Plan

None in the code — Tasks 1 and 2 were implemented exactly as specified in the 24-03-PLAN.md interface sketches (exact removal line ranges, symbol list, and grep-first verify-before-delete approach).

**Task 3 scope split:** the plan's `checkpoint:human-verify` bundles two verification halves — (a) the automated gate (`composer test && composer analyse && composer lint`, plus `composer verify:metrics`) and (b) manual browser-based UI verification of REVK-01..05 on a Studio/wp-env multisite. Half (a) is complete and green. Half (b) requires a browser-capable session (per this project's CLAUDE.md browser/Playwright handoff policy) and was not available in this execution context; the user explicitly chose to proceed on the automated gate alone rather than block plan completion on scheduling a browser session. This is not a Rule 1-4 deviation (no bug, no missing critical functionality, no blocker, no architectural change) — it is a recorded scope gap in verification coverage, tracked below and in STATE.md so it is not silently dropped.

## Issues Encountered

None in the code or automated gate. The one open item is process-level, not a bug:

### Outstanding: Manual UI Verification (REVK-01..05)

- **What's outstanding:** The manual browser-based UI verification steps in 24-03-PLAN.md's Task 3 `<how-to-verify>` — operator-with-cap-and-session, cap-holder-without-session, operator-without-cap, revoke-all confirm/count, and per-path distinct-notice checks — have NOT been performed against a live Studio/wp-env multisite.
- **Why:** No browser-capable session was available in this execution context; per this project's CLAUDE.md, browser/Playwright verification must run from a session started with `claude-playwright` or `claude-browser-handoff`.
- **Disposition:** User explicitly chose "Proceed on automated" rather than block plan completion pending a browser session.
- **Action required before this is truly closed:** Run the Task 3 `<how-to-verify>` manual steps from a browser-capable session against a Studio/wp-env multisite, then update this note (and REQUIREMENTS.md traceability, if any manual-only gap is found) accordingly. Tracked in STATE.md Pending Todos.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- Phase 24 (Session Revocation UI) is now feature-complete across all 3 plans: shared revocation core + CLI rewire (plan 01), Users-list UI (plan 02), and Access-tab cleanup + AJAX consolidation (plan 03). All 5 REVK requirements (REVK-01..05) are implemented and automated-gate-verified.
- Phase 25 (Governance Coverage Panel Fix) depends on Phase 24 touching the same file (`includes/class-admin.php`) and can now proceed sequentially.
- **Before claiming REVK-01..05 fully done end-to-end:** run the deferred manual UI verification from a browser-capable session, per the "Outstanding" note above.
- **Commit status:** Task 1 (`c40ddde`) and Task 2 (`4fc0203`) commits already exist in the working tree's history — this plan's code is fully committed. Only this SUMMARY.md and related `.planning/` doc updates (STATE.md, ROADMAP.md, REQUIREMENTS.md) remain for the orchestrator to stage and commit as a single docs commit.

---
*Phase: 24-session-revocation-ui*
*Completed: 2026-07-01*

## Self-Check: PASSED

- Commits found: c40ddde, 4fc0203
- Files found: includes/class-admin.php, admin/js/wp-sudo-admin.js, tests/Unit/AdminTest.php
- Automated gate status confirmed green (composer test 941/2831, analyse, lint, verify:metrics per af07dc1)
- Manual UI verification (REVK-01..05) confirmed outstanding/deferred — not marked complete
