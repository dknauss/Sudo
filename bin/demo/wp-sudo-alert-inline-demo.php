<?php
/**
 * WP Sudo — Inline Alert Demo (companion to the critical-event alert bridge)
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ DEMO / REFERENCE ONLY — DO NOT DEPLOY TO A PRODUCTION SITE.              │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * `bridges/wp-sudo-critical-alert-bridge.php` notifies a human by EMAIL when a
 * high-severity audit hook fires. WordPress Playground (and other sandboxes)
 * disable PHP outbound network, so `wp_mail()` silently goes nowhere and the
 * "trigger a tamper/escalation/lockout → watch the alert fire" story shows
 * nothing. This companion makes each composed alert visible IN wp-admin instead.
 *
 * It is the concrete realization of the bridge's documented use case: "capture
 * the composed alert for inline display where outbound network is unavailable."
 *
 * How it works:
 *
 * - It listens on the bridge's ADDITIVE `wp_sudo_critical_alert_dispatched`
 *   action — NOT the `wp_sudo_critical_alert_dispatch` replace-filter. The
 *   additive action always fires alongside the (here undeliverable) email, so
 *   this file observes without suppressing the bridge's real dispatch path. That
 *   means dropping this demo next to a real bridge cannot silently kill alert
 *   email, and cannot poison a downstream Slack/webhook dispatcher.
 * - Each composed alert is appended RAW to a small rolling site transient
 *   (newest {@see WP_SUDO_ALERT_INLINE_DEMO_CAP} kept, FIFO). On the next
 *   wp-admin page load it is rendered — escaped at output — as an admin notice,
 *   then the buffer is drained so each alert shows exactly once.
 * - Because it is a DEMO, it also relaxes the bridge's own flood protection
 *   (`wp_sudo_critical_alert_throttle` and `wp_sudo_critical_alert_hourly_cap`
 *   → 0) so repeatedly re-triggering the SAME event during a live walkthrough
 *   keeps surfacing instead of being deduped for an hour. A real deployment
 *   must NOT do this — that throttling exists to stop attacker-driven floods.
 *
 * Demo tip: the alert is composed on `shutdown` of the triggering request and
 * rendered on the NEXT admin page load — after triggering, reload/navigate in
 * wp-admin to see the panel. Single-site oriented: network-scope events render
 * on the triggering site only.
 *
 * @package WP_Sudo_Demo
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_SUDO_ALERT_INLINE_DEMO_KEY' ) ) {
	define( 'WP_SUDO_ALERT_INLINE_DEMO_KEY', '_wp_sudo_alert_inline_demo' );
}

if ( ! defined( 'WP_SUDO_ALERT_INLINE_DEMO_CAP' ) ) {
	define( 'WP_SUDO_ALERT_INLINE_DEMO_CAP', 20 );
}

if ( ! function_exists( 'wp_sudo_alert_inline_demo_capture' ) ) {
	/**
	 * Buffer a dispatched alert for inline display. Stores the alert RAW; the
	 * renderer escapes at output. Trims to the newest CAP entries (FIFO).
	 *
	 * @param array<string, mixed> $event Composed event from the alert bridge.
	 * @return void
	 */
	function wp_sudo_alert_inline_demo_capture( array $event ): void {
		$buffer = get_transient( WP_SUDO_ALERT_INLINE_DEMO_KEY );
		if ( ! is_array( $buffer ) ) {
			$buffer = array();
		}

		$buffer[] = array(
			'subject' => isset( $event['subject'] ) ? (string) $event['subject'] : 'Critical event',
			'body'    => isset( $event['body'] ) ? (string) $event['body'] : '',
			'scope'   => isset( $event['scope'] ) ? (string) $event['scope'] : 'site',
			'time'    => gmdate( 'c' ),
		);

		if ( count( $buffer ) > WP_SUDO_ALERT_INLINE_DEMO_CAP ) {
			$buffer = array_slice( $buffer, -WP_SUDO_ALERT_INLINE_DEMO_CAP );
		}

		set_transient( WP_SUDO_ALERT_INLINE_DEMO_KEY, $buffer, HOUR_IN_SECONDS );
	}
}

if ( ! function_exists( 'wp_sudo_alert_inline_demo_render' ) ) {
	/**
	 * Render buffered alerts as admin notices, then drain the buffer so each
	 * alert shows exactly once. Only users who can manage the site see them.
	 *
	 * @return void
	 */
	function wp_sudo_alert_inline_demo_render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$buffer = get_transient( WP_SUDO_ALERT_INLINE_DEMO_KEY );
		if ( ! is_array( $buffer ) || array() === $buffer ) {
			return;
		}

		// Drain first so a fatal mid-render can't wedge the notice permanently.
		delete_transient( WP_SUDO_ALERT_INLINE_DEMO_KEY );

		foreach ( $buffer as $entry ) {
			$subject = isset( $entry['subject'] ) ? (string) $entry['subject'] : 'Critical event';
			$body    = isset( $entry['body'] ) ? (string) $entry['body'] : '';
			$time    = isset( $entry['time'] ) ? (string) $entry['time'] : '';

			$line = '🔔 WP Sudo alert emitted: ' . $subject;
			if ( '' !== $body ) {
				$line .= ' — ' . $body;
			}
			if ( '' !== $time ) {
				$line .= ' (' . $time . ' UTC)';
			}

			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html( $line )
			);
		}
	}
}

add_action( 'wp_sudo_critical_alert_dispatched', 'wp_sudo_alert_inline_demo_capture', 10, 1 );
add_action( 'admin_notices', 'wp_sudo_alert_inline_demo_render' );

// DEMO ONLY: disable the bridge's dedupe window and hourly cap so a live
// walkthrough can re-trigger the same event and keep seeing it. Never do this
// on a production site — the throttling defends against attacker-driven floods.
add_filter( 'wp_sudo_critical_alert_throttle', '__return_zero' );
add_filter( 'wp_sudo_critical_alert_hourly_cap', '__return_zero' );
