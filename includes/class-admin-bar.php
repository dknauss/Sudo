<?php
/**
 * Admin bar countdown for active sudo sessions.
 *
 * Extracted from Sudo_Session in v2. Renders a green admin bar node
 * with a live countdown when a sudo session is active. Provides a
 * one-click deactivation button. Shows nothing when no session is
 * active — unlike v1, there is no "Activate Sudo" button because
 * gating is reactive (challenge-on-action), not proactive.
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
 * Manages the admin bar sudo countdown node and deactivation action.
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
	 * Only shows when a sudo session is active. Green background with
	 * countdown timer; turns red in the last 60 seconds.
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

		// Build the deactivation URL against the current page so the user
		// stays where they are after the session ends (instead of landing
		// on the dashboard).
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
					'<span class="ab-icon dashicons dashicons-unlock" aria-hidden="true"></span>'
					. '<span class="ab-label">%s</span>',
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
	 * Triggered when a user clicks the admin bar node.
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
		if ( isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
			$nonce = wp_unslash( $_GET['_wpnonce'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_verify_nonce handles nonce input.
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
	 * Enqueue admin bar assets when a session is active.
	 *
	 * Loads the admin bar CSS and an inline countdown script that
	 * updates the timer every second and auto-redirects on expiry.
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
	 * Generate the inline countdown JavaScript.
	 *
	 * Updates the admin bar label every second. When the remaining
	 * time drops below 60 seconds, the node turns red. On expiry,
	 * the page reloads so the user sees the normal (non-sudo) state.
	 *
	 * Also listens for the sudo keyboard shortcut (Ctrl+Shift+S /
	 * Cmd+Shift+S). When fired during an active session, the admin
	 * bar node briefly flashes to acknowledge the keypress.
	 *
	 * @param int $remaining Seconds remaining.
	 * @return string JavaScript code.
	 */
	public function countdown_script( int $remaining ): string {
		return sprintf(
			'(function(){' .
				'var r=%d;' .
				'var n=document.getElementById("wp-admin-bar-wp-sudo-active");' .
				'if(!n)return;' .
				'var a=n.querySelector(".ab-item");' .
				'var l=n.querySelector(".ab-label");' .
				'if(!l)return;' .
				'l.setAttribute("role","timer");' .
				'l.setAttribute("aria-live","off");' .
				'l.setAttribute("aria-atomic","true");' .
				// Create a separate live region for milestone announcements
				// so we don't flood AT with every-second updates.
				'var sr=document.createElement("span");' .
				'sr.className="wp-sudo-sr-only";' .
				'sr.setAttribute("role","status");' .
				'sr.setAttribute("aria-live","assertive");' .
				'sr.setAttribute("aria-atomic","true");' .
				'n.appendChild(sr);' .
				// Track which milestones have been announced.
				'var milestones={60:false,30:false,10:false,0:false};' .
				'var intervalId=setInterval(function(){' .
					'r--;' .
					'if(r<=0){' .
						'sr.textContent="Sudo session expired.";' .
						'window.location.reload();return;' .
					'}' .
					'var m=Math.floor(r/60);' .
					'var s=r%%60;' .
					'l.textContent="Sudo: "+m+":"+(s<10?"0":"")+s;' .
					'if(r<=60){n.classList.add("wp-sudo-expiring");}' .
					// Announce at milestone intervals only.
					'if(r===60&&!milestones[60]){' .
						'milestones[60]=true;' .
						'sr.textContent="Sudo session: 1 minute remaining.";' .
					'}else if(r===30&&!milestones[30]){' .
						'milestones[30]=true;' .
						'sr.textContent="Sudo session: 30 seconds remaining.";' .
					'}else if(r===10&&!milestones[10]){' .
						'milestones[10]=true;' .
						'sr.textContent="Sudo session: 10 seconds remaining.";' .
					'}' .
				'},1000);' .
				// Clean up interval on page unload to prevent bfcache issues.
				'window.addEventListener("pagehide",function(){clearInterval(intervalId);});' .
				// Keyboard shortcut: Ctrl+Shift+S / Cmd+Shift+S flashes the
				// admin bar node to acknowledge the session is already active.
				'document.addEventListener("keydown",function(e){' .
					'if(e.shiftKey&&(e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==="s"){' .
						'e.preventDefault();' .
						'if(!a)return;' .
						// Skip animation if user prefers reduced motion.
						'if(window.matchMedia&&window.matchMedia("(prefers-reduced-motion: reduce)").matches)return;' .
						'a.style.setProperty("transition","background 0.15s ease","important");' .
						'a.style.setProperty("background","#4caf50","important");' .
						'setTimeout(function(){a.style.removeProperty("background");a.style.removeProperty("transition");},300);' .
					'}' .
				'});' .
			'})();',
			$remaining
		);
	}
}
