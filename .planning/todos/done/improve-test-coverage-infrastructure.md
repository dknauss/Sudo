# Improve Test Coverage Infrastructure

**Status: ✅ Completed in v2.9.1 (commit 72a2e73)**

---

## Context

WP Sudo had 397 unit tests across all 10 production classes, but no code coverage measurement. The `phpunit.xml.dist` files declared `<coverage><include>` blocks for `includes/`, but no coverage driver was configured — CI ran with `coverage: none`. Two specific gaps carried real risk:

1. **Challenge class** — had unit tests only; the most security-critical flow (password verification → session grant → stash replay) had no integration test
2. **`uninstall.php`** — had zero tests; cleans up roles, options, and user meta across multisite networks
3. **Coverage reporting** — no baseline, no regression prevention

> **Note:** The pre-implementation version of this document stated "489 tests (397 unit + 92 integration)". That was incorrect — the integration count was a rough projection. The actual count after the work was completed is 397 unit tests and ~80 integration tests.

---

## What Was Planned vs. What Was Built

### Step 1: PCOV coverage CI job ✅

**Planned:**
- New `unit-tests-coverage` CI job, PHP 8.3 only, `coverage: pcov`
- Upload `coverage.xml` as artifact
- New `test:coverage` composer script
- No failure threshold

**Implemented (matches plan):**
- `.github/workflows/phpunit.yml` — `unit-tests-coverage` job runs alongside (not after) `unit-tests`; PHP 8.3, `coverage: pcov`, uploads `coverage.xml` via `actions/upload-artifact@v4`
- `composer.json` — `"test:coverage": "phpunit --configuration phpunit.xml.dist --coverage-clover coverage.xml --coverage-text"` (uses `phpunit` not `./vendor/bin/phpunit` — Composer resolves the binary)
- No threshold set; baseline established

---

### Step 2: Challenge integration test ✅

**Planned:** A single test exercising the 7-step challenge flow (wrong password → correct password → token binding → stash lifecycle)

**Implemented:** 5 separate focused test methods in `tests/Integration/ChallengeTest.php`:

| Method | What it verifies |
|--------|-----------------|
| `test_wrong_password_returns_invalid_and_fires_audit_hook` | Wrong password → `invalid_password` code, session stays inactive, `wp_sudo_reauth_failed` fires |
| `test_correct_password_activates_session_and_fires_audit_hook` | Correct password → `success` code, session active, `wp_sudo_activated` fires |
| `test_token_binding_matches_cookie_to_stored_hash` | `$_COOKIE[TOKEN_COOKIE]` set, `hash('sha256', cookie) === stored meta` |
| `test_request_stash_save_get_delete_lifecycle` | Full stash round-trip: `save()` → `get()` → `delete()` → `get()` returns null |
| `test_lockout_after_max_failed_attempts` | MAX_FAILED_ATTEMPTS − 1 return `invalid_password`, 5th triggers `locked_out` + `wp_sudo_lockout` hook, correct password still rejected during lockout |

The lockout test was also revised after a CI failure: the original plan looped `MAX_FAILED_ATTEMPTS` times expecting `invalid_password` on each, but the 5th attempt actually returns `locked_out` (not `invalid_password`). The fix: loop `MAX_FAILED_ATTEMPTS - 1` times, then assert the next returns `locked_out`.

---

### Step 3: Uninstall integration test ✅

**Planned:** Two methods — `test_single_site_uninstall_cleans_all_data()` and `test_multisite_uninstall_preserves_active_site_data()`

**Implemented:** `tests/Integration/UninstallTest.php` with two methods:

**`test_single_site_uninstall_cleans_all_data()`** — matches plan exactly:
- Activate plugin → create admin → activate sudo session → verify meta/option exist → define `WP_UNINSTALL_PLUGIN` → require `uninstall.php` → assert:
  - `wp_sudo_settings`, `wp_sudo_activated`, `wp_sudo_role_version`, `wp_sudo_db_version` options deleted
  - `_wp_sudo_expires`, `_wp_sudo_token`, `_wp_sudo_failed_attempts`, `_wp_sudo_lockout_until` user meta deleted
  - Editor role has `unfiltered_html` restored
- Skipped on multisite via `markTestSkipped()`

**`test_multisite_uninstall_cleans_user_meta()`** — name and behavior differ from plan:
- Plan described a two-site scenario where site A remains active and user meta is *preserved*
- Implemented scenario: plugin removed from `active_plugins` and `active_sitewide_plugins` (simulating post-deactivation state before uninstall runs), then uninstall runs → user meta *is* cleaned (no site has it active) → network options cleaned
- Skipped on single-site via `markTestSkipped()`

The plan's "preserves active site data" scenario was not implemented — the actual test verifies the more common "clean everything" case, which is the path exercised in CI since the integration test environment doesn't have multiple subsites with selective activation.

---

### Step 4: CLAUDE.md and ROADMAP.md ✅

**Implemented (matches plan):**
- `CLAUDE.md` — `composer test:coverage` added to Commands section
- `ROADMAP.md` — Section 6 (previously "Deferred") updated to note PCOV job added and coverage baseline established

---

### Step 5: Commit and sync ✅

- Commit: `72a2e73` — `test: add PCOV coverage CI job, Challenge and uninstall integration tests`
- Synced to all 5 dev sites
- No version bump (test infrastructure only)

---

## Files Modified

| File | Change |
|------|--------|
| `.github/workflows/phpunit.yml` | New `unit-tests-coverage` job (PHP 8.3, PCOV, artifact upload) |
| `composer.json` | New `test:coverage` script |
| `tests/Integration/ChallengeTest.php` | New — 5 test methods |
| `tests/Integration/UninstallTest.php` | New — 2 test methods |
| `CLAUDE.md` | `composer test:coverage` added to Commands |
| `ROADMAP.md` | Coverage baseline noted in Section 6 |

---

## Verification Results (CI — post-commit)

- **Unit tests:** 397 tests, 944 assertions — ✅ all 4 PHP versions (8.1/8.2/8.3/8.4)
- **Coverage job:** ✅ `coverage.xml` artifact generated (PHP 8.3, PCOV baseline)
- **Integration tests:** ✅ `UninstallTest` passed everywhere; `ChallengeTest` had 2 failures (lockout test logic bug) → fixed in same session
- **Code quality:** PHPStan level 6 clean, PHPCS clean

---

## Remaining Work (not part of this plan)

See `ROADMAP.md` Section 8 (Exit Path Testing) and Section 9 (Code Review Findings) for what comes next. The key remaining gap from the original testing strategy is exit-path coverage — the 76 `exit`/`die` paths in Gate that can't be tested without `@runInSeparateProcess` integration tests.
