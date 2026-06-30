---
phase: 21-two-factor-source-refresh-and-guard-design
plan: 01
subsystem: auth
tags: [wordpress, two-factor, wp-sudo, lifecycle-bridge, source-verification]

requires:
  - phase: 19-2fa-bridge-planning-and-compatibility-matrix
    provides: Upstream Two Factor lifecycle bridge seed evidence and profile-provider guard plan
provides:
  - Current upstream WordPress/two-factor source evidence for REST and classic profile lifecycle contracts
  - Idempotent, enrollment-aware profile-provider guard design for Phase 22
  - Reconciled bridge/docs wording for v4.4.0 first-enrollment policy
affects: [two-factor-integration, two-factor-lifecycle-bridge, phase-22]

tech-stack:
  added: []
  patterns:
    - Source-cited third-party technical claims with upstream commit SHA and checked date
    - Design-first security guard planning before TDD implementation

key-files:
  created:
    - .planning/phases/21-two-factor-source-refresh-and-guard-design/21-SOURCE-EVIDENCE.md
    - .planning/phases/21-two-factor-source-refresh-and-guard-design/21-01-SUMMARY.md
  modified:
    - bridges/wp-sudo-two-factor-lifecycle-bridge.php
    - docs/two-factor-integration.md

key-decisions:
  - "Proceed to Phase 22 planning/implementation because current upstream Two Factor contracts show no material scoped drift."
  - "First TOTP-backed enrollment is a future profile-guard lifecycle change because it creates a factor that can satisfy later sudo challenges."
  - "Patchstack remains fixture-blocked and out of implementation/runtime-claim scope."

patterns-established:
  - "Phase evidence artifacts must include source URLs, upstream SHA, checked date, and proceed/re-scope decision."
  - "Bridge comments should distinguish REST-only coverage from future classic profile-provider lifecycle guards."

requirements-completed:
  - TFA-SRC-01
  - TFA-SRC-02
  - TFA-GUARD-06
  - TFA-REST-01
  - TFA-REST-02

duration: 5min
completed: 2026-06-30
---

# Phase 21 Plan 01: Two Factor Source Refresh and Guard Design Summary

**Current upstream Two Factor source evidence with an enrollment-aware profile-provider guard design and unchanged REST bridge behavior**

## Performance

- **Duration:** 5 min
- **Started:** 2026-06-30T01:09:20Z
- **Completed:** 2026-06-30T01:14:24Z
- **Tasks:** 3
- **Files modified:** 4

## Accomplishments

- Refreshed upstream `WordPress/two-factor` source evidence at commit `c515462d51ac92941685e39293673c08538e16c8`, checked 2026-06-30.
- Confirmed the existing REST bridge route assumptions remain current: `POST /two-factor/1.0/generate-backup-codes` and `POST`/`DELETE /two-factor/1.0/totp`.
- Documented the classic profile-provider guard predicate for Phase 22: nonce/action plus lifecycle fields, target-user resolution, normalization, no-op pass-through, provider/primary-provider changes, TOTP-backed removal/replacement, and first TOTP-backed enrollment.
- Reconciled stale bridge/docs wording so first TOTP-backed enrollment is treated as a future profile-guard lifecycle change, not intentionally excluded.
- Preserved the Patchstack boundary: fixture-blocked/manual-test only, no bridge code or runtime compatibility claim.

## Source Evidence

- WordPress/two-factor master commit: `c515462d51ac92941685e39293673c08538e16c8`
- Checked: 2026-06-30
- Sources:
  - `https://api.github.com/repos/WordPress/two-factor/commits/master`
  - `https://raw.githubusercontent.com/WordPress/two-factor/c515462d51ac92941685e39293673c08538e16c8/class-two-factor-core.php`
  - `https://raw.githubusercontent.com/WordPress/two-factor/c515462d51ac92941685e39293673c08538e16c8/providers/class-two-factor-backup-codes.php`
  - `https://raw.githubusercontent.com/WordPress/two-factor/c515462d51ac92941685e39293673c08538e16c8/providers/class-two-factor-totp.php`

## Task Commits

Each task was committed atomically:

1. **Task 1: Capture current upstream Two Factor source evidence** — `07e029e` (docs)
2. **Task 2: Write guard predicate design and reconcile stale comments** — `7e50aa1` (docs/comments)
3. **Task 3: Validate REST bridge preservation and summarize Phase 21** — this metadata commit

## Files Created/Modified

- `.planning/phases/21-two-factor-source-refresh-and-guard-design/21-SOURCE-EVIDENCE.md` — source URLs/SHA/date, REST contracts, profile lifecycle contracts, provider normalization findings, guard design, and proceed decision.
- `bridges/wp-sudo-two-factor-lifecycle-bridge.php` — refreshed upstream commit/date comment and replaced stale enrollment-excluding wording with enrollment-aware follow-up wording.
- `docs/two-factor-integration.md` — refreshed profile-provider source citation and locked the v4.4.0 policy that first TOTP-backed enrollment is gated in the future guard.
- `.planning/phases/21-two-factor-source-refresh-and-guard-design/21-01-SUMMARY.md` — this execution summary.

## Decisions Made

- **Proceed:** No material scoped drift was found in upstream Two Factor REST routes, profile hooks, nonce/action names, lifecycle fields, or relevant meta keys.
- **No behavior change in Phase 21:** Existing REST bridge behavior remains unchanged; Phase 22 owns TDD implementation of the profile-provider guard.
- **First enrollment policy:** First TOTP-backed enrollment should be gated by the future profile guard because it creates a factor that can satisfy later sudo challenges.
- **Patchstack boundary:** Patchstack remains fixture-blocked and out of implementation/runtime-claim scope until a paid fixture exists and manual runtime tests are recorded.

## Deviations from Plan

None — plan executed as written.

**Total deviations:** 0 auto-fixed.
**Impact on plan:** No scope creep; Phase 21 remained source-refresh/design work.

## Issues Encountered

None.

## User Setup Required

None — no external service configuration required.

## Verification

- `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/TwoFactorLifecycleBridgeTest.php` — PASS (2 tests, 30 assertions)
- `grep -Eq 'Decision: proceed|RE-SCOPE REQUIRED' .planning/phases/21-two-factor-source-refresh-and-guard-design/21-SOURCE-EVIDENCE.md` — PASS
- `grep -E 'Patchstack.*fixture|fixture-blocked.*Patchstack' .planning/phases/21-two-factor-source-refresh-and-guard-design/21-SOURCE-EVIDENCE.md` — PASS
- `git diff --check` — PASS
- `composer analyse` — PASS
- `composer lint` — PASS
- Pre-commit full unit suite for PHP-comment/docs commit — PASS (888 tests, 2624 assertions)

## Next Phase Readiness

Phase 22 can plan/implement the profile-provider guard with TDD using `21-SOURCE-EVIDENCE.md` as the source contract. It should still re-check live upstream source immediately before behavior changes if time has passed or upstream changes are suspected.

---
*Phase: 21-two-factor-source-refresh-and-guard-design*
*Completed: 2026-06-30*
