# Sudo Architecture Comparison Matrix

## Scope and Sources

This document compares three architecture patterns for privileged WordPress operations:

1. **WP Sudo (current)**: action-gated reauthentication.
2. **Fortress (current)**: a proprietary, license-gated **must-use** security suite (not an ordinary plugin — server-level deployment, integrated by hosts such as GridPane) providing protected capabilities/pages plus multi-timeout session hardening.
3. **Proposed switch-only superadmin model**: stripped admin capabilities plus temporary switched access to one privileged account.

Scope is **Security + Ops** and the proposed model is intentionally **conceptual only** (no implementation runbook).  
Source review date: **July 25, 2026** (Fortress characterization re-verified against live Snicco/GridPane sources; prior review March 3, 2026).

## Architecture Definitions

- **WP Sudo (Current)**: Hook-based interception layer that matches dangerous operations and requires reauthentication before execution, while keeping the native WordPress role/capability model in place.
- **Fortress (Current)**: Session security model with absolute/idle/rotation/sudo timeouts and runtime restrictions via protected capabilities and protected pages outside sudo mode. Distributed under a proprietary EULA (not GPL/MIT); its runtime code ships via the separate license-gated `snicco/fortress-dist` repository, and it loads as a **must-use plugin** (deployed under `mu-plugins/snicco-fortress/`, ahead of ordinary plugins) with logs/config/secrets stored outside the web root — a server-level integration, not an ordinary plugin install.
- **Proposed Switch-Only Superadmin Model**: Normal administrators run with stripped capabilities; reauthentication grants temporary occupancy of a single privileged account through account switching.

## Matrix: Security and Operational Differences

| Dimension | WP Sudo (Current) | Fortress (Current) | Proposed Switch-Only Superadmin Model | Security / Threat-Mitigation Implication |
|---|---|---|---|---|
| Enforcement primitive | Hook-based action interception and rule matching (`admin_init`, REST interception, function-level hooks, WPGraphQL hook). | Runtime filtering of protected capabilities plus protected-page redirect to Confirm Access. | Stripped baseline privileges plus temporary identity switch into one privileged account after reauth. | Operation-level controls (WP Sudo) and capability/page controls (Fortress) are different from identity-brokering controls (proposed). |
| Default privilege posture after login | Native role capabilities remain; browser login also grants an initial sudo session window. | Session starts in sudo mode with full allowed privileges until timeout. | Administrators are intentionally constrained by default; the privileged state is not standing. | Proposed model gives the strictest default least privilege, with more operator friction. |
| What expires on timeout | Sudo session state expires; base WordPress login session remains valid. | Sudo mode expires; separate absolute/idle controls can also expire the session itself. | **Inference:** Temporary switched privileged context expires or is exited; operator returns to stripped account while normal login may continue. | Privilege expiry and session expiry are separate controls; architectures differ in where they draw that boundary. |
| Session hardening model (absolute / idle / rotation / sudo) | Sudo duration + challenge lockout controls; no plugin-managed absolute / idle / rotation session lifecycle model. | Explicit absolute, idle, rotation, and sudo timeouts (config defaults: absolute 12h / 24h remember-me, idle 30 min, rotation 20 min, sudo 10 min). | **Inference:** By itself, this model adds privilege-state controls, not full session lifecycle hardening, unless paired with additional controls. | Fortress has the most explicit stolen-cookie window management in the compared source set. |
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

## Design-Borrowing Assessment: Fortress Session & Sudo Patterns

*Added July 25, 2026. Sources: Fortress **public docs only** (session module) on the
raw GitHub `beta` branch; WP Sudo [`class-sudo-session.php`](../includes/class-sudo-session.php)
and [`class-gate.php`](../includes/class-gate.php). Fortress's implementation code ships
from a separate license-gated `fortress-dist` repo and was **not** read — every
Fortress-side mechanism below is from their documentation, and reasoned gaps are marked
`Inference:`.*

**Licensing constraint (read before reusing anything here).** Fortress is proprietary
**source-available** under a Snicco Media EULA, **not** open source. `LICENSE.txt` §3.1–3.2
prohibits reverse-engineering, reconstructing their source/algorithms, publishing
benchmarks, and building a competing product with similar functionality using their
binary. Only *design patterns* (ideas) are borrowed here; no Fortress code or doc text is
copied, and nothing below depends on reading their source. Keep any future notes at the
pattern level and cite their public docs.

### Threat framing: Fortress hardens the *session*; WP Sudo hardens the *action*

The two are not competing takes on one threat — they defend **different kill-chain steps**
(see [security-model.md](security-model.md#threat-model-the-kill-chain)). WP Sudo targets
the step 2→3 transition (valid session → escalate/persist/impact). Fortress's *session
module* targets step 2 itself — shrinking how long a stolen session is useful — plus a
step-up gate on sensitive capabilities. They overlap only at "sudo mode / step-up," and
even there the mechanism differs (cap-strip-until-sudo vs. challenge-on-action).

| Threat | Fortress (session module) | WP Sudo | Stronger |
|---|---|---|---|
| Stolen **auth** cookie, no active sudo window (or auth cookie captured *without* the sudo cookie) → **sensitive** action | Cap-strip forces password re-entry once sudo lapses | Reauth at the action — password, **plus 2FA only when the user has it configured** (`needs_two_factor()` defaults false); sudo cookie bound to login session. *Caveat: if the attacker also captures the sudo cookie **during** an active window — notably the post-login auto-grant — the action is not re-challenged; that is a documented residual risk in [security-model.md](security-model.md#what-it-does-not-protect-against).* | Tie — WP Sudo adds 2FA browser-binding + reauth rate-limiting |
| Stolen cookie → **non-sensitive** damage window | Rotation (20 min) + idle (30 min) + absolute timeouts shrink the whole window | Out of scope — relies on WP core auth expiry | **Fortress** (owns the session store) |
| Session fixation / rotate-on-privilege-change | Yes (owns the store) | No | **Fortress** |
| Programmatic surfaces (CLI/Cron/XML-RPC/App-Pass/GraphQL) | App-passwords = blunt global on/off; XML-RPC & non-`fort` CLI gating **undocumented**; cap-strip only catches paths that call `current_user_can()` | Explicit three-tier policy per surface + effect backstops + per-app-password overrides | **WP Sudo** |
| Reauth brute-force | Not documented in the session module | Per-user + per-(IP,user) progressive lockout | **WP Sudo** |
| Password-change session revocation | **Not documented** (relies on WP core) | Hooks `after_password_reset` / `profile_update` | **WP Sudo** |
| Priv-esc via a **broken-access-control** path that skips the cap check | Cap-strip **can't help** — nothing to strip if the path never checks the cap | Opt-in escalation guard hooks the *effect* (`{prefix}capabilities` write, `grant_super_admin`), firing even when the vulnerable code's own cap check failed | **WP Sudo** (opt-in/default-OFF; own `$wpdb`/`user_has_cap` blind spots) |

**"Does Fortress defend *more* threats?"** As a *product*, yes — but that is apples-to-oranges:
Fortress is a multi-module suite (login hardening, 2FA, etc. — step 1 of the chain), WP Sudo
is a focused step 2→3 gate. Comparing the *session/sudo modules specifically*: Fortress covers
continuous session-theft-window reduction that WP Sudo structurally does not; WP Sudo covers
multi-surface reach, reauth-throttling, password-change revocation, and BAC-path escalation that
Fortress's session module does not. The one real Fortress advantage (rotation/idle) is **out of
WP Sudo's scope by design** — WP Sudo "is not a session system" — so it is a *complementary tool*,
not a gap to fill.

### Borrow / skip / already-tracked / recommend-against

| Fortress pattern | Verdict | Rationale |
|---|---|---|
| **Per-token session rows** in a custom table (via `session_token_manager` drop-in) | **Already tracked** | This is the enabling primitive for independent rotation/idle/expiry. WP Sudo's own [ROADMAP](ROADMAP.md) "Next" item (session-store architecture) and "Later" per-session/device isolation already cover it; Fortress **validates** that direction. Not a new action item. |
| **Four orthogonal timeouts** (absolute/rotation/idle/sudo) | **Mostly skip** | Rotation/idle matter for 12–24 h login sessions, not a ≤15-min sudo window. WP Sudo's absolute duration + 120 s grace is the right shape for step-up. |
| ↳ **Per-capability / per-action timeout tiering** | **Borrow (new roadmap item)** | A shorter sudo TTL for `user.delete` than for `plugin.activate` maps to WP Sudo's *per-rule* model (it is role-agnostic, so per-rule fits better than per-cap). Real but small; logged to ROADMAP → Later, not scheduled. |
| **`toggle-sudo` operator CLI command** | **Low-value borrow** | An operator `wp sudo activate <user>` is cheap, but must be a real operator command, **not** a test-only shim (CLAUDE.md forbids test shims in production). |
| **"ajax-like request" heuristic** (Accept/X-Requested-With/admin-ajax/wp-json) | **Already better** | `Gate::is_rest_cookie_auth()` (nonce + app-password classification, incl. the both-present→headless C2 fix) is more precise than a header sniff. |
| **No IP/UA/device binding** (`Inference:` from complete-doc absence) | **Convergent (tentative)** | Fortress's public docs argue against IP/UA binding and rely on rotation instead — but `Inference:` doc silence establishes neither the runtime behavior nor the design intent of the license-gated code, so treat "Fortress does not bind" as a documented stance, not confirmed behavior. WP Sudo independently avoids IP/UA binding too, binding to the login-session token instead. The convergence is suggestive, not a verified validation of WP Sudo's call. |
| **Capability-stripping as the surface-agnostic backstop** | **Recommend against** (see below) | Conflicts with WP Sudo's challenge-on-action UX and is *weaker* than the existing effect-hook guard for the escalation threat. |

### Recommended against: a capability-stripping backstop

Fortress's actual security boundary is cap-stripping — filtering ~38 protected capabilities out
of a user's session at runtime (`Inference:` almost certainly the `user_has_cap`/`map_meta_cap`
filters; the exact hook is **not** named in their docs) whenever sudo has lapsed. They explicitly
separate this from path-matching, which their docs call *UX only*. That separation **validates**
WP Sudo's own split between challenge/redirect and hard effect-backstops. Adopting the cap-strip
mechanism itself, however, is the wrong move for WP Sudo, for two independent reasons:

1. **It is weaker than the effect-hook guard for the escalation threat.** Cap-stripping reinforces
   authorization but cannot catch a path that *skipped* the cap check — exactly the broken-access-control
   shape WP Sudo's escalation guard is built to stop by hooking the **effect** (`{prefix}capabilities`
   write, `grant_super_admin`). This is already recorded as escalation-guard blind-spot #1 in
   [ROADMAP.md](ROADMAP.md) ("a capability-based check … carries a high false-positive cost … needs a
   design before any attempt"); the Fortress comparison confirms that assessment rather than reopening it.
2. **It forces a "can't-see-until-sudo" UX WP Sudo deliberately rejected.** A runtime cap strip fires on
   *render-time* checks too (e.g. `current_user_can('activate_plugins')` used to decide whether to draw a
   page), so a non-sudo user cannot even see the admin surface. WP Sudo's chosen model is see-the-page,
   challenge-on-action, and its effect-hook set is intentionally narrow (true destructive effects only) so
   it never trips render-time checks — a better fit for that UX, and it also mirrors the inverse of the
   capability-*escalation* model WP Sudo abandoned in v2.

If a cap-layer backstop is ever revisited, it should be an **opt-in "hardened mode,"** not a default, and
only after the false-positive design work noted in the ROADMAP blind-spot item.

### Verification caveats

- Fortress's implementation source is **license-gated and unread**; all Fortress mechanisms above are from
  their public `beta`-branch docs.
- `Inference:`-marked items (no IP/UA binding; the exact cap-strip hook; absence of password-change
  revocation and explicit XML-RPC/non-`fort` CLI gating) are reasoned from complete-doc absence, not from an
  explicit Fortress statement or code.

## Assumptions and Limits

- This is a comparative analysis, not an implementation guide.
- Proposed model analysis is conceptual and intentionally avoids runbook-level details.
- Statements marked `Inference:` are reasoned conclusions where source documents do not specify exact behavior.
- Findings are constrained to the referenced documents and code paths, reviewed on the dates noted: the WP Sudo and proposed-model characterizations from the **March 3, 2026** baseline review; the **Fortress** characterization, its four added sources (installation, configuration reference, license, GridPane KB), and the session/sudo-module design-borrowing assessment from the **July 25, 2026** re-verification. Fortress's runtime source is license-gated and not public, so its characterization is scoped to those public docs/license/KB, not to reading the enforcement code.

## References

- WP Sudo local references:
  - [Security Model](security-model.md)
  - [Developer Reference](developer-reference.md)
  - [`includes/class-gate.php`](../includes/class-gate.php)
  - [`includes/class-sudo-session.php`](../includes/class-sudo-session.php)
  - [`includes/class-plugin.php`](../includes/class-plugin.php)
- Fortress references (public docs, license, and KB; implementation source is license-gated and not read):
  - [The Fortress sudo mode](https://raw.githubusercontent.com/snicco/fortress/beta/docs/modules/session/sudo-mode.md)
  - [Session management and security](https://raw.githubusercontent.com/snicco/fortress/beta/docs/modules/session/session-managment-and-security.md)
  - [Custom session storage — per-token rows via the session_token_manager drop-in](https://raw.githubusercontent.com/snicco/fortress/beta/docs/modules/session/custom-session-storage.md)
  - [Installation — must-use loader + server integration](https://raw.githubusercontent.com/snicco/fortress/beta/docs/getting-started/02_installation.md)
  - [Configuration reference — timeout defaults, protected caps/pages](https://raw.githubusercontent.com/snicco/fortress/beta/docs/configuration/02_configuration_reference.md)
  - [License — proprietary EULA (Snicco Media); §3.1–3.2 bar reverse-engineering and competitive use](https://raw.githubusercontent.com/snicco/fortress/beta/LICENSE.txt)
  - [GridPane — Fortress installed as a must-use plugin](https://gridpane.com/kb/fortress-security-part-2-quick-start-configuration-guide/)
- User Switching reference:
  - [User Switching (WordPress.org plugin documentation)](https://wordpress.org/plugins/user-switching/)
