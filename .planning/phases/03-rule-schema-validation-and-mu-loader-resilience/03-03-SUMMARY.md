# Plan 03-03 Summary: Integration + Docs + Gate Closure

## Objective

Finalize Phase 3 with integration coverage for malformed filtered rules and align developer/security/roadmap documentation with shipped contracts.

## Changes

### Integration Tests

- **`tests/Integration/ActionRegistryTest.php`**
  - Added mixed valid+invalid custom rule coverage (valid custom still matches).
  - Added malformed custom surface-shape coverage (built-in matching still works).
  - Added non-array `wp_sudo_gated_actions` payload coverage (safe fallback to built-ins).

### Documentation

- **`docs/developer-reference.md`**
  - Added explicit filtered-rule normalization contract for `wp_sudo_gated_actions`.
  - Documented fail-closed per-rule drop behavior and non-array fallback.
  - Added MU-loader diagnostics hook docs:
    - `wp_sudo_mu_loader_unresolved_plugin_path`.
- **`docs/security-model.md`**
  - Added MU-loader path-resolution and diagnostics note in environmental considerations.
- **`ROADMAP.md`**
  - Updated P2 entries (rule-schema validation and MU-loader resilience) to shipped/complete language with implemented fix/test details.
- **`.planning/ROADMAP.md`**
  - Marked Phase 3 checkboxes (`03-01`, `03-02`, `03-03`) complete.

## Verification Results

- ✅ `vendor/bin/phpunit --configuration phpunit.xml.dist --do-not-cache-result tests/Unit/ActionRegistryTest.php`
  - Passed (`48 tests`, `221 assertions`).
- ✅ `vendor/bin/phpunit --configuration phpunit.xml.dist --do-not-cache-result tests/Unit/PluginTest.php --filter test_mu_loader`
  - Passed (`5 tests`, `7 assertions`).
- ✅ `vendor/bin/phpunit --configuration phpunit.xml.dist --do-not-cache-result tests/Unit/GateTest.php --filter test_match_request_matches_builtin_rule_with_malformed_custom_rule_present`
  - Passed (`1 test`, `2 assertions`).

## Final Closure Verification

- ✅ Full quality gates were completed on `main` during release-readiness:
  - `composer test:unit`
  - `composer test:integration` (single-site)
  - `WP_MULTISITE=1 composer test:integration`
  - `composer analyse:phpstan`
  - `composer analyse:psalm`
  - `composer lint`
