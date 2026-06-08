<?php
/**
 * Defense-in-depth authorization helper for uninstall cleanup.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uninstall_Guard
 *
 * @since 3.1.5
 */
final class Uninstall_Guard {

	/**
	 * Whether the current uninstall execution context is authorized.
	 *
	 * WordPress core already gates browser uninstalls behind capability and nonce
	 * checks. This helper is an additional local assertion that preserves WP-CLI
	 * uninstall behavior, where command authorization is owned by WP-CLI/core.
	 *
	 * @param bool|null $is_wp_cli Optional test override for WP-CLI detection.
	 * @return bool
	 */
	public static function is_authorized( ?bool $is_wp_cli = null ): bool {
		$is_wp_cli = null === $is_wp_cli
			? defined( 'WP_CLI' ) && WP_CLI
			: $is_wp_cli;

		if ( $is_wp_cli ) {
			return true;
		}

		if ( ! function_exists( 'current_user_can' ) ) {
			return false;
		}

		return current_user_can( 'delete_plugins' );
	}
}
