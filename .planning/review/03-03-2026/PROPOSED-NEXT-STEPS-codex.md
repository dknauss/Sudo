# Proposed Next Steps: WP Sudo (Codex Unified Plan)

Date: 2026-03-04  
Scope: Immediate-to-near-term engineering priorities after review of codebase, `WORKING-ASSESSMENT-Codex.md`, and `PROPOSED-NEXT-STEPS-gemini.md`.

## Executive Direction

WP Sudo should run a focused hardening sprint before new UX or architecture expansion.  
Priority is to reduce real security/reliability risk at existing extension and replay boundaries, while preserving current behavior and test confidence.

## Top Priorities (In Order)

1. Rule-schema validation and fail-closed normalization for `wp_sudo_gated_actions`.
2. Request stash data minimization with replay-safe defaults.
3. Non-blocking, TOCTOU-safer failed-attempt rate limiting.
4. Explicit upload-action coverage verification and fix (`upload-plugin`, `upload-theme`).
5. WPGraphQL persisted-query mutation strategy and tests.
6. MU loader path/slug resilience.
7. Logging integrations (WSAL first, Stream next) after core hardening.

## Workstream 1: Rule Validation Hardening (P1)

Why now:
`Action_Registry::get_rules()` accepts filtered arrays without schema normalization. This is a reliability and security-hardening gap.

Implementation:
1. Add a normalizer/validator in `includes/class-action-registry.php`:
   - Require scalar `id`, `label`, `category`.
   - Allow only known surface keys (`admin`, `ajax`, `rest`) plus metadata.
   - Validate surface shape types (`pagenow`, `actions`, `method`, `route`, `methods`, optional callable `callback`).
   - Drop invalid rules and continue (fail closed per rule, not fatal globally).
2. Preserve current filter contract (`wp_sudo_gated_actions`) and cache behavior.
3. Add lightweight diagnostics hook for discarded rules (optional, non-fatal).

Tests:
1. Unit tests for valid/invalid/mixed filtered rule sets.
2. Unit tests for invalid type permutations (non-array surfaces, invalid route type, bad methods type).
3. Integration test ensuring plugin keeps operating when one custom rule is malformed.

Exit criteria:
1. Invalid filtered rules cannot crash matching paths.
2. Existing rule behavior is unchanged for valid inputs.
3. Unit + integration suites pass.

## Workstream 2: Request Stash Minimization (P1)

Why now:
`Request_Stash` currently stores raw request payloads; this is a data-minimization and exposure risk.

Implementation:
1. Add redaction policy in `includes/class-request-stash.php`:
   - Redact common secret keys by default (`password`, `pass`, `token`, `secret`, `api_key`, etc.).
   - Recursive matching, case-insensitive key handling.
2. Add filters:
   - Extend/override sensitive key list.
   - Per-key allowlist escape hatch for replay-critical cases.
3. Keep replay behavior deterministic:
   - If a required field is redacted and replay would break, fail safely with explicit user/admin error path (no silent corruption).
4. Add stash-volume guardrail:
   - Per-user stash cap (small fixed window), evict oldest first.

Tests:
1. Unit tests for recursive redaction and allowlist behavior.
2. Unit/integration tests for stash cap eviction.
3. Integration tests for GET and POST replay still working for built-in rules.

Exit criteria:
1. Sensitive fields are not stored verbatim by default.
2. Built-in replay flows remain intact.
3. Stash growth is bounded per user.

## Workstream 3: Rate-Limit Hardening (P1)

Why now:
Current failed-attempt path is read-modify-write integer meta with blocking `sleep()`.

Implementation:
1. Replace blocking delay with non-blocking time-based throttling metadata.
2. Move from simple counter to timestamp-window model (or equivalent) that narrows race susceptibility.
3. Keep lockout semantics predictable for UI and audit hooks.
4. Preserve hook contract (`wp_sudo_reauth_failed`, `wp_sudo_lockout`).

Tests:
1. Unit tests for threshold and lockout windows.
2. Integration tests for repeated failures and lockout expiration.
3. Update slow-test strategy to remove runtime coupling to `sleep()`.

Exit criteria:
1. No blocking sleeps in auth failure path.
2. Lockout still triggers correctly at configured threshold.
3. Existing external hooks still fire with expected args.

## Workstream 4: Upload Action Coverage (P1)

Why now:
Need explicit confirmation that ZIP upload installs are gated as intended.

Implementation:
1. Verify `update.php?action=upload-plugin` and `update.php?action=upload-theme` coverage in `includes/class-action-registry.php`.
2. If missing, add action mappings and any required callbacks.
3. Ensure Gate UI behavior remains coherent on plugin/theme install surfaces.

Tests:
1. Unit tests for matching upload action requests.
2. Integration/admin-path tests confirming challenge path on upload actions.

Exit criteria:
1. Upload-based plugin/theme install paths are explicitly gated.
2. Tests lock in regression protection.

## Workstream 5: WPGraphQL Persisted Queries (P2)

Why now:
Mutation detection in limited mode currently relies on body-text heuristics.

Implementation:
1. Define supported persisted-query detection model in docs.
2. Add optional resolver/filter hook so implementers can classify persisted operations.
3. Keep default behavior secure and explicit.

Tests:
1. Unit tests for bypass and mutation classification paths.
2. Integration test matrix for query/mutation/persisted-id permutations.

Exit criteria:
1. Persisted-query mutation behavior is documented and test-covered.
2. No regression for existing non-persisted GraphQL requests.

## Workstream 6: MU Loader Resilience (P2)

Why now:
Early-loading behavior depends on plugin path assumptions in nonstandard deployments.

Implementation:
1. Harden path detection in `mu-plugin/wp-sudo-loader.php`.
2. Add clearer failure logging/admin notice for unresolved plugin path.
3. Keep normal canonical installs unchanged.

Tests:
1. Unit tests for path resolution fallbacks.
2. Manual verification for canonical and noncanonical plugin directory layouts.

Exit criteria:
1. Early gate registration is reliable across supported layouts.
2. Failure modes are explicit and diagnosable.

## Workstream 7: Logging Roadmap (P2, After Hardening)

Why now:
Audit hooks exist; integration coverage with enterprise logging tools is next leverage point.

Implementation:
1. Ship WSAL sensor extension first (structured event mapping from existing hooks).
2. Add Stream adapter second to widen ecosystem compatibility.
3. Keep hooks as source of truth; adapters stay thin.

Tests:
1. Adapter unit tests for hook-to-event mapping.
2. Compatibility validation against current plugin versions.

Exit criteria:
1. Actionable logs for blocked/allowed/gated/replayed/lockout events in WSAL.
2. Stream parity plan ready (or implemented) immediately after WSAL.

## Explicit Deferrals (Do Not Pull Forward)

1. Modal challenge rewrite (design-heavy; not needed for immediate hardening).
2. Per-session/device sudo isolation redesign (valuable but larger architectural change).
3. Broad performance rewrites without measured bottleneck evidence.

## Suggested Delivery Sequence

1. Sprint A (Security core): Workstreams 1 + 2.
2. Sprint B (Auth resilience): Workstreams 3 + 4.
3. Sprint C (Surface hardening): Workstreams 5 + 6.
4. Sprint D (Observability): Workstream 7.

## Release Gates for Each Sprint

1. `composer test:unit`
2. `composer test:integration -- --do-not-cache-result`
3. `WP_MULTISITE=1 composer test:integration -- --do-not-cache-result`
4. `composer analyse:phpstan`
5. `psalm --no-cache`
6. `composer lint`

## Net Recommendation

Follow a hardening-first roadmap: secure extension boundaries, minimize sensitive transient data, and remove blocking/racy failure controls before expanding UX scope. This yields the highest security and reliability gain per engineering cycle while preserving WP Sudo’s current strengths.

## Update 2026-03-04: Roadmap-Aligned Progression (Append-Only)

This update keeps the prior plan intact and makes sequencing explicit against the existing roadmap/state documents.

### Current Roadmap Reality

1. Roadmap Phases 1-4 are complete.
2. Phase 5 is in progress.
3. Remaining planned Phase 5 execution item 1 is `05-02-PLAN.md` manual WP 7.0 test execution.
4. Remaining planned Phase 5 execution item 2 is `05-03-PLAN.md` time-gated “Tested up to: 7.0” update on/after April 9, 2026.

### Should Roadmap Priorities Change?

Yes, with an additive change only.

1. Keep existing Phase 5 priority and complete `05-02` first.
2. Insert a new **Phase 6: Hardening Sprint** immediately after `05-02`.
3. Execute `05-03` on/after April 9, 2026 (parallel with late Phase 6 work if needed).

Reason for change:

1. It preserves release-readiness commitments.
2. It uses the time-gated window to reduce real security/reliability risk.
3. It avoids replacing committed roadmap scope while still improving safety.

### Updated Progression and Intent

1. `Now`: Complete Phase 5 `05-02` manual verification.
2. `Next`: Run Phase 6 Workstreams 1-4 (rule validation, stash minimization, rate-limit hardening, upload action coverage).
3. `On/After April 9, 2026`: Execute Phase 5 `05-03` readme bump.
4. `Then`: Run Phase 6 Workstreams 5-7 (GraphQL persisted-query strategy, MU loader resilience, logging adapters).

### Codex vs Gemini: Not Yet Fully Aligned

1. Sequencing: Gemini presents hardening as immediate standalone sprint; Codex explicitly keeps remaining Phase 5 execution first, then hardening.
2. Canary optimization priority: Gemini keeps `enforce_editor_unfiltered_html()` optimization in active hardening scope; Codex treats it as lower priority than P1 hardening items.
3. Rate-limit implementation lock-in: Gemini specifies a timestamp-array model; Codex keeps implementation outcome-driven (non-blocking + race-resilient) without locking one storage pattern yet.
4. Logging progression: Codex explicitly sequences WSAL first and Stream second after hardening; Gemini’s current doc does not include this ordering.

### Codex vs Gemini: Aligned

1. Request stash data minimization is top priority.
2. Blocking `sleep()` and TOCTOU risk need hardening.
3. Rule-schema validation remains necessary.
4. Upload action coverage should be explicitly verified/fixed.
5. Modal rewrite is not immediate.
