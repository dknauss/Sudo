## Current Position

Phase: 8 (Keyboard Navigation + Admin Bar Interaction E2E) — COMPLETE
Plan: 02 complete (2/2 plans done)
Status: ALL COMPLETE — v2.14 Playwright E2E milestone plus post-milestone multisite hardening: 32/32 v1 requirements verified, 30/30 tests passing
Last activity: 2026-03-15 -- local multisite regression + symlink-safe bootstrap path recovery verified in unit, integration, Local browser, and hosted CI

## Project Reference

See: .planning/PROJECT.md (updated 2026-03-15)

**Core value:** Every destructive admin action requires proof the person at the keyboard is still the authenticated user.
**Current focus:** Playwright E2E Test Infrastructure

## Accumulated Context

- Security Hardening Sprint (5 phases, v2.10.2-v2.13.0) complete and archived
- Current test and size counts are centralized in `../docs/current-metrics.md`
- PHPStan level 6 + Psalm clean
- WP 7.0 GA ships April 9, 2026 -- visual regression baselines needed before then
- 5 PHPUnit-uncoverable scenarios identified and scoped into 32 requirements
- 3-phase roadmap: scaffold (Phase 6) → core tests (Phase 7) → keyboard/a11y (Phase 8)
- Research complete: STACK.md, FEATURES.md, ARCHITECTURE.md, PITFALLS.md synthesized
- Phase 6: 3 plans (06-01 toolchain, 06-02 Playwright config+smoke test, 06-03 CI workflow)
- Plan checker: VERIFICATION PASSED (1 info-level note: npm test vs npm run test:e2e)
- Phase 6: COMPLETE ✅ (all 3 waves, all 6 TOOL requirements verified)
- Phase 7: 4 plans (07-01 cookie+gate, 07-02 timer, 07-03 challenge+MU-plugin, 07-04 visual regression)
- Phase 7 plan checker: 1 blocker (MUPG mapping) + 4 warnings fixed; all selectors cross-referenced against source
- Phase 7: COMPLETE ✅ (all 4 waves, all 20 requirements verified — 23 E2E tests passing)
- Post-milestone hardening: COMPLETE ✅ (Local multisite network-admin regression added; symlink-safe bootstrap path recovery covered in unit + integration + Local browser runs)

## Phase 6 Execution Progress

- **06-01 (Wave 1) ✅** — package.json, .nvmrc, .wp-env.json, tsconfig.json, node_modules installed
  - Deviation: Added `testsPort: 8890` — wp-env v11 rejects identical dev/tests ports when dev is 8889
  - Committed: `871a54a`
- **06-02 (Wave 2) ✅** — playwright.config.ts, global-setup.ts, fixtures/test.ts, smoke.spec.ts
  - TOOL-06 verified: wp_sudo cookies filtered from storageState
  - Committed: `de6ca85`
- **06-03 (Wave 3) ✅** — 2 of 2 tasks complete
  - ✅ .gitignore updated (Playwright artifacts, wp-env state) — committed with Wave 2
  - ✅ .github/workflows/e2e.yml — standalone CI workflow created

## Phase 7 Execution Progress

- **07-01 (Wave 1) ✅** — fixtures/test.ts + activateSudoSession, cookie.spec.ts (COOK-01/02/03), gate-ui.spec.ts (GATE-01/02/03)
  - Deviation 1: waitForURL predicate to exclude challenge page (bare /wp-admin/ resolved immediately)
  - Deviation 2: GATE selectors use PHP-rendered spans (.activate [aria-disabled="true"]) not JS-modified anchors (.activate a)
  - All 8 tests pass (6 new + 2 smoke): 9.7s
- **07-02 (Wave 2) ✅** — admin-bar-timer.spec.ts (TIMR-01/02/03/04)
  - Deviation 1: page.clock.tick() doesn't exist in Playwright 1.58.2 — use runFor() instead
  - Deviation 2: TIMR-04 requires WP-CLI to expire PHP session before JS reload (PHP uses real time())
  - Deviation 3: WP-CLI container for port 8889 is 'cli' not 'tests-cli'
  - All 12 tests pass (4 new + 8 prior): 30s
- **07-03 (Wave 3) ✅** — challenge.spec.ts (CHAL-01/02/03) + mu-plugin.spec.ts (MUPG-01/02/03 + bonus)
  - Deviation 1: WordPress plugins.php relative href needs /wp-admin/ prefix for page.goto() to reach admin
  - Deviation 2: WP Sudo CLI policy (limited) blocks `wp plugin deactivate` — use withCliPolicyUnrestricted() pattern
  - Deviation 3: IP-based rate limiting transients (wp_sudo_ip_*) persist between test runs — must DELETE FROM options in beforeAll/afterAll
  - Deviation 4: Two Cancel links on challenge page (password + hidden 2FA form) — scope selector to #wp-sudo-challenge-password-step
  - All 19 tests pass (7 new + 12 prior): 83s
- **07-04 (Wave 4) ✅** — regression-baselines.spec.ts (VISN-01/02/03/04) + 4 baseline PNGs
  - Deviation 1: Clock ordering corrected — activateSudoSession() before page.clock.install() (matches Waves 2-3 pattern)
  - Deviation 2: Page-level clip (1280x32) instead of element screenshot for admin bar (element auto-sizes to timer text width)
  - Deviation 3: Timer text masked in admin bar snapshots — captures layout/color, not pixel-level text
  - All 23 tests pass (4 new + 19 prior): 1.5m
  - Committed: `09aff18`

## Key Decisions (Phase 7)

- waitForURL in activateSudoSession must use predicate function, not regex — challenge page URL already matches /wp-admin/ so bare regex resolves immediately before AJAX completes
- Gate UI tests must target PHP-rendered `<span class="wp-sudo-disabled">` elements (not JS-modified `<a>` tags) — filter_plugin_action_links() in class-gate.php replaces anchors server-side before gate-ui.js runs
- activateSudoSession is a standalone exported function (not a Playwright fixture) for simpler test usage
- Playwright 1.58.2 clock API: runFor(ms) is the equivalent of sinon tick() — there is no tick() method
- PHP/JS clock separation: page.clock.runFor() advances only browser JS time; PHP time() uses real wall clock — use WP-CLI to expire server-side sessions when testing reload-after-expiry
- wp-env container targeting: 'cli' container targets port 8889 (development site = browser tests); 'tests-cli' targets port 8890 (tests site)
- WordPress plugins.php activate links use relative hrefs (plugins.php?action=...) without leading slash — page.goto() resolves relative to origin not /wp-admin/, causing 404; prefix /wp-admin/ when href doesn't start with / or http
- WP Sudo CLI policy (default=limited) blocks gated WP-CLI commands (wp plugin deactivate) in test setup; withCliPolicyUnrestricted() pattern: wp option set cli_policy=unrestricted → run command → wp option delete (restores default)
- IP-based rate limiting uses WordPress transients (wp_sudo_ip_failure_event_* and wp_sudo_ip_lockout_until_*) that persist between test runs; `wp option list --search` cannot enumerate transients — use `wp transient delete --all` in beforeAll and afterAll of any spec that tests auth failure scenarios

## Phase 8 Execution Progress

- **08-01 (Wave 1) ✅** — keyboard.spec.ts (KEYB-01/02/03/04): Tab order, Enter submit, Ctrl+Shift+S nav/flash
  - Deviation 1 [Rule 1]: Chromium normalizes hex colors to rgb() in style.getPropertyValue() — check el.style.cssText instead
  - Deviation 2: Must call page.emulateMedia({ reducedMotion: 'no-preference' }) BEFORE keyboard.press() for KEYB-04
  - All 4 KEYB tests pass (27 total with prior suite, VISN-03 has pre-existing baseline drift unrelated to Phase 8)
  - Committed: `c149270`
- **08-02 (Wave 2) ✅** — admin-bar-deactivate.spec.ts (ABAR-01/02): click-to-deactivate, URL unchanged
  - Deviation 1 [Rule 1]: VISN-03/04 pre-existing flakiness — timer-node .ab-label mask boundary drift; fixed with maxDiffPixels:200
  - All 29 tests pass (2 new ABAR + all prior): 1.4m
  - Committed: `2c8f47e` (tests), `25935df` (full suite fix + docs)

## Key Decisions (Phase 8)

- Chromium normalizes hex color values (#4caf50) to rgb() notation in style.getPropertyValue(). Use el.style.cssText to verify inline styles set via JS — cssText preserves the original hex notation.
- page.emulateMedia({ reducedMotion: 'no-preference' }) must be called BEFORE the keyboard event for animation tests — wp-sudo-admin-bar.js reads matchMedia at keydown invocation time.
- Admin bar deactivation is a full-page navigation (302 redirect), NOT AJAX. Use Promise.all([waitForURL, click]) not waitForResponse(). PHP handle_deactivate() calls wp_safe_redirect() + exit.
- maxDiffPixels: N on toHaveScreenshot() tolerates mask boundary drift for dynamic-width elements. Use this (not higher threshold) when a small absolute pixel count varies due to JS-changed element width.

## Live Validation (2026-03-09)

- wp-env start: ✅ dev=http://localhost:8889, tests=http://localhost:8890
- WP Sudo plugin: ✅ active, v2.13.0
- Playwright full suite (Phase 8 Plan 02): 29/29 passed — all Phase 6-8 requirements complete
- ABAR-01/02: ✅ deactivation click removes cookie + node, URL pathname unchanged
- KEYB-01/02/03/04: ✅ Tab order, Enter submit, Ctrl+Shift+S navigation, Ctrl+Shift+S flash verified
- COOK-01/02/03: ✅ httpOnly, sameSite=Strict, path=/ verified
- GATE-01/02/03: ✅ aria-disabled, wp-sudo-disabled, click-no-navigate verified
- CHAL-01/02/03: ✅ stash-replay, form elements, wrong password inline error verified
- MUPG-01/02/03 + bonus: ✅ install/uninstall AJAX flow + 403 on no-session verified
- VISN-01/02/03/04: ✅ challenge card, settings form, admin bar active, admin bar expiring baselines (all passing with maxDiffPixels fix)
- PHP unit tests: ✅ 496 tests, 1293 assertions

## Post-Milestone Hardening (2026-03-15)

- Local multisite target: `https://multisite-subdomains.local`
- Drift control: `bin/local-plugin-dev.sh` supports `status`, `sync`, and `link` for copied vs symlinked Local plugin installs
- New regression: `tests/e2e/specs/multisite-network-admin.spec.ts` verifies network-admin reauth returns to `/wp-admin/network/plugins.php`
- Bootstrap fix: public plugin basename/path is recovered from active plugin state so symlinked Local installs do not generate filesystem-path asset URLs
- Coverage: unit (`tests/Unit/BootstrapTest.php`), integration (`tests/Integration/BootstrapTest.php`), Local browser regression, and hosted CI all green
- Important boundary: hosted Playwright CI is still single-site; multisite browser verification remains Local-only by design
