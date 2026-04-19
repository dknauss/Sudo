<?php
/**
 * Runs when the plugin is uninstalled via the WordPress admin.
 *
 * On a standard single-site install, all plugin data is removed:
 * plugin options and user-meta session data. The v1 Site Manager role
 * is also removed in case the 2.0.0 migration never ran.
 *
 * On multisite, per-site data (role, options) is cleaned for every
 * site in the network, and network-wide data (user meta, MU-plugin
 * shim, sitemeta options) is always removed. By the time WordPress
 * calls uninstall.php the plugin has been deactivated and its files
 * are about to be deleted, so all data is orphaned.
 *
 * @package WP_Sudo
 */

// Abort if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Clean up all per-site data: role and options.
 *
 * @return void
 */
function wp_sudo_cleanup_site(): void {
	// Remove the v1 Site Manager role (safe no-op if it doesn't exist).
	remove_role( 'site_manager' );

	// Restore unfiltered_html to editors (removed by WP Sudo on activation).
	$editor = get_role( 'editor' );
	if ( $editor ) {
		$editor->add_cap( 'unfiltered_html' );
	}

	delete_option( 'wp_sudo_settings' );
	delete_option( 'wp_sudo_version' );
	delete_option( 'wp_sudo_activated' );
	delete_option( 'wp_sudo_role_version' );
	delete_option( 'wp_sudo_db_version' );
}

/**
 * Remove the MU-plugin shim from wp-content/mu-plugins/.
 *
 * The shim is a stable loader that delegates to the plugin directory.
 * On uninstall, it must be removed so it does not remain as an orphan.
 *
 * @return void
 */
function wp_sudo_cleanup_mu_shim(): void {
	$shim_path = WP_CONTENT_DIR . '/mu-plugins/wp-sudo-gate.php';

	if ( file_exists( $shim_path ) ) {
		wp_delete_file( $shim_path );
	}
}

/**
 * Remove all sudo-related user meta from the network.
 *
 * Uses delete_metadata() with object_id=0 and $delete_all=true to
 * delete matching meta across every user in a single query — the same
 * pattern WordPress core uses for bulk meta cleanup on uninstall.
 * This pattern is supported since WordPress 3.0.
 *
 * @return void
 */
function wp_sudo_cleanup_user_meta(): void {
	delete_metadata( 'user', 0, '_wp_sudo_expires', '', true );
	delete_metadata( 'user', 0, '_wp_sudo_token', '', true );
	delete_metadata( 'user', 0, '_wp_sudo_failed_attempts', '', true );
	delete_metadata( 'user', 0, '_wp_sudo_failure_event', '', true );
	delete_metadata( 'user', 0, '_wp_sudo_throttle_until', '', true );
	delete_metadata( 'user', 0, '_wp_sudo_lockout_until', '', true );
	delete_metadata( 'user', 0, '_wp_sudo_stash_keys', '', true );
}

if ( is_multisite() ) {
	// Get every site in the network.
	$site_ids = get_sites(
		array(
			'fields'     => 'ids',
			'number'     => 0,
			'network_id' => get_current_network_id(),
		)
	);

	// Clean per-site data (role, options) on every site.
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
		wp_sudo_cleanup_site();
		restore_current_blog();
	}

	// Clean network-wide data.
	wp_sudo_cleanup_user_meta();
	wp_sudo_cleanup_mu_shim();

	// Clean network-wide options (stored in wp_sitemeta).
	delete_site_option( 'wp_sudo_settings' );
	delete_site_option( 'wp_sudo_version' );
	delete_site_option( 'wp_sudo_db_version' );
	delete_site_option( 'wp_sudo_activated' );
	delete_site_option( 'wp_sudo_role_version' );
} else {
	// Single-site: clean up everything.
	wp_sudo_cleanup_site();
	wp_sudo_cleanup_user_meta();
	wp_sudo_cleanup_mu_shim();
}
