# WordPress Core Authentication Logic

This document explains the current WordPress core browser-authentication path, cookie generation, and session validation flow.

> Verified against WordPress core source and official Developer Documentation on **March 31, 2026**. The forward local test lane in this repo is currently pinned to **WordPress 7.0-RC1**.

## 1. Sign-on Flow (`wp-login.php` → `wp_signon()` → authenticated session)

At a high level, browser login follows this sequence:

```text
User submits credentials
        |
        v
   wp_signon()
        |
        |-- do_action_ref_array( 'wp_authenticate', [&$user_login, &$user_password] )
        |-- apply_filters( 'secure_signon_cookie', ... )
        |-- add_filter( 'authenticate', 'wp_authenticate_cookie', 30, 3 )
        |-- wp_authenticate( $user_login, $user_password )
        |     |
        |     +-- apply_filters( 'authenticate', null, $username, $password )
        |           |
        |           |-- [20] wp_authenticate_username_password()
        |           |-- [20] wp_authenticate_email_password()
        |           |-- [20] wp_authenticate_application_password()
        |           |-- [30] wp_authenticate_cookie()     (added inside wp_signon)
        |           +-- [99] wp_authenticate_spam_check()
        |
        |-- On success: wp_set_auth_cookie()
        |-- Clear user_activation_key if present
        +-- do_action( 'wp_login', $user->user_login, $user )
```

### Notes

- `wp_authenticate_cookie()` is **not** a default global registration in `default-filters.php`; `wp_signon()` adds it at runtime with priority `30`.
- `wp_login_failed` is **not** fired for every failed auth attempt. Core skips it for the `empty_username` and `empty_password` cases inside `wp_authenticate()`.
- Plugins can insert their own logic on `authenticate`, `wp_login`, `set_auth_cookie`, `set_logged_in_cookie`, and `determine_current_user`. See the companion reference on [Two-Factor Plugin Authentication Flow](two-factor-authentication-flow.md) for a concrete example.

## 2. Core `authenticate` Filter Stack

Current core defaults register these `authenticate` callbacks:

```php
add_filter( 'authenticate', 'wp_authenticate_username_password', 20, 3 );
add_filter( 'authenticate', 'wp_authenticate_email_password', 20, 3 );
add_filter( 'authenticate', 'wp_authenticate_application_password', 20, 3 );
add_filter( 'authenticate', 'wp_authenticate_spam_check', 99 );
```

### What they do

- **`wp_authenticate_username_password()`**
  - Looks up the user by login.
  - Applies `wp_authenticate_user`.
  - Verifies the password via `wp_check_password()`.
  - Rehashes when `wp_password_needs_rehash()` says it should.
- **`wp_authenticate_email_password()`**
  - Same pattern, but resolves by email first.
- **`wp_authenticate_application_password()`**
  - Handles REST/XML-RPC or other requests allowed by `application_password_is_api_request`.
  - Accepts Basic Auth credentials for application passwords.
- **`wp_authenticate_spam_check()`**
  - Multisite-only spam-account check.

## 3. Cookie Generation (`wp_set_auth_cookie()`)

`wp_set_auth_cookie()` creates a session token, generates two cookie values, fires pre-send actions, and then sends cookies unless `send_auth_cookies` short-circuits the write.

### Expiration behavior

- **Remember-me login:** default 14-day lifetime, filterable via `auth_cookie_expiration`.
- **Non-remember login:** browser-session cookie (`$expire = 0`), while core still applies `auth_cookie_expiration` internally to enforce a default **2-day** login lifetime.
- **Login grace period:** for persistent cookies, core keeps the browser sending the cookie for another 12 hours after the main expiration so validation can apply grace behavior.

### Session token creation

`WP_Session_Tokens::create( $expiration )`:

- applies `attach_session_information`
- stores `expiration`
- stores `ip` if available
- stores `ua` (user agent) if available
- stores `login` timestamp
- generates a random **43-character** plaintext token
- stores the **SHA-256 verifier** in user meta under `session_tokens`

Default storage backend:

```text
WP_Session_Tokens
  └── WP_User_Meta_Session_Tokens
        └── user meta key: session_tokens
```

The storage backend is replaceable via `session_token_manager`.

### Cookies generated

1. **Auth cookie** (`AUTH_COOKIE` or `SECURE_AUTH_COOKIE`)
   - scheme: `auth` or `secure_auth`
   - paths: `PLUGINS_COOKIE_PATH`, `ADMIN_COOKIE_PATH`
2. **Logged-in cookie** (`LOGGED_IN_COOKIE`)
   - scheme: `logged_in`
   - paths: `COOKIEPATH`, and `SITECOOKIEPATH` when different

Core currently sets these cookies with `httpOnly=true`. The auth-cookie security flags also flow through `secure_auth_cookie` and `secure_logged_in_cookie`.

## 4. Cookie Format and Signature

Generated cookie format:

```text
username|expiration|token|hmac
```

Core derives the cookie HMAC in two steps:

1. **Key derivation**
   - uses `wp_hash( username|pass_frag|expiration|token, $scheme )`
2. **Cookie HMAC**
   - uses `hash_hmac( 'sha256', username|expiration|token, key )`

`pass_frag` is taken from the password hash:

- for legacy phpass (`$P$`) and vanilla bcrypt (`$2y$`) hashes: `substr( ..., 8, 4 )`
- otherwise: `substr( ..., -4 )`

That matters for current core too: modern non-`$2y$` formats fall into the second branch.

### Security properties

- **Password changes invalidate cookies** because `pass_frag` changes.
- **Salt rotation invalidates cookies site-wide** because `wp_hash()` depends on WordPress salts.
- **Per-session binding** comes from the session token verifier stored in user meta.

## 5. Request Validation (`determine_current_user`)

Core currently registers these callbacks on `determine_current_user`:

```php
add_filter( 'determine_current_user', 'wp_validate_auth_cookie' );
add_filter( 'determine_current_user', 'wp_validate_logged_in_cookie', 20 );
add_filter( 'determine_current_user', 'wp_validate_application_password', 20 );
```

### Validation flow

`_wp_get_current_user()` applies `determine_current_user` with `false` as the starting value, then calls `wp_set_current_user()` with the resolved ID (or `0`).

Important details:

- **`wp_validate_auth_cookie()`** is the main callback.
  - Parses the cookie.
  - Applies a **1-hour POST/AJAX grace window** before treating an otherwise valid cookie as expired.
  - Recomputes the HMAC with `hash_equals()`.
  - Verifies the session token via `WP_Session_Tokens::verify()`.
  - Sets `$GLOBALS['login_grace_period']` when the cookie is past its nominal expiration but still allowed by grace rules.
- **`wp_validate_logged_in_cookie()`** is a **front-end fallback**.
  - Returns early if a user ID is already resolved.
  - Bails on `is_blog_admin()`, `is_network_admin()`, or a missing `LOGGED_IN_COOKIE`.
  - Otherwise calls `wp_validate_auth_cookie( $_COOKIE[ LOGGED_IN_COOKIE ], 'logged_in' )`.
- **`wp_validate_application_password()`** only runs if no earlier callback resolved a user and Basic Auth credentials are present.

### XML-RPC note

XML-RPC authentication is special-cased earlier in core. Do not describe `determine_current_user` as the only path for every authenticated request.

## 6. Useful Validation Hooks and Actions

Authentication-cookie validation exposes these hooks:

| Hook | Fires when |
|---|---|
| `auth_cookie_malformed` | Cookie could not be parsed |
| `auth_cookie_expired` | Cookie is expired beyond allowed grace |
| `auth_cookie_bad_username` | User lookup by login failed |
| `auth_cookie_bad_hash` | HMAC mismatch |
| `auth_cookie_bad_session_token` | Session verifier missing or invalid |
| `auth_cookie_valid` | Cookie passed all checks |

Other key auth lifecycle hooks:

| Hook | Fires when |
|---|---|
| `wp_authenticate` | Before `wp_authenticate()` runs |
| `wp_login_failed` | Authentication failed, except empty username/password cases |
| `set_auth_cookie` | Core generated auth cookie value |
| `set_logged_in_cookie` | Core generated logged-in cookie value |
| `clear_auth_cookie` | Core is clearing auth cookies |
| `wp_login` | Successful login after cookies are set |

## 7. Why This Matters to WP Sudo

WP Sudo rides on top of these core guarantees rather than replacing them:

- WordPress core still owns password verification.
- WordPress core still owns auth-cookie generation and validation.
- WordPress core still owns session-token storage and invalidation.

WP Sudo adds a **second, shorter-lived reauthentication session** for sensitive actions, but it depends on the browser already having a valid core-authenticated WordPress session.

For a concrete plugin-level extension of this flow, see:

- [Two-Factor Plugin Authentication Flow](two-factor-authentication-flow.md)
- [Two-Factor Authentication Integration](two-factor-integration.md)

## Sources

Official documentation:

- [wp_signon()](https://developer.wordpress.org/reference/functions/wp_signon/)
- [wp_authenticate()](https://developer.wordpress.org/reference/functions/wp_authenticate/)
- [wp_authenticate_cookie()](https://developer.wordpress.org/reference/functions/wp_authenticate_cookie/)
- [wp_set_auth_cookie()](https://developer.wordpress.org/reference/functions/wp_set_auth_cookie/)
- [wp_generate_auth_cookie()](https://developer.wordpress.org/reference/functions/wp_generate_auth_cookie/)
- [wp_validate_auth_cookie()](https://developer.wordpress.org/reference/functions/wp_validate_auth_cookie/)
- [wp_validate_logged_in_cookie()](https://developer.wordpress.org/reference/functions/wp_validate_logged_in_cookie/)
- [wp_validate_application_password()](https://developer.wordpress.org/reference/functions/wp_validate_application_password/)
- [wp_authenticate_application_password()](https://developer.wordpress.org/reference/functions/wp_authenticate_application_password/)
- [WP_Session_Tokens](https://developer.wordpress.org/reference/classes/wp_session_tokens/)
- [Application Passwords](https://developer.wordpress.org/advanced-administration/security/application-passwords/)
- [Cookies](https://developer.wordpress.org/advanced-administration/wordpress/cookies/)

Primary source code checked on 2026-03-31:

- `wp-includes/user.php`
- `wp-includes/pluggable.php`
- `wp-includes/default-filters.php`
- `wp-includes/class-wp-session-tokens.php`
- `wp-includes/class-wp-user-meta-session-tokens.php`
