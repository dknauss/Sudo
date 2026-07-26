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
`tests/e2e/specs/visual/regression-baselines.spec.ts` (+2 snapshots).

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
- **Must not** leak the body class outside the editor or past unmount.

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
