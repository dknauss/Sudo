# Core-gate Unit 1a — verified seam inventory

Every row below was checked against **`WordPress/wordpress-develop` trunk, fetched 2026-07-26**, not from memory or from the earlier review panel. Where the panel or the spec is wrong, that is stated.

## Seams

| Seam | Verified behaviour | Usable veto today? |
|---|---|---|
| `add_user_to_blog` | `ms-functions.php:183` — `apply_filters( 'can_add_user_to_blog', true, $user_id, $role, $blog_id )`, then `if ( true !== $can_add_user ) { … return $can_add_user; }` — the `WP_Error` is returned to the caller. | **YES — no core patch.** Spec §6 row 7's "needs a signature/return change" is **wrong**. |
| `grant_super_admin` | `capabilities.php` — writes `update_site_option( 'site_admins', $super_admins )` **and discards its return**, then fires `do_action( 'granted_super_admin', $user_id )` and `return true`. Also returns `false` immediately when `$GLOBALS['super_admins']` is defined or the install is single-site. | **NO — needs a core patch.** A `pre_update_site_option_site_admins` veto produces a **false success**: the caller is told the grant succeeded and `granted_super_admin` fires while nothing was written. |
| `wp_delete_user` | `wp-admin/includes/user.php:346` — returns `bool`; `do_action( 'delete_user' )` at `:381` (pre-delete) and `'deleted_user'` at `:464`. No `pre_delete_user` filter. | **NO — needs a return-contract change.** Spec is right. |
| `switch_theme` | `theme.php` — writes, in order: `theme_switch_menu_locations`, `template`, `stylesheet`, `template_root`/`stylesheet_root` (or deletes both), `current_theme`; also mutates sidebar-widget state, then fires `switch_theme`/`after_switch_theme`. | **NO — needs a core patch.** Vetoing only `pre_update_option_template`/`_stylesheet` leaves the other writes applied → half-switched site reporting the new theme name while running the old one, with no error surfaced (`switch_theme()` returns `void`). |
| `personal_options_update` (self-email) | `wp-admin/user-edit.php:149` — `do_action( 'personal_options_update', $user_id )`; `send_confirmation_on_profile_email()` at `wp-includes/user.php:3864`. | **NO on the admin path — needs a core patch in `edit_user()`.** The REST self-update *can* veto via its `permission_callback`. |

## Mechanics

- **#319 cookie policy.** `pluggable.php:1192-1197` — the **auth** cookie is set on `ADMIN_COOKIE_PATH` + `PLUGINS_COOKIE_PATH`, but the **logged-in** cookie on `COOKIEPATH` + `SITECOOKIEPATH`, using a *separate* `$secure_logged_in_cookie`. `wp_get_session_token()` (`wp-includes/user.php`) reads `wp_parse_auth_cookie( '', 'logged_in' )`. **The proof cookie must mirror the logged-in cookie**, not the auth cookie and not `is_ssl()`.
- **#310 salt fallback.** `pluggable.php` `wp_salt()` — when the salt constants are undefined it reads `get_site_option( "{$scheme}_{$type}" )` and, when empty, generates via `wp_generate_password( 64, true, true )` and **`update_site_option()`**. So on those installs the salt lives in the DB and a SQLi *read* can obtain it. Confirms the HMAC's forgery-resistance is conditional — and why the cache-bypassing enforcement read (which is unconditional against the *cache-poisoning* primitive) must be required rather than "a complement".
- **No-actor / self-heal.** `validate_current_theme()` (`theme.php:898`) calls `switch_theme( WP_DEFAULT_THEME )` at `:927` with **no actor**, guarded only by `wp_installing()` and the `validate_current_theme` filter. A fail-closed veto without a carve-out prevents a broken site from self-healing.

## Correction to the review panel (and to `.planning/core-gate-unit1-review-synthesis.md`)

Synthesis finding **U-4** claimed *"`add_user_to_blog` and `grant_super_admin` already have veto filters — no core patches needed."*

**Half of that is wrong.** `add_user_to_blog` checks out. `grant_super_admin` does **not**: the option write's return is discarded, so a veto there is silently ignored and reported as success. The error ran in the **optimistic** direction — it made v1 look more landable than it is, which is exactly the kind of claim #320 turns on. Corrected here and in the spec.

## Core-patch tally for Unit 1a

Four of the five seams need core changes: `switch_theme`, `wp_delete_user` (return contract), `edit_user()` (self-email admin path), `grant_super_admin`. Only `add_user_to_blog` is free today. The spec must say this plainly rather than implying the seams are all hookable.
