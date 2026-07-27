---
status: current
applies_to: 4.9.x
last_verified: 2026-07-27
canonical_for: forward roadmap (Now / Next / Later / Non-goals)
---

# Roadmap

Forward-looking only. Shipped work lives in [`CHANGELOG.md`](../CHANGELOG.md); open
work is tracked as GitHub issues; design analyses live in their own docs. Current
release state is canonical in [`release-status.md`](release-status.md). Near-term work
is grouped in the [**post-4.9.0** milestone](https://github.com/dknauss/Sudo/milestone/5);
the full backlog is in the [issues](https://github.com/dknauss/Sudo/issues), labeled
`priority: high` / `medium` / `low`.

## Now

- **Network-wide role/capability lockdown sweep** ([#219](https://github.com/dknauss/Sudo/issues/219)) —
  the 4.8.0 lockdown audit covers only the current blog
  (`Role_Audit::collect_current_state()` uses `get_current_blog_id()`; `diff()`
  iterates a single site), so subsite privilege drift is invisible to one
  `wp sudo manifest diff` / Site Health / cron run. Walk the network (manifest sites,
  or all blogs via `switch_to_blog()`), keep the cache-bypass reads per-blog, and
  consider a `--site=<id|url>` scope flag with batching for large networks.
  `priority: high`, in the [post-4.9.0 milestone](https://github.com/dknauss/Sudo/milestone/5); documented MVP limitation until shipped.

## Next

- **Cross-site (network-admin) session revocation** ([#239](https://github.com/dknauss/Sudo/issues/239), `priority: high`, post-4.9.0) —
  network revoke-all / by-user / by-site for incident response, reusing
  `Sudo_Session::deactivate()`.
- **Session-store architecture** — evaluate and likely implement a dedicated
  sudo-session table (authoritative table + usermeta shadow writes). Design:
  [`session-store-evaluation.md`](session-store-evaluation.md). Snicco Fortress's
  per-token session-row model (one row per token via the `session_token_manager`
  drop-in) independently validates this direction — see the design-borrowing
  assessment in
  [`sudo-architecture-comparison-matrix.md`](sudo-architecture-comparison-matrix.md#design-borrowing-assessment-fortress-session--sudo-patterns).
- **Sudo Activity screen + export surface** — a dedicated list-table Activity admin
  screen (search, sortable columns, CSV export with capability + nonce checks) that
  gives the reserved `export_wp_sudo_activity` capability a UI surface. Keep it lean
  (recent events, short retention) and prepare it for External Audit Mode delegation.
  Include audit-visibility integrity warnings (flag when local passed-event logging is
  disabled or delegated coverage is missing).
- **Multisite terminology + coverage pass** — remaining Core-Trac-alignment work:
  standardize "network administrator" vs. "super admin"; review network-level
  gated-action coverage. Maps to Trac [#20140](https://core.trac.wordpress.org/ticket/20140).
- **Scoped single-user recovery form** ([#240](https://github.com/dknauss/Sudo/issues/240), `priority: high`, post-4.9.0) —
  `define( 'WP_SUDO_RECOVERY_MODE', <user> )`, plus a `Site_Health::test_recovery_mode()`
  status; the open follow-up to the hardened break-glass (Phase R3).
- **Test-scaffolding hardening** — blueprint rot-guard smoke lane (do first),
  tag-pinned blueprint copies at each release, and run the release environment matrix
  every release.
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
