<?php
/**
 * WP Sudo ↔ WP Activity Log (WSAL) Sensor Bridge
 *
 * Optional bridge that maps WP Sudo audit hooks into WSAL events.
 * Drop this file into wp-content/mu-plugins/ to activate.
 *
 * @package WP_Sudo_Bridges
 * @version 1.0.0
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

if ( ! wp_sudo_wsal_bridge_available() ) {
	return;
}

/**
 * Map WP Sudo hooks to WSAL event IDs.
 *
 * IDs use a high custom range to avoid conflicts with built-in WSAL IDs.
 *
 * @var array<string, array{event_id: int, accepted_args: int}>
 */
$wp_sudo_wsal_event_map = array(
	'wp_sudo_activated'           => array( 'event_id' => 1900001, 'accepted_args' => 3 ),
	'wp_sudo_deactivated'         => array( 'event_id' => 1900002, 'accepted_args' => 1 ),
	'wp_sudo_reauth_failed'       => array( 'event_id' => 1900003, 'accepted_args' => 2 ),
	'wp_sudo_lockout'             => array( 'event_id' => 1900004, 'accepted_args' => 2 ),
	'wp_sudo_action_gated'        => array( 'event_id' => 1900005, 'accepted_args' => 3 ),
	'wp_sudo_action_blocked'      => array( 'event_id' => 1900006, 'accepted_args' => 3 ),
	'wp_sudo_action_allowed'      => array( 'event_id' => 1900007, 'accepted_args' => 3 ),
	'wp_sudo_action_replayed'     => array( 'event_id' => 1900008, 'accepted_args' => 2 ),
	'wp_sudo_capability_tampered' => array( 'event_id' => 1900009, 'accepted_args' => 2 ),
);

foreach ( $wp_sudo_wsal_event_map as $hook => $meta ) {
	add_action(
		$hook,
		static function ( ...$args ) use ( $hook, $meta ): void {
			$payload = wp_sudo_wsal_bridge_event_payload( $hook, $args );
			wp_sudo_wsal_bridge_emit( (int) $meta['event_id'], $payload );
		},
		10,
		(int) $meta['accepted_args']
	);
}
