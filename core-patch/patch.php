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

		// ---- core.identity.{email_change,password_set} --------------
		// One seam, two effects: both land on the same user row write,
		// and a single submit can carry both.
		//
		// Inside the $update branch only: creating a user is a different
		// effect, and gating it here would fire on every new account.
		//
		// Each fires ONLY when that field actually changes. An admin
		// saving a profile without touching either must not be
		// challenged, or the control is abandoned within a day.
		//
		// Email covers BOTH the self-service and admin-edits-another
		// paths. Shipping WordPress requires confirmation only for the
		// former (send_confirmation_on_profile_email is hooked to
		// personal_options_update, which fires solely on self-edit),
		// leaving the more dangerous path unconfirmed. One seam closes
		// that asymmetry.
		//
		// Password: $user_pass is already hashed by wp_update_user()
		// before this point, and bcrypt salts differ per call, so Core's
		// own comparison two lines below means "a new password was
		// submitted" rather than "the password differs". Deliberately
		// NOT seamed at wp_set_password(): that is reached by
		// reset_password() AND by rehash-on-login (user.php:225, 308), so
		// a veto there would break ordinary authentication, quite apart
		// from the function being pluggable.
		'user_identity' => array(
			'file'   => '/var/www/html/wp-includes/user.php',
			'anchor' => "\$data['user_activation_key'] = '';",
			'seek'   => "\tif ( \$update ) {",
			'after'  => array( '$wpdb->update( $wpdb->users' ),
			'block'  => <<<'PHP'

	// BEGIN effect-authorization seam
	if ( $update && function_exists( 'wp_authorize_consequential_effect' ) && ! empty( $old_user_data ) ) {
		$cfp_effects = array();

		if ( 0 !== strcasecmp( (string) $user_email, (string) $old_user_data->user_email ) ) {
			$cfp_effects[] = array(
				'version' => 1,
				'id'      => 'core.identity.email_change',
				'site_id' => (int) get_current_blog_id(),
				'target'  => array(
					'user_id' => (int) $user_id,
					'from'    => (string) wp_unslash( $old_user_data->user_email ),
					'to'      => (string) wp_unslash( $user_email ),
				),
			);
		}

		if ( (string) $user_pass !== (string) $old_user_data->user_pass ) {
			/*
			 * No payload digest, deliberately: the payload is a secret and
			 * must not enter a descriptor that gets logged or displayed.
			 * The approval therefore authorises "set a new password for
			 * user X", not "set this exact password" -- weaker than the
			 * package seam's byte binding, unavoidable, and stated rather
			 * than glossed.
			 */
			$cfp_effects[] = array(
				'version' => 1,
				'id'      => 'core.identity.password_set',
				'site_id' => (int) get_current_blog_id(),
				'target'  => array(
					'user_id'    => (int) $user_id,
					'user_login' => (string) $old_user_data->user_login,
				),
			);
		}

		foreach ( $cfp_effects as $cfp_effect ) {
			$cfp_ok = wp_authorize_consequential_effect( $cfp_effect );
			if ( is_wp_error( $cfp_ok ) ) {
				return $cfp_ok;
			}
		}
	}
	// END effect-authorization seam

PHP
		),

		// ---- core.config.option_write -------------------------------
		// Placed AFTER update_option()'s no-op short-circuit (so an
		// unchanged write never fires) and BEFORE its delegation to
		// add_option() (so one seam covers both the update and the
		// delegated-create path).
		//
		// Guarded by an explicit option list because measurement showed
		// the name is a sufficient signal: across an admin crawl, a cron
		// run, and forced update checks, zero security-relevant options
		// were written, while the real Settings form fired them
		// immediately. See CENSUS.md.
		'option_write' => array(
			'file'   => '/var/www/html/wp-includes/option.php',
			// Dollars escaped: this is a double-quoted PHP string, and
			// unescaped $option/$value/$autoload interpolate to empty,
			// which turns the anchor into nonsense. The patcher caught it
			// by refusing rather than landing somewhere arbitrary.
			'anchor' => "return add_option( \$option, \$value, '', \$autoload );",
			'seek'   => "\t/** This filter is documented in wp-includes/option.php */",
			'after'  => array( 'add_option(' ),
			'block'  => <<<'PHP'
	// BEGIN effect-authorization seam
	/*
	 * Reentrancy guard. A policy answering this veto may read or expire a
	 * transient, and transients are options — without this, a guarded
	 * write could re-enter its own authorization and recurse until the
	 * stack dies. Cheap insurance against a failure that would be very
	 * hard to read in a stack trace.
	 */
	static $cfp_option_seam_busy = false;

	if ( ! $cfp_option_seam_busy
		&& function_exists( 'wp_authorize_consequential_effect' )
		&& function_exists( 'wp_effect_guarded_options' )
		&& in_array( $option, wp_effect_guarded_options(), true )
	) {
		$cfp_from = wp_effect_option_summary( $old_value );
		$cfp_to   = wp_effect_option_summary( $value );

		/*
		 * Core's short-circuit above is a STRICT comparison, so an
		 * unchecked checkbox submitting int 0 against a stored string "0"
		 * reaches here as a "change" that changes nothing. Challenging an
		 * administrator who touched no such setting is how a control gets
		 * switched off within a day, so compare on the same normalised
		 * form the operator would be shown: if the descriptor cannot
		 * distinguish them, there is nothing to approve.
		 *
		 * Found by reading a live refusal that said
		 * 'from "0" to "0"' -- the notice surfaced its own false positive.
		 */
		if ( $cfp_from !== $cfp_to ) {
			$cfp_option_seam_busy = true;

			$cfp_effect = array(
				'version' => 1,
				'id'      => 'core.config.option_write',
				'site_id' => (int) get_current_blog_id(),
				'target'  => array(
					'option' => (string) $option,
					'from'   => $cfp_from,
					'to'     => $cfp_to,
				),
			);

			$cfp_ok = wp_authorize_consequential_effect( $cfp_effect );

			$cfp_option_seam_busy = false;

			if ( is_wp_error( $cfp_ok ) ) {
				return false;
			}
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
