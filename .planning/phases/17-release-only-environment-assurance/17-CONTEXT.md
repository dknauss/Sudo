# Phase 17 Context — Release-only Environment Assurance

## Goal

The release/manual environment matrix can be executed and recorded for the current package without implying WordPress.org submission is happening now.

## Roadmap requirements

- **REL-01:** Maintainers can run and record a manual environment matrix for the current package, including the minimum WordPress floor and at least one managed-host lane.
- **REL-02:** The WordPress.org submission checklist remains executable from the current tree, with delayed/on-hold publication status preserved.
- **REL-03:** Release-readiness docs identify checks required before tags versus checks required only before WordPress.org submission/upload.

## Current repository context

- Latest tagged release is `v4.2.2`.
- `main` has post-tag localization-readiness drift from Phase 16: WP-CLI-backed POT generation/check scripts, committed `languages/wp-sudo.pot`, and release docs updated with `composer verify:i18n`.
- WordPress.org publication remains intentionally delayed/on hold. Phase 17 must not imply submission, upload, or publication is occurring now.
- `docs/wporg-submission-checklist.md` is the operational WordPress.org readiness checklist.
- `docs/release-status.md` is the canonical current release-state document.
- `tests/MANUAL-TESTING.md` already contains a release environment matrix, but it is a run checklist only; it does not provide a durable per-version run record.

## Scope boundary

Phase 17 should create and connect release-assurance documentation. It should not:

- submit the plugin to WordPress.org;
- create or upload WordPress.org SVN assets;
- cut a new release tag;
- broaden CI or make manual lanes mandatory GitHub checks;
- provision a managed-host environment inside this thread unless separately requested.

## Decisions from discussion

### Manual matrix run records

- Add a dedicated release environment log document, expected path: `docs/release-environment-log.md`.
- Keep the executable smoke instructions in `tests/MANUAL-TESTING.md`; do not duplicate the full manual test procedure in the log.
- Record matrix outcomes per package version/tag.
- Use a quick status table per version plus short detail/caveat sections below.
- Each completed lane should record:
  - package version or commit/tag tested;
  - lane name (Apache stack, managed WordPress host, minimum supported WordPress version);
  - host/tool and site type;
  - WordPress, PHP, database, and browser versions where known;
  - smoke sections run;
  - pass/fail/deferred status;
  - relevant rewrite/auth-header/caching/security notes;
  - follow-up issue, release note, or owner/timing when applicable.
- The current `v4.2.2` package can be recorded honestly as deferred where the matrix is not being rerun now.

### Deferral policy

- Deferrals are allowed only with owner and timing.
- A deferred lane should identify whether it is acceptable for the current state or blocks the next public tag/publication decision.
- For the current `v4.2.2` status, add a deferral note rather than pretending managed-host or minimum-version lanes have been rerun.
- For future public tags, unresolved release-grade lane deferrals should be treated as blockers unless the maintainer explicitly accepts them with documented rationale.

### Pre-tag versus WordPress.org-only gates

- Split release-readiness gates into two groups:
  - **Pre-tag/core release gates:** version sync; `composer test`; `composer analyse`; `composer lint`; `composer verify:metrics`; `composer verify:i18n`; pre-release external-claim audit; changelog/readme/release-status sanity; package metadata sanity.
  - **WordPress.org-only gates:** readme validator; Plugin Check against a clean production package; SVN layout/upload; listing assets; screenshot/caption parity; slug-lock decision; final publication approval.
- The manual environment matrix is a release-grade assurance gate. It can be deferred with owner/timing when not currently releasing, but it should be satisfied or explicitly waived before a public tag/publication decision.
- Document the split in both `docs/wporg-submission-checklist.md` and `docs/release-status.md` as appropriate.

### Failure handling

- Failed or skipped critical smoke items require a reason plus a follow-up issue, release note, or explicit maintainer waiver.
- Notes-only failure records are insufficient for release-grade confidence.
- Not every failure must automatically block all work, but unresolved failures in critical smoke items should block a public tag unless explicitly waived.

## Implementation targets

1. Add `docs/release-environment-log.md` with:
   - purpose and non-submission disclaimer;
   - per-version status table;
   - current `v4.2.2` row and detail notes showing matrix lanes deferred until the next release/publication decision;
   - required evidence fields for completed lanes;
   - deferral/failure policy.
2. Update `tests/MANUAL-TESTING.md` to point release runners to the durable log and clarify that the checklist is executable evidence source, not the record of record.
3. Update `docs/wporg-submission-checklist.md` to distinguish:
   - pre-tag/core release checks;
   - WordPress.org-only submission/upload checks;
   - final publication approval remains delayed/on hold.
4. Update `docs/release-status.md` to mention the environment-log location and current `v4.2.2` deferral posture.
5. Update planning state/requirements after implementation.

## Validation expectations

Because Phase 17 is docs/planning only, targeted validation should include:

- `git diff --check`
- `composer verify:metrics` if documentation changes could affect canonical counts or release docs
- optional read-through of `docs/wporg-submission-checklist.md`, `docs/release-status.md`, `tests/MANUAL-TESTING.md`, and `docs/release-environment-log.md`

Full test/analyse/lint is not required solely for docs-only Phase 17 changes unless code or generated runtime files are touched.

## Open/non-goals

- No managed-host smoke run is being performed in this phase by default.
- No new `.org` publication date is being chosen.
- No new release tag is being cut as part of this context gathering.
