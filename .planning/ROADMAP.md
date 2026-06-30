# GSD Roadmap Context

> **Current routing note (2026-06-30):** Milestone v4.4.0 — Two Factor Lifecycle Bridge is active. Product release/package truth remains `../docs/release-status.md`; product roadmap truth remains `../docs/ROADMAP.md`. Latest tagged plugin release remains `v4.2.2`.

## Active Milestone: v4.4.0 — Two Factor Lifecycle Bridge

**Goal:** Close the remaining upstream WordPress Two Factor lifecycle gap by adding a narrow, source-verified profile-provider guard without blocking unrelated profile saves.

**Requirements:** 13 total in `.planning/REQUIREMENTS.md`.
**Phases:** 21–23.
**Research posture:** Broad research skipped; Phase 19 upstream Two Factor evidence is reused as seed context, but Phase 21 must refresh live upstream source before implementation.

| # | Phase | Goal | Requirements | Success Criteria |
|---|-------|------|--------------|------------------|
| 21 | Two Factor Source Refresh and Guard Design | Complete — source evidence/design captured 2026-06-30 | TFA-SRC-01, TFA-SRC-02, TFA-GUARD-06, TFA-REST-01, TFA-REST-02 | 5/5 complete |
| 22 | Profile Provider Guard Implementation | Complete — guard implementation and validation finished 2026-06-30 | TFA-GUARD-01, TFA-GUARD-02, TFA-GUARD-03, TFA-GUARD-04, TFA-GUARD-05 | 5/5 complete |
| 23 | 1/1 | Complete    | 2026-06-30 | 4 |

## Phase Details

### Phase 21: Two Factor Source Refresh and Guard Design

**Goal:** Refresh upstream source evidence and design the idempotent profile-provider predicate before production changes.

**Requirements:** TFA-SRC-01, TFA-SRC-02, TFA-GUARD-06, TFA-REST-01, TFA-REST-02

**Success criteria:**
1. Current upstream WordPress/two-factor source is checked for REST routes, classic profile form fields, nonce/action names, hooks, provider normalization, and relevant user-meta writes.
2. Code comments or planning docs cite upstream URLs plus commit/revision and checked date for any technical claims used by implementation.
3. The profile-provider predicate design distinguishes unrelated profile saves, no-op provider resubmissions, provider set changes, primary-provider changes, and TOTP enrollment/removal/replacement.
4. Existing REST lifecycle bridge unit tests for backup-code generation and TOTP create/delete are run or updated only when refreshed source requires it.
5. Patchstack remains explicitly fixture-blocked and out of implementation scope.

**Primary files likely involved:**
- `bridges/wp-sudo-two-factor-lifecycle-bridge.php`
- `tests/Unit/TwoFactorLifecycleBridgeTest.php`
- `docs/two-factor-integration.md`
- `.planning/phases/21-two-factor-source-refresh-and-guard-design/`

### Phase 22: Profile Provider Guard Implementation

**Goal:** Implement the classic profile-provider lifecycle guard with TDD and preserve existing REST bridge behavior.

**Requirements:** TFA-GUARD-01, TFA-GUARD-02, TFA-GUARD-03, TFA-GUARD-04, TFA-GUARD-05

**Plans:** 2/2 plans complete

Plans:
- [x] 22-01-PLAN.md — TDD implementation of the optional Two Factor classic profile-provider lifecycle guard.
- [x] 22-02-PLAN.md — Required validation and scope-boundary verification for the guard implementation.

**Success criteria:**
1. Tests prove unrelated `profile.php` / `user-edit.php` saves without Two Factor lifecycle fields are not gated.
2. Tests prove unchanged Two Factor provider settings are not gated after normalization.
3. Tests prove enabled-provider additions/removals and primary-provider changes require an active WP Sudo session.
4. Tests prove TOTP-backed removal/replacement and first enrollment require an active WP Sudo session when they create or alter a future sudo factor.
5. Existing REST lifecycle bridge behavior for backup codes and TOTP create/delete remains green.

**Primary files likely involved:**
- `bridges/wp-sudo-two-factor-lifecycle-bridge.php`
- `tests/Unit/TwoFactorLifecycleBridgeTest.php`
- Additional unit/integration tests if Phase 21 design identifies a better test seam

### Phase 23: Bridge Documentation and Release Posture Closure

**Goal:** Update maintainer/release docs and canonical metrics without expanding unsupported compatibility claims.

**Requirements:** TFA-DOC-01, TFA-DOC-02, TFA-DOC-03

**Plans:** 1/1 plans complete

Plans:
- [ ] 23-01-PLAN.md — Refresh Two Factor bridge documentation, Patchstack/release posture, canonical metrics, and final validation.

**Success criteria:**
1. `docs/two-factor-integration.md` accurately distinguishes challenge-time provider delegation, REST lifecycle bridge coverage, and classic profile-provider lifecycle gating.
2. `docs/two-factor-ecosystem.md` and release/readiness docs keep Patchstack Security as fixture-blocked/manual-test only unless a paid fixture has actually been tested.
3. `docs/current-metrics.md` is updated if production/test line counts, rule counts, hook counts, or other canonical metrics change.
4. Final validation includes the relevant targeted Two Factor tests plus standard repo checks required by the implementation scope.

**Primary files likely involved:**
- `docs/two-factor-integration.md`
- `docs/two-factor-ecosystem.md`
- `docs/current-metrics.md`
- `CHANGELOG.md` or release/readiness docs only if release-facing behavior changes

## Milestones

- ◆ **v4.4.0 — Two Factor Lifecycle Bridge** — Phases 21–23 (active; Phases 21–22 complete, Phase 23 next).
- ✅ **v4.3.1 — E2E Shard Rebalance** — Phase 20 (completed 2026-06-30; merged via PR #129). Archive: `.planning/milestones/v4.3.1-ROADMAP.md`.
- ✅ **v4.3.0 — Post-.org Readiness Hardening** — Phases 16-19 (archived 2026-06-29). Archive: `.planning/milestones/v4.3.0-ROADMAP.md`.
- ✅ **v4.0.0 — Pre-Public Hardening Baseline** — Phases 11-15 plus 13.1 (archived). See `.planning/MILESTONES.md`.
- ✅ **v2.13 — Security Hardening Sprint** — Phases 01-05 (archived). See `.planning/MILESTONES.md`.

## Current Status

Phases 21 and 22 are complete. Ready to discuss or plan Phase 23 documentation and release-posture closure.

## Recently Completed

<details>
<summary>✅ v4.3.1 E2E Shard Rebalance (Phase 20) — COMPLETED 2026-06-30</summary>

- [x] **Phase 20: Baseline E2E Shard Rebalance** — Refreshed current Actions evidence, moved `admin-bar-timer.spec.ts` / `TIMR` from E2E group 1 to group 2, preserved four required E2E groups and final gate, and documented GitHub CI validation.

**Archived files:**
- `.planning/milestones/v4.3.1-ROADMAP.md`
- `.planning/milestones/v4.3.1-REQUIREMENTS.md`
- `.planning/milestones/historical-phases/20-baseline-e2e-shard-rebalance/`

**Product release note:** No plugin version bump or product git tag was created. Latest tagged plugin release remains `v4.2.2`.

</details>
