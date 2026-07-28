# Phase 27 — Trusted Handoff Decision Record

**Status:** Same-document negative control rejected; no handoff selected

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

This suggests a target-kind split for the first core cut:

- a scalar target that the server can independently resolve and canonicalize
  may be eligible for an isolated confirmation-and-redemption ceremony; while
- payload-bearing actions whose bytes the server has never received remain out
  until a transport gives the isolated surface possession of exactly what it
  attests to.

This is an experiment hypothesis, not yet a scope decision. It does not remove
package upload from the research catalog; it prevents results for scalar targets
from being generalized to unseen bytes.

## Confirmation, issuance, and redemption variants

The spike will compare:

1. **Issue then confirm** — expected unsafe; authority exists before approval.
2. **Confirm then issue to parent** — parent may read or exercise authority.
3. **Confirm then issue an HttpOnly ambient cookie** — unreadable does not mean
   authority-contained; parent JavaScript may navigate or submit a matching
   request and cause the browser to redeem the cookie without reading it.
4. **Confirm and redeem inside the isolated surface** — strongest intent
   ordering, but it may require the trusted surface to possess the exact payload.
5. **Confirm, seal a one-use server-side intent, then parent submits exact
   payload** — candidate only if the parent cannot substitute or exercise an
   unapproved intent and the isolated surface had trustworthy action details.

No variant is selected until the browser and server mutations are recorded.

## Evidence table

| Candidate | Read | Invoke | Redirect | Replay | Redeem | Mutate | Clone | Verdict |
|---|---|---|---|---|---|---|---|---|
| Same-document modal | yes | yes | not needed for rejection | pending | pending | pending | pending | rejected |
| Isolated popup | returned bearer: yes | pending | pending | pending | yes | pending | pending | readable-bearer variant rejected |
| Isolated top-level | pending | pending | pending | pending | pending | pending | pending | pending |
| WebAuthn/passkey | pending | pending | pending | pending | pending | pending | pending | pending |

### Same-document observation — 2026-07-27

The research fixture places the password field, approval button, application
handler, and a modeled compromised script in one document. The test performs
only these user operations:

1. open the upload approval modal; and
2. enter `victim-secret` in the password field.

The modeled compromised script observes the input event, reads the exact
password value, and clicks the approval button. The server consequently records
both:

- `read: victim-secret`; and
- `invoke: core/upload-extension-package`.

The test does not click **Approve upload**. The same-document modal is therefore
rejected before redirect, replay, digest mutation, or copied-browser behavior
needs to be characterized: script in that document already controls both the
credential and the purported final approval.

This was the predeclared convincing defeat: exact credential capture plus
invocation without a user approval click. A null observation would have
invalidated the fixture, not established modal safety.

Focused command:

```sh
npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/specs/research/phase27-same-document-modal.spec.ts
```

Focused mutation:

```sh
PHASE27_DISABLE_COMPROMISED_HANDLER=1 npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/specs/research/phase27-same-document-modal.spec.ts
```

The ordinary run passes. The mutation removes only the modeled compromised
handler; the named test then fails in its observation assertion with an empty
server log. This demonstrates that the test is killed by removal of the
attacker behavior it claims to observe.

This result does not establish anything about an isolated popup, an isolated
top-level route, WebAuthn, or Browser B with a copied cookie.

### `noopener` popup return-channel observation — 2026-07-27

The parent opens a same-origin confirmation popup with the `noopener` feature.
Chromium reports `window.opener === null` inside the popup. After a simulated
user enters the password and clicks **Approve exact action**, the popup sends a
bearer over a same-origin `BroadcastChannel`. Script already running in the
parent reads that bearer and invokes the modeled redemption endpoint.

This rejects one variant, not popup isolation as a whole:

- `noopener` did remove the popup's opener reference in this observation;
- it did not make a same-origin return channel trusted;
- returning readable authority to the compromised parent restores the
  compromised document to the authorization path.

The observation does not show that the parent read the popup's credential or
DOM, and it does not yet test a design where the isolated surface redeems the
action itself.

Focused command:

```sh
npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/specs/research/phase27-same-document-modal.spec.ts --grep "noopener popup"
```

Focused mutation:

```sh
PHASE27_DISABLE_POPUP_RETURN_HANDLER=1 npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/specs/research/phase27-same-document-modal.spec.ts --grep "noopener popup"
```

The ordinary run passes. With the compromised parent's return handler removed,
the popup still reports a null opener, but the named test fails because no
`authority-read` or `redeem` observation occurs.

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

The modal negative control and readable popup-bearer probe are complete. Next,
keep the popup isolated but do not return authority to the parent: have the
trusted surface redeem a server-held, exact action descriptor itself. Test
whether the compromised parent can substitute that descriptor, race redemption,
or cause a second effect. Package upload remains a separate experiment because
the isolated surface must possess the exact archive bytes it approves. Every
accepted property receives a focused mutation that removes its guard and must
make the named test fail.
