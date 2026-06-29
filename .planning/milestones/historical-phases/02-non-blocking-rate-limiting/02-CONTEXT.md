# Phase 2 Context

## Decisions (Locked)

- **No blocking delay in request thread:** `Sudo_Session::attempt_activation()` and `record_failed_attempt()` must not call `sleep()`/`usleep()`.
- **Storage model is explicit:** use append-oriented failure events via `add_user_meta()` duplicate keys (not `update_user_meta()` on a serialized array).
- **Preserve external result contract:** keep existing result codes (`success`, `2fa_pending`, `locked_out`, `invalid_password`) and retain optional `delay` metadata for client UX.
- **Preserve hook contract:** `wp_sudo_reauth_failed` and `wp_sudo_lockout` must continue firing with the same argument shape.
- **Keep lockout policy unchanged:** threshold remains 5 failed attempts and lockout duration remains 300 seconds.
- **Use WordPress-native storage only:** no new tables/services; user meta remains the persistence layer.
- **Throttle UX must be wired:** server-side `delay` must reach the browser via AJAX response and client logic must temporarily disable retry with visible countdown feedback.
- **Backward-compatible cleanup:** uninstall must remove both legacy and any new rate-limit meta keys.

## Claude/Codex Discretion

- Final internal key names for throttle/failure-event metadata.
- Exact helper method decomposition inside `Sudo_Session` (prune/count/throttle helpers).
- Whether to keep writing `_wp_sudo_failed_attempts` as a compatibility snapshot or fully migrate reads to event-derived counts.
- Exact challenge-page countdown text and disabling UX for throttle window.

## Deferred Ideas (Out of Scope)

- New database tables, transactional locks, or external rate-limit stores.
- UI redesign of challenge page beyond throttle feedback wiring.
- Custom admin settings for throttle curve in this phase.
- Broader auth architecture changes (device-binding redesign, per-session isolation changes).

## Success Conditions for Phase 2

- No blocking delays in failed-auth path.
- Failure tracking uses append rows (`add_user_meta`) and narrows counter race risk.
- Throttle delay is visible and enforced in challenge AJAX/JS UX.
- Lockout and retry behavior remain deterministic and test-covered.
- Existing hooks and challenge flow remain compatible.
- Unit + integration + static analysis + lint all pass.
