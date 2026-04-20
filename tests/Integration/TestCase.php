<?php
/**
 * Base integration test case for WP Sudo.
 *
 * Extends WP_UnitTestCase for real database + WordPress environment.
 * Each test runs in a database transaction that is rolled back in tear_down().
 *
 * Do NOT use Brain\Monkey here. Do NOT call Monkey\setUp().
 * Integration tests use real WordPress functions, not mocks.
 *
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Action_Registry;
use WP_Sudo\Admin;
use WP_Sudo\Event_Store;
use WP_Sudo\Sudo_Session;

/**
 * Base class for WP Sudo integration tests.
 */
class TestCase extends \WP_UnitTestCase {

	/**
	 * Superglobal snapshots for isolation between tests.
	 *
	 * @var array
	 */
	private array $server_snapshot  = array();
	private array $get_snapshot     = array();
	private array $post_snapshot    = array();
	private array $request_snapshot = array();
	private array $cookie_snapshot  = array();

	/**
	 * Set up test environment.
	 *
	 * Snapshots superglobals before each test so tear_down() can restore them.
	 * Always call parent::set_up() first (starts DB transaction).
	 */
	public function set_up(): void {
		parent::set_up();

		// Integration tests run many cases in one PHP process. Reset any runtime
		// caches that are request-scoped in production to avoid cross-test leakage.
		Event_Store::reset_runtime_cache();

		$this->server_snapshot  = $_SERVER;
		$this->get_snapshot     = $_GET;
		$this->post_snapshot    = $_POST;
		$this->request_snapshot = $_REQUEST;
		$this->cookie_snapshot  = $_COOKIE;
	}

	/**
	 * Tear down test environment.
	 *
	 * Restores superglobals, clears static caches, and unsets Gate's pagenow global.
	 * Always call parent::tear_down() last (rolls back DB transaction).
	 */
	public function tear_down(): void {
		// Restore superglobals to pre-test state.
		$_SERVER  = $this->server_snapshot;
		$_GET     = $this->get_snapshot;
		$_POST    = $this->post_snapshot;
		$_REQUEST = $this->request_snapshot;
		$_COOKIE  = $this->cookie_snapshot;

		// Clear static caches that persist across tests.
		Sudo_Session::reset_cache();
		Action_Registry::reset_cache();
		Admin::reset_cache();
		Event_Store::reset_runtime_cache();

		// Gate reads $GLOBALS['pagenow'] for admin request matching.
		unset( $GLOBALS['pagenow'] );

		// Reset the current screen set by simulate_admin_request().
		// Without this, is_admin() leaks 'true' to subsequent tests.
		$GLOBALS['current_screen'] = null;

		parent::tear_down();
	}

	/**
	 * Create an administrator user with a real bcrypt-hashed password in the database.
	 *
	 * Uses the factory so the created user is auto-cleaned up in tear_down().
	 * wp_hash_password() uses cost=5 in test environments (WP_UnitTestCase default).
	 *
	 * @param string $password Plain-text password for verification tests.
	 * @return \WP_User
	 */
	protected function make_admin( string $password = 'test-password' ): \WP_User {
		$user_id = self::factory()->user->create(
			array(
				'role'      => 'administrator',
				'user_pass' => $password,
			)
		);
		return get_user_by( 'id', $user_id );
	}

	/**
	 * Trigger the plugin's activation hook explicitly.
	 *
	 * The plugin is loaded via muplugins_loaded in the bootstrap, which does not
	 * fire the activation hook. Tests that verify activation side effects
	 * (unfiltered_html removal, option creation) must call this method.
	 */
	protected function activate_plugin(): void {
		do_action( 'activate_wp-sudo/wp-sudo.php' );
	}

	/**
	 * Update an option using the same API as the production code.
	 *
	 * On multisite, wp-sudo stores settings and version options with
	 * get_site_option() / update_site_option(). Tests that arrange option
	 * state must use the matching setter so the Upgrader, Admin, and Gate
	 * classes find the values they expect.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 */
	protected function update_wp_sudo_option( string $option, $value ): void {
		if ( is_multisite() ) {
			update_site_option( $option, $value );
		} else {
			update_option( $option, $value );
		}
	}

	/**
	 * Get an option using the same API as the production code.
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	protected function get_wp_sudo_option( string $option, $default = false ) {
		return is_multisite() ? get_site_option( $option, $default ) : get_option( $option, $default );
	}

	/**
	 * Simulate an admin page request for Gate's match_request().
	 *
	 * Sets the globals and superglobals that Gate reads:
	 * - $GLOBALS['pagenow'] — read by Gate::matches_admin()
	 * - $_SERVER['REQUEST_METHOD'] — read by Gate::match_request()
	 * - $_REQUEST['action'] — read by Gate::match_request()
	 * - $_SERVER['HTTP_HOST'] and $_SERVER['REQUEST_URI'] — read by Request_Stash::build_original_url()
	 * - $_GET and $_POST — captured by Request_Stash::save()
	 * - WP_Screen — set via set_current_screen() so is_admin() returns true
	 *
	 * @param string $pagenow  The page (e.g. 'plugins.php', 'themes.php').
	 * @param string $action   The action parameter (e.g. 'activate', 'delete-selected').
	 * @param string $method   HTTP method: 'GET' or 'POST'.
	 * @param array  $get      Additional $_GET parameters.
	 * @param array  $post     Additional $_POST parameters.
	 */
	protected function simulate_admin_request(
		string $pagenow,
		string $action = '',
		string $method = 'GET',
		array $get = array(),
		array $post = array()
	): void {
		$GLOBALS['pagenow'] = $pagenow;

		// Establish admin context so Gate::detect_surface() returns 'admin'.
		// set_current_screen() sets the global WP_Screen, which is_admin() checks.
		set_current_screen( $pagenow );

		$_SERVER['REQUEST_METHOD'] = strtoupper( $method );
		$_SERVER['HTTP_HOST']      = 'example.org';

		// Build a realistic request URI.
		$query = $action ? "action={$action}" : '';
		if ( $get ) {
			$extra = http_build_query( $get );
			$query = $query ? "{$query}&{$extra}" : $extra;
		}
		$_SERVER['REQUEST_URI'] = "/wp-admin/{$pagenow}" . ( $query ? "?{$query}" : '' );

		// Set $_GET, $_POST, $_REQUEST.
		$_GET = $get;
		if ( $action ) {
			$_GET['action'] = $action;
		}
		$_POST    = $post;
		$_REQUEST = array_merge( $_GET, $_POST );
	}
}
