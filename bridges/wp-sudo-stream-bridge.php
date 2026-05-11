<?php
/**
 * WP Sudo ↔ Stream Bridge
 *
 * Optional bridge that maps WP Sudo audit hooks into Stream records.
 * Drop this file into wp-content/mu-plugins/ to activate.
 *
 * @package WP_Sudo_Bridges
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_sudo_stream_bridge_available' ) ) {
	/**
	 * Check whether a compatible Stream logging API is available.
	 *
	 * @return bool
	 */
	function wp_sudo_stream_bridge_available(): bool {
		if ( ! function_exists( 'wp_stream_get_instance' ) ) {
			return false;
		}

		/** @psalm-suppress UndefinedFunction */
		$stream = wp_stream_get_instance();

		return is_object( $stream )
			&& isset( $stream->log )
			&& is_object( $stream->log )
			&& method_exists( $stream->log, 'log' );
	}
}

if ( ! function_exists( 'wp_sudo_stream_bridge_record_data' ) ) {
	/**
	 * Build Stream record data for a WP Sudo hook event.
	 *
	 * @param string $hook Hook name.
	 * @param array  $args Hook arguments.
	 * @return array{connector:string,message:string,args:array<string,mixed>,object_id:int,context:string,action:string,user_id:int}
	 */
	function wp_sudo_stream_bridge_record_data( string $hook, array $args ): array {
		$record = array(
			'connector' => 'wp_sudo',
			'message'   => 'WP Sudo event',
			'args'      => array(
				'source' => 'wp-sudo',
				'hook'   => $hook,
			),
			'object_id' => 0,
			'context'   => 'wp_sudo',
			'action'    => 'event',
			'user_id'   => 0,
		);

		switch ( $hook ) {
			case 'wp_sudo_activated':
				$record['message']            = 'WP Sudo session activated';
				$record['action']             = 'activated';
				$record['user_id']            = (int) ( $args[0] ?? 0 );
				$record['object_id']          = $record['user_id'];
				$record['args']['user_id']    = $record['user_id'];
				$record['args']['expires']    = (int) ( $args[1] ?? 0 );
				$record['args']['duration']   = (int) ( $args[2] ?? 0 );
				break;

			case 'wp_sudo_deactivated':
				$record['message']            = 'WP Sudo session deactivated';
				$record['action']             = 'deactivated';
				$record['user_id']            = (int) ( $args[0] ?? 0 );
				$record['object_id']          = $record['user_id'];
				$record['args']['user_id']    = $record['user_id'];
				break;

			case 'wp_sudo_reauth_failed':
				$record['message']            = 'WP Sudo reauthentication failed';
				$record['action']             = 'reauth_failed';
				$record['user_id']            = (int) ( $args[0] ?? 0 );
				$record['object_id']          = $record['user_id'];
				$record['args']['user_id']    = $record['user_id'];
				$record['args']['attempts']   = (int) ( $args[1] ?? 0 );
				break;

			case 'wp_sudo_lockout':
				$record['message']            = 'WP Sudo lockout triggered';
				$record['action']             = 'lockout';
				$record['user_id']            = (int) ( $args[0] ?? 0 );
				$record['object_id']          = $record['user_id'];
				$record['args']['user_id']    = $record['user_id'];
				$record['args']['attempts']   = (int) ( $args[1] ?? 0 );
				break;

			case 'wp_sudo_action_gated':
			case 'wp_sudo_action_blocked':
			case 'wp_sudo_action_allowed':
			case 'wp_sudo_action_passed':
				$record['message']            = 'WP Sudo policy decision';
				$record['action']             = str_replace( 'wp_sudo_action_', '', $hook );
				$record['user_id']            = (int) ( $args[0] ?? 0 );
				$record['object_id']          = $record['user_id'];
				$record['args']['user_id']    = $record['user_id'];
				$record['args']['rule_id']    = (string) ( $args[1] ?? '' );
				$record['args']['surface']    = (string) ( $args[2] ?? '' );
				break;

			case 'wp_sudo_action_replayed':
				$record['message']            = 'WP Sudo action replayed';
				$record['action']             = 'replayed';
				$record['user_id']            = (int) ( $args[0] ?? 0 );
				$record['object_id']          = $record['user_id'];
				$record['args']['user_id']    = $record['user_id'];
				$record['args']['rule_id']    = (string) ( $args[1] ?? '' );
				break;

			case 'wp_sudo_capability_tampered':
				$record['message']              = 'WP Sudo capability tamper corrected';
				$record['action']               = 'capability_tampered';
				$record['args']['role']         = (string) ( $args[0] ?? '' );
				$record['args']['capability']   = (string) ( $args[1] ?? '' );
				break;

			case 'wp_sudo_policy_preset_applied':
				$record['message']               = 'WP Sudo policy preset applied';
				$record['action']                = 'policy_preset_applied';
				$record['user_id']               = (int) ( $args[0] ?? 0 );
				$record['object_id']             = $record['user_id'];
				$record['args']['user_id']       = $record['user_id'];
				$record['args']['preset_key']    = (string) ( $args[1] ?? '' );
				$record['args']['previous']      = is_array( $args[2] ?? null ) ? $args[2] : array();
				$record['args']['current']       = is_array( $args[3] ?? null ) ? $args[3] : array();
				$record['args']['is_network']    = (bool) ( $args[4] ?? false );
				break;
		}

		return $record;
	}
}

if ( ! function_exists( 'wp_sudo_stream_bridge_emit' ) ) {
	/**
	 * Emit a mapped WP Sudo event to Stream.
	 *
	 * @param string $hook Hook name.
	 * @param array  $args Hook arguments.
	 * @return void
	 */
	function wp_sudo_stream_bridge_emit( string $hook, array $args ): void {
		if ( ! wp_sudo_stream_bridge_available() ) {
			return;
		}

		/** @psalm-suppress UndefinedFunction */
		$stream = wp_stream_get_instance();
		if ( ! is_object( $stream ) || ! isset( $stream->log ) || ! is_object( $stream->log ) || ! method_exists( $stream->log, 'log' ) ) {
			return;
		}

		$record = wp_sudo_stream_bridge_record_data( $hook, $args );

		$stream->log->log(
			$record['connector'],
			$record['message'],
			$record['args'],
			$record['object_id'],
			$record['context'],
			$record['action'],
			$record['user_id']
		);
	}
}

if ( ! function_exists( 'wp_sudo_stream_bridge_register' ) ) {
	/**
	 * Register WP Sudo hook listeners for Stream logging.
	 *
	 * @return void
	 */
	function wp_sudo_stream_bridge_register(): void {
		static $registered = false;

		if ( $registered || ! wp_sudo_stream_bridge_available() ) {
			return;
		}

		/**
		 * @var array<string, int> $hook_args_map
		 */
		$hook_args_map = array(
			'wp_sudo_activated'           => 3,
			'wp_sudo_deactivated'         => 1,
			'wp_sudo_reauth_failed'       => 2,
			'wp_sudo_lockout'             => 2,
			'wp_sudo_action_gated'        => 3,
			'wp_sudo_action_blocked'      => 3,
			'wp_sudo_action_allowed'      => 3,
			'wp_sudo_action_passed'       => 3,
			'wp_sudo_action_replayed'     => 2,
			'wp_sudo_capability_tampered' => 2,
			'wp_sudo_policy_preset_applied' => 5,
		);

		foreach ( $hook_args_map as $hook => $accepted_args ) {
			/** @psalm-suppress HookNotFound */
			add_action(
				$hook,
				static function ( ...$args ) use ( $hook ): void {
					wp_sudo_stream_bridge_emit( $hook, $args );
				},
				10,
				$accepted_args
			);
		}

		$registered = true;
	}
}

if ( wp_sudo_stream_bridge_available() ) {
	wp_sudo_stream_bridge_register();
} else {
	add_action( 'plugins_loaded', 'wp_sudo_stream_bridge_register', 20, 0 );
}
