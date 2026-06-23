# Security Audit Methodology

This document codifies how WP Sudo is to be security-audited. It exists because
a standard, sink-oriented audit pass returned this plugin **clean** while two
real weaknesses were present in the code — they were found only after the audit
was reframed around the target. The reframing, not new code, is what surfaced
them (see the case study below).

## Mandatory first step: Reason about the target first

**Before** any sink-oriented scanning — before enumerating request handlers and
hunting for injection, XSS, CSRF, broken access control, or missing nonces — you
**MUST** first reason about the target as a security *asset*, and write the
result down. A sink-oriented audit answers *"does this code contain a vulnerable
sink?"* It correctly returns "clean" for a plugin like WP Sudo that has no such
sinks, while it is structurally blind to the failures that only an asset-oriented
framing reveals.

This step is mandatory for every audit of this plugin and for any component whose
job is to enforce, gate, bind, or issue a security decision.

### What "reason about the target first" requires

For each component under audit, complete the following **before** scanning:

1. **Name the asset class.** Is this a security *control*, a *credential
   issuer*, a *data store*, or an *entry point*? WP Sudo's gate is a **control**
   (action-gated reauthentication); the sudo session is a **credential**.

2. **State the security properties as invariants.** Write them as things that
   must always hold, independent of any particular code path. For a control:
   *"the gate cannot be bypassed on any surface that can reach a gated-equivalent
   effect."* For a credential: *"the proof is bound to the login session and
   principal that created it, and cannot be replayed from another session or
   outlive it."*

3. **Enumerate what violates each property — independent of any sink.** These
   failure classes are properties, not sinks; a sink scan cannot see them:
   - **Incomplete surface coverage.** The control fires on the handlers the
     audit enumerated, but a non-enumerated or third-party handler (e.g. a custom
     `admin-post.php` route, a custom REST route) reaches the same destructive
     effect ungated.
   - **Unbound credential.** The proof is accepted regardless of which login
     session or principal presents it, so a captured cookie can be replayed, or
     the window outlives the login that created it.
   - **Policy/classification drift.** The same authorization decision is computed
     in two places that can diverge (e.g. cookie-vs-headless classification).

4. **Audit from the asset, not the code.** Ask the asset's own question —
   *"can this control be bypassed, and is its credential sound?"* — and then
   trace **every** path that could violate the stated invariant, **including
   paths that contain no traditional vulnerable sink.** A handler that simply
   *runs the effect* is in scope even when it is perfectly "secure" by sink
   standards, because the weakness is the control's absence there, not a bug in
   the handler.

### Mandatory pre-scan checklist

Complete and record this before starting the sink-oriented scan:

- [ ] Named the asset class (control / credential / store / entry point).
- [ ] Stated the security properties as explicit invariants.
- [ ] Enumerated the property-violation classes independent of sinks.
- [ ] **For a control:** checked surface completeness — every path that can reach
      a gated-equivalent effect, including non-enumerated and third-party
      handlers, on every entry point (admin, AJAX, REST, CLI, cron, XML-RPC,
      WPGraphQL).
- [ ] **For a credential:** checked binding — session, principal, replay, and
      lifetime (logout / re-login / session destruction).
- [ ] **For any duplicated decision:** checked that the two computation sites
      cannot drift (or refactored them to a single shared classifier).

Only after this checklist is complete may you proceed to the standard
sink-oriented scan. The two passes are complementary: the sink scan catches
implementation bugs; "reason about the target first" catches missing or unsound
controls.

## Case study (why this is mandatory)

The two action-gating completeness findings fixed under coordinated disclosure
were present in the shipped code and were **missed by a sink-oriented fleet
audit** that — correctly, for its question — reported the plugin clean. They were
found only when the audit was reframed with one prompt: *"Do you have any
recommendations for doing a more deep look at the code vs the standard audit
process?"* That reframing is exactly the asset-oriented framing above:

- Finding 1 is *incomplete surface coverage* — a control gap, not a sink bug; the
  handler that ran the effect was a third party's, and the gate simply never
  fired there.
- Finding 2 is *unbound credential* — a session-management property, invisible to
  a sink scan.

Both are the classes that **only** "reason about the target first" catches. The
weaknesses were not new in the audited version — the relevant code was unchanged
(byte-identical in the prior minor) — so the difference was methodology, not
code.

Canonical write-up, provenance, and affected versions:
[`security-report-2026-06-gate-completeness.md`](security-report-2026-06-gate-completeness.md).
Threat model and boundaries: [`security-model.md`](security-model.md).
