# Proposed Next Steps: WP Sudo (Claude Assessment)

Date: 2026-03-04
Scope: Review of Codex and Gemini assessments, verified against codebase, with priority recommendations.

## Executive Summary

Both assessments are grounded in real code — all technical claims verified. Codex's assessment is the stronger of the two: more precise on threat boundaries, better aligned with the existing roadmap, and correctly avoids premature implementation commitments. Gemini's initial framing had valuable observations but overstated some risks that Gemini later self-corrected after reading Codex.

The hardening sprint should focus on three P1 items (stash redaction, upload-action coverage, sleep removal) before moving to reliability improvements (rule validation, MU loader) and niche surface hardening (GraphQL persisted queries).

## Claim Verification Summary

All technical claims from both assessments were verified against production code:

| # | Claim | Verified | Location |
|---|-------|----------|----------|
| 1 | Raw `$_POST` stored with passwords | TRUE | `class-request-stash.php:205-211` — `sanitize_params()` returns verbatim |
| 2 | `sleep()` in auth failure path | TRUE | `class-sudo-session.php:719` — 2s at attempt 4, 5s at attempt 5 |
| 3 | No rule schema validation on filter output | TRUE | `class-action-registry.php:642` — `apply_filters()` result used directly |
| 4 | `upload-plugin`/`upload-theme` not gated | TRUE | No rules in `Action_Registry` for ZIP upload paths |
| 5 | Hardcoded plugin slug in MU loader | TRUE | `mu-plugin/wp-sudo-loader.php:22` — `'wp-sudo/wp-sudo.php'` |
| 6 | GraphQL mutation detection is substring match | TRUE | `class-gate.php:919` — `str_contains($body, 'mutation')` |
| 7 | `enforce_editor_unfiltered_html()` at `init` priority 1 | TRUE | `class-plugin.php:114` |
| 8 | Stale `wp_sudo_wpgraphql_route` in readme | TRUE | Was in `readme.md:311` and `readme.txt:227` — **now fixed** |
| 9 | REST match loops all rules with early exit on first match | TRUE | `class-gate.php:682-730` |

## Where Both Assessments Are Right

### 1. Request Stash data minimization is the top priority

`sanitize_params()` returns `$params` unchanged. The rationale ("they never leave the server") is incomplete — transients in `wp_options` are accessible to any code with database read access, any backup system, any log that dumps options, and any object cache backend. Passwords in serialized transients is a real exposure vector.

### 2. `sleep()` in auth failure path is an availability problem

A blocked PHP-FPM worker for up to 5 seconds. On a site with limited workers, an authenticated attacker can exhaust the pool by triggering concurrent failed auth attempts.

### 3. Upload action coverage is genuinely missing

`upload-plugin` and `upload-theme` have no rules in `Action_Registry`. The `install-plugin`/`install-theme` rules cover the WordPress.org directory installer but not the ZIP upload path at `update.php?action=upload-plugin`. A compromised session can upload arbitrary plugin ZIPs without sudo challenge.

### 4. Modal challenge rewrite should stay deferred

Design-heavy UX change that does not improve security posture. Correct deferral.

## Where I Disagree With Prioritization

### 1. Rule-schema validation is not P1 (Codex ranks it first)

The `wp_sudo_gated_actions` filter is a developer extension point. Malformed rules would come from custom code written by a site developer — not from an attacker (the filter requires PHP file access, at which point you already own the site). The existing `safe_preg_match()` guard in Gate prevents regex crashes. The remaining risk is a malformed rule silently failing to match — a reliability issue, not a security one. Worth doing, but after stash redaction, sleep removal, and upload coverage.

### 2. Gemini's canary optimization should be skipped entirely

`enforce_editor_unfiltered_html()` does three things per request: `is_multisite()` check (cached, free), `get_role('editor')` (reads from `$wp_roles` global, no DB query after first load), and checks `$editor->capabilities['unfiltered_html']` (array key lookup). Negligible overhead. Moving it to `admin_init` or `set_user_role` would create a window where the tamper canary does not fire on frontend requests — which is exactly where it is meant to run as a detection mechanism. The optimization saves microseconds and introduces a detection gap.

### 3. WPGraphQL persisted queries are P3, not P2

The current `str_contains()` heuristic is simple but effective for the standard WPGraphQL request format. Persisted queries are a niche feature used almost exclusively in headless builds. The existing "Disabled" default policy means most sites do not expose GraphQL at all. This matters only for sites that deliberately enable Limited mode — and those sites are likely sophisticated enough to use the Unrestricted policy with their own controls.

## Where Codex Is Better Than Gemini

### 1. Roadmap sequencing

Codex correctly identifies that Phase 5 work (`05-02` manual WP 7.0 verification) should finish first, then hardening. Gemini wants to jump straight to a standalone sprint. The WP 7.0 GA date (April 9) is a hard external deadline. Complete `05-02` first.

### 2. Stash flooding threat model

Gemini initially framed stash DoS as an internet-facing attack. Codex correctly narrows it: the stash write path only fires for logged-in users hitting admin challenge flows. The DoS is authenticated/insider abuse.

### 3. Rate-limit implementation

Gemini prescribes a specific timestamp-array model. Codex keeps it outcome-driven: non-blocking + race-resilient, without locking the storage pattern. The implementation should be chosen during TDD, not pre-committed in a planning document.

### 4. Logging sequence

Codex explicitly orders WSAL first, Stream second, after core hardening. WSAL is the dominant enterprise audit plugin; existing audit hooks map naturally to WSAL's sensor model.

## Where Gemini Adds Value Codex Missed

### 1. Per-user stash cap

Both mention stash growth, but Gemini is more specific about the mechanism (cap at 5, evict oldest). This should be part of the stash hardening workstream as a bounded-growth guarantee.

### 2. REST early-exit optimization (future note)

Adding a lightweight HTTP method pre-filter before the rule loop has merit. At 29 rules this is trivial overhead, but worth noting for when the rule count grows. P4 at most.

## Recommended Priority Order

| Priority | Workstream | Type | Rationale |
|----------|-----------|------|-----------|
| **P1** | Request Stash redaction + per-user cap | Security | Real data exposure; passwords in transients |
| **P1** | Upload action coverage (`upload-plugin`, `upload-theme`) | Security | Real gating gap; quick fix (2 rules + tests) |
| **P1** | Replace `sleep()` with non-blocking throttle | Availability | PHP-FPM exhaustion under concurrent failures |
| **P2** | Rule-schema validation for `wp_sudo_gated_actions` | Reliability | Malformed filter output; not a crash risk thanks to `safe_preg_match()` |
| **P2** | MU loader path resilience | Reliability | Only affects non-standard installs |
| **P3** | WPGraphQL persisted-query strategy | Security | Niche use case; safe default (Disabled) |
| **P3** | WSAL sensor extension | Observability | Value-add, not hardening |
| **Skip** | Canary optimization (`enforce_editor_unfiltered_html`) | Performance | Negligible overhead; introduces detection gap |

## Recommended Delivery Sequence

1. **Now**: Complete Phase 5 `05-02` manual WP 7.0 verification.
2. **Sprint A** (Security core): Stash redaction + per-user cap, upload-action coverage.
3. **Sprint B** (Auth resilience): Non-blocking rate limiting.
4. **Sprint C** (Reliability): Rule-schema validation, MU loader hardening.
5. **On/after April 9, 2026**: Phase 5 `05-03` "Tested up to: 7.0" readme bump.
6. **Sprint D** (Surface + Observability): WPGraphQL persisted-query strategy, WSAL sensor.

## Explicit Deferrals (Do Not Pull Forward)

1. Modal challenge rewrite — design-heavy; no security improvement.
2. Per-session/device sudo isolation — valuable but larger architectural change.
3. Canary hook relocation — negligible gain, introduces detection window.
4. Broad REST performance rewrites — no measured bottleneck at current rule count.

## Net Recommendation

Focus the hardening sprint on the three items that represent real security or availability risk today: stash data exposure, ungated upload paths, and blocking sleep in auth failures. Follow with reliability improvements (rule validation, MU loader), then niche surface hardening and observability. This yields the highest risk reduction per engineering cycle.
