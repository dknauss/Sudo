<?php
/**
 * Integration tests for REST API gating — cookie auth and app passwords.
 *
 * @covers \WP_Sudo\Gate::intercept_rest
 * @covers \WP_Sudo\Gate::get_app_password_policy
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Admin;
use WP_Sudo\Gate;
use WP_Sudo\Request_Stash;
use WP_Sudo\Sudo_Session;

class RestGatingTest extends TestCase {

	/**
	 * Gate instance for calling intercept_rest() directly.
	 *
	 * @var Gate
	 */
	private Gate $gate;

	public function set_up(): void {
		parent::set_up();

		$this->gate = new Gate( new Sudo_Session(), new Request_Stash() );
	}

	/**
	 * Clean up the app-password global so it doesn't leak between tests.
	 */
	public function tear_down(): void {
		unset( $GLOBALS['wp_rest_application_password_uuid'] );

		parent::tear_down();
	}

	// ─────────────────────────────────────────────────────────────────────
	// Cookie auth tests (SURF-02)
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * SURF-02: Cookie-auth DELETE /wp/v2/plugins/{slug} without sudo session
	 * returns WP_Error 'sudo_required' with status 403.
	 */
	public function test_cookie_auth_gated_route_returns_sudo_required(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		$request = new \WP_REST_Request( 'DELETE', '/wp/v2/plugins/hello' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$result = $this->gate->intercept_rest( null, array(), $request );

		$this->assertWPError( $result );
		$this->assertSame( 'sudo_required', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * SURF-02 + SURF-04: Cookie-auth gated route fires wp_sudo_action_gated
	 * with correct arguments (user_id, rule_id='plugin.delete', surface='rest').
	 */
	public function test_cookie_auth_gated_route_fires_gated_hook(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		$request = new \WP_REST_Request( 'DELETE', '/wp/v2/plugins/hello' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$captured = array();
		add_action(
			'wp_sudo_action_gated',
			static function ( $uid, $rule_id, $surface ) use ( &$captured ) {
				$captured = array( $uid, $rule_id, $surface );
			},
			10,
			3
		);

		$this->gate->intercept_rest( null, array(), $request );

		$this->assertSame( $user->ID, $captured[0] ?? null, 'user_id should match.' );
		$this->assertSame( 'plugin.delete', $captured[1] ?? null, 'rule_id should be plugin.delete.' );
		$this->assertSame( 'rest', $captured[2] ?? null, 'surface should be rest.' );
	}

	/**
	 * SURF-02: Cookie-auth gated route with active sudo session passes through.
	 */
	public function test_cookie_auth_passes_through_with_active_session(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		Sudo_Session::activate( $user->ID );

		$request = new \WP_REST_Request( 'DELETE', '/wp/v2/plugins/hello' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$original_response = array( 'deleted' => true );
		$result            = $this->gate->intercept_rest( $original_response, array(), $request );

		$this->assertSame( $original_response, $result, 'Active sudo session should pass through.' );
	}

	/**
	 * SURF-02: Non-gated route passes through regardless of sudo state.
	 */
	public function test_cookie_auth_non_gated_route_passes_through(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		// GET /wp/v2/posts is not a gated route.
		$request = new \WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$original_response = array( 'posts' => array() );
		$result            = $this->gate->intercept_rest( $original_response, array(), $request );

		$this->assertSame( $original_response, $result, 'Non-gated route should pass through.' );
	}

	/**
	 * SURF-02: Cookie-auth connector credential write requires sudo.
	 */
	public function test_cookie_auth_connector_settings_write_returns_sudo_required(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		$request = new \WP_REST_Request(
			'POST',
			'/wp/v2/settings'
		);
		$request->set_body_params(
			array(
				'connectors_ai_openai_api_key' => 'sk-test-1234',
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$result = $this->gate->intercept_rest( null, array(), $request );

		$this->assertWPError( $result );
		$this->assertSame( 'sudo_required', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 'connectors.update_credentials', $result->get_error_data()['rule_id'] );
	}

	// ─────────────────────────────────────────────────────────────────────
	// App password tests (SURF-03)
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * SURF-03 + SURF-04: Default 'limited' policy blocks app-password request
	 * with 'sudo_blocked' and fires wp_sudo_action_blocked hook.
	 */
	public function test_app_password_default_limited_blocks_with_hook(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		// Create a real app password.
		$created = \WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array( 'name' => 'test-app' )
		);

		$this->assertNotWPError( $created );

		$item = $created[1]; // The app password record with 'uuid'.
		$GLOBALS['wp_rest_application_password_uuid'] = $item['uuid'];

		// Default policy is 'limited'.
		$request = new \WP_REST_Request( 'DELETE', '/wp/v2/plugins/hello' );
		// No X-WP-Nonce — this is app-password auth.

		$blocked_args = array();
		add_action(
			'wp_sudo_action_blocked',
			static function ( $uid, $rule_id, $surface ) use ( &$blocked_args ) {
				$blocked_args = array( $uid, $rule_id, $surface );
			},
			10,
			3
		);

		$result = $this->gate->intercept_rest( null, array(), $request );

		$this->assertWPError( $result );
		$this->assertSame( 'sudo_blocked', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );

		// Verify hook arguments.
		$this->assertSame( $user->ID, $blocked_args[0] ?? null );
		$this->assertSame( 'plugin.delete', $blocked_args[1] ?? null );
		$this->assertSame( 'rest_app_password', $blocked_args[2] ?? null );
	}

	/**
	 * SURF-03: Per-app-password 'unrestricted' override passes through.
	 */
	public function test_app_password_override_unrestricted_passes_through(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		// Create app password.
		$created = \WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array( 'name' => 'deploy-bot' )
		);

		$this->assertNotWPError( $created );

		$item = $created[1];
		$GLOBALS['wp_rest_application_password_uuid'] = $item['uuid'];

		// Set per-app-password override to unrestricted.
		$settings = $this->get_wp_sudo_option( Admin::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['app_password_policies'] = array(
			$item['uuid'] => Gate::POLICY_UNRESTRICTED,
		);
		$this->update_wp_sudo_option( Admin::OPTION_KEY, $settings );
		Admin::reset_cache();

		$request = new \WP_REST_Request( 'DELETE', '/wp/v2/plugins/hello' );

		$original_response = array( 'deleted' => true );
		$result            = $this->gate->intercept_rest( $original_response, array(), $request );

		$this->assertSame( $original_response, $result, 'Unrestricted override should pass through.' );
	}

	/**
	 * SURF-03: 'disabled' policy blocks with 'sudo_disabled' and does NOT fire
	 * the wp_sudo_action_blocked hook.
	 */
	public function test_app_password_disabled_blocks_without_hook(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		// Create app password.
		$created = \WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array( 'name' => 'disabled-app' )
		);

		$this->assertNotWPError( $created );

		$item = $created[1];
		$GLOBALS['wp_rest_application_password_uuid'] = $item['uuid'];

		// Set global REST app password policy to 'disabled'.
		$settings = $this->get_wp_sudo_option( Admin::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['rest_app_password_policy'] = Gate::POLICY_DISABLED;
		$this->update_wp_sudo_option( Admin::OPTION_KEY, $settings );
		Admin::reset_cache();

		$request = new \WP_REST_Request( 'DELETE', '/wp/v2/plugins/hello' );

		$hook_fired = false;
		add_action(
			'wp_sudo_action_blocked',
			static function () use ( &$hook_fired ) {
				$hook_fired = true;
			}
		);

		$result = $this->gate->intercept_rest( null, array(), $request );

		$this->assertWPError( $result );
		$this->assertSame( 'sudo_disabled', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertFalse( $hook_fired, 'wp_sudo_action_blocked should NOT fire for disabled policy.' );
	}

	/**
	 * SURF-03: App-password connector credential write is blocked under limited policy.
	 */
	public function test_app_password_connector_settings_write_blocks_with_hook(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		$created = \WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array( 'name' => 'connector-bot' )
		);

		$this->assertNotWPError( $created );

		$item = $created[1];
		$GLOBALS['wp_rest_application_password_uuid'] = $item['uuid'];

		$request = new \WP_REST_Request(
			'POST',
			'/wp/v2/settings'
		);
		$request->set_body_params(
			array(
				'connectors_ai_openai_api_key' => 'sk-test-1234',
			)
		);

		$blocked_args = array();
		add_action(
			'wp_sudo_action_blocked',
			static function ( $uid, $rule_id, $surface ) use ( &$blocked_args ) {
				$blocked_args = array( $uid, $rule_id, $surface );
			},
			10,
			3
		);

		$result = $this->gate->intercept_rest( null, array(), $request );

		$this->assertWPError( $result );
		$this->assertSame( 'sudo_blocked', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( $user->ID, $blocked_args[0] ?? null );
		$this->assertSame( 'connectors.update_credentials', $blocked_args[1] ?? null );
		$this->assertSame( 'rest_app_password', $blocked_args[2] ?? null );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Connector credential rule boundary tests (SURF-02)
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * SURF-02: Cookie-auth POST /wp/v2/settings with only non-connector keys
	 * passes through — the connectors rule should NOT match.
	 */
	public function test_cookie_auth_non_connector_settings_write_passes_through(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		$request = new \WP_REST_Request(
			'POST',
			'/wp/v2/settings'
		);
		$request->set_body_params(
			array(
				'blogname'        => 'My Site',
				'blogdescription' => 'Just testing',
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$original_response = array( 'blogname' => 'My Site' );
		$result            = $this->gate->intercept_rest( $original_response, array(), $request );

		$this->assertNotWPError( $result, 'Non-connector settings write should pass through.' );
		$this->assertSame( $original_response, $result, 'Original response should be returned unchanged.' );
	}

	/**
	 * SURF-02: Cookie-auth POST /wp/v2/settings with a mix of a connector key
	 * and non-connector keys still requires sudo.
	 */
	public function test_cookie_auth_mixed_payload_with_connector_key_gates(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		$request = new \WP_REST_Request(
			'POST',
			'/wp/v2/settings'
		);
		$request->set_body_params(
			array(
				'blogname'                    => 'My Site',
				'connectors_ai_openai_api_key' => 'sk-test-1234',
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$result = $this->gate->intercept_rest( null, array(), $request );

		$this->assertWPError( $result );
		$this->assertSame( 'sudo_required', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 'connectors.update_credentials', $result->get_error_data()['rule_id'] );
	}

	/**
	 * SURF-02: Cookie-auth POST /wp/v2/settings with a non-OpenAI vendor connector
	 * key also requires sudo — tests regex `connectors_[a-z0-9_]+_api_key` matches
	 * non-openai vendor patterns.
	 */
	public function test_cookie_auth_other_vendor_connector_key_gates(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		$request = new \WP_REST_Request(
			'POST',
			'/wp/v2/settings'
		);
		$request->set_body_params(
			array(
				'connectors_anthropic_claude_api_key' => 'sk-ant-test',
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$result = $this->gate->intercept_rest( null, array(), $request );

		$this->assertWPError( $result );
		$this->assertSame( 'sudo_required', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 'connectors.update_credentials', $result->get_error_data()['rule_id'] );
	}
}
