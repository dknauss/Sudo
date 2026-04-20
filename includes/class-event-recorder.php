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
 * - wp_sudo_action_allowed (policy: non-interactive request permitted)
 * - wp_sudo_action_passed (feature: gated action succeeds during active session)
 * - wp_sudo_action_replayed (flow: stashed request replayed after reauth)
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
		'wp_sudo_lockout'         => 3, // user_id, attempts, ip.
		'wp_sudo_action_gated'    => 3, // user_id, rule_id, surface.
		'wp_sudo_action_blocked'  => 3, // user_id, rule_id, surface.
		'wp_sudo_action_allowed'  => 3, // user_id, rule_id, surface.
		'wp_sudo_action_passed'   => 3, // user_id, rule_id, surface.
		'wp_sudo_action_replayed' => 2, // user_id, rule_id.
	);

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
}
