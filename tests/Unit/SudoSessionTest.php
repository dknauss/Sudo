<?php
/**
 * Tests for WP_Sudo\Sudo_Session (v2).
 *
 * In v2, Sudo_Session is a stripped-down session manager. It no longer
 * handles capability escalation, role checks, admin bar, or reauth pages.
 * Tests cover: is_active, activate, deactivate, time_remaining,
 * attempt_activation, token binding, rate limiting, and 2FA hooks.
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Sudo_Session;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;

/**
 * @covers \WP_Sudo\Sudo_Session
 */
class SudoSessionTest extends TestCase
{

	protected function tearDown(): void
	{
		unset($_COOKIE[Sudo_Session::TOKEN_COOKIE]);
		parent::tearDown();
	}

	// =================================================================
	// Constants
	// =================================================================

	public function test_meta_key_constant(): void
	{
		$this->assertSame('_wp_sudo_expires', Sudo_Session::META_KEY);
	}

	public function test_token_meta_key_constant(): void
	{
		$this->assertSame('_wp_sudo_token', Sudo_Session::TOKEN_META_KEY);
	}

	public function test_token_cookie_constant(): void
	{
		$this->assertSame('wp_sudo_token', Sudo_Session::TOKEN_COOKIE);
	}

	public function test_lockout_constants(): void
	{
		$this->assertSame('_wp_sudo_failed_attempts', Sudo_Session::LOCKOUT_META_KEY);
		$this->assertSame('_wp_sudo_lockout_until', Sudo_Session::LOCKOUT_UNTIL_META_KEY);
		$this->assertSame(5, Sudo_Session::MAX_FAILED_ATTEMPTS);
		$this->assertSame(300, Sudo_Session::LOCKOUT_DURATION);
	}

	public function test_progressive_delays_constant(): void
	{
		$this->assertSame(array(4 => 2, 5 => 5), Sudo_Session::PROGRESSIVE_DELAYS);
	}

	// =================================================================
	// time_remaining()
	// =================================================================

	public function test_time_remaining_returns_zero_when_no_meta(): void
	{
		Functions\when('get_user_meta')->justReturn('');

		$this->assertSame(0, Sudo_Session::time_remaining(1));
	}

	public function test_time_remaining_returns_positive_when_active(): void
	{
		$future = time() + 300;
		Functions\when('get_user_meta')->justReturn($future);

		$remaining = Sudo_Session::time_remaining(1);

		$this->assertGreaterThan(0, $remaining);
		$this->assertLessThanOrEqual(300, $remaining);
	}

	public function test_time_remaining_returns_zero_when_expired(): void
	{
		$past = time() - 60;
		Functions\when('get_user_meta')->justReturn($past);

		$this->assertSame(0, Sudo_Session::time_remaining(1));
	}

	// =================================================================
	// is_active() — v2: no user_is_allowed() check
	// =================================================================

	public function test_is_active_returns_false_when_no_expiry(): void
	{
		Functions\when('get_user_meta')->justReturn('');

		$this->assertFalse(Sudo_Session::is_active(1));
	}

	public function test_is_active_returns_false_when_expired(): void
	{
		$past = time() - 60;
		Functions\when('get_user_meta')->justReturn($past);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('headers_sent')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);

		$this->assertFalse(Sudo_Session::is_active(1));
	}

	public function test_is_active_returns_false_when_no_cookie(): void
	{
		$future = time() + 300;

		Functions\when('get_user_meta')->alias(function ($uid, $key, $single) use ($future) {
			if (Sudo_Session::META_KEY === $key) {
				return $future;
			}
			if (Sudo_Session::TOKEN_META_KEY === $key) {
				return hash('sha256', 'correct-token');
			}
			return '';
		});

		// No cookie set.
		unset($_COOKIE[Sudo_Session::TOKEN_COOKIE]);

		$this->assertFalse(Sudo_Session::is_active(1));
	}

	public function test_is_active_returns_false_when_token_mismatch(): void
	{
		$future = time() + 300;

		Functions\when('get_user_meta')->alias(function ($uid, $key, $single) use ($future) {
			if (Sudo_Session::META_KEY === $key) {
				return $future;
			}
			if (Sudo_Session::TOKEN_META_KEY === $key) {
				return hash('sha256', 'correct-token');
			}
			return '';
		});

		// Wrong cookie value.
		$_COOKIE[Sudo_Session::TOKEN_COOKIE] = 'wrong-token';

		$this->assertFalse(Sudo_Session::is_active(1));
	}

	public function test_is_active_returns_true_when_valid(): void
	{
		$future = time() + 300;
		$token = 'valid-token-456';

		Functions\when('get_user_meta')->alias(function ($uid, $key, $single) use ($future, $token) {
			if (Sudo_Session::META_KEY === $key) {
				return $future;
			}
			if (Sudo_Session::TOKEN_META_KEY === $key) {
				return hash('sha256', $token);
			}
			return '';
		});

		$_COOKIE[Sudo_Session::TOKEN_COOKIE] = $token;

		// v2: is_active() only checks expiry + token. No role check.
		$this->assertTrue(Sudo_Session::is_active(1));
	}

	public function test_is_active_clears_session_when_expired(): void
	{
		// Use a timestamp well beyond the grace window (GRACE_SECONDS = 120 s)
		// to ensure cleanup actually fires. A value within the grace window would
		// be deferred and this assertion would fail.
		$past = time() - (Sudo_Session::GRACE_SECONDS + 60);
		Functions\when('get_user_meta')->justReturn($past);
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('headers_sent')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);

		Functions\expect('delete_user_meta')
			->twice(); // META_KEY + TOKEN_META_KEY

		Sudo_Session::is_active(1);
	}

	// =================================================================
	// activate()
	// =================================================================

	public function test_activate_stores_expiry_and_token(): void
	{
		Functions\when('get_option')->justReturn(array('session_duration' => 10));
		Functions\when('wp_generate_password')->justReturn('generated-token-xyz');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('headers_sent')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);

		Functions\expect('update_user_meta')
			->twice(); // Expiry + token hash.

		Actions\expectDone('wp_sudo_activated')
			->once()
			->with(7, \Mockery::type('int'), 10);

		$result = Sudo_Session::activate(7);

		$this->assertTrue($result);
	}

	public function test_activate_sets_cookie_in_superglobal(): void
	{
		Functions\when('get_option')->justReturn(array('session_duration' => 5));
		Functions\when('wp_generate_password')->justReturn('cookie-token');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('headers_sent')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);
		Functions\when('update_user_meta')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);

		Sudo_Session::activate(3);

		$this->assertSame('cookie-token', $_COOKIE[Sudo_Session::TOKEN_COOKIE]);
	}

	public function test_activate_sets_meta_and_superglobal_when_headers_already_sent(): void
	{
		Functions\when('get_option')->justReturn(array('session_duration' => 5));
		Functions\when('wp_generate_password')->justReturn('headers-sent-token');
		Functions\when('headers_sent')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);

		Functions\expect('setcookie')->never();
		Functions\expect('update_user_meta')->twice();

		$result = Sudo_Session::activate(3);

		$this->assertTrue($result);
		$this->assertSame('headers-sent-token', $_COOKIE[Sudo_Session::TOKEN_COOKIE]);
	}

	// =================================================================
	// deactivate()
	// =================================================================

	public function test_deactivate_clears_session_data(): void
	{
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('headers_sent')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);

		Functions\expect('delete_user_meta')
			->twice(); // META_KEY + TOKEN_META_KEY

		Actions\expectDone('wp_sudo_deactivated')
			->once()
			->with(9);

		Sudo_Session::deactivate(9);
	}

	public function test_deactivate_expires_cookie(): void
	{
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('headers_sent')->justReturn(false);

		// Clears cookies on both COOKIEPATH and ADMIN_COOKIE_PATH (stale cleanup).
		Functions\expect('setcookie')
			->twice()
			->with(
				Sudo_Session::TOKEN_COOKIE,
				'',
				\Mockery::type('array')
			);

		Sudo_Session::deactivate(9);
	}

	// =================================================================
	// Rate limiting — is_locked_out() is public in v2
	// =================================================================

	public function test_is_locked_out_returns_false_when_no_lockout(): void
	{
		Functions\when('get_user_meta')->justReturn('');

		$this->assertFalse(Sudo_Session::is_locked_out(1));
	}

	public function test_is_locked_out_returns_true_during_lockout(): void
	{
		$until = time() + 120;
		Functions\when('get_user_meta')->justReturn($until);

		$this->assertTrue(Sudo_Session::is_locked_out(1));
	}

	public function test_is_locked_out_returns_false_after_expiry(): void
	{
		$past = time() - 60;
		Functions\when('get_user_meta')->justReturn($past);
		Functions\when('delete_user_meta')->justReturn(true);

		$this->assertFalse(Sudo_Session::is_locked_out(1));
	}

	public function test_is_locked_out_resets_on_expiry(): void
	{
		$past = time() - 60;
		Functions\when('get_user_meta')->justReturn($past);

		// reset_failed_attempts deletes legacy lockout + lockout_until + failure_event + throttle.
		Functions\expect('delete_user_meta')
			->times(4);

		Sudo_Session::is_locked_out(1);
	}

	// =================================================================
	// attempt_activation() — v2: no user_is_allowed() check
	// =================================================================

	public function test_attempt_activation_rejects_locked_out_user(): void
	{
		$lockout_until = time() + 200;
		Functions\when('get_user_meta')->alias(function ($uid, $key, $single) use ($lockout_until) {
			if (Sudo_Session::LOCKOUT_UNTIL_META_KEY === $key) {
				return $lockout_until;
			}
			return '';
		});

		$result = Sudo_Session::attempt_activation(1, 'any-password');

		$this->assertSame('locked_out', $result['code']);
		$this->assertGreaterThan(0, $result['remaining']);
	}

	public function test_attempt_activation_rejects_wrong_password(): void
	{
		Functions\when('get_user_meta')->justReturn('');
		Functions\when('add_user_meta')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);

		$user = new \WP_User(1, array('editor'));
		Functions\when('get_userdata')->justReturn($user);
		Functions\when('wp_check_password')->justReturn(false);
		Functions\when('update_user_meta')->justReturn(true);

		$result = Sudo_Session::attempt_activation(1, 'wrong-password');

		$this->assertSame('invalid_password', $result['code']);
	}

	public function test_attempt_activation_rejects_invalid_user(): void
	{
		Functions\when('get_user_meta')->justReturn('');
		Functions\when('get_userdata')->justReturn(false);
		Functions\when('add_user_meta')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('update_user_meta')->justReturn(true);

		$result = Sudo_Session::attempt_activation(999, 'any-password');

		$this->assertSame('invalid_password', $result['code']);
	}

	public function test_attempt_activation_fires_reauth_failed_hook(): void
	{
		Functions\when('get_user_meta')->justReturn('');
		Functions\when('add_user_meta')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);

		$user = new \WP_User(1, array('editor'));
		Functions\when('get_userdata')->justReturn($user);
		Functions\when('wp_check_password')->justReturn(false);
		Functions\when('update_user_meta')->justReturn(true);

		Actions\expectDone('wp_sudo_reauth_failed')
			->once()
			->with(1, \Mockery::type('int'));

		Sudo_Session::attempt_activation(1, 'wrong-password');
	}

	public function test_attempt_activation_succeeds_with_correct_password(): void
	{
		Functions\when('get_user_meta')->justReturn('');

		$user = new \WP_User(1, array('editor'));
		Functions\when('get_userdata')->justReturn($user);
		Functions\when('wp_check_password')->justReturn(true);
		Functions\when('update_user_meta')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('wp_generate_password')->justReturn('test-token-123');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('headers_sent')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);
		Functions\when('get_option')->justReturn(array('session_duration' => 15));

		// 2FA not active.
		Functions\when('apply_filters')->justReturn(false);

		$result = Sudo_Session::attempt_activation(1, 'correct-password');

		$this->assertSame('success', $result['code']);
	}

	public function test_attempt_activation_returns_2fa_pending_when_needed(): void
	{
		Functions\when('get_user_meta')->justReturn('');

		$user = new \WP_User(1, array('editor'));
		Functions\when('get_userdata')->justReturn($user);
		Functions\when('wp_check_password')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('set_transient')->justReturn(true);
		Functions\when('wp_generate_password')->justReturn('test-challenge-nonce');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('headers_sent')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);

		// Mock needs_two_factor to return true via the filter.
		Functions\when('apply_filters')->justReturn(true);

		$result = Sudo_Session::attempt_activation(1, 'correct-password');

		$this->assertSame('2fa_pending', $result['code']);
		$this->assertArrayHasKey('expires_at', $result);
		$this->assertIsInt($result['expires_at']);
		$this->assertGreaterThan(time(), $result['expires_at']);
	}

	/**
	 * Test that the wp_sudo_two_factor_window filter adjusts the 2FA transient expiry.
	 */
	public function test_attempt_activation_2fa_window_is_filterable(): void
	{
		Functions\when('get_user_meta')->justReturn('');

		$user = new \WP_User(1, array('editor'));
		Functions\when('get_userdata')->justReturn($user);
		Functions\when('wp_check_password')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('wp_generate_password')->justReturn('test-challenge-nonce');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('headers_sent')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);

		// Capture the transient TTL.
		$stored_ttl = null;
		Functions\expect('set_transient')
			->once()
			->with(
				\Mockery::on(function ($key) {
					// Transient key is now wp_sudo_2fa_pending_{hash}.
					return str_starts_with($key, 'wp_sudo_2fa_pending_');
				}),
				\Mockery::type('array'),
				\Mockery::on(function ($ttl) use (&$stored_ttl) {
					$stored_ttl = $ttl;
					return true;
				})
			)
			->andReturn(true);

		// apply_filters: first call is wp_sudo_two_factor_window, return 15 min;
		// second call is wp_sudo_requires_two_factor, return true (needs 2FA).
		Functions\expect('apply_filters')
			->twice()
			->andReturnUsing(function ($filter_name) {
				if ('wp_sudo_requires_two_factor' === $filter_name) {
					return true;
				}
				if ('wp_sudo_two_factor_window' === $filter_name) {
					return 15 * MINUTE_IN_SECONDS;
				}
				return null;
			});

		$result = Sudo_Session::attempt_activation(1, 'correct-password');

		$this->assertSame('2fa_pending', $result['code']);
		$this->assertSame(15 * MINUTE_IN_SECONDS, $stored_ttl);
	}

	/**
	 * Test that the 2FA window is clamped to a minimum of 1 minute.
	 */
	public function test_two_factor_window_clamps_below_minimum(): void
	{
		Functions\when('get_user_meta')->justReturn('');

		$user = new \WP_User(1, array('editor'));
		Functions\when('get_userdata')->justReturn($user);
		Functions\when('wp_check_password')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('wp_generate_password')->justReturn('test-challenge-nonce');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('headers_sent')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);

		$stored_ttl = null;
		Functions\expect('set_transient')
			->once()
			->with(
				\Mockery::on(function ($key) {
					return str_starts_with($key, 'wp_sudo_2fa_pending_');
				}),
				\Mockery::type('array'),
				\Mockery::on(function ($ttl) use (&$stored_ttl) {
					$stored_ttl = $ttl;
					return true;
				})
			)
			->andReturn(true);

		// Return 10 seconds — well below the 1-minute minimum.
		Functions\expect('apply_filters')
			->twice()
			->andReturnUsing(function ($filter_name) {
				if ('wp_sudo_requires_two_factor' === $filter_name) {
					return true;
				}
				if ('wp_sudo_two_factor_window' === $filter_name) {
					return 10;
				}
				return null;
			});

		$result = Sudo_Session::attempt_activation(1, 'correct-password');

		$this->assertSame('2fa_pending', $result['code']);
		$this->assertSame(MINUTE_IN_SECONDS, $stored_ttl, 'Window should be clamped to 60 seconds minimum.');
	}

	/**
	 * Test that the 2FA window is clamped to a maximum of 15 minutes.
	 */
	public function test_two_factor_window_clamps_above_maximum(): void
	{
		Functions\when('get_user_meta')->justReturn('');

		$user = new \WP_User(1, array('editor'));
		Functions\when('get_userdata')->justReturn($user);
		Functions\when('wp_check_password')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('wp_generate_password')->justReturn('test-challenge-nonce');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('headers_sent')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);

		$stored_ttl = null;
		Functions\expect('set_transient')
			->once()
			->with(
				\Mockery::on(function ($key) {
					return str_starts_with($key, 'wp_sudo_2fa_pending_');
				}),
				\Mockery::type('array'),
				\Mockery::on(function ($ttl) use (&$stored_ttl) {
					$stored_ttl = $ttl;
					return true;
				})
			)
			->andReturn(true);

		// Return 3600 seconds (1 hour) — above the 15-minute maximum.
		Functions\expect('apply_filters')
			->twice()
			->andReturnUsing(function ($filter_name) {
				if ('wp_sudo_requires_two_factor' === $filter_name) {
					return true;
				}
				if ('wp_sudo_two_factor_window' === $filter_name) {
					return 3600;
				}
				return null;
			});

		$result = Sudo_Session::attempt_activation(1, 'correct-password');

		$this->assertSame('2fa_pending', $result['code']);
		$this->assertSame(15 * MINUTE_IN_SECONDS, $stored_ttl, 'Window should be clamped to 900 seconds maximum.');
	}

	/**
	 * Test that a valid 2FA window value within bounds is accepted as-is.
	 */
	public function test_two_factor_window_accepts_valid_value(): void
	{
		Functions\when('get_user_meta')->justReturn('');

		$user = new \WP_User(1, array('editor'));
		Functions\when('get_userdata')->justReturn($user);
		Functions\when('wp_check_password')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('wp_generate_password')->justReturn('test-challenge-nonce');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('headers_sent')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);

		$stored_ttl = null;
		Functions\expect('set_transient')
			->once()
			->with(
				\Mockery::on(function ($key) {
					return str_starts_with($key, 'wp_sudo_2fa_pending_');
				}),
				\Mockery::type('array'),
				\Mockery::on(function ($ttl) use (&$stored_ttl) {
					$stored_ttl = $ttl;
					return true;
				})
			)
			->andReturn(true);

		// Return 600 seconds (10 minutes) — valid, within bounds.
		Functions\expect('apply_filters')
			->twice()
			->andReturnUsing(function ($filter_name) {
				if ('wp_sudo_requires_two_factor' === $filter_name) {
					return true;
				}
				if ('wp_sudo_two_factor_window' === $filter_name) {
					return 600;
				}
				return null;
			});

		$result = Sudo_Session::attempt_activation(1, 'correct-password');

		$this->assertSame('2fa_pending', $result['code']);
		$this->assertSame(600, $stored_ttl, 'Valid window value should be accepted as-is.');
	}

	// =================================================================
	// needs_two_factor()
	// =================================================================

	public function test_needs_two_factor_returns_false_by_default(): void
	{
		Functions\when('apply_filters')->justReturn(false);

		$this->assertFalse(Sudo_Session::needs_two_factor(1));
	}

	public function test_needs_two_factor_respects_filter(): void
	{
		Functions\when('apply_filters')->justReturn(true);

		$this->assertTrue(Sudo_Session::needs_two_factor(1));
	}

	public function test_needs_two_factor_returns_false_when_provider_is_absent(): void
	{
		\Two_Factor_Core::$mock_provider = null;
		Functions\when('apply_filters')->alias(
			static function ($filter_name, $value) {
				return $value;
			}
		);

		$this->assertFalse(Sudo_Session::needs_two_factor(1));
	}

	public function test_needs_two_factor_returns_true_when_provider_exists(): void
	{
		\Two_Factor_Core::$mock_provider = new \Two_Factor_Provider();
		Functions\when('apply_filters')->alias(
			static function ($filter_name, $value) {
				return $value;
			}
		);

		$this->assertTrue(Sudo_Session::needs_two_factor(1));

		\Two_Factor_Core::$mock_provider = null;
	}

	// =================================================================
	// Challenge cookie constant
	// =================================================================

	public function test_challenge_cookie_constant(): void
	{
		$this->assertSame('wp_sudo_challenge', Sudo_Session::CHALLENGE_COOKIE);
	}

	// =================================================================
	// 2FA browser binding — attempt_activation sets challenge cookie
	// =================================================================

	public function test_2fa_pending_sets_challenge_cookie(): void
	{
		Functions\when('get_user_meta')->justReturn('');

		$user = new \WP_User(1, array('editor'));
		Functions\when('get_userdata')->justReturn($user);
		Functions\when('wp_check_password')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('set_transient')->justReturn(true);
		Functions\when('apply_filters')->justReturn(true);
		Functions\when('wp_generate_password')->justReturn('test-challenge-nonce-abc');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('headers_sent')->justReturn(false);

		// Verify setcookie is called with the CHALLENGE_COOKIE name.
		Functions\expect('setcookie')
			->once()
			->with(
				Sudo_Session::CHALLENGE_COOKIE,
				'test-challenge-nonce-abc',
				\Mockery::type('array')
			);

		Sudo_Session::attempt_activation(1, 'correct-password');

		// Also set in superglobal for current request.
		$this->assertSame('test-challenge-nonce-abc', $_COOKIE[Sudo_Session::CHALLENGE_COOKIE]);
	}

	public function test_2fa_pending_keys_transient_by_challenge_hash(): void
	{
		Functions\when('get_user_meta')->justReturn('');

		$user = new \WP_User(1, array('editor'));
		Functions\when('get_userdata')->justReturn($user);
		Functions\when('wp_check_password')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('apply_filters')->justReturn(true);
		Functions\when('wp_generate_password')->justReturn('challenge-nonce-xyz');
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('headers_sent')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);

		$expected_hash = hash('sha256', 'challenge-nonce-xyz');

		// Transient key must be hash-based, and value must be an array.
		Functions\expect('set_transient')
			->once()
			->with(
				'wp_sudo_2fa_pending_' . $expected_hash,
				\Mockery::on(function ($value) {
					return is_array($value)
						&& isset($value['user_id'])
						&& 1 === $value['user_id']
						&& isset($value['expires_at'])
						&& $value['expires_at'] > time();
				}),
				\Mockery::type('int')
			)
			->andReturn(true);

		Sudo_Session::attempt_activation(1, 'correct-password');
	}

	// =================================================================
	// get_2fa_pending()
	// =================================================================

	public function test_get_2fa_pending_returns_null_without_cookie(): void
	{
		// No challenge cookie set.
		unset($_COOKIE[Sudo_Session::CHALLENGE_COOKIE]);

		$result = Sudo_Session::get_2fa_pending(1);

		$this->assertNull($result);
	}

	public function test_get_2fa_pending_returns_null_for_wrong_user(): void
	{
		$_COOKIE[Sudo_Session::CHALLENGE_COOKIE] = 'test-nonce-for-user-5';
		$challenge_hash = hash('sha256', 'test-nonce-for-user-5');

		Functions\expect('get_transient')
			->once()
			->with('wp_sudo_2fa_pending_' . $challenge_hash)
			->andReturn(array(
				'user_id' => 5,
				'expires_at' => time() + 600,
			));

		// Requesting as user 99 — mismatch.
		$result = Sudo_Session::get_2fa_pending(99);

		$this->assertNull($result);
	}

	public function test_get_2fa_pending_returns_data_for_valid_session(): void
	{
		$nonce = 'valid-challenge-nonce-123';
		$challenge_hash = hash('sha256', $nonce);
		$expires = time() + 600;

		$_COOKIE[Sudo_Session::CHALLENGE_COOKIE] = $nonce;

		Functions\expect('get_transient')
			->once()
			->with('wp_sudo_2fa_pending_' . $challenge_hash)
			->andReturn(array(
				'user_id' => 42,
				'expires_at' => $expires,
			));

		$result = Sudo_Session::get_2fa_pending(42);

		$this->assertIsArray($result);
		$this->assertSame(42, $result['user_id']);
		$this->assertSame($expires, $result['expires_at']);
	}

	public function test_get_2fa_pending_returns_null_when_expired(): void
	{
		$nonce = 'expired-challenge-nonce';
		$challenge_hash = hash('sha256', $nonce);

		$_COOKIE[Sudo_Session::CHALLENGE_COOKIE] = $nonce;

		Functions\expect('get_transient')
			->once()
			->with('wp_sudo_2fa_pending_' . $challenge_hash)
			->andReturn(array(
				'user_id' => 42,
				'expires_at' => time() - 60,
			));

		$result = Sudo_Session::get_2fa_pending(42);

		$this->assertNull($result);
	}

	// =================================================================
	// clear_2fa_pending()
	// =================================================================

	public function test_clear_2fa_pending_deletes_transient_and_cookie(): void
	{
		$nonce = 'clear-me-nonce';
		$challenge_hash = hash('sha256', $nonce);

		$_COOKIE[Sudo_Session::CHALLENGE_COOKIE] = $nonce;

		Functions\expect('delete_transient')
			->once()
			->with('wp_sudo_2fa_pending_' . $challenge_hash);

		Functions\when('is_ssl')->justReturn(false);
		Functions\when('headers_sent')->justReturn(false);

		Functions\expect('setcookie')
			->once()
			->with(
				Sudo_Session::CHALLENGE_COOKIE,
				'',
				\Mockery::type('array')
			);

		Sudo_Session::clear_2fa_pending();

		// Cookie should be unset from superglobal.
		$this->assertArrayNotHasKey(Sudo_Session::CHALLENGE_COOKIE, $_COOKIE);
	}

	// =================================================================
	// is_within_grace() — grace period (two-tier expiry)
	// =================================================================

	/**
	 * is_within_grace() returns false when no session meta exists.
	 *
	 * A user with no sudo session has no expiry record, so there is nothing
	 * to be in grace for.
	 */
	public function test_is_within_grace_returns_false_when_no_session(): void
	{
		Functions\when('get_user_meta')->justReturn(0);

		$this->assertFalse(Sudo_Session::is_within_grace(1));
	}

	/**
	 * is_within_grace() returns false when the session is still active.
	 *
	 * Grace only applies after expiry — an active session is not in grace.
	 */
	public function test_is_within_grace_returns_false_when_session_still_active(): void
	{
		$future = time() + 60;

		Functions\when('get_user_meta')->alias(function ($uid, $key, $single) use ($future) {
			if (Sudo_Session::META_KEY === $key) {
				return $future;
			}
			return '';
		});

		$this->assertFalse(Sudo_Session::is_within_grace(1));
	}

	/**
	 * is_within_grace() returns true when just expired with a valid cookie token.
	 *
	 * A session that expired 30 seconds ago is within the GRACE_SECONDS window.
	 * The cookie token must still match — grace does not bypass session binding.
	 */
	public function test_is_within_grace_returns_true_when_just_expired_with_valid_token(): void
	{
		$past = time() - 30;
		$token = 'grace-valid-token';

		Functions\when('get_user_meta')->alias(function ($uid, $key, $single) use ($past, $token) {
			if (Sudo_Session::META_KEY === $key) {
				return $past;
			}
			if (Sudo_Session::TOKEN_META_KEY === $key) {
				return hash('sha256', $token);
			}
			return '';
		});

		$_COOKIE[Sudo_Session::TOKEN_COOKIE] = $token;

		$this->assertTrue(Sudo_Session::is_within_grace(1));
	}

	/**
	 * is_within_grace() returns false when the session is beyond the grace window.
	 *
	 * Once GRACE_SECONDS has elapsed after expiry, the grace window is closed and
	 * the user must re-authenticate.
	 */
	public function test_is_within_grace_returns_false_when_beyond_grace_window(): void
	{
		$past = time() - (Sudo_Session::GRACE_SECONDS + 60);

		Functions\when('get_user_meta')->alias(function ($uid, $key, $single) use ($past) {
			if (Sudo_Session::META_KEY === $key) {
				return $past;
			}
			return '';
		});

		$this->assertFalse(Sudo_Session::is_within_grace(1));
	}

	/**
	 * is_within_grace() returns false when the cookie token does not match.
	 *
	 * Grace does not relax session binding — a mismatched or absent cookie means
	 * the request is not from the same browser that authenticated, so grace is denied.
	 */
	public function test_is_within_grace_returns_false_without_valid_token(): void
	{
		$past = time() - 30;

		Functions\when('get_user_meta')->alias(function ($uid, $key, $single) use ($past) {
			if (Sudo_Session::META_KEY === $key) {
				return $past;
			}
			if (Sudo_Session::TOKEN_META_KEY === $key) {
				return hash('sha256', 'correct-token');
			}
			return '';
		});

		// No matching cookie set.
		unset($_COOKIE[Sudo_Session::TOKEN_COOKIE]);

		$this->assertFalse(Sudo_Session::is_within_grace(1));
	}

	// =================================================================
	// is_active() — deferred cleanup behaviour with grace window
	// =================================================================

	/**
	 * is_active() does NOT call delete_user_meta when expiry is within the grace window.
	 *
	 * The session meta must remain readable so is_within_grace() can verify the
	 * token and let an in-flight form submission through, even though is_active()
	 * itself returns false.
	 */
	public function test_is_active_defers_cleanup_during_grace_window(): void
	{
		$past = time() - 30; // Expired 30 s ago — still within GRACE_SECONDS (120 s).

		Functions\when('get_user_meta')->justReturn($past);
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('headers_sent')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);

		// Cleanup must be deferred — meta must survive for is_within_grace() to read.
		Functions\expect('delete_user_meta')->never();

		$result = Sudo_Session::is_active(1);

		$this->assertFalse($result);
	}

	/**
	 * is_active() DOES call delete_user_meta when the session is beyond the grace window.
	 *
	 * Once GRACE_SECONDS has elapsed, the meta is no longer needed and must be
	 * cleaned up to prevent stale data from accumulating.
	 */
	public function test_is_active_cleans_up_after_grace_window(): void
	{
		$past = time() - (Sudo_Session::GRACE_SECONDS + 60); // Well beyond grace.

		Functions\when('get_user_meta')->justReturn($past);
		Functions\when('is_ssl')->justReturn(false);
		Functions\when('headers_sent')->justReturn(false);
		Functions\when('setcookie')->justReturn(true);

		Functions\expect('delete_user_meta')
			->twice(); // META_KEY + TOKEN_META_KEY

		$result = Sudo_Session::is_active(1);

		$this->assertFalse($result);
	}

	// =================================================================
	// Phase 2: Non-blocking rate limiting
	// =================================================================

	/**
	 * test_attempt_activation_returns_invalid_password_with_delay_when_throttled()
	 */
	public function test_attempt_activation_returns_invalid_password_with_delay_when_throttled(): void
	{
		$future = time() + 5;
		Functions\when('get_user_meta')->alias(function ($uid, $key) use ($future) {
			if ('_wp_sudo_throttle_until' === $key) {
				return $future;
			}
			return '';
		});

		$result = Sudo_Session::attempt_activation(1, 'any-password');

		$this->assertSame('invalid_password', $result['code']);
		$this->assertGreaterThan(0, $result['delay']);
		$this->assertLessThanOrEqual(5, $result['delay']);
	}

	/**
	 * test_attempt_activation_throttle_path_skips_wp_check_password()
	 */
	public function test_attempt_activation_throttle_path_skips_wp_check_password(): void
	{
		$future = time() + 5;
		Functions\when('get_user_meta')->alias(function ($uid, $key) use ($future) {
			if ('_wp_sudo_throttle_until' === $key) {
				return $future;
			}
			return '';
		});

		Functions\expect('wp_check_password')->never();

		Sudo_Session::attempt_activation(1, 'any-password');
	}

	public function test_record_failed_attempt_uses_add_user_meta_for_append_tracking(): void
	{
		Functions\when('get_user_meta')->justReturn(array());
		Functions\when('update_user_meta')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);

		// Should append a new row instead of updating a scalar.
		Functions\expect('add_user_meta')
			->once()
			->with(1, '_wp_sudo_failure_event', \Mockery::type('int'), false);

		// We need to trigger a failure to run record_failed_attempt.
		// Since it's private, we trigger it via attempt_activation.
		$user = new \WP_User(1, array('editor'));
		Functions\when('get_userdata')->justReturn($user);
		Functions\when('wp_check_password')->justReturn(false);

		Sudo_Session::attempt_activation(1, 'wrong-password');
	}

	/**
	 * test_lockout_still_triggers_at_five_attempts_with_non_blocking_model()
	 */
	public function test_lockout_still_triggers_at_five_attempts_with_non_blocking_model(): void
	{
		$call_count = 0;
		Functions\when('get_user_meta')->alias(function ($uid, $key, $single) use (&$call_count) {
			if ('_wp_sudo_failure_event' === $key && !$single) {
				$call_count++;
				// First call (check lockout before) -> return 4.
				// Second call (record_failed_attempt -> get_failed_attempts) -> return 5.
				return array_fill(0, ($call_count > 1 ? 5 : 4), 1000);
			}
			if ('_wp_sudo_lockout_until' === $key) {
				// return active lockout only after the update has potentially happened.
				return $call_count > 1 ? (time() + 300) : '';
			}
			return '';
		});

		Functions\when('add_user_meta')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('get_userdata')->justReturn(new \WP_User(1));
		Functions\when('wp_check_password')->justReturn(false);

		// Fifth attempt should trigger lockout meta update.
		Functions\expect( 'update_user_meta' )
			->once()
			->with( 1, Sudo_Session::LOCKOUT_UNTIL_META_KEY, \Mockery::type( 'int' ) );

		Actions\expectDone( 'wp_sudo_lockout' )
			->once()
			->with( 1, 5, \Mockery::type( 'string' ) );

		$result = Sudo_Session::attempt_activation( 1, 'wrong-password' );
		$this->assertSame( 'locked_out', $result['code'] );
	}

	/**
	 * test_successful_activation_clears_throttle_and_failure_event_meta()
	 */
	public function test_successful_activation_clears_throttle_and_failure_event_meta(): void {
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'wp_generate_password' )->justReturn( 'token' );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'headers_sent' )->justReturn( false );
		Functions\when( 'setcookie' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );

		// reset_failed_attempts deletes: legacy lockout + lockout_until + failure_event + throttle.
		Functions\expect( 'delete_user_meta' )->times( 4 );

		Sudo_Session::activate( 1 );
	}

	/**
	 * test_prune_failed_attempts_removes_old_events()
	 */
	public function test_prune_failed_attempts_removes_old_events(): void
	{
		$now = time();
		$day_ago = $now - 86400;

		// Two fresh, two stale.
		$events = array(
			$now - 10,
			$now - 20,
			$day_ago - 10,
			$day_ago - 20,
		);

		Functions\when('get_user_meta')->alias(function ($uid, $key, $single) use ($events) {
			if ('_wp_sudo_failure_event' === $key && !$single) {
				return $events;
			}
			return array();
		});

		// Should call delete_user_meta for the two stale timestamps.
		Functions\expect('delete_user_meta')
			->once()
			->with(1, '_wp_sudo_failure_event', $day_ago - 10);
		Functions\expect('delete_user_meta')
			->once()
			->with(1, '_wp_sudo_failure_event', $day_ago - 20);

		Functions\when('get_userdata')->justReturn(new \WP_User(1));
		Functions\when('wp_check_password')->justReturn(false);
		Functions\when('add_user_meta')->justReturn(true);
		Functions\when('update_user_meta')->justReturn(true);

		Sudo_Session::attempt_activation(1, 'wrong-password');
	}

	/**
	 * test_2fa_failure_triggers_rate_limit_increment()
	 */
	public function test_2fa_failure_triggers_rate_limit_increment(): void
	{
		Functions\when('get_user_meta')->justReturn(array());
		Functions\when('update_user_meta')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);

		// Verify that a failure explicitly recorded (e.g. from 2FA) uses add_user_meta as append.
		Functions\expect('add_user_meta')
			->once()
			->with(1, '_wp_sudo_failure_event', \Mockery::any(), false);

		Sudo_Session::record_failed_attempt(1);
	}

	// =================================================================
	// Phase 5: IP + user multidimensional rate limiting
	// =================================================================

	public function test_attempt_activation_rejects_when_ip_lockout_is_active(): void
	{
		$_SERVER['REMOTE_ADDR'] = ' 203.0.113.44 ';
		$ip_lockout_until       = time() + 200;

		Functions\when('get_user_meta')->alias(
			static function ($uid, $key, $single = true) {
				if (Sudo_Session::THROTTLE_UNTIL_META_KEY === $key) {
					return '';
				}
				if (Sudo_Session::LOCKOUT_UNTIL_META_KEY === $key) {
					return '';
				}
				if (Sudo_Session::FAILURE_EVENT_META_KEY === $key && !$single) {
					return array();
				}
				return '';
			}
		);
		Functions\when('get_userdata')->justReturn(new \WP_User(1));
		Functions\when('add_user_meta')->justReturn(true);
		Functions\when('update_user_meta')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('get_transient')->alias(
			static function ($key) use ($ip_lockout_until) {
				if (str_starts_with($key, 'wp_sudo_ip_lockout_')) {
					return $ip_lockout_until;
				}
				return false;
			}
		);

		Functions\expect('wp_check_password')->never();

		$result = Sudo_Session::attempt_activation(1, 'wrong-password');

		$this->assertSame('locked_out', $result['code']);
		$this->assertArrayHasKey('remaining', $result);
		$this->assertGreaterThan(0, $result['remaining']);
	}

	public function test_record_failed_attempt_locks_out_when_ip_threshold_is_reached(): void
	{
		$_SERVER['REMOTE_ADDR'] = '198.51.100.11';

		Functions\when('get_user_meta')->alias(
			static function ($uid, $key, $single = true) {
				if (Sudo_Session::FAILURE_EVENT_META_KEY === $key && !$single) {
					// Keep user attempt count low so IP threshold is the lockout trigger.
					return array(time() - 30);
				}
				return '';
			}
		);
		Functions\when('add_user_meta')->justReturn(true);
		Functions\when('delete_user_meta')->justReturn(true);
		Functions\when('set_transient')->justReturn(true);
		Functions\when('get_transient')->alias(
			static function ($key) {
				if (str_starts_with($key, 'wp_sudo_ip_failure_event_')) {
					return array(time() - 4, time() - 3, time() - 2, time() - 1);
				}
				return false;
			}
		);

		Functions\expect('update_user_meta')
			->once()
			->with(1, Sudo_Session::LOCKOUT_UNTIL_META_KEY, \Mockery::type('int'))
			->andReturn(true);

		Actions\expectDone('wp_sudo_lockout')
			->once()
			->with(1, 5, '198.51.100.11');

		$delay = Sudo_Session::record_failed_attempt(1);

		$this->assertSame(0, $delay);
	}
}
