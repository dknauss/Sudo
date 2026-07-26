# Design brief — #288: in-editor padlock indicator (pre-implementation review)

## Problem / failure mode

`admin/js/wp-sudo-session-indicator.js` registers a `PluginSidebar` whose pinned
header button uses a static `shield` dashicon and communicates sudo state **only**
through its accessible name (`title`: "Sudo · active" / "Sudo · inactive"). A sighted
user editing full-screen — where the admin-bar countdown never renders — sees an
identical glyph whether sudo is active, about to expire, or inactive. This is not a
security hole; it is a state-legibility failure that makes the plugin's most
security-relevant transient state invisible on the one surface that hides the
admin bar. Secondary problem: the shield is iconography used nowhere else in the
plugin, so the editor and admin-bar surfaces do not read as one system.

## Proposed approach

Swap `icon: 'shield'` → `icon: 'unlock'` (the admin bar's `dashicons-unlock`), and
colour the **pinned header button** with the admin bar's exact tokens: active
`#2e7d32` (hover `#1b5e20`), expiring `#c62828` (hover `#b71c1c`), white glyph;
inactive/expired reverts to the stock Gutenberg button (no chip).

**Verified against the live WP 7.0 editor DOM** (not assumed): the pinned button is

```html
<button aria-controls="wp-sudo-session-indicator:wp-sudo-session-indicator"
        class="components-button is-compact has-icon" aria-label="Sudo · active">
  <span class="dashicon dashicons dashicons-shield"></span></button>
```

so `aria-controls` is a stable, locale-independent, plugin-scoped selector — the
issue's "fall back to matching the `aria-label`" contingency is unnecessary.

State is carried by a **class on `document.body`** (`wp-sudo-editor-session-active` /
`wp-sudo-editor-session-expiring`), toggled from a `useEffect` keyed on a derived
three-value state string, so the effect fires **only on transitions**, never per
second. CSS in a new `admin/css/wp-sudo-editor-indicator.css` (enqueued alongside
the indicator script in `Plugin::enqueue_editor_reauth()`) selects
`body.<state> .interface-pinned-items button[aria-controls="…"]`.

Body class rather than a class on the button itself: Gutenberg re-renders that
button with a fresh `className` prop whenever `is-pressed` flips (opening/closing
the panel), which would wipe an externally-added class. Body class rather than
`:has()`: `:has()` post-dates the plugin's WP 6.4 floor in Firefox (121, Dec 2023).

Files changed: `admin/js/wp-sudo-session-indicator.js`, new
`admin/css/wp-sudo-editor-indicator.css`, `includes/class-plugin.php`
(one `wp_enqueue_style`), `tests/e2e/specs/editor-session-indicator.spec.ts`,
`tests/e2e/specs/visual/regression-baselines.spec.ts` (+2 snapshots — three as shipped,
see the amendment below).

## What this explicitly must NOT do

- **Must not** add a per-second live region, per-second `aria-label` churn, or the
  admin bar's 60/30/10 s SR milestones. The grant snackbar stays the single spoken
  announcement; the accessible-name flip stays the only AT state signal.
- **Must not** mint, extend, refresh, or read a session. The module stays purely
  informational, driven by the two existing feeds (page-load localize + the
  `wp-sudo-session-granted` CustomEvent). No new endpoint, no polling.
- **Must not** change behaviour below WP 6.6, where `wp.editor.PluginSidebar` is
  absent and the module early-returns to the Part A snackbar. The CSS is inert
  there (the selector matches nothing); the enqueue is unconditional.
- **Must not** leak the body class outside the editor or past unmount. (As built, the
  toggle is armed on any screen firing `enqueue_block_editor_assets` — including the
  widgets editor, where `wp.editor.PluginSidebar` is present because `wp-editor` is a
  declared dependency of this script, so the 6.6 detect passes. Inert there: no button
  with the module's `aria-controls` renders outside the post/site editor.)

## Amendment (post-merge follow-up): state moves to the glyph, colour becomes the exception

Raised by the maintainer after #288 merged, and correct: other icons in the block
editor header do not change background colour. Verified against Gutenberg trunk
(fetched 2026-07-26) rather than assumed:

- **Semantic background colour has no core precedent here.** The only background
  change core applies to a pinned-item button is `.is-pressed` →
  `background: $components-color-foreground`, a neutral near-black
  (`packages/components/src/button/style.scss` L342-348). Colour in that header belongs
  to the two calls to action — the Inserter toggle and the Publish CTA, both on the WP
  admin blue — and not to the pinned sidebar controls this feature lives among. (An
  earlier draft said Publish was the only coloured element in the header; the Inserter
  is visibly the same blue in `.wordpress-org/screenshot-12.png`. Corrected after
  review.)
- **A state-driven glyph does have precedent, on this exact control.** Core swaps this
  button's icon conditionally itself — `icon={ showIconLabels ? check : icon }`
  (`packages/interface/src/components/complementary-area/index.js` L280, the
  `ComplementaryAreaToggle` rendered inside `<PinnedItems>`) — and that component takes
  a state-selected icon by design: `icon={ selectedIcon && isSelected ? selectedIcon :
  icon }` (`complementary-area-toggle/index.js` L58).
  - Correction to an earlier draft of this brief: the `isPinned ? starFilled :
    starEmpty` swap at L326 is **not** this control. It is the pin/unpin star
    (`className="interface-complementary-area__pin-unpin-item"`, labels "Pin to
    toolbar"/"Unpin from toolbar") rendered in the complementary-area **panel header**,
    a different node in a different container. L293 is the Options-menu item, not the
    toggle. The toggle gets its icon at L280.
  - Cost of core owning that icon slot: with the **Show button text labels** preference
    on, core discards our icon for its own `check` in every state, renders no visible
    text, and suppresses the tooltip (L281). Verified in a live WP 7.0 editor. That
    cohort would have neither shape nor hover to read state from, so active and inactive
    would collapse to one appearance — the bug this feature exists to fix.
  - **Resolved by a scoped fallback, not accepted as a gap.** In that mode — and only
    there — the green active chip returns, because colour is the only channel core
    leaves. Two orthogonal body classes (`wp-sudo-editor-icon-labels`, set from a
    `core/preferences` subscription, and `wp-sudo-editor-session-active`) compose in
    CSS, so the default view keeps no semantic colour but the urgent red. INDICATOR-09
    pins both directions: the chip appears under the preference, and does not appear
    without it.

### Verification sources for this amendment

All fetched 2026-07-26 and read in context, not grepped for a line number:

- `button/style.scss` L342-348 — `.is-pressed` → `background: $components-color-foreground`
  <https://raw.githubusercontent.com/WordPress/gutenberg/trunk/packages/components/src/button/style.scss>
- `complementary-area/index.js` L280-281 — `icon={ showIconLabels ? check : icon }`
  and `showTooltip={ ! showIconLabels }`; L293 is the Options-menu item; L326 is the
  pin/unpin star in the panel header
  <https://raw.githubusercontent.com/WordPress/gutenberg/trunk/packages/interface/src/components/complementary-area/index.js>
- `complementary-area-toggle/index.js` L58 — `icon={ selectedIcon && isSelected ?
  selectedIcon : icon }`
  <https://raw.githubusercontent.com/WordPress/gutenberg/trunk/packages/interface/src/components/complementary-area-toggle/index.js>
- `@wordpress/data` `registry.ts` L62-86 — `subscribe( listener, storeNameOrDescriptor )`
  <https://raw.githubusercontent.com/WordPress/gutenberg/trunk/packages/data/src/registry.ts>

So the shipped design had it backwards: it invented a convention for the states that
did not need one, and the *glyph* swap — the part that was already idiomatic — was
used only for the edge state. Amended vocabulary:

| State | #288 as shipped | Amended | Under `showIconLabels` |
|---|---|---|---|
| No session | `unlock`, no chip | **`lock`**, no chip | core `check`, no chip |
| Active | `unlock` + green chip | `unlock`, **no chip** | core `check` + **green chip** |
| Final 60 s | `warning` + red chip | `warning` + red chip (unchanged) | core `check` + red chip |

The last column is core's doing, not a second design: it owns the icon slot in that
mode, so the chip is the only channel left there.

Two independent gains. The at-rest state no longer shows an *unlocked* padlock while
sudo is inactive — which asserted the opposite of the truth for the state the header
displays most of the time, and which this brief's original scope decision (§Q2, "keep
`unlock` for inactive") got wrong. And with two of the three states carrying no colour,
WCAG 1.4.1 is satisfied by construction rather than by supplement: shape is the primary
channel and the red chip only reinforces it.

Cost, stated plainly: a 20 px glyph change is less noticeable peripherally than a
green→red chip, so this trades some at-a-glance salience — the original point of
#288 — for fitting the surface. The maintainer made that call knowing the trade.

`wp-sudo-editor-session-active` survives, but for one job only: composed with
`wp-sudo-editor-icon-labels` it paints the active chip in the mode where core has taken
the glyph (above). It no longer paints anything on its own, and it now means exactly
'active' rather than "not inactive", so the two state classes are mutually exclusive and
the stylesheet needs no source-order tiebreak between them.

## Resolutions (design review for the ORIGINAL #288 design — HISTORICAL)

> **Superseded by the amendment above.** This section records what was settled before
> #288 shipped and is kept for the reasoning, not as a description of the code. Three
> of its statements are no longer true of `main`:
>
> - Active and inactive no longer share `dashicons-unlock`; inactive is `dashicons-lock`
>   and the chip-vs-no-chip distinction it relies on is gone (§Q2 below, and the
>   original §Q2 scope decision to "keep `unlock` for inactive", which the amendment
>   reverses).
> - "Active green / expiring red" is now "expiring red always, active green only under
>   core's icon-labels preference".
> - The `+2 snapshots` scope is now three baselines, one per state (VISN-05/06/07).
>
> Read the amendment for the shipped behaviour; read this for why each question was
> answered the way it was at the time.

All five questions below were put to a design reviewer before implementation. The
settled answers, and the two places the implementation departs from this brief:

1. **Third accessible name — YES.** `Sudo · expiring` was added. It is one extra
   transition per session, not per-second, and an accessible-name change on an
   unfocused button is not announced at all, so it costs no interruption. It also
   does not duplicate the admin bar's 60/30/10 s SR milestones on this surface:
   in full-screen the admin bar is hidden, and live-region mutations inside a
   hidden subtree are not announced. Note `title` is also the open panel's heading
   and the Options-menu entry, so all three move together.
2. **Colour-only encoding — NOT discharged by the name; the glyph now differs.**
   1.4.1 is about *visual* means, and an accessible name is programmatic. `#2e7d32`
   and `#c62828` are **1.09:1 against each other**, i.e. the same swatch under a
   red-green deficiency, in greyscale, and in forced-colors mode. Active/inactive
   are already distinguished without colour (chip vs no chip, ~5:1), so only the
   active↔expiring pair needed fixing: expiring renders `dashicons-warning`, active
   and inactive keep `dashicons-unlock`. The admin bar needs no equivalent because
   it carries visible `Sudo: M:SS` text.
3. **Contrast — passes, no new argument needed.** Against white: `#2e7d32` 5.13:1,
   `#1b5e20` 7.87:1, `#c62828` 5.62:1, `#b71c1c` 6.57:1. The governing criterion
   for a meaningful icon is 1.4.11 (3:1); all four also clear 1.4.3 (4.5:1). Core
   draws the focus ring outside the button, so the chip never sits under it — which
   is why this CSS must not set `outline`, `box-shadow`, or `overflow`.
4. **`!important` — not used.** The selectors are (0,4,2); the core rules competing
   for this button's background top out at (0,3,0), and the sheet loads after
   `wp-components`. Verified in the live cascade. **Departure from this brief:**
   `:not(.is-pressed)` was added to every rule — the selectors otherwise out-specify
   Gutenberg's pressed styling and would destroy the panel-open affordance.
5. **Compact `M:SS` — omitted, as proposed.** On the pinned toggle the only place
   text can go is `title`, which *is* the accessible name, so a ticking `M:SS` would
   literally be the per-second name churn the "must not" list forbids first.

**Second departure — no `transition`.** This brief and the admin bar both cross-fade
`background 0.3s`. Porting it proved actively harmful: the green→red transition was
observed parked at `currentTime` 0 while the page was not producing frames, leaving
the chip showing the STALE colour until an unrelated style recalc unstuck it. An
expiry cue that can lie is worse than no animation, and this is a discrete security
state, not a decorative one. With no motion there is also nothing for a
`prefers-reduced-motion` block to reduce, so that block was dropped too.

**Third departure — the body-class toggle is module-level, not a `useEffect`.**
`IndicatorPanel` re-renders every second, and the site editor is an SPA where
`document.body` outlives route changes, so a component-scoped effect has an unmount
path that must be exactly right or a stale chip survives. A module-level subscriber
with a `lastState` guard has no unmount to get wrong, is armed only after the WP 6.6+
feature detect, and shares one `sessionState()` helper with the title derivation so
the class and the accessible name cannot drift.

## Open questions for the reviewer

1. **Accessible-name parity for the expiring state.** The red final-minute cue is
   currently sighted-only. Adding a third name state ("Sudo · expiring") would be
   one more transition — not per-second — and would give AT users parity. The
   issue text says only "keep the accessible-name flip" (two states). Is the
   third state an a11y improvement or an unrequested contract change?
2. **Colour-only state encoding.** Green vs. red on an icon-only button is a
   colour-only distinction (WCAG 1.4.1 concern) for the *sighted* colour-blind
   user; the accessible name carries active/inactive but not expiring. Does the
   two-state name flip discharge this, or does the glyph itself need to differ?
3. **Contrast.** `#2e7d32` and `#c62828` behind a white dashicon are inherited
   verbatim from the admin bar; worth confirming they are not being newly applied
   at a size/weight where the existing contrast argument stops holding.
4. **`!important`.** The admin-bar CSS uses `!important` throughout to beat core.
   Preference: land without it and add only if the live cascade demands it — is
   that the right call given Gutenberg's `.components-button` specificity?
5. **Scope.** The issue lists an optional compact `M:SS` on the header control.
   Proposal: **omit it** — it would reintroduce per-second DOM churn in the header
   and make the visual snapshot width-unstable. Correct call?
