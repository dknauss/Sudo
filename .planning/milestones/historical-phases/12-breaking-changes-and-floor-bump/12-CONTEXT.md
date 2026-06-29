# Phase 12: Breaking Changes and Floor Bump - Context

**Gathered:** 2026-06-16
**Status:** Ready for planning

<domain>
## Phase Boundary

Remove deprecated APIs and legacy governance paths, raise the platform floor, and
document the breaking changes for integrators. No new product behavior ships.

In scope (BRK-01 … BRK-07):
- Remove the deprecated `sudo_can()` alias (hard removal — calling it must fatal).
- Excise `compatibility` governance mode from both `wp_sudo_can()` and
  `wp_sudo_map_governance_meta_cap()`; `strict` + `WP_SUDO_RECOVERY_MODE` are the
  only remaining paths.
- Add an upgrade-time deprecation/migration notice for sites still set to
  `compatibility`.
- Raise minimums to **WordPress 6.4** and **PHP 8.2** across all declaration points
  and the CI support-floor lane.
- Reconcile BRK-06 (claimed `wp_get_admin_notice` shims) against reality.
- Write integrator migration notes.

Out of scope (Phase 13 / MIG): deleting or normalizing the stale
`wp_sudo_governance_mode` option in the database, capability audit, uninstall
verification, lockout-safe first-run. This phase's notice only *signals* the stale
option — it does not remove it.

</domain>

<decisions>
## Implementation Decisions

### Locked at v4.0.0 kickoff / by ROADMAP success criteria (not re-discussed)
- Raise **both** floors: WordPress → 6.4, PHP → 8.2.
- Compatibility-mode removal is **bundled into 4.0.0** (no interim 3.5.0).
- `sudo_can()` → **hard removal**: the function must not exist; calling it fatals.
  `wp_sudo_can()` survives and keeps its collision-avoidance guard semantics.
- `WP_SUDO_RECOVERY_MODE` remains the **sole** break-glass path (keep
  `wp_sudo_is_recovery_mode()` and both recovery short-circuits intact).
- Strip the `compatibility` branch from `wp_sudo_can()` (functions-governance.php
  lines 82–85) and from `wp_sudo_map_governance_meta_cap()` (lines 168–170); both
  default to `strict` (`user_can($user_id, $cap)`). Update the docblocks that
  describe compatibility mode (lines 47–49, 52, 156–160).

### Migration / deprecation notice (BRK-03)
- **Fire context:** admin screens only — hook `admin_notices` +
  `network_admin_notices`, matching the plugin's existing gate/replay notice
  pattern. No front-end, REST, or cron noise.
- **Persistence:** persistent until resolved — the notice reappears as long as
  `wp_sudo_governance_mode === 'compatibility'` is still in the DB. It is a real
  misconfiguration signal, so it is **not** dismissible into silence. It clears
  automatically once the option is normalized/removed (that cleanup is Phase 13).
- **Developer signal:** also emit `_doing_it_wrong()` (BRK-03 names it) so the
  condition surfaces in `WP_DEBUG`/CI/integrator contexts without spamming
  production operators.
- **Audience:** gate the operator notice on `wp_sudo_can('manage_wp_sudo')` (which
  already short-circuits for multisite super admins) — the people who own Sudo
  config, consistent with the governance model.

### Integrator migration notes (BRK-07)
- **Primary deliverable:** a prominent "Breaking changes" block for 4.0.0 in
  `CHANGELOG.md` and a `readme.txt` Upgrade Notice (discoverability), **plus** a
  durable "Migrating to 4.0" section in `docs/developer-reference.md` where the
  removed hook/function contracts already live. No new standalone file.
- Must cover: `sudo_can()` removal (use `wp_sudo_can()`), `compatibility` mode
  removal (governance is now strict; `WP_SUDO_RECOVERY_MODE` is the only
  break-glass), and the raised WordPress 6.4 / PHP 8.2 minimums.

### BRK-06 reconciliation (verify-then-close)
- BRK-06's premise is **stale**: there are no `wp_get_admin_notice()` shims in
  `includes/class-admin.php` (only legitimate `graphql` runtime-integration guards
  at lines 383, 616, 1295), and `wp_get_admin_notice` appears **nowhere** in
  `includes/`.
- The planner keeps BRK-06 as a **verification task**: confirm via grep that no
  `wp_get_admin_notice` shim exists anywhere in production code, record it as
  satisfied/obsolete in the SUMMARY, and update `REQUIREMENTS.md` accordingly.
  Do **not** invent removal work; do **not** silently drop it.

### Claude's Discretion
- Exact wording of the admin notice and the `_doing_it_wrong()` message.
- Whether the deprecation-notice logic lives in `Admin`, a small dedicated method,
  or is wired via the `Upgrader` — planner/research decides the cleanest home.
- Exact CI matrix edits beyond the floor bump (which lanes, label text).

</decisions>

<specifics>
## Specific Ideas

- Pre-Implementation Design Review is **required** before TDD (per CLAUDE.md and the
  ROADMAP phase note): the brief must cover what `compatibility` mode enabled and
  what its removal blocks, where the notice fires and the fatigue tradeoff (resolved
  above: admin-only, persistent-until-resolved, `_doing_it_wrong()` paired,
  manage_wp_sudo audience), that `WP_SUDO_RECOVERY_MODE` is the sole break-glass, and
  the `sudo_can()` removal scope.
- This project has a documented confabulation history (`docs/llm-lies-log.md`); the
  BRK-06 finding is an example. Any external/version claims added this phase must be
  verified against live source per CLAUDE.md verification rules.

</specifics>

<code_context>
## Existing Code Insights

### Reusable Assets
- **Admin-notice pattern**: `class-gate.php` (`render_blocked_notice`,
  `render_gate_notice`) and `class-challenge.php` (`render_redacted_replay_notice`,
  `render_blocked_replay_notice`) already register `admin_notices` +
  `network_admin_notices` callbacks that echo `notice notice-warning` divs. The new
  deprecation notice should follow this idiom.
- **Upgrader**: `includes/class-upgrader.php` runs version-keyed routines
  (`upgrade_2_0_0` … `upgrade_3_3_0`) via `maybe_upgrade()`. A new routine could
  detect the stale `compatibility` option at upgrade time, but per scope the *cleanup*
  is Phase 13 — Phase 12 only signals.
- **Governance helpers**: `includes/functions-governance.php` — `wp_sudo_can()`
  (60–88), `sudo_can()` alias (90–112), `wp_sudo_map_governance_meta_cap()` (149–173),
  `wp_sudo_is_recovery_mode()` (192–194), `wp_sudo_governance_caps()` (125–132).

### Established Patterns
- `wp_sudo_can('manage_wp_sudo')` is the canonical authority check across admin
  surfaces and already short-circuits multisite super admins — reuse it for the
  notice audience gate.
- Version constant must be bumped in **four** places per CLAUDE.md (wp-sudo.php
  header + constant, phpstan-bootstrap.php, tests/bootstrap.php) — but that is the
  release version, separate from the WP/PHP *floor* bumps below.

### Integration Points — floor bump declaration sites
- `wp-sudo.php:7` `Requires at least: 6.2` → 6.4; `wp-sudo.php:8` `Requires PHP: 8.0`
  → 8.2.
- `readme.txt:5` `Requires at least` → 6.4; `readme.txt:7` `Requires PHP` → 8.2.
- `composer.json:7` `"php": ">=8.0"` → `>=8.2`; `composer.json` `config.platform.php`
  (~line 37, currently 8.1.99) → re-evaluate vs the 8.2 floor.
- CI `.github/workflows/phpunit.yml`: integration matrix support-floor lane
  `wp: '6.2'` (line 177) → 6.4; PHP `8.0` lanes (lines 96, 112, 271, 287) → 8.2.
  Also review `compat-wordpress-minors.yml` and `plugin-check.yml` for floor refs.

### Verification finding (BRK-06)
- `grep -rn "wp_get_admin_notice" includes/ wp-sudo.php` → **no matches**. The only
  `function_exists` checks in `class-admin.php` guard `graphql` (runtime integration,
  NOT a 6.4 shim).

</code_context>

<deferred>
## Deferred Ideas

- **Stale-option cleanup** — normalizing/deleting `wp_sudo_governance_mode` when set
  to `compatibility` belongs to **Phase 13 (Migration Safety)**, which depends on this
  phase. Phase 12's notice only signals; it must not delete the option.
- Larger product features (Gutenberg UX, full Activity screen, network-admin tools,
  session table) remain deferred per PROJECT.md — out of scope for v4.0.0.

</deferred>

---

*Phase: 12-breaking-changes-and-floor-bump*
*Context gathered: 2026-06-16*
