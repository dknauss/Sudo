---
phase: 07-core-e2e-tests
plan: 03
subsystem: testing
tags: [playwright, e2e, challenge, stash-replay, mu-plugin, ajax, wp-env]

# Dependency graph
requires:
  - phase: 07-core-e2e-tests
    plan: 01
    provides: "activateSudoSession helper, cookie.spec.ts, gate-ui.spec.ts, fixtures/test.ts"
  - phase: 07-core-e2e-tests
    plan: 02
    provides: "admin-bar-timer.spec.ts, clock API patterns, TIMR-01/02/03/04"

provides:
  - "challenge.spec.ts — CHAL-01/02/03: full stash-challenge-replay flow tested end-to-end in browser"
  - "mu-plugin.spec.ts — MUPG-01/02/03 + bonus: MU-plugin AJAX install/uninstall flow tested with real session"
  - "withCliPolicyUnrestricted() helper — pattern for running gated WP-CLI commands in test setup"
  - "IP transient cleanup pattern — clears wp_sudo_ip_* transients between runs to prevent cross-run lockouts"

affects:
  - "07-04-PLAN.md (visual regression tests — all 19 tests now passing as baseline)"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "getActivateUrl() — activate session, scrape href from .activate a, prefix /wp-admin/ to relative href, clear session before navigating"
    - "withCliPolicyUnrestricted() — temporarily set wp_sudo_settings cli_policy=unrestricted via wp option set, run gated commands, restore via wp option delete"
    - "beforeAll meta + IP transient cleanup — delete user meta (_wp_sudo_lockout_until, _wp_sudo_failure_event, etc.) + wp transient delete --all to guarantee clean rate-limit state"
    - "afterAll dual cleanup — deactivate hello plugin (with CLI policy bypass) + clear failure meta + IP transients"
    - "Scoped Cancel link selector — #wp-sudo-challenge-password-step a.button instead of global a.button to avoid strict mode violation with hidden 2FA form"

key-files:
  created:
    - tests/e2e/specs/challenge.spec.ts
    - tests/e2e/specs/mu-plugin.spec.ts
  modified: []

key-decisions:
  - "href prefix fix: WordPress plugins.php renders activate links as relative paths (plugins.php?action=...) without leading slash; page.goto() resolves them relative to origin not /wp-admin/, resulting in frontend 404. Fix: prefix /wp-admin/ when href does not start with / or http"
  - "CLI policy bypass: WP Sudo's default cli_policy=limited blocks `wp plugin deactivate` in test setup/teardown; temporarily set to unrestricted via wp option set (not a gated action), run command, restore via wp option delete"
  - "IP-based rate limiting cross-run contamination: wp_sudo_ip_failure_event_ and wp_sudo_ip_lockout_until_ transients persist between test runs; wp option list --search does NOT enumerate transients — must use wp transient delete --all in beforeAll and afterAll"
  - "Cancel link strict mode: challenge page renders two Cancel links (one in #wp-sudo-challenge-password-step, one hidden in #wp-sudo-challenge-2fa-form); scope selector to password step container to avoid strict mode violation"
  - "CHAL-01 stash replay activates hello plugin: afterAll must deactivate hello (with CLI policy bypass) so gate-ui.spec.ts (which runs alphabetically after challenge.spec.ts) has an inactive plugin to test against"

patterns-established:
  - "Rate-limit cleanup in beforeAll/afterAll: delete user meta (_wp_sudo_lockout_until, _wp_sudo_failure_event, _wp_sudo_failed_attempts, _wp_sudo_throttle_until) via wp user meta delete + run wp transient delete --all to clear IP-based transients; wp option list --search cannot enumerate transients"
  - "CLI policy bypass pattern: withCliPolicyUnrestricted() wraps gated CLI commands in try/finally to always restore, even if the inner command fails"
  - "Alphabetical spec order awareness: challenge → cookie → gate-ui → mu-plugin → smoke. Cleanup in afterAll must account for this ordering to avoid cross-spec contamination"

# Metrics
duration: 90min
completed: 2026-03-09
---

# Phase 7 Plan 03: Challenge Stash-Replay and MU-Plugin AJAX Tests Summary

**Full stash-challenge-replay flow verified end-to-end (CHAL-01-03) and MU-plugin AJAX install/uninstall tested with live session (MUPG-01-03), with CLI policy bypass and IP-rate-limit cleanup for test isolation across 19 passing tests**

## Performance

- **Duration:** ~90 min
- **Started:** 2026-03-09T10:20:00Z
- **Completed:** 2026-03-09T11:49:59Z
- **Tasks:** 2 (plus significant deviation fixes)
- **Files modified:** 2

## Accomplishments

- `challenge.spec.ts` — CHAL-01 verifies the full stash-replay flow: gate redirects gated plugin activate URL to challenge page, correct password replays stash and lands on plugins.php. CHAL-02 verifies all required DOM elements are present and in correct visibility state. CHAL-03 verifies wrong password shows inline error without navigation.
- `mu-plugin.spec.ts` — MUPG-01 verifies install button triggers AJAX, shows spinner, reloads, and shows Installed. MUPG-02 verifies success icon (dashicons-yes-alt) after install. MUPG-03 verifies uninstall returns to Not installed. Bonus test verifies 403 + sudo_required code when no session active.
- All 19 tests in the full suite pass consistently across two consecutive runs.
- Three critical cross-spec isolation issues discovered and fixed: relative href resolution, CLI policy blocking test teardown, and IP-based rate limiting persisting between test runs.

## Task Commits

Per the instructions, commits are left for the orchestrator. No commits made during this plan execution.

## Files Created/Modified

- `/Users/danknauss/Documents/GitHub/wp-sudo/tests/e2e/specs/challenge.spec.ts` — New: CHAL-01 (stash-replay), CHAL-02 (form elements), CHAL-03 (wrong password inline error). Includes `getActivateUrl()` with href prefix fix, `clearSudoSession()`, `withCliPolicyUnrestricted()`, beforeAll/afterAll with full meta + IP transient cleanup.
- `/Users/danknauss/Documents/GitHub/wp-sudo/tests/e2e/specs/mu-plugin.spec.ts` — New: MUPG-01/02/03 (install/uninstall flow in serial describe block), Bonus 403 test. Includes `wpCli()`, `removeMuPlugin()`, `getMuPluginState()` helpers. Uses `cli` container (not `tests-cli`) to target port 8889 dev site.

## Decisions Made

1. **href prefix fix**: WordPress plugins.php renders activate links as `plugins.php?action=...` (bare relative path, no leading slash). `page.goto('plugins.php?...')` with Playwright's baseURL resolves to `http://localhost:8889/plugins.php` instead of `http://localhost:8889/wp-admin/plugins.php`. Gate only fires under `/wp-admin/`. Fix: prefix `/wp-admin/` when href doesn't start with `/` or `http`.

2. **CLI policy bypass**: WP Sudo's `cli_policy=limited` (default) blocks `wp plugin deactivate` in WP-CLI. Test setup/teardown needs to deactivate hello plugin. Solution: `withCliPolicyUnrestricted()` temporarily updates `wp_sudo_settings` option to unrestricted, runs the gated command, then restores via `wp option delete`. Uses try/finally to guarantee restoration.

3. **IP-based rate limiting is persistent**: `_wp_sudo_ip_failure_event_` and `_wp_sudo_ip_lockout_until_` transients stored in `wp_options` persist between test runs. After previous runs left CHAL-03's wrong password attempt accumulated, CHAL-03 itself got locked out. Fix: add `DELETE FROM options WHERE option_name LIKE '%wp_sudo_ip%'` in both `beforeAll` and `afterAll`.

4. **Cancel link strict mode violation**: `a.button:has-text("Cancel")` matched 2 elements (one in visible password step, one in hidden 2FA form). Fixed by scoping to `#wp-sudo-challenge-password-step a.button:has-text("Cancel")`.

5. **CHAL-01 activates hello plugin**: The stash-replay actually processes the plugin activation. gate-ui.spec.ts (alphabetically after challenge.spec.ts) needs an inactive plugin. Added `afterAll` that deactivates hello using the CLI policy bypass.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] WordPress plugins.php relative href causes frontend 404**
- **Found during:** Task 1 (CHAL-01 stash-replay test)
- **Issue:** `getAttribute('href')` on `.activate a` returns `plugins.php?action=activate&...` (no leading slash). `page.goto()` resolves relative to base URL origin, navigating to `http://localhost:8889/plugins.php` instead of `/wp-admin/plugins.php`. Gate only fires on admin requests, so the 404 frontend page was shown instead.
- **Fix:** In `getActivateUrl()`, check `if (!href.startsWith('/') && !href.startsWith('http'))` then prefix `/wp-admin/` to the href.
- **Files modified:** `tests/e2e/specs/challenge.spec.ts`
- **Verification:** Debug test confirmed the fixed URL navigated to `http://localhost:8889/wp-admin/admin.php?page=wp-sudo-challenge` (gate fired correctly).
- **Committed in:** Part of Task 1 (no separate commit — discovered during initial testing)

**2. [Rule 1 - Bug] Cancel link strict mode violation in CHAL-02**
- **Found during:** Task 1 verification (CHAL-02 failure)
- **Issue:** `a.button:has-text("Cancel")` matched 2 elements — one in the visible password step form, one in the hidden 2FA form (`#wp-sudo-challenge-2fa-form`). Playwright's strict mode requires a single match.
- **Fix:** Scoped selector to `#wp-sudo-challenge-password-step a.button:has-text("Cancel")`.
- **Files modified:** `tests/e2e/specs/challenge.spec.ts`
- **Verification:** CHAL-02 passes with scoped selector.
- **Committed in:** Part of Task 1

**3. [Rule 2 - Missing Critical] Cross-spec isolation: CHAL-01 stash-replay activates hello plugin**
- **Found during:** Full suite run after both tasks complete
- **Issue:** CHAL-01 successfully replays the plugin activate stash, which activates hello.php. gate-ui.spec.ts (alphabetically after challenge.spec.ts) needs at least one inactive plugin for GATE-01 and GATE-03. Both GATE-01 and GATE-03 failed because hello was active.
- **Fix:** Added `afterAll` hook that deactivates hello plugin using `withCliPolicyUnrestricted()` wrapper.
- **Files modified:** `tests/e2e/specs/challenge.spec.ts`
- **Verification:** Full suite GATE-01/02/03 all pass after fix.
- **Committed in:** Part of Task 1

**4. [Rule 2 - Missing Critical] WP Sudo CLI policy blocks test setup/teardown**
- **Found during:** Full suite run, afterAll hook failure
- **Issue:** `npx wp-env run cli wp plugin deactivate hello` failed with "This operation requires sudo and cannot be performed via WP-CLI" — the default `cli_policy=limited` blocks this.
- **Fix:** Created `withCliPolicyUnrestricted()` helper that temporarily updates `wp_sudo_settings` option to `{"cli_policy":"unrestricted"}` via `wp option set` (not a gated action), runs the callback, then restores via `wp option delete` in a finally block.
- **Files modified:** `tests/e2e/specs/challenge.spec.ts`
- **Verification:** Plugin deactivation succeeds in test setup/teardown after fix.
- **Committed in:** Part of Task 1

**5. [Rule 1 - Bug] IP-based rate limiting persists between test runs causing lockouts**
- **Found during:** Full suite runs 2 and 3, CHAL-03 getting "Too many failed attempts" lockout
- **Issue:** WP Sudo tracks failed auth attempts both per-user (in user meta) AND per-IP (in WordPress transients: `_transient_wp_sudo_ip_failure_event_*` and `_transient_wp_sudo_ip_lockout_until_*`). These transients accumulate across test runs. Even with clean user meta, the IP-level lockout transient caused CHAL-03's single wrong password to trigger the lockout response ("Too many failed attempts. Try again in 4:55") instead of the expected "Incorrect password" error.
- **Fix:** Created `clearSudoIpTransients()` function using `wp transient delete --all` — the only reliable way to clear transients since `wp option list --search` excludes transients from its output. Called in both `beforeAll` (clear previous runs) and `afterAll` (clean up for next run).
- **Files modified:** `tests/e2e/specs/challenge.spec.ts`
- **Verification:** CHAL-03 passes with "Incorrect password" error after fix. Two consecutive full suite runs both pass 19/19.
- **Committed in:** Part of Task 1

**6. [Rule 2 - Missing Critical] CHAL-03 wrong password throttle bleeds into mu-plugin tests**
- **Found during:** Full suite run, MUPG-02 activateSudoSession failure
- **Issue:** CHAL-03 submits a wrong password which creates `_wp_sudo_throttle_until` user meta. Subsequent `activateSudoSession()` calls in mu-plugin tests encountered "Please wait N seconds" errors with the password field disabled.
- **Fix:** afterAll clears all failure-related user meta keys: `_wp_sudo_lockout_until`, `_wp_sudo_failure_event`, `_wp_sudo_failed_attempts`, `_wp_sudo_throttle_until`.
- **Files modified:** `tests/e2e/specs/challenge.spec.ts`
- **Verification:** MUPG-01/02/03 all pass after fix.
- **Committed in:** Part of Task 1

---

**Total deviations:** 6 auto-fixed (4 Rule 2 - Missing Critical, 2 Rule 1 - Bug)
**Impact on plan:** All 6 fixes were essential for correct cross-spec test isolation. The IP-based rate limiting issue is particularly noteworthy — it was invisible when running specs in isolation but manifested only in full suite runs. The CLI policy bypass pattern is reusable for any future test that needs to run gated WP-CLI commands in setup/teardown.

## Issues Encountered

- The IP-based rate limiting cross-run contamination issue required understanding WP Sudo's dual rate limiting system (user meta + IP transients). The fix required querying `wp_options` directly via `wp eval` since there's no WP-CLI command to delete transients by prefix pattern.
- The CLI policy bypass required understanding that `wp option set/delete` is not a gated action, allowing it to be used to modify the policy that governs other gated actions.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- All 19 E2E tests pass (TIMR-01/02/03/04, CHAL-01/02/03, COOK-01/02/03, GATE-01/02/03, MUPG-01/02/03 + bonus, smoke-01/02)
- challenge.spec.ts and mu-plugin.spec.ts are complete and stable
- Rate-limit cleanup pattern (`beforeAll` + `afterAll` with meta and IP transient deletion) established — use in any future spec that tests auth failure scenarios
- CLI policy bypass pattern (`withCliPolicyUnrestricted`) established — use in any future spec that needs gated WP-CLI commands in setup
- Phase 7 Plan 04 (visual regression baselines) is the next planned spec — can build on these patterns

## Self-Check: PASSED

Files verified:
- FOUND: /Users/danknauss/Documents/GitHub/wp-sudo/tests/e2e/specs/challenge.spec.ts
- FOUND: /Users/danknauss/Documents/GitHub/wp-sudo/tests/e2e/specs/mu-plugin.spec.ts

Test results: 19/19 passed (two consecutive full suite runs)

---
*Phase: 07-core-e2e-tests*
*Completed: 2026-03-09*
