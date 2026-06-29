---
phase: 12-breaking-changes-and-floor-bump
plan: 02
status: complete
completed: 2026-06-16
requirements: [BRK-04, BRK-05, BRK-06]
commits:
  - ca9fc3d  # chore(12-02): raise minimum floors to WordPress 6.4 and PHP 8.2
  - d3bd0b8  # ci(12-02): pin CI floor to WP 6.4 / PHP 8.2 and drop php80-tests
---

# Plan 12-02 Summary — Floor bump + BRK-06 close

## What shipped

- **BRK-04 (WordPress 6.4 floor):** `wp-sudo.php` header `Requires at least: 6.2 → 6.4`;
  `readme.txt` `Requires at least: 6.2 → 6.4`; CI support-floor integration lane
  `wp '6.2' → '6.4'`; `plugin-check.yml` compatibility comment now references 6.4;
  `compat-wordpress-minors.yml` WP 6.3 lane dropped (below the new floor).
- **BRK-05 (PHP 8.2 floor):** `wp-sudo.php` header `Requires PHP: 8.0 → 8.2`;
  `readme.txt` `Requires PHP: 8.0 → 8.2`; `composer.json` `require.php >=8.0 → >=8.2`;
  `config.platform.php 8.1.99 → 8.2.99`; `composer.lock` regenerated; CI unit matrix
  `['8.0','8.1','8.2','8.3','8.4'] → ['8.2','8.3','8.4']`; integration PHP lanes all
  raised to 8.2; the four PHP 8.0 special-case CI conditionals and the
  `.github/php80-tests/` vendor tree removed.
- **BRK-06 (verify-then-close):** `grep -rn "wp_get_admin_notice" includes/ wp-sudo.php`
  returns zero matches — the claimed shim never existed (the only `function_exists`
  checks in `class-admin.php` guard `graphql`, a legitimate runtime integration).
  Recorded satisfied/obsolete in `REQUIREMENTS.md`. No code change.

## Floor sites changed (old → new)

| Site | Old | New |
|------|-----|-----|
| wp-sudo.php `Requires at least` | 6.2 | 6.4 |
| wp-sudo.php `Requires PHP` | 8.0 | 8.2 |
| readme.txt `Requires at least` | 6.2 | 6.4 |
| readme.txt `Requires PHP` | 8.0 | 8.2 |
| composer.json `require.php` | >=8.0 | >=8.2 |
| composer.json `config.platform.php` | 8.1.99 | **8.2.99** (see deviation) |
| phpunit.yml unit PHP matrix | 8.0,8.1,8.2,8.3,8.4 | 8.2,8.3,8.4 |
| phpunit.yml integration floor lane | php 8.0 / wp 6.2 | php 8.2 / wp 6.4 |
| phpunit.yml integration 8.1 lanes | 8.1 | 8.2 |
| plugin-check.yml comment | newer-than-6.2 | newer-than-6.4 |
| compat-wordpress-minors.yml lowest lane | 6.3 | 6.4 (6.3 dropped) |

## composer.lock

Regenerated via `composer update --lock`. The diff is exactly three lines:
content-hash, the locked `require.php` value (`>=8.2`), and the platform-override
(`8.2.99`). No package versions changed in the lock — the install output's
"upgrading" lines were vendor/ syncing to versions already pinned in the lock.

## Deviations

1. **`config.platform.php = 8.2.99`, not `8.2.0`.** The plan/research specified
   `8.2.0`, but that makes dependency resolution unsatisfiable: the dev dependency
   `vimeo/psalm 6.16.1` requires `~8.2.27` (>=8.2.27), so `composer update --lock`
   fails on an `8.2.0` platform. `8.2.99` follows the repo's documented ".99 ceiling"
   convention (the prior value was `8.1.99`), satisfies the toolchain, and stays in
   the 8.2 series. The runtime floor is enforced by `require.php >=8.2` and the header;
   the platform value only governs dev-dependency resolution. Reviewer independently
   confirmed the reasoning. The plan's Task 1 verify grep (`'"8.2.0"'`) is therefore
   superseded by `'"8.2.99"'`.
2. **All 8.1 integration lanes bumped to 8.2 (not just the floor lane).** The plan
   named only the floor lane, but Task 1's `require.php >=8.2` would make `composer
   install` fail on any 8.1 lane. Bumping every 8.1 integration lane to 8.2 was
   necessary to keep CI green; WP-version / multisite / MariaDB coverage is preserved
   at the new floor.

## Assumption stated

`WP_SUDO_VERSION` / `Stable tag` NOT bumped (stays 3.4.0). The floor bump is
independent of the plugin release version, which is set at the tagging step.

## Verification at completion

- `composer test:unit` — 795 tests, 2275 assertions, OK.
- `composer lint` — clean. `composer analyse:phpstan` (sandbox disabled) — No errors.
- All three workflow YAML files validate (`yaml.safe_load`).
- No `php: '8.0'`/`'8.1'` lanes or `php80-tests` references remain; `.github/php80-tests/` removed.
- `grep -rn "wp_get_admin_notice" includes/ wp-sudo.php` empty (BRK-06).

Each code/CI commit was reviewer-approved (flags host-clock stamped).
