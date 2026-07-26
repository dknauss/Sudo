<?php
/**
 * Test-only mu-plugin: guarantee a REST nonce on every admin screen.
 *
 * The REST-gate spec makes cookie-authenticated `/wp/v2/…` writes from the page
 * context, which need an `X-WP-Nonce`. Core localises `wpApiSettings.nonce` via the
 * `wp-api-request` handle, but only on screens where something already depends on
 * it — that is incidental, not a contract, so a spec that read it opportunistically
 * would pass or fail depending on which screen it happened to be on.
 *
 * Enqueueing the handle directly makes the nonce present on every admin screen, so
 * the spec's auth setup is deterministic. It grants nothing: `wp-api-request` is a
 * core script, and the nonce it prints is the same one core would print anyway.
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
