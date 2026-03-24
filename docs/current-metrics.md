# Current Metrics (Canonical)

This file is the single source of truth for current repository counts.

Last verified: 2026-03-23
Verification environment: primary local repo checkout at `/Users/danknauss/Developer/GitHub/wp-sudo` (mirrored checkouts may differ)

## Test Metrics

| Metric | Value | Verification |
|---|---:|---|
| Unit tests | 514 tests | `composer test:unit` |
| Unit assertions | 1348 assertions | `composer test:unit` |
| Integration tests in suite | 139 test methods | `rg -c "function test" tests/Integration/*.php | awk -F: '{sum+=$2} END{print sum}'` |
| Unit test files | 20 | `ls tests/Unit/*.php | wc -l` |
| Integration test files | 20 | `ls tests/Integration/*.php | wc -l` |

## Size Metrics

| Metric | Value | Verification |
|---|---:|---|
| Production PHP lines (`includes/`, `wp-sudo.php`, `uninstall.php`, `mu-plugin/`, `bridges/`) | 8,963 | `find ./includes ./wp-sudo.php ./uninstall.php ./mu-plugin ./bridges -type f -name "*.php" -print0 | xargs -0 wc -l | tail -1` |
| Tests PHP lines (`tests/`) | 17,307 | `find ./tests -type f -name "*.php" -print0 | xargs -0 wc -l | tail -1` |
| Production + tests PHP lines | 26,270 | sum of the two rows above |
| Test-to-production ratio | 1.93:1 | `17307 / 8963` |
| Total repo PHP lines (excluding `vendor/`, `vendor_test/`, `.tmp/`, `.git/`) | 26,327 | `find . -type f -name "*.php" ! -path "*/vendor/*" ! -path "*/vendor_test/*" ! -path "*/.tmp/*" ! -path "*/.git/*" -print0 | xargs -0 wc -l | tail -1` |

## Architectural Facts

Volatile counts that change when features ship. Every doc referencing these
numbers MUST point to or be verified against this table — never hardcode
the count in prose without a verification command.

| Fact | Value | Verification | Last changed |
|---|---:|---|---|
| Request surfaces | 7 | `grep -c "const SURFACE_" includes/class-gate.php` | v2.5.0 (WPGraphQL) |
| Gated rules (single-site) | 23 | `grep "'id'" includes/class-action-registry.php \| grep -v network \| grep -v "rule\[" \| wc -l` | v2.10.2 |
| Gated rules (multisite) | 9 | `grep "'id'" includes/class-action-registry.php \| grep -c "network"` | v2.0.0 |
| Gated rules (total) | 32 | `grep "'id'" includes/class-action-registry.php \| grep -v "rule\[" \| wc -l` | v2.10.2 |
| Help tabs | 10 | `grep -c "add_help_tab" includes/class-admin.php` | v2.4.0 |
| Audit hooks | 9 | `grep -c "do_action.*wp_sudo_" includes/class-*.php \| awk -F: '{sum+=$2} END{print sum}'` | v2.11.0 |
| Settings fields (base) | 5 | 1 numeric (duration) + 4 policy dropdowns (REST, CLI, Cron, XML-RPC) | v2.0.0 |
| Settings fields (with WPGraphQL) | 6 | +1 conditional WPGraphQL policy dropdown | v2.5.0 |
| E2E tests | 54 | `npx playwright test --config tests/e2e/playwright.config.ts --list` | unreleased |

### Files that reference these counts

When any fact above changes, update this table first, then grep for the old
value across these known consumers:

- `readme.md`, `readme.txt` — plugin description
- `CLAUDE.md` — project overview
- `docs/abilities-api-assessment.md` — Gate surfaces table
- `docs/ui-ux-testing-prompts.md` — settings page field count
- `docs/developer-reference.md` — hook signatures, audit hooks
- `tests/MANUAL-TESTING.md` — gated rules count
- `.planning/PROJECT.md` — project summary
- `docs/ROADMAP.md` — unit test coverage notes

## CI Matrix Snapshot

Source: `.github/workflows/phpunit.yml`, `.github/workflows/e2e.yml`, `.github/workflows/e2e-nginx.yml`, `.github/workflows/e2e-sqlite.yml`, `.github/workflows/compat-wordpress-minors.yml`

- Unit test matrix: PHP 8.0, 8.1, 8.2, 8.3, 8.4
- Integration matrix: PHP 8.0, 8.1, 8.3; WordPress 6.2, 6.7, 7.0-beta4; MySQL 8.0 plus one MariaDB LTS lane; multisite true/false on the main MySQL lanes
- Scheduled compat sweep: PHP 8.1 on WordPress 6.3, 6.4, 6.5, and 6.6, plus MariaDB LTS overlap lanes on WordPress 6.4 and 6.5
- Browser stack smoke workflows: Apache + MariaDB (`wp-env`), nginx + php-fpm + MariaDB, and Playground SQLite

## Verification Notes

- `composer test:unit` passed on 2026-03-24 (`514 tests`, `1348 assertions`).
- `composer test:integration` passed on 2026-03-24 (`144 tests`, `444 assertions`, `8 skipped`) using the repo-local WordPress test install and a reachable MariaDB-backed `wordpress_test` database.
- `WP_MULTISITE=1 composer test:integration` passed on 2026-03-24 (`144 tests`, `452 assertions`, `2 skipped`) using the same MariaDB-backed test database.
- `composer analyse:phpstan`, `composer analyse:psalm`, and `composer lint` passed on 2026-03-24.

## Update Procedure

1. Re-run all verification commands listed above.
2. Update this file first.
3. Run `composer verify:metrics` to confirm the document matches live counts.
4. Keep other docs referencing this file instead of duplicating current counts.
