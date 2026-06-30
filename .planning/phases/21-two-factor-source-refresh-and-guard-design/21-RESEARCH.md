# Phase 21 Research: Two Factor Source Refresh and Guard Design

**Researched:** 2026-06-30
**Phase:** 21 — Two Factor Source Refresh and Guard Design
**Status:** Ready for planning

## Research Question

What does Phase 21 need to establish before Phase 22 can safely implement a classic profile-provider lifecycle guard for upstream `WordPress/two-factor`?

## Inputs Read

- `.planning/PROJECT.md`
- `.planning/REQUIREMENTS.md`
- `.planning/ROADMAP.md`
- `.planning/STATE.md`
- `.planning/phases/21-two-factor-source-refresh-and-guard-design/21-CONTEXT.md`
- `bridges/wp-sudo-two-factor-lifecycle-bridge.php`
- `tests/Unit/TwoFactorLifecycleBridgeTest.php`
- `docs/two-factor-integration.md`
- `.planning/milestones/historical-phases/19-2fa-bridge-planning-and-compatibility-matrix/19-01-SUMMARY.md`

## Primary Source Probe

A lightweight primary-source probe was run during planning to confirm whether Phase 19 evidence was obviously stale. Execution must repeat this check and write a full source evidence artifact.

**Current upstream master checked:** `c515462d51ac92941685e39293673c08538e16c8`
**Checked date:** 2026-06-30
**Sources:**

- `https://api.github.com/repos/WordPress/two-factor/commits/master`
- `https://raw.githubusercontent.com/WordPress/two-factor/c515462d51ac92941685e39293673c08538e16c8/class-two-factor-core.php`
- `https://raw.githubusercontent.com/WordPress/two-factor/c515462d51ac92941685e39293673c08538e16c8/providers/class-two-factor-backup-codes.php`
- `https://raw.githubusercontent.com/WordPress/two-factor/c515462d51ac92941685e39293673c08538e16c8/providers/class-two-factor-totp.php`

Scoped findings from the planning probe:

- `Two_Factor_Core::REST_NAMESPACE` remains `two-factor/1.0`.
- Backup-code generation remains registered at `Two_Factor_Core::REST_NAMESPACE` plus `/generate-backup-codes` with `WP_REST_Server::CREATABLE`.
- TOTP lifecycle remains registered at `Two_Factor_Core::REST_NAMESPACE` plus `/totp` with `WP_REST_Server::CREATABLE` and `WP_REST_Server::DELETABLE`.
- Classic profile option rendering still emits nonce field `_nonce_user_two_factor_options` for action `user_two_factor_options`.
- Enabled providers still use user meta key `_two_factor_enabled_providers`; primary provider still uses `_two_factor_provider`.
- Profile saves still run through `personal_options_update` and `edit_user_profile_update` via `Two_Factor_Core::user_two_factor_options_update()`.
- TOTP still uses secret meta key `_two_factor_totp_key`.

No material drift was observed in the scoped contracts during this planning probe. Because the upstream commit differs from Phase 19’s checked commit, Phase 21 execution must still produce a complete evidence note and stop/re-scope if any implementation-relevant drift is found.

## Existing WP Sudo Seams

### Bridge registration

`bridges/wp-sudo-two-factor-lifecycle-bridge.php` registers bridge rules through `wp_sudo_gated_actions`. The existing REST bridge should remain opt-in and route-based unless live source evidence requires a deliberate update.

### Admin callback seam

`Gate::matches_admin_pagenow()` supports an optional `admin['callback']`. Existing built-in rules use callbacks to narrow broad profile saves, for example role changes and password changes. The future profile-provider guard should fit this rule/callback pattern instead of adding a broad profile-save gate.

### Test seam

`tests/Unit/TwoFactorLifecycleBridgeTest.php` already captures the procedural bridge’s `wp_sudo_gated_actions` callback and verifies REST rule shape. Phase 21 can extend or preserve this targeted test surface without needing browser automation.

## Planning Implications

- Plan Phase 21 as evidence/design work, not behavior implementation.
- Write a dedicated source evidence artifact such as `21-SOURCE-EVIDENCE.md`.
- Update stale bridge comments if they contradict v4.4.0 decisions, especially profile-guard and first-enrollment wording.
- Keep public docs mostly for Phase 23 unless a current source claim is wrong and should be corrected immediately.
- Run targeted bridge tests after comment/source-evidence updates; full standard checks are optional for Phase 21 unless implementation behavior changes.

## Validation Architecture

Phase 21 validation should prove evidence quality and planning readiness rather than runtime behavior.

Automated validation should cover:

1. Source evidence artifact exists and contains:
   - current upstream SHA;
   - checked date;
   - URLs for `class-two-factor-core.php`, backup-code provider, and TOTP provider;
   - REST namespace, route, method, field, hook, nonce/action, and meta-key findings;
   - explicit drift decision: proceed or stop/re-scope.
2. Guard design artifact or plan section exists and distinguishes:
   - unrelated profile saves;
   - no-op Two Factor provider submissions;
   - enabled-provider set changes;
   - primary-provider changes;
   - TOTP enrollment/removal/replacement;
   - first enrollment policy.
3. Existing REST bridge targeted tests pass:
   - `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/TwoFactorLifecycleBridgeTest.php`
4. Patchstack remains fixture-blocked and out of implementation scope.

## Recommended Plan Shape

One executable plan is enough:

1. Capture full upstream source evidence and stop on material drift.
2. Write the guard predicate design and reconcile stale bridge comments without implementing behavior.
3. Run targeted validation and produce the Phase 21 summary.

