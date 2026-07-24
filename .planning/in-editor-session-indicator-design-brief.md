# Design brief: in-editor sudo session indicator (#182)

**Status:** Pre-implementation design note (per CLAUDE.md → "Pre-Implementation Design
Review"). **Revised 2026-07-17 after design review** — see "Reviewer objections
incorporated" at the end. Ready for TDD.
**Source todo:** `.planning/todos/pending/2026-07-15-in-editor-sudo-session-indicator.md`
**Date:** 2026-07-17

---

## Problem & failure mode being addressed

After an in-editor reauth (Milestone A modal), the block editor gives **no visible
signal that a sudo session is active or how long it has left**. The live countdown
lives in `Admin_Bar` (`admin_bar_menu`, pri 100), but the full-screen editor hides the
admin bar, so it never renders while editing (DOM-probe-confirmed: no
`#wp-admin-bar-wp-sudo` in the editor). The user cannot predict whether the next gated
action will reauth or pass silently, and a successful grant has no positive persistent
confirmation. This is an **informational affordance only** — not a security control.

## Chosen approach (feature-detected persistent indicator + announce-once snackbar)

The persistent surface and the announce-once signal are **two complementary parts**, not
alternatives:

**Part A — announce-once snackbar (baseline; works on the full 6.4+ floor).**
On a successful in-editor grant, dispatch **one** `core/notices` snackbar
("Reauthenticated — sudo active for N minutes"), reusing the existing `surface()` /
`core/notices` path already in `admin/js/wp-sudo-editor-reauth.js`. This is robust in
both the post editor and the site editor at 6.4+ with **zero editor-slot dependency**,
and it is the single a11y announcement (see a11y). It is the guaranteed-visible signal
at the grant moment on every supported version.

**Part B — persistent header indicator, feature-detected on the unified editor API.**
Register a `PluginSidebar` via `@wordpress/plugins` `registerPlugin`, which auto-creates
a **pinned button in the editor header**; the button communicates active/inactive state
statically ("Sudo active"), and its panel body shows the live countdown text.

- **Version reality (verified):** the *unified* `wp.editor.PluginSidebar` that renders
  in **both** the post editor and the site editor landed in **WordPress 6.6**
  ([Make Core, 2024-06-18](https://make.wordpress.org/core/2024/06/18/editor-unified-extensibility-apis-in-6-6/)).
  Before 6.6, `PluginSidebar` came only from `wp.editPost` (**post editor only**); there
  is no single registration that renders in both editors at the 6.4–6.5 floor.
- **Therefore: feature-detect `wp.editor?.PluginSidebar` and register Part B only when
  it exists (effectively 6.6+).** On 6.4–6.5 the persistent indicator is simply absent
  and Part A (snackbar) carries the feature. This respects the plugin's 6.4 floor
  without version-conditional slot gymnastics or a post-editor-only half-feature, and it
  is honest about where the persistent surface is actually robust. (The declared floor
  is 6.4; "Tested up to 7.0". The persistent indicator gracefully degrades below 6.6.)

Rejected: **full sidebar with an "end session now" control** — the revoke control is
state-changing scope creep for v1; deferred. **A `PinnedItems`/`@wordpress/interface`
static badge** was considered instead of `PluginSidebar`, but it carries the *same*
pre/post-6.6 unification problem and is a less standard extension surface than
`registerPlugin`, so it wins nothing.

## Data source — reuse existing accessor, NO new endpoint

Resolves the todo's load-bearing question ("existing localized value vs. new
`sudo/session-status` read route"): **reuse `Sudo_Session::time_remaining( $user_id )`**
— the exact accessor `Admin_Bar` already localizes as `{ remaining }`. Two feeds, both
already-existing surfaces, no new REST route and no polling:

1. **Page load (active-at-load case):** extend the existing `enqueue_editor_reauth()`
   localize object (`wpSudoEditorReauth`, `class-plugin.php:344`) with a `remaining`
   seconds value. **Gate it on `Sudo_Session::is_active( $user_id )`** — `time_remaining()`
   (`class-sudo-session.php:390`) reads raw `_wp_sudo_expires` meta and does **not**
   verify the browser token, so a session bound to a *different* browser would otherwise
   report `remaining > 0` here. Localize `is_active() ? time_remaining() : 0`. (The
   enqueue deliberately runs even when a session is active — C2 docblock,
   `class-plugin.php:306` — so the active value is available at load.)
2. **After an in-editor grant (grant-during-session case):** add
   `'remaining' => Sudo_Session::time_remaining( $user_id )` to the grant-success AJAX
   responses the modal receives. The modal treats **any** `{ code: 'authenticated' }` as
   success (`wp-sudo-editor-reauth.js:463`), and that response is emitted in **three**
   places — all three must carry `remaining`:
   - `handle_ajax_auth()` session-only path — `class-challenge.php:664`
   - `handle_ajax_2fa()` session-only path — `class-challenge.php:873`
   - `complete_active_session_request()` — `class-challenge.php:909` (reached via the
     `is_active()` early-returns at `class-challenge.php:642` / `732` when the session
     became active between modal-open and submit, e.g. granted in another tab).
   **Do NOT** add `remaining` to `build_replay_response_data()`
   (`class-challenge.php:1004`) — the editor modal never sends a `stash_key`, so it never
   receives a replay response; that path feeds only the challenge page. Adding the field
   there is dead weight on a security-sensitive response shape.
   Client-side, `postPassword` / `postTwoFactor` currently narrow the response body to
   `{ ok, code, message }` (`wp-sudo-editor-reauth.js:212`, `373`); **widen them to carry
   `remaining`** before the indicator can consume feed #2. The `2fa_pending` response's
   existing `expires_at` (`class-challenge.php:672`) is the 2FA *challenge window*, not
   the session — do not conflate.

**Client ticks down from the single seeded `remaining` value** with a local
`setInterval` on the number only (arithmetic, no network) — satisfies "never poll on an
interval." The interval updates displayed text; it never calls the server, never re-grants.

Script deps: add `wp-plugins` and `wp-editor` to the `wp-sudo-editor-reauth` handle's
`deps` array (`class-plugin.php:339`) for Part B (both loaded only where the feature
registers; `wp-editor` presence is itself the ~6.6 signal).

## Behavior at expiry and in the 120 s grace window

- While `remaining > 0`: panel shows "Sudo active — M:SS remaining", ticking down; the
  pinned button shows the static active state.
- When the local tick reaches 0: switch to the inactive/"expired" state ("No active sudo
  session"). Do **not** surface a separate "grace" affordance — `time_remaining()`
  already returns 0 the moment `now > expires` (grace is entirely past expiry), so grace
  reads as inactive for free, matching the todo's "avoid implying a session is active
  when only the grace window remains."
- **Documented UX asymmetry (intentional, not a bug):** during the 120 s grace window
  the indicator reads "inactive" while the Gate still lets in-flight gated actions pass
  via `is_within_grace()` (`class-sudo-session.php:252`). This is the conservative
  direction the todo asked for; record it so it is not later filed as a defect.
- The client never distinguishes grace from fully-expired; the server stays authoritative
  and the next gated action re-challenges as normal.

## Single-site + multisite, post editor + site editor coverage

- `enqueue_editor_reauth()` is hooked on `enqueue_block_editor_assets`, which fires for
  **both** the post editor and the site editor. Feed #1 (localized `remaining`) is
  therefore available in both. Part B's persistent surface renders in both **only at
  6.6+** (see approach); on 6.4–6.5 Part A (snackbar) covers both editors.
- **Multisite:** the session's expiry lives in `_wp_sudo_expires`, which is
  **network-global user meta** (`update_user_meta`), not per-blog — correcting the prior
  draft's "per-user-per-site" framing. The indicator only *reads* `time_remaining()` for
  the current user and makes **no network call**, so there is nothing multisite-specific
  to route (unlike Milestone A's grant POST, already routed to the current site's
  `admin-ajax.php`). One nuance to confirm during E2E: on **subdomain** multisite the
  token cookie is domain-scoped, so `is_active()`'s `verify_token()` can read false on a
  sibling subsite even though the expiry meta is shared → feed #1 correctly seeds 0
  there, staying consistent with the Gate (which would re-challenge). Confirm behavior on
  subdomain vs subdirectory multisite and in a network-admin editor context.

## Accessibility (announce-once, then static)

- The **grant snackbar (Part A)** is the single announcement — one `core/notices`
  snackbar, dispatched once per grant.
- The ticking countdown in the Part B panel is **static readable text updated in place**,
  NOT inside an `aria-live` `polite`/`assertive` region — a per-second live region would
  be a screen-reader nuisance. The pinned button exposes active vs. inactive via its
  accessible name; it does not announce every tick and carries no live number.
- **Optional (decide in TDD):** also fire a one-shot snackbar on **page load when
  `is_active()`**, so a user who returns to an already-active editor session — or who has
  unpinned the Part B button — still gets one positive confirmation. Without it, the
  active-at-load + unpinned-button case shows nothing until the next grant. Lean toward
  including it (cheap, closes the only "silent" path), but keep it a single non-live
  snackbar to preserve announce-once.

## Non-goals / hard boundaries (unchanged from todo)

- **Informational only.** Reads session state; **never mints, extends, or refreshes** a
  session. No "end session" control in v1.
- **No new gated-action bypass** and no new grant/refresh path.
- **No timer-driven network requests** — the tick is local arithmetic off one seeded value.

## Test plan

- **Unit (JS):** the tick/format logic — seconds → "M:SS", clamp at 0, active→inactive
  transition at 0.
- **Unit (PHP):** the `is_active()`-gating of the localized `remaining` — active session
  → seconds; different-browser meta present but token absent → 0. Plus: all three
  `authenticated` emitters return `remaining`; `build_replay_response_data()` does not.
- **E2E** (`editor-reauth.spec.ts` sibling/extension): grant → indicator active +
  countdown → (short-duration) expiry → indicator clears → **re-grant re-seeds** from the
  new response's `remaining`. Single-site; multisite via the existing `WP_MULTISITE=1`
  sweep (subdomain nuance noted above). Gate Part-B assertions on `wp.editor` presence so
  the suite is meaningful on the 6.6+ test target.

## Reviewer objections incorporated (2026-07-17)

1. **[Contract/version — highest risk] "PluginSidebar in both editors at 6.4+" was
   unverified and wrong.** Verified against Make Core: unified `wp.editor.PluginSidebar`
   is **6.6**. Approach changed to feature-detect `wp.editor?.PluginSidebar` (Part B,
   6.6+) with the snackbar (Part A) as the 6.4+ baseline.
2. **[Scope] Sidebar-as-specified was over the todo's "lightest useful slice."**
   Rebalanced: snackbar is the baseline feature; the persistent panel is an additive,
   feature-detected enhancement rather than the load-bearing surface.
3. **[Caller coverage] Three `authenticated` emitters, not two** — added
   `complete_active_session_request()` (`class-challenge.php:909`).
4. **[Contract] Scope the `remaining` field to `authenticated` responses only** — not the
   replay/`build_replay_response_data` path (no consumer).
5. **[Caller coverage] Client plumbing** — widen `postPassword`/`postTwoFactor` body
   parsing to carry `remaining`; add `wp-plugins`/`wp-editor` deps.
6. **[Execution context] Active-at-load + unpinned button = silent** — addressed via the
   optional page-load snackbar (a11y section).
7. **[Execution context] Grace-window UX asymmetry** — documented as intentional.
8. **[Multisite] "per-user-per-site" was inaccurate** — corrected to network-global user
   meta; subdomain cookie/token nuance noted for E2E.

### Verified-accurate claims (reviewer confirmed against source)

`time_remaining()` does not verify the token (so the `is_active()` gate is necessary);
`time_remaining()` returns 0 during grace; the enqueue runs even when a session is
active; `2fa_pending`'s `expires_at` is the 2FA window not the session; an additive
`remaining` field is safe for the challenge-page JS and the modal (both read only named
fields).
