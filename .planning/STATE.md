---
gsd_state_version: 1.0
milestone: v4.3.0
milestone_name: Post-.org Readiness Hardening
status: in_progress
last_updated: "2026-06-28T22:40:00Z"
last_activity: "2026-06-28 — Completed Phase 16 localization packaging readiness: POT generation/check commands, committed POT, translator-comment cleanup, and docs."
progress:
  total_phases: 4
  completed_phases: 1
  total_plans: 0
  completed_plans: 0
---

## Current Position

Phase: v4.3.0 milestone — **IN PROGRESS**. Phase 16 localization/translation packaging readiness is complete; next planned phase is Phase 17 release-only environment assurance, or Phase 19 if 2FA bridge planning is the priority.
Branch: main — post-v4.2.2 Phase 16 implementation work. Latest tagged release remains `v4.2.2`; localization packaging readiness is unreleased drift.
Done & merged to main: v4.0.0 milestone phases 11-15 complete; v4.2.2 tagged/pushed; Patchstack 2FA compatibility documented and captured as a GSD todo.
Open threads: WordPress.org submission remains intentionally delayed/on hold; keep `docs/wporg-submission-checklist.md` ready. Remaining v4.3.0 work focuses on release-only environment assurance, E2E runtime review, and 2FA bridge planning.
Resume file: .planning/phases/16-localization-and-translation-packaging-readiness/16-CONTEXT.md
Last activity: 2026-06-28 — Phase 16 implemented and validated.

## Project Reference

Canonical current facts:

- `docs/release-status.md` — tagged/package release state and WordPress.org publication status.
- `docs/current-metrics.md` — current test, size, and architectural counts.
- `docs/ROADMAP.md` — current roadmap and priority order.
- `CHANGELOG.md` — shipped release contents.

**Core value:** Every destructive admin action requires proof the person at the keyboard is still the authenticated user.
**Current focus:** v4.3.0 post-.org readiness hardening is planned. Latest tagged release is **4.2.2** (see `docs/release-status.md`). WordPress.org submission (`docs/wporg-submission-checklist.md`) is delayed/on hold, but readiness should be maintained.

## Active Priorities (v4.3.0 milestone)

Phase execution order:
1. ✅ **Phase 16 — Localization and Translation Packaging Readiness** (L10N-01 through L10N-03): complete — Composer i18n commands, committed POT, translator-comment cleanup, and release docs.
2. ⬜ **Phase 17 — Release-only Environment Assurance** (REL-01 through REL-03): not started.
3. ⬜ **Phase 18 — E2E Runtime Review and Tuning Decision** (CI-01 through CI-03): not started.
4. ⬜ **Phase 19 — 2FA Bridge Planning and Compatibility Matrix** (2FA-01 through 2FA-03): not started; includes upstream Two Factor lifecycle bridge planning and Patchstack as a second-tier/manual-test target.

**Latest release: 4.2.2 (2026-06-28)** — `docs/release-status.md` is canonical for release/package metadata. WordPress.org submission is delayed/on hold; keep `docs/wporg-submission-checklist.md` ready to execute at any time.

## Performance Metrics

- v1 requirements this milestone: 12
- Phases: 4 (phases 16-19)
- Requirements mapped: 12/12
- Phase 16 requirements complete: 3/3
- Phase 13.1 Plan 01: 8 min, 3 tasks, 4 files (completed 2026-06-28)
- Phase 13.1 Plan 02: 4 min, 3 tasks, 2 files (completed 2026-06-28)

## Accumulated Context

- Security Hardening Sprint (5 phases, v2.10.2-v2.13.0) complete and archived.
- Playwright E2E milestone (v2.14, phases 6-8) complete; historical execution details in ROADMAP.md.
- v3.0.0 shipped operator tooling: policy presets, Request / Rule Tester, event store, and dashboard widget.
- v3.2.0 shipped governance capabilities and broad security hardening.
- v3.3.0 fixed governance backfill and audit/event-store details.
- v3.4.0 hardened recovery mode, repaired Psalm gating, hardened CI permissions, and corrected documentation drift.
- Current test and size counts are centralized in `../docs/current-metrics.md`.
- WordPress 7.0 GA shipped May 20, 2026; package metadata says `Tested up to: 7.0`.
- This plugin is not currently published to the WordPress.org plugin repository.

### Roadmap Evolution

- Phase 13.1 inserted after Phase 13 (2026-06-19): Access-tab UX polish (user picker + plain-English capability labels) + CI test-speed (matrix trim, WP-test-lib cache, E2E shard rebalance). GSD-routes the PR #88 follow-up (merged `6abd333`) and spawned task `task_671a7d54` into one reviewable cleanup phase ahead of Phase 14 (WordPress.org Readiness).
- External contributions merged to main 2026-06-21 (Nathan / `nathanpixodeo`, all reviewed + approved): **#96** `fix: rename recovery dashboard label` Recovery→Break-glass (closes #68; locally validated 813 tests + lint + PHPStan/Psalm green as CI substitute), **#97** `docs: clarify break-glass recovery terminology` (advances the Core-Trac terminology-alignment roadmap item), **#98** `docs: add manual environment matrix` (**substantially delivers Phase 15 / ENV-01…03**). Admin-merged because fork CI can't be triggered for a first-time contributor without maintainer approval; #96 validated locally instead. These external PRs overlapped planned GSD work and are now reflected in the completed Phase 14/15 record.

### Pending Todos

- `2026-06-28-add-patchstack-2fa-compatibility-target.md` — Track Patchstack Security as a second-tier 2FA bridge/manual compatibility target behind the upstream Two Factor lifecycle bridge.

## Key Decisions (Phase 13.1-01, 2026-06-28)

- Access picker is convenience UI only; backend grant/revoke authorization contract remains unchanged.
- Broader picker eligibility for custom roles or direct-cap users is deferred beyond Plan 01.
- Capability option values remain exact slugs while operator-facing labels use a slug-keyed map.

## Key Decisions (Phase 13.1-02, 2026-06-28)

- Existing CI-speed implementation already present on main was verified and documented rather than duplicated or reshuffled.
- Four E2E groups remain the right shape because wp-env startup is a fixed ~60s/job floor; group 2 was rebalanced within the existing matrix.

## Key Decisions (Phase 13-03, 2026-06-17)

- WP_SUDO_RECOVERY_MODE PHP constant cannot be undefined in integration tests; tests define once as true and use manage_options scope (subscriber is denied even with constant active) to prove no other break-glass exists.
- ReflectionProperty used to read private static $compat_option_cleared in clear-on-detection test per suite pattern; Admin::reset_cache() resets flag before arrange.
- Integration test environment (MySQL + WP test suite) not available in this session; files are RED-capable and syntax-verified; execution deferred to CI or manual provisioning.
- Pre-commit reviewer subagent could not be spawned (agent lacks Agent tool); manual quality gate verification substituted.

## Key Decisions (Phase 13-01, 2026-06-17)

- Static bool flag (not transient) for admin_init→admin_notices same-request signaling in compat mode cleanup.
- do_action('wp_sudo_inert_governance_mode_detected') replaces _doing_it_wrong() for governance-mode detection signal.
- Cleanup broadened: any non-false option value triggers delete, not just 'compatibility'.
- admin_init does NOT fire under WP-CLI/cron; cleanup is admin-HTTP-only; upgrade_4_0_0() covers non-admin contexts.
- MIG-05 verified clean: no bare manage_options access gate in includes/ except documented break-glass exceptions.

## Key Decisions Locked (v4.0.0 kickoff, 2026-06-13)

- Minimum floors: WordPress 6.4 (drops `wp_get_admin_notice` shim), PHP 8.2 (honest platform pin, security-supported EOL Dec 2026).
- Compatibility-mode removal bundled into 4.0.0 (no interim 3.5.0 release) — defensible because plugin is not yet on WordPress.org.
- Product name is "Sudo"; package/slug/text-domain remain `wp-sudo`; repo is `dknauss/Sudo`.
- Connectors matcher: registry-first (static cache per request), regex-fallback for pre-7.0 and late-registered connectors. Fail toward gating.
- Design review REQUIRED before TDD for Phase 11 (security-sensitive matcher) and Phase 12 (capability-check contract change). See CLAUDE.md.

## Implementation Notes (Phase 11)

- Target file: `includes/class-action-registry.php`
- Target method: `is_connector_api_key_setting_name()` (lines ~1045-1047 at research time — verify at execute time, lines drift)
- `request_contains_connector_api_key()` outer loop unchanged; change propagates from `is_connector_api_key_setting_name()`
- Add `self::$connector_setting_names_cache = null;` to `reset_cache()` for unit test hygiene
- Integration tests belong in `tests/Integration/` and require WP 7.0 with Connectors API
- Every Connectors claim in the commit message must cite verified WordPress core source (confabulation-prevention per CLAUDE.md)

## Implementation Notes (Phase 12)

- `sudo_can()` alias: `includes/functions-governance.php` lines 90-112 at research time. Delete entire `if ( ! function_exists( 'sudo_can' ) )` block. Verify zero internal callers first (`grep -rn 'sudo_can(' includes/ wp-sudo.php`).
- `compatibility` mode branches: `functions-governance.php` lines 82-85 (in `wp_sudo_can()`) and 168-169 (in `wp_sudo_map_governance_meta_cap()`).
- Deprecation/migration notice: `_doing_it_wrong()` + persistent admin notice in same 4.0.0 release. Design review decides whether it fires on every request or admin-only.
- `uninstall.php` already deletes `wp_sudo_governance_mode` — confirm before commit.
- `wp_get_admin_notice` shims: `includes/class-admin.php` lines 2442 and 2475 at research time. Two call sites, ~12 lines total.
- CI matrix: rename "6.2 support-floor" lane to "6.4 support-floor".

## Historical Playwright Milestone Notes

The details below are retained because they contain useful test-environment decisions and E2E pitfalls, but they are not the current GSD phase. See ROADMAP.md for full execution log.

### Key Decisions (Phase 7)

- waitForURL in activateSudoSession must use predicate function, not regex — challenge page URL already matches /wp-admin/ so bare regex resolves immediately before AJAX completes
- Gate UI tests must target PHP-rendered `<span class="wp-sudo-disabled">` elements (not JS-modified `<a>` tags) — filter_plugin_action_links() in class-gate.php replaces anchors server-side before gate-ui.js runs
- activateSudoSession is a standalone exported function (not a Playwright fixture) for simpler test usage
- Playwright 1.58.2 clock API: runFor(ms) is the equivalent of sinon tick() — there is no tick() method
- PHP/JS clock separation: page.clock.runFor() advances only browser JS time; PHP time() uses real wall clock — use WP-CLI to expire server-side sessions when testing reload-after-expiry
- wp-env container targeting: 'cli' container targets port 8889 (development site = browser tests); 'tests-cli' targets port 8890 (tests site)
- WordPress plugins.php activate links use relative hrefs (plugins.php?action=...) without leading slash — page.goto() resolves relative to origin not /wp-admin/, causing 404; prefix /wp-admin/ when href doesn't start with / or http
- WP Sudo CLI policy (default=limited) blocks gated WP-CLI commands (wp plugin deactivate) in test setup; withCliPolicyUnrestricted() pattern: wp option set cli_policy=unrestricted → run command → wp option delete (restores default)
- IP-based rate limiting uses WordPress transients (wp_sudo_ip_failure_event_* and wp_sudo_ip_lockout_until_*) that persist between test runs; `wp option list --search` cannot enumerate transients — use `wp transient delete --all` in beforeAll and afterAll of any spec that tests auth failure scenarios

### Key Decisions (Phase 8)

- Chromium normalizes hex color values (#4caf50) to rgb() notation in style.getPropertyValue(). Use el.style.cssText to verify inline styles set via JS — cssText preserves the original hex notation.
- page.emulateMedia({ reducedMotion: 'no-preference' }) must be called BEFORE the keyboard event for animation tests — wp-sudo-admin-bar.js reads matchMedia at keydown invocation time.
- Admin bar deactivation is a full-page navigation (302 redirect), NOT AJAX. Use Promise.all([waitForURL, click]) not waitForResponse(). PHP handle_deactivate() calls wp_safe_redirect() + exit.
- maxDiffPixels: N on toHaveScreenshot() tolerates mask boundary drift for dynamic-width elements. Use this (not higher threshold) when a small absolute pixel count varies due to JS-changed element width.


## Key Decisions (Phase 13.1-03, 2026-06-28)

- Searchable grant-user picker remains progressive enhancement over the native administrator select; selected numeric user ID remains the submitted contract.
- Access tab screenshot refresh used the project Playwright capture workflow against a dev-only wp-env config because another wp-env stack occupied the test port.
- Only `.wordpress-org/screenshot-6.png` was retained from the capture run; unrelated screenshot churn was reverted to keep Task 3 atomic.
