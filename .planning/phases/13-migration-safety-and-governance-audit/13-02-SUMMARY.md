---
phase: 13-migration-safety-and-governance-audit
plan: 02
subsystem: docs
tags: [governance, capabilities, recovery-mode, security-docs, wordpress-plugin]

# Dependency graph
requires: []
provides:
  - "Break-glass recovery procedure documented in developer-reference.md (concrete, reproducible)"
  - "WP_SUDO_RECOVERY_MODE declared as the sole break-glass path in security-model.md"
  - "Capability-model audit outcome recorded: all surfaces gate on dedicated Sudo caps"
  - "Stale 'Compatibility mode' paragraph removed from security-model.md"
affects:
  - "13-03-migration-coverage"  # tests that cover recovery-mode lockout scenario

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Audit outcome documentation: surface-to-capability mapping table with intentional-exception callouts"

key-files:
  created: []
  modified:
    - docs/security-model.md
    - docs/developer-reference.md

key-decisions:
  - "Removed stale 'Compatibility mode' paragraph from security-model.md and replaced with 4.0.0 note stating governance is always strict"
  - "Added sole-break-glass heading to the break-glass recovery section in security-model.md"
  - "Recovery procedure placed in developer-reference.md under Migrating to 4.0 as a subsection"
  - "Capability audit note placed in Governance Capabilities section with a surface-to-cap mapping table and named exception list"

requirements-completed: [MIG-05, MIG-06]

# Metrics
duration: 35min
completed: 2026-06-17
---

# Phase 13 Plan 02: Migration Safety and Governance Audit — Documentation Summary

**Stale compatibility-mode paragraph removed from security-model.md; WP_SUDO_RECOVERY_MODE declared the sole break-glass; concrete recovery procedure and capability-model audit outcome added to developer-reference.md**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-06-17T05:33:03Z
- **Completed:** 2026-06-17T06:07:26Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments

- Removed the stale "Compatibility mode" paragraph from `docs/security-model.md` that falsely implied compatibility mode was still an available option (it was removed in 4.0.0 by Phase 12). Replaced with a 4.0.0 note directing readers to the migration guide.
- Renamed the break-glass section heading to "the sole remaining escape hatch" and added a 4.0.0 note in `docs/security-model.md`, making it unambiguous that `WP_SUDO_RECOVERY_MODE` is the only recovery path.
- Added a complete, step-by-step "Recovery from a misconfigured `manage_wp_sudo` grant" procedure to `docs/developer-reference.md` under the Migrating to 4.0 section — covers the four recovery steps, why it works, WP-CLI fallback for non-admin Sudo managers, and first-run lockout-safety guarantee.
- Added a "Capability-model audit (4.0.0)" subsection to the Governance Capabilities section of `docs/developer-reference.md` with a surface-to-capability mapping table and a named list of intentional non-gate uses of `manage_options` (break-glass authority check, meta-cap mapping, Access-tab read-only panel).

## Task Commits

1. **Task 1: Document WP_SUDO_RECOVERY_MODE sole break-glass + recovery procedure** - `0e0ae71` (docs)
2. **Task 2: Record capability-model audit outcome** - `30416c6` (docs)

**Plan metadata:** TBD (final commit, this file)

## Files Created/Modified

- `docs/security-model.md` — removed compatibility-mode paragraph, updated break-glass heading to state it is the sole escape hatch
- `docs/developer-reference.md` — added recovery procedure subsection and capability-model audit subsection

## Decisions Made

- Recovery procedure placed in the "Migrating to 4.0" section of developer-reference.md as a subsection rather than at the top level, since it is most relevant to operators who have just upgraded.
- Capability audit note placed in "Governance Capabilities" section (where `wp_sudo_can()` is documented) so developers looking at the capability API see the audit outcome in context.
- Used a surface-to-cap table for the audit note to make the mapping scannable; named exceptions explicitly so a future auditor knows what to look for vs. what is intentional.

## Deviations from Plan

None — plan executed exactly as written. Both tasks were docs-only edits to existing sections; no code changes.

## Issues Encountered

None.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- MIG-05 doc side is complete; the regression lock (unit test) is Plan 13-01 Task 3.
- MIG-06 recovery documentation is complete; first-run grant is already covered by the `upgrade_3_3_0` backfill.
- Plan 13-03 covers lockout/misconfiguration test coverage (MIG-07).

---
*Phase: 13-migration-safety-and-governance-audit*
*Completed: 2026-06-17*
