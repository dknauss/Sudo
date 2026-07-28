# Phase 27 — Trusted Handoff Decision Record

**Status:** Copied-auth-cookie-only handoff selected; active-XSS handoff rejected

**Scope:** Plugin/theme package upload and plugin/theme file-editor write only

**Rule:** The mechanism result limits the claim to copied-auth-cookie-only
protection

## Decision to make

Choose how a user can authenticate and approve one exact action without giving a
second browser holding the copied WordPress authentication cookie—but not the
independent binding cookie—authority to perform it. Determine separately whether
ordinary same-origin browser UI can withstand active script in the initiating
document.

This record does not assume that a modal, popup, top-level page, HttpOnly cookie,
JavaScript token, or WebAuthn ceremony is safe. Each is a candidate until its
browser behavior is observed and its server contract survives mutation.

## Decision

Select the editor-style preflight pattern for the copied-auth-cookie-only threat:

```text
local unsent form or File
  ── preflight metadata/digest ──► server-held exact intent
  ── reauthenticate ─────────────► approval bound to browser + intent
  ── submit original once ───────► exact match + atomic consume + effect
```

The browser receives only status, not a readable bearer. A second browser with
the same authentication cookie receives a different HttpOnly browser binding
and cannot approve or redeem Browser A's intent. File-write and binary package
upload variants both survive binding, mutation, and one-use guard mutations.

Use password reauthentication by default. Existing 2FA or passkey integrations
may supply the reauthentication factor, but WebAuthn is not required.

Reject ordinary same-origin HTML as an active-XSS confirmation boundary. The
experiments show that script in the initiating document can:

- read and drive a same-document modal;
- read a bearer returned through a same-origin popup channel;
- replace `window.open()` and remove `noopener` before the application call; and
- cancel a same-window navigation, counterfeit its path with
  `history.pushState()`, and render a credential-stealing confirmation.

The current WebAuthn Level 3 editor source also warns that code injection on an
origin in a credential's scope can invalidate WebAuthn's guarantees (see
GB-WEBAUTHN-CODE-INJECTION in `docs/upstream-sources.md`). Replacing a password
with a passkey therefore does not repair the same-origin trust boundary.

The selected protocol is not specific to executable-code payloads. Scalar
identity targets can be canonicalized entirely by the server, so the same
browser-bound, exact-action, single-use contract is a suitable candidate for the
five identity pivots named in the architecture charter. Those actions are not
part of this Phase 27 browser fixture: they follow only after the two direct code
effects establish the boundary and the WordPress persistence primitive survives
Phase 29 concurrency tests.

### Surface conclusion

This is a browser handoff, not a replacement policy for every surface currently
recognized by WP Sudo. It covers interactive cookie-authenticated requests that
can pause an unsent operation, retain a browser binding, and ask a person to
reauthenticate.

The effect-level veto proposed for Phase 28 is reached by browser,
programmatic, and automation callers, but reach is not policy. WP-CLI, cron,
XML-RPC, Application Passwords, background updates, and arbitrary server-side
calls cannot be required to perform this browser ceremony by implication. A
later slice must give each path a separately justified policy or a named,
tested exclusion. Phase 27 establishes no provenance or automation result.

## Attacker traces

### A. Copied WordPress authentication cookie in Browser B

1. Browser A has an authenticated WordPress session.
2. Browser B receives a copy of A's WordPress authentication cookie before
   step-up, but not A's independent browser-binding cookie.
3. B can load wp-admin and mint valid WordPress nonces.
4. A authenticates and approves one action.
5. B attempts to discover, invoke, or redeem A's action authority.

Required property: B cannot redeem A's authority merely because both browsers
present the copied login session.

Boundary case: if B also receives A's independent binding cookie, the standard
browser provides no device binding. The fixture must show that complete
cookie-state clone reaching the ambient approval rather than silently treating
it as protected.

### B. Script confined to the original admin document

1. An XSS runs in the page that owns the unsent form or upload.
2. It can read and mutate that document, initiate requests, and open or navigate
   windows.
3. It does not execute in an isolated confirmation document unless the browser
   relationship or return flow gives it reach.
4. The user completes the candidate trusted flow.
5. The script attempts every operation in the experiment matrix.

Result: rejected. The selected same-origin flow does not claim protection from
this attacker.

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

These diagrams record the hypotheses that were tested, not accepted designs.

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

The experiments resolve the earlier target-kind hypothesis:

- for copied-cookie protection, an honest client can hash a locally selected
  package, preflight only the digest and package kind, retain the `File`, then
  upload its bytes once after approval;
- the server must recompute the digest over the received binary bytes before
  consuming approval and allowing the effect; and
- for active same-origin XSS, client hashing is not trustworthy because the
  script controls the bytes and ceremony. That stronger claim remains rejected.

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

Variant 5 is selected for the copied-cookie threat only.

## Evidence table

| Candidate | Read | Invoke | Redirect | Replay | Redeem | Mutate | Clone | Verdict |
|---|---|---|---|---|---|---|---|---|
| Same-document modal | yes | yes | not needed for rejection | not needed after fatal invoke | not needed after fatal invoke | not needed after fatal invoke | not needed after fatal invoke | rejected |
| Isolated popup | returned bearer: yes; credential after launch tampering: yes | yes after launch tampering | launch feature can be removed | not needed after fatal invoke | yes | not needed after fatal invoke | not needed after fatal invoke | rejected for active XSS |
| Isolated top-level | credential after counterfeit: yes | yes after counterfeit | navigation can be cancelled and path counterfeited | one genuine effect only | genuine flow succeeds | exact server-held intent | not needed after fatal counterfeit | rejected for active XSS |
| WebAuthn/passkey | credential material protected | ceremony invocable by same-origin script | not pursued after code-injection boundary | challenge can be one-use | authenticates, but does not independently describe the action | challenge may bind digest | may help copied-auth-cookie-only threat | optional factor, not handoff boundary |
| Browser-bound editor-style preflight | status only; no bearer | active script can invoke, so active XSS is out of scope | not needed | blocked | Browser A only | target/digest and binary bytes blocked | auth-cookie-only clone blocked; full cookie-state clone succeeds | selected for copied-auth-cookie-only threat |

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
npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/research/phase27-handoff.spec.ts --grep "same-document modal"
```

Focused mutation:

```sh
PHASE27_DISABLE_COMPROMISED_HANDLER=1 npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/research/phase27-handoff.spec.ts --grep "same-document modal"
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
npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/research/phase27-handoff.spec.ts --grep "noopener popup"
```

Focused mutation:

```sh
PHASE27_DISABLE_POPUP_RETURN_HANDLER=1 npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/research/phase27-handoff.spec.ts --grep "noopener popup"
```

The ordinary run passes. With the compromised parent's return handler removed,
the popup still reports a null opener, but the named test fails because no
`authority-read` or `redeem` observation occurs.

### Popup launch and top-level counterfeit observations — 2026-07-27

`noopener` is an argument supplied by JavaScript in the compromised parent. The
fixture replaces `window.open()` before the application's click handler, drops
that argument, retains the returned same-origin window handle, observes the
password field, and activates the exact-action confirmation. A popup that
self-redeems is therefore still not isolated when its launch originates in
script the attacker controls.

A genuine same-window navigation does destroy the confined parent document: the
parent emits `pagehide`, its credential-polling interval does not run in the new
document, and the isolated route executes one immutable intent. That is not a
winning active-XSS boundary, however. A capture-phase listener can cancel the
launch gesture, set the expected same-origin path with `history.pushState()`,
render a counterfeit confirmation, read the password, and call the same server
endpoint without unloading the parent.

These results do not weaken the copied-cookie candidate. They establish why its
claim excludes active script in Browser A.

### Selected copied-cookie observations — 2026-07-27

Two independent browser contexts receive the same `wp_auth` value and distinct
HttpOnly browser-binding cookies. A separate boundary test copies both cookies
and confirms that the clone can redeem Browser A's ambient approval.

For `core/write-extension-file`:

- Browser A preflights the exact action, target, and proposed-content digest
  before sending the write;
- Browser B cannot overwrite Browser A's immutable prepared intent;
- a wrong password, Browser B even with the right password, and a wrong intent
  ID all fail approval independently;
- after Browser A approves the exact server-held target and digest, Browser B
  cannot redeem it;
- Browser A cannot substitute another path;
- Browser A can perform the exact effect once; and
- a replay is rejected.

For `core/upload-extension-package`:

- Browser A hashes a locally selected package before sending its bytes;
- preflight sends the digest and generic package kind, not the package body;
- Browser B cannot overwrite Browser A's immutable prepared intent;
- a wrong password, Browser B even with the right password, and a wrong intent
  ID all fail approval independently;
- Browser B cannot submit the package with the copied auth cookie;
- changed package bytes fail the server's recomputed digest check;
- the approved binary bytes, including `0x00`, `0xff`, and `0x80`, upload once;
  and
- a replay is rejected.

For both preflight endpoints:

- anonymous and authenticated low-privilege callers receive the same minimal
  `403 Forbidden` response before the request body is interpreted or any
  descriptor is returned;
- the fixture models the action's capability as a distinct authorized
  principal, rather than treating possession of a nonce or knowledge of an
  action identifier as authorization; production must substitute each action's
  real capability decision;
- an authorized copied-login-session caller receives a bounded probe budget
  shared across browser bindings for that action class. Rotating the
  attacker-controlled browser-binding cookie therefore does not reset the
  budget; excess probes receive `429`.

The approval response contains only `{"status":"approved"}`. Approval authority
remains server-side and is keyed to the browser binding plus exact intent.

Focused ordinary command:

```sh
npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium
```

The file-write guard mutations are:

```sh
PHASE27_DISABLE_CANDIDATE_PREFLIGHT_IMMUTABILITY=1 npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/research/phase27-handoff.spec.ts --grep "browser-bound exact-action"
PHASE27_DISABLE_CANDIDATE_PASSWORD_CHECK=1 npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/research/phase27-handoff.spec.ts --grep "browser-bound exact-action"
PHASE27_DISABLE_CANDIDATE_APPROVAL_BINDING=1 npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/research/phase27-handoff.spec.ts --grep "browser-bound exact-action"
PHASE27_DISABLE_CANDIDATE_APPROVAL_INTENT=1 npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/research/phase27-handoff.spec.ts --grep "browser-bound exact-action"
PHASE27_DISABLE_CANDIDATE_BINDING=1 npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/research/phase27-handoff.spec.ts --grep "browser-bound exact-action"
PHASE27_DISABLE_CANDIDATE_DESCRIPTOR_CHECK=1 npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/research/phase27-handoff.spec.ts --grep "browser-bound exact-action"
PHASE27_DISABLE_CANDIDATE_ONE_USE=1 npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/research/phase27-handoff.spec.ts --grep "browser-bound exact-action"
```

The package-upload guard mutations are:

```sh
PHASE27_DISABLE_UPLOAD_PREFLIGHT_IMMUTABILITY=1 npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/research/phase27-handoff.spec.ts --grep "package approval"
PHASE27_DISABLE_UPLOAD_PASSWORD_CHECK=1 npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/research/phase27-handoff.spec.ts --grep "package approval"
PHASE27_DISABLE_UPLOAD_APPROVAL_BINDING=1 npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/research/phase27-handoff.spec.ts --grep "package approval"
PHASE27_DISABLE_UPLOAD_APPROVAL_INTENT=1 npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/research/phase27-handoff.spec.ts --grep "package approval"
PHASE27_DISABLE_UPLOAD_BINDING=1 npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/research/phase27-handoff.spec.ts --grep "package approval"
PHASE27_DISABLE_UPLOAD_DIGEST_CHECK=1 npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/research/phase27-handoff.spec.ts --grep "package approval"
PHASE27_DISABLE_UPLOAD_ONE_USE=1 npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/research/phase27-handoff.spec.ts --grep "package approval"
```

The shared preflight-read guard mutations are:

```sh
PHASE27_DISABLE_PREFLIGHT_CAPABILITY=1 npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/research/phase27-handoff.spec.ts --grep "preflight withholds descriptors"
PHASE27_DISABLE_PREFLIGHT_RATE_LIMIT=1 npx playwright test --config tests/e2e/research.playwright.config.ts --project=chromium tests/e2e/research/phase27-handoff.spec.ts --grep "preflight withholds descriptors"
```

The observed kill point for each selected-candidate mutation is:

| Mutant | Named assertion without the guard |
|---|---|
| File preflight immutability | Browser B's overwrite preflight returns 200, not 409 |
| File approval password | Browser A's wrong-password approval returns 200, not 403 |
| File approval binding | Browser B's correct-password approval returns 200, not 403 |
| File approval intent | Browser A's wrong-intent approval returns 200, not 403 |
| File effect binding | Browser B's effect returns 204, not 403 |
| File descriptor | Browser A's changed target returns 204, not 409 |
| File one-use | the second file effect returns 204, not 409 |
| Upload preflight immutability | Browser B's overwrite preflight returns 200, not 409 |
| Upload approval password | Browser A's wrong-password approval returns 200, not 403 |
| Upload approval binding | Browser B's correct-password approval returns 200, not 403 |
| Upload approval intent | Browser A's wrong-intent approval returns 200, not 403 |
| Upload effect binding | Browser B's upload returns 204, not 403 |
| Upload digest | the changed package reports Uploaded, not Rejected |
| Upload one-use | the second upload returns 204, not 409 |
| Preflight capability | an anonymous caller receives the complete file-write descriptor with 200, not the minimal 403 response |
| Preflight rate limit | an authorized clone's fourth session-scoped file-write probe returns 409, not 429 |

### Selected protocol contract

The browser binding is an independent, server-minted random value carried in a
host-only HttpOnly cookie. It is not authentication and does not replace the
WordPress login-session verifier. Approval binds both.

Preflight accepts only an authenticated, capability-authorized caller and:

```text
request:
  action
  canonicalizable target fields
  package kind, when applicable
  exact proposed-content digest

response:
  opaque intent ID
  canonical action ID
  server-defined label
  canonical target fields the caller may already read
  exact digest
  expiry
```

The server rate-limits preflight, creates an immutable intent, and never accepts
the client label as confirmation text. A second browser may create its own
intent if otherwise authorized and the design does not rely on intent-ID
secrecy; it cannot overwrite, approve, or redeem Browser A's intent.

Reauthentication accepts the opaque intent ID plus the site's supported factor.
Success stores approval server-side against actor, login-session verifier,
browser binding, immutable descriptor, and expiry. The response reports status
only; it contains no proof bearer.

The original operation is then sent once with the intent correlation. At the
early effect veto, the server reconstructs the action descriptor from the real
operation, recomputes any content digest, and atomically consumes only an exact
match before permitting the effect.

The storage-model decision is one authoritative record per intent with a
linearizable state transition from approved to consumed. Serialized per-user
maps, summary expiry markers, read-then-delete, and cache-only truth are
rejected. Phase 29 must select the WordPress persistence implementation and
prove two coordinated redemptions produce one winner; a backend without that
test does not satisfy this decision.

### Limits carried into implementation

- The local fixture uses HTTP, so its binding cookie cannot exercise the
  production `Secure` attribute. Production requires `Secure`, `HttpOnly`,
  host-only scope, and an appropriate `SameSite` policy.
- The browser binding is another possession secret, not a hardware- or
  device-bound credential. Copying the WordPress authentication cookie alone
  does not copy it; cloning the complete cookie state does. The boundary test
  demonstrates that a full cookie-state clone can race to consume an approval.
- Synchronous state changes in one Node process model compare-and-consume but do
  not prove a WordPress storage implementation is atomic. Phase 29 must select
  an authoritative per-intent store and demonstrate a real concurrent
  redemption loser.
- The fixture establishes capability-before-description and a login-session-
  scoped probe budget for both selected preflight endpoints. It does not select
  production thresholds, distributed rate-limit storage, expiry, revocation
  enumeration, or multisite isolation. Those implementation properties remain
  for later phases.
- Active script in Browser A can exercise its ambient server-side approval
  without reading a bearer. That is why active same-origin XSS is an explicit
  non-goal rather than a caveat hidden beneath HttpOnly.

## Claim discipline

The accepted statement is:

> Early effect vetoes plus browser-bound, exact-action, single-use approval can
> prevent a second browser holding a copied WordPress authentication cookie—but
> not the independent browser-binding cookie—from independently reaching the
> tested extension-file-write and package-upload effects.

That statement applies to the tested interactive cookie-authenticated browser
path. It says nothing about WP-CLI, cron, XML-RPC, Application Passwords,
background updates, or arbitrary server-side PHP.

The rejected statement is:

> Sudo closes XSS-to-RCE while attacker-controlled JavaScript remains active in
> the approving wp-admin origin.

No broader “XSS to RCE is closed” language is approved. The defensible framing
is that the gate breaks a copied-auth-cookie-only route to the two tested
executable-code effects. It does not neutralize active XSS, and it does not
survive a compromise that clones the independent binding cookie too.

## Handoff to later phases

Proceed to Phase 28 with the editor-style preflight sequence and the
copied-auth-cookie-only claim. Phase 28 should define explicit adapters for the
two effects rather than a generic form/route guesser. Phase 29 must implement the
proof primitive with real atomic storage, expiry, revocation, browser binding,
and concurrent redemption tests. Phase 30 may then integrate local
pause/reauth/submit-once UX into the block editor and the named classic wp-admin
screens.

Do not continue Phase 27 by testing more same-origin modal variations. Reopen
the active-XSS question only if a new browser primitive or separately trusted
origin can display and attest exact WordPress action details independently of
the compromised wp-admin origin.
