<?php
/**
 * Tests for WP_Sudo\Upgrader (v2).
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Admin;
use WP_Sudo\Upgrader;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers \WP_Sudo\Upgrader
 */
class UpgraderTest extends TestCase {

	/**
	 * Original global wpdb instance, if any.
	 *
	 * @var object|null
	 */
	private ?object $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_next_scheduled' )->justReturn( true );
		Functions\when( 'wp_schedule_event' )->justReturn( true );

		// Preserve any existing wpdb.
		$this->original_wpdb = isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;

		// Minimal wpdb mock for Event_Store::create_table() called by upgrade_2_15_0().
		$GLOBALS['wpdb'] = new class {
			public string $prefix      = 'wp_';
			public string $base_prefix = 'wp_';

			public function get_charset_collate(): string {
				return '';
			}

			public function get_results( string $query ): array {
				return [];
			}
		};
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}

		parent::tearDown();
	}

	// ── Framework ────────────────────────────────────────────────────

	public function test_skips_when_version_is_current(): void {
		Functions\when( 'get_option' )->justReturn( WP_SUDO_VERSION );
		Functions\expect( 'update_option' )->never();

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}

	public function test_skips_when_version_is_newer(): void {
		Functions\when( 'get_option' )->justReturn( '99.0.0' );
		Functions\expect( 'update_option' )->never();

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}

	public function test_stamps_version_on_older_install(): void {
		Functions\when( 'get_option' )->justReturn( '0.0.0' );
		Functions\when( 'remove_role' )->justReturn( null );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'dbDelta' )->justReturn( array() );

		Functions\expect( 'update_option' )
			->once()
			->with( Upgrader::VERSION_OPTION, WP_SUDO_VERSION );

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}

	// ── 2.0.0 migration ─────────────────────────────────────────────

	public function test_200_removes_site_manager_role(): void {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( Upgrader::VERSION_OPTION === $key ) {
				return '1.2.1';
			}
			if ( Admin::OPTION_KEY === $key ) {
				return array( 'session_duration' => 15 );
			}
			return $default;
		} );

		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'dbDelta' )->justReturn( array() );

		Functions\expect( 'remove_role' )
			->once()
			->with( 'site_manager' );

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}

	public function test_200_strips_allowed_roles_from_settings(): void {
		$old_settings = array(
			'session_duration' => 10,
			'allowed_roles'    => array( 'editor', 'site_manager' ),
		);

		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( $old_settings ) {
			if ( Upgrader::VERSION_OPTION === $key ) {
				return '1.2.1';
			}
			if ( Admin::OPTION_KEY === $key ) {
				return $old_settings;
			}
			return $default;
		} );

		Functions\when( 'remove_role' )->justReturn( null );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'dbDelta' )->justReturn( array() );

		// Should update settings without allowed_roles.
		Functions\expect( 'update_option' )
			->with(
				Admin::OPTION_KEY,
				\Mockery::on( function ( $settings ) {
					return isset( $settings['session_duration'] )
						&& ! isset( $settings['allowed_roles'] );
				} )
			)
			->once();

		// Also stamps the version.
		Functions\expect( 'update_option' )
			->with( Upgrader::VERSION_OPTION, WP_SUDO_VERSION )
			->once();

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}

	public function test_200_deletes_role_version_option(): void {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( Upgrader::VERSION_OPTION === $key ) {
				return '1.2.1';
			}
			if ( Admin::OPTION_KEY === $key ) {
				return array( 'session_duration' => 15 );
			}
			return $default;
		} );

		Functions\when( 'remove_role' )->justReturn( null );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'dbDelta' )->justReturn( array() );

		Functions\expect( 'delete_option' )
			->once()
			->with( 'wp_sudo_role_version' );

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}

	public function test_200_skips_settings_update_when_no_allowed_roles(): void {
		$settings = array( 'session_duration' => 15 );

		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( $settings ) {
			if ( Upgrader::VERSION_OPTION === $key ) {
				return '1.2.1';
			}
			if ( Admin::OPTION_KEY === $key ) {
				return $settings;
			}
			return $default;
		} );

		Functions\when( 'remove_role' )->justReturn( null );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'dbDelta' )->justReturn( array() );

		// Should only update the version stamp, not the settings.
		Functions\expect( 'update_option' )
			->once()
			->with( Upgrader::VERSION_OPTION, WP_SUDO_VERSION );

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}

	// ── Constants ────────────────────────────────────────────────────

	public function test_version_option_constant(): void {
		$this->assertSame( 'wp_sudo_db_version', Upgrader::VERSION_OPTION );
	}

	public function test_upgrades_include_2150_events_table_migration(): void {
		$reflection = new \ReflectionClass( Upgrader::class );
		$upgrades   = $reflection->getConstant( 'UPGRADES' );

		$this->assertIsArray( $upgrades );
		$this->assertArrayHasKey( '2.15.0', $upgrades );
		$this->assertSame( 'upgrade_2_15_0', $upgrades['2.15.0'] );
	}

	public function test_2150_creates_events_table(): void {
		$original_wpdb  = isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
		$GLOBALS['wpdb'] = new class() {
			public string $prefix      = 'wp_';
			public string $base_prefix = 'wp_';

			public function get_charset_collate(): string {
				return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
			}
		};

		Functions\expect( 'dbDelta' )
			->once()
			->with( \Mockery::type( 'string' ) )
			->andReturn( array() );

		$checked_hook   = null;
		$scheduled_args = null;

		Functions\when( 'wp_next_scheduled' )->alias(
			function ( $hook ) use ( &$checked_hook ) {
				$checked_hook = $hook;
				return false;
			}
		);

		Functions\when( 'wp_schedule_event' )->alias(
			function ( $timestamp, $recurrence, $hook ) use ( &$scheduled_args ) {
				$scheduled_args = array( $timestamp, $recurrence, $hook );
				return true;
			}
		);

		$upgrader = new Upgrader();

		// setAccessible() is required for PHP 8.0; deprecated in PHP 8.5+.
		// Suppress deprecation warning for cross-version compatibility.
		$method = new \ReflectionMethod( Upgrader::class, 'upgrade_2_15_0' );
		@$method->setAccessible( true );
		$method->invoke( $upgrader );

		$this->assertSame( 'wp_sudo_prune_events', $checked_hook );
		$this->assertIsArray( $scheduled_args );
		$this->assertIsInt( $scheduled_args[0] );
		$this->assertSame( 'daily', $scheduled_args[1] );
		$this->assertSame( 'wp_sudo_prune_events', $scheduled_args[2] );

		if ( null !== $original_wpdb ) {
			$GLOBALS['wpdb'] = $original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
	}

	// ── Multisite: site options ──────────────────────────────────────

	/**
	 * Test maybe_upgrade uses site options on multisite.
	 */
	public function test_uses_site_option_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		Functions\expect( 'get_site_option' )
			->once()
			->with( Upgrader::VERSION_OPTION, '0.0.0' )
			->andReturn( WP_SUDO_VERSION );

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();

		// Should not call update_site_option since version is current.
		$this->assertTrue( true );
	}

	/**
	 * Test maybe_upgrade stamps version with site option on multisite.
	 */
	public function test_stamps_version_with_site_option_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		Functions\expect( 'get_site_option' )
			->once()
			->with( Upgrader::VERSION_OPTION, '0.0.0' )
			->andReturn( '0.0.0' );

		Functions\when( 'remove_role' )->justReturn( null );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'dbDelta' )->justReturn( array() );

		Functions\expect( 'update_site_option' )
			->once()
			->with( Upgrader::VERSION_OPTION, WP_SUDO_VERSION );

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}

	// ── 2.1.0 migration ─────────────────────────────────────────────

	public function test_210_strips_unfiltered_html_from_editor(): void {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( Upgrader::VERSION_OPTION === $key ) {
				return '2.0.0';
			}
			return $default;
		} );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'dbDelta' )->justReturn( array() );

		$role = \Mockery::mock( 'WP_Role' );
		$role->shouldReceive( 'remove_cap' )
			->once()
			->with( 'unfiltered_html' );

		Functions\expect( 'get_role' )
			->once()
			->with( 'editor' )
			->andReturn( $role );

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}

	// ── 2.2.0 migration ─────────────────────────────────────────────

	public function test_220_migrates_block_to_limited(): void {
		$old_settings = array(
			'session_duration'         => 10,
			'cli_policy'               => 'block',
			'cron_policy'              => 'block',
			'xmlrpc_policy'            => 'block',
			'rest_app_password_policy' => 'block',
		);

		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( $old_settings ) {
			if ( Upgrader::VERSION_OPTION === $key ) {
				return '2.1.0';
			}
			if ( Admin::OPTION_KEY === $key ) {
				return $old_settings;
			}
			return $default;
		} );

		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'dbDelta' )->justReturn( array() );

		Functions\expect( 'update_option' )
			->with(
				Admin::OPTION_KEY,
				\Mockery::on( function ( $settings ) {
					return 'limited' === $settings['cli_policy']
						&& 'limited' === $settings['cron_policy']
						&& 'limited' === $settings['xmlrpc_policy']
						&& 'limited' === $settings['rest_app_password_policy']
						&& 10 === $settings['session_duration'];
				} )
			)
			->once();

		Functions\expect( 'update_option' )
			->with( Upgrader::VERSION_OPTION, WP_SUDO_VERSION )
			->once();

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}

	public function test_220_migrates_allow_to_unrestricted(): void {
		$old_settings = array(
			'session_duration'         => 15,
			'cli_policy'               => 'allow',
			'cron_policy'              => 'allow',
			'xmlrpc_policy'            => 'allow',
			'rest_app_password_policy' => 'allow',
		);

		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( $old_settings ) {
			if ( Upgrader::VERSION_OPTION === $key ) {
				return '2.1.0';
			}
			if ( Admin::OPTION_KEY === $key ) {
				return $old_settings;
			}
			return $default;
		} );

		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'dbDelta' )->justReturn( array() );

		Functions\expect( 'update_option' )
			->with(
				Admin::OPTION_KEY,
				\Mockery::on( function ( $settings ) {
					return 'unrestricted' === $settings['cli_policy']
						&& 'unrestricted' === $settings['cron_policy']
						&& 'unrestricted' === $settings['xmlrpc_policy']
						&& 'unrestricted' === $settings['rest_app_password_policy'];
				} )
			)
			->once();

		Functions\expect( 'update_option' )
			->with( Upgrader::VERSION_OPTION, WP_SUDO_VERSION )
			->once();

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}

	public function test_220_preserves_already_valid_values(): void {
		$settings = array(
			'session_duration'         => 10,
			'cli_policy'               => 'limited',
			'cron_policy'              => 'disabled',
			'xmlrpc_policy'            => 'unrestricted',
			'rest_app_password_policy' => 'limited',
		);

		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( $settings ) {
			if ( Upgrader::VERSION_OPTION === $key ) {
				return '2.1.0';
			}
			if ( Admin::OPTION_KEY === $key ) {
				return $settings;
			}
			return $default;
		} );

		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'dbDelta' )->justReturn( array() );

		// No changes needed — should only stamp the version.
		Functions\expect( 'update_option' )
			->once()
			->with( Upgrader::VERSION_OPTION, WP_SUDO_VERSION );

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}

	public function test_220_skips_update_when_no_changes_needed(): void {
		$settings = array(
			'session_duration'         => 15,
			'cli_policy'               => 'disabled',
			'cron_policy'              => 'limited',
			'xmlrpc_policy'            => 'limited',
			'rest_app_password_policy' => 'unrestricted',
		);

		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( $settings ) {
			if ( Upgrader::VERSION_OPTION === $key ) {
				return '2.1.0';
			}
			if ( Admin::OPTION_KEY === $key ) {
				return $settings;
			}
			return $default;
		} );

		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'dbDelta' )->justReturn( array() );

		// Should only update the version stamp, not settings.
		Functions\expect( 'update_option' )
			->once()
			->with( Upgrader::VERSION_OPTION, WP_SUDO_VERSION );

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}

	public function test_220_multisite_uses_site_option(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		$old_settings = array(
			'session_duration'         => 15,
			'cli_policy'               => 'block',
			'cron_policy'              => 'allow',
			'xmlrpc_policy'            => 'block',
			'rest_app_password_policy' => 'allow',
		);

		Functions\when( 'get_site_option' )->alias( function ( $key, $default = false ) use ( $old_settings ) {
			if ( Upgrader::VERSION_OPTION === $key ) {
				return '2.1.0';
			}
			if ( Admin::OPTION_KEY === $key ) {
				return $old_settings;
			}
			return $default;
		} );

		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'dbDelta' )->justReturn( array() );

		$updated_settings = null;
		$version_stamped  = false;

		Functions\when( 'update_site_option' )->alias( function ( $key, $value ) use ( &$updated_settings, &$version_stamped ) {
			if ( Admin::OPTION_KEY === $key ) {
				$updated_settings = $value;
			}
			if ( Upgrader::VERSION_OPTION === $key ) {
				$version_stamped = true;
			}
			return true;
		} );

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();

		$this->assertNotNull( $updated_settings, 'Settings should have been updated via update_site_option.' );
		$this->assertSame( 'limited', $updated_settings['cli_policy'] );
		$this->assertSame( 'unrestricted', $updated_settings['cron_policy'] );
		$this->assertSame( 'limited', $updated_settings['xmlrpc_policy'] );
		$this->assertSame( 'unrestricted', $updated_settings['rest_app_password_policy'] );
		$this->assertTrue( $version_stamped, 'Version should have been stamped via update_site_option.' );
	}

	public function test_210_skips_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		Functions\expect( 'get_site_option' )
			->once()
			->with( Upgrader::VERSION_OPTION, '0.0.0' )
			->andReturn( '2.0.0' );

		Functions\when( 'update_site_option' )->justReturn( true );
		Functions\when( 'dbDelta' )->justReturn( array() );

		// On multisite, strip_editor_unfiltered_html is a no-op,
		// so get_role should never be called.
		Functions\expect( 'get_role' )->never();

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();
	}
}
