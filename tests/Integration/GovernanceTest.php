<?php
/**
 * Integration tests for the governance helper functions.
 *
 * Verifies the v4.0.0 breaking changes against a real WordPress load:
 * the deprecated sudo_can() alias is gone, and a stale compatibility-mode
 * option is inert (governance behaves strictly).
 *
 * @covers ::wp_sudo_can
 * @covers ::wp_sudo_map_governance_meta_cap
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Admin;
use WP_Sudo\Upgrader;

class GovernanceTest extends TestCase {

	/**
	 * BRK-01: the deprecated sudo_can() alias is hard-removed. With the real
	 * plugin loaded, the function must not exist.
	 */
	public function test_sudo_can_alias_was_removed(): void {
		$this->assertFalse( function_exists( 'sudo_can' ) );
		$this->assertTrue( function_exists( 'wp_sudo_can' ) );
	}

	/**
	 * BRK-02: a stale wp_sudo_governance_mode = 'compatibility' option is inert.
	 * Governance falls through to strict — a plain admin who lacks the dedicated
	 * manage_wp_sudo capability is NOT granted it via the old manage_options
	 * fallback.
	 */
	public function test_stale_compatibility_option_is_treated_as_strict(): void {
		update_option( 'wp_sudo_governance_mode', 'compatibility' );

		$admin = $this->make_admin();

		// In strict governance the dedicated cap is what counts. A fresh admin
		// without the granted manage_wp_sudo cap must not pass via a bare
		// manage_options fallback (which is what compatibility mode used to do).
		$admin->remove_cap( 'manage_wp_sudo' );

		$this->assertFalse(
			wp_sudo_can( 'manage_wp_sudo', $admin->ID ),
			'Stale compatibility option must not grant manage_wp_sudo via manage_options fallback.'
		);

		delete_option( 'wp_sudo_governance_mode' );
	}

	/**
	 * MIG-02: WP_SUDO_RECOVERY_MODE is the sole break-glass path.
	 *
	 * Two assertions:
	 *
	 * 1. With WP_SUDO_RECOVERY_MODE active, an administrator who still holds
	 *    manage_options but lacks the dedicated manage_wp_sudo cap IS granted
	 *    wp_sudo_can('manage_wp_sudo') access via the break-glass path.
	 *
	 * 2. No other break-glass exists: a user who holds neither manage_wp_sudo
	 *    nor manage_options (a subscriber / editor) is denied, even with recovery
	 *    mode active — proving that the blast radius is contained to
	 *    manage_options holders only.
	 *
	 * CONSTANT NOTE: WP_SUDO_RECOVERY_MODE is a PHP constant that cannot be
	 * undefined once set. This test defines it as true if it is not yet defined
	 * in this process (the integration test bootstrap does not define it). When
	 * running recovery-mode-active assertions the constant must evaluate truthy.
	 * Tests that require recovery mode INACTIVE rely on the user-capability check
	 * (the subscriber lacks manage_options, so the break-glass guard denies them
	 * regardless of the constant value).
	 */
	public function test_recovery_mode_is_sole_break_glass(): void {
		// Make WP_SUDO_RECOVERY_MODE active for this test process.
		if ( ! defined( 'WP_SUDO_RECOVERY_MODE' ) ) {
			define( 'WP_SUDO_RECOVERY_MODE', true );
		}

		$this->assertTrue(
			wp_sudo_is_recovery_mode(),
			'WP_SUDO_RECOVERY_MODE must be truthy for this test to be meaningful.'
		);

		// Assertion 1: admin holds manage_options but NOT manage_wp_sudo — break-glass grants access.
		$admin = $this->make_admin();
		$admin->remove_cap( 'manage_wp_sudo' );
		wp_set_current_user( $admin->ID );

		$this->assertFalse(
			user_can( $admin->ID, 'manage_wp_sudo' ),
			'Precondition: admin must not hold manage_wp_sudo directly.'
		);
		$this->assertTrue(
			user_can( $admin->ID, 'manage_options' ),
			'Precondition: admin must hold manage_options so break-glass applies.'
		);

		$this->assertTrue(
			wp_sudo_can( 'manage_wp_sudo', $admin->ID ),
			'wp_sudo_can() must grant access via WP_SUDO_RECOVERY_MODE for a manage_options holder lacking manage_wp_sudo.'
		);

		// Assertion 2: a subscriber lacks manage_options — break-glass must NOT grant them access.
		// This proves no other break-glass exists: the sole path is recovery mode + manage_options.
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$this->assertFalse(
			user_can( $subscriber_id, 'manage_options' ),
			'Precondition: subscriber must not hold manage_options.'
		);
		$this->assertFalse(
			wp_sudo_can( 'manage_wp_sudo', $subscriber_id ),
			'Recovery mode must NOT grant manage_wp_sudo to a user who lacks manage_options — no other break-glass exists.'
		);
	}

	/**
	 * MIG-07: the no-super-admin lockout scenario is recoverable via WP_SUDO_RECOVERY_MODE.
	 *
	 * Simulates the "locked out" state: no user holds manage_wp_sudo. In strict
	 * governance this blocks all Sudo settings access. With WP_SUDO_RECOVERY_MODE
	 * active, an administrator who still holds manage_options regains effective
	 * manage_wp_sudo access, allowing them to re-grant the capability and restore
	 * normal governance — the documented escape hatch for the lockout scenario.
	 *
	 * On multisite the scenario is also safe: super admins always pass via
	 * wp_sudo_can()'s explicit super-admin short-circuit regardless of recovery mode.
	 *
	 * CONSTANT NOTE: see test_recovery_mode_is_sole_break_glass() for the
	 * WP_SUDO_RECOVERY_MODE constant strategy used across this test class.
	 */
	public function test_no_sudo_lockout_is_recoverable_via_recovery_mode(): void {
		// Ensure WP_SUDO_RECOVERY_MODE is active (idempotent if already defined).
		if ( ! defined( 'WP_SUDO_RECOVERY_MODE' ) ) {
			define( 'WP_SUDO_RECOVERY_MODE', true );
		}

		$this->assertTrue(
			wp_sudo_is_recovery_mode(),
			'WP_SUDO_RECOVERY_MODE must be truthy for the lockout recovery scenario.'
		);

		// Arrange: an administrator who lost manage_wp_sudo (lockout scenario).
		$locked_out_admin = $this->make_admin();
		$locked_out_admin->remove_cap( 'manage_wp_sudo' );
		$locked_out_admin->remove_cap( 'view_wp_sudo_activity' );
		$locked_out_admin->remove_cap( 'export_wp_sudo_activity' );
		$locked_out_admin->remove_cap( 'revoke_wp_sudo_sessions' );
		wp_set_current_user( $locked_out_admin->ID );

		// Verify the lockout: strict cap check denies access.
		$this->assertFalse(
			user_can( $locked_out_admin->ID, 'manage_wp_sudo' ),
			'Precondition: locked-out admin must not hold manage_wp_sudo directly.'
		);

		// Verify the escape hatch: recovery mode restores access.
		$this->assertTrue(
			wp_sudo_can( 'manage_wp_sudo', $locked_out_admin->ID ),
			'WP_SUDO_RECOVERY_MODE must restore wp_sudo_can() access for a locked-out manage_options holder.'
		);

		// Simulate recovery: the operator uses the restored access to re-grant the cap.
		$locked_out_admin->add_cap( 'manage_wp_sudo' );
		$recovered = get_user_by( 'id', $locked_out_admin->ID );

		$this->assertTrue(
			$recovered->has_cap( 'manage_wp_sudo' ),
			'After using recovery mode to re-grant manage_wp_sudo, the cap must be held directly.'
		);

		// After re-grant, strict governance works without recovery mode dependence.
		$this->assertTrue(
			user_can( $recovered->ID, 'manage_wp_sudo' ),
			'After re-grant, user_can() must grant manage_wp_sudo without relying on recovery mode.'
		);
	}

	/**
	 * MIG-07: clear-on-detection removes a stale wp_sudo_governance_mode option
	 * re-added AFTER the version is already stamped at 4.0.0.
	 *
	 * The upgrade_4_0_0() migration deletes the option at the version boundary,
	 * but a stale option could reappear via a DB restore or external tool.
	 * Admin::cleanup_inert_governance_mode_option() runs on admin_init (priority 1)
	 * and provides defense-in-depth: it detects and deletes the option on any admin
	 * request by an authorized manage_wp_sudo user, even when the version is already
	 * stamped at 4.0.0 (so the upgrade routine will never re-run).
	 *
	 * STATIC FLAG: the cleared signal is a static boolean property
	 * ($compat_option_cleared) on Admin, NOT a transient. This test verifies
	 * the flag via ReflectionProperty per the suite's PHP 8.0/8.5 pattern, and
	 * resets it via Admin::reset_cache() before arranging state to avoid
	 * cross-test bleed.
	 */
	public function test_clear_on_detection_removes_option_when_already_at_4_0_0(): void {
		// Reset static flag to ensure a clean starting state (prevents cross-test leakage).
		Admin::reset_cache();

		// Arrange: version is already stamped at WP_SUDO_VERSION — upgrade_4_0_0() will not re-run.
		$this->update_wp_sudo_option( Upgrader::VERSION_OPTION, WP_SUDO_VERSION );

		// Simulate the "option reappeared after upgrade" scenario.
		update_option( 'wp_sudo_governance_mode', 'compatibility' );

		// Arrange: an authorized manage_wp_sudo user is the current user (the gate
		// in cleanup_inert_governance_mode_option() calls wp_sudo_can('manage_wp_sudo')).
		$admin = $this->make_admin();
		$admin->add_cap( 'manage_wp_sudo' );
		wp_set_current_user( $admin->ID );

		// Assert preconditions.
		$this->assertNotFalse(
			get_option( 'wp_sudo_governance_mode' ),
			'Precondition: governance option must be present before clear-on-detection runs.'
		);

		// Act: invoke the cleanup method directly (simulates admin_init firing).
		$admin_instance = new Admin();
		$admin_instance->cleanup_inert_governance_mode_option();

		// Assert: option deleted.
		$this->assertFalse(
			get_option( 'wp_sudo_governance_mode' ),
			'cleanup_inert_governance_mode_option() must delete wp_sudo_governance_mode when found post-4.0.0 stamp.'
		);

		// Assert: static flag set (the LOCKED mechanism — NOT a transient; read via ReflectionProperty).
		$ref = new \ReflectionProperty( Admin::class, 'compat_option_cleared' );
		$ref->setAccessible( true );
		$this->assertTrue(
			$ref->getValue(),
			'cleanup_inert_governance_mode_option() must set the static $compat_option_cleared flag to true.'
		);
	}
}
