<?php
/**
 * Tests for Request_Stash.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Request_Stash;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers \WP_Sudo\Request_Stash
 */
class RequestStashTest extends TestCase {

	/**
	 * Instance under test.
	 *
	 * @var Request_Stash
	 */
	private Request_Stash $stash;

	protected function setUp(): void {
		parent::setUp();
		$this->stash = new Request_Stash();
	}

	/**
	 * Stub per-user stash index meta I/O for tests that do not assert it.
	 *
	 * @return void
	 */
	private function stub_stash_index_meta_io(): void {
		Functions\when( 'get_user_meta' )->justReturn( array() );
		Functions\when( 'update_user_meta' )->justReturn( true );
	}

	/**
	 * Build a rule with POST replay allowlist metadata.
	 *
	 * @param string   $id          Rule ID.
	 * @param string   $label       Rule label.
	 * @param string[] $post_fields Allowed top-level POST fields.
	 * @return array<string, mixed>
	 */
	private function rule_with_post_fields( string $id, string $label, array $post_fields ): array {
		return array(
			'id'    => $id,
			'label' => $label,
			'stash' => array(
				'post_mode'   => 'allowlist',
				'post_fields' => $post_fields,
			),
		);
	}

	/**
	 * Test save() stores data and returns a key.
	 */
	public function test_save_returns_key(): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/plugins.php?action=activate&plugin=hello.php';

		Functions\expect( 'wp_generate_password' )
			->once()
			->with( 16, false )
			->andReturn( 'abc123def456ghij' );

		Functions\expect( 'is_ssl' )
			->once()
			->andReturn( true );

		Functions\when( 'esc_url_raw' )->returnArg();

		Functions\expect( 'set_transient' )
			->once()
			->with(
				Request_Stash::TRANSIENT_PREFIX . 'abc123def456ghij',
				\Mockery::type( 'array' ),
				Request_Stash::TTL
			)
			->andReturn( true );

		$key = $this->stash->save( 1, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		$this->assertSame( 'abc123def456ghij', $key );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
	}

	/**
	 * #322 v2 (BLOCKER 1): no binding is minted for a cross-site-initiated request.
	 *
	 * WordPress nonces are bound to the session token, NOT the browser, so an attacker
	 * holding a stolen login cookie can mint a valid nonce and lure the victim into
	 * issuing the gated request. If we minted a binding then, it would land in the
	 * VICTIM's browser and the victim's own reauth would release the attacker's
	 * action. Anything other than `Sec-Fetch-Site: same-origin` must fail closed.
	 *
	 * @dataProvider non_same_origin_fetch_sites
	 */
	public function test_save_does_not_bind_unless_same_origin_initiated( ?string $fetch_site ): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/plugins.php?action=activate&plugin=evil.php';

		if ( null === $fetch_site ) {
			unset( $_SERVER['HTTP_SEC_FETCH_SITE'] );
		} else {
			$_SERVER['HTTP_SEC_FETCH_SITE'] = $fetch_site;
		}

		// CRITICAL: under PHPUnit CLI headers_sent() is always true (progress dots),
		// which would short-circuit mint_binding_proof() at the headers guard and make
		// this assertion pass for the WRONG reason. Stub it false so the Sec-Fetch-Site
		// guard is genuinely the thing under test.
		Functions\when( 'headers_sent' )->justReturn( false );
		Functions\when( 'setcookie' )->justReturn( true );
		Functions\when( 'wp_generate_password' )->justReturn( 'abc123def456ghij' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'force_ssl_admin' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'sanitize_text_field' )->returnArg();

		$stored = null;
		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				function ( $key, $value ) use ( &$stored ) {
					$stored = $value;
					return true;
				}
			);

		$this->stash->save( 1, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		$this->assertSame(
			'',
			$stored['binding_hash'] ?? 'MISSING',
			'A lured (non-same-origin) request must not mint a browser binding.'
		);

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $_SERVER['HTTP_SEC_FETCH_SITE'] );
	}

	/**
	 * #322 v2: a genuine same-origin request DOES mint a binding.
	 *
	 * The positive counterpart to the lure test above. Without this, deleting the
	 * Sec-Fetch-Site guard entirely would go undetected — every negative case would
	 * still pass because nothing asserts the guard ever lets anything through.
	 */
	public function test_save_binds_when_same_origin_initiated(): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD']      = 'GET';
		$_SERVER['HTTP_HOST']           = 'example.com';
		$_SERVER['REQUEST_URI']         = '/wp-admin/plugins.php?action=activate&plugin=hello.php';
		$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';

		Functions\when( 'headers_sent' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'abc123def456ghij' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'force_ssl_admin' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'sanitize_text_field' )->returnArg();

		$cookie = array();
		Functions\when( 'setcookie' )->alias(
			function ( $name, $value, $options ) use ( &$cookie ) {
				$cookie = array(
					'name'    => $name,
					'value'   => $value,
					'options' => $options,
				);
				return true;
			}
		);

		$stored = null;
		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				function ( $key, $value ) use ( &$stored ) {
					$stored = $value;
					return true;
				}
			);

		$this->stash->save( 1, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		$this->assertNotSame( '', $stored['binding_hash'] ?? '', 'A same-origin request must mint a binding.' );

		// The cookie carries the PLAINTEXT secret; the stash stores only its hash.
		$this->assertSame( Request_Stash::BINDING_COOKIE, $cookie['name'] ?? null );
		$this->assertStringStartsWith( '__Host-', (string) ( $cookie['name'] ?? '' ), 'Must be __Host- prefixed (host-only, no Domain).' );
		$this->assertSame( hash( 'sha256', (string) ( $cookie['value'] ?? '' ) ), $stored['binding_hash'] );
		$this->assertNotSame( $cookie['value'] ?? '', $stored['binding_hash'], 'The plaintext secret must never be persisted.' );

		// __Host- requires path=/ and no domain; the proof must not be script-readable.
		$this->assertSame( '/', $cookie['options']['path'] ?? null );
		$this->assertArrayNotHasKey( 'domain', $cookie['options'] ?? array() );
		$this->assertTrue( $cookie['options']['secure'] ?? false );
		$this->assertTrue( $cookie['options']['httponly'] ?? false );
		$this->assertSame( 'Strict', $cookie['options']['samesite'] ?? null );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $_SERVER['HTTP_SEC_FETCH_SITE'] );
	}

	/**
	 * Fetch-site values that must NOT produce a binding.
	 *
	 * @return array<string, array{0: string|null}>
	 */
	public static function non_same_origin_fetch_sites(): array {
		return array(
			'cross-site'    => array( 'cross-site' ),
			'same-site'     => array( 'same-site' ),
			'none'          => array( 'none' ),
			'absent header' => array( null ),
		);
	}

	/**
	 * #322 v2: the stash records the concrete target for informed confirmation.
	 */
	public function test_save_captures_target_for_informed_confirmation(): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/plugins.php?action=activate&plugin=evil%2Fevil.php';
		$_GET['plugin']            = 'evil/evil.php';

		Functions\when( 'wp_generate_password' )->justReturn( 'abc123def456ghij' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();

		$stored = null;
		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				function ( $key, $value ) use ( &$stored ) {
					$stored = $value;
					return true;
				}
			);

		$this->stash->save( 1, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		$this->assertSame(
			'evil/evil.php',
			$stored['target']['plugin'] ?? null,
			'The challenge must be able to name WHAT is being authorized.'
		);

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $_GET['plugin'] );
	}

	/**
	 * #322 F4: a target param listed as sensitive is NOT recorded.
	 *
	 * capture_target() bypasses the per-rule POST allowlist, so it must run values
	 * through the same filterable sensitive-key check that redaction uses.
	 */
	public function test_capture_target_skips_sensitive_params(): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/options.php';
		$_GET['option']            = 'super-secret-value';
		$_GET['plugin']            = 'hello.php';

		Functions\when( 'wp_generate_password' )->justReturn( 'abc123def456ghij' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		// A site adds 'option' to the sensitive list via the documented filter.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wp_sudo_sensitive_stash_keys' === $hook ) {
					return array( 'option' );
				}
				return $value;
			}
		);

		$stored = null;
		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				function ( $key, $value ) use ( &$stored ) {
					$stored = $value;
					return true;
				}
			);

		$this->stash->save( 1, array( 'id' => 'options.general', 'label' => 'Change settings' ) );

		$this->assertArrayNotHasKey( 'option', $stored['target'], 'A sensitive param must not be recorded as a target.' );
		$this->assertSame( 'hello.php', $stored['target']['plugin'] ?? null, 'Non-sensitive params are still captured.' );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $_GET['option'], $_GET['plugin'] );
	}

	/**
	 * #322 F5: truncation must not split a UTF-8 sequence.
	 *
	 * substr() can cut mid-codepoint; esc_html() then returns '' via
	 * wp_check_invalid_utf8, silently blanking the Target line — i.e. removing the
	 * primary control with no error. The stored value must survive escaping.
	 */
	public function test_capture_target_truncates_multibyte_safely(): void {
		$this->stub_stash_index_meta_io();

		// A THREE-byte character is required to make this test bite: the cap is 100,
		// so a 2-byte char (é) would have substr() cut exactly on a boundary and stay
		// valid UTF-8. At 3 bytes, byte 100 lands mid-codepoint (33 chars = 99 bytes),
		// so a byte-wise substr() produces invalid UTF-8 and esc_html() blanks it.
		$long = str_repeat( 'あ', 120 );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/plugins.php';
		$_GET['plugin']            = $long;

		Functions\when( 'wp_generate_password' )->justReturn( 'abc123def456ghij' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$stored = null;
		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				function ( $key, $value ) use ( &$stored ) {
					$stored = $value;
					return true;
				}
			);

		$this->stash->save( 1, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		$captured = $stored['target']['plugin'] ?? '';

		$this->assertNotSame( '', $captured );
		$this->assertTrue(
			mb_check_encoding( $captured, 'UTF-8' ),
			'Truncated target must remain valid UTF-8 or esc_html() will blank the Target line.'
		);

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $_GET['plugin'] );
	}

	/**
	 * #322: bulk actions send arrays — the target must name the accounts, not vanish.
	 *
	 * Discarding array values left the confirmation EMPTY exactly where the action is
	 * most destructive (bulk user delete / role change).
	 */
	public function test_capture_target_records_array_values(): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/users.php';
		$_POST['users']            = array( '5', '6', '7' );

		$this->stub_target_env();

		$stored = null;
		Functions\expect( 'set_transient' )->once()->andReturnUsing(
			function ( $key, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);

		$this->stash->save( 1, array( 'id' => 'user.delete', 'label' => 'Delete user' ) );

		$this->assertSame( '5, 6, 7', $stored['target']['users'] ?? null, 'Bulk targets must be named.' );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $_POST['users'] );
	}

	/**
	 * #322: the confirmation must name the value that will actually be replayed.
	 *
	 * A POST replay submits the stashed BODY, so reading the query first would let
	 * `?plugin=benign.php` with a body of `plugin=evil.php` display the benign value
	 * while the other is replayed — defeating informed confirmation entirely. The body
	 * wins, and a disagreeing query value is surfaced rather than hidden.
	 */
	public function test_capture_target_prefers_the_replayed_value_and_flags_conflicts(): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/plugins.php?plugin=benign.php';
		$_GET['plugin']            = 'benign.php';
		$_POST['plugin']           = 'evil.php';

		$this->stub_target_env();

		$stored = null;
		Functions\expect( 'set_transient' )->once()->andReturnUsing(
			function ( $key, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);

		$this->stash->save( 1, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		$shown = $stored['target']['plugin'] ?? '';

		$this->assertStringContainsString( 'evil.php', $shown, 'Must name the value that will be replayed.' );
		$this->assertStringContainsString( 'benign.php', $shown, 'A conflicting value must be surfaced, not hidden.' );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $_GET['plugin'], $_POST['plugin'] );
	}

	/**
	 * #322: critical option names must appear in the confirmation.
	 *
	 * options.critical stashes siteurl/home/admin_email/default_role; without them the
	 * most dangerous settings change would render an empty target.
	 */
	public function test_capture_target_records_critical_option_names(): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/options.php';
		$_POST['siteurl']          = 'https://evil.example';
		$_POST['admin_email']      = 'attacker@example.com';

		// Stored values differ from what is submitted, so this asserts that a REAL
		// change is named — not merely that any present field is echoed back.
		$this->stub_target_env(
			array(
				'siteurl'     => 'https://example.com',
				'admin_email' => 'owner@example.com',
			)
		);

		$stored = null;
		Functions\expect( 'set_transient' )->once()->andReturnUsing(
			function ( $key, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);

		$this->stash->save( 1, array( 'id' => 'options.critical', 'label' => 'Change critical settings' ) );

		$this->assertSame( 'https://evil.example', $stored['target']['siteurl'] ?? null );
		$this->assertSame( 'attacker@example.com', $stored['target']['admin_email'] ?? null );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $_POST['siteurl'], $_POST['admin_email'] );
	}

	/**
	 * #322: the no-mbstring fallback must still produce valid UTF-8.
	 *
	 * mbstring is optional even on supported PHP; a byte-wise cut would blank the
	 * Target line via esc_html()/wp_check_invalid_utf8.
	 */
	public function test_truncate_target_value_is_utf8_safe_without_mbstring(): void {
		\Patchwork\redefine(
			'function_exists',
			function ( string $name ) {
				return 'mb_substr' === $name ? false : \Patchwork\relay();
			}
		);

		$method = new \ReflectionMethod( $this->stash, 'truncate_target_value' );
		@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$out = $method->invoke( $this->stash, str_repeat( 'あ', 120 ) );

		$this->assertNotSame( '', $out );
		$this->assertTrue( mb_check_encoding( $out, 'UTF-8' ), 'Fallback truncation must stay valid UTF-8.' );
	}

	/**
	 * Shared stubs for target-capture tests.
	 */
	private function stub_target_env( array $stored_options = array() ): void {
		Functions\when( 'wp_generate_password' )->justReturn( 'abc123def456ghij' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// Default false = "option unreadable", which must never be read as unchanged.
		Functions\when( 'get_option' )->alias(
			function ( $name ) use ( $stored_options ) {
				return $stored_options[ $name ] ?? false;
			}
		);
	}

	/**
	 * The field set wp-admin/options-general.php submits, single site.
	 *
	 * The administration email input is named `new_admin_email`, not `admin_email`
	 * (see GB-ADMIN-EMAIL-FIELD in docs/upstream-sources.md), and siteurl/home/
	 * users_can_register/default_role are single-site only
	 * (see GB-OPTIONS-GENERAL-MULTISITE).
	 *
	 * @return array<string, string>
	 */
	private function options_general_payload(): array {
		return array(
			'option_page'        => 'general',
			'action'             => 'update',
			'_wpnonce'           => 'deadbeef',
			'_wp_http_referer'   => '/wp-admin/options-general.php',
			'blogname'           => 'A Brand New Site Title',
			'blogdescription'    => 'Just another site',
			'siteurl'            => 'https://example.com',
			'home'               => 'https://example.com',
			'new_admin_email'    => 'admin@example.com',
			'users_can_register' => '1',
			'default_role'       => 'subscriber',
			'timezone_string'    => 'UTC',
			'submit'             => 'Save Changes',
		);
	}

	/**
	 * The stored options matching options_general_payload() with nothing changed.
	 *
	 * @return array<string, string>
	 */
	private function options_general_stored(): array {
		return array(
			'siteurl'            => 'https://example.com',
			'home'               => 'https://example.com',
			'admin_email'        => 'admin@example.com',
			'users_can_register' => '1',
			'default_role'       => 'subscriber',
		);
	}

	/**
	 * Run a stash save and return the stored stash payload.
	 *
	 * @param array<string, string> $stored_options Stored option values.
	 * @return array<string, mixed>
	 */
	private function save_options_critical_stash( array $stored_options ): array {
		$this->stub_stash_index_meta_io();
		$this->stub_target_env( $stored_options );

		$stored = null;
		Functions\expect( 'set_transient' )->once()->andReturnUsing(
			function ( $key, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);

		$this->stash->save( 1, $this->options_critical_rule() );

		return (array) $stored;
	}

	/**
	 * The live options.critical rule, so tests track the registry rather than a copy.
	 *
	 * @return array<string, mixed>
	 */
	private function options_critical_rule(): array {
		foreach ( \WP_Sudo\Action_Registry::get_rules() as $rule ) {
			if ( 'options.critical' === ( $rule['id'] ?? '' ) ) {
				return $rule;
			}
		}

		$this->fail( 'options.critical rule not found in the registry.' );
	}

	/**
	 * #431: a settings save that changes nothing critical must not claim one did.
	 *
	 * options-general.php posts every critical option on every save, so capturing by
	 * presence rendered "Target: default_role: subscriber" when only the Site Title
	 * changed — a confirmation asserting a change that was not happening.
	 */
	public function test_options_general_save_does_not_name_untouched_critical_options(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/options.php';
		$_POST                     = $this->options_general_payload();

		$stored = $this->save_options_critical_stash( $this->options_general_stored() );

		$this->assertSame(
			array(),
			$stored['target'],
			'No critical option changed, so the confirmation must name none of them.'
		);

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		$_POST = array();
	}

	/**
	 * #431: the administration email address must be named when it really changes.
	 *
	 * Core posts `new_admin_email`, never `admin_email`, so capturing only the latter
	 * left the most dangerous change on the page with no target at all.
	 */
	public function test_capture_target_names_a_real_admin_email_change(): void {
		$_SERVER['REQUEST_METHOD']   = 'POST';
		$_SERVER['HTTP_HOST']        = 'example.com';
		$_SERVER['REQUEST_URI']      = '/wp-admin/options.php';
		$_POST                       = $this->options_general_payload();
		$_POST['new_admin_email']    = 'attacker@evil.example';

		$stored = $this->save_options_critical_stash( $this->options_general_stored() );

		$this->assertSame(
			'attacker@evil.example',
			$stored['target']['new_admin_email'] ?? null,
			'An administration email takeover must be named in the confirmation.'
		);
		$this->assertArrayNotHasKey( 'default_role', $stored['target'] );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		$_POST = array();
	}

	/**
	 * #431: an unchanged new_admin_email compares against the CURRENT admin_email.
	 *
	 * The field is prefilled with form_option( 'admin_email' ), so comparing it with
	 * the (normally empty) new_admin_email option would report every save as a change.
	 */
	public function test_capture_target_skips_new_admin_email_echoing_current_address(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/options.php';
		$_POST                     = $this->options_general_payload();

		$stored = $this->save_options_critical_stash( $this->options_general_stored() );

		$this->assertArrayNotHasKey( 'new_admin_email', $stored['target'] );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		$_POST = array();
	}

	/**
	 * #431 negative: an unreadable option must never be treated as "unchanged".
	 *
	 * get_option() returns false when the option is absent. Reading that as a match
	 * would HIDE a real change, which is the dangerous direction.
	 */
	public function test_capture_target_records_value_when_stored_option_is_unreadable(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/options.php';
		$_POST                     = $this->options_general_payload();
		$_POST['default_role']     = '0';

		// Every option absent: get_option() returns false throughout.
		$stored = $this->save_options_critical_stash( array() );

		$this->assertSame( '0', $stored['target']['default_role'] ?? null );
		$this->assertSame( 'https://example.com', $stored['target']['siteurl'] ?? null );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		$_POST = array();
	}

	/**
	 * #431 negative: a query/body disagreement must survive the unchanged check.
	 *
	 * If the body matches the stored value but the query carries another, the request
	 * still contains a value the user is not being shown.
	 */
	public function test_capture_target_surfaces_conflict_even_when_body_matches_stored(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/options.php';
		$_POST                     = $this->options_general_payload();
		$_GET['siteurl']           = 'https://evil.example';

		$stored = $this->save_options_critical_stash( $this->options_general_stored() );

		$shown = $stored['target']['siteurl'] ?? '';
		$this->assertStringContainsString(
			'evil.example',
			$shown,
			'A conflicting value must not be hidden by the unchanged check.'
		);

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $_GET['siteurl'] );
		$_POST = array();
	}

	/**
	 * #431 negative: an array submission must not be skipped or fatal.
	 */
	public function test_capture_target_records_array_valued_critical_option(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/options.php';
		$_POST                     = $this->options_general_payload();
		$_POST['home']             = array( 'https://example.com', 'https://evil.example' );

		$stored = $this->save_options_critical_stash( $this->options_general_stored() );

		$this->assertStringContainsString( 'evil.example', $stored['target']['home'] ?? '' );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		$_POST = array();
	}

	/**
	 * #431: a critical settings save is never replayed, however complete the target.
	 *
	 * options.php writes EVERY option in $allowed_options[$option_page], passing null
	 * for any the POST omits, so replaying an allowlisted subset would blank the rest
	 * of the settings page rather than reproduce the save. Pin the non-replayable
	 * contract here: even when every critical option changed and the target names all
	 * of them, no body is stashed and replay stays blocked.
	 */
	public function test_critical_settings_save_is_never_replayed(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/options.php';
		$_POST                     = array_merge(
			$this->options_general_payload(),
			array(
				'siteurl'            => 'https://evil.example',
				'home'               => 'https://evil.example',
				'new_admin_email'    => 'attacker@evil.example',
				'default_role'       => 'administrator',
				'users_can_register' => '0',
			)
		);

		$stored = $this->save_options_critical_stash( $this->options_general_stored() );

		$this->assertCount( 5, $stored['target'], 'Every real change must still be named.' );
		$this->assertTrue(
			(bool) $stored['post_replay_blocked'],
			'A settings save must never be replayed: the omitted fields would be blanked.'
		);
		$this->assertSame(
			array(),
			$stored['post'],
			'No body may be stashed for an action that is not replayable.'
		);

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		$_POST = array();
	}

	/**
	 * #431: the unchanged check compares the value that REPLAYS, not the display form.
	 *
	 * sanitize_text_field() can reduce a hostile payload to something that equals the
	 * stored value. Comparing after sanitization would then treat a real change as
	 * unchanged and drop it from the confirmation while the raw value still went to
	 * update_option() — hiding exactly the change the Target line exists to name.
	 */
	public function test_unchanged_check_compares_before_sanitization(): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/options.php';
		$_POST                     = $this->options_general_payload();
		$_POST['siteurl']          = 'https://example.com<script>';

		Functions\when( 'wp_generate_password' )->justReturn( 'abc123def456ghij' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->alias(
			function ( $name ) {
				$stored = $this->options_general_stored();
				return $stored[ $name ] ?? false;
			}
		);
		// A NON-identity sanitizer: strips from the first '<', so the submitted value
		// sanitizes down to exactly the stored siteurl.
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $value ) {
				$cut = strpos( (string) $value, '<' );
				return false === $cut ? $value : substr( (string) $value, 0, $cut );
			}
		);

		$stored = null;
		Functions\expect( 'set_transient' )->once()->andReturnUsing(
			function ( $key, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);

		$this->stash->save( 1, $this->options_critical_rule() );

		$this->assertArrayHasKey(
			'siteurl',
			$stored['target'],
			'A change that only LOOKS unchanged after sanitization must still be named.'
		);

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		$_POST = array();
	}

	/**
	 * #431: no admin-email takeover may be compared away.
	 *
	 * The comparison alias is the one place a WRONG mapping would be quiet rather than
	 * loud: comparing `new_admin_email` against the stored `admin_email` is what stops
	 * every untouched save reading as a change, but the inverse mistake would let a
	 * real takeover compare equal and vanish from the confirmation. Only an address
	 * core itself treats as a no-op may be skipped — update_option_new_admin_email()
	 * returns early when the submitted value equals the current admin_email — so every
	 * value that differs from it, however close, must still be named.
	 *
	 * @dataProvider provide_admin_email_takeover_values
	 *
	 * @param string $submitted Submitted administration email address.
	 */
	public function test_admin_email_takeover_is_always_named( string $submitted ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/options.php';
		$_POST                     = $this->options_general_payload();
		$_POST['new_admin_email']  = $submitted;

		$stored = $this->save_options_critical_stash( $this->options_general_stored() );

		$this->assertArrayHasKey(
			'new_admin_email',
			$stored['target'],
			'An address differing from the stored admin_email must be named: ' . $submitted
		);

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		$_POST = array();
	}

	/**
	 * Addresses that differ from the stored admin@example.com.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function provide_admin_email_takeover_values(): array {
		return array(
			'outright takeover'   => array( 'attacker@evil.example' ),
			'case variation'      => array( 'Admin@Example.com' ),
			'subaddress'          => array( 'admin+attacker@example.com' ),
			'lookalike domain'    => array( 'admin@examp1e.com' ),
			'trailing whitespace' => array( 'admin@example.com ' ),
			'unicode homoglyph'   => array( 'admin@exampłe.com' ),
		);
	}

	/**
	 * #431: narrowing wp_sudo_critical_options must not reinstate the false target.
	 *
	 * Whether an option is GATED and whether a displayed value is TRUE are separate
	 * questions. A site that narrows the protection set to new_admin_email still posts
	 * unchanged siteurl/home/default_role/users_can_register from the same form, and
	 * those keys stay in TARGET_PARAMS — so a comparison map derived from the filtered
	 * list would leave them uncomparable and name them as changes again, reinstating
	 * this bug on exactly the sites that customised their protection set.
	 */
	public function test_narrowed_critical_options_filter_does_not_restore_false_target(): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/options.php';
		$_POST                     = $this->options_general_payload();
		$_POST['new_admin_email']  = 'attacker@evil.example';

		Functions\when( 'wp_generate_password' )->justReturn( 'abc123def456ghij' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'get_option' )->alias(
			function ( $name ) {
				$stored = $this->options_general_stored();
				return $stored[ $name ] ?? false;
			}
		);
		// A site that gates ONLY the administration email address.
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return 'wp_sudo_critical_options' === $hook ? array( 'new_admin_email' ) : $value;
			}
		);

		$stored = null;
		Functions\expect( 'set_transient' )->once()->andReturnUsing(
			function ( $key, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);

		$this->stash->save( 1, $this->options_critical_rule() );

		$this->assertSame(
			array( 'new_admin_email' => 'attacker@evil.example' ),
			$stored['target'],
			'Only the changed option may be named, whatever the site chose to gate.'
		);

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		$_POST = array();
	}

	/**
	 * #431: a non-options rule keeps naming its own target.
	 */
	public function test_capture_target_unaffected_for_non_option_rules(): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/plugins.php';
		$_GET['plugin']            = 'akismet/akismet.php';
		$_GET['home']              = 'https://example.com';

		$this->stub_target_env( $this->options_general_stored() );

		$stored = null;
		Functions\expect( 'set_transient' )->once()->andReturnUsing(
			function ( $key, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);

		$this->stash->save( 1, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		$this->assertSame( 'akismet/akismet.php', $stored['target']['plugin'] ?? null );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $_GET['plugin'], $_GET['home'] );
	}

	/**
	 * Test save() still returns the key when transient storage fails.
	 */
	public function test_save_returns_key_when_set_transient_fails(): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/plugins.php?action=activate&plugin=hello.php';

		Functions\expect( 'wp_generate_password' )
			->once()
			->with( 16, false )
			->andReturn( 'failedtransient01' );

		Functions\expect( 'is_ssl' )
			->once()
			->andReturn( false );

		Functions\when( 'esc_url_raw' )->returnArg();

		Functions\expect( 'set_transient' )
			->once()
			->andReturn( false );

		$key = $this->stash->save( 1, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		$this->assertSame( 'failedtransient01', $key );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
	}

	/**
	 * Test save() serializes the full request data.
	 */
	public function test_save_stores_correct_data(): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/plugins.php';
		$_GET['action']            = 'activate';
		$_POST['plugin']           = 'hello.php';

		Functions\expect( 'wp_generate_password' )
			->once()
			->andReturn( 'testkey123456789' );

		Functions\expect( 'is_ssl' )
			->once()
			->andReturn( false );

		Functions\when( 'esc_url_raw' )->returnArg();

		$stored_data = null;

		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				function ( $name, $data, $ttl ) use ( &$stored_data ) {
					$stored_data = $data;
					return true;
				}
			);

		$this->stash->save( 42, $this->rule_with_post_fields( 'plugin.activate', 'Activate plugin', array( 'plugin' ) ) );

		$this->assertSame( 42, $stored_data['user_id'] );
		$this->assertSame( 'plugin.activate', $stored_data['rule_id'] );
		$this->assertSame( 'Activate plugin', $stored_data['label'] );
		$this->assertSame( 'POST', $stored_data['method'] );
		$this->assertSame( 'http://example.com/wp-admin/plugins.php', $stored_data['url'] );
		$this->assertArrayNotHasKey( 'get', $stored_data );
		$this->assertArrayHasKey( 'plugin', $stored_data['post'] );
		$this->assertFalse( $stored_data['post_replay_blocked'] );
		$this->assertIsInt( $stored_data['created'] );

		unset(
			$_SERVER['REQUEST_METHOD'],
			$_SERVER['HTTP_HOST'],
			$_SERVER['REQUEST_URI'],
			$_GET['action'],
			$_POST['plugin']
		);
	}

	/**
	 * Test save() does not store GET params separately from the original URL.
	 */
	public function test_save_does_not_store_get_params(): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/user-new.php?action=createuser';
		$_GET                      = array( 'action' => 'createuser' );
		$_POST                     = array(
			'user_login' => 'newuser',
			'_wpnonce'   => 'abc123',
		);

		Functions\expect( 'wp_generate_password' )->once()->andReturn( 'getdroptest1234' );
		Functions\expect( 'is_ssl' )->once()->andReturn( false );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$stored_data = null;
		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				function ( $name, $data ) use ( &$stored_data ) {
					$stored_data = $data;
					return true;
				}
			);

		$this->stash->save( 1, $this->rule_with_post_fields( 'user.create', 'Create user', array( 'user_login', '_wpnonce' ) ) );

		$this->assertArrayNotHasKey( 'get', $stored_data, 'GET params must not be stored in the stash.' );
		$this->assertArrayHasKey( 'post', $stored_data );
		$this->assertArrayHasKey( 'url', $stored_data, 'Full URL with query string remains the replay target.' );
		$this->assertSame( 'http://example.com/wp-admin/user-new.php?action=createuser', $stored_data['url'] );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		$_GET  = array();
		$_POST = array();
	}

	/**
	 * Test get() returns stashed data for the correct user.
	 */
	public function test_get_returns_data_for_owner(): void {
		$data = array(
			'user_id' => 5,
			'rule_id' => 'plugin.delete',
			'label'   => 'Delete plugin',
			'method'  => 'POST',
			'url'     => 'https://example.com/wp-admin/plugins.php',
			'get'     => array(),
			'post'    => array( 'checked' => array( 'hello.php' ) ),
			'created' => time(),
		);

		Functions\expect( 'get_transient' )
			->once()
			->with( Request_Stash::TRANSIENT_PREFIX . 'mykey123' )
			->andReturn( $data );

		$result = $this->stash->get( 'mykey123', 5 );

		$this->assertIsArray( $result );
		$this->assertSame( 5, $result['user_id'] );
		$this->assertSame( 'plugin.delete', $result['rule_id'] );
	}

	/**
	 * Test get() returns null for a different user.
	 */
	public function test_get_returns_null_for_wrong_user(): void {
		$data = array(
			'user_id' => 5,
			'rule_id' => 'plugin.delete',
			'method'  => 'POST',
			'url'     => 'https://example.com/wp-admin/plugins.php',
			'get'     => array(),
			'post'    => array(),
			'created' => time(),
		);

		Functions\expect( 'get_transient' )
			->once()
			->with( Request_Stash::TRANSIENT_PREFIX . 'somekey1234' )
			->andReturn( $data );

		$result = $this->stash->get( 'somekey1234', 99 );

		$this->assertNull( $result );
	}

	/**
	 * Test get() returns null for corrupted non-array transient payloads.
	 */
	public function test_get_returns_null_for_non_array_transient(): void {
		Functions\expect( 'get_transient' )
			->once()
			->with( Request_Stash::TRANSIENT_PREFIX . 'corruptedkey123' )
			->andReturn( 'corrupted' );

		$result = $this->stash->get( 'corruptedkey123', 1 );

		$this->assertNull( $result );
	}

	/**
	 * Test get() returns null when transient is missing (expired).
	 */
	public function test_get_returns_null_when_expired(): void {
		Functions\expect( 'get_transient' )
			->once()
			->with( Request_Stash::TRANSIENT_PREFIX . 'expiredkey12345' )
			->andReturn( false );

		$result = $this->stash->get( 'expiredkey12345', 1 );

		$this->assertNull( $result );
	}

	/**
	 * Test get() returns null for empty key.
	 */
	public function test_get_returns_null_for_empty_key(): void {
		$result = $this->stash->get( '', 1 );

		$this->assertNull( $result );
	}

	/**
	 * Test delete() calls delete_transient.
	 */
	public function test_delete_removes_transient(): void {
		Functions\expect( 'delete_transient' )
			->once()
			->with( Request_Stash::TRANSIENT_PREFIX . 'delkey123456789' );

		$this->stash->delete( 'delkey123456789' );
	}

	/**
	 * Test delete() with empty key does not call delete_transient.
	 */
	public function test_delete_skips_empty_key(): void {
		Functions\expect( 'delete_transient' )->never();

		$this->stash->delete( '' );
	}

	/**
	 * Test exists() returns true for a valid stash.
	 */
	public function test_exists_returns_true_for_valid_stash(): void {
		$data = array(
			'user_id' => 10,
			'rule_id' => 'theme.switch',
			'method'  => 'GET',
			'url'     => 'https://example.com/wp-admin/themes.php',
			'get'     => array(),
			'post'    => array(),
			'created' => time(),
		);

		Functions\expect( 'get_transient' )
			->once()
			->with( Request_Stash::TRANSIENT_PREFIX . 'existskey123456' )
			->andReturn( $data );

		$this->assertTrue( $this->stash->exists( 'existskey123456', 10 ) );
	}

	/**
	 * Test exists() returns false when stash is missing.
	 */
	public function test_exists_returns_false_when_missing(): void {
		Functions\expect( 'get_transient' )
			->once()
			->with( Request_Stash::TRANSIENT_PREFIX . 'nokey1234567890' )
			->andReturn( false );

		$this->assertFalse( $this->stash->exists( 'nokey1234567890', 10 ) );
	}

	/**
	 * Test that the transient prefix constant is defined.
	 */
	public function test_transient_prefix_is_defined(): void {
		$this->assertSame( '_wp_sudo_stash_', Request_Stash::TRANSIENT_PREFIX );
	}

	/**
	 * Test TTL constant is 300 seconds.
	 */
	public function test_ttl_is_five_minutes(): void {
		$this->assertSame( 300, Request_Stash::TTL );
	}

	/**
	 * Test save() handles missing SERVER vars gracefully.
	 */
	public function test_save_handles_missing_server_vars(): void {
		$this->stub_stash_index_meta_io();

		// Ensure the vars are not set.
		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );

		Functions\expect( 'wp_generate_password' )
			->once()
			->andReturn( 'fallbackkey12345' );

		Functions\expect( 'is_ssl' )
			->once()
			->andReturn( false );

		Functions\when( 'esc_url_raw' )->returnArg();

		$stored_data = null;

		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				function ( $name, $data, $ttl ) use ( &$stored_data ) {
					$stored_data = $data;
					return true;
				}
			);

		$this->stash->save( 1, array( 'id' => 'test.rule', 'label' => 'Test' ) );

		$this->assertSame( 'GET', $stored_data['method'] );
		$this->assertStringContainsString( 'localhost', $stored_data['url'] );
		$this->assertStringContainsString( '/wp-admin/', $stored_data['url'] );
	}

	/**
	 * Test save() preserves percent-encoded characters in REQUEST_URI.
	 *
	 * Plugin slugs like "my-plugin/plugin.php" are URL-encoded as
	 * "my-plugin%2Fplugin.php" in the query string. sanitize_text_field()
	 * strips percent-encoded characters entirely, corrupting the URL.
	 * The stash must use esc_url_raw() instead to preserve them.
	 *
	 * @see https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-includes/formatting.php
	 */
	public function test_save_preserves_percent_encoded_url(): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/plugins.php?action=activate&plugin=my-plugin%2Fplugin.php&_wpnonce=abc123';

		Functions\expect( 'wp_generate_password' )
			->once()
			->andReturn( 'pct_encoded_key01' );

		Functions\expect( 'is_ssl' )
			->once()
			->andReturn( false );

		Functions\when( 'esc_url_raw' )->returnArg();

		$stored_data = null;

		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				function ( $name, $data, $ttl ) use ( &$stored_data ) {
					$stored_data = $data;
					return true;
				}
			);

		$this->stash->save( 1, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		// The %2F must be preserved — sanitize_text_field() would strip it.
		$this->assertStringContainsString( 'my-plugin%2Fplugin.php', $stored_data['url'] );
		$this->assertStringContainsString( '_wpnonce=abc123', $stored_data['url'] );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
	}

	/**
	 * Test save() omits sensitive POST fields from stored data.
	 *
	 * Sensitive keys: pass1, pass2, user_pass, password, token, secret,
	 * api_key, api_secret, auth_key, auth_token, access_token, private_key,
	 * pass1-text, pwd.
	 */
	public function test_save_omits_sensitive_post_fields(): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/user-new.php';
		$_POST                     = array(
			'user_login' => 'newuser',
			'pass1'      => 'secret-password',
			'pass2'      => 'secret-password',
			'user_pass'  => 'another-secret',
			'token'      => 'mytoken',
			'_wpnonce'   => 'abc123',
		);

		Functions\expect( 'wp_generate_password' )->once()->andReturn( 'redacttest01234' );
		Functions\expect( 'is_ssl' )->once()->andReturn( false );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$stored_data = null;
		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				function ( $name, $data ) use ( &$stored_data ) {
					$stored_data = $data;
					return true;
				}
			);

		$this->stash->save( 1, $this->rule_with_post_fields( 'user.create', 'Create user', array( 'user_login', 'pass1', 'pass2', 'user_pass', 'token', '_wpnonce' ) ) );

		$this->assertArrayHasKey( 'user_login', $stored_data['post'], 'user_login must survive redaction' );
		$this->assertArrayHasKey( '_wpnonce', $stored_data['post'], '_wpnonce must survive redaction' );
		$this->assertArrayNotHasKey( 'pass1', $stored_data['post'], 'pass1 must be omitted' );
		$this->assertArrayNotHasKey( 'pass2', $stored_data['post'], 'pass2 must be omitted' );
		$this->assertArrayNotHasKey( 'user_pass', $stored_data['post'], 'user_pass must be omitted' );
		$this->assertArrayNotHasKey( 'token', $stored_data['post'], 'token must be omitted' );
		$this->assertTrue( $stored_data['redacted_fields_omitted'], 'Stash must record that sensitive fields were omitted' );
		$this->assertTrue( $stored_data['post_replay_blocked'], 'POST replay must be blocked when secrets were omitted' );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		$_POST = array();
	}

	/**
	 * Test save() records when no sensitive fields were omitted.
	 */
	public function test_save_records_no_redacted_fields_for_plain_post(): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/options-general.php';
		$_POST                     = array(
			'blogname' => 'Example Site',
			'_wpnonce' => 'abc123',
		);

		Functions\expect( 'wp_generate_password' )->once()->andReturn( 'plainpost012345' );
		Functions\expect( 'is_ssl' )->once()->andReturn( false );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$stored_data = null;
		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				function ( $name, $data ) use ( &$stored_data ) {
					$stored_data = $data;
					return true;
				}
			);

		$this->stash->save( 1, $this->rule_with_post_fields( 'options.critical', 'Change site setting', array( 'blogname', '_wpnonce' ) ) );

		$this->assertFalse( $stored_data['redacted_fields_omitted'] );
		$this->assertFalse( $stored_data['post_replay_blocked'] );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		$_POST = array();
	}

	/**
	 * Test that sensitive keys are matched case-insensitively.
	 */
	public function test_sensitive_keys_matched_case_insensitively(): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/profile.php';
		$_POST                     = array(
			'PASS1'     => 'uppercase-secret',
			'Password'  => 'mixed-case-secret',
			'USER_PASS' => 'another-secret',
			'email'     => 'user@example.com',
		);

		Functions\expect( 'wp_generate_password' )->once()->andReturn( 'casetest0123456' );
		Functions\expect( 'is_ssl' )->once()->andReturn( false );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$stored_data = null;
		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				function ( $name, $data ) use ( &$stored_data ) {
					$stored_data = $data;
					return true;
				}
			);

		$this->stash->save( 1, $this->rule_with_post_fields( 'user.change_password', 'Change password', array( 'PASS1', 'Password', 'USER_PASS', 'email' ) ) );

		$this->assertArrayNotHasKey( 'PASS1', $stored_data['post'], 'PASS1 (uppercase) must be omitted' );
		$this->assertArrayNotHasKey( 'Password', $stored_data['post'], 'Password (mixed case) must be omitted' );
		$this->assertArrayNotHasKey( 'USER_PASS', $stored_data['post'], 'USER_PASS (uppercase) must be omitted' );
		$this->assertArrayHasKey( 'email', $stored_data['post'], 'email must survive redaction' );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		$_POST = array();
	}

	/**
	 * Test that nested sensitive fields are omitted recursively.
	 */
	public function test_nested_sensitive_fields_are_omitted(): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/profile.php';
		$_POST                     = array(
			'user'         => array(
				'name'  => 'John',
				'pass1' => 'nested-secret',
			),
			'normal_field' => 'safe-value',
		);

		Functions\expect( 'wp_generate_password' )->once()->andReturn( 'nestedtest01234' );
		Functions\expect( 'is_ssl' )->once()->andReturn( false );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$stored_data = null;
		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				function ( $name, $data ) use ( &$stored_data ) {
					$stored_data = $data;
					return true;
				}
			);

		$this->stash->save( 1, $this->rule_with_post_fields( 'user.change_password', 'Change password', array( 'user', 'normal_field' ) ) );

		$this->assertArrayHasKey( 'user', $stored_data['post'] );
		$this->assertArrayHasKey( 'name', $stored_data['post']['user'], 'Non-sensitive nested key must survive' );
		$this->assertArrayNotHasKey( 'pass1', $stored_data['post']['user'], 'Nested pass1 must be omitted' );
		$this->assertArrayHasKey( 'normal_field', $stored_data['post'], 'Top-level non-sensitive field must survive' );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		$_POST = array();
	}

	/**
	 * Test that the sensitive key list is filterable.
	 */
	public function test_sensitive_keys_are_filterable(): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/options-general.php';
		$_POST                     = array(
			'my_custom_secret' => 'very-secret',
			'normal_field'     => 'safe-value',
		);

		Functions\expect( 'wp_generate_password' )->once()->andReturn( 'filtertest01234' );
		Functions\expect( 'is_ssl' )->once()->andReturn( false );
		Functions\when( 'esc_url_raw' )->returnArg();

		// apply_filters returns the default list PLUS a custom key.
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wp_sudo_sensitive_stash_keys', \Mockery::type( 'array' ) )
			->andReturnUsing(
				function ( $tag, $keys ) {
					$keys[] = 'my_custom_secret';
					return $keys;
				}
			);

		$stored_data = null;
		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				function ( $name, $data ) use ( &$stored_data ) {
					$stored_data = $data;
					return true;
				}
			);

		$this->stash->save( 1, $this->rule_with_post_fields( 'options.general', 'Save options', array( 'my_custom_secret', 'normal_field' ) ) );

		$this->assertArrayNotHasKey( 'my_custom_secret', $stored_data['post'], 'Custom secret added via filter must be omitted' );
		$this->assertArrayHasKey( 'normal_field', $stored_data['post'], 'Normal field must survive' );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		$_POST = array();
	}

	/**
	 * Test compound secret field names are omitted by high-signal suffix.
	 */
	public function test_compound_secret_suffixes_are_omitted(): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/options.php';
		$_POST                     = array(
			'connectors_openai_api_key' => 'sk-test',
			'stripe_secret_key'         => 'stripe-secret',
			'smtpPassword'              => 'smtp-secret',
			'mailgun-api-key'           => 'mailgun-secret',
			'oauth_access_token'        => 'access-secret',
			'display_name'              => 'Visible Name',
		);

		Functions\expect( 'wp_generate_password' )->once()->andReturn( 'suffixtest12345' );
		Functions\expect( 'is_ssl' )->once()->andReturn( false );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$stored_data = null;
		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				function ( $name, $data ) use ( &$stored_data ) {
					$stored_data = $data;
					return true;
				}
			);

		$this->stash->save(
			1,
			$this->rule_with_post_fields(
				'options.credentials',
				'Update credentials',
				array( 'connectors_openai_api_key', 'stripe_secret_key', 'smtpPassword', 'mailgun-api-key', 'oauth_access_token', 'display_name' )
			)
		);

		$this->assertArrayNotHasKey( 'connectors_openai_api_key', $stored_data['post'] );
		$this->assertArrayNotHasKey( 'stripe_secret_key', $stored_data['post'] );
		$this->assertArrayNotHasKey( 'smtpPassword', $stored_data['post'] );
		$this->assertArrayNotHasKey( 'mailgun-api-key', $stored_data['post'] );
		$this->assertArrayNotHasKey( 'oauth_access_token', $stored_data['post'] );
		$this->assertArrayHasKey( 'display_name', $stored_data['post'] );
		$this->assertTrue( $stored_data['redacted_fields_omitted'] );
		$this->assertTrue( $stored_data['post_replay_blocked'] );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		$_POST = array();
	}

	/**
	 * Test POST fields outside the rule allowlist are not stashed.
	 */
	public function test_save_stores_only_rule_allowlisted_post_fields(): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/options.php';
		$_POST                     = array(
			'option_page' => 'wp-sudo-settings',
			'action'      => 'update',
			'_wpnonce'    => 'abc123',
			'unexpected'  => 'must-not-store',
		);

		Functions\expect( 'wp_generate_password' )->once()->andReturn( 'allowlist123456' );
		Functions\expect( 'is_ssl' )->once()->andReturn( false );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$stored_data = null;
		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				function ( $name, $data ) use ( &$stored_data ) {
					$stored_data = $data;
					return true;
				}
			);

		$this->stash->save( 1, $this->rule_with_post_fields( 'options.wp_sudo', 'Change Sudo settings', array( 'option_page', 'action', '_wpnonce' ) ) );

		$this->assertSame( 'wp-sudo-settings', $stored_data['post']['option_page'] );
		$this->assertArrayNotHasKey( 'unexpected', $stored_data['post'] );
		$this->assertFalse( $stored_data['post_replay_blocked'] );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		$_POST = array();
	}

	/**
	 * Test POST rules without an allowlist are not replayed.
	 */
	public function test_save_blocks_post_replay_without_allowlist(): void {
		$this->stub_stash_index_meta_io();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/admin.php';
		$_POST                     = array( 'arbitrary' => 'value' );

		Functions\expect( 'wp_generate_password' )->once()->andReturn( 'noallowlist1234' );
		Functions\expect( 'is_ssl' )->once()->andReturn( false );
		Functions\when( 'esc_url_raw' )->returnArg();

		$stored_data = null;
		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				function ( $name, $data ) use ( &$stored_data ) {
					$stored_data = $data;
					return true;
				}
			);

		$this->stash->save( 1, array( 'id' => 'custom.unsafe', 'label' => 'Custom unsafe action' ) );

		$this->assertSame( array(), $stored_data['post'] );
		$this->assertTrue( $stored_data['post_replay_blocked'] );
		$this->assertSame( Request_Stash::REPLAY_BLOCKED_NO_ALLOWLIST, $stored_data['post_replay_block_reason'] );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		$_POST = array();
	}

	// -----------------------------------------------------------------
	// Stash cap and index
	// -----------------------------------------------------------------

	/**
	 * Test that save() calls get_user_meta and update_user_meta for the stash index.
	 */
	public function test_save_records_key_in_stash_index(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/plugins.php';

		Functions\expect( 'wp_generate_password' )->once()->andReturn( 'indextest1234567' );
		Functions\expect( 'is_ssl' )->once()->andReturn( false );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// Index is empty on first save.
		Functions\expect( 'get_user_meta' )
			->twice()
			->with( 1, Request_Stash::STASH_INDEX_META_KEY, true )
			->andReturn( array() );

		Functions\expect( 'set_transient' )->once()->andReturn( true );

		// After saving the transient, update_user_meta records the key.
		Functions\expect( 'update_user_meta' )->twice();

		$this->stash->save( 1, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
	}

	/**
	 * Test that save() evicts the oldest stash when the cap is reached.
	 *
	 * With MAX_STASH_PER_USER = 5, the 6th save must:
	 *   1. delete_transient for the oldest key
	 *   2. update_user_meta to remove oldest from index
	 *   3. set_transient for the new stash
	 *   4. update_user_meta to add new key to index
	 */
	public function test_save_enforces_stash_cap(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/plugins.php';

		Functions\expect( 'wp_generate_password' )->once()->andReturn( 'newsixthkey12345' );
		Functions\expect( 'is_ssl' )->once()->andReturn( false );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// Index is at cap (5 existing keys).
		$existing_keys = array( 'key1111111111111', 'key2222222222222', 'key3333333333333', 'key4444444444444', 'key5555555555555' );

		Functions\expect( 'get_user_meta' )
			->twice()
			->with( 1, Request_Stash::STASH_INDEX_META_KEY, true )
			->andReturn( $existing_keys );

		// Oldest key (key1111111111111) must be evicted before the new transient is stored.
		Functions\expect( 'delete_transient' )
			->once()
			->with( Request_Stash::TRANSIENT_PREFIX . 'key1111111111111' );

		// update_user_meta called twice: once after eviction, once after adding new key.
		Functions\expect( 'update_user_meta' )->twice();

		Functions\expect( 'set_transient' )->once()->andReturn( true );

		$this->stash->save( 1, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
	}

	/**
	 * Test that delete() with user_id removes the key from the stash index.
	 */
	public function test_delete_with_user_id_removes_from_stash_index(): void {
		Functions\expect( 'delete_transient' )
			->once()
			->with( Request_Stash::TRANSIENT_PREFIX . 'delindexkey12345' );

		// Index has two keys; one will be removed.
		Functions\expect( 'get_user_meta' )
			->once()
			->with( 42, Request_Stash::STASH_INDEX_META_KEY, true )
			->andReturn( array( 'delindexkey12345', 'otherkey12345678' ) );

		Functions\expect( 'update_user_meta' )
			->once()
			->with( 42, Request_Stash::STASH_INDEX_META_KEY, array( 'otherkey12345678' ) );

		$this->stash->delete( 'delindexkey12345', 42 );
	}

	/**
	 * Test that delete() with user_id = 0 skips index cleanup (backward compat).
	 */
	public function test_delete_without_user_id_skips_index_cleanup(): void {
		Functions\expect( 'delete_transient' )
			->once()
			->with( Request_Stash::TRANSIENT_PREFIX . 'noindexkey12345' );

		// get_user_meta and update_user_meta must NOT be called.
		Functions\expect( 'get_user_meta' )->never();
		Functions\expect( 'update_user_meta' )->never();

		// Calling with no second argument (default 0).
		$this->stash->delete( 'noindexkey12345' );
	}

	/**
	 * Test MAX_STASH_PER_USER constant is 5.
	 */
	public function test_max_stash_per_user_is_five(): void {
		$this->assertSame( 5, Request_Stash::MAX_STASH_PER_USER );
	}

	/**
	 * Test STASH_INDEX_META_KEY constant value.
	 */
	public function test_stash_index_meta_key_constant(): void {
		$this->assertSame( '_wp_sudo_stash_keys', Request_Stash::STASH_INDEX_META_KEY );
	}
	// -----------------------------------------------------------------
	// Multisite: site transients
	// -----------------------------------------------------------------

	/**
	 * Test save uses set_site_transient on multisite.
	 */
	public function test_save_uses_site_transient_on_multisite(): void {
		$this->stub_stash_index_meta_io();

		Functions\when( 'is_multisite' )->justReturn( true );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/plugins.php';

		Functions\expect( 'wp_generate_password' )
			->once()
			->andReturn( 'multisite_key_01' );

		Functions\expect( 'is_ssl' )->once()->andReturn( true );
		Functions\when( 'esc_url_raw' )->returnArg();

		Functions\expect( 'set_site_transient' )
			->once()
			->with(
				Request_Stash::TRANSIENT_PREFIX . 'multisite_key_01',
				\Mockery::type( 'array' ),
				Request_Stash::TTL
			)
			->andReturn( true );

		$key = $this->stash->save( 1, array( 'id' => 'plugin.activate', 'label' => 'Activate' ) );

		$this->assertSame( 'multisite_key_01', $key );
	}

	/**
	 * Test get uses get_site_transient on multisite.
	 */
	public function test_get_uses_site_transient_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		$data = array( 'user_id' => 1, 'rule_id' => 'plugin.activate' );

		Functions\expect( 'get_site_transient' )
			->once()
			->with( Request_Stash::TRANSIENT_PREFIX . 'testkey123456789' )
			->andReturn( $data );

		$result = $this->stash->get( 'testkey123456789', 1 );

		$this->assertSame( $data, $result );
	}

	/**
	 * Test delete uses delete_site_transient on multisite.
	 */
	public function test_delete_uses_site_transient_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		Functions\expect( 'delete_site_transient' )
			->once()
			->with( Request_Stash::TRANSIENT_PREFIX . 'deletekey1234567' )
			->andReturn( true );

		$this->stash->delete( 'deletekey1234567' );

		// If we reach here without errors, the test passes — Mockery verifies the expectation.
		$this->assertTrue( true );
	}
}
