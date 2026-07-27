<?php
/**
 * Integration coverage for the install_package() vertical slice.
 *
 * These drive the REAL WP_Upgrader::install_package() against a live WordPress,
 * so what is under test is core's actual `upgrader_pre_install` short-circuit,
 * not a re-implementation of it. The point of the slice is that this seam
 * already exists and already vetoes; a test that mocked it would prove nothing.
 *
 * @package WP_Sudo\PoC
 */

declare( strict_types = 1 );

namespace WP_Sudo\PoC\Tests;

use WP_Session_Tokens;
use WP_UnitTestCase;
use WP_Upgrader;
use Automatic_Upgrader_Skin;

use function WP_Sudo\PoC\InstallPackageGate\grant_proof;
use function WP_Sudo\PoC\InstallPackageGate\has_proof;
use function WP_Sudo\PoC\InstallPackageGate\revoke_proof;

final class InstallPackageGateTest extends WP_UnitTestCase {

	private int $admin_id;
	private string $source;
	private string $destination;

	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// A real (tiny) package on disk, and a destination that must stay absent
		// when the gate refuses.
		$this->source = get_temp_dir() . 'poc-src-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->source );
		file_put_contents( $this->source . '/plugin.php', "<?php\n// package payload\n" );

		$this->destination = get_temp_dir() . 'poc-dest-' . wp_generate_password( 8, false );

		// WP_Upgrader and the filesystem API live in wp-admin and are not loaded
		// by default in the test environment.
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! class_exists( 'WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		}
		WP_Filesystem();
	}

	public function tear_down(): void {
		unset(
			$_COOKIE[ LOGGED_IN_COOKIE ],
			$_COOKIE[ AUTH_COOKIE ],
			$_COOKIE[ SECURE_AUTH_COOKIE ],
			$_COOKIE[ \WP_Sudo\PoC\InstallPackageGate\PROOF_COOKIE ]
		);
		foreach ( array( $this->source, $this->destination ) as $dir ) {
			if ( is_dir( $dir ) ) {
				array_map( 'unlink', glob( $dir . '/*' ) ?: array() );
				@rmdir( $dir );
			}
		}
		parent::tear_down();
	}

	/** Put a real, verifiable login session behind the current request. */
	private function start_session( int $user_id ): string {
		$expiry  = time() + DAY_IN_SECONDS;
		$token   = WP_Session_Tokens::get_instance( $user_id )->create( $expiry );

		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, $expiry, 'logged_in', $token );

		return $token;
	}

	/** Drive the real core sink. */
	private function install( array $hook_extra = array( 'type' => 'plugin', 'action' => 'install' ) ) {
		// Automatic_Upgrader_Skin collects messages instead of echoing them, so
		// driving the sink does not write to the test output stream.
		$upgrader = new WP_Upgrader( new Automatic_Upgrader_Skin() );
		$upgrader->init();

		return $upgrader->install_package(
			array(
				'source'                      => $this->source,
				'destination'                 => $this->destination,
				'clear_destination'           => false,
				'abort_if_destination_exists' => false,
				'clear_working'               => false,
				'hook_extra'                  => $hook_extra,
			)
		);
	}

	/**
	 * The load-bearing test: an interactive session with no proof cannot write a
	 * package, and nothing lands on disk.
	 */
	public function test_interactive_session_without_proof_cannot_write_a_package(): void {
		$this->start_session( $this->admin_id );

		$result = $this->install();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_sudo_reauth_required', $result->get_error_code() );
		$this->assertDirectoryDoesNotExist(
			$this->destination,
			'the package must not reach the filesystem when the gate refuses'
		);
	}

	/** With proof, the gate is not what stops it. */
	public function test_interactive_session_with_proof_passes_the_gate(): void {
		$this->start_session( $this->admin_id );
		$this->assertTrue( grant_proof(), 'a session-bound proof can be issued' );
		$this->assertTrue( has_proof() );

		$result = $this->install();

		if ( is_wp_error( $result ) ) {
			$this->assertNotSame(
				'wp_sudo_reauth_required',
				$result->get_error_code(),
				'with proof present the gate must not be the thing refusing'
			);
		} else {
			$this->assertIsArray( $result );
		}
	}

	/**
	 * A *second, independent* session on the same account does not inherit the
	 * proof.
	 *
	 * Note what this does and does not prove. start_session() mints a NEW token
	 * for the second browser, so this is two separate logins — the weaker
	 * property. Resistance to a genuinely COPIED cookie is a different and
	 * harder claim, covered by
	 * test_a_copied_login_cookie_does_not_inherit_the_proof().
	 */
	public function test_a_second_independent_session_does_not_inherit_the_proof(): void {
		// Browser A proves intent.
		$this->start_session( $this->admin_id );
		grant_proof();
		$this->assertTrue( has_proof(), 'sanity: browser A holds the proof' );

		// Browser B — same user, same account, different login session.
		$this->start_session( $this->admin_id );

		$this->assertFalse( has_proof(), 'browser B must not inherit browser A proof' );

		$result = $this->install();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_sudo_reauth_required', $result->get_error_code() );
		$this->assertDirectoryDoesNotExist( $this->destination );
	}

	/**
	 * THE test the proposal stands or falls on (§4).
	 *
	 * An attacker who copies the victim's actual login cookie presents the SAME
	 * session token, so a proof keyed on the token alone would resolve to the
	 * same record and the copy would inherit it. Session binding does not close
	 * this; a separate browser-held secret does.
	 *
	 * Here browser B replays browser A's exact login cookie — captured before A
	 * answered the challenge, as a thief's copy would be — and must still be
	 * refused, because it does not carry the proof secret A was issued.
	 */
	public function test_a_copied_login_cookie_does_not_inherit_the_proof(): void {
		$this->start_session( $this->admin_id );

		// The thief's copy: taken before the challenge.
		$stolen_cookie = $_COOKIE[ LOGGED_IN_COOKIE ];

		grant_proof();
		$this->assertTrue( has_proof(), 'sanity: browser A holds the proof' );

		// Browser B: identical login cookie, no proof cookie.
		$_COOKIE[ LOGGED_IN_COOKIE ] = $stolen_cookie;
		unset( $_COOKIE[ \WP_Sudo\PoC\InstallPackageGate\PROOF_COOKIE ] );

		$this->assertSame(
			$stolen_cookie,
			$_COOKIE[ LOGGED_IN_COOKIE ],
			'sanity: this is the same cookie, not a second login'
		);
		$this->assertFalse( has_proof(), 'a copied cookie must not inherit the proof' );

		$result = $this->install();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_sudo_reauth_required', $result->get_error_code() );
		$this->assertDirectoryDoesNotExist( $this->destination );
	}

	/**
	 * Fail-open regression: a revoked session must be REFUSED, not reclassified
	 * as actorless and waved through.
	 *
	 * "Log out everywhere" destroys the token while the request may still hold a
	 * loaded current user. If that collapses to "no actor", the out-of-v1-scope
	 * branch allows the write — inverting the intent of revocation.
	 */
	public function test_a_revoked_session_is_refused_not_treated_as_actorless(): void {
		$this->start_session( $this->admin_id );
		grant_proof();
		$this->assertTrue( has_proof() );

		// Log out everywhere: the cookie is still on the request, the token is not.
		WP_Session_Tokens::get_instance( $this->admin_id )->destroy_all();

		$this->assertFalse( has_proof(), 'a destroyed session cannot still hold a proof' );

		$result = $this->install();

		$this->assertWPError(
			$result,
			'a revoked session must be refused, not reclassified as actorless'
		);
		$this->assertSame( 'wp_sudo_reauth_required', $result->get_error_code() );
		$this->assertDirectoryDoesNotExist( $this->destination );
	}

	/**
	 * v1 scope boundary (#320): with no interactive actor there is nobody to
	 * challenge, so the call behaves exactly as it does in stock WordPress.
	 * This is the branch that keeps background security updates working.
	 */
	public function test_a_request_with_no_session_is_out_of_v1_scope(): void {
		// No login cookie: cron, WP-CLI, or an API-credential caller.
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );

		$result = $this->install();

		if ( is_wp_error( $result ) ) {
			$this->assertNotSame(
				'wp_sudo_reauth_required',
				$result->get_error_code(),
				'a caller with no session must pass through ungated in v1'
			);
		} else {
			$this->assertIsArray( $result );
		}
	}

	/**
	 * The misclassification boundary from #357: "programmatic" is not an actor
	 * class. A call originating in plugin code during an interactive request is
	 * still an interactive actor, because the *request* carries the session — so
	 * calling the sink from a hook must not be a way around the gate.
	 */
	public function test_a_programmatic_call_inside_an_interactive_request_is_still_gated(): void {
		$this->start_session( $this->admin_id );

		$captured = null;
		add_action(
			'wp_sudo_poc_probe',
			function () use ( &$captured ) {
				// Same sink, reached from plugin code rather than a form handler.
				$captured = $this->install();
			}
		);
		do_action( 'wp_sudo_poc_probe' );

		$this->assertWPError( $captured );
		$this->assertSame(
			'wp_sudo_reauth_required',
			$captured->get_error_code(),
			'call origin must not change the actor class'
		);
	}

	/** A forged cookie is not a session, so it cannot mint or hold a proof. */
	public function test_a_forged_cookie_cannot_hold_a_proof(): void {
		$_COOKIE[ LOGGED_IN_COOKIE ] = 'someone|9999999999|forged-token|deadbeef';

		$this->assertFalse( grant_proof(), 'a forged cookie must not be able to issue a proof' );
		$this->assertFalse( has_proof() );
	}

	/**
	 * The control. Without this test the suite proves only that
	 * install_package() returned an error — which it might have done anyway,
	 * for reasons having nothing to do with the gate.
	 *
	 * Same call, same arguments, twice: once with the filter attached, once
	 * without. With the gate the call is refused and nothing lands. Without it
	 * the identical call writes the package to disk. That difference is the
	 * whole claim of proposal §3 — that install_package() is a real chokepoint
	 * and a filter there genuinely stops a code write.
	 */
	public function test_without_the_gate_the_identical_call_writes_the_package(): void {
		$this->start_session( $this->admin_id );

		$blocked = $this->install();
		$this->assertWPError( $blocked );
		$this->assertSame( 'wp_sudo_reauth_required', $blocked->get_error_code() );
		$this->assertDirectoryDoesNotExist( $this->destination );

		remove_filter( 'upgrader_pre_install', 'WP_Sudo\PoC\InstallPackageGate\gate_install_package', 10 );

		// Deliberately the SAME destination as the blocked call. Substituting a
		// fresh path would make this a different call, and the differential would
		// prove less than it claims.
		$allowed = $this->install();

		$this->assertNotWPError( $allowed, 'ungated, the identical call must succeed' );
		$this->assertDirectoryExists(
			$this->destination,
			'ungated, the package reaches the filesystem — which is what the gate prevents'
		);
	}

	/** Revoking is immediate — "log out everywhere" must close the window now. */
	public function test_revoking_a_proof_re_gates_the_sink(): void {
		$this->start_session( $this->admin_id );
		grant_proof();
		revoke_proof();

		$result = $this->install();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_sudo_reauth_required', $result->get_error_code() );
	}

	/**
	 * wp-admin resolves the current user from AUTH_COOKIE / SECURE_AUTH_COOKIE,
	 * but wp_get_session_token() reads LOGGED_IN_COOKIE unconditionally. A client
	 * presenting a valid admin auth cookie and no logged-in cookie is fully
	 * authenticated with full capabilities, yet has no readable token.
	 *
	 * Checking only LOGGED_IN_COOKIE classified that as actorless and allowed the
	 * write. Distinct from the revoked-session case: here the authenticating
	 * session is still perfectly valid.
	 */
	public function test_an_admin_auth_cookie_without_a_logged_in_cookie_is_not_actorless(): void {
		$expiry = time() + DAY_IN_SECONDS;
		$token  = WP_Session_Tokens::get_instance( $this->admin_id )->create( $expiry );

		// Authenticated by the admin cookie only.
		$_COOKIE[ AUTH_COOKIE ] = wp_generate_auth_cookie( $this->admin_id, $expiry, 'auth', $token );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );

		$result = $this->install();

		$this->assertWPError(
			$result,
			'an authenticated browser request must not be reclassified as actorless'
		);
		$this->assertSame( 'wp_sudo_reauth_required', $result->get_error_code() );
		$this->assertDirectoryDoesNotExist( $this->destination );
	}

	/**
	 * The seam-placement finding, and the most consequential thing this slice
	 * turned up.
	 *
	 * WP_Upgrader::run() calls unpack_package() — which extracts the archive into
	 * wp-content/upgrade/ — BEFORE install_package(). So gating only inside
	 * install_package() blocks the final move into the live plugin tree while
	 * still letting attacker-controlled PHP land in a directory many hosts
	 * execute PHP from.
	 *
	 * Gating upgrader_pre_download closes that: run() is refused before anything
	 * is downloaded or extracted.
	 */
	public function test_the_run_path_is_refused_before_anything_is_unpacked(): void {
		$this->start_session( $this->admin_id );

		$upgrade_dir = WP_CONTENT_DIR . '/upgrade';
		$before      = is_dir( $upgrade_dir ) ? glob( $upgrade_dir . '/*' ) : array();

		$upgrader = new WP_Upgrader( new Automatic_Upgrader_Skin() );
		$upgrader->init();

		// run() drives the skin's header/footer, which buffer output. Contain it
		// so the runner's strict output check stays meaningful elsewhere.
		$depth = ob_get_level();
		ob_start();
		$result = $upgrader->run(
			array(
				'package'     => $this->source . '/plugin.php',
				'destination' => $this->destination,
				'hook_extra'  => array( 'type' => 'plugin', 'action' => 'install' ),
			)
		);
		while ( ob_get_level() > $depth ) {
			ob_end_clean();
		}

		$this->assertWPError( $result );
		$this->assertSame(
			'wp_sudo_reauth_required',
			$result->get_error_code(),
			'run() must be refused at the download seam, before unpack_package()'
		);

		$after = is_dir( $upgrade_dir ) ? glob( $upgrade_dir . '/*' ) : array();
		$this->assertSame(
			$before,
			$after,
			'nothing may be extracted into wp-content/upgrade when the gate refuses'
		);
	}
}
