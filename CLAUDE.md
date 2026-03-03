# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WP Sudo is a WordPress plugin that provides action-gated reauthentication. Dangerous operations (plugin activation, user deletion, critical settings changes, etc.) require password confirmation before they proceed — regardless of user role.

**Requirements:** WordPress 6.2+, PHP 8.0+

## Commands

```bash
composer install              # Install dev dependencies
composer test                 # Alias for composer test:unit
composer test:unit            # Run unit tests only (fast, no database, ~0.3s)
composer test:integration     # Run integration tests (requires MySQL + WP test suite setup)
composer test:coverage        # Run unit tests with PCOV coverage (generates coverage.xml + text summary)
composer lint                 # Run PHPCS (WordPress-Extra + WordPress-Docs + WordPressVIPMinimum)
composer lint:fix             # Auto-fix PHPCS violations
composer analyse              # Run PHPStan level 6 (use --memory-limit=1G if needed)
composer sbom                 # Regenerate CycloneDX SBOM (bom.json)
./vendor/bin/phpunit tests/Unit/SudoSessionTest.php   # Run a single test file
./vendor/bin/phpunit --filter testMethodName           # Run a single test method
```

No build step. No production dependencies — only dev dependencies (PHPUnit 9.6, Brain\Monkey, Mockery, VIP WPCS, PHPStan, CycloneDX). `config.platform.php` is set to `8.1.99` so the lock file resolves packages compatible with PHP 8.1+ regardless of local PHP version.

## Documentation

- `docs/security-model.md` — threat model, boundaries, environmental considerations.
- `docs/developer-reference.md` — hook signatures, filters, custom rule structure.
- `FAQ.md` — all frequently asked questions.
- `CHANGELOG.md` — full version history.
- `docs/ai-agentic-guidance.md` — AI and agentic tool integration guidance.
- `docs/two-factor-integration.md` — 2FA plugin integration guide.
- `docs/two-factor-ecosystem.md` — 2FA plugin ecosystem survey.
- `docs/ui-ux-testing-prompts.md` — structured UI/UX testing prompts.
- `docs/abilities-api-assessment.md` — WordPress Abilities API (6.9+) assessment.
- `docs/sudo-architecture-comparison-matrix.md` — competitive comparison with other sudo/reauth approaches.
- `ROADMAP.md` — unified roadmap: integration tests, WP 7.0 prep, collaboration analysis, TDD strategy, core design features, feature backlog, accessibility appendix.

## Verification Requirements

LLM-generated content has a documented history of confabulation in this project.
See `llm_lies_log.txt` for the full record. These rules exist to prevent recurrence.

### External code references (method names, class names, meta keys, hooks)

- **MUST** verify against the live source before writing: WordPress.org SVN trunk,
  GitHub raw file URL, or the plugin's own codebase.
- **MUST** include the verification source in the commit message when adding or
  updating technical details about third-party code.
- If unable to verify, **MUST** say so explicitly — never guess or rely on training data.

### Statistics and counts (install numbers, version numbers, dates)

- **MUST** query the authoritative API or source. For WordPress.org plugins:
  ```bash
  curl -s "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=SLUG" | jq '.active_installs'
  ```
- **MUST** note the query date when the number is first written or updated.
- Never use training data for statistics. If the API is unreachable, say so.

### Verification commands for this project

```bash
# Plugin install counts
curl -s "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=two-factor" | jq '.active_installs'

# Verify a method/class in a WordPress.org plugin (example: AIOS/Simba TFA)
curl -s "https://plugins.svn.wordpress.org/all-in-one-wp-security-and-firewall/trunk/classes/wp-security-two-factor-login.php" | grep "class "
curl -s "https://plugins.svn.wordpress.org/all-in-one-wp-security-and-firewall/trunk/classes/simba-tfa/simba-tfa.php" | grep "function "

# Verify a GitHub-hosted plugin (example: Two Factor)
curl -s "https://raw.githubusercontent.com/WordPress/two-factor/master/class-two-factor-core.php" | grep "function "

# Project size (update readme.md table when line counts change significantly)
find . -type f -name "*.php" ! -path "*/vendor/*" ! -path "*/.git/*" -print0 | xargs -0 wc -l | tail -1          # total PHP
find ./includes ./wp-sudo.php ./uninstall.php ./mu-plugin ./bridges -type f -name "*.php" -print0 | xargs -0 wc -l | tail -1  # production
find ./tests -type f -name "*.php" -print0 | xargs -0 wc -l | tail -1                                             # tests
```

### Pre-release audit

Before tagging a release, re-verify all external claims added or modified since the
last tag. Append any new findings to `llm_lies_log.txt`. If new fabrications are
found, fix them before tagging.

Update the project size table in `readme.md` if production or test line counts
changed since the last release. Use the project size commands above.

**Version sync checklist** — every release, bump `WP_SUDO_VERSION` in ALL four places:
1. `wp-sudo.php` — plugin header `Version:` line
2. `wp-sudo.php` — `define( 'WP_SUDO_VERSION', ... )` constant
3. `phpstan-bootstrap.php` — `define( 'WP_SUDO_VERSION', ... )` constant
4. `tests/bootstrap.php` — `define( 'WP_SUDO_VERSION', ... )` constant

And update `Stable tag` in `readme.txt`.

## Test-Driven Development

All new code must follow TDD:
1. Write failing test(s) first — commit or show them before writing production code
2. Write the minimum production code to pass
3. Refactor if needed, keeping tests green
4. `composer test` must pass before every commit
5. `composer analyse` (PHPStan level 6) must pass before every commit

Never commit production code without corresponding test coverage.
Tests are the primary defense against LLM context collapse — they verify
behavior that the model cannot hold in working memory.

## Commit Practices

- Always run tests and PHPStan before committing.
- Use conventional commit format.

## Architecture

**Entry point:** `wp-sudo.php` — defines constants, registers an SPL autoloader (maps `WP_Sudo\Class_Name` to `includes/class-class-name.php`), and wires lifecycle hooks. The `wp_sudo()` function returns the singleton Plugin instance.

**Bootstrap sequence:** `plugins_loaded` → `Plugin::init()` → loads translations, runs upgrader, registers gate, sets up challenge page, initializes admin UI, registers `wp_login` hook to grant session on browser-based login, registers `after_password_reset` and `profile_update` hooks to expire session on password change.

### Core Classes (all in `includes/`, namespace `WP_Sudo`)

- **Plugin** — Orchestrator. Creates and owns the component instances. Handles activation/deactivation hooks. Strips `unfiltered_html` from editors on activation and restores it on deactivation. Expires sudo session on password change (`after_password_reset`, `profile_update`).
- **Gate** — Multi-surface interceptor. Matches incoming requests against the Action Registry and gates them via reauthentication (admin UI), error response (AJAX/REST), or policy (CLI/Cron/XML-RPC/App Passwords).
- **Action_Registry** — Defines all gated rules (21 single-site rules across 7 categories + 8 multisite rules = 29 total). Extensible via `wp_sudo_gated_actions` filter.
- **Challenge** — Interstitial reauthentication page. Handles password authentication, 2FA integration, request stash/replay.
- **Sudo_Session** — Session management. Cryptographic token (user meta + httponly cookie), rate limiting (5 attempts → 5-min lockout), session binding. Two-tier expiry: `is_active()` for true session state; `is_within_grace()` for the 120 s grace window after expiry (token-verified). Cleanup deferred until grace window closes.
- **Request_Stash** — Stashes and replays intercepted admin requests using transients.
- **Admin** — Settings page at Settings → Sudo. Settings: session duration (1–15 min), entry point policies (Disabled/Limited/Unrestricted for REST App Passwords, CLI, Cron, XML-RPC, and WPGraphQL when active). Option key: `wp_sudo_settings`.
- **Admin_Bar** — Live countdown timer in admin bar during active sessions.
- **Site_Health** — WordPress Site Health integration (status tests and debug info).
- **Upgrader** — Version-aware migration runner. Runs sequential upgrade routines when the stored version is older than the plugin version.

### Capability Restriction

On single-site, WP Sudo removes the `unfiltered_html` capability from the Editor role on activation. This ensures KSES content filtering is always active for editors. Administrators retain the capability. The capability is restored on deactivation or uninstall. On multisite, WordPress core already restricts `unfiltered_html` to Super Admins.

As a tamper-detection canary, `Plugin::enforce_editor_unfiltered_html()` runs at `init` priority 1 on every request. If the capability reappears on the Editor role (e.g. via direct `wp_user_roles` database modification), it is stripped and the `wp_sudo_capability_tampered` action fires for audit logging.

### Audit Hooks

The plugin fires 9 action hooks for external logging: `wp_sudo_activated`, `wp_sudo_deactivated`, `wp_sudo_reauth_failed`, `wp_sudo_lockout`, `wp_sudo_action_gated`, `wp_sudo_action_blocked`, `wp_sudo_action_allowed`, `wp_sudo_action_replayed`, `wp_sudo_capability_tampered`.

## Testing

Two test environments are used deliberately:

**Unit tests** (`tests/Unit/`) use **Brain\Monkey** to mock WordPress functions/hooks, **Mockery** for object mocking, and **Patchwork** for redefining `setcookie`/`header`. Fast (~0.3s). Use for: request matching, session state machine, policy enforcement, hook registration.

- `tests/bootstrap.php` — Defines WordPress constants and minimal class stubs (`WP_User`, `WP_Role`, `WP_Admin_Bar`).
- `tests/TestCase.php` — Base class with Brain\Monkey setup/teardown and `make_user()`/`make_role()` helpers.
- Test files live in `tests/Unit/` and follow the `*Test.php` naming convention.

**Integration tests** (`tests/Integration/`) load real WordPress + MySQL via `WP_UnitTestCase`. Use for: full reauth flows, real bcrypt, transient TTL, REST/AJAX gating, Two Factor interaction, multisite isolation. Requires one-time setup via `bash bin/install-wp-tests.sh` (see CONTRIBUTING.md).

- `tests/Integration/bootstrap.php` — Loads WordPress test library; loads plugin at `muplugins_loaded`.
- `tests/Integration/TestCase.php` — Base class with superglobal snapshots, static cache reset, and request simulation helpers.
- Test files live in `tests/Integration/` and follow the `*Test.php` naming convention.

PHPUnit strict mode is enabled: tests must assert something, produce no output, and not trigger warnings.

## Uninstall

`uninstall.php` handles multisite-safe cleanup: restores `unfiltered_html` to editors, removes the v1 Site Manager role (if present), deletes `wp_sudo_settings` option, and cleans user meta (`_wp_sudo_*` keys) across all sites in a network.
