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
use WP_Sudo\CLI_Command;
use WP_Sudo\Upgrader;
use WP_Sudo\Sudo_Session;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers \WP_Sudo\Plugin
 */
class PluginTest extends TestCase {

	/**
	 * Read a private Plugin component property.
	 *
	 * The challenge/admin_bar/admin component references are private; only the
	 * Gate is exposed publicly (for integration tests). Unit tests observe the
	 * others through reflection rather than a test-only getter.
	 *
	 * @param Plugin $plugin   Plugin instance.
	 * @param string $property Property name.
	 * @return mixed
	 */
	private function component( Plugin $plugin, string $property ) {
		$ref = new \ReflectionProperty( Plugin::class, $property );
		// setAccessible() is a required no-op on PHP 8.0 and deprecated on 8.5+; suppress the notice.
		@$ref->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $ref->getValue( $plugin );
	}

	// -----------------------------------------------------------------
	// init()
	// -----------------------------------------------------------------

	public function test_init_creates_all_components(): void {
		$this->stub_init_deps();

		$plugin = new Plugin();
		$plugin->init();

		$this->assertInstanceOf( Gate::class, $plugin->gate() );
		$this->assertInstanceOf( Challenge::class, $this->component( $plugin, 'challenge' ) );
		$this->assertInstanceOf( Admin_Bar::class, $this->component( $plugin, 'admin_bar' ) );
		$this->assertInstanceOf( Admin::class, $this->component( $plugin, 'admin' ) );
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

		$this->assertInstanceOf( Admin::class, $this->component( $plugin, 'admin' ) );
	}

	public function test_init_skips_admin_when_not_admin(): void {
		$this->stub_init_deps( false );

		$plugin = new Plugin();
		$plugin->init();

		$this->assertNull( $this->component( $plugin, 'admin' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_init_registers_wp_cli_command_when_cli_context(): void {
		// Bootstrap Brain\Monkey manually (separate process has no parent setUp).
		\Brain\Monkey\setUp();

		Functions\when( 'load_plugin_textdomain' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( WP_SUDO_VERSION );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		if ( ! class_exists( '\WP_CLI', false ) ) {
			// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
			eval( 'namespace { class WP_CLI { public static array $commands = []; public static function add_command( string $name, $callable ): bool { self::$commands[ $name ] = $callable; return true; } } }' );
		}

		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		$plugin = new Plugin();
		$plugin->init();

		$this->assertArrayHasKey( 'sudo', \WP_CLI::$commands );
		$this->assertSame( CLI_Command::class, \WP_CLI::$commands['sudo'] );

		\Brain\Monkey\tearDown();
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
		// Mock is_ssl() unconditionally: the return-URL path can reach it, and a
		// leaked mock from a sibling test must not be relied on (order-dependent
		// "is_ssl is not defined nor mocked" failure on the CI PHP 8.1 lane).
		Functions\when( 'is_ssl' )->justReturn( true );
		// Same defense for esc_url_raw(): when $_SERVER['REQUEST_URI'] and
		// HTTP_HOST leak in from a sibling test, get_current_admin_url() reaches
		// esc_url_raw() (class-plugin.php:540). Stub it unconditionally so the
		// test does not depend on execution order (surfaced on the CI PHP 8.4 lane).
		Functions\when( 'esc_url_raw' )->returnArg();

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

	/**
	 * Bug fix: settings-tab-lost-on-reauth-replay. return_url must be encoded
	 * EXACTLY ONCE via rawurlencode() before being handed to add_query_arg() —
	 * add_query_arg() does not encode newly-added array values, so leaving
	 * return_url raw let its nested "&" leak out as a new top-level query
	 * separator. This test locks in single-encoding: not raw, and not
	 * double-encoded (no literal "%25").
	 */
	public function test_enqueue_shortcut_return_url_is_not_double_encoded(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'home_url' )->alias( fn( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'esc_url_raw' )->returnArg();
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
		// The return_url must be rawurlencode()'d exactly once before add_query_arg
		// (which does NOT encode newly-added array values itself).
		$this->assertSame(
			rawurlencode( 'https://example.com/wp-admin/admin.php?page=plugins&plugin_status=active' ),
			$captured_args['return_url'],
			'return_url should be encoded exactly once before add_query_arg.'
		);
		// Guard against double-encoding: a single rawurlencode() never produces a literal "%25".
		$this->assertStringNotContainsString( '%25', $captured_args['return_url'], 'return_url must not be double-encoded.' );

		unset( $_GET['page'], $_SERVER['REQUEST_URI'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_SCHEME'] );
	}

	/**
	 * Bug: settings-tab-lost-on-reauth-replay (single-site, CONFIRMED).
	 *
	 * Root cause: enqueue_shortcut() passes return_url — a full URL that
	 * itself already contains a query string (e.g. "...?page=wp-sudo-settings&tab=access")
	 * — as a raw VALUE inside the array given to add_query_arg(). Real WP core's
	 * add_query_arg()/build_query() do NOT urlencode newly-added array values
	 * (build_query() calls _http_build_query() with $urlencode = false), so the
	 * nested "&tab=access" is emitted as a literal "&" in the output URL,
	 * becoming a new *sibling* top-level query parameter instead of staying
	 * part of the return_url value. When the browser then parses the resulting
	 * challengeUrl's query string into $_GET, $_GET['return_url'] is truncated
	 * at the first "&" and "tab=access" is lost entirely.
	 *
	 * This test uses FAITHFUL add_query_arg() semantics (TestCase::stub_faithful_add_query_arg(),
	 * a byte-for-byte port of wordpress-develop trunk's add_query_arg()/build_query()/
	 * _http_build_query()) — not the http_build_query()-based stub used elsewhere in
	 * this suite, which silently urlencodes and therefore cannot detect this defect.
	 */
	public function test_enqueue_shortcut_challenge_url_preserves_tab_query_arg_from_access_tab(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_enqueue_script' )->justReturn();
		$this->stub_faithful_add_query_arg();

		$_GET['page']           = 'wp-sudo-settings';
		$_SERVER['REQUEST_URI'] = '/wp-admin/options-general.php?page=wp-sudo-settings&tab=access';
		$_SERVER['HTTP_HOST']   = 'example.com';

		$captured = null;
		Functions\expect( 'wp_localize_script' )
			->once()
			->with(
				'wp-sudo-shortcut',
				'wpSudoShortcut',
				\Mockery::on(
					function ( $data ) use ( &$captured ) {
						$captured = $data;
						return true;
					}
				)
			);

		$plugin = new Plugin();
		$plugin->enqueue_shortcut();

		$this->assertIsArray( $captured );
		$this->assertArrayHasKey( 'challengeUrl', $captured );

		// Simulate the browser navigating to challengeUrl and PHP parsing its
		// query string into $_GET, exactly as Challenge::enqueue_assets() would see it.
		$parts = parse_url( $captured['challengeUrl'] );
		parse_str( $parts['query'] ?? '', $get );

		$this->assertArrayHasKey( 'return_url', $get );
		$this->assertStringContainsString(
			'tab=access',
			$get['return_url'],
			'The shortcut challengeUrl must carry a return_url that survives the browser\'s query-string round trip with &tab=access intact.'
		);

		unset( $_GET['page'], $_SERVER['REQUEST_URI'], $_SERVER['HTTP_HOST'] );
	}

	public function test_enqueue_shortcut_uses_current_network_admin_request_url(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );
		Functions\when( 'is_network_admin' )->justReturn( true );
		Functions\when( 'network_admin_url' )->alias( fn( $path = '' ) => 'http://multisite-subdomains.local/wp-admin/network/' . $path );
		Functions\when( 'home_url' )->alias( fn( $path = '' ) => 'http://subsite.multisite-subdomains.local' . $path );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_enqueue_script' )->justReturn();

		$_GET['page']             = 'plugins.php';
		$_SERVER['REQUEST_URI']   = '/wp-admin/network/plugins.php';
		$_SERVER['HTTP_HOST']     = 'multisite-subdomains.local';
		$_SERVER['REQUEST_SCHEME'] = 'http';

		$captured_args = null;
		Functions\expect( 'add_query_arg' )
			->once()
			->with(
				\Mockery::on(
					function ( $args ) use ( &$captured_args ) {
						$captured_args = $args;
						return true;
					}
				),
				'http://multisite-subdomains.local/wp-admin/network/admin.php'
			)
			->andReturn( 'http://multisite-subdomains.local/wp-admin/network/admin.php?page=wp-sudo-challenge' );

		Functions\expect( 'wp_localize_script' )
			->once()
			->with( 'wp-sudo-shortcut', 'wpSudoShortcut', \Mockery::type( 'array' ) );

		$plugin = new Plugin();
		$plugin->enqueue_shortcut();

		$this->assertSame(
			rawurlencode( 'http://multisite-subdomains.local/wp-admin/network/plugins.php' ),
			$captured_args['return_url'] ?? '',
			'Network admin shortcut should return to the current network admin URL, not a subsite home_url().'
		);

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
	// enqueue_editor_reauth()
	// -----------------------------------------------------------------

	public function test_enqueue_editor_reauth_skips_anonymous(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		Functions\expect( 'wp_enqueue_script' )->never();

		$plugin = new Plugin();
		$plugin->enqueue_editor_reauth();
	}

	public function test_enqueue_editor_reauth_loads_for_logged_in_user(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		Functions\expect( 'wp_enqueue_script' )
			->once()
			->with(
				'wp-sudo-editor-reauth',
				\Mockery::type( 'string' ),
				array( 'wp-api-fetch', 'wp-data', 'wp-notices', 'wp-i18n' ),
				WP_SUDO_VERSION,
				true
			);

		Functions\expect( 'wp_set_script_translations' )
			->once()
			->with( 'wp-sudo-editor-reauth', 'wp-sudo' );

		$plugin = new Plugin();
		$plugin->enqueue_editor_reauth();
	}

	/**
	 * C2 (revised, design brief Part 3.6): the editor recovery handler MUST load
	 * even when a sudo session is active at page load. The editor is a long-lived
	 * SPA and the short sudo session expires while it stays open, so a later gated
	 * action would return sudo_required with no handler present — reopening the
	 * opaque 403 this feature fixes. This is the contract that distinguishes it
	 * from enqueue_shortcut(), which deliberately skips when a session is active.
	 */
	public function test_enqueue_editor_reauth_loads_even_when_session_active(): void {
		$user_id = 7;
		$token   = 'editor-reauth-token';

		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
		Functions\when( 'get_user_meta' )->alias( function ( $uid, $key, $single ) use ( $token ) {
			if ( Sudo_Session::META_KEY === $key ) {
				return time() + 600; // Active session.
			}
			if ( Sudo_Session::TOKEN_META_KEY === $key ) {
				return hash( 'sha256', $token );
			}
			return '';
		} );
		Functions\when( 'wp_set_script_translations' )->justReturn( true );

		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		try {
			// Guard the scenario: the arrangement above must be a genuinely active
			// session, else this test silently degrades into a duplicate of the
			// logged-in case and stops exercising C2 (enqueue even when active).
			$this->assertTrue(
				Sudo_Session::is_active( $user_id ),
				'arranged state must be a genuinely active sudo session (guards C2)'
			);

			Functions\expect( 'wp_enqueue_script' )->once();

			$plugin = new Plugin();
			$plugin->enqueue_editor_reauth();
		} finally {
			// Ensure the cookie never leaks into later tests, even if an
			// assertion above throws.
			unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
		}
	}

	// -----------------------------------------------------------------
	// activate()
	// -----------------------------------------------------------------

	public function test_activate_stamps_version_and_sets_flag(): void {
		Functions\when( 'get_option' )->justReturn( WP_SUDO_VERSION );
		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );
		Functions\when( 'get_userdata' )->justReturn( false ); // No user during this test.

		Functions\expect( 'update_option' )
			->once()
			->with( 'wp_sudo_activated', true );

		$plugin = new Plugin();
		$plugin->activate();
	}

	/**
	 * On activation, all four governance caps are granted to the activating admin.
	 */
	public function test_activate_grants_governance_caps_to_activating_admin(): void {
		Functions\when( 'get_option' )->justReturn( WP_SUDO_VERSION );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );

		$user = \Mockery::mock( \WP_User::class );
		$user->ID = 42;
		$user->shouldReceive( 'add_cap' )->with( 'manage_wp_sudo' )->once();
		$user->shouldReceive( 'add_cap' )->with( 'view_wp_sudo_activity' )->once();
		$user->shouldReceive( 'add_cap' )->with( 'export_wp_sudo_activity' )->once();
		$user->shouldReceive( 'add_cap' )->with( 'revoke_wp_sudo_sessions' )->once();

		Functions\expect( 'get_userdata' )
			->once()
			->with( 42 )
			->andReturn( $user );

		$plugin = new Plugin();
		$plugin->activate();
	}

	/**
	 * No cap grant when there is no current user (CLI/cron activation, user ID 0).
	 */
	public function test_activate_skips_cap_grant_when_no_current_user(): void {
		Functions\when( 'get_option' )->justReturn( WP_SUDO_VERSION );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_userdata' )->justReturn( false );

		// No add_cap call expected.
		$plugin = new Plugin();
		$plugin->activate();

		$this->assertTrue( true );
	}

	public function test_activate_strips_unfiltered_html_from_editor(): void {
		Functions\when( 'get_option' )->justReturn( WP_SUDO_VERSION );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );
		Functions\when( 'get_userdata' )->justReturn( false ); // No user during this test.

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
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );
		Functions\when( 'get_userdata' )->justReturn( false ); // No user during this test.

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
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );
		Functions\when( 'get_userdata' )->justReturn( false ); // No user during this test.

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
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );

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
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );

		Functions\expect( 'get_role' )->never();

		$plugin = new Plugin();
		$plugin->activate_network();
	}

	// -----------------------------------------------------------------
	// deactivate()
	// -----------------------------------------------------------------

	public function test_deactivate_removes_flag(): void {
		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );

		Functions\expect( 'delete_option' )
			->once()
			->with( 'wp_sudo_activated' );

		$plugin = new Plugin();
		$plugin->deactivate();
	}

	public function test_deactivate_restores_unfiltered_html_to_editor(): void {
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );

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
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );

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
	// Cron prune scheduling
	// -----------------------------------------------------------------

	public function test_activate_schedules_prune_cron_event(): void {
		Functions\when( 'get_option' )->justReturn( WP_SUDO_VERSION );
		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_userdata' )->justReturn( false ); // No user during this test.

		// The cron event should not already be scheduled.
		Functions\when( 'wp_next_scheduled' )->justReturn( false );

		// Should schedule the prune event as a daily event.
		Functions\expect( 'wp_schedule_event' )
			->once()
			->with( \Mockery::type( 'int' ), 'daily', 'wp_sudo_prune_events' );

		$plugin = new Plugin();
		$plugin->activate();
	}

	public function test_activate_skips_scheduling_when_already_scheduled(): void {
		Functions\when( 'get_option' )->justReturn( WP_SUDO_VERSION );
		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_userdata' )->justReturn( false ); // No user during this test.

		// The cron event is already scheduled.
		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 3600 );

		// Should NOT schedule a duplicate event.
		Functions\expect( 'wp_schedule_event' )->never();

		$plugin = new Plugin();
		$plugin->activate();
	}

	public function test_deactivate_clears_prune_cron_event(): void {
		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'delete_option' )->justReturn( true );

		// Should clear the prune cron event.
		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()
			->with( 'wp_sudo_prune_events' );

		$plugin = new Plugin();
		$plugin->deactivate();
	}

	public function test_activate_network_schedules_prune_cron_event(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_site_option' )->justReturn( WP_SUDO_VERSION );
		Functions\when( 'update_site_option' )->justReturn( true );

		// The cron event should not already be scheduled.
		Functions\when( 'wp_next_scheduled' )->justReturn( false );

		// Should schedule the prune event as a daily event.
		Functions\expect( 'wp_schedule_event' )
			->once()
			->with( \Mockery::type( 'int' ), 'daily', 'wp_sudo_prune_events' );

		$plugin = new Plugin();
		$plugin->activate_network();
	}

	public function test_prune_events_callback_calls_event_store_prune(): void {
		// We can't easily mock Event_Store::prune() since it's a static method,
		// but we can verify the method exists and is callable.
		$this->assertTrue(
			method_exists( Plugin::class, 'prune_events' ),
			'Plugin should have a prune_events method'
		);

		// The method should be static and public.
		$reflection = new \ReflectionMethod( Plugin::class, 'prune_events' );
		$this->assertTrue( $reflection->isStatic(), 'prune_events should be static' );
		$this->assertTrue( $reflection->isPublic(), 'prune_events should be public' );
	}

	public function test_init_registers_prune_cron_callback(): void {
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( WP_SUDO_VERSION );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'fake-nonce' );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );

		// Capture all add_action calls to verify the cron callback is registered.
		$cron_callback_registered = false;
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority = 10, $accepted_args = 1 ) use ( &$cron_callback_registered ) {
				if ( 'wp_sudo_prune_events' === $hook
					&& is_array( $callback )
					&& Plugin::class === $callback[0]
					&& 'prune_events' === $callback[1]
				) {
					$cron_callback_registered = true;
				}
				return true;
			}
		);

		$plugin = new Plugin();
		$plugin->init();

		$this->assertTrue( $cron_callback_registered, 'init() should register wp_sudo_prune_events callback' );
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
		$this->assertNull( $this->component( $plugin, 'challenge' ) );
		$this->assertNull( $this->component( $plugin, 'admin_bar' ) );
		$this->assertNull( $this->component( $plugin, 'admin' ) );
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

	// -----------------------------------------------------------------
	// Sudo Active count cache invalidation registration
	// -----------------------------------------------------------------

	/**
	 * The Users-list "Sudo Active (N)" badge cache must be invalidated on
	 * session grant/teardown from EVERY execution context — sessions are
	 * granted on wp_login and revoked via WP-CLI, both outside is_admin()
	 * — so the flush hooks must register unconditionally in init(), not
	 * inside the is_admin()-only Admin instance.
	 *
	 * @return void
	 */
	public function test_init_registers_sudo_active_count_flush_hooks_outside_admin(): void {
		$this->stub_init_deps( false ); // Front-end context: no Admin instance.

		$captured = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, ...$unused ) use ( &$captured ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Accept add_action's priority/accepted_args explicitly; only hook + callback are asserted.
				$captured[] = array( $hook, $callback );
			}
		);

		$plugin = new Plugin();
		$plugin->init();

		$this->assertContains(
			array( 'wp_sudo_activated', array( Admin::class, 'flush_sudo_active_count_cache' ) ),
			$captured,
			'wp_sudo_activated must flush the Sudo Active count cache even outside is_admin()'
		);
		$this->assertContains(
			array( 'wp_sudo_deactivated', array( Admin::class, 'flush_sudo_active_count_cache' ) ),
			$captured,
			'wp_sudo_deactivated must flush the Sudo Active count cache even outside is_admin()'
		);
	}
}
