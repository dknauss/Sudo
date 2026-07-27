---
status: current
applies_to: 4.9.x (research prototype — see PROJECT-STATUS.md)
last_verified: 2026-07-27
canonical_for: forward roadmap (Now / Next / Deferred / Later / Non-goals)
---

# Roadmap

Forward-looking only. Shipped work lives in [`CHANGELOG.md`](../CHANGELOG.md); open
work is tracked as GitHub issues; design analyses live in their own docs. Current
release state is canonical in [`release-status.md`](release-status.md). The full
backlog is in the [issues](https://github.com/dknauss/Sudo/issues), labeled
`priority: high` / `medium` / `low`.

**What earns a place in Now/Next, since the reclassification.**
[`PROJECT-STATUS.md`](../PROJECT-STATUS.md) makes this a research demonstrator and says
a snapshot is published "only when it materially supports the conceptual project or an
upstream WordPress core proposal." That is a priority *filter*, and this file is where it
bites: work that makes the plugin a better product for operators running it in production
no longer sorts to the top, because production is out of scope. **Now** and **Next** are
therefore the core-gate program plus the findings that keep the demonstrator honest.
Operator-product work is not cancelled — it is parked in *Deferred while the project is a
research prototype* below, so the ordering reflects the stated status rather than
contradicting it.

## Now

- **Core-gate PoC, Slice B — identity pivots** ([#360](https://github.com/dknauss/Sudo/issues/360),
  the sole open item in the [Cut 1-readiness milestone](https://github.com/dknauss/Sudo/milestone/3),
  which is otherwise 23/24 closed). Slice A landed (#380) as a plugin at
  `poc/install-package-gate/` wired into CI as `composer test:poc`, and needed **no** core
  patch — `WP_Upgrader::install_package()` already carries a vetoing `upgrader_pre_install`
  short-circuit. Slice B is the part that needs a real `wordpress-develop` branch, because
  six identity-pivot sinks cannot refuse at all, in three distinct ways (each verified
  against WordPress 7.0 source, not against the summary table in #360, which has
  `grant_super_admin()` wrong): `wp_delete_user()` (`wp-admin/includes/user.php`) and
  `wpmu_delete_user()` (`wp-admin/includes/ms.php`) return `bool`, where a `WP_Error` is
  **truthy** — a refusal reads to the caller as success; `set_role()`, `add_role()`
  (`WP_User`, bare `return;`) and `switch_theme()` (`wp-includes/theme.php`, no `@return`)
  return `void` and silently no-op; and `grant_super_admin()`
  (`wp-includes/capabilities.php`) returns `bool` — **not `void`** — but discards the
  `update_site_option( 'site_admins', … )` return and then returns `true` unconditionally,
  so a failed privilege write already reports success today. That last one is a stronger
  argument for the seam work than a silent no-op would be: the sink does not merely fail
  to refuse, it actively misreports. It needs `pre_*` filters modelled on
  `pre_delete_post`.
  Unblocked: #358 (the chokepoint veto audit) is closed, and the "starts after 4.9.0 is
  tagged" condition cleared on 2026-07-27. Scope: `.planning/360-poc-patch-branch-scope.md`.
  The test that decides it: **two real browsers, cloned session** — browser B holds a copy
  of A's auth cookie and must not inherit A's elevation.
- **Post-4.9.0 correctness followups that bear on the research claims** — chiefly
  [#354](https://github.com/dknauss/Sudo/issues/354) (Site Health's stale-session cleanup
  deletes live proof maps: it classifies staleness from the scalar marker read *through the
  object cache*, while enforcement reads the signed proof *cache-bypassed*, so the exact
  cache-invalidation failure #278 exists to tolerate can wipe every valid proof) and
  [#404](https://github.com/dknauss/Sudo/issues/404) (an upgrade replay from `0.0.0` grants
  governance caps to every administrator, because the routine cannot distinguish "predates
  governance caps" from "deliberately has none"). Both are reachable in the v4.9.0 tag the
  public Playground badge installs.

## Next

- **Preflight demonstrator, plugin side** — the two-operation demonstrator named in
  "4.10 — Action-confirmation API" below (plugin/theme upload, file-editor save). This is
  the plugin's contribution to the core proposal rather than a product feature: it is where
  the `click → pause → preflight → proof → confirm → submit once` protocol gets exercised
  against real screens before core is asked to adopt it. Deliberately two operations, not a
  `wp-admin` modernisation.
- **Session-store architecture** — evaluate and likely implement a dedicated
  sudo-session table (authoritative table + usermeta shadow writes). Retained near the top
  because it answers **open question 2** of the core proposal — whether the proof record
  should build on `WP_Session_Tokens` or a dedicated store — so the plugin's choice here is
  evidence for the core one, not just plumbing. Design:
  [`session-store-evaluation.md`](session-store-evaluation.md). Snicco Fortress's
  per-token session-row model (one row per token via the `session_token_manager`
  drop-in) independently validates this direction — see the design-borrowing
  assessment in
  [`sudo-architecture-comparison-matrix.md`](sudo-architecture-comparison-matrix.md#design-borrowing-assessment-fortress-session--sudo-patterns).
- **Multisite terminology + coverage pass** — remaining Core-Trac-alignment work:
  standardize "network administrator" vs. "super admin"; review network-level
  gated-action coverage. Maps to Trac [#20140](https://core.trac.wordpress.org/ticket/20140).
- **Test-scaffolding hardening** — blueprint rot-guard smoke lane (do first),
  tag-pinned blueprint copies at each release, and run the release environment matrix
  every release. **Raised in importance by the reclassification, not lowered:** Playground
  is now one of the few sanctioned ways to run this at all, so a rotted blueprint is no
  longer a broken demo link — it is the evaluation path itself failing.
- **Screenshot refresh for the in-editor session indicator** ([#262](https://github.com/dknauss/Sudo/issues/262)
  client UI shipped in PR #277) — the indicator itself is now implemented and live-verified: a
  `core/notices` snackbar baseline (WP 6.4+ floor) plus a feature-detected `PluginSidebar` "Sudo"
  panel with a live countdown (WP 6.6+, degrading to the snackbar), fed by the #204 `is_active()`-gated
  `remaining` value and a `wp-sudo-session-granted` grant event. The remaining forward work is a
  **README/readme screenshot refresh** to add the net-new editor sidebar + snackbar surface to the
  public screenshot set (AGENTS.md → "UI screenshot planning"), folded into the next screenshot batch.
  A second, optional enhancement (out of the v1 brief scope): **cross-tab grant sync** — the v1
  indicator re-seeds only in the tab that granted (feed #2 is a same-`window` event) and via feed #1
  on any later page load; an already-open editor in a *different* tab, or a grant completed on the
  standalone challenge page, won't live-update until reload. A `BroadcastChannel`/`storage`-event
  channel could propagate grants to every open editor. Informational-only, so the staleness is
  cosmetic and self-heals (the server stays authoritative and re-challenges as needed).
  Distinct from the shipped in-editor reauth *modal* (Milestones A/B, v4.6/4.7).

## Deferred while the project is a research prototype

Not cancelled, and not judged low-value — these are the **operator-product** layer, and
[`PROJECT-STATUS.md`](../PROJECT-STATUS.md) puts the operators they serve out of scope. They
sort below the core-gate program until that status changes, and several would only be
*testable* on the production and multisite networks the project now tells people not to use.
They are recorded here rather than in Now/Next so the ordering matches the stated status; the
issues stay open.

- **Cross-site (network-admin) session revocation** ([#239](https://github.com/dknauss/Sudo/issues/239)) —
  network revoke-all / by-user / by-site for incident response, reusing
  `Sudo_Session::deactivate()`. Still labeled `priority: high`, which is now a priority
  *within this deferred layer*, not against the core-gate work.
- **Network-wide role/capability lockdown sweep** ([#219](https://github.com/dknauss/Sudo/issues/219)) —
  the 4.8.0 lockdown audit covers only the current blog
  (`Role_Audit::collect_current_state()` uses `get_current_blog_id()`; `diff()` iterates a
  single site), so subsite privilege drift is invisible to one `wp sudo manifest diff` /
  Site Health / cron run. Walk the network (manifest sites, or all blogs via
  `switch_to_blog()`), keep the cache-bypass reads per-blog, and consider a
  `--site=<id|url>` scope flag with batching. Remains a **documented MVP limitation** until
  shipped — that documentation obligation is not deferred.
- **Sudo Activity screen + export surface** — a dedicated list-table Activity admin screen
  (search, sortable columns, CSV export with capability + nonce checks) giving the reserved
  `export_wp_sudo_activity` capability a UI surface. Keep it lean and prepare it for
  External Audit Mode delegation, with audit-visibility integrity warnings.
- **Network admin tools, Protection Status panel, expanded Site Health, inline policy help**
  ([#243](https://github.com/dknauss/Sudo/issues/243)–[#247](https://github.com/dknauss/Sudo/issues/247),
  [#249](https://github.com/dknauss/Sudo/issues/249)) — the operator-console cluster in the
  post-4.9.0 milestone.

## 4.10 — Action-confirmation API (design)

**The organising idea for 4.10, and a correction to how this project has framed the
problem.** Everything below is design direction, not committed scope.

### The defect class it closes

`#429`, `#431`, `#436` and the settings-blanking path found in `#444` are not four
bugs. They are four symptoms of one thing: **the gate discovers the action only after
the request has been submitted.** By then the server holds an opaque already-sent
request, and every option available to it is bad:

| option | what it cost us |
|---|---|
| discard it | `#429` — the user lands on a screen with no form and the typed input is gone |
| stash and auto-replay | the confused deputy `#322` exists to close |
| reconstruct after reauth | the settings-blanking path — and for Settings-API pages this is **impossible**, not merely hard |

That third row is the load-bearing evidence. `wp-admin/options.php` initialises
`$value = null` and calls `update_option()` **unconditionally** for every option in
`$allowed_options[$option_page]`, so a body carrying a subset does not save a subset —
it blanks the remainder (`GB-OPTIONS-NULL-WRITE`). No allowlist narrower than the whole
page can reproduce a save, and a wider one carries fields the confirmation cannot name.
`options.critical` is therefore non-replayable as of `#444`. Reconstruction is not a
tuning problem for that class of screen; it is unavailable.

The deeper point is that intercept-after-submit makes **reauthentication stand in for
authorization**. A password proves a person is present. It cannot prove they intended
*this* action, because the action was chosen before the challenge began and was never
shown to them. Only a deliberate final click on a named action supplies intent.

### The flow

```
click → pause locally → server preflight → trusted reauth UI
      → action-bound proof → user confirms → original request sent once
```

Properties that matter:

- Intercept the click or submit **in the browser, before transmission**.
- Identify the action and target from **server-defined metadata**, not from guessing at
  URLs and field names. `Request_Stash::TARGET_PARAMS` is that guess, and `#431` is what
  guessing costs: it named `default_role` on a Site Title change, and named *nothing* on
  an administration-email takeover because core's field is `new_admin_email`.
- Ask the server whether fresh authentication is required, rather than inferring it.
- Reauthenticate through a **standard component**, so plugins integrate without
  implementing authentication themselves.
- Issue a **short-lived, single-use proof** bound to user, session, action, target and
  the parameters that matter.
- Show a final confirmation naming the concrete operation — "Upload plugin `acme.zip`".
- Send the original request **once**, carrying that proof.
- Re-enforce the proof at an **early server-side veto point**.

Form contents never leave the page: passwords, uploads, selections and validation state
stay in the document, so nothing sensitive is stored server-side awaiting replay.

### One effect does not always mean one veto point

The seam argument is usually stated as "one insertion reaches admin UI, REST, CLI and
in-process callers," and for the identity pivots that holds. It does **not** hold
universally, and the exception is already empirical rather than hypothetical: Slice A of
the core PoC found that `unpack_package()` extracts the archive into `wp-content/upgrade/`
**before** `install_package()` is ever reached ([#387](https://github.com/dknauss/Sudo/issues/387)),
so gating the nominal chokepoint alone still lets attacker-controlled files land on disk.
The slice gates `upgrader_pre_download` as well.

State the rule as: **the veto belongs at the last point where the effect can still be
refused, which is not always the function that names the effect.** Some operations need
more than one. Writing this down matters for the core proposal specifically — a reviewer
who finds the upgrader exception themselves will discount the whole one-seam framing,
whereas the framing survives fine once the exception is part of it.

### Three layers, and only one of them is the boundary

1. **Early server veto** — the non-bypassable enforcement layer. Unchanged in kind from
   what ships today; this is what covers direct URLs, REST clients, legacy forms,
   third-party admin pages and compromised scripts.
2. **Preflight client** — the editor-like experience, as progressive enhancement.
3. **Action-bound single-use proof** — what actually connects the reauthentication to
   the operation the user approved.

Layer 2 is UX and must never be mistaken for the boundary. Screens without integration
fall back to **reauthenticate-then-resubmit**. Automatic replay is never the fallback.

### Prevent-at-initiation is broader than the editor

The plugin already ships one instance of this pattern with no JavaScript integration at
all: `Gate::filter_plugin_action_links()` greys out gated links so the action cannot be
started. That is the cheap rung, it works on ordinary admin pages, and it is why plugin
activation was the one flow in this release that never lost anyone's work. The ladder is
three rungs, not two: **prevent at initiation** where the surface is renderable,
**preflight** where JavaScript owns the request, **server veto with an honest
fail-closed landing** everywhere else.

### The caveat that constrains the design

An ordinary same-origin modal is **not intrinsically trustworthy against active
admin-page XSS**. Malicious page script can inspect or imitate it, so password entry
into a potentially compromised admin document cannot be treated as fully protected. The
strongest form uses a browser-mediated credential (WebAuthn/passkeys) or a separately
isolated authentication surface.

This bears directly on a standing decline below: passkeys were declined in 2026-02-28 on
**UX** grounds — OS biometric autofill already covered it. That reasoning does not
answer an XSS-resistance argument, which is a security claim. The decline is not
reversed here, but it must be re-decided on the new grounds rather than inherited.

**Unresolved, and it needs deciding before the core proposal is submitted.** The XSS
caveat and the proposal's own non-goals currently point in opposite directions from the
same fact. Proposal §5 already concedes the gate "cannot stop code already running
in-process" — and active script in the admin document *is* code running in-process. So
either:

- **scope it out**, consistently: active admin-page XSS is outside the boundary, the same
  way in-process code already is, and the modal is then a perfectly acceptable rendering
  of a challenge whose security rests on the server-side veto and the digest-bound proof;
  or
- **scope it in**, and a browser-mediated factor stops being a "Later" item and becomes
  part of the Cut 1 challenge-provider contract.

What should not ship is the current in-between, where the flagship UX is documented as
untrustworthy against a threat the proposal neither excludes nor defends. The cheaper and
more defensible option is the first: it costs nothing, it makes two documents agree, and
it does not commit core to a credential mechanism in its first patch. Recorded as an open
decision rather than settled here, because it is a threat-model change and belongs to the
maintainer.

### Proposed demonstrator

Implement it for **two** high-value operations only — plugin/theme upload, and
file-editor save. Enough to establish the UX and the proof protocol; far short of
modernising every `wp-admin` form. Scope creep here would repeat the mistake this
release made in the small.

### Related

`#436` (multi-rule form described by one rule), `#445` (`options.critical` gates on
presence rather than change), `#446` (REST aliases missing from `TARGET_PARAMS`), and
the core proposal in
[`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md) — whose
confirm-then-redeem shape is the same idea at the core layer.

## Later (need design work)

- **Standard wp-admin preflight and action confirmation** — the preferred successor to
  server-side stash/replay, and the plugin-side counterpart of "4.10 — Action-confirmation
  API" above. Opted-in screens pause before sending, obtain a server-canonical
  action/target digest, reauthenticate through the standard provider UI, and submit once
  with a short-lived one-use proof. Legacy screens fall back to
  reauthenticate-then-resubmit. Start with a narrow demonstrator for plugin/theme upload
  and file-editor save; **do not build a generic request-capture/replay layer.** An
  ordinary same-origin modal is not an XSS security boundary, so the design must support
  browser-mediated factors or an isolated provider surface. Normative core direction:
  [`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md)
  §5.1/§7.1.

  This entry replaces **"Client-side modal challenge (GitHub-style inline reauth)"**,
  whose stated rationale was "no security gain over stash → challenge → replay". That
  is no longer defensible: the replay path carries a cost the in-page path does not,
  demonstrated in the 4.9.0 cycle by a reachable settings-blanking route and by
  reconstruction being *impossible* for Settings-API screens. If built, re-evaluate the
  password-first OS-autofill decision (see
  [`security-model.md`](security-model.md#reauthentication-flow-password-first-design-rationale)).
- **REST sudo-grant endpoint** (`POST /wp/v2/sudo`) for headless clients.
- **Per-session / device sudo isolation** via `WP_Session_Tokens` — deferred:
  architectural, not a hardening item.
- **Per-rule / per-action sudo TTL** ([#269](https://github.com/dknauss/Sudo/issues/269), `priority: low`) —
  a shorter sudo window for the highest-risk
  rules (e.g. `user.delete`, `options.critical`) than for routine ones (e.g.
  `plugin.activate`), instead of one global `session_duration`. Borrowed (adapted to
  WP Sudo's per-rule, role-agnostic model) from Fortress's per-capability timeout
  tiering; see the design-borrowing assessment in
  [`sudo-architecture-comparison-matrix.md`](sudo-architecture-comparison-matrix.md#design-borrowing-assessment-fortress-session--sudo-patterns).
  Small, unscheduled; needs a design pass on how a per-rule TTL interacts with an
  already-active broader window before any implementation.
- **SSO / SAML / OIDC provider framework** — a provider interface parallel to the 2FA hooks.
- **Third-party bridge discovery mode** — a report-only scanner for plugin
  AJAX / admin-post / REST entry points (not a generic hook firewall).
- **Network admin tools** — cross-site session widget, super-admin visibility
  controls, cross-site session revocation, and a network policy hierarchy (site admins
  can only tighten).
- **Abilities API / MCP** — monitor-only; add a REST rule to `Action_Registry` when a
  destructive core ability (`DELETE` on `/run`) appears. Analysis:
  [`abilities-api-assessment.md`](abilities-api-assessment.md).
- **Internal admin least-privilege governance** — see
  [`archive/internal-admin-governance-spec.md`](archive/internal-admin-governance-spec.md).
- **Request-stash conservative pattern redaction** — broaden beyond the suffix list
  (camelCase secrets such as `clientSecret`); a future option, not an open gap.
- **Environment-diversity and mutation testing** — deferred testing milestones.
- **WordPress core recent-auth gate proposal** — the strategic core work
  ([`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md) and
  companions; Trac #20140).
- **General Consequential-Actions registry/API** — possible future complement,
  not part of the core gate Cut 1. Revisit only when a concrete consumer proves
  the need for a public taxonomy and metadata contract; do not pattern it on
  Abilities by default.

## Non-goals / Declined

- **Session extension without reauth** — undermines the time-bounded trust model.
- **Passkey / WebAuthn as a standalone reauth factor** — declined 2026-02-28 on **UX**
  grounds: OS biometric autofill already covers the experience. (Key
  registration/deletion *gating* is a separate concern, shipped via the WebAuthn bridge.)
  **Open to re-decision on different grounds:** the 4.10 preflight direction raises an
  XSS-resistance argument — a same-origin modal cannot be trusted against active
  admin-page script — which the 2026-02-28 reasoning never addressed. Still declined
  until re-decided; recorded here so the decline is not inherited as though it answered
  that question.
- **`compatibility` governance mode** — removed in v4.0.0; not returning.
- **`enforce_editor_unfiltered_html` relocation / REST early-exit micro-opts** — no
  measured benefit; relocation would add a front-end detection gap.

---

## Backlog

Feature/enhancement candidates and open remediation items are tracked as
**GitHub issues**, not in this file. See the
[open issues](https://github.com/dknauss/Sudo/issues) — filter by
[`enhancement`](https://github.com/dknauss/Sudo/labels/enhancement) or
[`security`](https://github.com/dknauss/Sudo/labels/security).

The former roadmap backlog (feature backlog, multisite network-admin tools, and
internal-admin governance) plus the open post-v3.1.3 remediation items were migrated
to issues [#238](https://github.com/dknauss/Sudo/issues/238)–[#257](https://github.com/dknauss/Sudo/issues/257),
alongside the earlier [#219](https://github.com/dknauss/Sudo/issues/219) (network-wide
lockdown sweep) and [#67](https://github.com/dknauss/Sudo/issues/67) (lockout blueprint).
Shipped work is in the [CHANGELOG](../CHANGELOG.md); the archived v3.1–v3.3
security/governance execution record is
[`archive/execution-plan-v3.1-v3.3.md`](archive/execution-plan-v3.1-v3.3.md).
