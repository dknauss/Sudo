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
 * When a gated admin action is intercepted, the full request (URL, method,
 * GET/POST parameters, nonce) is serialized into a short-lived transient.
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

		$data = array(
			'user_id' => $user_id,
			'rule_id' => $matched_rule['id'] ?? '',
			'label'   => $matched_rule['label'] ?? '',
			'method'  => $this->get_request_method(),
			'url'     => $this->build_original_url(),
			'get'     => $this->sanitize_params( $_GET ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'post'    => $this->sanitize_params( $_POST ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'created' => time(),
		);

		$this->set_stash_transient( self::TRANSIENT_PREFIX . $key, $data, self::TTL );

		// Record key in user index AFTER successful write.
		$this->add_to_stash_index( $user_id, $key );

		return $key;
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
	 * @param array<string, mixed> $params Raw request parameters.
	 * @return array<string, mixed> Sanitized parameters with sensitive keys omitted.
	 */
	private function sanitize_params( array $params ): array {
		$sensitive = $this->sensitive_field_keys();

		$result = array();
		foreach ( $params as $key => $value ) {
			if ( is_array( $value ) ) {
				$result[ $key ] = $this->sanitize_params( $value );
			} elseif ( ! in_array( strtolower( (string) $key ), $sensitive, true ) ) {
				$result[ $key ] = $value;
			}
			// Sensitive keys are omitted entirely — not stored, not sent to JS replay.
		}
		return $result;
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
