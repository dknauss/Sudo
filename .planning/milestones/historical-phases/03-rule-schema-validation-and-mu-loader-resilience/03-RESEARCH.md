# Phase 3 Research: Rule Schema Validation and MU Loader Resilience

Date: 2026-03-04  
Phase: 03-rule-schema-validation-and-mu-loader-resilience  
Goal: harden filtered rule ingestion (`wp_sudo_gated_actions`) and remove non-canonical path fragility in MU loader startup.

## Current State (Code-Verified)

### Action registry filter handling

- `Action_Registry::get_rules()` currently assigns filter output directly:
  - `self::$cached_rules = apply_filters( 'wp_sudo_gated_actions', self::rules() );`
- No shape/type validation is performed before `Gate::match_request()` consumes rules.
- Existing safety (`Gate::safe_preg_match()`) only guards invalid REST regex warnings. It does **not** guard broader invalid array shapes/types.

### Runtime matcher assumptions

- `Gate::matches_admin()` assumes `$rule['admin']` is array-like and accesses offsets (`pagenow`, `actions`, `method`).
- `Gate::matches_ajax()` assumes `$rule['ajax']` contains an `actions` list.
- `Gate::matches_rest()` assumes `$rule['rest']` has `route` and `methods` with expected types.
- Malformed filtered rules can produce runtime warnings/errors or silently disable matching.

### MU loader assumptions

- `mu-plugin/wp-sudo-loader.php` hardcodes plugin basename `'wp-sudo/wp-sudo.php'` for active-plugin checks.
- Loader also hardcodes canonical plugin path for bootstrap:
  - `WP_CONTENT_DIR . '/plugins/wp-sudo/'`.
- In non-canonical directory layouts (renamed plugin folder), loader can fail inertly with no explicit operator signal.

### Existing test coverage

- `tests/Unit/ActionRegistryTest.php` and `tests/Integration/ActionRegistryTest.php` cover valid filter extensions/removals.
- No tests currently enforce behavior for malformed filter output.
- No dedicated unit tests for MU loader basename/path fallback logic.

## Design Options Considered

### Rule-schema hardening

1. **Validate at Gate matcher only**
   - Pros: localized changes.
   - Cons: duplicated checks across `matches_admin/ajax/rest`; cache stores unsafe data.
   - Verdict: rejected.

2. **Normalize in `Action_Registry::get_rules()` (recommended)**
   - Pros: single ingress hardening point; cache stores safe normalized rules; Gate remains simple.
   - Cons: adds normalization code in registry.
   - Verdict: recommended.

3. **Throw exceptions on invalid custom rule**
   - Pros: explicit for developers.
   - Cons: can break production request flow; contradicts “drop invalid rules silently”.
   - Verdict: rejected for runtime path.

### MU loader resilience

1. **Keep hardcoded basename/path**
   - Pros: no changes.
   - Cons: fragility remains.
   - Verdict: rejected.

2. **Dynamic basename/path resolver with fallback chain (recommended)**
   - Candidate basename order:
     1. `WP_SUDO_PLUGIN_BASENAME` when already defined.
     2. basename derived from loader directory (`dirname( __DIR__ ) . '/wp-sudo.php'`).
     3. canonical fallback `'wp-sudo/wp-sudo.php'`.
     4. final scan fallback from active plugin lists ending in `/wp-sudo.php`.
   - Path resolution falls back to derived plugin directory before canonical path.
   - Verdict: recommended.

3. **Directory scan of all plugin files each request**
   - Pros: very robust.
   - Cons: unnecessary I/O overhead on every load.
   - Verdict: rejected.

## Recommended Implementation Shape

### A. Rule normalization layer

- Add a normalization pipeline in `Action_Registry`:
  - accepts filtered output as mixed;
  - falls back safely if output is non-array;
  - validates each rule and drops invalid entries;
  - preserves valid built-ins and valid custom rules;
  - ensures each retained rule has required scalar metadata and at least one valid surface.
- Keep cache behavior unchanged (`$cached_rules` stores normalized output once per request).
- Optional lightweight diagnostics for dropped rules (non-fatal).

### B. MU loader resolver

- Introduce resolver helpers (prefer pure static methods for unit testability).
- Use resolver in `mu-plugin/wp-sudo-loader.php` for:
  - active-plugin detection (site + network),
  - plugin directory resolution,
  - safe file include decision.
- On unresolved path, remain inert and emit diagnosable admin-facing signal.

## Test Impact Map

### Unit

- `tests/Unit/ActionRegistryTest.php`
  - malformed filter output fallback behavior,
  - invalid rule drop behavior,
  - mixed valid/invalid rule set behavior.
- `tests/Unit/GateTest.php`
  - matcher continues operating when malformed custom rules are present (post-normalization).
- New MU resolver unit test file (if resolver class introduced).

### Integration

- `tests/Integration/ActionRegistryTest.php`
  - malformed custom rule does not break built-in matching,
  - mixed valid/invalid custom rule set still matches valid custom rule.

### Documentation

- Update developer/operator docs for:
  - rule validation behavior (invalid custom rules dropped),
  - MU loader fallback behavior and diagnostics expectations.

## Risks and Mitigations

1. **Risk:** Over-strict normalization drops legitimate custom rules.
   - **Mitigation:** allow scalar-or-list forms where existing matcher already accepts them; add integration test with real custom valid rule.

2. **Risk:** Basename/path fallback logic becomes difficult to reason about.
   - **Mitigation:** isolate resolver into test-covered pure helpers; explicit fallback order in code comments.

3. **Risk:** Silent failures remain opaque to operators.
   - **Mitigation:** add explicit diagnosable signal for unresolved MU loader state.

## Verification Gates (Phase-level)

```bash
composer test:unit -- --do-not-cache-result
composer test:integration -- --do-not-cache-result
WP_MULTISITE=1 composer test:integration -- --do-not-cache-result
composer analyse:phpstan
composer analyse:psalm
composer lint
```

## Conclusion

Phase 3 should run as three plans:

1. `03-01` rule-schema normalization and matcher hardening via TDD.
2. `03-02` MU loader basename/path fallback resilience with unit coverage.
3. `03-03` integration/docs/final gate verification and cleanup.

This keeps the work focused, measurable, and aligned with roadmap priorities.
