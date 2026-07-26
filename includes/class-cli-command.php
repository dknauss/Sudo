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
	 * [--user=<id|login>]
	 * : Target user. A purely numeric value always resolves as a user ID (even
	 *   if some user's login happens to be numeric); any other value is looked
	 *   up as a login. Defaults to the current WP-CLI user context.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sudo status --user=1
	 *     wp sudo status --user=jane
	 *
	 * @param array<int, string>   $args       Positional args (unused).
	 * @param array<string, mixed> $assoc_args Assoc args.
	 * @return void
	 */
	public function status( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$user_id = $this->resolve_user_id( $assoc_args );

		if ( $user_id <= 0 ) {
			\WP_CLI::error( 'No target user. Pass --user=<id|login> or run with a WP-CLI --user context.' );
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
	 * [--user=<id|login>]
	 * : Target user. A purely numeric value always resolves as a user ID (even
	 *   if some user's login happens to be numeric); any other value is looked
	 *   up as a login. Defaults to the current WP-CLI user context.
	 *
	 * [--all]
	 * : Revoke all active sudo sessions.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sudo revoke --user=1
	 *     wp sudo revoke --user=jane
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
			\WP_CLI::error( 'No target user. Pass --user=<id|login> or run with a WP-CLI --user context.' );
		}

		Sudo_Session::deactivate( $user_id );
		\WP_CLI::success( sprintf( 'Revoked sudo session for user %d.', $user_id ) );
	}

	/**
	 * Clear an active reauth lockout for a user (#280).
	 *
	 * There is no in-product (web/admin) way to lift a reauth lockout — that
	 * is deliberate, since the lockout defends against exhaustive password
	 * guessing. This command is an OUT-OF-BAND escape hatch for an operator
	 * who already holds WP-CLI (shell / hosting-config) access; it is not
	 * reachable from any web, REST, AJAX, or admin-UI surface. Registration
	 * of the whole `wp sudo` command tree is itself gated on
	 * `defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' )`, inline in
	 * Plugin::init() at the `\WP_CLI::add_command( 'sudo', ... )` call.
	 *
	 * Clears the whole lockout episode via
	 * Sudo_Session::clear_reauth_lockout(): the per-user hard lockout, and
	 * every per-(ip, user) rolling failure window that would otherwise
	 * re-trigger a fresh lockout on the next wrong password — including from
	 * an IP other than the one that submitted the threshold attempt, and on
	 * multisite from a blog other than the one running this command.
	 *
	 * A cleared lockout is a bounded escape hatch, not full remediation — if
	 * an attacker still holds the target user's WordPress LOGIN session, they
	 * can re-trigger a fresh lockout immediately. Note that
	 * `wp sudo revoke` is NOT the remedy for that: it calls
	 * Sudo_Session::deactivate(), which clears this plugin's own proof and
	 * session meta and never touches WordPress login sessions, so the attacker
	 * keeps their cookie and can simply reauthenticate. Destroy the login
	 * sessions (`wp user session destroy <user> --all`) or reset the password.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<id|login>]
	 * : Target user. A purely numeric value always resolves as a user ID
	 *   (even if some user's login happens to be numeric); any other value
	 *   is looked up as a login. Defaults to the current WP-CLI user context.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sudo unlock --user=1
	 *     wp sudo unlock --user=jane
	 *
	 * @since TBD
	 *
	 * @param array<int, string>   $args       Positional args (unused).
	 * @param array<string, mixed> $assoc_args Assoc args.
	 * @return void
	 */
	public function unlock( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$user_id = $this->resolve_user_id( $assoc_args );

		if ( $user_id <= 0 ) {
			\WP_CLI::error( 'No target user. Pass --user=<id|login> or run with a WP-CLI --user context.' );
		}

		// Both captured BEFORE anything is cleared. has_unexpired_lockout() and
		// has_failure_state() are pure reads (unlike is_locked_out(), which
		// auto-resets once the timestamp is in the past), so they reflect what
		// was actually true at invocation time rather than what this command
		// just did.
		$was_locked = Sudo_Session::has_unexpired_lockout( $user_id );
		$had_state  = Sudo_Session::has_failure_state( $user_id );

		// No per-user tracking to clear — but still invalidate the IP-scoped
		// generation, and do NOT delete any counters. The generation bump is the
		// only lever that reaches windows this process cannot see or enumerate:
		// they are per-blog transients keyed by a hash of (ip, user, epoch),
		// while every predicate above reads network-global user meta. A lockout
		// raised on subsite A and then orphaned by a login on subsite B —
		// activate() clears the global counters and pointers but cannot reach
		// A's transients — reads as "nothing tracked" here while still blocking
		// the user on A. Refusing to act on that reading would leave the escape
		// hatch unable to open the one door it exists for. The bump costs one
		// monotonic counter write and discards no per-user counter, so it is
		// safe when there was in fact nothing to invalidate.
		if ( ! $was_locked && ! $had_state ) {
			Sudo_Session::clear_ip_failure_generation( $user_id );

			// Audited the same as any other branch that discards state (see the
			// wp_sudo_lockout_cleared docblock below): the epoch bump destroys
			// real per-IP evidence this process cannot otherwise see, even
			// though no per-user counter was touched. $was_locked is false.
			do_action( 'wp_sudo_lockout_cleared', $user_id, false );

			\WP_CLI::log(
				sprintf(
					'No active reauth lockout for user %d — invalidated any residual per-IP failure windows (including ones raised on other sites).',
					$user_id
				)
			);
			return;
		}

		// Full clear: per-user counters AND the generation bump.
		Sudo_Session::clear_reauth_lockout( $user_id );

		/**
		 * Fires when an operator discards reauth failure state via WP-CLI.
		 *
		 * Fires on every branch that actually mutates state: a full lockout
		 * clear, a sub-threshold counter clear, and — see the earlier call site
		 * above — a "nothing tracked" outcome that still bumps the per-IP
		 * failure generation. Does NOT fire only when the command left the
		 * user completely untouched, which never happens as of #280's review
		 * follow-up: every reachable branch of unlock() calls this hook.
		 * Subscribers that need to tell a true lockout apart from a
		 * sub-threshold/generation-only clear must read $was_locked — the hook
		 * firing does NOT by itself mean the user was locked out.
		 *
		 * @since TBD
		 *
		 * @param int  $user_id    The unlocked user.
		 * @param bool $was_locked True only when a hard lockout was active at
		 *                         invocation; false when the command cleared
		 *                         pre-lockout counters or bumped the failure
		 *                         generation instead.
		 */
		do_action( 'wp_sudo_lockout_cleared', $user_id, $was_locked );

		// Not locked, but counters existed — clearing them discards a live
		// brute-force defense (accumulated failures, an active throttle, or an
		// expired-but-uncleared lockout's rolling window). Say so rather than
		// reporting a bare no-op.
		if ( ! $was_locked ) {
			\WP_CLI::log(
				sprintf(
					'No active reauth lockout for user %d — cleared pre-lockout failure counters (accumulated attempts, throttle, and any stale rolling window).',
					$user_id
				)
			);
			return;
		}

		\WP_CLI::success(
			sprintf(
				'Cleared reauth lockout for user %1$d. If an attacker still holds this user\'s WordPress login session, the lockout can retrigger — destroy their login sessions (`wp user session destroy %1$d --all`) or reset the password. Note `wp sudo revoke` only clears this plugin\'s own session, not the WordPress login.',
				$user_id
			)
		);
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
	 * [--manifest-path=<file>]
	 * : Manifest file path. Defaults to the WP_SUDO_ROLE_MANIFEST constant.
	 *   (Named `--manifest-path`, not `--path`, because `--path` is a WP-CLI
	 *   global parameter for the WordPress install directory and would be consumed
	 *   before this command on a non-strict-args install.)
	 *
	 * ## EXAMPLES
	 *
	 *     wp sudo manifest generate --manifest-path=/etc/wp-sudo-manifest.json
	 *     wp sudo manifest diff
	 *
	 * ## NOTES
	 *
	 * Multisite scope (MVP): generate and diff audit only the current blog
	 * (the one resolved from --url / the request context). Network-wide subsite
	 * privilege drift is not yet swept in a single run — audit each site by
	 * invoking the command per --url. A network-wide sweep is tracked as a
	 * follow-up.
	 *
	 * @param array<int, string>   $args       Positional args: [0] => action.
	 * @param array<string, mixed> $assoc_args Assoc args.
	 * @return void
	 */
	public function manifest( array $args, array $assoc_args ): void {
		$action = $args[0] ?? '';
		$path   = isset( $assoc_args['manifest-path'] ) && is_string( $assoc_args['manifest-path'] ) && '' !== $assoc_args['manifest-path']
			? $assoc_args['manifest-path']
			: Role_Manifest::configured_path();

		if ( null === $path || '' === $path ) {
			\WP_CLI::error( 'No manifest path. Pass --manifest-path=<file> or define WP_SUDO_ROLE_MANIFEST.' );
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
		// Watch every role's definition, not just administrator, so a privileged
		// primitive added to a non-admin role (e.g. manage_options on editor) is
		// captured in the trusted baseline and surfaces as drift on later diffs.
		$state    = Role_Audit::collect_current_state( array( 'privileged_roles' => Role_Audit::default_watched_roles() ) );
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
	 * Accepts `--user=<id|login>`: a **digits-only** value always resolves as a
	 * user ID (matching pre-existing behavior — a non-numeric string previously
	 * cast to 0 via `(int)` and was already treated as "no target user"), any
	 * other value is looked up as a login.
	 *
	 * The digits-only test is `ctype_digit()`, deliberately narrower than
	 * `is_numeric()`. `is_numeric()` also accepts floats, exponent notation,
	 * and leading whitespace, so a legitimate login of `1.5` or `1e3` would
	 * cast to user 1 or 1000 and the command would mutate the WRONG ACCOUNT —
	 * unacceptable for `revoke` and `unlock`. A string check rather than an
	 * `is_int()` type check is still correct here because a WP-CLI assoc-arg
	 * value is never a PHP int — see GB-CLI-ASSOC in docs/upstream-sources.md.
	 *
	 * @since TBD Accepts a login, not just a numeric ID (#280).
	 *
	 * @param array<string, mixed> $assoc_args Command assoc args.
	 * @return int Positive user ID or 0.
	 */
	private function resolve_user_id( array $assoc_args ): int {
		if ( isset( $assoc_args['user'] ) ) {
			$value = $assoc_args['user'];

			// A bare `--user` (or `--no-user`) arrives as a bool, which would
			// otherwise stringify to '1'/'' and silently target user 1 or a
			// login of ''. No target was named, so resolve nothing.
			if ( is_bool( $value ) || ! is_scalar( $value ) ) {
				return 0;
			}

			if ( is_int( $value ) || ctype_digit( (string) $value ) ) {
				return max( 0, (int) $value );
			}

			$user = get_user_by( 'login', (string) $value );
			return $user ? (int) $user->ID : 0;
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
