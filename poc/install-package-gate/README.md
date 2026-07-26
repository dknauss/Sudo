# Core Gate PoC — the `install_package()` vertical slice

The first slice of [#360](https://github.com/dknauss/Sudo/issues/360), replacing the quarantined pseudocode in [`docs/core-sudo-gate-poc-patches.md`](../../docs/core-sudo-gate-poc-patches.md).

**This is a proof of concept for the core proposal, not part of WP Sudo.** It is not activated, is excluded from the distributed package via `.pressshipignore`, and has its own test harness so it cannot perturb the plugin's **production or test** metrics. It does count toward the repo-wide PHP line total, which is updated in `docs/current-metrics.md` alongside it.

## What it proves

[`core-action-gate-proposal.md`](../../docs/core-action-gate-proposal.md) §3 argues that a recent-auth gate belongs at core's **shared effect sinks**, and names `WP_Upgrader::install_package()` as the package-write funnel beneath plugin/theme install, upload, update, bulk-update, the AJAX updaters, the auto-updater, and language packs.

That *funnel* claim is the proposal's, and this slice does not re-prove it: `GB-UPGRADER-PRE-INSTALL` cites the filter **inside** the sink, which establishes that a veto there works — not that every named producer reaches it. Enumerating the callers is separate work, and the word "single" should not be leaned on until it is done.

This slice shows that claim is implementable **with no core change at all**, because core already ships a vetoing seam there. `wp-admin/includes/class-wp-upgrader.php`, inside `WP_Upgrader::install_package()`:

```php
$res = apply_filters( 'upgrader_pre_install', true, $args['hook_extra'] );

if ( is_wp_error( $res ) ) {
    return $res;
}
```

That is the same short-circuit shape as `pre_delete_post` — a filter whose error return aborts the operation *and becomes the return value*. The write is refused at the sink, which is what the tests demonstrate. How each surface *renders* that refusal is a caller-by-caller question this slice has not audited: a caller could replace or swallow the error without the filesystem write ever happening.

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
| **Copied login cookie does not inherit the proof** | the test the proposal stands or falls on (§4) — browser B replays browser A's *exact* cookie |
| A second, independent session does not inherit it either | the weaker sibling property; kept distinct so the stronger claim is not read off the weaker test |
| A revoked session is refused, not treated as actorless | fail-open regression guard |
| No session (cron/CLI/API credential) → passes through | the v1 scope boundary ([#320](https://github.com/dknauss/Sudo/issues/320)); this is what keeps background security updates working |
| Programmatic call inside an interactive request → still gated | the misclassification boundary ([#357](https://github.com/dknauss/Sudo/issues/357)) — call origin is not an actor class |
| Forged cookie cannot mint or hold a proof | `wp_get_session_token()` only *parses* the cookie; `WP_Session_Tokens::verify()` is the real check |
| Revoking re-gates immediately | "log out everywhere" must close the window now |

## Design notes worth carrying into the spec

**Actor class comes from the request, never the call site.** A plugin calling `install_package()` during an admin page load is an *interactive* actor, because the request carries a cookie session. Classifying by call origin would hand any hijacked session a one-line bypass — call the sink from a hook instead of posting the form. See [#357](https://github.com/dknauss/Sudo/issues/357).

**Provenance genuinely cannot be read at this seam.** `WP_Automatic_Updater` does not implement its own package path — it instantiates the ordinary upgraders and calls `upgrade()` with generic options (`GB-AUTOUPDATER-UPGRADE`), so `$hook_extra` is built exactly as for a manual run and nothing in the payload identifies an automatic update. So there is no way to tell an auto-update from a manual one from inside the filter — which is exactly why [#307](https://github.com/dknauss/Sudo/issues/307) needs a provenance primitive decided upstream, and why v1 keys on *absence of a session* instead.

**Session binding alone is not enough — the proof is a separate browser secret.** A per-user proof lets any concurrent session inherit the elevation. But binding to the session *token* is also insufficient, and this is the subtle part: an attacker who copies the victim's login cookie presents the **same** token, so a token-keyed proof resolves to the same record and the copy inherits it. So `grant_proof()` issues a fresh random secret in its own cookie and stores only its hash, per proposal §4. A cookie copied *before* the challenge cannot pass; one copied *after* can, and that residual is why the TTL is short.

`wp_get_session_token()` is not a validator either — the cookie "parser" only splits and counts (`GB-AUTH-COOKIE-PARSE`), so the token is checked against `WP_Session_Tokens::verify()`.

**A revoked session is refused, not reclassified.** Folding "cookie present but token invalid" into "no actor" would be a fail-open: the request would drop into the out-of-v1-scope branch and the write would be allowed, inverting the intent of "log out everywhere".

**No stash, no auto-replay.** A blocked install returns the `WP_Error` the installer UI already renders; the operator re-runs the action after proving intent. Auto-replay is the defect tracked as [#315](https://github.com/dknauss/Sudo/issues/315).

## What this slice deliberately does not do

- **No challenge UI.** Proving intent is a separate concern (proposal §4); the tests grant the proof directly.
- **No identity pivots.** Own-password change and the rest of Group B need seams core does **not** have — `wp_set_password()` and `set_role()` are `void`, `wp_delete_user()` returns `bool` so a `WP_Error` is truthy and a refusal reads as success. Those need the four `pre_*` filters scoped in [#358](https://github.com/dknauss/Sudo/issues/358), and that is the second slice — the one that genuinely requires a `wordpress-develop` patch branch.
- **No non-interactive policy.** Out of v1 scope per [#320](https://github.com/dknauss/Sudo/issues/320).
- **The proof is not action- or target-bound.** Within its TTL it authorises any package write, so a session-riding attacker could substitute a different install. Scoping it is [#308](https://github.com/dknauss/Sudo/issues/308); flat freshness is the spec's current v1 recommendation, and this slice follows it deliberately rather than by omission.
- **The proof record is not signed.** `has_proof()` trusts a transient. Proposal §4 requires an HMAC-signed record precisely because that storage is cache-poisonable ([#310](https://github.com/dknauss/Sudo/issues/310)); a production implementation must not skip it.

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
