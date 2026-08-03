<?php
/**
 * Plugin Name: Capability Floor Prototype (increment 2 — approval)
 * Description: Adds a real, per-effect, single-use approval that lifts the
 *              increment-1 denial for exactly the capability (and, where
 *              given, the exact target) it was issued for. No identity
 *              switch — the approval attaches to the actor's own session.
 *
 * Deliberately does not repeat two defects found in #470 (Phase 27) tonight
 * — and one of the two took two attempts to actually fix, not one:
 *
 *   - Its lockout never reset (no time window, no timestamp column). This
 *     rate limiter uses a rolling time window instead of a permanent
 *     cumulative counter, so it self-releases without needing a successful
 *     login as the only recovery path. Fixed on the first attempt.
 *   - Its lockout check had a read-then-act race under concurrency. The
 *     first version of this file "fixed" that with an atomic upsert for the
 *     window-reset step, then still read the count, decided, called
 *     wp_check_password(), and incremented in three separate statements —
 *     structurally the same race, just with new code. Testing it the same
 *     adversarial way #470 was tested (ten concurrent wrong-password
 *     requests against a limit of three) found 6 of 10 fully evaluated, not
 *     meaningfully better than #470's own 7 of 10. The actual fix wraps the
 *     whole read-check-verify-increment sequence in one transaction with
 *     SELECT ... FOR UPDATE on the rate row, so concurrent requests for the
 *     same user are serialized by the database rather than left to race in
 *     PHP. Re-run the same concurrent test after touching this function.
 *
 * Also deliberately does not add a #470-style test-control REST route.
 * That surface is what let a second administrator, and a leaked
 * Application Password with no session at all, disrupt other users
 * tonight. Test-state manipulation for this prototype happens via WP-CLI
 * and direct DB queries in the test scripts, never an exposed endpoint.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

const CAP_FLOOR_APPROVAL_TTL      = 120; // seconds an approval is valid once granted.
const CAP_FLOOR_RATE_WINDOW       = 300; // seconds the failure window covers.
const CAP_FLOOR_RATE_LIMIT        = 3;   // failures allowed within the window.
const CAP_FLOOR_BINDING_COOKIE    = '__Host-cap_floor_binding';
const CAP_FLOOR_BINDING_OPTION    = 'cap_floor_bindings';
const CAP_FLOOR_BINDING_TTL       = 600;

/**
 * A second, independent cookie required alongside the login session --
 * closing the gap found in this session's own adversarial pass tonight: the
 * first version of this file relied only on wp_get_session_token(), which is
 * derivable from the same LOGGED_IN_COOKIE that already grants ordinary
 * WordPress access. Leaking just that one cookie was enough to request and
 * hold a real approval. #470 never had this gap, because it required this
 * exact second factor at every step; this file did not build the
 * equivalent, and that omission -- not anything inherited from #470 -- is
 * what an adversarial pass against this file's own first version found.
 *
 * Modelled directly on #470's proven implementation
 * (phase27-wordpress-adapter.php: phase27_binding(),
 * phase27_raw_cookie_values(), phase27_require_binding()), which held
 * against every attack tried against it tonight.
 */

/**
 * Parse all exact-name cookie values from the raw request header, not via
 * $_COOKIE. PHP's normalized cookie map silently collapses duplicate names
 * to one value, which would hide a duplicate-cookie ambiguity from this
 * code while some other part of the stack might see it differently.
 *
 * @return list<string>
 */
function cap_floor_raw_cookie_values( string $name ): array {
	$header = isset( $_SERVER['HTTP_COOKIE'] ) ? (string) $_SERVER['HTTP_COOKIE'] : '';
	$values = array();

	foreach ( explode( ';', $header ) as $pair ) {
		$parts = explode( '=', trim( $pair ), 2 );
		if ( 2 === count( $parts ) && $parts[0] === $name ) {
			$values[] = rawurldecode( $parts[1] );
		}
	}

	return $values;
}

/**
 * Validate the current request's binding, minting one if none exists yet.
 * Safe to call from a REST callback: no output has been sent at that point,
 * so setcookie() still succeeds, exactly as in #470's proven implementation.
 *
 * @return string|WP_Error Raw binding value, or a refusal.
 */
function cap_floor_binding( bool $may_mint = true ) {
	static $resolved = null;
	if ( is_string( $resolved ) ) {
		return $resolved;
	}

	$user_id = get_current_user_id();
	$session = wp_get_session_token();
	$values  = cap_floor_raw_cookie_values( CAP_FLOOR_BINDING_COOKIE );

	if ( $user_id < 1 || '' === $session ) {
		return new WP_Error( 'cap_floor_binding_auth', 'Authentication required.', array( 'status' => 401 ) );
	}
	if ( count( $values ) > 1 ) {
		return new WP_Error( 'cap_floor_binding_duplicate', 'Ambiguous binding cookie.', array( 'status' => 400 ) );
	}

	$registry = get_option( CAP_FLOOR_BINDING_OPTION, array() );
	$binding  = $values[0] ?? '';
	$hash     = '' === $binding ? '' : hash( 'sha256', $binding );
	$owner    = $registry[ $hash ] ?? null;

	if (
		'' !== $binding &&
		is_array( $owner ) &&
		(int) ( $owner['user_id'] ?? 0 ) === $user_id &&
		(int) ( $owner['expires_at'] ?? 0 ) >= time() &&
		hash_equals( (string) ( $owner['session_hash'] ?? '' ), hash( 'sha256', $session ) )
	) {
		$resolved = $binding;
		return $binding;
	}

	// Everything below MINTS. Callers that must never mint say so
	// explicitly, and this is where the round-2 fix actually has to be
	// enforced -- not only at the admin_init hook.
	//
	// An Opus-tier claims audit found this hole: cap_floor_require_binding()
	// called this function with no way to forbid minting, so presenting a
	// *junk* binding cookie fell straight through the validity check above
	// and minted a fresh registered one. The refusal response carried it in
	// Set-Cookie. Reproduced end-to-end: junk cookie -> 403 + a valid
	// binding, replay that value with only a stolen login cookie -> 201, a
	// real approval. The docblock immediately above the require_binding()
	// call in cap_floor_request_approval() asserted this could not happen
	// ("Minting on demand here would silently defeat the whole fix") while
	// the code did exactly that. Note the earlier no-cookie-at-all case
	// still correctly 403'd, which is why regression test 5 never caught
	// it -- it only ever sent no cookie, never a wrong one.
	if ( ! $may_mint ) {
		return new WP_Error( 'cap_floor_binding', 'Registered binding required.', array( 'status' => 403 ) );
	}

	$binding         = bin2hex( random_bytes( 32 ) );
	$hash            = hash( 'sha256', $binding );
	$registry[ $hash ] = array(
		'user_id'      => $user_id,
		'session_hash' => hash( 'sha256', $session ),
		'expires_at'   => time() + CAP_FLOOR_BINDING_TTL,
	);

	// Prune expired entries here, piggybacking on a write that's already
	// happening, rather than a separate scheduled job. A second
	// independent-review agent found this option grows without bound
	// otherwise -- every request from a client that never persists the
	// cookie mints and keeps a new entry forever, since only full-user
	// revocation on logout/password-reset previously removed any.
	$now = time();
	foreach ( $registry as $entry_hash => $entry ) {
		if ( ! is_array( $entry ) || (int) ( $entry['expires_at'] ?? 0 ) < $now ) {
			unset( $registry[ $entry_hash ] );
		}
	}

	update_option( CAP_FLOOR_BINDING_OPTION, $registry, false );
	setcookie(
		CAP_FLOOR_BINDING_COOKIE,
		$binding,
		array(
			'expires'  => time() + CAP_FLOOR_BINDING_TTL,
			'path'     => '/',
			'secure'   => true,
			'httponly' => true,
			'samesite' => 'Strict',
		)
	);

	$resolved = $binding;
	return $binding;
}

/**
 * Require the current request's registered binding, without minting one.
 * Use this wherever a missing binding must refuse, not silently proceed.
 *
 * The `false` argument below is load-bearing, not stylistic: this function
 * has always documented that it does not mint, but until an Opus-tier
 * audit found otherwise it called the minting variant and therefore did.
 *
 * @return string|WP_Error
 */
function cap_floor_require_binding() {
	$values = cap_floor_raw_cookie_values( CAP_FLOOR_BINDING_COOKIE );
	if ( 1 !== count( $values ) ) {
		return new WP_Error( 'cap_floor_binding', 'Exactly one binding is required.', array( 'status' => 403 ) );
	}

	$binding = cap_floor_binding( false );
	if ( is_wp_error( $binding ) || ! hash_equals( $values[0], $binding ) ) {
		return new WP_Error( 'cap_floor_binding', 'Registered binding required.', array( 'status' => 403 ) );
	}

	return $binding;
}

/**
 * Revoke every registered binding for a user, alongside their approvals.
 */
function cap_floor_revoke_bindings( int $user_id ): void {
	$registry = (array) get_option( CAP_FLOOR_BINDING_OPTION, array() );
	foreach ( $registry as $hash => $owner ) {
		if ( is_array( $owner ) && (int) ( $owner['user_id'] ?? 0 ) === $user_id ) {
			unset( $registry[ $hash ] );
		}
	}
	update_option( CAP_FLOOR_BINDING_OPTION, $registry, false );
}

/**
 * Read-only: is there currently a valid, registered binding for this
 * request? Never mints one. cap_floor_valid_approval_id() fires on every
 * incidental map_meta_cap check (menu rendering, admin-bar visibility, not
 * only a user's actual intended action), possibly deep into template output
 * -- calling the minting cap_floor_binding() from there risks a
 * headers-already-sent failure. Minting happens only from admin_init (early,
 * priority 1) and from the REST callbacks below, both proven-safe points.
 *
 * @return string|null The bound value's hash, or null.
 */
function cap_floor_current_binding_hash(): ?string {
	$values = cap_floor_raw_cookie_values( CAP_FLOOR_BINDING_COOKIE );
	if ( 1 !== count( $values ) ) {
		return null;
	}

	$user_id  = get_current_user_id();
	$session  = wp_get_session_token();
	$registry = get_option( CAP_FLOOR_BINDING_OPTION, array() );
	$hash     = hash( 'sha256', $values[0] );
	$owner    = $registry[ $hash ] ?? null;

	if (
		is_array( $owner ) &&
		(int) ( $owner['user_id'] ?? 0 ) === $user_id &&
		(int) ( $owner['expires_at'] ?? 0 ) >= time() &&
		'' !== $session &&
		hash_equals( (string) ( $owner['session_hash'] ?? '' ), hash( 'sha256', $session ) )
	) {
		return $hash;
	}

	return null;
}

/**
 * Mint a binding only on the one page that begins the elevate flow, not on
 * every logged-in admin request.
 *
 * An earlier version of this hook fired on any admin page for any logged-in
 * user. A second independent-review agent found the consequence: someone
 * holding only a stolen LOGGED_IN_COOKIE -- exactly the threat this cookie
 * exists to raise the bar against -- could mint a fully valid binding
 * themselves with one unauthenticated-looking GET to any wp-admin URL, then
 * proceed to request-approval as if they had held the binding all along.
 * #470's own admin_init hook was never this broad; it scopes minting to its
 * one harness page. This restores that scoping.
 *
 * State plainly what this does and does not fix: narrowing the trigger to
 * a small, fixed set of URLs does not stop an attacker who can freely
 * replay the stolen cookie in their own requests -- they can request any
 * one of these URLs with it exactly as easily as any other. It only
 * requires them to know or discover one of these specific URLs first,
 * which is a real but modest increase in cost, not a closed gap. See
 * BOUNDARY.md for the corrected claim.
 *
 * users.php was added alongside the harness page once a real delete-user
 * REST route and an intercepted native "Delete" button went live there
 * (mu-plugins/harness.php) -- it is a second genuine elevate-flow entry
 * point now, not an incidental admin screen swept in by a broad rule.
 */
add_action(
	'admin_init',
	static function (): void {
		// $pagenow is checked for BOTH entry points. Checking only
		// $_GET['page'] for the harness -- which is what this did until an
		// Opus-tier audit and a concurrent adversarial pass independently
		// flagged it -- keys the mint off a purely attacker-supplied query
		// parameter that any admin script will happily carry. Minting was
		// reproducible at /wp-admin/index.php?page=cap-floor-harness,
		// edit.php, options-general.php, and admin-ajax.php (which fires
		// admin_init), i.e. effectively the whole admin rather than the
		// "one named page" the old comment claimed.
		$pagenow = (string) ( $GLOBALS['pagenow'] ?? '' );
		$page    = isset( $_GET['page'] ) ? (string) $_GET['page'] : '';
		if ( ( 'tools.php' === $pagenow && 'cap-floor-harness' === $page ) || 'users.php' === $pagenow ) {
			cap_floor_binding();
		}
	},
	1
);

/**
 * Create the approval and rate-limit tables if they do not exist.
 */
function cap_floor_create_tables(): void {
	global $wpdb;

	$charset = $wpdb->get_charset_collate();

	$approvals = $wpdb->prefix . 'cap_floor_approvals';
	$wpdb->query(
		"CREATE TABLE IF NOT EXISTS {$approvals} (
			id varchar(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			session_hash char(64) NOT NULL,
			binding_hash char(64) NOT NULL,
			capability varchar(64) NOT NULL,
			target_hash char(64) DEFAULT NULL,
			state varchar(16) NOT NULL,
			created_at bigint(20) unsigned NOT NULL,
			expires_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY (id),
			KEY owner_lookup (user_id, capability, state)
		) {$charset};"
	);

	// Rolling time-window rate limit, not a permanent cumulative counter --
	// window_started_at is what makes this self-releasing.
	$rates = $wpdb->prefix . 'cap_floor_rates';
	$wpdb->query(
		"CREATE TABLE IF NOT EXISTS {$rates} (
			user_id bigint(20) unsigned NOT NULL,
			failures int unsigned NOT NULL DEFAULT 0,
			window_started_at bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY (user_id)
		) {$charset};"
	);
}
add_action( 'init', 'cap_floor_create_tables' );

/**
 * The session-binding verifier for the current request. Empty for any
 * authentication path that does not establish a real login session --
 * Application Passwords included, matching the property #470 relied on and
 * this session verified tonight (wp_get_session_token() is empty under
 * Application-Password auth).
 */
function cap_floor_session_hash(): string {
	$token = wp_get_session_token();

	return '' === $token ? '' : hash( 'sha256', $token );
}

/**
 * Read-only: does the current user's current session hold a live,
 * unconsumed approval for this exact capability (and target, if given)?
 *
 * Side-effect-free and safe to call repeatedly -- map_meta_cap() fires for
 * incidental checks (menu visibility, admin-bar rendering) as well as for
 * the actual commit a user intends, and this prototype does not attempt to
 * distinguish the two. That is a known, named limitation, not an oversight:
 * §4.1 of the finding this prototype is built from calls exactly this
 * problem -- an intent signal for consequential effects -- the hardest
 * unresolved piece of the whole proposal. This function answers "is there
 * currently a valid grant," not "was this specific action intended,"
 * because WordPress gives no chokepoint that could distinguish those here.
 * The single-use property is enforced separately, at actual consumption
 * (cap_floor_consume_approval()), not at this check.
 *
 * $target_hash is mandatory on the approval side (cap_floor_request_approval()
 * refuses to issue one without it) and here on the checking side too: a
 * caller that checks the bare capability with no target argument -- which is
 * how nearly every native WordPress admin screen checks a capability, e.g.
 * plugin-install.php's own current_user_can( 'install_plugins' ) with no
 * second argument -- must never match any approval, targeted or not.
 *
 * An earlier version of this function let a null $target_hash here match a
 * null target_hash stored on the approval row, on the theory that an
 * "untargeted" approval was a legitimate, narrower grant. It was not: an
 * adversarial pass found that requesting an approval with no target_hash
 * unlocked install_plugins across every native admin screen and the real
 * upload-plugin form -- none of which ever pass a target argument -- for
 * the full TTL, reusable, with no consumption at all, since only this
 * prototype's own harness route ever calls cap_floor_consume_approval().
 * One password check bought a blanket, repeatable unlock -- effectively the
 * same standing-privilege problem this design exists to remove, just
 * time-boxed to 120 seconds. There is now no such thing as an untargeted
 * approval to match, on either side.
 *
 * @param string      $capability  The capability being checked.
 * @param string|null $target_hash The target argument the caller passed, if
 *                                 any. A null here can now only ever mean
 *                                 "no target was supplied to this specific
 *                                 check" -- it does not mean "match anything".
 * @return string|null The approval ID if one is valid, else null.
 */
function cap_floor_valid_approval_id( string $capability, ?string $target_hash = null ): ?string {
	global $wpdb;

	if ( null === $target_hash ) {
		return null; // No target on this check; nothing can match it.
	}

	$user_id = get_current_user_id();
	$session = cap_floor_session_hash();
	$binding = cap_floor_current_binding_hash();
	if ( $user_id < 1 || '' === $session || null === $binding ) {
		return null;
	}

	$table = $wpdb->prefix . 'cap_floor_approvals';
	$sql   = "SELECT id, target_hash FROM {$table}
		WHERE user_id = %d AND session_hash = %s AND binding_hash = %s AND capability = %s
		AND state = 'approved' AND expires_at >= %d
		ORDER BY created_at DESC";
	$rows  = $wpdb->get_results( $wpdb->prepare( $sql, $user_id, $session, $binding, $capability, time() ) );

	foreach ( $rows as $row ) {
		if ( is_string( $row->target_hash ) && hash_equals( $row->target_hash, $target_hash ) ) {
			return $row->id;
		}
	}

	return null;
}

/**
 * Lift the increment-1 denial when a valid approval exists for this exact
 * capability and target. Runs after cap_floor_deny() (see
 * capability-floor-prototype.php) via filter priority.
 *
 * The "is this even a floored capability" guard checks $caps as well as
 * $cap, mirroring the fix in cap_floor_deny() -- see that function's
 * docblock for why: $cap is the original request (e.g. 'edit_user'), which
 * for most of the account-takeover surface this floor covers is not the
 * same string as the primitive capability core resolves it to and puts in
 * $caps (e.g. 'edit_users'). No wired effect currently requests an
 * approval for anything but install_plugins/create_users, so this had no
 * observable effect on either of those; it matters only if a future effect
 * (e.g. editing another user) is wired the same way and needs this side to
 * recognise the capability it was denied under.
 */
function cap_floor_allow_with_approval( array $caps, string $cap, int $user_id, array $args ): array {
	if ( ! in_array( $cap, CAP_FLOOR_DENIED_CAPS, true ) && ! array_intersect( $caps, CAP_FLOOR_DENIED_CAPS ) ) {
		return $caps;
	}
	if ( ! in_array( 'do_not_allow', $caps, true ) ) {
		return $caps; // Nothing to lift.
	}

	$target_hash = isset( $args[0] ) && is_string( $args[0] ) ? $args[0] : null;
	if ( null === cap_floor_valid_approval_id( $cap, $target_hash ) ) {
		return $caps;
	}

	// Remove exactly the do_not_allow this filter set added, not anything an
	// earlier filter may have added for its own reasons.
	$key = array_search( 'do_not_allow', $caps, true );
	if ( false !== $key ) {
		unset( $caps[ $key ] );
	}

	return array_values( $caps );
}
add_filter( 'map_meta_cap', 'cap_floor_allow_with_approval', 1000000, 4 );

/**
 * Request an approval for one capability (and optional target), given a
 * fresh password. The identity never changes -- this grants the actor's
 * own session a scoped, time-limited, single-use approval; it does not
 * switch who they are.
 *
 * The read-check-verify-increment sequence is wrapped in an explicit
 * transaction with SELECT ... FOR UPDATE on the rate row, so concurrent
 * requests for the same user are genuinely serialized by the database, not
 * merely hoped to interleave safely. This replaces an earlier version of
 * this function that read the failure count, decided, then incremented
 * separately -- structurally the same read-then-act race #470 had, found
 * by testing this function the same adversarial way #470 was tested: 6 of
 * 10 concurrent wrong-password requests were fully evaluated against a
 * stated limit of 3, before this fix. Do not remove the transaction as a
 * simplification without re-running that same test.
 *
 * $target_hash is mandatory, not optional -- an untargeted approval is what
 * an adversarial pass found and exploited as a blanket, reusable unlock of
 * the bare capability everywhere WordPress checks it with no target
 * argument, which is nearly everywhere in native core UI. There is no
 * legitimate use of this function that has no target to name; if a caller
 * genuinely cannot supply one, that capability does not belong in
 * CAP_FLOOR_DENIED_CAPS as a target-bound grant in the first place.
 *
 * @return string|WP_Error Approval ID, or a WP_Error on refusal.
 */
function cap_floor_request_approval( string $capability, string $password, ?string $target_hash = null ) {
	global $wpdb;

	if ( null === $target_hash || '' === $target_hash ) {
		return new WP_Error(
			'cap_floor_target_required',
			'A target hash is required; an untargeted approval is not permitted.',
			array( 'status' => 400 )
		);
	}
	// Column is char(64); reject an over-length value here rather than let
	// $wpdb->insert() fail it silently later, which is exactly how a real
	// password check and a rate-limited attempt were burned on a phantom
	// approval that could never be redeemed before this check existed.
	if ( strlen( $target_hash ) > 64 ) {
		return new WP_Error(
			'cap_floor_target_invalid',
			'Target hash exceeds the maximum length.',
			array( 'status' => 400 )
		);
	}

	$user_id = get_current_user_id();
	$session = cap_floor_session_hash();
	if ( $user_id < 1 || '' === $session ) {
		return new WP_Error( 'cap_floor_auth', 'A real login session is required.', array( 'status' => 401 ) );
	}

	// Required before the password check, not after: a stolen LOGGED_IN_COOKIE
	// alone must not reach wp_check_password() at all. Deliberately requires
	// a binding that already exists rather than minting one here -- #470's
	// proven design (phase27_intent_for_request(), which every real route
	// calls) never mints from a REST callback either, only from admin_init.
	// Minting on demand here would silently defeat the whole fix: an
	// attacker with only the login cookie could mint a fresh binding on this
	// same request and proceed exactly as before.
	$binding = cap_floor_require_binding();
	if ( is_wp_error( $binding ) ) {
		return $binding;
	}
	$binding_hash = hash( 'sha256', $binding );

	$table = $wpdb->prefix . 'cap_floor_rates';
	$now   = time();

	// A row must exist before FOR UPDATE has anything to lock. This insert
	// is intentionally allowed to race -- IGNORE means at most one of any
	// concurrent first-attempts creates the row; every request, including
	// the ones that lose that race, proceeds to the locking SELECT below,
	// which is the statement actually responsible for serialization.
	$wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO {$table} (user_id, failures, window_started_at) VALUES (%d, 0, %d)",
			$user_id,
			$now
		)
	);

	$wpdb->query( 'START TRANSACTION' );

	$row = $wpdb->get_row(
		$wpdb->prepare( "SELECT failures, window_started_at FROM {$table} WHERE user_id = %d FOR UPDATE", $user_id )
	);

	$failures = (int) $row->failures;
	if ( (int) $row->window_started_at < $now - CAP_FLOOR_RATE_WINDOW ) {
		$failures = 0; // The window has aged out; this attempt starts a new one.
	}

	if ( $failures >= CAP_FLOOR_RATE_LIMIT ) {
		$wpdb->query( 'COMMIT' ); // Nothing was written; release the lock.
		return new WP_Error(
			'cap_floor_rate',
			'Too many recent failed attempts; try again in a few minutes.',
			array( 'status' => 429 )
		);
	}

	$user   = get_userdata( $user_id );
	$passed = $user && wp_check_password( $password, $user->user_pass, $user_id );

	if ( ! $passed ) {
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET failures = %d, window_started_at = %d WHERE user_id = %d",
				$failures + 1,
				$failures === 0 ? $now : (int) $row->window_started_at,
				$user_id
			)
		);
	}

	$wpdb->query( 'COMMIT' );

	if ( ! $passed ) {
		return new WP_Error( 'cap_floor_factor', 'Fresh authentication failed.', array( 'status' => 403 ) );
	}

	$id      = bin2hex( random_bytes( 32 ) );
	$table   = $wpdb->prefix . 'cap_floor_approvals';
	$now     = time();
	$inserted = $wpdb->insert(
		$table,
		array(
			'id'           => $id,
			'user_id'      => $user_id,
			'session_hash' => $session,
			'binding_hash' => $binding_hash,
			'capability'   => $capability,
			'target_hash'  => $target_hash,
			'state'        => 'approved',
			'created_at'   => $now,
			'expires_at'   => $now + CAP_FLOOR_APPROVAL_TTL,
		),
		array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
	);

	// A second independent-review agent found this ignored: a target_hash too
	// long for the char(64) column makes $wpdb->insert() fail silently, and
	// this function returned a freshly generated $id anyway -- a real
	// password check and one of only 3 rate-limited attempts burned on a
	// phantom "success" that could never be redeemed, with no format
	// validation on target_hash to have caught it earlier.
	if ( false === $inserted ) {
		return new WP_Error(
			'cap_floor_storage',
			'Could not record the approval.',
			array( 'status' => 500 )
		);
	}

	return $id;
}

/**
 * Consume an approval exactly once, atomically. This -- not
 * cap_floor_valid_approval_id() -- is where the single-use property lives.
 * Call this at the actual commit point of the effect it guards, not at a
 * capability check.
 *
 * @return bool True if this call consumed it; false if it was already
 *              consumed, expired, or never existed for this user/session.
 */
function cap_floor_consume_approval( string $id, string $capability, ?string $target_hash = null ): bool {
	global $wpdb;

	$user_id = get_current_user_id();
	$session = cap_floor_session_hash();
	$table   = $wpdb->prefix . 'cap_floor_approvals';

	$binding = cap_floor_require_binding();
	if ( is_wp_error( $binding ) ) {
		return false;
	}
	$binding_hash = hash( 'sha256', $binding );

	$sql = "UPDATE {$table} SET state = 'consumed'
		WHERE id = %s AND user_id = %d AND session_hash = %s AND binding_hash = %s
		AND capability = %s AND state = 'approved' AND expires_at >= %d";
	$params = array( $id, $user_id, $session, $binding_hash, $capability, time() );

	if ( null === $target_hash ) {
		$sql     .= ' AND target_hash IS NULL';
	} else {
		$sql     .= ' AND target_hash = %s';
		$params[] = $target_hash;
	}

	$changed = $wpdb->query( $wpdb->prepare( $sql, ...$params ) );

	return 1 === $changed;
}

/**
 * Revoke every pending approval for a user -- wired to the same lifecycle
 * events #470 used, for the same reason: a copied session should not carry
 * a standing grant past logout or a password reset.
 */
function cap_floor_revoke_user( int $user_id ): void {
	global $wpdb;

	$table = $wpdb->prefix . 'cap_floor_approvals';
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET state = 'cancelled' WHERE user_id = %d AND state = 'approved'",
			$user_id
		)
	);
}
add_action( 'wp_logout', 'cap_floor_revoke_user' );
add_action( 'wp_logout', 'cap_floor_revoke_bindings' );
add_action( 'after_password_reset', static function ( WP_User $user ): void {
	cap_floor_revoke_user( (int) $user->ID );
	cap_floor_revoke_bindings( (int) $user->ID );
} );
