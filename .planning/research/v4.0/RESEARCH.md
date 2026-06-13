# WP Sudo v4.0.0 — Research Findings

**Researched:** 2026-06-13
**Scope:** Targeted milestone research — Connectors GA parity verification, minimum-requirement floor recommendations, Connectors implementation brief

---

## 1. Connectors GA Parity Verification

All claims verified against `wordpress-develop` trunk (branch `trunk`) on 2026-06-13.
Source: `https://raw.githubusercontent.com/WordPress/wordpress-develop/trunk/src/wp-includes/connectors.php`
Source: `https://raw.githubusercontent.com/WordPress/wordpress-develop/trunk/src/wp-includes/default-filters.php`
Source: `https://raw.githubusercontent.com/WordPress/wordpress-develop/trunk/src/wp-includes/class-wp-connector-registry.php`

### 1.1 Does `wp_get_connectors()` exist in WP 7.0 core?

**VERIFIED — YES.**

`wp_get_connectors()` is defined in `wp-includes/connectors.php` (since 7.0.0). The function signature and return shape:

```php
function wp_get_connectors(): array
// Returns array<string, array{
//     name: non-empty-string,
//     description: string,
//     logo_url?: non-empty-string,
//     type: non-empty-string,
//     authentication: array{
//         method: 'api_key'|'none',
//         credentials_url?: non-empty-string,
//         setting_name?: non-empty-string,
//         constant_name?: non-empty-string,
//         env_var_name?: non-empty-string
//     },
//     plugin: array{
//         file?: non-empty-string,
//         is_active: callable(): bool,
//     }
// }>
```

The strategy doc's claimed shape `{ id => { authentication: { method, setting_name } } }` is **correct**: the return value is an associative array keyed by connector ID, with each value containing an `authentication` sub-array with `method` and `setting_name`.

**Correction on traversal:** The strategy doc's proposed implementation iterates `wp_get_connectors()` as a flat array. Because the return is `array<string, array>` keyed by connector ID, the loop must use `foreach ( wp_get_connectors() as $id => $connector )` or `foreach ( wp_get_connectors() as $connector )`. The `$connector['authentication']['setting_name']` access path is correct.

### 1.2 `register_connector()` / registry mechanism

**VERIFIED.**

The `WP_Connector_Registry::register()` method accepts a connector ID and args array. When `authentication.setting_name` is omitted for an `api_key` connector, the setting name is auto-generated as:

```php
str_replace( '-', '_', "connectors_{$connector['type']}_{$id}_api_key" )
```

The `wp_connectors_init` action fires (via `_wp_connectors_init()`) and passes a `WP_Connector_Registry` instance to registered callbacks. Plugins can call `$registry->register( 'my-service', [...] )` to set an arbitrary `authentication.setting_name`. This confirms the "arbitrary setting_name via register()" capability the strategy doc describes.

### 1.3 The `wordpress_api_key` claim — CRITICAL VERIFICATION

**VERIFIED — CONFIRMED AND MORE SIGNIFICANT THAN THE ROADMAP DOCUMENTS.**

The ROADMAP.md (lines ~344-350) claims core ships a default connector whose key is `wordpress_api_key`. **This is correct.**

Exact source (`wp-includes/connectors.php`, lines 223-239, verified 2026-06-13):

```php
$registry->register(
    'akismet',
    array(
        'name'           => __( 'Akismet Anti-spam' ),
        'description'    => __( 'Protect your site from spam.' ),
        'type'           => 'spam_filtering',
        'plugin'         => array(
            'file'      => 'akismet/akismet.php',
            'is_active' => static function () {
                return defined( 'AKISMET_VERSION' );
            },
        ),
        'authentication' => array(
            'method'          => 'api_key',
            'credentials_url' => 'https://akismet.com/get/',
            'setting_name'    => 'wordpress_api_key',
            'constant_name'   => 'WPCOM_API_KEY',
        ),
    )
);
```

`wordpress_api_key` is the setting name for the **Akismet** connector. Akismet is a non-AI connector registered unconditionally by `_wp_connectors_init()` — it is not conditional on `wp_supports_ai()`. The three AI connectors (anthropic, google, openai) are conditionally registered only when AI support is enabled; the Akismet connector is registered on every WP 7.0 install.

**Security consequence:** Any site running WP 7.0 with Akismet is at risk. A `POST /wp/v2/settings` request with `{ "wordpress_api_key": "attacker-key" }` is a registered connector secret write that the current regex `^connectors_[a-z0-9_]+_api_key$` does **NOT** match. The current matcher silently passes this write — it will not gate it.

The ROADMAP.md calls this "a false negative on exactly the credential-replacement threat the security model advertises." That is accurate. This is a real gap, not a theoretical one.

**Additional note on AI connectors:** The setting names for AI providers (`connectors_ai_anthropic_api_key`, `connectors_ai_openai_api_key`, `connectors_ai_google_api_key`) DO match the existing regex and ARE currently gated correctly. Only `wordpress_api_key` (and any custom connector with a non-`connectors_*_api_key` setting name) escapes the regex.

### 1.4 REST save path and masking/validation behavior

**VERIFIED — UNCHANGED FROM DOCUMENTATION.**

- Write path: `POST /wp/v2/settings` (also `PUT`, `PATCH`) — unchanged.
- Masking: `_wp_connectors_rest_settings_dispatch()` hooked to `rest_post_dispatch` at priority 10 — unchanged.
- PATCH caveat: connector validation only fires on POST/PUT, not PATCH — unchanged (documented in `docs/connectors-api-reference.md`).
- Capability: `manage_options` — unchanged.

No changes to the REST surface affect WP Sudo's gating logic.

### 1.5 Registry initialization timing — CRITICAL FOR IMPLEMENTATION

**VERIFIED — timing is favorable for gate evaluation.**

Hook sequence verified in `wp-includes/default-filters.php` (line 559) and `wp-includes/connectors.php`:

| Priority | Hook | Action |
|----------|------|--------|
| `init` @ 15 | `_wp_connectors_init()` fires | Registry populated; `wp_connectors_init` action fires; Akismet registered unconditionally; AI providers registered if `wp_supports_ai()` |
| `init` @ 20 | `_wp_register_default_connector_settings()` | REST settings registered for connector fields |

The REST gate evaluates at `rest_request_before_callbacks`, which fires during a REST request — well after `init` completes. Admin gate evaluates at `admin_init` — also well after `init`. **The registry is fully populated before either gate surface evaluates.**

However: connectors registered via `add_action( 'wp_connectors_init', ... )` by plugins fire during `init` @ 15 (the `do_action` call inside `_wp_connectors_init`). Any plugin that registers connectors after `init` (e.g., during a later hook) would have its connector absent from the registry at gate evaluation time. This is the "timing edge case" the strategy doc flags — the regex fallback catches this case correctly.

**Design implication:** Per-request caching of the `wp_get_connectors()` result is appropriate. The registry is frozen after `init` @ 15; calling `wp_get_connectors()` once per request and caching in a static variable avoids repeated traversal on multi-match requests.

### 1.6 GA Parity Verdict

**HOLD — proceed with the registry-aware matcher as sketched, with one correction.**

The released WP 7.0 API matches what `docs/connectors-api-reference.md` documents. The strategy doc's two-tier design is correct. The existing regex fallback remains necessary and should be kept.

The one precision correction: the `wordpress_api_key` gap is more urgent than the strategy doc implies. It is not merely a "potential false negative with custom connectors" — it is a guaranteed false negative on every WP 7.0 + Akismet install. Akismet has approximately 5 million active installs. This gap is present in the current `main` build. The registry-aware matcher is the fix.

---

## 2. Minimum-Requirement Floor Recommendations

### 2.1 PHP Floor

**Recommendation: PHP 8.2**

**Rationale:**

As of 2026-06-13 (Source: php.net/supported-versions.php):

| Version | Status | Security Support Until |
|---------|--------|------------------------|
| 8.0 | EOL (no support) | Ended Nov 26, 2023 |
| 8.1 | EOL (no support) | Ended Dec 31, 2024 |
| 8.2 | Security fixes only | Dec 31, 2026 |
| 8.3 | Security fixes only | Dec 31, 2027 |
| 8.4 | Active support | Dec 31, 2028 |
| 8.5 | Active support | Dec 31, 2029 |

**Why 8.2 and not 8.1 or 8.3:**

- The existing `composer.json` platform pin is already `8.1.99`. The declared floor (8.0) already lags the actual resolution floor (8.1). Moving the declared floor to 8.1 would be a paper change only — no shims would drop.
- PHP 8.1 is EOL as of December 31, 2024. Declaring 8.1 as a minimum floor means declaring support for a version with no security support. This is a security stance problem for a security plugin.
- PHP 8.2 introduces readonly properties and class constants in interfaces — both used for code clarity in typed contexts. More importantly, raising to 8.2 lets the `composer.json` platform pin move to `8.2.99`, which is honest about the actual resolution floor.
- PHP 8.3 would be defensible (WordPress.org recommends 8.3+) but would be more disruptive for managed hosts that may trail. The WordPress ecosystem data shows PHP 8.2 at ~25% adoption and 8.3 growing — managed hosts like WP Engine, Pressable, and Flywheel support 8.2 widely.
- PHP 8.2 has security support through Dec 31, 2026 — enough runway to ship and maintain the 4.0 series.

**What raising to PHP 8.2 unlocks:**

- `readonly` class properties (8.1 feature, but 8.2 extends with `readonly` classes) — minor syntax improvement for value objects.
- `true`, `false`, `null` as standalone types (8.2) — not currently used but available for stricter typing.
- Removal of deprecated `${}` string interpolation (8.2) — no current usage in codebase.
- The primary benefit is the `composer.json` platform pin can honestly move to `8.2.99`, and CI matrix can drop the `8.0` and `8.1` lanes if desired (though keeping 8.1 in the unit matrix until close to release is fine for backward detection).

**What this does NOT unlock:**

- `json_validate()` — requires 8.3. Not currently used and not needed (WordPress validates JSON through its own APIs).
- Named arguments in function calls are available from 8.0 — no change.
- Fibers (8.1) — not relevant to this codebase.

**Shims that become unconditional at PHP 8.2:**

Scanning the production codebase, there are no PHP-version-gated shims in `includes/` for PHP 8.0/8.1 features. The codebase already uses PHP 8.0+ syntax (match expressions, union types, named arguments, `never` return type). There are no `if (PHP_VERSION_ID >= ...)` or `version_compare(PHP_VERSION, ...)` guards in production PHP files.

The platform pin move is the main concrete change. Files affected:
- `composer.json` line 38: `"php": "8.1.99"` → `"php": "8.2.99"`
- `composer.json` line 6: `"php": ">=8.0"` → `"php": ">=8.2"`
- `wp-sudo.php` line 8: `Requires PHP: 8.0` → `Requires PHP: 8.2`
- `readme.txt` line 14 (Requires PHP): update to `8.2`

### 2.2 WordPress Floor

**Recommendation: WordPress 6.4**

**Rationale:**

Current floor: WordPress 6.2. This floor carries a concrete compat shim:

**The `wp_get_admin_notice()` guard (verified 2026-06-13):**

`includes/class-admin.php` lines 2442 and 2475 contain:

```php
if ( function_exists( 'wp_get_admin_notice' ) ) {
    $notice_html = wp_get_admin_notice( ... );
    // ...
    return;
}
echo '<div class="notice notice-warning wp-sudo-notice"><p>' . esc_html( $message ) . '</p></div>';
```

`wp_get_admin_notice()` was introduced in WordPress 6.4.0 (verified against developer.wordpress.org). This is a two-branch fallback covering WP 6.2 and 6.3 with a manual notice HTML string. Raising the floor to 6.4 drops both manual-notice fallback branches and makes `wp_get_admin_notice()` unconditional.

**Files and lines affected by raising to WP 6.4:**

| File | Lines | Change |
|------|-------|--------|
| `includes/class-admin.php` | 2442, 2452–2454, 2475, 2487 | Remove `function_exists` guard and manual-HTML fallback at both call sites |
| `wp-sudo.php` | line 7 (`Requires at least`) | `6.2` → `6.4` |
| `readme.txt` | `Requires at least` line | `6.2` → `6.4` |

**Why 6.4 and not 6.5 or 6.6:**

- No additional compat guards or shims were found in the production codebase that would drop at WP 6.5 or 6.6.
- The CI matrix already includes a "6.2 support-floor lane" — that lane can be replaced with a "6.4 support-floor lane" without disrupting the overall matrix shape.
- WP 6.4 was released November 2023. As of June 2026, raising to 6.4 removes a version that is more than 2.5 years old from the supported range. This is a reasonable cut for a major version.
- WP 6.5+ would not drop any additional guards but would reduce the floor runway further. Save 6.6 as a candidate for a subsequent major.

**What raising to WP 6.5 or 6.6 would unlock:**

No additional code simplifications were identified for either version. The `wp_get_admin_notice()` shim is the only concrete guard tied to a WP version floor. A 6.5 or 6.6 floor would be defensible for other ecosystem reasons (e.g., matching what managed hosts guarantee) but does not reduce code complexity further.

**WordPress's own requirements (for context):**

WordPress 7.0 raised its own minimum PHP floor to 7.4. WordPress.org recommends PHP 8.3+ as of May 2026 (make.wordpress.org/core/2026/05/22/php-support-clarification-2026/). WordPress itself still runs on MySQL 5.5.5+ and PHP 7.4+ but discourages it.

### 2.3 Scope of "Raise Minimums + Shim Cleanup" Phase

This work is small enough to bundle with the deprecation-removal phase rather than being its own phase:

1. Update `wp-sudo.php` headers (`Requires at least: 6.4`, `Requires PHP: 8.2`)
2. Update `readme.txt` (`Requires at least`, `Requires PHP`)
3. Update `composer.json` (`require.php`, `config.platform.php`)
4. In `class-admin.php`: remove both `function_exists('wp_get_admin_notice')` branches and their manual-HTML fallbacks (two call sites, ~12 lines total)
5. Update CI matrix: rename "6.2 support-floor" lane to "6.4 support-floor"

Effort: ~30 minutes of code change plus a test pass to confirm the manual-notice fallback branches are dead code removal, not logic change.

---

## 3. Connectors Implementation Brief (Implementer-Facing)

### 3.1 What changes and where

**Target file:** `includes/class-action-registry.php`

**Target methods:**

| Method | Current lines | Change |
|--------|--------------|--------|
| `is_connector_api_key_setting_name( string $key ): bool` | 1045–1047 | Replace with two-tier check |
| `request_contains_connector_api_key( array $params ): bool` | 1026–1034 | No change to outer loop — change only propagates from `is_connector_api_key_setting_name` |

The rule definition at lines 481–494 (`connectors.update_credentials`) requires no changes. The callback invocation at line 491 (`self::request_contains_connector_api_key( $request->get_params() )`) is correct.

**Proposed implementation of `is_connector_api_key_setting_name()`:**

```php
private static function is_connector_api_key_setting_name( string $key ): bool {
    // Tier 1: Registry lookup (WP 7.0+).
    // Cache the setting-name set per request to avoid repeated traversal.
    static $registry_names = null;

    if ( null === $registry_names && function_exists( 'wp_get_connectors' ) ) {
        $registry_names = array();
        foreach ( wp_get_connectors() as $connector ) {
            if (
                isset( $connector['authentication']['method'], $connector['authentication']['setting_name'] )
                && 'api_key' === $connector['authentication']['method']
            ) {
                $registry_names[] = $connector['authentication']['setting_name'];
            }
        }
    }

    if ( is_array( $registry_names ) && in_array( $key, $registry_names, true ) ) {
        return true;
    }

    // Tier 2: Regex fallback — covers pre-7.0 sites, unregistered/late-registered
    // connectors, and timing edge cases where the registry is absent or empty.
    return 1 === preg_match( '#^connectors_[a-z0-9_]+_api_key$#', $key );
}
```

**Design notes:**
- Filter by `authentication.method === 'api_key'` before collecting `setting_name` to exclude connectors with `method === 'none'`.
- `setting_name` is optional in the PHPStan type (`setting_name?: non-empty-string`) — use `isset()` guard.
- `static $registry_names` resets at process start; safe for standard PHP-FPM request lifecycle. Integration tests that need to test different registry states must call `reset_cache()` or use a separate process.
- The static accumulates once per PHP process. This is correct: the registry is frozen after `init` @ 15.

### 3.2 Design-review questions that remain open

These are the questions the ROADMAP.md (lines 365–369) flags as requiring design review before implementation. They are not answered here — they need a design-review session before the TDD cycle begins:

**Registry population timing:** The registry is populated at `init` @ 15 via `_wp_connectors_init()`. Gate evaluation (REST `permission_callback`, `admin_init`) fires after `init` completes. This timing is safe. The edge case is connectors registered *outside* `wp_connectors_init` (incorrect usage) — these would be absent. The regex fallback catches standard-pattern names in that edge case. No action needed, but the implementation commit message should cite the timing verification.

**Per-request caching with `static $registry_names`:** The static approach is correct for standard request lifecycle. The risk is unit tests: Brain\Monkey mocks `wp_get_connectors()` per test, but the static cache survives across tests in the same process. The test suite must either (a) use `reset_cache()` to clear the static (requires exposing a reset path), or (b) test `is_connector_api_key_setting_name()` via a public path that does not cache (integration tests with real WP). Recommended: add `self::$connector_setting_names_cache = null;` to the existing `reset_cache()` method. This is a test infrastructure concern, not a production concern.

**Multisite behavior:** The `wp_get_connectors()` registry is process-scoped and not network-aware. Connector registration is global per-site (per WP install). In a multisite network, each site's request loads its own PHP process; the registry is populated identically on each site via `_wp_connectors_init()`. The `wordpress_api_key` option itself is per-site (standard `wp_options` row). No multisite special handling needed in the matcher.

**`setting_name` optionality:** The PHPStan type marks `setting_name` as optional. In practice, `WP_Connector_Registry::register()` auto-generates `setting_name` for `api_key` connectors when omitted — so every `api_key` connector in the registry should have a `setting_name`. The `isset()` guard is still correct defensive coding.

### 3.3 Integration test cases required

These belong in `tests/Integration/` and require WP 7.0 with Connectors API available.

| Test ID | Scenario | Expected result |
|---------|----------|-----------------|
| CONN-01 | `POST /wp/v2/settings` with `{ "wordpress_api_key": "..." }` on WP 7.0 + Akismet connector present in registry | `is_connector_api_key_setting_name()` returns `true` via Tier 1; request is gated |
| CONN-02 | Custom connector registered with non-`connectors_*` `setting_name` (e.g., `my_service_token`) | Tier 1 matches via registry; request is gated |
| CONN-03 | Standard AI connector setting name (e.g., `connectors_ai_openai_api_key`) | Both Tier 1 (registry) and Tier 2 (regex) would match; Tier 1 short-circuits correctly |
| CONN-04 | `wp_get_connectors` function absent (mock removal or pre-7.0 site) | Static cache remains `null`; Tier 2 regex runs; standard names gated, `wordpress_api_key` not gated (accepted regression on pre-7.0) |
| CONN-05 | Benign non-connector setting name (e.g., `blogname`, `siteurl`) | Neither tier matches; not gated |
| CONN-06 | `setting_name` absent for a `none`-method connector | `isset()` guard skips it; no incorrect match |

**Note on CONN-04:** On pre-7.0 sites, `wordpress_api_key` is not a connector setting name (Connectors API doesn't exist), so the regex correctly not matching it is correct behavior, not a gap.

### 3.4 Documentation updates

Per `.planning/connectors-matcher-strategy.md` Step 4:

| Document | Update |
|----------|--------|
| `docs/connectors-api-reference.md` | Add a "Two-Tier Matcher" subsection under "WP Sudo Gating Analysis" describing the registry-first approach and the `wordpress_api_key` gap closure |
| `docs/developer-reference.md` | Note the matcher upgrade in the Connectors subsection |
| `docs/ROADMAP.md` | Mark the Connectors matcher upgrade as complete in the WP 7.0 Prep section |
| `docs/connectors-api-reference.md` | Consider splitting into leaner core reference + security-analysis companion (per ROADMAP.md line 75 — this is a separate task, not part of the matcher upgrade) |

### 3.5 Deprecation removals — confirmed scope

Both removal targets are confirmed via direct source read:

**`sudo_can()` alias** (`includes/functions-governance.php` lines 90–112):
- `@deprecated 3.3.0` at line 100
- `function_exists('sudo_can')` guard at line 90 (this is a legitimate collision-avoidance guard, NOT a test shim — keep this pattern when rewriting)
- Removal: delete the entire `if ( ! function_exists( 'sudo_can' ) ) { ... }` block (lines 90–112)
- Verify no internal callers: `grep -rn 'sudo_can(' includes/ wp-sudo.php` — should return zero results before the 4.0.0 commit

**`compatibility` governance mode** (`includes/functions-governance.php` lines 82–85 and 168–169):
- Line 82–85 in `wp_sudo_can()`: the `if ( 'compatibility' === get_option(...) )` branch
- Line 168–169 in `wp_sudo_map_governance_meta_cap()`: the parallel branch
- Removal: delete both `if ( 'compatibility' === get_option( 'wp_sudo_governance_mode', 'strict' ) )` blocks
- The `_doing_it_wrong()` deprecation notice is bundled into the same 4.0.0 release (CLAUDE.md confirms: "no interim 3.5.0")
- `uninstall.php` already deletes the `wp_sudo_governance_mode` option — confirm this is still in uninstall.php before the 4.0.0 commit

**No other `@deprecated` symbols exist in production code.** Confirmed by grepping the codebase at the time of this research.

---

## 4. Open Questions Not Resolved Here

1. **`_doing_it_wrong()` deprecation notice for compatibility mode:** The 4.0.0 plan bundles the deprecation notice AND the removal. The question of where and when to fire `_doing_it_wrong()` for compatibility mode (should it fire on every request where the option is set, or only in admin contexts?) is a design-review question, not a research question. The design reviewer should weigh audit/notice fatigue against visibility.

2. **WP 6.4 floor and CI matrix:** The "6.2 support-floor" integration CI lane should become the "6.4 support-floor" lane. Verify that the GitHub Actions matrix definition in `.github/workflows/` (not read for this research) references the floor version explicitly and needs updating.

3. **README/readme screenshot refresh:** Scoped to v4.0.0 but not a blocker for the code changes. Can be the final task before tagging.

4. **`register_connector()` as a public API:** `WP_Connector_Registry::register()` is a method on a class instance passed via the `wp_connectors_init` action. There is no standalone `register_connector()` function. The strategy doc does not claim there is — but clarify this in the implementation commit message to avoid confusion.
