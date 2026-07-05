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
	 * Valid settings page tab keys.
	 *
	 * Single source of truth shared by render_settings_page() (tab routing)
	 * and handle_network_settings_save() (redirect tab preservation).
	 *
	 * @var string[]
	 */
	private const VALID_TABS = array( 'settings', 'actions', 'tester', 'access' );

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
	 * AJAX action for granting a governance capability.
	 *
	 * @var string
	 */
	public const AJAX_GRANT_CAP = 'wp_sudo_grant_cap';

	/**
	 * AJAX action for revoking a governance capability.
	 *
	 * @var string
	 */
	public const AJAX_REVOKE_CAP = 'wp_sudo_revoke_cap';

	/**
	 * Admin_post_ action for the per-user Users-list row-action revoke.
	 *
	 * @since 4.5.0
	 * @var string
	 */
	public const ACTION_REVOKE_SESSION_ROW = 'wp_sudo_revoke_session_row';

	/**
	 * Nonce action for the per-user Users-list row-action revoke link.
	 *
	 * @since 4.5.0
	 * @var string
	 */
	public const REVOKE_SESSION_ROW_NONCE_ACTION = 'wp_sudo_revoke_session_row';

	/**
	 * Query arg carrying the per-user/bulk result code after redirect
	 * back to users.php.
	 *
	 * @since 4.5.0
	 * @var string
	 */
	public const REVOKE_RESULT_QUERY_ARG = 'wp_sudo_revoke_result';

	/**
	 * Query arg carrying the number of sessions revoked by a successful
	 * bulk revocation, appended alongside REVOKE_RESULT_QUERY_ARG on redirect.
	 *
	 * @since 4.5.0
	 * @var string
	 */
	public const REVOKE_COUNT_QUERY_ARG = 'wp_sudo_revoke_count';

	/**
	 * Bulk-action key for the Users-list "Revoke sudo sessions" entry.
	 *
	 * Registered via the `bulk_actions-users` filter. Handling runs from a
	 * `load-users.php` interceptor that verifies the `bulk-users` nonce
	 * itself: core does NOT nonce-check custom bulk actions on users.php
	 * (unlike edit.php), so the `handle_bulk_actions-users` filter is
	 * reachable by crafted GET and is deliberately not used.
	 *
	 * @since 4.5.0
	 * @var string
	 */
	public const BULK_REVOKE_SESSIONS_ACTION = 'wp_sudo_revoke_sessions';

	/**
	 * Query arg flagging that a bulk revocation skipped the operator's own
	 * selected row, appended alongside REVOKE_RESULT_QUERY_ARG on redirect.
	 *
	 * @since 4.5.0
	 * @var string
	 */
	public const REVOKE_SKIPPED_SELF_QUERY_ARG = 'wp_sudo_revoke_skipped_self';

	/**
	 * All four governance capabilities managed by the Access tab.
	 *
	 * Mirrors the canonical bootstrap-safe list in wp_sudo_governance_caps()
	 * (includes/functions-governance.php) for use in class contexts.
	 *
	 * @var array<string>
	 */
	public const GOVERNANCE_CAPS = array(
		'manage_wp_sudo',
		'view_wp_sudo_activity',
		'export_wp_sudo_activity',
		'revoke_wp_sudo_sessions',
	);

	/**
	 * Get the operator-facing, translatable label for a governance capability.
	 *
	 * Labels are returned through the text domain at call time so localized
	 * installs get translated capability names — since the slug is no longer
	 * shown as the visible fallback in the Access-tab table, the label is the
	 * primary visible text. Cases are the exact capability slugs used in
	 * GOVERNANCE_CAPS; an unknown slug returns itself.
	 *
	 * @param string $cap Capability slug.
	 * @return string Human-readable label, or the slug when no label exists.
	 */
	private static function get_cap_label( string $cap ): string {
		switch ( $cap ) {
			case 'manage_wp_sudo':
				return __( 'Manage Sudo settings and policies', 'wp-sudo' );
			case 'view_wp_sudo_activity':
				return __( 'View sudo activity and sessions', 'wp-sudo' );
			case 'export_wp_sudo_activity':
				return __( 'Export sudo activity data', 'wp-sudo' );
			case 'revoke_wp_sudo_sessions':
				return __( "Revoke other users' active sessions", 'wp-sudo' );
			default:
				return $cap;
		}
	}

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
	 * Constant name for disabling Passed-event logging via code.
	 *
	 * @var string
	 */
	public const DISABLE_PASSED_EVENT_LOGGING_CONSTANT = 'WP_SUDO_DISABLE_PASSED_EVENT_LOGGING';

	/**
	 * Filter name for enabling/disabling Passed-event logging via code.
	 *
	 * @var string
	 */
	public const PASSED_EVENT_LOGGING_FILTER = 'wp_sudo_log_passed_events_enabled';

	/**
	 * Transient prefix for one-shot preset summary notices.
	 *
	 * @var string
	 */
	private const PRESET_NOTICE_TRANSIENT_PREFIX = 'wp_sudo_preset_notice_';

	/**
	 * Transient prefix for cached active sudo-session user counts.
	 *
	 * @var string
	 */
	private const SUDO_ACTIVE_COUNT_TRANSIENT_PREFIX = 'wp_sudo_active_count_';

	/**
	 * Cache TTL (seconds) for the Users-list active session count badge.
	 *
	 * @var int
	 */
	private const SUDO_ACTIVE_COUNT_CACHE_TTL = 30;

	/**
	 * Transient prefix for per-revoker session-revocation rate limiting.
	 *
	 * @var string
	 */
	private const REVOKE_RATE_PREFIX = '_wp_sudo_revoke_count_';

	/**
	 * Maximum session revocations per revoker per hour.
	 *
	 * @var int
	 */
	private const REVOKE_RATE_LIMIT = 10;

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
	 * Set to true within a request when cleanup_inert_governance_mode_option()
	 * finds and deletes the wp_sudo_governance_mode option. The flag signals
	 * render_compatibility_mode_notice() to show the one-time "fixed"
	 * confirmation. No transient is needed because admin_init and admin_notices
	 * fire in the same HTTP request with no intervening redirect.
	 *
	 * Reset in reset_cache() for test hygiene.
	 *
	 * @since 4.0.0
	 * @var bool
	 */
	private static bool $compat_option_cleared = false;

	/**
	 * Gate instance used by the Request / Rule Tester, lazily built on first use.
	 *
	 * @var Gate|null
	 */
	private ?Gate $diagnostic_gate = null;

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
		add_filter( 'plugin_action_links_' . WP_SUDO_PLUGIN_BASENAME, array( $this, 'add_action_links' ) );

		// Clear-on-detection: delete the inert wp_sudo_governance_mode option on
		// the next admin load after the 4.0.0 upgrade if it was not removed by
		// upgrade_4_0_0() (e.g. version already stamped). Priority 1 ensures this
		// runs before admin_notices so the flag is set when the notice checks it.
		// Note: admin_init does NOT fire under WP-CLI or cron — cleanup is
		// admin-HTTP-only by design; upgrade_4_0_0() covers non-admin contexts.
		add_action( 'admin_init', array( $this, 'cleanup_inert_governance_mode_option' ), 1, 0 );

		// One-time "fixed" confirmation notice after clear-on-detection runs
		// (BRK-03 reworked). Hooked on all admin screens so an operator who
		// never opens the Sudo page still sees the confirmation.
		add_action( 'admin_notices', array( $this, 'render_compatibility_mode_notice' ), 10, 0 );
		add_action( 'network_admin_notices', array( $this, 'render_compatibility_mode_notice' ), 10, 0 );

		// MU-plugin install/uninstall AJAX handlers.
		add_action( 'wp_ajax_' . self::AJAX_MU_INSTALL, array( $this, 'handle_mu_install' ), 10, 0 );
		add_action( 'wp_ajax_' . self::AJAX_MU_UNINSTALL, array( $this, 'handle_mu_uninstall' ), 10, 0 );

		// Governance Access tab: capability grant/revoke handlers.
		add_action( 'wp_ajax_' . self::AJAX_GRANT_CAP, array( $this, 'handle_grant_cap' ), 10, 0 );
		add_action( 'wp_ajax_' . self::AJAX_REVOKE_CAP, array( $this, 'handle_revoke_cap' ), 10, 0 );

		// Per-application-password policy dropdowns on user profile pages.
		add_action( 'wp_ajax_wp_sudo_app_password_policy', array( $this, 'handle_app_password_policy_save' ), 10, 0 );
		// Clean up per-App-Password policy overrides when a password is deleted.
		add_action( 'wp_delete_application_password', array( $this, 'handle_app_password_deleted' ), 10, 2 );

		// Users list screen: Sudo Active filter.
		add_filter( 'views_users', array( $this, 'filter_user_views' ) );
		add_action( 'pre_get_users', array( $this, 'filter_users_by_sudo_active' ) );

		// Users list screen: per-row "Revoke sudo session" action (current-site only).
		add_filter( 'user_row_actions', array( $this, 'user_row_actions' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION_REVOKE_SESSION_ROW, array( $this, 'handle_revoke_session_row_action' ), 10, 0 );

		// Users list screen: "Revoke sudo sessions" bulk action. The dropdown
		// entry registers via bulk_actions-users; handling deliberately does
		// NOT use the handle_bulk_actions-users filter — core fires it for
		// custom actions with no nonce check (and strips _wpnonce first when
		// present), so it cannot be secured. The load-users.php interceptor
		// below runs before core's dispatch, while the bulk-users nonce is
		// still in the request, and verifies it.
		add_filter( 'bulk_actions-users', array( $this, 'register_bulk_revoke_action' ), 10, 1 );
		add_action( 'load-users.php', array( $this, 'handle_bulk_revoke_request' ), 10, 0 );

		// Users list screen: post-redirect result notice for the row-action and bulk-action handlers.
		add_action( 'admin_notices', array( $this, 'render_revoke_result_notice' ), 10, 0 );
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
			'manage_wp_sudo',
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
			'manage_wp_sudo',
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

		if ( ! wp_sudo_can( 'manage_wp_sudo' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-sudo' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Sanitized via sanitize_settings().
		$input     = isset( $_POST[ self::OPTION_KEY ] ) ? wp_unslash( $_POST[ self::OPTION_KEY ] ) : array();
		$sanitized = $this->sanitize_settings( (array) $input );

		update_site_option( self::OPTION_KEY, $sanitized );
		self::reset_cache();

		$target = add_query_arg( array( 'page' => self::PAGE_SLUG ), network_admin_url( 'settings.php' ) );

		// wp_get_referer() prefers the replayed `_wp_http_referer` POST field
		// over the Referer header, which is what carries the tabbed settings
		// URL through a sudo reauth replay (see Action_Registry's
		// options.wp_sudo stash allowlist). A future edit to that allowlist
		// must not drop `_wp_http_referer` or this tab-preservation breaks.
		//
		// The referer is never redirected to directly — only a validated
		// `tab` value is lifted onto our own trusted settings URL, so an
		// attacker-controlled `_wp_http_referer` cannot redirect elsewhere.
		$referer = wp_get_referer();
		if ( $referer ) {
			$query = array();
			$parts = wp_parse_url( $referer );
			if ( ! empty( $parts['query'] ) ) {
				parse_str( $parts['query'], $query );
			}

			if ( isset( $query['page'] ) && self::PAGE_SLUG === $query['page'] && ! empty( $query['tab'] ) ) {
				$tab = sanitize_key( $query['tab'] );
				if ( in_array( $tab, self::VALID_TABS, true ) ) {
					$target = add_query_arg( 'tab', $tab, $target );
				}
			}
		}

		wp_safe_redirect( add_query_arg( 'updated', 'true', $target ) );
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
				'id'      => 'wp-sudo-start-here',
				'title'   => __( 'Start Here', 'wp-sudo' ),
				'content' =>
					'<h3>' . __( 'What Sudo does', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'Sudo verifies that the current user is still the account holder; WordPress still decides whether that user is allowed to perform the action.', 'wp-sudo' ) . '</p>'
					. '<ul>'
					. '<li>' . __( '<strong>Browser requests</strong> (admin, AJAX, cookie-auth REST) get an interactive challenge.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>Non-interactive requests</strong> (WP-CLI, Cron, XML-RPC, App Passwords, WPGraphQL) follow surface policies.', 'wp-sudo' ) . '</li>'
					. '</ul>'
					. '<p>' . __( 'Shortcut: Ctrl+Shift+S (Windows/Linux) or Cmd+Shift+S (Mac) opens the challenge on demand.', 'wp-sudo' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-modes-policies',
				'title'   => __( 'Modes &amp; Policies', 'wp-sudo' ),
				'content' =>
					'<h3>' . __( 'Session duration + mode defaults', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'Use a short session window (1–15 minutes) and keep non-interactive surfaces on Limited by default.', 'wp-sudo' ) . '</p>'
					. '<h4>' . __( 'Surface modes', 'wp-sudo' ) . '</h4>'
					. '<ul>'
					. '<li>' . __( '<strong>Disabled</strong> — surface off.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>Limited</strong> — default; blocks gated actions on that surface.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>Unrestricted</strong> — no Sudo checks on that surface.', 'wp-sudo' ) . '</li>'
					. '</ul>'
					. ( function_exists( 'graphql' )
						? '<p>' . __( 'WPGraphQL note: in Limited mode, mutations are blocked at the surface level unless sudo is active.', 'wp-sudo' ) . '</p>'
						: '<p>' . __( 'WPGraphQL policy appears here when WPGraphQL is installed.', 'wp-sudo' ) . '</p>' )
					. '<h4>' . __( 'Named modes', 'wp-sudo' ) . '</h4>'
					. '<ul>'
					. '<li>' . __( '<strong>Normal</strong> — all surfaces Limited.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>Incident Lockdown</strong> — most remote surfaces Disabled for containment.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>Headless Friendly</strong> — REST/GraphQL Unrestricted; others tightened.', 'wp-sudo' ) . '</li>'
					. '</ul>'
					. '<p>' . __( 'Connectors note: credential writes are gated by <code>connectors.update_credentials</code>. Database-backed connector credentials are per-site; env/wp-config values can apply network-wide.', 'wp-sudo' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-rule-tester',
				'title'   => __( 'Rule Tester', 'wp-sudo' ),
				'content' =>
					'<h3>' . __( 'Safe request diagnostics', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'Rule Tester evaluates request shape without executing the request.', 'wp-sudo' ) . '</p>'
					. '<ul>'
					. '<li>' . __( '<strong>Admin</strong>: screen/action simulation', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>AJAX</strong>: action-name simulation', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>REST</strong>: method/route + auth mode + optional JSON params', 'wp-sudo' ) . '</li>'
					. '</ul>'
					. '<p>' . __( 'Use REST Params for callback-based rules such as <code>connectors.update_credentials</code>.', 'wp-sudo' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-incident-response',
				'title'   => __( 'Incident Response', 'wp-sudo' ),
				'content' =>
					'<h3>' . __( 'Quick response playbook', 'wp-sudo' ) . '</h3>'
					. '<ol>'
					. '<li>' . __( 'Apply <strong>Incident Lockdown</strong>.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( 'Review recent Sudo activity and logging-plugin events.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( 'Revoke exposed application passwords and sudo sessions.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( 'Return to Normal or a reviewed Custom mode after containment.', 'wp-sudo' ) . '</li>'
					. '</ol>'
					. '<p>' . __( 'Sudo audit hooks are designed for external logging systems (for example, WP Activity Log or Stream).', 'wp-sudo' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-security-boundaries',
				'title'   => __( 'Security Boundaries', 'wp-sudo' ),
				'content' =>
					'<h3>' . __( 'What Sudo is strong at', 'wp-sudo' ) . '</h3>'
					. '<ul>'
					. '<li>' . __( '<strong>Compromised sessions</strong> — stolen cookies cannot perform gated actions.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>Destructive admin operations</strong> — reauthentication required before high-risk actions.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>Headless surface control</strong> — each non-interactive entry point has an explicit mode.', 'wp-sudo' ) . '</li>'
					. '</ul>'
					. '<h3>' . __( 'Out of scope', 'wp-sudo' ) . '</h3>'
					. '<ul>'
					. '<li>' . __( '<strong>Direct database access</strong> — SQL changes bypass all hooks.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>Server/file access</strong> — operations outside WordPress hooks.', 'wp-sudo' ) . '</li>'
					. '<li>' . __( '<strong>Broken third-party authorization</strong> inside already-active sudo windows.', 'wp-sudo' ) . '</li>'
					. '</ul>'
					. '<p>' . __( 'MU-plugin hardening, multisite scope, and environment assumptions are in the Security Model.', 'wp-sudo' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'wp-sudo-integrations-developers',
				'title'   => __( 'Developers', 'wp-sudo' ),
				'content' =>
					'<h3>' . __( 'Extension points', 'wp-sudo' ) . '</h3>'
					. '<p>' . __( 'Add custom gated actions with <code>wp_sudo_gated_actions</code> and tune behavior with filters.', 'wp-sudo' ) . '</p>'
					. '<h4>' . __( '2FA integration hooks', 'wp-sudo' ) . '</h4>'
					. '<ul>'
					. '<li><code>wp_sudo_requires_two_factor</code></li>'
					. '<li><code>wp_sudo_render_two_factor_fields</code></li>'
					. '<li><code>wp_sudo_validate_two_factor</code></li>'
					. '<li><code>wp_sudo_two_factor_window</code></li>'
					. '</ul>'
					. '<h4>' . __( 'Audit hooks', 'wp-sudo' ) . '</h4>'
					. '<ul>'
					. '<li><code>wp_sudo_action_gated</code></li>'
					. '<li><code>wp_sudo_action_blocked</code></li>'
					. '<li><code>wp_sudo_action_allowed</code></li>'
					. '<li><code>wp_sudo_action_replayed</code></li>'
					. '<li><code>wp_sudo_policy_preset_applied</code></li>'
					. '</ul>'
					. '<p>' . __( 'See Developer Reference for full signatures and examples.', 'wp-sudo' ) . '</p>',
			)
		);

		$screen->set_help_sidebar(
			'<p><strong>' . __( 'For more information:', 'wp-sudo' ) . '</strong></p>'
			. '<p><a href="https://github.com/dknauss/Sudo/blob/main/docs/FAQ.md" target="_blank">' . __( 'FAQ', 'wp-sudo' ) . '</a></p>'
			. '<p><a href="https://github.com/dknauss/Sudo/blob/main/docs/security-model.md" target="_blank">' . __( 'Security Model', 'wp-sudo' ) . '</a></p>'
			. '<p><a href="https://github.com/dknauss/Sudo/blob/main/docs/developer-reference.md" target="_blank">' . __( 'Developer Reference', 'wp-sudo' ) . '</a></p>'
			. '<p><a href="https://github.com/dknauss/Sudo/blob/main/docs/connectors-api-reference.md" target="_blank">' . __( 'Connectors Reference', 'wp-sudo' ) . '</a></p>'
			. '<p><a href="https://github.com/dknauss/Sudo/blob/main/docs/two-factor-integration.md" target="_blank">' . __( 'Two-Factor Integration', 'wp-sudo' ) . '</a></p>'
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
				'description' => __( 'Controls non-cookie-auth REST requests (Application Passwords, Bearer tokens, OAuth). Cookie-auth browser requests always get the sudo challenge. In multisite, Connectors credentials saved in the database remain per-site, but env or wp-config.php-backed connector keys may still apply across the whole install/network. Default: Limited.', 'wp-sudo' ),
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
				'description' => __( 'Keep intentional API-driven workflows open while tightening legacy or optional remote surfaces. Warning: Unrestricted REST also lets non-cookie callers update database-backed connector credentials without sudo on the current site.', 'wp-sudo' ),
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
	 * Build a key → description map for all presets (including "Custom").
	 *
	 * Used by wp_localize_script so the JS change handler can swap
	 * the description text without a server round-trip.
	 *
	 * @return array<string, string>
	 */
	private static function get_preset_descriptions(): array {
		$descriptions = array();
		foreach ( self::policy_presets() as $key => $preset ) {
			$descriptions[ $key ] = $preset['description'];
		}
		$descriptions[ self::POLICY_PRESET_CUSTOM ] = __( 'Current settings do not match any preset. Selecting a preset will overwrite the entry-point policy fields below.', 'wp-sudo' );
		return $descriptions;
	}

	/**
	 * Build a key → policies map for all presets.
	 *
	 * Used by wp_localize_script so the JS change handler can cascade
	 * preset selection to individual surface dropdowns.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function get_preset_policies(): array {
		$policies = array();
		foreach ( self::policy_presets() as $key => $preset ) {
			$policies[ $key ] = $preset['policies'];
		}
		return $policies;
	}

	/**
	 * Return the list of surface policy setting keys.
	 *
	 * Used by wp_localize_script so the JS reverse-sync handler knows
	 * which <select> elements to read when detecting a matching preset.
	 *
	 * @return list<string>
	 */
	private static function get_surface_keys(): array {
		return array(
			Gate::SETTING_REST_APP_PASS_POLICY,
			Gate::SETTING_CLI_POLICY,
			Gate::SETTING_CRON_POLICY,
			Gate::SETTING_XMLRPC_POLICY,
			Gate::SETTING_WPGRAPHQL_POLICY,
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
		self::$cached_settings       = null;
		self::$compat_option_cleared = false;
	}

	/**
	 * Return whether Passed-event logging is enabled.
	 *
	 * Passed-event logging is enabled by default and intentionally not
	 * configurable from the UI. It may only be disabled via a code-level
	 * override (constant/filter) for exceptional environments.
	 *
	 * @since 3.0.0
	 *
	 * @return bool
	 */
	public static function is_passed_event_logging_enabled(): bool {
		$enabled = true;

		if ( defined( self::DISABLE_PASSED_EVENT_LOGGING_CONSTANT ) && constant( self::DISABLE_PASSED_EVENT_LOGGING_CONSTANT ) ) {
			$enabled = false;
		}

		/**
		 * Filter whether WP Sudo records action_passed events.
		 *
		 * Return false only when a deployment intentionally accepts reduced
		 * audit visibility for actions performed during active sudo sessions.
		 *
		 * @since 3.0.0
		 *
		 * @param bool $enabled Default true unless disabled by constant.
		 */
		return (bool) apply_filters( 'wp_sudo_log_passed_events_enabled', $enabled );
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
			$user_id = get_current_user_id();
			$network = is_multisite();

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
			WP_SUDO_PLUGIN_URL . 'admin/css/wp-sudo-admin.css',
			array(),
			WP_SUDO_VERSION
		);

		wp_enqueue_script(
			'wp-sudo-admin',
			WP_SUDO_PLUGIN_URL . 'admin/js/wp-sudo-admin.js',
			array( 'wp-a11y' ),
			WP_SUDO_VERSION,
			true
		);

		wp_localize_script(
			'wp-sudo-admin',
			'wpSudoAdmin',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'nonce'              => wp_create_nonce( 'wp_sudo_mu_plugin' ),
				'installAction'      => self::AJAX_MU_INSTALL,
				'uninstallAction'    => self::AJAX_MU_UNINSTALL,
				'grantAction'        => self::AJAX_GRANT_CAP,
				'revokeCapAction'    => self::AJAX_REVOKE_CAP,
				'presetDescriptions' => self::get_preset_descriptions(),
				'presetPolicies'     => self::get_preset_policies(),
				'surfaceKeys'        => self::get_surface_keys(),
				'strings'            => array(
					'genericError' => __( 'An error occurred.', 'wp-sudo' ),
					'networkError' => __( 'A network error occurred. Please try again.', 'wp-sudo' ),
				),
				'access'             => array(
					'success'     => __( 'Done.', 'wp-sudo' ),
					'invalidUser' => __( 'Select a user.', 'wp-sudo' ),
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
	// Users list screen: Sudo Active filter
	// -------------------------------------------------------------------------

	/**
	 * Add "Sudo Active (N)" view link to the Users list screen.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, string> $views Existing view links.
	 * @return array<string, string>
	 */
	public function filter_user_views( array $views ): array {
		$count = $this->get_sudo_active_user_count();
		if ( 0 === $count ) {
			return $views;
		}

		$url     = admin_url( 'users.php?sudo_active=1' );
		$current = $this->is_sudo_active_filter_requested() ? ' class="current" aria-current="page"' : '';

		$views['sudo_active'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( $url ),
			$current,
			__( 'Sudo Active', 'wp-sudo' ),
			$count
		);

		return $views;
	}

	/**
	 * Return the number of users with active sudo sessions.
	 *
	 * Uses a count-oriented query so the Users screen does not materialize every
	 * matching user ID just to render the filter badge.
	 *
	 * @since 3.0.0
	 *
	 * @return int
	 */
	private function get_sudo_active_user_count(): int {
		$cache_key = self::SUDO_ACTIVE_COUNT_TRANSIENT_PREFIX . $this->get_current_site_id();

		$cached_count = get_transient( $cache_key );
		if ( is_numeric( $cached_count ) ) {
			return (int) $cached_count;
		}

		$query = new \WP_User_Query(
			array(
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					$this->get_sudo_active_meta_query_clause(),
				),
				'fields'      => 'ID',
				'number'      => 1,
				'count_total' => true,
			)
		);

		$total = (int) $query->get_total();

		set_transient( $cache_key, $total, self::SUDO_ACTIVE_COUNT_CACHE_TTL );

		return $total;
	}

	/**
	 * Filter the Users list query when sudo_active=1 is set.
	 *
	 * @since 3.0.0
	 *
	 * @param \WP_User_Query $query User query object.
	 * @return void
	 */
	public function filter_users_by_sudo_active( $query ): void {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! $this->is_sudo_active_filter_requested() ) {
			return;
		}

		$meta_query = $query->get( 'meta_query' );
		if ( ! is_array( $meta_query ) ) {
			$meta_query = array();
		}

		$meta_query[] = $this->get_sudo_active_meta_query_clause();

		$query->set( 'meta_query', $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	}

	/**
	 * Return whether the explicit sudo_active users filter is requested.
	 *
	 * @since 3.0.0
	 *
	 * @return bool
	 */
	private function is_sudo_active_filter_requested(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list-table filter state.
		$value = isset( $_GET['sudo_active'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['sudo_active'] ) ) : '';

		return '1' === $value;
	}

	/**
	 * Return the meta query clause for active sudo sessions.
	 *
	 * @since 3.0.0
	 *
	 * @return array<string, int|string>
	 */
	private function get_sudo_active_meta_query_clause(): array {
		return array(
			'key'     => '_wp_sudo_expires',
			'value'   => time(),
			'compare' => '>',
			'type'    => 'NUMERIC',
		);
	}

	/**
	 * Resolve the current site ID for per-site cache keys.
	 *
	 * @since 3.0.0
	 *
	 * @return int
	 */
	private function get_current_site_id(): int {
		return (int) get_current_blog_id();
	}

	/**
	 * Add a "Revoke sudo session" row action on the Users list.
	 *
	 * Visibility is gated on Sudo_Session::is_session_live() — the same
	 * browser-independent `_wp_sudo_expires > time()` predicate that drives
	 * the "Sudo Active (N)" count — NOT Sudo_Session::is_active(), which also
	 * requires the *current* request's cookie-bound token. The action is
	 * shown for a cap-holding operator even when the operator's own sudo
	 * session is not active; the per-user handler enforces that gate
	 * separately and fails with a distinct message instead of silently
	 * hiding the control.
	 *
	 * @since 4.5.0
	 *
	 * @param array<string, string> $actions Existing row actions, keyed by action slug.
	 * @param \WP_User              $user    The row's user object.
	 * @return array<string, string>
	 */
	public function user_row_actions( array $actions, \WP_User $user ): array {
		if ( ! wp_sudo_can( 'revoke_wp_sudo_sessions' ) ) {
			return $actions;
		}

		$row_user_id = (int) $user->ID;

		if ( get_current_user_id() === $row_user_id ) {
			return $actions;
		}

		if ( ! Sudo_Session::is_session_live( $row_user_id ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => self::ACTION_REVOKE_SESSION_ROW,
					'user_id' => $row_user_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::REVOKE_SESSION_ROW_NONCE_ACTION
		);

		$actions['wp_sudo_revoke_session'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Revoke sudo session', 'wp-sudo' )
		);

		return $actions;
	}

	/**
	 * Handle the per-user "Revoke sudo session" row-action admin_post_ request.
	 *
	 * Verifies the nonce, requires the revoke_wp_sudo_sessions capability,
	 * requires the operator's OWN active sudo session (a second, distinct
	 * gate from the target's liveness check inside revoke_session_core()),
	 * then delegates to the factored core with the users_list_row_action
	 * reason tag. Every core outcome maps to a distinct redirect query arg —
	 * including target_expired, reached when the target's session expires
	 * between row-render and click (REVK-05: never a silent no-op).
	 *
	 * @since 4.5.0
	 *
	 * @return void
	 */
	public function handle_revoke_session_row_action(): void {
		check_admin_referer( self::REVOKE_SESSION_ROW_NONCE_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_admin_referer.
		$target_user_id = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : 0;

		$result_code = $this->process_revoke_session_row_action( $target_user_id );

		wp_safe_redirect(
			add_query_arg(
				array( self::REVOKE_RESULT_QUERY_ARG => $result_code ),
				admin_url( 'users.php' )
			)
		);
		exit;
	}

	/**
	 * Run the guarded per-user revoke flow and return its result code.
	 *
	 * Separated from handle_revoke_session_row_action() so the redirect/exit
	 * wiring stays in one place while this method's branching maps every
	 * core outcome to a distinct, testable result code.
	 *
	 * @since 4.5.0
	 *
	 * @param int $target_user_id Target user ID from the request.
	 * @return string Result code: no_cap|no-operator-session|self_target|target_expired|success.
	 */
	private function process_revoke_session_row_action( int $target_user_id ): string {
		if ( ! wp_sudo_can( 'revoke_wp_sudo_sessions' ) ) {
			return 'no_cap';
		}

		$operator_id = get_current_user_id();

		// Token-bound gate: require the operator's CURRENT request to carry a
		// valid cookie-bound sudo token (is_active), not merely a live expiry
		// timestamp (is_session_live). The row-action visibility deliberately
		// uses the browser-independent is_session_live so the control still
		// shows and the operator gets a distinct message, but performing the
		// revocation must verify this request actually holds sudo — otherwise a
		// stolen auth cookie, or a second session without its own sudo, could
		// revoke other users' sessions while `_wp_sudo_expires` is still future.
		if ( ! Sudo_Session::is_active( $operator_id ) ) {
			return 'no-operator-session';
		}

		$result = $this->revoke_session_core( $target_user_id, $operator_id, 'users_list_row_action' );

		return $result['outcome'];
	}

	// -------------------------------------------------------------------------
	// Users list screen: "Revoke sudo sessions" bulk action
	// -------------------------------------------------------------------------

	/**
	 * Intercept the "Revoke sudo sessions" bulk submission on load-users.php.
	 *
	 * Runs before users.php dispatches bulk actions, while the list-table's
	 * `bulk-users` nonce is still present in the request — core's own
	 * dispatch fires `handle_bulk_actions-users` for custom actions WITHOUT
	 * any nonce check, and self-redirects to strip `_wpnonce` first when a
	 * referer field is present, so this is the only point where the request
	 * can be nonce-verified. Action detection mirrors the users screen's
	 * verified dispatch exactly: bail when `filter_action` is set (the
	 * Filter button won the submit), bail when `changeit` is set (the
	 * WP_Users_List_Table::current_action() override gives role-change
	 * submits precedence as 'promote'), then read `action` only — the base
	 * current_action() has no action2 fallback, and since WP 5.7 common.js
	 * marries the bottom bulk controls to the top, so the bottom dropdown
	 * always arrives in `action`. Bails in
	 * the network admin: network/users.php rewrites $pagenow to users.php,
	 * so load-users.php fires there too, but that screen is out of scope
	 * (its nonce is bulk-users-network and this handler is site-scoped).
	 *
	 * @since 4.5.0
	 *
	 * @return void
	 */
	public function handle_bulk_revoke_request(): void {
		if ( is_network_admin() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Dispatch detection only; check_admin_referer() runs below before any state change.
		if ( ! empty( $_REQUEST['filter_action'] ) ) {
			return;
		}

		// Mirrors WP_Users_List_Table::current_action(): a role-change submit
		// (changeit) takes precedence and dispatches 'promote', so a stale
		// revoke selection in the dropdown must not hijack a role change.
		if ( isset( $_REQUEST['changeit'] ) ) {
			return;
		}

		$doaction = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['action'] ) ) : '';
		if ( self::BULK_REVOKE_SESSIONS_ACTION !== $doaction ) {
			return;
		}
		// phpcs:enable

		check_admin_referer( 'bulk-users' );

		$user_ids = isset( $_REQUEST['users'] ) ? array_map( 'intval', (array) $_REQUEST['users'] ) : array();
		if ( empty( $user_ids ) ) {
			// Match core: an empty selection falls through to the page render.
			return;
		}

		$sendback = wp_get_referer();
		if ( ! $sendback ) {
			$sendback = admin_url( 'users.php' );
		}

		$sendback = $this->handle_bulk_revoke_sessions( $sendback, $doaction, $user_ids );

		wp_safe_redirect( $sendback );
		exit;
	}

	/**
	 * Add the "Revoke sudo sessions" entry to the Users-list bulk actions.
	 *
	 * Visible whenever the operator holds the revocation capability. The
	 * entry is deliberately NOT conditioned on the cached active-session
	 * count — that cache is up to 30 seconds stale, and a dropdown entry
	 * that flickers in and out would read as broken. Selection validation
	 * makes no-live-session rows a safe no-op instead.
	 *
	 * @since 4.5.0
	 *
	 * @param array<string, string> $actions Registered bulk actions.
	 * @return array<string, string>
	 */
	public function register_bulk_revoke_action( array $actions ): array {
		if ( ! wp_sudo_can( 'revoke_wp_sudo_sessions' ) ) {
			return $actions;
		}

		$actions[ self::BULK_REVOKE_SESSIONS_ACTION ] = __( 'Revoke sudo sessions', 'wp-sudo' );

		return $actions;
	}

	/**
	 * Guarded engine for the "Revoke sudo sessions" bulk action.
	 *
	 * Called only from handle_bulk_revoke_request(), which has already
	 * verified the `bulk-users` nonce (core does NOT nonce-check custom
	 * bulk actions on users.php, so this engine must never be wired to the
	 * un-nonced handle_bulk_actions-users filter). Kept as a separate
	 * sendback-in/sendback-out method so tests can exercise the guard
	 * ladder directly. Guard order mirrors the row-action path: capability first,
	 * then the operator's token-bound sudo session (is_active — a live
	 * expiry with a stolen auth cookie must not revoke anyone), then the
	 * per-operator rate limit. The batch consumes exactly ONE rate slot
	 * regardless of size; per-target teardown then skips the operator's own
	 * row and silently ignores selections without a live session.
	 *
	 * Result args are appended to the sendback core passed in — which
	 * derives from the referer — so the operator's filter context
	 * (sudo_active=1, paged, etc.) survives the redirect.
	 *
	 * @since 4.5.0
	 *
	 * @param string     $sendback Redirect URL core will send the browser to.
	 * @param string     $doaction The bulk action key being executed.
	 * @param array<int> $user_ids Selected user IDs.
	 * @return string Sendback URL with result query args appended.
	 */
	public function handle_bulk_revoke_sessions( string $sendback, string $doaction, array $user_ids ): string {
		if ( self::BULK_REVOKE_SESSIONS_ACTION !== $doaction ) {
			return $sendback;
		}

		$sendback = remove_query_arg(
			array( self::REVOKE_RESULT_QUERY_ARG, self::REVOKE_COUNT_QUERY_ARG, self::REVOKE_SKIPPED_SELF_QUERY_ARG ),
			$sendback
		);

		// 1. Cap gate FIRST — never reveal target session state to an unauthorized caller.
		if ( ! wp_sudo_can( 'revoke_wp_sudo_sessions' ) ) {
			return add_query_arg( self::REVOKE_RESULT_QUERY_ARG, 'no_cap', $sendback );
		}

		// 2. Operator token-bound sudo gate (mirrors the row action): a batch
		// revocation must verify this request holds a valid cookie-bound sudo
		// token (is_active), not merely a live expiry timestamp — otherwise a
		// stolen auth cookie, or a second session without its own sudo, could
		// revoke every selected session.
		$operator_id = get_current_user_id();
		if ( ! Sudo_Session::is_active( $operator_id ) ) {
			return add_query_arg( self::REVOKE_RESULT_QUERY_ARG, 'no-operator-session', $sendback );
		}

		// 3. Per-operator rate limit: exactly ONE slot per batch, consumed up front.
		$rate_key     = self::REVOKE_RATE_PREFIX . $operator_id;
		$revoke_count = (int) get_transient( $rate_key );
		if ( $revoke_count >= self::REVOKE_RATE_LIMIT ) {
			return add_query_arg( self::REVOKE_RESULT_QUERY_ARG, 'rate_limited', $sendback );
		}
		set_transient( $rate_key, $revoke_count + 1, HOUR_IN_SECONDS );

		// 4. Per-target teardown: skip self, skip non-members of this site
		// (forged users[] cannot reach cross-site sessions; a batch of only
		// forged IDs reports bulk_none_live), ignore rows without a live
		// session.
		$revoked      = 0;
		$skipped_self = false;

		foreach ( $user_ids as $user_id ) {
			$outcome = $this->revoke_session_teardown( (int) $user_id, $operator_id, 'users_list_bulk_action' );

			if ( 'success' === $outcome ) {
				++$revoked;
			} elseif ( 'self_target' === $outcome ) {
				$skipped_self = true;
			}
		}

		if ( $revoked > 0 ) {
			$args = array(
				self::REVOKE_RESULT_QUERY_ARG => 'success',
				self::REVOKE_COUNT_QUERY_ARG  => $revoked,
			);
			if ( $skipped_self ) {
				$args[ self::REVOKE_SKIPPED_SELF_QUERY_ARG ] = '1';
			}

			return add_query_arg( $args, $sendback );
		}

		if ( $skipped_self ) {
			return add_query_arg( self::REVOKE_RESULT_QUERY_ARG, 'self_target', $sendback );
		}

		return add_query_arg( self::REVOKE_RESULT_QUERY_ARG, 'bulk_none_live', $sendback );
	}

	/**
	 * Delete the current site's "Sudo Active (N)" badge count cache.
	 *
	 * Hooked (unconditionally, from Plugin::init()) to wp_sudo_activated and
	 * wp_sudo_deactivated so the Users-list badge reflects grants and
	 * revocations immediately. Registration must NOT live inside this
	 * is_admin()-only class instance: sessions are granted on wp_login and
	 * torn down via WP-CLI, both outside is_admin(). On multisite the flush
	 * covers only the current site; other sites' badges decay via the 30 s
	 * TTL, which also ages out naturally-expired sessions that fire no hook.
	 *
	 * @since 4.5.0
	 *
	 * @return void
	 */
	public static function flush_sudo_active_count_cache(): void {
		delete_transient( self::SUDO_ACTIVE_COUNT_TRANSIENT_PREFIX . (int) get_current_blog_id() );
	}

	/**
	 * Render the post-redirect result notice on the Users-list screen.
	 *
	 * Hooked to `admin_notices`. Reads the result code the per-user row
	 * action and bulk handler append to the redirect (REVOKE_RESULT_QUERY_ARG,
	 * plus REVOKE_COUNT_QUERY_ARG and REVOKE_SKIPPED_SELF_QUERY_ARG for a
	 * successful bulk revocation), maps it to a
	 * distinct, actionable message via select_revoke_result_notice(), and
	 * renders it with wp_admin_notice() (WP 6.4+). Only renders on users.php
	 * — the only screen either handler ever redirects back to — and renders
	 * nothing for an absent or unrecognized code (REVK-05: every KNOWN
	 * outcome gets a notice; an unrecognized/forged code fabricates none).
	 *
	 * @since 4.5.0
	 *
	 * @return void
	 */
	public function render_revoke_result_notice(): void {
		if ( 'users.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice selection from a redirect the handler itself constructed.
		$result_code = isset( $_GET[ self::REVOKE_RESULT_QUERY_ARG ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::REVOKE_RESULT_QUERY_ARG ] ) ) : '';
		if ( '' === $result_code ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only count display from a redirect the handler itself constructed.
		$count = isset( $_GET[ self::REVOKE_COUNT_QUERY_ARG ] ) ? absint( wp_unslash( $_GET[ self::REVOKE_COUNT_QUERY_ARG ] ) ) : 0;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag display from a redirect the handler itself constructed.
		$skipped_self = isset( $_GET[ self::REVOKE_SKIPPED_SELF_QUERY_ARG ] );

		$notice = $this->select_revoke_result_notice( $result_code, $count, $skipped_self );
		if ( null === $notice ) {
			return;
		}

		$is_error = 'error' === $notice['type'];

		wp_admin_notice(
			$notice['message'],
			array(
				'type'        => $notice['type'],
				'dismissible' => true,
				'attributes'  => array(
					'role'      => $is_error ? 'alert' : 'status',
					'aria-live' => $is_error ? 'assertive' : 'polite',
				),
			)
		);
	}

	/**
	 * Map a revoke result code to its distinct, actionable notice.
	 *
	 * Every outcome revoke_session_core(), process_revoke_session_row_action(),
	 * and handle_bulk_revoke_sessions() can produce is covered so no blocked
	 * path is a silent no-op (REVK-05). The three REVK-05 blocked-path
	 * messages — no_cap, no-operator-session, target_expired — are each
	 * distinct and actionable; self_target and rate_limited are covered for
	 * completeness even though self_target is defense-in-depth (the row
	 * action never renders on the operator's own row; the bulk handler
	 * reports a self-only selection with it).
	 *
	 * @since 4.5.0
	 *
	 * @param string $result_code  Result code from the redirect query arg.
	 * @param int    $count        Sessions revoked, used for a successful bulk revocation.
	 * @param bool   $skipped_self Whether a bulk revocation skipped the operator's own row.
	 * @return array{type: string, message: string}|null Notice data, or null for an unrecognized code.
	 */
	private function select_revoke_result_notice( string $result_code, int $count, bool $skipped_self = false ): ?array {
		switch ( $result_code ) {
			case 'no_cap':
				return array(
					'type'    => 'error',
					'message' => __( "You don't have permission to revoke sudo sessions.", 'wp-sudo' ),
				);
			case 'no-operator-session':
				return array(
					'type'    => 'error',
					'message' => __( 'Start a sudo session before revoking others.', 'wp-sudo' ),
				);
			case 'self_target':
				return array(
					'type'    => 'error',
					'message' => __( 'You cannot revoke your own session.', 'wp-sudo' ),
				);
			case 'target_expired':
				return array(
					'type'    => 'error',
					'message' => __( 'That user no longer has an active sudo session.', 'wp-sudo' ),
				);
			case 'target_not_member':
				return array(
					'type'    => 'error',
					'message' => __( 'That user is not a member of this site.', 'wp-sudo' ),
				);
			case 'rate_limited':
				return array(
					'type'    => 'error',
					'message' => __( 'Rate limit exceeded. You may revoke at most 10 sessions per hour.', 'wp-sudo' ),
				);
			case 'bulk_none_live':
				return array(
					'type'    => 'warning',
					'message' => __( 'None of the selected users had an active sudo session.', 'wp-sudo' ),
				);
			case 'success':
				if ( $count > 0 ) {
					/* translators: %d: number of sudo sessions revoked. */
					$message = sprintf( _n( '%d active sudo session revoked.', '%d active sudo sessions revoked.', $count, 'wp-sudo' ), $count );
				} else {
					$message = __( 'Sudo session revoked.', 'wp-sudo' );
				}

				if ( $skipped_self ) {
					$message .= ' ' . __( 'Your own session was skipped.', 'wp-sudo' );
				}

				return array(
					'type'    => 'success',
					'message' => $message,
				);
			default:
				return null;
		}
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
		if ( ! wp_sudo_can( 'manage_wp_sudo' ) ) {
			return;
		}

		$this->maybe_record_recovery_mode_usage();

		$is_network = is_multisite();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab routing only, no state change.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'settings';
		if ( ! in_array( $active_tab, self::VALID_TABS, true ) ) {
			$active_tab = 'settings';
		}

		$base_url = $is_network
			? network_admin_url( 'settings.php?page=' . self::PAGE_SLUG )
			: admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php $this->render_recovery_mode_notice(); ?>
			<?php $this->render_policy_preset_notice(); ?>
			<?php $this->render_passed_event_logging_override_notice(); ?>
			<?php if ( $is_network && isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible wp-sudo-notice">
					<p><?php esc_html_e( 'Settings saved.', 'wp-sudo' ); ?></p>
				</div>
			<?php endif; ?>
			<p class="description">
				<?php esc_html_e( 'Sudo adds a reauthentication step before dangerous operations like activating plugins, deleting users, or changing critical settings. Any user who attempts a gated action must re-enter their password — and complete two-factor authentication if enabled — before proceeding.', 'wp-sudo' ); ?>
			</p>

			<h2 class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Sudo settings sections', 'wp-sudo' ); ?>">
				<?php
				$tabs = array(
					'settings' => __( 'Settings', 'wp-sudo' ),
					'actions'  => __( 'Gated Actions', 'wp-sudo' ),
					'tester'   => __( 'Rule Tester', 'wp-sudo' ),
					'access'   => __( 'Access', 'wp-sudo' ),
				);
				foreach ( $tabs as $tab_key => $tab_label ) :
					$class        = ( $active_tab === $tab_key ) ? 'nav-tab nav-tab-active' : 'nav-tab';
					$url          = add_query_arg( array( 'tab' => $tab_key ), $base_url );
					$aria_current = ( $active_tab === $tab_key ) ? ' aria-current="page"' : '';
					?>
					<a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $class ); ?>"<?php echo $aria_current; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute fragment. ?>><?php echo esc_html( $tab_label ); ?></a>
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

				case 'access':
					$this->render_access_tab();
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
			<?php esc_html_e( 'The following actions require reauthentication before execution. The surfaces shown (Admin, AJAX, REST) reflect interactive entry points where WordPress provides APIs. WP-CLI, Cron, and XML-RPC Limited mode use Sudo\'s built-in function-hook coverage for core rules; custom non-interactive workflows need an explicit integration or a stricter surface policy. Application Password requests are covered when a rule defines REST criteria. WPGraphQL is governed separately at the surface level — when the WPGraphQL policy is Limited, all mutations require an active sudo session regardless of which operation is being performed. Developers can add custom rules via the wp_sudo_gated_actions filter.', 'wp-sudo' ); ?>
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
	 * Render the Access tab: current grantees, grant/revoke controls, drift detection.
	 *
	 * @return void
	 */
	public function render_access_tab(): void {
		$holders     = $this->get_sudo_cap_holders();
		$grant_users = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => 'all',
				'number'  => -1,
			)
		);
		$nonce       = wp_create_nonce( 'wp_sudo_access' );
		?>
		<h2><?php esc_html_e( 'Access Control', 'wp-sudo' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Users listed here hold one or more Sudo governance capabilities. Use grant and revoke controls to manage access. All changes require an active sudo session and are logged via audit hooks.', 'wp-sudo' ); ?>
		</p>

		<?php if ( empty( $holders ) ) : ?>
			<p><?php esc_html_e( 'No users currently hold any Sudo governance capabilities.', 'wp-sudo' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<caption class="screen-reader-text"><?php esc_html_e( 'Users with Sudo governance capabilities', 'wp-sudo' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'User', 'wp-sudo' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Capabilities', 'wp-sudo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $holders as $holder ) : ?>
						<tr>
							<td>
								<?php
								$holder_user  = $holder['user'];
								$holder_id    = (int) $holder_user->ID;
								$holder_name  = User_Identity::primary_name( $holder_user );
								$holder_login = isset( $holder_user->user_login ) && is_string( $holder_user->user_login ) ? $holder_user->user_login : '';
								$holder_roles = User_Identity::role_labels( $holder_user );
								// self_admin_url() keeps the link on the correct (site vs network) admin surface.
								$holder_edit = current_user_can( 'edit_user', $holder_id ) ? self_admin_url( 'user-edit.php?user_id=' . $holder_id ) : '';
								// The username renders as a distinct secondary line only when it differs
								// from the primary name; otherwise the login IS the primary, so the edit
								// link attaches to the primary instead of being dropped.
								$holder_has_secondary = ( '' !== $holder_login && $holder_login !== $holder_name );
								?>
								<div class="wp-sudo-access-user">
									<span class="wp-sudo-access-user-avatar" aria-hidden="true">
										<?php
										// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar returns trusted, escaped WP markup.
										echo get_avatar( $holder_id, 32, '', '', array( 'force_display' => true ) );
										?>
									</span>
									<span class="wp-sudo-access-user-info">
										<span class="wp-sudo-access-user-primary">
											<?php if ( ! $holder_has_secondary && '' !== $holder_edit ) : ?>
												<a href="<?php echo esc_url( $holder_edit ); ?>" class="wp-sudo-access-user-name wp-sudo-access-user-login"><?php echo esc_html( $holder_name ); ?></a>
											<?php else : ?>
												<span class="wp-sudo-access-user-name"><?php echo esc_html( $holder_name ); ?></span>
											<?php endif; ?>
											<?php foreach ( $holder_roles as $holder_role ) : ?>
												<span class="wp-sudo-access-user-role"><?php echo esc_html( $holder_role ); ?></span>
											<?php endforeach; ?>
										</span>
										<?php if ( $holder_has_secondary ) : ?>
											<span class="wp-sudo-access-user-secondary">
												<?php if ( '' !== $holder_edit ) : ?>
													<a href="<?php echo esc_url( $holder_edit ); ?>" class="wp-sudo-access-user-login"><?php echo esc_html( $holder_login ); ?></a>
												<?php else : ?>
													<span class="wp-sudo-access-user-login"><?php echo esc_html( $holder_login ); ?></span>
												<?php endif; ?>
											</span>
										<?php endif; ?>
									</span>
								</div>
							</td>
							<td>
								<?php foreach ( $holder['caps'] as $cap ) : ?>
									<div class="wp-sudo-cap-item" title="<?php echo esc_attr( $cap ); ?>">
										<span class="wp-sudo-cap-label"><?php echo esc_html( self::get_cap_label( $cap ) ); ?><span class="screen-reader-text"><?php echo esc_html( $cap ); ?></span></span>
										<button type="button" class="button-link wp-sudo-revoke-cap"
											data-user-id="<?php echo (int) $holder['user']->ID; ?>"
											data-cap="<?php echo esc_attr( $cap ); ?>"
											data-nonce="<?php echo esc_attr( $nonce ); ?>"
											aria-label="<?php echo esc_attr( sprintf( /* translators: %s: governance capability label. */ __( 'Revoke %s capability', 'wp-sudo' ), self::get_cap_label( $cap ) ) ); ?>"
										><?php esc_html_e( 'Revoke', 'wp-sudo' ); ?></button>
									</div>
								<?php endforeach; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Grant Capability', 'wp-sudo' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Grant a Sudo capability to an administrator. Select a user and capability.', 'wp-sudo' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="wp-sudo-grant-user"><?php esc_html_e( 'User', 'wp-sudo' ); ?></label>
				</th>
				<td>
					<input type="search" id="wp-sudo-grant-user-search" class="regular-text" autocomplete="off" aria-controls="wp-sudo-grant-user" placeholder="<?php esc_attr_e( 'Search administrators by name or username', 'wp-sudo' ); ?>" />
					<p class="description"><?php esc_html_e( 'Search filters the administrator list by name or username.', 'wp-sudo' ); ?></p>
					<select id="wp-sudo-grant-user" data-searchable="true">
						<option value="0"><?php esc_html_e( '— Select a user —', 'wp-sudo' ); ?></option>
						<?php foreach ( $grant_users as $grant_user ) : ?>
							<?php $search_text = strtolower( $grant_user->display_name . ' ' . $grant_user->user_login ); ?>
							<option value="<?php echo (int) $grant_user->ID; ?>" data-search-text="<?php echo esc_attr( $search_text ); ?>"><?php echo esc_html( $grant_user->display_name . ' (' . $grant_user->user_login . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="wp-sudo-grant-cap"><?php esc_html_e( 'Capability', 'wp-sudo' ); ?></label>
				</th>
				<td>
					<select id="wp-sudo-grant-cap">
						<?php foreach ( self::GOVERNANCE_CAPS as $cap ) : ?>
							<option value="<?php echo esc_attr( $cap ); ?>" title="<?php echo esc_attr( $cap ); ?>">
								<?php echo esc_html( self::get_cap_label( $cap ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td>
					<button type="button" id="wp-sudo-grant-submit" class="button button-primary"
						data-nonce="<?php echo esc_attr( $nonce ); ?>"
					><?php esc_html_e( 'Grant Capability', 'wp-sudo' ); ?></button>
					<span id="wp-sudo-grant-result" role="status" aria-live="polite"></span>
				</td>
			</tr>
		</table>

		<?php $this->render_drift_detection_panel( $nonce ); ?>
		<?php
	}

	/**
	 * Render the drift detection panel on the Access tab.
	 *
	 * Shows users who hold manage_options (or manage_network_options on multisite)
	 * but do not hold manage_wp_sudo. Surfaced for review: the separation is often
	 * intentional (least privilege), so this is informational, not an error.
	 *
	 * Multisite notes: super admins are excluded — they have effective access
	 * via the wp_sudo_can() short-circuit regardless of stored caps, so listing
	 * them as "cannot access" would be false. They only become candidates at
	 * all when a STORED manage_network_options grant exists (a plugin role or
	 * direct add_cap): WP_User_Query capability queries read the stored
	 * {prefix}capabilities meta and cannot see the site_admins network option,
	 * so on a default network the candidate set is empty and the panel renders
	 * nothing — that is a known scope limit, not a regression (a future phase
	 * could union get_super_admins() into the candidate source). A recovery-mode
	 * viewer who lacks the raw cap still appears in their own list by design:
	 * break-glass access is temporary and they are genuinely drifted.
	 *
	 * @param string $nonce Nonce value for the grant action button.
	 * @return void
	 */
	private function render_drift_detection_panel( string $nonce ): void {
		$capability     = is_multisite() ? 'manage_network_options' : 'manage_options';
		$options_admins = get_users(
			array(
				'capability' => $capability,
				'number'     => -1,
				'fields'     => 'all',
			)
		);

		$drift = array_filter(
			is_array( $options_admins ) ? $options_admins : array(),
			static function ( \WP_User $user ): bool {
				// Super admins mirror wp_sudo_can()'s short-circuit: effective
				// access is always true for them on multisite, so they are never
				// drifted. is_super_admin() reads the site_admins network option,
				// not the viewer's caps, so the list stays viewer-independent.
				// The is_multisite() gate is essential — on single-site
				// is_super_admin() degrades to has_cap( 'delete_users' ), which
				// would wrongly exclude ordinary administrators.
				if ( is_multisite() && is_super_admin( $user->ID ) ) {
					return false;
				}

				// Check the RAW stored capability via allcaps, not has_cap().
				// has_cap() routes through map_meta_cap, where the break-glass
				// recovery mapper (wp_sudo_map_governance_meta_cap) rewrites
				// manage_wp_sudo -> manage_options for the CURRENT user under
				// recovery mode — which would hide a drifted admin from their own
				// drift list while another viewer still sees them. allcaps holds
				// role-derived + add_cap()-granted primitives and is immune to
				// that remap, so drift reflects true stored governance state
				// consistently regardless of who is viewing or recovery mode.
				return empty( $user->allcaps['manage_wp_sudo'] );
			}
		);

		if ( empty( $drift ) ) {
			return;
		}
		?>
		<h3><?php esc_html_e( 'Sudo governance coverage', 'wp-sudo' ); ?></h3>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the capability the panel scanned for (manage_options on single-site, manage_network_options on multisite). */
					__( 'These administrators hold %s but not manage_wp_sudo, so they cannot access Sudo settings. This is often intentional — review the list to confirm each separation is deliberate rather than an accidental gap.', 'wp-sudo' ),
					$capability
				)
			);
			?>
		</p>
		<table class="widefat striped">
			<caption class="screen-reader-text"><?php esc_html_e( 'Users with admin capabilities but without Sudo management access', 'wp-sudo' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'User', 'wp-sudo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Action', 'wp-sudo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $drift as $user ) : ?>
					<tr>
						<td>
							<?php echo esc_html( $user->display_name ); ?>
							<code><?php echo esc_html( $user->user_login ); ?></code>
						</td>
						<td>
							<button type="button" class="button wp-sudo-grant-manage"
								data-user-id="<?php echo (int) $user->ID; ?>"
								data-cap="manage_wp_sudo"
								data-nonce="<?php echo esc_attr( $nonce ); ?>"
							><?php esc_html_e( 'Grant manage_wp_sudo', 'wp-sudo' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Handle AJAX request to grant a governance capability to a user.
	 *
	 * Requires: manage_wp_sudo capability + valid nonce.
	 * Fires: wp_sudo_capability_granted after a successful grant.
	 *
	 * @return void
	 */
	public function handle_grant_cap(): void {
		check_ajax_referer( 'wp_sudo_access', '_nonce' );

		if ( ! wp_sudo_can( 'manage_wp_sudo' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-sudo' ) ), 403 );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer.
		$target_user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; sanitized below.
		$cap = isset( $_POST['cap'] ) ? sanitize_key( $_POST['cap'] ) : '';

		if ( ! in_array( $cap, self::GOVERNANCE_CAPS, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid capability.', 'wp-sudo' ) ), 400 );
			return;
		}

		$target = get_userdata( $target_user_id );
		if ( ! $target instanceof \WP_User ) {
			wp_send_json_error( array( 'message' => __( 'User not found.', 'wp-sudo' ) ), 404 );
			return;
		}

		$target->add_cap( $cap );

		/**
		 * Fires after a Sudo governance capability is granted to a user.
		 *
		 * @since 3.2.0
		 *
		 * @param int    $target_user_id  User who received the capability.
		 * @param string $cap             Capability granted.
		 * @param int    $granter_user_id User who performed the grant.
		 * @param int    $site_id         Site context for the grant.
		 */
		do_action( 'wp_sudo_capability_granted', $target_user_id, $cap, get_current_user_id(), get_current_blog_id() );

		wp_send_json_success( array( 'message' => __( 'Capability granted.', 'wp-sudo' ) ) );
	}

	/**
	 * Handle AJAX request to revoke a governance capability from a user.
	 *
	 * Requires: manage_wp_sudo capability + valid nonce.
	 * Guard: blocks removing manage_wp_sudo from the final holder.
	 * Fires: wp_sudo_capability_revoked after a successful revoke.
	 *
	 * @return void
	 */
	public function handle_revoke_cap(): void {
		check_ajax_referer( 'wp_sudo_access', '_nonce' );

		if ( ! wp_sudo_can( 'manage_wp_sudo' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-sudo' ) ), 403 );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer.
		$target_user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; sanitized below.
		$cap = isset( $_POST['cap'] ) ? sanitize_key( $_POST['cap'] ) : '';

		if ( ! in_array( $cap, self::GOVERNANCE_CAPS, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid capability.', 'wp-sudo' ) ), 400 );
			return;
		}

		// Last manager guard: block removing manage_wp_sudo from the sole holder.
		if ( 'manage_wp_sudo' === $cap && $this->count_manage_wp_sudo_holders() <= 1 ) {
			wp_send_json_error(
				array( 'message' => __( 'Cannot remove the last Sudo manager. Define WP_SUDO_RECOVERY_MODE in wp-config.php to restore access if needed.', 'wp-sudo' ) ),
				409
			);
			return;
		}

		$target = get_userdata( $target_user_id );
		if ( ! $target instanceof \WP_User ) {
			wp_send_json_error( array( 'message' => __( 'User not found.', 'wp-sudo' ) ), 404 );
			return;
		}

		$target->remove_cap( $cap );

		/**
		 * Fires after a Sudo governance capability is revoked from a user.
		 *
		 * @since 3.2.0
		 *
		 * @param int    $target_user_id  User who lost the capability.
		 * @param string $cap             Capability revoked.
		 * @param int    $revoker_user_id User who performed the revocation.
		 * @param int    $site_id         Site context for the revocation.
		 */
		do_action( 'wp_sudo_capability_revoked', $target_user_id, $cap, get_current_user_id(), get_current_blog_id() );

		wp_send_json_success( array( 'message' => __( 'Capability revoked.', 'wp-sudo' ) ) );
	}

	/**
	 * Factored revocation core shared by per-user revoke surfaces.
	 *
	 * Enforces, in order: capability gate, self-target guard, target-liveness
	 * precondition, per-revoker rate limit, then deactivates the target's
	 * session and fires the audit hook. The cap check runs FIRST so a capless
	 * caller never learns whether the target session is live. The site-wide
	 * enumerator (Sudo_Session::revoke_all_active_sessions()) does NOT route
	 * through this core — it stays gate-free so WP-CLI can call it un-gated;
	 * the Users-list bulk action carries its own cap/rate wrapper in
	 * handle_bulk_revoke_sessions().
	 *
	 * @since 4.5.0
	 *
	 * @param int    $target_user_id  User whose session is being revoked.
	 * @param int    $revoker_user_id Operator performing the revocation.
	 * @param string $reason          Reason/surface tag passed through to the audit hook.
	 * @return array{outcome: string} Result with outcome code: no_cap|self_target|target_not_member|target_expired|rate_limited|success.
	 */
	private function revoke_session_core( int $target_user_id, int $revoker_user_id, string $reason ): array {
		// 1. Cap gate FIRST — never reveal target session state to an unauthorized caller.
		if ( ! wp_sudo_can( 'revoke_wp_sudo_sessions' ) ) {
			return array( 'outcome' => 'no_cap' );
		}

		// 2. Self-target guard — the core refuses to revoke the operator's own session.
		if ( $target_user_id === $revoker_user_id ) {
			return array( 'outcome' => 'self_target' );
		}

		// 2b. Site-membership guard, BEFORE liveness — a forged target ID
		// must not let an operator on this site enumerate (or touch) the
		// network-global sudo state of users belonging to other sites.
		if ( ! is_user_member_of_blog( $target_user_id ) ) {
			return array( 'outcome' => 'target_not_member' );
		}

		// 3. Target-liveness precondition — browser-independent, mirrors row-action visibility.
		if ( ! Sudo_Session::is_session_live( $target_user_id ) ) {
			return array( 'outcome' => 'target_expired' );
		}

		// 4. Per-revoker rate limit: max REVOKE_RATE_LIMIT revocations per hour.
		$rate_key     = self::REVOKE_RATE_PREFIX . $revoker_user_id;
		$revoke_count = (int) get_transient( $rate_key );
		if ( $revoke_count >= self::REVOKE_RATE_LIMIT ) {
			return array( 'outcome' => 'rate_limited' );
		}
		set_transient( $rate_key, $revoke_count + 1, HOUR_IN_SECONDS );

		// 5. Teardown (self/liveness re-checked inside; both passed above).
		return array( 'outcome' => $this->revoke_session_teardown( $target_user_id, $revoker_user_id, $reason ) );
	}

	/**
	 * Un-rated per-target revocation teardown shared by the row action and
	 * the bulk action.
	 *
	 * Applies the self-target guard and the target-liveness precondition,
	 * then deactivates the session and fires the audit hook. Deliberately
	 * carries NO capability check and NO rate accounting: callers own those
	 * — revoke_session_core() consumes one slot per row-action call, while
	 * handle_bulk_revoke_sessions() consumes one slot for a whole batch
	 * before iterating this teardown per selected user.
	 *
	 * @since 4.5.0
	 *
	 * @param int    $target_user_id  User whose session is being revoked.
	 * @param int    $revoker_user_id Operator performing the revocation.
	 * @param string $reason          Reason/surface tag passed through to the audit hook.
	 * @return string Outcome code: self_target|target_not_member|target_expired|success.
	 */
	private function revoke_session_teardown( int $target_user_id, int $revoker_user_id, string $reason ): string {
		if ( $target_user_id === $revoker_user_id ) {
			return 'self_target';
		}

		// Membership before liveness (mirrors revoke_session_core): forged
		// IDs from other network sites are skipped without touching — or
		// revealing — their global session state.
		if ( ! is_user_member_of_blog( $target_user_id ) ) {
			return 'target_not_member';
		}

		if ( ! Sudo_Session::is_session_live( $target_user_id ) ) {
			return 'target_expired';
		}

		Sudo_Session::deactivate( $target_user_id );

		/**
		 * Fires when an operator force-revokes another user's sudo session.
		 *
		 * @since 3.2.0
		 *
		 * @param int    $target_user_id  User whose session was revoked.
		 * @param int    $revoker_user_id Operator who performed the revocation.
		 * @param string $reason          Reason/surface tag for the revocation.
		 * @param int    $site_id         Site context for the revocation.
		 */
		do_action( 'wp_sudo_session_revoked', $target_user_id, $revoker_user_id, $reason, get_current_blog_id() );

		return 'success';
	}

	/**
	 * Get all users holding any Sudo governance capability, grouped by user ID.
	 *
	 * @return array<int, array{user: \WP_User, caps: list<string>}>
	 */
	private function get_sudo_cap_holders(): array {
		$holders = array();

		foreach ( self::GOVERNANCE_CAPS as $cap ) {
			$users = get_users(
				array(
					'capability' => $cap,
					'number'     => -1,
					'fields'     => 'all',
				)
			);

			if ( ! is_array( $users ) ) {
				continue;
			}

			foreach ( $users as $user ) {
				if ( ! isset( $holders[ $user->ID ] ) ) {
					$holders[ $user->ID ] = array(
						'user' => $user,
						'caps' => array(),
					);
				}

				$holders[ $user->ID ]['caps'][] = $cap;
			}
		}

		return $holders;
	}

	/**
	 * Count users who currently hold the manage_wp_sudo capability.
	 *
	 * Used by the last-manager guard to block removing the final holder.
	 *
	 * @return int
	 */
	private function count_manage_wp_sudo_holders(): int {
		$users = get_users(
			array(
				'capability' => 'manage_wp_sudo',
				'number'     => -1,
				'fields'     => 'ID',
			)
		);

		return is_array( $users ) ? count( $users ) : 0;
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
			<?php esc_html_e( 'See how Sudo would evaluate a representative request without executing it. This diagnostic tool is for admin, AJAX, and REST request shapes only.', 'wp-sudo' ); ?>
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
					<tr>
						<th scope="row"><label for="wp-sudo-request-tester-rest-params"><?php esc_html_e( 'REST Params', 'wp-sudo' ); ?></label></th>
						<td>
							<textarea id="wp-sudo-request-tester-rest-params" name="wp_sudo_request_tester[rest_params]" class="large-text code" rows="3" placeholder='<?php echo esc_attr( '{"connectors_ai_openai_api_key": "sk-test"}' ); ?>'><?php echo esc_textarea( (string) $form_values['rest_params'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Optional JSON body for REST requests. Used by callback-based rules like connectors.update_credentials.', 'wp-sudo' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
			<?php submit_button( __( 'Evaluate Request', 'wp-sudo' ), 'secondary', 'wp_sudo_request_tester_submit', false ); ?>
		</form>
		<?php if ( is_array( $result ) ) : ?>
			<div id="wp-sudo-tester-result" class="notice notice-info inline" style="margin-top: 1em;" role="status" aria-live="polite" aria-atomic="true" tabindex="-1">
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
										<code>wp-content/plugins/&lt;current-plugin-directory&gt;/mu-plugin/wp-sudo-gate.php</code>
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
										<code>wp-content/plugins/&lt;current-plugin-directory&gt;/mu-plugin/wp-sudo-gate.php</code>
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

		if ( ! wp_sudo_can( 'manage_wp_sudo' ) ) {
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

		$source = WP_SUDO_PLUGIN_DIR . 'mu-plugin/wp-sudo-gate.php';
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

		$contents = self::personalize_mu_shim_contents( $contents, WP_SUDO_PLUGIN_DIR . 'mu-plugin/wp-sudo-loader.php' );

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

		if ( ! wp_sudo_can( 'manage_wp_sudo' ) ) {
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
	 * Personalize the MU shim template with the current plugin loader path.
	 *
	 * The copied shim embeds the resolved loader path so renamed plugin
	 * directories keep early gate coverage. The shim still contains fallback
	 * discovery for manual copies and later directory moves.
	 *
	 * @param string $contents    Shim template contents.
	 * @param string $loader_path Absolute loader path.
	 * @return string Personalized shim contents.
	 */
	private static function personalize_mu_shim_contents( string $contents, string $loader_path ): string {
		$escaped_loader_path = str_replace(
			array( '\\', "'" ),
			array( '\\\\', "\\'" ),
			$loader_path
		);

		return str_replace( '__WP_SUDO_LOADER_PATH__', $escaped_loader_path, $contents );
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
		echo '<p>' . esc_html__( 'Control how non-interactive entry points handle gated operations. Disabled shuts off the entire surface. Limited (default) blocks only gated actions and logs them. Unrestricted lets everything through with no blocking checks; allowed gated actions are still logged for audit visibility. Browser-based requests (admin UI, AJAX, REST with cookie auth) always get the interactive reauthentication challenge regardless of these settings.', 'wp-sudo' ) . '</p>';
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

		echo '<select id="' . esc_attr( self::SETTING_POLICY_PRESET_SELECTION ) . '" name="' . esc_attr( $select_name ) . '" aria-describedby="wp-sudo-preset-description">';

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
			echo '<p class="description" id="wp-sudo-preset-description" aria-live="polite" aria-atomic="true">' . esc_html( $presets[ $current_preset ]['description'] ) . '</p>';
		} else {
			echo '<p class="description" id="wp-sudo-preset-description" aria-live="polite" aria-atomic="true">' . esc_html__( 'Current settings do not match any preset. Selecting a preset will overwrite the entry-point policy fields below.', 'wp-sudo' ) . '</p>';
		}
	}

	/**
	 * Render a policy select field (Disabled / Limited / Unrestricted).
	 *
	 * @param array<string, string> $args Field arguments (key, description).
	 * @return void
	 */
	public function render_field_policy( array $args ): void {
		$key            = $args['key'] ?? '';
		$value          = self::get( $key, Gate::POLICY_LIMITED );
		$description_id = '';
		if ( ! empty( $args['description'] ) ) {
			$description_id = $key . '-description';
		}

		printf(
			'<select id="%1$s" name="%2$s[%1$s]"%3$s>',
			esc_attr( $key ),
			esc_attr( self::OPTION_KEY ),
			'' !== $description_id ? ' aria-describedby="' . esc_attr( $description_id ) . '"' : ''
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
			echo '<p class="description" id="' . esc_attr( $description_id ) . '">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Get the currently stored settings array.
	 *
	 * @return array<string, mixed>
	 */
	private function get_stored_settings(): array {
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
			'rest_params'      => '',
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
			'rest_params'      => sanitize_textarea_field( (string) ( $raw['rest_params'] ?? '' ) ),
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

		// Decode rest_params JSON; fall back to empty array on invalid JSON.
		$rest_params = array();
		if ( '' !== $values['rest_params'] ) {
			$decoded = json_decode( $values['rest_params'], true );
			if ( is_array( $decoded ) ) {
				$rest_params = $decoded;
			}
		}

		return $this->get_diagnostic_gate()->evaluate_diagnostic_request(
			array(
				'surface'          => $values['surface'],
				'method'           => $values['method'],
				'url'              => $values['url'],
				'is_authenticated' => $values['is_authenticated'],
				'has_active_sudo'  => $values['has_active_sudo'],
				'is_network_admin' => $values['is_network_admin'],
				'rest_auth_mode'   => $values['rest_auth_mode'],
				'rest_params'      => $rest_params,
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
	 * Render an explicit warning when Passed-event logging is code-disabled.
	 *
	 * @return void
	 */
	private function render_passed_event_logging_override_notice(): void {
		if ( self::is_passed_event_logging_enabled() ) {
			return;
		}

		$message = __( 'Passed event logging is disabled by code override (constant/filter). Actions performed during active sudo sessions will not appear in dashboard event history.', 'wp-sudo' );

		echo '<div class="notice notice-warning wp-sudo-notice"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Render a permanent, non-dismissible notice while recovery mode is active.
	 *
	 * Break-glass recovery (WP_SUDO_RECOVERY_MODE) is uncontained by design: it
	 * re-opens Sudo governance to every administrator for as long as the
	 * constant is defined. This notice makes that state impossible to miss on
	 * the Sudo settings screen so operators remove the constant promptly.
	 *
	 * @since 3.4.0
	 * @return void
	 */
	private function render_recovery_mode_notice(): void {
		if ( ! wp_sudo_is_recovery_mode() ) {
			return;
		}

		$message = __( 'Sudo break-glass recovery mode is active (WP_SUDO_RECOVERY_MODE is defined in wp-config.php). While it is set, any user who holds the manage_options capability — manage_network_options on multisite — regains full Sudo governance access, regardless of role. Remove the constant as soon as normal access is restored — leaving it enabled weakens the governance model.', 'wp-sudo' );

		echo '<div class="notice notice-warning wp-sudo-notice"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Delete the inert wp_sudo_governance_mode option if found on this admin
	 * request (clear-on-detection, BRK-03 defense-in-depth).
	 *
	 * Runs on admin_init at priority 1 (before admin_notices). When the option
	 * is present in either the per-site or network-wide store, it is deleted from
	 * both, the static $compat_option_cleared flag is set, and the audit action
	 * fires. The notice method then reads the flag on the same request.
	 *
	 * Authority-gated: only an authorized manage_wp_sudo user triggers cleanup.
	 * Returns early when the option is absent (no writes, no flag, no action).
	 *
	 * Note: admin_init does NOT fire under WP-CLI or cron — this cleanup is
	 * admin-HTTP-only by design. The upgrade_4_0_0() migration routine handles
	 * the 3.x → 4.0.0 boundary for non-admin contexts.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	public function cleanup_inert_governance_mode_option(): void {
		if ( ! wp_sudo_can( 'manage_wp_sudo' ) ) {
			return;
		}

		// Check both stores. On single-site only the per-site store is relevant;
		// on multisite the value may live in either (historical inconsistency).
		$found = ( false !== get_option( 'wp_sudo_governance_mode' ) );
		if ( is_multisite() ) {
			$found = $found || ( false !== get_site_option( 'wp_sudo_governance_mode' ) );
		}

		if ( ! $found ) {
			return;
		}

		delete_option( 'wp_sudo_governance_mode' );
		if ( is_multisite() ) {
			delete_site_option( 'wp_sudo_governance_mode' );
		}

		self::$compat_option_cleared = true;

		/**
		 * Fires when cleanup_inert_governance_mode_option() detects and removes
		 * the stale wp_sudo_governance_mode option left over from before 4.0.0.
		 *
		 * This is the semantically-correct developer and audit signal — the option
		 * name was never a callable, so _doing_it_wrong() was inappropriate here.
		 *
		 * @since 4.0.0
		 */
		do_action( 'wp_sudo_inert_governance_mode_detected' );
	}

	/**
	 * Render a one-time dismissible "fixed" confirmation notice after the inert
	 * wp_sudo_governance_mode option is cleaned up on this admin request (BRK-03).
	 *
	 * The notice is gated on the static $compat_option_cleared flag set by
	 * cleanup_inert_governance_mode_option() on admin_init (priority 1). Because
	 * no redirect intervenes between admin_init and admin_notices, a transient is
	 * not needed — the flag lives only for the duration of this request.
	 *
	 * The notice is dismissible and uses notice-success because the cleanup
	 * already happened — this is a confirmation, not a warning. It does NOT call
	 * _doing_it_wrong(); the developer/audit signal moved to the audit action hook
	 * in cleanup_inert_governance_mode_option().
	 *
	 * Hooked on admin_notices and network_admin_notices. Only one fires per
	 * request. Returns immediately for unauthorized users and when the flag is
	 * not set.
	 *
	 * @since 4.0.0
	 * @return void
	 */
	public function render_compatibility_mode_notice(): void {
		if ( ! wp_sudo_can( 'manage_wp_sudo' ) ) {
			return;
		}

		if ( ! self::$compat_option_cleared ) {
			return;
		}

		$message = __( 'Sudo removed a leftover permission-mode setting left over from before version 4.0.0. Sudo now always enforces strict, role-based permission checks. No action is needed.', 'wp-sudo' );

		printf(
			'<div class="notice notice-success is-dismissible wp-sudo-notice" role="alert"><p>%s</p></div>',
			wp_kses_post( $message )
		);
	}

	/**
	 * Fire the recovery-mode audit hook when the Sudo surface is accessed
	 * while break-glass recovery mode is active.
	 *
	 * Fired on every such page load (not throttled here) so external audit /
	 * SIEM listeners observe the complete usage pattern. The bundled
	 * Event_Recorder samples the internal record to one row per user per hour
	 * to avoid flooding the events table.
	 *
	 * @since 3.4.0
	 * @return void
	 */
	private function maybe_record_recovery_mode_usage(): void {
		if ( ! wp_sudo_is_recovery_mode() ) {
			return;
		}

		/**
		 * Fires when the Sudo admin surface is accessed under break-glass
		 * recovery mode.
		 *
		 * @since 3.4.0
		 *
		 * @param int $user_id The user accessing the Sudo surface under recovery mode.
		 */
		do_action( 'wp_sudo_recovery_mode_active', get_current_user_id() );
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

		if ( ! wp_sudo_can( 'manage_wp_sudo' ) ) {
			return;
		}

		// Determine which user's profile is being viewed.
		$profile_user_id = $this->get_profile_user_id();
		if ( ! $profile_user_id ) {
			return;
		}

		wp_enqueue_script(
			'wp-sudo-app-passwords',
			WP_SUDO_PLUGIN_URL . 'admin/js/wp-sudo-app-passwords.js',
			array( 'wp-a11y' ),
			WP_SUDO_VERSION,
			true
		);

		$policies = self::get( 'app_password_policies', array() );

		wp_localize_script(
			'wp-sudo-app-passwords',
			'wpSudoAppPasswords',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wp_sudo_app_password_policy' ),
				'userId'   => $profile_user_id,
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

		if ( ! wp_sudo_can( 'manage_wp_sudo' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'wp-sudo' ) ), 403 );
			return;
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
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer; value is cast with absint.
		$target_user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : $user_id;
		if ( $target_user_id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid user.', 'wp-sudo' ) ), 400 );
			return;
		}

		if ( $target_user_id !== $user_id && ! current_user_can( 'edit_user', $target_user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'wp-sudo' ) ), 403 );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above via check_ajax_referer; sanitized in helper.
		$uuid = isset( $_POST['uuid'] ) && is_string( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above via check_ajax_referer; sanitized in helper.
		$policy = isset( $_POST['policy'] ) && is_string( $_POST['policy'] ) ? sanitize_text_field( wp_unslash( $_POST['policy'] ) ) : '';

		if ( empty( $uuid ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid application password UUID.', 'wp-sudo' ) ) );
			return;
		}

		// Validate UUID v4 format before using it as an array key.
		if ( ! wp_is_uuid( $uuid, 4 ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid application password UUID format.', 'wp-sudo' ) ) );
			return;
		}

		// Validate that the UUID belongs to an existing application password for
		// the profile user — prevents option-table bloat from orphaned policy entries.
		$password_item = \WP_Application_Passwords::get_user_application_password( $target_user_id, $uuid );
		if ( null === $password_item ) {
			wp_send_json_error( array( 'message' => __( 'Application password not found.', 'wp-sudo' ) ) );
			return;
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
	 * Remove the per-App-Password policy override for a deleted application password.
	 *
	 * Hooked on `wp_delete_application_password` so that option-table entries
	 * for deleted passwords are cleaned up immediately rather than accumulating
	 * as orphaned data.
	 *
	 * @since 3.1.5
	 *
	 * @param int                  $user_id The user ID whose password was deleted.
	 * @param array<string, mixed> $item    The deleted password item (contains 'uuid' key).
	 * @return void
	 */
	public function handle_app_password_deleted( int $user_id, array $item ): void {
		if ( empty( $item['uuid'] ) || ! is_string( $item['uuid'] ) ) {
			return;
		}

		$uuid     = (string) $item['uuid'];
		$settings = is_multisite()
			? get_site_option( self::OPTION_KEY, self::defaults() )
			: get_option( self::OPTION_KEY, self::defaults() );

		if ( ! is_array( $settings ) ) {
			return;
		}

		if ( empty( $settings['app_password_policies'][ $uuid ] ) ) {
			return;
		}

		unset( $settings['app_password_policies'][ $uuid ] );

		if ( is_multisite() ) {
			update_site_option( self::OPTION_KEY, $settings );
		} else {
			update_option( self::OPTION_KEY, $settings );
		}

		self::reset_cache();
	}
}
