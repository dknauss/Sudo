# Phase 21 Source Evidence — WordPress/two-factor Lifecycle Guard

**Checked:** 2026-06-30
**Upstream repository:** `WordPress/two-factor`
**Upstream commit:** `c515462d51ac92941685e39293673c08538e16c8`
**Evidence purpose:** Verify source contracts before Phase 22 implements a WP Sudo guard for upstream Two Factor profile-provider lifecycle changes.

## Sources

- Commit API: `https://api.github.com/repos/WordPress/two-factor/commits/master`
- Core source: `https://raw.githubusercontent.com/WordPress/two-factor/c515462d51ac92941685e39293673c08538e16c8/class-two-factor-core.php`
- Backup Codes provider: `https://raw.githubusercontent.com/WordPress/two-factor/c515462d51ac92941685e39293673c08538e16c8/providers/class-two-factor-backup-codes.php`
- TOTP provider: `https://raw.githubusercontent.com/WordPress/two-factor/c515462d51ac92941685e39293673c08538e16c8/providers/class-two-factor-totp.php`

## Decision: proceed

No material drift was found from Phase 19's scoped assumptions for the contracts this milestone depends on. The upstream commit changed from Phase 19's checked commit, but the REST namespace/routes, classic profile hooks, Two Factor form fields, nonce/action names, and relevant meta keys remain compatible with the existing REST bridge and the planned profile-provider guard design.

If a later Phase 22 source refresh finds drift in these contracts, implementation should stop and re-scope before changing behavior.

## REST lifecycle contracts

### REST namespace

`Two_Factor_Core::REST_NAMESPACE` is `two-factor/1.0` in `class-two-factor-core.php`.

### Recovery-code generation

`providers/class-two-factor-backup-codes.php` registers a REST route under `Two_Factor_Core::REST_NAMESPACE` plus `/generate-backup-codes`.

- Effective route: `POST /two-factor/1.0/generate-backup-codes`
- Method: `WP_REST_Server::CREATABLE`
- Permission callback checks `Two_Factor_Core::rest_api_can_edit_user_and_update_two_factor_options( $request['user_id'] )`
- `user_id` is a REST request argument, not a route path segment
- The provider's localized admin `restPath` is `Two_Factor_Core::REST_NAMESPACE . '/generate-backup-codes'`

### TOTP setup/delete

`providers/class-two-factor-totp.php` registers a REST route under `Two_Factor_Core::REST_NAMESPACE` plus `/totp`.

- Effective route: `/two-factor/1.0/totp`
- Delete method: `WP_REST_Server::DELETABLE`
- Setup method: `WP_REST_Server::CREATABLE`
- Both permission callbacks check `Two_Factor_Core::rest_api_can_edit_user_and_update_two_factor_options( $request['user_id'] )`
- `user_id` is a REST request argument, not a route path segment
- The provider's localized admin `restPath` is `Two_Factor_Core::REST_NAMESPACE . '/totp'`

### REST bridge implication

The existing WP Sudo bridge rules remain correctly scoped for the verified REST contracts:

- `two_factor.backup_codes_generate`: route `#^/two-factor/1\.0/generate-backup-codes$#`, method `POST`
- `two_factor.totp_manage`: route `#^/two-factor/1\.0/totp$#`, methods `POST`, `DELETE`

No REST rule behavior change is indicated by this source refresh.

## Classic profile lifecycle contracts

### Hooks

`class-two-factor-core.php` registers the profile UI and save handlers through these hooks:

- `show_user_profile` → `Two_Factor_Core::user_two_factor_options()`
- `edit_user_profile` → `Two_Factor_Core::user_two_factor_options()`
- `personal_options_update` → `Two_Factor_Core::user_two_factor_options_update()`
- `edit_user_profile_update` → `Two_Factor_Core::user_two_factor_options_update()`

### Nonce and form fields

The classic profile UI renders:

- Nonce field name: `_nonce_user_two_factor_options`
- Nonce action: `user_two_factor_options`
- Enabled providers field: `_two_factor_enabled_providers[]`
- Dummy hidden enabled-providers value: empty `_two_factor_enabled_providers[]`, so the POST field is present when no providers are enabled
- Primary provider field: `_two_factor_provider`

### Relevant meta keys

`class-two-factor-core.php` defines:

- Primary provider user meta: `_two_factor_provider`
- Enabled providers user meta: `_two_factor_enabled_providers`

`providers/class-two-factor-totp.php` defines:

- TOTP secret user meta: `_two_factor_totp_key`
- TOTP last-successful-login meta: `_two_factor_totp_last_successful_login`

The future WP Sudo profile guard only needs `_two_factor_totp_key` as a TOTP-backed enrollment/removal signal. It does not need to inspect or alter `_two_factor_totp_last_successful_login`.

## Provider normalization and update behavior

`Two_Factor_Core::user_two_factor_options_update( $user_id )` performs the classic profile save.

Observed behavior:

1. The update routine runs only when `_nonce_user_two_factor_options` is present, then verifies action `user_two_factor_options`.
2. The routine returns if `_two_factor_enabled_providers` is absent or not an array.
3. The routine checks `current_user_can_update_two_factor_options( 'save' )` before writing settings.
4. It fetches supported providers for the target user with `get_supported_providers_for_user( $user_id )`.
5. It reads submitted enabled providers from `$_POST['_two_factor_enabled_providers']`.
6. It normalizes submitted enabled providers with `array_intersect_key( $providers, array_flip( $enabled_providers ) )`.
7. Providers that are not available/configured for the target user are unset before writing.
8. It writes the normalized provider keys to `_two_factor_enabled_providers`.
9. It writes `_two_factor_provider` only when the submitted primary provider is non-empty and remains enabled; otherwise it deletes `_two_factor_provider`.
10. On first enablement or provider removal, it may update current session metadata or destroy other sessions.

## Profile provider guard design

The future Phase 22 guard should use the existing WP Sudo admin-rule callback seam. It should not implement custom blocking outside normal WP Sudo request matching unless Phase 22 design review finds the callback seam insufficient.

### Request preconditions

Evaluate only classic profile saves:

- `profile.php` or `user-edit.php`
- `action=update`
- `POST`
- Two Factor nonce/action contract present: `_nonce_user_two_factor_options` for action `user_two_factor_options`
- At least one relevant Two Factor lifecycle field present, especially `_two_factor_enabled_providers` or `_two_factor_provider`

Unrelated profile saves with no Two Factor lifecycle contract must not be gated.

### Target user resolution

The guard should cover both self-profile and administrator-edited profile saves:

- `profile.php`: current user is the target user.
- `user-edit.php`: posted/requested `user_id` is the target user, subject to the same WordPress profile-edit context that will later run Two Factor's update hook.

### Normalization before comparison

Normalize submitted enabled-provider values before comparing:

- Ignore the dummy empty provider value.
- Ignore unsupported/unknown provider keys after upstream-style normalization.
- Intersect submitted keys with the upstream supported-provider map.
- Treat provider ordering as irrelevant.
- For provider availability, follow the same source finding that upstream unsets unsupported/unavailable providers before writing. Phase 22 should decide the safest test seam for this without duplicating all provider internals.

### Meaningful lifecycle changes to gate

Gate only meaningful lifecycle changes:

- Enabled-provider additions.
- Enabled-provider removals.
- Primary-provider changes.
- TOTP-backed state removal or replacement.
- First TOTP-backed enrollment when it creates a future sudo factor.

Do not gate:

- Unrelated profile saves.
- Profile saves with no Two Factor nonce/action and lifecycle fields.
- Normalized no-op Two Factor provider submissions.

### First enrollment policy

First TOTP-backed enrollment is intentionally a gated lifecycle change for v4.4.0 because it creates a future factor that can satisfy later WP Sudo challenges. This updates the older bridge-comment posture that treated first enrollment as intentionally not blocked by the REST-only v1 bridge. Phase 21 still does not implement the guard; it only records the policy for Phase 22.

## Existing REST bridge preservation

The existing bridge's REST rules remain current against upstream commit `c515462d51ac92941685e39293673c08538e16c8`.

Targeted validation remains:

```bash
./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/TwoFactorLifecycleBridgeTest.php
```

## Patchstack boundary

Patchstack Security remains fixture-blocked and out of implementation scope for Phase 21. No Patchstack bridge code or runtime compatibility claim should be added until a paid Patchstack-enabled runtime fixture exists and manual challenge/lifecycle behavior is recorded.

## No release action

This source refresh is a GSD planning/design phase. It does not create a product version bump, release candidate, WordPress.org submission, or product git tag.
