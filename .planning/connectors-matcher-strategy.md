# Connectors Matcher Strategy

*Created April 18, 2026*

## Context

WP Sudo ships a `connectors.update_credentials` rule that gates `POST /wp/v2/settings` when request params contain connector API key fields. The matcher uses a regex pattern:

```php
preg_match( '#^connectors_[a-z0-9_]+_api_key$#', $key )
```

This fires on setting names like `connectors_ai_openai_api_key`, `connectors_ai_anthropic_api_key`, etc.

The WordPress 7.0 Connectors API was expected to ship April 9, 2026, but the release was [delayed](https://make.wordpress.org/core/2026/03/31/extending-the-7-0-cycle/) for real-time collaboration architecture work. No confirmed ship date. The API remains pre-release.

## Decision: Regex is correct for pre-release

The current regex matcher is the right approach while the Connectors API is pre-release.

**Why not use the registry now:**

1. **API stability.** `wp_get_connectors()`, `WP_Connector_Registry`, and the `wp_connectors_init` action are not committed to a stable release. Any of them could be renamed, restructured, or removed before GA.

2. **No runtime dependency.** The regex fires on param shape alone. It works whether or not the Connectors feature is present in the WordPress build. This makes WP Sudo forward-compatible with 6.2–6.7 (where Connectors doesn't exist) and 7.0+ (where it does).

3. **Pattern is deterministic.** Core normalizes connector type and ID to underscores, generating setting names as `connectors_{type}_{id}_api_key`. The regex matches this pattern exactly. Custom connectors registered via `$registry->register()` also auto-generate setting names following this pattern (see `connectors-api-reference.md` line 239).

4. **False positive risk is negligible.** No existing WordPress core or plugin option uses the `connectors_*_api_key` namespace. The pattern is specific enough to avoid collateral gating.

**What Codex recommended and why it's premature:**

Codex suggested preferring registry metadata — calling `wp_get_connectors()` to enumerate `authentication.setting_name` values, with the regex as a fallback. This is the correct *eventual* approach, but building it now means:

- Coupling to an API that may not ship in its current form
- Adding `function_exists()` branching that can't be integration-tested until 7.0 GA
- Risk of confabulation-driven bugs (the API surface is only known from pre-release trunk, not stable documentation)

## Post-GA upgrade path

When WordPress 7.0 GA ships with a stable Connectors API, upgrade the matcher in this order:

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
- **No pre-release `function_exists()` branching.** Not until GA ships.
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
