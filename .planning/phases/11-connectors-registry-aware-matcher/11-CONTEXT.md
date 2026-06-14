# Phase 11: Connectors Registry-Aware Matcher - Context

**Gathered:** 2026-06-14
**Status:** Ready for planning — pre-TDD design review COMPLETE (see Design Review section; 2 blockers resolved: cache mandate + audit-detail descope)

<domain>
## Phase Boundary

Make WP Sudo's `connectors.update_credentials` rule gate **every** registered connector's credential write to `POST /wp/v2/settings`, not just setting names matching the `connectors_*_api_key` regex. This closes a verified, live false-negative: Akismet's `wordpress_api_key` — registered unconditionally on every WP 7.0 install (verified `wp-includes/connectors.php:237`; research §1.3) — currently passes **ungated**.

Implementation is confined to rewriting `is_connector_api_key_setting_name()` in `includes/class-action-registry.php` as a two-tier check (registry-first via `wp_get_connectors()`, regex fallback), plus tests and docs. Covers requirements CONN-01…CONN-06. This phase does **not** add new gated surfaces, new rules, or per-connector settings/toggles — Connectors stays a single rule under the existing consequence-based model.

</domain>

<decisions>
## Implementation Decisions

### Matching scope
- **api_key-method connectors only.** The registry tier collects `authentication.setting_name` for connectors where `authentication.method === 'api_key'`. This covers all WP 7.0 core connectors (Akismet + the 3 conditional AI connectors). `method === 'none'` connectors carry no secret and are excluded.
- No dedicated new filter for additional credential keys — integrators needing more can already register custom rules via the existing `wp_sudo_gated_actions` filter.

### Fallback posture
- **Union, fail-toward-gating.** Gate if EITHER the registry matches OR the regex `^connectors_[a-z0-9_]+_api_key$` matches. The regex fallback runs as a safety net on every evaluation (not only when `wp_get_connectors()` is absent), so standard-pattern keys are still gated if the registry is unavailable, empty, or a connector registered late. Over-gating risk is negligible because the namespace is connector-specific.

### Credential-clear / removal
- **Gate any write touching the field, regardless of value** — new, changed, or blank/removal. Matching stays presence-based (the param key appears in the request); no value inspection. Rotating or removing a credential is itself sensitive and worth a reauth challenge.

### Gated response
- **Standard `sudo_required` error.** Blocked connector-credential REST/App-Password writes return the existing generic gating error, uniform with every other gated REST action. No per-rule message handling (no other rule uses one).

### Audit-event detail
- **DESCOPED from Phase 11 (design review Finding 2).** Audit hooks fire with a fixed signature `($user_id, $rule_id, $surface)` (`class-gate.php:704` etc.) and the matcher returns a bare `bool`, so the matched field name/connector id are discarded before any hook runs. Delivering field-name detail requires changing the public audit-hook contract (touches the bundled Stream/WSAL bridges + Activity dashboard) — out of this phase's one-method scope and needing its own design review.
- Phase 11 keeps the **existing** audit trail: `rule_id = connectors.update_credentials` is already logged on gate events (verified `tests/Unit/EventStoreTest.php:287`). The "never the secret value" guarantee still holds (the value was never captured).
- Field-name + connector-id observability is **deferred to its own phase** — see Deferred.

### Rule Tester reflection
- The operator Request/Rule Tester (v3.0) must evaluate through the **same two-tier matcher** as the live gate, so testing a write to `wordpress_api_key` reports "would be gated" on WP 7.0. Single source of truth; no divergence between Tester output and live gating.

### Disclosure framing
- **Security-note changelog entry + SECURITY.md entry** (the SECURITY.md work itself lands in Phase 14 / ORG-04). Clearly mark this as a security-relevant coverage fix in the changelog.
- **No CVE now.** It is a defense-in-depth coverage gap, not a WP Sudo-introduced vulnerability: core's `manage_options` capability still gates the underlying `/wp/v2/settings` write, and the plugin is not yet published (no deployed population to warn). Fixed before public distribution.
- **Trigger to revisit:** open a CVE / coordinated disclosure (WPVDP / Patchstack / WPScan norms) **if a coverage-gap of this class is ever found in a publicly-distributed (.org) version** with real installs. The valuable groundwork now is the SECURITY.md reporting channel + supported-versions policy, not a CVE.
- **Release targeting:** ship in **v4.0.0 only** — no 3.4.x backport (plugin not yet on .org; 4.0.0 bundling already decided).

### Claude's Discretion (design-review/technical, not user calls)
- Registry-iteration shape (`foreach ( wp_get_connectors() as $connector )`), `isset()` guards on `authentication.method`/`setting_name`, PHPStan level-6 typing.
- Whether/how to touch `request_contains_connector_api_key()` traversal (research: outer loop unchanged).
- Exact changelog wording and SECURITY.md phrasing.

(Cache mechanism is NO LONGER discretionary — see Design Review Finding 1 below; it is a mandated requirement.)

</decisions>

<code_context>
## Existing Code Insights

### Reusable Assets
- `is_connector_api_key_setting_name( string $key ): bool` — `includes/class-action-registry.php:1045-1047`. **The single method to rewrite** as the two-tier check. Currently regex-only.
- `request_contains_connector_api_key( array $params ): bool` — `:1026-1034`. Outer loop unchanged; the new behavior propagates from `is_connector_api_key_setting_name()`.
- `connectors.update_credentials` rule definition — `:481-494`. **No change needed** (id, surface, callback at `:491` stay the same), so the public rule contract is preserved.
- `reset_cache()` — existing static-cache hygiene method; add the new connector-setting-names cache to it for unit-test isolation.
- Audit hooks fired by the Gate on block/pass — feed the Activity dashboard and the bundled Stream/WSAL bridges; this phase adds matched field-name/connector-id context (never value).
- Request/Rule Tester (v3.0) — operator tool that evaluates request shapes against rules; must route through the same matcher.

### Established Patterns
- Rule match callbacks return `bool`; matching is presence-based over request params.
- Per-request `static` cache + `reset_cache()` reset is the project's pattern for test hygiene (Brain\Monkey mocks per test, static survives the process).
- PHPStan level 6 must pass; `authentication.setting_name` is optional in the type (`setting_name?: non-empty-string`) — guard with `isset()`.
- **Confabulation rule (CLAUDE.md):** the implementation commit MUST cite the verified WordPress core source; re-verify line numbers against the live file at execute time (they drift).

### Integration Points
- WP 7.0 `wp_get_connectors()` registry — returns `array<string id, array{ authentication: { method, setting_name } }>`; frozen at `init @ 15`.
- Gate evaluation surfaces — `rest_request_before_callbacks` and `admin_init`, both after `init`, so the registry is populated when the gate runs. Connectors registered outside `wp_connectors_init` fall through to the regex fallback (fail toward gating).
- Multisite — registry is process-/site-scoped; `wordpress_api_key` is per-site `wp_options`. No special handling expected (design review to confirm, not assume).
- SECURITY.md (Phase 14 / ORG-04) — receives the disclosure-policy groundwork referenced above.

</code_context>

<specifics>
## Specific Ideas

- The concrete driver is the **Akismet `wordpress_api_key` gap** — registered unconditionally on every WP 7.0 install (Akismet ≈5M active installs), reachable via `POST /wp/v2/settings` with cookie auth or Application Password, both modeled WP Sudo adversary surfaces. AI-connector keys (`connectors_ai_*_api_key`) already match the regex and are gated correctly today; only `wordpress_api_key` and custom non-`connectors_*` setting names escape.
- 6 integration tests planned (CONN-01…06), in `tests/Integration/`, exercising the registry path (WP 7.0 lane) and the regex-fallback path (registry absent), a custom-registered connector, and a benign non-connector key (no over-gating).
- Reference implementation sketch and verified core line numbers live in `.planning/research/v4.0/RESEARCH.md` (§1.x, §"Proposed implementation") and `.planning/connectors-matcher-strategy.md`.

</specifics>

<design_review>
## Design Review — COMPLETED 2026-06-14

The CLAUDE.md pre-implementation design review ran before TDD. Core design verified sound (contract integrity, caller coverage, execution contexts, `api_key`-only scope all clean against the live code). Findings the PLAN/implementer MUST honor:

### Finding 1 (BLOCKER) — Cache MUST be a class property, not a function-local static
- The RESEARCH.md "Proposed implementation" sketch uses a **function-local** `static $registry_names` — this is **wrong**: `reset_cache()` cannot clear a function-local static, so it would leak across the shared-process unit suite and poison tests (`tests/TestCase.php:78` calls `reset_cache()` in tearDown).
- **Mandate:** `private static ?array $connector_setting_names_cache = null;` cleared by adding `self::$connector_setting_names_cache = null;` to `reset_cache()` (`class-action-registry.php:~850`). Strike the function-local sketch from RESEARCH.md so it isn't copied during GREEN. This is the single highest-risk item.

### Finding 2 (BLOCKER) — Audit field-name detail descoped (resolved)
- See "Audit-event detail" decision above. Phase 11 ships `rule_id`-only audit; field-name observability is deferred.

### Finding 3 (MEDIUM) — Don't cache an empty registry result as "checked"
- Distinguish "registry not yet built" (`null`) from "built, empty". Do not lock in an empty Tier-1 set that would persist for the request. Production is safe (no pre-`init` caller; registry frozen at `init@15`, both gate surfaces run after) — but the commit message must cite that `init@15` / `rest_request_before_callbacks` ordering as the explicit correctness premise.

### Finding 4 (LOW/MED) — Preserve the regex contract under `wp_get_connectors` absence
- Keep the `function_exists('wp_get_connectors')` Tier-1 guard (legitimate runtime integration check per CLAUDE.md, NOT a shim — do not remove).
- Existing connector tests (`GateTest.php:917`, `AdminTest.php:1232`, using `connectors_ai_openai_api_key`) must stay green; they will now exercise the Tier-1 fall-through with no `wp_get_connectors` stub. Do not add `function_exists` mocks to them; rely on Brain\Monkey's falsy default.

### Finding 5 (LOW) — Document the deliberate `api_key`-only scope
- `method === 'api_key'`-only is correct and complete against verified WP 7.0 core (only `api_key`/`none` exist; `none` carries no secret). Add a code comment + a note in `docs/connectors-api-reference.md` stating a future core auth method is a known re-scoping trigger, not a silent gap. Integrators with other secret methods use `wp_sudo_gated_actions`.

### Required RED-step tests (Findings 3 + 4)
1. `wp_get_connectors` absent → regex tier still gates `connectors_ai_openai_api_key` (preserves pre-7.0 contract).
2. After `reset_cache()`, a second evaluation re-reads `wp_get_connectors()` (pins the cache-invalidation contract against future drift).

</design_review>

<deferred>
## Deferred Ideas

- **Connector field-name audit observability** — record the matched connector setting_name + connector id (never the value) in the audit trail. Descoped from Phase 11 (design review Finding 2): requires changing the audit-hook signature `($user_id, $rule_id, $surface)`, which touches the public hook contract + Stream/WSAL bridges + Activity dashboard. Its own phase, with its own design review.
- **CVE / coordinated disclosure** — explicitly deferred unless a coverage-gap of this class is found in a publicly-distributed (.org) version (see Disclosure framing trigger).
- **Split `docs/connectors-api-reference.md`** into a leaner core reference + a security-analysis companion — already tracked as Future RDOC-01; not part of CONN-06.
- **Dedicated filter for non-api_key credential keys** — rejected for this phase; integrators use the existing `wp_sudo_gated_actions` filter.
- **Connector-specific gated-response message** — rejected in favor of the uniform `sudo_required` error.

</deferred>

---

*Phase: 11-connectors-registry-aware-matcher*
*Context gathered: 2026-06-14*
