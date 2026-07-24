<?php
/**
 * Challenge page — interstitial reauthentication for gated admin actions.
 *
 * When the Gate intercepts an admin UI request, it stashes the request
 * and redirects here. The user enters their password (+2FA if configured),
 * and on success the stashed request is replayed:
 *   - GET requests: wp_safe_redirect() to the original URL.
 *   - POST requests: self-submitting HTML form with stashed fields.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Challenge
 *
 * @since 2.0.0
 */
class Challenge {


	/**
	 * Nonce action for challenge authentication.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'wp_sudo_challenge';

	/**
	 * AJAX action name for password step.
	 *
	 * @var string
	 */
	public const AJAX_AUTH_ACTION = 'wp_sudo_challenge_auth';

	/**
	 * AJAX action name for 2FA step.
	 *
	 * @var string
	 */
	public const AJAX_2FA_ACTION = 'wp_sudo_challenge_2fa';

	/**
	 * AJAX action name for the in-editor 2FA partial (Milestone B).
	 *
	 * Returns the primary provider's server-rendered 2FA fields so the in-editor
	 * modal can host an OTP-family second factor in place. Gated on a valid
	 * `2fa_pending` state; validation still happens in the unchanged
	 * `handle_ajax_2fa`.
	 *
	 * @var string
	 */
	public const AJAX_2FA_PARTIAL_ACTION = 'wp_sudo_challenge_2fa_partial';

	/**
	 * AJAX action name for re-minting a fresh grant nonce.
	 *
	 * The grant nonce localized into a long-lived editor at page load ages out
	 * (~24 h). A block/site editor left open past that point would open the reauth
	 * modal but then fail `check_ajax_referer()` on the stale nonce — recreating
	 * the dead-end this feature removes. The editor calls this endpoint to obtain
	 * a fresh `NONCE_ACTION` nonce before authenticating. It grants nothing; its
	 * CSRF proof is the login cookie (the `wp_ajax_` hook is logged-in-only).
	 *
	 * @var string
	 */
	public const AJAX_REFRESH_NONCE_ACTION = 'wp_sudo_refresh_grant_nonce';

	/**
	 * Query arg used to show a notice after redirecting instead of replaying
	 * a POST that contained redacted secret fields.
	 *
	 * @var string
	 */
	public const REDACTED_REPLAY_QUERY_ARG = 'wp_sudo_redacted_replay';

	/**
	 * Query arg used to show a notice after redirecting instead of replaying
	 * a POST that was intentionally not stored for replay.
	 *
	 * @var string
	 */
	public const BLOCKED_REPLAY_QUERY_ARG = 'wp_sudo_blocked_replay';

	/**
	 * Request stash instance.
	 *
	 * @var Request_Stash
	 */
	private Request_Stash $stash;

	/**
	 * Constructor.
	 *
	 * @param Request_Stash $stash Request stash.
	 */
	public function __construct( Request_Stash $stash ) {
		$this->stash = $stash;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ), 10, 0 );

		// Register in network admin too — challenge page is needed in both contexts.
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'register_page' ), 10, 0 );
		}

		add_action( 'wp_ajax_' . self::AJAX_AUTH_ACTION, array( $this, 'handle_ajax_auth' ), 10, 0 );
		add_action( 'wp_ajax_' . self::AJAX_2FA_ACTION, array( $this, 'handle_ajax_2fa' ), 10, 0 );
		add_action( 'wp_ajax_' . self::AJAX_2FA_PARTIAL_ACTION, array( $this, 'handle_ajax_2fa_partial' ), 10, 0 );
		add_action( 'wp_ajax_' . self::AJAX_REFRESH_NONCE_ACTION, array( $this, 'handle_ajax_refresh_nonce' ), 10, 0 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 10, 0 );
		add_action( 'admin_notices', array( $this, 'render_redacted_replay_notice' ), 10, 0 );
		add_action( 'network_admin_notices', array( $this, 'render_redacted_replay_notice' ), 10, 0 );
		add_action( 'admin_notices', array( $this, 'render_blocked_replay_notice' ), 10, 0 );
		add_action( 'network_admin_notices', array( $this, 'render_blocked_replay_notice' ), 10, 0 );
	}

	/**
	 * Render a notice when a redacted secret prevented automatic POST replay.
	 *
	 * @return void
	 */
	public function render_redacted_replay_notice(): void {
		$notice = isset( $_GET[ self::REDACTED_REPLAY_QUERY_ARG ] ) && is_string( $_GET[ self::REDACTED_REPLAY_QUERY_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::REDACTED_REPLAY_QUERY_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Notice flag only; sanitized in helper.

		if ( '1' !== $notice ) {
			return;
		}

		echo '<div class="notice notice-warning is-dismissible"><p>'
			. esc_html__( 'Reauthentication complete. For your security, password and secret fields were not replayed. Re-enter them to finish the change.', 'wp-sudo' )
			. '</p></div>';
	}

	/**
	 * Render a notice when automatic POST replay was intentionally disabled.
	 *
	 * @return void
	 */
	public function render_blocked_replay_notice(): void {
		$notice = isset( $_GET[ self::BLOCKED_REPLAY_QUERY_ARG ] ) && is_string( $_GET[ self::BLOCKED_REPLAY_QUERY_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::BLOCKED_REPLAY_QUERY_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Notice flag only; sanitized in helper.

		if ( '1' !== $notice ) {
			return;
		}

		echo '<div class="notice notice-warning is-dismissible"><p>'
			. esc_html__( 'Reauthentication complete. For your security, this request was not replayed automatically. Review the form and submit it again to finish the change.', 'wp-sudo' )
			. '</p></div>';
	}

	/**
	 * Register the hidden challenge admin page.
	 *
	 * @return void
	 */
	public function register_page(): void {
		$page_hook = add_submenu_page(
			'', // No parent — hidden page.
			__( 'Confirm Your Identity — Sudo', 'wp-sudo' ),
			'',
			'read',
			'wp-sudo-challenge',
			array( $this, 'render_page' )
		);

		if ( is_string( $page_hook ) && '' !== $page_hook ) {
			add_action( 'load-' . $page_hook, array( $this, 'prime_page_title' ), 10, 0 );
		}
	}

	/**
	 * Prime the global admin title for the hidden challenge page.
	 *
	 * Hidden submenu pages with an empty parent slug do not reliably populate
	 * the global title early enough for wp-admin/admin-header.php. Set it on the
	 * page load hook so core never passes null to strip_tags().
	 *
	 * @return void
	 */
	public function prime_page_title(): void {
		$GLOBALS['title'] = __( 'Confirm Your Identity — Sudo', 'wp-sudo' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Hidden challenge page must seed the admin title before core strip_tags() runs.
	}

	/**
	 * Enqueue challenge page assets.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$current_page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Routing check only; sanitized in helper.

		if ( 'wp-sudo-challenge' !== $current_page ) {
			return;
		}

		wp_enqueue_style(
			'wp-sudo-challenge',
			WP_SUDO_PLUGIN_URL . 'admin/css/wp-sudo-challenge.css',
			array(),
			WP_SUDO_VERSION
		);

		wp_enqueue_script(
			'wp-sudo-challenge',
			WP_SUDO_PLUGIN_URL . 'admin/js/wp-sudo-challenge.js',
			array( 'wp-a11y' ),
			WP_SUDO_VERSION,
			true
		);

		$stash_key = isset( $_GET['stash_key'] ) && is_string( $_GET['stash_key'] ) ? sanitize_text_field( wp_unslash( $_GET['stash_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Routing data only; sanitized in helper.

		$default_url = is_network_admin() ? network_admin_url() : admin_url();

		$return_url = isset( $_GET['return_url'] ) && is_string( $_GET['return_url'] ) ? esc_url_raw( wp_unslash( $_GET['return_url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Routing data only; sanitized in helper.
		$cancel_url = $return_url
			? wp_validate_redirect( $return_url, $default_url )
			: $default_url;

		wp_localize_script(
			'wp-sudo-challenge',
			'wpSudoChallenge',
			array(
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'nonce'             => wp_create_nonce( self::NONCE_ACTION ),
				'stashKey'          => $stash_key,
				'authAction'        => self::AJAX_AUTH_ACTION,
				'tfaAction'         => self::AJAX_2FA_ACTION,
				'cancelUrl'         => $cancel_url,
				'sessionOnly'       => empty( $stash_key ),
				'throttleRemaining' => Sudo_Session::throttle_remaining( get_current_user_id() ),
				'strings'           => array(
					'unexpectedResponse'   => __( 'The server returned an unexpected response. Check the browser console for details.', 'wp-sudo' ),
					'genericError'         => __( 'An error occurred.', 'wp-sudo' ),
					'networkError'         => __( 'A network error occurred. Please try again.', 'wp-sudo' ),
					'authenticationFailed' => __( 'Authentication failed.', 'wp-sudo' ),
					/* translators: %s: countdown timer like "4:30" */
					'lockoutCountdown'     => __( 'Too many failed attempts. Try again in %s.', 'wp-sudo' ),
					/* translators: %s: countdown timer like "0:05" */
					'throttleCountdown'    => __( 'Please wait %s before trying again.', 'wp-sudo' ),
					/* translators: %s: countdown timer like "9:30" */
					'timeRemaining'        => __( 'Time remaining: %s', 'wp-sudo' ),
					/* translators: %s: countdown timer like "0:45" */
					'timeRemainingWarn'    => __( '⚠ Time remaining: %s', 'wp-sudo' ),
					'sessionExpired'       => __( 'Your authentication session has expired.', 'wp-sudo' ),
					'sessionMayExpired'    => __( 'Your session may have expired.', 'wp-sudo' ),
					'startOver'            => __( 'Start over', 'wp-sudo' ),
					'twoFactorRequired'    => __( 'Password confirmed. Two-factor authentication required.', 'wp-sudo' ),
					'replayingAction'      => __( 'Replaying your action…', 'wp-sudo' ),
					'leavingChallenge'     => __( 'Leaving challenge page.', 'wp-sudo' ),
					'lockoutExpired'       => __( 'Lockout expired. You may try again.', 'wp-sudo' ),
				),
			)
		);
	}

	/**
	 * Render the challenge page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			wp_die( esc_html__( 'You must be logged in.', 'wp-sudo' ), 403 );
		}

		$stash_key    = isset( $_GET['stash_key'] ) && is_string( $_GET['stash_key'] ) ? sanitize_text_field( wp_unslash( $_GET['stash_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Routing data only; sanitized in helper.
		$session_only = empty( $stash_key );

		// Compute cancel URL — mirrors enqueue_assets() logic.
		$default_url = is_network_admin() ? network_admin_url() : admin_url();
		$return_url  = isset( $_GET['return_url'] ) && is_string( $_GET['return_url'] ) ? esc_url_raw( wp_unslash( $_GET['return_url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Routing data only; sanitized in helper.
		$cancel_url  = $return_url
			? wp_validate_redirect( $return_url, $default_url )
			: $default_url;

		if ( Sudo_Session::is_active( $user_id ) ) {
			$this->render_resume_page( $user_id, $stash_key, $cancel_url );
			return;
		}

		if ( $session_only ) {
			// Session-only mode: no stash, just activate a sudo session.
			$stash        = null;
			$action_label = __( 'Activate sudo session', 'wp-sudo' );
		} else {
			$stash = $this->stash->get( $stash_key, $user_id );

			if ( ! $stash ) {
				wp_die( esc_html__( 'Invalid or expired challenge. Please try again.', 'wp-sudo' ), 403 );
			}

			$action_label = $stash['label'] ?? $stash['rule_id'] ?? __( 'this action', 'wp-sudo' );
		}
		$throttle_delay = Sudo_Session::throttle_remaining( $user_id );
		$is_locked      = Sudo_Session::is_locked_out( $user_id );
		$is_throttled   = $throttle_delay > 0;
		$disabled       = $is_locked || $is_throttled;
		?>
		<div class="wrap">
			<div class="wp-sudo-challenge-card" id="wp-sudo-challenge-card">
				<h1>
					<span class="dashicons dashicons-shield" aria-hidden="true"></span>
					<?php esc_html_e( 'Confirm Your Identity', 'wp-sudo' ); ?>
				</h1>
				<p class="description">
					<?php
					printf(
						/* translators: %s: action label (e.g. "Activate plugin") */
						esc_html__( 'To continue: %s — please enter your password.', 'wp-sudo' ),
						'<strong>' . esc_html( $action_label ) . '</strong>'
					);
					?>
				</p>

				<ol class="wp-sudo-lecture">
					<li><?php esc_html_e( 'Respect the privacy of others.', 'wp-sudo' ); ?></li>
					<li><?php esc_html_e( 'Think before you type.', 'wp-sudo' ); ?></li>
					<li><?php esc_html_e( 'With great power comes great responsibility.', 'wp-sudo' ); ?></li>
				</ol>

				<!-- Password step -->
				<div id="wp-sudo-challenge-password-step">
					<?php if ( $is_locked ) : ?>
						<div class="notice notice-warning inline" role="alert">
							<p><?php esc_html_e( 'Too many failed attempts. The form is temporarily disabled. Please wait and try again.', 'wp-sudo' ); ?>
							</p>
						</div>
					<?php elseif ( $is_throttled ) : ?>
						<div class="notice notice-warning inline" id="wp-sudo-challenge-throttle-notice" role="alert">
							<p>
								<?php
									printf(
										/* translators: %d: seconds remaining */
										esc_html__( 'Please wait %d seconds before trying again.', 'wp-sudo' ),
										absint( $throttle_delay )
									);
								?>
							</p>
						</div>
					<?php endif; ?>

					<div class="notice notice-error inline" id="wp-sudo-challenge-error" hidden role="alert" aria-atomic="true">
						<p></p>
					</div>

					<form id="wp-sudo-challenge-password-form" method="post">
						<p>
							<label for="wp-sudo-challenge-password">
								<?php esc_html_e( 'Password', 'wp-sudo' ); ?>
							</label><br />
							<input type="password" id="wp-sudo-challenge-password" class="regular-text"
								autocomplete="current-password" aria-describedby="wp-sudo-challenge-error" required <?php echo $disabled ? 'disabled' : 'autofocus'; ?> />
						</p>
						<p class="submit">
							<button type="submit" class="button button-primary" id="wp-sudo-challenge-submit" <?php disabled( $disabled ); ?>>
								<?php esc_html_e( 'Confirm & Continue', 'wp-sudo' ); ?>
							</button>
							<a href="<?php echo esc_url( $cancel_url ); ?>" class="button">
								<?php esc_html_e( 'Cancel', 'wp-sudo' ); ?>
							</a>
						</p>
					</form>
				</div>

				<!-- 2FA step (hidden by default) -->
				<div id="wp-sudo-challenge-2fa-step" hidden>
					<h2 id="wp-sudo-challenge-2fa-title">
						<?php esc_html_e( 'Two-Factor Authentication', 'wp-sudo' ); ?>
					</h2>

					<div class="notice notice-error inline" id="wp-sudo-challenge-2fa-error" hidden role="alert"
						aria-atomic="true">
						<p></p>
					</div>

					<form id="wp-sudo-challenge-2fa-form" method="post" aria-describedby="wp-sudo-challenge-2fa-error">
						<?php
						$user = get_userdata( $user_id );
						if ( $user instanceof \WP_User ) {
							$this->render_two_factor_fields( $user );
						}
						?>
						<p class="submit">
							<button type="submit" class="button button-primary" id="wp-sudo-challenge-2fa-submit">
								<?php esc_html_e( 'Confirm & Continue', 'wp-sudo' ); ?>
							</button>
							<a href="<?php echo esc_url( $cancel_url ); ?>" class="button">
								<?php esc_html_e( 'Cancel', 'wp-sudo' ); ?>
							</a>
						</p>
						<span id="wp-sudo-challenge-2fa-timer" class="wp-sudo-2fa-timer" hidden aria-live="polite"></span>
					</form>
				</div>

				<!-- Loading overlay -->
				<div class="wp-sudo-challenge-loading" id="wp-sudo-challenge-loading" hidden role="status">
					<span class="spinner is-active"></span>
					<span class="wp-sudo-sr-only"><?php esc_html_e( 'Authenticating…', 'wp-sudo' ); ?></span>
					<span class="wp-sudo-loading-text"></span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the primary provider's two-factor authentication fields.
	 *
	 * Shared between the full-page challenge (`render_page()`) and the in-editor
	 * modal partial (`handle_ajax_2fa_partial()`) so the two surfaces can never
	 * drift — the same markup a provider emits is the markup its
	 * `validate_authentication()` reads back. Echoes the primary provider's own
	 * `authentication_page()` output (its field names are provider-specific — e.g.
	 * Two Factor TOTP's `authcode`) and fires the `wp_sudo_render_two_factor_fields`
	 * extension hook. Callers place this inside their own `<form>` (full page) or a
	 * contained non-form node (modal); it emits fields only, no form or submit.
	 *
	 * @since 4.7.0
	 *
	 * @param \WP_User $user The user authenticating.
	 * @return void
	 */
	private function render_two_factor_fields( \WP_User $user ): void {
		if ( class_exists( '\\Two_Factor_Core' ) ) {
			$provider = \Two_Factor_Core::get_primary_provider_for_user( $user );
			if ( $provider ) {
				$provider->authentication_page( $user );
			}
		}

		/**
		 * Render additional two-factor fields for challenge reauthentication.
		 *
		 * @since 2.0.0
		 *
		 * @param \WP_User $user The user authenticating.
		 */
		do_action( 'wp_sudo_render_two_factor_fields', $user );
	}

	/**
	 * Whether a Two Factor primary provider can host its second factor inside
	 * the in-editor modal.
	 *
	 * Default-deny allowlist (Q-B1): only the built-in OTP-family providers emit
	 * a plain field that works when injected as inert markup and validated by the
	 * unchanged `handle_ajax_2fa`. WebAuthn / U2F (script ceremony), push / cloud
	 * providers, the dev Dummy provider, any third-party provider, and the
	 * hook-only path (no provider object at all → `null`) all fall back to the
	 * full-page challenge link-out. No public filter in v1 — extend the allowlist
	 * here if a plain-field provider should opt in. `instanceof` against an absent
	 * class is safely false, so no `class_exists` guard is needed.
	 *
	 * @since 4.7.0
	 *
	 * @param mixed $provider The primary Two_Factor provider object, or null/false.
	 * @return bool True when the provider is modal-capable.
	 */
	private static function is_modal_capable_2fa( $provider ): bool {
		return $provider instanceof \Two_Factor_Totp
			|| $provider instanceof \Two_Factor_Email
			|| $provider instanceof \Two_Factor_Backup_Codes;
	}

	/**
	 * Whether the given user's primary Two Factor provider is modal-capable.
	 *
	 * Localized into the editor at page load as the `twoFactorModalCapable` UX
	 * hint so a non-capable 2FA user skips the modal and links out pre-password
	 * (no double password prompt) while a capable user opens the modal. The
	 * server stays authoritative — `handle_ajax_2fa_partial()` re-classifies after
	 * the password step and returns `link_out` on any mismatch (e.g. the user
	 * enrolled a different provider after page load).
	 *
	 * @since 4.7.0
	 *
	 * @param int $user_id The user ID.
	 * @return bool
	 */
	public static function is_user_2fa_modal_capable( int $user_id ): bool {
		if ( ! class_exists( '\\Two_Factor_Core' ) ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}
		return self::is_modal_capable_2fa( \Two_Factor_Core::get_primary_provider_for_user( $user ) );
	}

	/**
	 * AJAX: return the primary provider's 2FA fields for the in-editor modal.
	 *
	 * Milestone B. Lets an OTP-family 2FA user reauthenticate in place instead of
	 * linking out. The server never validates a generic code — validation
	 * (`handle_ajax_2fa`, unchanged) reads provider-specific field names that only
	 * the provider's own `authentication_page()` render emits — so the modal must
	 * inject this server-rendered markup verbatim and POST it back.
	 *
	 * Security:
	 * - `check_ajax_referer( NONCE_ACTION )` (CSRF) on a logged-in `wp_ajax_` hook.
	 * - Gated on `Sudo_Session::get_2fa_pending()`: the pending state is set only by
	 *   `attempt_activation()` after a correct password and is browser-bound via the
	 *   challenge cookie. No/expired pending → 403. Uncircumventable — there is no
	 *   path to a valid pending state without the password step.
	 * - Renders only the CURRENT user's primary provider; not-modal-capable (or no
	 *   classifiable provider) → `link_out`, never dead markup.
	 * - Validation authority stays server-side in `handle_ajax_2fa`.
	 *
	 * @since 4.7.0
	 *
	 * @return void
	 */
	public function handle_ajax_2fa_partial(): void {
		check_ajax_referer( self::NONCE_ACTION );

		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'wp-sudo' ) ), 400 );
		}

		// Gate: never render 2FA fields without a valid password-step pending state.
		if ( ! Sudo_Session::get_2fa_pending( $user_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Your authentication session has expired. Please start over.', 'wp-sudo' ) ),
				403
			);
		}

		$provider = class_exists( '\\Two_Factor_Core' )
			? \Two_Factor_Core::get_primary_provider_for_user( $user )
			: null;

		if ( ! self::is_modal_capable_2fa( $provider ) ) {
			// WebAuthn / push / unknown / hook-only → the full-page challenge.
			wp_send_json_success( array( 'code' => 'link_out' ) );
		}

		// The email provider is the one modal-capable provider whose render is
		// state-changing: Two_Factor_Email::authentication_page() emails an OTP when
		// no valid token exists. Share the SAME wp_sudo_resend_<id> throttle that
		// handle_ajax_2fa's resend path uses, so partial render + validate-path
		// resend are bounded by ONE counter. Gate the SEND, not the field: a render
		// that would NOT send (the user still holds a valid token) never counts and
		// is never blocked — so a user is never locked out of a code they already
		// hold. Only a genuine would-send at/over the cap is refused (§3a HIGH).
		if ( $provider instanceof \Two_Factor_Email ) {
			$will_send = ! $provider->user_has_token( $user->ID ) || $provider->user_token_has_expired( $user->ID );
			if ( $will_send ) {
				$resend_key   = 'wp_sudo_resend_' . $user_id;
				$resend_count = (int) get_transient( $resend_key );
				if ( $resend_count >= 3 ) {
					wp_send_json_error(
						array(
							'message' => __( 'Too many code requests. Please use your current code or wait.', 'wp-sudo' ),
							'code'    => 'resend_throttled',
						),
						429
					);
				}
				// The render below will send this code; count it against the throttle.
				set_transient( $resend_key, $resend_count + 1, 5 * MINUTE_IN_SECONDS );
			}
		}

		ob_start();
		$this->render_two_factor_fields( $user );
		$html = (string) ob_get_clean();

		wp_send_json_success(
			array(
				'code' => 'partial',
				'html' => $html,
			)
		);
	}

	/**
	 * Re-mint a fresh grant nonce for a long-open editor (Increment 2, Task 2).
	 *
	 * The editor localizes the `NONCE_ACTION` grant nonce at page load; after it
	 * ages out (~24 h) the reauth modal would otherwise dead-end on a stale-nonce
	 * `check_ajax_referer()`. The editor calls this to obtain a fresh nonce first.
	 *
	 * Auth model: the `wp_ajax_` hook is logged-in-only; the explicit
	 * `get_current_user_id()` guard makes that a hard requirement. No nonce is
	 * verified here — this endpoint issues a CSRF token and changes no state, so
	 * its CSRF proof is the login cookie, not a nonce (it cannot require the very
	 * nonce it exists to refresh). It never grants a session.
	 *
	 * @return void
	 */
	public function handle_ajax_refresh_nonce(): void {
		if ( ! get_current_user_id() ) {
			wp_send_json_error( array( 'message' => __( 'Not logged in.', 'wp-sudo' ) ), 403 );
			return; // wp_send_json_error exits in core; explicit for unambiguous flow.
		}

		wp_send_json_success( array( 'nonce' => wp_create_nonce( self::NONCE_ACTION ) ) );
	}

	/**
	 * Handle AJAX password authentication for the challenge page.
	 *
	 * @return void
	 */
	public function handle_ajax_auth(): void {
		check_ajax_referer( self::NONCE_ACTION );

		$user_id  = get_current_user_id();
		$password = '';
		if ( isset( $_POST['password'] ) && is_string( $_POST['password'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer.
			$password = wp_unslash( $_POST['password'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be sanitized.
		}

		if ( ! $user_id || ! $password ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'wp-sudo' ) ), 400 );
		}

		$stash_key = isset( $_POST['stash_key'] ) && is_string( $_POST['stash_key'] ) ? sanitize_text_field( wp_unslash( $_POST['stash_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; sanitized in helper.

		if ( Sudo_Session::is_active( $user_id ) ) {
			$this->complete_active_session_request( $user_id, $stash_key );
			return;
		}

		// Verify the stash exists — only when a stash_key is provided (challenge page flow).
		// Session-only auth sends no stash_key (session activation only, no replay).
		if ( $stash_key && ! $this->stash->exists( $stash_key, $user_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Your challenge session has expired. Please try again.', 'wp-sudo' ) ),
				403
			);
		}

		$result = Sudo_Session::attempt_activation( $user_id, $password );

		switch ( $result['code'] ) {
			case 'success':
				if ( $stash_key ) {
					$this->replay_stash( $user_id, $stash_key );
				} else {
					// Session-only flow — session is now active, user retries manually.
					// `remaining` seeds the in-editor indicator's countdown (#182).
					wp_send_json_success(
						array(
							'code'      => 'authenticated',
							'remaining' => Sudo_Session::time_remaining( $user_id ),
						)
					);
				}
				break; // replay_stash / wp_send_json_success terminate the request.

			case '2fa_pending':
				wp_send_json_success(
					array(
						'code'       => '2fa_pending',
						'expires_at' => $result['expires_at'] ?? 0,
					)
				);
				break;

			case 'locked_out':
				wp_send_json_error(
					array(
						'message'   => sprintf(
							/* translators: %d: seconds remaining */
							__( 'Too many failed attempts. Please wait %d seconds.', 'wp-sudo' ),
							$result['remaining'] ?? 0
						),
						'code'      => 'locked_out',
						'remaining' => $result['remaining'] ?? 0,
					),
					429
				);
				break;

			case 'not_allowed':
				wp_send_json_error(
					array( 'message' => __( 'You are not allowed to perform this action.', 'wp-sudo' ) ),
					403
				);
				break;

			case 'invalid_password':
				$data = array( 'message' => __( 'Incorrect password. Please try again.', 'wp-sudo' ) );
				if ( ! empty( $result['delay'] ) ) {
					$data['delay'] = (int) $result['delay'];
				}
				wp_send_json_error( $data, 401 );
				break;

			default:
				wp_send_json_error(
					array( 'message' => __( 'Incorrect password. Please try again.', 'wp-sudo' ) ),
					401
				);
		}
	}

	/**
	 * Handle AJAX 2FA authentication for the challenge page.
	 *
	 * @return void
	 */
	public function handle_ajax_2fa(): void {
		check_ajax_referer( self::NONCE_ACTION );

		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'wp-sudo' ) ), 400 );
		}

		$stash_key = isset( $_POST['stash_key'] ) && is_string( $_POST['stash_key'] ) ? sanitize_text_field( wp_unslash( $_POST['stash_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; sanitized in helper.

		if ( Sudo_Session::is_active( $user_id ) ) {
			$this->complete_active_session_request( $user_id, $stash_key );
			return;
		}

		// Verify 2FA pending state — browser-bound via challenge cookie.
		$pending = Sudo_Session::get_2fa_pending( $user_id );

		if ( ! $pending ) {
			wp_send_json_error(
				array( 'message' => __( 'Your authentication session has expired. Please start over.', 'wp-sudo' ) ),
				403
			);
		}

		$throttle_delay = Sudo_Session::throttle_remaining( $user_id );
		if ( $throttle_delay > 0 ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: seconds remaining */
						__( 'Too many attempts. Please wait %d seconds.', 'wp-sudo' ),
						$throttle_delay
					),
					'code'    => 'throttled',
					'delay'   => $throttle_delay,
				),
				429
			);
		}

		if ( Sudo_Session::is_locked_out( $user_id ) ) {
			$remaining = max( 0, (int) get_user_meta( $user_id, Sudo_Session::LOCKOUT_UNTIL_META_KEY, true ) - time() );
			wp_send_json_error(
				array(
					'message'   => sprintf(
						/* translators: %d: seconds remaining */
						__( 'Too many failed attempts. Please wait %d seconds.', 'wp-sudo' ),
						$remaining
					),
					'code'      => 'locked_out',
					'remaining' => $remaining,
				),
				429
			);
		}

		// Mirror the per-IP lockout that the password step already checks.
		// Without this one extra validation attempt leaks per pending account
		// despite an active IP lockout (F7). Pass user_id so the lockout check
		// uses the per-user key introduced by F6.
		if ( Sudo_Session::is_current_request_ip_locked_out( $user_id ) ) {
			$remaining = Sudo_Session::current_request_ip_lockout_remaining( $user_id );
			wp_send_json_error(
				array(
					'message'   => sprintf(
						/* translators: %d: seconds remaining */
						__( 'Too many failed attempts. Please wait %d seconds.', 'wp-sudo' ),
						$remaining
					),
					'code'      => 'locked_out',
					'remaining' => $remaining,
				),
				429
			);
		}

		$valid = false;

		// Built-in: Two Factor plugin validation.
		if ( class_exists( '\\Two_Factor_Core' ) ) {
			$provider = \Two_Factor_Core::get_primary_provider_for_user( $user );
			if ( $provider ) {
				// Rate-limit OTP resend to prevent delivery-channel spam (F18a).
				$resend_key   = 'wp_sudo_resend_' . $user_id;
				$resend_count = (int) get_transient( $resend_key );
				if ( $resend_count >= 3 ) {
					wp_send_json_error(
						array(
							'message' => __( 'Too many resend attempts. Please try your current code or wait.', 'wp-sudo' ),
							'code'    => 'resend_throttled',
						),
						429
					);
				}
				if ( true === $provider->pre_process_authentication( $user ) ) {
					set_transient( $resend_key, $resend_count + 1, 5 * MINUTE_IN_SECONDS );
					wp_send_json_success( array( 'code' => '2fa_resent' ) );
				}
				$valid = ( true === $provider->validate_authentication( $user ) );
			}
		}

		/**
		 * Filter whether the two-factor code is valid for sudo.
		 *
		 * @since 2.0.0
		 *
		 * @param bool     $valid Whether the 2FA code is valid.
		 * @param \WP_User $user  The user being authenticated.
		 */
		$valid = (bool) apply_filters( 'wp_sudo_validate_two_factor', $valid, $user );

		if ( ! $valid ) {
			$delay = Sudo_Session::record_failed_attempt( $user_id );

			$lockout_until = (int) get_user_meta( $user_id, Sudo_Session::LOCKOUT_UNTIL_META_KEY, true );
			if ( $lockout_until > time() ) {
				$remaining = max( 0, $lockout_until - time() );
				wp_send_json_error(
					array(
						'message'   => sprintf(
							/* translators: %d: seconds remaining */
							__( 'Too many failed attempts. Please wait %d seconds.', 'wp-sudo' ),
							$remaining
						),
						'code'      => 'locked_out',
						'remaining' => $remaining,
					),
					429
				);
			}

			$data = array(
				'message' => __( 'Invalid authentication code. Please try again.', 'wp-sudo' ),
				'code'    => 'invalid_two_factor',
			);
			if ( $delay > 0 ) {
				$data['delay'] = $delay;
			}

			wp_send_json_error( $data, 401 );
		}

		Sudo_Session::clear_2fa_pending();
		Sudo_Session::activate( $user_id );

		if ( $stash_key ) {
			$this->replay_stash( $user_id, $stash_key );
		} else {
			// Session-only flow — session is now active, user retries manually.
			// `remaining` seeds the in-editor indicator's countdown (#182).
			wp_send_json_success(
				array(
					'code'      => 'authenticated',
					'remaining' => Sudo_Session::time_remaining( $user_id ),
				)
			);
		}
	}

	/**
	 * Prepare the stashed request for replay and send the JSON response.
	 *
	 * The browser JS receives the replay data and either:
	 *   - Redirects for GET requests.
	 *   - Builds and submits a hidden form for POST requests.
	 *
	 * @param int    $user_id   The user ID.
	 * @param string $stash_key The stash key.
	 * @return void
	 */
	private function replay_stash( int $user_id, string $stash_key ): void {
		wp_send_json_success( $this->build_replay_response_data( $user_id, $stash_key ) );
	}

	/**
	 * Complete an already-active session during an AJAX challenge request.
	 *
	 * A stale challenge tab should not block the user once the browser already
	 * holds an active sudo session. Replay a still-valid stash when possible;
	 * otherwise instruct the client to leave the challenge page.
	 *
	 * @param int    $user_id   Current user ID.
	 * @param string $stash_key Challenge stash key.
	 * @return void
	 */
	private function complete_active_session_request( int $user_id, string $stash_key ): void {
		if ( $stash_key && $this->stash->exists( $stash_key, $user_id ) ) {
			$this->replay_stash( $user_id, $stash_key );
			return;
		}

		// `remaining` seeds the in-editor indicator's countdown (#182).
		wp_send_json_success(
			array(
				'code'      => 'authenticated',
				'remaining' => Sudo_Session::time_remaining( $user_id ),
			)
		);
	}

	/**
	 * Render an auto-resume screen for already-authenticated users.
	 *
	 * @param int    $user_id   Current user ID.
	 * @param string $stash_key Challenge stash key.
	 * @param string $cancel_url Safe URL to leave the challenge page.
	 * @return void
	 */
	private function render_resume_page( int $user_id, string $stash_key, string $cancel_url ): void {
		$data = array(
			'code'     => 'authenticated',
			'redirect' => $cancel_url,
		);

		if ( $stash_key && $this->stash->exists( $stash_key, $user_id ) ) {
			$data = $this->build_replay_response_data( $user_id, $stash_key, $cancel_url );
		}

		$redirect_url = isset( $data['redirect'] ) && is_string( $data['redirect'] )
			? $data['redirect']
			: $cancel_url;
		?>
		<div class="wrap">
			<div class="wp-sudo-challenge-card" id="wp-sudo-challenge-card">
				<h1>
					<span class="dashicons dashicons-shield" aria-hidden="true"></span>
					<?php esc_html_e( 'Session already confirmed', 'wp-sudo' ); ?>
				</h1>
				<p class="description">
					<?php esc_html_e( 'Your sudo session is already active. Continuing…', 'wp-sudo' ); ?>
				</p>
				<p class="submit">
					<a href="<?php echo esc_url( $redirect_url ); ?>" class="button button-primary">
						<?php esc_html_e( 'Continue', 'wp-sudo' ); ?>
					</a>
					<a href="<?php echo esc_url( $cancel_url ); ?>" class="button">
						<?php esc_html_e( 'Cancel', 'wp-sudo' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php if ( ! empty( $data['replay'] ) && ! empty( $data['url'] ) ) : ?>
			<form id="wp-sudo-resume-form" method="<?php echo esc_attr( (string) ( $data['method'] ?? 'POST' ) ); ?>" action="<?php echo esc_url( (string) $data['url'] ); ?>" hidden>
				<?php $this->render_hidden_fields( $data['post_data'] ?? array() ); ?>
			</form>
			<script>
				document.addEventListener('DOMContentLoaded', function () {
					var form = document.getElementById('wp-sudo-resume-form');
					if (form) {
						HTMLFormElement.prototype.submit.call(form);
					}
				});
			</script>
		<?php else : ?>
			<script>
				document.addEventListener('DOMContentLoaded', function () {
					window.location.href = <?php echo wp_json_encode( $redirect_url ); ?>;
				});
			</script>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render nested hidden form fields using PHP-style bracket notation.
	 *
	 * @param array<string, mixed> $fields Field data.
	 * @param string               $prefix Current name prefix.
	 * @return void
	 */
	private function render_hidden_fields( array $fields, string $prefix = '' ): void {
		foreach ( $fields as $key => $value ) {
			$field_name = '' === $prefix ? (string) $key : $prefix . '[' . (string) $key . ']';

			if ( is_array( $value ) ) {
				$this->render_hidden_fields( $value, $field_name );
				continue;
			}
			?>
			<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" />
			<?php
		}
	}

	/**
	 * Build replay response data for a stashed request.
	 *
	 * @param int         $user_id      The user ID.
	 * @param string      $stash_key    The stash key.
	 * @param string|null $fallback_url Fallback redirect when stash is missing.
	 * @return array<string, mixed>
	 */
	private function build_replay_response_data( int $user_id, string $stash_key, ?string $fallback_url = null ): array {
		$stash = $this->stash->get( $stash_key, $user_id );

		if ( ! $fallback_url ) {
			$fallback_url = is_network_admin() ? network_admin_url() : admin_url();
		}

		if ( ! $stash ) {
			return array(
				'code'     => 'success',
				'redirect' => $fallback_url,
			);
		}

		$safe_url = wp_validate_redirect( $stash['url'], $fallback_url );

		// Consume the stash (one-time use).
		$this->stash->delete( $stash_key, $user_id );

		if ( ! empty( $stash['redacted_fields_omitted'] ) || ! empty( $stash['post_replay_blocked'] ) ) {
			$return_url = ! empty( $stash['return_url'] ) && is_string( $stash['return_url'] )
				? $stash['return_url']
				: $safe_url;

			$redirect_url = wp_validate_redirect( $return_url, $safe_url );
			$notice_arg   = ! empty( $stash['redacted_fields_omitted'] )
				? self::REDACTED_REPLAY_QUERY_ARG
				: self::BLOCKED_REPLAY_QUERY_ARG;
			$redirect_url = add_query_arg( $notice_arg, '1', $redirect_url );

			return array(
				'code'                    => 'success',
				'redirect'                => $redirect_url,
				'redacted_fields_omitted' => ! empty( $stash['redacted_fields_omitted'] ),
				'post_replay_blocked'     => ! empty( $stash['post_replay_blocked'] ),
			);
		}

		/**
		 * Fires when a stashed request is about to be replayed.
		 *
		 * @since 2.0.0
		 *
		 * @param int    $user_id The user who reauthenticated.
		 * @param string $rule_id The rule ID that was gated.
		 */
		do_action( 'wp_sudo_action_replayed', $user_id, $stash['rule_id'] ?? '' );

		if ( 'GET' === ( $stash['method'] ?? 'GET' ) ) {
			return array(
				'code'     => 'success',
				'redirect' => $safe_url,
			);
		}

		return array(
			'code'      => 'success',
			'replay'    => true,
			'method'    => $stash['method'],
			'url'       => $safe_url,
			'post_data' => $stash['post'] ?? array(),
		);
	}
}
