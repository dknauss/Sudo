# External Audit Mode Spec (v3.2 candidate)

*Draft: April 20, 2026. Status: not scheduled. Target: Phase 5 (governance polish) of [`execution-plan-v3.1-v3.3.md`](execution-plan-v3.1-v3.3.md), optional v3.2.*

## Problem

Operators who use **Stream** or **WP Activity Log (WSAL)** as their canonical
audit destination receive WP Sudo events through the shipped bridges:

- `bridges/wp-sudo-stream-bridge.php` (added v2.12.0)
- `bridges/wp-sudo-wsal-sensor.php` (added v2.11.0)

Both bridges subscribe to WP Sudo's public audit hooks, so the external
destination already captures every gated action, grant, revoke, lockout, and
policy change.

WP Sudo **also** writes the same events to its own `wpsudo_events` table
through `Event_Recorder`. For Stream/WSAL operators this produces:

- Redundant storage (14-day retention duplicating the bridge's record).
- Extra write cost on every gated action — material at the Tier 2 scale
  boundary described in [`session-store-evaluation.md`](session-store-evaluation.md)
  (~1,000 concurrently sudo-active users per site).
- A second source of truth that auditors have to reconcile against the
  canonical WSAL/Stream trail.

There is no operator-facing way to opt out of the internal store without
also breaking the dashboard widget's Recent Events panel and the governance
spec's `view_wp_sudo_activity` capability semantics.

## Non-goals

- **Not a "disable logging" kill switch.** Silencing audit without a
  verified destination is unacceptable in a security plugin.
- **Not a widget-hiding feature.** Per-user widget visibility is already
  handled by WP core Screen Options; per-role visibility is covered by the
  v3.1 `view_wp_sudo_activity` capability.
- **Not a bridge replacement.** The bridges themselves do not change.

## Design

A single operator setting, `wp_sudo_external_audit`, with three values:

| Value    | Event store writes | Widget Recent Events panel         | Audit hooks fire | Bridges receive events |
|----------|--------------------|-----------------------------------|------------------|------------------------|
| `off`    | Enabled (default)  | Full table from `Event_Store`     | Yes              | Yes                    |
| `stream` | Suppressed         | Bridge status tile, link to Stream| Yes              | Yes                    |
| `wsal`   | Suppressed         | Bridge status tile, link to WSAL  | Yes              | Yes                    |

**Key invariant:** audit hooks keep firing regardless of the setting. Only
the `Event_Recorder::record()` path to `wpsudo_events` is conditional. This
guarantees that:

- External bridges continue to receive events.
- Third-party integrations on `wp_sudo_*` hooks are unaffected.
- The dashboard widget's **Active Sessions** and **Policy Summary** panels
  (neither depends on the event store) continue to work unchanged.

## Activation flow

Switching `wp_sudo_external_audit` away from `off` is gated by four checks,
in order:

1. **Capability.** Setting is editable only by `sudo_can( 'manage_wp_sudo' )`
   (per the [governance spec](internal-admin-governance-spec.md)).
2. **Sudo-gated.** The save handler writes through the
   `options.wp_sudo_access` gated rule, so the mutation itself requires an
   active sudo session. Changing audit destination is privilege-sensitive.
3. **Bridge preflight.** The chosen bridge must be **loaded and active** at
   save time:
   - `stream` → `class_exists( 'WP_Stream\Connectors' )` (or the equivalent
     published API at implementation time) **and** the Sudo Stream connector
     is registered.
   - `wsal` → `class_exists( 'WpSecurityAuditLog' )` **and** the Sudo
     sensor is loaded.
   If preflight fails, the save is rejected with an admin notice and the
   setting stays at `off`. The rejection reason is logged via
   `wp_sudo_external_audit_preflight_failed` (see hooks below).
4. **Confirmation.** The Settings UI requires a confirmation checkbox ("I
   understand that WP Sudo's internal event log will stop recording; events
   will only be visible in Stream/WSAL") before the save button enables.

Deactivation (`stream`/`wsal` → `off`) has no preflight: restoring internal
logging is always safe.

## Runtime behavior

### Event_Recorder short-circuit

```php
public static function record( array $event ): void {
    if ( self::is_external_audit_active() ) {
        /**
         * Fires when an event would have been written to wpsudo_events
         * but external audit mode routed it to a bridge instead.
         *
         * @param array  $event   The event that was not persisted locally.
         * @param string $target  'stream' or 'wsal'.
         */
        do_action( 'wp_sudo_event_externally_routed', $event, self::external_audit_target() );
        return;
    }
    // ... existing buffer + shutdown flush path.
}
```

`is_external_audit_active()` returns `false` if the bridge has since been
deactivated (see integrity warning below) — this is a fail-closed guard, not
the primary check.

### Dashboard widget

The widget's `render_events_panel()` method branches on the setting:

- `off`: renders the existing Recent Events table (current 3.0.0 behavior).
- `stream`/`wsal`: renders a compact status tile:

  > **Audit routed to Stream** ✓
  >
  > Recent WP Sudo events are recorded in Stream.
  > Open Stream in wp-admin: `/wp-admin/admin.php?page=wp_stream`
  > *Last event received: 3 minutes ago.*

  "Last event received" uses a 60-second transient updated on each
  `wp_sudo_event_externally_routed` dispatch, so the widget confirms the
  bridge is actually getting events — not just that the setting is on.

Active Sessions panel, Policy Summary panel, and filters are unchanged.

### Integrity warning: bridge deactivated while mode active

If `wp_sudo_external_audit` is `stream` or `wsal` and the corresponding
plugin/bridge is no longer loaded at `admin_init`:

1. A permanent, non-dismissible admin notice shows on every admin page:

   > **⚠ WP Sudo: External audit destination not available.**
   > The {Stream|WSAL} plugin is not loaded, but WP Sudo is configured to
   > route events there. New events are **not being recorded anywhere**
   > until this is resolved.
   > [Restore internal logging] | [Re-enable {Stream|WSAL}]

2. `Event_Recorder::is_external_audit_active()` returns `false` as a
   fail-closed guard, so writes resume to `wpsudo_events`. The operator
   sees the mismatch but no audit coverage is lost in practice.

3. A one-time event `governance.external_audit_bridge_missing` is recorded
   the first time the condition is detected per admin session.

This matches the pattern already shipped in v3.0.0 for passed-event logging
overrides (integrity warning when visibility is narrower than expected).

## Audit hooks

New hooks added in Phase 5:

| Hook                                           | Fired when                                                         | Payload                                              |
|------------------------------------------------|--------------------------------------------------------------------|------------------------------------------------------|
| `wp_sudo_external_audit_enabled`               | Setting transitions `off` → `stream` / `wsal`                      | `( string $target, int $user_id )`                   |
| `wp_sudo_external_audit_disabled`              | Setting transitions `stream` / `wsal` → `off`                      | `( string $previous_target, int $user_id )`          |
| `wp_sudo_external_audit_preflight_failed`      | Attempted enable with bridge not loaded                             | `( string $target, string $reason, int $user_id )`   |
| `wp_sudo_event_externally_routed`              | An event is suppressed from `wpsudo_events` because mode is active | `( array $event, string $target )`                   |

All four are recorded in `wpsudo_events` itself even while external audit is
active (they describe the audit configuration, not routine events, and must
not be lost during a destination transition). They are also forwarded to
the active bridge.

A new event type `governance.external_audit_toggled` is emitted on each
transition so the destination system (Stream/WSAL) captures the configuration
change in its own record.

## Settings UI

In Settings → Sudo, under a new **Audit destination** section:

- Radio group: `Internal (default)` / `Stream` / `WSAL`.
- Stream/WSAL options are disabled (with hover explanation) when the
  corresponding plugin is not loaded.
- Confirmation checkbox appears when selecting Stream or WSAL.
- Save triggers the preflight flow described above.
- A status line below the radio group shows: "Last event received by
  {target}: Xs ago" when mode is active.

## Uninstall and upgrade behavior

- **Upgrade.** Installations upgrading from 3.x to 3.2+ default to `off`.
  No migration required — existing event-store behavior is preserved.
- **Uninstall.** The setting is removed alongside other `wp_sudo_*` options
  by `uninstall.php`. The `wpsudo_events` table is dropped as part of the
  normal uninstall path regardless of mode.
- **Bridge plugin removed.** See integrity warning above — fail-closed to
  internal logging with a visible notice.

## Test plan

Unit coverage:

- `Event_Recorder::record()` short-circuits when mode is active and a bridge
  is available.
- Short-circuit does **not** apply to governance-transition hooks (those are
  always persisted locally).
- Preflight rejects `stream`/`wsal` when the bridge plugin is missing.
- Fail-closed behavior when bridge is deactivated at runtime.

Integration coverage:

- Full round-trip: enable mode → emit event → verify no row in
  `wpsudo_events` → verify bridge captured event → disable mode → emit event
  → verify row appears.
- Multisite: setting is per-site (each site configures independently).
- Upgrade path from 3.0.x/3.1.x retains `off` and existing events.

Playwright coverage:

- Settings UI radio group enable/disable states.
- Confirmation checkbox gating.
- Integrity notice rendering when bridge is deactivated.
- Widget tile rendering and "last event received" liveness.

## Out of scope for this spec

- Routing to **both** internal store and an external bridge (the current
  default `off` already does this). No hybrid tier proposed.
- Supporting audit destinations beyond Stream and WSAL. A third bridge would
  need its own preflight detector and is out of scope until a concrete
  integration is requested.
- Automatic migration of historical `wpsudo_events` rows into the external
  destination on activation. Out of scope; the bridge captures from the
  point of activation forward, which matches WSAL/Stream semantics for any
  new sensor anyway.

## Why this is non-blocking

The v3.1 governance foundation does not depend on this feature. Operators
who want Stream or WSAL as their canonical audit can already use the bridges
today — they simply pay the duplicate-write cost. External Audit Mode is a
cost/consolidation optimization for a specific operator profile, not a
correctness fix. It should land only after v3.1 ships and real deployment
patterns confirm the demand.
