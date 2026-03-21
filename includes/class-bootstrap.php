<?php
/**
 * Bootstrap helpers for the main plugin file.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bootstrap
 *
 * Keeps bootstrap-only WordPress calls unit-testable.
 */
class Bootstrap {

	/**
	 * Register the plugin's real path so symlinked installs resolve through the public plugin path.
	 *
	 * @param string $plugin_file Main plugin file path.
	 * @return void
	 */
	public static function register_plugin_realpath( string $plugin_file ): void {
		wp_register_plugin_realpath( self::public_plugin_file( $plugin_file ) );
	}

	/**
	 * Resolve the public plugin basename.
	 *
	 * @param string $plugin_file Main plugin file path.
	 * @return string
	 */
	public static function plugin_basename( string $plugin_file ): string {
		$public_plugin_basename = self::detect_public_plugin_basename( $plugin_file );

		if ( '' !== $public_plugin_basename ) {
			return $public_plugin_basename;
		}

		return plugin_basename( $plugin_file );
	}

	/**
	 * Resolve the plugin directory URL from the public plugin basename.
	 *
	 * @param string $plugin_file Main plugin file path.
	 * @return string
	 */
	public static function plugin_dir_url( string $plugin_file ): string {
		$plugin_basename = self::plugin_basename( $plugin_file );
		$url             = untrailingslashit( plugins_url() );
		$plugin_dirname  = dirname( $plugin_basename );

		if ( '.' !== $plugin_dirname ) {
			$url .= '/' . ltrim( $plugin_dirname, '/' );
		}

		/**
		 * Mirror WordPress' `plugins_url` filter while using the recovered public basename.
		 */
		$url = apply_filters( 'plugins_url', $url, '', $plugin_basename );

		return trailingslashit( $url );
	}

	/**
	 * Resolve the public plugin file path inside WP_PLUGIN_DIR.
	 *
	 * @param string $plugin_file Main plugin file path.
	 * @return string
	 */
	private static function public_plugin_file( string $plugin_file ): string {
		return trailingslashit( WP_PLUGIN_DIR ) . self::plugin_basename( $plugin_file );
	}

	/**
	 * Detect the active public plugin basename for the current plugin file.
	 *
	 * Symlinked Local/Studio installs load the plugin from the real filesystem
	 * target, so `__FILE__` alone cannot reveal the public `wp-content/plugins/...`
	 * mount point. Instead, recover the relative plugin path that WordPress actually
	 * activated and use that as the canonical public path.
	 *
	 * @param string $plugin_file Main plugin file path.
	 * @return string
	 */
	private static function detect_public_plugin_basename( string $plugin_file ): string {
		$plugin_filename = basename( $plugin_file );
		$plugin_dirname  = basename( dirname( $plugin_file ) );
		$candidates      = self::active_plugin_candidates();

		foreach ( $candidates as $candidate ) {
			if ( ! is_string( $candidate ) ) {
				continue;
			}

			if ( $candidate === $plugin_dirname . '/' . $plugin_filename ) {
				return $candidate;
			}
		}

		foreach ( $candidates as $candidate ) {
			if ( ! is_string( $candidate ) ) {
				continue;
			}

			if ( basename( $candidate ) === $plugin_filename ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Collect active plugin candidates from single-site and multisite activation state.
	 *
	 * @return array<int, string>
	 */
	private static function active_plugin_candidates(): array {
		$candidates = array_values( (array) get_option( 'active_plugins', array() ) );

		if ( is_multisite() ) {
			$candidates = array_merge(
				$candidates,
				array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) )
			);
		}

		return array_values( array_unique( array_filter( $candidates, 'is_string' ) ) );
	}
}
