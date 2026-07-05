<?php
/**
 * Shared user-identity display helpers.
 *
 * Single source of truth for how WP Sudo presents a user across admin
 * surfaces (the dashboard Session Activity widget and the Settings → Sudo
 * Access tab): a human-readable primary name preferred over the login, and
 * translated role labels. Keeping this in one place stops the two surfaces
 * from drifting apart.
 *
 * @package WP_Sudo
 */

declare(strict_types=1);

namespace WP_Sudo;

/**
 * Resolves display-friendly identity strings from a WP_User.
 */
final class User_Identity {

	/**
	 * The user's primary display name: their full real name when available,
	 * falling back to display_name, then to the login.
	 *
	 * WP_User name meta (first_name/last_name) is lazily loaded and may be
	 * absent, so every read is isset-guarded.
	 *
	 * @param \WP_User $user User object.
	 * @return string Non-empty display string (the login as a last resort).
	 */
	public static function primary_name( \WP_User $user ): string {
		$first = isset( $user->first_name ) && is_string( $user->first_name ) ? $user->first_name : '';
		$last  = isset( $user->last_name ) && is_string( $user->last_name ) ? $user->last_name : '';
		$full  = trim( $first . ' ' . $last );

		if ( '' !== $full ) {
			return $full;
		}

		$login   = isset( $user->user_login ) && is_string( $user->user_login ) ? $user->user_login : '';
		$display = isset( $user->display_name ) && is_string( $user->display_name ) ? $user->display_name : '';

		if ( '' !== $display && $display !== $login ) {
			return $display;
		}

		return $login;
	}

	/**
	 * Translated, human-readable role labels for a user — one entry per role.
	 *
	 * An orphaned/legacy role slug not present in the registered role map
	 * falls back to the raw slug (guarded against undefined-index). A user
	 * with no stored site role (e.g. a multisite super admin whose access
	 * comes from the network `site_admins` option) yields an empty array.
	 *
	 * @param \WP_User $user User object.
	 * @return string[] Zero or more translated role names.
	 */
	public static function role_labels( \WP_User $user ): array {
		if ( empty( $user->roles ) || ! is_array( $user->roles ) ) {
			return array();
		}

		$names  = wp_roles()->get_names();
		$labels = array();

		foreach ( $user->roles as $slug ) {
			$labels[] = isset( $names[ $slug ] )
				? translate_user_role( (string) $names[ $slug ] )
				: (string) $slug;
		}

		return $labels;
	}
}
