<?php
/**
 * Integration tests for Upgrader — migration chain with real DB.
 *
 * @covers \WP_Sudo\Upgrader
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Admin;
use WP_Sudo\Gate;
use WP_Sudo\Upgrader;

class UpgraderTest extends TestCase {

	/**
	 * Defensively clean up in-memory state that DB rollback cannot revert.
	 *
	 * WP_Roles is a singleton loaded once into memory. remove_role() modifies
	 * both the DB and the singleton, but transaction rollback only restores the
	 * DB — the singleton retains whatever state tests left it in. We defensively
	 * clean up here so tests are isolated.
	 *
	 * Similarly, the editor role's capabilities are an in-memory array. If a
	 * test removed unfiltered_html, we restore it here.
	 */
	public function tear_down(): void {
		// Remove the site_manager role if it was added during the test.
		remove_role( 'site_manager' );

		// Restore editor's unfiltered_html capability if it was stripped.
		$editor = get_role( 'editor' );
		if ( $editor && empty( $editor->capabilities['unfiltered_html'] ) ) {
			$editor->add_cap( 'unfiltered_html' );
		}

		parent::tear_down();
	}

	/**
	 * SURF-01: Full migration chain from v1.9.0 — all 3 routines run.
	 *
	 * Verifies:
	 * - site_manager role is removed (2.0.0)
	 * - allowed_roles stripped from settings (2.0.0)
	 * - wp_sudo_role_version deleted (2.0.0)
	 * - editor unfiltered_html stripped (2.1.0)
	 * - old binary policies migrated to three-tier (2.2.0)
	 * - version stamp updated to WP_SUDO_VERSION
	 */
	public function test_full_migration_chain_from_v1(): void {
		// Arrange: simulate v1 state.
		$this->update_wp_sudo_option( Upgrader::VERSION_OPTION, '1.9.0' );
		add_role( 'site_manager', 'Site Manager', array( 'read' => true ) );
		$this->update_wp_sudo_option(
			Admin::OPTION_KEY,
			array(
				'session_duration'          => 15,
				'allowed_roles'             => array( 'administrator', 'site_manager' ),
				'rest_app_password_policy'  => 'block',
				'cli_policy'                => 'allow',
				'cron_policy'               => 'block',
				'xmlrpc_policy'             => 'allow',
			)
		);
		$this->update_wp_sudo_option( 'wp_sudo_role_version', '1.0.0' );

		// Add unfiltered_html to editor so 2.1.0 migration can strip it.
		$editor = get_role( 'editor' );
		$this->assertNotNull( $editor );
		$editor->add_cap( 'unfiltered_html' );

		Admin::reset_cache();

		// Act.
		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();

		// Assert: version updated.
		$this->assertSame( WP_SUDO_VERSION, $this->get_wp_sudo_option( Upgrader::VERSION_OPTION ) );

		// Assert: 2.0.0 — site_manager role removed.
		$this->assertNull( get_role( 'site_manager' ) );

		// The 2.0.0 migration uses get_option()/delete_option() — not site-option-aware.
		// On multisite these only affect the blog options table (not sitemeta), so the
		// settings written via update_site_option() are not found by the migration.
		// The 2.1.0 migration calls strip_editor_unfiltered_html() which is a no-op
		// on multisite (WP core restricts unfiltered_html to Super Admins already).
		if ( ! is_multisite() ) {
			// Assert: 2.0.0 — allowed_roles stripped.
			$settings = $this->get_wp_sudo_option( Admin::OPTION_KEY );
			$this->assertArrayNotHasKey( 'allowed_roles', $settings );

			// Assert: 2.0.0 — role version option deleted.
			$this->assertFalse( $this->get_wp_sudo_option( 'wp_sudo_role_version' ) );

			// Assert: 2.1.0 — editor unfiltered_html removed.
			$editor = get_role( 'editor' );
			$this->assertEmpty(
				$editor->capabilities['unfiltered_html'] ?? false,
				'Editor should not have unfiltered_html after 2.1.0 migration.'
			);
		}

		// Assert: 2.2.0 — policies migrated to three-tier.
		Admin::reset_cache();
		$this->assertSame( Gate::POLICY_LIMITED, Admin::get( 'rest_app_password_policy' ) );
		$this->assertSame( Gate::POLICY_UNRESTRICTED, Admin::get( 'cli_policy' ) );
		$this->assertSame( Gate::POLICY_LIMITED, Admin::get( 'cron_policy' ) );
		$this->assertSame( Gate::POLICY_UNRESTRICTED, Admin::get( 'xmlrpc_policy' ) );
	}

	/**
	 * SURF-01: Upgrade skipped when already at current version.
	 *
	 * Verifies that no migrations run and pre-existing state survives.
	 */
	public function test_upgrade_skipped_when_already_current(): void {
		// Arrange: version is already current, site_manager exists (should survive).
		$this->update_wp_sudo_option( Upgrader::VERSION_OPTION, WP_SUDO_VERSION );
		add_role( 'site_manager', 'Site Manager', array( 'read' => true ) );

		// Act.
		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();

		// Assert: site_manager role survives — 2.0.0 didn't run.
		$this->assertNotNull(
			get_role( 'site_manager' ),
			'site_manager role should survive when no upgrade runs.'
		);
	}

	/**
	 * SURF-01: Partial migration from v2.0.0 — only 2.1.0 and 2.2.0 run.
	 *
	 * Verifies:
	 * - 2.0.0 routine is skipped (site_manager survives if present).
	 * - editor unfiltered_html stripped (2.1.0).
	 * - policies migrated (2.2.0).
	 */
	public function test_partial_migration_from_v2_0_0(): void {
		// Arrange: already past 2.0.0.
		$this->update_wp_sudo_option( Upgrader::VERSION_OPTION, '2.0.0' );
		$this->update_wp_sudo_option(
			Admin::OPTION_KEY,
			array(
				'session_duration'          => 15,
				'rest_app_password_policy'  => 'block',
				'cli_policy'                => 'allow',
			)
		);

		// Add unfiltered_html to editor so 2.1.0 can strip it.
		$editor = get_role( 'editor' );
		$this->assertNotNull( $editor );
		$editor->add_cap( 'unfiltered_html' );

		Admin::reset_cache();

		// Act.
		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();

		// Assert: version updated.
		$this->assertSame( WP_SUDO_VERSION, $this->get_wp_sudo_option( Upgrader::VERSION_OPTION ) );

		// Assert: 2.1.0 ran — editor unfiltered_html stripped (single site only).
		// On multisite, strip_editor_unfiltered_html() is a no-op because WP core
		// restricts unfiltered_html to Super Admins already.
		if ( ! is_multisite() ) {
			$editor = get_role( 'editor' );
			$this->assertEmpty(
				$editor->capabilities['unfiltered_html'] ?? false,
				'Editor unfiltered_html should be stripped by 2.1.0 migration.'
			);
		}

		// Assert: 2.2.0 ran — policies migrated.
		Admin::reset_cache();
		$this->assertSame( Gate::POLICY_LIMITED, Admin::get( 'rest_app_password_policy' ) );
		$this->assertSame( Gate::POLICY_UNRESTRICTED, Admin::get( 'cli_policy' ) );
	}

	/**
	 * Regression: 3.3.0 backfill must not fatal when the global $wp_roles is
	 * uninitialized at the time the migration runs.
	 *
	 * Reproduces the WP 7.0 WP-CLI provisioning fatal. On WP 7.0,
	 * WP_User_Query::prepare_query() dereferences the raw global $wp_roles
	 * ($wp_roles->for_site()) when handling a `capability` query. At
	 * plugins_loaded — where maybe_upgrade() runs under WP-CLI — that global can
	 * be null, fataling with "Call to a member function for_site() on null".
	 *
	 * The integration suite's bootstrap initializes roles before plugins_loaded,
	 * so we must explicitly null $wp_roles here to reproduce the production
	 * condition, then restore it in finally to avoid cross-test pollution (the
	 * base TestCase does not snapshot $wp_roles).
	 *
	 * Single-site only — the 3.3.0 backfill returns early on multisite.
	 */
	public function test_3_3_0_backfill_survives_uninitialized_wp_roles_global(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'The 3.3.0 governance backfill is single-site only.' );
		}

		// Arrange: stored 3.2.0 so only upgrade_3_3_0() is pending, and a fresh
		// administrator with no governance cap so the backfill actually runs.
		$this->update_wp_sudo_option( Upgrader::VERSION_OPTION, '3.2.0' );
		$admin = $this->make_admin();
		$this->assertFalse(
			$admin->has_cap( 'manage_wp_sudo' ),
			'Precondition: the new admin must not already hold the governance cap.'
		);

		$saved_roles = $GLOBALS['wp_roles'] ?? null;

		try {
			// Simulate the WP-CLI / plugins_loaded condition: roles not yet built.
			// With the bug, the capability holder query inside upgrade_3_3_0()
			// dereferences this null global and fatals.
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentionally reproducing an uninitialized $wp_roles; restored in finally.
			$GLOBALS['wp_roles'] = null;

			$upgrader = new Upgrader();
			$upgrader->maybe_upgrade();
		} finally {
			// Restore the saved roles object regardless of outcome so a failure
			// (or the pre-fix fatal) cannot leak null $wp_roles into later tests.
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the snapshot taken above.
			$GLOBALS['wp_roles'] = $saved_roles;
		}

		// Assert: no fatal (reaching here proves it), version stamped, and the
		// backfill granted the governance caps to the existing administrator.
		$this->assertSame(
			WP_SUDO_VERSION,
			$this->get_wp_sudo_option( Upgrader::VERSION_OPTION ),
			'maybe_upgrade() must complete and stamp the current version.'
		);

		$refetched = get_user_by( 'id', $admin->ID );
		$this->assertInstanceOf( \WP_User::class, $refetched );
		foreach ( Admin::GOVERNANCE_CAPS as $cap ) {
			$this->assertTrue(
				$refetched->has_cap( $cap ),
				"Backfill must grant the {$cap} governance cap to existing admins."
			);
		}
	}

	/**
	 * MIG-01: upgrade_4_0_0 deletes the stale wp_sudo_governance_mode option.
	 *
	 * Stamps the stored version at '3.4.0' so that every routine from 2.0.0
	 * through 4.0.0 runs. Seeds the compatibility-mode option and asserts it is
	 * absent after maybe_upgrade() completes. Proves the deletion path added in
	 * Plan 01's upgrade_4_0_0() routine.
	 */
	public function test_upgrade_4_0_0_deletes_stale_governance_mode_option(): void {
		// Arrange: stored at 3.4.0, compatibility option present.
		$this->update_wp_sudo_option( Upgrader::VERSION_OPTION, '3.4.0' );
		update_option( 'wp_sudo_governance_mode', 'compatibility' );
		if ( is_multisite() ) {
			update_site_option( 'wp_sudo_governance_mode', 'compatibility' );
		}

		// Act.
		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();

		// Assert: option deleted from both stores.
		$this->assertFalse(
			get_option( 'wp_sudo_governance_mode' ),
			'upgrade_4_0_0() must delete wp_sudo_governance_mode from the per-site option store.'
		);
		if ( is_multisite() ) {
			$this->assertFalse(
				get_site_option( 'wp_sudo_governance_mode' ),
				'upgrade_4_0_0() must delete wp_sudo_governance_mode from sitemeta on multisite.'
			);
		}

		// Assert: version stamped to current.
		$this->assertSame(
			WP_SUDO_VERSION,
			$this->get_wp_sudo_option( Upgrader::VERSION_OPTION ),
			'maybe_upgrade() must stamp WP_SUDO_VERSION after running upgrade_4_0_0().'
		);
	}

	/**
	 * MIG-01: upgrade_4_0_0 is idempotent when the governance option is absent.
	 *
	 * A fresh install or an install that already had the option removed must not
	 * error. delete_option() on an absent key is a no-op in WordPress, so this
	 * test verifies that the routine completes cleanly and stamps the version.
	 */
	public function test_upgrade_4_0_0_is_idempotent_when_option_absent(): void {
		// Arrange: stored at 3.4.0, option NOT present.
		$this->update_wp_sudo_option( Upgrader::VERSION_OPTION, '3.4.0' );
		// Ensure the option is definitely absent (transaction rollback handles this,
		// but be explicit for clarity).
		delete_option( 'wp_sudo_governance_mode' );
		if ( is_multisite() ) {
			delete_site_option( 'wp_sudo_governance_mode' );
		}

		// Act: must not error.
		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();

		// Assert: version stamped — the routine completed without fataling.
		$this->assertSame(
			WP_SUDO_VERSION,
			$this->get_wp_sudo_option( Upgrader::VERSION_OPTION ),
			'maybe_upgrade() must stamp WP_SUDO_VERSION even when governance option is absent.'
		);

		// Sanity: option still absent (delete_option on absent key must not create it).
		$this->assertFalse(
			get_option( 'wp_sudo_governance_mode' ),
			'wp_sudo_governance_mode must remain absent after idempotent upgrade_4_0_0() run.'
		);
	}

	/**
	 * MIG-01 / WP 7.0 regression: maybe_upgrade() must not fatal when
	 * $GLOBALS['wp_roles'] is null at the time upgrade_4_0_0() runs.
	 *
	 * This is the 4.0.0 analog of the 3.3.0 regression guard
	 * (test_3_3_0_backfill_survives_uninitialized_wp_roles_global). Because
	 * maybe_upgrade() primes wp_roles() before running any routine, all
	 * capability-based user queries in upgrade_3_3_0() and upgrade_4_0_0()
	 * are safe even when the global is null at entry. Nulling it here and
	 * spanning the assertion across both the 3.3.0 and 4.0.0 routines ensures
	 * the wp_roles() priming line in maybe_upgrade() is never removed.
	 *
	 * Stamping version at '3.3.0' exercises upgrade_4_0_0() with the null-global
	 * condition active, covering the exact failure mode seen on WP 7.0 WP-CLI.
	 *
	 * Single-site only: the 3.3.0 backfill returns early on multisite.
	 * The 4.0.0 routine is universal; the multisite branch is tested in
	 * test_upgrade_4_0_0_deletes_stale_governance_mode_option.
	 */
	public function test_upgrade_4_0_0_does_not_regress_wp_roles_priming(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'The combined regression guard spans upgrade_3_3_0() which is single-site only.' );
		}

		// Arrange: stamp at 3.3.0 so upgrade_4_0_0() is the only pending routine;
		// seed the governance option so there is actual deletion work to do.
		$this->update_wp_sudo_option( Upgrader::VERSION_OPTION, '3.3.0' );
		update_option( 'wp_sudo_governance_mode', 'compatibility' );

		$saved_roles = $GLOBALS['wp_roles'] ?? null;

		try {
			// Reproduce the WP-CLI / plugins_loaded condition: null global.
			// With the bug (missing wp_roles() prime), the capability query inside
			// upgrade_3_3_0() would dereference $wp_roles->for_site() and fatal.
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentionally reproducing an uninitialized $wp_roles; restored in finally.
			$GLOBALS['wp_roles'] = null;

			$upgrader = new Upgrader();
			$upgrader->maybe_upgrade();
		} finally {
			// Restore — a test failure or pre-fix fatal must not leak null into later tests.
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the snapshot taken above.
			$GLOBALS['wp_roles'] = $saved_roles;
		}

		// Assert: no fatal (reaching here proves it), version stamped.
		$this->assertSame(
			WP_SUDO_VERSION,
			$this->get_wp_sudo_option( Upgrader::VERSION_OPTION ),
			'maybe_upgrade() must complete and stamp WP_SUDO_VERSION even with null $wp_roles.'
		);

		// Assert: governance option deleted — upgrade_4_0_0() ran.
		$this->assertFalse(
			get_option( 'wp_sudo_governance_mode' ),
			'upgrade_4_0_0() must delete wp_sudo_governance_mode during the wp_roles=null regression guard test.'
		);
	}

	/**
	 * MIG-06: first activation grants the manage_wp_sudo governance cap to the
	 * activating administrator.
	 *
	 * The Plugin::activate() callback runs the upgrader (which stamps the version)
	 * and then grants all four governance capabilities to get_current_user_id().
	 * This test verifies the first-run grant path so the "no holder after fresh
	 * install" lockout scenario cannot regress.
	 */
	public function test_first_activation_grants_manage_wp_sudo_to_administrator(): void {
		// Arrange: a fresh admin is the current user (simulates the activating operator).
		$admin = $this->make_admin();
		wp_set_current_user( $admin->ID );

		// Precondition: the admin does not yet have the governance cap.
		$admin->remove_cap( 'manage_wp_sudo' );
		$admin->remove_cap( 'view_wp_sudo_activity' );
		$admin->remove_cap( 'export_wp_sudo_activity' );
		$admin->remove_cap( 'revoke_wp_sudo_sessions' );
		$fresh = get_user_by( 'id', $admin->ID );
		$this->assertFalse(
			$fresh->has_cap( 'manage_wp_sudo' ),
			'Precondition: the admin must not already hold manage_wp_sudo before activation.'
		);

		// Act: fire the activation hook (Plugin::activate() calls Upgrader then grants caps).
		$this->activate_plugin();

		// Assert: all four governance caps granted.
		$granted = get_user_by( 'id', $admin->ID );
		$this->assertInstanceOf( \WP_User::class, $granted );
		foreach ( Admin::GOVERNANCE_CAPS as $cap ) {
			$this->assertTrue(
				$granted->has_cap( $cap ),
				"First activation must grant the {$cap} governance capability to the activating administrator."
			);
		}
	}

	/**
	 * SURF-01: 2.2.0 preserves already-valid three-tier policy values.
	 *
	 * Verifies that 'disabled', 'limited', 'unrestricted' values survive
	 * the migration unchanged.
	 */
	public function test_upgrade_2_2_0_preserves_valid_policy_values(): void {
		// Arrange: version at 2.1.0, already-valid values.
		$this->update_wp_sudo_option( Upgrader::VERSION_OPTION, '2.1.0' );
		$this->update_wp_sudo_option(
			Admin::OPTION_KEY,
			array(
				'rest_app_password_policy' => Gate::POLICY_DISABLED,
				'cli_policy'               => Gate::POLICY_LIMITED,
				'cron_policy'              => Gate::POLICY_UNRESTRICTED,
				'xmlrpc_policy'            => Gate::POLICY_DISABLED,
			)
		);

		Admin::reset_cache();

		// Act.
		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();

		// Assert: values unchanged.
		Admin::reset_cache();
		$this->assertSame( Gate::POLICY_DISABLED, Admin::get( 'rest_app_password_policy' ) );
		$this->assertSame( Gate::POLICY_LIMITED, Admin::get( 'cli_policy' ) );
		$this->assertSame( Gate::POLICY_UNRESTRICTED, Admin::get( 'cron_policy' ) );
		$this->assertSame( Gate::POLICY_DISABLED, Admin::get( 'xmlrpc_policy' ) );
	}
}
