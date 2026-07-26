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

	// Remove orphaned IP-scoped lockout transients on this site (#280).
	wp_sudo_cleanup_ip_lockout_transients();

	delete_option( 'wp_sudo_settings' );
	delete_option( 'wp_sudo_version' );
	delete_option( 'wp_sudo_activated' );
	delete_option( 'wp_sudo_role_version' );
	delete_option( 'wp_sudo_db_version' );
	delete_option( 'wp_sudo_governance_mode' );
}

/**
 * Remove orphaned IP-scoped lockout transients on the current site (#280).
 *
 * These transients (Sudo_Session::ip_lockout_transient_key() /
 * ip_failure_event_transient_key()) are keyed deterministically from
 * (ip, user_id, failure-epoch) — not from user meta — so the network-wide
 * user-meta wipe in wp_sudo_cleanup_user_meta() does not reach them, and
 * they are per-blog (delete_transient() operates on the current blog's
 * options), so this must run once per site rather than once network-wide.
 *
 * Why this matters on uninstall specifically: the failure-epoch meta
 * (IP_FAILURE_EPOCH_META_KEY) is what makes an out-of-band `wp sudo unlock`
 * durable — bumping it orphans every transient derived from the OLD epoch.
 * But that meta is itself deleted by wp_sudo_cleanup_user_meta(), so a
 * reinstall within a transient's TTL (up to 24h) sees an absent epoch
 * (identity value 0) and derives the exact same pre-unlock key for a
 * matching (ip, user_id) pair — reviving a failure count an operator
 * already cleared. Sweeping the transients themselves at uninstall time,
 * rather than relying on the epoch to keep them unreachable, closes that
 * window regardless of what epoch value a later reinstall starts from.
 *
 * Uses a direct $wpdb sweep because WordPress exposes no "list transients
 * matching a name pattern" API — the whole point is to catch entries whose
 * (ip, user_id, epoch) triple is no longer reconstructable once the meta
 * above is gone. This only reaches the options-table storage path: on a
 * site with a persistent external object cache (Redis/Memcached), these
 * transients live in the cache instead of `wp_options` and are unaffected,
 * same as any other transient cleanup performed via raw SQL.
 *
 * KNOWN RESIDUAL GAP (tracked in #371, not fixed here): on such a cache-backed
 * site specifically, a reinstall within a still-live transient's TTL can still
 * revive a cleared/pre-clear window at epoch 0, because this sweep cannot
 * reach the cache and the epoch identity value (0) is unchanged by uninstall.
 * The durable fix is a per-installation-instance nonce folded into the key
 * material (so a fresh activation always derives unreachable keys, regardless
 * of storage backend) — deferred because it touches the hottest per-attempt
 * key-derivation path in the plugin and needs its own dedicated PR.
 *
 * @return void
 */
function wp_sudo_cleanup_ip_lockout_transients(): void {
	global $wpdb;

	// Mirrors Sudo_Session::IP_FAILURE_EVENT_TRANSIENT_PREFIX /
	// IP_LOCKOUT_UNTIL_TRANSIENT_PREFIX. Not read from the class constants:
	// this file deliberately avoids loading Sudo_Session (see the plain
	// string literals in wp_sudo_cleanup_user_meta() above for the same
	// reason), so these two prefixes must be kept in sync by hand if they
	// ever change.
	$transient_prefixes = array( 'wp_sudo_ip_failure_event_', 'wp_sudo_ip_lockout_until_' );

	foreach ( $transient_prefixes as $prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- one-time uninstall sweep of a known option-name prefix; no core API enumerates transients by pattern, and the value being deleted is never read back.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
			)
		);
	}
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
	delete_metadata( 'user', 0, '_wp_sudo_proofs', '', true );
	delete_metadata( 'user', 0, '_wp_sudo_token', '', true ); // Legacy pre-4.9.0.
	delete_metadata( 'user', 0, '_wp_sudo_session_bind', '', true ); // Legacy pre-4.9.0.
	delete_metadata( 'user', 0, '_wp_sudo_failed_attempts', '', true );
	delete_metadata( 'user', 0, '_wp_sudo_failure_event', '', true );
	delete_metadata( 'user', 0, '_wp_sudo_throttle_until', '', true );
	delete_metadata( 'user', 0, '_wp_sudo_lockout_until', '', true );
	delete_metadata( 'user', 0, '_wp_sudo_lockout_ip_transient', '', true );
	delete_metadata( 'user', 0, '_wp_sudo_lockout_ip_failure_transient', '', true );
	delete_metadata( 'user', 0, '_wp_sudo_ip_failure_epoch', '', true );
	delete_metadata( 'user', 0, '_wp_sudo_stash_keys', '', true );
}

if ( is_multisite() ) {
	// Get every site in the network.
	$wp_sudo_site_ids = get_sites(
		array(
			'fields'     => 'ids',
			'number'     => 0,
			'network_id' => get_current_network_id(),
		)
	);

	// Clean per-site data (role, options) on every site.
	foreach ( $wp_sudo_site_ids as $wp_sudo_site_id ) {
		switch_to_blog( $wp_sudo_site_id ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
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
