<?php
/**
 * Regression test: user account-mutation REST rules must gate POST.
 *
 * WordPress registers the users update route under WP_REST_Server::EDITABLE
 * ('POST, PUT, PATCH'). Rules that matched only PUT/PATCH were bypassed by a
 * cookie-authenticated POST /wp/v2/users/{id}, leaving password and role
 * changes ungated (no effect-level backstop covers account mutations). This
 * guards the fix so the gap cannot silently return.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Action_Registry;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers \WP_Sudo\Action_Registry
 */
class UserMutationRestMethodTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	public function test_user_mutation_rules_gate_post(): void {
		foreach ( array( 'user.change_password', 'user.promote' ) as $id ) {
			$rule = Action_Registry::find( $id );
			$this->assertNotNull( $rule, "$id should exist" );
			$this->assertContains( 'POST', $rule['rest']['methods'], "$id must gate POST (EDITABLE includes it)" );
		}
	}

	/**
	 * Core registers /wp/v2/users/me as EDITABLE → update_current_item() →
	 * update_item(), so a role change via POST /wp/v2/users/me must be gated too;
	 * user.promote's route regex must match /me, not only a numeric id.
	 */
	public function test_promote_rule_route_covers_me(): void {
		$rule = Action_Registry::find( 'user.promote' );
		$this->assertNotNull( $rule );
		$this->assertSame( 1, preg_match( $rule['rest']['route'], '/wp/v2/users/me' ), 'user.promote must match /wp/v2/users/me' );
		$this->assertSame( 1, preg_match( $rule['rest']['route'], '/wp/v2/users/2' ), 'user.promote must still match a numeric id' );
	}
}
