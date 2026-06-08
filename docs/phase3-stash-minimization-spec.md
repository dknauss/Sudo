# Phase 3 — Request-Stash Minimization (F4 + F9)

**Status:** Ready for implementation  
**Findings:** F4 (suffix-based redaction), F9 (stash captures `$_GET` unnecessarily)  
**Severity:** LOW — both require a Sudo session to exploit; impact is cleartext secrets
persisting in transients for up to 5 minutes.  
**Prerequisite:** All Phase 1/2 work is merged. Run `git log --oneline v3.1.3..main`
to confirm.

---

## Context

`includes/class-request-stash.php` serializes the intercepted HTTP request into
a short-lived transient (5 min TTL). After reauthentication, `class-challenge.php`
replays the stored `post` body. Two problems:

### F9 — `$_GET` is stashed but never replayed

`save()` stores both `$_GET` and `$_POST`. The replay path in
`build_replay_response_data()` sends `get_data` to the browser JS, but
`handleReplay()` in `admin/js/wp-sudo-challenge.js` (line 333) only reads
`post_data` — `get_data` is never appended to the replay form. For GET requests
the JS does a plain redirect to `data.redirect` (the stored `url`, which already
contains the full query string). `$_GET` is therefore dead weight in every stash
entry: stored for 5 minutes, never used.

**Fix:** Remove `$_GET` from `save()` and `get_data` from `build_replay_response_data()`.

### F4 — Secret redaction uses exact key match, misses compound names

`sanitize_params()` calls `in_array(strtolower($key), $sensitive, true)` where
`$sensitive` is the exact-match list from `sensitive_field_keys()`. Field names
like `connectors_openai_api_key`, `stripe_secret_key`, `smtp_password`,
`oauth_access_token` are not redacted — they pass through as cleartext.

**Fix:** Add a suffix-matching pass after the exact-match check so any field
whose name ends with a recognized high-signal suffix is also redacted.

---

## Implementation

Work in two TDD cycles. Commit F9 first (it has no test ambiguity), then F4.

### Cycle A — F9: Drop `$_GET` from stash

#### Files to change

| File | Change |
|---|---|
| `includes/class-request-stash.php` | Remove `'get' => …` line from `save()` |
| `includes/class-challenge.php` | Remove `'get_data' => …` line from `build_replay_response_data()` |
| `tests/Unit/RequestStashTest.php` | Update tests that assert on `$stored_data['get']` |

#### Exact edits

**`includes/class-request-stash.php`, `save()` (~line 87–98):**

Remove:
```php
'get'                     => $this->sanitize_params( $_GET, $redacted_fields_omitted ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
```

After removal the `$data` array contains `user_id`, `rule_id`, `label`, `method`,
`url`, `return_url`, `post`, `redacted_fields_omitted`, `created`. No `get` key.

**`includes/class-challenge.php`, `build_replay_response_data()` (~line 814–821):**

Remove:
```php
'get_data'  => $stash['get'] ?? array(),
```

The return array for POST replay becomes:
```php
return array(
    'code'      => 'success',
    'replay'    => true,
    'method'    => $stash['method'],
    'url'       => $safe_url,
    'post_data' => $stash['post'] ?? array(),
);
```

#### Tests to update

`tests/Unit/RequestStashTest.php` has several tests that assert `$stored_data['get']`
exists. These assertions must be removed or changed to `assertArrayNotHasKey('get', ...)`.
Grep for `stored_data['get']` and `'get'` in that file to find all occurrences. Key
locations at approximately lines 150, 173, 199, 280.

Also add one new test to confirm `get` is absent:

```php
public function test_save_does_not_store_get_params(): void {
    $this->stub_stash_index_meta_io();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST']      = 'example.com';
    $_SERVER['REQUEST_URI']    = '/wp-admin/user-new.php?action=createuser';
    $_GET  = array( 'action' => 'createuser' );
    $_POST = array( 'user_login' => 'newuser', '_wpnonce' => 'abc123' );

    Functions\expect( 'wp_generate_password' )->once()->andReturn( 'getdroptest1234' );
    Functions\expect( 'is_ssl' )->once()->andReturn( false );
    Functions\when( 'esc_url_raw' )->returnArg();
    Functions\when( 'apply_filters' )->returnArg( 2 );

    $stored_data = null;
    Functions\expect( 'set_transient' )
        ->once()
        ->andReturnUsing( function ( $name, $data ) use ( &$stored_data ) {
            $stored_data = $data;
            return true;
        } );

    $this->stash->save( 1, array( 'id' => 'user.create', 'label' => 'Create user' ) );

    $this->assertArrayNotHasKey( 'get', $stored_data, '$_GET must not be stored in stash.' );
    $this->assertArrayHasKey( 'post', $stored_data );
    $this->assertArrayHasKey( 'url', $stored_data, 'Full URL (with query string) must still be stored.' );

    unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
    $_GET  = array();
    $_POST = array();
}
```

**Acceptance criteria for Cycle A:**
- `save()` no longer stores a `get` key.
- `build_replay_response_data()` no longer returns `get_data`.
- All updated tests pass: `composer test:unit`.
- PHPStan level 6 clean: `composer analyse`.
- PHPCS clean: `composer lint`.

---

### Cycle B — F4: Suffix-based redaction for compound field names

#### Approach

Add `is_sensitive_key(string $key, array $sensitive): bool` as a private method
on `Request_Stash`. Replace the `in_array(…)` call in `sanitize_params()` with a
call to this new method.

`is_sensitive_key` must:
1. Check the existing exact-match list (current behavior, preserved).
2. If no exact match, check whether the lowercased key **ends with** any entry in
   a static suffix list.

#### Suffix list

The suffix list is intentionally conservative — only high-signal endings that are
unambiguously secrets:

```php
private const SENSITIVE_KEY_SUFFIXES = array(
    // Snake_case compound endings (most common in WordPress/PHP plugins).
    '_password',
    '_pass',
    '_api_key',
    '_secret_key',
    '_secret',
    '_private_key',
    '_access_token',
    '_auth_token',
    '_api_token',
    '_api_secret',
    // CamelCase word endings (lowercased for comparison).
    // These match e.g. stripeApiKey → stripeapikey ends with 'apikey'.
    'apikey',
    'secretkey',
    'privatekey',
    'accesstoken',
    'authtoken',
    'apitoken',
    'apisecret',
);
```

Note: `password` alone is already in the exact-match list, so suffix matching
adds `_password` (with underscore) and bare `password` is covered by exact match.
For camelCase `smtpPassword` → lowercase `smtppassword` → ends with `password`
(which is also in the exact-match list via the camelCase suffix or direct match —
add `password` without underscore to the suffix list too for camelCase coverage).

**Revised suffix list** (replace the above with this final version):

```php
private const SENSITIVE_KEY_SUFFIXES = array(
    '_password',
    '_pass',
    '_api_key',
    '_secret_key',
    '_secret',
    '_private_key',
    '_access_token',
    '_auth_token',
    '_api_token',
    '_api_secret',
    'password',
    'apikey',
    'secretkey',
    'privatekey',
    'accesstoken',
    'authtoken',
    'apitoken',
    'apisecret',
);
```

Adding bare `password` does not cause false positives — any field ending in
`password` (e.g. `smtppassword`, `ftppassword`, `oldpassword`) should be redacted.

#### New private method

```php
/**
 * Whether a field key should be treated as sensitive.
 *
 * Checks the exact-match sensitive key list first, then falls back to
 * a suffix scan for compound names like connectors_openai_api_key or
 * stripeSecretKey that would not appear in the exact-match list.
 *
 * @since 3.2.0
 *
 * @param string   $key       Field key, any case.
 * @param string[] $sensitive Exact-match sensitive key list (lowercase).
 * @return bool
 */
private function is_sensitive_key( string $key, array $sensitive ): bool {
    $lower = strtolower( $key );

    if ( in_array( $lower, $sensitive, true ) ) {
        return true;
    }

    foreach ( self::SENSITIVE_KEY_SUFFIXES as $suffix ) {
        if ( str_ends_with( $lower, $suffix ) ) {
            return true;
        }
    }

    return false;
}
```

`str_ends_with()` is available from PHP 8.0. WP Sudo requires PHP 8.0+.

#### Edit `sanitize_params()`

Change line ~357:
```php
// Before:
} elseif ( ! in_array( strtolower( (string) $key ), $sensitive, true ) ) {

// After:
} elseif ( ! $this->is_sensitive_key( (string) $key, $sensitive ) ) {
```

The `strtolower` call moves inside `is_sensitive_key`; remove it from the call site.

#### Tests to write (write RED first, then implement)

All new tests go in `tests/Unit/RequestStashTest.php`, in a new section
`// ── F4 suffix-based redaction ──`.

```php
/** @test */
public function test_compound_snake_case_api_key_is_redacted(): void {
    // connectors_openai_api_key must match the _api_key suffix.
    $this->assert_key_is_redacted( 'connectors_openai_api_key' );
}

/** @test */
public function test_compound_snake_case_secret_key_is_redacted(): void {
    $this->assert_key_is_redacted( 'stripe_secret_key' );
}

/** @test */
public function test_compound_snake_case_password_is_redacted(): void {
    $this->assert_key_is_redacted( 'smtp_password' );
}

/** @test */
public function test_compound_snake_case_access_token_is_redacted(): void {
    $this->assert_key_is_redacted( 'oauth_access_token' );
}

/** @test */
public function test_camelcase_api_key_is_redacted(): void {
    // stripeApiKey → lowercase: stripeapikey → ends with apikey.
    $this->assert_key_is_redacted( 'stripeApiKey' );
}

/** @test */
public function test_camelcase_secret_key_is_redacted(): void {
    $this->assert_key_is_redacted( 'stripeSecretKey' );
}

/** @test */
public function test_camelcase_password_is_redacted(): void {
    $this->assert_key_is_redacted( 'smtpPassword' );
}

/** @test */
public function test_non_sensitive_field_survives_suffix_check(): void {
    // 'page' does not end with any sensitive suffix.
    $this->assert_key_survives( 'page' );
}

/** @test */
public function test_non_sensitive_field_with_similar_word_survives(): void {
    // 'message_type' does not end with a sensitive suffix.
    $this->assert_key_survives( 'message_type' );
}

/** @test */
public function test_nested_compound_secret_fields_are_redacted(): void {
    // Compound sensitive keys in nested arrays must be caught.
    $this->assert_key_is_redacted_nested( 'settings', 'openai_api_key' );
}
```

**Required helpers in the test class** (add as private methods):

```php
/**
 * Assert that a single POST field with the given key is omitted from the stash.
 */
private function assert_key_is_redacted( string $field_key ): void {
    $this->stub_stash_index_meta_io();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST']      = 'example.com';
    $_SERVER['REQUEST_URI']    = '/wp-admin/options.php';
    $_POST = array( $field_key => 'sensitive-value', 'safe_field' => 'safe-value' );

    Functions\expect( 'wp_generate_password' )->once()->andReturn( 'suffixtest01234' );
    Functions\expect( 'is_ssl' )->once()->andReturn( false );
    Functions\when( 'esc_url_raw' )->returnArg();
    Functions\when( 'apply_filters' )->returnArg( 2 );

    $stored_data = null;
    Functions\expect( 'set_transient' )
        ->once()
        ->andReturnUsing( function ( $name, $data ) use ( &$stored_data ) {
            $stored_data = $data;
            return true;
        } );

    $this->stash->save( 1, array( 'id' => 'options.critical', 'label' => 'Change setting' ) );

    $this->assertArrayNotHasKey( $field_key, $stored_data['post'], "$field_key must be redacted." );
    $this->assertArrayHasKey( 'safe_field', $stored_data['post'], 'Non-sensitive field must survive.' );
    $this->assertTrue( $stored_data['redacted_fields_omitted'] );

    unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
    $_POST = array();
}

/**
 * Assert that a single POST field with the given key is NOT redacted.
 */
private function assert_key_survives( string $field_key ): void {
    $this->stub_stash_index_meta_io();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST']      = 'example.com';
    $_SERVER['REQUEST_URI']    = '/wp-admin/options.php';
    $_POST = array( $field_key => 'visible-value' );

    Functions\expect( 'wp_generate_password' )->once()->andReturn( 'survivtest1234' );
    Functions\expect( 'is_ssl' )->once()->andReturn( false );
    Functions\when( 'esc_url_raw' )->returnArg();
    Functions\when( 'apply_filters' )->returnArg( 2 );

    $stored_data = null;
    Functions\expect( 'set_transient' )
        ->once()
        ->andReturnUsing( function ( $name, $data ) use ( &$stored_data ) {
            $stored_data = $data;
            return true;
        } );

    $this->stash->save( 1, array( 'id' => 'options.critical', 'label' => 'Change setting' ) );

    $this->assertArrayHasKey( $field_key, $stored_data['post'], "$field_key must not be redacted." );
    $this->assertFalse( $stored_data['redacted_fields_omitted'] );

    unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
    $_POST = array();
}

/**
 * Assert that a nested POST field with the given key is omitted.
 */
private function assert_key_is_redacted_nested( string $parent_key, string $child_key ): void {
    $this->stub_stash_index_meta_io();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST']      = 'example.com';
    $_SERVER['REQUEST_URI']    = '/wp-admin/options.php';
    $_POST = array( $parent_key => array( $child_key => 'secret', 'safe' => 'visible' ) );

    Functions\expect( 'wp_generate_password' )->once()->andReturn( 'nestedtest01234' );
    Functions\expect( 'is_ssl' )->once()->andReturn( false );
    Functions\when( 'esc_url_raw' )->returnArg();
    Functions\when( 'apply_filters' )->returnArg( 2 );

    $stored_data = null;
    Functions\expect( 'set_transient' )
        ->once()
        ->andReturnUsing( function ( $name, $data ) use ( &$stored_data ) {
            $stored_data = $data;
            return true;
        } );

    $this->stash->save( 1, array( 'id' => 'options.critical', 'label' => 'Change setting' ) );

    $nested = $stored_data['post'][ $parent_key ] ?? array();
    $this->assertArrayNotHasKey( $child_key, $nested, "Nested $parent_key[$child_key] must be redacted." );
    $this->assertArrayHasKey( 'safe', $nested, 'Non-sensitive nested field must survive.' );

    unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
    $_POST = array();
}
```

**Note on Brain\Monkey per-test function mock reuse:** Each helper calls
`Functions\expect('wp_generate_password')` and `Functions\expect('set_transient')`.
Because Brain\Monkey resets between tests, this works cleanly — each test gets
fresh expectations.

**Acceptance criteria for Cycle B:**
- All 10 new tests pass (10 compound/camelCase/nested cases).
- Existing tests `test_save_omits_sensitive_post_fields`, `test_sensitive_keys_matched_case_insensitively`, `test_nested_sensitive_fields_are_omitted`, `test_sensitive_keys_are_filterable` still pass unchanged.
- Non-sensitive fields (`page`, `message_type`, `blogname`) are NOT redacted (confirmed by `assert_key_survives` tests).
- `composer test:unit`, `composer analyse`, `composer lint` all clean.

---

## Gate checks (both cycles)

```bash
composer test:unit    # must pass
composer analyse      # PHPStan level 6, must pass
composer lint         # PHPCS, must pass
```

Run after each cycle before committing. Follow the CLAUDE.md reviewer workflow:
spawn the reviewer agent, let it write `reviewer-approved`, then commit.

---

## Commit messages

**Cycle A commit:**
```
fix(security): drop $_GET from request stash (F9)

$_GET was stored in every stash entry but never used in replay: GET
requests are replayed via redirect to the stashed URL (which already
includes the query string), and POST replay only submits post_data
from the JS handleReplay function. Remove the 'get' key from save()
and 'get_data' from build_replay_response_data() to eliminate the
unnecessary 5-minute transient exposure of GET parameters.
```

**Cycle B commit:**
```
fix(security): add suffix-based redaction for compound secret field names (F4)

Exact-match redaction missed compound field names such as
connectors_openai_api_key, stripe_secret_key, smtp_password, and
smtpPassword. Add is_sensitive_key() with a suffix scan (str_ends_with
on the lowercased key) covering _api_key, _secret_key, _secret,
_password, _pass, _private_key, _access_token, _auth_token, _api_token,
_api_secret, and their camelCase equivalents. Exact-match behavior
is preserved for the existing sensitive_field_keys() list.
```

---

## What this does NOT cover

- Per-rule `stash_fields` allowlists (the broader F9 architectural option from the
  execution plan). The structural concern is addressed by dropping `$_GET` entirely
  and by F4's suffix redaction. Full per-rule allowlists can ship later if operators
  or integrations require fine-grained control.
- The nonce-in-stash residual risk item (execution plan §10) — separate finding,
  no action planned.

---

## After this phase

Next: update `docs/execution-plan-v3.1-v3.3.md` to mark F4 and F9 as fixed with
the commit hashes, then proceed to the release checklist for `3.2.0`.
