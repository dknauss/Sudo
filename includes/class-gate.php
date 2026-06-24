<?php
/**
 * Multi-surface interceptor for gated admin actions.
 *
 * Detects which surface a request enters through (admin UI, AJAX, REST,
 * CLI, Cron, XML-RPC) and either challenges, soft-blocks, or hard-blocks
 * depending on the surface and policy settings.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Gate
 *
 * The heart of WP Sudo v2. Role-agnostic: any logged-in user attempting
 * a gated action is intercepted, regardless of role. It reauthenticates
 * account-holder presence; WordPress and target handlers remain responsible
 * for normal capability checks.
 *
 * @since 2.0.0
 */
class Gate {

	/**
	 * Policy value: shut off the entire surface/protocol.
	 *
	 * No gating checks, no logging, nothing runs through it.
	 *
	 * @since 2.2.0
	 * @var string
	 */
	public const POLICY_DISABLED = 'disabled';

	/**
	 * Policy value: gated actions are blocked and logged;
	 * non-gated operations work normally.
	 *
	 * @since 2.2.0
	 * @var string
	 */
	public const POLICY_LIMITED = 'limited';

	/**
	 * Policy value: everything passes through as if WP Sudo
	 * is not installed. Gated operations may still emit allowed-action
	 * audit hooks when a surface-specific hook sees them.
	 *
	 * @since 2.2.0
	 * @var string
	 */
	public const POLICY_UNRESTRICTED = 'unrestricted';

	/**
	 * Settings key for WP-CLI policy.
	 *
	 * @var string
	 */
	public const SETTING_CLI_POLICY = 'cli_policy';

	/**
	 * Settings key for Cron policy.
	 *
	 * @var string
	 */
	public const SETTING_CRON_POLICY = 'cron_policy';

	/**
	 * Settings key for XML-RPC policy.
	 *
	 * @var string
	 */
	public const SETTING_XMLRPC_POLICY = 'xmlrpc_policy';

	/**
	 * Settings key for REST App Password policy.
	 *
	 * @var string
	 */
	public const SETTING_REST_APP_PASS_POLICY = 'rest_app_password_policy';

	/**
	 * Settings key for WPGraphQL policy.
	 *
	 * @since 2.5.0
	 * @var string
	 */
	public const SETTING_WPGRAPHQL_POLICY = 'wpgraphql_policy';

	/**
	 * Transient prefix for blocked-action fallback notices.
	 *
	 * When the Gate blocks an AJAX or REST request with `sudo_required`,
	 * it sets a short-lived transient so the next page load can show a
	 * WordPress admin notice — in case the user needs to activate a
	 * sudo session and retry the action.
	 *
	 * @var string
	 */
	public const BLOCKED_TRANSIENT_PREFIX = '_wp_sudo_blocked_';

	/**
	 * The sudo session instance.
	 *
	 * @var Sudo_Session
	 */
	private Sudo_Session $session;

	/**
	 * The request stash instance.
	 *
	 * @var Request_Stash
	 */
	private Request_Stash $stash;

	/**
	 * Constructor.
	 *
	 * @param Sudo_Session  $session Session manager.
	 * @param Request_Stash $stash   Request stash.
	 */
	public function __construct( Sudo_Session $session, Request_Stash $stash ) {
		$this->session = $session;
		$this->stash   = $stash;
	}

	/**
	 * Register all interception hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Admin UI + AJAX interception (admin-ajax.php also fires admin_init).
		add_action( 'admin_init', array( $this, 'intercept' ), 1, 0 );

		// Defense-in-depth backstop: arm session-aware effect-level guards on the
		// interactive admin surface so a gated-equivalent destructive action
		// invoked through a non-enumerated handler (e.g. a third-party
		// admin-post.php route) cannot run while no sudo window is open.
		// Enumerated flows are still handled by intercept() at priority 1
		// (challenge + stash/replay); this only catches what request-pattern
		// matching cannot see.
		add_action( 'admin_init', array( $this, 'register_interactive_backstop' ), 0, 0 );

		// REST API interception — fires after route matching, before callbacks.
		add_filter( 'rest_request_before_callbacks', array( $this, 'intercept_rest' ), 10, 3 );

		// REST effect-level backstop — same defense-in-depth as the admin
		// backstop, for destructive effects invoked by a non-enumerated/custom
		// REST route that intercept_rest() cannot match. Armed only during REST
		// requests (rest_api_init fires before route dispatch).
		add_action( 'rest_api_init', array( $this, 'register_rest_backstop' ), 0, 0 );

		// Role-aware admin-escalation guard (opt-in via wp_sudo_guard_escalation,
		// default OFF). Effect-level capabilities-meta guard that blocks a NEW
		// administrator grant when no sudo session is active, across surfaces
		// (CLI/Cron/XML-RPC defer to the non-interactive policy layer). See
		// docs/admin-escalation-guard-analysis.md.
		$this->arm_escalation_guard();

		// WPGraphQL interception — hooks into WPGraphQL's own lifecycle.
		// Fires after auth validation, before body reading, regardless of endpoint name.
		/**
		 * Suppress optional WPGraphQL hook symbol lookup.
		 *
		 * @psalm-suppress HookNotFound Optional hook provided by WPGraphQL plugin.
		 */
		add_action( 'graphql_process_http_request', array( $this, 'gate_wpgraphql' ), 10, 0 );

		// Fallback admin notice when a gated AJAX/REST request was blocked.
		add_action( 'admin_notices', array( $this, 'render_blocked_notice' ), 10, 0 );
		add_action( 'network_admin_notices', array( $this, 'render_blocked_notice' ), 10, 0 );

		// Persistent gate notice on gated pages when no sudo session is active.
		add_action( 'admin_notices', array( $this, 'render_gate_notice' ), 10, 0 );
		add_action( 'network_admin_notices', array( $this, 'render_gate_notice' ), 10, 0 );

		// PHP action link filters for server-rendered buttons (plugins list table).
		add_filter( 'plugin_action_links', array( $this, 'filter_plugin_action_links' ), 50, 2 );
		add_filter( 'theme_action_links', array( $this, 'filter_theme_action_links' ), 50, 2 );
	}

	/**
	 * Register early hooks for non-interactive surfaces.
	 *
	 * Called at `plugins_loaded` (or `muplugins_loaded` if the mu-plugin
	 * is installed) to block gated operations on CLI, Cron, and XML-RPC
	 * before any other plugin can process them.
	 *
	 * @return void
	 */
	public function register_early(): void {
		add_action( 'init', array( $this, 'gate_non_interactive' ), 0, 0 );
	}

	/**
	 * Arm the interactive effect-level backstop on the admin surface.
	 *
	 * Hooks the unambiguous destructive WordPress effect actions so that, when
	 * no sudo window is active, a gated-equivalent action invoked through a
	 * handler that request-pattern matching does not enumerate (third-party
	 * admin-post.php routes, custom dispatchers) is blocked at the effect
	 * boundary instead of running silently.
	 *
	 * Scope is deliberately limited to file/record-destroying actions whose hook
	 * fires only inside the named effect function (wp_delete_user(),
	 * delete_plugins(), etc.). Option-level filters (pre_update_option_*) are
	 * intentionally excluded: WordPress core rewrites those options incidentally
	 * during ordinary admin loads (e.g. validate_active_plugins()), so guarding
	 * them here would hard-block legitimate non-gated workflows. user.create and
	 * user.promote are likewise excluded — their hooks fire on benign,
	 * high-frequency paths (every wp_insert_user / role assignment).
	 *
	 * Enumerated admin flows never reach these guards while unauthenticated for
	 * sudo: intercept() (admin_init priority 1) redirects them to the challenge
	 * before the effect fires. When a sudo window is active the guard allows the
	 * effect silently; the enumerated path owns the wp_sudo_action_passed audit
	 * signal, so the backstop does not duplicate it.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function register_interactive_backstop(): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		$guard = function ( string $rule_id, string $label ) use ( $user_id ): void {
			// Sudo window open (or within grace) — allow, silently.
			if ( Sudo_Session::is_active( $user_id ) || Sudo_Session::is_within_grace( $user_id ) ) {
				return;
			}

			/** This action is documented in includes/class-gate.php */
			do_action( 'wp_sudo_action_blocked', $user_id, $rule_id, 'admin' );

			$this->die_sudo_required( $label );
		};

		$this->arm_effect_guards( $guard );
	}

	/**
	 * Arm the REST effect-level backstop.
	 *
	 * Mirrors the interactive backstop on the REST surface so a destructive
	 * effect invoked by a non-enumerated/custom REST route — one that
	 * intercept_rest() does not match — cannot run while no sudo window is open.
	 * Enumerated REST routes are already handled by intercept_rest() at
	 * rest_request_before_callbacks: with no sudo it returns a WP_Error before
	 * the route callback (the effect never fires); with a sudo window active the
	 * callback runs and this guard allows the effect silently.
	 *
	 * Unlike the admin guard, the REST guard honours the Application Password
	 * policy via the shared is_rest_cookie_auth() classification: an Unrestricted
	 * headless client is allowed (audit only), while a cookie-authenticated
	 * browser request or a Limited/Disabled app-password request is blocked.
	 * Because an action hook cannot return a WP_Error, the block is delivered via
	 * wp_die(), which WordPress renders as a JSON 403 during a REST request. The
	 * two paths never fire for the same request (enumerated -> intercept_rest;
	 * non-enumerated -> backstop), so the differing block shapes never collide.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function register_rest_backstop(): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		$guard = function ( string $rule_id, string $label ) use ( $user_id ): void {
			// Sudo window open (or within grace) — allow, silently.
			if ( Sudo_Session::is_active( $user_id ) || Sudo_Session::is_within_grace( $user_id ) ) {
				return;
			}

			if ( $this->is_rest_cookie_auth() ) {
				// Cookie-authenticated browser request — hard block. An effect
				// hook cannot stash and replay the request the way intercept_rest()
				// does for enumerated routes, so surface a blocked (not gated)
				// audit on 'rest': no challenge will follow.
				/** This action is documented in includes/class-gate.php */
				do_action( 'wp_sudo_action_blocked', $user_id, $rule_id, 'rest' );
				$this->die_sudo_required( $label );
			} else {
				// Headless (app-password / bearer) — honour the App Password policy.
				$policy = $this->get_app_password_policy();

				// Unrestricted: pass through, audit only.
				if ( self::POLICY_UNRESTRICTED === $policy ) {
					/** This action is documented in includes/class-gate.php */
					do_action( 'wp_sudo_action_allowed', $user_id, $rule_id, 'rest_app_password' );
					return;
				}

				// Limited: block with logging. Disabled: block without logging.
				if ( self::POLICY_LIMITED === $policy ) {
					/** This action is documented in includes/class-gate.php */
					do_action( 'wp_sudo_action_blocked', $user_id, $rule_id, 'rest_app_password' );
				}

				$this->die_sudo_required( $label );
			}
		};

		$this->arm_effect_guards( $guard );
	}

	/**
	 * Register the destructive-effect guards shared by the backstops.
	 *
	 * The interactive (admin) and REST backstops cover exactly the same set of
	 * file/record-destroying effects — actions whose hook fires only inside the
	 * named effect function (wp_delete_user(), delete_plugins(), etc.) plus the
	 * plugin/theme install/update operations classified from upgrader_pre_install.
	 * Keeping the hook set in one place guarantees the two surfaces stay in sync;
	 * each passes its own surface-specific guard closure.
	 *
	 * @since 4.1.0
	 *
	 * @param callable $guard fn(string $rule_id, string $label): void — the
	 *                        surface guard to invoke when an effect fires.
	 * @return void
	 */
	private function arm_effect_guards( callable $guard ): void {
		add_action(
			'activate_plugin',
			function () use ( $guard ) {
				$guard( 'plugin.activate', __( 'Activate plugin', 'wp-sudo' ) );
			},
			0
		);

		add_action(
			'delete_plugin',
			function () use ( $guard ) {
				$guard( 'plugin.delete', __( 'Delete plugin', 'wp-sudo' ) );
			},
			0
		);

		add_action(
			'delete_theme',
			function () use ( $guard ) {
				$guard( 'theme.delete', __( 'Delete theme', 'wp-sudo' ) );
			},
			0
		);

		add_action(
			'delete_user',
			function () use ( $guard ) {
				$guard( 'user.delete', __( 'Delete user', 'wp-sudo' ) );
			},
			0
		);

		add_action(
			'export_wp',
			function () use ( $guard ) {
				$guard( 'tools.export', __( 'Export site data', 'wp-sudo' ) );
			},
			0
		);

		add_filter(
			'upgrader_pre_install',
			function ( $response, $hook_extra = array() ) use ( $guard ) {
				if ( is_wp_error( $response ) ) {
					return $response;
				}
				// upgrader_pre_install is shared by the plugin, theme, core, and
				// language-pack updaters. Only guard the covered plugin/theme
				// install/update operations, with their precise rule id/label;
				// core updates (enumerated via update-core.php) and language packs
				// (not a gated rule) pass through rather than being mislabelled or
				// over-blocked.
				$rule = $this->classify_upgrader_effect( is_array( $hook_extra ) ? $hook_extra : array() );
				if ( null !== $rule ) {
					$guard( $rule[0], $rule[1] );
				}
				return $response;
			},
			0,
			2
		);
	}

	/**
	 * Terminate a request that requires sudo with a 403.
	 *
	 * Shared by the interactive (admin) and REST effect-level backstops, which
	 * fire inside effect hooks and so cannot return a WP_Error. wp_die() renders
	 * an HTML page on the admin surface and a JSON error during a REST request —
	 * WordPress selects the handler from the request context.
	 *
	 * @since 4.1.0
	 *
	 * @param string $label Human-readable action label.
	 * @return void
	 */
	private function die_sudo_required( string $label ): void {
		wp_die(
			esc_html(
				sprintf(
					/* translators: %s: action label */
					__( 'This operation (%s) requires sudo. Activate a sudo session and try again.', 'wp-sudo' ),
					$label
				)
			),
			'',
			array( 'response' => 403 )
		);
	}

	/**
	 * Whether the current REST request is cookie-authenticated (a browser request).
	 *
	 * Returns true when the request carries a valid wp_rest nonce AND is not
	 * authenticated via an Application Password; false otherwise (App Password,
	 * bearer, or any headless credential). A request presenting BOTH a valid nonce
	 * AND an App Password is treated as headless, so a headless client cannot
	 * bypass the App Password policy by also sending a nonce (C2). WordPress core
	 * accepts the REST nonce via the X-WP-Nonce header or the _wpnonce parameter
	 * (see rest_cookie_check_errors() in wp-includes/rest-api.php).
	 *
	 * Shared by intercept_rest() (enumerated routes) and register_rest_backstop()
	 * (non-enumerated routes) so the two paths cannot drift. The optional request
	 * argument lets intercept_rest() read the nonce from the parsed
	 * WP_REST_Request header; the backstop runs inside an effect hook without the
	 * request object and falls back to the X-WP-Nonce header and _wpnonce
	 * parameter from the superglobals.
	 *
	 * @since 4.1.0
	 *
	 * @param \WP_REST_Request|null $request The REST request, when available.
	 * @return bool True for cookie/browser auth, false for any headless credential.
	 */
	private function is_rest_cookie_auth( ?\WP_REST_Request $request = null ): bool {
		$nonce = '';
		if ( $request instanceof \WP_REST_Request ) {
			$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		}
		if ( '' === $nonce ) {
			$nonce = self::sanitize_input_string( $_SERVER['HTTP_X_WP_NONCE'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only classification; sanitized in helper.
		}
		if ( '' === $nonce ) {
			$nonce = self::sanitize_input_string( $_REQUEST['_wpnonce'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only classification; sanitized in helper.
		}

		return '' !== $nonce
			&& wp_verify_nonce( $nonce, 'wp_rest' )
			&& ! rest_get_authenticated_app_password();
	}

	/**
	 * Map an upgrader_pre_install hook_extra payload to a covered gated rule.
	 *
	 * WordPress core's plugin and theme upgraders set `type` (plugin|theme) and
	 * `action` (install|update) in the hook_extra array. The language-pack
	 * upgrader uses `language_update_type` instead (no `type` key), and core
	 * updates do not carry a plugin/theme type — both therefore return null and
	 * pass through. Returns array{0:string rule_id, 1:string label} or null.
	 *
	 * @since 4.1.0
	 *
	 * @param array<string, mixed> $hook_extra Upgrader hook_extra payload.
	 * @return array{0: string, 1: string}|null
	 */
	private function classify_upgrader_effect( array $hook_extra ): ?array {
		$type   = isset( $hook_extra['type'] ) ? (string) $hook_extra['type'] : '';
		$action = isset( $hook_extra['action'] ) ? (string) $hook_extra['action'] : '';

		if ( 'plugin' === $type ) {
			return 'install' === $action
				? array( 'plugin.install', __( 'Install plugin', 'wp-sudo' ) )
				: array( 'plugin.update', __( 'Update plugin', 'wp-sudo' ) );
		}

		if ( 'theme' === $type ) {
			return 'install' === $action
				? array( 'theme.install', __( 'Install theme', 'wp-sudo' ) )
				: array( 'theme.update', __( 'Update theme', 'wp-sudo' ) );
		}

		return null;
	}

	/**
	 * Gate non-interactive surfaces (CLI, Cron, XML-RPC) at init.
	 *
	 * Runs at `init` priority 0 so it fires after WordPress core is
	 * fully loaded (roles, options, etc.) but before plugins handle
	 * any gated operations.
	 *
	 * @return void
	 */
	public function gate_non_interactive(): void {
		$surface = $this->detect_surface();

		if ( 'cli' === $surface ) {
			$this->gate_cli();
		} elseif ( 'cron' === $surface ) {
			$this->gate_cron();
		} elseif ( 'xmlrpc' === $surface ) {
			$this->gate_xmlrpc();
		}
	}

	/**
	 * Gate WP-CLI operations.
	 *
	 * Three modes:
	 * - Disabled: block ALL CLI commands immediately.
	 * - Limited:  block only gated operations; non-gated commands work normally.
	 * - Unrestricted: no blocking checks; allowed gated actions are logged when seen.
	 *
	 * In Limited and Unrestricted modes, `wp cron` subcommands also
	 * respect the Cron policy — if Cron is Disabled, `wp cron event run`
	 * is blocked even when CLI itself is open.
	 *
	 * @since 2.0.0
	 * @since 2.2.0 Three-tier model; --sudo flag removed.
	 *
	 * @return void
	 */
	public function gate_cli(): void {
		$policy = $this->get_policy( self::SETTING_CLI_POLICY );

		// Disabled: kill all CLI immediately.
		if ( self::POLICY_DISABLED === $policy ) {
			wp_die(
				esc_html__( 'WP-CLI is disabled by Sudo policy.', 'wp-sudo' ),
				'',
				array( 'response' => 403 )
			);
			return; // @codeCoverageIgnore
		}

		// Limited or Unrestricted: enforce Cron policy on wp cron subcommands.
		$this->enforce_cron_policy_on_cli();

		if ( self::POLICY_UNRESTRICTED === $policy ) {
			$this->register_function_hooks( 'cli', 'audit' );
			return;
		}

		// Limited: hook into WordPress function-level actions to deny gated operations.
		$this->register_function_hooks( 'cli' );
	}

	/**
	 * Enforce the Cron policy when running `wp cron` via WP-CLI.
	 *
	 * Prevents `wp cron event run` from bypassing a Disabled cron policy
	 * even when CLI itself is Limited or Unrestricted.
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	private function enforce_cron_policy_on_cli(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- CLI argv is a string array; no user input.
		$argv = $_SERVER['argv'] ?? array();

		if ( ! in_array( 'cron', (array) $argv, true ) ) {
			return;
		}

		$cron_policy = $this->get_policy( self::SETTING_CRON_POLICY );

		if ( self::POLICY_DISABLED === $cron_policy ) {
			wp_die(
				esc_html__( 'WP-Cron is disabled by Sudo policy. The wp cron command is not available.', 'wp-sudo' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Gate Cron operations.
	 *
	 * Three modes:
	 * - Disabled: exit immediately — kills the entire cron request.
	 *   Covers both WP-Cron (page-load trigger) and server-level cron
	 *   jobs hitting wp-cron.php directly, since both set DOING_CRON
	 *   before init fires.
	 * - Limited:  block only gated operations; non-gated events run normally.
	 * - Unrestricted: no blocking checks; allowed gated actions are logged when seen.
	 *
	 * @since 2.0.0
	 * @since 2.2.0 Three-tier model.
	 *
	 * @return void
	 */
	public function gate_cron(): void {
		$policy = $this->get_policy( self::SETTING_CRON_POLICY );

		// Disabled: kill the entire cron request immediately.
		if ( self::POLICY_DISABLED === $policy ) {
			exit;
		}

		if ( self::POLICY_UNRESTRICTED === $policy ) {
			$this->register_function_hooks( 'cron', 'audit' );
			return;
		}

		// Limited: hook into WordPress function-level actions to deny gated operations.
		$this->register_function_hooks( 'cron' );
	}

	/**
	 * Gate XML-RPC operations.
	 *
	 * Three modes:
	 * - Disabled: shut off the entire XML-RPC protocol.
	 * - Limited:  block only gated operations; non-gated methods work normally.
	 * - Unrestricted: no blocking checks; allowed gated actions are logged when seen.
	 *
	 * @since 2.0.0
	 * @since 2.2.0 Three-tier model.
	 *
	 * @return void
	 */
	public function gate_xmlrpc(): void {
		$policy = $this->get_policy( self::SETTING_XMLRPC_POLICY );

		// Disabled: kill the entire XML-RPC protocol.
		if ( self::POLICY_DISABLED === $policy ) {
			/**
			 * Suppress callback signature mismatch for core utility callback.
			 *
			 * @psalm-suppress PossiblyInvalidArgument __return_false intentionally ignores callback args.
			 */
			add_filter( 'xmlrpc_enabled', '__return_false' );
			return;
		}

		if ( self::POLICY_UNRESTRICTED === $policy ) {
			$this->register_function_hooks( 'xmlrpc', 'audit' );
			return;
		}

		// Limited: hook into WordPress function-level actions to deny gated operations.
		$this->register_function_hooks( 'xmlrpc' );
	}

	/**
	 * Register WordPress function-level hooks for non-interactive gating.
	 *
	 * Instead of trying to match admin UI request patterns (which don't work
	 * on CLI, Cron, or XML-RPC), this method hooks into the WordPress actions
	 * and filters that fire before each gated operation takes effect. These
	 * fire regardless of which surface triggers the operation.
	 *
	 * @since 2.2.0
	 *
	 * @param string $surface The surface label: 'cli', 'cron', or 'xmlrpc'.
	 * @param string $mode    'block' (default) blocks the action; 'audit' fires
	 *                        wp_sudo_action_allowed and lets the action proceed.
	 * @return void
	 */
	public function register_function_hooks( string $surface, string $mode = 'block' ): void {
		if ( 'audit' === $mode ) {
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $label required for signature parity with the block callback.
			$callback = function ( string $rule_id, string $label ) use ( $surface ): void {
				/**
				 * Fires when a gated action is permitted by Unrestricted policy.
				 *
				 * @since 2.9.0
				 *
				 * @param int    $user_id Always 0 for non-interactive surfaces.
				 * @param string $rule_id The rule ID that matched.
				 * @param string $surface The surface: 'cli', 'cron', or 'xmlrpc'.
				 */
				do_action( 'wp_sudo_action_allowed', 0, $rule_id, $surface );
			};
		} else {
			$callback = function ( string $rule_id, string $label ) use ( $surface ): void {
				/**
				 * Fires when a gated action is blocked by policy.
				 *
				 * @since 2.0.0
				 *
				 * @param int    $user_id Always 0 for non-interactive surfaces.
				 * @param string $rule_id The rule ID that matched.
				 * @param string $surface The surface: 'cli', 'cron', or 'xmlrpc'.
				 */
				do_action( 'wp_sudo_action_blocked', 0, $rule_id, $surface );

				if ( 'cron' === $surface ) {
					// Silently exit — cron jobs shouldn't produce visible errors.
					exit;
				}

				wp_die(
					esc_html(
						sprintf(
							/* translators: 1: action label, 2: surface name */
							__( 'This operation (%1$s) requires sudo and cannot be performed via %2$s.', 'wp-sudo' ),
							$label,
							'cli' === $surface ? 'WP-CLI' : 'XML-RPC'
						)
					),
					'',
					array( 'response' => 403 )
				);
			};
		}

		// ── Plugin activate ──────────────────────────────────────────
		// Fires inside activate_plugin() before the plugin is added to active_plugins.
		add_action(
			'activate_plugin',
			function () use ( $callback ) {
				$callback( 'plugin.activate', __( 'Activate plugin', 'wp-sudo' ) );
			},
			0
		);

		// ── Plugin deactivate ────────────────────────────────────────
		// No generic 'deactivate_plugin' action exists — the hook is dynamic:
		// deactivate_{$plugin_file}. We intercept at the option level instead.
		add_filter(
			'pre_update_option_active_plugins',
			function ( $new_value, $old_value ) use ( $callback ) {
				// Only block when plugins are being removed (deactivation).
				if ( is_array( $new_value ) && is_array( $old_value )
					&& count( $new_value ) < count( $old_value )
				) {
					$callback( 'plugin.deactivate', __( 'Deactivate plugin', 'wp-sudo' ) );
				}
				return $new_value;
			},
			0,
			2
		);

		// ── Plugin delete ────────────────────────────────────────────
		// Fires inside delete_plugins() before files are removed.
		add_action(
			'delete_plugin',
			function () use ( $callback ) {
				$callback( 'plugin.delete', __( 'Delete plugin', 'wp-sudo' ) );
			},
			0
		);

		// ── Theme switch ─────────────────────────────────────────────
		// No pre-switch hook exists. Intercept at the option level.
		add_filter(
			'pre_update_option_stylesheet',
			function ( $new_value, $old_value ) use ( $callback ) {
				if ( $new_value !== $old_value ) {
					$callback( 'theme.switch', __( 'Switch theme', 'wp-sudo' ) );
				}
				return $new_value;
			},
			0,
			2
		);

		// ── Theme delete ─────────────────────────────────────────────
		// Fires inside delete_theme() before files are removed.
		add_action(
			'delete_theme',
			function () use ( $callback ) {
				$callback( 'theme.delete', __( 'Delete theme', 'wp-sudo' ) );
			},
			0
		);

		// ── Plugin/Theme install and update ──────────────────────────
		// Fires inside WP_Upgrader::install_package() before extraction.
		add_filter(
			'upgrader_pre_install',
			function ( $response ) use ( $callback ) {
				if ( is_wp_error( $response ) ) {
					return $response;
				}
				// Block all installs/updates on gated surfaces.
				$callback( 'plugin.install', __( 'Install or update plugin/theme', 'wp-sudo' ) );
				return $response; // @codeCoverageIgnore
			},
			0
		);

		// ── User delete ──────────────────────────────────────────────
		// Fires inside wp_delete_user() before the record is removed.
		add_action(
			'delete_user',
			function () use ( $callback ) {
				$callback( 'user.delete', __( 'Delete user', 'wp-sudo' ) );
			},
			0
		);

		// ── User create ──────────────────────────────────────────────
		// Fires inside wp_insert_user() before the database insert.
		add_filter(
			'wp_pre_insert_user_data',
			function ( $data ) use ( $callback ) {
				// Only block new user creation, not updates.
				// wp_insert_user sets $update internally; we detect it by
				// checking if user_login is being inserted (new) vs ID exists.
				if ( is_array( $data ) && ! empty( $data['user_login'] ) ) {
					// Check if this is a creation by seeing if user_login already exists.
					$existing = get_user_by( 'login', $data['user_login'] );
					if ( ! $existing ) {
						$callback( 'user.create', __( 'Create new user', 'wp-sudo' ) );
					}
				}
				return $data;
			},
			0
		);

		// ── User role change ─────────────────────────────────────────
		// WP_User::set_role() writes the capabilities user meta before it
		// fires set_user_role, so block at the metadata layer instead.
		$user_role_meta_filter = function ( $check, $_user_id, $meta_key, $_meta_value, $_prev_value ) use ( $callback ) {
			unset( $_user_id, $_meta_value, $_prev_value );

			if ( $this->is_user_capabilities_meta_key( (string) $meta_key ) ) {
				$callback( 'user.promote', __( 'Change user role', 'wp-sudo' ) );
			}
			return $check;
		};

		add_filter( 'add_user_metadata', $user_role_meta_filter, 0, 5 );
		add_filter( 'update_user_metadata', $user_role_meta_filter, 0, 5 );

		// ── Critical options ─────────────────────────────────────────
		$critical_options = Action_Registry::critical_option_names();
		foreach ( $critical_options as $opt ) {
			add_filter(
				"pre_update_option_{$opt}",
				function ( $new_value, $old_value ) use ( $callback ) {
					if ( $new_value !== $old_value ) {
						$callback( 'options.critical', __( 'Change critical site setting', 'wp-sudo' ) );
					}
					return $new_value;
				},
				0,
				2
			);
		}

		// ── WP Sudo own settings ─────────────────────────────────────
		// Gate the plugin's own policy option on non-interactive surfaces,
		// closing a self-protection gap where a CLI/Cron/XML-RPC actor could
		// flip policies to Unrestricted without a sudo block (F3 / B4).
		// Both single-site (update_option) and multisite (update_site_option)
		// paths are covered.
		add_filter(
			'pre_update_option_wp_sudo_settings',
			function ( $new_value, $old_value ) use ( $callback ) {
				if ( $new_value !== $old_value ) {
					$callback( 'options.wp_sudo', __( 'Change Sudo settings', 'wp-sudo' ) );
				}
				return $new_value;
			},
			0,
			2
		);

		add_filter(
			'pre_update_site_option_wp_sudo_settings',
			function ( $new_value, $old_value ) use ( $callback ) {
				if ( $new_value !== $old_value ) {
					$callback( 'options.wp_sudo', __( 'Change Sudo settings', 'wp-sudo' ) );
				}
				return $new_value;
			},
			0,
			2
		);

		// ── Export ────────────────────────────────────────────────────
		// Fires inside export_wp() before headers are sent.
		add_action(
			'export_wp',
			function () use ( $callback ) {
				$callback( 'tools.export', __( 'Export site data', 'wp-sudo' ) );
			},
			0
		);
	}

	/**
	 * Arm the role-aware admin-escalation guard (opt-in, default OFF).
	 *
	 * Closes the gap left by the interactive/REST effect backstops, which
	 * deliberately exclude user.create/user.promote because hooking them
	 * unconditionally fires on every benign role assignment. This guard hooks the
	 * capabilities meta write but blocks ONLY when the write **newly grants
	 * administrator** to a user who does not already hold it (see
	 * newly_grants_administrator()), so low-privilege assignments, demotions, and
	 * idempotent self-edits pass untouched.
	 *
	 * Surface coverage: the metadata filters fire on every surface, but
	 * CLI/Cron/XML-RPC are already governed by the non-interactive policy layer
	 * (register_function_hooks); this guard defers there to avoid double-firing.
	 *
	 * Block mechanism is die_sudo_required() (wp_die 403 / JSON), i.e. the request
	 * is halted before the capabilities write persists — never a short-circuit
	 * return. See docs/admin-escalation-guard-analysis.md §6/§9.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function arm_escalation_guard(): void {
		$guard = function ( $check, $user_id, $meta_key, $meta_value, $prev_value ) {
			unset( $prev_value );

			// Opt-in; default OFF. Security-conscious sites enable it; SSO and
			// provisioning sites are unaffected unless they opt in.
			if ( ! apply_filters( 'wp_sudo_guard_escalation', false ) ) {
				return $check;
			}

			// Recovery / deployment / migration bypass — checked FIRST, before any
			// session or capability read, so a sole-admin recovery path is never
			// hard-blocked.
			if ( defined( 'WP_SUDO_ALLOW_ESCALATION' ) && WP_SUDO_ALLOW_ESCALATION ) {
				return $check;
			}

			if ( ! $this->is_user_capabilities_meta_key( (string) $meta_key ) ) {
				return $check;
			}

			// CLI/Cron/XML-RPC are owned by register_function_hooks() (policy
			// layer); defer there so the two guards never double-fire.
			$surface = $this->detect_surface();
			if ( in_array( $surface, array( 'cli', 'cron', 'xmlrpc' ), true ) ) {
				return $check;
			}

			$target_id    = (int) $user_id;
			$current_caps = get_user_meta( $target_id, (string) $meta_key, true );

			if ( ! $this->newly_grants_administrator( $meta_value, is_array( $current_caps ) ? $current_caps : array() ) ) {
				return $check;
			}

			/**
			 * Allow a trusted provisioner to opt a specific administrator grant
			 * out of the escalation guard (e.g. an allowlisted SSO/sync flow).
			 *
			 * @since 4.1.0
			 *
			 * @param bool  $allow      Whether to allow the grant. Default false.
			 * @param int   $target_id  Target user being granted administrator.
			 * @param mixed $meta_value Incoming capabilities value.
			 */
			if ( apply_filters( 'wp_sudo_allow_escalation', false, $target_id, $meta_value ) ) {
				return $check;
			}

			// A re-confirmed actor (active or in-grace sudo session) may grant
			// administrator. An unauthenticated (0) or low-privilege actor cannot
			// hold a session, so the grant is blocked.
			$actor = (int) get_current_user_id();
			if ( $actor && ( Sudo_Session::is_active( $actor ) || Sudo_Session::is_within_grace( $actor ) ) ) {
				return $check;
			}

			/**
			 * Fires when an administrator grant is blocked because no sudo session
			 * is active — a high-severity signal of a likely privilege-escalation
			 * attempt. Distinct from wp_sudo_action_blocked so external alerting can
			 * subscribe to only this case.
			 *
			 * @since 4.1.0
			 *
			 * @param int    $target_id Target user being granted administrator.
			 * @param string $rule_id   Always 'user.promote'.
			 * @param string $surface   Detected request surface.
			 */
			do_action( 'wp_sudo_escalation_blocked', $target_id, 'user.promote', $surface );

			$this->die_sudo_required( __( 'Grant administrator', 'wp-sudo' ) );

			return $check;
		};

		add_filter( 'add_user_metadata', $guard, 0, 5 );
		add_filter( 'update_user_metadata', $guard, 0, 5 );
	}

	/**
	 * Whether a capabilities meta value newly grants the administrator role.
	 *
	 * Returns true only when the incoming capabilities map grants `administrator`
	 * AND the user's currently persisted capabilities do not already include it.
	 * Re-asserting an administrator a user already holds (an idempotent self-edit)
	 * and assigning any lower-privilege role both return false, so the escalation
	 * guard never fires on benign, high-frequency role assignments — this is what
	 * keeps WooCommerce/membership/LMS provisioning and sole-admin self-edits
	 * unaffected. See docs/admin-escalation-guard-analysis.md §6.
	 *
	 * @since 4.1.0
	 *
	 * @param mixed                $new_caps     Incoming capabilities value (role => bool map).
	 * @param array<string, mixed> $current_caps The user's currently persisted capabilities map.
	 * @return bool True when administrator is present in the new value but absent
	 *              from the current value.
	 */
	private function newly_grants_administrator( $new_caps, array $current_caps ): bool {
		if ( ! is_array( $new_caps ) ) {
			return false;
		}

		$now_admin = ! empty( $new_caps['administrator'] );
		$was_admin = ! empty( $current_caps['administrator'] );

		return $now_admin && ! $was_admin;
	}

	/**
	 * Determine whether a user meta key stores WordPress role capabilities.
	 *
	 * WordPress stores user roles in a site-scoped capabilities meta key such
	 * as `wp_capabilities` or `wp_2_capabilities`. Matching exact keys avoids
	 * treating unrelated plugin metadata as a role mutation.
	 *
	 * @param string $meta_key User meta key.
	 * @return bool True when the key stores role capabilities.
	 */
	private function is_user_capabilities_meta_key( string $meta_key ): bool {
		global $wpdb;

		$keys = array( 'wp_capabilities' );

		$keys[] = (string) $wpdb->get_blog_prefix() . 'capabilities';

		if ( isset( $wpdb->prefix ) ) {
			$keys[] = (string) $wpdb->prefix . 'capabilities';
		}

		if ( isset( $wpdb->base_prefix ) ) {
			$keys[] = (string) $wpdb->base_prefix . 'capabilities';
		}

		$keys = array_values( array_unique( $keys ) );

		return in_array( $meta_key, $keys, true );
	}

	/**
	 * Get a policy setting value.
	 *
	 * @since 2.0.0
	 * @since 2.2.0 Three-tier model: disabled, limited, unrestricted.
	 *
	 * @param string $key The policy setting key.
	 * @return string The policy value ('disabled', 'limited', or 'unrestricted').
	 */
	public function get_policy( string $key ): string {
		$policy = Admin::get( $key, self::POLICY_LIMITED );
		$valid  = array( self::POLICY_DISABLED, self::POLICY_LIMITED, self::POLICY_UNRESTRICTED );

		if ( ! in_array( $policy, $valid, true ) ) {
			return self::POLICY_LIMITED;
		}

		return $policy;
	}

	/**
	 * Get the effective REST API policy for the current application password.
	 *
	 * Checks for a per-application-password policy override first. If the
	 * current request was authenticated via an Application Password and a
	 * per-password override exists, that override is returned. Otherwise,
	 * falls back to the global REST API (App Passwords) policy.
	 *
	 * @since 2.3.0
	 *
	 * @return string The policy value ('disabled', 'limited', or 'unrestricted').
	 */
	public function get_app_password_policy(): string {
		// Check if this request was authenticated via an application password.
		$app_password_uuid = rest_get_authenticated_app_password();

		if ( $app_password_uuid ) {
			$overrides = Admin::get( 'app_password_policies', array() );

			if ( is_array( $overrides ) && isset( $overrides[ $app_password_uuid ] ) ) {
				$valid = array( self::POLICY_DISABLED, self::POLICY_LIMITED, self::POLICY_UNRESTRICTED );

				if ( in_array( $overrides[ $app_password_uuid ], $valid, true ) ) {
					return $overrides[ $app_password_uuid ];
				}
			}
		}

		// Fall back to the global REST App Password policy.
		return $this->get_policy( self::SETTING_REST_APP_PASS_POLICY );
	}

	/**
	 * Main interception entry point at admin_init priority 1.
	 *
	 * Determines the surface (admin UI vs AJAX) and routes accordingly.
	 *
	 * @return void
	 */
	public function intercept(): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		// Determine current surface.
		$surface = $this->detect_surface();

		if ( 'admin' !== $surface && 'ajax' !== $surface ) {
			return;
		}

		// Match the current request against the action registry.
		$matched_rule = $this->match_request( $surface );

		if ( ! $matched_rule ) {
			return;
		}

		// If a sudo session is active (or just expired within the grace window), let the request through.
		if ( Sudo_Session::is_active( $user_id ) || Sudo_Session::is_within_grace( $user_id ) ) {
			/**
			 * Fires when a gated action passes through due to an active sudo session.
			 *
			 * @since 3.0.0
			 *
			 * @param int    $user_id The user who triggered the action.
			 * @param string $rule_id The rule ID that matched.
			 * @param string $surface The surface: 'admin' or 'ajax'.
			 */
			do_action( 'wp_sudo_action_passed', $user_id, $matched_rule['id'], $surface );
			return;
		}

		/**
		 * Fires when a gated action is intercepted.
		 *
		 * @since 2.0.0
		 *
		 * @param int    $user_id The user who triggered the action.
		 * @param string $rule_id The rule ID that matched.
		 * @param string $surface The surface: 'admin' or 'ajax'.
		 */
		do_action( 'wp_sudo_action_gated', $user_id, $matched_rule['id'], $surface );

		if ( 'ajax' === $surface ) {
			$this->block_ajax( $matched_rule );
			return;
		}

		// Admin UI: stash-challenge-replay.
		$this->challenge_admin( $user_id, $matched_rule );
	}

	/**
	 * Detect which surface the current request is on.
	 *
	 * @return string One of: 'admin', 'ajax', 'rest', 'cli', 'cron', 'xmlrpc', 'unknown'.
	 */
	public function detect_surface(): string {
		if ( wp_doing_ajax() ) {
			return 'ajax';
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return 'xmlrpc';
		}

		if ( wp_doing_cron() ) {
			return 'cron';
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}

		if ( is_admin() ) {
			return 'admin';
		}

		return 'unknown';
	}

	/**
	 * Evaluate how WP Sudo would classify a representative request.
	 *
	 * This diagnostic helper is side-effect-free: it does not stash requests,
	 * set transients, redirect, or fire audit hooks. It is intended for the
	 * internal Request / Rule Tester so operators can understand which rule
	 * would match and what the resulting gate decision would be.
	 *
	 * Supported inputs:
	 * - surface (string): admin, ajax, rest.
	 * - method (string): HTTP method, defaults to GET.
	 * - url (string): Representative URL to evaluate.
	 * - is_authenticated (bool): Whether the request has an authenticated user.
	 * - has_active_sudo (bool): Whether the request already has an active sudo session.
	 * - rest_auth_mode (string): cookie, application_password, bearer, or none.
	 * - request_params (array): Additional request parameters for admin/ajax simulation.
	 * - post_params (array): Additional POST parameters for admin/ajax simulation.
	 * - rest_params (array): Additional REST params/body params.
	 *
	 * @since 2.14.0
	 *
	 * @param array<string, mixed> $input Diagnostic input.
	 * @return array<string, mixed>
	 */
	public function evaluate_diagnostic_request( array $input ): array {
		$surface          = $this->normalize_diagnostic_surface( $input['surface'] ?? '' );
		$method           = strtoupper( self::sanitize_input_string( $input['method'] ?? 'GET' ) );
		$url              = is_string( $input['url'] ?? null ) ? $input['url'] : '';
		$is_authenticated = ! empty( $input['is_authenticated'] );
		$has_active_sudo  = ! empty( $input['has_active_sudo'] );
		$rest_auth_mode   = $this->normalize_diagnostic_rest_auth_mode( $input['rest_auth_mode'] ?? 'none' );
		$request_params   = is_array( $input['request_params'] ?? null ) ? $input['request_params'] : array();
		$post_params      = is_array( $input['post_params'] ?? null ) ? $input['post_params'] : array();
		$rest_params      = is_array( $input['rest_params'] ?? null ) ? $input['rest_params'] : array();

		if ( '' === $method ) {
			$method = 'GET';
		}

		$result = array(
			'matched_rule_id'       => null,
			'matched_rule_label'    => null,
			'matched_surface'       => null,
			'decision'              => 'allow',
			'stash_replay_eligible' => false,
			'notes'                 => array(),
		);

		if ( ! in_array( $surface, array( 'admin', 'ajax', 'rest' ), true ) ) {
			$result['notes'][] = __( 'Only admin, AJAX, and REST request simulation is available in the current tester MVP.', 'wp-sudo' );
			return $result;
		}

		$matched_rule = null;

		if ( 'rest' === $surface ) {
			$matched_rule = $this->match_diagnostic_rest_request( $method, $url, $rest_params );
		} else {
			$matched_rule = $this->match_diagnostic_browser_request( $surface, $method, $url, $request_params, $post_params );
		}

		if ( $matched_rule ) {
			$result['matched_rule_id']    = $matched_rule['id'] ?? null;
			$result['matched_rule_label'] = $matched_rule['label'] ?? ( $matched_rule['id'] ?? null );
			$result['matched_surface']    = $surface;
		}

		if ( ! $is_authenticated ) {
			$result['notes'][] = __( 'Sudo only gates authenticated users; anonymous requests fall through to WordPress authentication and capability checks first.', 'wp-sudo' );
			return $result;
		}

		if ( ! $matched_rule ) {
			$result['notes'][] = __( 'No gated rule matched this request shape.', 'wp-sudo' );
			return $result;
		}

		if ( $has_active_sudo ) {
			$result['notes'][] = __( 'An active sudo session would allow this matched request to proceed.', 'wp-sudo' );
			return $result;
		}

		if ( 'admin' === $surface ) {
			$result['decision']              = 'gate';
			$result['stash_replay_eligible'] = true;
			$result['notes'][]               = __( 'Interactive admin requests use challenge + stash/replay.', 'wp-sudo' );
			return $result;
		}

		if ( 'ajax' === $surface ) {
			$result['decision'] = 'soft-block';
			$result['notes'][]  = __( 'AJAX requests are blocked in-place and must be retried after activating sudo.', 'wp-sudo' );
			return $result;
		}

		if ( 'cookie' === $rest_auth_mode ) {
			$result['decision'] = 'soft-block';
			$result['notes'][]  = __( 'Cookie-authenticated REST requests receive sudo_required and can be retried after activating sudo.', 'wp-sudo' );
			return $result;
		}

		$policy = $this->get_policy( self::SETTING_REST_APP_PASS_POLICY );

		if ( self::POLICY_UNRESTRICTED === $policy ) {
			$result['notes'][] = __( 'REST Application Password policy is Unrestricted, so Sudo would allow the matched request.', 'wp-sudo' );
			return $result;
		}

		$result['decision'] = 'hard-block';

		if ( self::POLICY_DISABLED === $policy ) {
			$result['notes'][] = __( 'REST Application Password policy is Disabled, so Sudo would reject the request at the surface level.', 'wp-sudo' );
			return $result;
		}

		$result['notes'][] = __( 'REST Application Password policy is Limited, so gated requests are blocked until policy changes.', 'wp-sudo' );

		return $result;
	}

	/**
	 * Match the current request against the action registry for a given surface.
	 *
	 * @param string                $surface The surface to match against ('admin', 'ajax', or 'rest').
	 * @param \WP_REST_Request|null $request REST request object (required for 'rest' surface).
	 * @return array<string, mixed>|null The matched rule, or null.
	 */
	public function match_request( string $surface, ?\WP_REST_Request $request = null ): ?array {
		$rules = Action_Registry::get_rules();

		// Hoist sanitization of request params above the loop so each
		// rule iteration reuses the same sanitized values instead of
		// calling sanitize_text_field() up to 28 times per request.
			$request_action = '';
			$request_method = '';

		if ( 'admin' === $surface || 'ajax' === $surface ) {
			$request_action = self::sanitize_input_string( $_REQUEST['action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Gate routing only; sanitized in helper.
		}

		// WordPress core's multisite sites.php uses a two-step confirmation
		// flow: the initial link sends action=confirm&action2=archiveblog
		// (or deleteblog, spamblog, deactivateblog). The real action name
		// is in action2, so we extract it as a fallback for matching.
		$request_action2 = '';
		if ( 'admin' === $surface && 'confirm' === $request_action ) {
			$request_action2 = self::sanitize_input_string( $_REQUEST['action2'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Gate routing only; sanitized in helper.
		}

		if ( 'admin' === $surface ) {
			$request_method = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) );
		}

		foreach ( $rules as $rule ) {
			if ( 'admin' === $surface && $this->matches_admin( $rule, $request_action, $request_method ) ) {
				return $rule;
			}

			// Fallback: try action2 for the WP core confirm-action flow.
			if ( 'admin' === $surface && '' !== $request_action2 && $this->matches_admin( $rule, $request_action2, $request_method ) ) {
				return $rule;
			}

			if ( 'ajax' === $surface && $this->matches_ajax( $rule, $request_action ) ) {
				return $rule;
			}

			if ( 'rest' === $surface && null !== $request && $this->matches_rest( $rule, $request ) ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * Match a representative admin or AJAX request without leaving state behind.
	 *
	 * @param string               $surface        admin or ajax.
	 * @param string               $method         HTTP method.
	 * @param string               $url            Representative URL.
	 * @param array<string, mixed> $request_params Additional request parameters.
	 * @param array<string, mixed> $post_params    Additional POST parameters.
	 * @return array<string, mixed>|null
	 */
	private function match_diagnostic_browser_request( string $surface, string $method, string $url, array $request_params, array $post_params ): ?array {
		$parsed_url   = $this->parse_diagnostic_url( $url );
		$query_params = $parsed_url['query_params'];
		$merged_post  = $post_params;
		$merged_get   = $query_params;
		$merged_req   = array_merge( $query_params, $request_params, $post_params );
		$pagenow      = $parsed_url['pagenow'];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Diagnostic matching only; side-effect-free simulation.
		$request_action  = self::sanitize_input_string( $merged_req['action'] ?? '' );
		$request_action2 = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Diagnostic helper only backs up and restores local request state.
		$original_get = $_GET;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Diagnostic helper only backs up and restores local request state.
		$original_post = $_POST;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Diagnostic helper only backs up and restores local request state.
		$original_req = $_REQUEST;

		if ( 'admin' === $surface && 'confirm' === $request_action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Diagnostic matching only; side-effect-free simulation.
			$request_action2 = self::sanitize_input_string( $merged_req['action2'] ?? '' );
		}

		try {
			$_GET     = $merged_get;
			$_POST    = $merged_post;
			$_REQUEST = $merged_req;

			foreach ( Action_Registry::get_rules() as $rule ) {
				if ( 'admin' === $surface && $this->matches_admin_pagenow( $rule, $pagenow, $request_action, $method ) ) {
					return $rule;
				}

				if ( 'admin' === $surface && '' !== $request_action2 && $this->matches_admin_pagenow( $rule, $pagenow, $request_action2, $method ) ) {
					return $rule;
				}

				if ( 'ajax' === $surface && $this->matches_ajax( $rule, $request_action ) ) {
					return $rule;
				}
			}

			return null;
		} finally {
			$_GET     = $original_get;
			$_POST    = $original_post;
			$_REQUEST = $original_req;
		}
	}

	/**
	 * Match a representative REST request without runtime side effects.
	 *
	 * @param string               $method      HTTP method.
	 * @param string               $url         Representative URL.
	 * @param array<string, mixed> $rest_params Additional REST params/body params.
	 * @return array<string, mixed>|null
	 */
	private function match_diagnostic_rest_request( string $method, string $url, array $rest_params ): ?array {
		$parsed_url   = $this->parse_diagnostic_url( $url );
		$query_params = $parsed_url['query_params'];
		$route        = $this->extract_rest_route_from_parsed_url( $parsed_url );

		$request = new \WP_REST_Request( $method, $route );
		$request->set_query_params( $query_params );
		$request->set_body_params( $rest_params );

		return $this->match_request( 'rest', $request );
	}

	/**
	 * Check if the current admin request matches a rule's admin criteria.
	 *
	 * @param array<string, mixed> $rule           A gated action rule.
	 * @param string               $request_action Pre-sanitized $_REQUEST['action'] value.
	 * @param string               $request_method Pre-sanitized $_SERVER['REQUEST_METHOD'] value.
	 * @return bool
	 */
	private function matches_admin( array $rule, string $request_action, string $request_method ): bool {
		global $pagenow;
		$current_pagenow = is_string( $pagenow ) ? $pagenow : '';

		return $this->matches_admin_pagenow( $rule, $current_pagenow, $request_action, $request_method );
	}

	/**
	 * Check if an admin request with a known pagenow matches a rule.
	 *
	 * @param array<string, mixed> $rule           A gated action rule.
	 * @param string               $pagenow        The target admin page basename.
	 * @param string               $request_action Pre-sanitized request action.
	 * @param string               $request_method Pre-sanitized request method.
	 * @return bool
	 */
	private function matches_admin_pagenow( array $rule, string $pagenow, string $request_action, string $request_method ): bool {
		if ( empty( $rule['admin'] ) ) {
			return false;
		}

		$admin = $rule['admin'];

		$pagenow_list = (array) ( $admin['pagenow'] ?? array() );
		if ( ! in_array( $pagenow, $pagenow_list, true ) ) {
			return false;
		}

		$actions = (array) ( $admin['actions'] ?? array() );

		if ( ! in_array( $request_action, $actions, true ) ) {
			return false;
		}

		$method = $admin['method'] ?? 'ANY';
		if ( 'ANY' !== $method && $request_method !== $method ) {
			return false;
		}

		if ( isset( $admin['callback'] ) && is_callable( $admin['callback'] ) ) {
			if ( ! call_user_func( $admin['callback'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if the current AJAX request matches a rule's ajax criteria.
	 *
	 * @param array<string, mixed> $rule           A gated action rule.
	 * @param string               $request_action Pre-sanitized $_REQUEST['action'] value.
	 * @return bool
	 */
	private function matches_ajax( array $rule, string $request_action ): bool {
		if ( empty( $rule['ajax'] ) ) {
			return false;
		}

		$ajax = $rule['ajax'];

		$actions = (array) ( $ajax['actions'] ?? array() );

		return in_array( $request_action, $actions, true );
	}

	/**
	 * REST API interception via rest_request_before_callbacks filter.
	 *
	 * Returns a WP_Error to short-circuit the request when a gated
	 * action is attempted without an active sudo session.
	 *
	 * Cookie-auth (browser) requests get a soft block (sudo_required).
	 * An admin notice on the next page load links to the challenge page.
	 *
	 * @param mixed                $response Response to replace the requested response.
	 * @param array<string, mixed> $handler Route handler info.
	 * @param \WP_REST_Request     $request  REST request object.
	 * @return mixed|\WP_Error Original response or WP_Error to block.
	 */
	public function intercept_rest( $response, $handler, \WP_REST_Request $request ) {
		// If already an error, don't override.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return $response;
		}

		$matched_rule = $this->match_request( 'rest', $request );

		if ( ! $matched_rule ) {
			return $response;
		}

		if ( Sudo_Session::is_active( $user_id ) || Sudo_Session::is_within_grace( $user_id ) ) {
			/**
			 * Fires when a gated action passes through due to an active sudo session.
			 *
			 * @since 3.0.0
			 *
			 * @param int    $user_id The user who triggered the action.
			 * @param string $rule_id The rule ID that matched.
			 * @param string $surface Always 'rest' for REST API.
			 */
			do_action( 'wp_sudo_action_passed', $user_id, $matched_rule['id'], 'rest' );
			return $response;
		}

		// Distinguish cookie-auth (browser) from app-password/bearer (headless)
		// via the shared classifier (also used by the REST effect backstop).
		$is_cookie_auth = $this->is_rest_cookie_auth( $request );

		if ( ! $is_cookie_auth ) {
			// Non-browser auth (app-password, bearer, etc.) — check policy.
			// Per-app-password override takes precedence over the global policy.
			$policy = $this->get_app_password_policy();

			// Unrestricted: pass through, audit only.
			if ( self::POLICY_UNRESTRICTED === $policy ) {
				/**
				 * Fires when a gated action is permitted by Unrestricted policy.
				 *
				 * @since 2.9.0
				 *
				 * @param int    $user_id The user who triggered the action.
				 * @param string $rule_id The rule ID that matched.
				 * @param string $surface Always 'rest_app_password' here.
				 */
				do_action( 'wp_sudo_action_allowed', $user_id, $matched_rule['id'], 'rest_app_password' );
				return $response;
			}

			// Disabled: block without logging.
			if ( self::POLICY_DISABLED === $policy ) {
				return new \WP_Error(
					'sudo_disabled',
					__( 'This REST API operation is disabled by Sudo policy.', 'wp-sudo' ),
					array( 'status' => 403 )
				);
			}

			// Limited: block with logging.
			/** This action is documented in includes/class-gate.php */
			do_action( 'wp_sudo_action_blocked', $user_id, $matched_rule['id'], 'rest_app_password' );
			return new \WP_Error(
				'sudo_blocked',
				__( 'This operation requires sudo and cannot be performed via Application Passwords.', 'wp-sudo' ),
				array( 'status' => 403 )
			);
		}

		// Cookie-auth browser request — return sudo_required error and set admin notice.

		/**
		 * Fires when a gated action is intercepted on the REST surface.
		 *
		 * @since 2.0.0
		 *
		 * @param int    $user_id The user who triggered the action.
		 * @param string $rule_id The rule ID that matched.
		 * @param string $surface Always 'rest'.
		 */
		do_action( 'wp_sudo_action_gated', $user_id, $matched_rule['id'], 'rest' );

		return $this->block_rest( $matched_rule );
	}

	/**
	 * Evaluate a WPGraphQL request body against the current policy.
	 *
	 * Extracted from gate_wpgraphql() for testability: the hook handler
	 * calls this with the real php://input body; integration tests call it
	 * directly with any body string, without needing WPGraphQL installed.
	 *
	 * Returns a WP_Error to signal a block (sudo_disabled or sudo_blocked),
	 * or null to pass through. The wp_sudo_action_blocked audit hook fires
	 * here for the Limited-blocked path only; the Disabled path has no
	 * audit hook by design.
	 *
	 * @since 2.6.0
	 *
	 * @param string               $body           The raw GraphQL request body.
	 * @param array<string, mixed> $request_params Parsed request parameters, when available.
	 * @return \WP_Error|null WP_Error to block, null to pass through.
	 */
	public function check_wpgraphql( string $body, array $request_params = array() ): ?\WP_Error {
		$policy = $this->get_policy( self::SETTING_WPGRAPHQL_POLICY );

		// Unrestricted: pass everything through; audit mutations only.
		if ( self::POLICY_UNRESTRICTED === $policy ) {
			if ( $this->is_wpgraphql_mutation( $body, $request_params ) ) {
				/** This action is documented in includes/class-gate.php */
				do_action( 'wp_sudo_action_allowed', get_current_user_id(), 'wpgraphql', 'wpgraphql' );
			}
			return null;
		}

		// Disabled: block all requests, no audit hook.
		if ( self::POLICY_DISABLED === $policy ) {
			return new \WP_Error(
				'sudo_disabled',
				__( 'WPGraphQL is disabled by Sudo policy.', 'wp-sudo' ),
				array( 'status' => 403 )
			);
		}

		// Limited: block mutations without an active sudo session.

		/**
		 * Filter whether to bypass WPGraphQL gating for this request.
		 *
		 * Fires in Limited mode before mutation detection. Return true to
		 * allow the request through without sudo session checks. Useful for
		 * exempting authentication mutations (e.g. JWT login/refresh) that
		 * must work without a sudo session.
		 *
		 * Does NOT fire in Disabled or Unrestricted mode — those policies
		 * return before this point.
		 *
		 * @since 2.7.0
		 *
		 * @param bool   $bypass Whether to bypass gating. Default false.
		 * @param string $body   The raw GraphQL request body.
		 */
		$bypass = (bool) apply_filters( 'wp_sudo_wpgraphql_bypass', false, $body );

		if ( $bypass ) {
			return null;
		}

		if ( ! $this->is_wpgraphql_mutation( $body, $request_params ) ) {
			return null; // Not a mutation — pass through.
		}

		$user_id = get_current_user_id();

		if ( $user_id && ( Sudo_Session::is_active( $user_id ) || Sudo_Session::is_within_grace( $user_id ) ) ) {
			/**
			 * Fires when a gated action passes through due to an active sudo session.
			 *
			 * @since 3.0.0
			 *
			 * @param int    $user_id The user who triggered the action.
			 * @param string $rule_id Always 'wpgraphql' for this surface.
			 * @param string $surface Always 'wpgraphql'.
			 */
			do_action( 'wp_sudo_action_passed', $user_id, 'wpgraphql', 'wpgraphql' );
			return null; // Active sudo session (or grace window) — pass through.
		}

		/**
		 * Fires when a gated action is blocked by policy.
		 *
		 * @since 2.0.0
		 *
		 * @param int    $user_id The user who triggered the action.
		 * @param string $rule_id Always 'wpgraphql' for this surface.
		 * @param string $surface Always 'wpgraphql'.
		 */
		do_action( 'wp_sudo_action_blocked', $user_id, 'wpgraphql', 'wpgraphql' );

		return new \WP_Error(
			'sudo_blocked',
			__( 'This GraphQL mutation requires sudo. Activate a sudo session and try again.', 'wp-sudo' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Determine whether a WPGraphQL request body should be treated as mutation.
	 *
	 * Applies the optional classifier filter first. Supported return values:
	 * - 'mutation': treat as mutation.
	 * - 'query': treat as non-mutation.
	 * Any other value falls back to extracting inline GraphQL documents and
	 * scanning them for a top-level `mutation` token; an opaque persisted/APQ
	 * operation that carries no inline document fails safe and is treated as a
	 * mutation.
	 *
	 * @since 2.11.0
	 *
	 * @param string               $body           Raw GraphQL request body.
	 * @param array<string, mixed> $request_params Parsed request parameters, when available.
	 * @return bool
	 */
	private function is_wpgraphql_mutation( string $body, array $request_params = array() ): bool {
		/**
		 * Classify a WPGraphQL request body as mutation or query.
		 *
		 * Return 'mutation' to force mutation handling, or 'query' to force
		 * query handling. Any other return value falls back to the default
		 * document tokenizer.
		 *
		 * Useful for persisted-query setups where the request body does not
		 * include inline GraphQL text. Such opaque operations cannot be
		 * tokenized, so they fail safe (treated as mutations) by default;
		 * return 'query' here to let known read-only persisted operations
		 * through.
		 *
		 * @since 2.11.0
		 *
		 * @param string $classification Classification hint. Default ''.
		 * @param string $body           Raw GraphQL request body.
		 */
		$classification = apply_filters( 'wp_sudo_wpgraphql_classification', '', $body );

		if ( is_string( $classification ) ) {
			$classification = strtolower( trim( $classification ) );

			if ( 'mutation' === $classification ) {
				return true;
			}

			if ( 'query' === $classification ) {
				return false;
			}
		}

		foreach ( $this->extract_wpgraphql_documents( $body, $request_params ) as $document ) {
			if ( $this->wpgraphql_document_contains_mutation( $document ) ) {
				return true;
			}
		}

		// Fail-safe: a persisted/APQ request carries only an operation id or
		// hash, not inline GraphQL text, so the tokenizer above cannot see
		// whether it resolves to a mutation. Treat such opaque operations as
		// mutations by default so a persisted mutation cannot slip through
		// ungated in Limited mode. Operators running persisted queries can
		// resolve the real operation type via the
		// `wp_sudo_wpgraphql_classification` filter (checked above) or set the
		// surface policy to Unrestricted.
		if ( $this->body_has_persisted_operation( $body, $request_params ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether a WPGraphQL request body is an opaque persisted/APQ operation.
	 *
	 * A persisted operation references stored GraphQL by id or hash instead of
	 * sending the document text, so its operation type (query vs mutation) is
	 * not visible to the body tokenizer. Detecting it lets Limited mode fail
	 * safe rather than fail open.
	 *
	 * @since 3.1.4
	 *
	 * @param string               $body           Raw request body.
	 * @param array<string, mixed> $request_params Parsed request parameters, when available.
	 * @return bool True when the body looks like a persisted operation.
	 */
	private function body_has_persisted_operation( string $body, array $request_params = array() ): bool {
		$body           = self::strip_leading_bom( $body );
		$body           = trim( $body );
		$request_params = $this->normalize_wpgraphql_request_params( $request_params );

		if ( '' !== $body ) {
			$decoded = json_decode( $body, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				// Single operation object.
				if ( $this->entry_is_persisted_operation( $decoded ) ) {
					return true;
				}

				// Batched array of operation objects: any persisted entry fails safe.
				foreach ( $decoded as $entry ) {
					if ( is_array( $entry ) && $this->entry_is_persisted_operation( $entry ) ) {
						return true;
					}
				}
			}
		}

		if ( $this->entry_is_persisted_operation( $request_params ) ) {
			return true;
		}

		foreach ( $this->extract_wpgraphql_operations_payloads_from_request_params( $request_params ) as $payload ) {
			if ( ! is_array( $payload ) ) {
				continue;
			}

			if ( $this->entry_is_persisted_operation( $payload ) ) {
				return true;
			}

			foreach ( $payload as $entry ) {
				if ( is_array( $entry ) && $this->entry_is_persisted_operation( $entry ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Normalize parsed GraphQL request params for classification.
	 *
	 * GET requests commonly carry `extensions` as a JSON-encoded string, while
	 * bracket-notation form requests may already provide it as an array.
	 *
	 * @since 3.1.4
	 *
	 * @param array<string, mixed> $params Parsed request parameters.
	 * @return array<string, mixed> Parameters with supported JSON fields decoded.
	 */
	private function normalize_wpgraphql_request_params( array $params ): array {
		if ( isset( $params['extensions'] ) && is_string( $params['extensions'] ) ) {
			$decoded = json_decode( self::strip_leading_bom( $params['extensions'] ), true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$params['extensions'] = $decoded;
			}
		}

		return $params;
	}

	/**
	 * Whether a single decoded GraphQL operation entry is a persisted reference.
	 *
	 * @since 3.1.4
	 *
	 * @param array<string, mixed> $entry Decoded operation entry.
	 * @return bool
	 */
	private function entry_is_persisted_operation( array $entry ): bool {
		// Inline document text is classified by the tokenizer, not the fail-safe.
		if ( isset( $entry['query'] ) && is_string( $entry['query'] ) && '' !== trim( $entry['query'] ) ) {
			return false;
		}

		// Apollo/WPGraphQL persisted-operation id and hash indicators.
		foreach ( array( 'id', 'queryId', 'documentId' ) as $key ) {
			if ( isset( $entry[ $key ] ) && is_scalar( $entry[ $key ] ) && '' !== (string) $entry[ $key ] ) {
				return true;
			}
		}

		if ( isset( $entry['extensions']['persistedQuery'] ) && is_array( $entry['extensions']['persistedQuery'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Strip a leading UTF-8 byte-order mark from a request body.
	 *
	 * A BOM ahead of the JSON envelope otherwise defeats json_decode(), forcing
	 * the raw-scan fallback where a mutation hidden inside the JSON braces is
	 * never seen at top level.
	 *
	 * @since 3.1.4
	 *
	 * @param string $body Raw request body.
	 * @return string Body without a leading UTF-8 BOM.
	 */
	private static function strip_leading_bom( string $body ): string {
		if ( 0 === strncmp( $body, "\xEF\xBB\xBF", 3 ) ) {
			return substr( $body, 3 );
		}

		return $body;
	}

	/**
	 * Extract GraphQL document strings from a WPGraphQL request body.
	 *
	 * Supports the common JSON body shape, JSON batch arrays, form-encoded
	 * `query=` payloads, and raw GraphQL documents.
	 *
	 * @since 3.1.4
	 *
	 * @param string               $body           Raw request body.
	 * @param array<string, mixed> $request_params Parsed request parameters, when available.
	 * @return string[] GraphQL document strings.
	 */
	private function extract_wpgraphql_documents( string $body, array $request_params = array() ): array {
		$request_documents = $this->extract_wpgraphql_documents_from_request_params( $request_params );
		$body              = self::strip_leading_bom( $body );
		$body              = trim( $body );
		if ( '' === $body ) {
			return $request_documents;
		}

		$decoded = json_decode( $body, true );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			return array_merge(
				$request_documents,
				$this->extract_wpgraphql_documents_from_decoded_payload( $decoded )
			);
		}

		$params = array();
		parse_str( $body, $params );

		if ( isset( $params['query'] ) && is_string( $params['query'] ) ) {
			$request_documents[] = $params['query'];
		}

		$request_documents = array_merge(
			$request_documents,
			$this->extract_wpgraphql_documents_from_request_params( $params )
		);

		if ( array() !== $request_documents ) {
			return $request_documents;
		}

		return array( $body );
	}

	/**
	 * Extract GraphQL documents from parsed request parameters.
	 *
	 * Supports GraphQL multipart requests where PHP parses the `operations`
	 * form field into $_POST instead of leaving the GraphQL document visible in
	 * php://input.
	 *
	 * @since 3.1.4
	 *
	 * @param array<string, mixed> $params Parsed request parameters.
	 * @return string[] GraphQL document strings.
	 */
	private function extract_wpgraphql_documents_from_request_params( array $params ): array {
		$documents = array();

		if ( isset( $params['query'] ) && is_string( $params['query'] ) ) {
			$documents[] = $params['query'];
		}

		foreach ( $this->extract_wpgraphql_operations_payloads_from_request_params( $params ) as $payload ) {
			$documents = array_merge(
				$documents,
				$this->extract_wpgraphql_documents_from_decoded_payload( $payload )
			);
		}

		return $documents;
	}

	/**
	 * Extract decoded GraphQL `operations` payloads from parsed request params.
	 *
	 * @since 3.1.4
	 *
	 * @param array<string, mixed> $params Parsed request parameters.
	 * @return array<int, mixed> Decoded operations payloads.
	 */
	private function extract_wpgraphql_operations_payloads_from_request_params( array $params ): array {
		if ( ! array_key_exists( 'operations', $params ) ) {
			return array();
		}

		$operations = $params['operations'];

		if ( is_array( $operations ) ) {
			return array( $operations );
		}

		if ( ! is_string( $operations ) ) {
			return array();
		}

		$decoded = json_decode( self::strip_leading_bom( $operations ), true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return array();
		}

		return array( $decoded );
	}

	/**
	 * Extract GraphQL documents from a decoded JSON payload.
	 *
	 * @since 3.1.4
	 *
	 * @param mixed $payload Decoded JSON payload.
	 * @return string[] GraphQL document strings.
	 */
	private function extract_wpgraphql_documents_from_decoded_payload( $payload ): array {
		if ( ! is_array( $payload ) ) {
			return array();
		}

		if ( isset( $payload['query'] ) && is_string( $payload['query'] ) ) {
			return array( $payload['query'] );
		}

		$documents = array();

		foreach ( $payload as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['query'] ) || ! is_string( $item['query'] ) ) {
				continue;
			}

			$documents[] = $item['query'];
		}

		return $documents;
	}

	/**
	 * Determine whether a GraphQL document contains a mutation operation.
	 *
	 * The scan ignores quoted strings, block strings, comments, and nested
	 * selection/input object blocks. This avoids both JSON-escape false negatives
	 * and simple query string-argument false positives.
	 *
	 * @since 3.1.4
	 *
	 * @param string $document GraphQL document string.
	 * @return bool True when a top-level mutation operation token is present.
	 */
	private function wpgraphql_document_contains_mutation( string $document ): bool {
		$length = strlen( $document );
		$depth  = 0;

		for ( $i = 0; $i < $length; ++$i ) {
			$char = $document[ $i ];

			if ( '#' === $char ) {
				// A GraphQL line comment terminates at ANY line terminator —
				// line feed (U+000A) OR carriage return (U+000D), per
				// https://spec.graphql.org/draft/#sec-Line-Terminators. Stopping
				// only at LF let a bare-CR comment swallow a following top-level
				// `mutation` token that graphql-php would still tokenize, opening
				// a parser-differential gate bypass.
				while ( $i < $length && "\n" !== $document[ $i ] && "\r" !== $document[ $i ] ) {
					++$i;
				}
				continue;
			}

			if ( '"' === $char ) {
				$next_three = substr( $document, $i, 3 );
				if ( '"""' === $next_three ) {
					$i += 3;
					while ( $i < $length ) {
						// Escaped triple-quote (\""") stays inside the block
						// string and does not terminate it. Skip the backslash
						// and the escaped """, then keep scanning.
						if ( '\\' === $document[ $i ] && '"""' === substr( $document, $i + 1, 3 ) ) {
							$i += 4;
							continue;
						}

						if ( '"""' === substr( $document, $i, 3 ) ) {
							break;
						}

						++$i;
					}
					$i += 2;
					continue;
				}

				++$i;
				while ( $i < $length ) {
					if ( '\\' === $document[ $i ] ) {
						$i += 2;
						continue;
					}

					if ( '"' === $document[ $i ] ) {
						break;
					}

					++$i;
				}
				continue;
			}

			if ( '{' === $char ) {
				++$depth;
				continue;
			}

			if ( '}' === $char ) {
				$depth = max( 0, $depth - 1 );
				continue;
			}

			if ( 0 !== $depth || ! preg_match( '/[A-Za-z_]/', $char ) ) {
				continue;
			}

			$start = $i;
			while ( $i < $length && preg_match( '/[A-Za-z0-9_]/', $document[ $i ] ) ) {
				++$i;
			}

			$token = substr( $document, $start, $i - $start );
			--$i;

			if ( 'mutation' === $token ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Gate WPGraphQL surface requests.
	 *
	 * Hooked to graphql_process_http_request, which fires inside WPGraphQL's
	 * own request handler after authentication is validated but before the
	 * request body is read or any GraphQL execution begins. This hook fires
	 * regardless of how the WPGraphQL endpoint is named or configured —
	 * WPGraphQL handles its own URL routing, so no endpoint pattern matching
	 * is needed here.
	 *
	 * Three modes:
	 * - Unrestricted: pass through without any checks.
	 * - Disabled:     block ALL requests (queries and mutations); no audit hook.
	 * - Limited:      block only mutations without an active sudo session.
	 *
	 * Note on php://input: graphql_process_http_request fires before WPGraphQL
	 * reads the request body. Reading php://input here is safe — PHP-FPM buffers
	 * the input stream, so WPGraphQL can still read it after this hook returns.
	 *
	 * @since 2.5.0 (re-implemented; previously non-functional due to incorrect hook)
	 * @return void
	 */
	public function gate_wpgraphql(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsRemoteFile -- php://input is a local stream, not a remote request.
		$body        = (string) file_get_contents( 'php://input' );
		$get_params  = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Classification only; no state change or trust decision without sudo session validation.
		$post_params = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Classification only; no state change or trust decision without sudo session validation.
		$result      = $this->check_wpgraphql(
			$body,
			array_merge(
				is_array( $get_params ) ? $get_params : array(),
				is_array( $post_params ) ? $post_params : array()
			)
		);

		if ( null === $result ) {
			return;
		}

		$data = $result->get_error_data();

		wp_send_json(
			array(
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
				'data'    => is_array( $data ) ? $data : array( 'status' => 403 ),
			),
			is_array( $data ) ? ( $data['status'] ?? 403 ) : 403
		);
	}

	/**
	 * Check if a REST request matches a rule's rest criteria.
	 *
	 * @param array<string, mixed> $rule    A gated action rule.
	 * @param \WP_REST_Request     $request REST request object.
	 * @return bool
	 */
	private function matches_rest( array $rule, \WP_REST_Request $request ): bool {
		if ( empty( $rule['rest'] ) ) {
			return false;
		}

		$rest = $rule['rest'];

		// Match route pattern (regex).
		$route         = $request->get_route();
		$route_pattern = $rest['route'] ?? '';
		if ( ! is_string( $route_pattern ) || '' === $route_pattern ) {
			return false;
		}

		// A malformed pattern on a BUILT-IN rule is a bug in code we ship and
		// must fail closed (gate the request) rather than silently passing it
		// through. A malformed pattern from a third-party filter rule still
		// degrades gracefully so a buggy extension cannot gate unrelated traffic.
		$fail_closed = Action_Registry::is_builtin_rule_id( (string) ( $rule['id'] ?? '' ) );

		if ( ! $this->safe_preg_match( $route_pattern, $route, $fail_closed ) ) {
			return false;
		}

		// Match HTTP method.
		$methods = (array) ( $rest['methods'] ?? array() );
		if ( ! in_array( $request->get_method(), $methods, true ) ) {
			return false;
		}

		// Optional callback for extra conditions.
		if ( isset( $rest['callback'] ) && is_callable( $rest['callback'] ) ) {
			if ( ! call_user_func( $rest['callback'], $request ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Safely evaluate a regex pattern without leaking warnings.
	 *
	 * A pattern can fail to compile (invalid PCRE injected by a third-party
	 * filter on wp_sudo_gated_actions) or fail at runtime (e.g. a PCRE
	 * backtrack/recursion limit hit on a pathological subject). Both raise a
	 * warning and make preg_match() return false. When that happens the match
	 * outcome is unknowable, so the caller decides the safe direction:
	 *
	 * - $fail_closed = true  (built-in rule): treat as a match so the request
	 *   is gated rather than silently allowed.
	 * - $fail_closed = false (third-party rule): treat as no match so a buggy
	 *   extension cannot gate unrelated traffic.
	 *
	 * Either way a `wp_sudo_rule_regex_error` action fires so the misbehaving
	 * pattern is observable instead of silently disabling (or over-enforcing) a
	 * gate.
	 *
	 * @param string $pattern     Regex pattern.
	 * @param string $subject     Subject to test.
	 * @param bool   $fail_closed Direction to return when the pattern errors.
	 * @return bool True when the pattern matches (or, on error, $fail_closed).
	 */
	private function safe_preg_match( string $pattern, string $subject, bool $fail_closed = false ): bool {
		$had_warning = false;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Guard invalid third-party regex patterns and fail closed.
		set_error_handler(
			static function () use ( &$had_warning ): bool {
				$had_warning = true;
				return true;
			}
		);

		$matched = preg_match( $pattern, $subject );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Restore the previous handler immediately after guarded call.
		restore_error_handler();

		if ( $had_warning || false === $matched ) {
			/**
			 * Fires when a gated-action rule's regex pattern fails to evaluate.
			 *
			 * Lets operators detect a malformed or pathological rule pattern
			 * that would otherwise silently change gating behavior.
			 *
			 * @since 3.1.4
			 *
			 * @param string $pattern     The pattern that failed.
			 * @param string $subject     The subject under test.
			 * @param bool   $fail_closed Whether the failure gated the request.
			 */
			do_action( 'wp_sudo_rule_regex_error', $pattern, $subject, $fail_closed );

			return $fail_closed;
		}

		return 1 === $matched;
	}

	/**
	 * Return a WP_Error to block a REST request that requires sudo.
	 *
	 * Also sets a short-lived transient for the admin notice fallback.
	 *
	 * @param array<string, mixed> $matched_rule The rule that matched.
	 * @return \WP_Error
	 */
	private function block_rest( array $matched_rule ): \WP_Error {
		$this->set_blocked_transient( $matched_rule );

		// phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__ -- UI hint only.
		$is_mac = isset( $_SERVER['HTTP_USER_AGENT'] )
			&& false !== strpos( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 'Mac' );
		// phpcs:enable WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__

		$shortcut = $is_mac ? 'Cmd+Shift+S' : 'Ctrl+Shift+S';

		return new \WP_Error(
			'sudo_required',
			sprintf(
				/* translators: 1: action label (e.g. "Delete plugin"), 2: keyboard shortcut */
				__( 'This action (%1$s) requires reauthentication. Press %2$s to start a sudo session, then try again.', 'wp-sudo' ),
				$matched_rule['label'] ?? $matched_rule['id'],
				$shortcut
			),
			array(
				'status'  => 403,
				'rule_id' => $matched_rule['id'],
			)
		);
	}

	/**
	 * Admin UI interception: stash the request and redirect to challenge page.
	 *
	 * @param int                  $user_id      Current user ID.
	 * @param array<string, mixed> $matched_rule The rule that matched.
	 * @return void
	 */
	private function challenge_admin( int $user_id, array $matched_rule ): void {
		$stash_key = $this->stash->save( $user_id, $matched_rule );

		// Use the correct admin URL for the current context.
		$base_url = is_network_admin()
			? network_admin_url( 'admin.php' )
			: admin_url( 'admin.php' );

		// Build the return URL so the cancel button returns to the originating page.
		$return_url = wp_get_referer();
		if ( false === $return_url ) {
			$return_url = '';
		}

		$query_args = array(
			'page'      => 'wp-sudo-challenge',
			'stash_key' => $stash_key,
		);
		if ( $return_url ) {
			$query_args['return_url'] = $return_url;
		}

		$challenge_url = add_query_arg( $query_args, $base_url );

		if ( wp_safe_redirect( $challenge_url ) ) {
			exit;
		}

		wp_die(
			esc_html__( 'Unable to redirect to the sudo challenge page.', 'wp-sudo' ),
			'',
			array( 'response' => 403 )
		);
	}

	/**
	 * AJAX interception: return a JSON error.
	 *
	 * Also sets a short-lived transient so the admin notice fallback
	 * can alert the user on the next page load with a link to the
	 * challenge page for session activation.
	 *
	 * @param array<string, mixed> $matched_rule The rule that matched.
	 * @return void
	 */
	private function block_ajax( array $matched_rule ): void {
		$this->set_blocked_transient( $matched_rule );

		// phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__ -- UI hint only.
		$is_mac = isset( $_SERVER['HTTP_USER_AGENT'] )
			&& false !== strpos( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 'Mac' );
		// phpcs:enable WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__

		$shortcut = $is_mac ? 'Cmd+Shift+S' : 'Ctrl+Shift+S';

		$message = sprintf(
			/* translators: 1: action label, 2: keyboard shortcut */
			__( 'This action (%1$s) requires reauthentication. Press %2$s to start a sudo session, then try again.', 'wp-sudo' ),
			$matched_rule['label'] ?? $matched_rule['id'],
			$shortcut
		);

		/*
		 * Build a response compatible with WordPress core's wp.updates JS.
		 *
		 * 1. HTTP 200 with success=false — so wp.ajax.send() parses the
		 *    response through .done() → rejectWith( this, [response.data] ).
		 *    A non-200 status causes jQuery to route through .fail(), which
		 *    passes the raw jqXHR object and bypasses JSON parsing entirely.
		 *
		 * 2. Include slug/plugin from $_POST — wp.updates error handlers
		 *    (installThemeError, updatePluginError, etc.) use response.slug
		 *    to locate the DOM element and reset the button/spinner state.
		 *    Without slug, the handler can't find the button and the spinner
		 *    spins forever.
		 *
		 * 3. errorMessage is plain text, not HTML. wp.updates appends it
		 *    inside theme/plugin cards whose click handlers intercept anchor
		 *    clicks and navigate to the preview screen. The admin notice on
		 *    page reload (via set_blocked_transient) already provides a
		 *    clickable link to the challenge page.
		 */
		$data = array(
			'code'         => 'sudo_required',
			'message'      => $message,
			'rule_id'      => $matched_rule['id'],
			'errorCode'    => 'sudo_required',
			'errorMessage' => $message,
		);

		// Pass through slug/plugin so wp.updates can locate the DOM element.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only; nonce checked by wp.updates before dispatch. Values sanitized in helper methods below.
		if ( ! empty( $_POST['slug'] ) ) {
			$data['slug'] = self::sanitize_input_key( $_POST['slug'] );
		}
		if ( ! empty( $_POST['plugin'] ) ) {
			$data['plugin'] = self::sanitize_input_string( $_POST['plugin'] );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		wp_send_json_error( $data );
	}

	/**
	 * Store a short-lived transient so the admin notice fallback can
	 * alert the user on the next page load.
	 *
	 * @param array<string, mixed> $matched_rule The rule that was blocked.
	 * @return void
	 */
	private function set_blocked_transient( array $matched_rule ): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		set_transient(
			self::BLOCKED_TRANSIENT_PREFIX . $user_id,
			array(
				'rule_id' => $matched_rule['id'],
				'label'   => $matched_rule['label'] ?? $matched_rule['id'],
			),
			60 // 1 minute — enough for the next page load.
		);
	}

	/**
	 * Render a fallback admin notice when a gated AJAX/REST request was blocked.
	 *
	 * When the Gate blocks an AJAX or REST request with `sudo_required`,
	 * it sets a short-lived transient. On the next admin page load, this
	 * notice tells the user how to activate a sudo session manually via
	 * the challenge page or the keyboard shortcut.
	 *
	 * @return void
	 */
	public function render_blocked_notice(): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		// No fallback needed if sudo is already active.
		if ( Sudo_Session::is_active( $user_id ) ) {
			return;
		}

		$blocked = get_transient( self::BLOCKED_TRANSIENT_PREFIX . $user_id );

		if ( ! $blocked ) {
			return;
		}

		// Consume the transient — show only once.
		delete_transient( self::BLOCKED_TRANSIENT_PREFIX . $user_id );

		$label = is_array( $blocked ) && ! empty( $blocked['label'] )
			? $blocked['label']
			: __( 'a protected action', 'wp-sudo' );

		// phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__ -- UI hint only.
		$is_mac = isset( $_SERVER['HTTP_USER_AGENT'] )
			&& false !== strpos( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 'Mac' );
		// phpcs:enable WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__
		$shortcut = $is_mac ? 'Cmd+Shift+S' : 'Ctrl+Shift+S';

		$current_url = $this->get_current_admin_url();

		$query_args = array( 'page' => 'wp-sudo-challenge' );
		if ( $current_url ) {
			$query_args['return_url'] = $current_url;
		}

		$challenge_url = add_query_arg(
			$query_args,
			is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' )
		);

		printf(
			'<div class="notice notice-warning is-dismissible wp-sudo-notice" role="alert"><p>%s</p></div>',
			sprintf(
				/* translators: 1: action label, 2: challenge page link, 3: keyboard shortcut */
				esc_html__( 'Your recent action (%1$s) was blocked because it requires reauthentication. %2$s to activate a sudo session, then try again. You can also press %3$s.', 'wp-sudo' ),
				'<strong>' . esc_html( $label ) . '</strong>',
				'<a href="' . esc_url( $challenge_url ) . '">' . esc_html__( 'Confirm your identity', 'wp-sudo' ) . '</a>',
				'<kbd>' . esc_html( $shortcut ) . '</kbd>'
			)
		);
	}

	/**
	 * Render a persistent gate notice on gated admin pages.
	 *
	 * Unlike render_blocked_notice() (transient-based, one-time), this notice
	 * appears every time the user loads a gated page without an active sudo
	 * session. It replaces the need to click a button and fail first.
	 *
	 * @return void
	 */
	public function render_gate_notice(): void {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		if ( Sudo_Session::is_active( $user_id ) ) {
			return;
		}

		// Only show on gated pages.
		$gated_pages = array(
			'themes.php',
			'theme-install.php',
			'plugins.php',
			'plugin-install.php',
		);

		global $pagenow;

		if ( ! in_array( $pagenow, $gated_pages, true ) ) {
			return;
		}

		// phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__ -- UI hint only.
		$is_mac = isset( $_SERVER['HTTP_USER_AGENT'] )
			&& false !== strpos( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 'Mac' );
		// phpcs:enable WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__

		$shortcut = $is_mac ? 'Cmd+Shift+S' : 'Ctrl+Shift+S';

		$current_url = $this->get_current_admin_url();

		$query_args = array( 'page' => 'wp-sudo-challenge' );
		if ( $current_url ) {
			$query_args['return_url'] = $current_url;
		}

		$challenge_url = add_query_arg(
			$query_args,
			is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' )
		);

		printf(
			'<div class="notice notice-warning wp-sudo-notice" role="status"><p>%s</p></div>',
			sprintf(
				/* translators: 1: challenge page link, 2: keyboard shortcut */
				esc_html__( 'Installing, activating, updating, and deleting themes and plugins requires an active sudo session. %1$s or press %2$s to start one.', 'wp-sudo' ),
				'<a href="' . esc_url( $challenge_url ) . '">' . esc_html__( 'Confirm your identity', 'wp-sudo' ) . '</a>',
				'<kbd>' . esc_html( $shortcut ) . '</kbd>'
			)
		);
	}

	/**
	 * Filter plugin action links to disable gated actions.
	 *
	 * Replaces Activate, Deactivate, and Delete links with disabled
	 * span elements when no sudo session is active. Only runs on
	 * the plugins.php list table.
	 *
	 * @param string[] $actions     Action links for the plugin row.
	 * @param string   $plugin_file Plugin file path (unused; required by filter).
	 * @return string[]
	 */
	public function filter_plugin_action_links( array $actions, string $plugin_file ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return $actions;
		}

		if ( Sudo_Session::is_active( $user_id ) ) {
			return $actions;
		}

		$gated_keys = array( 'activate', 'deactivate', 'delete' );

		foreach ( $gated_keys as $key ) {
			if ( isset( $actions[ $key ] ) ) {
				/*
				 * Extract the visible link text and replace the anchor with a
				 * disabled span. wp_strip_all_tags() safely removes the <a> wrapper.
				 */
				$text            = wp_strip_all_tags( $actions[ $key ] );
				$actions[ $key ] = '<span class="wp-sudo-disabled" aria-disabled="true" style="color:#787c82;cursor:default">'
					. esc_html( $text )
					. '</span>';
			}
		}

		return $actions;
	}

	/**
	 * Filter theme action links to disable gated actions.
	 *
	 * Covers the old themes list-table (non-JS fallback) on themes.php.
	 *
	 * @param string[] $actions Action links for the theme row.
	 * @param object   $theme   WP_Theme instance (unused; required by filter).
	 * @return string[]
	 */
	public function filter_theme_action_links( array $actions, $theme ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return $actions;
		}

		if ( Sudo_Session::is_active( $user_id ) ) {
			return $actions;
		}

		$gated_keys = array( 'activate', 'delete' );

		foreach ( $gated_keys as $key ) {
			if ( isset( $actions[ $key ] ) ) {
				$text            = wp_strip_all_tags( $actions[ $key ] );
				$actions[ $key ] = '<span class="wp-sudo-disabled" aria-disabled="true" style="color:#787c82;cursor:default">'
					. esc_html( $text )
					. '</span>';
			}
		}

		return $actions;
	}

	/**
	 * Normalize a diagnostic surface value.
	 *
	 * @param mixed $surface Raw surface value.
	 * @return string
	 */
	private function normalize_diagnostic_surface( mixed $surface ): string {
		$surface = strtolower( self::sanitize_input_string( $surface ) );

		return in_array( $surface, array( 'admin', 'ajax', 'rest' ), true ) ? $surface : '';
	}

	/**
	 * Normalize a diagnostic REST auth mode.
	 *
	 * @param mixed $auth_mode Raw auth-mode value.
	 * @return string
	 */
	private function normalize_diagnostic_rest_auth_mode( mixed $auth_mode ): string {
		$auth_mode = strtolower( self::sanitize_input_string( $auth_mode ) );
		$valid     = array( 'cookie', 'application_password', 'bearer', 'none' );

		return in_array( $auth_mode, $valid, true ) ? $auth_mode : 'none';
	}

	/**
	 * Parse a representative URL for diagnostic matching.
	 *
	 * This method is used exclusively by the side-effect-free Rule Tester —
	 * it is not a security boundary. parse_url() is used directly because
	 * diagnostic URLs are developer-supplied representative shapes, not
	 * end-user input that needs sanitization or WordPress-compat wrapping.
	 *
	 * parse_str() is used for query parsing. It uses last-key-wins semantics
	 * for duplicate keys (e.g. `?foo=a&foo=b` → `foo = 'b'`). This is
	 * intentional and acceptable for tester purposes.
	 *
	 * @param string $url Representative URL.
	 * @return array{path:string,request_uri:string,pagenow:string,query_params:array<string,mixed>}
	 */
	private function parse_diagnostic_url( string $url ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Local representative URLs are fine here; no cross-version parsing edge cases affect the tester.
		$parts        = parse_url( $url );
		$path         = is_array( $parts ) && isset( $parts['path'] ) && is_string( $parts['path'] ) ? $parts['path'] : '';
		$query        = is_array( $parts ) && isset( $parts['query'] ) && is_string( $parts['query'] ) ? $parts['query'] : '';
		$request_uri  = $path;
		$query_params = array();

		if ( '' !== $query ) {
			parse_str( $query, $query_params );
			$request_uri .= '?' . $query;
		}

		$pagenow = basename( $path );

		return array(
			'path'         => $path,
			'request_uri'  => '' !== $request_uri ? $request_uri : '/',
			'pagenow'      => $pagenow,
			'query_params' => $query_params,
		);
	}

	/**
	 * Extract a REST route from parsed diagnostic URL data.
	 *
	 * @param array{path:string,request_uri:string,pagenow:string,query_params:array<string,mixed>} $parsed_url Parsed URL data.
	 * @return string
	 */
	private function extract_rest_route_from_parsed_url( array $parsed_url ): string {
		$query_params = $parsed_url['query_params'];
		$path         = $parsed_url['path'];

		if ( isset( $query_params['rest_route'] ) && is_string( $query_params['rest_route'] ) && '' !== $query_params['rest_route'] ) {
			$route = $query_params['rest_route'];
			return '/' === $route[0] ? $route : '/' . $route;
		}

		$wp_json_marker = '/wp-json/';
		$marker_pos     = strpos( $path, $wp_json_marker );

		if ( false !== $marker_pos ) {
			$route = substr( $path, $marker_pos + strlen( $wp_json_marker ) );
			return '/' . ltrim( $route, '/' );
		}

		return '/' . ltrim( $path, '/' );
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
	 * Sanitize a request value as a slug/key.
	 *
	 * @param mixed $value Raw request value.
	 * @return string
	 */
	private static function sanitize_input_key( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return sanitize_key( wp_unslash( $value ) );
	}

	/**
	 * Build the current admin URL from the incoming request host and URI.
	 *
	 * This preserves the actual network admin host on multisite subdomain installs.
	 *
	 * @return string
	 */
	private function get_current_admin_url(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$scheme = is_ssl() ? 'https' : 'http';
		$host   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw() sanitizes the full URL; sanitize_text_field() would corrupt encoded path/query segments.
		$request_uri = wp_unslash( $_SERVER['REQUEST_URI'] );

		if ( '' === $host ) {
			return '';
		}

		return esc_url_raw( $scheme . '://' . $host . $request_uri );
	}
}
