# Phase 01-03 Summary: Per-User Request Stash Cap and Index

## Objective
Implement a per-user stash cap (max 5 entries) with oldest-first eviction, track stash keys in user meta, and keep the index clean during replay/delete/uninstall.

## Changes Implemented

### 1. Request Stash Cap and Index
Updated [includes/class-request-stash.php](/Users/danknauss/Documents/GitHub/wp-sudo/includes/class-request-stash.php):
- Added constants:
  - `MAX_STASH_PER_USER = 5`
  - `STASH_INDEX_META_KEY = '_wp_sudo_stash_keys'`
- Updated `save()`:
  - Calls `enforce_stash_cap( $user_id )` before write.
  - Writes transient.
  - Calls `add_to_stash_index( $user_id, $key )` after write.
- Updated `delete()` signature to `delete( string $key, int $user_id = 0 )`:
  - Always deletes transient when key provided.
  - Removes key from index when `user_id > 0`.
- Added private index helpers:
  - `enforce_stash_cap()`
  - `add_to_stash_index()`
  - `remove_from_stash_index()`
  - `get_stash_index()`
  - `set_stash_index()`

### 2. Replay Path Index Cleanup
Updated [includes/class-challenge.php](/Users/danknauss/Documents/GitHub/wp-sudo/includes/class-challenge.php):
- `replay_stash()` now calls:
  - `$this->stash->delete( $stash_key, $user_id );`

### 3. Uninstall Cleanup
Updated [uninstall.php](/Users/danknauss/Documents/GitHub/wp-sudo/uninstall.php):
- Added user-meta cleanup:
  - `delete_metadata( 'user', 0, '_wp_sudo_stash_keys', '', true );`

### 4. Unit Tests
Updated [tests/Unit/RequestStashTest.php](/Users/danknauss/Documents/GitHub/wp-sudo/tests/Unit/RequestStashTest.php):
- Added new stash cap/index tests:
  - `test_save_records_key_in_stash_index`
  - `test_save_enforces_stash_cap`
  - `test_delete_with_user_id_removes_from_stash_index`
  - `test_delete_without_user_id_skips_index_cleanup`
  - `test_max_stash_per_user_is_five`
  - `test_stash_index_meta_key_constant`
- Added a helper stub for non-index assertions:
  - `stub_stash_index_meta_io()`
- Updated save-path tests to stub `get_user_meta`/`update_user_meta`.

Updated [tests/Unit/ChallengeTest.php](/Users/danknauss/Documents/GitHub/wp-sudo/tests/Unit/ChallengeTest.php):
- Updated stash delete expectation to include `user_id` argument.

## Verification
Executed from `/Users/danknauss/Documents/GitHub/wp-sudo`:
- `composer test:unit -- --do-not-cache-result` -> `OK (448 tests, 1129 assertions)`
- `./vendor/bin/phpstan analyse --memory-limit=1G --debug` -> `[OK] No errors`
- `./vendor/bin/psalm --config=psalm.xml.dist --threads=1 --no-progress --no-cache` -> `No errors found`
- `composer lint` -> pass

## Notes
- In this environment, `composer analyse:phpstan` can intermittently fail with `tcp://127.0.0.1:0` EPERM due sandbox parallel worker binding. `phpstan --debug` is the reliable equivalent.
- In this environment, `composer analyse:psalm` may hang or fail on cache file locks; `psalm --no-cache` completes reliably and returned clean.
