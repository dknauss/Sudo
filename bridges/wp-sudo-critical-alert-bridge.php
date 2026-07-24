<?php
/**
 * WP Sudo ↔ Critical-Event Alert Bridge
 *
 * Optional bridge that PUSHES a notification when WP Sudo fires one of its
 * high-severity audit hooks. Drop this file into wp-content/mu-plugins/ to
 * activate. Unlike the Stream and WSAL bridges (which LOG every audit event),
 * this bridge notifies a human about the events that usually warrant a look.
 *
 * Safety properties (see the design notes below each concern):
 *
 * - Deferred, never blocking. Alerts are queued and dispatched on `shutdown`,
 *   which fires even after the gate's `wp_die()`. A slow SMTP send therefore
 *   never delays the security-blocking response — the event that fires an alert
 *   (e.g. wp_sudo_escalation_blocked, right before the gate dies) is already
 *   answered before the mail is attempted.
 * - Throttled against floods. Every mapped event is deduped per identity for a
 *   window, and a per-recipient hourly cap collapses an incident into a single
 *   "N more suppressed" summary. This matters because several of these hooks are
 *   attacker-driven at volume (lockout enumeration, per-request tamper), so an
 *   unthrottled bridge would be a mail/outbound-DoS amplifier.
 * - Observability only. It consumes `do_action` hooks; it cannot change any
 *   gating decision and does not interfere with the Stream/WSAL bridges.
 *
 * Configuration (all filterable):
 *
 * - `wp_sudo_critical_alert_events`     — array of enabled event keys.
 * - `wp_sudo_critical_alert_recipient`  — ( string $email, array $event ).
 * - `wp_sudo_critical_alert_throttle`   — int seconds, per-event dedupe window.
 * - `wp_sudo_critical_alert_hourly_cap` — int max alerts/hour per recipient.
 * - `wp_sudo_critical_alert_dispatch`   — ( null|mixed $handled, array $event ):
 *       return non-null to REPLACE the default email (Slack/webhook/inline
 *       capture). Runs before the default email; a non-null return suppresses it.
 * - `wp_sudo_critical_alert_dispatched` — action ( array $event ): additive
 *       observer that always fires (e.g. a demo panel or secondary sink).
 *
 * @package WP_Sudo_Bridges
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_sudo_critical_alert_bridge_event_map' ) ) {
	/**
	 * Map of alert event keys to their WP Sudo audit hook + argument count.
	 *
	 * `recovery_mode` is deliberately absent from the default enabled set (see
	 * wp_sudo_critical_alert_bridge_enabled_events): it fires on every Sudo
	 * admin-page load during a legitimate break-glass episode and would drown
	 * the genuinely urgent tamper/escalation alerts.
	 *
	 * @return array<string, array{hook: string, args: int}>
	 */
	function wp_sudo_critical_alert_bridge_event_map(): array {
		return array(
			'capability_tampered'   => array( 'hook' => 'wp_sudo_capability_tampered', 'args' => 2 ),
			'escalation_blocked'    => array( 'hook' => 'wp_sudo_escalation_blocked', 'args' => 3 ),
			'lockout'               => array( 'hook' => 'wp_sudo_lockout', 'args' => 3 ),
			'missing_builtin_rules' => array( 'hook' => 'wp_sudo_gated_actions_missing_builtin_rules', 'args' => 1 ),
			'role_drift'            => array( 'hook' => 'wp_sudo_role_drift_detected', 'args' => 1 ),
			'recovery_mode'         => array( 'hook' => 'wp_sudo_recovery_mode_active', 'args' => 1 ),
		);
	}
}

if ( ! function_exists( 'wp_sudo_critical_alert_bridge_enabled_events' ) ) {
	/**
	 * The enabled event keys. Defaults to the always-suspicious set; the
	 * frequent `recovery_mode` event is opt-in via the filter.
	 *
	 * @return string[]
	 */
	function wp_sudo_critical_alert_bridge_enabled_events(): array {
		$default = array( 'capability_tampered', 'escalation_blocked', 'lockout', 'missing_builtin_rules', 'role_drift' );

		$events = apply_filters( 'wp_sudo_critical_alert_events', $default );

		if ( ! is_array( $events ) ) {
			return $default;
		}

		// Keep only keys we actually know how to build.
		return array_values( array_intersect( array_map( 'strval', $events ), array_keys( wp_sudo_critical_alert_bridge_event_map() ) ) );
	}
}

if ( ! function_exists( 'wp_sudo_critical_alert_bridge_register' ) ) {
	/**
	 * Register listeners for the enabled critical audit hooks, plus the
	 * shutdown flush that dispatches queued alerts.
	 *
	 * @return void
	 */
	function wp_sudo_critical_alert_bridge_register(): void {
		$map     = wp_sudo_critical_alert_bridge_event_map();
		$enabled = wp_sudo_critical_alert_bridge_enabled_events();

		foreach ( $enabled as $key ) {
			if ( ! isset( $map[ $key ] ) ) {
				continue;
			}

			/** @psalm-suppress HookNotFound WP Sudo audit hook names are declared by the event map above. */
			add_action(
				$map[ $key ]['hook'],
				static function ( ...$args ) use ( $key ): void {
					$event = wp_sudo_critical_alert_bridge_build_event( $key, $args );
					if ( null !== $event ) {
						wp_sudo_critical_alert_bridge_queue( 'add', $event );
					}
				},
				10,
				(int) $map[ $key ]['args']
			);
		}

		// Dispatch on shutdown so a slow send never delays the triggering
		// request (shutdown fires even after the gate's wp_die()).
		add_action( 'shutdown', 'wp_sudo_critical_alert_bridge_flush', 100, 0 );
	}
}

if ( ! function_exists( 'wp_sudo_critical_alert_bridge_queue' ) ) {
	/**
	 * Pending-alert accumulator. `add` appends, `reset` clears (test hygiene),
	 * `get` returns the current queue.
	 *
	 * @param string     $op    One of 'get', 'add', 'reset'.
	 * @param array|null $event Event to append when $op is 'add'.
	 * @return array<int, array<string, mixed>>
	 */
	function wp_sudo_critical_alert_bridge_queue( string $op = 'get', ?array $event = null ): array {
		static $queue = array();

		if ( 'reset' === $op ) {
			$queue = array();
		} elseif ( 'add' === $op && null !== $event ) {
			$queue[] = $event;
		}

		return $queue;
	}
}

if ( ! function_exists( 'wp_sudo_critical_alert_bridge_build_event' ) ) {
	/**
	 * Normalize a raw hook payload into a dispatchable event, or null if the
	 * event key is unknown.
	 *
	 * @param string             $key  Event key.
	 * @param array<int, mixed>  $args Raw hook arguments.
	 * @return array<string, mixed>|null
	 */
	function wp_sudo_critical_alert_bridge_build_event( string $key, array $args ): ?array {
		switch ( $key ) {
			case 'capability_tampered':
				$role = isset( $args[0] ) && is_string( $args[0] ) ? $args[0] : '';
				$cap  = isset( $args[1] ) && is_string( $args[1] ) ? $args[1] : '';
				return array(
					'key'      => $key,
					'subject'  => 'Capability tamper detected',
					'body'     => sprintf( 'Role "%s" regained capability "%s"; WP Sudo re-stripped it.', $role, $cap ),
					'identity' => $role . ':' . $cap,
					'scope'    => 'site',
				);

			case 'escalation_blocked':
				// arg[0] is the TARGET being granted/deleted, NOT the actor. The
				// hook carries no actor id, so enrich with the current user for
				// context (may be 0/unknown on some surfaces) and never imply the
				// target is the perpetrator.
				$target  = (int) ( $args[0] ?? 0 );
				$rule    = isset( $args[1] ) && is_string( $args[1] ) ? $args[1] : '';
				$surface = isset( $args[2] ) && is_string( $args[2] ) ? $args[2] : '';
				$actor   = (int) get_current_user_id();
				return array(
					'key'      => $key,
					'subject'  => 'Admin escalation blocked',
					'body'     => sprintf(
						'Blocked %s targeting user #%d via %s. Actor: %s.',
						$rule,
						$target,
						$surface,
						$actor > 0 ? '#' . $actor : 'unknown'
					),
					'identity' => $rule . ':' . $target,
					// A super-admin grant/deletion is a network-scope concern. On
					// multisite a super-admin *target* routes to the network admin
					// even under a generic user.delete/user.promote rule id.
					'scope'    => ( 'user.super_admin' === $rule || ( is_multisite() && $target > 0 && is_super_admin( $target ) ) ) ? 'network' : 'site',
				);

			case 'lockout':
				$user     = (int) ( $args[0] ?? 0 );
				$attempts = (int) ( $args[1] ?? 0 );
				$ip       = isset( $args[2] ) && is_string( $args[2] ) ? $args[2] : '';
				return array(
					'key'      => $key,
					'subject'  => 'Reauthentication lockout',
					'body'     => sprintf( 'User #%d locked out after %d failed attempts from %s.', $user, $attempts, $ip ),
					'identity' => $user . ':' . $ip,
					'scope'    => 'site',
				);

			case 'missing_builtin_rules':
				$missing = isset( $args[0] ) && is_array( $args[0] ) ? array_map( 'strval', $args[0] ) : array();
				// A removed network-scope rule (registry ids are network.*) concerns
				// the network admin; site rules the site admin.
				$missing_network = is_multisite() && (bool) array_filter(
					$missing,
					static fn( string $rule_id ): bool => str_starts_with( $rule_id, 'network' )
				);
				return array(
					'key'      => $key,
					'subject'  => 'Built-in gated rules missing',
					'body'     => 'A filter removed built-in gated rules: ' . implode( ', ', $missing ),
					'identity' => implode( ',', $missing ),
					'scope'    => $missing_network ? 'network' : 'site',
				);

			case 'role_drift':
				$report     = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : array();
				$principals = 0;
				$sig        = array();
				foreach ( ( is_array( $report['sites'] ?? null ) ? $report['sites'] : array() ) as $blog_id => $entry ) {
					$admins = array_map( 'intval', (array) ( $entry['administrators'] ?? array() ) );
					$gov    = array_map( 'intval', (array) ( $entry['governance'] ?? array() ) );
					sort( $admins );
					sort( $gov );
					$principals += count( $admins ) + count( $gov );
					$sig[]       = $blog_id . ':a=' . implode( ',', $admins ) . ':g=' . implode( ',', $gov );
				}
				$supers = array_map( 'intval', (array) ( $report['network']['super_admins'] ?? array() ) );
				sort( $supers );
				$principals += count( $supers );
				$rolemap     = is_array( $report['roles'] ?? null ) ? $report['roles'] : array();
				$roles       = array_keys( $rolemap );
				sort( $roles );
				// Signature carries each role's expected/actual hashes, not just the
				// slug, so a further modification of an already-drifted role (same
				// slug, new actual hash) produces a new identity and is not deduped.
				$role_sig = array();
				foreach ( $rolemap as $slug => $hashes ) {
					$role_sig[] = $slug . '=' . ( is_array( $hashes ) ? (string) ( $hashes['expected'] ?? '' ) . '/' . (string) ( $hashes['actual'] ?? '' ) : '' );
				}
				sort( $role_sig );
				$sig[] = 's=' . implode( ',', $supers ) . ':r=' . implode( ',', $role_sig );
				return array(
					'key'      => $key,
					'subject'  => 'Role/capability drift detected',
					'body'     => sprintf(
						'%d unauthorized privileged principal(s) and %d changed role definition(s) diverge from the trusted manifest%s. Review with "wp sudo manifest diff".',
						$principals,
						count( $roles ),
						$roles ? ' (roles: ' . implode( ', ', $roles ) . ')' : ''
					),
					// Dedup on the drift signature: a persistent, unremediated drift
					// must not re-alert on every (daily) sweep. It alerts once per
					// throttle window, and again only when the drift set changes.
					'identity' => 'role_drift:' . md5( implode( '|', $sig ) ),
					// A drifted super-admin set is a network-scope concern.
					'scope'    => empty( $supers ) ? 'site' : 'network',
				);

			case 'recovery_mode':
				$user = (int) ( $args[0] ?? 0 );
				return array(
					'key'      => $key,
					'subject'  => 'Recovery mode active',
					'body'     => sprintf( 'WP Sudo break-glass recovery mode is active (user #%d).', $user ),
					'identity' => (string) $user,
					'scope'    => 'site',
				);
		}

		return null;
	}
}

if ( ! function_exists( 'wp_sudo_critical_alert_bridge_flush' ) ) {
	/**
	 * Dispatch queued alerts, applying per-event dedupe and the hourly cap.
	 *
	 * @return void
	 */
	function wp_sudo_critical_alert_bridge_flush(): void {
		$queue = wp_sudo_critical_alert_bridge_queue( 'get' );
		if ( array() === $queue ) {
			return;
		}

		// Clear immediately so a second shutdown pass cannot re-send.
		wp_sudo_critical_alert_bridge_queue( 'reset' );

		$window     = (int) apply_filters( 'wp_sudo_critical_alert_throttle', HOUR_IN_SECONDS );
		$cap        = (int) apply_filters( 'wp_sudo_critical_alert_hourly_cap', 10 );
		$suppressed = 0;

		foreach ( $queue as $event ) {
			// Per-event dedupe (skip repeats of the same event+identity).
			if ( $window > 0 && ! wp_sudo_critical_alert_bridge_reserve( $event, $window ) ) {
				continue;
			}

			// Per-recipient hourly cap: network-scope events share a network-wide
			// counter, site-scope events a per-site one, so no single mailbox is
			// flooded and one busy site cannot starve another site's alerts.
			$network = ( isset( $event['scope'] ) && 'network' === $event['scope'] ) && is_multisite();
			if ( $cap > 0 && wp_sudo_critical_alert_bridge_count( false, $network ) >= $cap ) {
				++$suppressed;
				continue;
			}

			wp_sudo_critical_alert_bridge_dispatch( $event );
			wp_sudo_critical_alert_bridge_count( true, $network );
		}

		if ( $suppressed > 0 ) {
			$digest = array(
				'key'      => 'digest',
				'subject'  => 'Additional critical events suppressed',
				'body'     => sprintf(
					'%d further critical event(s) were suppressed this hour by the alert cap. Review the Sudo dashboard widget and activity log.',
					$suppressed
				),
				'identity' => 'digest',
				'scope'    => 'network',
			);

			// Throttle the digest itself so a per-request flood cannot emit one
			// digest per request. Never pass a non-positive window to reserve() (a
			// 0 TTL is a *permanent* transient); with dedupe disabled (window <= 0,
			// demo only) the digest intentionally flows every time.
			if ( $window <= 0 || wp_sudo_critical_alert_bridge_reserve( $digest, $window ) ) {
				wp_sudo_critical_alert_bridge_dispatch( $digest );
			}
		}
	}
}

if ( ! function_exists( 'wp_sudo_critical_alert_bridge_reserve' ) ) {
	/**
	 * Reserve a dedupe slot for an event; returns false if one is already held
	 * within the window (i.e. this event should be skipped). Network-scope
	 * events dedupe network-wide; others per-site.
	 *
	 * @param array<string, mixed> $event  Event.
	 * @param int                  $window Dedupe window in seconds.
	 * @return bool True if the caller may send; false if deduped.
	 */
	function wp_sudo_critical_alert_bridge_reserve( array $event, int $window ): bool {
		$key     = isset( $event['key'] ) ? (string) $event['key'] : '';
		$ident   = isset( $event['identity'] ) ? (string) $event['identity'] : '';
		$name    = '_wp_sudo_critical_alert_' . $key . '_' . md5( $ident );
		$network = ( isset( $event['scope'] ) && 'network' === $event['scope'] ) && is_multisite();

		if ( $network ) {
			if ( get_site_transient( $name ) ) {
				return false;
			}
			set_site_transient( $name, 1, $window );
			return true;
		}

		if ( get_transient( $name ) ) {
			return false;
		}
		set_transient( $name, 1, $window );
		return true;
	}
}

if ( ! function_exists( 'wp_sudo_critical_alert_bridge_count' ) ) {
	/**
	 * Read (or, when $increment is true, bump) the hourly alert counter used by
	 * the cap. The window opens on the first alert and rolls over an hour later,
	 * rather than being extended on every increment (which would let a slow
	 * trickle suppress alerts long after any real hourly burst). Network-scope
	 * events use a network-wide counter, site-scope events a per-site one, so the
	 * cap protects each recipient mailbox independently.
	 *
	 * @param bool $increment Whether to increment the counter.
	 * @param bool $network   Whether to use the network-wide counter (multisite).
	 * @return int Current count within the active window (after increment).
	 */
	function wp_sudo_critical_alert_bridge_count( bool $increment = false, bool $network = false ): int {
		$name = '_wp_sudo_critical_alert_count';
		$data = $network ? get_site_transient( $name ) : get_transient( $name );

		if ( ! is_array( $data ) || ! isset( $data['count'], $data['start'] ) ) {
			$data = array( 'count' => 0, 'start' => 0 );
		}

		// Roll the window over once a full hour has elapsed since it opened.
		if ( time() - (int) $data['start'] >= HOUR_IN_SECONDS ) {
			$data = array( 'count' => 0, 'start' => time() );
		}

		if ( $increment ) {
			$data['count'] = (int) $data['count'] + 1;
			if ( $network ) {
				set_site_transient( $name, $data, HOUR_IN_SECONDS );
			} else {
				set_transient( $name, $data, HOUR_IN_SECONDS );
			}
		}

		return (int) $data['count'];
	}
}

if ( ! function_exists( 'wp_sudo_critical_alert_bridge_dispatch' ) ) {
	/**
	 * Deliver one alert. A `wp_sudo_critical_alert_dispatch` filter that returns
	 * non-null fully replaces the default email (Slack/webhook/inline capture);
	 * the `wp_sudo_critical_alert_dispatched` action always fires for additive
	 * observers.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return void
	 */
	function wp_sudo_critical_alert_bridge_dispatch( array $event ): void {
		$handled = apply_filters( 'wp_sudo_critical_alert_dispatch', null, $event );

		if ( null === $handled ) {
			wp_sudo_critical_alert_bridge_email( $event );
		}

		do_action( 'wp_sudo_critical_alert_dispatched', $event );
	}
}

if ( ! function_exists( 'wp_sudo_critical_alert_bridge_email' ) ) {
	/**
	 * Send the default email for an event to the scope-appropriate recipient.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return bool wp_mail() result, or false when there is no recipient.
	 */
	function wp_sudo_critical_alert_bridge_email( array $event ): bool {
		$to = wp_sudo_critical_alert_bridge_recipient( $event );
		if ( '' === $to ) {
			return false;
		}

		$scope = isset( $event['scope'] ) ? (string) $event['scope'] : 'site';
		$home  = ( 'network' === $scope && is_multisite() ) ? network_home_url() : home_url();

		$subject = '[WP Sudo] ' . ( isset( $event['subject'] ) ? (string) $event['subject'] : 'Critical event' );
		$body    = ( isset( $event['body'] ) ? (string) $event['body'] : '' )
			. "\n\nSite: " . $home
			. "\nTime (UTC): " . gmdate( 'c' );

		return (bool) wp_mail( $to, $subject, $body );
	}
}

if ( ! function_exists( 'wp_sudo_critical_alert_bridge_recipient' ) ) {
	/**
	 * Resolve the recipient email for an event. Network-scope events default to
	 * the network admin email; site events to the site admin email. Filterable.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return string Recipient email (may be empty).
	 */
	function wp_sudo_critical_alert_bridge_recipient( array $event ): string {
		$scope = isset( $event['scope'] ) ? (string) $event['scope'] : 'site';

		if ( 'network' === $scope && is_multisite() ) {
			$default = (string) get_site_option( 'admin_email' );
		} else {
			$default = (string) get_option( 'admin_email' );
		}

		$to = apply_filters( 'wp_sudo_critical_alert_recipient', $default, $event );

		return is_string( $to ) ? $to : '';
	}
}

add_action( 'plugins_loaded', 'wp_sudo_critical_alert_bridge_register', 10, 0 );
