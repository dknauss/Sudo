# Internal Admin Governance Spec

*Status: proposed (post-v3.0.0), April 20, 2026*

## Why this exists

WP Sudo's threat model includes compromised administrator sessions. Today, however,
any user with `manage_options` (or `manage_network_options` on multisite) can:

- access Sudo settings,
- change surface policy posture,
- change logging-related visibility controls,
- view Sudo dashboard activity.

That is operationally convenient, but too broad for environments that want
least-privilege administrative control and clearer insider-risk boundaries.

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
- `revoke_wp_sudo_sessions` — revoke active sessions (site scope by default; network scope for super admins).

Default behavior target:

- **Single-site new installs:** installing admin gets all three caps; other admins get none until explicitly granted.
- **Multisite:** super admins retain management by default; per-site admins are denied unless explicitly delegated.

## Target UX changes

1. **Access tab** on Settings → Sudo
   - List users/roles that currently hold Sudo capabilities.
   - Grant/revoke controls with nonce + capability checks.
   - Audit hook when assignments change.

2. **Dashboard visibility split**
   - Widget checks `view_wp_sudo_activity`, not `manage_options`.
   - Settings screen checks `manage_wp_sudo`.

3. **Operator safety**
   - "Last manager" guard: block removing `manage_wp_sudo` from the final holder.
   - Recovery constant/filter escape hatch for break-glass.

## Multisite rules (target)

- Network settings are managed by users with `manage_wp_sudo` at network scope (super admin by default).
- Site-level activity views can be delegated with `view_wp_sudo_activity`.
- Network activity views remain super-admin only unless explicitly expanded later.
- No silent inheritance that gives all site admins Sudo-management rights.

## Phased rollout

### Phase 1 (v3.1): Capability scaffolding + compatibility mode

- Add capability constants and centralized access checks.
- Keep current behavior as fallback for upgrades (`manage_options` / `manage_network_options`) to avoid surprise lockouts.
- Add read-only "Access Overview" panel showing who would have access under new model.
- Add audit hooks for capability assignment changes.

Deliverables:
- capability helpers,
- compatibility fallback,
- unit/integration tests for all access gates.

### Phase 2 (v3.2): Explicit grants for new installs + guided upgrade path

- New installs default to installer/super-admin-owned `manage_wp_sudo`.
- Upgrades stay in compatibility mode until an explicit migration action is completed.
- Add guided migration flow:
  - choose Sudo managers,
  - choose activity viewers,
  - preview resulting access matrix.

Deliverables:
- migration wizard,
- rollback toggle to compatibility mode,
- docs + operator checklist.

### Phase 3 (v3.3): Hardened default posture

- Newly upgraded sites that completed migration run in strict-cap mode by default.
- Compatibility mode remains as an explicit fallback (documented, auditable).
- Add integrity warnings when access posture is broader than configured intent.

Deliverables:
- strict mode default after migration,
- admin notices for drift/misconfiguration,
- finalized policy docs.

## Test plan

- Unit tests for each capability gate path (`settings`, `widget`, `revoke`, network contexts).
- Integration tests covering:
  - single-site install defaults,
  - multisite super-admin behavior,
  - migration wizard transitions,
  - "last manager" protection.
- Manual checks for lockout recovery path and downgrade/rollback safety.

## Migration / rollback requirements

- Never remove existing access without explicit operator confirmation.
- Preserve a one-click compatibility fallback during rollout.
- Log all access-model transitions and assignment changes via audit hooks.

## Open decisions

1. Should role-level grants be supported in v1, or user-only grants first?
2. Should `view_wp_sudo_activity` include export capability, or should export require a separate cap?
3. Should network-mode activity visibility ever be delegated beyond super admins?

## Roadmap linkage

This document is the implementation spec for roadmap section **11.2 Internal Admin Least-Privilege and Governance** in [`docs/ROADMAP.md`](ROADMAP.md).
