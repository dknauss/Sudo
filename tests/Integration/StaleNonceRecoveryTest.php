<?php
/**
 * Integration tests for stale grant-nonce recovery (overnight-tab scenario).
 *
 * The editor localizes the grant nonce (Challenge::NONCE_ACTION) at page load.
 * A long-open editor (past the ~24h nonce lifetime) holds a stale nonce that
 * check_ajax_referer() would reject on the grant POST. The recovery is the
 * refresh endpoint (Challenge::handle_ajax_refresh_nonce, action
 * wp_sudo_refresh_grant_nonce): it is login-gated ONLY — it does not require the
 * stale grant nonce — and re-mints a fresh NONCE_ACTION nonce that the grant
 * handler then accepts. The client calls it before every password submit.
 *
 * These tests assert the RECOVERY, not just the happy path: a stale nonce is
 * rejected, and a freshly-refreshed nonce is accepted and grants.
 *
 * @covers \WP_Sudo\Challenge::handle_ajax_refresh_nonce
 * @covers \WP_Sudo\Challenge::handle_ajax_auth
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Challenge;
use WP_Sudo\Request_Stash;
use WP_Sudo\Sudo_Session;

class StaleNonceRecoveryTest extends TestCase {

	private function ajax_setup(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
	}

	private function ajax_teardown(): void {
		remove_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset(
			$_POST['password'],
			$_POST['stash_key'],
			$_POST['_wpnonce'],
			$_POST['_ajax_nonce'],
			$_REQUEST['_wpnonce'],
			$_REQUEST['_ajax_nonce']
		);
	}

	/**
	 * Run an AJAX handler, capture the JSON body it prints before wp_die().
	 *
	 * @param callable $handler The handler to invoke.
	 * @return array<string,mixed> Decoded JSON response.
	 */
	private function capture_ajax( callable $handler ): array {
		ob_start();
		try {
			$handler();
		} catch ( \WPDieException $e ) {
			$this->addToAssertionCount( 1 );
		} finally {
			$output = ob_get_clean();
		}
		$json = json_decode( (string) $output, true );
		$this->assertIsArray( $json, 'AJAX handler must print a JSON body.' );
		return $json;
	}

	/**
	 * STALE-01: the refresh endpoint re-mints a valid grant nonce for a logged-in
	 * user WITHOUT requiring the (stale) grant nonce. This independence is what
	 * makes overnight-tab recovery possible — a client whose nonce is stale can
	 * still obtain a fresh one.
	 */
	public function test_refresh_endpoint_remints_valid_nonce_without_grant_nonce(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );
		$challenge = new Challenge( new Request_Stash() );

		// No nonce present at all — models a stale/absent client nonce.
		$this->ajax_setup();
		$json = $this->capture_ajax( array( $challenge, 'handle_ajax_refresh_nonce' ) );
		$this->ajax_teardown();

		$this->assertTrue( $json['success'], 'Refresh endpoint should succeed for a logged-in user.' );
		$this->assertArrayHasKey( 'nonce', $json['data'] );
		$this->assertNotEmpty( $json['data']['nonce'] );
		$this->assertNotFalse(
			wp_verify_nonce( $json['data']['nonce'], Challenge::NONCE_ACTION ),
			'The re-minted nonce must validate for the grant NONCE_ACTION.'
		);
	}

	/**
	 * STALE-02: the refresh endpoint is login-gated and grants nothing to a
	 * logged-out request.
	 */
	public function test_refresh_endpoint_requires_login(): void {
		wp_set_current_user( 0 );
		$challenge = new Challenge( new Request_Stash() );

		$this->ajax_setup();
		$json = $this->capture_ajax( array( $challenge, 'handle_ajax_refresh_nonce' ) );
		$this->ajax_teardown();

		$this->assertFalse( $json['success'], 'Logged-out refresh must be rejected.' );
	}

	/**
	 * STALE-03: end-to-end recovery. A stale grant nonce is rejected by
	 * handle_ajax_auth() (check_ajax_referer), no session is granted; then a
	 * freshly-refreshed nonce is accepted and the grant succeeds. This is the
	 * overnight-tab recovery, asserted beyond the happy path.
	 */
	public function test_stale_nonce_rejected_then_refreshed_nonce_grants(): void {
		$password = 'correct-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );
		$challenge = new Challenge( new Request_Stash() );

		// (A) A stale/invalid grant nonce is rejected by check_ajax_referer.
		$this->ajax_setup();
		$_POST['password']    = $password;
		$_POST['stash_key']   = '';
		$_POST['_wpnonce']    = 'stale-nonce-from-an-overnight-tab';
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		$rejected = false;
		ob_start();
		try {
			$challenge->handle_ajax_auth();
		} catch ( \WPDieException $e ) {
			$rejected = true;
		} finally {
			ob_get_clean();
		}

		$this->assertTrue( $rejected, 'A stale grant nonce must be rejected by check_ajax_referer.' );
		$this->assertFalse(
			Sudo_Session::is_active( $user->ID ),
			'No session may be granted from a stale-nonce attempt.'
		);

		// (B) The refresh endpoint re-mints a fresh nonce.
		$refresh = $this->capture_ajax( array( $challenge, 'handle_ajax_refresh_nonce' ) );
		$fresh_nonce = $refresh['data']['nonce'] ?? '';
		$this->assertNotEmpty( $fresh_nonce, 'Refresh must return a fresh nonce.' );

		// (C) The fresh nonce is accepted and grants the session (recovery).
		$_POST['password']    = $password;
		$_POST['stash_key']   = '';
		$_POST['_wpnonce']    = $fresh_nonce;
		$_REQUEST['_wpnonce'] = $fresh_nonce;

		$json = $this->capture_ajax( array( $challenge, 'handle_ajax_auth' ) );
		$this->ajax_teardown();

		$this->assertTrue( $json['success'], 'Grant with a fresh nonce should succeed.' );
		$this->assertSame( 'authenticated', $json['data']['code'] ?? null );
		$this->assertTrue(
			Sudo_Session::is_active( $user->ID ),
			'Session must be active after the refreshed-nonce grant (recovery succeeded).'
		);
	}
}
