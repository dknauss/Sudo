# Two-Factor Plugin Authentication Flow

This document explains how the upstream [WordPress Two-Factor plugin](https://github.com/WordPress/two-factor) extends the WordPress core authentication flow.

> Verified against the current upstream `WordPress/two-factor` source, current WordPress core source, and official WordPress Developer Documentation on **March 31, 2026**.

## 1. Where the Plugin Hooks Core

The plugin inserts itself at these key points:

```php
add_action( 'login_form_validate_2fa', array( __CLASS__, 'login_form_validate_2fa' ) );
add_action( 'login_form_revalidate_2fa', array( __CLASS__, 'login_form_revalidate_2fa' ) );
add_filter( 'authenticate', array( __CLASS__, 'filter_authenticate' ), 31 );
add_action( 'wp_login', array( __CLASS__, 'wp_login' ), PHP_INT_MAX, 2 );
add_action( 'set_auth_cookie', array( __CLASS__, 'collect_auth_cookie_tokens' ) );
add_action( 'set_logged_in_cookie', array( __CLASS__, 'collect_auth_cookie_tokens' ) );
add_filter( 'attach_session_information', array( __CLASS__, 'filter_session_information' ), 10, 2 );
```

These registrations are the backbone of the plugin’s browser-login, second-factor, and revalidation flows.

## 2. Browser Login Flow

At a high level, browser login looks like this:

```text
wp_signon()
  -> core authenticate callbacks
     -> [20] wp_authenticate_username_password()
     -> [20] wp_authenticate_email_password()
     -> [20] wp_authenticate_application_password()
     -> [30] wp_authenticate_cookie()           [added by wp_signon()]
     -> [31] Two_Factor_Core::filter_authenticate()
  -> wp_set_auth_cookie()
     -> set_auth_cookie / set_logged_in_cookie hooks capture token(s)
  -> wp_login
     -> Two_Factor_Core::wp_login() at PHP_INT_MAX
        -> destroy current password-auth session token(s)
        -> wp_clear_auth_cookie()
        -> show_two_factor_login()
        -> exit
```

### `filter_authenticate()`

For users who have Two-Factor enabled, `Two_Factor_Core::filter_authenticate()`:

- adds `send_auth_cookies => __return_false` at `PHP_INT_MAX`
- allows the password-authenticated `WP_User` object to continue for **browser login**
- blocks API login with `WP_Error` when the request is specifically an **XML-RPC or REST request** and the user is not allowed to log in that way

That means the plugin does **not** replace WordPress password authentication. It suppresses cookie delivery until the second factor succeeds.

By default, API login remains allowed only when `application_password_did_authenticate` already fired or a site overrides `two_factor_user_api_login_enable`.

### Cookie suppression and token capture

Even while cookie sending is suppressed, core still calls `wp_set_auth_cookie()`, and Two-Factor captures the generated token(s) via:

- `set_auth_cookie`
- `set_logged_in_cookie`

`collect_auth_cookie_tokens()` parses those cookie values and stores their session tokens in an internal array so they can be destroyed before the second factor completes.

### `wp_login()` handoff

At `wp_login`, the plugin checks whether the user is actually using Two-Factor.

If yes, it:

1. destroys the just-created password-auth session token(s)
2. calls `wp_clear_auth_cookie()` defensively
3. calls `show_two_factor_login( $user )`
4. exits

The important guarantee here is **server-side token destruction**. In this specific flow, `send_auth_cookies` is still filtered to `false`, so `wp_clear_auth_cookie()` is not the step that actually clears already-sent browser cookies; the password-authenticated session never becomes usable because its session token is destroyed before the second-factor challenge is completed.

## 3. Two-Factor Challenge Screen

`show_two_factor_login()`:

1. creates a login nonce via `create_login_nonce( $user_id )`
2. stores the **hashed** nonce in user meta under `_two_factor_nonce`
3. sets a **10-minute** expiration on that nonce
4. calls `login_html()` to render the challenge form

### Challenge form fields

`login_html()` renders a form posting to `wp-login.php?action=validate_2fa` with fields including:

- `provider`
- `wp-auth-id`
- `wp-auth-nonce`
- `redirect_to`
- `rememberme`

The selected provider renders its own UI through:

```php
$provider->authentication_page( $user );
```

If multiple providers are available, `login_html()` also renders backup-provider links using `get_alternative_provider_label()`.

## 4. Validation Flow (`action=validate_2fa`)

`login_form_validate_2fa()` collects request state and delegates into `_login_form_validate_2fa()`.

That flow is:

```text
verify_login_nonce()
  -> get_provider_for_user()
  -> process_provider()
     -> provider->pre_process_authentication()
     -> if GET: no validation yet
     -> rate limit check
     -> provider->validate_authentication()
     -> on failure: update counters, maybe reset password, return WP_Error
  -> on success:
     -> delete_login_nonce()
     -> clear rate-limit meta
     -> attach two-factor session metadata
     -> remove send_auth_cookies suppression
     -> wp_set_auth_cookie()
     -> do_action( 'two_factor_user_authenticated', ... )
     -> apply_filters( 'login_redirect', ... )
     -> redirect
```

### Important details

- **Nonce verification** uses a dedicated login nonce, not a standard `wp_nonce`.
- `verify_login_nonce()` returns `false` and deletes the stored nonce when verification fails; the actual redirect to `home_url()` happens in `_login_form_validate_2fa()`, not in `verify_login_nonce()` itself.
- `get_provider_for_user()` can prefer the currently active two-factor provider already recorded in the current session.
- `process_provider()` runs `pre_process_authentication()` **before** POST validation. This is how providers like Email can handle resend actions.
- The current built-in upstream providers are **Email**, **TOTP**, **Backup Codes**, and **Dummy**. WebAuthn/passkey support is typically provided by third-party extensions, not by upstream core Two-Factor itself.

### Rate limiting and reset behavior

The plugin uses two user-meta keys together:

- `_two_factor_last_login_failure`
- `_two_factor_failed_login_attempts`

Behavior:

- `_two_factor_last_login_failure` stores the **timestamp of the most recent failure**, not the computed backoff duration.
- backoff starts from the filtered base rate (`two_factor_rate_limit`, default 1 second)
- delay becomes `2^n * base`, capped by `two_factor_max_rate_limit` (default 15 minutes)
- after the failed-attempt limit (`two_factor_failed_attempt_limit`, default 30), the plugin can reset the password and send notifications when the user is not already logged in

## 5. Revalidation Flow (`action=revalidate_2fa`)

This is a separate path used when a logged-in user needs a fresh second-factor confirmation to change Two-Factor settings.

### Revalidation policy

`current_user_can_update_two_factor_options()`:

- returns `true` immediately for users not using Two-Factor
- requires the current session to already be a Two-Factor-authenticated session
- uses `two_factor_revalidate_time` with these defaults:
  - **10 minutes** for display
  - **20 minutes** for save (`display * 2`)

That method only returns a boolean; it does **not** redirect by itself. The calling request handlers decide whether to render, block, or redirect.

### Revalidation request handling

`login_form_revalidate_2fa()` delegates into `_login_form_revalidate_2fa()`.

Key differences from login validation:

- GET requests can render the form without a nonce check
- POST requests use a standard WordPress nonce: `two_factor_revalidate_{$user_id}`
- on success, the plugin updates the **current session in place** via `update_current_user_session()`
- it then fires `two_factor_user_revalidated`
- it applies `login_redirect` before redirecting
- it does **not** mint a brand-new login cookie the way the initial 2FA login flow does

## 6. Session Continuity

The plugin uses `attach_session_information` in **two different ways**:

1. **Temporary success-path closure during initial 2FA completion**
   - `_login_form_validate_2fa()` adds a one-off closure that stamps the newly created session with:
     - `two-factor-login`
     - `two-factor-provider`
   - it removes that closure immediately after `wp_set_auth_cookie()` returns

2. **Always-on continuity filter**
   - `filter_session_information()` copies any existing current-session keys beginning with `two-factor-` into replacement sessions

In practice, that preserves values like:

- `two-factor-login`
- `two-factor-provider`

This matters when core or other code paths create a replacement session while a user is already authenticated.

## 7. Provider Contract

The abstract provider base class defines these required provider responsibilities:

| Method | Required? | Purpose |
|---|---|---|
| `get_label()` | Yes | Human-readable provider name |
| `authentication_page( $user )` | Yes | Render the challenge UI |
| `validate_authentication( $user )` | Yes | Validate the second factor |
| `is_available_for_user( $user )` | Yes | Whether the provider is configured/usable |
| `pre_process_authentication( $user )` | No | Optional pre-processing, e.g. resend code |
| `get_key()` | Provided by base class | Provider key (defaults to class name) |
| `get_alternative_provider_label()` | Provided by base class | “Use X” label for backup-provider links |

## 8. Key Security Properties

| Mechanism | Purpose |
|---|---|
| `send_auth_cookies => __return_false` | Prevents browser cookies from being sent before second-factor success |
| `collect_auth_cookie_tokens()` + `destroy_current_session_for_user()` | Removes the pre-2FA session created by core |
| `wp_clear_auth_cookie()` | Defensive cleanup call before challenge rendering |
| Login nonce stored hashed in user meta | Binds the 2FA challenge to a user and time window |
| User-meta rate limiting | Slows repeated invalid second-factor attempts |
| Password reset threshold | Stops effectively unbounded brute force on weaker second factors |
| Revalidation grace period | Limits stale-session settings changes |
| `filter_session_information()` | Preserves Two-Factor metadata on replacement sessions |

## 9. Why This Matters to WP Sudo

WP Sudo does **not** reuse the upstream Two-Factor login flow. Instead, it borrows the same general model:

- password verification first
- second factor before granting a higher-trust session
- provider-rendered fields plus provider-owned validation
- explicit replay or continuation only after the second factor succeeds

That is why this reference belongs alongside:

- [Two-Factor Authentication Integration](two-factor-integration.md)
- [WordPress Core Authentication Logic](wordpress-core-authentication.md)

## Sources

Official WordPress Developer Documentation:

- [wp_signon()](https://developer.wordpress.org/reference/functions/wp_signon/)
- [wp_authenticate_cookie()](https://developer.wordpress.org/reference/functions/wp_authenticate_cookie/)
- [wp_set_auth_cookie()](https://developer.wordpress.org/reference/functions/wp_set_auth_cookie/)
- [wp_clear_auth_cookie()](https://developer.wordpress.org/reference/functions/wp_clear_auth_cookie/)
- [wp_validate_application_password()](https://developer.wordpress.org/reference/functions/wp_validate_application_password/)
- [Application Passwords](https://developer.wordpress.org/advanced-administration/security/application-passwords/)

Upstream Two-Factor permalinks checked on 2026-03-31:

- [Hook registration (`add_hooks()`)](https://github.com/WordPress/two-factor/blob/bea876d72062626f830c6b39f5348836e58472da/class-two-factor-core.php#L102-L135)
- [Browser handoff (`wp_login()`) and authenticate filter](https://github.com/WordPress/two-factor/blob/bea876d72062626f830c6b39f5348836e58472da/class-two-factor-core.php#L855-L920)
- [Login nonce creation and verification](https://github.com/WordPress/two-factor/blob/bea876d72062626f830c6b39f5348836e58472da/class-two-factor-core.php#L1280-L1371)
- [Rate limiting and revalidation policy](https://github.com/WordPress/two-factor/blob/bea876d72062626f830c6b39f5348836e58472da/class-two-factor-core.php#L1385-L1524)
- [Initial validation completion flow](https://github.com/WordPress/two-factor/blob/bea876d72062626f830c6b39f5348836e58472da/class-two-factor-core.php#L1560-L1711)
- [Revalidation completion flow](https://github.com/WordPress/two-factor/blob/bea876d72062626f830c6b39f5348836e58472da/class-two-factor-core.php#L1712-L1804)
- [Provider processing and reset logic](https://github.com/WordPress/two-factor/blob/bea876d72062626f830c6b39f5348836e58472da/class-two-factor-core.php#L1812-L1919)
- [Session continuity filter](https://github.com/WordPress/two-factor/blob/bea876d72062626f830c6b39f5348836e58472da/class-two-factor-core.php#L2583-L2597)
- [Provider base contract](https://github.com/WordPress/two-factor/blob/bea876d72062626f830c6b39f5348836e58472da/providers/class-two-factor-provider.php#L49-L129)

WordPress core permalinks checked on 2026-03-31:

- [Current `wp_signon()` flow](https://github.com/WordPress/wordpress-develop/blob/abf9109166099011904710d1e8c63f444d0b862a/src/wp-includes/user.php#L41-L138)
- [Default `authenticate` registrations](https://github.com/WordPress/wordpress-develop/blob/abf9109166099011904710d1e8c63f444d0b862a/src/wp-includes/default-filters.php#L503-L506)
- [Cookie set / clear behavior](https://github.com/WordPress/wordpress-develop/blob/abf9109166099011904710d1e8c63f444d0b862a/src/wp-includes/pluggable.php#L1071-L1219)
- [Core `attach_session_information` plumbing](https://github.com/WordPress/wordpress-develop/blob/abf9109166099011904710d1e8c63f444d0b862a/src/wp-includes/class-wp-session-tokens.php#L118-L149)
