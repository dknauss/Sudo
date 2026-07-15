# Research: in-editor sudo session-active indicator

## Status

Tentative research backlog. Milestone-B-adjacent (does not depend on in-modal
2FA and could ship before or after it). Research only — do not implement until
the questions below are answered.

## Problem

After a user reauthenticates in the block editor (Milestone A modal), there is
**no visible indication inside the editor** that a sudo session is active or how
long it has left. The live countdown lives in `Admin_Bar`
(`admin_bar_menu`, priority 100), but the block editor runs full-screen and
**hides the WP admin bar**, so the countdown never renders while editing. DOM
probes of the Playground demo confirm no `#wp-admin-bar-wp-sudo` node is present
on the editor page.

Consequences:

- A user cannot tell whether they are still in a sudo window, so they cannot
  predict whether the next gated action will reauth or pass silently.
- The reauth UX is "modal appears on challenge, then nothing" — no positive
  confirmation that the grant succeeded and is still in effect.
- This is distinct from the demo's per-page-load session reset (a demo-only
  affordance in `blueprint-editor-reauth.json` to keep the modal demonstrable);
  the gap exists in real WordPress, where the session legitimately persists for
  its 1–15 min duration but is invisible in the editor.

## Candidate approaches

1. **Editor-header slot fill** — render a small "sudo active · N min" indicator
   via a `wp.plugins` registration into an editor slot (e.g. the
   `PinnedItems`/header area). Closest analogue to the admin-bar timer.
2. **Snackbar / notice on grant** — on a successful in-editor grant, dispatch a
   `core/notices` snackbar ("Reauthenticated — sudo active for N minutes").
   Cheapest; transient, not a persistent countdown.
3. **PluginSidebar panel** — a dedicated sudo panel with live countdown and a
   "end session now" control. Most capable; heaviest.

Lightest useful slice is likely (2) plus a static header badge; a live countdown
(1) reuses the same expiry data the admin bar already consumes.

## Required research questions

- What expiry data is already exposed to the editor? The modal grant flow knows
  when a session was granted; is the `expires` timestamp available client-side
  without a new endpoint, or does the indicator need a `permission_callback`'d
  read route (`sudo/session-status`)? Prefer reusing existing localized data
  over adding a surface.
- Which editor slot/API is stable across the WP versions in the support window
  (6.4+) and both the post editor and the site editor?
- Should the indicator poll/tick client-side off a single granted-at timestamp
  (no network) or periodically confirm server-side (adds requests on a hot UI)?
  Default to client-side tick off the known expiry; never poll on an interval.
- How should it behave at expiry and within the 120 s grace window
  (`is_within_grace`)? Show "expiring", then clear? Avoid implying a session is
  active when only the grace window remains.
- Multisite / network-admin editor contexts — any difference?
- Accessibility: the countdown must not be a `polite`/`assertive` live-region
  nuisance; announce grant once, then present the timer as static readable text.

## Boundaries / non-goals

- Not a security control — purely an informational affordance. It must read
  session state, never extend or mint it.
- Must not add a gated-action bypass or a new way to grant/refresh a session.
- Must not run network requests on a timer (editor performance).

## Acceptance criteria before implementation planning

- A short note picks one approach and names the exact editor slot/API + the data
  source (existing localized value vs. new read endpoint).
- Behavior at expiry and in the grace window is specified.
- Single-site + multisite, post editor + site editor coverage is confirmed.
- Accessibility behavior (announce-once, static timer) is specified.
- Tests: unit for the tick/format logic, E2E for grant → indicator visible →
  expiry → cleared.
