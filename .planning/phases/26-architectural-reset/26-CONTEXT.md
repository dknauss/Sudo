# Phase 26 Context — Architectural Reset

## Why this phase exists

The public roadmap now points toward early server veto plus pause-before-send, but
the detailed proposal family accumulated several older architectures:

- a general consequential-actions registry as the opening primitive;
- a reusable recent-authentication window;
- server-side request stash, carry, reconstruction, confirmation, or replay;
- route/rule metadata asked to predict runtime behavior; and
- an in-page modal treated as though smooth UX implied an XSS-resistant boundary.

Those documents are valuable as a record of what was learned. They are unsafe as
simultaneous implementation instructions.

## Locked decisions

- The project is a research prototype.
- The next program is a WordPress core proposal and demonstrator, not a normal
  WP Sudo feature release.
- The first effects are plugin/theme upload and plugin/theme file-editor save.
- The boundary is an early, structured, veto-capable core seam.
- Integrated UX pauses before transmission.
- The original operation is sent once; no automatic replay returns.
- Approval is action-bound and single-use, not a reusable sudo window.
- A registry is later, optional work.
- Cut 1 consequently has no general plugin registration/extensibility API.
- Active same-origin XSS is a distinct attacker that must not be collapsed into a
  copied-cookie test.

## Artifact disposition

| Artifact | Phase-26 action | Reason |
|---|---|---|
| `docs/ROADMAP.md` action-confirmation section | Keep and reconcile | Closest current statement of the new direction |
| `docs/core-action-gate-proposal.md` | Rewrite in Phase 32; add an interim supersession banner in Phase 26 | Still mixes current rationale with server-carried confirmation and registry material |
| `docs/core-sudo-gate-implementation-spec.md` | Freeze as superseded design evidence; replace in Phase 32 | Too broad and based on mechanisms no longer on the critical path |
| `docs/core-sudo-gate-proposal-notes.md` | Archive | Working notes repeatedly restate resolved and later-reversed decisions |
| `docs/sudo-design-notes.md` | Retain as plugin history; remove forward authority | Useful history, not the core implementation plan |
| `.planning/322-stash-confused-deputy-design-brief.md` | Retain as failure evidence | Explains why replay was removed |
| `.planning/gutenberg-editor-reauth-*` | Retain as UX evidence | Demonstrates local pause; does not prove trusted UI under active XSS |
| `.planning/360-poc-patch-branch-scope.md` | Mine verified seams, then archive | Contains useful source work but an older mechanism and scope |
| `poc/install-package-gate` (Slice A) | Preserve unchanged as a runnable evidence fixture; create successor work beside it | Its differential filesystem test and pre-unpack finding remain load-bearing; its reusable proof window is obsolete |
| branch `wip/coregate-unit1` | Extract verified tests/findings only | One-shot research branch, not a merge target |
| `dknauss/consequential-actions` | Preserve the tagged MVP; add a clearly separated successor demo | Its attack story, Playground narrator, and compact harness are useful; its registry/window/modal architecture is superseded |
| Fortress references | Reduce to optional comparative history | No longer a requirement source |
| route/rule tester replay rows | Remove from forward design | Static metadata cannot predict runtime proof/replay conditions |

## Questions deliberately deferred to Phase 27

- Can any password-based flow be called resistant to active same-origin XSS?
- Does an isolated popup meaningfully improve the boundary if the proof returns to
  the compromised origin?
- Can WebAuthn confirmation bind the displayed action or only user presence?
- Can the proof be redeemed directly by the trusted surface while the original
  unsent upload remains in another page?
- What is the honest first headline if active XSS remains outside closure?
- How does preflight avoid becoming a target-enumeration/state-probing oracle?
- What aggregate diagnostic makes a broken preflight integration visible without
  retaining sensitive action or target data?

## Phase boundary

Phase 26 changes planning authority and labels old work. It does not implement a
veto, proof, challenge, client, or registry.

## Retention strategy

“Superseded” does not mean deleted or rewritten.

### Slice A

Keep the current directory, commits, and tests runnable as the record that:

- `upgrader_pre_install` really vetoes the final install;
- that seam alone is too late because extraction has already happened;
- `upgrader_pre_download` can stop the ordinary `run()` path before extraction;
- actor class must come from authenticated request state, not PHP call origin; and
- a copied login cookie is meaningfully different from a second independent
  session.

The next slice should live beside it or in a pinned core-patch repository and
cite which Slice A tests were adapted. It must not silently “upgrade” Slice A's
reusable proof into a different experiment.

### `consequential-actions`

Keep the existing MVP tag as a runnable historical snapshot. Its successor can
reuse:

- the five-minute-readable repository shape;
- WordPress Playground provisioning;
- the narrator/walkthrough;
- the unprotected-versus-protected comparison; and
- small unit/integration harnesses.

The successor must have a separate name or versioned demo path and lead with
effects, early veto, and the exact attacker tested. The old registry, per-user
window, and inline password modal remain visible as lessons, not hidden as though
they never existed.
