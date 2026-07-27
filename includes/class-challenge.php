<?php
/**
 * Challenge page — interstitial reauthentication for gated admin actions.
 *
 * When the Gate intercepts an admin UI request, it stashes the request
 * and redirects here. The user enters their password (+2FA if configured),
 * and on success the stash is CONSUMED, never executed: the user is returned
 * to the screen the request came from, holding the sudo session they just
 * earned, to re-issue the action themselves.
 *
 * Automatic replay was removed in 4.9.0 (#322). Nothing here reconstructs or
 * re-sends an intercepted request.
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
	 * Admin filenames that render no usable screen on a bare GET.
	 *
	 * The fail-closed landing must never return a user to one of these: each renders
	 * a blank page, a wp_die(), a raw dump, or a redirect that discards the notice
	 * argument, so the user would lose the explanation as well as their input. See
	 * is_handler_endpoint() for the per-file justification and registry IDs.
	 *
	 * Not private, so the guard test can walk it against Action_Registry's own
	 * pagenow set rather than restating it.
	 *
	 * @var string[]
	 */
	public const HANDLER_ENDPOINTS = array(
		'options.php',
		'admin-post.php',
		'admin-ajax.php',
		'admin.php',
		'user-edit.php',
		'update.php',
	);

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
					// #322: most responses do not replay — the action is not resumed and
					// the user returns to re-perform it. Announcing a replay there would
					// tell screen-reader users the opposite of what happened.
					'returningToPage'      => __( 'Returning you to your page…', 'wp-sudo' ),
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

		// #322 v2: name the concrete target. A coarse label ("Activate plugin") is a
		// blank cheque — an attacker who lures the user into a gated request gets the
		// same prompt as a legitimate action. Showing WHAT is about to happen makes
		// the password submit an informed decision, which is the control that holds
		// even if the browser binding is bypassed.
		$action_target  = $this->describe_stash_target( $stash );
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
				<?php if ( '' !== $action_target ) : ?>
					<p class="wp-sudo-challenge-target">
						<?php
						printf(
							/* translators: %s: the concrete target of the action (e.g. a plugin file or user login) */
							esc_html__( 'Target: %s', 'wp-sudo' ),
							'<code>' . esc_html( $action_target ) . '</code>'
						);
						?>
					</p>
				<?php endif; ?>

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
					// Password just verified on this request → bound replay is eligible.
					$this->replay_stash( $user_id, $stash_key, true );
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
			// Second factor just verified on this request → bound replay is eligible.
			$this->replay_stash( $user_id, $stash_key, true );
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
	 * @param int    $user_id             The user ID.
	 * @param string $stash_key           The stash key.
	 * @param bool   $credential_verified Whether a password/2FA was verified on this request (#322 v2).
	 * @return void
	 */
	private function replay_stash( int $user_id, string $stash_key, bool $credential_verified = false ): void {
		wp_send_json_success( $this->build_replay_response_data( $user_id, $stash_key, null, $credential_verified ) );
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
		<?php
		/*
		 * #322 v1: this auto-submit branch is DORMANT — build_replay_response_data()
		 * fails closed and can no longer return `replay`/`url`/`post_data`, so only
		 * the `else` redirect below runs. It is retained (not deleted) because #322 v2
		 * (origin-bound replay) re-activates it for the same-browser case, where the
		 * stash's binding cookie proves the replaying browser is the one that created
		 * it. If v2 is abandoned, delete this branch and render_hidden_fields().
		 */
		?>
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
		<?php endif; ?>
		<?php
		/*
		 * #322 — no auto-navigation here either. This branch used to send the browser
		 * to $redirect_url on DOMContentLoaded with no interaction.
		 *
		 * When no stash is present, $redirect_url is $cancel_url, which comes from
		 * $_GET['return_url'] — requester-supplied, checked only for same host by
		 * wp_validate_redirect(). So a user *already holding* an active sudo session
		 * who opened a crafted challenge URL was navigated to an address of the
		 * requester's choosing, with no password step at all. Against the attacker
		 * this release is about — one holding a cloned session cookie, who can
		 * therefore mint a valid nonce (GB-NONCE-TOKEN) — that is the same confused
		 * deputy as the stash path, reached without touching the stash.
		 *
		 * The invariant says nothing is auto-executed after reauthentication. Leaving
		 * this would have made that claim false by a route the wording did not cover,
		 * which is worse than not making the claim. The "Continue" link above is the
		 * explicit second action; nothing is lost but a redirect the user did not ask
		 * for.
		 */
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
	 * Describe the concrete target of a stashed action for informed confirmation.
	 *
	 * Display only — never used to route or replay. Values were sanitized and
	 * length-capped at stash time; they are escaped again at render.
	 *
	 * @param array<string, mixed>|null $stash The stash data, if any.
	 * @return string Human-readable target, or '' when the stash records none.
	 */
	private function describe_stash_target( ?array $stash ): string {
		if ( ! $stash || empty( $stash['target'] ) || ! is_array( $stash['target'] ) ) {
			return '';
		}

		$parts = array();

		foreach ( $stash['target'] as $key => $value ) {
			if ( ! is_string( $key ) || ! is_scalar( $value ) || '' === (string) $value ) {
				continue;
			}

			$value = (string) $value;

			// A bare numeric id ("user_id=5") tells an admin nothing about WHO they are
			// authorizing — and this line is the only control left in the same-origin
			// lure case, so resolve it to something a human can actually judge.
			//
			// Capability-gated: this is the one target value that is a SERVER-SIDE
			// lookup rather than an echo of what the requester already supplied. The
			// Gate is role-agnostic (it intercepts any logged-in user, leaving
			// capability checks to the target handler) and the challenge page renders
			// at 'read', so without this check any subscriber could walk
			// users.php?action=promote&user_id=N and enumerate every account's login.
			// Core deliberately never exposes user_login via the REST users endpoint.
			if ( 'user_id' === $key && current_user_can( 'list_users' ) ) {
				$user = get_userdata( (int) $value );

				if ( $user && ! empty( $user->user_login ) ) {
					$value = $user->user_login . ' (#' . (int) $value . ')';
				}
			}

			$parts[] = $key . ': ' . $value;
		}

		return implode( ', ', $parts );
	}

	/**
	 * Whether a URL points at an admin endpoint that renders no usable screen on GET.
	 *
	 * The fail-closed landing returns the user to the page their request was aimed at
	 * so they can re-issue it. That only helps when a GET of the stripped URL actually
	 * draws something: these endpoints are form *handlers*, and landing on one is
	 * worse than the neutral dashboard rather than better.
	 *
	 * - options.php    — GET renders the raw "All Settings" dump, not the settings form
	 *                    (GB-OPTIONS-ALLSETTINGS in docs/upstream-sources.md).
	 * - admin-post.php — with no action, renders a blank page and fires no
	 *                    admin_notices, so the blocked-replay notice is lost too
	 *                    (GB-ADMIN-POST-BLANK).
	 * - admin.php      — with no `page`, falls through to end of file without ever
	 *                    requiring admin-header.php: the same blank, notice-less
	 *                    response (GB-ADMIN-PHP-BLANK). This is the one that matters
	 *                    most for extensibility, because `admin.php?page=…` is where
	 *                    third-party settings screens live and is the `pagenow`
	 *                    example in docs/developer-reference.md.
	 * - user-edit.php  — without a user_id it calls wp_die( 'Invalid user ID.' ): no
	 *                    chrome, no notice, no form (GB-USER-EDIT-DIES). Reached by the
	 *                    profile role/password/email rules, whose stash is marked
	 *                    non-replayable, so this landing is their EVERY-TIME path
	 *                    rather than an attack-only one.
	 * - update.php     — its whole body is inside `isset( $_GET['action'] )`, so a bare
	 *                    GET renders nothing at all (GB-UPDATE-NEEDS-ACTION). Reached by
	 *                    the plugin/theme install, upload and update rules.
	 * - admin-ajax.php — returns 0/JSON, never a page.
	 * - network/edit.php — an empty action redirects to the network dashboard, which
	 *                    is the neutral page by a slower route, and drops the notice
	 *                    arg on the way (GB-NETWORK-EDIT-REDIRECT).
	 *
	 * This list fails OPEN: an endpoint nobody enumerated is treated as a good landing.
	 * ChallengeTest::test_every_builtin_post_rule_lands_somewhere_usable() closes that
	 * by walking Action_Registry's pagenow set in BOTH single-site and multisite form
	 * and resolving each to a full URL, so adding a rule on a new admin page forces
	 * this decision instead of silently inheriting a blank page.
	 *
	 * Matched on the path's basename so a subdirectory install or any admin path
	 * prefix is handled without string-prefix assumptions.
	 *
	 * @param string $url Absolute URL, query already stripped.
	 * @return bool
	 */
	private function is_handler_endpoint( string $url ): bool {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return false;
		}

		$segments = explode( '/', trim( untrailingslashit( $path ), '/' ) );
		$file     = (string) array_pop( $segments );

		if ( in_array( $file, self::HANDLER_ENDPOINTS, true ) ) {
			return true;
		}

		// edit.php is the Posts list in site admin — a real screen — but a bare handler
		// under network admin, so it is only a handler in that context.
		//
		// Decided from the URL's own path, NOT is_network_admin(). This method runs
		// under admin-ajax.php (both replay_stash() callers are wp_ajax_ handlers),
		// which never calls set_current_screen() and does not define WP_NETWORK_ADMIN,
		// so is_network_admin() returns false here even for a genuine network action —
		// a condition that cannot be true on the live path is not a guard. The unit
		// suite stubs it false globally (tests/TestCase.php), so no test could have
		// shown that either.
		return 'edit.php' === $file && 'network' === (string) array_pop( $segments );
	}

	/**
	 * Clear the one-time stash binding cookie.
	 *
	 * Cleared on BOTH the replay and fail-closed paths so a proof never outlives the
	 * stash it belonged to.
	 *
	 * @return void
	 */
	private function clear_binding_cookie(): void {
		if ( headers_sent() ) {
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
		setcookie(
			Request_Stash::BINDING_COOKIE,
			'',
			array(
				'expires'  => time() - 3600,
				'path'     => '/',
				'secure'   => true,
				'httponly' => true,
				'samesite' => 'Strict',
			)
		);

		unset( $_COOKIE[ Request_Stash::BINDING_COOKIE ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Clearing a one-time proof for the current request; not a caching decision.
	}

	/**
	 * Build replay response data for a stashed request.
	 *
	 * @param int         $user_id      The user ID.
	 * @param string      $stash_key    The stash key.
	 * @param string|null $fallback_url Fallback redirect when stash is missing.
	 * @param bool        $credential_verified Whether a password/2FA was verified on this request (#322 v2).
	 * @return array<string, mixed>
	 */
	private function build_replay_response_data( int $user_id, string $stash_key, ?string $fallback_url = null, bool $credential_verified = false ): array {
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

		// Consume the stash (one-time use).
		$this->stash->delete( $stash_key, $user_id );

		/*
		 * #322 — automatic replay is DISABLED, unconditionally.
		 *
		 * The release invariant: after reauthentication, WP Sudo never automatically
		 * executes a previously intercepted server-side request. Every method, every
		 * rule, every surface. There is no eligibility test here because there is no
		 * eligibility question — this method has no branch that can return a replay
		 * instruction, which is the whole guarantee and is verifiable by reading it.
		 *
		 * This replaces a per-request eligibility check (a `__Host-` binding cookie
		 * plus a confirmation naming the target). That approach was sound in outline
		 * and repeatedly wrong in detail: guards keyed on values that could not hold
		 * where they ran, a target line that named an unchanged field, and a route
		 * where replaying a partial Settings-API body made core write `null` over
		 * every option the body omitted. Each was individually fixable; together they
		 * showed that *which stashes may replay* is the wrong question to keep
		 * answering. Removing the primitive makes every one of those cases moot
		 * rather than individually correct, and removal fails closed.
		 *
		 * The dead machinery — `may_replay_bound_stash()`, the binding cookie, the
		 * captured target used for eligibility — is deliberately left in place and
		 * uncalled for this release, so the security change is one unreachable
		 * branch rather than an architectural deletion. It is removed in 4.10.
		 */
		$refusal_reason = 'replay_disabled';

		if ( ! $credential_verified ) {
			// The lure footprint: a stash released by someone who already held an
			// active session rather than by the browser that created it. Worth
			// distinguishing because it is the only reason here an operator would
			// alert on — `replay_disabled` is now the ordinary case.
			$refusal_reason = 'no_credential_this_request';
		}

		$this->clear_binding_cookie();

		/**
		 * Fires when a stashed action was discarded instead of replayed.
		 *
		 * The successor to `wp_sudo_action_replayed`, which is dormant since 4.9.0.
		 * This fires at the same lifecycle moment, for EVERY consumed stash rather
		 * than only the replayed ones, and carries a reason the older hook never
		 * had. Without it the path would be silent, so the case this mechanism
		 * exists to stop — someone completing a reauthentication in a browser that
		 * did not start the action — would leave no audit trail at all.
		 *
		 * NOT limited to the post-reauthentication path. This method also runs from
		 * `complete_active_session_request()` and `render_resume_page()`, where no
		 * credential is presented and the reason is `no_credential_this_request`. A
		 * consumer that assumes every fire follows a password step will misread
		 * those.
		 *
		 * That reason is the highest-value one to alert on, and the easiest to
		 * mistake for noise: it is the footprint of a lure that landed on a
		 * session-holder and was refused. Since 4.9.0 nothing executes on ANY
		 * path — replay is removed, not conditioned — so this is a record of an
		 * attempt, never of a partial success. But the attempt
		 * is visible nowhere else.
		 *
		 * Fires at most once per stash — the stash is consumed (deleted) above
		 * before this point, and a subsequent request finds nothing to refuse.
		 *
		 * @since 4.9.0
		 *
		 * @param int    $user_id The user whose stashed action was discarded.
		 * @param string $rule_id The rule ID that was gated.
		 * @param string $reason  Why the action was not resumed. Two values since
		 *                        4.9.0 removed automatic replay, down from ten
		 *                        eligibility reasons:
		 *                        - `replay_disabled` — the ordinary case. Replay is
		 *                          off; there is nothing to be eligible for.
		 *                        - `no_credential_this_request` — the stash was
		 *                          released without a credential verified on this
		 *                          request, i.e. by someone who already held an
		 *                          active session rather than by the browser that
		 *                          created it. This is the lure footprint and the
		 *                          only value here worth alerting on.
		 */
		do_action( 'wp_sudo_replay_refused', $user_id, $stash['rule_id'] ?? '', $refusal_reason );

		/*
		 * #322 — fail closed, with a soft landing. The stashed action is NEVER
		 * auto-executed or auto-submitted after reauth, and we never redirect to the
		 * stashed action URL or the (attacker-controllable) return_url. That
		 * auto-resume-without-confirmation primitive was a confused deputy: the stash
		 * is keyed to user_id alone, so a cloned session (stolen cookie, no password)
		 * could plant one, lure the victim to the challenge URL, and have the victim's
		 * reauth carry out the attacker's transaction — GET actions worst of all,
		 * since replaying a GET was a plain redirect to the destructive URL.
		 *
		 * Nothing is ever replayed; both methods return the user to the *originating
		 * screen* — the admin page the request was aimed at, with its query (the
		 * action + nonce, i.e. the effect) stripped and re-validated same-origin — so
		 * re-performing it is a re-click or a re-submit that the now-active sudo
		 * session passes straight through. Both carry the "review and submit again"
		 * notice (the redacted variant when secret fields were dropped at stash time).
		 * No per-rule taxonomy is involved. #322 v2 (origin-bound replay) restores
		 * seamless auto-replay for the same-browser case without reopening this hole.
		 *
		 * #429: POSTs previously landed on the dashboard, on the reasoning that their
		 * re-submit needs the form re-filled regardless. That was wrong in the case it
		 * matters most — the notice tells the user to re-enter redacted fields, and the
		 * dashboard has no form to re-enter them into, so the instruction is
		 * unactionable and the typed input is simply lost. v4.8.0 got the landing right
		 * by using the stashed return_url; what was unsafe there was the SOURCE, not
		 * the intent. return_url is derived from wp_get_referer(), so the request that
		 * planted the stash chooses it, and honouring it re-opens the confused deputy
		 * (a same-host action URL executed by the victim's reauthenticated browser).
		 * $stash['url'] carries no such choice — it is the URL the gate intercepted.
		 *
		 * return_url is deliberately not consulted even to CLASSIFY the landing. An
		 * earlier cut of this fix compared its path to the request path to tell a
		 * self-posting form from a handler; that guard was dead on arrival, because a
		 * form posting to its own URI yields no referer at all, so the stash records
		 * return_url = '' for user-new.php — the very form #429 is about. See
		 * GB-REFERER-SELFPOST and GB-USER-NEW-SELFPOST in docs/upstream-sources.md.
		 * Reading it also handed the requester a lever over which branch ran.
		 */
		$neutral_url = is_network_admin() ? network_admin_url() : admin_url();
		$target      = $neutral_url;
		$is_get      = 'GET' === strtoupper( (string) ( $stash['method'] ?? 'GET' ) );

		if ( ! empty( $stash['url'] ) && is_string( $stash['url'] ) ) {
			// Originating screen only: drop the query (action + nonce = the effect),
			// keep the same-origin admin page, re-validate. Never the full action URL.
			// A POST URL can carry an effect-bearing query too (admin-post.php?action=…)
			// and a rule reading $_REQUEST would see it on the GET that follows, so the
			// stripping is not GET-specific.
			$origin    = wp_validate_redirect( $stash['url'], $neutral_url );
			$query_pos = strpos( $origin, '?' );

			$screen = false === $query_pos ? $origin : substr( $origin, 0, $query_pos );

			if ( $this->is_handler_endpoint( $screen ) ) {
				// A handler renders no screen on GET: options.php is the raw All Settings
				// dump, admin-post.php with no action is a blank page that fires no
				// admin_notices hook at all (so even the explanatory notice is lost), and
				// network edit.php just bounces to the network dashboard. Stranding a
				// user there is worse than the dashboard, not better. Applied to BOTH
				// branches — a query-carrying POST to admin-post.php?action=… strips down
				// to exactly the blank page.
				$screen = $neutral_url;
			} elseif ( false === $query_pos && $is_get ) {
				// A queryless stashed GET must NOT be used as the landing spot: with no
				// query to strip, "the originating screen" IS the action URL, so an
				// extensibility rule gating an effect that fires on path load alone would
				// still be carried out by the victim's reauth — the confused deputy this
				// whole change removes.
				//
				// A queryless POST is not the same case and does not take this fallback.
				// The gated request was `POST $screen`; the landing issues `GET $screen`,
				// which is a different request carrying none of the discarded body. That
				// is what makes a self-posting form (user-new.php) safe to return to, and
				// it is the whole of #429. The residual risk is a third-party rule whose
				// effect fires on a bare-path GET while it was the POST that was gated;
				// the landing re-enters the Gate, but the user now holds a session that
				// passes. Accepted deliberately, and narrower than the UX cost of not
				// returning anyone to any form.
				$screen = $neutral_url;
			}

			$target = wp_validate_redirect( $screen, $neutral_url );
		}

		$notice_arg = ! empty( $stash['redacted_fields_omitted'] )
			? self::REDACTED_REPLAY_QUERY_ARG
			: self::BLOCKED_REPLAY_QUERY_ARG;
		$target     = add_query_arg( $notice_arg, '1', $target );

		return array(
			'code'                    => 'success',
			'redirect'                => $target,
			'redacted_fields_omitted' => ! empty( $stash['redacted_fields_omitted'] ),
			'post_replay_blocked'     => true,
		);
	}
}
