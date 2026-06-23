<?php
/**
 * Integration tests for Sudo_Session — real bcrypt and session token binding.
 *
 * @covers \WP_Sudo\Sudo_Session
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Sudo_Session;

class SudoSessionTest extends TestCase {

	/**
	 * INTG-02: Real bcrypt hash starts with $2y$ and wp_check_password() returns true.
	 */
	public function test_wp_check_password_verifies_correct_bcrypt(): void {
		$password = 's3cureP@ss';
		$user     = $this->make_admin( $password );

		// Reload from DB to get the hashed password.
		$user = get_user_by( 'id', $user->ID );

		// WP 6.8+ uses bcrypt with a '$wp' prefix for domain separation (SHA-384 pre-hashing).
		// The stored hash is '$wp$2y$...' — the '$wp' prefix distinguishes it from vanilla bcrypt.
		// Older WP (< 6.8) used phpass which produces '$P$' hashes. In either case,
		// wp_check_password() handles verification correctly.
		$is_bcrypt = str_starts_with( $user->user_pass, '$wp$2y$' ) || str_starts_with( $user->user_pass, '$2y$' );
		$is_phpass = str_starts_with( $user->user_pass, '$P$' );
		$this->assertTrue(
			$is_bcrypt || $is_phpass,
			"Password hash should be bcrypt (\$wp\$2y\$ or \$2y\$) or phpass (\$P\$). Got: {$user->user_pass}"
		);
		$this->assertTrue( wp_check_password( $password, $user->user_pass, $user->ID ) );
	}

	/**
	 * INTG-02: Wrong password is rejected by real wp_check_password().
	 */
	public function test_wp_check_password_rejects_wrong_password(): void {
		$user = $this->make_admin( 'correct-password' );
		$user = get_user_by( 'id', $user->ID );

		$this->assertFalse( wp_check_password( 'wrong-password', $user->user_pass, $user->ID ) );
	}

	/**
	 * INTG-03: activate() stores a SHA-256 token hash in user meta.
	 */
	public function test_activate_stores_token_hash_in_user_meta(): void {
		$user = $this->make_admin();

		Sudo_Session::activate( $user->ID );

		$stored_hash = get_user_meta( $user->ID, Sudo_Session::TOKEN_META_KEY, true );
		$this->assertNotEmpty( $stored_hash, 'Token hash should be stored in user meta.' );

		$expiry = get_user_meta( $user->ID, Sudo_Session::META_KEY, true );
		$this->assertGreaterThan( time(), (int) $expiry, 'Expiry should be in the future.' );
	}

	/**
	 * INTG-03: activate() sets the token cookie in the $_COOKIE superglobal.
	 */
	public function test_activate_sets_cookie_superglobal(): void {
		$user = $this->make_admin();

		Sudo_Session::activate( $user->ID );

		$this->assertArrayHasKey( Sudo_Session::TOKEN_COOKIE, $_COOKIE );
		$this->assertSame( 64, strlen( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] ), 'Token should be 64 characters.' );
	}

	/**
	 * INTG-03: The cookie value's SHA-256 hash matches the stored meta hash.
	 *
	 * This is the core token-binding proof: cookie → SHA-256 → user meta.
	 */
	public function test_cookie_sha256_matches_stored_meta_hash(): void {
		$user = $this->make_admin();

		Sudo_Session::activate( $user->ID );

		$cookie_token = $_COOKIE[ Sudo_Session::TOKEN_COOKIE ];
		$stored_hash  = get_user_meta( $user->ID, Sudo_Session::TOKEN_META_KEY, true );

		$this->assertSame(
			hash( 'sha256', $cookie_token ),
			$stored_hash,
			'SHA-256 of cookie token should match stored meta hash.'
		);
	}

	/**
	 * INTG-03: is_active() returns true with valid token binding.
	 */
	public function test_is_active_returns_true_with_valid_binding(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		Sudo_Session::activate( $user->ID );

		$this->assertTrue( Sudo_Session::is_active( $user->ID ) );
	}

	/**
	 * INTG-03: is_active() returns false when cookie is tampered.
	 */
	public function test_is_active_returns_false_with_tampered_cookie(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		Sudo_Session::activate( $user->ID );
		$this->assertTrue( Sudo_Session::is_active( $user->ID ) );

		// Tamper the cookie — SHA-256 mismatch.
		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = 'tampered-cookie-value';
		Sudo_Session::reset_cache();

		$this->assertFalse( Sudo_Session::is_active( $user->ID ) );
	}

	/**
	 * INTG-03: is_active() returns false when session is expired.
	 */
	public function test_is_active_returns_false_when_expired(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		Sudo_Session::activate( $user->ID );
		$this->assertTrue( Sudo_Session::is_active( $user->ID ) );

		// Force expiry to the past.
		update_user_meta( $user->ID, Sudo_Session::META_KEY, time() - 60 );
		Sudo_Session::reset_cache();

		$this->assertFalse( Sudo_Session::is_active( $user->ID ) );
	}

	/**
	 * INTG-03: deactivate() clears meta and cookie.
	 */
	public function test_deactivate_clears_meta_and_cookie(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		Sudo_Session::activate( $user->ID );
		$this->assertTrue( Sudo_Session::is_active( $user->ID ) );

		Sudo_Session::deactivate( $user->ID );
		Sudo_Session::reset_cache();

		$this->assertEmpty( get_user_meta( $user->ID, Sudo_Session::TOKEN_META_KEY, true ) );
		$this->assertEmpty( get_user_meta( $user->ID, Sudo_Session::META_KEY, true ) );
		$this->assertArrayNotHasKey( Sudo_Session::TOKEN_COOKIE, $_COOKIE );
		$this->assertFalse( Sudo_Session::is_active( $user->ID ) );
	}

	/**
	 * INTG-02: verify_token() refuses cross-user checks end-to-end.
	 *
	 * Locks in the defense-in-depth user-ID check added in ca53797 under real
	 * WordPress conditions (user meta + cookie + wp_set_current_user). Even when
	 * another user's session is fully active and the browser still holds that
	 * user's TOKEN_COOKIE, Sudo_Session::is_active() and is_within_grace() must
	 * return false for anyone other than the current request's user.
	 */
	public function test_verify_token_refuses_cross_user_checks(): void {
		$user_a = $this->make_admin();
		$user_b = $this->make_admin();

		// Activate a real session for user B while B is the current user.
		wp_set_current_user( $user_b->ID );
		Sudo_Session::activate( $user_b->ID );
		$this->assertTrue( Sudo_Session::is_active( $user_b->ID ), 'Precondition: B has an active session.' );
		Sudo_Session::reset_cache();

		// Switch current user to A. The cookie and B's stored token hash both
		// still exist and would hash-match — only the current_user context has
		// changed. Without the defense-in-depth check in verify_token(), this
		// assertion would fail (is_active would return true for B).
		wp_set_current_user( $user_a->ID );
		$this->assertFalse(
			Sudo_Session::is_active( $user_b->ID ),
			'is_active() must refuse a check for a user who is not the current request user.'
		);
		Sudo_Session::reset_cache();

		// Expire the meta into the grace window and repeat — is_within_grace()
		// shares the same verify_token() path and must also refuse.
		update_user_meta( $user_b->ID, Sudo_Session::META_KEY, time() - 30 );
		Sudo_Session::reset_cache();
		$this->assertFalse(
			Sudo_Session::is_within_grace( $user_b->ID ),
			'is_within_grace() must also refuse cross-user checks.'
		);
	}

	/**
	 * INTG-02: attempt_activation() with correct password returns success via real bcrypt.
	 */
	public function test_attempt_activation_exercises_real_bcrypt(): void {
		$password = 'correct-horse-battery';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );

		$before = did_action( 'wp_sudo_activated' );
		$result = Sudo_Session::attempt_activation( $user->ID, $password );

		$this->assertSame( 'success', $result['code'] );
		$this->assertTrue( Sudo_Session::is_active( $user->ID ) );
		$this->assertSame( $before + 1, did_action( 'wp_sudo_activated' ), 'wp_sudo_activated should fire once.' );
	}

	/**
	 * INTG-F2: a sudo proof bound to one login session is rejected once the
	 * current login-session token differs (re-login / a different session).
	 *
	 * This is the real binding guarantee under live WordPress: activate() binds
	 * the proof to the token returned by wp_get_session_token() (read from the
	 * logged-in cookie). When that token changes, the unchanged sudo proof cookie
	 * must no longer verify — a captured cookie cannot be replayed from another
	 * login session.
	 */
	public function test_binding_rejects_when_login_session_token_changes(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		$expiration = time() + DAY_IN_SECONDS;
		$manager    = \WP_Session_Tokens::get_instance( $user->ID );

		// Establish login session A and the logged-in cookie wp_get_session_token() reads.
		$token_a                     = $manager->create( $expiration );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user->ID, $expiration, 'logged_in', $token_a );
		$this->assertSame( $token_a, wp_get_session_token(), 'Precondition: cookie resolves login token A.' );

		// Activate sudo — binds the proof to token A.
		Sudo_Session::activate( $user->ID );
		Sudo_Session::reset_cache();
		$this->assertTrue( Sudo_Session::is_active( $user->ID ), 'Active within the binding session.' );
		$this->assertSame(
			hash( 'sha256', $token_a ),
			get_user_meta( $user->ID, Sudo_Session::SESSION_BIND_META_KEY, true ),
			'The stored bind is the SHA-256 of the login-session token.'
		);

		// Switch to a different login session (token B). The sudo proof cookie is
		// unchanged; only the login-session token differs.
		$token_b                     = $manager->create( $expiration );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user->ID, $expiration, 'logged_in', $token_b );
		Sudo_Session::reset_cache();

		$this->assertFalse(
			Sudo_Session::is_active( $user->ID ),
			'A proof bound to session A must not verify under session B.'
		);
	}

	/**
	 * INTG-F2: logging out ends the sudo window. The plugin hooks wp_logout to
	 * deactivate, so a bound proof does not outlive the login that created it.
	 */
	public function test_logout_deactivates_bound_session(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		$expiration                  = time() + DAY_IN_SECONDS;
		$manager                     = \WP_Session_Tokens::get_instance( $user->ID );
		$token                       = $manager->create( $expiration );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user->ID, $expiration, 'logged_in', $token );

		Sudo_Session::activate( $user->ID );
		Sudo_Session::reset_cache();
		$this->assertTrue( Sudo_Session::is_active( $user->ID ), 'Active before logout.' );

		// The plugin's wp_logout handler must clear the sudo session.
		do_action( 'wp_logout', $user->ID );
		Sudo_Session::reset_cache();

		$this->assertFalse( Sudo_Session::is_active( $user->ID ), 'Logout must end the sudo window.' );
		$this->assertEmpty(
			get_user_meta( $user->ID, Sudo_Session::TOKEN_META_KEY, true ),
			'Logout clears the sudo token meta.'
		);
	}
}
