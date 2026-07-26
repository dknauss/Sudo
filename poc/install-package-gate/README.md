# Core Gate PoC — the `install_package()` vertical slice

The first slice of [#360](https://github.com/dknauss/Sudo/issues/360), replacing the quarantined pseudocode in [`docs/core-sudo-gate-poc-patches.md`](../../docs/core-sudo-gate-poc-patches.md).

**This is a proof of concept for the core proposal, not part of WP Sudo.** It is not shipped, not activated, and has its own test harness so it cannot perturb the plugin's suites or metrics.

## What it proves

[`core-action-gate-proposal.md`](../../docs/core-action-gate-proposal.md) §3 argues that a recent-auth gate belongs at core's **shared effect sinks**, and names `WP_Upgrader::install_package()` as the single package-write funnel beneath plugin/theme install, upload, update, bulk-update, the AJAX updaters, the auto-updater, and language packs.

This slice shows that claim is implementable **with no core change at all**, because core already ships a vetoing seam there. `wp-admin/includes/class-wp-upgrader.php`, inside `WP_Upgrader::install_package()`:

```php
$res = apply_filters( 'upgrader_pre_install', true, $args['hook_extra'] );

if ( is_wp_error( $res ) ) {
    return $res;
}
```

That is the same short-circuit shape as `pre_delete_post` — a filter whose error return aborts the operation *and becomes the return value*. So every caller that already handles `install_package()`'s `WP_Error` surfaces the refusal with no further change.

### The control test is the point

Anyone can write a test asserting `install_package()` returned an error. That proves nothing on its own — it might have failed for unrelated reasons. So the suite runs the **identical call twice**, once with the filter attached and once without:

| | outcome | package written to disk |
|---|---|---|
| gate on | `wp_sudo_reauth_required` | **no** |
| gate off | success | **yes** |

That difference is the entire claim of §3, and it is now empirical rather than argued.

## What it covers

| Behaviour | Why it is here |
|---|---|
| Interactive session, no proof → refused, nothing written | the core case |
| Interactive session, with proof → gate is not what stops it | no false positives |
| **Cloned session does not inherit the proof** | the test the proposal stands or falls on (§4) |
| No session (cron/CLI/API credential) → passes through | the v1 scope boundary ([#320](https://github.com/dknauss/Sudo/issues/320)); this is what keeps background security updates working |
| Programmatic call inside an interactive request → still gated | the misclassification boundary ([#357](https://github.com/dknauss/Sudo/issues/357)) — call origin is not an actor class |
| Forged cookie cannot mint or hold a proof | `wp_get_session_token()` only *parses* the cookie; `WP_Session_Tokens::verify()` is the real check |
| Revoking re-gates immediately | "log out everywhere" must close the window now |

## Design notes worth carrying into the spec

**Actor class comes from the request, never the call site.** A plugin calling `install_package()` during an admin page load is an *interactive* actor, because the request carries a cookie session. Classifying by call origin would hand any hijacked session a one-line bypass — call the sink from a hook instead of posting the form. See [#357](https://github.com/dknauss/Sudo/issues/357).

**Provenance genuinely cannot be read at this seam.** `WP_Automatic_Updater` reuses `Plugin_Upgrader`/`Theme_Upgrader` and adds **no marker of its own** to `$hook_extra` (verified against trunk). So there is no way to tell an auto-update from a manual one from inside the filter — which is exactly why [#307](https://github.com/dknauss/Sudo/issues/307) needs a provenance primitive decided upstream, and why v1 keys on *absence of a session* instead.

**The proof is per-session, never per-user.** A per-user proof is what lets a cloned cookie inherit the elevation the legitimate browser earned. The key mixes in a hash of the *verified* session token — hashed because the key becomes an option name and a session token is a credential.

**No stash, no auto-replay.** A blocked install returns the `WP_Error` the installer UI already renders; the operator re-runs the action after proving intent. Auto-replay is the defect tracked as [#315](https://github.com/dknauss/Sudo/issues/315).

## What this slice deliberately does not do

- **No challenge UI.** Proving intent is a separate concern (proposal §4); the tests grant the proof directly.
- **No identity pivots.** Own-password change and the rest of Group B need seams core does **not** have — `wp_set_password()` and `set_role()` are `void`, `wp_delete_user()` returns `bool` so a `WP_Error` is truthy and a refusal reads as success. Those need the four `pre_*` filters scoped in [#358](https://github.com/dknauss/Sudo/issues/358), and that is the second slice — the one that genuinely requires a `wordpress-develop` patch branch.
- **No non-interactive policy.** Out of v1 scope per [#320](https://github.com/dknauss/Sudo/issues/320).

## Running it

Needs a WordPress test-library install and MySQL:

```bash
WP_TESTS_DIR=/tmp/poc-wp-tests-lib WP_CORE_DIR=/tmp/poc-wordpress \
  bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1:3306 latest true
```

Then, from this directory:

```bash
WP_TESTS_DIR=/tmp/poc-wp-tests-lib ../../vendor/bin/phpunit -c phpunit.xml.dist
```
