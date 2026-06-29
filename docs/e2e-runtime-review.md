# E2E Runtime Review

Collected: 2026-06-29T15:29:00Z
Repository: `dknauss/Sudo`
Post-release lower bound: `2026-06-28T18:22:50Z` (`v4.2.2` release-prep run boundary).

This review uses GitHub Actions run and job timestamps as the authoritative CI runtime evidence. No local Playwright run, browser automation, workflow edit, or WordPress.org submission checklist edit was used for this evidence pass.

## Refresh commands

Run inventory command used for each workflow file:

```bash
repo="dknauss/Sudo"
cutoff="2026-06-28T18:22:50Z"
for wf in e2e.yml e2e-nginx.yml e2e-nginx-multisite.yml e2e-sqlite.yml e2e-visual.yml; do
  gh run list -R "$repo" --workflow "$wf" --limit 20 --json databaseId,displayTitle,event,headBranch,status,conclusion,createdAt,updatedAt,url \
    | jq --arg cutoff "$cutoff" '[.[] | select(.createdAt >= $cutoff)]'
done
```

Per-run job expansion and duration calculation:

```bash
gh run view -R dknauss/Sudo "$run_id" --json databaseId,workflowName,displayTitle,event,headBranch,conclusion,createdAt,url,jobs
jq -r '.jobs[] | select(.startedAt and .completedAt) | [.name, .conclusion, ((.completedAt | fromdateiso8601) - (.startedAt | fromdateiso8601))] | @tsv'
```

Manual-dispatch refresh commands for sparse scheduled/manual workflows:

```bash
gh workflow run -R dknauss/Sudo e2e-nginx-multisite.yml --ref main
gh workflow run -R dknauss/Sudo e2e-sqlite.yml --ref main
gh workflow run -R dknauss/Sudo e2e-visual.yml --ref main
```

## Workflow inventory

| Workflow file | Workflow name | Trigger/scope | Runtime comparison note |
|---|---|---|---|
| `.github/workflows/e2e.yml` | `E2E Tests` | Required baseline matrix | Comparable jobs: E2E Tests 1/4..4/4; excludes Detect code changes and final gate job exact `E2E Tests`. |
| `.github/workflows/e2e-nginx.yml` | `E2E Nginx Smoke` | Required nginx/php-fpm smoke | Comparable job: `E2E Nginx Smoke (run)`; excludes Detect code changes and final gate job exact `E2E Nginx Smoke`. |
| `.github/workflows/e2e-nginx-multisite.yml` | `E2E Nginx Multisite Smoke` | Scheduled/manual multisite nginx smoke | Comparable job: `E2E Nginx Multisite Smoke`; scheduled/manual only. |
| `.github/workflows/e2e-sqlite.yml` | `E2E SQLite Smoke` | Scheduled/manual Playground SQLite smoke | Comparable job: `E2E Stack Smoke (Playground SQLite)`; scheduled/manual only. |
| `.github/workflows/e2e-visual.yml` | `E2E Visual Baselines` | Scheduled/manual non-blocking visual baseline | Comparable job: `E2E Visual Baselines (Apache + MariaDB, non-blocking)`; non-blocking. |

## Run inventory

| Workflow file | Run ID | Created | Event | Branch/ref | Status | Conclusion | Title | URL |
|---|---:|---|---|---|---|---|---|---|
| `.github/workflows/e2e.yml` | 28380853974 | 2026-06-29T14:49:49Z | pull_request | dependabot/github_actions/actions/cache-6 | completed | success | chore(deps): bump actions/cache from 5 to 6 | https://github.com/dknauss/Sudo/actions/runs/28380853974 |
| `.github/workflows/e2e.yml` | 28380729144 | 2026-06-29T14:47:52Z | push | main | completed | success | fix(ci): align docs lint with canonical release logs | https://github.com/dknauss/Sudo/actions/runs/28380729144 |
| `.github/workflows/e2e.yml` | 28380102621 | 2026-06-29T14:38:21Z | push | main | completed | success | docs(state): record phase 18 planned status | https://github.com/dknauss/Sudo/actions/runs/28380102621 |
| `.github/workflows/e2e.yml` | 28375124231 | 2026-06-29T13:20:58Z | push | main | completed | success | docs(state): record phase 17 context session | https://github.com/dknauss/Sudo/actions/runs/28375124231 |
| `.github/workflows/e2e.yml` | 28352652331 | 2026-06-29T06:19:09Z | pull_request | dependabot/npm_and_yarn/wordpress/env-11.9.0 | completed | success | chore(deps-dev): bump @wordpress/env from 11.8.1 to 11.9.0 | https://github.com/dknauss/Sudo/actions/runs/28352652331 |
| `.github/workflows/e2e.yml` | 28352628097 | 2026-06-29T06:18:37Z | pull_request | dependabot/npm_and_yarn/playwright/test-1.61.1 | completed | failure | chore(deps-dev): bump @playwright/test from 1.58.2 to 1.61.1 | https://github.com/dknauss/Sudo/actions/runs/28352628097 |
| `.github/workflows/e2e.yml` | 28351374488 | 2026-06-29T05:47:20Z | pull_request | dependabot/github_actions/actions/cache-6 | completed | success | chore(deps): bump actions/cache from 5 to 6 | https://github.com/dknauss/Sudo/actions/runs/28351374488 |
| `.github/workflows/e2e.yml` | 28338912849 | 2026-06-28T22:59:35Z | push | main | completed | success | docs(i18n): record phase 16 completion | https://github.com/dknauss/Sudo/actions/runs/28338912849 |
| `.github/workflows/e2e.yml` | 28332836974 | 2026-06-28T19:05:59Z | push | main | completed | success | docs: start milestone v4.3.0 post-org readiness | https://github.com/dknauss/Sudo/actions/runs/28332836974 |
| `.github/workflows/e2e.yml` | 28332772394 | 2026-06-28T19:03:33Z | push | main | completed | cancelled | docs: track patchstack 2fa compatibility | https://github.com/dknauss/Sudo/actions/runs/28332772394 |
| `.github/workflows/e2e.yml` | 28331693010 | 2026-06-28T18:22:50Z | push | main | completed | success | chore(release): prepare 4.2.2 | https://github.com/dknauss/Sudo/actions/runs/28331693010 |
| `.github/workflows/e2e-nginx.yml` | 28380854298 | 2026-06-29T14:49:50Z | pull_request | dependabot/github_actions/actions/cache-6 | completed | success | chore(deps): bump actions/cache from 5 to 6 | https://github.com/dknauss/Sudo/actions/runs/28380854298 |
| `.github/workflows/e2e-nginx.yml` | 28380729163 | 2026-06-29T14:47:52Z | push | main | completed | success | fix(ci): align docs lint with canonical release logs | https://github.com/dknauss/Sudo/actions/runs/28380729163 |
| `.github/workflows/e2e-nginx.yml` | 28380102723 | 2026-06-29T14:38:21Z | push | main | completed | success | docs(state): record phase 18 planned status | https://github.com/dknauss/Sudo/actions/runs/28380102723 |
| `.github/workflows/e2e-nginx.yml` | 28375124235 | 2026-06-29T13:20:58Z | push | main | completed | success | docs(state): record phase 17 context session | https://github.com/dknauss/Sudo/actions/runs/28375124235 |
| `.github/workflows/e2e-nginx.yml` | 28352652363 | 2026-06-29T06:19:09Z | pull_request | dependabot/npm_and_yarn/wordpress/env-11.9.0 | completed | success | chore(deps-dev): bump @wordpress/env from 11.8.1 to 11.9.0 | https://github.com/dknauss/Sudo/actions/runs/28352652363 |
| `.github/workflows/e2e-nginx.yml` | 28352628068 | 2026-06-29T06:18:37Z | pull_request | dependabot/npm_and_yarn/playwright/test-1.61.1 | completed | failure | chore(deps-dev): bump @playwright/test from 1.58.2 to 1.61.1 | https://github.com/dknauss/Sudo/actions/runs/28352628068 |
| `.github/workflows/e2e-nginx.yml` | 28351374500 | 2026-06-29T05:47:20Z | pull_request | dependabot/github_actions/actions/cache-6 | completed | success | chore(deps): bump actions/cache from 5 to 6 | https://github.com/dknauss/Sudo/actions/runs/28351374500 |
| `.github/workflows/e2e-nginx.yml` | 28338912827 | 2026-06-28T22:59:35Z | push | main | completed | success | docs(i18n): record phase 16 completion | https://github.com/dknauss/Sudo/actions/runs/28338912827 |
| `.github/workflows/e2e-nginx.yml` | 28332836984 | 2026-06-28T19:05:59Z | push | main | completed | success | docs: start milestone v4.3.0 post-org readiness | https://github.com/dknauss/Sudo/actions/runs/28332836984 |
| `.github/workflows/e2e-nginx.yml` | 28332772398 | 2026-06-28T19:03:33Z | push | main | completed | success | docs: track patchstack 2fa compatibility | https://github.com/dknauss/Sudo/actions/runs/28332772398 |
| `.github/workflows/e2e-nginx.yml` | 28331692982 | 2026-06-28T18:22:50Z | push | main | completed | success | chore(release): prepare 4.2.2 | https://github.com/dknauss/Sudo/actions/runs/28331692982 |
| `.github/workflows/e2e-nginx-multisite.yml` | — | — | — | — | — | — | No post-cutoff runs found in `--limit 20` inventory. | — |
| `.github/workflows/e2e-sqlite.yml` | — | — | — | — | — | — | No post-cutoff runs found in `--limit 20` inventory. | — |
| `.github/workflows/e2e-visual.yml` | 28352129620 | 2026-06-29T06:07:10Z | schedule | main | completed | success | E2E Visual Baselines | https://github.com/dknauss/Sudo/actions/runs/28352129620 |

## Successful comparable job runtimes

Gate/helper jobs excluded from this table: `Detect code changes`, exact `E2E Tests`, and exact `E2E Nginx Smoke`. Only successful jobs from successful runs are included here.

| Workflow | Run ID | Created | Event | Branch/ref | Job/group | Seconds | Minutes | URL |
|---|---:|---|---|---|---|---:|---:|---|
| E2E Nginx Smoke | 28331692982 | 2026-06-28T18:22:50Z | push | main | E2E Nginx Smoke (run) | 99 | 1.65 | https://github.com/dknauss/Sudo/actions/runs/28331692982 |
| E2E Nginx Smoke | 28332772398 | 2026-06-28T19:03:33Z | push | main | E2E Nginx Smoke (run) | 97 | 1.62 | https://github.com/dknauss/Sudo/actions/runs/28332772398 |
| E2E Nginx Smoke | 28332836984 | 2026-06-28T19:05:59Z | push | main | E2E Nginx Smoke (run) | 98 | 1.63 | https://github.com/dknauss/Sudo/actions/runs/28332836984 |
| E2E Nginx Smoke | 28338912827 | 2026-06-28T22:59:35Z | push | main | E2E Nginx Smoke (run) | 100 | 1.67 | https://github.com/dknauss/Sudo/actions/runs/28338912827 |
| E2E Nginx Smoke | 28351374500 | 2026-06-29T05:47:20Z | pull_request | dependabot/github_actions/actions/cache-6 | E2E Nginx Smoke (run) | 99 | 1.65 | https://github.com/dknauss/Sudo/actions/runs/28351374500 |
| E2E Nginx Smoke | 28352652363 | 2026-06-29T06:19:09Z | pull_request | dependabot/npm_and_yarn/wordpress/env-11.9.0 | E2E Nginx Smoke (run) | 96 | 1.60 | https://github.com/dknauss/Sudo/actions/runs/28352652363 |
| E2E Nginx Smoke | 28375124235 | 2026-06-29T13:20:58Z | push | main | E2E Nginx Smoke (run) | 90 | 1.50 | https://github.com/dknauss/Sudo/actions/runs/28375124235 |
| E2E Nginx Smoke | 28380102723 | 2026-06-29T14:38:21Z | push | main | E2E Nginx Smoke (run) | 95 | 1.58 | https://github.com/dknauss/Sudo/actions/runs/28380102723 |
| E2E Nginx Smoke | 28380729163 | 2026-06-29T14:47:52Z | push | main | E2E Nginx Smoke (run) | 100 | 1.67 | https://github.com/dknauss/Sudo/actions/runs/28380729163 |
| E2E Nginx Smoke | 28380854298 | 2026-06-29T14:49:50Z | pull_request | dependabot/github_actions/actions/cache-6 | E2E Nginx Smoke (run) | 108 | 1.80 | https://github.com/dknauss/Sudo/actions/runs/28380854298 |
| E2E Tests | 28331693010 | 2026-06-28T18:22:50Z | push | main | E2E Tests 1/4 (challenge-basic-admin) | 357 | 5.95 | https://github.com/dknauss/Sudo/actions/runs/28331693010 |
| E2E Tests | 28331693010 | 2026-06-28T18:22:50Z | push | main | E2E Tests 2/4 (challenge-2fa-ui) | 229 | 3.82 | https://github.com/dknauss/Sudo/actions/runs/28331693010 |
| E2E Tests | 28331693010 | 2026-06-28T18:22:50Z | push | main | E2E Tests 3/4 (challenge-lockout-surfaces) | 272 | 4.53 | https://github.com/dknauss/Sudo/actions/runs/28331693010 |
| E2E Tests | 28331693010 | 2026-06-28T18:22:50Z | push | main | E2E Tests 4/4 (challenge-replay-multisite) | 268 | 4.47 | https://github.com/dknauss/Sudo/actions/runs/28331693010 |
| E2E Tests | 28332836974 | 2026-06-28T19:05:59Z | push | main | E2E Tests 1/4 (challenge-basic-admin) | 347 | 5.78 | https://github.com/dknauss/Sudo/actions/runs/28332836974 |
| E2E Tests | 28332836974 | 2026-06-28T19:05:59Z | push | main | E2E Tests 2/4 (challenge-2fa-ui) | 242 | 4.03 | https://github.com/dknauss/Sudo/actions/runs/28332836974 |
| E2E Tests | 28332836974 | 2026-06-28T19:05:59Z | push | main | E2E Tests 3/4 (challenge-lockout-surfaces) | 279 | 4.65 | https://github.com/dknauss/Sudo/actions/runs/28332836974 |
| E2E Tests | 28332836974 | 2026-06-28T19:05:59Z | push | main | E2E Tests 4/4 (challenge-replay-multisite) | 307 | 5.12 | https://github.com/dknauss/Sudo/actions/runs/28332836974 |
| E2E Tests | 28338912849 | 2026-06-28T22:59:35Z | push | main | E2E Tests 1/4 (challenge-basic-admin) | 386 | 6.43 | https://github.com/dknauss/Sudo/actions/runs/28338912849 |
| E2E Tests | 28338912849 | 2026-06-28T22:59:35Z | push | main | E2E Tests 2/4 (challenge-2fa-ui) | 251 | 4.18 | https://github.com/dknauss/Sudo/actions/runs/28338912849 |
| E2E Tests | 28338912849 | 2026-06-28T22:59:35Z | push | main | E2E Tests 3/4 (challenge-lockout-surfaces) | 299 | 4.98 | https://github.com/dknauss/Sudo/actions/runs/28338912849 |
| E2E Tests | 28338912849 | 2026-06-28T22:59:35Z | push | main | E2E Tests 4/4 (challenge-replay-multisite) | 265 | 4.42 | https://github.com/dknauss/Sudo/actions/runs/28338912849 |
| E2E Tests | 28351374488 | 2026-06-29T05:47:20Z | pull_request | dependabot/github_actions/actions/cache-6 | E2E Tests 1/4 (challenge-basic-admin) | 354 | 5.90 | https://github.com/dknauss/Sudo/actions/runs/28351374488 |
| E2E Tests | 28351374488 | 2026-06-29T05:47:20Z | pull_request | dependabot/github_actions/actions/cache-6 | E2E Tests 2/4 (challenge-2fa-ui) | 217 | 3.62 | https://github.com/dknauss/Sudo/actions/runs/28351374488 |
| E2E Tests | 28351374488 | 2026-06-29T05:47:20Z | pull_request | dependabot/github_actions/actions/cache-6 | E2E Tests 3/4 (challenge-lockout-surfaces) | 302 | 5.03 | https://github.com/dknauss/Sudo/actions/runs/28351374488 |
| E2E Tests | 28351374488 | 2026-06-29T05:47:20Z | pull_request | dependabot/github_actions/actions/cache-6 | E2E Tests 4/4 (challenge-replay-multisite) | 280 | 4.67 | https://github.com/dknauss/Sudo/actions/runs/28351374488 |
| E2E Tests | 28352652331 | 2026-06-29T06:19:09Z | pull_request | dependabot/npm_and_yarn/wordpress/env-11.9.0 | E2E Tests 1/4 (challenge-basic-admin) | 402 | 6.70 | https://github.com/dknauss/Sudo/actions/runs/28352652331 |
| E2E Tests | 28352652331 | 2026-06-29T06:19:09Z | pull_request | dependabot/npm_and_yarn/wordpress/env-11.9.0 | E2E Tests 2/4 (challenge-2fa-ui) | 240 | 4.00 | https://github.com/dknauss/Sudo/actions/runs/28352652331 |
| E2E Tests | 28352652331 | 2026-06-29T06:19:09Z | pull_request | dependabot/npm_and_yarn/wordpress/env-11.9.0 | E2E Tests 3/4 (challenge-lockout-surfaces) | 274 | 4.57 | https://github.com/dknauss/Sudo/actions/runs/28352652331 |
| E2E Tests | 28352652331 | 2026-06-29T06:19:09Z | pull_request | dependabot/npm_and_yarn/wordpress/env-11.9.0 | E2E Tests 4/4 (challenge-replay-multisite) | 274 | 4.57 | https://github.com/dknauss/Sudo/actions/runs/28352652331 |
| E2E Tests | 28375124231 | 2026-06-29T13:20:58Z | push | main | E2E Tests 1/4 (challenge-basic-admin) | 340 | 5.67 | https://github.com/dknauss/Sudo/actions/runs/28375124231 |
| E2E Tests | 28375124231 | 2026-06-29T13:20:58Z | push | main | E2E Tests 2/4 (challenge-2fa-ui) | 236 | 3.93 | https://github.com/dknauss/Sudo/actions/runs/28375124231 |
| E2E Tests | 28375124231 | 2026-06-29T13:20:58Z | push | main | E2E Tests 3/4 (challenge-lockout-surfaces) | 250 | 4.17 | https://github.com/dknauss/Sudo/actions/runs/28375124231 |
| E2E Tests | 28375124231 | 2026-06-29T13:20:58Z | push | main | E2E Tests 4/4 (challenge-replay-multisite) | 274 | 4.57 | https://github.com/dknauss/Sudo/actions/runs/28375124231 |
| E2E Tests | 28380102621 | 2026-06-29T14:38:21Z | push | main | E2E Tests 1/4 (challenge-basic-admin) | 418 | 6.97 | https://github.com/dknauss/Sudo/actions/runs/28380102621 |
| E2E Tests | 28380102621 | 2026-06-29T14:38:21Z | push | main | E2E Tests 2/4 (challenge-2fa-ui) | 251 | 4.18 | https://github.com/dknauss/Sudo/actions/runs/28380102621 |
| E2E Tests | 28380102621 | 2026-06-29T14:38:21Z | push | main | E2E Tests 3/4 (challenge-lockout-surfaces) | 280 | 4.67 | https://github.com/dknauss/Sudo/actions/runs/28380102621 |
| E2E Tests | 28380102621 | 2026-06-29T14:38:21Z | push | main | E2E Tests 4/4 (challenge-replay-multisite) | 292 | 4.87 | https://github.com/dknauss/Sudo/actions/runs/28380102621 |
| E2E Tests | 28380729144 | 2026-06-29T14:47:52Z | push | main | E2E Tests 1/4 (challenge-basic-admin) | 371 | 6.18 | https://github.com/dknauss/Sudo/actions/runs/28380729144 |
| E2E Tests | 28380729144 | 2026-06-29T14:47:52Z | push | main | E2E Tests 2/4 (challenge-2fa-ui) | 227 | 3.78 | https://github.com/dknauss/Sudo/actions/runs/28380729144 |
| E2E Tests | 28380729144 | 2026-06-29T14:47:52Z | push | main | E2E Tests 3/4 (challenge-lockout-surfaces) | 266 | 4.43 | https://github.com/dknauss/Sudo/actions/runs/28380729144 |
| E2E Tests | 28380729144 | 2026-06-29T14:47:52Z | push | main | E2E Tests 4/4 (challenge-replay-multisite) | 256 | 4.27 | https://github.com/dknauss/Sudo/actions/runs/28380729144 |
| E2E Tests | 28380853974 | 2026-06-29T14:49:49Z | pull_request | dependabot/github_actions/actions/cache-6 | E2E Tests 1/4 (challenge-basic-admin) | 375 | 6.25 | https://github.com/dknauss/Sudo/actions/runs/28380853974 |
| E2E Tests | 28380853974 | 2026-06-29T14:49:49Z | pull_request | dependabot/github_actions/actions/cache-6 | E2E Tests 2/4 (challenge-2fa-ui) | 254 | 4.23 | https://github.com/dknauss/Sudo/actions/runs/28380853974 |
| E2E Tests | 28380853974 | 2026-06-29T14:49:49Z | pull_request | dependabot/github_actions/actions/cache-6 | E2E Tests 3/4 (challenge-lockout-surfaces) | 282 | 4.70 | https://github.com/dknauss/Sudo/actions/runs/28380853974 |
| E2E Tests | 28380853974 | 2026-06-29T14:49:49Z | pull_request | dependabot/github_actions/actions/cache-6 | E2E Tests 4/4 (challenge-replay-multisite) | 272 | 4.53 | https://github.com/dknauss/Sudo/actions/runs/28380853974 |
| E2E Visual Baselines | 28352129620 | 2026-06-29T06:07:10Z | schedule | main | E2E Visual Baselines (Apache + MariaDB, non-blocking) | 193 | 3.22 | https://github.com/dknauss/Sudo/actions/runs/28352129620 |

## Successful runtime aggregates

| Workflow | Job/group | Observations | Min seconds | Max seconds | Average seconds | Average minutes | Median seconds |
|---|---|---:|---:|---:|---:|---:|---:|
| E2E Nginx Smoke | E2E Nginx Smoke (run) | 10 | 90 | 108 | 98.2 | 1.64 | 98.5 |
| E2E Tests | E2E Tests 1/4 (challenge-basic-admin) | 9 | 340 | 418 | 372.2 | 6.20 | 371.0 |
| E2E Tests | E2E Tests 2/4 (challenge-2fa-ui) | 9 | 217 | 254 | 238.6 | 3.98 | 240.0 |
| E2E Tests | E2E Tests 3/4 (challenge-lockout-surfaces) | 9 | 250 | 302 | 278.2 | 4.64 | 279.0 |
| E2E Tests | E2E Tests 4/4 (challenge-replay-multisite) | 9 | 256 | 307 | 276.4 | 4.61 | 274.0 |
| E2E Visual Baselines | E2E Visual Baselines (Apache + MariaDB, non-blocking) | 1 | 193 | 193 | 193.0 | 3.22 | 193.0 |

## Non-successful or excluded-from-performance observations

These rows are recorded for auditability but are not used to decide green-run long poles.

| Workflow | Run ID | Run conclusion | Job/group | Job conclusion | Seconds | Minutes | Notes |
|---|---:|---|---|---|---:|---:|---|
| E2E Nginx Smoke | 28352628068 | failure | E2E Nginx Smoke (run) | failure | 324 | 5.40 | Failed/cancelled/dependency-bump or interrupted run; excluded from successful green-run performance comparison. |
| E2E Tests | 28332772394 | cancelled | E2E Tests 1/4 (challenge-basic-admin) | cancelled | 165 | 2.75 | Failed/cancelled/dependency-bump or interrupted run; excluded from successful green-run performance comparison. |
| E2E Tests | 28332772394 | cancelled | E2E Tests 2/4 (challenge-2fa-ui) | cancelled | 165 | 2.75 | Failed/cancelled/dependency-bump or interrupted run; excluded from successful green-run performance comparison. |
| E2E Tests | 28332772394 | cancelled | E2E Tests 3/4 (challenge-lockout-surfaces) | cancelled | 155 | 2.58 | Failed/cancelled/dependency-bump or interrupted run; excluded from successful green-run performance comparison. |
| E2E Tests | 28332772394 | cancelled | E2E Tests 4/4 (challenge-replay-multisite) | cancelled | 163 | 2.72 | Failed/cancelled/dependency-bump or interrupted run; excluded from successful green-run performance comparison. |
| E2E Tests | 28352628097 | failure | E2E Tests 1/4 (challenge-basic-admin) | failure | 681 | 11.35 | Failed/cancelled/dependency-bump or interrupted run; excluded from successful green-run performance comparison. |
| E2E Tests | 28352628097 | failure | E2E Tests 2/4 (challenge-2fa-ui) | success | 281 | 4.68 | Failed/cancelled/dependency-bump or interrupted run; excluded from successful green-run performance comparison. |
| E2E Tests | 28352628097 | failure | E2E Tests 3/4 (challenge-lockout-surfaces) | failure | 676 | 11.27 | Failed/cancelled/dependency-bump or interrupted run; excluded from successful green-run performance comparison. |
| E2E Tests | 28352628097 | failure | E2E Tests 4/4 (challenge-replay-multisite) | failure | 772 | 12.87 | Failed/cancelled/dependency-bump or interrupted run; excluded from successful green-run performance comparison. |

## Evidence gaps

- `E2E Nginx Multisite Smoke` / `.github/workflows/e2e-nginx-multisite.yml`: zero successful post-cutoff observations found. Keep this as a sparse scheduled/manual evidence gap and rerun with `gh workflow run -R dknauss/Sudo e2e-nginx-multisite.yml --ref main` when current data is needed.
- `E2E SQLite Smoke` / `.github/workflows/e2e-sqlite.yml`: zero successful post-cutoff observations found. Keep this as a sparse scheduled/manual evidence gap and rerun with `gh workflow run -R dknauss/Sudo e2e-sqlite.yml --ref main` when current data is needed.
- `E2E Visual Baselines` / `.github/workflows/e2e-visual.yml`: one successful post-cutoff observation found. Record it, but do not declare a repeatable long pole from a single scheduled/manual data point.
- Failed/cancelled dependency-bump observations are intentionally separated above so they do not distort successful-run runtime comparisons.

## Findings

### Baseline `E2E Tests` matrix

`E2E Tests 1/4 (challenge-basic-admin)` is the repeatable long pole in the successful post-`v4.2.2` baseline matrix:

- 9 successful comparable observations, 340-418 seconds, 372.2 seconds average.
- The next-slowest baseline groups averaged 278.2 seconds (`3/4`) and 276.4 seconds (`4/4`).
- Group 1 was about 33.8% slower than group 3 and about 34.6% slower than group 4 by average runtime, crossing the Phase 18 materiality threshold of about 25%.
- In the 6 successful `push`/`main` observations, group 1 averaged 369.8 seconds versus 274.3-277.0 seconds for groups 3 and 4, so the finding is not limited to dependency PR variance.

This is enough evidence to treat the group-1 imbalance as real, but it does not justify adding a fifth E2E group, removing specs, changing required checks, or moving coverage to manual-only validation. The existing workflow comments already prefer rebalancing inside the four current groups before adding parallelism because each group pays the fixed `wp-env` startup floor.

### Smoke and scheduled/manual workflows

- `E2E Nginx Smoke (run)` is stable in successful runs: 10 observations, 90-108 seconds, 98.2 seconds average.
- `E2E Visual Baselines (Apache + MariaDB, non-blocking)` has one successful scheduled observation at 193 seconds; it is recorded but not enough to infer a repeatable long pole.
- `E2E Nginx Multisite Smoke` and `E2E SQLite Smoke` have no post-cutoff observations in the refreshed inventory. This is an evidence gap, not a failure of the current required CI path.

## Final decision

**One proposed follow-up:** Rebalance the existing four-group baseline `E2E Tests` matrix by moving a small, low-risk test slice out of `E2E Tests 1/4 (challenge-basic-admin)` into a shorter existing group, preferably group 2 or whichever group is shortest in the follow-up refresh.

Scope constraints for that follow-up:

- preserve release-grade E2E coverage with no coverage loss;
- keep the same four groups and the same required `E2E Tests` gate;
- do not remove specs, skip surfaces, or move coverage to manual-only validation;
- do not change scheduled/manual smoke workflows as part of the rebalance.

This plan does **not** implement the workflow change. It records the evidence-based decision only; the actual test-slice movement should happen in a separate narrow CI follow-up after refreshing the same `gh run view` job-duration table.

### Phase follow-up note

- **Evidence:** 9 successful post-cutoff baseline observations show `E2E Tests 1/4 (challenge-basic-admin)` averaging 372.2 seconds, about 34% slower than the next comparable baseline groups and repeatably on the critical path.
- **Rationale:** A small rebalance within the current four groups can reduce the long pole without adding startup overhead, changing required checks, or sacrificing coverage.
- **Owner/timing:** Maintainer / next CI-speed follow-up before the next release-grade tuning pass; re-run the refresh commands in this document immediately before editing `.github/workflows/e2e.yml`.

## Phase 20 refresh — E2E shard rebalance implementation

Collected: 2026-06-29T22:10:22Z
Repository: `dknauss/Sudo`
Workflow: `.github/workflows/e2e.yml` / `E2E Tests`
Implementation branch: `ci/e2e-shard-rebalance`

This refresh was collected immediately before editing `.github/workflows/e2e.yml`. It uses GitHub Actions job timestamps as the source of truth; local Playwright timing assumptions were not used.

### Phase 20 refresh commands

```bash
gh run list -R dknauss/Sudo --workflow e2e.yml --limit 20 --json databaseId,displayTitle,event,headBranch,status,conclusion,createdAt,updatedAt,url
RUN_ID=<run id from successful comparable run>
gh run view "$RUN_ID" -R dknauss/Sudo --json databaseId,workflowName,displayTitle,event,headBranch,conclusion,createdAt,url,jobs
```

Successful completed workflow runs were expanded with `gh run view "$RUN_ID" -R dknauss/Sudo --json jobs`; comparable jobs are only `E2E Tests 1/4` through `4/4` from successful workflow runs. `Detect code changes` and the exact final gate job named `E2E Tests` remain excluded from runtime aggregation.

### Phase 20 refreshed run inventory

| Run ID | Created | Event | Branch/ref | 1/4 seconds | 2/4 seconds | 3/4 seconds | 4/4 seconds | URL |
|---:|---|---|---|---:|---:|---:|---:|---|
| 28405201903 | 2026-06-29T21:54:21Z | push | main | 411 | 258 | 345 | 292 | https://github.com/dknauss/Sudo/actions/runs/28405201903 |
| 28404488595 | 2026-06-29T21:40:25Z | push | main | 458 | 279 | 353 | 312 | https://github.com/dknauss/Sudo/actions/runs/28404488595 |
| 28403154219 | 2026-06-29T21:14:55Z | push | main | 400 | 278 | 354 | 305 | https://github.com/dknauss/Sudo/actions/runs/28403154219 |
| 28403078588 | 2026-06-29T21:13:30Z | pull_request | dependabot/github_actions/actions/cache-6 | 418 | 304 | 317 | 305 | https://github.com/dknauss/Sudo/actions/runs/28403078588 |
| 28396183980 | 2026-06-29T19:08:40Z | pull_request | dependabot/npm_and_yarn/playwright/test-1.61.1 | 400 | 261 | 328 | 302 | https://github.com/dknauss/Sudo/actions/runs/28396183980 |
| 28393595457 | 2026-06-29T18:21:46Z | push | main | 416 | 236 | 293 | 286 | https://github.com/dknauss/Sudo/actions/runs/28393595457 |
| 28384568687 | 2026-06-29T15:46:40Z | pull_request | dependabot/npm_and_yarn/wordpress/env-11.9.0 | 385 | 256 | 295 | 271 | https://github.com/dknauss/Sudo/actions/runs/28384568687 |
| 28380853974 | 2026-06-29T14:49:49Z | pull_request | dependabot/github_actions/actions/cache-6 | 375 | 254 | 282 | 272 | https://github.com/dknauss/Sudo/actions/runs/28380853974 |

### Phase 20 refreshed aggregates

| Job/group | Observations | Min seconds | Max seconds | Average seconds | Median seconds |
|---|---:|---:|---:|---:|---:|
| E2E Tests 1/4 (challenge-basic-admin) | 8 | 375 | 458 | 407.9 | 405.5 |
| E2E Tests 2/4 (challenge-2fa-ui) | 8 | 236 | 304 | 265.8 | 259.5 |
| E2E Tests 3/4 (challenge-lockout-surfaces) | 8 | 282 | 354 | 320.9 | 322.5 |
| E2E Tests 4/4 (challenge-replay-multisite) | 8 | 271 | 312 | 293.1 | 297.0 |

### Phase 20 rebalance decision

Current long pole: `E2E Tests 1/4 (challenge-basic-admin)` at 407.9 seconds average across 8 successful comparable observations.

Destination group: `E2E Tests 2/4 (challenge-2fa-ui)`, the shortest suitable existing group at 265.8 seconds average. The selected low-risk slice is `tests/e2e/specs/admin-bar-timer.spec.ts` / `TIMR`, moved from group 1 to group 2.

Rationale: Group 1 remains materially slower than the other baseline groups, while group 2 is consistently shortest in the refreshed data. Moving the timer spec preserves coverage, keeps the same required final `E2E Tests` gate, and stays within the existing four groups so the workflow does not add another fixed `wp-env` startup floor.

Implementation: `.github/workflows/e2e.yml` now removes `admin-bar-timer.spec.ts` and `TIMR` from group 1 and adds `admin-bar-timer.spec.ts` to group 2's second command. No spec is skipped, deleted, or moved to manual-only validation.
