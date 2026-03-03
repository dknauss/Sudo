<?php
/**
 * Tests for Request_Stash.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Request_Stash;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers \WP_Sudo\Request_Stash
 */
class RequestStashTest extends TestCase {

	/**
	 * Instance under test.
	 *
	 * @var Request_Stash
	 */
	private Request_Stash $stash;

	protected function setUp(): void {
		parent::setUp();
		$this->stash = new Request_Stash();
	}

	/**
	 * Test save() stores data and returns a key.
	 */
	public function test_save_returns_key(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/plugins.php?action=activate&plugin=hello.php';

		Functions\expect( 'wp_generate_password' )
			->once()
			->with( 16, false )
			->andReturn( 'abc123def456ghij' );

		Functions\expect( 'is_ssl' )
			->once()
			->andReturn( true );

		Functions\when( 'esc_url_raw' )->returnArg();

		Functions\expect( 'set_transient' )
			->once()
			->with(
				Request_Stash::TRANSIENT_PREFIX . 'abc123def456ghij',
				\Mockery::type( 'array' ),
				Request_Stash::TTL
			)
			->andReturn( true );

		$key = $this->stash->save( 1, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		$this->assertSame( 'abc123def456ghij', $key );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
	}

	/**
	 * Test save() still returns the key when transient storage fails.
	 */
	public function test_save_returns_key_when_set_transient_fails(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/plugins.php?action=activate&plugin=hello.php';

		Functions\expect( 'wp_generate_password' )
			->once()
			->with( 16, false )
			->andReturn( 'failedtransient01' );

		Functions\expect( 'is_ssl' )
			->once()
			->andReturn( false );

		Functions\when( 'esc_url_raw' )->returnArg();

		Functions\expect( 'set_transient' )
			->once()
			->andReturn( false );

		$key = $this->stash->save( 1, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		$this->assertSame( 'failedtransient01', $key );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
	}

	/**
	 * Test save() serializes the full request data.
	 */
	public function test_save_stores_correct_data(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/plugins.php';
		$_GET['action']            = 'activate';
		$_POST['plugin']           = 'hello.php';

		Functions\expect( 'wp_generate_password' )
			->once()
			->andReturn( 'testkey123456789' );

		Functions\expect( 'is_ssl' )
			->once()
			->andReturn( false );

		Functions\when( 'esc_url_raw' )->returnArg();

		$stored_data = null;

		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				function ( $name, $data, $ttl ) use ( &$stored_data ) {
					$stored_data = $data;
					return true;
				}
			);

		$this->stash->save( 42, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		$this->assertSame( 42, $stored_data['user_id'] );
		$this->assertSame( 'plugin.activate', $stored_data['rule_id'] );
		$this->assertSame( 'Activate plugin', $stored_data['label'] );
		$this->assertSame( 'POST', $stored_data['method'] );
		$this->assertSame( 'http://example.com/wp-admin/plugins.php', $stored_data['url'] );
		$this->assertArrayHasKey( 'action', $stored_data['get'] );
		$this->assertArrayHasKey( 'plugin', $stored_data['post'] );
		$this->assertIsInt( $stored_data['created'] );

		unset(
			$_SERVER['REQUEST_METHOD'],
			$_SERVER['HTTP_HOST'],
			$_SERVER['REQUEST_URI'],
			$_GET['action'],
			$_POST['plugin']
		);
	}

	/**
	 * Test get() returns stashed data for the correct user.
	 */
	public function test_get_returns_data_for_owner(): void {
		$data = array(
			'user_id' => 5,
			'rule_id' => 'plugin.delete',
			'label'   => 'Delete plugin',
			'method'  => 'POST',
			'url'     => 'https://example.com/wp-admin/plugins.php',
			'get'     => array(),
			'post'    => array( 'checked' => array( 'hello.php' ) ),
			'created' => time(),
		);

		Functions\expect( 'get_transient' )
			->once()
			->with( Request_Stash::TRANSIENT_PREFIX . 'mykey123' )
			->andReturn( $data );

		$result = $this->stash->get( 'mykey123', 5 );

		$this->assertIsArray( $result );
		$this->assertSame( 5, $result['user_id'] );
		$this->assertSame( 'plugin.delete', $result['rule_id'] );
	}

	/**
	 * Test get() returns null for a different user.
	 */
	public function test_get_returns_null_for_wrong_user(): void {
		$data = array(
			'user_id' => 5,
			'rule_id' => 'plugin.delete',
			'method'  => 'POST',
			'url'     => 'https://example.com/wp-admin/plugins.php',
			'get'     => array(),
			'post'    => array(),
			'created' => time(),
		);

		Functions\expect( 'get_transient' )
			->once()
			->with( Request_Stash::TRANSIENT_PREFIX . 'somekey1234' )
			->andReturn( $data );

		$result = $this->stash->get( 'somekey1234', 99 );

		$this->assertNull( $result );
	}

	/**
	 * Test get() returns null for corrupted non-array transient payloads.
	 */
	public function test_get_returns_null_for_non_array_transient(): void {
		Functions\expect( 'get_transient' )
			->once()
			->with( Request_Stash::TRANSIENT_PREFIX . 'corruptedkey123' )
			->andReturn( 'corrupted' );

		$result = $this->stash->get( 'corruptedkey123', 1 );

		$this->assertNull( $result );
	}

	/**
	 * Test get() returns null when transient is missing (expired).
	 */
	public function test_get_returns_null_when_expired(): void {
		Functions\expect( 'get_transient' )
			->once()
			->with( Request_Stash::TRANSIENT_PREFIX . 'expiredkey12345' )
			->andReturn( false );

		$result = $this->stash->get( 'expiredkey12345', 1 );

		$this->assertNull( $result );
	}

	/**
	 * Test get() returns null for empty key.
	 */
	public function test_get_returns_null_for_empty_key(): void {
		$result = $this->stash->get( '', 1 );

		$this->assertNull( $result );
	}

	/**
	 * Test delete() calls delete_transient.
	 */
	public function test_delete_removes_transient(): void {
		Functions\expect( 'delete_transient' )
			->once()
			->with( Request_Stash::TRANSIENT_PREFIX . 'delkey123456789' );

		$this->stash->delete( 'delkey123456789' );
	}

	/**
	 * Test delete() with empty key does not call delete_transient.
	 */
	public function test_delete_skips_empty_key(): void {
		Functions\expect( 'delete_transient' )->never();

		$this->stash->delete( '' );
	}

	/**
	 * Test exists() returns true for a valid stash.
	 */
	public function test_exists_returns_true_for_valid_stash(): void {
		$data = array(
			'user_id' => 10,
			'rule_id' => 'theme.switch',
			'method'  => 'GET',
			'url'     => 'https://example.com/wp-admin/themes.php',
			'get'     => array(),
			'post'    => array(),
			'created' => time(),
		);

		Functions\expect( 'get_transient' )
			->once()
			->with( Request_Stash::TRANSIENT_PREFIX . 'existskey123456' )
			->andReturn( $data );

		$this->assertTrue( $this->stash->exists( 'existskey123456', 10 ) );
	}

	/**
	 * Test exists() returns false when stash is missing.
	 */
	public function test_exists_returns_false_when_missing(): void {
		Functions\expect( 'get_transient' )
			->once()
			->with( Request_Stash::TRANSIENT_PREFIX . 'nokey1234567890' )
			->andReturn( false );

		$this->assertFalse( $this->stash->exists( 'nokey1234567890', 10 ) );
	}

	/**
	 * Test that the transient prefix constant is defined.
	 */
	public function test_transient_prefix_is_defined(): void {
		$this->assertSame( '_wp_sudo_stash_', Request_Stash::TRANSIENT_PREFIX );
	}

	/**
	 * Test TTL constant is 300 seconds.
	 */
	public function test_ttl_is_five_minutes(): void {
		$this->assertSame( 300, Request_Stash::TTL );
	}

	/**
	 * Test save() handles missing SERVER vars gracefully.
	 */
	public function test_save_handles_missing_server_vars(): void {
		// Ensure the vars are not set.
		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );

		Functions\expect( 'wp_generate_password' )
			->once()
			->andReturn( 'fallbackkey12345' );

		Functions\expect( 'is_ssl' )
			->once()
			->andReturn( false );

		Functions\when( 'esc_url_raw' )->returnArg();

		$stored_data = null;

		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				function ( $name, $data, $ttl ) use ( &$stored_data ) {
					$stored_data = $data;
					return true;
				}
			);

		$this->stash->save( 1, array( 'id' => 'test.rule', 'label' => 'Test' ) );

		$this->assertSame( 'GET', $stored_data['method'] );
		$this->assertStringContainsString( 'localhost', $stored_data['url'] );
		$this->assertStringContainsString( '/wp-admin/', $stored_data['url'] );
	}

	/**
	 * Test save() preserves percent-encoded characters in REQUEST_URI.
	 *
	 * Plugin slugs like "my-plugin/plugin.php" are URL-encoded as
	 * "my-plugin%2Fplugin.php" in the query string. sanitize_text_field()
	 * strips percent-encoded characters entirely, corrupting the URL.
	 * The stash must use esc_url_raw() instead to preserve them.
	 *
	 * @see https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-includes/formatting.php
	 */
	public function test_save_preserves_percent_encoded_url(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/plugins.php?action=activate&plugin=my-plugin%2Fplugin.php&_wpnonce=abc123';

		Functions\expect( 'wp_generate_password' )
			->once()
			->andReturn( 'pct_encoded_key01' );

		Functions\expect( 'is_ssl' )
			->once()
			->andReturn( false );

		Functions\when( 'esc_url_raw' )->returnArg();

		$stored_data = null;

		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				function ( $name, $data, $ttl ) use ( &$stored_data ) {
					$stored_data = $data;
					return true;
				}
			);

		$this->stash->save( 1, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		// The %2F must be preserved — sanitize_text_field() would strip it.
		$this->assertStringContainsString( 'my-plugin%2Fplugin.php', $stored_data['url'] );
		$this->assertStringContainsString( '_wpnonce=abc123', $stored_data['url'] );

		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
	}

	// -----------------------------------------------------------------
	// Multisite: site transients
	// -----------------------------------------------------------------

	/**
	 * Test save uses set_site_transient on multisite.
	 */
	public function test_save_uses_site_transient_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_URI']    = '/wp-admin/plugins.php';

		Functions\expect( 'wp_generate_password' )
			->once()
			->andReturn( 'multisite_key_01' );

		Functions\expect( 'is_ssl' )->once()->andReturn( true );
		Functions\when( 'esc_url_raw' )->returnArg();

		Functions\expect( 'set_site_transient' )
			->once()
			->with(
				Request_Stash::TRANSIENT_PREFIX . 'multisite_key_01',
				\Mockery::type( 'array' ),
				Request_Stash::TTL
			)
			->andReturn( true );

		$key = $this->stash->save( 1, array( 'id' => 'plugin.activate', 'label' => 'Activate' ) );

		$this->assertSame( 'multisite_key_01', $key );
	}

	/**
	 * Test get uses get_site_transient on multisite.
	 */
	public function test_get_uses_site_transient_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		$data = array( 'user_id' => 1, 'rule_id' => 'plugin.activate' );

		Functions\expect( 'get_site_transient' )
			->once()
			->with( Request_Stash::TRANSIENT_PREFIX . 'testkey123456789' )
			->andReturn( $data );

		$result = $this->stash->get( 'testkey123456789', 1 );

		$this->assertSame( $data, $result );
	}

	/**
	 * Test delete uses delete_site_transient on multisite.
	 */
	public function test_delete_uses_site_transient_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		Functions\expect( 'delete_site_transient' )
			->once()
			->with( Request_Stash::TRANSIENT_PREFIX . 'deletekey1234567' )
			->andReturn( true );

		$this->stash->delete( 'deletekey1234567' );

		// If we reach here without errors, the test passes — Mockery verifies the expectation.
		$this->assertTrue( true );
	}
}
