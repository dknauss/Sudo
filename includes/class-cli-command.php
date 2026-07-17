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
	 * Generate or diff the role/capability lockdown manifest (#179).
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Either `generate` (snapshot the current trusted state to the manifest
	 * file) or `diff` (report drift of the current state from the manifest).
	 *
	 * [--path=<file>]
	 * : Manifest file path. Defaults to the WP_SUDO_ROLE_MANIFEST constant.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sudo manifest generate --path=/etc/wp-sudo-manifest.json
	 *     wp sudo manifest diff
	 *
	 * @param array<int, string>   $args       Positional args: [0] => action.
	 * @param array<string, mixed> $assoc_args Assoc args.
	 * @return void
	 */
	public function manifest( array $args, array $assoc_args ): void {
		$action = $args[0] ?? '';
		$path   = isset( $assoc_args['path'] ) && is_string( $assoc_args['path'] ) && '' !== $assoc_args['path']
			? $assoc_args['path']
			: Role_Manifest::configured_path();

		if ( null === $path || '' === $path ) {
			\WP_CLI::error( 'No manifest path. Pass --path=<file> or define WP_SUDO_ROLE_MANIFEST.' );
			return;
		}

		if ( 'generate' === $action ) {
			$this->manifest_generate( $path );
			return;
		}

		if ( 'diff' === $action ) {
			$this->manifest_diff( $path );
			return;
		}

		\WP_CLI::error( sprintf( "Unknown manifest action '%s'. Use 'generate' or 'diff'.", (string) $action ) );
	}

	/**
	 * Snapshot the current trusted state to the manifest file.
	 *
	 * @param string $path Destination path.
	 * @return void
	 */
	private function manifest_generate( string $path ): void {
		// Watch the administrator role definition by default.
		$state    = Role_Audit::collect_current_state( array( 'privileged_roles' => array( 'administrator' => '' ) ) );
		$document = Role_Manifest::build_document( $state, gmdate( 'c' ) );

		if ( ! Role_Manifest::write( $path, $document ) ) {
			\WP_CLI::error( sprintf( 'Failed to write manifest to %s', $path ) );
			return;
		}

		\WP_CLI::success( sprintf( 'Wrote role/capability manifest to %s', $path ) );
	}

	/**
	 * Report drift of the current state from the manifest (non-firing).
	 *
	 * @param string $path Manifest path.
	 * @return void
	 */
	private function manifest_diff( string $path ): void {
		$manifest = Role_Manifest::load( $path );

		if ( null === $manifest ) {
			\WP_CLI::error( sprintf( 'Manifest missing or unreadable: %s', $path ) );
			return;
		}

		$report = Role_Audit::diff( $manifest, Role_Audit::collect_current_state( $manifest ) );

		if ( ! $report['has_drift'] ) {
			\WP_CLI::success( 'No role/capability drift from the manifest.' );
			return;
		}

		foreach ( $report['sites'] as $blog_id => $entry ) {
			foreach ( $entry['administrators'] as $uid ) {
				\WP_CLI::log( sprintf( 'Unauthorized administrator: user %d (site %d)', $uid, $blog_id ) );
			}
			foreach ( $entry['governance'] as $uid ) {
				\WP_CLI::log( sprintf( 'Unauthorized governance-cap holder: user %d (site %d)', $uid, $blog_id ) );
			}
		}
		foreach ( $report['network']['super_admins'] as $uid ) {
			\WP_CLI::log( sprintf( 'Unauthorized super admin: user %d', $uid ) );
		}
		foreach ( array_keys( $report['roles'] ) as $role ) {
			\WP_CLI::log( sprintf( 'Privileged role definition changed: %s', $role ) );
		}

		\WP_CLI::error( 'Role/capability drift detected.' );
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
