# Plan 05-03 Summary: Docs + Full Gate Closure

## Objective

Align developer/security/manual documentation with shipped v2.13 behavior and close full pre-PR quality gates.

## Changes

### Documentation

- **`docs/developer-reference.md`**
  - Updated `wp_sudo_lockout` signature to include additive third arg `string $ip`.
  - Added backward-compat note for existing 2-argument callbacks.
  - Updated Stream bridge args/meta list to include `ip`.

- **`docs/security-model.md`**
  - Expanded transient section to include per-IP failure and lockout transient state.
  - Added risk/mitigation notes for IP lockout transient eviction/stale reads.
  - Updated cache failure-mode summary table with IP lockout transient behavior.

- **`tests/MANUAL-TESTING.md`**
  - Added section `1.5.1` for IP + user combined policy validation (same-IP, cross-user threshold and post-lockout behavior).

### Planning / Baseline Hygiene

- **`.planning/ROADMAP.md`**
  - Marked Phase 5 plans (`05-01`, `05-02`, `05-03`) complete.
  - Updated status to complete for all five phases.

- **`psalm-baseline.xml`**
  - Removed stale baseline entry no longer applicable after v2.13 code changes.

## Full Gate Results

- ✅ `composer test:unit`
  - Passed (`496 tests`, `1293 assertions`).
- ✅ `WP_TESTS_DIR="$PWD/.tmp/wordpress-tests-lib" WP_CORE_DIR="$PWD/.tmp/wordpress" composer test:integration`
  - Passed (`137 tests`, `430 assertions`, `Skipped: 8`).
- ✅ `WP_TESTS_DIR="$PWD/.tmp/wordpress-tests-lib" WP_CORE_DIR="$PWD/.tmp/wordpress" WP_MULTISITE=1 composer test:integration`
  - Passed (`137 tests`, `438 assertions`, `Skipped: 2`).
- ✅ `composer analyse:phpstan`
  - Passed (0 errors).
- ✅ `composer analyse:psalm`
  - Passed (0 errors).
- ✅ `composer lint`
  - Passed (PHPCS clean; only upstream JS-sniff deprecation notices).
