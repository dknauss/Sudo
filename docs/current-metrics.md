# Current Metrics (Canonical)

This file is the single source of truth for current repository counts.

Last verified: 2026-07-05
Verification environment: local workspace, PHP 8.x

## Test Metrics

| Metric | Value | Verification |
|---|---:|---|
| Unit tests | 997 tests | `composer test:unit` |
| Unit assertions | 3,032 assertions | `composer test:unit` |
| Integration tests in suite | 208 test methods | `rg -c "function test" tests/Integration/*.php | awk -F: '{sum+=$2} END{print sum}'` |
| Unit test files | 30 | `ls tests/Unit/*.php | wc -l` |
| Integration test files | 28 | `ls tests/Integration/*.php | wc -l` |

## Size Metrics

| Metric | Value | Verification |
|---|---:|---|
| Production PHP lines (`includes/`, `wp-sudo.php`, `uninstall.php`, `mu-plugin/`, `bridges/`) | 17,183 | `find ./includes ./wp-sudo.php ./uninstall.php ./mu-plugin ./bridges -type f -name "*.php" -print0 | xargs -0 wc -l | tail -1 | awk '{print $1}'` |
| Tests PHP lines (`tests/`) | 36,231 | `find ./tests -type f -name "*.php" -print0 | xargs -0 wc -l | tail -1 | awk '{print $1}'` |
| Production + tests PHP lines | 53,414 | sum of the two rows above |
| Test-to-production ratio | 2.11:1 | `36231 / 17183` |
| Total repo PHP lines (excluding `vendor/`, `vendor_test/`, `.tmp/`, `.git/`) | 53,686 | `find . -type f -name "*.php" ! -path "*/vendor/*" ! -path "*/vendor_test/*" ! -path "*/.tmp/*" ! -path "*/.git/*" -print0 | xargs -0 wc -l | tail -1 | awk '{print $1}'` |

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

- `composer test:unit` passed on 2026-07-05 (`995 tests`, `3024 assertions`). Harmonized user identity across the dashboard Session Activity widget and the Settings → Sudo Access tab: both now lead with the user's **full real name** (primary), with the **username secondary** (linked to user-edit when permitted), an avatar, and translated role chip(s). A shared `WP_Sudo\User_Identity` helper (`primary_name()` / `role_labels()`) is the single source of truth so the two surfaces cannot drift; role names go through `translate_user_role()`. Also fixed a latent no-op: `get_avatar()`'s force key is `force_display`, not `force` (the widget's avatar previously honored the site "Show Avatars" setting instead of always rendering); the stale Psalm baseline entry for it was removed (+9 tests: 6 `UserIdentityTest`, 3 Access-tab/widget cell + permission-branch).
- `composer test:unit` passed on 2026-07-05 (`986 tests`, `3003 assertions`). Access-tab capability-holder table: each capability now renders as its own `wp-sudo-cap-item` row showing the human-readable label instead of the raw slug, with the Revoke control paired directly to it (responsive at narrow widths, no orphaned links); the slug moves to a tooltip + `screen-reader-text` span, and the Grant Capability dropdown drops the parenthetical slug from its visible option text. The revoke JS now removes the whole capability item container rather than a position-dependent `<code>` sibling. Each Revoke button also carries a capability-specific `aria-label` ("Revoke <label> capability") so screen-reader users tabbing through otherwise-identical controls get the capability context, and `get_cap_label()` now returns its labels through the text domain so localized installs get translated capability names (the label is the primary visible text now that the slug is hidden) (+1 test).
- `composer test:unit` passed on 2026-07-04 (`985 tests`, `2993 assertions`). Phase 25 (Governance Coverage Panel Fix, GCOV-01/02): the Access-tab coverage panel names the context-correct capability (manage_network_options on multisite) and excludes multisite super admins from the "cannot access" list while still listing genuinely drifted non-super-admin network operators (+3 tests).
- `composer test:unit` passed on 2026-07-04 (`982 tests`, `2986 assertions`). Session revocation moved from the Users-list toolbar button + admin-post interstitial to a native "Revoke sudo sessions" bulk action (one rate slot per batch, self-skip, per-user audit fires with the `users_list_bulk_action` reason); the Sudo Active badge count transient is now invalidated on session grant/teardown; the stale `wp_sudo_revoke_session` AJAX reference was scrubbed from the registry. External-review hardening: bulk handling moved behind a nonce-verified `load-users.php` interceptor (core does not nonce-check custom users.php bulk actions) and both revocation paths gained a current-site membership guard placed before the liveness check.
- `composer test:unit` passed on 2026-07-04 (`968 tests`, `2948 assertions`). The Event_Recorder now subscribes to `wp_sudo_session_revoked` and stores `session_revoked` event rows, and the dashboard widget renders human-readable labels for `session_revoked` ("Revoked") and `escalation_blocked` ("Escalation") with distinct pill styling (+4 tests).
- `composer test:unit` passed on 2026-07-01 (`956 tests`, `2888 assertions`). Phase 24 (Session Revocation UI) added the shared revoke-all/liveness methods, the factored revocation core, the Users-list row action + revoke-all interstitial + distinct result notices, and removed the Access-tab session-revoke button and the orphaned AJAX path. Follow-up fixes preserve the settings `tab` across a sudo reauth: on multisite network settings save, and single-site via a shared `wp_sudo_build_challenge_url()` helper that rawurlencodes the nested return_url across all challenge-URL builders + the admin-bar deactivate link (+11 tests, incl. a faithful add_query_arg test stub).
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
