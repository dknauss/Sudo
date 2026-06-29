# Phase 18: E2E Runtime Review and Tuning Decision - Context

**Gathered:** 2026-06-29
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 18 collects and records recent GitHub Actions E2E runtime evidence, then documents either one narrow no-coverage-loss tuning action or a no-change decision. The goal is evidence-based CI tuning after the `v4.2.2` release, not assumed slowness.

This phase does **not** implement broad CI restructuring by default, does not reduce release-grade E2E coverage, does not add or remove required checks without a separately planned change, and does not affect WordPress.org submission/publication status.

</domain>

<decisions>
## Implementation Decisions

### Runtime evidence scope

- GitHub Actions is the authoritative evidence source for Phase 18 runtime decisions.
- Review runs after the `v4.2.2` release/tag context on 2026-06-28.
- Cover all current E2E-related workflows, not only the baseline required check:
  - `E2E Tests` / `.github/workflows/e2e.yml`
  - `E2E Nginx Smoke` / `.github/workflows/e2e-nginx.yml`
  - `E2E Nginx Multisite Smoke` / `.github/workflows/e2e-nginx-multisite.yml`
  - `E2E SQLite Smoke` / `.github/workflows/e2e-sqlite.yml`
  - `E2E Visual Baselines` / `.github/workflows/e2e-visual.yml`
- If post-`v4.2.2` run data is missing or sparse for some workflows, do not block the phase solely on that absence. Record the evidence gap and include exact `gh`/API commands maintainers can rerun when data exists.

### Long-pole decision rule

- Decide from explicit E2E job/group durations, not only whole-workflow wall time.
- Treat a long pole as material when a job/group is at least ~25% slower than comparable E2E peers or repeatably dominates the workflow's critical path.
- Prefer at least two recent completed observations before declaring a long pole real. If only one completed run exists, the plan may record it with a caveat rather than overfitting to runner variance.
- A no-change decision is acceptable when the groups are within the material threshold, evidence is sparse/inconclusive, or any speed gain would add more complexity than it removes.

### Evidence record format

- Add a dedicated durable review document, expected path: `docs/e2e-runtime-review.md`.
- Use tables plus a clear decision section:
  - workflow/run inventory;
  - job/group runtime table;
  - finding for each E2E workflow;
  - final decision: no-change or exactly one proposed low-risk tuning action.
- Include provenance sufficient to refresh or audit the data:
  - run IDs/URLs;
  - run dates;
  - branch/ref/status/conclusion;
  - exact commands used to collect or refresh the evidence.
- Link the dedicated review doc from release/planning context, specifically `docs/release-status.md` and `.planning/ROADMAP.md`. Do not add it to `docs/wporg-submission-checklist.md` by default; Phase 18 is CI-tuning evidence, not a WordPress.org submission gate.

### Allowed tuning response

- If a material long pole exists, propose exactly one narrow, low-risk follow-up action.
- Any proposed tuning action must preserve release-grade E2E coverage. Prefer rebalancing, caching, or scheduling/trigger clarity over dropping E2E surfaces.
- Do not trim E2E coverage or move CI coverage to manual checks as part of this phase.
- If evidence does not show a material long pole, document no-change and avoid adding CI complexity.
- If a tuning action is proposed but not implemented immediately, track it as a concrete GSD todo/phase note with the evidence, rationale, and expected owner/timing. Opening a GitHub issue is optional future workflow, not required for Phase 18.

### Implementation Discretion

- Exact `gh` command flags, JSON processing approach, and table column ordering are implementation discretion as long as the resulting record is auditable and reproducible.
- The planner may choose whether Phase 18 implementation is one docs-only plan or split into evidence collection plus documentation/state update, provided the phase remains small and evidence-driven.

</decisions>

<specifics>
## Specific Ideas

- Phase 13.1 previously found the E2E groups were roughly balanced around a fixed wp-env startup floor, with group 2 historically a modest outlier. Phase 18 should verify current data instead of assuming that old finding still holds.
- Use GitHub Actions run/job evidence rather than local Playwright timings because the question is CI wall-clock/runner behavior.
- The final review should be explicit enough that a future maintainer can say: "No change was made because evidence did not justify complexity" or "One action was proposed because this specific group was materially slower."

</specifics>

<code_context>
## Existing Code Insights

### Reusable Assets

- `.github/workflows/e2e.yml`: baseline E2E matrix with four `e2e-group` jobs named `challenge-basic-admin`, `challenge-2fa-ui`, `challenge-lockout-surfaces`, and `challenge-replay-multisite`; includes comments noting the fixed wp-env startup floor and that rebalancing inside four groups should come before adding more parallelism.
- `.github/workflows/e2e-nginx.yml`: required/gated nginx smoke workflow with a code-change detector and a final gate job named `E2E Nginx Smoke`.
- `.github/workflows/e2e-nginx-multisite.yml`: scheduled/manual multisite nginx smoke workflow.
- `.github/workflows/e2e-sqlite.yml`: scheduled/manual Playground SQLite smoke workflow.
- `.github/workflows/e2e-visual.yml`: scheduled/manual non-blocking visual baseline workflow.
- `package.json`: E2E scripts (`test:e2e`, `test:e2e:all`, `test:e2e:stack-smoke`, `test:e2e:multisite:stack-smoke`, `test:e2e:visual`) identify the command surfaces behind the workflows.

### Established Patterns

- Docs-only or evidence-only phases should use targeted validation first; full Playwright execution is not required unless implementation changes E2E behavior or workflow files.
- Prior CI-speed work prefers measured evidence and explicit coverage tradeoff documentation over speculative optimization.
- The repo already distinguishes release-readiness gates from WordPress.org publication gates; Phase 18 should preserve that separation.

### Integration Points

- `docs/e2e-runtime-review.md` should become the durable evidence/decision record for this phase.
- `docs/release-status.md` should link to the E2E runtime review as current release-readiness/CI evidence, without turning it into a `.org` submission step.
- `.planning/ROADMAP.md`, `.planning/REQUIREMENTS.md`, and `.planning/STATE.md` should be updated after execution to show CI-01 through CI-03 complete if the review and decision are recorded.

</code_context>

<deferred>
## Deferred Ideas

- Implementing a CI workflow change, such as a shard rebalance or cache adjustment, is deferred unless Phase 18 evidence clearly justifies exactly one narrow no-coverage-loss follow-up and the plan scopes it explicitly.
- Dropping E2E coverage, moving coverage to manual release checks, or changing required GitHub checks is out of scope for Phase 18 and would need a separate phase or explicit maintainer decision.
- Opening a GitHub issue for any proposed tuning action is optional; the required tracking mechanism for this phase is a GSD todo/phase note.

</deferred>

---

*Phase: 18-e2e-runtime-review-and-tuning-decision*
*Context gathered: 2026-06-29*
