<?php
/**
 * Integration tests for Two Factor plugin interaction — real plugin, real DB.
 *
 * Tests verify that Sudo_Session correctly detects Two Factor configuration,
 * enters the 2FA pending state machine, and manages challenge cookies/transients.
 *
 * Self-guarding: each test skips when Two Factor plugin is not installed.
 *
 * @group two-factor
 * @covers \WP_Sudo\Sudo_Session
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Challenge;
use WP_Sudo\Request_Stash;
use WP_Sudo\Sudo_Session;

class TwoFactorTest extends TestCase {

	/**
	 * Skip if Two Factor plugin is not loaded.
	 */
	private function require_two_factor(): void {
		if ( ! class_exists( '\\Two_Factor_Core' ) ) {
			$this->markTestSkipped( 'Two Factor plugin not installed.' );
		}
	}

	/**
	 * Configure TOTP for a user so Two_Factor_Core::is_user_using_two_factor() returns true.
	 *
	 * Sets the 3 user meta keys that the Two Factor plugin checks:
	 * - _two_factor_enabled_providers: list of enabled provider class names
	 * - _two_factor_provider: primary provider class name
	 * - _two_factor_totp_key: Base32 TOTP secret (needed for is_available_for_user)
	 *
	 * @param int $user_id User ID.
	 */
	private function configure_totp_for_user( int $user_id ): void {
		update_user_meta( $user_id, '_two_factor_enabled_providers', array( 'Two_Factor_Totp' ) );
		update_user_meta( $user_id, '_two_factor_provider', 'Two_Factor_Totp' );
		update_user_meta( $user_id, '_two_factor_totp_key', 'JBSWY3DPEHPK3PXP' );
	}

	/**
	 * ADVN-01: needs_two_factor() returns false for user without 2FA providers.
	 */
	public function test_needs_two_factor_false_without_providers(): void {
		$this->require_two_factor();

		$user = $this->make_admin();

		$this->assertFalse(
			Sudo_Session::needs_two_factor( $user->ID ),
			'needs_two_factor() should return false without 2FA providers configured.'
		);
	}

	/**
	 * ADVN-01: needs_two_factor() returns true with TOTP configured.
	 */
	public function test_needs_two_factor_true_with_totp_configured(): void {
		$this->require_two_factor();

		$user = $this->make_admin();
		$this->configure_totp_for_user( $user->ID );

		$this->assertTrue(
			Sudo_Session::needs_two_factor( $user->ID ),
			'needs_two_factor() should return true with TOTP configured.'
		);
	}

	/**
	 * ADVN-01: attempt_activation() returns '2fa_pending' when password is correct
	 * and user has 2FA configured.
	 */
	public function test_attempt_activation_returns_2fa_pending(): void {
		$this->require_two_factor();

		$password = 'correct-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );
		$this->configure_totp_for_user( $user->ID );

		$result = Sudo_Session::attempt_activation( $user->ID, $password );

		$this->assertSame( '2fa_pending', $result['code'] );
		$this->assertArrayHasKey( 'expires_at', $result );
		$this->assertGreaterThan( time(), $result['expires_at'] );
	}

	/**
	 * SECURITY — 2FA-bypass invariant (session layer, real Two Factor). A real
	 * TOTP-configured user's correct-password attempt_activation() returns
	 * 2fa_pending and mints NO sudo session. The password step alone can never
	 * grant a 2FA user — this is what makes the password-only in-editor modal
	 * safe for 2FA accounts.
	 */
	public function test_attempt_activation_2fa_user_mints_no_session(): void {
		$this->require_two_factor();

		$password = 'correct-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );
		$this->configure_totp_for_user( $user->ID );

		$this->assertFalse(
			Sudo_Session::is_active( $user->ID ),
			'No sudo session should exist before the password attempt.'
		);

		$result = Sudo_Session::attempt_activation( $user->ID, $password );

		$this->assertSame( '2fa_pending', $result['code'], 'A 2FA user must land in 2fa_pending.' );
		$this->assertNotSame( 'success', $result['code'], 'A 2FA user must never reach success on the password step.' );
		$this->assertFalse(
			Sudo_Session::is_active( $user->ID ),
			'attempt_activation() must not activate a session for a 2FA user (bypass invariant).'
		);

		Sudo_Session::clear_2fa_pending();
	}

	/**
	 * SECURITY — 2FA-bypass invariant (AJAX layer, real Two Factor). The in-editor
	 * modal's handler, handle_ajax_auth(), given a 2FA user's CORRECT password must
	 * return JSON code '2fa_pending' (never 'authenticated') and leave no active
	 * sudo session. Guards against a regression that would let the password-only
	 * modal grant a 2FA user in place instead of linking out to the full challenge.
	 */
	public function test_handle_ajax_auth_2fa_user_yields_pending_and_no_session(): void {
		$this->require_two_factor();

		$password = 'correct-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );
		$this->configure_totp_for_user( $user->ID );

		$this->assertFalse( Sudo_Session::is_active( $user->ID ) );

		$challenge = new Challenge( new Request_Stash() );

		$_POST['password']       = $password;
		$_POST['stash_key']      = '';
		$_POST['_ajax_nonce']    = wp_create_nonce( Challenge::NONCE_ACTION );
		$_REQUEST['_ajax_nonce'] = $_POST['_ajax_nonce'];
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );

		ob_start();
		try {
			$challenge->handle_ajax_auth();
			$this->fail( 'Expected WPDieException from wp_send_json_success.' );
		} catch ( \WPDieException $e ) {
			$this->addToAssertionCount( 1 );
		} finally {
			remove_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
			remove_filter( 'wp_doing_ajax', '__return_true' );
			$output = ob_get_clean();
		}

		$json = json_decode( $output, true );
		$this->assertIsArray( $json, 'Output should be valid JSON.' );
		$this->assertTrue( $json['success'], '2fa_pending is a success-shaped response.' );
		$this->assertSame(
			'2fa_pending',
			$json['data']['code'] ?? null,
			'A 2FA user must receive 2fa_pending, never authenticated.'
		);
		$this->assertNotSame(
			'authenticated',
			$json['data']['code'] ?? null,
			'The password step must not authenticate a 2FA user.'
		);
		$this->assertFalse(
			Sudo_Session::is_active( $user->ID ),
			'No sudo session may be minted for a 2FA user at the AJAX auth layer (bypass invariant).'
		);

		Sudo_Session::clear_2fa_pending();
	}

	/**
	 * ADVN-01: 2fa_pending path sets CHALLENGE_COOKIE in $_COOKIE superglobal.
	 */
	public function test_2fa_pending_sets_challenge_cookie(): void {
		$this->require_two_factor();

		$password = 'correct-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );
		$this->configure_totp_for_user( $user->ID );

		Sudo_Session::attempt_activation( $user->ID, $password );

		$this->assertNotEmpty(
			$_COOKIE[ Sudo_Session::CHALLENGE_COOKIE ] ?? '',
			'CHALLENGE_COOKIE should be set in $_COOKIE after 2fa_pending.'
		);
	}

	/**
	 * ADVN-01: get_2fa_pending() reads the challenge transient correctly.
	 *
	 * After attempt_activation() returns 2fa_pending, get_2fa_pending() should
	 * return the stored pending data with matching user_id and future expires_at.
	 */
	public function test_get_2fa_pending_reads_challenge_transient(): void {
		$this->require_two_factor();

		$password = 'correct-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );
		$this->configure_totp_for_user( $user->ID );

		$result = Sudo_Session::attempt_activation( $user->ID, $password );
		$this->assertSame( '2fa_pending', $result['code'] );

		$pending = Sudo_Session::get_2fa_pending( $user->ID );

		$this->assertIsArray( $pending );
		$this->assertSame( $user->ID, $pending['user_id'] );
		$this->assertSame( $result['expires_at'], $pending['expires_at'] );
	}

	/**
	 * ADVN-01: clear_2fa_pending() deletes the transient and unsets CHALLENGE_COOKIE.
	 */
	public function test_clear_2fa_pending_deletes_transient_and_cookie(): void {
		$this->require_two_factor();

		$password = 'correct-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );
		$this->configure_totp_for_user( $user->ID );

		Sudo_Session::attempt_activation( $user->ID, $password );

		// Verify pending state exists before clearing.
		$this->assertNotNull( Sudo_Session::get_2fa_pending( $user->ID ) );

		Sudo_Session::clear_2fa_pending();

		// Transient should be gone.
		$this->assertNull(
			Sudo_Session::get_2fa_pending( $user->ID ),
			'get_2fa_pending() should return null after clear_2fa_pending().'
		);

		// Cookie should be unset from superglobal.
		$this->assertArrayNotHasKey(
			Sudo_Session::CHALLENGE_COOKIE,
			$_COOKIE,
			'CHALLENGE_COOKIE should be unset from $_COOKIE after clear_2fa_pending().'
		);
	}

	/**
	 * ADVN-01: wp_sudo_requires_two_factor filter overrides detection.
	 *
	 * Even without Two Factor plugin meta, a filter returning true should
	 * make needs_two_factor() return true.
	 */
	public function test_needs_two_factor_filter_overrides(): void {
		$user = $this->make_admin();

		// No 2FA meta, no Two Factor plugin class needed — filter forces true.
		add_filter( 'wp_sudo_requires_two_factor', '__return_true' );

		$this->assertTrue(
			Sudo_Session::needs_two_factor( $user->ID ),
			'wp_sudo_requires_two_factor filter should override detection to true.'
		);

		remove_filter( 'wp_sudo_requires_two_factor', '__return_true' );
	}
}
