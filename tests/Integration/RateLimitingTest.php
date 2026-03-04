<?php
/**
 * Integration tests for rate limiting — real user meta in the database.
 *
 * Tests use the append-row failure event model (add_user_meta) and
 * non-blocking throttle semantics. No sleep() calls are involved.
 *
 * @covers \WP_Sudo\Sudo_Session
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Sudo_Session;

class RateLimitingTest extends TestCase {

	/**
	 * SURF-05: Failed attempts increment via append-row event tracking.
	 *
	 * Three wrong-password calls via attempt_activation(). Each appends a
	 * failure event row via add_user_meta(). get_failed_attempts() derives
	 * the count from the number of rows.
	 */
	public function test_failed_attempts_increment_user_meta(): void {
		$user = $this->make_admin( 'correct-password' );
		wp_set_current_user( $user->ID );

		Sudo_Session::attempt_activation( $user->ID, 'wrong' );
		$this->assertSame(
			1,
			Sudo_Session::get_failed_attempts( $user->ID ),
			'After 1 failed attempt, event count should be 1.'
		);

		Sudo_Session::attempt_activation( $user->ID, 'wrong' );
		$this->assertSame(
			2,
			Sudo_Session::get_failed_attempts( $user->ID ),
			'After 2 failed attempts, event count should be 2.'
		);

		Sudo_Session::attempt_activation( $user->ID, 'wrong' );
		$this->assertSame(
			3,
			Sudo_Session::get_failed_attempts( $user->ID ),
			'After 3 failed attempts, event count should be 3.'
		);
	}

	/**
	 * SURF-05: attempt_activation() with wrong password returns 'invalid_password'.
	 */
	public function test_failed_attempt_returns_invalid_password(): void {
		$user = $this->make_admin( 'correct-password' );
		wp_set_current_user( $user->ID );

		$result = Sudo_Session::attempt_activation( $user->ID, 'wrong-password' );

		$this->assertSame( 'invalid_password', $result['code'] );
	}

	/**
	 * SURF-05: Successful activation resets all failure tracking.
	 *
	 * 2 failed attempts → 1 success → failure events + throttle cleared.
	 */
	public function test_successful_activation_resets_failed_attempts(): void {
		$password = 'correct-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );

		// 2 failed attempts.
		Sudo_Session::attempt_activation( $user->ID, 'wrong' );
		Sudo_Session::attempt_activation( $user->ID, 'wrong' );
		$this->assertSame( 2, Sudo_Session::get_failed_attempts( $user->ID ) );

		// Successful activation.
		$result = Sudo_Session::attempt_activation( $user->ID, $password );
		$this->assertSame( 'success', $result['code'] );

		// All failure tracking should be reset.
		$this->assertSame(
			0,
			Sudo_Session::get_failed_attempts( $user->ID ),
			'Failure event count should be 0 after successful activation.'
		);
		$this->assertEmpty(
			get_user_meta( $user->ID, Sudo_Session::THROTTLE_UNTIL_META_KEY, true ),
			'Throttle meta should be cleared after successful activation.'
		);
	}

	/**
	 * SURF-05: is_locked_out() returns true when lockout meta is set to the future.
	 */
	public function test_is_locked_out_with_simulated_lockout_meta(): void {
		$user = $this->make_admin();

		// Simulate an active lockout — 5 minutes in the future.
		update_user_meta(
			$user->ID,
			Sudo_Session::LOCKOUT_UNTIL_META_KEY,
			time() + Sudo_Session::LOCKOUT_DURATION
		);

		$this->assertTrue(
			Sudo_Session::is_locked_out( $user->ID ),
			'is_locked_out() should return true with future lockout timestamp.'
		);
	}

	/**
	 * SURF-05: Expired lockout returns false and cleans up all failure tracking.
	 *
	 * Sets lockout timestamp in the past. is_locked_out() should return false
	 * and reset failure events, throttle, and lockout meta.
	 */
	public function test_expired_lockout_returns_false_and_resets(): void {
		$user = $this->make_admin();

		// Simulate an expired lockout with failure event rows.
		update_user_meta( $user->ID, Sudo_Session::LOCKOUT_UNTIL_META_KEY, time() - 60 );
		for ( $i = 0; $i < 5; $i++ ) {
			add_user_meta( $user->ID, Sudo_Session::FAILURE_EVENT_META_KEY, time() - 120, false );
		}

		$this->assertFalse(
			Sudo_Session::is_locked_out( $user->ID ),
			'is_locked_out() should return false for expired lockout.'
		);

		// All failure tracking should be cleaned up.
		$this->assertSame(
			0,
			Sudo_Session::get_failed_attempts( $user->ID ),
			'Failure event rows should be cleared after expired lockout.'
		);
		$this->assertEmpty(
			get_user_meta( $user->ID, Sudo_Session::LOCKOUT_UNTIL_META_KEY, true ),
			'Lockout-until meta should be reset after expired lockout.'
		);
	}

	/**
	 * Non-blocking throttle: attempt during active throttle returns delay.
	 *
	 * Deterministically sets THROTTLE_UNTIL_META_KEY to a known future
	 * timestamp, then verifies attempt_activation returns immediately
	 * with delay metadata and does not trigger lockout.
	 */
	public function test_retry_during_throttle_window_returns_delay(): void {
		$user = $this->make_admin( 'correct-password' );
		wp_set_current_user( $user->ID );

		// Directly set throttle meta — deterministic, no wall-clock dependency.
		update_user_meta( $user->ID, Sudo_Session::THROTTLE_UNTIL_META_KEY, time() + 10 );

		$result = Sudo_Session::attempt_activation( $user->ID, 'wrong-password' );

		$this->assertSame( 'invalid_password', $result['code'], 'Throttled attempt should return invalid_password.' );
		$this->assertArrayHasKey( 'delay', $result, 'Throttled response should include delay.' );
		$this->assertGreaterThan( 0, $result['delay'], 'Delay should be positive.' );
		$this->assertFalse(
			Sudo_Session::is_locked_out( $user->ID ),
			'Throttled attempt should not trigger lockout.'
		);
	}

	/**
	 * Throttle window skips password check — even correct password is rejected.
	 */
	public function test_throttle_window_skips_password_check(): void {
		$password = 'correct-password';
		$user     = $this->make_admin( $password );
		wp_set_current_user( $user->ID );

		// Set active throttle.
		update_user_meta( $user->ID, Sudo_Session::THROTTLE_UNTIL_META_KEY, time() + 10 );

		// Even the correct password should be rejected with delay.
		$result = Sudo_Session::attempt_activation( $user->ID, $password );

		$this->assertSame( 'invalid_password', $result['code'], 'Correct password should be rejected during throttle.' );
		$this->assertArrayHasKey( 'delay', $result );
		$this->assertFalse(
			Sudo_Session::is_active( $user->ID ),
			'Session should not be active when throttled.'
		);
	}

	/**
	 * SURF-05: attempt_activation() returns 'locked_out' with remaining seconds
	 * when user is in lockout state.
	 */
	public function test_attempt_activation_returns_locked_out(): void {
		$user = $this->make_admin( 'correct-password' );
		wp_set_current_user( $user->ID );

		// Simulate active lockout (5 minutes in the future).
		$lockout_until = time() + Sudo_Session::LOCKOUT_DURATION;
		update_user_meta( $user->ID, Sudo_Session::LOCKOUT_UNTIL_META_KEY, $lockout_until );

		$result = Sudo_Session::attempt_activation( $user->ID, 'correct-password' );

		$this->assertSame( 'locked_out', $result['code'] );
		$this->assertArrayHasKey( 'remaining', $result );
		$this->assertGreaterThan( 0, $result['remaining'], 'Remaining seconds should be positive.' );
		$this->assertLessThanOrEqual(
			Sudo_Session::LOCKOUT_DURATION,
			$result['remaining'],
			'Remaining should not exceed lockout duration.'
		);
	}
}
