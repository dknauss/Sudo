<?php
/**
 * Tests for the shared User_Identity display helper.
 *
 * @package WP_Sudo
 */

declare(strict_types=1);

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\User_Identity;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers \WP_Sudo\User_Identity
 */
final class UserIdentityTest extends TestCase {

	public function test_primary_name_prefers_full_real_name(): void {
		$user               = new \WP_User( 5, array( 'administrator' ) );
		$user->user_login   = 'msantos';
		$user->display_name = 'msantos';
		$user->first_name   = 'Maria';
		$user->last_name    = 'Santos';

		$this->assertSame( 'Maria Santos', User_Identity::primary_name( $user ) );
	}

	public function test_primary_name_falls_back_to_display_name_then_login(): void {
		$display               = new \WP_User( 6, array() );
		$display->user_login   = 'bobby';
		$display->display_name = 'Bob The Builder';
		$this->assertSame( 'Bob The Builder', User_Identity::primary_name( $display ) );

		// No real name and display_name === login → login is the only identity.
		$bare             = new \WP_User( 7, array() );
		$bare->user_login = 'plainuser';
		$bare->display_name = 'plainuser';
		$this->assertSame( 'plainuser', User_Identity::primary_name( $bare ) );
	}

	public function test_primary_name_tolerates_missing_name_meta(): void {
		// The WP_User stub declares no first_name/last_name; reading them must not warn.
		$user             = new \WP_User( 8, array() );
		$user->user_login = 'nometa';
		$user->display_name = 'No Meta';
		$this->assertSame( 'No Meta', User_Identity::primary_name( $user ) );
	}

	public function test_primary_name_falls_back_to_user_id_when_name_and_login_empty(): void {
		// A degenerate user with no name, display, or login must still yield a
		// non-empty identity so the primary line never renders blank.
		$user               = new \WP_User( 42, array() );
		$user->user_login   = '';
		$user->display_name = '';
		$this->assertSame( 'User 42', User_Identity::primary_name( $user ) );
	}

	public function test_role_labels_returns_translated_names_one_per_role(): void {
		Functions\when( 'wp_roles' )->alias(
			static function () {
				return new class() {
					public function get_names(): array {
						return array(
							'administrator' => 'Administrator',
							'editor'        => 'Editor',
						);
					}
				};
			}
		);
		Functions\when( 'translate_user_role' )->returnArg();

		$user = new \WP_User( 9, array( 'administrator', 'editor' ) );
		$this->assertSame( array( 'Administrator', 'Editor' ), User_Identity::role_labels( $user ) );
	}

	public function test_role_labels_guards_unknown_slug_and_empty_roles(): void {
		Functions\when( 'wp_roles' )->alias(
			static function () {
				return new class() {
					public function get_names(): array {
						return array( 'administrator' => 'Administrator' );
					}
				};
			}
		);
		Functions\when( 'translate_user_role' )->returnArg();

		// Unknown/orphaned slug falls back to the raw slug, no undefined-index warning.
		$orphan = new \WP_User( 10, array( 'legacy_role' ) );
		$this->assertSame( array( 'legacy_role' ), User_Identity::role_labels( $orphan ) );

		// No roles → empty array (e.g. a multisite super admin with no stored site role).
		$none = new \WP_User( 11, array() );
		$this->assertSame( array(), User_Identity::role_labels( $none ) );
	}
}
