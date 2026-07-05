<?php
/**
 * Psudo Lite snippet / mu-plugin.
 *
 * A deliberately small admin-screen reauthentication guard inspired by the
 * original Psudo gist. Drop into wp-content/mu-plugins/psudo-lite.php or load
 * as a code snippet.
 *
 * Scope: interactive wp-admin page loads only. This is not a full replacement
 * for WP Sudo's action registry, request replay, REST/AJAX handling, or
 * effect-level backstops.
 *
 * SECURITY NOTICE — ILLUSTRATIVE REFERENCE ONLY. NOT PRODUCTION-SECURE.
 * DO NOT DEPLOY.
 *
 * This "Sudo Lite" baseline is a teaching reference for cross-checking WP Sudo's
 * design against a minimal reauthentication model. It is intentionally not
 * hardened and has known limitations that the WP Sudo plugin itself handles:
 *
 * - The grant keys off a pre-login nonce bound to the WordPress session token,
 *   which does not survive the token rotation of a real wp-login.php?reauth=1
 *   flow; the return handler also grants without proof that a password was
 *   actually re-entered, so it must not be relied on for enforcement.
 * - Gating is a filename allow-list of interactive wp-admin screens and does
 *   not cover the Settings-API save (options.php) or the plugin/theme install
 *   and upgrade endpoints (update.php?action=...).
 *
 * For real enforcement use the WP Sudo plugin.
 *
 * @package PsudoLite
 */

defined( 'ABSPATH' ) || exit;

const PSUDO_LITE_META_KEY       = '_psudo_lite_grant';
const PSUDO_LITE_QUERY_FLAG     = 'psudo_lite_reauth';
const PSUDO_LITE_QUERY_NONCE    = '_psudo_lite_nonce';
const PSUDO_LITE_NONCE_ACTION   = 'psudo_lite_reauth';
const PSUDO_LITE_DEFAULT_MINUTE = 10;

add_action( 'admin_init', 'psudo_lite_admin_gate', 1 );
add_filter( 'login_message', 'psudo_lite_login_message' );

/**
 * Gate selected interactive admin screens behind recent reauthentication.
 */
function psudo_lite_admin_gate(): void {
	if ( wp_doing_ajax() || ! is_user_logged_in() || ! psudo_lite_user_requires_reauth() ) {
		return;
	}

	if ( psudo_lite_handle_reauth_return() ) {
		return;
	}

	if ( ! psudo_lite_is_protected_admin_request() || psudo_lite_has_active_grant() ) {
		return;
	}

	psudo_lite_redirect_to_reauth();
}

/**
 * Decide whether the current user should be subject to Psudo Lite.
 */
function psudo_lite_user_requires_reauth(): bool {
	/**
	 * Filters the capability required before Psudo Lite applies to a user.
	 *
	 * @param string $capability Capability name.
	 */
	$capability = (string) apply_filters( 'psudo_lite_required_capability', 'manage_options' );

	return current_user_can( $capability );
}

/**
 * Process the post-login return URL and grant a temporary Psudo Lite window.
 */
function psudo_lite_handle_reauth_return(): bool {
	$flag = isset( $_GET[ PSUDO_LITE_QUERY_FLAG ] )
		? sanitize_text_field( wp_unslash( $_GET[ PSUDO_LITE_QUERY_FLAG ] ) )
		: '';

	if ( '1' !== $flag ) {
		return false;
	}

	$nonce = isset( $_GET[ PSUDO_LITE_QUERY_NONCE ] )
		? sanitize_text_field( wp_unslash( $_GET[ PSUDO_LITE_QUERY_NONCE ] ) )
		: '';

	if ( ! wp_verify_nonce( $nonce, PSUDO_LITE_NONCE_ACTION ) ) {
		return false;
	}

	psudo_lite_grant_current_session();

	wp_safe_redirect(
		remove_query_arg(
			array( PSUDO_LITE_QUERY_FLAG, PSUDO_LITE_QUERY_NONCE ),
			psudo_lite_current_admin_url()
		)
	);
	exit;
}

/**
 * Return whether the current admin request is protected by Psudo Lite.
 */
function psudo_lite_is_protected_admin_request(): bool {
	global $pagenow;

	$protected_pages = array(
		'plugins.php',
		'plugin-install.php',
		'plugin-editor.php',
		'themes.php',
		'theme-install.php',
		'theme-editor.php',
		'users.php',
		'user-new.php',
		'user-edit.php',
		'options-general.php',
		'options-writing.php',
		'options-reading.php',
		'options-discussion.php',
		'options-media.php',
		'options-permalink.php',
		'tools.php',
		'import.php',
		'export.php',
		'update-core.php',
		'site-health.php',
	);

	/**
	 * Filters protected wp-admin page filenames.
	 *
	 * @param string[] $protected_pages Protected values such as plugins.php.
	 */
	$protected_pages = (array) apply_filters( 'psudo_lite_protected_pages', $protected_pages );
	$protected_pages = array_map( 'sanitize_file_name', $protected_pages );

	$is_protected = in_array( (string) $pagenow, $protected_pages, true );

	/**
	 * Filters whether the current admin request is protected.
	 *
	 * @param bool   $is_protected Whether Psudo Lite protects this request.
	 * @param string $pagenow      Current admin page filename.
	 */
	return (bool) apply_filters( 'psudo_lite_is_protected_admin_request', $is_protected, (string) $pagenow );
}

/**
 * Return whether this browser session has a still-valid Psudo Lite grant.
 */
function psudo_lite_has_active_grant(): bool {
	$grant = get_user_meta( get_current_user_id(), PSUDO_LITE_META_KEY, true );

	if ( ! is_array( $grant ) || empty( $grant['time'] ) || empty( $grant['token_hash'] ) ) {
		return false;
	}

	$issued_at = absint( $grant['time'] );
	$duration  = psudo_lite_duration_seconds();

	if ( time() > ( $issued_at + $duration ) ) {
		delete_user_meta( get_current_user_id(), PSUDO_LITE_META_KEY );
		return false;
	}

	return hash_equals( (string) $grant['token_hash'], psudo_lite_current_session_hash() );
}

/**
 * Grant Psudo Lite to the current WordPress session token.
 */
function psudo_lite_grant_current_session(): void {
	update_user_meta(
		get_current_user_id(),
		PSUDO_LITE_META_KEY,
		array(
			'time'       => time(),
			'token_hash' => psudo_lite_current_session_hash(),
		)
	);
}

/**
 * Redirect the current request through WordPress's forced reauthentication flow.
 */
function psudo_lite_redirect_to_reauth(): void {
	$redirect_to = add_query_arg(
		array(
			PSUDO_LITE_QUERY_FLAG  => '1',
			PSUDO_LITE_QUERY_NONCE => wp_create_nonce( PSUDO_LITE_NONCE_ACTION ),
		),
		psudo_lite_current_admin_url()
	);

	wp_safe_redirect( wp_login_url( $redirect_to, true ) );
	exit;
}

/**
 * Return the current request URL, constrained to this site.
 */
function psudo_lite_current_admin_url(): string {
	$request_uri = isset( $_SERVER['REQUEST_URI'] )
		? sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) )
		: '/wp-admin/';

	// REQUEST_URI is already the full path from the domain root, including any
	// subdirectory install path (e.g. /blog/wp-admin/plugins.php). Building the
	// URL from the request host rather than home_url() avoids prepending the
	// home path a second time, which on a subdirectory install would yield
	// /blog/blog/wp-admin/... and 404 after reauth. wp_validate_redirect()
	// still constrains the result to this site, falling back to admin_url().
	$host    = isset( $_SERVER['HTTP_HOST'] )
		? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
		: '';
	$current = '' !== $host ? set_url_scheme( 'http://' . $host . $request_uri ) : admin_url();

	return wp_validate_redirect( $current, admin_url() );
}

/**
 * Return the configured Psudo Lite duration in seconds.
 */
function psudo_lite_duration_seconds(): int {
	$minutes = defined( 'PSUDO_LITE_DURATION_MINUTES' ) ? (int) PSUDO_LITE_DURATION_MINUTES : PSUDO_LITE_DEFAULT_MINUTE;
	$minutes = max( 1, min( 30, $minutes ) );

	/**
	 * Filters the Psudo Lite grant duration in seconds.
	 *
	 * @param int $duration Duration in seconds.
	 */
	return (int) apply_filters( 'psudo_lite_duration', $minutes * MINUTE_IN_SECONDS );
}

/**
 * Hash the current WordPress session token without storing the raw token.
 */
function psudo_lite_current_session_hash(): string {
	return hash_hmac( 'sha256', wp_get_session_token(), wp_salt( 'auth' ) );
}

/**
 * Add a small explanatory note to the forced-login screen.
 *
 * @param string $message Existing login message HTML.
 * @return string Modified login message HTML.
 */
function psudo_lite_login_message( string $message ): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only inspection of WordPress login redirect target for contextual messaging.
	$redirect_to = isset( $_REQUEST['redirect_to'] )
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only inspection of WordPress login redirect target for contextual messaging.
		? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) )
		: '';

	if ( false === strpos( $redirect_to, PSUDO_LITE_QUERY_FLAG . '=1' ) ) {
		return $message;
	}

	return $message . '<p class="message"><strong>' . esc_html__( 'Confirm your identity to continue.', 'psudo-lite' ) . '</strong><br>' . esc_html__( 'This admin area is protected by Psudo Lite.', 'psudo-lite' ) . '</p>';
}
