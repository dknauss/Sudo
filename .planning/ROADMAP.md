# Roadmap: Playwright E2E Test Infrastructure

**Milestone:** v2.14 — Playwright E2E Test Infrastructure
**Status:** COMPLETE — all 3 phases done, all 32 v1 requirements verified
**Created:** 2026-03-08
**Depth:** Standard (3 phases)
**Source:** .planning/research/SUMMARY.md, .planning/REQUIREMENTS.md

---

### Phase 6: E2E Infrastructure Scaffold

**Goal:** Stand up the complete Playwright + wp-env toolchain from zero Node.js baseline. First smoke test passes locally and in CI. Login helper works with storageState. No behavioral tests yet — this phase is pure infrastructure.

**Requirements covered:** TOOL-01, TOOL-02, TOOL-03, TOOL-04, TOOL-05, TOOL-06

**Plans:** 3 plans

Plans:
- [ ] 06-01-PLAN.md — Node.js toolchain: package.json, .nvmrc, .wp-env.json, tsconfig.json
- [ ] 06-02-PLAN.md — Playwright config, global-setup with auth/cookie filtering, fixture, smoke test
- [ ] 06-03-PLAN.md — CI workflow (e2e.yml) and .gitignore updates

**Key decisions:**
- `@playwright/test` 1.58.2, `@wordpress/env` 11.1.0 (exact versions, pinned)
- Chromium only (~300MB install)
- Port 8889 for wp-env (avoids conflict with local dev sites on 8888)
- `tests/e2e/` directory structure (third test tier alongside Unit and Integration)
- `global-setup.ts` logs in once, saves WordPress auth cookies to `storageState`
- Sudo token cookies explicitly excluded from `storageState`
- Separate CI workflow (`e2e.yml`) — no changes to `phpunit.yml`
- `workers: 1` — single WordPress instance

**Pitfalls addressed:** 1 (stale wp-env state), 5 (cold-start latency), 6 (stale sudo cookies in storageState), 9 (port conflict), 10 (TypeScript scope)

**New files:**
- `package.json` — devDependencies only
- `.wp-env.json` — plugin mount, PHP 8.2, port 8889
- `.nvmrc` — pin Node 20
- `playwright.config.ts` — testDir, baseURL, workers, retries, reporter
- `tests/e2e/global-setup.ts` — login → storageState
- `tests/e2e/fixtures/test.ts` — extended test with WP admin helpers
- `tests/e2e/specs/smoke.spec.ts` — first smoke test (settings page loads)
- `.github/workflows/e2e.yml` — CI job
- `.gitignore` updates — `node_modules/`, `tests/e2e/artifacts/`, `playwright-report/`

**Success criteria:**
- `npx wp-env start && npx playwright test` passes locally
- CI workflow runs, starts wp-env, runs smoke test, uploads artifacts on failure
- `storageState` file created with WP auth cookies, no `wp_sudo_token`
- Smoke test navigates to Settings → Sudo and asserts page title

---

### Phase 7: Core E2E Tests + Visual Regression Baselines

**Goal:** Write the E2E tests that close the 5 PHPUnit-uncoverable gaps: cookie attributes, admin bar timer JS, MU-plugin AJAX, gate UI disabled buttons, and challenge stash-replay flow. Capture visual regression baselines for WP 7.0. This phase delivers the milestone's core value.

**Requirements covered:** COOK-01, COOK-02, COOK-03, TIMR-01, TIMR-02, TIMR-03, TIMR-04, MUPG-01, MUPG-02, MUPG-03, GATE-01, GATE-02, GATE-03, CHAL-01, CHAL-02, CHAL-03, VISN-01, VISN-02, VISN-03, VISN-04

**Plans:** 4 plans

Plans:
- [ ] 07-01-PLAN.md — activateSudoSession fixture helper + cookie attribute tests (COOK-01-03) + gate UI tests (GATE-01-03)
- [ ] 07-02-PLAN.md — Admin bar timer tests with page.clock (TIMR-01-04)
- [ ] 07-03-PLAN.md — Challenge stash-replay flow (CHAL-01-03) + MU-plugin AJAX (MUPG-01-03)
- [ ] 07-04-PLAN.md — Visual regression baselines captured and committed (VISN-01-04)

**Key decisions:**
- Cookie verification via `context.cookies()` API — programmatic, no screenshots
- Admin bar timer tests use `page.clock.install()` + `page.clock.tick()` for deterministic time control
- Challenge flow tests use `Promise.all([waitForURL, click])` pattern for AJAX navigation
- activateSudoSession is a standalone exported function (not a fixture) — simpler to call with just `page`
- Visual snapshots use `toHaveScreenshot()` clipped to specific elements (challenge card, settings form, admin bar node)
- Snapshot threshold: `threshold: 0.05` for stable elements, `threshold: 0.1` for text-heavy admin bar
- Admin bar timer masked in non-timer visual snapshots; clock frozen for timer snapshots

**Pitfalls addressed:** 2 (AJAX navigation pattern), 4 (countdown changes DOM), 7 (iframe-break), 8 (dynamic timestamps in snapshots)

**Test files:**
- `tests/e2e/specs/cookie.spec.ts` — COOK-01, COOK-02, COOK-03
- `tests/e2e/specs/admin-bar-timer.spec.ts` — TIMR-01, TIMR-02, TIMR-03, TIMR-04
- `tests/e2e/specs/mu-plugin.spec.ts` — MUPG-01, MUPG-02, MUPG-03
- `tests/e2e/specs/gate-ui.spec.ts` — GATE-01, GATE-02, GATE-03
- `tests/e2e/specs/challenge.spec.ts` — CHAL-01, CHAL-02, CHAL-03
- `tests/e2e/specs/visual/regression-baselines.spec.ts` — VISN-01, VISN-02, VISN-03, VISN-04

**Success criteria:**
- All 5 PHPUnit-uncoverable scenarios have passing E2E tests
- Cookie `httpOnly`, `sameSite` values asserted programmatically
- Admin bar timer countdown verified with clock manipulation (60s threshold, 0s reload)
- MU-plugin AJAX install/uninstall flow exercised end-to-end
- Gate UI disabled buttons verified with `aria-disabled` assertions
- Challenge stash-replay flow completes: gated action → challenge → auth → destination
- Visual baselines committed for challenge card, settings form, admin bar node
- CI passes with all tests green

---

### Phase 8: Keyboard Navigation + Admin Bar Interaction E2E — COMPLETE

**Goal:** Complete the E2E suite with keyboard-driven tests: Tab order on challenge page, Enter to submit, Ctrl+Shift+S shortcut behavior, and admin bar click-to-deactivate. These close the remaining user interaction gaps and establish the accessibility testing pattern for future milestones.

**Requirements covered:** KEYB-01, KEYB-02, KEYB-03, KEYB-04, ABAR-01, ABAR-02

**Key decisions:**
- All four KEYB tests consolidated in a single flat spec file (tests/e2e/specs/keyboard.spec.ts) — follows established Phase 7 flat-file pattern, not subdirectory split
- Keyboard tests use `page.keyboard.press()` for Tab, Enter
- Focus assertions use `page.evaluate(() => document.activeElement?.id)`
- Shortcut tests use `page.keyboard.press('Control+Shift+S')` — Control modifier for Linux CI (JS checks ctrlKey || metaKey)
- KEYB-04 uses `page.emulateMedia({ reducedMotion: 'no-preference' })` before pressing shortcut — admin-bar.js guards flash on prefers-reduced-motion
- Admin bar deactivation asserts URL unchanged after click (PHP wp_safe_redirect strips deactivation params)
- Shortcut flash verified via inline style check immediately after keypress (synchronous style mutation, 300ms setTimeout removes it)
- ABAR tests use beforeEach with activateSudoSession to ensure admin bar node is present for both tests

**Test files:**
- `tests/e2e/specs/keyboard.spec.ts` — KEYB-01, KEYB-02, KEYB-03, KEYB-04
- `tests/e2e/specs/admin-bar-deactivate.spec.ts` — ABAR-01, ABAR-02

**Success criteria:**
- Tab key traverses challenge page form in correct order (password input → submit → cancel)
- Enter submits challenge form
- Ctrl+Shift+S navigates to challenge when no session active
- Ctrl+Shift+S flashes admin bar when session is active (inline style #4caf50 asserted)
- Admin bar click deactivates session (cookie absent, timer node gone)
- URL pathname unchanged after admin bar deactivation click
- All tests pass in CI

**Plans:** 2 plans

Plans:
- [x] 08-01-PLAN.md — Keyboard navigation + shortcut tests (KEYB-01-04)
- [x] 08-02-PLAN.md — Admin bar deactivation + CI verification + milestone docs (ABAR-01-02)

---

## Phase Summary

| Phase | Goal | Requirements | Plans | Depends On |
|-------|------|-------------|-------|------------|
| 6 | E2E infrastructure scaffold | TOOL-01-06 (6) | 3 | None |
| 7 | Core E2E tests + visual regression | COOK, TIMR, MUPG, GATE, CHAL, VISN (20) | 4 | Phase 6 |
| 8 | Keyboard + admin bar interaction | KEYB, ABAR (6) | 2 | Phase 7 |

**Total:** 3 phases, 9 plans, 32 v1 requirements
**Estimated effort:** ~2 weeks (Phase 6: 2-3 days, Phase 7: 5-7 days, Phase 8: 2-3 days)
**Time-sensitive:** Visual regression baselines (VISN-01-04) should be captured before WP 7.0 GA (April 9, 2026)
