<?php
/**
 * Tests for the WSAL sensor bridge.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo\Tests\Unit;

use Brain\Monkey\Functions;
use WP_Sudo\Tests\TestCase;

/**
 * @coversNothing Procedural bridge file.
 */
class WsalSensorBridgeTest extends TestCase {

	/**
	 * Test bridge stays inert when WSAL APIs are unavailable.
	 */
	public function test_01_bridge_is_inert_when_wsal_unavailable(): void {

		// The Alert_Manager stub eval-defined by the other tests in this
		// file is process-global, so hide it from the bridge's availability
		// check instead of relying on the class being absent.
		\Patchwork\redefine(
			'class_exists',
			function ( string $class_name, bool $autoload = true ): bool {
				return false !== strpos( $class_name, 'Alert_Manager' ) ? false : \Patchwork\relay();
			}
		);

		$registered_hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, callable $callback ) use ( &$registered_hooks ): bool {
				$registered_hooks[] = $hook;
				return true;
			}
		);

		include __DIR__ . '/../../bridges/wp-sudo-wsal-sensor.php';

		$this->assertSame( array(), $registered_hooks );
	}

	/**
	 * Test bridge registers listeners for WP Sudo audit hooks when WSAL is available.
	 */
	public function test_02_bridge_registers_expected_listeners_when_wsal_available(): void {
		$this->define_wsal_alert_manager_stub();

		$callbacks = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, callable $callback ) use ( &$callbacks ): bool {
				$callbacks[ $hook ] = $callback;
				return true;
			}
		);

		include __DIR__ . '/../../bridges/wp-sudo-wsal-sensor.php';

		$expected = array(
			'wp_sudo_activated',
			'wp_sudo_deactivated',
			'wp_sudo_reauth_failed',
			'wp_sudo_lockout',
			'wp_sudo_action_gated',
			'wp_sudo_action_blocked',
			'wp_sudo_action_allowed',
			'wp_sudo_action_passed',
			'wp_sudo_action_replayed',
			'wp_sudo_capability_tampered',
			'wp_sudo_policy_preset_applied',
		);

		foreach ( $expected as $hook ) {
			$this->assertArrayHasKey( $hook, $callbacks );
		}
	}

	/**
	 * Test hook payloads map into structured WSAL event data.
	 */
	public function test_03_bridge_maps_hook_payload_to_structured_wsal_event_data(): void {
		$this->define_wsal_alert_manager_stub();

		$callbacks = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, callable $callback ) use ( &$callbacks ): bool {
				$callbacks[ $hook ] = $callback;
				return true;
			}
		);

		include __DIR__ . '/../../bridges/wp-sudo-wsal-sensor.php';

		$this->assertArrayHasKey( 'wp_sudo_action_blocked', $callbacks );

		$callbacks['wp_sudo_action_blocked']( 42, 'plugin.activate', 'cli' );

		$this->assertNotEmpty( \WSAL\Controllers\Alert_Manager::$events );

		$event = \WSAL\Controllers\Alert_Manager::$events[0];
		$this->assertSame( 1900006, $event[0] );
		$this->assertSame( 'wp-sudo', $event[1]['source'] ?? null );
		$this->assertSame( 'wp_sudo_action_blocked', $event[1]['hook'] ?? null );
		$this->assertSame( 42, $event[1]['user_id'] ?? null );
		$this->assertSame( 'plugin.activate', $event[1]['rule_id'] ?? null );
		$this->assertSame( 'cli', $event[1]['surface'] ?? null );
	}

	/**
	 * Test bridge callbacks preserve original WP Sudo hook args/flow.
	 */
	public function test_04_bridge_callbacks_are_pass_through_and_do_not_mutate_args(): void {
		$this->define_wsal_alert_manager_stub();

		$callbacks = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, callable $callback ) use ( &$callbacks ): bool {
				$callbacks[ $hook ] = $callback;
				return true;
			}
		);

		include __DIR__ . '/../../bridges/wp-sudo-wsal-sensor.php';

		$user_id = 7;
		$rule_id = 'plugin.delete';
		$surface = 'ajax';

		$result = $callbacks['wp_sudo_action_allowed']( $user_id, $rule_id, $surface );

		$this->assertNull( $result );
		$this->assertSame( 7, $user_id );
		$this->assertSame( 'plugin.delete', $rule_id );
		$this->assertSame( 'ajax', $surface );
	}

	/**
	 * Test preset application payloads map into a dedicated WSAL event.
	 */
	public function test_05_bridge_maps_policy_preset_payload_to_structured_wsal_event_data(): void {
		$this->define_wsal_alert_manager_stub();

		$callbacks = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, callable $callback ) use ( &$callbacks ): bool {
				$callbacks[ $hook ] = $callback;
				return true;
			}
		);

		include __DIR__ . '/../../bridges/wp-sudo-wsal-sensor.php';

		$this->assertArrayHasKey( 'wp_sudo_policy_preset_applied', $callbacks );

		$callbacks['wp_sudo_policy_preset_applied'](
			7,
			'incident_lockdown',
			array( 'cli_policy' => 'limited' ),
			array( 'cli_policy' => 'disabled' ),
			true
		);

		$this->assertNotEmpty( \WSAL\Controllers\Alert_Manager::$events );

		$event = \WSAL\Controllers\Alert_Manager::$events[0];
		$this->assertSame( 1900011, $event[0] );
		$this->assertSame( 'wp_sudo_policy_preset_applied', $event[1]['hook'] ?? null );
		$this->assertSame( 7, $event[1]['user_id'] ?? null );
		$this->assertSame( 'incident_lockdown', $event[1]['preset_key'] ?? null );
		$this->assertSame( 'limited', $event[1]['previous']['cli_policy'] ?? null );
		$this->assertSame( 'disabled', $event[1]['current']['cli_policy'] ?? null );
		$this->assertTrue( $event[1]['is_network'] ?? false );
	}

	/**
	 * Test passed action payloads use the documented WSAL event ID.
	 */
	public function test_06_bridge_maps_action_passed_to_documented_wsal_event_id(): void {
		$this->define_wsal_alert_manager_stub();

		$callbacks = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, callable $callback ) use ( &$callbacks ): bool {
				$callbacks[ $hook ] = $callback;
				return true;
			}
		);

		include __DIR__ . '/../../bridges/wp-sudo-wsal-sensor.php';

		$this->assertArrayHasKey( 'wp_sudo_action_passed', $callbacks );

		$callbacks['wp_sudo_action_passed']( 42, 'plugin.activate', 'admin' );

		$this->assertNotEmpty( \WSAL\Controllers\Alert_Manager::$events );

		$event = \WSAL\Controllers\Alert_Manager::$events[0];
		$this->assertSame( 1900008, $event[0] );
		$this->assertSame( 'wp_sudo_action_passed', $event[1]['hook'] ?? null );
		$this->assertSame( 42, $event[1]['user_id'] ?? null );
		$this->assertSame( 'plugin.activate', $event[1]['rule_id'] ?? null );
		$this->assertSame( 'admin', $event[1]['surface'] ?? null );
	}

	/**
	 * Test bridge registers listeners for the v1.1.0 security hooks.
	 */
	public function test_07_bridge_registers_security_hook_listeners(): void {
		$this->define_wsal_alert_manager_stub();

		$callbacks = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, callable $callback ) use ( &$callbacks ): bool {
				$callbacks[ $hook ] = $callback;
				return true;
			}
		);

		include __DIR__ . '/../../bridges/wp-sudo-wsal-sensor.php';

		$expected = array(
			'wp_sudo_escalation_blocked',
			'wp_sudo_session_revoked',
			'wp_sudo_recovery_mode_active',
			'wp_sudo_capability_granted',
			'wp_sudo_capability_revoked',
			'wp_sudo_gated_actions_missing_builtin_rules',
			'wp_sudo_rule_regex_error',
		);

		foreach ( $expected as $hook ) {
			$this->assertArrayHasKey( $hook, $callbacks );
		}
	}

	/**
	 * Test escalation-blocked payload maps to its dedicated WSAL event.
	 */
	public function test_08_bridge_maps_escalation_blocked_payload(): void {
		$callbacks = $this->include_bridge_with_captured_callbacks();

		$callbacks['wp_sudo_escalation_blocked']( 99, 'user.super_admin', 'admin' );

		$event = \WSAL\Controllers\Alert_Manager::$events[0];
		$this->assertSame( 1900012, $event[0] );
		$this->assertSame( 'wp_sudo_escalation_blocked', $event[1]['hook'] ?? null );
		$this->assertSame( 99, $event[1]['user_id'] ?? null );
		$this->assertSame( 'user.super_admin', $event[1]['rule_id'] ?? null );
		$this->assertSame( 'admin', $event[1]['surface'] ?? null );
	}

	/**
	 * Test session-revoked payload carries actor and blog context.
	 */
	public function test_09_bridge_maps_session_revoked_payload(): void {
		$callbacks = $this->include_bridge_with_captured_callbacks();

		$callbacks['wp_sudo_session_revoked']( 42, 7, 'admin_revoked', 3 );

		$event = \WSAL\Controllers\Alert_Manager::$events[0];
		$this->assertSame( 1900013, $event[0] );
		$this->assertSame( 42, $event[1]['user_id'] ?? null );
		$this->assertSame( 7, $event[1]['revoker_id'] ?? null );
		$this->assertSame( 'admin_revoked', $event[1]['reason'] ?? null );
		$this->assertSame( 3, $event[1]['blog_id'] ?? null );
	}

	/**
	 * Test capability grant/revoke payloads carry actor and blog context.
	 */
	public function test_10_bridge_maps_capability_change_payloads(): void {
		$callbacks = $this->include_bridge_with_captured_callbacks();

		$callbacks['wp_sudo_capability_granted']( 42, 'manage_options', 7, 2 );
		$callbacks['wp_sudo_capability_revoked']( 42, 'manage_options', 7, 2 );

		$granted = \WSAL\Controllers\Alert_Manager::$events[0];
		$this->assertSame( 1900015, $granted[0] );
		$this->assertSame( 42, $granted[1]['user_id'] ?? null );
		$this->assertSame( 'manage_options', $granted[1]['capability'] ?? null );
		$this->assertSame( 7, $granted[1]['actor_id'] ?? null );
		$this->assertSame( 2, $granted[1]['blog_id'] ?? null );

		$revoked = \WSAL\Controllers\Alert_Manager::$events[1];
		$this->assertSame( 1900016, $revoked[0] );
		$this->assertSame( 'manage_options', $revoked[1]['capability'] ?? null );
	}

	/**
	 * Test missing-builtin-rules payload normalises its array arg to strings.
	 */
	public function test_11_bridge_maps_missing_builtin_rules_payload(): void {
		$callbacks = $this->include_bridge_with_captured_callbacks();

		$callbacks['wp_sudo_gated_actions_missing_builtin_rules']( array( 'plugin.activate', 'user.delete' ) );

		$event = \WSAL\Controllers\Alert_Manager::$events[0];
		$this->assertSame( 1900017, $event[0] );
		$this->assertSame( array( 'plugin.activate', 'user.delete' ), $event[1]['missing'] ?? null );
	}

	/**
	 * Test regex-error payload preserves the fail-closed disposition.
	 */
	public function test_12_bridge_maps_rule_regex_error_payload(): void {
		$callbacks = $this->include_bridge_with_captured_callbacks();

		$callbacks['wp_sudo_rule_regex_error']( '/[/', 'options-general.php', false );

		$event = \WSAL\Controllers\Alert_Manager::$events[0];
		$this->assertSame( 1900018, $event[0] );
		$this->assertSame( '/[/', $event[1]['pattern'] ?? null );
		$this->assertSame( 'options-general.php', $event[1]['subject'] ?? null );
		$this->assertFalse( $event[1]['fail_closed'] ?? null );
	}

	/**
	 * Test recovery-mode usage emits once then throttles within the window.
	 */
	public function test_13_bridge_throttles_recovery_mode_per_user(): void {
		$set_keys = array();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value, int $ttl ) use ( &$set_keys ): bool {
				$set_keys[] = array( $key, $ttl );
				return true;
			}
		);

		$callbacks = $this->include_bridge_with_captured_callbacks();

		$callbacks['wp_sudo_recovery_mode_active']( 21 );

		$event = \WSAL\Controllers\Alert_Manager::$events[0];
		$this->assertSame( 1900014, $event[0] );
		$this->assertSame( 21, $event[1]['user_id'] ?? null );
		$this->assertNotEmpty( $set_keys, 'First fire must set a throttle transient' );
		$this->assertSame( HOUR_IN_SECONDS, $set_keys[0][1] );
	}

	/**
	 * Test recovery-mode emission is suppressed while the throttle is set.
	 */
	public function test_14_bridge_suppresses_throttled_recovery_mode(): void {
		Functions\when( 'get_transient' )->justReturn( 1 );
		Functions\expect( 'set_transient' )->never();

		$callbacks = $this->include_bridge_with_captured_callbacks();

		$callbacks['wp_sudo_recovery_mode_active']( 21 );

		$this->assertSame( array(), \WSAL\Controllers\Alert_Manager::$events, 'Throttled recovery fire must not emit' );
	}

	/**
	 * Include the bridge with WSAL available and capture its hook callbacks.
	 *
	 * @return array<string, callable>
	 */
	private function include_bridge_with_captured_callbacks(): array {
		$this->define_wsal_alert_manager_stub();

		$callbacks = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, callable $callback ) use ( &$callbacks ): bool {
				$callbacks[ $hook ] = $callback;
				return true;
			}
		);

		include __DIR__ . '/../../bridges/wp-sudo-wsal-sensor.php';

		return $callbacks;
	}

	/**
	 * Define a lightweight WSAL Alert Manager class for bridge tests.
	 *
	 * @return void
	 */
	private function define_wsal_alert_manager_stub(): void {
		if ( ! class_exists( '\WSAL\Controllers\Alert_Manager', false ) ) {
			// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
			eval( 'namespace WSAL\Controllers; class Alert_Manager { public static array $events = []; public static function trigger_event( int $event_id, array $payload ): void { self::$events[] = [$event_id, $payload]; } }' );
		}

		\WSAL\Controllers\Alert_Manager::$events = array();
	}
}
