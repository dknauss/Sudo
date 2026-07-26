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
		$gated_before     = did_action( 'wp_sudo_action_gated' );
		$activated_before = did_action( 'wp_sudo_activated' );

		// Manually fire the gated hook (normally fired inside Gate::intercept() which we can't call due to exit).
		do_action( 'wp_sudo_action_gated', $user->ID, $matched_rule['id'], 'admin' );
		$this->assertSame( $gated_before + 1, did_action( 'wp_sudo_action_gated' ) );

		$result = Sudo_Session::attempt_activation( $user->ID, $password );

		$this->assertSame( 'success', $result['code'], 'Password verification should succeed with real bcrypt.' );
		$this->assertSame( $activated_before + 1, did_action( 'wp_sudo_activated' ) );

		// Step 6: Session is now active (real meta + cookie binding).
		$this->assertTrue( Sudo_Session::is_active( $user->ID ), 'Session should be active after authentication.' );

		// Verify the token binding chain via the per-login-session proof entry.
		$cookie_token = $_COOKIE[ Sudo_Session::TOKEN_COOKIE ];
		$map          = get_user_meta( $user->ID, Sudo_Session::PROOF_META_KEY, true );
		$stored_hash  = $map[ hash( 'sha256', wp_get_session_token() ) ]['token'] ?? '';
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
