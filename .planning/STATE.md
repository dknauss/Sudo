---
gsd_state_version: 1.0
milestone: Action Gate Research Program
last_updated: "2026-07-27"
status: Phase 27 active — proof handoff and XSS claim decision
---

# Planning State

## Current position

Phase 26's architecture reset landed in PR #467. Phase 27 now owns one coupled
decision: select the proof handoff by testing what active same-origin JavaScript
can do to it, then derive the permitted XSS claim from those results.

## Immediate next work

1. Write the Phase 27 handoff decision record and attacker traces.
2. Build the research-only browser spike for the candidate flows.
3. Run the read/invoke/redirect/replay/redeem/mutate/clone matrix.
4. Select a handoff and exact claim, or stop the program if none survives.
5. Do not implement the proof primitive or client integration yet.

## Decisions carried forward

- Research-only project; no production deployment.
- No automatic request replay.
- Early server veto is the security boundary.
- Preflight is progressive UX.
- Approval is action-bound and single-use.
- Registry is a possible future companion, not a prerequisite.
- The first slice covers plugin/theme upload and file-editor save only.

## Active blocker

The trusted confirmation and proof handoff under active same-origin XSS is not yet
settled. This is the Phase 27 decision gate and blocks client implementation.

## Canonical sources

- Architecture: `.planning/action-gate-architecture-charter.md`
- Requirements: `.planning/REQUIREMENTS.md`
- Phases: `.planning/ROADMAP.md`
- Public roadmap: `docs/ROADMAP.md`
- Research-use boundary: `PROJECT-STATUS.md`
- Live repository state: Git and GitHub
