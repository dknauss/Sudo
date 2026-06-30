# Phase 21: Two Factor Source Refresh and Guard Design - Context

**Gathered:** 2026-06-30
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 21 refreshes upstream `WordPress/two-factor` source evidence and designs the idempotent classic profile-provider guard predicate before production behavior changes. It covers source verification, guard-boundary decisions, existing REST bridge preservation, and Patchstack/release scope boundaries. It does **not** implement the profile-provider guard; implementation belongs to Phase 22.

</domain>

<decisions>
## Implementation Decisions

### Source evidence package
- Produce a Phase 21 source evidence note/summary as the primary artifact, then copy only durable citations into code comments or docs where they support implementation-facing claims.
- Capture implementation-ready details: REST routes/methods, classic profile hooks, field names, nonce/action names, user-meta keys, provider normalization behavior, and any relevant user-id/target-user resolution details.
- Use strict citations for third-party technical claims: upstream source URL, commit or revision identifier, and checked date.
- If live upstream `WordPress/two-factor` source differs from Phase 19 evidence in a way that affects routes, form fields, nonce/action names, hooks, meta keys, or normalization behavior, Phase 21 should stop and re-scope before Phase 22 implementation.

### Profile-provider guard predicate
- The guard should only evaluate classic profile saves when Two Factor's own nonce/action contract and lifecycle fields are present. This prevents gating unrelated profile updates.
- The guard should cover both self-profile changes and administrator edits of another user's Two Factor lifecycle settings.
- No-op Two Factor provider submissions should not be gated. The design should normalize submitted values and compare the effective provider set and primary provider against the current state.
- Unsupported or unknown submitted provider keys should be ignored after normalization to match upstream Two Factor behavior, rather than treated as suspicious or blocked outright.
- First enrollment remains a gated lifecycle change for this milestone when it creates a TOTP-backed factor that can satisfy future sudo challenges.
- Broad `profile.php` / `user-edit.php` save gating remains out of scope; the predicate must be idempotent and enrollment-aware.

### Existing REST bridge preservation
- If refreshed upstream source confirms the existing REST routes are still current, Phase 21 should make no REST behavior change: record evidence, run targeted bridge tests, and leave the REST rules unchanged.
- If REST routes or methods changed upstream, Phase 21 should stop and re-scope rather than silently patching behavior during the design/source-refresh phase.
- Expected validation for REST preservation is targeted bridge testing, especially `tests/Unit/TwoFactorLifecycleBridgeTest.php`; broader standard checks belong to implementation/closure unless Phase 21 edits implementation files materially.
- Phase 21 should flag and update stale bridge comments when they contradict v4.4.0 decisions, especially wording around the future profile predicate or first-enrollment policy.

### Patchstack and release boundaries
- Patchstack Security remains a boundary note only in Phase 21: fixture-blocked, manual-test pending, and out of implementation scope.
- Docs must not claim Patchstack runtime compatibility; the allowed posture is only that Patchstack is a future manual-test target pending a paid Patchstack-enabled fixture.
- Phase 21 is planning/source-refresh work only. It does not imply a plugin version bump, product release tag, release candidate, or WordPress.org submission.
- Public docs should usually wait for Phase 23, except Phase 21 may correct stale source claims or bridge comments discovered during source refresh.

### Implementation Discretion
- The planner may choose the exact filename/format for the Phase 21 source evidence artifact inside the phase directory.
- The planner may choose whether stale bridge-comment fixes are committed in Phase 21 or recorded as explicit Phase 22/23 tasks, as long as contradictions are not left hidden.
- The planner may decide whether targeted validation is limited to `TwoFactorLifecycleBridgeTest` or includes nearby unit tests if source refresh changes the planned predicate seam.

</decisions>

<specifics>
## Specific Ideas

- Treat Phase 19 as seed context, not as a substitute for live source verification.
- Keep the source-refresh artifact maintainer-oriented; public docs should remain concise and release-facing.
- The user selected the conservative/default path throughout: strict source citations, no behavior drift without re-scoping, no broad profile-save gate, and no Patchstack runtime claims.

</specifics>

<code_context>
## Existing Code Insights

### Reusable Assets
- `bridges/wp-sudo-two-factor-lifecycle-bridge.php`: Existing opt-in bridge registers Two Factor REST lifecycle rules through `wp_sudo_gated_actions`; Phase 21 should preserve this mechanism unless live source evidence proves it stale.
- `tests/Unit/TwoFactorLifecycleBridgeTest.php`: Existing targeted unit coverage asserts the REST rule ids, route patterns, and methods for backup-code generation and TOTP management.
- `docs/two-factor-integration.md`: Already distinguishes challenge-time provider delegation, REST lifecycle coverage, and planned profile-provider guard work; Phase 21 may update source-evidence details if they are stale.
- `.planning/milestones/historical-phases/19-2fa-bridge-planning-and-compatibility-matrix/19-01-SUMMARY.md`: Prior seed evidence and TDD plan, checked against upstream commit `fb2671b46d7fad4ceb1962297bf02762e9547309` on 2026-06-29.

### Established Patterns
- Gated actions are defined as rule arrays with `admin`, `ajax`, `rest`, and optional callback criteria. Third-party extensions use the `wp_sudo_gated_actions` filter.
- Existing admin callbacks narrow broad WordPress form submissions, for example user role and password-change profile callbacks. The Two Factor profile-provider guard should follow this pattern rather than gate all profile saves.
- Unit tests use Brain\Monkey and may require separate-process tests for procedural bridge files. Existing bridge tests already capture the registered filter callback.
- Repository policy requires live-source verification for third-party method names, class names, meta keys, hooks, route names, and similar technical claims.

### Integration Points
- `wp_sudo_gated_actions` filter: likely registration point for the future profile-provider rule.
- `Gate::matches_admin_pagenow()`: admin rule matching calls an optional `admin['callback']`; predicate design should fit this existing callback seam.
- Classic profile pages: `profile.php` and `user-edit.php`, `action=update`, `POST`, narrowed by Two Factor nonce/action and lifecycle fields.
- Existing REST bridge routes: `POST /two-factor/1.0/generate-backup-codes`; `POST` and `DELETE /two-factor/1.0/totp`.

</code_context>

<deferred>
## Deferred Ideas

- Phase 22: Implement the profile-provider lifecycle guard with TDD.
- Phase 23: Update public integration/ecosystem/release docs and canonical metrics after implementation behavior is known.
- Future milestone: Acquire a paid Patchstack-enabled fixture and run manual challenge/lifecycle compatibility tests before any Patchstack bridge or runtime support claim.
- Future milestone: Multisite browser coverage for network-admin stash/replay flows remains separate from this Two Factor lifecycle bridge work.

</deferred>

---

*Phase: 21-two-factor-source-refresh-and-guard-design*
*Context gathered: 2026-06-30*
