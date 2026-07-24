<?php
/**
 * Tests for the critical-event alert bridge.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo\Tests\Unit;

use Brain\Monkey\Functions;
use WP_Sudo\Tests\TestCase;

/**
 * @coversNothing Procedural bridge file.
 */
class CriticalAlertBridgeTest extends TestCase {

	/**
	 * In-memory transient stores backing the throttle/count helpers.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * @var array<string, mixed>
	 */
	private array $site_transients = array();

	/**
	 * Install baseline WordPress stubs the bridge depends on, then load it.
	 *
	 * @param array<string, mixed> $overrides Optional per-test behavior:
	 *   'events'    => string[] returned by the enabled-events filter,
	 *   'dispatch'  => mixed returned by the dispatch short-circuit filter,
	 *   'throttle'  => int throttle window, 'cap' => int hourly cap,
	 *   'recipient' => string forced recipient, 'multisite' => bool,
	 *   'current_user' => int.
	 * @return void
	 */
	private function boot( array $overrides = array() ): void {
		$this->transients      = array();
		$this->site_transients = array();

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'is_multisite' )->justReturn( (bool) ( $overrides['multisite'] ?? false ) );
		Functions\when( 'is_super_admin' )->justReturn( (bool) ( $overrides['super_admin'] ?? false ) );
		Functions\when( 'get_current_user_id' )->justReturn( (int) ( $overrides['current_user'] ?? 0 ) );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'network_home_url' )->justReturn( 'https://network.example.test' );
		Functions\when( 'get_option' )->justReturn( 'site-admin@example.test' );
		Functions\when( 'get_site_option' )->justReturn( 'network-admin@example.test' );

		// Filterable knobs. apply_filters is aliased so each documented tag
		// resolves to its default (or a per-test override).
		Functions\when( 'apply_filters' )->alias(
			function ( string $tag, $value = null ) use ( $overrides ) {
				switch ( $tag ) {
					case 'wp_sudo_critical_alert_events':
						return $overrides['events'] ?? $value;
					case 'wp_sudo_critical_alert_dispatch':
						return $overrides['dispatch'] ?? null;
					case 'wp_sudo_critical_alert_throttle':
						return $overrides['throttle'] ?? $value;
					case 'wp_sudo_critical_alert_hourly_cap':
						return $overrides['cap'] ?? $value;
					case 'wp_sudo_critical_alert_recipient':
						return $overrides['recipient'] ?? $value;
				}
				return $value;
			}
		);

		$store = &$this->transients;
		Functions\when( 'get_transient' )->alias(
			static function ( string $k ) use ( &$store ) {
				return $store[ $k ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( string $k, $v, $exp = 0 ) use ( &$store ): bool {
				$store[ $k ] = $v;
				return true;
			}
		);
		$sstore = &$this->site_transients;
		Functions\when( 'get_site_transient' )->alias(
			static function ( string $k ) use ( &$sstore ) {
				return $sstore[ $k ] ?? false;
			}
		);
		Functions\when( 'set_site_transient' )->alias(
			static function ( string $k, $v, $exp = 0 ) use ( &$sstore ): bool {
				$sstore[ $k ] = $v;
				return true;
			}
		);

		include __DIR__ . '/../../bridges/wp-sudo-critical-alert-bridge.php';
	}

	public function test_registers_default_events_and_shutdown_but_not_recovery_mode(): void {
		$this->transients      = array();
		$this->site_transients = array();
		Functions\when( 'apply_filters' )->returnArg( 2 ); // events filter → default.
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$hooks ): bool {
				$hooks[] = $hook;
				return true;
			}
		);

		include __DIR__ . '/../../bridges/wp-sudo-critical-alert-bridge.php';

		// Registration is deferred to plugins_loaded so regular plugins/themes
		// can filter the enabled-event set before it is read.
		$this->assertContains( 'plugins_loaded', $hooks );

		// Fire the deferred registration and assert the audit-hook wiring.
		wp_sudo_critical_alert_bridge_register();

		$this->assertContains( 'wp_sudo_capability_tampered', $hooks );
		$this->assertContains( 'wp_sudo_escalation_blocked', $hooks );
		$this->assertContains( 'wp_sudo_lockout', $hooks );
		$this->assertContains( 'wp_sudo_gated_actions_missing_builtin_rules', $hooks );
		$this->assertContains( 'wp_sudo_role_drift_detected', $hooks );
		$this->assertContains( 'shutdown', $hooks );
		// recovery_mode is high-frequency and opt-in: not wired by default.
		$this->assertNotContains( 'wp_sudo_recovery_mode_active', $hooks );
	}

	public function test_recovery_mode_wired_only_when_enabled(): void {
		$this->transients      = array();
		$this->site_transients = array();
		Functions\when( 'apply_filters' )->alias(
			static fn( string $tag, $value = null ) => 'wp_sudo_critical_alert_events' === $tag
				? array( 'recovery_mode' )
				: $value
		);
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$hooks ): bool {
				$hooks[] = $hook;
				return true;
			}
		);

		include __DIR__ . '/../../bridges/wp-sudo-critical-alert-bridge.php';
		wp_sudo_critical_alert_bridge_register();

		$this->assertContains( 'wp_sudo_recovery_mode_active', $hooks );
	}

	public function test_escalation_event_labels_target_and_actor_not_confused(): void {
		$this->boot( array( 'current_user' => 7 ) );

		$event = wp_sudo_critical_alert_bridge_build_event( 'escalation_blocked', array( 42, 'user.super_admin', 'admin' ) );

		$this->assertIsArray( $event );
		$this->assertStringContainsString( 'targeting user #42', $event['body'] );
		$this->assertStringContainsString( 'Actor: #7', $event['body'] );
		// super-admin grants are network-scope.
		$this->assertSame( 'network', $event['scope'] );
	}

	public function test_lockout_and_tampered_events_build_expected_bodies(): void {
		$this->boot();

		$lockout = wp_sudo_critical_alert_bridge_build_event( 'lockout', array( 5, 5, '203.0.113.9' ) );
		$this->assertStringContainsString( 'User #5 locked out after 5 failed attempts from 203.0.113.9', $lockout['body'] );
		$this->assertSame( '5:203.0.113.9', $lockout['identity'] );

		$tamper = wp_sudo_critical_alert_bridge_build_event( 'capability_tampered', array( 'editor', 'unfiltered_html' ) );
		$this->assertStringContainsString( 'Role "editor" regained capability "unfiltered_html"', $tamper['body'] );
	}

	public function test_reserve_dedupes_same_event_within_window(): void {
		$this->boot();
		$event = array( 'key' => 'lockout', 'identity' => '5:203.0.113.9', 'scope' => 'site' );

		$this->assertTrue( wp_sudo_critical_alert_bridge_reserve( $event, 3600 ) );
		$this->assertFalse( wp_sudo_critical_alert_bridge_reserve( $event, 3600 ) );
	}

	public function test_flush_sends_one_email_per_distinct_event_and_dedupes_repeats(): void {
		$this->boot();
		$sent = array();
		Functions\when( 'wp_mail' )->alias(
			static function ( string $to, string $subject ) use ( &$sent ): bool {
				$sent[] = $subject;
				return true;
			}
		);

		$a = array( 'key' => 'lockout', 'subject' => 'Reauthentication lockout', 'body' => 'x', 'identity' => '5:ip', 'scope' => 'site' );
		$b = array( 'key' => 'capability_tampered', 'subject' => 'Capability tamper detected', 'body' => 'y', 'identity' => 'editor:cap', 'scope' => 'site' );
		wp_sudo_critical_alert_bridge_queue( 'add', $a );
		wp_sudo_critical_alert_bridge_queue( 'add', $a ); // duplicate → deduped.
		wp_sudo_critical_alert_bridge_queue( 'add', $b );

		wp_sudo_critical_alert_bridge_flush();

		$this->assertCount( 2, $sent );
		$this->assertContains( '[WP Sudo] Reauthentication lockout', $sent );
		$this->assertContains( '[WP Sudo] Capability tamper detected', $sent );

		// Queue is cleared after flush.
		$this->assertSame( array(), wp_sudo_critical_alert_bridge_queue( 'get' ) );
	}

	public function test_flush_hourly_cap_collapses_overflow_into_a_digest(): void {
		$this->boot( array( 'cap' => 1 ) );
		$sent = array();
		Functions\when( 'wp_mail' )->alias(
			static function ( string $to, string $subject, string $body ) use ( &$sent ): bool {
				$sent[] = array( 'subject' => $subject, 'body' => $body );
				return true;
			}
		);

		foreach ( array( 'a', 'b', 'c' ) as $i ) {
			wp_sudo_critical_alert_bridge_queue(
				'add',
				array( 'key' => 'lockout', 'subject' => 'Reauthentication lockout', 'body' => $i, 'identity' => $i, 'scope' => 'site' )
			);
		}

		wp_sudo_critical_alert_bridge_flush();

		// One real alert (cap = 1) + one digest summarizing the other two.
		$this->assertCount( 2, $sent );
		$digest = end( $sent );
		$this->assertStringContainsString( '[WP Sudo] Additional critical events suppressed', $digest['subject'] );
		$this->assertStringContainsString( '2 further critical event', $digest['body'] );
	}

	public function test_dispatch_filter_short_circuit_suppresses_default_email(): void {
		$this->boot( array( 'dispatch' => 'handled' ) );
		$mailed = false;
		Functions\when( 'wp_mail' )->alias(
			static function () use ( &$mailed ): bool {
				$mailed = true;
				return true;
			}
		);
		$observed = array();
		Functions\when( 'do_action' )->alias(
			static function ( string $tag, $event = null ) use ( &$observed ): void {
				if ( 'wp_sudo_critical_alert_dispatched' === $tag ) {
					$observed[] = $event;
				}
			}
		);

		wp_sudo_critical_alert_bridge_dispatch( array( 'key' => 'lockout', 'subject' => 's', 'body' => 'b', 'scope' => 'site' ) );

		$this->assertFalse( $mailed, 'A non-null dispatch filter must replace the default email.' );
		$this->assertCount( 1, $observed, 'The additive dispatched action always fires.' );
	}

	public function test_recipient_uses_network_admin_for_network_scope(): void {
		$this->boot( array( 'multisite' => true ) );

		$this->assertSame(
			'network-admin@example.test',
			wp_sudo_critical_alert_bridge_recipient( array( 'scope' => 'network' ) )
		);
		$this->assertSame(
			'site-admin@example.test',
			wp_sudo_critical_alert_bridge_recipient( array( 'scope' => 'site' ) )
		);
	}

	public function test_hourly_counter_preserves_window_start_across_increments(): void {
		$this->boot();

		wp_sudo_critical_alert_bridge_count( true, false );
		$first = $this->transients['_wp_sudo_critical_alert_count'];
		wp_sudo_critical_alert_bridge_count( true, false );
		$second = $this->transients['_wp_sudo_critical_alert_count'];

		$this->assertSame( 2, $second['count'] );
		// The window opens once; increments must not push its start forward
		// (which would let a slow trickle suppress alerts indefinitely).
		$this->assertSame( $first['start'], $second['start'] );
	}

	public function test_hourly_counter_reports_zero_after_the_window_elapses(): void {
		$this->boot();
		// Seed an expired window (opened over an hour ago).
		$this->transients['_wp_sudo_critical_alert_count'] = array(
			'count' => 9,
			'start' => time() - HOUR_IN_SECONDS - 1,
		);

		// A read past the window reports zero without persisting a write.
		$this->assertSame( 0, wp_sudo_critical_alert_bridge_count( false, false ) );
	}

	public function test_network_scope_events_count_against_a_separate_network_counter(): void {
		$this->boot( array( 'multisite' => true ) );

		wp_sudo_critical_alert_bridge_count( true, true );
		wp_sudo_critical_alert_bridge_count( true, false );

		// Network events use a network transient, site events a per-site one, so
		// no single recipient's mailbox is flooded and one busy site cannot
		// starve another site's alerts.
		$this->assertSame( 1, $this->site_transients['_wp_sudo_critical_alert_count']['count'] );
		$this->assertSame( 1, $this->transients['_wp_sudo_critical_alert_count']['count'] );
	}

	public function test_overflow_digest_is_deduped_across_repeated_flushes(): void {
		$this->boot( array( 'cap' => 1 ) );
		$digests = 0;
		Functions\when( 'wp_mail' )->alias(
			static function ( string $to, string $subject ) use ( &$digests ): bool {
				if ( false !== strpos( $subject, 'Additional critical events suppressed' ) ) {
					++$digests;
				}
				return true;
			}
		);

		// First flush: 'a' fills the cap, 'b' overflows → one digest.
		wp_sudo_critical_alert_bridge_queue( 'add', array( 'key' => 'lockout', 'subject' => 's', 'body' => 'a', 'identity' => 'a', 'scope' => 'site' ) );
		wp_sudo_critical_alert_bridge_queue( 'add', array( 'key' => 'lockout', 'subject' => 's', 'body' => 'b', 'identity' => 'b', 'scope' => 'site' ) );
		wp_sudo_critical_alert_bridge_flush();

		// Second flush in the same window overflows again but must NOT re-emit a
		// digest — otherwise a per-request flood sends one digest per request.
		wp_sudo_critical_alert_bridge_queue( 'add', array( 'key' => 'lockout', 'subject' => 's', 'body' => 'c', 'identity' => 'c', 'scope' => 'site' ) );
		wp_sudo_critical_alert_bridge_flush();

		$this->assertSame( 1, $digests );
	}

	public function test_escalation_super_admin_target_routes_to_network_on_multisite(): void {
		$this->boot( array( 'multisite' => true, 'super_admin' => true, 'current_user' => 7 ) );

		// Generic user.delete against a super-admin target is a network concern.
		$event = wp_sudo_critical_alert_bridge_build_event( 'escalation_blocked', array( 42, 'user.delete', 'admin' ) );

		$this->assertSame( 'network', $event['scope'] );
	}

	public function test_escalation_non_super_admin_target_stays_site_scope(): void {
		$this->boot( array( 'multisite' => true, 'super_admin' => false ) );

		$event = wp_sudo_critical_alert_bridge_build_event( 'escalation_blocked', array( 42, 'user.delete', 'admin' ) );

		$this->assertSame( 'site', $event['scope'] );
	}

	public function test_missing_network_rules_route_to_network_on_multisite(): void {
		$this->boot( array( 'multisite' => true ) );

		$event = wp_sudo_critical_alert_bridge_build_event(
			'missing_builtin_rules',
			array( array( 'network.super_admin', 'plugin.activate' ) )
		);

		$this->assertSame( 'network', $event['scope'] );
	}

	public function test_missing_site_only_rules_stay_site_scope(): void {
		$this->boot( array( 'multisite' => true ) );

		$event = wp_sudo_critical_alert_bridge_build_event(
			'missing_builtin_rules',
			array( array( 'plugin.activate', 'user.delete' ) )
		);

		$this->assertSame( 'site', $event['scope'] );
	}

	public function test_role_drift_event_summarizes_counts_and_is_site_scoped(): void {
		$this->boot();

		$report = array(
			'sites'   => array( 1 => array( 'administrators' => array( 99 ), 'governance' => array( 7, 8 ) ) ),
			'network' => array( 'super_admins' => array() ),
			'roles'   => array( 'editor' => array( 'expected' => 'a', 'actual' => 'b' ) ),
		);
		$event = wp_sudo_critical_alert_bridge_build_event( 'role_drift', array( $report ) );

		$this->assertIsArray( $event );
		$this->assertSame( 'Role/capability drift detected', $event['subject'] );
		// 1 admin + 2 governance = 3 principals; 1 changed role.
		$this->assertStringContainsString( '3 unauthorized privileged principal(s)', $event['body'] );
		$this->assertStringContainsString( '1 changed role definition(s)', $event['body'] );
		$this->assertStringContainsString( 'roles: editor', $event['body'] );
		$this->assertSame( 'site', $event['scope'] );
	}

	public function test_role_drift_super_admin_set_is_network_scoped(): void {
		$this->boot( array( 'multisite' => true ) );

		$event = wp_sudo_critical_alert_bridge_build_event(
			'role_drift',
			array( array( 'network' => array( 'super_admins' => array( 42 ) ), 'roles' => array() ) )
		);

		$this->assertSame( 'network', $event['scope'] );
	}

	public function test_role_drift_identity_is_stable_for_the_same_drift_but_changes_with_it(): void {
		$this->boot();

		$a = wp_sudo_critical_alert_bridge_build_event( 'role_drift', array( array( 'sites' => array( 1 => array( 'administrators' => array( 99 ) ) ) ) ) );
		$b = wp_sudo_critical_alert_bridge_build_event( 'role_drift', array( array( 'sites' => array( 1 => array( 'administrators' => array( 99 ) ) ) ) ) );
		$c = wp_sudo_critical_alert_bridge_build_event( 'role_drift', array( array( 'sites' => array( 1 => array( 'administrators' => array( 99, 100 ) ) ) ) ) );

		// Identical drift → identical identity (deduped across daily sweeps)...
		$this->assertSame( $a['identity'], $b['identity'] );
		// ...but a changed drift set produces a new identity (re-alerts).
		$this->assertNotSame( $a['identity'], $c['identity'] );
	}

	public function test_role_drift_identity_changes_when_a_drifted_roles_hash_changes(): void {
		$this->boot();

		// Same role slug still drifted, but its actual definition changed again
		// (new actual hash) — must NOT dedupe to the first alert.
		$first  = wp_sudo_critical_alert_bridge_build_event( 'role_drift', array( array( 'roles' => array( 'editor' => array( 'expected' => 'sha256:base', 'actual' => 'sha256:one' ) ) ) ) );
		$second = wp_sudo_critical_alert_bridge_build_event( 'role_drift', array( array( 'roles' => array( 'editor' => array( 'expected' => 'sha256:base', 'actual' => 'sha256:two' ) ) ) ) );

		$this->assertNotSame( $first['identity'], $second['identity'] );
	}
}
