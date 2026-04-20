<?php
/**
 * Dashboard_Widget class.
 *
 * Session Activity Dashboard Widget for operator visibility.
 *
 * @package WP_Sudo
 * @since   2.15.0
 */

namespace WP_Sudo;

/**
 * Dashboard widget showing sudo session activity and policy status.
 *
 * Four sections:
 * - Session Duration: at-a-glance timer setting (at top).
 * - Active Sessions: current users with gravatars.
 * - Recent Events: filterable event log.
 * - Policy Summary: surface policies with spacing.
 *
 * @since 2.15.0
 * @since 3.0.0 Added gravatars, filters, and layout improvements.
 */
class Dashboard_Widget {

	/**
	 * Widget ID.
	 *
	 * @var string
	 */
	public const WIDGET_ID = 'wp_sudo_activity';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_dashboard_setup', array( self::class, 'register' ) );
	}

	/**
	 * Register the dashboard widget.
	 *
	 * Only adds the widget for users with manage_options capability.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'Sudo Session Activity', 'wp-sudo' ),
			array( self::class, 'render' ),
			null,
			null,
			'side',
			'high'
		);
	}

	/**
	 * Render the widget content.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::render_active_sessions();
		self::render_policy_summary();
		self::render_recent_events();
		self::render_inline_styles();
	}

	/**
	 * Maximum users to display in active sessions list.
	 *
	 * @var int
	 */
	private const MAX_DISPLAY_USERS = 6;

	/**
	 * Transient TTL for the active-sessions payload, in seconds.
	 *
	 * Short window: the widget is rendered every time a logged-in admin
	 * visits the dashboard, and on a busy multi-admin site this otherwise
	 * runs a full `WP_User_Query` (with a `_wp_sudo_expires` meta_query)
	 * on every page load. 30 seconds keeps the UI effectively live while
	 * collapsing storms of repeat queries into a single row read.
	 *
	 * @var int
	 */
	private const ACTIVE_SESSIONS_CACHE_TTL = 30;

	/**
	 * Transient key prefix for the active-sessions payload. The current
	 * blog id is appended so multisite networks don't collide.
	 *
	 * @var string
	 */
	private const ACTIVE_SESSIONS_CACHE_KEY = 'wp_sudo_active_sessions_';

	/**
	 * Maximum number of event rows visible in the widget before scrolling.
	 *
	 * @var int
	 */
	private const MAX_VISIBLE_EVENT_ROWS = 6;

	/**
	 * Number of events fetched for widget filtering/sorting buffer.
	 *
	 * Keep this bounded to avoid dashboard render overhead while still allowing
	 * useful on-widget filtering without extra database round trips.
	 *
	 * @var int
	 */
	private const EVENT_BUFFER_LIMIT = 50;

	/**
	 * Default settings for display.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = array(
		'session_duration'         => 15,
		'rest_app_password_policy' => 'limited',
		'cli_policy'               => 'limited',
		'cron_policy'              => 'disabled',
		'xmlrpc_policy'            => 'disabled',
		'wpgraphql_policy'         => 'disabled',
	);

	/**
	 * Render active sessions section.
	 *
	 * Queries users with unexpired sudo sessions and displays with gravatars.
	 *
	 * @return void
	 */
	private static function render_active_sessions(): void {
		$payload = self::get_active_sessions_payload();
		$users   = isset( $payload['users'] ) && is_array( $payload['users'] ) ? $payload['users'] : array();
		$count   = isset( $payload['count'] ) ? (int) $payload['count'] : 0;

		echo '<h3>' . esc_html__( 'Active Sessions', 'wp-sudo' ) . '</h3>';

		if ( 0 === $count ) {
			echo '<div class="wp-sudo-empty-container">';
			echo '<div class="wp-sudo-empty-status">';
			echo '<span class="wp-sudo-empty-status-icon" aria-hidden="true"><span class="dashicons dashicons-lock"></span></span>';
			echo '<strong>' . esc_html__( 'No active sessions now...', 'wp-sudo' ) . '</strong>';
			echo '</div>';
			echo '<div class="wp-sudo-empty-desc">';
			echo '<p>' . esc_html__( 'Sudo monitors gated actions to ensure high-privilege operations are authorized. Events are logged here to provide visibility into who is performing sensitive tasks.', 'wp-sudo' ) . '</p>';
			echo '<p>' . sprintf(
				/* translators: %1$s: opening link tag, %2$s: closing link tag */
				esc_html__( 'You can also %1$svisit the Sudo Settings page%2$s to configure session durations and policies.', 'wp-sudo' ),
				'<a href="' . esc_url( admin_url( 'options-general.php?page=wp-sudo-settings' ) ) . '">',
				'</a>'
			) . '</p>';
			echo '</div>';
			echo '</div>';
			return;
		}

		// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- Simple count.
		echo '<p class="wp-sudo-active-count"><strong>' . esc_html( sprintf( _n( '%d active session', '%d active sessions', $count, 'wp-sudo' ), $count ) ) . '</strong></p>';

		echo '<ul class="wp-sudo-user-list">';
		foreach ( $users as $user ) {
			if ( ! is_object( $user ) || ! isset( $user->ID ) ) {
				continue;
			}

			$user_id      = (int) $user->ID;
			$expires      = (int) get_user_meta( $user_id, '_wp_sudo_expires', true );
			$time_left    = self::format_compact_duration( $expires - time() );
			$user_login   = isset( $user->user_login ) && is_string( $user->user_login ) ? $user->user_login : 'User ' . $user_id;
			$display_name = isset( $user->display_name ) && is_string( $user->display_name ) ? $user->display_name : '';
			$first_name   = isset( $user->first_name ) && is_string( $user->first_name ) ? $user->first_name : '';
			$last_name    = isset( $user->last_name ) && is_string( $user->last_name ) ? $user->last_name : '';
			$full_name    = trim( $first_name . ' ' . $last_name );

			// Role - simplified to avoid WordPress type issues in widget.
			$role = '';
			if ( ! empty( $user->roles ) && is_array( $user->roles ) ) {
				$role = $user->roles[0];
			}

			// Gravatar.
			$avatar = get_avatar(
				(int) $user_id,
				32,
				'',
				'',
				array(
					'force' => true,
				)
			);

			$edit_url = esc_url( admin_url( 'user-edit.php?user_id=' . (int) $user_id ) );

			echo '<li class="wp-sudo-user-row">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar is trusted WP function.
			echo '<div class="wp-sudo-user-gravatar">' . $avatar . '</div>';
			echo '<div class="wp-sudo-user-info">';
			echo '<div class="wp-sudo-user-primary">';
			echo '<a href="' . esc_url( $edit_url ) . '" class="wp-sudo-username">' . esc_html( $user_login ) . '</a>';
			if ( $role ) {
				echo '<span class="wp-sudo-user-role">' . esc_html( $role ) . '</span>';
			}
			echo '</div>';
			echo '<div class="wp-sudo-user-secondary">';
			if ( $full_name ) {
				echo '<span class="wp-sudo-fullname">' . esc_html( $full_name ) . '</span>';
			} elseif ( $display_name && $display_name !== $user_login ) {
				echo '<span class="wp-sudo-displayname">' . esc_html( $display_name ) . '</span>';
			}
			echo '<span class="wp-sudo-time-remaining">' . esc_html( $time_left ) . ' ' . esc_html__( 'left', 'wp-sudo' ) . '</span>';
			echo '</div>';
			echo '</div>';
			echo '</li>';
		}
		echo '</ul>';

		if ( $count > self::MAX_DISPLAY_USERS ) {
			echo '<p class="wp-sudo-view-all">';
			echo '<a href="' . esc_url( admin_url( 'users.php?sudo_active=1' ) ) . '">';
			/* translators: %d is the number of users with active sudo sessions. */
			echo esc_html( sprintf( __( 'View all sudo-active users (%d) →', 'wp-sudo' ), $count ) );
			echo '</a></p>';
		}
	}

	/**
	 * Return the active-sessions payload, using a short-TTL transient cache.
	 *
	 * A transient hit avoids the expensive WP_User_Query with a numeric
	 * `meta_query` on every dashboard page load. On a cache miss the helper
	 * runs the query and persists the result for ACTIVE_SESSIONS_CACHE_TTL
	 * seconds, scoped to the current blog id so multisite networks don't
	 * share stale data across sites.
	 *
	 * @return array{count: int, users: array<int, mixed>}
	 */
	private static function get_active_sessions_payload(): array {
		$blog_id   = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$cache_key = self::ACTIVE_SESSIONS_CACHE_KEY . $blog_id;

		if ( function_exists( 'get_transient' ) ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && array_key_exists( 'count', $cached ) && array_key_exists( 'users', $cached ) ) {
				return array(
					'count' => (int) $cached['count'],
					'users' => is_array( $cached['users'] ) ? $cached['users'] : array(),
				);
			}
		}

		$query = new \WP_User_Query(
			array(
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_wp_sudo_expires',
						'value'   => time(),
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
				'fields'      => 'all',
				'number'      => self::MAX_DISPLAY_USERS,
				'count_total' => true,
			)
		);

		$users = $query->get_results();
		if ( ! is_array( $users ) ) {
			$users = array();
		}

		$payload = array(
			'count' => (int) $query->get_total(),
			'users' => $users,
		);

		if ( function_exists( 'set_transient' ) ) {
			set_transient( $cache_key, $payload, self::ACTIVE_SESSIONS_CACHE_TTL );
		}

		return $payload;
	}

	/**
	 * Event label map for human-readable display.
	 *
	 * @var array<string, string>
	 */
	private const EVENT_LABELS = array(
		'lockout'         => 'Lockout',
		'action_gated'    => 'Gated',
		'action_blocked'  => 'Blocked',
		'action_allowed'  => 'Allowed',
		'action_passed'   => 'Passed',
		'action_replayed' => 'Replayed',
	);

	/**
	 * High-risk rule IDs that should show the critical badge in activity views.
	 *
	 * @var array<int, string>
	 */
	private const CRITICAL_RULE_IDS = array(
		'plugin.delete',
		'theme.delete',
		'user.delete',
		'options.critical',
		'editor.plugin',
		'editor.theme',
		'network.site_delete',
		'network.super_admin',
	);

	/**
	 * Cached rule metadata map keyed by rule ID.
	 *
	 * @var array<string, array{label: string, is_critical: bool}>|null
	 */
	private static ?array $rule_metadata_map = null;

	/**
	 * Surface label map for compact, code-like display in the events table.
	 *
	 * @var array<string, string>
	 */
	private const SURFACE_LABELS = array(
		'admin'             => 'admin',
		'ajax'              => 'ajax',
		'rest'              => 'rest',
		'rest_app_password' => 'app-pass',
		'cli'               => 'wp-cli',
		'cron'              => 'cron',
		'xmlrpc'            => 'xml-rpc',
		'wpgraphql'         => 'graphql',
		'public_api'        => 'public-api',
		'reauth'            => 'reauth',
	);

	/**
	 * Render recent events section.
	 *
	 * Displays recent events from Event_Store in a table with filters.
	 *
	 * @return void
	 */
	private static function render_recent_events(): void {
		$passed_event_logging_enabled = Admin::is_passed_event_logging_enabled();

		// Ensure the events table exists before querying.
		Event_Store::maybe_create_table();
		$events              = Event_Store::recent_for_dashboard( self::EVENT_BUFFER_LIMIT );
		$event_scroll_height = self::MAX_VISIBLE_EVENT_ROWS * 33;

		echo '<div class="wp-sudo-events-header">';
		echo '<h3 class="wp-sudo-events-heading">' . esc_html__( 'Recent Events', 'wp-sudo' ) . '</h3>';

		// Filter controls in header row.
		echo '<div class="wp-sudo-event-filters" aria-label="' . esc_attr__( 'Recent events filters', 'wp-sudo' ) . '">';

		// Time dropdown.
		echo '<select class="wp-sudo-filter-select" data-filter="time" aria-label="' . esc_attr__( 'Filter events by time range', 'wp-sudo' ) . '">';
		echo '<option value="all">' . esc_html__( 'All Time', 'wp-sudo' ) . '</option>';
		echo '<option value="24h" selected>' . esc_html__( 'Last 24 Hours', 'wp-sudo' ) . '</option>';
		echo '<option value="7d">' . esc_html__( 'Last 7 Days', 'wp-sudo' ) . '</option>';
		echo '</select>';

		// Event type dropdown.
		echo '<select class="wp-sudo-filter-select" data-filter="event" aria-label="' . esc_attr__( 'Filter events by session event type', 'wp-sudo' ) . '">';
		echo '<option value="all">' . esc_html__( 'All Sessions', 'wp-sudo' ) . '</option>';
		echo '<option value="lockout">' . esc_html__( 'Lockout', 'wp-sudo' ) . '</option>';
		echo '<option value="action_gated">' . esc_html__( 'Gated', 'wp-sudo' ) . '</option>';
		echo '<option value="action_blocked">' . esc_html__( 'Blocked', 'wp-sudo' ) . '</option>';
		echo '<option value="action_allowed">' . esc_html__( 'Allowed', 'wp-sudo' ) . '</option>';
		echo '<option value="action_passed"' . ( $passed_event_logging_enabled ? '' : ' disabled' ) . '>';
		echo esc_html__( 'Passed', 'wp-sudo' );
		echo '</option>';
		echo '<option value="action_replayed">' . esc_html__( 'Replayed', 'wp-sudo' ) . '</option>';
		echo '</select>';

		// Surface dropdown.
		echo '<select class="wp-sudo-filter-select" data-filter="surface" aria-label="' . esc_attr__( 'Filter events by request surface', 'wp-sudo' ) . '">';
		echo '<option value="all">' . esc_html__( 'All Surfaces', 'wp-sudo' ) . '</option>';
		echo '<option value="admin">' . esc_html__( 'Admin', 'wp-sudo' ) . '</option>';
		echo '<option value="ajax">' . esc_html__( 'AJAX', 'wp-sudo' ) . '</option>';
		echo '<option value="rest">' . esc_html__( 'REST', 'wp-sudo' ) . '</option>';
		echo '<option value="rest_app_password">' . esc_html__( 'App Pass', 'wp-sudo' ) . '</option>';
		echo '<option value="cli">' . esc_html__( 'WP-CLI', 'wp-sudo' ) . '</option>';
		echo '<option value="cron">' . esc_html__( 'Cron', 'wp-sudo' ) . '</option>';
		echo '<option value="xmlrpc">' . esc_html__( 'XML-RPC', 'wp-sudo' ) . '</option>';
		echo '<option value="wpgraphql">' . esc_html__( 'GraphQL', 'wp-sudo' ) . '</option>';
		echo '<option value="public_api">' . esc_html__( 'Public API', 'wp-sudo' ) . '</option>';
		echo '</select>';

		echo '</div>';
		echo '</div>';

		// Code-override notice.
		if ( ! $passed_event_logging_enabled ) {
			echo '<p class="wp-sudo-filter-notice">';
			echo esc_html__( 'Passed events are currently hidden by a code-level policy override.', 'wp-sudo' );
			echo '</p>';
		}

		if ( empty( $events ) ) {
			echo '<p class="wp-sudo-empty">' . esc_html__( 'No recent activity', 'wp-sudo' ) . '</p>';
			return;
		}

		$user_logins = self::get_event_user_login_map( $events );
		$user_links  = self::get_event_user_profile_url_map( $user_logins );

		echo '<div class="wp-sudo-events-scroll" aria-label="' . esc_attr__( 'Recent event history', 'wp-sudo' ) . '" style="max-height:' . esc_attr( (string) $event_scroll_height ) . 'px">';
		echo '<table class="widefat striped wp-sudo-events-table">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Recent sudo session events', 'wp-sudo' ) . '</caption>';
		echo '<thead><tr>';
		echo '<th scope="col" data-sort-column="time" aria-sort="descending"><button type="button" class="wp-sudo-sort-button" data-sort-key="time" data-default-dir="desc"><span class="wp-sudo-sort-label">' . esc_html__( 'Time', 'wp-sudo' ) . '</span><span class="wp-sudo-sort-indicator" aria-hidden="true"></span></button></th>';
		echo '<th scope="col" data-sort-column="user" aria-sort="none"><button type="button" class="wp-sudo-sort-button" data-sort-key="user" data-default-dir="asc"><span class="wp-sudo-sort-label">' . esc_html__( 'User', 'wp-sudo' ) . '</span><span class="wp-sudo-sort-indicator" aria-hidden="true"></span></button></th>';
		echo '<th scope="col" data-sort-column="event" aria-sort="none"><button type="button" class="wp-sudo-sort-button" data-sort-key="event" data-default-dir="asc"><span class="wp-sudo-sort-label">' . esc_html__( 'Event', 'wp-sudo' ) . '</span><span class="wp-sudo-sort-indicator" aria-hidden="true"></span></button></th>';
		echo '<th scope="col" data-sort-column="action" aria-sort="none"><button type="button" class="wp-sudo-sort-button" data-sort-key="action" data-default-dir="asc"><span class="wp-sudo-sort-label">' . esc_html__( 'Action', 'wp-sudo' ) . '</span><span class="wp-sudo-sort-indicator" aria-hidden="true"></span></button></th>';
		echo '<th scope="col" data-sort-column="surface" aria-sort="none"><button type="button" class="wp-sudo-sort-button" data-sort-key="surface" data-default-dir="asc"><span class="wp-sudo-sort-label">' . esc_html__( 'Surface', 'wp-sudo' ) . '</span><span class="wp-sudo-sort-indicator" aria-hidden="true"></span></button></th>';
		echo '</tr></thead><tbody>';

		$row_index = 0;
		foreach ( $events as $event ) {
			$event_obj       = is_array( $event ) ? (object) $event : $event;
			$created_at      = isset( $event_obj->created_at ) ? self::parse_created_at_timestamp( (string) $event_obj->created_at ) : 0;
			$time_ago        = $created_at > 0 ? self::format_compact_duration( time() - $created_at ) : '—';
			$time_title      = $created_at > 0 ? self::format_absolute_event_time( $created_at ) : '';
			$user_id         = isset( $event_obj->user_id ) ? (int) $event_obj->user_id : 0;
			$is_deleted_user = false;
			if ( $user_id > 0 && isset( $user_logins[ $user_id ] ) ) {
				$username = $user_logins[ $user_id ];
			} elseif ( $user_id > 0 ) {
				$is_deleted_user = true;
				/* translators: %d is the deleted user's numeric ID. */
				$username = sprintf( __( 'Deleted user (#%d)', 'wp-sudo' ), $user_id );
			} else {
				$username = '—';
			}
			$event_type    = isset( $event_obj->event ) ? (string) $event_obj->event : '';
			$event_label   = self::EVENT_LABELS[ $event_type ] ?? $event_type;
			$event_class   = 'wp-sudo-event-pill wp-sudo-event-pill-' . str_replace( '_', '-', $event_type );
			$rule_id       = isset( $event_obj->rule_id ) ? (string) $event_obj->rule_id : '';
			$rule_metadata = self::get_rule_metadata( $rule_id );
			$rule_label    = $rule_metadata['label'];
			$is_critical   = $rule_metadata['is_critical'];
			$surface       = isset( $event_obj->surface ) ? (string) $event_obj->surface : '';
			if ( '' === $surface && 'action_replayed' === $event_type ) {
				$surface = 'reauth';
			}
			$surface_label = self::SURFACE_LABELS[ $surface ] ?? $surface;
			$user_title    = $username;
			$event_title   = $event_label;
			$action_title  = $rule_label;
			$surface_title = '' !== $surface_label ? $surface_label : '—';

			$cell_bg = 1 === ( $row_index % 2 ) ? '#eef1f5' : '#ffffff';

			echo '<tr';
			if ( 1 === ( $row_index % 2 ) ) {
				echo ' class="alternate"';
			}
			echo ' data-time="' . esc_attr( (string) $created_at ) . '" data-event="' . esc_attr( $event_type ) . '" data-surface="' . esc_attr( $surface ) . '" data-sort-user="' . esc_attr( strtolower( $username ) ) . '" data-sort-event="' . esc_attr( strtolower( $event_label ) ) . '" data-sort-action="' . esc_attr( strtolower( $rule_label ) ) . '" data-sort-surface="' . esc_attr( strtolower( $surface_label ) ) . '">';
			echo '<td style="background:' . esc_attr( $cell_bg ) . ';"' . ( '' !== $time_title ? ' title="' . esc_attr( $time_title ) . '"' : '' ) . '>' . esc_html( $time_ago ) . '</td>';
			echo '<td style="background:' . esc_attr( $cell_bg ) . ';" title="' . esc_attr( $user_title ) . '">';
			if ( $user_id > 0 && isset( $user_links[ $user_id ] ) ) {
				echo '<a href="' . esc_url( $user_links[ $user_id ] ) . '" class="wp-sudo-event-user-link">' . esc_html( $username ) . '</a>';
			} elseif ( $is_deleted_user ) {
				echo '<em class="wp-sudo-event-user-deleted">' . esc_html( $username ) . '</em>';
			} else {
				echo esc_html( $username );
			}
			echo '</td>';
			echo '<td style="background:' . esc_attr( $cell_bg ) . ';" title="' . esc_attr( $event_title ) . '"><span class="' . esc_attr( $event_class ) . '">' . esc_html( $event_label ) . '</span></td>';
			echo '<td style="background:' . esc_attr( $cell_bg ) . ';" title="' . esc_attr( $action_title ) . '"><span class="wp-sudo-action-label">' . esc_html( $rule_label ) . '</span>';
			if ( $is_critical ) {
				echo ' <span class="wp-sudo-critical-badge">' . esc_html__( 'Critical', 'wp-sudo' ) . '</span>';
			}
			if ( '' !== $rule_id ) {
				/* translators: %s is the technical action ID (for example options.wp_sudo). */
				$action_id_tooltip = sprintf( __( 'Technical action ID: %s', 'wp-sudo' ), $rule_id );
				echo ' <code class="wp-sudo-action-id" title="' . esc_attr( $action_id_tooltip ) . '">' . esc_html( $rule_id ) . '</code>';
			}
			echo '</td>';
			echo '<td style="background:' . esc_attr( $cell_bg ) . ';" title="' . esc_attr( $surface_title ) . '">' . ( '' !== $surface_label ? '<code class="wp-sudo-surface-code">' . esc_html( $surface_label ) . '</code>' : '—' ) . '</td>';
			echo '</tr>';
			++$row_index;
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Build a user_id => user_login map for Recent Events rows in one query.
	 *
	 * @param array<int, mixed> $events Event rows from Event_Store::recent().
	 * @return array<int, string>
	 */
	private static function get_event_user_login_map( array $events ): array {
		$user_ids = array();

		foreach ( $events as $event ) {
			$event_obj = is_array( $event ) ? (object) $event : $event;
			$user_id   = isset( $event_obj->user_id ) ? (int) $event_obj->user_id : 0;
			if ( $user_id > 0 ) {
				$user_ids[ $user_id ] = $user_id;
			}
		}

		if ( empty( $user_ids ) ) {
			return array();
		}

		$users = get_users(
			array(
				'include' => array_values( $user_ids ),
				'orderby' => 'include',
				'fields'  => array( 'ID', 'user_login' ),
			)
		);

		$map = array();
		foreach ( $users as $user ) {
			if ( ! is_object( $user ) || ! isset( $user->ID ) ) {
				continue;
			}

			$id = (int) $user->ID;
			if ( $id <= 0 ) {
				continue;
			}

			$login = isset( $user->user_login ) && is_string( $user->user_login ) ? $user->user_login : '';
			if ( '' !== $login ) {
				$map[ $id ] = $login;
			}
		}

		return $map;
	}

	/**
	 * Build a user_id => profile-edit URL map for users the viewer can edit.
	 *
	 * @param array<int, string> $user_logins user_id => user_login map.
	 * @return array<int, string>
	 */
	private static function get_event_user_profile_url_map( array $user_logins ): array {
		if ( empty( $user_logins ) ) {
			return array();
		}

		$links = array();
		foreach ( array_keys( $user_logins ) as $user_id ) {
			$id = (int) $user_id;
			if ( $id <= 0 ) {
				continue;
			}

			if ( ! current_user_can( 'edit_user', $id ) ) {
				continue;
			}

			$links[ $id ] = admin_url( 'user-edit.php?user_id=' . $id );
		}

		return $links;
	}

	/**
	 * Get display metadata for a rule ID.
	 *
	 * @param string $rule_id Action Registry rule ID.
	 * @return array{label: string, is_critical: bool}
	 */
	private static function get_rule_metadata( string $rule_id ): array {
		if ( '' === $rule_id ) {
			return array(
				'label'       => '—',
				'is_critical' => false,
			);
		}

		if ( null === self::$rule_metadata_map ) {
			self::$rule_metadata_map = array();
			$rules                   = Action_Registry::get_rules();

			foreach ( $rules as $rule ) {
				$id = isset( $rule['id'] ) ? (string) $rule['id'] : '';
				if ( '' === $id ) {
					continue;
				}

				$label = isset( $rule['label'] ) ? (string) $rule['label'] : self::humanize_rule_id( $id );

				self::$rule_metadata_map[ $id ] = array(
					'label'       => $label,
					'is_critical' => self::is_critical_rule_id( $id ),
				);
			}
		}

		if ( isset( self::$rule_metadata_map[ $rule_id ] ) ) {
			return self::$rule_metadata_map[ $rule_id ];
		}

		return array(
			'label'       => self::humanize_rule_id( $rule_id ),
			'is_critical' => self::is_critical_rule_id( $rule_id ),
		);
	}

	/**
	 * Determine whether a rule ID should be visually marked as critical.
	 *
	 * @param string $rule_id Rule ID.
	 * @return bool
	 */
	private static function is_critical_rule_id( string $rule_id ): bool {
		return in_array( $rule_id, self::CRITICAL_RULE_IDS, true );
	}

	/**
	 * Humanize unknown technical rule IDs for primary UI display.
	 *
	 * @param string $rule_id Technical rule ID.
	 * @return string
	 */
	private static function humanize_rule_id( string $rule_id ): string {
		$parts = explode( '.', $rule_id );

		if ( 2 === count( $parts ) ) {
			return ucfirst( self::humanize_rule_token( $parts[1] ) . ' ' . self::humanize_rule_token( $parts[0] ) );
		}

		return ucwords( self::humanize_rule_token( str_replace( '.', ' ', $rule_id ) ) );
	}

	/**
	 * Humanize one segment of a technical rule ID.
	 *
	 * @param string $value Rule token.
	 * @return string
	 */
	private static function humanize_rule_token( string $value ): string {
		return strtolower( trim( str_replace( array( '_', '-' ), ' ', $value ) ) );
	}

	/**
	 * Parse a stored UTC datetime value to a UNIX timestamp.
	 *
	 * @param string $created_at Stored created_at value.
	 * @return int
	 */
	private static function parse_created_at_timestamp( string $created_at ): int {
		if ( '' === $created_at ) {
			return 0;
		}

		$timestamp_utc = strtotime( $created_at . ' UTC' );
		if ( false !== $timestamp_utc ) {
			return $timestamp_utc;
		}

		$timestamp = strtotime( $created_at );

		return false === $timestamp ? 0 : $timestamp;
	}

	/**
	 * Format an absolute event timestamp for hover/title display.
	 *
	 * @param int $timestamp UTC timestamp.
	 * @return string
	 */
	private static function format_absolute_event_time( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp ) . ' UTC';
	}

	/**
	 * Format a duration as a compact unit string (s/m/h/d/w/mo/y).
	 *
	 * @param int $seconds Duration in seconds.
	 * @return string
	 */
	private static function format_compact_duration( int $seconds ): string {
		$seconds = max( 1, $seconds );

		if ( $seconds < MINUTE_IN_SECONDS ) {
			return (string) $seconds . 's';
		}

		if ( $seconds < HOUR_IN_SECONDS ) {
			return (string) floor( $seconds / MINUTE_IN_SECONDS ) . 'm';
		}

		if ( $seconds < DAY_IN_SECONDS ) {
			return (string) floor( $seconds / HOUR_IN_SECONDS ) . 'h';
		}

		if ( $seconds < WEEK_IN_SECONDS ) {
			return (string) floor( $seconds / DAY_IN_SECONDS ) . 'd';
		}

		if ( $seconds < MONTH_IN_SECONDS ) {
			return (string) floor( $seconds / WEEK_IN_SECONDS ) . 'w';
		}

		if ( $seconds < YEAR_IN_SECONDS ) {
			return (string) floor( $seconds / MONTH_IN_SECONDS ) . 'mo';
		}

		return (string) floor( $seconds / YEAR_IN_SECONDS ) . 'y';
	}

	/**
	 * Policy label map for human-readable display.
	 *
	 * @var array<string, string>
	 */
	private const POLICY_LABELS = array(
		'disabled'     => 'Disabled',
		'limited'      => 'Limited',
		'unrestricted' => 'Unrestricted',
	);

	/**
	 * Render policy summary section.
	 *
	 * Displays entry-point surface policies (session duration shown at top).
	 *
	 * @return void
	 */
	private static function render_policy_summary(): void {
		$settings = get_option( 'wp_sudo_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$mode_meta = self::get_active_policy_mode_meta( $settings );

		echo '<div class="wp-sudo-policy-wrap">';
		echo '<div class="wp-sudo-policy-header">';
		echo '<h3 class="wp-sudo-policy-heading">' . esc_html__( 'Policy Summary', 'wp-sudo' ) . '</h3>';
		echo '<a class="button button-small wp-sudo-policy-mode' . esc_attr( $mode_meta['class'] ) . '" href="' . esc_url( $mode_meta['url'] ) . '"><span class="wp-sudo-policy-mode-label">' . esc_html__( 'Mode:', 'wp-sudo' ) . '</span> <span class="wp-sudo-policy-mode-value">' . esc_html( $mode_meta['label'] ) . '</span></a>';
		echo '</div>';
		echo '<details class="wp-sudo-policy-details">';
		echo '<summary class="wp-sudo-policy-toggle">' . esc_html__( 'Policy details', 'wp-sudo' ) . '</summary>';
		echo '<table class="widefat striped wp-sudo-policy-table">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Entry-point surface policies', 'wp-sudo' ) . '</caption>';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Surface', 'wp-sudo' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Policy', 'wp-sudo' ) . '</th>';
		echo '</tr></thead><tbody>';

		$surfaces = array(
			'rest_app_password_policy' => __( 'App Passwords', 'wp-sudo' ),
			'cli_policy'               => __( 'WP-CLI', 'wp-sudo' ),
			'cron_policy'              => __( 'Cron', 'wp-sudo' ),
			'xmlrpc_policy'            => __( 'XML-RPC', 'wp-sudo' ),
		);

		// Add WPGraphQL if active.
		if ( class_exists( 'WPGraphQL' ) ) {
			$surfaces['wpgraphql_policy'] = __( 'GraphQL', 'wp-sudo' );
		}

		$row_index = 0;
		foreach ( $surfaces as $key => $label ) {
			$policy       = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : (string) self::DEFAULTS[ $key ];
			$policy_label = self::POLICY_LABELS[ $policy ] ?? $policy;
			$cell_bg      = 1 === ( $row_index % 2 ) ? '#eef1f5' : '#ffffff';

			echo '<tr';
			if ( 1 === ( $row_index % 2 ) ) {
				echo ' class="alternate"';
			}
			echo '>';
			echo '<td style="background:' . esc_attr( $cell_bg ) . ';">' . esc_html( $label ) . '</td>';
			echo '<td style="background:' . esc_attr( $cell_bg ) . ';">' . esc_html( $policy_label ) . '</td>';
			echo '</tr>';
			++$row_index;
		}

		echo '</tbody></table>';
		echo '</details>';
		echo '</div>';
	}

	/**
	 * Resolve mode display metadata for the policy-summary badge.
	 *
	 * @param array<string, mixed> $settings Stored plugin settings.
	 * @return array{key: string, label: string, url: string, class: string}
	 */
	private static function get_active_policy_mode_meta( array $settings ): array {
		$presets    = Admin::policy_presets();
		$preset_key = isset( $settings[ Admin::SETTING_POLICY_PRESET ] ) ? (string) $settings[ Admin::SETTING_POLICY_PRESET ] : '';

		if ( '' !== $preset_key && isset( $presets[ $preset_key ]['label'] ) ) {
			return self::build_mode_meta( $preset_key, (string) $presets[ $preset_key ]['label'] );
		}

		foreach ( $presets as $key => $preset ) {
			if ( ! isset( $preset['policies'] ) || ! is_array( $preset['policies'] ) ) {
				continue;
			}

			$matches = true;
			foreach ( $preset['policies'] as $policy_key => $expected_value ) {
				$current_value = isset( $settings[ $policy_key ] ) ? (string) $settings[ $policy_key ] : (string) ( self::DEFAULTS[ $policy_key ] ?? '' );
				if ( $current_value !== (string) $expected_value ) {
					$matches = false;
					break;
				}
			}

			if ( $matches && isset( $presets[ $key ]['label'] ) ) {
				return self::build_mode_meta( $key, (string) $presets[ $key ]['label'] );
			}
		}

		return self::build_mode_meta( Admin::POLICY_PRESET_CUSTOM, __( 'Custom', 'wp-sudo' ) );
	}

	/**
	 * Build mode metadata for UI rendering.
	 *
	 * @param string $preset_key Policy preset key.
	 * @param string $label      Human-readable preset label.
	 * @return array{key: string, label: string, url: string, class: string}
	 */
	private static function build_mode_meta( string $preset_key, string $label ): array {
		$anchor = Admin::POLICY_PRESET_CUSTOM === $preset_key
			? Gate::SETTING_REST_APP_PASS_POLICY
			: Admin::SETTING_POLICY_PRESET_SELECTION;

		$url = admin_url( 'options-general.php?page=wp-sudo-settings&tab=settings#' . $anchor );

		$class = ' is-' . str_replace( '_', '-', $preset_key );
		if ( Admin::POLICY_PRESET_INCIDENT_LOCKDOWN === $preset_key ) {
			$class .= ' is-lockdown';
		}

		return array(
			'key'   => $preset_key,
			'label' => $label,
			'url'   => $url,
			'class' => $class,
		);
	}

	/**
	 * Render inline styles and JavaScript for widget interactivity.
	 *
	 * @return void
	 */
	private static function render_inline_styles(): void {
		?>
<style>
#wp_sudo_activity .wp-sudo-session-duration {
	display: inline-flex;
	align-items: baseline;
	gap: 0.25em;
	margin-bottom: 1em;
	padding: 0.5em 0.75em;
	background: #f0f6fc;
	border-radius: 4px;
	font-size: 0.9em;
}
#wp_sudo_activity .wp-sudo-session-duration .duration-value {
	font-size: 1.5em;
	font-weight: 600;
	color: #1d2327;
}
#wp_sudo_activity .wp-sudo-session-duration .duration-label {
	color: #646970;
}

#wp_sudo_activity h3 {
	margin-top: 1.25em;
	margin-bottom: 0.5em;
}

/* Active sessions — responsive grid */
#wp_sudo_activity .wp-sudo-user-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 8px;
}
#wp_sudo_activity .wp-sudo-user-row {
	display: flex;
	align-items: center;
	gap: 0.5em;
	padding: 6px 8px;
	border: 1px solid #f0f0f1;
	border-radius: 4px;
	background: #f9f9f9;
}
#wp_sudo_activity .wp-sudo-user-gravatar img {
	width: 32px;
	height: 32px;
	border-radius: 50%;
}
#wp_sudo_activity .wp-sudo-user-info {
	flex: 1;
	min-width: 0;
}
#wp_sudo_activity .wp-sudo-user-primary {
	display: flex;
	align-items: center;
	gap: 0.5em;
	flex-wrap: wrap;
}
#wp_sudo_activity .wp-sudo-username {
	font-weight: 600;
	color: #2271b1;
	text-decoration: none;
}
#wp_sudo_activity .wp-sudo-username:hover {
	color: #135e96;
	text-decoration: underline;
}
#wp_sudo_activity .wp-sudo-user-role {
	font-size: 0.75em;
	padding: 0.15em 0.5em;
	background: #ddefe3;
	color: #1a7f37;
	border-radius: 3px;
}
#wp_sudo_activity .wp-sudo-user-secondary {
	font-size: 0.85em;
	color: #646970;
}
#wp_sudo_activity .wp-sudo-user-secondary .wp-sudo-fullname,
#wp_sudo_activity .wp-sudo-user-secondary .wp-sudo-displayname {
	margin-right: 0.5em;
}

#wp_sudo_activity .wp-sudo-empty-container {
	display: grid;
	grid-template-columns: minmax(140px, 30%) 1fr;
	gap: 1.25em;
	align-items: flex-start;
	margin-bottom: 1em;
}
#wp_sudo_activity .wp-sudo-empty-status {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 0.4em;
	padding: 0.75em 0.5em;
	border: 1px solid #e0e0e0;
	border-radius: 4px;
	background: #f8f9fa;
	min-height: 120px;
	text-align: center;
}
#wp_sudo_activity .wp-sudo-empty-status strong {
	font-size: 1.05em;
	line-height: 1.25;
}
#wp_sudo_activity .wp-sudo-empty-status-icon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	border-radius: 999px;
	background: #f0f6fc;
	color: #2271b1;
}
#wp_sudo_activity .wp-sudo-empty-status-icon .dashicons {
	width: 18px;
	height: 18px;
	font-size: 18px;
}
#wp_sudo_activity .wp-sudo-empty-desc {
	font-size: 0.85em;
	color: #646970;
	line-height: 1.4;
}
#wp_sudo_activity .wp-sudo-empty-desc p {
	margin: 0 0 0.5em;
}
#wp_sudo_activity .wp-sudo-empty-desc p:last-child {
	margin-bottom: 0;
}

#wp_sudo_activity .wp-sudo-view-all {
	margin-top: 0.75em;
	margin-bottom: 0;
	text-align: right;
}
#wp_sudo_activity .wp-sudo-view-all a {
	font-size: 0.85em;
	font-weight: 600;
	text-decoration: none;
}

/* Recent events header and filters follow standard WP stacked controls. */
#wp_sudo_activity .wp-sudo-events-header {
	display: block;
	margin-top: 1.2em;
	margin-bottom: 0.35em;
}
#wp_sudo_activity .wp-sudo-events-heading {
	margin: 0 0 0.35em;
}

/* Event filters — compact row that can wrap cleanly in narrow side columns. */
#wp_sudo_activity .wp-sudo-event-filters {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px;
	margin: 0;
	justify-content: flex-start;
}
#wp_sudo_activity .wp-sudo-event-filters select {
	font-size: 11px !important;
	padding: 0 24px 0 6px !important;
	height: 22px !important;
	min-height: 22px !important;
	line-height: 20px !important;
	border: 1px solid #c3c4c7;
	border-radius: 0;
	background: #fff;
	min-width: 0;
	max-width: 100%;
	text-align: center;
	text-align-last: center;
	-moz-text-align-last: center;
}
#wp_sudo_activity .wp-sudo-filter-notice {
	font-size: 0.75em;
	color: #646970;
	margin: 0 0 0.45em;
}
#wp_sudo_activity .wp-sudo-events-scroll {
	overflow-y: auto;
	overflow-x: hidden;
}
#wp_sudo_activity .wp-sudo-events-table {
	width: 100%;
	table-layout: auto;
}
#wp_sudo_activity .wp-sudo-events-table th,
#wp_sudo_activity .wp-sudo-events-table td {
	vertical-align: top;
}
#wp_sudo_activity .wp-sudo-events-table th[data-sort-column="action"],
#wp_sudo_activity .wp-sudo-events-table td:nth-child(4) {
	width: 34%;
}
#wp_sudo_activity .wp-sudo-events-table th[data-sort-column="surface"],
#wp_sudo_activity .wp-sudo-events-table td:nth-child(5) {
	width: 20%;
	white-space: nowrap;
}

#wp_sudo_activity .wp-sudo-sort-button {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 0;
	margin: 0;
	border: 0;
	background: transparent;
	color: inherit;
	font: inherit;
	cursor: pointer;
}
#wp_sudo_activity .wp-sudo-sort-button:focus-visible,
#wp_sudo_activity .wp-sudo-event-user-link:focus-visible,
#wp_sudo_activity .wp-sudo-event-filters select:focus-visible {
	outline: 2px solid #2271b1;
	outline-offset: 2px;
}
#wp_sudo_activity .wp-sudo-sort-indicator {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 1em;
	min-width: 1em;
	font-size: 11px;
	line-height: 1;
	color: #646970;
}
#wp_sudo_activity .wp-sudo-sort-indicator::before {
	display: block;
	width: 100%;
	text-align: center;
}
#wp_sudo_activity th[aria-sort="ascending"] .wp-sudo-sort-indicator::before {
	content: "▲";
}
#wp_sudo_activity th[aria-sort="descending"] .wp-sudo-sort-indicator::before {
	content: "▼";
}
#wp_sudo_activity th[aria-sort="none"] .wp-sudo-sort-indicator::before {
	content: "↕";
}

#wp_sudo_activity .wp-sudo-event-pill {
	display: inline-block;
	padding: 2px 6px;
	border-radius: 999px;
	font-size: 11px;
	line-height: 1.35;
	font-weight: 600;
	border: 1px solid #dcdcde;
	background: #f6f7f7;
	color: #1d2327;
}
#wp_sudo_activity .wp-sudo-event-pill-lockout {
	background: #fbeeee;
	border-color: #efcfcf;
	color: #8c2f2f;
}
#wp_sudo_activity .wp-sudo-event-pill-action-blocked {
	background: #fff3ea;
	border-color: #f4d8bd;
	color: #8e5423;
}
#wp_sudo_activity .wp-sudo-event-pill-action-gated {
	background: #fff9e8;
	border-color: #f3e5b4;
	color: #7a6115;
}
#wp_sudo_activity .wp-sudo-event-pill-action-allowed {
	background: #e5f7e9;
	border-color: #badfc5;
	color: #1f6a39;
}
#wp_sudo_activity .wp-sudo-event-pill-action-passed {
	background: #eaf9ef;
	border-color: #c4e7cf;
	color: #237544;
}
#wp_sudo_activity .wp-sudo-event-pill-action-replayed {
	background: #e7f0ff;
	border-color: #c2d5fb;
	color: #2e5fa9;
}
#wp_sudo_activity .wp-sudo-surface-code {
	display: inline-block;
	padding: 1px 5px;
	border-radius: 3px;
	background: #f6f7f7;
	border: 1px solid #dcdcde;
	font-size: 11px;
	line-height: 1.35;
	color: #3c434a;
}
#wp_sudo_activity .wp-sudo-critical-badge {
	display: inline-block;
	margin-left: 4px;
	padding: 1px 6px;
	border-radius: 999px;
	border: 1px solid #e6b8b8;
	background: #fdf2f2;
	color: #8a2424;
	font-size: 10px;
	font-weight: 600;
	line-height: 1.3;
	vertical-align: middle;
}
#wp_sudo_activity .wp-sudo-action-label {
	display: block;
}
#wp_sudo_activity .wp-sudo-action-id {
	display: inline-block;
	max-width: none;
	margin-top: 3px;
	margin-left: 0;
	padding: 1px 5px;
	border-radius: 3px;
	border: 1px solid #dcdcde;
	background: #f6f7f7;
	color: #646970;
	font-size: 11px;
	line-height: 1.35;
	white-space: normal;
	overflow: visible;
	text-overflow: clip;
	overflow-wrap: anywhere;
	vertical-align: middle;
}

/* Policy Summary spacing */
#wp_sudo_activity .wp-sudo-policy-wrap {
	margin-top: 1.1em;
	padding: 0;
	border: 0;
	background: transparent;
}
#wp_sudo_activity .wp-sudo-policy-header {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 8px;
	margin-bottom: 0.35em;
}
#wp_sudo_activity .wp-sudo-policy-heading {
	margin: 0;
	padding: 0;
	border: 0;
}
#wp_sudo_activity .wp-sudo-policy-mode.button {
	padding: 0 8px;
	font-size: 11px;
	font-weight: 600;
	line-height: 20px;
	height: 22px;
	min-height: 22px;
}
#wp_sudo_activity .wp-sudo-policy-mode.button:hover,
#wp_sudo_activity .wp-sudo-policy-mode.button:focus {
	text-decoration: none;
}
#wp_sudo_activity .wp-sudo-policy-mode.button.is-normal {
	border-color: #badfc5;
	background: #eaf9ef;
	color: #237544;
}
#wp_sudo_activity .wp-sudo-policy-mode.button.is-headless-friendly {
	border-color: #c2d5fb;
	background: #e7f0ff;
	color: #2e5fa9;
}
#wp_sudo_activity .wp-sudo-policy-mode.button.is-custom {
	border-color: #f3e5b4;
	background: #fff9e8;
	color: #7a6115;
}
#wp_sudo_activity .wp-sudo-policy-mode.button.is-lockdown {
	border-color: #efcfcf;
	background: #fbeeee;
	color: #8c2f2f;
}
#wp_sudo_activity .wp-sudo-policy-table {
	margin-top: 0.35em;
}
#wp_sudo_activity .wp-sudo-policy-table th,
#wp_sudo_activity .wp-sudo-policy-table td {
	padding: 4px 8px;
	font-size: 12px;
	line-height: 1.25;
}
#wp_sudo_activity .wp-sudo-policy-details {
	margin: 0;
}
#wp_sudo_activity .wp-sudo-policy-toggle {
	cursor: pointer;
	color: #2271b1;
	font-size: 12px;
	font-weight: 500;
	user-select: none;
}
#wp_sudo_activity .wp-sudo-policy-toggle:hover,
#wp_sudo_activity .wp-sudo-policy-toggle:focus {
	color: #135e96;
}

/* Mobile responsive */
@media screen and (max-width: 600px) {
	#wp_sudo_activity .wp-sudo-user-gravatar {
		display: none;
	}
	#wp_sudo_activity .wp-sudo-user-secondary .wp-sudo-fullname,
	#wp_sudo_activity .wp-sudo-user-secondary .wp-sudo-displayname {
		display: none;
	}
	#wp_sudo_activity .wp-sudo-event-filters {
		gap: 3px;
		justify-content: flex-start;
	}
	#wp_sudo_activity .wp-sudo-empty-container {
		grid-template-columns: 1fr;
		gap: 0.75em;
	}
	#wp_sudo_activity .wp-sudo-empty-status {
		min-height: 0;
	}
}
</style>
<script>
(function() {
	var widget = document.getElementById('wp_sudo_activity');
	if (!widget) return;

	var table = widget.querySelector('.wp-sudo-events-table');
	if (!table) return;

	var tbody = table.querySelector('tbody');
	if (!tbody) return;

	var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
	if (!rows.length) return;

	var timeSelect = widget.querySelector('select[data-filter="time"]');
	var eventSelect = widget.querySelector('select[data-filter="event"]');
	var surfaceSelect = widget.querySelector('select[data-filter="surface"]');
	var sortButtons = widget.querySelectorAll('.wp-sudo-sort-button');
	var sortKey = 'time';
	var sortDir = 'desc';

	function updateSortUi() {
		for (var i = 0; i < sortButtons.length; i++) {
			var btn = sortButtons[i];
			var key = btn.getAttribute('data-sort-key') || '';
			var th = btn.closest('th');
			if (!th) continue;
			if (key === sortKey) {
				th.setAttribute('aria-sort', sortDir === 'asc' ? 'ascending' : 'descending');
			} else {
				th.setAttribute('aria-sort', 'none');
			}
		}
	}

	function compareRows(a, b) {
		if (sortKey === 'time') {
			var ta = parseInt(a.getAttribute('data-time') || '0', 10);
			var tb = parseInt(b.getAttribute('data-time') || '0', 10);
			return sortDir === 'asc' ? ta - tb : tb - ta;
		}

		var attr = 'data-sort-' + sortKey;
		var va = (a.getAttribute(attr) || '').toLowerCase();
		var vb = (b.getAttribute(attr) || '').toLowerCase();

		if (va === vb) {
			var fallbackA = parseInt(a.getAttribute('data-time') || '0', 10);
			var fallbackB = parseInt(b.getAttribute('data-time') || '0', 10);
			return fallbackB - fallbackA;
		}

		if (sortDir === 'asc') {
			return va.localeCompare(vb);
		}

		return vb.localeCompare(va);
	}

	function sortRows() {
		var sortedRows = rows.slice().sort(compareRows);
		for (var i = 0; i < sortedRows.length; i++) {
			tbody.appendChild(sortedRows[i]);
		}

		var noMatch = tbody.querySelector('.wp-sudo-no-match');
		if (noMatch) {
			tbody.appendChild(noMatch);
		}
	}

	function filterRows() {
		var now = Math.floor(Date.now() / 1000);
		var periods = { 'all': 0, '24h': 86400, '7d': 604800 };
		var filterTime = timeSelect ? timeSelect.value : 'all';
		var filterEvent = eventSelect ? eventSelect.value : 'all';
		var filterSurface = surfaceSelect ? surfaceSelect.value : 'all';
		var visible = 0;

		for (var i = 0; i < rows.length; i++) {
			var row = rows[i];
			var rowTime = parseInt(row.getAttribute('data-time'), 10) || 0;
			var rowEvent = row.getAttribute('data-event') || '';
			var rowSurface = row.getAttribute('data-surface') || '';
			var show = true;

			if (filterTime !== 'all' && periods[filterTime]) {
				if (now - rowTime > periods[filterTime]) {
					show = false;
				}
			}
			if (show && filterEvent !== 'all' && rowEvent !== filterEvent) {
				show = false;
			}
			if (show && filterSurface !== 'all' && rowSurface !== filterSurface) {
				show = false;
			}

			row.style.display = show ? '' : 'none';
			if (show) visible++;
		}

		/* Show/hide "no matching events" row. */
		var noMatch = tbody.querySelector('.wp-sudo-no-match');
		if (visible === 0) {
			if (!noMatch) {
				noMatch = document.createElement('tr');
				noMatch.className = 'wp-sudo-no-match';
				noMatch.innerHTML = '<td colspan="5" style="text-align:center;color:#646970;">No matching events</td>';
				tbody.appendChild(noMatch);
			}
			noMatch.style.display = '';
		} else if (noMatch) {
			noMatch.style.display = 'none';
		}

		sortRows();
	}

	if (timeSelect) timeSelect.addEventListener('change', filterRows);
	if (eventSelect) eventSelect.addEventListener('change', filterRows);
	if (surfaceSelect) surfaceSelect.addEventListener('change', filterRows);
	for (var i = 0; i < sortButtons.length; i++) {
		sortButtons[i].addEventListener('click', function() {
			var clickedKey = this.getAttribute('data-sort-key') || '';
			var defaultDir = this.getAttribute('data-default-dir') || 'asc';

			if (clickedKey === sortKey) {
				sortDir = sortDir === 'asc' ? 'desc' : 'asc';
			} else {
				sortKey = clickedKey;
				sortDir = defaultDir;
			}

			updateSortUi();
			sortRows();
		});
	}

	/* Initial filter application. */
	updateSortUi();
	filterRows();
})();
</script>
		<?php
	}
}
