# Phase 22: Profile Provider Guard Implementation - Context

**Gathered:** 2026-06-30
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 22 implements the classic `profile.php` / `user-edit.php` provider lifecycle guard for the upstream WordPress.org Two Factor plugin. The guard must be narrow, TDD-covered, and source-verified: unrelated profile saves and normalized no-op Two Factor settings resubmissions must pass without WP Sudo gating, while meaningful enabled-provider, primary-provider, and TOTP-backed lifecycle changes require an active WP Sudo session. Existing REST lifecycle bridge behavior for backup-code generation and TOTP create/delete must remain green and unchanged unless refreshed upstream source requires a deliberate re-scope.

This phase does not add Patchstack runtime support, change WP Sudo's built-in challenge-time Two Factor validation, introduce a browser-only UI affordance, or create a product release/version tag.

</domain>

<decisions>
## Implementation Decisions

### Packaging and surface
- Extend `bridges/wp-sudo-two-factor-lifecycle-bridge.php`, the existing optional lifecycle bridge, rather than adding this as a core built-in rule.
- Keep the bridge inert when the upstream Two Factor plugin is absent; the optional bridge should not fail closed solely because its integration target is unavailable.
- Register the classic profile guard through the existing `wp_sudo_gated_actions` admin-rule callback seam so normal WP Sudo admin request matching, stash, challenge, and replay behavior governs the flow.
- Do not add custom blocking logic, custom JavaScript, or a new in-place challenge UI in this phase.

### Profile-provider predicate
- Evaluate only classic profile saves that match the upstream Two Factor lifecycle contract from Phase 21 evidence: `profile.php` or `user-edit.php`, `POST`, `action=update`, nonce field `_nonce_user_two_factor_options`, nonce action `user_two_factor_options`, and relevant lifecycle fields such as `_two_factor_enabled_providers` or `_two_factor_provider`.
- Unrelated profile updates with no Two Factor lifecycle fields must not be gated.
- Submitted provider values should be normalized before comparison: ignore the dummy empty value, ignore unknown or unavailable provider keys after upstream-style normalization, and treat provider ordering as irrelevant.
- Normalized no-op submissions must not be gated.
- Gate meaningful lifecycle changes: enabled-provider additions, enabled-provider removals, primary-provider changes, and TOTP-backed removal/replacement/first-enrollment events that create or alter a future factor able to satisfy WP Sudo challenges.
- If submitted Two Factor form data is absent, malformed, or not array-shaped in a way that upstream would no-op, do not gate unless a meaningful normalized lifecycle change can be proven.

### User flow and operator experience
- Use WP Sudo's normal admin challenge flow for matched profile-provider changes. The user should see the existing reauthentication challenge and then replay the original profile save.
- Keep labels and docs focused on changing Two Factor settings rather than implying all profile saves are sensitive.
- Preserve support for both self-profile saves and administrator-edited target users.
- Avoid broad `profile.php` / `user-edit.php` gating because it would block routine profile edits and violate TFA-GUARD-01.

### TDD and review posture
- Write failing unit tests before production behavior changes. The first tests should prove non-gating cases, then gating cases, then REST preservation.
- Prefer targeted unit tests around the bridge filter callback and predicate seam. Add integration coverage only if planning finds unit tests cannot reliably exercise target-user or normalization behavior.
- Run the targeted Two Factor bridge test after implementation tasks and the standard validation suite before any code commit: `composer test`, `composer analyse`, `composer lint`, and relevant targeted PHPUnit.
- Because this is security-sensitive request-gating behavior, Phase 22 planning should include a pre-implementation design review before tests/code are committed.

### Implementation Discretion
- The planner may choose whether to extract small internal helper functions in the bridge file to keep the predicate testable, as long as they are not public production API and do not become test-only shims.
- The planner may choose the exact rule id and label, provided they are clear, stable, and scoped to upstream Two Factor profile settings.
- The planner may decide whether to update implementation comments during Phase 22 or defer public docs to Phase 23, but stale comments that contradict implemented behavior should not remain.

</decisions>

<specifics>
## Specific Ideas

- Phase 21 source evidence is the implementation contract for upstream technical details. It verified WordPress/two-factor master commit `c515462d51ac92941685e39293673c08538e16c8` on 2026-06-30.
- The discussion defaulted to the conservative Phase 21 posture: existing optional bridge, normal WP Sudo challenge/replay flow, and gating only when a meaningful normalized lifecycle change can be proven.
- First TOTP-backed enrollment is treated as sensitive because it creates a future factor that can satisfy later WP Sudo challenges.

</specifics>

<code_context>
## Existing Code Insights

### Reusable Assets
- `bridges/wp-sudo-two-factor-lifecycle-bridge.php`: Existing optional bridge that registers Two Factor REST lifecycle rules through `wp_sudo_gated_actions`; Phase 22 should extend this file with the classic profile-provider admin rule.
- `tests/Unit/TwoFactorLifecycleBridgeTest.php`: Existing targeted bridge tests capture the registered filter callback and assert REST rule shape; extend this file first for TDD coverage of the profile guard.
- `.planning/phases/21-two-factor-source-refresh-and-guard-design/21-SOURCE-EVIDENCE.md`: Source contract for routes, hooks, nonce/action names, fields, meta keys, provider normalization, and first-enrollment policy.

### Established Patterns
- Admin rules use `pagenow`, `actions`, `method`, and an optional `callback` to narrow broad admin submissions before WP Sudo gates them.
- `Gate::matches_admin_pagenow()` already invokes an admin callback only after page, action, and method match, making it the correct seam for an idempotent Two Factor predicate.
- Existing built-in user/network rules use callbacks to avoid broad profile or settings gates when only a subset of a form submission is sensitive.
- Unit tests use Brain\Monkey and separate-process bridge tests for procedural bridge files.

### Integration Points
- `wp_sudo_gated_actions`: Rule registration point for the optional bridge.
- `profile.php` and `user-edit.php`: Classic profile pages for self and admin-edited Two Factor settings.
- `$_POST['_nonce_user_two_factor_options']`, `$_POST['_two_factor_enabled_providers']`, and `$_POST['_two_factor_provider']`: Upstream fields relevant to the predicate.
- User meta keys `_two_factor_enabled_providers`, `_two_factor_provider`, and `_two_factor_totp_key`: Current-state inputs for detecting provider and TOTP-backed lifecycle changes.
- Existing REST bridge rules `two_factor.backup_codes_generate` and `two_factor.totp_manage`: Must remain registered and behaviorally unchanged.

</code_context>

<deferred>
## Deferred Ideas

- Phase 23: Update `docs/two-factor-integration.md`, `docs/two-factor-ecosystem.md`, release/readiness docs, and canonical metrics after implementation behavior is known.
- Future milestone: Patchstack Security runtime compatibility remains fixture-blocked pending a paid Patchstack-enabled environment and manual challenge/lifecycle tests.
- Future milestone: Browser/in-place recovery for REST `sudo_required` JSON responses from the Two Factor settings UI remains separate from this classic profile guard implementation.

</deferred>

---

*Phase: 22-profile-provider-guard-implementation*
*Context gathered: 2026-06-30*
