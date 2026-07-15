# Research: MU-plugin role/capability lockdown mode

## Status

Tentative research backlog. Do not implement until the research questions below
are answered and the operator/recovery story is clear.

## Hypothesis

The optional WP Sudo MU-plugin could provide a high-assurance lockdown mode that
loads an operator-reviewed file manifest of trusted privileged principals and
uses it to detect, deny, or repair database-stored role/capability drift.

This would be valuable mainly against **database-only compromise** or accidental
role-editor drift: an attacker or plugin can mutate `wp_user_roles`, a user's
`{prefix}capabilities` meta row, or multisite `site_admins`, but cannot write PHP
or alter the manifest file.

## Why this might fit WP Sudo

- WP Sudo already treats direct role/capability tampering as a security concern:
  it strips `unfiltered_html` from the Editor role and fires
  `wp_sudo_capability_tampered` when that canary drifts.
- The admin-escalation guard catches many *hooked* administrator/super-admin
  grants, but direct `$wpdb` writes bypass the user-meta hooks.
- The MU-plugin loads earlier than normal plugins and is the right deployment
  surface for policy that should be file-backed rather than database-backed.

## Candidate scope

Start narrow. Protect privileged authority, not every role on the site.

Potential manifest entries:

- allowed administrator users per site;
- allowed multisite super admins;
- allowed WP Sudo governance capability holders:
  - `manage_wp_sudo`
  - `view_wp_sudo_activity`
  - `export_wp_sudo_activity`
  - `revoke_wp_sudo_sessions`
- optionally, holders of dangerous primitive caps such as:
  - `manage_options`
  - `manage_network_options`
  - `promote_users`
  - `activate_plugins`
  - `install_plugins`
  - `delete_plugins`

Potential modes:

1. **Audit only** — detect drift, fire hooks/events, and show admin/site-health
   warnings.
2. **Deny effective capability** — filter effective caps for principals not in
   the manifest without rewriting the database.
3. **Repair stored state** — remove unauthorized DB role/cap/super-admin grants.
   This is strongest but operationally sharpest.

Likely MVP: audit-only plus an explicit operator command/workflow to generate a
reviewable manifest. Enforce mode should come later, if at all.

## Required research questions

- What exact WordPress load point gives reliable access to roles, current user,
  multisite context, and capability filters without racing core initialization?
- Should enforcement filter effective capabilities, rewrite stored state, or both?
- How can the manifest represent multisite safely:
  - network super admins;
  - main-site administrators;
  - per-site administrators on secondary blogs?
- How should legitimate dynamic provisioning work:
  - SSO/SAML/OIDC directory sync;
  - Members/User Role Editor-style plugins;
  - deployment scripts that create admins;
  - emergency access changes?
- What is the break-glass mechanism if the manifest is stale or wrong?
- How should the feature interact with `WP_SUDO_RECOVERY_MODE` and
  `WP_SUDO_ALLOW_ESCALATION`?
- Which audit hooks/events should fire for detected drift vs. enforced repair?
- Can this be tested deterministically in unit/integration tests without creating
  brittle assumptions about core role loading?

## Security boundaries

This does **not** protect against:

- filesystem/PHP write access;
- malicious code that can edit the manifest;
- runtime capability grants through `user_has_cap` / `map_meta_cap` unless the
  chosen enforcement model explicitly filters final effective caps;
- legitimate admins operating inside an already-active sudo window;
- direct database writes to non-role application state.

It should be documented as a high-assurance integrity control for trusted
operators, not a generic firewall.

## Acceptance criteria before implementation planning

- A short design note compares audit-only, deny-effective, and repair-stored-state
  approaches.
- A concrete manifest format is proposed and reviewed.
- A recovery/break-glass path is defined before any enforce mode is implemented.
- Compatibility risks with role-management and identity-provider plugins are
  listed with opt-out or allowlist mechanics.
- The first implementation slice has tests for single-site, multisite, direct
  DB mutation, manifest drift, and recovery bypass behavior.

## Reviewer notes (added during PR review, 2026-07-15)

Sharpening the research before any design phase — these are the load-bearing
tensions to resolve:

- **Draw the boundary against the existing escalation guard.** `Gate::arm_escalation_guard`
  (default-OFF, filter `wp_sudo_guard_escalation`) already blocks a hooked
  administrator/super-admin grant by hooking the `{prefix}capabilities` meta write
  and `grant_super_admin`. This lockdown mode's *unique* value is precisely the
  path that guard misses: a direct `$wpdb` write to `wp_usermeta` / `wp_user_roles`
  / multisite `site_admins` that never fires those hooks. The design must state how
  the two compose — same manifest/allowlist? shared audit event? — so they don't
  double-fire, disagree, or leave a gap between them.
- **The "dangerous primitive caps" list mostly restates "all administrators."**
  `manage_options`, `activate_plugins`, `install_plugins`, `promote_users`, etc.
  are held by every administrator, so allowlisting their holders is nearly the same
  set as the administrator allowlist. The primitive-cap layer only earns its keep
  for **non-admin roles that have been granted these caps directly** (custom roles,
  role-editor drift). Scope it to that case explicitly, or drop it as redundant.
- **Deterministic testing is already tractable.** The integration harness
  (`WP_UnitTestCase`, real WP + MySQL) can mutate `wp_user_roles` /
  `{prefix}capabilities` / `site_admins` directly and assert detection, drift
  repair, and break-glass — single-site and `WP_TESTS_MULTISITE=1`. This is how the
  `wp_sudo_capability_tampered` canary is already exercised; the open testability
  question is answerable with that pattern, not a blocker.
- **Enforcement cost must be named.** A "deny effective capability" model that
  filters `user_has_cap` / `map_meta_cap` runs on **every** capability check on
  **every** request — a real hot path. Weigh that against "repair stored state"
  (a one-shot DB rewrite that is operationally sharper and can lock out a
  legitimately-provisioned admin). This reinforces the audit-only MVP: ship
  detection + events first, and treat any enforce mode as a separate, later,
  heavily-gated decision.
- **Manifest lifecycle is an operational burden, not just a format.** Beyond the
  file schema, define how the manifest is generated, reviewed, versioned, and kept
  in sync across environments (dev/stage/prod), and how a snapshot is regenerated
  safely after a legitimate change — otherwise the feature trades a compromise risk
  for a self-inflicted lockout risk.
