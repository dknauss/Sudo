# Phase 11 — Connectors Registry-Aware Matcher — Research

**Created:** 2026-06-14
**Provenance:** Consolidated (not a fresh researcher run). The domain research for this phase was completed at the milestone level in [`.planning/research/v4.0/RESEARCH.md`](../../research/v4.0/RESEARCH.md) (verified against `wordpress-develop` trunk with cited line numbers) and deepened by the COMPLETED pre-TDD design review recorded in [`11-CONTEXT.md`](11-CONTEXT.md). This file gives the phase its own canonical reference and — critically — the **Validation Architecture** that `11-VALIDATION.md` derives from.

---

## What we need to know to PLAN well (answered)

### Verified core API (source: milestone RESEARCH.md §1.x, cited against `wp-includes/connectors.php`)

- `wp_get_connectors()` returns `array<string $id, array{ authentication: array{ method: 'api_key'|'none', setting_name?: non-empty-string } }>`.
- **Akismet** connector is registered *unconditionally* on every WP 7.0 install: `method => 'api_key'`, `setting_name => 'wordpress_api_key'`. This is the live false-negative — the current regex `^connectors_[a-z0-9_]+_api_key$` does not match `wordpress_api_key`.
- The three AI connectors (`connectors_ai_{anthropic,openai,google}_api_key`) are conditionally registered and DO match the existing regex — already gated correctly today.
- `WP_Connector_Registry::register()` (via the `wp_connectors_init` action) lets plugins set an arbitrary `authentication.setting_name`.
- **Timing:** the registry is frozen at `init @ 15`. Both gate evaluation surfaces — `rest_request_before_callbacks` and `admin_init` — run after `init`, so the registry is populated when the matcher executes. Connectors registered outside `wp_connectors_init` fall through to the regex fallback (fail toward gating).

### Implementation approach (LOCKED — see CONTEXT.md + design review)

Two-tier **union** matcher in `is_connector_api_key_setting_name()` (`includes/class-action-registry.php:~1045`):
- **Tier 1 — registry:** guarded by `function_exists('wp_get_connectors')`; collect `setting_name` for connectors where `authentication.method === 'api_key'`; cache the set in a **class property** `private static ?array $connector_setting_names_cache` cleared by `reset_cache()` (~:850). Distinguish `null` (not built) from built-empty.
- **Tier 2 — regex fallback (always runs):** `^connectors_[a-z0-9_]+_api_key$`.

> **Design-review BLOCKER carried here:** the milestone RESEARCH.md's "Proposed implementation" sketch uses a **function-local** `static $registry_names` (RESEARCH.md:266). That is WRONG — `reset_cache()` cannot clear a function-local static, so it leaks across the shared-process unit suite. Use a class property. This is the single highest-risk item; the plan (`11-01-PLAN.md`) overrides the sketch explicitly.

Scope is `method === 'api_key'` only (complete for WP 7.0 core; `none` carries no secret). The rule definition (`:481-494`) and `request_contains_connector_api_key()` outer loop (`:1026-1034`) are unchanged. No audit-hook signature change (field-name observability descoped).

---

## Validation Architecture

This section is the source for [`11-VALIDATION.md`](11-VALIDATION.md). It defines what is verified, at which level, and how feedback is sampled during execution.

### Test levels

| Level | Why | What it covers |
|-------|-----|----------------|
| **Unit** (Brain\Monkey, `tests/Unit/GateTest.php`) | Fast (~1s), mocks `wp_get_connectors()` per test, drives the matcher through the `request_contains_connector_api_key` callback path. The existing connector test `test_match_request_matches_rest_connector_credentials_settings` (~:917) is the pattern. | Registry-tier match of a non-regex `setting_name` (unit analog of CONN-01); regex fallback when registry absent (CONN-04 / DR-1); benign keys not gated (CONN-05); `connectors_*_api_key` regression (CONN-03); cache invalidation via `reset_cache()` (DR-2). |
| **Static** (PHPStan L6, `composer analyse`) | Catches type errors the LLM introduces (optional `setting_name`, null-cache handling). | The `isset()` guards + nullable cache property type-check clean. |
| **Integration** (`WP_UnitTestCase`, new `tests/Integration/ConnectorsMatcherTest.php`, WP 7.0 lane) | Only a real WP 7.0 environment has the live Connectors registry; mocks can't prove the registry path end to end. | CONN-01 (real Akismet `wordpress_api_key` gated); CONN-02 (custom connector registered via `$registry->register()` gated). Skips cleanly when `wp_get_connectors()` is absent. |
| **Manual / process** | "Commit cites verified WP core source" and "docs updated" are confabulation-prevention checks, not automatable assertions. | CONN-06. |

### Observability & sampling (Nyquist)

- **After each task commit:** `composer test:unit` (~1s feedback).
- **After the GREEN task:** `composer test:unit && composer analyse`.
- **Before verify-work:** full unit + PHPStan green; integration green on the WP 7.0 lane.
- **Max feedback latency:** ~1s (unit tier). No watch-mode flags. No behavior verified only at the slow (integration) tier without a fast-tier proxy — every registry-path behavior has a unit analog (mocked `wp_get_connectors`) plus an integration confirmation.

### Wave 0 (new test infrastructure created within the plan's own RED task)

- Unit tests extending `tests/Unit/GateTest.php` (CONN-03/04/05, DR-1, DR-2, registry-tier match). No `function_exists` mocks added — rely on Brain\Monkey's falsy default for "registry absent".
- New `tests/Integration/ConnectorsMatcherTest.php` (CONN-01/02).
- No framework install — PHPUnit 9.6 + Brain\Monkey + Mockery already present.

### Coverage map (requirement → level)

| Req | Unit | Integration | Manual |
|-----|------|-------------|--------|
| CONN-01 | ✅ (mocked) | ✅ (real) | |
| CONN-02 | | ✅ | |
| CONN-03 | ✅ | | |
| CONN-04 | ✅ | | |
| CONN-05 | ✅ | | |
| CONN-06 | | | ✅ |
| DR-1 | ✅ | | |
| DR-2 | ✅ | | |

---

## Pitfalls (from design review)

- **Function-local static cache** — the one BLOCKER; use a class property (above).
- **Caching an empty registry as "checked"** — distinguish `null` from built-empty so a post-`reset_cache()` rebuild is possible.
- **Removing the `function_exists` guard** — it is a legitimate runtime integration check (WP 6.2 min vs 7.0 feature), NOT a shim. Keep it.
- **Line drift** — re-verify `:1045` / `:850` / `:482` / `:1026` against the live file at execute time.
- **Confabulation** — the implementation commit MUST cite the verified `wp-includes/connectors.php` Akismet block and the `init@15` / `rest_request_before_callbacks` timing premise.

---

*Phase: 11-connectors-registry-aware-matcher*
*Research consolidated: 2026-06-14*
