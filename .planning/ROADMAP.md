# GSD Roadmap Context

> **Current routing note (2026-06-29):** Active milestone is v4.3.1 — E2E Shard Rebalance. This is a narrow CI/runtime follow-up from Phase 18, not a plugin release tag and not a WordPress.org submission milestone. Product release/package truth remains `../docs/release-status.md`; product roadmap truth remains `../docs/ROADMAP.md`.

---

# Active Roadmap: Milestone v4.3.1 — E2E Shard Rebalance

**Milestone:** v4.3.1
**Status:** Planned
**Previous milestone last phase:** 19 (2FA Bridge Planning and Compatibility Matrix, v4.3.0)
**Phase numbering continues from:** 20

**Strategic framing:** v4.3.1 closes the single Phase 18 CI follow-up by rebalancing the required E2E matrix from current GitHub Actions evidence. It should reduce the long pole without adding groups, weakening required checks, removing coverage, or touching scheduled/manual smoke workflows.

## Phases

- [ ] **Phase 20: Baseline E2E Shard Rebalance** — Refresh current Actions evidence, move one small low-risk test slice out of group 1 into the shortest suitable existing group, and verify/document the result.

## Phase Details

### Phase 20: Baseline E2E Shard Rebalance

**Goal:** The required four-group E2E matrix has a better-balanced critical path while preserving the same coverage, same required gate, and same startup-cost-aware group count.

**Depends on:** Phase 18 runtime evidence and current GitHub Actions availability.

**Requirements:** E2E-01, E2E-02, E2E-03, E2E-04, E2E-05, E2E-06, E2E-07

**Success Criteria:**
1. A fresh Actions runtime snapshot is recorded before changing `.github/workflows/e2e.yml` and identifies the current long pole plus destination group.
2. `.github/workflows/e2e.yml` still has exactly four baseline groups and the final required `E2E Tests` gate after the change.
3. The moved test slice is small and coverage-preserving: no spec is skipped, deleted, or moved to manual-only validation.
4. CI passes on the resulting branch/PR, including `E2E Tests 1/4` through `4/4` and the final gate.
5. `docs/e2e-runtime-review.md` records the implementation, validation run, and whether observed timing supports keeping the rebalance.

**Plans:** 0/1 planned

Plans:
- [ ] 20-01-PLAN.md — Refresh E2E runtime evidence, rebalance one low-risk test slice within the existing four-group matrix, and document/verify the result.

## Progress

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 20. Baseline E2E Shard Rebalance | v4.3.1 | 0/1 | Planned | - |
