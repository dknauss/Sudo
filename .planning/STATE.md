---
gsd_state_version: 1.0
milestone: Action Gate Research Program
last_updated: "2026-07-27"
status: Phase 26 active — architecture reset and evidence inventory
---

# Planning State

## Current position

Phase 26 has begun. The architecture charter, requirements, and phased roadmap
have been drafted. They are based on the research-prototype posture introduced by
PR #466 and should land after or with that status change, not inside its atomic
commit.

## Immediate next work

1. Complete the active-document disposition inventory.
2. Mark the broad core implementation spec and proposal sections that still
   prescribe server-carried requests or registry-first work as superseded.
3. Reconcile `docs/ROADMAP.md` and `docs/README.md` with this program.
4. Triage open core-gate and post-4.9 issues into the new phases or deferred work.
5. Prepare Phase 27 context and research documents; do not implement a proof or
   client yet.

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
