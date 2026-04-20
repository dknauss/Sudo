# Internal Admin Governance Spec

*Status: proposed (post-v3.0.0), April 20, 2026*

## Why this exists

WP Sudo's threat model includes compromised administrator sessions. Today, any
user with `manage_options` (or `manage_network_options` on multisite) can:

- access Sudo settings,
- change surface policy posture,
- change logging-related visibility controls,
- view Sudo dashboard activity.

That is operationally convenient, but too broad for environments that want
least-privilege administrative control and clearer insider-risk boundaries.

### Framing: additive hardening, not a fix for an open vulnerability

Changing Sudo settings is already a gated action (`options.wp_sudo` in
`Action_Registry`), so a compromised session without active sudo cannot
silently widen policy today — it hits the challenge. This governance work is
**additive**: it distinguishes "who *should* have authority here" from "who
*happens* to have `manage_options`." Multi-admin environments gain explicit
delegation boundaries and cleaner insider-risk posture. Single-admin sites
see no practical change beyond a clearer capability surface.

### No existing install base — design for clean launch, not migration

WP Sudo has not yet shipped to the public WordPress.org directory. There is
no install base to protect from breaking changes. This shifts the rollout
strategy: rather than carrying long-lived compatibility mode to soften a
capability-model transition for existing operators, the plugin can **ship
with strict-capability mode as the default from day one**. Compatibility
mode remains as an explicit opt-in for single-admin smoke-testing, CI
fixtures, and operators who actively want the simpler `manage_options`
fallback — but it is not the default, and it does not require a migration
wizard.

## Goals

1. Separate **Sudo management** from general WordPress site administration.
2. Separate **Sudo activity visibility** from policy-editing privileges.
3. Keep upgrades safe and reversible.
4. Preserve multisite correctness (network vs site scope).
5. Avoid lockout of legitimate operators.

## Non-goals

- Replacing WordPress capability architecture.
- Building a full SIEM/audit product inside WP Sudo.
- Solving direct database/file access bypasses (out of scope for plugin-level controls).

## Proposed capability model

Introduce dedicated capabilities:

- `manage_wp_sudo` — access Sudo settings, policy presets, rule tester, and management actions.
- `view_wp_sudo_activity` — view widget/activity screens without policy-edit authority.
- `export_wp_sudo_activity` — export activity data (CSV/JSON). **Separate from view**: a portable artifact is a distinct governance concern (SOC2/ISO27001/GDPR audit trails typically separate "read" from "export").
- `revoke_wp_sudo_sessions` — revoke active sessions (site scope by default; network scope for super admins).

### Default behavior

- **Single-site new installs:** the installing admin receives all four caps. Other admins receive none until explicitly granted.
- **Multisite:** super admins receive all four caps at network scope by default. Per-site admins receive none until explicitly delegated.
- **Compatibility mode (opt-in only):** when `wp_sudo_governance_mode` option is `compatibility`, the plugin falls back to `manage_options` / `manage_network_options` / `is_super_admin()` checks. This is not the default — operators must set it explicitly. Intended for single-admin sites, CI environments, and smoke-testing scenarios that don't want capability delegation overhead.

### Helper contract

All access checks MUST route through a single centralized helper:

```php
function sudo_can( string $cap, ?int $user_id = null ): bool {
    $user_id ??= get_current_user_id();
    if ( is_multisite() && is_super_admin( $user_id ) ) {
        return true;
    }
    if ( 'compatibility' === get_option( 'wp_sudo_governance_mode', 'strict' ) ) {
        return user_can( $user_id, is_multisite() ? 'manage_network_options' : 'manage_options' );
    }
    return user_can( $user_id, $cap );
}
```

The super-admin short-circuit is required because super admins do not literally hold `manage_network_options` in their cap set on subsite contexts — `current_user_can()` works via `map_meta_cap`'s super-admin bypass, but `user_can( $user_id, $cap )` does not. Every call site in `Admin`, `Dashboard_Widget`, `Site_Health`, and any future activity screen MUST use `sudo_can()`. The Phase 1 exit criterion is: zero direct `current_user_can('manage_options')` or `current_user_can('manage_network_options')` calls remain in Sudo code for governance-sensitive surfaces.

## Privilege-sensitive operations (gated by Sudo itself)

The new capability model introduces three operations that are themselves privilege-sensitive and MUST be gated by `Action_Registry`, not only by nonce + cap checks:

- **Granting a Sudo capability** — a compromised cap-holder could otherwise create a persistence channel by granting `manage_wp_sudo` to a second attacker-controlled user before the original session is nuked.
- **Revoking a Sudo capability** — same attack shape in reverse (remove access from incident responders during a live response).
- **Forcing session revocation** — destructive action against other users; trivially weaponized as an incident-DoS.

Add a single gated rule covering all three:

```php
array(
    'id'       => 'options.wp_sudo_access',
    'label'    => __( 'Change Sudo access controls', 'wp-sudo' ),
    'category' => 'options',
    'admin'    => array(
        'pagenow' => 'options.php',
        'actions' => array( 'update' ),
        'method'  => 'POST',
        'callback' => function (): bool {
            return 'wp-sudo-access' === sanitize_text_field( $_POST['option_page'] ?? '' );
        },
    ),
    'ajax' => array( 'actions' => array( 'wp_sudo_revoke_session' ) ),
    'rest' => null,
),
```

Rate-limit revocations per user (reuse `Sudo_Session` throttle infrastructure: e.g., ≤ 10 per hour) to blunt the incident-DoS abuse path.

### Audit hooks for access-model transitions

Fire through `Event_Recorder` so WSAL / Stream bridges pick them up without new surface:

- `wp_sudo_capability_granted` — fired after a grant completes. Args: `$target_user_id`, `$cap`, `$granter_user_id`, `$site_id`.
- `wp_sudo_capability_revoked` — fired after a revoke completes. Same args.
- `wp_sudo_session_revoked` — fired when an operator force-expires another user's session. Args: `$target_user_id`, `$revoker_user_id`, `$reason`, `$site_id`.

Revoker attribution is mandatory: without it, post-incident forensics cannot identify who nuked whose session.

## Target UX changes

1. **Access tab** on Settings → Sudo
   - List users (user-only grants in v1 — see Decisions below) that currently hold Sudo capabilities, with the cap(s) each holds.
   - Grant/revoke controls with nonce + capability checks, themselves gated by `options.wp_sudo_access`.
   - Audit hook when assignments change (`wp_sudo_capability_granted` / `wp_sudo_capability_revoked`).
   - **Drift detection panel** — in addition to showing current-state grants, surface:
     - Users who hold `manage_options` (or `manage_network_options`) but do not hold `manage_wp_sudo` under the current governance mode. These are users whose admin-UI access to Sudo differs from their policy-edit authority — useful for catching unexpected gaps after third-party role plugins have modified cap assignments.
     - Users granted caps through unexpected paths (e.g., editor role upgraded to `manage_options` by another plugin).

2. **Dashboard visibility split**
   - Widget checks `view_wp_sudo_activity`, not `manage_options`.
   - Settings screen checks `manage_wp_sudo`.
   - Export actions (CSV/JSON) check `export_wp_sudo_activity`.

3. **Operator safety**
   - "Last manager" guard: block removing `manage_wp_sudo` from the final holder on a site (network-scope: from the last super admin holding the network grant).
   - Break-glass recovery — see dedicated section below.

## Multisite rules

- Network settings are managed by users with `manage_wp_sudo` at network scope (super admin by default).
- Site-level activity views can be delegated with `view_wp_sudo_activity`, bounded by that site's `site_id`.
- **Cross-site visibility is not delegable.** Network-scope activity views require a separate network-scope grant and are reserved for super admins in v1. This resolves the open question from the original draft.
- **Query-invariant for site-scope visibility**: every `Event_Store::recent()` / `Event_Store::count_since()` call initiated from a site-scope activity surface MUST pass the current `site_id`. Integration tests MUST assert no unfiltered cross-site reads slip through. The `wpsudo_events` table is network-shared; a missing `site_id` filter would silently expose cross-site activity to a site-scope delegate.
- No silent inheritance that gives all site admins Sudo-management rights.

## Break-glass recovery

A dedicated recovery path is required for the "last manager" lockout case (e.g., the sole holder of `manage_wp_sudo` leaves the organization, or a mis-revoke removes access from every operator). Without a tightly scoped escape hatch, operators will default to turning governance off entirely.

### Design

- Defined **only** via `define( 'WP_SUDO_RECOVERY_MODE', true );` in `wp-config.php`. Not a DB option, not a filter default — the constant must be set by someone with filesystem access, which matches the trust level required for this escape hatch.
- When active, every Sudo admin screen displays a permanent (non-dismissible) admin notice stating that recovery mode is in effect.
- Every request that relies on recovery mode writes an event to `wpsudo_events` with a dedicated event type (`governance.recovery_mode`) so the usage pattern is auditable.
- Recovery mode grants the current user effective `manage_wp_sudo` regardless of their actual cap set — scoped to the lifetime of the constant being defined. Removing the `define` restores normal behavior.
- Does NOT bypass the reauth challenge itself. A user in recovery mode still hits the sudo challenge on gated actions; they simply regain access to the Sudo settings and access-control screens.

### Anti-drift

Set-once-forget is the primary failure mode. Two mitigations:

1. On every Sudo admin page load while recovery mode is active, emit the admin notice and log a `governance.recovery_mode` event (sampled, not once-per-request, to avoid log flooding).
2. Document clearly in the FAQ and the notice itself: recovery mode is for emergency access restoration. Leaving it enabled indefinitely effectively bypasses governance.

## Implementation phases

Because there is no existing install base, the original three-phase compatibility-first rollout is replaced with a compressed implementation plan. Strict-capability mode ships as the default in v3.1; compatibility mode is present as an opt-in from day one but is not the migration endpoint of a multi-version transition.

### Phase 1 (v3.1): Ship strict governance as the default

Build the whole capability surface in one coordinated release:

**Core**
- Capability constants: `manage_wp_sudo`, `view_wp_sudo_activity`, `export_wp_sudo_activity`, `revoke_wp_sudo_sessions`.
- Centralized `sudo_can()` helper per the Helper contract above.
- Governance-mode option `wp_sudo_governance_mode` = `strict` (default) | `compatibility`. Persisted per-site on multisite.
- On activation: grant all four caps to the installing user (single-site) or to the activating super admin (multisite).

**Access surface**
- Access tab on Settings → Sudo: list grantees, grant/revoke controls, drift detection panel.
- All grant/revoke form submissions covered by the new `options.wp_sudo_access` gated rule.
- "Last manager" guard enforced in the grant/revoke action handler, not only in the UI.

**Enforcement**
- Replace every `current_user_can('manage_options')` / `current_user_can('manage_network_options')` check in `Admin`, `Dashboard_Widget`, `Site_Health`, and any Sudo admin surface with `sudo_can($cap)`.
- Phase 1 exit criterion: zero direct `manage_options` / `manage_network_options` checks remain in governance-sensitive surfaces.

**Audit + recovery**
- `wp_sudo_capability_granted`, `wp_sudo_capability_revoked`, `wp_sudo_session_revoked` audit hooks through `Event_Recorder`.
- `WP_SUDO_RECOVERY_MODE` break-glass constant per the Break-glass section.
- Per-user rate limit on session revocations.

**Documentation**
- FAQ entry on break-glass recovery.
- Developer-reference entry on the three new audit hooks.
- `docs/security-model.md` §19 update reflecting the shipped capability boundary (not just a planned one).

### Phase 2 (v3.2, optional): Governance polish

Additive improvements that benefit from a release of production field data:

- Integrity warnings when effective visibility is broader than configured intent (drift-detection promotion from read-only panel to active notices).
- Optional 2FA-enrollment requirement for `manage_wp_sudo` holders (see Decisions).
- Audit visibility for governance-mode transitions (`strict` ↔ `compatibility` switches).

Phase 2 is scope-bounded; none of it is required to close the governance story. The work exists because real-world delegation patterns will surface edge cases that read-only drift detection misses.

### Explicitly not phased

- **No guided migration wizard.** There is nothing to migrate — the plugin ships strict by default, and `compatibility` is an opt-in mode for operators who actively want it.
- **No "strict default after migration" milestone.** Strict is the default from v3.1.
- **No role-level grant delegation.** See Decisions.

## Test plan

Per the project's TDD policy, every capability gate lands with tests first.

### Unit tests

- `sudo_can()` helper: strict-mode path, compatibility-mode path, super-admin short-circuit on multisite subsites, recovery-mode short-circuit.
- Each capability gate path: `manage_wp_sudo`, `view_wp_sudo_activity`, `export_wp_sudo_activity`, `revoke_wp_sudo_sessions` × single-site + multisite contexts.
- "Last manager" guard: grant/revoke transitions that would leave zero holders are rejected with a specific error.
- Access-model audit hooks fire with the expected argument shapes.
- Session-revocation rate limit: 10 revocations per hour is allowed; the 11th is blocked.

### Integration tests

- Single-site install defaults: installing admin receives all four caps; a second admin-role user receives none until explicitly granted.
- Multisite install defaults: activating super admin receives all four caps at network scope; per-site admins receive none.
- Query-invariant for site-scope visibility: a site admin granted `view_wp_sudo_activity` on site 2 cannot observe events from sites 1 or 3 via any dashboard / activity-screen query.
- Break-glass recovery: with `WP_SUDO_RECOVERY_MODE` defined, a user without governance caps regains Sudo-screen access; every such request writes a `governance.recovery_mode` event.
- Governance-mode toggle: switching `wp_sudo_governance_mode` between `strict` and `compatibility` is itself covered by the `options.wp_sudo_access` gated rule.
- `options.wp_sudo_access` rule correctly matches the Access tab form submission and AJAX revocation handlers.

### Manual / E2E

- Operator walkthrough: grant a second user `view_wp_sudo_activity`, confirm widget appears for that user, confirm settings screen does not.
- Recovery-mode activation procedure: set constant in `wp-config.php`, visit Sudo settings, confirm notice appears and access works.
- "Last manager" recovery: attempt to revoke from the sole holder; confirm block + clear error message pointing at recovery mode.

## Safety requirements

- Never remove access without explicit operator confirmation (Access-tab revoke is the only removal path; automated cleanup never revokes caps).
- Log all access-model transitions and assignment changes via audit hooks.
- Compatibility mode remains available as a per-site opt-in but is never silently selected.

## Decisions

Questions left open in the original draft, now resolved:

1. **Role-level vs user-only grants (v1 decision): user-only.**
   Role-level grants would mutate `wp_user_roles` (persists after uninstall, degrades audit trail, and overlaps with existing role-management plugins like Members or User Role Editor). If role-level delegation is ever added, do it via a filter (`wp_sudo_role_grants`) rather than stored mutation so uninstall cleanup stays complete.

2. **Export capability: separate cap (`export_wp_sudo_activity`).**
   Export produces a portable artifact (CSV/JSON) that maps directly to data-exfiltration playbooks and to SOC2/ISO27001/GDPR audit categorization. "Can view" and "can download" are meaningfully different governance states. Default the two caps to the same grantees; splitting them now costs nothing and avoids a breaking migration later.

3. **Cross-site activity visibility on multisite: super-admin only, not delegable in v1.**
   See the Multisite rules section. The query-invariant (`site_id` filter on every Event_Store call from site-scope surfaces) makes this enforceable; delegating cross-site visibility would require a separate explicit grant and is not in scope for v1.

### Open

1. **2FA enrollment requirement for `manage_wp_sudo` holders.**
   Should the plugin require that users holding `manage_wp_sudo` have a 2FA method enrolled (via Two Factor or equivalent)? Enterprise environments typically expect this, but it creates a hard dependency on a 2FA plugin being active. Lean toward: **opt-in site-level option in v3.2** — defaulted off, documented as a recommended hardening step, with a clear error message when a grant would violate the requirement.

## Roadmap linkage

This document is the implementation spec for roadmap section **11.2 Internal Admin Least-Privilege and Governance** in [`docs/ROADMAP.md`](ROADMAP.md).
