<?php
/**
 * PHPStan bootstrap file.
 *
 * Defines constants that exist at runtime but are not available during
 * static analysis. These are defined in wp-sudo.php (plugin constants)
 * and wp-settings.php (WordPress cookie constants).
 *
 * @package WP_Sudo
 */

// Plugin constants (defined in wp-sudo.php at runtime).
define( 'WP_SUDO_VERSION', '2.13.0' );
define( 'WP_SUDO_PLUGIN_DIR', __DIR__ . '/' );
define( 'WP_SUDO_PLUGIN_URL', 'https://example.com/wp-content/plugins/wp-sudo/' );
define( 'WP_SUDO_PLUGIN_BASENAME', 'wp-sudo/wp-sudo.php' );

// WordPress cookie constants (defined in wp-settings.php).
if ( ! defined( 'COOKIEPATH' ) ) {
	define( 'COOKIEPATH', '/' );
}
if ( ! defined( 'ADMIN_COOKIE_PATH' ) ) {
	define( 'ADMIN_COOKIE_PATH', '/wp-admin' );
}
if ( ! defined( 'COOKIE_DOMAIN' ) ) {
	define( 'COOKIE_DOMAIN', '' );
}

// WordPress content directory (used by Admin::get_mu_plugin_dir).
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', __DIR__ . '/wp-content' );
}
if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
	define( 'WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins' );
}

// WordPress MU-plugin constant.
if ( ! defined( 'WP_SUDO_MU_LOADED' ) ) {
	define( 'WP_SUDO_MU_LOADED', false );
}

// WP-CLI class (runtime-only in CLI context).
if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static function add_command( string $name, $callable ): bool {
			return true;
		}

		public static function success( string $message ): void {}

		public static function warning( string $message ): void {}

		public static function log( string $message ): void {}

		public static function error( string $message ): void {}
	}
}
