# Design brief — #246: Protection Status panel (operator health summary)

## Problem / what it solves

WP Sudo's protective posture is currently spread across surfaces an operator has
to visit one at a time: MU-plugin state and policy review live in **Site Health**,
recent drift/lockout events live in the **dashboard widget**, recovery-mode and
policy-preset state show only as **notices on the Sudo settings screen**, and
several postures (escalation guard on/off, role-manifest enabled/drifted,
recovery-mode scope, HTTPS/cookie context) have **no consolidated view at all**.
There is no single "is WP Sudo actually protecting this site right now?" answer.
This panel is a **read-only** at-a-glance summary that aggregates that state.

This is observability, **not** a control surface. It adds no new enforcement,
no new write actions, and — critically — no new way to weaken protection. That
constraint is the whole point: see [`246 non-goals`](#what-it-must-not-do).

## Proposed approach

Add a compact **Status** view to the existing Settings → Sudo page (a new tab in
the `$tabs` array at `class-admin.php:1924` + `VALID_TABS` + the `switch` at
:1940, rendered by a new `render_status_panel()`), **not** a new top-level admin
screen (per #246). On multisite it renders network-appropriate state.

**Reuse existing state sources — do not re-implement diagnostics** (#246 explicitly
says reuse Site Health). Indicators, each mapped to an existing read:

| Indicator | Source (read-only) |
|---|---|
| MU-plugin installed | `Site_Health::test_mu_plugin_status()` |
| Entry-point policy summary | `Site_Health::test_policy_review()` |
| Gated-action registry integrity | `Site_Health::test_gated_action_integrity()` |
| Role/cap manifest: enabled + drift | `Role_Manifest::is_enabled()`; manifest audit result (side-effect-free) |
| Recovery mode: active + scope | `wp_sudo_is_recovery_mode()` / `wp_sudo_recovery_mode_is_unscoped()` / `wp_sudo_recovery_mode_user()` (from #240) |
| Escalation guard: armed? | `apply_filters( 'wp_sudo_guard_escalation', false )` |
| Current user's session / grace state | `Sudo_Session::is_active()` / `is_within_grace()` |
| HTTPS / secure-cookie context | `is_ssl()` + cookie flags (advisory; ties to #248) |
| Recent lockouts / drift (last 24h) | `Event_Store` query (already powers the dashboard widget) |

Render each as good / needs-attention / critical with a one-line explanation and
a deep link to the setting or doc that explains remediation — mirroring the
Site Health result shape the plugin already returns.

## What it must NOT do (safety constraints for the reviewer)

- **No control actions.** No enable/disable toggles for the escalation guard, the
  sweep, recovery mode, or manifest generate/activate. Those establish or weaken
  trust and must stay in the filesystem/CLI/constant layer — a UI switch is an
  attack the panel would be handing to a compromised admin session. The panel
  only *reports* posture.
- **No writes on render.** ⚠️ Gotcha: `Site_Health::test_stale_sessions()` has a
  **side effect** — it deletes stale session meta. The panel must **not** call
  the mutating `test_*` methods as reads. It needs side-effect-free reads (e.g. a
  count via the private `find_stale_sessions()` path, or a new read-only helper);
  reaching private state from a read view should not add a production getter that
  exists only for the panel — factor a genuine read helper or use the event store.
- **No secret / PII leakage.** Same rule as #240's Site Health test: usernames may
  appear in the panel (admin-only surface) but nothing sensitive, and nothing in
  `debug_information` (the publicly-pasteable tab).
- **True posture, not the remapped view.** Reading escalation-guard / recovery
  state must reflect reality even under the recovery-mode `map_meta_cap` remap —
  the same trap `render_drift_detection_panel()` already had to avoid by reading
  raw caps.
- **No new attack surface.** The panel is gated by `wp_sudo_can('manage_wp_sudo')`
  like the rest of the settings page; it must not become reachable to a
  lower-privilege user, and it must not itself be a gated *action* (it's read-only).

## Scope

MVP = the indicator grid above, read-only, one settings-page tab. **Out of scope
for the MVP:** the network cross-site rollup (#243/#244), CSV/export (that's the
separate Activity-screen item), HTTPS auto-remediation (#248 stays advisory), and
any historical charts. Keep it a status view, not a reporting subsystem.

## Open questions for the design reviewer

1. **Tab vs. panel-on-Settings.** New "Status" tab, or a compact summary block at
   the top of the existing Settings tab? #246 allows either; which is less
   clutter and clearer as the canonical "health" home?
2. **Stale-session read.** What is the cleanest side-effect-free source for the
   session/lockout indicators without adding a test-only production getter or
   triggering the cleanup write?
3. **Escalation-guard posture read.** Is evaluating `apply_filters(
   'wp_sudo_guard_escalation', false )` at render time a faithful "is it armed?"
   signal, given the filter can be context-dependent — or is a dedicated
   posture accessor warranted?
4. **Multisite.** Which indicators are per-site vs. network-global, and does the
   network-admin rendering need different sources than the per-site view?
5. **Duplication risk.** Does adding this panel create two sources of truth vs.
   Site Health, and if so should the panel link out to Site Health for detail
   rather than restating it?

---

## Review incorporated (design reviewer)

> Note: the reviewer ran while a concurrent session had the shared working tree
> checked out on `feat/262` (the shared-tree hazard), so it read code without the
> #240 recovery helpers. **B1 below is corrected accordingly**; the rest stand.

**B1 (corrected → sequencing, not confabulation).** The recovery-mode *scope*
indicator uses `wp_sudo_recovery_mode_is_unscoped()` / `wp_sudo_recovery_mode_user()`,
which **do exist** — on PR #268 (`feat/240`), not yet merged to `main`. So:
sequence #246 implementation **after #240 merges**. Until then the recovery
indicator degrades to the boolean `wp_sudo_is_recovery_mode()` only. Re-verify the
helper names against `main` at build time (they were added by #240).

**B2 (blocking) — drop the stale-session indicator.** `Site_Health::test_stale_sessions()`
**mutates** (`delete_user_meta` per stale user, `class-site-health.php:333-337`) and
its result text is past-tense ("cleaned up"), so it can't be a read. Its pure
sibling `find_stale_sessions()` runs an **unbounded per-render `get_users` meta
scan** — too costly for a settings-page render, and we won't add a test-only
production getter. Resolution: **remove the stale-session indicator**; derive any
session/lockout signal from `Event_Store` (already queried for the lockout/drift
row). Site Health still owns stale-session cleanup.

**B3 (blocking) — no live filter-probe for the escalation guard.** Evaluating
`apply_filters('wp_sudo_guard_escalation', false)` at render time is **not**
faithful: the guard closure evaluates it in the *write-surface* context and
early-returns for cli/cron/xmlrpc, and the guard is **fully bypassed when
`WP_SUDO_ALLOW_ESCALATION` is defined truthy** (`class-gate.php` ~940/1037/1101) —
which the filter alone doesn't reflect. Also, invoking a third-party filter purely
as a probe is mildly risky. Resolution: either **drop the guard indicator for the
MVP**, or add a **dedicated read-only posture accessor** that mirrors the guard's
real preconditions (`filter-on AND ! WP_SUDO_ALLOW_ESCALATION`) — documented as a
new stable contract.

**B4 (blocking) — raw-state reads, per indicator.** Every indicator that touches
capabilities must read **raw stored state** (`$user->allcaps`, the `site_admins`
option, the constant directly) and **never** `current_user_can()`/`has_cap()`,
because the recovery-mode `map_meta_cap` remap rewrites `manage_wp_sudo →
manage_options` for the current user (the exact trap
`render_drift_detection_panel()` already avoids, `class-admin.php:2250-2259`). Make
this an explicit column/constraint in the indicator table.

**N5 (decision) — capability/container.** A pure observability panel is
conceptually a `view_wp_sudo_activity` surface, but a *settings tab* forces
`manage_wp_sudo`. Resolve open-Q1 with this: if activity-viewers should see
posture, host it on the **dashboard-widget context** (already
`view_wp_sudo_activity`-scoped) rather than a settings tab — or accept the higher
cap deliberately. Do not default silently.

**N6 (decision) — multisite scope labels.** Label each indicator per-site vs
network-global (sessions/events are network-global; policies are per-site
`Admin::get()` reads). Verify `Admin::get(...POLICY)` resolves to the intended
store under network admin, or the panel misreports.

**N7 — reuse, don't re-derive.** Call the four side-effect-free Site Health tests
directly (`test_mu_plugin_status`, `test_policy_review`, `test_gated_action_integrity`,
`test_role_manifest` — verified pure; the drift `do_action` fires only in
`Role_Audit::evaluate()`/`run_sweep()`, which the panel must **not** call). Link
out to the dashboard widget for event data rather than restating it.

**N8 — metrics + TDD.** A new `VALID_TABS` entry is fine **iff** it adds no
settings field and no help tab (those counts in `current-metrics.md` stay 6/6-7).
If a help tab is added, bump Help tabs 6→7 there **first** (verify:metrics gate).
Extract a pure `collect_status_indicators(): array` (state assembly, zero render)
and unit-test that it performs **no writes** — assert `delete_user_meta` /
`update_option` / `set_transient` are `->never()` (Brain\Monkey), so a future
mutation reintroduction fails the suite.

**N9 — stay separate from the Access tab.** Do not embed/reuse
`render_drift_detection_panel()`'s governance-coverage table — it renders
grant/revoke controls wired to the `handle_grant_cap` AJAX **write**, which would
break the read-only invariant.

**N10 — nothing into `debug_information`.** Manifest baseline contents and any
recovery-bound identity must never reach the publicly-pasteable Site Health debug
tab.

### Net MVP shape after review

Read-only indicators, sourced as: MU-plugin / policy / registry / role-manifest →
the four pure `test_*` methods; recovery-mode → `wp_sudo_is_recovery_mode()` (scope
detail after #240 merges); recent lockouts/drift → `Event_Store`; HTTPS →
`is_ssl()`. **Dropped from MVP:** stale-session indicator (B2) and the live guard
filter-probe (B3, unless a real accessor is added). **Decide before TDD:** N5
(container/cap) and N6 (multisite scope). Build **after #240 is on main**.
