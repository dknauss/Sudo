# Phase 27 — Trusted Handoff Decision Record

**Status:** Experiment design in progress; no handoff selected

**Scope:** Plugin/theme package upload and plugin/theme file-editor write only

**Rule:** The mechanism result determines the XSS claim

## Decision to make

Choose how a user can authenticate and approve one exact action without giving
compromised same-origin JavaScript a general bearer or an approval it can redeem
for a different operation.

This record does not assume that a modal, popup, top-level page, HttpOnly cookie,
JavaScript token, or WebAuthn ceremony is safe. Each is a candidate until its
browser behavior is observed and its server contract survives mutation.

## Attacker traces

### A. Copied cookie in Browser B

1. Browser A has an authenticated WordPress session.
2. Browser B receives a copy of A's authentication cookie before step-up.
3. B can load wp-admin and mint valid WordPress nonces.
4. A authenticates and approves one action.
5. B attempts to discover, invoke, or redeem A's action authority.

Required property: B cannot redeem A's authority merely because both browsers
present the copied login session.

### B. Script confined to the original admin document

1. An XSS runs in the page that owns the unsent form or upload.
2. It can read and mutate that document, initiate requests, and open or navigate
   windows.
3. It does not execute in an isolated confirmation document unless the browser
   relationship or return flow gives it reach.
4. The user completes the candidate trusted flow.
5. The script attempts every operation in the experiment matrix.

Required claim is undecided. It depends on what the selected handoff exposes.

### C. Script persistent across ordinary wp-admin pages

Same as B, but the payload executes after ordinary top-level navigation and on
return to wp-admin. It does not automatically execute in a deliberately isolated
authentication route; that is a property to test, not assume.

### D. Script reaches the confirmation route

The script executes in the document that renders credentials or final action
details. An ordinary DOM confirmation cannot be trusted in this case. Only a
browser-mediated ceremony may retain a narrower property, and even then the
server must bind the ceremony to the action rather than authentication alone.

### E. Arbitrary server-side PHP

Installed PHP can bypass or remove WordPress policy and write code by another
route. This attacker is out of scope; the action gate is not a plugin sandbox.

## Candidate sequence hypotheses

These diagrams describe what will be tested, not accepted designs.

### 1. Same-document modal — negative control

```text
compromised wp-admin document
  ├─ retains form/upload
  ├─ renders password and confirmation modal
  ├─ receives authority
  └─ submits mutation
```

Expected result: reject for active XSS. The script shares the credential,
confirmation, and handoff document and can inspect or drive all three.

### 2. Isolated popup returning authority

```text
compromised parent ── opens with noopener ──► isolated popup
       ▲                                      │
       └──── constrained return channel ◄─────┘
```

Questions:

- Does `noopener` prevent the popup from obtaining the parent while leaving the
  parent able to navigate the popup?
- Can the parent listen to, imitate, race, or redirect the return channel?
- Is returned material readable, or merely exercisable through an ambient
  cookie or endpoint?
- Can the parent redeem before or without the user's final confirmation?

Expected result: popup isolation protects popup DOM content from a confined
parent script, but returning a bearer to the compromised parent is not accepted.

### 3. Isolated top-level confirmation

```text
wp-admin with unsent state
  ── navigate ──► isolated authentication + action confirmation
  ── return ──► reconstructed or history-restored wp-admin state
```

Questions:

- Can unsent upload/form state survive without server-side request retention?
- Does returning restore the compromised script?
- Can ambient action authority be exercised by that restored script?
- Can confirmation and redemption be combined before returning?

Expected result: stronger document isolation, but potentially incompatible with
the “preserve unsent state locally” requirement unless state remains in another
window or the trusted surface executes the action.

### 4. Browser-mediated WebAuthn/passkey confirmation

```text
wp-admin or isolated surface
  ── server challenge binds action digest
  ── browser/authenticator signs challenge
  ── server verifies and atomically authorizes or executes that digest
```

Questions:

- Is the action digest part of the signed challenge?
- What meaningful action information does the browser/authenticator display?
- Can same-origin script request a ceremony for an attacker-selected digest and
  socially obtain a touch?
- Does successful authentication itself authorize, or is trusted action
  confirmation still separate?

Expected result: strongest credential protection, but not automatically proof of
informed action intent.

## Load-bearing upload problem

The upload descriptor binds the exact archive bytes. Before the original upload
is sent, a server cannot independently compute that digest or derive a trusted
package label from the archive.

Client-side hashing preserves “send once,” but active same-origin script controls
which bytes are presented to the hashing and preflight code. A trusted isolated
surface cannot inspect a parent document's selected `File` when it has no opener
relationship, and restoring that relationship weakens the isolation.

The browser spike must therefore distinguish:

1. copied-cookie protection, where honest client hashing may be adequate;
2. confined-XSS protection, where the compromised parent can choose the bytes;
3. informed confirmation, where the trusted surface must accurately describe
   what the server will write; and
4. exact server redemption, where changed bytes must fail even if the parent
   controls submission.

If no flow both preserves a local upload and gives the isolated confirmation
surface trustworthy information about its bytes, the first claim must narrow or
package upload must use a different two-stage transport. The documentation may
not hide this conflict behind “canonical digest.”

## Confirmation, issuance, and redemption variants

The spike will compare:

1. **Issue then confirm** — expected unsafe; authority exists before approval.
2. **Confirm then issue to parent** — parent may read or exercise authority.
3. **Confirm then issue an HttpOnly ambient cookie** — unreadable does not mean
   unusable; parent may navigate or submit a matching request.
4. **Confirm and redeem inside the isolated surface** — strongest intent
   ordering, but it may require the trusted surface to possess the exact payload.
5. **Confirm, seal a one-use server-side intent, then parent submits exact
   payload** — candidate only if the parent cannot substitute or exercise an
   unapproved intent and the isolated surface had trustworthy action details.

No variant is selected until the browser and server mutations are recorded.

## Evidence table

| Candidate | Read | Invoke | Redirect | Replay | Redeem | Mutate | Clone | Verdict |
|---|---|---|---|---|---|---|---|---|
| Same-document modal | pending | pending | pending | pending | pending | pending | pending | expected reject |
| Isolated popup | pending | pending | pending | pending | pending | pending | pending | pending |
| Isolated top-level | pending | pending | pending | pending | pending | pending | pending | pending |
| WebAuthn/passkey | pending | pending | pending | pending | pending | pending | pending | pending |

## Claim discipline

Until this table is complete, the permitted statement is:

> The research is testing whether early effect vetoes plus action-bound approval
> can prevent a copied authentication cookie from independently reaching two
> executable-code effects. Protection from active same-origin XSS is undecided.

No broader “XSS to RCE is closed” language is approved.

## Next experiment

Build a research-only same-origin fixture with:

- a compromised parent document;
- a same-document modal;
- an isolated popup opened with `noopener`;
- a top-level confirmation route;
- a server-observed one-use intent ledger; and
- explicit logs for read, invoke, redirect, replay, redeem, mutate, and clone.

Run the modal first as the negative control, then change one browser relationship
at a time. Every accepted property receives a focused mutation that removes its
guard and must make the named test fail.
