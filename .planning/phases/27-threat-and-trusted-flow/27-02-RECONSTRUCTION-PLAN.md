# Phase 27 Plan 02 — Per-Intent Reconstruction

**Status:** Proposed response to the independent review of `d3693b7`

**Review record:**

- [consolidated four-lens review](https://github.com/dknauss/Sudo/pull/470#issuecomment-5100156995)
- [reproduction addendum](https://github.com/dknauss/Sudo/pull/470#issuecomment-5100187784)

## Purpose

Rebuild the Phase 27 experiment so that a passing suite proves the selected
properties rather than properties of a singleton mock. The successor must:

1. allow two independently bound browsers sharing one copied WordPress login
   session to create independent intents;
2. bind approval and redemption to the selected intent and the bytes actually
   submitted;
3. model every security actor explicitly;
4. make every independent guard removable through one named mutation;
5. run the baseline and the complete mutation manifest through one reproducible
   command in CI; and
6. reproduce the winning protocol in real WordPress/PHP before Phase 27 can
   conclude.

The Node fixture remains useful for browser-document relationships. It is not
evidence for WordPress multipart parsing, PHP concurrency, cookie parsing,
persistent rate limits, or atomic consumption.

## Reading map

- **Protocol shape:** actor model, per-intent data model, binding-cookie
  contract, and endpoint contract.
- **Security controls:** rate-limit decision, preflight-oracle contract, and
  guard and mutation ledger.
- **Transport evidence:** content-binding corpus and required test layers.
- **Reproducibility:** npm commands and CI matrix.
- **Decision gate:** Phase 27 exit criteria, stop conditions, and implementation
  sequence.

## Deliverable crosswalk

| Requested preparation | Normative section |
|---|---|
| Independent A/B intents | Actor model; per-intent data model |
| Complete guard inventory and focused mutations | Guard and mutation ledger |
| File bytes and multipart canonicalization | Endpoint contract; content-binding corpus |
| Explicit authentication actors | Actor model |
| Approval throttling and rate-key tradeoff | Rate-limit decision |
| `__Host-` binding and fixation/duplicate handling | Binding-cookie contract |
| Preflight status/body/timing/existence/size oracles | Preflight-oracle contract |
| Reproducible npm command and CI | Reproducible commands; CI matrix |
| Non-gameable completion decision | Phase 27 exit criteria; stop conditions |
| Node versus WordPress/PHP boundary | Decision: hybrid harness now; required test layers |

## Corrected claim under test

The experiment may accept only this candidate claim:

> A second browser holding a copied, still-valid WordPress login session, but
> not the account's reauthentication factor or the first browser's independently
> minted action-binding cookie, cannot approve or execute either selected
> effect.

The experiment must separately demonstrate these boundaries:

- A browser carrying the complete cookie state is indistinguishable by cookie
  possession alone.
- Active same-origin script in the approving browser is not stopped by an
  ordinary same-origin ceremony.
- A caller that knows or controls the reauthentication factor can approve its
  own intent; that is not a property this gate can prevent.
- The action-binding cookie is browser-context binding, not physical-device or
  tab attestation.
- Non-browser callers require separately selected policy.

## Decision: hybrid harness now

### Keep Node for browser questions

The Node/Playwright fixture remains the fastest and clearest place to test:

- same-document credential capture;
- popup opener and return-channel behavior;
- top-level navigation and counterfeit same-origin UI;
- multiple isolated browser contexts;
- browser enforcement of `Secure`, `HttpOnly`, `SameSite`, host-only, and
  `__Host-` cookie requirements; and
- copied-login-session versus full-cookie-state differentials.

### Require WordPress/PHP for the winning result

No Node-only candidate may be called selected, complete, or ready for Phase 28.
The same protocol must also run in a test-only WordPress adapter and demonstrate:

- real WordPress authentication and capability checks;
- actual PHP cookie-header parsing, including duplicate cookies;
- `multipart/form-data` parsing through `$_FILES`;
- the exact decoded upload bytes hashed on both sides;
- exact file-editor content bytes after the same request decoding and
  unslashing used by the effect adapter;
- persistent, actor-scoped rate storage;
- failure behavior with the selected cache/storage topology; and
- two or more concurrent PHP requests contending for one approval, with one
  effect winner.

The WordPress adapter may use an experimental store that is not proposed for
core. Phase 27 must prove that at least one real PHP/WordPress implementation
can satisfy the protocol. Phase 29 still selects and justifies the landable
production store.

## Actor model

Every test names one of these actors. Tests may not use an untyped cookie string
as a substitute for a principal.

| Actor | Authentication | Capability | Binding | Factor | Expected authority |
|---|---|---|---|---|---|
| `ANON` | none | none | none or attacker supplied | none | no descriptor or intent |
| `LOW` | valid low-privilege login session | lacks effect capability | separately minted | may know own factor | no descriptor or intent for either effect |
| `ADMIN_A` | valid privileged login session `S1` | effect capability | server-minted `BA` | victim factor | may create, approve, and redeem its own intents |
| `COPIED_B` | copy of `S1` | inherits the stolen session's capability | distinct server-minted `BB` | absent | may create its own intent, but cannot approve or redeem any intent |
| `COPIED_B_WITH_FACTOR` | copy of `S1` | inherited | `BB` | correct victim factor | may approve and redeem its own intent; explicitly outside the accepted claim |
| `FULL_CLONE_B` | copy of `S1` | inherited | copy of `BA` | absent | with a known intent correlation, can race ambient approved authority; required boundary failure |
| `XSS_A` | rides `ADMIN_A` | inherited | ambient `BA` | can observe/drive same-origin UI as modeled | ordinary same-origin UI is not a trust boundary |
| `SERVER_CODE` | arbitrary in-process PHP | dominates plugin code | irrelevant | irrelevant | outside browser ceremony; effect policy is separate |

`ADMIN_A` and `COPIED_B` must both receive successful preflight responses with
different intent IDs. A `409` caused merely by the other browser having an
intent is a test failure, not a security success.

Here `S1` means the complete set of WordPress cookies needed to reproduce the
validated WordPress login session on the tested surface, including the
logged-in cookie that supplies the session verifier. It excludes the separate
action-binding cookie. The fixture may not collapse that set into a magic
string while making claims about real WordPress cookie behavior.

## Per-intent data model

Use maps only as the Node representation of this model. The WordPress
representation must be persistent and support an atomic state transition.

```text
Intent {
  id: random 256-bit opaque identifier
  actor_id: canonical WordPress user ID
  login_session_hash: hash of the validated WordPress session verifier
  binding_hash: hash of a server-minted binding value
  action: core/write-extension-file | core/upload-extension-package
  target: canonical server-defined target structure
  proposed_digest: SHA-256 of the exact proposed effect bytes
  display: server-defined label plus escaped canonical target data
  state: prepared | approved | consumed | cancelled | expired
  prepared_at
  approved_at?
  expires_at
  failed_approval_attempts
  version
}
```

### State transitions

```text
absent ── authorized preflight ──► prepared
prepared ── factor success ──────► approved
approved ── exact atomic redeem ─► consumed
prepared|approved ── expiry ─────► expired
prepared|approved ── cancel ─────► cancelled
```

There is no update that changes an existing intent's action, target, proposed
digest, actor, login session, or binding. A changed operation requires a new
intent. A preflight never searches for or overwrites “the current intent.”

## Binding-cookie contract

The experimental cookie name is `__Host-wp_sudo_action_binding`.

Required attributes and behavior:

- `Secure`
- `HttpOnly`
- `Path=/`
- no `Domain`
- an explicitly selected `SameSite` policy tested against the return flow
- at least 256 random bits from the server
- stored only as a hash in the authoritative binding record
- associated with the validated login-session identity
- never accepted as valid merely because the client supplied it

### Issuance and validation

1. Binding bootstrap requires a valid WordPress login session.
2. With no binding cookie, the server creates and records a random binding.
3. A presented value unknown to the server is rejected by security endpoints;
   bootstrap may replace it with a fresh server value but may not adopt it.
4. More than one cookie with the exact binding name is ambiguous and fails
   closed. The WordPress adapter must inspect the raw `Cookie` header; relying
   on one normalized cookie value does not satisfy the duplicate-value test.
5. A known binding associated with another login session fails closed.
6. Logout, password reset, explicit session destruction, and proof revocation
   invalidate associated bindings and intents.

### Required binding tests

- attacker-fixed value is not adopted;
- unknown value is not usable;
- duplicate cookie values are rejected in both orders;
- subdomain `Domain=` cookie cannot shadow the `__Host-` cookie;
- JavaScript cannot read the cookie;
- Browser B with copied login cookies receives `BB`, not `BA`;
- copying `BA` as well and supplying the known intent correlation produces the
  documented full-clone boundary failure, so the result does not rely on the
  intent ID remaining secret;
- a new tab in Browser A shares `BA`, proving this is not tab binding; and
- a separate profile on the same device receives a different binding, proving
  this is not physical-device attestation.

## Endpoint contract

Only the endpoints below belong to the candidate protocol. Observation routes
used by the active-XSS negative controls are test instrumentation and must be
bound to the loopback research server; they are not protocol endpoints.

### `GET /binding-bootstrap`

Validates the WordPress login session, validates or mints the binding, and
returns status only. It never accepts a client-selected binding as authoritative.

### `POST /preflight/file`

Small JSON request containing the action ID, plugin/theme identity, canonical
relative path candidate, and digest of locally retained proposed content.

The server:

1. rejects oversized input before reading or parsing the body;
2. authenticates the login session;
3. validates the server-owned binding;
4. applies the real effect capability;
5. applies the target-read decision before describing a target;
6. consumes the principal/action preflight budget;
7. canonicalizes the target from server state;
8. validates digest syntax;
9. ignores/rejects client display labels;
10. creates a new immutable intent; and
11. returns only the opaque ID and metadata already readable by the caller.

### `POST /preflight/upload`

Small JSON request containing action ID, package kind, destination/effect class,
and the SHA-256 digest of the locally selected archive bytes. It does not
receive the archive.

The display label remains generic unless the server already possesses
independently trusted package metadata. Client ZIP metadata is not trusted
confirmation text.

The same shared preflight guards run as for file writes, followed by
upload-specific kind and destination validation. It creates a different intent
for every successful call, including calls from two bindings sharing one login
session.

### `GET /confirm/{intent_id}`

The confirmation surface:

1. authenticates the login session;
2. validates exactly one server-owned binding cookie;
3. selects the named intent from the per-intent store;
4. requires actor, login session, and binding equality;
5. requires `prepared` and unexpired state; and
6. renders server-defined action text and escaped target data.

It does not receive an action, target, digest, or display label from the
initiating page.

### `POST /approve/{intent_id}`

The approval endpoint:

1. rejects oversized input before reading it;
2. performs authentication, binding, intent-selection, ownership, state, and
   expiry checks independently;
3. checks and increments approval-attempt budgets atomically;
4. validates the site's real factor provider and requires an explicit fresh
   authentication result independent of mere possession of the stolen login
   session;
5. atomically changes only the selected intent from `prepared` to `approved`;
   and
6. returns status only, never a bearer.

Wrong-factor attempts on one intent may not be avoided by creating a new
intent, rotating a binding, or rotating the login-session token.

### `POST /effect/file`

Receives the intent ID, canonical target fields, and the actual proposed file
content in the real adapter's request encoding.

At the authoritative effect seam the server:

1. authenticates and validates the binding;
2. selects the named intent;
3. independently checks owner, login session, state, expiry, and action;
4. reconstructs the canonical target from the actual operation;
5. derives the exact byte sequence that the sink would write;
6. recomputes SHA-256 over those bytes;
7. atomically changes the exact matching intent from `approved` to `consumed`;
   and
8. performs the write only for the one successful consumer.

The effect endpoint does not accept a client-supplied digest as proof of
content. A digest field may be sent for diagnostics but the recomputed digest
is authoritative.

### `POST /effect/upload`

Receives the intent ID plus a real `multipart/form-data` upload. The server
hashes the decoded file-part bytes from the PHP temporary upload, not:

- the multipart envelope;
- the filename;
- MIME metadata;
- a re-encoded string; or
- a client-supplied digest field.

It rejects missing, truncated, or errored normalized uploads. The raw-transport
model separately rejects duplicate expected parts before normalization. The
exact temporary-file bytes whose digest is checked are the bytes passed to the
package effect adapter. Atomic consume occurs before unpacking or installing.

The WordPress adapter begins with the upload state made available to it in
`$_FILES`; it therefore must not claim that its own guard prevented earlier
network or temporary-file buffering. The experiment records that as an
environmental boundary. In particular, PHP's normalized view cannot establish
how many same-name raw parts were present; duplicate-part cardinality belongs
to the Node/raw-transport evidence unless a genuine pre-normalization PHP or
web-server mechanism is added. The experiment tests size limits at both the surrounding server
configuration and application layers.

## Rate-limit decision

There is no rate key that simultaneously prevents attacker rotation and makes
victim-lockout denial of service impossible. The experiment must expose that
tradeoff.

### Load-bearing approval budget

Failed reauthentication is limited by canonical account ID, independent of:

- login-session token;
- intent ID;
- browser binding; and
- source IP.

This is the guard that prevents a copied-session attacker from creating fresh
intents or rotating cookies to reset password guesses. It necessarily lets a
copied-session attacker consume the victim's failure budget and temporarily
deny approval. That fail-closed availability cost is documented and tested.

Supplemental per-intent, per-binding, and source-network budgets may reduce
abuse but cannot replace the account-scoped budget. Success does not silently
erase a concurrent failure count without an atomic policy.

The account counter update is itself atomic. Parallel wrong-factor requests may
not all observe the same old count and pass beneath the threshold.

### Preflight budget

Preflight uses a sliding window or token bucket keyed by:

```text
canonical actor ID + action class
```

with a supplemental source-network/global circuit breaker. It is not keyed by
the raw login cookie, intent, or binding. Rotation therefore does not mint a
fresh budget. A copied privileged session can consume the victim's preflight
budget; this is an explicit degradation/DoS boundary, not hidden by choosing a
weaker key.

All rate tests use an injected monotonic clock. They test the window boundary;
three requests immediately before and after a wall-clock boundary may not
produce an unintended double allowance.

## Preflight-oracle contract

### Unauthorized callers

For anonymous, low-privilege, and target-read-denied callers, known and unknown
action/target combinations must have:

- the same status;
- the same body shape and bounded length;
- the same cache headers;
- no server-defined label or canonical target;
- no intent creation;
- no target-store lookup before the authorization decision; and
- no target-dependent logging.

The strongest assertion is an instrumentation count proving the target resolver
was never called. Timing measurements supplement that assertion; they do not
stand in for it.

### Authorized copied sessions

A copied privileged session can already read much of the underlying admin
state. Preflight may return only information that the same capability and
target-read decision already exposes elsewhere. It must not disclose whether
another browser has an intent for the same target. Independent preflight always
creates a fresh opaque intent, so there is no target-collision `409` oracle.

Probe volume is bounded by the actor/action preflight budget.

### Oversized-body tests

For preflight and approval JSON:

- reject an excessive declared `Content-Length` before reading;
- enforce a streaming byte cap when length is absent or false;
- abort the socket/read at the cap;
- do not authenticate, resolve a target, create an intent, or consume a
  principal probe budget after the transport rejection; and
- test chunked bodies and a body that exceeds the cap by one byte.

The test reports bytes read, not only response status.

## Guard and mutation ledger

The successor uses one fault selector:

```text
PHASE27_MUTATION=<guard-id>
```

Production-like fixture code calls `guard( '<guard-id>', condition )`. The
mutation helper may negate or skip only that guard. A checked-in manifest maps
every guard ID to one focused test and its expected assertion ID. CI fails when:

- a guard ID exists in code but not the manifest;
- a manifest ID does not exist in code;
- two independent clauses share one guard ID;
- the selected mutation does not make its focused test fail;
- the focused failure lacks the expected assertion ID; or
- any non-focused test fails first and masks the intended kill point.

Shared helpers also require call-site wiring guards so a handler cannot omit the
entire helper while all helper unit tests remain green.

### Binding/bootstrap guards

| ID | Independent clause | Focused test |
|---|---|---|
| `BIND_TLS` | ceremony requires HTTPS and a `Secure` cookie | HTTP bootstrap cannot issue usable binding |
| `BIND_AUTH` | valid login session required | anonymous bootstrap creates no binding |
| `BIND_SERVER_MINT` | unknown client value is never adopted | fixed attacker value differs from issued value |
| `BIND_RANDOM` | binding is not derived from auth/session input | two fresh bindings differ and meet entropy contract |
| `BIND_HOST_PREFIX` | exact `__Host-` attributes | browser cookie inspection matches contract |
| `BIND_SINGLE_COOKIE` | duplicate exact-name cookies fail closed | both header orders reject |
| `BIND_REGISTRY` | presented binding must exist server-side | unknown random value rejects |
| `BIND_SESSION_OWNER` | binding belongs to validated login session | binding from another session rejects |
| `BIND_EXPIRY` | binding lifetime is bounded | expired binding rejects and cannot approve |
| `BIND_REVOCATION` | session/password/logout revocation reaches binding | revoked binding and intents reject |
| `BIND_FILE_WIRING` | file protocol invokes shared binding validation | removing call lets named file test fail |
| `BIND_UPLOAD_WIRING` | upload protocol invokes shared binding validation | removing call lets named upload test fail |

### Shared preflight guards

| ID | Independent clause | Focused test |
|---|---|---|
| `PREFLIGHT_LENGTH` | declared body size checked before read | oversized body reads zero bytes |
| `PREFLIGHT_STREAM_CAP` | undeclared/chunked body capped | cap+1 aborts at cap |
| `PREFLIGHT_CONTENT_TYPE` | only expected JSON accepted | alternate encoding rejected before target work |
| `PREFLIGHT_FETCH_SITE` | cross-site initiation refused before budget/lookup | cross-site request cannot create intent or consume victim budget |
| `PREFLIGHT_AUTH` | login session validated | anonymous known/unknown targets are indistinguishable |
| `PREFLIGHT_CAP` | effect capability enforced | low-privilege known/unknown targets are indistinguishable |
| `PREFLIGHT_TARGET_READ` | target may already be read | capable-but-target-denied caller gets no descriptor |
| `PREFLIGHT_RATE` | actor/action token bucket enforced | rotation cannot reset; clock boundary tested |
| `PREFLIGHT_RATE_ATOMIC` | concurrent counter updates cannot lose increments | parallel probes enforce one total budget |
| `PREFLIGHT_BINDING` | server-owned binding required | copied session without bootstrap cannot create intent |
| `PREFLIGHT_NO_CLIENT_LABEL` | client label ignored/rejected | attacker label never reaches response/record |
| `PREFLIGHT_RANDOM_ID` | new opaque ID per success | A and B receive distinct IDs |
| `PREFLIGHT_NEW_RECORD` | success inserts, never overwrites | B leaves A's complete record unchanged |
| `PREFLIGHT_EXPIRY` | bounded expiry stored | no unbounded prepared intent |
| `PREFLIGHT_MIN_RESPONSE` | response contains only permitted fields | unauthorized/state data absent |
| `PREFLIGHT_FILE_WIRING` | file handler invokes every shared guard | call-site removal killed by file test |
| `PREFLIGHT_UPLOAD_WIRING` | upload handler invokes every shared guard | call-site removal killed by upload test |

### File-preflight guards

| ID | Independent clause | Focused test |
|---|---|---|
| `FILE_ACTION` | exact server action class | alternate action rejected |
| `FILE_TARGET_CANON` | server canonicalizes identity/path | traversal and alias target rejected |
| `FILE_DIGEST_SHAPE` | exactly 32-byte SHA-256 encoding | malformed digest rejected |
| `FILE_DISPLAY_SERVER` | display text comes from catalog | client HTML/label cannot render |

### Upload-preflight guards

| ID | Independent clause | Focused test |
|---|---|---|
| `UPLOAD_ACTION` | exact server action class | alternate action rejected |
| `UPLOAD_KIND` | plugin/theme kind allowlist | unknown kind rejected |
| `UPLOAD_DESTINATION` | destination/effect class canonical | substituted destination rejected |
| `UPLOAD_DIGEST_SHAPE` | exactly 32-byte SHA-256 encoding | malformed digest rejected |
| `UPLOAD_DISPLAY_SERVER` | generic server label only | archive/client label cannot render |

### Confirmation guards

| ID | Independent clause | Focused test |
|---|---|---|
| `CONFIRM_AUTH` | valid login session | anonymous intent read rejected |
| `CONFIRM_BINDING` | exact registered binding | Browser B cannot render A's intent |
| `CONFIRM_LOOKUP` | requested ID selects record | unknown ID yields minimal refusal |
| `CONFIRM_ACTOR` | actor ID matches | other user cannot render |
| `CONFIRM_SESSION` | login-session hash matches | fresh session cannot inherit intent |
| `CONFIRM_STATE` | only prepared intent renders | consumed/cancelled intent cannot render |
| `CONFIRM_EXPIRY` | unexpired intent required | expired intent cannot render |
| `CONFIRM_ESCAPE` | target values escaped as data | hostile target cannot create markup |
| `CONFIRM_SERVER_FIELDS` | action/label/digest from record | query parameters cannot substitute details |

### Approval guards

| ID | Independent clause | Focused test |
|---|---|---|
| `APPROVE_LENGTH` | bounded credential body before read | oversized request reads zero bytes |
| `APPROVE_FETCH_SITE` | cross-site credential submission refused | cross-site POST reaches no factor provider |
| `APPROVE_AUTH` | valid login session | anonymous correct-factor attempt rejects |
| `APPROVE_BINDING` | exact registered intent binding | Browser B cannot approve A's intent |
| `APPROVE_LOOKUP` | path ID selects exact record | wrong ID cannot fall back to another intent |
| `APPROVE_ACTOR` | actor ID matches | other user rejects |
| `APPROVE_SESSION` | login-session hash matches | rotated/fresh session rejects |
| `APPROVE_STATE` | only prepared state accepted | approved/consumed/cancelled rejects |
| `APPROVE_EXPIRY` | intent unexpired | expired correct-factor attempt rejects |
| `APPROVE_ACCOUNT_RATE` | account-scoped failure budget | intent/session/binding rotation cannot reset |
| `APPROVE_RATE_ATOMIC` | parallel failures cannot lose increments | concurrent guesses exhaust one shared budget |
| `APPROVE_INTENT_RATE` | supplemental intent budget | one intent cannot be hammered |
| `APPROVE_FACTOR` | real provider must affirm | wrong/absent factor rejects |
| `APPROVE_FACTOR_FRESH` | provider result is fresh and not session-only | provider that merely echoes current login rejects |
| `APPROVE_ATOMIC` | prepared→approved is conditional atomic transition | concurrent approvals have one transition |
| `APPROVE_STATUS_ONLY` | no bearer in success response | parent receives status only |

### Shared effect/redemption guards

| ID | Independent clause | Focused test |
|---|---|---|
| `EFFECT_AUTH` | valid login session | anonymous exact submission rejects |
| `EFFECT_CSRF` | normal WordPress CSRF/fetch-metadata guard applies | cross-site request rejects, while nonce alone grants no approval |
| `EFFECT_BINDING` | exact registered intent binding | Browser B cannot redeem A's intent |
| `EFFECT_LOOKUP` | submitted ID selects exact intent | wrong ID cannot fall back |
| `EFFECT_ACTOR` | actor ID matches | other user rejects |
| `EFFECT_SESSION` | login-session hash matches | different login session rejects |
| `EFFECT_STATE` | only approved state redeemable | prepared/consumed/cancelled rejects |
| `EFFECT_EXPIRY` | approval unexpired | expired exact submission rejects |
| `EFFECT_ACTION` | reconstructed action equals record | cross-effect redemption rejects |
| `EFFECT_TARGET` | reconstructed target equals record | target substitution rejects |
| `EFFECT_DIGEST` | recomputed bytes equal record | byte substitution rejects |
| `EFFECT_ATOMIC_CONSUME` | conditional approved→consumed occurs before effect | concurrent PHP requests yield one winner |
| `EFFECT_FAIL_NO_SINK` | every refusal bypasses sink and success hooks | mutation makes differential sink assertion fail |
| `EFFECT_FILE_WIRING` | file handler invokes shared redemption | call-site removal killed by file test |
| `EFFECT_UPLOAD_WIRING` | upload handler invokes shared redemption | call-site removal killed by upload test |

### File-effect guards

| ID | Independent clause | Focused test |
|---|---|---|
| `FILE_CONTENT_PRESENT` | actual proposed content required | digest-only request rejects |
| `FILE_CONTENT_DECODE` | same decoding/unslashing as sink | slash/newline/UTF-8 corpus matches sink bytes |
| `FILE_CONTENT_HASH` | server hashes actual decoded bytes | post-approval byte change rejects |
| `FILE_PATH_REBUILD` | target rebuilt from operation | post-approval path change rejects |
| `FILE_SINK_BYTES` | checked bytes are written bytes | captured sink bytes equal hashed bytes |
| `FILE_SINK_ONCE` | sink executes once | sequential/concurrent replay writes once |

### Upload-effect guards

| ID | Independent clause | Focused test |
|---|---|---|
| `UPLOAD_MULTIPART` | real multipart request required | raw octet stream rejects in raw-transport model |
| `UPLOAD_SINGLE_PART` | exactly one expected raw file part | duplicate/missing part rejects before normalization in raw-transport model |
| `UPLOAD_ERROR` | PHP upload error is success-only | truncated/error upload rejects |
| `UPLOAD_TMP_READ` | authoritative bytes read from temp file | metadata-only substitution irrelevant |
| `UPLOAD_CONTENT_HASH` | hash decoded part bytes | post-approval byte change rejects |
| `UPLOAD_KIND_REBUILD` | real operation kind equals record | plugin/theme substitution rejects |
| `UPLOAD_DEST_REBUILD` | real destination class equals record | destination substitution rejects |
| `UPLOAD_SINK_BYTES` | checked temp bytes are supplied to effect | captured sink bytes equal hashed bytes |
| `UPLOAD_SINK_ONCE` | unpack/install reached once | sequential/concurrent replay effects once |

## Content-binding corpus

### File content

Test at minimum:

- empty file;
- LF and CRLF;
- quotes and backslashes that exercise WordPress slashing;
- valid multibyte UTF-8;
- NUL where the real sink permits or rejects it;
- content differing only in final newline;
- one-byte post-approval mutation; and
- target path alias/traversal attempts.

Each case records:

1. browser-local byte digest;
2. PHP post-decoding byte digest;
3. bytes captured immediately before the sink; and
4. bytes observed after the test write.

No claim passes unless 2–4 are byte-identical and match the approved digest.

### Multipart upload

Build the request through browser `FormData`. Verify raw part cardinality in
the Node/raw-transport model, then verify the normalized upload in PHP:

- the multipart envelope digest differs from the file-part digest;
- the client `File.arrayBuffer()` digest equals `hash_file()` on the PHP temp
  file;
- filename and MIME changes do not change the content digest;
- binary `00`, `ff`, and `80` bytes survive;
- duplicate raw parts reject in the raw-transport model;
- empty normalized files, upload errors, and truncation reject in PHP;
- replacing bytes after approval rejects; and
- the temp file passed to the effect contains the checked bytes.

## Required test layers

### Layer N — Node browser-relationship suite

Purpose: browser documents, channels, navigation, and cookie enforcement.

It must not claim WordPress authentication, capabilities, transport
canonicalization, persistent rate state, or PHP atomicity.

### Layer W1 — WordPress integration suite

Use real WordPress plus MySQL and the test-only action-gate adapter for:

- user/session/capability actor construction;
- persistent intent and binding records;
- fake-clock expiry and sliding/token-bucket rate logic;
- storage/cache failure injection;
- logout/password/session revocation; and
- exact state-transition assertions.

### Layer W2 — wp-env browser suite

Use two Playwright browser contexts against real WordPress for:

- real authentication cookies;
- `__Host-` binding behavior over HTTPS;
- independent A/B preflights;
- confirmation and status-only return;
- actual file request encoding;
- actual multipart `$_FILES`; and
- copied-session and full-cookie-state differential cases.

### Layer W3 — concurrent PHP redemption

Coordinate 2, 8, and 32 HTTP/PHP workers at a barrier against one approved
intent. Assert:

- exactly one atomic consume succeeds;
- exactly one sink observation occurs;
- every loser receives the documented refusal;
- the final record is consumed once; and
- cache failure or stale cache cannot create a second winner.

Node run-to-completion does not satisfy W3.

## Reproducible commands

Add these package scripts:

```json
{
  "test:research:phase27:node": "playwright test --config tests/e2e/research.playwright.config.ts --project=chromium",
  "test:research:phase27:mutations": "node bin/run-phase27-mutations.mjs",
  "test:research:phase27:wordpress": "node bin/run-phase27-wordpress.mjs",
  "verify:research:phase27": "node bin/verify-phase27-research.mjs"
}
```

The runner:

1. verifies local non-symlinked `node_modules/` and `vendor/`;
2. verifies the Composer classmap root;
3. validates the guard/manifest bijection;
4. runs the clean Node and WordPress baselines;
5. creates a unique temporary artifact directory per mutation;
6. runs each mutation in a fresh server/process;
7. requires the expected focused assertion ID;
8. fails on a timeout, unrelated first failure, surviving mutation, or dirty
   tracked tree; and
9. emits JSON and Markdown summaries pinned to the commit SHA.

Do not build shell programs from mutation names. Use `spawn()`/`execFile()` with
an argument array and an explicit environment.

## CI matrix

Add `.github/workflows/phase27-research.yml` with path filters for:

- `.planning/phases/27-threat-and-trusted-flow/**`
- `tests/e2e/research/**`
- `tests/e2e/fixtures/phase27-*`
- the WordPress test adapter and its integration tests
- `bin/run-phase27-*.mjs`
- `package.json` and `package-lock.json`

Jobs:

| Job | Runtime | Required work |
|---|---|---|
| `phase27-node-baseline` | Node + Chromium | clean browser suite |
| `phase27-node-mutations` | Node + Chromium | full guard manifest, sharded deterministically |
| `phase27-wp-integration` | PHP 8.2 + MySQL | W1, including rate and failure injection |
| `phase27-wp-browser` | wp-env HTTPS + Chromium | W2, including multipart and cookies |
| `phase27-wp-concurrency` | PHP-FPM/HTTP + MySQL | W3 at 2/8/32 workers |
| `phase27-evidence-audit` | no browser | manifest bijection, source/classmap roots, clean-tree and evidence-summary checks |

No required research job may silently skip because production PHP files did not
change. The final evidence artifact includes baseline counts, every mutation
and kill point, actor cases, concurrency winners, and the exact head SHA.

## Phase 27 exit criteria

Phase 27 may conclude only when every item below has direct evidence.

### Model validity

- [ ] A and B sharing one validated login session create independent intent IDs.
- [ ] Creating B's intent leaves every field and state transition of A's intent
      unchanged.
- [ ] Effect handlers select by submitted opaque ID; there is no global
      “current” intent.
- [ ] File and upload effects carry and verify actual submitted bytes.
- [ ] The WordPress harness uses real request parsing for both effect shapes.

### Actor and boundary coverage

- [ ] Every actor in the actor table has a named baseline test.
- [ ] Copied-session-only B cannot approve its own or A's intent.
- [ ] Correct-factor B can approve its own intent, proving the factor—not an
      accidental singleton—is the boundary.
- [ ] Full-cookie-state cloning is a demonstrated boundary failure.
- [ ] The full-clone test supplies a known intent correlation explicitly, so
      neither the protection nor the failure relies on intent-ID secrecy.
- [ ] Active same-origin script remains an explicit rejected claim.
- [ ] Browser binding is not called device or tab binding.

### Guard completeness

- [ ] Every clause in the guard ledger exists as a distinct code guard.
- [ ] Code and mutation manifest form a mechanically checked bijection.
- [ ] Every mutation dies first at its named focused assertion ID.
- [ ] Removing any authentication, intent-selection, action, target, digest,
      rate, binding, expiry, or atomic-consume clause cannot leave all suites
      green.
- [ ] Shared-helper unit tests and per-endpoint wiring mutations both exist.

### Binding and rate behavior

- [ ] `__Host-` cookie behavior is observed in a real HTTPS browser.
- [ ] Server minting, fixation rejection, unknown values, duplicate cookies,
      ownership, rotation, and revocation are tested.
- [ ] Account-scoped approval throttling survives session, binding, and intent
      rotation.
- [ ] Concurrent failure increments cannot lose attempts from either the
      preflight or approval budget.
- [ ] The victim-lockout DoS tradeoff is demonstrated and documented.
- [ ] Preflight uses an actor/action budget with injected-clock boundary tests.
- [ ] No load-bearing budget is keyed solely by caller-supplied cookie text.

### Preflight oracle and transport

- [ ] Known and unknown targets are indistinguishable to unauthorized,
      low-privilege, and target-read-denied actors in status, body, and server
      work.
- [ ] Target resolution is proven not to run before authorization.
- [ ] Authorized response fields are no broader than the underlying read
      authority.
- [ ] Independent intents eliminate the other-browser `409` state oracle.
- [ ] Oversized declared and chunked bodies reject at the measured read cap.
- [ ] Multipart file-part digest equals the browser-selected bytes and the PHP
      temp-file bytes.
- [ ] File decoded bytes equal the bytes checked and written.

### Real WordPress/PHP behavior

- [ ] W1, W2, and W3 are green against the same pushed SHA.
- [ ] The factor provider returns an explicit fresh result independent of the
      stolen login session; a session-only provider cannot satisfy approval.
- [ ] At 2, 8, and 32 concurrent PHP redemptions, exactly one effect executes.
- [ ] Storage/cache failures fail closed.
- [ ] Expiry and revocation cannot be skipped through a summary marker.
- [ ] The research evidence does not rely on Node run-to-completion for a PHP
      claim.

### Reproducibility and review

- [ ] `npm run verify:research:phase27` runs every required baseline and mutation.
- [ ] CI runs the same command family and publishes the evidence artifact.
- [ ] The pushed head is clean and matches the independently reviewed tree.
- [ ] At least two fresh-context reviewers reproduce the winning path and try
      clause deletion beyond the declared mutations.
- [ ] Reviewers state what they ruled out; a clean verdict without reproduction
      evidence is insufficient.
- [ ] The decision record is rewritten from the new evidence and contains no
      claim inherited from `d3693b7`.

## Stop conditions

Stop and record Phase 27 as a dead end if:

- copied-session-only B can execute without the factor after all accidental
  singleton/global-state protections are removed;
- exact submitted bytes cannot be bound through the real WordPress transport;
- no practical WordPress persistence operation can produce one winner under
  concurrent redemption;
- binding fixation or duplicate-cookie ambiguity cannot be rejected;
- approval brute-force control can be reset without accepting an explicitly
  documented, reviewable alternative threat boundary;
- the preflight endpoint necessarily reveals target state beyond existing read
  authority and cannot be bounded; or
- the only smooth flow requires trusting active same-origin script while still
  claiming protection from that script.

## Implementation sequence

1. Freeze `d3693b7` as failed evidence; do not incrementally bless its singleton
   assertions.
2. Check in the guard manifest and failing actor/per-intent tests before the new
   handlers.
3. Implement the Node per-intent store and cookie registry only to satisfy the
   browser relationship tests.
4. Add real content and multipart transport tests.
5. Add throttling and oracle tests with an injected clock and resolver
   instrumentation.
6. Add the WordPress experimental adapter and persistent store.
7. Add real-browser and concurrent-PHP tests.
8. Wire the complete baseline/mutation command into CI.
9. Re-run adversarial review against the pushed SHA.
10. Only then rewrite the handoff decision and decide whether Phase 28 may begin.
