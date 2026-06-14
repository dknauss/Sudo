# Phase 3 - Request-Stash Minimization (F4 + F9)

- **Status:** Closed locally in `00e741a`
- **Findings:** F4, F9
- **Related release blockers closed in the same phase:** F14, F18d, F18e/P2 uninstall WP-CLI gap
- **Primary security goal:** Keep intercepted requests replayable only when replay is explicitly safe, and keep secrets out of the transient-backed request stash.

## Outcome

Phase 3 was implemented as a broader fix than the original draft described. The initial draft treated F9 as "drop unused `$_GET` from the stash" and deferred per-rule allowlists. The final implementation treats F9 as the structural problem: replay should not capture arbitrary request bodies by default.

The as-built behavior is:

- `Request_Stash` no longer stores standalone `$_GET`.
- `Challenge::build_replay_response_data()` no longer emits `get_data`.
- POST replay is allowlisted per gated action rule through `stash` metadata.
- Rules that should not replay POST bodies use `post_mode: none`.
- Custom or filtered rules with no stash policy fail closed for POST replay instead of capturing full `$_POST`.
- Unsafe replay attempts redirect with a `wp_sudo_replay_blocked=1` notice instead of silently submitting a reduced or unsafe request.
- Sensitive field redaction now covers exact keys and compound suffixes such as `connectors_openai_api_key`, `stripe_secret_key`, `smtpPassword`, and `oauthAccessToken`.

## Implemented Data Shape

`Request_Stash::save()` stores a bounded payload:

```php
array(
    'user_id'                => 123,
    'rule_id'                => 'plugin.activate',
    'label'                  => 'Activate plugin',
    'method'                 => 'POST',
    'url'                    => 'https://example.test/wp-admin/plugins.php?action=activate&plugin=...',
    'return_url'             => 'https://example.test/wp-admin/plugins.php',
    'post'                   => array(),
    'redacted_fields_omitted'  => array(),
    'post_replay_blocked'      => false,
    'post_replay_block_reason' => '',
    'created'                => 1780940000,
)
```

The full intercepted URL is still stored because WordPress admin actions commonly depend on query-string action parameters. Standalone `$_GET` is not stored because replay does not need a second copy of those values.

## Rule Stash Policy

Built-in gated actions now declare one of these policies:

- `post_fields`: explicit POST field allowlist for replayable form submissions.
- `post_mode: none`: do not replay POST body for this action.

This makes built-in replay behavior intentional and makes custom-rule replay fail-safe. A custom rule author must opt into replayable POST fields rather than receiving full `$_POST` capture by default.

## Redaction Policy

Redaction still begins with the existing exact-match sensitive key list. It now also redacts high-signal suffixes for compound field names:

- `_password`, `_pass`
- `_api_key`, `_secret_key`, `_secret`, `_private_key`
- `_access_token`, `_auth_token`, `_api_token`, `_api_secret`
- dashed equivalents such as `-api-key`
- camelCase/lowercase endings such as `password`, `apikey`, `secretkey`, `privatekey`, `accesstoken`, `authtoken`, `apitoken`, `apisecret`

Redacted fields are omitted from replay. If a redacted field was part of the allowlist, POST replay is blocked rather than replaying an incomplete privileged request.

## Files Changed

Primary Phase 3 files:

- `includes/class-request-stash.php`
- `includes/class-challenge.php`
- `includes/class-action-registry.php`
- `tests/Unit/RequestStashTest.php`
- `tests/Integration/RequestStashTest.php`
- `tests/Unit/ChallengeTest.php`

Related release-blocker files updated in the same phase:

- `includes/class-uninstall-guard.php`
- `uninstall.php`
- `includes/class-site-health.php`
- `tests/Unit/UninstallGuardTest.php`
- `tests/Unit/ActionRegistryTest.php`
- `tests/Unit/SiteHealthTest.php`

Documentation updated:

- `CHANGELOG.md`
- `docs/current-metrics.md`
- `docs/developer-reference.md`
- `docs/archive/execution-plan-v3.1-v3.3.md`
- `docs/release-status.md`
- `docs/security-model.md`

## Validation

Validation run before commit `00e741a`:

```bash
composer test:unit
composer test:integration
composer analyse
composer lint
composer verify:metrics
composer audit --locked --no-interaction
git diff --check
```

Results:

- Unit tests: passed.
- Integration tests: passed with the expected skipped groups.
- PHPStan level 6: passed.
- PHPCS: passed.
- Metrics verification: passed.
- Composer audit: no security vulnerability advisories found.
- Whitespace check: passed.

## Residual Work

These are outside Phase 3 and should remain on the roadmap:

- Cancelled challenges still rely on the 5-minute transient TTL for cleanup unless a follow-up explicitly deletes the stash on cancel.
- Nonce fields may still need to be stored when they are required for replay. That is expected, but future replay hardening should continue treating nonce storage as a narrow exception, not a general POST-capture pattern.
- Broader session-store architecture work remains a later phase item.
