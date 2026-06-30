# WP Sudo

> **GSD project context.** Keep this file aligned enough for planning/routing. Canonical volatile facts still live in `docs/current-metrics.md`, `docs/release-status.md`, and `docs/ROADMAP.md`.


## What This Is

WP Sudo is a WordPress plugin that provides action-gated reauthentication. Dangerous admin operations (plugin activation, user deletion, critical settings changes, etc.) require password confirmation before they proceed — regardless of user role. It covers 7 request surfaces (admin UI, REST API, AJAX, WP-CLI, Cron, XML-RPC, WPGraphQL) with per-surface policy controls.

## Core Value

Every destructive WordPress admin action requires proof that the person at the keyboard is still the authenticated user — not a hijacked session, XSS payload, or unattended browser.


## Current State

No active GSD milestone is open. Milestone v4.3.1 — E2E Shard Rebalance is complete and archived after PR #129 merged on 2026-06-30.

**Most recent GSD milestone outcome:**
- Refreshed current GitHub Actions E2E runtime evidence.
- Moved `tests/e2e/specs/admin-bar-timer.spec.ts` / `TIMR` from `E2E Tests 1/4` to `E2E Tests 2/4`.
- Preserved the same four required E2E groups and final `E2E Tests` gate.
- Documented validation and keep decision in `../docs/e2e-runtime-review.md`.

**Product release state:** Latest tagged plugin release remains `4.2.2`; v4.3.1 was a GSD/CI milestone only and did not create a product release tag or version bump.

**Next planning step:** Run `$gsd-new-milestone` when the next work cycle is selected.

## Requirements

### Validated

<!-- Shipped and confirmed valuable. -->

- Action-gated reauthentication across 7 surfaces (admin UI, REST, AJAX, CLI, Cron, XML-RPC, WPGraphQL) — v1.0+, WPGraphQL v2.5.0
- Built-in gated rules across single-site and multisite contexts — see `../docs/current-metrics.md` for current counts
- Cryptographic session tokens (cookie + SHA-256 user meta) — v1.0+
- Two Factor plugin integration — v1.0+
- Request stash and replay for POST interception — v1.0+
- Per-surface policy controls (Disabled/Limited/Unrestricted) — v2.0+
- Per-application-password policy overrides — v2.3+
- Login grants sudo session — v2.6.0
- Grace period (120s two-tier expiry) — v2.6.0
- WPGraphQL surface gating — v2.5.0
- Non-blocking rate limiting (per-user + per-IP) — v2.10.2, v2.13.0
- Request stash redaction and per-user cap — v2.10.2
- Rule-schema validation and MU loader resilience — v2.11.0
- WPGraphQL persisted-query classification hook — v2.11.0
- WSAL sensor bridge and Stream audit bridge — v2.11.0, v2.12.0
- WP-CLI subcommands (status, revoke) — v2.12.0
- Public API (wp_sudo_check/wp_sudo_require) — v2.12.0
- Audit hooks for external logging — see `../docs/current-metrics.md` for current count
- Lockdown policy presets for remote/non-interactive surfaces — v3.0.0
- Editor unfiltered_html restriction + tamper detection — v2.0+
- Comprehensive automated test coverage (current counts in `../docs/current-metrics.md`)
- Localization and translation packaging readiness — v4.3.0
- Release-only environment assurance and WordPress.org checklist readiness — v4.3.0
- E2E explicit-group runtime review and targeted tuning decision — v4.3.0
- 2FA bridge planning for upstream Two Factor lifecycle operations and Patchstack compatibility — v4.3.0
- E2E shard rebalance based on current GitHub Actions runtime evidence — v4.3.1


### Active

<!-- Current scope. -->

- [ ] Define the next milestone with `$gsd-new-milestone`.
- [ ] Acquire a paid Patchstack-enabled fixture before making runtime Patchstack compatibility claims.

### Out of Scope

<!-- Explicit boundaries. Includes reasoning to prevent re-adding. -->

- Client-side modal challenge — design-heavy, separate milestone
- Gutenberg block editor integration — depends on Playwright being in place first
- Network policy hierarchy — feature work, not testing infrastructure
- Per-session sudo isolation — architectural change, not testing
- REST API sudo grant endpoint — feature work

## Context

WP Sudo has comprehensive PHPUnit coverage (see `../docs/current-metrics.md` for current counts) plus real-browser coverage for the highest-risk flows PHPUnit cannot exercise fully. The original browser gap centered on five scenarios:

1. **Cookie attributes** — `setcookie()` output (httponly, SameSite, Secure) not capturable
2. **Admin bar countdown JS** — requires real DOM + `setInterval`
3. **MU-plugin install button AJAX** — button click -> AJAX -> file copy -> status update
4. **Block editor snackbar** (future) — requires `@wordpress/notices` API in browser
5. **Challenge page keyboard navigation** — real focus management needs browser DOM

Those five scenarios drove the Phase 6-8 Playwright work. The settings page, challenge flow, admin bar, and one Local-only multisite network-admin regression are now covered end to end in a real browser. WordPress 7.0 GA shipped May 20, 2026; keeping browser and visual baselines current remains useful for drift detection.

WordPress dev environment and CI matrix evolve over time; use `../docs/current-metrics.md` and workflow files for current lanes. Playwright hosted CI remains single-site via `wp-env`; Local `multisite-subdomains.local` now provides the dedicated multisite network-admin browser regression target.

Current multisite browser coverage is intentionally narrow: `MULTI-01` proves network-admin session reauthentication returns to the correct network URL on a real Local multisite install. The main remaining browser gap is not return URL handling, but network-admin stash/challenge/replay for gated actions that only exist on multisite.

Recommended next multisite browser sequence:

1. `MULTI-02` — `network/themes.php` network enable/disable GET stash replay
2. `MULTI-03` — `sites.php` confirm/action2 flow for archive/deactivate/spam/delete
3. `MULTI-04` — network settings POST replay

## Constraints

- **Compatibility**: Must work with existing CI matrix (GitHub Actions, Ubuntu)
- **WordPress test env**: Needs a running WordPress instance with WP Sudo activated (wp-env or similar)
- **No build step pollution**: Playwright deps must not affect the plugin's zero-production-dependency stance
- **CI time budget**: E2E suite should add no more than ~2 minutes to CI pipeline
- **Local multisite drift**: Symlinked Local/Studio plugin installs can execute the plugin from the repo target path, not the public `wp-content/plugins/<slug>` path. Bootstrap URL logic must recover the public plugin basename from active plugin state, and browser regressions for that behavior remain local-only.
- **Multisite browser scope**: Hosted Playwright CI still cannot prove network-admin-only stash/replay behavior. Local multisite regressions should target multisite-specific routing seams in priority order instead of adding broad duplicate coverage.

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Playwright over Cypress | Playwright has better multi-browser support, faster execution, and native WordPress ecosystem adoption (Gutenberg uses it) | Adopted — @playwright/test 1.58.2, Chromium only |
| wp-env for test environment | Standard WordPress dev tool, used by Gutenberg, handles DB setup | Adopted — @wordpress/env 11.1.0, port 8889 |
| Visual regression via screenshot comparison | Catches WP 7.0 admin refresh breakage without manual testing | Adopted — 4 baselines captured (challenge card, settings form, admin bar active/expiring) |
| Local multisite browser verification stays outside hosted CI | GitHub-hosted `wp-env` is single-site; the multisite network-admin failure only surfaced on a symlinked Local install | Adopted — keep hosted CI single-site, add Local multisite regression + helper script + bootstrap hardening |
| Actions runtime evidence drives E2E shard balancing | Local Playwright timings and transient wp-env behavior are less reliable than GitHub Actions job durations for CI critical-path decisions | Adopted — moved TIMR/admin-bar-timer from group 1 to group 2 in v4.3.1 |

---
*Last updated: 2026-06-30 — v4.3.1 E2E Shard Rebalance milestone archived; product release metadata remains 4.2.2.*
