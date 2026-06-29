# Plan 05-01 Summary: Core IP + User Multidimensional Lockout

## Objective

Implement v2.13 core rate-limiting changes in `Sudo_Session` so lockout decisions are made across both user and source-IP dimensions.

## Changes

### Core

- **`includes/class-sudo-session.php`**
  - Added per-IP transient key constants:
    - `IP_FAILURE_EVENT_TRANSIENT_PREFIX`
    - `IP_LOCKOUT_UNTIL_TRANSIENT_PREFIX`
  - Added request-IP resolver with validation/fallback (`unknown`) and deterministic hashing for transient keys.
  - Added IP lockout checks to `attempt_activation()` before password verification.
  - Added combined lockout decision in `record_failed_attempt()`:
    - lock out when either user attempts or IP attempts reaches `MAX_FAILED_ATTEMPTS`.
  - Added additive IP argument to lockout audit hook dispatch:
    - `do_action( 'wp_sudo_lockout', $user_id, $attempts, $ip )`.
  - Added helper methods for IP attempt buckets and lockout remaining-time calculations.
  - Added safe transient wrappers used by unit-test context paths.

### Unit Tests

- **`tests/Unit/SudoSessionTest.php`**
  - Added IP lockout short-circuit coverage for `attempt_activation()`.
  - Added combined-policy coverage where IP threshold triggers lockout.
  - Updated lockout hook expectation to include additive third arg (IP) while preserving original first two args.

## Verification Results

- ✅ `composer test:unit && composer analyse:phpstan`
  - Passed (`496 tests`, `1293 assertions`; PHPStan 0 errors).
