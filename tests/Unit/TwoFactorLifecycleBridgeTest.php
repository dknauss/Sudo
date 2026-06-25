<?php
/**
 * Tests for the Two Factor lifecycle bridge (v1: REST-route gating).
 *
 * @package WP_Sudo
 */

namespace WP_Sudo\Tests\Unit;

use Brain\Monkey\Functions;
use WP_Sudo\Tests\TestCase;

/**
 * @coversNothing Procedural bridge file.
 *
 * Note: `tests/bootstrap.php` unconditionally stubs `Two_Factor_Core`, so the
 * bridge's `class_exists( 'Two_Factor_Core' )` runtime guard always sees the
 * plugin as present here. The "Two Factor absent → bridge inert" branch is a
 * standard one-line guard (mirroring the other bridges) that cannot be
 * exercised in the unit suite without undefining a class; it is left to
 * real-environment verification.
 */
class TwoFactorLifecycleBridgeTest extends TestCase {

	/**
	 * Capture the wp_sudo_gated_actions filter the bridge registers, then
	 * require the bridge file under Brain\Monkey-aliased WordPress functions.
	 *
	 * @return callable The registered wp_sudo_gated_actions callback.
	 */
	private function capture_gated_actions_filter(): callable {
		$captured = null;

		Functions\when( '__' )->returnArg();
		Functions\when( 'add_filter' )->alias(
			static function ( string $hook, callable $callback ) use ( &$captured ): bool {
				if ( 'wp_sudo_gated_actions' === $hook ) {
					$captured = $callback;
				}
				return true;
			}
		);

		require __DIR__ . '/../../bridges/wp-sudo-two-factor-lifecycle-bridge.php';

		$this->assertIsCallable( $captured, 'Bridge must register a wp_sudo_gated_actions filter.' );

		return $captured;
	}

	/**
	 * Find a rule by id in the filtered rule list.
	 *
	 * @param array<int, array<string, mixed>> $rules Rules.
	 * @param string                           $id    Rule id.
	 * @return array<string, mixed>
	 */
	private function rule_by_id( array $rules, string $id ): array {
		foreach ( $rules as $rule ) {
			if ( ( $rule['id'] ?? '' ) === $id ) {
				return $rule;
			}
		}

		$this->fail( "Rule '{$id}' was not registered." );
	}

	/**
	 * The bridge registers exactly the two factor-management REST rules, each
	 * well-formed for the Action Registry (non-empty id/label/category, null
	 * admin/ajax, a valid rest matcher).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_registers_two_well_formed_rules_when_two_factor_present(): void {
		\Brain\Monkey\setUp();

		$filter = $this->capture_gated_actions_filter();
		$rules  = $filter( array() );

		$ids = array_column( $rules, 'id' );
		$this->assertContains( 'two_factor.backup_codes_generate', $ids );
		$this->assertContains( 'two_factor.totp_manage', $ids );
		$this->assertCount( 2, $rules );

		foreach ( $rules as $rule ) {
			$this->assertNotEmpty( $rule['id'] );
			$this->assertNotEmpty( $rule['label'] );
			$this->assertNotEmpty( $rule['category'] );
			$this->assertNull( $rule['admin'] );
			$this->assertNull( $rule['ajax'] );
			$this->assertIsArray( $rule['rest'] );
			$this->assertIsString( $rule['rest']['route'] );
			$this->assertIsArray( $rule['rest']['methods'] );
			$this->assertNotFalse(
				@preg_match( $rule['rest']['route'], '' ),
				"Route pattern for {$rule['id']} must be a valid regex."
			);
		}

		\Brain\Monkey\tearDown();
	}

	/**
	 * The TOTP rule matches POST and DELETE on the exact /totp route only, and
	 * the backup-codes rule matches POST on generate-backup-codes only.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_routes_and_methods_match_the_verified_two_factor_endpoints(): void {
		\Brain\Monkey\setUp();

		$rules = ( $this->capture_gated_actions_filter() )( array() );

		$totp = $this->rule_by_id( $rules, 'two_factor.totp_manage' );
		$this->assertSame( 1, preg_match( $totp['rest']['route'], '/two-factor/1.0/totp' ) );
		$this->assertSame( 0, preg_match( $totp['rest']['route'], '/two-factor/1.0/totp/extra' ) );
		$this->assertSame( array( 'POST', 'DELETE' ), $totp['rest']['methods'] );

		$backup = $this->rule_by_id( $rules, 'two_factor.backup_codes_generate' );
		$this->assertSame( 1, preg_match( $backup['rest']['route'], '/two-factor/1.0/generate-backup-codes' ) );
		$this->assertSame( array( 'POST' ), $backup['rest']['methods'] );
		// The backup-codes rule must NOT match the totp route or unrelated routes.
		$this->assertSame( 0, preg_match( $backup['rest']['route'], '/two-factor/1.0/totp' ) );
		$this->assertSame( 0, preg_match( $backup['rest']['route'], '/wp/v2/users/1' ) );

		\Brain\Monkey\tearDown();
	}
}
