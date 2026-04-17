<?php
/**
 * Plugin Name:       Sudo
 * Plugin URI:        https://github.com/dknauss/wp-sudo
 * Description:       Action-gated reauthentication for WordPress. Dangerous operations require password confirmation before they proceed — regardless of user role.
 * Version:           2.14.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Dan Knauss
 * Author URI:        https://profiles.wordpress.org/danknauss/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-sudo
 * Domain Path:       /languages
 *
 * @package WP_Sudo
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-bootstrap.php';

// Plugin version.
define( 'WP_SUDO_VERSION', '2.14.0' );

// Plugin directory path.
define( 'WP_SUDO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Register the real path so symlinked installs resolve plugin_basename()/plugins_url()
// through the public plugin slug instead of the filesystem target path.
\WP_Sudo\Bootstrap::register_plugin_realpath( __FILE__ );

// Plugin basename.
define( 'WP_SUDO_PLUGIN_BASENAME', \WP_Sudo\Bootstrap::plugin_basename( __FILE__ ) );

// Plugin directory URL.
define( 'WP_SUDO_PLUGIN_URL', \WP_Sudo\Bootstrap::plugin_dir_url( __FILE__ ) );

/**
 * Autoload plugin classes.
 */
spl_autoload_register(
	function ( string $class_name ) {
		$prefix   = 'WP_Sudo\\';
		$base_dir = WP_SUDO_PLUGIN_DIR . 'includes/';

		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class_name, $len ) !== 0 ) {
				return;
		}

		$relative_class = substr( $class_name, $len );
		$file           = $base_dir . 'class-' . strtolower( str_replace( array( '\\', '_' ), array( '/', '-' ), $relative_class ) ) . '.php';

		if ( file_exists( $file ) ) {
			require $file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		}
	}
);

/**
 * Get the main plugin instance.
 *
 * @return WP_Sudo\Plugin Main plugin instance.
 */
function wp_sudo(): WP_Sudo\Plugin {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new WP_Sudo\Plugin();
	}

	return $instance;
}

/**
 * Public helper: check whether a user currently has an active sudo session.
 *
 * Only the current request's authenticated user can be checked — sudo
 * sessions are bound to a per-browser cookie. Passing a user ID other
 * than the current user always returns false.
 *
 * @since 2.12.0
 *
 * @param int|null $user_id Optional user ID. Defaults to current user.
 *                          Must match the current request's authenticated user.
 * @return bool
 */
function wp_sudo_check( ?int $user_id = null ): bool {
	return \WP_Sudo\Public_API::check( $user_id );
}

/**
 * Public helper: require an active sudo session.
 *
 * See `WP_Sudo\Public_API::require()` for accepted args.
 *
 * @since 2.12.0
 *
 * @param array<string, mixed> $args Optional API args.
 * @return bool
 */
function wp_sudo_require( array $args = array() ): bool {
	return \WP_Sudo\Public_API::require( $args );
}

// Boot the plugin.
add_action(
	'plugins_loaded',
	static function () {
		wp_sudo()->init();
	},
	10,
	0
);

// Register activation hook.
register_activation_hook(
	__FILE__,
	static function ( bool $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			wp_sudo()->activate_network();
		} else {
			wp_sudo()->activate();
		}
	}
);

// Register deactivation hook.
register_deactivation_hook(
	__FILE__,
	static function () {
		wp_sudo()->deactivate();
	}
);
