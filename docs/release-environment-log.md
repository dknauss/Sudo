# Release Environment Log

This file is the durable record of release-grade manual environment matrix outcomes for WP Sudo packages.

It records what was actually run, what was deferred, who owns follow-up, and whether any deferral blocks the next release or publication decision. It does **not** perform a WordPress.org upload, managed-host provisioning, release tag, or publication approval. WordPress.org submission remains intentionally delayed/on hold until the maintainer explicitly approves publication.

Keep the executable smoke-test procedure in [`tests/MANUAL-TESTING.md`](../tests/MANUAL-TESTING.md). This log is the record of record for per-version outcomes; do not duplicate the full smoke steps here.

## Status by package

| Package/version | Date | Overall status | Summary | WordPress.org posture |
|-----------------|------|----------------|---------|-----------------------|
| `v4.2.2` | 2026-06-29 | Deferred | Release environment matrix was documented for future execution; lanes were not rerun in Phase 17. | Submission/upload remains delayed/on hold. |

## `v4.2.2` environment matrix

The `v4.2.2` package already exists, but Phase 17 did not rerun release-grade manual environment lanes. Each row below is therefore an explicit deferral, not evidence of a completed smoke run.

| Environment lane | Status | Owner | Timing | Blocks next public tag/publication decision? | Notes |
|------------------|--------|-------|--------|---------------------------------------------|-------|
| Apache stack | Deferred | Maintainer | Before next public tag/publication decision | Yes unless explicitly waived | Run the core smoke set from the manual guide on DDEV, MAMP, Local, or an Apache staging host and record evidence here. |
| Managed WordPress host | Deferred | Maintainer | Before next public tag/publication decision | Yes unless explicitly waived | Provision or use an approved staging/trial managed WordPress host only when the maintainer chooses to execute the release-grade lane. |
| Minimum supported WordPress version | Deferred | Maintainer | Before next public tag/publication decision | Yes unless explicitly waived | Confirm the current floor in `docs/release-status.md`, run the manual smoke set there, and record exact WordPress/PHP/site-mode evidence here. |

## Required evidence for completed lanes

When a lane is completed, add or update a dated package row with:

- package/version and run date
- environment lane and host/tool name
- WordPress version, PHP version, database engine/version, and web server where available
- site mode (single-site or multisite)
- browser and operating system for browser-admin checks
- smoke sections run from `tests/MANUAL-TESTING.md`
- pass/fail/deferred result for each lane
- rewrite/auth-header, cache, mu-plugin, filesystem, or host-policy observations
- runner/owner name and follow-up issue or waiver link for any skipped item

## Deferral and failure policy

A deferred or failed lane must include all of the following before the release decision can proceed:

1. **Owner** — the person or role responsible for resolution.
2. **Timing** — when the lane will be run, retried, or intentionally waived.
3. **Follow-up or waiver** — a linked issue, release note, or explicit maintainer waiver.
4. **Blocker posture** — whether it blocks the next public tag/publication decision.

Unless a maintainer explicitly records a waiver, deferred release-grade lanes block the next public tag/publication decision. WordPress.org-only upload/submission work remains separate and on hold until publication approval.
