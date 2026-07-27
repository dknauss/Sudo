# Versioning Policy

WP Sudo follows [Semantic Versioning 2.0.0](https://semver.org/). Semver is only
meaningful once "the public API" is defined, so this document does that first,
then states the bump rules and how they map to our commit conventions.

This file is the canonical source for **how a version number is chosen**.
`docs/release-status.md` remains the source for **current release state**, and the
version-sync checklist in `CLAUDE.md` remains the mechanical release procedure.

## What the public API is

A version bump is judged against this declared surface — the contracts external
code (themes, other plugins, site glue code, WP-CLI users, integrations) can
depend on. The canonical, signature-level list lives in
[`docs/developer-reference.md`](docs/developer-reference.md); this is the summary
of what is *covered*:

- **Global functions** — `wp_sudo()`, `wp_sudo_check()`, `wp_sudo_require()`,
  `wp_sudo_can()`, `wp_sudo_governance_caps()`, `wp_sudo_map_governance_meta_cap()`,
  `wp_sudo_is_recovery_mode()`, `wp_sudo_recovery_mode_is_unscoped()`,
  `wp_sudo_recovery_mode_user()`, `wp_sudo_user_matches_recovery()`,
  `wp_sudo_build_challenge_url()`.
- **Filters** — e.g. `wp_sudo_gated_actions`, `wp_sudo_guard_escalation`,
  `wp_sudo_allow_escalation`, `wp_sudo_cookie_secure`, `wp_sudo_grant_session_on_login`,
  `wp_sudo_requires_two_factor`, `wp_sudo_two_factor_window`, `wp_sudo_validate_two_factor`,
  `wp_sudo_log_passed_events_enabled`, `wp_sudo_wpgraphql_bypass`, `wp_sudo_wpgraphql_classification`.
- **Action (audit) hooks** — the documented `wp_sudo_*` `do_action` hooks and their
  argument signatures (session lifecycle, gated actions, policy presets, escalation
  blocks, session revocation, tamper detection, etc.).
- **The gated-rule structure** — the array shape a `wp_sudo_gated_actions` callback
  receives and returns (rule `id`, matcher keys, category, etc.).
- **Documented class API used by integrations** — the `Sudo_Session` methods and
  constants documented in the developer reference (`is_active()`, `is_within_grace()`,
  `activate()`, `deactivate()`, `GRACE_SECONDS`).
- **WP-CLI commands** — the `wp sudo` command names, arguments, and output contract.
- **The `sudo_required` soft-block payload** — the documented error code/shape
  returned to AJAX/REST clients.
- **Public constants** — `WP_SUDO_VERSION`, `WP_SUDO_RECOVERY_MODE`, and other
  documented `WP_SUDO_*` constants.
- **The settings option contract** — the `wp_sudo_settings` option keys that are
  documented as stable.
- **Slug and text domain** — `wp-sudo` (changing either is a breaking change).

## What the public API is NOT

Changes confined to the following are **not** API changes and do **not**, on their
own, warrant a MINOR or MAJOR bump:

- **Internal classes and private/protected methods** — anything not documented in
  the developer reference (e.g. `Gate` internals, `Challenge`, `Request_Stash`,
  `Admin` rendering helpers, the `User_Identity` display helper).
- **Admin-UI presentation** — markup, CSS, labels, layout, and wording of the
  settings pages, dashboard widget, Access tab, and challenge page.
- **Database table internals** — column layout of the events table and other
  internal storage, except where a documented contract depends on it.
- **Test code, tooling, CI, and documentation.**
- **Undocumented hooks/behavior** — if it is not in the developer reference, callers
  should not depend on it, and we do not treat it as covered.

## Bump rules

Given the surface above:

- **MAJOR (`X`.0.0)** — a backward-incompatible change to the declared public API:
  removing/renaming a function, filter, hook, CLI command, or constant; changing a
  documented signature or the meaning of a return value; changing the gated-rule
  structure incompatibly; raising the minimum WordPress/PHP requirement; changing
  the slug or text domain.
- **MINOR (`x`.`Y`.0)** — a backward-compatible **addition** to the declared public
  API: a new filter/hook/function/CLI command/constant, a new documented setting, a
  new optional parameter, or a new gated surface consumers can rely on.
- **PATCH (`x`.`y`.`Z`)** — a backward-compatible bug fix, security fix, internal
  refactor, or **admin-UI/UX change that touches no declared public API**.

### Security-forced inertness — the missing clause (added 4.9.0)

None of the three rules above covers the case where a **security fix makes a
documented parameter, argument, or event stop having its documented effect** while
leaving every symbol in place. It is not MAJOR (nothing is removed or renamed, no
signature changes, no return value changes meaning); it is not MINOR (nothing is
added); it is not PATCH (it is not backward-compatible). The rule was silent, and
4.9.0 was initially classified by reasoning that only *sounded* like it followed —
"removing it would be MAJOR, so keeping it makes this MINOR", which is a
non-sequitur. An external review caught that. This clause exists so the next one is
looked up rather than argued.

**Treat it as MINOR when all of these hold:**

1. The symbol, argument or hook **remains accepted** — nothing errors, nothing
   fatals, no caller has to change code to keep running.
2. The behaviour was removed because it **could not be made safe**, not for
   convenience, and the reasoning is recorded in `CHANGELOG.md` and
   `docs/release-status.md`.
3. The change is **disclosed prominently** in the changelog, the readme upgrade
   notice, and `docs/developer-reference.md` at the symbol's own entry — an
   integrator must be able to find out *why* their thing stopped working from the
   documentation for that thing, not by reading a security advisory sideways.

**Escalate to MAJOR when the inert thing is an event or signal with no successor
and integrators have no way to observe the change.** A silent event is worse than a
silent parameter: a parameter that stops taking effect eventually shows up as
visible behaviour, whereas an event that stops firing looks exactly like nothing
happening. Where a successor exists at the same lifecycle point (as
`wp_sudo_replay_refused` does for `wp_sudo_action_replayed`), MINOR is right and the
successor **must be named** in the docs.

**Worked examples, both 4.9.0 (#322):**

- **`wp_sudo_require()`'s `return_url` argument** became inert — still accepted,
  still emitted into the challenge URL, consumed by nothing. Automatic navigation to
  a requester-supplied destination after a successful challenge executes under the
  sudo authority just minted, and every attempt to filter the value while keeping the
  convenience failed on a case with nothing to filter. No successor exists, by
  design — but it is a parameter, not a signal, and its effect is directly
  observable, so **MINOR** with disclosure.
- **`wp_sudo_action_replayed`** became dormant — retained, documented, still
  subscribed, never fired. **MINOR** because `wp_sudo_replay_refused` fires at the
  same lifecycle moment with strictly more information. Had it gone dark with no
  successor, this clause would have made it MAJOR.

### Worked examples

- **4.5.1 (PATCH)** — PR #154 harmonized the dashboard-widget and Access-tab user
  presentation and fixed a `get_avatar()` `force`→`force_display` no-op. It added an
  *internal* `User_Identity` helper and changed admin markup/CSS only — no public
  hook, filter, function, or documented contract. Admin-UI change + bug fix →
  **patch**, even though it is user-visible. (Visible ≠ minor; the API contract, not
  the pixels, decides.)
- **4.6.0 (MINOR)** — this release was re-scoped up from a staged `4.5.1`. The
  headline is a *user-visible new capability* — block-editor in-editor
  reauthentication (a `sudo_required` snackbar link-out plus a logged-in-only
  `admin-ajax` nonce-refresh endpoint). That capability, taken alone, adds **no new
  declared public-API entry** (it is admin JS + an internal `Challenge` endpoint), so
  by the `4.5.1` rule above it would be *patch*-level — visible ≠ minor. What sets
  the floor at **minor** is that the same release shipped the optional Critical-Event
  Alert Bridge (#166), which adds **new documented public extension points** —
  `wp_sudo_critical_alert_events`, `wp_sudo_critical_alert_recipient`,
  `wp_sudo_critical_alert_throttle`, `wp_sudo_critical_alert_hourly_cap`,
  `wp_sudo_critical_alert_dispatch` (filters) and `wp_sudo_critical_alert_dispatched`
  (action), all documented in the developer reference. **Documented extension points
  shipped in the product count toward the public API even when they live in an
  optional `bridges/` mu-plugin** (that file carries its own `@version` for its
  internal iteration, but adding brand-new documented hooks to what WP Sudo ships is
  a backward-compatible API *addition*). New documented filters/action → **MINOR**.
  Lesson: bump for the *declared-API addition*, not merely because a feature is
  visible — the visible editor feature is the headline, the bridge hooks are the
  contract reason.
- **4.7.0 (MINOR, maintainer override) — a deliberate exception to the rule above.**
  The 4.7.0 payload completes the in-editor reauthentication modal that 4.6.0
  explicitly deferred: the in-place password modal with request re-dispatch
  (Milestone A) and the in-modal second factor (Milestone B). By the strict test
  this would be **patch**-level — it is block-editor JS plus a new internal
  `Challenge` AJAX endpoint (`wp_sudo_challenge_2fa_partial`), and it fires the
  **pre-existing, already-documented** `wp_sudo_render_two_factor_fields` action
  (in `docs/developer-reference.md`; a bridge-facing integration hook that predates
  this release) from a new context — the in-modal AJAX partial. **No new entry was
  added to `docs/developer-reference.md`, and no *new* symbol joins the declared
  public API.**
  It was nonetheless released as **MINOR** as a maintainer product-signaling
  decision: finishing a headline, previously-deferred capability warranted a minor
  rather than burying it in a patch. This is recorded here explicitly so the choice
  reads as an intentional override, not versioning drift — the default rule stands
  (visible ≠ minor; a completed internal-only feature is patch), and future
  releases should not treat "it's a big visible feature" as minor-qualifying on its
  own without a declared-API addition.
- **A new `wp_sudo_*` filter or `wp sudo` subcommand → MINOR.**
- **Removing `sudo_can()` in favor of `wp_sudo_can()` (4.0.0) → MAJOR.**

## Commit conventions and the `feat:`→minor trap

This repository uses [Conventional Commits](https://www.conventionalcommits.org/).
Automated version tools map `feat:` → MINOR and `fix:` → PATCH, so commit **type**
must reflect the *public-API* rule above, not merely whether something is
user-facing:

- Reserve **`feat:`** for additions to the **declared public API** (a new
  filter/hook/function/CLI command/setting/gated surface).
- Use **`fix:`**, **`refactor:`**, **`style:`**, or **`perf:`** for bug fixes,
  internal changes, and **admin-UI/UX work that adds no public API** — these are
  PATCH-level.
- Use a **`BREAKING CHANGE:`** footer (or `!`) only for a backward-incompatible
  change to the declared API → MAJOR.

Rule of thumb: if you cannot point to a new entry this change adds to
`docs/developer-reference.md`, it is not a `feat:`.

## Tag and release mechanics

- Releases are annotated tags `vX.Y.Z` cut from the release commit; the GitHub
  Release is published from that tag. Tagging/publishing is **maintainer-owned**.
- Every release keeps the five version-sync points in agreement (see the
  `AGENTS.md` version-sync checklist). This is a **manual** release-checklist step —
  `verify:metrics` checks `docs/current-metrics.md` (test/LOC/registry counts), **not**
  the version-sync points, so version-sync drift is caught by the checklist, not by an
  automated CI gate.
- The public "Try latest release" Playground badge loads `blueprint.json` from
  `main`, so its tag-ZIP target is bumped **after** the tag is cut, never before
  (a pre-tag bump would make the public demo fetch a missing ZIP).

## Pre-1.0

Not applicable — WP Sudo is past 1.0, so the rules above apply in full. (For any
future 0.x companion repo, note that under semver 0.x a MINOR may carry breaking
changes; such a repo needs its own stated stance.)
