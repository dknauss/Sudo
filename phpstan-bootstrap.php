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
define( 'WP_SUDO_VERSION', '4.6.0' );
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

// WordPress 7.0 Connectors API (runtime-only on WP 7.0+).
if ( ! function_exists( 'wp_get_connectors' ) ) {
	function wp_get_connectors(): array {
		return array();
	}
}

// Two Factor plugin classes (optional integration — present only when the
// WordPress/two-factor plugin is active). Stubbed for static analysis so the
// provider-class `instanceof` checks and the email provider's token methods in
// Challenge resolve; runtime `class_exists()` guards remain the real gate.
if ( ! class_exists( 'Two_Factor_Provider' ) ) {
	abstract class Two_Factor_Provider {
		/**
		 * @param \WP_User $user User.
		 * @return void
		 */
		public function authentication_page( $user ) {}

		/**
		 * @param \WP_User $user User.
		 * @return bool
		 */
		public function validate_authentication( $user ) {
			return false;
		}

		/**
		 * @param \WP_User $user User.
		 * @return bool
		 */
		public function pre_process_authentication( $user ) {
			return false;
		}
	}
}
if ( ! class_exists( 'Two_Factor_Core' ) ) {
	class Two_Factor_Core {
		/**
		 * @param int $user_id User ID.
		 * @return bool
		 */
		public static function is_user_using_two_factor( $user_id = null ) {
			return false;
		}

		/**
		 * @param \WP_User $user User.
		 * @return Two_Factor_Provider|null
		 */
		public static function get_primary_provider_for_user( $user ) {
			return null;
		}
	}
}
if ( ! class_exists( 'Two_Factor_Totp' ) ) {
	class Two_Factor_Totp extends Two_Factor_Provider {}
}
if ( ! class_exists( 'Two_Factor_Email' ) ) {
	class Two_Factor_Email extends Two_Factor_Provider {
		/**
		 * @param int $user_id User ID.
		 * @return bool
		 */
		public function user_has_token( $user_id ) {
			return false;
		}

		/**
		 * @param int $user_id User ID.
		 * @return bool
		 */
		public function user_token_has_expired( $user_id ) {
			return false;
		}
	}
}
if ( ! class_exists( 'Two_Factor_Backup_Codes' ) ) {
	class Two_Factor_Backup_Codes extends Two_Factor_Provider {}
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
