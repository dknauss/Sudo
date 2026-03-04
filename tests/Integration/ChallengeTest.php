<?php
/**
 * Integration tests for the challenge (reauthentication) security path.
 *
 * Exercises the security-critical components with real WordPress functions:
 * password verification (bcrypt), session token binding (user meta + cookie),
 * request stash lifecycle (transients), audit hooks, and rate limiting.
 *
 * Pattern follows ReauthFlowTest — component methods called directly because
 * the production path involves exit/wp_die which cannot execute in PHPUnit.
 *
 * @covers \WP_Sudo\Sudo_Session
 * @covers \WP_Sudo\Request_Stash
 * @covers \WP_Sudo\Gate
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Gate;
use WP_Sudo\Request_Stash;
use WP_Sudo\Sudo_Session;

class ChallengeTest extends TestCase {

	/**
	 * Wrong password returns 'invalid_password' and fires the audit hook.
	 */
	public function test_wrong_password_returns_invalid_and_fires_audit_hook(): void {
		$password = 'test-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );

		$failed_before = did_action( 'wp_sudo_reauth_failed' );
		$result        = Sudo_Session::attempt_activation( $user->ID, 'wrong-password' );

		$this->assertSame( 'invalid_password', $result['code'], 'Wrong password should return invalid_password.' );
		$this->assertFalse( Sudo_Session::is_active( $user->ID ), 'Session should not be active after wrong password.' );
		$this->assertSame( $failed_before + 1, did_action( 'wp_sudo_reauth_failed' ), 'wp_sudo_reauth_failed should fire once.' );
	}

	/**
	 * Correct password activates the session and fires the audit hook.
	 */
	public function test_correct_password_activates_session_and_fires_audit_hook(): void {
		$password = 'test-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );

		$activated_before = did_action( 'wp_sudo_activated' );
		$result           = Sudo_Session::attempt_activation( $user->ID, $password );

		$this->assertSame( 'success', $result['code'], 'Correct password should return success.' );
		$this->assertTrue( Sudo_Session::is_active( $user->ID ), 'Session should be active after correct password.' );
		$this->assertSame( $activated_before + 1, did_action( 'wp_sudo_activated' ), 'wp_sudo_activated should fire once.' );
	}

	/**
	 * Session token binding: cookie hash matches stored user meta.
	 */
	public function test_token_binding_matches_cookie_to_stored_hash(): void {
		$password = 'test-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );

		$result = Sudo_Session::attempt_activation( $user->ID, $password );

		$this->assertSame( 'success', $result['code'] );

		// The activation path sets $_COOKIE[TOKEN_COOKIE] and stores its hash in user meta.
		$this->assertArrayHasKey( Sudo_Session::TOKEN_COOKIE, $_COOKIE, 'Token cookie should be set after activation.' );

		$cookie_value = $_COOKIE[ Sudo_Session::TOKEN_COOKIE ];
		$stored_hash  = get_user_meta( $user->ID, Sudo_Session::TOKEN_META_KEY, true );

		$this->assertNotEmpty( $cookie_value, 'Cookie value should not be empty.' );
		$this->assertNotEmpty( $stored_hash, 'Stored token hash should not be empty.' );
		$this->assertSame(
			hash( 'sha256', $cookie_value ),
			$stored_hash,
			'SHA-256 hash of cookie should match stored meta value.'
		);
	}

	/**
	 * Request stash lifecycle: save → get → delete → gone.
	 */
	public function test_request_stash_save_get_delete_lifecycle(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		// Simulate a gated admin request.
		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET', array( 'plugin' => 'hello.php' ) );

		// Gate matches the request.
		$gate         = wp_sudo()->gate();
		$matched_rule = $gate->match_request( 'admin' );

		$this->assertNotNull( $matched_rule, 'Gate should match plugin.activate rule.' );

		// Save the stash.
		$stash     = new Request_Stash();
		$stash_key = $stash->save( $user->ID, $matched_rule );

		$this->assertSame( 16, strlen( $stash_key ), 'Stash key should be 16 characters.' );

		// Retrieve the stash.
		$retrieved = $stash->get( $stash_key, $user->ID );

		$this->assertIsArray( $retrieved, 'Stash should return an array.' );
		$this->assertSame( 'plugin.activate', $retrieved['rule_id'], 'Stash should contain the original rule ID.' );
		$this->assertSame( 'GET', $retrieved['method'], 'Stash should contain the original HTTP method.' );
		$this->assertStringContainsString( 'plugins.php', $retrieved['url'], 'Stash should contain the original URL.' );

		// Delete the stash.
		$stash->delete( $stash_key );

		$this->assertNull( $stash->get( $stash_key, $user->ID ), 'Stash should be null after deletion.' );
		$this->assertFalse( $stash->exists( $stash_key, $user->ID ), 'Stash should not exist after deletion.' );
	}

	/**
	 * Rate limiting: lockout after MAX_FAILED_ATTEMPTS wrong passwords.
	 */
	public function test_lockout_after_max_failed_attempts(): void {
		$user = $this->make_admin( 'correct-password' );
		wp_set_current_user( $user->ID );

		$lockout_before = did_action( 'wp_sudo_lockout' );

		// Fail MAX_FAILED_ATTEMPTS - 1 times (these return invalid_password).
		for ( $i = 0; $i < Sudo_Session::MAX_FAILED_ATTEMPTS - 1; $i++ ) {
			$result = Sudo_Session::attempt_activation( $user->ID, 'wrong-password' );
			$this->assertSame( 'invalid_password', $result['code'], "Attempt {$i} should return invalid_password." );
		}

		// Not yet locked out.
		$this->assertFalse( Sudo_Session::is_locked_out( $user->ID ), 'Should not be locked out before final attempt.' );

		// Attempt 4 sets a throttle window. Clear it so the 5th attempt
		// actually processes the password instead of being short-circuited.
		delete_user_meta( $user->ID, Sudo_Session::THROTTLE_UNTIL_META_KEY );

		// The MAX_FAILED_ATTEMPTS-th attempt triggers lockout.
		$result = Sudo_Session::attempt_activation( $user->ID, 'wrong-password' );
		$this->assertSame( 'locked_out', $result['code'], 'Final attempt should trigger lockout.' );
		$this->assertTrue( Sudo_Session::is_locked_out( $user->ID ), 'User should be locked out after max failed attempts.' );
		$this->assertSame( $lockout_before + 1, did_action( 'wp_sudo_lockout' ), 'wp_sudo_lockout should fire once.' );

		// Even the correct password should be rejected during lockout.
		$result = Sudo_Session::attempt_activation( $user->ID, 'correct-password' );
		$this->assertSame( 'locked_out', $result['code'], 'Correct password should be rejected during lockout.' );
		$this->assertFalse( Sudo_Session::is_active( $user->ID ), 'Session should not be active during lockout.' );
	}
}
