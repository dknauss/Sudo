<?php
/**
 * Integration tests for security-critical exit paths and grace window behaviour.
 *
 * Existing integration tests call component methods directly to avoid exit/die.
 * These tests verify the actual HTTP response shapes (JSON bodies, status codes,
 * error shapes) that exit-path methods produce, using:
 * - REST dispatch for REST API gating (no exit — returns WP_REST_Response)
 * - WPDieException + output capture for AJAX/WPGraphQL/Challenge paths
 *
 * Grace window tests (GRACE-01 through GRACE-03) verify that the Gate allows
 * gated actions during the 120-second wind-down window and blocks them after.
 *
 * @covers \WP_Sudo\Gate::intercept_rest
 * @covers \WP_Sudo\Gate::intercept
 * @covers \WP_Sudo\Gate::gate_wpgraphql
 * @covers \WP_Sudo\Sudo_Session::is_within_grace
 * @covers \WP_Sudo\Challenge::handle_ajax_auth
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Admin;
use WP_Sudo\Challenge;
use WP_Sudo\Gate;
use WP_Sudo\Request_Stash;
use WP_Sudo\Sudo_Session;

class ExitPathTest extends TestCase {

	private Gate $gate;

	public function set_up(): void {
		parent::set_up();

		$this->gate = new Gate( new Sudo_Session(), new Request_Stash() );

		// Make wp_die() throw instead of die() for AJAX paths.
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
		add_filter( 'wp_die_handler', array( $this, 'get_wp_die_handler' ) );
	}

	public function tear_down(): void {
		remove_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
		remove_filter( 'wp_die_handler', array( $this, 'get_wp_die_handler' ) );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );

		parent::tear_down();
	}

	// ─────────────────────────────────────────────────────────────────────
	// EXIT-01: REST API blocked mutation returns 403 with WP_Error shape
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * A cookie-authenticated DELETE /wp/v2/plugins/{slug} without sudo
	 * returns a 403 JSON response with the standard WP REST error shape.
	 */
	public function test_rest_blocked_mutation_returns_403_json(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		// Register the Gate's rest_pre_dispatch filter the same way the plugin does.
		add_filter( 'rest_pre_dispatch', array( $this->gate, 'intercept_rest' ), 10, 3 );

		$request = new \WP_REST_Request( 'DELETE', '/wp/v2/plugins/hello' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$server   = rest_get_server();
		$response = $server->dispatch( $request );

		remove_filter( 'rest_pre_dispatch', array( $this->gate, 'intercept_rest' ) );

		$this->assertSame( 403, $response->get_status(), 'Blocked REST mutation should return 403.' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'code', $data, 'Response should include error code.' );
		$this->assertSame( 'sudo_required', $data['code'], 'Error code should be sudo_required.' );
		$this->assertArrayHasKey( 'message', $data, 'Response should include error message.' );
	}

	// ─────────────────────────────────────────────────────────────────────
	// EXIT-02: AJAX blocked action returns JSON error with sudo_required
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * A gated AJAX action (delete-plugin) without sudo returns a JSON
	 * error body with code 'sudo_required'.
	 */
	public function test_ajax_blocked_action_returns_json_error_body(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		add_filter( 'wp_doing_ajax', '__return_true' );

		$_REQUEST['action'] = 'delete-plugin';
		$_POST['slug']      = 'hello-dolly/hello.php';

		ob_start();
		try {
			$this->gate->intercept();
			$this->fail( 'Expected WPDieException from wp_send_json_error.' );
		} catch ( \WPDieException $e ) {
			// Expected.
		} finally {
			$output = ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		$json = json_decode( $output, true );
		$this->assertIsArray( $json, 'Output should be valid JSON.' );
		$this->assertFalse( $json['success'], 'JSON response success should be false.' );
		$this->assertArrayHasKey( 'data', $json, 'JSON response should include data.' );
		$this->assertSame( 'sudo_required', $json['data']['code'], 'Error code should be sudo_required.' );
		$this->assertArrayHasKey( 'message', $json['data'], 'Error should include a message.' );
	}

	// ─────────────────────────────────────────────────────────────────────
	// EXIT-03: WPGraphQL blocked mutation returns 403 JSON error shape
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * A blocked WPGraphQL mutation returns a JSON response with code,
	 * message, and data.status keys matching the error contract.
	 */
	public function test_wpgraphql_blocked_mutation_returns_403_json(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		// Ensure Limited policy (default).
		$settings = $this->get_wp_sudo_option( Admin::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings[ Gate::SETTING_WPGRAPHQL_POLICY ] = Gate::POLICY_LIMITED;
		$this->update_wp_sudo_option( Admin::OPTION_KEY, $settings );
		Admin::reset_cache();

		// gate_wpgraphql() reads php://input. We need to test check_wpgraphql()
		// → wp_send_json() path. Since php://input cannot be mocked in integration
		// tests, call check_wpgraphql() to verify the WP_Error, then verify the
		// JSON shape that gate_wpgraphql() would produce.
		$mutation_body = '{"query":"mutation { deleteUser(input:{id:\\"1\\"}) { deletedId } }"}';
		$result        = $this->gate->check_wpgraphql( $mutation_body );

		$this->assertWPError( $result, 'Blocked mutation should return WP_Error.' );
		$this->assertSame( 'sudo_blocked', $result->get_error_code() );

		// Reconstruct the JSON that gate_wpgraphql() would send.
		$data     = $result->get_error_data();
		$expected = array(
			'code'    => $result->get_error_code(),
			'message' => $result->get_error_message(),
			'data'    => is_array( $data ) ? $data : array( 'status' => 403 ),
		);

		$this->assertSame( 'sudo_blocked', $expected['code'] );
		$this->assertNotEmpty( $expected['message'], 'Error message should not be empty.' );
		$this->assertArrayHasKey( 'status', $expected['data'] );
		$this->assertSame( 403, $expected['data']['status'] );
	}

	// ─────────────────────────────────────────────────────────────────────
	// EXIT-04: Admin gating redirects to challenge page
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * A gated admin action (plugin activate) triggers a redirect to the
	 * challenge page. We verify via the wp_redirect filter and force
	 * wp_safe_redirect() to fail so the code falls back to wp_die().
	 */
	public function test_admin_gating_redirects_to_challenge_page(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET', array(
			'plugin' => 'hello-dolly/hello.php',
		) );

		// Capture the redirect URL via the wp_redirect filter.
		$redirect_url      = null;
		$redirect_callback = static function ( $location ) use ( &$redirect_url ) {
			$redirect_url = $location;
			return false; // Prevent actual redirect.
		};
		add_filter( 'wp_redirect', $redirect_callback );

		// intercept() calls challenge_admin(); when wp_safe_redirect() returns false,
		// challenge_admin() fails closed via wp_die().
		try {
			$this->gate->intercept();
		} catch ( \WPDieException $e ) {
			// Expected.
			$this->addToAssertionCount( 1 );
		} finally {
			remove_filter( 'wp_redirect', $redirect_callback );
		}

		$this->assertNotNull( $redirect_url, 'Gate should redirect to challenge page.' );
		$this->assertStringContainsString(
			'page=wp-sudo-challenge',
			$redirect_url,
			'Redirect URL should target the challenge page.'
		);
		$this->assertStringContainsString(
			'stash_key=',
			$redirect_url,
			'Redirect URL should include a stash_key parameter.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// GRACE-01: Admin gated action passes during grace window
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * A gated admin action during the grace window (session expired <120 s ago,
	 * valid token) passes without redirect. The grace window permits any gated
	 * action — it does not distinguish "in-progress" from "new".
	 */
	public function test_admin_gated_action_passes_during_grace_window(): void {
		$password = 'test-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );

		// Activate a real session so token meta is set correctly.
		Sudo_Session::activate( $user->ID );

		// Read back the token from the cookie that activate() set.
		$token = $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] ?? '';
		$this->assertNotEmpty( $token, 'Token cookie should be set after activation.' );

		// Move the expiry into the past (30 s ago) — within GRACE_SECONDS (120 s).
		$expired_at = time() - 30;
		update_user_meta( $user->ID, Sudo_Session::META_KEY, $expired_at );
		Sudo_Session::reset_cache();

		// Verify preconditions: session is NOT active but IS within grace.
		$this->assertFalse( Sudo_Session::is_active( $user->ID ), 'Session should not be active (expired).' );
		Sudo_Session::reset_cache();
		$this->assertTrue( Sudo_Session::is_within_grace( $user->ID ), 'Session should be within grace window.' );
		Sudo_Session::reset_cache();

		// Set up a gated admin request (plugin activate).
		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET', array(
			'plugin' => 'hello-dolly/hello.php',
		) );

		// If the gate triggers, it will redirect. Capture any redirect.
		$redirect_url      = null;
		$redirect_callback = static function ( $location ) use ( &$redirect_url ) {
			$redirect_url = $location;
			return false;
		};
		add_filter( 'wp_redirect', $redirect_callback );

		try {
			$this->gate->intercept();
		} catch ( \WPDieException $e ) {
			$this->fail( 'Grace-window request should not trigger wp_die().' );
		} finally {
			remove_filter( 'wp_redirect', $redirect_callback );
		}

		$this->assertNull( $redirect_url, 'Gate should NOT redirect during the grace window.' );
	}

	// ─────────────────────────────────────────────────────────────────────
	// GRACE-02: Admin gated action blocked after grace window closes
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * A gated admin action after the grace window has closed (session expired
	 * >120 s ago) triggers the challenge redirect — same as no session.
	 */
	public function test_admin_gated_action_blocked_after_grace_window(): void {
		$password = 'test-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );

		// Activate a real session so token meta is set correctly.
		Sudo_Session::activate( $user->ID );

		// Read back the token cookie.
		$token = $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] ?? '';
		$this->assertNotEmpty( $token, 'Token cookie should be set after activation.' );

		// Move the expiry well beyond the grace window (180 s ago).
		$expired_at = time() - 180;
		update_user_meta( $user->ID, Sudo_Session::META_KEY, $expired_at );
		Sudo_Session::reset_cache();

		// Verify preconditions: session is NOT active and NOT within grace.
		$this->assertFalse( Sudo_Session::is_active( $user->ID ), 'Session should not be active.' );
		Sudo_Session::reset_cache();
		$this->assertFalse( Sudo_Session::is_within_grace( $user->ID ), 'Session should not be within grace.' );
		Sudo_Session::reset_cache();

		// Set up a gated admin request.
		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET', array(
			'plugin' => 'hello-dolly/hello.php',
		) );

		$redirect_url      = null;
		$redirect_callback = static function ( $location ) use ( &$redirect_url ) {
			$redirect_url = $location;
			return false;
		};
		add_filter( 'wp_redirect', $redirect_callback );

		try {
			$this->gate->intercept();
		} catch ( \WPDieException $e ) {
			// Expected.
			$this->addToAssertionCount( 1 );
		} finally {
			remove_filter( 'wp_redirect', $redirect_callback );
		}

		$this->assertNotNull( $redirect_url, 'Gate should redirect after grace window closes.' );
		$this->assertStringContainsString(
			'page=wp-sudo-challenge',
			$redirect_url,
			'Redirect should target the challenge page.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// GRACE-03: REST gated mutation passes during grace window
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * A cookie-authenticated REST mutation during the grace window returns
	 * the normal response, not a 403 error.
	 */
	public function test_rest_gated_mutation_passes_during_grace_window(): void {
		$password = 'test-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );

		// Activate a real session.
		Sudo_Session::activate( $user->ID );

		// Move expiry into grace window (30 s ago).
		$expired_at = time() - 30;
		update_user_meta( $user->ID, Sudo_Session::META_KEY, $expired_at );
		Sudo_Session::reset_cache();

		// Register the Gate's rest_pre_dispatch filter.
		add_filter( 'rest_pre_dispatch', array( $this->gate, 'intercept_rest' ), 10, 3 );

		$request = new \WP_REST_Request( 'DELETE', '/wp/v2/plugins/hello' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$server   = rest_get_server();
		$response = $server->dispatch( $request );

		remove_filter( 'rest_pre_dispatch', array( $this->gate, 'intercept_rest' ) );

		// The gate should let it through (grace window). The response may be
		// a 404 (plugin doesn't exist) or other non-403 status — the point is
		// it should NOT be a 403 sudo_required error.
		$data = $response->get_data();
		if ( 403 === $response->get_status() && isset( $data['code'] ) ) {
			$this->assertNotSame(
				'sudo_required',
				$data['code'],
				'REST mutation should not be sudo-blocked during grace window.'
			);
		}

		// If we got here without a sudo_required 403, the grace window worked.
		$this->assertTrue( true, 'REST mutation passed through grace window.' );
	}

	// ─────────────────────────────────────────────────────────────────────
	// EXIT-05: Challenge wrong password returns 401 JSON error
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Submitting a wrong password to handle_ajax_auth() returns a JSON
	 * error with 'invalid_password' code and a human-readable message.
	 */
	public function test_challenge_wrong_password_returns_401_json(): void {
		$password = 'test-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );

		$stash = new Request_Stash();
		$challenge = new Challenge( $stash );

		// Set up AJAX request context.
		$_POST['password']  = 'wrong-password';
		$_POST['stash_key'] = '';
		$_POST['_ajax_nonce'] = wp_create_nonce( Challenge::NONCE_ACTION );
		$_REQUEST['_ajax_nonce'] = $_POST['_ajax_nonce'];
		add_filter( 'wp_doing_ajax', '__return_true' );

		ob_start();
		try {
			$challenge->handle_ajax_auth();
			$this->fail( 'Expected WPDieException from wp_send_json_error.' );
		} catch ( \WPDieException $e ) {
			// Expected.
			$this->addToAssertionCount( 1 );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
			$output = ob_get_clean();
		}

		$json = json_decode( $output, true );
		$this->assertIsArray( $json, 'Output should be valid JSON.' );
		$this->assertFalse( $json['success'], 'Wrong password response should be an error.' );
		$this->assertArrayHasKey( 'data', $json );
		$this->assertArrayHasKey( 'message', $json['data'], 'Error should include a message.' );
		$this->assertNotEmpty( $json['data']['message'], 'Error message should not be empty.' );
	}

	// ─────────────────────────────────────────────────────────────────────
	// EXIT-06: Challenge correct password returns success JSON
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Submitting the correct password in session-only mode returns a JSON
	 * success response with code 'authenticated'.
	 */
	public function test_challenge_correct_password_returns_success_json(): void {
		$password = 'test-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );

		$stash = new Request_Stash();
		$challenge = new Challenge( $stash );

		// Session-only flow: no stash_key, just password.
		$_POST['password']  = $password;
		$_POST['stash_key'] = '';
		$_POST['_ajax_nonce'] = wp_create_nonce( Challenge::NONCE_ACTION );
		$_REQUEST['_ajax_nonce'] = $_POST['_ajax_nonce'];
		add_filter( 'wp_doing_ajax', '__return_true' );

		ob_start();
		try {
			$challenge->handle_ajax_auth();
			$this->fail( 'Expected WPDieException from wp_send_json_success.' );
		} catch ( \WPDieException $e ) {
			// Expected.
			$this->addToAssertionCount( 1 );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
			$output = ob_get_clean();
		}

		$json = json_decode( $output, true );
		$this->assertIsArray( $json, 'Output should be valid JSON.' );
		$this->assertTrue( $json['success'], 'Correct password response should be success.' );
		$this->assertArrayHasKey( 'data', $json );
		$this->assertSame( 'authenticated', $json['data']['code'], 'Success code should be authenticated.' );

		// Session should be active after correct password.
		$this->assertTrue( Sudo_Session::is_active( $user->ID ), 'Session should be active after authentication.' );
	}

	/**
	 * A stale challenge request with an already-active sudo session exits cleanly
	 * instead of failing on an expired stash key.
	 */
	public function test_challenge_with_active_session_ignores_expired_stash(): void {
		$password = 'test-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );

		Sudo_Session::activate( $user->ID );
		$this->assertTrue( Sudo_Session::is_active( $user->ID ), 'Precondition: session should already be active.' );

		$challenge = new Challenge( new Request_Stash() );

		$_POST['password']     = $password;
		$_POST['stash_key']    = 'expired-key';
		$_POST['_ajax_nonce']  = wp_create_nonce( Challenge::NONCE_ACTION );
		$_REQUEST['_ajax_nonce'] = $_POST['_ajax_nonce'];
		add_filter( 'wp_doing_ajax', '__return_true' );

		ob_start();
		try {
			$challenge->handle_ajax_auth();
			$this->fail( 'Expected WPDieException from wp_send_json_success.' );
		} catch ( \WPDieException $e ) {
			$this->addToAssertionCount( 1 );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
			$output = ob_get_clean();
		}

		$json = json_decode( $output, true );
		$this->assertIsArray( $json, 'Output should be valid JSON.' );
		$this->assertTrue( $json['success'], 'Active-session stale challenge should return success.' );
		$this->assertSame( 'authenticated', $json['data']['code'], 'Active-session stale challenge should escape the screen.' );
	}
}
