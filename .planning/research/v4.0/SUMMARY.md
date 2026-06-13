# Research Summary — WP Sudo v4.0.0 (Major Release & Breaking Changes)

**Milestone:** v4.0.0
**Synthesized:** 2026-06-13
**Source:** `.planning/research/v4.0/RESEARCH.md` (Connectors GA verification + min-req analysis)
**Confidence:** HIGH — Connectors claims verified against `wordpress-develop` trunk source with cited line numbers; PHP/WP floor claims verified against php.net and developer.wordpress.org.

> Roadmapper: read THIS file, not the stale top-level `.planning/research/SUMMARY.md` (that is v2.14 Playwright research).

---

## Headline Verdicts

- **Connectors GA parity: HOLD.** The released WP 7.0 Connectors API matches `docs/connectors-api-reference.md`. Proceed with the registry-aware (registry-first, regex-fallback) matcher exactly as sketched in `.planning/connectors-matcher-strategy.md`.
- **The Connectors gap is a real bug, not hardening.** `wordpress_api_key` is the **Akismet** connector's setting name, registered *unconditionally* on every WP 7.0 install (verified `connectors.php:237`). The current regex `^connectors_[a-z0-9_]+_api_key$` does **not** match it, so `POST /wp/v2/settings` writing `wordpress_api_key` passes ungated today on every WP 7.0 + Akismet site. Changelog should call this a **bug fix**.

## Recommended Minimum Floors (decision locked: bump both)

- **PHP → 8.2.** 8.0 EOL Nov 2023, 8.1 EOL Dec 2024; composer platform pin already `8.1.99`, so the declared 8.0 floor already lags reality. No PHP-version shims exist in production code — the win is an honest declaration + platform-pin bump. (4 lines: `wp-sudo.php`, `readme.txt`, `composer.json` ×2.)
- **WordPress → 6.4.** Unlocks removal of the only WP-version shim: the `function_exists('wp_get_admin_notice')` guard + manual-HTML fallback at two call sites in `includes/class-admin.php` (~lines 2442 and 2475). No further shims drop at 6.5/6.6, so 6.4 is the right cut.

## Milestone Track List (likely phase order; no hard cross-track dependencies)

1. **Connectors GA parity + registry-aware matcher** — `includes/class-action-registry.php`: rewrite `is_connector_api_key_setting_name()` (~1045–1047) as two-tier; `request_contains_connector_api_key()` (~1026–1034) unchanged. Add `$connector_setting_names_cache` clearing to `reset_cache()`. 6 integration tests (CONN-01…06). Doc updates. **Needs a design-review session before TDD** (cache pattern, multisite, registry timing) per CLAUDE.md's pre-implementation review rule — this touches a security-sensitive matcher.
2. **Breaking changes — deprecation removals + min-floor bump.** Remove `sudo_can()` alias (`functions-governance.php` ~90–112; keep the `function_exists` collision guard pattern when deleting) and both `'compatibility'` governance-mode branches (`:82`, `:168`); ship the `_doing_it_wrong()` + persistent notice in the SAME release (bundled, no interim 3.5.0). Bump WP→6.4 / PHP→8.2 and delete the `wp_get_admin_notice` shims. Update CI "6.2 support-floor" lane → "6.4". Verify `uninstall.php` still deletes `wp_sudo_governance_mode`. Integrator migration notes.
3. **Public screenshot/docs refresh** — README/readme/.org assets for the current UI. Low risk; can be the last task before tagging.
4. **Managed-host + minimum-WP manual testing checklist** — extend `tests/MANUAL-TESTING.md` (Apache + one managed host + the new 6.4 floor).

## Watch Out For

- **Confabulation history.** This project has a documented LLM-fabrication log. Every Connectors claim above is source-cited; the implementation commit MUST cite the verified WordPress source (CLAUDE.md verification rule). Re-verify `class-admin.php` / `class-action-registry.php` line numbers against the live file at execute time — they drift.
- **Static cache vs unit tests.** The Tier-1 registry cache must be resettable (recommend a class-level `static` property cleared by `reset_cache()`), or unit tests sharing a process will see a stale `wp_get_connectors()` mock. Design-review item, not implementer free choice.
- **Registry timing.** Registry is frozen at `init @ 15`; both gate surfaces (`rest_request_before_callbacks`, `admin_init`) evaluate after `init` — safe. Connectors registered *outside* `wp_connectors_init` (misuse) fall through to the regex fallback. Fail toward gating.
- **Multisite.** No special handling needed (registry process-scoped/per-site; `wordpress_api_key` is per-site `wp_options`). Document it so the design reviewer can confirm rather than assume.
- **`_doing_it_wrong()` noise.** Firing on every request where compatibility mode is set vs admin-context-only is a real tradeoff — defer to design review.
