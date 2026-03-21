<?php
/**
 * Tests for bootstrap helpers.
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use Brain\Monkey\Functions;
use WP_Sudo\Bootstrap;
use WP_Sudo\Tests\TestCase;

/**
 * @covers \WP_Sudo\Bootstrap
 */
class BootstrapTest extends TestCase {

	public function test_register_plugin_realpath_uses_public_plugin_path(): void {
		$plugin_file = '/tmp/wp-sudo/wp-sudo.php';

		Functions\when( 'get_option' )->justReturn( array( 'wp-sudo/wp-sudo.php' ) );
		Functions\expect( 'wp_register_plugin_realpath' )
			->once()
			->with( '/tmp/fake-wordpress/wp-content/plugins/wp-sudo/wp-sudo.php' );

		Bootstrap::register_plugin_realpath( $plugin_file );
	}

	public function test_plugin_basename_prefers_active_plugin_entry_for_symlinked_install(): void {
		$plugin_file = '/tmp/wp-sudo/wp-sudo.php';

		Functions\when( 'get_option' )->justReturn( array( 'custom-public-dir/wp-sudo.php' ) );

		Functions\expect( 'plugin_basename' )->never();

		$this->assertSame( 'custom-public-dir/wp-sudo.php', Bootstrap::plugin_basename( $plugin_file ) );
	}

	public function test_plugin_basename_falls_back_to_wordpress_when_plugin_not_active(): void {
		$plugin_file = '/tmp/wp-sudo/wp-sudo.php';

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\expect( 'plugin_basename' )
			->once()
			->with( $plugin_file )
			->andReturn( 'wp-sudo/wp-sudo.php' );

		$this->assertSame( 'wp-sudo/wp-sudo.php', Bootstrap::plugin_basename( $plugin_file ) );
	}

	public function test_plugin_dir_url_uses_public_plugin_basename(): void {
		$plugin_file = '/tmp/wp-sudo/wp-sudo.php';

		Functions\when( 'get_option' )->justReturn( array( 'custom-public-dir/wp-sudo.php' ) );
		Functions\expect( 'plugins_url' )
			->once()
			->withNoArgs()
			->andReturn( 'https://example.com/wp-content/plugins' );
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'plugins_url', 'https://example.com/wp-content/plugins/custom-public-dir', '', 'custom-public-dir/wp-sudo.php' )
			->andReturn( 'https://example.com/wp-content/plugins/custom-public-dir' );

		$this->assertSame(
			'https://example.com/wp-content/plugins/custom-public-dir/',
			Bootstrap::plugin_dir_url( $plugin_file )
		);
	}

	public function test_plugin_dir_url_preserves_wordpress_plugin_url_filters(): void {
		$plugin_file = '/tmp/wp-sudo/wp-sudo.php';

		Functions\when( 'get_option' )->justReturn( array( 'custom-public-dir/wp-sudo.php' ) );
		Functions\expect( 'plugins_url' )
			->once()
			->withNoArgs()
			->andReturn( 'https://example.com/wp-content/plugins' );
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'plugins_url', 'https://example.com/wp-content/plugins/custom-public-dir', '', 'custom-public-dir/wp-sudo.php' )
			->andReturn( 'https://cdn.example.com/plugins/custom-public-dir' );

		$this->assertSame(
			'https://cdn.example.com/plugins/custom-public-dir/',
			Bootstrap::plugin_dir_url( $plugin_file )
		);
	}

	public function test_plugin_basename_reads_network_active_plugins_on_multisite(): void {
		$plugin_file = '/tmp/wp-sudo/wp-sudo.php';

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\expect( 'get_site_option' )
			->once()
			->with( 'active_sitewide_plugins', array() )
			->andReturn(
				array(
					'network-public-dir/wp-sudo.php' => 1740000000,
				)
			);

		$this->assertSame( 'network-public-dir/wp-sudo.php', Bootstrap::plugin_basename( $plugin_file ) );
	}
}
