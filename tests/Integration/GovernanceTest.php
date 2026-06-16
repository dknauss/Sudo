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
}
