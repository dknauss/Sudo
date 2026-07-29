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

		// #429: every fail-closed landing now classifies its target URL, so these are
		// needed by any test that reaches build_replay_response_data(). Both are pure
		// string helpers, so aliasing the real behaviour keeps the assertions honest.
		Functions\when('wp_parse_url')->alias(
			static function ( string $url, int $component = -1 ) {
				return parse_url($url, $component);
			}
		);
		Functions\when('untrailingslashit')->alias(
			static function ( string $value ): string {
				return rtrim($value, '/\\');
			}
		);
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

		Actions\expectAdded('wp_ajax_' . Challenge::AJAX_REFRESH_NONCE_ACTION)
			->once()
			->with(array($this->challenge, 'handle_ajax_refresh_nonce'), \Mockery::any(), 0);

		Actions\expectAdded('admin_enqueue_scripts')
			->once()
			->with(array($this->challenge, 'enqueue_assets'), \Mockery::any(), 0);

		Actions\expectAdded('admin_notices')
			->once()
			->with(array($this->challenge, 'render_redacted_replay_notice'), \Mockery::any(), 0);

		Actions\expectAdded('network_admin_notices')
			->once()
			->with(array($this->challenge, 'render_redacted_replay_notice'), \Mockery::any(), 0);

		Actions\expectAdded('admin_notices')
			->once()
			->with(array($this->challenge, 'render_blocked_replay_notice'), \Mockery::any(), 0);

		Actions\expectAdded('network_admin_notices')
			->once()
			->with(array($this->challenge, 'render_blocked_replay_notice'), \Mockery::any(), 0);

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
		$this->assertSame('wp_sudo_refresh_grant_nonce', Challenge::AJAX_REFRESH_NONCE_ACTION);
	}

	/**
	 * The nonce-refresh endpoint returns a fresh grant nonce for a logged-in
	 * user and performs no state change (it grants nothing). Lets a long-open
	 * editor re-mint the wp_sudo_challenge CSRF nonce after the localized one
	 * ages out, instead of dead-ending on a stale-nonce check_ajax_referer.
	 */
	public function test_handle_ajax_refresh_nonce_returns_fresh_nonce(): void
	{
		Functions\when('get_current_user_id')->justReturn(7);
		Functions\expect('wp_create_nonce')
			->once()
			->with(Challenge::NONCE_ACTION)
			->andReturn('fresh-nonce-123');
		Functions\expect('wp_send_json_success')
			->once()
			->with(array('nonce' => 'fresh-nonce-123'));

		$this->challenge->handle_ajax_refresh_nonce();
	}

	/**
	 * The nonce-refresh endpoint rejects a request with no current user.
	 */
	public function test_handle_ajax_refresh_nonce_rejects_logged_out(): void
	{
		Functions\when('get_current_user_id')->justReturn(0);
		Functions\expect('wp_send_json_error')->once();
		// The explicit return after wp_send_json_error prevents fallthrough to the
		// success path even when the error helper is a no-op mock (no nonce minted).
		Functions\expect('wp_create_nonce')->never();
		Functions\expect('wp_send_json_success')->never();

		$this->challenge->handle_ajax_refresh_nonce();
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
		Functions\when('get_transient')->justReturn(false);

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

		Functions\when('get_transient')->justReturn(false);

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
			if (\WP_Sudo\Sudo_Session::PROOF_META_KEY === $key) {
				return $this->make_proof_map((int) $uid, 'browser-token', $expires);
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

	// -----------------------------------------------------------------
	// #182: authenticated grant responses carry `remaining` seconds so the
	// in-editor indicator can re-seed its countdown after an in-place grant
	// without a page reload or a new read endpoint. The three emitters of
	// {code:'authenticated'} — session-only handle_ajax_auth, session-only
	// handle_ajax_2fa, and complete_active_session_request — must all include
	// it; the replay path (build_replay_response_data) must NOT.
	// -----------------------------------------------------------------

	/**
	 * #182: session-only handle_ajax_auth success includes a positive `remaining`.
	 */
	public function test_handle_ajax_auth_authenticated_includes_remaining(): void
	{
		$_POST['password'] = 'correct-horse';
		// No stash_key — session-only flow.

		Functions\expect('check_ajax_referer')->once();
		Functions\when('get_current_user_id')->justReturn(42);
		Functions\when('__')->returnArg();

		$user = new \WP_User(42);
		$user->user_pass = 'hashed';
		Functions\expect('get_userdata')->andReturn($user);
		Functions\expect('wp_check_password')->once()->andReturn(true);

		// Stateful meta so activate()'s write is visible to time_remaining():
		// empty at is_active() entry (session inactive → attempt_activation runs),
		// then the future expiry activate() stores becomes readable.
		$meta = array();
		Functions\when('update_user_meta')->alias(function ($uid, $key, $val) use (&$meta) {
			$meta[$key] = $val;
			return true;
		});
		Functions\when('get_user_meta')->alias(function ($uid, $key, $single = true) use (&$meta) {
			return $meta[$key] ?? '';
		});
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('get_option')->justReturn(array());
		Functions\when('apply_filters')->returnArg(2);
		Functions\when('wp_generate_password')->justReturn('test-token-abc');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);
		Functions\when('get_transient')->justReturn(false);

		$captured = null;
		Functions\expect('wp_send_json_success')
			->once()
			->with(\Mockery::on(function ($data) use (&$captured) {
				$captured = $data;
				return is_array($data) && 'authenticated' === ($data['code'] ?? '');
			}));

		$this->stash->shouldNotReceive('exists');

		$this->challenge->handle_ajax_auth();

		$this->assertArrayHasKey('remaining', $captured, 'authenticated response must carry remaining.');
		$this->assertIsInt($captured['remaining']);
		$this->assertGreaterThan(0, $captured['remaining'], 'a just-granted session has positive remaining.');
		$this->assertLessThanOrEqual(15 * 60, $captured['remaining'], 'remaining is bounded by the default 15-min duration.');

		unset($_POST['password']);
	}

	/**
	 * #182: complete_active_session_request (session already active at submit)
	 * includes `remaining`.
	 */
	public function test_complete_active_session_request_includes_remaining(): void
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
			if (\WP_Sudo\Sudo_Session::PROOF_META_KEY === $key) {
				return $this->make_proof_map((int) $uid, 'browser-token', $expires);
			}
			return '';
		});

		$captured = null;
		Functions\expect('wp_send_json_success')
			->once()
			->with(\Mockery::on(function ($data) use (&$captured) {
				$captured = $data;
				return is_array($data) && 'authenticated' === ($data['code'] ?? '');
			}));
		Functions\expect('wp_send_json_error')->never();

		$this->stash->shouldReceive('exists')->once()->with('expired-key', 42)->andReturn(false);
		$this->stash->shouldNotReceive('get');

		$this->challenge->handle_ajax_auth();

		$this->assertArrayHasKey('remaining', $captured, 'active-session completion must carry remaining.');
		$this->assertIsInt($captured['remaining']);
		$this->assertGreaterThan(0, $captured['remaining']);
		$this->assertLessThanOrEqual(300, $captured['remaining']);

		unset($_POST['password'], $_POST['stash_key'], $_COOKIE[\WP_Sudo\Sudo_Session::TOKEN_COOKIE]);
	}

	/**
	 * #182: a GET-replay success (build_replay_response_data) must NOT carry
	 * `remaining` — the editor modal never sends a stash_key, so the replay
	 * path has no indicator consumer and the field must not widen that
	 * security-sensitive response shape.
	 */
	public function test_replay_success_omits_remaining(): void
	{
		$_POST['password'] = 'correct-horse';
		$_POST['stash_key'] = 'good-key';

		Functions\expect('check_ajax_referer')->once();
		Functions\when('get_current_user_id')->justReturn(42);
		Functions\when('__')->returnArg();
		Functions\when('sanitize_text_field')->returnArg();

		$user = new \WP_User(42);
		$user->user_pass = 'hashed';
		Functions\expect('get_userdata')->andReturn($user);
		Functions\expect('wp_check_password')->once()->andReturn(true);

		Functions\when('get_user_meta')->justReturn('');
		Functions\when('update_user_meta')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('get_option')->justReturn(array());
		Functions\when('apply_filters')->returnArg(2);
		Functions\when('wp_generate_password')->justReturn('test-token-abc');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);
		Functions\when('get_transient')->justReturn(false);
		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/');
		Functions\when('wp_validate_redirect')->returnArg();

		// Stash exists and replays as a GET → build_replay_response_data returns
		// {code:'success', redirect:...}.
		$this->stash->shouldReceive('exists')->once()->with('good-key', 42)->andReturn(true);
		$this->stash->shouldReceive('get')->once()->with('good-key', 42)->andReturn(array(
			'url' => 'https://example.com/wp-admin/plugins.php',
			'method' => 'GET',
			'rule_id' => 'test-rule',
		));
		$this->stash->shouldReceive('delete')->once();

		Functions\when('add_query_arg')->alias(
			static function ( string $key, string $value, string $url ): string {
				$separator = str_contains($url, '?') ? '&' : '?';
				return $url . $separator . $key . '=' . $value;
			}
		);

		$captured = null;
		Functions\expect('wp_send_json_success')
			->once()
			->with(\Mockery::on(function ($data) use (&$captured) {
				$captured = $data;
				return is_array($data) && 'success' === ($data['code'] ?? '');
			}));

		$this->challenge->handle_ajax_auth();

		$this->assertArrayNotHasKey('remaining', $captured, 'replay path must not carry remaining.');

		unset($_POST['password'], $_POST['stash_key']);
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
	 * #182: session-only handle_ajax_2fa success includes a positive `remaining`.
	 */
	public function test_handle_ajax_2fa_authenticated_includes_remaining(): void
	{
		$challenge_nonce = 'test-challenge-nonce-2fa-remaining';
		$challenge_hash = hash('sha256', $challenge_nonce);
		$_COOKIE[\WP_Sudo\Sudo_Session::CHALLENGE_COOKIE] = $challenge_nonce;

		Functions\expect('check_ajax_referer')->once();
		Functions\when('get_current_user_id')->justReturn(42);
		Functions\expect('get_userdata')->once()->andReturn(new \WP_User(42));
		Functions\when('__')->returnArg();
		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/');
		Functions\when('sanitize_text_field')->returnArg();

		Functions\expect('get_transient')
			->once()
			->with('wp_sudo_2fa_pending_' . $challenge_hash)
			->andReturn(array(
				'user_id' => 42,
				'expires_at' => time() + 600,
			));
		Functions\when('apply_filters')->justReturn(true);
		Functions\expect('delete_transient')->once()->with('wp_sudo_2fa_pending_' . $challenge_hash);

		// Stateful meta so activate()'s expiry write is visible to time_remaining().
		$meta = array();
		Functions\when('update_user_meta')->alias(function ($uid, $key, $val) use (&$meta) {
			$meta[$key] = $val;
			return true;
		});
		Functions\when('get_user_meta')->alias(function ($uid, $key, $single = true) use (&$meta) {
			return $meta[$key] ?? '';
		});
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('get_option')->justReturn(array());
		Functions\when('wp_generate_password')->justReturn('test-token-abc');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);
		Functions\when('wp_send_json_error')->justReturn(null);

		$captured = null;
		Functions\expect('wp_send_json_success')
			->once()
			->with(\Mockery::on(function ($data) use (&$captured) {
				$captured = $data;
				return is_array($data) && 'authenticated' === ($data['code'] ?? '');
			}));

		$this->stash->shouldNotReceive('get');

		$this->challenge->handle_ajax_2fa();

		$this->assertArrayHasKey('remaining', $captured, '2FA authenticated response must carry remaining.');
		$this->assertIsInt($captured['remaining']);
		$this->assertGreaterThan(0, $captured['remaining']);
		$this->assertLessThanOrEqual(15 * 60, $captured['remaining']);

		unset($_COOKIE[\WP_Sudo\Sudo_Session::CHALLENGE_COOKIE]);
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
		Functions\when('set_transient')->justReturn(true);
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
		Functions\when('get_transient')->justReturn(false);
		Functions\when('set_transient')->justReturn(true);
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
		Functions\when('set_transient')->justReturn(true);

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

		Actions\expectAdded('wp_ajax_' . Challenge::AJAX_REFRESH_NONCE_ACTION)
			->once()
			->with(array($this->challenge, 'handle_ajax_refresh_nonce'), \Mockery::any(), 0);

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

	/**
	 * Single-site reproduction: the Ctrl+Shift+S / Cmd+Shift+S shortcut opens
	 * the challenge page in session-only mode (no stash_key) from the Access
	 * tab (Plugin::enqueue_shortcut() builds return_url from the CURRENT
	 * REQUEST_URI, i.e. options-general.php?page=wp-sudo-settings&tab=access).
	 * enqueue_assets() must parse that return_url back into a cancelUrl that
	 * still contains &tab=access — this is the URL the challenge JS uses for
	 * the code==='authenticated' (session-only-success) redirect target.
	 */
	/**
	 * #322: a stash released without a credential must be distinguishable in audit.
	 *
	 * An independent review mutated the `! $credential_verified` branch away — so
	 * every refusal reported `replay_disabled` — and the suite stayed green. That is
	 * the one value an operator can correlate on (an active-session release, which is
	 * either an ordinary multi-tab resume or a lure that landed on a session-holder;
	 * the server cannot tell them apart). Coverage stopped exactly at the reason that
	 * matters for detection.
	 */
	public function test_release_without_a_credential_is_audited_distinctly(): void
	{
		$this->stash->shouldReceive('get')->once()->andReturn($this->boundPostStash('secret'));
		$this->stash->shouldReceive('delete')->once();
		$this->stubReplayEnv();

		$captured = null;
		Actions\expectDone('wp_sudo_replay_refused')
			->once()
			->whenHappen(function ($user_id, $rule_id, $reason) use (&$captured) {
				$captured = $reason;
			});

		$this->invokeReplay('no-credential-key', false);

		$this->assertSame(
			'no_credential_this_request',
			$captured,
			'An active-session release must not be flattened into the ordinary disabled-replay reason.'
		);
	}

	/**
	 * #322: the "session already confirmed" page must not auto-navigate.
	 *
	 * An independent review mutated this fix away — re-inserting the deleted
	 * DOMContentLoaded redirect — and the entire suite stayed green. A guard no test
	 * kills is not a guard: it can be lost to a careless merge, a rebase conflict
	 * resolution, or the planned 4.10 cleanup of the surrounding dormant machinery,
	 * with CI reporting success the whole way.
	 *
	 * The page must render the Continue and Cancel anchors and NOTHING that navigates
	 * on load, so the second action stays explicit.
	 */
	public function test_resume_page_does_not_auto_navigate(): void
	{
		Functions\when('__')->returnArg();
		Functions\when('esc_html__')->returnArg();
		Functions\when('esc_html_e')->alias(static function ($t) { echo $t; });
		Functions\when('esc_url')->returnArg();
		Functions\when('esc_attr')->returnArg();
		Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));

		$this->stash->shouldReceive('exists')->andReturn(false);

		$method = new \ReflectionMethod($this->challenge, 'render_resume_page');
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible(true);
		}

		ob_start();
		$method->invoke($this->challenge, 42, '', 'https://example.com/wp-admin/');
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString(
			'window.location.href',
			$html,
			'The resume page must not navigate on load — the Continue click is the explicit second action.'
		);
        $this->assertStringNotContainsString(
			'DOMContentLoaded',
			$html,
			'No load-time script may run here at all.'
		);
		$this->assertStringContainsString('Continue', $html, 'The explicit action must still be offered.');
	}

	/**
	 * #322: the shipped JS must not navigate to `config.cancelUrl` on success.
	 *
	 * This asserts against the source file, which is unusual and deliberate. The
	 * client half of the invariant cannot be reached any other way here: there is no
	 * JavaScript unit harness in this repository, and an end-to-end test cannot
	 * observe the mutation either — because the server now forces `cancelUrl` to the
	 * neutral page, restoring `config.cancelUrl` in a success branch is *currently*
	 * harmless. It is only dangerous in combination with a future regression on the
	 * PHP side, which is exactly the combination nobody tests.
	 *
	 * So the guard is placed where the property lives: the success branches navigate
	 * via `neutralDestination()`, and nothing else. An independent review restored a
	 * `window.location.href = config.cancelUrl` success branch and watched the whole
	 * PHP suite stay green; this is what turns that mutation red.
	 *
	 * If the JS is ever restructured, update this test to match the new shape rather
	 * than deleting it — the property it encodes outlives the current file layout.
	 */
	public function test_challenge_js_never_navigates_to_cancel_url_on_success(): void
	{
		$js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/js/wp-sudo-challenge.js' );

		$this->assertNotSame('', $js, 'The challenge script must be readable.');

		// The success paths: authenticated (password), authenticated (2FA), sessionOnly.
		$this->assertSame(
			3,
			substr_count($js, 'window.location.href = neutralDestination();'),
			'All three success branches must navigate to the server-chosen neutral page.'
		);

		// `cancelUrl` may still back the Escape/Cancel affordance, but must never be
		// assigned to location in a success branch.
		$this->assertStringNotContainsString(
			"window.location.href = config.cancelUrl ||",
			$js,
			'A success branch must never fall back to the requester-supplied cancelUrl.'
		);

		$this->assertStringContainsString(
			'function neutralDestination()',
			$js,
			'The single navigation helper must exist.'
		);

		// Pin the BODY, not just the call sites. An independent review mutated
		// `return config.neutralUrl || …` to `return config.cancelUrl || …` and every
		// assertion above still held — the call-site count was unchanged and the
		// forbidden literal was `return`, not `window.location.href =`. That is the
		// one line deciding where all three success branches go, and no browser test
		// can catch it either: `cancelUrl` and `neutralUrl` are the same PHP variable
		// on every request, so the mutant is observationally equivalent end to end.
		$body = (string) preg_replace(
			'/^.*function neutralDestination\(\)\s*\{(.*?)\}.*$/s',
			'$1',
			$js
		);

		$this->assertStringContainsString(
			'config.neutralUrl',
			$body,
			'neutralDestination() must resolve the server-chosen neutral page.'
		);
		$this->assertStringNotContainsString(
			'cancelUrl',
			$body,
			'neutralDestination() must not resolve anything on the cancel path.'
		);

		// Pin the TOTAL number of cancelUrl uses, not just the shape of the ones we
		// know about. The count assertion above catches a success branch being
		// REPLACED; it cannot catch one being ADDED — a review mutant that appended a
		// new `code === "resumed"` branch reading config.cancelUrl left all three
		// existing calls intact and survived the whole suite. Additions are how this
		// file will actually grow, so the guard has to bound them.
		//
		// The two permitted uses are the Escape-key affordance: its `if` condition and
		// its assignment. If you are adding a legitimate third, this test is asking
		// you to justify it in review rather than blocking you.
		$code = (string) preg_replace('#/\*.*?\*/#s', '', $js);
		$code = (string) preg_replace('#^\s*//.*$#m', '', $code);

		$this->assertSame(
			2,
			substr_count($code, 'config.cancelUrl'),
			'cancelUrl may be read only by the Escape-key affordance (its condition and '
				. 'its assignment). A third use means a new navigation path can reach the '
				. 'cancel value — state why in review.'
		);
	}

	/**
	 * #322: the client-side replay engine must stay removed.
	 *
	 * `handleReplay()` used to build a hidden form from `data.replay`/`data.url`/
	 * `data.post_data` and auto-submit it with
	 * `HTMLFormElement.prototype.submit.call()` — chosen deliberately so a stashed
	 * field named `submit` could not shadow the method. It is gone.
	 *
	 * Nothing asserted that it stays gone: an independent review re-added a working
	 * branch and the entire suite remained green. The invariant's client half was
	 * correct by authorship rather than by test, which is the state this release
	 * exists to stop accepting.
	 */
	public function test_challenge_js_contains_no_form_submission_engine(): void
	{
		$js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/js/wp-sudo-challenge.js' );

		$this->assertNotSame('', $js);

		// Strip comments first: this asserts about CODE, not prose. The file
		// deliberately explains what was removed and why, and that explanation is
		// worth keeping — a future reader who finds no trace of the engine has no
		// way to know it was a decision rather than an oversight.
		$code = (string) preg_replace('#/\*.*?\*/#s', '', $js);
		$code = (string) preg_replace('#^\s*//.*$#m', '', $code);

		foreach (
			array(
				'HTMLFormElement.prototype.submit' => 'the shadow-proof auto-submitter',
				"createElement('form')"            => 'form construction',
				'appendFields'                     => 'the recursive hidden-input builder',
				'post_data'                        => 'the stashed request body',
				'.replay'                          => 'the replay branch selector',
			) as $needle => $what
		) {
			$this->assertStringNotContainsString(
				$needle,
				$code,
				'The challenge script must not reintroduce ' . $what . '.'
			);
		}
	}

	/**
	 * #322 blunt rule: no requester-supplied destination survives, ever.
	 *
	 * An earlier cut classified `return_url` — same host, then strip `action` and
	 * nonce params. That failed on the case with nothing to strip: a **queryless**
	 * custom-action path (`options-general.php?page=…`, `tools.php?page=…`, or any
	 * route whose effect rides the path) passed every filter and was navigated to
	 * automatically on password success, under the sudo authority just minted.
	 *
	 * @dataProvider provideRequesterSuppliedDestinations
	 *
	 * @param string $return_url The attacker-supplied value.
	 * @param string $label      Case description.
	 */
	public function test_cancel_url_is_never_the_requester_destination(string $return_url, string $label): void
	{
		$_GET['page'] = 'wp-sudo-challenge';
		$_GET['return_url'] = $return_url;

		$captured = $this->captureLocalizedChallengeConfig();

		$this->assertIsArray($captured);
		$this->assertSame(
			'https://example.com/wp-admin/',
			$captured['cancelUrl'],
			$label . ': cancelUrl must be the server-chosen neutral page.'
		);
		$this->assertSame(
			'https://example.com/wp-admin/',
			$captured['neutralUrl'],
			$label . ': neutralUrl is what every success branch navigates to.'
		);
	}

	/**
	 * Run enqueue_assets() and return the array it localises.
	 *
	 * @return array<string, mixed>|null
	 */
	private function captureLocalizedChallengeConfig(): ?array
	{
		Functions\when('__')->returnArg();
		Functions\when('get_current_user_id')->justReturn(42);
		Functions\when('wp_enqueue_style')->justReturn(null);
		Functions\when('wp_enqueue_script')->justReturn(null);
		Functions\when('wp_create_nonce')->justReturn('test-nonce');
		Functions\when('sanitize_text_field')->alias(static fn($value) => $value);
		Functions\when('wp_unslash')->alias(static fn($value) => $value);
		Functions\when('admin_url')->alias(
			static fn(string $path = ''): string => 'https://example.com/wp-admin/' . $path
		);
		Functions\when('wp_validate_redirect')->alias(
			static function (string $location, $fallback = '') {
				$host = parse_url($location, PHP_URL_HOST);
				return ('example.com' === $host) ? $location : $fallback;
			}
		);

		$captured = null;
		Functions\expect('wp_localize_script')
			->once()
			->with(
				'wp-sudo-challenge',
				'wpSudoChallenge',
				\Mockery::on(function ($data) use (&$captured) {
					$captured = $data;
					return true;
				})
			);

		$this->challenge->enqueue_assets();

		return $captured;
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provideRequesterSuppliedDestinations(): array
	{
		return array(
			'queryless custom action path' => array(
				'https://example.com/wp-admin/options-general.php?page=acme-danger',
				'queryless custom action',
			),
			'tools.php plugin screen' => array(
				'https://example.com/wp-admin/tools.php?page=acme-wipe',
				'tools.php custom page',
			),
			'classic action + nonce' => array(
				'https://example.com/wp-admin/plugins.php?action=activate&plugin=evil%2Fevil.php&_wpnonce=VALID',
				'action + nonce',
			),
			'bare path with no query at all' => array(
				'https://example.com/wp-admin/acme-handler.php',
				'bare path',
			),
			'foreign host' => array(
				'https://evil.example/collect',
				'foreign host',
			),
		);
	}

	/**
	 * #322: cancelUrl must remain same-host.
	 *
	 * Guards the `wp_validate_redirect()` call itself. Without a test here, dropping
	 * that call turns the sink above into a full cross-host open redirect for the
	 * victim's authenticated browser and nothing goes red — one of two branches an
	 * independent review found surviving mutation.
	 */
	public function test_enqueue_assets_cancel_url_rejects_a_foreign_host(): void
	{
		$_GET['page'] = 'wp-sudo-challenge';
		$_GET['return_url'] = 'https://evil.example/collect?x=1';

		$captured = $this->captureLocalizedChallengeConfig();

		$this->assertIsArray($captured);
		$this->assertStringNotContainsString('evil.example', $captured['cancelUrl'], 'A foreign host must never survive.');
	}

	/**
	 * REMOVED in 4.9.0 (#322): cancelUrl no longer preserves `tab=access`.
	 *
	 * This asserted that the challenge returned the user to the Settings tab they
	 * came from, by carrying their `return_url` through. That convenience is
	 * deliberately traded away: any requester-supplied destination reached after a
	 * successful challenge executes under the sudo authority just minted, and every
	 * attempt to keep the value while filtering it failed on the case with nothing
	 * to filter. The destination is now server-chosen unconditionally.
	 *
	 * Recorded rather than silently deleted so the loss is a decision, not a
	 * regression someone re-adds later.
	 */

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
		Functions\when('set_transient')->justReturn(true);
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
	// handle_ajax_2fa — IP lockout enforcement (F7)
	// -----------------------------------------------------------------

	/**
	 * The per-IP lockout from the password-verification path must also be
	 * enforced at 2FA entry. Without this one extra validation attempt leaks
	 * per already-pending account despite an active IP lockout (F7).
	 */
	public function test_handle_ajax_2fa_rejects_when_request_ip_is_locked_out(): void
	{
		$challenge_nonce = 'test-challenge-nonce-ip-lock';
		$challenge_hash  = hash( 'sha256', $challenge_nonce );
		$_COOKIE[\WP_Sudo\Sudo_Session::CHALLENGE_COOKIE] = $challenge_nonce;

		$_SERVER['REMOTE_ADDR'] = '192.0.2.77';
		// Key format after F6 fix: hash("$ip|$user_id") — scoped per-user.
		$ip_key = \WP_Sudo\Sudo_Session::IP_LOCKOUT_UNTIL_TRANSIENT_PREFIX
			. hash( 'sha256', '192.0.2.77|42' );

		Functions\expect( 'check_ajax_referer' )->once();
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		Functions\expect( 'get_userdata' )->once()->andReturn( new \WP_User( 42 ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) use ( $challenge_hash, $ip_key ): mixed {
				if ( 'wp_sudo_2fa_pending_' . $challenge_hash === $key ) {
					return array( 'user_id' => 42, 'expires_at' => time() + 600 );
				}
				if ( $ip_key === $key ) {
					return time() + 300; // IP locked out.
				}
				return false;
			}
		);

		Functions\when( 'get_user_meta' )->justReturn( '' ); // No per-user throttle/lockout.

		$error = null;
		Functions\expect( 'wp_send_json_error' )
			->once()
			->andReturnUsing( function ( $data, $status = 200 ) use ( &$error ): void {
				$error = array( 'data' => $data, 'status' => $status );
				throw new \RuntimeException( 'stop' );
			} );

		try {
			$this->challenge->handle_ajax_2fa();
			$this->fail( 'Expected early wp_send_json_error.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'stop', $e->getMessage() );
		}

		$this->assertIsArray( $error );
		$this->assertSame( 429, $error['status'] );
		$this->assertSame( 'locked_out', $error['data']['code'] ?? '' );
		$this->assertGreaterThan( 0, $error['data']['remaining'] ?? 0 );
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

		Functions\when('add_query_arg')->alias(
			static function ( string $key, string $value, string $url ): string {
				$separator = str_contains($url, '?') ? '&' : '?';
				return $url . $separator . $key . '=' . $value;
			}
		);

		// #322: reauth lands on a neutral admin URL + blocked-replay notice.
		Functions\expect('wp_send_json_success')
			->once()
			->with(\Mockery::on(function ($data) {
				return is_array($data)
					&& 'success' === ($data['code'] ?? '')
					&& isset($data['redirect'])
					// #322: lands on the originating screen (plugins.php) WITHOUT the
					// action query, plus the blocked-replay notice — never a replay.
					&& str_contains($data['redirect'], 'plugins.php')
					&& ! str_contains($data['redirect'], 'action=activate')
					&& str_contains($data['redirect'], 'wp_sudo_blocked_replay=1');
			}));

		$this->challenge->handle_ajax_2fa();

		unset($_POST['stash_key']);
	}

	/**
	 * Test redacted secret fields redirect back instead of replaying partial POST data.
	 */
	public function test_redacted_secret_stash_redirects_instead_of_post_replay(): void
	{
		$this->stash->shouldReceive('get')
			->once()
			->with('redacted-stash-key', 42)
			->andReturn(array(
				'method' => 'POST',
				// Bare, because that is the shape core's stock profile form produces: it
				// posts to a queryless self_admin_url() with `action` and `user_id` as
				// HIDDEN POST FIELDS (GB-USER-EDIT-FORM-ACTION, GB-USER-EDIT-USER-ID-FIELD).
				// The `?user_id=42` this carried until #533 was the only query-bearing
				// user-edit.php stash URL in the suite, and it did not match the form the
				// rule under test is reached from — a fixture shaped like a bug rather
				// than like the system, which would have made a query-preserving "fix"
				// look correct. Not a claim that no request can be query-bearing: one
				// posted directly to user-edit.php?user_id=42 is, and lands by path.
				'url' => 'https://example.com/wp-admin/user-edit.php',
				'return_url' => 'https://example.com/wp-admin/profile.php',
				'rule_id' => 'user.change_password',
				'post' => array(
					'_wpnonce' => 'abc123',
				),
				'redacted_fields_omitted' => true,
			));

		$this->stash->shouldReceive('delete')
			->once()
			->with('redacted-stash-key', 42);

		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/');
		Functions\when('wp_validate_redirect')->returnArg();
		Functions\when('current_user_can')->justReturn(true);
		Functions\when('add_query_arg')->alias(
			static function ( string $key, string $value, string $url ): string {
				$separator = str_contains($url, '?') ? '&' : '?';
				return $url . $separator . $key . '=' . $value;
			}
		);

		Actions\expectDone('wp_sudo_action_replayed')->never();

		$method = new \ReflectionMethod($this->challenge, 'build_replay_response_data');
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible(true);
		}
		$data = $method->invoke($this->challenge, 42, 'redacted-stash-key', 'https://example.com/wp-admin/');

		$this->assertSame('success', $data['code']);
		$this->assertArrayHasKey('redirect', $data);
		// #322: redirect is a neutral admin URL, NOT the attacker-controllable return_url.
		$this->assertStringNotContainsString('profile.php', $data['redirect']);
		$this->assertStringContainsString('/wp-admin/', $data['redirect']);
		// #469: one notice arg now, whatever the stash recorded. The redacted variant
		// claimed secrets were the reason and implied the rest of the request landed;
		// both were false, and the flag it keyed on does not track secrets anyway.
		$this->assertStringContainsString('wp_sudo_blocked_replay=1', $data['redirect']);
		$this->assertStringNotContainsString('wp_sudo_redacted_replay=1', $data['redirect']);
		// The response field is retained and still true: it accurately reports what
		// sanitize_params() did. Only the notice wording drawn from it was wrong.
		$this->assertTrue($data['redacted_fields_omitted']);
		$this->assertArrayNotHasKey('replay', $data);
		$this->assertArrayNotHasKey('post_data', $data);
	}

	/**
	 * Test blocked POST replay redirects back with a non-replay notice.
	 */
	public function test_blocked_post_replay_redirects_instead_of_partial_replay(): void
	{
		$this->stash->shouldReceive('get')
			->once()
			->with('blocked-stash-key', 42)
			->andReturn(array(
				'method' => 'POST',
				'url' => 'https://example.com/wp-admin/update.php?action=upload-plugin',
				'return_url' => 'https://example.com/wp-admin/plugin-install.php?tab=upload',
				'rule_id' => 'plugin.upload',
				'post' => array(),
				'post_replay_blocked' => true,
				'post_replay_block_reason' => Request_Stash::REPLAY_BLOCKED_NO_REPLAY,
			));

		$this->stash->shouldReceive('delete')
			->once()
			->with('blocked-stash-key', 42);

		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/');
		Functions\when('wp_validate_redirect')->returnArg();
		Functions\when('add_query_arg')->alias(
			static function ( string $key, string $value, string $url ): string {
				$separator = str_contains($url, '?') ? '&' : '?';
				return $url . $separator . $key . '=' . $value;
			}
		);

		Actions\expectDone('wp_sudo_action_replayed')->never();

		$method = new \ReflectionMethod($this->challenge, 'build_replay_response_data');
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible(true);
		}
		$data = $method->invoke($this->challenge, 42, 'blocked-stash-key', 'https://example.com/wp-admin/');

		$this->assertSame('success', $data['code']);
		$this->assertArrayHasKey('redirect', $data);
		// #322: redirect is a neutral admin URL, NOT the attacker-controllable return_url.
		$this->assertStringNotContainsString('plugin-install.php', $data['redirect']);
		$this->assertStringContainsString('/wp-admin/', $data['redirect']);
		$this->assertStringContainsString('wp_sudo_blocked_replay=1', $data['redirect']);
		$this->assertFalse($data['redacted_fields_omitted']);
		$this->assertTrue($data['post_replay_blocked']);
		$this->assertArrayNotHasKey('replay', $data);
		$this->assertArrayNotHasKey('post_data', $data);
	}

	// -----------------------------------------------------------------
	// #322 — stash auto-replay is a confused deputy (fail-closed).
	// The stash is bound to user_id only, so a cloned session can plant one
	// and the victim's reauth would execute the attacker's transaction. The
	// chokepoint (build_replay_response_data) must NOT auto-replay ANY gated
	// stash and must NOT redirect to the stashed action URL — regardless of
	// method or whether the rule was flagged no-replay/redacted.
	// -----------------------------------------------------------------

	/**
	 * #322: a normal (non-flagged) POST stash must not auto-replay.
	 */
	public function test_gated_post_stash_does_not_auto_replay(): void
	{
		$this->stash->shouldReceive('get')
			->once()
			->with('planted-post', 42)
			->andReturn(array(
				'method' => 'POST',
				'url' => 'https://example.com/wp-admin/users.php',
				'return_url' => 'https://example.com/wp-admin/user-new.php',
				'rule_id' => 'user.create',
				'post' => array('role' => 'administrator'),
			));

		$this->stash->shouldReceive('delete')->once()->with('planted-post', 42);

		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/');
		Functions\when('wp_validate_redirect')->returnArg();
		Functions\when('add_query_arg')->alias(
			static function ( string $key, string $value, string $url ): string {
				$separator = str_contains($url, '?') ? '&' : '?';
				return $url . $separator . $key . '=' . $value;
			}
		);

		$method = new \ReflectionMethod($this->challenge, 'build_replay_response_data');
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible(true);
		}
		$data = $method->invoke($this->challenge, 42, 'planted-post', 'https://example.com/wp-admin/');

		$this->assertSame('success', $data['code']);
		$this->assertArrayNotHasKey('replay', $data, 'A planted POST stash must not auto-replay.');
		$this->assertArrayNotHasKey('post_data', $data);
		$this->assertArrayHasKey('redirect', $data);
	}

	/**
	 * #322: a normal GET stash must not redirect to the stashed action URL
	 * (the confused-deputy replay for GET actions: plugin activate, site delete).
	 */
	public function test_gated_get_stash_does_not_redirect_to_action_url(): void
	{
		$this->stash->shouldReceive('get')
			->once()
			->with('planted-get', 42)
			->andReturn(array(
				'method' => 'GET',
				'url' => 'https://example.com/wp-admin/plugins.php?action=activate&plugin=evil%2Fevil.php&_wpnonce=abc',
				'return_url' => 'https://example.com/wp-admin/plugins.php',
				'rule_id' => 'plugin.activate',
				'post' => array(),
			));

		$this->stash->shouldReceive('delete')->once()->with('planted-get', 42);

		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/');
		Functions\when('wp_validate_redirect')->returnArg();
		Functions\when('add_query_arg')->alias(
			static function ( string $key, string $value, string $url ): string {
				$separator = str_contains($url, '?') ? '&' : '?';
				return $url . $separator . $key . '=' . $value;
			}
		);

		$method = new \ReflectionMethod($this->challenge, 'build_replay_response_data');
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible(true);
		}
		$data = $method->invoke($this->challenge, 42, 'planted-get', 'https://example.com/wp-admin/');

		$this->assertSame('success', $data['code']);
		$this->assertArrayNotHasKey('replay', $data);
		$this->assertArrayHasKey('redirect', $data);
		$this->assertStringNotContainsString(
			'action=activate',
			$data['redirect'],
			'Must not redirect the victim browser to the stashed action URL.'
		);
		// #322 v1 soft landing: returns to the originating screen (the admin page),
		// minus the effect-bearing query — one re-click, no replay.
		$this->assertStringContainsString('plugins.php', $data['redirect']);
		$this->assertStringContainsString('wp_sudo_blocked_replay=1', $data['redirect']);
	}

	/**
	 * #429: a refused POST must land on its ORIGINATING SCREEN, not the dashboard.
	 *
	 * This is a regression guard, not a preference. v4.8.0 redirected a refused POST
	 * to the stashed return_url — the form — so the notice named something the user
	 * could actually act on. #322 v1 removed that (return_url is referer-derived and
	 * therefore requester-chosen, so redirecting to it re-opened the confused deputy)
	 * and landed POSTs on the dashboard instead, where the notice tells the user to
	 * do again an action whose form is nowhere on screen.
	 *
	 * The fix is the treatment the GET path already had: derive the screen from
	 * $stash['url'], which is the URL the gate intercepted rather than a value the
	 * request supplied.
	 *
	 * The empty return_url below is not a convenience — it is what Request_Stash
	 * actually stores for this request, and getting that wrong is what sank the first
	 * attempt at this fix. The wp-admin/user-new.php forms carry no action attribute,
	 * so they post to their own URI, and wp_get_referer() discards a referer equal to
	 * REQUEST_URI — see GB-USER-NEW-SELFPOST and GB-REFERER-SELFPOST in
	 * docs/upstream-sources.md. Any fixture here that supplies a return_url for a
	 * self-posting form is testing a request that cannot happen.
	 */
	public function test_refused_post_lands_on_originating_screen_not_dashboard(): void
	{
		$this->stash->shouldReceive('get')
			->once()
			->with('gated-post', 42)
			->andReturn(array(
				'method' => 'POST',
				// user-new.php posts to itself, so GETting it renders the form again.
				'url' => 'https://example.com/wp-admin/user-new.php',
				'return_url' => '',
				'rule_id' => 'user.create',
				'post' => array('role' => 'administrator'),
				'redacted_fields_omitted' => true,
			));

		$this->stash->shouldReceive('delete')->once()->with('gated-post', 42);
		$this->stubReplayEnv();

		$data = $this->invokeReplay('gated-post', false);

		$this->assertSame('success', $data['code']);
		$this->assertArrayNotHasKey('replay', $data);
		$this->assertStringContainsString(
			'user-new.php',
			$data['redirect'],
			'A refused POST must return the user to the screen the request came from.'
		);
		// The landing must be the originating screen, not the dashboard. #469 removed
		// the "re-enter them" wording this once guarded against, but the landing
		// requirement is independent of the copy and outlives it: user-new.php is the
		// one screen where the redaction flag can actually be raised by a builtin
		// rule, so it is exactly where a dashboard landing would strand the user.
		$this->assertNotSame(
			'https://example.com/wp-admin/?' . \WP_Sudo\Challenge::BLOCKED_REPLAY_QUERY_ARG . '=1',
			$data['redirect'],
			'A refused user.create POST must not land on the dashboard.'
		);
	}

	/**
	 * #429: a POST to a HANDLER must not be returned to that handler.
	 *
	 * The Settings API posts to options.php, which on GET renders the raw All
	 * Settings dump rather than the form the user was filling in — a worse landing
	 * than the dashboard, not a better one. Only self-posting screens are returned to.
	 */
	public function test_refused_post_to_a_handler_lands_on_the_neutral_page(): void
	{
		$this->stash->shouldReceive('get')
			->once()
			->with('gated-handler', 42)
			->andReturn(array(
				'method' => 'POST',
				'url' => 'https://example.com/wp-admin/options.php',
				'return_url' => 'https://example.com/wp-admin/options-general.php?page=wp-sudo-settings',
				'rule_id' => 'settings.sudo',
				'post' => array('session_duration' => '14'),
				'post_replay_blocked' => true,
			));

		$this->stash->shouldReceive('delete')->once()->with('gated-handler', 42);
		$this->stubReplayEnv();

		$data = $this->invokeReplay('gated-handler', false);

		$this->assertStringNotContainsString(
			'options.php',
			$data['redirect'],
			'options.php renders All Settings on GET — never strand the user there.'
		);
		$this->assertStringContainsString('wp_sudo_blocked_replay=1', $data['redirect']);
	}

	/**
	 * #429 safety: return_url must not influence the landing at all.
	 *
	 * return_url is derived from wp_get_referer(), so the request that planted the
	 * stash chooses it. The landing is derived solely from $stash['url'] — it is not
	 * read as a redirect target and not read to classify the landing either, so a
	 * forged value can neither redirect the victim nor steer which branch runs.
	 * Pointing it at a destructive same-host action URL must change nothing; this is
	 * the confused deputy #322 v1 closed.
	 */
	public function test_forged_return_url_is_never_the_landing_spot(): void
	{
		$this->stash->shouldReceive('get')
			->once()
			->with('lured-post', 42)
			->andReturn(array(
				'method' => 'POST',
				'url' => 'https://example.com/wp-admin/user-new.php',
				'return_url' => 'https://example.com/wp-admin/plugins.php?action=activate&plugin=evil%2Fevil.php&_wpnonce=abc',
				'rule_id' => 'user.create',
				'post' => array('role' => 'administrator'),
				'post_replay_blocked' => true,
			));

		$this->stash->shouldReceive('delete')->once()->with('lured-post', 42);
		$this->stubReplayEnv();

		$data = $this->invokeReplay('lured-post', false);

		$this->assertStringNotContainsString('plugins.php', $data['redirect']);
		$this->assertStringNotContainsString('action=activate', $data['redirect']);
		$this->assertStringNotContainsString('_wpnonce', $data['redirect']);
		// The forged value must not steer the branch either: the landing is still the
		// screen derived from $stash['url'].
		$this->assertStringContainsString('user-new.php', $data['redirect']);
	}

	/**
	 * #429: the POST landing keeps the GET path's query stripping.
	 *
	 * A POST URL may still carry an effect-bearing query, and a rule reading $_REQUEST
	 * would see it on the GET that follows. Returning the user to the screen must
	 * never carry the effect back with it.
	 */
	public function test_refused_post_landing_strips_the_query(): void
	{
		$this->stash->shouldReceive('get')
			->once()
			->with('gated-post-query', 42)
			->andReturn(array(
				'method' => 'POST',
				// users.php renders the Users list on a bare GET, so it is a real screen
				// to return to. (An earlier revision used user-edit.php here and asserted
				// the landing kept it — pinning a wp_die() dead end as correct.)
				'url' => 'https://example.com/wp-admin/users.php?action=wp_sudo_danger&user=5&_wpnonce=abc',
				'return_url' => '',
				'rule_id' => 'custom.rule',
				'post' => array('confirm' => '1'),
				'post_replay_blocked' => true,
			));

		$this->stash->shouldReceive('delete')->once()->with('gated-post-query', 42);
		$this->stubReplayEnv();

		$data = $this->invokeReplay('gated-post-query', false);

		$this->assertStringContainsString('users.php', $data['redirect']);
		$this->assertStringNotContainsString('action=wp_sudo_danger', $data['redirect']);
		$this->assertStringNotContainsString('_wpnonce', $data['redirect']);
		$this->assertStringNotContainsString('user=5', $data['redirect']);
	}

	// -----------------------------------------------------------------
	// Handler landing map (#533)
	//
	// A handler renders nothing usable on a bare GET, so the landing may not go
	// there. The dashboard was the only alternative, which is why editing another
	// user's profile dumped the operator on the dashboard with their work gone AND
	// their place lost. Where a handler has a sibling screen that IS usable, land
	// there instead.
	//
	// Deliberately NOT a query allowlist. On core's stock profile form there is no
	// query to preserve: it posts to a queryless self_admin_url() with `action=update`
	// and `user_id` as hidden POST fields (GB-USER-EDIT-FORM-ACTION,
	// GB-USER-EDIT-USER-ID-FIELD). That is a claim about core's form, not about every
	// request — Request_Stash::build_original_url() builds from REQUEST_URI verbatim,
	// so a third-party form posting to user-edit.php?user_id=42 does put `user_id` in
	// the stashed URL. A query allowlist would still be the wrong instrument there.
	// A destination is also bounded by a static map, which a preserved parameter is not:
	// the path selects between the map's site and network forms, but cannot name a
	// target absent from it.
	// -----------------------------------------------------------------

	/**
	 * A refused user-edit.php POST lands on the Users list, not the dashboard.
	 */
	public function test_handler_landing_sends_user_edit_to_the_users_list(): void
	{
		$this->stash->shouldReceive('get')
			->once()
			->with('gated-user-edit', 42)
			->andReturn(array(
				'method' => 'POST',
				'url' => 'https://example.com/wp-admin/user-edit.php',
				'return_url' => '',
				'rule_id' => 'user.promote_profile',
				'post' => array(),
				'post_replay_blocked' => true,
			));

		$this->stash->shouldReceive('delete')->once()->with('gated-user-edit', 42);
		$this->stubReplayEnv();
		Functions\when('current_user_can')->justReturn(true);

		$data = $this->invokeReplay('gated-user-edit', false);

		$this->assertStringContainsString('/wp-admin/users.php', $data['redirect']);
		$this->assertStringNotContainsString('user-edit.php', $data['redirect']);
	}

	/**
	 * The sibling is derived from the URL's own path, so network admin stays network.
	 *
	 * is_handler_endpoint() classifies from the path rather than is_network_admin()
	 * because that answer is ambient to the request (GB-IS-NETWORK-ADMIN). The
	 * landing must be derived the same way or it reintroduces the dependency that
	 * reasoning exists to avoid — sending a network operator to the site Users list.
	 */
	public function test_handler_landing_keeps_network_admin_in_network_admin(): void
	{
		$this->stash->shouldReceive('get')
			->once()
			->with('gated-network-user-edit', 42)
			->andReturn(array(
				'method' => 'POST',
				'url' => 'https://example.com/wp-admin/network/user-edit.php',
				'return_url' => '',
				'rule_id' => 'network.super_admin',
				'post' => array(),
				'post_replay_blocked' => true,
			));

		$this->stash->shouldReceive('delete')->once()->with('gated-network-user-edit', 42);
		$this->stubReplayEnv();
		Functions\when('current_user_can')->justReturn(true);

		$data = $this->invokeReplay('gated-network-user-edit', false);

		$this->assertStringContainsString('/wp-admin/network/users.php', $data['redirect']);
	}

	/**
	 * Without the capability for the sibling screen, fall back to the neutral page.
	 *
	 * user-edit.php acts as the profile page when user_id === the current user, so a
	 * non-administrator can reach it for their own account and trip
	 * user.change_password. Landing them on users.php would show "Sorry, you are not
	 * allowed to access this page" — worse than the dashboard, not better.
	 */
	public function test_handler_landing_falls_back_when_the_user_cannot_use_the_sibling(): void
	{
		$this->stash->shouldReceive('get')
			->once()
			->with('gated-no-cap', 42)
			->andReturn(array(
				'method' => 'POST',
				'url' => 'https://example.com/wp-admin/user-edit.php',
				'return_url' => '',
				'rule_id' => 'user.change_password',
				'post' => array(),
				'post_replay_blocked' => true,
			));

		$this->stash->shouldReceive('delete')->once()->with('gated-no-cap', 42);
		$this->stubReplayEnv();
		Functions\when('current_user_can')->justReturn(false);

		$data = $this->invokeReplay('gated-no-cap', false);

		$this->assertStringNotContainsString('users.php', $data['redirect']);
		$this->assertStringNotContainsString('user-edit.php', $data['redirect']);
		$this->assertStringStartsWith('https://example.com/wp-admin/?', $data['redirect']);
	}

	/**
	 * Network admin falls back too, and on its OWN capability.
	 *
	 * wp-admin/users.php requires list_users; wp-admin/network/users.php requires
	 * manage_network_users. A multisite administrator who is not a super admin holds
	 * the first and not the second, so a single-capability map would send them from a
	 * working dashboard to a 403 — the exact outcome the check exists to prevent.
	 *
	 * The other network test stubs current_user_can() true and so is structurally
	 * blind here; this is the case that distinguishes the two capabilities.
	 */
	public function test_handler_landing_network_falls_back_without_the_network_capability(): void
	{
		$this->stash->shouldReceive('get')
			->once()
			->with('gated-network-no-cap', 42)
			->andReturn(array(
				'method' => 'POST',
				'url' => 'https://example.com/wp-admin/network/user-edit.php',
				'return_url' => '',
				'rule_id' => 'network.super_admin',
				'post' => array(),
				'post_replay_blocked' => true,
			));

		$this->stash->shouldReceive('delete')->once()->with('gated-network-no-cap', 42);
		$this->stubReplayEnv();

		// Holds the site capability, lacks the network one.
		Functions\when('current_user_can')->alias(
			static fn( string $cap ): bool => 'list_users' === $cap
		);

		$data = $this->invokeReplay('gated-network-no-cap', false);

		$this->assertStringNotContainsString('users.php', $data['redirect']);
	}

	/**
	 * Guard: every mapped landing target must be a screen the suite has verified.
	 *
	 * The map's whole safety argument is that it points at screens already asserted
	 * usable on a bare GET — unlike a query allowlist, which asserts something new
	 * about core at every release. That argument is only true if it is enforced, so
	 * enforce it: walk the map in BOTH site and network form and require each target
	 * to appear in the verified-screen set.
	 *
	 * Written because the review that caught the missing network capability observed
	 * that this test would have caught it unaided — network/users.php was absent from
	 * verifiedScreens(), which is the same gap that made the wrong capability
	 * invisible. Adding an entry to the map now fails here until someone has decided
	 * what that screen does on a bare GET, in both contexts.
	 *
	 * It also asserts the entry's SHAPE, so handler_landing() cannot hit an undefined
	 * key in the network branch. The @var docblock is not protection: it drifted
	 * inside the very change that added network_cap, and Psalm caught that only
	 * because the literal and the annotation disagreed — an entry omitting the key
	 * entirely would have matched neither complaint.
	 *
	 * What it does NOT establish is which capability each target requires. That is
	 * recorded in prose beside the verifiedScreens() entry and in GB-USERS-LIST-CAP,
	 * and is not machine-checked here — verifiedScreens() holds URLs, not capabilities.
	 *
	 * Note the coupling: verifiedScreens() doubles as the absolution list for
	 * test_every_builtin_post_rule_lands_somewhere_usable(), so adding a map entry
	 * injects URLs into that guard's allowlist too. It errs safe — both guards force a
	 * decision rather than skipping one — but the two are wired together.
	 */
	public function test_every_handler_landing_target_is_a_verified_screen(): void
	{
		$verified = $this->verifiedScreens();

		foreach (\WP_Sudo\Challenge::HANDLER_LANDINGS as $handler => $landing) {
			foreach (array('file', 'cap', 'network_cap') as $key) {
				$this->assertArrayHasKey(
					$key,
					$landing,
					sprintf('HANDLER_LANDINGS[%s] is missing "%s"; handler_landing() reads all three.', $handler, $key)
				);
				$this->assertNotSame('', $landing[$key], sprintf('HANDLER_LANDINGS[%s]["%s"] must not be empty.', $handler, $key));
			}

			foreach (array('', 'network/') as $prefix) {
				$target = 'https://example.com/wp-admin/' . $prefix . $landing['file'];

				$this->assertContains(
					$target,
					$verified,
					sprintf(
						'HANDLER_LANDINGS maps %s to %s, but %s is not in the verified-screen '
							. 'set — so nothing has established it renders usably on a bare GET.',
						$handler,
						$landing['file'],
						$target
					)
				);
			}
		}
	}

	/**
	 * A handler with no mapped sibling still lands on the neutral page.
	 *
	 * options.php has no usable sibling — options-general.php is one of several
	 * Settings screens and guessing which one the operator wanted would be a worse
	 * answer than the dashboard. The map is deliberately partial.
	 */
	public function test_handler_landing_leaves_unmapped_handlers_on_the_neutral_page(): void
	{
		$this->stash->shouldReceive('get')
			->once()
			->with('gated-options', 42)
			->andReturn(array(
				'method' => 'POST',
				'url' => 'https://example.com/wp-admin/options.php',
				'return_url' => '',
				'rule_id' => 'options.critical',
				'post' => array(),
				'post_replay_blocked' => true,
			));

		$this->stash->shouldReceive('delete')->once()->with('gated-options', 42);
		$this->stubReplayEnv();
		Functions\when('current_user_can')->justReturn(true);

		$data = $this->invokeReplay('gated-options', false);

		$this->assertStringNotContainsString('options.php', $data['redirect']);
		$this->assertStringNotContainsString('options-general.php', $data['redirect']);
	}

	/**
	 * #429 guard: every built-in POST-capable rule must land somewhere usable.
	 *
	 * The handler list fails OPEN — an admin page nobody enumerated is treated as a
	 * good landing — and three review rounds each found another page missing from it.
	 * This closes that by construction rather than by vigilance: Action_Registry's
	 * pagenow set is finite, so walk it and require every value to have been decided
	 * once, either as a verified screen or as a handler.
	 *
	 * Adding a rule on a new admin page now fails here until someone checks what that
	 * page does on a bare GET. The screens below were each read in
	 * wordpress-develop trunk and render real output with no required parameter.
	 */
	public function test_every_builtin_post_rule_lands_somewhere_usable(): void
	{
		// Full URLs whose bare GET was read in wordpress-develop trunk and renders a
		// usable screen. Only pages the walk can actually reach are listed: a rule with
		// method GET is skipped, so its pagenow does not belong here — listing one would
		// imply coverage this test does not have.
		//
		// Keyed by URL, not filename, and that is load-bearing. edit.php is the Posts
		// list in site admin (a real screen) and a bare handler under network admin. An
		// earlier cut of this test listed bare filenames, so `edit.php` absolved BOTH —
		// meaning the test stayed green even when the network edit.php clause was
		// removed from production, re-hiding the exact defect it was written to catch.
		$verified_screens = $this->verifiedScreens();

		$single = $this->postCapablePagenow();

		// Action_Registry::rules() merges network_rules() only when is_multisite() is
		// true, and tests/TestCase.php stubs that false for the whole suite — so a walk
		// that does not lift the stub silently covers the single-site half only, and a
		// network rule added later on a blank-page handler would slip straight through.
		// That is the failure this guard exists to prevent, so it must not contain it.
		\WP_Sudo\Action_Registry::reset_cache();
		Functions\when('is_multisite')->justReturn(true);
		$multi = $this->postCapablePagenow();
		Functions\when('is_multisite')->justReturn(false);
		\WP_Sudo\Action_Registry::reset_cache();

		$network_only = array_values(array_diff($multi, $single));

		$this->assertNotEmpty(
			$single,
			'The single-site walk found no POST-capable rules — it is testing nothing.'
		);
		// A positive assertion rather than a count floor: the floor was satisfied by the
		// single-site half alone, so it could not detect the network half going missing.
		$this->assertContains(
			'settings.php',
			$network_only,
			'The network rules were not walked, so the multisite half of the registry is '
				. 'outside this guard.'
		);

		$undecided = array();

		foreach (array($single, $network_only) as $index => $set) {
			// Resolve to the URL the landing logic would actually see. Network rules
			// live under /wp-admin/network/, which is what makes network edit.php a
			// handler and site edit.php a screen.
			$base = 0 === $index
				? 'https://example.com/wp-admin/'
				: 'https://example.com/wp-admin/network/';

			foreach ($set as $pagenow) {
				$url = $base . $pagenow;

				// The production predicate, on a real URL — not a name comparison.
				if ($this->isHandlerEndpoint($url)) {
					continue;
				}

				if (in_array($url, $verified_screens, true)) {
					continue;
				}

				$undecided[$url] = $url;
			}
		}

		$this->assertSame(
			array(),
			array_values($undecided),
			'These admin URLs are reachable as a refused POST landing but have not been '
				. 'checked. Read each one in wordpress-develop: if a bare GET renders a '
				. 'usable screen, add it to $verified_screens; if it renders a blank page, '
				. 'a wp_die(), a raw dump, or a redirect that drops the query, add it to '
				. 'Challenge::HANDLER_ENDPOINTS with a registry row for the evidence.'
		);
	}

	/**
	 * #434: a URL cannot be both a handler and a verified usable screen.
	 *
	 * The existing walk checks the handler predicate first and continues, so an
	 * overlapping verified-screen entry is never consulted. Assert the invariant
	 * separately through the production predicate, including its network context.
	 */
	public function test_handler_endpoints_and_verified_screens_are_disjoint(): void {
		$overlap = array();

		foreach ( $this->verifiedScreens() as $url ) {
			if ( $this->isHandlerEndpoint( $url ) ) {
				$overlap[] = $url;
			}
		}

		$this->assertSame(
			array(),
			$overlap,
			'These URLs are both handlers and verified screens. Decide whether a bare '
				. 'GET renders a usable screen, then remove the contradictory entry.'
		);
	}

	/**
	 * Admin URLs whose bare GET renders a usable screen.
	 *
	 * URLs, rather than filenames, preserve the site/network distinction for
	 * edit.php. Shared by the landing walk and the #434 disjointness guard so the
	 * two checks cannot silently diverge.
	 *
	 * @return string[]
	 */
	private function verifiedScreens(): array {
		return array(
			'https://example.com/wp-admin/authorize-application.php',
			'https://example.com/wp-admin/edit.php',
			'https://example.com/wp-admin/options-general.php',
			'https://example.com/wp-admin/plugin-editor.php',
			'https://example.com/wp-admin/plugins.php',
			'https://example.com/wp-admin/profile.php',
			'https://example.com/wp-admin/theme-editor.php',
			'https://example.com/wp-admin/themes.php',
			'https://example.com/wp-admin/update-core.php',
			'https://example.com/wp-admin/user-new.php',
			'https://example.com/wp-admin/users.php',
			// Network Settings renders on a bare GET; network edit.php does not and is
			// caught by the handler predicate, so it is deliberately absent here.
			'https://example.com/wp-admin/network/settings.php',
			// Network Users renders the list on a bare GET: the whole action-handling
			// block is behind `isset( $_GET['action'] )`, so with no query it falls
			// through to the list table. Verified in core, network/users.php. Note it
			// gates on manage_network_users, NOT list_users like its site counterpart —
			// which is why HANDLER_LANDINGS carries a separate network_cap, and why the
			// landing must choose the capability from the same path as the destination.
			'https://example.com/wp-admin/network/users.php',
		);
	}

	/**
	 * Every pagenow reachable by a POST-capable built-in admin rule.
	 *
	 * @return string[]
	 */
	private function postCapablePagenow(): array
	{
		$found = array();

		foreach (\WP_Sudo\Action_Registry::rules() as $rule) {
			// pagenow/method live under the rule's 'admin' surface, not at the top
			// level. Reading them from the top level is why the first cut of this test
			// iterated nothing and passed while user-edit.php was still missing.
			$admin = $rule['admin'] ?? null;

			if (!is_array($admin)) {
				continue;
			}

			// Only POST-capable rules can strand a user on a form handler; a GET rule
			// with no query already takes the neutral fallback.
			if ('GET' === strtoupper((string) ($admin['method'] ?? 'ANY'))) {
				continue;
			}

			foreach ((array) ($admin['pagenow'] ?? array()) as $pagenow) {
				if ('' !== (string) $pagenow) {
					$found[(string) $pagenow] = (string) $pagenow;
				}
			}
		}

		return array_values($found);
	}

	/**
	 * Invoke the production handler predicate directly.
	 */
	private function isHandlerEndpoint(string $url): bool
	{
		$method = new \ReflectionMethod($this->challenge, 'is_handler_endpoint');
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible(true);
		}

		return (bool) $method->invoke($this->challenge, $url);
	}

	/**
	 * #429: a handler is refused as a landing spot even when the URL carried a query.
	 *
	 * The handler check must not live only in the queryless branch. A gated
	 * admin-post.php?action=… strips down to bare admin-post.php, which fires
	 * do_action( 'admin_post' ) and falls off the end — a blank page that never runs
	 * admin_notices, so the user would lose the explanatory notice as well as the
	 * form. See GB-ADMIN-POST-BLANK in docs/upstream-sources.md.
	 */
	public function test_refused_landing_never_lands_on_a_handler_with_a_query(): void
	{
		$this->stash->shouldReceive('get')
			->once()
			->with('gated-adminpost', 42)
			->andReturn(array(
				'method' => 'POST',
				'url' => 'https://example.com/wp-admin/admin-post.php?action=wp_sudo_danger&_wpnonce=abc',
				'return_url' => '',
				'rule_id' => 'custom.rule',
				'post' => array('confirm' => '1'),
				'post_replay_blocked' => true,
			));

		$this->stash->shouldReceive('delete')->once()->with('gated-adminpost', 42);
		$this->stubReplayEnv();

		$data = $this->invokeReplay('gated-adminpost', false);

		$this->assertStringNotContainsString(
			'admin-post.php',
			$data['redirect'],
			'admin-post.php renders a blank page and no notices — never land there.'
		);
		$this->assertStringContainsString('wp_sudo_blocked_replay=1', $data['redirect']);
	}

	/**
	 * #429: admin.php is a handler — the case that matters most for custom rules.
	 *
	 * `admin.php?page=…` is where third-party settings screens live, and is the
	 * pagenow example in docs/developer-reference.md. Bare admin.php never requires
	 * admin-header.php, so it renders nothing and fires no admin_notices — see
	 * GB-ADMIN-PHP-BLANK in docs/upstream-sources.md. Stripping a gated
	 * admin.php?page=x&action=y down to admin.php would land the user on a blank page
	 * having ALSO lost the notice, which is worse than the dashboard they got before.
	 */
	public function test_refused_post_to_admin_php_lands_on_the_neutral_page(): void
	{
		$this->stash->shouldReceive('get')
			->once()
			->with('gated-adminphp', 42)
			->andReturn(array(
				'method' => 'POST',
				'url' => 'https://example.com/wp-admin/admin.php?page=acme-settings&action=save',
				'return_url' => '',
				'rule_id' => 'custom.acme',
				'post' => array('acme_key' => 'x'),
				'post_replay_blocked' => true,
			));

		$this->stash->shouldReceive('delete')->once()->with('gated-adminphp', 42);
		$this->stubReplayEnv();

		$data = $this->invokeReplay('gated-adminphp', false);

		$this->assertStringNotContainsString(
			'admin.php',
			$data['redirect'],
			'Bare admin.php renders nothing and fires no notices — never land there.'
		);
		$this->assertStringContainsString('wp_sudo_blocked_replay=1', $data['redirect']);
	}

	/**
	 * #429: network/edit.php is a handler, decided from the URL rather than context.
	 *
	 * The built-in options.wp_sudo rule POSTs to edit.php?action={slug} under network
	 * admin, and bare network/edit.php redirects to the network dashboard, dropping
	 * the notice arg with it (GB-NETWORK-EDIT-REDIRECT).
	 *
	 * This test deliberately does not touch is_network_admin(): tests/TestCase.php
	 * stubs it false for the whole unit suite. An earlier cut of this fix gated the
	 * clause on it — an ambient value the classified URL does not carry, read from
	 * global state that earlier-running code can set (GB-IS-NETWORK-ADMIN) — which
	 * left the guard unfalsifiable in test and its production behaviour dependent
	 * on request context rather than on the URL. The classification must therefore
	 * come from the path itself.
	 */
	public function test_network_edit_php_is_a_handler_without_consulting_context(): void
	{
		$this->stash->shouldReceive('get')
			->once()
			->with('gated-network', 42)
			->andReturn(array(
				'method' => 'POST',
				'url' => 'https://example.com/wp-admin/network/edit.php?action=wp_sudo_settings',
				'return_url' => '',
				'rule_id' => 'options.wp_sudo',
				'post' => array('session_duration' => '14'),
				'post_replay_blocked' => true,
			));

		$this->stash->shouldReceive('delete')->once()->with('gated-network', 42);
		$this->stubReplayEnv();

		$data = $this->invokeReplay('gated-network', false);

		$this->assertStringNotContainsString(
			'edit.php',
			$data['redirect'],
			'network/edit.php redirects away and drops the notice — never land there.'
		);
		$this->assertStringContainsString('wp_sudo_blocked_replay=1', $data['redirect']);
	}

	/**
	 * #429: site-admin edit.php is a real screen and must stay one.
	 *
	 * The network carve-out keys on the /network/ path segment, so the Posts list must
	 * not be swept up by it — and a site that merely happens to live under a path
	 * containing "network" must not be either.
	 */
	public function test_site_admin_edit_php_is_not_treated_as_a_handler(): void
	{
		$this->stash->shouldReceive('get')
			->once()
			->with('gated-posts', 42)
			->andReturn(array(
				'method' => 'POST',
				'url' => 'https://example.com/network/wp-admin/edit.php?post_type=page&action=bulk',
				'return_url' => '',
				'rule_id' => 'custom.posts',
				'post' => array('confirm' => '1'),
				'post_replay_blocked' => true,
			));

		$this->stash->shouldReceive('delete')->once()->with('gated-posts', 42);
		$this->stubReplayEnv();

		$data = $this->invokeReplay('gated-posts', false);

		$this->assertStringContainsString(
			'edit.php',
			$data['redirect'],
			'Only /network/edit.php is a handler; a site under a "network" path is not.'
		);
		$this->assertStringNotContainsString('action=bulk', $data['redirect']);
	}

	// -----------------------------------------------------------------
	// #322 v2 — origin-bound replay. Auto-replay is allowed ONLY when a
	// credential was verified on THIS request AND the browser presents the
	// binding proof minted when the stash was created. Everything else keeps
	// the v1 fail-closed landing.
	// -----------------------------------------------------------------

	/**
	 * Build a clean, replayable POST stash bound to $secret.
	 *
	 * @param string $secret Binding secret whose hash the stash stores.
	 * @return array<string, mixed>
	 */
	private function boundPostStash(string $secret): array
	{
		return array(
			'method' => 'POST',
			'url' => 'https://example.com/wp-admin/users.php',
			'return_url' => 'https://example.com/wp-admin/user-new.php',
			'rule_id' => 'user.create',
			'post' => array('role' => 'administrator'),
			'binding_hash' => hash('sha256', $secret),
			// The confirmation described the whole effect — required for bound replay.
			'target' => array('role' => 'administrator'),
			'target_complete' => true,
		);
	}

	/**
	 * Stub the functions the replay chokepoint needs.
	 */
	private function stubReplayEnv(): void
	{
		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/');
		Functions\when('wp_validate_redirect')->returnArg();
		Functions\when('sanitize_text_field')->returnArg();
		// handler_landing() consults this for every handler landing (#533). Tests that
		// care about the capability branch override it after calling this helper.
		Functions\when('current_user_can')->justReturn(true);
		Functions\when('headers_sent')->justReturn(true); // skip setcookie in unit context
		Functions\when('add_query_arg')->alias(
			static function ( string $key, string $value, string $url ): string {
				$separator = str_contains($url, '?') ? '&' : '?';
				return $url . $separator . $key . '=' . $value;
			}
		);
	}

	/**
	 * Invoke the replay chokepoint directly.
	 *
	 * @param bool $credentialVerified Whether a credential was verified this request.
	 * @return array<string, mixed>
	 */
	private function invokeReplay(string $stashKey, bool $credentialVerified): array
	{
		$method = new \ReflectionMethod($this->challenge, 'build_replay_response_data');
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible(true);
		}
		return $method->invoke($this->challenge, 42, $stashKey, 'https://example.com/wp-admin/', $credentialVerified);
	}

	/**
	 * Invoke the target-description helper directly.
	 *
	 * @param array<string, mixed>|null $stash Stash data.
	 */
	private function describeTarget(?array $stash): string
	{
		$method = new \ReflectionMethod($this->challenge, 'describe_stash_target');
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible(true);
		}
		return (string) $method->invoke($this->challenge, $stash);
	}

	/**
	 * #322 v2: the Target line actually names the action (the PRIMARY control).
	 *
	 * Without this, describe_stash_target() could be reduced to `return ''` and the
	 * whole informed-confirmation control would vanish with a green suite.
	 */
	public function test_describe_stash_target_names_the_concrete_action(): void
	{
		Functions\when('current_user_can')->justReturn(false);

		$out = $this->describeTarget(array('target' => array('plugin' => 'evil/evil.php')));

		$this->assertStringContainsString('evil/evil.php', $out, 'The Target line must name what is being authorized.');
		$this->assertStringContainsString('plugin', $out);
	}

	/**
	 * #322 v2: empty/malformed targets degrade quietly, not fatally.
	 */
	public function test_describe_stash_target_handles_missing_target(): void
	{
		Functions\when('current_user_can')->justReturn(false);

		$this->assertSame('', $this->describeTarget(null));
		$this->assertSame('', $this->describeTarget(array()));
		$this->assertSame('', $this->describeTarget(array('target' => 'not-an-array')));
		$this->assertSame('', $this->describeTarget(array('target' => array('k' => array('nested')))));
	}

	/**
	 * #322 N1: user_id resolves to a login ONLY for viewers entitled to see it.
	 *
	 * The Gate is role-agnostic and the challenge page renders at 'read', so an
	 * unprivileged user could otherwise walk users.php?action=promote&user_id=N and
	 * enumerate every account's login name.
	 */
	public function test_describe_stash_target_does_not_leak_user_login_without_capability(): void
	{
		Functions\when('current_user_can')->justReturn(false);
		Functions\expect('get_userdata')->never();

		$out = $this->describeTarget(array('target' => array('user_id' => '1')));

		$this->assertStringNotContainsString('admin', $out, 'user_login must not leak to an unentitled viewer.');
		$this->assertStringContainsString('1', $out, 'The bare id is still shown.');
	}

	/**
	 * #322 F3: an entitled viewer DOES get the readable login.
	 */
	public function test_describe_stash_target_resolves_user_login_with_capability(): void
	{
		Functions\when('current_user_can')->justReturn(true);

		$user = new \WP_User(1);
		$user->user_login = 'admin';
		Functions\expect('get_userdata')->once()->with(1)->andReturn($user);

		$out = $this->describeTarget(array('target' => array('user_id' => '1')));

		$this->assertStringContainsString('admin', $out, 'An entitled viewer must see WHO is being changed.');
		$this->assertStringContainsString('#1', $out);
	}

	/**
	 * #322: a QUERYLESS stashed GET must land on the neutral page, not the stash URL.
	 *
	 * With no query to strip, "the originating screen" IS the action URL — so an
	 * extensibility rule gating an effect that fires on path load alone would still be
	 * carried out by the victim's reauth. Only a URL we actually stripped is safe.
	 */
	public function test_queryless_get_stash_lands_neutral_not_on_the_action_url(): void
	{
		$this->stash->shouldReceive('get')->once()->andReturn(array(
			'method' => 'GET',
			'url' => 'https://example.com/wp-admin/admin.php/destructive-path-action',
			'rule_id' => 'thirdparty.path_action',
		));
		$this->stash->shouldReceive('delete')->once();
		$this->stubReplayEnv();

		$data = $this->invokeReplay('queryless-key', true);

		$this->assertArrayNotHasKey('replay', $data);
		$this->assertStringNotContainsString(
			'destructive-path-action',
			$data['redirect'],
			'A queryless GET stash must not be used as the landing URL.'
		);
		$this->assertStringContainsString('wp_sudo_blocked_replay=1', $data['redirect']);
	}

	/**
	 * #322: a refused replay must be AUDITABLE.
	 *
	 * The fail-closed path fired no hook at all, so the case this mechanism exists to
	 * stop — a reauthentication completed in a browser that did not start the action —
	 * was indistinguishable from nothing happening. Bridges (WSAL/Stream) saw silence.
	 */
	public function test_refused_replay_fires_audit_hook_with_reason(): void
	{
		unset($_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE]);

		$this->stash->shouldReceive('get')->once()->andReturn($this->boundPostStash('attacker-secret'));
		$this->stash->shouldReceive('delete')->once();
		$this->stubReplayEnv();

		$captured = null;
		Actions\expectDone('wp_sudo_replay_refused')
			->once()
			->whenHappen(function ($user_id, $rule_id, $reason) use (&$captured) {
				$captured = array($user_id, $rule_id, $reason);
			});

		$data = $this->invokeReplay('planted-key', true);

		$this->assertArrayNotHasKey('replay', $data);
		$this->assertSame(42, $captured[0]);
		$this->assertSame(
			'replay_disabled',
			$captured[2],
			'The audit event must say WHY the action was not resumed. With a credential '
				. 'verified on this request, that reason is the invariant itself.'
		);
	}

	/**
	 * #322: the release invariant. Nothing is ever auto-executed after reauth.
	 *
	 * Replaces two tests that asserted the opposite — that a matching binding cookie
	 * plus a verified credential replays the stashed action. That was the contract
	 * this release removes, so the tests asserting it are removed with it rather than
	 * adjusted; leaving them weakened would leave the old guarantee half-asserted.
	 *
	 * @dataProvider provideInvariantCases
	 *
	 * @param array<string, mixed> $stash    Stash as Request_Stash would have stored it.
	 * @param bool                 $verified Whether a credential was verified this request.
	 * @param string               $label    Case description, for the failure message.
	 */
	public function test_no_stash_is_ever_auto_executed(array $stash, bool $verified, string $label): void
	{
		$secret = 'super-secret-proof';
		// Present the binding cookie in EVERY case. Under the old mechanism this was
		// the thing that authorised a replay, so if any case still replays, this is
		// what would let it — the invariant must hold with the proof satisfied.
		$_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE] = $secret;

		$this->stash->shouldReceive('get')->once()->andReturn($stash);
		$this->stash->shouldReceive('delete')->once();
		$this->stubReplayEnv();

		$data = $this->invokeReplay('any-key', $verified);

		$this->assertArrayNotHasKey('replay', $data, $label . ': must not auto-submit.');
		$this->assertArrayNotHasKey('post_data', $data, $label . ': must not carry a body.');
		$this->assertArrayNotHasKey('replaying', $data, $label . ': must not signal execution.');
		$this->assertNotSame(
			$stash['url'] ?? null,
			$data['redirect'] ?? null,
			$label . ': must not redirect to the action URL, which would execute a GET.'
		);

		unset($_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE]);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: bool, 2: string}>
	 */
	public function provideInvariantCases(): array
	{
		$secret = 'super-secret-proof';
		$bound  = function (array $over) use ($secret): array {
			return array_merge($this->boundPostStash($secret), $over);
		};

		return array(
			'GET action' => array(
				$bound(array(
					'method' => 'GET',
					'url' => 'https://example.com/wp-admin/plugins.php?action=activate&plugin=evil%2Fevil.php&_wpnonce=abc',
					'post' => array(),
					'rule_id' => 'plugin.activate',
				)),
				true,
				'GET',
			),
			'POST action' => array($bound(array()), true, 'POST'),
			'bodyless POST' => array(
				$bound(array('post' => array(), 'target' => array('plugin' => 'acme/acme.php'))),
				true,
				'bodyless POST',
			),
			'already-active session, no credential this request' => array(
				$bound(array()),
				false,
				'active-session resume',
			),
			'Application Password authorization' => array(
				$bound(array(
					'method' => 'POST',
					'url' => 'https://example.com/wp-admin/authorize-application.php',
					'rule_id' => 'app_password.create',
					'post' => array('app_name' => 'acme', 'success_url' => 'https://evil.example/collect'),
					'target' => array('app_name' => 'acme'),
				)),
				true,
				'App Password authorization',
			),
		);
	}

	/**
	 * #322 v2: the attacker's planted stash — victim's browser has no proof.
	 */
	public function test_planted_stash_without_binding_cookie_fails_closed(): void
	{
		unset($_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE]);

		$this->stash->shouldReceive('get')->once()->andReturn($this->boundPostStash('attacker-secret'));
		$this->stash->shouldReceive('delete')->once();
		$this->stubReplayEnv();

		$data = $this->invokeReplay('planted-key', true);

		$this->assertArrayNotHasKey('replay', $data, 'No proof in this browser → must not replay.');
		$this->assertArrayNotHasKey('post_data', $data);
	}

	/**
	 * #322 v2: cookie transplant — a proof that does not match this stash is refused.
	 */
	public function test_transplanted_binding_cookie_is_refused(): void
	{
		$_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE] = 'a-different-stashs-proof';

		$this->stash->shouldReceive('get')->once()->andReturn($this->boundPostStash('the-real-proof'));
		$this->stash->shouldReceive('delete')->once();
		$this->stubReplayEnv();

		$data = $this->invokeReplay('transplant-key', true);

		$this->assertArrayNotHasKey('replay', $data, 'Mismatched proof must not replay.');

		unset($_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE]);
	}

	/**
	 * #322 v2: the no-password resume paths stay fail-closed even with a valid proof.
	 */
	public function test_bound_stash_is_not_replayed_without_credential_verification(): void
	{
		$secret = 'super-secret-proof';
		$_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE] = $secret;

		$this->stash->shouldReceive('get')->once()->andReturn($this->boundPostStash($secret));
		$this->stash->shouldReceive('delete')->once();
		$this->stubReplayEnv();

		$data = $this->invokeReplay('resume-key', false);

		$this->assertArrayNotHasKey('replay', $data, 'Resume path (no credential this request) must not replay.');

		unset($_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE]);
	}

	/**
	 * #322 v2 CALLER CONTRACT: the already-active-session AJAX path must not
	 * request a bound replay, even with a perfectly valid proof cookie.
	 *
	 * `may_replay_bound_stash()` enforces this at the callee, but that alone does not
	 * stop a future edit from passing `true` from `complete_active_session_request()`.
	 * This drives the real entry point (handle_ajax_auth with an active session) so
	 * flipping that caller is caught.
	 */
	public function test_active_session_ajax_path_never_bound_replays(): void
	{
		$secret = 'super-secret-proof';
		$_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE] = $secret;

		Functions\when('__')->returnArg();
		$this->stubReplayEnv();

		$this->stash->shouldReceive('exists')->once()->with('active-key', 42)->andReturn(true);
		$this->stash->shouldReceive('get')->once()->andReturn($this->boundPostStash($secret));
		$this->stash->shouldReceive('delete')->once();

		$captured = null;
		Functions\when('wp_send_json_success')->alias(
			function ($data) use (&$captured) {
				$captured = $data;
			}
		);

		// Drive the caller itself — this is what a regression would flip.
		$method = new \ReflectionMethod($this->challenge, 'complete_active_session_request');
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible(true);
		}
		$method->invoke($this->challenge, 42, 'active-key');

		$this->assertIsArray($captured);
		$this->assertArrayNotHasKey(
			'replay',
			$captured,
			'The already-active (no credential this request) path must never bound-replay.'
		);
		$this->assertArrayNotHasKey('post_data', $captured);

		unset($_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE]);
	}

	/**
	 * #322: an EMPTY target must not be treated as a complete description.
	 *
	 * `capture_target()` initialises `$complete = true` and only ever sets it false on
	 * truncation, so a request carrying none of `TARGET_PARAMS` stores
	 * `target => [], target_complete => true`. `target_describes_payload()` then
	 * iterates zero keys and agrees. The completeness check is satisfied VACUOUSLY.
	 *
	 * That matters because `render_page()` guards the confirmation on
	 * `'' !== $action_target`, so an empty target renders NO `Target:` line at all —
	 * the user confirms a coarse label ("Export site data") and nothing else. Replaying
	 * there means the informed-confirmation control, which is the control that holds
	 * when the browser binding is bypassed, was never shown.
	 *
	 * Ships live on `tools.export`: a GET rule on `export.php` gated when
	 * `$_GET['download']` is set, whose parameters appear in none of `TARGET_PARAMS`.
	 */
	public function test_bound_stash_with_empty_target_is_not_replayed(): void
	{
		$secret = 'super-secret-proof';
		$_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE] = $secret;

		$stash = $this->boundPostStash($secret);
		// Exactly what capture_target() stores for a request naming no known target.
		$stash['target'] = array();
		$stash['target_complete'] = true;

		$this->stash->shouldReceive('get')->once()->andReturn($stash);
		$this->stash->shouldReceive('delete')->once();
		$this->stubReplayEnv();

		$data = $this->invokeReplay('empty-target-key', true);

		$this->assertArrayNotHasKey(
			'replay',
			$data,
			'A stash whose confirmation named nothing must fall back to the v1 landing'
		);
		$this->assertArrayNotHasKey('post_data', $data);
		$this->assertTrue($data['post_replay_blocked']);

		unset($_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE]);
	}

	/**
	 * #322: a TRUTHY NON-ARRAY target is refused.
	 *
	 * This is the only input that reaches the `! is_array()` half of the guard — an
	 * empty array and a missing key both trip `empty()` first. Without this test that
	 * half is unverified: deleting `! is_array( $stash['target'] )` leaves the entire
	 * suite green, so a refactor could drop it silently.
	 *
	 * What it defends is a corrupted or hand-forged stash whose `target` is a scalar.
	 * The stash lives in a transient, and the threat model this whole feature is
	 * written against (#278) includes an attacker who can write into the object cache —
	 * so a non-array `target` is a shape the enforcement path can actually be handed,
	 * not merely a type-system nicety. `foreach` over a scalar would emit a warning and
	 * describe nothing, which is the vacuous-confirmation failure again by another
	 * route.
	 */
	public function test_bound_stash_with_non_array_target_is_not_replayed(): void
	{
		$secret = 'super-secret-proof';
		$_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE] = $secret;

		$stash = $this->boundPostStash($secret);
		$stash['target'] = 'plugins.php?plugin=evil.php'; // truthy, but not an array
		$stash['target_complete'] = true;

		$this->stash->shouldReceive('get')->once()->andReturn($stash);
		$this->stash->shouldReceive('delete')->once();
		$this->stubReplayEnv();

		$data = $this->invokeReplay('scalar-target-key', true);

		$this->assertArrayNotHasKey(
			'replay',
			$data,
			'A stash whose target is not an array must fall back to the v1 landing'
		);
		$this->assertArrayNotHasKey('post_data', $data);
		$this->assertTrue($data['post_replay_blocked']);

		unset($_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE]);
	}

	/**
	 * #322: a target that renders NOTHING is refused, even though it is a
	 * non-empty array.
	 *
	 * `describe_stash_target()` skips any entry whose value is not a non-empty
	 * scalar, so `['plugin' => []]` produces no `Target:` line at all — the exact
	 * vacuous-confirmation state this guard exists to prevent — while satisfying a
	 * naive `! empty()` check.
	 *
	 * Guarded by asking the renderer rather than re-deriving its rule, so the
	 * eligibility check and the thing the user actually sees cannot drift apart.
	 */
	public function test_bound_stash_whose_target_renders_nothing_is_not_replayed(): void
	{
		$secret = 'super-secret-proof';
		$_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE] = $secret;

		$stash = $this->boundPostStash($secret);
		// Non-empty array, but no entry describe_stash_target() will render.
		$stash['target'] = array('plugin' => array());
		$stash['target_complete'] = true;

		$this->stash->shouldReceive('get')->once()->andReturn($stash);
		$this->stash->shouldReceive('delete')->once();
		$this->stubReplayEnv();

		$data = $this->invokeReplay('unrenderable-target-key', true);

		$this->assertArrayNotHasKey(
			'replay',
			$data,
			'A target that renders no confirmation must fall back to the v1 landing'
		);
		$this->assertTrue($data['post_replay_blocked']);

		unset($_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE]);
	}

	/**
	 * #322: a missing `target` key is the same refusal as an empty one.
	 *
	 * Guards the shape rather than one value — a stash written by an older build, or
	 * by a future code path that forgets to record a target, must not inherit replay.
	 */
	public function test_bound_stash_with_missing_target_key_is_not_replayed(): void
	{
		$secret = 'super-secret-proof';
		$_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE] = $secret;

		$stash = $this->boundPostStash($secret);
		unset($stash['target']);
		$stash['target_complete'] = true;

		$this->stash->shouldReceive('get')->once()->andReturn($stash);
		$this->stash->shouldReceive('delete')->once();
		$this->stubReplayEnv();

		$data = $this->invokeReplay('no-target-key', true);

		$this->assertArrayNotHasKey('replay', $data);
		$this->assertTrue($data['post_replay_blocked']);

		unset($_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE]);
	}

	/**
	 * #322: never replay more than the confirmation described.
	 *
	 * `target_complete` is false when a displayed value was truncated (the first few
	 * accounts of a bulk delete shown, the rest hidden) or when the payload carries an
	 * effect field the target does not name. Consent to a partial description is not
	 * consent to the whole effect, so bound replay must be refused.
	 */
	public function test_bound_stash_with_incomplete_target_is_not_replayed(): void
	{
		$secret = 'super-secret-proof';
		$_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE] = $secret;

		$stash = $this->boundPostStash($secret);
		$stash['target'] = array('users' => '5, 6, 7');
		$stash['target_complete'] = false;

		$this->stash->shouldReceive('get')->once()->andReturn($stash);
		$this->stash->shouldReceive('delete')->once();
		$this->stubReplayEnv();

		$data = $this->invokeReplay('partial-key', true);

		$this->assertArrayNotHasKey(
			'replay',
			$data,
			'A partially described effect must not be auto-replayed.'
		);

		unset($_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE]);
	}

	/**
	 * #322: refuse a bound replay whose stashed URL is not HTTPS.
	 *
	 * A binding only mints when cookies are Secure, but that can be true via
	 * FORCE_SSL_ADMIN while PHP does not see the request as SSL (TLS terminated
	 * upstream) — in which case the stored action URL is http://. Replaying it would
	 * downgrade the scheme, dropping the POST body on redirect or omitting the Secure
	 * auth cookies.
	 */
	public function test_bound_stash_with_non_https_url_is_not_replayed(): void
	{
		$secret = 'super-secret-proof';
		$_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE] = $secret;

		$stash = $this->boundPostStash($secret);
		$stash['url'] = 'http://example.com/wp-admin/users.php';

		$this->stash->shouldReceive('get')->once()->andReturn($stash);
		$this->stash->shouldReceive('delete')->once();
		$this->stubReplayEnv();

		$data = $this->invokeReplay('downgrade-key', true);

		$this->assertArrayNotHasKey(
			'replay',
			$data,
			'A bound replay must not downgrade to an http:// action URL.'
		);

		unset($_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE]);
	}

	/**
	 * #322 v2: a redacted/blocked stash is never replayed, binding or not.
	 */
	public function test_bound_but_redacted_stash_is_not_replayed(): void
	{
		$secret = 'super-secret-proof';
		$_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE] = $secret;

		$stash = $this->boundPostStash($secret);
		$stash['redacted_fields_omitted'] = true;

		$this->stash->shouldReceive('get')->once()->andReturn($stash);
		$this->stash->shouldReceive('delete')->once();
		$this->stubReplayEnv();

		$data = $this->invokeReplay('redacted-key', true);

		$this->assertArrayNotHasKey('replay', $data, 'Redacted stash must never replay (secrets are missing).');
		// #469: the arg no longer varies with the redaction flag — see
		// test_redacted_secret_stash_redirects_instead_of_post_replay.
		$this->assertStringContainsString('wp_sudo_blocked_replay=1', $data['redirect']);

		unset($_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE]);
	}

	/**
	 * #322 v2: refuse rather than re-target when validation alters the stashed URL.
	 */
	public function test_bound_stash_with_unvalidatable_url_is_not_replayed(): void
	{
		$secret = 'super-secret-proof';
		$_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE] = $secret;

		$this->stash->shouldReceive('get')->once()->andReturn($this->boundPostStash($secret));
		$this->stash->shouldReceive('delete')->once();
		$this->stubReplayEnv();
		// Simulate wp_validate_redirect() rejecting/altering the stashed URL.
		Functions\when('wp_validate_redirect')->justReturn('https://example.com/wp-admin/');

		$data = $this->invokeReplay('crosshost-key', true);

		$this->assertArrayNotHasKey('replay', $data, 'Altered URL must fail closed, not re-target the POST.');

		unset($_COOKIE[\WP_Sudo\Request_Stash::BINDING_COOKIE]);
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

		// F18a: resend rate-limit counter (0 = not throttled).
		Functions\expect('get_transient')
			->once()
			->with('wp_sudo_resend_42')
			->andReturn(0);
		Functions\expect('set_transient')
			->once()
			->with('wp_sudo_resend_42', 1, 300)
			->andReturn(true);

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
			if (\WP_Sudo\Sudo_Session::PROOF_META_KEY === $key) {
				return $this->make_proof_map((int) $uid, 'browser-token', $expires);
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
			if (\WP_Sudo\Sudo_Session::PROOF_META_KEY === $key) {
				return $this->make_proof_map((int) $uid, 'browser-token', $expires);
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
		// #322: the Continue/Cancel targets are server-chosen, so the requester's
		// return_url must NOT appear anywhere in the rendered page. This user already
		// holds sudo authority, which is exactly when a requester-chosen destination
		// is most dangerous — one click would execute under it.
		$this->assertStringNotContainsString(
			'plugins.php',
			$output,
			'A requester-supplied return_url must not reach the resume page at all.'
		);
		$this->assertStringContainsString(
			'https://example.com/wp-admin/',
			$output,
			'The neutral dashboard is the only offered destination.'
		);
		$this->assertStringNotContainsString('wp-sudo-challenge-password-form', $output);

		unset($_GET['return_url'], $_COOKIE[\WP_Sudo\Sudo_Session::TOKEN_COOKIE]);
	}

	// -----------------------------------------------------------------
	// Post-challenge notice bodies (#463, #469, #436 face 2)
	//
	// Before this group, ZERO tests asserted either notice's text — only the
	// query arg in the redirect URL. That gap is why three separate wording
	// defects survived review: the copy is the one part of the refusal the user
	// actually reads, and nothing held it to the truth.
	// -----------------------------------------------------------------

	/**
	 * Stub the translation and sanitization helpers the notice renderers use.
	 */
	private function stub_notice_helpers(): void
	{
		Functions\when('esc_html__')->returnArg();
		Functions\when('sanitize_text_field')->returnArg();
		Functions\when('wp_unslash')->returnArg();
	}

	/**
	 * Capture a notice renderer's output.
	 */
	private function capture_notice(string $method): string
	{
		ob_start();
		$this->challenge->{$method}();

		return (string) ob_get_clean();
	}

	/**
	 * Test the notice does not tell a link-driven GET action to review a form.
	 *
	 * #463: since #322 every stash takes the refusal path, so plugin and theme
	 * activation — reached by clicking a link, carrying no form at all — lands on
	 * its list screen and is told to "review the form and submit it again". That
	 * is now the most common path, not an edge case.
	 */
	public function test_blocked_replay_notice_does_not_reference_a_form(): void
	{
		$this->stub_notice_helpers();
		$_GET[\WP_Sudo\Challenge::BLOCKED_REPLAY_QUERY_ARG] = '1';

		$output = $this->capture_notice('render_blocked_replay_notice');

		$this->assertNotSame('', $output, 'The blocked-replay arg must render a notice.');
		$this->assertStringNotContainsString(
			'Review the form',
			$output,
			'A link-driven GET action has no form to review (#463).'
		);

		unset($_GET[\WP_Sudo\Challenge::BLOCKED_REPLAY_QUERY_ARG]);
	}

	/**
	 * Test the notice does not open by claiming a reauthentication just happened.
	 *
	 * "Reauthentication complete" is true only on the replay_stash() path where a
	 * credential was verified. complete_active_session_request() and
	 * render_resume_page() both reach the same notice with $credential_verified
	 * false — the user already held a session and presented nothing on that
	 * request. The opener is false on two of the three paths.
	 */
	public function test_blocked_replay_notice_does_not_claim_reauthentication_occurred(): void
	{
		$this->stub_notice_helpers();
		$_GET[\WP_Sudo\Challenge::BLOCKED_REPLAY_QUERY_ARG] = '1';

		$output = $this->capture_notice('render_blocked_replay_notice');

		$this->assertStringNotContainsString(
			'Reauthentication complete',
			$output,
			'False on complete_active_session_request() and render_resume_page().'
		);

		unset($_GET[\WP_Sudo\Challenge::BLOCKED_REPLAY_QUERY_ARG]);
	}

	/**
	 * Test the notice states that nothing was carried out.
	 *
	 * The load-bearing fact for both #463 and #469: the whole request was
	 * discarded. Any wording that leaves the user believing part of it landed is
	 * the defect, whichever arg brought them here.
	 */
	public function test_blocked_replay_notice_states_nothing_was_changed(): void
	{
		$this->stub_notice_helpers();
		$_GET[\WP_Sudo\Challenge::BLOCKED_REPLAY_QUERY_ARG] = '1';

		$output = $this->capture_notice('render_blocked_replay_notice');

		$this->assertStringContainsString('nothing was changed', $output);
		$this->assertStringContainsString('do it again', strtolower($output));

		unset($_GET[\WP_Sudo\Challenge::BLOCKED_REPLAY_QUERY_ARG]);
	}

	/**
	 * Test the redacted arg renders the same body as the blocked arg.
	 *
	 * #469 / #436 face 2: the redacted/blocked axis is unsound as a description
	 * of secrets. `redacted_fields_omitted` is set only inside
	 * Request_Stash::sanitize_params(), which build_stashed_post_params() returns
	 * before whenever post_mode is `none` — so among builtin rules only
	 * user.create can raise it, while every credential-carrying rule
	 * (user.change_password, user.change_email, user.promote_profile) uses
	 * stash_no_replay() and gets the OTHER notice. A POST that definitely carried
	 * a password produced the message that did not mention passwords, and naming
	 * which fields "were not replayed" implied the rest were.
	 *
	 * One string, true on every path, is the fix. The arg is kept only so a URL
	 * already in flight across an upgrade still explains itself.
	 */
	public function test_redacted_replay_arg_renders_the_same_body_as_blocked(): void
	{
		$this->stub_notice_helpers();

		$_GET[\WP_Sudo\Challenge::BLOCKED_REPLAY_QUERY_ARG] = '1';
		$blocked = $this->capture_notice('render_blocked_replay_notice');
		unset($_GET[\WP_Sudo\Challenge::BLOCKED_REPLAY_QUERY_ARG]);

		$_GET[\WP_Sudo\Challenge::REDACTED_REPLAY_QUERY_ARG] = '1';
		$redacted = $this->capture_notice('render_redacted_replay_notice');
		unset($_GET[\WP_Sudo\Challenge::REDACTED_REPLAY_QUERY_ARG]);

		$this->assertNotSame('', $redacted, 'The legacy arg must still explain itself.');
		$this->assertSame(
			$blocked,
			$redacted,
			'One honest string on every path — the axis described secrets wrongly.'
		);
		$this->assertStringNotContainsString(
			'were not replayed',
			$redacted,
			'Naming which fields were not replayed implies the others were (#469).'
		);
	}

	/**
	 * Test only one notice renders when both args are present.
	 *
	 * Both renderers are hooked to admin_notices, and a URL surviving an upgrade
	 * can carry both args. Two identical warnings stacked on one screen is its
	 * own defect.
	 */
	public function test_only_one_notice_renders_when_both_args_are_present(): void
	{
		$this->stub_notice_helpers();
		$_GET[\WP_Sudo\Challenge::BLOCKED_REPLAY_QUERY_ARG]  = '1';
		$_GET[\WP_Sudo\Challenge::REDACTED_REPLAY_QUERY_ARG] = '1';

		$output = $this->capture_notice('render_blocked_replay_notice')
			. $this->capture_notice('render_redacted_replay_notice');

		$this->assertSame(
			1,
			substr_count($output, 'notice-warning'),
			'Exactly one notice must render when a URL carries both args.'
		);

		unset(
			$_GET[\WP_Sudo\Challenge::BLOCKED_REPLAY_QUERY_ARG],
			$_GET[\WP_Sudo\Challenge::REDACTED_REPLAY_QUERY_ARG]
		);
	}

	/**
	 * Test the notice is announced to assistive technology.
	 *
	 * The in-page challenge notices at render_page() carry role="alert"; these do
	 * not. On the dashboard landing a screen-reader user gets no announcement
	 * that the action they just took was discarded — which is the entire content
	 * of the message.
	 */
	public function test_blocked_replay_notice_is_announced_to_assistive_technology(): void
	{
		$this->stub_notice_helpers();
		$_GET[\WP_Sudo\Challenge::BLOCKED_REPLAY_QUERY_ARG] = '1';

		$output = $this->capture_notice('render_blocked_replay_notice');

		$this->assertStringContainsString('role="alert"', $output);

		unset($_GET[\WP_Sudo\Challenge::BLOCKED_REPLAY_QUERY_ARG]);
	}
}
