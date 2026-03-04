# Phase 1: Integration Test Harness Scaffold - Research

**Researched:** 2026-02-19
**Domain:** WordPress plugin integration testing infrastructure (WP_UnitTestCase + PHPUnit 9.6 + Brain\Monkey coexistence)
**Confidence:** HIGH — all implementation details verified against: live codebase files, wordpress-develop trunk source, yoast/phpunit-polyfills README, wp-cli/scaffold-command template, Brain\Monkey vendor source

---

## Summary

Phase 1 adds the integration test scaffold to WP Sudo — the infrastructure required for a real WordPress + MySQL test environment to coexist cleanly alongside the existing 220 Brain\Monkey unit tests. No tests are written in this phase; only the harness is created. The phase is complete when `composer test:unit` passes all existing tests unchanged and `composer test:integration` runs an empty suite with 0 failures.

The two test environments are mutually exclusive at the PHP process level. Brain\Monkey replaces WordPress function names with Mockery stubs; the WP test library defines those same function names for real. They cannot share a bootstrap file, a PHPUnit config, or a Composer script invocation. Every deliverable in this phase exists to enforce that separation.

The Patchwork isolation concern (Pitfall 2 from milestone research) is fully resolved by the codebase structure: Patchwork is loaded lazily inside `Brain\Monkey\Container::instance()`, which is only called when `Brain\Monkey\setUp()` is invoked. The integration bootstrap never calls `Brain\Monkey\setUp()`, so Patchwork is never activated in the integration process — no special exclusion configuration is needed.

**Primary recommendation:** Create all 7 deliverables in order (Composer dep → script → config → bootstrap → TestCase → install script → CI workflow), verify each independently, and confirm no Brain\Monkey or Patchwork symbols appear in any integration file before closing the phase.

---

## 1. Current State

### What Exists Today

**File: `composer.json`** — current state:

```json
{
    "require-dev": {
        "automattic/vipwpcs": "^3.0",
        "brain/monkey": "^2.7",
        "cyclonedx/cyclonedx-php-composer": "^6.2",
        "mockery/mockery": "^1.6",
        "phpstan/phpstan": "^2.0",
        "phpunit/phpunit": "^9.6",
        "szepeviktor/phpstan-wordpress": "^2.0"
    },
    "scripts": {
        "lint": "phpcs",
        "lint:fix": "phpcbf",
        "test": "phpunit",
        "analyse": "phpstan analyse",
        "sbom": "composer CycloneDX:make-sbom --output-file=bom.json --output-format=JSON --spec-version=1.6 --output-reproducible"
    }
}
```

`yoast/phpunit-polyfills` is NOT in `require-dev`. `test:unit` and `test:integration` scripts do NOT exist. `composer test` runs plain `phpunit` with no `--configuration` flag, which means PHPUnit auto-discovers `phpunit.xml.dist`.

**File: `phpunit.xml.dist`** — current state:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.6/phpunit.xsd"
    bootstrap="tests/bootstrap.php"
    colors="true"
    beStrictAboutTestsThatDoNotTestAnything="true"
    beStrictAboutOutputDuringTests="true"
    failOnWarning="true"
    failOnRisky="true"
>
    <testsuites>
        <testsuite name="Unit">
            <directory suffix="Test.php">tests/Unit</directory>
        </testsuite>
    </testsuites>
    <coverage>
        <include>
            <directory suffix=".php">includes</directory>
        </include>
    </coverage>
</phpunit>
```

Key flags: `beStrictAboutOutputDuringTests="true"`, `failOnWarning="true"`, `failOnRisky="true"`. These must be KEPT for the unit config and intentionally NOT copied to the integration config.

**File: `patchwork.json`** — current state:

```json
{
    "redefinable-internals": [
        "setcookie",
        "header",
        "hash_equals"
    ]
}
```

This file is read by Patchwork when it initializes. Patchwork initializes inside `Brain\Monkey\Container::instance()` (see `vendor/brain/monkey/inc/patchwork-loader.php`). Patchwork is NOT in the Composer `autoload.files` list — it has no autoload entry in its own `composer.json`. It only activates when `Brain\Monkey\setUp()` is called in a test. **The integration bootstrap must never call `Brain\Monkey\setUp()`.** This is the complete Patchwork isolation solution — no additional exclusion configuration is required.

**File: `tests/bootstrap.php`** — existing unit bootstrap:
- Defines `ABSPATH = '/tmp/fake-wordpress/'`
- Defines `WP_CONTENT_DIR`, `WP_SUDO_VERSION`, plugin constants, time constants, cookie constants
- Defines stub classes: `WP_User`, `WP_Admin_Bar`, `WP_Error`, `WP_REST_Request`, `WP_Screen`
- Defines `Two_Factor_Provider` and `Two_Factor_Core` stubs
- Requires `vendor/autoload.php`

**CRITICAL:** This file defines `ABSPATH` and global class stubs. The integration bootstrap MUST NOT include this file or require it in any way. The `vendor/autoload.php` must be required independently.

**File: `tests/TestCase.php`** — existing unit base class:
- Namespace: `WP_Sudo\Tests`
- Extends: `PHPUnit\Framework\TestCase`
- Uses: `MockeryPHPUnitIntegration` trait
- `setUp()`: calls `Monkey\setUp()`, stubs `wp_unslash`, `sanitize_text_field`, `rest_get_authenticated_app_password`, `is_multisite`, `is_network_admin`, `network_admin_url`
- `tearDown()`: clears `$_COOKIE`, calls `Action_Registry::reset_cache()`, `Sudo_Session::reset_cache()`, `Admin::reset_cache()`, then `Monkey\tearDown()`

**File: `tests/Unit/`** — 10 test files (220+ tests):
`ActionRegistryTest.php`, `AdminBarTest.php`, `AdminTest.php`, `ChallengeTest.php`, `GateTest.php`, `PluginTest.php`, `RequestStashTest.php`, `SiteHealthTest.php`, `SudoSessionTest.php`, `UpgraderTest.php`

**File: `.github/workflows/copilot-setup-steps.yml`** — only existing workflow:
- Triggered on `workflow_dispatch`, `push`/`pull_request` to the workflow file itself
- Installs PHP 8.3, runs `composer install --no-interaction --prefer-dist`
- Does NOT run tests

**Directory: `bin/`** — does NOT exist. Must be created.

**Directory: `tests/integration/`** — does NOT exist. Must be created.

**File: `.github/workflows/phpunit.yml`** — does NOT exist. Must be created.

---

## 2. Implementation Details

### Deliverable A: Add `yoast/phpunit-polyfills ^2.0` to `require-dev`

**Command:**
```bash
composer require --dev yoast/phpunit-polyfills:"^2.0"
```

**Why `^2.0` not `^1.1`:** The WordPress core bootstrap requires minimum 1.1.0. Version 2.x supports PHPUnit 5.7–10.x; version 1.x supports 4.8–9.x. Both satisfy PHPUnit 9.6. Using 2.x future-proofs for PHPUnit 10 when the project eventually upgrades. The `config.platform.php` is set to `8.1.99` so Composer will resolve 2.x cleanly.

**What this adds:** `vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php` — the file that WP's bootstrap needs.

**Default path:** The WordPress test bootstrap (`wordpress-tests-lib/includes/bootstrap.php`) defaults to looking for polyfills at `dirname(__DIR__, 3) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php'`. When `WP_TESTS_DIR=/tmp/wordpress-tests-lib`, that resolves to `/tmp/vendor/yoast/...` — which won't exist. Therefore, the integration bootstrap MUST define `WP_TESTS_PHPUNIT_POLYFILLS_PATH` pointing to the plugin's own vendor directory before loading the WP bootstrap.

### Deliverable B: `phpunit-integration.xml.dist`

**Exact content:**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.6/phpunit.xsd"
    bootstrap="tests/integration/bootstrap.php"
    colors="true"
    beStrictAboutTestsThatDoNotTestAnything="true"
>
    <testsuites>
        <testsuite name="Integration">
            <directory suffix="Test.php">tests/integration</directory>
        </testsuite>
    </testsuites>

    <coverage>
        <include>
            <directory suffix=".php">includes</directory>
        </include>
    </coverage>
</phpunit>
```

**Schema:** Same `https://schema.phpunit.de/9.6/phpunit.xsd` as the unit config — both use PHPUnit 9.6.

**Intentionally omitted from integration config vs. unit config:**
- `beStrictAboutOutputDuringTests="true"` — WordPress core emits output during bootstrap (deprecation notices). This flag would fail the entire run before a single test executes.
- `failOnWarning="true"` — WordPress and the WP test library emit deprecation warnings during bootstrap. These would cascade into failures.
- `failOnRisky="true"` — Omitted for same reason.

**Bootstrap path:** `tests/integration/bootstrap.php` (relative to repo root, same as how `phpunit.xml.dist` references `tests/bootstrap.php`).

**Testsuite directory:** `tests/integration` (lowercase — matches the directory structure used by Two Factor and wordpress-importer plugins, verified against their source).

### Deliverable C: `tests/integration/bootstrap.php`

**Exact content:**

```php
<?php
/**
 * PHPUnit bootstrap for WP Sudo integration tests.
 *
 * Loads the real WordPress test environment (WP_UnitTestCase, real DB).
 * Brain\Monkey and Patchwork are NOT loaded here — integration tests
 * use real WordPress functions, not mocks.
 *
 * Prerequisites: run `bash bin/install-wp-tests.sh` once before using this suite.
 *
 * @package WP_Sudo\Tests\Integration
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
    $_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    echo "ERROR: WordPress test library not found at {$_tests_dir}/includes/functions.php\n";
    echo "Run: bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest\n";
    exit( 1 );
}

// Polyfills path: tell the WP bootstrap where to find yoast/phpunit-polyfills.
// The WP bootstrap defaults to dirname(__DIR__, 3)/vendor/... which resolves to
// /tmp/vendor/... (wrong). Point it to this plugin's vendor directory instead.
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );

// Give access to tests_add_filter() before WordPress boots.
require_once $_tests_dir . '/includes/functions.php';

// Load the plugin under test at muplugins_loaded — the earliest safe hook.
// muplugins_loaded fires after WP core functions exist but before plugins_loaded.
// wp-sudo.php calls add_action(), which requires WordPress functions.
tests_add_filter(
    'muplugins_loaded',
    static function () {
        require_once dirname( __DIR__, 2 ) . '/wp-sudo.php';
    }
);

// Composer autoloader for WP_Sudo\Tests\Integration\* classes.
// Do NOT require tests/bootstrap.php — it defines ABSPATH and class stubs
// that conflict with the real WordPress environment loaded below.
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Boot the real WordPress environment (connects to MySQL, fires init hooks).
require $_tests_dir . '/includes/bootstrap.php';
```

**Key decisions documented in comments:**
- `WP_TESTS_PHPUNIT_POLYFILLS_PATH` defined before `bootstrap.php` is required (the WP bootstrap reads it on load)
- `tests_add_filter()` called before WP bootstrap (which fires the hooks)
- `vendor/autoload.php` required directly, NOT `tests/bootstrap.php`
- No Brain\Monkey, no Patchwork, no class stubs

**Patchwork isolation (verified):** Patchwork is loaded by `Brain\Monkey\Container::instance()`, which is called only from `Brain\Monkey\setUp()`. The integration bootstrap never calls `Brain\Monkey\setUp()`. Patchwork is never activated. No `--prepend` or config exclusion is needed.

**Cookie testing boundary (Pitfall 3):** `setcookie()` emits HTTP headers in a real web context but silently no-ops in a CLI context (PHPUnit runs via CLI). Integration tests can assert `$_COOKIE` superglobal mutations and user meta values (`_wp_sudo_token`, `_wp_sudo_expires`), but NOT actual browser-visible cookie attributes (httponly, samesite, secure). This limitation is by design; document in the comment block.

**TTL testing boundary (Pitfall 4):** `time()` cannot be mocked without Patchwork. Integration tests verify the happy path (transient written, transient read before expiry, explicit `delete_transient` path). TTL expiry tests stay in the unit suite where Patchwork can mock `time()`.

### Deliverable D: `tests/integration/TestCase.php`

**What `WP_UnitTestCase` already provides (verified against `wordpress-develop/tests/phpunit/includes/abstract-testcase.php`):**
- `set_up()` / `tear_down()` (snake_case — PHPUnit 9.6 polyfill layer handles the camelCase bridge)
- `START TRANSACTION` on `set_up()`, `ROLLBACK` on `tear_down()` — no manual DB cleanup needed
- `static::factory()` — returns `WP_UnitTest_Factory` with `->user`, `->post`, `->comment`, `->term`, `->blog`, `->network` sub-factories
- `assertWPError()`, `assertNotWPError()`, `assertEqualSets()`, `assertSameSets()`, `assertQueryTrue()`
- Hook state backup/restore in `set_up_before_class()` / `tear_down_after_class()`

**WP_UnitTestCase does NOT have:** `make_admin()`, `make_user()`, or `activate_plugin()`. These must be added in our custom TestCase.

**Exact content:**

```php
<?php
/**
 * Base integration test case for WP Sudo.
 *
 * Extends WP_UnitTestCase for real database + WordPress environment.
 * Each test runs in a database transaction that is rolled back in tear_down().
 *
 * Do NOT use Brain\Monkey here. Do NOT call Monkey\setUp().
 * Integration tests use real WordPress functions, not mocks.
 *
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

/**
 * Base class for WP Sudo integration tests.
 */
class TestCase extends \WP_UnitTestCase {

    /**
     * Create an administrator user with a real bcrypt-hashed password in the database.
     *
     * Uses the factory so the created user is auto-cleaned up in tear_down().
     * wp_hash_password() uses cost=5 in test environments (WP_UnitTestCase default).
     *
     * @param string $password Plain-text password for verification tests.
     * @return \WP_User
     */
    protected function make_admin( string $password = 'test-password' ): \WP_User {
        $user_id = self::factory()->user->create(
            array(
                'role'      => 'administrator',
                'user_pass' => $password,
            )
        );
        return get_user_by( 'id', $user_id );
    }

    /**
     * Trigger the plugin's activation hook explicitly.
     *
     * The plugin is loaded via muplugins_loaded in the bootstrap, which does not
     * fire the activation hook. Tests that verify activation side effects
     * (unfiltered_html removal, option creation) must call this method.
     */
    protected function activate_plugin(): void {
        do_action( 'activate_wp-sudo/wp-sudo.php' );
    }
}
```

**`make_admin()` design notes:**
- Uses `self::factory()->user->create()` so the user is tracked by `WP_UnitTestCase`'s factory and automatically cleaned up on `tear_down()`. Do NOT use `wp_create_user()` directly — it bypasses factory tracking and leaks users across tests.
- `user_pass` is accepted as a factory argument; WordPress hashes it via `wp_hash_password()` during insert. In test environments, `WP_UnitTestCase` reduces bcrypt cost to 5 automatically (via `wp_hash_password_options` filter). This is fast AND exercises the real bcrypt path.
- Returns `\WP_User` (real object, not a stub).

**`activate_plugin()` design notes:**
- The hook name is `'activate_' . plugin_basename( __FILE__ )` which for this plugin resolves to `'activate_wp-sudo/wp-sudo.php'`. Verify against `wp-sudo.php` activation hook registration before using.
- This method is needed because `register_activation_hook()` fires only when activated via the WordPress admin UI, not when loaded via `muplugins_loaded`.

### Deliverable E: `bin/install-wp-tests.sh`

**Source:** `wp-cli/scaffold-command` template (fetched 2026-02-19 from GitHub raw). The complete script content was verified against the actual file.

**Argument order (positional):**
```
$1 = DB_NAME
$2 = DB_USER
$3 = DB_PASS
$4 = DB_HOST (default: localhost)
$5 = WP_VERSION (default: latest)
$6 = SKIP_DB_CREATE (default: false)
```

**Environment variables read by the script:**
- `TMPDIR` (default: `/tmp`) — base temp directory
- `WP_TESTS_DIR` (default: `$TMPDIR/wordpress-tests-lib`) — where test library is installed
- `WP_CORE_DIR` (default: `$TMPDIR/wordpress`) — where WordPress core is installed

**What the script does:**
1. `install_wp` — downloads WordPress core to `$WP_CORE_DIR` via tarball (or SVN for trunk)
2. `install_test_suite` — SVN-exports `tests/phpunit/includes/` and `tests/phpunit/data/` from `develop.svn.wordpress.org/${WP_TESTS_TAG}/` to `$WP_TESTS_DIR`; downloads and configures `wp-tests-config.php` (substitutes DB credentials)
3. `install_db` — creates the MySQL test database via `mysqladmin create`

**WP_TESTS_TAG derivation:**
- `trunk` or `nightly` → `"trunk"`
- `X.Y` (e.g., `6.9`) → `"branches/X.Y"`
- `X.Y.Z` → `"tags/X.Y.Z"` (with `.0` handling)
- `latest` (default) → fetches current version from WordPress API, maps to tag

**CI invocation (use `127.0.0.1` not `localhost`):**
```bash
bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest
bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 trunk
```

Using `localhost` in CI causes MySQL to attempt a Unix socket connection, which fails in GitHub Actions service containers. `127.0.0.1` forces TCP.

**Local development invocation:**
```bash
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

**Script requires `svn`:** The `check_svn_installed()` function verifies SVN is available and exits with an error if not. In GitHub Actions, SVN is available via `shivammathur/setup-php` with `tools: svn`. On macOS, install via `brew install subversion`.

**File must be executable:**
```bash
chmod +x bin/install-wp-tests.sh
```

The exact script content to commit is the verbatim content from `wp-cli/scaffold-command/blob/main/templates/install-wp-tests.sh` (fetched 2026-02-19). Use it without modification — it is a maintained, tested script that handles macOS/Linux differences (Darwin vs Linux `sed -i` syntax), version parsing, and both curl/wget availability.

### Deliverable F: Updated `composer.json` scripts

**Exact new scripts section:**

```json
"scripts": {
    "lint": "phpcs",
    "lint:fix": "phpcbf",
    "test": "phpunit",
    "test:unit": "phpunit --configuration phpunit.xml.dist",
    "test:integration": "phpunit --configuration phpunit-integration.xml.dist",
    "analyse": "phpstan analyse",
    "sbom": "composer CycloneDX:make-sbom --output-file=bom.json --output-format=JSON --spec-version=1.6 --output-reproducible"
}
```

**Key decisions:**
- `"test": "phpunit"` is UNCHANGED. When run without `--configuration`, PHPUnit auto-discovers `phpunit.xml.dist` (the unit config). This preserves backward compatibility with `CLAUDE.md` instructions and pre-commit habits.
- `"test:unit"` explicitly passes `--configuration phpunit.xml.dist` — identical result to `composer test`, but explicit.
- `"test:integration"` uses `phpunit-integration.xml.dist` (with `.dist` suffix — the `.dist` convention means "distributable default; override locally with `phpunit-integration.xml`").
- There is no `"test:all"` script — developers without MySQL can still run `composer test` safely.

**PHPUnit binary path:** The scripts use `phpunit` without the `./vendor/bin/` prefix. Composer scripts add `vendor/bin` to `PATH` automatically, so this works. Alternatively, write `./vendor/bin/phpunit` for explicitness — both are equivalent in Composer scripts context.

### Deliverable G: `.github/workflows/phpunit.yml`

**Complete workflow file:**

```yaml
name: PHPUnit

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  unit-tests:
    name: "Unit Tests (PHP ${{ matrix.php }})"
    runs-on: ubuntu-24.04
    strategy:
      fail-fast: false
      matrix:
        php: ['8.1', '8.2', '8.3', '8.4']
    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          tools: composer:v2
          coverage: none

      - name: Install Composer dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Run unit tests
        run: composer test:unit

  integration-tests:
    name: "Integration Tests (PHP ${{ matrix.php }}, WP ${{ matrix.wp }})"
    runs-on: ubuntu-24.04
    strategy:
      fail-fast: false
      matrix:
        php: ['8.1', '8.3']
        wp: ['latest', 'trunk']
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: wordpress_test
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=5
    env:
      WP_TESTS_DIR: /tmp/wordpress-tests-lib
    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          tools: composer:v2, svn
          extensions: mysqli
          coverage: none

      - name: Install Composer dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Install WordPress test suite
        run: bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 ${{ matrix.wp }}

      - name: Run integration tests
        run: composer test:integration
```

**Key decisions:**

- `ubuntu-24.04` — current LTS runner. Use explicit version not `ubuntu-latest` to avoid unexpected runner changes.
- `fail-fast: false` on both matrices — a PHP 8.4 unit test failure should not cancel the PHP 8.1 run. Same for integration matrix.
- Unit tests matrix: `['8.1', '8.2', '8.3', '8.4']` — all supported PHP versions. No MySQL needed.
- Integration tests matrix: `['8.1', '8.3']` — subset (integration tests are slower; 8.1 = floor, 8.3 = current). `'trunk'` in WP version covers WP 7.0 nightly.
- `mysql:8.0` service container — WordPress 6.2+ requires MySQL 5.7+; MySQL 8.0 is what managed hosts use.
- `127.0.0.1` in `install-wp-tests.sh` call — critical. `localhost` causes socket connection attempt which fails in GitHub Actions service containers.
- `tools: composer:v2, svn` — SVN is required by `install-wp-tests.sh` to export the WP test suite. Adding it to `setup-php` `tools:` is the standard CI approach.
- `extensions: mysqli` — required for WordPress's database connection in test environment.
- `env: WP_TESTS_DIR: /tmp/wordpress-tests-lib` — matches the default used by `install-wp-tests.sh` when `TMPDIR=/tmp`.
- No caching of the WordPress test library in Phase 1 — keep it simple. Caching can be added in a follow-up if CI becomes slow.
- WP `trunk` matrix entry: WP 7.0 is the target of this milestone. Running against trunk means integration tests will catch WP 7.0 regressions before GA (April 9, 2026). `fail-fast: false` means a trunk failure does not block the stable (latest) job.

---

## 3. Integration Points

### How new files connect to existing files

| New File | Connects To | Connection |
|----------|-------------|------------|
| `phpunit-integration.xml.dist` | `tests/integration/bootstrap.php` | `bootstrap=` attribute |
| `phpunit-integration.xml.dist` | `tests/integration/*.php` | `<directory suffix="Test.php">tests/integration</directory>` |
| `tests/integration/bootstrap.php` | `vendor/autoload.php` | `require_once` — gets Composer PSR-4 autoloading for `WP_Sudo\Tests\Integration\*` |
| `tests/integration/bootstrap.php` | `wp-sudo.php` | Via `tests_add_filter('muplugins_loaded', ...)` |
| `tests/integration/bootstrap.php` | `vendor/yoast/phpunit-polyfills/` | Via `WP_TESTS_PHPUNIT_POLYFILLS_PATH` constant |
| `tests/integration/bootstrap.php` | `$WP_TESTS_DIR/includes/bootstrap.php` | `require` — boots real WordPress |
| `tests/integration/TestCase.php` | `WP_UnitTestCase` | `extends \WP_UnitTestCase` |
| `composer.json` (updated scripts) | `phpunit.xml.dist` (existing) | `test:unit` → `phpunit --configuration phpunit.xml.dist` |
| `composer.json` (updated scripts) | `phpunit-integration.xml.dist` (new) | `test:integration` → `phpunit --configuration phpunit-integration.xml.dist` |
| `bin/install-wp-tests.sh` | MySQL service (local or CI) | Creates `wordpress_test` database |
| `bin/install-wp-tests.sh` | `$WP_TESTS_DIR` | Installs WP test library there |
| `.github/workflows/phpunit.yml` | `bin/install-wp-tests.sh` | Runs it as a step before `composer test:integration` |
| `.github/workflows/phpunit.yml` | `composer.json` scripts | Calls `composer test:unit` and `composer test:integration` |

### Autoload namespace

The `composer.json` `autoload-dev` PSR-4 section currently maps `"WP_Sudo\\Tests\\"` to `"tests/"`. The integration base class will be `WP_Sudo\Tests\Integration\TestCase` in `tests/integration/TestCase.php`. This maps correctly:
- Namespace: `WP_Sudo\Tests\Integration\TestCase`
- File: `tests/integration/TestCase.php`
- Autoload prefix: `WP_Sudo\Tests\` → `tests/`

No change to `composer.json` autoload section is needed. The existing PSR-4 mapping covers `tests/integration/` automatically.

### What must NOT be connected

- `tests/integration/bootstrap.php` must NOT `require` or `require_once` `tests/bootstrap.php`
- `tests/integration/bootstrap.php` must NOT `use Brain\Monkey` or any Brain\Monkey namespace
- `tests/integration/bootstrap.php` must NOT call `Monkey\setUp()` or `Monkey\tearDown()`
- `tests/integration/TestCase.php` must NOT `use MockeryPHPUnitIntegration`
- `phpunit-integration.xml.dist` must NOT reference `tests/Unit/` directory

---

## 4. Risk Factors

### Risk 1: `WP_TESTS_PHPUNIT_POLYFILLS_PATH` not set before WP bootstrap loads

**Probability:** HIGH if not explicitly handled
**Impact:** Fatal error — WP bootstrap defaults to `/tmp/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php` which does not exist

**Mitigation:** Define `WP_TESTS_PHPUNIT_POLYFILLS_PATH` BEFORE `require $tests_dir . '/includes/bootstrap.php'` in the integration bootstrap. The constant must be defined in the plugin's integration bootstrap, not assumed to exist from the environment.

**Detection:** If you see `require(): Failed opening required '/tmp/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php'` — this is the cause.

### Risk 2: `composer test` no longer auto-discovers unit config

**Probability:** LOW — current behavior is `phpunit` without `--configuration` which auto-discovers `phpunit.xml.dist`

**Impact:** If `phpunit-integration.xml.dist` or `phpunit-integration.xml` exists alongside `phpunit.xml.dist`, PHPUnit 9.6 may become confused about which config to use. PHPUnit's discovery prefers an explicit file over auto-discovery.

**Mitigation:** Verify `composer test` still runs unit tests only after adding `phpunit-integration.xml.dist`. PHPUnit 9.6 auto-discovers the first `phpunit.xml` or `phpunit.xml.dist` it finds — it does not run multiple. The `.dist` suffix file is lower priority than the non-dist version.

**Detection:** `composer test` output shows "Integration" suite or more than 220 tests running.

### Risk 3: GitHub Actions MySQL not ready when `install-wp-tests.sh` runs

**Probability:** MEDIUM — service containers can take 10–30 seconds to become healthy

**Impact:** `install-wp-tests.sh`'s `install_db()` runs `mysqladmin create` which fails if MySQL isn't listening yet

**Mitigation:** The `options: --health-cmd="mysqladmin ping" --health-interval=10s --health-retries=5` block in the service container config causes GitHub Actions to wait until MySQL is healthy before starting job steps. This is the standard pattern.

**Detection:** `mysqladmin: connect to server at '127.0.0.1' failed` in CI logs.

### Risk 4: `trunk` WP version causes non-deterministic test failures

**Probability:** MEDIUM — WP trunk is unstable by definition

**Impact:** Integration tests fail in CI due to WP core bugs/changes, not plugin bugs. Blocks PR merges.

**Mitigation:** Phase 1 has an empty test suite — this risk is deferred to later phases. When tests are written, consider `continue-on-error: true` for the `trunk` matrix entry.

### Risk 5: Missing `svn` in GitHub Actions PHP setup

**Probability:** LOW — `shivammathur/setup-php` includes SVN when added to `tools:`

**Impact:** `install-wp-tests.sh` fails with "Error: svn is not installed"

**Mitigation:** Explicitly include `svn` in `tools: composer:v2, svn` in the `setup-php` step. Already documented in the workflow above.

**Detection:** `Error: svn is not installed. Please install svn and try again.` in CI logs.

### Risk 6: Static cache not cleared between integration tests

**Probability:** MEDIUM for session/gate tests, LOW for Phase 1 (empty suite)

**Impact:** `Action_Registry`, `Sudo_Session`, and `Admin` have `reset_cache()` static methods called in the unit `TestCase::tearDown()`. If integration tests mutate these caches, later tests see stale state.

**Mitigation:** The integration `TestCase::tear_down()` does NOT currently call these static cache resets. Add them if tests become flaky. For Phase 1 (empty suite), this is a non-issue. Document as a known concern for Phase 2.

### Risk 7: `wp-sudo.php` activation hook name mismatch in `activate_plugin()`

**Probability:** LOW but verifiable
**Impact:** `do_action('activate_wp-sudo/wp-sudo.php')` fires nothing if the plugin basename differs

**Mitigation:** Verify the plugin basename by checking `plugin_basename(__FILE__)` in `wp-sudo.php`. The expected basename is `wp-sudo/wp-sudo.php` for a plugin installed as `wp-content/plugins/wp-sudo/wp-sudo.php`. In a test context where the plugin is loaded via `require`, `plugin_basename()` may return a different path. Phase 1 is an empty suite — `activate_plugin()` will be exercised in Phase 2. Document this as a verification step.

---

## 5. Verification Steps

### Verify A: Patchwork is not active in integration context

```bash
# Run integration suite (empty) and confirm no Patchwork-related output
WP_TESTS_DIR=/tmp/wordpress-tests-lib composer test:integration

# If Patchwork were active, it would log to stderr or produce warnings.
# An empty suite with no Brain\Monkey imports should produce:
# "OK (0 tests, 0 assertions)"
```

Also verify by inspection: search all files in `tests/integration/` for Brain\Monkey or Patchwork references:
```bash
grep -r "Brain\\\Monkey\|Patchwork\|Monkey\\\\setUp" tests/integration/
# Expected: no output
```

### Verify B: Unit tests unchanged

```bash
composer test:unit
# Expected: same result as before (220+ tests, 0 failures)

composer test
# Expected: same result as composer test:unit (auto-discovers phpunit.xml.dist)
```

Both must pass with the same count as before any Phase 1 changes.

### Verify C: Integration suite runs empty

After installing WP test library and creating test database:
```bash
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
composer test:integration
```

Expected output:
```
PHPUnit 9.6.x by Sebastian Bergmann and contributors.

Runtime: PHP 8.x.x

No tests executed!
# OR (if PHPUnit 9.6 shows it differently):
OK (0 tests, 0 assertions)
```

An empty test suite with no errors means the bootstrap loaded successfully, WordPress booted, and the plugin loaded via `muplugins_loaded`. A fatal error means the bootstrap has a configuration problem.

### Verify D: Integration bootstrap has correct structure

```bash
# WP_TESTS_PHPUNIT_POLYFILLS_PATH is defined before WP bootstrap
grep -n "WP_TESTS_PHPUNIT_POLYFILLS_PATH" tests/integration/bootstrap.php
# Expected: line number before the line that requires wp-tests-lib/includes/bootstrap.php

# No Brain\Monkey or Patchwork in integration bootstrap
grep -n "Brain\\\|Monkey\|Patchwork\|setUp\(\)" tests/integration/bootstrap.php
# Expected: no output

# No stubs from unit bootstrap
grep -n "class WP_User\|class Two_Factor\|ABSPATH.*fake" tests/integration/bootstrap.php
# Expected: no output
```

### Verify E: GitHub Actions passes both jobs

After pushing the branch with Phase 1 changes:
1. Unit Tests job: all 4 PHP versions pass, ~220 tests each
2. Integration Tests job: all 4 matrix entries (2 PHP × 2 WP versions) show "0 tests" without error

Any exit code 1 or 2 from PHPUnit in the integration job indicates a bootstrap failure, not a test failure.

### Verify F: Composer scripts are correct

```bash
# Confirm test:unit and test:integration are registered
composer run --list | grep "test"
# Expected: test, test:unit, test:integration

# Confirm test:integration references the integration config
composer run test:integration -- --version
# Expected: PHPUnit 9.6.x (not an error)
```

---

## Code Examples

### Verified: `WP_UnitTestCase::set_up()` / `tear_down()` method names

Source: `wordpress-develop/tests/phpunit/includes/abstract-testcase.php` (fetched 2026-02-19)

```php
// WP_UnitTestCase uses snake_case method names (PHPUnit 9.6 polyfill bridges these)
class WP_UnitTestCase_Base extends PHPUnit_Adapter_TestCase {
    // These are the correct method names for subclasses to override:
    public function set_up() { ... }       // Called before each test
    public function tear_down() { ... }    // Called after each test
    public static function set_up_before_class() { ... }
    public static function tear_down_after_class() { ... }
}

// Our TestCase extension uses the same convention:
class TestCase extends \WP_UnitTestCase {
    // If we need to override setUp behavior, use set_up() not setUp()
    public function set_up(): void {
        parent::set_up(); // Always call parent
        // ... custom setup
    }
}
```

### Verified: factory() usage pattern

Source: `wordpress-develop/tests/phpunit/includes/factory/class-wp-unittest-factory.php` (fetched 2026-02-19)

```php
// Create a user with factory (auto-cleaned up by WP_UnitTestCase::tear_down)
$user_id = self::factory()->user->create([
    'role'      => 'administrator',
    'user_pass' => 'test-password',  // Hashed via wp_hash_password() at cost=5
]);

// Create a post
$post_id = self::factory()->post->create([
    'post_status' => 'publish',
    'post_author' => $user_id,
]);

// Available factory sub-objects: user, post, comment, term, blog, network
```

### Verified: Patchwork activation chain

Source: `vendor/brain/monkey/src/Container.php` + `vendor/brain/monkey/inc/patchwork-loader.php` (local codebase, 2026-02-19)

```php
// Patchwork is loaded ONLY when Brain\Monkey\Container::instance() is called.
// Container::instance() is called by Brain\Monkey\setUp().
// Integration bootstrap never calls Brain\Monkey\setUp().
// Therefore: Patchwork is never loaded in the integration process.

// Evidence — patchwork-loader.php:
if (function_exists('Patchwork\redefine')) {
    return; // Already loaded (idempotent guard)
}
// Loads from: vendor/antecedent/patchwork/Patchwork.php
// patchwork.json is read by Patchwork.php when it loads.
// This file is never executed in the integration test process.
```

### Verified: install-wp-tests.sh argument order

Source: `wp-cli/scaffold-command/templates/install-wp-tests.sh` (fetched 2026-02-19)

```bash
# CI usage (127.0.0.1 for TCP connection, not Unix socket):
bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest
bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 trunk

# Local usage (localhost works if MySQL socket is available):
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest

# Environment variable defaults:
# WP_TESTS_DIR defaults to $TMPDIR/wordpress-tests-lib (usually /tmp/wordpress-tests-lib)
# WP_CORE_DIR defaults to $TMPDIR/wordpress (usually /tmp/wordpress)
# These match the CI workflow's env: WP_TESTS_DIR: /tmp/wordpress-tests-lib
```

---

## Open Questions

1. **Does `activate_plugin()` helper need the real plugin basename?**
   - What we know: `register_activation_hook()` in `wp-sudo.php` uses `__FILE__` to derive the plugin file path. In a test environment where the plugin is loaded via `require_once` rather than via the plugins directory, `plugin_basename()` may return an unexpected value.
   - What's unclear: Whether `do_action('activate_wp-sudo/wp-sudo.php')` is the correct hook name in the test context.
   - Recommendation: Defer resolution to Phase 2 when `activate_plugin()` is first called. Verify by checking the registered action hook names after plugin load: `var_dump(has_action('activate_wp-sudo/wp-sudo.php'))`.

2. **Should the integration job cache the WP test library?**
   - What we know: `install-wp-tests.sh` downloads WordPress and SVN-exports the test library on every CI run. This adds 20–40 seconds per matrix entry.
   - What's unclear: Whether the GitHub Actions cache key would be stable enough to be worth implementing.
   - Recommendation: Skip caching in Phase 1 (empty suite, speed doesn't matter). Add `actions/cache@v4` for `/tmp/wordpress-tests-lib` in Phase 2 when tests actually run.

3. **Should the `trunk` integration matrix entry be non-blocking?**
   - What we know: WP trunk is unstable; WordPress 7.0 is not yet released (April 9, 2026). Trunk test failures could be WP core regressions, not plugin bugs.
   - What's unclear: The team's preference for blocking vs. non-blocking trunk failures.
   - Recommendation: In Phase 1 with an empty suite, trunk cannot fail due to test logic. If/when trunk produces bootstrap failures, add `continue-on-error: true` to the trunk matrix entry in Phase 2.

---

## Sources

### Primary (HIGH confidence — verified against live source)

- **WP Sudo `composer.json`** (local, 2026-02-19) — exact current scripts, require-dev, autoload configuration
- **WP Sudo `phpunit.xml.dist`** (local, 2026-02-19) — exact PHPUnit 9.6 schema, existing flags, bootstrap path
- **WP Sudo `patchwork.json`** (local, 2026-02-19) — confirms which functions are redefinable
- **WP Sudo `tests/bootstrap.php`** (local, 2026-02-19) — exact stub classes, constants, and load order
- **WP Sudo `tests/TestCase.php`** (local, 2026-02-19) — exact Brain\Monkey setup/teardown pattern
- **WP Sudo `vendor/brain/monkey/src/Container.php`** (local, 2026-02-19) — confirms lazy Patchwork loading via `Container::instance()`
- **WP Sudo `vendor/brain/monkey/inc/patchwork-loader.php`** (local, 2026-02-19) — confirms Patchwork is NOT autoloaded; only loaded when Brain\Monkey container initializes
- **WP Sudo `vendor/antecedent/patchwork/composer.json`** (local, 2026-02-19) — confirms Patchwork has no `autoload.files` entry; does not self-load
- **`wp-cli/scaffold-command` install-wp-tests.sh** (fetched from GitHub 2026-02-19) — complete script content including argument order, `check_svn_installed`, `install_wp`, `install_test_suite`, `install_db`, Darwin/Linux sed differences
- **`wordpress-develop/tests/phpunit/includes/bootstrap.php`** (fetched 2026-02-19) — `WP_TESTS_PHPUNIT_POLYFILLS_PATH` handling code (lines 73-88), default polyfills path resolution, require sequence
- **`wordpress-develop/tests/phpunit/includes/abstract-testcase.php`** (fetched 2026-02-19) — `set_up()`/`tear_down()` method names (snake_case), factory property type, no built-in `make_admin()`

### Secondary (HIGH confidence — verified against official source)

- **Existing `.planning/research/STACK.md`** (2026-02-19) — consensus decisions from milestone research, version compatibility table
- **Existing `.planning/research/ARCHITECTURE.md`** (2026-02-19) — directory structure, bootstrap patterns, CI pipeline structure
- **Existing `.planning/research/PITFALLS.md`** (2026-02-19) — Pitfalls 1–4 directly applicable to Phase 1

---

## Metadata

**Confidence breakdown:**
- Current state (what exists): HIGH — read from live files
- `install-wp-tests.sh` content: HIGH — fetched from GitHub raw
- Bootstrap patterns: HIGH — verified against wordpress-develop trunk
- Patchwork isolation: HIGH — traced through actual vendor source code
- GitHub Actions workflow: HIGH — standard documented pattern
- `WP_TESTS_PHPUNIT_POLYFILLS_PATH` requirement: HIGH — verified from WP bootstrap source

**Research date:** 2026-02-19
**Valid until:** 2026-03-21 (30 days for the stable tools; `trunk` content may change faster)
