# Phase 27 Context — Threat Contract and Trusted Flow

## The decision this phase must produce

Before implementation, decide exactly which stolen-session/XSS attacker the first
slice stops and how a trusted approval returns to the original unsent operation.

A clean outcome may be narrower than the motivating ambition. It may not be
ambiguous.

## Do not collapse browser script into server-side plugin code

Two facts must coexist:

1. Arbitrary server-side PHP runs inside WordPress and can call or remove policy,
   alter proof storage, or write files by another route. The action gate is not a
   plugin sandbox and does not defend this attacker.
2. Active browser JavaScript cannot be dismissed merely by calling it “in-process
   code.” It operates in the browser's same-origin authority, can issue requests
   and manipulate a compromised document, but it still encounters server vetoes
   and cannot directly become arbitrary PHP without reaching a code effect.

The second attacker is the reason for the proposal. Whether it can subvert an
isolated confirmation or steal/use the returned proof is an empirical design
question, not a reason to equate it with the first attacker.

## XSS cases to test separately

| Case | Minimum experiment |
|---|---|
| Script runs only in the original vulnerable admin document | Can it read, navigate, impersonate, or receive secrets from the approval surface? |
| Stored script runs on multiple ordinary wp-admin pages | Does it also execute on the chosen challenge/confirmation route? |
| Script can open or control a child window | Do `noopener`, navigation, and messaging rules prevent control or confused handoff? |
| Script watches the original page after approval | Is any returned proof readable or usable for an action other than the one visibly approved? |
| Script changes form fields or upload bytes after approval | Does the final server digest refuse? |
| Copied cookie runs in another browser | Can it preflight, mint WP nonces, obtain approval, or redeem Browser A's approval? |

The first slice may explicitly exclude an XSS capable of executing on the trusted
confirmation route itself. If so, the proposal must name that persistence/reach
assumption instead of saying it closes XSS generally.

## Preflight is a security-sensitive read

Preflight can become an oracle if an authenticated caller supplies an action and
target and receives canonical labels or state before approval.

The contract must therefore:

- apply the same underlying action capability before returning a descriptor;
- avoid returning any target detail the caller cannot already read;
- return stable, low-information errors that do not amplify enumeration;
- rate-limit repeated probes;
- bind the returned correlation/digest to the caller and candidate operation; and
- never accept a client-supplied human label as trusted confirmation text.

Capability checks do not make a stolen administrator harmless. They prevent
preflight from creating a new information surface for lower-privilege callers and
limit the endpoint to information the underlying action already exposes.

## “Sent once” needs two mechanisms

The UX property—do not send the original mutation until approval—is client-side.
The execution property—do not execute twice—is server-side.

The proof store must expose a linearizable consume operation:

```text
consume(proof_id, expected_digest) → exactly one of:
  redeemed
  missing
  expired
  digest_mismatch
```

A read followed by a separate delete is insufficient. Two concurrent requests can
both read success before either deletes. The implementation must use
compare-and-delete or an equivalent atomic primitive, and the test must coordinate
two redemptions so the race is actually exercised.

## Invisible degradation

Failing closed is necessary but not sufficient for adoption. If a screen is
supposed to preflight and its JavaScript is broken by CSP, another plugin, or an
exception, users otherwise see only the resubmit fallback.

The server should distinguish:

- a surface not integrated with preflight; and
- a declared integrated surface arriving without a valid preflight correlation.

Record only an aggregate diagnostic suitable for Site Health or development
logging. Do not retain targets, filenames, form contents, credentials, upload
bytes, or proof material.

## Required outputs

1. Threat matrix with exact closure language.
2. Trusted-surface decision.
3. Proof-handoff sequence diagram.
4. Preflight authorization and response schema.
5. Atomic redemption storage decision.
6. Browser-spike results for each XSS case above.
7. Approved headline for the Phase 28/29 demonstrator.
