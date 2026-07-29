# Sudo Architecture Comparison

## Purpose

This document compares WP Sudo's action/effect gate with a conceptual
switch-only privileged-account model. It is a decision aid, not an
implementation plan. Decisions here rest on WP Sudo's threat model, verified
WordPress behavior, and repository tests.

## Architectures

### WP Sudo

WP Sudo preserves WordPress's native user and capability model. It intercepts
known dangerous requests and selected destructive effects, then requires recent
reauthentication or applies a configured non-interactive policy.

### Switch-only privileged account

Normal administrator accounts would run with stripped capabilities. A user
would reauthenticate and temporarily switch into a separate privileged account
to perform administrative work.

This model remains conceptual. Account switching alone does not define approval
scope, expiry, attribution, automation policy, or safe recovery.

## Comparison

| Dimension | WP Sudo | Switch-only privileged account |
|---|---|---|
| Enforcement unit | Named action or destructive effect | Temporary privileged identity |
| Baseline role model | Native WordPress roles remain | Ordinary administrators are deliberately constrained |
| Reauthentication | At a gated operation, subject to an active sudo window | Before entering the privileged identity |
| Attribution | The acting user's identity remains visible | Requires durable origin-actor correlation so activity is not attributed only to the shared account |
| Non-interactive traffic | Explicit REST App Password, CLI, Cron, XML-RPC, and WPGraphQL policies | Needs separate rules; an interactive identity switch does not govern automation by itself |
| Missed-path failure | Native WordPress capability checks remain underneath a missed gate | A capability-stripping gap may restore broad privilege |
| Recovery | Does not replace the administrator role or login path | The privileged identity and switch mechanism become recovery dependencies |
| Ecosystem compatibility | Overlays native capabilities | Greater risk where plugins assume administrators retain standard capabilities |
| Operational cost | Rule and policy maintenance | Identity lifecycle, attribution, expiry, and break-glass design |

## Decision

WP Sudo continues to use action/effect gating. The switch-only model does not
replace it unless a future design demonstrates, with tests, all of the
following:

- scoped and single-use authorization rather than a general privileged window;
- actor attribution that survives the identity switch;
- explicit behavior for non-interactive entry points;
- fail-closed expiry and revocation;
- a recovery path that does not depend on the failed control plane; and
- acceptable compatibility with native WordPress capability expectations.

Until then, identity switching is research context rather than a roadmap
commitment.

## Bounded Prior Art: Fortress

Fortress publicly documents a capability/page-oriented sudo mode and broader
session-lifecycle controls (`FT-SUDO-MODE`, `FT-SESSION-TIMEOUTS`, and
`FT-PROTECTED-CAPS` in
[`upstream-sources.md`](upstream-sources.md)). Its runtime implementation was
not inspected. WP Sudo uses action/effect gating and derives no security
guarantee or implementation requirement from Fortress.

The comparison stops there deliberately. Product analogy is not evidence that a
control fits WP Sudo's threat model or WordPress's execution paths.

## Related Documents

- [`security-model.md`](security-model.md) — threat model and residual risks
- [`sudo-design-notes.md`](sudo-design-notes.md) — WP Sudo design decisions
- [`core-action-gate-proposal.md`](core-action-gate-proposal.md) — proposed core-layer direction
- [`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md) — core implementation research
