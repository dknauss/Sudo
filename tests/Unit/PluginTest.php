<?php
/**
 * Tests for WP_Sudo\Plugin (v2).
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Plugin;
use WP_Sudo\Gate;
use WP_Sudo\Challenge;
use WP_Sudo\Admin_Bar;
use WP_Sudo\Admin;
use WP_Sudo\Upgrader;
use WP_Sudo\Sudo_Session;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers \WP_Sudo\Plugin
 */
class PluginTest extends TestCase {

	// -----------------------------------------------------------------
	// init()
	// -----------------------------------------------------------------

	public function test_init_creates_all_components(): void {
		$this->stub_init_deps();

		$plugin = new Plugin();
		$plugin->init();

		$this->assertInstanceOf( Gate::class, $plugin->gate() );
		$this->assertInstanceOf( Challenge::class, $plugin->challenge() );
		$this->assertInstanceOf( Admin_Bar::class, $plugin->admin_bar() );
		$this->assertInstanceOf( Admin::class, $plugin->admin() );
	}

	public function test_init_loads_textdomain(): void {
		Functions\expect( 'load_plugin_textdomain' )
			->once()
			->with( 'wp-sudo', false, \Mockery::type( 'string' ) );

		Functions\when( 'get_option' )->justReturn( WP_SUDO_VERSION );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );

		$plugin = new Plugin();
		$plugin->init();
	}

	public function test_init_runs_upgrader(): void {
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );

		// Upgrader reads get_option for the stored version.
		Functions\expect( 'get_option' )
			->with( Upgrader::VERSION_OPTION, '0.0.0' )
			->once()
			->andReturn( WP_SUDO_VERSION );

		$plugin = new Plugin();
		$plugin->init();
	}

	public function test_init_skips_upgrader_on_frontend(): void {
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
		Functions\when( 'get_option' )->justReturn( array() );

		$plugin = new Plugin();
		$plugin->init();

		// Upgrader was never instantiated on front-end — no version option read.
		$this->assertTrue( true );
	}

	public function test_init_creates_admin_when_is_admin(): void {
		$this->stub_init_deps( true );

		$plugin = new Plugin();
		$plugin->init();

		$this->assertInstanceOf( Admin::class, $plugin->admin() );
	}

	public function test_init_skips_admin_when_not_admin(): void {
		$this->stub_init_deps( false );

		$plugin = new Plugin();
		$plugin->init();

		$this->assertNull( $plugin->admin() );
	}

	// -----------------------------------------------------------------
	// enqueue_notice_css()
	// -----------------------------------------------------------------

	public function test_enqueue_notice_css_skips_anonymous(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		Functions\expect( 'wp_enqueue_style' )->never();

		$plugin = new Plugin();
		$plugin->enqueue_notice_css();
	}

	public function test_enqueue_notice_css_loads_for_logged_in_user(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		Functions\expect( 'wp_enqueue_style' )
			->once()
			->with(
				'wp-sudo-notices',
				\Mockery::type( 'string' ),
				array(),
				WP_SUDO_VERSION
			);

		$plugin = new Plugin();
		$plugin->enqueue_notice_css();
	}

	// -----------------------------------------------------------------
	// enqueue_shortcut()
	// -----------------------------------------------------------------

	public function test_enqueue_shortcut_skips_anonymous(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		Functions\expect( 'wp_enqueue_script' )->never();

		$plugin = new Plugin();
		$plugin->enqueue_shortcut();
	}

	public function test_enqueue_shortcut_skips_active_session(): void {
		$user_id = 5;
		$token   = 'shortcut-token';

		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
		Functions\when( 'get_user_meta' )->alias( function ( $uid, $key, $single ) use ( $token ) {
			if ( Sudo_Session::META_KEY === $key ) {
				return time() + 600;
			}
			if ( Sudo_Session::TOKEN_META_KEY === $key ) {
				return hash( 'sha256', $token );
			}
			return '';
		} );

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		Functions\expect( 'wp_enqueue_script' )->never();

		$plugin = new Plugin();
		$plugin->enqueue_shortcut();

		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
	}

	public function test_enqueue_shortcut_skips_challenge_page(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );

		$_GET['page'] = 'wp-sudo-challenge';

		Functions\expect( 'wp_enqueue_script' )->never();

		$plugin = new Plugin();
		$plugin->enqueue_shortcut();

		unset( $_GET['page'] );
	}

	public function test_enqueue_shortcut_loads_on_admin_page(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/wp-admin/admin.php?page=wp-sudo-challenge' );

		$_GET['page'] = 'some-other-page';

		Functions\expect( 'wp_enqueue_script' )
			->once()
			->with(
				'wp-sudo-shortcut',
				\Mockery::type( 'string' ),
				array(),
				WP_SUDO_VERSION,
				true
			);

		Functions\expect( 'wp_localize_script' )
			->once()
			->with(
				'wp-sudo-shortcut',
				'wpSudoShortcut',
				\Mockery::on( function ( $data ) {
					return isset( $data['challengeUrl'] );
				} )
			);

		$plugin = new Plugin();
		$plugin->enqueue_shortcut();

		unset( $_GET['page'] );
	}

	public function test_enqueue_shortcut_return_url_is_not_double_encoded(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'home_url' )->alias( fn( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_enqueue_script' )->justReturn();

		$_GET['page']            = 'some-other-page';
		$_SERVER['REQUEST_URI']  = '/wp-admin/admin.php?page=plugins&plugin_status=active';
		$_SERVER['HTTP_HOST']    = 'example.com';
		$_SERVER['REQUEST_SCHEME'] = 'https';

		$captured_args = null;
		Functions\expect( 'add_query_arg' )
			->once()
			->with(
				\Mockery::on( function ( $args ) use ( &$captured_args ) {
					$captured_args = $args;
					return true;
				} ),
				\Mockery::type( 'string' )
			)
			->andReturn( 'https://example.com/wp-admin/admin.php?page=wp-sudo-challenge' );

		Functions\expect( 'wp_localize_script' )
			->once()
			->with( 'wp-sudo-shortcut', 'wpSudoShortcut', \Mockery::type( 'array' ) );

		$plugin = new Plugin();
		$plugin->enqueue_shortcut();

		$this->assertArrayHasKey( 'return_url', $captured_args );
		// The return_url must NOT be URL-encoded before add_query_arg (which encodes it itself).
		$this->assertStringNotContainsString( '%3A', $captured_args['return_url'], 'return_url should not be pre-encoded before add_query_arg.' );
		$this->assertStringNotContainsString( '%2F', $captured_args['return_url'], 'return_url should not be pre-encoded before add_query_arg.' );

		unset( $_GET['page'], $_SERVER['REQUEST_URI'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_SCHEME'] );
	}

	// -----------------------------------------------------------------
	// enqueue_gate_ui()
	// -----------------------------------------------------------------

	public function test_enqueue_gate_ui_skips_anonymous(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		Functions\expect( 'wp_enqueue_script' )->never();

		$plugin = new Plugin();
		$plugin->enqueue_gate_ui( 'plugins.php' );
	}

	public function test_enqueue_gate_ui_skips_active_session(): void {
		$user_id = 5;
		$token   = 'gate-ui-token';

		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
		Functions\when( 'get_user_meta' )->alias( function ( $uid, $key, $single ) use ( $token ) {
			if ( Sudo_Session::META_KEY === $key ) {
				return time() + 600;
			}
			if ( Sudo_Session::TOKEN_META_KEY === $key ) {
				return hash( 'sha256', $token );
			}
			return '';
		} );

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		Functions\expect( 'wp_enqueue_script' )->never();

		$plugin = new Plugin();
		$plugin->enqueue_gate_ui( 'plugins.php' );

		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
	}

	public function test_enqueue_gate_ui_skips_non_gated_page(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );

		Functions\expect( 'wp_enqueue_script' )->never();

		$plugin = new Plugin();
		$plugin->enqueue_gate_ui( 'edit.php' );
	}

	/**
	 * @dataProvider gated_page_provider
	 *
	 * @param string $hook_suffix Admin page hook suffix.
	 * @param string $expected_page Expected page identifier passed to JS.
	 */
	public function test_enqueue_gate_ui_loads_on_gated_page( string $hook_suffix, string $expected_page ): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );

		Functions\expect( 'wp_enqueue_script' )
			->once()
			->with(
				'wp-sudo-gate-ui',
				\Mockery::type( 'string' ),
				array(),
				WP_SUDO_VERSION,
				true
			);

		Functions\expect( 'wp_localize_script' )
			->once()
			->with(
				'wp-sudo-gate-ui',
				'wpSudoGateUi',
				\Mockery::on( function ( $data ) use ( $expected_page ) {
					return isset( $data['page'] ) && $expected_page === $data['page'];
				} )
			);

		$plugin = new Plugin();
		$plugin->enqueue_gate_ui( $hook_suffix );
	}

	/**
	 * Data provider for gated page test.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function gated_page_provider(): array {
		return array(
			'themes'         => array( 'themes.php', 'themes' ),
			'theme-install'  => array( 'theme-install.php', 'theme-install' ),
			'plugins'        => array( 'plugins.php', 'plugins' ),
			'plugin-install' => array( 'plugin-install.php', 'plugin-install' ),
		);
	}

	// -----------------------------------------------------------------
	// activate()
	// -----------------------------------------------------------------

	public function test_activate_stamps_version_and_sets_flag(): void {
		Functions\when( 'get_option' )->justReturn( WP_SUDO_VERSION );
		Functions\when( 'get_role' )->justReturn( null );

		Functions\expect( 'update_option' )
			->once()
			->with( 'wp_sudo_activated', true );

		$plugin = new Plugin();
		$plugin->activate();
	}

	public function test_activate_strips_unfiltered_html_from_editor(): void {
		Functions\when( 'get_option' )->justReturn( WP_SUDO_VERSION );
		Functions\when( 'update_option' )->justReturn( true );

		$role = \Mockery::mock( 'WP_Role' );
		$role->shouldReceive( 'remove_cap' )
			->once()
			->with( 'unfiltered_html' );

		Functions\expect( 'get_role' )
			->once()
			->with( 'editor' )
			->andReturn( $role );

		$plugin = new Plugin();
		$plugin->activate();
	}

	public function test_activate_skips_strip_when_no_editor_role(): void {
		Functions\when( 'get_option' )->justReturn( WP_SUDO_VERSION );
		Functions\when( 'update_option' )->justReturn( true );

		Functions\expect( 'get_role' )
			->once()
			->with( 'editor' )
			->andReturn( null );

		$plugin = new Plugin();
		$plugin->activate();

		// No error — null role is handled gracefully.
		$this->assertTrue( true );
	}

	public function test_activate_skips_strip_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( WP_SUDO_VERSION );
		Functions\when( 'get_site_option' )->justReturn( WP_SUDO_VERSION );
		Functions\when( 'update_option' )->justReturn( true );

		Functions\expect( 'get_role' )->never();

		$plugin = new Plugin();
		$plugin->activate();
	}

	// -----------------------------------------------------------------
	// activate_network()
	// -----------------------------------------------------------------

	public function test_activate_network_stamps_version_and_sets_site_flag(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_site_option' )->justReturn( WP_SUDO_VERSION );

		Functions\expect( 'update_site_option' )
			->once()
			->with( 'wp_sudo_activated', true );

		$plugin = new Plugin();
		$plugin->activate_network();
	}

	public function test_activate_network_does_not_strip_unfiltered_html(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_site_option' )->justReturn( WP_SUDO_VERSION );
		Functions\when( 'update_site_option' )->justReturn( true );

		Functions\expect( 'get_role' )->never();

		$plugin = new Plugin();
		$plugin->activate_network();
	}

	// -----------------------------------------------------------------
	// deactivate()
	// -----------------------------------------------------------------

	public function test_deactivate_removes_flag(): void {
		Functions\when( 'get_role' )->justReturn( null );

		Functions\expect( 'delete_option' )
			->once()
			->with( 'wp_sudo_activated' );

		$plugin = new Plugin();
		$plugin->deactivate();
	}

	public function test_deactivate_restores_unfiltered_html_to_editor(): void {
		Functions\when( 'delete_option' )->justReturn( true );

		$role = \Mockery::mock( 'WP_Role' );
		$role->shouldReceive( 'add_cap' )
			->once()
			->with( 'unfiltered_html' );

		Functions\expect( 'get_role' )
			->once()
			->with( 'editor' )
			->andReturn( $role );

		$plugin = new Plugin();
		$plugin->deactivate();
	}

	public function test_deactivate_skips_restore_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'delete_site_option' )->justReturn( true );

		Functions\expect( 'get_role' )->never();

		$plugin = new Plugin();
		$plugin->deactivate();
	}

	// -----------------------------------------------------------------
	// enforce_editor_unfiltered_html()
	// -----------------------------------------------------------------

	public function test_enforce_strips_cap_and_fires_hook_when_tampered(): void {
		$role               = \Mockery::mock( 'WP_Role' );
		$role->capabilities = array( 'unfiltered_html' => true );

		$role->shouldReceive( 'remove_cap' )
			->once()
			->with( 'unfiltered_html' );

		Functions\when( 'get_role' )->justReturn( $role );

		Functions\expect( 'do_action' )
			->once()
			->with( 'wp_sudo_capability_tampered', 'editor', 'unfiltered_html' );

		$plugin = new Plugin();
		$plugin->enforce_editor_unfiltered_html();
	}

	public function test_enforce_skips_when_cap_not_present(): void {
		$role               = \Mockery::mock( 'WP_Role' );
		$role->capabilities = array();

		$role->shouldNotReceive( 'remove_cap' );

		Functions\when( 'get_role' )->justReturn( $role );

		Functions\expect( 'do_action' )
			->with( 'wp_sudo_capability_tampered', \Mockery::any(), \Mockery::any() )
			->never();

		$plugin = new Plugin();
		$plugin->enforce_editor_unfiltered_html();
	}

	public function test_enforce_skips_when_no_editor_role(): void {
		Functions\when( 'get_role' )->justReturn( null );

		Functions\expect( 'do_action' )
			->with( 'wp_sudo_capability_tampered', \Mockery::any(), \Mockery::any() )
			->never();

		$plugin = new Plugin();
		$plugin->enforce_editor_unfiltered_html();

		// No error — null role is handled gracefully.
		$this->assertTrue( true );
	}

	public function test_enforce_skips_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		Functions\expect( 'get_role' )->never();

		Functions\expect( 'do_action' )
			->with( 'wp_sudo_capability_tampered', \Mockery::any(), \Mockery::any() )
			->never();

		$plugin = new Plugin();
		$plugin->enforce_editor_unfiltered_html();

		$this->assertTrue( true );
	}

	// -----------------------------------------------------------------
	// MU loader resilience
	// -----------------------------------------------------------------

	public function test_mu_loader_registers_when_active_plugin_matches_defined_basename(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_site_option' )->justReturn( array() );
		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = array() ) {
				if ( 'active_plugins' === $key ) {
					return array( WP_SUDO_PLUGIN_BASENAME );
				}
				return $default;
			}
		);

		Functions\expect( 'add_action' )->once();

		$this->include_mu_loader_file();
	}

	public function test_mu_loader_basename_builder_falls_back_when_defined_basename_missing(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_site_option' )->justReturn( array() );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'add_action' )->justReturn( true );

		$this->include_mu_loader_file();

		$candidates = \wp_sudo_loader_build_basename_candidates( null, 'renamed-sudo' );

		$this->assertContains( 'renamed-sudo/wp-sudo.php', $candidates );
		$this->assertContains( 'wp-sudo/wp-sudo.php', $candidates );
		$this->assertSame( 'renamed-sudo/wp-sudo.php', $candidates[0] );
	}

	public function test_mu_loader_registers_for_non_canonical_plugin_slug(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_site_option' )->justReturn( array() );
		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = array() ) {
				if ( 'active_plugins' === $key ) {
					return array( 'my-security-stack/wp-sudo.php' );
				}
				return $default;
			}
		);

		Functions\expect( 'add_action' )->once();

		$this->include_mu_loader_file();
	}

	public function test_mu_loader_stays_inert_when_no_active_match_is_found(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_site_option' )->justReturn( array() );
		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = array() ) {
				if ( 'active_plugins' === $key ) {
					return array( 'akismet/akismet.php' );
				}
				return $default;
			}
		);

		Functions\expect( 'add_action' )->never();

		$this->include_mu_loader_file();
	}

	public function test_mu_loader_unresolved_path_signal_is_explicit(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_site_option' )->justReturn( array() );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'add_action' )->justReturn( true );

		$this->include_mu_loader_file();

		$candidates = array(
			'/tmp/fake-wordpress/wp-content/plugins/nonexistent/wp-sudo.php',
		);

		Functions\expect( 'do_action' )
			->once()
			->with( 'wp_sudo_mu_loader_unresolved_plugin_path', $candidates );

		\wp_sudo_loader_signal_unresolved_plugin_path( $candidates );
	}

	// -----------------------------------------------------------------
	// Accessors (null before init)
	// -----------------------------------------------------------------

	public function test_accessors_return_null_before_init(): void {
		$plugin = new Plugin();

		$this->assertNull( $plugin->gate() );
		$this->assertNull( $plugin->challenge() );
		$this->assertNull( $plugin->admin_bar() );
		$this->assertNull( $plugin->admin() );
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * Stub all WordPress functions needed by init().
	 *
	 * @param bool $is_admin Whether is_admin() returns true.
	 * @return void
	 */
	private function stub_init_deps( bool $is_admin = true ): void {
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( WP_SUDO_VERSION );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( $is_admin );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'fake-nonce' );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
	}

	/**
	 * Include the procedural MU loader file for side-effect assertions.
	 *
	 * @return void
	 */
	private function include_mu_loader_file(): void {
		include __DIR__ . '/../../mu-plugin/wp-sudo-loader.php';
	}
}
