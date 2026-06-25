<?php
/**
 * WP Sudo ↔ Two Factor (WordPress.org) Lifecycle Bridge
 *
 * Requires an active WP Sudo session before security-sensitive Two Factor
 * factor-MANAGEMENT operations over the Two Factor REST API. These operations
 * create or replace credentials that can satisfy a *later* WP Sudo 2FA
 * challenge, so a compromised session (with the password also known or phished)
 * must not be able to mint them:
 *
 *   - POST   /two-factor/1.0/generate-backup-codes  (new recovery codes)
 *   - POST   /two-factor/1.0/totp                   (set up / reconfigure TOTP)
 *   - DELETE /two-factor/1.0/totp                   (remove TOTP — a downgrade)
 *
 * Drop this file into wp-content/mu-plugins/.
 *
 * Requirements:
 *   - WP Sudo 4.1+
 *   - Two Factor by the WordPress.org Two Factor team
 *
 * Mechanism: the routes are registered as WP Sudo gated rules via the
 * `wp_sudo_gated_actions` filter, so WP Sudo's REST interceptor governs them
 * with its normal policy — a cookie-authenticated browser request is gated
 * (sudo required), and an Application Password request follows the operator's
 * REST App Password policy. No custom blocking logic lives here.
 *
 * Verified against WordPress/two-factor (master): routes are
 * `two-factor/1.0/totp` (POST + DELETE) and
 * `two-factor/1.0/generate-backup-codes` (POST); `user_id` is a request
 * parameter, not a path segment.
 *
 * KNOWN LIMITS (v1):
 *   - The classic profile-form provider toggle (`_two_factor_enabled_providers`
 *     / `_two_factor_provider`) and classic-form TOTP-key writes are NOT yet
 *     gated here — they need an effect-level guard with an idempotent,
 *     enrollment-excluding change predicate and are tracked as a follow-up.
 *   - A blocked request from the Two Factor *settings UI* (an `apiFetch`)
 *     receives a `sudo_required` JSON 403 it cannot yet recover from in place; a
 *     future in-editor/challenge-URL affordance will improve this.
 *   - Recovery-code generation via WP-CLI or a direct PHP call is not gated
 *     (governed instead by WP Sudo's non-interactive surface policy).
 *   - First-time enrollment is intentionally not blocked here — these rules fire
 *     on the management routes regardless, but a user with no prior factor can
 *     obtain a sudo session with their password alone (the challenge is
 *     password-only until a factor exists).
 *
 * @package    WP_Sudo_Bridges
 * @version    1.0.0
 * @license    GPL-2.0-or-later
 * @link       https://github.com/dknauss/Sudo
 */

defined( 'ABSPATH' ) || exit;

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

		return $rules;
	}
);
