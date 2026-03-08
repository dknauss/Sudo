# Copilot Instructions

Use this file as a quick execution guide for this repository. If instructions conflict, follow this precedence:

1. `AGENTS.md`
2. `CLAUDE.md`
3. This file

## Repository Snapshot

- Project: WP Sudo (WordPress plugin)
- Runtime requirements: WordPress 6.2+, PHP 8.0+
- Plugin version source of truth: `wp-sudo.php` (`Version` header + `WP_SUDO_VERSION`)
- Architecture center: `includes/class-gate.php`
- Built-in gated actions: 32 rules (single-site + multisite)

## What This Plugin Does

WP Sudo requires reauthentication before dangerous actions execute (plugin/theme changes, user/role operations, key settings changes, etc.). It enforces this across admin UI, AJAX, REST, WP-CLI, Cron, XML-RPC, and WPGraphQL policy surfaces.

## Ground Rules For Changes

- Follow TDD: write/adjust failing tests first, then production code.
- Do not invent external plugin methods/classes/hooks/meta keys.
- Verify external technical references against authoritative sources before writing docs or integration code.
- Keep lifecycle behavior safe and idempotent (activation, deactivation, uninstall, upgrades).
- Do not weaken capability checks, nonce checks, sanitization, or escaping.

## Correct Commands

### Setup

```bash
composer install
```

### Test and Quality Gates

```bash
composer test:unit
composer test:integration
composer analyse:phpstan
composer analyse:psalm
composer lint
```

### Optional Utilities

```bash
composer test:coverage
composer sbom
```

## Important Reality Checks

- There is no production JS build pipeline for current plugin runtime assets.
- Do not introduce or depend on `build/` artifacts unless explicitly requested.
- Do not assume npm is required for normal PHP/plugin development in this repo.
- Frontend assets used by the plugin live in `admin/js/` and `admin/css/`.

## File Map

- Entry point: `wp-sudo.php`
- Core classes: `includes/`
- Optional bridges: `bridges/`
- MU support: `mu-plugin/`
- Unit tests: `tests/Unit/`
- Integration tests: `tests/Integration/`
- Security + developer docs: `docs/`
- Roadmap and planning: `.planning/`

## Before Commit

Run and record outcomes:

1. `composer test:unit`
2. `composer test:integration` (or document blocker)
3. `composer analyse:phpstan`
4. `composer analyse:psalm`
5. `composer lint`

If any command cannot run, report the exact command and blocker.

## Documentation Hygiene

- Treat hardcoded counts as perishable.
- When updating test counts, verify from test output first.
- When updating project size metrics, use the commands in `AGENTS.md`.
- Keep release-history entries historical; do not rewrite prior release facts.
