---
phase: 22-profile-provider-guard-implementation
plan: 01
subsystem: security
tags: [wordpress, two-factor, wp-sudo, request-gating, tdd, phpunit]

requires:
  - phase: 21-two-factor-source-refresh-and-guard-design
    provides: "Verified WordPress/two-factor source contract at commit c515462d51ac92941685e39293673c08538e16c8 checked 2026-06-30"
provides:
  - "Optional Two Factor classic profile-provider lifecycle guard"
  - "RED/GREEN unit coverage for normalized provider, primary-provider, and TOTP-backed lifecycle predicates"
  - "Preserved REST lifecycle bridge behavior for backup-code generation and TOTP create/delete"
affects: [phase-22-validation, phase-23-documentation, two-factor-bridge]

tech-stack:
  added: []
  patterns: ["Optional bridge rule via wp_sudo_gated_actions admin callback", "Source-verified provider normalization before gating"]

key-files:
  created:
    - .planning/phases/22-profile-provider-guard-implementation/22-DESIGN-BRIEF.md
    - .planning/phases/22-profile-provider-guard-implementation/22-01-SUMMARY.md
  modified:
    - bridges/wp-sudo-two-factor-lifecycle-bridge.php
    - tests/Unit/TwoFactorLifecycleBridgeTest.php
    - tests/bootstrap.php
    - psalm-baseline.xml

key-decisions:
  - "Keep the guard in the optional Two Factor bridge and fail open when upstream lifecycle data is absent, malformed, unauthorized, or cannot prove a meaningful change."
  - "Preserve source-verified core profile fields in the replay allowlist so mixed profile plus Two Factor saves replay as complete core profile updates."
  - "Do not infer classic-form TOTP secret replacement from unverified POST fields; gate verified TOTP provider enable/disable against existing _two_factor_totp_key state."

patterns-established:
  - "Profile lifecycle bridge callbacks normalize submitted and current provider sets against Two_Factor_Core::get_supported_providers_for_user()."
  - "Bridge tests capture wp_sudo_gated_actions rules and assert both REST preservation and admin callback predicates."

requirements-completed: [TFA-GUARD-01, TFA-GUARD-02, TFA-GUARD-03, TFA-GUARD-04, TFA-GUARD-05]

duration: 19min
completed: 2026-06-30
---

# Phase 22 Plan 01: Two Factor Profile Provider Guard Summary

**Optional Two Factor bridge now gates meaningful classic profile provider lifecycle changes without blocking unrelated or normalized no-op profile saves.**

## Performance

- **Duration:** 19 min
- **Started:** 2026-06-30T02:13:01Z
- **Completed:** 2026-06-30T02:31:35Z
- **Tasks:** 3
- **Files modified:** 5

## Accomplishments

- Recorded a security-sensitive design brief and incorporated reviewer critique before tests or production code.
- Added RED unit tests for all five profile-provider guard requirements plus existing REST bridge preservation.
- Implemented `two_factor.profile_provider_lifecycle` as a narrow optional bridge admin rule using source-verified nonce, target-user, provider-normalization, and TOTP-backed lifecycle checks.
- Preserved complete core profile replay fields for mixed profile plus Two Factor submissions.

## Task Commits

Each task was committed atomically:

1. **Task 1: Complete security-sensitive design review before tests/code** — `6b0ca45` (docs)
2. **Task 2 RED: Write RED unit tests for the profile-provider guard and REST preservation** — `9fd7239` (test)
3. **Task 3 GREEN: Implement the narrow profile-provider lifecycle guard until tests pass** — `ee55b0f` (feat)

**Plan metadata:** pending final docs commit.

_Note: Task 2 intentionally committed failing RED tests before implementation; the recorded RED run failed because `two_factor.profile_provider_lifecycle` was not registered._

## Files Created/Modified

- `.planning/phases/22-profile-provider-guard-implementation/22-DESIGN-BRIEF.md` — Security-sensitive pre-implementation design review record.
- `bridges/wp-sudo-two-factor-lifecycle-bridge.php` — Adds the optional classic profile-provider lifecycle rule and predicate.
- `tests/Unit/TwoFactorLifecycleBridgeTest.php` — Covers non-gating, no-op, provider-change, primary-change, TOTP-backed, REST preservation, and replay allowlist behavior.
- `tests/bootstrap.php` — Extends Two Factor test stubs for supported-provider normalization and capability parity.
- `psalm-baseline.xml` — Removes stale bridge HookNotFound baseline after adding an inline integration-hook suppression.

## Decisions Made

- Keep the profile lifecycle guard in the optional bridge, not WP Sudo core, because it only applies when upstream WordPress.org Two Factor is present.
- Fail open when nonce, target user, enabled-provider shape, upstream capability parity, or supported-provider lookup cannot prove an upstream Two Factor lifecycle write.
- Normalize provider sets order-insensitively and ignore dummy empty values, duplicates, non-scalar entries, and unsupported provider keys.
- Preserve core profile fields in the stash allowlist after reviewer feedback identified incomplete replay as a security/UX correctness risk.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] Added core profile replay fields to the profile guard stash allowlist**
- **Found during:** Task 3 (pre-commit reviewer pass)
- **Issue:** The initial stash allowlist preserved WP Sudo defaults plus Two Factor fields, but a mixed core profile plus Two Factor save could replay without required fields such as `email` and `nickname`.
- **Fix:** Added source-verified core profile fields from WordPress `user-edit.php` / `edit_user()` to the allowlist and test assertions so replay remains complete for core profile saves.
- **Files modified:** `bridges/wp-sudo-two-factor-lifecycle-bridge.php`, `tests/Unit/TwoFactorLifecycleBridgeTest.php`
- **Verification:** Targeted bridge tests, `composer test`, `composer analyse`, and `composer lint` passed; reviewer approved after the fix.
- **Committed in:** `ee55b0f`

**2. [Rule 3 - Blocking] Resolved Psalm HookNotFound baseline drift caused by bridge expansion**
- **Found during:** Task 3 validation
- **Issue:** Expanding the bridge changed the `wp_sudo_gated_actions` call enough that the old Psalm baseline entry no longer matched, causing `composer analyse` to fail.
- **Fix:** Added an inline `@psalm-suppress HookNotFound` matching other optional bridge integration hooks and updated the baseline to remove the stale entry.
- **Files modified:** `bridges/wp-sudo-two-factor-lifecycle-bridge.php`, `psalm-baseline.xml`
- **Verification:** `composer analyse` passed.
- **Committed in:** `ee55b0f`

---

**Total deviations:** 2 auto-fixed (1 missing critical, 1 blocking)
**Impact on plan:** Both fixes were necessary for correctness and validation. No scope creep beyond verified core profile replay fields.

## Issues Encountered

- The first pre-commit reviewer rejected the implementation for incomplete profile replay safety. The stash allowlist and tests were updated, then a second reviewer approved and wrote `reviewer-approved`.
- RED test evidence is captured in `/tmp/wp-sudo-phase22-red.log` for this session and described in the Task 2 commit message.

## Verification

- RED: `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/TwoFactorLifecycleBridgeTest.php --filter TwoFactorLifecycleBridgeTest` failed with missing `two_factor.profile_provider_lifecycle` registration.
- GREEN targeted: `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/TwoFactorLifecycleBridgeTest.php --filter TwoFactorLifecycleBridgeTest` passed with 7 tests and 82 assertions.
- Full unit: `composer test` passed with 893 tests and 2676 assertions.
- Static analysis: `composer analyse` passed.
- Lint: `composer lint` passed.

## Auth Gates

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

Plan 22-02 can perform the required validation and scope-boundary checks against the implemented bridge. Phase 23 can update maintainer-facing docs and canonical metrics if validation confirms counts or release-facing descriptions changed.

---
*Phase: 22-profile-provider-guard-implementation*
*Completed: 2026-06-30*

## Self-Check: PASSED

- Verified summary, design brief, bridge, tests, test bootstrap, and Psalm baseline files exist.
- Verified task commits exist: `6b0ca45`, `9fd7239`, `ee55b0f`.
