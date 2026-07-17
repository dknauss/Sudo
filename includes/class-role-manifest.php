<?php
/**
 * Role_Manifest — file-backed manifest of trusted privileged principals (#179).
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and validates the opt-in, file-backed role/capability lockdown manifest.
 *
 * @since 4.8.0
 */
class Role_Manifest {

	/**
	 * Constant naming the absolute manifest path (opt-in; no default path).
	 *
	 * @var string
	 */
	public const CONSTANT = 'WP_SUDO_ROLE_MANIFEST';

	/**
	 * Supported manifest schema version.
	 *
	 * @var int
	 */
	public const VERSION = 1;

	/**
	 * Resolve the configured manifest path from the opt-in constant.
	 *
	 * @return string|null Absolute path, or null when the feature is not enabled.
	 */
	public static function configured_path(): ?string {
		if ( ! defined( self::CONSTANT ) ) {
			return null;
		}

		$path = constant( self::CONSTANT );

		return is_string( $path ) && '' !== $path ? $path : null;
	}

	/**
	 * Whether the lockdown-audit feature is enabled (manifest path configured).
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return null !== self::configured_path();
	}

	/**
	 * Parse and normalize a manifest JSON string.
	 *
	 * Returns null — not a fatal — for any malformed or unsupported input, so a
	 * corrupt manifest degrades to "feature inert + warning" rather than crashing
	 * the site. The integrity of this file is the whole point of the feature, so
	 * defensive parsing here is a security requirement, not a test shim.
	 *
	 * @param string $json Raw manifest JSON.
	 * @return array<string, mixed>|null Normalized manifest, or null when invalid.
	 */
	public static function parse( string $json ): ?array {
		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		// Reject unsupported/absent schema versions rather than guessing.
		if ( ! isset( $decoded['manifest_version'] ) || self::VERSION !== (int) $decoded['manifest_version'] ) {
			return null;
		}

		return array(
			'manifest_version' => self::VERSION,
			'sites'            => self::normalize_sites( $decoded['sites'] ?? array() ),
			'network'          => array(
				'super_admins' => self::normalize_ids( ( $decoded['network']['super_admins'] ?? array() ) ),
			),
			'privileged_roles' => self::normalize_roles( $decoded['privileged_roles'] ?? array() ),
		);
	}

	/**
	 * Load, read, and parse the manifest file.
	 *
	 * @param string|null $path Absolute path; defaults to the configured path.
	 * @return array<string, mixed>|null Normalized manifest, or null when unavailable/invalid.
	 */
	public static function load( ?string $path = null ): ?array {
		$path ??= self::configured_path();

		if ( null === $path || ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Reading a local, operator-controlled config file, not a remote resource.
		$contents = file_get_contents( $path );

		if ( ! is_string( $contents ) ) {
			return null;
		}

		return self::parse( $contents );
	}

	/**
	 * Normalize the per-site principal allowlists.
	 *
	 * @param mixed $sites Raw sites section.
	 * @return array<int, array{administrators: int[], governance: int[]}>
	 */
	private static function normalize_sites( $sites ): array {
		if ( ! is_array( $sites ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $sites as $blog_id => $entry ) {
			$entry                        = is_array( $entry ) ? $entry : array();
			$normalized[ (int) $blog_id ] = array(
				'administrators' => self::normalize_ids( $entry['administrators'] ?? array() ),
				'governance'     => self::normalize_ids( $entry['governance'] ?? array() ),
			);
		}

		return $normalized;
	}

	/**
	 * Normalize a list of principal IDs to a sorted, unique int list.
	 *
	 * @param mixed $ids Raw ID list.
	 * @return int[]
	 */
	private static function normalize_ids( $ids ): array {
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$ints = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ints );

		return $ints;
	}

	/**
	 * Normalize the privileged-role hash map to string => string.
	 *
	 * @param mixed $roles Raw privileged_roles section.
	 * @return array<string, string>
	 */
	private static function normalize_roles( $roles ): array {
		if ( ! is_array( $roles ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $roles as $role => $hash ) {
			if ( is_scalar( $hash ) ) {
				$normalized[ (string) $role ] = (string) $hash;
			}
		}

		return $normalized;
	}
}
