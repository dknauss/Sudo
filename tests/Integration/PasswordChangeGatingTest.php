<?php
/**
 * Integration tests for the password-change kill chain (review finding B-CU1).
 *
 * The security model's most-emphasized claim is the session-theft ->
 * password-change -> lockout kill chain: changing the account password must
 * (a) itself be a gated action on every surface, and (b) immediately expire
 * any active sudo session so stolen credentials cannot ride an old session.
 *
 * Pins, end-to-end with real WordPress + MySQL (no mocks):
 *  1. The 'user.change_password' rule gates admin-surface profile updates
 *     carrying pass1/pass2 (includes/class-action-registry.php:337-367, verified).
 *  2. The REST surface blocks a password write to /wp/v2/users/{id} without
 *     sudo (includes/class-gate.php:1108-1207 intercept_rest, verified;
 *     block_rest error shape at includes/class-gate.php:1914-1937, verified).
 *  3. WordPress core reset_password() fires 'after_password_reset'
 *     (.tmp/wordpress/wp-includes/user.php:3518, verified) and
 *     Plugin::deactivate_session_on_password_reset() expires the session
 *     (includes/class-plugin.php:348-352, hooked at class-plugin.php:152, verified).
 *  4. wp_update_user() with a changed password fires 'profile_update'
 *     (.tmp/wordpress/wp-includes/user.php:2659, verified) and
 *     Plugin::deactivate_session_on_profile_update() expires the session only
 *     when the password hash actually changed
 *     (includes/class-plugin.php:375-381, hooked at class-plugin.php:153, verified).
 *
 * @covers \WP_Sudo\Gate::match_request
 * @covers \WP_Sudo\Gate::intercept_rest
 * @covers \WP_Sudo\Plugin::deactivate_session_on_password_reset
 * @covers \WP_Sudo\Plugin::deactivate_session_on_profile_update
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Gate;
use WP_Sudo\Request_Stash;
use WP_Sudo\Sudo_Session;

class PasswordChangeGatingTest extends TestCase {

	// ─────────────────────────────────────────────────────────────────────
	// 1. Admin surface: user.change_password rule matching
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * A profile-update POST carrying pass1/pass2 matches user.change_password
	 * on both profile.php and user-edit.php.
	 *
	 * Pins the admin matcher of the rule: pagenow array('profile.php',
	 * 'user-edit.php'), actions array('update'), method POST, and the callback
	 * that returns true when $_POST['pass1'] or $_POST['pass2'] is non-empty
	 * (includes/class-action-registry.php:340-353, verified). Matching is
	 * evaluated through Gate::match_request('admin')
	 * (includes/class-gate.php:892-938, verified).
	 */
	public function test_admin_password_change_matches_rule_on_profile_and_user_edit_pages(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		$gate = wp_sudo()->gate();
		$this->assertInstanceOf( Gate::class, $gate, 'Plugin::init() should have created the Gate.' );

		// profile.php — own-profile password change.
		$this->simulate_admin_request(
			'profile.php',
			'update',
			'POST',
			array(),
			array(
				'pass1' => 'new-secret-123',
				'pass2' => 'new-secret-123',
			)
		);

		$matched = $gate->match_request( 'admin' );
		$this->assertNotNull( $matched, 'profile.php POST with pass1/pass2 should match a rule.' );
		$this->assertSame( 'user.change_password', $matched['id'] );

		// user-edit.php — changing another user's password. No 'role' key is
		// posted, so the earlier user.promote_profile rule (callback requires
		// non-empty $_POST['role'], includes/class-action-registry.php:325-329,
		// verified) must not swallow the match.
		$this->simulate_admin_request(
			'user-edit.php',
			'update',
			'POST',
			array( 'user_id' => (string) $user->ID ),
			array(
				'pass1' => 'new-secret-123',
				'pass2' => 'new-secret-123',
			)
		);

		$matched = $gate->match_request( 'admin' );
		$this->assertNotNull( $matched, 'user-edit.php POST with pass1/pass2 should match a rule.' );
		$this->assertSame( 'user.change_password', $matched['id'] );
	}

	/**
	 * A profile-update POST WITHOUT password fields does not match any rule.
	 *
	 * profile.php uses action=update for every profile save (bio, email,
	 * display name, ...), so the rule callback narrows the gate to requests
	 * where pass1/pass2 carry a value
	 * (includes/class-action-registry.php:344-353, verified). Empty-string
	 * password fields (browsers submit them blank on unrelated saves) must
	 * also not gate.
	 */
	public function test_admin_profile_update_without_password_fields_is_not_gated(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		$gate = wp_sudo()->gate();
		$this->assertInstanceOf( Gate::class, $gate );

		// Routine profile save: display name only, no pass1/pass2 keys at all.
		$this->simulate_admin_request(
			'profile.php',
			'update',
			'POST',
			array(),
			array( 'display_name' => 'New Display Name' )
		);

		$this->assertNull(
			$gate->match_request( 'admin' ),
			'Profile save without password fields should not be gated.'
		);

		// Same save with the password fields present but EMPTY — the shape the
		// real profile.php form submits when the password is left untouched.
		$this->simulate_admin_request(
			'profile.php',
			'update',
			'POST',
			array(),
			array(
				'display_name' => 'New Display Name',
				'pass1'        => '',
				'pass2'        => '',
			)
		);

		$this->assertNull(
			$gate->match_request( 'admin' ),
			'Empty pass1/pass2 fields should not trigger the password-change gate.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// 2. REST surface: password write to /wp/v2/users/{id}
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Cookie-auth REST PUT /wp/v2/users/{id} with a 'password' body param and
	 * no sudo session is blocked with WP_Error 'sudo_required' (403).
	 *
	 * Pins the REST matcher of user.change_password: route regex
	 * '#^/wp/v2/users/(?:\d+|me)$#', methods PUT/PATCH, callback
	 * array_key_exists('password', $request->get_params())
	 * (includes/class-action-registry.php:356-365, verified). The cookie-auth
	 * block path returns block_rest()'s WP_Error with code 'sudo_required',
	 * status 403, and the rule id in error data
	 * (includes/class-gate.php:1193-1206 and 1914-1937, verified).
	 */
	public function test_rest_password_write_without_sudo_returns_sudo_required(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		$gate = new Gate( new Sudo_Session(), new Request_Stash() );

		$request = new \WP_REST_Request( 'PUT', '/wp/v2/users/' . $user->ID );
		$request->set_body_params( array( 'password' => 'attacker-chosen-pass' ) );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$result = $gate->intercept_rest( null, array(), $request );

		$this->assertWPError( $result );
		$this->assertSame( 'sudo_required', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 'user.change_password', $result->get_error_data()['rule_id'] );
	}

	/**
	 * The same REST user update WITHOUT the password field passes through.
	 *
	 * The rule callback gates only when 'password' is present in the request
	 * params (includes/class-action-registry.php:359-364, verified) —
	 * /wp/v2/users/{id} also serves routine profile edits which must not
	 * require sudo. 'roles' is deliberately absent so the user.promote REST
	 * rule (includes/class-action-registry.php:306-313, verified) cannot
	 * match either.
	 */
	public function test_rest_user_update_without_password_field_passes_through(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		$gate = new Gate( new Sudo_Session(), new Request_Stash() );

		$request = new \WP_REST_Request( 'PUT', '/wp/v2/users/' . $user->ID );
		$request->set_body_params( array( 'description' => 'Just a bio update' ) );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$original_response = array( 'id' => $user->ID );
		$result            = $gate->intercept_rest( $original_response, array(), $request );

		$this->assertNotWPError( $result, 'User update without password field should not be gated.' );
		$this->assertSame( $original_response, $result, 'Original response should pass through unchanged.' );
	}

	// ─────────────────────────────────────────────────────────────────────
	// 3. after_password_reset expiry (lost-password flow)
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * reset_password() expires an active sudo session and clears token meta.
	 *
	 * Activates a real session via Sudo_Session::attempt_activation() with the
	 * real bcrypt-hashed password (includes/class-sudo-session.php:348-472,
	 * verified), then calls WordPress core reset_password(), which fires
	 * 'after_password_reset' (.tmp/wordpress/wp-includes/user.php:3518,
	 * verified). Plugin::deactivate_session_on_password_reset() is hooked
	 * there (includes/class-plugin.php:152, verified) and calls
	 * Sudo_Session::deactivate(), which deletes the _wp_sudo_expires and
	 * _wp_sudo_token user meta (includes/class-plugin.php:348-352 and
	 * includes/class-sudo-session.php:870-897 clear_session_data, verified).
	 */
	public function test_password_reset_expires_active_sudo_session(): void {
		$password = 'kill-chain-original-pass';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );

		// The handler must actually be registered in the integration
		// environment (Plugin::init() runs at plugins_loaded via the
		// muplugins_loaded bootstrap; hook wiring at class-plugin.php:152).
		$this->assertSame(
			10,
			has_action( 'after_password_reset', array( wp_sudo(), 'deactivate_session_on_password_reset' ) ),
			'deactivate_session_on_password_reset should be hooked at after_password_reset priority 10.'
		);

		// Activate a real session: real bcrypt verification, real user meta,
		// token bound via the $_COOKIE superglobal.
		$result = Sudo_Session::attempt_activation( $user->ID, $password );
		$this->assertSame( 'success', $result['code'], 'Real-password activation should succeed.' );
		$this->assertTrue( Sudo_Session::is_active( $user->ID ), 'Session should be active before the reset.' );

		$deactivated_before = did_action( 'wp_sudo_deactivated' );

		// Lost-password flow: core stores the new password, then fires
		// after_password_reset.
		reset_password( $user, 'new-password-123' );

		Sudo_Session::reset_cache();
		$this->assertFalse(
			Sudo_Session::is_active( $user->ID ),
			'Sudo session must be expired after a password reset.'
		);
		$this->assertSame(
			$deactivated_before + 1,
			did_action( 'wp_sudo_deactivated' ),
			'wp_sudo_deactivated should fire exactly once for the reset.'
		);

		// Token meta must be gone — a stolen session cookie is now worthless.
		$this->assertSame( '', get_user_meta( $user->ID, Sudo_Session::META_KEY, true ) );
		$this->assertSame( '', get_user_meta( $user->ID, Sudo_Session::TOKEN_META_KEY, true ) );
	}

	// ─────────────────────────────────────────────────────────────────────
	// 4. profile_update expiry (password change via wp_update_user)
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * wp_update_user() with a new password expires the active sudo session.
	 *
	 * wp_update_user() hashes the changed password and fires 'profile_update'
	 * with the merged userdata (.tmp/wordpress/wp-includes/user.php:2744-2748,
	 * 2784-2785, 2659, verified). Plugin::deactivate_session_on_profile_update()
	 * (hooked at includes/class-plugin.php:153, verified) compares
	 * $old_user_data->user_pass against $userdata['user_pass'] and deactivates
	 * on mismatch (includes/class-plugin.php:375-381, verified).
	 */
	public function test_profile_update_with_password_change_expires_active_session(): void {
		$password = 'kill-chain-profile-pass';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );

		$this->assertSame(
			10,
			has_action( 'profile_update', array( wp_sudo(), 'deactivate_session_on_profile_update' ) ),
			'deactivate_session_on_profile_update should be hooked at profile_update priority 10.'
		);

		$result = Sudo_Session::attempt_activation( $user->ID, $password );
		$this->assertSame( 'success', $result['code'] );
		$this->assertTrue( Sudo_Session::is_active( $user->ID ) );

		$deactivated_before = did_action( 'wp_sudo_deactivated' );

		$updated = wp_update_user(
			array(
				'ID'        => $user->ID,
				'user_pass' => 'changed-pass-456',
			)
		);
		$this->assertNotWPError( $updated, 'wp_update_user should succeed.' );

		Sudo_Session::reset_cache();
		$this->assertFalse(
			Sudo_Session::is_active( $user->ID ),
			'Sudo session must be expired after a profile-update password change.'
		);
		$this->assertSame(
			$deactivated_before + 1,
			did_action( 'wp_sudo_deactivated' ),
			'wp_sudo_deactivated should fire exactly once for the password change.'
		);
		$this->assertSame( '', get_user_meta( $user->ID, Sudo_Session::META_KEY, true ) );
		$this->assertSame( '', get_user_meta( $user->ID, Sudo_Session::TOKEN_META_KEY, true ) );
	}

	/**
	 * wp_update_user() WITHOUT a password change preserves the active session.
	 *
	 * Negative pin for the hash comparison in
	 * Plugin::deactivate_session_on_profile_update()
	 * (includes/class-plugin.php:376, verified): wp_update_user() merges the
	 * existing user row into $userdata (.tmp/wordpress/wp-includes/user.php:2784,
	 * verified), so user_pass is present but UNCHANGED on a display-name-only
	 * save — the handler must not disturb the session.
	 */
	public function test_profile_update_without_password_change_preserves_active_session(): void {
		$password = 'kill-chain-stable-pass';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );

		$result = Sudo_Session::attempt_activation( $user->ID, $password );
		$this->assertSame( 'success', $result['code'] );
		$this->assertTrue( Sudo_Session::is_active( $user->ID ) );

		$deactivated_before = did_action( 'wp_sudo_deactivated' );

		$updated = wp_update_user(
			array(
				'ID'           => $user->ID,
				'display_name' => 'Renamed Admin',
			)
		);
		$this->assertNotWPError( $updated, 'wp_update_user should succeed.' );

		// Force a fresh read of meta + token so the assertion is not satisfied
		// by the per-request is_active() cache.
		Sudo_Session::reset_cache();
		$this->assertTrue(
			Sudo_Session::is_active( $user->ID ),
			'A profile save without a password change must NOT expire the sudo session.'
		);
		$this->assertSame(
			$deactivated_before,
			did_action( 'wp_sudo_deactivated' ),
			'wp_sudo_deactivated should not fire for a display-name-only save.'
		);
		$this->assertNotSame( '', get_user_meta( $user->ID, Sudo_Session::META_KEY, true ) );
	}
}
