# WP Sudo

WP Sudo adds **action-gated reauthentication** to WordPress so high-risk operations require fresh confirmation before they proceed.

[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress: 6.2+](https://img.shields.io/badge/WordPress-6.2%2B-0073aa.svg)](https://wordpress.org/)
[![PHP: 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)](https://www.php.net/)
[![PHPUnit](https://github.com/dknauss/wp-sudo/actions/workflows/phpunit.yml/badge.svg)](https://github.com/dknauss/wp-sudo/actions/workflows/phpunit.yml)
[![Psalm](https://github.com/dknauss/wp-sudo/actions/workflows/psalm.yml/badge.svg)](https://github.com/dknauss/wp-sudo/actions/workflows/psalm.yml)
[![Playwright Tests](https://github.com/dknauss/wp-sudo/actions/workflows/e2e.yml/badge.svg)](https://github.com/dknauss/wp-sudo/actions/workflows/e2e.yml)
[![CodeQL](https://github.com/dknauss/wp-sudo/actions/workflows/codeql.yml/badge.svg)](https://github.com/dknauss/wp-sudo/actions/workflows/codeql.yml)
[![Codecov](https://codecov.io/gh/dknauss/wp-sudo/graph/badge.svg?branch=main)](https://codecov.io/gh/dknauss/wp-sudo)
[![Type Coverage](https://shepherd.dev/github/dknauss/wp-sudo/coverage.svg)](https://shepherd.dev/github/dknauss/wp-sudo)
[![Try in Playground](https://img.shields.io/badge/Try%20it-Playground-3858e9?logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/dknauss/wp-sudo/main/blueprint.json)

> **3.0.0 milestone:** WP Sudo now combines action-gated reauthentication with policy presets, Connectors credential protection, request diagnostics, and operator activity visibility. See [docs/release-status.md](docs/release-status.md) for current release posture.

## What’s new in 3.0.0

- **Policy control:** one-click **Normal**, **Incident Lockdown**, and **Headless Friendly** presets for non-interactive surfaces
- **Ecosystem hardening:** built-in gating for Connectors API credential writes through `/wp/v2/settings`
- **Operator diagnostics:** a **Request / Rule Tester** for representative admin, AJAX, and REST request shapes
- **Operator visibility:** a **Session Activity Dashboard Widget** backed by a lightweight event store and recorder
- **Recovery hardening:** cleaner challenge, lockout, and 2FA recovery behavior around edge-case retry flows

## Why WP Sudo exists

WordPress has roles, capabilities, and authentication, but it has no native way to say:

> this action is consequential enough that a valid session alone should not be enough.

WP Sudo adds that missing layer on the covered paths it intercepts.

It is designed to reduce risk when an attacker has:
- a stolen browser session cookie,
- access to an unattended authenticated browser,
- or a delegated request path that reaches a high-impact operation.

On those covered paths, a valid session without an active sudo window is not enough.

## What WP Sudo covers

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
6. Test a covered action such as plugin activation or a protected settings change.

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
- [docs/project-introduction.md](docs/project-introduction.md) — the longer conceptual introduction, graphic, poem, and gate metaphor preserved from the earlier README

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
