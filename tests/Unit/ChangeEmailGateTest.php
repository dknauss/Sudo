<?php
/**
 * Tests for the user.change_email gated rule.
 *
 * Closes the stolen-cookie pivot documented in
 * docs/stolen-cookie-rce-attack-tree.md: an attacker with a valid admin
 * session cookie changes an account email, triggers a password reset to their
 * inbox, and thereby manufactures a credential that passes every downstream
 * sudo challenge. Email change must itself be gated, on both the admin profile
 * forms and cookie-authenticated REST (including POST, which core's
 * WP_REST_Server::EDITABLE accepts alongside PUT/PATCH).
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
class ChangeEmailGateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// Identity email sanitizer; the rule normalizes case itself.
		Functions\when( 'sanitize_email' )->returnArg();
		unset( $GLOBALS['pagenow'] );
	}

	protected function tearDown(): void {
		unset( $_POST['email'], $_POST['user_id'], $_REQUEST['user_id'], $GLOBALS['pagenow'] );
		parent::tearDown();
	}

	/**
	 * Stub get_userdata so only $id resolves, to a user with $email.
	 */
	private function stub_user_email( int $id, string $email ): void {
		Functions\when( 'get_userdata' )->alias(
			static function ( $uid ) use ( $id, $email ) {
				return (int) $uid === $id ? (object) array( 'user_email' => $email ) : false;
			}
		);
	}

	/**
	 * Minimal WP_REST_Request stand-in exposing the two methods the rule reads.
	 */
	private function rest_request( array $params, string $route ) {
		return new class( $params, $route ) {
			/** @var array<string,mixed> */
			private $params;
			/** @var string */
			private $route;
			public function __construct( array $params, string $route ) {
				$this->params = $params;
				$this->route  = $route;
			}
			public function get_params(): array {
				return $this->params;
			}
			public function get_route(): string {
				return $this->route;
			}
		};
	}

	private function admin_callback() {
		return Action_Registry::find( 'user.change_email' )['admin']['callback'];
	}

	private function rest_callback() {
		return Action_Registry::find( 'user.change_email' )['rest']['callback'];
	}

	public function test_rule_exists_with_admin_and_rest_surfaces(): void {
		$rule = Action_Registry::find( 'user.change_email' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'users', $rule['category'] );
		$this->assertNotNull( $rule['admin']['callback'] );
		$this->assertNotNull( $rule['rest']['callback'] );
	}

	/**
	 * Core registers the users update route under WP_REST_Server::EDITABLE
	 * ('POST, PUT, PATCH'). A rule matching only PUT/PATCH is bypassed by POST,
	 * so the new change_email rule must gate POST. (The sibling password/role
	 * rules are covered by UserMutationRestMethodTest, shipped separately.)
	 */
	public function test_change_email_gates_post(): void {
		$rule = Action_Registry::find( 'user.change_email' );
		$this->assertNotNull( $rule );
		$this->assertContains( 'POST', $rule['rest']['methods'], 'change_email REST rule must gate POST (EDITABLE includes it)' );
	}

	public function test_admin_gates_email_change_on_own_profile(): void {
		$GLOBALS['pagenow'] = 'profile.php';
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$this->stub_user_email( 5, 'old@example.test' );
		$_POST['email'] = 'new@example.test';

		$this->assertTrue( call_user_func( $this->admin_callback() ) );
	}

	/**
	 * profile.php edits the current user regardless of any user_id param, so the
	 * rule must compare against the current user, not a spoofed user_id.
	 */
	public function test_admin_profile_ignores_spoofed_user_id(): void {
		$GLOBALS['pagenow']  = 'profile.php';
		$_REQUEST['user_id'] = '2'; // spoofed; core ignores it on profile.php
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$this->stub_user_email( 5, 'old@example.test' ); // user 2 is unknown here
		$_POST['email'] = 'new@example.test';

		// Compared against current user 5 (old@ vs new@) => gated.
		$this->assertTrue( call_user_func( $this->admin_callback() ) );
	}

	public function test_admin_gates_email_change_on_another_user(): void {
		$GLOBALS['pagenow']  = 'user-edit.php';
		$_REQUEST['user_id'] = '2';
		$this->stub_user_email( 2, 'victim@example.test' );
		$_POST['email'] = 'attacker@evil.test';

		$this->assertTrue( call_user_func( $this->admin_callback() ) );
	}

	/**
	 * Desync guard: on user-edit.php the target must come from $_REQUEST['user_id']
	 * (core uses $_REQUEST), even when it is only in the query string and absent
	 * from the POST body — otherwise the gate compares against the wrong user.
	 */
	public function test_admin_user_edit_resolves_target_from_request_not_post_body(): void {
		$GLOBALS['pagenow']  = 'user-edit.php';
		$_REQUEST['user_id'] = '2';        // victim in the query string only
		// deliberately no $_POST['user_id']
		Functions\when( 'get_current_user_id' )->justReturn( 1 ); // attacker
		$this->stub_user_email( 2, 'victim@example.test' );
		$_POST['email'] = 'attacker@evil.test';

		$this->assertTrue( call_user_func( $this->admin_callback() ) );
	}

	public function test_admin_ignores_unchanged_email(): void {
		$GLOBALS['pagenow'] = 'profile.php';
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$this->stub_user_email( 5, 'same@example.test' );
		$_POST['email'] = 'same@example.test';

		$this->assertFalse( call_user_func( $this->admin_callback() ) );
	}

	public function test_admin_ignores_case_only_email_difference(): void {
		$GLOBALS['pagenow'] = 'profile.php';
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$this->stub_user_email( 5, 'user@example.test' );
		$_POST['email'] = 'USER@Example.test';

		$this->assertFalse( call_user_func( $this->admin_callback() ) );
	}

	public function test_admin_ignores_save_without_email_field(): void {
		$GLOBALS['pagenow'] = 'profile.php';
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$this->stub_user_email( 5, 'user@example.test' );
		// Bio-only save: no email field submitted.

		$this->assertFalse( call_user_func( $this->admin_callback() ) );
	}

	public function test_admin_fails_closed_when_target_unreadable(): void {
		$GLOBALS['pagenow']  = 'user-edit.php';
		$_REQUEST['user_id'] = '999';
		$this->stub_user_email( 2, 'victim@example.test' ); // only id 2 is known
		$_POST['email'] = 'attacker@evil.test';

		$this->assertTrue( call_user_func( $this->admin_callback() ) );
	}

	public function test_rest_gates_email_change_on_user_id_route(): void {
		$this->stub_user_email( 2, 'victim@example.test' );
		$request = $this->rest_request( array( 'email' => 'attacker@evil.test' ), '/wp/v2/users/2' );

		$this->assertTrue( call_user_func( $this->rest_callback(), $request ) );
	}

	public function test_rest_gates_email_change_on_me_route(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$this->stub_user_email( 5, 'old@example.test' );
		$request = $this->rest_request( array( 'email' => 'new@example.test' ), '/wp/v2/users/me' );

		$this->assertTrue( call_user_func( $this->rest_callback(), $request ) );
	}

	public function test_rest_ignores_request_without_email(): void {
		$request = $this->rest_request( array( 'first_name' => 'X' ), '/wp/v2/users/2' );

		$this->assertFalse( call_user_func( $this->rest_callback(), $request ) );
	}

	/**
	 * Gated profile saves are non-replayable. Because the profile form always
	 * submits empty pass1/pass2 (which the stash redacts), a shared allowlist
	 * could never losslessly auto-replay; explicit non-replay avoids the silent
	 * field-drop the design targeted and is honest about the reauth-then-resubmit
	 * flow. All three rules that can win one profile submit must agree on this.
	 */
	public function test_profile_rules_are_non_replayable(): void {
		foreach ( array( 'user.change_email', 'user.change_password', 'user.promote_profile' ) as $id ) {
			$rule = Action_Registry::find( $id );
			$this->assertNotNull( $rule, "$id should exist" );
			$this->assertSame( 'none', $rule['stash']['post_mode'], "$id must be non-replayable (stash_no_replay)" );
		}
	}
}
