# Current Metrics (Canonical)

This file is the single source of truth for current repository counts.

Last verified: 2026-04-19
Verification environment: primary local repo checkout at `/Users/danknauss/Developer/GitHub/wp-sudo` (mirrored checkouts may differ)

## Test Metrics

| Metric | Value | Verification |
|---|---:|---|
| Unit tests | 561 tests | `composer test:unit` |
| Unit assertions | 1525 assertions | `composer test:unit` |
| Integration tests in suite | 145 test methods | `rg -c "function test" tests/Integration/*.php | awk -F: '{sum+=$2} END{print sum}'` |
| Unit test files | 20 | `ls tests/Unit/*.php | wc -l` |
| Integration test files | 20 | `ls tests/Integration/*.php | wc -l` |

## Size Metrics

| Metric | Value | Verification |
|---|---:|---|
| Production PHP lines (`includes/`, `wp-sudo.php`, `uninstall.php`, `mu-plugin/`, `bridges/`) | 10,213 | `find ./includes ./wp-sudo.php ./uninstall.php ./mu-plugin ./bridges -type f -name "*.php" -print0 | xargs -0 wc -l | tail -1 | awk '{print $1}'` |
| Tests PHP lines (`tests/`) | 19,108 | `find ./tests -type f -name "*.php" -print0 | xargs -0 wc -l | tail -1 | awk '{print $1}'` |
| Production + tests PHP lines | 29,321 | sum of the two rows above |
| Test-to-production ratio | 1.87:1 | `19108 / 10213` |
| Total repo PHP lines (excluding `vendor/`, `vendor_test/`, `.tmp/`, `.git/`) | 29,378 | `find . -type f -name "*.php" ! -path "*/vendor/*" ! -path "*/vendor_test/*" ! -path "*/.tmp/*" ! -path "*/.git/*" -print0 | xargs -0 wc -l | tail -1 | awk '{print $1}'` |

## Architectural Facts

Volatile counts that change when features ship. Every doc referencing these
numbers MUST point to or be verified against this table — never hardcode
the count in prose without a verification command.

| Fact | Value | Verification | Last changed |
|---|---:|---|---|
| Request surfaces | 7 | `printf '%s\n' admin ajax rest cli cron xmlrpc wpgraphql | wc -l` | v2.5.0 (WPGraphQL) |
| Gated rules (single-site) | 26 | `grep "'id'" includes/class-action-registry.php \| grep -v network \| grep -v "rule\[" \| wc -l` | unreleased |
| Gated rules (multisite) | 8 | `grep "'id'" includes/class-action-registry.php \| grep -c "network"` | unreleased |
| Gated rules (total) | 34 | `grep "'id'" includes/class-action-registry.php \| grep -v "rule\[" \| wc -l` | unreleased |
| Help tabs | 12 | `grep -c -- "->add_help_tab(" includes/class-admin.php` | unreleased |
| Audit hooks | 10 | `python3 - <<'PY'\nimport pathlib, re\nhooks = set()\nfor path in pathlib.Path('includes').glob('class-*.php'):\n    hooks.update(re.findall(r\"do_action\\(\\s*'([^']+)'\", path.read_text()))\nhooks.discard('wp_sudo_render_two_factor_fields')\nprint(len(hooks))\nPY` | unreleased |
| Settings fields (base) | 6 | 1 numeric (duration) + 1 preset chooser + 4 policy dropdowns (REST, CLI, Cron, XML-RPC) | unreleased |
| Settings fields (with WPGraphQL) | 7 | +1 conditional WPGraphQL policy dropdown | unreleased |
| E2E tests | 60 | `npx playwright test --config tests/e2e/playwright.config.ts --list` | unreleased |

### Files that reference these counts

When any fact above changes, update this table first, then grep for the old
value across these known consumers:

- `readme.md`, `readme.txt` — plugin description
- `docs/abilities-api-assessment.md` — Gate surfaces table
- `docs/ui-ux-testing-prompts.md` — settings page field count
- `docs/developer-reference.md` — hook signatures, audit hooks
- `tests/MANUAL-TESTING.md` — gated rules count
- `docs/ROADMAP.md` — unit test coverage notes

## CI Matrix Snapshot

Source: `.github/workflows/phpunit.yml`, `.github/workflows/e2e.yml`, `.github/workflows/e2e-nginx.yml`, `.github/workflows/e2e-sqlite.yml`, `.github/workflows/compat-wordpress-minors.yml`

- Unit test matrix: PHP 8.0, 8.1, 8.2, 8.3, 8.4
- Integration matrix: PHP 8.0, 8.1, 8.3; WordPress 6.2, 6.7, 7.0-RC1; MySQL 8.0 plus one MariaDB LTS lane; multisite true/false on the main MySQL lanes
- Scheduled compat sweep: PHP 8.1 on WordPress 6.3, 6.4, 6.5, and 6.6, plus MariaDB LTS overlap lanes on WordPress 6.4 and 6.5
- Browser stack smoke workflows: Apache + MariaDB (`wp-env`), nginx + php-fpm + MariaDB, and Playground SQLite

## Verification Notes

- `composer test:unit` passed on 2026-04-19 (`561 tests`, `1525 assertions`).
- `composer test:integration` passed on 2026-04-18 (`150 tests`, `474 assertions`, `9 skipped`) using the repo wrapper's `wp-env` `tests-cli` fallback against the containerized `wordpress_test` database.
- `WP_MULTISITE=1 composer test:integration` passed on 2026-04-18 (`150 tests`, `488 assertions`, `2 skipped`) using the same `wp-env` `tests-cli` fallback and database.
- `composer analyse:phpstan`, `composer analyse:psalm`, and `composer lint` passed on 2026-04-19.

## Update Procedure

1. Re-run all verification commands listed above.
2. Update this file first.
3. Run `composer verify:metrics` to confirm the document matches live counts.
4. Keep other docs referencing this file instead of duplicating current counts.
