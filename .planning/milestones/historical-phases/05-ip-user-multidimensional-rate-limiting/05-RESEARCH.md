# Phase 5 Research: IP + User Multidimensional Rate Limiting

Date: 2026-03-07  
Phase: 05-ip-user-multidimensional-rate-limiting  
Goal: add per-IP tracking alongside per-user tracking, then enforce a combined lockout policy that preserves current challenge contracts.

## Current State (Code-Verified)

### Existing rate-limit model

- `Sudo_Session` tracks failed attempts with per-user append-row user meta (`_wp_sudo_failure_event`).
- Lockout is per-user only (`_wp_sudo_lockout_until`).
- Throttle is per-user only (`_wp_sudo_throttle_until`).
- `record_failed_attempt()` triggers lockout at 5 failed attempts and fires:
  - `do_action( 'wp_sudo_lockout', int $user_id, int $attempts )`

### Gap for v2.13 scope

- No per-IP accounting exists, so a distributed credential-guessing pattern can rotate users while staying below per-user thresholds.
- Audit hooks do not include request IP context for lockout events.

## Design Options Considered

### IP storage model

1. **User meta rows keyed by IP per user**
- Pros: reuses metadata APIs.
- Cons: does not provide cross-user lockout signal for a shared source IP.
- Verdict: insufficient.

2. **Transient-backed per-IP attempt bucket + transient IP lockout marker (recommended)**
- Pros: naturally cross-user, bounded lifetime, no schema changes.
- Cons: transient races remain possible under extreme concurrency (acceptable for defense-in-depth).
- Verdict: recommended.

3. **Custom DB table for atomic counters**
- Pros: strongest consistency.
- Cons: migration/maintenance overhead and out of scope for this phase.
- Verdict: rejected.

### Combined lockout decision

1. **Require both dimensions to hit threshold**
- Pros: lower false positives.
- Cons: weakens protection against single-dimension abuse patterns.
- Verdict: rejected.

2. **Lock out when either user or IP threshold is reached (recommended)**
- Pros: aligns with multidimensional hardening intent.
- Cons: may block benign shared-IP scenarios during active abuse.
- Verdict: recommended.

## Recommended Implementation Shape

### A. Sudo_Session updates

- Add request-IP extraction helper (defaulting to `REMOTE_ADDR`, with optional filter extension point).
- Add per-IP failed-attempt tracking via transient event bucket (append timestamps, prune old entries).
- Add IP lockout transient with `LOCKOUT_DURATION` semantics.
- Update `attempt_activation()` lockout checks to include active IP lockout.
- Update `record_failed_attempt()` to:
  - write both user and IP attempt events,
  - compute both counts,
  - trigger lockout when either reaches threshold,
  - fire `wp_sudo_lockout` with IP as third argument.

### B. Test updates

- Unit:
  - IP extraction and sanitization behavior.
  - Combined lockout branch when IP hits threshold first.
  - Hook payload includes IP while preserving existing first args.
- Integration:
  - Same-IP cross-user failures trigger lockout at threshold.
  - Active IP lockout blocks subsequent attempts from that IP.
  - Audit hook receives IP in real WP runtime.

### C. Documentation updates

- `docs/developer-reference.md` hook signature update (`wp_sudo_lockout` third arg).
- `docs/security-model.md` caching/state table updates for IP transients.
- `tests/MANUAL-TESTING.md` scenario updates for same-IP combined lockout behavior.

## Risks and Mitigations

1. **Risk:** trusting spoofable proxy headers.
- **Mitigation:** default to `REMOTE_ADDR`; expose filter for environments with trusted proxy handling.

2. **Risk:** transient cleanup drift.
- **Mitigation:** prune per-IP attempt buckets on each failed attempt and keep explicit lockout expiry timestamp.

3. **Risk:** backward compatibility for hook listeners.
- **Mitigation:** append IP as third argument only; existing 2-arg callbacks remain valid.

## Verification Gates (Phase-level)

```bash
composer test:unit -- --do-not-cache-result
composer test:integration -- --do-not-cache-result
WP_MULTISITE=1 composer test:integration -- --do-not-cache-result
composer analyse:phpstan
composer analyse:psalm
composer lint
```

## Conclusion

Phase 5 should execute as three plans:

1. `05-01` core unit-first implementation for IP tracking and combined lockout.
2. `05-02` integration + audit-hook contract validation in single-site and multisite runs.
3. `05-03` docs/manual alignment and full gate closure.
