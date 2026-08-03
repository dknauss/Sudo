<?php
/**
 * Plugin Name: Option churn probe (measurement only)
 * Description: Records every option write with its name and the request that
 *              caused it. Answers the question the census left open: is the
 *              option name a sufficient signal to gate on, or do the
 *              security-relevant options genuinely churn during ordinary
 *              admin use?
 *
 * `CENSUS.md` states option writes have no answer, on the grounds that Core
 * and plugins write options constantly so a deliberate change cannot be told
 * from incidental churn. That was asserted, never measured. If the dangerous
 * options never appear in a no-op crawl, the claim is too pessimistic and a
 * named-option seam is viable.
 *
 * Logs to a file, not an option — writing an option from an option-write
 * hook would recurse and would also pollute the very measurement.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

const OPTION_PROBE_LOG = '/tmp/option-churn.jsonl';

/**
 * Options whose mutation is security-relevant, drawn from what an attacker
 * would actually want rather than from what happens to be easy to gate.
 *
 * siteurl/home       — redirect the site, or load its assets from a
 *                      hostile origin
 * users_can_register
 *   + default_role   — together, self-service administrator accounts
 * active_plugins     — a DIRECT write path to code execution that does not
 *                      pass through activate_plugin()
 * template/stylesheet— switch the executing theme
 * admin_email        — receives recovery and notification mail
 * mailserver_*       — mail interception
 */
const OPTION_PROBE_DANGEROUS = array(
	'siteurl',
	'home',
	'users_can_register',
	'default_role',
	'active_plugins',
	'template',
	'stylesheet',
	'admin_email',
	'mailserver_url',
	'mailserver_login',
	'mailserver_pass',
);

function option_probe_record( string $hook, string $option ): void {
	$row = array(
		'phase'     => isset( $_SERVER['HTTP_X_PROBE_PHASE'] ) ? (string) $_SERVER['HTTP_X_PROBE_PHASE'] : 'unlabelled',
		'hook'      => $hook,
		'option'    => $option,
		'dangerous' => in_array( $option, OPTION_PROBE_DANGEROUS, true ) ? 1 : 0,
		'uri'       => isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '(cli)',
	);

	file_put_contents( OPTION_PROBE_LOG, wp_json_encode( $row ) . "\n", FILE_APPEND );
}

add_action(
	'updated_option',
	static function ( $option ) {
		option_probe_record( 'updated_option', is_string( $option ) ? $option : gettype( $option ) );
	},
	1,
	1
);

add_action(
	'added_option',
	static function ( $option ) {
		option_probe_record( 'added_option', is_string( $option ) ? $option : gettype( $option ) );
	},
	1,
	1
);
