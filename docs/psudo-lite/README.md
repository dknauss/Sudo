# Psudo Lite

These files are standalone reference implementations derived from the original Psudo gist:

- `psudo-lite-snippet.php` — snippet / mu-plugin version with no settings UI.
- `psudo-lite.php` — single-file plugin version with a minimal Settings → Psudo Lite duration field.

Both versions intentionally protect only interactive `wp-admin` page loads. They do not attempt to comprehensively gate AJAX, REST, CLI, cron, XML-RPC, application passwords, or effect-level destructive operations. Use the full WP Sudo plugin for that broader threat model.

## Scope

Psudo Lite protects selected admin page filenames such as `plugins.php`, `themes.php`, `users.php`, settings screens, tools screens, update-core, and Site Health. The protected page list is filterable with `psudo_lite_protected_pages`; the final request decision is filterable with `psudo_lite_is_protected_admin_request`.

## Security model

- Uses WordPress forced reauthentication via `wp_login_url( $redirect_to, true )`.
- Does not forcibly log the user out.
- Grants the temporary window only after returning with a valid nonce.
- Binds the grant to the current WordPress session token hash.
- Stores only the grant timestamp and session-token hash in user meta.
- Validates redirects back to the current site.

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
