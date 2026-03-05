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
		$this->admin_bar = new Admin_Bar();
	}

	protected function tearDown(): void {
		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
		unset( $_SERVER['REQUEST_URI'] );
		parent::tearDown();
	}

	// ── Constants ─────────────────────────────────────────────────────

	public function test_deactivate_nonce_constant(): void {
		$this->assertSame( 'wp_sudo_deactivate', Admin_Bar::DEACTIVATE_NONCE );
	}

	public function test_deactivate_param_constant(): void {
		$this->assertSame( 'wp_sudo_deactivate', Admin_Bar::DEACTIVATE_PARAM );
	}

	// ── register() ───────────────────────────────────────────────────

	public function test_register_hooks(): void {
		$this->assertIsCallable( array( $this->admin_bar, 'register' ) );
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
		Functions\when( 'wp_nonce_url' )->justReturn( 'https://example.com/wp-admin/plugins.php?wp_sudo_deactivate=1&_wpnonce=abc' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/wp-admin/plugins.php?wp_sudo_deactivate=1' );
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
		Functions\when( 'home_url' )->alias( fn( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'set_url_scheme' )->returnArg();

		$_SERVER['REQUEST_URI'] = '/wp-admin/plugins.php';

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
		$_GET['wp_sudo_deactivate'] = '1';
		$_GET['_wpnonce']           = 'good-nonce';

		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'delete_user_meta' )->justReturn( true );
		Functions\when( 'headers_sent' )->justReturn( false );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'setcookie' )->justReturn( true );
		Functions\when( 'remove_query_arg' )->justReturn( 'https://example.com/wp-admin/plugins.php' );

		Actions\expectDone( 'wp_sudo_deactivated' )
			->once()
			->with( 5 );

		Functions\expect( 'wp_safe_redirect' )
			->once()
			->with( 'https://example.com/wp-admin/plugins.php' )
			->andThrow( new \RuntimeException( 'redirected' ) );

		try {
			$this->admin_bar->handle_deactivate();
			$this->fail( 'Expected redirect short-circuit.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		unset( $_GET['wp_sudo_deactivate'], $_GET['_wpnonce'] );
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

	// ── countdown_script() ───────────────────────────────────────────

	public function test_countdown_script_returns_javascript(): void {
		$script = $this->admin_bar->countdown_script( 300 );

		$this->assertStringContainsString( 'var r=300', $script );
		$this->assertStringContainsString( 'wp-admin-bar-wp-sudo-active', $script );
		$this->assertStringContainsString( 'setInterval', $script );
		$this->assertStringContainsString( 'wp-sudo-expiring', $script );
		$this->assertStringContainsString( 'aria-live', $script );
		$this->assertStringContainsString( 'role', $script );
	}

	public function test_countdown_script_uses_correct_remaining_value(): void {
		$script = $this->admin_bar->countdown_script( 120 );

		$this->assertStringContainsString( 'var r=120', $script );
	}
}
