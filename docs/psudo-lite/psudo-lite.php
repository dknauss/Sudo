<?php
/**
 * Plugin Name: Psudo Lite
 * Description: A small reauthentication guard for selected high-risk WordPress admin screens. Inspired by the original Psudo gist; not a replacement for WP Sudo.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Dan Knauss
 * License: GPL-2.0-or-later
 * Text Domain: psudo-lite
 *
 * @package PsudoLite
 */

defined( 'ABSPATH' ) || exit;

const PSUDO_LITE_VERSION         = '0.1.0';
const PSUDO_LITE_OPTION          = 'psudo_lite_settings';
const PSUDO_LITE_META_KEY        = '_psudo_lite_grant';
const PSUDO_LITE_QUERY_FLAG      = 'psudo_lite_reauth';
const PSUDO_LITE_QUERY_NONCE     = '_psudo_lite_nonce';
const PSUDO_LITE_NONCE_ACTION    = 'psudo_lite_reauth';
const PSUDO_LITE_DEFAULT_MINUTES = 10;

add_action( 'admin_init', 'psudo_lite_register_settings' );
add_action( 'admin_menu', 'psudo_lite_add_settings_page' );
add_action( 'admin_init', 'psudo_lite_admin_gate', 1 );
add_filter( 'login_message', 'psudo_lite_login_message' );

/**
 * Register the minimal settings used by Psudo Lite.
 */
function psudo_lite_register_settings(): void {
	register_setting(
		'psudo_lite_settings',
		PSUDO_LITE_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'psudo_lite_sanitize_settings',
			'default'           => psudo_lite_default_settings(),
		)
	);

	add_settings_section(
		'psudo_lite_main',
		esc_html__( 'Reauthentication window', 'psudo-lite' ),
		'__return_null',
		'psudo-lite'
	);

	add_settings_field(
		'duration_minutes',
		esc_html__( 'Duration in minutes', 'psudo-lite' ),
		'psudo_lite_render_duration_field',
		'psudo-lite',
		'psudo_lite_main'
	);
}

/**
 * Default plugin settings.
 *
 * @return array{duration_minutes:int}
 */
function psudo_lite_default_settings(): array {
	return array( 'duration_minutes' => PSUDO_LITE_DEFAULT_MINUTES );
}

/**
 * Sanitize settings from the Settings API.
 *
 * @param mixed $input Raw settings input.
 * @return array{duration_minutes:int}
 */
function psudo_lite_sanitize_settings( mixed $input ): array {
	$settings = psudo_lite_default_settings();

	if ( is_array( $input ) && isset( $input['duration_minutes'] ) ) {
		$settings['duration_minutes'] = absint( $input['duration_minutes'] );
	}

	if ( $settings['duration_minutes'] < 1 || $settings['duration_minutes'] > 30 ) {
		add_settings_error(
			PSUDO_LITE_OPTION,
			'psudo_lite_duration_minutes',
			esc_html__( 'Psudo Lite duration must be between 1 and 30 minutes.', 'psudo-lite' ),
			'error'
		);
		$settings['duration_minutes'] = psudo_lite_duration_minutes();
	}

	return $settings;
}

/**
 * Add the Settings → Psudo Lite page.
 */
function psudo_lite_add_settings_page(): void {
	add_options_page(
		esc_html__( 'Psudo Lite Settings', 'psudo-lite' ),
		esc_html__( 'Psudo Lite', 'psudo-lite' ),
		'manage_options',
		'psudo-lite',
		'psudo_lite_render_settings_page'
	);
}

/**
 * Render the duration field.
 */
function psudo_lite_render_duration_field(): void {
	printf(
		'<input type="number" id="psudo-lite-duration" name="%1$s[duration_minutes]" value="%2$d" min="1" max="30" class="small-text">',
		esc_attr( PSUDO_LITE_OPTION ),
		absint( psudo_lite_duration_minutes() )
	);
	printf(
		'<p class="description">%s</p>',
		esc_html__( 'How long a successful reauthentication should unlock protected admin screens for this browser session.', 'psudo-lite' )
	);
}

/**
 * Render the settings page.
 */
function psudo_lite_render_settings_page(): void {
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Psudo Lite Settings', 'psudo-lite' ); ?></h1>
		<p><?php echo esc_html__( 'Psudo Lite asks administrators to reauthenticate before entering selected high-risk admin screens.', 'psudo-lite' ); ?></p>
		<p><?php echo esc_html__( 'It protects interactive wp-admin page loads only. Use WP Sudo for comprehensive action gating across request surfaces.', 'psudo-lite' ); ?></p>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'psudo_lite_settings' );
			do_settings_sections( 'psudo-lite' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

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
 * Return the configured duration in minutes.
 */
function psudo_lite_duration_minutes(): int {
	$settings = get_option( PSUDO_LITE_OPTION, psudo_lite_default_settings() );
	$minutes  = is_array( $settings ) && isset( $settings['duration_minutes'] )
		? absint( $settings['duration_minutes'] )
		: PSUDO_LITE_DEFAULT_MINUTES;

	return max( 1, min( 30, $minutes ) );
}

/**
 * Return the configured Psudo Lite duration in seconds.
 */
function psudo_lite_duration_seconds(): int {
	/**
	 * Filters the Psudo Lite grant duration in seconds.
	 *
	 * @param int $duration Duration in seconds.
	 */
	return (int) apply_filters( 'psudo_lite_duration', psudo_lite_duration_minutes() * MINUTE_IN_SECONDS );
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
