<?php
/**
 * WP-CLI command: wp sudo.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Operator-facing WP-CLI commands for sudo session management.
 *
 * @since 2.12.0
 */
class CLI_Command {

	/**
	 * Show sudo session status for the target user.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<id>]
	 * : User ID to inspect. Defaults to the current WP-CLI user context.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sudo status --user=1
	 *
	 * @param array<int, string>   $args       Positional args (unused).
	 * @param array<string, mixed> $assoc_args Assoc args.
	 * @return void
	 */
	public function status( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$user_id = $this->resolve_user_id( $assoc_args );

		if ( $user_id <= 0 ) {
			\WP_CLI::error( 'No target user. Pass --user=<id> or run with a WP-CLI --user context.' );
		}

		$remaining = Sudo_Session::time_remaining( $user_id );

		if ( $remaining > 0 ) {
			\WP_CLI::success(
				sprintf(
					'Sudo session expiry is in the future for user %d (%d seconds remaining); token binding cannot be verified from WP-CLI.',
					$user_id,
					$remaining
				)
			);
			return;
		}

		\WP_CLI::log( sprintf( 'No active sudo session for user %d.', $user_id ) );
	}

	/**
	 * Revoke sudo sessions.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<id>]
	 * : User ID to revoke. Defaults to current WP-CLI user context.
	 *
	 * [--all]
	 * : Revoke all active sudo sessions.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sudo revoke --user=1
	 *     wp sudo revoke --all
	 *
	 * @param array<int, string>   $args       Positional args (unused).
	 * @param array<string, mixed> $assoc_args Assoc args.
	 * @return void
	 */
	public function revoke( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( $this->is_flag_enabled( $assoc_args, 'all' ) ) {
			$revoked = Sudo_Session::revoke_all_active_sessions();

			if ( 0 === $revoked ) {
				\WP_CLI::log( 'No active sudo sessions found.' );
				return;
			}

			\WP_CLI::success( sprintf( 'Revoked %d sudo session(s).', $revoked ) );
			return;
		}

		$user_id = $this->resolve_user_id( $assoc_args );
		if ( $user_id <= 0 ) {
			\WP_CLI::error( 'No target user. Pass --user=<id> or run with a WP-CLI --user context.' );
		}

		Sudo_Session::deactivate( $user_id );
		\WP_CLI::success( sprintf( 'Revoked sudo session for user %d.', $user_id ) );
	}

	/**
	 * Resolve target user ID from assoc args or CLI auth context.
	 *
	 * @param array<string, mixed> $assoc_args Command assoc args.
	 * @return int Positive user ID or 0.
	 */
	private function resolve_user_id( array $assoc_args ): int {
		if ( isset( $assoc_args['user'] ) && is_scalar( $assoc_args['user'] ) ) {
			$user_id = (int) $assoc_args['user'];
			return max( 0, $user_id );
		}

		return (int) get_current_user_id();
	}

	/**
	 * Check if a CLI flag is enabled.
	 *
	 * @param array<string, mixed> $assoc_args Command assoc args.
	 * @param string               $flag       Flag name.
	 * @return bool
	 */
	private function is_flag_enabled( array $assoc_args, string $flag ): bool {
		if ( ! array_key_exists( $flag, $assoc_args ) ) {
			return false;
		}

		$value = $assoc_args[ $flag ];

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) ) {
			return 1 === $value;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return false;
	}
}
