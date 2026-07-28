<?php
/**
 * Tests for the public WP Sudo API helpers.
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use WP_Sudo\Public_API;
use WP_Sudo\Sudo_Session;
use WP_Sudo\Tests\TestCase;

/**
 * @covers \WP_Sudo\Public_API
 */
class PublicApiTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
	}

	public function test_check_returns_false_when_no_user_is_available(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		$this->assertFalse( Public_API::check() );
	}

	public function test_check_returns_true_for_active_session(): void {
		$user_id = 12;
		$token   = 'public-api-token';

		Functions\when('get_current_user_id')->justReturn($user_id);

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		$record = $this->make_proof_map( $user_id, $token, time() + 300 );

		Functions\when( 'get_user_meta' )->alias(
			static function ( int $uid, string $meta_key, bool $single ) use ( $user_id, $record ) {
				if ( $uid !== $user_id || true !== $single ) {
					return '';
				}

				if ( Sudo_Session::META_KEY === $meta_key ) {
					return time() + 300;
				}

				if ( Sudo_Session::PROOF_META_KEY === $meta_key ) {
					return $record;
				}

				return '';
			}
		);

		$this->assertTrue( Public_API::check( $user_id ) );
	}

	/**
	 * Locks in the defense-in-depth user-ID check in Sudo_Session::verify_token().
	 *
	 * Even when a target user has a valid expiry and a token hash that matches
	 * the browser's TOKEN_COOKIE, Public_API::check() must return false if the
	 * target user differs from the current request's authenticated user. A
	 * caller passing someone else's user ID never gets a "yes".
	 */
	public function test_check_returns_false_when_target_differs_from_current_user(): void {
		$current_user_id = 5;
		$other_user_id   = 99;
		$token           = 'other-user-token';

		Functions\when( 'get_current_user_id' )->justReturn( $current_user_id );

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		$record = $this->make_proof_map( $other_user_id, $token, time() + 300 );

		Functions\when( 'get_user_meta' )->alias(
			static function ( int $uid, string $meta_key, bool $single ) use ( $other_user_id, $record ) {
				if ( $uid !== $other_user_id || true !== $single ) {
					return '';
				}

				if ( Sudo_Session::META_KEY === $meta_key ) {
					return time() + 300;
				}

				if ( Sudo_Session::PROOF_META_KEY === $meta_key ) {
					return $record;
				}

				return '';
			}
		);

		$this->assertFalse( Public_API::check( $other_user_id ) );
	}

	/**
	 * F18f — The explicit cross-user guard must short-circuit before any
	 * session lookup: is_active()/is_within_grace() must not be reached for
	 * cross-user calls. Passing a different user ID returns false without
	 * ever reading user meta.
	 *
	 * @since 3.1.5
	 */
	public function test_check_cross_user_guard_fires_before_session_lookup(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );

		// The explicit guard must short-circuit before get_user_meta is called.
		Functions\expect( 'get_user_meta' )->never();

		$this->assertFalse( Public_API::check( 99 ) );
	}

	/**
	 * Locks in the same tightened semantics for require(): passing an
	 * arg-supplied user_id that differs from the current user never short-
	 * circuits as "already authenticated", even when the target user has
	 * an active session. The gated flow always triggers for cross-user calls.
	 */
	public function test_require_does_not_treat_other_users_session_as_active(): void {
		$current_user_id = 5;
		$other_user_id   = 99;
		$token           = 'other-user-token';

		Functions\when( 'get_current_user_id' )->justReturn( $current_user_id );

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		$record = $this->make_proof_map( $other_user_id, $token, time() + 300 );

		Functions\when( 'get_user_meta' )->alias(
			static function ( int $uid, string $meta_key, bool $single ) use ( $other_user_id, $record ) {
				if ( $uid !== $other_user_id || true !== $single ) {
					return '';
				}

				if ( Sudo_Session::META_KEY === $meta_key ) {
					return time() + 300;
				}

				if ( Sudo_Session::PROOF_META_KEY === $meta_key ) {
					return $record;
				}

				return '';
			}
		);

		// Gated flow must fire for the arg-supplied user_id, not treat the
		// other user's active session as authorization.
		Actions\expectDone( 'wp_sudo_action_gated' )
			->once()
			->with( $other_user_id, 'cross_user.call', 'public_api' );

		Functions\expect( 'wp_safe_redirect' )->never();

		$this->assertFalse(
			Public_API::require(
				array(
					'user_id'  => $other_user_id,
					'rule_id'  => 'cross_user.call',
					'redirect' => false,
				)
			)
		);
	}

	public function test_require_returns_false_when_redirect_is_disabled(): void {
		$user_id = 7;

		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
		Functions\when( 'get_user_meta' )->justReturn( '' );

		Actions\expectDone( 'wp_sudo_action_gated' )
			->once()
			->with( $user_id, 'custom.action', 'public_api' );

		Functions\expect( 'wp_safe_redirect' )->never();

		$this->assertFalse(
			Public_API::require(
				array(
					'rule_id'  => 'custom.action',
					'redirect' => false,
				)
			)
		);
	}

	/**
	 * #461: an explicitly-passed return_url gets a runtime signal.
	 *
	 * The argument went inert in 4.9.0 (#322) — still accepted, still emitted into
	 * the challenge URL, consumed by nothing, because navigating to a
	 * requester-supplied destination after a successful challenge would execute
	 * under the sudo authority just minted. Integrators who pass it got no signal
	 * at all: their users land on the dashboard and nothing says why.
	 *
	 * _deprecated_argument() rather than _doing_it_wrong(): the caller made no
	 * mistake. They followed a documented API and the argument is still documented
	 * as accepted, so this is "this argument no longer does anything", which is
	 * what _deprecated_argument() is for. It also routes to E_USER_DEPRECATED and
	 * the deprecated_argument_run action, which aggregators bucket apart from real
	 * bugs.
	 *
	 * Not to be read as reversing Admin::cleanup_inert_governance_mode_option(),
	 * which deliberately used an audit hook INSTEAD of a notice (pinned by four
	 * ->never() assertions in AdminTest). Its production comment gives the reason:
	 * the option name "was never a callable, so _doing_it_wrong() was inappropriate
	 * here". Here the subject is a callable and the misuse is one of its arguments.
	 */
	public function test_require_flags_an_explicitly_passed_return_url(): void {
		$user_id = 88;

		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
		Functions\when( 'get_user_meta' )->justReturn( '' );

		Functions\expect( '_deprecated_argument' )
			->once()
			->with( 'wp_sudo_require', '4.9.0', \Mockery::type( 'string' ) );

		Public_API::require(
			array(
				'rule_id'    => 'custom.action',
				'redirect'   => false,
				'return_url' => 'https://example.com/wp-admin/tools.php',
			)
		);
	}

	/**
	 * #461: the overwhelmingly common call passes no return_url and must stay silent.
	 */
	public function test_require_does_not_flag_when_return_url_is_absent(): void {
		$user_id = 89;

		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
		Functions\when( 'get_user_meta' )->justReturn( '' );

		Functions\expect( '_deprecated_argument' )->never();

		Public_API::require(
			array(
				'rule_id'  => 'custom.action',
				'redirect' => false,
			)
		);
	}

	/**
	 * #461: an empty string is the default and is not a use of the argument.
	 *
	 * This also pins the referer fallback out of scope. sanitize_return_url()
	 * derives a URL from HTTP_REFERER when the caller passes nothing, so the
	 * emitted return_url is frequently non-empty on calls that never named it.
	 * Keying the notice off the emitted value rather than the passed argument
	 * would fire on callers who did nothing at all.
	 */
	public function test_require_does_not_flag_an_empty_return_url(): void {
		$user_id = 90;

		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
		Functions\when( 'get_user_meta' )->justReturn( '' );

		Functions\expect( '_deprecated_argument' )->never();

		Public_API::require(
			array(
				'rule_id'    => 'custom.action',
				'redirect'   => false,
				'return_url' => '',
			)
		);
	}

	/**
	 * #461: the signal is about the call, not the outcome.
	 *
	 * Fired at every exit path, so it does not depend on a user resolving or on
	 * there being no active session. An integrator whose users usually hold a live
	 * session would otherwise see the notice rarely or never — which is exactly
	 * the silence #461 exists to end.
	 */
	public function test_require_flags_return_url_even_when_sudo_is_already_active(): void {
		$user_id = 91;
		$token   = 'active-session-token';

		// An earlier cut of this test stubbed get_user_meta() to '' throughout, so
		// check() resolved FALSE and the call took the ordinary gated path. It
		// asserted nothing about the branch its name claims: moving the emission
		// below the check() short-circuit would have left it green. A real proof
		// and cookie are installed here so check() returns true and the
		// short-circuit is the exit actually exercised.
		Functions\when( 'get_current_user_id' )->justReturn( $user_id );

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		$record = $this->make_proof_map( $user_id, $token, time() + 300 );

		Functions\when( 'get_user_meta' )->alias(
			static function ( int $uid, string $meta_key, bool $single ) use ( $user_id, $record ) {
				if ( $uid !== $user_id || true !== $single ) {
					return '';
				}

				if ( Sudo_Session::META_KEY === $meta_key ) {
					return time() + 300;
				}

				if ( Sudo_Session::PROOF_META_KEY === $meta_key ) {
					return $record;
				}

				return '';
			}
		);

		Functions\expect( '_deprecated_argument' )
			->once()
			->with( 'wp_sudo_require', '4.9.0', \Mockery::type( 'string' ) );

		// Returning true proves the check() short-circuit was the exit taken, and
		// the expectation above proves it still emitted on the way out.
		$this->assertTrue(
			Public_API::require(
				array(
					'rule_id'    => 'custom.action',
					'redirect'   => false,
					'return_url' => 'https://example.com/wp-admin/tools.php',
				)
			),
			'Precondition: sudo must be active so the check() short-circuit is exercised.'
		);
	}

	/**
	 * #461: and not on the unresolved-user path either, which returns before the
	 * gated-action hook ever fires.
	 */
	public function test_require_flags_return_url_when_no_user_resolves(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		Functions\expect( '_deprecated_argument' )
			->once()
			->with( 'wp_sudo_require', '4.9.0', \Mockery::type( 'string' ) );

		$this->assertFalse(
			Public_API::require(
				array(
					'rule_id'    => 'custom.action',
					'return_url' => 'https://example.com/wp-admin/tools.php',
				)
			)
		);
	}

	public function test_require_returns_false_when_headers_are_already_sent(): void {
		$user_id = 21;

		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'headers_sent' )->justReturn( true );

		Actions\expectDone( 'wp_sudo_action_gated' )
			->once()
			->with( $user_id, 'plugin.activate', 'public_api' );

		Functions\expect( 'wp_safe_redirect' )->never();

		$this->assertFalse(
			Public_API::require(
				array(
					'rule_id' => 'plugin.activate',
				)
			)
		);
	}

	/**
	 * #461 regression: the notice must never be emitted before the redirect.
	 *
	 * `_deprecated_argument()` prints under WP_DEBUG with display_errors on. Any
	 * output makes `headers_sent()` true, and `can_redirect_to_challenge()`
	 * returns the negation of that — so emitting the notice at the top of
	 * require() cancelled the challenge redirect outright, on exactly the
	 * debug-enabled installs whose developers would be reading the notice. A
	 * cosmetic gap became a functional one.
	 *
	 * This pins "not before", not "after": `wp_safe_redirect` is stubbed to throw
	 * (the suite's standard way of stopping short of `exit`), so nothing past it
	 * is reachable here. The emission on the redirect path therefore never runs
	 * in this test, and `never()` is exactly right — under the original ordering
	 * the notice fired before the redirect and this assertion fails.
	 *
	 * The redirect-path interaction is exercised by the PUB-01 E2E test, whose
	 * fixture passes `return_url` and expects the challenge — that is what caught
	 * this, and no existing unit test did. (PUB-02 shares the fixture and reaches
	 * the check()-true exit with the same non-empty `return_url`, so it emits too;
	 * only the redirect coupling is PUB-01's.)
	 */
	public function test_require_does_not_emit_the_return_url_notice_before_redirecting(): void {
		$user_id = 92;

		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'headers_sent' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_network_admin' )->justReturn( false );
		Functions\when( 'admin_url' )->alias(
			static function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
			}
		);
		$this->stub_faithful_add_query_arg();

		Functions\expect( 'wp_safe_redirect' )
			->once()
			->andThrow( new \RuntimeException( 'redirected' ) );

		// The assertion: nothing was emitted on the way to the redirect.
		Functions\expect( '_deprecated_argument' )->never();

		$this->expectException( \RuntimeException::class );

		Public_API::require(
			array(
				'rule_id'    => 'custom.action',
				'return_url' => 'https://example.com/wp-admin/tools.php',
			)
		);
	}

	public function test_require_redirects_to_challenge_page_when_interactive(): void {
		$user_id = 33;

		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'headers_sent' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_network_admin' )->justReturn( false );
		Functions\when( 'admin_url' )->alias(
			static function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
			}
		);

		$_SERVER['HTTP_REFERER'] = 'https://example.com/wp-admin/plugins.php';

		Functions\expect( 'add_query_arg' )
			->once()
			->with(
				\Mockery::on(
					static function ( array $args ): bool {
						return 'wp-sudo-challenge' === ( $args['page'] ?? '' )
							&& rawurlencode( 'https://example.com/wp-admin/plugins.php' ) === ( $args['return_url'] ?? '' );
					}
				),
				'https://example.com/wp-admin/admin.php'
			)
			->andReturn( 'https://example.com/wp-admin/admin.php?page=wp-sudo-challenge' );

		Actions\expectDone( 'wp_sudo_action_gated' )
			->once()
			->with( $user_id, 'cron.run', 'public_api' );

		Functions\expect( 'wp_safe_redirect' )
			->once()
			->with( 'https://example.com/wp-admin/admin.php?page=wp-sudo-challenge' )
			->andThrow( new \RuntimeException( 'redirected' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirected' );

		Public_API::require( array( 'rule_id' => 'cron.run' ) );
	}

	/**
	 * Bug: settings-tab-lost-on-reauth-replay (7th affected site — Public_API::
	 * build_challenge_url(), missed by the first pass of the fix).
	 *
	 * build_challenge_url() nests a full URL (which already contains its own
	 * query string, e.g. "...options-general.php?page=wp-sudo-settings&tab=access")
	 * as a raw VALUE inside the array given to add_query_arg(). Real WP core's
	 * add_query_arg()/build_query() do NOT urlencode newly-added array values,
	 * so the nested "&tab=access" becomes a new sibling top-level query
	 * parameter, truncating return_url at the first "&" once the browser
	 * round-trips the link through $_GET. This test uses FAITHFUL
	 * add_query_arg() semantics (TestCase::stub_faithful_add_query_arg())
	 * rather than the Mockery::on()/andReturn() stub used above, which
	 * cannot detect this defect.
	 */
	public function test_require_redirect_preserves_nested_query_string_in_return_url(): void {
		$user_id = 34;

		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'headers_sent' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_network_admin' )->justReturn( false );
		Functions\when( 'admin_url' )->alias(
			static function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
			}
		);
		$this->stub_faithful_add_query_arg();

		$_SERVER['HTTP_REFERER'] = 'https://example.com/wp-admin/options-general.php?page=wp-sudo-settings&tab=access';

		Actions\expectDone( 'wp_sudo_action_gated' )
			->once()
			->with( $user_id, 'cron.run', 'public_api' );

		$captured_url = null;
		Functions\expect( 'wp_safe_redirect' )
			->once()
			->andReturnUsing(
				function ( $url ) use ( &$captured_url ) {
					$captured_url = $url;
					throw new \RuntimeException( 'redirected' );
				}
			);

		try {
			Public_API::require( array( 'rule_id' => 'cron.run' ) );
			$this->fail( 'Expected RuntimeException from wp_safe_redirect stub.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertIsString( $captured_url );

		// Simulate the browser navigating to $captured_url and PHP parsing its
		// query string into $_GET, exactly as Challenge::enqueue_assets() would see it.
		$parts = parse_url( $captured_url );
		parse_str( $parts['query'] ?? '', $get );

		$this->assertArrayHasKey( 'return_url', $get );
		$this->assertStringContainsString(
			'tab=access',
			$get['return_url'],
			'The Public_API::require() challenge_url must carry a return_url that survives the browser\'s query-string round trip with &tab=access intact.'
		);

		unset( $_SERVER['HTTP_REFERER'] );
	}

	public function test_require_calls_wp_die_when_redirect_fails(): void {
		$user_id = 41;

		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'headers_sent' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_network_admin' )->justReturn( false );
		Functions\when( 'admin_url' )->alias(
			static function ( string $path = '' ): string {
				return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
			}
		);
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/wp-admin/admin.php?page=wp-sudo-challenge' );

		Actions\expectDone( 'wp_sudo_action_gated' )
			->once()
			->with( $user_id, 'user.delete', 'public_api' );

		Functions\expect( 'wp_safe_redirect' )
			->once()
			->with( 'https://example.com/wp-admin/admin.php?page=wp-sudo-challenge' )
			->andReturn( false );

		Functions\expect( 'wp_die' )
			->once()
			->with(
				\Mockery::type( 'string' ),
				'',
				array( 'response' => 403 )
			)
			->andReturn( null );

		$this->assertFalse( Public_API::require( array( 'rule_id' => 'user.delete' ) ) );
	}
}
