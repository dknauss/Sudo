# Phase 22 Profile Provider Guard Design Brief

## Threat

A hijacked authenticated browser session must not be able to enroll, remove, or switch an upstream WordPress.org Two Factor provider in a way that can create, replace, or weaken a future WP Sudo factor without first satisfying WP Sudo reauthentication.

## Approach

The change belongs in the optional `bridges/wp-sudo-two-factor-lifecycle-bridge.php` file, not core WP Sudo, and should add one `wp_sudo_gated_actions` admin rule for `profile.php` / `user-edit.php` `POST` saves with `action=update`.
The admin rule should use the existing WP Sudo admin matcher, stash, challenge, and replay flow, with a callback predicate that verifies the Two Factor nonce contract, resolves the target user, normalizes submitted/current provider state with `Two_Factor_Core::get_supported_providers_for_user( $user_id )`, and gates only meaningful lifecycle differences.
The guard must block enabled-provider additions/removals, primary-provider changes after normalization, and TOTP-backed first enrollment, removal, or replacement when source-verified fields prove the change can create or alter a future sudo factor.

## Must NOT block

The guard must not block unrelated classic profile saves, saves without the upstream Two Factor nonce and lifecycle fields, malformed non-array enabled-provider submissions that upstream would no-op, or normalized no-op provider resubmissions that differ only by ordering, dummy empty values, or unknown/unavailable providers.
Existing REST bridge behavior for backup-code generation and TOTP create/delete must remain route/method compatible with Phase 21 evidence, and this phase must not add Patchstack runtime support, product release metadata, browser UI, or public documentation expansion.

## Reviewer

Design reviewer critique was incorporated before tests or production code were edited.
The predicate should fail open for missing/invalid nonce values, missing/non-positive/non-scalar `user_id` on `user-edit.php`, and requests the upstream Two Factor save routine would not be permitted to write when a callable upstream capability helper is available.
Normalization must unslash and sanitize scalar provider keys, ignore nested/non-scalar values and duplicates, drop unsupported current and submitted providers before comparison, and treat `profile.php` as targeting the current user even if a `user_id` is posted.
Primary-provider deletion by empty or unavailable submitted primary is a meaningful lifecycle change when the normalized current primary exists, but classic-profile TOTP replacement must not depend on invented secret POST fields; TOTP-sensitive cases are limited to verified provider enable/disable state combined with existing `_two_factor_totp_key` state.
The replay allowlist must preserve the source-verified Two Factor fields without expanding scope to unverified public docs, browser UI, Patchstack runtime support, or release metadata.
