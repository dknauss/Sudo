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
| `wp_set_password()` | `wp-includes/pluggable.php:3099` (exact; writes `$wpdb->update` on `user_pass` at 3109) | Callers in `user.php` and `pluggable.php` only. **But pluggable — see caveat 1.** The line was originally recorded as approximate; verified exact on re-check, after a `^function` grep appeared to contradict it and was itself wrong (the definition is tab-indented inside the `function_exists` guard, which is *why* it is pluggable). |
| `WP_Application_Passwords::create_new_application_password()` | `wp-includes/class-wp-application-passwords.php:89` | Issues a credential that bypasses interactive reauth by design. |
| User row write | `wp-includes/user.php:2553` / `2555`, inside `wp_insert_user()` | `$wpdb->update`/`$wpdb->insert` on `$wpdb->users` — two branches, one function. Covers email change (which enables password reset). |

## Destruction — 2 mutation points

| Mutation point | Location |
|---|---|
| `wp_delete_user()` | `wp-admin/includes/user.php:345` |
| `remove_user_from_blog()` | `wp-includes/ms-functions.php:239` |

---

## Credential pivot: mapped, and reset issuance is not the critical seam

Mapped after the `install_package()` seam was built, to decide what the
second seam should be. The result inverted the assumption that reset
issuance mattered most.

Three ways a hijacked admin session reaches another account:

| Path | Route | Seam that stops it |
|---|---|---|
| **P1 — direct password set** | `edit_user()` sets `$user->user_pass` (`wp-admin/includes/user.php:187`) → `wp_update_user()` → the `wp_insert_user` row write. Immediate takeover, no email involved. | user row write, and/or `wp_set_password()` |
| **P2 — email change, then public reset** | Admin changes the target's email, then uses the **unauthenticated** "Lost your password?" form. The reset link goes to the attacker's address. | **email change only** |
| **P3 — admin-triggered reset** | `users.php:238` bulk `resetpassword`, and `retrieve_password()` (`user.php:3243`). | reset issuance |

**P3 is close to worthless to an attacker, and that is the finding.** The
reset link goes to the victim's *real* address, so it yields nothing unless
the email was already changed — and if it was, the attacker uses the public
form in P2 and never needs an admin-triggered reset at all. **Sealing reset
issuance does not break the pivot chain**, because the load-bearing step is
public and unauthenticated.

So the second seam should be **P1 (direct password set)** or **P2 (email
change)** — not reset issuance, which is where this was heading before the
map was drawn. P1 is the more direct takeover; P2 is the one that also
closes an asymmetry in shipping WordPress, where a user changing their own
email must confirm it but an admin changing someone else's need not.

## Coverage against the seven bypasses that killed the predecessor

`docs/finding.md` §2.1 records seven verified bypasses across six axes that
defeated the request-matching approach. They are the strongest available
acceptance test for this one: real, independently found, and fatal to the
thing they were found in. Running them against the seam architecture also
tested this census, and the census failed — it had missed a seam.

| Bypass | Effect reached | Seam |
|---|---|---|
| REST route capitalisation | whichever handler it reached | mechanism absent — a seam never parses routes |
| File editor write (POST vs `action=update`) | `wp_edit_theme_plugin_file()` | ✅ covered |
| `options.php` (`$_REQUEST` vs `$_POST`) | option write | ✅ **closeable** — a named-option seam; the earlier ❌ was based on an unmeasured claim, see below |
| Bulk promote (`changeit`) | capabilities usermeta | ✅ covered |
| REST plugin deactivation (method set) | `deactivate_plugins()` | ⚠️ **census had missed it** — now added |
| 2FA bridge (`$_REQUEST` user_id) | user meta / auth change | ⚠️ partial |
| `wp_ajax_add-user` (surface coverage) | `wp_insert_user` + capabilities | ✅ covered |

**All seven share one failure: predicate drift** — the plugin's guess about
a request disagreeing with Core's actual dispatch. Inside a seam there is
no predicate about the request, so route casing, superglobal choice, method
set, and evaluation order cannot produce a bypass. That failure mode is
absent by construction, not mitigated. The CLI result already demonstrates
it empirically: CLI is simply the most extreme case of "arrived by a path
nobody enumerated."

**But coverage is a separate question from mechanism, and the exercise
proved it.** One entry above is still not green (the 2FA bridge, partial),
and one was a seam this census had omitted entirely. A third — the option
write — was marked open on an assumption that later measurement
falsified.

## Seams added after the bypass cross-check

| Mutation point | Location | Why it was missed |
|---|---|---|
| `activate_plugin()` | `wp-admin/includes/plugin.php:641`, 6 caller files converging including the REST plugins controller and `ajax-actions.php` | The census enumerated code *arrival* and forgot code *activation* — the step that makes already-present code execute. |
| `deactivate_plugins()` | `wp-admin/includes/plugin.php:758` | Same omission; also the effect behind bypass #5. |
| `retrieve_password()` / `get_password_reset_key()` | `wp-includes/user.php:3243` / `3081` | Added for completeness, but see the pivot map: low priority. |
| `reset_password()` | `wp-includes/user.php:3492` | Consumption side of the same flow. |

## The option-write class: the "no answer" claim was wrong, and measuring it said so

This section previously asserted that option writes could not be gated,
because Core and plugins write options constantly and a deliberate change
could not be told from incidental churn. **That was asserted twice and
never measured. Measuring it falsified it.**

Method: instrument `updated_option`/`added_option`, crawl the admin doing
nothing, then deliberately change dangerous settings through the real
form. (`mu-plugins/option-churn-probe.php`.)

| Scenario | Total option writes | Of which security-relevant |
|---|---|---|
| 20 admin pages + front page + cron spawn | 1 | **0** |
| Same, plus 34 forced cron events and update transients cleared | 3 | **0** |
| Settings → General submitted with `users_can_register=1`, `default_role=administrator` | 6 | **2 — both captured** |

The single write in the first row was this prototype's own binding
registry. The churn in the second is `_site_transient_update_core` and
`cron` — distinguishable from the dangerous set by name alone, without any
intent inference.

**So the option name IS a sufficient discriminator**, and a named-option
seam at `update_option()` is viable. The signal fires on deliberate change
and stays silent through ordinary use, which is exactly the property the
earlier claim said was unobtainable.

**This closes bypass #3.** `options.php` self-protection failed because the
plugin read `$_POST` while Core read `option_page` from `$_REQUEST`. At a
seam on the option write itself, which superglobal the request used is not
a fact the check consults. The bypass matrix above should be read with #3
moved to covered.

### What genuinely remains, stated narrowly

- **Measured on a stock install with few plugins.** A site running thirty
  plugins may write far more. The relevant question is narrower than
  volume, though: would a *plugin* write `siteurl` or `default_role`
  during ordinary operation? Migration and multisite tooling plausibly
  would. Unmeasured.
- **Extensibility is the real limit.** The dangerous list here is
  hand-picked from what an attacker would want. Plugins define their own
  security-relevant options — a security plugin's "disable two-factor"
  switch is as dangerous as `default_role` and Core cannot know it exists.
  A Core-owned list covers Core's own surface and nothing beyond it.
- **`active_plugins` is double-covered**, since `activate_plugin()` is
  also a seam. Harmless, but the descriptor should say which path is
  being authorised so an operator is not asked twice for one act.

That is a bounded extensibility problem, not the "no answer" this section
previously claimed.

## Result: ~14 seams

**Fourteen insertions, each at a point where the effect is fully
determined, versus 36+ request predicates that provably drift.** Fourteen
is still enumerable, auditable, and stable across releases in a way request
shapes are not — Core function signatures change slowly and visibly;
dispatch behaviour changes quietly.

The number moved from ten to fourteen when the seven bypasses were used as
a cross-check, which is the more useful result than either figure: **an
enumeration built forward from effect classes missed seams that an
independently derived attack list found immediately.** Expect it to move
again. The claim worth making is the order of magnitude and the drift
properties, never the completeness.

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
