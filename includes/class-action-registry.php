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
					'route'   => '#^/wp/v2/plugins/[^/]+$#',
					'methods' => array( 'PUT', 'PATCH' ),
				),
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
					'route'   => '#^/wp/v2/plugins/[^/]+$#',
					'methods' => array( 'PUT', 'PATCH' ),
				),
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
					'route'   => '#^/wp/v2/plugins/[^/]+$#',
					'methods' => array( 'DELETE' ),
				),
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
						$action = self::sanitize_request_string( $_REQUEST['action'] ?? '' );
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
					'route'    => '#^/wp/v2/users/\d+$#',
					'methods'  => array( 'PUT', 'PATCH' ),
					'callback' => function ( $request ): bool {
						$params = $request->get_params();
						return isset( $params['roles'] );
					},
				),
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
						$pass1 = isset( $_POST['pass1'] ) ? $_POST['pass1'] : '';
						// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
						$pass2 = isset( $_POST['pass2'] ) ? $_POST['pass2'] : '';
						return '' !== $pass1 || '' !== $pass2;
					},
				),
				'ajax'     => null,
				'rest'     => array(
					'route'    => '#^/wp/v2/users/(?:\\d+|me)$#',
					'methods'  => array( 'PUT', 'PATCH' ),
					'callback' => function ( $request ): bool {
						// Gate only when a password field is present in the request body.
						// /wp/v2/users/{id} also handles role changes (covered by user.promote),
						// so the callback isolates the password-change use case.
						return array_key_exists( 'password', $request->get_params() );
					},
				),
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
						$params   = $request->get_params();
						$critical = self::critical_option_names();
						foreach ( $critical as $opt ) {
							if ( array_key_exists( $opt, $params ) ) {
								return true;
							}
						}
						return false;
					},
				),
			),

			// ── WP Sudo Self-Protection ────────────────────────────────

				array(
					'id'       => 'options.wp_sudo',
					'label'    => __( 'Change WP Sudo settings', 'wp-sudo' ),
					'category' => 'options',
					'admin'    => array(
						'pagenow'  => 'options.php',
						'actions'  => array( 'update' ),
						'method'   => 'POST',
						'callback' => function (): bool {
							// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by WordPress before this callback runs; sanitized in helper.
							$option_page = self::sanitize_request_string( $_POST['option_page'] ?? '' );
							return 'wp-sudo-settings' === $option_page;
						},
					),
					'ajax'     => null,
					'rest'     => null,
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
				'label'    => __( 'Change WP Sudo settings', 'wp-sudo' ),
				'category' => 'options',
				'admin'    => array(
					'pagenow' => 'edit.php',
					'actions' => array( 'wp_sudo_settings' ),
					'method'  => 'POST',
				),
				'ajax'     => null,
				'rest'     => null,
			),
		);
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
		 *
		 * @since 2.0.0
		 *
		 * @param array $rules Array of gated action rules.
		 */
		$filtered_rules     = apply_filters( 'wp_sudo_gated_actions', self::rules() );
		self::$cached_rules = self::normalize_filtered_rules( $filtered_rules );

		return self::$cached_rules;
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
		self::$cached_rules = null;
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
	 * Sanitize a request value as a string.
	 *
	 * Request superglobals may contain arrays; return an empty string in that case.
	 *
	 * @param mixed $value Raw request value.
	 * @return string
	 */
	private static function sanitize_request_string( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $value ) );
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
				'default_role',
				'users_can_register',
			)
		);
	}
}
