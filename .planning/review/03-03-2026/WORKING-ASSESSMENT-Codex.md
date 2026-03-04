# WP Sudo Working Assessment and SWOT

Date: 2026-03-04
Scope: Full architecture/security/code-quality assessment of the current `main` codebase, with roadmap and planning alignment.

## Executive Summary

WP Sudo is a strong, pragmatic "last-mile" reauthentication control for high-impact WordPress actions. It is effective against a key real-world failure mode: authenticated session compromise followed by destructive admin operations. The core design is coherent, and implementation quality is above average for a WordPress plugin.

The highest remaining risks are not obvious auth bypasses. They are extension fragility, data-exposure tradeoffs in request stashing, and operational/performance limits under edge environments. These should be prioritized ahead of lower-impact feature expansion.

## Architecture Summary

1. Bootstrap and composition:
`wp-sudo.php` boots the plugin and delegates to `Plugin::init()` in `includes/class-plugin.php`.
2. Core enforcement:
`Gate` (`includes/class-gate.php`) is the central interceptor for admin, AJAX, REST, WPGraphQL, CLI, Cron, and XML-RPC policy paths.
3. Action model:
`Action_Registry` (`includes/class-action-registry.php`) defines data-driven gated rules and supports filter-based extension.
4. Session model:
`Sudo_Session` (`includes/class-sudo-session.php`) manages token-bound sudo sessions, grace window, lockout, and 2FA pending state.
5. Interactive flow:
`Challenge` + `Request_Stash` (`includes/class-challenge.php`, `includes/class-request-stash.php`) implement stash-challenge-replay.
6. Hardening option:
MU shim/loader (`mu-plugin/wp-sudo-gate.php`, `mu-plugin/wp-sudo-loader.php`) enables earlier gate registration.

## Strengths

1. Strong post-compromise containment model for privileged operations.
2. Broad surface coverage with explicit policy tiers (`disabled`, `limited`, `unrestricted`).
3. Good session binding and 2FA browser-binding primitives.
4. Solid nonce/capability checks in sensitive handlers.
5. High test maturity and good quality gates (unit, integration, static analysis, lint).
6. Clear threat model documentation and explicit non-goals.

## Weaknesses and Tradeoffs

1. Hook-based by design, therefore cannot protect direct DB/filesystem bypasses.
2. Large central classes (`Gate`, `Admin`) increase change risk and cognitive load.
3. Extensibility via raw filtered rule arrays increases runtime fragility.
4. Some defensive controls prioritize simplicity over operational scalability.
5. Headless/WPGraphQL behavior is secure-by-default but can be surprising without explicit deployment tuning.

## Key Findings (Prioritized)

1. P2 Security and exposure:
Request stash stores raw request payloads, including potentially sensitive fields (`includes/class-request-stash.php`).
2. P2 Reliability and security hardening:
`wp_sudo_gated_actions` output is not schema-validated before gate use (`includes/class-action-registry.php` and `includes/class-gate.php`).
3. P2 Security boundary caveat:
WPGraphQL mutation detection in Limited mode is heuristic and does not cover persisted-query mutation pathways (documented in `docs/security-model.md`).
4. P2 Operational fragility:
MU loader assumes canonical plugin slug/path (`mu-plugin/wp-sudo-loader.php`).
5. P3 Availability under abuse:
Blocking `sleep()` in failed auth path can reduce PHP worker throughput (`includes/class-sudo-session.php`).
6. P3 Maintainability:
Per-app-password policy overrides can accumulate stale UUID mappings over time (`includes/class-admin.php`).
7. P4 Documentation consistency:
Readme still contains stale reference to removed `wp_sudo_wpgraphql_route` filter (`readme.md` vs `CHANGELOG.md`).

## Quality and Test Signal

Current local run results in this assessment pass:

1. `composer test:unit`: pass, 428 tests / 1043 assertions.
2. `composer test:integration -- --do-not-cache-result`: pass (single-site), 121 tests / 343 assertions.
3. `WP_MULTISITE=1 composer test:integration -- --do-not-cache-result`: pass (multisite), 121 tests / 349 assertions.
4. `composer analyse:phpstan`: pass.
5. `psalm --no-cache`: pass (no errors).
6. `composer lint`: pass (PHPCS JS/CSS deprecation warnings only).

## SWOT Analysis

### Strengths

1. Clear and defensible security objective: reauthentication at action execution time.
2. Comprehensive surface interception for practical WordPress attack paths.
3. Good engineering discipline with meaningful automated coverage.
4. Strong extension points (rules, hooks, 2FA bridges) without hard dependency lock-in.

### Weaknesses

1. Runtime trust in third-party filtered rules is too permissive.
2. Request stash data minimization is not implemented.
3. Core enforcement logic is concentrated in one large class.
4. Some behavior correctness depends on environment quality (cache/proxy/path conventions).

### Opportunities

1. Add strict rule-schema validation and fail-closed normalization for filtered rules.
2. Implement configurable secret-field redaction for stash payloads.
3. Add optional persisted-query-aware GraphQL mutation detection strategy.
4. Add WSAL sensor extension to improve enterprise audit visibility (already on roadmap).
5. Evolve to per-session/device sudo isolation to reduce cross-device coupling.

### Threats

1. Misconfigured cache/proxy/CDN environments can induce fail-open behavior at edges.
2. Third-party plugin incompatibilities can create bypass or stability regressions.
3. New WordPress/GraphQL execution paths may outpace current matching assumptions.
4. Under heavy abuse, blocking delays can become an availability risk.

## Roadmap and Planning Alignment

Reviewed against:

1. `ROADMAP.md`
2. `tests/testing-recommendations.md`
3. `docs/security-model.md`
4. `docs/developer-reference.md`
5. `docs/abilities-api-assessment.md`

### What the roadmap already captures well

1. Exit-path testing gap and phased quality expansion.
2. Environment diversity testing.
3. Mutation testing as later-stage quality work.
4. WSAL sensor extension and operational visibility items.
5. Future design work for per-session isolation and modal challenge.

### Important items missing or under-prioritized

1. Rule-schema validation hardening for `wp_sudo_gated_actions` input.
2. Request stash data-minimization/redaction policy and implementation.
3. GraphQL persisted-query mutation detection strategy in Limited mode.
4. MU loader path/slug hardening for nonstandard plugin layouts.
5. Stale app-password policy cleanup lifecycle.

### Recommended roadmap changes

1. Add a new near-term "Security Hardening Sprint" before major UX expansion:
rule validation, stash redaction, GraphQL persisted-query handling, MU loader resilience.
2. Keep WSAL sensor work in near-term medium priority after core hardening.
3. Keep modal challenge and per-session isolation as design-track, not immediate build-track.
4. Add docs consistency checks as release gate to prevent readme/changelog drift.

## Priority Recommendations

### Immediate priorities (next 2-4 weeks)

1. Implement and test strict rule validation/normalization in `Action_Registry::get_rules()`.
2. Implement secret-key redaction for request stash with conservative defaults and filter override.
3. Update GraphQL policy docs and enforcement strategy for persisted queries, at minimum with explicit fail-safe guidance and tests.
4. Remove remaining stale docs references (`wp_sudo_wpgraphql_route` mention in readme).

### Near-term priorities (next 1-2 months)

1. Replace blocking `sleep()` strategy with non-blocking time-based throttling.
2. Add stale app-password policy GC path and associated tests.
3. Harden MU loader path detection to reduce deployment fragility.
4. Land WSAL sensor extension to improve observability and enterprise adoption.

### Later priorities (design-heavy)

1. Per-session/device sudo isolation via `WP_Session_Tokens` integration.
2. Modal challenge architecture (only after threat-model and browser/autofill behavior validation).
3. REST sudo grant endpoint design only if headless use-cases justify security complexity.

## Most Important Next Steps

1. Open and track a dedicated hardening epic with 5 workstreams:
rule validation, stash redaction, GraphQL persisted-query handling, non-blocking rate limit, loader resilience.
2. Ship rule validation and stash redaction first. These deliver the highest net security and reliability gain per unit effort.
3. Add release-gate checks for docs consistency and critical security-model claims.
4. Follow hardening with logging/visibility improvements (WSAL sensor), then larger architecture changes.

## Net Assessment

WP Sudo is already effective and defensible as a WordPress security layer for high-impact operations. The highest-value path now is not broad new feature scope. It is targeted hardening of extension boundaries, data minimization, and operational resilience, followed by observability improvements and then larger structural upgrades.

## Addendum: Gemini Delta (2026-03-04)

This section captures comparison findings against `WORKING-ASSESSMENT-Gemini.md` and merges valid deltas into the Codex plan.

### Gemini findings validated

1. Interactive interception fragility risk is real over time as WordPress and plugin UIs evolve (`Action_Registry` route/action matching model).
2. Request stash raw payload storage is a valid data-exposure concern.
3. Failed-attempt increment path has a TOCTOU race window under high concurrency and should be hardened.
4. `headers_sent()` token-cookie edge cases are operationally plausible in misbehaving plugin environments.

### Gemini findings corrected or narrowed

1. Request stash flooding was overstated for unauthenticated/AJAX/REST paths. Stash writes are on admin challenge flow; AJAX/REST blocked paths do not stash requests.
2. Modal challenge should not be treated as an immediate security fix. It remains high-value UX and architecture work with substantial complexity/risk and should stay on design track.
3. Some performance concerns are valid but contextual. Current rule volume is small; maintainability and hardening yield better immediate return than broad perf rewrites.

### Merged priority updates

Immediate hardening scope should include two additions:

1. Add TOCTOU-safe failed-attempt tracking and lockout enforcement (`Sudo_Session::record_failed_attempt` path).
2. Add explicit verification and tests for plugin/theme ZIP upload action coverage (`update.php?action=upload-plugin` and `update.php?action=upload-theme`) to confirm intended gating behavior and close any mismatch.

Revised top immediate sequence:

1. Rule-schema validation and fail-closed normalization for `wp_sudo_gated_actions`.
2. Request stash secret-field redaction/data minimization.
3. TOCTOU-safe rate-limit counter updates.
4. Upload-action gating verification and fix if needed.
5. Persisted-query WPGraphQL strategy + tests.
