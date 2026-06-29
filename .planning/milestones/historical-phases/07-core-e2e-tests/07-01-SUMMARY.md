---
phase: 07-core-e2e-tests
plan: 01
subsystem: testing
tags: [playwright, e2e, cookie, gate-ui, wp-env]

# Dependency graph
requires:
  - phase: 06-e2e-scaffold
    provides: "playwright.config.ts, global-setup.ts, fixtures/test.ts (visitAdminPage), smoke.spec.ts, wp-env running on :8889"

provides:
  - "activateSudoSession helper exported from fixtures/test.ts — reusable session acquisition for all subsequent plans"
  - "cookie.spec.ts — COOK-01/02/03 assertions on wp_sudo_token httpOnly/sameSite/path attributes"
  - "gate-ui.spec.ts — GATE-01/02/03 assertions on server-rendered disabled plugin action elements"

affects:
  - "07-02-PLAN.md (timer tests use activateSudoSession)"
  - "07-03-PLAN.md (challenge+MU-plugin tests use activateSudoSession)"
  - "07-04-PLAN.md (visual regression uses activateSudoSession)"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "activateSudoSession — navigate to challenge page, waitForFunction(wpSudoChallenge), fill, Promise.all([waitForURL predicate, click])"
    - "waitForURL predicate to exclude current page pattern (prevents immediate resolution)"
    - "beforeEach cookie cleanup: clearCookies + addCookies(non-sudo) to guarantee session state"

key-files:
  created:
    - tests/e2e/specs/cookie.spec.ts
    - tests/e2e/specs/gate-ui.spec.ts
  modified:
    - tests/e2e/fixtures/test.ts

key-decisions:
  - "activateSudoSession uses URL predicate (not regex) to exclude challenge page — bare /wp-admin/ regex resolved immediately because challenge page URL already matched"
  - "GATE selectors target server-rendered spans (.activate [aria-disabled=true]) not gate-ui.js-modified anchors — PHP filter_plugin_action_links replaces <a> with <span> before gate-ui.js runs"
  - "activateSudoSession is a standalone exported function (not a fixture) — allows calling with just page argument without fixture plumbing overhead"

patterns-established:
  - "Cookie cleanup pattern: clearCookies + restore non-sudo cookies in beforeEach to guarantee known session state"
  - "No-session gate tests: verify timer node absent (#wp-admin-bar-wp-sudo-active) before asserting disabled state"

# Metrics
duration: 76min
completed: 2026-03-09
---

# Phase 7 Plan 01: Cookie Attributes and Gate UI Tests Summary

**activateSudoSession helper exported from fixtures/test.ts, cookie security attributes (httpOnly/sameSite/path) verified via context.cookies(), and gate UI disabled-button behavior verified against PHP-rendered spans on plugins.php**

## Performance

- **Duration:** ~76 min
- **Started:** 2026-03-09T01:30:31Z
- **Completed:** 2026-03-09T02:46:31Z
- **Tasks:** 2
- **Files modified:** 3

## Accomplishments
- `activateSudoSession` helper function exported from `tests/e2e/fixtures/test.ts` — navigates to challenge page, waits for JS config, submits password, and waits for redirect using a URL predicate that excludes the challenge page itself
- Cookie tests (COOK-01/02/03) verify `wp_sudo_token` httpOnly, sameSite=Strict, and path=/ attributes via Playwright's `context.cookies()` API
- Gate UI tests (GATE-01/02/03) verify disabled plugin action elements on plugins.php with no active session — with corrected selectors targeting PHP-rendered `<span>` elements (not JS-modified `<a>` elements)

## Task Commits

Per the instructions, commits are left for the orchestrator. No commits made during this plan execution.

## Files Created/Modified

- `tests/e2e/fixtures/test.ts` — Extended with `activateSudoSession` standalone exported function; added `type Page` import; kept `visitAdminPage` fixture and `expect` re-export unchanged
- `tests/e2e/specs/cookie.spec.ts` — New: COOK-01 (httpOnly=true), COOK-02 (sameSite=Strict), COOK-03 (path=/) with beforeEach cookie cleanup
- `tests/e2e/specs/gate-ui.spec.ts` — New: GATE-01 (aria-disabled=true on .activate elements), GATE-02 (wp-sudo-disabled class + pointer-events:none), GATE-03 (click does not navigate)

## Decisions Made

1. **URL predicate instead of regex for waitForURL**: The plan specified `waitForURL(/wp-admin/)` but the challenge page URL itself is `/wp-admin/admin.php?page=wp-sudo-challenge`, which already matches the pattern. Changed to a URL predicate `(url) => url.pathname.includes('/wp-admin/') && !url.search.includes('wp-sudo-challenge')`.

2. **GATE selectors target PHP-rendered spans**: The plan specified `.activate a` selectors (based on gate-ui.js JS behavior), but the PHP `filter_plugin_action_links()` in class-gate.php replaces `<a>` tags with `<span class="wp-sudo-disabled" aria-disabled="true">` on the server side. Since PHP runs before JavaScript, the DOM contains `<span>` elements, not `<a>` elements. Changed GATE-01 selector to `.activate [aria-disabled="true"]` and GATE-03 selector to `.activate .wp-sudo-disabled`.

3. **GATE-03 element is a `<span>`, not `<a>`**: The test description updated to "clicking a disabled activate element" (not "link") to accurately reflect the PHP-rendered output.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] waitForURL pattern resolved immediately on challenge page**
- **Found during:** Task 1 (activateSudoSession) and Task 2 (cookie tests debugging)
- **Issue:** The plan specified `waitForURL(/wp-admin/, { timeout: 15_000 })`. The challenge page URL is `/wp-admin/admin.php?page=wp-sudo-challenge`, which already matches `/wp-admin/`. The `Promise.all` resolved immediately upon click (matching current URL), before the AJAX call to `admin-ajax.php` had completed. Result: cookie tests found `wp_sudo_token` undefined.
- **Fix:** Changed to a URL predicate function: `(url) => url.pathname.includes('/wp-admin/') && !url.search.includes('wp-sudo-challenge')`. This waits for an actual navigation away from the challenge page.
- **Files modified:** `tests/e2e/fixtures/test.ts`
- **Verification:** Cookie tests COOK-01/02/03 all pass. Debug test confirmed URL changes to `/wp-admin/` and `wp_sudo_token` cookie is set.
- **Committed in:** Part of Task 2 commit (no separate commit — discovered during testing)

**2. [Rule 1 - Bug] GATE selectors targeted non-existent `<a>` elements**
- **Found during:** Task 2 (gate-ui tests debugging)
- **Issue:** GATE-01 used `.activate a` selector (0 results). GATE-03 used `.activate a` selector (timeout). The plan's selectors were based on `gate-ui.js`'s `disableButtons()` function which targets `.activate a`. However, the PHP `filter_plugin_action_links()` in `class-gate.php` runs at filter time and replaces the `<a>` HTML with `<span class="wp-sudo-disabled" aria-disabled="true" style="color:#787c82;cursor:default">Activate</span>`. When PHP renders the page, there are no `.activate a` elements for the JS to find.
- **Fix:** Changed GATE-01 selector from `.activate a` to `.activate [aria-disabled="true"]`. Changed GATE-03 selector from `.activate a` to `.activate .wp-sudo-disabled`. Updated comments throughout gate-ui.spec.ts to explain the two-layer approach (PHP primary + JS fallback).
- **Files modified:** `tests/e2e/specs/gate-ui.spec.ts`
- **Verification:** All three gate tests pass. Debug test confirmed `.activate` cells contain `<span class="wp-sudo-disabled" aria-disabled="true">` elements with count > 0.
- **Committed in:** Part of Task 2 commit

---

**Total deviations:** 2 auto-fixed (both Rule 1 - Bug)
**Impact on plan:** Both fixes required for test correctness. The plan's selectors were based on gate-ui.js source code, but the PHP filter makes the JS irrelevant on initial server-rendered page load. This is an important insight for all future gate UI tests.

## Issues Encountered

- The `pointer-events:none` assertion in GATE-02 passed when run in isolation but failed once in the full suite. Investigation showed this was due to a stale sudo session in the browser context from a previous incomplete test run (not within-run contamination). The `beforeEach` cookie cleanup handles this correctly; the one-time failure was pre-existing state from manual testing. Three consecutive full suite runs all pass cleanly.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- `activateSudoSession` is ready for use by plans 07-02, 07-03, and 07-04
- All 8 tests pass (COOK-01/02/03, GATE-01/02/03, smoke-01/02)
- Two key behaviors established: session activation flow and PHP-level gate rendering
- 07-02 (timer tests) should call `page.clock.install()` BEFORE calling `activateSudoSession` (see RESEARCH.md Pitfall)
- 07-03 (challenge + MU-plugin) can use `activateSudoSession` directly

## Self-Check: PASSED

Files verified:
- FOUND: tests/e2e/fixtures/test.ts
- FOUND: tests/e2e/specs/cookie.spec.ts
- FOUND: tests/e2e/specs/gate-ui.spec.ts

Exports verified:
- `activateSudoSession` exported from fixtures/test.ts (line 60)
- `test` exported from fixtures/test.ts (line 7)
- `expect` re-exported from fixtures/test.ts (line 93)

Test results: 8/8 passed (3 cookie, 3 gate-ui, 2 smoke)

---
*Phase: 07-core-e2e-tests*
*Completed: 2026-03-09*
