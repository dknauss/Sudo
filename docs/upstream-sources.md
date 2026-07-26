# Upstream source registry

Every claim this repo makes about **third-party code** lives here, once. Code comments,
changelog entries, specs, and design docs carry a one-line summary and point at an ID in
this table — they do not restate the citation. This is the same arrangement
`docs/current-metrics.md` uses for counts, and for the same reason: a fact duplicated
across seven files is a fact that will be wrong in six of them within a month.

`bin/verify-sources.sh` checks every row against the live upstream file. It fails when a
snippet has **disappeared** (the claim is now false) and warns when a snippet has merely
**moved** (the line number drifted; upstream trunk moves constantly and that alone is not
a lie).

## How to add a row

1. Read the file. Not `grep` — read enough of it to name the **enclosing symbol**, the
   component or function the line sits inside. If you cannot name it, you have not
   verified anything: `starFilled` appearing at some line proves a token's address, not
   that it belongs to the control you are describing. That exact mistake was made in
   #317's own first commit (`503e565`): `starFilled` was cited at a real line that
   belongs to a different control. It spanned five files and was corrected on-branch 24
   minutes later in `724ebcb`, before merge — caught by re-reading the file in context,
   which is the habit this column exists to force.
2. Add the row: a stable ID, the raw URL, the line, an exact snippet, the enclosing
   symbol, and the claim it supports.
3. Run `bin/verify-sources.sh`.
4. In prose, write `see GB-ICON-SWAP in docs/upstream-sources.md` rather than another
   copy of the URL and line.

Escape any `|` inside a snippet as `\|`.

## Registry

| ID | Raw URL | Line | Must contain | Enclosing symbol | Claim it supports |
|---|---|---|---|---|---|
| GB-PRESSED-FILL | https://raw.githubusercontent.com/WordPress/gutenberg/trunk/packages/components/src/button/style.scss | 347 | `background: $components-color-foreground;` | `&.is-pressed` block in `.components-button` | Core applies no *semantic* background colour to a pinned-item button — the pressed state fills with a neutral foreground token. (Not the only background core sets: `:disabled`/`[aria-disabled]` gets `$gray-600`, and `.is-tertiary:hover` gets an accent mix in the icon-labels mode. None of them encode meaning, which is the point.) This is why WP Sudo carries state on the glyph and not a chip. |
| GB-ICON-SWAP | https://raw.githubusercontent.com/WordPress/gutenberg/trunk/packages/interface/src/components/complementary-area/index.js | 280 | `icon={ showIconLabels ? check : icon }` | `ComplementaryAreaToggle` rendered inside `<PinnedItems>` | A conditional icon on this exact button is core's own construction, so a state-driven glyph is precedent, not invention. Also the mechanism by which core takes the icon slot when "Show button text labels" is on. |
| GB-NO-TOOLTIP | https://raw.githubusercontent.com/WordPress/gutenberg/trunk/packages/interface/src/components/complementary-area/index.js | 281 | `showTooltip={ ! showIconLabels }` | `ComplementaryAreaToggle` rendered inside `<PinnedItems>` | With icon labels on, core suppresses the tooltip too — so that cohort loses both the glyph and the hover affordance, which is why the active chip returns in that mode alone. |
| GB-SELECTED-ICON | https://raw.githubusercontent.com/WordPress/gutenberg/trunk/packages/interface/src/components/complementary-area-toggle/index.js | 58 | `icon={ selectedIcon && isSelected ? selectedIcon : icon }` | `ComplementaryAreaToggle` | The component itself takes a state-selected icon by design. |
| GB-PIN-STAR | https://raw.githubusercontent.com/WordPress/gutenberg/trunk/packages/interface/src/components/complementary-area/index.js | 326 | `icon={ isPinned ? starFilled : starEmpty }` | `Button.interface-complementary-area__pin-unpin-item`, in the panel header | **Counter-example, recorded so it is not miscited again.** This is the pin/unpin star inside the complementary-area panel header — a different control from the pinned toolbar button. #317's first commit cited it as precedent for the toolbar toggle; that was wrong, and was corrected on the same branch before merge. |
| GB-SCOPED-SUBSCRIBE | https://raw.githubusercontent.com/WordPress/gutenberg/trunk/packages/data/src/registry.ts | 62 | `const subscribe = (` | `createRegistry` | `subscribe( listener, storeNameOrDescriptor )` accepts a store scope, so the indicator's `core/preferences` listener does not run on every editor keystroke. |
| GB-AREA-IDENTIFIER | https://raw.githubusercontent.com/WordPress/gutenberg/trunk/packages/interface/src/components/complementary-area-toggle/index.js | 45 | `const identifier = identifierProp \|\| ` | `ComplementaryAreaToggle` | `aria-controls` is `{plugin-name}:{sidebar-name}`, which is what makes the CSS selector stable and locale-independent. |
| GB-AREA-ARIA-CONTROLS | https://raw.githubusercontent.com/WordPress/gutenberg/trunk/packages/interface/src/components/complementary-area-toggle/index.js | 59 | `aria-controls={ identifier.replace( '/', ':' ) }` | `ComplementaryAreaToggle` | The pinned button's `aria-controls` is the identifier with the first `/` swapped for `:`, which is why `button[aria-controls="wp-sudo-session-indicator:wp-sudo-session-indicator"]` is a stable, locale-independent selector. |
| GB-PINNED-CLASS | https://raw.githubusercontent.com/WordPress/gutenberg/trunk/packages/interface/src/components/pinned-items/index.js | 23 | `'interface-pinned-items'` | `PinnedItemsSlot` (NOT `PinnedItems` — that is the three-line `Fill` at L11-13; the class lives in the `Slot`) | The container class the stylesheet scopes to, so the Options-menu copy of the same toggle is not painted. |
