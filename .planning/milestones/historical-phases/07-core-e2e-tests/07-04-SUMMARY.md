# 07-04 Summary: Visual Regression Baselines (Wave 4)

**Status:** Complete
**Tests:** 4 passed (VISN-01, VISN-02, VISN-03, VISN-04)
**Full suite:** 23 passed (19 functional + 4 visual)

## What was done

Created `tests/e2e/specs/visual/regression-baselines.spec.ts` with 4 visual regression baseline tests covering all VISN requirements. Captured 4 baseline PNG files committed to `tests/e2e/specs/visual/__snapshots__/`.

## Requirements covered

| Requirement | Test | Baseline file |
|-------------|------|---------------|
| VISN-01 | challenge page card element | challenge-card-chromium.png (22KB) |
| VISN-02 | settings page form element | settings-form-chromium.png (312KB) |
| VISN-03 | admin bar node active state | admin-bar-active-chromium.png (5KB) |
| VISN-04 | admin bar node expiring state | admin-bar-expiring-chromium.png (5KB) |

## Deviations from plan

1. **Clock ordering corrected:** Plan had `page.clock.install()` before `activateSudoSession()` for VISN-03/04. Executor corrected to `activateSudoSession()` → `clock.install()` → `page.goto()` (matching the pattern established in Waves 2-3 where frozen clock breaks AJAX challenge flow).

2. **`runFor()` instead of `tick()`:** Plan used `page.clock.tick(840_000)` for VISN-04. Executor corrected to `page.clock.runFor(840_000)` (matching TIMR deviation from Wave 2).

3. **Page-level clip instead of element screenshot for VISN-03/04:** Plan used element-level `toHaveScreenshot()` on the `li#wp-admin-bar-wp-sudo-active` node. Executor switched to page-level screenshot with fixed clip `{x:0, y:0, width:1280, height:32}` plus timer text mask. Reason: the `li` element auto-sizes to text content ("Sudo: 15:00" vs "Sudo: 14:53" = different widths), causing Playwright to reject with "Expected 312px, received 315px". Fixed clip ensures stable 1280x32 dimensions regardless of timer text.

4. **MU-plugin status masked in VISN-02:** Settings form snapshot masks `#wp-sudo-mu-status` to avoid baseline instability from installed/not-installed state changes between runs.

## Thresholds

- Challenge card, settings form: `threshold: 0.05` (5%)
- Admin bar snapshots: `threshold: 0.1` (10%) — text-heavy, font rendering varies

## Update command

```bash
npx playwright test tests/e2e/specs/visual/regression-baselines.spec.ts --update-snapshots
```
