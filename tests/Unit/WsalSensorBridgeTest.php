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
			'wp_sudo_action_replayed',
			'wp_sudo_capability_tampered',
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
