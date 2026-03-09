## Current Position

Phase: 7 (Core E2E Tests + Visual Regression Baselines) — in progress
Plan: 03 complete, proceeding to 04
Status: 3/4 plans complete
Last activity: 2026-03-09 -- Plan 07-03 (challenge stash-replay + MU-plugin AJAX tests) complete

## Project Reference

See: .planning/PROJECT.md (updated 2026-03-08)

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
- **07-04 (Wave 4)** — visual-regression.spec.ts — pending

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

## Live Validation (2026-03-09)

- wp-env start: ✅ dev=http://localhost:8889, tests=http://localhost:8890
- WP Sudo plugin: ✅ active, v2.13.0
- Playwright full suite: ✅ 19 passed (83s) — admin-bar-timer + challenge + cookie + gate-ui + mu-plugin + smoke
- COOK-01/02/03: ✅ httpOnly, sameSite=Strict, path=/ verified
- GATE-01/02/03: ✅ aria-disabled, wp-sudo-disabled, click-no-navigate verified
- CHAL-01/02/03: ✅ stash-replay, form elements, wrong password inline error verified
- MUPG-01/02/03 + bonus: ✅ install/uninstall AJAX flow + 403 on no-session verified
- PHP unit tests: ✅ 496 tests, 1293 assertions
