# Post-4.1.0 Development Scopes — Part 2 (design-reviewed)

Companion to [`post-4.1.0-dev-scopes.md`](post-4.1.0-dev-scopes.md). Two further
candidate work items, each scoped from the code and run through the
Pre-Implementation Design Review process (per `CLAUDE.md`); reviewer objections are
incorporated. **No implementation has started.**

This same change also **fixed the drift** in
[`../docs/external-audit-mode-spec.md`](../docs/external-audit-mode-spec.md) that the
Cluster 2 review surfaced (see Cluster 2 → 2a).

Source of truth is the code (`includes/`), not these notes — re-verify symbol and
line references before editing.

---

## Cluster 1 — Two Factor lifecycle bridge

**Type:** new security feature (opt-in `bridges/` mu-plugin). **Effort:** Medium.
**Status:** researched + design-reviewed; 7 required changes captured below.

### Problem
WP Sudo validates a second factor *during* the sudo challenge but does not gate
**factor-management**. A compromised session + known/phished password can generate
new recovery codes or reset TOTP that satisfy the *later* sudo 2FA step, defeating
the gate. Goal: require an active sudo session before **creating or replacing**
factors.

### Research (verified against upstream `WordPress/two-factor` master; matches repo notes @ `38cd183`)
The Two Factor providers fire **no dedicated `do_action` lifecycle hooks** — gate by
**route + meta write**, not by event:
- Recovery-code generation: `POST /two-factor/1.0/generate-backup-codes` →
  `generate_codes()` writes `_two_factor_backup_codes`.
- TOTP setup/delete: `POST`/`DELETE /two-factor/1.0/totp` →
  `set_user_totp_key()`/`delete_user_totp_key()` write/delete `_two_factor_totp_key`.
- Provider enable/disable: `profile.php`/`user-edit.php` `action=update` writes
  `_two_factor_enabled_providers` / `_two_factor_provider`.
- **Trap:** `_two_factor_backup_codes` is rewritten on code **consumption during
  login** (`delete_code()`), not only on generation.

### Approach (hybrid: route-gating + selective meta-gating)
1. **REST-route rules** (via `wp_sudo_gated_actions`, gated by `intercept_rest` +
   app-password policy): `POST /two-factor/1.0/generate-backup-codes`; `POST` and
   `DELETE /two-factor/1.0/totp`. (`matches_rest()` regex-matches any namespace, so
   `/two-factor/1.0/...` routes work.)
2. **Effect-level meta-gating** for the **safe** keys only — `_two_factor_totp_key`,
   `_two_factor_enabled_providers`, `_two_factor_provider` (written only on deliberate
   config, never during auth) — to catch the profile-form path the REST rules miss.
3. **EXCLUDE `_two_factor_backup_codes` from meta-gating** (consumption rewrites it;
   gating would block 2FA sign-in). Generation is covered by the route rule in (1).

### Required design changes (from review) — before TDD
1. **🔴 BLOCKER — actor-session / target-audit.** The meta guard MUST check the
   **actor's** session (`get_current_user_id()`) and use the meta `$user_id` only as
   the target/audit subject — exactly like `arm_escalation_guard()`. Checking the
   *target's* session fails open on the admin-resets-another-user path.
2. **Idempotence check.** `profile.php` round-trips `_two_factor_enabled_providers` to
   its same value on every save; gate **only on actual change** (compare incoming vs
   persisted, mirror `newly_grants_administrator()`) or every profile save by a 2FA
   user gets a spurious challenge.
3. **Gate replacement/reset/disable only — never first-time enrollment.** Confirmed in
   code: a user with no factor gets a **password-only** sudo challenge
   (`Sudo_Session::needs_two_factor()` false), so no lockout; gating zero-to-one buys
   ~no security and adds onboarding friction. (TOTP: gate only when a key already
   exists. Providers: gate change/removal, not first add.)
4. **Drop the effect-backstop from the rationale.** `arm_effect_guards()` covers a
   fixed WP-core hook set and structurally cannot see Two Factor meta writes; the
   meta-gating layer is the only effect-level coverage.
5. **Document residual gaps honestly.** CLI/direct `generate_codes()` is not gated
   (can't meta-guard `_two_factor_backup_codes` without blocking login); the JS
   2FA-settings UI gets a non-recoverable `sudo_required` JSON 403 → **depends on the
   block-editor Phase-1 `challenge_url` work** (see Part 1, Scope 3) or ships a
   degraded-but-honest error.
6. **Bridge, not built-in** — opt-in `bridges/` mu-plugin, `class_exists('Two_Factor_Core')`-
   guarded, rules via `wp_sudo_gated_actions` (built-in rules fail *closed* on a
   plugin that may be absent; a bridge degrades gracefully).
7. **Multisite:** 2FA meta keys are **global** (`_two_factor_*`) — do NOT copy the
   capabilities blog-prefix regex (`is_user_capabilities_meta_key`).

### Dependency
Cleanly benefits from the block-editor Phase-1 `challenge_url` work (recoverable
in-UI reauth for the 2FA settings screen). Sequence after, or accept a degraded error.

---

## Cluster 2 — Audit / visibility cluster

Three interrelated sub-items of **different readiness**.

### 2a. External Audit Mode — spec was DRIFTED; fixed in this change, then implement
The review found `docs/external-audit-mode-spec.md` named a non-existent
`Event_Recorder::record()` choke point and `render_events_panel()` method, guessed the
bridge-detector class names, and asserted per-site settings that contradict the
network-wide `wp_sudo_settings` storage. **Fixed in this change** (verified against
code):
- Choke point is the private **`Event_Recorder::enqueue()`** (single point both the
  buffered `$pending` and direct paths funnel through); short-circuit there, before
  the buffer append.
- The four external-audit **meta-events** persist locally via an explicit
  **`always_persist()` allowlist** at that choke point (resolves the hand-waved
  "governance hooks always persist").
- Detector reuses the bridges' own helpers — `wp_sudo_stream_bridge_available()`
  (checks `wp_stream_get_instance()`) and `wp_sudo_wsal_bridge_available()` (checks
  `\WSAL\Controllers\Alert_Manager` / `wsal_log_event()`) — **not** the guessed
  `WP_Stream\Connectors`. Detector evaluated **at event time** (not cached from
  `admin_init`) so non-admin surfaces (REST/cron/CLI) fail-closed correctly.
- Dashboard method is **`render_recent_events()`**.
- Liveness copy says "WP Sudo last **dispatched** an event to {target}" — it cannot
  confirm bridge-side persistence, so it must not claim "✓ recorded in Stream".
- Multisite: setting is **network-wide** (in `wp_sudo_settings`); events remain
  per-site. (Per-site override would require splitting the key — out of scope.)

**Status:** with the spec corrected, 2a is implement-to-spec + TDD. One open item:
re-verify the upstream Stream/WSAL symbol names against the shipped bridge files
before coding (per `CLAUDE.md`).

### 2b. Sudo Activity screen MVP — scope-creep + capability contradiction
- 🔴 A dashboard widget **already** renders a filterable 50-row Recent Events table.
  A separate paginated "screen" *is* an audit-log viewer — violating the non-goals.
  **Extend the widget or add a single "View all"** reusing `recent_for_dashboard()`;
  **forbid** pagination/export/date-range.
- 🔴 **Capability contradiction:** the Settings → Sudo page requires `manage_wp_sudo`
  to render, but the activity view should be `view_wp_sudo_activity` — so a Settings
  tab can't serve view-only users. Needs a dashboard-area screen or separate menu.
  Resolve before building.
- **PII discipline:** keep `DASHBOARD_SELECT_COLUMNS` (excludes `ip`/`context`); never
  use `Event_Store::recent()` (full `*`) — don't regress IP exposure (`ip` exists only
  on `lockout` rows).

### 2c. Audit-visibility integrity warnings — collapse to one surface
- 🔴 Three surfaces (Site Health + settings + dashboard) for the same fact = alarm
  fatigue, violates Simplicity-First. **One authoritative surface — a Site Health
  test** (`class-site-health.php` already hosts integrity tests), passive inline
  mention at most.
- **Detectable conditions only:** passed-event logging disabled
  (`WP_SUDO_DISABLE_PASSED_EVENT_LOGGING` / `wp_sudo_log_passed_events_enabled`),
  bridge class absent. The code **cannot** tell "bridge loaded but silently dropping"
  from "quiet" — warning copy must not imply it can.

### Sequencing
`2a (now implementable, spec fixed) → 2b (extend widget, with the bridge-status tile
2a defines) → 2c (one Site Health warning)`. Guardrail across all three: stay a
**modest built-in visibility layer**, not an audit-log product — delegation to
Stream/WSAL (2a) is the answer for heavy audit needs. Keep 2b/2c **current-site-scoped**;
defer network aggregation to the network-dashboard backlog item.

---

## Cross-cutting
- Each scope (and each phase) takes its own design review + TDD + pre-commit reviewer
  before code.
- Two findings of note that the design reviews surfaced: an actor-vs-target
  **fail-open** that would be introduced by a naive 2FA bridge (prevented at design
  time, code not yet written), and **spec drift** in the External Audit Mode doc that
  would have misled implementation (fixed here). The shipped-code item worth acting on
  is the **Connectors cache-invalidation** finding tracked in Part 1, Scope 1.
