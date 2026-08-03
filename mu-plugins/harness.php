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
			'/create-user',
			array(
				'methods'             => 'POST',
				// Same split as /install-plugin: the digest-bound approval
				// can't be evaluated until the exact fields it covers are
				// known, so the real decision happens in the callback body,
				// not here.
				'permission_callback' => static function (): bool {
					return is_user_logged_in();
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$approval_id_param = $request->get_param( 'approval_id' );
					$username_param    = $request->get_param( 'username' );
					$email_param       = $request->get_param( 'email' );
					$role_param        = $request->get_param( 'role' );

					if (
						! is_string( $approval_id_param ) ||
						! is_string( $username_param ) ||
						! is_string( $email_param ) ||
						! is_string( $role_param )
					) {
						return new WP_Error( 'cap_floor_invalid_param', 'Invalid parameter type.', array( 'status' => 400 ) );
					}

					$username = sanitize_user( $username_param, true );
					$email    = sanitize_email( $email_param );
					$role     = $role_param;

					if ( '' === $username || ! is_email( $email ) || ! array_key_exists( $role, wp_roles()->roles ) ) {
						return new WP_Error( 'cap_floor_user_params', 'Invalid username, email, or role.', array( 'status' => 400 ) );
					}

					// Checked here, before the approval is touched at all --
					// not because wp_insert_user() wouldn't also catch this
					// (it would, via WP_Error 'existing_user_login'), but
					// because by then the approval is already consumed. A
					// fresh-context agent found exactly this: request an
					// approval for a username that already exists, redeem
					// it, get a 500 for the doomed insert, and the approval
					// -- and one of only 3 rate-limited password attempts --
					// is gone anyway, with no user ever created. This is a
					// knowable-in-advance failure, unlike whatever
					// WP_Upgrader might do with a well-formed-looking zip
					// below; check what can cheaply be checked before
					// spending the one-time grant on it.
					if ( username_exists( $username ) || email_exists( $email ) ) {
						return new WP_Error( 'cap_floor_user_exists', 'That username or email is already registered.', array( 'status' => 409 ) );
					}

					// Deterministic digest over exactly the fields this approval
					// authorizes -- the second-effect analogue of hashing the
					// uploaded zip's bytes below. Order and delimiter are fixed
					// so the requester (who computes this same value to ask for
					// an approval) and this route always agree on it.
					$target_hash = hash( 'sha256', $username . "\n" . $email . "\n" . $role );

					if ( ! current_user_can( 'create_users', $target_hash ) ) {
						return new WP_Error( 'cap_floor_digest_mismatch', 'No approval matches these exact user details.', array( 'status' => 403 ) );
					}

					if ( ! cap_floor_consume_approval( $approval_id_param, 'create_users', $target_hash ) ) {
						return new WP_Error( 'cap_floor_consumed', 'Approval already used or expired.', array( 'status' => 409 ) );
					}

					$user_id = wp_insert_user(
						array(
							'user_login' => $username,
							'user_email' => $email,
							'role'       => $role,
							'user_pass'  => wp_generate_password( 24 ),
						)
					);

					if ( is_wp_error( $user_id ) ) {
						return $user_id;
					}

					return new WP_REST_Response(
						array( 'status' => 'created', 'user_id' => $user_id, 'target_hash' => $target_hash ),
						201
					);
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

		// The third effect -- deliberately structurally different from the
		// first two, which both create/upload fresh content with a natural
		// byte-level fingerprint. This one targets an EXISTING resource by
		// ID. Wired for real native-UI use (see users-delete-intercept.js,
		// enqueued below) rather than a REST-only harness call, to test
		// whether the digest-bound approval pattern still holds once a real
		// wp-admin screen -- not just a bespoke fetch() call -- is driving
		// it.
		register_rest_route(
			'cap-floor/v1',
			'/delete-user',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function (): bool {
					return is_user_logged_in();
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$approval_id_param = $request->get_param( 'approval_id' );
					$user_id_param     = $request->get_param( 'user_id' );
					$reassign_param    = $request->get_param( 'reassign' );

					if (
						! is_string( $approval_id_param ) ||
						! is_string( $user_id_param ) ||
						( null !== $reassign_param && ! is_string( $reassign_param ) )
					) {
						return new WP_Error( 'cap_floor_invalid_param', 'Invalid parameter type.', array( 'status' => 400 ) );
					}

					if ( ! ctype_digit( $user_id_param ) ) {
						return new WP_Error( 'cap_floor_user_params', 'Invalid user_id.', array( 'status' => 400 ) );
					}
					$reassign = ( is_string( $reassign_param ) && ctype_digit( $reassign_param ) ) ? $reassign_param : '0';
					$user_id  = (int) $user_id_param;

					// Checked before the approval is touched, same principle
					// as the create-user existing-username fix: a target
					// that can never succeed should never cost a password
					// check or a rate-limited attempt.
					if ( $user_id === get_current_user_id() ) {
						return new WP_Error( 'cap_floor_self_delete', 'Cannot delete your own account through this route.', array( 'status' => 400 ) );
					}
					if ( ! get_userdata( $user_id ) ) {
						return new WP_Error( 'cap_floor_user_missing', 'That user does not exist.', array( 'status' => 409 ) );
					}
					if ( '0' !== $reassign && ! get_userdata( (int) $reassign ) ) {
						return new WP_Error( 'cap_floor_reassign_missing', 'The reassignment target does not exist.', array( 'status' => 409 ) );
					}

					// Same shape as the other two effects: a deterministic
					// digest over exactly the fields this approval
					// authorizes, computed identically on the requesting
					// side (users-delete-intercept.js) and here.
					$target_hash = hash( 'sha256', $user_id_param . "\n" . $reassign );

					if ( ! current_user_can( 'delete_users', $target_hash ) ) {
						return new WP_Error( 'cap_floor_digest_mismatch', 'No approval matches this exact user and reassignment.', array( 'status' => 403 ) );
					}

					if ( ! cap_floor_consume_approval( $approval_id_param, 'delete_users', $target_hash ) ) {
						return new WP_Error( 'cap_floor_consumed', 'Approval already used or expired.', array( 'status' => 409 ) );
					}

					// wp_delete_user() lives in wp-admin/includes/user.php,
					// never autoloaded on a REST request -- unlike
					// wp_insert_user(), which is a wp-includes core function
					// always available.
					require_once ABSPATH . 'wp-admin/includes/user.php';
					$result = wp_delete_user( $user_id, '0' === $reassign ? null : (int) $reassign );
					if ( ! $result ) {
						return new WP_Error( 'cap_floor_delete_failed', 'wp_delete_user() reported failure.', array( 'status' => 500 ) );
					}

					return new WP_REST_Response(
						array( 'status' => 'deleted', 'user_id' => $user_id, 'target_hash' => $target_hash ),
						200
					);
				},
			)
		);
	}
);

// Loaded only on the real Users list screen, so the delete row-action link
// can be intercepted there instead of requiring a separate harness page.
add_action(
	'admin_enqueue_scripts',
	static function ( string $hook ): void {
		if ( 'users.php' !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'cap-floor-delete-intercept',
			plugins_url( 'assets/users-delete-intercept.js', __FILE__ ),
			array(),
			'1.0.0',
			true
		);
		wp_localize_script(
			'cap-floor-delete-intercept',
			'capFloorDeleteIntercept',
			array(
				'restUrl' => esc_url_raw( rest_url( 'cap-floor/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}
);

// Cosmetic only -- restores the "Delete" row-action link that WordPress's
// own list table omits entirely once current_user_can( 'delete_user', $id )
// is false, which it always is here without an approval already held (see
// class-wp-users-list-table.php around line 476). Found empirically: the
// floor's side effect is not just that the action is blocked, it's that
// the entire native affordance for it disappears, since core builds this
// row-action array from the same capability check the floor denies.
//
// This filter does not grant anything. It is a static HTML link the JS
// intercept above claims via a click handler before the browser ever
// follows it; the real authorization decision happens entirely in the
// /delete-user REST route, independent of this markup. If the JS fails to
// load, the link still points at the real native flow (matching core's
// own href construction), which still correctly requires an approval that
// does not yet exist -- it fails closed, not open.
add_filter(
	'user_row_actions',
	static function ( array $actions, WP_User $user_object ): array {
		if ( is_multisite() || get_current_user_id() === $user_object->ID || isset( $actions['delete'] ) ) {
			return $actions;
		}
		$actions['delete'] = "<a class='submitdelete' href='" . esc_url( wp_nonce_url( 'users.php?action=delete&user=' . $user_object->ID, 'bulk-users' ) ) . "'>" . __( 'Delete' ) . '</a>';
		return $actions;
	},
	10,
	2
);
