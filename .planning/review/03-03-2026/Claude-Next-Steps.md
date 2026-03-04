## Assessment Review: Codex vs Gemini on WP Sudo Hardening

I verified all technical claims against the codebase. Both assessments are grounded in real code — no fabrication. Here's where I agree, disagree, and where I'd change priorities.

### Where both are right (and I agree)

**1. Request Stash data minimization is the top priority.** `sanitize_params()` at `class-request-stash.php:205` literally returns `$params` unchanged with a comment explaining why. The rationale ("they never leave the server") is incomplete — transients stored in `wp_options` are accessible to any code with database read access, any backup system, any log that dumps options, and any object cache backend. Passwords sitting in serialized transients is a real exposure vector.

**2. `sleep()` in the auth failure path is an availability problem.** `class-sudo-session.php:719` blocks a PHP-FPM worker for up to 5 seconds. On a site with limited workers, an authenticated attacker can exhaust the pool by triggering 5 concurrent failed auth attempts. Both assessments correctly flag this.

**3. Upload action coverage is genuinely missing.** I confirmed: `upload-plugin` and `upload-theme` have no rules in `Action_Registry`. The `install-plugin`/`install-theme` rules cover the WordPress.org directory installer but not the ZIP upload path at `update.php?action=upload-plugin`. This is a real gap — a compromised session can upload arbitrary plugin ZIPs without sudo challenge.

**4. Modal challenge rewrite should stay deferred.** Both converge on this. Correct — it's a design-heavy UX change that doesn't improve security posture.

### Where I disagree with prioritization

**1. Rule-schema validation is not P1.** Codex ranks this first. I'd rank it P2 at most.

The `wp_sudo_gated_actions` filter is a developer extension point. Malformed rules from this filter would come from custom code written by a site developer — not from an attacker (the filter requires PHP file access, at which point you already own the site). The existing `safe_preg_match()` guard in Gate already prevents regex crashes. The remaining risk is a malformed rule silently failing to match, which is a reliability issue, not a security one. Worth doing, but after stash redaction, sleep removal, and upload coverage.

**2. Gemini's canary optimization (P3) is even lower than P3.** `enforce_editor_unfiltered_html()` at `class-plugin.php:357` does three things per request: `is_multisite()` check (cached, essentially free), `get_role('editor')` (reads from `$wp_roles` global, no DB query after first load), and checks `$editor->capabilities['unfiltered_html']` (array key lookup). This is negligible overhead. Moving it to `admin_init` or `set_user_role` would create a window where the tamper canary doesn't fire on frontend requests — which is exactly where it's meant to run as a detection mechanism. The optimization saves microseconds and introduces a detection gap. Skip it entirely.

**3. WPGraphQL persisted queries are P3, not P2.** The current `str_contains($body, 'mutation')` heuristic at `class-gate.php:919` is simple but effective for the standard WPGraphQL request format. Persisted queries are a niche WPGraphQL feature used almost exclusively in headless builds. The existing "Disabled" default policy means most sites don't expose GraphQL at all. This matters, but only for sites that deliberately enable Limited mode for headless use — and those sites are likely sophisticated enough to use the Unrestricted policy with their own controls.

### Where Codex is clearly better than Gemini

**1. Sequencing against the existing roadmap.** Codex correctly identifies that Phase 5 work (`05-02` manual WP 7.0 verification) should finish first, then hardening. Gemini wants to jump straight to a standalone sprint. Codex is right — the WP 7.0 GA date (April 9) is a hard external deadline. Complete `05-02` first, then use the window before GA to run hardening.

**2. Stash flooding is an insider threat, not an anonymous one.** Gemini initially framed stash DoS as an internet-facing attack. Codex correctly narrows it: the stash write path only fires for logged-in users hitting admin challenge flows. The DoS is authenticated/insider abuse. Gemini's revised document acknowledges this correction.

**3. Rate-limit implementation.** Gemini prescribes a specific timestamp-array model. Codex keeps it outcome-driven: non-blocking + race-resilient, without locking the storage pattern. Codex is right — the implementation should be chosen during TDD, not pre-committed in a planning document.

**4. Logging sequence.** Codex explicitly orders WSAL first, Stream second, after core hardening. This makes sense — WSAL is the dominant enterprise audit plugin for WordPress, and the existing audit hooks (`wp_sudo_action_gated`, etc.) map naturally to WSAL's sensor model. Stream is a worthy second target but has a smaller footprint in the enterprise segment Sudo is aimed at.

### Where Gemini adds value Codex missed

**1. REST early-exit optimization.** Gemini's observation about adding a lightweight namespace/method pre-filter before the rule loop (Gemini 1.2) has merit that Codex didn't address. Currently `match_request()` iterates all 29 rules for every REST request. At 29 rules this is trivial, but the architecture note is valid: a fast HTTP method check (`GET` requests can never match a `POST`-only rule) could skip the loop entirely for read-only REST traffic. This is a P4 "nice to have" — worth noting for when the rule count grows, not worth doing now.

**2. Per-user stash cap.** Both mention this, but Gemini is more specific about the mechanism (cap at 5, evict oldest). This should be part of the stash hardening workstream. It's a simple addition to `save()` — query existing transients for the user, delete oldest if count exceeds cap.

### My recommended priority order

| Priority | Workstream | Why |
|----------|-----------|-----|
| **P1** | Request Stash redaction + per-user cap | Real data exposure + bounded growth |
| **P1** | Upload action coverage (`upload-plugin`, `upload-theme`) | Real gating gap — quick fix |
| **P1** | Replace `sleep()` with non-blocking throttle | Real availability risk |
| **P2** | Rule-schema validation | Reliability, not security; no crash risk thanks to `safe_preg_match()` |
| **P2** | MU loader path resilience | Only affects non-standard installs |
| **P3** | WPGraphQL persisted-query strategy | Niche use case with safe default |
| **P3** | WSAL sensor extension | Value-add, not hardening |
| **Skip** | Canary optimization | Negligible overhead, introduces detection gap |

### Stale doc reference — quick fix

Both assessments flag the `wp_sudo_wpgraphql_route` filter still referenced in `readme.md` and `readme.txt` despite being removed from code. Confirmed stale. This should be cleaned up immediately — it's a 30-second fix and prevents developer confusion.

### Net verdict

Codex's assessment is the stronger of the two — more precise on threat boundaries, better aligned with existing roadmap, and correctly avoids premature implementation commitments. Gemini's initial framing had valuable observations but overstated some risks (anonymous stash DoS, canary overhead) that Gemini later self-corrected after reading Codex. The unified plan in Gemini's section 3 is solid and largely mirrors Codex's priorities.

The one adjustment I'd make to both: move upload-action coverage up to P1 alongside stash redaction and sleep removal. It's a small change (2 rules + tests) with outsized impact — ZIP upload is arguably the most dangerous un-gated action remaining.