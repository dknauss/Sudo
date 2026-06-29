---
phase: 13-migration-safety-and-governance-audit
plan: 01
subsystem: database
tags: [upgrader, migration, admin-notice, governance, tdd, php]

# Dependency graph
requires:
  - phase: 12-breaking-changes-floor-bump
    provides: "wp_sudo_governance_mode option now inert; compatibility mode removed"
provides:
  - "upgrade_4_0_0() routine deletes wp_sudo_governance_mode on 3.x→4.0.0 boundary"
  - "cleanup_inert_governance_mode_option() clears option on next admin load"
  - "reworked notice: dismissible notice-success one-time fixed confirmation"
  - "do_action('wp_sudo_inert_governance_mode_detected') audit hook"
  - "MIG-05 regression lock: admin surfaces use manage_wp_sudo, not manage_options"
affects:
  - Phase 14 (release readiness will document the new notice behavior)
  - Phase 15 (E2E test environment should not have stale governance-mode option)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Static boolean flag for same-request signaling (admin_init → admin_notices)"
    - "do_action() audit hook as semantically-correct dev signal replacing _doing_it_wrong()"
    - "Clear-on-detection: cleanup method deletes from both option stores, sets flag, fires hook"

key-files:
  created: []
  modified:
    - includes/class-upgrader.php
    - includes/class-admin.php
    - tests/Unit/UpgraderTest.php
    - tests/Unit/AdminTest.php

key-decisions:
  - "Static bool flag (not transient) for same-request admin_init→admin_notices signaling; no redirect intervenes"
  - "do_action('wp_sudo_inert_governance_mode_detected') replaces _doing_it_wrong('wp_sudo_governance_mode') — option name is not callable"
  - "cleanup checks AND deletes from both per-site and sitemeta stores on multisite (historical inconsistency)"
  - "cleanup detection is broadened: any non-false option value triggers delete (not just 'compatibility')"
  - "admin_init does NOT fire under WP-CLI/cron; cleanup is admin-HTTP-only; upgrade_4_0_0() covers non-admin contexts"
  - "MIG-05 verified clean: no bare manage_options access gate in includes/ — only documented break-glass exceptions"

requirements-completed: [MIG-01, MIG-05]

# Metrics
duration: 57min
completed: 2026-06-17
---

# Phase 13 Plan 01: Migration Safety and Governance Audit Summary

**upgrade_4_0_0() deletes the inert wp_sudo_governance_mode option; clear-on-detection admin_init cleanup with static-flag notice and audit action; MIG-05 capability routing locked**

## Performance

- **Duration:** ~57 min
- **Started:** 2026-06-17T06:28:37Z
- **Completed:** 2026-06-17T07:25:48Z
- **Tasks:** 3 (plus design review checkpoint completed before this run)
- **Files modified:** 4

## Accomplishments

- upgrade_4_0_0() appended LAST to UPGRADES map; deletes from both per-site and sitemeta option stores; idempotent (absent option is no-op); wp_roles() priming at line 87 of maybe_upgrade() byte-for-byte unchanged
- cleanup_inert_governance_mode_option() hooked on admin_init priority 1; authority-gated; checks both stores on multisite; sets static flag; fires do_action('wp_sudo_inert_governance_mode_detected') as semantically-correct audit signal
- render_compatibility_mode_notice() rewritten: gated on static flag not option read; renders notice-success + is-dismissible one-time fixed confirmation; _doing_it_wrong() removed entirely
- MIG-05 audit: grep confirms zero bare manage_options access gates outside documented break-glass exceptions; two explicit regression-lock unit tests added
- Test suite grew from 795 to 810 tests (15 new), all green; PHPStan L6 clean

## Task Commits

Each task was committed atomically (TDD RED then GREEN):

1. **Task 1 RED: add failing unit tests for upgrade_4_0_0** - `8f02184` (test)
2. **Task 1 GREEN: add upgrade_4_0_0 to delete inert governance-mode option** - `d758f4f` (feat)
3. **Task 2 RED: update AdminTest for clear-on-detection + reworked notice** - `e01a566` (test)
4. **Task 2 GREEN: rework compatibility notice to one-time fixed confirmation** - `6ab2f3e` (feat)
5. **Task 3: lock manage_wp_sudo capability routing (MIG-05)** - `be71ffb` (test)

_Note: TDD tasks have two commits each (test RED → feat GREEN)_

## Files Created/Modified

- `includes/class-upgrader.php` - Added '4.0.0' => 'upgrade_4_0_0' LAST in UPGRADES map; added private upgrade_4_0_0() method with dual-store delete
- `includes/class-admin.php` - Added $compat_option_cleared static bool; reset in reset_cache(); added cleanup_inert_governance_mode_option() on admin_init pri 1; rewrote render_compatibility_mode_notice() (static-flag-gated, notice-success, is-dismissible, no _doing_it_wrong)
- `tests/Unit/UpgraderTest.php` - 4 new 4.0.0 unit tests; setUp stubs for delete_option/delete_site_option; test_200_deletes_role_version_option updated for dual-delete (upgrade_4_0_0 also runs when stored is 1.2.1)
- `tests/Unit/AdminTest.php` - 11 new tests (cleanup behavior, notice contract, MIG-05 locks); 4 existing notice tests updated to new contract; register hook tests updated for admin_init twice

## Decisions Made

- Static bool flag, not transient: admin_init and admin_notices fire in the same HTTP request with no intervening redirect — no DB write needed.
- do_action() audit hook as dev signal: _doing_it_wrong() requires the first arg to be a callable; 'wp_sudo_governance_mode' is an option key, not a callable — using it as such was semantically incorrect.
- Broadened detection in cleanup: any non-false option value triggers delete, not just 'compatibility'. The value is inert regardless — broadening prevents a site from being stuck with a non-'compatibility' stale value that the old notice would have missed.
- admin_init does NOT fire under WP-CLI/cron: cleanup is admin-HTTP-only by design. upgrade_4_0_0() handles the 3.x→4.0.0 boundary for non-admin contexts. Documented in cleanup method docblock.
- reset_cache() extended to also reset $compat_option_cleared for test hygiene (existing convention — no new reset method invented).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] setUp() baseline stubs for delete_option/delete_site_option in UpgraderTest**
- **Found during:** Task 1 GREEN (running UpgraderTest)
- **Issue:** Adding upgrade_4_0_0() to UPGRADES meant existing maybe_upgrade() tests (with stored versions < 4.0.0) also triggered the new routine, which called delete_option() without it being stubbed. Tests that used Functions\expect('delete_option')->once()->with('wp_sudo_role_version') now failed because delete_option was called twice.
- **Fix:** Added `Functions\when('delete_option')->justReturn(true)` and `Functions\when('delete_site_option')->justReturn(true)` to setUp() as baseline stubs. Tests that need exact count assertions use the alias/capture pattern instead of `Functions\expect()` (to avoid when/expect conflict in Brain\Monkey).
- **Files modified:** tests/Unit/UpgraderTest.php
- **Committed in:** 8f02184 (Task 1 RED commit)

**2. [Rule 1 - Bug] Updated test_200_deletes_role_version_option to handle dual-delete**
- **Found during:** Task 1 GREEN
- **Issue:** The existing test used `Functions\expect('delete_option')->once()->with('wp_sudo_role_version')` but upgrade_4_0_0 now also deletes 'wp_sudo_governance_mode' in the same maybe_upgrade() run.
- **Fix:** Changed to alias/capture pattern that records all delete_option calls and asserts 'wp_sudo_role_version' is in the captured set.
- **Files modified:** tests/Unit/UpgraderTest.php
- **Committed in:** 8f02184 (Task 1 RED commit)

**3. [Rule 1 - Bug] test_register_uses_network_admin_menu_on_multisite expected admin_init once**
- **Found during:** Task 2 GREEN
- **Issue:** Existing multisite register test expected admin_init added once (for register_sections). With cleanup hook on admin_init pri 1, it's now twice.
- **Fix:** Updated to `->twice()` with comment explaining both registrations.
- **Files modified:** tests/Unit/AdminTest.php
- **Committed in:** e01a566 (Task 2 RED commit)

---

**Total deviations:** 3 auto-fixed (all Rule 1 — tests adapting to new production behavior)
**Impact on plan:** All auto-fixes were necessary to keep the existing suite green after adding new functionality. No scope creep.

## Issues Encountered

- Brain\Monkey `when()` and `expect()` conflict for the same function in the same test: when setUp() registers `when('delete_option')`, a test-level `expect('delete_option')->once()` does not properly override it for count assertions. Resolved by using the alias/capture pattern in new 4.0.0 tests instead of `expect()`.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plan 13-01 complete. MIG-01 and MIG-05 requirements fulfilled.
- Plans 13-02 through 13-03 cover remaining documentation and audit tasks in Phase 13.
- Phase 14 (WordPress.org Readiness) can begin in parallel once Phase 12 is fully closed.

---
*Phase: 13-migration-safety-and-governance-audit*
*Completed: 2026-06-17*
