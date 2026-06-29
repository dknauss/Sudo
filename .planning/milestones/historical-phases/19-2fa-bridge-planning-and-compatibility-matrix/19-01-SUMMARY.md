---
phase: 19-2fa-bridge-planning-and-compatibility-matrix
plan: 01
subsystem: auth
tags: [wordpress, two-factor, wp-sudo, lifecycle-bridge, rest, tdd]

requires: []
provides:
  - "Source-refreshed upstream Two Factor REST lifecycle bridge posture"
  - "Implementation-ready TDD plan for classic profile provider lifecycle guard"
  - "Verified distinction between built-in challenge validation, current REST bridge coverage, and future profile guard work"
affects: [two-factor-integration, 2fa-bridge, security-docs, phase-19]

tech-stack:
  added: []
  patterns:
    - "Source-cited third-party technical claims with upstream commit SHA and checked date"
    - "TDD-first planning for security guards that could block legitimate profile saves"

key-files:
  created:
    - ".planning/phases/19-2fa-bridge-planning-and-compatibility-matrix/19-01-SUMMARY.md"
  modified:
    - "bridges/wp-sudo-two-factor-lifecycle-bridge.php"
    - "docs/two-factor-integration.md"
    - "docs/current-metrics.md"

key-decisions:
  - "WordPress/two-factor REST lifecycle route behavior remains current at commit fb2671b46d7fad4ceb1962297bf02762e9547309, so bridge behavior and unit tests did not need changes."
  - "Classic profile provider changes remain future TDD/design-review work rather than an overbroad profile-save gate."
  - "docs/current-metrics.md must be updated when source-comment changes alter production PHP line counts."

patterns-established:
  - "Bridge docs should distinguish challenge-time 2FA validation from factor lifecycle gating."
  - "Future profile-provider guards must use an idempotent predicate and explicit first-enrollment policy."

requirements-completed: [2FA-01, 2FA-03]

duration: 5min
completed: 2026-06-29
---

# Phase 19 Plan 01: Upstream Two Factor Lifecycle Bridge Summary

**Source-refreshed Two Factor REST lifecycle bridge posture plus a TDD-first profile-provider guard plan**

## Performance

- **Duration:** 5 min
- **Started:** 2026-06-29T17:50:38Z
- **Completed:** 2026-06-29T17:56:05Z
- **Tasks:** 3
- **Files modified:** 3

## Accomplishments

- Refreshed upstream WordPress/two-factor evidence at commit `fb2671b46d7fad4ceb1962297bf02762e9547309`, checked 2026-06-29.
- Updated the integration docs from stale “planned” lifecycle wording to an honest current posture: challenge-time validation is built in, the REST lifecycle bridge already covers recovery-code generation and TOTP create/delete, and profile-provider changes remain planned guard work.
- Added an implementation-ready profile provider changes plan with TDD cases for unrelated profile saves, provider set changes, primary provider changes, TOTP removal/replacement, and explicit first-enrollment policy.
- Confirmed `tests/Unit/TwoFactorLifecycleBridgeTest.php` did not require changes because refreshed upstream routes and methods still match the existing bridge behavior.

## Source Evidence

- WordPress/two-factor master commit: `fb2671b46d7fad4ceb1962297bf02762e9547309`
- Checked: 2026-06-29
- Sources:
  - `https://raw.githubusercontent.com/WordPress/two-factor/fb2671b46d7fad4ceb1962297bf02762e9547309/providers/class-two-factor-backup-codes.php`
  - `https://raw.githubusercontent.com/WordPress/two-factor/fb2671b46d7fad4ceb1962297bf02762e9547309/providers/class-two-factor-totp.php`
  - `https://raw.githubusercontent.com/WordPress/two-factor/fb2671b46d7fad4ceb1962297bf02762e9547309/class-two-factor-core.php`

## Task Commits

Each task was committed atomically:

1. **Task 1: Refresh upstream Two Factor source evidence and update REST bridge posture** — `d37e75e` (docs)
2. **Task 2: Write implementation-ready profile-provider guard plan without shipping the guard** — `1e822a0` (docs)
3. **Task 3: Validate upstream bridge readiness and preserve source-citation discipline** — `21e353e` (docs)

## Files Created/Modified

- `bridges/wp-sudo-two-factor-lifecycle-bridge.php` — Refreshed bridge comment with upstream Two Factor commit and checked date; no behavior changed.
- `docs/two-factor-integration.md` — Documents current REST lifecycle bridge coverage and future profile-provider guard plan.
- `docs/current-metrics.md` — Synchronized PHP line counts after the bridge comment update changed production line totals.
- `.planning/phases/19-2fa-bridge-planning-and-compatibility-matrix/19-01-SUMMARY.md` — This execution summary.

## Verification

- `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/TwoFactorLifecycleBridgeTest.php` — PASS (2 tests, 30 assertions)
- `composer test` — PASS (888 tests, 2624 assertions)
- `composer analyse` — PASS (PHPStan no errors; Psalm reports no errors, informational issues only)
- `composer lint` — PASS
- `composer verify:metrics` — PASS after metrics sync
- `grep -n "wp-sudo-two-factor-lifecycle-bridge\.php\|generate-backup-codes\|two-factor/1.0/totp" docs/two-factor-integration.md` — PASS
- `grep -n "Profile provider changes\|unrelated profile\|primary provider\|first enrollment\|design review" docs/two-factor-integration.md` — PASS
- Manual source-citation review — PASS; edited third-party claims include upstream URL, commit SHA, and checked date.

## Decisions Made

- No bridge behavior change was needed because live upstream route/method details still match existing rules:
  - `POST /two-factor/1.0/generate-backup-codes`
  - `POST` and `DELETE /two-factor/1.0/totp`
- No unit-test change was needed because existing `TwoFactorLifecycleBridgeTest` coverage already asserts the refreshed route and method contract.
- Profile-provider changes must be implemented later with CLAUDE.md design review and TDD because a broad `profile.php` / `user-edit.php` save gate would block unrelated profile edits.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Synced canonical metrics after bridge comment line-count drift**
- **Found during:** Task 3 (Validate upstream bridge readiness and preserve source-citation discipline)
- **Issue:** `composer verify:metrics` detected that the Task 1 bridge comment update changed production PHP line counts by +1 while `docs/current-metrics.md` still held old counts.
- **Fix:** Updated `docs/current-metrics.md` production, production+tests, ratio formula, and total repo PHP line counts.
- **Files modified:** `docs/current-metrics.md`
- **Verification:** `composer verify:metrics` passed after the update.
- **Committed in:** `21e353e`

---

**Total deviations:** 1 auto-fixed (1 bug)
**Impact on plan:** Metrics sync was required by the repository’s canonical-count policy and did not expand product scope.

## Issues Encountered

- Parallel Plan 19-02 commits landed during execution. This plan avoided reverting or overwriting them and limited implementation writes to Plan 01 files plus required canonical metrics and GSD metadata.

## Auth Gates

None.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- Upstream Two Factor REST lifecycle bridge documentation is current and source-cited.
- Future profile-provider guard work has executable TDD cases and an explicit design-review requirement.
- Patchstack compatibility/matrix work was handled by separate Plan 19-02 scope.

## Self-Check: PASSED

- Found expected files: `bridges/wp-sudo-two-factor-lifecycle-bridge.php`, `docs/two-factor-integration.md`, `docs/current-metrics.md`, and this summary.
- Found task commits: `d37e75e`, `1e822a0`, and `21e353e`.

---
*Phase: 19-2fa-bridge-planning-and-compatibility-matrix*
*Completed: 2026-06-29*
