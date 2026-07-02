# Current Metrics (Canonical)

This file is the single source of truth for current repository counts.

Last verified: 2026-06-30
Verification environment: local workspace, PHP 8.x

## Test Metrics

| Metric | Value | Verification |
|---|---:|---|
| Unit tests | 945 tests | `composer test:unit` |
| Unit assertions | 2,855 assertions | `composer test:unit` |
| Integration tests in suite | 208 test methods | `rg -c "function test" tests/Integration/*.php | awk -F: '{sum+=$2} END{print sum}'` |
| Unit test files | 28 | `ls tests/Unit/*.php | wc -l` |
| Integration test files | 28 | `ls tests/Integration/*.php | wc -l` |

## Size Metrics

| Metric | Value | Verification |
|---|---:|---|
| Production PHP lines (`includes/`, `wp-sudo.php`, `uninstall.php`, `mu-plugin/`, `bridges/`) | 16,719 | `find ./includes ./wp-sudo.php ./uninstall.php ./mu-plugin ./bridges -type f -name "*.php" -print0 | xargs -0 wc -l | tail -1 | awk '{print $1}'` |
| Tests PHP lines (`tests/`) | 34,296 | `find ./tests -type f -name "*.php" -print0 | xargs -0 wc -l | tail -1 | awk '{print $1}'` |
| Production + tests PHP lines | 51,015 | sum of the two rows above |
| Test-to-production ratio | 2.05:1 | `34296 / 16719` |
| Total repo PHP lines (excluding `vendor/`, `vendor_test/`, `.tmp/`, `.git/`) | 51,287 | `find . -type f -name "*.php" ! -path "*/vendor/*" ! -path "*/vendor_test/*" ! -path "*/.tmp/*" ! -path "*/.git/*" -print0 | xargs -0 wc -l | tail -1 | awk '{print $1}'` |

## Architectural Facts

Volatile counts that change when features ship. Every doc referencing these
numbers MUST point to or be verified against this table — never hardcode
the count in prose without a verification command.

| Fact | Value | Verification | Last changed |
|---|---:|---|---|
| Request surfaces | 7 | `printf '%s\n' admin ajax rest cli cron xmlrpc wpgraphql | wc -l` | v2.5.0 (WPGraphQL) |
| Gated rules (single-site) | 27 | `grep "'id'" includes/class-action-registry.php \| grep -v network \| grep -v "rule\[" \| wc -l` | v3.2.0 |
| Gated rules (multisite) | 8 | `grep "'id'" includes/class-action-registry.php \| grep -c "network"` | v3.2.0 |
| Gated rules (total) | 35 | `grep "'id'" includes/class-action-registry.php \| grep -v "rule\[" \| wc -l` | v3.2.0 |
| Help tabs | 6 | `grep -c -- "->add_help_tab(" includes/class-admin.php` | v3.2.0 |
| Audit hooks | 19 | `python3 - <<'PY'\nimport pathlib, re\nhooks = set()\nfor path in pathlib.Path('includes').glob('class-*.php'):\n    hooks.update(re.findall(r\"do_action\\(\\s*'([^']+)'\", path.read_text()))\nhooks.discard('wp_sudo_render_two_factor_fields')\nprint(len(hooks))\nPY` | v4.1.0 (wp_sudo_escalation_blocked) |
| Settings fields (base) | 6 | 1 numeric (duration) + 1 preset chooser + 4 policy dropdowns (REST, CLI, Cron, XML-RPC) | v3.0.0 |
| Settings fields (with WPGraphQL) | 7 | +1 conditional WPGraphQL policy dropdown | v3.0.0 |
| E2E tests | 64 | `npx playwright test --config tests/e2e/playwright.config.ts --list` (verified 2026-06-28) | v4.0.0 |

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

- Unit test matrix: PHP 8.2, 8.3, 8.4
- Integration matrix: trimmed from 10 to 7 lanes — PHP 8.2/8.3 across WordPress 6.4, 6.7, and 7.0; five single-site MySQL 8.0 lanes, one multisite MySQL 8.0 representative lane (PHP 8.3 / WP 7.0), and one MariaDB LTS lane
- Scheduled compat sweep: PHP 8.2 on WordPress 6.4, 6.5, and 6.6, plus MariaDB LTS overlap lanes on WordPress 6.4 and 6.5
- Browser stack smoke workflows: Apache + MariaDB (`wp-env`), nginx + php-fpm + MariaDB, and Playground SQLite

## Verification Notes

- `composer test:unit` passed on 2026-07-01 (`945 tests`, `2855 assertions`). Phase 24 (Session Revocation UI) added the shared revoke-all/liveness methods, the factored revocation core, the Users-list row action + revoke-all interstitial + distinct result notices, and removed the Access-tab session-revoke button and the orphaned AJAX path; a follow-up fix preserves the settings `tab` on multisite network settings save (+4 tests).
- `composer lint` passed on 2026-06-30.
- Static analysis passed on 2026-06-30 (PHPStan L6 `[OK] No errors`; Psalm `No errors found!`, baseline current).
- `composer verify:metrics` passed on 2026-06-30 (after this update).
- `npx playwright test --config tests/e2e/playwright.config.ts --list` reported `64 tests in 15 files` on 2026-06-28 after `access-grant.spec.ts` was wired into the E2E CI groups.
- Plugin Check CI passed on 2026-06-14 against a clean production dist; warning triage remains a follow-up.
- `composer test:integration` passed locally on 2026-06-27: single-site `213 tests`, `728 assertions`, `14 skipped`, `0 failures`; multisite (`WP_MULTISITE=1 composer test:integration`) `213 tests`, `725 assertions`, `8 skipped`, `0 failures`.

## Update Procedure

1. Re-run all verification commands listed above.
2. Update this file first.
3. Run `composer verify:metrics` to confirm the document matches live counts.
4. Keep other docs referencing this file instead of duplicating current counts.
