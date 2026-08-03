<?php
/**
 * Applies / reverts the Core effect-authorization seams in a running
 * WordPress. Runs INSIDE the container.
 *
 *   php patch.php apply
 *   php patch.php revert    # mutation test: remove only the vetoes
 *   php patch.php status
 *
 * Every seam is anchored on unique source text, never line numbers, and the
 * patcher refuses to act at all if an anchor is missing — landing silently
 * in the wrong place is worse than failing.
 */

declare( strict_types = 1 );

const SETTINGS = '/var/www/html/wp-settings.php';
const SEAM_LIB = '/var/www/html/wp-includes/effect-authorization.php';

const BEGIN = '// BEGIN effect-authorization seam';
const END   = '// END effect-authorization seam';

/**
 * Each seam: the file, an anchor unique within it, how to find the
 * insertion point relative to that anchor, a post-insert sanity check, and
 * the block itself.
 */
function seams(): array {
	return array(

		// ---- core.code.package_commit -------------------------------
		// Last point at which the operation is fully determined and
		// nothing has been written anywhere WordPress executes from.
		// $source is unpacked on disk, so the digest is over real bytes.
		'package_commit' => array(
			'file'   => '/var/www/html/wp-admin/includes/class-wp-upgrader.php',
			'anchor' => "If 'clear_working' is false",
			// Back up to the start of the comment block before the write.
			'seek'   => "\t\t/*",
			'after'  => array( 'move_dir(', 'copy_dir(' ),
			'block'  => <<<'PHP'

		// BEGIN effect-authorization seam
		if ( function_exists( 'wp_authorize_consequential_effect' ) ) {
			$cfp_extra = isset( $args['hook_extra'] ) && is_array( $args['hook_extra'] ) ? $args['hook_extra'] : array();
			$cfp_slug  = '';
			if ( isset( $cfp_extra['plugin'] ) && is_string( $cfp_extra['plugin'] ) ) {
				$cfp_slug = $cfp_extra['plugin'];
			} elseif ( isset( $cfp_extra['theme'] ) && is_string( $cfp_extra['theme'] ) ) {
				$cfp_slug = $cfp_extra['theme'];
			}

			$cfp_effect = array(
				'version' => 1,
				'id'      => 'core.code.package_commit',
				'site_id' => (int) get_current_blog_id(),
				'target'  => array(
					'type'        => isset( $cfp_extra['type'] ) && is_string( $cfp_extra['type'] ) ? $cfp_extra['type'] : 'unknown',
					'action'      => isset( $cfp_extra['action'] ) && is_string( $cfp_extra['action'] ) ? $cfp_extra['action'] : 'unknown',
					'slug'        => $cfp_slug,
					'destination' => (string) $remote_destination,
				),
				'payload' => array(
					'sha256' => wp_effect_source_digest( (string) $source ),
				),
			);

			$cfp_ok = wp_authorize_consequential_effect( $cfp_effect );
			if ( is_wp_error( $cfp_ok ) ) {
				return $cfp_ok;
			}
		}
		// END effect-authorization seam

PHP
		),

		// ---- core.identity.email_change -----------------------------
		// Inside the $update branch only: creating a user is a different
		// effect, and gating it here would fire on every new account.
		//
		// Fires ONLY when the address actually changes, which is what
		// makes the seam usable — an admin saving a profile without
		// touching the email must not be challenged, or the control is
		// abandoned within a day.
		//
		// This covers BOTH the self-service and admin-edits-another
		// paths, which is the point. Shipping WordPress requires
		// confirmation only for the former (send_confirmation_on_profile_email
		// is hooked to personal_options_update, which fires solely on
		// self-edit), leaving the more dangerous path unconfirmed. One
		// seam closes that asymmetry.
		'email_change' => array(
			'file'   => '/var/www/html/wp-includes/user.php',
			'anchor' => "\$data['user_activation_key'] = '';",
			'seek'   => "\tif ( \$update ) {",
			'after'  => array( '$wpdb->update( $wpdb->users' ),
			'block'  => <<<'PHP'

	// BEGIN effect-authorization seam
	if ( $update
		&& function_exists( 'wp_authorize_consequential_effect' )
		&& ! empty( $old_user_data )
		&& 0 !== strcasecmp( (string) $user_email, (string) $old_user_data->user_email )
	) {
		$cfp_effect = array(
			'version' => 1,
			'id'      => 'core.identity.email_change',
			'site_id' => (int) get_current_blog_id(),
			'target'  => array(
				'user_id' => (int) $user_id,
				'from'    => (string) wp_unslash( $old_user_data->user_email ),
				'to'      => (string) wp_unslash( $user_email ),
			),
		);

		$cfp_ok = wp_authorize_consequential_effect( $cfp_effect );
		if ( is_wp_error( $cfp_ok ) ) {
			return $cfp_ok;
		}
	}
	// END effect-authorization seam

PHP
		),
	);
}

function fail( string $msg ): void {
	fwrite( STDERR, "FAIL: {$msg}\n" );
	exit( 1 );
}

function lint( string $file ): array {
	$out = array();
	exec( 'php -l ' . escapeshellarg( $file ) . ' 2>&1', $out, $rc );
	return array( $rc, implode( "\n", $out ) );
}

function status(): void {
	printf( "seam library present:         %s\n", file_exists( SEAM_LIB ) ? 'yes' : 'NO' );
	printf( "loaded from wp-settings.php:  %s\n", str_contains( (string) file_get_contents( SETTINGS ), 'effect-authorization' ) ? 'yes' : 'NO' );
	foreach ( seams() as $name => $seam ) {
		$src              = (string) file_get_contents( $seam['file'] );
		list( $rc, $err ) = lint( $seam['file'] );
		printf(
			"  %-16s veto:%-4s lint:%s\n",
			$name,
			str_contains( $src, BEGIN ) ? 'yes' : 'NO',
			0 === $rc ? 'ok' : ( 'BROKEN ' . $err )
		);
	}
}

$mode = $argv[1] ?? 'status';

if ( 'status' === $mode ) {
	status();
	exit( 0 );
}

foreach ( seams() as $name => $seam ) {
	$src = file_get_contents( $seam['file'] );
	if ( false === $src ) {
		fail( "cannot read {$seam['file']}" );
	}

	if ( 'revert' === $mode ) {
		$pattern = '/\n?[ \t]*' . preg_quote( BEGIN, '/' ) . '.*?' . preg_quote( END, '/' ) . '\n/s';
		$out     = preg_replace( $pattern, "\n", $src );
		if ( null === $out ) {
			fail( "regex failed for {$name}" );
		}
		file_put_contents( $seam['file'], $out );
		echo "{$name}: veto removed\n";
		continue;
	}

	// ---- apply ----
	if ( str_contains( $src, BEGIN ) ) {
		echo "{$name}: already patched\n";
		continue;
	}

	$anchor_pos = strpos( $src, $seam['anchor'] );
	if ( false === $anchor_pos ) {
		fail( "{$name}: anchor not found — Core moved underneath this patch; refusing to guess" );
	}

	$insert_at = strrpos( substr( $src, 0, $anchor_pos ), $seam['seek'] );
	if ( false === $insert_at ) {
		fail( "{$name}: insertion point not found" );
	}

	// The guarded write must actually come after where we are inserting.
	$tail = substr( $src, $anchor_pos );
	foreach ( $seam['after'] as $needle ) {
		if ( ! str_contains( $tail, $needle ) ) {
			fail( "{$name}: guarded write '{$needle}' is not after the anchor — wrong location" );
		}
	}

	$patched = substr( $src, 0, $insert_at ) . $seam['block'] . substr( $src, $insert_at );
	file_put_contents( $seam['file'], $patched );

	list( $rc, $err ) = lint( $seam['file'] );
	if ( 0 !== $rc ) {
		file_put_contents( $seam['file'], $src ); // never leave Core broken
		fail( "{$name}: patched Core failed lint, rolled back:\n{$err}" );
	}

	echo "{$name}: veto inserted\n";
}

status();
