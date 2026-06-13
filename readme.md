# WP Sudo

WP Sudo is a Multisite-compatible, zero-trust aligned, security-hardening plugin for WordPress. It adds **action-gated reauthentication**, enables **attack surface definition** (open, closed, or sudo-gated), gives **visibility to privileged action requests**, and confines Sudo administration to explicitly designated users.

[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://spdx.org/licenses/GPL-2.0-or-later.html)
[![WordPress: 6.2+](https://img.shields.io/badge/WordPress-6.2%2B-0073aa.svg)](https://wordpress.org/)
[![PHP: 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)](https://www.php.net/)
[![PHPUnit](https://github.com/dknauss/Sudo/actions/workflows/phpunit.yml/badge.svg)](https://github.com/dknauss/Sudo/actions/workflows/phpunit.yml)
[![Psalm](https://github.com/dknauss/Sudo/actions/workflows/psalm.yml/badge.svg)](https://github.com/dknauss/Sudo/actions/workflows/psalm.yml)
[![Playwright Tests](https://github.com/dknauss/Sudo/actions/workflows/e2e.yml/badge.svg)](https://github.com/dknauss/Sudo/actions/workflows/e2e.yml)
[![CodeQL](https://github.com/dknauss/Sudo/actions/workflows/codeql.yml/badge.svg)](https://github.com/dknauss/Sudo/actions/workflows/codeql.yml)
[![Codecov](https://codecov.io/gh/dknauss/Sudo/graph/badge.svg?branch=main)](https://codecov.io/gh/dknauss/Sudo)
[![Type Coverage](https://shepherd.dev/github/dknauss/Sudo/coverage.svg)](https://shepherd.dev/github/dknauss/Sudo)
[![Try latest release in Playground](https://img.shields.io/badge/Try%20release-Playground-3858e9?logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fdknauss%2FSudo%2Fv3.4.0%2Fblueprint.json)
[![Try main in Playground](https://img.shields.io/badge/Try%20main-Playground-23282d?logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fdknauss%2FSudo%2Fmain%2Fblueprint-main.json)

Playground demo credentials are `admin` / `password`. When WP Sudo asks for reauthentication, enter the same password: `password`.

## Overview

Before performing high-risk (Administrator and Super Administrator) actions in any interface or API surface, a privileged user session must re-authenticate by re-entering their credentials, followed by any active and compatible two-factor authentication requirement. Successful reauthentication starts a short, configurable window (up to 15 minutes) for privileged, gated actions without further challenge. Each non-interactive surface — WP-CLI, Cron, XML-RPC, REST App Passwords, WPGraphQL — can be independently set to Disabled, Limited (Gated), or Unrestricted. A built-in Access tab controls which users hold Sudo management privileges, with a drift-detection panel that surfaces capability mismatches introduced by third-party role plugins. All privileged sessions can be monitored in the dashboard or with compatible plugins for logging user activity. 

## Why WP Sudo exists

WordPress has roles, capabilities, and authentication limiting who/what can take privileged actions. However, it has no native way to say, "This action is too consequential to assume a valid user session alone is sufficient to allow it." 

For high-value single sites and multisite networks with multiple administrators, this default posture is far too open. A phished user account, a hijacked admin session, and a rogue agent/non-human identity all appear legitimate and can freely take destructive actions. WP Sudo adds a missing layer to reduce these risks and limit the potential damage by intercepting the most privileged actions with a standard password challenge, followed by a second authentication requirement if 2FA is enabled. 

Sudo is designed to reduce risk when an attacker has:
- a stolen browser session cookie,
- access to an unattended authenticated browser,
- or a delegated request path that reaches a high-impact operation.

On those covered paths, a valid session without an active sudo window is not enough.

## What WP Sudo gates and protects

WP Sudo currently gates built-in operations across categories such as:
- plugin and theme management,
- user creation, deletion, and role changes,
- file editor access,
- critical option changes,
- WordPress core updates,
- export flows,
- WP Sudo settings themselves,
- selected Multisite network actions,
- and connector credential writes saved through the REST settings endpoint.

For the canonical current rule totals and surface counts, see [docs/current-metrics.md](docs/current-metrics.md).

## What Sudo _doesn't_ defend against

WP Sudo is not a general fix for broken authorization in plugin code. WP Sudo gates specific known operations on specific known surfaces. A plugin vulnerability that performs a privileged state change through its own code path — without routing through a surface WP Sudo intercepts — is outside this layer. WP Sudo is the guard at the door of the operations it knows about; it is not a monitor of arbitrary plugin execution.

## Sudo administration and governance

"With great power comes great responsibility," so users with the capability to change Sudo settings, view sudo session activity, kill sudo sessions, or export sudo activity logs are limited by default:  

- On **single sites**, the installing administrator receives all four caps. Other admins receive none until explicitly granted.
- On **multisite networks**, super administrators receive all four caps at network scope by default. Per-site admins receive none until explicitly delegated.

(Export privileges are separated from view privileges because a portable export artifact is a distinct governance concern — SOC2/GDPR audits treat "can read" and "can take a copy offsite" differently.)

WP Sudo integrates with the **Site Health** tool in WordPress core for rich security diagnostics and advisory notifications. 

### Break glass recovery scenario

In a lost, last administrator scenario where no one has access to Sudo's settings, the break-glass mechanism is to set `WP_SUDO_RECOVERY_MODE` in `wp-config.php`. It requires filesystem access to activate, so it is not a remote-escalation vector. The grant is **role-gated**: while the constant is defined, the current user receives the master `manage_wp_sudo` capability only if they also hold `manage_options` (single-site) / `manage_network_options` (multisite), so a locked-out administrator recovers while non-admins gain nothing. A permanent non-dismissible notice appears on the Sudo settings screen while it is active, and the `wp_sudo_recovery_mode_active` audit hook fires so the usage is logged. The role gate does not eliminate the residual risk — every administrator regains full Sudo governance while the constant is set — so remove it the moment normal access is restored.

## For developers and integrators

WP Sudo exposes a small, stable API. Custom gated rules are plain associative arrays registered via the `wp_sudo_gated_actions` filter, with per-surface matchers for admin, AJAX, REST, and CLI. The `wp_sudo_can()` helper centralizes all governance checks — super-admin short-circuit, recovery-mode bypass, and strict/compatibility mode — so integrations don't touch capability internals directly. Audit hooks fire on every session event, capability grant or revoke, tamper detection, and policy change; bridge classes for WP Activity Log and Stream are bundled. The `wp_sudo_grant_session_on_login` filter lets SSO and kiosk integrations suppress the automatic browser-login session grant. All of this is covered by a dual-layer test suite (unit tests + a full integration matrix) and PHPStan level 6.

## How it works

### Browser requests
For wp-admin flows, WP Sudo redirects the user to a challenge screen. After successful reauthentication, the original request can continue.

### AJAX and REST requests
These receive a `sudo_required` error instead of silently proceeding.

### Non-interactive surfaces
WP Sudo supports configurable policies for:
- WP-CLI
- Cron
- XML-RPC
- REST Application Passwords
- WPGraphQL (when active)

Each surface can be set to **Disabled**, **Limited**, or **Unrestricted**.

## What WP Sudo does **not** do

WP Sudo is deliberately narrow. It is **not**:
- a replacement for WordPress capabilities,
- a firewall or exploit detector,
- a fix for arbitrary broken access control inside third-party plugin code,
- or a sandbox for malicious in-process code.

It is strongest when an attacker has a valid session but **does not** have an active sudo window and must cross one of the plugin's covered action paths.

Active sudo is **per browser session**, not site-wide.

## Requirements

- **WordPress:** 6.2+
- **PHP:** 8.0+
- **Multisite:** supported

For current release posture, supported lanes, and forward `main` notes, see [docs/release-status.md](docs/release-status.md).

## Quick start

1. Install and activate WP Sudo.
2. Go to **Settings → Sudo**.
3. Choose a session duration.
4. Review the default policies for non-interactive surfaces.
5. Optionally install the bundled mu-plugin loader from the settings page for earlier hook registration.
6. Test a covered action, such as plugin activation or a protected settings change.

You can also install [User Switching](https://en-ca.wordpress.org/plugins/user-switching/), switch to another admin user whose password is unknown, and see how your actions are limited by Sudo. 

### Recommended companion plugins

- [Two Factor](https://wordpress.org/plugins/two-factor/) — strongly recommended for password + second-factor challenge flows.
- [WP Activity Log](https://wordpress.org/plugins/wp-security-audit-log/) or [Stream](https://wordpress.org/plugins/stream/) — recommended if you want audit visibility from WP Sudo's action hooks.

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

### Verification and project status docs for humans and LLMs
- [tests/MANUAL-TESTING.md](tests/MANUAL-TESTING.md) — manual verification procedures for all surfaces
- [docs/current-metrics.md](docs/current-metrics.md) — canonical current counts and architectural facts
- [docs/ROADMAP.md](docs/ROADMAP.md) — roadmap and backlog
- [CHANGELOG.md](CHANGELOG.md) — release history

### Background, research, and lessons learned
- [docs/sudo-architecture-comparison-matrix.md](docs/sudo-architecture-comparison-matrix.md) — comparison with other sudo/reauth approaches
- [docs/abilities-api-assessment.md](docs/abilities-api-assessment.md) — WordPress Abilities API assessment
- [docs/core-action-gate-proposal.md](docs/core-action-gate-proposal.md) — longer-form core proposal and design thinking
- [docs/llm-lies-log.md](docs/llm-lies-log.md) — verification discipline for agentic coding and past documentation failures
- [docs/project-introduction.md](docs/project-introduction.md) — the original conceptual introduction to sudo with some poetry, art, and history.

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
