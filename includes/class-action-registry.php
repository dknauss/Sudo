<?php
/**
 * Registry of gated admin actions.
 *
 * Defines the dangerous operations that require sudo reauthentication
 * before execution. Each rule specifies matching criteria for admin UI,
 * AJAX, and REST API entry points.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Action_Registry
 *
 * Pure-data class with no hooks. Returns an array of rules that define
 * which operations are gated behind sudo reauthentication.
 *
 * Rules are filterable via `wp_sudo_gated_actions` so developers can
 * add, remove, or modify gated actions.
 *
 * @since 2.0.0
 */
class Action_Registry {

	/**
	 * Cached rules array (per-request).
	 *
	 * Prevents rebuilding the rules array (28+ arrays with closures
	 * and translation calls) on every get_rules() invocation. Reset
	 * via reset_cache() for testing.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $cached_rules = null;

	/**
	 * Cached set of built-in rule ids (id => true), per-request.
	 *
	 * Derived from the canonical built-in rule set, independent of the
	 * `wp_sudo_gated_actions` filter, so the Gate can tell whether a matched
	 * rule is authoritative (built-in) and therefore must fail closed on a
	 * malformed matcher. Reset via reset_cache() for testing.
	 *
	 * @var array<string, bool>|null
	 */
	private static ?array $cached_builtin_ids = null;

	/**
	 * Cached set of connector api_key setting names from the WP 7.0 registry,
	 * per-request. Null means "not yet built". An empty array means the registry
	 * exists but has no api_key connectors. These two states are distinct so that
	 * reset_cache() can force a re-read on the next evaluation.
	 *
	 * Reset via reset_cache() for unit-test isolation (a class property, NOT a
	 * function-local static, so reset_cache() can clear it between tests).
	 *
	 * @var array<string, bool>|null
	 */
	private static ?array $connector_setting_names_cache = null;

	/**
	 * Default WordPress form fields that are replay-safe when paired with a rule allowlist.
	 *
	 * @var string[]
	 */
	private const DEFAULT_REPLAY_POST_FIELDS = array(
		'_wpnonce',
		'_wp_http_referer',
		'option_page',
		'action',
		'action2',
		'submit',
	);

	/**
	 * Built-in gated action rules.
	 *
	 * Each rule is an associative array with:
	 *   - id       (string)     Unique identifier for logging/filtering.
	 *   - label    (string)     Human-readable description shown on challenge page.
	 *   - category (string)     Grouping key: plugins, themes, users, editors, options, multisite.
	 *   - admin    (array|null) Admin UI matching: {pagenow, actions, method}.
	 *   - ajax     (array|null) AJAX matching: {actions}.
	 *   - rest     (array|null) REST matching: {route, methods}.
	 *   - callback (callable|null) Optional extra condition.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function rules(): array {
		$rules = array(
			// ── Plugins ─────────────────────────────────────────────────

			array(
				'id'       => 'plugin.activate',
				'label'    => __( 'Activate plugin', 'wp-sudo' ),
				'category' => 'plugins',
				'admin'    => array(
					'pagenow' => 'plugins.php',
					'actions' => array( 'activate', 'activate-selected' ),
					'method'  => 'ANY',
				),
				'ajax'     => null,
				'rest'     => array(
					'route'   => '#^/wp/v2/plugins/[^/]+(?:/[^/]+)?$#',
					'methods' => array( 'PUT', 'PATCH' ),
				),
				'stash'    => self::stash_allowlist( array( 'plugin', 'checked', 'plugin_status', 'paged', 's' ) ),
			),

			array(
				'id'       => 'plugin.deactivate',
				'label'    => __( 'Deactivate plugin', 'wp-sudo' ),
				'category' => 'plugins',
				'admin'    => array(
					'pagenow' => 'plugins.php',
					'actions' => array( 'deactivate', 'deactivate-selected' ),
					'method'  => 'ANY',
				),
				'ajax'     => null,
				'rest'     => array(
					'route'   => '#^/wp/v2/plugins/[^/]+(?:/[^/]+)?$#',
					'methods' => array( 'PUT', 'PATCH' ),
				),
				'stash'    => self::stash_allowlist( array( 'plugin', 'checked', 'plugin_status', 'paged', 's' ) ),
			),

			array(
				'id'       => 'plugin.delete',
				'label'    => __( 'Delete plugin', 'wp-sudo' ),
				'category' => 'plugins',
				'admin'    => array(
					'pagenow' => 'plugins.php',
					'actions' => array( 'delete-selected' ),
					'method'  => 'POST',
				),
				'ajax'     => array(
					'actions' => array( 'delete-plugin' ),
				),
				'rest'     => array(
					'route'   => '#^/wp/v2/plugins/[^/]+(?:/[^/]+)?$#',
					'methods' => array( 'DELETE' ),
				),
				'stash'    => self::stash_allowlist( array( 'checked', 'plugin_status', 'paged', 's' ) ),
			),

			array(
				'id'       => 'plugin.install',
				'label'    => __( 'Install plugin', 'wp-sudo' ),
				'category' => 'plugins',
				'admin'    => array(
					'pagenow' => 'update.php',
					'actions' => array( 'install-plugin' ),
					'method'  => 'ANY',
				),
				'ajax'     => array(
					'actions' => array( 'install-plugin' ),
				),
				'rest'     => array(
					'route'   => '#^/wp/v2/plugins$#',
					'methods' => array( 'POST' ),
				),
				'stash'    => self::stash_allowlist( array( 'plugin' ) ),
			),

			array(
				'id'       => 'plugin.upload',
				'label'    => __( 'Upload plugin', 'wp-sudo' ),
				'category' => 'plugins',
				'admin'    => array(
					'pagenow' => 'update.php',
					'actions' => array( 'upload-plugin' ),
					'method'  => 'POST',
				),
				'ajax'     => null,
				'rest'     => null,
				'stash'    => self::stash_no_replay(),
			),

			array(
				'id'       => 'plugin.update',
				'label'    => __( 'Update plugin', 'wp-sudo' ),
				'category' => 'plugins',
				'admin'    => array(
					'pagenow' => array( 'update.php', 'plugins.php' ),
					'actions' => array( 'upgrade-plugin', 'update-selected' ),
					'method'  => 'ANY',
				),
				'ajax'     => array(
					'actions' => array( 'update-plugin' ),
				),
				'rest'     => null,
				'stash'    => self::stash_allowlist( array( 'plugin', 'checked', 'plugins', 'plugin_status', 'paged', 's' ) ),
			),

			// ── Themes ──────────────────────────────────────────────────

			array(
				'id'       => 'theme.switch',
				'label'    => __( 'Switch theme', 'wp-sudo' ),
				'category' => 'themes',
				'admin'    => array(
					'pagenow' => 'themes.php',
					'actions' => array( 'activate' ),
					'method'  => 'GET',
				),
				'ajax'     => null,
				'rest'     => null,
				'stash'    => self::stash_allowlist( array( 'checked' ) ),
			),

			array(
				'id'       => 'theme.delete',
				'label'    => __( 'Delete theme', 'wp-sudo' ),
				'category' => 'themes',
				'admin'    => array(
					'pagenow' => 'themes.php',
					'actions' => array( 'delete' ),
					'method'  => 'ANY',
				),
				'ajax'     => array(
					'actions' => array( 'delete-theme' ),
				),
				'rest'     => null,
				'stash'    => self::stash_allowlist( array( 'stylesheet' ) ),
			),

			array(
				'id'       => 'theme.install',
				'label'    => __( 'Install theme', 'wp-sudo' ),
				'category' => 'themes',
				'admin'    => array(
					'pagenow' => 'update.php',
					'actions' => array( 'install-theme' ),
					'method'  => 'ANY',
				),
				'ajax'     => array(
					'actions' => array( 'install-theme' ),
				),
				'rest'     => null,
				'stash'    => self::stash_allowlist( array( 'theme' ) ),
			),

			array(
				'id'       => 'theme.upload',
				'label'    => __( 'Upload theme', 'wp-sudo' ),
				'category' => 'themes',
				'admin'    => array(
					'pagenow' => 'update.php',
					'actions' => array( 'upload-theme' ),
					'method'  => 'POST',
				),
				'ajax'     => null,
				'rest'     => null,
				'stash'    => self::stash_no_replay(),
			),

			array(
				'id'       => 'theme.update',
				'label'    => __( 'Update theme', 'wp-sudo' ),
				'category' => 'themes',
				'admin'    => array(
					'pagenow' => array( 'update.php', 'themes.php' ),
					'actions' => array( 'upgrade-theme' ),
					'method'  => 'ANY',
				),
				'ajax'     => array(
					'actions' => array( 'update-theme' ),
				),
				'rest'     => null,
				'stash'    => self::stash_allowlist( array( 'theme', 'checked', 'themes' ) ),
			),

			// ── Users ───────────────────────────────────────────────────

			array(
				'id'       => 'user.delete',
				'label'    => __( 'Delete user', 'wp-sudo' ),
				'category' => 'users',
				'admin'    => array(
					'pagenow' => 'users.php',
					'actions' => array( 'delete', 'dodelete' ),
					'method'  => 'POST',
				),
				'ajax'     => null,
				'rest'     => array(
					'route'   => '#^/wp/v2/users/\d+$#',
					'methods' => array( 'DELETE' ),
				),
				'stash'    => self::stash_allowlist( array( 'users', 'delete_option', 'reassign_user' ) ),
			),

			array(
				'id'       => 'user.promote',
				'label'    => __( 'Change user role', 'wp-sudo' ),
				'category' => 'users',
				'admin'    => array(
					'pagenow'  => 'users.php',
					'actions'  => array( 'promote', '-1' ),
					'method'   => 'ANY',
					'callback' => function (): bool {
						// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Gate routing only; sanitized in helper.
						$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';
						if ( 'promote' === $action ) {
							return true;
						}
						// "Change role to…" dropdown: WordPress sends changeit + new_role instead of action=promote.
						// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Gate routing, not data processing.
						return isset( $_REQUEST['changeit'] ) && isset( $_REQUEST['new_role'] );
					},
				),
				'ajax'     => null,
				'rest'     => array(
					// `/me` too: core registers /wp/v2/users/me as EDITABLE →
					// update_current_item() → update_item(), so a role change via
					// POST /wp/v2/users/me would otherwise slip a numeric-only regex.
					'route'    => '#^/wp/v2/users/(?:\d+|me)$#',
					// Core registers the users update route under WP_REST_Server::EDITABLE
					// ('POST, PUT, PATCH'); POST must be gated too or a stolen cookie can
					// change a user's role via POST /wp/v2/users/{id|me} ungated.
					'methods'  => array( 'POST', 'PUT', 'PATCH' ),
					'callback' => function ( $request ): bool {
						$params = $request->get_params();
						return isset( $params['roles'] );
					},
				),
				'stash'    => self::stash_allowlist( array( 'users', 'new_role', 'changeit' ) ),
			),

			array(
				'id'       => 'user.promote_profile',
				'label'    => __( 'Change user role', 'wp-sudo' ),
				'category' => 'users',
				'admin'    => array(
					'pagenow'  => 'user-edit.php',
					'actions'  => array( 'update' ),
					'method'   => 'POST',
					'callback' => function (): bool {
						// Only gate when a role change is submitted (the form also handles other profile fields).
						// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Gate routing, not data processing.
						return isset( $_POST['role'] ) && '' !== $_POST['role'];
					},
				),
				'ajax'     => null,
				'rest'     => null,
				// Gated profile saves are non-replayable: after reauth the user
				// re-submits the form. This is the honest, silent-drop-free choice —
				// the profile form always submits empty pass1/pass2, which the stash
				// redacts, so an allowlist could never losslessly auto-replay anyway.
				'stash'    => self::stash_no_replay(),
			),

			array(
				'id'       => 'user.change_password',
				'label'    => __( 'Change password', 'wp-sudo' ),
				'category' => 'users',
				'admin'    => array(
					'pagenow'  => array( 'profile.php', 'user-edit.php' ),
					'actions'  => array( 'update' ),
					'method'   => 'POST',
					'callback' => function (): bool {
						// Only gate when a new password is being set.
						// profile.php and user-edit.php both use action=update for ALL profile
						// changes (bio, email, role, etc.) so the callback narrows to password changes.
						// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Gate routing: checking presence only, value not used as data.
						$pass1 = isset( $_POST['pass1'] ) ? wp_unslash( $_POST['pass1'] ) : '';
						// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
						$pass2 = isset( $_POST['pass2'] ) ? wp_unslash( $_POST['pass2'] ) : '';
						return '' !== $pass1 || '' !== $pass2;
					},
				),
				'ajax'     => null,
				'rest'     => array(
					'route'    => '#^/wp/v2/users/(?:\\d+|me)$#',
					// Core registers the users update route under WP_REST_Server::EDITABLE
					// ('POST, PUT, PATCH'); POST must be gated too or a stolen cookie can
					// change a user's password via POST /wp/v2/users/{id|me} ungated.
					'methods'  => array( 'POST', 'PUT', 'PATCH' ),
					'callback' => function ( $request ): bool {
						// Gate only when a password field is present in the request body.
						// /wp/v2/users/{id} also handles role changes (covered by user.promote),
						// so the callback isolates the password-change use case.
						return array_key_exists( 'password', $request->get_params() );
					},
				),
				// Non-replayable: a password change is already blocked from replay by
				// sensitive-field redaction, so this is the equivalent explicit choice.
				'stash'    => self::stash_no_replay(),
			),

			array(
				'id'       => 'user.change_email',
				'label'    => __( 'Change email address', 'wp-sudo' ),
				'category' => 'users',
				'admin'    => array(
					'pagenow'  => array( 'profile.php', 'user-edit.php' ),
					'actions'  => array( 'update' ),
					'method'   => 'POST',
					'callback' => function (): bool {
						// The email field is always present on a profile save, so gate
						// only a real change. Target: user-edit.php carries user_id;
						// profile.php edits the current user.
						// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Gate routing; core verifies its nonce before this runs.
						if ( ! isset( $_POST['email'] ) || ! is_string( $_POST['email'] ) ) {
							return false;
						}
						// Resolve the edited user exactly as core does: profile.php always
						// edits the current user (IS_PROFILE_PAGE) regardless of any user_id
						// param; user-edit.php uses $_REQUEST['user_id'] (query or body).
						// Reading only $_POST['user_id'] would desync from a crafted
						// user-edit.php?user_id=<victim> whose id is absent from the body.
						$is_profile = isset( $GLOBALS['pagenow'] ) && 'profile.php' === $GLOBALS['pagenow'];
						// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Gate routing; core verifies its nonce first.
						$req_user   = ( ! $is_profile && isset( $_REQUEST['user_id'] ) && is_numeric( $_REQUEST['user_id'] ) ) ? (int) $_REQUEST['user_id'] : 0;
						$target     = $req_user > 0 ? $req_user : (int) get_current_user_id();
						// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalized in helper.
						return self::email_change_differs( (string) wp_unslash( $_POST['email'] ), $target );
					},
				),
				'ajax'     => null,
				'rest'     => array(
					'route'    => '#^/wp/v2/users/(?:\\d+|me)$#',
					// Core registers the users update route under WP_REST_Server::EDITABLE
					// ('POST, PUT, PATCH'); POST must be gated too or it bypasses the rule.
					'methods'  => array( 'POST', 'PUT', 'PATCH' ),
					'callback' => function ( $request ): bool {
						$params = $request->get_params();
						if ( ! array_key_exists( 'email', $params ) || ! is_string( $params['email'] ) ) {
							return false;
						}
						$route  = (string) $request->get_route();
						$target = preg_match( '#/wp/v2/users/(\\d+)$#', $route, $m )
							? (int) $m[1]
							: (int) get_current_user_id();
						return self::email_change_differs( (string) $params['email'], $target );
					},
				),
				// Non-replayable: reauth, then re-submit (see the profile rules above).
				'stash'    => self::stash_no_replay(),
			),

			array(
				'id'       => 'user.create',
				'label'    => __( 'Create new user', 'wp-sudo' ),
				'category' => 'users',
				'admin'    => array(
					'pagenow' => 'user-new.php',
					'actions' => array( 'createuser', 'adduser' ),
					'method'  => 'POST',
				),
				'ajax'     => null,
				'rest'     => array(
					'route'   => '#^/wp/v2/users$#',
					'methods' => array( 'POST' ),
				),
				'stash'    => self::stash_allowlist( array( 'user_login', 'email', 'first_name', 'last_name', 'url', 'role', 'locale', 'send_user_notification', 'noconfirmation', 'pass1', 'pass2', 'pass1-text', 'pw_weak' ) ),
			),

			array(
				'id'       => 'auth.app_password',
				'label'    => __( 'Create application password', 'wp-sudo' ),
				'category' => 'users',
				'admin'    => array(
					'pagenow'  => 'authorize-application.php',
					'actions'  => array( 'authorize_application_password' ),
					'method'   => 'POST',
					'callback' => function (): bool {
						// Only gate approval, not rejection.
						// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Gate routing, not data processing.
						return isset( $_POST['approve'] );
					},
				),
				'ajax'     => null,
				'rest'     => array(
					'route'   => '#^/wp/v2/users/(?:\d+|me)/application-passwords$#',
					'methods' => array( 'POST' ),
				),
				'stash'    => self::stash_allowlist( array( 'approve', 'app_name', 'app_id', 'success_url', 'reject_url' ) ),
			),

			// ── File Editors ────────────────────────────────────────────

			array(
				'id'       => 'editor.plugin',
				'label'    => __( 'Edit plugin file', 'wp-sudo' ),
				'category' => 'editors',
				'admin'    => array(
					'pagenow' => 'plugin-editor.php',
					'actions' => array( 'update' ),
					'method'  => 'POST',
				),
				'ajax'     => array(
					'actions' => array( 'edit-theme-plugin-file' ),
				),
				'rest'     => null,
				'stash'    => self::stash_no_replay(),
			),

			array(
				'id'       => 'editor.theme',
				'label'    => __( 'Edit theme file', 'wp-sudo' ),
				'category' => 'editors',
				'admin'    => array(
					'pagenow' => 'theme-editor.php',
					'actions' => array( 'update' ),
					'method'  => 'POST',
				),
				'ajax'     => array(
					'actions' => array( 'edit-theme-plugin-file' ),
				),
				'rest'     => null,
				'stash'    => self::stash_no_replay(),
			),

			// ── Critical Options ────────────────────────────────────────

			array(
				'id'       => 'options.critical',
				'label'    => __( 'Change critical site setting', 'wp-sudo' ),
				'category' => 'options',
				'admin'    => array(
					'pagenow'  => array( 'options.php', 'options-general.php' ),
					'actions'  => array( 'update' ),
					'method'   => 'POST',
					'callback' => function (): bool {
						$critical = self::critical_option_names();
						foreach ( $critical as $opt ) {
							// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WordPress before this callback runs.
							if ( isset( $_POST[ $opt ] ) ) {
								return true;
							}
						}
						return false;
					},
				),
				'ajax'     => null,
				'rest'     => array(
					'route'    => '#^/wp/v2/settings$#',
					'methods'  => array( 'PUT', 'PATCH', 'POST' ),
					'callback' => function ( $request ): bool {
						$params = $request->get_params();
						// Core keys /wp/v2/settings by each setting's show_in_rest name,
						// not the raw option name — so siteurl arrives as 'url' and
						// admin_email as 'email'. Match both the raw names (harmless;
						// future-proof) and those REST aliases, or a stolen cookie could
						// repoint siteurl via POST /wp/v2/settings {"url":"..."} ungated.
						$keys = array_merge( self::critical_option_names(), self::critical_option_rest_keys() );
						foreach ( $keys as $key ) {
							if ( array_key_exists( $key, $params ) ) {
								return true;
							}
						}
						return false;
					},
				),
				'stash'    => self::stash_allowlist( self::critical_option_names() ),
			),

			array(
				'id'       => 'connectors.update_credentials',
				'label'    => __( 'Update connector credentials', 'wp-sudo' ),
				'category' => 'options',
				'admin'    => null,
				'ajax'     => null,
				'rest'     => array(
					'route'    => '#^/wp/v2/settings$#',
					'methods'  => array( 'PUT', 'PATCH', 'POST' ),
					'callback' => function ( $request ): bool {
						return self::request_contains_connector_api_key( $request->get_params() );
					},
				),
			),

			// ── WP Sudo Self-Protection ────────────────────────────────

				array(
					'id'       => 'options.wp_sudo',
					'label'    => __( 'Change Sudo settings', 'wp-sudo' ),
					'category' => 'options',
					'admin'    => array(
						'pagenow'  => 'options.php',
						'actions'  => array( 'update' ),
						'method'   => 'POST',
						'callback' => function (): bool {
							// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by WordPress before this callback runs; sanitized in helper.
							$option_page = isset( $_POST['option_page'] ) && is_string( $_POST['option_page'] ) ? sanitize_text_field( wp_unslash( $_POST['option_page'] ) ) : '';
							return 'wp-sudo-settings' === $option_page;
						},
					),
					'ajax'     => null,
					'rest'     => null,
					'stash'    => self::stash_allowlist( array( Admin::OPTION_KEY ) ),
				),

			array(
				'id'       => 'options.wp_sudo_access',
				'label'    => __( 'Change Sudo access controls', 'wp-sudo' ),
				'category' => 'options',
				'admin'    => array(
					'pagenow'  => 'options.php',
					'actions'  => array( 'update' ),
					'method'   => 'POST',
					'callback' => function (): bool {
						// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by WordPress before this callback runs; sanitized in helper.
						$option_page = isset( $_POST['option_page'] ) && is_string( $_POST['option_page'] ) ? sanitize_text_field( wp_unslash( $_POST['option_page'] ) ) : '';
						return 'wp-sudo-access' === $option_page;
					},
				),
				'ajax'     => array( 'actions' => array( 'wp_sudo_grant_cap', 'wp_sudo_revoke_cap' ) ),
				'rest'     => null,
				'stash'    => self::stash_allowlist( array( Admin::OPTION_KEY ) ),
			),

			// ── Core Updates ────────────────────────────────────────────

			array(
				'id'       => 'core.update',
				'label'    => __( 'Update WordPress core', 'wp-sudo' ),
				'category' => 'updates',
				'admin'    => array(
					'pagenow' => 'update-core.php',
					'actions' => array( 'do-core-upgrade', 'do-core-reinstall' ),
					'method'  => 'POST',
				),
				'ajax'     => null,
				'rest'     => null,
				'stash'    => self::stash_allowlist( array( 'version', 'locale' ) ),
			),

			// ── Tools ───────────────────────────────────────────────────

			array(
				'id'       => 'tools.export',
				'label'    => __( 'Export site data', 'wp-sudo' ),
				'category' => 'tools',
				'admin'    => array(
					'pagenow'  => 'export.php',
					'actions'  => array( '' ),
					'method'   => 'GET',
					'callback' => function (): bool {
						// Only gate when the download parameter triggers WXR generation.
						// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- Gate routing, not data processing.
						return isset( $_GET['download'] );
					},
				),
				'ajax'     => null,
				'rest'     => null,
				'stash'    => self::stash_allowlist( array( 'checked' ) ),
			),
		);

		if ( is_multisite() ) {
			$rules = array_merge( $rules, self::network_rules() );
		}

		return $rules;
	}

	/**
	 * Network admin rules — registered only on multisite installs.
	 *
	 * These cover operations that only exist in the network admin context:
	 * theme enable/disable, site management, super admin grants, and
	 * network-wide settings changes.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function network_rules(): array {
		return array(
			array(
				'id'       => 'network.theme_enable',
				'label'    => __( 'Network enable theme', 'wp-sudo' ),
				'category' => 'themes',
				'admin'    => array(
					'pagenow'  => 'themes.php',
					'actions'  => array( 'enable', 'enable-selected' ),
					'method'   => 'ANY',
					'callback' => function (): bool {
						return is_network_admin();
					},
				),
				'ajax'     => null,
				'rest'     => null,
				'stash'    => self::stash_allowlist( array( 'user_id', 'super_admin', 'noconfirmation' ) ),
			),

			array(
				'id'       => 'network.theme_disable',
				'label'    => __( 'Network disable theme', 'wp-sudo' ),
				'category' => 'themes',
				'admin'    => array(
					'pagenow'  => 'themes.php',
					'actions'  => array( 'disable', 'disable-selected' ),
					'method'   => 'ANY',
					'callback' => function (): bool {
						return is_network_admin();
					},
				),
				'ajax'     => null,
				'rest'     => null,
				'stash'    => self::stash_no_replay(),
			),

			array(
				'id'       => 'network.site_delete',
				'label'    => __( 'Delete site', 'wp-sudo' ),
				'category' => 'sites',
				'admin'    => array(
					'pagenow' => 'sites.php',
					'actions' => array( 'deleteblog' ),
					'method'  => 'GET',
				),
				'ajax'     => null,
				'rest'     => null,
			),

			array(
				'id'       => 'network.site_deactivate',
				'label'    => __( 'Deactivate site', 'wp-sudo' ),
				'category' => 'sites',
				'admin'    => array(
					'pagenow' => 'sites.php',
					'actions' => array( 'deactivateblog' ),
					'method'  => 'GET',
				),
				'ajax'     => null,
				'rest'     => null,
			),

			array(
				'id'       => 'network.site_spam',
				'label'    => __( 'Mark site as spam', 'wp-sudo' ),
				'category' => 'sites',
				'admin'    => array(
					'pagenow' => 'sites.php',
					'actions' => array( 'spamblog' ),
					'method'  => 'GET',
				),
				'ajax'     => null,
				'rest'     => null,
			),

			array(
				'id'       => 'network.site_archive',
				'label'    => __( 'Archive site', 'wp-sudo' ),
				'category' => 'sites',
				'admin'    => array(
					'pagenow' => 'sites.php',
					'actions' => array( 'archiveblog' ),
					'method'  => 'GET',
				),
				'ajax'     => null,
				'rest'     => null,
			),

			array(
				'id'       => 'network.super_admin',
				'label'    => __( 'Grant or revoke super admin', 'wp-sudo' ),
				'category' => 'users',
				'admin'    => array(
					'pagenow'  => 'user-edit.php',
					'actions'  => array( 'update' ),
					'method'   => 'POST',
					'callback' => function (): bool {
						if ( ! is_network_admin() ) {
							return false;
						}
						// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Gate routing, not data processing.
						return isset( $_POST['super_admin'] ) || isset( $_POST['noconfirmation'] );
					},
				),
				'ajax'     => null,
				'rest'     => null,
			),

			array(
				'id'       => 'network.settings',
				'label'    => __( 'Change network settings', 'wp-sudo' ),
				'category' => 'options',
				'admin'    => array(
					'pagenow'  => 'settings.php',
					'actions'  => array( '' ),
					'method'   => 'POST',
					'callback' => function (): bool {
						return is_network_admin();
					},
				),
				'ajax'     => null,
				'rest'     => null,
			),

			// Network admin forms POST to edit.php?action={slug} — the
			// standard WordPress pattern for custom network admin settings
			// pages. The single-site options.wp_sudo rule only matches
			// pagenow=options.php, which never fires on multisite where
			// $pagenow=edit.php.
			array(
				'id'       => 'options.wp_sudo',
				'label'    => __( 'Change Sudo settings', 'wp-sudo' ),
				'category' => 'options',
				'admin'    => array(
					'pagenow' => 'edit.php',
					'actions' => array( 'wp_sudo_settings' ),
					'method'  => 'POST',
				),
				'ajax'     => null,
				'rest'     => null,
				'stash'    => self::stash_allowlist( array( Admin::OPTION_KEY ) ),
			),
		);
	}

	/**
	 * Build allowlist metadata for POST replay-safe fields.
	 *
	 * @param string[] $fields Rule-specific top-level POST field names.
	 * @return array{post_mode:string, post_fields:string[]}
	 */
	private static function stash_allowlist( array $fields ): array {
		return array(
			'post_mode'   => 'allowlist',
			'post_fields' => array_values( array_unique( array_merge( self::DEFAULT_REPLAY_POST_FIELDS, $fields ) ) ),
		);
	}

	/**
	 * Build metadata for actions whose POST body should not be replayed.
	 *
	 * @return array{post_mode:string}
	 */
	private static function stash_no_replay(): array {
		return array(
			'post_mode' => 'none',
		);
	}

	/**
	 * Whether a submitted email differs from a target user's stored email.
	 *
	 * Backs the user.change_email rule. The email field is always present and
	 * pre-filled on a profile save, so gating on presence alone would challenge
	 * every save; this compares (sanitized, case-insensitive) against the stored
	 * address so only a real change is gated.
	 *
	 * Fails CLOSED: when an email IS submitted but the target user or their
	 * stored address cannot be read, returns true (gate) rather than assuming
	 * "no change". Returns false only when no usable email was submitted or it
	 * equals the stored address. Side-effect-free (reads user data only), so it
	 * is safe under the diagnostic request simulator.
	 *
	 * @param string $submitted_raw Raw submitted email (possibly slashed).
	 * @param int    $target_id     User whose stored email to compare against.
	 * @return bool
	 */
	private static function email_change_differs( string $submitted_raw, int $target_id ): bool {
		$submitted = sanitize_email( $submitted_raw );
		if ( '' === $submitted ) {
			return false;
		}
		if ( $target_id <= 0 ) {
			return true;
		}
		$user = get_userdata( $target_id );
		if ( ! $user || ! isset( $user->user_email ) ) {
			return true;
		}
		return strtolower( $submitted ) !== strtolower( (string) $user->user_email );
	}

	/**
	 * Get the filtered list of gated action rules.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_rules(): array {
		if ( null !== self::$cached_rules ) {
			return self::$cached_rules;
		}

		/**
		 * Filter the list of gated actions that require sudo reauthentication.
		 *
		 * Developers can use this filter to add, remove, or modify gated actions.
		 * Malformed rules are silently dropped (fail-closed); see normalize_filtered_rules().
		 * If the filter returns a non-array, the built-in rule set is used as a fallback.
		 *
		 * Each rule must be an associative array with at minimum:
		 *   - `id`       (string) — unique machine key, e.g. 'vendor.action_name'
		 *   - `label`    (string) — human-readable label for UI display
		 *   - `category` (string) — rule category for grouping in the Gated Actions table
		 *
		 * And one or more surface sub-arrays (`null` for a surface this rule
		 * does not gate). Matchers read ONLY these nested keys:
		 *
		 *   `admin` => array(
		 *       'pagenow'  => string|string[],   // matched against $GLOBALS['pagenow']
		 *       'actions'  => string[],          // matched against $_GET/$_POST['action']
		 *       'method'   => string,            // 'POST' | 'GET' | 'ANY' (default ANY)
		 *       'callback' => callable(): bool,  // optional; final narrowing predicate
		 *   )
		 *   `ajax` => array(
		 *       'actions'  => string[],          // matched against the AJAX 'action'
		 *   )
		 *   `rest` => array(
		 *       'route'    => string,            // PCRE regex w/ delimiter, e.g. '#^/wp/v2/users/(?:\d+|me)$#'
		 *       'methods'  => string[],          // e.g. array( 'PUT', 'PATCH' )
		 *       'callback' => callable( WP_REST_Request $request ): bool, // optional narrowing
		 *   )
		 *   `stash` => array(...)                // optional; replay allowlist via self::stash_allowlist()
		 *
		 * There are no `action`, `page`, `post_type`, `rest_route`, `rest_method`,
		 * `rest_callback`, or `network` keys — a rule using those validates but
		 * never matches (gating silently fails open). See the built-in rules in
		 * self::rules() for working examples, or docs/developer-reference.md.
		 *
		 * @since 2.0.0
		 *
		 * @param array<int, array<string, mixed>> $rules Indexed array of gated action rule arrays.
		 * @return array<int, array<string, mixed>>
		 */
		$filtered_rules      = apply_filters( 'wp_sudo_gated_actions', self::rules() );
		self::$cached_rules  = self::normalize_filtered_rules( $filtered_rules );
		$missing_builtin_ids = self::get_missing_builtin_rule_ids_from_rules( self::$cached_rules );
		if ( ! empty( $missing_builtin_ids ) ) {
			/**
			 * Fires when filtered gated-action rules omit one or more built-in rules.
			 *
			 * Built-in rule removal remains supported for advanced integrations, but
			 * operators and audit bridges need a visible signal when the core
			 * protection set has been reduced.
			 *
			 * @since 3.1.5
			 *
			 * @param string[] $missing_builtin_ids Built-in rule IDs missing after filtering.
			 */
			do_action( 'wp_sudo_gated_actions_missing_builtin_rules', $missing_builtin_ids );
		}

		return self::$cached_rules;
	}

	/**
	 * Get built-in rule IDs that are missing from the filtered registry.
	 *
	 * @since 3.1.5
	 *
	 * @return string[] Missing built-in rule IDs.
	 */
	public static function get_missing_builtin_rule_ids(): array {
		return self::get_missing_builtin_rule_ids_from_rules( self::get_rules() );
	}

	/**
	 * Reset the rules cache.
	 *
	 * Primarily for use in tests that add or modify rules via the
	 * wp_sudo_gated_actions filter.
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$cached_rules                  = null;
		self::$cached_builtin_ids            = null;
		self::$connector_setting_names_cache = null;
	}

	/**
	 * Whether the given rule id belongs to the canonical built-in rule set.
	 *
	 * Used by the Gate to decide whether a malformed REST route pattern should
	 * fail closed (built-in rule — gate the request) or degrade gracefully
	 * (third-party rule). Independent of the `wp_sudo_gated_actions` filter so
	 * a filtered rule that reuses a built-in id is still treated as
	 * authoritative.
	 *
	 * @since 3.1.4
	 *
	 * @param string $id Rule id.
	 * @return bool
	 */
	public static function is_builtin_rule_id( string $id ): bool {
		if ( '' === $id ) {
			return false;
		}

		if ( null === self::$cached_builtin_ids ) {
			$ids = array();
			foreach ( self::rules() as $rule ) {
				if ( isset( $rule['id'] ) && is_string( $rule['id'] ) ) {
					$ids[ $rule['id'] ] = true;
				}
			}
			self::$cached_builtin_ids = $ids;
		}

		return isset( self::$cached_builtin_ids[ $id ] );
	}

	/**
	 * Normalize filtered rules into a matcher-safe shape.
	 *
	 * Fail-closed at the per-rule level: malformed entries are dropped.
	 * If the filter output is not an array, fall back to built-in rules.
	 *
	 * @param mixed $filtered_rules Filtered rule output.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_filtered_rules( mixed $filtered_rules ): array {
		if ( ! is_array( $filtered_rules ) ) {
			return self::rules();
		}

		$normalized = array();

		foreach ( $filtered_rules as $rule ) {
			if ( ! self::is_valid_rule( $rule ) ) {
				continue;
			}

			// Type-safe after is_valid_rule() check.
			$rule['id']       = (string) $rule['id'];
			$rule['label']    = (string) $rule['label'];
			$rule['category'] = (string) $rule['category'];
			$normalized[]     = $rule;
		}

		return $normalized;
	}

	/**
	 * Compare filtered rules against the canonical built-in rule IDs.
	 *
	 * @param array<int, array<string, mixed>> $rules Filtered, normalized rules.
	 * @return string[] Missing built-in rule IDs.
	 */
	private static function get_missing_builtin_rule_ids_from_rules( array $rules ): array {
		$builtin_ids = array();
		foreach ( self::rules() as $rule ) {
			if ( isset( $rule['id'] ) && is_string( $rule['id'] ) ) {
				$builtin_ids[] = $rule['id'];
			}
		}

		$current_ids = array();
		foreach ( $rules as $rule ) {
			if ( isset( $rule['id'] ) && is_string( $rule['id'] ) ) {
				$current_ids[] = $rule['id'];
			}
		}

		return array_values( array_diff( array_unique( $builtin_ids ), array_unique( $current_ids ) ) );
	}

	/**
	 * Check if a rule has the minimum schema required for safe matching.
	 *
	 * @param mixed $rule Rule candidate.
	 * @return bool
	 */
	private static function is_valid_rule( mixed $rule ): bool {
		if ( ! is_array( $rule ) ) {
			return false;
		}

		if ( ! self::has_required_scalar_metadata( $rule ) ) {
			return false;
		}

		return self::has_valid_surface_shapes( $rule );
	}

	/**
	 * Validate required scalar metadata keys on a rule.
	 *
	 * @param array<string, mixed> $rule Rule candidate.
	 * @return bool
	 */
	private static function has_required_scalar_metadata( array $rule ): bool {
		foreach ( array( 'id', 'label', 'category' ) as $key ) {
			if ( ! isset( $rule[ $key ] ) || ! is_scalar( $rule[ $key ] ) ) {
				return false;
			}

			if ( '' === trim( (string) $rule[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate that rule surfaces are array-or-null to prevent runtime errors.
	 *
	 * @param array<string, mixed> $rule Rule candidate.
	 * @return bool
	 */
	private static function has_valid_surface_shapes( array $rule ): bool {
		foreach ( array( 'admin', 'ajax', 'rest' ) as $surface ) {
			if ( ! array_key_exists( $surface, $rule ) ) {
				continue;
			}

			if ( null === $rule[ $surface ] ) {
				continue;
			}

			if ( ! is_array( $rule[ $surface ] ) ) {
				return false;
			}
		}

		return true;
	}


	/**
	 * Check whether REST settings params contain connector API key fields.
	 *
	 * @param array<mixed> $params Request parameters.
	 * @return bool
	 */
	private static function request_contains_connector_api_key( array $params ): bool {
		foreach ( array_keys( $params ) as $key ) {
			if ( is_string( $key ) && self::is_connector_api_key_setting_name( $key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a settings key is a connector API key setting name.
	 *
	 * Two-tier union matcher (fail-toward-gating): gates if EITHER tier matches.
	 *
	 * Tier 1 — WP 7.0 Connectors registry (when wp_get_connectors() exists):
	 *   Collects setting_name values from connectors whose authentication.method
	 *   is 'api_key'. Result is cached in $connector_setting_names_cache (a
	 *   class property, NOT a function-local static, so reset_cache() can clear
	 *   it between unit tests). This tier gates connectors with non-regex names
	 *   such as Akismet's 'wordpress_api_key' (registered unconditionally in
	 *   wp-includes/connectors.php as method=api_key, setting_name=wordpress_api_key;
	 *   verified against WordPress 7.0 GA, 2026-06-15).
	 *
	 * Tier 2 — Regex fallback (always runs):
	 *   Gates connectors_[a-z0-9_]+_api_key keys. Covers pre-WP-7.0 installs
	 *   and connectors registered with the default naming convention.
	 *
	 * Scope note: this matcher intentionally covers method === 'api_key' only.
	 * A future core authentication method that carries a secret is a known
	 * RE-SCOPING TRIGGER — integrators should extend via wp_sudo_gated_actions
	 * until this matcher is updated.
	 *
	 * The function_exists( 'wp_get_connectors' ) guard is a legitimate runtime
	 * integration check (WP 6.2 plugin minimum vs WP 7.0 feature), NOT a shim.
	 *
	 * @param string $key Settings field name.
	 * @return bool
	 */
	private static function is_connector_api_key_setting_name( string $key ): bool {
		// Tier 1: WP 7.0 Connectors registry (when available).
		$wp_get_connectors = 'wp_get_connectors';
		if ( function_exists( $wp_get_connectors ) ) {
			if ( null === self::$connector_setting_names_cache ) {
				$names      = array();
				$connectors = $wp_get_connectors();
				foreach ( $connectors as $connector ) {
					if (
						isset( $connector['authentication']['method'], $connector['authentication']['setting_name'] ) &&
						'api_key' === $connector['authentication']['method']
					) {
						$names[ $connector['authentication']['setting_name'] ] = true;
					}
				}
				self::$connector_setting_names_cache = $names;
			}

			if ( isset( self::$connector_setting_names_cache[ $key ] ) ) {
				return true;
			}
		}

		// Tier 2: Regex fallback — always evaluated (union, not early-exit).
		return 1 === preg_match( '#^connectors_[a-z0-9_]+_api_key$#', $key );
	}

	/**
	 * Get all unique categories from the registry.
	 *
	 * @return string[]
	 */
	public static function get_categories(): array {
		$categories = array();
		foreach ( self::get_rules() as $rule ) {
			if ( ! empty( $rule['category'] ) && ! in_array( $rule['category'], $categories, true ) ) {
				$categories[] = $rule['category'];
			}
		}
		return $categories;
	}

	/**
	 * Get rules filtered by category.
	 *
	 * @param string $category Category name.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_rules_by_category( string $category ): array {
		return array_values(
			array_filter(
				self::get_rules(),
				function ( $rule ) use ( $category ) {
					return ( $rule['category'] ?? '' ) === $category;
				}
			)
		);
	}

	/**
	 * Find a rule by ID.
	 *
	 * @param string $id Rule ID.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $id ): ?array {
		foreach ( self::get_rules() as $rule ) {
			if ( ( $rule['id'] ?? '' ) === $id ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Option names considered critical for the options.critical rule.
	 *
	 * @return string[]
	 */
	public static function critical_option_names(): array {
		/**
		 * Filter the list of option names considered critical.
		 *
		 * @since 2.0.0
		 *
		 * @param string[] $options Critical option names.
		 */
		return apply_filters(
			'wp_sudo_critical_options',
			array(
				'siteurl',
				'home',
				'admin_email',
				'new_admin_email',
				'default_role',
				'users_can_register',
			)
		);
	}

	/**
	 * REST parameter keys that map to critical options on /wp/v2/settings.
	 *
	 * WordPress keys the settings endpoint by each setting's `show_in_rest`
	 * name, which differs from the raw option name for the two single-site
	 * critical options core exposes there: `siteurl` → `url` and `admin_email`
	 * → `email` (`register_initial_settings()`). The other critical options
	 * (`home`, `new_admin_email`, `default_role`, `users_can_register`) are not
	 * registered on the settings endpoint, so only these two REST aliases can
	 * appear in a `/wp/v2/settings` body.
	 *
	 * @return string[]
	 */
	public static function critical_option_rest_keys(): array {
		// Derive the REST aliases from the FILTERED critical option list, so that
		// narrowing `wp_sudo_critical_options` ungates an option on the REST path
		// too — parity with the admin path (developer-reference.md: removing an
		// entry ungates that option). Only siteurl/admin_email have a differing
		// show_in_rest name; every other critical option keys by its raw name.
		$aliases = array(
			'siteurl'     => 'url',
			'admin_email' => 'email',
		);
		$keys    = array();
		foreach ( self::critical_option_names() as $opt ) {
			if ( isset( $aliases[ $opt ] ) ) {
				$keys[] = $aliases[ $opt ];
			}
		}
		return $keys;
	}
}
