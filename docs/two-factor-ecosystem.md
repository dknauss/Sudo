# Two-Factor Plugin Ecosystem: Integration Guide for Plugin Developers

This document is for **developers of 2FA plugins** who want their plugin to work with WP Sudo's reauthentication challenge. It surveys the WordPress 2FA landscape and provides concrete guidance for building a bridge.

For the general architecture overview and hook reference, see [two-factor-integration.md](two-factor-integration.md).

## Table of Contents

- [How WP Sudo Handles 2FA](#how-wp-sudo-handles-2fa)
- [What Your Plugin Needs to Provide](#what-your-plugin-needs-to-provide)
- [The Three Required Hooks](#the-three-required-hooks)
- [Ecosystem Survey: How Major Plugins Store and Validate 2FA](#ecosystem-survey)
- [Working Bridge Example: WP 2FA by Melapress](#working-bridge-example-wp-2fa-by-melapress)
- [Bridge Patterns for Other Plugins](#bridge-patterns-for-other-plugins)
- [Constraints and Unsupported Patterns](#constraints-and-unsupported-patterns)
- [Testing Your Bridge](#testing-your-bridge)

---

## How WP Sudo Handles 2FA

WP Sudo has a two-step reauthentication challenge:

1. **Password step** — the user enters their WordPress password.
2. **2FA step** — if a 2FA plugin signals the user has 2FA configured, a second form collects and validates an authentication code.

WP Sudo does **not** implement any 2FA method itself. It delegates entirely:
- **Detection** — "Does this user need 2FA?" is answered by plugins via a filter.
- **Rendering** — "What form fields should the user see?" is answered by plugins via an action.
- **Validation** — "Is this code correct?" is answered by plugins via a filter.

The [Two Factor](https://wordpress.org/plugins/two-factor/) plugin by WordPress contributors is supported automatically. Every other 2FA plugin requires a small bridge — typically 30–50 lines of PHP.

---

## What Your Plugin Needs to Provide

A bridge between your 2FA plugin and WP Sudo needs three things:

| Capability | WP Sudo Hook | What You Provide |
|------------|-------------|------------------|
| **Detection** | `wp_sudo_requires_two_factor` | A boolean: does this user have 2FA configured? |
| **Form rendering** | `wp_sudo_render_two_factor_fields` | HTML form fields (typically a 6-digit code input) |
| **Validation** | `wp_sudo_validate_two_factor` | A boolean: is the submitted code correct? |

That's it. WP Sudo handles everything else: the challenge page layout, the AJAX transport, browser binding, session timing, countdown UI, and request replay.

---

## The Three Required Hooks

### 1. `wp_sudo_requires_two_factor` (filter)

```php
add_filter( 'wp_sudo_requires_two_factor', function ( bool $needs, int $user_id ): bool {
    // Return true if $user_id has 2FA configured in your plugin.
    // Return $needs unchanged if your plugin doesn't manage this user.
    return $needs;
}, 10, 2 );
```

**Called when:** The user has just entered a correct password. WP Sudo needs to decide whether to show the 2FA step or activate the session immediately.

**Important:** If `$needs` is already `true` (another plugin claimed the user), you should generally return `true` — don't override another plugin's detection.

### 2. `wp_sudo_render_two_factor_fields` (action)

```php
add_action( 'wp_sudo_render_two_factor_fields', function ( \WP_User $user ): void {
    // Output HTML form fields. They will be inside WP Sudo's <form>.
    ?>
    <p>
        <label for="my-2fa-code"><?php esc_html_e( 'Authentication code:', 'my-plugin' ); ?></label>
        <input type="text" id="my-2fa-code" name="my_2fa_code"
               autocomplete="one-time-code" inputmode="numeric"
               pattern="[0-9]*" required />
    </p>
    <?php
} );
```

**Called when:** The challenge page HTML is being rendered. Your fields appear inside `#wp-sudo-challenge-2fa-form`.

**Rules:**
- **No submit button.** WP Sudo provides "Confirm & Continue."
- **No `action` or `_wpnonce` hidden fields.** WP Sudo's JavaScript strips them and adds its own.
- **No wrapping `<form>` tag.** Your fields are already inside one.
- **Use a unique `name` attribute** so your validation callback can read it from `$_POST`.

### 3. `wp_sudo_validate_two_factor` (filter)

```php
add_filter( 'wp_sudo_validate_two_factor', function ( bool $valid, \WP_User $user ): bool {
    // If already validated by another plugin, don't override.
    if ( $valid ) {
        return true;
    }

    // Read your field from $_POST and validate.
    $code = isset( $_POST['my_2fa_code'] )
        ? sanitize_text_field( wp_unslash( $_POST['my_2fa_code'] ) )
        : '';

    return my_plugin_verify_code( $user->ID, $code );
}, 10, 2 );
```

**Called when:** The user has submitted the 2FA form via AJAX. WP Sudo has already verified the nonce and the browser-bound pending state.

**Important:** WP Sudo does **not** call `check_ajax_referer()` on your behalf for your fields — it already did that for the overall request. You do not need to verify a nonce. Just read `$_POST` and validate.

### Optional: `wp_sudo_two_factor_window` (filter)

```php
add_filter( 'wp_sudo_two_factor_window', function ( int $window ): int {
    return 15 * MINUTE_IN_SECONDS; // Give the user 15 minutes.
} );
```

Adjusts how long (in seconds) the user has to complete the 2FA step after entering their password. Default is 300 (5 minutes). Increase this if your method involves waiting for an email or push notification.

---

## Ecosystem Survey

Here is how the major WordPress 2FA plugins store and validate credentials, and what a bridge needs to call.

### Two Factor (WordPress/two-factor)

**Status:** Built-in. No bridge needed.

| Aspect | Detail |
|--------|--------|
| Detection | `Two_Factor_Core::is_user_using_two_factor( $user_id )` |
| Validation | `$provider->validate_authentication( $user )` |
| Storage | `_two_factor_totp_key` user meta |
| Architecture | Provider-based API, fully extensible |

### WP 2FA (Melapress)

**Status:** Bridgeable. Working example below.

| Aspect | Detail |
|--------|--------|
| Detection | `\WP2FA\Admin\Helpers\User_Helper::is_user_using_two_factor( $user_id )` |
| Method check | `\WP2FA\Admin\Helpers\User_Helper::get_enabled_method_for_user( $user_id )` → `'totp'`, `'email'`, etc. |
| TOTP validation | `\WP2FA\Authenticator\Authentication::is_valid_authcode( $key, $code )` |
| TOTP secret | `\WP2FA\Methods\TOTP::get_totp_key( $user_id )` (returns encrypted key, which `is_valid_authcode` expects) |
| Email validation | `\WP2FA\Authenticator\Authentication::validate_token( $user, $code )` |
| Backup codes | `\WP2FA\Methods\Backup_Codes::validate_code( $user, $code )` |
| Storage | `wp_2fa_totp_key` user meta (AES-256-CTR encrypted) |


### Patchstack Security

**Status:** Bridgeable compatibility target, but likely requires a paid Patchstack
setup for end-to-end testing because the login/2FA hooks are disabled when the
plugin is in free-license mode.

Verified against WordPress.org SVN trunk revision 3589135 on 2026-06-28:

| Aspect | Detail |
|--------|--------|
| Feature flag | Plugin option `patchstack_login_2fa` enables the 2FA hooks. |
| Free-license behavior | `P_Login::__construct()` returns early when `patchstack_license_free` is `1`, before registering 2FA hooks. |
| Detection | `get_user_option( 'webarx_2fa_enabled', $user_id )` |
| Login field | `patchstack_2fa` |
| Validation | `TokenAuth6238::verify( $secret, $code )` after `P_Login::tfa_get_secret( $user )` decrypts/generates the secret. |
| Storage | `webarx_2fa_secretkey` plus `webarx_2fa_secretkey_nonce` user options. |
| Profile lifecycle | `patchstack_2fa_enabled` is saved on `personal_options_update` / `edit_user_profile_update`; WooCommerce account forms have parallel handlers. |
| Source | [`includes/login.php`](https://plugins.svn.wordpress.org/patchstack/trunk/includes/login.php) |

Compatibility-test implications:

- Patchstack will not be covered by WP Sudo's built-in `WordPress/two-factor`
  integration because it uses its own TOTP implementation and user-option keys.
- It appears practical to build a bridge because validation is local TOTP rather
  than a hosted/cloud-only check.
- Treat it as a second-tier 2FA compatibility target after the upstream
  `WordPress/two-factor` lifecycle bridge, unless user demand makes Patchstack
  compatibility urgent.

### Wordfence Login Security

**Status:** Bridgeable with direct class calls.

| Aspect | Detail |
|--------|--------|
| Detection | `\WordfenceLS\Controller_Users::shared()->has_2fa_active( $user )` |
| Validation | `\WordfenceLS\Controller_TOTP::shared()->validate_2fa( $user, $code )` |
| Storage | Custom database table (not user meta) |
| Notes | No public hooks. Integration requires calling singleton methods. |

### Solid Security (formerly iThemes Security)

**Status:** May work automatically — bundles Two Factor provider classes internally.

| Aspect | Detail |
|--------|--------|
| Detection | Uses `_two_factor_totp_key` user meta (same as Two Factor) |
| Validation | Two Factor-compatible provider pattern |
| Storage | user meta (encrypted with `ITSEC_ENCRYPTION_KEY`) |
| Notes | Test whether `class_exists( 'Two_Factor_Core' )` returns true. If so, WP Sudo's built-in integration covers it. |

### All-In-One Security (AIOS)

**Status:** Bridgeable with user meta checks and class calls.

| Aspect | Detail |
|--------|--------|
| Detection | `get_user_meta( $user_id, 'tfa_enable_tfa', true )` |
| Validation | `Simba_TFA->authorise_user_from_login( $params )` |
| Storage | `tfa_priv_key_64` user meta (base64-encoded) |
| Notes | Embeds the Simba Two Factor Authentication engine. |

### Shield Security

**Status:** Not practically bridgeable.

| Aspect | Detail |
|--------|--------|
| Detection | Deep container/controller system |
| Validation | `GoogleAuth->processOtp()` |
| Storage | Custom database table |
| Notes | Deeply encapsulated architecture, no public API. |

### miniOrange Google Authenticator

**Status:** Not practically bridgeable.

| Aspect | Detail |
|--------|--------|
| Detection | `get_user_meta( ..., 'currentMethod' )` |
| Validation | Cloud API call to miniOrange servers |
| Notes | No local validation path for hosted 2FA methods. |

---

## Working Bridge Example: WP 2FA by Melapress

This bridge supports TOTP, email OTP, and backup code methods. Drop it in `wp-content/mu-plugins/wp-sudo-wp2fa-bridge.php`.

The complete, tested bridge file is provided at [`bridges/wp-sudo-wp2fa-bridge.php`](../bridges/wp-sudo-wp2fa-bridge.php).

**Key design decisions:**

1. **All three methods supported.** The bridge checks which method the user has enabled and validates accordingly. TOTP and backup codes validate locally; email codes use WP 2FA's token system.

2. **Encrypted secrets handled transparently.** WP 2FA encrypts TOTP secrets with AES-256-CTR. The `Authentication::is_valid_authcode()` method accepts the encrypted key directly — no manual decryption needed.

3. **Backup code field is separate.** The bridge renders a distinct backup code input. WP Sudo's JavaScript submits all form fields, so both inputs arrive in `$_POST`.

4. **Respects existing validation.** If `$valid` arrives as `true` in the validate filter (meaning another plugin already validated), the bridge returns `true` immediately.

---

## Bridge Patterns for Other Plugins

### Wordfence (~30 lines)

```php
// Detection
add_filter( 'wp_sudo_requires_two_factor', function ( $needs, $user_id ) {
    if ( class_exists( '\WordfenceLS\Controller_Users' ) ) {
        $user = get_userdata( $user_id );
        if ( $user && \WordfenceLS\Controller_Users::shared()->has_2fa_active( $user ) ) {
            return true;
        }
    }
    return $needs;
}, 10, 2 );

// Render — standard 6-digit TOTP input (same as any TOTP bridge)

// Validate
add_filter( 'wp_sudo_validate_two_factor', function ( $valid, $user ) {
    if ( $valid ) return true;
    if ( ! class_exists( '\WordfenceLS\Controller_TOTP' ) ) return $valid;
    $code = sanitize_text_field( wp_unslash( $_POST['wf_2fa_code'] ?? '' ) );
    return \WordfenceLS\Controller_TOTP::shared()->validate_2fa( $user, $code );
}, 10, 2 );
```

### AIOS / Simba TFA (~40 lines)

```php
// Detection
add_filter( 'wp_sudo_requires_two_factor', function ( $needs, $user_id ) {
    if ( get_user_meta( $user_id, 'tfa_enable_tfa', true ) ) {
        return true;
    }
    return $needs;
}, 10, 2 );

// Render — standard 6-digit TOTP input

// Validate
add_filter( 'wp_sudo_validate_two_factor', function ( $valid, $user ) {
    if ( $valid ) return true;
    if ( ! class_exists( 'Simba_Two_Factor_Authentication_1' ) ) return $valid;
    $code = sanitize_text_field( wp_unslash( $_POST['aios_2fa_code'] ?? '' ) );
    // Simba TFA reads from $_POST internally, so set the expected field.
    $_POST['two_factor_code'] = $code;
    global $simba_two_factor_authentication;
    if ( $simba_two_factor_authentication && method_exists( $simba_two_factor_authentication, 'authorise_user_from_login' ) ) {
        $params = array( 'log' => $user->user_login, 'caller' => 'wp-sudo' );
        return (bool) $simba_two_factor_authentication->authorise_user_from_login( $params );
    }
    return $valid;
}, 10, 2 );
```

---

## Known Issues

### Silent fallback to recovery codes when TOTP key is missing

**Affects:** Two Factor plugin (WordPress/two-factor) 0.14.x
**Status:** Upstream bug — to be reported to the Two Factor project.

When a user enables the TOTP provider via the Two Factor profile UI but the REST API call that saves the TOTP secret (`_two_factor_totp_key`) fails silently, the plugin enters an inconsistent state:

- `_two_factor_enabled_providers` lists `Two_Factor_Totp` (saved by the profile form).
- `_two_factor_totp_key` is missing (the REST call to `POST /two-factor/1.0/totp` failed).

Because `Two_Factor_Totp::is_available_for_user()` checks for the TOTP key and returns `false` when it is missing, `Two_Factor_Core::get_primary_provider_for_user()` silently falls back to the next available provider — typically `Two_Factor_Backup_Codes`. The user sees a prompt for a recovery code when they expect to enter a TOTP code from their authenticator app.

**Impact on WP Sudo:** WP Sudo calls `get_primary_provider_for_user()` and renders whatever provider Two Factor returns. If Two Factor silently falls back to Backup Codes, the WP Sudo challenge page shows "enter a recovery code" instead of "enter the code from your authenticator app." The user enters a valid TOTP code, it is validated as a backup code, and validation fails with no explanation of why.

**Root cause:** The Two Factor TOTP setup uses a JavaScript REST API call (`wp.apiRequest`) to save the TOTP key. If this call fails (due to REST API issues, plugin conflicts, or environment-specific problems like SQLite compatibility), the failure is not surfaced to the user. The profile form save succeeds independently, writing `_two_factor_enabled_providers` with TOTP listed but no corresponding TOTP secret in the database.

**Recommended fix for Two Factor:** When `get_primary_provider_for_user()` falls back from the user's configured primary provider to a different provider, the plugin should display a visible warning — either on the login 2FA screen or on the user's profile page. A silent fallback from TOTP to recovery codes is a poor UX pattern that confuses users.

**Workaround:** Verify the TOTP key exists in user meta after setup:
```bash
wp user meta get <user_id> _two_factor_totp_key
```
If empty, the TOTP setup did not complete. Delete `_two_factor_enabled_providers` and repeat the setup, watching for REST API errors in the browser console.

---

## Constraints and Unsupported Patterns

### Things that won't work

1. **Cloud-based validation.** If your plugin validates codes through a remote API (e.g., miniOrange), the latency and error-handling complexity make integration unreliable in a synchronous AJAX context.

2. **JavaScript-only methods.** WP Sudo's 2FA form submission is a standard `FormData` POST. If your method requires a JavaScript ceremony (e.g., WebAuthn), you'll need to enqueue your scripts on the challenge page and populate a hidden field with the result. See the WebAuthn notes in [two-factor-integration.md](two-factor-integration.md#webauthn--passkey-considerations).

3. **Push notification methods.** Methods where the user approves on a separate device (push notifications, Duo) don't fit the synchronous form-submit model. A polling-based approach would be needed, which WP Sudo does not currently support.

### Encrypted secrets

If your plugin encrypts TOTP secrets (as WP 2FA and Solid Security do), your validation method must handle decryption internally. WP Sudo never reads or stores TOTP secrets — it only calls your validation callback.

### Multiple active methods

If a user has multiple 2FA methods configured (e.g., TOTP primary + backup codes), your bridge should:
- Render inputs for the primary method and a fallback (backup codes).
- In the validation callback, check the primary input first, then fall back.

---

## Testing Your Bridge

### Manual test procedure

1. Activate your 2FA plugin and WP Sudo on the same site.
2. Configure 2FA for a test user.
3. Drop the bridge into `mu-plugins/`.
4. Trigger a gated action (e.g., activate a plugin from the Plugins page).
5. Verify the challenge page shows:
   - Password step first.
   - After correct password: 2FA step with your form fields.
   - After correct code: the original action completes.
6. Test with a wrong code — should show "Invalid authentication code."
7. Test with an expired session (wait for the countdown) — should show "Your authentication session has expired."

### Automated testing

If you want to unit test your bridge in isolation:

```php
// In your test, mock WP Sudo's filter system:
$needs = apply_filters( 'wp_sudo_requires_two_factor', false, $user_id );
$this->assertTrue( $needs );

// Simulate a POST with a valid code:
$_POST['my_2fa_code'] = '123456';
$valid = apply_filters( 'wp_sudo_validate_two_factor', false, $user );
$this->assertTrue( $valid );
```

The bridge is just WordPress filters and an action — standard `add_filter`/`add_action` patterns that are easy to test with Brain\Monkey or WP_Mock.
