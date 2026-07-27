<?php
/**
 * Test-only mu-plugin: guarantee a REST nonce on every admin screen.
 *
 * The REST-gate spec makes cookie-authenticated `/wp/v2/…` writes from the page
 * context, which need an `X-WP-Nonce`. Enqueueing core's `wp-api-request` handle is
 * how this fixture obtains one; whether any given admin screen already enqueues it
 * is not something this fixture assumes or asserts. Doing it explicitly makes the
 * spec's auth setup deterministic rather than dependent on the screen it lands on.
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
