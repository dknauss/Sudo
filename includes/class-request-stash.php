<?php
/**
 * Request stash — serialize and replay intercepted requests.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Request_Stash
 *
 * When a gated admin action is intercepted, the replay target (URL, method,
 * and allowlisted POST parameters) is serialized into a short-lived transient.
 * After the user successfully reauthenticates, the stashed request is
 * retrieved and replayed — via redirect for GET, or a self-submitting
 * form for POST.
 *
 * @since 2.0.0
 */
class Request_Stash {

	/**
	 * Transient prefix for stashed requests.
	 *
	 * @var string
	 */
	public const TRANSIENT_PREFIX = '_wp_sudo_stash_';

	/**
	 * Stash time-to-live in seconds (5 minutes).
	 *
	 * @var int
	 */
	public const TTL = 300;

	/**
	 * Stash key length (alphanumeric characters).
	 *
	 * @var int
	 */
	private const KEY_LENGTH = 16;

	/**
	 * Cookie carrying the per-browser stash binding proof (#322 v2).
	 *
	 * `__Host-` prefixed on purpose: the prefix forces Secure + Path=/ and, crucially,
	 * forbids a `Domain` attribute, so the cookie is host-only and a sibling subdomain
	 * cannot plant or overwrite it (cookie tossing). On subdomain multisite
	 * COOKIE_DOMAIN is `.example.com`, which would otherwise let any host under the
	 * network transplant a proof the attacker legitimately owns.
	 *
	 * @var string
	 */
	public const BINDING_COOKIE = '__Host-wp_sudo_stash_proof';

	/**
	 * Request params that identify the concrete target of a gated action.
	 *
	 * Recorded so the challenge can name what is about to happen ("Activate plugin:
	 * evil/evil.php") instead of only the coarse rule label. Informed confirmation is
	 * the control that survives a binding bypass — the user is consenting to a NAMED
	 * action, not signing a blank cheque.
	 *
	 * @var string[]
	 */
	private const TARGET_PARAMS = array(
		'plugin',
		'theme',
		'stylesheet',
		'template',
		'user_id',
		'users',
		'option',
		'file',
		'id',
		'post',
		'blog_id',
		'app_name',
		// options.critical stashes these; without them the most dangerous settings
		// change (site URL takeover, admin email, default role) would render an EMPTY
		// target — i.e. no informed confirmation exactly where it matters most.
		//
		// `new_admin_email` is the field options-general.php actually posts for the
		// administration email address; that screen never submits `admin_email` (see
		// GB-ADMIN-EMAIL-FIELD in docs/upstream-sources.md). Capturing only the latter
		// left the single most dangerous change on the page unnamed. Both are listed
		// because options.php and REST callers can send the raw option name.
		'siteurl',
		'home',
		'admin_email',
		'new_admin_email',
		'default_role',
		'users_can_register',
		// Effect fields for built-ins whose target is not the rule's own noun:
		// plugin.delete identifies its plugins with checked[], user.promote its
		// destination with new_role. Without these the confirmation is blank while the
		// full payload replays.
		'checked',
		'new_role',
	);

	/**
	 * Request fields that carry no effect and need not be described.
	 *
	 * Mirrors Action_Registry::DEFAULT_REPLAY_POST_FIELDS — nonces, referrers and
	 * submit buttons say nothing about WHAT an action does, so their presence must not
	 * make an otherwise-describable request undescribable.
	 *
	 * @var string[]
	 */
	private const NON_EFFECT_FIELDS = array(
		'_wpnonce',
		'_wp_http_referer',
		'option_page',
		'action',
		'action2',
		'submit',
		'stash_key',
	);

	/**
	 * Maximum stored length of a single target value.
	 *
	 * @var int
	 */
	private const TARGET_MAX_LENGTH = 100;

	/**
	 * Reason code for POST requests that are intentionally not replayed.
	 *
	 * @var string
	 */
	public const REPLAY_BLOCKED_NO_REPLAY = 'post_replay_disabled';

	/**
	 * Reason code for POST requests without an allowlist.
	 *
	 * @var string
	 */
	public const REPLAY_BLOCKED_NO_ALLOWLIST = 'missing_post_allowlist';

	/**
	 * High-signal field-name suffixes that indicate secret values.
	 *
	 * @var string[]
	 */
	private const SENSITIVE_KEY_SUFFIXES = array(
		'_password',
		'_pass',
		'_api_key',
		'_secret_key',
		'_secret',
		'_private_key',
		'_access_token',
		'_auth_token',
		'_api_token',
		'_api_secret',
		'-password',
		'-pass',
		'-api-key',
		'-secret-key',
		'-secret',
		'-private-key',
		'-access-token',
		'-auth-token',
		'-api-token',
		'-api-secret',
		'password',
		'apikey',
		'secretkey',
		'privatekey',
		'accesstoken',
		'authtoken',
		'apitoken',
		'apisecret',
	);

	/**
	 * Maximum number of stash entries per user.
	 *
	 * Intentionally a constant, not a filter. This is a server-side
	 * security bound. Making it filterable would allow third-party code
	 * to inflate it, defeating the purpose.
	 *
	 * @var int
	 */
	public const MAX_STASH_PER_USER = 5;

	/**
	 * User meta key for the per-user stash key index.
	 *
	 * Stores an array of stash keys (without the transient prefix)
	 * for the current user. Used by enforce_stash_cap() and delete().
	 *
	 * Cleaned up by uninstall.php.
	 *
	 * @var string
	 */
	public const STASH_INDEX_META_KEY = '_wp_sudo_stash_keys';

	/**
	 * Save the current request into a transient.
	 *
	 * @param int                  $user_id      The user ID.
	 * @param array<string, mixed> $matched_rule The action registry rule that was matched.
	 * @return string Stash key for use in the challenge URL.
	 */
	public function save( int $user_id, array $matched_rule ): string {
		$key = wp_generate_password( self::KEY_LENGTH, false );

		// Enforce per-user cap BEFORE writing (evicts oldest if at limit).
		$this->enforce_stash_cap( $user_id );

		$redacted_fields_omitted  = false;
		$post_replay_blocked      = false;
		$post_replay_block_reason = '';
		$target_complete          = true;
		$method                   = $this->get_request_method();
		$post                     = $this->build_stashed_post_params(
			$matched_rule,
			$method,
			$redacted_fields_omitted,
			$post_replay_blocked,
			$post_replay_block_reason
		);

		$data = array(
			'user_id'                  => $user_id,
			'rule_id'                  => $matched_rule['id'] ?? '',
			'label'                    => $matched_rule['label'] ?? '',
			'method'                   => $method,
			'url'                      => $this->build_original_url(),
			'return_url'               => $this->get_return_url(),
			'post'                     => $post,
			'redacted_fields_omitted'  => $redacted_fields_omitted,
			'post_replay_blocked'      => $post_replay_blocked || $redacted_fields_omitted,
			'post_replay_block_reason' => $redacted_fields_omitted ? 'redacted_fields_omitted' : $post_replay_block_reason,
			'created'                  => time(),
			'target'                   => $this->capture_target( $target_complete ),
			'target_complete'          => $target_complete,
			'binding_hash'             => $this->mint_binding_proof(),
		);

		// A replay may only run when the confirmation described the WHOLE effect. If a
		// value was truncated for display, or the payload carries an effect field the
		// target does not name (a custom rule's own field, say), the user would be
		// confirming less than what executes — so fall back to the manual path.
		$data['target_complete'] = $target_complete && $this->target_describes_payload( $data['target'], $post );

		$this->set_stash_transient( self::TRANSIENT_PREFIX . $key, $data, self::TTL );

		// Record key in user index AFTER successful write.
		$this->add_to_stash_index( $user_id, $key );

		return $key;
	}

	/**
	 * Capture the concrete target of the gated request for informed confirmation.
	 *
	 * Read-only, sanitized, length-capped. Never used to route or replay — it exists
	 * solely so the challenge page can name the action the user is authorizing.
	 *
	 * @param bool $complete Set false when any value was truncated for display,
	 *                            so the caller can refuse to replay more than the
	 *                            confirmation described.
	 * @return array<string, string>
	 */
	private function capture_target( bool &$complete ): array {
		$complete  = true;
		$target    = array();
		$sensitive = $this->sensitive_field_keys();

		foreach ( self::TARGET_PARAMS as $param ) {
			// Read the source that will actually be REPLAYED first. A POST replay
			// submits the stashed body, so preferring the query would let
			// `plugins.php?plugin=benign.php` with a body of `plugin=evil.php` display
			// the benign value and replay the other — defeating the whole point of
			// informed confirmation. When both are present and disagree, show both
			// rather than silently picking one.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Display metadata only, never used to route or replay; sanitized below.
			$from_query = $_GET[ $param ] ?? null;
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Display metadata only, never used to route or replay; sanitized below.
			$from_body = $_POST[ $param ] ?? null;

			$is_post = 'POST' === strtoupper( $this->get_request_method() );
			$raw     = $is_post ? ( $from_body ?? $from_query ) : ( $from_query ?? $from_body );
			$other   = $is_post ? $from_query : $from_body;

			if ( null === $raw || '' === $raw ) {
				continue;
			}

			// Never record a secret-shaped value, even though no default target param
			// is secret-shaped: a site may add one via the sensitive-key filter, and
			// this path bypasses the per-rule POST allowlist and redaction.
			if ( $this->is_sensitive_key( $param, $sensitive ) ) {
				continue;
			}

			// A critical-option field that only repeats the value already stored is not
			// part of what this request changes. options-general.php posts every one of
			// them on every save, so a Site Title change rendered
			// "Target: default_role: subscriber" — the confirmation asserting a change
			// that was not happening (#431). Naming the wrong thing is worse than
			// naming nothing: it teaches users that the Target line can be skimmed.
			if ( $this->target_value_echoes_stored_option( $param, $raw, $other ) ) {
				continue;
			}

			// Bulk actions ('users' on bulk delete / role change) arrive as ARRAYS.
			// Discarding them left the target EMPTY exactly where the action is most
			// destructive — no informed confirmation on a bulk user delete.
			if ( is_array( $raw ) ) {
				$parts = array();

				foreach ( $raw as $item ) {
					if ( ! is_scalar( $item ) ) {
						continue;
					}

					$item = sanitize_text_field( wp_unslash( (string) $item ) );

					if ( '' !== $item ) {
						$parts[] = $item;
					}
				}

				if ( ! $parts ) {
					continue;
				}

				$value = implode( ', ', $parts );
			} else {
				$value = sanitize_text_field( wp_unslash( (string) $raw ) );
			}

			if ( '' === $value ) {
				continue;
			}

			// Surface a query/body disagreement instead of hiding it — the confirmation
			// must not name one value while another is replayed.
			if ( null !== $other && ! is_array( $other ) && ! is_array( $raw ) ) {
				$other_value = sanitize_text_field( wp_unslash( (string) $other ) );

				if ( '' !== $other_value && $other_value !== $value ) {
					$value .= ' (conflicting value in request: ' . $other_value . ')';
				}
			}

			$shown = $this->truncate_target_value( $value );

			// Truncating the DISPLAY while replaying the FULL value would let a user
			// approve the first few accounts of a bulk delete and silently remove the
			// rest. Record that the description is partial.
			if ( $shown !== $value ) {
				$complete = false;
			}

			$target[ $param ] = $shown;
		}

		return $target;
	}

	/**
	 * Whether a critical-option field only repeats the value already stored.
	 *
	 * Compares every source present in the request against the stored option and
	 * reports "unchanged" only when they ALL match, so a query/body disagreement is
	 * still surfaced by the caller rather than quietly dropped here.
	 *
	 * Fails toward SHOWING the value. An option that cannot be read, a non-scalar
	 * stored value and an array submission are all treated as a possible change,
	 * because hiding a real target is far more dangerous than naming a spurious one.
	 *
	 * KNOWN LIMITATION, deliberately not fixed here: this runs for EVERY matched rule,
	 * not only options.critical, because capture_target() has no rule context. A custom
	 * rule whose effect field happens to be named `home`, `siteurl` or another entry
	 * below, submitting a value equal to that stored option, has it suppressed from the
	 * target — after which target_describes_payload() no longer names the whole payload
	 * and the replay is refused. That fails CLOSED (the user re-submits manually;
	 * nothing executes unnamed), which is why it is a functional wart rather than a
	 * security defect, and why the fix — threading the matched rule through target
	 * capture — was not made during a release freeze. Tracked as #452; remove this
	 * block in the same commit that fixes it.
	 *
	 * @param string $param Request parameter name.
	 * @param mixed  $raw   Value from the source that would be replayed.
	 * @param mixed  $other Value from the other source, if present.
	 * @return bool
	 */
	private function target_value_echoes_stored_option( string $param, $raw, $other ): bool {
		if ( ! isset( self::CRITICAL_OPTION_SOURCES[ $param ] ) ) {
			return false;
		}

		$stored = get_option( self::CRITICAL_OPTION_SOURCES[ $param ] );

		// get_option() returns false when the option is absent. That is not evidence
		// the submitted value is unchanged, so treat it as a change and show it.
		if ( false === $stored || ! is_scalar( $stored ) ) {
			return false;
		}

		$stored = (string) $stored;

		foreach ( array( $raw, $other ) as $candidate ) {
			if ( null === $candidate ) {
				continue;
			}

			if ( ! is_scalar( $candidate ) ) {
				return false;
			}

			// Compare the value that would REPLAY, before display sanitization:
			// sanitize_text_field() can reduce a payload to something matching the
			// stored value while the unsanitized value is what actually replays.
			if ( (string) wp_unslash( (string) $candidate ) !== $stored ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Map each option-shaped target field to the option it is compared against.
	 *
	 * Deliberately NOT derived from `wp_sudo_critical_options`. Whether an option is
	 * GATED and whether a displayed value is TRUE are different questions: a site that
	 * narrows the filter to `new_admin_email` still posts unchanged `siteurl`, `home`,
	 * `default_role` and `users_can_register` from the same form, and those keys stay
	 * in TARGET_PARAMS. Deriving from the filtered list would leave them uncomparable
	 * and name them as changes again — reinstating #431 on exactly the sites that
	 * customised their protection set.
	 *
	 * The administration email address is the one field that is not self-titled: core
	 * posts `new_admin_email` but prefills it with the current `admin_email` (see
	 * GB-ADMIN-EMAIL-FIELD in docs/upstream-sources.md), so an untouched save submits
	 * the stored address. Comparing it against the `new_admin_email` option — empty
	 * unless a change is already pending — would report every save as a change.
	 *
	 * @var array<string, string>
	 */
	private const CRITICAL_OPTION_SOURCES = array(
		'siteurl'            => 'siteurl',
		'home'               => 'home',
		'admin_email'        => 'admin_email',
		'new_admin_email'    => 'admin_email',
		'default_role'       => 'default_role',
		'users_can_register' => 'users_can_register',
	);

	/**
	 * Whether the captured target names every effect-bearing field in the payload.
	 *
	 * TARGET_PARAMS is a fixed list, but rules are extensible: a custom rule (or a
	 * built-in whose effect field is not in the list) can allowlist a field the target
	 * never mentions. Replaying that would execute more than the confirmation showed,
	 * so such a stash is not eligible for bound replay.
	 *
	 * @param array<string, string> $target Captured target.
	 * @param array<string, mixed>  $post   Stashed POST payload.
	 * @return bool
	 */
	private function target_describes_payload( array $target, array $post ): bool {
		foreach ( array_keys( $post ) as $key ) {
			$key = (string) $key;

			if ( in_array( $key, self::NON_EFFECT_FIELDS, true ) ) {
				continue;
			}

			if ( ! array_key_exists( $key, $target ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Truncate a target value without breaking UTF-8.
	 *
	 * Splitting a multibyte sequence makes esc_html() emit '' (wp_check_invalid_utf8),
	 * which silently blanks the Target line — quietly removing the primary #322
	 * control instead of erroring. mbstring is optional even on supported PHP, so the
	 * fallback trims any trailing partial sequence rather than truncating by bytes.
	 *
	 * @param string $value Sanitized target value.
	 * @return string
	 */
	private function truncate_target_value( string $value ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, self::TARGET_MAX_LENGTH );
		}

		$cut = substr( $value, 0, self::TARGET_MAX_LENGTH );

		// Drop a trailing incomplete multibyte sequence (lead byte + any continuation
		// bytes) so the result is always valid UTF-8.
		return (string) preg_replace( '/[\xC0-\xFF][\x80-\xBF]*$/', '', $cut );
	}

	/**
	 * Mint a per-browser binding proof for this stash (#322 v2).
	 *
	 * Returns the sha256 of a fresh random secret and sets the plaintext secret in a
	 * host-only, httponly, SameSite=Strict cookie. Only the HASH is ever stored, so a
	 * stash read does not yield the proof.
	 *
	 * Deliberately refuses to mint (returns '') unless ALL hold:
	 *  - the gated request was same-origin initiated (`Sec-Fetch-Site: same-origin`).
	 *    WordPress nonces are bound to the session token, NOT the browser (GB-NONCE-TOKEN,
	 *    GB-SESSION-TOKEN-SRC), so an attacker holding a stolen login cookie can mint
	 *    a valid nonce and lure the victim into issuing the gated request — which would
	 *    otherwise mint the binding in the VICTIM's browser and defeat the whole
	 *    mechanism. A missing or cross-site/same-site header fails closed.
	 *  - cookies are Secure (`__Host-` requires it; without TLS there is no binding
	 *    worth trusting anyway).
	 *  - headers are not already sent, so the cookie can actually reach the browser.
	 *    Storing a hash the browser can never satisfy would strand the stash.
	 *
	 * Binding is defence-in-depth only. It is never sufficient on its own to authorize
	 * a replay — informed confirmation of the named target is the primary control.
	 *
	 * @return string sha256 hex digest, or '' when no binding is minted.
	 */
	private function mint_binding_proof(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Fetch metadata header, not user input.
		$fetch_site = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) : '';

		if ( 'same-origin' !== $fetch_site ) {
			return '';
		}

		if ( ! Sudo_Session::cookie_secure() || headers_sent() ) {
			return '';
		}

		// random_bytes(): unfilterable, unlike wp_generate_password(), whose
		// `random_password` filter a third-party plugin could collapse.
		try {
			$secret = bin2hex( random_bytes( 32 ) );
		} catch ( \Exception $e ) {
			return '';
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
		setcookie(
			self::BINDING_COOKIE,
			$secret,
			array(
				'expires'  => time() + self::TTL,
				// __Host- requires path=/ and forbids an explicit domain.
				'path'     => '/',
				'secure'   => true,
				'httponly' => true,
				'samesite' => 'Strict',
			)
		);

		$_COOKIE[ self::BINDING_COOKIE ] = $secret; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

		return hash( 'sha256', $secret );
	}

	/**
	 * Get a safe return URL for flows that cannot replay redacted secrets.
	 *
	 * @return string Referrer URL or an empty string.
	 */
	private function get_return_url(): string {
		$return_url = wp_get_referer();

		return is_string( $return_url ) ? $return_url : '';
	}

	/**
	 * Retrieve a stashed request.
	 *
	 * Returns the stashed data only if it exists, has not expired,
	 * and belongs to the specified user.
	 *
	 * @param string $key     The stash key.
	 * @param int    $user_id The user who must own the stash.
	 * @return array<string, mixed>|null The stashed data, or null.
	 */
	public function get( string $key, int $user_id ): ?array {
		if ( empty( $key ) ) {
			return null;
		}

		$data = $this->get_stash_transient( self::TRANSIENT_PREFIX . $key );

		if ( ! $data || ! is_array( $data ) ) {
			return null;
		}

		// Verify ownership.
		if ( ( $data['user_id'] ?? 0 ) !== $user_id ) {
			return null;
		}

		return $data;
	}

	/**
	 * Delete a stashed request (one-time use).
	 *
	 * @param string $key     The stash key.
	 * @param int    $user_id Optional. The user ID. If > 0, removes the key from
	 *                        the user's stash index. Defaults to 0 (skip index cleanup).
	 *                        Pass user_id when available for cleaner index maintenance.
	 * @return void
	 */
	public function delete( string $key, int $user_id = 0 ): void {
		if ( ! empty( $key ) ) {
			$this->delete_stash_transient( self::TRANSIENT_PREFIX . $key );

			if ( $user_id > 0 ) {
				$this->remove_from_stash_index( $user_id, $key );
			}
		}
	}

	/**
	 * Check whether a stash key is valid for a user without consuming it.
	 *
	 * @param string $key     The stash key.
	 * @param int    $user_id The user ID.
	 * @return bool
	 */
	public function exists( string $key, int $user_id ): bool {
		return null !== $this->get( $key, $user_id );
	}

	/**
	 * Get the current HTTP request method.
	 *
	 * @return string 'GET', 'POST', etc.
	 */
	private function get_request_method(): string {
		return strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) );
	}

	/**
	 * Reconstruct the original request URL.
	 *
	 * Uses esc_url_raw() instead of sanitize_text_field() because
	 * REQUEST_URI may contain percent-encoded characters (e.g. %2F
	 * in plugin slugs like "my-plugin%2Fplugin.php") that
	 * sanitize_text_field() strips entirely, corrupting the URL.
	 *
	 * @return string Full URL including query string.
	 */
	private function build_original_url(): string {
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? 'localhost' ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw() on the full URL below; sanitize_text_field() strips percent-encoded characters (%2F, etc.).
		$uri = wp_unslash( $_SERVER['REQUEST_URI'] ?? '/wp-admin/' );

		return esc_url_raw( $scheme . '://' . $host . $uri );
	}

	/**
	 * Store a stash transient, network-wide on multisite.
	 *
	 * @param string $key   Transient key.
	 * @param mixed  $value Transient value.
	 * @param int    $ttl   Time-to-live in seconds.
	 * @return void
	 */
	private function set_stash_transient( string $key, mixed $value, int $ttl ): void {
		if ( is_multisite() ) {
			set_site_transient( $key, $value, $ttl );
		} else {
			set_transient( $key, $value, $ttl );
		}
	}

	/**
	 * Retrieve a stash transient, network-wide on multisite.
	 *
	 * @param string $key Transient key.
	 * @return mixed Transient value or false.
	 */
	private function get_stash_transient( string $key ): mixed {
		return is_multisite() ? get_site_transient( $key ) : get_transient( $key );
	}

	/**
	 * Delete a stash transient, network-wide on multisite.
	 *
	 * @param string $key Transient key.
	 * @return void
	 */
	private function delete_stash_transient( string $key ): void {
		if ( is_multisite() ) {
			delete_site_transient( $key );
		} else {
			delete_transient( $key );
		}
	}

	/**
	 * Enforce the per-user stash cap, evicting the oldest entry if at the limit.
	 *
	 * Calls delete_stash_transient() unconditionally (no existence check) because
	 * the index may contain keys for transients that have already expired naturally.
	 * delete_transient() on a missing key is a no-op; the stale index entry is
	 * still removed, self-correcting the index.
	 *
	 * @param int $user_id The user ID.
	 * @return void
	 */
	private function enforce_stash_cap( int $user_id ): void {
		$keys      = $this->get_stash_index( $user_id );
		$key_count = count( $keys );

		while ( $key_count >= self::MAX_STASH_PER_USER ) {
			$oldest = array_shift( $keys );

			if ( is_string( $oldest ) && '' !== $oldest ) {
				$this->delete_stash_transient( self::TRANSIENT_PREFIX . $oldest );
			}

			$key_count = count( $keys );
		}

		$this->set_stash_index( $user_id, $keys );
	}

	/**
	 * Append a stash key to the user's index.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $key     The stash key (without prefix).
	 * @return void
	 */
	private function add_to_stash_index( int $user_id, string $key ): void {
		$keys   = $this->get_stash_index( $user_id );
		$keys[] = $key;
		$this->set_stash_index( $user_id, $keys );
	}

	/**
	 * Remove a stash key from the user's index.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $key     The stash key to remove (without prefix).
	 * @return void
	 */
	private function remove_from_stash_index( int $user_id, string $key ): void {
		$keys = $this->get_stash_index( $user_id );
		$keys = array_values(
			array_filter(
				$keys,
				static function ( $stash_key ) use ( $key ) {
					return $stash_key !== $key;
				}
			)
		);
		$this->set_stash_index( $user_id, $keys );
	}

	/**
	 * Retrieve the user's stash key index from user meta.
	 *
	 * @param int $user_id The user ID.
	 * @return string[] Array of stash keys (without prefix).
	 */
	private function get_stash_index( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::STASH_INDEX_META_KEY, true );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Persist the user's stash key index to user meta.
	 *
	 * User meta is network-wide on multisite (no switch_to_blog needed),
	 * consistent with the Sudo_Session lockout meta pattern.
	 *
	 * @param int      $user_id The user ID.
	 * @param string[] $keys    Array of stash keys.
	 * @return void
	 */
	private function set_stash_index( int $user_id, array $keys ): void {
		update_user_meta( $user_id, self::STASH_INDEX_META_KEY, $keys );
	}

	/**
	 * Build the POST payload that is safe to store and replay for the matched rule.
	 *
	 * @param array<string, mixed> $matched_rule             The matched action rule.
	 * @param string               $method                   Request method.
	 * @param bool                 $redacted_fields_omitted  Whether any sensitive field was omitted.
	 * @param bool                 $post_replay_blocked      Whether automatic POST replay is blocked.
	 * @param string               $post_replay_block_reason Replay block reason.
	 * @return array<string, mixed> Sanitized, allowlisted POST params.
	 */
	private function build_stashed_post_params(
		array $matched_rule,
		string $method,
		bool &$redacted_fields_omitted,
		bool &$post_replay_blocked,
		string &$post_replay_block_reason
	): array {
		if ( 'POST' !== $method || empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence check only; values are filtered below for replay storage after gate matching.
			return array();
		}

		$stash_policy = $this->get_stash_policy( $matched_rule );
		$post_mode    = $stash_policy['post_mode'];

		if ( 'none' === $post_mode ) {
			$post_replay_blocked      = true;
			$post_replay_block_reason = self::REPLAY_BLOCKED_NO_REPLAY;
			return array();
		}

		$post_fields = $stash_policy['post_fields'];
		if ( empty( $post_fields ) ) {
			$post_replay_blocked      = true;
			$post_replay_block_reason = self::REPLAY_BLOCKED_NO_ALLOWLIST;
			return array();
		}

		return $this->sanitize_params(
			$this->filter_top_level_params( $_POST, $post_fields ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$redacted_fields_omitted
		);
	}

	/**
	 * Resolve replay policy metadata from a rule.
	 *
	 * @param array<string, mixed> $matched_rule The matched action rule.
	 * @return array{post_mode:string, post_fields:string[]}
	 */
	private function get_stash_policy( array $matched_rule ): array {
		$policy = $matched_rule['stash'] ?? array();
		if ( ! is_array( $policy ) ) {
			$policy = array();
		}

		$post_mode = isset( $policy['post_mode'] ) && is_string( $policy['post_mode'] )
			? $policy['post_mode']
			: 'allowlist';

		if ( ! in_array( $post_mode, array( 'allowlist', 'none' ), true ) ) {
			$post_mode = 'allowlist';
		}

		$post_fields = array();
		if ( isset( $policy['post_fields'] ) && is_array( $policy['post_fields'] ) ) {
			foreach ( $policy['post_fields'] as $field ) {
				if ( is_string( $field ) && '' !== $field ) {
					$post_fields[] = $field;
				}
			}
		}

		return array(
			'post_mode'   => $post_mode,
			'post_fields' => array_values( array_unique( $post_fields ) ),
		);
	}

	/**
	 * Keep only top-level request params named by the replay allowlist.
	 *
	 * @param array<string, mixed> $params Raw request params.
	 * @param string[]             $fields Allowed top-level field names.
	 * @return array<string, mixed>
	 */
	private function filter_top_level_params( array $params, array $fields ): array {
		$result = array();
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $params ) ) {
				$result[ $field ] = $params[ $field ];
			}
		}

		return $result;
	}

	/**
	 * Recursively sanitize request parameters, omitting sensitive fields.
	 *
	 * Sensitive keys (passwords, tokens, API keys) are omitted entirely —
	 * not replaced with a placeholder. The JS replay mechanism submits all
	 * post_data fields verbatim as hidden form inputs; a sentinel value like
	 * __REDACTED__ would be submitted as the actual field value (e.g. as the
	 * new password), which is incorrect behavior.
	 *
	 * Key matching is case-insensitive. Nested array values are recursed.
	 * See sensitive_field_keys() for the filterable default list.
	 *
	 * @since 2.11.0
	 *
	 * @param array<string, mixed> $params                  Raw request parameters.
	 * @param bool                 $redacted_fields_omitted Whether any sensitive field was omitted.
	 * @return array<string, mixed> Sanitized parameters with sensitive keys omitted.
	 */
	private function sanitize_params( array $params, bool &$redacted_fields_omitted ): array {
		$sensitive = $this->sensitive_field_keys();

		$result = array();
		foreach ( $params as $key => $value ) {
			if ( is_array( $value ) ) {
				$result[ $key ] = $this->sanitize_params( $value, $redacted_fields_omitted );
			} elseif ( ! $this->is_sensitive_key( (string) $key, $sensitive ) ) {
				$result[ $key ] = $value;
			} else {
				$redacted_fields_omitted = true;
			}
			// Sensitive keys are omitted entirely — not stored, not sent to JS replay.
		}
		return $result;
	}

	/**
	 * Whether a request field key should be omitted from the stash.
	 *
	 * @param string   $key       Request field key.
	 * @param string[] $sensitive Exact-match sensitive keys.
	 * @return bool
	 */
	private function is_sensitive_key( string $key, array $sensitive ): bool {
		$key = strtolower( $key );

		if ( in_array( $key, $sensitive, true ) ) {
			return true;
		}

		foreach ( self::SENSITIVE_KEY_SUFFIXES as $suffix ) {
			if ( str_ends_with( $key, $suffix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the list of POST parameter keys to omit from the request stash.
	 *
	 * Keys are matched case-insensitively. Omitted fields are not replayed —
	 * the user must re-enter them after reauthentication. This is correct
	 * UX: for password changes, WordPress treats absent pass1/pass2 as
	 * "no password change requested."
	 *
	 * The list is intentionally a constant-length list of full key names,
	 * not substrings, to avoid false positives (e.g. matching "page" for "password").
	 *
	 * @since 2.11.0
	 *
	 * @return string[] Lowercase sensitive field key names.
	 */
	private function sensitive_field_keys(): array {
		// Memoized per request: the list is consulted by sanitize_params() (which
		// recurses) and by capture_target(), and the filter should be applied once per
		// stash, not once per nested field.
		if ( null !== $this->sensitive_keys_cache ) {
			return $this->sensitive_keys_cache;
		}

		$this->sensitive_keys_cache = $this->build_sensitive_field_keys();

		return $this->sensitive_keys_cache;
	}

	/**
	 * Memoized sensitive-key list for this instance.
	 *
	 * @var string[]|null
	 */
	private ?array $sensitive_keys_cache = null;

	/**
	 * Build the filterable sensitive-key list.
	 *
	 * @return string[] Lowercase sensitive field key names.
	 */
	private function build_sensitive_field_keys(): array {
		/**
		 * Filter the list of POST parameter keys that should be
		 * omitted from the request stash before storage.
		 *
		 * Keys are matched case-insensitively. Nested array keys are
		 * also matched. Omitted fields are not replayed — the user
		 * must re-enter them after reauthentication.
		 *
		 * @since 2.11.0
		 *
		 * @param string[] $keys Sensitive field key names (lowercase).
		 */
		return apply_filters(
			'wp_sudo_sensitive_stash_keys',
			array(
				'password',
				'user_pass',
				'pass1',
				'pass2',
				'pass1-text',
				'pwd',
				'token',
				'secret',
				'api_key',
				'api_secret',
				'auth_key',
				'auth_token',
				'access_token',
				'private_key',
			)
		);
	}
}
