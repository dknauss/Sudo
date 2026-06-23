<?php
/**
 * Tests for the REST effect-level backstop (deferred #102 follow-up, Item 1).
 *
 * Mirrors the interactive admin backstop on the REST surface: a destructive,
 * gated-equivalent effect invoked through a non-enumerated/custom REST route —
 * one that intercept_rest() does not match — must be blocked at the effect
 * boundary when no sudo window is active, while honouring the Application
 * Password policy so legitimate headless clients are not over-blocked.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Gate;
use WP_Sudo\Sudo_Session;
use WP_Sudo\Request_Stash;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

/**
 * @covers \WP_Sudo\Gate::register_rest_backstop
 * @covers \WP_Sudo\Gate::arm_effect_guards
 * @covers \WP_Sudo\Gate::rest_auth_mode
 * @covers \WP_Sudo\Gate::die_rest_sudo_required
 */
class RestBackstopTest extends TestCase {

	/**
	 * Gate instance under test.
	 *
	 * @var Gate
	 */
	private Gate $gate;

	protected function setUp(): void {
		parent::setUp();

		$this->gate = new Gate(
			\Mockery::mock( Sudo_Session::class ),
			\Mockery::mock( Request_Stash::class )
		);

		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'is_wp_error' )->justReturn( false );

		$this->clear_request_state();
	}

	protected function tearDown(): void {
		$this->clear_request_state();
		parent::tearDown();
	}

	/**
	 * Clear the request superglobals the backstop reads, to keep order-independent.
	 */
	private function clear_request_state(): void {
		unset(
			$_SERVER['HTTP_X_WP_NONCE'],
			$_REQUEST['_wpnonce'],
			$_COOKIE[ Sudo_Session::TOKEN_COOKIE ]
		);
	}

	/**
	 * Register the backstop and return the captured `delete_user` guard closure.
	 *
	 * @return callable
	 */
	private function capture_delete_user_guard(): callable {
		$closure = null;
		Actions\expectAdded( 'delete_user' )
			->once()
			->with(
				\Mockery::on(
					static function ( $candidate ) use ( &$closure ): bool {
						$closure = $candidate;
						return is_callable( $candidate );
					}
				),
				0
			);

		$this->gate->register_rest_backstop();

		$this->assertIsCallable( $closure );
		return $closure;
	}

	/**
	 * Invoke the private rest_auth_mode() classifier via reflection.
	 *
	 * @return string
	 */
	private function invoke_rest_auth_mode(): string {
		$method = new \ReflectionMethod( Gate::class, 'rest_auth_mode' );
		@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return (string) $method->invoke( $this->gate, null );
	}

	// =====================================================================
	// Arming
	// =====================================================================

	/**
	 * The REST backstop does nothing for anonymous requests.
	 */
	public function test_rest_backstop_skips_when_not_logged_in(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		Actions\expectAdded( 'delete_user' )->never();

		$this->gate->register_rest_backstop();
	}

	/**
	 * The REST backstop arms exactly the same destructive effect set as the
	 * admin backstop — and excludes the deferred user.create/user.promote hooks.
	 */
	public function test_rest_backstop_arms_same_effect_set(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		Actions\expectAdded( 'activate_plugin' )->once();
		Actions\expectAdded( 'delete_plugin' )->once();
		Actions\expectAdded( 'delete_theme' )->once();
		Actions\expectAdded( 'delete_user' )->once();
		Actions\expectAdded( 'export_wp' )->once();
		Filters\expectAdded( 'upgrader_pre_install' )->once();

		// Excluded by design (deferred increment / incidental writes).
		Filters\expectAdded( 'wp_pre_insert_user_data' )->never();
		Filters\expectAdded( 'update_user_metadata' )->never();

		$this->gate->register_rest_backstop();
	}

	// =====================================================================
	// Guard behaviour
	// =====================================================================

	/**
	 * An active sudo window allows the effect silently — no block, no audit.
	 */
	public function test_rest_backstop_allows_when_sudo_active(): void {
		$future = time() + 300;
		$token  = 'valid-token';

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->alias(
			static function ( $uid, $key, $single ) use ( $future, $token ) {
				if ( Sudo_Session::META_KEY === $key ) {
					return $future;
				}
				if ( Sudo_Session::TOKEN_META_KEY === $key ) {
					return hash( 'sha256', $token );
				}
				return '';
			}
		);
		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		Actions\expectDone( 'wp_sudo_action_blocked' )->never();
		Actions\expectDone( 'wp_sudo_action_allowed' )->never();
		Functions\expect( 'wp_die' )->never();

		$guard = $this->capture_delete_user_guard();
		$guard();
	}

	/**
	 * A cookie-authenticated browser request with no sudo is hard-blocked, with
	 * a blocked (not gated) audit on the 'rest' surface — no challenge follows.
	 */
	public function test_rest_backstop_blocks_cookie_auth_without_sudo(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( '' ); // is_active/grace false.

		// Cookie auth: a valid REST nonce and no App Password credential.
		$_SERVER['HTTP_X_WP_NONCE'] = 'valid';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'rest_get_authenticated_app_password' )->justReturn( null );

		Actions\expectDone( 'wp_sudo_action_blocked' )
			->once()
			->with( 1, 'user.delete', 'rest' );
		Functions\expect( 'wp_die' )
			->once()
			->with( \Mockery::type( 'string' ), '', array( 'response' => 403 ) );

		$guard = $this->capture_delete_user_guard();
		$guard();
	}

	/**
	 * An Unrestricted App Password request passes through (audit only).
	 */
	public function test_rest_backstop_allows_unrestricted_app_password(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( '' );

		// No nonce => headless app-password path. Global policy: unrestricted.
		Functions\when( 'get_option' )->justReturn( array( 'rest_app_password_policy' => 'unrestricted' ) );

		Actions\expectDone( 'wp_sudo_action_allowed' )
			->once()
			->with( 1, 'user.delete', 'rest_app_password' );
		Actions\expectDone( 'wp_sudo_action_blocked' )->never();
		Functions\expect( 'wp_die' )->never();

		$guard = $this->capture_delete_user_guard();
		$guard();
	}

	/**
	 * A Limited App Password request is blocked with a logged audit.
	 */
	public function test_rest_backstop_blocks_limited_app_password(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( '' );

		// Default (empty settings) => limited policy.
		Functions\when( 'get_option' )->justReturn( array() );

		Actions\expectDone( 'wp_sudo_action_blocked' )
			->once()
			->with( 1, 'user.delete', 'rest_app_password' );
		Functions\expect( 'wp_die' )->once();

		$guard = $this->capture_delete_user_guard();
		$guard();
	}

	/**
	 * A Disabled App Password request is blocked WITHOUT logging.
	 */
	public function test_rest_backstop_disabled_app_password_blocks_without_log(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( '' );

		Functions\when( 'get_option' )->justReturn( array( 'rest_app_password_policy' => 'disabled' ) );

		Actions\expectDone( 'wp_sudo_action_blocked' )->never();
		Actions\expectDone( 'wp_sudo_action_allowed' )->never();
		Functions\expect( 'wp_die' )->once();

		$guard = $this->capture_delete_user_guard();
		$guard();
	}

	// =====================================================================
	// rest_auth_mode() classification (shared with intercept_rest)
	// =====================================================================

	/**
	 * A valid nonce with no App Password credential classifies as cookie auth.
	 */
	public function test_rest_auth_mode_cookie_when_valid_nonce_and_no_app_password(): void {
		$_SERVER['HTTP_X_WP_NONCE'] = 'valid';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'rest_get_authenticated_app_password' )->justReturn( null );

		$this->assertSame( 'cookie', $this->invoke_rest_auth_mode() );
	}

	/**
	 * A request presenting BOTH a valid nonce AND an App Password is headless —
	 * the nonce cannot be used to bypass the App Password policy (C2).
	 */
	public function test_rest_auth_mode_app_password_when_credential_present_despite_nonce(): void {
		$_SERVER['HTTP_X_WP_NONCE'] = 'valid';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'rest_get_authenticated_app_password' )->justReturn( 'uuid-123' );

		$this->assertSame( 'app_password', $this->invoke_rest_auth_mode() );
	}

	/**
	 * No nonce at all classifies as headless (app-password / bearer).
	 */
	public function test_rest_auth_mode_app_password_when_no_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$this->assertSame( 'app_password', $this->invoke_rest_auth_mode() );
	}

	/**
	 * The _wpnonce request parameter is honoured when the header is absent.
	 */
	public function test_rest_auth_mode_reads_wpnonce_request_param(): void {
		$_REQUEST['_wpnonce'] = 'param-nonce';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'rest_get_authenticated_app_password' )->justReturn( null );

		$this->assertSame( 'cookie', $this->invoke_rest_auth_mode() );
	}
}
