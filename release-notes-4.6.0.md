Feature release. Adds block-editor in-editor reauthentication (link-out increment) and an optional push-notification bridge for high-severity audit events, plus the admin user-identity harmonization that had been staged as 4.5.1. Backward-compatible — no migration required.

## Highlights

- **In-editor reauthentication for the block editor.** When a block-editor request is soft-blocked with `sudo_required`, the editor now shows an in-editor snackbar with a "Reauthenticate" action that opens the challenge page, instead of dead-ending on an opaque 403. Editor state is preserved; you grant a sudo session and retry. An `apiFetch` middleware detects the block (including inside a `/batch/v1` envelope) and uses the server-emitted `challenge_url` verbatim, degrading to a plain message if that URL is absent or not same-origin. Supporting server plumbing keeps a long-open editor able to reauthenticate. This is the link-out increment — the in-editor password/2FA modal and automatic request re-dispatch come in a later release.

- **Optional critical-event alert bridge.** New optional mu-plugin (`bridges/wp-sudo-critical-alert-bridge.php`) that *pushes* a notification when a high-severity audit hook fires — capability tamper, blocked escalation, reauth lockout, and dropped built-in rules (plus opt-in, throttled recovery-mode) — where the Stream/WSAL bridges only *log*. Emails the scope-appropriate admin out of the box, with a `wp_sudo_critical_alert_dispatch` filter to send Slack/Teams/webhook instead. Alerts are dispatched on `shutdown` (never delaying the gate), deduped per identity, and capped per recipient, with an overflow digest. A demo companion renders alerts inline for sandboxes without outbound network (e.g. WordPress Playground). Inert unless installed.

- **Harmonized user identity across admin surfaces.** The Session Activity dashboard widget and the Settings → Sudo Access tab now present users identically: full real name primary, username secondary (linked to user-edit when permitted), with an avatar and translated role chips. Also fixes a widget avatar that failed to render when the site's "Show Avatars" setting was off.

## Notes

- **No migration required.** The alert bridge is opt-in — drop it into `wp-content/mu-plugins/`.
- **Requires** WordPress 6.4+ and PHP 8.2+.
- **Versioning:** minor. The new documented public extension filters shipped with the alert bridge are a backward-compatible API addition (see `VERSIONING.md`).

**Full changelog:** see `CHANGELOG.md` → 4.6.0, or `git log v4.5.0..v4.6.0 --oneline`.
