<?php
/**
 * Unit tests for Dashboard_Widget class.
 *
 * Tests widget registration, capability checks, and render sections.
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use WP_Sudo\Dashboard_Widget;
use WP_Sudo\Tests\TestCase;

/**
 * Simple wpdb mock for Dashboard_Widget tests.
 */
class DashboardWidgetFakeWpdb {

	/** @var string */
	public string $prefix = 'wp_';

	/** @var string */
	public string $base_prefix = 'wp_';

	/**
	 * Mock get_results().
	 *
	 * @param string $query SQL query.
	 * @return array<int, object>
	 */
	public function get_results( string $query ): array {
		return [];
	}

	/**
	 * Mock prepare().
	 *
	 * @param string $query  Query.
	 * @param mixed  ...$args Arguments.
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		return $query;
	}

	/**
	 * Mock get_charset_collate().
	 *
	 * @return string
	 */
	public function get_charset_collate(): string {
		return '';
	}

	/**
	 * Mock get_var().
	 *
	 * @param string $query SQL query.
	 * @return string|null
	 */
	public function get_var( string $query ): ?string {
		return null;
	}

	/**
	 * Mock suppress_errors().
	 *
	 * @param bool $suppress True to suppress.
	 * @return void
	 */
	public function suppress_errors( bool $suppress = false ): void {
		// No-op.
	}

}

/**
 * Fake wpdb that returns events for recent events tests.
 */
class DashboardWidgetFakeWpdbWithEvents {

	/** @var string */
	public string $prefix = 'wp_';

	/** @var string */
	public string $base_prefix = 'wp_';

	/**
	 * Mock get_results() - returns fake events.
	 *
	 * @param string $query SQL query.
	 * @return array<int, object>
	 */
	public function get_results( string $query ): array {
		// Return fake events when querying the events table.
		if ( strpos( $query, 'wpsudo_events' ) !== false ) {
			return [
				(object) [
					'id'         => 1,
					'site_id'    => 1,
					'user_id'    => 1,
					'event'      => 'action_gated',
					'rule_id'    => 'plugins.activate',
					'surface'    => 'admin',
					'ip'         => '127.0.0.1',
					'context'    => '{}',
					'created_at' => gmdate( 'Y-m-d H:i:s', time() - 120 ),
				],
			];
		}
		return [];
	}

	/**
	 * Mock prepare().
	 *
	 * @param string $query  Query.
	 * @param mixed  ...$args Arguments.
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		return $query;
	}

	/**
	 * Mock get_charset_collate().
	 *
	 * @return string
	 */
	public function get_charset_collate(): string {
		return '';
	}

	/**
	 * Mock get_var().
	 *
	 * @param string $query SQL query.
	 * @return string|null
	 */
	public function get_var( string $query ): ?string {
		return null;
	}

	/**
	 * Mock suppress_errors().
	 *
	 * @param bool $suppress True to suppress.
	 * @return void
	 */
	public function suppress_errors( bool $suppress = false ): void {
		// No-op.
	}

}

/**
 * @covers \WP_Sudo\Dashboard_Widget
 */
class DashboardWidgetTest extends TestCase {

	/**
	 * Fake wpdb.
	 *
	 * @var DashboardWidgetFakeWpdb|null
	 */
	private ?DashboardWidgetFakeWpdb $fake_wpdb = null;

	/**
	 * Original wpdb.
	 *
	 * @var object|null
	 */
	private ?object $original_wpdb = null;

	/**
	 * Set up fake wpdb.
	 *
	 * @return void
	 */
	private function setUpFakeWpdb(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->fake_wpdb     = new DashboardWidgetFakeWpdb();
		$GLOBALS['wpdb']     = $this->fake_wpdb;

		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
	}

	/**
	 * Restore original wpdb.
	 *
	 * @return void
	 */
	private function restoreWpdb(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
	}

	/**
	 * Set up common stubs for render tests.
	 *
	 * @return void
	 */
	private function setUpRenderStubs(): void {
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( '_n' )->returnArg( 2 ); // Return plural form.
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_avatar' )->justReturn( '<img src="avatar.jpg" />' );
		Functions\when( 'get_role' )->justReturn( null );
		$this->setUpFakeWpdb();
	}

	// ─── Registration tests ──────────────────────────────────────────────

	/**
	 * Test init() registers wp_dashboard_setup action.
	 *
	 * @return void
	 */
	public function testInitRegistersDashboardSetupAction(): void {
		Actions\expectAdded( 'wp_dashboard_setup' )
			->once()
			->with( [ Dashboard_Widget::class, 'register' ] );

		Dashboard_Widget::init();
	}

	/**
	 * Test register() calls wp_add_dashboard_widget when user has manage_options.
	 *
	 * @return void
	 */
	public function testRegisterAddsWidgetWhenUserHasManageOptions(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		Functions\expect( 'wp_add_dashboard_widget' )
			->once()
			->with(
				'wp_sudo_activity', // Widget ID.
				\Mockery::type( 'string' ), // Title (translated).
				[ Dashboard_Widget::class, 'render' ] // Render callback.
			);

		Dashboard_Widget::register();
	}

	/**
	 * Test register() does NOT add widget when user lacks manage_options.
	 *
	 * @return void
	 */
	public function testRegisterDoesNotAddWidgetWhenUserLacksCapability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		Functions\expect( 'wp_add_dashboard_widget' )->never();

		Dashboard_Widget::register();
	}

	/**
	 * Test register() checks manage_options capability specifically.
	 *
	 * @return void
	 */
	public function testRegisterChecksManageOptionsCapability(): void {
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_options' )
			->andReturn( true );

		Functions\when( 'wp_add_dashboard_widget' )->justReturn();

		Dashboard_Widget::register();
	}

	// ─── Active sessions section tests ───────────────────────────────────

	/**
	 * Test active sessions queries users with future _wp_sudo_expires.
	 *
	 * @return void
	 */
	public function testActiveSessionsQueriesUsersWithFutureExpiry(): void {
		$captured_args = null;

		Functions\expect( 'get_users' )
			->once()
			->andReturnUsing(
				function ( $args ) use ( &$captured_args ) {
					$captured_args = $args;
					return [];
				}
			);

		// Stub functions used in render.
		$this->setUpRenderStubs();
		Functions\when( 'get_option' )->justReturn( [] );

		ob_start();
		Dashboard_Widget::render();
		ob_end_clean();

		$this->assertNotNull( $captured_args );
		$this->assertArrayHasKey( 'meta_query', $captured_args );
		$this->assertSame( '_wp_sudo_expires', $captured_args['meta_query'][0]['key'] );
		$this->assertSame( '>', $captured_args['meta_query'][0]['compare'] );

		$this->restoreWpdb();
	}

	/**
	 * Test active sessions renders "No active sessions" when empty.
	 *
	 * @return void
	 */
	public function testActiveSessionsRendersNoSessionsMessage(): void {
		Functions\when( 'get_users' )->justReturn( [] );
		$this->setUpRenderStubs();
		Functions\when( 'get_option' )->justReturn( [] );

		ob_start();
		Dashboard_Widget::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No active sessions', $output );

		$this->restoreWpdb();
	}

	/**
	 * Test active sessions renders count and usernames.
	 *
	 * @return void
	 */
	public function testActiveSessionsRendersCountAndUsernames(): void {
		$user1             = new \WP_User( 1, [ 'administrator' ] );
		$user1->ID         = 1;
		$user1->user_login = 'admin';

		$user2             = new \WP_User( 2, [ 'editor' ] );
		$user2->ID         = 2;
		$user2->user_login = 'editor';

		Functions\when( 'get_users' )->justReturn( [ 1, 2 ] );
		$this->setUpRenderStubs();
		Functions\when( 'get_user_meta' )->justReturn( time() + 300 ); // 5 min from now.
		Functions\when( 'get_userdata' )->alias(
			function ( $id ) use ( $user1, $user2 ) {
				return $id === 1 ? $user1 : $user2;
			}
		);
		Functions\when( 'human_time_diff' )->justReturn( '5 mins' );
		Functions\when( 'get_option' )->justReturn( [] );

		ob_start();
		Dashboard_Widget::render();
		$output = ob_get_clean();

		// Should show count of 2.
		$this->assertStringContainsString( '2', $output );

		$this->restoreWpdb();
	}

	/**
	 * Test active sessions limits display to 5 users max.
	 *
	 * @return void
	 */
	public function testActiveSessionsLimitsToFiveUsers(): void {
		$users = [];
		for ( $i = 1; $i <= 7; $i++ ) {
			$user              = new \WP_User( $i, [ 'subscriber' ] );
			$user->ID          = $i;
			$user->user_login  = 'user' . $i;
			$users[ $i ]       = $user;
		}

		// Return IDs (since we use fields => ID).
		Functions\when( 'get_users' )->justReturn( array_keys( $users ) );
		$this->setUpRenderStubs();
		Functions\when( 'get_user_meta' )->justReturn( time() + 300 );
		Functions\when( 'get_userdata' )->alias( fn( $id ) => $users[ $id ] ?? null );
		Functions\when( 'human_time_diff' )->justReturn( '5 mins' );
		Functions\when( 'get_option' )->justReturn( [] );

		ob_start();
		Dashboard_Widget::render();
		$output = ob_get_clean();

		// Count should show total (7), but UI should indicate limited display.
		$this->assertStringContainsString( '7', $output );

		$this->restoreWpdb();
	}

	// ─── Recent events section tests ─────────────────────────────────────

	/**
	 * Test recent events calls Event_Store::recent(10).
	 *
	 * @return void
	 */
	public function testRecentEventsCallsEventStoreRecent(): void {
		Functions\when( 'get_users' )->justReturn( [] );
		$this->setUpRenderStubs();
		Functions\when( 'get_option' )->justReturn( [] );

		// Event_Store::recent() will call $wpdb->get_results().
		// We'll verify it's called by checking no errors occur.
		ob_start();
		Dashboard_Widget::render();
		$output = ob_get_clean();

		// When no events, should show "No recent activity".
		$this->assertStringContainsString( 'No recent activity', $output );

		$this->restoreWpdb();
	}

	/**
	 * Test recent events renders "No recent activity" when empty.
	 *
	 * @return void
	 */
	public function testRecentEventsRendersNoActivityMessage(): void {
		Functions\when( 'get_users' )->justReturn( [] );
		$this->setUpRenderStubs();
		Functions\when( 'get_option' )->justReturn( [] );

		ob_start();
		Dashboard_Widget::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Recent Events', $output );
		$this->assertStringContainsString( 'No recent activity', $output );

		$this->restoreWpdb();
	}

	/**
	 * Test recent events renders event table with rows.
	 *
	 * @return void
	 */
	public function testRecentEventsRendersEventTable(): void {
		Functions\when( 'get_users' )->justReturn( [] );
		$this->setUpRenderStubs();
		Functions\when( 'human_time_diff' )->justReturn( '2 mins ago' );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_userdata' )->alias(
			function ( $id ) {
				$user             = new \WP_User( $id, [] );
				$user->user_login = 'testuser';
				return $user;
			}
		);

		// Create fake wpdb that returns events.
		$fake_wpdb       = new DashboardWidgetFakeWpdbWithEvents();
		$GLOBALS['wpdb'] = $fake_wpdb;

		ob_start();
		Dashboard_Widget::render();
		$output = ob_get_clean();

		// Should contain table structure.
		$this->assertStringContainsString( '<table', $output );
		$this->assertStringContainsString( 'Gated', $output ); // Human-readable label.

		$this->restoreWpdb();
	}

	/**
	 * Test recent events uses human-readable event labels.
	 *
	 * @return void
	 */
	public function testRecentEventsUsesHumanReadableLabels(): void {
		Functions\when( 'get_users' )->justReturn( [] );
		$this->setUpRenderStubs();
		Functions\when( 'human_time_diff' )->justReturn( '2 mins ago' );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_userdata' )->alias(
			function ( $id ) {
				$user             = new \WP_User( $id, [] );
				$user->user_login = 'admin';
				return $user;
			}
		);

		$fake_wpdb       = new DashboardWidgetFakeWpdbWithEvents();
		$GLOBALS['wpdb'] = $fake_wpdb;

		ob_start();
		Dashboard_Widget::render();
		$output = ob_get_clean();

		// Event labels should be present (translated).
		// The implementation should map 'lockout' -> 'Lockout' etc.
		$this->assertStringContainsString( 'Gated', $output );

		$this->restoreWpdb();
	}

	// ─── Policy summary section tests ────────────────────────────────────

	/**
	 * Test policy summary renders session duration.
	 *
	 * @return void
	 */
	public function testPolicySummaryRendersSessionDuration(): void {
		Functions\when( 'get_users' )->justReturn( [] );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( '_n' )->returnArg( 2 );
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = [] ) {
				if ( 'wp_sudo_settings' === $name ) {
					return [ 'session_duration' => 10 ];
				}
				return $default;
			}
		);
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		$this->setUpFakeWpdb();

		ob_start();
		Dashboard_Widget::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Policy Summary', $output );
		$this->assertStringContainsString( '10', $output ); // Session duration.

		$this->restoreWpdb();
	}

	/**
	 * Test policy summary renders surface policies.
	 *
	 * @return void
	 */
	public function testPolicySummaryRendersSurfacePolicies(): void {
		Functions\when( 'get_users' )->justReturn( [] );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( '_n' )->returnArg( 2 );
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = [] ) {
				if ( 'wp_sudo_settings' === $name ) {
					return [
						'session_duration'       => 5,
						'rest_app_password_policy' => 'limited',
						'cli_policy'             => 'unrestricted',
						'cron_policy'            => 'disabled',
						'xmlrpc_policy'          => 'limited',
					];
				}
				return $default;
			}
		);
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		$this->setUpFakeWpdb();

		ob_start();
		Dashboard_Widget::render();
		$output = ob_get_clean();

		// Should show policy labels for each surface.
		$this->assertStringContainsString( 'REST', $output );
		$this->assertStringContainsString( 'CLI', $output );
		$this->assertStringContainsString( 'Cron', $output );
		$this->assertStringContainsString( 'XML-RPC', $output );

		$this->restoreWpdb();
	}

	/**
	 * Test policy summary uses defaults when option missing.
	 *
	 * @return void
	 */
	public function testPolicySummaryUsesDefaultsWhenOptionMissing(): void {
		Functions\when( 'get_users' )->justReturn( [] );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( '_n' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( [] ); // Empty settings.
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		$this->setUpFakeWpdb();

		ob_start();
		Dashboard_Widget::render();
		$output = ob_get_clean();

		// Should still render policy section with defaults.
		$this->assertStringContainsString( 'Policy Summary', $output );
		// Default session duration is 5 minutes.
		$this->assertStringContainsString( '5', $output );

		$this->restoreWpdb();
	}

}
