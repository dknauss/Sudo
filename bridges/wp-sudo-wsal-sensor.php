<?php
/**
 * WP Sudo ↔ WP Activity Log (WSAL) Sensor Bridge
 *
 * Optional bridge that maps WP Sudo audit hooks into WSAL events.
 * Drop this file into wp-content/mu-plugins/ to activate.
 *
 * @package WP_Sudo_Bridges
 * @version 1.1.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_sudo_wsal_bridge_available' ) ) {
	/**
	 * Check whether a compatible WSAL event API is available.
	 *
	 * @return bool
	 */
	function wp_sudo_wsal_bridge_available(): bool {
		if ( class_exists( '\WSAL\Controllers\Alert_Manager' ) ) {
			return method_exists( '\WSAL\Controllers\Alert_Manager', 'trigger_event' ) || method_exists( '\WSAL\Controllers\Alert_Manager', 'Trigger' );
		}

		return function_exists( 'wsal_log_event' );
	}
}

if ( ! function_exists( 'wp_sudo_wsal_bridge_event_payload' ) ) {
	/**
	 * Build structured WSAL payload data for a WP Sudo hook event.
	 *
	 * @param string $hook Hook name.
	 * @param array  $args Hook arguments.
	 * @return array<string, mixed>
	 */
	function wp_sudo_wsal_bridge_event_payload( string $hook, array $args ): array {
		$payload = array(
			'source' => 'wp-sudo',
			'hook'   => $hook,
		);

		switch ( $hook ) {
			case 'wp_sudo_activated':
				$payload['user_id']  = (int) ( $args[0] ?? 0 );
				$payload['expires']  = (int) ( $args[1] ?? 0 );
				$payload['duration'] = (int) ( $args[2] ?? 0 );
				break;

			case 'wp_sudo_deactivated':
				$payload['user_id'] = (int) ( $args[0] ?? 0 );
				break;

			case 'wp_sudo_reauth_failed':
			case 'wp_sudo_lockout':
				$payload['user_id']  = (int) ( $args[0] ?? 0 );
				$payload['attempts'] = (int) ( $args[1] ?? 0 );
				break;

			case 'wp_sudo_action_gated':
			case 'wp_sudo_action_blocked':
			case 'wp_sudo_action_allowed':
			case 'wp_sudo_action_passed':
				$payload['user_id'] = (int) ( $args[0] ?? 0 );
				$payload['rule_id'] = (string) ( $args[1] ?? '' );
				$payload['surface'] = (string) ( $args[2] ?? '' );
				break;

			case 'wp_sudo_action_replayed':
				$payload['user_id'] = (int) ( $args[0] ?? 0 );
				$payload['rule_id'] = (string) ( $args[1] ?? '' );
				break;

			case 'wp_sudo_capability_tampered':
				$payload['role']       = (string) ( $args[0] ?? '' );
				$payload['capability'] = (string) ( $args[1] ?? '' );
				break;

			case 'wp_sudo_policy_preset_applied':
				$payload['user_id']    = (int) ( $args[0] ?? 0 );
				$payload['preset_key'] = (string) ( $args[1] ?? '' );
				$payload['previous']   = is_array( $args[2] ?? null ) ? $args[2] : array();
				$payload['current']    = is_array( $args[3] ?? null ) ? $args[3] : array();
				$payload['is_network'] = (bool) ( $args[4] ?? false );
				break;

			case 'wp_sudo_escalation_blocked':
				$payload['user_id'] = (int) ( $args[0] ?? 0 );
				$payload['rule_id'] = (string) ( $args[1] ?? '' );
				$payload['surface'] = (string) ( $args[2] ?? '' );
				break;

			case 'wp_sudo_session_revoked':
				$payload['user_id']    = (int) ( $args[0] ?? 0 );
				$payload['revoker_id'] = (int) ( $args[1] ?? 0 );
				$payload['reason']     = (string) ( $args[2] ?? '' );
				$payload['blog_id']    = (int) ( $args[3] ?? 0 );
				break;

			case 'wp_sudo_recovery_mode_active':
				$payload['user_id'] = (int) ( $args[0] ?? 0 );
				break;

			case 'wp_sudo_capability_granted':
			case 'wp_sudo_capability_revoked':
				$payload['user_id']    = (int) ( $args[0] ?? 0 );
				$payload['capability'] = (string) ( $args[1] ?? '' );
				$payload['actor_id']   = (int) ( $args[2] ?? 0 );
				$payload['blog_id']    = (int) ( $args[3] ?? 0 );
				break;

			case 'wp_sudo_gated_actions_missing_builtin_rules':
				$payload['missing'] = array_values( array_map( 'strval', (array) ( $args[0] ?? array() ) ) );
				break;

			case 'wp_sudo_rule_regex_error':
				$payload['pattern']     = (string) ( $args[0] ?? '' );
				$payload['subject']     = (string) ( $args[1] ?? '' );
				$payload['fail_closed'] = (bool) ( $args[2] ?? false );
				break;
		}

		return $payload;
	}
}

if ( ! function_exists( 'wp_sudo_wsal_bridge_emit' ) ) {
	/**
	 * Emit a mapped WP Sudo event to WSAL.
	 *
	 * @param int                 $event_id WSAL event ID.
	 * @param array<string, mixed> $payload Event payload.
	 * @return void
	 */
	function wp_sudo_wsal_bridge_emit( int $event_id, array $payload ): void {
		if ( class_exists( '\WSAL\Controllers\Alert_Manager' ) ) {
			if ( method_exists( '\WSAL\Controllers\Alert_Manager', 'trigger_event' ) ) {
				\WSAL\Controllers\Alert_Manager::trigger_event( $event_id, $payload );
				return;
			}

			if ( method_exists( '\WSAL\Controllers\Alert_Manager', 'Trigger' ) ) {
				\WSAL\Controllers\Alert_Manager::Trigger( $event_id, $payload );
				return;
			}
		}

		if ( function_exists( 'wsal_log_event' ) ) {
			wsal_log_event( $event_id, $payload );
		}
	}
}

if ( ! function_exists( 'wp_sudo_wsal_bridge_should_emit' ) ) {
	/**
	 * Decide whether a throttled event should emit on this fire.
	 *
	 * Some WP Sudo hooks (notably wp_sudo_recovery_mode_active) fire on every
	 * matching request by design, so SIEM/stream listeners can observe the
	 * complete usage pattern. A table-backed activity log would flood, so —
	 * mirroring the bundled Event_Recorder's one-row-per-user-per-hour
	 * sampling — the bridge throttles such events per user.
	 *
	 * @param string               $hook     Hook name.
	 * @param array<int, mixed>    $args     Hook arguments (arg 0 is the user ID).
	 * @param int                  $throttle Throttle window in seconds.
	 * @return bool True if the event should emit; false to skip this fire.
	 */
	function wp_sudo_wsal_bridge_should_emit( string $hook, array $args, int $throttle ): bool {
		if ( $throttle <= 0 ) {
			return true;
		}

		$user_id = (int) ( $args[0] ?? 0 );
		$key     = '_wp_sudo_wsal_throttle_' . $hook . '_' . $user_id;

		if ( get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, 1, $throttle );

		return true;
	}
}

if ( ! wp_sudo_wsal_bridge_available() ) {
	return;
}

/**
 * Map WP Sudo hooks to WSAL event IDs.
 *
 * IDs use a high custom range to avoid conflicts with built-in WSAL IDs.
 *
 * @var array<string, array{event_id: int, accepted_args: int, throttle?: int}>
 */
$wp_sudo_wsal_event_map = array(
	'wp_sudo_activated'           => array( 'event_id' => 1900001, 'accepted_args' => 3 ),
	'wp_sudo_deactivated'         => array( 'event_id' => 1900002, 'accepted_args' => 1 ),
	'wp_sudo_reauth_failed'       => array( 'event_id' => 1900003, 'accepted_args' => 2 ),
	'wp_sudo_lockout'             => array( 'event_id' => 1900004, 'accepted_args' => 2 ),
	'wp_sudo_action_gated'        => array( 'event_id' => 1900005, 'accepted_args' => 3 ),
	'wp_sudo_action_blocked'      => array( 'event_id' => 1900006, 'accepted_args' => 3 ),
	'wp_sudo_action_allowed'      => array( 'event_id' => 1900007, 'accepted_args' => 3 ),
	'wp_sudo_action_passed'       => array( 'event_id' => 1900008, 'accepted_args' => 3 ),
	'wp_sudo_action_replayed'     => array( 'event_id' => 1900009, 'accepted_args' => 2 ),
	'wp_sudo_capability_tampered' => array( 'event_id' => 1900010, 'accepted_args' => 2 ),
	'wp_sudo_policy_preset_applied' => array( 'event_id' => 1900011, 'accepted_args' => 5 ),
	// v1.1.0 security/governance events. Recovery-mode usage is throttled
	// per user (see wp_sudo_wsal_bridge_should_emit) to avoid flooding a
	// table-backed log; the diagnostic-only wp_sudo_inert_governance_mode_detected
	// hook is intentionally not mapped.
	'wp_sudo_escalation_blocked'  => array( 'event_id' => 1900012, 'accepted_args' => 3 ),
	'wp_sudo_session_revoked'     => array( 'event_id' => 1900013, 'accepted_args' => 4 ),
	'wp_sudo_recovery_mode_active' => array( 'event_id' => 1900014, 'accepted_args' => 1, 'throttle' => HOUR_IN_SECONDS ),
	'wp_sudo_capability_granted'  => array( 'event_id' => 1900015, 'accepted_args' => 4 ),
	'wp_sudo_capability_revoked'  => array( 'event_id' => 1900016, 'accepted_args' => 4 ),
	'wp_sudo_gated_actions_missing_builtin_rules' => array( 'event_id' => 1900017, 'accepted_args' => 1 ),
	'wp_sudo_rule_regex_error'    => array( 'event_id' => 1900018, 'accepted_args' => 3 ),
);

foreach ( $wp_sudo_wsal_event_map as $wp_sudo_hook => $wp_sudo_meta ) {
	/** @psalm-suppress HookNotFound WP Sudo audit hook names are declared by the event map above. */
	add_action(
		$wp_sudo_hook,
		static function ( ...$args ) use ( $wp_sudo_hook, $wp_sudo_meta ): void {
			$throttle = (int) ( $wp_sudo_meta['throttle'] ?? 0 );
			if ( ! wp_sudo_wsal_bridge_should_emit( $wp_sudo_hook, $args, $throttle ) ) {
				return;
			}

			$payload = wp_sudo_wsal_bridge_event_payload( $wp_sudo_hook, $args );
			wp_sudo_wsal_bridge_emit( (int) $wp_sudo_meta['event_id'], $payload );
		},
		10,
		(int) $wp_sudo_meta['accepted_args']
	);
}
