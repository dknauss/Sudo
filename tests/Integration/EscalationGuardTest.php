<?php
/**
 * Live-hook integration coverage for the role-aware admin-escalation guard.
 *
 * Gate::arm_escalation_guard() ships unit-tested only (Brain\Monkey-mocked). It
 * is armed unconditionally during Gate::register() at plugin init, then gated at
 * runtime by the opt-in `wp_sudo_guard_escalation` filter (default OFF). These
 * tests drive the REAL `update_user_metadata` and `grant_super_admin` hooks under
 * WordPress + a database, asserting behaviour a mocked test cannot: that the
 * request actually halts (wp_die → WPDieException) BEFORE the capabilities write
 * persists, that a genuinely bound sudo session lets the grant through, and that
 * the multisite super-admin grant leaves the network `site_admins` option intact.
 *
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Sudo_Session;

/**
 * Integration tests for the escalation guard's live hooks.
 */
class EscalationGuardTest extends TestCase {

	/**
	 * The guard hooks are armed process-globally, so the opt-in filters a test
	 * enables must be removed here or their enabled state leaks to later tests.
	 * (The base teardown resets neither arbitrary filters nor defined constants;
	 * this guard deliberately defines no WP_SUDO_ALLOW_ESCALATION constant.)
	 */
	public function tear_down(): void {
		remove_filter( 'wp_sudo_guard_escalation', '__return_true' );
		remove_filter( 'wp_sudo_allow_escalation', '__return_true' );
		parent::tear_down();
	}

	/**
	 * Turn the (default-OFF) guard on for the current test.
	 */
	private function enable_guard(): void {
		add_filter( 'wp_sudo_guard_escalation', '__return_true' );
	}

	/**
	 * Establish a genuinely bound, active sudo session for $user and make them the
	 * current user — mirrors the real activate() binding (login token in the store
	 * + matching logged-in cookie), so is_active() verifies for the right reason.
	 *
	 * @param \WP_User $user The actor.
	 */
	private function grant_bound_session( \WP_User $user ): void {
		wp_set_current_user( $user->ID );

		$expiration                  = time() + DAY_IN_SECONDS;
		$manager                     = \WP_Session_Tokens::get_instance( $user->ID );
		$token                       = $manager->create( $expiration );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user->ID, $expiration, 'logged_in', $token );

		Sudo_Session::activate( $user->ID );
		Sudo_Session::reset_cache();
		$this->assertTrue(
			Sudo_Session::is_active( $user->ID ),
			'Precondition: the actor holds an active bound sudo session.'
		);
	}

	/**
	 * Read a user's persisted roles with a cache-bypassing fresh fetch, so an
	 * in-memory WP_User object mutated by an interrupted set_role() cannot mask
	 * whether the capabilities write actually reached the database.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, string>
	 */
	private function persisted_roles( int $user_id ): array {
		clean_user_cache( $user_id );
		$fresh = get_user_by( 'id', $user_id );

		return $fresh instanceof \WP_User ? array_values( (array) $fresh->roles ) : array();
	}

	/**
	 * With the guard enabled and NO active session, promoting a subscriber to
	 * administrator hard-blocks: the request halts before the capabilities write
	 * persists, and the high-severity wp_sudo_escalation_blocked action fires.
	 *
	 * Users are created BEFORE the guard is enabled — creating the admin actor
	 * with the guard on would itself be a guarded administrator grant.
	 */
	public function test_new_admin_grant_blocked_without_session(): void {
		$actor = $this->make_admin(); // Logged in, but holds no sudo session.
		wp_set_current_user( $actor->ID );
		$target = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		$this->enable_guard();

		$blocked = 0;
		add_action(
			'wp_sudo_escalation_blocked',
			static function () use ( &$blocked ) {
				++$blocked;
			},
			10,
			0
		);

		$halted = false;
		try {
			$target->set_role( 'administrator' );
		} catch ( \WPDieException $e ) {
			$halted = true;
		}

		$this->assertTrue( $halted, 'The grant must halt the request via wp_die().' );
		$this->assertSame( 1, $blocked, 'wp_sudo_escalation_blocked must fire exactly once.' );
		$this->assertNotContains(
			'administrator',
			$this->persisted_roles( $target->ID ),
			'The administrator grant must NOT persist (halt before the capabilities write).'
		);
	}

	/**
	 * With the guard enabled and a genuinely bound, active actor session, the same
	 * promotion is allowed: no halt, no escalation event, and the grant persists.
	 */
	public function test_new_admin_grant_allowed_with_bound_session(): void {
		$actor = $this->make_admin();
		$this->grant_bound_session( $actor );
		$target = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		$this->enable_guard();

		$blocked = 0;
		add_action(
			'wp_sudo_escalation_blocked',
			static function () use ( &$blocked ) {
				++$blocked;
			},
			10,
			0
		);

		$halted = false;
		try {
			$target->set_role( 'administrator' );
		} catch ( \WPDieException $e ) {
			$halted = true;
		}

		$this->assertFalse( $halted, 'A bound, active session must allow the grant.' );
		$this->assertSame( 0, $blocked, 'No escalation event fires when the actor holds a session.' );
		$this->assertContains(
			'administrator',
			$this->persisted_roles( $target->ID ),
			'The grant persists when the actor holds an active sudo session.'
		);
	}

	/**
	 * Default-OFF posture, end to end: with the filter NOT enabled, arming the
	 * hooks must not change behaviour — a normal administrator promotion succeeds
	 * even with no session, so SSO/provisioning flows are unaffected until opt-in.
	 */
	public function test_default_off_allows_admin_grant_without_session(): void {
		$target = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		wp_set_current_user( 0 );

		$blocked = 0;
		add_action(
			'wp_sudo_escalation_blocked',
			static function () use ( &$blocked ) {
				++$blocked;
			},
			10,
			0
		);

		// Guard is OFF (no enable_guard()): this must complete without halting.
		$target->set_role( 'administrator' );

		$this->assertSame( 0, $blocked, 'The default-OFF guard fires no escalation event.' );
		$this->assertContains(
			'administrator',
			$this->persisted_roles( $target->ID ),
			'With the guard off, a normal administrator grant persists.'
		);
	}

	/**
	 * Multisite: granting super admin with the guard enabled and no session halts
	 * before the network `site_admins` option is written. grant_super_admin() does
	 * NOT touch the capabilities meta, so this exercises the separate action guard.
	 */
	public function test_super_admin_grant_blocked_without_session_on_multisite(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'grant_super_admin() is a multisite-only path.' );
		}

		$actor = $this->make_admin();
		wp_set_current_user( $actor->ID );
		$target = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		$this->assertFalse(
			is_super_admin( $target->ID ),
			'Precondition: the target is not already a super admin (an idempotent re-grant would pass).'
		);

		$this->enable_guard();

		$blocked = 0;
		add_action(
			'wp_sudo_escalation_blocked',
			static function () use ( &$blocked ) {
				++$blocked;
			},
			10,
			0
		);

		$halted = false;
		try {
			grant_super_admin( $target->ID );
		} catch ( \WPDieException $e ) {
			$halted = true;
		}

		$this->assertTrue( $halted, 'grant_super_admin() must halt without an active session.' );
		$this->assertSame( 1, $blocked, 'wp_sudo_escalation_blocked must fire once for the super-admin grant.' );

		$super_admins = get_super_admins();
		$this->assertNotContains(
			$target->user_login,
			is_array( $super_admins ) ? $super_admins : array(),
			'The super-admin grant must NOT persist (site_admins network option unchanged).'
		);
	}
}
