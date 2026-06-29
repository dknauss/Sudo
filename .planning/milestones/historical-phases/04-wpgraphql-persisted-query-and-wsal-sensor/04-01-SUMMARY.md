# Plan 04-01 Summary: WPGraphQL Persisted-Query Classification

## Objective

Add an explicit persisted-query classification extension point while preserving existing WPGraphQL policy flow and fallback behavior.

## Changes

### Production

- **`includes/class-gate.php`**
  - Added `is_wpgraphql_mutation()` helper.
  - Added new classifier filter:
    - `wp_sudo_wpgraphql_classification`
    - Return `'mutation'` or `'query'`; any other return falls back to legacy body heuristic (`str_contains(..., 'mutation')`).
  - Updated `check_wpgraphql()` to use classifier-aware mutation detection in:
    - Unrestricted mutation audit path.
    - Limited mutation block/pass path.
  - Preserved Disabled/Limited/Unrestricted sequencing and existing bypass behavior.

### Tests

- **`tests/Unit/GateTest.php`**
  - Added classifier-forced mutation block test.
  - Added classifier-forced query pass-through test.
  - Added unknown-classifier fallback-to-heuristic test.
  - Added bypass-preservation test when classifier is present.
- **`tests/Integration/WpGraphQLGatingTest.php`**
  - Added persisted-query mutation classification integration assertion.
  - Added persisted-query query classification integration assertion.

### Docs

- **`docs/developer-reference.md`**
  - Added `wp_sudo_wpgraphql_classification` filter documentation and updated persisted-query guidance.

## Verification Results

- ✅ `vendor/bin/phpunit --configuration phpunit.xml.dist --do-not-cache-result tests/Unit/GateTest.php --filter test_check_wpgraphql`
  - Passed (`4 tests`, `7 assertions`).
- ✅ `php -l includes/class-gate.php tests/Integration/WpGraphQLGatingTest.php`
  - No syntax errors.

## Blockers / Environment Notes

- ⛔ Integration DB unavailable in this environment:
  - `composer test:integration -- tests/Integration/WpGraphQLGatingTest.php --do-not-cache-result`
  - Fails with MySQL auth error (`Access denied for user 'root'@'localhost'`).
- ⛔ `composer analyse:phpstan` remains stalled/timing out in this runner.
