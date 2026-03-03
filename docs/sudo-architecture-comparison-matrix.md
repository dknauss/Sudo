# Sudo Architecture Comparison Matrix

## Scope and Sources

This document compares three architecture patterns for privileged WordPress operations:

1. **WP Sudo (current)**: action-gated reauthentication.
2. **Fortress (current)**: protected capabilities/pages plus multi-timeout session hardening.
3. **Proposed switch-only superadmin model**: stripped admin capabilities plus temporary switched access to one privileged account.

Scope is **Security + Ops** and the proposed model is intentionally **conceptual only** (no implementation runbook).  
Source review date: **March 3, 2026**.

## Architecture Definitions

- **WP Sudo (Current)**: Hook-based interception layer that matches dangerous operations and requires reauthentication before execution, while keeping the native WordPress role/capability model in place.
- **Fortress (Current)**: Session security model with absolute/idle/rotation/sudo timeouts and runtime restrictions via protected capabilities and protected pages outside sudo mode.
- **Proposed Switch-Only Superadmin Model**: Normal administrators run with stripped capabilities; reauthentication grants temporary occupancy of a single privileged account through account switching.

## Matrix: Security and Operational Differences

| Dimension | WP Sudo (Current) | Fortress (Current) | Proposed Switch-Only Superadmin Model | Security / Threat-Mitigation Implication |
|---|---|---|---|---|
| Enforcement primitive | Hook-based action interception and rule matching (`admin_init`, REST interception, function-level hooks, WPGraphQL hook). | Runtime filtering of protected capabilities plus protected-page redirect to Confirm Access. | Stripped baseline privileges plus temporary identity switch into one privileged account after reauth. | Operation-level controls (WP Sudo) and capability/page controls (Fortress) are different from identity-brokering controls (proposed). |
| Default privilege posture after login | Native role capabilities remain; browser login also grants an initial sudo session window. | Session starts in sudo mode with full allowed privileges until timeout. | Administrators are intentionally constrained by default; the privileged state is not standing. | Proposed model gives the strictest default least privilege, with more operator friction. |
| What expires on timeout | Sudo session state expires; base WordPress login session remains valid. | Sudo mode expires; separate absolute/idle controls can also expire the session itself. | **Inference:** Temporary switched privileged context expires or is exited; operator returns to stripped account while normal login may continue. | Privilege expiry and session expiry are separate controls; architectures differ in where they draw that boundary. |
| Session hardening model (absolute / idle / rotation / sudo) | Sudo duration + challenge lockout controls; no plugin-managed absolute / idle / rotation session lifecycle model. | Explicit absolute timeout, idle timeout, rotation timeout, and sudo timeout. | **Inference:** By itself, this model adds privilege-state controls, not full session lifecycle hardening, unless paired with additional controls. | Fortress has the most explicit stolen-cookie window management in the compared source set. |
| Reauth trigger semantics | Triggered when a request matches a gated action (or policy block on non-interactive surfaces). | Triggered when user is outside sudo mode and hits protected capability/page boundaries. | **Inference:** Triggered when user requests entry into privileged switched identity. | Trigger timing differs: per-action (WP Sudo), per-cap/page state (Fortress), or per-identity-escalation event (proposed). |
| Coverage of known destructive operations | Explicit built-in registry for many plugin / theme / user / options / update / export and multisite operations; extensible with custom rules. | Broad default protected capability/page lists cover many destructive admin paths. | Broad when those operations require capabilities held only by the privileged switched account. | All three can cover many known high-risk operations, but they maintain coverage through different maintenance models. |
| Coverage of unknown/new plugin operations | Depends on hook/rule coverage; operations bypassing expected hooks can evade gating. | Depends on whether plugin paths rely on protected capabilities/pages. | **Inference:** Depends on whether operations require stripped capabilities; custom capabilities granted to stripped roles create gaps. | Unknown-path resilience is strongest where privilege boundaries are broad and correctly mapped; each model has different blind spots. |
| Non-interactive surfaces (REST / app-password / CLI / cron / XML-RPC / GraphQL) | Explicit per-surface policy controls (Disabled / Limited / Unrestricted) for REST app-passwords, CLI, cron, XML-RPC, and WPGraphQL. | The cited Fortress session/sudo docs focus on authenticated session behavior and do not present an equivalent per-surface policy matrix across these interfaces. | **Inference:** Interactive account switching does not inherently govern non-interactive channels unless separate controls are added. | WP Sudo has the most explicit non-interactive policy surface in the cited materials. |
| Cookie-theft containment characteristics | Stolen auth cookie alone does not satisfy sudo for gated actions; sudo token is browser-bound. Non-gated actions still follow base role permissions. | Absolute / idle / rotation reduce token utility window; sudo mode still gates protected actions after timeout. | **Inference:** Stolen stripped-admin session has lower blast radius, but stolen active switched privileged session may be high impact until it ends. | All three help with cookie theft differently; proposed model concentrates risk into privileged switched sessions. |
| Shared-identity blast radius | No shared privileged identity requirement. | No shared privileged identity requirement. | Single privileged account can become a concentration point. | Shared privileged identity increases systemic blast radius and requires stronger compensating controls. |
| Audit attribution and non-repudiation | Native actor identity preserved; plugin provides audit hooks for gated/blocked/allowed lifecycle events. | User identity stays native to account/session model. | **Inference:** Without robust origin-actor correlation, privileged actions may appear as one shared account. | Forensics quality is best when privileged actions remain attributable to individual human principals. |
| Insider-threat friction | Reauthentication required before gated high-impact operations. | Reauthentication required after sudo expiry for protected operations/pages. | **Inference:** Highest friction; no standing admin privileges and explicit elevation step required. | Stronger friction can reduce abuse opportunity but increases workflow burden. |
| Failure mode if gating/switch layer fails | If a path misses interception, underlying WordPress capability checks still apply. | If protected capability/page restrictions fail or are mis-scoped, normal role capabilities remain active. | **Inference:** If cap-stripping fails open, many accounts may regain broad admin rights; if switching fails closed, privileged work halts. | Failure behavior differs materially: bypass risk vs operational stoppage risk. |
| Break-glass / lockout recovery risk | Lower: does not redefine administrator role as a prerequisite for normal admin access. | Moderate: timeout controls can interrupt sessions, but base role model persists. | Higher: recovery depends on safe access to the single privileged path and its control plane. | Centralized privileged identity improves control but increases recovery criticality. |
| Plugin ecosystem compatibility risk | Lower-to-moderate; preserves native role/capability design and overlays action gating. | Moderate; runtime protection of broad capabilities can affect plugin/admin UX assumptions. | High potential; hard capability stripping from administrators can conflict with common plugin assumptions. | Compatibility risk rises as architecture departs further from native WordPress role semantics. |
| Operational complexity | Moderate policy/rule management and audit integration. | Moderate-high due multi-timeout tuning plus protected capability/page governance. | High due identity brokering, elevation lifecycle, attribution requirements, and recovery design. | Security gains from stronger controls come with corresponding operational complexity costs. |
| Incident response and forensic clarity | Strong actor-level clarity plus explicit hook-driven event model. | Strong actor continuity with clear session-state transitions. | **Inference:** Weaker unless switch-origin metadata is consistently logged and queryable. | Incident handling quality correlates with identity continuity and event observability. |
| Best-fit deployment profile | Teams wanting action-level hardening with minimal RBAC redesign and explicit non-interactive policy controls. | Teams prioritizing full session lifecycle hardening plus capability/page sudo behavior. | High-assurance environments willing to absorb operational overhead for strict least privilege defaults. | Selection should follow risk appetite: compatibility/operability vs stricter privilege minimization. |

## Pros/Cons by Architecture

| Architecture | Security Strengths | Security Weaknesses | Operational Tradeoffs |
|---|---|---|---|
| WP Sudo (Current) | Strong mitigation for many high-impact actions, browser-bound sudo token, explicit non-interactive surface policies, strong audit hooks. | Coverage depends on rule/hook interception; does not itself implement absolute/idle/rotation timeout model. | Lower RBAC disruption; requires ongoing rule governance and policy tuning. |
| Fortress (Current) | Multi-timeout session hardening (absolute/idle/rotation/sudo), broad capability/page protection model. | Behavior depends on protected capability/page scope; non-interactive policy model is not equivalent in cited docs. | More session-control tuning and UX balancing around timeout behavior. |
| Proposed Switch-Only Superadmin Model | Strong default least privilege on day-to-day accounts; can broadly constrain privileged actions behind explicit elevation. | Shared privileged identity concentration risk; attribution and break-glass risks are significant without compensating controls. | Highest complexity and strongest dependency on reliable switching/elevation workflows. |

## Threat-Mitigation Takeaways

- The three patterns optimize different control planes: **action-level**, **capability/page-level**, and **identity-level**.
- Fortress provides the most explicit session lifecycle hardening in the cited sources; WP Sudo provides the most explicit cross-surface action/policy controls.
- The proposed switch-only design can improve least-privilege posture but introduces concentrated identity and recovery risks that must be treated as first-class security concerns.
- In practice, architecture choice is a tradeoff between stricter privilege minimization and operational/audit complexity.

## Assumptions and Limits

- This is a comparative analysis, not an implementation guide.
- Proposed model analysis is conceptual and intentionally avoids runbook-level details.
- Statements marked `Inference:` are reasoned conclusions where source documents do not specify exact behavior.
- Findings are constrained to the referenced documents and code paths reviewed on March 3, 2026.

## References

- WP Sudo local references:
  - [Security Model](security-model.md)
  - [Developer Reference](developer-reference.md)
  - [`includes/class-gate.php`](../includes/class-gate.php)
  - [`includes/class-sudo-session.php`](../includes/class-sudo-session.php)
  - [`includes/class-plugin.php`](../includes/class-plugin.php)
- Fortress references:
  - [The Fortress sudo mode](https://raw.githubusercontent.com/snicco/fortress/beta/docs/modules/session/sudo-mode.md)
  - [Session management and security](https://raw.githubusercontent.com/snicco/fortress/beta/docs/modules/session/session-managment-and-security.md)
- User Switching reference:
  - [User Switching (WordPress.org plugin documentation)](https://wordpress.org/plugins/user-switching/)
