# Phase 5 Context

## Decisions (Locked)

- **Two-dimensional tracking:** failed reauthentication attempts are tracked in both existing per-user state and a new per-IP state.
- **Combined lockout policy:** lockout triggers when either dimension reaches threshold (`MAX_FAILED_ATTEMPTS`).
- **Preserve existing user contract:** result codes remain `success`, `2fa_pending`, `locked_out`, `invalid_password`; delay semantics remain additive.
- **Preserve default threshold/duration:** threshold remains 5 attempts and lockout remains 300 seconds unless explicitly changed in a future phase.
- **IP lockout is time-boxed:** IP-triggered lockout uses a finite lockout window, not a 24h hard block.
- **Backward-compatible audit hook:** `wp_sudo_lockout` adds IP as an additional argument while preserving existing first two arguments.
- **WordPress-native storage:** use user meta + transients only; no new tables/services.
- **TDD execution:** every production change follows failing tests first.

## Claude/Codex Discretion

- Exact transient key derivation strategy for IP tracking and lockout windows.
- IP source extraction details (`REMOTE_ADDR` defaults and extension/filter behavior).
- Whether combined-attempt count passed to audit hooks should be user count, IP count, or `max(user, ip)`.
- Scope of docs/manual updates between developer-reference, security-model, and manual checklist.

## Deferred Ideas (Out of Scope)

- CIDR/network-prefix based lockouts.
- External/shared rate-limit stores (Redis, WAF, edge service).
- Per-surface thresholds (different limits for REST/AJAX/admin).
- Advanced proxy-trust admin UI.

## Success Conditions for Phase 5

- IP and user dimensions are both tracked and validated by tests.
- Combined policy locks out correctly when either dimension hits threshold.
- Existing challenge/session response contracts remain compatible.
- Audit visibility includes IP context for lockout events.
- Unit + integration + static analysis + lint gates pass.
