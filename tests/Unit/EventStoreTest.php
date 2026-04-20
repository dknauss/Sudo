<?php
/**
 * Tests for WP_Sudo\Event_Store.
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use Brain\Monkey\Functions;
use WP_Sudo\Event_Store;
use WP_Sudo\Tests\TestCase;

/**
 * @covers \WP_Sudo\Event_Store
 */
class EventStoreTest extends TestCase {

	/**
	 * Original global wpdb instance, if any.
	 *
	 * @var object|null
	 */
	private ?object $original_wpdb = null;

	/**
	 * Fake wpdb used for unit tests.
	 *
	 * @var FakeWpdb
	 */
	private FakeWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->original_wpdb = isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
		$this->wpdb          = new FakeWpdb();
		$GLOBALS['wpdb']     = $this->wpdb;

		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}

		parent::tearDown();
	}

	public function test_create_table_calls_dbdelta_with_expected_schema(): void {
		Functions\expect( 'dbDelta' )
			->once()
			->with(
				\Mockery::on(
					static function ( string $sql ): bool {
						return str_contains( $sql, 'CREATE TABLE wp_wpsudo_events' )
							&& str_contains( $sql, 'site_id bigint(20) unsigned NOT NULL' )
							&& str_contains( $sql, 'user_id bigint(20) unsigned NOT NULL' )
							&& str_contains( $sql, 'event varchar(50) NOT NULL' )
							&& str_contains( $sql, 'rule_id varchar(100) NOT NULL' )
							&& str_contains( $sql, 'surface varchar(30) NOT NULL' )
							&& str_contains( $sql, 'ip varchar(45) NOT NULL' )
							&& str_contains( $sql, 'context longtext NOT NULL' )
							&& str_contains( $sql, 'created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP' )
							&& str_contains( $sql, 'PRIMARY KEY  (id)' )
							&& str_contains( $sql, 'KEY event_created_at (event, created_at)' )
							&& str_contains( $sql, 'KEY site_created_at (site_id, created_at)' )
							&& str_contains( $sql, 'KEY user_created_at (user_id, created_at)' );
					}
				)
			)
			->andReturn( array() );

		Event_Store::create_table();
	}

	public function test_maybe_create_table_on_sqlite_skips_existence_probe_and_creates_table(): void {
		$this->wpdb->is_sqlite = true;

		Event_Store::maybe_create_table();

		$this->assertCount( 0, $this->wpdb->get_var_calls );
		$this->assertNotEmpty( $this->wpdb->query_calls );
		$this->assertStringContainsString( 'CREATE TABLE IF NOT EXISTS wp_wpsudo_events', $this->wpdb->query_calls[0] );
	}

	public function test_maybe_create_table_on_mysql_checks_describe_and_skips_creation_when_present(): void {
		$this->wpdb->get_results_return = array(
			(object) array(
				'Field' => 'id',
			),
		);

		Event_Store::maybe_create_table();

		$this->assertCount( 0, $this->wpdb->prepare_calls );
		$this->assertCount( 1, $this->wpdb->get_results_calls );
		$this->assertSame( 'DESCRIBE wp_wpsudo_events', $this->wpdb->get_results_calls[0] );
		$this->assertCount( 0, $this->wpdb->query_calls );
	}

	public function test_drop_table_queries_drop_statement(): void {
		$this->wpdb->query_return = 1;

		Event_Store::drop_table();

		$this->assertCount( 1, $this->wpdb->query_calls );
		$this->assertSame( 'DROP TABLE IF EXISTS wp_wpsudo_events', $this->wpdb->query_calls[0] );
	}

	public function test_insert_writes_row_with_site_id_and_json_context(): void {
		Functions\when( 'get_current_blog_id' )->justReturn( 7 );
		$this->wpdb->insert_return      = 1;
		$this->wpdb->get_results_return = array(
			(object) array(
				'Field' => 'id',
			),
		);

		$result = Event_Store::insert(
			array(
				'user_id' => 12,
				'event'   => 'Action Gated!!',
				'rule_id' => 'plugins.activate',
				'surface' => 'rest',
				'ip'      => '127.0.0.1',
				'context' => array(
					'duration' => 60,
				),
			)
		);

		$this->assertTrue( $result );
		$this->assertCount( 1, $this->wpdb->insert_calls );
		$this->assertSame( 'wp_wpsudo_events', $this->wpdb->insert_calls[0]['table'] );
		$this->assertSame( 7, $this->wpdb->insert_calls[0]['data']['site_id'] );
		$this->assertSame( 12, $this->wpdb->insert_calls[0]['data']['user_id'] );
		$this->assertSame( 'action_gated', $this->wpdb->insert_calls[0]['data']['event'] );
		$this->assertSame( 'plugins.activate', $this->wpdb->insert_calls[0]['data']['rule_id'] );
		$this->assertSame( 'rest', $this->wpdb->insert_calls[0]['data']['surface'] );
		$this->assertSame( '127.0.0.1', $this->wpdb->insert_calls[0]['data']['ip'] );
		$this->assertSame( '{"duration":60}', $this->wpdb->insert_calls[0]['data']['context'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $this->wpdb->insert_calls[0]['data']['created_at'] );
	}

	public function test_insert_returns_false_on_failure(): void {
		$this->wpdb->insert_return      = false;
		$this->wpdb->get_results_return = array(
			(object) array(
				'Field' => 'id',
			),
		);

		$result = Event_Store::insert(
			array(
				'user_id' => 12,
				'event'   => 'action_blocked',
			)
		);

		$this->assertFalse( $result );
	}

	public function test_insert_returns_false_without_db_call_when_table_missing(): void {
		// Simulate table not existing: DESCRIBE returns no columns.
		$this->wpdb->get_results_return = array();

		$result = Event_Store::insert(
			array(
				'user_id' => 12,
				'event'   => 'action_blocked',
			)
		);

		$this->assertFalse( $result );
		// Should have checked table existence but NOT attempted insert.
		$this->assertCount( 0, $this->wpdb->prepare_calls );
		$this->assertCount( 1, $this->wpdb->get_results_calls );
		$this->assertSame( 'DESCRIBE wp_wpsudo_events', $this->wpdb->get_results_calls[0] );
		$this->assertCount( 0, $this->wpdb->insert_calls );
	}

	public function test_insert_reuses_cached_table_exists_result_within_request(): void {
		$this->wpdb->insert_return      = 1;
		$this->wpdb->get_results_return = array(
			(object) array(
				'Field' => 'id',
			),
		);

		$result_one = Event_Store::insert(
			array(
				'user_id' => 10,
				'event'   => 'action_gated',
			)
		);

		$result_two = Event_Store::insert(
			array(
				'user_id' => 11,
				'event'   => 'action_allowed',
			)
		);

		$this->assertTrue( $result_one );
		$this->assertTrue( $result_two );
		$this->assertCount( 1, $this->wpdb->get_results_calls );
		$this->assertCount( 2, $this->wpdb->insert_calls );
	}

	public function test_recent_queries_current_site_and_orders_descending(): void {
		Functions\when( 'get_current_blog_id' )->justReturn( 11 );
		$this->wpdb->get_results_return = array(
			(object) array(
				'user_id'    => 3,
				'event'      => 'action_gated',
				'rule_id'    => 'connectors.update_credentials',
				'surface'    => 'rest',
				'created_at' => '2026-04-19 12:00:00',
			),
		);

		$result = Event_Store::recent( 5 );

		$this->assertSame( 'action_gated', $result[0]['event'] );
		$this->assertCount( 1, $this->wpdb->prepare_calls );
		$this->assertStringContainsString( 'WHERE site_id = %d', $this->wpdb->prepare_calls[0]['query'] );
		$this->assertStringContainsString( 'ORDER BY created_at DESC, id DESC', $this->wpdb->prepare_calls[0]['query'] );
		$this->assertStringContainsString( 'LIMIT %d', $this->wpdb->prepare_calls[0]['query'] );
		$this->assertSame( array( 11, 5 ), $this->wpdb->prepare_calls[0]['args'] );
		$this->assertCount( 1, $this->wpdb->get_results_calls );
	}

	public function test_recent_with_event_filter_adds_where_clause(): void {
		Functions\when( 'get_current_blog_id' )->justReturn( 9 );

		Event_Store::recent( 10, 'action_blocked' );

		$this->assertCount( 1, $this->wpdb->prepare_calls );
		$this->assertStringContainsString( 'AND event = %s', $this->wpdb->prepare_calls[0]['query'] );
		$this->assertSame( array( 9, 'action_blocked', 10 ), $this->wpdb->prepare_calls[0]['args'] );
	}

	public function test_count_since_uses_prepare_and_returns_integer(): void {
		Functions\when( 'get_current_blog_id' )->justReturn( 4 );
		$this->wpdb->get_var_return = '6';

		$count = Event_Store::count_since( 'action_replayed', 3600 );

		$this->assertSame( 6, $count );
		$this->assertCount( 1, $this->wpdb->prepare_calls );
		$this->assertStringContainsString( 'SELECT COUNT(*)', $this->wpdb->prepare_calls[0]['query'] );
		$this->assertStringContainsString( 'site_id = %d', $this->wpdb->prepare_calls[0]['query'] );
		$this->assertStringContainsString( 'event = %s', $this->wpdb->prepare_calls[0]['query'] );
		$this->assertStringContainsString( 'created_at >= %s', $this->wpdb->prepare_calls[0]['query'] );
		$this->assertSame( 4, $this->wpdb->prepare_calls[0]['args'][0] );
		$this->assertSame( 'action_replayed', $this->wpdb->prepare_calls[0]['args'][1] );
	}

	public function test_prune_deletes_rows_older_than_threshold_and_returns_rows_affected(): void {
		$this->wpdb->rows_affected = 3;
		$this->wpdb->query_return  = 3;

		$deleted = Event_Store::prune( 14 );

		$this->assertSame( 3, $deleted );
		$this->assertCount( 1, $this->wpdb->prepare_calls );
		$this->assertStringContainsString( 'DELETE FROM wp_wpsudo_events WHERE created_at < %s', $this->wpdb->prepare_calls[0]['query'] );
		$this->assertCount( 1, $this->wpdb->query_calls );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $this->wpdb->prepare_calls[0]['args'][0] );

		$threshold = strtotime( $this->wpdb->prepare_calls[0]['args'][0] . ' UTC' );
		$this->assertIsInt( $threshold );
		$this->assertGreaterThanOrEqual( time() - ( 14 * DAY_IN_SECONDS ) - 1, $threshold );
		$this->assertLessThanOrEqual( time() - ( 14 * DAY_IN_SECONDS ) + 1, $threshold );
	}

	public function test_prune_batches_delete_when_each_batch_fills_batch_size(): void {
		$batch_size = Event_Store::PRUNE_BATCH_SIZE;

		$this->wpdb->rows_affected_sequence = array( $batch_size, $batch_size, 500 );

		$deleted = Event_Store::prune( 14 );

		$this->assertSame( ( 2 * $batch_size ) + 500, $deleted );
		$this->assertCount( 3, $this->wpdb->query_calls );
		$this->assertCount( 3, $this->wpdb->prepare_calls );
		foreach ( $this->wpdb->prepare_calls as $call ) {
			$this->assertStringContainsString( 'DELETE FROM wp_wpsudo_events', $call['query'] );
			$this->assertStringContainsString( 'WHERE created_at < %s', $call['query'] );
			$this->assertStringContainsString( 'LIMIT %d', $call['query'] );
			$this->assertSame( $batch_size, $call['args'][1] );
		}
	}

	public function test_prune_stops_when_batch_returns_fewer_than_batch_size(): void {
		$batch_size = Event_Store::PRUNE_BATCH_SIZE;

		$this->wpdb->rows_affected_sequence = array( max( 1, $batch_size - 1 ) );

		$deleted = Event_Store::prune( 14 );

		$this->assertSame( $batch_size - 1, $deleted );
		$this->assertCount( 1, $this->wpdb->query_calls );
	}

	public function test_prune_stops_when_batch_returns_zero_after_full_batch(): void {
		$batch_size = Event_Store::PRUNE_BATCH_SIZE;

		$this->wpdb->rows_affected_sequence = array( $batch_size, 0 );

		$deleted = Event_Store::prune( 14 );

		$this->assertSame( $batch_size, $deleted );
		$this->assertCount( 2, $this->wpdb->query_calls );
	}

	public function test_prune_uses_unbatched_delete_on_sqlite(): void {
		$this->wpdb->is_sqlite     = true;
		$this->wpdb->rows_affected = 7;
		$this->wpdb->query_return  = 7;

		$deleted = Event_Store::prune( 14 );

		$this->assertSame( 7, $deleted );
		$this->assertCount( 1, $this->wpdb->query_calls );
		$this->assertCount( 1, $this->wpdb->prepare_calls );
		$this->assertStringNotContainsString( 'LIMIT', $this->wpdb->prepare_calls[0]['query'] );
	}
}

/**
 * Minimal wpdb fake for Event_Store unit tests.
 */
final class FakeWpdb {

	public string $prefix      = 'wp_';
	public string $base_prefix = 'wp_';
	public bool $is_sqlite     = false;

	/** @var array<int, array{table: string, data: array<string, mixed>, format: array<int, string>|null}> */
	public array $insert_calls = [];

	/** @var array<int, string> */
	public array $get_results_calls = [];

	/** @var array<int, string> */
	public array $get_var_calls = [];

	/** @var array<int, string> */
	public array $query_calls = [];

	/** @var array<int, array{query: string, args: array<int, mixed>}> */
	public array $prepare_calls = [];

	/** @var int|false */
	public $insert_return = 1;

	/** @var array<int, object> */
	public array $get_results_return = [];

	/** @var string|int|null */
	public $get_var_return = null;

	public int $query_return   = 0;
	public int $rows_affected  = 0;

	/**
	 * Optional sequence of rows_affected values, one popped per query() call.
	 *
	 * @var array<int, int>
	 */
	public array $rows_affected_sequence = array();

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	/**
	 * @param string                     $table  Table name.
	 * @param array<string, mixed>       $data   Row data.
	 * @param array<int, string>|null    $format Format array.
	 * @return int|false
	 */
	public function insert( string $table, array $data, ?array $format = null ) {
		$this->insert_calls[] = array(
			'table'  => $table,
			'data'   => $data,
			'format' => $format,
		);

		return $this->insert_return;
	}

	/**
	 * @return array<int, object>
	 */
	public function get_results( string $query ): array {
		$this->get_results_calls[] = $query;
		return $this->get_results_return;
	}

	/**
	 * @return string|int|null
	 */
	public function get_var( string $query ) {
		$this->get_var_calls[] = $query;
		return $this->get_var_return;
	}

	public function query( string $query ): int {
		$this->query_calls[] = $query;
		if ( ! empty( $this->rows_affected_sequence ) ) {
			$this->rows_affected = (int) array_shift( $this->rows_affected_sequence );
			return $this->rows_affected;
		}
		return $this->query_return;
	}

	/**
	 * Fake SQLite integration handle accessor.
	 *
	 * @return object
	 */
	public function dbh(): object {
		return (object) array(
			'driver_name' => $this->is_sqlite ? 'sqlite' : 'mysql',
		);
	}

	/**
	 * Fake suppress_errors toggle.
	 *
	 * @param bool $suppress Whether to suppress errors.
	 * @return bool
	 */
	public function suppress_errors( bool $suppress ): bool {
		return $suppress;
	}

	/**
	 * @param string            $query Query with placeholders.
	 * @param mixed ...$args          Parameters.
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = array_values( $args[0] );
		}

		$this->prepare_calls[] = array(
			'query' => $query,
			'args'  => $args,
		);

		$index = 0;

		return (string) preg_replace_callback(
			'/%(?:\d+\$)?([sd])/',
			static function ( array $matches ) use ( &$index, $args ): string {
				$arg = $args[ $index ] ?? '';
				++$index;

				if ( 'd' === $matches[1] ) {
					return (string) (int) $arg;
				}

				return "'" . addslashes( (string) $arg ) . "'";
			},
			$query
		);
	}
}
