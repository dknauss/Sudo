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

if ( ! class_exists( '\WP_Sudo\Event_Store' ) ) {
	require_once __DIR__ . '/includes/class-event-store.php';
}

if ( ! class_exists( '\WP_Sudo\Uninstall_Guard' ) ) {
	require_once __DIR__ . '/includes/class-uninstall-guard.php';
}

if ( ! function_exists( 'wp_sudo_governance_caps' ) ) {
	require_once __DIR__ . '/includes/functions-governance.php';
}

if ( ! \WP_Sudo\Uninstall_Guard::is_authorized() ) {
	exit;
}

/**
 * Remove the governance capabilities granted by activation/backfill/Access tab.
 *
 * Caps are granted to individual users (WP_User::add_cap) and may also have been
 * added to roles by a site admin. Both stores are swept so no orphaned custom cap
 * survives uninstall on the current site.
 *
 * @return void
 */
function wp_sudo_cleanup_governance_caps(): void {
	$caps = wp_sudo_governance_caps();

	// Direct per-user grants.
	$holders = get_users(
		array(
			'capability__in' => $caps,
			'number'         => 0,
			'fields'         => array( 'ID' ),
		)
	);

	foreach ( $holders as $holder ) {
		$user = get_userdata( (int) $holder->ID );
		if ( $user ) {
			foreach ( $caps as $cap ) {
				$user->remove_cap( $cap );
			}
		}
	}

	// Role grants (if an admin added a governance cap to a role).
	$roles = wp_roles();
	if ( $roles instanceof WP_Roles ) {
		foreach ( array_keys( $roles->role_objects ) as $role_name ) {
			$role = $roles->role_objects[ $role_name ];
			foreach ( $caps as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}

/**
 * Clean up all per-site data: role, capabilities, and options.
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

	// Remove governance capabilities granted on this site.
	wp_sudo_cleanup_governance_caps();

	delete_option( 'wp_sudo_settings' );
	delete_option( 'wp_sudo_version' );
	delete_option( 'wp_sudo_activated' );
	delete_option( 'wp_sudo_role_version' );
	delete_option( 'wp_sudo_db_version' );
	delete_option( 'wp_sudo_governance_mode' );
}

/**
 * Drop the shared events table.
 *
 * @return void
 */
function wp_sudo_cleanup_events_table(): void {
	\WP_Sudo\Event_Store::drop_table();
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
	wp_sudo_cleanup_events_table();

	// Clean network-wide options (stored in wp_sitemeta).
	delete_site_option( 'wp_sudo_settings' );
	delete_site_option( 'wp_sudo_version' );
	delete_site_option( 'wp_sudo_db_version' );
	delete_site_option( 'wp_sudo_activated' );
	delete_site_option( 'wp_sudo_role_version' );
	delete_site_option( 'wp_sudo_governance_mode' );
} else {
	// Single-site: clean up everything.
	wp_sudo_cleanup_site();
	wp_sudo_cleanup_user_meta();
	wp_sudo_cleanup_mu_shim();
	wp_sudo_cleanup_events_table();
}
