#!/usr/bin/env php
<?php
/**
 * Demo script: Generate sample events for Dashboard Widget verification.
 *
 * Run via WP-CLI:
 *   wp eval-file bin/demo-events.php
 *   wp eval-file bin/demo-events.php --count=20
 *   wp eval-file bin/demo-events.php --clear
 *
 * This script fires real audit hooks, which Event_Recorder captures and
 * writes to Event_Store. Use it to populate the Dashboard Widget for
 * manual/demo verification.
 *
 * @package WP_Sudo
 * @since   3.0.0
 */

// Abort if not running under WP-CLI.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output, not browser.
	fwrite( STDERR, "Error: This script must be run via WP-CLI.\n" );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output, not browser.
	fwrite( STDERR, "Usage: wp eval-file bin/demo-events.php [--count=N] [--clear]\n" );
	exit( 1 );
}

use WP_Sudo\Event_Store;

// Parse arguments.
$args  = $GLOBALS['argv'] ?? array();
$count = 10;
$clear = false;

foreach ( $args as $arg ) {
	if ( preg_match( '/^--count=(\d+)$/', $arg, $m ) ) {
		$count = max( 1, min( 100, (int) $m[1] ) );
	}
	if ( '--clear' === $arg ) {
		$clear = true;
	}
}

// Ensure table exists.
Event_Store::maybe_create_table();

// Clear mode: prune all events and exit.
if ( $clear ) {
	$deleted = Event_Store::prune( 0 ); // 0 days = delete everything.
	WP_CLI::success( "Cleared {$deleted} events from the event store." );
	exit( 0 );
}

// Get current user for realistic events.
$user_id = get_current_user_id();
if ( ! $user_id ) {
	$user_id = 1; // Fallback to admin.
}

// Sample data pools.
$surfaces = array( 'admin', 'ajax', 'rest', 'cli', 'cron', 'xmlrpc' );
$events   = array(
	'action_gated'    => 0.4,  // 40% gated (redirected to challenge).
	'action_blocked'  => 0.25, // 25% blocked (non-interactive denial).
	'action_allowed'  => 0.2,  // 20% allowed (unrestricted policy).
	'action_replayed' => 0.1,  // 10% replayed (post-reauth).
	'lockout'         => 0.05, // 5% lockouts.
);

$rule_ids = array(
	'plugin.activate',
	'plugin.deactivate',
	'plugin.delete',
	'plugin.install',
	'theme.activate',
	'theme.delete',
	'user.delete',
	'user.role_change',
	'options.update',
	'connectors.update_credentials',
);

$ips = array(
	'192.168.1.100',
	'10.0.0.50',
	'172.16.0.25',
	'203.0.113.42',
	'198.51.100.77',
);

/**
 * Weighted random selection.
 *
 * @param array $weights Associative array of value => weight.
 * @return string
 */
function weighted_random( array $weights ): string {
	$rand = wp_rand() / mt_getrandmax();
	$sum  = 0;
	foreach ( $weights as $value => $weight ) {
		$sum += $weight;
		if ( $rand <= $sum ) {
			return $value;
		}
	}
	return array_key_first( $weights );
}

WP_CLI::log( "Generating {$count} demo events..." );

$generated = array(
	'action_gated'    => 0,
	'action_blocked'  => 0,
	'action_allowed'  => 0,
	'action_replayed' => 0,
	'lockout'         => 0,
);

for ( $i = 0; $i < $count; $i++ ) {
	$event   = weighted_random( $events );
	$surface = $surfaces[ array_rand( $surfaces ) ];
	$rule_id = $rule_ids[ array_rand( $rule_ids ) ];
	$ip      = $ips[ array_rand( $ips ) ];

	// Fire the appropriate audit hook (Event_Recorder will capture it).
	switch ( $event ) {
		case 'lockout':
			$attempts = wp_rand( 5, 10 );
			/**
			 * Fires when a user is locked out due to failed attempts.
			 *
			 * @param int    $user_id  User ID.
			 * @param int    $attempts Number of failed attempts.
			 * @param string $ip       IP address.
			 */
			do_action( 'wp_sudo_lockout', $user_id, $attempts, $ip );
			break;

		case 'action_gated':
			/**
			 * Fires when a gated action redirects to reauthentication.
			 *
			 * @param int    $user_id User ID.
			 * @param string $rule_id Rule ID.
			 * @param string $surface Surface.
			 */
			do_action( 'wp_sudo_action_gated', $user_id, $rule_id, $surface );
			break;

		case 'action_blocked':
			/**
			 * Fires when a gated action is blocked on non-interactive surface.
			 *
			 * @param int    $user_id User ID.
			 * @param string $rule_id Rule ID.
			 * @param string $surface Surface.
			 */
			do_action( 'wp_sudo_action_blocked', $user_id, $rule_id, $surface );
			break;

		case 'action_allowed':
			/**
			 * Fires when a gated action is allowed under Unrestricted policy.
			 *
			 * @param int    $user_id User ID.
			 * @param string $rule_id Rule ID.
			 * @param string $surface Surface.
			 */
			do_action( 'wp_sudo_action_allowed', $user_id, $rule_id, $surface );
			break;

		case 'action_replayed':
			/**
			 * Fires when a stashed request is replayed after reauth.
			 *
			 * @param int    $user_id User ID.
			 * @param string $rule_id Rule ID.
			 */
			do_action( 'wp_sudo_action_replayed', $user_id, $rule_id );
			break;
	}

	$generated[ $event ]++;
}

// Summary.
WP_CLI::success( "Generated {$count} demo events:" );
foreach ( $generated as $event => $n ) {
	if ( $n > 0 ) {
		WP_CLI::log( "  - {$event}: {$n}" );
	}
}

// Show recent events.
$recent = Event_Store::recent( 5 );
if ( ! empty( $recent ) ) {
	WP_CLI::log( "\nMost recent events:" );
	foreach ( $recent as $row ) {
		$time = $row['created_at'] ?? 'unknown';
		$evt  = $row['event'] ?? 'unknown';
		$rule = $row['rule_id'] ?? '';
		WP_CLI::log( "  [{$time}] {$evt}" . ( $rule ? " ({$rule})" : '' ) );
	}
}

WP_CLI::log( "\nView the Dashboard Widget at: /wp-admin/ (scroll to WP Sudo Activity)" );
