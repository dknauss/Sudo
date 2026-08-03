<?php
/**
 * Plugin Name: Effect policy (answers the Core effect-authorization seam)
 * Description: The policy half of the split. Core owns the boundary and the
 *              descriptor; this owns the decision. Nothing here can be
 *              trusted to find effects — that is precisely why the boundary
 *              is in Core.
 *
 * Demonstrates `core.code.package_commit`, the seam `CENSUS.md` identified
 * as the highest-value of the ten: one function covering plugin, theme, and
 * language package writes across every entry point that reaches them, and
 * the only code-arrival seam reached by both interactive and system actors.
 *
 * PREFLIGHT-BY-REFUSAL. The first attempt is refused and the refusal carries
 * the effect digest Core computed from the real unpacked bytes. The operator
 * approves that exact digest, then retries. No separate preflight endpoint,
 * no client-side digest computation — the value being approved is the value
 * Core produced, which is the entire correction this prototype exists to
 * make. The digest is stable across retries because it is taken over
 * relative paths and content, never the temp directory it happens to
 * occupy.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Effects this policy governs. Anything not listed passes through
 * untouched, so adding a Core seam does not silently start gating an
 * effect nobody wrote a policy for.
 */
const EFFECT_POLICY_GOVERNED = array(
	'core.code.package_commit',
	'core.identity.email_change',
);

add_filter(
	'wp_authorize_consequential_effect',
	static function ( $decision, array $effect ) {
		if ( is_wp_error( $decision ) ) {
			return $decision; // Someone already refused; do not overturn.
		}
		if ( ! in_array( (string) ( $effect['id'] ?? '' ), EFFECT_POLICY_GOVERNED, true ) ) {
			return $decision; // Not an effect this policy governs.
		}

		$actor  = $effect['actor'] ?? array();
		$class  = (string) ( $actor['class'] ?? 'anonymous' );
		$digest = (string) ( $effect['digest'] ?? '' );

		$described = effect_policy_describe( $effect );

		if ( 'interactive' === $class ) {
			if ( effect_policy_has_approval( $digest ) ) {
				effect_policy_consume( $digest );
				return $decision; // Allow.
			}

			return new WP_Error(
				'effect_unauthorized',
				sprintf(
					'This action requires approval. Effect: %s. Approve digest %s, then retry.',
					$described,
					$digest
				),
				array(
					'status' => 403,
					'effect' => $effect['id'],
					'digest' => $digest,
					'actor'  => $class,
				)
			);
		}

		/*
		 * Every non-interactive actor is refused, and this is a real
		 * limitation rather than a policy choice worth defending.
		 * Authorizing a system actor requires verified package provenance —
		 * quarantine, independent checksum, fail-closed — which is a
		 * separate discipline this prototype has not built and should not
		 * pretend to. Refusing is the honest placeholder; it also means
		 * automatic updates do not work while this policy is active, which
		 * is exactly the cost of the missing half.
		 */
		return new WP_Error(
			'effect_no_machine_provenance',
			sprintf(
				'Refused: %s requested by a "%s" actor. Machine actors require verified package provenance, which is not implemented.',
				$described,
				$class
			),
			array(
				'status' => 403,
				'effect' => $effect['id'],
				'digest' => $digest,
				'actor'  => $class,
			)
		);
	},
	10,
	2
);

/**
 * Approvals are keyed to the Core-computed effect digest, single-use, and
 * scoped to the actor's own session — reusing the properties the approval
 * mechanism already demonstrated, but bound to an effect rather than a
 * capability name.
 */
function effect_policy_key( string $digest ): string {
	$user    = get_current_user_id();
	$session = function_exists( 'wp_get_session_token' ) ? (string) wp_get_session_token() : '';

	return 'effect_approval_' . hash( 'sha256', $user . '|' . $session . '|' . $digest );
}

function effect_policy_has_approval( string $digest ): bool {
	if ( '' === $digest ) {
		return false;
	}
	return (bool) get_transient( effect_policy_key( $digest ) );
}

function effect_policy_consume( string $digest ): void {
	delete_transient( effect_policy_key( $digest ) );
}

/**
 * Grants an approval for one exact effect digest. In a real system this is
 * the point behind the reauthentication challenge; here it is reachable via
 * WP-CLI so the demonstrator can exercise the boundary without also having
 * to build challenge UI.
 *
 * Deliberately NOT a REST route: an endpoint that mints approvals is the
 * kind of test-control surface that, in this project's own history, turned
 * out to be exploitable by a second administrator and by an Application
 * Password. Test state goes through WP-CLI.
 */
function effect_policy_grant( string $digest, int $user_id, string $session ): void {
	$key = 'effect_approval_' . hash( 'sha256', $user_id . '|' . $session . '|' . $digest );
	set_transient( $key, 1, 120 );
}

/**
 * Human-readable statement of exactly what is about to happen.
 *
 * This is what a confirmation UI would show, so it must be derived from the
 * descriptor Core built — never from anything the request supplied. An
 * operator approving "change bob@example.com to x@evil.test" is approving
 * that sentence; if the sentence and the digest can disagree, the whole
 * control is decorative.
 */
function effect_policy_describe( array $effect ): string {
	$t = isset( $effect['target'] ) && is_array( $effect['target'] ) ? $effect['target'] : array();

	if ( 'core.identity.email_change' === ( $effect['id'] ?? '' ) ) {
		return sprintf(
			'change user #%d email from "%s" to "%s"',
			(int) ( $t['user_id'] ?? 0 ),
			(string) ( $t['from'] ?? '?' ),
			(string) ( $t['to'] ?? '?' )
		);
	}

	return sprintf(
		'%s %s "%s"',
		(string) ( $t['action'] ?? '?' ),
		(string) ( $t['type'] ?? '?' ),
		(string) ( $t['slug'] ?? '(new)' )
	);
}
