<?php
/**
 * Integration tests for non-interactive function-level gating (review finding B-CU2).
 *
 * Gate::register_function_hooks() (includes/class-gate.php:356) maps gated
 * operations onto WordPress function-level hooks so CLI/Cron/XML-RPC actors
 * cannot perform them under the Limited policy. Prior to this file only the
 * plugin.activate interception had integration coverage (AuditHooksTest).
 *
 * This file pins the highest-value uncovered interceptions on the CLI
 * surface, where the block closure calls wp_die() — converted to
 * WPDieException by the WP test suite — so each block is observable
 * without terminating the PHP process:
 *
 * - user.promote   via add_user_metadata / update_user_metadata filters
 *                  (includes/class-gate.php:514-524, verified) keyed on
 *                  is_user_capabilities_meta_key()
 *                  (includes/class-gate.php:593-615, verified).
 * - plugin.deactivate via the pre_update_option_active_plugins
 *                  count-comparison closure
 *                  (includes/class-gate.php:417-430, verified).
 * - user.create    via the wp_pre_insert_user_data closure
 *                  (includes/class-gate.php:493-509, verified), which only
 *                  fires for NEW logins (core omits user_login from $data on
 *                  updates — .tmp/wordpress/wp-includes/user.php:2506-2543,
 *                  verified — and the closure double-checks get_user_by()).
 *
 * Policy plumbing matches AuditHooksTest: settings stored under
 * Admin::OPTION_KEY (includes/class-admin.php:35, verified) with key
 * Gate::SETTING_CLI_POLICY (includes/class-gate.php:65, verified); gate
 * armed by Gate::gate_cli() (includes/class-gate.php:218-241, verified).
 *
 * @covers \WP_Sudo\Gate::register_function_hooks
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Admin;
use WP_Sudo\Gate;
use WP_Sudo\Request_Stash;
use WP_Sudo\Sudo_Session;

class NonInteractiveGatingTest extends TestCase {

	/**
	 * Set the CLI policy and arm the Gate's function-level hooks.
	 *
	 * Mirrors the arrangement used by AuditHooksTest
	 * (tests/Integration/AuditHooksTest.php:319-330, verified): write the
	 * policy into the settings option BEFORE constructing the Gate, reset
	 * the Admin settings cache, then call gate_cli() which registers the
	 * block ('limited') or audit ('unrestricted') closures via
	 * register_function_hooks( 'cli', ... ).
	 *
	 * Settings must be written before arming: register_function_hooks()
	 * also gates pre_update_option_wp_sudo_settings
	 * (includes/class-gate.php:548-558, verified), so a later settings
	 * write inside the same test would itself be intercepted.
	 *
	 * @param string $policy Gate::POLICY_LIMITED or Gate::POLICY_UNRESTRICTED.
	 */
	private function arm_cli_gate( string $policy ): void {
		$settings = $this->get_wp_sudo_option( Admin::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings[ Gate::SETTING_CLI_POLICY ] = $policy;
		$this->update_wp_sudo_option( Admin::OPTION_KEY, $settings );
		Admin::reset_cache();

		$gate = new Gate( new Sudo_Session(), new Request_Stash() );
		$gate->gate_cli();
	}

	/**
	 * Capture wp_sudo_action_blocked invocations.
	 *
	 * The block closure fires the hook BEFORE wp_die()
	 * (includes/class-gate.php:382-400, verified), so the capture happens
	 * even though the closure subsequently throws WPDieException under the
	 * test suite's wp_die handler.
	 *
	 * @param array $captured Reference receiving array{0:int,1:string,2:string} tuples.
	 */
	private function capture_blocked_hook( array &$captured ): void {
		add_action(
			'wp_sudo_action_blocked',
			static function ( $uid, $rule_id, $surface ) use ( &$captured ) {
				$captured[] = array( $uid, $rule_id, $surface );
			},
			10,
			3
		);
	}

	/**
	 * B-CU2: a capabilities user-meta write is blocked under CLI Limited.
	 *
	 * Pins the user.promote interception at the metadata layer:
	 * register_function_hooks() adds the same closure to add_user_metadata
	 * and update_user_metadata at priority 0
	 * (includes/class-gate.php:514-524, verified). The closure matches the
	 * site-prefixed capabilities key via is_user_capabilities_meta_key(),
	 * which includes $wpdb->get_blog_prefix() . 'capabilities'
	 * (includes/class-gate.php:599-601, verified), then fires
	 * wp_sudo_action_blocked( 0, 'user.promote', 'cli' ) and wp_die()s.
	 *
	 * Core fires the update_user_metadata filter before any DB write
	 * (.tmp/wordpress/wp-includes/meta.php:250, verified), so the thrown
	 * WPDieException aborts the write and the stored meta is unchanged.
	 */
	public function test_capabilities_meta_write_blocked_under_cli_limited(): void {
		global $wpdb;

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$cap_key = $wpdb->get_blog_prefix() . 'capabilities';

		$before = get_user_meta( $user_id, $cap_key, true );
		$this->assertIsArray( $before, 'Sanity: factory user must have capabilities meta.' );
		$this->assertArrayHasKey( 'subscriber', $before, 'Sanity: factory user starts as subscriber.' );

		$this->arm_cli_gate( Gate::POLICY_LIMITED );

		$captured = array();
		$this->capture_blocked_hook( $captured );

		try {
			update_user_meta( $user_id, $cap_key, array( 'administrator' => true ) );
			$this->fail( 'Expected WPDieException: capabilities meta write must be blocked on CLI.' );
		} catch ( \WPDieException $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertSame(
			array( array( 0, 'user.promote', 'cli' ) ),
			$captured,
			'Blocked hook should fire once with (0, user.promote, cli).'
		);

		$this->assertSame(
			$before,
			get_user_meta( $user_id, $cap_key, true ),
			'Capabilities meta must be unchanged after the blocked write.'
		);
	}

	/**
	 * B-CU2: shrinking active_plugins (deactivation) is blocked under CLI Limited.
	 *
	 * Pins the plugin.deactivate heuristic: no generic deactivation hook
	 * exists, so the Gate filters pre_update_option_active_plugins and
	 * blocks only when count(new) < count(old)
	 * (includes/class-gate.php:417-430, verified). The pre_update filter
	 * runs before the value compare and DB write
	 * (.tmp/wordpress/wp-includes/option.php:899, verified), so the thrown
	 * WPDieException preserves the stored option value.
	 */
	public function test_shrinking_active_plugins_blocked_under_cli_limited(): void {
		$original = array( 'hello.php', 'akismet/akismet.php' );
		update_option( 'active_plugins', $original );

		$this->arm_cli_gate( Gate::POLICY_LIMITED );

		$captured = array();
		$this->capture_blocked_hook( $captured );

		try {
			update_option( 'active_plugins', array( 'hello.php' ) );
			$this->fail( 'Expected WPDieException: shrinking active_plugins must be blocked on CLI.' );
		} catch ( \WPDieException $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertSame(
			array( array( 0, 'plugin.deactivate', 'cli' ) ),
			$captured,
			'Blocked hook should fire once with (0, plugin.deactivate, cli).'
		);

		$this->assertSame(
			$original,
			get_option( 'active_plugins' ),
			'active_plugins must keep its old value after the blocked deactivation.'
		);
	}

	/**
	 * B-CU2 control: growing active_plugins is NOT blocked by the
	 * deactivation heuristic under CLI Limited.
	 *
	 * Pins the count comparison in the pre_update_option_active_plugins
	 * closure (includes/class-gate.php:421-423, verified): the
	 * plugin.deactivate callback only fires when the list shrinks, so an
	 * addition passes through and the option is persisted.
	 *
	 * (Activation via activate_plugin() is separately gated by the
	 * 'activate_plugin' action — includes/class-gate.php:406-412, verified,
	 * covered in AuditHooksTest — but a raw option write that grows the
	 * list is intentionally outside the deactivation rule.)
	 */
	public function test_growing_active_plugins_not_blocked_under_cli_limited(): void {
		update_option( 'active_plugins', array( 'hello.php' ) );

		$this->arm_cli_gate( Gate::POLICY_LIMITED );

		$captured = array();
		$this->capture_blocked_hook( $captured );

		$grown  = array( 'hello.php', 'akismet/akismet.php' );
		$result = update_option( 'active_plugins', $grown );

		$this->assertTrue( $result, 'Growing the active_plugins list should persist.' );
		$this->assertSame( $grown, get_option( 'active_plugins' ) );
		$this->assertSame( array(), $captured, 'No block hook should fire for a growing list.' );
	}

	/**
	 * B-CU2: creating a NEW user is blocked under CLI Limited.
	 *
	 * Pins the user.create interception: the Gate filters
	 * wp_pre_insert_user_data at priority 0 and blocks when $data carries a
	 * user_login that does not resolve via get_user_by( 'login', ... )
	 * (includes/class-gate.php:493-509, verified). Core applies this filter
	 * before the users-table insert
	 * (.tmp/wordpress/wp-includes/user.php:2543, verified), so the thrown
	 * WPDieException prevents the row from being created.
	 */
	public function test_new_user_insert_blocked_under_cli_limited(): void {
		$this->arm_cli_gate( Gate::POLICY_LIMITED );

		$captured = array();
		$this->capture_blocked_hook( $captured );

		try {
			wp_insert_user(
				array(
					'user_login' => 'wp-sudo-blocked-login',
					'user_pass'  => 'secret-password-123',
					'user_email' => 'blocked@example.org',
				)
			);
			$this->fail( 'Expected WPDieException: new user creation must be blocked on CLI.' );
		} catch ( \WPDieException $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertSame(
			array( array( 0, 'user.create', 'cli' ) ),
			$captured,
			'Blocked hook should fire once with (0, user.create, cli).'
		);

		$this->assertFalse(
			get_user_by( 'login', 'wp-sudo-blocked-login' ),
			'No user row may exist after the blocked insert.'
		);
	}

	/**
	 * B-CU2 control: updating an EXISTING user is NOT blocked under CLI Limited.
	 *
	 * Pins the create-vs-update distinction in the wp_pre_insert_user_data
	 * closure (includes/class-gate.php:499-505, verified). On the update
	 * path core omits user_login from the filtered $data array
	 * (.tmp/wordpress/wp-includes/user.php:2506-2510, verified:
	 * "if ( ! $update ) { $data = $data + compact( 'user_login' ); }"), so
	 * the closure's ! empty( $data['user_login'] ) guard skips the block
	 * and the update proceeds. A display_name change avoids the separately
	 * gated capabilities meta path.
	 */
	public function test_existing_user_update_not_blocked_under_cli_limited(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->arm_cli_gate( Gate::POLICY_LIMITED );

		$captured = array();
		$this->capture_blocked_hook( $captured );

		$result = wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => 'Updated Display Name',
			)
		);

		$this->assertSame( $user_id, $result, 'wp_update_user should return the user ID, not WP_Error.' );
		$this->assertSame(
			'Updated Display Name',
			get_userdata( $user_id )->display_name,
			'The update must be persisted.'
		);
		$this->assertSame( array(), $captured, 'No block hook should fire for an existing-user update.' );
	}

	/**
	 * B-CU2 allowed control: a role change proceeds under CLI Unrestricted.
	 *
	 * Under Unrestricted, gate_cli() registers the audit-mode callback
	 * (includes/class-gate.php:234-237 and 357-370, verified), which fires
	 * wp_sudo_action_allowed( 0, $rule_id, 'cli' ) and returns $check
	 * unchanged so the metadata write proceeds. WP_User::set_role() writes
	 * the site-prefixed capabilities meta via update_user_meta()
	 * (.tmp/wordpress/wp-includes/class-wp-user.php:617-635, verified),
	 * which routes through the same update_user_metadata filter the
	 * Limited path uses to block.
	 */
	public function test_role_change_allowed_under_cli_unrestricted(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->arm_cli_gate( Gate::POLICY_UNRESTRICTED );

		$allowed = array();
		add_action(
			'wp_sudo_action_allowed',
			static function ( $uid, $rule_id, $surface ) use ( &$allowed ) {
				$allowed[] = array( $uid, $rule_id, $surface );
			},
			10,
			3
		);

		$blocked = array();
		$this->capture_blocked_hook( $blocked );

		$user = get_user_by( 'id', $user_id );
		$user->set_role( 'editor' );

		$this->assertContains(
			array( 0, 'user.promote', 'cli' ),
			$allowed,
			'Allowed hook should fire with (0, user.promote, cli) under Unrestricted.'
		);
		$this->assertSame( array(), $blocked, 'No block hook may fire under Unrestricted.' );

		clean_user_cache( $user_id );
		$fresh = get_userdata( $user_id );
		$this->assertContains( 'editor', $fresh->roles, 'Role change must be persisted.' );
		$this->assertNotContains( 'subscriber', $fresh->roles, 'Old role must be removed.' );
	}
}
