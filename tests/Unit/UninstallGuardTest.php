<?php
/**
 * Tests for Uninstall_Guard.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Tests\TestCase;
use WP_Sudo\Uninstall_Guard;
use Brain\Monkey\Functions;

/**
 * @covers \WP_Sudo\Uninstall_Guard
 */
class UninstallGuardTest extends TestCase {

	/**
	 * Browser/admin uninstalls require the delete_plugins capability.
	 */
	public function test_browser_uninstall_requires_delete_plugins_capability(): void {
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'delete_plugins' )
			->andReturn( false );

		$this->assertFalse( Uninstall_Guard::is_authorized( false ) );
	}

	/**
	 * Browser/admin uninstalls pass when the user can delete plugins.
	 */
	public function test_browser_uninstall_allows_delete_plugins_users(): void {
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'delete_plugins' )
			->andReturn( true );

		$this->assertTrue( Uninstall_Guard::is_authorized( false ) );
	}

	/**
	 * WP-CLI uninstall keeps using the CLI/core authorization path.
	 */
	public function test_wp_cli_uninstall_is_authorized_without_browser_capability_check(): void {
		Functions\expect( 'current_user_can' )->never();

		$this->assertTrue( Uninstall_Guard::is_authorized( true ) );
	}
}
