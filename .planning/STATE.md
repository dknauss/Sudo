---
gsd_state_version: 1.0
milestone: Action Gate Research Program
last_updated: "2026-07-27"
status: Phase 27 decision complete — copied-auth-cookie-only handoff selected
---

# Planning State

## Current position

Phase 27 selected an editor-style pause/preflight/reauthenticate/submit-once
handoff for the copied-auth-cookie-only threat. Browser tests cover file writes
and byte-faithful package uploads in two contexts sharing one WordPress auth
cookie but not the independent browser binding. A boundary test shows that
cloning both cookies defeats this property. Ordinary same-origin modal, popup,
top-level, and WebAuthn ceremonies were rejected as an active-XSS trust boundary.

## Immediate next work

1. Independently review and merge the Phase 27 decision and browser evidence.
2. Phase 28: prove the early effect vetoes for package writes and file-editor
   writes.
3. Phase 29: implement authoritative per-intent proof state and demonstrate one
   winner under real concurrent redemption.
4. Phase 30: adapt the existing editor pause-before-send UX to the named classic
   wp-admin actions without a generic form or route guesser.
5. Phase 30B: reuse the proven mechanism for password/email changes to privileged
   accounts, administrator creation/promotion, and super-admin grants.

## Decisions carried forward

- Research-only project; no production deployment.
- No automatic request replay.
- Early server veto is the security boundary.
- Preflight is progressive UX.
- Approval is action-bound and single-use.
- Registry is a possible future companion, not a prerequisite.
- The first slice covers plugin/theme upload and file-editor save only.
- The closure MVP adds five identity pivots only after that two-effect slice
  proves the boundary; identity-only gating would not close the direct
  editor/uploader route.
- The closure claim is copied-auth-cookie-only protection, not protection from a
  full cookie-state clone or active same-origin script in the approving browser.
- Password remains the default reauthentication factor; 2FA and passkeys are
  optional integrations.

## Active blocker

No Phase 27 browser-decision blocker remains. The next security blocker is the
Phase 29 storage primitive: the research fixture's synchronous in-memory
compare-and-consume must not be mistaken for a proven WordPress atomic store.

Separately, #354 now demonstrates that WP Sudo's scalar liveness marker,
serialized proof map, Site Health cleanup, and revocation enumeration are not
safe storage prior art: cleanup violates the grace window, a low scalar can make
bulk revocation fail open, and cache-bypassed reads do not solve concurrent
read-modify-write. Phase 29 must design from authoritative per-proof state rather
than inherit this model.

## Canonical sources

- Architecture: `.planning/action-gate-architecture-charter.md`
- Requirements: `.planning/REQUIREMENTS.md`
- Phases: `.planning/ROADMAP.md`
- Public roadmap: `docs/ROADMAP.md`
- Research-use boundary: `PROJECT-STATUS.md`
- Live repository state: Git and GitHub
