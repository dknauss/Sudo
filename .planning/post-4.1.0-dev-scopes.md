# Post-4.1.0 Development Scopes (design-reviewed)

Three candidate work items scoped and run through the **Pre-Implementation Design
Review** process (per `CLAUDE.md`) after the 4.1.0 release. Each brief below has had
its design critiqued; the reviewer's objections are incorporated. **No
implementation has started** — these are planning artifacts, ready to execute at the
noted boundaries.

Recommended execution order: **1 → 2 (Tier 1) → 3 (Phase 1 only)**. Items 1 and 2 are
concrete and low-risk; item 3's later phases are gated behind a route inventory.

Source of truth is always the code (`includes/`), not these notes — re-verify line
references before editing, as they drift.

---

## Scope 1 — WordPress 7.0 Connectors GA-parity verification + tests

**Type:** verification + test/doc hardening (security-sensitive path). **Effort:** Low–Medium.
**Status:** design-reviewed; one production finding added (below). Tests + ROADMAP-fix
can proceed now; GA-verification + manual checks block on a network-capable environment.

### Problem
The shipped two-tier `connectors.update_credentials` matcher
(`ActionRegistry::is_connector_api_key_setting_name()`, `class-action-registry.php`)
was verified against WP 7.0 **trunk** at build time, not re-confirmed against
**released GA**; ROADMAP §74/§347 still mislabel it an "open gap" (it shipped in
4.0.0). A false negative — a registered connector secret whose `setting_name` the
matcher misses — is exactly the credential-replacement threat the security model
advertises.

### Approach (no matcher-logic change)
1. **Re-verify against released GA** `wp-includes/connectors.php`: route still
   `/wp/v2/settings` + same methods; every core default `api_key` connector's
   `setting_name` is gated by Tier 1; readback masking unchanged. Confirm every core
   `api_key` connector exposes its secret via `authentication.setting_name` (the
   assumption that makes Tier 1 complete). Record findings + date in
   `tests/MANUAL-TESTING.md`.
2. **Integration tests** (extend `tests/Integration/ConnectorsMatcherTest.php`):
   non-`api_key` method must NOT gate; PUT **and** PATCH (not just POST); mixed
   benign+secret payload gates; `api_key`-with-missing-`setting_name` falls through to
   Tier 2 without error; non-`api_key` method does NOT gate; multisite write gates.
3. **Manual release verification:** cookie-auth AND Application-Password writes to
   `/wp/v2/settings` with connector fields → `tests/MANUAL-TESTING.md`.
4. **Doc split** (separate commit/PR): `docs/connectors-api-reference.md` → lean core
   ref + security companion.
5. **Fix stale ROADMAP §74/§347** — mark shipped, point to code.

### Cache scope (evaluated → non-issue; corrects an earlier overstated finding)
An earlier pass flagged the per-request `$connector_setting_names_cache` (no runtime
invalidation; `reset_cache()` has zero runtime callers) as a `switch_to_blog()`
cross-blog false-negative. **A follow-up design review found that is not reachable**
and the proposed blog-keying fix is **not warranted**: the WP Connectors registry is
an `init`-populated in-process **singleton** (`WP_Connector_Registry`; settings at
`init` priority 20), and `switch_to_blog()` does **not** re-run `init`/registration —
so `wp_get_connectors()` returns the same `method`/`setting_name` mapping regardless of
the current blog. Only the stored *credential value* is per-site, not the mapping the
cache holds. The single per-request cache is therefore correct on multisite; blog-keying
would add complexity + unbounded growth under `switch_to_blog` loops and is untestable
(core never produces a per-blog-varying registry).

**Disposition: document the accepted residual, no code change.** The sole Tier-1
residual is **late registration** (a connector registered *after* the matcher's first
call in a request), already backstopped by the Tier-2 regex and accepted as a
documented limitation (see `.planning/connectors-matcher-strategy.md` and
`docs/connectors-api-reference.md`).

### MUST DO / MUST NOT
- MUST keep the fail-toward-gating union; gate every core `api_key` setting incl.
  `wordpress_api_key`.
- MUST NOT over-gate benign settings; MUST NOT extend scope to non-`api_key` auth
  methods (documented re-scoping trigger); MUST NOT weaken the regex fallback.
- MUST NOT refresh the in-code "verified against WP 7.0 GA …" date unless verification
  actually ran. Egress here blocks `*.wordpress.org` → if unreachable, leave the date
  and record the blocker (per the `llm-lies-log` discipline).

### Gating
Steps 2 (tests) and 5 (ROADMAP fix) proceed in this environment. Steps 1, 3, and the
date refresh require a network-capable environment. Step 4 is a separate commit.

---

## Scope 2 — Testing backlog (Tier 1)

**Type:** test/CI hardening (no production code unless a test reveals a defect).
**Effort:** Low–Medium. **Status:** design-reviewed; three test-correctness blockers
resolved below. Authored here, **verified in CI** (no DB/integration runner locally).

### Items
1. **`destroy_all()` session invariant.** The security model claims a bound proof
   "stops verifying after `WP_Session_Tokens::destroy_all()`." Logout is covered
   (`SudoSessionTest` INTG-F2); `destroy_all()` is not.
2. **Escalation-guard integration coverage.** The 4.1.0 guard
   (`Gate::arm_escalation_guard`) ships **unit-only** (mocked). Add real-WordPress
   tests of the live `add_user_metadata`/`update_user_metadata`/`grant_super_admin`
   hooks.
3. **Integration CI matrix:** PHP **8.2** (the declared minimum, entirely absent) and
   **8.4**.

### 🔴 Design-review blockers (resolved in this brief)
- **`destroy_all()` is a NEXT-request, core-auth-cookie effect — not WP Sudo's bind.**
  A same-request `$_COOKIE` flip would encode a *false* invariant (the bind compares
  the token string, which doesn't consult the emptied store mid-request). The real
  invariant: on the next request `wp_validate_auth_cookie()` rejects the cookie → user
  logged out → `verify_token()`'s current-user check fails. The test must drive
  `wp_validate_auth_cookie`/logout, and must confirm it adds coverage beyond the
  existing token-change test (`SudoSessionTest` line ~226), which already covers the
  only mechanism WP Sudo itself owns.
- **Escalation tests:** use `try/catch(\WPDieException)` (not `expectException`, which
  aborts before assertions) so DB state can be asserted post-halt with cache-bypassing
  reads. Halt-before-write holds (priority-0 metadata filter fires before the `$wpdb`
  write) — assert it via a fresh read. Multisite `grant_super_admin` asserts the
  **`site_admins` network option** (not user meta), needs a multisite bootstrap/skip
  guard and a not-already-super target. The "allowed" case must build a **genuinely
  bound actor session** or it passes for the wrong reason. Pick **one** deliberate
  promotion trigger (`$user->set_role('administrator')`), not `wp_update_user`/
  `update_user_meta` interchangeably.
- **No `define()`-based surface/bypass simulation** (constants leak across tests — the
  base teardown resets neither constants nor arbitrary filters). Explicit teardown of
  the `wp_sudo_guard_escalation` filter. Reflection, not new public accessors
  (`CLAUDE.md` "No Test-Environment Shims in Production").

### Priority nuance
**PHP 8.2 = correctness floor (must-have)** — the minimum supported runtime has zero
integration coverage. **8.4 = narrow forward-deprecation tripwire (nice-to-have);**
the 8.3 lane likely already catches most 8.4-class deprecations.

### MUST NOT
Introduce test-only production shims/DI; duplicate the Connectors edge tests (owned by
Scope 1).

---

## Scope 3 — Block-editor (Gutenberg) reauthentication UX

**Type:** new feature, **design-first**, phased. **Effort:** Large overall; Phase 1 is
small. **Status:** design-reviewed; premise corrected (Phase 1 shrank); phases 2–5
gated behind a route inventory.

### Problem
A gated action triggered from the block/site editor's `apiFetch` returns a 403 the SPA
can't recover from, and a full-page redirect mid-edit would destroy unsaved editor
state. The classic admin flow (`intercept()` → `challenge_admin()` → stash/replay) has
no editor equivalent. (Ordinary content saves are not gated — this is about gated
actions reachable *from* the editor.)

### 🔴 Design-review correction: the structured error already exists
For cookie-auth REST, `intercept_rest()` → `block_rest()` already returns
`WP_Error('sudo_required', {status:403, rule_id})`, pinned by `RestGatingTest`. So
Phase 1 is **not** "replace a generic 403" — it is an **additive one-field change.**

### Phased plan
- **Phase 1 (independently shippable, small PHP change):** add a `challenge_url` field
  to the existing `block_rest()` error, **cookie-auth branch only**. Do **not** touch
  `die_sudo_required()` (a different, shared backstop/admin-HTML path). **Never** add
  `challenge_url` to app-password/bearer branches (info disclosure). Add `RestGatingTest`
  coverage for the new key + a regression that the headless `sudo_disabled`/`sudo_blocked`
  branches are unchanged.
- **Gate before Phases 2–5:** enumerate the gated REST routes **actually reachable from
  the editor `apiFetch` today** (likely thin). If thin, the JS machinery is premature
  (Simplicity-First).
- **Phase 2+:** extract challenge rendering from `class-challenge.php` (without
  regressing the classic flow); `apiFetch` middleware catching `sudo_required` →
  in-editor snackbar (MVP) then modal → re-dispatch; 2FA in-editor (deferred — see
  below); Playwright E2E.

### 🔴 Load-bearing security boundary
**Client re-dispatch ≠ `Request_Stash` replay.** It is safe **only** because REST
gating is a *soft block* (no server stash is created on the REST path) and the client
re-fires *its own in-memory request* after the session is granted. Editor flows must
**never** route through `Request_Stash` (which exists to strip secrets / enforce
one-time, ownership-bound replay). This distinction must be stated in any
implementation.

### Other incorporated findings
- 2FA-in-editor is a **script/enqueue-context** problem (not CSS) → **defer**;
  snackbar-link-to-full-page is the only safe MVP for 2FA sites.
- Rule out **iframe** (SameSite/partitioned-cookie breaks sudo-cookie readback) → use
  same-document AJAX against the existing challenge handlers.
- Re-mint the REST nonce on retry (apiFetch's nonce middleware already does this).
- `challenge_url` has **no reliable network-admin context** under REST
  (`is_network_admin()` is false) — client builds it from a localized base, or the
  server emits a context-correct URL; don't naively reuse admin-path logic.
- The grace window (120 s) already covers "don't re-challenge"; retry should re-fire
  and let the gate re-evaluate, not gate the UI on a stale flag.
- The **`@wordpress/scripts` build step** is the largest risk: the plugin has **zero
  production dependencies and no build step** today; it adds SBOM, lint, and CI
  surface. Gate it behind an **explicit decision** after Phase 1 + the route inventory.

### Recommendation
Ship **Phase 1 alone** (server `challenge_url` + tests). Do **not** commit to phases
2–5 until (a) the route inventory justifies the JS, (b) the client-re-dispatch boundary
above is documented in the design, and (c) the build-step decision is taken explicitly.

---

## Cross-cutting notes
- Each scope (and each phase of Scope 3) takes its **own** Pre-Implementation Design
  Review + TDD + pre-commit reviewer per `CLAUDE.md` before code.
- Environment limits in the current session: `*.wordpress.org` egress is blocked
  (affects Scope 1 GA-verify); only the **unit** suite runs locally (integration tests
  for Scopes 1–2 verify in CI).
- The ROADMAP stale Connectors sections (§74/§347) and the bridge-coverage /
  escalation-guard blind-spot items are tracked in `docs/ROADMAP.md`.
