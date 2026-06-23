<?php
/**
 * Tests for the two coordinated-disclosure gate-completeness findings.
 *
 * F1 — interactive effect-level backstop: a destructive, gated-equivalent
 *      action invoked through a handler that request-pattern matching does not
 *      enumerate (e.g. a third-party admin-post.php route) must be blocked at
 *      the effect boundary when no sudo window is active.
 *
 * F2 — login-session binding: the sudo proof must be bound to the WordPress
 *      login session that created it, so a captured cookie cannot be replayed
 *      from another session and the window does not outlive logout.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Gate;
use WP_Sudo\Plugin;
use WP_Sudo\Sudo_Session;
use WP_Sudo\Request_Stash;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

/**
 * @covers \WP_Sudo\Sudo_Session::set_token
 * @covers \WP_Sudo\Sudo_Session::verify_token
 * @covers \WP_Sudo\Gate::register_interactive_backstop
 * @covers \WP_Sudo\Plugin::deactivate_session_on_logout
 * @covers \WP_Sudo\Plugin::capture_login_session_token
 */
class ActionGatingCompletenessTest extends TestCase {

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
		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
	}

	// =====================================================================
	// F2 — login-session binding (Sudo_Session::verify_token)
	// =====================================================================

	/**
	 * A non-empty stored bind that does not match the current login-session
	 * token rejects the session — the captured-cookie replay path.
	 */
	public function test_is_active_rejects_when_login_session_bind_mismatches(): void {
		$future = time() + 300;
		$token  = 'valid-token';

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_get_session_token' )->justReturn( 'session-B' );
		Functions\when( 'get_user_meta' )->alias(
			static function ( $uid, $key, $single ) use ( $future, $token ) {
				if ( Sudo_Session::META_KEY === $key ) {
					return $future;
				}
				if ( Sudo_Session::TOKEN_META_KEY === $key ) {
					return hash( 'sha256', $token );
				}
				if ( Sudo_Session::SESSION_BIND_META_KEY === $key ) {
					return hash( 'sha256', 'session-A' );
				}
				return '';
			}
		);

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		$this->assertFalse( Sudo_Session::is_active( 1 ) );
	}

	/**
	 * A non-empty stored bind that matches the current login-session token is
	 * accepted — the legitimate same-session path.
	 */
	public function test_is_active_accepts_when_login_session_bind_matches(): void {
		$future = time() + 300;
		$token  = 'valid-token';

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_get_session_token' )->justReturn( 'session-A' );
		Functions\when( 'get_user_meta' )->alias(
			static function ( $uid, $key, $single ) use ( $future, $token ) {
				if ( Sudo_Session::META_KEY === $key ) {
					return $future;
				}
				if ( Sudo_Session::TOKEN_META_KEY === $key ) {
					return hash( 'sha256', $token );
				}
				if ( Sudo_Session::SESSION_BIND_META_KEY === $key ) {
					return hash( 'sha256', 'session-A' );
				}
				return '';
			}
		);

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		$this->assertTrue( Sudo_Session::is_active( 1 ) );
	}

	/**
	 * An empty stored bind (pre-patch session, or cookie-less activation) skips
	 * the binding check entirely — graceful degradation, no migration required.
	 */
	public function test_is_active_skips_binding_when_bind_value_empty(): void {
		$future = time() + 300;
		$token  = 'valid-token';

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		// wp_get_session_token must NOT be consulted on the empty-bind path.
		Functions\when( 'get_user_meta' )->alias(
			static function ( $uid, $key, $single ) use ( $future, $token ) {
				if ( Sudo_Session::META_KEY === $key ) {
					return $future;
				}
				if ( Sudo_Session::TOKEN_META_KEY === $key ) {
					return hash( 'sha256', $token );
				}
				return ''; // SESSION_BIND_META_KEY → empty.
			}
		);

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		$this->assertTrue( Sudo_Session::is_active( 1 ) );
	}

	/**
	 * set_token() records the login-session bind when a session token resolves.
	 */
	public function test_set_token_stores_login_session_bind_when_token_present(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_generate_password' )->justReturn( 'sudo-token' );
		Functions\when( 'headers_sent' )->justReturn( true );
		Functions\when( 'wp_get_session_token' )->justReturn( 'login-session-1' );
		Functions\when( 'delete_user_meta' )->justReturn( true );

		$writes = array();
		Functions\when( 'update_user_meta' )->alias(
			static function ( $uid, $key, $value ) use ( &$writes ) {
				$writes[] = array( $uid, $key, $value );
				return true;
			}
		);

		$this->invoke_set_token( 1 );

		$this->assertContains(
			array( 1, Sudo_Session::SESSION_BIND_META_KEY, hash( 'sha256', 'login-session-1' ) ),
			$writes
		);
	}

	/**
	 * set_token() clears any stale bind when no login-session token resolves
	 * (cookie-less surfaces), so the empty-bind skip path applies cleanly.
	 */
	public function test_set_token_clears_bind_when_no_session_token(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_generate_password' )->justReturn( 'sudo-token' );
		Functions\when( 'headers_sent' )->justReturn( true );
		Functions\when( 'wp_get_session_token' )->justReturn( '' );
		Functions\when( 'update_user_meta' )->justReturn( true );

		Functions\expect( 'delete_user_meta' )
			->once()
			->with( 1, Sudo_Session::SESSION_BIND_META_KEY );

		$this->invoke_set_token( 1 );
	}

	/**
	 * A pending login-session token (captured at set_logged_in_cookie time)
	 * takes precedence over wp_get_session_token(), which returns empty during
	 * the login request before $_COOKIE is populated.
	 */
	public function test_pending_login_token_is_used_when_session_token_empty(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_generate_password' )->justReturn( 'sudo-token' );
		Functions\when( 'headers_sent' )->justReturn( true );
		Functions\when( 'wp_get_session_token' )->justReturn( '' );
		Functions\when( 'delete_user_meta' )->justReturn( true );

		$writes = array();
		Functions\when( 'update_user_meta' )->alias(
			static function ( $uid, $key, $value ) use ( &$writes ) {
				$writes[] = array( $uid, $key, $value );
				return true;
			}
		);

		Sudo_Session::set_pending_login_token( 'grant-session' );
		$this->invoke_set_token( 1 );

		$this->assertContains(
			array( 1, Sudo_Session::SESSION_BIND_META_KEY, hash( 'sha256', 'grant-session' ) ),
			$writes
		);
	}

	// =====================================================================
	// F2 — logout teardown + token capture (Plugin)
	// =====================================================================

	/**
	 * Plugin::init() wires wp_logout and set_logged_in_cookie.
	 */
	public function test_init_registers_logout_and_token_capture_hooks(): void {
		$this->stub_plugin_init_environment();

		Actions\expectAdded( 'wp_logout' )
			->once()
			->with( \Mockery::type( 'array' ), 10, 1 );
		Actions\expectAdded( 'set_logged_in_cookie' )
			->once()
			->with( \Mockery::type( 'array' ), 10, 6 );

		( new Plugin() )->init();
	}

	/**
	 * Logout deactivates an active sudo session for the user who logged out.
	 */
	public function test_logout_deactivates_active_session(): void {
		Functions\when( 'get_user_meta' )->justReturn( time() + 600 );
		Functions\when( 'delete_user_meta' )->justReturn( true );
		Functions\when( 'headers_sent' )->justReturn( true );

		Actions\expectDone( 'wp_sudo_deactivated' )->once()->with( 12 );

		( new Plugin() )->deactivate_session_on_logout( 12 );
	}

	/**
	 * Logout is a no-op when the user has no active sudo session.
	 */
	public function test_logout_skips_when_no_session(): void {
		Functions\when( 'get_user_meta' )->justReturn( '' );

		Actions\expectDone( 'wp_sudo_deactivated' )->never();

		( new Plugin() )->deactivate_session_on_logout( 12 );
	}

	/**
	 * The token-capture hook stashes the login-session token for binding.
	 */
	public function test_capture_login_session_token_sets_pending(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_generate_password' )->justReturn( 'sudo-token' );
		Functions\when( 'headers_sent' )->justReturn( true );
		Functions\when( 'wp_get_session_token' )->justReturn( '' );
		Functions\when( 'delete_user_meta' )->justReturn( true );

		$writes = array();
		Functions\when( 'update_user_meta' )->alias(
			static function ( $uid, $key, $value ) use ( &$writes ) {
				$writes[] = array( $uid, $key, $value );
				return true;
			}
		);

		( new Plugin() )->capture_login_session_token( 'cookie', 0, 0, 12, 'logged_in', 'captured-session' );
		$this->invoke_set_token( 12 );

		$this->assertContains(
			array( 12, Sudo_Session::SESSION_BIND_META_KEY, hash( 'sha256', 'captured-session' ) ),
			$writes
		);
	}

	// =====================================================================
	// F1 — interactive effect-level backstop (Gate)
	// =====================================================================

	/**
	 * The backstop arms only the unambiguous destructive action/effect hooks,
	 * and deliberately NOT the incidental pre_update_option_* filters or the
	 * deferred user.create / user.promote hooks.
	 */
	public function test_backstop_arms_destructive_hooks_only(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		Actions\expectAdded( 'activate_plugin' )->once();
		Actions\expectAdded( 'delete_plugin' )->once();
		Actions\expectAdded( 'delete_theme' )->once();
		Actions\expectAdded( 'delete_user' )->once();
		Actions\expectAdded( 'export_wp' )->once();
		Filters\expectAdded( 'upgrader_pre_install' )->once();

		// Excluded by design (incidental writes / deferred increments).
		Filters\expectAdded( 'pre_update_option_active_plugins' )->never();
		Filters\expectAdded( 'pre_update_option_stylesheet' )->never();
		Filters\expectAdded( 'wp_pre_insert_user_data' )->never();
		Filters\expectAdded( 'update_user_metadata' )->never();

		$this->gate->register_interactive_backstop();
	}

	/**
	 * The backstop does nothing for anonymous requests.
	 */
	public function test_backstop_skips_when_not_logged_in(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		Actions\expectAdded( 'delete_user' )->never();

		$this->gate->register_interactive_backstop();
	}

	/**
	 * A destructive effect through a non-enumerated handler is hard-blocked
	 * when no sudo window is active.
	 */
	public function test_backstop_blocks_delete_user_without_sudo(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		// is_active() false: no expiry meta. is_within_grace() false: no cookie.
		Functions\when( 'get_user_meta' )->justReturn( '' );

		$callback = null;
		Actions\expectAdded( 'delete_user' )
			->once()
			->with(
				\Mockery::on(
					static function ( $candidate ) use ( &$callback ): bool {
						$callback = $candidate;
						return is_callable( $candidate );
					}
				),
				0
			);

		Actions\expectDone( 'wp_sudo_action_blocked' )
			->once()
			->with( 1, 'user.delete', 'admin' );
		Functions\expect( 'wp_die' )
			->once()
			->with( \Mockery::type( 'string' ), '', array( 'response' => 403 ) );

		$this->gate->register_interactive_backstop();

		$this->assertIsCallable( $callback );
		$callback();
	}

	/**
	 * The same effect is allowed (silently) when a sudo window is active.
	 */
	public function test_backstop_allows_delete_user_with_active_sudo(): void {
		$future = time() + 300;
		$token  = 'valid-token';

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_get_session_token' )->justReturn( 'session-A' );
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

		$callback = null;
		Actions\expectAdded( 'delete_user' )
			->once()
			->with(
				\Mockery::on(
					static function ( $candidate ) use ( &$callback ): bool {
						$callback = $candidate;
						return is_callable( $candidate );
					}
				),
				0
			);

		Actions\expectDone( 'wp_sudo_action_blocked' )->never();
		Functions\expect( 'wp_die' )->never();

		$this->gate->register_interactive_backstop();

		$this->assertIsCallable( $callback );
		$callback();
	}

	/**
	 * Every armed destructive effect (not just delete_user) blocks with its own
	 * rule id when no sudo window is active.
	 */
	public function test_backstop_blocks_every_destructive_effect_without_sudo(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( '' ); // is_active false; no cookie => no grace.
		Functions\when( 'is_wp_error' )->justReturn( false );

		$action_hooks = array(
			'activate_plugin' => 'plugin.activate',
			'delete_plugin'   => 'plugin.delete',
			'delete_theme'    => 'theme.delete',
			'delete_user'     => 'user.delete',
			'export_wp'       => 'tools.export',
		);

		$closures = array();
		foreach ( array_keys( $action_hooks ) as $hook ) {
			Actions\expectAdded( $hook )
				->once()
				->with(
					\Mockery::on(
						static function ( $candidate ) use ( &$closures, $hook ): bool {
							$closures[ $hook ] = $candidate;
							return is_callable( $candidate );
						}
					),
					0
				);
		}

		$upgrader = null;
		Filters\expectAdded( 'upgrader_pre_install' )
			->once()
			->with(
				\Mockery::on(
					static function ( $candidate ) use ( &$upgrader ): bool {
						$upgrader = $candidate;
						return is_callable( $candidate );
					}
				),
				0,
				2
			);

		foreach ( $action_hooks as $rule_id ) {
			Actions\expectDone( 'wp_sudo_action_blocked' )->once()->with( 1, $rule_id, 'admin' );
		}
		Actions\expectDone( 'wp_sudo_action_blocked' )->once()->with( 1, 'plugin.install', 'admin' );
		Functions\expect( 'wp_die' )->times( 6 )->with( \Mockery::type( 'string' ), '', array( 'response' => 403 ) );

		$this->gate->register_interactive_backstop();

		foreach ( $action_hooks as $hook => $rule_id ) {
			$this->assertIsCallable( $closures[ $hook ] );
			$closures[ $hook ]();
		}
		$this->assertIsCallable( $upgrader );
		// A plugin install through a non-enumerated handler is blocked as plugin.install.
		$this->assertTrue( $upgrader( true, array( 'type' => 'plugin', 'action' => 'install' ) ) );
	}

	/**
	 * The upgrader_pre_install guard passes an existing WP_Error straight
	 * through without firing the gate.
	 */
	public function test_backstop_upgrader_passes_through_existing_wp_error(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$upgrader = null;
		Filters\expectAdded( 'upgrader_pre_install' )
			->once()
			->with(
				\Mockery::on(
					static function ( $candidate ) use ( &$upgrader ): bool {
						$upgrader = $candidate;
						return is_callable( $candidate );
					}
				),
				0,
				2
			);

		Actions\expectDone( 'wp_sudo_action_blocked' )->never();
		Functions\expect( 'wp_die' )->never();

		$this->gate->register_interactive_backstop();

		$error = \Mockery::mock( 'WP_Error' );
		$this->assertSame( $error, $upgrader( $error ) );
	}

	/**
	 * The upgrader_pre_install guard maps hook_extra to the precise gated rule,
	 * and passes through updater types that are not a covered rule (language
	 * packs use language_update_type, not type; core carries no plugin/theme
	 * type) so they are neither mislabelled nor over-blocked.
	 */
	public function test_backstop_upgrader_classifies_by_hook_extra(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( '' ); // No active sudo.
		Functions\when( 'is_wp_error' )->justReturn( false );

		$upgrader = null;
		Filters\expectAdded( 'upgrader_pre_install' )
			->once()
			->with(
				\Mockery::on(
					static function ( $candidate ) use ( &$upgrader ): bool {
						$upgrader = $candidate;
						return is_callable( $candidate );
					}
				),
				0,
				2
			);

		// Each covered plugin/theme operation blocks with its precise rule id.
		Actions\expectDone( 'wp_sudo_action_blocked' )->once()->with( 1, 'plugin.install', 'admin' );
		Actions\expectDone( 'wp_sudo_action_blocked' )->once()->with( 1, 'plugin.update', 'admin' );
		Actions\expectDone( 'wp_sudo_action_blocked' )->once()->with( 1, 'theme.install', 'admin' );
		Actions\expectDone( 'wp_sudo_action_blocked' )->once()->with( 1, 'theme.update', 'admin' );
		// Four covered operations block; the two pass-through cases do not.
		Functions\expect( 'wp_die' )->times( 4 )->with( \Mockery::type( 'string' ), '', array( 'response' => 403 ) );

		$this->gate->register_interactive_backstop();
		$this->assertIsCallable( $upgrader );

		$this->assertTrue( $upgrader( true, array( 'type' => 'plugin', 'action' => 'install' ) ) );
		$this->assertTrue( $upgrader( true, array( 'type' => 'plugin', 'action' => 'update' ) ) );
		$this->assertTrue( $upgrader( true, array( 'type' => 'theme', 'action' => 'install' ) ) );
		$this->assertTrue( $upgrader( true, array( 'type' => 'theme', 'action' => 'update' ) ) );

		// Language pack: uses language_update_type (no `type`) → pass through.
		$this->assertTrue( $upgrader( true, array( 'language_update_type' => 'plugin' ) ) );
		// Core update / unknown updater: no plugin/theme type → pass through.
		$this->assertTrue( $upgrader( true, array() ) );
	}

	/**
	 * A non-empty bind with an empty current session token is rejected — covers
	 * the cookie-less / destroyed-session branch of verify_token().
	 */
	public function test_is_active_rejects_when_bind_present_but_session_token_empty(): void {
		$future = time() + 300;
		$token  = 'valid-token';

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_get_session_token' )->justReturn( '' ); // No current login session.
		Functions\when( 'get_user_meta' )->alias(
			static function ( $uid, $key, $single ) use ( $future, $token ) {
				if ( Sudo_Session::META_KEY === $key ) {
					return $future;
				}
				if ( Sudo_Session::TOKEN_META_KEY === $key ) {
					return hash( 'sha256', $token );
				}
				if ( Sudo_Session::SESSION_BIND_META_KEY === $key ) {
					return hash( 'sha256', 'session-A' );
				}
				return '';
			}
		);

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		$this->assertFalse( Sudo_Session::is_active( 1 ) );
	}

	/**
	 * Logout with no explicit user id falls back to the current user.
	 */
	public function test_logout_falls_back_to_current_user(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 15 );
		Functions\when( 'get_user_meta' )->justReturn( time() + 600 );
		Functions\when( 'delete_user_meta' )->justReturn( true );
		Functions\when( 'headers_sent' )->justReturn( true );

		Actions\expectDone( 'wp_sudo_deactivated' )->once()->with( 15 );

		( new Plugin() )->deactivate_session_on_logout( 0 );
	}

	// =====================================================================
	// Helpers
	// =====================================================================

	/**
	 * Invoke the private Sudo_Session::set_token() for a user.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private function invoke_set_token( int $user_id ): void {
		$method = new \ReflectionMethod( Sudo_Session::class, 'set_token' );
		@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$method->invoke( null, $user_id );
	}

	/**
	 * Stub the minimum WordPress surface Plugin::init() touches so it can run.
	 *
	 * @return void
	 */
	private function stub_plugin_init_environment(): void {
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );
		Functions\when( 'plugin_basename' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 100 );
		Functions\when( 'remove_role' )->justReturn( null );
	}
}
