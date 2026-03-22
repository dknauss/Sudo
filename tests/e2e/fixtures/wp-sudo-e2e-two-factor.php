<?php
/**
 * Test-only 2FA bridge for Playwright challenge flows.
 *
 * Loaded as an MU plugin inside wp-env during CHAL-05.
 */

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
