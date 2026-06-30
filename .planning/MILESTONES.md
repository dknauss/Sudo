# Milestones

## v4.3.1 — E2E Shard Rebalance (Archived: 2026-06-30)

**Delivered:** Required E2E matrix shard rebalance based on current GitHub Actions timing evidence, preserving browser coverage and required-check semantics.

**Release note:** This is a GSD/CI milestone archive, not a plugin release tag. Product version metadata remains `4.2.2`; no `v4.3.1` git tag was created.

**Phases completed:** 20 (1 phase; 1 plan)

### Phase

| # | Phase | Outcome |
|---|-------|---------|
| 20 | Baseline E2E Shard Rebalance | Moved `admin-bar-timer.spec.ts` / `TIMR` from E2E group 1 to group 2 after refreshing Actions runtime evidence; PR #129 merged green. |

### Key Outcomes

- Refreshed baseline `E2E Tests` 1/4–4/4 GitHub Actions duration evidence before editing the workflow.
- Identified group 1 as the long pole and group 2 as the shortest suitable destination from current Actions data.
- Preserved exactly four required E2E groups and the final `E2E Tests` gate.
- Validated the implementation through GitHub Actions, including all four E2E shards and the final gate.
- Archived Phase 20 summary and verification in `.planning/milestones/historical-phases/20-baseline-e2e-shard-rebalance/`.

### Deferred Follow-up

- Revisit adding a fifth E2E group only if future evidence shows rebalance cannot reduce the critical path and the added startup floor is justified.
- Refresh sparse scheduled/manual smoke workflow evidence in a separate release-grade evidence pass if needed.

---
## v4.3.0 — Post-.org Readiness Hardening (Archived: 2026-06-29)

**Delivered:** Release-readiness hardening and 2FA bridge planning after the v4.2.2 package release, while keeping WordPress.org submission intentionally delayed/on hold.

**Release note:** This is a GSD milestone archive, not a plugin release tag. Product version metadata remains `4.2.2`; no `v4.3.0` git tag was created.

**Phases completed:** 16–19 (4 phases; 4 documented plans plus direct Phase 16 implementation)

### Phases

| # | Phase | Outcome |
|---|-------|---------|
| 16 | Localization and Translation Packaging Readiness | POT generation/check workflow, committed translation source, translator comments, and release docs completed |
| 17 | Release-only Environment Assurance | Release environment log, manual matrix evidence links, and pre-tag vs WordPress.org-only gate split completed |
| 18 | E2E Runtime Review and Tuning Decision | GitHub Actions E2E runtime evidence recorded; one no-coverage-loss shard-rebalance follow-up identified |
| 19 | 2FA Bridge Planning and Compatibility Matrix | Upstream Two Factor lifecycle bridge plan and Patchstack fixture-blocked manual-test target matrix completed |

### Key Outcomes

- Added release-ready localization tooling and `languages/wp-sudo.pot` checks without production dependencies.
- Preserved WordPress.org readiness while explicitly keeping publication delayed/on hold.
- Based CI tuning on measured E2E runtime evidence and deferred exactly one narrow shard-rebalance follow-up.
- Refreshed upstream WordPress/two-factor lifecycle evidence and documented a TDD-first profile-provider guard plan.
- Added Patchstack Security to the 2FA compatibility matrix as fixture-blocked/manual-test only, with no unsupported bridge-code claim.

### Deferred Follow-up

- Rebalance a small test slice out of `E2E Tests 1/4 (challenge-basic-admin)` after refreshing Actions data.
- Acquire a paid Patchstack-enabled fixture before making runtime compatibility claims or considering bridge code.

---

## v4.0.0 — Pre-Public Hardening Baseline (Archived)

**Shipped:** v4.0.0–v4.2.2
**Phases:** 6 (numbered 11–15, including inserted 13.1)
**Last phase number:** 15

### Phases

| # | Phase | Version / Status |
|---|-------|------------------|
| 11 | Connectors Registry-Aware Matcher | v4.0.0 |
| 12 | Breaking Changes and Floor Bump | v4.0.0 |
| 13 | Migration Safety and Governance Audit | v4.0.0–v4.1.0 |
| 13.1 | Access-tab UX Polish and CI Test-Speed | v4.2.2 |
| 14 | WordPress.org Readiness | v4.2.x |
| 15 | Manual Testing Environment Checklist | v4.2.x |

### Key Outcomes

- Registry-aware Connectors matcher with regex fallback.
- Removed legacy compatibility governance paths and raised floors to WordPress 6.4 / PHP 8.2.
- Hardened migration, recovery, and governance-capability behavior.
- Refreshed Access-tab UX, screenshots, release metadata, and WordPress.org readiness docs.
- Established manual environment matrix and kept WordPress.org submission delayed/on hold but ready.

## v2.13 — Security Hardening Sprint (Archived)

**Shipped:** v2.10.2–v2.13.0
**Phases:** 5 (numbered 01–05)
**Last phase number:** 05

### Phases

| # | Phase | Version |
|---|-------|---------|
| 01 | Request Stash Redaction and Upload Action Coverage | v2.10.2 |
| 02 | Non-Blocking Rate Limiting | v2.10.2 |
| 03 | Rule Schema Validation and MU Loader Resilience | v2.11.0 |
| 04 | WPGraphQL Persisted Query Strategy and WSAL Sensor | v2.11.0 |
| 05 | IP + User Multidimensional Rate Limiting | v2.13.0 |

### Key Outcomes

- Request stash redaction (passwords no longer stored in transients)
- Non-blocking rate limiting (no more PHP-FPM worker exhaustion)
- Rule-schema validation (malformed third-party rules fail closed)
- MU loader resilience (non-standard directory layouts supported)
- WPGraphQL persisted-query classification hook
- WSAL sensor bridge for enterprise audit
- IP + user multidimensional lockout policy
- 496 unit tests, 132 integration tests at completion
