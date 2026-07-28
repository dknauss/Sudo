# Phase 27 Exit Work — Handoff

**Prepared:** 2026-07-28  
**Branch:** `research/action-gate-phase-27`  
**Last pushed clean head:** `d418618ad5e3c29049776b84e922389a2bfd53ba`  
**PR:** #470 (draft)

## Do not commit the current worktree yet

The worktree contains integrated but unreviewed exit work. The Node tranche is
green. The WordPress baseline was green before the latest storage test and
multisite integration, but the complete WordPress baseline plus mutation loop
has not been re-established on the current combined tree.

Several agents ran formatters over whole TypeScript/JavaScript files. Before
staging, reduce those diffs to semantic hunks. In particular:

- `tests/e2e/fixtures/phase27-research-server.ts`
- `tests/e2e/research/phase27-handoff.spec.ts`
- `bin/run-phase27-wordpress.mjs`

Preserve the new behavior while removing unrelated quote/indent/reflow churn.
The repository commit gate is 10 files and roughly 1,000 insertions per commit,
so split the work into reviewed tranches.

## Completed and directly reproduced

### Oracle and transport limits

- Unauthorized valid/invalid targets have the same status, body, and cache
  behavior.
- Instrumentation proves the target resolver is not called before
  authorization.
- Excessive declared `Content-Length` rejects before reading (`0` bytes).
- Chunked cap-plus-one input stops at `1025` bytes for a `1024`-byte cap.
- Node baseline: **14/14 passed**.
- Node mutation runner completed successfully, including:
  `PREFLIGHT_ORACLE`, `UPLOAD_DECLARED_LENGTH_CAP`, and
  `UPLOAD_STREAM_CAP`.

### Lifecycle baseline

The WordPress adapter now has:

- bounded binding and intent expiry with an injected research clock;
- cancellation;
- user-state revocation callbacks for logout and password reset;
- explicit session-destruction cleanup behavior; and
- mutations `INTENT_EXPIRY` and `LIFECYCLE_REVOCATION`.

Before later integration edits, the WordPress baseline passed **5/5** and the
three pre-existing mutations were killed. The two new lifecycle mutations have
not yet been run successfully on the final combined tree.

### Real WordPress upgrader handoff

- `tests/e2e/fixtures/phase27-real-upgrader.php` invokes
  `WP_Upgrader::run()` with the exact already-hashed PHP temporary upload.
- It installs into a unique directory under
  `uploads/phase27-upgrader/<uuid>`, hashes an inert proof file, and removes the
  directory. It does not write to or activate anything under `WP_PLUGIN_DIR`.
- The valid inert plugin ZIP replaces the old malformed byte fixture.
- A baseline run against WordPress 7.0.2/PHP 8.2 passed.
- `EFFECT_INPUT` remains the required substitution mutation, but it must be
  rerun through the combined mutation runner.

### CI and multisite scaffolding

- `.github/workflows/phase27-research.yml` adds Node baseline/mutation,
  WordPress integration/browser/concurrency, and evidence-audit jobs.
- `tests/e2e/phase27.multisite.wp-env.json` defines WordPress 7.0.2, PHP 8.2,
  multisite, and port 8893.
- `npm run test:research:phase27:wordpress:multisite` selects that lane.
- The runner asserts whether WordPress actually is multisite.
- YAML/JSON/JavaScript syntax checks passed. Neither the pushed CI run nor a
  captured full local multisite result exists yet.

### Storage failure baseline

- `phase27_require_storage()` and `STORAGE_FAIL_CLOSED` are wired.
- `phase27-wordpress-failures.spec.ts` requires storage failure to return 503
  before an intent is created, then verifies recovery.
- The WordPress Playwright config now includes that spec.
- This test and its mutation have not yet been run successfully on the combined
  tree.

## Immediate next commands

First inspect and reduce formatting noise, then run:

```bash
node --check bin/run-phase27-wordpress.mjs
git diff --check
npm run test:research:phase27:node
npm run test:research:phase27:mutations
npm run test:research:phase27:wordpress
npm run test:research:phase27:wordpress:multisite
```

The WordPress runner contains the mutation loop after the baseline at
`bin/run-phase27-wordpress.mjs` near `const mutationResults = []`. A prior agent
reported that its command appeared to return after a green baseline without
printing mutation JSON. The code path itself remains present; treat this as an
unresolved runner observation (possibly execution-wrapper timeout) until a
direct run proves all WordPress mutations and emits the JSON summary.

Then run the ordinary project gates:

```bash
composer test
composer lint
composer analyse
composer verify:metrics
composer verify:sources
```

## Required tranche order

1. **Normalize and verify Node oracle/body-limit work.** Stage only the Node
   fixture/spec/manifest/runner changes. Obtain reviewer approval, then commit.
2. **Verify and commit lifecycle/storage changes.** Require the WordPress
   baseline and all lifecycle/storage mutations to die first at their named
   assertions.
3. **Verify and commit the real-upgrader handoff.** Require baseline,
   `EFFECT_INPUT`, atomic consume, and one-effect assertions through
   `WP_Upgrader`.
4. **Verify and commit multisite plus CI.** Run both single-site and multisite,
   push, and require the new workflow at the exact SHA.
5. **Reconcile the Phase 27 result document and metrics.** Do not mark Phase 27
   complete merely because the user-named categories pass.

Each code tranche requires staged independent review under `AGENTS.md`.

## Remaining scope beyond the user-named exit categories

The reconstruction plan contains roughly one hundred independent guard clauses;
the current manifests cover only a minority. Even after the work above passes,
the following full-exit claims remain unless separately implemented:

- every actor-table row has a named WordPress baseline;
- every authentication, ownership, session, action, target, digest, rate,
  binding, expiry, and wiring clause has its own mutation;
- file-editor content canonicalization and its real WordPress sink;
- concurrent preflight and approval-rate increments;
- complete binding fixation/ownership/rotation coverage;
- complete endpoint wiring mutations; and
- evidence summaries pinned to the exact commit SHA.

The honest near-term outcome may therefore be “all release-critical named
tranches pass; full Phase 27 ledger remains open,” not “Phase 27 complete.”

## Node-lane ledger: recorded open items

These are known and deliberate as of the tranche-1 commits. They are recorded
here so a later adversarial pass reads them as disclosed rather than as misses.

### `/confirm-and-redeem` compares the factor unbudgeted — deliberately

The handler checks `confirmation.password !== 'victim-secret'` inline with no
counter, and reads neither an authentication cookie nor a browser binding. 200
sequential wrong passwords all return a clean 403; the correct password then
returns 200 and executes the effect.

**It must stay that way.** The endpoint has exactly three call sites in
`phase27-research-server.ts`, all in *rejected*-candidate fixtures:
`isolatedParentFixture`, `isolatedConfirmationFixture`, and
`counterfeitTopLevelParentFixture`. (It is not reached by the same-document
modal, whose `compromisedSameDocumentHandler` calls only `/observe`, nor by the
`noopener` popup return channel, whose `compromisedPopupReturnHandler` calls
`/redeem` — a separate handler. Those two were misattributed here in an earlier
draft.) Those tests exist to demonstrate that script in the compromised document
reads the credential and drives the ceremony, so their predeclared convincing
defeat *is* a successful redemption with a stolen factor. An account-scoped
lockout there would make those negative controls pass for the wrong reason.

**The justification is semantic, not mechanical — a budget would not break
these tests today.** Counted rather than assumed: the rejected-candidate set
makes exactly one wrong-password call to this endpoint, the unconditional
`password: ''` probe `isolatedParentFixture` fires at script load. The other two
call sites send whatever the user entered, and each test starts a fresh server,
so failure counters never accumulate across tests. A 3-failure budget would
leave every one of these tests green. Anyone who adds one will not be caught by
this suite — which is exactly why the reason is recorded here.

A per-keystroke argument drafted earlier is **withdrawn as false**. It claimed
that because `counterfeitTopLevelParentFixture` fires `/confirm-and-redeem` from
an `input` listener, entering `victim-secret` produces 13 events of which 12 are
wrong partials, so a 3-failure budget would lock the fixture out before its
asserted effect. The listener is real, but the test uses Playwright's `fill()`.
Measured against this worktree's own Playwright build:

```text
fill() input events:              ["victim-secret"]                 (1)
pressSequentially() input events: ["v","vi","vic", … ,"victim-secret"] (13)
```

So the listener fires once, with the correct password, and there are no wrong
partials to count. The dynamic would only become real if the fixture switched to
`pressSequentially` — noted here as a hypothetical, not a property.

Recorded rather than deleted because the error is instructive: the fixture-side
fact was checked and the driver-side semantics were not, and the claim needed
both.

It is pre-existing at HEAD and predates the approval-budget work. The
`APPROVE_ACCOUNT_RATE` clause names the two endpoints it covers, so it does not
overclaim across this one.

### `UPLOAD_MULTIPART` and `UPLOAD_SINGLE_PART` are weak kills

Both probes run before approval, so under mutation the request falls past the
body parse into the binding disjunct and dies at 403 rather than the 400 its
clause describes. Each therefore discriminates two rejection codes and never an
accepted effect. Both manifest clauses now carry a `WEAK KILL:` disclosure.
Strengthening them means moving the probes after approval, which would consume
the one-use intent — so it needs that test restructured, not a line moved.

### `approvalFailures` has no window

`preflightAttempts` carries a 60 s window; the approval budget does not, so the
modeled lockout is permanent for the server's lifetime. This diverges from
`27-02-RECONSTRUCTION-PLAN.md` §Rate-limit decision, which requires an injected
monotonic clock and a window-boundary test for all rate tests. The manifest
clause does not claim a window, so this narrows scope rather than misstating it.
The assertion formerly called `P27-APPROVE-VICTIM-LOCKOUT-BOUNDARY` was renamed
to `P27-APPROVE-VICTIM-LOCKED-OUT` because there is no boundary to cross.

### U-3 — the login-session axis has no mutation on the four approve/effect endpoints

`! authenticated` can be replaced with `false` on both approve endpoints or both
effect endpoints and no test fails. No `mutationEnabled` wrapper and no
`PHASE27_DISABLE_*` switch touches that disjunct on any of the four.

The precise reason is narrower than "every browser context sets `wp_auth`",
which is false — `anonymous` in the preflight-disclosure test, the bare
`context` in the oracle test, and the default `page` contexts in the six
rejected-candidate tests never call `addCookies`. (`lowPrivilege` in that same
preflight test *does* call `addCookies`; it sets `wp_auth` to
`low-privilege-session`. An earlier draft listed it here as cookie-less, which
is wrong.) The true statement is: **no request in the suite reaches an approval
or effect endpoint without `wp_auth`.** The candidate fixture pages fire those
endpoints only on click, so the anonymous pages that merely `goto` them never
get there.

`lowPrivilege` is also the cheapest route to closing this. It already carries a
`wp_auth` value that fails `auth === 'copied-login-session'`, so it is a
ready-made negative principal for the four approve/effect endpoints — it simply
never fetches them today. Closing U-3 likely needs no new context, only that one
pointed at an approve endpoint with the 403 asserted, plus a guard wrapping the
shared check. Note the shadowing constraint: `! authenticated` sits in the same
`if` as the binding disjunct, so a request that lacks `wp_auth` *and* lacks the
prepared binding still returns 403 with the guard removed. A mutation that dies
needs the correct binding and intent, and only the login session missing.

Nor is the axis unexercised everywhere: on the **preflight** endpoints it is
exercised negatively — the anonymous context asserts a `403 Forbidden` in
`preflight withholds descriptors by capability and rate-limits authorized
probing`. The gap is specific to the four approve/effect endpoints, and it
matters because that is the axis the copied-auth-cookie claim turns on.

### The approve-endpoint response shape is unguarded

Tranche 1b made `/candidate-upload-approve`'s early 403 emit the same JSON body
and content type as its wrong-factor 403, so the two are not distinguishable by
shape. Nothing enforces that: no assertion, no manifest guard, no mutation. Every
approve-endpoint fetch in the spec reads `response.status` only, so reverting the
hunk to `text/plain 'Forbidden'` leaves the suite green. A guard is not verified
until a test has been seen to fail for the intended mutation, so this is a code
comment stating intent, not a checked property.

## Release boundary

Do not cut a production plugin release from this research branch. Under the
project policy, the earliest defensible output is a **research prerelease**
after:

1. single-site and multisite commands pass;
2. every checked-in mutation is killed;
3. the new CI workflow passes at the pushed head;
4. two fresh final-SHA reviews reproduce the result; and
5. the docs state the copied-auth-cookie-only boundary, active-XSS/full-cookie
   exclusions, and the experimental storage/upgrader limitations.

