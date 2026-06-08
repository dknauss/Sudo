<?php
/**
 * Tests for the sudo_can() governance helper and wp_sudo_is_recovery_mode().
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use Brain\Monkey\Functions;
use WP_Sudo\Tests\TestCase;

/**
 * Class GovernanceTest
 *
 * Covers sudo_can() across all three decision paths:
 *  - Strict mode  (default): delegates to user_can( $user_id, $cap )
 *  - Compatibility mode:     delegates to manage_options / manage_network_options
 *  - Super-admin short-circuit (multisite only)
 *  - Break-glass recovery mode (manage_wp_sudo only, current user only)
 */
class GovernanceTest extends TestCase {

	// ----------------------------------------------------------------
	// sudo_can() — strict mode (default)
	// ----------------------------------------------------------------

	/**
	 * In strict mode, returns true when user_can returns true for the cap.
	 */
	public function test_sudo_can_strict_returns_true_when_user_has_cap(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 'strict' );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( false );

		$this->assertTrue( sudo_can( 'manage_wp_sudo', 42 ) );
	}

	/**
	 * In strict mode, returns false when user_can returns false for the cap.
	 */
	public function test_sudo_can_strict_returns_false_when_user_lacks_cap(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 'strict' );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( false );

		$this->assertFalse( sudo_can( 'manage_wp_sudo', 42 ) );
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

		$this->assertFalse( sudo_can( 'manage_wp_sudo' ) );
	}

	/**
	 * Returns true for all four governance caps in strict mode.
	 */
	public function test_sudo_can_strict_handles_all_four_caps(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 'strict' );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( false );

		$this->assertTrue( sudo_can( 'manage_wp_sudo', 1 ) );
		$this->assertTrue( sudo_can( 'view_wp_sudo_activity', 1 ) );
		$this->assertTrue( sudo_can( 'export_wp_sudo_activity', 1 ) );
		$this->assertTrue( sudo_can( 'revoke_wp_sudo_sessions', 1 ) );
	}

	// ----------------------------------------------------------------
	// sudo_can() — compatibility mode
	// ----------------------------------------------------------------

	/**
	 * Compatibility mode on single-site falls back to manage_options.
	 */
	public function test_sudo_can_compatibility_single_site_checks_manage_options(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 'compatibility' );
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( false );
		Functions\expect( 'user_can' )
			->once()
			->with( 42, 'manage_options' )
			->andReturn( true );

		$this->assertTrue( sudo_can( 'manage_wp_sudo', 42 ) );
	}

	/**
	 * Compatibility mode on single-site returns false when manage_options absent.
	 */
	public function test_sudo_can_compatibility_single_site_returns_false_without_manage_options(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 'compatibility' );
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( false );
		Functions\when( 'user_can' )->justReturn( false );

		$this->assertFalse( sudo_can( 'manage_wp_sudo', 42 ) );
	}

	/**
	 * Compatibility mode on multisite falls back to manage_network_options.
	 */
	public function test_sudo_can_compatibility_multisite_checks_manage_network_options(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_super_admin' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 'compatibility' );
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( false );
		Functions\expect( 'user_can' )
			->once()
			->with( 42, 'manage_network_options' )
			->andReturn( true );

		$this->assertTrue( sudo_can( 'manage_wp_sudo', 42 ) );
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

		$this->assertTrue( sudo_can( 'view_wp_sudo_activity', 42 ) );
	}

	// ----------------------------------------------------------------
	// sudo_can() — super-admin short-circuit (multisite only)
	// ----------------------------------------------------------------

	/**
	 * Super-admin on multisite bypasses cap check entirely.
	 */
	public function test_sudo_can_super_admin_short_circuits_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_super_admin' )->justReturn( true );
		Functions\expect( 'get_option' )->never();
		Functions\expect( 'user_can' )->never();

		$this->assertTrue( sudo_can( 'manage_wp_sudo', 42 ) );
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

		$this->assertFalse( sudo_can( 'manage_wp_sudo', 42 ) );
	}

	// ----------------------------------------------------------------
	// sudo_can() — break-glass recovery mode
	// ----------------------------------------------------------------

	/**
	 * Recovery mode grants manage_wp_sudo to the current user.
	 */
	public function test_sudo_can_recovery_mode_grants_manage_wp_sudo_to_current_user(): void {
		// TestCase stubs get_current_user_id → 0; use user 0 explicitly.
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( true );
		// user_can and get_option must NOT be reached for this path.
		Functions\expect( 'user_can' )->never();

		$this->assertTrue( sudo_can( 'manage_wp_sudo', 0 ) );
	}

	/**
	 * Recovery mode does NOT bypass checks for other caps.
	 */
	public function test_sudo_can_recovery_mode_does_not_bypass_other_caps(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( 'strict' );
		Functions\when( 'user_can' )->justReturn( false );

		// Recovery mode only covers manage_wp_sudo, not view_wp_sudo_activity.
		$this->assertFalse( sudo_can( 'view_wp_sudo_activity', 0 ) );
	}

	/**
	 * Recovery mode does NOT bypass checks for a different user (not current).
	 */
	public function test_sudo_can_recovery_mode_does_not_apply_to_other_users(): void {
		// Current user is 0 (TestCase default), target user is 99.
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( 'strict' );
		Functions\when( 'user_can' )->justReturn( false );

		// user 99 ≠ current user 0 → recovery mode does not apply.
		$this->assertFalse( sudo_can( 'manage_wp_sudo', 99 ) );
	}

	// ----------------------------------------------------------------
	// wp_sudo_is_recovery_mode()
	// ----------------------------------------------------------------

	/**
	 * wp_sudo_is_recovery_mode() returns true only when constant is defined and truthy.
	 *
	 * We test the wrapper indirectly — since WP_SUDO_RECOVERY_MODE cannot be
	 * toggled at runtime without uopz/runkit, we verify the behavior of
	 * sudo_can() by mocking wp_sudo_is_recovery_mode() which is the testable
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

		$this->assertTrue( sudo_can( 'manage_wp_sudo', 42 ) );
	}
}
