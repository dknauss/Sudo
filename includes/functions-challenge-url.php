<?php
/**
 * Challenge URL helper functions.
 *
 * Provides the centralized wp_sudo_build_challenge_url() helper used by every
 * surface that builds a link to the sudo challenge page carrying a
 * `return_url`. Nesting a full URL (which already contains its own `&`
 * -delimited query string) as a raw, un-pre-encoded array VALUE inside
 * add_query_arg()'s $args array is unsafe: WordPress core's add_query_arg()/
 * build_query() do NOT urlencode newly-added array values (only values
 * already present in the URL's existing query string get urlencode_deep()'d
 * on the way back out). The nested "&" therefore leaks out as a new
 * top-level query separator, truncating the return_url at the first "&"
 * once the browser round-trips the link through $_GET.
 *
 * This file is loaded unconditionally at plugin boot (wp-sudo.php) and in
 * the unit-test bootstrap, making wp_sudo_build_challenge_url() available
 * as a testable global function — mirroring functions-governance.php.
 *
 * @since      4.6.0
 * @package    WP_Sudo
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build a challenge-page URL, safely encoding a nested return_url.
 *
 * Encodes $return_url with rawurlencode() exactly once before handing it to
 * add_query_arg(), so a return_url that itself carries a query string (e.g.
 * "...options-general.php?page=wp-sudo-settings&tab=access") survives the
 * browser's query-string round trip intact. The read side is unchanged:
 * $_GET auto-decodes exactly once, then Challenge reads it via
 * esc_url_raw( wp_unslash( $_GET['return_url'] ) ) followed by
 * wp_validate_redirect() (see class-challenge.php).
 *
 * This helper intentionally does NOT call wp_validate_redirect() at build
 * time — that is a read-side concern. Validating here could pre-strip a
 * legitimate different-host referer before the value is even encoded.
 *
 * @since 4.6.0
 *
 * @param string               $base       Base admin URL (e.g. admin_url( 'admin.php' )).
 * @param string               $return_url Optional return URL. Encoded once via rawurlencode()
 *                                         when non-empty; omitted from the args entirely when empty.
 * @param array<string, mixed> $extra      Additional query args (e.g. 'page', 'stash_key').
 * @return string
 */
function wp_sudo_build_challenge_url( string $base, string $return_url, array $extra = array() ): string {
	$query_args = $extra;

	if ( '' !== $return_url ) {
		$query_args['return_url'] = rawurlencode( $return_url );
	}

	return add_query_arg( $query_args, $base );
}
