# Two-Factor Authentication Integration

WP Sudo has built-in support for the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin, but its 2FA architecture is designed to work with **any** 2FA plugin through four hooks. This document explains how the integration works and how to connect an alternative 2FA provider.

## Table of Contents

- [Architecture Overview](#architecture-overview)
- [The Two-Step Challenge Flow](#the-two-step-challenge-flow)
- [How Built-In Two Factor Support Works](#how-built-in-two-factor-support-works)
- [Compatibility Hazards](#compatibility-hazards)
- [Hooks for Third-Party 2FA Plugins](#hooks-for-third-party-2fa-plugins)
- [Integration Guide: Connecting Your Own 2FA Plugin](#integration-guide-connecting-your-own-2fa-plugin)
- [Security Model](#security-model)
- [Reference: Files Involved](#reference-files-involved)

---

## Architecture Overview

WP Sudo's challenge page is a two-step reauthentication flow:

1. **Password step** -- the user enters their WordPress password.
2. **2FA step** -- if the user has two-factor authentication configured, a second form appears for their authentication code (TOTP, email code, backup code, WebAuthn, etc.).

The sudo session is **only activated after both steps succeed**. A correct password alone does not grant a session when 2FA is enabled.

The 2FA step is entirely optional. If no 2FA plugin is active or the user has not configured 2FA, the session activates immediately after a successful password.

```
┌─────────────────────────────────────────────────────────────┐
│                    Gate intercepts action                   │
│              (plugin activation, user deletion, etc.)       │
└────────────────────────────┬────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────┐
│                  Challenge Page (Step 1)                    │
│                    Password Prompt                          │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐    │
│  │  User enters WordPress password                     │    │
│  │  → AJAX POST to wp_sudo_challenge_auth              │    │
│  └──────────────────────┬──────────────────────────────┘    │
└─────────────────────────┼───────────────────────────────────┘
                          │
                          ▼
              ┌───────────────────────┐
              │  Password correct?    │
              └───────┬───────┬───────┘
                 NO   │       │  YES
                 ▼    │       ▼
             (error)  │  ┌──────────────────┐
                      │  │ needs_two_factor?│
                      │  └──────┬─────┬─────┘
                      │    NO   │     │ YES
                      │    ▼    │     ▼
                      │ activate│  ┌──────────────────────────────────────┐
                      │ session │  │  Return '2fa_pending'                │
                      │         │  │  Set challenge cookie (httponly)     │
                      │         │  │  Store pending transient             │
                      │         │  └──────────────────┬───────────────────┘
                      │         │                     │
                      │         │                     ▼
                      │         │  ┌──────────────────────────────────────┐
                      │         │  │  Challenge Page (Step 2)             │
                      │         │  │  2FA Authentication                   │
                      │         │  │                                      │
                      │         │  │  ┌──────────────────────────────┐    │
                      │         │  │  │ User enters 2FA code         │    │
                      │         │  │  │ → AJAX POST to               │    │
                      │         │  │  │   wp_sudo_challenge_2fa      │    │
                      │         │  │  └──────────────┬───────────────┘    │
                      │         │  └─────────────────┼────────────────────┘
                      │         │                    │
                      │         │                    ▼
                      │         │        ┌───────────────────────┐
                      │         │        │   2FA code valid?     │
                      │         │        └───────┬───────┬───────┘
                      │         │           NO   │       │ YES
                      │         │           ▼    │       ▼
                      │         │       (error)  │  clear pending state
                      │         │                │  activate session
                      │         │                │  replay stashed request
                      │         │                │
                      ▼         ▼                ▼
              ┌─────────────────────────────────────────┐
              │  Sudo session active                    │
              │  Original action replayed               │
              └─────────────────────────────────────────┘
```

---

## The Two-Step Challenge Flow

### Step 1: Password Authentication

When the user submits their password, the JavaScript sends an AJAX request to `wp_sudo_challenge_auth`. The server calls `Sudo_Session::attempt_activation()`, which:

1. Checks for lockout (5 failed attempts = 5-minute lockout).
2. Validates the password with `wp_check_password()`.
3. Calls `Sudo_Session::needs_two_factor( $user_id )`.

If 2FA is **not** required, the session activates immediately and the original request is replayed.

If 2FA **is** required, the server:

- Generates a random 32-character challenge nonce.
- Hashes it with SHA-256.
- Stores a transient (`wp_sudo_2fa_pending_{hash}`) containing the user ID and expiry.
- Sets the nonce in an httponly, SameSite=Strict cookie (`wp_sudo_challenge`).
- Returns `{ code: '2fa_pending', expires_at: <timestamp> }`.

The JavaScript hides the password form and reveals the 2FA form, starting a visible countdown timer.

### Step 2: 2FA Authentication

When the user submits the 2FA form, the JavaScript:

1. Collects the form data (including any fields the 2FA provider rendered).
2. **Strips** any `action` and `_wpnonce` fields the provider may have injected.
3. Appends WP Sudo's own AJAX action (`wp_sudo_challenge_2fa`) and nonce.
4. Sends the request.

The server (`Challenge::handle_ajax_2fa()`) then:

1. Verifies the WordPress nonce.
2. Reads the challenge cookie and looks up the matching transient.
3. Validates the transient belongs to the current user and has not expired.
4. Calls the 2FA provider's validation method.
5. Applies the `wp_sudo_validate_two_factor` filter.
6. On success: clears the pending state, activates the session, replays the stash.

---

## How Built-In Two Factor Support Works

WP Sudo has zero-configuration support for the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin. The integration uses three methods from `Two_Factor_Core`:

| Method | Where Called | Purpose |
|--------|-------------|---------|
| `Two_Factor_Core::is_user_using_two_factor( $user_id )` | `Sudo_Session::needs_two_factor()` | Detect if the user has a 2FA provider configured |
| `Two_Factor_Core::get_primary_provider_for_user( $user )` | `Challenge::render_page()` and `Challenge::handle_ajax_2fa()` | Get the user's primary provider object |
| `$provider->authentication_page( $user )` | `Challenge::render_page()` | Render the provider's form fields (TOTP input, email code input, etc.) |
| `$provider->pre_process_authentication( $user )` | `Challenge::handle_ajax_2fa()` | Pre-process (e.g., resend email code) |
| `$provider->validate_authentication( $user )` | `Challenge::handle_ajax_2fa()` | Validate the submitted code |

All detection uses `class_exists( '\\Two_Factor_Core' )` -- there is no filename-based check. This works regardless of how the Two Factor plugin is installed (standard plugin, mu-plugin, Composer).

The integration is **provider-agnostic**: WP Sudo does not know or care whether the user is using TOTP, email, backup codes, or WebAuthn. It delegates all rendering and validation to the provider's own API.

---

## Compatibility Hazards

The built-in Two Factor support works well, but there are a few integration assumptions that matter for both upstream Two Factor providers and third-party bridges:

1. **Provider fields render inside WP Sudo's form, not a provider-owned form.** Provider HTML is injected into `#wp-sudo-challenge-2fa-form` and submitted through WP Sudo's AJAX handler. Providers must not assume they control the outer `<form>` element or the request target.

2. **Provider `action` and `_wpnonce` fields are not authoritative.** Some providers render hidden `action` or `_wpnonce` inputs for their normal login flow. WP Sudo strips those fields before submitting the 2FA AJAX request and replaces them with its own handler and nonce. If a provider depends on those hidden values surviving unchanged, it is not compatible with the challenge flow as-is.

3. **Resend and pre-processing flows must stay on the same 2FA step.** When a provider uses `pre_process_authentication()` for actions like resending an email code, WP Sudo keeps the browser on the active 2FA screen and expects the provider to continue validating a later submission in the same challenge session.

4. **Challenge tabs can become stale while the browser already has an active sudo session.** A provider or bridge should not assume that loading the challenge page always means "user is unauthenticated." Another tab may already have completed the sudo flow, including 2FA, and WP Sudo may short-circuit back to the requested admin page instead of prompting again.

5. **Throttle and lockout UX is owned by WP Sudo.** Providers are responsible for rendering fields and validating codes. Countdown messaging, disabled submit states, and retry timing are handled by WP Sudo's challenge UI once the provider has returned a resend, invalid, throttled, or locked-out outcome.

These assumptions are enforced by the browser test suite, including provider-hidden-field, resend, stale-session, throttle, and lockout challenge cases.

---

## Hooks for Third-Party 2FA Plugins

Four hooks allow any 2FA plugin to integrate with WP Sudo's challenge flow:

### 1. `wp_sudo_requires_two_factor` (filter)

**When:** During password verification, after `wp_check_password()` succeeds.

**Signature:**
```php
apply_filters( 'wp_sudo_requires_two_factor', bool $needs, int $user_id ): bool
```

**Purpose:** Tell WP Sudo that this user has 2FA configured and should see the second step.

**Parameters:**
- `$needs` -- `true` if the Two Factor plugin already detected 2FA; `false` otherwise.
- `$user_id` -- The user being authenticated.

**Return:** `true` to require 2FA, `false` to skip it.

### 2. `wp_sudo_render_two_factor_fields` (action)

**When:** While rendering the challenge page HTML, inside the `#wp-sudo-challenge-2fa-form` element.

**Signature:**
```php
do_action( 'wp_sudo_render_two_factor_fields', WP_User $user )
```

**Purpose:** Output the HTML form fields for your 2FA method (input fields, hidden fields, scripts).

**Parameters:**
- `$user` -- The `WP_User` object for the user being authenticated.

**Notes:**
- Fires after the built-in Two Factor provider rendering (if present).
- Your fields will be collected as `FormData` and submitted via AJAX.
- Do **not** render your own submit button -- WP Sudo provides "Verify & Continue".
- Do **not** add `action` or `_wpnonce` hidden fields -- the JavaScript strips and replaces them.

### 3. `wp_sudo_validate_two_factor` (filter)

**When:** During AJAX 2FA authentication, after the built-in Two Factor validation runs.

**Signature:**
```php
apply_filters( 'wp_sudo_validate_two_factor', bool $valid, WP_User $user ): bool
```

**Purpose:** Validate the submitted 2FA code against your plugin's logic.

**Parameters:**
- `$valid` -- `true` if the Two Factor plugin already validated it; `false` otherwise.
- `$user` -- The `WP_User` object.

**Return:** `true` if the 2FA code is valid, `false` otherwise.

**Notes:**
- Your submitted form fields are available in `$_POST`.
- If the Two Factor plugin is not installed, `$valid` will always arrive as `false`.

### 4. `wp_sudo_two_factor_window` (filter)

**When:** When creating the 2FA pending state, after a successful password.

**Signature:**
```php
apply_filters( 'wp_sudo_two_factor_window', int $window ): int
```

**Purpose:** Control how long (in seconds) the user has to complete the 2FA step.

**Default:** 300 seconds (5 minutes).

---

## Integration Guide: Connecting Your Own 2FA Plugin

Here is a minimal, complete integration for a hypothetical 2FA plugin:

```php
<?php
/**
 * Bridge between My2FA Plugin and WP Sudo.
 *
 * Drop this in a separate file (e.g., mu-plugins/my2fa-wp-sudo-bridge.php)
 * or add it to your 2FA plugin's initialization.
 */

// 1. Tell WP Sudo this user needs 2FA.
add_filter( 'wp_sudo_requires_two_factor', function ( bool $needs, int $user_id ): bool {
    if ( my2fa_user_has_2fa( $user_id ) ) {
        return true;
    }
    return $needs;
}, 10, 2 );

// 2. Render the 2FA input field on the challenge page.
add_action( 'wp_sudo_render_two_factor_fields', function ( WP_User $user ): void {
    // Do NOT render a submit button or action/nonce fields.
    ?>
    <p>
        <label for="my2fa-code">
            <?php esc_html_e( 'Enter your authentication code:', 'my2fa' ); ?>
        </label>
        <input type="text"
               id="my2fa-code"
               name="my2fa_code"
               autocomplete="one-time-code"
               inputmode="numeric"
               pattern="[0-9]*"
               required />
    </p>
    <?php
} );

// 3. Validate the submitted code.
add_filter( 'wp_sudo_validate_two_factor', function ( bool $valid, WP_User $user ): bool {
    // If another plugin already validated, don't override.
    if ( $valid ) {
        return true;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WP Sudo handles nonce.
    $code = isset( $_POST['my2fa_code'] )
        ? sanitize_text_field( wp_unslash( $_POST['my2fa_code'] ) )
        : '';

    return my2fa_verify_code( $user->ID, $code );
}, 10, 2 );
```

### Integration Checklist

| Step | Hook | What to do |
|------|------|------------|
| Detect | `wp_sudo_requires_two_factor` | Return `true` when the user has your 2FA method configured |
| Render | `wp_sudo_render_two_factor_fields` | Output HTML form fields (no submit button, no `action`/`_wpnonce` fields) |
| Validate | `wp_sudo_validate_two_factor` | Read your fields from `$_POST` and verify the code |
| (Optional) | `wp_sudo_two_factor_window` | Adjust the authentication window if your method needs more time |

### Things to Avoid

- **Do not render a submit button.** WP Sudo provides "Verify & Continue".
- **Do not add `action` or `_wpnonce` hidden fields.** The JavaScript strips them and adds WP Sudo's own values. If you add them, they will be silently removed.
- **Do not add your own form element.** Your fields render inside WP Sudo's `<form>`.
- **Do not handle nonce verification.** WP Sudo calls `check_ajax_referer()` before your filter runs.
- **Respect the `$valid` parameter.** If it arrives as `true`, another plugin already validated. Return `true` to let it pass. Only set it to `false` if you have a positive reason to reject.

### WebAuthn / Passkey Considerations

If your 2FA method uses WebAuthn (browser-based passkey ceremonies), you'll need to:

1. Enqueue your JavaScript on the challenge page. Hook `admin_enqueue_scripts` and check for the `wp-sudo-challenge` page.
2. Render a hidden input in `wp_sudo_render_two_factor_fields` that your JS populates with the attestation/assertion response.
3. Validate the response server-side in `wp_sudo_validate_two_factor`.

The Two Factor plugin's WebAuthn provider already works this way through `authentication_page()`, so the pattern is proven.

#### Gating WebAuthn Key Registration

By default, WebAuthn security key registration and deletion are **not gated** by WP Sudo. An attacker with a hijacked session could silently register their own security key — the same risk class as ungated Application Password creation.

To gate these operations, install the WebAuthn bridge as a mu-plugin:

```bash
cp bridges/wp-sudo-webauthn-bridge.php wp-content/mu-plugins/
```

The bridge uses the `wp_sudo_gated_actions` filter to add AJAX rules for the WebAuthn Provider's `webauthn_preregister`, `webauthn_register`, and `webauthn_delete_key` endpoints. Key renaming (`webauthn_rename_key`) is intentionally not gated — it is not a security-sensitive operation.

See `bridges/wp-sudo-webauthn-bridge.php` for the complete implementation, or the [Developer Reference](developer-reference.md#gating-third-party-plugin-actions) for a general guide to adding custom gated actions.

---

## Security Model

The two-step flow has several security properties:

### Browser Binding

The challenge cookie (`wp_sudo_challenge`) is a random 32-character nonce set as httponly with `SameSite=Strict`. The pending transient is keyed by its SHA-256 hash. An attacker who steals the WordPress session cookie on a different machine cannot complete the 2FA step because they don't have this cookie.

### Time-Bounded Window

The pending state expires after 5 minutes (configurable via `wp_sudo_two_factor_window`). Both the transient TTL and the cookie expiry enforce this. The JavaScript shows a countdown timer and an advisory warning when time runs out; the server remains the authoritative source for expiry.

### User Ownership

The transient stores the `user_id`. Even if the challenge cookie were somehow obtained, `get_2fa_pending()` validates that the transient's user ID matches the current session's user.

### One-Time Use

After successful 2FA, `clear_2fa_pending()` deletes both the transient and the cookie, preventing replay.

### No Partial Activation

`Sudo_Session::activate()` is only called after **both** password and 2FA succeed. A correct password alone creates no session state -- only a pending 2FA transient.

---

## Reference: Files Involved

| File | 2FA Role |
|------|----------|
| `includes/class-sudo-session.php` | Detection (`needs_two_factor`), pending state (`get_2fa_pending`, `clear_2fa_pending`), challenge cookie, window filter |
| `includes/class-challenge.php` | Rendering the 2FA form, AJAX handlers for password and 2FA steps, provider delegation |
| `admin/js/wp-sudo-challenge.js` | Client-side step transition, form field handling, countdown timer, code-resent handling |
| `admin/css/wp-sudo-challenge.css` | Timer styling, hides Two Factor plugin's own submit button |
| `includes/class-admin.php` | Help tab documentation of 2FA hooks |

---

## Further Reading

- **[Two-Factor Plugin Ecosystem Guide](two-factor-ecosystem.md)** — Comprehensive survey of how major WordPress 2FA plugins work internally, with bridge patterns for Wordfence, WP 2FA, and AIOS.
- **[`bridges/wp-sudo-wp2fa-bridge.php`](../bridges/wp-sudo-wp2fa-bridge.php)** — A complete, working bridge for WP 2FA by Melapress, ready to drop into `mu-plugins/`.
