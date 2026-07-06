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
	 * Admin settings instance.
	 *
	 * @var Admin|null
	 */
	private ?Admin $admin = null;

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
		// Load bundled translations for non-WordPress.org installs.
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- GitHub/manual installs still need the bundled language path.
		load_plugin_textdomain( 'wp-sudo', false, dirname( WP_SUDO_PLUGIN_BASENAME ) . '/languages' );

		// Run any pending upgrade routines (must run before other components).
		// Only on admin/CLI requests — front-end visitors never trigger migrations.
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$upgrader = new Upgrader();
			$upgrader->maybe_upgrade();
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

		// WP-CLI commands for operators.
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'sudo', CLI_Command::class );
		}

		// Challenge: interstitial page for admin UI reauthentication.
		$this->challenge = new Challenge( $stash );
		$this->challenge->register();

		// Admin bar: countdown UI when session is active.
		$this->admin_bar = new Admin_Bar();
		$this->admin_bar->register();

		// Dashboard widget: session activity overview.
		Dashboard_Widget::init();

		// Event recorder: log security events to database. Arm the per-request
		// buffer so multiple audit events in one request collapse into a single
		// bulk INSERT on shutdown instead of N synchronous round-trips.
		Event_Recorder::init();
		Event_Recorder::arm_buffer();

		// Cron: prune old event rows daily.
		add_action( 'wp_sudo_prune_events', array( self::class, 'prune_events' ), 10, 0 );

		// Users-list "Sudo Active (N)" badge: invalidate the count cache whenever
		// a session starts or ends. Registered here, unconditionally — sessions
		// are granted on wp_login and torn down via WP-CLI, both outside
		// is_admin(), where the Admin instance below never exists.
		add_action( 'wp_sudo_activated', array( Admin::class, 'flush_sudo_active_count_cache' ), 10, 0 );
		add_action( 'wp_sudo_deactivated', array( Admin::class, 'flush_sudo_active_count_cache' ), 10, 0 );

		// Enforce unfiltered_html restriction on every request (tamper detection).
		add_action( 'init', array( $this, 'enforce_editor_unfiltered_html' ), 1, 0 );

		// Notice styles: ensure white background on WP Sudo admin notices (WP 7.0+).
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_notice_css' ), 10, 0 );

		// Keyboard shortcut: enqueue on admin pages when no active session.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_shortcut' ), 10, 0 );

		// Gate UI: disable action buttons on gated pages when no session.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_gate_ui' ) );

		// Block/site editor: surface a gated action's sudo_required 403 as an
		// in-editor snackbar instead of an opaque dead-end (link-out to challenge).
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_reauth' ), 10, 0 );

		// Login grant: a successful form login implicitly satisfies reauthentication.
		// wp_login fires for browser-based logins only (not App Passwords / XML-RPC),
		// so the session cookie set by activate() is guaranteed to reach the browser.
		// Suppressible via the wp_sudo_grant_session_on_login filter.
		add_action( 'wp_login', array( $this, 'grant_session_on_login' ), 10, 2 );

		// Password change: expire any active sudo session when credentials change.
		// after_password_reset fires for the lost-password reset flow.
		// profile_update fires for all profile saves (profile.php, user-edit.php, REST API);
		// we compare the password hash to detect an actual change.
		add_action( 'after_password_reset', array( $this, 'deactivate_session_on_password_reset' ), 10, 2 );
		add_action( 'profile_update', array( $this, 'deactivate_session_on_profile_update' ), 10, 3 );

		// Logout teardown: end the sudo window when the login session ends, so a
		// captured sudo cookie cannot outlive the login that created it.
		add_action( 'wp_logout', array( $this, 'deactivate_session_on_logout' ), 10, 1 );

		// Capture the login-session token as the auth cookie is issued, so a
		// session granted during the login request (grant_session_on_login) binds
		// to it before $_COOKIE is populated.
		add_action( 'set_logged_in_cookie', array( $this, 'capture_login_session_token' ), 10, 6 );

		// Admin settings page (admin-only).
		if ( is_admin() ) {
			$this->admin = new Admin();
			$this->admin->register();

			$site_health = new Site_Health();
			$site_health->register();
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
			WP_SUDO_PLUGIN_URL . 'admin/css/wp-sudo-notices.css',
			array(),
			WP_SUDO_VERSION
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
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Routing check only; sanitized in helper.
		if ( 'wp-sudo-challenge' === $page ) {
			return;
		}

		wp_enqueue_script(
			'wp-sudo-shortcut',
			WP_SUDO_PLUGIN_URL . 'admin/js/wp-sudo-shortcut.js',
			array(),
			WP_SUDO_VERSION,
			true
		);

		$challenge_url = wp_sudo_build_challenge_url(
			is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' ),
			$this->get_current_admin_url(),
			array( 'page' => 'wp-sudo-challenge' )
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
			WP_SUDO_PLUGIN_URL . 'admin/js/wp-sudo-gate-ui.js',
			array(),
			WP_SUDO_VERSION,
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
	 * Enqueue the block/site-editor reauth handler.
	 *
	 * Loads a build-free `apiFetch` middleware that turns a gated action's
	 * `sudo_required` REST rejection — the editor's opaque 403 dead-end when a
	 * flow such as Block Directory plugin install/activate fires without an
	 * active sudo session — into an in-editor snackbar that links out to the
	 * challenge page (Increment 1: notify + link-out only, no in-editor grant).
	 *
	 * Loaded on every block/site-editor screen for logged-in users, INCLUDING
	 * when a sudo session is active at page load. The editor is a long-lived SPA
	 * and the short sudo session expires while it stays open, so the recovery
	 * handler must already be present when a later gated action returns
	 * sudo_required (condition C2, revised — see the design brief Part 3.6).
	 * Unlike enqueue_shortcut(), it deliberately does NOT skip active sessions.
	 *
	 * Localizes (Increment 2, Task 2) the grant nonce, the AJAX action names, and
	 * the current-site `admin-ajax.php` URL so the in-editor modal can grant a sudo
	 * session in place. The nonce is the single `Challenge::NONCE_ACTION`
	 * (`wp_sudo_challenge`) — a CSRF token reused as-is, never broadened to
	 * authorization (C1). It is localized on block/site-editor screens only, but
	 * loaded even when a session is active (C2, same rationale as the enqueue).
	 * `admin-ajax.php` is resolved via `admin_url()` (current site), never
	 * `network_admin_url()` — a subsite editor must post its grant to its own
	 * `admin-ajax.php`. Only the initial nonce value is minted here; when it goes
	 * stale (editor open past the ~24 h lifetime) a fresh one is fetched at runtime
	 * from the `Challenge::AJAX_REFRESH_NONCE_ACTION` endpoint (whose action name is
	 * localized above, but not a nonce value).
	 *
	 * @since 4.6.0
	 *
	 * @return void
	 */
	public function enqueue_editor_reauth(): void {
		if ( ! get_current_user_id() ) {
			return;
		}

		wp_enqueue_script(
			'wp-sudo-editor-reauth',
			WP_SUDO_PLUGIN_URL . 'admin/js/wp-sudo-editor-reauth.js',
			array( 'wp-api-fetch', 'wp-data', 'wp-notices', 'wp-i18n', 'wp-element', 'wp-components' ),
			WP_SUDO_VERSION,
			true
		);

		wp_localize_script(
			'wp-sudo-editor-reauth',
			'wpSudoEditorReauth',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'nonce'              => wp_create_nonce( Challenge::NONCE_ACTION ),
				'authAction'         => Challenge::AJAX_AUTH_ACTION,
				'twoFactorAction'    => Challenge::AJAX_2FA_ACTION,
				'refreshNonceAction' => Challenge::AJAX_REFRESH_NONCE_ACTION,
			)
		);

		wp_set_script_translations( 'wp-sudo-editor-reauth', 'wp-sudo' );
	}

	/**
	 * Grant a sudo session immediately after a successful WordPress login.
	 *
	 * The user just proved knowledge of the password via the login form — an
	 * immediate password challenge would add friction without a barrier. The
	 * grant is password-strength only: 2FA plugins interrupt on this same
	 * wp_login hook at later priority (Two Factor hooks it at PHP_INT_MAX),
	 * so for 2FA-enrolled users this grant runs before the second factor is
	 * verified.
	 *
	 * wp_login fires for browser-based form logins only (not Application Passwords
	 * or XML-RPC), so session-cookie binding via setcookie() is safe here —
	 * headers have not yet been sent at this point.
	 *
	 * @since 2.6.0
	 * @since 3.3.0 Added the `wp_sudo_grant_session_on_login` filter.
	 *
	 * @param string   $user_login The user's login name (unused; ID is read from object).
	 * @param \WP_User $user       The authenticated user object.
	 * @return void
	 */
	public function grant_session_on_login( string $user_login, \WP_User $user ): void {
		/**
		 * Filters whether a sudo session is granted automatically on login.
		 *
		 * Default true. Any falsy return suppresses the automatic grant, so the
		 * user must pass an explicit challenge at their first gated action.
		 * Intended for shared-terminal/kiosk hardening and SSO integrations.
		 * Note: suppressing the grant for users without a usable WordPress
		 * password (common with SSO) makes gated actions unreachable for them.
		 *
		 * @since 3.3.0
		 *
		 * @param bool     $grant Whether to grant the sudo session. Default true.
		 * @param \WP_User $user  The user who logged in.
		 */
		if ( ! (bool) apply_filters( 'wp_sudo_grant_session_on_login', true, $user ) ) {
			return;
		}

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
	 * Timing note: `profile_update` fires after the new password hash is already
	 * written to the database. An attacker with a stolen cookie would have at most
	 * one request before the session is invalidated on the next `is_active()` check.
	 * The 120-second grace window does not apply here — `deactivate()` ends the
	 * session immediately without waiting for the grace window to close.
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
	 * Expire the sudo session when the user logs out.
	 *
	 * The sudo proof is bound to the login session that created it
	 * (Sudo_Session::SESSION_BIND_META_KEY), so a stale cookie would already
	 * fail verification once the login session is gone. Deactivating here makes
	 * logout a clean boundary: the meta and cookie are cleared immediately
	 * rather than lingering until the next is_active() check or expiry.
	 *
	 * @since 4.1.0
	 *
	 * @param int $user_id The user who logged out. Passed by core since WP 5.5.
	 * @return void
	 */
	public function deactivate_session_on_logout( int $user_id = 0 ): void {
		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}

		if ( $user_id > 0 && get_user_meta( $user_id, Sudo_Session::META_KEY, true ) ) {
			Sudo_Session::deactivate( $user_id );
		}
	}

	/**
	 * Capture the login-session token as the logged-in cookie is issued.
	 *
	 * Hooked to set_logged_in_cookie, which fires inside wp_set_auth_cookie()
	 * during the login request — before $_COOKIE[LOGGED_IN_COOKIE] is populated,
	 * so wp_get_session_token() would otherwise return ''. Stashing the token
	 * here lets a sudo session granted later in the same request (on wp_login)
	 * bind to the correct login session.
	 *
	 * @since 4.1.0
	 *
	 * @param string $logged_in_cookie The logged-in cookie value (unused).
	 * @param int    $expire           Cookie expiry timestamp (unused).
	 * @param int    $expiration       Session expiration timestamp (unused).
	 * @param int    $user_id          The authenticating user ID (unused).
	 * @param string $scheme           Authentication scheme (unused).
	 * @param string $token            The login-session token.
	 * @return void
	 */
	public function capture_login_session_token( string $logged_in_cookie, int $expire, int $expiration, int $user_id, string $scheme, string $token = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Signature parity with the set_logged_in_cookie hook; only $token is consumed.
		Sudo_Session::set_pending_login_token( $token );
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

		// Schedule the daily prune cron event.
		self::schedule_prune_cron();

		// Grant governance capabilities to the activating admin on the site being
		// activated (a single-site install, or one site of a network — this is the
		// per-site activation hook, not network activation). On multisite, super
		// admins are additionally covered by the wp_sudo_can() short-circuit.
		$admin = get_userdata( get_current_user_id() );
		if ( $admin instanceof \WP_User ) {
			$admin->add_cap( 'manage_wp_sudo' );
			$admin->add_cap( 'view_wp_sudo_activity' );
			$admin->add_cap( 'export_wp_sudo_activity' );
			$admin->add_cap( 'revoke_wp_sudo_sessions' );
		}

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

		// Schedule the daily prune cron event.
		self::schedule_prune_cron();

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

		// Clear the daily prune cron event.
		wp_clear_scheduled_hook( 'wp_sudo_prune_events' );

		if ( is_multisite() ) {
			delete_site_option( 'wp_sudo_activated' );
		} else {
			delete_option( 'wp_sudo_activated' );
		}
	}

	/**
	 * Get the Gate instance.
	 *
	 * Exposed for integration tests that exercise the live wired interceptor
	 * (ReauthFlowTest, ChallengeTest, PasswordChangeGatingTest).
	 *
	 * @return Gate|null
	 */
	public function gate(): ?Gate {
		return $this->gate;
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

		$scheme = is_ssl() ? 'https' : 'http';
		$host   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw() sanitizes the full URL; sanitize_text_field() would corrupt encoded path/query segments.
		$request_uri = wp_unslash( $_SERVER['REQUEST_URI'] );

		if ( '' === $host ) {
			return is_network_admin() ? network_admin_url() : admin_url();
		}

		return esc_url_raw( $scheme . '://' . $host . $request_uri );
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

	/**
	 * Schedule the daily prune cron event if not already scheduled.
	 *
	 * @since 2.15.0
	 * @return void
	 */
	public static function schedule_prune_cron(): void {
		if ( ! wp_next_scheduled( 'wp_sudo_prune_events' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_sudo_prune_events' );
		}
	}

	/**
	 * Cron callback: prune event rows older than the retention period.
	 *
	 * @since 2.15.0
	 * @return void
	 */
	public static function prune_events(): void {
		Event_Store::prune( 14 );
	}
}
