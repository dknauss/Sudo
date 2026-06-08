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
		);

		$this->set_stash_transient( self::TRANSIENT_PREFIX . $key, $data, self::TTL );

		// Record key in user index AFTER successful write.
		$this->add_to_stash_index( $user_id, $key );

		return $key;
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
