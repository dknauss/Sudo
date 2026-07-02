<?php
/**
 * Admin bar countdown for active sudo sessions.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Bar
 *
 * Shows active sudo state in the admin bar and allows one-click deactivation.
 *
 * @since 2.0.0
 */
class Admin_Bar {

	/**
	 * Nonce action for deactivation.
	 *
	 * @var string
	 */
	public const DEACTIVATE_NONCE = 'wp_sudo_deactivate';

	/**
	 * Query parameter for deactivation.
	 *
	 * @var string
	 */
	public const DEACTIVATE_PARAM = 'wp_sudo_deactivate';

	/**
	 * Query parameter for the post-deactivation redirect target.
	 *
	 * @var string
	 */
	public const REDIRECT_PARAM = 'wp_sudo_redirect_to';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_node' ), 100 );
		add_action( 'init', array( $this, 'handle_deactivate' ), 5, 0 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 10, 0 );
	}

	/**
	 * Add the sudo countdown node to the admin bar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 * @return void
	 */
	public function admin_bar_node( $wp_admin_bar ): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		if ( ! Sudo_Session::is_active( $user_id ) ) {
			return;
		}

		$remaining = Sudo_Session::time_remaining( $user_id );

		if ( $remaining <= 0 ) {
			return;
		}

		$minutes = floor( $remaining / 60 );
		$seconds = $remaining % 60;

		$current_url = self::current_url();

		$deactivate_url = wp_nonce_url(
			add_query_arg(
				array(
					self::DEACTIVATE_PARAM => '1',
					self::REDIRECT_PARAM   => rawurlencode( $current_url ),
				),
				admin_url()
			),
			self::DEACTIVATE_NONCE,
			'_wpnonce'
		);

		$wp_admin_bar->add_node(
			array(
				'id'    => 'wp-sudo-active',
				'title' => sprintf(
					'<span class="ab-icon dashicons dashicons-unlock" aria-hidden="true"></span><span class="ab-label">%s</span>',
					sprintf(
						/* translators: %1$d: minutes, %2$d: seconds */
						__( 'Sudo: %1$d:%2$02d', 'wp-sudo' ),
						$minutes,
						$seconds
					)
				),
				'href'  => $deactivate_url,
				'meta'  => array(
					'class' => 'wp-sudo-active',
					'title' => __( 'Click to deactivate sudo mode', 'wp-sudo' ),
				),
			)
		);
	}

	/**
	 * Handle the deactivation action.
	 *
	 * @return void
	 */
	public function handle_deactivate(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified below.
		if ( ! isset( $_GET[ self::DEACTIVATE_PARAM ] ) ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		$nonce = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
		if ( isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_verify_nonce handles nonce input.
			$nonce = wp_unslash( $_GET['_wpnonce'] );
		}

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::DEACTIVATE_NONCE ) ) {
			wp_die(
				esc_html__( 'Security check failed.', 'wp-sudo' ),
				'',
				array( 'response' => 403 )
			);
		}

		Sudo_Session::deactivate( $user_id );

		wp_safe_redirect( self::deactivation_redirect_url() );
		exit;
	}

	/**
	 * Resolve the current URL used as the return target after deactivation.
	 *
	 * @return string
	 */
	private static function current_url(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return admin_url();
		}

		$scheme = is_ssl() ? 'https' : 'http';
		$host   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw() sanitizes the full URL; sanitize_text_field() would corrupt encoded path/query segments.
		$uri = wp_unslash( $_SERVER['REQUEST_URI'] );

		if ( '' === $host ) {
			return admin_url();
		}

		return esc_url_raw( $scheme . '://' . $host . $uri );
	}

	/**
	 * Resolve and clean the post-deactivation redirect URL.
	 *
	 * @return string
	 */
	private static function deactivation_redirect_url(): string {
		$redirect_url = admin_url();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified before this helper is called.
		if ( isset( $_GET[ self::REDIRECT_PARAM ] ) && is_string( $_GET[ self::REDIRECT_PARAM ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified; esc_url_raw() sanitizes the full URL.
			$candidate = esc_url_raw( wp_unslash( $_GET[ self::REDIRECT_PARAM ] ) );

			if ( '' !== $candidate ) {
				$redirect_url = $candidate;
			}
		}

		return remove_query_arg( array( self::DEACTIVATE_PARAM, self::REDIRECT_PARAM, '_wpnonce' ), $redirect_url );
	}

	/**
	 * Enqueue admin bar assets when session is active.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		if ( ! Sudo_Session::is_active( $user_id ) ) {
			return;
		}

		$remaining = Sudo_Session::time_remaining( $user_id );

		if ( $remaining <= 0 ) {
			return;
		}

		wp_enqueue_style(
			'wp-sudo-admin-bar',
			WP_SUDO_PLUGIN_URL . 'admin/css/wp-sudo-admin-bar.css',
			array(),
			WP_SUDO_VERSION
		);

		wp_enqueue_script(
			'wp-sudo-admin-bar',
			WP_SUDO_PLUGIN_URL . 'admin/js/wp-sudo-admin-bar.js',
			array(),
			WP_SUDO_VERSION,
			true
		);

		wp_localize_script(
			'wp-sudo-admin-bar',
			'wpSudoAdminBar',
			array( 'remaining' => $remaining )
		);
	}
}
