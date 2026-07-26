# Design brief — #240: scoped break-glass recovery + Site Health critical status

## Problem / threat model

`WP_SUDO_RECOVERY_MODE` is the break-glass escape hatch for the "last Sudo
manager locked out" scenario. Today `wp_sudo_is_recovery_mode()` treats the
constant as boolean-only: while it is defined truthy, **every** user who still
holds `manage_options` (single-site) / `manage_network_options` (multisite)
regains full `manage_wp_sudo` governance authority. On a site with several
administrators that is a wider blast radius than break-glass needs — the failure
mode being contained is "one specific admin is locked out," not "all admins
lost governance." A permanent-if-forgotten, all-admins grant is the risk.

Second gap: recovery mode has no Site Health surface. An operator who defines
the constant during an incident and forgets to remove it gets only the in-admin
notice (visible only while on a Sudo screen). There is no health-dashboard
signal that the governance model is currently weakened.

## Proposed approach

Two independent, additive changes to `includes/functions-governance.php` and
`includes/class-site-health.php`.

### 1. Scoped recovery user

Allow `define( 'WP_SUDO_RECOVERY_MODE', <user_id | user_login> )` to scope the
grant to a single user. Semantics, chosen to preserve the existing contract:

- **`true` (strict boolean)** → unscoped legacy behavior: any admin passes the
  role gate, exactly as today. Backward-compatible.
- **`int`** → resolve as a user **ID**; recovery grants only that user.
- **non-empty `string`** → resolve as a user **login** via `get_user_by('login')`;
  grants only that user. (A numeric string is treated as a login first; this is
  documented.)
- **falsy / unresolvable value** (`false`, `''`, `0`, a login/ID that matches no
  user) → recovery is inactive / matches nobody. Fail-closed: an unresolvable
  target grants no one. The operator controls wp-config and can correct a typo;
  break-glass failing closed on a bad target is safer than granting broadly.

New helpers (bootstrap-safe, in `functions-governance.php`):

- `wp_sudo_recovery_mode_user(): ?int` — resolves the constant to a target user
  ID, or `null` when unscoped (boolean `true`) **or** inactive. Note the return
  cannot by itself distinguish "unscoped" from "inactive"; callers pair it with
  `wp_sudo_is_recovery_mode()`.
- `wp_sudo_user_matches_recovery( int $user_id ): bool` — the single predicate
  both governance callers use. Returns true iff recovery is active AND
  (`unscoped` OR `$user_id === resolved target`).

`wp_sudo_is_recovery_mode()` keeps its current boolean meaning — "is recovery
active at all" — returning true for **both** the boolean and scoped forms, so
every existing caller/test contract (the admin notice, event recorder,
dashboard widget label) is preserved.

Wiring — replace the inline `get_current_user_id() === $user_id` current-user
check in the two governance functions with the new predicate:

- `wp_sudo_can()` path 2: add `&& wp_sudo_user_matches_recovery( $user_id )`
  alongside the existing current-user and role-gate checks.
- `wp_sudo_map_governance_meta_cap()`: the recovery branch already keys on
  `get_current_user_id() === $user_id`; add the scoped predicate so a scoped
  constant maps `manage_wp_sudo` → primitive admin cap only for the target user.
  Non-target users fall through to the strict `array( $cap )` path (core then
  denies, since they lack the granted governance cap).

### 2. Site Health critical status

Add `Site_Health::test_recovery_mode()`, registered in `register_tests()` beside
`wp_sudo_stale_sessions` as a `direct` test. Pull-based (runs on the Site Health
screen / `wp cli site-health`), no scheduling.

- Recovery inactive → `good`: "Break-glass recovery mode is not active."
- Recovery active → `critical`: names that the governance model is currently
  weakened, whether it is scoped (report the target login/ID) or unscoped (any
  admin), and instructs removing the constant from wp-config.php once normal
  access is restored. Badge: Security.

## What this explicitly blocks / does NOT block

- **Blocks:** a scoped constant granting governance to any admin other than the
  named target. Blocks a silent/undiscoverable "recovery left on" state (Site
  Health now flags it critical).
- **Must NOT block / must preserve:**
  - Legacy `define( ..., true )` unscoped behavior — unchanged.
  - The multisite super-admin short-circuit in `wp_sudo_can()` (runs before the
    recovery branch) — super admins never depend on recovery.
  - The role gate: even a scoped target still must hold `manage_options` /
    `manage_network_options`. Scoping is additive, not a replacement.
  - The reauth challenge — recovery has never bypassed it and still must not.
  - `wp_sudo_is_recovery_mode()` returning true for the scoped form, so the
    existing admin notice / audit hook / widget label still fire.

## Open questions for the reviewer

1. **`define(..., 1)` ambiguity.** An operator who wrote `1` as "truthy/on" now
   scopes to user ID 1. The plugin has no shipped install base (release delayed,
   per release-status.md), so back-compat cost is low — but is treating only a
   strict boolean `true` as "unscoped" the right cut, or should there be an
   explicit sentinel (e.g. `true` vs any scalar)? Current plan: strict `true`
   (`=== true`) is the only unscoped form.
2. **Numeric login vs ID.** Plan treats any string as a login first. Is an
   int-vs-string type split the least-surprising rule, or should a numeric
   string try ID-then-login?
3. **Fail-closed on unresolvable target.** Is granting nobody on a bad
   login/ID the right call for a break-glass control, given the operator can
   still fall back to `true`?
4. **Execution-context coverage.** `get_user_by()` is available on all admin /
   REST / CLI / cron paths where governance checks run; the resolver adds a user
   lookup to `wp_sudo_can()`. Any hot path where that lookup is a concern, or
   context where `get_user_by` is unsafe/unavailable at the load point?
5. **Multisite target resolution.** `get_user_by('login')` is network-global;
   the role gate already uses the network cap on multisite. Any per-site
   subtlety where a login resolves to a user not present on the current blog?

---

## Review incorporated (design reviewer, pre-implementation)

**B1 (blocking) — no `?int`-conflation fail-open.** The grant decision lives in a
single seam `wp_sudo_user_matches_recovery( int $user_id ): bool` that classifies
recovery into THREE explicit states before granting, never inferring "unscoped"
from a null target:
- inactive → `false`;
- **unscoped** (`wp_sudo_recovery_mode_is_unscoped()` true) → legacy behavior:
  current-user-only match (`get_current_user_id() === $user_id`), preserving the
  pre-scoping contract;
- **scoped** → grant iff `wp_sudo_recovery_mode_user()` resolves to a non-null ID
  AND `$user_id === target`. An unresolvable target (typo'd login/ID) yields
  `null` → **matches nobody** (fail-closed). A null target is never read as
  unscoped.

**B2 (blocking) — strict unscoped detection.** `wp_sudo_recovery_mode_is_unscoped()`
uses `true === WP_SUDO_RECOVERY_MODE` (strict). `define(...,1)` is therefore a
scoped user-ID-1, not unscoped. `wp_sudo_is_recovery_mode()` deliberately stays
loose (`&& WP_SUDO_RECOVERY_MODE`) so a truthy scoped value still reports
"active"; a code comment flags the intentional strictness split.

**M3 — GovernanceTest churn is in scope.** Existing recovery-branch unit tests are
re-pointed to stub the new `wp_sudo_user_matches_recovery()` seam. `SiteHealthTest`'s
"adds four tests" count updates to five.

**M4 — notice copy branches.** `Admin::render_recovery_mode_notice()` gains
scoped vs unscoped copy (scoped names the target); the unscoped copy is unchanged.

**N5/N6 — Site Health diagnosability + no leak.** `test_recovery_mode()` reports
scoped-resolved (`login`, ID N), scoped-**unresolvable** ("does not resolve to any
user; no one is granted — check for a typo"), or unscoped (any admin). The target
is `esc_html()`'d and appears in the status result only — never in
`debug_information`.

**N8 — resolver does no cap checks.** `wp_sudo_recovery_mode_user()` calls only
`get_user_by()` (no `user_can`/`current_user_can`), so it cannot re-enter
`map_meta_cap`. Comment added. Int form does no lookup at all.

**N9 — multisite documented.** Login resolution is network-global; access still
hinges on the network role gate (fail-closed if the target lost admin). Noted in
`security-model.md` §Break-glass.

## Test split

- **Unit (GovernanceTest):** the full three-state grant logic of
  `wp_sudo_user_matches_recovery()` (B1), stubbing its sub-seams — including the
  fail-closed "unresolvable target grants nobody" case. Plus guards that the
  new helpers return false/null when the constant is undefined (the unit env).
- **Integration:** `wp_sudo_recovery_mode_user()` constant + `get_user_by()`
  resolution against real users (int-ID and login forms, target-grant vs
  non-target-deny) — the correct environment for a real user lookup.
