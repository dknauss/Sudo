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
  `wp_sudo_is_recovery_mode()`, `wp_sudo_build_challenge_url()`.
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

### Worked examples

- **4.5.1 (PATCH)** — PR #154 harmonized the dashboard-widget and Access-tab user
  presentation and fixed a `get_avatar()` `force`→`force_display` no-op. It added an
  *internal* `User_Identity` helper and changed admin markup/CSS only — no public
  hook, filter, function, or documented contract. Admin-UI change + bug fix →
  **patch**, even though it is user-visible. (Visible ≠ minor; the API contract, not
  the pixels, decides.)
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
  `CLAUDE.md` version-sync checklist); the `verify:metrics`/CI gates fail on drift.
- The public "Try latest release" Playground badge loads `blueprint.json` from
  `main`, so its tag-ZIP target is bumped **after** the tag is cut, never before
  (a pre-tag bump would make the public demo fetch a missing ZIP).

## Pre-1.0

Not applicable — WP Sudo is past 1.0, so the rules above apply in full. (For any
future 0.x companion repo, note that under semver 0.x a MINOR may carry breaking
changes; such a repo needs its own stated stance.)
