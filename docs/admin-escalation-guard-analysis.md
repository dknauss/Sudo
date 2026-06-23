# User Privilege-Escalation Guard — Implications Analysis

> **Status: analysis / decision input.** Began as the deferred "Item 2"
> (admin-surface escalation guard) from the gate-completeness work
> (PRs #102/#104). Not implemented. **Scope has since evolved:** the analysis now
> recommends an **effect-level** guard (hooked on the capabilities-meta write and
> `grant_super_admin`) that covers *all* surfaces uniformly, rather than an
> admin-`init`-only guard — because the exploit paths this defends against live on
> REST/AJAX/unauthenticated surfaces, not the interactive admin (§1, §5). If
> implemented, the guard ships **behind a filter defaulting OFF** (a recorded
> decision), with a documented path to default-ON once escape hatches are proven
> (§8). This document exists so the over-block surface is understood before any
> code lands.

## 1. The gap

The interactive admin backstop (`Gate::register_interactive_backstop` →
`arm_effect_guards`) and the REST effect backstop (`register_rest_backstop`)
both deliberately **exclude** `user.create` and `user.promote`. The
non-interactive path (`register_function_hooks`, CLI/cron/XML-RPC) *does* guard
them, because those surfaces are wholly policy-governed and headless.

As originally scoped, the uncovered vector looked narrow: a **non-enumerated
*interactive* admin handler** (a third-party `admin-post.php` route, or
programmatic `wp_insert_user()` / `WP_User::add_role()` on an admin request) that
creates or promotes a user **while no sudo window is active**. Enumerated admin
flows (`users.php`, `user-new.php`, `user-edit.php`, `profile.php`) are already
redirected to the challenge by `intercept()` before the effect fires; enumerated
REST user routes (`PUT /wp/v2/users/<id>` with `roles`) are gated by
`intercept_rest()`.

**That narrow framing understates the threat.** The high-value exploit paths for
this vulnerability class are *not* the interactive admin surface; they are
**unauthenticated or low-privilege REST / AJAX / front-end endpoints with broken
access control** — OWASP A01 (Broken Access Control), the most common serious
WordPress plugin vuln class. In Wordfence's 2024 Annual WordPress Security
Report, **privilege escalation ranked as the second most common vulnerability
type** disclosed that year; Patchstack's State of WordPress Security 2025 reports
that the **majority of disclosed vulnerabilities require no authentication**
(e.g. its 2025 mid-year breakdown puts only ~20.6% at Contributor and ~11.5% at
Subscriber level, the rest lower or unauthenticated). *(Figures are taken from
the published report summaries; the full report bodies were not machine-fetchable
at write time — treat as indicative, not exact, and re-verify before quoting in
shipping copy.)* What makes a reauth gate a *genuine mitigation* there — not mere
friction — is a structural property:

> **In the common exploit shapes, the attacker structurally cannot hold a sudo
> session.**

- **Unauthenticated** broken-endpoint exploit (the classic `role=administrator`
  POST to a missing-capability route): `current_user` is `0`, so no sudo session
  can possibly exist → an `administrator` grant is **blocked**.
- **Low-privilege authenticated** escalation (e.g. a subscriber escalating self to
  admin): that user cannot obtain a sudo session without the admin password →
  **blocked**.

So the reauth requirement becomes a capability the attacker **cannot forge**, and
— decisively — it holds **even when the vulnerable plugin's own access control is
completely broken**. That is the "don't trust the vulnerable code" property you
want from a mitigation, and it reframes the feature from modest defense-in-depth
into a real mitigation for the #1 broken-access-control category.

**Residual bypass (honest limit):** the property fails only if the escalation
fires *during a legitimate admin's own active sudo window* (e.g. CSRF or stored
XSS landing mid-session). That window is short (1–15 min) and much smaller than
the unauthenticated/low-priv surface the guard closes, but it is real.

## 2. Why the blanket guard was rejected, and the narrowing

Guarding **all** `user.create` / `user.promote` (on any surface) hard-`403`s
legitimate flows that assign **low-privilege** roles. The fix is
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
  *(Verified against WP core `wp-includes/capabilities.php`.)*

### Hook coverage matrix (verified against WP core)

Every role-name path to `administrator` converges on a `{prefix}capabilities`
user-meta write, because `WP_User::set_role()` and `WP_User::add_role()` both end
in `update_user_meta( $this->ID, $this->cap_key, $this->caps )` where
`$this->cap_key = $wpdb->get_blog_prefix( $site_id ) . 'capabilities'`. That meta
write fires `add_user_metadata` (first assignment, empty prev value) /
`update_user_metadata` (subsequent). So the two effect-level hooks
(`{prefix}capabilities` meta + `grant_super_admin`) cover:

| Admin-grant path | Reaches the guard via | Caught? |
|---|---|---|
| `wp_insert_user( ['role'=>'administrator'] )` | post-insert `set_role()` → caps meta | ✅ (as a promotion; creation excluded, §3) |
| `wp_update_user( ['role'=>...] )` | `set_role()` → caps meta | ✅ |
| `WP_User::set_role()` / `add_role()` | caps meta write | ✅ |
| Direct `update_user_meta( id, '{prefix}capabilities', … )` | the meta hooks fire directly | ✅ |
| REST `POST`/`PUT /wp/v2/users` with `roles` | core `wp_update_user` → `set_role()` | ✅ |
| WP-CLI `wp user create/set-role/add-role` | same core functions | ✅ (also already policy-governed) |
| Multisite `add_user_to_blog( $blog, $uid, 'administrator' )` | `$user->set_role()` on that blog → that blog's caps meta | ✅ (requires blog-prefixed key match, below) |
| Multisite `grant_super_admin( $uid )` | `grant_super_admin` action (before) | ✅ via the separate hook |

*(Verified: `wp-includes/class-wp-user.php` `set_role`/`add_role`/`cap_key`;
`wp-includes/ms-functions.php` `add_user_to_blog` → `set_role`;
`wp-includes/capabilities.php` `grant_super_admin`/`granted_super_admin`.)*

**Coverage requirements and known gaps:**

- **Blog-prefixed key match (multisite).** On secondary blogs `cap_key` is
  `{$base_prefix}{$blog_id}_capabilities`, not `{$base_prefix}capabilities`. The
  guard's `is_user_capabilities_meta_key()` must match the **blog-prefixed
  variants**, or per-blog admin grants on non-main sites slip through.
- **Both meta filters.** Hook `add_user_metadata` *and* `update_user_metadata` —
  brand-new users hit the `add` path (empty `$prev_value`).
- **Gap — runtime `user_has_cap` grants.** A plugin can grant administrator-tier
  *capabilities* at runtime via the `user_has_cap` / `map_meta_cap` filters
  **without writing capabilities meta**. A role-name/meta guard does **not** see
  this. It is the §6 deferred "capability-based check" tradeoff; document as a
  known limitation rather than chase it (high false-positive cost).
- **Gap — direct DB writes.** A raw `$wpdb->update` on the usermeta table bypasses
  the meta filters entirely. Out of scope: anything with direct DB access has
  already won.

## 4. Ecosystem implications

### WooCommerce — NOT affected by the narrowed guard (verified)

- `wc_create_new_customer()` creates users with `'role' => 'customer'` and never
  assigns `administrator`. It is the only `wp_insert_user()` caller in
  `includes/wc-user-functions.php`.
  *(Verified: WooCommerce `trunk/.../includes/wc-user-functions.php`.)*
- The WooCommerce REST customers controller exposes `role` as **`readonly`** in
  its schema, so a REST caller cannot request `administrator`; creation funnels
  to the customer role.
  *(Verified: `class-wc-rest-customers-controller.php` schema.)*

**Conclusion:** customer creation/registration/checkout-account and
`POST /wc/v3/customers` all assign `customer`, so a "newly-granted
administrator" guard never fires for them. This is the core evidence that the
narrowing removes the blanket version's over-block.

### Membership / LMS plugins — verified for LifterLMS and PMPro

- **LifterLMS** — front-end registration assigns the **`student`** role (its
  person/form handler sets the registration default role to `student`), never
  `administrator`. *(Verified: gocodebox/lifterlms trunk
  `includes/class.llms.person.handler.php`.)*
- **Paid Memberships Pro** — checkout creates the WordPress user with the **site
  default role**: `$wpuser->set_role( get_option( 'default_role', 'subscriber' ) )`,
  never `administrator`. The optional pmpro-roles add-on maps each membership
  level to a *custom* role — still not `administrator`. *(Verified:
  strangerstudios/paid-memberships-pro `preheaders/checkout.php`.)*
- **MemberPress** — closed-source / premium; **not verifiable from public
  source**. By convention it provisions members into a subscriber-tier role, but
  per the project's verification discipline this remains an **assumption**, not a
  verified fact; confirm against the licensed source before relying on it.

These confirm, for the two open-source leaders, the invariant the guard depends
on — *legitimate membership/LMS provisioning does not grant the administrator
role*. MemberPress is the one unverified gap.

### SSO / identity-sync / bulk-import — the real residual false positive (verified)

Plugins that **auto-provision administrators** perform exactly the guarded action
— granting admin — often on an *unattended* request (a 3am SSO login, a scheduled
directory sync) with no sudo window. The narrowed guard **would block these**.
This is the concrete breakage case and the reason the guard must ship **default
OFF** behind a filter (§6), with an **allowlist** for trusted provisioners.

Source-verified plugins that can reach `administrator` (and the mechanism, all of
which the effect-level guard catches — §3):

| Plugin | Can grant admin? | Mechanism (verified) |
|---|---|---|
| **Next Active Directory Integration** (NADI) | Yes — "Role Equivalent Groups" map any AD group → `administrator`; the literal `super admin` token → super-admin | `add_role('administrator')`; `grant_super_admin()` for the super-admin token *(`src/.../Role/Manager.php`)* |
| **WP SAML Auth** (Pantheon) | Yes — `default_role` set to `administrator`, or a SAML attribute via the `wp_saml_auth_insert_user` filter | `wp_insert_user(['role'=>…])` *(`inc/class-wp-saml-auth.php`)* |
| **Authorizer** (UH) | Yes — default-role / per-user dropdowns include `administrator` (single-site) | `wp_insert_user`/`set_role`/`add_role`/`add_user_to_blog` *(`src/authorizer/class-authorization.php`)* |
| **Import users from CSV with meta** (codection) | Yes — a CSV `role=administrator` column; no exclusion | `wp_insert_user`/`wp_update_user`/`set_role`; `add_user_to_blog` on multisite |
| **Trifoia Cognito Login** (`cognito-login`) | Yes — maps an **unvalidated** Cognito `custom:role` token straight to the WP role, no allowlist | `wp_insert_user(['role'=>$token['custom:role']])` *(`includes/units/user.php`)* |
| **Login with Google** (rtCamp) / **Google Apps Login** | Configurable — default-role / `rt_gauth_user_role` filter; free versions default to the site role (admin only if a site sets it) | `wp_insert_user(['role'=>…])` |
| **`import-users` addon** (the user-import engine split out of WP Ultimate CSV Importer) | Yes — CSV `role` column accepts **any** registered role incl. `administrator`, with **no** editable-roles check | `wp_insert_user`/`wp_update_user(['role'=>…])` *(`importExtensions/UsersImport.php`)* |
| **wp-openid** (nicko170; generic OIDC, Keycloak-capable) | Yes — configurable default role and a claim→`role` mapping; admin dropdown via `get_editable_roles()` | `wp_insert_user(['role'=>…])` *(`OpenID.php`)* |
| **miniOrange SAML / OAuth** | SAML: group→role mapping incl. admin in an **old (v3.0) mirror**; OAuth (v4.8 mirror) had none. Current free trunk **unverified** (see caveat) | `wp_update_user(['role'=>…])` |

Two plugins are *not* false positives: **OpenID Connect Generic** sets **no role**
in core (admin only if a site's own callback does it via its action/filter hooks),
and **WP OAuth Server** (`oauth2-provider`) is an identity *provider* — verified to
contain no user-creation/role-write code at all, so it cannot promote anyone.
**Keycloak** has no dedicated WP.org plugin; it is consumed through generic OIDC
clients (wp-openid, Authorizer, OpenID Connect Generic) whose role behaviour is
covered above. Still **UNVERIFIED** (premium/closed): the **WP All Import** user
add-on, miniOrange premium attribute→role enforcement, and commercial Cognito
connectors — if any grant admin via the `user_has_cap` runtime filter rather than a
meta write, the guard would miss them (§3 gap).

**Design takeaways:**

1. The allowlist is **not hypothetical** — at least seven verified, legitimately
   installed plugins map an external identity (or CSV row) to `administrator`. The
   guard's filter/allowlist must let an operator exempt a named provisioner.
2. **Interactive vs. unattended matters.** A bulk CSV import an admin runs in
   `wp-admin` happens *inside* an active sudo window, so the guard naturally lets
   it through; the breakage is the **unattended** path (SSO login, cron sync) with
   no session. Framing the escape hatch around "trusted unattended context" (a
   constant/allowlist) rather than blanket-OFF is the better long-term shape.
3. Every verified mechanism writes `{prefix}capabilities` or calls
   `grant_super_admin` — so the §3 effect-level guard *does* cover them, **and**
   the per-blog capability-key coverage (NADI/Authorizer/CSV via
   `add_user_to_blog`) is load-bearing, not optional.

> **Verification caveat:** this session's egress policy blocks all
> `*.wordpress.org` hosts (SVN/API/downloads → 403), so the two miniOrange rows
> rest on **older `wp-plugins` GitHub mirror snapshots** (SAML v3.0, OAuth v4.8),
> not current trunk. Re-verify current free versions from an unrestricted
> environment before publishing version-specific claims. All other rows were
> verified against current GitHub upstreams.

### REST user-creation frequency — yes, heavily, but all low-privilege

Plugins *do* create users over REST at high volume — which is precisely why a
**blanket** REST user-creation guard would be unacceptable, yet does **not**
threaten an escalation-only guard:

- **WooCommerce Store API checkout** (`POST /wc/store/v1/checkout`) creates a
  customer account during checkout when `create_account` is set or the store
  requires registration, by calling `wc_create_new_customer()` (role
  `customer`). Its `permission_callback` is `__return_true` and it runs in a
  **guest** context authenticated by a cart token / Store API nonce (or an
  authenticated shopper). This is **storefront-volume** — potentially every
  guest signup / checkout-with-account. *(Verified: StoreApi `Checkout` route
  `process_customer()` / `should_create_customer_account()`.)*
- **WooCommerce REST `POST /wc/v3/customers`** — integrations, ERPs, and headless
  frontends create customers (often in batches); authenticated with
  customer-management caps, `role` read-only → `customer`.
- **Core `POST /wp/v2/users`** — admin tooling and headless WordPress; requires
  `create_users`.

All three create **low-privilege** users, and the two high-volume WooCommerce
paths force the customer role / mark `role` read-only — **none can mint an
administrator**. Therefore:

1. An **escalation-only** guard (fires only on a *newly granted
   administrator/super-admin*) **never triggers** on these flows, regardless of
   volume — frequency is a non-issue for the recommended design.
2. A **blanket** REST `user.create` guard **would** break storefront signups and
   checkout-with-account at scale, and would run in a guest context where "sudo"
   is meaningless — a decisive argument against ever guarding REST *creation*
   broadly, and against the blanket option generally.
3. If the REST *surface* ever gains an escalation guard (separate from this
   admin-surface item), it must be escalation-to-admin **only** and
   App-Password/policy-aware (Unrestricted passes), and must explicitly not touch
   Store API guest account creation.

Net: REST user-creation volume argues *for* narrowing to escalation-only — not
against the feature.

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
would, *if pursued surface-by-surface*, require guards on **three** surfaces
(admin, REST, non-interactive), of which only the non-interactive one exists
today. The admin-`init`-only "Item 2" closes only the **interactive admin** third
— which, per §1, is the **least valuable** third, since the broken-access-control
exploits land on REST/AJAX/unauthenticated endpoints, not `admin_init`.

**The surface-thirds problem dissolves when you hook the *effect* instead of the
surface.** Role assignment and super-admin grants converge on two effect-level
hooks — the `{prefix}capabilities` user-meta write (`add_user_metadata` /
`update_user_metadata`) and `grant_super_admin` — that fire **regardless of
surface** (admin, REST, AJAX, cron, XML-RPC, *and* unauthenticated front-end
requests). A single guard hung on those hooks ("block a newly-granted
administrator/super-admin when the acting user has no active sudo session")
therefore covers **all** surfaces with one mechanism, including precisely the
unauth/low-priv paths where these exploits live. This is the same hook the CLI
`user.promote` guard already uses (§3), so it is architecturally consistent — not
new machinery. **The recommended design is this effect-level guard, not a set of
per-surface guards.** One consequence: on non-interactive surfaces there is no
human to send to the challenge, so the only available response is a **hard block**
(short-circuit the write), not a reauth prompt (§6, §8).

## 6. Implementation shape (if approved)

- **Hooks (effect-level, surface-agnostic):** reuse the `add_user_metadata` /
  `update_user_metadata` filter on the `{prefix}capabilities` key; additionally
  hook `grant_super_admin` for the multisite path. These fire on **every** surface
  (admin, REST, AJAX, cron, XML-RPC, unauthenticated), so one guard here covers
  all of them — this is the recommended scope (§5), not an admin-`init`-only
  guard.
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
- **Default-OFF filter:** `wp_sudo_guard_escalation` (name TBD), **default
  `false`** (OFF) per the recorded decision — security-conscious sites opt in;
  SSO/provisioning sites are unaffected by default. See §8 for the path to a
  future default-ON.
- **Escape hatches (prerequisites for ever flipping default-ON):**
  - an **allowlist filter** so known-good provisioners (SSO/SAML/OIDC, directory
    sync) can mark a specific grant as legitimate;
  - a **constant / WP-CLI bypass** (e.g. a defined constant, plus the existing CLI
    policy) for deployment, migration, and first-admin bootstrapping;
  - **audit events on every block** (`wp_sudo_action_blocked`) so a silent
    short-circuit is diagnosable rather than a mystery.
- **Exclude `user.create`** from the guard (orphan problem, §3); admin *creation*
  is still caught as the subsequent administrator capabilities write.

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
8. **Core value case** — escalation to `administrator` via an *unauthenticated*
   request (`current_user` = 0) and via a *low-privilege* authenticated user
   (subscriber), filter ON → **blocked** in both, since neither can hold a sudo
   session. This exercises the effect-level/all-surfaces property from §1/§5.

## 8. Recommendation

**Build it — escalation-only, effect-level, creation-excluded — and treat it as a
real mitigation, not optional polish.** An earlier draft of this section called
the security gain "modest" on the theory that the attacker is "already
admin-context." **That was wrong** and is corrected here: in the common exploit
shapes the attacker is *unauthenticated or low-privilege* and (per §1)
**structurally cannot hold a sudo session**, so the guard meaningfully blocks the
#1 broken-access-control category — and does so even when the vulnerable plugin's
own access control is broken. Scoping it at the **effect level** (§5) makes it
cover the REST/AJAX/unauth surfaces where these exploits actually land, with one
mechanism.

**Default posture — OFF first, earn ON:**

- **Ship default-OFF** behind the `wp_sudo_guard_escalation` filter, with the §6
  escape hatches (allowlist, constant/CLI bypass, audit-on-block) built *before*
  release. The breakage case (silent denial of legitimate SSO/sync/migration
  admin provisioning, §4) is confusing precisely because the operator will not
  connect a failed SAML admin grant to this plugin — silent breakage of admin
  provisioning would burn the trust the plugin exists to build.
- **Plan to flip default-ON in a later major**, once the false-positive surface is
  mapped and the escape hatches are proven in the field. For a security plugin,
  "granting administrator requires an active reauth" is squarely on-thesis and is
  a defensible — arguably correct — default; it simply has to *earn* that default
  rather than ship with it.

A formal Pre-Implementation Design Review (per `CLAUDE.md`) and the §7 TDD
scenarios remain prerequisites to writing code; the design review should
specifically weigh the short-circuit-vs-`wp_die` block mechanism (§6) and the
residual mid-session bypass (§1).

## Verification sources

- WooCommerce `wc_create_new_customer()` role: `woocommerce/trunk` →
  `plugins/woocommerce/includes/wc-user-functions.php` (GitHub raw).
- WooCommerce REST customer `role` readonly: `class-wc-rest-customers-controller.php`
  schema (GitHub raw).
- WordPress super-admin storage/hooks: `wp-includes/capabilities.php`
  (`grant_super_admin` / `revoke_super_admin`, GitHub raw).
- In-tree mechanics: `includes/class-gate.php`
  (`register_function_hooks`, `is_user_capabilities_meta_key`,
  `register_interactive_backstop`, `register_rest_backstop`).
- LifterLMS registration role (`student`): gocodebox/lifterlms trunk
  `includes/class.llms.person.handler.php` (GitHub).
- Paid Memberships Pro checkout role
  (`get_option( 'default_role', 'subscriber' )`):
  strangerstudios/paid-memberships-pro `preheaders/checkout.php` (GitHub raw).
- Threat figures (indicative, see §1): Wordfence 2024 Annual WordPress Security
  Report (privilege escalation = 2nd most common type); Patchstack State of
  WordPress Security 2025 + 2025 mid-year breakdown (majority of disclosures
  unauthenticated). Full report bodies were not machine-fetchable at write time.
- WP-core hook chain (§3 matrix): `wp-includes/class-wp-user.php`
  (`set_role`/`add_role`/`cap_key`), `wp-includes/ms-functions.php`
  (`add_user_to_blog` → `set_role`), `wp-includes/capabilities.php`
  (`grant_super_admin`/`granted_super_admin`) — WordPress/WordPress master (GitHub raw).
- SSO/provisioning admin-grant mechanisms (§4 table): NADI
  `src/.../Role/Manager.php` (NeosIT); WP SAML Auth `inc/class-wp-saml-auth.php`
  (pantheon-systems); Authorizer `src/authorizer/class-authorization.php` (uhm-coe);
  OpenID Connect Generic `includes/openid-connect-generic-client-wrapper.php`
  (oidc-wp); Login with Google `src/Modules/Login.php` (rtCamp); Google Apps Login
  `core/core_google_apps_login.php`; Trifoia Cognito Login
  `includes/units/user.php`; codection Import-users-from-CSV `classes/import.php`;
  the `import-users` addon (user engine split from WP Ultimate CSV Importer)
  `importExtensions/UsersImport.php`; wp-openid (nicko170) `OpenID.php`; WP OAuth
  Server (`oauth2-provider`) verified to contain no role-write code. miniOrange
  rows from `wp-plugins` GitHub mirrors (SAML v3.0, OAuth v4.8) — current trunk
  unverified, see §4 caveat. WP All Import user add-on remains unverified
  (premium/closed). Non-`wordpress.org` sources used because SVN/API are egress-blocked
  (see §4 caveat): current-release mirror `github.com/common-repository/<slug>`.
