<?php
/**
 * WP Sudo ↔ Two Factor (WordPress.org) Lifecycle Bridge
 *
 * Requires an active WP Sudo session before security-sensitive Two Factor
 * factor-management operations over the Two Factor REST API and the classic
 * profile provider form. These operations create, replace, or remove
 * credentials that can satisfy a *later* WP Sudo 2FA challenge, so a
 * compromised session (with the password also known or phished) must not be
 * able to mint or downgrade them:
 *
 *   - POST   /two-factor/1.0/generate-backup-codes  (new recovery codes)
 *   - POST   /two-factor/1.0/totp                   (set up / reconfigure TOTP)
 *   - DELETE /two-factor/1.0/totp                   (remove TOTP — a downgrade)
 *   - POST   profile.php / user-edit.php            (provider lifecycle changes)
 *
 * Drop this file into wp-content/mu-plugins/.
 *
 * Requirements:
 *   - WP Sudo 4.1+
 *   - Two Factor by the WordPress.org Two Factor team
 *
 * Mechanism: the routes/profile form are registered as WP Sudo gated rules via
 * the `wp_sudo_gated_actions` filter, so WP Sudo's normal request interceptor
 * governs them. Cookie-authenticated browser requests are gated (sudo required),
 * and Application Password REST requests follow the operator's REST App Password
 * policy. No custom blocking logic lives here.
 *
 * Verified against WordPress/two-factor master commit
 * c515462d51ac92941685e39293673c08538e16c8 (checked 2026-06-30):
 * routes are `two-factor/1.0/totp` (POST + DELETE) and
 * `two-factor/1.0/generate-backup-codes` (POST); `user_id` is a request
 * parameter, not a path segment. The classic profile contract uses
 * `profile.php` / `user-edit.php`, `action=update`, nonce field
 * `_nonce_user_two_factor_options`, nonce action `user_two_factor_options`,
 * enabled-provider field `_two_factor_enabled_providers[]`, primary-provider
 * field `_two_factor_provider`, and user meta `_two_factor_enabled_providers`,
 * `_two_factor_provider`, and `_two_factor_totp_key`.
 *
 * KNOWN LIMITS:
 *   - A blocked request from the Two Factor *settings UI* (an `apiFetch`)
 *     receives a `sudo_required` JSON 403 it cannot yet recover from in place; a
 *     future in-editor/challenge-URL affordance will improve this.
 *   - Recovery-code generation via WP-CLI or a direct PHP call is not gated
 *     (governed instead by WP Sudo's non-interactive surface policy).
 *   - Classic-form TOTP secret replacement is not inferred from unverified POST
 *     field names; this bridge gates the source-verified provider lifecycle
 *     changes that can create or alter a future sudo factor.
 *
 * @package    WP_Sudo_Bridges
 * @version    1.0.0
 * @license    GPL-2.0-or-later
 * @link       https://github.com/dknauss/Sudo
 */

defined( 'ABSPATH' ) || exit;

/** @psalm-suppress HookNotFound WP Sudo defines this integration hook when the bridge is loaded with the plugin. */
add_filter(
	'wp_sudo_gated_actions',
	/**
	 * Register the Two Factor factor-management REST routes as gated rules.
	 *
	 * @param array<int, array<string, mixed>> $rules Existing gated rules.
	 * @return array<int, array<string, mixed>>
	 */
	static function ( array $rules ): array {
		// Runtime integration check: only register when the Two Factor plugin is
		// actually present. mu-plugins load before regular plugins, so this is
		// evaluated when the filter runs (request time), not at file load.
		if ( ! class_exists( 'Two_Factor_Core' ) ) {
			return $rules;
		}

		$normalize_enabled_providers = static function ( $providers, array $supported_providers ): array {
			if ( ! is_array( $providers ) ) {
				return array();
			}

			$supported_keys = array_fill_keys( array_keys( $supported_providers ), true );
			$normalized    = array();

			foreach ( $providers as $provider ) {
				if ( ! is_scalar( $provider ) ) {
					continue;
				}

				$provider_key = sanitize_text_field( wp_unslash( (string) $provider ) );
				if ( '' === $provider_key || ! isset( $supported_keys[ $provider_key ] ) ) {
					continue;
				}

				$normalized[ $provider_key ] = true;
			}

			$provider_keys = array_keys( $normalized );
			sort( $provider_keys, SORT_STRING );

			return $provider_keys;
		};

		$normalize_primary_provider = static function ( $provider, array $enabled_providers ): string {
			if ( ! is_scalar( $provider ) ) {
				return '';
			}

			$provider_key = sanitize_text_field( wp_unslash( (string) $provider ) );
			if ( '' === $provider_key || ! in_array( $provider_key, $enabled_providers, true ) ) {
				return '';
			}

			return $provider_key;
		};

		$profile_provider_changed = static function () use ( $normalize_enabled_providers, $normalize_primary_provider ): bool {
			global $pagenow;

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- This predicate verifies the upstream Two Factor nonce before comparing lifecycle fields.
			if ( ! isset( $_POST['_nonce_user_two_factor_options'] ) || is_array( $_POST['_nonce_user_two_factor_options'] ) ) {
				return false;
			}

			$nonce = sanitize_text_field( wp_unslash( $_POST['_nonce_user_two_factor_options'] ) );
			if ( ! wp_verify_nonce( $nonce, 'user_two_factor_options' ) ) {
				return false;
			}

			if ( ! isset( $_POST['_two_factor_enabled_providers'] ) || ! is_array( $_POST['_two_factor_enabled_providers'] ) ) {
				return false;
			}

			if (
				is_callable( array( 'Two_Factor_Core', 'current_user_can_update_two_factor_options' ) )
				&& ! Two_Factor_Core::current_user_can_update_two_factor_options( 'save' )
			) {
				return false;
			}

			$current_pagenow = is_string( $pagenow ) ? $pagenow : '';
			if ( 'profile.php' === $current_pagenow ) {
				$target_user_id = absint( get_current_user_id() );
			} elseif ( 'user-edit.php' === $current_pagenow ) {
				if ( ! isset( $_POST['user_id'] ) || is_array( $_POST['user_id'] ) ) {
					return false;
				}

				$target_user_id = absint( wp_unslash( $_POST['user_id'] ) );
			} else {
				return false;
			}

			if ( $target_user_id <= 0 ) {
				return false;
			}

			if ( ! is_callable( array( 'Two_Factor_Core', 'get_supported_providers_for_user' ) ) ) {
				return false;
			}

			$supported_providers = Two_Factor_Core::get_supported_providers_for_user( $target_user_id );
			if ( ! is_array( $supported_providers ) ) {
				return false;
			}

			$current_enabled_raw = get_user_meta( $target_user_id, '_two_factor_enabled_providers', true );
			$current_enabled     = $normalize_enabled_providers( is_array( $current_enabled_raw ) ? $current_enabled_raw : array(), $supported_providers );
			$submitted_enabled   = $normalize_enabled_providers( $_POST['_two_factor_enabled_providers'], $supported_providers );

			$current_primary_raw   = get_user_meta( $target_user_id, '_two_factor_provider', true );
			$submitted_primary_raw = $_POST['_two_factor_provider'] ?? '';
			$current_primary       = $normalize_primary_provider( $current_primary_raw, $current_enabled );
			$submitted_primary     = $normalize_primary_provider( $submitted_primary_raw, $submitted_enabled );

			$totp_key     = get_user_meta( $target_user_id, '_two_factor_totp_key', true );
			$has_totp_key = is_scalar( $totp_key ) && '' !== (string) $totp_key;
			if (
				$has_totp_key
				&& in_array( 'Two_Factor_Totp', $current_enabled, true ) !== in_array( 'Two_Factor_Totp', $submitted_enabled, true )
			) {
				return true;
			}

			// phpcs:enable WordPress.Security.NonceVerification.Missing

			return $current_enabled !== $submitted_enabled || $current_primary !== $submitted_primary;
		};

		$rules[] = array(
			'id'       => 'two_factor.backup_codes_generate',
			'label'    => __( 'Generate Two Factor recovery codes', 'wp-sudo' ),
			'category' => 'users',
			'admin'    => null,
			'ajax'     => null,
			'rest'     => array(
				'route'   => '#^/two-factor/1\.0/generate-backup-codes$#',
				'methods' => array( 'POST' ),
			),
		);

		$rules[] = array(
			'id'       => 'two_factor.totp_manage',
			'label'    => __( 'Set up or remove Two Factor TOTP', 'wp-sudo' ),
			'category' => 'users',
			'admin'    => null,
			'ajax'     => null,
			'rest'     => array(
				'route'   => '#^/two-factor/1\.0/totp$#',
				'methods' => array( 'POST', 'DELETE' ),
			),
		);

		$rules[] = array(
			'id'       => 'two_factor.profile_provider_lifecycle',
			'label'    => __( 'Change Two Factor profile settings', 'wp-sudo' ),
			'category' => 'users',
			'admin'    => array(
				'pagenow'  => array( 'profile.php', 'user-edit.php' ),
				'actions'  => array( 'update' ),
				'method'   => 'POST',
				'callback' => $profile_provider_changed,
			),
			'ajax'     => null,
			'rest'     => null,
			'stash'    => array(
				'post_mode'   => 'allowlist',
				// Preserve the source-verified core profile fields used by
				// user-edit.php/edit_user() so a mixed profile + Two Factor
				// submission replays as a complete core profile save after the
				// WP Sudo challenge. Third-party profile fields are deliberately
				// outside this verified bridge allowlist.
				'post_fields' => array(
					'_wpnonce',
					'_wp_http_referer',
					'wp_http_referer',
					'option_page',
					'action',
					'action2',
					'submit',
					'from',
					'checkuser_id',
					'user_id',
					'user_login',
					'email',
					'nickname',
					'first_name',
					'last_name',
					'display_name',
					'url',
					'description',
					'locale',
					'rich_editing',
					'syntax_highlighting',
					'comment_shortcuts',
					'admin_bar_front',
					'admin_color',
					'color-nonce',
					'use_ssl',
					'role',
					'super_admin',
					'pass1',
					'pass2',
					'pass1-text',
					'pw_weak',
					'_nonce_user_two_factor_options',
					'_two_factor_enabled_providers',
					'_two_factor_provider',
				),
			),
		);

		return $rules;
	}
);
