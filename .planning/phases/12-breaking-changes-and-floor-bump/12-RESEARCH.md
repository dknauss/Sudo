# Phase 12: Breaking Changes and Floor Bump - Research

**Researched:** 2026-06-16
**Domain:** PHP/WordPress plugin — deprecated API removal, governance-mode excision, platform-floor bump, TDD-over-existing-tests, persistent admin notices
**Confidence:** HIGH (all findings verified against live source files)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- Raise **both** floors: WordPress → 6.4, PHP → 8.2.
- Compatibility-mode removal is **bundled into 4.0.0** (no interim 3.5.0).
- `sudo_can()` → **hard removal**: the function must not exist; calling it fatals.
  `wp_sudo_can()` survives and keeps its collision-avoidance guard semantics.
- `WP_SUDO_RECOVERY_MODE` remains the **sole** break-glass path (keep
  `wp_sudo_is_recovery_mode()` and both recovery short-circuits intact).
- Strip the `compatibility` branch from `wp_sudo_can()` (lines 82–85) and from
  `wp_sudo_map_governance_meta_cap()` (lines 168–170); both default to `strict`
  (`user_can($user_id, $cap)`). Update the docblocks that describe compatibility
  mode (lines 47–49, 52, 156–160).
- Migration/deprecation notice: `admin_notices` + `network_admin_notices` only,
  persistent-until-resolved (NOT dismissible), gated on `wp_sudo_can('manage_wp_sudo')`,
  also emit `_doing_it_wrong()`.
- Integrator migration notes: CHANGELOG breaking-changes block + `readme.txt`
  Upgrade Notice + durable "Migrating to 4.0" section in `docs/developer-reference.md`.
  No new standalone file.
- BRK-06 is a **verify-then-close** task: confirm via grep that no
  `wp_get_admin_notice` shim exists anywhere in production code, record it as
  satisfied/obsolete in the SUMMARY, update `REQUIREMENTS.md`. Do **not** invent
  removal work; do **not** silently drop it.

### Claude's Discretion

- Exact wording of the admin notice and the `_doing_it_wrong()` message.
- Whether the deprecation-notice logic lives in `Admin`, a small dedicated method,
  or is wired via the `Upgrader` — planner/research decides the cleanest home.
- Exact CI matrix edits beyond the floor bump (which lanes, label text).

### Deferred Ideas (OUT OF SCOPE)

- **Stale-option cleanup** — normalizing/deleting `wp_sudo_governance_mode` when set
  to `compatibility` belongs to **Phase 13 (Migration Safety)**, which depends on this
  phase. Phase 12's notice only signals; it must not delete the option.
- Larger product features (Gutenberg UX, full Activity screen, network-admin tools,
  session table) remain deferred per PROJECT.md — out of scope for v4.0.0.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| BRK-01 | Remove `sudo_can()` alias; only `wp_sudo_can()` remains | Verified: zero internal callers; remove lines 90–112 of `functions-governance.php`; test via `assertFalse(function_exists('sudo_can'))` in integration test |
| BRK-02 | Remove `compatibility` governance mode from `wp_sudo_can()` and `wp_sudo_map_governance_meta_cap()` | Verified: branches at lines 82–85 and 168–170; 7 unit tests in GovernanceTest cover compatibility mode and must be removed/updated |
| BRK-03 | Persistent admin notice + `_doing_it_wrong()` when `wp_sudo_governance_mode === 'compatibility'` | Pattern: `ob_start()`/`expectOutputRegex()` + `Functions\expect('_doing_it_wrong')` in unit test; recommend `Admin` class home |
| BRK-04 | Raise WordPress minimum to 6.4 across plugin header, `readme.txt`, CI support-floor lane | Verified 5 change sites; CI line 177 in `phpunit.yml` is the integration support-floor matrix entry |
| BRK-05 | Raise PHP minimum to 8.2 across plugin header, `readme.txt`, `composer.json` require, `config.platform.php` | Verified 4 change sites; `config.platform.php` currently `8.1.99` must become `8.2.0` |
| BRK-06 | Remove `function_exists('wp_get_admin_notice')` shims | VERIFIED OBSOLETE: grep confirms zero matches in production code; task is confirm + record |
| BRK-07 | Document removed APIs and raised minimums for integrators | Three deliverables: CHANGELOG block, `readme.txt` Upgrade Notice, `docs/developer-reference.md` "Migrating to 4.0" section |
</phase_requirements>

---

## Summary

Phase 12 is a cleanup-and-declare release: it removes `sudo_can()` and the `compatibility` governance branch, raises the WP/PHP floors in all declaration sites, adds a persistent admin notice for sites that still have the stale option, and documents the breaking changes. No new product behavior ships.

The main implementation complexity is in test hygiene. Removing the `compatibility` branch means deleting or heavily rewriting 7 unit tests in `GovernanceTest.php`, and adding new tests for the persistent notice. The notice implementation should live in `Admin` (matching `render_recovery_mode_notice` as a precedent) rather than `Upgrader`, because the trigger condition is evaluated on every admin page load, not at upgrade time.

The floor bump is mechanical: 5 text-substitution sites for WordPress 6.4, 4 for PHP 8.2, plus removing the `php80-tests` CI shim lanes once PHP 8.0 is no longer supported. The `@$method->setAccessible(true)` pattern throughout the test suite is already `@`-suppressed for PHP 8.5+ deprecation and requires no change when the PHP floor moves to 8.2.

**Primary recommendation:** implement in this order — (1) design review, (2) BRK-02 branch removal + GovernanceTest update, (3) BRK-01 `sudo_can()` deletion, (4) BRK-03 persistent notice with TDD, (5) floor bumps (BRK-04/BRK-05/BRK-06), (6) migration docs (BRK-07).

---

## Standard Stack

### Core (verified against live files)

| Component | Version/Location | Purpose | Notes |
|-----------|-----------------|---------|-------|
| Brain\Monkey | ^2.7 | WordPress function mocking in unit tests | Used for all governance stubs |
| Mockery | ^1.6 | Object mocking | Already in use |
| PHPUnit | ^9.6 | Test runner | `expectOutputRegex()` used for notice tests |
| `functions-governance.php` | live at `includes/` | All governance helpers | The only file requiring PHP logic changes for BRK-01/BRK-02 |
| `class-admin.php` | live at `includes/` | Admin UI surfaces | Home for new deprecation notice (matching `render_recovery_mode_notice` pattern) |

### No new dependencies required

All needed machinery exists. The deprecation notice is a new method on `Admin` wired via `admin_notices`/`network_admin_notices` — identical to the `render_recovery_mode_notice` pattern already present and tested.

---

## Architecture Patterns

### BRK-01: Hard removal of `sudo_can()`

**Current state (verified):** `includes/functions-governance.php` lines 90–112 define `sudo_can()` inside an `if ( ! function_exists( 'sudo_can' ) )` guard block. The function body calls `_deprecated_function()` (guarded by another `function_exists`) then delegates to `wp_sudo_can()`.

**Removal:** Delete the entire `if ( ! function_exists( 'sudo_can' ) ) { ... }` block (lines 90–112). No internal callers exist anywhere in production code (verified: `grep -rn "sudo_can(" includes/ wp-sudo.php uninstall.php` returns only the definition itself).

**What survives:** `wp_sudo_can()` at lines 60–88 remains unchanged including its collision-avoidance guard semantics (the `function_exists` is on `sudo_can`, not on `wp_sudo_can`).

**Test approach for "function does not exist":** the unit test suite defines `sudo_can()` via the live file in `tests/bootstrap.php`. After removal, `function_exists('sudo_can')` will be `false` in both unit and integration environments. The correct assertion is:

```php
// Unit test (GovernanceTest.php) — confirm function is gone after removal
public function test_sudo_can_alias_no_longer_exists(): void {
    $this->assertFalse( function_exists( 'sudo_can' ) );
}
```

This works in unit tests because `functions-governance.php` is loaded at line 336 of `tests/bootstrap.php` — after removal, `sudo_can` will simply not be defined. Do NOT attempt to assert a fatal; instead use the integration test to confirm no callers remain (this is architectural, not a runtime assertion).

**Existing test to remove:** `test_deprecated_sudo_can_alias_delegates_and_warns()` in `GovernanceTest.php` (line 375) tests the alias behavior that will no longer exist. This test must be replaced with the existence check above.

### BRK-02: Remove `compatibility` governance mode

**Current state (verified):**

`wp_sudo_can()` (lines 60–88):
- Lines 82–85: `if ( 'compatibility' === get_option(...) ) { $fallback = ...; return user_can( $user_id, $fallback ); }`

`wp_sudo_map_governance_meta_cap()` (lines 149–173):
- Lines 168–170: `if ( 'compatibility' === get_option(...) ) { return array( ...'manage_options'... ); }`

**After removal:** both functions fall through to strict mode (`return user_can($user_id, $cap)` and `return array($cap)` respectively). The default for `get_option('wp_sudo_governance_mode', 'strict')` already handles this correctly once the `compatibility` branch is gone.

**Docblock updates required:**
- Lines 47–49: remove the `compatibility` bullet from the "3. Governance mode" comment block in `wp_sudo_can()`
- Line 52: remove `@since 3.3.0 Renamed...` is fine to keep; update the `@param` for `$cap` if it mentions compatibility
- Lines 156–160: remove the `compatibility` branch description in `wp_sudo_map_governance_meta_cap()`

**Tests to remove or update in `GovernanceTest.php`:**

The following 7 test methods cover `compatibility` mode behavior and must be **deleted** after the branch is removed (they will fail on the removed code path):

| Method | Line | What it Tests |
|--------|------|---------------|
| `test_map_governance_meta_cap_compatibility_single_site_maps_to_manage_options` | 45 | Meta cap compatibility → manage_options |
| `test_map_governance_meta_cap_compatibility_multisite_maps_to_manage_network_options` | 56 | Meta cap compatibility → manage_network_options |
| `test_sudo_can_compatibility_single_site_checks_manage_options` | 177 | `wp_sudo_can()` compat → manage_options |
| `test_sudo_can_compatibility_single_site_returns_false_without_manage_options` | 192 | `wp_sudo_can()` compat → false when no manage_options |
| `test_sudo_can_compatibility_multisite_checks_manage_network_options` | 204 | `wp_sudo_can()` compat → manage_network_options |
| `test_deprecated_sudo_can_alias_delegates_and_warns` | 375 | `sudo_can()` alias behavior (BRK-01 overlap) |

The existing strict-mode, super-admin, and recovery-mode tests remain valid and unchanged.

**New test to add** (covers the transition): a test that confirms `get_option(...)` returning `'compatibility'` no longer changes the behavior of `wp_sudo_can()` — it falls through to strict mode:

```php
public function test_sudo_can_treats_compatibility_as_strict_after_removal(): void {
    // After removal, 'compatibility' is just an unrecognized option value.
    // The function falls through to strict-mode user_can().
    Functions\when( 'is_multisite' )->justReturn( false );
    Functions\when( 'get_option' )->justReturn( 'compatibility' );
    Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( false );
    Functions\expect( 'user_can' )
        ->once()
        ->with( 42, 'manage_wp_sudo' ) // strict: checks the actual cap
        ->andReturn( true );

    $this->assertTrue( wp_sudo_can( 'manage_wp_sudo', 42 ) );
}
```

### BRK-03: Persistent admin notice + `_doing_it_wrong()`

**Recommended home:** A new `render_compatibility_mode_notice()` private method on the `Admin` class, registered on `admin_notices` and `network_admin_notices` in `Admin::__construct()` (or wherever `Admin` wires its hooks). This matches the existing `render_recovery_mode_notice()` pattern (lines 3185–3210 in AdminTest show the established test approach).

**Conditions the notice must satisfy:**
1. Fires only on admin page loads (hook context guarantees this)
2. Gates on `wp_sudo_can('manage_wp_sudo')` — audience is plugin managers
3. Checks `get_option('wp_sudo_governance_mode') === 'compatibility'`
4. If condition met: echoes `notice notice-warning wp-sudo-notice` div (NOT `is-dismissible`)
5. Also calls `_doing_it_wrong( 'wp_sudo_governance_mode', ..., '4.0.0' )`

**Notice markup pattern (from existing `render_gate_notice`, NOT dismissible):**
```php
printf(
    '<div class="notice notice-warning wp-sudo-notice" role="alert"><p>%s</p></div>',
    wp_kses_post( $message )
);
```

**`_doing_it_wrong()` placement:** call it before the `printf`, once per page load when the condition is true. The `_doing_it_wrong()` message should name the option key, explain that `compatibility` mode was removed in 4.0.0, and state that governance now uses strict mode.

**Brain\Monkey test pattern for `_doing_it_wrong()`:**
```php
Functions\expect( '_doing_it_wrong' )
    ->once()
    ->with(
        'wp_sudo_governance_mode',
        \Mockery::type( 'string' ),
        '4.0.0'
    );
```

**Full test structure for the notice:**

```php
// Fires notice when option is 'compatibility' and user has manage_wp_sudo
public function test_compatibility_notice_renders_when_option_is_stale(): void {
    Functions\when( 'get_option' )->justReturn( 'compatibility' );
    Functions\when( 'wp_sudo_can' )->justReturn( true );
    Functions\when( '__' )->returnArg();
    Functions\when( 'esc_html__' )->returnArg();
    Functions\when( 'wp_kses_post' )->returnArg();
    Functions\expect( '_doing_it_wrong' )->once();

    $method = new \ReflectionMethod( Admin::class, 'render_compatibility_mode_notice' );
    @$method->setAccessible( true ); // required on PHP 8.0, no-op 8.1+, deprecated 8.5+
    ob_start();
    $method->invoke( new Admin() );
    $output = ob_get_clean();

    $this->assertStringContainsString( 'notice-warning', $output );
    $this->assertStringNotContainsString( 'is-dismissible', $output );
}

// Skips notice when user lacks manage_wp_sudo
public function test_compatibility_notice_skips_when_user_lacks_authority(): void {
    Functions\when( 'get_option' )->justReturn( 'compatibility' );
    Functions\when( 'wp_sudo_can' )->justReturn( false );
    Functions\expect( '_doing_it_wrong' )->never();

    $method = new \ReflectionMethod( Admin::class, 'render_compatibility_mode_notice' );
    @$method->setAccessible( true );
    ob_start();
    $method->invoke( new Admin() );
    $this->assertSame( '', ob_get_clean() );
}

// Skips notice when option is not 'compatibility'
public function test_compatibility_notice_skips_when_option_is_not_compatibility(): void {
    Functions\when( 'get_option' )->justReturn( 'strict' );
    Functions\when( 'wp_sudo_can' )->justReturn( true );
    Functions\expect( '_doing_it_wrong' )->never();

    $method = new \ReflectionMethod( Admin::class, 'render_compatibility_mode_notice' );
    @$method->setAccessible( true );
    ob_start();
    $method->invoke( new Admin() );
    $this->assertSame( '', ob_get_clean() );
}
```

### BRK-04 / BRK-05: Floor bump — complete list of change sites

**WordPress 6.2 → 6.4 (verified against live files):**

| File | Current | Change |
|------|---------|--------|
| `wp-sudo.php` line 7 | `Requires at least: 6.2` | → `Requires at least: 6.4` |
| `readme.txt` line 5 | `Requires at least: 6.2` | → `Requires at least: 6.4` |
| `.github/workflows/phpunit.yml` line 177 | `wp: '6.2'` (support-floor integration matrix entry, php 8.0 lane) | → `wp: '6.4'` |
| `.github/workflows/plugin-check.yml` line 103 | Comment references `newer-than-6.2` | Update comment to `newer-than-6.4` |

Note: `compat-wordpress-minors.yml` already tests WP 6.3, 6.4, 6.5, 6.6 — its floor entry is 6.3. After the bump, the 6.3 lane is below the new minimum; consider whether to keep it (for integration context) or remove it. Recommend keeping 6.3 lane removal as Claude's discretion — it provides no value once 6.4 is the minimum.

**PHP 8.0 → 8.2 (verified against live files):**

| File | Current | Change |
|------|---------|--------|
| `wp-sudo.php` line 8 | `Requires PHP: 8.0` | → `Requires PHP: 8.2` |
| `readme.txt` line 7 | `Requires PHP: 8.0` | → `Requires PHP: 8.2` |
| `composer.json` line 6 | `"php": ">=8.0"` | → `"php": ">=8.2"` |
| `composer.json` lines 37–39 (`config.platform.php`) | `"php": "8.1.99"` | → `"php": "8.2.0"` |
| `.github/workflows/phpunit.yml` line 71 | `php: ['8.0', '8.1', '8.2', '8.3', '8.4']` | → `php: ['8.2', '8.3', '8.4']` (drop 8.0 and 8.1 from unit-tests matrix) |
| `.github/workflows/phpunit.yml` line 176 | matrix entry `php: '8.0'` / `wp: '6.2'` (floor lane) | → `php: '8.2'` / `wp: '6.4'` |
| `.github/workflows/phpunit.yml` conditional blocks (lines 96–97, 112–113, 271–272, 287–289) | `if [ "${{ matrix.php }}" = "8.0" ]` special-case for `.github/php80-tests/` | Remove these conditionals entirely once 8.0 lane is dropped |

**php80-tests directory:** `.github/php80-tests/` is a separate Composer configuration with its own `vendor/` tree providing PHP 8.0-compatible Brain\Monkey/PHPUnit. Once the 8.0 lane is removed from CI, this entire directory and its `composer.json`/`composer.lock`/`vendor/` become dead weight. The plan should include removing `.github/php80-tests/` as part of the PHP floor bump.

### BRK-06: Verify-then-close (no code removal)

**Verified finding (confirmed against live `includes/class-admin.php`):**

`grep -rn "wp_get_admin_notice" includes/ wp-sudo.php` → **zero matches**

The only `function_exists()` guards in `class-admin.php` are at lines 383, 616, and 1295, all guarding `graphql` (a legitimate runtime-integration check, not a WP 6.4 shim). The STATE.md reference to "class-admin.php lines 2442 and 2475" is stale — these lines contain unrelated code, no `wp_get_admin_notice` shim was ever present (or has already been removed in a prior release).

BRK-06 task: run the grep, confirm no matches, update `REQUIREMENTS.md` to mark BRK-06 satisfied/obsolete, record in SUMMARY.

### BRK-07: Migration documentation

Three distinct deliverables:

1. **CHANGELOG.md "Unreleased" section** — add a "Breaking changes" subsection (bold heading, not a new `##` level) covering:
   - `sudo_can()` removed — use `wp_sudo_can()`
   - `compatibility` governance mode removed — governance is now always strict; `WP_SUDO_RECOVERY_MODE` is the only break-glass
   - Minimum WordPress raised to 6.4
   - Minimum PHP raised to 8.2

2. **`readme.txt` Upgrade Notice** — add a `= 4.0.0 =` entry to the `== Upgrade Notice ==` section explaining the three breaking changes at a high level with action items.

3. **`docs/developer-reference.md` "Migrating to 4.0" section** — a durable section that integrators can reference long-term. It should cover:
   - `sudo_can()` → `wp_sudo_can()` search-replace
   - `wp_sudo_governance_mode = 'compatibility'` behavior: now treated as strict; update option or delete it
   - WordPress 6.4 and PHP 8.2 as new minimums and what that unlocks (e.g., `wp_get_admin_notice()` always available)
   - Note that the existing `sudo_can()` deprecation warning in the current docblock (line 247–248) promised removal in 4.0.0 — that promise is now delivered

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Test isolation for removed functions | Custom autoload tricks, `runkit` | `function_exists()` assertion in integration test | Brain\Monkey cannot "un-define" a PHP function; asserting absence is simpler |
| Notice dismissal state | Custom transient or option | Nothing — notice is persistent-until-resolved | CONTEXT decision: not dismissible; clears when option changes (Phase 13) |
| "Function does not exist" fatal testing | Trying to call `sudo_can()` and assert a fatal | `assertFalse(function_exists('sudo_can'))` | Fatals end the process; `function_exists` is the correct test seam |

---

## Common Pitfalls

### Pitfall 1: Leaving GovernanceTest compatibility tests as-is

**What goes wrong:** After removing the `compatibility` branch, the 5+ unit tests that `Functions\when('get_option')->justReturn('compatibility')` and assert that `user_can()` is called with `'manage_options'` will fail — because the `compatibility` branch no longer exists and the code falls through to the strict-mode `user_can($user_id, $cap)` call instead.

**How to avoid:** The TDD cycle for BRK-02 must start by updating GovernanceTest first — remove the 7 compatibility-mode tests (RED: they're now orphaned), then remove the code branch (GREEN), then add the "stale-option-falls-through-to-strict" test.

### Pitfall 2: Forgetting `config.platform.php`

**What goes wrong:** `composer.json` has both a `require.php` key (line 6) AND a `config.platform.php` key (lines 37–39). The platform key controls what packages Composer resolves against — it's currently pinned to `8.1.99`. If only `require.php` is bumped to `>=8.2`, the lock file still resolves against PHP 8.1 semantics.

**How to avoid:** Bump both. Set `config.platform.php` to `"8.2.0"` when setting `require.php` to `">=8.2"`. Run `composer update --lock` (or full `composer update`) to regenerate the lock file with the new platform.

### Pitfall 3: Missing CI matrix entries for 8.0/8.1 unit-test special case

**What goes wrong:** The unit-test job has a PHP matrix at line 71 AND special-case conditionals at lines 96–97 and 112–113 that test for `matrix.php == "8.0"` to use the `.github/php80-tests/` vendor tree. If only the matrix is updated (dropping 8.0/8.1) but the conditionals are left, they become dead code. If the matrix is correct but the `php80-tests` directory is not removed, CI succeeds but dead files remain.

**How to avoid:** Remove the conditionals AND the `.github/php80-tests/` directory together.

### Pitfall 4: `_doing_it_wrong()` in unit tests — it IS a WordPress function

**What goes wrong:** In `functions-governance.php`, the current `sudo_can()` already guards `_deprecated_function()` with `function_exists`. The new `_doing_it_wrong()` call in the notice method does NOT need a `function_exists` guard — `_doing_it_wrong()` is a WordPress core function available at `plugins_loaded` (CLAUDE.md: no shims for symbols guaranteed by WP core at load point). Brain\Monkey handles the mock in unit tests via `Functions\expect('_doing_it_wrong')`.

**How to avoid:** Do not add a `function_exists('_doing_it_wrong')` guard. Mock it with Brain\Monkey in tests.

### Pitfall 5: `@$method->setAccessible(true)` pattern is NOT a shim

**What goes wrong:** One might interpret CLAUDE.md's "No Test-Environment Shims in Production" to mean the `@$method->setAccessible(true)` pattern in tests should be removed once the PHP floor is 8.2 (where `setAccessible` is fully a no-op).

**Resolution:** The `@$method->setAccessible(true)` calls are in TEST code, not production code. They are harmless no-ops in PHP 8.1+ and PHP 8.2+. The `@` suppressor prevents a deprecation notice in PHP 8.5+. This pattern is already correct, already established, and does NOT need to change as part of this floor bump. Leave it as-is.

---

## Code Examples

### How to use `Functions\expect('_doing_it_wrong')` in Brain\Monkey

```php
// Source: verified against existing test patterns in tests/Unit/GovernanceTest.php
// and tests/Unit/AdminTest.php (3216–3218: Actions\expectDone pattern)
Functions\expect( '_doing_it_wrong' )
    ->once()
    ->with(
        'wp_sudo_governance_mode',            // function/option name
        \Mockery::type( 'string' ),           // human-readable message
        '4.0.0'                               // version where it became wrong
    );
```

### Existing notice test pattern (ob_start + ReflectionMethod)

```php
// Source: tests/Unit/AdminTest.php lines 3186–3210 (render_recovery_mode_notice tests)
ob_start();
$method = new \ReflectionMethod( Admin::class, 'render_compatibility_mode_notice' );
@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
$method->invoke( $admin );
$output = ob_get_clean();

$this->assertStringContainsString( 'notice-warning', $output );
$this->assertStringNotContainsString( 'is-dismissible', $output );
```

### Integration test — confirm sudo_can function does not exist

```php
// In tests/Integration/ (real PHP environment, no Brain\Monkey)
public function test_sudo_can_alias_was_removed(): void {
    $this->assertFalse(
        function_exists( 'sudo_can' ),
        'sudo_can() must not exist after 4.0.0 hard removal'
    );
}
```

### Non-test assertion for compatibility → strict fallthrough

```php
// functions-governance.php after removal — what remains in wp_sudo_can():
// (no compatibility branch; falls straight through to strict)
return user_can( $user_id, $cap );
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `sudo_can()` unprefixed alias | `wp_sudo_can()` (3.3.0) | 3.3.0 | Alias finally removed in 4.0.0 |
| `compatibility` governance mode (option) | Always strict; `WP_SUDO_RECOVERY_MODE` only | 4.0.0 | Simplified governance contract |
| PHP 8.0 support (`.github/php80-tests/`) | PHP 8.2 minimum | 4.0.0 | Removes special CI vendor tree |
| `Requires at least: 6.2` | `Requires at least: 6.4` | 4.0.0 | `wp_get_admin_notice()` unconditionally available |

**Removed/obsolete:**
- `if ( ! function_exists( 'sudo_can' ) )` block in `functions-governance.php`
- `if ( 'compatibility' === get_option(...) )` branch in `wp_sudo_can()`
- `if ( 'compatibility' === get_option(...) )` branch in `wp_sudo_map_governance_meta_cap()`
- `.github/php80-tests/` directory and its CI conditionals
- GovernanceTest compatibility-mode test methods (7 methods)

---

## Open Questions

1. **`compat-wordpress-minors.yml` 6.3 lane**
   - What we know: this workflow tests WP 6.3, 6.4, 6.5, 6.6. After raising the floor to 6.4, the WP 6.3 lane tests below-minimum.
   - What's unclear: the intent of the compat sweep — if it's "verify we work on versions just below the announced floor" then 6.3 can stay. If it's "verify the stated minimums," drop it.
   - Recommendation: Claude's discretion — remove the 6.3 lane and update the lowest lane to WP 6.4.

2. **`composer.lock` regeneration after platform bump**
   - What we know: changing `config.platform.php` from `8.1.99` to `8.2.0` may or may not produce lock-file changes, depending on whether any dev dependency requires a version conditional on PHP 8.2 features.
   - What's unclear: whether `yoast/phpunit-polyfills ^4.0`, `brain/monkey ^2.7`, etc. have different resolutions under 8.1 vs 8.2.
   - Recommendation: run `composer update --lock` locally as part of the floor-bump commit and commit the updated `composer.lock`.

3. **`plugin-check.yml` comment update scope**
   - The comment at line 103 says "the only newer-than-6.2 calls in the plugin." After the floor bump to 6.4, `wp_get_admin_notice()` and any other 6.4+ functions are no longer "newer than the floor." The comment should be updated to reflect 6.4 and to confirm the only remaining newer-than-floor calls are WP 7.0+ (`wp_get_connectors()`, still guarded by `function_exists()`).
   - Recommendation: update the comment as part of the floor-bump commit.

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 9.6 |
| Unit config | `phpunit.xml.dist` |
| Integration config | `phpunit-integration.xml.dist` |
| Quick run command | `composer test:unit` (~0.3s) |
| Full suite command | `composer test:unit && composer test:integration` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Test Location | Automated Command | Notes |
|--------|----------|-----------|---------------|-------------------|-------|
| BRK-01 | `sudo_can()` does not exist | Unit + Integration | `GovernanceTest.php` (unit); new `GovernanceTest.php`/`PublicApiTest.php` (integration) | `composer test:unit` | Replace `test_deprecated_sudo_can_alias_delegates_and_warns` with `test_sudo_can_alias_no_longer_exists`; add integration assertion |
| BRK-01 | `wp_sudo_can()` still works correctly | Unit | `GovernanceTest.php` (existing strict/recovery/super-admin tests) | `composer test:unit` | No change needed — existing tests cover this |
| BRK-02 | `wp_sudo_can()` with stale `compatibility` option falls through to strict | Unit | `GovernanceTest.php` | `composer test:unit` | New test: `test_sudo_can_treats_compatibility_as_strict_after_removal` |
| BRK-02 | `wp_sudo_map_governance_meta_cap()` with stale `compatibility` option maps to the cap itself (strict) | Unit | `GovernanceTest.php` | `composer test:unit` | New test: `test_map_governance_meta_cap_treats_compatibility_as_strict_after_removal` |
| BRK-02 | All compatibility-mode tests removed from `GovernanceTest` | Unit | `GovernanceTest.php` | `composer test:unit` | 7 methods must be deleted; suite must still pass |
| BRK-03 | Notice renders when `wp_sudo_governance_mode === 'compatibility'` and user has `manage_wp_sudo` | Unit | `AdminTest.php` | `composer test:unit` | New test using `ob_start()`/`ob_get_clean()` + `ReflectionMethod` |
| BRK-03 | Notice skips when user lacks `manage_wp_sudo` | Unit | `AdminTest.php` | `composer test:unit` | New test |
| BRK-03 | Notice skips when option is not `compatibility` | Unit | `AdminTest.php` | `composer test:unit` | New test |
| BRK-03 | `_doing_it_wrong()` fires when notice condition is true | Unit | `AdminTest.php` | `composer test:unit` | `Functions\expect('_doing_it_wrong')->once()` |
| BRK-03 | `_doing_it_wrong()` does NOT fire when condition is false | Unit | `AdminTest.php` | `composer test:unit` | `Functions\expect('_doing_it_wrong')->never()` |
| BRK-03 | Notice HTML is non-dismissible (`notice-warning` present, `is-dismissible` absent) | Unit | `AdminTest.php` | `composer test:unit` | `assertStringContainsString`/`assertStringNotContainsString` |
| BRK-03 | Notice hooks are registered on `admin_notices` and `network_admin_notices` | Unit | `AdminTest.php` | `composer test:unit` | `Actions\expectAdded('admin_notices', ...)` |
| BRK-04 | Plugin header declares `Requires at least: 6.4` | Manual verification / grep | `wp-sudo.php` | `grep "Requires at least" wp-sudo.php` | Part of design-review checklist |
| BRK-04 | `readme.txt` declares `Requires at least: 6.4` | Manual verification / grep | `readme.txt` | `grep "Requires at least" readme.txt` | Part of design-review checklist |
| BRK-04 | CI support-floor integration lane uses WP 6.4 | Manual verification | `phpunit.yml` | `grep "wp: '6.4'" .github/workflows/phpunit.yml` | Confirm old `6.2` entry is gone |
| BRK-05 | Plugin header declares `Requires PHP: 8.2` | Manual verification / grep | `wp-sudo.php` | `grep "Requires PHP" wp-sudo.php` | Part of checklist |
| BRK-05 | `readme.txt` declares `Requires PHP: 8.2` | Manual verification / grep | `readme.txt` | `grep "Requires PHP" readme.txt` | Part of checklist |
| BRK-05 | `composer.json` `require.php` is `>=8.2` | Manual verification | `composer.json` | `grep '"php"' composer.json` | Part of checklist |
| BRK-05 | `composer.json` `config.platform.php` is `8.2.0` | Manual verification | `composer.json` | `grep "platform" -A 2 composer.json` | Part of checklist |
| BRK-06 | No `wp_get_admin_notice` shims exist in production code | Automated grep | All `includes/` PHP files | `grep -rn "wp_get_admin_notice" includes/ wp-sudo.php` → zero output | Record as satisfied/obsolete |
| BRK-07 | CHANGELOG has 4.0.0 breaking-changes block | Manual review | `CHANGELOG.md` | Visual inspection | Three items: `sudo_can`, `compatibility`, WP/PHP floors |
| BRK-07 | `readme.txt` has `= 4.0.0 =` Upgrade Notice | Manual review | `readme.txt` | `grep "4.0.0" readme.txt` | Part of checklist |
| BRK-07 | `docs/developer-reference.md` has "Migrating to 4.0" section | Manual review | `docs/developer-reference.md` | `grep "Migrating to 4.0" docs/developer-reference.md` | Part of checklist |

### Sampling Rate

- **Per task commit:** `composer test:unit` (~0.3 s)
- **Per wave merge:** `composer test:unit && composer analyse`
- **Phase gate:** Full suite green (`composer test:unit`) + PHPStan L6 clean before `/gsd:verify-work`

### Wave 0 Gaps

None — existing test infrastructure covers all phase requirements. New test methods are added to the existing `GovernanceTest.php` and `AdminTest.php` files, following established patterns. No new test files, fixtures, or framework configuration required.

---

## Sources

### Primary (HIGH confidence)
- Live source: `includes/functions-governance.php` — verified all line numbers for `sudo_can()` and `compatibility` branches
- Live source: `tests/Unit/GovernanceTest.php` — enumerated all 7 compatibility-mode test methods to remove
- Live source: `.github/workflows/phpunit.yml` — verified PHP matrix (line 71), WP floor matrix entry (line 176–177), and php80-tests conditionals (lines 96–97, 112–113, 271–272, 287–289)
- Live source: `composer.json` — verified `require.php` (line 6) and `config.platform.php` (lines 37–39)
- Live source: `wp-sudo.php` lines 7–8 — verified `Requires at least: 6.2` and `Requires PHP: 8.0`
- Live source: `readme.txt` lines 5, 7 — verified floor declarations
- Live source: `tests/Unit/AdminTest.php` lines 3185–3210 — verified `render_recovery_mode_notice` test pattern (the template for BRK-03 tests)
- Live source: `tests/bootstrap.php` line 336 — verified `functions-governance.php` is loaded from the live file (so `sudo_can` disappearance is testable via `function_exists`)
- Live source: `includes/class-admin.php` lines 383, 616, 1295 — verified BRK-06: only `graphql` guards, no `wp_get_admin_notice`
- Live source: `tests/Unit/GateTest.php` lines 2862–3026 — verified `expectOutputRegex` / `ob_start` notice test pattern

### Secondary (MEDIUM confidence)
- `.github/workflows/compat-wordpress-minors.yml` — verified WP 6.3 is the current lowest lane in the compat sweep; 6.3 lane is below the new floor after bump

### Tertiary (LOW confidence)
- None

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — all tooling verified against live composer.json and test files
- Architecture: HIGH — all line numbers and class locations verified against live source
- Pitfalls: HIGH — derived from actual live code state and established project patterns

**Research date:** 2026-06-16
**Valid until:** 2026-09-01 (stable codebase; invalidated if `functions-governance.php` or CI workflows change significantly before Phase 12 executes)
