<?php
/**
 * WP Sudo — MU-Plugin Shim.
 *
 * Stable shim that delegates to the loader inside the plugin directory.
 * This file is copied to wp-content/mu-plugins/ and should never need
 * updating — the loader it requires ships with the regular plugin and
 * is updated via the standard WordPress update mechanism.
 *
 * @package WP_Sudo
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_SUDO_MU_LOADED', true );

$wp_sudo_loader_placeholder = '__WP_SUDO_LOADER_PATH__';
$wp_sudo_loader_sentinel    = '__WP_SUDO_' . 'LOADER_PATH__';
$wp_sudo_loader_candidates  = array();

if ( '' !== $wp_sudo_loader_placeholder && $wp_sudo_loader_sentinel !== $wp_sudo_loader_placeholder ) {
	$wp_sudo_loader_candidates[] = $wp_sudo_loader_placeholder;
}

if ( defined( 'WP_PLUGIN_DIR' ) ) {
	$wp_sudo_loader_candidates[] = WP_PLUGIN_DIR . '/wp-sudo/mu-plugin/wp-sudo-loader.php';

	// Fallback for manual installs where the plugin directory was renamed.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_glob
	$wp_sudo_discovered_loaders = glob( WP_PLUGIN_DIR . '/*/mu-plugin/wp-sudo-loader.php' );
	if ( is_array( $wp_sudo_discovered_loaders ) ) {
		$wp_sudo_loader_candidates = array_merge( $wp_sudo_loader_candidates, $wp_sudo_discovered_loaders );
	}
} else {
	$wp_sudo_loader_candidates[] = WP_CONTENT_DIR . '/plugins/wp-sudo/mu-plugin/wp-sudo-loader.php';
}

$wp_sudo_loader_candidates = array_unique( array_filter( $wp_sudo_loader_candidates ) );

foreach ( $wp_sudo_loader_candidates as $wp_sudo_loader ) {
	if ( file_exists( $wp_sudo_loader ) ) {
		require_once $wp_sudo_loader; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		break;
	}
}
