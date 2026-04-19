<?php
/**
 * Integration tests for uninstall.php.
 *
 * Verifies that the uninstall routine correctly removes all plugin data:
 * options, user meta, and capability changes. Tests use real WordPress
 * functions against a real database.
 *
 * @covers uninstall.php
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Event_Store;
use WP_Sudo\Sudo_Session;

class UninstallTest extends TestCase {

	/**
	 * Single-site uninstall removes all plugin data.
	 *
	 * Exercises the full uninstall path: options, user meta, and
	 * capability restoration. Verifies that no plugin artifacts
	 * remain in the database after cleanup.
	 */
	public function test_single_site_uninstall_cleans_all_data(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single-site test — skipped on multisite.' );
		}

		// Arrange: activate plugin and create data.
		$this->activate_plugin();

		$password = 'test-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );

		// Activate a sudo session so user meta exists.
		Sudo_Session::attempt_activation( $user->ID, $password );

		// Seed rate-limit metadata.
		add_user_meta( $user->ID, '_wp_sudo_failure_event', time() );
		update_user_meta( $user->ID, '_wp_sudo_throttle_until', time() + 30 );

		// Verify the data exists before uninstall.
		$this->assertNotEmpty( get_user_meta( $user->ID, '_wp_sudo_expires', true ), 'Expiry meta should exist before uninstall.' );
		$this->assertNotEmpty( get_user_meta( $user->ID, '_wp_sudo_token', true ), 'Token meta should exist before uninstall.' );
		$this->assertNotEmpty( get_user_meta( $user->ID, '_wp_sudo_failure_event', false ), 'Failure event meta should exist before uninstall.' );
		$this->assertNotEmpty( get_user_meta( $user->ID, '_wp_sudo_throttle_until', true ), 'Throttle meta should exist before uninstall.' );

		// Set an option so we can verify deletion.
		update_option( 'wp_sudo_settings', array( 'session_duration' => 5 ) );
		$this->assertNotFalse( get_option( 'wp_sudo_settings' ), 'Settings option should exist before uninstall.' );

		global $wpdb;
		Event_Store::create_table();
		$table = $wpdb->base_prefix . 'wpsudo_events';
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'Events table should exist before uninstall.' );

		// Act: run uninstall.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp-sudo/wp-sudo.php' );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		// Assert: options are removed.
		$this->assertFalse( get_option( 'wp_sudo_settings' ), 'wp_sudo_settings should be deleted.' );
		$this->assertFalse( get_option( 'wp_sudo_activated' ), 'wp_sudo_activated should be deleted.' );
		$this->assertFalse( get_option( 'wp_sudo_role_version' ), 'wp_sudo_role_version should be deleted.' );
		$this->assertFalse( get_option( 'wp_sudo_db_version' ), 'wp_sudo_db_version should be deleted.' );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'Events table should be dropped on uninstall.' );

		// Assert: user meta is removed.
		$this->assertEmpty( get_user_meta( $user->ID, '_wp_sudo_expires', true ), 'Expiry meta should be deleted.' );
		$this->assertEmpty( get_user_meta( $user->ID, '_wp_sudo_token', true ), 'Token meta should be deleted.' );
		$this->assertEmpty( get_user_meta( $user->ID, '_wp_sudo_failed_attempts', true ), 'Legacy failed attempts meta should be deleted.' );
		$this->assertEmpty( get_user_meta( $user->ID, '_wp_sudo_failure_event', false ), 'Failure event meta should be deleted.' );
		$this->assertEmpty( get_user_meta( $user->ID, '_wp_sudo_throttle_until', true ), 'Throttle meta should be deleted.' );
		$this->assertEmpty( get_user_meta( $user->ID, '_wp_sudo_lockout_until', true ), 'Lockout meta should be deleted.' );

		// Assert: unfiltered_html restored to editors.
		$editor = get_role( 'editor' );
		$this->assertTrue( $editor->has_cap( 'unfiltered_html' ), 'Editor should have unfiltered_html restored after uninstall.' );
	}

	/**
	 * Multisite uninstall cleans user meta when no site has the plugin active.
	 *
	 * On multisite, user meta is stored in a shared table. The uninstall
	 * routine must only delete it when no remaining site still has the
	 * plugin active.
	 */
	public function test_multisite_uninstall_cleans_user_meta(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite test — skipped on single-site.' );
		}

		// Arrange: activate plugin and create data.
		$this->activate_plugin();

		$password = 'test-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );

		// Activate a sudo session.
		Sudo_Session::attempt_activation( $user->ID, $password );

		// Seed rate-limit metadata.
		add_user_meta( $user->ID, '_wp_sudo_failure_event', time() );
		update_user_meta( $user->ID, '_wp_sudo_throttle_until', time() + 30 );

		// Verify meta exists.
		$this->assertNotEmpty( get_user_meta( $user->ID, '_wp_sudo_expires', true ), 'Expiry meta should exist before uninstall.' );
		$this->assertNotEmpty( get_user_meta( $user->ID, '_wp_sudo_token', true ), 'Token meta should exist before uninstall.' );
		$this->assertNotEmpty( get_user_meta( $user->ID, '_wp_sudo_failure_event', false ), 'Failure event meta should exist before uninstall.' );
		$this->assertNotEmpty( get_user_meta( $user->ID, '_wp_sudo_throttle_until', true ), 'Throttle meta should exist before uninstall.' );

		// Set site options.
		update_site_option( 'wp_sudo_settings', array( 'session_duration' => 5 ) );

		global $wpdb;
		Event_Store::create_table();
		$table = $wpdb->base_prefix . 'wpsudo_events';
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'Events table should exist before uninstall.' );

		// Ensure the plugin is NOT in the active plugins list for the current site
		// (simulates the state after WordPress has already deactivated the plugin
		// but before the uninstall routine runs).
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$active_plugins = array_diff( $active_plugins, array( 'wp-sudo/wp-sudo.php' ) );
		update_option( 'active_plugins', $active_plugins );

		// Also ensure it's not network-activated.
		$network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
		unset( $network_plugins['wp-sudo/wp-sudo.php'] );
		update_site_option( 'active_sitewide_plugins', $network_plugins );

		// Act: run uninstall.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp-sudo/wp-sudo.php' );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		// Assert: user meta is cleaned (no site has the plugin active).
		$this->assertEmpty( get_user_meta( $user->ID, '_wp_sudo_expires', true ), 'Expiry meta should be deleted on multisite.' );
		$this->assertEmpty( get_user_meta( $user->ID, '_wp_sudo_token', true ), 'Token meta should be deleted on multisite.' );
		$this->assertEmpty( get_user_meta( $user->ID, '_wp_sudo_failed_attempts', true ), 'Legacy failed attempts meta should be deleted on multisite.' );
		$this->assertEmpty( get_user_meta( $user->ID, '_wp_sudo_failure_event', false ), 'Failure event meta should be deleted on multisite.' );
		$this->assertEmpty( get_user_meta( $user->ID, '_wp_sudo_throttle_until', true ), 'Throttle meta should be deleted on multisite.' );
		$this->assertEmpty( get_user_meta( $user->ID, '_wp_sudo_lockout_until', true ), 'Lockout meta should be deleted on multisite.' );

		// Assert: network options are cleaned.
		$this->assertFalse( get_site_option( 'wp_sudo_settings' ), 'Network settings option should be deleted.' );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'Events table should be dropped on multisite uninstall.' );
	}
}
