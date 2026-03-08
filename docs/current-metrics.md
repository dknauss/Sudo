# Current Metrics (Canonical)

This file is the single source of truth for current repository counts.

Last verified: 2026-03-08
Verification environment: local repo checkout at `/Users/danknauss/Documents/GitHub/wp-sudo`

## Test Metrics

| Metric | Value | Verification |
|---|---:|---|
| Unit tests | 496 tests | `composer test:unit` |
| Unit assertions | 1293 assertions | `composer test:unit` |
| Integration tests in suite | 132 test methods | `rg -c "function test" tests/Integration/*.php | awk -F: '{sum+=$2} END{print sum}'` |
| Unit test files | 18 | `ls tests/Unit/*.php | wc -l` |
| Integration test files | 18 | `ls tests/Integration/*.php | wc -l` |

## Size Metrics

| Metric | Value | Verification |
|---|---:|---|
| Production PHP lines (`includes/`, `wp-sudo.php`, `uninstall.php`, `mu-plugin/`, `bridges/`) | 8,652 | `find ./includes ./wp-sudo.php ./uninstall.php ./mu-plugin ./bridges -type f -name "*.php" -print0 | xargs -0 wc -l | tail -1` |
| Tests PHP lines (`tests/`) | 16,207 | `find ./tests -type f -name "*.php" -print0 | xargs -0 wc -l | tail -1` |
| Production + tests PHP lines | 24,859 | sum of the two rows above |
| Test-to-production ratio | 1.87:1 | `16207 / 8652` |
| Total repo PHP lines (excluding `vendor/`, `vendor_test/`, `.tmp/`, `.git/`) | 24,916 | `find . -type f -name "*.php" ! -path "*/vendor/*" ! -path "*/vendor_test/*" ! -path "*/.tmp/*" ! -path "*/.git/*" -print0 | xargs -0 wc -l | tail -1` |

## CI Matrix Snapshot

Source: `.github/workflows/phpunit.yml`

- Unit test matrix: PHP 8.1, 8.2, 8.3, 8.4
- Integration matrix: PHP 8.1 and 8.3; WordPress 6.7, latest, trunk; multisite true/false

## Verification Notes

- `composer test:unit` passed on 2026-03-08.
- `composer test:integration` passed on 2026-03-08 (`137 tests`, `430 assertions`, `8 skipped`) after resetting the local test database (`wordpress_test`).
- `WP_MULTISITE=1 composer test:integration` passed on 2026-03-08 (`137 tests`, `438 assertions`, `2 skipped`).
- `composer analyse:phpstan`, `composer analyse:psalm`, and `composer lint` passed on 2026-03-08.

## Update Procedure

1. Re-run all verification commands listed above.
2. Update this file first.
3. Run `composer verify:metrics` to confirm the document matches live counts.
4. Keep other docs referencing this file instead of duplicating current counts.
