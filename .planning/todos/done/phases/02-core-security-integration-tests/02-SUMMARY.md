---
phase: 02
plan: 02
subsystem: integration-tests
tags: [integration-tests, bcrypt, session-binding, transients, reauth-flow]
dependency-graph:
  requires: [01-integration-test-harness-scaffold]
  provides: [INTG-01, INTG-02, INTG-03, INTG-04]
  affects: [class-sudo-session.php, tests/integration/]
tech-stack:
  added: []
  patterns: [WP_UnitTestCase, real-DB-transactions, superglobal-isolation, did_action-delta]
key-files:
  created:
    - tests/integration/SudoSessionTest.php
    - tests/integration/RequestStashTest.php
    - tests/integration/ReauthFlowTest.php
  modified:
    - tests/integration/TestCase.php
    - includes/class-sudo-session.php
    - tests/Unit/SudoSessionTest.php
    - patchwork.json
decisions:
  - "WP 6.8+ bcrypt prefix is $wp$2y$ not $2y$ — test asserts both prefixes for portability"
  - "headers_sent() guard added to all setcookie() call sites for CLI/cron/integration-test compatibility"
  - "headers_sent added to patchwork.json redefinable-internals so Brain\\Monkey can stub it"
metrics:
  duration: "~9 min"
  completed: 2026-02-19
  tasks-completed: 5
  files-modified: 7
---

# Phase 2 Plan 02: Core Security Integration Tests Summary

Real bcrypt verification, SHA-256 session token binding, transient stash lifecycle, and full 5-class reauth flow — 21 integration tests, 0 mocks, real WordPress + MySQL.

## Tasks Completed

| Task | Description | Commit |
|------|-------------|--------|
| 1 | Enhance TestCase base class | `4eb7d7c` |
| 2+3+4 | SudoSessionTest, RequestStashTest, ReauthFlowTest | `07af246` |
| 5 | Verification (deviation fixes) | `60cef31` |

## What Was Built

### TestCase base class (Task 1)

Enhanced `tests/integration/TestCase.php` with:
- Superglobal snapshot/restore (`$_SERVER`, `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`) in `set_up()`/`tear_down()`
- Static cache resets in `tear_down()`: `Sudo_Session::reset_cache()`, `Action_Registry::reset_cache()`, `Admin::reset_cache()`
- `unset($GLOBALS['pagenow'])` in `tear_down()` for Gate isolation
- `simulate_admin_request()` helper that sets all superglobals Gate reads

### SudoSessionTest — 10 tests (INTG-02, INTG-03)

- `test_wp_check_password_verifies_correct_bcrypt` — WP 6.8+ `$wp$2y$` hash verified
- `test_wp_check_password_rejects_wrong_password` — real bcrypt rejection
- `test_activate_stores_token_hash_in_user_meta` — SHA-256 hash stored in user meta
- `test_activate_sets_cookie_superglobal` — 64-char token in `$_COOKIE`
- `test_cookie_sha256_matches_stored_meta_hash` — token binding proof
- `test_is_active_returns_true_with_valid_binding` — full binding check
- `test_is_active_returns_false_with_tampered_cookie` — SHA-256 mismatch detection
- `test_is_active_returns_false_when_expired` — past expiry detection
- `test_deactivate_clears_meta_and_cookie` — full cleanup verification
- `test_attempt_activation_exercises_real_bcrypt` — `wp_sudo_activated` hook fires

### RequestStashTest — 7 tests (INTG-04)

- `test_save_stores_transient` — `set_transient()` with 16-char key
- `test_get_retrieves_for_correct_user` — user-scoped retrieval
- `test_get_returns_null_for_wrong_user` — user ownership enforcement
- `test_delete_removes_transient` — `delete_transient()` confirms removal
- `test_exists_true_then_false_after_delete` — one-time-use pattern
- `test_stash_preserves_request_structure` — all 8 fields present and accurate
- `test_save_captures_post_data` — passwords preserved for replay

### ReauthFlowTest — 4 tests (INTG-01)

- `test_full_reauth_flow_exercises_five_classes` — full 8-step flow: Gate match → stash → bcrypt → session active → token binding verified → stash retrieved → stash deleted → hooks verified
- `test_reauth_flow_rejects_wrong_password` — session stays inactive, stash preserved
- `test_gate_does_not_match_non_gated_action` — `index.php` returns null
- `test_stash_is_user_bound_across_flow` — cross-class ownership test

## Final Test Counts

| Suite | Tests | Assertions | Failures |
|-------|-------|------------|---------|
| Unit | 343 | 853 | 0 |
| Integration | 21 | 65 | 0 |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] setcookie() fails with "headers already sent" in CLI/integration-test contexts**

- **Found during:** Task 5 (integration test run)
- **Issue:** All four `setcookie()` call sites in `class-sudo-session.php` throw a PHP warning in PHPUnit/CLI because output has already occurred, causing 9/21 integration tests to ERROR
- **Fix:** Wrapped all `setcookie()` call sites with `if ( ! headers_sent() )` guards. The `$_COOKIE` superglobal assignment always runs (so current-request token reads still work). Added `headers_sent` to `patchwork.json` `redefinable-internals`. Added `Functions\when('headers_sent')->justReturn(false)` to all 12 affected unit test methods.
- **Files modified:** `includes/class-sudo-session.php`, `patchwork.json`, `tests/Unit/SudoSessionTest.php`
- **Commit:** `60cef31`

**2. [Rule 1 - Bug] WP 6.8+ bcrypt hash uses $wp$2y$ prefix, not $2y$**

- **Found during:** Task 5 (integration test run)
- **Issue:** `test_wp_check_password_verifies_correct_bcrypt` asserted `assertStringStartsWith('$2y$', ...)` but WP 6.8+ wraps bcrypt with a `$wp` prefix for domain separation (SHA-384 pre-hashing), producing `$wp$2y$05$...`
- **Fix:** Updated assertion to accept both `$wp$2y$` (WP 6.8+) and `$2y$` (older WP) using `str_starts_with()` logic with a descriptive failure message showing the actual hash
- **Files modified:** `tests/integration/SudoSessionTest.php`
- **Commit:** `60cef31`

## Decisions Made

| Decision | Choice | Rationale |
|----------|--------|-----------|
| WP 6.8+ bcrypt prefix | Assert `$wp$2y$` OR `$2y$` | Portable across WP versions; test env uses WP 6.9.1 |
| headers_sent() guard | Guard ALL setcookie() call sites | Correct behavior in CLI/cron/integration contexts where HTTP headers can't be sent |
| patchwork.json | Add `headers_sent` to redefinable-internals | Required for Brain\Monkey to stub native PHP internals |

## Self-Check: PASSED

All files exist and all commits verified:
- `tests/integration/TestCase.php` — FOUND
- `tests/integration/SudoSessionTest.php` — FOUND
- `tests/integration/RequestStashTest.php` — FOUND
- `tests/integration/ReauthFlowTest.php` — FOUND
- `includes/class-sudo-session.php` — FOUND
- `patchwork.json` — FOUND
- Commit `4eb7d7c` — FOUND
- Commit `07af246` — FOUND
- Commit `60cef31` — FOUND
