<?php
/**
 * Integration tests for the public helper API.
 *
 * @covers \WP_Sudo\Public_API
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Sudo_Session;

class PublicApiTest extends TestCase {

	public function test_wp_sudo_check_treats_grace_window_as_active(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		Sudo_Session::activate( $user->ID );
		update_user_meta( $user->ID, Sudo_Session::META_KEY, time() - 30 );
		Sudo_Session::reset_cache();

		$this->assertFalse( Sudo_Session::is_active( $user->ID ), 'Session should be expired before helper check.' );
		Sudo_Session::reset_cache();
		$this->assertTrue( Sudo_Session::is_within_grace( $user->ID ), 'Session should still be within grace window.' );
		Sudo_Session::reset_cache();

		$this->assertTrue( \wp_sudo_check( $user->ID ) );
	}

	public function test_wp_sudo_require_returns_false_and_emits_audit_hook_when_redirect_disabled(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		$captured = array();
		$listener = static function ( int $user_id, string $rule_id, string $surface ) use ( &$captured ): void {
			$captured = array(
				'user_id' => $user_id,
				'rule_id' => $rule_id,
				'surface' => $surface,
			);
		};

		add_action( 'wp_sudo_action_gated', $listener, 10, 3 );

		try {
			$this->assertFalse(
				\wp_sudo_require(
					array(
						'rule_id'  => 'manual.require_no_redirect',
						'redirect' => false,
					)
				)
			);
		} finally {
			remove_action( 'wp_sudo_action_gated', $listener, 10 );
		}

		$this->assertSame(
			array(
				'user_id' => $user->ID,
				'rule_id' => 'manual.require_no_redirect',
				'surface' => 'public_api',
			),
			$captured
		);
	}

	public function test_wp_sudo_require_returns_true_when_session_is_active(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		Sudo_Session::activate( $user->ID );
		Sudo_Session::reset_cache();

		$this->assertTrue(
			\wp_sudo_require(
				array(
					'rule_id'  => 'manual.require_active',
					'redirect' => false,
				)
			)
		);
	}
}
