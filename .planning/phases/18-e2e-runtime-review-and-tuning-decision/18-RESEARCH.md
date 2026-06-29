# Phase 18: E2E Runtime Review and Tuning Decision - Research

**Researched:** 2026-06-29  
**Domain:** GitHub Actions E2E runtime evidence collection and CI tuning decision documentation  
**Confidence:** HIGH for repository/workflow facts and GitHub Actions access; MEDIUM for current long-pole conclusion because CI data is time-sensitive and should be refreshed during execution.

<user_constraints>

## User Constraints (from CONTEXT.md)

### Locked Decisions

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

### Claude's Discretion

- Exact `gh` command flags, JSON processing approach, and table column ordering are implementation discretion as long as the resulting record is auditable and reproducible.
- The planner may choose whether Phase 18 implementation is one docs-only plan or split into evidence collection plus documentation/state update, provided the phase remains small and evidence-driven.

### Deferred Ideas (OUT OF SCOPE)

- Implementing a CI workflow change, such as a shard rebalance or cache adjustment, is deferred unless Phase 18 evidence clearly justifies exactly one narrow no-coverage-loss follow-up and the plan scopes it explicitly.
- Dropping E2E coverage, moving coverage to manual release checks, or changing required GitHub checks is out of scope for Phase 18 and would need a separate phase or explicit maintainer decision.
- Opening a GitHub issue for any proposed tuning action is optional; the required tracking mechanism for this phase is a GSD todo/phase note.

</user_constraints>

<phase_requirements>

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| CI-01 | The explicit E2E group runtimes from recent GitHub Actions runs are reviewed and recorded before any further CI tuning. | Use `gh run list` + `gh run view --json jobs` against all five E2E workflow files; compute job duration from each job's `startedAt` and `completedAt`; record run IDs, URLs, dates, refs, status, conclusion, and refresh commands. |
| CI-02 | If a new E2E long pole exists, the next tuning action is scoped narrowly and preserves release-grade full-suite coverage. | Apply the locked ~25%/critical-path rule only to comparable successful E2E jobs; if the baseline `E2E Tests 1/4` long pole persists, propose exactly one low-risk no-coverage-loss shard rebalance as a follow-up, not broad CI restructuring. |
| CI-03 | If no material long pole exists, the decision to avoid additional CI complexity is documented. | The review doc must include a final decision section that explicitly says "no change" when evidence is balanced, sparse, or complexity outweighs speed gain; link that decision from `docs/release-status.md` and `.planning/ROADMAP.md`. |

</phase_requirements>

## Summary

Phase 18 should be planned as an evidence-and-decision documentation phase, not a CI rewrite. The authoritative data source is GitHub Actions for `dknauss/Sudo`; local Playwright timings are useful for debugging but are not sufficient for the phase goal because the decision is about CI wall-clock behavior, runner variance, skipped jobs, and workflow critical paths.

The current session is authenticated to GitHub CLI as `dknauss`, and `gh` can read `dknauss/Sudo` Actions data. A live access check on 2026-06-29 found multiple successful post-`v4.2.2` baseline `E2E Tests` and `E2E Nginx Smoke` runs, one post-tag `E2E Visual Baselines` run, and no post-tag completed `E2E Nginx Multisite Smoke` or `E2E SQLite Smoke` runs. Preliminary successful-main-push data shows `E2E Tests 1/4 (challenge-basic-admin)` repeatably longest at ~340-386s, about 29% slower than the next-slowest baseline E2E peer on average. The planner should require Phase 18 execution to refresh this data before finalizing the durable review.

**Primary recommendation:** Plan one docs-first implementation plan: collect/refresh GitHub Actions job-level runtimes, create `docs/e2e-runtime-review.md`, link it from release/planning docs, and either document no-change or propose exactly one low-risk follow-up shard rebalance if the refreshed data still shows the baseline group-1 long pole.

## Standard Stack

### Core

| Tool | Version | Purpose | Why Standard |
|------|---------|---------|--------------|
| GitHub Actions run/job data | Live API via GitHub CLI | Authoritative source for workflow/job status, dates, URLs, and job timings | The phase decision is about CI behavior; Actions data avoids guessing from local runs. |
| `gh` CLI | 2.93.0 | Query workflow runs and jobs with `gh run list` / `gh run view --json` | Already authenticated in this session; exposes `jobs`, `createdAt`, `startedAt`, `updatedAt`, `url`, `workflowName`, and conclusions. |
| `jq` | 1.7.1 | Filter runs, calculate durations from ISO timestamps, generate tables | Small, repeatable, auditable; no repo dependency needed. |
| Repository workflow files | Current tree | Define the groups/jobs that the review must cover | Prevents missing scheduled/manual workflows or comparing gate jobs instead of test jobs. |

### Supporting

| Tool/File | Version | Purpose | When to Use |
|-----------|---------|---------|-------------|
| `package.json` E2E scripts | `@playwright/test` 1.58.2; `@wordpress/env` 11.8.1 | Map workflow jobs to underlying test commands | Use to explain what a group runs and to propose any low-risk rebalancing action. |
| `docs/release-status.md` | Last verified 2026-06-29 | Canonical release-state link target | Update with a short link to the runtime review after the phase doc exists. |
| `.planning/ROADMAP.md` | Current milestone roadmap | Planning-state link target | Update Phase 18 status/decision link after the review exists. |
| `gh api` | via `gh` 2.93.0 | Fallback access to REST endpoints | Use if `gh run view --json jobs` is insufficient or maintainers want raw REST output. |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `gh run view --json jobs` | GitHub REST API `GET /repos/{owner}/{repo}/actions/runs/{run_id}/jobs` | REST is more explicit and stable for automation, but `gh run view` is faster and enough for this small docs phase. Include both refresh paths if desired. |
| Job-level durations | Whole-workflow `createdAt` → `updatedAt` wall time | Whole-workflow wall time hides matrix skew and gate/skip behavior; only use as secondary context. |
| Actions data | Local `npm run test:e2e...` timings | Local timings miss runner startup, Docker/wp-env cost, queue behavior, and scheduled workflow variance. Do not use for CI tuning decisions. |

**Installation:** No new dependencies. Use the existing developer environment:

```bash
gh --version
jq --version
```

## Current Evidence Access Check

Commands run during research:

```bash
gh auth status
gh repo view --json nameWithOwner,url,visibility --jq '.'
```

Result: authenticated as `dknauss` with `repo` and `workflow` scopes; repository resolved as `dknauss/Sudo`.

Preliminary post-`v4.2.2` snapshot window used for research: `createdAt >= 2026-06-28T18:22:50Z` (the `v4.2.2` release-prep push run; annotated tag date observed locally as 2026-06-28 12:12:47 -0600). Refresh during implementation because Actions data changes.

### Main-push successful E2E job summary observed 2026-06-29

| Workflow/job | Observations | Min | Max | Avg | Finding |
|--------------|--------------|-----|-----|-----|---------|
| `E2E Tests 1/4 (challenge-basic-admin)` | 4 | 340s | 386s | 357.5s | Repeatably longest baseline group; material candidate. |
| `E2E Tests 2/4 (challenge-2fa-ui)` | 4 | 229s | 251s | 239.5s | Shortest baseline group. |
| `E2E Tests 3/4 (challenge-lockout-surfaces)` | 4 | 250s | 299s | 275.0s | Mid-pack baseline group. |
| `E2E Tests 4/4 (challenge-replay-multisite)` | 4 | 265s | 307s | 278.5s | Mid-pack baseline group. |
| `E2E Nginx Smoke (run)` | 5 | 90s | 100s | 96.8s | Stable; no long pole within that workflow. |

Interpretation: group 1 is ~28.4% slower than group 4 and ~30.0% slower than group 3 in main-push successful runs, crossing the locked ~25% materiality threshold and dominating the baseline `E2E Tests` critical path in every successful main-push observation. Treat this as a preliminary finding only; the implementation plan should refresh data and decide from the final table.

### Sparse or missing post-tag data observed 2026-06-29

| Workflow | Post-tag completed observations found | Planning implication |
|----------|----------------------------------------|----------------------|
| `E2E Nginx Multisite Smoke` | 0 | Record evidence gap; include rerun commands. Do not block the phase solely on absence. |
| `E2E SQLite Smoke` | 0 | Record evidence gap; include rerun commands. Do not block the phase solely on absence. |
| `E2E Visual Baselines` | 1 successful scheduled run, 193s | Record with caveat; do not overfit because there is only one post-tag observation. |

## Architecture Patterns

### Recommended Documentation Structure

```text
docs/
├── e2e-runtime-review.md      # Durable evidence tables + final decision
└── release-status.md          # Short link to the review, not duplicated data

.planning/
├── ROADMAP.md                 # Phase 18 status/link/decision summary
├── REQUIREMENTS.md            # Mark CI-01..CI-03 complete after execution
└── STATE.md                   # Record phase completion and next focus
```

### Pattern 1: Job-Level Runtime Collection

**What:** Collect recent workflow runs per workflow file, then expand each run to jobs and compute `completedAt - startedAt` per E2E job/group.

**When to use:** Always for Phase 18. Whole-workflow duration is secondary only.

**Example:**

```bash
repo="dknauss/Sudo"
since="2026-06-28T18:22:50Z"
workflow="e2e.yml"

gh run list -R "$repo" --workflow "$workflow" --limit 20 \
  --json databaseId,createdAt,status,conclusion,url \
  --jq ".[] | select(.createdAt >= \"$since\" and .status == \"completed\") | .databaseId"
```

Then for each run ID:

```bash
gh run view -R dknauss/Sudo "$run_id" \
  --json databaseId,workflowName,displayTitle,event,headBranch,conclusion,createdAt,url,jobs \
  --jq '. as $r
    | $r.jobs[]
    | select(.name | test("E2E|Smoke|Visual"))
    | select(.name | test("Detect code changes|^E2E Tests$|^E2E Nginx Smoke$") | not)
    | {
        run_id: $r.databaseId,
        workflow: $r.workflowName,
        title: $r.displayTitle,
        event: $r.event,
        branch: $r.headBranch,
        run_conclusion: $r.conclusion,
        createdAt: $r.createdAt,
        url: $r.url,
        job: .name,
        job_conclusion: .conclusion,
        seconds: ((.completedAt | fromdateiso8601) - (.startedAt | fromdateiso8601))
      }'
```

### Pattern 2: Evidence Table Before Decision

**What:** Write the durable review in this order: collection method, workflow inventory, run inventory, job/group runtime table, findings by workflow, final decision.

**When to use:** Always. This lets future maintainers audit whether the decision followed the evidence.

**Example final-decision shape:**

```markdown
## Decision

**Decision:** Propose one follow-up: rebalance the existing four-group baseline
E2E matrix by moving a small test slice from group 1 to group 2. Do not add
parallel groups or reduce coverage.

**Why:** Group 1 was >=25% slower than comparable baseline groups in N/N
recent successful observations and dominated the workflow critical path.

**Not doing:** No coverage reduction, no required-check change, no manual-only
replacement, no broad workflow restructuring.
```

If refreshed data no longer supports a material long pole, the same section should explicitly say no change.

### Pattern 3: Link, Don't Duplicate

**What:** Put detailed data in `docs/e2e-runtime-review.md`; link to it from `docs/release-status.md` and `.planning/ROADMAP.md`.

**When to use:** Always. This follows the repo's existing canonical-doc pattern and avoids duplicated stale statistics.

### Anti-Patterns to Avoid

- **Scraping the GitHub Actions web UI:** brittle and unauditable; use `gh`/API.
- **Comparing required gate jobs to real test jobs:** `E2E Tests` and `E2E Nginx Smoke` gate jobs are near-zero-duration status checks; exclude them from long-pole analysis.
- **Treating failed dependency-bump runs as baseline performance:** failed Playwright upgrade runs can show long failure timeouts; record separately if relevant but do not use them as comparable green-run performance.
- **Adding parallelism before rebalancing:** `e2e.yml` already documents a fixed wp-env startup floor; adding more groups can increase runner-minutes without lowering useful critical path enough.
- **Updating `docs/wporg-submission-checklist.md`:** locked out by context; Phase 18 is release-readiness CI evidence, not a WordPress.org submission gate.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| GitHub Actions data access | HTML scraper or browser automation | `gh run list`, `gh run view --json jobs`, or `gh api` | Official data path, includes stable IDs/URLs/timestamps, reproducible in docs. |
| Duration calculations | Manual stopwatch math in prose | `jq` `fromdateiso8601` on `startedAt`/`completedAt` | Avoids arithmetic errors and preserves refreshability. |
| CI speed conclusion | Anecdotal "E2E feels slow" narrative | Threshold-based table with N observations | The phase goal is measured evidence. |
| Tuning response | Broad workflow redesign | Exactly one narrow no-coverage-loss proposal, if justified | Keeps CI complexity bounded and honors user constraints. |

**Key insight:** The hard part is not collecting one duration; it is deciding what counts as comparable evidence. Filter out skipped/gate jobs, cancelled runs, and dependency-failure timeouts before applying the long-pole threshold.

## Common Pitfalls

### Pitfall 1: Whole-Workflow Wall Time Hides Matrix Skew

**What goes wrong:** A workflow may look acceptable overall while one matrix group dominates the critical path.  
**Why it happens:** `updatedAt - createdAt` includes queue/setup/gate behavior and does not expose matrix imbalance.  
**How to avoid:** Use job-level `startedAt`/`completedAt` for `E2E Tests N/4 (...)` jobs.  
**Warning signs:** The review table only lists workflow run durations, not group names.

### Pitfall 2: Gate Jobs Pollute Runtime Tables

**What goes wrong:** The required `E2E Tests` and `E2E Nginx Smoke` gate jobs appear as 2-4s jobs and distort averages.  
**Why it happens:** They are real jobs in the workflow but not E2E test groups.  
**How to avoid:** Exclude `Detect code changes`, exact `E2E Tests`, and exact `E2E Nginx Smoke`; include `E2E Nginx Smoke (run)`.  
**Warning signs:** The fastest "E2E group" is only a few seconds.

### Pitfall 3: Sparse Scheduled Workflows

**What goes wrong:** Scheduled/manual workflows may have zero or one post-tag run, causing false precision.  
**Why it happens:** `e2e-nginx-multisite.yml` and `e2e-sqlite.yml` run only on `workflow_dispatch` and weekly schedule; `e2e-visual.yml` is scheduled/manual and non-blocking.  
**How to avoid:** Record the gap and exact rerun commands; do not block Phase 18 solely on missing post-tag data.  
**Warning signs:** A final decision claims "balanced" for a workflow with no observations.

### Pitfall 4: Failed Dependency Runs Are Not Comparable Green Performance

**What goes wrong:** A dependency-bump PR failure is treated as normal runtime evidence.  
**Why it happens:** Failed runs still have completed jobs and durations; Playwright failures may wait for retries/timeouts.  
**How to avoid:** Separate failed/cancelled runs from successful-run performance. Use failed runs only as context for dependency risk.  
**Warning signs:** The long-pole table includes the `@playwright/test` 1.61.1 failure beside successful `main` pushes without caveat.

### Pitfall 5: Proposing Coverage Reduction

**What goes wrong:** The "tuning" response drops E2E surfaces or moves coverage to manual release checks.  
**Why it happens:** Runtime pressure tempts coverage trimming.  
**How to avoid:** Locked context forbids trimming. If a long pole exists, propose rebalancing/caching/scheduling clarity only.  
**Warning signs:** The proposed action removes a spec file, changes required checks, or makes release-grade E2E manual-only.

## Code Examples

Verified patterns from repository workflows and `gh` CLI help.

### Workflow Inventory

```bash
for wf in e2e.yml e2e-nginx.yml e2e-nginx-multisite.yml e2e-sqlite.yml e2e-visual.yml; do
  gh run list -R dknauss/Sudo --workflow "$wf" --limit 10 \
    --json databaseId,displayTitle,event,headBranch,status,conclusion,createdAt,updatedAt,url \
    --jq '.[] | select(.createdAt >= "2026-06-28T18:22:50Z")'
done
```

### Summary Statistics from JSONL

```bash
grep '^{.*}' runtime-snapshot.jsonl > runtime-snapshot-only.jsonl

jq -s '
  map(select(.run_conclusion == "success" and .job_conclusion == "success"))
  | group_by(.job)
  | map({
      job: .[0].job,
      n: length,
      min: (map(.seconds) | min),
      max: (map(.seconds) | max),
      avg: (((map(.seconds) | add) / length) * 10 | round / 10)
    })
' runtime-snapshot-only.jsonl
```

### Manual Dispatch Commands for Sparse Workflows

Use only if the maintainer wants fresh evidence and accepts the runner cost:

```bash
gh workflow run -R dknauss/Sudo e2e-nginx-multisite.yml --ref main
gh workflow run -R dknauss/Sudo e2e-sqlite.yml --ref main
gh workflow run -R dknauss/Sudo e2e-visual.yml --ref main
```

Then record the resulting run IDs and job durations with the same `gh run view --json jobs` path.

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Assume the old Phase 13.1 group-2 modest outlier still applies | Refresh current GitHub Actions job durations after `v4.2.2` | Phase 18 context, 2026-06-29 | Avoid stale CI optimization decisions. |
| Optimize from local Playwright runtime | Optimize only from GitHub Actions job/group evidence | Locked Phase 18 decision | Keeps decisions aligned with actual CI wall-clock and runner behavior. |
| Add shards/parallelism when E2E is slow | Rebalance inside the existing four groups before more parallelism | Documented in `.github/workflows/e2e.yml` | Respects wp-env startup floor and avoids extra runner complexity. |
| Treat no-change as inaction | Document no-change as a deliberate evidence-based decision | Phase 18 goal | Prevents recurring speculative CI churn. |

**Deprecated/outdated:**

- "E2E is slow, so add parallel jobs" — outdated for this repo because `e2e.yml` explicitly notes a fixed wp-env startup floor.
- "Use workflow run duration only" — insufficient because Phase 18 requires explicit group runtimes.

## Open Questions

1. **Should Phase 18 trigger manual scheduled workflows to fill gaps?**
   - What we know: Context says missing/sparse data should not block the phase; exact rerun commands are enough.
   - What's unclear: Whether the maintainer wants to spend runner minutes now.
   - Recommendation: Do not require dispatch in the plan. Include optional dispatch commands and record "no post-tag run found" if not run.

2. **Should the one low-risk action be implemented or only proposed?**
   - What we know: Success criteria require a proposed action if a long pole exists; deferred ideas say implementation is deferred unless evidence clearly justifies exactly one narrow follow-up and the plan scopes it explicitly.
   - What's unclear: Whether the planner should include an actual workflow edit in Phase 18.
   - Recommendation: Default to proposal-only documentation. If refreshed evidence still shows group 1 as material, propose a follow-up rebalance from group 1 to group 2; do not modify workflows unless the plan explicitly adds that one narrow action.

3. **Which observation set should drive the final threshold: all successful runs or main-push only?**
   - What we know: Main-push successful runs are most comparable; successful PR runs add data but dependency PRs can change runtime characteristics.
   - What's unclear: Whether to include successful Dependabot PRs in the final average.
   - Recommendation: Use main-push successful runs as primary; include all successful runs as secondary context with branch/event columns.

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Docs/evidence validation with POSIX shell + `grep`; existing PHP unit safety net via Composer/PHPUnit 9.6 |
| Config file | `composer.json` scripts; `.github/workflows/e2e*.yml`; no new test config required |
| Quick run command | Run each separately: `test -f docs/e2e-runtime-review.md`; `grep -q "Run ID" docs/e2e-runtime-review.md`; `grep -q "Final decision" docs/e2e-runtime-review.md`; `grep -q "docs/e2e-runtime-review.md" docs/release-status.md`; `grep -q "e2e-runtime-review.md" .planning/ROADMAP.md` |
| Full suite command | Run separately if docs touch is low-risk but release confidence is desired: `composer test`; `composer analyse` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|--------------|
| CI-01 | Runtime review doc records explicit GitHub Actions run/job evidence and refresh commands. | docs smoke | `grep -q "gh run view" docs/e2e-runtime-review.md` | ❌ Wave 0 |
| CI-02 | If material long pole exists, exactly one no-coverage-loss tuning action is proposed. | docs smoke/manual review | `grep -Eq "exactly one|one follow-up|one proposed" docs/e2e-runtime-review.md` plus manual check that no coverage is removed | ❌ Wave 0 |
| CI-03 | If no material long pole exists, no-change decision is documented. | docs smoke/manual review | `grep -Eq "no-change|no change|Final decision" docs/e2e-runtime-review.md` | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** Run the quick docs-smoke commands separately.
- **Per wave merge:** Run `composer test` and `composer analyse` separately if any non-doc files changed; for docs-only changes, targeted docs-smoke plus `git diff --check` is sufficient under the repo E2E validation policy.
- **Phase gate:** `docs/e2e-runtime-review.md` exists, contains reproducible GitHub Actions provenance, links are present in `docs/release-status.md` and `.planning/ROADMAP.md`, and CI-01 through CI-03 are marked complete only after the decision is recorded.

### Wave 0 Gaps

- [ ] `docs/e2e-runtime-review.md` — covers CI-01, CI-02, CI-03.
- [ ] Link from `docs/release-status.md` — release-readiness context.
- [ ] Link/status update in `.planning/ROADMAP.md` — planning context.
- [ ] Requirement/state updates in `.planning/REQUIREMENTS.md` and `.planning/STATE.md` after the decision is complete.

## Sources

### Primary (HIGH confidence)

- `.planning/phases/18-e2e-runtime-review-and-tuning-decision/18-CONTEXT.md` — locked user decisions, long-pole rule, evidence format, out-of-scope items.
- `.planning/REQUIREMENTS.md` — CI-01, CI-02, CI-03 descriptions and traceability.
- `.planning/STATE.md` and `.planning/ROADMAP.md` — current milestone state, Phase 18 goal, neighboring phases.
- `.github/workflows/e2e.yml` — baseline four-group matrix, fixed wp-env startup-floor comment, required gate job pattern.
- `.github/workflows/e2e-nginx.yml`, `.github/workflows/e2e-nginx-multisite.yml`, `.github/workflows/e2e-sqlite.yml`, `.github/workflows/e2e-visual.yml` — current E2E-related workflow inventory.
- `package.json` — current E2E scripts and dev dependency versions.
- `gh run list` / `gh run view --json jobs` live output for `dknauss/Sudo` on 2026-06-29 — current Actions run/job evidence access.
- GitHub CLI manual: <https://cli.github.com/manual/gh_run_view> and <https://cli.github.com/manual/gh_run_list> — JSON fields and command behavior.
- GitHub REST API workflow jobs docs: <https://docs.github.com/en/rest/actions/workflow-jobs> — official fallback endpoint for jobs in a workflow run.

### Secondary (MEDIUM confidence)

- `docs/release-status.md` — canonical release-state context for `v4.2.2` and post-tag drift, verified locally on 2026-06-29.
- `docs/current-metrics.md` — current E2E test count and metrics context; useful for avoiding duplicated volatile counts.

### Tertiary (LOW confidence)

- None used for implementation guidance. No community/SEO sources are needed for this phase.

## Metadata

**Confidence breakdown:**

- Standard stack: HIGH — repo files and authenticated `gh` CLI verified.
- Architecture: HIGH — documentation/link pattern follows locked context and existing canonical-doc policy.
- Runtime evidence snapshot: MEDIUM — live GitHub Actions data is temporally unstable and must be refreshed during execution.
- Pitfalls: HIGH — derived from current workflow structure and observed run/job output.

**Research date:** 2026-06-29  
**Valid until:** 2026-07-06 for runtime evidence; documentation architecture remains stable until workflows change.
