# Phase 20 Context — Baseline E2E Shard Rebalance

**Milestone:** v4.3.1 — E2E Shard Rebalance
**Created:** 2026-06-29

## Objective

Refresh current GitHub Actions runtime evidence, rebalance one small low-risk test slice out of `E2E Tests 1/4 (challenge-basic-admin)` within the existing four-group `.github/workflows/e2e.yml` matrix, and document/verify the result without coverage loss.

## Source Evidence

- `docs/e2e-runtime-review.md` records Phase 18 evidence: group 1 averaged 372.2 seconds across 9 successful post-`v4.2.2` observations, about 34% slower than groups 3/4.
- Refresh the same `gh run list` / `gh run view` job-duration table immediately before editing the workflow.

## Scope Boundaries

- Keep exactly four baseline E2E groups.
- Keep the same final required `E2E Tests` gate.
- Do not skip/delete specs or move required coverage to manual-only validation.
- Do not tune scheduled/manual smoke workflows in this phase.
- This is not a plugin release/version bump and not a WordPress.org submission step.

## Likely Planning Shape

One plan should be enough:

1. Refresh runtime evidence and choose the destination group.
2. Move the smallest suitable group-1 test slice.
3. Update `docs/e2e-runtime-review.md` with the implementation and CI evidence.
4. Verify via required GitHub CI.
