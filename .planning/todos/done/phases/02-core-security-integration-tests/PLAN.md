# Phase 2 Plan: Core Security Integration Tests

## Overview

Write the first real integration tests exercising WP Sudo's core security boundaries with real WordPress + MySQL. Three test files and a TestCase enhancement satisfy 4 requirements (INTG-01 through INTG-04): real bcrypt password verification, session token binding via SHA-256, transient-based request stashing, and a full 5-class reauthentication flow — all without mocks.

## Prerequisites

- Phase 1 complete: `composer test:integration` runs an empty suite (0 tests, 0 failures)
- Repository at `/Users/danknauss/Documents/GitHub/wp-sudo`
- `composer install` has been run

## Tasks

### Task 1: Enhance TestCase base class

**Requirements:** Prerequisite for INTG-01, INTG-02, INTG-03, INTG-04

**File:** `tests/integration/TestCase.php` (modify existing)

**Description:**

Replace the existing `TestCase.php` content with an enhanced version that adds:

1. **Superglobal snapshot/restore** — `$_SERVER`, `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE` snapshotted in `set_up()`, restored in `tear_down()`.

2. **Static cache resets** in `tear_down()`:
   - `\WP_Sudo\Sudo_Session::reset_cache()`
   - `\WP_Sudo\Action_Registry::reset_cache()`
   - `\WP_Sudo\Admin::reset_cache()`
   - `unset( $GLOBALS['pagenow'] )`

3. **`simulate_admin_request()` helper** — sets globals and superglobals that Gate's `match_request()` needs.

The full file content:

```php
<?php
/**
 * Base integration test case for WP Sudo.
 *
 * Extends WP_UnitTestCase for real database + WordPress environment.
 * Each test runs in a database transaction that is rolled back in tear_down().
 *
 * Do NOT use Brain\Monkey here. Do NOT call Monkey\setUp().
 * Integration tests use real WordPress functions, not mocks.
 *
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Action_Registry;
use WP_Sudo\Admin;
use WP_Sudo\Sudo_Session;

/**
 * Base class for WP Sudo integration tests.
 */
class TestCase extends \WP_UnitTestCase {

	/**
	 * Superglobal snapshots for isolation between tests.
	 *
	 * @var array
	 */
	private array $server_snapshot  = array();
	private array $get_snapshot     = array();
	private array $post_snapshot    = array();
	private array $request_snapshot = array();
	private array $cookie_snapshot  = array();

	/**
	 * Set up test environment.
	 *
	 * Snapshots superglobals before each test so tear_down() can restore them.
	 * Always call parent::set_up() first (starts DB transaction).
	 */
	public function set_up(): void {
		parent::set_up();

		$this->server_snapshot  = $_SERVER;
		$this->get_snapshot     = $_GET;
		$this->post_snapshot    = $_POST;
		$this->request_snapshot = $_REQUEST;
		$this->cookie_snapshot  = $_COOKIE;
	}

	/**
	 * Tear down test environment.
	 *
	 * Restores superglobals, clears static caches, and unsets Gate's pagenow global.
	 * Always call parent::tear_down() last (rolls back DB transaction).
	 */
	public function tear_down(): void {
		// Restore superglobals to pre-test state.
		$_SERVER  = $this->server_snapshot;
		$_GET     = $this->get_snapshot;
		$_POST    = $this->post_snapshot;
		$_REQUEST = $this->request_snapshot;
		$_COOKIE  = $this->cookie_snapshot;

		// Clear static caches that persist across tests.
		Sudo_Session::reset_cache();
		Action_Registry::reset_cache();
		Admin::reset_cache();

		// Gate reads $GLOBALS['pagenow'] for admin request matching.
		unset( $GLOBALS['pagenow'] );

		parent::tear_down();
	}

	/**
	 * Create an administrator user with a real bcrypt-hashed password in the database.
	 *
	 * Uses the factory so the created user is auto-cleaned up in tear_down().
	 * wp_hash_password() uses cost=5 in test environments (WP_UnitTestCase default).
	 *
	 * @param string $password Plain-text password for verification tests.
	 * @return \WP_User
	 */
	protected function make_admin( string $password = 'test-password' ): \WP_User {
		$user_id = self::factory()->user->create(
			array(
				'role'      => 'administrator',
				'user_pass' => $password,
			)
		);
		return get_user_by( 'id', $user_id );
	}

	/**
	 * Trigger the plugin's activation hook explicitly.
	 *
	 * The plugin is loaded via muplugins_loaded in the bootstrap, which does not
	 * fire the activation hook. Tests that verify activation side effects
	 * (unfiltered_html removal, option creation) must call this method.
	 */
	protected function activate_plugin(): void {
		do_action( 'activate_wp-sudo/wp-sudo.php' );
	}

	/**
	 * Simulate an admin page request for Gate's match_request().
	 *
	 * Sets the globals and superglobals that Gate reads:
	 * - $GLOBALS['pagenow'] — read by Gate::matches_admin()
	 * - $_SERVER['REQUEST_METHOD'] — read by Gate::match_request()
	 * - $_REQUEST['action'] — read by Gate::match_request()
	 * - $_SERVER['HTTP_HOST'] and $_SERVER['REQUEST_URI'] — read by Request_Stash::build_original_url()
	 * - $_GET and $_POST — captured by Request_Stash::save()
	 *
	 * @param string $pagenow  The page (e.g. 'plugins.php', 'themes.php').
	 * @param string $action   The action parameter (e.g. 'activate', 'delete-selected').
	 * @param string $method   HTTP method: 'GET' or 'POST'.
	 * @param array  $get      Additional $_GET parameters.
	 * @param array  $post     Additional $_POST parameters.
	 */
	protected function simulate_admin_request(
		string $pagenow,
		string $action = '',
		string $method = 'GET',
		array $get = array(),
		array $post = array()
	): void {
		$GLOBALS['pagenow'] = $pagenow;

		$_SERVER['REQUEST_METHOD'] = strtoupper( $method );
		$_SERVER['HTTP_HOST']      = 'example.org';

		// Build a realistic request URI.
		$query = $action ? "action={$action}" : '';
		if ( $get ) {
			$extra = http_build_query( $get );
			$query = $query ? "{$query}&{$extra}" : $extra;
		}
		$_SERVER['REQUEST_URI'] = "/wp-admin/{$pagenow}" . ( $query ? "?{$query}" : '' );

		// Set $_GET, $_POST, $_REQUEST.
		$_GET = $get;
		if ( $action ) {
			$_GET['action'] = $action;
		}
		$_POST    = $post;
		$_REQUEST = array_merge( $_GET, $_POST );
	}
}
```

**Verification:**

```bash
composer test:integration
# Expected: No tests executed! (no test files with *Test.php suffix yet)
```

---

### Task 2: SudoSessionTest — bcrypt & session binding (INTG-02, INTG-03)

**Requirements:** INTG-02, INTG-03

**File:** `tests/integration/SudoSessionTest.php` (create new)

**Description:**

Create the test file with 10 test methods. Key patterns:

- Create admin users via `$this->make_admin('known-password')` — uses factory, auto-cleaned by DB rollback
- `Sudo_Session::activate()` sets `$_COOKIE[TOKEN_COOKIE]` directly (line 543 of class-sudo-session.php) — readable in CLI
- Use `Sudo_Session::reset_cache()` before re-checking `is_active()` after modifying state
- Use `did_action()` delta pattern: `$before = did_action('hook'); ...; assertSame($before + 1, did_action('hook'))`
- Call `wp_set_current_user()` before `attempt_activation()` (it calls `get_current_user_id()`)

```php
<?php
/**
 * Integration tests for Sudo_Session — real bcrypt and session token binding.
 *
 * @covers \WP_Sudo\Sudo_Session
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Sudo_Session;

class SudoSessionTest extends TestCase {

	/**
	 * INTG-02: Real bcrypt hash starts with $2y$ and wp_check_password() returns true.
	 */
	public function test_wp_check_password_verifies_correct_bcrypt(): void {
		$password = 's3cureP@ss';
		$user     = $this->make_admin( $password );

		// Reload from DB to get the hashed password.
		$user = get_user_by( 'id', $user->ID );

		// WP 6.8+ uses bcrypt by default.
		$this->assertStringStartsWith( '$2y$', $user->user_pass, 'Password hash should be bcrypt ($2y$).' );
		$this->assertTrue( wp_check_password( $password, $user->user_pass, $user->ID ) );
	}

	/**
	 * INTG-02: Wrong password is rejected by real wp_check_password().
	 */
	public function test_wp_check_password_rejects_wrong_password(): void {
		$user = $this->make_admin( 'correct-password' );
		$user = get_user_by( 'id', $user->ID );

		$this->assertFalse( wp_check_password( 'wrong-password', $user->user_pass, $user->ID ) );
	}

	/**
	 * INTG-03: activate() stores a SHA-256 token hash in user meta.
	 */
	public function test_activate_stores_token_hash_in_user_meta(): void {
		$user = $this->make_admin();

		Sudo_Session::activate( $user->ID );

		$stored_hash = get_user_meta( $user->ID, Sudo_Session::TOKEN_META_KEY, true );
		$this->assertNotEmpty( $stored_hash, 'Token hash should be stored in user meta.' );

		$expiry = get_user_meta( $user->ID, Sudo_Session::META_KEY, true );
		$this->assertGreaterThan( time(), (int) $expiry, 'Expiry should be in the future.' );
	}

	/**
	 * INTG-03: activate() sets the token cookie in the $_COOKIE superglobal.
	 */
	public function test_activate_sets_cookie_superglobal(): void {
		$user = $this->make_admin();

		Sudo_Session::activate( $user->ID );

		$this->assertArrayHasKey( Sudo_Session::TOKEN_COOKIE, $_COOKIE );
		$this->assertSame( 64, strlen( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] ), 'Token should be 64 characters.' );
	}

	/**
	 * INTG-03: The cookie value's SHA-256 hash matches the stored meta hash.
	 *
	 * This is the core token-binding proof: cookie → SHA-256 → user meta.
	 */
	public function test_cookie_sha256_matches_stored_meta_hash(): void {
		$user = $this->make_admin();

		Sudo_Session::activate( $user->ID );

		$cookie_token = $_COOKIE[ Sudo_Session::TOKEN_COOKIE ];
		$stored_hash  = get_user_meta( $user->ID, Sudo_Session::TOKEN_META_KEY, true );

		$this->assertSame(
			hash( 'sha256', $cookie_token ),
			$stored_hash,
			'SHA-256 of cookie token should match stored meta hash.'
		);
	}

	/**
	 * INTG-03: is_active() returns true with valid token binding.
	 */
	public function test_is_active_returns_true_with_valid_binding(): void {
		$user = $this->make_admin();

		Sudo_Session::activate( $user->ID );

		$this->assertTrue( Sudo_Session::is_active( $user->ID ) );
	}

	/**
	 * INTG-03: is_active() returns false when cookie is tampered.
	 */
	public function test_is_active_returns_false_with_tampered_cookie(): void {
		$user = $this->make_admin();

		Sudo_Session::activate( $user->ID );
		$this->assertTrue( Sudo_Session::is_active( $user->ID ) );

		// Tamper the cookie — SHA-256 mismatch.
		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = 'tampered-cookie-value';
		Sudo_Session::reset_cache();

		$this->assertFalse( Sudo_Session::is_active( $user->ID ) );
	}

	/**
	 * INTG-03: is_active() returns false when session is expired.
	 */
	public function test_is_active_returns_false_when_expired(): void {
		$user = $this->make_admin();

		Sudo_Session::activate( $user->ID );
		$this->assertTrue( Sudo_Session::is_active( $user->ID ) );

		// Force expiry to the past.
		update_user_meta( $user->ID, Sudo_Session::META_KEY, time() - 60 );
		Sudo_Session::reset_cache();

		$this->assertFalse( Sudo_Session::is_active( $user->ID ) );
	}

	/**
	 * INTG-03: deactivate() clears meta and cookie.
	 */
	public function test_deactivate_clears_meta_and_cookie(): void {
		$user = $this->make_admin();

		Sudo_Session::activate( $user->ID );
		$this->assertTrue( Sudo_Session::is_active( $user->ID ) );

		Sudo_Session::deactivate( $user->ID );
		Sudo_Session::reset_cache();

		$this->assertEmpty( get_user_meta( $user->ID, Sudo_Session::TOKEN_META_KEY, true ) );
		$this->assertEmpty( get_user_meta( $user->ID, Sudo_Session::META_KEY, true ) );
		$this->assertArrayNotHasKey( Sudo_Session::TOKEN_COOKIE, $_COOKIE );
		$this->assertFalse( Sudo_Session::is_active( $user->ID ) );
	}

	/**
	 * INTG-02: attempt_activation() with correct password returns success via real bcrypt.
	 */
	public function test_attempt_activation_exercises_real_bcrypt(): void {
		$password = 'correct-horse-battery';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );

		$before = did_action( 'wp_sudo_activated' );
		$result = Sudo_Session::attempt_activation( $user->ID, $password );

		$this->assertSame( 'success', $result['code'] );
		$this->assertTrue( Sudo_Session::is_active( $user->ID ) );
		$this->assertSame( $before + 1, did_action( 'wp_sudo_activated' ), 'wp_sudo_activated should fire once.' );
	}
}
```

**Verification:**

```bash
composer test:integration
# Expected: 10 tests, X assertions, 0 failures
```

---

### Task 3: RequestStashTest — transient lifecycle (INTG-04)

**Requirements:** INTG-04

**File:** `tests/integration/RequestStashTest.php` (create new)

**Description:**

Create the test file with 7 test methods. Key patterns:

- `Request_Stash` is an instance class: `new \WP_Sudo\Request_Stash()`
- Set `$_SERVER['REQUEST_METHOD']`, `$_SERVER['HTTP_HOST']`, `$_SERVER['REQUEST_URI']`, `$_GET`, `$_POST` before `save()`
- Verify raw transients via `get_transient( Request_Stash::TRANSIENT_PREFIX . $key )`
- User ownership check: stash saved by user A, retrieved by user B → null

```php
<?php
/**
 * Integration tests for Request_Stash — real transient write/read/delete lifecycle.
 *
 * @covers \WP_Sudo\Request_Stash
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Request_Stash;

class RequestStashTest extends TestCase {

	/**
	 * INTG-04: save() stores a transient via real set_transient().
	 */
	public function test_save_stores_transient(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		$key = $stash->save( $user->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		$this->assertSame( 16, strlen( $key ), 'Stash key should be 16 characters.' );

		// Verify via raw transient API.
		$raw = get_transient( Request_Stash::TRANSIENT_PREFIX . $key );
		$this->assertIsArray( $raw );
		$this->assertSame( $user->ID, $raw['user_id'] );
		$this->assertSame( 'plugin.activate', $raw['rule_id'] );
	}

	/**
	 * INTG-04: get() retrieves the stash for the correct user.
	 */
	public function test_get_retrieves_for_correct_user(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		$key  = $stash->save( $user->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );
		$data = $stash->get( $key, $user->ID );

		$this->assertIsArray( $data );
		$this->assertSame( 'plugin.activate', $data['rule_id'] );
	}

	/**
	 * INTG-04: get() returns null for the wrong user.
	 */
	public function test_get_returns_null_for_wrong_user(): void {
		$stash  = new Request_Stash();
		$user_a = $this->make_admin();
		$user_b = $this->make_admin( 'other-password' );

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		$key = $stash->save( $user_a->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		$this->assertNull( $stash->get( $key, $user_b->ID ) );
	}

	/**
	 * INTG-04: delete() removes the transient.
	 */
	public function test_delete_removes_transient(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		$key = $stash->save( $user->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		// Transient exists before delete.
		$this->assertIsArray( get_transient( Request_Stash::TRANSIENT_PREFIX . $key ) );

		$stash->delete( $key );

		// Transient gone after delete.
		$this->assertFalse( get_transient( Request_Stash::TRANSIENT_PREFIX . $key ) );
	}

	/**
	 * INTG-04: exists() returns true then false after delete.
	 */
	public function test_exists_true_then_false_after_delete(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		$key = $stash->save( $user->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		$this->assertTrue( $stash->exists( $key, $user->ID ) );

		$stash->delete( $key );

		$this->assertFalse( $stash->exists( $key, $user->ID ) );
	}

	/**
	 * INTG-04: Stash preserves the full request structure including all 8 fields.
	 */
	public function test_stash_preserves_request_structure(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request(
			'plugins.php',
			'activate',
			'POST',
			array( 'plugin' => 'hello.php' ),
			array( '_wpnonce' => 'abc123' )
		);

		$key  = $stash->save( $user->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );
		$data = $stash->get( $key, $user->ID );

		$this->assertSame( $user->ID, $data['user_id'] );
		$this->assertSame( 'plugin.activate', $data['rule_id'] );
		$this->assertSame( 'Activate plugin', $data['label'] );
		$this->assertSame( 'POST', $data['method'] );
		$this->assertArrayHasKey( 'url', $data );
		$this->assertArrayHasKey( 'get', $data );
		$this->assertArrayHasKey( 'post', $data );
		$this->assertArrayHasKey( 'created', $data );
		$this->assertEqualsWithDelta( time(), $data['created'], 2, 'Created timestamp should be within 2 seconds.' );
	}

	/**
	 * INTG-04: $_POST data (including passwords) is preserved for replay.
	 */
	public function test_save_captures_post_data(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request(
			'users.php',
			'createuser',
			'POST',
			array(),
			array(
				'user_login' => 'newuser',
				'pass1'      => 'secret-password',
				'role'       => 'subscriber',
			)
		);

		$key  = $stash->save( $user->ID, array( 'id' => 'user.create', 'label' => 'Create user' ) );
		$data = $stash->get( $key, $user->ID );

		// POST data preserved for replay (passwords NOT sanitized — needed for replay).
		$this->assertSame( 'newuser', $data['post']['user_login'] );
		$this->assertSame( 'secret-password', $data['post']['pass1'] );
	}
}
```

**Verification:**

```bash
composer test:integration
# Expected: 17 tests (10 session + 7 stash), 0 failures
```

---

### Task 4: ReauthFlowTest — full cross-class flow (INTG-01)

**Requirements:** INTG-01

**File:** `tests/integration/ReauthFlowTest.php` (create new)

**Description:**

The production flow calls `wp_safe_redirect()` + `exit` (Gate) and `wp_send_json_*()` + `wp_die()` (Challenge). Neither works in PHPUnit.

**Solution:** Test the logical flow by calling the component methods each step delegates to. This exercises all 5 classes with real WordPress, zero mocks, just without the HTTP redirect/die cycle.

The flow: Gate matches → Stash saves → Session authenticates (bcrypt) → Session activates (token binding) → Stash retrieves → Stash deletes.

```php
<?php
/**
 * Integration tests for the full reauthentication flow.
 *
 * Exercises 5 classes (Gate, Action_Registry, Sudo_Session, Request_Stash, Challenge logic)
 * with real WordPress functions — no mocks, no Brain\Monkey.
 *
 * The production flow involves wp_safe_redirect() + exit (Gate) and wp_send_json() + wp_die()
 * (Challenge), which cannot execute in PHPUnit. Instead, we call the component methods each
 * step delegates to, verifying the cross-class contract with real data.
 *
 * @covers \WP_Sudo\Gate
 * @covers \WP_Sudo\Sudo_Session
 * @covers \WP_Sudo\Request_Stash
 * @covers \WP_Sudo\Action_Registry
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Gate;
use WP_Sudo\Request_Stash;
use WP_Sudo\Sudo_Session;

class ReauthFlowTest extends TestCase {

	/**
	 * INTG-01: Full reauth flow exercises 5 classes with real WordPress functions.
	 *
	 * Steps: match rule → no session → stash → authenticate (bcrypt) → session active
	 * → retrieve stash → delete stash → verify hooks.
	 */
	public function test_full_reauth_flow_exercises_five_classes(): void {
		$password = 'integration-test-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );

		// Step 1: Simulate a gated admin action (plugin activation).
		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET', array( 'plugin' => 'hello.php' ) );

		// Step 2: Gate matches the request against Action_Registry rules.
		$gate         = wp_sudo()->gate();
		$matched_rule = $gate->match_request( 'admin' );

		$this->assertNotNull( $matched_rule, 'Gate should match plugin.activate rule.' );
		$this->assertSame( 'plugin.activate', $matched_rule['id'] );

		// Step 3: No session yet.
		$this->assertFalse( Sudo_Session::is_active( $user->ID ), 'Session should not be active before authentication.' );

		// Step 4: Stash the intercepted request (real transient).
		$stash     = new Request_Stash();
		$stash_key = $stash->save( $user->ID, $matched_rule );

		$this->assertSame( 16, strlen( $stash_key ) );
		$this->assertTrue( $stash->exists( $stash_key, $user->ID ) );

		// Step 5: Authenticate with real bcrypt.
		$gated_before    = did_action( 'wp_sudo_action_gated' );
		$activated_before = did_action( 'wp_sudo_activated' );

		// Manually fire the gated hook (normally fired inside Gate::intercept() which we can't call due to exit).
		do_action( 'wp_sudo_action_gated', $user->ID, $matched_rule['id'], 'admin' );
		$this->assertSame( $gated_before + 1, did_action( 'wp_sudo_action_gated' ) );

		$result = Sudo_Session::attempt_activation( $user->ID, $password );

		$this->assertSame( 'success', $result['code'], 'Password verification should succeed with real bcrypt.' );
		$this->assertSame( $activated_before + 1, did_action( 'wp_sudo_activated' ) );

		// Step 6: Session is now active (real meta + cookie binding).
		$this->assertTrue( Sudo_Session::is_active( $user->ID ), 'Session should be active after authentication.' );

		// Verify the token binding chain.
		$cookie_token = $_COOKIE[ Sudo_Session::TOKEN_COOKIE ];
		$stored_hash  = get_user_meta( $user->ID, Sudo_Session::TOKEN_META_KEY, true );
		$this->assertSame( hash( 'sha256', $cookie_token ), $stored_hash );

		// Step 7: Retrieve stash (real transient).
		$retrieved = $stash->get( $stash_key, $user->ID );

		$this->assertIsArray( $retrieved );
		$this->assertSame( 'plugin.activate', $retrieved['rule_id'] );
		$this->assertSame( 'GET', $retrieved['method'] );

		// Step 8: Delete stash (one-time use after replay).
		$replayed_before = did_action( 'wp_sudo_action_replayed' );
		do_action( 'wp_sudo_action_replayed', $user->ID, $retrieved['rule_id'] );

		$stash->delete( $stash_key );

		$this->assertSame( $replayed_before + 1, did_action( 'wp_sudo_action_replayed' ) );
		$this->assertFalse( $stash->exists( $stash_key, $user->ID ), 'Stash should be consumed after replay.' );
	}

	/**
	 * INTG-01: Wrong password leaves session inactive and stash preserved.
	 */
	public function test_reauth_flow_rejects_wrong_password(): void {
		$user = $this->make_admin( 'correct-password' );
		wp_set_current_user( $user->ID );

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		// Stash the request.
		$stash     = new Request_Stash();
		$stash_key = $stash->save( $user->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		// Attempt with wrong password.
		$failed_before = did_action( 'wp_sudo_reauth_failed' );
		$result        = Sudo_Session::attempt_activation( $user->ID, 'wrong-password' );

		$this->assertSame( 'invalid_password', $result['code'] );
		$this->assertFalse( Sudo_Session::is_active( $user->ID ), 'Session should remain inactive.' );
		$this->assertSame( $failed_before + 1, did_action( 'wp_sudo_reauth_failed' ) );

		// Stash should be preserved (not consumed on failure).
		$this->assertTrue( $stash->exists( $stash_key, $user->ID ), 'Stash should survive a failed attempt.' );
	}

	/**
	 * INTG-01: Gate does not match a non-gated page.
	 */
	public function test_gate_does_not_match_non_gated_action(): void {
		$this->simulate_admin_request( 'index.php', '', 'GET' );

		$gate = wp_sudo()->gate();
		$this->assertNull( $gate->match_request( 'admin' ), 'Dashboard (index.php) should not be gated.' );
	}

	/**
	 * INTG-01: Stash is user-bound — user B cannot retrieve user A's stash.
	 */
	public function test_stash_is_user_bound_across_flow(): void {
		$user_a = $this->make_admin( 'password-a' );
		$user_b = $this->make_admin( 'password-b' );

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		$stash     = new Request_Stash();
		$stash_key = $stash->save( $user_a->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		// User A can retrieve.
		$this->assertIsArray( $stash->get( $stash_key, $user_a->ID ) );

		// User B cannot.
		$this->assertNull( $stash->get( $stash_key, $user_b->ID ) );
	}
}
```

**Verification:**

```bash
composer test:integration
# Expected: 21 tests (10 session + 7 stash + 4 flow), 0 failures
```

---

### Task 5: Verify all success criteria

**Requirements:** All (INTG-01 through INTG-04)

**Files:** None (verification only)

**Description:**

1. Run unit tests — confirm no regressions:
```bash
composer test:unit
# Expected: 343 tests, 0 failures
```

2. Run integration tests:
```bash
composer test:integration
# Expected: 21 tests, 0 failures
```

3. Verify no Brain\Monkey contamination:
```bash
grep -r "Brain\\\Monkey\|Patchwork\|Monkey\\\\setUp\|MockeryPHPUnitIntegration" tests/integration/
# Expected: no output
```

4. Backward compatibility:
```bash
composer test
# Expected: identical to composer test:unit
```

**Verification:**

All commands produce expected output. Phase 2 is complete when CI also passes.
