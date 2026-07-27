# Action Gate Research Program — Architecture Charter

**Status:** Current planning authority for the next research track
**Program type:** WordPress core proposal + executable demonstrator
**Product posture:** Research only; no production plugin release is implied

## The problem

An authenticated WordPress administrator session can reach effects that introduce
executable code. That makes an XSS or copied-session report look like an indirect
RCE even when the original defect cannot execute PHP by itself.

The narrow objective is to make an ordinary authenticated session insufficient to:

- upload executable plugin or theme code; or
- write executable code through the plugin or theme file editors.

This program does not attempt to classify every consequential action or make the
WP Sudo plugin a complete security boundary.

## The architecture to prove

The target has three cooperating layers:

1. **Early server veto.** WordPress core exposes a structured, veto-capable check
   immediately before a named irreversible effect. A refusal must stop the effect
   without partial writes or false-success responses.
2. **Preflight client.** An integrated wp-admin surface pauses the user's action
   before sending the original request, asks the server what approval is required,
   and preserves the unsent form, file, and validation state in the page.
3. **Action-bound, single-use proof.** Reauthentication authorizes one precisely
   described effect. The proof binds the actor, login session, action, target, and
   security-relevant parameters; it expires quickly and is consumed atomically.

Only layer 1 is the non-bypassable boundary. Layer 2 is progressive enhancement.
Layer 3 connects the human approval to the server veto.

The intended integrated flow is:

```text
user initiates action
  → client keeps the request local
  → server preflight describes the required approval
  → trusted reauthentication and confirmation
  → server issues one action-bound proof
  → client sends the original request once with that proof
  → early server veto redeems the proof or refuses the effect
```

## Security claims that must remain separate

The research must not use “stolen session” as one undifferentiated attacker.

| Attacker | Candidate protection | Honest limit |
|---|---|---|
| Copied authentication cookie in a different browser | Separate, action-bound proof unavailable to the clone | A later theft of the complete approved browser state may include usable material until it expires |
| Session-riding request from an XSS | Trusted confirmation must name the effect and require a deliberate approval | A same-origin script can manipulate the compromised admin document; an ordinary modal in that document is not a trusted boundary |
| Active same-origin XSS throughout the flow | Browser-mediated credential or isolated top-level/popup confirmation may raise the bar | This is not considered closed until the handoff, persistence reach, and browser-isolation assumptions are tested explicitly |
| Malicious installed plugin or arbitrary server-side PHP | None | Server-side in-process code can bypass WordPress policy; plugin sandboxing is a different project |

No proposal may claim to close “XSS → RCE” generally unless its evidence covers
the active-XSS row. Otherwise it must name the narrower copied-cookie or
session-riding property that was actually demonstrated.

## Architectural decisions

### Current decisions

- Gate the **effect**, not a screen, route, registry entry, or capability check.
- Seek a small set of **early veto points** in core before broad API design.
- Pause before transmission; never restore automatic server-side request replay.
- Use reauthenticate-then-resubmit as the no-integration fallback.
- Do not treat a WordPress nonce as proof of human intent. A cloned session can
  mint valid nonces.
- Do not use a reusable “recently authenticated” window for the in-scope effects.
- Start with plugin/theme upload and plugin/theme file-editor writes only.
- Preserve the registry idea as a possible later companion. It is not a
  dependency, enforcement point, or current implementation phase.
- Accept that Cut 1 therefore has no general registration API for plugins to
  declare and gate their own consequential effects. Cut 1 protects only the
  privately defined core effects in its patch; ecosystem extensibility is a
  deliberate later design question.
- Treat the current WP Sudo plugin, its in-editor UX, and existing PoCs as
  evidence sources—not as code that must be evolved or shipped.

### Decisions Phase 27 must settle

- The exact core veto-filter contract and error propagation for both effects.
- Whether Cut 1 exposes only two private core descriptors or a deliberately tiny
  invocation contract without a general registry.
- Which fields form each action digest and how upload bytes are represented.
- Where trusted reauthentication and final confirmation render.
- How the proof reaches the original page without becoming a general bearer
  available to compromised same-origin script.
- Whether active same-origin XSS is in the first closure claim or an explicitly
  deferred attacker.
- Atomic proof issuance/redemption and behavior when a persistent object cache is
  unavailable or hostile.
- Preflight authorization, response minimization, rate limiting, and diagnostics
  for integrated screens that fail to preflight.

No client implementation begins while these are unresolved.

### Why the registry is deferred

The registry was never load-bearing. A naming or taxonomy layer cannot refuse an
effect, and the earlier spec already required enforcement at the effect
chokepoint whether or not a registry existed. Leading with the registry inverted
that dependency and bundled a security fix with independently debatable API
questions such as identifier ownership and metadata shape.

Deferring it is not cost-free: plugin authors cannot opt their own effects into
Cut 1. That limitation is preferable to presenting an unproven general API as a
prerequisite. Extensibility should follow evidence that the boundary and approval
protocol work for the two core effects.

## How existing work is used

| Existing effort | Keep | Do not carry forward |
|---|---|---|
| WP Sudo | Attack-path inventory, test harnesses, reauth integrations, failure history | Production posture, broad rule registry, route-checker predictions, request stash/replay |
| In-editor reauth | Evidence that pause-before-send preserves work and can feel native | The assumption that a same-document password modal resists active XSS |
| `poc/install-package-gate` (Slice A) | Preserve as an immutable evidence fixture: its real-core differential test, pre-unpack finding, actor classification cases, and cloned-cookie test seed Phase 28/29 | Reusing its 15-minute proof window, transient trust, or treating two filters as a complete boundary |
| `wip/coregate-unit1` | Verified core seam inventory and tests worth extracting | The former registry-first and server-carried-request architecture |
| `consequential-actions` | Preserve its existing tagged MVP as historical evidence; reuse its small Playground attack narrative, narrator, and comparative demo harness in a clearly named successor demo | Its per-user window, inline password modal, registry-first pitch, or production-security claims |
| Fortress references | Historical comparison where directly relevant | Requirements, naming, or architecture justified by analogy |

## Program guardrails

- Every security claim needs a negative test that fails when the relevant guard is
  mutated or removed.
- A green broad suite is not evidence that a specific guard works.
- Core-source claims are verified against a pinned `wordpress-develop` commit and
  recorded with that SHA.
- No phase expands the action catalog. New effects are follow-up work after the
  two-action slice passes.
- No production release is cut for planning progress. A research prerelease is
  justified only by a reproducible demonstration that materially advances the
  core proposal.

## Definition of done

The program succeeds when a reviewer can run one reproducible environment and
observe:

1. both in-scope effects succeed normally without the experimental gate;
2. the same requests are refused at the effect boundary without an adequate
   proof and cause no partial effect;
3. a copied-cookie browser cannot obtain or redeem another browser's approval;
4. an integrated wp-admin flow preserves unsent work and sends the operation once;
5. the no-JavaScript path fails safely and never auto-replays;
6. the documentation states exactly which XSS/session attacker was and was not
   stopped; and
7. the proposed core changes are narrow enough to review independently of a
   general actions registry or the WP Sudo plugin.
