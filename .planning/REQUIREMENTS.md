# Requirements: WP Sudo v4.0.0 — Pre-Public Hardening Baseline

**Defined:** 2026-06-13
**Core Value:** Every destructive admin action requires proof the person at the keyboard is still the authenticated user.
**Milestone:** v4.0.0

> **Strategic framing (per Codex review, 2026-06-13):** v4.0.0 is a *focused pre-public hardening baseline*, not a feature release. It simplifies governance, verifies WP 7.0 Connectors, removes legacy compatibility paths, sets final platform requirements, and prepares docs/assets for eventual WordPress.org publication — establishing a clean foundation before larger product features. No new product features ship in this milestone.
>
> The previous milestone (v2.14 — Playwright E2E, 32 requirements) shipped complete; its requirements live in git history.
>
> **Source of truth:** `docs/ROADMAP.md` (v4.0.0 planned breaking changes + Connectors matcher) and `.planning/connectors-matcher-strategy.md`. Research: `.planning/research/v4.0/`.
>
> **Kickoff decisions (locked 2026-06-13):** raise BOTH minimum floors (WordPress → 6.4, PHP → 8.2); bundle compatibility-mode deprecation notice + removal into 4.0.0 (no interim 3.5.0); product name is "Sudo", package/slug/text-domain remain `wp-sudo`; repo is `dknauss/Sudo`.

## v1 Requirements

Requirements for the v4.0.0 release. Each maps to exactly one roadmap phase.

### Connectors Registry-Aware Matcher

Closes a verified gating bug: `wordpress_api_key` (the Akismet connector, registered unconditionally on every WP 7.0 install) does not match the current `^connectors_[a-z0-9_]+_api_key$` regex, so connector-credential writes to it pass ungated today. See `research/v4.0/RESEARCH.md` §1.3.

- [x] **CONN-01**: WP Sudo gates a `POST /wp/v2/settings` write to any registered connector's `api_key` setting name — including names that do not fit the `connectors_*_api_key` pattern (e.g. Akismet's `wordpress_api_key`) — when the WP 7.0 Connectors registry is available
- [x] **CONN-02**: WP Sudo gates connector-credential writes for custom-registered connectors that declare an arbitrary `authentication.setting_name`
- [x] **CONN-03**: WP Sudo continues to gate standard `connectors_*_api_key` writes with no regression
- [x] **CONN-04**: On WordPress versions without the Connectors API (`wp_get_connectors` absent), WP Sudo falls back to regex matching and still gates `connectors_*_api_key` writes
- [x] **CONN-05**: WP Sudo does not over-gate benign (non-connector) settings writes (e.g. `blogname`, `siteurl`) under the new matcher
- [x] **CONN-06**: The two-tier matcher and the closed `wordpress_api_key` gap are documented in `docs/connectors-api-reference.md` and `docs/developer-reference.md`, with the verified WordPress core source cited in the implementation commit (confabulation-prevention requirement)

### Breaking Changes & Minimum-Floor Bump

The defining acts of the major: remove deprecated APIs and raise minimum requirements. Compatibility-mode removal ships with its migration notice in the same release.

- [ ] **BRK-01**: The deprecated `sudo_can()` alias is removed; only `wp_sudo_can()` remains (collision-avoidance guard pattern preserved on the surviving function)
- [ ] **BRK-02**: The `compatibility` governance mode is removed — both branches in `wp_sudo_can()` and `wp_sudo_map_governance_meta_cap()`; `strict` governance + the hardened `WP_SUDO_RECOVERY_MODE` break-glass are the only paths
- [ ] **BRK-03**: On upgrade, if `wp_sudo_governance_mode` is still set to `compatibility`, a persistent admin notice and `_doing_it_wrong()` warn the operator that the mode was removed and governance is now strict
- [ ] **BRK-04**: Minimum WordPress version is raised to 6.4 across the plugin header, `readme.txt`, and the CI support-floor lane
- [ ] **BRK-05**: Minimum PHP version is raised to 8.2 across the plugin header, `readme.txt`, `composer.json` `require.php`, and `config.platform.php`
- [x] **BRK-06**: ~~The `function_exists('wp_get_admin_notice')` compatibility shims (two call sites in `includes/class-admin.php`) are removed now that 6.4 is the floor~~ — **OBSOLETE (verified 2026-06-16, Phase 12).** The premise was stale: `wp_get_admin_notice` appears nowhere in `includes/` or `wp-sudo.php` (`grep -rn` returns zero). The only `function_exists` checks in `class-admin.php` guard `graphql` (legitimate runtime integration, not a 6.4 shim). No shim existed to remove; requirement closed as satisfied/obsolete.
- [ ] **BRK-07**: Integrator migration notes document the removed APIs (`sudo_can()`, `compatibility` mode) and the raised WordPress/PHP minimums

### Migration Safety, Capability Audit & First-Run Governance

Post-removal verification track. Confirms the governance simplification did not strand existing 3.0–3.4 installs or leave a lockout/misconfiguration trap before public distribution.

- [ ] **MIG-01**: Upgrades from 3.0–3.4 to 4.0.0 complete cleanly with no orphaned governance state (a leftover `wp_sudo_governance_mode = 'compatibility'` does not produce broken or undefined behavior)
- [ ] **MIG-02**: `WP_SUDO_RECOVERY_MODE` break-glass still works after `compatibility` mode removal and is the only remaining break-glass path
- [ ] **MIG-03**: Uninstall cleanup remains correct after the removals (governance option, `_wp_sudo_*` user meta, legacy role) on both single-site and multisite
- [ ] **MIG-04**: Multisite upgrade behavior is verified — network governance state and super-admin capabilities remain intact through the upgrade
- [ ] **MIG-05**: Post-removal capability audit — all settings/admin/widget screens use the dedicated Sudo capabilities (`manage_wp_sudo`, etc.) with no fallback to bare `manage_options`
- [ ] **MIG-06**: First-run governance is clear and lockout-safe — on first activation an administrator holds `manage_wp_sudo`, and the recovery path for a misconfigured grant is documented
- [ ] **MIG-07**: Tests cover lockout and misconfiguration-recovery scenarios introduced or affected by the governance simplification

### WordPress.org Readiness

Prepares the repo for eventual `.org` submission. The plugin is not yet published; these establish the honest, compliant baseline.

- [ ] **ORG-01**: `readme.txt` passes the WordPress.org readme validator (headers, sections, tested-up-to, stable tag semantics)
- [ ] **ORG-02**: Plugin `assets/` are complete and current — screenshots of the live UI (Settings → Sudo tabs, Access tab, Session Activity dashboard widget, Request/Rule Tester), plus banner and icon
- [ ] **ORG-03**: Brand/identity consistency audit — UI strings read "Sudo" (not "WP Sudo"); package/slug/text-domain intentionally remain `wp-sudo`; GitHub URLs reflect `dknauss/Sudo`; docs explain the product-name vs. slug relationship
- [ ] **ORG-04**: `SECURITY.md` reviewed/updated — vulnerability reporting channel, supported-versions policy, whether GitHub issues are acceptable for security reports, and the security-fix changelog-wording convention
- [ ] **ORG-05**: `Stable tag`, license declaration, and plugin-header fields are WordPress.org-compliant
- [ ] **ORG-06**: Documentation-accuracy pass reconciles stale roadmap status against shipped code — notably the request-stash pattern-based redaction item (ROADMAP lists it open, but `phase3-stash-minimization-spec.md` + `CHANGELOG` show suffix-based redaction shipped); verify the true residual against the redaction code and correct the docs
- [ ] **ORG-07**: An initial WordPress.org submission checklist is captured in the repo docs

### Manual Testing Environment Checklist

- [ ] **ENV-01**: `tests/MANUAL-TESTING.md` has an environment checklist section listing the environments to verify before each release
- [ ] **ENV-02**: The checklist requires at least one Apache environment, one managed WordPress host (Pressable / WP Engine / Cloudways), and the new minimum-supported WordPress version (6.4)
- [ ] **ENV-03**: The checklist captures Connectors-credential manual verification — cookie-auth and Application Password writes to `/wp/v2/settings` with connector credential fields, including `wordpress_api_key`

## Future Requirements

Acknowledged, deferred beyond v4.0.0. Kept visible per Codex review; not v4.0.0 blockers.

### Governance & Recovery

- **RGOV-01**: Scoped single-user recovery form — `define( 'WP_SUDO_RECOVERY_MODE', <user_id_or_login> )` (ROADMAP §12.1 Phase R3)

### Visibility & Audit

- **RACT-01**: Full Sudo Activity admin screen (list-table: pagination, search, sortable columns, CSV export)
- **RAUDIT-01**: External Audit Mode (delegate persistence/notifications to Stream / WP Activity Log) — only on real operator demand

### Documentation Structure

- **RDOC-01**: Split `docs/connectors-api-reference.md` into a leaner core reference + a separate security-analysis companion (ROADMAP line 75)

### Larger Tracks (separate milestones)

- **GUTEN-01**: Gutenberg block editor reauthentication UX (snackbar/notices transport)
- **NETADM-01**: Multisite network-admin dashboard widget + cross-site session revocation (ROADMAP §11.1)
- **SESS-01**: Dedicated sudo-session table architecture (conditional on Tier 2+ scale)

## Out of Scope

Explicitly excluded from v4.0.0. Documented to prevent scope creep.

| Feature | Reason |
|---------|--------|
| Interim 3.5.0 deprecation release | Decision locked: deprecation notice + removal bundled into 4.0.0 (plugin not yet on WordPress.org) |
| PHP floor above 8.2 (8.3+) | 8.2 is the conservative raise with security support through 2026; higher bump buys no shim cleanup and trails managed-host support |
| WordPress floor above 6.4 (6.5/6.6) | No additional compat shims drop above 6.4; reserve for a later major |
| New pattern-redaction *build* for request stash | Suffix-based redaction already shipped (v2.10.2–v2.11.0); v4.0.0 only reconciles the stale doc status (ORG-06), not new code |
| Dedicated sudo-session table | Conditional Tier 2+ scale work (deferred → SESS-01) |
| Full Sudo Activity screen / External Audit Mode | Deferred (RACT-01 / RAUDIT-01); useful but less urgent than governance/Connectors cleanup |
| Gutenberg reauth UX | Large design lift; do after v4 cleanup (GUTEN-01) |
| New per-connector setting or toggle | Breaks the two-layer (consequence-based rules + surface policy) model; Connectors stays a rule |

## Traceability

Populated during roadmap creation (2026-06-13). Each requirement maps to exactly one phase.

| Requirement | Phase | Status |
|-------------|-------|--------|
| CONN-01 | Phase 11 | Complete |
| CONN-02 | Phase 11 | Complete |
| CONN-03 | Phase 11 | Complete |
| CONN-04 | Phase 11 | Complete |
| CONN-05 | Phase 11 | Complete |
| CONN-06 | Phase 11 | Complete |
| BRK-01 | Phase 12 | Done |
| BRK-02 | Phase 12 | Done |
| BRK-03 | Phase 12 | Done |
| BRK-04 | Phase 12 | Done |
| BRK-05 | Phase 12 | Done |
| BRK-06 | Phase 12 | Done (obsolete — no shim existed) |
| BRK-07 | Phase 12 | Pending |
| MIG-01 | Phase 13 | Pending |
| MIG-02 | Phase 13 | Pending |
| MIG-03 | Phase 13 | Pending |
| MIG-04 | Phase 13 | Pending |
| MIG-05 | Phase 13 | Pending |
| MIG-06 | Phase 13 | Pending |
| MIG-07 | Phase 13 | Pending |
| ORG-01 | Phase 14 | Pending |
| ORG-02 | Phase 14 | Pending |
| ORG-03 | Phase 14 | Pending |
| ORG-04 | Phase 14 | Pending |
| ORG-05 | Phase 14 | Pending |
| ORG-06 | Phase 14 | Pending |
| ORG-07 | Phase 14 | Pending |
| ENV-01 | Phase 15 | Pending |
| ENV-02 | Phase 15 | Pending |
| ENV-03 | Phase 15 | Pending |

**Coverage:**
- v1 requirements: 30 total
- Mapped to phases: 30/30
- Unmapped: 0

---
*Requirements defined: 2026-06-13*
*Last updated: 2026-06-13 — traceability populated by gsd-roadmapper (phases 11-15)*
