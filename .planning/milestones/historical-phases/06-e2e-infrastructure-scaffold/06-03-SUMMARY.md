---
phase: 06-e2e-infrastructure-scaffold
plan: 03
subsystem: infra
tags: [playwright, github-actions, ci, wp-env, e2e]

requires:
  - phase: 06-01
    provides: package.json, .nvmrc, .wp-env.json — consumed by the CI workflow via setup-node and wp-env start
  - phase: 06-02
    provides: playwright.config.ts, smoke.spec.ts, npm run test:e2e script — invoked by e2e.yml

provides:
  - Standalone GitHub Actions workflow (.github/workflows/e2e.yml) for Playwright E2E CI
  - .gitignore updated with Playwright artifacts, wp-env state, and storageState files

affects: [07-core-e2e-tests, 08-keyboard-a11y]

tech-stack:
  added: []
  patterns:
    - Separate CI workflow file per test stack (E2E vs PHPUnit never share jobs)
    - ubuntu-24.04 runner with Docker for wp-env + Chromium-only Playwright install
    - Artifact upload on !cancelled() with if-no-files-found ignore

key-files:
  created: [.github/workflows/e2e.yml]
  modified: [.gitignore]

key-decisions:
  - "Separate workflow file (TOOL-03): e2e.yml is a sibling of phpunit.yml with zero shared jobs, steps, or environment variables — cross-workflow dependencies not supported in GitHub Actions."
  - "Chromium-only Playwright install (--with-deps chromium) saves ~500MB vs full browser suite; sufficient for functional E2E."
  - "Wait loop polls wp-login.php up to 60s to address wp-env cold-start latency (Pitfall 5)."
  - "Artifact upload uses !cancelled() (not failure()) so reports capture both flaky and hard-fail runs."
  - "No PHP matrix for E2E: browser behavior is PHP-version-agnostic; one WP version (from .wp-env.json) is sufficient."

patterns-established:
  - "E2E CI triggers on push/PR to main, same pattern as phpunit.yml — no nightly schedule until test suite is larger."
  - "wp-env stop runs with if: always() for guaranteed cleanup regardless of test outcome."

duration: 15 min
completed: 2026-03-08
---

# Phase 6 Plan 03: CI Workflow Summary

**Standalone GitHub Actions E2E workflow using actions/checkout@v6, setup-node@v4 with .nvmrc, wp-env Docker start, Playwright Chromium, and artifact upload on failure**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-03-08T21:30:00Z
- **Completed:** 2026-03-08T21:45:00Z
- **Tasks:** 2/2 completed (Task 2 was completed in the prior Wave 2 session)
- **Files modified:** 2

## Accomplishments

- Created `.github/workflows/e2e.yml` as a standalone CI pipeline for Playwright E2E tests.
- Verified YAML syntax, absence of PHP/Composer references (TOOL-03), and presence of all four key step patterns.
- Confirmed `phpunit.yml` has zero diff — the two CI pipelines are completely independent.
- `.gitignore` exclusions for Playwright artifacts, test-results, wp-env state, and storageState files were committed in Wave 2 (`de6ca85`) and are confirmed in place.

## Task Commits

Both tasks in this plan were handled across two sessions:

1. **Task 2: Update .gitignore** — committed as part of Wave 2 (`de6ca85`, `chore(06-02): add Playwright config, global-setup, fixture, and smoke test`)
2. **Task 1: Create .github/workflows/e2e.yml** — file created in this session, pending commit

**Note:** Per instructions, no commit was made in this session — the orchestrator handles the final commit.

## Files Created/Modified

- `.github/workflows/e2e.yml` — Standalone CI workflow: checkout, setup-node (reads .nvmrc), npm ci, Playwright Chromium install with deps, wp-env start, 60s WordPress readiness poll, `npm run test:e2e`, artifact upload on !cancelled(), wp-env stop.
- `.gitignore` — Added `tests/e2e/artifacts/`, `playwright-report/`, `test-results/`, `.wp-env/` (committed in prior session with Wave 2).

## Decisions Made

- Action versions match `phpunit.yml` exactly: `actions/checkout@v6`, `actions/setup-node@v4`, `actions/upload-artifact@v7`. No version drift between workflows.
- The plan specifies `actions/checkout@v6` and `actions/upload-artifact@v7`, both of which are already used in `phpunit.yml` — no adjustment was needed.
- `timeout-minutes: 30` is generous but bounded: wp-env start (~90s) + Chromium install (~90s) + test run leaves ample headroom.

## Deviations from Plan

### Verification Note

The plan's verification step `grep -c 'wp-env start\|playwright test\|playwright install\|upload-artifact'` expects count `4`, but the actual count is `3`. This is because the workflow uses `npm run test:e2e` (as the plan's own Key Decisions section specifies) rather than a bare `playwright test` command. All four functional capabilities are present — the grep pattern for `playwright test` simply doesn't match the npm script invocation. This is an inconsistency in the plan's verification check, not a gap in the workflow.

The plan's core requirement ("npm run test:e2e: Uses the npm script from package.json, which points to `playwright test --config tests/e2e/playwright.config.ts`") is fully satisfied.

---

**Total deviations:** 0 code changes. 1 verification note (plan grep pattern vs actual npm script invocation — no code change needed).
**Impact on plan:** None. All success criteria met.

## Issues Encountered

None.

## User Setup Required

None — no external service configuration required. The workflow will run automatically on push or PR to main once committed.

## Next Phase Readiness

Phase 6 is now complete. All three plans are done:
- 06-01 (Wave 1): Toolchain scaffold — package.json, .nvmrc, .wp-env.json, tsconfig.json
- 06-02 (Wave 2): Playwright config, global-setup, fixtures, smoke test
- 06-03 (Wave 3): CI workflow, .gitignore

Phase 7 (Core E2E Tests) is next. It will build on this infrastructure to write the functional E2E test suite covering the reauth challenge flow, gate interception, session expiry, and the admin settings page.

---
*Phase: 06-e2e-infrastructure-scaffold*
*Completed: 2026-03-08*
