<?php
/**
 * Integration tests for the plugin activation / deactivation lifecycle hooks.
 *
 * Activation (single-site) strips unfiltered_html from the editor role, grants
 * the governance capabilities to the activating admin, schedules the prune
 * cron, and sets the activated flag. Network activation (multisite) stamps the
 * version, schedules the cron, and sets the network flag without touching
 * editor capabilities. Deactivation restores unfiltered_html, clears the cron,
 * and deletes the flag — but must NOT remove settings or session meta (that is
 * uninstall's job). Before this file only uninstall.php had lifecycle coverage.
 *
 * @covers \WP_Sudo\Plugin::activate
 * @covers \WP_Sudo\Plugin::activate_network
 * @covers \WP_Sudo\Plugin::deactivate
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Sudo_Session;

class PluginLifecycleTest extends TestCase {

	/**
	 * Tear down: clear cron and rebuild the roles global.
	 *
	 * Role capability mutations live both in the wp_user_roles option and in the
	 * cached in-memory WP_Roles global. parent::tear_down() rolls back the option
	 * with the DB transaction, but the already-instantiated $wp_roles global keeps
	 * its mutated cap map for the rest of the process, leaking a stripped (or
	 * restored) editor into later tests. Rebuild the global from the restored
	 * option after the rollback so this class's role changes do not leak.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		// Clear any prune cron scheduled during the test (within the transaction).
		wp_clear_scheduled_hook( 'wp_sudo_prune_events' );

		parent::tear_down();

		unset( $GLOBALS['wp_roles'] );
		wp_roles();
	}

	/**
	 * Count scheduled instances of a cron hook across the cron array.
	 *
	 * wp_next_scheduled() only reports the next run, so it cannot distinguish a
	 * single scheduled event from a double-scheduled one. Counting every entry
	 * for the hook lets the idempotency test assert "exactly one".
	 *
	 * @param string $hook Cron hook name.
	 * @return int Number of scheduled instances.
	 */
	private function count_scheduled( string $hook ): int {
		$crons = _get_cron_array();
		$count = 0;

		if ( is_array( $crons ) ) {
			foreach ( $crons as $events ) {
				if ( isset( $events[ $hook ] ) && is_array( $events[ $hook ] ) ) {
					$count += count( $events[ $hook ] );
				}
			}
		}

		return $count;
	}

	/**
	 * Force the editor role into a known state with unfiltered_html present.
	 *
	 * The shared wp-env database may already have the live plugin active and the
	 * cap stripped, which would make "activation strips it" pass vacuously. Rebuild
	 * the roles global first so arrange and assert read the same object, then add
	 * the cap back.
	 *
	 * @return void
	 */
	private function arrange_editor_with_unfiltered_html(): void {
		unset( $GLOBALS['wp_roles'] );
		wp_roles();
		get_role( 'editor' )->add_cap( 'unfiltered_html' );
		$this->assertTrue(
			get_role( 'editor' )->has_cap( 'unfiltered_html' ),
			'Precondition: editor role holds unfiltered_html before activation.'
		);
	}

	/**
	 * Single-site activation: strip editor cap, grant governance caps, set flag, schedule cron.
	 *
	 * @return void
	 */
	public function test_single_site_activation_sets_up_capabilities_cron_and_flag(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single-site test — skipped on multisite.' );
		}

		$this->arrange_editor_with_unfiltered_html();

		delete_option( 'wp_sudo_activated' );
		wp_clear_scheduled_hook( 'wp_sudo_prune_events' );
		$this->assertSame( 0, $this->count_scheduled( 'wp_sudo_prune_events' ), 'Precondition: no prune cron scheduled.' );

		$admin = $this->make_admin();
		wp_set_current_user( $admin->ID );

		$this->activate_plugin();

		// Editor loses unfiltered_html.
		$this->assertFalse(
			get_role( 'editor' )->has_cap( 'unfiltered_html' ),
			'Activation strips unfiltered_html from the editor role.'
		);

		// The activating admin receives the governance caps as PER-USER caps.
		// Assert against the user's own caps array rather than has_cap(), which
		// can resolve true for an administrator via the governance map_meta_cap
		// short-circuit and would pass even if the grant never ran.
		$refetched = get_userdata( $admin->ID );
		foreach ( wp_sudo_governance_caps() as $cap ) {
			$this->assertArrayHasKey( $cap, $refetched->caps, "Activation grants {$cap} as a per-user capability." );
			$this->assertTrue( (bool) $refetched->caps[ $cap ], "Granted governance cap {$cap} is enabled." );
		}

		// Activated flag and cron.
		$this->assertTrue( get_option( 'wp_sudo_activated' ), 'Activation sets wp_sudo_activated.' );
		$this->assertSame(
			1,
			$this->count_scheduled( 'wp_sudo_prune_events' ),
			'Activation schedules exactly one prune cron event.'
		);
	}

	/**
	 * Single-site deactivation: restore editor cap, clear cron, delete flag, preserve data.
	 *
	 * @return void
	 */
	public function test_single_site_deactivation_restores_cap_clears_cron_and_preserves_data(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single-site test — skipped on multisite.' );
		}

		$password = 'test-password';
		$admin    = $this->make_admin( $password );
		wp_set_current_user( $admin->ID );
		$this->activate_plugin();

		// Settings and session meta that deactivation must PRESERVE.
		update_option( 'wp_sudo_settings', array( 'session_duration' => 5 ) );
		Sudo_Session::attempt_activation( $admin->ID, $password );

		$this->assertFalse( get_role( 'editor' )->has_cap( 'unfiltered_html' ), 'Precondition: editor stripped after activation.' );
		$this->assertTrue( get_option( 'wp_sudo_activated' ), 'Precondition: activated flag set.' );
		$this->assertSame( 1, $this->count_scheduled( 'wp_sudo_prune_events' ), 'Precondition: prune cron scheduled.' );
		$this->assertNotEmpty( get_user_meta( $admin->ID, '_wp_sudo_token', true ), 'Precondition: session token meta exists.' );

		$this->deactivate_plugin();

		// Restored / cleared.
		$this->assertTrue(
			get_role( 'editor' )->has_cap( 'unfiltered_html' ),
			'Deactivation restores unfiltered_html to the editor role.'
		);
		$this->assertFalse( get_option( 'wp_sudo_activated' ), 'Deactivation deletes wp_sudo_activated.' );
		$this->assertSame( 0, $this->count_scheduled( 'wp_sudo_prune_events' ), 'Deactivation clears the prune cron.' );

		// Preserved — cleanup is uninstall's responsibility, not deactivation's.
		$this->assertNotFalse( get_option( 'wp_sudo_settings' ), 'Deactivation preserves wp_sudo_settings.' );
		$this->assertNotEmpty( get_user_meta( $admin->ID, '_wp_sudo_token', true ), 'Deactivation preserves session token meta.' );
	}

	/**
	 * Activation → deactivation round-trip restores the editor cap to its original state.
	 *
	 * Exercises the deactivate() restore path (distinct from uninstall.php) and
	 * confirms the cap returns to the arranged present state.
	 *
	 * @return void
	 */
	public function test_activation_deactivation_round_trip_restores_editor_cap(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single-site test — skipped on multisite.' );
		}

		$this->arrange_editor_with_unfiltered_html();

		$admin = $this->make_admin();
		wp_set_current_user( $admin->ID );

		$this->activate_plugin();
		$this->assertFalse( get_role( 'editor' )->has_cap( 'unfiltered_html' ), 'Activation strips the cap mid-round-trip.' );

		$this->deactivate_plugin();
		$this->assertTrue(
			get_role( 'editor' )->has_cap( 'unfiltered_html' ),
			'Round-trip restores editor unfiltered_html to its original state.'
		);
	}

	/**
	 * Repeated activation is idempotent for the cron schedule and flag.
	 *
	 * @return void
	 */
	public function test_activation_is_idempotent_for_cron_and_flag(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single-site test — skipped on multisite.' );
		}

		$this->arrange_editor_with_unfiltered_html();
		delete_option( 'wp_sudo_activated' );
		wp_clear_scheduled_hook( 'wp_sudo_prune_events' );

		$admin = $this->make_admin();
		wp_set_current_user( $admin->ID );

		$this->activate_plugin();
		$this->activate_plugin();

		$this->assertSame(
			1,
			$this->count_scheduled( 'wp_sudo_prune_events' ),
			'Repeated activation schedules the prune cron only once.'
		);
		$this->assertTrue( get_option( 'wp_sudo_activated' ), 'Activated flag remains set after repeated activation.' );
		$this->assertFalse(
			get_role( 'editor' )->has_cap( 'unfiltered_html' ),
			'Editor remains stripped (not double-mutated) after repeated activation.'
		);
	}

	/**
	 * Multisite network activation sets the network flag and cron without touching editor caps.
	 *
	 * Targets the $network_wide = true branch of the activation closure. The
	 * editor-cap invariant is asserted as "unchanged across activation" rather
	 * than a fixed true/false, because the multisite editor-role default for
	 * unfiltered_html is not relied upon here — only that activate_network is a
	 * no-op for it.
	 *
	 * @return void
	 */
	public function test_multisite_network_activation_sets_flag_and_cron_without_touching_editor(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite test — skipped on single-site.' );
		}

		delete_site_option( 'wp_sudo_activated' );
		wp_clear_scheduled_hook( 'wp_sudo_prune_events' );

		$editor_cap_before = get_role( 'editor' )->has_cap( 'unfiltered_html' );

		$this->network_activate_plugin();

		$this->assertTrue( get_site_option( 'wp_sudo_activated' ), 'Network activation sets the network wp_sudo_activated flag.' );
		$this->assertSame(
			1,
			$this->count_scheduled( 'wp_sudo_prune_events' ),
			'Network activation schedules exactly one prune cron event.'
		);

		$editor_cap_after = get_role( 'editor' )->has_cap( 'unfiltered_html' );
		$this->assertSame(
			$editor_cap_before,
			$editor_cap_after,
			'Network activation does not alter the editor role unfiltered_html cap (core handles it on multisite).'
		);
	}
}
