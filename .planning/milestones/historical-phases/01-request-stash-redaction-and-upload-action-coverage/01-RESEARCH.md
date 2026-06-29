# Phase 01: Request Stash Redaction and Upload Action Coverage - Research

**Researched:** 2026-03-03
**Domain:** PHP security hardening — WordPress transient data minimization and admin action gating
**Confidence:** HIGH (all findings verified directly against production source code)

---

## Summary

This phase addresses two confirmed security gaps in WP Sudo's production code, both verified against live source files.

**Gap 1 — Stash data exposure:** `Request_Stash::sanitize_params()` at `class-request-stash.php:205-211` returns `$_POST` verbatim. Passwords (`pass1`, `pass2`, `user_pass`), tokens, API keys, and other secrets entered in WordPress admin forms are serialized into WordPress transients (`wp_options`). This table is accessible to any code with database read access, all backup systems, and object cache backends (Memcached, Redis) that may not be encrypted at rest.

**Gap 2 — Missing upload rules:** `Action_Registry` has rules for `install-plugin` and `install-theme` (WordPress.org directory installs via `update.php`) but no rules for `upload-plugin` and `upload-theme` (ZIP file upload paths, also via `update.php`). Both are POST requests to `update.php` with the action in `$_GET['action']`, confirmed from WordPress source. A session that has not been challenged can upload arbitrary plugin or theme ZIPs.

**Gap 3 — Unbounded stash growth:** No per-user stash cap exists. An authenticated user could trigger repeated gated-action intercepts, accumulating stash transients in `wp_options`. This is an authenticated insider threat (not internet-facing), but bounded growth is correct hygiene.

**Critical architectural constraint:** Sensitive fields (passwords, tokens) cannot be replaced with a sentinel value for replay. The JS replay mechanism in `wp-sudo-challenge.js:291-299` builds a self-submitting hidden form with all `post_data` fields verbatim. A sentinel like `__REDACTED__` in `pass1` would be submitted to WordPress's profile update handler, which would either set the sentinel string as the new password or silently discard it. The correct behavior is to **omit** redacted fields entirely from the stash (they are not stored at all), not replace them with a placeholder. This preserves the password-change flow: the user who successfully reauthenticates via the sudo challenge re-submits the form manually (the replay just navigates back to the same admin page with a now-active sudo session, not a pre-filled form).

**Primary recommendation:** Implement field-key-based redaction that omits matching keys before storage; add two new registry rules (`plugin.upload` and `theme.upload`); add a per-user stash index (user meta) with max-5 eviction. All three are independent, self-contained changes with clear test boundaries.

---

## Standard Stack

### Core (this project uses these already)
| Component | Version | Purpose | Notes |
|-----------|---------|---------|-------|
| WordPress transients API | n/a | Stash storage | `set_transient` / `set_site_transient` (multisite) |
| WordPress user meta API | n/a | Per-user stash key index | `get_user_meta` / `update_user_meta` — same pattern as `Sudo_Session` |
| `apply_filters` | n/a | Filterable sensitive-key list | Follows existing `wp_sudo_critical_options` pattern |
| Brain\Monkey | 2.x | Unit test mocking | Already used in all unit tests |
| PHPUnit 9.6 | 9.6 | Test runner | Already configured |

### No new dependencies
Zero new `composer.json` entries are needed. All functionality uses WordPress APIs already present and tested in this codebase.

---

## Architecture Patterns

### Pattern 1: Sensitive field redaction in `sanitize_params()`

**What:** Replace the current pass-through with a recursive, case-insensitive key matcher. Keys matching the sensitive list are dropped (not replaced with a sentinel). Non-sensitive fields pass through unchanged.

**When to use:** On every `save()` call, applied to `$_POST` only (GET parameters never contain secrets).

**Source:** Verified against `class-request-stash.php:205-211` and `class-challenge.php:528-538` (replay_stash sends `post_data` directly to JS).

```php
// Source: class-request-stash.php — new sanitize_params() implementation
private function sanitize_params( array $params ): array {
    $sensitive = $this->sensitive_field_keys();

    $result = array();
    foreach ( $params as $key => $value ) {
        if ( is_array( $value ) ) {
            $result[ $key ] = $this->sanitize_params( $value );
        } elseif ( ! in_array( strtolower( (string) $key ), $sensitive, true ) ) {
            $result[ $key ] = $value;
        }
        // Sensitive keys are omitted entirely — not stored, not sent to JS replay.
    }
    return $result;
}

private function sensitive_field_keys(): array {
    /**
     * Filter the list of POST parameter keys that should be
     * omitted from the request stash before storage.
     *
     * Keys are matched case-insensitively. Nested array keys are
     * also matched. Omitted fields are not replayed — the user
     * must re-enter them after reauthentication.
     *
     * @since 2.11.0
     *
     * @param string[] $keys Sensitive field key names (lowercase).
     */
    return apply_filters(
        'wp_sudo_sensitive_stash_keys',
        array(
            'password',
            'user_pass',
            'pass1',
            'pass2',
            'pass1-text',
            'pwd',
            'token',
            'secret',
            'api_key',
            'api_secret',
            'auth_key',
            'auth_token',
            'access_token',
            'private_key',
        )
    );
}
```

### Pattern 2: Per-user stash index with eviction (user meta)

**What:** A user meta key (`_wp_sudo_stash_keys`) stores a JSON-encoded array of stash keys for the user. On every `save()`, the key is appended. If the count exceeds `MAX_STASH_PER_USER` (5), the oldest key's transient is deleted before the new one is stored.

**When to use:** Applied in `save()` after the transient is written.

**Source:** Follows the exact same `get_user_meta` / `update_user_meta` pattern used in `class-sudo-session.php:693-699` for lockout tracking. On multisite, `user_meta` is global (network-wide) so no `switch_to_blog` is needed — same as `Sudo_Session::activate()`.

```php
// Source: class-request-stash.php — new constant and index methods
public const MAX_STASH_PER_USER = 5;
public const STASH_INDEX_META_KEY = '_wp_sudo_stash_keys';

private function enforce_stash_cap( int $user_id ): void {
    $keys = $this->get_stash_index( $user_id );

    while ( count( $keys ) >= self::MAX_STASH_PER_USER ) {
        $oldest = array_shift( $keys );
        $this->delete_stash_transient( self::TRANSIENT_PREFIX . $oldest );
    }

    $this->set_stash_index( $user_id, $keys );
}

private function add_to_stash_index( int $user_id, string $key ): void {
    $keys   = $this->get_stash_index( $user_id );
    $keys[] = $key;
    $this->set_stash_index( $user_id, $keys );
}

private function get_stash_index( int $user_id ): array {
    $raw = get_user_meta( $user_id, self::STASH_INDEX_META_KEY, true );
    return is_array( $raw ) ? $raw : array();
}

private function set_stash_index( int $user_id, array $keys ): void {
    update_user_meta( $user_id, self::STASH_INDEX_META_KEY, $keys );
}
```

**Integration order in `save()`:**
1. `enforce_stash_cap()` — evict if at cap before writing new transient
2. `set_stash_transient()` — write the new stash
3. `add_to_stash_index()` — record the key in user meta

Also: `delete()` must remove the key from the index when consuming a stash (one-time use in `Challenge::replay_stash()`).

### Pattern 3: Upload action rules in `Action_Registry`

**What:** Two new rules — `plugin.upload` and `theme.upload` — in the `rules()` method. Both are POST to `update.php` with the action in `$_GET['action']` (the form encodes the action in the URL query string, not as a POST field).

**Verified from WordPress source:** `plugin-install.php` (via `install_plugins_upload()` in `wp-admin/includes/plugin-install.php`) renders: `<form method="post" ... action="update.php?action=upload-plugin">`. Likewise `theme-install.php` renders: `<form method="post" ... action="update.php?action=upload-theme">`. WordPress sets `$pagenow` from the script filename, so `$pagenow = 'update.php'` on both upload submissions.

```php
// Source: class-action-registry.php — to add in rules() after plugin.install

array(
    'id'       => 'plugin.upload',
    'label'    => __( 'Upload plugin', 'wp-sudo' ),
    'category' => 'plugins',
    'admin'    => array(
        'pagenow' => 'update.php',
        'actions' => array( 'upload-plugin' ),
        'method'  => 'POST',
    ),
    'ajax'     => null,
    'rest'     => null,
),

array(
    'id'       => 'theme.upload',
    'label'    => __( 'Upload theme', 'wp-sudo' ),
    'category' => 'themes',
    'admin'    => array(
        'pagenow' => 'update.php',
        'actions' => array( 'upload-theme' ),
        'method'  => 'POST',
    ),
    'ajax'     => null,
    'rest'     => null,
),
```

**Why `method: 'POST'`:** The upload forms use `method="post"` and `enctype="multipart/form-data"`. The action value (`upload-plugin`, `upload-theme`) is in the URL query string (`$_GET['action']`), not in `$_POST`. The Gate's `matches_admin()` reads `$_REQUEST['action']`, which merges `$_GET` and `$_POST`, so this is correctly matched.

**Why no AJAX or REST:** WordPress does not support file-upload ZIPs via AJAX or the REST API. The upload path is exclusively the admin form POST to `update.php`. The Plugins REST API (`/wp/v2/plugins`) supports ZIP upload via a different mechanism (multipart REST request) which is already partially covered by the `plugin.install` REST rule. No change needed for REST.

### Recommended `save()` method final structure

```php
public function save( int $user_id, array $matched_rule ): string {
    $key = wp_generate_password( self::KEY_LENGTH, false );

    // Enforce per-user cap BEFORE writing (evicts oldest if at limit).
    $this->enforce_stash_cap( $user_id );

    $data = array(
        'user_id' => $user_id,
        'rule_id' => $matched_rule['id'] ?? '',
        'label'   => $matched_rule['label'] ?? '',
        'method'  => $this->get_request_method(),
        'url'     => $this->build_original_url(),
        'get'     => $this->sanitize_params( $_GET ),  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        'post'    => $this->sanitize_params( $_POST ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
        'created' => time(),
    );

    $this->set_stash_transient( self::TRANSIENT_PREFIX . $key, $data, self::TTL );

    // Record key in user index AFTER successful write.
    $this->add_to_stash_index( $user_id, $key );

    return $key;
}
```

### Recommended `delete()` method update

```php
public function delete( string $key, int $user_id = 0 ): void {
    if ( ! empty( $key ) ) {
        $this->delete_stash_transient( self::TRANSIENT_PREFIX . $key );

        if ( $user_id > 0 ) {
            $this->remove_from_stash_index( $user_id, $key );
        }
    }
}
```

Note: `Challenge::replay_stash()` calls `$this->stash->delete( $stash_key )` (line 503) without user_id. Options: (a) pass user_id to delete(), (b) look up the stash first (already done at line 489), (c) make user_id optional and skip index cleanup if 0. Option (c) is backward-compatible — index self-corrects when `enforce_stash_cap()` processes non-existent transients, but a dangling key in the index for up to 5 minutes is acceptable since the transient is gone. Option (a) is cleaner if `Challenge::replay_stash()` can be updated at the same time — the method already has `$user_id`.

**Recommendation:** Pass `$user_id` to `delete()` from `replay_stash()`. The Challenge already holds `$user_id` at that call site.

### Anti-Patterns to Avoid

- **Sentinel replacement (`__REDACTED__`):** Do not store a placeholder. The JS replay form submits all `post_data` fields as-is. A sentinel password value would be submitted as the actual new password. Omit entirely.
- **Sanitizing GET params for secrets:** `$_GET` parameters are not typically secrets (they are in URLs), but the recursive `sanitize_params()` applies to both. This is safe — `$_GET` rarely contains password-like keys, but applying consistently avoids a logic split.
- **Redacting the entire POST for password-bearing actions:** Do not suppress the whole stash for `user.change_password`. The non-password fields (nonce, user ID, email, bio) are safe to stash. The password fields are omitted; on replay, WordPress's profile update handler will see the form without `pass1`/`pass2` and treat it as "no password change" — which is the correct UX (user re-submits password fields after authentication).
- **Making `MAX_STASH_PER_USER` a filter:** The cap is a server-side security bound. Making it filterable allows reduction of the cap but could also allow inflation by third-party code. Keep it a constant; document via code comment.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Key storage for stash index | Custom DB table | `update_user_meta` | Already used by Sudo_Session; network-aware on multisite; cleaned up by existing `uninstall.php` `delete_metadata` call |
| Case-insensitive key matching | Custom regex engine | `strtolower()` + `in_array()` | Sufficient for known static key list; no regex overhead |
| Transient TTL-based eviction | Cron cleanup job | `enforce_stash_cap()` eviction at write time | Simpler; transients already have TTL; no scheduling needed |
| WordPress upload-plugin route detection | Parsing `$_FILES` or MIME type | Registry rule on `$pagenow` + action | Consistent with all other rules; fires at Gate's `admin_init` hook before `update.php` processes the upload |

---

## Common Pitfalls

### Pitfall 1: Stash index diverging from actual transients

**What goes wrong:** User meta index contains a key for a transient that has already expired naturally (TTL=300s). `enforce_stash_cap()` calls `delete_stash_transient()` on an already-expired key — `delete_transient()` returns false but this is harmless. Index grows stale.

**Why it happens:** The stash index is user meta (no TTL); transients have TTL. They can diverge.

**How to avoid:** `enforce_stash_cap()` should call `delete_stash_transient()` unconditionally (no check for existence first). Stale keys are evicted naturally when the cap is hit. Cap count is an upper bound, not an exact count of live stashes.

**Warning signs:** Index grows beyond 5 entries (bug in eviction logic).

### Pitfall 2: `delete()` signature change breaking `Challenge::replay_stash()`

**What goes wrong:** Adding `int $user_id = 0` to `delete()` is backward-compatible, but `replay_stash()` still passes only `$stash_key`. The stash index is not cleaned on replay, leaving a dangling key in user meta.

**Why it happens:** `replay_stash()` calls `$this->stash->delete( $stash_key )` (one argument). The transient is gone, but the index still has the key.

**How to avoid:** Update `replay_stash()` at the same time: `$this->stash->delete( $stash_key, $user_id )`. The `$user_id` variable is already in scope in `replay_stash()`.

**Warning signs:** User meta index grows without bound (index cleanup not happening on replay).

### Pitfall 3: Redacting `$_GET` params unintentionally stripping `plugin` slug

**What goes wrong:** The `plugin` key in `$_GET` (`?plugin=hello.php`) is NOT in the sensitive list, but a custom filter might add overly broad patterns. The URL is already stashed in `$data['url']` which includes the full query string, so GET params are somewhat redundant — but stripping legitimate GET params breaks replay for GET-method rules.

**Why it happens:** Overly broad sensitive key patterns (e.g., `pass` matching `pagenow` or a plugin key).

**How to avoid:** The default sensitive key list uses full key names, not substrings. `strtolower($key)` comparison is exact match via `in_array()`, not `str_contains()`. Document this in the filter docblock.

### Pitfall 4: Upload rule firing on the "overwrite" prompt, not the initial upload

**What goes wrong:** `update.php` also handles `action=upload-plugin-cancel-overwrite` for canceling a plugin overwrite. This action is a GET request (cancel link, not form submit) and is not sensitive — it cancels the upload, not installs it. However, `upload-plugin` fires on the initial POST upload AND potentially on the overwrite-confirm step.

**Why it happens:** WordPress's upload flow has three steps: initial upload form submission, overwrite prompt, and overwrite confirmation. The initial submission (`action=upload-plugin`) lands at `update.php` and processes the upload directly (no overwrite step unless a plugin with the same slug exists).

**How to avoid:** The `plugin.upload` rule matches `action=upload-plugin` with `method=POST`. The overwrite-cancel step uses `action=upload-plugin-cancel-overwrite` (different action string, GET method). No false positives from the overwrite flow. The overwrite-confirm step reuses `action=upload-plugin` (per `Plugin_Installer_Skin::feedback()`) — this is correct behavior; overwriting an existing plugin IS a privileged action that should be gated.

### Pitfall 5: `uninstall.php` not cleaning stash index meta

**What goes wrong:** `uninstall.php` already calls `delete_metadata( 'user', 0, '_wp_sudo_stash_keys', '', true )` ... but it doesn't currently, because this key doesn't exist yet.

**Why it happens:** New meta key added in this phase; uninstall.php is not updated.

**How to avoid:** Add `delete_metadata( 'user', 0, '_wp_sudo_stash_keys', '', true )` to `wp_sudo_cleanup_user_meta()` in `uninstall.php` in the same PR. This is a 1-line addition.

---

## Code Examples

### Existing `sanitize_params()` (current state — the problem)

```php
// Source: includes/class-request-stash.php:205-212
private function sanitize_params( array $params ): array {
    // We store verbatim because these parameters will be replayed
    // to the same WordPress handler that expected them.
    return $params;  // <-- passwords stored here
}
```

### Existing `save()` call site (current state)

```php
// Source: includes/class-request-stash.php:64-66
'get'     => $this->sanitize_params( $_GET ),
'post'    => $this->sanitize_params( $_POST ), // passwords go in here verbatim
```

### Existing registry rule pattern (model for new rules)

```php
// Source: includes/class-action-registry.php:111-127
array(
    'id'       => 'plugin.install',
    'label'    => __( 'Install plugin', 'wp-sudo' ),
    'category' => 'plugins',
    'admin'    => array(
        'pagenow' => 'update.php',
        'actions' => array( 'install-plugin' ),
        'method'  => 'ANY',
    ),
    'ajax'     => array(
        'actions' => array( 'install-plugin' ),
    ),
    'rest'     => array(
        'route'   => '#^/wp/v2/plugins$#',
        'methods' => array( 'POST' ),
    ),
),
```

The new `plugin.upload` rule is identical in structure to `plugin.install` except:
- `actions` = `array( 'upload-plugin' )`
- `method` = `'POST'` (upload is always POST, not ANY)
- `ajax` = `null` (no AJAX path for file uploads)
- `rest` = `null` (separate REST upload path already covered by existing REST rule)

### Existing filter docblock pattern (model for new filter)

```php
// Source: includes/class-action-registry.php:719-727
/**
 * Filter the list of option names considered critical.
 *
 * @since 2.0.0
 *
 * @param string[] $options Critical option names.
 */
return apply_filters( 'wp_sudo_critical_options', array( ... ) );
```

### Existing user meta pattern (model for stash index)

```php
// Source: includes/class-sudo-session.php:693-699
update_user_meta( $user_id, self::LOCKOUT_META_KEY, $attempts );
if ( $attempts >= self::MAX_ATTEMPTS ) {
    update_user_meta(
        $user_id,
        self::LOCKOUT_UNTIL_META_KEY,
        time() + self::LOCKOUT_DURATION
    );
}
```

### JS replay form builder (why omit, not sentinel)

```javascript
// Source: admin/js/wp-sudo-challenge.js:291-299
var postData = data.post_data || {};
appendFields( form, postData, '' );
// ...
HTMLFormElement.prototype.submit.call( form );
// All keys in post_data are submitted verbatim.
// A sentinel value WOULD be submitted as the field value.
```

---

## State of the Art

| Old Approach | Current Approach | Status |
|--------------|------------------|--------|
| Store `$_POST` verbatim (current) | Omit sensitive keys before storage | To implement |
| No upload rules (current) | Add `plugin.upload` + `theme.upload` | To implement |
| Unlimited stash transients (current) | Per-user max-5 with oldest-first eviction | To implement |

---

## Open Questions

1. **`delete()` signature: optional `$user_id` vs always required**
   - What we know: `Challenge::replay_stash()` calls `delete()` with only the stash key. The Challenge has `$user_id` in scope.
   - What's unclear: Should `$user_id = 0` be a required parameter (cleaner API) or optional (backward-compatible for any third-party code calling `delete()` directly)?
   - Recommendation: Make `$user_id` optional (default 0, skip index cleanup if 0). Document that passing user_id is preferred. Update `Challenge::replay_stash()` to pass `$user_id`.

2. **Stash cap constant vs filter**
   - What we know: A constant is a security bound; a filter can be overridden.
   - What's unclear: Whether any real use case needs more than 5 concurrent stashes per user.
   - Recommendation: Keep as a constant (`MAX_STASH_PER_USER = 5`). Add a code comment explaining it is intentionally not filterable. Five is generous — in normal use, a user triggers one stash at a time.

3. **GET param redaction**
   - What we know: The current `sanitize_params()` is applied to both `$_GET` and `$_POST`. GET parameters do not typically contain secrets; they are in the URL.
   - What's unclear: Whether applying redaction to GET introduces any risk of stripping legitimate routing parameters.
   - Recommendation: Apply to both (consistent behavior) but note in docblock that GET params rarely contain sensitive data. The default sensitive key list does not contain common GET param names (`plugin`, `action`, `page`, `tab`, etc.).

---

## Test Plan Overview

### Unit tests (new/modified — `tests/Unit/RequestStashTest.php`)

- `test_save_omits_sensitive_post_fields` — `pass1`, `user_pass`, `token` are absent from stored data
- `test_save_preserves_non_sensitive_post_fields` — `user_login`, `email`, `_wpnonce` are present
- `test_sensitive_field_keys_returns_list` — verifies default list has expected entries
- `test_sensitive_keys_matched_case_insensitively` — `PASS1`, `Password`, `USER_PASS` all omitted
- `test_sensitive_keys_are_filterable` — `wp_sudo_sensitive_stash_keys` filter adds a custom key
- `test_nested_sensitive_fields_are_omitted` — `$_POST['user']['pass1']` is omitted
- `test_save_enforces_stash_cap` — sixth save evicts the oldest key transient
- `test_save_records_key_in_stash_index` — `get_user_meta` is called with `STASH_INDEX_META_KEY`
- `test_delete_removes_key_from_stash_index` — index is updated after delete

### Unit tests (new — `tests/Unit/ActionRegistryTest.php`)

- `test_plugin_upload_rule_exists` — `Action_Registry::find('plugin.upload')` not null
- `test_plugin_upload_rule_matches_update_php_post` — pagenow `update.php`, action `upload-plugin`, method `POST`
- `test_theme_upload_rule_exists` — `Action_Registry::find('theme.upload')` not null
- `test_theme_upload_rule_matches_update_php_post` — pagenow `update.php`, action `upload-theme`, method `POST`
- `test_plugin_upload_rule_has_no_ajax_surface` — `$rule['ajax']` is null
- `test_theme_upload_rule_has_no_ajax_surface` — `$rule['ajax']` is null

### Integration tests (new — `tests/Integration/RequestStashTest.php`)

- `test_save_omits_passwords_from_stored_transient` — raw transient read confirms no `pass1` key
- `test_save_non_sensitive_fields_survive_redaction` — `user_login` present in raw transient
- `test_stash_cap_evicts_oldest` — save 6 stashes, verify first transient deleted, only 5 remain in index
- `test_delete_with_user_id_removes_from_index` — after delete, index has one fewer entry

---

## Sources

### Primary (HIGH confidence — verified against source)
- `includes/class-request-stash.php` — `sanitize_params()` at lines 205-212 (verbatim return); `save()` at lines 56-73; `delete()` at lines 110-114; `replay_stash()` consumer pattern
- `includes/class-action-registry.php` — `rules()` method (confirmed no `upload-plugin`/`upload-theme` entries); rule structure for `plugin.install` as template
- `includes/class-challenge.php` — `replay_stash()` at lines 488-538; JS post_data handling
- `admin/js/wp-sudo-challenge.js` — `appendFields()` at lines 311-322; form submission at lines 291-299
- `includes/class-sudo-session.php` — user meta pattern at lines 693-699; meta key constants
- `uninstall.php` — `wp_sudo_cleanup_user_meta()` at lines 66-71; existing `delete_metadata` calls
- Local WordPress install (`wp-admin/includes/plugin-install.php`) — `install_plugins_upload()` form action `update.php?action=upload-plugin` (POST)
- Local WordPress install (`wp-admin/includes/theme-install.php`) — `install_themes_upload()` form action `update.php?action=upload-theme` (POST)
- Local WordPress install (`wp-admin/update.php`) — `'upload-plugin' === $action` and `'upload-theme' === $action` branch handling
- `tests/Unit/RequestStashTest.php` — existing test patterns, Brain\Monkey mock structure
- `tests/Unit/ActionRegistryTest.php` — existing rule test patterns
- `tests/Integration/RequestStashTest.php` — existing integration test structure

### Secondary (MEDIUM confidence)
- `ROADMAP.md` section 12 — confirmed priority, confirmed specific field names in sensitive list, confirmed cap=5 recommendation
- `.planning/review/03-03-2026/PROPOSED-NEXT-STEPS-Claude.md` — confirmed all technical claims verified, architectural constraints documented

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — no new dependencies; uses established WordPress APIs already in use
- Architecture patterns: HIGH — verified against live source code, JS replay constraints confirmed
- Pitfalls: HIGH — derived directly from reading production code paths (Challenge.php replay, JS form builder, uninstall.php)
- Upload rule structure: HIGH — verified against local WordPress install source files
- Stash cap eviction: HIGH — follows existing Sudo_Session user meta pattern exactly

**Research date:** 2026-03-03
**Valid until:** 2026-06-03 (WordPress APIs are stable; no upcoming changes in WP 7.0 affect these files)
