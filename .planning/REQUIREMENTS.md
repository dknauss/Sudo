# Requirements: Action Gate Research Program

**Defined:** 2026-07-27
**Core value:** Prove a narrow, reviewable core boundary and a safe, usable
pause-before-send flow for two executable-code effects.

## Architecture truth

- [ ] **ARCH-01:** One canonical architecture document distinguishes early veto,
  preflight UX, and action-bound proof, and identifies only early veto as the
  non-bypassable server boundary.
- [ ] **ARCH-02:** The proposal separates copied-cookie, session-riding XSS, active
  same-origin XSS, and malicious-server-code attackers; each closure claim names
  the attacker it actually covers.
- [ ] **ARCH-03:** Superseded registry-first, reusable-window, request-replay, and
  same-document-modal claims are clearly retired from active implementation docs.

## Early veto

- [ ] **VETO-01:** A veto-capable core seam runs immediately before plugin/theme
  package installation writes executable files.
- [ ] **VETO-02:** A veto-capable core seam runs immediately before plugin/theme
  file-editor writes executable content.
- [ ] **VETO-03:** Refusal has a structured error contract, propagates accurately to
  callers, produces no success action/response, and leaves no partial effect.
- [ ] **VETO-04:** Direct admin, AJAX/REST where applicable, and programmatic calls
  to the in-scope effect reach the same server decision or have a documented,
  tested exclusion.
- [ ] **VETO-05:** Removing or bypassing each veto makes its named negative test
  fail for the expected reason.

## Action-bound approval

- [ ] **PROOF-01:** Approval binds actor, login session, action, target, and
  security-relevant parameters or content digest.
- [ ] **PROOF-02:** Approval is short-lived, single-use, and atomically consumed
  by a compare-and-delete or equivalently linearizable operation before the
  effect; concurrent redemption cannot execute twice.
- [ ] **PROOF-03:** A cloned authentication cookie cannot mint, discover, or redeem
  approval issued to another browser merely because it can mint valid WP nonces.
- [ ] **PROOF-04:** Failure of proof storage or verification fails closed for the
  experimental gated effect.
- [ ] **PROOF-05:** No general recent-auth window authorizes a different action.

## Trust and confirmation

- [ ] **TRUST-01:** The design states where credentials and final action details are
  displayed and why that surface is or is not trustworthy against active
  same-origin XSS.
- [ ] **TRUST-02:** Trusted UI names the concrete action and target from
  server-defined data; attacker-controlled values render only as escaped data.
- [ ] **TRUST-03:** The approval handoff is tested against active same-origin
  script for every operation it exposes: read, invoke, redirect, replay, and
  redeem. The result determines—not merely accompanies—the permitted XSS claim.
- [ ] **TRUST-04:** Every proposal and demo states only the XSS property the
  selected handoff actually survives. If it does not close active same-origin
  XSS, it makes no general “XSS → RCE closed” claim.

## wp-admin experience

- [ ] **UX-01:** Integrated screens pause before transmission and preserve unsent
  form state, upload selection, and validation state locally.
- [ ] **UX-02:** A server preflight identifies the required action descriptor; the
  client does not infer security decisions from URLs or rule-tester rows.
- [ ] **UX-02A:** Preflight enforces the same action capability before describing
  a target, rate-limits callers, and returns no target/state information that the
  caller is not already authorized to read.
- [ ] **UX-03:** After approval, the original operation is sent once. No server-side
  stash reconstructs or automatically executes it.
- [ ] **UX-04:** Without JavaScript or integration, the server refuses safely and
  instructs the user to reauthenticate and resubmit; it never auto-replays.
- [ ] **UX-05:** Cancellation, expiration, wrong factor, duplicate submit, and
  changed-target paths preserve security and give honest recovery guidance.
- [ ] **UX-06:** The server records a privacy-preserving diagnostic when an
  integrated action reaches the veto without a valid preflight correlation, so
  broken JavaScript, CSP, or plugin conflicts degrade safely but not invisibly.

## Demonstrator and evidence

- [ ] **DEMO-01:** One reproducible environment demonstrates plugin/theme ZIP
  upload and plugin/theme editor save with the experimental boundary on and off.
- [ ] **DEMO-02:** A two-browser cloned-cookie test proves the exact copied-session
  property and includes a differential unpatched control.
- [ ] **DEMO-03:** Browser tests cover integrated, cancelled, expired, duplicate,
  no-JavaScript, and direct-request paths.
- [ ] **DEMO-04:** Each core-source assertion and patch is pinned to a
  `wordpress-develop` commit SHA.
- [ ] **DEMO-05:** The demonstrator explains which parts came from WP Sudo,
  `poc/install-package-gate`, `wip/coregate-unit1`, and
  `consequential-actions`, and which prior assumptions it rejects.
- [ ] **DEMO-06:** Slice A and the original `consequential-actions` MVP remain
  reproducible at immutable commits/tags; the successor work is additive and does
  not rewrite the historical experiments into appearing architecturally current.

## Proposal readiness

- [ ] **CORE-01:** The first upstream ask is limited to veto-capable seams and the
  minimum approval contract required by the two effects.
- [ ] **CORE-02:** The actions registry appears only as a possible later companion,
  with no claim that it provides enforcement or must resemble Abilities.
- [ ] **CORE-03:** Automation, provenance, broad identity-pivot coverage, and a
  full wp-admin action-confirmation framework are explicitly follow-up work.
- [ ] **CORE-03A:** Cut 1 states that plugin authors cannot register arbitrary
  consequential actions; a general extension mechanism is deferred with the
  registry/API work.
- [ ] **CORE-04:** An independent reviewer can reproduce the demo, identify every
  security assumption, and kill every claimed guard with the named mutation.

## Out of scope

- Production readiness or another normal plugin release.
- Comprehensive coverage of all dangerous WordPress operations.
- A general consequential-actions registry or Actions API.
- Plugin sandboxing or protection from arbitrary in-process PHP.
- Solving package authenticity or WordPress.org signed updates.
- Automation/provenance policy for cron, WP-CLI, XML-RPC, or Application Passwords.
- A polished universal wp-admin component before the two-action slice is proven.

## Traceability

| Requirement family | Phase |
|---|---|
| ARCH | 26 |
| TRUST | 27 |
| VETO | 28 |
| PROOF | 29 |
| UX | 30 |
| DEMO | 31 |
| CORE | 32 |
