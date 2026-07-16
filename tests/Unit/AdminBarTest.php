<?php
/**
 * Tests for Admin_Bar.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Admin_Bar;
use WP_Sudo\Sudo_Session;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;

/**
 * @covers \WP_Sudo\Admin_Bar
 */
class AdminBarTest extends TestCase {

	/**
	 * Admin_Bar instance under test.
	 *
	 * @var Admin_Bar
	 */
	private Admin_Bar $admin_bar;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'force_ssl_admin' )->justReturn( false );
		$this->admin_bar = new Admin_Bar();
	}

	protected function tearDown(): void {
		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
		unset( $_SERVER['REQUEST_URI'] );
		unset( $_SERVER['HTTP_HOST'] );
		parent::tearDown();
	}

	// ── Constants ─────────────────────────────────────────────────────

	public function test_deactivate_nonce_constant(): void {
		$this->assertSame( 'wp_sudo_deactivate', Admin_Bar::DEACTIVATE_NONCE );
	}

	public function test_deactivate_param_constant(): void {
		$this->assertSame( 'wp_sudo_deactivate', Admin_Bar::DEACTIVATE_PARAM );
	}

	public function test_redirect_param_constant(): void {
		$this->assertSame( 'wp_sudo_redirect_to', Admin_Bar::REDIRECT_PARAM );
	}

	// ── register() ───────────────────────────────────────────────────

	public function test_register_hooks(): void {
		Actions\expectAdded( 'admin_bar_menu' )
			->once()
			->with( array( $this->admin_bar, 'admin_bar_node' ), 100 );

		Actions\expectAdded( 'init' )
			->once()
			->with( array( $this->admin_bar, 'handle_deactivate' ), 5, 0 );

		Actions\expectAdded( 'admin_enqueue_scripts' )
			->once()
			->with( array( $this->admin_bar, 'enqueue_assets' ), 10, 0 );

		$this->admin_bar->register();
	}

	// ── admin_bar_node() ─────────────────────────────────────────────

	public function test_admin_bar_node_skips_anonymous(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		$bar = new \WP_Admin_Bar();
		$this->admin_bar->admin_bar_node( $bar );

		$this->assertEmpty( $bar->get_nodes() );
	}

	public function test_admin_bar_node_skips_no_session(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_user_meta' )->justReturn( '' );

		$bar = new \WP_Admin_Bar();
		$this->admin_bar->admin_bar_node( $bar );

		$this->assertEmpty( $bar->get_nodes() );
	}

	public function test_admin_bar_node_shows_for_active_session(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( '__' )->returnArg();
		Functions\expect( 'admin_url' )
			->once()
			->andReturn( 'https://example.com/wp-admin/' );
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\expect( 'esc_url_raw' )
			->once()
			->with( 'https://example.com/sample-page/' )
			->andReturn( 'https://example.com/sample-page/' );
		Functions\expect( 'add_query_arg' )
			->once()
			->with(
				\Mockery::on(
					static function ( array $args ): bool {
						return isset( $args[ Admin_Bar::DEACTIVATE_PARAM ], $args[ Admin_Bar::REDIRECT_PARAM ] )
							&& '1' ==== $args[ Admin_Bar::DEACTIVATE_PARAM ]
							&& rawurlencode( 'https://example.com/sample-page/' ) === $args[ Admin_Bar::REDIRECT_PARAM ];
					}
				),
				'https://example.com/wp-admin/'
			)
			->andReturn( 'https://example.com/wp-admin/?wp_sudo_deactivate=1&wp_sudo_redirect_to=https%3A%2F%2Fexample.com%2Fsample-page%2F' );
		Functions\expect( 'wp_nonce_url' )
			->once()
			->with(
				'https://example.com/wp-admin/?wp_sudo_deactivate=1&wp_sudo_redirect_to=https%3A%2F%2Fexample.com%2Fsample-page%2F',
				Admin_Bar::DEACTIVATE_NONCE,
				'_wpnonce'
			)
			->andReturn( 'https://example.com/wp-admin/?wp_sudo_deactivate=1&wp_sudo_redirect_to=https%3A%2F%2Fexample.com%2Fsample-page%2F&_wpnonce=abc' );

		$_SERVER['REQUEST_URI'] = '/sample-page/';
		$_SERVER['HTTP_HOST']   = 'example.com';

		$future = time() + 300;
		$token  = 'bar-token-123';

		Functions\when( 'get_user_meta' )->alias( function ( $uid, $key, $single ) use ( $future, $token ) {
			if ( Sudo_Session::META_KEY === $key ) {
				return $future;
			}
			if ( Sudo_Session::TOKEN_META_KEY === $key ) {
				return hash( 'sha256', $token );
			}
			return '';
		} );

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		$bar = new \WP_Admin_Bar();
		$this->admin_bar->admin_bar_node( $bar );

		$nodes = $bar->get_nodes();
		$this->assertArrayHasKey( 'wp-sudo-active', $nodes );
		$this->assertSame(
			'https://example.com/wp-admin/?wp_sudo_deactivate=1&wp_sudo_redirect_to=https%3A%2F%2Fexample.com%2Fsample-page%2F&_wpnonce=abc',
			$nodes['wp-sudo-active']['href']
		);
	}

	/**
	 * Bug: settings-tab-lost-on-reauth-replay (single-site, CONFIRMED — 4th
	 * trigger: admin-bar sudo deactivation from a tabbed/query-string page).
	 *
	 * admin_bar_node() builds the deactivate URL by nesting $current_url — a
	 * full URL that already contains its own query string (e.g.
	 * "...options-general.php?page=wp-sudo-settings&tab=access") — as a raw
	 * VALUE inside the array given to add_query_arg() (self::REDIRECT_PARAM).
	 * Real WP core's add_query_arg()/build_query() do not urlencode newly-added
	 * array values, so the nested "&tab=access" becomes a new sibling
	 * top-level query parameter on the deactivate URL instead of staying part
	 * of the REDIRECT_PARAM value. When handle_deactivate() later reads
	 * $_GET[REDIRECT_PARAM] back (class-admin-bar.php:186-188), it is
	 * truncated at the first "&" and "tab=access" is lost.
	 *
	 * This test uses FAITHFUL add_query_arg() semantics
	 * (TestCase::stub_faithful_add_query_arg()) rather than the fully
	 * hand-fabricated Mockery expectation/andReturn() string used by
	 * test_admin_bar_node_shows_for_active_session() above (which hardcodes
	 * a pre-percent-encoded fake result and does not exercise a query-string
	 * REQUEST_URI at all), so it can actually detect the defect.
	 */
	public function test_admin_bar_node_deactivate_url_preserves_tab_query_arg(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( '__' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_nonce_url' )->alias(
			static fn( string $url, $action = -1, string $name = '_wpnonce' ): string => $url . '&' . $name . '=abc'
		);
		$this->stub_faithful_add_query_arg();

		$_SERVER['REQUEST_URI'] = '/wp-admin/options-general.php?page=wp-sudo-settings&tab=access';
		$_SERVER['HTTP_HOST']   = 'example.com';

		$future = time() + 300;
		$token  = 'bar-token-tab';

		Functions\when( 'get_user_meta' )->alias(
			function ( $uid, $key, $single ) use ( $future, $token ) {
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

		$bar = new \WP_Admin_Bar();
		$this->admin_bar->admin_bar_node( $bar );

		$nodes = $bar->get_nodes();
		$this->assertArrayHasKey( 'wp-sudo-active', $nodes );

		// Simulate the browser navigating to the deactivate href and PHP
		// parsing its query string into $_GET, exactly as handle_deactivate()
		// would see it via $_GET[ Admin_Bar::REDIRECT_PARAM ].
		$parts = parse_url( $nodes['wp-sudo-active']['href'] );
		parse_str( $parts['query'] ?? '', $get );

		$this->assertArrayHasKey( Admin_Bar::REDIRECT_PARAM, $get );
		$this->assertStringContainsString(
			'tab=access',
			$get[ Admin_Bar::REDIRECT_PARAM ],
			'The admin-bar deactivate URL\'s redirect_to must survive the browser\'s query-string round trip with &tab=access intact.'
		);
	}

	// ── handle_deactivate() ──────────────────────────────────────────

	public function test_handle_deactivate_does_nothing_without_param(): void {
		unset( $_GET['wp_sudo_deactivate'] );

		// Should not call get_current_user_id.
		Functions\expect( 'get_current_user_id' )->never();

		$this->admin_bar->handle_deactivate();
	}

	public function test_handle_deactivate_skips_anonymous(): void {
		$_GET['wp_sudo_deactivate'] = '1';
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		// Should not verify nonce.
		Functions\expect( 'wp_verify_nonce' )->never();

		$this->admin_bar->handle_deactivate();

		unset( $_GET['wp_sudo_deactivate'] );
	}

	public function test_handle_deactivate_dies_on_invalid_nonce(): void {
		$_GET['wp_sudo_deactivate'] = '1';
		$_GET['_wpnonce']           = 'bad-nonce';

		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'esc_html__' )->returnArg();

		Functions\expect( 'wp_die' )
			->once()
			->with( 'Security check failed.', '', array( 'response' => 403 ) )
			->andThrow( new \RuntimeException( 'nonce failed' ) );

		try {
			$this->admin_bar->handle_deactivate();
			$this->fail( 'Expected nonce failure.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'nonce failed', $e->getMessage() );
		}

		unset( $_GET['wp_sudo_deactivate'], $_GET['_wpnonce'] );
	}

	public function test_handle_deactivate_deactivates_session_and_redirects_on_valid_nonce(): void {
		$_GET['wp_sudo_deactivate']  = '1';
		$_GET['_wpnonce']            = 'good-nonce';
		$_GET['wp_sudo_redirect_to'] = 'https://example.com/wp-admin/plugins.php?foo=1';

		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'delete_user_meta' )->justReturn( true );
		Functions\when( 'headers_sent' )->justReturn( false );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'setcookie' )->justReturn( true );
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\expect( 'remove_query_arg' )
			->once()
			->with(
				array( Admin_Bar::DEACTIVATE_PARAM, Admin_Bar::REDIRECT_PARAM, '_wpnonce' ),
				'https://example.com/wp-admin/plugins.php?foo=1'
			)
			->andReturn( 'https://example.com/wp-admin/plugins.php?foo=1' );

		Actions\expectDone( 'wp_sudo_deactivated' )
			->once()
			->with( 5 );

		Functions\expect( 'wp_safe_redirect' )
			->once()
			->with( 'https://example.com/wp-admin/plugins.php?foo=1' )
			->andThrow( new \RuntimeException( 'redirected' ) );

		try {
			$this->admin_bar->handle_deactivate();
			$this->fail( 'Expected redirect short-circuit.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		unset( $_GET['wp_sudo_deactivate'], $_GET['_wpnonce'], $_GET['wp_sudo_redirect_to'] );
	}

	// ── enqueue_assets() ─────────────────────────────────────────────

	public function test_enqueue_assets_skips_anonymous(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		Functions\expect( 'wp_enqueue_style' )->never();

		$this->admin_bar->enqueue_assets();
	}

	public function test_enqueue_assets_skips_no_session(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_user_meta' )->justReturn( '' );

		Functions\expect( 'wp_enqueue_style' )->never();

		$this->admin_bar->enqueue_assets();
	}

	public function test_enqueue_assets_loads_for_active_session(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );

		$future = time() + 300;
		$token  = 'asset-token-456';

		Functions\when( 'get_user_meta' )->alias( function ( $uid, $key, $single ) use ( $future, $token ) {
			if ( Sudo_Session::META_KEY === $key ) {
				return $future;
			}
			if ( Sudo_Session::TOKEN_META_KEY === $key ) {
				return hash( 'sha256', $token );
			}
			return '';
		} );

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		Functions\when( 'wp_enqueue_style' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->justReturn( true );
		Functions\when( 'wp_localize_script' )->justReturn( true );

		$this->admin_bar->enqueue_assets();
		$this->assertTrue( true );
	}
}
