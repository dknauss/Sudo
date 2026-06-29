---
phase: 12
slug: breaking-changes-and-floor-bump
status: passed
verified: 2026-06-16
verifier: inline (gsd-verifier hit a transient API 500 mid-run; checks re-run by orchestrator)
---

# Phase 12 — Goal Verification

**Goal:** v4.0.0 ships with all deprecated APIs removed, the `compatibility`
governance mode fully excised (with a migration notice for sites still using it),
and minimum requirements honestly declared as WordPress 6.4 and PHP 8.2 — with the
corresponding shims deleted.

**Result: PASSED.** All 7 BRK requirements verified against the live codebase.

## Per-requirement verification

| Req | Verified by | Result |
|-----|-------------|--------|
| BRK-01 | `grep "function sudo_can(" includes/functions-governance.php` → 0; `wp_sudo_can` present; unit test `test_sudo_can_alias_no_longer_exists` present; integration `test_sudo_can_alias_was_removed` present | ✅ sudo_can() removed; wp_sudo_can() sole survivor |
| BRK-02 | `grep "'compatibility' === get_option"` → 0; both functions end with strict returns; 2 `*_treats_compatibility_as_strict_after_removal` tests; `wp_sudo_is_recovery_mode()` + both recovery short-circuits intact | ✅ compatibility mode excised; stale option inert/strict; recovery preserved |
| BRK-03 | `render_compatibility_mode_notice()` present (class-admin.php:2492), registered on `admin_notices` + `network_admin_notices` (lines 253–254); 4 AdminTest notice tests (8 grep hits incl. helper); non-dismissible; authority-gated; `_doing_it_wrong(…, '4.0.0')` | ✅ persistent migration notice shipped |
| BRK-04 | `Requires at least: 6.4` in wp-sudo.php + readme.txt; CI floor lane `wp: '6.4'` in phpunit.yml | ✅ WordPress 6.4 floor declared |
| BRK-05 | `Requires PHP: 8.2` in wp-sudo.php + readme.txt; composer `require.php >=8.2` + `config.platform.php 8.2.99`; unit matrix `['8.2','8.3','8.4']`; php80-tests has 0 tracked files | ✅ PHP 8.2 floor declared; 8.0/8.1 lanes + php80 vendor tree removed |
| BRK-06 | `grep -rn "wp_get_admin_notice" includes/ wp-sudo.php` → empty; REQUIREMENTS.md marks obsolete/satisfied | ✅ verify-then-close: no shim existed |
| BRK-07 | CHANGELOG `Breaking changes (4.0.0)` block; readme `= 4.0.0 =` Upgrade Notice; `docs/developer-reference.md` "Migrating to 4.0" section; no new standalone file | ✅ migration notes in three durable places |

## Suite / analysis

- `composer test:unit` — 795 tests, 2275 assertions, OK.
- `composer lint` — clean (20/20).
- `composer analyse:phpstan` (sandbox disabled) — No errors.
- All three modified workflow YAML files validate.

## Notes / deviations (documented in plan SUMMARYs)

- `config.platform.php = 8.2.99` (not the plan's `8.2.0`) — required because dev
  dep `vimeo/psalm 6.16.1` needs `~8.2.27`; follows the repo's `.99` convention.
  Runtime floor is `require.php >=8.2` + header.
- All 8.1 CI integration lanes bumped to 8.2 (plan named only the floor lane) —
  necessary because `require.php >=8.2` would break `composer install` on 8.1.
- TDD RED commits were not separately committed: the pre-commit hook blocks any
  code commit with failing tests, so each task was committed once green (RED was
  verified locally first).
- `WP_SUDO_VERSION` / `Stable tag` remain 3.4.0 — an explicit, documented
  assumption (floors are independent of the release version). Not a gap.
- Benign local artifact: `.github/php80-tests/` has 0 git-tracked files (removed),
  but an untracked/gitignored `vendor/` subdirectory lingers on disk locally
  (`rm` was permission-denied). It is not part of the repository and does not
  affect CI; `git status` is clean for that path.

## Requirement traceability

All BRK-01..BRK-07 accounted for in REQUIREMENTS.md (BRK-06 marked obsolete/satisfied).
No unaddressed requirement IDs.
