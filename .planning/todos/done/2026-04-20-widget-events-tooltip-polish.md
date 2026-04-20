# Widget Event-Row Tooltip Polish

Status: ✅ Completed on April 20, 2026  
Created: April 20, 2026  
Priority: medium (UI polish)

## Goal

Add simple hover/title tooltips for the **User**, **Event**, **Action**, and
**Surface** columns in the dashboard widget Recent Events table, using the same
lightweight approach already used by the Time column.

## Problem statement

- Time already shows an absolute timestamp on hover.
- Other columns do not consistently provide hover context.
- Action technical IDs currently use the generic title string
  `"Technical action ID"` with no row-specific detail.

## Scope (v1)

Implement `title=""` hover text for:

1. **User**
   - Username or deleted-user label as rendered.
2. **Event**
   - Human event label (e.g., `Gated`, `Replayed`, `Passed`).
3. **Action**
   - Primary action label on the visible text.
   - Technical code suffix tooltip should include the actual ID value
     (e.g., `Technical action ID: options.wp_sudo`), not the generic string.
4. **Surface**
   - Surface display label (e.g., `admin`, `app-pass`, `reauth`).

## Non-goals

- No new JS tooltip library.
- No custom popovers or help icons.
- No schema or event-store changes.

## Acceptance criteria

- Hovering cells in User/Event/Action/Surface shows relevant title text.
- Action code tooltip includes the concrete rule ID value per row.
- No measurable widget-query overhead (tooltips derived from already-rendered row data).
- Screen-reader behavior remains unchanged (title only; no aria regressions).
- Unit tests cover tooltip output for at least one representative row.

## Notes

- Keep wording concise and user-facing in visible UI.
- Keep technical detail in code-markup tooltip where power users expect it.
