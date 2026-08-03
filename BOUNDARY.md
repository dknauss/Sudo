# What this prototype actually claims

Written 2026-08-02, after the binding-cookie fix and the first regression
suite, both same-day, and revised the same day five more times: once after
an independent fresh-context agent — given the live instances and the raw
source, but not this file or the regression suite — found a high-severity
defect in under ten minutes that the suite it's revising had not covered;
again after a second, separately spawned fresh-context agent, given the
same access and the same exclusions, found four more defects in code that
had already been "fixed" and re-tested once; again after this prototype's
own author found, by hand, that a real administrator could change another
real user's email address with zero reauthentication -- found not by
either agent, and not by the regression suite, but by clicking through
native wp-admin like an ordinary user would; again after a third
fresh-context agent, explicitly briefed on that history and told to hunt
the same bug class elsewhere, found `remove_users` missing from the denied
list entirely and an approval-burning bug in the newly-wired create-user
effect; and again after a fourth fresh-context agent ran a clean, targeted
sweep of the ten capabilities nobody had individually checked yet -- and
whose one "cosmetic" side observation, chased by this prototype's author
rather than the agent, turned out to be a sixth real bypass
(`install_languages`, never covered under any name). Revised once more on
2026-08-03, after a third effect (`delete_users`) was wired two different
ways to probe the intent-signal problem — an experiment whose stated
prediction turned out to be wrong, recorded below rather than quietly
dropped. This is the claim as it stands *today*, not aspirational, and not
identical to what an earlier version of this prototype — or an earlier
version of this file — would have supported.

**A third finding, found by neither agent: the floor's filter checked the
wrong string for most of its own stated purpose.** `wp-admin/user-edit.php`
gates access to another user's profile with
`current_user_can( 'edit_user', $user_id )` -- the singular meta
capability. Core's own `map_meta_cap()` resolves that internally to the
primitive `edit_users` by appending it directly to the `$caps` array,
without ever re-invoking `map_meta_cap()` with `$cap = 'edit_users'`. The
`map_meta_cap` filter this floor hooks always receives the *original*
`$cap`, so a version of `cap_floor_deny()` that checked only `$cap` against
the denied list -- which is what both `cap_floor_deny()` and
`cap_floor_allow_with_approval()` did until this fix -- silently let
`edit_user` straight through: the administrator's own role already had
`edit_users`, so once the floor declined to add `do_not_allow`,
`WP_User::has_cap()`'s final check against the role's own capabilities
simply passed. Reproduced directly: opened `user-edit.php` for a second
real account, changed its email field, submitted the real form, and the
change took effect -- no password, no approval, nothing. `edit_users` was
never actually enforced for the one screen that exists to use it, for the
entire time it was on `CAP_FLOOR_DENIED_CAPS`. `delete_users` has the
identical meta-cap indirection (`delete_user` → `delete_users`) but turned
out to already be safe, for an unrelated reason: `wp-admin/users.php`
checks the bare, unwrapped `current_user_can( 'delete_users' )` before
ever reaching the per-row meta-cap check, so the floor's `$cap`-only check
happened to still match there. `create_users` was never affected either --
`user-new.php` calls `current_user_can( 'create_users' )` directly, with no
meta-cap wrapper, so `$cap` already equals the primitive. Fixed by checking
the resolved `$caps` array (`array_intersect` against the denied list), not
only `$cap`, in both functions. **This changes what "two independent
fresh-context agents reviewed this and found what they found" is actually
evidence of**: both agents were pointed at, and stayed within, the
approval/binding/rate-limit mechanics of one wired effect. Neither one
tried the single most direct test of the floor's own headline claim --
open a second real user's edit screen as an administrator and see what
happens -- because neither was told to and neither happened to think of
it unprompted. An agent finding nothing in its assigned area is evidence
about that area, not about the whole surface.

**A third fresh-context agent, explicitly told to attack `create-user` and
hunt for the same meta-cap bug class elsewhere, found two more real
defects and confirmed several things safe.** Told the same day's history
(what the two prior agents found, and the `edit_users` finding above, for
calibration), given the same live instances and raw source, and still
excluded from this file and the regression suite.

- **`remove_users` was simply absent from `CAP_FLOOR_DENIED_CAPS` (High).**
  Not the meta-cap indirection bug -- a distinct primitive, checked bare,
  that gates `wp-admin/users.php`'s multisite-only "Remove" bulk/row action
  (unassign a user from one site without deleting their network account).
  Administrator has it natively; with it missing from the list the floor
  never engaged at all. The agent reproduced this live: removed a real
  user from a real multisite site with zero password prompt, then ran a
  positive control confirming Network Admin's "Delete" (`delete_users`)
  correctly stayed absent from the same bulk-actions menu -- isolating the
  gap to this one specific, distinct capability, not a general failure.
  Fixed by adding it to the list; verified via
  `current_user_can( 'remove_users' )` returning false under wp-cli's
  `--user=admin` context. **This is the same underlying lesson as the
  `edit_users` finding, in a different shape**: a denied-capabilities list
  is only as complete as the person who wrote it knew to include, and nobody
  had specifically gone looking for a capability that should be on the list
  but wasn't, until this agent's brief said to hunt for exactly that.
- **`/create-user` consumed the approval before calling
  `wp_insert_user()` (Medium).** Requesting an approval for an
  already-registered username or email burned a real password check and
  one of only 3 rate-limited attempts on an insert that was always going
  to fail with `existing_user_login`. Fixed with a
  `username_exists()`/`email_exists()` check before the approval is
  touched at all -- the same "reject what's knowable in advance" principle
  already applied to `target_hash` length validation. Regression test 17
  checks the fix's actual mechanism (the approval survives, still
  `approved`, not just that the request is refused).
- **Confirmed safe, not merely untested**: cross-account approval reuse
  (an approval requested under one session cannot be consumed under
  another, even with the exact ID and matching fields); 10-way concurrent
  redemption of one `create-user` approval (exactly 1 of 10 succeeds); an
  approval requesting `role=subscriber` cannot be redeemed with
  `role=administrator` -- and, importantly, that mismatch does **not**
  burn the approval, so a corrected retry with the right role still works.
- **A fragility worth naming even though it isn't a live defect on these
  instances**: the `Secure`-flagged binding cookie only survives because
  browsers treat `http://localhost` as a "potentially trustworthy origin,"
  exempting it from the Secure-requires-HTTPS rule. Any deployment on a
  real hostname over plain HTTP, or behind a proxy that doesn't correctly
  signal HTTPS to PHP, would silently and completely disable the entire
  approval mechanism -- the cookie would simply never be set, and every
  binding check would fail closed for a reason nobody watching the app
  would see (no error, just a cookie that never appears). This prototype
  has only ever been run over plain HTTP on localhost; nobody has verified
  it against a real TLS-terminating deployment.

**A fourth fresh-context agent ran a clean sweep, and its fine print led
to a sixth real finding anyway.** Briefed on all three prior rounds for
calibration, excluded from this file and the suite as always, and given a
narrow, falsifiable brief: check the ten capabilities nobody had
individually tried against a real native trigger yet
(`edit_files`/`edit_plugins`/`edit_themes`/`upload_plugins`/`upload_themes`/
`update_plugins`/`update_themes`/`delete_plugins`/`update_core`/`unfiltered_html`),
against their actual screens, on both single-site and multisite Super
Admin, with real HTTP cookie sessions rather than `wp --user`.

- **Zero bypasses among the ten** — every one denied correctly on both
  instances. `edit_files` has no native trigger to test at all (confirmed
  by grepping all of wp-admin and wp-includes: nothing calls
  `current_user_can( 'edit_files' )` directly; it exists only as a shared
  label inside core's own switch statement), so nine were actually
  exercised, all clean.
- **The `array_intersect` fix from the `edit_users` finding is already
  covering more than it was built for.** The agent noticed, while reading
  core's `map_meta_cap()` source for calibration, that case `'edit_css'`
  resolves to `'unfiltered_html'` -- the identical different-name
  indirection as `edit_user` → `edit_users` -- and `'edit_css'` itself was
  never on the denied list either. It doesn't bypass anything, purely
  because the generalized fix already checks the *resolved* `$caps` array,
  not the literal capability name requested. Confirms that fix was the
  right generalization, not a narrow patch for one case.
- **The one loose thread the agent flagged, and didn't chase, was real.**
  Its brief was the ten listed capabilities; along the way it noticed
  `update-core.php`'s page-level gate has an `update_languages` branch not
  covered by the list, called it "cosmetic" (the page still renders no
  working controls), and correctly left it outside its assigned scope.
  Reading core's actual switch statement for that case found it wasn't
  cosmetic: `update_languages` resolves to `install_languages` -- a
  primitive that gates a real mutation
  (`update-core.php?action=do-translation-upgrade`, driving a genuine
  `Language_Pack_Upgrader`) -- and neither string had ever been on the
  list. Confirmed live before the fix: `current_user_can( 'install_languages' )`
  returned true for a real administrator on both single-site and
  multisite. Fixed by adding it; see `capability-floor-prototype.php` and
  regression test 18.
- **Also untested, by the agent's own account**: whether a genuine
  `DISALLOW_FILE_MODS` lockdown (neither instance defines the constant)
  would interact safely with an approval trying to override it. Traced by
  reading code, not exercised live: core's own switch would add a second
  `do_not_allow` to `$caps` alongside the floor's, and
  `cap_floor_allow_with_approval()`'s `array_search()`/`unset()` only
  removes one occurrence -- so a survivable-looking read of the code
  suggests an approval could never fully lift a genuine core-level
  lockdown, which would be the fail-closed direction to be wrong in if
  the reasoning is off. Nobody has set the constant and actually checked.

**The independent-review method worked, once, concretely.** The agent was
told nothing about what had already been tested and explicitly instructed
to treat this file's own predecessor's claims as unverified. It found that
requesting an approval with no `target_hash` minted a grant that matched
*any* bare `current_user_can( 'install_plugins' )` check — which is how
nearly every native WordPress admin screen checks it — unconsumed and
reusable for the full 120-second window, since only this prototype's own
harness route ever called `cap_floor_consume_approval()`. One password
check bought a blanket, repeatable unlock of the real upload-plugin form
and the native plugin-install screen, on both single-site and multisite.
That is the same standing-privilege problem this design exists to remove,
merely time-boxed. `target_hash` is now mandatory, on both the request side
and the checking side; see `capability-floor-approvals.php` for the fix and
test 9 in `tests/regression.sh` for the regression coverage.

**This also clarifies the claim's actual shape, not just its safety.**
Because a target is now mandatory and native WordPress code never passes
one to a bare capability check, an approval issued by this prototype can
**only ever be consumed through its own purpose-built REST route** — never
through the native wp-admin UI. That was always the design's intent; it
was not, until this fix, what the code actually enforced.

**A second independent agent, on already-"fixed" code, found four more
issues in one pass.** All four are fixed here; the point of naming them is
what they say about the first pass, not just the second.

1. **The binding cookie was not actually independent (High).** The
   `admin_init` hook minted a binding for any logged-in user on any
   wp-admin page — `is_user_logged_in()`, not scoped to this prototype's
   own surface at all. An attacker holding only a stolen `LOGGED_IN_COOKIE`
   needed one plain GET, to a page they'd have to guess or find, to mint
   their own second factor and proceed as if they'd cleared it honestly.
   Fixed by narrowing the hook to the harness page's own slug,
   `cap-floor-harness`, matching the actual narrower pattern `#470` used
   (which this prototype had deviated from without noticing). **This is a
   walk-back, not a re-confirmation**, of this file's own earlier claim
   that a leaked login cookie alone was insufficient — see below.
2. **A failed database insert was reported as success (Medium).** An
   over-length `target_hash` silently failed the `INSERT`, but the
   function still returned a fresh approval ID as though a row existed —
   burning a real password check and a rate-limited attempt on a phantom
   grant that could never be redeemed. Fixed by checking `$wpdb->insert()`'s
   return value and rejecting oversized input before attempting the write
   at all.
3. **The binding registry grew without bound (Low).** Expired entries in
   the `cap_floor_bindings` option were never pruned. Fixed with
   opportunistic cleanup on each write.
4. **REST parameters were cast without checking their type first (Low).**
   Array input to a string parameter triggered a PHP conversion warning
   that printed ahead of the JSON response body, corrupting the HTTP
   status the client saw and leaking this file's server path in plaintext.
   Fixed by validating `is_string()` before any cast, on every affected
   parameter on both routes.

**Walked back: the claim that a leaked `LOGGED_IN_COOKIE` alone is
insufficient does not fully hold, even after fix #1 above.** The honest
version: minting now requires a GET to one specific, publicly-discoverable
admin URL rather than any admin page at all — that raises the cost and
narrows the exposure, but it does not close it. An attacker who can freely
replay a stolen login cookie in their own requests can simply also request
`/wp-admin/tools.php?page=cap-floor-harness` with it; I reproduced this
directly — a plain GET to `/wp-admin/index.php` with only stolen cookies no
longer mints a binding, but the same request to the harness page URL still
does. Nobody has verified whether the equivalent question — attacker holds
only the stolen session cookie, requests `#470`'s own equivalent research
page directly — was ever tested against `#470` itself, across all of this
session's extensive adversarial work there. That is an open gap in `#470`'s
evidentiary record, not just this prototype's, and it is not currently
flagged anywhere in the archived Sudo repo's `docs/finding.md`.

## Defends against — verified, not asserted

- **No account holds a dangerous capability at rest — now checked
  individually against a real native trigger for every capability on the
  list except one.** The list itself has grown twice past its original
  sixteen: `remove_users` and `install_languages` were both simply absent
  under any name until found, not denied-then-bypassed. Confirmed against
  real native wp-admin screens or direct capability checks for
  `install_plugins`, `create_users`, `edit_users`, `delete_users` (the
  last two only after the meta-cap indirection fix), `remove_users` and
  `install_languages`/`update_languages` (both only after being added to
  the list at all), and — as of a fourth fresh-context agent's targeted
  sweep — `edit_plugins`, `edit_themes`, `upload_plugins`, `upload_themes`,
  `update_plugins`, `update_themes`, `delete_plugins`, `update_core`, and
  `unfiltered_html`, all denied correctly on both single-site and
  multisite Super Admin against their actual native screens (theme/plugin
  editors, upload forms, bulk update/delete actions, and a real
  KSES-stripped `<script>`/`onclick` injection attempt). **`edit_files` is
  the one exception, and not because it's unverified** — the agent found,
  by grepping all of wp-admin and wp-includes, that no native WordPress
  code anywhere calls `current_user_can( 'edit_files' )` on its own; it
  exists only as a shared label inside core's own `map_meta_cap()` switch
  alongside `edit_plugins`/`edit_themes`. There is no real screen to have
  tried it against. Every one of these four rounds found something
  wrong the first three times a genuinely new corner was checked
  (`edit_users`, `remove_users`, `install_languages`) and nothing wrong
  the one time a broad, systematic sweep of previously-untouched ground
  was run instead — evidence the list itself is now reasonably solid, not
  proof it's complete.
- **Each dangerous action requires its own fresh, single-use approval**,
  scoped to the actor's own identity (no switch), their login session, a
  second independent `__Host`-bound cookie, and — where bytes matter — a
  content digest.
- **One account cannot read, consume, or rate-limit-interfere with
  another's approval.** Tested with two real accounts on both single-site
  and multisite.
- **A leaked Application Password alone is insufficient** — no session
  token, refused before any password check runs.
- **Minting a binding cookie requires visiting this prototype's one named
  harness page, not just holding a `LOGGED_IN_COOKIE`.** A second
  independent agent found that the original fix here overclaimed: the
  `admin_init` hook that mints the binding fired on `is_user_logged_in()`,
  meaning any stolen login cookie could mint a binding with one GET to
  *any* wp-admin page. It is now scoped to the single harness page URL. See
  "Walked back" below — this is a narrower, verified claim than the one
  this line originally made, not the same claim re-confirmed.
- **Concurrent redemption of one approval has exactly one winner**; the
  rate limiter releases on a rolling time window rather than locking an
  account out permanently, which an earlier version of this same file did
  not do correctly, and which is the exact defect-class that made `#470`'s
  own rate limiter a shipping blocker.
- **A consumed approval cannot be replayed, and cannot be retargeted** to a
  different digest than the one it was issued for.
- **No untargeted approval can exist.** `target_hash` is mandatory at
  request time and mandatory at the matching side; a bare capability check
  with no target argument cannot match any approval, ever. This is the fix
  for the master-key finding above, not merely a mitigation of it.
- **A real administrator cannot open another user's edit screen and change
  their profile with no reauthentication.** This was false, for the entire
  time `edit_users` has been on the denied list, until the meta-cap
  indirection fix above. Reproduced fixed: `user-edit.php` for a second
  account now returns "Sorry, you are not allowed to edit this user."
- **The second gated effect (`create_users`) works end-to-end the same way
  the first one does.** Wired the same day as this correction, using the
  identical digest-bind-consume pattern as `/install-plugin`; see
  "Generality across effects" below for what that does and doesn't prove.

## Does not defend against — named, not merely implied

- **The same meta-cap indirection bug, in a capability nobody has tried
  yet.** The fix above covers exactly the two cases found so far
  (`edit_user`→`edit_users`, and `delete_user`→`delete_users`, which turned
  out to already be safe for an unrelated reason). It is a general fix
  (checks the resolved `$caps` array, not a name-by-name patch), so it
  should generalize — but "should" is a claim about the mechanism, not a
  report of having tried the other ten denied capabilities against their
  own native screens one by one. Nobody has.

- **A full cookie-jar clone.** By construction this almost certainly
  succeeds the same way it does against `#470` — the binding cookie is the
  same class of bearer secret as the login cookie, just a second instance
  of it. **This is inferred from the mechanism's shape, not independently
  tested.** Do not read it as verified until someone actually tries it.
- **Active same-origin script during the approval window.** Zero browser
  or DOM-level testing exists for this prototype. `#470` had a 15-test
  Playwright suite probing exactly this; this prototype has none. This is
  a real gap, not a stated concession backed by evidence.
- **The intent-signal problem.** `map_meta_cap()` fires identically for a
  user's deliberate action and for incidental checks — menu rendering, the
  admin bar — that happen to touch the same capability. This prototype
  does not and cannot distinguish them; it only gates the commit point
  itself. Named already in `cap_floor_valid_approval_id()`'s own docblock,
  restated here because it belongs in the claim, not just the code comment.

  **Two approaches to this were built and tested, and the prediction going
  in was wrong.** The question was how a *native* wp-admin action — not a
  bespoke REST call — could ever be let through, given that the master-key
  fix makes a null-target capability check unable to match any approval,
  and native `users.php`'s `dodelete` handler checks the bare, targetless
  `current_user_can( 'delete_users' )` first.

  - **Approach A (JS intercept, `assets/users-delete-intercept.js`)**
    claims the real "Delete" link's click before the browser follows it and
    routes the mutation through `/wp-json/cap-floor/v1/delete-user`. The
    server-side trust boundary is unchanged — it is the same narrow,
    single-entry-point route pattern as the other two effects. Verified
    working in a real browser against a real click.
  - **Approach B (`experimental-dispatch-gate.php`)** matches the real
    native POST at `admin_init` — one action name, one screen, one request
    method, single-target only — consumes the approval there, and grants a
    request-scoped override so core's own two checks pass. **This is a
    reconstruction of the request-shape-matching pattern that the earlier
    WP Sudo work concluded was fragile, and I predicted it would break the
    same way.** It did not break under the convergence tests aimed at it:
    the same parameters sent as GET are refused *and leave the approval
    unconsumed*; core's own REST `DELETE /wp/v2/users/{id}` is refused even
    with a matching approval pending (it passes an integer ID, never a
    string digest, so it cannot match by construction); five concurrent
    real form POSTs sharing one approval produce exactly one winner.

  **What that does not prove.** It survived the specific attacks aimed at
  it in one sitting, on one action, on one screen, by the person who wrote
  it — which is precisely the evidentiary situation that has been wrong
  five times already in this file's history. The known costs are real and
  unfixed: the approval is consumed at `admin_init`, *before* core's own
  `check_admin_referer()` runs, so a subsequent nonce failure spends the
  grant with no deletion performed; and the whole path is single-site-only,
  because core `wp_die()`s on multisite before any capability check (a
  guard had to be added so a multisite attempt would not burn an approval
  against a screen that always dies). Most importantly, the argument
  against this pattern was never "it fails one screen's tests" — it was
  that it needs a correct request-shape matcher *per action, per surface,
  forever*, and one screen passing says nothing about the twentieth. Treat
  Approach A as the design and Approach B as a recorded experiment.

- **WP-CLI, for any of this.** `wp user delete` deletes the user with no
  `current_user_can()` consultation at all — confirmed live, with zero
  approvals ever issued. That is WP-CLI's own long-standing behavior, not
  something this prototype introduced or could fix from inside
  `map_meta_cap()`; noted because a reader could otherwise reasonably
  assume "the floor denies `delete_users`" implied CLI coverage. It does
  not.
- **Code that never calls `current_user_can()`.** Same structural limit as
  every capability-layer defense discussed this session. Nothing here
  reaches a vulnerability that skips WordPress's permission system.
- **Filesystem-level compromise.** Anyone with write access to `mu-plugins`
  edits the rule enforcing all of this.
- **Generality across effects — narrowed again, still not closed.** Three
  effects are now wired, each digesting the exact fields its approval
  covers before the matching `current_user_can( $cap, $target_hash )`
  re-check, and consuming at the real commit point: plugin install
  (`install_plugins`, digesting uploaded bytes), user creation
  (`create_users`, digesting `username`+`email`+`role`), and user deletion
  (`delete_users`, digesting `user_id`+`reassign`). The third was chosen
  specifically because it is structurally unlike the first two — it targets
  an **existing** resource by ID rather than creating fresh content with a
  natural fingerprint — and it needed no change to the approval mechanism
  to work. Regression coverage now exists for all three (tests 1–27).
  Still not a demonstrated framework: every effect so far reduces to a
  single deterministic digest. An effect with no natural single digest, or
  one needing several related approvals at once, or a genuine bulk
  operation over many targets, has not been attempted — `/delete-user`
  explicitly refuses a multi-target batch rather than pretending to handle
  one.
- **Native wp-admin affordances, once floored, disappear rather than
  fail.** Found empirically while wiring the delete effect: WordPress's own
  users-list table builds its row-action links from the very capability
  checks the floor denies, so with `delete_user`/`edit_user` denied, the
  "Delete" and "Edit" links are never rendered for any other user at all —
  there is no native button left to reauthenticate *through*. The floor's
  real-world effect is therefore not "the action is blocked pending
  approval" but "the action vanishes from the UI." Anything built on this
  design has to restore its own affordances (this prototype adds a purely
  cosmetic `user_row_actions` filter, granting nothing), which means a
  shippable version owns a growing surface of re-added UI, one screen at a
  time. Named here because it is a real design cost that no amount of
  server-side correctness removes.
- **Ordinary (non-Super-Admin) site administrators on multisite.** Core
  already denies them these capabilities independently
  (`is_multisite() && ! is_super_admin()` in core's own `map_meta_cap()`);
  this prototype's interaction with that existing denial has not been
  separately verified.

## How much to trust the passing regression suite

Most of it was written by the same person who wrote the fixes it verifies,
using the same method — hypotheses generated by re-reading my own code —
that missed the master-key gap, then missed the binding-cookie-scope gap
and three more, on two separate passes. Tests 9–11 exist because someone
else found what they cover; the suite's author had no reason to doubt
`target_hash` being optional, or `is_user_logged_in()` being the right
scope for minting a binding, until an outside perspective found otherwise
— twice, on two different rounds of already-"tested" code. Passing is
evidence the *known* findings don't regress. It is not evidence there are
no more findings, and this file having needed two separate corrections in
one day, from two separately spawned agents, each finding what the other
didn't, is the argument for that — not just an assertion of it.

**The third finding — the `edit_users` meta-cap bypass — wasn't found by
an agent, or by the suite. It was found by the person who wrote both,
clicking through wp-admin like an ordinary user, after two rounds of agent
review had already run.** That is a different, and arguably more
uncomfortable, data point than "agents catch what the author misses": the
author's own attention, method, and the two agents' assigned scope all
missed the same thing at the same time, for the same underlying reason —
nobody had tried the single most direct test of the floor's headline claim
against a capability that wasn't the one already-wired effect. A fourth
fresh pass, scoped explicitly to try that, is the obvious next check, not
a fifth thing to hope doesn't exist.
