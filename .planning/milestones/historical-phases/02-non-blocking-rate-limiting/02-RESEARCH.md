# Phase 2 Research: Non-Blocking Rate Limiting

Date: 2026-03-04  
Phase: 02-non-blocking-rate-limiting  
Goal: Replace blocking `sleep()` with time-based throttling and narrow TOCTOU race risk in failed-attempt tracking.

## Current State (Code-Verified)

### Runtime behavior today

- `Sudo_Session::attempt_activation()` calls `record_failed_attempt()` on invalid password.
- `Sudo_Session::record_failed_attempt()`:
  - Reads failed count from user meta (`_wp_sudo_failed_attempts`), increments in PHP, writes back.
  - Sets `_wp_sudo_lockout_until` at threshold.
  - Calls `sleep($delay)` for attempts 4 and 5 via `PROGRESSIVE_DELAYS`.

### AJAX/UX behavior today

- `Challenge::handle_ajax_auth()` default branch returns generic 401 incorrect-password message.
- Current JS handler only has explicit countdown UX for `locked_out` + `remaining`.
- There is no dedicated branch to surface a non-lockout throttle delay.

### Why this is a problem

1. **Availability:** `sleep()` blocks PHP workers under abusive concurrent failures.
2. **Race susceptibility:** integer read-modify-write has TOCTOU windows under concurrency.
3. **UX regression risk after backend fix:** if server returns non-blocking delay but AJAX/JS does not surface it, users see repeated "Incorrect password" during throttle windows.

## Constraints and Contracts

### Must preserve

- Public result codes consumed by `Challenge::handle_ajax_auth()`:
  - `success`, `2fa_pending`, `locked_out`, `invalid_password`
- Hook contracts:
  - `wp_sudo_reauth_failed( int $user_id, int $attempts )`
  - `wp_sudo_lockout( int $user_id, int $attempts )`
- Policy constants:
  - `MAX_FAILED_ATTEMPTS = 5`
  - `LOCKOUT_DURATION = 300`

### Must improve

- Remove all blocking delay calls from auth-failure path.
- Enforce retry windows via metadata (non-blocking).
- Replace scalar increment dependence with append-style writes.
- Wire `delay` through AJAX + JS so throttle state is explicit to the user.

## Design Options Considered

### Option A: Keep integer counter, replace sleep with retry-until timestamp

- Pros: smallest patch.
- Cons: preserves core TOCTOU weakness (counter update race).
- Verdict: insufficient for phase goal.

### Option B: Store array of timestamps in one meta value via `update_user_meta`

- Pros: richer model than scalar count.
- Cons: still read-modify-write race on same row.
- Verdict: partial improvement only.

### Option C (Recommended): Append failure events via `add_user_meta` + throttle-until meta

- Approach:
  - On each failed password attempt, append an event row with `add_user_meta()`.
  - Count active events from `get_user_meta( $user_id, $key, false )`.
  - Maintain non-blocking retry boundary in dedicated throttle meta (`*_throttle_until`).
  - Return `delay` metadata and expose it in AJAX/JS UX.
- Pros:
  - Removes worker blocking.
  - Avoids scalar overwrite races from read-before-write counters.
  - Preserves deterministic lockout/hook semantics.
- Cons:
  - Requires cleanup/prune helpers and uninstall updates.

### Option D: Dedicated table / SQL atomic updates

- Pros: strongest atomicity.
- Cons: architectural scope increase, migration burden.
- Verdict: out of scope for this phase.

## Recommended Implementation Shape

1. **Core algorithm (`Sudo_Session`)**
   - Check active lockout first.
   - Check throttle-until; if active, return `invalid_password` + `delay` immediately.
   - On bad password:
     - append failure event (`add_user_meta`),
     - derive attempt count,
     - fire `wp_sudo_reauth_failed`,
     - set lockout and fire `wp_sudo_lockout` at threshold.
   - On success or expired lockout:
     - clear failure events, lockout meta, throttle meta.

2. **AJAX/JS propagation**
   - `Challenge::handle_ajax_auth()` must include `delay` in error payload when present.
   - `admin/js/wp-sudo-challenge.js` must disable submit and display a short countdown for throttle windows (distinct from lockout if needed).

3. **Compatibility handling**
   - Keep legacy key cleanup in uninstall.
   - Rewrite integration tests coupled to scalar `_wp_sudo_failed_attempts` values.

## Test Impact Map

### Unit

- `tests/Unit/SudoSessionTest.php`
  - Add RED tests for throttle short-circuit and append-event attempt counting.
  - Validate lockout threshold/hook contract with new model.
- `tests/Unit/ChallengeTest.php`
  - Add tests asserting `delay` is forwarded in AJAX error responses.

### Integration

- `tests/Integration/RateLimitingTest.php`
  - Rewrite scalar-counter-coupled assertions (especially `test_failed_attempts_increment_user_meta()`) to event/count semantics.
  - Add throttle-window behavior checks.
- `tests/Integration/AuditHooksTest.php`
  - Keep hook payload validation without sleep-based assumptions.
- `tests/Integration/ChallengeTest.php`
  - Verify challenge auth response behavior under throttle and lockout.
- `tests/Integration/UninstallTest.php`
  - Assert new and legacy rate-limit keys are deleted on uninstall.

### Documentation

- `docs/security-model.md`
  - Update rate-limit storage table and cache/race notes for append-event + throttle model.

## Verification Commands (Phase Gate)

```bash
composer test:unit -- --do-not-cache-result
composer test:integration -- --do-not-cache-result
WP_MULTISITE=1 composer test:integration -- --do-not-cache-result
composer analyse:phpstan
composer analyse:psalm
composer lint
```

## Risks and Mitigations

1. **Risk:** throttle remains invisible in browser UX.  
   **Mitigation:** explicit Phase 2 plan for AJAX + JS delay wiring.

2. **Risk:** meta growth from append rows.  
   **Mitigation:** reset on success/expiry and prune stale events during failure processing.

3. **Risk:** hook argument regressions.  
   **Mitigation:** targeted unit/integration hook assertions.

## Conclusion

Phase 2 should execute as four plans: core engine TDD, integration contract TDD, AJAX/JS throttle UX wiring, then cleanup/docs/full-gate verification. This closes availability, TOCTOU, and UX gaps in one hardening sequence.
