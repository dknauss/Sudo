<p align="center">
  <img src="https://raw.githubusercontent.com/dknauss/Sudo/main/.wordpress-org/banner-1544x500.png" alt="Sudo" width="100%">
</p>

# Sudo

Require password confirmation before high-risk changes go through on your WordPress site — even from an already-authenticated admin session. Sudo also lets site owners define the shape of their administrative attack surface across admin UI, AJAX, REST, WP-CLI, Cron, XML-RPC, Application Passwords, and WPGraphQL. Built-in activity visibility, audit hooks, and governance controls help administrators see who is attempting sensitive actions and decide which users can manage Sudo policy.

[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://spdx.org/licenses/GPL-2.0-or-later.html) [![Security Policy](https://img.shields.io/badge/security-policy-4c1)](SECURITY.md) [![Docs](https://img.shields.io/badge/docs-available-0a7ea4.svg)](docs/) [![AI Authorship](https://img.shields.io/badge/AI%20authorship-disclosed-8a63d2.svg)](docs/ai-authorship.md)
[![WordPress: 6.4+](https://img.shields.io/badge/WordPress-6.4%2B-0073aa.svg)](https://wordpress.org/)
[![PHP: 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4.svg)](https://www.php.net/)
[![PHPUnit](https://github.com/dknauss/Sudo/actions/workflows/phpunit.yml/badge.svg)](https://github.com/dknauss/Sudo/actions/workflows/phpunit.yml)
[![Psalm](https://github.com/dknauss/Sudo/actions/workflows/psalm.yml/badge.svg)](https://github.com/dknauss/Sudo/actions/workflows/psalm.yml)
[![Playwright Tests](https://github.com/dknauss/Sudo/actions/workflows/e2e.yml/badge.svg)](https://github.com/dknauss/Sudo/actions/workflows/e2e.yml)
[![CodeQL](https://github.com/dknauss/Sudo/actions/workflows/codeql.yml/badge.svg)](https://github.com/dknauss/Sudo/actions/workflows/codeql.yml)
[![Codecov](https://codecov.io/gh/dknauss/Sudo/graph/badge.svg?branch=main)](https://codecov.io/gh/dknauss/Sudo)
[![Type Coverage](https://shepherd.dev/github/dknauss/Sudo/coverage.svg)](https://shepherd.dev/github/dknauss/Sudo)
[![Try latest release in Playground](https://img.shields.io/badge/Try%20release-Playground-3858e9?logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fdknauss%2FSudo%2Fmain%2Fblueprint.json)
[![Try main in Playground](https://img.shields.io/badge/Try%20main-Playground-23282d?logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fdknauss%2FSudo%2Fmain%2Fblueprint-main.json)
[![Try in-editor reauth in Playground](https://img.shields.io/badge/Try%20in--editor%20reauth-Playground-8a63d2?logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fdknauss%2FSudo%2Ffeat%2Fgutenberg-reauth-increment-3%2Fblueprint-editor-reauth.json)

Playground demo credentials are `admin` / `password`. When Sudo asks for reauthentication, enter the same password: `password`.

## In-editor reauthentication (Gutenberg — in development)

When a gated action trips Sudo *inside the block editor* — for example, installing or activating a block from the inserter's Block Directory — reauthentication happens in place: a password modal opens over the editor, grants the sudo session, and transparently resumes the original request. No full-page redirect, and the editor state is preserved.

[**Try the in-editor reauth demo in Playground →**](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fdknauss%2FSudo%2Ffeat%2Fgutenberg-reauth-increment-3%2Fblueprint-editor-reauth.json) In the editor, open the inserter (**+**), search a Block-Directory block (e.g. *Icon Block*), and install it — the modal appears; enter `password` to continue.

<p align="center">
  <img src="https://raw.githubusercontent.com/dknauss/Sudo/feat/gutenberg-reauth-increment-3/assets/editor-reauth-modal.png" alt="WP Sudo &quot;Confirm your identity&quot; reauthentication modal open over the WordPress block editor." width="80%">
</p>

> This is unreleased work on the `feat/gutenberg-reauth-increment-3` branch; the demo installs the plugin from that branch. Password-only for now — accounts with two-factor fall back to the standard challenge page (2FA in the modal is planned).

## Screenshots

<table>
<tr>
<td width="50%"><img src="https://raw.githubusercontent.com/dknauss/Sudo/main/.wordpress-org/screenshot-1.png" alt="Sudo challenge page asking the current user to confirm their identity with a password."></td>
<td width="50%"><img src="https://raw.githubusercontent.com/dknauss/Sudo/main/.wordpress-org/screenshot-2.png" alt="Gated plugin activation — the Plugins page Activate link replaced with a reauthentication prompt."></td>
</tr>
<tr>
<td><strong>Challenge page</strong></td>
<td><strong>Gated plugin activation</strong></td>
</tr>
<tr>
<td><img src="https://raw.githubusercontent.com/dknauss/Sudo/main/.wordpress-org/screenshot-3.png" alt="Settings tab with policy presets, session duration, and entry-point policies."></td>
<td><img src="https://raw.githubusercontent.com/dknauss/Sudo/main/.wordpress-org/screenshot-4.png" alt="Gated Actions tab showing protected operations and the surfaces where each rule applies."></td>
</tr>
<tr>
<td><strong>Settings tab</strong></td>
<td><strong>Gated Actions tab</strong></td>
</tr>
<tr>
<td><img src="https://raw.githubusercontent.com/dknauss/Sudo/main/.wordpress-org/screenshot-5.png" alt="Rule Tester tab evaluating a representative admin request without executing it."></td>
<td><img src="https://raw.githubusercontent.com/dknauss/Sudo/main/.wordpress-org/screenshot-6.png" alt="Access tab for managing dedicated Sudo governance capabilities."></td>
</tr>
<tr>
<td><strong>Rule Tester tab</strong></td>
<td><strong>Access tab</strong></td>
</tr>
<tr>
<td><img src="https://raw.githubusercontent.com/dknauss/Sudo/main/.wordpress-org/screenshot-7.png" alt="Dashboard widget with active sudo sessions, policy summary, and recent events including session revocations."></td>
<td width="50%"><img src="https://raw.githubusercontent.com/dknauss/Sudo/main/.wordpress-org/screenshot-8.png" alt="Admin bar showing a live countdown timer while a sudo session is active."></td>
</tr>
<tr>
<td><strong>Dashboard widget</strong></td>
<td><strong>Admin bar timer</strong></td>
</tr>
<tr>
<td><img src="https://raw.githubusercontent.com/dknauss/Sudo/main/.wordpress-org/screenshot-9.png" alt="Users list showing the Sudo Active view with the Revoke sudo sessions bulk action selected."></td>
<td></td>
</tr>
<tr>
<td><strong>Users list revocation</strong></td>
<td></td>
</tr>
</table>

## Features

- **Confirmation before destructive actions** — plugin installs/deletions, user management, settings changes, core updates, and more all require a fresh password before proceeding
- **Two-factor support** — integrates with the [Two Factor plugin](https://wordpress.org/plugins/two-factor/) so the challenge includes your second factor when active
- **Short sudo window** — one confirmation covers 1–15 minutes of related work (your choice) so admins can work without interruption following one reauthentication challenge before being challenged again
- **Per-surface policies** — configure WP-CLI, Cron, XML-RPC, REST App Passwords, and WPGraphQL independently as Disabled, Limited, or Unrestricted
- **Privilege-escalation guard (opt-in)** — optionally refuse to grant a *new* administrator or super-admin unless the actor has an active sudo session, blocking the most common privilege-escalation shape even through another plugin's broken endpoint (off by default; see the FAQ)
- **Governance controls** — manage which users and roles can administer Sudo settings via a dedicated Access tab
- **Activity visibility** — audit hooks fire on every gated event; works with WP Activity Log, Stream, and similar plugins
- **Multisite support** — network-aware; super admins governed separately from per-site admins

## Quick start

1. Install and activate Sudo.
2. Go to **Settings → Sudo**.
3. Choose a session duration.
4. Review the default policies for non-interactive surfaces.
5. Optionally install the bundled mu-plugin loader from the settings page for earlier hook registration.
6. Test a covered action such as plugin activation or a protected settings change.

### Recommended companion plugins

- [Two Factor](https://wordpress.org/plugins/two-factor/) — strongly recommended for password + second-factor challenge flows.
- [WP Activity Log](https://wordpress.org/plugins/wp-security-audit-log/) or [Stream](https://wordpress.org/plugins/stream/) — recommended if you want audit visibility from Sudo's action hooks.

## What gets protected

Sudo gates built-in operations across categories including:
- plugin and theme installation, activation, and deletion
- user creation, deletion, and role changes
- file editor access
- critical option changes
- WordPress core updates
- export flows
- Sudo settings themselves
- selected Multisite network actions
- connector credential writes via the REST settings endpoint

For the full rule list and surface counts, see [docs/current-metrics.md](docs/current-metrics.md).

## Single sign-on (SSO)

Sudo's challenge is a WordPress **password** check, so it assumes an account can authenticate with a WordPress-native password. It still works alongside SSO/SAML/OIDC **when your identity provider's plugin fires the standard `wp_login` action** — verify this against your provider's documentation or source, since a provider that does not fire it leaves passwordless accounts unable to pass the WordPress-password challenge. When it does fire, each fresh provider login grants the sudo window automatically — so even accounts with **no** WordPress password reach gated actions, and logging in again through the provider *is* their reauthentication. The tradeoff to know: for those passwordless accounts the window opens at login rather than at the moment of the action, a slightly weaker guarantee than the password challenge gives. Sudo is **not** something to avoid under SSO — it still gates actions, bounds the window, and fires audit hooks — but if you require a genuine at-the-moment step-up for administrators, note that giving them WordPress passwords is not enough on its own: the login auto-grant still opens a window on every login, so also suppress it for those admins via the `wp_sudo_grant_session_on_login` filter (so they meet the challenge at the first gated action), or track the roadmapped identity-provider challenge framework. See the [SSO section of the FAQ](docs/FAQ.md) for setup details and the `wp_sudo_grant_session_on_login` opt-out.

## Why it helps

WordPress has roles, capabilities, and authentication, but no native way to say "a logged-in session alone isn't enough for this action." Sudo adds that missing checkpoint for the parts of WordPress where a mistake, hijacked session, stale browser, or over-broad automation token can do the most damage.

That helps site owners, agencies, network operators, and teams with multiple administrators reduce the blast radius of privileged accounts. It is especially useful on sites where people, scripts, application passwords, WP-CLI jobs, Cron tasks, XML-RPC clients, WPGraphQL clients, or AI/agentic tooling can all reach administrative surfaces.

Sudo also makes privilege use more visible. The dashboard widget shows active sudo sessions, policy posture, and recent privileged activity; audit hooks and bundled bridges let logging plugins such as WP Activity Log and Stream record sudo sessions, gated requests, policy changes, and governance events.

The result is not just another password prompt. It is a way to define the shape and size of your site's administrative attack surface: close a surface entirely, limit it to non-destructive operations, require sudo for covered actions, or leave it unrestricted when that is the deliberate operational choice.

Active sudo is **per browser session**, not site-wide. Sudo works alongside your existing roles and capabilities — it does not replace them.

## How it works

More technically, Sudo is a Multisite-compatible, zero-trust-aligned security-hardening plugin for WordPress. It adds **action-gated reauthentication**, enables **attack surface definition** (open, closed, or sudo-gated), gives **visibility to privileged action requests**, and confines Sudo administration to explicitly designated users.

**Browser (wp-admin):** gated actions redirect to a challenge screen. After successful reauthentication, the original request replays automatically.

**AJAX and REST:** blocked requests receive a `sudo_required` error until reauthentication occurs.

**Non-interactive surfaces** (WP-CLI, Cron, XML-RPC, REST App Passwords, WPGraphQL): each can be set independently to Disabled, Limited, or Unrestricted under Settings → Sudo.

Before a covered high-risk action continues, the current user must reauthenticate by entering their password, followed by any active and compatible two-factor challenge. Successful reauthentication starts a short, configurable window of 1–15 minutes for additional covered actions in that browser session. WordPress core and the target feature still own their normal capability and authorization checks; Sudo adds the fresh-identity checkpoint before the covered action is allowed to continue.

Sudo gates specific operations on specific surfaces. It is not a firewall, exploit detector, malware scanner, or fix for authorization vulnerabilities inside third-party plugin code.

## Sudo administration and governance

"With great power comes great responsibility," so users with the capability to change Sudo settings, view sudo session activity, kill sudo sessions, or export sudo activity logs are limited by default:

- On **single sites**, the installing administrator receives all four caps. Other admins receive none until explicitly granted.
- On **multisite networks**, super administrators receive all four caps at network scope by default. Per-site admins receive none until explicitly delegated.

(Export privileges are separated from view privileges because a portable export artifact is a distinct governance concern — SOC2/GDPR audits treat "can read" and "can take a copy offsite" differently.)

Sudo integrates with the **Site Health** tool in WordPress core for rich security diagnostics and advisory notifications.

### Break-glass recovery scenario

In a lost, last administrator scenario where no one has access to Sudo's settings, the break-glass mechanism is to set `WP_SUDO_RECOVERY_MODE` in `wp-config.php`. This is Sudo's break-glass governance recovery path, not WordPress core's `WP_Recovery_Mode`. It requires filesystem access to activate, so it is not a remote-escalation vector. The grant is **role-gated**: while the constant is defined, the current user receives the master `manage_wp_sudo` capability only if they also hold `manage_options` (single-site) / `manage_network_options` (multisite), so a locked-out administrator recovers while non-admins gain nothing. A permanent non-dismissible notice appears on the Sudo settings screen while it is active, and the `wp_sudo_recovery_mode_active` audit hook fires so the usage is logged. The role gate does not eliminate the residual risk — every administrator regains full Sudo governance while the constant is set — so remove it the moment normal access is restored.

## For developers and integrators

Sudo exposes a small, stable API. Custom gated rules are plain associative arrays registered via the `wp_sudo_gated_actions` filter, with per-surface matchers for admin, AJAX, REST, and CLI. The `wp_sudo_can()` helper centralizes all governance checks — super-admin short-circuit and recovery-mode bypass, with always-strict capability checks (the `compatibility` mode was removed in 4.0.0) — so integrations don't touch capability internals directly. Audit hooks fire on every session event, capability grant or revoke, tamper detection, and policy change; bridge classes for WP Activity Log and Stream are bundled. The `wp_sudo_grant_session_on_login` filter lets SSO and kiosk integrations suppress the automatic browser-login session grant. All of this is covered by a dual-layer test suite (unit tests + a full integration matrix) and PHPStan level 6.

## Requirements

- **WordPress:** 6.4+
- **PHP:** 8.2+
- **Multisite:** supported

For current release posture, supported lanes, and forward `main` notes, see [docs/release-status.md](docs/release-status.md).

## Footprint and performance

Sudo is an event-gate, not a query-heavy plugin — it does no per-page database work.

- **No production dependencies and no build step** — ~17k lines of PHP plus vanilla JS assets (no bundler or transpiler).
- **Front-end page loads:** zero added database queries for visitors, and at most one cached user-meta read for a logged-in user (the admin-bar session check). Database activity is confined to the specific gated action being confirmed, not to normal browsing.
- **Storage:** three small options, per-user session and rate-limit meta plus transients that self-expire, and one activity-log table that self-prunes at a 14-day default retention. Everything is removed on uninstall.

These are verified against the plugin's always-on hooks; the exact counts, retention, and re-derivation commands live in [docs/current-metrics.md](docs/current-metrics.md#footprint--performance).

## Documentation

### Start here
- [docs/security-model.md](docs/security-model.md) — threat model, boundaries, and environmental assumptions
- [docs/FAQ.md](docs/FAQ.md) — practical questions and operational caveats
- [docs/release-status.md](docs/release-status.md) — current stable release state and forward-lane posture

### For developers and integrators
- [docs/developer-reference.md](docs/developer-reference.md) — hooks, filters, custom rule structure, and integration API details
- [docs/two-factor-integration.md](docs/two-factor-integration.md) — Two Factor integration behavior
- [docs/connectors-api-reference.md](docs/connectors-api-reference.md) — connector credential gating notes
- [docs/ai-agentic-guidance.md](docs/ai-agentic-guidance.md) — AI and agent tooling guidance

### Verification and project status
- [tests/MANUAL-TESTING.md](tests/MANUAL-TESTING.md) — manual verification procedures
- [docs/current-metrics.md](docs/current-metrics.md) — canonical current counts and architectural facts
- [docs/ROADMAP.md](docs/ROADMAP.md) — roadmap and backlog
- [CHANGELOG.md](CHANGELOG.md) — release history

### Background and research
- [docs/sudo-architecture-comparison-matrix.md](docs/sudo-architecture-comparison-matrix.md) — comparison with other sudo/reauth approaches
- [docs/abilities-api-assessment.md](docs/abilities-api-assessment.md) — WordPress Abilities API assessment
- [docs/core-action-gate-proposal.md](docs/core-action-gate-proposal.md) — longer-form core proposal and design thinking
- [docs/llm-lies-log.md](docs/llm-lies-log.md) — verification discipline and past documentation failures
- [docs/archive/project-introduction.md](docs/archive/project-introduction.md) — the longer conceptual introduction, graphic, poem, and gate metaphor preserved from the earlier README

## Development

Quick local checks:

```bash
composer install
composer test:unit
composer lint
composer analyse
```

For full setup, integration tests, E2E workflows, and contributor expectations, see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

GPL-2.0-or-later.
