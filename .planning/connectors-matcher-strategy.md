# Connectors Matcher Strategy

*Created April 18, 2026. Updated June 13, 2026 after WordPress 7.0 GA.*

## Context

WP Sudo ships a `connectors.update_credentials` rule that gates `POST /wp/v2/settings` when request params contain connector API key fields. The matcher uses a regex pattern:

```php
preg_match( '#^connectors_[a-z0-9_]+_api_key$#', $key )
```

This fires on setting names like `connectors_ai_openai_api_key`, `connectors_ai_anthropic_api_key`, etc.

WordPress 7.0 GA has shipped and WP Sudo now advertises compatibility with WordPress 7.0 in package metadata. This file has moved from a pre-GA wait plan to the current implementation plan for verifying GA parity and then upgrading the matcher.

## Current decision: verify GA parity, then prefer registry metadata

The regex matcher was correct while the Connectors API was pre-release. Now that WordPress 7.0 is GA, the next step is source verification against the released API before implementation. If the released API still exposes connector authentication setting names as expected, upgrade to a registry-aware matcher with the regex retained as a fallback.

**Why the regex was used pre-GA:**

1. **API stability.** Before GA, `wp_get_connectors()`, `WP_Connector_Registry`, and the `wp_connectors_init` action were not committed to a stable release and could have changed before shipment.

2. **No runtime dependency.** The regex fires on param shape alone. It works whether or not the Connectors feature is present in the WordPress build. This makes WP Sudo forward-compatible with 6.2–6.7 (where Connectors doesn't exist) and 7.0+ (where it does).

3. **Pattern is deterministic.** Core normalizes connector type and ID to underscores, generating setting names as `connectors_{type}_{id}_api_key`. The regex matches this pattern exactly. Custom connectors registered via `$registry->register()` also auto-generate setting names following this pattern (see `connectors-api-reference.md` line 239).

4. **False positive risk is negligible.** No existing WordPress core or plugin option uses the `connectors_*_api_key` namespace. The pattern is specific enough to avoid collateral gating.

**What was previously premature but is now the target path:**

Codex suggested preferring registry metadata — calling `wp_get_connectors()` to enumerate `authentication.setting_name` values, with the regex as a fallback. This is now the desired approach, but only after verifying the exact released API shape:

- Avoid coupling to names or structures that differ from the released source.
- Add `function_exists()` branching only with tests that exercise the available 7.0 API path and the fallback path.
- Avoid confabulation-driven bugs by citing the verified WordPress source in the implementation commit message.

## Post-GA upgrade path

Upgrade the matcher in this order:

### Step 1: Verify GA parity (already in ROADMAP.md)

Confirm the released API matches what's documented in `docs/connectors-api-reference.md`:

- `wp_get_connectors()` returns the expected data structure
- `authentication.setting_name` follows the `connectors_{type}_{id}_api_key` pattern
- The REST save path is still `POST /wp/v2/settings`
- Masking/validation behavior is unchanged

If anything diverges, update the reference doc first.

### Step 2: Implement registry-aware matcher

Replace `is_connector_api_key_setting_name()` with a two-tier check:

```php
private static function is_connector_api_key_setting_name( string $key ): bool {
    // Tier 1: Registry lookup (available on WP 7.0+).
    if ( function_exists( 'wp_get_connectors' ) ) {
        foreach ( wp_get_connectors() as $connector ) {
            if (
                isset( $connector['authentication']['setting_name'] )
                && $connector['authentication']['setting_name'] === $key
            ) {
                return true;
            }
        }
    }

    // Tier 2: Regex fallback (covers pre-7.0 or unregistered connectors).
    return 1 === preg_match( '#^connectors_[a-z0-9_]+_api_key$#', $key );
}
```

**Rationale for keeping the regex fallback:**

- Covers connectors registered after `wp_connectors_init` (timing edge cases)
- Covers sites running WP < 7.0 where the API doesn't exist
- Costs nothing — the regex is fast and the false positive surface is empty

**Cache scope (evaluated post-4.1.0):** the per-request
`$connector_setting_names_cache` is intentionally **not** keyed by blog, and that is
correct. The registry is an `init`-populated in-process singleton
(`WP_Connector_Registry`), blog-invariant within a request — `switch_to_blog()` does
not re-run `init` or connector registration, so `wp_get_connectors()` returns the same
`method`/`setting_name` mapping on every blog. A design review rejected per-blog keying
(adds complexity + unbounded growth under `switch_to_blog` loops; untestable, since core
never emits a per-blog-varying registry). The only Tier-1 residual is the
**late-registration** timing edge above, accepted and backstopped by the Tier-2 regex.
Caveat: this depends on the matcher running *after* `init` (it does — REST dispatch is
well after `init`); invoking it earlier would be the real staleness vector.

### Step 3: Add integration tests

Integration tests require a real WordPress environment with the Connectors API available:

- Test with a core connector (e.g., OpenAI) that the registry lookup matches
- Test with a custom connector registered via `$registry->register()` that the registry lookup matches
- Test that the regex fallback still fires when `wp_get_connectors` is absent (mock removal)
- Test a non-connector setting name is rejected by both tiers

These tests belong in `tests/Integration/` and can only be written after 7.0 GA.

### Step 4: Update documentation

- `docs/connectors-api-reference.md` — update the WP Sudo Gating Analysis section to document the two-tier matcher
- `docs/developer-reference.md` — note the matcher upgrade in the Connectors subsection
- `docs/ROADMAP.md` — mark the Connectors matcher upgrade as complete

## Phase 10 interaction

Phase 10 (Settings UI Revision) Task 5 adds help panel content mentioning Connectors. That content should:

- Use hedged language: "WordPress 7.0 Connectors credentials (when present)" or "AI provider API keys managed by the Connectors feature"
- Avoid implying Connectors is a stable, shipped feature
- Not reference `wp_get_connectors()` or registry internals in user-facing help text

The Gated Actions table already shows the `connectors.update_credentials` rule (Phase 10 Task 4 verified this). No language change needed there — the table shows rule IDs, not API stability claims.

## What this plan does NOT include

- **No dedicated Connectors setting or toggle.** WP Sudo's model is consequence-based rules + surface policy. Connectors credential gating is a rule, not a surface. Adding a per-connector toggle would break the clean two-layer model.
- **No unverified `function_exists()` branching.** GA has shipped, but implementation still starts with source verification.
- **No new Phase.** This is a contingent upgrade to an existing rule's matcher, not a feature development phase. It belongs in the ROADMAP under "WordPress 7.0 Prep."

## Key file references

| File | Lines | Content |
|------|-------|---------|
| `includes/class-action-registry.php` | 435–448 | `connectors.update_credentials` rule definition |
| `includes/class-action-registry.php` | 816–837 | `request_contains_connector_api_key()` and `is_connector_api_key_setting_name()` |
| `docs/connectors-api-reference.md` | 192–240 | Public API functions, data structure, custom registration |
| `docs/connectors-api-reference.md` | 244–281 | WP Sudo gating analysis |
| `docs/ROADMAP.md` | 28–32 | Immediate: Connectors GA parity check |
| `.planning/phases/10-settings-ui-revision/10-01-PLAN.md` | 222–257 | Task 5: Help panel Connectors mentions |
