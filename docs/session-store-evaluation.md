# Session Store Evaluation for WP Sudo

*Created April 19, 2026. Updated April 20, 2026 to reflect the 3.0.0 pre-release performance pass (widget and Users-list transient caches, Event_Recorder write buffering, batched event-log prune).*

## Summary

WP Sudo's current sudo-session state is authoritative in user meta:

- `_wp_sudo_token`
- `_wp_sudo_expires`
- `_wp_sudo_failure_event`
- `_wp_sudo_throttle_until`
- `_wp_sudo_lockout_until`

That remains correct and shippable for v3.0.0. The remaining performance question is not whether the current model works, but whether high-frequency reads should stay on `usermeta` as dashboard visibility and multi-user operations expand.

**Recommendation:** for a future post-3.0.0 performance phase, move toward an **authoritative session table with usermeta shadow writes**. That option gives WP Sudo the best read-path improvement while preserving rollback safety, compatibility with existing session logic, and a gradual migration path.

This is a follow-up architecture decision, **not** a v3.0.0 release blocker.

---

## Current State and Hot Paths

Current hot-path session reads/writes live in:

- `includes/class-sudo-session.php`
- `includes/class-gate.php`
- `includes/class-admin-bar.php`
- `includes/class-dashboard-widget.php`
- `includes/class-admin.php`
- `uninstall.php`
- integration and E2E fixtures that directly manipulate `_wp_sudo_expires`

### Current authoritative data model

| Store | Purpose | Current authority |
|---|---|---|
| user meta | sudo token, expiry, lockout/throttle, failed attempts | Authoritative |
| cookie | browser-bound sudo token transport | Transport/binding only |
| events table (`wpsudo_events`) | audit visibility and operator telemetry | Authoritative for audit data |

### Current hot-path reads that would matter at scale

| Area | Current read shape | Why it matters |
|---|---|---|
| `Sudo_Session::is_active()` / token verification | point reads from user meta | frequent gate-path check |
| `Sudo_Session::is_within_grace()` | point reads from user meta | frequent near-expiry check |
| admin bar | repeated sudo-state checks while browsing admin | high-request-frequency path |
| dashboard widget active sessions | `WP_User_Query` meta query on `_wp_sudo_expires`, cached in a 30 s per-site transient (3.0.0) | warm path is cheap; cold rebuild still meta-query-bound at large user counts |
| Users screen `sudo_active=1` count badge | `WP_User_Query::get_total()` via `_wp_sudo_expires` meta query, cached in a 30 s per-site transient (3.0.0) | warm path is cheap; filtered Users-list render still hits the meta query uncached |
| uninstall cleanup | deletes `_wp_sudo_*` keys across users/sites | migration/rollback concern |

---

## Evaluation Criteria

Each option below was evaluated against the same criteria:

1. hot-path read reduction
2. migration complexity
3. multisite behavior
4. uninstall/cleanup parity
5. rollback safety
6. test churn
7. object-cache interaction
8. compatibility with cookie/grace behavior
9. impact on audit/event logic
10. future usefulness for network dashboards/reporting

---

## Option 1: Authoritative Session Table + Usermeta Shadow

### Model

- Add a dedicated session table, likely shared-network style like `wpsudo_events`, with `site_id` retained for local views.
- Session truth moves to the table.
- Existing user meta keys continue to be written as compatibility shadows during the migration period.

### Candidate schema shape

| Column | Purpose |
|---|---|
| `id` | row primary key |
| `site_id` | current site context |
| `user_id` | user owning the session |
| `token_hash` | hashed sudo token |
| `expires_at` | session expiry timestamp |
| `grace_until` or derived grace logic | grace handling |
| `lockout_until` | lockout timestamp |
| `throttle_until` | retry-delay timestamp |
| `failure_events` or separate failure rows | failed-attempt tracking |
| `updated_at` | diagnostics / reconciliation |

### Pros

- Best reduction in high-value read-path cost.
- Clean indexed reads for:
  - active session lists
  - network dashboards
  - cross-site operator reporting
- Safer long-term base for multisite and fleet-level tooling.
- Usermeta shadow keeps rollback simple.
- Existing cookie binding and grace logic can be preserved with minimal UX change.

### Cons

- Highest write-path complexity of the three options.
- Requires temporary dual-write discipline.
- Needs migration/reconciliation logic and explicit cutover checks.

### Migration shape

1. Create session table.
2. Dual-write: table + existing user meta.
3. Shift read paths to prefer table, with optional fallback to meta.
4. Run soak period and telemetry validation.
5. Remove fallback reads later; keep shadow writes until comfortable.

### Rollback story

- Roll back reads to user meta immediately.
- Keep usermeta shadow current during rollout so rollback is low-risk.
- Session table can remain inert without breaking existing users.

### Read/write matrix

| Component | Reads move? | Writes move? | Notes |
|---|---|---|---|
| `Sudo_Session` | Yes | Yes | primary touchpoint |
| `Gate` | Indirectly | No direct change | depends on session helpers |
| `Admin_Bar` | Yes | No | should read through session helper only |
| `Dashboard_Widget` | Yes | No | active sessions should query table |
| Users filter | Yes | No | avoid meta query |
| uninstall | Yes | Yes | remove table rows + meta shadows |
| test fixtures | Yes | Yes | many fixtures need helpers instead of direct meta |

---

## Option 2: Read-Model Mirror Table

### Model

- Keep user meta authoritative.
- Maintain a dedicated table only for fast reads (active-session lists, counts, dashboards).
- Writes update both user meta and the mirror table.

### Pros

- Lower behavioral risk than a full authority move.
- Can accelerate widget and users-list paths quickly.
- Leaves `Sudo_Session` core logic mostly unchanged at first.

### Cons

- Two sources of truth in practice, even if one is nominally primary.
- Reconciliation logic is still required.
- Point-read gate paths still hit user meta, so only some hot paths improve.
- Long-term complexity can be worse than Option 1 because the mirror never becomes a complete model.

### Migration shape

1. Create mirror table.
2. Update activation/session writes to keep mirror in sync.
3. Move widget/users-list reads to mirror.
4. Keep core gate/session logic on user meta.

### Rollback story

- Easy to stop reading the mirror table.
- Harder to justify keeping it forever if it does not become authoritative.

### Read/write matrix

| Component | Reads move? | Writes move? | Notes |
|---|---|---|---|
| `Sudo_Session` | Mostly no | Yes | still meta-centric |
| `Gate` | No | No | no major change |
| `Admin_Bar` | No | No | unchanged |
| `Dashboard_Widget` | Yes | No | main beneficiary |
| Users filter | Yes | No | main beneficiary |
| uninstall | Yes | Yes | remove mirror + meta |
| test fixtures | Some | Some | dual representation still needed |

---

## Option 3: Full Cutover from Usermeta to Session Table

### Model

- Stop using user meta as the session store.
- All token/expiry/lockout/throttle state lives only in the session table.

### Pros

- Cleanest eventual architecture.
- No dual-write tail once complete.
- Strongest long-term query model.

### Cons

- Highest migration risk.
- Weakest rollback story.
- Largest test churn.
- Most likely to break assumptions in existing helper code, fixtures, and third-party extensions that inspect `_wp_sudo_expires`.

### Migration shape

1. Create session table.
2. Backfill from user meta.
3. Cut reads and writes over in one or two tightly coupled releases.
4. Remove user meta compatibility path.

### Rollback story

- Hardest rollback: requires reverse-sync or acceptance of session invalidation.
- Higher chance of user-visible session churn during rollout.

### Read/write matrix

| Component | Reads move? | Writes move? | Notes |
|---|---|---|---|
| `Sudo_Session` | Yes | Yes | full rewrite point |
| `Gate` | Indirectly | No direct change | depends on session helpers |
| `Admin_Bar` | Yes | No | through helpers |
| `Dashboard_Widget` | Yes | No | through new query surface |
| Users filter | Yes | No | through new query surface |
| uninstall | Yes | Yes | table-only cleanup |
| test fixtures | Yes | Yes | largest churn |

---

## Additional Alternatives Considered

These alternatives are worth documenting, but are currently lower-ranked than
Options 1–3 for WP Sudo's long-term direction.

## Option 4: Keep Usermeta Authoritative + Query-Shape Hardening

### Model

- Keep session truth in user meta.
- Continue optimizing high-frequency reads with query-shape improvements and
  short-lived caches.
- Defer any new session table.

### Pros

- Lowest migration risk.
- Lowest test churn.
- Fastest delivery for near-term performance wins.

### Cons

- Aggregate reads (active-session counts/lists) remain constrained by usermeta
  query behavior at larger scale.
- Multisite/network-level reporting remains harder than a table-backed model.
- Defers, rather than resolves, long-term session-read architecture limits.

## Option 5: Cache-First Overlay (Object Cache Read Model)

### Model

- Keep user meta as authority.
- Maintain cache-backed active-session indexes/counters for dashboard and users
  screens.

### Pros

- Can reduce read latency significantly on deployments with persistent object
  cache.
- Avoids immediate session-table migration complexity.

### Cons

- Higher invalidation complexity; correctness depends on cache coherence.
- Uneven portability across hosts without persistent object cache.
- Adds operational fragility compared with a database-backed authoritative model.

### Current partial adoption (3.0.0)

A bounded, TTL-based variant of this option shipped in 3.0.0 for the two
aggregate-read hot paths most sensitive to user-table size:

- `Dashboard_Widget::get_active_sessions_payload()` — 30 s transient
  `wp_sudo_active_sessions_{blog_id}`.
- `Admin::get_sudo_active_user_count()` — 30 s transient
  `wp_sudo_active_count_{blog_id}`.

These use the WordPress transient API (object-cache-backed when a persistent
cache is configured, `wp_options`-backed otherwise). The short TTL sidesteps
the invalidation-correctness concerns above: stale reads decay within 30 s of
a session create/expire rather than requiring coherent invalidation on every
session write.

This is **interim mitigation, not the long-term design.** Gate-path reads
(`Sudo_Session::is_active()`, `is_within_grace()`) are untouched and still
read user meta per request. Option 1 remains the recommended direction.

---

## Option Comparison

| Criterion | Option 1: authoritative table + shadow | Option 2: mirror table | Option 3: full cutover |
|---|---|---|---|
| Hot-path read reduction | **High** | Medium | **High** |
| Migration complexity | Medium-high | Medium | **High** |
| Multisite fit | **Strong** | Medium | Strong |
| Uninstall parity | Strong | Strong | Medium |
| Rollback safety | **Strong** | Strong | Weak |
| Test churn | Medium | Low-medium | **High** |
| Object-cache dependence reduction | **High** | Medium | **High** |
| Cookie/grace compatibility | Strong | Strong | Medium-high |
| Audit/event integration fit | Strong | Medium | Strong |
| Network dashboard/reporting value | **Strong** | Medium | Strong |

---

## Recommended Option

### Choose Option 1

**Recommendation:** implement **authoritative session table + usermeta shadow** in a future dedicated phase.

Why:

- It meaningfully improves the right reads, not just the widget.
- It keeps rollback practical.
- It supports future multisite/network operator tooling better than a mirror-only model.
- It avoids the operational risk of a hard cutover.

The 3.0.0 widget/Users-list transient caches (see Option 5 → Current partial
adoption) mitigate aggregate-read cost but do not touch the per-request
gate-path meta reads on `_wp_sudo_token` / `_wp_sudo_expires` that fire on
every gated admin request. Option 1 is still required to retire those reads
and to support network-admin cross-site session views.

### Why not Option 2

- It solves only part of the problem.
- It keeps the most important gate-path reads on user meta.
- It adds reconciliation complexity without yielding a complete long-term model.

### Why not Option 3

- It is too abrupt for the current plugin maturity and release cadence.
- It would force the most invasive migration and fixture churn.
- It does not buy enough additional value over Option 1 to justify the rollback risk.

### Why not Option 4

- It is a valid short-term posture, but it does not materially change the
  long-term scaling characteristics of aggregate session reads.
- It delays, rather than solves, the architecture problem this evaluation was
  intended to address.

### Why not Option 5

- It can be useful as an optimization layer, but is not a strong primary design
  because behavior varies widely by hosting/cache configuration.
- Cache invalidation complexity creates additional failure modes for security
  visibility features.

---

## Required Code Touchpoints for a Future Session-Table Phase

### Production code

- `includes/class-sudo-session.php`
  - token activation
  - token verification
  - expiry / grace reads
  - lockout/throttle storage
- `includes/class-gate.php`
  - indirect session-state lookups via `Sudo_Session`
- `includes/class-admin-bar.php`
  - active-session state display
- `includes/class-dashboard-widget.php`
  - active-session listing/counts
- `includes/class-admin.php`
  - Users screen `sudo_active` count and filtered list
- `includes/class-plugin.php`
  - activation/deactivation hooks and cron/setup helpers
- `includes/class-upgrader.php`
  - schema creation and migration sequencing
- `uninstall.php`
  - table cleanup plus compatibility meta cleanup

### Tests and fixtures

- integration tests that seed `_wp_sudo_expires` directly
- unit tests that assert direct `get_user_meta()`/`update_user_meta()` behavior
- Playwright/E2E helpers that assume usermeta-backed active sessions

---

## Future Implementation Notes

- Preserve the current cookie-bound sudo behavior exactly during the first phase.
- Prefer helper methods over direct meta access in tests before the storage move.
- Keep multisite shared-table semantics aligned with `wpsudo_events`.
- Treat shadow writes as temporary compatibility plumbing, not permanent architecture.

## Decision

For post-v3.0.0 performance work, WP Sudo should plan around **Option 1: authoritative session table with usermeta shadow writes**.
