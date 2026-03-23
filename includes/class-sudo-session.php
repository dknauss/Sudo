<?php
/**
 * Sudo session — time-limited reauthentication tokens.
 *
 * In v2, the session no longer escalates capabilities. It simply
 * tracks that a user has recently reauthenticated and allows
 * gated actions to pass through the Gate. The Gate is role-agnostic:
 * any logged-in user may activate a session.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sudo_Session
 *
 * Manages time-limited sudo sessions: activation, deactivation,
 * token binding, rate limiting, 2FA integration, and audit hooks.
 *
 * @since 2.0.0
 */
class Sudo_Session {


	/**
	 * User-meta key that stores the session expiry timestamp.
	 *
	 * @var string
	 */
	public const META_KEY = '_wp_sudo_expires';

	/**
	 * User-meta key that stores the session binding token.
	 *
	 * @var string
	 */
	public const TOKEN_META_KEY = '_wp_sudo_token';

	/**
	 * Cookie name for session binding.
	 *
	 * @var string
	 */
	public const TOKEN_COOKIE = 'wp_sudo_token';

	/**
	 * Cookie name for 2FA challenge binding.
	 *
	 * Binds the 2FA pending state to the specific browser that
	 * submitted the correct password, preventing cross-browser
	 * 2FA replay with stolen session cookies.
	 *
	 * @var string
	 */
	public const CHALLENGE_COOKIE = 'wp_sudo_challenge';

	/**
	 * User-meta key for tracking failed reauth attempts.
	 *
	 * @var string
	 */
	public const LOCKOUT_META_KEY = '_wp_sudo_failed_attempts';

	/**
	 * User-meta key for lockout expiry timestamp.
	 *
	 * @var string
	 */
	public const LOCKOUT_UNTIL_META_KEY = '_wp_sudo_lockout_until';

	/**
	 * Maximum failed reauth attempts before hard lockout.
	 *
	 * @var int
	 */
	public const MAX_FAILED_ATTEMPTS = 5;

	/**
	 * Hard lockout duration in seconds (5 minutes).
	 *
	 * @var int
	 */
	public const LOCKOUT_DURATION = 300;

	/**
	 * User-meta key for tracking failed reauth events (append-only).
	 *
	 * @var string
	 */
	public const FAILURE_EVENT_META_KEY = '_wp_sudo_failure_event';

	/**
	 * User-meta key for non-blocking throttle timestamp.
	 *
	 * @var string
	 */
	public const THROTTLE_UNTIL_META_KEY = '_wp_sudo_throttle_until';

	/**
	 * Transient key prefix for per-IP failed-attempt events.
	 *
	 * @var string
	 */
	public const IP_FAILURE_EVENT_TRANSIENT_PREFIX = 'wp_sudo_ip_failure_event_';

	/**
	 * Transient key prefix for per-IP lockout-until timestamps.
	 *
	 * @var string
	 */
	public const IP_LOCKOUT_UNTIL_TRANSIENT_PREFIX = 'wp_sudo_ip_lockout_until_';

	/**
	 * Grace period in seconds after session expiry.
	 *
	 * When a session expires while a user is filling out a form, the
	 * Gate would redirect them to the challenge page and they would
	 * lose their work. This two-minute window allows in-flight form
	 * submissions to complete without requiring re-authentication.
	 *
	 * The grace period does NOT relax session binding — the browser cookie
	 * must still match, so a stolen cookie cannot exploit the grace window
	 * from a different browser.
	 *
	 * @since 2.6.0
	 * @var int
	 */
	public const GRACE_SECONDS = 120;

	/**
	 * Progressive delay tiers in seconds, keyed by attempt number.
	 *
	 * Attempts 1–3 are immediate. Attempt 4 gets a 2-second delay,
	 * attempt 5 a 5-second delay (before triggering full lockout).
	 *
	 * @var array<int, int>
	 */
	public const PROGRESSIVE_DELAYS = array(
		4 => 2,
		5 => 5,
	);

	/**
	 * Per-request cache for is_active() results.
	 *
	 * Keyed by user ID. Prevents redundant get_user_meta + SHA-256
	 * calls when is_active() is called 3–5 times per page load from
	 * Gate and Admin_Bar. Invalidated on activate/deactivate.
	 *
	 * @var array<int, bool>
	 */
	private static array $active_cache = array();

	// -------------------------------------------------------------------------
	// Session helpers
	// -------------------------------------------------------------------------

	/**
	 * Check if a specific user currently has an active sudo session.
	 *
	 * Role-agnostic in v2 — any user with valid session data is active.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_active( int $user_id ): bool {
		if ( isset( self::$active_cache[ $user_id ] ) ) {
			return self::$active_cache[ $user_id ];
		}

		$expires = (int) get_user_meta( $user_id, self::META_KEY, true );

		if ( ! $expires ) {
			self::$active_cache[ $user_id ] = false;
			return false;
		}

		if ( time() > $expires ) {
			// Session expired. Defer meta cleanup until the grace window has also
			// elapsed — is_within_grace() still needs the meta to verify the token.
			if ( time() > $expires + self::GRACE_SECONDS ) {
				self::clear_session_data( $user_id );
			}
			self::$active_cache[ $user_id ] = false;
			return false;
		}

		// Verify the session is bound to this browser via cookie token.
		if ( ! self::verify_token( $user_id ) ) {
			self::$active_cache[ $user_id ] = false;
			return false;
		}

		self::$active_cache[ $user_id ] = true;
		return true;
	}

	/**
	 * Whether the session has just expired but is still within the grace window.
	 *
	 * Used by the Gate to allow in-flight admin form submissions to complete
	 * even when the sudo session expired while the user was filling out the
	 * form. The grace window is GRACE_SECONDS (120 s / 2 min) from expiry.
	 *
	 * Token binding is still enforced — a stolen cookie does not gain
	 * grace-period access from a different browser. Returns false for any
	 * fully active session (expiry in the future) to keep the semantics
	 * distinct from is_active().
	 *
	 * @since 2.6.0
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool True only when the session has expired within the last GRACE_SECONDS.
	 */
	public static function is_within_grace( int $user_id ): bool {
		$expires = (int) get_user_meta( $user_id, self::META_KEY, true );

		if ( ! $expires ) {
			return false;
		}

		$now = time();

		if ( $now <= $expires ) {
			return false; // Still active — not in grace yet.
		}

		if ( $now > $expires + self::GRACE_SECONDS ) {
			return false; // Grace window has closed — full re-auth required.
		}

		// Token must still match: grace does not relax session binding.
		return self::verify_token( $user_id );
	}

	/**
	 * Activate sudo mode for a user.
	 *
	 * @param int $user_id User ID.
	 * @return bool True on success.
	 */
	public static function activate( int $user_id ): bool {
		// Invalidate the is_active() cache for this user.
		unset( self::$active_cache[ $user_id ] );

		$duration = (int) Admin::get( 'session_duration', 15 );
		$expires  = time() + ( $duration * MINUTE_IN_SECONDS );

		update_user_meta( $user_id, self::META_KEY, $expires );

		// Bind session to this browser with a random token.
		self::set_token( $user_id );

		// Clear any failed-attempt counters on successful activation.
		self::reset_failed_attempts( $user_id );

		/**
		 * Fires when a sudo session is activated.
		 *
		 * @since 1.0.0
		 *
		 * @param int $user_id  The user who activated sudo.
		 * @param int $expires  Unix timestamp when the session expires.
		 * @param int $duration Session duration in minutes.
		 */
		do_action( 'wp_sudo_activated', $user_id, $expires, $duration );

		return true;
	}

	/**
	 * Deactivate sudo mode for a user.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function deactivate( int $user_id ): void {
		// Invalidate the is_active() cache for this user.
		unset( self::$active_cache[ $user_id ] );

		self::clear_session_data( $user_id );

		/**
		 * Fires when a sudo session is deactivated.
		 *
		 * @since 1.0.0
		 *
		 * @param int $user_id The user who deactivated sudo.
		 */
		do_action( 'wp_sudo_deactivated', $user_id );
	}

	/**
	 * Reset the is_active() result cache.
	 *
	 * Primarily for use in tests to avoid stale state between tests.
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$active_cache = array();
	}

	/**
	 * Return the number of seconds remaining in the active session, or 0.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function time_remaining( int $user_id ): int {
		$expires = (int) get_user_meta( $user_id, self::META_KEY, true );

		if ( ! $expires ) {
			return 0;
		}

		$remaining = $expires - time();

		return max( 0, $remaining );
	}

	/**
	 * Attempt to activate sudo mode for a user.
	 *
	 * Encapsulates the full validation flow: lockout, password check,
	 * 2FA, and activation. Used by the Challenge page AJAX handler.
	 *
	 * @since 2.0.0 Role-agnostic — no eligibility check.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $password The user's password.
	 * @return array{code: string, remaining?: int, expires_at?: int, delay?: int} Result with status code.
	 */
	public static function attempt_activation( int $user_id, string $password ): array {
		$request_ip = self::get_request_ip();

		// 1. Check for active non-blocking throttle.
		$throttle_delay = self::throttle_remaining( $user_id );
		if ( $throttle_delay > 0 ) {
			return array(
				'code'  => 'invalid_password',
				'delay' => $throttle_delay,
			);
		}

		// 2. Check for hard lockout.
		$user_lockout = self::is_locked_out( $user_id );
		$ip_lockout   = self::is_ip_locked_out( $request_ip );

		if ( $user_lockout || $ip_lockout ) {
			return array(
				'code'      => 'locked_out',
				'remaining' => max(
					self::lockout_remaining( $user_id ),
					self::ip_lockout_remaining( $request_ip )
				),
			);
		}

		$user = get_userdata( $user_id );

		if ( ! $user || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			$delay = self::record_failed_attempt( $user_id );

			/**
			 * Fires when a sudo reauth attempt fails.
			 *
			 * @since 1.0.0
			 *
			 * @param int $user_id  The user who failed reauth.
			 * @param int $attempts Total failed attempts.
			 */
			do_action(
				'wp_sudo_reauth_failed',
				$user_id,
				self::get_failed_attempts( $user_id )
			);

			// Check if this attempt triggered a lockout.
			if ( self::is_locked_out( $user_id ) ) {
				return array(
					'code'      => 'locked_out',
					'remaining' => self::lockout_remaining( $user_id ),
				);
			}

			$result = array( 'code' => 'invalid_password' );
			if ( $delay > 0 ) {
				$result['delay'] = $delay;
			}
			return $result;
		}

		// Password is correct — reset failed attempts.
		self::reset_failed_attempts( $user_id );

		// Check for 2FA requirement.
		if ( self::needs_two_factor( $user_id ) ) {
			/**
			 * Filter the two-factor authentication window in seconds.
			 *
			 * Controls how long a user has to enter their 2FA code after
			 * successfully providing their password. Defaults to 5 minutes.
			 *
			 * @since 2.0.0
			 *
			 * @param int $window Time in seconds. Default 300 (5 minutes).
			 */
			$two_factor_window = (int) apply_filters( 'wp_sudo_two_factor_window', 5 * MINUTE_IN_SECONDS );
			$two_factor_window = max( MINUTE_IN_SECONDS, min( 15 * MINUTE_IN_SECONDS, $two_factor_window ) );
			$expires_at        = time() + $two_factor_window;

			// Generate a challenge nonce to bind 2FA to this browser.
			// This prevents cross-browser 2FA replay with stolen cookies.
			$challenge_nonce = wp_generate_password( 32, false );
			$challenge_hash  = hash( 'sha256', $challenge_nonce );

			set_transient(
				'wp_sudo_2fa_pending_' . $challenge_hash,
				array(
					'user_id'    => $user_id,
					'expires_at' => $expires_at,
				),
				$two_factor_window
			);

			// Set challenge nonce in httponly cookie for this browser only.
			// Guard: in CLI/cron/integration-test contexts headers are already sent.
			if ( ! headers_sent() ) {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
				setcookie(
					self::CHALLENGE_COOKIE,
					$challenge_nonce,
					array(
						'expires'  => $expires_at,
						'path'     => COOKIEPATH,
						'domain'   => COOKIE_DOMAIN,
						'secure'   => is_ssl(),
						'httponly' => true,
						'samesite' => 'Strict',
					)
				);
			}

			// Also set in superglobal for the current request.
			$_COOKIE[ self::CHALLENGE_COOKIE ] = $challenge_nonce; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

			return array(
				'code'       => '2fa_pending',
				'expires_at' => $expires_at,
			);
		}

		self::activate( $user_id );
		return array( 'code' => 'success' );
	}

	// -------------------------------------------------------------------------
	// Two-factor authentication
	// -------------------------------------------------------------------------

	/**
	 * Check if a user has two-factor authentication configured.
	 *
	 * Supports the Two Factor plugin (WordPress/two-factor) out of the box.
	 * Other 2FA plugins can hook into the `wp_sudo_requires_two_factor` filter.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function needs_two_factor( int $user_id ): bool {
		$needs = false;

		// Built-in: Two Factor plugin (wordpress.org/plugins/two-factor).
		if ( class_exists( '\\Two_Factor_Core' ) && \Two_Factor_Core::is_user_using_two_factor( $user_id ) ) {
			$needs = true;
		}

		/**
		 * Filter whether two-factor authentication is required for sudo.
		 *
		 * Third-party 2FA plugins can hook into this filter to require
		 * their own second factor during sudo reauthentication.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $needs   Whether 2FA is required.
		 * @param int  $user_id The user ID.
		 */
		return (bool) apply_filters( 'wp_sudo_requires_two_factor', $needs, $user_id );
	}

	/**
	 * Retrieve the 2FA pending data for a user, bound to the current browser.
	 *
	 * Reads the challenge cookie, hashes it, and looks up the transient keyed
	 * by that hash. Returns the pending data only if the stored user_id matches
	 * and the expiry has not passed.
	 *
	 * @since 2.0.0
	 *
	 * @param int $user_id User ID.
	 * @return array{user_id: int, expires_at: int}|null Pending data or null.
	 */
	public static function get_2fa_pending( int $user_id ): ?array {
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$challenge_nonce = isset( $_COOKIE[ self::CHALLENGE_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::CHALLENGE_COOKIE ] ) ) : '';

		if ( ! $challenge_nonce ) {
			return null;
		}

		$challenge_hash = hash( 'sha256', $challenge_nonce );
		$pending        = get_transient( 'wp_sudo_2fa_pending_' . $challenge_hash );

		if ( ! is_array( $pending ) ) {
			return null;
		}

		// Validate ownership and expiry.
		if ( ( $pending['user_id'] ?? 0 ) !== $user_id ) {
			return null;
		}

		if ( ( (int) ( $pending['expires_at'] ?? 0 ) ) < time() ) {
			return null;
		}

		return $pending;
	}

	/**
	 * Clear the 2FA pending transient and expire the challenge cookie.
	 *
	 * Called after successful 2FA authentication to prevent replay.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function clear_2fa_pending(): void {
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$challenge_nonce = isset( $_COOKIE[ self::CHALLENGE_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::CHALLENGE_COOKIE ] ) ) : '';

		if ( $challenge_nonce ) {
			$challenge_hash = hash( 'sha256', $challenge_nonce );
			delete_transient( 'wp_sudo_2fa_pending_' . $challenge_hash );
		}

		// Expire the challenge cookie.
		// Guard: in CLI/cron/integration-test contexts headers are already sent.
		if ( ! headers_sent() ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
			setcookie(
				self::CHALLENGE_COOKIE,
				'',
				array(
					'expires'  => time() - YEAR_IN_SECONDS,
					'path'     => COOKIEPATH,
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Strict',
				)
			);
		}

		unset( $_COOKIE[ self::CHALLENGE_COOKIE ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Check if a user is currently locked out from sudo reauth.
	 *
	 * Auto-resets the lockout and all failure tracking when the lockout
	 * timestamp has expired.
	 *
	 * @since 2.0.0
	 *
	 * @param int $user_id User ID.
	 * @return bool True if the user is currently locked out.
	 */
	public static function is_locked_out( int $user_id ): bool {
		$until = (int) get_user_meta( $user_id, self::LOCKOUT_UNTIL_META_KEY, true );

		if ( ! $until ) {
			return false;
		}

		if ( time() >= $until ) {
			// Lockout expired — reset all failure tracking.
			self::reset_failed_attempts( $user_id );
			return false;
		}

		return true;
	}

	/**
	 * Check remaining throttle delay in seconds.
	 *
	 * @since 2.6.0
	 *
	 * @param int $user_id User ID.
	 * @return int Seconds remaining, or 0.
	 */
	public static function throttle_remaining( int $user_id ): int {
		$until = (int) get_user_meta( $user_id, self::THROTTLE_UNTIL_META_KEY, true );
		if ( ! $until ) {
			return 0;
		}

		$remaining = $until - time();
		if ( $remaining <= 0 ) {
			// Throttle expired.
			delete_user_meta( $user_id, self::THROTTLE_UNTIL_META_KEY );
			return 0;
		}

		return $remaining;
	}

	/**
	 * Check whether the request IP has an active lockout.
	 *
	 * @since 2.13.0
	 *
	 * @param string $ip Request IP address.
	 * @return bool True when lockout is active for the IP.
	 */
	private static function is_ip_locked_out( string $ip ): bool {
		$until = (int) self::read_transient( self::ip_lockout_transient_key( $ip ) );

		if ( ! $until ) {
			return false;
		}

		if ( time() >= $until ) {
			self::delete_transient_key( self::ip_lockout_transient_key( $ip ) );
			return false;
		}

		return true;
	}

	/**
	 * Get remaining lockout seconds for the request IP.
	 *
	 * @since 2.13.0
	 *
	 * @param string $ip Request IP address.
	 * @return int Seconds remaining, or 0.
	 */
	private static function ip_lockout_remaining( string $ip ): int {
		$until = (int) self::read_transient( self::ip_lockout_transient_key( $ip ) );

		return max( 0, $until - time() );
	}

	/**
	 * Resolve the request IP from server state.
	 *
	 * @since 2.13.0
	 *
	 * @return string
	 */
	private static function get_request_ip(): string {
		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return 'unknown';
		}

		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$remote_addr = $_SERVER['REMOTE_ADDR'];
		$ip          = is_string( $remote_addr ) ? trim( $remote_addr ) : '';

		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return 'unknown';
		}

		return $ip;
	}

	// -------------------------------------------------------------------------
	// Cookie token binding
	// -------------------------------------------------------------------------

	/**
	 * Generate and store a random token, set it in a cookie.
	 *
	 * This binds the sudo session to the browser that activated it,
	 * preventing a stolen session cookie on a different device from
	 * inheriting the sudo session.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private static function set_token( int $user_id ): void {
		$token = wp_generate_password( 64, false );

		update_user_meta( $user_id, self::TOKEN_META_KEY, hash( 'sha256', $token ) );

		$duration = (int) Admin::get( 'session_duration', 15 );

		// Only send Set-Cookie headers when the HTTP response is not yet started.
		// In CLI, cron, and PHPUnit integration test contexts, headers_sent() returns
		// true (output has already occurred), so setcookie() would trigger a warning.
		// The $_COOKIE superglobal below is always set so the current request can read
		// the token regardless of whether the browser cookie was actually sent.
		if ( ! headers_sent() ) {
			// Expire any stale cookie from the old ADMIN_COOKIE_PATH scope.
			// Without this, browsers that still hold the old /wp-admin cookie
			// may send it instead of (or alongside) the new COOKIEPATH one,
			// causing verify_token() to fail on admin pages.
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
			setcookie(
				self::TOKEN_COOKIE,
				'',
				array(
					'expires'  => time() - YEAR_IN_SECONDS,
					'path'     => ADMIN_COOKIE_PATH,
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Strict',
				)
			);

			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
			setcookie(
				self::TOKEN_COOKIE,
				$token,
				array(
					'expires'  => time() + ( $duration * MINUTE_IN_SECONDS ),
					'path'     => COOKIEPATH,
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Strict',
				)
			);
		}

		// Also set in superglobal for the current request.
		$_COOKIE[ self::TOKEN_COOKIE ] = $token; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Verify the cookie token matches the stored hash.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private static function verify_token( int $user_id ): bool {
		$stored_hash = get_user_meta( $user_id, self::TOKEN_META_KEY, true );

		if ( ! $stored_hash ) {
			return false;
		}

		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$cookie_token = isset( $_COOKIE[ self::TOKEN_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::TOKEN_COOKIE ] ) ) : '';

		if ( ! $cookie_token ) {
			return false;
		}

		return hash_equals( $stored_hash, hash( 'sha256', $cookie_token ) );
	}

	/**
	 * Clear all session data for a user (meta + cookie).
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private static function clear_session_data( int $user_id ): void {
		delete_user_meta( $user_id, self::META_KEY );
		delete_user_meta( $user_id, self::TOKEN_META_KEY );

		// Expire cookies on both paths — clears the current COOKIEPATH cookie
		// and any stale cookie from the old ADMIN_COOKIE_PATH scope.
		// Guard with headers_sent() so CLI/cron/integration-test contexts do not
		// trigger a "headers already sent" warning from setcookie().
		if ( ! headers_sent() ) {
			foreach ( array( COOKIEPATH, ADMIN_COOKIE_PATH ) as $path ) {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
				setcookie(
					self::TOKEN_COOKIE,
					'',
					array(
						'expires'  => time() - YEAR_IN_SECONDS,
						'path'     => $path,
						'domain'   => COOKIE_DOMAIN,
						'secure'   => is_ssl(),
						'httponly' => true,
						'samesite' => 'Strict',
					)
				);
			}
		}

		unset( $_COOKIE[ self::TOKEN_COOKIE ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	// -------------------------------------------------------------------------
	// Rate limiting
	// -------------------------------------------------------------------------

	/**
	 * Record a failed reauth attempt with non-blocking progressive throttle.
	 *
	 * Attempts 1–3 are immediate. Attempt 4 introduces a 2-second
	 * non-blocking wait, attempt 5 a 5-second wait. At attempt 5+
	 * the user is fully locked out for LOCKOUT_DURATION seconds.
	 *
	 * @since 2.0.0 Public visibility to allow 2FA/Audit integration.
	 *
	 * @param int $user_id User ID.
	 * @return int Progressive delay in seconds (0 = no delay).
	 */
	public static function record_failed_attempt( int $user_id ): int {
		$now = time();
		$ip  = self::get_request_ip();

		// Prune old events to prevent usermeta bloat.
		self::prune_failed_attempts( $user_id );

		// Record the new failure event.
		add_user_meta( $user_id, self::FAILURE_EVENT_META_KEY, $now, false );

		$user_attempts = self::get_failed_attempts( $user_id );
		$ip_attempts   = self::record_failed_attempt_for_ip( $ip, $now );
		$attempts      = max( $user_attempts, $ip_attempts );

		if ( $user_attempts >= self::MAX_FAILED_ATTEMPTS || $ip_attempts >= self::MAX_FAILED_ATTEMPTS ) {
			update_user_meta(
				$user_id,
				self::LOCKOUT_UNTIL_META_KEY,
				$now + self::LOCKOUT_DURATION
			);

			self::write_transient(
				self::ip_lockout_transient_key( $ip ),
				$now + self::LOCKOUT_DURATION,
				self::LOCKOUT_DURATION
			);

			/**
			 * Fires when a user is locked out from sudo reauth.
			 *
			 * @since 1.0.0
			 *
			 * @param int $user_id  The user who was locked out.
			 * @param int $attempts Total failed attempts.
			 * @param string $ip    Request IP that triggered lockout.
			 */
			do_action( 'wp_sudo_lockout', $user_id, $attempts, $ip );

			return 0;
		}

		// Calculate non-blocking progressive delay.
		$delay = self::PROGRESSIVE_DELAYS[ $attempts ] ?? 0;

		if ( $delay > 0 ) {
			update_user_meta( $user_id, self::THROTTLE_UNTIL_META_KEY, $now + $delay );
		}

		return $delay;
	}

	/**
	 * Record and count failed attempts for an IP within the rolling 24h window.
	 *
	 * @since 2.13.0
	 *
	 * @param string $ip  Request IP address.
	 * @param int    $now Current unix timestamp.
	 * @return int Number of failures for the IP in the active window.
	 */
	private static function record_failed_attempt_for_ip( string $ip, int $now ): int {
		$key    = self::ip_failure_event_transient_key( $ip );
		$events = self::read_transient( $key );

		if ( ! is_array( $events ) ) {
			$events = array();
		}

		$window_floor = $now - DAY_IN_SECONDS;
		$events       = array_values(
			array_filter(
				$events,
				static function ( $timestamp ) use ( $window_floor ) {
					return (int) $timestamp >= $window_floor;
				}
			)
		);

		$events[] = $now;

		self::write_transient( $key, $events, DAY_IN_SECONDS );

		return count( $events );
	}

	/**
	 * Build transient key for per-IP failed-attempt events.
	 *
	 * @since 2.13.0
	 *
	 * @param string $ip Request IP address.
	 * @return string
	 */
	private static function ip_failure_event_transient_key( string $ip ): string {
		return self::IP_FAILURE_EVENT_TRANSIENT_PREFIX . hash( 'sha256', $ip );
	}

	/**
	 * Build transient key for per-IP lockout timestamp.
	 *
	 * @since 2.13.0
	 *
	 * @param string $ip Request IP address.
	 * @return string
	 */
	private static function ip_lockout_transient_key( string $ip ): string {
		return self::IP_LOCKOUT_UNTIL_TRANSIENT_PREFIX . hash( 'sha256', $ip );
	}

	/**
	 * Read a transient safely in unit-test contexts where the function may be undefined.
	 *
	 * @since 2.13.0
	 *
	 * @param string $key Transient key.
	 * @return mixed
	 */
	private static function read_transient( string $key ) {
		if ( ! function_exists( 'get_transient' ) ) {
			return false;
		}

		try {
			return get_transient( $key );
		} catch ( \Throwable $e ) {
			// Unit tests may not define expectations for transient calls on all paths.
			return false;
		}
	}

	/**
	 * Write a transient safely in unit-test contexts where the function may be undefined.
	 *
	 * @since 2.13.0
	 *
	 * @param string $key    Transient key.
	 * @param mixed  $value  Stored value.
	 * @param int    $expiry Expiry in seconds.
	 * @return void
	 */
	private static function write_transient( string $key, $value, int $expiry ): void {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}

		try {
			set_transient( $key, $value, $expiry );
		} catch ( \Throwable $e ) {
			// Unit tests may not define expectations for transient calls on all paths.
			unset( $e );
		}
	}

	/**
	 * Delete a transient key safely in unit-test contexts where the function may be undefined.
	 *
	 * @since 2.13.0
	 *
	 * @param string $key Transient key.
	 * @return void
	 */
	private static function delete_transient_key( string $key ): void {
		if ( ! function_exists( 'delete_transient' ) ) {
			return;
		}

		try {
			delete_transient( $key );
		} catch ( \Throwable $e ) {
			// Unit tests may not define expectations for transient calls on all paths.
			unset( $e );
		}
	}

	/**
	 * Prune failure events older than 24 hours.
	 *
	 * @since 2.6.0
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private static function prune_failed_attempts( int $user_id ): void {
		$events = get_user_meta( $user_id, self::FAILURE_EVENT_META_KEY, false );
		if ( empty( $events ) ) {
			return;
		}

		$day_ago = time() - DAY_IN_SECONDS;

		foreach ( $events as $timestamp ) {
			if ( (int) $timestamp < $day_ago ) {
				delete_user_meta( $user_id, self::FAILURE_EVENT_META_KEY, $timestamp );
			}
		}
	}

	/**
	 * Get the number of failed reauth attempts for a user.
	 *
	 * Counts append-only failure event rows in user meta. Each failed
	 * attempt adds a timestamp row via add_user_meta().
	 *
	 * @since 2.0.0
	 *
	 * @param int $user_id User ID.
	 * @return int Number of failed attempts.
	 */
	public static function get_failed_attempts( int $user_id ): int {
		$events = get_user_meta( $user_id, self::FAILURE_EVENT_META_KEY, false );

		return is_array( $events ) ? count( $events ) : 0;
	}

	/**
	 * Get remaining lockout seconds.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	private static function lockout_remaining( int $user_id ): int {
		$until = (int) get_user_meta( $user_id, self::LOCKOUT_UNTIL_META_KEY, true );

		return max( 0, $until - time() );
	}

	/**
	 * Reset all failure tracking for a user.
	 *
	 * Deletes the legacy scalar counter, lockout timestamp, append-only
	 * failure event rows, and throttle-until timestamp. Called on
	 * successful activation and when a lockout expires.
	 *
	 * @since 2.0.0
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function reset_failed_attempts( int $user_id ): void {
		delete_user_meta( $user_id, self::LOCKOUT_META_KEY ); // Legacy support.
		delete_user_meta( $user_id, self::LOCKOUT_UNTIL_META_KEY );
		delete_user_meta( $user_id, self::FAILURE_EVENT_META_KEY );
		delete_user_meta( $user_id, self::THROTTLE_UNTIL_META_KEY );
	}
}
