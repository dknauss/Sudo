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
use WP_Sudo\Admin;
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

	/** @var string|null */
	public ?string $last_get_results_query = null;

	/**
	 * Mock get_results().
	 *
	 * @param string $query SQL query.
	 * @return array<int, object>
	 */
	public function get_results( string $query ): array {
		$this->last_get_results_query = $query;
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

	/** @var string|null */
	public ?string $last_get_results_query = null;

	/**
	 * Mock get_results() - returns fake events.
	 *
	 * @param string $query SQL query.
	 * @return array<int, object>
	 */
	public function get_results( string $query ): array {
		$this->last_get_results_query = $query;
		// Return fake events when querying the events table.
		if ( strpos( $query, 'wpsudo_events' ) !== false ) {
			return [
				(object) [
					'id'         => 1,
					'user_id'    => 1,
					'event'      => 'action_gated',
					'rule_id'    => 'options.update',
					'surface'    => 'admin',
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
 * Fake wpdb that returns a critical event for badge rendering tests.
 */
class DashboardWidgetFakeWpdbWithCriticalEvent {

	/** @var string */
	public string $prefix = 'wp_';

	/** @var string */
	public string $base_prefix = 'wp_';

	/** @var string|null */
	public ?string $last_get_results_query = null;

	/**
	 * Mock get_results() - returns one critical event row.
	 *
	 * @param string $query SQL query.
	 * @return array<int, object>
	 */
	public function get_results( string $query ): array {
		$this->last_get_results_query = $query;
		if ( strpos( $query, 'wpsudo_events' ) !== false ) {
			return [
				(object) [
					'id'         => 2,
					'user_id'    => 1,
					'event'      => 'action_gated',
					'rule_id'    => 'user.delete',
					'surface'    => 'admin',
					'created_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
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
 * Fake wpdb that returns a replayed event with no surface.
 */
class DashboardWidgetFakeWpdbWithReplayedEvent {

	/** @var string */
	public string $prefix = 'wp_';

	/** @var string */
	public string $base_prefix = 'wp_';

	/** @var string|null */
	public ?string $last_get_results_query = null;

	/**
	 * Mock get_results() - returns one replayed event row.
	 *
	 * @param string $query SQL query.
	 * @return array<int, object>
	 */
	public function get_results( string $query ): array {
		$this->last_get_results_query = $query;
		if ( strpos( $query, 'wpsudo_events' ) !== false ) {
			return [
				(object) [
					'id'         => 3,
					'user_id'    => 1,
					'event'      => 'action_replayed',
					'rule_id'    => 'options.update',
					'surface'    => '',
					'created_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
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
		// Default: transient cache always misses so existing tests keep
		// exercising the full WP_User_Query path. Individual tests override
		// via Functions\when('get_transient')->alias(...) when needed.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
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
		Functions\when( 'esc_attr__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_kses' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( '_n' )->returnArg( 2 ); // Return plural form.
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_avatar' )->justReturn( '<img src="avatar.jpg" />' );
		Functions\when( 'get_role' )->justReturn( null );
		$this->setUpFakeWpdb(); // Includes transient stubs (cache miss by default).
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
				[ Dashboard_Widget::class, 'render' ], // Render callback.
				null,   // Control callback.
				null,   // Callback args.
				'side', // Context.
				'high'  // Priority.
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
		// Stub functions used in render.
		$this->setUpRenderStubs();
		Functions\when( 'get_option' )->justReturn( [] );
		\WP_User_Query::$mock_total   = 0;
		\WP_User_Query::$mock_results = [];

		ob_start();
		Dashboard_Widget::render();
		ob_end_clean();

		$this->assertArrayHasKey( 'meta_query', \WP_User_Query::$last_query_vars );
		$this->assertSame( '_wp_sudo_expires', \WP_User_Query::$last_query_vars['meta_query'][0]['key'] );
		$this->assertSame( '>', \WP_User_Query::$last_query_vars['meta_query'][0]['compare'] );
		$this->assertSame( 'all', \WP_User_Query::$last_query_vars['fields'] );
		$this->assertSame( 6, \WP_User_Query::$last_query_vars['number'] );
		$this->assertTrue( \WP_User_Query::$last_query_vars['count_total'] );

		$this->restoreWpdb();
	}

	/**
	 * Test active sessions renders "No active sessions" when empty.
	 *
	 * @return void
	 */
	public function testActiveSessionsRendersNoSessionsMessage(): void {
		$this->setUpRenderStubs();
		Functions\when( 'get_option' )->justReturn( [] );
		\WP_User_Query::$mock_total   = 0;
		\WP_User_Query::$mock_results = [];

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

		$this->setUpRenderStubs();
		\WP_User_Query::$mock_total   = 2;
		\WP_User_Query::$mock_results = [ $user1, $user2 ];
		Functions\when( 'get_user_meta' )->justReturn( time() + 300 ); // 5 min from now.
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
	 * Test active sessions caps display and shows a "View all" indicator link.
	 *
	 * @return void
	 */
	public function testActiveSessionsShowsViewAllIndicatorWhenCountExceedsDisplayCap(): void {
		$users = [];
		for ( $i = 1; $i <= 6; $i++ ) {
			$user              = new \WP_User( $i, [ 'subscriber' ] );
			$user->ID          = $i;
			$user->user_login  = 'user' . $i;
			$users[ $i ]       = $user;
		}

		$this->setUpRenderStubs();
		\WP_User_Query::$mock_total   = 12;
		\WP_User_Query::$mock_results = array_values( $users );
		Functions\when( 'get_user_meta' )->justReturn( time() + 300 );
		Functions\when( 'human_time_diff' )->justReturn( '5 mins' );
		Functions\when( 'get_option' )->justReturn( [] );

		ob_start();
		Dashboard_Widget::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '12', $output );
		$this->assertStringContainsString( 'View all sudo-active users (12)', $output );
		$this->assertStringContainsString( 'users.php?sudo_active=1', $output );

		$this->restoreWpdb();
	}

	/**
	 * Test active sessions query is skipped when a valid transient is present.
	 *
	 * Regression guard against running WP_User_Query on every dashboard page
	 * load when the list was recently fetched.
	 *
	 * @return void
	 */
	public function testActiveSessionsSkipsQueryWhenTransientHit(): void {
		$this->setUpRenderStubs();
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_user_meta' )->justReturn( time() + 300 );
		Functions\when( 'human_time_diff' )->justReturn( '5 mins' );

		// Cached payload: the helper must consume this verbatim and skip the query.
		$user              = new \WP_User( 42, [ 'administrator' ] );
		$user->ID          = 42;
		$user->user_login  = 'cached_user';
		Functions\when( 'get_transient' )->justReturn(
			array(
				'count' => 3,
				'users' => array( $user ),
			)
		);

		// Canary: if render runs the query, last_query_vars is overwritten to
		// the real query shape. A cache hit must leave the canary intact.
		\WP_User_Query::$last_query_vars = array( 'canary' => true );
		\WP_User_Query::$mock_total      = 99; // Must NOT appear in output.
		\WP_User_Query::$mock_results    = array();

		ob_start();
		Dashboard_Widget::render();
		$output = ob_get_clean();

		$this->assertSame(
			array( 'canary' => true ),
			\WP_User_Query::$last_query_vars,
			'WP_User_Query must not be built on a transient cache hit'
		);
		$this->assertStringContainsString( '3 active sessions', $output );
		$this->assertStringNotContainsString( '99 active sessions', $output );

		$this->restoreWpdb();
	}

	/**
	 * Test active sessions persists the query result in a transient on a miss.
	 *
	 * @return void
	 */
	public function testActiveSessionsStoresQueryResultInTransient(): void {
		$user             = new \WP_User( 7, [ 'editor' ] );
		$user->ID         = 7;
		$user->user_login = 'writer';

		$this->setUpRenderStubs();
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_user_meta' )->justReturn( time() + 300 );
		Functions\when( 'human_time_diff' )->justReturn( '5 mins' );
		\WP_User_Query::$mock_total   = 1;
		\WP_User_Query::$mock_results = array( $user );

		$captured_key  = null;
		$captured_data = null;
		$captured_ttl  = null;

		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value, int $ttl = 0 ) use ( &$captured_key, &$captured_data, &$captured_ttl ): bool {
				$captured_key  = $key;
				$captured_data = $value;
				$captured_ttl  = $ttl;
				return true;
			}
		);

		ob_start();
		Dashboard_Widget::render();
		ob_end_clean();

		$this->assertIsString( $captured_key );
		$this->assertStringContainsString( 'wp_sudo_active_sessions', (string) $captured_key );
		$this->assertIsArray( $captured_data );
		$this->assertSame( 1, $captured_data['count'] );
		$this->assertCount( 1, $captured_data['users'] );
		$this->assertGreaterThan( 0, $captured_ttl, 'Transient TTL must be positive' );
		$this->assertLessThanOrEqual( 120, $captured_ttl, 'Transient TTL must be short (<= 2 minutes)' );

		$this->restoreWpdb();
	}

	/**
	 * Test active-sessions transient key is site-scoped for multisite safety.
	 *
	 * @return void
	 */
	public function testActiveSessionsTransientKeyIsSiteScoped(): void {
		$this->setUpRenderStubs();
		Functions\when( 'get_option' )->justReturn( [] );
		\WP_User_Query::$mock_total   = 0;
		\WP_User_Query::$mock_results = array();

		$captured_keys = array();
		Functions\when( 'set_transient' )->alias(
			function ( string $key ) use ( &$captured_keys ): bool {
				$captured_keys[] = $key;
				return true;
			}
		);

		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		ob_start();
		Dashboard_Widget::render();
		ob_end_clean();

		Functions\when( 'get_current_blog_id' )->justReturn( 42 );
		ob_start();
		Dashboard_Widget::render();
		ob_end_clean();

		$this->assertCount( 2, $captured_keys );
		$this->assertNotSame(
			$captured_keys[0],
			$captured_keys[1],
			'Different blog_ids must yield different transient keys'
		);

		$this->restoreWpdb();
	}

	// ─── Recent events section tests ─────────────────────────────────────

	/**
	 * Test recent events use the dashboard-specific lean Event_Store query.
	 *
	 * @return void
	 */
	public function testRecentEventsUseDashboardSelectColumns(): void {
		$this->setUpRenderStubs();
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'human_time_diff' )->justReturn( '2 mins ago' );
		Functions\when( 'get_users' )->alias(
			function ( array $args ): array {
				if ( isset( $args['include'] ) ) {
					$user             = (object) array();
					$user->ID         = 1;
					$user->user_login = 'testuser';
					return array( $user );
				}

				return array();
			}
		);
		\WP_User_Query::$mock_total   = 0;
		\WP_User_Query::$mock_results = [];

		$fake_wpdb       = new DashboardWidgetFakeWpdbWithEvents();
		$GLOBALS['wpdb'] = $fake_wpdb;

		ob_start();
		Dashboard_Widget::render();
		ob_end_clean();

		$this->assertIsString( $fake_wpdb->last_get_results_query );
		$this->assertStringContainsString( 'SELECT id, created_at, user_id, event, rule_id, surface FROM wp_wpsudo_events', $fake_wpdb->last_get_results_query );
		$this->assertStringNotContainsString( 'context', $fake_wpdb->last_get_results_query );
		$this->assertStringNotContainsString( 'ip', $fake_wpdb->last_get_results_query );

		$this->restoreWpdb();
	}

	/**
	 * Test recent events renders "No recent activity" when empty.
	 *
	 * @return void
	 */
	public function testRecentEventsRendersNoActivityMessage(): void {
		$this->setUpRenderStubs();
		Functions\when( 'get_option' )->justReturn( [] );
		\WP_User_Query::$mock_total   = 0;
		\WP_User_Query::$mock_results = [];

		ob_start();
		Dashboard_Widget::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Recent Events', $output );
		$this->assertStringContainsString( 'No recent activity', $output );

		$this->restoreWpdb();
	}

	/**
	 * Test recent events show policy notice and disable Passed filter when disabled by code.
	 *
	 * @return void
	 */
	public function testRecentEventsShowPassedLoggingOverrideNoticeWhenDisabled(): void {
		$this->setUpRenderStubs();
		Functions\when( 'apply_filters' )->alias(
			function ( string $hook, $value ) {
				if ( Admin::PASSED_EVENT_LOGGING_FILTER === $hook ) {
					return false;
				}

				return $value;
			}
		);
		Functions\when( 'get_option' )->justReturn( [] );
		\WP_User_Query::$mock_total   = 0;
		\WP_User_Query::$mock_results = [];

		ob_start();
		Dashboard_Widget::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Passed events are currently hidden by a code-level policy override.', $output );
		$this->assertStringContainsString( '<option value="action_passed" disabled>', $output );

		$this->restoreWpdb();
	}

	/**
	 * Test recent events renders event table with rows.
	 *
	 * @return void
	 */
	public function testRecentEventsRendersEventTable(): void {
		$this->setUpRenderStubs();
		\WP_User_Query::$mock_total   = 0;
		\WP_User_Query::$mock_results = [];
		Functions\when( 'human_time_diff' )->justReturn( '2 mins ago' );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_users' )->alias(
			function ( array $args ): array {
				if ( isset( $args['include'] ) ) {
					$user             = (object) array();
					$user->ID         = 1;
					$user->user_login = 'testuser';
					return array( $user );
				}

				return array();
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
		$this->assertStringContainsString( 'title="', $output );
		$this->assertStringContainsString( 'UTC', $output );
		$this->assertStringContainsString( 'Update options', $output );
		$this->assertStringContainsString( '<code class="wp-sudo-action-id" title="Technical action ID">options.update</code>', $output );

		$this->restoreWpdb();
	}

	/**
	 * Test event usernames link to user-edit pages when editable.
	 *
	 * @return void
	 */
	public function testRecentEventsUsernamesLinkToEditableProfiles(): void {
		$this->setUpRenderStubs();
		\WP_User_Query::$mock_total   = 0;
		\WP_User_Query::$mock_results = [];
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_users' )->alias(
			function ( array $args ): array {
				if ( isset( $args['include'] ) ) {
					$user             = (object) array();
					$user->ID         = 1;
					$user->user_login = 'testuser';
					return array( $user );
				}

				return array();
			}
		);

		$GLOBALS['wpdb'] = new DashboardWidgetFakeWpdbWithEvents();

		ob_start();
		Dashboard_Widget::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'class="wp-sudo-event-user-link"', $output );
		$this->assertStringContainsString( 'href="user-edit.php?user_id=1"', $output );
		$this->assertStringContainsString( '>testuser</a>', $output );

		$this->restoreWpdb();
	}

	/**
	 * Test event usernames render as plain text when edit access is denied.
	 *
	 * @return void
	 */
	public function testRecentEventsUsernamesNotLinkedWhenEditAccessDenied(): void {
		$this->setUpRenderStubs();
		\WP_User_Query::$mock_total   = 0;
		\WP_User_Query::$mock_results = [];
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_users' )->alias(
			function ( array $args ): array {
				if ( isset( $args['include'] ) ) {
					$user             = (object) array();
					$user->ID         = 1;
					$user->user_login = 'testuser';
					return array( $user );
				}

				return array();
			}
		);
		Functions\when( 'current_user_can' )->alias(
			function ( string $capability ): bool {
				if ( 'edit_user' === $capability ) {
					return false;
				}

				return true;
			}
		);

		$GLOBALS['wpdb'] = new DashboardWidgetFakeWpdbWithEvents();

		ob_start();
		Dashboard_Widget::render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'class="wp-sudo-event-user-link"', $output );
		$this->assertMatchesRegularExpression( '/<td[^>]*>testuser<\\/td>/', $output );

		$this->restoreWpdb();
	}

	/**
	 * Test missing event users are rendered as an explicit deleted-user label.
	 *
	 * @return void
	 */
	public function testRecentEventsRenderDeletedUserLabelWhenUserMissing(): void {
		$this->setUpRenderStubs();
		\WP_User_Query::$mock_total   = 0;
		\WP_User_Query::$mock_results = [];
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_users' )->justReturn( [] );

		$GLOBALS['wpdb'] = new DashboardWidgetFakeWpdbWithEvents();

		ob_start();
		Dashboard_Widget::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'class="wp-sudo-event-user-deleted"', $output );
		$this->assertStringContainsString( 'Deleted user (#1)', $output );
		$this->assertStringNotContainsString( 'class="wp-sudo-event-user-link"', $output );

		$this->restoreWpdb();
	}

	/**
	 * Test recent events table renders sortable column toggles.
	 *
	 * @return void
	 */
	public function testRecentEventsRendersSortableHeaderToggles(): void {
		$this->setUpRenderStubs();
		\WP_User_Query::$mock_total   = 0;
		\WP_User_Query::$mock_results = [];
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_users' )->alias(
			function ( array $args ): array {
				if ( isset( $args['include'] ) ) {
					$user             = (object) array();
					$user->ID         = 1;
					$user->user_login = 'testuser';
					return array( $user );
				}

				return array();
			}
		);

		$GLOBALS['wpdb'] = new DashboardWidgetFakeWpdbWithEvents();

		ob_start();
		Dashboard_Widget::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'data-sort-key="time"', $output );
		$this->assertStringContainsString( 'data-sort-key="user"', $output );
		$this->assertStringContainsString( 'data-sort-key="event"', $output );
		$this->assertStringContainsString( 'data-sort-key="action"', $output );
		$this->assertStringContainsString( 'data-sort-key="surface"', $output );
		$this->assertStringContainsString( 'aria-sort="descending"', $output );

		$this->restoreWpdb();
	}

	/**
	 * Test critical actions render a visible badge in the action column.
	 *
	 * @return void
	 */
	public function testRecentEventsRenderCriticalBadgeForCriticalAction(): void {
		$this->setUpRenderStubs();
		\WP_User_Query::$mock_total   = 0;
		\WP_User_Query::$mock_results = [];
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_users' )->alias(
			function ( array $args ): array {
				if ( isset( $args['include'] ) ) {
					$user             = (object) array();
					$user->ID         = 1;
					$user->user_login = 'admin';
					return array( $user );
				}

				return array();
			}
		);

		$GLOBALS['wpdb'] = new DashboardWidgetFakeWpdbWithCriticalEvent();

		ob_start();
		Dashboard_Widget::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Delete user', $output );
		$this->assertStringContainsString( 'wp-sudo-critical-badge', $output );
		$this->assertStringContainsString( 'Critical', $output );
		$this->assertStringContainsString( '<code class="wp-sudo-action-id" title="Technical action ID">user.delete</code>', $output );

		$this->restoreWpdb();
	}

	/**
	 * Test recent events uses human-readable event labels.
	 *
	 * @return void
	 */
	public function testRecentEventsUsesHumanReadableLabels(): void {
		$this->setUpRenderStubs();
		\WP_User_Query::$mock_total   = 0;
		\WP_User_Query::$mock_results = [];
		Functions\when( 'human_time_diff' )->justReturn( '2 mins ago' );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_users' )->alias(
			function ( array $args ): array {
				if ( isset( $args['include'] ) ) {
					$user             = (object) array();
					$user->ID         = 1;
					$user->user_login = 'admin';
					return array( $user );
				}

				return array();
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

	/**
	 * Test replayed events render "reauth" surface code when source surface is empty.
	 *
	 * @return void
	 */
	public function testReplayedEventsRenderReauthSurfaceCode(): void {
		$this->setUpRenderStubs();
		\WP_User_Query::$mock_total   = 0;
		\WP_User_Query::$mock_results = [];
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_users' )->alias(
			function ( array $args ): array {
				if ( isset( $args['include'] ) ) {
					$user             = (object) array();
					$user->ID         = 1;
					$user->user_login = 'admin';
					return array( $user );
				}

				return array();
			}
		);

		$GLOBALS['wpdb'] = new DashboardWidgetFakeWpdbWithReplayedEvent();

		ob_start();
		Dashboard_Widget::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<code class="wp-sudo-surface-code">reauth</code>', $output );

		$this->restoreWpdb();
	}

	// ─── Policy summary section tests ────────────────────────────────────

	/**
	 * Test policy summary renders session duration.
	 *
	 * @return void
	 */
	public function testPolicySummaryRendersSessionDuration(): void {
		\WP_User_Query::$mock_total   = 0;
		\WP_User_Query::$mock_results = [];
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_attr__' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'wp_kses' )->returnArg( 1 );
		Functions\when( '_n' )->returnArg( 2 );
		Functions\when( 'get_users' )->justReturn( [] );
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
		\WP_User_Query::$mock_total   = 0;
		\WP_User_Query::$mock_results = [];
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_attr__' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'wp_kses' )->returnArg( 1 );
		Functions\when( '_n' )->returnArg( 2 );
		Functions\when( 'get_users' )->justReturn( [] );
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
	 * Test policy summary displays mode badge for active named preset.
	 *
	 * @return void
	 */
	public function testPolicySummaryDisplaysActivePresetLabel(): void {
		\WP_User_Query::$mock_total   = 0;
		\WP_User_Query::$mock_results = [];
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_attr__' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'wp_kses' )->returnArg( 1 );
		Functions\when( '_n' )->returnArg( 2 );
		Functions\when( 'get_users' )->justReturn( [] );
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = [] ) {
				if ( 'wp_sudo_settings' === $name ) {
					return [
						'policy_preset'            => 'incident_lockdown',
						'rest_app_password_policy' => 'disabled',
						'cli_policy'               => 'disabled',
						'cron_policy'              => 'limited',
						'xmlrpc_policy'            => 'disabled',
						'wpgraphql_policy'         => 'disabled',
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

		$this->assertStringContainsString( 'Mode:', $output );
		$this->assertStringContainsString( 'Incident Lockdown', $output );
		$this->assertStringContainsString( 'wp-sudo-policy-mode', $output );
		$this->assertStringContainsString( 'is-lockdown', $output );
		$this->assertStringContainsString( '#policy_preset_selection', $output );

		$this->restoreWpdb();
	}

	/**
	 * Test policy summary links to custom settings when mode is custom.
	 *
	 * @return void
	 */
	public function testPolicySummaryLinksToCustomSettingsWhenModeIsCustom(): void {
		\WP_User_Query::$mock_total   = 0;
		\WP_User_Query::$mock_results = [];
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_attr__' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'wp_kses' )->returnArg( 1 );
		Functions\when( '_n' )->returnArg( 2 );
		Functions\when( 'get_users' )->justReturn( [] );
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = [] ) {
				if ( 'wp_sudo_settings' === $name ) {
					return [
						'policy_preset'            => 'custom',
						'rest_app_password_policy' => 'limited',
						'cli_policy'               => 'disabled',
						'cron_policy'              => 'limited',
						'xmlrpc_policy'            => 'disabled',
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

		$this->assertStringContainsString( 'Mode:', $output );
		$this->assertStringContainsString( 'Custom', $output );
		$this->assertStringContainsString( 'is-custom', $output );
		$this->assertStringContainsString( '#rest_app_password_policy', $output );

		$this->restoreWpdb();
	}

	/**
	 * Test policy summary uses defaults when option missing.
	 *
	 * @return void
	 */
	public function testPolicySummaryUsesDefaultsWhenOptionMissing(): void {
		\WP_User_Query::$mock_total   = 0;
		\WP_User_Query::$mock_results = [];
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_attr__' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'wp_kses' )->returnArg( 1 );
		Functions\when( '_n' )->returnArg( 2 );
		Functions\when( 'get_users' )->justReturn( [] );
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
