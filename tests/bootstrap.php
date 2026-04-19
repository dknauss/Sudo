<?php
/**
 * PHPUnit bootstrap file for WP Sudo tests.
 *
 * Defines WordPress constants and class stubs so plugin classes
 * can be loaded without a full WordPress environment.
 *
 * @package WP_Sudo\Tests
 */

// ── WordPress core constant (guards in every class file check this) ──
define( 'ABSPATH', '/tmp/fake-wordpress/' );

// ── WordPress directory constants ────────────────────────────────────
define( 'WP_CONTENT_DIR', '/tmp/fake-wordpress/wp-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
define( 'WP_PLUGIN_URL', 'https://example.com/wp-content/plugins' );
define( 'WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins' );
define( 'WPMU_PLUGIN_URL', 'https://example.com/wp-content/mu-plugins' );

// ── Plugin constants (normally defined in wp-sudo.php) ───────────────
define( 'WP_SUDO_VERSION', '3.0.0' );
define( 'WP_SUDO_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'WP_SUDO_PLUGIN_URL', 'https://example.com/wp-content/plugins/wp-sudo/' );
define( 'WP_SUDO_PLUGIN_BASENAME', 'wp-sudo/wp-sudo.php' );

require_once dirname( __DIR__ ) . '/includes/class-bootstrap.php';

// ── WordPress time constants ─────────────────────────────────────────
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS );

// ── WordPress cookie constants ───────────────────────────────────────
define( 'COOKIEPATH', '/' );
define( 'ADMIN_COOKIE_PATH', '/wp-admin' );
define( 'COOKIE_DOMAIN', '' );

// ── Minimal WordPress class stubs ────────────────────────────────────
// Must be defined before the autoloader loads plugin classes that use
// these as type hints in method signatures.

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public int    $ID         = 0;
		public array  $roles      = [];
		public array  $caps       = [];
		public string $user_pass  = '';
		public array  $allcaps    = [];
		public string $user_login = '';

		public function __construct( int $id = 0, array $roles = [] ) {
			$this->ID    = $id;
			$this->roles = $roles;
		}
	}
}

if ( ! class_exists( 'WP_Admin_Bar' ) ) {
	class WP_Admin_Bar {
		private array $nodes = [];

		public function add_node( array $args ): void {
			$id = $args['id'] ?? '';
			if ( $id ) {
				$this->nodes[ $id ] = $args;
			}
		}

		public function get_nodes(): array {
			return $this->nodes;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private array  $data;

		public function __construct( string $code = '', string $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = is_array( $data ) ? $data : array();
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private string $method;
		private string $route;
		private array  $attributes;
		private array  $query_params = [];
		private array  $body_params  = [];
		private array  $headers      = [];
		private string $body         = '';

		/**
		 * Stub mirrors real WP_REST_Request: third arg is $attributes (route
		 * metadata), NOT request parameters. Use set_query_params() or
		 * set_body_params() to populate params that get_params() returns.
		 */
		public function __construct( string $method = 'GET', string $route = '', array $attributes = array() ) {
			$this->method     = $method;
			$this->route      = $route;
			$this->attributes = $attributes;
		}

		public function get_method(): string {
			return $this->method;
		}

		public function get_route(): string {
			return $this->route;
		}

		public function get_params(): array {
			return array_merge( $this->query_params, $this->body_params );
		}

		public function set_query_params( array $params ): void {
			$this->query_params = $params;
		}

		public function set_body_params( array $params ): void {
			$this->body_params = $params;
		}

		public function get_attributes(): array {
			return $this->attributes;
		}

		public function get_header( string $key ): ?string {
			$key = strtolower( $key );
			return $this->headers[ $key ] ?? null;
		}

		public function set_header( string $key, string $value ): void {
			$this->headers[ strtolower( $key ) ] = $value;
		}

		public function get_body(): string {
			return $this->body ?? '';
		}

		public function set_body( string $body ): void {
			$this->body = $body;
		}
	}
}

if ( ! class_exists( 'WP_Screen' ) ) {
	class WP_Screen {
		private array  $help_tabs = [];
		private string $help_sidebar = '';

		public function add_help_tab( array $args ): void {
			$id = $args['id'] ?? '';
			if ( $id ) {
				$this->help_tabs[ $id ] = $args;
			}
		}

		public function set_help_sidebar( string $content ): void {
			$this->help_sidebar = $content;
		}

		public function get_help_tabs(): array {
			return $this->help_tabs;
		}

		public function get_help_sidebar(): string {
			return $this->help_sidebar;
		}
	}
}

// ── Two Factor plugin stubs ──────────────────────────────────────────
// Minimal stubs so tests can exercise the class_exists('Two_Factor_Core')
// branches in Challenge::handle_ajax_2fa() and Challenge::render_page().
// The static $mock_provider property controls provider availability per test.

if ( ! class_exists( 'Two_Factor_Provider' ) ) {
	class Two_Factor_Provider {
		public function authentication_page( WP_User $user ): void {}
		public function pre_process_authentication( WP_User $user ) {
			return false;
		}
		public function validate_authentication( WP_User $user ): bool {
			return false;
		}
	}
}

if ( ! class_exists( 'Two_Factor_Core' ) ) {
	class Two_Factor_Core {
		/** @var Two_Factor_Provider|null */
		public static ?Two_Factor_Provider $mock_provider = null;

		public static function is_user_using_two_factor( int $user_id ): bool {
			return self::$mock_provider !== null;
		}

		public static function get_primary_provider_for_user( WP_User $user ): ?Two_Factor_Provider {
			return self::$mock_provider;
		}
	}
}

// ── Composer autoloader ──────────────────────────────────────────────
$vendor_autoload_override = getenv( 'WP_SUDO_VENDOR_AUTOLOAD' );
$vendor_autoload          = dirname( __DIR__ ) . '/vendor/autoload.php';
$vendor_test_autoload     = dirname( __DIR__ ) . '/vendor_test/autoload.php';

if ( $vendor_autoload_override && file_exists( $vendor_autoload_override ) ) {
	require_once $vendor_autoload_override;
} elseif ( file_exists( $vendor_autoload ) ) {
	require_once $vendor_autoload;
} elseif ( file_exists( $vendor_test_autoload ) ) {
	require_once $vendor_test_autoload;
} else {
	fwrite( STDERR, "Unable to locate Composer autoloader in vendor/, vendor_test/, or WP_SUDO_VENDOR_AUTOLOAD.\n" );
	exit( 1 );
}
