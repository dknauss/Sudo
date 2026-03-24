# Contributing to WP Sudo

## Prerequisites

- PHP 8.1+
- Composer
- MySQL 8.0 server plus client tools (`mysql`, `mysqladmin`) (for integration tests only)
- SVN + unzip (for integration test setup only — `apt install subversion unzip` on Ubuntu, `brew install subversion` on macOS)

## Setup

```bash
composer install
```

## Running Tests

### Unit tests (fast, no database)

```bash
composer test:unit
```

Runs in ~0.3s. No external dependencies — all WordPress functions are mocked with Brain\Monkey.

### Integration tests (real WordPress + MySQL)

```bash
# Recommended: pin WP test paths to the repo-local .tmp directory.
# This avoids accidentally using stale global /tmp test configs with mismatched DB credentials.
export WP_TESTS_DIR="$PWD/.tmp/wordpress-tests-lib"
export WP_CORE_DIR="$PWD/.tmp/wordpress"

# One-time setup: installs the WordPress 7.0 beta 4 test library and creates test DB
bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 7.0-RC1

# Run tests
composer test:integration
```

Set `WP_MULTISITE=1` to run the multisite test suite:

```bash
WP_MULTISITE=1 composer test:integration
```

If the generated host-side WordPress test config points at a stale or unreachable
Docker-published MySQL port after a `wp-env` rebuild, `composer test:integration`
now falls back automatically to the running `wp-env` `tests-cli` container and
executes the same suite there against the `tests-mysql` service.

If `127.0.0.1` is not serving MySQL but Local by Flywheel is running, `bin/install-wp-tests.sh` now auto-detects a single Local MySQL socket under `~/Library/Application Support/Local/run/*/mysql/mysqld.sock` and rewrites the generated `DB_HOST` to `localhost:/path/to/mysqld.sock`.

If you have multiple Local sites running and want to choose a specific socket, pass it explicitly:

```bash
WP_TESTS_DIR="$PWD/.tmp/wordpress-tests-lib" \
WP_CORE_DIR="$PWD/.tmp/wordpress" \
bash bin/install-wp-tests.sh \
  wordpress_test \
  root \
  root \
  "localhost:$HOME/Library/Application Support/Local/run/<site-id>/mysql/mysqld.sock" \
  7.0-RC1
```

To discover the available Local sockets:

```bash
find "$HOME/Library/Application Support/Local/run" -name mysqld.sock
```

If you do not want to export variables in your shell, prefix each command:

```bash
WP_TESTS_DIR="$PWD/.tmp/wordpress-tests-lib" \
WP_CORE_DIR="$PWD/.tmp/wordpress" \
composer test:integration

WP_TESTS_DIR="$PWD/.tmp/wordpress-tests-lib" \
WP_CORE_DIR="$PWD/.tmp/wordpress" \
WP_MULTISITE=1 composer test:integration
```

### E2E tests against Studio/Local (Flywheel)

You can run Playwright against an existing local WordPress site (Studio or Local by Flywheel) instead of `wp-env`:

```bash
WP_BASE_URL="http://your-local-site.test" \
WP_USERNAME="admin" \
WP_PASSWORD="your-password" \
npm run test:e2e:local
```

Use the local site's real admin credentials. `WP_BASE_URL` can be `http://` or `https://` depending on your local environment.

For WordPress Studio, use the Studio site's localhost port directly. Studio is the
recommended local path for SQLite-focused verification because it avoids pretending
that the default `wp-env` Docker stack exercises SQLite:

```bash
WP_BASE_URL="http://localhost:8881" \
WP_USERNAME="admin" \
WP_PASSWORD="password" \
npm run test:e2e:local
```

If you point Playwright at a Studio site, keep `WP_BASE_URL` and `WP_REQUEST_BASE_URL`
on the same `localhost:<port>` origin unless you intentionally configured a custom
domain for that Studio instance.

To run the multisite network-admin regression against the `multisite-subdomains` Local site:

```bash
WP_BASE_URL="https://multisite-subdomains.local" \
WP_USERNAME="test" \
WP_PASSWORD="test" \
npm run test:e2e:local:multisite
```

This spec is local-only. It skips automatically under the default single-site `wp-env` configuration used in CI.

### Local site plugin drift and symlink management

Some Local sites use a copied `wp-content/plugins/wp-sudo` directory instead of
the live repo checkout. In that case the site can drift behind `main` even when
this repo is up to date.

The canonical helper for compare/sync/symlink operations is:

```bash
npm run local:plugin -- status
npm run local:plugin -- sync
npm run local:plugin -- link
```

By default it targets the active Local multisite environment used for `multisite-subdomains.local`:

```bash
/Users/danknauss/Development/Local Sites/multisite-subdomains/app/public/wp-content/plugins/wp-sudo
```

Override that path with `SITE_PLUGIN=/path/to/site/wp-content/plugins/wp-sudo`.

Before debugging unexpected Local-site behaviour, run:

```bash
npm run local:plugin -- status
```

If the site copy differs, sync it from the repo before continuing:

```bash
npm run local:plugin -- sync
```

If you want zero drift risk, replace the copied plugin directory with a symlink
to this repo checkout:

```bash
npm run local:plugin -- link
```

The `link` command preserves the previous copied plugin directory as a timestamped
backup before replacing it with a symlink.

### E2E CI split: functional vs visual

The Playwright suite is split into two projects:

- `chromium` — required functional E2E coverage. This is what `npm run test:e2e` runs locally and what the required `E2E Tests` GitHub workflow runs in CI.
- `chromium-visual` — visual regression baselines only. This is what `npm run test:e2e:visual` runs locally and what the separate non-blocking `E2E Visual Baselines` workflow runs.

Run the functional suite locally:

```bash
npm run test:e2e
```

If `wp-env` gets stuck after a partial Docker startup, clear the generated stack
and retry:

```bash
npm run env:stop
npm run env:clean
npm run env:start
npm run env:assert-wp-version
```

The last command verifies that both `wp-env` environments are actually running
WordPress `7.0-RC1`, not just that the containers started.

Run both Playwright projects locally:

```bash
npm run test:e2e:all
```

Run visual baselines only:

```bash
npm run test:e2e:visual
```

Refresh visual snapshots intentionally:

```bash
npm run test:e2e:visual -- --update-snapshots
```

Do not update visual snapshots just to make required CI pass. Review the uploaded Playwright artifacts from the `E2E Visual Baselines` workflow first, then refresh snapshots only when the UI change is intentional.

### Release CI gates vs breadth checks

WP Sudo now has two different kinds of browser/compatibility workflows:

- **Primary release gates:** `PHPUnit`, `Psalm`, `CodeQL`, `E2E Tests`, and `E2E Nginx Smoke`. These should all be green before tagging a release.
- **Breadth workflows:** `WordPress Compat Sweep` and `E2E SQLite Smoke`. These expand compatibility signal across older WordPress minors and SQLite, but they are not the fast-feedback path for every push or PR.

The nginx smoke workflow is treated as a first-class release gate because it is fast, stable, and catches stack-sensitive issues around routing, cookies, redirects, replay, and AJAX behavior that the default Apache stack can miss.

The scheduled compatibility sweep and SQLite smoke workflow should still be checked before release when the touched code could plausibly affect:

- WordPress version compatibility (`6.3` through `6.6`)
- MariaDB-specific behavior outside the main integration matrix
- SQLite request handling, bootstrap timing, or alternate persistence behavior
- stack-sensitive flows such as redirects, cookies, request replay, and admin/AJAX request handling

When SQLite-specific release assurance matters, run the Studio checklist in
[`docs/studio-sqlite-release-runbook.md`](docs/studio-sqlite-release-runbook.md)
instead of treating the CI smoke lane as a full substitute for local SQLite verification.

When expanding the alternate-stack smoke pack, keep it focused on stack-sensitive behaviors only. Do not clone the full Playwright suite onto every environment. Prefer adding or extending smoke cases when a flow depends on:

- cookies or session persistence
- redirects or replay after auth
- admin-bar or AJAX request handling
- settings POST replay
- challenge rendering or browser-visible auth transitions

If `mysql` or `mysqladmin` is not found, install client tooling first:

```bash
# macOS (Homebrew)
brew install mysql-client

# Ubuntu/Debian
sudo apt install mysql-client
```

Homebrew may install client binaries without linking them on PATH. If needed:

```bash
# Apple Silicon
export PATH="/opt/homebrew/opt/mysql-client/bin:$PATH"

# Intel macOS
export PATH="/usr/local/opt/mysql-client/bin:$PATH"
```

### Static analysis + code style

```bash
composer analyse:phpstan  # PHPStan
composer analyse:psalm    # Psalm + WordPress plugin/stubs
composer analyse          # Runs both
composer lint      # PHPCS (WordPress-Extra + WordPress-Docs + VIP rulesets)
composer lint:fix  # Auto-fix PHPCS violations
```

### Metrics refresh (when counts change)

Current live counts are centralized in `docs/current-metrics.md`.

```bash
composer verify:metrics
```

If it reports drift, update `docs/current-metrics.md` first, then re-run `composer verify:metrics` until it passes.

## Test Strategy

Two environments are used deliberately — choose based on what you are testing:

**Unit tests** (`tests/Unit/`) use Brain\Monkey to mock all WordPress functions. Use for:
- Request matching logic (Gate surfaces, action registry)
- Session state machine and policy enforcement
- Hook registration
- Settings sanitization and defaults
- Upgrader migration logic

**Integration tests** (`tests/Integration/`) load real WordPress against a MySQL database. Use for:
- Full reauth flows (Gate → Challenge → Session → Stash)
- Real bcrypt password verification (`wp_check_password`)
- Transient TTL and cookie behavior
- REST and AJAX gating
- Two Factor plugin interaction
- Multisite session isolation
- Upgrader migrations against real DB

When in doubt: if the test needs a real database, real crypto, or calls that cross class boundaries in production, write an integration test.

## Coverage Goals

Do not optimize for a single global coverage percentage. WP Sudo is a security plugin, so coverage targets are risk-weighted by test category:

- **Unit tests:** target `90%+` line coverage on business logic in `includes/`, and near-`100%` branch coverage on security-critical state machines and policy code such as `Sudo_Session`, `Gate`, `Challenge`, `Action_Registry`, and request replay logic.
- **Integration tests:** target `100%` scenario coverage for security-critical WordPress flows at least once in a real environment. That includes session activation/expiry, grace-window behavior, password failure/throttle/lockout/expiry recovery, 2FA pending/validation/resend/expiry, stash save/get/delete/replay, AJAX/REST/admin exit behavior, and multisite isolation.
- **E2E/browser tests:** target `100%` coverage of every distinct user-visible challenge/replay branch. Each visible password, 2FA, stale-session, resend, throttle, lockout, expiry-recovery, and replay path should have at least one Playwright case.
- **Bridge files:** target `100%` unit coverage for first-party integration bridges. These adapters are thin and should be fully covered directly.
- **Lifecycle code:** target `100%` integration coverage for activation, deactivation, uninstall, and upgrade routines.

Static analysis is part of the assurance target, not optional polish. `composer analyse:phpstan`, `composer analyse:psalm`, `composer lint`, and the required GitHub workflows should remain green at all times.

## Current Coverage Snapshot

Current live counts and matrix details are centralized in [`docs/current-metrics.md`](docs/current-metrics.md). As of the latest verification:

- `514` unit tests and `1348` assertions cover core business logic, bridges, and state-machine behavior.
- `139` integration test methods cover real-WordPress flows across admin, REST, AJAX, request stash/replay, multisite, and Two Factor interaction.
- `58` Playwright tests cover the browser-visible challenge flow, including stale-session recovery, 2FA, resend, throttle, lockout, expiry recovery, stash replay, and reusable stack-smoke subsets for alternate environments.
- CI runs unit tests on PHP `8.0` through `8.4`.
- CI runs integration tests on PHP `8.0`, `8.1`, and `8.3` against WordPress `6.2`, `6.7`, and `7.0-RC1`.
- CI runs a scheduled WordPress compatibility sweep on `6.3`, `6.4`, `6.5`, and `6.6` with PHP `8.1`, plus MariaDB overlap lanes on WordPress `6.4` and `6.5`.
- CI runs integration coverage on MySQL `8.0` plus one MariaDB LTS lane.
- CI runs browser tests on the default `wp-env` Apache + MariaDB stack and stack-smoke browser tests on explicit nginx + php-fpm + MariaDB, explicit nginx + php-fpm + MariaDB multisite, and Playground SQLite lanes.
- Studio remains the recommended local path for deeper SQLite investigation beyond the automated smoke coverage, with a dedicated release runbook in [`docs/studio-sqlite-release-runbook.md`](docs/studio-sqlite-release-runbook.md).

The strongest covered area is the challenge flow itself: password and 2FA auth, stale tabs, resend behavior, throttle/lockout UX, expiry recovery, and request replay all have automated coverage. The main remaining gaps are matrix depth on alternate stacks rather than core flow depth: the full browser suite still runs only on the default Apache + MariaDB lane, while nginx and SQLite currently run the focused stack-smoke subset.

### Separate Multisite Alternate-Stack Lane

Keep [`tests/e2e/specs/stack-smoke.spec.ts`](tests/e2e/specs/stack-smoke.spec.ts) single-site only. Its job is fast stack-sensitivity coverage around cookies, redirects, replay, AJAX, and REST, not full environment matrix depth.

Multisite alternate-stack coverage now lives in its own lane:

- workflow: [`e2e-nginx-multisite.yml`](.github/workflows/e2e-nginx-multisite.yml)
- compose stack: [`nginx-mariadb-multisite.compose.yml`](.github/docker/nginx-mariadb-multisite.compose.yml)
- spec: [`multisite-stack-smoke.spec.ts`](tests/e2e/specs/multisite-stack-smoke.spec.ts)

That lane intentionally starts small:

- network-admin challenge cancel/return behavior
- one gated network-admin POST replay

It is a breadth workflow, not a merge gate. Keep single-site and multisite alternate-stack signals separate so failures stay easy to interpret. Keep SQLite multisite out of CI until the nginx + MariaDB multisite lane proves stable over time.

## WordPress Playground

Every PR automatically gets a **"Try in WordPress Playground"** comment with a
link that installs the plugin from that PR's commit and lands you in the admin
logged in as `admin` / `password`.

Current Playground previews are pinned to WordPress `7.0-RC1` as of 2026-03-24.

For WordPress 7.0 release signoff, do not treat the green RC-era CI matrix as a substitute for the remaining RC/GA manual passes. RC1 is recorded in the `15.0 Release Signoff Log` table in [`tests/MANUAL-TESTING.md`](tests/MANUAL-TESTING.md); repeat that signoff for each later RC and for GA before claiming final 7.0 readiness.

### What you can test in Playground

| Feature | Notes |
|---|---|
| Plugin activation & settings page | ✅ |
| Gate fires on dangerous actions (plugin activate/delete, user delete, etc.) | ✅ |
| Challenge / reauthentication page | ✅ |
| Password verification & session cookie | ✅ |
| Admin bar countdown timer | ✅ |
| Request stash & replay after auth | ✅ |
| Rate limiting / 5-attempt lockout | ✅ within session |
| Session expiry by time | ✅ wait out the configured duration (1–15 min) |
| Two Factor plugin (TOTP) | ✅ installed automatically via blueprint |
| `unfiltered_html` removed from Editor role | ✅ |

### What won't work in Playground

| Feature | Why |
|---|---|
| WP-CLI / Cron entry point policies | No CLI in browser |
| REST / XML-RPC entry point policies | Network disabled in Playground |
| Two Factor email / magic-link providers | PHP outbound network is off |
| WebAuthn bridge (security key gating) | Browser WebAuthn API unavailable in Playground sandbox |
| Multisite behaviour | Single-site only |
| State after refreshing Playground | Full reset on page reload |

Transients and user meta persist across normal WP navigation within a session,
but are wiped if you reload the Playground page itself. Use the integration test
suite (`composer test:integration`) to verify transient TTL, real bcrypt, and
multisite isolation — those require a real MySQL database and can't be covered by
Playground.

To test the current `main` branch interactively without opening a PR, use
[`blueprint.json`](blueprint.json) directly:
`https://playground.wordpress.net/#` + the URL-encoded contents of that file.

## TDD Workflow

1. Write a failing test first — commit or show it before writing production code
2. Write the minimum production code to pass
3. `composer test:unit` must pass before every commit
4. `composer analyse` must pass before every commit (PHPStan + Psalm)

## MU-Plugin (Local Dev)

The one-click installer on the Settings page copies the shim via `file_put_contents`. If it fails due to file permissions in your local environment, install manually:

1. Copy `mu-plugin/wp-sudo-gate.php` from the plugin directory
2. Paste it into `wp-content/mu-plugins/wp-sudo-gate.php` (create the directory if needed)
3. The mu-plugin will be active on the next page load

## Commit Conventions

Use conventional commit format. Run tests and static analysis before committing.

## Manual Testing

The `tests/MANUAL-TESTING.md` checklist covers 20 test areas across all surfaces (admin UI, AJAX, REST, CLI, Cron, XML-RPC, WPGraphQL, multisite). Run through it before tagging a release. The file includes expected results, curl commands, and cleanup steps. For structured UI/UX-focused testing scenarios, see [docs/ui-ux-testing-prompts.md](docs/ui-ux-testing-prompts.md).

## Extending Gated Actions

Use the `wp_sudo_gated_actions` filter. See [docs/developer-reference.md](docs/developer-reference.md) for the rule structure and examples.

### Bridge Plugins

The `bridges/` directory contains drop-in mu-plugins that gate third-party plugin actions:

| Bridge | Target Plugin | What It Gates |
|--------|--------------|---------------|
| `wp-sudo-wp2fa-bridge.php` | WP 2FA (Melapress) | Connects WP 2FA's TOTP/email/backup code methods to the sudo challenge page |
| `wp-sudo-webauthn-bridge.php` | Two Factor Provider for WebAuthn | Gates security key registration and deletion via AJAX |

To create a new bridge:

1. Copy an existing bridge as a template
2. Add a class-existence guard so rules are only injected when the target plugin is active
3. Add AJAX and/or REST rules via `wp_sudo_gated_actions`
4. Add unit tests in `tests/Unit/` and manual test steps in `tests/MANUAL-TESTING.md`
5. Document in `docs/developer-reference.md` and `docs/two-factor-integration.md` (if 2FA-related)

See [docs/developer-reference.md](docs/developer-reference.md#gating-third-party-plugin-actions) for the full worked example.

## Audit Hooks

Nine action hooks are available for external logging. See [docs/developer-reference.md](docs/developer-reference.md).
