# WP Sudo

> **GSD project context.** Keep this file aligned enough for planning/routing. Canonical volatile facts still live in `docs/current-metrics.md`, `docs/release-status.md`, and `docs/ROADMAP.md`.


## What This Is

WP Sudo is a WordPress plugin that provides action-gated reauthentication. Dangerous admin operations (plugin activation, user deletion, critical settings changes, etc.) require password confirmation before they proceed — regardless of user role. It covers 7 request surfaces (admin UI, REST API, AJAX, WP-CLI, Cron, XML-RPC, WPGraphQL) with per-surface policy controls.

## Core Value

Every destructive WordPress admin action requires proof that the person at the keyboard is still the authenticated user — not a hijacked session, XSS payload, or unattended browser.


## Current Milestone: v4.5 — Session Governance & Admin UX

**Goal:** Separate sudo-session revocation from capability administration with an active-session-keyed revocation surface, and correct the governance-coverage panel's multisite behavior.

**Target features:**
- Add a "Revoke sudo session" row action on the Users list, shown only for users with an active session (reuses the existing `_wp_sudo_expires > time()` "Sudo Active" enumeration).
- Surface the network-wide "revoke all active sessions" action in the UI behind a confirmation step (today CLI-only via `wp sudo revoke --all`).
- Decouple session revocation from capability revocation in the Access-tab governance table so the two controls are unambiguous.
- Fix the "Sudo governance coverage" panel on multisite: name the correct capability (`manage_network_options`) and stop flagging super admins who have effective access via the `wp_sudo_can()` short-circuit.
- Preserve the existing revocation guardrails throughout: `revoke_wp_sudo_sessions` capability, per-revoker rate limit, and the `wp_sudo_session_revoked` audit hook.

## Current State

Milestone v4.5 — Session Governance & Admin UX is starting (defining requirements). Milestone v4.4.0 — Two Factor Lifecycle Bridge is complete (Phases 21–23; milestone audit passed 2026-06-30).

**Most recent GSD milestone outcome (v4.4.0):**
- Refreshed upstream WordPress/two-factor source evidence (Phase 21).
- Implemented a narrow classic profile-provider lifecycle guard plus preserved REST lifecycle bridge coverage (Phase 22).
- Closed bridge documentation, canonical metrics, and release posture (Phase 23).

**Origin of v4.5 scope:** Captured during a session-revocation UI review on 2026-06-30 — see `.planning/todos/pending/2026-06-30-session-revocation-surfaces.md`.

**Scope decisions (locked):** revocation surface = Users-list row action (not an Access-tab panel); network-wide revoke-all = UI button with confirmation; multisite coverage-panel fixes included in this milestone; research skipped (internal refactor of existing capability/UI code).

**Product release state:** Latest tagged plugin release remains `4.2.2`; v4.5 is a GSD milestone and does not imply a product release tag or version bump until release metadata is intentionally changed.

**Next planning step:** Define v4.5 requirements and roadmap, then start Phase 24.

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
- Narrow classic profile-provider Two Factor lifecycle guard + preserved REST lifecycle bridge — v4.4.0


### Active

<!-- Current scope (v4.5). -->

- [ ] Revoke another user's active sudo session from a Users-list row action (shown only for users with a live session).
- [ ] Revoke all active sudo sessions network-wide from the UI, behind a confirmation step.
- [ ] Decouple session revocation from capability revocation in the Access-tab governance table.
- [ ] Correct the "Sudo governance coverage" panel on multisite (capability naming + super-admin false positives).

### Out of Scope

<!-- Explicit boundaries. Includes reasoning to prevent re-adding. -->

- Access-tab "Active Sessions" panel — chose the Users-list row action instead for v4.5 (where admins already manage users)
- Session metadata in the revoke UI (expiry countdown, bound IP) — deferred; not required to revoke
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
- **Two Factor source discipline**: Third-party technical claims about upstream WordPress/two-factor internals must be refreshed from live source before implementation and cited in code/docs.
- **Patchstack fixture boundary**: Patchstack Security 2FA compatibility remains manual/fixture-blocked until a paid Patchstack-enabled test environment exists; do not ship Patchstack bridge code or runtime support claims from source inspection alone.

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Playwright over Cypress | Playwright has better multi-browser support, faster execution, and native WordPress ecosystem adoption (Gutenberg uses it) | Adopted — @playwright/test 1.58.2, Chromium only |
| wp-env for test environment | Standard WordPress dev tool, used by Gutenberg, handles DB setup | Adopted — @wordpress/env 11.1.0, port 8889 |
| Visual regression via screenshot comparison | Catches WP 7.0 admin refresh breakage without manual testing | Adopted — 4 baselines captured (challenge card, settings form, admin bar active/expiring) |
| Local multisite browser verification stays outside hosted CI | GitHub-hosted `wp-env` is single-site; the multisite network-admin failure only surfaced on a symlinked Local install | Adopted — keep hosted CI single-site, add Local multisite regression + helper script + bootstrap hardening |
| Actions runtime evidence drives E2E shard balancing | Local Playwright timings and transient wp-env behavior are less reliable than GitHub Actions job durations for CI critical-path decisions | Adopted — moved TIMR/admin-bar-timer from group 1 to group 2 in v4.3.1 |
| Upstream Two Factor profile guard is the v4.4.0 focus | Phase 19 proved the REST lifecycle bridge remains current while classic profile provider changes need a narrow idempotent guard | ✓ Good — shipped in v4.4.0 (Phase 22) |
| Active-session revocation lives on the Users list, not an Access-tab panel | Reuses the existing `_wp_sudo_expires > time()` "Sudo Active" enumeration and puts the action where admins already manage users; the Access-tab table stays for capability administration | Pending — v4.5 |
| Coverage panel must measure effective `wp_sudo_can()` access, not raw `allcaps` | Keying on raw `allcaps['manage_wp_sudo']` produces multisite super-admin false positives because it bypasses the `is_super_admin()` short-circuit | Pending — v4.5 |

---
*Last updated: 2026-06-30 — v4.5 Session Governance & Admin UX milestone started; product release metadata remains 4.2.2.*
