<?php
/**
 * Consequential-effect authorization — the Core-owned seam.
 *
 * This file is written as if it lived in `wp-includes/`. It is the piece a
 * Core proposal would actually own: the descriptor contract, the canonical
 * serialization rule, the actor taxonomy, and one vetoable authorization
 * call. Everything policy-shaped (challenges, approvals, storage, UI) lives
 * outside it, in a plugin.
 *
 * The division matters. `FINDING.md` concluded that a plugin cannot create
 * a trustworthy effect boundary from outside Core, because it can only
 * infer effects from names and request shapes — and `CENSUS.md` counted
 * roughly ten places where Core would have to offer one instead. This is
 * the first of those ten.
 *
 * Default posture is ALLOW. With no policy attached, patched Core behaves
 * exactly as unpatched Core. The seam is inert until something opts in,
 * which is both how a Core feature would have to ship and what makes the
 * mutation test meaningful.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Canonical byte form of an effect descriptor.
 *
 * Load-bearing, not housekeeping. The descriptor is the thing being
 * approved, so both sides must agree on its exact bytes or the digest
 * comparison is meaningless. Leaving that to `json_encode()`'s default key
 * order is how you build a mismatch bug into the specification itself —
 * this prototype hit that failure class twice already (a client-computed
 * digest, and a field-ordering scheme with no stated canonical form).
 *
 * Rules: recursively key-sorted, no escaping of slashes or unicode,
 * integers preserved as integers.
 */
function wp_canonical_effect_json( array $effect ): string {
	$canonicalize = static function ( $value ) use ( &$canonicalize ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		ksort( $value, SORT_STRING );
		foreach ( $value as $k => $v ) {
			$value[ $k ] = $canonicalize( $v );
		}
		return $value;
	};

	$json = wp_json_encode( $canonicalize( $effect ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	return is_string( $json ) ? $json : '';
}

/**
 * Digest of an effect descriptor, over its canonical form.
 */
function wp_effect_digest( array $effect ): string {
	return hash( 'sha256', wp_canonical_effect_json( $effect ) );
}

/**
 * Digest of an unpacked package directory, over its real bytes.
 *
 * Deterministic by construction: relative paths sorted with `strcmp`, each
 * contributing path, size, and content hash. Directory iteration order is
 * filesystem-dependent and must never leak into the digest, or the same
 * package hashes differently on two machines and every approval breaks.
 *
 * Hashing the unpacked tree rather than the uploaded archive is deliberate.
 * The archive is what the caller sent; the tree is what will actually be
 * written. Those differ whenever anything rewrites the source between
 * download and install, and it is the second one the approval must cover.
 */
function wp_effect_source_digest( string $source ): string {
	if ( '' === $source || ! is_dir( $source ) ) {
		return '';
	}

	$entries = array();
	$base    = rtrim( $source, '/\\' );

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $iterator as $item ) {
		/** @var SplFileInfo $item */
		$relative = substr( $item->getPathname(), strlen( $base ) + 1 );
		$relative = str_replace( '\\', '/', (string) $relative );

		if ( $item->isDir() ) {
			$entries[] = 'd:' . $relative;
			continue;
		}

		$hash      = hash_file( 'sha256', $item->getPathname() );
		$entries[] = 'f:' . $relative . ':' . $item->getSize() . ':' . ( is_string( $hash ) ? $hash : '?' );
	}

	usort( $entries, 'strcmp' );

	return hash( 'sha256', implode( "\n", $entries ) );
}

/**
 * Options whose mutation is security-relevant.
 *
 * Core-owned and, critically, **add-only via filter**. A replaceable list
 * is not a control: this project's predecessor shipped a filter that let a
 * caller return an empty rule set and silence all gating, and had to grow a
 * guard that re-added missing builtins. Do not repeat it — plugins may
 * extend this list, never shrink it.
 *
 * Measured rather than guessed (see CENSUS.md): across an admin crawl, a
 * cron run, and forced update checks, none of these were written. They fire
 * on deliberate change and stay silent through ordinary use.
 *
 * @return string[]
 */
function wp_effect_guarded_options(): array {
	$core = array(
		'siteurl',            // redirect the site, or serve its assets from a hostile origin
		'home',
		'users_can_register', // with default_role, self-service administrators
		'default_role',
		'active_plugins',     // a direct write path to code execution
		'template',           // switch the executing theme
		'stylesheet',
		'admin_email',        // receives recovery and notification mail
		'mailserver_url',     // mail interception
		'mailserver_login',
		'mailserver_pass',
	);

	/**
	 * Filters ADDITIONAL guarded options. Core's own entries cannot be
	 * removed — the array_unique/array_merge below is the enforcement, not
	 * a convention.
	 *
	 * @param string[] $extra Additional option names to guard.
	 */
	$extra = apply_filters( 'wp_effect_guarded_options', array() );
	$extra = is_array( $extra ) ? array_filter( $extra, 'is_string' ) : array();

	return array_values( array_unique( array_merge( $core, $extra ) ) );
}

/**
 * A descriptor-safe summary of an option value.
 *
 * Option values are arbitrary — arrays, objects, long blobs — and a
 * descriptor is displayed to an operator and may be logged. Scalars short
 * enough to read are shown verbatim so the confirmation can state what is
 * actually changing; anything else is reduced to a digest of its
 * serialized form.
 */
function wp_effect_option_summary( $value ): string {
	if ( is_scalar( $value ) || null === $value ) {
		$str = (string) $value;
		if ( strlen( $str ) <= 100 ) {
			return $str;
		}
		return 'sha256:' . hash( 'sha256', $str );
	}

	return 'sha256:' . hash( 'sha256', (string) maybe_serialize( $value ) );
}

/**
 * Classify the actor driving the current request.
 *
 * Deliberately coarse and deliberately conservative: anything not
 * positively identified as an interactive human is treated as a system
 * actor, because the failure direction matters. Misreading a human as a
 * system actor refuses a legitimate action; misreading a system actor as a
 * human would let an unattended process satisfy a control designed to
 * require presence.
 *
 * `interactive` requires a real login session token. Application Passwords
 * do not establish one, so they classify as `remote` — verified in this
 * prototype rather than assumed.
 *
 * @return array{class:string, user_id:int, session:string}
 */
function wp_effect_actor(): array {
	$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	$session = '';

	if ( $user_id > 0 && function_exists( 'wp_get_session_token' ) ) {
		$session = (string) wp_get_session_token();
	}

	if ( ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		$class = 'cli';
	} elseif ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
		$class = 'cron';
	} elseif ( $user_id > 0 && '' !== $session ) {
		$class = 'interactive';
	} elseif ( $user_id > 0 ) {
		// Authenticated, but with no browser session behind it —
		// Application Passwords and similar token auth land here.
		$class = 'remote';
	} else {
		$class = 'anonymous';
	}

	return array(
		'class'   => $class,
		'user_id' => $user_id,
		'session' => '' === $session ? '' : hash( 'sha256', $session ),
	);
}

/**
 * Authorize a consequential effect, immediately before it is committed.
 *
 * Returns `true` to proceed, or `WP_Error` to refuse. Callers MUST treat a
 * `WP_Error` return as fatal to the operation and MUST NOT commit.
 *
 * This is a function with a return value, not an action, and that is the
 * whole point. WordPress already has an observational hook in this shape —
 * `wp_before_execute_ability` is a `do_action()` (verified at
 * `class-wp-ability.php:645`) — and an observer cannot refuse. A boundary
 * that cannot say no is not a boundary.
 *
 * @param array $effect Effect descriptor. See `WP_Upgrader::install_package()`
 *                      for the first Core call site and the shape it builds.
 * @return true|WP_Error
 */
function wp_authorize_consequential_effect( array $effect ) {
	$effect['actor']  = $effect['actor'] ?? wp_effect_actor();
	$effect['digest'] = wp_effect_digest( $effect );

	/**
	 * Filters the authorization decision for a consequential effect.
	 *
	 * Return a `WP_Error` to refuse. Returning anything else — including
	 * the default `null` — allows the effect, so that Core with no policy
	 * attached behaves exactly as it does today.
	 *
	 * @param null|WP_Error $decision Refusal, or null to allow.
	 * @param array         $effect   The effect descriptor, with `actor`
	 *                                and `digest` populated by Core.
	 */
	$decision = apply_filters( 'wp_authorize_consequential_effect', null, $effect );

	if ( is_wp_error( $decision ) ) {
		return $decision;
	}

	return true;
}
