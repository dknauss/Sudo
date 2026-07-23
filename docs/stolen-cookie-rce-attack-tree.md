# Threat Model: Stolen Administrator Session Cookie → RCE

**Status:** Working threat model. Complements [`security-model.md`](security-model.md) (the general boundaries) with a single worked adversary: an attacker holding a valid Administrator auth cookie, whose goal is server-side code execution.

**Why this adversary:** it is the concrete endpoint of the XSS-in-`wp-admin` class the WordPress security team now routinely classifies as RCE-facilitating — steal an admin's cookie, then walk one of several routes to the plugin/theme editor or installer. It is also the exact adversary WP Sudo exists to frustrate.

---

## 1. Attacker capabilities and the asymmetry we exploit

A stolen Administrator cookie gives the attacker **an authenticated session**:

- they can load any `wp-admin` page, so **nonces are not a defense** — any nonce the UI would generate, they can harvest;
- they can call REST and AJAX as that administrator.

What the cookie does **not** carry:

- the account **password**;
- any **2FA device or passkey**.

That gap is the entire basis of the defense:

> **Every sudo challenge demands the *actor's current password* (optionally plus a second factor). A cookie thief has neither.**

Therefore the model holds **if and only if there is no ungated route by which the attacker can obtain or set a credential** — because a credential the attacker controls lets them answer the challenge and every gate downstream of it collapses. The rest of this document is a search for such a route.

---

## 2. Tier 1 — terminal RCE actions (direct code write / execution)

These write or execute PHP directly. All are gated in the built-in registry (`includes/class-action-registry.php`).

| Route | Capability | Gated rule |
|---|---|---|
| Plugin file editor | `edit_plugins` | `editor.plugin` (also killed by `DISALLOW_FILE_EDIT`) |
| Theme file editor | `edit_themes` | `editor.theme` |
| Install plugin from wordpress.org | `install_plugins` | `plugin.install` |
| Upload plugin ZIP | `install_plugins` | `plugin.upload` |
| Update plugin → malicious payload | `update_plugins` | `plugin.update` |
| **Activate** plugin (runs its PHP) | `activate_plugins` | `plugin.activate` |
| Theme install / upload / switch / update | `install_themes`, `edit_theme_options` | `theme.install` / `theme.upload` / `theme.switch` / `theme.update` |
| Core update → attacker source | `update_core` | `core.update` |
| (Multisite) network-enable theme | `manage_network_themes` | `network.theme_enable` |

**Installing from wordpress.org is a legitimate RCE route**, not just uploading: any plugin's activation hook runs its own PHP, and the attacker can install a plugin they control or a known-vulnerable one and then exploit it. `plugin.install` + `plugin.activate` gate both the arrival and the execution.

### Route multiplicity is handled by effect-level backstops, not by enumeration

The concern "there are too many routes to the installer" is answered architecturally. Beyond request-pattern rules, the Gate arms **effect-level backstops** (`arm_effect_guards`, `classify_upgrader_effect`, `register_rest_backstop`, since 4.1.0) that hard-block unambiguous install/activate/delete **effects reached through non-enumerated handlers** — a custom AJAX action, an unusual admin-post target, or the core REST route `POST /wp/v2/plugins` (WP 5.5+, install+activate by slug). The guard sits on the *effect*, so a new or obscure route to the same effect is still caught. (Confirming test worth having: `POST /wp/v2/plugins` with `slug` + `status:active`.)

**Tier 1 verdict:** well covered.

---

## 3. Tier 2 — credential pivots (manufacture a principal that passes Tier 1)

Because Tier 1 is gated, the attacker's real move is to **mint an account whose password they chose**, log in fresh, and then answer every challenge. These are the routes that matter most.

| Pivot | Gated rule | Notes |
|---|---|---|
| Change **own** password | `user.change_password` | **Load-bearing.** Core requires no old password here; *only* sudo does. |
| Change another admin's password | `user.change_password` | challenge = the *actor's* password, which the thief lacks |
| Create a new admin | `user.create` (+ escalation guard) | |
| Promote existing user → admin | `user.promote` / `user.promote_profile` / escalation guard | |
| Create Application Password | `auth.app_password` | durable API credential; confirm REST `/users/<id>/application-passwords` coverage |
| Delete user | `user.delete` | |
| Grant super admin (multisite) | `network.super_admin` | |
| Change account email → password reset | `user.change_email` (added by this change) | **was** the open door — now closed (§4) |

---

## 4. Finding: account-email change was not gated — closed by this change

`user.change_password` **deliberately narrows to password-only**: its admin callback returns true only when `pass1`/`pass2` is present, with a source comment noting that `profile.php`/`user-edit.php` handle "bio, email, role, etc." under the same `action=update`. Before this change there was **no `user.change_email` rule**, and `options.critical` matches only the **site** `admin_email`/`new_admin_email` option — not a user's per-account email.

So the following **was ungated** — the attack this rule (added below) closes:

1. Cookie thief opens `user-edit.php` for another admin (or their own `profile.php`) and changes the **email** only (no password field) → the change commits with no challenge.
   - Editing *another* user's email via `user-edit.php` commits **immediately**, no confirmation.
   - Self-service email change on `profile.php` sends a confirmation link — but to the **new**, attacker-controlled address, so it is one extra click, not a mitigation.
2. Attacker triggers "Lost your password?" → the reset email lands in their inbox → they set a password **they now know**.
3. Fresh login as that admin → they answer every sudo challenge (change-password, editor, installer, …) → **RCE**.

This **defeats the entire model** for a cookie thief: it manufactures a known credential without ever passing a challenge. While this door is open, gating the editor and installer is moot.

### Fix (implemented)

A `user.change_email` rule now gates the pivot on both surfaces:

- **admin** — `pagenow` in `profile.php` / `user-edit.php`, `action=update`, gated when the submitted `email` differs (sanitized, case-insensitive) from the stored email of the edited user (self on `profile.php`, `user_id` target on `user-edit.php`);
- **rest** — `#^/wp/v2/users/(?:\d+|me)$#`, `POST`/`PUT`/`PATCH` (core's `WP_REST_Server::EDITABLE`), gated when an `email` param is present and differs from the stored email.

Comparing against the stored value (rather than gating any submit that merely *contains* an `email` field) avoids challenging no-op profile saves — the email field is always present and pre-filled, so a presence-only check would gate every save. The comparator **fails closed**: if an email is submitted but the target user or stored address can't be read, the request is gated. It is side-effect-free, so it is safe under the diagnostic request simulator.

Two design points, from the pre-implementation review:

- **Silent-drop fix (non-replayable profile saves).** `Gate::match_request()` returns the *first* matching rule, and on `user-edit.php` a role+email change matches `user.promote_profile` before `user.change_email`; a narrow per-rule allowlist would silently drop the email on replay. A shared "comprehensive" allowlist was tried but does not actually work — the profile form always submits empty `pass1`/`pass2`, which the stash redacts by field name, so replay is blocked for *every* gated profile save regardless. So `user.promote_profile`, `user.change_password`, and `user.change_email` are now explicitly **non-replayable** (`stash_no_replay()`): after reauth the user re-submits the form. This is honest, silent-drop-free, and simpler. (Independently flagged by two post-implementation reviews.)
- **Two-step confirmation.** Gating the *initiating* POST is sufficient: on `profile.php` that POST is what writes the pending `_new_email` and sends the confirmation link, so a challenged attacker never creates a pending change and the commit GET has nothing to act on. The confirm/dismiss GETs are intentionally left ungated.

**Scope:** admin UI + cookie-authenticated REST only, matching `user.change_password`'s non-interactive parity — a `wp user update --user_email=` under CLI/cron is governed by those surfaces' policy, not by a new function hook.

### Cross-check

The `consequential-actions` MVP and the core-gate spec both already list `core/change-own-email` / `core/change-user-email` — so the *proposal* catalog is more complete on email than the shipping plugin. This finding brings WP Sudo's own registry in line with them.

---

## 5. Tier 3 — verify / lower priority

- **Disable 2FA** on a reset-owned account — plugin scope (Two Factor), not core; a route to make a reset-owned account fully usable if it had 2FA. Candidate for a gated action in the 2FA bridge.
- **WXR importer** can create users (`import` capability) — not in the catalog; lower priority (arbitrary passwords are not easily set through it).
- **Application Password via REST** (`/users/<id>/application-passwords`) — confirm the `auth.app_password` rule covers the REST endpoint, not only the profile screen.
- **`upload_filetypes`** (enable PHP uploads → media becomes an RCE vector) — not a single-site core option; multisite is covered by `network.settings`.

---

## 6. Summary

- The **terminal RCE actions** (editor, install, upload, update, activate — plugin and theme) are gated and backstopped at the effect level, so route multiplicity does not require exhaustive enumeration.
- The **credential-manufacturing pivots** are gated: account-email change is now closed by the `user.change_email` rule (§4), which gates **`POST` as well as `PUT`/`PATCH`** (core's `WP_REST_Server::EDITABLE = 'POST, PUT, PATCH'`). **Dependency:** the sibling `user.change_password` / `user.promote` REST rules had the same `PUT`/`PATCH`-only gap (a stolen cookie could change a password or role via `POST /wp/v2/users/{id}` ungated); that fix ships separately (PR #213) and reaches this branch when it merges to `main`. On this branch *alone*, only `change_email` gates `POST` — so this row is fully closed only after #213 lands.
- **Known remaining gap (tracked separately):** `POST /wp/v2/settings` writes to critical options are not yet gated — the `options.critical` REST callback matches raw option names (`siteurl`, `admin_email`) while core keys the endpoint by `show_in_rest` names (`url`, `email`). Repointing `siteurl` yields the XSS-as-RCE primitive.
- The defense works because a cookie thief holds a session but not a password, and every gate demands the actor's password. **Scope (per [`security-model.md`](security-model.md)):** this invariant holds for the **enumerated core routes and effect-backstopped operations** above; it does *not* extend to custom plugin endpoints, direct database writes, or code already executing in-process. The claim is "no *gated core route* to RCE avoids a challenge," not an absolute one.
