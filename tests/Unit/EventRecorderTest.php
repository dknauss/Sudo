<?php
/**
 * Unit tests for Event_Recorder class.
 *
 * Tests hook subscription and payload mapping for the dashboard widget
 * event recording layer.
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use WP_Sudo\Admin;
use WP_Sudo\Event_Recorder;
use WP_Sudo\Tests\TestCase;

/**
 * Simple wpdb mock for capturing insert() calls.
 *
 * @phpstan-type InsertCall array{table: string, data: array<string, mixed>}
 */
class EventRecorderFakeWpdb {

	/** @var string */
	public string $prefix = 'wp_';

	/** @var string */
	public string $base_prefix = 'wp_';

	/**
	 * Captured insert calls.
	 *
	 * @var list<InsertCall>
	 */
	public array $inserts = [];

	/**
	 * Mock insert().
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $data  Row data.
	 * @return int|false
	 */
	public function insert( string $table, array $data ) {
		$this->inserts[] = [
			'table' => $table,
			'data'  => $data,
		];
		return 1;
	}

	/**
	 * Mock get_charset_collate().
	 *
	 * @return string
	 */
	public function get_charset_collate(): string {
		return '';
	}

	/**
	 * Mock prepare().
	 *
	 * @param string       $query Query with placeholders.
	 * @param mixed ...$args      Parameters.
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		return $query;
	}

	/**
	 * Mock get_results().
	 *
	 * Returns one column definition to simulate an accessible table.
	 *
	 * @param string $query Query.
	 * @return array<int, object>
	 */
	public function get_results( string $query ): array {
		return [
			(object) [
				'Field' => 'id',
			],
		];
	}

	/**
	 * Mock suppress_errors().
	 *
	 * @param bool $suppress Whether to suppress errors.
	 * @return bool
	 */
	public function suppress_errors( bool $suppress ): bool {
		return $suppress;
	}

}

/**
 * @covers \WP_Sudo\Event_Recorder
 */
class EventRecorderTest extends TestCase {

	/**
	 * Fake wpdb for capturing Event_Store::insert() calls.
	 *
	 * @var EventRecorderFakeWpdb|null
	 */
	private ?EventRecorderFakeWpdb $fake_wpdb = null;

	/**
	 * Original wpdb, if any.
	 *
	 * @var object|null
	 */
	private ?object $original_wpdb = null;

	// ─── Hook subscription tests ────────────────────────────────────────

	/**
	 * Test that constructor registers add_action for only the MVP widget hooks.
	 *
	 * @return void
	 */
	public function testConstructorRegistersOnlyMvpHooks(): void {
		// Expect exactly these hooks to be registered.
		Actions\expectAdded( 'wp_sudo_lockout' )
			->once()
			->with( [ Event_Recorder::class, 'on_lockout' ], 10, 3 );

		Actions\expectAdded( 'wp_sudo_action_gated' )
			->once()
			->with( [ Event_Recorder::class, 'on_action_gated' ], 10, 3 );

		Actions\expectAdded( 'wp_sudo_action_blocked' )
			->once()
			->with( [ Event_Recorder::class, 'on_action_blocked' ], 10, 3 );

		Actions\expectAdded( 'wp_sudo_action_allowed' )
			->once()
			->with( [ Event_Recorder::class, 'on_action_allowed' ], 10, 3 );

		Actions\expectAdded( 'wp_sudo_action_passed' )
			->once()
			->with( [ Event_Recorder::class, 'on_action_passed' ], 10, 3 );

		Actions\expectAdded( 'wp_sudo_action_replayed' )
			->once()
			->with( [ Event_Recorder::class, 'on_action_replayed' ], 10, 2 );

		new Event_Recorder();
	}

	/**
	 * Test that hooks NOT in the MVP set are NOT registered.
	 *
	 * The full audit hook set includes wp_sudo_activated, wp_sudo_deactivated,
	 * wp_sudo_reauth_failed, etc. — but the Event_Recorder only subscribes to
	 * the hooks that produce immediate operator value in the dashboard widget.
	 *
	 * @return void
	 */
	public function testConstructorDoesNotRegisterNonMvpHooks(): void {
		// These hooks exist but should NOT be registered by Event_Recorder.
		Actions\expectAdded( 'wp_sudo_activated' )->never();
		Actions\expectAdded( 'wp_sudo_deactivated' )->never();
		Actions\expectAdded( 'wp_sudo_reauth_failed' )->never();
		Actions\expectAdded( 'wp_sudo_capability_tampered' )->never();
		Actions\expectAdded( 'wp_sudo_preset_applied' )->never();

		// Allow the MVP hooks to be registered.
		Actions\expectAdded( 'wp_sudo_lockout' )->once();
		Actions\expectAdded( 'wp_sudo_action_gated' )->once();
		Actions\expectAdded( 'wp_sudo_action_blocked' )->once();
		Actions\expectAdded( 'wp_sudo_action_allowed' )->once();
		Actions\expectAdded( 'wp_sudo_action_passed' )->once();
		Actions\expectAdded( 'wp_sudo_action_replayed' )->once();

		new Event_Recorder();
	}

	/**
	 * Test that each hook is registered at default priority (10).
	 *
	 * @return void
	 */
	public function testHooksRegisteredAtDefaultPriority(): void {
		// This is implicitly tested by testConstructorRegistersOnlyMvpHooks,
		// but we make it explicit here for documentation.
		$hooks_with_args = [
			'wp_sudo_lockout'          => 3,
			'wp_sudo_action_gated'     => 3,
			'wp_sudo_action_blocked'   => 3,
			'wp_sudo_action_allowed'   => 3,
			'wp_sudo_action_passed'    => 3,
			'wp_sudo_action_replayed'  => 2,
		];

		foreach ( $hooks_with_args as $hook => $accepted_args ) {
			Actions\expectAdded( $hook )
				->once()
				->with(
					\Mockery::type( 'array' ), // callback
					10,                         // priority
					$accepted_args              // accepted args
				);
		}

		new Event_Recorder();
	}

	/**
	 * Test accepted args count matches each hook's signature.
	 *
	 * wp_sudo_lockout:         3 args (user_id, attempts, ip)
	 * wp_sudo_action_gated:    3 args (user_id, rule_id, surface)
	 * wp_sudo_action_blocked:  3 args (user_id, rule_id, surface)
	 * wp_sudo_action_allowed:  3 args (user_id, rule_id, surface)
	 * wp_sudo_action_replayed: 2 args (user_id, rule_id)
	 *
	 * @return void
	 */
	public function testAcceptedArgsMatchHookSignatures(): void {
		// Capture the actual add_action calls to verify accepted_args.
		$captured = [];

		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority, $accepted_args ) use ( &$captured ) {
				$captured[ $hook ] = [
					'priority'      => $priority,
					'accepted_args' => $accepted_args,
				];
			}
		);

		new Event_Recorder();

		$expected_args = [
			'wp_sudo_lockout'         => 3,
			'wp_sudo_action_gated'    => 3,
			'wp_sudo_action_blocked'  => 3,
			'wp_sudo_action_allowed'  => 3,
			'wp_sudo_action_passed'   => 3,
			'wp_sudo_action_replayed' => 2,
		];

		foreach ( $expected_args as $hook => $expected ) {
			$this->assertArrayHasKey( $hook, $captured, "Hook $hook should be registered" );
			$this->assertSame(
				$expected,
				$captured[ $hook ]['accepted_args'],
				"Hook $hook should have $expected accepted args"
			);
		}
	}

	// ─── Payload mapping tests ───────────────────────────────────────────

	/**
	 * Set up fake wpdb for payload mapping tests.
	 *
	 * @return void
	 */
	private function setUpFakeWpdb(): void {
		$this->original_wpdb     = $GLOBALS['wpdb'] ?? null;
		$this->fake_wpdb         = new EventRecorderFakeWpdb();
		$GLOBALS['wpdb']         = $this->fake_wpdb;

		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
	}

	/**
	 * Restore original wpdb.
	 *
	 * @return void
	 */
	private function restoreWpdb(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
	}

	/**
	 * Test on_lockout() inserts event with 'lockout' type and IP populated.
	 *
	 * @return void
	 */
	public function testOnLockoutInsertsEventWithIpAndAttempts(): void {
		$this->setUpFakeWpdb();

		Event_Recorder::on_lockout( 42, 5, '192.168.1.100' );

		$this->assertCount( 1, $this->fake_wpdb->inserts, 'Should insert one event' );

		$data = $this->fake_wpdb->inserts[0]['data'];
		$this->assertSame( 42, $data['user_id'] );
		$this->assertSame( 'lockout', $data['event'] );
		$this->assertSame( '192.168.1.100', $data['ip'] );

		// Context should include attempts count.
		$context = json_decode( $data['context'], true );
		$this->assertIsArray( $context );
		$this->assertSame( 5, $context['attempts'] );

		$this->restoreWpdb();
	}

	/**
	 * Test on_action_gated() inserts event with rule_id and surface.
	 *
	 * @return void
	 */
	public function testOnActionGatedInsertsEventWithRuleIdAndSurface(): void {
		$this->setUpFakeWpdb();

		Event_Recorder::on_action_gated( 7, 'plugins.activate', 'admin' );

		$this->assertCount( 1, $this->fake_wpdb->inserts );

		$data = $this->fake_wpdb->inserts[0]['data'];
		$this->assertSame( 7, $data['user_id'] );
		$this->assertSame( 'action_gated', $data['event'] );
		$this->assertSame( 'plugins.activate', $data['rule_id'] );
		$this->assertSame( 'admin', $data['surface'] );

		$this->restoreWpdb();
	}

	/**
	 * Test on_action_blocked() inserts event for non-interactive denial.
	 *
	 * @return void
	 */
	public function testOnActionBlockedInsertsEventForNonInteractiveDenial(): void {
		$this->setUpFakeWpdb();

		Event_Recorder::on_action_blocked( 12, 'users.delete', 'cli' );

		$this->assertCount( 1, $this->fake_wpdb->inserts );

		$data = $this->fake_wpdb->inserts[0]['data'];
		$this->assertSame( 12, $data['user_id'] );
		$this->assertSame( 'action_blocked', $data['event'] );
		$this->assertSame( 'users.delete', $data['rule_id'] );
		$this->assertSame( 'cli', $data['surface'] );

		$this->restoreWpdb();
	}

	/**
	 * Test on_action_allowed() inserts event for Unrestricted policy pass-through.
	 *
	 * @return void
	 */
	public function testOnActionAllowedInsertsEventForUnrestrictedPassThrough(): void {
		$this->setUpFakeWpdb();

		Event_Recorder::on_action_allowed( 99, 'options.update', 'rest_app_password' );

		$this->assertCount( 1, $this->fake_wpdb->inserts );

		$data = $this->fake_wpdb->inserts[0]['data'];
		$this->assertSame( 99, $data['user_id'] );
		$this->assertSame( 'action_allowed', $data['event'] );
		$this->assertSame( 'options.update', $data['rule_id'] );
		$this->assertSame( 'rest_app_password', $data['surface'] );

		$this->restoreWpdb();
	}

	/**
	 * Test on_action_replayed() inserts event after successful reauth.
	 *
	 * @return void
	 */
	public function testOnActionReplayedInsertsEventAfterReauth(): void {
		$this->setUpFakeWpdb();

		Event_Recorder::on_action_replayed( 55, 'themes.switch' );

		$this->assertCount( 1, $this->fake_wpdb->inserts );

		$data = $this->fake_wpdb->inserts[0]['data'];
		$this->assertSame( 55, $data['user_id'] );
		$this->assertSame( 'action_replayed', $data['event'] );
		$this->assertSame( 'themes.switch', $data['rule_id'] );
		// Replayed events have no surface (originated from admin UI).
		$this->assertSame( '', $data['surface'] );

		$this->restoreWpdb();
	}

	/**
	 * Test on_action_passed() inserts event when passed logging is enabled.
	 *
	 * @return void
	 */
	public function testOnActionPassedInsertsEventWhenLoggingEnabled(): void {
		$this->setUpFakeWpdb();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		Event_Recorder::on_action_passed( 18, 'options.update', 'admin' );

		$this->assertCount( 1, $this->fake_wpdb->inserts );

		$data = $this->fake_wpdb->inserts[0]['data'];
		$this->assertSame( 18, $data['user_id'] );
		$this->assertSame( 'action_passed', $data['event'] );
		$this->assertSame( 'options.update', $data['rule_id'] );
		$this->assertSame( 'admin', $data['surface'] );

		$this->restoreWpdb();
	}

	/**
	 * Test on_action_passed() skips insert when disabled by policy override.
	 *
	 * @return void
	 */
	public function testOnActionPassedSkipsInsertWhenLoggingDisabledByFilter(): void {
		$this->setUpFakeWpdb();
		Functions\when( 'apply_filters' )->alias(
			function ( string $hook, $value ) {
				if ( Admin::PASSED_EVENT_LOGGING_FILTER === $hook ) {
					return false;
				}

				return $value;
			}
		);

		Event_Recorder::on_action_passed( 18, 'options.update', 'admin' );

		$this->assertCount( 0, $this->fake_wpdb->inserts );

		$this->restoreWpdb();
	}

}
