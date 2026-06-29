---
phase: 08-keyboard-admin-bar
plan: "01"
subsystem: testing
tags: [playwright, keyboard, e2e, accessibility]

# Dependency graph
requires:
  - phase: 07-core-e2e-tests
    provides: activateSudoSession helper, fixtures/test.ts, Phase 7 test patterns (waitForURL predicate, clock ordering, WP-CLI container 'cli')
provides:
  - KEYB-01: Tab order on challenge page verified (password → submit → cancel)
  - KEYB-02: Enter key submits challenge form verified (native form submit → AJAX → navigation)
  - KEYB-03: Ctrl+Shift+S navigation to challenge page verified (wp-sudo-shortcut.js)
  - KEYB-04: Ctrl+Shift+S admin bar flash verified (wp-sudo-admin-bar.js inline style)
affects: [08-keyboard-admin-bar]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - page.emulateMedia({ reducedMotion: 'no-preference' }) before keyboard shortcut tests that check animation state
    - el.style.cssText includes check for hex color values (Chromium normalizes hex to rgb in getPropertyValue)
    - waitForFunction for window.wpSudoShortcut.challengeUrl before Ctrl+Shift+S test

key-files:
  created:
    - tests/e2e/specs/keyboard.spec.ts
  modified: []

key-decisions:
  - "Chromium normalizes hex color values (#4caf50) to rgb(76, 175, 80) in style.getPropertyValue('background'). Check el.style.cssText instead to verify the inline style was set via JS — cssText preserves the original hex notation."
  - "page.emulateMedia({ reducedMotion: 'no-preference' }) must be called BEFORE keyboard.press() for KEYB-04 — the wp-sudo-admin-bar.js handler reads matchMedia at keydown invocation time."
  - "VISN-03 admin-bar-active.png baseline has pre-existing drift (64 pixels); this is unrelated to Plan 08-01 and was failing before keyboard.spec.ts was added."

patterns-established:
  - "Pattern: KEYB-04 style assertion — check cssText for both hex and rgb representations when asserting inline style set by JS, because Chromium normalizes hex→rgb in getPropertyValue()"
  - "Pattern: emulateMedia before animation shortcut test — override prefers-reduced-motion before the key event, not after"
  - "Pattern: waitForFunction for config object before shortcut test — wait for wpSudoShortcut.challengeUrl before pressing Ctrl+Shift+S"

# Metrics
duration: 54min
completed: 2026-03-09
---

# Phase 08 Plan 01: Keyboard Navigation Tests Summary

**Four Playwright E2E tests covering Tab order, Enter-to-submit, and Ctrl+Shift+S navigation/flash for both session states — all sourced from live JS/PHP.**

## Performance

- **Duration:** 54 min
- **Started:** 2026-03-09T18:00:09Z
- **Completed:** 2026-03-09T18:54:00Z
- **Tasks:** 2 (Tasks 1+2 written together, verified, committed)
- **Files modified:** 1

## Accomplishments

- KEYB-01: Tab key traverses challenge form in DOM order (password input → submit button → Cancel link), using `autofocus` and separate `page.evaluate()` calls for tag/text to keep TypeScript types clean
- KEYB-02: Enter key submits challenge form via native form submit event → JS `passwordForm` submit listener → AJAX → `window.location.href` navigation
- KEYB-03: Ctrl+Shift+S from dashboard (no session) navigates to challenge page via `wp-sudo-shortcut.js` handler (`window.location.href = config.challengeUrl`)
- KEYB-04: Ctrl+Shift+S with active session flashes `.ab-item` inside `#wp-admin-bar-wp-sudo-active` with `background: #4caf50 !important` via `wp-sudo-admin-bar.js` keydown handler

## Task Commits

1. **Tasks 1+2: keyboard.spec.ts — all four KEYB tests** — `c149270` (test)

**Plan metadata:** (committed in final metadata commit below)

## Files Created/Modified

- `tests/e2e/specs/keyboard.spec.ts` — Four keyboard navigation E2E tests (KEYB-01/02/03/04), 250 lines, one `test.describe` block following Phase 7 patterns

## Decisions Made

- Used `el.style.cssText` check (includes `#4caf50` OR `rgb(76, 175, 80)`) instead of `el.style.getPropertyValue('background')` for KEYB-04 because Chromium normalizes hex color values to `rgb()` notation when reading them back — the hex string is preserved in `cssText`
- Called `page.emulateMedia({ reducedMotion: 'no-preference' })` before `keyboard.press('Control+Shift+S')` in KEYB-04 to prevent the `prefers-reduced-motion` guard in `wp-sudo-admin-bar.js` from skipping the flash animation
- Verified `window.wpSudoShortcut.challengeUrl` via `waitForFunction` before KEYB-03 keypress to ensure the shortcut script is fully initialized

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] KEYB-04 assertion — Chromium normalizes hex color to rgb() in style.getPropertyValue()**
- **Found during:** Task 2 (running KEYB-04 test)
- **Issue:** Plan specified asserting `el.style.getPropertyValue('background') === '#4caf50'`. In practice, Chromium returns `'rgb(76, 175, 80)'` (normalized form). The assertion `expect(flashBackground).toBe('#4caf50')` failed with `Received: "rgb(76, 175, 80)"`.
- **Fix:** Changed assertion to check `el.style.cssText` for either the original hex `'#4caf50'` or the normalized `'rgb(76, 175, 80)'`. `cssText` preserves the original hex value as written by `style.setProperty()`.
- **Files modified:** `tests/e2e/specs/keyboard.spec.ts`
- **Verification:** Test passes — `expect(flashStyleSet).toBe(true)` confirms the inline style was set by the JS handler.
- **Committed in:** `c149270` (part of task commit)

---

**Total deviations:** 1 auto-fixed (Rule 1 — bug in assertion logic)
**Impact on plan:** Single assertion correction. No scope creep. All four KEYB requirements verified.

## Issues Encountered

- VISN-03 (`admin-bar-active.png`) shows pre-existing baseline drift (64 pixels different) unrelated to Plan 08-01. Confirmed by running `visual/regression-baselines.spec.ts` in isolation without `keyboard.spec.ts` present — VISN-03 still fails. This is a known visual baseline fragility (timer text pixel drift between baseline capture and current run) that predates Phase 8.

## Self-Check

### Files exist

- [x] `tests/e2e/specs/keyboard.spec.ts` — confirmed (250 lines, 4 tests)
- [x] `.planning/phases/08-keyboard-admin-bar/08-01-SUMMARY.md` — this file

### Commits exist

- [x] `c149270` — `test(e2e/08-01): add keyboard navigation tests (KEYB-01-04)`

## Self-Check: PASSED

All created files and commits verified.

## Next Phase Readiness

- KEYB-01/02/03/04 are complete. Phase 08 Plan 02 (ABAR-01/02 admin bar deactivation tests) can proceed.
- The `cssText` hex-normalization pattern is documented for any future assertion on JS-set inline styles.
- Pre-existing VISN-03 baseline drift should be investigated and updated before any visual regression audit.

---
*Phase: 08-keyboard-admin-bar*
*Completed: 2026-03-09*
