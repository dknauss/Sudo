# Psudo Lite

> **Status: illustrative reference only — not production-secure, do not deploy.**
> These files are a teaching baseline for cross-checking WP Sudo's design against a
> minimal reauthentication model. They are **not** a shippable plugin and **not** a
> roadmap toward one. See "Security model and known limitations" below, and use the
> WP Sudo plugin for real enforcement.

These files are standalone reference implementations derived from the original Psudo gist:

- `psudo-lite-snippet.php` — snippet / mu-plugin version with no settings UI.
- `psudo-lite.php` — single-file plugin version with a minimal Settings → Psudo Lite duration field.

Both versions intentionally protect only interactive `wp-admin` page loads. They do not attempt to comprehensively gate AJAX, REST, CLI, cron, XML-RPC, application passwords, or effect-level destructive operations. Use the full WP Sudo plugin for that broader threat model.

## Scope

Psudo Lite protects selected admin page filenames such as `plugins.php`, `themes.php`, `users.php`, settings screens, tools screens, update-core, and Site Health. The protected page list is filterable with `psudo_lite_protected_pages`; the final request decision is filterable with `psudo_lite_is_protected_admin_request`.

## Security model and known limitations

The intended model:

- Uses WordPress forced reauthentication via `wp_login_url( $redirect_to, true )`.
- Does not forcibly log the user out.
- Binds the grant to the current WordPress session token hash.
- Stores only the grant timestamp and session-token hash in user meta.
- Validates redirects back to the current site.

**Known limitations — why this is a reference, not a deployable guard:**

- The grant is issued on a pre-login nonce embedded in the return URL, not on
  proof that a password was actually re-entered. A stolen session cookie can read
  that nonce from the redirect and replay the return URL to grant the window with
  no password. A real implementation needs evidence from the *completed*
  login/reauth (e.g. a one-time token set on the `wp_login` hook and consumed on
  return), not just a nonce.
- WordPress nonces are bound to the session token, which rotates on a genuine
  `wp-login.php?reauth=1` login — so after a real reauth the pre-login nonce fails
  to verify and the intended flow does not grant at all.
- Gating is a `$pagenow` allow-list of interactive screens; it does not cover the
  Settings-API save (`options.php`) or the plugin/theme install and upgrade
  endpoints (`update.php?action=...`), so an admin who opened a protected screen
  can still complete the effect after the window expires.

Fixing these means solving the same problems WP Sudo already solves — proof of
completed reauth, state that survives token rotation, and effect-level gating — at
which point the "lite" version converges on WP Sudo. These files are therefore
kept as an illustrative/teaching reference, not evolved toward a shipped plugin.

## Snippet configuration

Define `PSUDO_LITE_DURATION_MINUTES` before loading the snippet, or use the `psudo_lite_duration` filter.

```php
define( 'PSUDO_LITE_DURATION_MINUTES', 5 );
```

Example protected-page filter:

```php
add_filter( 'psudo_lite_protected_pages', function ( array $pages ): array {
    $pages[] = 'admin.php';
    return $pages;
} );
```
