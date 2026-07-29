# Design brief: role/capability lockdown — audit mode (#179)

**Status:** Pre-implementation design note (per CLAUDE.md → "Pre-Implementation Design
Review"). Security-sensitive (capability/role/auth path). **Revised 2026-07-17 after design
review** — see "Reviewer objections incorporated" at the end. Ready for a phased TDD plan.
**Source todo:** `.planning/todos/pending/2026-07-15-mu-role-cap-lockdown-mode.md`.
**Date:** 2026-07-17

> **Naming note:** the todo is titled "MU-plugin role/cap lockdown." The design review
> established that the **audit-only MVP needs no MU-plugin code at all** (objection #3) —
> every trigger runs in the normal plugin lifecycle. The MU early-load surface is reserved
> for a *future enforce* mode. This brief therefore scopes the MVP as **role/capability
> drift auditing**; "MU-plugin" describes the eventual enforcement deployment surface, not
> the first slice.

---

## Threat model

An attacker or buggy plugin with **database-only** write access (no PHP/filesystem write)
can mutate stored privilege state directly, bypassing WordPress's metadata/API layer:

1. **Principal drift** — grant a user `administrator` via `{prefix}capabilities` meta, or
   add a user to the multisite `site_admins` network option.
2. **Role-definition drift** — redefine what a role *grants* by editing the
   `{prefix}user_roles` option (e.g. add `manage_options`/`promote_users` to `editor`),
   creating an admin-equivalent principal who holds **neither** the `administrator` role
   **nor** any governance cap.
3. **Governance drift** — grant a user a WP Sudo governance cap (`manage_wp_sudo`, …)
   directly, to read or suppress the audit trail — an attack on the security tool itself.

`Gate::arm_escalation_guard()` (`class-gate.php:927`) already blocks **hooked**
administrator/super-admin grants: it filters `add_user_metadata`/`update_user_metadata` on
the capabilities key (`class-gate.php:1023-1024`) and hooks `grant_super_admin`
(`:1085`) / `delete_user` (`:1138`). **Verified:** those hooks fire only through WP's
metadata/API layer — a raw `$wpdb->update()`/`$wpdb->query()` fires **none** of them. That
direct-DB-write path is the structural gap this feature closes.

The trust root is a **file-backed manifest** of trusted privileged principals *and*
privileged role definitions that a database-only attacker cannot alter.

## Approach comparison (todo acceptance criterion #1)

| Model | What it does | Cost / risk | Verdict |
|---|---|---|---|
| **Audit-only** | Detect drift; fire an audit event + Site Health warning + admin notice. No cap filtering, no DB write. | Sweep cost only (bounded, on-demand/cron). **Cannot lock anyone out.** | **MVP — ship this.** |
| Deny-effective | Filter `user_has_cap`/`map_meta_cap` to strip caps for principals absent from the manifest. | Runs on **every capability check on every request** — a hot path. A stale manifest silently breaks a legit admin. | Deferred; separate design. |
| Repair-stored | Rewrite `{prefix}capabilities`/`{prefix}user_roles`/`site_admins` to remove unauthorized grants. | One-shot but **operationally sharpest** — a stale manifest hard-removes a legit admin; races IdP sync. | Deferred; separate design. |

**Recommendation: audit-only MVP.** It delivers detection against the DB-only threat now,
and a stale/wrong manifest in audit-only mode can **only warn, never lock out** — the
property that makes it safe to ship before the enforcement recovery story is solved.
Enforce modes are later, independent, separately-reviewed decisions.

## What the MVP audits (scope — reviewer objections #1, #6, #2 in todo notes)

Three snapshots, compared to the manifest on each sweep:

1. **Administrator principals per site** — enumerated by **role membership** (a user whose
   roles include `administrator`, not just `roles[0]`), so an admin held via a secondary
   role is still seen.
2. **Super admins** — the network `site_admins` option vs. `network.super_admins`.
3. **Governance-cap holders** — users holding any `wp_sudo_governance_caps()` slug
   (`functions-governance.php:97`). **Kept, not dropped:** governance caps are granted per
   **user** to the activating admin (`class-plugin.php:583-588` uses `$admin->add_cap()`),
   **not** to the `administrator` role — verified — so a later-created admin holds none and
   the governance set genuinely diverges from the admin set. Dropping it would blind the
   feature to threat #3.
4. **Privileged role definitions** — a hash of the cap-map of privileged roles
   (`administrator`, and any role the manifest marks privileged) read from
   `{prefix}user_roles`, vs. a hash stored in the manifest. **This closes threat #2**
   (role redefinition), which a pure principal-set sweep misses. Cheap — one option read +
   hash. This is the fix for design-review objection #1 (the MVP previously claimed
   `wp_user_roles` protection while deferring the only layer that delivered it).

**Explicitly NOT in the MVP:** enumerating holders of primitive caps
(`manage_options`, `activate_plugins`, …). Those are held by every administrator, so
allowlisting them restates the admin set (todo reviewer note 2). The one case they'd add
value — a *non-admin custom role* granted admin-primitive caps directly — is covered
instead by the **role-definition hash** (#4 above) for defined roles; per-user primitive
grants to custom principals are a deferred, opt-in extension.

## Manifest format & trust root (todo criterion #2 — reviewer objection #2)

**JSON file** (`.json`), not a PHP return-array. Rationale from the review: a `require`d
PHP manifest with a syntax error throws an **uncatchable fatal** (`E_ERROR`/`ParseError`
on an undefined symbol) — for a security control that reads it early, "the manifest
crashed the site" is a worse failure than the drift it guards. `json_decode()` failure is a
**catchable, non-fatal** `null`. Loading is defensive: read → `json_decode` → validate
top-level `is_array`/shape → on **any** failure (missing, unreadable, malformed, wrong
schema) the feature degrades to **inert + an admin/Site-Health warning "manifest
unreadable"**, never a fatal, never a false "all clear."

> Note for the pre-commit reviewer: the guarded read (e.g. `try { json_decode(...) }` +
> `is_array()` validation) is **legitimate integrity handling of a file whose integrity is
> the whole point**, not a CLAUDE.md "test-environment shim." It stays in production.

**Location:** required constant `WP_SUDO_ROLE_MANIFEST` giving an absolute path, ideally
**outside the web root** (no HTTP fetch of the trusted file). **No default guessable
path** — absent constant → feature inert. This makes the security feature strictly opt-in
and gives no probe target. (Answers open Q3.)

**Schema (v1):**
```json
{
  "manifest_version": 1,
  "generated": "2026-07-17T00:00:00Z",
  "sites": {
    "1": { "administrators": [1, 7], "governance": [1] }
  },
  "network": { "super_admins": [1] },
  "privileged_roles": {
    "administrator": "sha256:<hash of its cap-map>"
  }
}
```
Principals are **user IDs** (stable; logins renameable), with logins captured in a sidecar
comment/report at generation time for human review. Role hashes are over a canonicalized
cap-map so key ordering doesn't cause false drift.

## Manifest lifecycle (todo criterion #2, reviewer note 5)

- **Generate:** `wp sudo manifest generate [--network]` snapshots the current trusted set +
  privileged-role hashes into the file; prints a human summary. Operator commits it to
  their config repo.
- **Diff / review:** `wp sudo manifest diff` shows current-state-vs-manifest without
  writing — the same engine the sweep and Site Health use. Human-diffable file.
- **Version / resync:** `manifest_version` gates format migrations; after any *legitimate*
  privilege change the operator re-runs `generate` to re-baseline.
- **Cross-environment:** IDs differ per environment, so the manifest is **not** portable
  as-is — documented; each environment generates its own. (A future login-keyed portable
  variant is out of scope.)

## Triggers & timing (todo research Q1 — reviewer objections #3, #7; answers Q2)

**The MVP touches no MU-plugin / `muplugins_loaded` code.** Every trigger runs in the
normal plugin lifecycle:

- **On-demand:** `wp sudo manifest diff` (WP-CLI, unthrottled) — the authoritative check.
- **Site Health:** a status test in the existing `Site_Health` integration — the reliable
  operator-pull surface.
- **Optional wp-cron daily sweep** — the only *automatic* trigger that fires independent of
  an admin session, so it covers front-end-only / rarely-logged-into sites (a plain
  `admin_init` hook does not, and adds per-admin-load hot-path cost for no coverage gain —
  dropped). Honors the usual `DISABLE_WP_CRON`/alternate-cron caveat, documented.

Reading privileged-user/role state is a query, safe in all three contexts. **Multisite:**
the per-blog Site Health test sees only its blog's principals + role defs; `--network`
(CLI/cron) walks all blogs + `site_admins`. Any throttle transient for the cron sweep is
**network-scoped** for the network walk and blog-scoped for a single-blog check; the sweep
is bounded by the privileged-user count, not all users.

## Boundary vs. the escalation guard — how they compose (todo reviewer note 1)

Complementary, non-overlapping:

- **Escalation guard** = *synchronous, write-time* block of a **hooked** admin/super-admin
  grant; it dies the request before the write persists.
- **Manifest audit** = *asynchronous, after-the-fact* detection of stored state that
  doesn't match the manifest — including the direct-`$wpdb` writes the guard structurally
  cannot see, and role-definition drift the guard never watched.
- **No double-fire, no gap:** guard blocks at write time → nothing persists → the sweep has
  nothing to flag. A direct-DB write bypasses the guard → persists → the next sweep flags
  it. Each event maps to exactly one mechanism.
- **Separate allowlist (answers Q1):** define a dedicated
  `wp_sudo_allow_role_drift( bool $allow, array $principal )` filter — **do not** reuse
  `wp_sudo_allow_escalation`, which is a documented *write-time* decision filter carrying
  `$target_id, $meta_value` (`class-gate.php:973-985`) that has no meaning during a
  state-enumeration sweep. A separate filter keeps both contracts clean and independently
  tunable.
- **Shared audit vocabulary:** a new `wp_sudo_role_drift_detected` action (mirroring
  `wp_sudo_capability_tampered`, `class-plugin.php:560`) plus a matching
  `Event_Recorder::on_role_drift_detected()` (mirroring `on_escalation_blocked`,
  `class-event-recorder.php:261`), so a live guard block and a later drift detection land
  in the same audit stream.

## Break-glass / recovery (todo criterion #3 — reviewer objection #5)

- **Filesystem is the break-glass.** The trust root is the file; any operator with file
  access edits or deletes the manifest. **Deleting it (or unsetting the constant) disables
  the feature.** Since the threat model already excludes filesystem write, this is a clean,
  always-available recovery path.
- **Audit-only cannot lock out** — for the MVP there is nothing to break out *of*; a stale
  manifest only warns. This is the core safety argument for shipping audit-only first.
- **No coupling to `WP_SUDO_RECOVERY_MODE` in the MVP.** Corrected mischaracterization:
  that constant is a **narrow, per-current-user, `manage_wp_sudo`-only** rescue that only
  helps a user who *still holds* `manage_options`/`manage_network_options`
  (`functions-governance.php:74-80`, verified) — **not** a global enforcement kill switch.
  Overloading it would create two sibling controls with divergent break-glass semantics
  (the escalation guard does not consult it either), and in an audit-only MVP there is
  nothing to suppress, so the coupling would be a no-op (and its integration test a
  tautology). Dropped. A **future enforce mode** should instead honor `WP_SUDO_ALLOW_
  ESCALATION` (and/or a new `WP_SUDO_ALLOW_ROLE_DRIFT`), consistent with the escalation
  family; audit-only still *records* during such windows so the trail stays complete.

## IdP / role-management plugin compatibility (todo criterion #4)

Audit-only **never breaks provisioning** — worst case is a warning after an SSO/SAML/OIDC
sync or a Members/User Role Editor change adds a principal not yet in the manifest.
Mitigations: (a) the `wp_sudo_allow_role_drift` filter to pre-bless known dynamic
principals/roles; (b) `wp sudo manifest generate` to re-baseline after a legitimate change;
(c) the warning names the drifted principal/role so the operator can act. Enforce mode is
where compatibility turns dangerous (a sync undone, a provisioned admin denied) — another
reason it is deferred and separately gated.

## Non-goals / security boundaries (from the todo, unchanged)

Does **not** protect against: filesystem/PHP write; code that can edit the manifest;
runtime `user_has_cap`/`map_meta_cap` grants (unless a future enforce model filters
effective caps); legitimate admins acting inside an active sudo window; direct DB writes to
non-role application state. Document as a **high-assurance integrity control for trusted
operators**, not a generic firewall.

## Docs & metrics housekeeping (reviewer objection #9)

The new `wp_sudo_role_drift_detected` action + `Event_Recorder` method are additive but
must be reflected in `docs/current-metrics.md` (hook/event count) and
`docs/developer-reference.md` (canonical hook list/signatures) with the implementation,
per CLAUDE.md's internal-count rule.

## First slice & tests (todo criterion #5, reviewer note 3 + missing scenarios)

**Slice = audit-only detection (principals + role-definition hashes) + `manifest
generate`/`diff` + `wp_sudo_role_drift_detected` event + Site Health test.** No effective-
cap filtering, no DB repair, no MU-loader changes.

Integration tests (real WP + MySQL — the harness that already exercises the tamper canary,
mutating stored state directly, single-site and `WP_TESTS_MULTISITE=1`):

- **Principal drift (direct DB):** `$wpdb`-write `{prefix}capabilities` to add
  `administrator` for a user absent from the manifest → the escalation guard does **NOT**
  fire (proving the boundary) but the sweep fires `wp_sudo_role_drift_detected`.
- **Role-definition drift:** mutate `{prefix}user_roles` to add `manage_options` to
  `editor` → role-hash mismatch → detected. (Closes the headline threat #2.)
- **Multisite super-admin drift:** add a user to `site_admins` not in
  `network.super_admins` → detected.
- **Governance-holder drift:** directly grant a non-admin `manage_wp_sudo` → detected
  (proves threat #3 coverage).
- **Admin via secondary role:** a user with `administrator` as a non-primary role is
  enumerated (role-membership, not `roles[0]`).
- **No-drift / idempotent:** state matching the manifest → no event, no warning.
- **Absent manifest / unset constant:** feature inert (no queries, no events).
- **Malformed manifest:** unreadable/invalid JSON → inert + "manifest unreadable" warning,
  **no fatal**, no false all-clear.
- **Cron-context sweep:** runs with no current user (front-end-equivalent) and fires
  correctly — the primary automatic trigger.
- **Generate round-trip:** generate → legitimate change → re-generate → diff clean.

## Open questions answered (by the design review)

1. **Allowlist:** *separate* `wp_sudo_allow_role_drift(bool, array $principal)` — not a
   reuse of the write-time `wp_sudo_allow_escalation`.
2. **Sweep trigger:** *drop `admin_init`*; ship WP-CLI + Site Health + optional wp-cron
   daily.
3. **Manifest location:** *opt-in by `WP_SUDO_ROLE_MANIFEST` constant*, ideally outside the
   web root; no default guessable path.
4. **Governance holders:** *keep them* — the "admins already cover them" premise is false
   (per-user grant, not role grant).

## Reviewer objections incorporated (2026-07-17)

1. **[Scope/threat] MVP now covers `wp_user_roles` role-definition drift** via a
   privileged-role cap-map hash — previously the headline threat was claimed but its only
   mechanism was deferred.
2. **[Execution/availability] JSON manifest with defensive, non-fatal loading** (was a
   PHP return-array that could fatal the site); malformed → inert + warning.
3. **[Scope/simplicity] MVP touches no MU-loader code** — normal plugin lifecycle only;
   MU surface reserved for a future enforce mode. Brief re-titled accordingly.
4. **[Contract] Separate `wp_sudo_allow_role_drift` filter** instead of overloading the
   write-time `wp_sudo_allow_escalation`.
5. **[Contract] Dropped `WP_SUDO_RECOVERY_MODE` coupling** and corrected its semantics;
   future enforce honors `WP_SUDO_ALLOW_ESCALATION`; removed the tautological recovery test.
6. **[Coverage] Kept governance-holder tracking** (per-user grant ≠ admin role set).
7. **[Execution] Dropped `admin_init`; wp-cron is the automatic trigger** covering
   front-end-only sites; specified per-blog vs. network sweep scope + transient scoping.
8. **[Coverage] Enumerate admins by role membership**, not `roles[0]`.
9. **[Housekeeping] Update `docs/current-metrics.md` + `docs/developer-reference.md`** for
   the new hook/event.

### Verified-against-source claims

Escalation guard hooks `add_user_metadata`/`update_user_metadata` + `grant_super_admin` +
`delete_user`, blind to direct `$wpdb` writes (`class-gate.php:1023-1024,1085,1138`);
tamper canary is `init` pri 1, single-site-only (`class-plugin.php:523,533-535,560`);
`WP_SUDO_RECOVERY_MODE` is a narrow per-user `manage_wp_sudo` rescue requiring
`manage_options` (`functions-governance.php:74-80`); governance caps granted per-user to
the activating admin, not to the role (`class-plugin.php:583-588`); MU loader wires the
gate at `muplugins_loaded` but `register_early()` only registers an `init` pri 0 hook, so
almost nothing runs at `muplugins_loaded` (`wp-sudo-loader.php:189-200`,
`class-gate.php:198-200`).
