<?php
/**
 * Integration tests for Milestone B — the in-editor 2FA partial.
 *
 * Covers the shared 2FA renderer extraction (byte-identical guard), the
 * `handle_ajax_2fa_partial` endpoint (pending gate, provider capability
 * classification, TOTP markup), and the email-provider shared send throttle.
 *
 * Self-guarding: each test skips when the Two Factor plugin is not installed.
 *
 * @group two-factor
 * @covers \WP_Sudo\Challenge
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Challenge;
use WP_Sudo\Request_Stash;
use WP_Sudo\Sudo_Session;

class EditorTwoFactorPartialTest extends TestCase {

	/**
	 * Skip if the Two Factor plugin is not loaded.
	 */
	private function require_two_factor(): void {
		if ( ! class_exists( '\\Two_Factor_Core' ) ) {
			$this->markTestSkipped( 'Two Factor plugin not installed.' );
		}
	}

	/**
	 * Configure TOTP as the user's primary provider (mirrors TwoFactorTest).
	 *
	 * @param int $user_id User ID.
	 */
	private function configure_totp_for_user( int $user_id ): void {
		update_user_meta( $user_id, '_two_factor_enabled_providers', array( 'Two_Factor_Totp' ) );
		update_user_meta( $user_id, '_two_factor_provider', 'Two_Factor_Totp' );
		update_user_meta( $user_id, '_two_factor_totp_key', 'JBSWY3DPEHPK3PXP' );
	}

	/**
	 * Invoke a private/protected Challenge method, capturing its echoed output.
	 *
	 * @param Challenge $challenge The instance.
	 * @param string    $method    Method name.
	 * @param array     $args      Positional arguments.
	 * @return string The captured output.
	 */
	private function capture_private( Challenge $challenge, string $method, array $args ): string {
		$ref = new \ReflectionMethod( Challenge::class, $method );
		@$ref->setAccessible( true ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- PHP 8.0/8.5 suite pattern.
		ob_start();
		$ref->invokeArgs( $challenge, $args );
		return (string) ob_get_clean();
	}

	/**
	 * STEP 1 — the shared 2FA renderer produces the primary provider's markup.
	 *
	 * `render_two_factor_fields()` echoes the TOTP login field (`name="authcode"`,
	 * verified against class-two-factor-totp.php:776-800) and fires the
	 * `wp_sudo_render_two_factor_fields` extension hook.
	 */
	public function test_render_two_factor_fields_emits_primary_provider_markup_and_fires_hook(): void {
		$this->require_two_factor();

		$user = $this->make_admin();
		wp_set_current_user( $user->ID );
		$this->configure_totp_for_user( $user->ID );

		$fired = 0;
		$cb    = function ( $u ) use ( &$fired, $user ) {
			++$fired;
			$this->assertInstanceOf( \WP_User::class, $u );
			$this->assertSame( $user->ID, $u->ID );
		};
		add_action( 'wp_sudo_render_two_factor_fields', $cb );

		$challenge = new Challenge( new Request_Stash() );
		$output    = $this->capture_private( $challenge, 'render_two_factor_fields', array( get_userdata( $user->ID ) ) );

		remove_action( 'wp_sudo_render_two_factor_fields', $cb );

		$this->assertStringContainsString( 'name="authcode"', $output, 'The TOTP login field must be present.' );
		$this->assertSame( 1, $fired, 'The wp_sudo_render_two_factor_fields hook must fire exactly once with the user.' );
	}

	/**
	 * STEP 1 — regression guard: the extraction is byte-identical.
	 *
	 * `render_page()` must embed `render_two_factor_fields()`'s exact output
	 * verbatim inside its 2FA form, proving the extraction did not alter the
	 * full-page challenge's 2FA markup.
	 */
	public function test_render_page_embeds_shared_renderer_output_verbatim(): void {
		$this->require_two_factor();

		$user = $this->make_admin();
		wp_set_current_user( $user->ID );
		$this->configure_totp_for_user( $user->ID );

		// Session-only mode: no stash_key in $_GET.
		unset( $_GET['stash_key'], $_GET['return_url'] );

		$challenge = new Challenge( new Request_Stash() );

		$fields = $this->capture_private( $challenge, 'render_two_factor_fields', array( get_userdata( $user->ID ) ) );
		$this->assertNotSame( '', $fields, 'The shared renderer must produce output for a TOTP user.' );

		ob_start();
		$challenge->render_page();
		$page = (string) ob_get_clean();

		$this->assertStringContainsString(
			$fields,
			$page,
			'render_page() must embed the shared renderer output verbatim (byte-identical extraction).'
		);
	}

	// -----------------------------------------------------------------
	// STEP 2 — capability classifier + handle_ajax_2fa_partial endpoint
	// -----------------------------------------------------------------

	/**
	 * Call the private static capability classifier via reflection.
	 *
	 * @param mixed $provider Provider object or null.
	 * @return bool
	 */
	private function is_modal_capable( $provider ): bool {
		$ref = new \ReflectionMethod( Challenge::class, 'is_modal_capable_2fa' );
		@$ref->setAccessible( true ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- PHP 8.0/8.5 suite pattern.
		return (bool) $ref->invoke( null, $provider );
	}

	/**
	 * Invoke handle_ajax_2fa_partial() in an AJAX context and return decoded JSON.
	 *
	 * @return array Decoded JSON response.
	 */
	private function call_partial(): array {
		$_POST['_ajax_nonce']    = wp_create_nonce( Challenge::NONCE_ACTION );
		$_REQUEST['_ajax_nonce'] = $_POST['_ajax_nonce'];
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );

		$challenge = new Challenge( new Request_Stash() );

		ob_start();
		try {
			$challenge->handle_ajax_2fa_partial();
			$this->fail( 'Expected WPDieException from wp_send_json.' );
		} catch ( \WPDieException $e ) {
			$this->addToAssertionCount( 1 );
		} finally {
			remove_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
			remove_filter( 'wp_doing_ajax', '__return_true' );
			$out = (string) ob_get_clean();
		}

		$json = json_decode( $out, true );
		$this->assertIsArray( $json, 'Partial handler output must be valid JSON.' );
		return $json;
	}

	/**
	 * STEP 2 — the classifier is default-deny: only the built-in OTP providers
	 * (TOTP, email, backup codes) are modal-capable; a null (no/hook-only
	 * provider) and the Dummy provider are not (Q-B1).
	 */
	public function test_is_modal_capable_2fa_default_deny_allowlist(): void {
		$this->require_two_factor();

		$this->assertTrue( $this->is_modal_capable( \Two_Factor_Totp::get_instance() ), 'TOTP is modal-capable.' );
		$this->assertTrue( $this->is_modal_capable( \Two_Factor_Email::get_instance() ), 'Email OTP is modal-capable.' );
		$this->assertTrue( $this->is_modal_capable( \Two_Factor_Backup_Codes::get_instance() ), 'Backup codes are modal-capable.' );

		$this->assertFalse( $this->is_modal_capable( null ), 'No provider (hook-only path) is not modal-capable.' );
		$this->assertFalse( $this->is_modal_capable( \Two_Factor_Dummy::get_instance() ), 'Dummy provider is not modal-capable.' );
	}

	/**
	 * STEP 2 — the partial 403s when there is no 2fa_pending state.
	 *
	 * A user who has not passed the password step (no pending transient/cookie)
	 * can never obtain rendered 2FA fields. Uncircumventable gate.
	 */
	public function test_partial_403s_without_pending(): void {
		$this->require_two_factor();

		$user = $this->make_admin();
		wp_set_current_user( $user->ID );
		$this->configure_totp_for_user( $user->ID );
		// NOTE: no attempt_activation() — no pending state.

		$json = $this->call_partial();

		$this->assertFalse( $json['success'], 'No pending → error response.' );
	}

	/**
	 * STEP 2 — a TOTP-primary user with valid pending gets the provider markup.
	 */
	public function test_partial_returns_totp_markup_for_totp_primary(): void {
		$this->require_two_factor();

		$password = 'correct-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );
		$this->configure_totp_for_user( $user->ID );

		$pending = Sudo_Session::attempt_activation( $user->ID, $password );
		$this->assertSame( '2fa_pending', $pending['code'] );

		$json = $this->call_partial();

		$this->assertTrue( $json['success'], 'Capable provider with pending → success.' );
		$this->assertSame( 'partial', $json['data']['code'] ?? null );
		$this->assertStringContainsString( 'name="authcode"', $json['data']['html'] ?? '', 'TOTP login field must be in the partial.' );

		Sudo_Session::clear_2fa_pending();
	}

	/**
	 * STEP 2 — a 2FA requirement forced by the hook filter with NO classifiable
	 * Two_Factor provider links out (never blindly echoes hook output).
	 */
	public function test_partial_links_out_for_hook_only_no_provider(): void {
		$this->require_two_factor();

		$password = 'correct-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );
		// No provider meta — the filter alone forces the 2FA requirement.
		add_filter( 'wp_sudo_requires_two_factor', '__return_true' );

		$pending = Sudo_Session::attempt_activation( $user->ID, $password );
		$this->assertSame( '2fa_pending', $pending['code'], 'Filter-only 2FA still lands in pending.' );

		$json = $this->call_partial();

		remove_filter( 'wp_sudo_requires_two_factor', '__return_true' );

		$this->assertTrue( $json['success'], 'link_out is a success-shaped response.' );
		$this->assertSame( 'link_out', $json['data']['code'] ?? null );
		$this->assertArrayNotHasKey( 'html', $json['data'], 'link_out must carry no markup.' );

		Sudo_Session::clear_2fa_pending();
	}

	/**
	 * STEP 2 — a non-allowlisted provider (Dummy stands in for WebAuthn/unknown)
	 * links out rather than injecting a dead ceremony.
	 */
	public function test_partial_links_out_for_non_capable_provider(): void {
		$this->require_two_factor();

		$password = 'correct-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );
		update_user_meta( $user->ID, '_two_factor_enabled_providers', array( 'Two_Factor_Dummy' ) );
		update_user_meta( $user->ID, '_two_factor_provider', 'Two_Factor_Dummy' );

		$pending = Sudo_Session::attempt_activation( $user->ID, $password );
		$this->assertSame( '2fa_pending', $pending['code'] );

		$json = $this->call_partial();

		$this->assertTrue( $json['success'] );
		$this->assertSame( 'link_out', $json['data']['code'] ?? null );

		Sudo_Session::clear_2fa_pending();
	}

	/**
	 * STEP 2 — the wp_sudo_render_two_factor_fields extension hook fires in the
	 * new admin-ajax context without breaking the endpoint (a plain-field
	 * callback's output is included in the returned partial markup).
	 */
	public function test_partial_render_hook_fires_in_ajax_context(): void {
		$this->require_two_factor();

		$password = 'correct-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );
		$this->configure_totp_for_user( $user->ID );

		Sudo_Session::attempt_activation( $user->ID, $password );

		$marker = 'wp-sudo-test-extra-2fa-field';
		$cb     = function () use ( $marker ) {
			echo '<input type="text" name="' . esc_attr( $marker ) . '" />';
		};
		add_action( 'wp_sudo_render_two_factor_fields', $cb );

		$json = $this->call_partial();

		remove_action( 'wp_sudo_render_two_factor_fields', $cb );

		$this->assertTrue( $json['success'] );
		$this->assertSame( 'partial', $json['data']['code'] ?? null );
		$this->assertStringContainsString( $marker, $json['data']['html'] ?? '', 'The extension hook must fire in the ajax partial context.' );

		Sudo_Session::clear_2fa_pending();
	}

	// -----------------------------------------------------------------
	// STEP 3 — email-provider shared send throttle (§3a HIGH finding)
	// -----------------------------------------------------------------

	/**
	 * Configure Two Factor Email as the user's primary provider.
	 *
	 * @param int $user_id User ID.
	 */
	private function configure_email_for_user( int $user_id ): void {
		update_user_meta( $user_id, '_two_factor_enabled_providers', array( 'Two_Factor_Email' ) );
		update_user_meta( $user_id, '_two_factor_provider', 'Two_Factor_Email' );
	}

	/**
	 * STEP 3 — the email partial render SHARES the wp_sudo_resend_<id> throttle.
	 *
	 * `Two_Factor_Email::authentication_page()` sends an OTP on render when no
	 * valid token exists. That send must count against the SAME counter the
	 * validate path (`handle_ajax_2fa`) uses, and a re-fetch inside the token TTL
	 * (which does NOT re-send) must NOT increment — so a user is never locked out
	 * of a code they already hold.
	 */
	public function test_partial_email_render_shares_resend_throttle_and_counts_only_real_sends(): void {
		$this->require_two_factor();

		$password = 'correct-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );
		$this->configure_email_for_user( $user->ID );

		Sudo_Session::attempt_activation( $user->ID, $password );

		$resend_key = 'wp_sudo_resend_' . $user->ID;
		$this->assertFalse( get_transient( $resend_key ), 'No resend counter before the first fetch.' );

		// First fetch: no valid token yet → a real send → counter increments to 1.
		$first = $this->call_partial();
		$this->assertTrue( $first['success'] );
		$this->assertSame( 'partial', $first['data']['code'] ?? null );
		$this->assertSame( 1, (int) get_transient( $resend_key ), 'The render-time send must count against the shared throttle.' );

		// Second fetch inside the 15-min token TTL: no send → counter unchanged.
		$second = $this->call_partial();
		$this->assertTrue( $second['success'] );
		$this->assertSame( 'partial', $second['data']['code'] ?? null );
		$this->assertSame(
			1,
			(int) get_transient( $resend_key ),
			'A re-fetch with a still-valid token must NOT re-send or re-count (no lockout of a held code).'
		);

		Sudo_Session::clear_2fa_pending();
	}

	/**
	 * STEP 3 — when the shared throttle is maxed AND a send would fire, the email
	 * partial returns resend_throttled WITHOUT rendering (no unbounded mail-send).
	 */
	public function test_partial_email_send_blocked_when_throttle_maxed(): void {
		$this->require_two_factor();

		$password = 'correct-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );
		$this->configure_email_for_user( $user->ID );

		Sudo_Session::attempt_activation( $user->ID, $password );

		// Simulate three prior sends within the window; the user holds no valid
		// token (fresh), so the next render WOULD send — and must be blocked.
		set_transient( 'wp_sudo_resend_' . $user->ID, 3, 5 * MINUTE_IN_SECONDS );

		$json = $this->call_partial();

		$this->assertFalse( $json['success'], 'A maxed send throttle → error, no render.' );
		$this->assertSame( 'resend_throttled', $json['data']['code'] ?? null );
		$this->assertArrayNotHasKey( 'html', $json['data'], 'No 2FA markup is rendered when the send is throttled.' );

		Sudo_Session::clear_2fa_pending();
	}
}
