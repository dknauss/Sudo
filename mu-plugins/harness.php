<?php
/**
 * Plugin Name: Capability Floor Prototype — REST harness
 * Description: Exercises the floor + approval mechanism against one real
 *              effect (plugin install via the real WP_Upgrader, reusing
 *              #470's own proven, isolated helper). No test-control route
 *              with global mutable state — deliberately, per the lesson
 *              from tonight's #470 findings.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// real-upgrader-helper.php (from #470) checks this to decide whether to
// substitute a mutated package -- test-only infrastructure this prototype
// does not use. Stubbed to always report no mutation active.
function phase27_mutation_enabled( string $id ): bool {
	return false;
}

require_once __DIR__ . '/real-upgrader-helper.php';

// A same-session REST nonce, rendered server-side in the actual
// cookie-authenticated request rather than scraped from an unrelated core
// page -- matching the pattern #470 used for the same reason: a nonce
// generated any other way (e.g. via WP-CLI) is tied to a different session
// token and will not validate against a real browser cookie session.
add_action(
	'admin_menu',
	static function (): void {
		add_management_page(
			'Capability Floor Harness',
			'Capability Floor Harness',
			'read',
			'cap-floor-harness',
			static function (): void {
				printf(
					'<div class="wrap" id="cap-floor-harness" data-nonce="%s"><h1>Capability Floor Harness</h1></div>',
					esc_attr( wp_create_nonce( 'wp_rest' ) )
				);
			}
		);
	}
);

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'cap-floor/v1',
			'/request-approval',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function (): bool {
					return is_user_logged_in();
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					// Validated as strings before any cast, not after: an array
					// input (e.g. capability[]=x) cast with (string) throws an
					// "Array to string conversion" PHP warning, which a second
					// independent-review agent found prints before this
					// response body when display_errors is on, corrupting the
					// HTTP status the client sees and leaking this file's
					// server path in plaintext. Rejecting the wrong type
					// outright, before any cast runs, avoids triggering the
					// warning at all rather than merely tolerating it.
					$capability_param = $request->get_param( 'capability' );
					$password_param   = $request->get_param( 'password' );
					if ( ! is_string( $capability_param ) || ! is_string( $password_param ) ) {
						return new WP_Error( 'cap_floor_invalid_param', 'Invalid parameter type.', array( 'status' => 400 ) );
					}
					$capability  = $capability_param;
					$password    = $password_param;
					$target_hash = $request->get_param( 'target_hash' );
					$target_hash = is_string( $target_hash ) && '' !== $target_hash ? $target_hash : null;

					if ( ! in_array( $capability, CAP_FLOOR_DENIED_CAPS, true ) ) {
						return new WP_Error( 'cap_floor_unknown_cap', 'Not a floored capability.', array( 'status' => 400 ) );
					}

					$result = cap_floor_request_approval( $capability, $password, $target_hash );
					if ( is_wp_error( $result ) ) {
						return $result;
					}

					return new WP_REST_Response( array( 'approval_id' => $result ), 201 );
				},
			)
		);

		register_rest_route(
			'cap-floor/v1',
			'/install-plugin',
			array(
				'methods'             => 'POST',
				// Deliberately not the full authorization decision: a
				// digest-bound approval can't be evaluated before the
				// upload is parsed, so current_user_can('install_plugins')
				// with no target here would wrongly reject a caller who
				// holds a correctly-scoped approval for a specific digest
				// they haven't uploaded yet. The real decision happens in
				// the callback body below, once the digest is known --
				// the same split #470 used between phase27_permission()
				// and its explicit hash_equals() check.
				'permission_callback' => static function (): bool {
					return is_user_logged_in();
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$approval_id_param = $request->get_param( 'approval_id' );
					if ( ! is_string( $approval_id_param ) ) {
						return new WP_Error( 'cap_floor_invalid_param', 'Invalid parameter type.', array( 'status' => 400 ) );
					}
					$approval_id = $approval_id_param;
					$file        = $_FILES['package'] ?? null;

					if (
						! is_array( $file ) ||
						UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ||
						! is_uploaded_file( (string) ( $file['tmp_name'] ?? '' ) )
					) {
						return new WP_Error( 'cap_floor_upload', 'One successful upload is required.', array( 'status' => 400 ) );
					}

					$digest = hash_file( 'sha256', (string) $file['tmp_name'] );
					if ( ! is_string( $digest ) ) {
						return new WP_Error( 'cap_floor_digest', 'Could not hash the upload.', array( 'status' => 500 ) );
					}

					// Re-check current_user_can with the digest as the
					// meta-cap argument, matching what map_meta_cap actually
					// received -- permission_callback above ran before the
					// file was read, so it could not have checked the
					// digest-bound approval yet. This is the real,
					// digest-bound authorization decision.
					if ( ! current_user_can( 'install_plugins', $digest ) ) {
						return new WP_Error( 'cap_floor_digest_mismatch', 'No approval matches these exact bytes.', array( 'status' => 403 ) );
					}

					// Single-use consumption happens here, at the actual
					// commit point -- not in map_meta_cap, which is called
					// too often and for too many incidental reasons to be
					// where a one-time grant gets spent.
					if ( ! cap_floor_consume_approval( $approval_id, 'install_plugins', $digest ) ) {
						return new WP_Error( 'cap_floor_consumed', 'Approval already used or expired.', array( 'status' => 409 ) );
					}

					$effect_digest = phase27_real_upgrader_effect( (string) $file['tmp_name'] );
					if ( is_wp_error( $effect_digest ) ) {
						return $effect_digest;
					}

					return new WP_REST_Response(
						array( 'status' => 'installed', 'digest' => $digest, 'effect_digest' => $effect_digest ),
						200
					);
				},
			)
		);
	}
);
