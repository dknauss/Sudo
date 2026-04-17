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
	 * sessions are bound to a per-browser cookie, and Sudo_Session::verify_token()
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

		return Sudo_Session::is_active( $target_user_id ) || Sudo_Session::is_within_grace( $target_user_id );
	}

	/**
	 * Require an active sudo session.
	 *
	 * If no session is active, this helper can optionally redirect the current
	 * browser request to the challenge page in session-only mode.
	 *
	 * Accepted args:
	 * - user_id (int): target user, defaults to current user.
	 * - rule_id (string): audit rule ID, defaults to public_api.require.
	 * - redirect (bool): when false, fail with `false` instead of redirecting.
	 * - return_url (string): optional challenge cancel/return URL.
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

		$user_id = (int) $args['user_id'];

		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( self::check( $user_id ) ) {
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
			return false;
		}

		$challenge_url = self::build_challenge_url( self::sanitize_return_url( $args['return_url'] ?? '' ) );

		if ( wp_safe_redirect( $challenge_url ) ) {
			exit;
		}

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
	 * Build a session-only challenge URL.
	 *
	 * @param string $return_url Optional return URL.
	 * @return string
	 */
	private static function build_challenge_url( string $return_url ): string {
		$base_url = is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );

		$query_args = array(
			'page' => 'wp-sudo-challenge',
		);

		if ( '' !== $return_url ) {
			$query_args['return_url'] = $return_url;
		}

		return add_query_arg( $query_args, $base_url );
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
