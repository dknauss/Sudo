<?php
/**
 * Role_Audit — detects role/capability drift against the trusted manifest (#179).
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audit-only drift detection for privileged principals and role definitions.
 *
 * Pure comparison + hashing here; the WP-integration surface (state collection,
 * sweep, event firing) is layered on top in a later slice.
 *
 * @since 4.8.0
 */
class Role_Audit {

	/**
	 * Collect the current privileged state from WordPress.
	 *
	 * @param array<string, mixed> $manifest Manifest whose `privileged_roles` keys
	 *                                        name the roles whose definitions to hash.
	 * @return array<string, mixed> Current state in the manifest's normalized shape.
	 */
	public static function collect_current_state( array $manifest ): array {
		$blog_id = get_current_blog_id();

		$administrators = array_map(
			'intval',
			get_users(
				array(
					'role'   => 'administrator',
					'fields' => 'ID',
				) 
			) 
		);

		$governance = array();
		foreach ( wp_sudo_governance_caps() as $cap ) {
			$governance = array_merge(
				$governance,
				array_map(
					'intval',
					get_users(
						array(
							'capability' => $cap,
							'fields'     => 'ID',
						) 
					) 
				) 
			);
		}

		sort( $administrators );
		$governance = array_values( array_unique( $governance ) );
		sort( $governance );

		$state = array(
			'sites'            => array(
				$blog_id => array(
					'administrators' => $administrators,
					'governance'     => $governance,
				),
			),
			'network'          => array( 'super_admins' => array() ),
			'privileged_roles' => array(),
		);

		if ( is_multisite() ) {
			$supers = array();
			foreach ( get_super_admins() as $login ) {
				$user = get_user_by( 'login', $login );
				if ( $user instanceof \WP_User ) {
					$supers[] = (int) $user->ID;
				}
			}
			sort( $supers );
			$state['network']['super_admins'] = $supers;
		}

		// Hash each role the manifest watches; a watched role that no longer exists
		// is simply absent here, which diff() reports as drift.
		$roles   = wp_roles();
		$watched = is_array( $manifest['privileged_roles'] ?? null ) ? array_keys( $manifest['privileged_roles'] ) : array();
		foreach ( $watched as $role ) {
			$role = (string) $role;
			if ( isset( $roles->roles[ $role ]['capabilities'] ) && is_array( $roles->roles[ $role ]['capabilities'] ) ) {
				$state['privileged_roles'][ $role ] = self::hash_role_definition( $roles->roles[ $role ]['capabilities'] );
			}
		}

		return $state;
	}

	/**
	 * Evaluate current state against a manifest, firing the drift action on drift.
	 *
	 * @param array<string, mixed> $manifest Normalized manifest.
	 * @return array<string, mixed> The drift report.
	 */
	public static function evaluate( array $manifest ): array {
		$report = self::diff( $manifest, self::collect_current_state( $manifest ) );

		if ( $report['has_drift'] ) {
			/**
			 * Fires when the audit sweep detects role/capability drift from the
			 * trusted manifest.
			 *
			 * @since 4.8.0
			 *
			 * @param array<string, mixed> $report Drift report (unauthorized principals + role mismatches).
			 */
			do_action( 'wp_sudo_role_drift_detected', $report );
		}

		return $report;
	}

	/**
	 * Load the configured manifest and run a sweep, or null when the feature is
	 * disabled or the manifest is unreadable.
	 *
	 * @return array<string, mixed>|null The drift report, or null.
	 */
	public static function run_sweep(): ?array {
		if ( ! Role_Manifest::is_enabled() ) {
			return null;
		}

		$manifest = Role_Manifest::load();

		if ( null === $manifest ) {
			// Malformed/unreadable manifest: never fatal, never a false all-clear.
			// The Site Health / CLI surfaces warn "manifest unreadable" (later slice).
			return null;
		}

		return self::evaluate( $manifest );
	}

	/**
	 * Hash a role's capability map, canonicalized so ordering and false-valued
	 * capabilities do not produce spurious drift.
	 *
	 * @param array<string, mixed> $caps Role capability map (cap => bool).
	 * @return string A `sha256:`-prefixed hash of the granted capability set.
	 */
	public static function hash_role_definition( array $caps ): string {
		// Canonicalize: keep only granted caps (truthy), so a cap set to false
		// hashes the same as an absent cap; sort so key order is irrelevant.
		$granted = array_keys( array_filter( $caps ) );
		sort( $granted );

		// Cap slugs are [a-z0-9_]; a sorted newline-joined list is a stable,
		// collision-safe canonical form for the hash.
		return 'sha256:' . hash( 'sha256', implode( "\n", $granted ) );
	}

	/**
	 * Compare current privileged state to the manifest and report drift.
	 *
	 * Drift = principals present in the current state but absent from the
	 * manifest allowlist (unauthorized additions), plus privileged-role
	 * definition hashes that differ from (or are missing vs.) the manifest.
	 *
	 * @param array<string, mixed> $manifest Normalized manifest.
	 * @param array<string, mixed> $current  Current collected state (same shape).
	 * @return array<string, mixed> Drift report with a boolean `has_drift`.
	 */
	public static function diff( array $manifest, array $current ): array {
		$report = array(
			'has_drift' => false,
			'sites'     => array(),
			'network'   => array( 'super_admins' => array() ),
			'roles'     => array(),
		);

		// Per-site unauthorized principals: present in current, absent from the
		// manifest allowlist. Removals (manifest \ current) are NOT flagged — a
		// missing trusted admin is not an escalation.
		$manifest_sites = is_array( $manifest['sites'] ?? null ) ? $manifest['sites'] : array();
		$current_sites  = is_array( $current['sites'] ?? null ) ? $current['sites'] : array();

		foreach ( $current_sites as $blog_id => $entry ) {
			$allowed_admins = self::allowed( $manifest_sites, (int) $blog_id, 'administrators' );
			$allowed_gov    = self::allowed( $manifest_sites, (int) $blog_id, 'governance' );

			$unauth_admins = self::unauthorized( $entry['administrators'] ?? array(), $allowed_admins );
			$unauth_gov    = self::unauthorized( $entry['governance'] ?? array(), $allowed_gov );

			if ( array() !== $unauth_admins || array() !== $unauth_gov ) {
				$report['sites'][ (int) $blog_id ] = array(
					'administrators' => $unauth_admins,
					'governance'     => $unauth_gov,
				);
				$report['has_drift']               = true;
			}
		}

		// Unauthorized super admins.
		$allowed_supers = is_array( $manifest['network']['super_admins'] ?? null ) ? $manifest['network']['super_admins'] : array();
		$current_supers = is_array( $current['network']['super_admins'] ?? null ) ? $current['network']['super_admins'] : array();
		$unauth_supers  = self::unauthorized( $current_supers, $allowed_supers );

		if ( array() !== $unauth_supers ) {
			$report['network']['super_admins'] = $unauth_supers;
			$report['has_drift']               = true;
		}

		// Privileged-role definition drift. The manifest declares which roles are
		// watched and their expected hash; a differing OR missing current hash is
		// drift. Extra current roles the manifest does not watch are ignored.
		$manifest_roles = is_array( $manifest['privileged_roles'] ?? null ) ? $manifest['privileged_roles'] : array();
		$current_roles  = is_array( $current['privileged_roles'] ?? null ) ? $current['privileged_roles'] : array();

		foreach ( $manifest_roles as $role => $expected ) {
			$actual = $current_roles[ $role ] ?? null;

			if ( $actual !== $expected ) {
				$report['roles'][ (string) $role ] = array(
					'expected' => (string) $expected,
					'actual'   => is_string( $actual ) ? $actual : null,
				);
				$report['has_drift']               = true;
			}
		}

		return $report;
	}

	/**
	 * The manifest's allowlist for a blog's principal set, defaulting to empty.
	 *
	 * @param array<int, mixed> $manifest_sites Manifest sites section.
	 * @param int               $blog_id        Blog ID.
	 * @param string            $key            'administrators' or 'governance'.
	 * @return int[]
	 */
	private static function allowed( array $manifest_sites, int $blog_id, string $key ): array {
		$list = $manifest_sites[ $blog_id ][ $key ] ?? array();

		return is_array( $list ) ? $list : array();
	}

	/**
	 * Principals present in the current set but absent from the allowlist.
	 *
	 * @param int[] $current Current principal IDs.
	 * @param int[] $allowed Manifest-allowed principal IDs.
	 * @return int[] Sorted, re-indexed list of unauthorized IDs.
	 */
	private static function unauthorized( array $current, array $allowed ): array {
		$diff = array_values( array_diff( $current, $allowed ) );
		sort( $diff );

		return $diff;
	}
}
