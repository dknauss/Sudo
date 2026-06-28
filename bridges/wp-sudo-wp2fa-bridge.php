<?php
/**
 * WP Sudo ↔ WP 2FA (Melapress) Bridge
 *
 * Connects WP 2FA's TOTP, email, and backup code methods to WP Sudo's
 * reauthentication challenge. Drop this file into wp-content/mu-plugins/.
 *
 * Requirements:
 *   - WP Sudo 2.0+
 *   - WP 2FA 3.0+ by Melapress
 *
 * @package    WP_Sudo_Bridges
 * @version    1.0.0
 * @license    GPL-2.0-or-later
 * @link       https://github.com/your-org/wp-sudo
 */

defined( 'ABSPATH' ) || exit;

/**
 * 1. DETECTION — Tell WP Sudo this user needs 2FA.
 *
 * Hooked to: wp_sudo_requires_two_factor
 * Checks WP 2FA's User_Helper to see if the user has an enabled 2FA method.
 */
add_filter(
	'wp_sudo_requires_two_factor',
	static function ( bool $needs, int $user_id ): bool {
		// Don't override if another plugin already claims 2FA.
		if ( $needs ) {
			return true;
		}

		if ( ! class_exists( '\WP2FA\Admin\Helpers\User_Helper' ) ) {
			return $needs;
		}

		return \WP2FA\Admin\Helpers\User_Helper::is_user_using_two_factor( $user_id );
	},
	10,
	2
);

/**
 * 2. RENDERING — Show the appropriate 2FA input on the challenge page.
 *
 * Hooked to: wp_sudo_render_two_factor_fields
 * Renders a code input. For email users, sends the OTP email.
 * For TOTP users, prompts for their authenticator code.
 * A backup code fallback input is always shown when backup codes are available.
 *
 * Rules enforced by WP Sudo:
 *   - No <form> wrapper (already inside one).
 *   - No submit button (WP Sudo provides "Confirm & Continue").
 *   - No 'action' or '_wpnonce' hidden fields (WP Sudo strips and replaces them).
 */
add_action(
	'wp_sudo_render_two_factor_fields',
	static function ( \WP_User $user ): void {
		if ( ! class_exists( '\WP2FA\Admin\Helpers\User_Helper' ) ) {
			return;
		}

		$method = \WP2FA\Admin\Helpers\User_Helper::get_enabled_method_for_user( $user );

		if ( empty( $method ) ) {
			return;
		}

		// Primary method input.
		if ( 'totp' === $method ) {
			?>
			<p>
				<label for="wp-sudo-wp2fa-code">
					<?php esc_html_e( 'Enter the code from your authenticator app:', 'wp-sudo' ); ?>
				</label><br />
				<input type="text"
					id="wp-sudo-wp2fa-code"
					name="wp2fa_authcode"
					class="regular-text"
					autocomplete="one-time-code"
					inputmode="numeric"
					pattern="[0-9]*"
					maxlength="6"
					required />
			</p>
			<?php
		} elseif ( 'email' === $method ) {
			// Generate and send the email OTP now.
			if ( class_exists( '\WP2FA\Authenticator\Authentication' ) ) {
				\WP2FA\Authenticator\Authentication::generate_token( $user->ID );
			}
			?>
			<p>
				<label for="wp-sudo-wp2fa-code">
					<?php esc_html_e( 'Enter the code sent to your email:', 'wp-sudo' ); ?>
				</label><br />
				<input type="text"
					id="wp-sudo-wp2fa-code"
					name="wp2fa_authcode"
					class="regular-text"
					autocomplete="one-time-code"
					inputmode="numeric"
					pattern="[0-9]*"
					maxlength="6"
					required />
			</p>
			<?php
		}

		// Backup code fallback — shown for any primary method.
		if ( class_exists( '\WP2FA\Methods\Backup_Codes' ) ) {
			$has_backup = get_user_meta( $user->ID, 'wp_2fa_backup_codes', true );
			if ( ! empty( $has_backup ) ) {
				?>
				<details class="wp-sudo-wp2fa-backup">
					<summary>
						<?php esc_html_e( 'Use a backup code instead', 'wp-sudo' ); ?>
					</summary>
					<p>
						<label for="wp-sudo-wp2fa-backup">
							<?php esc_html_e( 'Backup code:', 'wp-sudo' ); ?>
						</label><br />
						<input type="text"
							id="wp-sudo-wp2fa-backup"
							name="wp2fa_backup_code"
							class="regular-text"
							autocomplete="off" />
					</p>
				</details>
				<?php
			}
		}
	}
);

/**
 * 3. VALIDATION — Verify the submitted 2FA code.
 *
 * Hooked to: wp_sudo_validate_two_factor
 * Tries the primary method first, then falls back to backup codes.
 *
 * WP Sudo has already verified the nonce. We just need to read $_POST
 * and call the appropriate WP 2FA validation method.
 *
 * @psalm-suppress HookNotFound WP Sudo defines this integration hook when the bridge is loaded with the plugin.
 */
add_filter(
	'wp_sudo_validate_two_factor',
	static function ( bool $valid, \WP_User $user ): bool {
		// Don't override if another plugin already validated.
		if ( $valid ) {
			return true;
		}

		if ( ! class_exists( '\WP2FA\Admin\Helpers\User_Helper' ) ) {
			return $valid;
		}

		$method = \WP2FA\Admin\Helpers\User_Helper::get_enabled_method_for_user( $user );

		if ( empty( $method ) ) {
			return $valid;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WP Sudo verifies the challenge nonce before invoking this validation filter.
		$code = isset( $_POST['wp2fa_authcode'] )
			? sanitize_text_field( wp_unslash( $_POST['wp2fa_authcode'] ) )
			: '';

		$backup_code = isset( $_POST['wp2fa_backup_code'] )
			? sanitize_text_field( wp_unslash( $_POST['wp2fa_backup_code'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Try primary method.
		if ( ! empty( $code ) ) {
			if ( 'totp' === $method && class_exists( '\WP2FA\Authenticator\Authentication' ) && class_exists( '\WP2FA\Methods\TOTP' ) ) {
				$key = \WP2FA\Methods\TOTP::get_totp_key( $user );
				if ( $key && \WP2FA\Authenticator\Authentication::is_valid_authcode( $key, $code ) ) {
					return true;
				}
			} elseif ( 'email' === $method && class_exists( '\WP2FA\Authenticator\Authentication' ) ) {
				if ( \WP2FA\Authenticator\Authentication::validate_token( $user, $code ) ) {
					return true;
				}
			}
		}

		// Try backup code fallback.
		if ( ! empty( $backup_code ) && class_exists( '\WP2FA\Methods\Backup_Codes' ) ) {
			if ( \WP2FA\Methods\Backup_Codes::validate_code( $user, $backup_code ) ) {
				return true;
			}
		}

		return false;
	},
	10,
	2
);
