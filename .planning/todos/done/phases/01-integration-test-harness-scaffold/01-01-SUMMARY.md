---
phase: 01-integration-test-harness-scaffold
plan: 01
subsystem: testing
tags: [phpunit, wordpress-tests-lib, phpunit-polyfills, github-actions, mysql, integration-tests]

# Dependency graph
requires: []
provides:
  - PHPUnit integration test suite separate from Brain\Monkey unit tests
  - composer test:unit and test:integration scripts
  - WordPress real-DB test environment bootstrap (tests/integration/bootstrap.php)
  - Base TestCase extending WP_UnitTestCase with make_admin() and activate_plugin()
  - bin/install-wp-tests.sh for local and CI test suite setup
  - GitHub Actions CI workflow running unit (PHP 8.1-8.4) and integration (PHP 8.1/8.3 x WP latest/trunk)
affects: [02-core-security-integration-tests, 03-surface-coverage-tests, 04-advanced-coverage, 05-wp70-readiness]

# Tech tracking
tech-stack:
  added: [yoast/phpunit-polyfills ^2.0]
  patterns:
    - Integration tests in tests/integration/ directory, separate from tests/Unit/
    - WP_TESTS_PHPUNIT_POLYFILLS_PATH defined before WordPress bootstrap loads
    - Plugin loaded via muplugins_loaded hook in bootstrap (not activate hook)
    - Test isolation via WP_UnitTestCase transaction rollback per test

key-files:
  created:
    - phpunit-integration.xml.dist
    - tests/integration/bootstrap.php
    - tests/integration/TestCase.php
    - bin/install-wp-tests.sh
    - .github/workflows/phpunit.yml
  modified:
    - composer.json
    - composer.lock

key-decisions:
  - "yoast/phpunit-polyfills ^2.0 chosen for WP test bootstrap compatibility"
  - "WP_TESTS_PHPUNIT_POLYFILLS_PATH defined before require bootstrap.php to prevent /tmp/vendor/ resolution failure"
  - "muplugins_loaded used to load plugin (not plugins_loaded) as earliest safe hook for add_action() calls"
  - "tests/integration/bootstrap.php must NOT require tests/bootstrap.php to avoid ABSPATH/class-stub conflicts"
  - "beStrictAboutOutputDuringTests and failOnWarning omitted from integration config (WP core emits output/warnings during bootstrap)"
  - "127.0.0.1 not localhost in CI MySQL connection to force TCP (Unix socket fails in GitHub Actions service containers)"
  - "ubuntu-24.04 explicit (not ubuntu-latest) for stable CI runner"
  - "Integration test matrix: PHP 8.1+8.3 only (subset for speed); unit tests run 8.1+8.2+8.3+8.4"

patterns-established:
  - "Integration test files: tests/integration/*Test.php, namespace WP_Sudo\\Tests\\Integration"
  - "Base class: WP_Sudo\\Tests\\Integration\\TestCase extends WP_UnitTestCase"
  - "No Brain\\Monkey, Patchwork, or Mockery in integration files"

# Metrics
duration: 3min
completed: 2026-02-19
---

# Phase 1 Plan 1: Integration Test Harness Scaffold Summary

**PHPUnit integration test harness with real WordPress+MySQL environment, separate from the 343 Brain\Monkey unit tests, with GitHub Actions CI running both suites across PHP 8.1-8.4**

## Performance

- **Duration:** ~3 min
- **Started:** 2026-02-19T11:34:25Z
- **Completed:** 2026-02-19T11:37:07Z
- **Tasks:** 5 (4 with commits, 1 verification-only)
- **Files modified:** 7

## Accomplishments
- Added yoast/phpunit-polyfills ^2.0 and new composer test:unit / test:integration scripts while preserving backward-compatible composer test
- Created complete integration test infrastructure: PHPUnit config, bootstrap, base TestCase, and install script — all ready for Phase 2 tests to use
- GitHub Actions CI workflow runs unit tests on PHP 8.1/8.2/8.3/8.4 and integration tests on PHP 8.1/8.3 against WP latest and trunk
- All 343 existing unit tests continue passing with zero regressions

## Task Commits

Each task was committed atomically:

1. **Task 1: Add yoast/phpunit-polyfills and Composer scripts** - `718e14c` (chore)
2. **Task 2: Create integration PHPUnit config, bootstrap, and base TestCase** - `aa1c837` (feat)
3. **Task 3: Add bin/install-wp-tests.sh from canonical source** - `7d89c85` (chore)
4. **Task 4: Create GitHub Actions CI workflow** - `324ba1c` (feat)
5. **Task 5: Verify unit test regression** - no commit (verification only, no file changes)

## Files Created/Modified
- `composer.json` - Added yoast/phpunit-polyfills to require-dev; added test:unit and test:integration scripts
- `composer.lock` - Updated lock file (auto-generated)
- `phpunit-integration.xml.dist` - PHPUnit config for integration suite; bootstraps tests/integration/bootstrap.php; omits strict output/warning flags incompatible with WordPress core
- `tests/integration/bootstrap.php` - Loads real WordPress test environment; defines WP_TESTS_PHPUNIT_POLYFILLS_PATH; loads plugin at muplugins_loaded; does not reference Brain\Monkey or unit bootstrap
- `tests/integration/TestCase.php` - Base class extending WP_UnitTestCase with make_admin() and activate_plugin() helpers
- `bin/install-wp-tests.sh` - Canonical wp-cli/scaffold-command script for installing WordPress test suite (unmodified)
- `.github/workflows/phpunit.yml` - CI workflow with unit (4 PHP versions) and integration (2 PHP x 2 WP) jobs

## Decisions Made
- WP_TESTS_PHPUNIT_POLYFILLS_PATH must be defined before the WP bootstrap is required — the WP bootstrap reads it on load and defaults to /tmp/vendor/ otherwise
- Plugin loaded at muplugins_loaded (not plugins_loaded) because wp-sudo.php calls add_action() which requires WordPress core functions to exist
- Integration bootstrap must NOT require tests/bootstrap.php — that file defines ABSPATH and class stubs (WP_User, WP_Role) that conflict with real WordPress
- beStrictAboutOutputDuringTests, failOnWarning, failOnRisky omitted from integration config — WordPress core emits output and deprecation warnings during bootstrap that would fail the entire run before any test executes
- 127.0.0.1 used in CI MySQL connection string (not localhost) — localhost triggers Unix socket which fails in GitHub Actions service containers

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
None.

## User Setup Required
None - no external service configuration required. The integration tests require MySQL (provided by CI) or a local install running `bash bin/install-wp-tests.sh` before `composer test:integration` can be used locally.

## Next Phase Readiness
- Full integration test harness in place; Phase 2 can immediately write WP_UnitTestCase-based tests extending tests/integration/TestCase.php
- bin/install-wp-tests.sh documents the local setup command (see bootstrap.php error message)
- CI will run the empty integration suite on push/PR — confirms harness works before any integration tests are written

---
*Phase: 01-integration-test-harness-scaffold*
*Completed: 2026-02-19*

## Self-Check: PASSED

All 7 required files confirmed present on disk. All 4 task commits confirmed in git log.
