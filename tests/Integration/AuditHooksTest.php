<?php
/**
 * Integration tests for audit hook argument verification.
 *
 * Verifies that all do_action() hooks fire with the correct argument
 * values — user_id, timestamps, counts, role/capability names.
 *
 * REST gating hooks (wp_sudo_action_gated, wp_sudo_action_blocked for
 * rest_app_password surface) are argument-verified in RestGatingTest.
 * This file covers wp_sudo_action_blocked for non-interactive surfaces
 * (cli, cron, xmlrpc).
 *
 *
 * @covers \WP_Sudo\Sudo_Session
 * @covers \WP_Sudo\Plugin::enforce_editor_unfiltered_html
 * @covers \WP_Sudo\Gate::register_function_hooks
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Admin;
use WP_Sudo\Gate;
use WP_Sudo\Request_Stash;
use WP_Sudo\Sudo_Session;

class AuditHooksTest extends TestCase {

	/**
	 * Restore editor's unfiltered_html cap if a test stripped it.
	 */
	public function tear_down(): void {
		$editor = get_role( 'editor' );
		if ( $editor && empty( $editor->capabilities['unfiltered_html'] ) ) {
			$editor->add_cap( 'unfiltered_html' );
		}

		parent::tear_down();
	}

	/**
	 * SURF-04: wp_sudo_activated fires with (user_id, expires_timestamp, duration_minutes).
	 */
	public function test_activated_hook_receives_correct_arguments(): void {
		$user = $this->make_admin();

		$captured = array();
		add_action(
			'wp_sudo_activated',
			static function ( $uid, $expires, $duration ) use ( &$captured ) {
				$captured = array( $uid, $expires, $duration );
			},
			10,
			3
		);

		$before = time();
		Sudo_Session::activate( $user->ID );
		$after = time();

		$this->assertCount( 3, $captured, 'Hook should fire with 3 arguments.' );
		$this->assertSame( $user->ID, $captured[0], 'First arg should be user_id.' );

		// Expires should be approximately now + duration.
		$duration = (int) Admin::get( 'session_duration', 15 );
		$expected_min = $before + ( $duration * MINUTE_IN_SECONDS );
		$expected_max = $after + ( $duration * MINUTE_IN_SECONDS );
		$this->assertGreaterThanOrEqual( $expected_min, $captured[1] );
		$this->assertLessThanOrEqual( $expected_max, $captured[1] );

		$this->assertSame( $duration, $captured[2], 'Third arg should be duration in minutes.' );
	}

	/**
	 * SURF-04: wp_sudo_deactivated fires with (user_id).
	 */
	public function test_deactivated_hook_receives_user_id(): void {
		$user = $this->make_admin();
		Sudo_Session::activate( $user->ID );

		$captured_uid = null;
		add_action(
			'wp_sudo_deactivated',
			static function ( $uid ) use ( &$captured_uid ) {
				$captured_uid = $uid;
			}
		);

		Sudo_Session::deactivate( $user->ID );

		$this->assertSame( $user->ID, $captured_uid );
	}

	/**
	 * SURF-04: wp_sudo_reauth_failed fires with (user_id, incrementing_attempt_count).
	 *
	 * Two failed attempts → count goes 1 → 2.
	 */
	public function test_reauth_failed_hook_receives_user_id_and_attempt_count(): void {
		$user = $this->make_admin( 'correct-password' );
		wp_set_current_user( $user->ID );

		$captured_calls = array();
		add_action(
			'wp_sudo_reauth_failed',
			static function ( $uid, $attempts ) use ( &$captured_calls ) {
				$captured_calls[] = array( $uid, $attempts );
			},
			10,
			2
		);

		// Attempt 1: wrong password.
		Sudo_Session::attempt_activation( $user->ID, 'wrong-password' );
		// Attempt 2: wrong password again.
		Sudo_Session::attempt_activation( $user->ID, 'wrong-password' );

		$this->assertCount( 2, $captured_calls, 'Hook should fire twice.' );

		$this->assertSame( $user->ID, $captured_calls[0][0] );
		$this->assertSame( 1, $captured_calls[0][1], 'First attempt count should be 1.' );

		$this->assertSame( $user->ID, $captured_calls[1][0] );
		$this->assertSame( 2, $captured_calls[1][1], 'Second attempt count should be 2.' );
	}

	/**
	 * SURF-04: wp_sudo_lockout fires with (user_id, attempt_count=5) when
	 * the lockout threshold is reached.
	 *
	 * Pre-sets failure events to 4 via add_user_meta append rows,
	 * then one more attempt triggers lockout. Non-blocking model
	 * means no sleep() delay.
	 */
	public function test_lockout_hook_receives_user_id_and_count(): void {
		$user = $this->make_admin( 'correct-password' );
		wp_set_current_user( $user->ID );

		// Pre-set 4 failure event rows (just below lockout threshold).
		for ( $i = 0; $i < 4; $i++ ) {
			add_user_meta( $user->ID, Sudo_Session::FAILURE_EVENT_META_KEY, time(), false );
		}

		$captured = array();
		add_action(
			'wp_sudo_lockout',
			static function ( $uid, $attempts ) use ( &$captured ) {
				$captured = array( $uid, $attempts );
			},
			10,
			2
		);

		// Attempt 5: triggers lockout (no sleep — non-blocking model).
		Sudo_Session::attempt_activation( $user->ID, 'wrong-password' );

		$this->assertSame( $user->ID, $captured[0] ?? null, 'user_id should match.' );
		$this->assertSame( 5, $captured[1] ?? null, 'Attempt count should be 5.' );
	}

	/**
	 * SURF-04: wp_sudo_capability_tampered fires with ('editor', 'unfiltered_html').
	 *
	 * Skipped on multisite where enforce_editor_unfiltered_html() is a no-op.
	 */
	public function test_capability_tampered_hook_fires_with_role_and_cap(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'enforce_editor_unfiltered_html() is a no-op on multisite.' );
		}

		// Add unfiltered_html to editor (simulating DB tampering).
		$editor = get_role( 'editor' );
		$this->assertNotNull( $editor );
		$editor->add_cap( 'unfiltered_html' );

		$captured = array();
		add_action(
			'wp_sudo_capability_tampered',
			static function ( $role, $cap ) use ( &$captured ) {
				$captured = array( $role, $cap );
			},
			10,
			2
		);

		// Call the enforcement method.
		wp_sudo()->enforce_editor_unfiltered_html();

		$this->assertSame( 'editor', $captured[0] ?? null );
		$this->assertSame( 'unfiltered_html', $captured[1] ?? null );

		// Verify the capability was actually stripped.
		$editor = get_role( 'editor' );
		$this->assertEmpty(
			$editor->capabilities['unfiltered_html'] ?? false,
			'unfiltered_html should be stripped after enforcement.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// wp_sudo_action_allowed — non-interactive surfaces (Unrestricted)
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * wp_sudo_action_allowed fires with (0, 'plugin.activate', 'cli')
	 * when the CLI policy is 'unrestricted' and a gated action is triggered.
	 */
	public function test_action_allowed_hook_fires_for_cli_unrestricted(): void {
		$settings = $this->get_wp_sudo_option( Admin::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings[ Gate::SETTING_CLI_POLICY ] = Gate::POLICY_UNRESTRICTED;
		$this->update_wp_sudo_option( Admin::OPTION_KEY, $settings );
		Admin::reset_cache();

		$gate = new Gate( new Sudo_Session(), new Request_Stash() );
		$gate->gate_cli();

		$captured = array();
		add_action(
			'wp_sudo_action_allowed',
			static function ( $uid, $rule_id, $surface ) use ( &$captured ) {
				$captured = array( $uid, $rule_id, $surface );
			},
			10,
			3
		);

		// Trigger the gated action — should fire audit hook but NOT block.
		do_action( 'activate_plugin', 'hello.php', false );

		$this->assertSame( 0, $captured[0] ?? null, 'User ID should be 0 for CLI surface.' );
		$this->assertSame( 'plugin.activate', $captured[1] ?? null, 'Rule ID should be plugin.activate.' );
		$this->assertSame( 'cli', $captured[2] ?? null, 'Surface should be cli.' );
	}

	/**
	 * wp_sudo_action_allowed fires with (0, 'plugin.activate', 'xmlrpc')
	 * when the XML-RPC policy is 'unrestricted' and a gated action is triggered.
	 */
	public function test_action_allowed_hook_fires_for_xmlrpc_unrestricted(): void {
		$settings = $this->get_wp_sudo_option( Admin::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings[ Gate::SETTING_XMLRPC_POLICY ] = Gate::POLICY_UNRESTRICTED;
		$this->update_wp_sudo_option( Admin::OPTION_KEY, $settings );
		Admin::reset_cache();

		$gate = new Gate( new Sudo_Session(), new Request_Stash() );
		$gate->gate_xmlrpc();

		$captured = array();
		add_action(
			'wp_sudo_action_allowed',
			static function ( $uid, $rule_id, $surface ) use ( &$captured ) {
				$captured = array( $uid, $rule_id, $surface );
			},
			10,
			3
		);

		// Trigger the gated action — should fire audit hook but NOT block.
		do_action( 'activate_plugin', 'hello.php', false );

		$this->assertSame( 0, $captured[0] ?? null, 'User ID should be 0 for XML-RPC surface.' );
		$this->assertSame( 'plugin.activate', $captured[1] ?? null, 'Rule ID should be plugin.activate.' );
		$this->assertSame( 'xmlrpc', $captured[2] ?? null, 'Surface should be xmlrpc.' );
	}

	/**
	 * wp_sudo_action_allowed fires with (0, 'plugin.activate', 'cron')
	 * when the Cron policy is 'unrestricted' and a gated action is triggered.
	 *
	 * Unlike the Limited/blocked test, there is no exit() to intercept —
	 * the audit hook fires and the action proceeds normally.
	 */
	public function test_action_allowed_hook_fires_for_cron_unrestricted(): void {
		$settings = $this->get_wp_sudo_option( Admin::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings[ Gate::SETTING_CRON_POLICY ] = Gate::POLICY_UNRESTRICTED;
		$this->update_wp_sudo_option( Admin::OPTION_KEY, $settings );
		Admin::reset_cache();

		$gate = new Gate( new Sudo_Session(), new Request_Stash() );
		$gate->gate_cron();

		$captured = array();
		add_action(
			'wp_sudo_action_allowed',
			static function ( $uid, $rule_id, $surface ) use ( &$captured ) {
				$captured = array( $uid, $rule_id, $surface );
			},
			10,
			3
		);

		// Trigger the gated action — should fire audit hook but NOT block/exit.
		do_action( 'activate_plugin', 'hello.php', false );

		$this->assertSame( 0, $captured[0] ?? null, 'User ID should be 0 for Cron surface.' );
		$this->assertSame( 'plugin.activate', $captured[1] ?? null, 'Rule ID should be plugin.activate.' );
		$this->assertSame( 'cron', $captured[2] ?? null, 'Surface should be cron.' );
	}

	// ─────────────────────────────────────────────────────────────────────
	// wp_sudo_action_blocked — non-interactive surfaces (CLI, Cron, XML-RPC)
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * SURF-04: wp_sudo_action_blocked fires with (0, 'plugin.activate', 'cli')
	 * when the CLI policy is 'limited' and a gated action is triggered.
	 */
	public function test_action_blocked_hook_fires_for_cli_surface(): void {
		// Arrange: set CLI policy to 'limited'.
		$settings = $this->get_wp_sudo_option( Admin::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings[ Gate::SETTING_CLI_POLICY ] = Gate::POLICY_LIMITED;
		$this->update_wp_sudo_option( Admin::OPTION_KEY, $settings );
		Admin::reset_cache();

		$gate = new Gate( new Sudo_Session(), new Request_Stash() );
		$gate->gate_cli();

		$captured = array();
		add_action(
			'wp_sudo_action_blocked',
			static function ( $uid, $rule_id, $surface ) use ( &$captured ) {
				$captured = array( $uid, $rule_id, $surface );
			},
			10,
			3
		);

		// Act: trigger the activate_plugin action (fires block closure).
		try {
			do_action( 'activate_plugin', 'hello.php', false );
		} catch ( \WPDieException $e ) {
			// Expected: wp_die() is called after the hook fires for CLI.
			$this->addToAssertionCount( 1 );
		}

		// Assert hook args: user_id=0 (non-interactive), rule_id, surface.
		$this->assertSame( 0, $captured[0] ?? null, 'User ID should be 0 for CLI surface.' );
		$this->assertSame( 'plugin.activate', $captured[1] ?? null, 'Rule ID should be plugin.activate.' );
		$this->assertSame( 'cli', $captured[2] ?? null, 'Surface should be cli.' );
	}

	/**
	 * SURF-04: wp_sudo_action_blocked fires with (0, 'plugin.activate', 'xmlrpc')
	 * when the XML-RPC policy is 'limited' and a gated action is triggered.
	 */
	public function test_action_blocked_hook_fires_for_xmlrpc_surface(): void {
		// Arrange: set XML-RPC policy to 'limited'.
		$settings = $this->get_wp_sudo_option( Admin::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings[ Gate::SETTING_XMLRPC_POLICY ] = Gate::POLICY_LIMITED;
		$this->update_wp_sudo_option( Admin::OPTION_KEY, $settings );
		Admin::reset_cache();

		$gate = new Gate( new Sudo_Session(), new Request_Stash() );
		$gate->gate_xmlrpc();

		$captured = array();
		add_action(
			'wp_sudo_action_blocked',
			static function ( $uid, $rule_id, $surface ) use ( &$captured ) {
				$captured = array( $uid, $rule_id, $surface );
			},
			10,
			3
		);

		// Act: trigger the activate_plugin action (fires block closure).
		try {
			do_action( 'activate_plugin', 'hello.php', false );
		} catch ( \WPDieException $e ) {
			// Expected: wp_die() is called after the hook fires for XML-RPC.
			$this->addToAssertionCount( 1 );
		}

		// Assert hook args: user_id=0 (non-interactive), rule_id, surface.
		$this->assertSame( 0, $captured[0] ?? null, 'User ID should be 0 for XML-RPC surface.' );
		$this->assertSame( 'plugin.activate', $captured[1] ?? null, 'Rule ID should be plugin.activate.' );
		$this->assertSame( 'xmlrpc', $captured[2] ?? null, 'Surface should be xmlrpc.' );
	}

	/**
	 * SURF-04: wp_sudo_action_blocked fires with (0, 'plugin.activate', 'cron')
	 * when the Cron policy is 'limited' and a gated action is triggered.
	 *
	 * Strategy: a priority-9 listener on wp_sudo_action_blocked captures args
	 * and throws an exception to prevent the block closure from reaching exit().
	 * The cron block fires the hook at priority 10 then calls exit; intercepting
	 * at priority 9 stops execution before exit without terminating the process.
	 */
	public function test_action_blocked_hook_fires_for_cron_surface(): void {
		// Arrange: set Cron policy to 'limited'.
		$settings = $this->get_wp_sudo_option( Admin::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings[ Gate::SETTING_CRON_POLICY ] = Gate::POLICY_LIMITED;
		$this->update_wp_sudo_option( Admin::OPTION_KEY, $settings );
		Admin::reset_cache();

		$gate = new Gate( new Sudo_Session(), new Request_Stash() );
		$gate->gate_cron();

		$captured = array();

		// Priority 9 fires inside the block closure's do_action() call, before exit().
		// Throwing here unwinds the stack and prevents exit from terminating the process.
		add_action(
			'wp_sudo_action_blocked',
			static function ( $uid, $rule_id, $surface ) use ( &$captured ) {
				$captured = array( $uid, $rule_id, $surface );
				throw new \RuntimeException( 'Caught before cron exit.' );
			},
			9,
			3
		);

		// Act: trigger the activate_plugin action (fires block closure at priority 0).
		try {
			do_action( 'activate_plugin', 'hello.php', false );
		} catch ( \RuntimeException $e ) {
			// Expected: our priority-9 listener threw before exit() was reached.
			$this->addToAssertionCount( 1 );
		}

		// Assert: hook args captured before exit().
		$this->assertSame( 0, $captured[0] ?? null, 'User ID should be 0 for Cron surface.' );
		$this->assertSame( 'plugin.activate', $captured[1] ?? null, 'Rule ID should be plugin.activate.' );
		$this->assertSame( 'cron', $captured[2] ?? null, 'Surface should be cron.' );
	}
}
