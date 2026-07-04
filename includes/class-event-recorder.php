<?php
/**
 * Event_Recorder class.
 *
 * Subscribes to the MVP subset of WP Sudo audit hooks and records events
 * to the Event_Store for dashboard widget display.
 *
 * @package WP_Sudo
 * @since   2.15.0
 */

namespace WP_Sudo;

/**
 * Records a subset of WP Sudo audit events for the dashboard widget.
 *
 * Subscribes only to the hooks that provide immediate operator value:
 * - wp_sudo_lockout (security: rate limit triggered)
 * - wp_sudo_action_gated (flow: user redirected to reauthentication)
 * - wp_sudo_action_blocked (policy: non-interactive request denied)
 * - wp_sudo_escalation_blocked (security: high-severity admin-escalation alarm)
 * - wp_sudo_action_allowed (policy: non-interactive request permitted)
 * - wp_sudo_action_passed (feature: gated action succeeds during active session)
 * - wp_sudo_action_replayed (flow: stashed request replayed after reauth)
 * - wp_sudo_recovery_mode_active (security: break-glass access, sampled hourly)
 * - wp_sudo_session_revoked (security: operator revoked sudo session(s) via UI)
 *
 * @since 2.15.0
 */
class Event_Recorder {

	/**
	 * MVP hook set with accepted args count.
	 *
	 * @var array<string, int>
	 */
	private const HOOKS = array(
		'wp_sudo_lockout'              => 3, // user_id, attempts, ip.
		'wp_sudo_action_gated'         => 3, // user_id, rule_id, surface.
		'wp_sudo_action_blocked'       => 3, // user_id, rule_id, surface.
		'wp_sudo_escalation_blocked'   => 3, // user_id, rule_id, surface (high-severity admin-escalation alarm).
		'wp_sudo_action_allowed'       => 3, // user_id, rule_id, surface.
		'wp_sudo_action_passed'        => 3, // user_id, rule_id, surface.
		'wp_sudo_action_replayed'      => 2, // user_id, rule_id.
		'wp_sudo_recovery_mode_active' => 1, // user_id.
		'wp_sudo_session_revoked'      => 4, // target_user_id, revoker_user_id, reason, site_id.
	);

	/**
	 * Transient prefix for per-user recovery_mode event sampling.
	 *
	 * @var string
	 */
	private const RECOVERY_THROTTLE_PREFIX = '_wp_sudo_recovery_logged_';

	/**
	 * Whether the per-request buffer is armed.
	 *
	 * When armed, hook callbacks enqueue rows instead of writing them
	 * synchronously, and a WordPress `shutdown` hook flushes the queue
	 * as a single bulk INSERT. This collapses N blocking round-trips
	 * per request into one, which matters on busy headless/hybrid sites
	 * where many admin users trip multiple gated actions per request.
	 *
	 * @var bool
	 */
	private static bool $buffer_armed = false;

	/**
	 * Pending event rows awaiting flush.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $pending = array();

	/**
	 * Whether the shutdown flush hook has already been registered.
	 *
	 * @var bool
	 */
	private static bool $shutdown_registered = false;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		new self();
	}

	/**
	 * Arm the per-request event buffer.
	 *
	 * After this call, hook callbacks accumulate rows in memory and a
	 * single `shutdown` hook writes them via Event_Store::bulk_insert().
	 * Idempotent: safe to call multiple times; only one shutdown handler
	 * is registered per request.
	 *
	 * @return void
	 */
	public static function arm_buffer(): void {
		if ( self::$shutdown_registered ) {
			self::$buffer_armed = true;
			return;
		}

		self::$buffer_armed        = true;
		self::$shutdown_registered = true;

		add_action( 'shutdown', array( self::class, 'flush' ), PHP_INT_MAX, 0 );
	}

	/**
	 * Flush all buffered rows via a single bulk INSERT.
	 *
	 * No-op when the buffer is empty. Clears the buffer after flushing so
	 * subsequent calls within the same request don't re-emit rows.
	 *
	 * @return void
	 */
	public static function flush(): void {
		if ( empty( self::$pending ) ) {
			return;
		}

		$rows          = self::$pending;
		self::$pending = array();

		Event_Store::bulk_insert( $rows );
	}

	/**
	 * Reset buffering state for tests.
	 *
	 * @return void
	 */
	public static function reset_buffer(): void {
		self::$buffer_armed        = false;
		self::$shutdown_registered = false;
		self::$pending             = array();
	}

	/**
	 * Route an event row to either the per-request buffer or the direct
	 * single-row insert path, depending on whether the buffer is armed.
	 *
	 * @param array<string, mixed> $row Event row payload.
	 * @return void
	 */
	private static function enqueue( array $row ): void {
		if ( self::$buffer_armed ) {
			self::$pending[] = $row;
			return;
		}

		Event_Store::insert( $row );
	}

	/**
	 * Constructor. Registers hooks.
	 */
	public function __construct() {
		$this->register_hooks();
	}

	/**
	 * Register action hooks for the MVP event subset.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		foreach ( self::HOOKS as $hook => $accepted_args ) {
			$callback_method = 'on_' . str_replace( 'wp_sudo_', '', $hook );
			add_action( $hook, array( self::class, $callback_method ), 10, $accepted_args );
		}
	}

	/**
	 * Handle wp_sudo_lockout event.
	 *
	 * Fired when a user exceeds the failed attempt threshold.
	 *
	 * @param int    $user_id  User ID.
	 * @param int    $attempts Number of failed attempts.
	 * @param string $ip       IP address that triggered the lockout.
	 * @return void
	 */
	public static function on_lockout( int $user_id, int $attempts, string $ip ): void {
		self::enqueue(
			array(
				'user_id' => $user_id,
				'event'   => 'lockout',
				'rule_id' => '',
				'surface' => '',
				'ip'      => $ip,
				'context' => array( 'attempts' => $attempts ),
			)
		);
	}

	/**
	 * Handle wp_sudo_action_gated event.
	 *
	 * Fired when a gated action redirects the user to reauthentication.
	 *
	 * @param int    $user_id User ID.
	 * @param string $rule_id Action Registry rule ID.
	 * @param string $surface Surface (admin, ajax, rest, etc.).
	 * @return void
	 */
	public static function on_action_gated( int $user_id, string $rule_id, string $surface ): void {
		self::enqueue(
			array(
				'user_id' => $user_id,
				'event'   => 'action_gated',
				'rule_id' => $rule_id,
				'surface' => $surface,
			)
		);
	}

	/**
	 * Handle wp_sudo_action_blocked event.
	 *
	 * Fired when a gated action is blocked on a non-interactive surface.
	 *
	 * @param int    $user_id User ID.
	 * @param string $rule_id Action Registry rule ID.
	 * @param string $surface Surface (cli, cron, xmlrpc, rest_app_password, etc.).
	 * @return void
	 */
	public static function on_action_blocked( int $user_id, string $rule_id, string $surface ): void {
		self::enqueue(
			array(
				'user_id' => $user_id,
				'event'   => 'action_blocked',
				'rule_id' => $rule_id,
				'surface' => $surface,
			)
		);
	}

	/**
	 * Handle wp_sudo_escalation_blocked event.
	 *
	 * Fired by the admin-escalation guard when a NEW administrator/super-admin
	 * grant, admin creation, or admin deletion is blocked (or alarmed) because no
	 * sudo session is active — a high-severity signal of a likely
	 * privilege-escalation attempt. Recorded as a distinct `escalation_blocked`
	 * event (with a high-severity context marker) so the activity dashboard and
	 * external alerting can surface it apart from routine policy denials.
	 *
	 * @since 4.1.0
	 *
	 * @param int    $user_id Target user being granted/deleted.
	 * @param string $rule_id One of user.promote, user.super_admin, user.delete.
	 * @param string $surface Detected request surface.
	 * @return void
	 */
	public static function on_escalation_blocked( int $user_id, string $rule_id, string $surface ): void {
		self::enqueue(
			array(
				'user_id' => $user_id,
				'event'   => 'escalation_blocked',
				'rule_id' => $rule_id,
				'surface' => $surface,
				'context' => array( 'severity' => 'high' ),
			)
		);
	}

	/**
	 * Handle wp_sudo_action_allowed event.
	 *
	 * Fired when a gated action is permitted on an Unrestricted surface.
	 *
	 * @param int    $user_id User ID.
	 * @param string $rule_id Action Registry rule ID.
	 * @param string $surface Surface (cli, cron, xmlrpc, rest_app_password, etc.).
	 * @return void
	 */
	public static function on_action_allowed( int $user_id, string $rule_id, string $surface ): void {
		self::enqueue(
			array(
				'user_id' => $user_id,
				'event'   => 'action_allowed',
				'rule_id' => $rule_id,
				'surface' => $surface,
			)
		);
	}

	/**
	 * Handle wp_sudo_action_replayed event.
	 *
	 * Fired when a stashed request is replayed after successful reauthentication.
	 *
	 * @param int    $user_id User ID.
	 * @param string $rule_id Action Registry rule ID.
	 * @return void
	 */
	public static function on_action_replayed( int $user_id, string $rule_id ): void {
		self::enqueue(
			array(
				'user_id' => $user_id,
				'event'   => 'action_replayed',
				'rule_id' => $rule_id,
				'surface' => '',
			)
		);
	}

	/**
	 * Handle wp_sudo_action_passed event.
	 *
	 * Fired when a gated action passes through due to an active sudo session.
	 * Logging is enabled by default and may only be disabled by explicit
	 * code-level override (constant/filter).
	 *
	 * @since 3.0.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $rule_id Action Registry rule ID.
	 * @param string $surface Surface (admin, ajax, rest, wpgraphql).
	 * @return void
	 */
	public static function on_action_passed( int $user_id, string $rule_id, string $surface ): void {
		if ( ! Admin::is_passed_event_logging_enabled() ) {
			return;
		}

		self::enqueue(
			array(
				'user_id' => $user_id,
				'event'   => 'action_passed',
				'rule_id' => $rule_id,
				'surface' => $surface,
			)
		);
	}

	/**
	 * Handle wp_sudo_recovery_mode_active event.
	 *
	 * Fired (unthrottled) whenever the Sudo admin surface is accessed while
	 * break-glass recovery mode is active. To keep the events table from
	 * flooding — recovery mode can stay on across many page loads and users —
	 * the internal record is SAMPLED to at most one row per user per hour.
	 * External listeners on the hook still observe every occurrence and do
	 * their own deduplication.
	 *
	 * @since 3.4.0
	 *
	 * @param int $user_id User who accessed the Sudo surface under recovery mode.
	 * @return void
	 */
	public static function on_recovery_mode_active( int $user_id ): void {
		$throttle_key = self::RECOVERY_THROTTLE_PREFIX . $user_id;

		if ( get_transient( $throttle_key ) ) {
			return;
		}

		set_transient( $throttle_key, 1, HOUR_IN_SECONDS );

		self::enqueue(
			array(
				'user_id' => $user_id,
				'event'   => 'recovery_mode',
				'rule_id' => '',
				'surface' => '',
			)
		);
	}

	/**
	 * Handle wp_sudo_session_revoked event.
	 *
	 * Fired when an operator revokes another user's sudo session from the
	 * Users-list row action, or every active session via the revoke-all
	 * control (batch firings carry a zero target). The reason tag is stored
	 * in the surface column — the dashboard widget's event query omits the
	 * context payload, so surface is the only slot that keeps revocation
	 * provenance visible in the widget. The operator lands in context for
	 * full-row consumers (Event_Store::recent(), external log bridges).
	 *
	 * The $site_id hook argument is accepted for signature parity but not
	 * written: Event_Store stamps the current site itself at insert (or
	 * buffered shutdown-flush) time. That matches the hook value today
	 * because both fire sites are admin-post handlers that do not
	 * switch_to_blog() before shutdown — revisit this if a network-admin
	 * revoke surface is ever added.
	 *
	 * WP-CLI revocations (`wp sudo revoke`) do not fire this hook and are
	 * therefore not recorded here.
	 *
	 * @since 4.5.0
	 *
	 * @param int    $target_user_id  Revoked user (0 for a batch revoke-all).
	 * @param int    $revoker_user_id Operator who performed the revocation.
	 * @param string $reason          Reason/surface tag (users_list_row_action, revoke_all_ui).
	 * @param int    $site_id         Site context reported by the fire site (unused; see above).
	 * @return void
	 */
	public static function on_session_revoked( int $target_user_id, int $revoker_user_id, string $reason, int $site_id ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Signature parity with the 4-arg hook; Event_Store stamps the site itself.
		self::enqueue(
			array(
				'user_id' => $target_user_id,
				'event'   => 'session_revoked',
				'rule_id' => '',
				'surface' => $reason,
				'context' => array( 'revoked_by' => $revoker_user_id ),
			)
		);
	}
}
