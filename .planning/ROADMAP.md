# GSD Roadmap Context

> **Current routing note (2026-06-13):** Milestone v4.0.0 (Pre-Public Hardening Baseline) is the active milestone. Phases 11-15 are defined below. The Playwright E2E milestone (phases 6-8) is complete and retained below for historical execution detail. Canonical roadmap truth is `../docs/ROADMAP.md`; current release/package truth is `../docs/release-status.md`.

---

# Active Roadmap: Milestone v4.0.0 — Pre-Public Hardening Baseline

**Milestone:** v4.0.0
**Status:** In progress — requirements defined, roadmap created 2026-06-13, execution not yet started
**Previous milestone last phase:** 10 (Settings UI Revision, v3.4.0)
**Phase numbering continues from:** 11

**Strategic framing:** v4.0.0 is a focused pre-public hardening baseline, not a feature release. It simplifies governance, verifies WP 7.0 Connectors, removes legacy compatibility paths, sets final platform requirements, and prepares docs/assets for eventual WordPress.org publication. No new product features ship.

## Phases

- [x] **Phase 11: Connectors Registry-Aware Matcher** - Close the `wordpress_api_key` gating gap with a two-tier registry-first, regex-fallback matcher (completed 2026-06-16)
- [x] **Phase 12: Breaking Changes and Floor Bump** - Remove deprecated APIs (`sudo_can()`, `compatibility` mode), raise WP to 6.4 and PHP to 8.2, drop shims (completed 2026-06-16)
- [ ] **Phase 13: Migration Safety and Governance Audit** - Verify 3.0-3.4 upgrade paths are clean, audit capabilities, confirm lockout-safe first-run
- [ ] **Phase 14: WordPress.org Readiness** - readme validator pass, screenshots/assets, brand consistency, SECURITY.md, submission checklist
- [ ] **Phase 15: Manual Testing Environment Checklist** - Extend MANUAL-TESTING.md with environment matrix, Connectors verification steps

## Phase Details

### Phase 11: Connectors Registry-Aware Matcher

**Goal**: The gate correctly intercepts connector-credential writes for all registered connectors — including Akismet's `wordpress_api_key` which the current regex misses — by querying the WP 7.0 registry first and falling back to the existing regex on pre-7.0 sites.

**Depends on**: Nothing (independent; can lead the milestone)

**Requirements**: CONN-01, CONN-02, CONN-03, CONN-04, CONN-05, CONN-06

**Pre-Implementation Design Review Required**: YES — this touches the security-sensitive `is_connector_api_key_setting_name()` matcher in `includes/class-action-registry.php`. Per CLAUDE.md, write a 3-8 sentence design brief covering: the `wordpress_api_key` gap and threat; the two-tier approach (registry-first static cache, regex fallback); what the guard blocks and must NOT block (benign settings like `blogname`, `siteurl`); static cache invalidation for unit tests; multisite behavior; registry timing relative to gate evaluation. Spawn reviewer before TDD cycle.

**Success Criteria** (what must be TRUE):
  1. A `POST /wp/v2/settings` request writing `wordpress_api_key` is intercepted by the gate on a WP 7.0 install, triggering the reauthentication challenge (the bug is closed)
  2. A `POST /wp/v2/settings` request writing a custom connector's arbitrary `setting_name` (non-`connectors_*_api_key` pattern) is gated when that connector is in the WP 7.0 registry
  3. Standard `connectors_*_api_key` writes (e.g., `connectors_ai_openai_api_key`) remain gated with no regression
  4. On a WP version without `wp_get_connectors`, the matcher falls back to regex-only and standard-pattern names are still gated (pre-7.0 backward compatibility preserved)
  5. Benign settings writes (`blogname`, `siteurl`, `timezone_string`) pass through without being gated under the new matcher

**Plans**: 1 — `11-01-PLAN.md` (RED unit tests → GREEN matcher rewrite → integration + docs)

### Phase 12: Breaking Changes and Floor Bump

**Goal**: v4.0.0 ships with all deprecated APIs removed, `compatibility` governance mode fully excised (with a migration notice for sites still using it), and minimum requirements honestly declared as WordPress 6.4 and PHP 8.2 — with the corresponding shims deleted.

**Depends on**: Nothing (independent; can run after Phase 11 or in parallel)

**Requirements**: BRK-01, BRK-02, BRK-03, BRK-04, BRK-05, BRK-06, BRK-07

**Pre-Implementation Design Review Required**: YES — removing `compatibility` governance mode changes the capability-check contract in `wp_sudo_can()` and `wp_sudo_map_governance_meta_cap()`. Per CLAUDE.md, write a design brief covering: what `compatibility` mode enabled and what its removal blocks; where the deprecation/migration notice fires (every request vs admin-context-only) and the fatigue tradeoff; whether `WP_SUDO_RECOVERY_MODE` remains the sole break-glass; that `uninstall.php` already deletes the governance option; the `sudo_can()` removal scope (collision-avoidance guard pattern preserved on surviving `wp_sudo_can()`). Spawn reviewer before TDD cycle.

**Success Criteria** (what must be TRUE):
  1. Calling `sudo_can()` produces a fatal error (function does not exist); `wp_sudo_can()` is the only surviving function and works correctly
  2. A site with `wp_sudo_governance_mode = 'compatibility'` in the database shows a persistent admin notice after upgrading to 4.0.0, and governance behaves as `strict` — no undefined behavior, no broken capability checks
  3. `WP_SUDO_RECOVERY_MODE` constant still grants access and is the sole remaining break-glass path
  4. The plugin header, `readme.txt`, and `composer.json` all declare `Requires at least: 6.4` and `Requires PHP: 8.2`; the CI support-floor lane runs WP 6.4 (not 6.2)
  5. `includes/class-admin.php` contains no `function_exists('wp_get_admin_notice')` branches; admin notices render via `wp_get_admin_notice()` unconditionally

**Plans**: 3 plans

Plans:
- [ ] 12-01-PLAN.md — Remove compatibility mode + `sudo_can()` alias + persistent migration notice (BRK-01, BRK-02, BRK-03; design review first, TDD)
- [ ] 12-02-PLAN.md — Raise WP 6.4 / PHP 8.2 floors across header/readme/composer/CI, drop php80-tests, close BRK-06 (BRK-04, BRK-05, BRK-06)
- [ ] 12-03-PLAN.md — Integrator migration notes: CHANGELOG block + readme Upgrade Notice + developer-reference "Migrating to 4.0" (BRK-07)

### Phase 13: Migration Safety and Governance Audit

**Goal**: No 3.0-3.4 upgrade path leaves an install in broken, undefined, or lockout-prone state after v4.0.0 lands. The capability model is audited, uninstall remains correct, and the first-run flow is documented as lockout-safe.

**Depends on**: Phase 12 (governance simplification must land before migration safety can be verified against the final code)

**Requirements**: MIG-01, MIG-02, MIG-03, MIG-04, MIG-05, MIG-06, MIG-07

**Success Criteria** (what must be TRUE):
  1. Upgrading from a v3.3 install with `wp_sudo_governance_mode = 'compatibility'` to v4.0.0 lands on `strict` governance with a visible admin notice and no PHP errors, broken UI, or missing capabilities
  2. After compatibility-mode removal, `WP_SUDO_RECOVERY_MODE` break-glass grants full access and no other break-glass path exists
  3. Uninstalling v4.0.0 (single-site and multisite) leaves no `wp_sudo_governance_mode` option, no `_wp_sudo_*` user meta, and no legacy v1 Site Manager role in the database
  4. On a multisite upgrade, network governance state and super-admin capabilities are intact; no cross-site capability bleed
  5. Every admin and settings screen renders without a bare `manage_options` check — all use dedicated Sudo capabilities (`manage_wp_sudo` etc.)
  6. PHPUnit tests cover the lockout scenario (first activation with no super admin) and the compatibility-mode-upgrade recovery path

**Plans**: TBD

### Phase 14: WordPress.org Readiness

**Goal**: The plugin repository entry and supporting assets accurately represent the current plugin, the readme passes the WordPress.org validator, brand/identity is internally consistent, security disclosure is documented, and a submission checklist exists in the repo.

**Depends on**: Phase 12 (code and UI must be final before screenshots and readme reflect the accurate state)

**Requirements**: ORG-01, ORG-02, ORG-03, ORG-04, ORG-05, ORG-06, ORG-07

**Success Criteria** (what must be TRUE):
  1. `readme.txt` passes the WordPress.org readme validator (correct headers, all required sections present, `Stable tag` and `Tested up to` consistent with current release)
  2. `assets/` contains current screenshots of the live UI — Settings tabs, Access tab, Session Activity widget, Request/Rule Tester — plus banner and icon; no screenshots show stale or removed UI
  3. UI strings in the plugin consistently read "Sudo" (product name); `wp-sudo` slug/text-domain and `dknauss/Sudo` GitHub URL are documented in the readme or developer reference as intentional divergences from the product name
  4. `SECURITY.md` states the vulnerability reporting channel, supported-versions policy, and whether GitHub issues are acceptable for initial security contact
  5. The request-stash redaction status in `docs/ROADMAP.md` correctly reflects what shipped (suffix-based redaction, not pattern-based build), verified against the redaction code in `class-request-stash.php`

**Plans**: TBD

### Phase 15: Manual Testing Environment Checklist

**Goal**: Every release candidate is verified against a documented environment matrix that covers the new minimum floor, a managed-host environment, and Connectors-credential gating — so environment-specific regressions are caught before tagging.

**Depends on**: Phase 12 (minimum-requirement bump must be locked before checklist can reference the correct floor; ENV-02 requires BRK-04's 6.4 floor to be established)

**Requirements**: ENV-01, ENV-02, ENV-03

**Success Criteria** (what must be TRUE):
  1. `tests/MANUAL-TESTING.md` has an "Environment Matrix" section listing named environments to verify before each release
  2. The checklist requires verification on at least one Apache environment, one managed WordPress host (Pressable, WP Engine, or Cloudways), and the minimum-supported WordPress version (6.4)
  3. The checklist includes a "Connectors Credential Gating" section with explicit steps for verifying cookie-auth and Application Password `POST /wp/v2/settings` requests writing `wordpress_api_key` and at least one `connectors_*_api_key` field are intercepted by the gate

**Plans**: TBD

## Progress

**Execution Order:**
Phases execute in dependency order: 11 → 12 → 13 (after 12) → 14 (after 12) → 15 (after 12)
Note: Phases 13, 14, and 15 all depend on Phase 12 completing. Phases 13 and 14 can run concurrently after Phase 12. Phase 15 is low-effort and can follow 12 directly.

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 11. Connectors Registry-Aware Matcher | 1/1 | Complete    | 2026-06-16 | - |
| 12. Breaking Changes and Floor Bump | 3/3 | Complete    | 2026-06-16 | - |
| 13. Migration Safety and Governance Audit | v4.0.0 | 0/TBD | Not started | - |
| 14. WordPress.org Readiness | v4.0.0 | 0/TBD | Not started | - |
| 15. Manual Testing Environment Checklist | v4.0.0 | 0/TBD | Not started | - |

---

# Historical Roadmap: Playwright E2E Test Infrastructure

**Milestone:** v2.14 — Playwright E2E Test Infrastructure
**Status:** COMPLETE — all 3 phases done, all 32 v1 requirements verified
**Created:** 2026-03-08
**Depth:** Standard (3 phases)
**Source:** .planning/research/SUMMARY.md, .planning/REQUIREMENTS.md

---

### Phase 6: E2E Infrastructure Scaffold

**Goal:** Stand up the complete Playwright + wp-env toolchain from zero Node.js baseline. First smoke test passes locally and in CI. Login helper works with storageState. No behavioral tests yet — this phase is pure infrastructure.

**Requirements covered:** TOOL-01, TOOL-02, TOOL-03, TOOL-04, TOOL-05, TOOL-06

**Plans:** 3/3 plans complete

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

## Historical Phase Summary

| Phase | Goal | Requirements | Plans | Depends On |
|-------|------|-------------|-------|------------|
| 6 | E2E infrastructure scaffold | TOOL-01-06 (6) | 3 | None |
| 7 | Core E2E tests + visual regression | COOK, TIMR, MUPG, GATE, CHAL, VISN (20) | 4 | Phase 6 |
| 8 | Keyboard + admin bar interaction | KEYB, ABAR (6) | 2 | Phase 7 |

**Total (v2.14 milestone):** 3 phases, 9 plans, 32 v1 requirements
