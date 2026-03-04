<?php
/**
 * Main plugin orchestrator (v2).
 *
 * Bootstraps all components for action-gated reauthentication.
 * No custom roles — gating is role-agnostic and covers every entry point.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 *
 * Creates and wires all plugin components.
 *
 * @since 1.0.0
 * @since 2.0.0 Rewritten: removed Site_Manager_Role and Modal_Reauth;
 *              added Gate, Challenge, Admin_Bar.
 */
class Plugin {

	/**
	 * Gate (multi-surface interceptor) instance.
	 *
	 * @var Gate|null
	 */
	private ?Gate $gate = null;

	/**
	 * Challenge (interstitial reauth page) instance.
	 *
	 * @var Challenge|null
	 */
	private ?Challenge $challenge = null;

	/**
	 * Admin bar (countdown UI) instance.
	 *
	 * @var Admin_Bar|null
	 */
	private ?Admin_Bar $admin_bar = null;

	/**
	 * Site Health integration instance.
	 *
	 * @var Site_Health|null
	 */
	private ?Site_Health $site_health = null;

	/**
	 * Admin settings instance.
	 *
	 * @var Admin|null
	 */
	private ?Admin $admin = null;

	/**
	 * Upgrader instance.
	 *
	 * @var Upgrader|null
	 */
	private ?Upgrader $upgrader = null;

	/**
	 * Initialize the plugin and register hooks.
	 *
	 * Called at `plugins_loaded`. All interactive gating hooks (admin_init,
	 * rest_request_before_callbacks) are registered here. Non-interactive
	 * early hooks (CLI, Cron, XML-RPC) are also registered unless the
	 * mu-plugin has already claimed them.
	 *
	 * @return void
	 */
	public function init(): void {
		// Load translations.
		load_plugin_textdomain( 'wp-sudo', false, dirname( self::plugin_basename() ) . '/languages' );

		// Run any pending upgrade routines (must run before other components).
		// Only on admin/CLI requests — front-end visitors never trigger migrations.
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$this->upgrader = new Upgrader();
			$this->upgrader->maybe_upgrade();
		}

		// Shared dependencies used by multiple components.
		$session = new Sudo_Session();
		$stash   = new Request_Stash();

		// Gate: intercepts gated operations on all surfaces.
		$this->gate = new Gate( $session, $stash );
		$this->gate->register();

		// Register early hooks only if the mu-plugin has not already done so.
		if ( ! defined( 'WP_SUDO_MU_LOADED' ) ) {
			$this->gate->register_early();
		}

		// Challenge: interstitial page for admin UI reauthentication.
		$this->challenge = new Challenge( $stash );
		$this->challenge->register();

		// Admin bar: countdown UI when session is active.
		$this->admin_bar = new Admin_Bar();
		$this->admin_bar->register();

		// Enforce unfiltered_html restriction on every request (tamper detection).
		add_action( 'init', array( $this, 'enforce_editor_unfiltered_html' ), 1, 0 );

		// Notice styles: ensure white background on WP Sudo admin notices (WP 7.0+).
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_notice_css' ), 10, 0 );

		// Keyboard shortcut: enqueue on admin pages when no active session.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_shortcut' ), 10, 0 );

		// Gate UI: disable action buttons on gated pages when no session.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_gate_ui' ) );

		// Login grant: a successful form login implicitly satisfies reauthentication.
		// wp_login fires for browser-based logins only (not App Passwords / XML-RPC),
		// so the session cookie set by activate() is guaranteed to reach the browser.
		add_action( 'wp_login', array( $this, 'grant_session_on_login' ), 10, 2 );

		// Password change: expire any active sudo session when credentials change.
		// after_password_reset fires for the lost-password reset flow.
		// profile_update fires for all profile saves (profile.php, user-edit.php, REST API);
		// we compare the password hash to detect an actual change.
		add_action( 'after_password_reset', array( $this, 'deactivate_session_on_password_reset' ), 10, 2 );
		add_action( 'profile_update', array( $this, 'deactivate_session_on_profile_update' ), 10, 3 );

		// Admin settings page (admin-only).
		if ( is_admin() ) {
			$this->admin = new Admin();
			$this->admin->register();

			$this->site_health = new Site_Health();
			$this->site_health->register();
		}
	}

	/**
	 * Enqueue the admin notice stylesheet on all admin pages.
	 *
	 * Ensures WP Sudo notices retain a white background in WordPress 7.0+,
	 * where core removed the explicit background-color from .notice.
	 *
	 * @return void
	 */
	public function enqueue_notice_css(): void {
		if ( ! get_current_user_id() ) {
			return;
		}

		wp_enqueue_style(
			'wp-sudo-notices',
			self::plugin_url() . 'admin/css/wp-sudo-notices.css',
			array(),
			self::plugin_version()
		);
	}

	/**
	 * Enqueue the keyboard shortcut script on admin pages.
	 *
	 * The shortcut (Ctrl+Shift+S / Cmd+Shift+S) navigates to the
	 * challenge page in session-only mode for proactive sudo activation.
	 * Only loads when no sudo session is active and not on the challenge
	 * page itself.
	 *
	 * @return void
	 */
	public function enqueue_shortcut(): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		// Don't load if sudo is already active.
		if ( Sudo_Session::is_active( $user_id ) ) {
			return;
		}

		// Don't load on the challenge page — it has its own JS.
		$page = self::sanitize_input_string( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Routing check only; sanitized in helper.
		if ( 'wp-sudo-challenge' === $page ) {
			return;
		}

		wp_enqueue_script(
			'wp-sudo-shortcut',
			self::plugin_url() . 'admin/js/wp-sudo-shortcut.js',
			array(),
			self::plugin_version(),
			true
		);

		$challenge_url = add_query_arg(
			array(
				'page'       => 'wp-sudo-challenge',
				'return_url' => $this->get_current_admin_url(),
			),
			is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' )
		);

		wp_localize_script(
			'wp-sudo-shortcut',
			'wpSudoShortcut',
			array(
				'challengeUrl' => $challenge_url,
			)
		);
	}

	/**
	 * Enqueue the gate UI script on gated admin pages.
	 *
	 * Disables Install, Activate, Update, and Delete buttons on theme
	 * and plugin pages when no sudo session is active. Also renders a
	 * persistent admin notice with a link to the challenge page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_gate_ui( string $hook_suffix = '' ): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		// Don't disable buttons when a sudo session is active.
		if ( Sudo_Session::is_active( $user_id ) ) {
			return;
		}

		// Map admin page hook suffixes to page identifiers.
		$page_map = array(
			'theme-install.php'  => 'theme-install',
			'themes.php'         => 'themes',
			'plugin-install.php' => 'plugin-install',
			'plugins.php'        => 'plugins',
		);

		$page = $page_map[ $hook_suffix ] ?? null;

		if ( ! $page ) {
			return;
		}

		wp_enqueue_script(
			'wp-sudo-gate-ui',
			self::plugin_url() . 'admin/js/wp-sudo-gate-ui.js',
			array(),
			self::plugin_version(),
			true
		);

		wp_localize_script(
			'wp-sudo-gate-ui',
			'wpSudoGateUi',
			array(
				'page' => $page,
			)
		);
	}

	/**
	 * Grant a sudo session immediately after a successful WordPress login.
	 *
	 * The user just proved their identity via the login form — challenging again
	 * immediately is unnecessary friction. This mirrors the behaviour of Unix sudo
	 * and GitHub's sudo mode: a fresh login implicitly satisfies the
	 * reauthentication requirement.
	 *
	 * wp_login fires for browser-based form logins only (not Application Passwords
	 * or XML-RPC), so session-cookie binding via setcookie() is safe here —
	 * headers have not yet been sent at this point.
	 *
	 * @since 2.6.0
	 *
	 * @param string   $user_login The user's login name (unused; ID is read from object).
	 * @param \WP_User $user       The authenticated user object.
	 * @return void
	 */
	public function grant_session_on_login( string $user_login, \WP_User $user ): void {
		Sudo_Session::activate( $user->ID );
	}

	/**
	 * Expire the sudo session when a user resets their password via the lost-password flow.
	 *
	 * The `after_password_reset` hook fires after the reset form is processed and the new
	 * password has been stored. The sudo session is tied to the old credentials, so it must
	 * be invalidated to enforce re-authentication with the new password.
	 *
	 * @since 2.8.0
	 *
	 * @param \WP_User $user     The user whose password was reset.
	 * @param string   $new_pass New plaintext password (unused; present for hook signature).
	 * @return void
	 */
	public function deactivate_session_on_password_reset( \WP_User $user, string $new_pass ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( get_user_meta( $user->ID, Sudo_Session::META_KEY, true ) ) {
			Sudo_Session::deactivate( $user->ID );
		}
	}

	/**
	 * Expire the sudo session when a profile save includes a password change.
	 *
	 * The `profile_update` hook fires for profile.php, user-edit.php, and REST API
	 * PATCH/PUT on users. Only expires the session when the password hash actually
	 * changed — routine profile saves (display name, email, etc.) must not disturb
	 * an active session.
	 *
	 * @since 2.8.0
	 *
	 * @param int                 $user_id       The user whose profile was updated.
	 * @param \WP_User            $old_user_data The user object before the update.
	 * @param array<string,mixed> $userdata      Raw data array passed to wp_update_user().
	 * @return void
	 */
	public function deactivate_session_on_profile_update( int $user_id, \WP_User $old_user_data, array $userdata ): void {
		if ( isset( $userdata['user_pass'] ) && $old_user_data->user_pass !== $userdata['user_pass'] ) {
			if ( get_user_meta( $user_id, Sudo_Session::META_KEY, true ) ) {
				Sudo_Session::deactivate( $user_id );
			}
		}
	}

	/**
	 * Enforce the unfiltered_html restriction on every request.
	 *
	 * Acts as a tamper-detection canary: if the Editor role has the
	 * unfiltered_html capability (e.g. because `wp_user_roles` was modified
	 * directly in the database), this method strips it and fires the
	 * `wp_sudo_capability_tampered` action so logging plugins like
	 * Stream or WP Activity Log can record the event.
	 *
	 * Hooked at `init` priority 1, before `kses_init` (priority 10),
	 * so KSES is always correctly configured.
	 *
	 * On multisite this is a no-op — WordPress core restricts
	 * unfiltered_html to Super Admins.
	 *
	 * @since 2.1.0
	 * @return void
	 */
	public function enforce_editor_unfiltered_html(): void {
		if ( is_multisite() ) {
			return;
		}

		$editor = get_role( 'editor' );

		if ( ! $editor ) {
			return;
		}

		// Check if the capability is present on the role.
		if ( empty( $editor->capabilities['unfiltered_html'] ) ) {
			return;
		}

		// Tamper detected — strip the capability and fire an audit hook.
		$editor->remove_cap( 'unfiltered_html' );

		/**
		 * Fires when a capability that should have been removed is detected
		 * on a role, indicating possible database tampering.
		 *
		 * @since 2.1.0
		 *
		 * @param string $role       The role slug (e.g. 'editor').
		 * @param string $capability The capability that was re-added (e.g. 'unfiltered_html').
		 */
		do_action( 'wp_sudo_capability_tampered', 'editor', 'unfiltered_html' );
	}

	/**
	 * Plugin activation callback.
	 *
	 * @return void
	 */
	public function activate(): void {
		// Run the upgrader to stamp the version on fresh installs.
		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();

		// Remove unfiltered_html from editors (single-site only).
		self::strip_editor_unfiltered_html();

		// Set a flag so we know the plugin has been activated.
		update_option( 'wp_sudo_activated', true );
	}

	/**
	 * Network-wide activation callback (multisite only).
	 *
	 * Settings and the version stamp are stored as network-wide options,
	 * so a single upgrader run covers all sites.
	 *
	 * WordPress core already restricts unfiltered_html to Super Admins on
	 * multisite, so no capability changes are needed here.
	 *
	 * @return void
	 */
	public function activate_network(): void {
		// Run the upgrader to stamp the version as a network option.
		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();

		// Set a flag so we know the plugin has been network-activated.
		update_site_option( 'wp_sudo_activated', true );
	}

	/**
	 * Plugin deactivation callback.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		// Restore unfiltered_html to editors (single-site only).
		self::restore_editor_unfiltered_html();

		if ( is_multisite() ) {
			delete_site_option( 'wp_sudo_activated' );
		} else {
			delete_option( 'wp_sudo_activated' );
		}
	}

	/**
	 * Get the Gate instance.
	 *
	 * @return Gate|null
	 */
	public function gate(): ?Gate {
		return $this->gate;
	}

	/**
	 * Get the Challenge instance.
	 *
	 * @return Challenge|null
	 */
	public function challenge(): ?Challenge {
		return $this->challenge;
	}

	/**
	 * Get the Admin_Bar instance.
	 *
	 * @return Admin_Bar|null
	 */
	public function admin_bar(): ?Admin_Bar {
		return $this->admin_bar;
	}

	/**
	 * Get the Admin instance.
	 *
	 * @return Admin|null
	 */
	public function admin(): ?Admin {
		return $this->admin;
	}

	/**
	 * Resolve plugin basename constant safely for static analysis and bootstrap edge cases.
	 *
	 * @return string
	 */
	private static function plugin_basename(): string {
		return defined( 'WP_SUDO_PLUGIN_BASENAME' ) ? (string) WP_SUDO_PLUGIN_BASENAME : 'wp-sudo/wp-sudo.php';
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
	 * Build the current admin page URL from the request URI.
	 *
	 * Used to pass a return_url to the challenge page so the user
	 * is redirected back to where they were after authentication.
	 *
	 * @return string The current admin URL, or the admin root as fallback.
	 */
	private function get_current_admin_url(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return is_network_admin() ? network_admin_url() : admin_url();
		}

		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );

		return home_url( $request_uri );
	}

	/**
	 * Remove the unfiltered_html capability from the Editor role.
	 *
	 * On single-site WordPress, editors have unfiltered_html by default,
	 * which lets them embed scripts, iframes, and other non-whitelisted
	 * HTML in post content. This method removes that capability so KSES
	 * content filtering is always active for editors.
	 *
	 * On multisite, WordPress core already restricts unfiltered_html to
	 * Super Admins, so this is a no-op.
	 *
	 * @since 2.1.0
	 * @return void
	 */
	public static function strip_editor_unfiltered_html(): void {
		if ( is_multisite() ) {
			return;
		}

		$editor = get_role( 'editor' );

		if ( $editor ) {
			$editor->remove_cap( 'unfiltered_html' );
		}
	}

	/**
	 * Restore the unfiltered_html capability to the Editor role.
	 *
	 * Called on plugin deactivation and uninstall to leave the site in
	 * its original state.
	 *
	 * @since 2.1.0
	 * @return void
	 */
	public static function restore_editor_unfiltered_html(): void {
		if ( is_multisite() ) {
			return;
		}

		$editor = get_role( 'editor' );

		if ( $editor ) {
			$editor->add_cap( 'unfiltered_html' );
		}
	}
}
