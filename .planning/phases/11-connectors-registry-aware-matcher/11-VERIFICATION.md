---
phase: 11-connectors-registry-aware-matcher
verified: 2026-06-15T00:00:00Z
status: passed
score: 6/6 must-haves verified
---

# Phase 11: Connectors Registry-Aware Matcher — Verification Report

**Phase Goal:** Close the `wordpress_api_key` gating gap — rewrite the connector-credential matcher so connector-credential writes to `POST /wp/v2/settings` are gated for ALL registered `api_key`-method connectors (including Akismet's `wordpress_api_key`) via a two-tier matcher (WP 7.0 Connectors registry first, existing regex as a union fallback).

**Verified:** 2026-06-15
**Status:** PASSED
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|---------|
| 1 | POST /wp/v2/settings writing wordpress_api_key is gated on WP 7.0 (registry tier matches Akismet's api_key setting_name) | VERIFIED | `is_connector_api_key_setting_name()` Tier 1 collects setting_name where method=api_key; unit test `test_connector_registry_tier_gates_non_regex_setting_name` passes; integration test `test_conn01_wordpress_api_key_gated_via_registry_tier` covers live WP 7.0 lane |
| 2 | POST /wp/v2/settings writing a custom connector's arbitrary api_key setting_name is gated when present in the registry | VERIFIED | Integration test `test_conn02_custom_api_key_connector_is_auto_gated` registers on live `WP_Connector_Registry` and asserts gated |
| 3 | connectors_ai_openai_api_key remains gated with no regression | VERIFIED | Unit test `test_connector_regex_fallback_gates_connectors_ai_openai_api_key_conn03` passes (793/793 green) |
| 4 | When wp_get_connectors is absent, the regex fallback still gates connectors_*_api_key names | VERIFIED | Unit test `test_connector_regex_fallback_when_registry_absent_conn04_dr1` stubs empty registry and asserts regex fallback gates; Tier 2 always runs (union) |
| 5 | Benign settings writes (blogname, siteurl, timezone_string) are not gated by the new matcher | VERIFIED | Unit test `test_connector_matcher_does_not_gate_benign_settings_conn05` asserts none of the three settings match `connectors.update_credentials` |
| 6 | The connector setting-name set is cached in a class property cleared by reset_cache(), not a function-local static | VERIFIED | `private static ?array $connector_setting_names_cache = null` declared at line 66; `reset_cache()` sets it to null at line 866; `test_connector_reset_cache_forces_registry_reread` (DR-2) proves re-read after reset |

**Score:** 6/6 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `includes/class-action-registry.php` | Two-tier `is_connector_api_key_setting_name()` with class-property cache cleared in `reset_cache()` | VERIFIED | Lines 1079–1102: registry-first tier + regex fallback union; property at line 66; reset at line 866 |
| `tests/Unit/GateTest.php` | RED unit tests: registry-tier match, CONN-03/04/05 guards, DR-1, DR-2 | VERIFIED | 4107 lines; 5 new tests at lines 3920–4106 covering all specified cases; 793 tests pass |
| `tests/Integration/ConnectorsMatcherTest.php` | CONN-01 (Akismet wordpress_api_key gated) and CONN-02 (custom connector gated) against real WP 7.0 registry | VERIFIED | 221 lines; 3 tests; skip guard for pre-WP-7.0 lanes; CONN-01 and CONN-02 coverage present |
| `docs/connectors-api-reference.md` | Two-tier matcher documentation with api_key-only re-scoping note | VERIFIED | "Two-tier matcher" subsection at line 304+; RE-SCOPING TRIGGER note documented |
| `docs/developer-reference.md` | Updated connectors.update_credentials example with link to connectors-api-reference.md | VERIFIED | Two-tier reference at lines 143–154; link present |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `is_connector_api_key_setting_name()` | `wp_get_connectors()` | `function_exists('wp_get_connectors')` guard + foreach collecting api_key setting_name | VERIFIED | `function_exists( 'wp_get_connectors' )` at line 1081; foreach at line 1084; isset guards on method/setting_name at lines 1085–1087 |
| `reset_cache()` | `self::$connector_setting_names_cache` | `= null` assignment | VERIFIED | `self::$connector_setting_names_cache = null;` at line 866 in `reset_cache()` |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|---------|
| CONN-01 | 11-01-PLAN.md | Gate POST /wp/v2/settings for registered api_key connector names including non-regex names (wordpress_api_key) when registry available | SATISFIED | Registry-tier in is_connector_api_key_setting_name(); unit + integration tests |
| CONN-02 | 11-01-PLAN.md | Gate connector-credential writes for custom-registered connectors with arbitrary setting_name | SATISFIED | Integration test CONN-02 registers on WP_Connector_Registry and asserts gated |
| CONN-03 | 11-01-PLAN.md | Continue gating standard connectors_*_api_key with no regression | SATISFIED | Regex fallback always runs (union); unit test conn03 green |
| CONN-04 | 11-01-PLAN.md | Regex fallback when wp_get_connectors absent | SATISFIED | Tier 2 unconditional; unit test conn04/DR-1 green |
| CONN-05 | 11-01-PLAN.md | Do not over-gate benign settings writes | SATISFIED | Unit test conn05 asserts blogname/siteurl/timezone_string not matched as connector credentials |
| CONN-06 | 11-01-PLAN.md | Document two-tier matcher; cite verified WP core source in commit | SATISFIED | connectors-api-reference.md "Two-tier matcher" section; developer-reference.md updated; commit dba8672 and 8970c23 both cite wp-includes/connectors.php Akismet block (WordPress/wordpress-develop trunk, 2026-06-15) |

No orphaned requirements: CONN-01 through CONN-06 are the only Phase 11 requirements in REQUIREMENTS.md, and all are covered by plan 11-01.

### Anti-Patterns Found

No anti-patterns detected. Grep for TODO/FIXME/XXX/HACK/PLACEHOLDER across the three modified/created files produced no output. No stub implementations, empty handlers, or placeholder returns found.

### Human Verification Required

#### 1. Integration test pass on WP 7.0 lane

**Test:** Run `composer test:integration` in a WordPress 7.0+ environment where `wp_get_connectors()` is populated at `init@15`.
**Expected:** `test_conn01_wordpress_api_key_gated_via_registry_tier`, `test_conn01_match_request_identifies_connectors_update_credentials`, and `test_conn02_custom_api_key_connector_is_auto_gated` all pass; tests skip cleanly in older WP environments.
**Why human:** Integration tests require a live WordPress 7.0 database environment. The current CI and local test suite runs unit tests only. No WP 7.0 environment is wired in the sandbox for automated verification.

### Commit Verification

All three documented commits exist and are valid:

- `b1ad0bb` — `test(11-01): RED — failing unit tests for two-tier connector matcher`
- `8970c23` — `feat(11-01): GREEN — two-tier connector matcher with class-property cache` (cites wp-includes/connectors.php verified 2026-06-15)
- `dba8672` — `feat(11-01): integration tests + docs for two-tier connector matcher (CONN-01/02/06)` (cites wordpress-develop trunk sources for connectors.php, default-filters.php, class-wp-connector-registry.php)

### Test Suite Results

- `composer test:unit`: 793 tests, 2268 assertions — OK
- `composer analyse` (PHPStan L6): No errors

### Constraints Verification

All plan constraints were honored:

- Cache is a class property (`private static ?array $connector_setting_names_cache = null`) NOT a function-local static
- null (not built) and [] (built, empty) are distinct — the guard `null === self::$connector_setting_names_cache` at line 1082 correctly distinguishes them
- `function_exists('wp_get_connectors')` guard retained as legitimate runtime integration check
- Scope is `method === 'api_key'` only with `isset()` guards on both `method` and `setting_name`
- Union matcher: Tier 2 regex always runs (not an early-exit after Tier 1)
- Rule definition (~line 495) is unchanged
- `request_contains_connector_api_key()` outer loop (lines 1040–1048) is unchanged
- No audit-hook signature changes
- Re-scoping comment present in PHPDoc and in production code comment

---

_Verified: 2026-06-15_
_Verifier: Claude (gsd-verifier)_
