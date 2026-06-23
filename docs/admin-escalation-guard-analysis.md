# Admin-Surface User Escalation Guard — Implications Analysis

> **Status: analysis / decision input.** Revisits the deferred "Item 2" from the
> gate-completeness work (PRs #102/#104). Not implemented. If implemented, the
> guard ships **behind an opt-out filter defaulting OFF** (decision recorded
> 2026-06-23). This document exists so the over-block surface is understood
> before any code lands.

## 1. The gap

The interactive admin backstop (`Gate::register_interactive_backstop` →
`arm_effect_guards`) and the REST effect backstop (`register_rest_backstop`)
both deliberately **exclude** `user.create` and `user.promote`. The
non-interactive path (`register_function_hooks`, CLI/cron/XML-RPC) *does* guard
them, because those surfaces are wholly policy-governed and headless.

So the uncovered vector is narrow: a **non-enumerated *interactive* admin
handler** (a third-party `admin-post.php` route, or programmatic
`wp_insert_user()` / `WP_User::add_role()` on an admin request) that
creates or promotes a user **while no sudo window is active**. Enumerated admin
flows (`users.php`, `user-new.php`, `user-edit.php`, `profile.php`) are already
redirected to the challenge by `intercept()` before the effect fires; enumerated
REST user routes (`PUT /wp/v2/users/<id>` with `roles`) are gated by
`intercept_rest()`. The attacker already needs an authenticated admin-context
request **plus** a custom escalating handler — real defense-in-depth, but narrow.

## 2. Why the blanket guard was rejected, and the narrowing

Guarding **all** `user.create` / `user.promote` on the admin surface hard-`403`s
legitimate non-enumerated flows that assign **low-privilege** roles. The fix is
to guard only **privilege escalation to administrator** (single-site) /
**super-admin** (multisite) — i.e. block only when a write *newly grants*
administrator-tier control. Everything below that (customer, subscriber,
contributor, author, editor; demotions; lateral changes) passes untouched.

The rest of this document tests whether that narrowing actually removes the
over-block.

## 3. WordPress core mechanics (where the guard would hook)

- **Role assignment writes per-user capabilities meta.** `WP_User::set_role()` /
  `add_role()` / `remove_role()` write the `{$wpdb->prefix}capabilities` user
  meta. The plugin already detects this key via
  `Gate::is_user_capabilities_meta_key()` and hooks `add_user_metadata` /
  `update_user_metadata` for the CLI `user.promote` guard — so the hook point is
  already proven in-tree.
- **`user.create`'s role is applied *after* the row insert.** `wp_insert_user()`
  fires `wp_pre_insert_user_data` **before** the DB insert, and that payload does
  **not** carry the role; the role is applied afterward via the capabilities meta
  write. Consequence: an admin-surface guard cannot see "is this a new admin?" at
  pre-insert time, and blocking at the later capabilities write would `wp_die()`
  *after* the user row exists → an **orphaned, roleless user**. → **Creation must
  be excluded** from the admin guard; admin *creation* still surfaces as an
  administrator capabilities write and is caught there as a *promotion*, at the
  cost of the orphan caveat (mitigated by short-circuit, §6).
- **Multisite super-admin is NOT in capabilities meta.** `grant_super_admin()` /
  `revoke_super_admin()` store status in the network `site_admins` site option
  (`update_site_option( 'site_admins', … )`) and fire the `grant_super_admin`
  action (before) and `granted_super_admin` action (after), each with `$user_id`.
  So the per-blog capabilities guard will **not** catch super-admin grants; a
  separate hook on `grant_super_admin` is required.
  *(Verified against WP core `wp-includes/capabilities.php`, 2026-06-23.)*

## 4. Ecosystem implications

### WooCommerce — NOT affected by the narrowed guard (verified)

- `wc_create_new_customer()` creates users with `'role' => 'customer'` and never
  assigns `administrator`. It is the only `wp_insert_user()` caller in
  `includes/wc-user-functions.php`.
  *(Verified: WooCommerce `trunk/.../includes/wc-user-functions.php`, 2026-06-23.)*
- The WooCommerce REST customers controller exposes `role` as **`readonly`** in
  its schema, so a REST caller cannot request `administrator`; creation funnels
  to the customer role.
  *(Verified: `class-wc-rest-customers-controller.php` schema, 2026-06-23.)*

**Conclusion:** customer creation/registration/checkout-account and
`POST /wc/v3/customers` all assign `customer`, so a "newly-granted
administrator" guard never fires for them. This is the core evidence that the
narrowing removes the blanket version's over-block.

### Membership / LMS plugins — expected safe, NOT individually verified

MemberPress, LifterLMS, Paid Memberships Pro, etc. provision members into their
own membership/subscriber-tier roles, not `administrator`, by convention. This
was **not** individually verified here. The guard's safety for them rests on the
same invariant — *they do not grant the administrator role* — which should be
confirmed per integration before relying on it. (Per the project's verification
discipline: stated as an assumption, not a verified fact.)

### SSO / identity-sync — the real residual false positive

Plugins that **auto-provision administrators** (SAML/OIDC SSO mapping an IdP
group to `administrator`, directory-sync tools) perform exactly the guarded
action — granting admin — and often on a non-enumerated request with no sudo
window. The narrowed guard **would block these**. This is the single concrete
breakage case and the reason the guard must ship **default OFF** behind an
opt-out/opt-in filter (§6). It is defensible to *gate* admin provisioning (it is
privilege-sensitive and matches the plugin's thesis), but it must not be the
out-of-the-box default.

## 5. Surface boundaries (important scoping clarification)

The admin backstop arms on `admin_init` — it does **not** run for REST requests.
So an admin-surface escalation guard does **not** cover:

- **Custom REST routes** that escalate (e.g. a plugin's own
  `/my-plugin/v1/make-admin`). Those would need the *REST* backstop to also gain
  a `user.promote` guard — a separate change with its own headless/app-password
  policy and JSON-`wp_die` considerations (see #104's `register_rest_backstop`).
  WooCommerce's `/wc/v3/customers` is a custom REST route but is safe (role
  `customer`, readonly), so it is not a motivator here.
- **CLI/cron/XML-RPC** — already covered by `register_function_hooks` (which
  blocks *all* role changes there).

This means a fully-symmetric "escalation can never happen without sudo" property
would require guards on **three** surfaces (admin, REST, non-interactive), of
which only the non-interactive one exists today. Item 2 as scoped here closes
only the **interactive admin** third.

## 6. Implementation shape (if approved)

- **Hooks:** reuse the `add_user_metadata` / `update_user_metadata` filter on the
  `{prefix}capabilities` key; additionally hook `grant_super_admin` for the
  multisite path.
- **Trigger condition:** block only when the write **newly grants**
  `administrator` (single-site) or super-admin (multisite) AND no
  `Sudo_Session::is_active()/is_within_grace()`. "Newly grants" = administrator
  present in the new value but absent from the user's current capabilities (use
  the existing caps, not solely the filter's `$prev_value`, which is empty for
  `add_user_metadata`).
- **Detection basis:** role-name `administrator` is the v1 choice for precision
  and low false positives. A capability-based check (gaining `manage_options` /
  `promote_users` / `edit_users`) would catch custom admin-equivalent roles but
  raises false positives; defer unless a concrete bypass is shown.
- **Block mechanism:** prefer **short-circuit-return** from the metadata filter
  (return the `$check` short-circuit value to *prevent* the capabilities write)
  over `wp_die()` mid-write, to avoid half-applied state; pair with the standard
  `wp_sudo_action_blocked` audit (surface `admin`) so the block is observable.
  *(Open question for the design review: a silent short-circuit may confuse an
  operator who sees no error; a redirect-to-challenge is not possible this deep
  in the write. Weigh short-circuit+audit vs. `wp_die` with a clear 403.)*
- **Opt-out filter:** `wp_sudo_guard_admin_escalation` (name TBD), **default
  `false`** (OFF) per the 2026-06-23 decision — security-conscious sites opt in;
  SSO/provisioning sites are unaffected by default.
- **Exclude `user.create`** from the admin guard (orphan problem, §3).

## 7. Required TDD scenarios (before any implementation)

1. Promote existing user to `administrator` via the meta filter, no sudo, filter
   ON → blocked + `wp_sudo_action_blocked('admin')`; capabilities write
   prevented.
2. Same, sudo active/grace → allowed silently, write proceeds.
3. Assign `customer` / `subscriber` / `editor` (non-admin), no sudo, filter ON →
   **allowed** (no over-block) — the WooCommerce-shaped case.
4. Idempotent write of `administrator` to a user who already has it → not treated
   as a new grant → allowed.
5. Filter OFF (default) → guard inert regardless of role.
6. Multisite `grant_super_admin`, no sudo, filter ON → blocked; revoke/lateral →
   allowed.
7. Enumerated `user-edit.php` role change → still handled by `intercept()` (guard
   does not double-fire).

## 8. Recommendation

The narrowed, escalation-only, **default-OFF**, creation-excluded guard is
**safe enough to implement** for the interactive-admin third of the surface,
*provided* the opt-out default is OFF and the membership/SSO implications above
are documented for operators. The marginal security gain is modest (narrow
vector; attacker already admin-context), so this remains optional hardening
rather than a correctness fix. A formal Pre-Implementation Design Review (per
`CLAUDE.md`) and the §7 TDD scenarios are prerequisites to writing code.

## Verification sources

- WooCommerce `wc_create_new_customer()` role: `woocommerce/trunk` →
  `plugins/woocommerce/includes/wc-user-functions.php` (GitHub raw, 2026-06-23).
- WooCommerce REST customer `role` readonly: `class-wc-rest-customers-controller.php`
  schema (GitHub raw, 2026-06-23).
- WordPress super-admin storage/hooks: `wp-includes/capabilities.php`
  (`grant_super_admin` / `revoke_super_admin`, GitHub raw, 2026-06-23).
- In-tree mechanics: `includes/class-gate.php`
  (`register_function_hooks`, `is_user_capabilities_meta_key`,
  `register_interactive_backstop`, `register_rest_backstop`).
