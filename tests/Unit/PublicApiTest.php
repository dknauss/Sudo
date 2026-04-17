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
							&& 'https://example.com/wp-admin/plugins.php' === ( $args['return_url'] ?? '' );
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
