# Phase 4 Research: WPGraphQL Persisted Query Strategy and WSAL Sensor

Date: 2026-03-04  
Phase: 04-wpgraphql-persisted-query-and-wsal-sensor  
Goal: close persisted-query visibility gap in WPGraphQL Limited mode and ship first-party audit visibility bridge for WP Activity Log (WSAL).

## Current State (Code-Verified)

### WPGraphQL mutation detection

- `Gate::check_wpgraphql()` currently classifies mutations via:
  - `str_contains( $body, 'mutation' )`.
- This correctly handles inline GraphQL operation bodies.
- Persisted query requests can contain only hash/ID metadata and no mutation text.
- Existing docs already call out this limitation and recommend Disabled policy when mutation gating must be strict.
- Existing bypass extension point:
  - `wp_sudo_wpgraphql_bypass` (fires in Limited mode before mutation heuristic).

### Existing test coverage

- `tests/Integration/WpGraphQLGatingTest.php` covers policy modes, session behavior, and bypass filter contract.
- No explicit persisted-query classification tests currently exist.

### Logging integration surface

- WP Sudo emits 9 audit hooks across lifecycle/policy decisions.
- Docs recommend WP Activity Log and Stream, but there is no first-party WSAL sensor implementation in repository.
- Existing hooks provide sufficient event source data for adapter mapping:
  - activation/deactivation, reauth failed/lockout, gated/blocked/allowed/replayed, capability tamper.

## Design Options Considered

### Persisted-query handling

1. **Leave behavior docs-only**
   - Pros: zero code risk.
   - Cons: no implementation path for environments requiring Limited-mode persisted-query support.
   - Verdict: insufficient for Phase 4.

2. **Add classification filter before heuristic (recommended)**
   - Introduce filter that can classify request body as mutation/query/unknown for persisted-query workflows.
   - Keep fallback heuristic for non-persisted requests.
   - Preserve fail-safe behavior when unclassified.
   - Verdict: recommended.

3. **Parse full GraphQL AST in core**
   - Pros: strongest direct classification for inline bodies.
   - Cons: out-of-scope complexity; does not solve hash-only persisted requests without external lookup.
   - Verdict: rejected.

### WSAL integration packaging

1. **Core plugin hard dependency on WSAL**
   - Pros: immediate integration.
   - Cons: unacceptable coupling and plugin dependency assumptions.
   - Verdict: rejected.

2. **Optional bridge/sensor module (recommended)**
   - Implement optional bridge under `bridges/` that registers listeners only when WSAL APIs/classes are present.
   - Keep core plugin dependency-free.
   - Verdict: recommended.

3. **Docs-only recommendation**
   - Pros: no maintenance burden.
   - Cons: roadmap objective not delivered.
   - Verdict: insufficient.

## Recommended Implementation Shape

### A. Persisted-query strategy in Gate

- Add a new filter-based classifier in `Gate::check_wpgraphql()`:
  - receives raw body and default classification result.
  - can return explicit mutation classification for persisted-query request envelopes.
- Keep current `wp_sudo_wpgraphql_bypass` semantics.
- Keep secure fallback behavior documented and test-covered.
- Update developer docs with concrete bridge example for persisted-query providers.

### B. WSAL sensor bridge

- Add optional bridge file under `bridges/`:
  - hooks WP Sudo audit actions,
  - maps to WSAL event writer APIs only when WSAL is active,
  - no-op when WSAL unavailable.
- Include mapping table in docs:
  - `wp_sudo_action_gated`, `wp_sudo_action_blocked`, `wp_sudo_action_allowed`, etc.

### C. Stream alignment note

- In this phase, capture Stream parity plan and mapping compatibility notes.
- Stream implementation can follow as subsequent plan/phase if not completed in same sprint.

## Test Impact Map

### Unit

- `tests/Unit/GateTest.php`:
  - persisted-query classifier filter precedence and fallback behavior.
  - no regression to existing Limited/Disabled/Unrestricted behavior.
- New WSAL bridge unit test file:
  - hook registration and event mapping behavior with WSAL present/absent.

### Integration

- `tests/Integration/WpGraphQLGatingTest.php`:
  - persisted-query body scenarios classified via new filter.
  - ensure mutation gating decisions remain deterministic.

### Documentation

- `docs/developer-reference.md`:
  - persisted-query strategy and classifier filter contract.
  - WSAL bridge install/use guidance.
- `docs/security-model.md` and `tests/MANUAL-TESTING.md`:
  - updated guidance and verification path.

## Risks and Mitigations

1. **Risk:** classifier filter misuse opens bypass path.
   - **Mitigation:** define strict return contract and fallback behavior; add negative-path tests.

2. **Risk:** WSAL APIs differ across versions.
   - **Mitigation:** keep adapter thin, capability-checked, and version-guarded; document supported range.

3. **Risk:** hook mapping ambiguity for operators.
   - **Mitigation:** publish explicit event mapping table with example records.

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

Phase 4 should execute as three plans:

1. `04-01` persisted-query classification contract and Gate/test updates.
2. `04-02` WSAL sensor bridge implementation with unit coverage.
3. `04-03` integration/docs/manual verification and full quality gates.

This preserves secure defaults while enabling enterprise audit visibility and clearer GraphQL policy operation.
