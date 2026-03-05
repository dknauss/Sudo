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
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_node' ), 100 );
		add_action( 'admin_init', array( $this, 'handle_deactivate' ), 5, 0 );
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

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Fallback handles missing key.
		$current_url = isset( $_SERVER['REQUEST_URI'] )
			? set_url_scheme( home_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) )
			: admin_url();

		$deactivate_url = wp_nonce_url(
			add_query_arg( self::DEACTIVATE_PARAM, '1', $current_url ),
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

		wp_safe_redirect( remove_query_arg( array( self::DEACTIVATE_PARAM, '_wpnonce' ) ) );
		exit;
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
			self::plugin_url() . 'admin/css/wp-sudo-admin-bar.css',
			array(),
			self::plugin_version()
		);

		wp_enqueue_script(
			'wp-sudo-admin-bar',
			self::plugin_url() . 'admin/js/wp-sudo-admin-bar.js',
			array(),
			self::plugin_version(),
			true
		);

		wp_localize_script(
			'wp-sudo-admin-bar',
			'wpSudoAdminBar',
			array( 'remaining' => $remaining )
		);
	}

	/**
	 * Resolve plugin URL constant safely.
	 *
	 * @return string
	 */
	private static function plugin_url(): string {
		return defined( 'WP_SUDO_PLUGIN_URL' ) ? (string) WP_SUDO_PLUGIN_URL : '';
	}

	/**
	 * Resolve plugin version constant safely.
	 *
	 * @return string
	 */
	private static function plugin_version(): string {
		return defined( 'WP_SUDO_VERSION' ) ? (string) WP_SUDO_VERSION : '0.0.0';
	}

	/**
	 * Generate inline countdown JavaScript.
	 *
	 * @param int $remaining Seconds remaining.
	 * @return string
	 */
	public function countdown_script( int $remaining ): string {
		return sprintf(
			'(function(){' .
				'var r=%d;' .
				'var root=document.getElementById("wp-admin-bar-wp-sudo-active");' .
				'if(!root){return;}' .
				'var label=root.querySelector(".ab-label");' .
				'if(label){label.setAttribute("aria-live","polite");label.setAttribute("role","status");}' .
				'function render(){' .
					'if(r<0){return;}' .
					'var m=Math.floor(r/60);' .
					'var s=r%%60;' .
					'if(label){label.textContent="Sudo: "+m+":"+(s<10?"0"+s:s);}' .
					'if(r<=60){root.classList.add("wp-sudo-expiring");}' .
					'if(r===0){window.location.reload();}' .
					'r--;}' .
				'render();setInterval(render,1000);' .
			'})();',
			$remaining
		);
	}
}
