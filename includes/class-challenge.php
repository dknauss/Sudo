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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 10, 0 );
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
		$current_page = self::sanitize_input_string( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Routing check only; sanitized in helper.

		if ( 'wp-sudo-challenge' !== $current_page ) {
			return;
		}

		wp_enqueue_style(
			'wp-sudo-challenge',
			self::plugin_url() . 'admin/css/wp-sudo-challenge.css',
			array(),
			self::plugin_version()
		);

		wp_enqueue_script(
			'wp-sudo-challenge',
			self::plugin_url() . 'admin/js/wp-sudo-challenge.js',
			array( 'wp-a11y' ),
			self::plugin_version(),
			true
		);

		$stash_key = self::sanitize_input_string( $_GET['stash_key'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Routing data only; sanitized in helper.

		$default_url = is_network_admin() ? network_admin_url() : admin_url();

		$return_url = self::sanitize_input_url( $_GET['return_url'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Routing data only; sanitized in helper.
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

		$stash_key    = self::sanitize_input_string( $_GET['stash_key'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Routing data only; sanitized in helper.
		$session_only = empty( $stash_key );

		// Compute cancel URL — mirrors enqueue_assets() logic.
		$default_url = is_network_admin() ? network_admin_url() : admin_url();
		$return_url  = self::sanitize_input_url( $_GET['return_url'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Routing data only; sanitized in helper.
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
						if ( $user && class_exists( '\\Two_Factor_Core' ) ) {
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

		$stash_key = self::sanitize_input_string( $_POST['stash_key'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; sanitized in helper.

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
					wp_send_json_success( array( 'code' => 'authenticated' ) );
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

		$stash_key = self::sanitize_input_string( $_POST['stash_key'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; sanitized in helper.

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

		$valid = false;

		// Built-in: Two Factor plugin validation.
		if ( class_exists( '\\Two_Factor_Core' ) ) {
			$provider = \Two_Factor_Core::get_primary_provider_for_user( $user );
			if ( $provider ) {
				if ( true === $provider->pre_process_authentication( $user ) ) {
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
			wp_send_json_success( array( 'code' => 'authenticated' ) );
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

		wp_send_json_success( array( 'code' => 'authenticated' ) );
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

		// Consume the stash (one-time use).
		$this->stash->delete( $stash_key, $user_id );

		/**
		 * Fires when a stashed request is about to be replayed.
		 *
		 * @since 2.0.0
		 *
		 * @param int    $user_id The user who reauthenticated.
		 * @param string $rule_id The rule ID that was gated.
		 */
		do_action( 'wp_sudo_action_replayed', $user_id, $stash['rule_id'] ?? '' );

		$safe_url = wp_validate_redirect( $stash['url'], $fallback_url );

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
			'get_data'  => $stash['get'] ?? array(),
		);
	}

	/**
	 * Resolve plugin URL constant safely for static analysis and bootstrap edge cases.
	 *
	 * @return string
	 */
	private static function plugin_url(): string {
		return defined( 'WP_SUDO_PLUGIN_URL' ) ? (string) WP_SUDO_PLUGIN_URL : '';
	}

	/**
	 * Resolve plugin version constant safely for static analysis and bootstrap edge cases.
	 *
	 * @return string
	 */
	private static function plugin_version(): string {
		return defined( 'WP_SUDO_VERSION' ) ? (string) WP_SUDO_VERSION : '0.0.0';
	}

	/**
	 * Sanitize a request value as a string.
	 *
	 * @param mixed $value Raw request value.
	 * @return string
	 */
	private static function sanitize_input_string( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $value ) );
	}

	/**
	 * Sanitize a request value as a URL.
	 *
	 * @param mixed $value Raw request value.
	 * @return string
	 */
	private static function sanitize_input_url( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return esc_url_raw( wp_unslash( $value ) );
	}
}
