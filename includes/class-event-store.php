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
	 * Create or update the events table.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			$upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( is_readable( $upgrade_file ) ) {
				// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- WordPress core file.
				require_once $upgrade_file;
			}
		}

		$table           = self::table_name();
		$charset_collate = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';

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
KEY user_created_at (user_id, created_at)
) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Insert an event row.
	 *
	 * @param array<string, mixed> $data Row data.
	 * @return bool
	 */
	public static function insert( array $data ): bool {
		global $wpdb;

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
	 * Fetch recent events for the current site.
	 *
	 * @param int         $limit Maximum number of rows.
	 * @param string|null $event Optional event filter.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $limit = 10, ?string $event = null ): array {
		global $wpdb;

		$query = 'SELECT * FROM ' . self::table_name() . ' WHERE site_id = %d';
		$args  = array( self::current_site_id() );

		if ( null !== $event && '' !== $event ) {
			$query .= ' AND event = %s';
			$args[] = self::sanitize_event_name( $event );
		}

		$query .= ' ORDER BY created_at DESC LIMIT %d';
		$args[] = max( 1, $limit );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query uses placeholders, table_name() is safe.
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
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table_name() is safe, uses placeholder.
		$prepared = $wpdb->prepare(
			'DELETE FROM ' . self::table_name() . ' WHERE created_at < %s',
			$threshold
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $prepared is output of prepare().
		$wpdb->query( $prepared );

		return isset( $wpdb->rows_affected ) ? (int) $wpdb->rows_affected : 0;
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
