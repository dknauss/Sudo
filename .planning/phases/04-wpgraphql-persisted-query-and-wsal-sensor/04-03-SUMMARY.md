# Plan 04-03 Summary: Integration + Docs + Manual Alignment

## Objective

Finalize Phase 4 contracts across integration coverage, docs, manual test guidance, and roadmap state.

## Changes

### Integration Coverage

- **`tests/Integration/WpGraphQLGatingTest.php`**
  - Added persisted-query mutation classification scenario (Limited mode blocked).
  - Added persisted-query query classification scenario (Limited mode pass-through).

### Documentation

- **`docs/developer-reference.md`**
  - Added persisted-query classifier contract (`wp_sudo_wpgraphql_classification`).
  - Updated persisted-query guidance to classifier-first model with secure fallback.
  - Added optional WSAL bridge section with hook→event ID mapping.
  - Added Stream parity note (planned next, WSAL-first delivery).
- **`docs/security-model.md`**
  - Updated persisted-query section to reference classifier filter path and fallback posture.
  - Added MU loader path-resolution diagnostics note.
- **`tests/MANUAL-TESTING.md`**
  - Added persisted-query classifier validation checklist (`16.6`).
  - Added WSAL bridge validation checklist (`19.6`).
- **`.planning/ROADMAP.md`**
  - Marked `04-01` and `04-02` complete.

## Verification Results

- ✅ `vendor/bin/phpunit --configuration phpunit.xml.dist --do-not-cache-result tests/Unit/GateTest.php --filter "test_check_wpgraphql|test_match_request_matches_builtin_rule_with_malformed_custom_rule_present"`
  - Passed (`5 tests`, `9 assertions`).
- ✅ `vendor/bin/phpunit --configuration phpunit.xml.dist --do-not-cache-result tests/Unit/WsalSensorBridgeTest.php`
  - Passed (`4 tests`, `22 assertions`).
- ✅ `vendor/bin/phpunit --configuration phpunit.xml.dist --do-not-cache-result tests/Unit/ActionRegistryTest.php`
  - Passed (`48 tests`, `221 assertions`).
- ✅ `vendor/bin/phpunit --configuration phpunit.xml.dist --do-not-cache-result tests/Unit/PluginTest.php --filter test_mu_loader`
  - Passed (`5 tests`, `7 assertions`).

## Blockers / Environment Notes

- ⛔ Integration tests are blocked by local DB auth in this environment:
  - `composer test:integration -- tests/Integration/ActionRegistryTest.php --do-not-cache-result`
  - `composer test:integration -- tests/Integration/WpGraphQLGatingTest.php --do-not-cache-result`
  - Failure: `Access denied for user 'root'@'localhost' (using password: NO)`.
- ⛔ Full static/lint gates (`composer analyse:phpstan`, `composer lint`) remain intermittently stalled/timed out in this runner.
