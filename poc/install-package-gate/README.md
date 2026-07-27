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

## A finding that corrects the proposal: `install_package()` alone is too late

`WP_Upgrader::run()` does this, in order (`class-wp-upgrader.php`):

| step | line | effect |
|---|---|---|
| `download_package()` | 849 | fetches the archive |
| `unpack_package()` | 887 | **extracts it into `wp-content/upgrade/`** (:367, :378) |
| `install_package()` | 898 | moves it into the live plugin/theme tree — where this gate fires |

So a gate that fires *only* inside `install_package()` blocks the final move but **not the extraction**. Attacker-controlled PHP is already sitting in `wp-content/upgrade/`, a directory many hosts happily execute PHP from, and a session-riding attacker who can request a known path during the window gets execution regardless of the refusal that follows.

Proposal §3 names `install_package()` as *the* code-write seam. On this evidence that is **not sufficient on its own** for the interactive branch, and the spec should say so.

This slice therefore gates at **`upgrader_pre_download`** as well, which fires before both the download and the unpack — and keeps the `install_package()` gate, because that sink is reachable directly by callers that never go through `run()`. `test_the_run_path_is_refused_before_anything_is_unpacked()` asserts `wp-content/upgrade/` is untouched.

Two caveats kept honest: this was found by reading `run()`, not by demonstrating the race, and the residual depends on the host executing PHP under `wp-content`. Neither weakens the ordering fact.

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
| An admin `AUTH_COOKIE` with no `LOGGED_IN_COOKIE` is not actorless | wp-admin authenticates from `AUTH_COOKIE`/`SECURE_AUTH_COOKIE`, while `wp_get_session_token()` reads only `LOGGED_IN_COOKIE` — checking one cookie was a fail-open |
| `run()` is refused before anything is unpacked | the seam-placement finding above |

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
### ⚠️ The proof model here is behind the spec

This slice implements a **reusable 15-minute window**. The spec has since moved past that, and the divergence is deliberate but must not be mistaken for agreement:

> §5.1: *"**No reusable window for in-scope actions.** A proof is minted for **one action digest**, is single-use, and is discarded on redemption."*

§11 item 3 ("flat freshness vs. scope-bound windows") is now **closed** — *"with no reusable window there is nothing to scope."* So what this slice does is not the spec's v1 recommendation; it is a **superseded** model that happens to be enough to demonstrate the seam.

**What this slice is evidence about, and what it is not.** The spec is normative for *mechanism*; this slice is evidence about *behaviour*. It exercises a session-scoped proof with a TTL — the model that was current when it was written — and demonstrates two things that do not depend on which proof model is used:

- a copied login cookie cannot inherit a proof issued to another browser, and
- `install_package()` fires after `unpack_package()`, so it is too late on its own.

Both hold under per-action step-up too. Neither is a claim that the TTL model is the right one.

Concretely, the slice does **not** implement:

- **Per-action digest binding and single use**, which §5.1 now requires. (Do not read the older #308 here as an open gap — it is **closed**, resolved by construction when §4.2 moved to per-action step-up: with no reusable window there is nothing to scope.)
- **An HMAC-signed record.** `has_proof()` trusts a transient. §4.2 requires the MAC to cover the proof hash and a cache-bypassing read on the enforcement path, because that storage is poisonable ([#310](https://github.com/dknauss/Sudo/issues/310)).

Reconciling the slice to per-action proofs is worthwhile but is not a prerequisite for either finding above.

## Two traps this slice walked into, for whoever writes the next one

**Drive the entry point, not the sink.** The first version of these tests called `install_package()` directly. That proves the check works *when reached* and says nothing about *whether it is reached* — which is exactly how the `unpack_package()` ordering above went unnoticed until a reviewer read `run()`. A gate test that invokes the gate is not a test of the gate's placement. Drive `run()`, or the admin request, or the REST route.

**`git diff A B` does not answer "what would merging do."** It is a two-tree diff: files present on `A` and absent on `B` are reported as deletions, which reads exactly like `B` will delete them. It will not — a merge applies `B`'s diff *relative to its merge base*, so commits added to `A` afterwards survive. Ask the real question with:

```bash
git merge-tree $(git merge-base <branch> origin/main) <branch> origin/main   # paths from main show as `their`
git diff $(git merge-base <branch> origin/main) <branch> -- <path>          # empty ⇒ branch never touched it
```

This cost a false alarm that `poc/` was about to be deleted by a stale branch. It wasn't.

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
