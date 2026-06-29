---
phase: 07-core-e2e-tests
plan: 02
subsystem: testing
tags: [playwright, e2e, page-clock, admin-bar, timer, wp-env]

# Dependency graph
requires:
  - phase: 07-core-e2e-tests
    plan: 01
    provides: "activateSudoSession helper exported from fixtures/test.ts"

provides:
  - "admin-bar-timer.spec.ts — TIMR-01/02/03/04 assertions on countdown timer visibility, format, expiring class, and reload-on-expiry"

affects:
  - "07-04-PLAN.md (VISN-03/04 visual regression tests need timer clock knowledge)"

# Tech tracking
tech-stack:
  added:
    - "child_process.execSync — used in TIMR-04 to expire PHP session server-side via WP-CLI"
  patterns:
    - "page.clock.install() before any navigation for timer tests — setInterval captures Date at script-load time"
    - "page.clock.runFor(ms) — Playwright 1.45+ equivalent of sinon tick() — fires setInterval callbacks at each step"
    - "PHP/JS clock separation: page.clock only affects browser JS; PHP time() is real wall clock — server-side session expiry requires WP-CLI for deterministic tests"
    - "wp-env cli vs tests-cli: 'cli' container targets port 8889 (dev/browser tests); 'tests-cli' targets port 8890 (tests site)"

key-files:
  created:
    - tests/e2e/specs/admin-bar-timer.spec.ts
  modified: []

key-decisions:
  - "page.clock.runFor() not tick() — Playwright 1.58.2 has runFor() as the sinon-tick equivalent; tick() does not exist in this version"
  - "TIMR-04 requires WP-CLI to expire the PHP session before triggering the JS reload — page.clock advances JS fake time only, not PHP real time()"
  - "WP-CLI container for port 8889 tests is 'cli' not 'tests-cli' — tests-cli targets the tests site on port 8890"
  - "TIMR-01 intentionally uses real clock — format-only assertion, no fake-clock overhead needed"

patterns-established:
  - "PHP/JS time separation pattern: for any test where JS timer triggers a page reload and PHP-side state must be expired, use execSync WP-CLI to expire server-side state first"
  - "wp-env container targeting: always use 'cli' container for browser tests (port 8889), 'tests-cli' only for tests-site-specific WP-CLI operations"
  - "page.clock.install() call order: install → activateSudoSession → goto → interact (never install after goto)"

# Metrics
duration: 30min
completed: 2026-03-09
---

# Phase 7 Plan 02: Admin Bar Timer Tests Summary

**Four deterministic admin bar timer tests (TIMR-01/02/03/04) using page.clock.runFor() to control setInterval without real waiting, plus WP-CLI session expiry for TIMR-04's PHP/JS clock boundary**

## Performance

- **Duration:** ~30 min
- **Started:** 2026-03-09T02:50:00Z
- **Completed:** 2026-03-09T03:20:00Z
- **Tasks:** 1
- **Files modified:** 1 (created)

## Accomplishments

- `admin-bar-timer.spec.ts` created with TIMR-01 through TIMR-04 all passing
- Discovered and documented Playwright 1.58.2 clock API: `runFor()` is the correct method (not `tick()`, which does not exist)
- Discovered and documented PHP/JS clock separation: `page.clock` only affects browser JavaScript; PHP's `time()` uses real wall clock, requiring WP-CLI for server-side session expiry in TIMR-04
- Documented `cli` vs `tests-cli` container distinction: browser tests (port 8889) must use `cli`
- Full suite (12 tests) passes: TIMR-01/02/03/04 + COOK-01/02/03 + GATE-01/02/03 + smoke-01/02

## Task Commits

Per the instructions, commits are left for the orchestrator. No commits made during this plan execution.

## Files Created/Modified

- `tests/e2e/specs/admin-bar-timer.spec.ts` — New: TIMR-01 (timer visible + M:SS format), TIMR-02 (text updates per second via runFor), TIMR-03 (wp-sudo-expiring class at 60s), TIMR-04 (page reload at 0s + node absent after reload)

## Decisions Made

1. **page.clock.runFor() instead of tick()**: The plan specified `page.clock.tick()`. Playwright 1.58.2 does not have a `tick()` method — the correct equivalent is `runFor(ms)`, which fires all timer callbacks within the time range (same semantics as sinon's `tick()`). `fastForward()` also exists but skips intermediate callbacks.

2. **WP-CLI PHP session expiry in TIMR-04**: The plan assumed that ticking 910 seconds would result in an expired session after reload. This is incorrect — `page.clock.runFor()` only advances JavaScript time in the browser. PHP uses real `time()` to check session expiry. After a 10-second test run, PHP sees the session as still active (it was created 10 real seconds ago with a 900-second TTL). Fix: use `execSync('npx wp-env run cli wp user meta update 1 _wp_sudo_expires 1')` before triggering the JS reload. This sets `_wp_sudo_expires` to a past timestamp, making PHP's `is_active()` return false on the reloaded page.

3. **wp-env container is 'cli' not 'tests-cli'**: The initial fix for TIMR-04 used `tests-cli`. This silently targets the tests site on port 8890 (not the browser test site on port 8889). The browser tests use `http://localhost:8889`, which is the `cli` container's WordPress. Corrected to `npx wp-env run cli`.

4. **TIMR-01 uses real clock**: The plan specified TIMR-01 does not use page.clock. This is preserved. The test verifies the initial render format (M:SS regex) without time manipulation — the format assertion is stable enough that the 1-2 seconds of real time during the assertion won't cause a format failure.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] page.clock.tick() does not exist in Playwright 1.58.2**
- **Found during:** Task 1 (first test run — TIMR-02, TIMR-03, TIMR-04 all failed)
- **Issue:** The plan specified `page.clock.tick(ms)` as the time-advance method. Playwright 1.58.2's Clock class has `install()`, `fastForward()`, `pauseAt()`, `resume()`, `runFor()`, `setFixedTime()`, `setSystemTime()`. There is no `tick()` method.
- **Fix:** Replaced all `page.clock.tick(ms)` calls with `page.clock.runFor(ms)`. `runFor()` is semantically identical to sinon's `tick()` — it fires all timer callbacks that would execute within the specified time range. Updated file header comment to document the Playwright clock API.
- **Files modified:** `tests/e2e/specs/admin-bar-timer.spec.ts`
- **Verification:** TIMR-02 (1-second tick), TIMR-03 (840-second tick) both passed immediately after the change.
- **Committed in:** No commit yet — orchestrator handles commits.

**2. [Rule 1 - Bug] TIMR-04 expected PHP session expired after JS clock fast-forward**
- **Found during:** Task 1 (TIMR-04 failed — timer node still visible after reload)
- **Issue:** After `page.clock.runFor(910_000)` triggered `window.location.reload()`, the reloaded page still showed the admin bar timer. PHP's `is_active()` in class-sudo-session.php uses `time()` (real wall clock). Only ~11 real seconds had elapsed during the test. The PHP session had a real 900-second TTL from when `activateSudoSession()` called `Sudo_Session::activate()`. PHP correctly reported the session as still active.
- **Fix:** Added `execSync('npx wp-env run cli wp user meta update 1 _wp_sudo_expires 1')` before `runFor()`. This sets the server-side session expiry to Unix timestamp 1 (January 1, 1970 — distant past). When the page reloads, PHP's `is_active()` sees `time() > 1` (true), returns false, and `admin_bar_node()` skips rendering the timer node.
- **Files modified:** `tests/e2e/specs/admin-bar-timer.spec.ts` (added `import { execSync } from 'child_process'` and the WP-CLI call)
- **Verification:** TIMR-04 passes after this fix.
- **Committed in:** No commit yet — orchestrator handles commits.

**3. [Rule 1 - Bug] WP-CLI container was 'tests-cli' instead of 'cli'**
- **Found during:** Task 1 (TIMR-04 still failed after initial WP-CLI fix)
- **Issue:** Initial fix used `npx wp-env run tests-cli wp user meta update 1 _wp_sudo_expires 1`. The `tests-cli` container targets the tests WordPress site on port 8890. Browser tests run against the development site on port 8889 (the `cli` container). The meta update succeeded on the wrong site.
- **Fix:** Changed `tests-cli` to `cli` in the execSync command.
- **Files modified:** `tests/e2e/specs/admin-bar-timer.spec.ts`
- **Verification:** `npx wp-env run cli wp user meta get 1 _wp_sudo_expires` confirmed the update applies to the correct site. TIMR-04 passes.
- **Committed in:** No commit yet — orchestrator handles commits.

---

**Total deviations:** 3 auto-fixed (all Rule 1 - Bug)
**Impact on plan:** All three fixes were required for test correctness. The `tick()` rename is a Playwright API difference. The PHP/JS clock separation is a fundamental architectural constraint that the plan's approach hadn't accounted for. The container targeting is a wp-env configuration detail. No scope creep — all four TIMR tests verify exactly the behaviors specified in the plan.

## Issues Encountered

The PHP/JS clock separation is the most important insight from this plan. Any future test that:
1. Uses `page.clock` to advance time
2. Then triggers a server-side PHP reload or redirect
3. Then asserts on PHP-rendered content that depends on time-sensitive state

...will need to either: (a) expire the PHP-side state via WP-CLI before the reload, OR (b) not assert on time-sensitive PHP state after the reload.

This is now documented in the spec file header and should be referenced by VISN tests in Plan 07-04 if they involve session expiry.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- All 12 E2E tests pass: TIMR-01/02/03/04 + COOK-01/02/03 + GATE-01/02/03 + smoke-01/02
- `page.clock.runFor()` pattern is now established and documented for future timer tests
- PHP/JS clock separation pattern documented — 07-03 and 07-04 should be aware if they interact with time-sensitive PHP state after clock-controlled reloads
- 07-03 (challenge + MU-plugin) can proceed — those tests don't use page.clock
- 07-04 (visual regression) VISN-03/04 use page.clock for timer freeze — the `clock.install()` → `activateSudoSession()` → `goto()` → `runFor()` pattern is now proven

## Self-Check: PASSED

Files verified:
- FOUND: tests/e2e/specs/admin-bar-timer.spec.ts

Exports verified:
- `activateSudoSession` imported from fixtures/test.ts (line 47)
- `test` imported from fixtures/test.ts (line 47)
- `expect` imported from fixtures/test.ts (line 47)
- `execSync` imported from 'child_process' (line 46)

Test results: 4/4 TIMR tests passed, 12/12 full suite passed

---
*Phase: 07-core-e2e-tests*
*Completed: 2026-03-09*
