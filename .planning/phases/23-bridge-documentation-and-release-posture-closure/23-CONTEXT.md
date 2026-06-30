# Phase 23: Bridge Documentation and Release Posture Closure - Context

**Gathered:** 2026-06-30
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 23 closes the v4.4.0 Two Factor Lifecycle Bridge milestone by updating maintainer-facing/public documentation, canonical metrics, and release-posture notes after the Phase 22 implementation. The phase must explain the now-shipped optional upstream WordPress.org Two Factor lifecycle bridge without expanding runtime compatibility claims, adding Patchstack code, changing product release metadata, or initiating WordPress.org submission.

The fixed scope is TFA-DOC-01 through TFA-DOC-03: distinguish challenge-time Two Factor validation, REST lifecycle bridge coverage, and classic profile-provider lifecycle gating; keep Patchstack Security fixture-blocked/manual-test-only; and refresh current metrics/changelog/release notes only where implementation changes affected canonical counts or release-facing behavior.

</domain>

<decisions>
## Implementation Decisions

### Two Factor documentation structure
- Update `docs/two-factor-integration.md` to distinguish three separate surfaces:
  1. built-in challenge-time Two Factor validation, which delegates provider rendering/preprocessing/code validation during WP Sudo challenges;
  2. optional bridge REST lifecycle gating for `POST /two-factor/1.0/generate-backup-codes` and `POST`/`DELETE /two-factor/1.0/totp`;
  3. optional bridge classic profile-provider lifecycle gating for meaningful `profile.php` / `user-edit.php` provider, primary-provider, and TOTP-backed lifecycle changes.
- Keep wording precise: the classic profile guard is narrow and idempotent; unrelated profile saves, malformed upstream no-op submissions, and normalized no-op provider resubmissions are not gated.
- Include the Phase 21 source-evidence citation posture for upstream technical claims: WordPress/two-factor master commit `c515462d51ac92941685e39293673c08538e16c8`, checked 2026-06-30, with durable links or references to the Phase 21 evidence where appropriate.
- Preserve known limits: in-place recovery for REST `sudo_required` JSON in the Two Factor settings UI remains future work; direct WP-CLI/PHP recovery-code generation remains governed by non-interactive policy; classic-form TOTP secret replacement must not be inferred from unverified POST fields.

### Ecosystem and Patchstack posture
- Update `docs/two-factor-ecosystem.md` so WordPress/two-factor lifecycle rows no longer describe the profile-provider guard as a candidate; it is shipped in the optional bridge after Phase 22 validation.
- Keep Patchstack Security as a manual-test target only. The allowed wording is fixture-blocked/manual runtime testing pending a paid Patchstack-enabled environment.
- Do not add Patchstack bridge code, runtime support claims, or compatibility language that implies tested runtime behavior.
- If Patchstack appears in release/readiness docs, make sure the same fixture-blocked boundary is preserved there too.

### Canonical metrics and release notes
- Refresh `docs/current-metrics.md` first for any changed counts. Phase 22 already observed live unit results of `893 tests` and `2676 assertions`; planner/executor must still regenerate all metrics via the documented commands instead of trusting memory.
- Run `composer verify:metrics` after updating metrics and fix any drift it reports.
- Add concise release-facing notes only as warranted by actual implementation changes:
  - `CHANGELOG.md` Unreleased should mention the optional Two Factor profile-provider lifecycle guard if release-facing behavior changed.
  - `docs/release-status.md` should mention current unreleased `main` now includes the Two Factor profile-provider lifecycle bridge and refreshed metrics, but must not imply a new tag or product release.
- Do not bump `WP_SUDO_VERSION`, plugin header `Version:`, `readme.txt` Stable tag, or create a product git tag.

### Validation posture
- Use the standard docs/metrics closure gate:
  - targeted bridge PHPUnit: `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/TwoFactorLifecycleBridgeTest.php --filter TwoFactorLifecycleBridgeTest`;
  - `composer test`;
  - `composer analyse`;
  - `composer lint`;
  - `composer verify:metrics`.
- No Playwright/E2E run is required unless Phase 23 unexpectedly changes browser/admin UI behavior or screenshot-visible claims. Documentation and metrics updates alone should not block on full E2E.
- Run `git diff --check` before committing.
- Docs-only commits do not require reviewer approval, but any unexpected code changes must follow the repo reviewer/TDD rules.

### Implementation Discretion
- Planner may decide the exact doc section headings and whether to use a matrix/table or prose, as long as the three Two Factor surfaces and Patchstack boundary are clear.
- Planner may decide whether `readme.txt`, `readme.md`, `docs/wporg-submission-checklist.md`, or `tests/MANUAL-TESTING.md` need updates after `docs/current-metrics.md` is refreshed. Prefer canonical links over duplicated volatile counts.
- Planner may decide whether to include a compact milestone-completion note in planning summaries, but public release metadata must remain unchanged.

</decisions>

<specifics>
## Specific Ideas

- Phase 22 verification passed with 9/9 must-haves and no human verification required in `.planning/phases/22-profile-provider-guard-implementation/22-VERIFICATION.md`.
- Phase 22 added `two_factor.profile_provider_lifecycle` in `bridges/wp-sudo-two-factor-lifecycle-bridge.php` and RED/GREEN tests in `tests/Unit/TwoFactorLifecycleBridgeTest.php`.
- Phase 22 validation found `docs/current-metrics.md` drift: live `composer test` result was `893 tests` / `2676 assertions`, while canonical metrics still showed pre-Phase-22 counts.
- Current `docs/two-factor-ecosystem.md` still contains stale rows that describe WordPress/two-factor profile provider changes as a candidate and cite the 2026-06-29 seed evidence; Phase 23 should update those rows to Phase 21/22 verified reality.
- Current `CHANGELOG.md` Unreleased only mentions localization packaging readiness; it should gain a concise Two Factor lifecycle bridge note if the planner confirms this is release-facing behavior.

</specifics>

<code_context>
## Existing Code Insights

### Reusable Assets
- `bridges/wp-sudo-two-factor-lifecycle-bridge.php`: Source of truth for the optional bridge behavior and known limits after Phase 22.
- `tests/Unit/TwoFactorLifecycleBridgeTest.php`: Targeted validation for REST route preservation and classic profile lifecycle predicates.
- `.planning/phases/21-two-factor-source-refresh-and-guard-design/21-SOURCE-EVIDENCE.md`: Source-cited upstream contract for REST routes, profile fields, nonce/action names, meta keys, and provider normalization.
- `.planning/phases/22-profile-provider-guard-implementation/22-VERIFICATION.md`: Verification evidence for the shipped profile-provider guard and standard validation results.
- `docs/current-metrics.md`: Canonical current counts that must be updated before other docs reference changed counts.
- `docs/release-status.md`: Canonical current release state and unreleased-main posture; must remain explicit that latest tagged release is `v4.2.2` unless a real tag/version bump occurs.

### Established Patterns
- Volatile counts belong in `docs/current-metrics.md`; other docs should link to it instead of duplicating hardcoded counts.
- Release state belongs in `docs/release-status.md`; GSD milestone names do not imply product release versions or tags.
- WordPress.org submission remains delayed/on hold while readiness docs stay actionable.
- Third-party technical claims about upstream WordPress/two-factor internals require source URL, commit/revision, and checked date.

### Integration Points
- `docs/two-factor-integration.md`: Primary maintainer-facing guide for challenge-time Two Factor integration and lifecycle bridge behavior.
- `docs/two-factor-ecosystem.md`: Compatibility matrix and manual-target posture, including Patchstack fixture boundary.
- `CHANGELOG.md`: Unreleased user-facing behavior notes without version bump.
- `docs/current-metrics.md`: Metrics refresh and `composer verify:metrics` target.
- `docs/release-status.md`: Current `main` drift after `v4.2.2` and WordPress.org on-hold posture.
- `docs/wporg-submission-checklist.md`: Readiness checklist; update only if Phase 23 finds stale release/metrics/Patchstack gate wording.

</code_context>

<deferred>
## Deferred Ideas

- Product release/tag/version bump remains deferred until explicitly requested; latest tagged release stays `v4.2.2` unless release metadata is intentionally changed.
- WordPress.org SVN upload/submission remains delayed/on hold until the maintainer explicitly approves publication.
- Patchstack runtime compatibility remains deferred until a paid Patchstack-enabled fixture exists and manual challenge/lifecycle tests are recorded.
- Browser/in-place recovery for Two Factor settings UI REST `sudo_required` responses remains a future UX phase.

</deferred>

---

*Phase: 23-bridge-documentation-and-release-posture-closure*
*Context gathered: 2026-06-30*
