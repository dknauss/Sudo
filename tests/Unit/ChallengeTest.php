<?php
/**
 * Tests for Challenge.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Challenge;
use WP_Sudo\Request_Stash;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;

/**
 * @covers \WP_Sudo\Challenge
 */
class ChallengeTest extends TestCase
{

	/**
	 * Challenge instance under test.
	 *
	 * @var Challenge
	 */
	private Challenge $challenge;

	/**
	 * Mock stash.
	 *
	 * @var Request_Stash|\Mockery\MockInterface
	 */
	private $stash;

	protected function setUp(): void
	{
		parent::setUp();
		$this->stash = \Mockery::mock(Request_Stash::class);
		$this->challenge = new Challenge($this->stash);

		// Reset Two_Factor_Core mock provider between tests.
		\Two_Factor_Core::$mock_provider = null;

		// Prevent stash_key leakage between tests.
		unset($_POST['stash_key'], $_GET['stash_key'], $_GET['return_url'], $_GET['page']);

		Functions\when('get_user_meta')->justReturn('');
		Functions\when('esc_url_raw')->returnArg();
	}

	/**
	 * Test register hooks the correct actions.
	 */
	public function test_register_hooks(): void
	{
		Actions\expectAdded('admin_menu')
			->once()
			->with(array($this->challenge, 'register_page'), \Mockery::any(), 0);

		Actions\expectAdded('wp_ajax_' . Challenge::AJAX_AUTH_ACTION)
			->once()
			->with(array($this->challenge, 'handle_ajax_auth'), \Mockery::any(), 0);

		Actions\expectAdded('wp_ajax_' . Challenge::AJAX_2FA_ACTION)
			->once()
			->with(array($this->challenge, 'handle_ajax_2fa'), \Mockery::any(), 0);

		Actions\expectAdded('admin_enqueue_scripts')
			->once()
			->with(array($this->challenge, 'enqueue_assets'), \Mockery::any(), 0);

		$this->challenge->register();
	}

	/**
	 * Test NONCE_ACTION constant is defined.
	 */
	public function test_nonce_action_constant(): void
	{
		$this->assertSame('wp_sudo_challenge', Challenge::NONCE_ACTION);
	}

	/**
	 * Test AJAX action constants are defined.
	 */
	public function test_ajax_action_constants(): void
	{
		$this->assertSame('wp_sudo_challenge_auth', Challenge::AJAX_AUTH_ACTION);
		$this->assertSame('wp_sudo_challenge_2fa', Challenge::AJAX_2FA_ACTION);
	}

	/**
	 * Test enqueue_assets only runs on the challenge page.
	 */
	public function test_enqueue_assets_skips_other_pages(): void
	{
		$_GET['page'] = 'some-other-page';

		// wp_enqueue_style should never be called.
		Functions\expect('wp_enqueue_style')->never();
		Functions\expect('wp_enqueue_script')->never();

		$this->challenge->enqueue_assets();

		unset($_GET['page']);
	}

	/**
	 * Test enqueue_assets loads on the challenge page.
	 */
	public function test_enqueue_assets_loads_on_challenge_page(): void
	{
		$_GET['page'] = 'wp-sudo-challenge';
		$_GET['stash_key'] = 'testkey123';

		Functions\when('__')->returnArg();
		Functions\when('get_current_user_id')->justReturn(42);

		Functions\expect('wp_enqueue_style')
			->once()
			->with('wp-sudo-challenge', \Mockery::type('string'), array(), WP_SUDO_VERSION);

		Functions\expect('wp_enqueue_script')
			->once()
			->with(
				'wp-sudo-challenge',
				\Mockery::type('string'),
				array('wp-a11y'),
				WP_SUDO_VERSION,
				true
			);

		Functions\expect('admin_url')
			->once()
			->with('admin-ajax.php')
			->andReturn('https://example.com/wp-admin/admin-ajax.php');

		Functions\expect('wp_create_nonce')
			->once()
			->with(Challenge::NONCE_ACTION)
			->andReturn('test-nonce-123');

		Functions\expect('wp_localize_script')
			->once()
			->with(
				'wp-sudo-challenge',
				'wpSudoChallenge',
				\Mockery::on(
					function ($data) {
						return isset($data['ajaxUrl'])
							&& isset($data['nonce'])
							&& 'testkey123' === $data['stashKey']
							&& Challenge::AJAX_AUTH_ACTION === $data['authAction']
							&& Challenge::AJAX_2FA_ACTION === $data['tfaAction']
							&& isset($data['strings'])
							&& is_array($data['strings']);
					}
				)
			);

		$this->challenge->enqueue_assets();

		unset($_GET['page'], $_GET['stash_key']);
	}

	/**
	 * Test enqueue_assets localizes all required string keys.
	 */
	public function test_enqueue_assets_localizes_all_string_keys(): void
	{
		$_GET['page'] = 'wp-sudo-challenge';
		$_GET['stash_key'] = 'key123';

		Functions\when('__')->returnArg();
		Functions\when('get_current_user_id')->justReturn(42);

		Functions\expect('wp_enqueue_style')->once();
		Functions\expect('wp_enqueue_script')->once();

		Functions\expect('admin_url')
			->with('admin-ajax.php')
			->andReturn('https://example.com/wp-admin/admin-ajax.php');

		Functions\expect('wp_create_nonce')
			->with(Challenge::NONCE_ACTION)
			->andReturn('test-nonce');

		$captured = null;
		Functions\expect('wp_localize_script')
			->once()
			->with(
				'wp-sudo-challenge',
				'wpSudoChallenge',
				\Mockery::on(
					function ($data) use (&$captured) {
						$captured = $data;
						return true;
					}
				)
			);

		$this->challenge->enqueue_assets();

		$this->assertIsArray($captured['strings']);
		$expected_keys = array(
			'unexpectedResponse',
			'genericError',
			'networkError',
			'authenticationFailed',
			'lockoutCountdown',
			'throttleCountdown',
			'timeRemaining',
			'timeRemainingWarn',
			'sessionExpired',
			'startOver',
			'twoFactorRequired',
			'replayingAction',
			'leavingChallenge',
		);
		foreach ($expected_keys as $key) {
			$this->assertArrayHasKey($key, $captured['strings'], "Missing string key: $key");
			$this->assertNotEmpty($captured['strings'][$key], "Empty string for key: $key");
		}

		unset($_GET['page'], $_GET['stash_key']);
	}

	/**
	 * Test register_page adds a submenu page.
	 */
	public function test_register_page_adds_submenu(): void
	{
		Functions\when('__')->returnArg();

		Functions\expect('add_submenu_page')
			->once()
			->with(
				'',
				\Mockery::type('string'),
				'',
				'read',
				'wp-sudo-challenge',
				\Mockery::type('array')
			)
			->andReturn('admin_page_wp-sudo-challenge');

		Actions\expectAdded('load-admin_page_wp-sudo-challenge')
			->once()
			->with(array($this->challenge, 'prime_page_title'), \Mockery::any(), 0);

		$this->challenge->register_page();
	}

	/**
	 * Test prime_page_title sets the global admin title for the hidden page.
	 */
	public function test_prime_page_title_sets_global_title(): void
	{
		global $title;

		$title = null;
		Functions\when('__')->returnArg();

		$this->challenge->prime_page_title();

		$this->assertSame('Confirm Your Identity — Sudo', $title);
	}

	// -----------------------------------------------------------------
	// handle_ajax_auth — session-only flow (no stash_key)
	// -----------------------------------------------------------------

	/**
	 * Test handle_ajax_auth succeeds without stash_key (session-only flow).
	 *
	 * When the challenge page is in session-only mode, no stash_key is
	 * sent. The handler should skip stash validation and return
	 * {code: 'authenticated'}.
	 */
	public function test_handle_ajax_auth_succeeds_without_stash_key(): void
	{
		$_POST['password'] = 'correct-horse';
		// No stash_key in $_POST — session-only flow.

		Functions\expect('check_ajax_referer')->once();
		Functions\when('get_current_user_id')->justReturn(42);
		Functions\when('__')->returnArg();

		// attempt_activation internals: not locked out, password correct, no 2FA.
		$user = new \WP_User(42);
		$user->user_pass = 'hashed';

		Functions\when('get_user_meta')->justReturn('');
		Functions\expect('get_userdata')->andReturn($user);
		Functions\expect('wp_check_password')->once()->andReturn(true);
		Functions\when('update_user_meta')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('get_option')->justReturn(array());
		Functions\when('apply_filters')->returnArg(2);

		// activate() internals: token + cookie.
		Functions\when('wp_generate_password')->justReturn('test-token-abc');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);

		// Session-only success should return 'authenticated' code (not replay).
		Functions\expect('wp_send_json_success')
			->once()
			->with(\Mockery::on(function ($data) {
				return is_array($data)
					&& isset($data['code'])
					&& 'authenticated' === $data['code'];
			}));

		$this->stash->shouldNotReceive('exists');
		$this->stash->shouldNotReceive('get');

		$this->challenge->handle_ajax_auth();

		unset($_POST['password']);
	}

	/**
	 * Test handle_ajax_auth validates stash_key when provided (challenge flow).
	 *
	 * When a stash_key is sent, the handler must verify it exists.
	 * If the stash is expired/invalid, return 403.
	 */
	public function test_handle_ajax_auth_validates_stash_key_when_provided(): void
	{
		$_POST['password'] = 'correct-horse';
		$_POST['stash_key'] = 'expired-key';

		Functions\expect('check_ajax_referer')->once();
		Functions\when('get_current_user_id')->justReturn(42);
		Functions\when('__')->returnArg();

		// Stash does not exist — expired.
		$this->stash->shouldReceive('exists')
			->once()
			->with('expired-key', 42)
			->andReturn(false);

		// wp_send_json_error is called for the 403 (our primary assertion).
		// In real WP this dies; in tests execution continues to attempt_activation.
		// Stub everything attempt_activation and replay_stash may touch.
		$user = new \WP_User(42);
		$user->user_pass = 'hashed';
		Functions\when('get_user_meta')->justReturn('');
		Functions\when('get_userdata')->justReturn($user);
		Functions\when('wp_check_password')->justReturn(true);
		Functions\when('update_user_meta')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('get_option')->justReturn(array());
		Functions\when('apply_filters')->returnArg(2);
		Functions\when('wp_generate_password')->justReturn('test-token-abc');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);

		// replay_stash will be called (stash_key is set); stash returns null → redirect.
		$this->stash->shouldReceive('get')->andReturn(null);
		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/');
		Functions\when('wp_send_json_success')->justReturn(null);

		// Primary assertion: first wp_send_json_error call has 403.
		Functions\expect('wp_send_json_error')
			->once()
			->with(\Mockery::type('array'), 403);

		$this->challenge->handle_ajax_auth();

		unset($_POST['password'], $_POST['stash_key']);
	}

	/**
	 * Test handle_ajax_auth default invalid-password path includes delay in JSON error payload when backend result has delay.
	 */
	public function test_handle_ajax_auth_returns_delay_in_invalid_password_response(): void
	{
		$_POST['password'] = 'wrong-password';

		Functions\expect('check_ajax_referer')->once();
		Functions\when('get_current_user_id')->justReturn(42);
		Functions\when('__')->returnArg();

		$throttle_until = time() + 3;
		Functions\when('get_user_meta')->alias(function ($uid, $key, $single = true) use ($throttle_until) {
			if (\WP_Sudo\Sudo_Session::THROTTLE_UNTIL_META_KEY === $key) {
				return $throttle_until;
			}
			return '';
		});

		Functions\expect('get_userdata')->never();
		Functions\expect('wp_check_password')->never();

		Functions\expect('wp_send_json_error')
			->once()
			->with(
				\Mockery::on(function ($data) {
					return is_array($data)
						&& isset($data['delay'])
						&& $data['delay'] > 0;
				}),
				401
			);

		$this->challenge->handle_ajax_auth();

		unset($_POST['password']);
	}

	/**
	 * Test handle_ajax_auth exits cleanly when sudo is already active and the stash expired.
	 */
	public function test_handle_ajax_auth_returns_authenticated_when_session_is_already_active(): void
	{
		$_POST['password'] = 'correct-horse';
		$_POST['stash_key'] = 'expired-key';
		$_COOKIE[\WP_Sudo\Sudo_Session::TOKEN_COOKIE] = 'browser-token';

		Functions\expect('check_ajax_referer')->once();
		Functions\when('get_current_user_id')->justReturn(42);
		Functions\when('__')->returnArg();
		Functions\when('sanitize_text_field')->returnArg();

		$expires = time() + 300;
		Functions\when('get_user_meta')->alias(function ($uid, $key, $single = true) use ($expires) {
			if (\WP_Sudo\Sudo_Session::META_KEY === $key) {
				return $expires;
			}
			if (\WP_Sudo\Sudo_Session::TOKEN_META_KEY === $key) {
				return hash('sha256', 'browser-token');
			}
			return '';
		});

		Functions\expect('wp_send_json_success')
			->once()
			->with(\Mockery::on(function ($data) {
				return is_array($data)
					&& 'authenticated' === ($data['code'] ?? '');
			}));

		Functions\expect('wp_send_json_error')->never();
		Functions\expect('get_userdata')->never();
		Functions\expect('wp_check_password')->never();

		$this->stash->shouldReceive('exists')
			->once()
			->with('expired-key', 42)
			->andReturn(false);
		$this->stash->shouldNotReceive('get');

		$this->challenge->handle_ajax_auth();

		unset($_POST['password'], $_POST['stash_key'], $_COOKIE[\WP_Sudo\Sudo_Session::TOKEN_COOKIE]);
	}

	/**
	 * Test handle_ajax_2fa succeeds without stash_key (session-only flow).
	 */
	public function test_handle_ajax_2fa_succeeds_without_stash_key(): void
	{
		// No stash_key in $_POST — session-only flow.

		// Set challenge cookie to bind the 2FA lookup.
		$challenge_nonce = 'test-challenge-nonce-2fa';
		$challenge_hash = hash('sha256', $challenge_nonce);
		$_COOKIE[\WP_Sudo\Sudo_Session::CHALLENGE_COOKIE] = $challenge_nonce;

		Functions\expect('check_ajax_referer')->once();
		Functions\when('get_current_user_id')->justReturn(42);
		Functions\expect('get_userdata')->once()->andReturn(new \WP_User(42));
		Functions\when('__')->returnArg();
		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/');
		Functions\when('sanitize_text_field')->returnArg();

		// get_2fa_pending() looks up transient by challenge hash.
		Functions\expect('get_transient')
			->once()
			->with('wp_sudo_2fa_pending_' . $challenge_hash)
			->andReturn(array(
				'user_id' => 42,
				'expires_at' => time() + 600,
			));

		// Two_Factor_Core not loaded — filter validates.
		Functions\when('apply_filters')->justReturn(true);

		// clear_2fa_pending() deletes transient + expires cookie.
		Functions\expect('delete_transient')->once()->with('wp_sudo_2fa_pending_' . $challenge_hash);

		// activate() stubs.
		Functions\when('update_user_meta')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('get_option')->justReturn(array());
		Functions\when('get_user_meta')->justReturn('');
		Functions\when('wp_generate_password')->justReturn('test-token-abc');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);
		Functions\when('wp_send_json_error')->justReturn(null);

		// Should return authenticated (not replay).
		Functions\expect('wp_send_json_success')
			->once()
			->with(\Mockery::on(function ($data) {
				return is_array($data)
					&& isset($data['code'])
					&& 'authenticated' === $data['code'];
			}));

		$this->stash->shouldNotReceive('get');

		$this->challenge->handle_ajax_2fa();
	}

	/**
	 * Test handle_ajax_2fa rejects an expired timestamp even if transient still exists.
	 */
	public function test_handle_ajax_2fa_rejects_expired_timestamp(): void
	{
		// Set challenge cookie — the transient will have an expired timestamp.
		$challenge_nonce = 'expired-challenge-nonce';
		$challenge_hash = hash('sha256', $challenge_nonce);
		$_COOKIE[\WP_Sudo\Sudo_Session::CHALLENGE_COOKIE] = $challenge_nonce;

		Functions\expect('check_ajax_referer')->once();
		Functions\when('get_current_user_id')->justReturn(42);
		Functions\expect('get_userdata')->once()->andReturn(new \WP_User(42));
		Functions\when('__')->returnArg();
		Functions\when('sanitize_text_field')->returnArg();

		// Transient exists but stores a past timestamp (expired 60 seconds ago).
		Functions\expect('get_transient')
			->once()
			->with('wp_sudo_2fa_pending_' . $challenge_hash)
			->andReturn(array(
				'user_id' => 42,
				'expires_at' => time() - 60,
			));

		// Capture wp_send_json_error calls to verify the expired message.
		$error_calls = array();
		Functions\expect('wp_send_json_error')
			->atLeast()
			->once()
			->andReturnUsing(function ($data, $status = 200) use (&$error_calls) {
				$error_calls[] = array('data' => $data, 'status' => $status);
			});

		// Fallthrough stubs — wp_send_json_error doesn't die in tests.
		Functions\when('apply_filters')->justReturn(false);
		Functions\when('delete_transient')->justReturn(true);
		Functions\when('update_user_meta')->justReturn(true);
		Functions\when('add_user_meta')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('get_option')->justReturn(array());
		Functions\when('get_user_meta')->justReturn('');
		Functions\when('wp_generate_password')->justReturn('test-token-abc');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);
		Functions\when('wp_send_json_success')->justReturn(null);

		$this->challenge->handle_ajax_2fa();

		// First error call should be the expired session message with 403 status.
		$this->assertNotEmpty($error_calls, 'wp_send_json_error should have been called.');
		$this->assertSame(403, $error_calls[0]['status']);
		$this->assertStringContainsString('expired', $error_calls[0]['data']['message']);
	}

	/**
	 * Test handle_ajax_2fa rejects when no challenge cookie is present.
	 *
	 * This simulates an attacker who stole the WordPress session cookie
	 * but does not have the challenge cookie (set in the legitimate browser).
	 */
	public function test_handle_ajax_2fa_rejects_without_challenge_cookie(): void
	{
		// No challenge cookie — simulating cross-browser attack.
		unset($_COOKIE[\WP_Sudo\Sudo_Session::CHALLENGE_COOKIE]);

		Functions\expect('check_ajax_referer')->once();
		Functions\when('get_current_user_id')->justReturn(42);
		Functions\expect('get_userdata')->once()->andReturn(new \WP_User(42));
		Functions\when('__')->returnArg();
		Functions\when('sanitize_text_field')->returnArg();

		// Capture wp_send_json_error calls.
		$error_calls = array();
		Functions\expect('wp_send_json_error')
			->atLeast()
			->once()
			->andReturnUsing(function ($data, $status = 200) use (&$error_calls) {
				$error_calls[] = array('data' => $data, 'status' => $status);
			});

		// Fallthrough stubs.
		Functions\when('apply_filters')->justReturn(false);
		Functions\when('delete_transient')->justReturn(true);
		Functions\when('update_user_meta')->justReturn(true);
		Functions\when('add_user_meta')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('get_option')->justReturn(array());
		Functions\when('get_user_meta')->justReturn('');
		Functions\when('wp_generate_password')->justReturn('test-token-abc');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);
		Functions\when('wp_send_json_success')->justReturn(null);

		$this->challenge->handle_ajax_2fa();

		// Should get "expired" 403 because get_2fa_pending() returns null.
		$this->assertNotEmpty($error_calls, 'wp_send_json_error should have been called.');
		$this->assertSame(403, $error_calls[0]['status']);
		$this->assertStringContainsString('expired', $error_calls[0]['data']['message']);
	}

	/**
	 * Test handle_ajax_2fa returns delay metadata when 2FA code is invalid and throttled.
	 */
	public function test_handle_ajax_2fa_returns_delay_on_invalid_code(): void
	{
		// Set challenge cookie.
		$challenge_nonce = 'test-challenge-nonce-2fa-delay';
		$challenge_hash = hash('sha256', $challenge_nonce);
		$_COOKIE[\WP_Sudo\Sudo_Session::CHALLENGE_COOKIE] = $challenge_nonce;

		Functions\expect('check_ajax_referer')->once();
		Functions\when('get_current_user_id')->justReturn(42);
		Functions\expect('get_userdata')->once()->andReturn(new \WP_User(42));
		Functions\when('__')->returnArg();
		Functions\when('sanitize_text_field')->returnArg();

		// get_2fa_pending() succeeds.
		Functions\expect('get_transient')
			->once()
			->with('wp_sudo_2fa_pending_' . $challenge_hash)
			->andReturn(array(
				'user_id' => 42,
				'expires_at' => time() + 600,
			));

		// Not throttled BEFORE validation; emulate 4 failed events after write for delay.
		$now = time();
		Functions\when('get_user_meta')->alias(function ($uid, $key, $single = true) use ($now) {
			if (\WP_Sudo\Sudo_Session::THROTTLE_UNTIL_META_KEY === $key) {
				return '';
			}
			if (\WP_Sudo\Sudo_Session::LOCKOUT_UNTIL_META_KEY === $key) {
				return '';
			}
			if (\WP_Sudo\Sudo_Session::FAILURE_EVENT_META_KEY === $key) {
				return array($now - 3, $now - 2, $now - 1, $now);
			}
			return '';
		});

		// 2FA validation fails.
		Functions\when('apply_filters')->justReturn(false);

		// Throttled AFTER validation (e.g. 4th attempt).
		Functions\expect('add_user_meta')
			->once()
			->with(42, \WP_Sudo\Sudo_Session::FAILURE_EVENT_META_KEY, \Mockery::any(), false)
			->andReturn(true);
		Functions\expect('update_user_meta')
			->once()
			->with(42, \WP_Sudo\Sudo_Session::THROTTLE_UNTIL_META_KEY, \Mockery::type('int'))
			->andReturn(true);

		// Verify JSON response contains delay.
		$error = null;
		Functions\expect('wp_send_json_error')
			->once()
			->andReturnUsing(function ($data, $status = 200) use (&$error) {
				$error = array('data' => $data, 'status' => $status);
				throw new \RuntimeException('stop');
			});

		try {
			$this->challenge->handle_ajax_2fa();
			$this->fail('Expected early wp_send_json_error.');
		} catch (\RuntimeException $e) {
			$this->assertSame('stop', $e->getMessage());
		}

		$this->assertIsArray($error);
		$this->assertSame(401, $error['status']);
		$this->assertSame('invalid_two_factor', $error['data']['code'] ?? '');
		$this->assertSame(2, $error['data']['delay'] ?? 0);

		unset($_COOKIE[\WP_Sudo\Sudo_Session::CHALLENGE_COOKIE]);
	}

	/**
	 * Test register adds network_admin_menu on multisite.
	 */
	public function test_register_adds_network_admin_menu_on_multisite(): void
	{
		Functions\when('is_multisite')->justReturn(true);

		Actions\expectAdded('admin_menu')
			->once()
			->with(array($this->challenge, 'register_page'), \Mockery::any(), 0);

		Actions\expectAdded('network_admin_menu')
			->once()
			->with(array($this->challenge, 'register_page'), \Mockery::any(), 0);

		Actions\expectAdded('wp_ajax_' . Challenge::AJAX_AUTH_ACTION)
			->once()
			->with(array($this->challenge, 'handle_ajax_auth'), \Mockery::any(), 0);

		Actions\expectAdded('wp_ajax_' . Challenge::AJAX_2FA_ACTION)
			->once()
			->with(array($this->challenge, 'handle_ajax_2fa'), \Mockery::any(), 0);

		Actions\expectAdded('admin_enqueue_scripts')
			->once()
			->with(array($this->challenge, 'enqueue_assets'), \Mockery::any(), 0);

		$this->challenge->register();
	}

	// -----------------------------------------------------------------
	// Session-only mode (no stash key)
	// -----------------------------------------------------------------

	/**
	 * Test enqueue_assets passes sessionOnly flag when stash key is empty.
	 */
	public function test_enqueue_assets_passes_session_only_flag(): void
	{
		$_GET['page'] = 'wp-sudo-challenge';
		// No stash_key — session-only mode.

		Functions\when('__')->returnArg();
		Functions\when('get_current_user_id')->justReturn(42);

		Functions\expect('wp_enqueue_style')->once();
		Functions\expect('wp_enqueue_script')->once();

		Functions\expect('admin_url')
			->with('admin-ajax.php')
			->andReturn('https://example.com/wp-admin/admin-ajax.php');

		Functions\expect('wp_create_nonce')
			->with(Challenge::NONCE_ACTION)
			->andReturn('test-nonce');

		Functions\expect('wp_localize_script')
			->once()
			->with(
				'wp-sudo-challenge',
				'wpSudoChallenge',
				\Mockery::on(
					function ($data) {
						return isset($data['sessionOnly'])
							&& true === $data['sessionOnly']
							&& '' === $data['stashKey'];
					}
				)
			);

		$this->challenge->enqueue_assets();

		unset($_GET['page']);
	}

	// -----------------------------------------------------------------
	// handle_ajax_2fa — invalid code returns 401
	// -----------------------------------------------------------------

	/**
	 * Test handle_ajax_2fa returns 401 when 2FA code is invalid.
	 */
	public function test_handle_ajax_2fa_rejects_invalid_code(): void
	{
		$challenge_nonce = 'test-challenge-nonce-invalid';
		$challenge_hash = hash('sha256', $challenge_nonce);
		$_COOKIE[\WP_Sudo\Sudo_Session::CHALLENGE_COOKIE] = $challenge_nonce;

		Functions\expect('check_ajax_referer')->once();
		Functions\when('get_current_user_id')->justReturn(42);
		Functions\expect('get_userdata')->once()->andReturn(new \WP_User(42));
		Functions\when('__')->returnArg();
		Functions\when('sanitize_text_field')->returnArg();

		// Valid pending state.
		Functions\expect('get_transient')
			->once()
			->with('wp_sudo_2fa_pending_' . $challenge_hash)
			->andReturn(array(
				'user_id' => 42,
				'expires_at' => time() + 600,
			));

		// Two_Factor_Core not loaded — filter returns false (invalid code).
		Functions\when('apply_filters')->justReturn(false);

		// Capture error calls.
		$error_calls = array();
		Functions\expect('wp_send_json_error')
			->atLeast()
			->once()
			->andReturnUsing(function ($data, $status = 200) use (&$error_calls) {
				$error_calls[] = array('data' => $data, 'status' => $status);
			});

		// Fallthrough stubs — execution continues past wp_send_json_error in tests.
		Functions\when('delete_transient')->justReturn(true);
		Functions\when('update_user_meta')->justReturn(true);
		Functions\when('add_user_meta')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('get_option')->justReturn(array());
		Functions\when('get_user_meta')->justReturn('');
		Functions\when('wp_generate_password')->justReturn('test-token-abc');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);
		Functions\when('wp_send_json_success')->justReturn(null);

		$this->challenge->handle_ajax_2fa();

		// First error call should be the invalid code message with 401 status.
		$this->assertNotEmpty($error_calls, 'wp_send_json_error should have been called.');
		$this->assertSame(401, $error_calls[0]['status']);
		$this->assertStringContainsString('Invalid', $error_calls[0]['data']['message']);
	}

	/**
	 * Test handle_ajax_2fa rejects when throttle is still active.
	 */
	public function test_handle_ajax_2fa_rejects_when_throttled(): void
	{
		$challenge_nonce = 'test-challenge-nonce-throttle';
		$challenge_hash = hash('sha256', $challenge_nonce);
		$_COOKIE[\WP_Sudo\Sudo_Session::CHALLENGE_COOKIE] = $challenge_nonce;

		Functions\expect('check_ajax_referer')->once();
		Functions\when('get_current_user_id')->justReturn(42);
		Functions\expect('get_userdata')->once()->andReturn(new \WP_User(42));
		Functions\when('__')->returnArg();
		Functions\when('sanitize_text_field')->returnArg();

		Functions\expect('get_transient')
			->once()
			->with('wp_sudo_2fa_pending_' . $challenge_hash)
			->andReturn(array(
				'user_id' => 42,
				'expires_at' => time() + 600,
			));

		$throttle_until = time() + 4;
		Functions\when('get_user_meta')->alias(function ($uid, $key, $single = true) use ($throttle_until) {
			if (\WP_Sudo\Sudo_Session::THROTTLE_UNTIL_META_KEY === $key) {
				return $throttle_until;
			}
			if (\WP_Sudo\Sudo_Session::LOCKOUT_UNTIL_META_KEY === $key) {
				return '';
			}
			return '';
		});

		Functions\expect('apply_filters')->never();

		$error = null;
		Functions\expect('wp_send_json_error')
			->once()
			->andReturnUsing(function ($data, $status = 200) use (&$error) {
				$error = array('data' => $data, 'status' => $status);
				throw new \RuntimeException('stop');
			});

		try {
			$this->challenge->handle_ajax_2fa();
			$this->fail('Expected early wp_send_json_error.');
		} catch (\RuntimeException $e) {
			$this->assertSame('stop', $e->getMessage());
		}

		$this->assertIsArray($error);
		$this->assertSame(429, $error['status']);
		$this->assertSame('throttled', $error['data']['code'] ?? '');
		$this->assertGreaterThan(0, $error['data']['delay'] ?? 0);
	}

	/**
	 * Test handle_ajax_2fa rejects when the user is already locked out.
	 */
	public function test_handle_ajax_2fa_rejects_when_locked_out(): void
	{
		$challenge_nonce = 'test-challenge-nonce-lockout';
		$challenge_hash = hash('sha256', $challenge_nonce);
		$_COOKIE[\WP_Sudo\Sudo_Session::CHALLENGE_COOKIE] = $challenge_nonce;

		Functions\expect('check_ajax_referer')->once();
		Functions\when('get_current_user_id')->justReturn(42);
		Functions\expect('get_userdata')->once()->andReturn(new \WP_User(42));
		Functions\when('__')->returnArg();
		Functions\when('sanitize_text_field')->returnArg();

		Functions\expect('get_transient')
			->once()
			->with('wp_sudo_2fa_pending_' . $challenge_hash)
			->andReturn(array(
				'user_id' => 42,
				'expires_at' => time() + 600,
			));

		$lockout_until = time() + 120;
		Functions\when('get_user_meta')->alias(function ($uid, $key, $single = true) use ($lockout_until) {
			if (\WP_Sudo\Sudo_Session::THROTTLE_UNTIL_META_KEY === $key) {
				return '';
			}
			if (\WP_Sudo\Sudo_Session::LOCKOUT_UNTIL_META_KEY === $key) {
				return $lockout_until;
			}
			return '';
		});

		Functions\expect('apply_filters')->never();

		$error = null;
		Functions\expect('wp_send_json_error')
			->once()
			->andReturnUsing(function ($data, $status = 200) use (&$error) {
				$error = array('data' => $data, 'status' => $status);
				throw new \RuntimeException('stop');
			});

		try {
			$this->challenge->handle_ajax_2fa();
			$this->fail('Expected early wp_send_json_error.');
		} catch (\RuntimeException $e) {
			$this->assertSame('stop', $e->getMessage());
		}

		$this->assertIsArray($error);
		$this->assertSame(429, $error['status']);
		$this->assertSame('locked_out', $error['data']['code'] ?? '');
		$this->assertGreaterThan(0, $error['data']['remaining'] ?? 0);
	}

	// -----------------------------------------------------------------
	// handle_ajax_2fa — wp_sudo_validate_two_factor filter override
	// -----------------------------------------------------------------

	/**
	 * Test that wp_sudo_validate_two_factor filter can override validation.
	 *
	 * When Two_Factor_Core is not loaded (so built-in validation returns false),
	 * the filter alone can make validation succeed — this is the third-party
	 * integration path.
	 */
	public function test_handle_ajax_2fa_respects_validate_filter(): void
	{
		$challenge_nonce = 'test-challenge-nonce-filter';
		$challenge_hash = hash('sha256', $challenge_nonce);
		$_COOKIE[\WP_Sudo\Sudo_Session::CHALLENGE_COOKIE] = $challenge_nonce;

		Functions\expect('check_ajax_referer')->once();
		Functions\when('get_current_user_id')->justReturn(42);
		Functions\expect('get_userdata')->once()->andReturn(new \WP_User(42));
		Functions\when('__')->returnArg();
		Functions\when('sanitize_text_field')->returnArg();

		// Valid pending state.
		Functions\expect('get_transient')
			->once()
			->with('wp_sudo_2fa_pending_' . $challenge_hash)
			->andReturn(array(
				'user_id' => 42,
				'expires_at' => time() + 600,
			));

		// The filter is the only call to apply_filters — return true to simulate
		// a third-party 2FA plugin validating the code.
		Functions\when('apply_filters')->justReturn(true);

		// clear_2fa_pending + activate stubs.
		Functions\expect('delete_transient')->once()->with('wp_sudo_2fa_pending_' . $challenge_hash);
		Functions\when('update_user_meta')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('get_option')->justReturn(array());
		Functions\when('get_user_meta')->justReturn('');
		Functions\when('wp_generate_password')->justReturn('test-token-abc');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);
		Functions\when('wp_send_json_error')->justReturn(null);

		// Should return authenticated (session-only, no stash_key).
		Functions\expect('wp_send_json_success')
			->once()
			->with(\Mockery::on(function ($data) {
				return is_array($data)
					&& isset($data['code'])
					&& 'authenticated' === $data['code'];
			}));

		$this->challenge->handle_ajax_2fa();
	}

	// -----------------------------------------------------------------
	// handle_ajax_2fa — stash replay after 2FA
	// -----------------------------------------------------------------

	/**
	 * Test handle_ajax_2fa replays stashed request after successful 2FA.
	 */
	public function test_handle_ajax_2fa_replays_stash_on_success(): void
	{
		$challenge_nonce = 'test-challenge-nonce-replay';
		$challenge_hash = hash('sha256', $challenge_nonce);
		$_COOKIE[\WP_Sudo\Sudo_Session::CHALLENGE_COOKIE] = $challenge_nonce;
		$_POST['stash_key'] = 'test-stash-key-abc';

		Functions\expect('check_ajax_referer')->once();
		Functions\when('get_current_user_id')->justReturn(42);
		Functions\expect('get_userdata')->once()->andReturn(new \WP_User(42));
		Functions\when('__')->returnArg();
		Functions\when('sanitize_text_field')->returnArg();

		// Valid pending state.
		Functions\expect('get_transient')
			->once()
			->with('wp_sudo_2fa_pending_' . $challenge_hash)
			->andReturn(array(
				'user_id' => 42,
				'expires_at' => time() + 600,
			));

		// 2FA valid via filter.
		Functions\when('apply_filters')->justReturn(true);

		// clear_2fa_pending + activate stubs.
		Functions\when('delete_transient')->justReturn(true);
		Functions\when('update_user_meta')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('get_option')->justReturn(array());
		Functions\when('get_user_meta')->justReturn('');
		Functions\when('wp_generate_password')->justReturn('test-token-abc');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);
		Functions\when('wp_send_json_error')->justReturn(null);

		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/');

		// replay_stash() will call $this->stash->get().
		$this->stash->shouldReceive('get')
			->once()
			->with('test-stash-key-abc', 42)
			->andReturn(array(
				'method' => 'GET',
				'url' => 'https://example.com/wp-admin/plugins.php?action=activate&plugin=hello.php',
				'rule_id' => 'activate_plugin',
			));

		// replay_stash() consumes the stash.
		$this->stash->shouldReceive('delete')
			->once()
			->with('test-stash-key-abc', 42);

		Functions\when('wp_validate_redirect')->returnArg();

		// Should return success with redirect for GET replay.
		Functions\expect('wp_send_json_success')
			->once()
			->with(\Mockery::on(function ($data) {
				return is_array($data)
					&& 'success' === ($data['code'] ?? '')
					&& isset($data['redirect'])
					&& str_contains($data['redirect'], 'plugins.php');
			}));

		$this->challenge->handle_ajax_2fa();

		unset($_POST['stash_key']);
	}

	// -----------------------------------------------------------------
	// handle_ajax_2fa — Two Factor provider: pre_process_authentication resend
	// -----------------------------------------------------------------

	/**
	 * Test handle_ajax_2fa returns 2fa_resent when pre_process_authentication
	 * returns true (e.g. the Email provider resending a code).
	 */
	public function test_handle_ajax_2fa_returns_resent_on_pre_process(): void
	{
		$challenge_nonce = 'test-challenge-nonce-resend';
		$challenge_hash = hash('sha256', $challenge_nonce);
		$_COOKIE[\WP_Sudo\Sudo_Session::CHALLENGE_COOKIE] = $challenge_nonce;

		Functions\expect('check_ajax_referer')->once();
		Functions\when('get_current_user_id')->justReturn(42);
		Functions\expect('get_userdata')->once()->andReturn(new \WP_User(42));
		Functions\when('__')->returnArg();
		Functions\when('sanitize_text_field')->returnArg();

		// Valid pending state.
		Functions\expect('get_transient')
			->once()
			->with('wp_sudo_2fa_pending_' . $challenge_hash)
			->andReturn(array(
				'user_id' => 42,
				'expires_at' => time() + 600,
			));

		// Set up a mock provider where pre_process_authentication returns true.
		$provider = \Mockery::mock(\Two_Factor_Provider::class);
		$provider->shouldReceive('pre_process_authentication')
			->once()
			->with(\Mockery::type(\WP_User::class))
			->andReturn(true);

		// In production, wp_send_json_success() dies so validate_authentication
		// is never reached. In tests execution continues, so we stub it.
		$provider->shouldReceive('validate_authentication')->andReturn(false);

		\Two_Factor_Core::$mock_provider = $provider;

		// Capture wp_send_json_success calls so we can assert 2fa_resent was first.
		// In production the first call dies; in tests execution continues.
		$success_calls = array();
		Functions\expect('wp_send_json_success')
			->atLeast()
			->once()
			->andReturnUsing(function ($data) use (&$success_calls) {
				$success_calls[] = $data;
			});

		// Fallthrough stubs.
		Functions\when('apply_filters')->justReturn(false);
		Functions\when('wp_send_json_error')->justReturn(null);
		Functions\when('delete_transient')->justReturn(true);
		Functions\when('update_user_meta')->justReturn(true);
		Functions\when('add_user_meta')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('get_option')->justReturn(array());
		Functions\when('get_user_meta')->justReturn('');
		Functions\when('wp_generate_password')->justReturn('test-token-abc');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);

		$this->challenge->handle_ajax_2fa();

		// First wp_send_json_success call should be the 2fa_resent response.
		$this->assertNotEmpty($success_calls, 'wp_send_json_success should have been called.');
		$this->assertSame('2fa_resent', $success_calls[0]['code'] ?? '');
	}

	// -----------------------------------------------------------------
	// handle_ajax_2fa — Two Factor provider: validate_authentication
	// -----------------------------------------------------------------

	/**
	 * Test handle_ajax_2fa succeeds when the Two Factor provider validates.
	 */
	public function test_handle_ajax_2fa_succeeds_via_two_factor_provider(): void
	{
		$challenge_nonce = 'test-challenge-nonce-provider';
		$challenge_hash = hash('sha256', $challenge_nonce);
		$_COOKIE[\WP_Sudo\Sudo_Session::CHALLENGE_COOKIE] = $challenge_nonce;

		Functions\expect('check_ajax_referer')->once();
		Functions\when('get_current_user_id')->justReturn(42);
		Functions\expect('get_userdata')->once()->andReturn(new \WP_User(42));
		Functions\when('__')->returnArg();
		Functions\when('sanitize_text_field')->returnArg();

		// Valid pending state.
		Functions\expect('get_transient')
			->once()
			->with('wp_sudo_2fa_pending_' . $challenge_hash)
			->andReturn(array(
				'user_id' => 42,
				'expires_at' => time() + 600,
			));

		// Set up a mock provider: pre_process returns false, validate returns true.
		$provider = \Mockery::mock(\Two_Factor_Provider::class);
		$provider->shouldReceive('pre_process_authentication')
			->once()
			->andReturn(false);
		$provider->shouldReceive('validate_authentication')
			->once()
			->andReturn(true);

		\Two_Factor_Core::$mock_provider = $provider;

		// apply_filters (wp_sudo_validate_two_factor) — pass through the true value.
		Functions\when('apply_filters')->justReturn(true);

		// clear_2fa_pending + activate stubs.
		Functions\expect('delete_transient')->once()->with('wp_sudo_2fa_pending_' . $challenge_hash);
		Functions\when('update_user_meta')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('get_option')->justReturn(array());
		Functions\when('get_user_meta')->justReturn('');
		Functions\when('wp_generate_password')->justReturn('test-token-abc');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);
		Functions\when('wp_send_json_error')->justReturn(null);

		// Session-only success.
		Functions\expect('wp_send_json_success')
			->once()
			->with(\Mockery::on(function ($data) {
				return is_array($data)
					&& 'authenticated' === ($data['code'] ?? '');
			}));

		$this->challenge->handle_ajax_2fa();
	}

	/**
	 * Test handle_ajax_2fa exits cleanly when sudo is already active and pending state is gone.
	 */
	public function test_handle_ajax_2fa_returns_authenticated_when_session_is_already_active(): void
	{
		$_POST['stash_key'] = 'expired-key';
		$_COOKIE[\WP_Sudo\Sudo_Session::TOKEN_COOKIE] = 'browser-token';

		Functions\expect('check_ajax_referer')->once();
		Functions\when('get_current_user_id')->justReturn(42);
		Functions\expect('get_userdata')->once()->andReturn(new \WP_User(42));
		Functions\when('__')->returnArg();
		Functions\when('sanitize_text_field')->returnArg();

		$expires = time() + 300;
		Functions\when('get_user_meta')->alias(function ($uid, $key, $single = true) use ($expires) {
			if (\WP_Sudo\Sudo_Session::META_KEY === $key) {
				return $expires;
			}
			if (\WP_Sudo\Sudo_Session::TOKEN_META_KEY === $key) {
				return hash('sha256', 'browser-token');
			}
			if (\WP_Sudo\Sudo_Session::THROTTLE_UNTIL_META_KEY === $key || \WP_Sudo\Sudo_Session::LOCKOUT_UNTIL_META_KEY === $key) {
				return '';
			}
			return '';
		});

		Functions\expect('wp_send_json_success')
			->once()
			->with(\Mockery::on(function ($data) {
				return is_array($data)
					&& 'authenticated' === ($data['code'] ?? '');
			}));

		Functions\expect('wp_send_json_error')->never();
		Functions\expect('get_transient')->never();
		Functions\expect('apply_filters')->never();
		Functions\expect('delete_transient')->never();

		$this->stash->shouldReceive('exists')
			->once()
			->with('expired-key', 42)
			->andReturn(false);
		$this->stash->shouldNotReceive('get');

		$this->challenge->handle_ajax_2fa();

		unset($_POST['stash_key'], $_COOKIE[\WP_Sudo\Sudo_Session::TOKEN_COOKIE]);
	}

	// -----------------------------------------------------------------
	// render_page — wp_sudo_render_two_factor_fields action
	// -----------------------------------------------------------------

	/**
	 * Test that render_page fires the wp_sudo_render_two_factor_fields action.
	 */
	public function test_render_page_fires_render_two_factor_fields_action(): void
	{
		$_GET['page'] = 'wp-sudo-challenge';

		Functions\when('get_current_user_id')->justReturn(42);
		Functions\expect('get_userdata')->andReturn(new \WP_User(42));
		Functions\when('__')->returnArg();
		Functions\when('esc_html__')->returnArg();
		Functions\when('esc_html_e')->alias(function ($text) {
			echo $text;
		});
		Functions\when('esc_html')->returnArg();
		Functions\when('esc_attr')->returnArg();
		Functions\when('esc_url')->returnArg();
		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/');
		Functions\when('wp_validate_redirect')->returnArg();
		Functions\when('get_user_meta')->justReturn('');
		Functions\when('disabled')->justReturn('');
		Functions\when('sanitize_url')->returnArg();

		Actions\expectDone('wp_sudo_render_two_factor_fields')
			->once()
			->with(\Mockery::type(\WP_User::class));

		// Session-only mode (no stash_key).
		ob_start();
		$this->challenge->render_page();
		ob_end_clean();

		unset($_GET['page']);
	}

	// -----------------------------------------------------------------
	// Session-only mode (no stash key)
	// -----------------------------------------------------------------

	/**
	 * Test enqueue_assets sets sessionOnly to false when stash key is present.
	 */
	public function test_enqueue_assets_passes_stash_mode_flag(): void
	{
		$_GET['page'] = 'wp-sudo-challenge';
		$_GET['stash_key'] = 'abc123';

		Functions\when('__')->returnArg();
		Functions\when('get_current_user_id')->justReturn(42);

		Functions\expect('wp_enqueue_style')->once();
		Functions\expect('wp_enqueue_script')->once();

		Functions\expect('admin_url')
			->with('admin-ajax.php')
			->andReturn('https://example.com/wp-admin/admin-ajax.php');

		Functions\expect('wp_create_nonce')
			->with(Challenge::NONCE_ACTION)
			->andReturn('test-nonce');

		Functions\expect('wp_localize_script')
			->once()
			->with(
				'wp-sudo-challenge',
				'wpSudoChallenge',
				\Mockery::on(
					function ($data) {
						return isset($data['sessionOnly'])
							&& false === $data['sessionOnly']
							&& 'abc123' === $data['stashKey'];
					}
				)
			);

		$this->challenge->enqueue_assets();

		unset($_GET['page'], $_GET['stash_key']);
	}

	/**
	 * Test render_page detects active throttle and disables form immediately.
	 */
	public function test_render_page_disables_form_when_throttled(): void
	{
		Functions\when('get_current_user_id')->justReturn(42);
		Functions\when('__')->returnArg();
		Functions\when('esc_html__')->returnArg();
		Functions\when('esc_html_e')->returnArg();
		Functions\when('esc_html')->returnArg();
		Functions\when('esc_attr')->returnArg();
		Functions\when('esc_url')->returnArg();
		Functions\when('disabled')->returnArg();
		Functions\when('wp_validate_redirect')->returnArg();
		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/');
		Functions\expect('get_userdata')->andReturn(new \WP_User(42));

		$this->stash->shouldReceive('get')->andReturn(null);

		// Active throttle: 5 seconds.
		Functions\when('get_user_meta')->alias(function ($uid, $key, $single = true) {
			if (\WP_Sudo\Sudo_Session::THROTTLE_UNTIL_META_KEY === $key) {
				return time() + 5;
			}
			return '';
		});

		ob_start();
		$this->challenge->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString('disabled', $output);
		$this->assertMatchesRegularExpression('/Please wait \d+ seconds/', $output);
		$this->assertStringContainsString('wp-sudo-challenge-throttle-notice', $output);
	}

	/**
	 * Test render_page resumes instead of rendering the password form when sudo is already active.
	 */
	public function test_render_page_resumes_when_session_is_already_active(): void
	{
		$_GET['return_url'] = 'https://example.com/wp-admin/plugins.php';
		$_COOKIE[\WP_Sudo\Sudo_Session::TOKEN_COOKIE] = 'browser-token';

		Functions\when('get_current_user_id')->justReturn(42);
		Functions\when('__')->returnArg();
		Functions\when('esc_html__')->returnArg();
		Functions\when('esc_html_e')->alias(function ($text) {
			echo $text;
		});
		Functions\when('esc_html')->returnArg();
		Functions\when('esc_attr')->returnArg();
		Functions\when('esc_url')->returnArg();
		Functions\when('esc_js')->returnArg();
		Functions\when('wp_json_encode')->alias(function ($value) {
			return json_encode($value);
		});
		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/');
		Functions\when('wp_validate_redirect')->returnArg();
		Functions\when('sanitize_url')->returnArg();

		$expires = time() + 300;
		Functions\when('get_user_meta')->alias(function ($uid, $key, $single = true) use ($expires) {
			if (\WP_Sudo\Sudo_Session::META_KEY === $key) {
				return $expires;
			}
			if (\WP_Sudo\Sudo_Session::TOKEN_META_KEY === $key) {
				return hash('sha256', 'browser-token');
			}
			return '';
		});

		Functions\expect('get_userdata')->never();
		Functions\expect('disabled')->never();

		$this->stash->shouldNotReceive('get');
		$this->stash->shouldNotReceive('delete');

		ob_start();
		$this->challenge->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString('Session already confirmed', $output);
		$this->assertStringContainsString('https://example.com/wp-admin/plugins.php', $output);
		$this->assertStringNotContainsString('wp-sudo-challenge-password-form', $output);

		unset($_GET['return_url'], $_COOKIE[\WP_Sudo\Sudo_Session::TOKEN_COOKIE]);
	}
}
