<?php
/**
 * Public helper API for plugin and theme integrations.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Public_API
 *
 * Provides lightweight public helpers for third-party integrations that
 * need to require an active sudo session without defining full Gate rules.
 *
 * @since 2.12.0
 */
class Public_API {

	/**
	 * Default rule ID used by wp_sudo_require().
	 *
	 * @var string
	 */
	public const DEFAULT_RULE_ID = 'public_api.require';

	/**
	 * Check whether a user currently has an active sudo session.
	 *
	 * Grace-window sessions are treated as active for gating purposes.
	 *
	 * Only the current request's authenticated user can be checked: sudo
	 * sessions are bound to a per-browser cookie, and Sudo_Session::resolve_valid_proof()
	 * rejects any call where $user_id does not match get_current_user_id().
	 * Passing a different user ID therefore always returns false.
	 *
	 * @since 2.12.0
	 *
	 * @param int|null $user_id Optional user ID. Defaults to current user.
	 *                          Any non-null value other than the current user
	 *                          causes this method to return false.
	 * @return bool
	 */
	public static function check( ?int $user_id = null ): bool {
		$target_user_id = null === $user_id ? get_current_user_id() : $user_id;

		if ( $target_user_id <= 0 ) {
			return false;
		}

		// Explicit cross-user isolation guard: sudo sessions are cookie-bound
		// to the current request's authenticated user. Without this guard the
		// isolation relied solely on the proof resolver rejecting cross-user calls;
		// a future fast-path refactor to Sudo_Session could accidentally open a
		// cross-user read path. Make the invariant explicit at this boundary.
		if ( get_current_user_id() !== $target_user_id ) {
			return false;
		}

		return Sudo_Session::is_active( $target_user_id ) || Sudo_Session::is_within_grace( $target_user_id );
	}

	/**
	 * Require an active sudo session.
	 *
	 * If no session is active, this helper can optionally redirect the current
	 * browser request to the challenge page in session-only mode.
	 *
	 * Only the current request's authenticated user is ever recognized as
	 * already-authenticated — sudo sessions are bound to a per-browser cookie
	 * and Sudo_Session::resolve_valid_proof() rejects any check where the target
	 * user differs from get_current_user_id(). Supplying a `user_id` arg that
	 * does not match the current user therefore always triggers the gated
	 * flow (audit hook + challenge redirect or `false` return), even when
	 * that other user happens to have an active session.
	 *
	 * Accepted args:
	 * - user_id (int): target user, defaults to current user. Must match the
	 *   current request's authenticated user to be treated as authorized.
	 * - rule_id (string): audit rule ID, defaults to public_api.require.
	 * - redirect (bool): when false, fail with `false` instead of redirecting.
	 * - return_url (string): **inert since 4.9.0 (#322).** Still accepted and still
	 *   emitted into the challenge URL, but nothing consumes it — the user is landed
	 *   on a neutral admin page instead. Passing it non-empty raises
	 *   `_deprecated_argument( 'wp_sudo_require', … )` (#461). Suppress in tests with
	 *   `setExpectedDeprecated( 'wp_sudo_require' )`, or route the user onward
	 *   yourself once this returns true.
	 *
	 * @since 2.12.0
	 *
	 * @param array<string, mixed> $args Optional API args.
	 * @return bool True when sudo is active. False when not active and redirect is disabled/unavailable.
	 */
	public static function require( array $args = array() ): bool {
		$defaults = array(
			'user_id'    => 0,
			'rule_id'    => self::DEFAULT_RULE_ID,
			'redirect'   => true,
			'return_url' => '',
		);

		$args = array_merge( $defaults, $args );

		// #461: `return_url` went inert in 4.9.0 (#322) — still accepted, still
		// emitted into the challenge URL, consumed by nothing. Emission is retained
		// deliberately (the 4.9.0 docs say so, and PublicApiTest pins it); what was
		// missing is any runtime signal, so an integrator's users just landed on the
		// dashboard with nothing anywhere saying why.
		//
		// _deprecated_argument(), not _doing_it_wrong(): the caller made no mistake.
		// They passed a documented argument that still appears in the reference. Core
		// reserves _doing_it_wrong() for caller error and provides this function for
		// "this argument no longer does anything", routing to E_USER_DEPRECATED and
		// `deprecated_argument_run` so aggregators bucket it apart from real bugs.
		//
		// The version is 4.9.0 because core's contract for this parameter is "the
		// version that deprecated the argument used" — not the version this notice
		// ships in, which is _doing_it_wrong()'s different convention.
		//
		// Reported as `wp_sudo_require`, the symbol integrators actually call and the
		// documented handle for setExpectedDeprecated(), rather than __METHOD__ —
		// which would name a class method most callers never type.
		//
		// Emitted at each exit, never before the redirect. `_deprecated_argument()`
		// prints under WP_DEBUG with display_errors on, and any output makes
		// headers_sent() true — which can_redirect_to_challenge() returns the negation
		// of. Emitting this at the top therefore SUPPRESSED the challenge redirect on
		// exactly the debug-enabled installs most likely to be reading the notice,
		// turning a cosmetic gap into a functional one. Caught by the PUB-01 E2E test,
		// which passes return_url and expects the challenge.
		//
		// So it fires at each exit path instead, and on the redirect path only AFTER
		// wp_safe_redirect() has sent the Location header — the browser follows the
		// 302 and never renders the body the notice lands in.
		//
		// It still fires regardless of whether a user resolves or a session is already
		// active — the check() short-circuit emits on its way out. Emitting only on
		// the gated path would go quiet for integrators whose users stay elevated,
		// which is the silence being fixed.
		//
		// Keyed on the caller's argument, never on the emitted value:
		// sanitize_return_url() falls back to HTTP_REFERER, so the emitted URL is
		// often non-empty on calls that never named the argument.
		$inert_return_url = isset( $args['return_url'] ) && is_string( $args['return_url'] ) && '' !== $args['return_url'];

		$user_id = (int) $args['user_id'];

		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}

		if ( $user_id <= 0 ) {
			self::flag_inert_return_url( $inert_return_url );
			return false;
		}

		if ( self::check( $user_id ) ) {
			self::flag_inert_return_url( $inert_return_url );
			return true;
		}

		$rule_id = self::sanitize_rule_id( $args['rule_id'] ?? '' );

		/**
		 * Fires when a third-party integration requires sudo and no active
		 * session is present.
		 *
		 * @since 2.12.0
		 *
		 * @param int    $user_id The user who attempted the action.
		 * @param string $rule_id The integration-provided rule ID.
		 * @param string $surface Always `public_api` for this helper.
		 */
		do_action( 'wp_sudo_action_gated', $user_id, $rule_id, 'public_api' );

		if ( empty( $args['redirect'] ) || ! self::can_redirect_to_challenge() ) {
			self::flag_inert_return_url( $inert_return_url );
			return false;
		}

		$challenge_url = self::build_challenge_url( self::sanitize_return_url( $args['return_url'] ?? '' ) );

		if ( wp_safe_redirect( $challenge_url ) ) {
			// After the Location header, deliberately. See the note above.
			self::flag_inert_return_url( $inert_return_url );
			exit;
		}

		self::flag_inert_return_url( $inert_return_url );

		wp_die(
			esc_html__( 'Unable to redirect to the sudo challenge page.', 'wp-sudo' ),
			'',
			array( 'response' => 403 )
		);

		return false;
	}

	/**
	 * Determine whether the current request can be redirected to challenge page.
	 *
	 * @return bool
	 */
	private static function can_redirect_to_challenge(): bool {
		if ( wp_doing_ajax() ) {
			return false;
		}

		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return false;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		if ( wp_doing_cron() ) {
			return false;
		}

		return ! headers_sent();
	}

	/**
	 * Emit the inert-`return_url` deprecation, once, at an exit path.
	 *
	 * Never call this before a redirect. `_deprecated_argument()` prints under
	 * WP_DEBUG with display_errors on; any output makes `headers_sent()` true, and
	 * `can_redirect_to_challenge()` returns its negation — so emitting early
	 * cancels the challenge redirect on precisely the debug-enabled installs whose
	 * developers would read the notice.
	 *
	 * @since 4.9.0
	 *
	 * @param bool $inert Whether a non-empty `return_url` was passed by the caller.
	 * @return void
	 */
	private static function flag_inert_return_url( bool $inert ): void {
		if ( ! $inert ) {
			return;
		}

		_deprecated_argument(
			'wp_sudo_require',
			'4.9.0',
			esc_html__( 'The return_url argument has no effect. Automatic navigation to a caller-supplied destination after reauthentication was removed in 4.9.0, because it would run under the sudo authority just granted. Send the user onward yourself once wp_sudo_require() returns true.', 'wp-sudo' )
		);
	}

	/**
	 * Build a session-only challenge URL.
	 *
	 * @param string $return_url Optional return URL.
	 * @return string
	 */
	private static function build_challenge_url( string $return_url ): string {
		$base_url = is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );

		return wp_sudo_build_challenge_url( $base_url, $return_url, array( 'page' => 'wp-sudo-challenge' ) );
	}

	/**
	 * Normalize the rule ID value.
	 *
	 * @param mixed $rule_id Raw rule ID value.
	 * @return string
	 */
	private static function sanitize_rule_id( mixed $rule_id ): string {
		if ( ! is_string( $rule_id ) ) {
			return self::DEFAULT_RULE_ID;
		}

		$rule_id = sanitize_text_field( wp_unslash( $rule_id ) );

		return '' === $rule_id ? self::DEFAULT_RULE_ID : $rule_id;
	}

	/**
	 * Normalize return_url or derive a safe fallback from the referrer.
	 *
	 * @param mixed $return_url Raw return URL value.
	 * @return string
	 */
	private static function sanitize_return_url( mixed $return_url ): string {
		if ( is_string( $return_url ) && '' !== $return_url ) {
			return esc_url_raw( wp_unslash( $return_url ) );
		}

		if ( isset( $_SERVER['HTTP_REFERER'] ) && is_string( $_SERVER['HTTP_REFERER'] ) ) {
			return esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		}

		return '';
	}
}
