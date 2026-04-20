<?php
/**
 * Lightweight event persistence for dashboard/status tooling.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Event_Store
 *
 * Small wrapper around the shared wpsudo_events table used by the
 * Session Activity Dashboard Widget MVP.
 *
 * @since 2.15.0
 */
class Event_Store {

	/**
	 * Maximum rows deleted per batch during prune().
	 *
	 * Keeps InnoDB row-lock duration bounded on large tables so the prune
	 * cron cycle doesn't block concurrent event inserts on a busy site.
	 *
	 * @var int
	 */
	public const PRUNE_BATCH_SIZE = 1000;

	/**
	 * Per-request cache of events-table existence.
	 *
	 * Null = unknown for current request, true/false = cached result.
	 *
	 * @var bool|null
	 */
	private static ?bool $table_exists_cache = null;

	/**
	 * Column list used by dashboard widget event queries.
	 *
	 * Keep this limited to fields the widget actually renders so large text
	 * payloads (context) and IP strings are not fetched just to build rows.
	 *
	 * @var string
	 */
	private const DASHBOARD_SELECT_COLUMNS = 'id, created_at, user_id, event, rule_id, surface';

	/**
	 * Return the shared events table name.
	 *
	 * Uses base_prefix so multisite can share one event table while still
	 * recording site_id for local/current-site views.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		$base_prefix = isset( $wpdb->base_prefix ) && is_string( $wpdb->base_prefix ) ? $wpdb->base_prefix : ( is_string( $wpdb->prefix ?? null ) ? $wpdb->prefix : '' );

		return $base_prefix . 'wpsudo_events';
	}

	/**
	 * Check if the database is SQLite.
	 *
	 * @return bool
	 */
	private static function is_sqlite(): bool {
		global $wpdb;
		// phpcs:disable WordPress.Caps.UseTitle -- WordPress global.
		$dbh = is_object( $wpdb ) && method_exists( $wpdb, 'dbh' ) ? $wpdb->dbh() : null;
		// phpcs:enable

		return is_object( $dbh ) && isset( $dbh->driver_name ) && is_string( $dbh->driver_name ) && 'sqlite' === strtolower( $dbh->driver_name );
	}

	/**
	 * Create the events table if it doesn't exist.
	 *
	 * Uses a lightweight check to avoid errors on first load.
	 *
	 * @return void
	 */
	public static function maybe_create_table(): void {
		global $wpdb;

		if ( true === self::$table_exists_cache ) {
			return;
		}

		if ( self::is_sqlite() ) {
			self::create_table();
			return;
		}

		if ( self::table_exists() ) {
			return;
		}

		if ( is_object( $wpdb ) && method_exists( $wpdb, 'suppress_errors' ) ) {
			$wpdb->suppress_errors( true );
		}
		self::create_table();
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'suppress_errors' ) ) {
			$wpdb->suppress_errors( false );
		}
	}

	/**
	 * Create or update the events table.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table = self::table_name();

		// Check if SQLite.
		$is_sqlite = self::is_sqlite();

		if ( ! function_exists( 'dbDelta' ) && ! $is_sqlite ) {
			$upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( is_readable( $upgrade_file ) ) {
				// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- WordPress core file.
				require_once $upgrade_file;
			}
		}

		if ( ! $is_sqlite && ! function_exists( 'dbDelta' ) ) {
			return;
		}

		$charset_collate = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';

		if ( $is_sqlite ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				"CREATE TABLE IF NOT EXISTS {$table} (
					id INTEGER PRIMARY KEY AUTOINCREMENT,
					site_id INTEGER NOT NULL,
					user_id INTEGER NOT NULL,
					event TEXT NOT NULL,
					rule_id TEXT NOT NULL,
					surface TEXT NOT NULL,
					ip TEXT NOT NULL,
					context TEXT NOT NULL,
					created_at TEXT DEFAULT CURRENT_TIMESTAMP
				)"
			);
			$wpdb->query( "CREATE INDEX IF NOT EXISTS idx_event_created ON {$table}(event, created_at)" );
			$wpdb->query( "CREATE INDEX IF NOT EXISTS idx_site_created ON {$table}(site_id, created_at)" );
			$wpdb->query( "CREATE INDEX IF NOT EXISTS idx_user_created ON {$table}(user_id, created_at)" );
			$wpdb->query( "CREATE INDEX IF NOT EXISTS idx_created ON {$table}(created_at)" );
			$wpdb->query( "CREATE INDEX IF NOT EXISTS idx_site_event_created ON {$table}(site_id, event, created_at)" );
			self::$table_exists_cache = true;
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
			return;
		}

		$sql = "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL auto_increment,
site_id bigint(20) unsigned NOT NULL,
user_id bigint(20) unsigned NOT NULL,
event varchar(50) NOT NULL,
rule_id varchar(100) NOT NULL,
surface varchar(30) NOT NULL,
ip varchar(45) NOT NULL,
context longtext NOT NULL,
created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY  (id),
KEY event_created_at (event, created_at),
KEY site_created_at (site_id, created_at),
KEY user_created_at (user_id, created_at),
KEY created_at (created_at),
KEY site_event_created_at (site_id, event, created_at)
) {$charset_collate};";

		if ( function_exists( 'dbDelta' ) ) {
			dbDelta( $sql );
			self::$table_exists_cache = null;
			self::table_exists();
		}
	}

	/**
	 * Insert an event row.
	 *
	 * Gracefully returns false if the events table does not exist,
	 * allowing the plugin to function even when event logging is unavailable.
	 *
	 * @param array<string, mixed> $data Row data.
	 * @return bool
	 */
	public static function insert( array $data ): bool {
		global $wpdb;

		// Graceful degradation: skip insert if table doesn't exist.
		if ( ! self::table_exists() ) {
			return false;
		}

		$row = array(
			'site_id'    => self::current_site_id(),
			'user_id'    => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
			'event'      => self::sanitize_event_name( $data['event'] ?? '' ),
			'rule_id'    => isset( $data['rule_id'] ) ? (string) $data['rule_id'] : '',
			'surface'    => isset( $data['surface'] ) ? (string) $data['surface'] : '',
			'ip'         => isset( $data['ip'] ) ? (string) $data['ip'] : '',
			'context'    => self::normalize_context( $data['context'] ?? array() ),
			'created_at' => isset( $data['created_at'] ) && is_string( $data['created_at'] ) && '' !== $data['created_at']
				? $data['created_at']
				: gmdate( 'Y-m-d H:i:s' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- insert uses prepared values.
		return false !== $wpdb->insert( self::table_name(), $row );
	}

	/**
	 * Insert multiple event rows in a single query.
	 *
	 * Used by Event_Recorder to flush request-lifetime buffered events on
	 * the WordPress shutdown hook, collapsing N synchronous single-row
	 * inserts into one multi-row INSERT. On a busy headless/hybrid site
	 * with many admin users hitting multiple gated actions per request,
	 * this removes per-insert round-trips (and their InnoDB row-lock
	 * acquisitions) from the hot path.
	 *
	 * @param array<int, array<string, mixed>> $rows Raw event rows shaped like insert() input.
	 * @return int Rows written. Zero if $rows is empty or the table does not exist.
	 */
	public static function bulk_insert( array $rows ): int {
		if ( empty( $rows ) ) {
			return 0;
		}

		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$placeholders = array();
		$args         = array();

		foreach ( $rows as $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}

			$placeholders[] = '(%d, %d, %s, %s, %s, %s, %s, %s)';

			$args[] = self::current_site_id();
			$args[] = isset( $data['user_id'] ) ? (int) $data['user_id'] : 0;
			$args[] = self::sanitize_event_name( $data['event'] ?? '' );
			$args[] = isset( $data['rule_id'] ) ? (string) $data['rule_id'] : '';
			$args[] = isset( $data['surface'] ) ? (string) $data['surface'] : '';
			$args[] = isset( $data['ip'] ) ? (string) $data['ip'] : '';
			$args[] = self::normalize_context( $data['context'] ?? array() );
			$args[] = isset( $data['created_at'] ) && is_string( $data['created_at'] ) && '' !== $data['created_at']
				? $data['created_at']
				: gmdate( 'Y-m-d H:i:s' );
		}

		if ( empty( $placeholders ) ) {
			return 0;
		}

		$query = 'INSERT INTO ' . self::table_name() .
			' (site_id, user_id, event, rule_id, surface, ip, context, created_at) VALUES ' .
			implode( ', ', $placeholders );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table_name() is safe, placeholders controlled internally.
		$prepared = $wpdb->prepare( $query, ...$args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $prepared is output of prepare().
		$wpdb->query( $prepared );

		return isset( $wpdb->rows_affected ) ? (int) $wpdb->rows_affected : 0;
	}

	/**
	 * Fetch recent events for the current site.
	 *
	 * @param int         $limit Maximum number of rows.
	 * @param string|null $event Optional event filter.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $limit = 10, ?string $event = null ): array {
		return self::query_recent_rows( '*', $limit, $event );
	}

	/**
	 * Fetch recent events using the lean column set needed by the dashboard.
	 *
	 * Internal helper for widget rendering. Keeps Event_Store::recent() stable
	 * for existing callers that expect full rows including context and IP.
	 *
	 * @param int         $limit Maximum number of rows.
	 * @param string|null $event Optional event filter.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent_for_dashboard( int $limit = 10, ?string $event = null ): array {
		return self::query_recent_rows( self::DASHBOARD_SELECT_COLUMNS, $limit, $event );
	}

	/**
	 * Count recent events of a given type for the current site.
	 *
	 * @param string $event   Event name.
	 * @param int    $seconds Time window in seconds.
	 * @return int
	 */
	public static function count_since( string $event, int $seconds ): int {
		global $wpdb;

		$threshold = gmdate( 'Y-m-d H:i:s', time() - max( 0, $seconds ) );
		$query     = 'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE site_id = %d AND event = %s AND created_at >= %s';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $query uses placeholders, table_name() is safe.
		$prepared = $wpdb->prepare(
			$query,
			self::current_site_id(),
			self::sanitize_event_name( $event ),
			$threshold
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $prepared is output of prepare().
		return (int) $wpdb->get_var( $prepared );
	}

	/**
	 * Delete rows older than the retention threshold.
	 *
	 * @param int $days Retention window in days.
	 * @return int
	 */
	public static function prune( int $days = 14 ): int {
		global $wpdb;

		$threshold = gmdate( 'Y-m-d H:i:s', time() - ( max( 0, $days ) * DAY_IN_SECONDS ) );
		$table     = self::table_name();

		// SQLite builds are not guaranteed to include DELETE...LIMIT support
		// (needs SQLITE_ENABLE_UPDATE_DELETE_LIMIT at compile time). The SQLite
		// path is dev/Playground only, so a single DELETE is safe there.
		if ( self::is_sqlite() ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table_name() is safe, uses placeholder.
			$prepared = $wpdb->prepare(
				'DELETE FROM ' . $table . ' WHERE created_at < %s',
				$threshold
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $prepared is output of prepare().
			$wpdb->query( $prepared );

			return isset( $wpdb->rows_affected ) ? (int) $wpdb->rows_affected : 0;
		}

		// MySQL: batch the DELETE so InnoDB row locks are held briefly. A large
		// unbounded DELETE on a busy events table can stall concurrent inserts
		// from gate dispatch. Loop until a batch returns fewer rows than the
		// batch size (or zero), meaning we've caught up.
		$batch_size = self::PRUNE_BATCH_SIZE;
		$total      = 0;

		while ( true ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table_name() is safe, uses placeholders.
			$prepared = $wpdb->prepare(
				'DELETE FROM ' . $table . ' WHERE created_at < %s LIMIT %d',
				$threshold,
				$batch_size
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $prepared is output of prepare().
			$wpdb->query( $prepared );

			$affected = isset( $wpdb->rows_affected ) ? (int) $wpdb->rows_affected : 0;
			$total   += $affected;

			// Partial batch = caught up. Full batch = more rows may remain;
			// loop again. Guards against runaway loops by also stopping if
			// rows_affected is not reported (0).
			if ( $affected < $batch_size ) {
				break;
			}
		}

		return $total;
	}

	/**
	 * Drop the events table.
	 *
	 * @return void
	 */
	public static function drop_table(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- table_name() is safe, DROP is idempotent.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table_name() );
		self::$table_exists_cache = false;
	}

	/**
	 * Reset runtime cache values.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public static function reset_runtime_cache(): void {
		self::$table_exists_cache = null;
	}

	/**
	 * Check if the events table exists.
	 *
	 * Used for graceful degradation: operations that require the table
	 * can bail early if it doesn't exist yet. On MySQL, use DESCRIBE
	 * rather than SHOW TABLES so temporary tables created by the WordPress
	 * test suite transaction layer are detected correctly.
	 *
	 * @return bool
	 */
	private static function table_exists(): bool {
		global $wpdb;

		if ( null !== self::$table_exists_cache ) {
			return self::$table_exists_cache;
		}

		// SQLite: use sqlite_master.
		if ( self::is_sqlite() ) {
			if ( ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
				self::$table_exists_cache = false;
				return false;
			}

			$table = self::table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- table_name() is safe.
			$result                   = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT name FROM sqlite_master WHERE type='table' AND name=%s",
					$table
				)
			);
			self::$table_exists_cache = is_string( $result ) && $table === $result;

			return self::$table_exists_cache;
		}

		if ( ! method_exists( $wpdb, 'get_results' ) ) {
			self::$table_exists_cache = false;
			return false;
		}

		if ( is_object( $wpdb ) && method_exists( $wpdb, 'suppress_errors' ) ) {
			$wpdb->suppress_errors( true );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- table_name() is a safe identifier assembled from the configured prefix.
		$columns = $wpdb->get_results( 'DESCRIBE ' . self::table_name() );
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'suppress_errors' ) ) {
			$wpdb->suppress_errors( false );
		}

		self::$table_exists_cache = is_array( $columns ) && ! empty( $columns );

		return self::$table_exists_cache;
	}

	/**
	 * Query recent rows using a fixed, internally-controlled SELECT list.
	 *
	 * @param string      $select SQL-safe SELECT expression from internal constants.
	 * @param int         $limit  Maximum number of rows.
	 * @param string|null $event  Optional event filter.
	 * @return array<int, array<string, mixed>>
	 */
	private static function query_recent_rows( string $select, int $limit, ?string $event = null ): array {
		global $wpdb;

		$query = 'SELECT ' . $select . ' FROM ' . self::table_name() . ' WHERE site_id = %d';
		$args  = array( self::current_site_id() );

		if ( null !== $event && '' !== $event ) {
			$query .= ' AND event = %s';
			$args[] = self::sanitize_event_name( $event );
		}

		$query .= ' ORDER BY created_at DESC, id DESC LIMIT %d';
		$args[] = max( 1, $limit );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query uses placeholders, table_name() and $select are controlled internally.
		$prepared = $wpdb->prepare( $query, ...$args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $prepared is output of prepare().
		$rows = $wpdb->get_results( $prepared );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( $row ): array {
				return is_object( $row ) ? get_object_vars( $row ) : (array) $row;
			},
			$rows
		);
	}

	/**
	 * Normalize the event context payload.
	 *
	 * @param mixed $context Context payload.
	 * @return string
	 */
	private static function normalize_context( $context ): string {
		if ( is_array( $context ) || is_object( $context ) ) {
			return (string) wp_json_encode( $context );
		}

		return (string) $context;
	}

	/**
	 * Sanitize an event name to lowercase underscores.
	 *
	 * @param mixed $event Event input.
	 * @return string
	 */
	private static function sanitize_event_name( $event ): string {
		$event = strtolower( (string) $event );
		$event = (string) preg_replace( '/[^a-z0-9_]+/', '_', $event );
		$event = trim( $event, '_' );

		return '' !== $event ? $event : 'unknown';
	}

	/**
	 * Resolve the current site ID safely.
	 *
	 * @return int
	 */
	private static function current_site_id(): int {
		if ( function_exists( 'get_current_blog_id' ) ) {
			return (int) get_current_blog_id();
		}

		return 1;
	}
}
