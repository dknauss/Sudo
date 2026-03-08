# WP Sudo

## What This Is

WP Sudo is a WordPress plugin that provides action-gated reauthentication. Dangerous admin operations (plugin activation, user deletion, critical settings changes, etc.) require password confirmation before they proceed — regardless of user role. It covers 6 request surfaces (admin UI, REST API, AJAX, WP-CLI, Cron, XML-RPC) with per-surface policy controls.

## Core Value

Every destructive WordPress admin action requires proof that the person at the keyboard is still the authenticated user — not a hijacked session, XSS payload, or unattended browser.

## Requirements

### Validated

<!-- Shipped and confirmed valuable. -->

- Action-gated reauthentication across 6 surfaces — v1.0+
- 32 built-in gated rules (23 single-site + 9 multisite) — v2.0+
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
- 9 audit hooks for external logging — v2.0+
- Editor unfiltered_html restriction + tamper detection — v2.0+
- Comprehensive automated test coverage (current counts in `../docs/current-metrics.md`) — v2.13.0+

### Active

<!-- Current scope. Building toward these. -->

- [ ] Playwright E2E test infrastructure covering PHPUnit-uncoverable scenarios
- [ ] WP 7.0 visual regression baselines
- [ ] Admin UI smoke tests in a real browser
- [ ] E2E tests in CI on every push

### Out of Scope

<!-- Explicit boundaries. Includes reasoning to prevent re-adding. -->

- Client-side modal challenge — design-heavy, separate milestone
- Session activity dashboard widget — feature work, not testing infrastructure
- Gutenberg block editor integration — depends on Playwright being in place first
- Network policy hierarchy — feature work, not testing infrastructure
- Per-session sudo isolation — architectural change, not testing
- REST API sudo grant endpoint — feature work

## Context

WP Sudo has comprehensive PHPUnit coverage (see `../docs/current-metrics.md` for current counts) but zero browser-level testing. Five specific scenarios cannot be tested with PHPUnit:

1. **Cookie attributes** — `setcookie()` output (httponly, SameSite, Secure) not capturable
2. **Admin bar countdown JS** — requires real DOM + `setInterval`
3. **MU-plugin install button AJAX** — button click -> AJAX -> file copy -> status update
4. **Block editor snackbar** (future) — requires `@wordpress/notices` API in browser
5. **Challenge page keyboard navigation** — real focus management needs browser DOM

Beyond these 5, the settings page, challenge flow, and admin bar have never been tested end-to-end in a real browser. WP 7.0 GA ships April 9, 2026 with an admin visual refresh — visual regression baselines established now will catch any breakage.

WordPress dev environment: PHP 8.1+, WP 6.7+. CI matrix: unit tests on PHP 8.1-8.4, integration tests on PHP 8.1/8.3, WP 6.7/latest/trunk, single-site + multisite.

## Constraints

- **Compatibility**: Must work with existing CI matrix (GitHub Actions, Ubuntu)
- **WordPress test env**: Needs a running WordPress instance with WP Sudo activated (wp-env or similar)
- **No build step pollution**: Playwright deps must not affect the plugin's zero-production-dependency stance
- **CI time budget**: E2E suite should add no more than ~2 minutes to CI pipeline

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Playwright over Cypress | Playwright has better multi-browser support, faster execution, and native WordPress ecosystem adoption (Gutenberg uses it) | -- Pending |
| wp-env for test environment | Standard WordPress dev tool, used by Gutenberg, handles DB setup | -- Pending |
| Visual regression via screenshot comparison | Catches WP 7.0 admin refresh breakage without manual testing | -- Pending |

---
*Last updated: 2026-03-08 after milestone v2.14 initialization*
