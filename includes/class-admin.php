<?php
/**
 * Admin settings page (v2).
 *
 * Simplified for v2: no allowed-roles setting (gate is role-agnostic),
 * no custom role references. Settings cover session duration and
 * entry-point policies. Also shows a read-only gated actions reference
 * and MU-plugin status.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin
 *
 * Handles the plugin settings page in WP Admin.
 *
 * @since 1.0.0
 * @since 2.0.0 Rewritten: removed allowed_roles, added entry-point policies.
 */
class Admin {

	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	public const OPTION_KEY = 'wp_sudo_settings';

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'wp-sudo-settings';

	/**
	 * AJAX action for installing the MU-plugin shim.
	 *
	 * @var string
	 */
	public const AJAX_MU_INSTALL = 'wp_sudo_mu_install';

	/**
	 * AJAX action for uninstalling the MU-plugin shim.
	 *
	 * @var string
	 */
	public const AJAX_MU_UNINSTALL = 'wp_sudo_mu_uninstall';

	/**
	 * Nonce action for the Request / Rule Tester form.
	 *
	 * @var string
	 */
	public const REQUEST_TESTER_NONCE_ACTION = 'wp_sudo_request_tester';

	/**
	 * Nonce field name for the Request / Rule Tester form.
	 *
	 * @var string
	 */
	public const REQUEST_TESTER_NONCE_NAME = '_wp_sudo_request_tester_nonce';

	/**
	 * Stored marker for the currently active preset.
	 *
	 * @var string
	 */
	public const SETTING_POLICY_PRESET = 'policy_preset';

	/**
	 * Form-only setting key for selecting a preset.
	 *
	 * @var string
	 */
	public const SETTING_POLICY_PRESET_SELECTION = 'policy_preset_selection';

	/**
	 * Form-only flag requiring explicit confirmation before applying a preset.
	 *
	 * @var string
	 */
	public const SETTING_APPLY_POLICY_PRESET = 'apply_policy_preset';

	/**
	 * Preset key for the documented defaults.
	 *
	 * @var string
	 */
	public const POLICY_PRESET_NORMAL = 'normal';

	/**
	 * Preset key for the emergency lockdown mode.
	 *
	 * @var string
	 */
	public const POLICY_PRESET_INCIDENT_LOCKDOWN = 'incident_lockdown';

	/**
	 * Preset key for API-centric environments.
	 *
	 * @var string
	 */
	public const POLICY_PRESET_HEADLESS_FRIENDLY = 'headless_friendly';

	/**
	 * Marker used when current settings no longer match an applied preset.
	 *
	 * @var string
	 */
	public const POLICY_PRESET_CUSTOM = 'custom';

	/**
	 * Transient prefix for one-shot preset summary notices.
	 *
	 * @var string
	 */
	private const PRESET_NOTICE_TRANSIENT_PREFIX = 'wp_sudo_preset_notice_';

	/**
	 * Per-request cache for the full settings array.
	 *
	 * Prevents redundant is_multisite() + get_option/get_site_option
	 * calls when Admin::get() is invoked multiple times per request
	 * (e.g., session duration + policy lookups).
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cached_settings = null;

	/**
	 * Optional Gate instance used by the Request / Rule Tester.
	 *
	 * @var Gate|null
	 */
	private ?Gate $diagnostic_gate = null;

	/**
	 * Constructor.
	 *
	 * @param Gate|null $diagnostic_gate Optional Gate dependency for diagnostics/testing.
	 */
	public function __construct( ?Gate $diagnostic_gate = null ) {
		$this->diagnostic_gate = $diagnostic_gate;
	}

	/**
	 * Register admin hooks.
	 *
	 * On multisite, settings live under Network Admin → Settings and
	 * use site options (network-wide). On single-site, they use the
	 * standard Settings API under Settings → Sudo.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'add_network_settings_page' ), 10, 0 );
			add_action( 'network_admin_edit_wp_sudo_settings', array( $this, 'handle_network_settings_save' ), 10, 0 );
			// Register sections/fields so do_settings_sections() works on the network page.
			add_action( 'admin_init', array( $this, 'register_sections' ), 10, 0 );
		} else {
			add_action( 'admin_menu', array( $this, 'add_settings_page' ), 10, 0 );
			add_action( 'admin_init', array( $this, 'register_settings' ), 10, 0 );
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_app_password_assets' ) );
		add_filter( 'plugin_action_links_' . self::plugin_basename(), array( $this, 'add_action_links' ) );

		// MU-plugin install/uninstall AJAX handlers.
		add_action( 'wp_ajax_' . self::AJAX_MU_INSTALL, array( $this, 'handle_mu_install' ), 10, 0 );
		add_action( 'wp_ajax_' . self::AJAX_MU_UNINSTALL, array( $this, 'handle_mu_uninstall' ), 10, 0 );

		// Replace core's confusing "user editing capabilities" error with
		// a clearer message on the Users page.
		add_action( 'load-users.php', array( $this, 'rewrite_role_error' ), 10, 0 );
		add_action( 'admin_notices', array( $this, 'render_role_error_notice' ), 10, 0 );

		// Per-application-password policy dropdowns on user profile pages.
		add_action( 'wp_ajax_wp_sudo_app_password_policy', array( $this, 'handle_app_password_policy_save' ), 10, 0 );
	}

	/**
	 * Add the settings page to the admin menu.
	 *
	 * @return void
	 */
	public function add_settings_page(): void {
		$hook_suffix = add_options_page(
			__( 'Sudo Settings', 'wp-sudo' ),
			__( 'Sudo', 'wp-sudo' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);

		if ( $hook_suffix ) {
			add_action( 'load-' . $hook_suffix, array( $this, 'add_help_tabs' ), 10, 0 );
		}
	}

	/**
	 * Add the network settings page (multisite only).
	 *
	 * @return void
	 */
	public function add_network_settings_page(): void {
		$hook_suffix = add_submenu_page(
			'settings.php',
			__( 'Sudo Settings', 'wp-sudo' ),
			__( 'Sudo', 'wp-sudo' ),
			'manage_network_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);

		if ( $hook_suffix ) {
			add_action( 'load-' . $hook_suffix, array( $this, 'add_help_tabs' ), 10, 0 );
		}
	}

	/**
	 * Handle the network settings form submission.
	 *
	 * WordPress network admin settings pages POST to edit.php with
	 * `action={page_slug}`. This is the standard pattern used by
	 * WordPress core's own network settings.
	 *
	 * @return void
	 */
	public function handle_network_settings_save(): void {
		check_admin_referer( self::PAGE_SLUG . '-options' );

		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-sudo' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Sanitized via sanitize_settings().
		$input     = isset( $_POST[ self::OPTION_KEY ] ) ? wp_unslash( $_POST[ self::OPTION_KEY ] ) : array();
		$sanitized = $this->sanitize_settings( (array) $input );

		update_site_option( self::OPTION_KEY, $sanitized );
		self::reset_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'updated' => 'true',
				),
				network_admin_url( 'settings.php' )
			)
		);
		exit;
	}

	/**
	 * Register contextual help tabs on the settings page.
	 *
	 * @return void
	 */
	public function add_help_tabs(): void {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-how-it-works',
				'title'   => __( 'How Sudo Works', 'wp-sudo' ),
				'content' =>
					'<h3>' . __( 'Zero-Trust Reauthentication', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'WP Sudo brings zero-trust principles to WordPress admin operations. A valid login session is never sufficient on its own — dangerous operations require explicit identity confirmation every time.', 'wp-sudo' ) . '</p>'
					. '<p>' . __( 'This is role-agnostic: administrators, editors, and any custom role are all challenged equally. Sessions are time-bounded and non-extendable. WordPress capability checks still run after the gate.', 'wp-sudo' ) . '</p>'
					. '<p>' . __( 'Browser requests (admin UI, AJAX, REST with cookie auth) get an interactive challenge. Non-interactive entry points (WP-CLI, Cron, XML-RPC, App Passwords, WPGraphQL) are governed by configurable policies.', 'wp-sudo' ) . '</p>'
					. '<h3>' . __( 'Keyboard Shortcut', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'Press Ctrl+Shift+S (Windows/Linux) or Cmd+Shift+S (Mac) to open the sudo challenge without triggering a gated action first. This is useful when you know you are about to perform several gated actions and want to authenticate once upfront. When a session is already active, the shortcut flashes the admin bar timer.', 'wp-sudo' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-session-policies',
				'title'   => __( 'Session &amp; Policies', 'wp-sudo' ),
				'content' =>
					'<h3>' . __( 'Session Duration', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'This setting controls how long the sudo session stays open after reauthentication. Once the session expires, the next gated action will require another challenge. The maximum duration is 15 minutes.', 'wp-sudo' ) . '</p>'
					. '<h3>' . __( 'Entry Point Policies', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'Each non-interactive entry point (REST API, WP-CLI, Cron, XML-RPC, WPGraphQL) has three modes:', 'wp-sudo' ) . '</p>'
					. '<ul>'
					. '<li>' . __( '<strong>Disabled</strong> — Shuts off the entire surface/protocol. No requests are processed, no checks run, nothing is logged.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>Limited</strong> (default) — Only gated (dangerous) actions are blocked and logged. Non-gated operations work normally.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>Unrestricted</strong> — Everything passes through as if WP Sudo is not installed. No checks, no logging.', 'wp-sudo' ) . '</li>'
					. '</ul>'
				. ( function_exists( 'graphql' )
					? '<p>' . __( 'WPGraphQL works differently from the other surfaces: when set to Limited, all GraphQL mutations require an active sudo session — the block is at the surface level rather than per-action.', 'wp-sudo' ) . '</p>'
					: '<p>' . __( 'WPGraphQL is also supported as an entry point — its policy setting appears on this page when WPGraphQL is installed.', 'wp-sudo' ) . '</p>' )
				. '<h3>' . __( 'Connectors', 'wp-sudo' ) . '</h3>'
				. '<p>' . __( 'Settings updates that include AI provider API keys — such as those managed by the WordPress 7.0 Connectors feature, when present — are gated separately via the <code>connectors.update_credentials</code> rule. This rule matches REST API requests that write connector credential fields, regardless of surface policy.', 'wp-sudo' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-policy-presets',
				'title'   => __( 'Policy Presets', 'wp-sudo' ),
				'content' =>
					'<h3>' . __( 'Policy Presets', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'Presets apply a named set of entry-point policies in one step. Selecting a preset overwrites the individual surface policy fields.', 'wp-sudo' ) . '</p>'
					. '<ul>'
					. '<li>' . __( '<strong>Normal</strong> — All surfaces set to Limited. This is the recommended baseline: every remote surface remains available, but gated operations are blocked.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>Incident Lockdown</strong> — Disables REST, CLI, XML-RPC, and GraphQL. Cron stays Limited so scheduled maintenance can continue. Use during active incident response.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>Headless Friendly</strong> — REST and GraphQL are Unrestricted for headless front-ends and API consumers. CLI and Cron stay Limited. XML-RPC is Disabled.', 'wp-sudo' ) . '</li>'
					. '</ul>'
					. '<p>' . __( 'After selecting a preset, manually editing any individual policy changes the configuration to Custom. Select a preset again to return to a named configuration.', 'wp-sudo' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-app-passwords',
				'title'   => __( 'App Passwords', 'wp-sudo' ),
				'content' =>
					'<h3>' . __( 'Per-Application-Password Policies', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'Individual application passwords can override the global REST API policy. On the user profile page, each application password shows a Sudo Policy dropdown. "Global default" inherits the REST API (App Passwords) setting above.', 'wp-sudo' ) . '</p>'
					. '<p>' . __( 'Setting a specific policy on an individual password lets you grant different access levels to different tools — for example, a deployment pipeline can be set to Unrestricted while an AI writing assistant stays Limited.', 'wp-sudo' ) . '</p>'
					. '<p>' . __( 'This is also the recommended way to handle headless AI agents and MCP-based tools: assign each tool its own application password and set an explicit policy, rather than relaxing the global REST API setting.', 'wp-sudo' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-mu-plugin',
				'title'   => __( 'MU-Plugin', 'wp-sudo' ),
				'content' =>
					'<h3>' . __( 'MU-Plugin', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'The optional mu-plugin ensures gate hooks are registered before any other plugin loads. Install or remove it with one click from the MU-Plugin Status section below. The mu-plugin is a stable shim that loads gate code from the main plugin directory, so it stays current with regular plugin updates.', 'wp-sudo' ) . '</p>'
					. '<p>' . __( 'If the one-click installer fails (for example, due to file permission restrictions on your host), install the mu-plugin manually: copy <code>wp-sudo-gate.php</code> from <code>wp-content/plugins/wp-sudo/mu-plugin/</code> into your <code>wp-content/mu-plugins/</code> directory, creating that directory first if it does not exist. The mu-plugin will be active on the next page load.', 'wp-sudo' ) . '</p>'
					. '<h3>' . __( 'Multisite', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'On multisite, settings are network-wide and the settings page appears under Network Admin &rarr; Settings &rarr; Sudo. Sudo sessions are also network-wide &mdash; authenticating on one site covers all sites in the network.', 'wp-sudo' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-security',
				'title'   => __( 'Security Features', 'wp-sudo' ),
				'content' =>
					'<h3>' . __( 'Two-Factor Authentication', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'WP Sudo is compatible with the Two Factor plugin. When a user has two-factor authentication enabled, the sudo challenge requires both a password and a second-factor authentication code. All configured providers (TOTP, email, backup codes, WebAuthn/passkeys, etc.) are supported automatically.', 'wp-sudo' ) . '</p>'
					. '<p>' . __( 'Other 2FA plugins (WP 2FA, Wordfence, AIOS, etc.) can integrate through four hooks. See the Extending tab for details.', 'wp-sudo' ) . '</p>'
					. '<h3>' . __( 'Content Sanitization', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'WP Sudo removes the <code>unfiltered_html</code> capability from the Editor role. This means KSES content filtering is always active for editors — script tags, iframes, and other potentially dangerous HTML are stripped on save. Administrators retain <code>unfiltered_html</code>. The capability is restored if the plugin is deactivated or uninstalled.', 'wp-sudo' ) . '</p>'
					. '<h3>' . __( 'Tamper Detection', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'WP Sudo checks the Editor role on every request. If <code>unfiltered_html</code> reappears (e.g. via direct database modification), it is stripped and the <code>wp_sudo_capability_tampered</code> action fires so logging plugins can record the event.', 'wp-sudo' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-security-model',
				'title'   => __( 'Security Model', 'wp-sudo' ),
				'content' =>
					'<h3>' . __( 'Security Model', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'WP Sudo operates within WordPress\'s plugin API (<code>admin_init</code>, <code>activate_plugin</code>, REST <code>permission_callback</code>, etc.). Gating is only as strong as this hook system.', 'wp-sudo' ) . '</p>'
					. '<h3>' . __( 'Protects Against', 'wp-sudo' ) . '</h3>'
					. '<ul>'
					. '<li>' . __( '<strong>Compromised sessions</strong> &mdash; stolen cookies cannot perform gated actions.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>Insider threats</strong> &mdash; administrators must prove identity before destructive operations.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>Automated abuse</strong> &mdash; headless entry points can be disabled or restricted.', 'wp-sudo' ) . '</li>'
					. '</ul>'
					. '<h3>' . __( 'Does Not Protect Against', 'wp-sudo' ) . '</h3>'
					. '<ul>'
					. '<li>' . __( '<strong>Direct database access</strong> &mdash; SQL changes bypass all hooks.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>File system access</strong> &mdash; scripts loading <code>wp-load.php</code> directly may bypass the gate.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>Plugins that suppress hooks</strong> &mdash; the mu-plugin mitigates this.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>Server-level operations</strong> &mdash; deployment scripts and direct PHP execution are outside hooks.', 'wp-sudo' ) . '</li>'
					. '</ul>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-environment',
				'title'   => __( 'Environment', 'wp-sudo' ),
				'content' =>
					'<h3>' . __( 'Requirements', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'Sudo session tokens require secure httponly cookies. Reverse proxies must pass cookies through to PHP. User meta reads may be served from an object cache; standard WordPress cache invalidation handles this correctly.', 'wp-sudo' ) . '</p>'
					. '<h3>' . __( 'Multisite Scope', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'Network-level operations (network settings, theme management, site creation/deletion, Super Admin grants) are all gated. Subsite General Settings (site title, tagline, admin email, timezone) are not gated because WordPress core already removes the dangerous fields from subsites.', 'wp-sudo' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-recommended-plugins',
				'title'   => __( 'Recommended Plugins', 'wp-sudo' ),
				'content' =>
					'<h3>' . __( 'Complementary Plugins', 'wp-sudo' ) . '</h3>'
					. '<ul>'
					. '<li>' . __( '<strong>Two Factor</strong> &mdash; strongly recommended. Adds a second authentication step (TOTP, email, backup codes) to the sudo challenge.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>WebAuthn Provider for Two Factor</strong> &mdash; recommended alongside Two Factor. Adds passkey and security key (FIDO2/WebAuthn) support so users can reauthenticate with a hardware key or platform passkey.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>WP Activity Log</strong> or <strong>Stream</strong> &mdash; recommended for audit visibility. These logging plugins capture the 10 action hooks WP Sudo currently fires for session lifecycle, policy decisions, gated actions, preset application, and tamper detection. A ready-to-use WSAL sensor bridge is included at <code>bridges/wp-sudo-wsal-sensor.php</code>.', 'wp-sudo' ) . '</li>'
					. '</ul>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-extending',
				'title'   => __( 'Extending', 'wp-sudo' ),
				'content' =>
					'<h3>' . __( 'Custom Gated Actions', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'Developers can add custom rules via the <code>wp_sudo_gated_actions</code> filter. Each rule defines matching criteria for admin UI, AJAX, and REST surfaces. Custom rules appear in the Gated Actions table. All rules — including custom rules — are automatically protected on non-interactive surfaces (CLI, Cron, XML-RPC, App Passwords) via the three-tier policy settings (Disabled, Limited, Unrestricted), even if they don\'t define AJAX or REST criteria.', 'wp-sudo' ) . '</p>'
					. '<h3>' . __( '2FA Authentication Window', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'The default 2FA window is 5 minutes. Use the <code>wp_sudo_two_factor_window</code> filter to adjust it (value in seconds). A visible countdown timer is shown during the authentication step.', 'wp-sudo' ) . '</p>'
					. '<h3>' . __( 'Third-Party 2FA Integration', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'Plugins other than Two Factor can integrate via four hooks:', 'wp-sudo' ) . '</p>'
					. '<ul>'
					. '<li><code>wp_sudo_requires_two_factor</code> — ' . __( 'detect whether the user has 2FA configured.', 'wp-sudo' ) . '</li>'
					. '<li><code>wp_sudo_render_two_factor_fields</code> — ' . __( 'render form fields for the 2FA step.', 'wp-sudo' ) . '</li>'
					. '<li><code>wp_sudo_validate_two_factor</code> — ' . __( 'validate the submitted 2FA code.', 'wp-sudo' ) . '</li>'
					. '<li><code>wp_sudo_two_factor_window</code> — ' . __( 'adjust the authentication time window.', 'wp-sudo' ) . '</li>'
					. '</ul>'
					. '<p>' . __( 'A complete integration guide and a working bridge for WP 2FA by Melapress are included in the <code>docs/</code> and <code>bridges/</code> directories.', 'wp-sudo' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-audit-hooks',
				'title'   => __( 'Audit Hooks', 'wp-sudo' ),
				'content' =>
					'<h3>' . __( 'Available Hooks', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'All hooks are captured by logging plugins like WP Activity Log and Stream.', 'wp-sudo' ) . '</p>'
					. '<ul>'
					. '<li><code>wp_sudo_activated</code> — ' . __( 'Session started.', 'wp-sudo' ) . '</li>'
					. '<li><code>wp_sudo_deactivated</code> — ' . __( 'Session ended.', 'wp-sudo' ) . '</li>'
					. '<li><code>wp_sudo_reauth_failed</code> — ' . __( 'Wrong password.', 'wp-sudo' ) . '</li>'
					. '<li><code>wp_sudo_lockout</code> — ' . __( 'Too many failures.', 'wp-sudo' ) . '</li>'
					. '<li><code>wp_sudo_action_gated</code> — ' . __( 'Intercepted, challenge shown.', 'wp-sudo' ) . '</li>'
					. '<li><code>wp_sudo_action_blocked</code> — ' . __( 'Denied by Limited policy.', 'wp-sudo' ) . '</li>'
					. '<li><code>wp_sudo_action_allowed</code> — ' . __( 'Permitted by Unrestricted policy.', 'wp-sudo' ) . '</li>'
					. '<li><code>wp_sudo_action_replayed</code> — ' . __( 'Stashed request replayed.', 'wp-sudo' ) . '</li>'
					. '<li><code>wp_sudo_policy_preset_applied</code> — ' . __( 'Named surface-policy preset applied.', 'wp-sudo' ) . '</li>'
					. '<li><code>wp_sudo_capability_tampered</code> — ' . __( 'Removed capability re-detected (possible database tampering).', 'wp-sudo' ) . '</li>'
				. '</ul>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-rule-tester',
				'title'   => __( 'Rule Tester', 'wp-sudo' ),
				'content' =>
					'<h3>' . __( 'Rule Tester', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'The Rule Tester is a side-effect-free diagnostic tool. It evaluates how WP Sudo would handle a request with the shape you describe, without actually performing the action.', 'wp-sudo' ) . '</p>'
					. '<p>' . __( 'You can test across three surfaces: Admin (screen + action), AJAX (action name), and REST (method + route). For REST requests, choose the authentication mode (Cookie or App Password) to see how surface policies interact with the matched rule.', 'wp-sudo' ) . '</p>'
					. '<p>' . __( 'Some rules use callback-based matching that inspects request parameters. For example, the <code>connectors.update_credentials</code> rule checks whether REST request body params contain connector API key fields. Use the REST Params field to supply JSON body parameters for testing these rules.', 'wp-sudo' ) . '</p>'
					. '<p>' . __( 'Results show the matched rule (if any), the decision (gated, blocked, allowed, or no match), and the surface that was evaluated.', 'wp-sudo' ) . '</p>',
			)
		);

		$screen->set_help_sidebar(
			'<p><strong>' . __( 'For more information:', 'wp-sudo' ) . '</strong></p>'
			. '<p><a href="https://en.wikipedia.org/wiki/Sudo" target="_blank">' . __( 'About', 'wp-sudo' ) . '<code>sudo</code></a>' . __( ' (*nix command)', 'wp-sudo' ) . '</p>'
			. '<p><a href="https://wordpress.org/plugins/two-factor/" target="_blank">' . __( 'Two Factor plugin', 'wp-sudo' ) . '</a></p>'
			. '<p><a href="https://wordpress.org/plugins/two-factor-provider-webauthn/" target="_blank">' . __( 'WebAuthn Provider', 'wp-sudo' ) . '</a></p>'
			. '<p><a href="https://wordpress.org/plugins/wp-security-audit-log/" target="_blank">' . __( 'WP Activity Log', 'wp-sudo' ) . '</a></p>'
			. '<p><a href="https://wordpress.org/plugins/stream/" target="_blank">' . __( 'Stream', 'wp-sudo' ) . '</a></p>'
			. '<p><a href="https://developer.wordpress.org/plugins/users/roles-and-capabilities/" target="_blank">' . __( 'Roles &amp; Capabilities', 'wp-sudo' ) . '</a></p>'
		);
	}

	/**
	 * Register plugin settings, sections, and fields.
	 *
	 * Used on single-site only. Registers the setting with the Settings
	 * API so `options.php` handles validation and storage.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::PAGE_SLUG,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);

		$this->register_sections();
	}

	/**
	 * Register settings sections and fields.
	 *
	 * Separated from register_settings() so the sections/fields are
	 * available on multisite network admin pages where the Settings API
	 * (register_setting) is not used.
	 *
	 * @return void
	 */
	public function register_sections(): void {
		// Policy presets section.
		add_settings_section(
			'wp_sudo_policy_presets',
			__( 'Policy Presets', 'wp-sudo' ),
			array( $this, 'render_section_policy_presets' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			self::SETTING_POLICY_PRESET_SELECTION,
			__( 'Quick Presets', 'wp-sudo' ),
			array( $this, 'render_field_policy_presets' ),
			self::PAGE_SLUG,
			'wp_sudo_policy_presets',
			array(
				'label_for' => self::SETTING_POLICY_PRESET_SELECTION,
			)
		);

		// Session section.
		add_settings_section(
			'wp_sudo_session',
			__( 'Session Settings', 'wp-sudo' ),
			array( $this, 'render_section_session' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'session_duration',
			__( 'Session Duration (minutes)', 'wp-sudo' ),
			array( $this, 'render_field_session_duration' ),
			self::PAGE_SLUG,
			'wp_sudo_session',
			array( 'label_for' => 'session_duration' )
		);

		// Entry point policies section.
		add_settings_section(
			'wp_sudo_policies',
			__( 'Entry Point Policies', 'wp-sudo' ),
			array( $this, 'render_section_policies' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			Gate::SETTING_REST_APP_PASS_POLICY,
			__( 'REST API (App Passwords)', 'wp-sudo' ),
			array( $this, 'render_field_policy' ),
			self::PAGE_SLUG,
			'wp_sudo_policies',
			array(
				'label_for'   => Gate::SETTING_REST_APP_PASS_POLICY,
				'key'         => Gate::SETTING_REST_APP_PASS_POLICY,
				'description' => __( 'Controls non-cookie-auth REST requests (Application Passwords, Bearer tokens, OAuth). Cookie-auth browser requests always get the sudo challenge. Default: Limited.', 'wp-sudo' ),
			)
		);

		add_settings_field(
			Gate::SETTING_CLI_POLICY,
			__( 'WP-CLI', 'wp-sudo' ),
			array( $this, 'render_field_policy' ),
			self::PAGE_SLUG,
			'wp_sudo_policies',
			array(
				'label_for'   => Gate::SETTING_CLI_POLICY,
				'key'         => Gate::SETTING_CLI_POLICY,
				'description' => __( 'Disabled blocks all WP-CLI commands. Limited blocks only gated operations. Unrestricted allows everything. The wp cron subcommand also respects the Cron policy. Default: Limited.', 'wp-sudo' ),
			)
		);

		add_settings_field(
			Gate::SETTING_CRON_POLICY,
			__( 'Cron', 'wp-sudo' ),
			array( $this, 'render_field_policy' ),
			self::PAGE_SLUG,
			'wp_sudo_policies',
			array(
				'label_for'   => Gate::SETTING_CRON_POLICY,
				'key'         => Gate::SETTING_CRON_POLICY,
				'description' => __( 'Disabled stops all cron execution (WP-Cron and server-level cron). Limited blocks only gated scheduled events. Unrestricted allows everything. Default: Limited.', 'wp-sudo' ),
			)
		);

		add_settings_field(
			Gate::SETTING_XMLRPC_POLICY,
			__( 'XML-RPC', 'wp-sudo' ),
			array( $this, 'render_field_policy' ),
			self::PAGE_SLUG,
			'wp_sudo_policies',
			array(
				'label_for'   => Gate::SETTING_XMLRPC_POLICY,
				'key'         => Gate::SETTING_XMLRPC_POLICY,
				'description' => __( 'Disabled shuts off the entire XML-RPC protocol. Limited blocks only gated operations. Unrestricted allows everything. Default: Limited.', 'wp-sudo' ),
			)
		);

		// Only show the WPGraphQL field when WPGraphQL is active.
		// The setting is stored and enforced regardless — this just hides a
		// non-relevant field when the plugin is absent.
		if ( function_exists( 'graphql' ) ) {
			add_settings_field(
				Gate::SETTING_WPGRAPHQL_POLICY,
				__( 'WPGraphQL', 'wp-sudo' ),
				array( $this, 'render_field_policy' ),
				self::PAGE_SLUG,
				'wp_sudo_policies',
				array(
					'label_for'   => Gate::SETTING_WPGRAPHQL_POLICY,
					'key'         => Gate::SETTING_WPGRAPHQL_POLICY,
					'description' => __( 'Controls WPGraphQL. Disabled blocks all GraphQL requests. Limited blocks mutations without an active sudo session; queries always pass through. Unrestricted allows everything. Default: Limited.', 'wp-sudo' ),
				)
			);
		}
	}

	/**
	 * Return default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'session_duration'          => 15,
			'rest_app_password_policy'  => Gate::POLICY_LIMITED,
			'cli_policy'                => Gate::POLICY_LIMITED,
			'cron_policy'               => Gate::POLICY_LIMITED,
			'xmlrpc_policy'             => Gate::POLICY_LIMITED,
			'wpgraphql_policy'          => Gate::POLICY_LIMITED,
			self::SETTING_POLICY_PRESET => self::POLICY_PRESET_NORMAL,
			'app_password_policies'     => array(),
		);
	}

	/**
	 * Return supported policy-setting keys in display/storage order.
	 *
	 * @return string[]
	 */
	public static function policy_setting_keys(): array {
		return array(
			Gate::SETTING_REST_APP_PASS_POLICY,
			Gate::SETTING_CLI_POLICY,
			Gate::SETTING_CRON_POLICY,
			Gate::SETTING_XMLRPC_POLICY,
			Gate::SETTING_WPGRAPHQL_POLICY,
		);
	}

	/**
	 * Return supported preset definitions.
	 *
	 * @return array<string, array{
	 *     label: string,
	 *     description: string,
	 *     policies: array<string, string>
	 * }>
	 */
	public static function policy_presets(): array {
		return array(
			self::POLICY_PRESET_NORMAL            => array(
				'label'       => __( 'Normal', 'wp-sudo' ),
				'description' => __( 'Restore the recommended baseline: every remote surface remains available, but only gated operations are blocked.', 'wp-sudo' ),
				'policies'    => array(
					Gate::SETTING_REST_APP_PASS_POLICY => Gate::POLICY_LIMITED,
					Gate::SETTING_CLI_POLICY           => Gate::POLICY_LIMITED,
					Gate::SETTING_CRON_POLICY          => Gate::POLICY_LIMITED,
					Gate::SETTING_XMLRPC_POLICY        => Gate::POLICY_LIMITED,
					Gate::SETTING_WPGRAPHQL_POLICY     => Gate::POLICY_LIMITED,
				),
			),
			self::POLICY_PRESET_INCIDENT_LOCKDOWN => array(
				'label'       => __( 'Incident Lockdown', 'wp-sudo' ),
				'description' => __( 'Clamp down remote entry points during incident response while keeping scheduled jobs in Limited mode so routine maintenance can continue.', 'wp-sudo' ),
				'policies'    => array(
					Gate::SETTING_REST_APP_PASS_POLICY => Gate::POLICY_DISABLED,
					Gate::SETTING_CLI_POLICY           => Gate::POLICY_DISABLED,
					Gate::SETTING_CRON_POLICY          => Gate::POLICY_LIMITED,
					Gate::SETTING_XMLRPC_POLICY        => Gate::POLICY_DISABLED,
					Gate::SETTING_WPGRAPHQL_POLICY     => Gate::POLICY_DISABLED,
				),
			),
			self::POLICY_PRESET_HEADLESS_FRIENDLY => array(
				'label'       => __( 'Headless Friendly', 'wp-sudo' ),
				'description' => __( 'Keep intentional API-driven workflows open while tightening legacy or optional remote surfaces.', 'wp-sudo' ),
				'policies'    => array(
					Gate::SETTING_REST_APP_PASS_POLICY => Gate::POLICY_UNRESTRICTED,
					Gate::SETTING_CLI_POLICY           => Gate::POLICY_LIMITED,
					Gate::SETTING_CRON_POLICY          => Gate::POLICY_LIMITED,
					Gate::SETTING_XMLRPC_POLICY        => Gate::POLICY_DISABLED,
					Gate::SETTING_WPGRAPHQL_POLICY     => Gate::POLICY_UNRESTRICTED,
				),
			),
		);
	}

	/**
	 * Get a single setting value.
	 *
	 * On multisite, settings are stored as a network-wide site option.
	 * On single-site, they are a regular option.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default_value Fallback value.
	 * @return mixed
	 */
	public static function get( string $key, mixed $default_value = null ): mixed {
		if ( null === self::$cached_settings ) {
			$settings = is_multisite()
				? get_site_option( self::OPTION_KEY, self::defaults() )
				: get_option( self::OPTION_KEY, self::defaults() );

			self::$cached_settings = is_array( $settings ) ? $settings : self::defaults();
		}

		return self::$cached_settings[ $key ] ?? $default_value ?? self::defaults()[ $key ] ?? null;
	}

	/**
	 * Reset the settings cache.
	 *
	 * Called after settings are saved, and available for tests.
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$cached_settings = null;
	}

	/**
	 * Sanitize settings input.
	 *
	 * @param array<string, mixed> $input Raw input from the settings form.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( array $input ): array {
		$sanitized = array();
		$current   = $this->get_stored_settings();

		// Session duration: 1–15 minutes.
		$sanitized['session_duration'] = (int) ( $input['session_duration'] ?? 15 );
		if ( $sanitized['session_duration'] < 1 || $sanitized['session_duration'] > 15 ) {
			$sanitized['session_duration'] = 15;
		}

		// Entry point policies: disabled, limited, or unrestricted.
		$policy_keys = self::policy_setting_keys();

		$valid_policies = array( Gate::POLICY_DISABLED, Gate::POLICY_LIMITED, Gate::POLICY_UNRESTRICTED );

		foreach ( $policy_keys as $key ) {
			$value             = sanitize_text_field( $input[ $key ] ?? Gate::POLICY_LIMITED );
			$sanitized[ $key ] = in_array( $value, $valid_policies, true ) ? $value : Gate::POLICY_LIMITED;
		}

		// Per-application-password policy overrides (keyed by UUID).
		$app_password_policies = array();
		if ( isset( $input['app_password_policies'] ) && is_array( $input['app_password_policies'] ) ) {
			foreach ( $input['app_password_policies'] as $uuid => $policy_value ) {
				$uuid         = sanitize_text_field( $uuid );
				$policy_value = sanitize_text_field( $policy_value );

				// Only store explicit overrides; empty/default means "use global".
				if ( ! empty( $uuid ) && in_array( $policy_value, $valid_policies, true ) ) {
					$app_password_policies[ $uuid ] = $policy_value;
				}
			}
		}
		$sanitized['app_password_policies'] = $app_password_policies;

		$selected_preset       = $this->sanitize_policy_preset_key( $input[ self::SETTING_POLICY_PRESET_SELECTION ] ?? '' );
		$current_stored_preset = $this->sanitize_policy_preset_key( $current[ self::SETTING_POLICY_PRESET ] ?? self::POLICY_PRESET_NORMAL );

		if ( '' !== $selected_preset && $selected_preset !== $current_stored_preset ) {
			$previous_policies = $this->extract_policy_values( $current );
			$preset_policies   = self::policy_presets()[ $selected_preset ]['policies'];

			foreach ( $preset_policies as $key => $value ) {
				$sanitized[ $key ] = $value;
			}

			$sanitized[ self::SETTING_POLICY_PRESET ] = $selected_preset;

			$this->store_policy_preset_notice( $selected_preset, $previous_policies, $preset_policies );
			$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
			$network = function_exists( 'is_multisite' ) ? is_multisite() : false;

			/**
			 * Fires when an operator applies a named policy preset.
			 *
			 * @since 2.15.0
			 *
			 * @param int    $user_id   Current user applying the preset.
			 * @param string $preset    Preset key.
			 * @param array  $previous  Previous policy values keyed by setting name.
			 * @param array  $current   New policy values keyed by setting name.
			 * @param bool   $network   Whether the current save context is network-wide.
			 */
			do_action(
				'wp_sudo_policy_preset_applied',
				$user_id,
				$selected_preset,
				$previous_policies,
				$preset_policies,
				$network
			);

			return $sanitized;
		}

		$current_marker = $this->sanitize_policy_preset_key( $current[ self::SETTING_POLICY_PRESET ] ?? self::POLICY_PRESET_NORMAL );
		$matched_preset = $this->detect_matching_policy_preset( $sanitized );
		$stored_preset  = self::POLICY_PRESET_CUSTOM;

		if ( self::POLICY_PRESET_CUSTOM === $current_marker ) {
			$stored_preset = $matched_preset ?? self::POLICY_PRESET_CUSTOM;
		} elseif ( '' !== $current_marker ) {
			$stored_preset = $this->policies_match_preset( $sanitized, $current_marker )
				? $current_marker
				: self::POLICY_PRESET_CUSTOM;
		} elseif ( null !== $matched_preset ) {
			$stored_preset = $matched_preset;
		}

		$sanitized[ self::SETTING_POLICY_PRESET ] = $stored_preset;

		return $sanitized;
	}

	/**
	 * Enqueue admin CSS and JS on the plugin settings page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// Single-site: 'settings_page_wp-sudo-settings'
		// Multisite network admin: 'settings_page_wp-sudo-settings'
		// Both produce the same hook suffix from add_options_page / add_submenu_page.
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wp-sudo-admin',
			self::plugin_url() . 'admin/css/wp-sudo-admin.css',
			array(),
			self::plugin_version()
		);

		wp_enqueue_script(
			'wp-sudo-admin',
			self::plugin_url() . 'admin/js/wp-sudo-admin.js',
			array(),
			self::plugin_version(),
			true
		);

		wp_localize_script(
			'wp-sudo-admin',
			'wpSudoAdmin',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'wp_sudo_mu_plugin' ),
				'installAction'   => self::AJAX_MU_INSTALL,
				'uninstallAction' => self::AJAX_MU_UNINSTALL,
				'strings'         => array(
					'genericError' => __( 'An error occurred.', 'wp-sudo' ),
					'networkError' => __( 'A network error occurred. Please try again.', 'wp-sudo' ),
				),
			)
		);
	}

	/**
	 * Add a "Settings" link on the Plugins list page.
	 *
	 * @param array<string> $links Existing action links.
	 * @return array<string>
	 */
	public function add_action_links( array $links ): array {
		$url = is_multisite()
			? network_admin_url( 'settings.php?page=' . self::PAGE_SLUG )
			: admin_url( 'options-general.php?page=' . self::PAGE_SLUG );

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			__( 'Settings', 'wp-sudo' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	// -------------------------------------------------------------------------
	// Render callbacks
	// -------------------------------------------------------------------------

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		$required_cap = is_multisite() ? 'manage_network_options' : 'manage_options';

		if ( ! current_user_can( $required_cap ) ) {
			return;
		}

		$is_network = is_multisite();
		$valid_tabs = array( 'settings', 'actions', 'tester' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab routing only, no state change.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'settings';
		if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
			$active_tab = 'settings';
		}

		$base_url = $is_network
			? network_admin_url( 'settings.php?page=' . self::PAGE_SLUG )
			: admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php $this->render_policy_preset_notice(); ?>
			<?php if ( $is_network && isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible wp-sudo-notice">
					<p><?php esc_html_e( 'Settings saved.', 'wp-sudo' ); ?></p>
				</div>
			<?php endif; ?>
			<p class="description">
				<?php esc_html_e( 'WP Sudo adds a reauthentication step before dangerous operations like activating plugins, deleting users, or changing critical settings. Any user who attempts a gated action must re-enter their password — and complete two-factor authentication if enabled — before proceeding.', 'wp-sudo' ); ?>
			</p>

			<h2 class="nav-tab-wrapper">
				<?php
				$tabs = array(
					'settings' => __( 'Settings', 'wp-sudo' ),
					'actions'  => __( 'Gated Actions', 'wp-sudo' ),
					'tester'   => __( 'Rule Tester', 'wp-sudo' ),
				);
				foreach ( $tabs as $tab_key => $tab_label ) :
					$class = ( $active_tab === $tab_key ) ? 'nav-tab nav-tab-active' : 'nav-tab';
					$url   = add_query_arg( array( 'tab' => $tab_key ), $base_url );
					?>
					<a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $tab_label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<?php
			switch ( $active_tab ) {
				case 'actions':
					$this->render_gated_actions_table();
					break;

				case 'tester':
					$this->render_request_rule_tester();
					break;

				default: // 'settings'.
					$this->render_mu_plugin_status();
					if ( $is_network ) :
						?>
						<form action="<?php echo esc_url( network_admin_url( 'edit.php?action=wp_sudo_settings' ) ); ?>" method="post">
							<?php
							wp_nonce_field( self::PAGE_SLUG . '-options' );
							do_settings_sections( self::PAGE_SLUG );
							submit_button( __( 'Save Settings', 'wp-sudo' ) );
							?>
						</form>
						<?php
					else :
						?>
						<form action="options.php" method="post">
							<?php
							settings_fields( self::PAGE_SLUG );
							do_settings_sections( self::PAGE_SLUG );
							submit_button( __( 'Save Settings', 'wp-sudo' ) );
							?>
						</form>
						<?php
					endif;
					break;
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the read-only gated actions reference table.
	 *
	 * Lists all currently registered gated actions grouped by category,
	 * including any custom rules added via the wp_sudo_gated_actions filter.
	 *
	 * @return void
	 */
	public function render_gated_actions_table(): void {
		$categories = Action_Registry::get_categories();

		if ( empty( $categories ) ) {
			return;
		}

		?>
		<h2><?php esc_html_e( 'Gated Actions', 'wp-sudo' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'The following actions require reauthentication before execution. The surfaces shown (Admin, AJAX, REST) reflect interactive entry points where WordPress provides APIs. All gated actions are also protected on non-interactive surfaces (WP-CLI, Cron, XML-RPC, Application Passwords) via the configurable policy settings above. WPGraphQL is governed separately at the surface level — when the WPGraphQL policy is Limited, all mutations require an active sudo session regardless of which operation is being performed. Developers can add custom rules via the wp_sudo_gated_actions filter.', 'wp-sudo' ); ?>
		</p>
		<table class="widefat striped">
			<caption class="screen-reader-text"><?php esc_html_e( 'Gated actions requiring reauthentication, grouped by category', 'wp-sudo' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Category', 'wp-sudo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Action', 'wp-sudo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Surfaces', 'wp-sudo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $categories as $category ) : ?>
					<?php
					$rules = Action_Registry::get_rules_by_category( $category );
					$first = true;
					foreach ( $rules as $rule ) :
						$surfaces = array();
						if ( ! empty( $rule['admin'] ) ) {
							$surfaces[] = __( 'Admin', 'wp-sudo' );
						}
						if ( ! empty( $rule['ajax'] ) ) {
							$surfaces[] = __( 'AJAX', 'wp-sudo' );
						}
						if ( ! empty( $rule['rest'] ) ) {
							$surfaces[] = __( 'REST', 'wp-sudo' );
						}
						?>
						<tr>
							<td><?php echo $first ? esc_html( ucfirst( $category ) ) : ''; ?></td>
							<td>
								<?php echo esc_html( $rule['label'] ?? $rule['id'] ); ?>
								<code class="wp-sudo-rule-id"><?php echo esc_html( $rule['id'] ); ?></code>
							</td>
							<td><?php echo esc_html( implode( ', ', $surfaces ) ); ?></td>
						</tr>
						<?php
						$first = false;
					endforeach;
					?>
				<?php endforeach; ?>
			<?php if ( function_exists( 'graphql' ) ) : ?>
				<tr>
					<td><?php esc_html_e( 'GraphQL', 'wp-sudo' ); ?></td>
					<td><?php esc_html_e( 'All mutations (surface-level policy — see WPGraphQL setting above)', 'wp-sudo' ); ?></td>
					<td><?php esc_html_e( 'GraphQL', 'wp-sudo' ); ?></td>
				</tr>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the Request / Rule Tester diagnostic panel.
	 *
	 * @return void
	 */
	private function render_request_rule_tester(): void {
		$form_values = $this->get_request_tester_form_values();
		$result      = $this->maybe_get_request_tester_result();
		?>
		<h2><?php esc_html_e( 'Request / Rule Tester', 'wp-sudo' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'See how WP Sudo would evaluate a representative request without executing it. This diagnostic tool is for admin, AJAX, and REST request shapes only.', 'wp-sudo' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( $this->get_request_tester_action_url() ); ?>">
			<?php wp_nonce_field( self::REQUEST_TESTER_NONCE_ACTION, self::REQUEST_TESTER_NONCE_NAME ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="wp-sudo-request-tester-surface"><?php esc_html_e( 'Surface', 'wp-sudo' ); ?></label></th>
						<td>
							<select id="wp-sudo-request-tester-surface" name="wp_sudo_request_tester[surface]">
								<?php foreach ( array( 'admin', 'ajax', 'rest' ) as $surface ) : ?>
									<option value="<?php echo esc_attr( $surface ); ?>" <?php echo selected( $form_values['surface'], $surface, false ); ?>><?php echo esc_html( strtoupper( $surface ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wp-sudo-request-tester-method"><?php esc_html_e( 'Method', 'wp-sudo' ); ?></label></th>
						<td>
							<select id="wp-sudo-request-tester-method" name="wp_sudo_request_tester[method]">
								<?php foreach ( array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ) as $method ) : ?>
									<option value="<?php echo esc_attr( $method ); ?>" <?php echo selected( $form_values['method'], $method, false ); ?>><?php echo esc_html( $method ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wp-sudo-request-tester-url"><?php esc_html_e( 'URL', 'wp-sudo' ); ?></label></th>
						<td>
							<input type="url" class="regular-text code" id="wp-sudo-request-tester-url" name="wp_sudo_request_tester[url]" value="<?php echo esc_attr( (string) $form_values['url'] ); ?>" placeholder="<?php echo esc_attr__( 'https://example.com/wp-admin/plugins.php?action=activate', 'wp-sudo' ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Context', 'wp-sudo' ); ?></th>
						<td>
							<label><input type="checkbox" name="wp_sudo_request_tester[is_authenticated]" value="1" <?php echo checked( $form_values['is_authenticated'], true, false ); ?> /> <?php esc_html_e( 'Authenticated user', 'wp-sudo' ); ?></label><br>
							<label><input type="checkbox" name="wp_sudo_request_tester[has_active_sudo]" value="1" <?php echo checked( $form_values['has_active_sudo'], true, false ); ?> /> <?php esc_html_e( 'Active sudo session', 'wp-sudo' ); ?></label><br>
							<label><input type="checkbox" name="wp_sudo_request_tester[is_network_admin]" value="1" <?php echo checked( $form_values['is_network_admin'], true, false ); ?> /> <?php esc_html_e( 'Network admin context', 'wp-sudo' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wp-sudo-request-tester-rest-auth"><?php esc_html_e( 'REST auth mode', 'wp-sudo' ); ?></label></th>
						<td>
							<select id="wp-sudo-request-tester-rest-auth" name="wp_sudo_request_tester[rest_auth_mode]">
								<?php
								$rest_modes = array(
									'cookie'               => __( 'Cookie / browser nonce', 'wp-sudo' ),
									'application_password' => __( 'Application Password', 'wp-sudo' ),
									'bearer'               => __( 'Bearer / other headless auth', 'wp-sudo' ),
									'none'                 => __( 'None / unknown', 'wp-sudo' ),
								);
								foreach ( $rest_modes as $mode => $label ) :
									?>
									<option value="<?php echo esc_attr( $mode ); ?>" <?php echo selected( $form_values['rest_auth_mode'], $mode, false ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Only used for REST simulations. Admin and AJAX requests ignore this field.', 'wp-sudo' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
			<?php submit_button( __( 'Evaluate Request', 'wp-sudo' ), 'secondary', 'wp_sudo_request_tester_submit', false ); ?>
		</form>
		<?php if ( is_array( $result ) ) : ?>
			<div id="wp-sudo-tester-result" class="notice notice-info inline" style="margin-top: 1em;">
				<p>
					<strong><?php esc_html_e( 'Matched rule:', 'wp-sudo' ); ?></strong>
					<?php echo esc_html( (string) ( $result['matched_rule_label'] ?? '—' ) ); ?>
					<?php if ( ! empty( $result['matched_rule_id'] ) ) : ?>
						<code><?php echo esc_html( (string) $result['matched_rule_id'] ); ?></code>
					<?php endif; ?>
				</p>
				<p><strong><?php esc_html_e( 'Decision:', 'wp-sudo' ); ?></strong> <code><?php echo esc_html( (string) ( $result['decision'] ?? 'allow' ) ); ?></code></p>
				<p><strong><?php esc_html_e( 'Surface:', 'wp-sudo' ); ?></strong> <?php echo esc_html( (string) ( $result['matched_surface'] ?? $form_values['surface'] ) ); ?></p>
				<p><strong><?php esc_html_e( 'Stash/replay eligible:', 'wp-sudo' ); ?></strong> <?php echo ! empty( $result['stash_replay_eligible'] ) ? esc_html__( 'Yes', 'wp-sudo' ) : esc_html__( 'No', 'wp-sudo' ); ?></p>
				<?php if ( ! empty( $result['notes'] ) && is_array( $result['notes'] ) ) : ?>
					<ul style="margin-left: 1.5em; list-style: disc;">
						<?php foreach ( $result['notes'] as $note ) : ?>
							<li><?php echo esc_html( (string) $note ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the MU-plugin status section.
	 *
	 * Shows whether the MU-plugin shim is installed and provides
	 * a button to install or remove it.
	 *
	 * @return void
	 */
	public function render_mu_plugin_status(): void {
		$installed = defined( 'WP_SUDO_MU_LOADED' );
		$mu_dir    = self::get_mu_plugin_dir();

		// Check if the mu-plugins directory (or its parent) is writable.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_is_writable
		$writable = is_dir( $mu_dir ) ? is_writable( $mu_dir ) : is_writable( dirname( $mu_dir ) );
		?>
		<h2><?php esc_html_e( 'Early Gate (MU-Plugin)', 'wp-sudo' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'The optional MU-plugin shim ensures gate hooks are registered before any regular plugin loads. This prevents other plugins from deregistering or bypassing the gate. The shim delegates to a loader inside the plugin directory, so it never needs updating — regular plugin updates handle it automatically.', 'wp-sudo' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'wp-sudo' ); ?></th>
					<td>
						<p id="wp-sudo-mu-status">
							<?php if ( $installed ) : ?>
								<span class="dashicons dashicons-yes-alt" style="color: #00a32a;" aria-hidden="true"></span>
								<?php esc_html_e( 'Installed', 'wp-sudo' ); ?>
							<?php else : ?>
								<span class="dashicons dashicons-warning" style="color: #dba617;" aria-hidden="true"></span>
								<?php esc_html_e( 'Not installed', 'wp-sudo' ); ?>
							<?php endif; ?>
						</p>
						<?php if ( $installed ) : ?>
							<button type="button" class="button" id="wp-sudo-mu-uninstall">
								<?php esc_html_e( 'Remove MU-Plugin', 'wp-sudo' ); ?>
							</button>
						<?php elseif ( $writable ) : ?>
							<button type="button" class="button button-primary" id="wp-sudo-mu-install">
								<?php esc_html_e( 'Install MU-Plugin', 'wp-sudo' ); ?>
							</button>
							<details style="margin-top: 0.75em;">
								<summary><?php esc_html_e( 'Manual install instructions', 'wp-sudo' ); ?></summary>
								<ol style="margin: 0.5em 0 0 1.5em;">
									<li>
										<?php esc_html_e( 'Locate the shim file inside the plugin directory:', 'wp-sudo' ); ?><br>
										<code>wp-content/plugins/wp-sudo/mu-plugin/wp-sudo-gate.php</code>
									</li>
									<li>
										<?php esc_html_e( 'Copy it into your mu-plugins directory:', 'wp-sudo' ); ?><br>
										<code>wp-content/mu-plugins/wp-sudo-gate.php</code>
									</li>
									<li><?php esc_html_e( 'Create the mu-plugins directory first if it does not exist.', 'wp-sudo' ); ?></li>
									<li><?php esc_html_e( 'The mu-plugin will be active on the next page load.', 'wp-sudo' ); ?></li>
								</ol>
							</details>
						<?php else : ?>
							<details open style="margin-top: 0.75em;">
								<summary><?php esc_html_e( 'Manual install instructions', 'wp-sudo' ); ?></summary>
								<p class="description" style="margin: 0.5em 0;">
									<?php esc_html_e( 'Your hosting environment does not allow writing to the mu-plugins directory. Install the mu-plugin manually:', 'wp-sudo' ); ?>
								</p>
								<ol style="margin: 0.5em 0 0 1.5em;">
									<li>
										<?php esc_html_e( 'Locate the shim file inside the plugin directory:', 'wp-sudo' ); ?><br>
										<code>wp-content/plugins/wp-sudo/mu-plugin/wp-sudo-gate.php</code>
									</li>
									<li>
										<?php esc_html_e( 'Copy it into your mu-plugins directory:', 'wp-sudo' ); ?><br>
										<code>wp-content/mu-plugins/wp-sudo-gate.php</code>
									</li>
									<li><?php esc_html_e( 'Create the mu-plugins directory first if it does not exist.', 'wp-sudo' ); ?></li>
									<li><?php esc_html_e( 'The mu-plugin will be active on the next page load.', 'wp-sudo' ); ?></li>
								</ol>
							</details>
						<?php endif; ?>
						<span id="wp-sudo-mu-spinner" class="spinner" role="status" aria-label="<?php esc_attr_e( 'Processing…', 'wp-sudo' ); ?>"></span>
						<p id="wp-sudo-mu-message" class="description" role="status" aria-live="polite" aria-atomic="true" tabindex="-1"></p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Check if the MU-plugin shim is installed.
	 *
	 * @return bool True if the shim file exists in mu-plugins/.
	 */
	public static function is_mu_plugin_installed(): bool {
		$mu_dir = defined( 'WPMU_PLUGIN_DIR' )
			? WPMU_PLUGIN_DIR
			: ( WP_CONTENT_DIR . '/mu-plugins' );

		return file_exists( $mu_dir . '/wp-sudo-gate.php' );
	}

	/**
	 * Get the path to the MU-plugins directory.
	 *
	 * @return string Absolute path to the mu-plugins directory.
	 */
	private static function get_mu_plugin_dir(): string {
		return defined( 'WPMU_PLUGIN_DIR' )
			? WPMU_PLUGIN_DIR
			: ( WP_CONTENT_DIR . '/mu-plugins' );
	}

	/**
	 * Handle AJAX request to install the MU-plugin shim.
	 *
	 * Copies the stable shim file from wp-sudo/mu-plugin/wp-sudo-gate.php
	 * to wp-content/mu-plugins/wp-sudo-gate.php. Creates the mu-plugins
	 * directory if it does not exist.
	 *
	 * @return void
	 */
	public function handle_mu_install(): void {
		check_ajax_referer( 'wp_sudo_mu_plugin', '_nonce' );

		$required_cap = is_multisite() ? 'manage_network_options' : 'manage_options';
		if ( ! current_user_can( $required_cap ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'wp-sudo' ) ), 403 );
		}

		$user_id = get_current_user_id();
		if ( ! Sudo_Session::is_active( $user_id ) ) {
			wp_send_json_error(
				array(
					'code'    => 'sudo_required',
					'message' => __( 'A sudo session is required. Activate one then try again.', 'wp-sudo' ),
				),
				403
			);
		}

		$source = self::plugin_dir() . 'mu-plugin/wp-sudo-gate.php';
		$mu_dir = self::get_mu_plugin_dir();
		$dest   = $mu_dir . '/wp-sudo-gate.php';

		if ( ! file_exists( $source ) ) {
			wp_send_json_error( array( 'message' => __( 'Source shim file not found.', 'wp-sudo' ) ) );
		}

		// Create the mu-plugins directory if needed.
		if ( ! is_dir( $mu_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir
			if ( ! mkdir( $mu_dir, 0755, true ) ) {
				wp_send_json_error( array( 'message' => __( 'Could not create mu-plugins directory. Check file permissions.', 'wp-sudo' ) ) );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
		$contents = file_get_contents( $source );
		if ( false === $contents ) {
			wp_send_json_error( array( 'message' => __( 'Could not read source shim file.', 'wp-sudo' ) ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		$written = file_put_contents( $dest, $contents );
		if ( false === $written ) {
			wp_send_json_error( array( 'message' => __( 'Could not write to mu-plugins directory. Check file permissions.', 'wp-sudo' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'MU-plugin installed. It will be active on the next page load.', 'wp-sudo' ) ) );
	}

	/**
	 * Handle AJAX request to uninstall the MU-plugin shim.
	 *
	 * Deletes wp-content/mu-plugins/wp-sudo-gate.php.
	 *
	 * @return void
	 */
	public function handle_mu_uninstall(): void {
		check_ajax_referer( 'wp_sudo_mu_plugin', '_nonce' );

		$required_cap = is_multisite() ? 'manage_network_options' : 'manage_options';
		if ( ! current_user_can( $required_cap ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'wp-sudo' ) ), 403 );
		}

		$user_id = get_current_user_id();
		if ( ! Sudo_Session::is_active( $user_id ) ) {
			wp_send_json_error(
				array(
					'code'    => 'sudo_required',
					'message' => __( 'A sudo session is required. Activate one then try again.', 'wp-sudo' ),
				),
				403
			);
		}

		$mu_file = self::get_mu_plugin_dir() . '/wp-sudo-gate.php';

		if ( ! file_exists( $mu_file ) ) {
			wp_send_json_success( array( 'message' => __( 'MU-plugin is already removed.', 'wp-sudo' ) ) );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		if ( ! unlink( $mu_file ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not remove MU-plugin file. Check file permissions.', 'wp-sudo' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'MU-plugin removed. It will be inactive on the next page load.', 'wp-sudo' ) ) );
	}

	/**
	 * Render the session section description.
	 *
	 * @return void
	 */
	public function render_section_session(): void {
		echo '<p>' . esc_html__( 'Configure how long a sudo session lasts after reauthentication.', 'wp-sudo' ) . '</p>';
	}

	/**
	 * Render the policy presets section description.
	 *
	 * @return void
	 */
	public function render_section_policy_presets(): void {
		echo '<p>' . esc_html__( 'Apply one-click policy bundles for incident response or headless environments. Presets only affect the remote and non-interactive surface settings below.', 'wp-sudo' ) . '</p>';
	}

	/**
	 * Render the policies section description.
	 *
	 * @return void
	 */
	public function render_section_policies(): void {
		echo '<p>' . esc_html__( 'Control how non-interactive entry points handle gated operations. Disabled shuts off the entire surface. Limited (default) blocks only gated actions and logs them. Unrestricted lets everything through with no checks or logging. Browser-based requests (admin UI, AJAX, REST with cookie auth) always get the interactive reauthentication challenge regardless of these settings.', 'wp-sudo' ) . '</p>';
	}

	/**
	 * Render the session duration field.
	 *
	 * @return void
	 */
	public function render_field_session_duration(): void {
		$value = self::get( 'session_duration', 15 );
		printf(
			'<input type="number" id="session_duration" name="%s[session_duration]" value="%d" min="1" max="15" class="small-text" />',
			esc_attr( self::OPTION_KEY ),
			absint( $value )
		);
		echo '<p class="description">' . esc_html__( 'How long a sudo session lasts before automatically expiring. Range: 1–15 minutes. Default: 15 minutes.', 'wp-sudo' ) . '</p>';
	}

	/**
	 * Render the policy preset chooser.
	 *
	 * @return void
	 */
	public function render_field_policy_presets(): void {
		$current_preset = $this->detect_matching_policy_preset( $this->get_stored_settings() ) ?? self::POLICY_PRESET_CUSTOM;
		$presets        = self::policy_presets();

		$select_name = self::OPTION_KEY . '[' . self::SETTING_POLICY_PRESET_SELECTION . ']';

		echo '<select id="' . esc_attr( self::SETTING_POLICY_PRESET_SELECTION ) . '" name="' . esc_attr( $select_name ) . '">';

		foreach ( $presets as $preset_key => $preset ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $preset_key ),
				selected( $current_preset, $preset_key, false ),
				esc_html( $preset['label'] )
			);
		}

		// Show a disabled "Custom" option when current config doesn't match any preset.
		if ( self::POLICY_PRESET_CUSTOM === $current_preset ) {
			printf(
				'<option value="%1$s" selected="selected" disabled>%2$s</option>',
				esc_attr( self::POLICY_PRESET_CUSTOM ),
				esc_html__( 'Custom', 'wp-sudo' )
			);
		}

		echo '</select>';

		// Show the selected preset's description.
		if ( self::POLICY_PRESET_CUSTOM !== $current_preset && isset( $presets[ $current_preset ] ) ) {
			echo '<p class="description">' . esc_html( $presets[ $current_preset ]['description'] ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Current settings do not match any preset. Selecting a preset will overwrite the entry-point policy fields below.', 'wp-sudo' ) . '</p>';
		}
	}

	/**
	 * Render a policy select field (Disabled / Limited / Unrestricted).
	 *
	 * @param array<string, string> $args Field arguments (key, description).
	 * @return void
	 */
	public function render_field_policy( array $args ): void {
		$key   = $args['key'] ?? '';
		$value = self::get( $key, Gate::POLICY_LIMITED );

		printf(
			'<select id="%1$s" name="%2$s[%1$s]">',
			esc_attr( $key ),
			esc_attr( self::OPTION_KEY )
		);
		printf(
			'<option value="disabled" %s>%s</option>',
			selected( $value, Gate::POLICY_DISABLED, false ),
			esc_html__( 'Disabled', 'wp-sudo' )
		);
		printf(
			'<option value="limited" %s>%s</option>',
			selected( $value, Gate::POLICY_LIMITED, false ),
			esc_html__( 'Limited (default)', 'wp-sudo' )
		);
		printf(
			'<option value="unrestricted" %s>%s</option>',
			selected( $value, Gate::POLICY_UNRESTRICTED, false ),
			esc_html__( 'Unrestricted', 'wp-sudo' )
		);
		echo '</select>';

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Get the currently stored settings array.
	 *
	 * @return array<string, mixed>
	 */
	private function get_stored_settings(): array {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'get_site_option' ) ) {
			return self::defaults();
		}

		$settings = is_multisite()
			? get_site_option( self::OPTION_KEY, self::defaults() )
			: get_option( self::OPTION_KEY, self::defaults() );

		return is_array( $settings ) ? array_merge( self::defaults(), $settings ) : self::defaults();
	}

	/**
	 * Get the target URL for the Request / Rule Tester form.
	 *
	 * @return string
	 */
	private function get_request_tester_action_url(): string {
		$base = is_multisite()
			? network_admin_url( 'settings.php?page=' . self::PAGE_SLUG . '&tab=tester' )
			: admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=tester' );

		return $base . '#wp-sudo-tester-result';
	}

	/**
	 * Build default/preserved Request / Rule Tester values.
	 *
	 * @return array<string, mixed>
	 */
	private function get_request_tester_form_values(): array {
		$defaults = array(
			'surface'          => 'admin',
			'method'           => 'GET',
			'url'              => '',
			'is_authenticated' => true,
			'has_active_sudo'  => false,
			'is_network_admin' => false,
			'rest_auth_mode'   => 'cookie',
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only form repopulation for the current admin page.
		$raw = isset( $_POST['wp_sudo_request_tester'] ) && is_array( $_POST['wp_sudo_request_tester'] ) ? wp_unslash( $_POST['wp_sudo_request_tester'] ) : array();

		if ( empty( $raw ) ) {
			return $defaults;
		}

		return array(
			'surface'          => $this->sanitize_request_tester_choice( $raw['surface'] ?? '', array( 'admin', 'ajax', 'rest' ), $defaults['surface'] ),
			'method'           => $this->sanitize_request_tester_choice( strtoupper( sanitize_text_field( (string) ( $raw['method'] ?? '' ) ) ), array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), $defaults['method'] ),
			'url'              => esc_url_raw( (string) ( $raw['url'] ?? '' ) ),
			'is_authenticated' => ! empty( $raw['is_authenticated'] ),
			'has_active_sudo'  => ! empty( $raw['has_active_sudo'] ),
			'is_network_admin' => ! empty( $raw['is_network_admin'] ),
			'rest_auth_mode'   => $this->sanitize_request_tester_choice( $raw['rest_auth_mode'] ?? '', array( 'cookie', 'application_password', 'bearer', 'none' ), $defaults['rest_auth_mode'] ),
		);
	}

	/**
	 * Evaluate the Request / Rule Tester submission, if present.
	 *
	 * @return array<string, mixed>|null
	 */
	private function maybe_get_request_tester_result(): ?array {
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Presence check before explicit nonce validation below.
		if ( empty( $_POST['wp_sudo_request_tester_submit'] ) ) {
			return null;
		}

		check_admin_referer( self::REQUEST_TESTER_NONCE_ACTION, self::REQUEST_TESTER_NONCE_NAME );

		$values = $this->get_request_tester_form_values();

		return $this->get_diagnostic_gate()->evaluate_diagnostic_request(
			array(
				'surface'          => $values['surface'],
				'method'           => $values['method'],
				'url'              => $values['url'],
				'is_authenticated' => $values['is_authenticated'],
				'has_active_sudo'  => $values['has_active_sudo'],
				'is_network_admin' => $values['is_network_admin'],
				'rest_auth_mode'   => $values['rest_auth_mode'],
			)
		);
	}

	/**
	 * Return the Gate instance used by the tester.
	 *
	 * @return Gate
	 */
	private function get_diagnostic_gate(): Gate {
		if ( null === $this->diagnostic_gate ) {
			$this->diagnostic_gate = new Gate( new Sudo_Session(), new Request_Stash() );
		}

		return $this->diagnostic_gate;
	}

	/**
	 * Normalize a Request / Rule Tester select value.
	 *
	 * @param mixed    $value   Raw value.
	 * @param string[] $allowed Allowed normalized values.
	 * @param string   $fallback Fallback value.
	 * @return string
	 */
	private function sanitize_request_tester_choice( mixed $value, array $allowed, string $fallback ): string {
		if ( ! is_string( $value ) ) {
			return $fallback;
		}

		$value = sanitize_text_field( $value );

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Extract only surface policy values from a settings array.
	 *
	 * @param array<string, mixed> $settings Settings array.
	 * @return array<string, string>
	 */
	private function extract_policy_values( array $settings ): array {
		$values = array();
		foreach ( self::policy_setting_keys() as $key ) {
			$value          = $settings[ $key ] ?? Gate::POLICY_LIMITED;
			$values[ $key ] = is_string( $value ) ? $value : Gate::POLICY_LIMITED;
		}

		return $values;
	}

	/**
	 * Sanitize a preset key.
	 *
	 * @param mixed $preset_key Raw preset key.
	 * @return string
	 */
	private function sanitize_policy_preset_key( mixed $preset_key ): string {
		$preset_key = is_string( $preset_key ) ? sanitize_text_field( $preset_key ) : '';
		if ( self::POLICY_PRESET_CUSTOM === $preset_key ) {
			return self::POLICY_PRESET_CUSTOM;
		}

		return array_key_exists( $preset_key, self::policy_presets() ) ? $preset_key : '';
	}

	/**
	 * Detect which preset matches the given policy values.
	 *
	 * @param array<string, mixed> $settings Settings array.
	 * @return string|null
	 */
	private function detect_matching_policy_preset( array $settings ): ?string {
		foreach ( array_keys( self::policy_presets() ) as $preset_key ) {
			if ( $this->policies_match_preset( $settings, $preset_key ) ) {
				return $preset_key;
			}
		}

		return null;
	}

	/**
	 * Check whether the provided settings match a preset exactly.
	 *
	 * @param array<string, mixed> $settings   Settings array.
	 * @param string               $preset_key Preset key.
	 * @return bool
	 */
	private function policies_match_preset( array $settings, string $preset_key ): bool {
		$presets = self::policy_presets();
		if ( ! isset( $presets[ $preset_key ] ) ) {
			return false;
		}

		foreach ( $presets[ $preset_key ]['policies'] as $key => $value ) {
			if ( ! isset( $settings[ $key ] ) || $value !== $settings[ $key ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Store a one-shot summary notice for the next settings page load.
	 *
	 * @param string               $preset_key        Preset key.
	 * @param array<string, mixed> $previous_policies Previous values.
	 * @param array<string, mixed> $new_policies      New values.
	 * @return void
	 */
	private function store_policy_preset_notice( string $preset_key, array $previous_policies, array $new_policies ): void {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		set_transient(
			self::PRESET_NOTICE_TRANSIENT_PREFIX . $user_id,
			array(
				'preset'   => $preset_key,
				'previous' => $previous_policies,
				'current'  => $new_policies,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Render the one-shot preset summary notice, if present.
	 *
	 * @return void
	 */
	private function render_policy_preset_notice(): void {
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'delete_transient' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$notice = get_transient( self::PRESET_NOTICE_TRANSIENT_PREFIX . $user_id );
		if ( ! is_array( $notice ) ) {
			return;
		}

		delete_transient( self::PRESET_NOTICE_TRANSIENT_PREFIX . $user_id );

		$current = is_array( $notice['current'] ?? null ) ? $notice['current'] : array();

		// Group surfaces by policy value.
		$groups = array();
		foreach ( self::policy_setting_keys() as $key ) {
			if ( ! isset( $current[ $key ] ) || ! is_string( $current[ $key ] ) ) {
				continue;
			}
			$groups[ $current[ $key ] ][] = self::surface_label_for_key( $key );
		}

		$preset_label = $this->policy_preset_label(
			is_string( $notice['preset'] ?? '' ) ? $notice['preset'] : self::POLICY_PRESET_CUSTOM
		);

		// When all surfaces share the same value, simplify.
		if ( 1 === count( $groups ) ) {
			$value_label = $this->policy_value_label( array_key_first( $groups ) );
			$summary     = sprintf(
				/* translators: 1: preset label, 2: policy value (e.g. "limited"). */
				__( '%1$s preset applied. All surfaces are now %2$s.', 'wp-sudo' ),
				$preset_label,
				strtolower( $value_label )
			);
		} else {
			// Build grouped fragments: "REST and GraphQL are now unrestricted".
			$fragments = array();
			foreach ( $groups as $value => $names ) {
				$value_label = strtolower( $this->policy_value_label( $value ) );
				$names_str   = self::join_surface_names( $names );
				$verb        = count( $names ) > 1 ? 'are' : 'is';
				$fragments[] = sprintf(
					/* translators: 1: surface names, 2: is/are, 3: policy value. */
					__( '%1$s %2$s now %3$s', 'wp-sudo' ),
					$names_str,
					$verb,
					$value_label
				);
			}
			$summary = sprintf(
				/* translators: 1: preset label, 2: semicolon-separated policy summary. */
				__( '%1$s preset applied. %2$s.', 'wp-sudo' ),
				$preset_label,
				implode( '; ', $fragments )
			);
		}

		printf(
			'<div class="notice notice-success is-dismissible wp-sudo-notice"><p>%1$s</p></div>',
			esc_html( $summary )
		);
	}

	/**
	 * Convert a preset key to a display label.
	 *
	 * @param string $preset_key Preset key.
	 * @return string
	 */
	private function policy_preset_label( string $preset_key ): string {
		if ( self::POLICY_PRESET_CUSTOM === $preset_key ) {
			return __( 'Custom', 'wp-sudo' );
		}

		$presets = self::policy_presets();
		return $presets[ $preset_key ]['label'] ?? __( 'Custom', 'wp-sudo' );
	}

	/**
	 * Convert a policy value to a concise display label.
	 *
	 * @param string $value Policy value.
	 * @return string
	 */
	private function policy_value_label( string $value ): string {
		return match ( $value ) {
			Gate::POLICY_DISABLED => __( 'Disabled', 'wp-sudo' ),
			Gate::POLICY_UNRESTRICTED => __( 'Unrestricted', 'wp-sudo' ),
			default => __( 'Limited', 'wp-sudo' ),
		};
	}

	/**
	 * Map a policy-setting key to a short human-readable surface name.
	 *
	 * @param string $key Setting key (e.g. Gate::SETTING_CLI_POLICY).
	 * @return string Short surface name (e.g. "CLI").
	 */
	private static function surface_label_for_key( string $key ): string {
		return match ( $key ) {
			Gate::SETTING_REST_APP_PASS_POLICY => 'REST',
			Gate::SETTING_CLI_POLICY           => 'CLI',
			Gate::SETTING_CRON_POLICY          => 'Cron',
			Gate::SETTING_XMLRPC_POLICY        => 'XML-RPC',
			Gate::SETTING_WPGRAPHQL_POLICY     => 'GraphQL',
			default                            => $key,
		};
	}

	/**
	 * Join an array of surface names with commas and "and".
	 *
	 * @param string[] $names Surface names.
	 * @return string Joined string (e.g. "REST, CLI, and Cron").
	 */
	private static function join_surface_names( array $names ): string {
		$count = count( $names );
		if ( 0 === $count ) {
			return '';
		}
		if ( 1 === $count ) {
			return $names[0];
		}
		if ( 2 === $count ) {
			return $names[0] . ' and ' . $names[1];
		}

		$last = array_pop( $names );
		return implode( ', ', $names ) . ', and ' . $last;
	}

	/**
	 * Rewrite core's confusing err_admin_role query parameter.
	 *
	 * WordPress core redirects to `users.php?update=err_admin_role` when
	 * a bulk role change skips the current user because the target role
	 * lacks `promote_users`. The resulting error message is unclear.
	 *
	 * This method intercepts the redirect before the page renders and
	 * swaps the value so core's switch statement doesn't match, allowing
	 * us to render a clearer notice instead.
	 *
	 * Hooked at `load-users.php`.
	 *
	 * @since 2.1.0
	 * @return void
	 */
	public function rewrite_role_error(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check.
		if ( ! isset( $_GET['update'] ) || 'err_admin_role' !== $_GET['update'] ) {
			return;
		}

		$url = add_query_arg( 'update', 'wp_sudo_role_error' );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render a clearer notice when a bulk role change skips the current user.
	 *
	 * Replaces core's "The current user's role must have user editing
	 * capabilities" with a message that explains the actual constraint.
	 *
	 * @since 2.1.0
	 * @return void
	 */
	public function render_role_error_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display check.
		if ( ! isset( $_GET['update'] ) || 'wp_sudo_role_error' !== $_GET['update'] ) {
			return;
		}

		echo wp_kses_post(
			wp_get_admin_notice(
				__( 'You can&#8217;t demote yourself to a role that doesn&#8217;t allow you to promote yourself back again.', 'wp-sudo' ),
				array(
					'id'                 => 'message',
					'additional_classes' => array( 'error', 'wp-sudo-notice' ),
					'dismissible'        => true,
				)
			)
		);
	}

	// -------------------------------------------------------------------------
	// Per-Application-Password Policies
	// -------------------------------------------------------------------------

	/**
	 * Enqueue the per-application-password policy script on user profile pages.
	 *
	 * Hooks into admin_enqueue_scripts (already registered) and conditionally
	 * loads on profile.php and user-edit.php.
	 *
	 * @since 2.3.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function maybe_enqueue_app_password_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'profile.php', 'user-edit.php' ), true ) ) {
			return;
		}

		$required_cap = is_multisite() ? 'manage_network_options' : 'manage_options';
		if ( ! current_user_can( $required_cap ) ) {
			return;
		}

		// Determine which user's profile is being viewed.
		$profile_user_id = $this->get_profile_user_id();
		if ( ! $profile_user_id ) {
			return;
		}

		wp_enqueue_script(
			'wp-sudo-app-passwords',
			self::plugin_url() . 'admin/js/wp-sudo-app-passwords.js',
			array( 'wp-a11y' ),
			self::plugin_version(),
			true
		);

		$policies = self::get( 'app_password_policies', array() );

		wp_localize_script(
			'wp-sudo-app-passwords',
			'wpSudoAppPasswords',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wp_sudo_app_password_policy' ),
				'policies' => is_array( $policies ) ? $policies : array(),
				'options'  => array(
					''             => __( 'Global default', 'wp-sudo' ),
					'disabled'     => __( 'Disabled', 'wp-sudo' ),
					'limited'      => __( 'Limited', 'wp-sudo' ),
					'unrestricted' => __( 'Unrestricted', 'wp-sudo' ),
				),
				'i18n'     => array(
					'sudoRequired'       => __( 'A sudo session is required. Activate one then try again.', 'wp-sudo' ),
					'policyAriaLabel'    => __( 'Sudo policy for this application password', 'wp-sudo' ),
					'policyColumnHeader' => __( 'Sudo Policy', 'wp-sudo' ),
					'policyColumnName'   => __( 'Sudo Policy', 'wp-sudo' ),
					'policySaved'        => __( 'Policy saved.', 'wp-sudo' ),
					'policyError'        => __( 'Policy could not be saved.', 'wp-sudo' ),
				),
			)
		);
	}

	/**
	 * Get the user ID from the current profile page context.
	 *
	 * @since 2.3.0
	 *
	 * @return int User ID, or 0 if unavailable.
	 */
	private function get_profile_user_id(): int {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only context; the profile page handles its own nonce.
		if ( isset( $_GET['user_id'] ) ) {
			return absint( $_GET['user_id'] );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return get_current_user_id();
	}

	/**
	 * Handle AJAX save of a per-application-password policy override.
	 *
	 * Expects POST parameters:
	 * - uuid:   The application password UUID.
	 * - policy: The policy value ('disabled', 'limited', 'unrestricted', or '' for global default).
	 *
	 * @since 2.3.0
	 *
	 * @return void
	 */
	public function handle_app_password_policy_save(): void {
		check_ajax_referer( 'wp_sudo_app_password_policy', '_nonce' );

		$required_cap = is_multisite() ? 'manage_network_options' : 'manage_options';
		if ( ! current_user_can( $required_cap ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'wp-sudo' ) ), 403 );
		}

		$user_id = get_current_user_id();
		if ( ! Sudo_Session::is_active( $user_id ) ) {
			wp_send_json_error(
				array(
					'code'    => 'sudo_required',
					'message' => __( 'A sudo session is required. Activate one then try again.', 'wp-sudo' ),
				),
				403
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above via check_ajax_referer; sanitized in helper.
		$uuid = self::sanitize_input_string( $_POST['uuid'] ?? '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above via check_ajax_referer; sanitized in helper.
		$policy = self::sanitize_input_string( $_POST['policy'] ?? '' );

		if ( empty( $uuid ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid application password UUID.', 'wp-sudo' ) ) );
		}

		$valid_policies = array( Gate::POLICY_DISABLED, Gate::POLICY_LIMITED, Gate::POLICY_UNRESTRICTED );

		// Get current settings.
		$settings = is_multisite()
			? get_site_option( self::OPTION_KEY, self::defaults() )
			: get_option( self::OPTION_KEY, self::defaults() );

		if ( ! is_array( $settings ) ) {
			$settings = self::defaults();
		}

		if ( ! isset( $settings['app_password_policies'] ) || ! is_array( $settings['app_password_policies'] ) ) {
			$settings['app_password_policies'] = array();
		}

		if ( empty( $policy ) || ! in_array( $policy, $valid_policies, true ) ) {
			// Empty means "use global default" — remove the override.
			unset( $settings['app_password_policies'][ $uuid ] );
		} else {
			$settings['app_password_policies'][ $uuid ] = $policy;
		}

		if ( is_multisite() ) {
			update_site_option( self::OPTION_KEY, $settings );
		} else {
			update_option( self::OPTION_KEY, $settings );
		}
		self::reset_cache();

		wp_send_json_success( array( 'message' => __( 'Policy saved.', 'wp-sudo' ) ) );
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
	 * Resolve plugin directory constant safely for static analysis and bootstrap edge cases.
	 *
	 * @return string
	 */
	private static function plugin_dir(): string {
		return defined( 'WP_SUDO_PLUGIN_DIR' ) ? (string) WP_SUDO_PLUGIN_DIR : dirname( __DIR__ ) . '/';
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
}
