# Accessibility Audit Record (archived)

> Relocated from `docs/ROADMAP.md` Appendix A on 2026-07-25. Status: complete —
> initial audit resolved in v2.2.0–v2.3.1, follow-up audit resolved in v2.10.1.
> Retained for the WCAG audit trail; not part of the forward roadmap.


> **Status: Complete.** Initial audit items resolved in v2.2.0–v2.3.1. Follow-up
> audit items (v2.4.0–v2.10.0 UI additions) resolved in v2.10.1.

### Initial audit (v2.2.0–v2.3.1)

All Critical, High, Medium, and Low severity items from the WCAG 2.1 AA audit and
WCAG 2.2 AA follow-up audit have been addressed:

- **Escape key guard (WCAG 3.2.2):** `aria-live` announcement with 600 ms delay
  before navigating away from the challenge page.
- **Step-change announcement (WCAG 4.1.3):** Password → 2FA transition announced
  via `wp.a11y.speak()`.
- **Settings label-input association (WCAG 1.3.1):** All `add_settings_field()`
  calls include `label_for` matching the rendered input `id`.
- **Replay status message (WCAG 4.1.3):** Visible "Replaying your action..." message
  and `wp.a11y.speak()` announcement before form submission.
- **Localized JavaScript strings (i18n):** All user-facing strings passed through
  `wp_localize_script()`.
- **Session expiry handling (WCAG 2.2.1):** "Start over" button replaces automatic
  reload.
- **Reduced motion preferences:** `@media (prefers-reduced-motion: reduce)` rules
  in both CSS files.
- **Focus-visible outlines:** `:focus-visible` outlines with proper offset.
- **Gated actions table semantics:** Native table semantics with `<caption>` element
  (replaced `role="presentation"` in v2.2.0).
- **Disabled link contrast:** Changed to `#787c82` (4.6:1 ratio, WCAG AA).
- **Admin notice ARIA roles:** `role="alert"` on blocked-action notice,
  `role="status"` on gate notice.
- **MU-plugin message area:** `role="status"` and `aria-live="polite"`.
- **Admin bar countdown cleanup:** `pagehide` listener clears interval, prevents
  bfcache issues.
- **Settings default value documentation (WCAG 3.3.5):** Inline `<p class="description">`
  text on all fields.
- **Lockout countdown SR throttling (WCAG 4.1.3):** `aria-live="off"` with
  30-second and 10-second `announce()` intervals.

### Follow-up audit (v2.4.0–v2.10.0 additions, fixed v2.11.0)

Three accessibility gaps found in UI added after v2.3.1:

- **Per-app-password policy SR feedback (WCAG 4.1.3):** Save success/error was
  visual-only (outline color). Added `wp.a11y.speak()` announcements for save
  confirmation and error states. Added `wp-a11y` as script dependency.
- **Disabled action button semantics (WCAG 4.1.2):** `aria-disabled="true"` on
  `<a>` elements (theme/plugin pages) without `role="button"` — screen readers
  may not announce disabled state on native links. Added `role="button"` to
  disabled `<a>` elements.
- **MU-plugin message `aria-atomic` (WCAG 4.1.3):** `role="status"` +
  `aria-live="polite"` message element was missing `aria-atomic="true"`, so
  content replacements may only announce changed text nodes. Added attribute.

Also fixed in this pass:

- **Admin bar countdown 0:01 stall (WCAG 4.1.3):** Timer label never updated to
  `0:00` on session expiry — last visible value was `0:01` during reload latency.
  Fixed: label updates to `0:00`, interval cleared, expiry announced before reload.
