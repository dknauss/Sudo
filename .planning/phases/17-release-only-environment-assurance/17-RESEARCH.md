# Phase 17: Release-only Environment Assurance - Research

**Researched:** 2026-06-29  
**Domain:** Docs-only WordPress plugin release assurance, manual environment evidence, and WordPress.org readiness separation  
**Confidence:** HIGH

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

#### Manual matrix run records

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

#### Deferral policy

- Deferrals are allowed only with owner and timing.
- A deferred lane should identify whether it is acceptable for the current state or blocks the next public tag/publication decision.
- For the current `v4.2.2` status, add a deferral note rather than pretending managed-host or minimum-version lanes have been rerun.
- For future public tags, unresolved release-grade lane deferrals should be treated as blockers unless the maintainer explicitly accepts them with documented rationale.

#### Pre-tag versus WordPress.org-only gates

- Split release-readiness gates into two groups:
  - **Pre-tag/core release gates:** version sync; `composer test`; `composer analyse`; `composer lint`; `composer verify:metrics`; `composer verify:i18n`; pre-release external-claim audit; changelog/readme/release-status sanity; package metadata sanity.
  - **WordPress.org-only gates:** readme validator; Plugin Check against a clean production package; SVN layout/upload; listing assets; screenshot/caption parity; slug-lock decision; final publication approval.
- The manual environment matrix is a release-grade assurance gate. It can be deferred with owner/timing when not currently releasing, but it should be satisfied or explicitly waived before a public tag/publication decision.
- Document the split in both `docs/wporg-submission-checklist.md` and `docs/release-status.md` as appropriate.

#### Failure handling

- Failed or skipped critical smoke items require a reason plus a follow-up issue, release note, or explicit maintainer waiver.
- Notes-only failure records are insufficient for release-grade confidence.
- Not every failure must automatically block all work, but unresolved failures in critical smoke items should block a public tag unless explicitly waived.

### Claude's Discretion

No separate Claude's Discretion section was present in `17-CONTEXT.md`.

### Deferred Ideas (OUT OF SCOPE)

#### Open/non-goals

- No managed-host smoke run is being performed in this phase by default.
- No new `.org` publication date is being chosen.
- No new release tag is being cut as part of this context gathering.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| REL-01 | Maintainers can run and record the manual environment matrix for the current package, including the minimum WordPress floor and at least one managed-host lane. | Add `docs/release-environment-log.md` as the record of record; link it from `tests/MANUAL-TESTING.md`; record `v4.2.2` as explicitly deferred with owner/timing where no current run happens. |
| REL-02 | The WordPress.org submission checklist remains executable from the current tree, with delayed/on-hold publication status clearly preserved. | Update `docs/wporg-submission-checklist.md` without deleting existing operational checks; add clearer "ready but on hold" language and keep final approval as a hard non-submission gate. |
| REL-03 | Release-readiness docs identify which checks are required before tags versus only before `.org` submission/upload. | Split release gates into pre-tag/core release versus WordPress.org-only sections in both checklist and canonical release status docs. |
</phase_requirements>

## Summary

Phase 17 is a documentation architecture phase, not a code, CI, or live release phase. The planner should treat the work as creating one durable evidence document, then wiring three existing release/manual-testing documents to it without implying that WordPress.org publication, a managed-host run, or a new tag is happening now.

The current repository already has the executable manual smoke matrix in `tests/MANUAL-TESTING.md`, a WordPress.org operational checklist in `docs/wporg-submission-checklist.md`, and canonical release state in `docs/release-status.md`. The missing piece is a per-package release environment log that separates evidence from procedure and allows honest deferrals for `v4.2.2`.

**Primary recommendation:** Add `docs/release-environment-log.md` as the record of record, then update `tests/MANUAL-TESTING.md`, `docs/wporg-submission-checklist.md`, and `docs/release-status.md` to distinguish executable checks, durable evidence, pre-tag gates, and WordPress.org-only gates.

## Standard Stack

### Core

| Library / Tool | Version | Purpose | Why Standard |
|----------------|---------|---------|--------------|
| Markdown docs | N/A | Author release, checklist, and manual-testing documentation. | Existing project docs are Markdown; no new tooling required. |
| `tests/MANUAL-TESTING.md` | current tree | Executable smoke-test procedure and environment matrix source. | Already contains the release environment matrix lanes and smoke sections. |
| `docs/release-status.md` | current tree | Canonical volatile release-state document. | Project policy says release state belongs here before it is repeated elsewhere. |
| `docs/wporg-submission-checklist.md` | current tree | Operational WordPress.org readiness/submission checklist. | Existing checklist already tracks readme validation, Plugin Check, assets, SVN layout, brand/slug decisions, and final non-submission gate. |
| `docs/release-environment-log.md` | new docs file | Durable per-version environment evidence and deferrals. | Required by Phase 17 decisions; prevents checklist rows from becoming stale quasi-evidence. |

### Supporting

| Tool | Version | Purpose | When to Use |
|------|---------|---------|-------------|
| `git diff --check` | system git | Validate whitespace and patch hygiene for docs changes. | Always for this docs-only phase. |
| `composer verify:metrics` | project script | Ensure canonical metrics docs remain consistent if touched. | Run if `docs/current-metrics.md`, README/readme metrics, or count-sensitive docs change; Phase 17 likely does not need it unless prose drifts into counts. |
| `composer verify:i18n` | project script | Release gate for POT freshness. | Document as pre-tag/core release gate; do not require for implementing docs-only Phase 17 unless localization files change. |
| Read-through review | manual | Validate the four docs tell one non-contradictory release story. | Required because requirements are about wording, scope boundaries, and release status clarity. |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Dedicated `docs/release-environment-log.md` | Add completed/deferred rows inside `tests/MANUAL-TESTING.md` | Rejected by user decision; mixing procedure and per-version evidence makes the manual guide stale and harder to reuse. |
| Honest `v4.2.2` deferral | Invent a current run record | Rejected; project policy emphasizes anti-confabulation and live verification. |
| Docs-only validation | Full test/analyse/lint suite | Full suite is unnecessary for Markdown-only changes unless code/runtime files are touched; project E2E policy allows targeted validation for docs-only changes. |

**Installation:**

```bash
# No dependencies or install steps for Phase 17.
```

## Architecture Patterns

### Recommended Documentation Structure

```text
docs/
├── release-environment-log.md        # durable per-version lane evidence / deferrals
├── release-status.md                 # canonical current release state and gate split
└── wporg-submission-checklist.md     # executable .org readiness/upload checklist, explicitly on hold

tests/
└── MANUAL-TESTING.md                 # executable smoke procedure and matrix lanes, links to log
```

### Pattern 1: Procedure vs Evidence Separation

**What:** Keep reusable manual test instructions in `tests/MANUAL-TESTING.md`; keep per-version results, deferrals, owner/timing, and caveats in `docs/release-environment-log.md`.

**When to use:** Any time a checklist is meant to be rerun across versions but evidence must persist after the checklist is reset.

**Example:**

```markdown
## 4.2.2 — current package

| Lane | Status | Evidence / Deferral | Blocks next public tag? |
|------|--------|---------------------|--------------------------|
| Apache stack | Deferred | Owner: maintainer; Timing: before next public tag/publication decision | Yes unless waived |
```

### Pattern 2: Gate Taxonomy

**What:** Name gates by release decision point:

- **Pre-tag/core release gates:** checks that should be satisfied before a public GitHub/package tag.
- **WordPress.org-only gates:** checks needed only when submitting/uploading to WordPress.org.
- **Final publication gate:** explicit maintainer approval; remains delayed/on hold.

**When to use:** In `docs/wporg-submission-checklist.md` and `docs/release-status.md` to prevent `.org` submission tasks from being mistaken for normal pre-tag release tasks.

**Example:**

```markdown
## Pre-tag/core release gates

- [ ] Version sync
- [ ] `composer test`
- [ ] `composer analyse`
- [ ] `composer lint`
- [ ] `composer verify:metrics`
- [ ] `composer verify:i18n`
- [ ] Pre-release external-claim audit
- [ ] Manual environment matrix passed or explicitly waived/deferred
```

### Pattern 3: Canonical Source Linking

**What:** Reference volatile facts instead of copying them. Use `docs/release-status.md` for release/tag/WordPress target state and `docs/current-metrics.md` for counts.

**When to use:** Any Phase 17 prose that mentions current version, latest tag, WordPress target, or counts.

**Example:**

```markdown
Confirm the current package version, latest tag, and WordPress support posture in
[`docs/release-status.md`](release-status.md) before starting a release-grade run.
```

### Anti-Patterns to Avoid

- **Do not imply WordPress.org publication is in progress.** Keep "submission delayed/on hold" near any `.org` checklist language.
- **Do not duplicate full smoke procedures in the new log.** Link to sections in `tests/MANUAL-TESTING.md` instead.
- **Do not mark deferred lanes as passed.** Use explicit `Deferred` status with owner, timing, and whether it blocks the next release/publication decision.
- **Do not introduce automation or CI gates.** Phase boundary excludes broadening CI or making manual lanes mandatory GitHub checks.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Per-version manual evidence | A new test runner, schema, JSON format, or CI gate | Markdown `docs/release-environment-log.md` | Phase is docs-only; human-readable audit trail is enough and matches project docs. |
| WordPress.org readiness checks | Custom readme/parser/plugin validation | Official readme validator and Plugin Check, as already documented | WordPress.org checks are external and can change; use the official tools at execution time. |
| Version/release facts | Repeated hardcoded current versions in multiple docs | Links to `docs/release-status.md` | Prevents stale release claims and follows project anti-confabulation policy. |
| Manual smoke details | Copy/paste of sections 1.1, 2.1, etc. into the log | Section references to `tests/MANUAL-TESTING.md` | Avoids drift when smoke instructions evolve. |

**Key insight:** The valuable artifact is not more procedure; it is a durable, honest, per-package evidence/deferral record that can survive between releases without falsely signaling `.org` submission.

## Common Pitfalls

### Pitfall 1: Submission Readiness Sounds Like Submission Intent

**What goes wrong:** Updating `docs/wporg-submission-checklist.md` can accidentally read as "submit now."  
**Why it happens:** Pre-tag and `.org` upload tasks are currently close together in one checklist.  
**How to avoid:** Add explicit "process only; delayed/on hold" language and group WordPress.org-only tasks separately from pre-tag/core release gates.  
**Warning signs:** Phrases like "before this release is submitted" without "if/when publication is approved."

### Pitfall 2: Deferral Without Accountability

**What goes wrong:** A lane says "deferred" but gives no owner, timing, or blocking decision.  
**Why it happens:** Manual matrix execution is intentionally not happening in this phase by default.  
**How to avoid:** Require owner, timing, rationale, and "blocks next public tag/publication?" for every deferred lane.  
**Warning signs:** "TBD", "later", "not run", or blank owner fields.

### Pitfall 3: Evidence Duplicates Procedure

**What goes wrong:** The new log repeats the manual testing guide, creating two sources to maintain.  
**Why it happens:** The log needs to mention smoke sections, but not their full steps.  
**How to avoid:** Record section IDs/names and outcomes only; link to `tests/MANUAL-TESTING.md`.  
**Warning signs:** Full step-by-step browser instructions appearing in `docs/release-environment-log.md`.

### Pitfall 4: Over-validating Docs-only Changes

**What goes wrong:** Planner adds full unit/integration/E2E work to a docs-only phase.  
**Why it happens:** Project default commit hygiene is strong, but Phase 17 changes no runtime behavior.  
**How to avoid:** Use `git diff --check` plus targeted read-through; reserve full suites for code/runtime/generated-file changes.  
**Warning signs:** Plan tasks requiring MySQL, Playwright, wp-env, or managed-host provisioning to complete docs updates.

## Code Examples

Verified patterns from project and official sources:

### Release Environment Log Skeleton

```markdown
# Release Environment Log

This log records release-grade manual environment matrix outcomes for WP Sudo
packages. It is not a WordPress.org submission notice; publication remains
controlled by `docs/release-status.md` and `docs/wporg-submission-checklist.md`.

## Status by package

| Package | Date | Overall status | Notes |
|---------|------|----------------|-------|
| v4.2.2 | 2026-06-29 | Deferred | Current package; lanes deferred until next public tag/publication decision. |

## v4.2.2 details

| Lane | Status | Owner | Timing | Blocks next public tag/publication? | Notes |
|------|--------|-------|--------|-------------------------------------|-------|
| Apache stack | Deferred | Maintainer | Before next public tag/publication decision | Yes unless explicitly waived | Use `tests/MANUAL-TESTING.md` release matrix. |
```

### Manual Testing Link Pattern

```markdown
For durable per-version outcomes, record the package run in
[`docs/release-environment-log.md`](../docs/release-environment-log.md).
This manual guide remains the executable procedure and should not be treated as
the record of record.
```

### Checklist Split Pattern

```markdown
## Pre-tag/core release gates

Run these before cutting a public GitHub/package tag.

## WordPress.org-only submission/upload gates

Run these only if publication has been explicitly approved. Publication remains
delayed/on hold until the final maintainer approval gate is checked.
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| One checklist mixed pre-release validation, `.org` upload tasks, and final approval. | Separate pre-tag/core release gates from WordPress.org-only gates and final approval. | Phase 17 decision, 2026-06-29. | Reduces false implication that WordPress.org submission is happening now. |
| `tests/MANUAL-TESTING.md` matrix as run checklist only. | Dedicated `docs/release-environment-log.md` for per-version evidence and deferrals. | Phase 17 decision, 2026-06-29. | Creates durable release evidence without duplicating smoke steps. |
| Manual deferrals as loose notes. | Deferrals require owner, timing, and blocking/waiver status. | Phase 17 decision, 2026-06-29. | Prevents "deferred" from silently eroding release confidence. |

**Deprecated/outdated:**

- Treating `docs/wporg-submission-checklist.md` as a generic release checklist without a tag-vs-.org split is now outdated for this milestone.
- Recording current-package manual matrix status only in transient checklist checkboxes is insufficient for REL-01.

## Open Questions

1. **Who should be named as owner for current `v4.2.2` deferred lanes?**
   - What we know: Context says owner/timing are mandatory.
   - What's unclear: The exact owner string to use (e.g., "Maintainer" vs a named person).
   - Recommendation: Use "Maintainer" unless the planner/user supplies a named owner.

2. **Should `docs/current-metrics.md` be updated?**
   - What we know: Phase 17 should not change code or canonical counts.
   - What's unclear: Whether final prose edits will reference any count-sensitive facts.
   - Recommendation: Do not touch metrics unless implementation changes count references; run `composer verify:metrics` if it is touched.

3. **Should the manual environment matrix actually be run during implementation?**
   - What we know: Context says no managed-host smoke run is being performed by default and `v4.2.2` can be honestly deferred.
   - What's unclear: User could separately request a run.
   - Recommendation: Plan for documentation and deferral only; do not provision environments.

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Docs-only validation; existing PHP toolchain available but not required for Markdown-only edits |
| Config file | none for docs-only validation; project has `phpunit.xml.dist`, `phpstan.neon.dist`, `psalm.xml.dist`, and PHPCS config for code changes |
| Quick run command | `git diff --check` |
| Full suite command | Not required for Phase 17 docs-only scope; if code/runtime files are touched, use `composer test`, `composer analyse`, and `composer lint` as separate commands |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|--------------|
| REL-01 | Manual environment matrix has a durable current-package record or explicit owner/timing deferral. | docs review + grep smoke | `test -f docs/release-environment-log.md && grep -E "v4\\.2\\.2|Deferred|Owner|Timing" docs/release-environment-log.md` | ❌ Wave 0 |
| REL-02 | WordPress.org checklist remains executable and clearly delayed/on hold. | docs review + grep smoke | `grep -E "delayed/on hold|Do not initiate|publication" docs/wporg-submission-checklist.md` | ✅ |
| REL-03 | Release docs distinguish pre-tag checks from WordPress.org-only checks. | docs review + grep smoke | `grep -R "Pre-tag\\|WordPress.org-only" docs/wporg-submission-checklist.md docs/release-status.md` | ✅ source files; headings need update |

### Sampling Rate

- **Per task commit:** `git diff --check`
- **Per wave merge:** Read through `docs/release-environment-log.md`, `tests/MANUAL-TESTING.md`, `docs/wporg-submission-checklist.md`, and `docs/release-status.md` for contradictory status language.
- **Phase gate:** Requirements grep checks above plus final manual read-through before `/gsd:verify-work`.

### Wave 0 Gaps

- [ ] `docs/release-environment-log.md` — covers REL-01.
- [ ] Updated `tests/MANUAL-TESTING.md` link to log — supports REL-01.
- [ ] Updated `docs/wporg-submission-checklist.md` gate split — covers REL-02 and REL-03.
- [ ] Updated `docs/release-status.md` environment-log and deferral posture — covers REL-02 and REL-03.

## Sources

### Primary (HIGH confidence)

- `.planning/phases/17-release-only-environment-assurance/17-CONTEXT.md` — user decisions, scope boundaries, deferral policy, implementation targets.
- `.planning/REQUIREMENTS.md` — REL-01 through REL-03 definitions.
- `.planning/STATE.md` — current milestone status, v4.2.2 tag posture, Phase 16 drift, WordPress.org delayed/on-hold status.
- `.planning/ROADMAP.md` — Phase 17 goal, requirements, and success criteria.
- `docs/wporg-submission-checklist.md` — current WordPress.org readiness checklist structure.
- `tests/MANUAL-TESTING.md` — existing environment matrix lanes and smoke sections.
- `docs/release-status.md` — canonical release state and package metadata posture.
- WordPress Plugin Handbook: Plugin Readmes — official readme format and validator reference: <https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/>
- WordPress Plugin Handbook: Using Subversion — official trunk/tags release guidance: <https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/>
- WordPress Plugin Handbook: Plugin Assets — official asset naming/size guidance: <https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/>
- WordPress.org Plugin Check — official Plugin Check usage entry point: <https://wordpress.org/plugins/plugin-check/>

### Secondary (MEDIUM confidence)

- WordPress Plugin Developer FAQ — stable tag and slug immutability guidance: <https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/>

### Tertiary (LOW confidence)

- None used for implementation recommendations.

## Metadata

**Confidence breakdown:**

- Standard stack: HIGH — this phase is constrained to existing Markdown docs and project Composer scripts.
- Architecture: HIGH — user decisions specify the new log path, source/record split, and gate taxonomy.
- Pitfalls: HIGH — pitfalls come directly from Phase 17 scope boundaries and project anti-confabulation policy.
- External WordPress.org facts: MEDIUM-HIGH — verified against official WordPress.org/developer.wordpress.org docs, but directory policies can change before actual submission.

**Research date:** 2026-06-29  
**Valid until:** 2026-07-29 for internal docs architecture; re-check official WordPress.org docs immediately before actual submission/upload.
