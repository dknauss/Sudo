# Mutation-point census: how many seams would a Core effect boundary need?

**Date:** 2026-08-03. **Against:** WordPress 7.0.2 source, read inside a
running container (not from memory, not from documentation).

## Why this number decides the proposal

`FINDING.md` concluded that binding authorization to *capability names*
fails, because names are not stable referents for effects, and that the
binding has to happen where the effect is actually committed. That
conclusion implies a question nobody had answered:

> For each dangerous effect class, how many distinct mutation points exist,
> and do the entry points converge on them?

This matters because it is the whole argument against the predecessor
approach. WP Sudo enumerated **36 request-shape rules** and still leaked
**seven bypasses across six independent axes** (`docs/finding.md` §2.1),
because two independently maintained predicates over the same request
drift apart and nothing can detect the drift. If effect-level seams are
similarly numerous, nothing has been gained. If they are few and stable,
that is the case for a Core-owned boundary.

**Method.** Enumerate from known dangerous effect classes, locate the
irreversible write, then count *entry points converging on it* separately
from *mutation points*. High convergence is the property being tested.

---

## Code arrival — 3 mutation points

PHP reaching a location WordPress will execute.

| Mutation point | Location | Convergence |
|---|---|---|
| `WP_Upgrader::install_package()` | `wp-admin/includes/class-wp-upgrader.php:510`; writes via `move_dir()`/`copy_dir()` inside it | **Strongest seam in Core.** Called from exactly one place (`WP_Upgrader::run()`, line 898). All four subclasses — `Plugin_Upgrader`, `Theme_Upgrader`, `Language_Pack_Upgrader`, `Core_Upgrader` — reach it through 9 `$this->run(…)` call sites. Plugin, theme, and language packages all land here. |
| `update_core()` | `wp-admin/includes/update-core.php:1093` | Core files. **`Core_Upgrader::upgrade()` bypasses `install_package()`** and calls this directly (line 178), so it is a genuinely separate seam. |
| `wp_edit_theme_plugin_file()` | `wp-admin/includes/file.php:380` | 3 callers converge: `theme-editor.php`, `plugin-editor.php`, `ajax-actions.php`. Already returns `WP_Error` throughout. |

## Privilege — 2 mutation points

A principal becomes code-capable.

| Mutation point | Location | Note |
|---|---|---|
| Capabilities usermeta write | `wp-includes/class-wp-user.php` lines 563, 591, 635, 720, 737 | `add_role`, `remove_role`, `set_role`, `add_cap`, `remove_cap` — **five physical sites executing the identical statement** `update_user_meta( $this->ID, $this->cap_key, $this->caps )`. One semantic seam; becomes one physical seam with a small private-helper refactor, or needs five insertions without it. |
| `grant_super_admin()` | `wp-includes/capabilities.php:1215` | Writes `update_site_option( 'site_admins' )`. |

## Authentication material — 3 mutation points

| Mutation point | Location | Note |
|---|---|---|
| `wp_set_password()` | `wp-includes/pluggable.php:~3099` | Only 3 Core callers. **But pluggable — see caveat 1.** |
| `WP_Application_Passwords::create_new_application_password()` | `wp-includes/class-wp-application-passwords.php:89` | Issues a credential that bypasses interactive reauth by design. |
| User row write | `wp-includes/user.php:2553` / `2555`, inside `wp_insert_user()` | `$wpdb->update`/`$wpdb->insert` on `$wpdb->users` — two branches, one function. Covers email change (which enables password reset). |

## Destruction — 2 mutation points

| Mutation point | Location |
|---|---|
| `wp_delete_user()` | `wp-admin/includes/user.php:345` |
| `remove_user_from_blog()` | `wp-includes/ms-functions.php:239` |

---

## Result: ~10 seams

**Ten insertions, each at a point where the effect is fully determined,
versus 36+ request predicates that provably drift.** Ten is enumerable,
auditable, and stable across releases in a way request shapes are not —
core function signatures change slowly and visibly; dispatch behaviour
changes quietly.

That is the case for the Core proposal, and it is now a counted number
rather than an assumption.

## Caveats that must travel with this number

1. **`wp_set_password()` is pluggable.** Any plugin may redefine it, and a
   veto placed there simply disappears. That seam is not trustworthy as
   written. It would require Core to make the function non-pluggable, or
   the seam moves down to the `$wpdb->update` inside `wp_insert_user()`.
   This is the one seam in the census that does not currently work.
2. **The privilege seam is five sites, not one**, until refactored.
3. **This enumeration is not exhaustive.** It works forward from known
   dangerous effect classes; it is not an exhaustive search of every write
   in Core. A real proposal needs the exhaustive version, and should expect
   the number to grow — the claim here is about order of magnitude (ten,
   not a hundred), not about completeness.
4. **Direct `$wpdb` and direct filesystem writes bypass all ten.** Partly
   this is the conceded boundary (code already executing on the host), but
   only partly: a plugin calling `update_user_meta()` on the capabilities
   key directly never touches `WP_User`, and that is not host compromise.
5. **Multisite adds variants.** `remove_user_from_blog()` was found;
   others likely exist outside the paths searched here.

## Consequence for sequencing

`wp_edit_theme_plugin_file()` — the slice originally proposed as first —
is **one of three code-arrival points and the least valuable of them**. An
attacker who can install a plugin never opens the editor.

`install_package()` is the high-value seam: one function covering plugin,
theme, and language writes across every entry point that reaches them, and
— unlike the editor — it is reached by **both interactive and system
actors** (admin upload, REST install, cron auto-update). It is therefore
the only one of the three that exercises the actor-classification split the
architecture depends on.

Start there.
