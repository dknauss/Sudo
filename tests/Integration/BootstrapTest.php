<?php
/**
 * Integration tests for bootstrap helpers.
 *
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Bootstrap;

/**
 * @covers \WP_Sudo\Bootstrap
 */
class BootstrapTest extends TestCase {

	public function test_plugin_basename_uses_active_plugin_entry_as_public_path(): void {
		$public_basename = is_multisite()
			? 'network-public-dir/wp-sudo.php'
			: 'custom-public-dir/wp-sudo.php';

		$this->arrange_public_plugin_basename( $public_basename );

		$this->assertSame(
			$public_basename,
			Bootstrap::plugin_basename( '/tmp/symlinked-target/wp-sudo.php' )
		);
	}

	public function test_plugin_dir_url_uses_recovered_public_plugin_path(): void {
		$public_basename = is_multisite()
			? 'network-public-dir/wp-sudo.php'
			: 'custom-public-dir/wp-sudo.php';

		$this->arrange_public_plugin_basename( $public_basename );

		$expected_url = content_url( 'plugins/' . dirname( $public_basename ) ) . '/';

		$this->assertSame(
			$expected_url,
			Bootstrap::plugin_dir_url( '/tmp/symlinked-target/wp-sudo.php' )
		);
	}

	public function test_plugin_dir_url_honors_plugins_url_filter(): void {
		$public_basename = is_multisite()
			? 'network-public-dir/wp-sudo.php'
			: 'custom-public-dir/wp-sudo.php';

		$this->arrange_public_plugin_basename( $public_basename );

		$callback = static function ( string $url, string $path, string $plugin ) use ( $public_basename ): string {
			if ( '' === $path && $public_basename === $plugin ) {
				return 'https://cdn.example.com/custom-plugin-url';
			}

			return $url;
		};

		add_filter( 'plugins_url', $callback, 10, 3 );

		try {
			$this->assertSame(
				'https://cdn.example.com/custom-plugin-url/',
				Bootstrap::plugin_dir_url( '/tmp/symlinked-target/wp-sudo.php' )
			);
		} finally {
			remove_filter( 'plugins_url', $callback, 10 );
		}
	}

	/**
	 * Arrange a synthetic public plugin basename in the same storage WordPress uses.
	 *
	 * @param string $public_basename Relative plugin basename as stored by WordPress.
	 * @return void
	 */
	private function arrange_public_plugin_basename( string $public_basename ): void {
		update_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			update_site_option(
				'active_sitewide_plugins',
				array(
					$public_basename => time(),
				)
			);
			return;
		}

		update_option( 'active_plugins', array( $public_basename ) );
	}
}
