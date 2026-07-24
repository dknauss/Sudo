<?php
/**
 * Tests for Role_Audit pure engine — role-definition hashing and manifest diff.
 *
 * The WP-integration surface (state collection, sweep, event firing) is covered
 * separately by integration tests; this file covers only the deterministic,
 * DB-free logic.
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use Brain\Monkey\Functions;
use WP_Sudo\Role_Audit;
use WP_Sudo\Tests\TestCase;

/**
 * @covers \WP_Sudo\Role_Audit
 */
class RoleAuditTest extends TestCase {

	// ---- hash_role_definition() ----

	public function test_hash_role_definition_is_order_independent(): void {
		$a = Role_Audit::hash_role_definition(
			array(
				'read'           => true,
				'edit_posts'     => true,
				'manage_options' => true,
			) 
		);
		$b = Role_Audit::hash_role_definition(
			array(
				'manage_options' => true,
				'read'           => true,
				'edit_posts'     => true,
			) 
		);

		$this->assertSame( $a, $b, 'cap-map order must not change the hash.' );
	}

	public function test_hash_role_definition_false_equals_absent(): void {
		$with_false = Role_Audit::hash_role_definition(
			array(
				'read'           => true,
				'manage_options' => false,
			) 
		);
		$absent     = Role_Audit::hash_role_definition( array( 'read' => true ) );

		$this->assertSame( $with_false, $absent, 'a cap set to false must hash the same as an absent cap.' );
	}

	public function test_hash_role_definition_changes_when_a_cap_is_added(): void {
		$before = Role_Audit::hash_role_definition( array( 'read' => true ) );
		$after  = Role_Audit::hash_role_definition(
			array(
				'read'           => true,
				'manage_options' => true,
			) 
		);

		$this->assertNotSame( $before, $after, 'adding a granted cap must change the hash.' );
	}

	public function test_hash_role_definition_is_prefixed(): void {
		$hash = Role_Audit::hash_role_definition( array( 'read' => true ) );
		$this->assertStringStartsWith( 'sha256:', $hash );
	}

	// ---- diff(): principal drift ----

	private function manifest(): array {
		return array(
			'manifest_version' => 1,
			'sites'            => array(
				1 => array(
					'administrators' => array( 1, 7 ),
					'governance'     => array( 1 ),
				),
			),
			'network'          => array( 'super_admins' => array( 1 ) ),
			'privileged_roles' => array( 'administrator' => 'sha256:expected' ),
		);
	}

	public function test_diff_clean_state_reports_no_drift(): void {
		$current = $this->manifest();
		$report  = Role_Audit::diff( $this->manifest(), $current );

		$this->assertFalse( $report['has_drift'] );
	}

	public function test_diff_flags_unauthorized_administrator(): void {
		$current                               = $this->manifest();
		$current['sites'][1]['administrators'] = array( 1, 7, 99 ); // 99 is not in the manifest.

		$report = Role_Audit::diff( $this->manifest(), $current );

		$this->assertTrue( $report['has_drift'] );
		$this->assertSame( array( 99 ), $report['sites'][1]['administrators'] );
	}

	public function test_diff_flags_unauthorized_super_admin(): void {
		$current                            = $this->manifest();
		$current['network']['super_admins'] = array( 1, 42 ); // 42 unauthorized.

		$report = Role_Audit::diff( $this->manifest(), $current );

		$this->assertTrue( $report['has_drift'] );
		$this->assertSame( array( 42 ), $report['network']['super_admins'] );
	}

	public function test_diff_flags_unauthorized_governance_holder(): void {
		$current                           = $this->manifest();
		$current['sites'][1]['governance'] = array( 1, 5 ); // 5 unauthorized.

		$report = Role_Audit::diff( $this->manifest(), $current );

		$this->assertTrue( $report['has_drift'] );
		$this->assertSame( array( 5 ), $report['sites'][1]['governance'] );
	}

	public function test_diff_does_not_flag_removed_authorized_principal(): void {
		// A trusted admin present in the manifest but missing from current state is
		// NOT an escalation — audit only flags unauthorized ADDITIONS.
		$current                               = $this->manifest();
		$current['sites'][1]['administrators'] = array( 1 ); // 7 removed.

		$report = Role_Audit::diff( $this->manifest(), $current );

		$this->assertFalse( $report['has_drift'] );
	}

	// ---- diff(): role-definition drift ----

	public function test_diff_flags_role_hash_mismatch(): void {
		$current                                      = $this->manifest();
		$current['privileged_roles']['administrator'] = 'sha256:tampered';

		$report = Role_Audit::diff( $this->manifest(), $current );

		$this->assertTrue( $report['has_drift'] );
		$this->assertSame( 'sha256:expected', $report['roles']['administrator']['expected'] );
		$this->assertSame( 'sha256:tampered', $report['roles']['administrator']['actual'] );
	}

	public function test_diff_flags_missing_privileged_role(): void {
		// A watched privileged role that vanished from current state is drift.
		$current                     = $this->manifest();
		$current['privileged_roles'] = array(); // administrator role definition gone.

		$report = Role_Audit::diff( $this->manifest(), $current );

		$this->assertTrue( $report['has_drift'] );
		$this->assertArrayHasKey( 'administrator', $report['roles'] );
	}

	public function test_diff_ignores_current_role_not_watched_by_manifest(): void {
		// Manifest watches only 'administrator'; an extra role hash in current state
		// that the manifest does not declare privileged is not the audit's concern.
		$current                             = $this->manifest();
		$current['privileged_roles']['shop'] = 'sha256:whatever';

		$report = Role_Audit::diff( $this->manifest(), $current );

		$this->assertFalse( $report['has_drift'] );
	}

	// ---- default_watched_roles() (P1) + fresh, cache-bypassed reads (F3/F4) ----

	public function test_default_watched_roles_includes_every_current_role(): void {
		global $wpdb;
		$wpdb = \Mockery::mock();
		$wpdb->shouldReceive( 'get_blog_prefix' )->andReturn( 'wp_' );

		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\expect( 'get_option' )
			->once()
			->with( 'wp_user_roles', array() )
			->andReturn(
				array(
					'administrator' => array( 'capabilities' => array( 'manage_options' => true ) ),
					'editor'        => array( 'capabilities' => array( 'edit_others_posts' => true ) ),
					'subscriber'    => array( 'capabilities' => array( 'read' => true ) ),
				)
			);

		$this->assertSame(
			array(
				'administrator' => '',
				'editor'        => '',
				'subscriber'    => '',
			),
			Role_Audit::default_watched_roles(),
			'generate must watch every role, not just administrator (P1).'
		);
	}

	public function test_role_definitions_are_read_fresh_bypassing_the_object_cache(): void {
		global $wpdb;
		$wpdb = \Mockery::mock();
		$wpdb->shouldReceive( 'get_blog_prefix' )->andReturn( 'wp_' );

		$deleted = array();
		Functions\when( 'wp_cache_delete' )->alias(
			function ( $key, $group ) use ( &$deleted ) {
				$deleted[] = $key . '/' . $group;
				return true;
			}
		);
		Functions\expect( 'get_option' )->once()->with( 'wp_user_roles', array() )->andReturn( array() );

		Role_Audit::default_watched_roles();

		// Both the option's own key and the autoloaded alloptions blob must be
		// evicted so a raw {prefix}user_roles edit is actually re-read from the DB.
		$this->assertContains( 'wp_user_roles/options', $deleted );
		$this->assertContains( 'alloptions/options', $deleted );
	}

	public function test_collect_current_state_bypasses_the_user_query_cache(): void {
		global $wpdb;
		$wpdb = \Mockery::mock();
		$wpdb->shouldReceive( 'get_blog_prefix' )->andReturn( 'wp_' );

		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );

		// Every audit user query must disable the WP_User_Query cache so a raw
		// $wpdb capability grant is not hidden by a stale cached result (F4).
		Functions\expect( 'get_users' )
			->atLeast()
			->once()
			->andReturnUsing(
				function ( $args ) {
					$this->assertArrayHasKey( 'cache_results', $args, 'audit get_users() must set cache_results.' );
					$this->assertFalse( $args['cache_results'], 'audit get_users() must disable the query cache.' );
					return array();
				}
			);

		$this->assertIsArray( Role_Audit::collect_current_state( array( 'privileged_roles' => array() ) ) );
	}
}
