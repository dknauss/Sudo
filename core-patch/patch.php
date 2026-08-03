<?php
/**
 * Applies / reverts the `core.code.package_commit` effect boundary in a
 * running WordPress. Runs INSIDE the container.
 *
 *   php patch.php apply
 *   php patch.php revert    # mutation test: remove only the veto
 *   php patch.php status
 *
 * Anchored on unique source text, never line numbers, and refuses to patch
 * at all if the anchor is missing — a patcher that silently lands in the
 * wrong place is worse than one that fails.
 */

declare( strict_types = 1 );

const UPGRADER = '/var/www/html/wp-admin/includes/class-wp-upgrader.php';
const SETTINGS = '/var/www/html/wp-settings.php';
const SEAM     = '/var/www/html/wp-includes/effect-authorization.php';

const BEGIN = "\t\t// BEGIN effect-authorization seam";
const END   = "\t\t// END effect-authorization seam";

/**
 * The inserted block. Builds the descriptor from what Core actually knows
 * at this point — not from anything the caller supplied — and refuses by
 * returning WP_Error, which install_package()'s callers already handle.
 */
function seam_block(): string {
	return <<<'PHP'

		// BEGIN effect-authorization seam
		/*
		 * Last point at which this operation is fully determined and
		 * nothing has yet been written somewhere WordPress executes from.
		 * $source is unpacked on disk, so the payload digest is over the
		 * real bytes about to be installed rather than anything a caller
		 * claimed about them.
		 */
		if ( function_exists( 'wp_authorize_consequential_effect' ) ) {
			$cfp_hook_extra = isset( $args['hook_extra'] ) && is_array( $args['hook_extra'] ) ? $args['hook_extra'] : array();
			$cfp_slug       = '';
			if ( isset( $cfp_hook_extra['plugin'] ) && is_string( $cfp_hook_extra['plugin'] ) ) {
				$cfp_slug = $cfp_hook_extra['plugin'];
			} elseif ( isset( $cfp_hook_extra['theme'] ) && is_string( $cfp_hook_extra['theme'] ) ) {
				$cfp_slug = $cfp_hook_extra['theme'];
			}

			$cfp_effect = array(
				'version' => 1,
				'id'      => 'core.code.package_commit',
				'site_id' => (int) get_current_blog_id(),
				'target'  => array(
					'type'        => isset( $cfp_hook_extra['type'] ) && is_string( $cfp_hook_extra['type'] ) ? $cfp_hook_extra['type'] : 'unknown',
					'action'      => isset( $cfp_hook_extra['action'] ) && is_string( $cfp_hook_extra['action'] ) ? $cfp_hook_extra['action'] : 'unknown',
					'slug'        => $cfp_slug,
					'destination' => (string) $remote_destination,
				),
				'payload' => array(
					'sha256' => wp_effect_source_digest( (string) $source ),
				),
			);

			$cfp_authorized = wp_authorize_consequential_effect( $cfp_effect );
			if ( is_wp_error( $cfp_authorized ) ) {
				return $cfp_authorized;
			}
		}
		// END effect-authorization seam

PHP;
}

function fail( string $msg ): void {
	fwrite( STDERR, "FAIL: {$msg}\n" );
	exit( 1 );
}

function status(): void {
	$up = file_get_contents( UPGRADER );
	$st = file_get_contents( SETTINGS );
	printf( "seam file present:            %s\n", file_exists( SEAM ) ? 'yes' : 'NO' );
	printf( "loaded from wp-settings.php:  %s\n", str_contains( $st, 'effect-authorization' ) ? 'yes' : 'NO' );
	printf( "veto present in Core:         %s\n", str_contains( $up, 'wp_authorize_consequential_effect' ) ? 'yes' : 'NO' );
	printf( "install_package() intact:     %s\n", str_contains( $up, 'function install_package' ) ? 'yes' : 'NO' );
	printf( "PHP syntax of patched Core:   " );
	$out = array();
	exec( 'php -l ' . escapeshellarg( UPGRADER ) . ' 2>&1', $out, $rc );
	echo 0 === $rc ? "ok\n" : ( "BROKEN\n" . implode( "\n", $out ) . "\n" );
}

$mode = $argv[1] ?? 'status';

if ( 'status' === $mode ) {
	status();
	exit( 0 );
}

$src = file_get_contents( UPGRADER );
if ( false === $src ) {
	fail( 'cannot read ' . UPGRADER );
}

if ( 'revert' === $mode ) {
	$pattern = '/\n?' . preg_quote( BEGIN, '/' ) . '.*?' . preg_quote( END, '/' ) . '\n/s';
	$out     = preg_replace( $pattern, "\n", $src );
	if ( null === $out ) {
		fail( 'regex failed' );
	}
	file_put_contents( UPGRADER, $out );
	echo str_contains( $out, 'wp_authorize_consequential_effect' ) ? "STILL PRESENT\n" : "veto removed\n";
	status();
	exit( 0 );
}

// ---- apply ----

if ( str_contains( $src, 'wp_authorize_consequential_effect' ) ) {
	echo "already patched\n";
	status();
	exit( 0 );
}

// Anchor: the comment block immediately preceding the move_dir/copy_dir
// write. Unique in this file, and semantically the right place.
$anchor_pos = strpos( $src, "If 'clear_working' is false" );
if ( false === $anchor_pos ) {
	fail( 'anchor not found — Core moved underneath this patch; refusing to guess' );
}

// Walk back to the opening of that comment block.
$insert_at = strrpos( substr( $src, 0, $anchor_pos ), "\t\t/*" );
if ( false === $insert_at ) {
	fail( 'comment-block start not found' );
}

// Sanity: the write we are guarding must appear after the insertion point.
$tail = substr( $src, $anchor_pos );
if ( ! str_contains( $tail, 'move_dir(' ) || ! str_contains( $tail, 'copy_dir(' ) ) {
	fail( 'the guarded write is not after the anchor — wrong location' );
}

$patched = substr( $src, 0, $insert_at ) . seam_block() . substr( $src, $insert_at );
file_put_contents( UPGRADER, $patched );

$out = array();
exec( 'php -l ' . escapeshellarg( UPGRADER ) . ' 2>&1', $out, $rc );
if ( 0 !== $rc ) {
	file_put_contents( UPGRADER, $src ); // roll back rather than leave Core broken
	fail( "patched Core failed lint, rolled back:\n" . implode( "\n", $out ) );
}

echo "veto inserted\n";
status();
