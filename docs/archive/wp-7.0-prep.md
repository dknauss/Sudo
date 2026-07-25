# WordPress 7.0 Preparation (archived)

> Relocated from `ROADMAP.md` §2 on 2026-07-25. **Status: complete** — WP 7.0
> shipped 2026-05-20. Retained as the prep/verification record; the live
> Abilities/MCP forward concern is tracked in `../abilities-api-assessment.md`.

## WordPress 7.0 Prep

> **Status: Complete.** WP 7.0 GA shipped May 20, 2026. All RC and final signoffs are recorded in `tests/MANUAL-TESTING.md`. `Tested up to: 7.0` ships in v3.3.0. The `rewrite_role_error()` / `render_role_error_notice()` workaround (Trac #64690) removed in v3.4.0. The Connectors registry-aware matcher **shipped in v4.1.0** (`Action_Registry::is_connector_api_key_setting_name()` reads and caches `wp_get_connectors()` — see the CHANGELOG); the "open security gap" section below is retained only as the historical problem statement.

### Verified changes that affect WP Sudo

| Change | Impact | Action needed |
|--------|--------|---------------|
| **PHP minimum raised to 7.4** (dropping 7.2/7.3) | WP Sudo requires PHP 8.2+. No impact. | None. Already ahead of the curve. |
| **Always-iframed post editor** | All blocks render in iframe. WP Sudo's admin UI gating does not touch the block editor — it intercepts `admin_init` actions, not editor saves. | **Low risk.** Verify the challenge page CSS still works inside the admin chrome. |
| **Admin visual refresh** (DataViews, design tokens, Trac #64308) | Settings → Sudo page uses standard `settings_fields()` / `do_settings_sections()`. If WP 7.0 reskins these, our page gets the new look for free. | ✅ Completed against WP 7.0 pre-release and GA builds; continue ordinary visual checks before future compatibility bumps. |
| **Fragment Notes + @ mentions** | Extends 6.9 Notes (block-level comments). No auth surface — notes are post meta. | No impact on WP Sudo. |
| **Abilities API expansion** | New REST surface for AI agents. 3 read-only core abilities in WP 7.0. Abilities use `permission_callback` (typically `current_user_can()`). Not gated by WP Sudo. | **No action for 7.0.** Existing REST surface interception already covers `/wp-abilities/v1/` routes. When destructive abilities appear (`DELETE` on `/run`), add a REST rule to `Action_Registry`. See [`docs/abilities-api-assessment.md`](../abilities-api-assessment.md). |
| **WP AI Client merge proposal** | Provider-agnostic AI API. Includes REST/JS layer. | No immediate impact. If merged, AI model calls routed through REST are covered by existing Gate. Monitor. |
| **WordPress MCP Adapter** | Translates Abilities into MCP tools for AI agents (Claude, Cursor, etc.). Calls abilities through the same REST endpoints. | **No new surface.** MCP Adapter is a REST consumer — covered by existing `Gate::intercept_rest()`. Same gating strategy as Abilities API. See [`docs/abilities-api-assessment.md`](../abilities-api-assessment.md). |
| **Viewport-based block visibility** | Editor-only. No auth surface. | No impact. |
| **Trac #64690 — Bulk role-change error message** ([ticket](https://core.trac.wordpress.org/ticket/64690)) | ✅ Core shipped the fix in WP 7.0 GA. `rewrite_role_error()` and `render_role_error_notice()` removed in v3.4.0; corresponding unit tests deleted. | Done. |

### What to do now

1. ~~**Install WP 7.0 Beta 1** on Local or Studio dev site~~ — done (February 19, 2026)
2. ~~**Run the manual testing guide** against WP 7.0 pre-release builds~~ — done; all 15 sections PASS
3. ~~**Visual check:** settings page, help tabs, admin bar timer, challenge interstitial, admin notices~~ — done; all pass against refreshed admin chrome
4. ~~**Run `composer test`**~~ — passing through WP 7.0 GA; CI covers the current 7.0 lane
5. ~~**Repeat manual verification on each later RC build and on the final release**~~ ✅ Done — WP 7.0 GA shipped May 20, 2026; final signoff recorded in `tests/MANUAL-TESTING.md`.
6. ~~**Keep the standard local verification set green for each RC/GA checkpoint**~~ ✅ Done.
7. ~~**Update version references when WordPress 7.0 final ships**~~ ✅ Done in v3.3.0 — `Tested up to: 7.0` in `readme.txt`.
8. ~~**Remove `handle_err_admin_role()` workaround**~~ — done (v3.4.0); `rewrite_role_error()` and `render_role_error_notice()` removed after Trac #64690 confirmed in WP 7.0 GA.

### Connectors registry-aware matcher (✅ shipped in v4.1.0 — historical problem statement)

> This was the open problem when WP 7.0 landed; the fix shipped in v4.1.0 (two-tier
> matcher: `wp_get_connectors()` registry first, regex fallback). Kept as history.

**Problem (verified against core trunk):** the `connectors.update_credentials` rule
identifies connector-secret writes to `/wp/v2/settings` by a **name convention** —
`is_connector_api_key_setting_name()` matches `^connectors_[a-z0-9_]+_api_key$`
(`includes/class-action-registry.php`). But WP 7.0 derives setting names from the
connector registry, and they do not all fit that shape: core's own defaults include
a connector whose key is `wordpress_api_key` (`wp-includes/connectors.php`), and
`register_connector()` lets plugins set an arbitrary `authentication.setting_name`.
A write to such a key is a registered connector secret that the current regex does
**not** gate — a false negative on exactly the credential-replacement threat the
security model advertises. (The regex can also false-positive on a benign option
that happens to fit the pattern.)

**Fix:** make the registry the source of truth.
- Primary: when `function_exists( 'wp_get_connectors' )` (WP 7.0+), enumerate
  `wp_get_connectors()`, collect `authentication.setting_name` for every connector
  with `authentication.method === 'api_key'`, and gate any settings write whose
  params touch one of those keys. Core shape: `{ id => { authentication: { method,
  setting_name } } }`.
- Fallback: keep the regex when the registry function is absent or returns empty
  (older WP, or connectors not yet initialized at match time) — fail toward gating.
- Files: `request_contains_connector_api_key()` / `is_connector_api_key_setting_name()`
  and the rule definition in `includes/class-action-registry.php`.

**Design-review questions (security-sensitive + execution-context-dependent):** is the
registry populated when the gate evaluates (REST `permission_callback` / `admin_init`)
relative to when `_wp_connectors_init` fires? Cache the setting-name set per request;
verify the full set of core default setting_names against trunk before coding;
multisite behavior.

**Tests:** a registered connector with a non-`connectors_*` setting_name (e.g.
`wordpress_api_key`) is gated; registry-unavailable falls back to the regex; a benign
non-connector key is not over-gated. **Effort:** Low–Medium.

### Abilities API and MCP Adapter: the longer-range question

The Abilities API (WP 6.9+) and the WordPress MCP Adapter are the first new admin
action surfaces WordPress has added since Application Passwords (WP 5.6). The MCP
Adapter translates registered abilities into MCP tools for AI agents — it calls the
same REST endpoints, so both are covered by the same gating strategy.

Currently, all three core abilities are read-only (`GET` on `/run`). If a destructive
ability appears (e.g., a future `core/delete-plugin` using `DELETE` on `/run`), WP Sudo
would need to intercept it.

**Recommended approach:** Add a REST rule to `Action_Registry` matching destructive
ability runs. The existing `Gate::intercept_rest()` already intercepts all REST
requests via `rest_request_before_callbacks` — no new surface type is needed. A new
`ability` surface type would only be warranted if abilities become a separate
first-class non-REST execution surface in plugin/core practice. Core already has a PHP
execution path via `WP_Ability::execute()`, but that remains a monitor-only concern for
now rather than justification for a new surface type.

For full analysis, trigger conditions, and example rules, see
[`docs/abilities-api-assessment.md`](../abilities-api-assessment.md).

---

