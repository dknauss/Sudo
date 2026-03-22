<?php
/**
 * Test-only 2FA bridge for Playwright challenge flows.
 *
 * Loaded as an MU plugin inside wp-env during CHAL-05.
 */

if ( ! class_exists( 'Two_Factor_Provider' ) ) {
	/**
	 * Minimal test double for the Two Factor provider base class.
	 */
	class Two_Factor_Provider {
		public function authentication_page( WP_User $user ): void {
		}

		public function pre_process_authentication( WP_User $user ): bool {
			return false;
		}

		public function validate_authentication( WP_User $user ): bool {
			return false;
		}
	}
}

if ( ! class_exists( 'WP_Sudo_E2E_Two_Factor_Provider' ) ) {
	/**
	 * Test-only provider that can emulate resend and validation flows.
	 */
	class WP_Sudo_E2E_Two_Factor_Provider extends Two_Factor_Provider {
		public function pre_process_authentication( WP_User $user ): bool {
			if ( '1' !== (string) get_user_meta( $user->ID, '_wp_sudo_e2e_two_factor_use_provider', true ) ) {
				return false;
			}

			$mode = '';
			if ( isset( $_POST['wp_sudo_e2e_two_factor_mode'] ) && is_string( $_POST['wp_sudo_e2e_two_factor_mode'] ) ) {
				$mode = sanitize_text_field( wp_unslash( $_POST['wp_sudo_e2e_two_factor_mode'] ) );
			}

			if ( 'resend' !== $mode ) {
				return false;
			}

			update_user_meta( $user->ID, '_wp_sudo_e2e_two_factor_last_provider_event', 'resent' );
			return true;
		}

		public function validate_authentication( WP_User $user ): bool {
			if ( '1' !== (string) get_user_meta( $user->ID, '_wp_sudo_e2e_two_factor_use_provider', true ) ) {
				return false;
			}

			$expected  = (string) get_user_meta( $user->ID, '_wp_sudo_e2e_two_factor_code', true );
			$submitted = '';

			if ( isset( $_POST['wp_sudo_e2e_two_factor_code'] ) && is_string( $_POST['wp_sudo_e2e_two_factor_code'] ) ) {
				$submitted = sanitize_text_field( wp_unslash( $_POST['wp_sudo_e2e_two_factor_code'] ) );
			}

			$valid = '' !== $expected && hash_equals( $expected, $submitted );

			if ( $valid ) {
				update_user_meta( $user->ID, '_wp_sudo_e2e_two_factor_last_provider_event', 'validated' );
			}

			return $valid;
		}
	}
}

if ( ! class_exists( 'Two_Factor_Core' ) ) {
	/**
	 * Minimal test double for the Two Factor plugin integration points WP Sudo uses.
	 */
	class Two_Factor_Core {
		public static function is_user_using_two_factor( int $user_id ): bool {
			return '1' === (string) get_user_meta( $user_id, '_wp_sudo_e2e_two_factor_use_provider', true );
		}

		public static function get_primary_provider_for_user( WP_User $user ): ?Two_Factor_Provider {
			if ( '1' !== (string) get_user_meta( $user->ID, '_wp_sudo_e2e_two_factor_use_provider', true ) ) {
				return null;
			}

			return new WP_Sudo_E2E_Two_Factor_Provider();
		}
	}
}

add_filter(
	'wp_sudo_requires_two_factor',
	static function ( bool $needs, int $user_id ): bool {
		if ( '1' === (string) get_user_meta( $user_id, '_wp_sudo_e2e_require_two_factor', true ) ) {
			return true;
		}

		return $needs;
	},
	10,
	2
);

add_action(
	'wp_sudo_render_two_factor_fields',
	static function ( WP_User $user ): void {
		if ( '1' !== (string) get_user_meta( $user->ID, '_wp_sudo_e2e_require_two_factor', true ) ) {
			return;
		}
		?>
		<?php if ( '1' === (string) get_user_meta( $user->ID, '_wp_sudo_e2e_two_factor_provider_hidden_fields', true ) ) : ?>
			<input type="hidden" name="action" value="e2e_provider_shadow_action" />
			<input type="hidden" name="_wpnonce" value="e2e-provider-shadow-nonce" />
		<?php endif; ?>
		<?php if ( '1' === (string) get_user_meta( $user->ID, '_wp_sudo_e2e_two_factor_use_provider', true ) ) : ?>
			<input
				type="hidden"
				id="wp-sudo-e2e-two-factor-mode"
				name="wp_sudo_e2e_two_factor_mode"
				value="verify"
			/>
		<?php endif; ?>
		<p>
			<label for="wp-sudo-e2e-two-factor-code">Authentication code</label><br />
			<input
				type="text"
				id="wp-sudo-e2e-two-factor-code"
				name="wp_sudo_e2e_two_factor_code"
				inputmode="numeric"
				autocomplete="one-time-code"
			/>
		</p>
		<?php
	},
	10,
	1
);

add_filter(
	'wp_sudo_validate_two_factor',
	static function ( bool $valid, WP_User $user ): bool {
		if ( $valid ) {
			return true;
		}

		if ( '1' !== (string) get_user_meta( $user->ID, '_wp_sudo_e2e_require_two_factor', true ) ) {
			return false;
		}

		$expected  = (string) get_user_meta( $user->ID, '_wp_sudo_e2e_two_factor_code', true );
		$submitted = '';

		if ( isset( $_POST['wp_sudo_e2e_two_factor_code'] ) && is_string( $_POST['wp_sudo_e2e_two_factor_code'] ) ) {
			$submitted = sanitize_text_field( wp_unslash( $_POST['wp_sudo_e2e_two_factor_code'] ) );
		}

		return '' !== $expected && hash_equals( $expected, $submitted );
	},
	10,
	2
);

add_action(
	'wp_ajax_wp_sudo_e2e_expire_two_factor',
	static function (): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		\WP_Sudo\Sudo_Session::clear_2fa_pending();
		wp_send_json_success();
	}
);
