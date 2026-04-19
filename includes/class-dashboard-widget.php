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
			array( self::class, 'render' )
		);
	}

	/**
	 * Render the widget content.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::render_active_sessions();
		self::render_recent_events();
		self::render_policy_summary();
		self::render_inline_styles();
	}

	/**
	 * Maximum users to display in active sessions list.
	 *
	 * @var int
	 */
	private const MAX_DISPLAY_USERS = 5;

	/**
	 * Default settings for display.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = array(
		'session_duration'         => 15,
		'log_passthrough'          => false,
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
		$users = get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_wp_sudo_expires',
						'value'   => time(),
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
				'fields'     => 'ID',
			)
		);

		$count = count( $users );

		echo '<h3>' . esc_html__( 'Active Sessions', 'wp-sudo' ) . '</h3>';

		if ( 0 === $count ) {
			echo '<p class="wp-sudo-empty">' . esc_html__( 'No active sessions', 'wp-sudo' ) . '</p>';
			return;
		}

		// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- Simple count.
		echo '<p class="wp-sudo-active-count"><strong>' . esc_html( sprintf( _n( '%d active session', '%d active sessions', $count, 'wp-sudo' ), $count ) ) . '</strong></p>';

		echo '<ul class="wp-sudo-user-list">';
		$displayed = 0;
		foreach ( $users as $user_id ) {
			if ( $displayed >= self::MAX_DISPLAY_USERS ) {
				break;
			}

			$user = get_userdata( (int) $user_id );
			if ( ! $user ) {
				continue;
			}

			$expires      = (int) get_user_meta( (int) $user_id, '_wp_sudo_expires', true );
			$time_left    = human_time_diff( time(), $expires );
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

			echo '<li class="wp-sudo-user-row">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar is trusted WP function.
			echo '<div class="wp-sudo-user-gravatar">' . $avatar . '</div>';
			echo '<div class="wp-sudo-user-info">';
			echo '<div class="wp-sudo-user-primary">';
			echo '<span class="wp-sudo-username">' . esc_html( $user_login ) . '</span>';
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
			++$displayed;
		}
		echo '</ul>';

		if ( $count > self::MAX_DISPLAY_USERS ) {
			// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- Simple count.
			echo '<p><em>' . esc_html( sprintf( __( '+ %d more', 'wp-sudo' ), $count - self::MAX_DISPLAY_USERS ) ) . '</em></p>';
		}
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
	 * Render recent events section.
	 *
	 * Displays the last 10 events from Event_Store in a table with filters.
	 *
	 * @return void
	 */
	private static function render_recent_events(): void {
		$settings = get_option( 'wp_sudo_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$log_passthrough = ! empty( $settings['log_passthrough'] );

		// Ensure the events table exists before querying.
		Event_Store::maybe_create_table();
		$events = Event_Store::recent( 50 ); // Get more for client-side filtering.

		echo '<h3>' . esc_html__( 'Recent Events', 'wp-sudo' ) . '</h3>';

		// Filter controls - all in a single row.
		echo '<div class="wp-sudo-event-filters">';

		// Time dropdown.
		echo '<select class="wp-sudo-filter-select" data-filter="time">';
		echo '<option value="all">' . esc_html__( 'All time', 'wp-sudo' ) . '</option>';
		echo '<option value="24h" selected>' . esc_html__( 'Last 24 hours', 'wp-sudo' ) . '</option>';
		echo '<option value="7d">' . esc_html__( 'Last 7 days', 'wp-sudo' ) . '</option>';
		echo '</select>';

		// Event type dropdown.
		echo '<select class="wp-sudo-filter-select" data-filter="event">';
		echo '<option value="all">' . esc_html__( 'All events', 'wp-sudo' ) . '</option>';
		echo '<option value="lockout">' . esc_html__( 'Lockout', 'wp-sudo' ) . '</option>';
		echo '<option value="action_gated">' . esc_html__( 'Gated', 'wp-sudo' ) . '</option>';
		echo '<option value="action_blocked">' . esc_html__( 'Blocked', 'wp-sudo' ) . '</option>';
		echo '<option value="action_allowed">' . esc_html__( 'Allowed', 'wp-sudo' ) . '</option>';
		echo '<option value="action_passed"' . ( $log_passthrough ? '' : ' disabled' ) . '>';
		echo esc_html__( 'Passed', 'wp-sudo' );
		if ( ! $log_passthrough ) {
			echo ' *';
		}
		echo '</option>';
		echo '<option value="action_replayed">' . esc_html__( 'Replayed', 'wp-sudo' ) . '</option>';
		echo '</select>';

		// Surface dropdown.
		echo '<select class="wp-sudo-filter-select" data-filter="surface">';
		echo '<option value="all">' . esc_html__( 'All surfaces', 'wp-sudo' ) . '</option>';
		echo '<option value="admin">' . esc_html__( 'Admin', 'wp-sudo' ) . '</option>';
		echo '<option value="ajax">' . esc_html__( 'AJAX', 'wp-sudo' ) . '</option>';
		echo '<option value="rest">' . esc_html__( 'REST', 'wp-sudo' ) . '</option>';
		echo '<option value="rest_app_password">' . esc_html__( 'App Password', 'wp-sudo' ) . '</option>';
		echo '<option value="cli">' . esc_html__( 'CLI', 'wp-sudo' ) . '</option>';
		echo '<option value="cron">' . esc_html__( 'Cron', 'wp-sudo' ) . '</option>';
		echo '<option value="xmlrpc">' . esc_html__( 'XML-RPC', 'wp-sudo' ) . '</option>';
		echo '<option value="wpgraphql">' . esc_html__( 'WPGraphQL', 'wp-sudo' ) . '</option>';
		echo '</select>';

		echo '</div>';

		// Pass-through notice.
		if ( ! $log_passthrough ) {
			$settings_url = esc_url( admin_url( 'options-general.php?page=wp-sudo-settings#log_passthrough' ) );
			echo '<p class="wp-sudo-filter-notice"><em>';
			echo wp_kses(
				sprintf(
					/* translators: %1$s: opening link tag, %2$s: closing link tag */
					__( '* Enable "Log Session Pass-Throughs" in %1$sSettings%2$s to track Passed events.', 'wp-sudo' ),
					'<a href="' . $settings_url . '">',
					'</a>'
				),
				array( 'a' => array( 'href' => array() ) )
			);
			echo '</em></p>';
		}

		if ( empty( $events ) ) {
			echo '<p class="wp-sudo-empty">' . esc_html__( 'No recent activity', 'wp-sudo' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped wp-sudo-events-table">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Recent sudo session events', 'wp-sudo' ) . '</caption>';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Time', 'wp-sudo' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'User', 'wp-sudo' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Event', 'wp-sudo' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Action', 'wp-sudo' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Surface', 'wp-sudo' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $events as $event ) {
			$event_obj   = is_array( $event ) ? (object) $event : $event;
			$created_at  = isset( $event_obj->created_at ) ? strtotime( (string) $event_obj->created_at ) : 0;
			$time_ago    = $created_at > 0 ? human_time_diff( $created_at, time() ) : '—';
			$user_id     = isset( $event_obj->user_id ) ? (int) $event_obj->user_id : 0;
			$user        = $user_id > 0 ? get_userdata( $user_id ) : null;
			$username    = $user && isset( $user->user_login ) ? $user->user_login : '—';
			$event_type  = isset( $event_obj->event ) ? (string) $event_obj->event : '';
			$event_label = self::EVENT_LABELS[ $event_type ] ?? $event_type;
			$rule_id     = isset( $event_obj->rule_id ) ? (string) $event_obj->rule_id : '';
			$rule_label  = self::get_rule_label( $rule_id );
			$surface     = isset( $event_obj->surface ) ? (string) $event_obj->surface : '';

			echo '<tr data-time="' . esc_attr( (string) $created_at ) . '" data-event="' . esc_attr( $event_type ) . '" data-surface="' . esc_attr( $surface ) . '">';
			echo '<td>' . esc_html( $time_ago ) . '</td>';
			echo '<td>' . esc_html( $username ) . '</td>';
			echo '<td>' . esc_html( $event_label ) . '</td>';
			echo '<td>' . esc_html( $rule_label ) . '</td>';
			echo '<td>' . esc_html( '' !== $surface ? $surface : '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Get human-readable label for a rule ID.
	 *
	 * @param string $rule_id Action Registry rule ID.
	 * @return string
	 */
	private static function get_rule_label( string $rule_id ): string {
		if ( '' === $rule_id ) {
			return '—';
		}

		$rules = Action_Registry::get_rules();
		foreach ( $rules as $rule ) {
			if ( isset( $rule['id'] ) && $rule['id'] === $rule_id ) {
				return isset( $rule['label'] ) ? (string) $rule['label'] : $rule_id;
			}
		}

		return $rule_id;
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

		echo '<h3 class="wp-sudo-policy-heading">' . esc_html__( 'Policy Summary', 'wp-sudo' ) . '</h3>';

		echo '<table class="widefat striped">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Entry-point surface policies', 'wp-sudo' ) . '</caption>';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Surface', 'wp-sudo' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Policy', 'wp-sudo' ) . '</th>';
		echo '</tr></thead><tbody>';

		$surfaces = array(
			'rest_app_password_policy' => __( 'REST App Passwords', 'wp-sudo' ),
			'cli_policy'               => __( 'CLI', 'wp-sudo' ),
			'cron_policy'              => __( 'Cron', 'wp-sudo' ),
			'xmlrpc_policy'            => __( 'XML-RPC', 'wp-sudo' ),
		);

		// Add WPGraphQL if active.
		if ( class_exists( 'WPGraphQL' ) ) {
			$surfaces['wpgraphql_policy'] = __( 'WPGraphQL', 'wp-sudo' );
		}

		foreach ( $surfaces as $key => $label ) {
			$policy       = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : (string) self::DEFAULTS[ $key ];
			$policy_label = self::POLICY_LABELS[ $policy ] ?? $policy;

			echo '<tr>';
			echo '<td>' . esc_html( $label ) . '</td>';
			echo '<td>' . esc_html( $policy_label ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
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

/* Active sessions with gravatars */
#wp_sudo_activity .wp-sudo-user-list {
	list-style: none;
	margin: 0;
	padding: 0;
}
#wp_sudo_activity .wp-sudo-user-row {
	display: flex;
	align-items: center;
	gap: 0.75em;
	padding: 0.5em 0;
	border-bottom: 1px solid #f0f0f1;
}
#wp_sudo_activity .wp-sudo-user-row:last-child {
	border-bottom: none;
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
	color: #1d2327;
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

/* Event filters — compact inline row matching WP dashboard patterns */
#wp_sudo_activity .wp-sudo-event-filters {
	display: flex;
	flex-wrap: nowrap;
	align-items: center;
	gap: 4px;
	margin-bottom: 0.5em;
}
#wp_sudo_activity .wp-sudo-event-filters select {
	font-size: 12px;
	padding: 0 4px;
	height: 24px;
	line-height: 22px;
	border: 1px solid #c3c4c7;
	border-radius: 3px;
	background: #fff;
	min-width: 0;
	max-width: 100%;
}
#wp_sudo_activity .wp-sudo-filter-notice {
	font-size: 0.75em;
	color: #646970;
	margin: 0 0 0.5em;
}

/* Policy Summary spacing */
#wp_sudo_activity .wp-sudo-policy-heading {
	margin-top: 1.5em;
	padding-top: 1em;
	border-top: 1px solid #e0e0e0;
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
	}

	if (timeSelect) timeSelect.addEventListener('change', filterRows);
	if (eventSelect) eventSelect.addEventListener('change', filterRows);
	if (surfaceSelect) surfaceSelect.addEventListener('change', filterRows);

	/* Initial filter application. */
	filterRows();
})();
</script>
		<?php
	}
}
