<?php
/**
 * WP Sudo — MU-Plugin Loader.
 *
 * This file lives inside the plugin directory (wp-sudo/mu-plugin/) and
 * is loaded by the stable shim at wp-content/mu-plugins/wp-sudo-gate.php.
 * It ships with regular plugin updates so constructor signatures, class
 * names, and autoloader paths can change freely without breaking the
 * shim in mu-plugins/.
 *
 * @package WP_Sudo
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_sudo_loader_build_basename_candidates' ) ) {
	/**
	 * Build basename candidates for active-plugin checks.
	 *
	 * @param string|null $defined_basename Basename from constant, if available.
	 * @param string      $loader_slug      Plugin directory slug derived from loader path.
	 * @return string[]
	 */
	function wp_sudo_loader_build_basename_candidates( ?string $defined_basename, string $loader_slug ): array {
		$candidates = array();

		if ( is_string( $defined_basename ) && '' !== $defined_basename ) {
			$candidates[] = $defined_basename;
		}

		if ( '' !== $loader_slug ) {
			$candidates[] = $loader_slug . '/wp-sudo.php';
		}

		$candidates[] = 'wp-sudo/wp-sudo.php';

		return array_values( array_unique( $candidates ) );
	}
}

if ( ! function_exists( 'wp_sudo_loader_basename_candidates' ) ) {
	/**
	 * Resolve basename candidates for WP Sudo plugin activation checks.
	 *
	 * @return string[]
	 */
	function wp_sudo_loader_basename_candidates(): array {
		$defined_basename = defined( 'WP_SUDO_PLUGIN_BASENAME' ) ? (string) WP_SUDO_PLUGIN_BASENAME : null;
		$loader_slug      = basename( dirname( __DIR__ ) );

		return wp_sudo_loader_build_basename_candidates( $defined_basename, $loader_slug );
	}
}

if ( ! function_exists( 'wp_sudo_loader_matches_active_basename' ) ) {
	/**
	 * Check whether an active plugin basename represents WP Sudo.
	 *
	 * @param string   $active_basename Active plugin basename.
	 * @param string[] $candidates      Accepted WP Sudo basenames.
	 * @return bool
	 */
	function wp_sudo_loader_matches_active_basename( string $active_basename, array $candidates ): bool {
		if ( in_array( $active_basename, $candidates, true ) ) {
			return true;
		}

		// Fallback for non-canonical slugs: {anything}/wp-sudo.php.
		return str_ends_with( $active_basename, '/wp-sudo.php' );
	}
}

if ( ! function_exists( 'wp_sudo_loader_is_active' ) ) {
	/**
	 * Determine whether WP Sudo is active (site or network scope).
	 *
	 * @param string[] $basename_candidates Basename candidates.
	 * @return bool
	 */
	function wp_sudo_loader_is_active( array $basename_candidates ): bool {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );

			foreach ( array_keys( $network_plugins ) as $active_basename ) {
				if ( is_string( $active_basename ) && wp_sudo_loader_matches_active_basename( $active_basename, $basename_candidates ) ) {
					return true;
				}
			}
		}

		$site_plugins = (array) get_option( 'active_plugins', array() );

		foreach ( $site_plugins as $active_basename ) {
			if ( is_string( $active_basename ) && wp_sudo_loader_matches_active_basename( $active_basename, $basename_candidates ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'wp_sudo_loader_plugin_file_candidates' ) ) {
	/**
	 * Resolve possible main-plugin file paths.
	 *
	 * @return string[]
	 */
	function wp_sudo_loader_plugin_file_candidates(): array {
		$candidates = array();

		if ( defined( 'WP_SUDO_PLUGIN_DIR' ) ) {
			$candidates[] = rtrim( (string) WP_SUDO_PLUGIN_DIR, '/\\' ) . '/wp-sudo.php';
		}

		// Loader-relative path (works when plugin directory slug is renamed).
		$candidates[] = dirname( __DIR__ ) . '/wp-sudo.php';

		// Canonical fallback.
		$candidates[] = WP_CONTENT_DIR . '/plugins/wp-sudo/wp-sudo.php';

		return array_values( array_unique( $candidates ) );
	}
}

if ( ! function_exists( 'wp_sudo_loader_resolve_plugin_file' ) ) {
	/**
	 * Pick the first existing main-plugin file path.
	 *
	 * @param string[] $file_candidates Candidate plugin file paths.
	 * @return string Absolute plugin file path, or empty string when unresolved.
	 */
	function wp_sudo_loader_resolve_plugin_file( array $file_candidates ): string {
		foreach ( $file_candidates as $candidate ) {
			if ( is_string( $candidate ) && '' !== $candidate && file_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}
}

if ( ! function_exists( 'wp_sudo_loader_signal_unresolved_plugin_path' ) ) {
	/**
	 * Emit a diagnosable signal when the loader cannot resolve plugin path.
	 *
	 * @param string[] $file_candidates Candidate plugin file paths.
	 * @return void
	 */
	function wp_sudo_loader_signal_unresolved_plugin_path( array $file_candidates ): void {
		/**
		 * Fires when the MU loader cannot resolve the main plugin file path.
		 *
		 * @since 2.10.3
		 *
		 * @param string[] $file_candidates Candidate plugin file paths checked by loader.
		 */
		do_action( 'wp_sudo_mu_loader_unresolved_plugin_path', $file_candidates );
	}
}

$wp_sudo_basename_candidates = wp_sudo_loader_basename_candidates();

// Check if the main plugin is active before loading it. If the plugin has
// been deactivated via the admin UI but the MU-plugin shim was not removed,
// the loader should be inert — no file loading, no gate hooks.
if ( ! wp_sudo_loader_is_active( $wp_sudo_basename_candidates ) ) {
	return;
}

// Load the main plugin if not already bootstrapped. Check the constant
// first to avoid file_exists() calls on every request when already loaded.
if ( ! defined( 'WP_SUDO_PLUGIN_DIR' ) ) {
	$wp_sudo_plugin_file = wp_sudo_loader_resolve_plugin_file( wp_sudo_loader_plugin_file_candidates() );

	if ( '' === $wp_sudo_plugin_file ) {
		wp_sudo_loader_signal_unresolved_plugin_path( wp_sudo_loader_plugin_file_candidates() );
		return;
	}

	require_once $wp_sudo_plugin_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
}

// Register the early non-interactive gate hooks at muplugins_loaded.
add_action(
	'muplugins_loaded',
	static function () {
		$gate = new WP_Sudo\Gate(
			new WP_Sudo\Sudo_Session(),
			new WP_Sudo\Request_Stash()
		);
		$gate->register_early();
	},
	10,
	0
);
