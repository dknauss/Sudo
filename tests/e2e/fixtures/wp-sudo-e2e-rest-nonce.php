<?php
/**
 *
 * @package WP_Sudo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'admin_enqueue_scripts',
	static function (): void {
		wp_enqueue_script( 'wp-api-request' );
	}
);

/**
 * Ensure the disposable probe target exists.
 *
 * Created here rather than with `wp user create` because user creation is a gated
 * action and the CLI surface cannot complete a sudo challenge — the plugin refuses
 * it, correctly. Relaxing the CLI policy is not a way around that either: changing
 * WP Sudo's own settings is itself gated on that same surface, so the gate cannot be
 * disarmed from the surface it protects. That is the design working, and a fixture
 * should not try to defeat it.
 *
 * This is setup rather than a bypass: it runs as plugin code at `init`, not as a
 * gated request, which is the same footing every other test fixture in this suite
 * stands on. Nothing here weakens a policy or leaves state behind for other specs.
 */
add_action(
	'init',
	static function (): void {
		// Only ever during a web request. WP Sudo's CLI policy maps the *function*,
		// not just the `wp user create` command, so calling wp_insert_user() while
		// `init` runs under WP-CLI is refused exactly as the command would be — and
		// would fail whatever CLI command happened to trigger it. Creating during a
		// normal admin page load sidesteps nothing: that surface completes challenges.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		if ( username_exists( 'wp_sudo_rest_gate_target' ) ) {
			return;
		}

		wp_insert_user(
			array(
				'user_login' => 'wp_sudo_rest_gate_target',
				'user_pass'  => wp_generate_password( 24, true, true ),
				'user_email' => 'rest-gate-target@example.com',
				'role'       => 'subscriber',
			)
		);
	}
);
