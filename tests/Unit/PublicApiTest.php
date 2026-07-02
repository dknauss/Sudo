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

		Functions\when( 'get_user_meta' )->alias(
			static function ( int $uid, string $meta_key, bool $single ) use ( $user_id, $token ) {
				if ( $uid !== $user_id || true !== $single ) {
					return '';
				}

				if ( Sudo_Session::META_KEY === $meta_key ) {
					return time() + 300;
				}

				if ( Sudo_Session::TOKEN_META_KEY === $meta_key ) {
					return hash( 'sha256', $token );
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

		Functions\when( 'get_user_meta' )->alias(
			static function ( int $uid, string $meta_key, bool $single ) use ( $other_user_id, $token ) {
				if ( $uid !== $other_user_id || true !== $single ) {
					return '';
				}

				if ( Sudo_Session::META_KEY === $meta_key ) {
					return time() + 300;
				}

				if ( Sudo_Session::TOKEN_META_KEY === $meta_key ) {
					return hash( 'sha256', $token );
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

		Functions\when( 'get_user_meta' )->alias(
			static function ( int $uid, string $meta_key, bool $single ) use ( $other_user_id, $token ) {
				if ( $uid !== $other_user_id || true !== $single ) {
					return '';
				}

				if ( Sudo_Session::META_KEY === $meta_key ) {
					return time() + 300;
				}

				if ( Sudo_Session::TOKEN_META_KEY === $meta_key ) {
					return hash( 'sha256', $token );
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
