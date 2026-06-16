---
gsd_state_version: 1.0
milestone: v4.0
milestone_name: milestone
status: completed
last_updated: "2026-06-16T00:12:24.350Z"
last_activity: 2026-06-15 — Phase 11 executed; two-tier connector matcher shipped (commits b1ad0bb, 8970c23, dba8672). 793 unit tests passing, PHPStan L6 clean. SUMMARY at .planning/phases/11-connectors-registry-aware-matcher/11-01-SUMMARY.md.
progress:
  total_phases: 8
  completed_phases: 4
  total_plans: 10
  completed_plans: 10
---

## Current Position

Phase: 11 — Connectors Registry-Aware Matcher
Plan: 11-01-PLAN.md (1 plan, 1 wave, 3 TDD tasks) — EXECUTED
Status: PHASE COMPLETE — all CONN-01…CONN-06 requirements fulfilled. Advance to Phase 12.
Resume file: None
Last activity: 2026-06-15 — Phase 11 executed; two-tier connector matcher shipped (commits b1ad0bb, 8970c23, dba8672). 793 unit tests passing, PHPStan L6 clean. SUMMARY at .planning/phases/11-connectors-registry-aware-matcher/11-01-SUMMARY.md.

## Project Reference

Canonical current facts:

- `docs/release-status.md` — tagged/package release state and WordPress.org publication status.
- `docs/current-metrics.md` — current test, size, and architectural counts.
- `docs/ROADMAP.md` — current roadmap and priority order.
- `CHANGELOG.md` — shipped release contents.

**Core value:** Every destructive admin action requires proof the person at the keyboard is still the authenticated user.
**Current focus:** Milestone v4.0.0 — Phase 11 (Connectors Registry-Aware Matcher). Design review required before TDD.

## Active Priorities (v4.0.0 milestone)

Phase execution order:
1. **Phase 11 — Connectors Registry-Aware Matcher** (CONN-01 through CONN-06): Close the `wordpress_api_key` gating gap. Design review required first.
2. **Phase 12 — Breaking Changes and Floor Bump** (BRK-01 through BRK-07): Remove `compatibility` mode + `sudo_can()`, raise floors, drop shims. Design review required first.
3. **Phase 13 — Migration Safety and Governance Audit** (MIG-01 through MIG-07): After Phase 12.
4. **Phase 14 — WordPress.org Readiness** (ORG-01 through ORG-07): After Phase 12.
5. **Phase 15 — Manual Testing Environment Checklist** (ENV-01 through ENV-03): After Phase 12.

Phases 13, 14, and 15 can run concurrently after Phase 12 completes.

## Performance Metrics

- v1 requirements this milestone: 30
- Phases: 5 (phases 11-15)
- Requirements mapped: 30/30

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
