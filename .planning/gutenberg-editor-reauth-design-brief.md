# Design Phase Scaffold — Block-Editor (Gutenberg) Reauthentication UX

**Status:** Design-phase scaffold. No production code proposed here. This document
is the input to the mandatory Pre-Implementation Design Review (per `CLAUDE.md`)
that must run before any TDD on this feature.

**Verification stamp:** Surface inventory and security-boundary claims below were
re-grounded against the live codebase on **2026-07-05** (current `main`, plugin
`4.2.2`). Line references are to `includes/class-gate.php` and
`includes/class-action-registry.php` as of that date. This supersedes the
`2026-06-25` route inventory buried in
[`post-4.1.0-dev-scopes.md`](post-4.1.0-dev-scopes.md) §"Scope 3" — that analysis
is still correct; this consolidates it into a review-ready artifact and re-verifies
it against current code.

**Prior art already shipped (v4.2.0):** the server-side `challenge_url` field on the
cookie-auth REST `sudo_required` error (`Phase 1` of the original plan). See
[`class-gate.php:2628`](../includes/class-gate.php). Everything below is the *UX layer*
that consumes it — not yet started.

---

## Part 1 — Surface Inventory (the "which editor flows trip the gate" question)

### Method

The block/site editor does not call literal REST route strings; it writes through
`@wordpress/core-data` entities (`saveEntityRecord` / `deleteEntityRecord`), whose
`baseURL`s resolve to `/wp/v2/*` routes. So the authoritative question is: **which
of WP Sudo's gated REST rules sit on a route the editor's `apiFetch` can reach?**

The gated REST surface is not a matter of opinion — it is exactly the set of rules
in the Action Registry with a non-null `rest` block. Re-derive it:

```bash
# Every gated REST route + methods, with its rule id
grep -n "'id'\s*=>\|'route'\s*=>\|'methods'\s*=>" includes/class-action-registry.php \
  | grep -B1 -A1 "'route'"
```

### Gated REST surface (authoritative, from current registry)

| Rule id | REST route (regex) | Methods | Editor-reachable? | Realistic in-editor trigger |
|---|---|---|---|---|
| `plugin.activate` | `/wp/v2/plugins/<slug>` | PUT, PATCH | **Yes** | **Block Directory:** activate a block plugin from the inserter |
| `plugin.deactivate` | `/wp/v2/plugins/<slug>` | PUT, PATCH | Yes (theoretical) | No first-class editor UI; reachable via apiFetch |
| `plugin.delete` | `/wp/v2/plugins/<slug>` | DELETE | Yes (theoretical) | No first-class editor UI |
| `plugin.install` | `/wp/v2/plugins` | POST | **Yes** | **Block Directory:** install a block plugin from the inserter |
| `user.delete` | `/wp/v2/users/<id>` | DELETE | No (theoretical) | Editor has no user-management UI |
| `user.promote` | `/wp/v2/users/<id>` | PUT, PATCH | No (theoretical) | — |
| `user.change_password` | `/wp/v2/users/<id\|me>` | PUT, PATCH | No (theoretical) | — |
| `user.create` | `/wp/v2/users` | POST | No (theoretical) | — |
| `auth.app_password` | `/wp/v2/users/<id\|me>/application-passwords` | POST | No | Profile screen, not editor |
| `options.critical` | `/wp/v2/settings` | PUT, PATCH, POST | Low | Critical opts¹ not written from the editor canvas |
| `connectors.update_credentials` | `/wp/v2/settings` | PUT, PATCH, POST | No | Connector API keys not editor-written |

¹ Critical options = `siteurl`, `home`, `admin_email`, `new_admin_email`,
`default_role`, `users_can_register` (filterable via `wp_sudo_critical_options`).
Re-derive: `sed -n '/function critical_option_names/,/^\t\t}/p' includes/class-action-registry.php`.

**Rules with NO REST surface** (gated only on the classic admin/AJAX surface, so
*never* reachable from the editor's REST client): `plugin.upload`, `plugin.update`,
all `theme.*`, `editor.plugin`, `editor.theme`, `core.update`, `tools.export`, all
`network.*`, `options.wp_sudo`, `options.wp_sudo_access`, `user.promote_profile`.
(These have `'rest' => null` in the registry.)

### Deliberately NOT gated (content/design — gating them would be a scope violation)

The editor writes these entities constantly; gating them would break the documented
"don't gate content saves" principle and is explicitly **out of scope**:
`/wp/v2/posts`, `/pages`, `/templates`, `/template-parts`, `/global-styles`,
`/navigation`, `/menus`, `/menu-items`, `/widgets`, `/sidebars`, `/blocks` (reusable),
`/font-families`, `/font-faces`, `/media`, `/comments`.

### Conclusion — the surface is thin, and that is the point

**The only realistically editor-reachable gated flow is Block Directory plugin
install/activate (`/wp/v2/plugins`).** Everything else on the gated REST surface
either has no editor UI (users, app passwords) or is content/design that is
deliberately ungated. This **confirms** the earlier "likely thin" intuition and
**refutes** any framing that this feature needs new gating rules for
templates/global-styles/blocks/menus. **Phase 2 requires zero new gating rules** —
it is a pure UX layer over the one-to-few already-gated routes the editor can hit.

Framing consequence for the reviewer: this is a **UX-polish feature, not a
security-coverage feature.** Its value is "don't destroy unsaved editor state and
don't dead-end on a 403," not "close a gap." That should calibrate how much
machinery it earns.

---

## Part 2 — Load-bearing security boundary (re-verified in current code)

**Client re-dispatch ≠ `Request_Stash` replay.** The editor flow is safe *only*
because the REST path is a **soft block that creates no server stash**. The client
re-fires its own in-memory request after the session is granted. Editor flows must
**never** route through `Request_Stash` (which exists to strip secrets and enforce
one-time, ownership-bound replay of *admin form* posts).

**`challenge_url` is emitted on the cookie-auth branch only.** Verified in
[`class-gate.php:intercept_rest()`](../includes/class-gate.php) (as of 2026-07-05):

- App-password / bearer requests → `sudo_disabled` or `sudo_blocked`, **no
  `challenge_url`** (lines ~1847–1884). Emitting a challenge URL to a headless
  client would be information disclosure and is correctly avoided.
- Cookie-auth browser requests → `block_rest()`, which is the *only* caller that
  attaches `challenge_url` (line ~2628).

Any implementation must preserve this split. A regression test must assert the
headless branches stay `challenge_url`-free.

---

## Part 3 — Design Brief (Pre-Implementation Design Review format)

*This is the brief to hand to the design reviewer agent. Describe factually; do not
instruct the reviewer to approve.*

**What problem is being solved / what failure mode.** A gated action triggered from
the block or site editor (in practice: installing/activating a block plugin from the
inserter) returns a `sudo_required` 403 that the editor SPA surfaces as an opaque
failure, and there is no in-editor path to reauthenticate. A full-page redirect to
the challenge page would destroy unsaved editor state. This is a UX dead-end, not a
security gap — the gate already fires correctly.

**Proposed approach / files.** A **build-free, vanilla-JS** client layer (same
pattern as the existing hand-written `admin/js/*.js` files, declaring script deps on
`wp-api-fetch`, `wp-data`, `wp-notices`):
1. An `apiFetch` middleware (`wp.apiFetch.use(...)`) that catches the `sudo_required`
   error, reads its `challenge_url`, and instead of failing shows an in-editor
   **snackbar** (`wp.data.dispatch('core/notices').createNotice(...)`) with a
   "Reauthenticate" action.
2. Snackbar action opens the challenge (MVP: link to the full-page challenge in a new
   context / same-document AJAX against existing challenge handlers — **not** an
   iframe, which breaks SameSite/partitioned sudo-cookie readback).
3. On successful session grant, the client **re-dispatches its own original request**
   (re-minting the REST nonce, which apiFetch's nonce middleware already does).
   No new PHP gating rules. Possible small refactor to extract challenge rendering
   from `class-challenge.php` for reuse without regressing the classic flow.

**What it explicitly blocks / must NOT block.** It changes **no** gating decision:
the same actions gate, the same actions pass. It must NOT gate any content/design
save; must NOT route REST through `Request_Stash`; must NOT emit `challenge_url` to
headless clients; must NOT depend on `is_network_admin()` context under REST (build
the URL from a localized base or the server-emitted `challenge_url`); must NOT
introduce an `@wordpress/scripts` build step for the MVP.

---

## Part 4 — Phased plan & effort

| Phase | Scope | Build step? | Status |
|---|---|---|---|
| 1 | Server `challenge_url` on cookie-auth REST error + tests | No | ✅ **Shipped v4.2.0** |
| 2 | `apiFetch` middleware + snackbar (MVP) + re-dispatch; Playwright E2E | **No (build-free)** | Not started |
| 3 | Snackbar → modal challenge (try `wp.components.Modal` via `createElement`) | Reconsider build only if unmaintainable | Not started |
| 4 | 2FA-in-editor (script/enqueue-context problem) | — | **Deferred** — snackbar-link-to-full-page is the only safe 2FA MVP |
| 5 | Broaden E2E matrix once challenge transport is no longer page-based | — | Not started |

**Build-step decision (settled):** declined for the MVP. The plugin ships zero
production npm deps and no build step today; the snackbar/middleware reach core
globals via a hand-written file, the same pattern as existing `admin/js/`. Adding
`@wordpress/scripts` would introduce an npm prod-dep tree, SBOM/`npm audit` surface,
a `build/` artifact, and version-pinning maintenance — cost a snackbar does not earn.

---

## Part 5 — Open questions for the design reviewer

1. **Challenge transport for the snackbar action.** Same-document AJAX against the
   existing challenge handlers vs. link-to-full-page. AJAX keeps editor state but
   needs the challenge password/2FA form reachable outside the full-page context;
   link-to-full-page is safe but loses state. For 2FA sites, link-to-full-page is
   forced (Phase 4 deferral). Is a mixed strategy (AJAX for password-only, link for
   2FA) acceptable, or does that split add too much surface?
2. **`class-challenge.php` extraction.** How much can be reused without regressing
   the classic stash/replay flow and its tests? Is a shared verifier extraction worth
   it for a one-to-few-route UX layer, or is a thin dedicated AJAX endpoint simpler?
3. **Scope discipline.** Given the surface is essentially one flow (Block Directory
   plugin install/activate), is the snackbar+re-dispatch machinery proportionate, or
   should the MVP be even smaller (e.g. improve the existing error message with an
   actionable link and stop there)?
4. **Gray-area opt-in.** `global-styles` and `font-families/faces` sit just outside
   the model. If ever gated, do it as a **default-OFF, filter-gated opt-in** (mirror
   the 4.1.0 escalation guard: `wp_sudo_guard_escalation`), **not** a Settings
   checkbox. Confirm this stays out of the Phase 2 scope entirely.
5. **Test strategy.** Phase 2 is the natural first Playwright E2E of a
   challenge-transport flow. Which specs: block-plugin install/activate happy path,
   re-dispatch after grant, headless-branch-stays-`challenge_url`-free regression,
   grace-window no-re-challenge.

---

## Part 6 — Re-derivation commands (anti-confabulation)

```bash
# Gated REST surface (rule id + route + methods)
grep -n "'id'\s*=>\|'route'\s*=>\|'methods'\s*=>" includes/class-action-registry.php | grep -B1 -A1 "'route'"

# Confirm challenge_url is emitted only by block_rest() (cookie-auth branch)
grep -n "challenge_url\|block_rest\|sudo_disabled\|sudo_blocked" includes/class-gate.php

# Critical option names
sed -n '/function critical_option_names/,/^\t\t}/p' includes/class-action-registry.php

# Confirm no build step / zero prod npm deps
test -f package.json && jq '.dependencies // {}' package.json   # expect {} or dev-only
```
