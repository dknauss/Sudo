<?php
/**
 * Integration tests for the #179 role/capability drift sweep.
 *
 * These drive REAL stored state (users, roles, capabilities meta, and — on
 * multisite — the site_admins network option), mutating it directly (including
 * via $wpdb, the path the escalation guard's metadata hooks cannot see) and
 * asserting the audit sweep detects the drift and fires
 * `wp_sudo_role_drift_detected`.
 *
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Role_Audit;

/**
 * @coversDefaultClass \WP_Sudo\Role_Audit
 * @group role-lockdown
 */
class RoleAuditSweepTest extends TestCase {

	/**
	 * Build a manifest that reflects the CURRENT clean state, watching the
	 * `administrator` role definition. Evaluated immediately it yields no drift;
	 * subsequent mutations then show up as the only drift.
	 *
	 * @return array<string, mixed>
	 */
	private function baseline_manifest(): array {
		return Role_Audit::collect_current_state( array( 'privileged_roles' => array( 'administrator' => '' ) ) );
	}

	public function test_no_drift_when_state_matches_manifest(): void {
		$manifest = $this->baseline_manifest();

		$report = Role_Audit::evaluate( $manifest );

		$this->assertFalse( $report['has_drift'], 'a manifest matching current state must show no drift.' );
	}

	public function test_detects_unauthorized_administrator(): void {
		$manifest = $this->baseline_manifest();

		// A new administrator created AFTER the baseline is unauthorized.
		$intruder = $this->make_admin();

		$report = Role_Audit::evaluate( $manifest );

		$this->assertTrue( $report['has_drift'] );
		$this->assertContains( $intruder->ID, $report['sites'][ get_current_blog_id() ]['administrators'] );
	}

	public function test_detects_administrator_via_secondary_role(): void {
		$manifest = $this->baseline_manifest();

		// Subscriber promoted by ADDING administrator as a secondary role — the
		// collector must enumerate by role membership, not just the primary role.
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_role( 'administrator' );

		$report = Role_Audit::evaluate( $manifest );

		$this->assertTrue( $report['has_drift'] );
		$this->assertContains( $user->ID, $report['sites'][ get_current_blog_id() ]['administrators'] );
	}

	public function test_detects_direct_db_capabilities_write(): void {
		$manifest = $this->baseline_manifest();

		// Grant administrator via a DIRECT usermeta write, bypassing the metadata
		// API the escalation guard hooks — the exact gap this feature closes.
		global $wpdb;
		$user     = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$meta_key = $wpdb->get_blog_prefix( get_current_blog_id() ) . 'capabilities';
		$wpdb->update(
			$wpdb->usermeta,
			array( 'meta_value' => serialize( array( 'administrator' => true ) ) ),
			array(
				'user_id'  => $user->ID,
				'meta_key' => $meta_key,
			)
		);
		clean_user_cache( $user->ID );

		$report = Role_Audit::evaluate( $manifest );

		$this->assertTrue( $report['has_drift'], 'a direct-DB administrator grant must be detected.' );
		$this->assertContains( $user->ID, $report['sites'][ get_current_blog_id() ]['administrators'] );
	}

	public function test_detects_governance_capability_holder(): void {
		$manifest = $this->baseline_manifest();

		// A non-admin directly granted a WP Sudo governance cap — an attack on the
		// audit tool itself. Governance holders diverge from the admin set.
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'manage_wp_sudo' );

		$report = Role_Audit::evaluate( $manifest );

		$this->assertTrue( $report['has_drift'] );
		$this->assertContains( $user->ID, $report['sites'][ get_current_blog_id() ]['governance'] );
	}

	public function test_detects_role_definition_drift(): void {
		$manifest = $this->baseline_manifest();

		$admin_role = get_role( 'administrator' );
		$admin_role->add_cap( 'wp_sudo_injected_cap' );

		try {
			$report = Role_Audit::evaluate( $manifest );

			$this->assertTrue( $report['has_drift'], 'redefining the administrator role must be detected.' );
			$this->assertArrayHasKey( 'administrator', $report['roles'] );
		} finally {
			$admin_role->remove_cap( 'wp_sudo_injected_cap' );
		}
	}

	public function test_fires_role_drift_detected_action_on_drift(): void {
		$manifest = $this->baseline_manifest();
		$this->make_admin();

		$captured = null;
		add_action(
			'wp_sudo_role_drift_detected',
			static function ( $report ) use ( &$captured ) {
				$captured = $report;
			}
		);

		Role_Audit::evaluate( $manifest );

		$this->assertIsArray( $captured, 'the drift action must fire with the report.' );
		$this->assertTrue( $captured['has_drift'] );
	}

	public function test_does_not_fire_action_when_no_drift(): void {
		$manifest = $this->baseline_manifest();

		$fired = false;
		add_action(
			'wp_sudo_role_drift_detected',
			static function () use ( &$fired ) {
				$fired = true;
			}
		);

		$report = Role_Audit::evaluate( $manifest );

		$this->assertFalse( $report['has_drift'] );
		$this->assertFalse( $fired, 'no drift → no action.' );
	}

	public function test_detects_unauthorized_super_admin(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'super-admin drift is a multisite concern (run with WP_TESTS_MULTISITE=1).' );
		}

		$manifest = $this->baseline_manifest();

		// Direct grant of super-admin authority: add the user to the network
		// `site_admins` option (grant_super_admin writes it; the audit reads it).
		$user = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		grant_super_admin( $user->ID );

		try {
			$report = Role_Audit::evaluate( $manifest );

			$this->assertTrue( $report['has_drift'], 'an unauthorized super admin must be detected.' );
			$this->assertContains( $user->ID, $report['network']['super_admins'] );
		} finally {
			revoke_super_admin( $user->ID );
		}
	}

	public function test_run_sweep_returns_null_when_feature_disabled(): void {
		// WP_SUDO_ROLE_MANIFEST is not defined in the test env → inert.
		$this->assertFalse( defined( 'WP_SUDO_ROLE_MANIFEST' ) );
		$this->assertNull( Role_Audit::run_sweep() );
	}
}
