# Plan 03-02 Summary: MU Loader Resilience

## Objective

Harden MU loader startup so activation/path resolution does not rely on a single hardcoded slug/path and remains diagnosable when unresolved.

## Changes

### Production

- **`mu-plugin/wp-sudo-loader.php`**
  - Added basename-candidate builder and resolver helpers.
  - Added active-plugin matcher that supports:
    - exact configured basename(s),
    - loader-derived basename,
    - canonical fallback (`wp-sudo/wp-sudo.php`),
    - non-canonical `*/wp-sudo.php` active entries.
  - Added plugin-file candidate resolution:
    - `WP_SUDO_PLUGIN_DIR` path (when available),
    - loader-relative plugin root path,
    - canonical content/plugins fallback.
  - Added explicit unresolved-path diagnostic action:
    - `wp_sudo_mu_loader_unresolved_plugin_path`.
  - Preserved inert behavior when no active plugin match exists.
  - Preserved early `register_early()` hook registration behavior.

### Tests

- **`tests/Unit/PluginTest.php`**
  - Added MU-loader tests for:
    - active plugin match via configured basename,
    - fallback candidate generation when configured basename is absent,
    - non-canonical active plugin slug recognition (`*/wp-sudo.php`),
    - inert behavior with no active plugin match,
    - explicit unresolved-path diagnostic signal.

### Manual Verification

- **`tests/MANUAL-TESTING.md`**
  - Added section **9.6 MU Loader Resilience** covering:
    - canonical path behavior,
    - renamed plugin-directory behavior,
    - inactive-plugin + shim behavior,
    - unresolved-path diagnostic signal verification.

## Verification Results

- ✅ `vendor/bin/phpunit --configuration phpunit.xml.dist --do-not-cache-result tests/Unit/PluginTest.php --filter test_mu_loader`
  - Passed (`5 tests`, `7 assertions`).
- ✅ `php -l mu-plugin/wp-sudo-loader.php tests/Unit/PluginTest.php`
  - No syntax errors.

## Tooling Note

- `composer analyse:phpstan` remains intermittently stalled in this environment during this phase; unit verification for touched scope is green.
