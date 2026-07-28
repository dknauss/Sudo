# GSD Roadmap — Action Gate Research Program

**Status:** Phase 27 decision complete; Phase 28 is next
**Goal:** Produce a narrow, executable case for early core veto points plus safe
action-bound step-up approval for plugin/theme upload and file-editor writes,
then apply the proven mechanism to five identity pivots that would otherwise
preserve or recreate privileged access.

The phases are sequential because each phase decides whether the next one is
safe to build. Parallel documentation work is allowed; parallel mechanism work
across an unresolved security gate is not.

## Phase 26 — Architectural reset and evidence inventory

**Goal:** Establish one current direction and stop old designs from continuing as
accidental requirements.

**Requirements:** ARCH-01, ARCH-03

**Work:**

1. Adopt the architecture charter and this milestone plan.
2. Inventory active docs and code that still prescribe a registry-first design,
   reusable recent-auth window, request carry/replay, or same-document modal as a
   security boundary.
3. Classify each artifact: retain as evidence, update, archive, or freeze.
4. Reconcile the public roadmap and documentation index with the program.
5. Map open GitHub issues to this milestone, deferred research, or historical
   plugin maintenance; do not inherit priority labels as architecture.
6. Record the intentional Cut-1 consequence: third-party plugins have no general
   API to register their own gated effects.

**Exit criteria:**

- A reader can find one current architecture without reading release archaeology.
- Old PoCs are visibly non-normative.
- The registry is absent from the critical path.
- No production release is implied.

**Deliverables:**

- Architecture charter.
- Current GSD project, requirements, roadmap, and state files.
- Doc/code disposition table.
- Issue triage proposal.

## Phase 27 — Threat contract and trusted-flow decision

**Goal:** Decide what the first slice can honestly claim before choosing UI or
proof transport.

**Requirements:** ARCH-02, TRUST-01..04, UX-02A

**Work:**

1. Write four explicit attacker traces: copied cookie, session-riding XSS, active
   same-origin XSS, and arbitrary server-side plugin code.
2. Specify the exact action descriptors for:
   - upload plugin/theme package; and
   - save plugin/theme editor file.
3. Compare trusted-flow candidates:
   - same-document modal;
   - isolated top-level challenge and confirmation;
   - isolated popup with `noopener` and a constrained return channel; and
   - browser-mediated WebAuthn/passkey confirmation.
4. Resolve one coupled mechanism-and-claim question: model what active
   same-origin script can read, invoke, redirect, replay, or redeem through each
   proof handoff, then derive the permitted XSS claim from that result. Do not
   “fix” the wording while leaving the mechanism unresolved.
5. Specify preflight as a security-sensitive read endpoint: same-capability
   authorization, response minimization, and rate limiting.

**Required adversarial tests on paper and in a browser spike:**

- compromised parent reads or replaces modal content;
- compromised parent initiates or navigates the child;
- proof delivery is observed, replayed, or redirected;
- attacker preselects the target before victim reauth;
- attacker changes target or bytes after approval;
- cloned-cookie browser mints arbitrary valid WP nonces.
- unauthorized or low-privilege caller probes action names and targets through
  preflight;
- authorized stolen session enumerates targets faster than the underlying admin
  surface would permit.

**Exit gate:**

No Phase 28 implementation until the trust boundary and proof handoff survive
the named tests and the closure claim is derived from those results. A narrowed
claim is an acceptable result; independently resolving the prose and mechanism
is not.

## Phase 28 — Early-veto core slice

**Goal:** Prove that both effects can be stopped at a small, accurate core seam
before irreversible mutation.

**Requirements:** VETO-01..05

**Work:**

1. Pin a `wordpress-develop` base SHA.
2. Reuse findings—not code assumptions—from `poc/install-package-gate` and
   `wip/coregate-unit1`.
3. Add the smallest structured veto contract for package installation and
   file-editor save.
4. Thread refusal through each caller without false success.
5. Assert no file write, extraction, activation, success hook, or success response
   occurs after refusal.
6. Test direct and alternate callers; document exclusions rather than broadening
   scope invisibly.
7. Keep seam reach separate from actor policy: demonstrate the
   cookie-authenticated browser denial, and enumerate every non-browser caller as
   separately governed or explicitly excluded rather than forcing it through a
   browser proof.

**Slice A salvage rule:** keep `poc/install-package-gate` runnable and historically
honest. Do not retrofit its reusable-window proof into the new protocol. Extract
or adapt these durable assets into the successor slice:

- the identical-call gate-on/gate-off filesystem control;
- the `run()`-before-unpack assertion;
- the forged/revoked/admin-cookie actor-class cases;
- the programmatic-call-inside-interactive-request case; and
- the copied-cookie browser setup, upgraded in Phase 29 to an action digest and
  atomic single-use redemption.

**Exit gate:**

- Each veto has a focused test that fails when the guard is removed.
- Refusal occurs before the first irreversible effect.
- Core's relevant existing tests remain green.
- The patch contains no registry and no authentication UI.

If a clean, early, structured veto cannot be added narrowly, stop and report that
finding; do not compensate with request interception.

## Phase 29 — Action-bound proof protocol

**Goal:** Make one approval authorize exactly one described effect in exactly one
browser/session context.

**Requirements:** PROOF-01..05

**Work:**

1. Define canonical action descriptors and digests.
2. Bind proof to actor, login-session identity, action, target, and relevant
   parameter/content digest.
3. Implement short TTL and atomic single-use redemption.
4. Define storage/cache failure behavior and revocation.
5. Ensure proof is distinct from WP nonces and cannot become a reusable recent-auth
   window.
6. Build a two-browser cloned-cookie differential test before adding polished UI.

**Exit gate:**

- Browser B with Browser A's copied WordPress auth cookie, but not Browser A's
  independent browser-binding cookie, cannot redeem Browser A's approval.
- A full cookie-state clone is demonstrated as a boundary failure rather than
  silently included in the accepted claim.
- Two concurrent redemptions execute at most once.
- The concurrency test proves the storage operation is compare-and-delete (or
  equivalently linearizable), not a read followed by a separate delete.
- Any digest change refuses.
- Every proof guard is killed by a named mutant.

Failure of the cloned-cookie test blocks the protocol and Phase 30.

## Phase 30 — wp-admin pause-before-send client

**Goal:** Demonstrate editor-quality continuity without trusting the client as the
security boundary.

**Requirements:** UX-01..06

**Work:**

1. Add preflight integration for plugin/theme upload.
2. Add preflight integration for plugin/theme file-editor save.
3. Keep original form/file state local until approval.
4. Use the trusted flow selected in Phase 27.
5. Send the original operation once with its exact proof.
6. Implement honest no-JavaScript and unsupported-screen fallback:
   reauthenticate, return, and ask the user to resubmit.
7. Remove any route-checker or static row that predicts a runtime replay result.
8. Add privacy-preserving observability when an integrated server action reaches
   the veto with no valid preflight correlation. Report aggregate failures or a
   Site Health diagnostic without logging action targets, credentials, or payloads.

**Exit gate:**

- Integrated flows preserve work across success, cancel, expiry, and wrong factor.
- The effect still refuses when JavaScript is bypassed.
- Network logs show the original mutation request was sent once.
- No request payload is retained server-side for later execution.
- A deliberately broken client integration is visible through the diagnostic,
  while the action still fails closed.

## Phase 30B — Identity-pivot companion perimeter

**Goal:** Prevent a copied-cookie browser from bypassing the code-effect boundary
by taking over or manufacturing a privileged identity.

**Requirements:** IDENT-01..04

**Scope:**

1. Change the password of an existing privileged account, including self.
2. Change the email address of an existing privileged account, including self.
3. Create a new administrator.
4. Promote an existing user to administrator.
5. Grant super-admin status on multisite.

Password and email changes count as two actions even when one profile submission
can request both. “Privileged account” and the target identity must be resolved
from authoritative server state, not client labels.

**Work:**

1. Inventory and pin every core mutation seam and caller for the five actions.
2. Add the smallest early vetoes needed where existing seams cannot refuse.
3. Define server-canonical scalar descriptors and reuse the Phase 29
   browser-bound, exact-action, single-use approval primitive.
4. Add named adapters only for the corresponding wp-admin/REST surfaces; do not
   introduce a general action registry or route guesser.
5. Test direct and alternate callers, copied-cookie contexts, target changes,
   combined profile updates, multisite behavior, and every guard mutation.

**Exit gate:**

- Refusal leaves passwords, email addresses, roles, capabilities, and network
  super-admin membership unchanged.
- Browser B with Browser A's copied WordPress auth cookie, but not Browser A's
  independent browser-binding cookie, cannot approve or redeem Browser A's
  identity intent.
- A proof for one target, identity mutation, or privilege level cannot authorize
  another.
- Each of the five actions has a focused test that fails when its veto, binding,
  descriptor, or atomic-use guard is removed.

This phase is a companion perimeter, not a substitute for the two direct
executable-code effects. It starts only after Phases 28 and 29 prove the shared
boundary.

## Phase 31 — Reproducible demonstrator and attack evidence

**Goal:** Turn the vertical slice into evidence a core reviewer can falsify.

**Requirements:** DEMO-01..06

**Work:**

1. Build one pinned WordPress Playground/wp-env demonstrator against the core patch.
2. Show unpatched attack/control, server-only veto fallback, and integrated
   preflight flow.
3. Automate cloned-cookie, direct-request, no-JavaScript, duplicate, cancellation,
   expiration, and changed-target cases.
4. Demonstrate that the five identity pivots cannot recreate or preserve
   privileged access for the copied-cookie browser.
5. Preserve the original `consequential-actions` MVP at an immutable tag and
   build an additive successor demo around effects and the new boundary. Reuse
   its Playground blueprint/narrator/test-shell assets; do not port its window or
   registry into the new mechanism.
6. Publish a concise evidence report with commands, SHAs, expected results, and
   honest residual risk.

**Exit gate:**

An independent fresh-context reviewer reproduces every claim and demonstrates
that each claimed guard's removal is detected by the named test.

## Phase 32 — Core proposal package

**Goal:** Present the smallest credible upstream change, supported by executable
evidence rather than a framework vision.

**Requirements:** CORE-01..04, including CORE-03A

**Work:**

1. Rewrite `docs/core-action-gate-proposal.md` around the two-effect closure and
   early veto ask, with the five identity pivots as a distinct companion
   perimeter rather than a general catalog.
2. Replace or archive the current broad implementation spec.
3. Provide separately reviewable patch units:
   - veto seams;
   - proof/confirmation primitive, if required in the initial ask;
   - identity-pivot companion seams; and
   - optional client demonstrator.
4. Put the registry, automation/provenance, identity actions beyond the five
   named pivots, broad action catalog, and general wp-admin API in a clearly
   marked future-work appendix.
5. State that plugins cannot register arbitrary consequential effects in Cut 1;
   do not imply that a private core catalog is an ecosystem API.
6. Request upstream review of the seam and threat claim—not endorsement of WP
   Sudo as a plugin.

**Exit criteria:**

- The proposal can be understood without the WP Sudo architecture.
- A reviewer can accept the veto seams without accepting a registry.
- Every headline claim links to a reproducible test.
- Known gaps are short, current, and consequential—not a log of historical churn.

## Phase order and stopping rules

| Phase | Starts when | Stops the program when |
|---|---|---|
| 26 | now | no canonical direction can be established |
| 27 | Phase 26 docs agree | trusted handoff has an unbounded fatal assumption |
| 28 | threat/flow contract settled | no narrow pre-effect veto exists |
| 29 | veto seams proven | copied-cookie or atomic-use property fails |
| 30 | proof protocol proven | smooth flow requires weakening the boundary |
| 30B | two-effect UX and proof protocol proven | identity seams cannot remain narrow or fail closed |
| 31 | vertical slice passes | independent reproduction fails |
| 32 | evidence package passes | upstream ask cannot remain narrow |

## Explicitly deferred

- General actions/consequences registry.
- Abilities integration.
- Identity actions beyond the five named pivots.
- Signed-update/package-provenance architecture.
- Non-browser automation policy.
- Production hardening of WP Sudo.
- General-purpose wp-admin action-confirmation API.

Those may become later milestones only after the two-action evidence and narrow
identity companion change the question from “is the boundary viable?” to “where
else is it worth applying?”
