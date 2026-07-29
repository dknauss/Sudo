# Independent verification of the seven-high gate audit

**Date:** 2026-07-29
**Verifier:** separate pass, checking each claim against source rather than against the audit narrative
**Core tree:** WordPress 7.0 (`$wp_version = '7.0'`), local wp-env checkout
**Plugin tree:** `fix/533-handler-landing-map` rebased on `origin/main` `9d29946`

Each claim below required two independent facts: what **core** dispatches on, and
what the **plugin matcher** predicates on. A claim is only verified when both
were read in context.

---

## Result summary

| # | Claim | Status |
|---|---|---|
| 1 | REST route case-sensitivity | **Verified** |
| 2 | File editors → arbitrary PHP write | **Verified** |
| 3 | `options.php` self-protection bypass | **Verified**, including the author-knew detail |
| 4 | `users.php` promote short-circuit | **Verified** |
| 5 | REST `POST` plugin deactivation | **Verified** |
| 6 | `wp_ajax_add-user` ungated | **Verified** |
| 7 | 2FA lifecycle bridge parameter divergence | **Verified** |

All seven stand on read-in-context evidence. Nothing was refuted. Details below,
including what was *not* established.

---

## 1. REST route case-sensitivity — VERIFIED

**Core (GB-CORE70-REST-CASE).** `WP_REST_Server::match_request_to_handler()`:

```php
$match = preg_match( '@^' . $route . '$@i', $path, $matches );
```

The `i` flag makes core's route match case-insensitive.

**Plugin.** Every REST pattern in `Action_Registry` uses the `#...#` delimiter
with no flags — `#^/wp/v2/users/\d+$#`, `#^/wp/v2/users$#`,
`#^/wp/v2/plugins/[^/]+(?:/[^/]+)?$#`, `#^/wp/v2/settings$#`.

**Consequence.** `POST /wp-json/wp/v2/Users/5` dispatches to core's users
endpoint and matches no plugin rule. This is the broadest of the seven: it voids
the REST arm of role change, password change, email change, user create,
application-password minting, and the settings route in one character.

## 2. File editors → arbitrary PHP write — VERIFIED

**Core (GB-CORE70-PLUGIN-EDITOR-POST,
GB-CORE70-THEME-EDITOR-POST).** The two submission branches are identical:

```php
if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
	$edit_result = wp_edit_theme_plugin_file( wp_unslash( $_POST ) );
```

The write is predicated on the HTTP method alone. No `action` value is consulted.

**Plugin.** `Action_Registry`, `editor.plugin` rule (line 503):

```php
'pagenow' => 'plugin-editor.php',
'actions' => array( 'update' ),
'method'  => 'POST',
```

`editor.theme` (line 519) mirrors it.

**Consequence.** A POST to `plugin-editor.php` that simply omits the `action`
field is written by core and matched by no rule.

**Supporting detail — gap, not decision.** The same rule's AJAX arm *is* gated
(`'actions' => array( 'edit-theme-plugin-file' )`). The surface reached by the
browser form is the ungated one.

## 3. `options.php` self-protection bypass — VERIFIED

**Core (GB-OPTIONS-PAGE-REQUEST).**

```php
$option_page = ! empty( $_REQUEST['option_page'] ) ? sanitize_text_field( $_REQUEST['option_page'] ) : '';
```

Core reads `$_REQUEST`, so `option_page` may arrive in the query string.

**Plugin — the rule that got it right.** `Action_Registry:605`, in the
`options.critical` callback:

```php
$option_page = ! empty( $_REQUEST['option_page'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['option_page'] ) ) : '';
```

with a comment at line 598 stating that `option_page` travels in the query
string.

**Plugin — the rules that did not.** Lines 699 and 718, the two rules protecting
**WP Sudo's own settings**:

```php
$option_page = isset( $_POST['option_page'] ) && is_string( $_POST['option_page'] ) ? ... : '';
return 'wp-sudo-settings' === $option_page;   // line 700
return 'wp-sudo-access'   === $option_page;   // line 719
```

**Consequence.** `POST /wp-admin/options.php?option_page=wp-sudo-settings` with
the field absent from the body is processed by core as a WP Sudo settings write
and matched by neither self-protection rule.

**This is the finding that matters most methodologically.** The bypass class was
correctly diagnosed, written down in a comment, and fixed — in one rule. The two
rules guarding the plugin against being disarmed kept the wrong superglobal. The
defect is not ignorance of the bug class; it is that knowing the class did not
produce a sweep of its other instances.

## 4. `users.php` promote short-circuit — VERIFIED

**Core (GB-CORE70-USERS-CHANGEIT).**
`WP_Users_List_Table::current_action()`:

```php
public function current_action() {
	if ( isset( $_REQUEST['changeit'] ) ) {
		return 'promote';
	}
	return parent::current_action();
}
```

`wp-admin/users.php:110` switches on that return. So the promote path is entered
on the presence of `changeit`, regardless of `action` / `action2`.

**Plugin.** `Gate::matches_admin()`, `includes/class-gate.php:1787–1801`:

```php
$actions = (array) ( $admin['actions'] ?? array() );

if ( ! in_array( $request_action, $actions, true ) ) {
	return false;                      // ← returns here
}
...
if ( isset( $admin['callback'] ) && is_callable( $admin['callback'] ) ) {
	if ( ! call_user_func( $admin['callback'] ) ) {   // ← never reached
		return false;
	}
}
```

**Consequence.** The action allowlist is evaluated *before* the rule's own
callback. A request carrying `changeit` with an `action` value outside the
allowlist never reaches the callback that would have detected it correctly. The
rule's own logic is not wrong; it is unreachable.

## 5. REST `POST` plugin deactivation — VERIFIED

**Core (GB-CORE70-REST-EDITABLE).** The editable method set includes POST, PUT,
and PATCH.

**Plugin.** `plugin.activate` and `plugin.deactivate` both declare:

```php
'route'   => '#^/wp/v2/plugins/[^/]+(?:/[^/]+)?$#',
'methods' => array( 'PUT', 'PATCH' ),
```

`POST` is absent.

**Consequence.** `POST /wp/v2/plugins/wp-sudo/wp-sudo` with
`{"status":"inactive"}` deactivates WP Sudo itself, ungated. Combined with #1,
the same request also survives a capitalised route.

## 6. `wp_ajax_add-user` ungated — VERIFIED AS A GAP

**Core (GB-CORE70-AJAX-ADD-USER,
GB-CORE70-AJAX-ADD-USER-HANDLER).** The authenticated AJAX action list registers
`add-user`, whose handler reaches `edit_user()`.

**Plugin.** The `user.create` rule declares `'ajax' => null`. Its admin arm
covers `user-new.php`, and its REST arm covers `POST /wp/v2/users`. The AJAX
surface is explicitly unset.

**Role escalation traced and confirmed.** `wp_ajax_add_user()` calls
`edit_user()` with no arguments. `wp-admin/includes/user.php:56`:

```php
if ( isset( $_POST['role'] ) && current_user_can( 'promote_users' ) && ( ! $user_id || current_user_can( 'promote_user', $user_id ) ) ) {
	$new_role = sanitize_text_field( $_POST['role'] );
	$editable_roles = get_editable_roles();
	if ( ! empty( $new_role ) && empty( $editable_roles[ $new_role ] ) ) {
		wp_die( __( 'Sorry, you are not allowed to give users that role.' ), 403 );
	}
	...
	$user->role = $new_role;
```

For a **new** user `$user_id` is `0`, so the `! $user_id` disjunct short-circuits
the per-user check. An administrator holds `promote_users`, and `administrator`
is present in `get_editable_roles()`. So `role=administrator` is accepted.

The self-demotion guard at lines 68–76 only prevents an actor removing
`promote_users` from itself and does not apply.

**Consequence.** `POST /wp-admin/admin-ajax.php` with `action=add-user` and
`role=administrator` creates an administrator on a surface the `user.create` rule
explicitly declines to cover.

## 7. 2FA lifecycle bridge parameter divergence — VERIFIED

An earlier pass recorded this as partial on the assumption that the diverging
counterpart was the Two Factor plugin. It is not. The branch in question guards
the **classic profile form on `user-edit.php`**, so the counterpart is core.

**Core (GB-CORE70-USER-EDIT-REQUEST).**

```php
$user_id = ! empty( $_REQUEST['user_id'] ) ? absint( $_REQUEST['user_id'] ) : 0;
```

Core resolves the edited user from `$_REQUEST`, so `user_id` may arrive in the
query string.

**Plugin.** `bridges/wp-sudo-two-factor-lifecycle-bridge.php:141–147`:

```php
} elseif ( 'user-edit.php' === $current_pagenow ) {
	if ( ! isset( $_POST['user_id'] ) || is_array( $_POST['user_id'] ) ) {
		return false;
	}
	$target_user_id = absint( wp_unslash( $_POST['user_id'] ) );
}
```

`$_POST` only, and the callback **returns false** — declining to gate — when the
field is absent from the body.

**Consequence.** `POST /wp-admin/user-edit.php?user_id=<victim>` carrying the
two-factor provider fields in the body is processed by core against the victim's
account, while the bridge's callback declines the rule. A victim administrator's
second factor can be altered or stripped with no sudo challenge.

No Two Factor source was required: the divergence is bridge-versus-core.

---

## Not verified in this pass

- **"The effect-level backstop covers only 6 of 20 gated operations."** The
  ratio was not recomputed. The *direction* is corroborated independently:
  `Gate::arm_*` scopes the backstop to file/record-destroying effects
  (`includes/class-gate.php:205–211, 328`), and every one of the seven highs
  lands in option writes or user create/promote, which that scope excludes.
Claims 6 and 7 were both closed in a follow-up pass and are no longer partial.

## What the seven have in common

Every one is the same shape: **the plugin's matching predicate is not congruent
with the predicate WordPress core dispatches on.** Six independent axes —
route-pattern case, HTTP method set, superglobal (`$_POST` vs `$_REQUEST`),
action-name derivation, evaluation order within the matcher, and surface
coverage (`ajax => null`).

This is a stronger and more falsifiable statement than "enumeration is
incomplete." Incompleteness would mean unenumerated paths escape. What these
show is that **enumerated** paths escape, because two independently-maintained
predicates over the same request drift apart. Congruence is not a property
either side can enforce, and nothing in the architecture detects divergence —
each of the six was found by reading core and the matcher side by side, which is
not something a test of the plugin against itself can do.
