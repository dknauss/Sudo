# Plan 03-01 Summary: Rule Schema Validation Hardening

## Objective

Harden `Action_Registry::get_rules()` so malformed `wp_sudo_gated_actions` output cannot destabilize runtime matching.

## Changes

### Tests (RED first, then GREEN)

- **`tests/Unit/ActionRegistryTest.php`**
  - Added non-array filter payload fallback coverage.
  - Added invalid metadata drop coverage (`id`/`label`/`category` requirements).
  - Added invalid surface-shape drop coverage (`admin`/`ajax`/`rest` must be array-or-null).
  - Added mixed valid+invalid filtered set coverage (valid preserved, invalid dropped).
- **`tests/Unit/GateTest.php`**
  - Added regression test that built-in matching remains functional when malformed custom rules are present in filtered output.

### Production

- **`includes/class-action-registry.php`**
  - `get_rules()` now normalizes filtered output before caching.
  - Added fail-closed normalization pipeline:
    - non-array filter output falls back to built-in rules.
    - non-array rule entries are dropped.
    - rules missing required scalar metadata are dropped.
    - rules with invalid `admin`/`ajax`/`rest` surface shapes are dropped.
  - Normalized metadata to strings for matcher consistency.

## Verification Results

- ✅ `composer test:unit -- tests/Unit/ActionRegistryTest.php --do-not-cache-result`
  - Passed (`48 tests`, `221 assertions`).
- ✅ `composer test:unit -- tests/Unit/GateTest.php --filter "match_request|action_registry" --do-not-cache-result`
  - Passed (`56 tests`, `95 assertions`).
- ✅ `php -l includes/class-action-registry.php tests/Unit/ActionRegistryTest.php tests/Unit/GateTest.php`
  - No syntax errors.

## Tooling Note

- `composer analyse:phpstan` repeatedly stalled/timed out in this environment during this phase, including isolated direct invocations. Unit gates for changed scope are green; rerun full static analysis once runner process state is clean.
