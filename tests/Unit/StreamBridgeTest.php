<?php
/**
 * Tests for the Stream bridge.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo\Tests\Unit;

use Brain\Monkey\Functions;
use WP_Sudo\Tests\TestCase;

/**
 * @coversNothing Procedural bridge file.
 */
class StreamBridgeTest extends TestCase {

	/**
	 * Test bridge defers hook registration when Stream APIs are unavailable.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_01_bridge_defers_when_stream_unavailable(): void {
		\Brain\Monkey\setUp();

		$callbacks = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ) use ( &$callbacks ): bool {
				$callbacks[ $hook ][] = array(
					'callback'      => $callback,
					'priority'      => $priority,
					'accepted_args' => $accepted_args,
				);
				return true;
			}
		);

		include __DIR__ . '/../../bridges/wp-sudo-stream-bridge.php';

		$this->assertArrayHasKey( 'plugins_loaded', $callbacks );
		$this->assertCount( 1, $callbacks );

		\Brain\Monkey\tearDown();
	}

	/**
	 * Test bridge registers audit listeners when Stream APIs are available.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_02_bridge_registers_expected_listeners_when_stream_available(): void {
		\Brain\Monkey\setUp();
		$this->define_stream_stub();

		$GLOBALS['wp_sudo_stream_test_instance'] = (object) array(
			'log' => new \WP_Sudo_Stream_Test_Log(),
		);

		$callbacks = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ) use ( &$callbacks ): bool {
				$callbacks[ $hook ] = array(
					'callback'      => $callback,
					'priority'      => $priority,
					'accepted_args' => $accepted_args,
				);
				return true;
			}
		);

		include __DIR__ . '/../../bridges/wp-sudo-stream-bridge.php';

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

		$this->assertArrayNotHasKey( 'plugins_loaded', $callbacks );

		\Brain\Monkey\tearDown();
	}

	/**
	 * Test bridge maps hook payloads into Stream log entries.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_03_bridge_maps_payload_to_stream_log(): void {
		\Brain\Monkey\setUp();
		$this->define_stream_stub();

		$GLOBALS['wp_sudo_stream_test_instance'] = (object) array(
			'log' => new \WP_Sudo_Stream_Test_Log(),
		);

		$callbacks = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ) use ( &$callbacks ): bool {
				$callbacks[ $hook ] = $callback;
				return true;
			}
		);

		include __DIR__ . '/../../bridges/wp-sudo-stream-bridge.php';

		$this->assertArrayHasKey( 'wp_sudo_action_blocked', $callbacks );
		$callbacks['wp_sudo_action_blocked']( 42, 'plugin.activate', 'cli' );

		$this->assertNotEmpty( \WP_Sudo_Stream_Test_Log::$events );

		$event = \WP_Sudo_Stream_Test_Log::$events[0];
		$this->assertSame( 'wp_sudo', $event['connector'] ?? null );
		$this->assertSame( 'wp_sudo', $event['context'] ?? null );
		$this->assertSame( 'blocked', $event['action'] ?? null );
		$this->assertSame( 42, $event['user_id'] ?? null );
		$this->assertSame( 'wp_sudo_action_blocked', $event['args']['hook'] ?? null );
		$this->assertSame( 'plugin.activate', $event['args']['rule_id'] ?? null );
		$this->assertSame( 'cli', $event['args']['surface'] ?? null );

		\Brain\Monkey\tearDown();
	}

	/**
	 * Test preset application payloads are mapped into Stream log entries.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_03b_bridge_maps_policy_preset_payload_to_stream_log(): void {
		\Brain\Monkey\setUp();
		$this->define_stream_stub();

		$GLOBALS['wp_sudo_stream_test_instance'] = (object) array(
			'log' => new \WP_Sudo_Stream_Test_Log(),
		);

		$callbacks = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ) use ( &$callbacks ): bool {
				$callbacks[ $hook ] = $callback;
				return true;
			}
		);

		include __DIR__ . '/../../bridges/wp-sudo-stream-bridge.php';

		$this->assertArrayHasKey( 'wp_sudo_policy_preset_applied', $callbacks );
		$callbacks['wp_sudo_policy_preset_applied'](
			42,
			'headless_friendly',
			array( 'rest_app_password_policy' => 'limited' ),
			array( 'rest_app_password_policy' => 'unrestricted' ),
			false
		);

		$this->assertNotEmpty( \WP_Sudo_Stream_Test_Log::$events );

		$event = \WP_Sudo_Stream_Test_Log::$events[0];
		$this->assertSame( 'policy_preset_applied', $event['action'] ?? null );
		$this->assertSame( 42, $event['user_id'] ?? null );
		$this->assertSame( 'wp_sudo_policy_preset_applied', $event['args']['hook'] ?? null );
		$this->assertSame( 'headless_friendly', $event['args']['preset_key'] ?? null );
		$this->assertSame( 'limited', $event['args']['previous']['rest_app_password_policy'] ?? null );
		$this->assertSame( 'unrestricted', $event['args']['current']['rest_app_password_policy'] ?? null );
		$this->assertFalse( $event['args']['is_network'] ?? true );

		\Brain\Monkey\tearDown();
	}

	/**
	 * Test passed action payloads are mapped into Stream log entries.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_03c_bridge_maps_action_passed_payload_to_stream_log(): void {
		\Brain\Monkey\setUp();
		$this->define_stream_stub();

		$GLOBALS['wp_sudo_stream_test_instance'] = (object) array(
			'log' => new \WP_Sudo_Stream_Test_Log(),
		);

		$callbacks = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ) use ( &$callbacks ): bool {
				$callbacks[ $hook ] = $callback;
				return true;
			}
		);

		include __DIR__ . '/../../bridges/wp-sudo-stream-bridge.php';

		$this->assertArrayHasKey( 'wp_sudo_action_passed', $callbacks );
		$callbacks['wp_sudo_action_passed']( 42, 'plugin.activate', 'admin' );

		$this->assertNotEmpty( \WP_Sudo_Stream_Test_Log::$events );

		$event = \WP_Sudo_Stream_Test_Log::$events[0];
		$this->assertSame( 'passed', $event['action'] ?? null );
		$this->assertSame( 42, $event['user_id'] ?? null );
		$this->assertSame( 'wp_sudo_action_passed', $event['args']['hook'] ?? null );
		$this->assertSame( 'plugin.activate', $event['args']['rule_id'] ?? null );
		$this->assertSame( 'admin', $event['args']['surface'] ?? null );

		\Brain\Monkey\tearDown();
	}

	/**
	 * Test bridge can register listeners on plugins_loaded when Stream loads late.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_04_bridge_registers_late_when_stream_becomes_available(): void {
		\Brain\Monkey\setUp();
		$this->define_stream_stub();

		$GLOBALS['wp_sudo_stream_test_instance'] = null;

		$callbacks = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ) use ( &$callbacks ): bool {
				$callbacks[ $hook ][] = array(
					'callback'      => $callback,
					'priority'      => $priority,
					'accepted_args' => $accepted_args,
				);
				return true;
			}
		);

		include __DIR__ . '/../../bridges/wp-sudo-stream-bridge.php';

		$this->assertArrayHasKey( 'plugins_loaded', $callbacks );

		$GLOBALS['wp_sudo_stream_test_instance'] = (object) array(
			'log' => new \WP_Sudo_Stream_Test_Log(),
		);

		$plugins_loaded = $callbacks['plugins_loaded'][0]['callback'];
		$plugins_loaded();

		$this->assertArrayHasKey( 'wp_sudo_action_allowed', $callbacks );
		$this->assertArrayHasKey( 'wp_sudo_capability_tampered', $callbacks );

		\Brain\Monkey\tearDown();
	}

	/**
	 * Define lightweight Stream stubs for bridge tests.
	 *
	 * @return void
	 */
	private function define_stream_stub(): void {
		if ( ! class_exists( '\\WP_Sudo_Stream_Test_Log', false ) ) {
			// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
			eval( 'class WP_Sudo_Stream_Test_Log { public static array $events = []; public function log( string $connector, string $message, array $args, int $object_id, string $context, string $action, int $user_id = 0 ): bool { self::$events[] = ["connector" => $connector, "message" => $message, "args" => $args, "object_id" => $object_id, "context" => $context, "action" => $action, "user_id" => $user_id]; return true; } }' );
		}

		\WP_Sudo_Stream_Test_Log::$events = array();

		if ( ! function_exists( 'wp_stream_get_instance' ) ) {
			// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
			eval( 'function wp_stream_get_instance() { return $GLOBALS["wp_sudo_stream_test_instance"] ?? null; }' );
		}
	}
}
