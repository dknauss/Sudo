---
created: 2026-06-30T00:00:00.000Z
title: Active-session revocation surfaces (separate session revocation from capability administration)
area: admin-ui
files:
  - includes/class-admin.php
  - admin/js/wp-sudo-admin.js
  - includes/class-sudo-session.php
  - includes/class-cli-command.php
---

## Status

Design brief — ready to seed a full phase plan (`/gsd:plan-phase`). Not yet
designed-reviewed or implemented. Per CLAUDE.md this is a non-trivial change
(new UI surface, capability checks, multisite/Users-list execution contexts):
write a design brief → spawn the design reviewer → then TDD.

## Problem / failure mode

Sudo-session revocation is reachable in the UI only from the Access-tab table,
which enumerates governance-**capability** holders (`get_sudo_cap_holders()`,
keyed on the four `*_wp_sudo_*` caps). But an active sudo session can belong to
**any** user who passed a gate and reauthenticated — including plain
administrators with no governance cap. Those sessions are *visible* (the
Users-list "Sudo Active" filter, `_wp_sudo_expires > time()`) but **not
revocable anywhere in the UI**; only `wp sudo revoke --user` reaches them. For an
incident-response action, the users most worth revoking are exactly the ones the
UI cannot target today.

Secondary issue: the cap-holder table mixes capability revocation ("Revoke") and
session revocation ("Revoke Session") in adjacent columns with near-identical
labels, which reads ambiguously — it is unclear whether the UI revokes the
capability, the session, or both.

## Proposed approach

Separate the two concerns:

1. **Capability administration** stays in the holder table + grant form (manage
   *who governs Sudo*). Remove the "Revoke Session" button from this table, or
   relabel both controls so capability-revoke vs session-revoke is unambiguous.
2. **Session revocation** becomes its own surface keyed on **active sessions**,
   reusing the existing `_wp_sudo_expires > time()` enumeration:
   - a **"Revoke sudo session" row action on the Users list**, shown only for
     users currently in the active-session set (reuses `user_row_actions` +
     `filter_users_by_sudo_active` machinery already present), and/or
   - a dedicated **"Active Sessions" panel** on the Access tab listing all
     session-holders with a per-row revoke, plus the network-wide **"revoke all"**
     that is currently CLI-only (`revoke_all_active_sessions()`).

Reuse the existing server side: `handle_revoke_session()`, the
`REVOKE_RATE_PREFIX` rate limiter, and the `wp_sudo_session_revoked` audit hook.

Likely files: `includes/class-admin.php` (new render + AJAX handler or row-action
wiring), `admin/js/wp-sudo-admin.js` (handler with **visible** success + error
feedback — the current revoke-session JS passes `null` as the result element, so
gate/cap errors surface only as a `window.alert()`), and the Users-list
integration.

## What it must block / must not block

- **Must** keep requiring `revoke_wp_sudo_sessions` on every path; keep the
  10/hour per-revoker rate limit; fire `wp_sudo_session_revoked`; and remain
  itself gated (revoking is destructive — it is already a gated AJAX action in
  `options.wp_sudo_access`).
- **Must not** let session revocation imply or require any *other* governance cap
  — the four caps stay discrete (granting `manage_wp_sudo` confers nothing else).
- **Must not** allow revoking via an unauthenticated/REST path, and must not
  regress the last-manager / self-revoke guards.
- **Multisite:** per-site session meta; honour the `is_super_admin()`
  short-circuit in `wp_sudo_can()`. The Users-list row action must not render for
  users without a live session.

## Open questions for the design reviewer

- Users-list row action vs. Access-tab "Active Sessions" panel vs. both?
- Should "revoke all" gain a UI button (with a confirm dialog) or stay CLI-only?
- Should the surface show session metadata (expiry countdown, bound context) to
  aid the revoke decision — and does surfacing that leak anything sensitive?
- Note `Sudo_Session::is_active()` requires a cookie-bound token, so the
  enumeration uses the `_wp_sudo_expires > time()` meta query (browser-independent),
  not `is_active()` — keep that distinction explicit in the new code.

## Bundle in: "Sudo governance coverage" panel is wrong on multisite

The drift/coverage panel (`render_drift_detection_panel()`,
`includes/class-admin.php`) has two multisite defects that should be fixed in the
same phase, since both are scope/correctness questions:

1. **Wording:** the body string hardcodes "manage_options", but detection uses
   `manage_network_options` on multisite (the `$capability` ternary at the top of
   the method). On a network the text names the wrong capability. Fix by injecting
   `$capability` via `printf`/`sprintf` or branching the message — do not just
   hardcode the other cap.
2. **False positives:** the filter keys on the **raw** `allcaps['manage_wp_sudo']`,
   which intentionally bypasses `map_meta_cap` (to dodge the recovery-mode remap).
   But it also bypasses the legitimate **super-admin short-circuit** in
   `wp_sudo_can()` (`if ( is_multisite() && is_super_admin( $user_id ) ) return true;`).
   On multisite the candidate set (`manage_network_options` holders) *is* the super
   admins, who **can** reach Sudo settings via that short-circuit — so the panel can
   list a super admin and assert they "cannot access Sudo settings," which is false.

Design questions: scope the panel to single-site only? Exclude super admins on
multisite? Or measure *effective* access via `wp_sudo_can()` instead of raw
`allcaps`, while still avoiding the recovery-mode remap that the current raw-cap
check was written to dodge? The reviewer should reconcile "show true stored
governance state" (the original intent in the method comment) against "don't
mislabel users who actually have access."

## Related

- Demo blueprints were fixed (2026-06-30) so `carlosadmin`/`mariadev` hold
  governance caps and thus appear as revocable rows — that was a data fix, not
  the UI redesign this brief covers.
- Drift-detection copy/heading reworded the same day (now "Sudo governance
  coverage").
