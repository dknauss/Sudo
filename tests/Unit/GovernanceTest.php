<?php
/**
 * Tests for the wp_sudo_can() governance helper and wp_sudo_is_recovery_mode().
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use Brain\Monkey\Functions;
use WP_Sudo\Tests\TestCase;

/**
 * Class GovernanceTest
 *
 * Covers wp_sudo_can() across all three decision paths:
 *  - Strict mode  (default): delegates to user_can( $user_id, $cap )
 *  - Compatibility mode:     delegates to manage_options / manage_network_options
 *  - Super-admin short-circuit (multisite only)
 *  - Break-glass recovery mode (manage_wp_sudo only, current user only)
 */
class GovernanceTest extends TestCase {

	// ----------------------------------------------------------------
	// wp_sudo_map_governance_meta_cap()
	// ----------------------------------------------------------------

	public function test_map_governance_meta_cap_leaves_unrelated_caps_unchanged(): void {
		$this->assertSame(
			array( 'edit_posts' ),
			wp_sudo_map_governance_meta_cap( array( 'edit_posts' ), 'edit_posts', 42 )
		);
	}

	public function test_map_governance_meta_cap_strict_mode_maps_to_requested_cap(): void {
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 'strict' );

		$this->assertSame(
			array( 'manage_wp_sudo' ),
			wp_sudo_map_governance_meta_cap( array( 'do_not_allow' ), 'manage_wp_sudo', 42 )
		);
	}

	public function test_map_governance_meta_cap_treats_compatibility_as_strict_after_removal(): void {
		// BRK-02: compatibility mode is removed. A stale 'compatibility' option
		// value is inert — the mapper falls through to strict, returning the
		// requested cap itself rather than manage_options / manage_network_options.
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 'compatibility' );
		Functions\when( 'is_multisite' )->justReturn( false );

		$this->assertSame(
			array( 'manage_wp_sudo' ),
			wp_sudo_map_governance_meta_cap( array(), 'manage_wp_sudo', 42 )
		);
	}

	public function test_map_governance_meta_cap_recovery_mode_maps_manage_wp_sudo_to_manage_options_single_site(): void {
		// The recovery decision now lives in the wp_sudo_user_matches_recovery()
		// seam; a matched user maps manage_wp_sudo to the admin primitive cap.
		Functions\when( 'wp_sudo_user_matches_recovery' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );

		// Recovery mode delegates the role check to core by mapping the meta cap
		// to manage_options, so the admin-page gate only admits real admins.
		$this->assertSame(
			array( 'manage_options' ),
			wp_sudo_map_governance_meta_cap( array( 'do_not_allow' ), 'manage_wp_sudo', 0 )
		);
	}

	public function test_map_governance_meta_cap_recovery_mode_maps_manage_wp_sudo_to_manage_network_options_multisite(): void {
		Functions\when( 'wp_sudo_user_matches_recovery' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( true );

		$this->assertSame(
			array( 'manage_network_options' ),
			wp_sudo_map_governance_meta_cap( array( 'do_not_allow' ), 'manage_wp_sudo', 0 )
		);
	}

	public function test_map_governance_meta_cap_recovery_mode_ignores_unmatched_user(): void {
		// A user the recovery seam does not match (wrong user, or scoped to
		// someone else) must not get the mapping — falls through to strict mode.
		Functions\when( 'wp_sudo_user_matches_recovery' )->justReturn( false );

		$this->assertSame(
			array( 'manage_wp_sudo' ),
			wp_sudo_map_governance_meta_cap( array( 'do_not_allow' ), 'manage_wp_sudo', 99 )
		);
	}

	public function test_map_governance_meta_cap_recovery_mode_does_not_grant_other_caps(): void {
		// Even a recovery-matched user only gets manage_wp_sudo mapped; other
		// governance caps fall through to strict (the seam is never consulted
		// for them, so leaving it matched proves the cap gate short-circuits).
		Functions\when( 'wp_sudo_user_matches_recovery' )->justReturn( true );

		$this->assertSame(
			array( 'view_wp_sudo_activity' ),
			wp_sudo_map_governance_meta_cap( array( 'do_not_allow' ), 'view_wp_sudo_activity', 0 )
		);
	}

	// ----------------------------------------------------------------
	// wp_sudo_can() — strict mode (default)
	// ----------------------------------------------------------------

	/**
	 * In strict mode, returns true when user_can returns true for the cap.
	 */
	public function test_sudo_can_strict_returns_true_when_user_has_cap(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 'strict' );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( false );

		$this->assertTrue( wp_sudo_can( 'manage_wp_sudo', 42 ) );
	}

	/**
	 * In strict mode, returns false when user_can returns false for the cap.
	 */
	public function test_sudo_can_strict_returns_false_when_user_lacks_cap(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 'strict' );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( false );

		$this->assertFalse( wp_sudo_can( 'manage_wp_sudo', 42 ) );
	}

	/**
	 * Default user_id resolves to get_current_user_id() when omitted.
	 */
	public function test_sudo_can_defaults_to_current_user(): void {
		// TestCase already stubs get_current_user_id → 0.
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 'strict' );
		Functions\expect( 'user_can' )
			->once()
			->with( 0, 'manage_wp_sudo' )
			->andReturn( false );
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( false );

		$this->assertFalse( wp_sudo_can( 'manage_wp_sudo' ) );
	}

	/**
	 * Returns true for all four governance caps in strict mode.
	 */
	public function test_sudo_can_strict_handles_all_four_caps(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 'strict' );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( false );

		$this->assertTrue( wp_sudo_can( 'manage_wp_sudo', 1 ) );
		$this->assertTrue( wp_sudo_can( 'view_wp_sudo_activity', 1 ) );
		$this->assertTrue( wp_sudo_can( 'export_wp_sudo_activity', 1 ) );
		$this->assertTrue( wp_sudo_can( 'revoke_wp_sudo_sessions', 1 ) );
	}

	// ----------------------------------------------------------------
	// wp_sudo_can() — stale compatibility option is inert (BRK-02)
	// ----------------------------------------------------------------

	/**
	 * BRK-02: compatibility mode is removed. A stale 'compatibility' option
	 * value falls through to the strict cap check — wp_sudo_can() delegates to
	 * user_can() for the requested cap, never the manage_options fallback.
	 */
	public function test_sudo_can_treats_compatibility_as_strict_after_removal(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 'compatibility' );
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( false );
		Functions\expect( 'user_can' )
			->once()
			->with( 42, 'manage_wp_sudo' )
			->andReturn( true );

		$this->assertTrue( wp_sudo_can( 'manage_wp_sudo', 42 ) );
	}

	/**
	 * Strict mode strict on multisite still checks the specific cap (not manage_network_options).
	 */
	public function test_sudo_can_strict_multisite_checks_specific_cap(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_super_admin' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 'strict' );
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( false );
		Functions\expect( 'user_can' )
			->once()
			->with( 42, 'view_wp_sudo_activity' )
			->andReturn( true );

		$this->assertTrue( wp_sudo_can( 'view_wp_sudo_activity', 42 ) );
	}

	// ----------------------------------------------------------------
	// wp_sudo_can() — super-admin short-circuit (multisite only)
	// ----------------------------------------------------------------

	/**
	 * Super-admin on multisite bypasses cap check entirely.
	 */
	public function test_sudo_can_super_admin_short_circuits_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_super_admin' )->justReturn( true );
		Functions\expect( 'get_option' )->never();
		Functions\expect( 'user_can' )->never();

		$this->assertTrue( wp_sudo_can( 'manage_wp_sudo', 42 ) );
	}

	/**
	 * Super-admin on single-site does NOT short-circuit (is_super_admin not checked).
	 */
	public function test_sudo_can_super_admin_no_short_circuit_on_single_site(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 'strict' );
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( false );
		Functions\when( 'user_can' )->justReturn( false );
		// is_super_admin should never be called on single-site.
		Functions\expect( 'is_super_admin' )->never();

		$this->assertFalse( wp_sudo_can( 'manage_wp_sudo', 42 ) );
	}

	// ----------------------------------------------------------------
	// wp_sudo_can() — break-glass recovery mode
	// ----------------------------------------------------------------

	/**
	 * Recovery mode grants manage_wp_sudo to the current user only when they
	 * still hold manage_options (single-site).
	 */
	public function test_sudo_can_recovery_mode_grants_manage_wp_sudo_to_matched_admin(): void {
		// A recovery-matched user still must pass the role gate (manage_options).
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_sudo_user_matches_recovery' )->justReturn( true );
		Functions\expect( 'user_can' )
			->once()
			->with( 0, 'manage_options' )
			->andReturn( true );

		$this->assertTrue( wp_sudo_can( 'manage_wp_sudo', 0 ) );
	}

	/**
	 * Recovery mode does NOT grant manage_wp_sudo to a matched user who lacks
	 * site-admin authority — a subscriber/editor gains nothing. The role gate
	 * fails, then the strict cap check also fails.
	 */
	public function test_sudo_can_recovery_mode_does_not_grant_to_non_admin(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_sudo_user_matches_recovery' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( 'strict' );
		// manage_options (role gate) → false; manage_wp_sudo (strict) → false.
		Functions\when( 'user_can' )->justReturn( false );

		$this->assertFalse( wp_sudo_can( 'manage_wp_sudo', 0 ) );
	}

	/**
	 * On multisite, the recovery role gate checks manage_network_options.
	 * (Super admins already short-circuit at step 1 and never reach this branch.)
	 */
	public function test_sudo_can_recovery_mode_role_gate_uses_network_cap_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_super_admin' )->justReturn( false );
		Functions\when( 'wp_sudo_user_matches_recovery' )->justReturn( true );
		Functions\expect( 'user_can' )
			->once()
			->with( 0, 'manage_network_options' )
			->andReturn( true );

		$this->assertTrue( wp_sudo_can( 'manage_wp_sudo', 0 ) );
	}

	/**
	 * Recovery mode does NOT bypass checks for other caps.
	 */
	public function test_sudo_can_recovery_mode_does_not_bypass_other_caps(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_sudo_user_matches_recovery' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( 'strict' );
		Functions\when( 'user_can' )->justReturn( false );

		// Recovery mode only covers manage_wp_sudo, not view_wp_sudo_activity.
		$this->assertFalse( wp_sudo_can( 'view_wp_sudo_activity', 0 ) );
	}

	/**
	 * Recovery mode does NOT apply to a user the seam does not match (wrong or
	 * out-of-scope user). The role gate is never reached; strict check decides.
	 */
	public function test_sudo_can_recovery_mode_does_not_apply_to_unmatched_users(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_sudo_user_matches_recovery' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 'strict' );
		Functions\when( 'user_can' )->justReturn( false );

		$this->assertFalse( wp_sudo_can( 'manage_wp_sudo', 99 ) );
	}

	// ----------------------------------------------------------------
	// wp_sudo_user_matches_recovery() — the three-state grant seam (#240)
	// ----------------------------------------------------------------

	/**
	 * Inactive recovery matches no user.
	 */
	public function test_user_matches_recovery_false_when_inactive(): void {
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( false );

		$this->assertFalse( wp_sudo_user_matches_recovery( 5 ) );
	}

	/**
	 * Unscoped recovery (legacy boolean true) matches the CURRENT user only,
	 * preserving the pre-scoping contract.
	 */
	public function test_user_matches_recovery_unscoped_matches_current_user(): void {
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( true );
		Functions\when( 'wp_sudo_recovery_mode_is_unscoped' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		$this->assertTrue( wp_sudo_user_matches_recovery( 7 ) );
	}

	public function test_user_matches_recovery_unscoped_rejects_other_user(): void {
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( true );
		Functions\when( 'wp_sudo_recovery_mode_is_unscoped' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		$this->assertFalse( wp_sudo_user_matches_recovery( 8 ) );
	}

	/**
	 * Scoped recovery matches only the resolved target user, regardless of who
	 * the current user is.
	 */
	public function test_user_matches_recovery_scoped_matches_target(): void {
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( true );
		Functions\when( 'wp_sudo_recovery_mode_is_unscoped' )->justReturn( false );
		Functions\when( 'wp_sudo_recovery_mode_user' )->justReturn( 12 );

		$this->assertTrue( wp_sudo_user_matches_recovery( 12 ) );
	}

	public function test_user_matches_recovery_scoped_rejects_non_target(): void {
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( true );
		Functions\when( 'wp_sudo_recovery_mode_is_unscoped' )->justReturn( false );
		Functions\when( 'wp_sudo_recovery_mode_user' )->justReturn( 12 );

		$this->assertFalse( wp_sudo_user_matches_recovery( 13 ) );
	}

	/**
	 * B1 fail-closed: a scoped-but-unresolvable target (typo'd login/ID →
	 * wp_sudo_recovery_mode_user() returns null) must grant NOBODY. A null
	 * target must never be read as "unscoped / any admin".
	 */
	public function test_user_matches_recovery_scoped_unresolvable_matches_nobody(): void {
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( true );
		Functions\when( 'wp_sudo_recovery_mode_is_unscoped' )->justReturn( false );
		Functions\when( 'wp_sudo_recovery_mode_user' )->justReturn( null );

		$this->assertFalse( wp_sudo_user_matches_recovery( 0 ) );
		$this->assertFalse( wp_sudo_user_matches_recovery( 1 ) );
		$this->assertFalse( wp_sudo_user_matches_recovery( 12 ) );
	}

	// ----------------------------------------------------------------
	// Constant-reading helpers — undefined-constant guards (#240)
	// ----------------------------------------------------------------

	/**
	 * With the constant undefined (the unit-test environment), the unscoped
	 * predicate is false. Value resolution is covered in integration tests.
	 */
	public function test_recovery_mode_is_unscoped_false_when_constant_undefined(): void {
		$this->assertFalse( defined( 'WP_SUDO_RECOVERY_MODE' ), 'guard: constant must be undefined.' );

		$this->assertFalse( wp_sudo_recovery_mode_is_unscoped() );
	}

	/**
	 * With the constant undefined, the scoped-target resolver returns null and
	 * never attempts a user lookup.
	 */
	public function test_recovery_mode_user_null_when_constant_undefined(): void {
		$this->assertFalse( defined( 'WP_SUDO_RECOVERY_MODE' ), 'guard: constant must be undefined.' );

		$this->assertNull( wp_sudo_recovery_mode_user() );
	}

	// ----------------------------------------------------------------
	// wp_sudo_is_recovery_mode()
	// ----------------------------------------------------------------

	/**
	 * wp_sudo_is_recovery_mode() returns true only when constant is defined and truthy.
	 *
	 * We test the wrapper indirectly — since WP_SUDO_RECOVERY_MODE cannot be
	 * toggled at runtime without uopz/runkit, we verify the behavior of
	 * wp_sudo_can() by mocking wp_sudo_is_recovery_mode() which is the testable
	 * seam. The constant check itself is covered by the definition in
	 * functions-governance.php and verified at runtime.
	 */
	public function test_sudo_can_when_recovery_mode_false_falls_through_to_cap_check(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 'strict' );
		Functions\expect( 'user_can' )
			->once()
			->with( 42, 'manage_wp_sudo' )
			->andReturn( true );

		$this->assertTrue( wp_sudo_can( 'manage_wp_sudo', 42 ) );
	}

	// ----------------------------------------------------------------
	// sudo_can() deprecated alias — removed in 4.0.0 (BRK-01)
	// ----------------------------------------------------------------

	/**
	 * BRK-01: the deprecated sudo_can() alias is hard-removed. The function must
	 * not exist; calling it would fatal. wp_sudo_can() is the only survivor.
	 */
	public function test_sudo_can_alias_no_longer_exists(): void {
		$this->assertFalse( function_exists( 'sudo_can' ) );
	}

	// ----------------------------------------------------------------
	// wp_sudo_governance_caps()
	// ----------------------------------------------------------------

	/**
	 * The canonical cap list contains exactly the four governance caps and
	 * matches the list the meta-cap mapper gates on.
	 */
	public function test_governance_caps_returns_the_four_canonical_caps(): void {
		$this->assertSame(
			array(
				'manage_wp_sudo',
				'view_wp_sudo_activity',
				'export_wp_sudo_activity',
				'revoke_wp_sudo_sessions',
			),
			wp_sudo_governance_caps()
		);
	}
}
