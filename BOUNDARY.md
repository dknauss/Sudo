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
dropped, and which surfaced a sharper problem than the one it set out to
test: on multisite the approved capability and the performed effect were
not the same thing, and only reading core's source revealed it. This is the claim as it stands *today*, not aspirational, and not
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
one to a bare capability check, an approval issued by this prototype could
at that time only be consumed through its own purpose-built REST route,
never through the native wp-admin UI.

**That is no longer true, and this paragraph asserted it for a full day
after it stopped being true.** `experimental-dispatch-gate.php` (added
2026-08-03) deliberately consumes an approval from the *real* native
`users.php` POST and lifts the denial for core's own bare
`current_user_can( 'delete_users' )` check. An Opus-tier claims audit
reproduced it: mint an approval, POST the genuine native form, HTTP 302,
user deleted, no REST route involved. The document described that
experiment four sections below while still claiming here that it could not
happen, and `tests/regression.sh` test 25 asserts the contradiction in as
many words. Corrected rather than deleted, because the failure mode — a
claim that was true when written and silently falsified by a later
change — is the one this file is least able to catch on its own.

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
version: minting requires a GET to one of a small, fixed set of specific,
publicly-discoverable admin URLs rather than any admin page at all — that
raises the cost and narrows the exposure, but it does not close it. An
attacker who can freely replay a stolen login cookie in their own requests
can simply request one of those URLs with it; I reproduced this directly —
a plain GET to `/wp-admin/index.php` with only stolen cookies mints
nothing, but the same request to a minting URL still mints a valid
binding.

**That set grew from one URL to two on 2026-08-03**, when
`/wp-admin/users.php` was added alongside
`/wp-admin/tools.php?page=cap-floor-harness` so the intercepted native
Delete button could work.

**And "two URLs" was itself false — on two independent counts, both found
the same day by an Opus-tier claims audit and an Opus-tier adversarial
pass working separately.**

1. The `admin_init` hook keyed the harness mint off `$_GET['page']` alone,
   with no check on *which* admin script was running. Since `page` is an
   attacker-supplied query parameter that any admin entry point will
   carry, minting was reproducible at `/wp-admin/index.php?page=…`,
   `edit.php`, `options-general.php`, and `admin-ajax.php` (which fires
   `admin_init`). That is not two URLs; it is one query string against
   essentially the whole admin, including its best-known endpoint.
2. **Worse: the REST route minted.** `cap_floor_require_binding()` — whose
   own docblock said it "deliberately requires a binding that already
   exists rather than minting one here … Minting on demand here would
   silently defeat the whole fix" — called the minting variant with no way
   to forbid it. Presenting a *junk* binding cookie fell through the
   validity check into the mint branch, so the 403 refusal shipped a
   freshly registered binding in `Set-Cookie`. Reproduced end to end: junk
   cookie → 403 + valid binding; replay that value with only a stolen
   login cookie → 201, a real approval. Regression test 5 never caught it
   because it only ever sent *no* cookie, never a wrong one.

Both are now fixed (`$pagenow` is checked for both entry points;
`cap_floor_binding()` takes an explicit `$may_mint` flag and
`cap_floor_require_binding()` passes `false`), with regression tests 11b
and 11c covering them including a positive control.

**What this means for the claim, stated plainly: for most of this
prototype's existence the binding cookie provided no meaningful protection
against a login-cookie thief at all.** Not "modest extra cost" — none. The
adversarial pass reached the same conclusion independently and rated its
half LOW *precisely because* the document already conceded the threat; the
audit's half shows the concession was more complete than intended. Fixing
both restores the modest-cost position the document claimed, and the
underlying limit is unchanged: the binding is the same class of bearer
secret as the login cookie, so an attacker who can replay one can obtain
the other.

The increment from one URL to two is small, but the direction is the point — **under Approach A,
every native screen given a real elevate flow has to become a minting
URL, so this set grows monotonically as the design covers more of
wp-admin.** A version of this idea covering a realistic slice of the admin
would have minting reachable from most of the screens an attacker would
want, at which point "scoped to a few known URLs" stops being a
meaningful narrowing at all. This is a structural cost of the approach,
not a bug to fix in it.

Nobody has verified whether the equivalent question — attacker holds only
the stolen session cookie, requests `#470`'s own equivalent research page
directly — was ever tested against `#470` itself, across all of this
session's extensive adversarial work there. That is an open gap in
`#470`'s evidentiary record, not just this prototype's, and it is not
currently flagged anywhere in the archived Sudo repo's `docs/finding.md`.

## Defends against — verified, not asserted

- **No account holds a dangerous capability at rest — individually
  verified for 16 of the 19 capabilities on the list.** The counts here
  were wrong until an Opus-tier audit checked them against the code: the
  list held **17** entries originally, not "sixteen", and holds **19**
  now after `remove_users` and `install_languages` were found simply
  absent under any name (not denied-then-bypassed). The related claim that
  round 4 swept "the ten capabilities nobody had individually tried" was
  also wrong — 13 were untried at that point, and it covered nine.
  **`install_themes`, `delete_themes` and `promote_users` have never been
  named as individually checked here.** The auditor did check all three
  and found them correctly denied — `promote_users` against its real
  native trigger (the `users.php` bulk "Change role to administrator"
  POST, which core gates at `users.php:113`): 403, target's role
  unchanged. So this was a documentation-accounting failure, not a
  bypass, but it is exactly the kind of miscount that makes a coverage
  claim worthless. Confirmed against
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
  tried it against. (Strictly, "only inside the switch" overstates it: it
  is also granted as a real role capability at
  `wp-admin/includes/schema.php:765`. Nothing reads it, but it exists in
  more than one place.) Every one of these four rounds found something
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
- **Minting a binding cookie requires visiting one of this prototype's two
  named elevate-flow screens (`tools.php?page=cap-floor-harness` or
  `users.php`), not just holding a `LOGGED_IN_COOKIE`.** This bullet has
  now been wrong twice and should be read with that in mind. It first
  claimed the binding was independent of the login cookie, which a second
  agent disproved (the mint hook fired on `is_user_logged_in()`, so any
  admin page minted). It was then corrected to "the single harness page
  URL" — still wrong, on two counts an Opus-tier audit and adversarial
  pass found separately: the hook matched `$_GET['page']` on *any* admin
  script including `admin-ajax.php`, and the REST route itself minted when
  handed a junk cookie. Both fixed; regression tests 11b/11c now cover
  them. See "Walked back" above for the full account — the honest summary
  is that this protection did not exist for most of the prototype's life,
  and the claim survived two prior corrections without becoming true.
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
  claim about every capability having been individually exercised. Round 4
  did subsequently try nine of them against their real native screens (see
  above); an earlier version of this bullet said "nobody has" and was left
  unrevised after that round, which an Opus-tier audit flagged as
  self-contradicting.

- **A full cookie-jar clone.** By construction this almost certainly
  succeeds the same way it does against `#470` — the binding cookie is the
  same class of bearer secret as the login cookie, just a second instance
  of it. **This is inferred from the mechanism's shape, not independently
  tested.** Do not read it as verified until someone actually tries it.
- **Active same-origin script during the approval window.** Zero browser
  or DOM-level testing exists for this prototype. **An earlier version of
  this bullet claimed `#470` had "a 15-test Playwright suite probing
  exactly this." That was wrong** — an Opus-tier audit checked the
  archived Sudo repo and found the 15 tests confirm the §2.4 cookie
  boundary, while §4.2 names active same-origin script an explicit
  *non-goal*. So `#470` conceded this rather than testing it, and the
  correction matters twice over: the gap here is real either way, but a
  claim about an external artifact was asserted without being checked
  against that artifact — the exact failure mode this project'"'"'s
  verification rules exist to prevent.
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

- **The flagship effect does not perform the dangerous action it names —
  and never did, through four rounds of review that treated it as the
  proof the design works.** An audit of what each guarded function
  actually does, prompted by the multisite finding below, checked
  `install_plugins` next. `phase27_real_upgrader_effect()` runs the
  genuine `WP_Upgrader` package pipeline, but against an isolated
  destination under `wp-content/uploads/`, which it then deletes. Nothing
  ever reaches `WP_PLUGIN_DIR`; WordPress never loads anything new.
  Confirmed live: `wp plugin list` and the contents of
  `wp-content/plugins/` are byte-identical before and after a `200`
  response that used to read `{"status":"installed"}`.

  The sandboxing itself is correct and deliberate — it is `#470`'s test
  infrastructure, and a suite that really installed plugins on every run
  would be worse. The response string was the defect, and it has been
  corrected to `upgrader_pipeline_completed` with an explicit
  `"installed": false`. **What has to be walked back is this document's
  own framing.** Earlier revisions described "one real effect... plugin
  install via the real `WP_Upgrader`" and counted it as the demonstrated
  case. The accurate statement: the approval machinery around that route
  is real and well-tested, but the dangerous effect it guards is a
  sandboxed no-op, so "this design gated a genuine plugin installation
  end-to-end" was never demonstrated and is not demonstrated now.

  Of the three wired effects, **two perform genuinely irreversible real
  work** — `create_users` really creates an account and `delete_users`
  really deletes one, both on single-site; both are now refused on
  multisite (see below). The one presented as the flagship for four review
  rounds does nothing. That is close to the inverse of the impression the
  earlier text gave.

  **An earlier version of this paragraph said `create_users` was "audited
  on both site types," citing `is_user_member_of_blog`. That audit was
  shallow and this document repeated its conclusion as settled** — an
  Opus-tier claims audit then found the multisite twin of the delete
  finding sitting in the very effect just called clean. Checking
  membership and role answers "did a usable account result?", not "is
  this the effect core's own `create_users` path performs here?" It is
  not: see the `create_users` bullet below.

- **`create_users` on multisite: the same finding again, in the effect
  that had just been declared clean.** Found by an Opus-tier claims audit
  immediately after the delete case below was fixed and this document had
  moved on. Core's own multisite `create_users` path
  (`wp-admin/user-new.php`, the `is_multisite()` branch) runs
  `wpmu_validate_user_signup()` and then `wpmu_signup_user()`, producing a
  **pending signup the invitee must confirm by email**; activating without
  that confirmation is gated on a *second* capability,
  `manage_network_users` ("Skip Confirmation Email"). Calling
  `wp_insert_user()` directly, as this route did, skips all of it. The
  auditor reproduced it: a `create_users` approval produced a live,
  network-wide account with the **administrator** role, instantly, with
  zero rows in `wp_signups`, and bypassed the username policy
  `wpmu_validate_user_signup()` enforces. So the approval performed
  strictly *more* than the capability it names authorizes. Now refused
  with 501 on multisite, like `delete_users`. **Two of the three effects
  turned out to mean something different on multisite than the capability
  they were approved under, and in both cases the mechanism was working
  perfectly — this is not a bug class the approval layer can detect.**

- **The same approval meaning different things on different site types.**
  Wiring the third effect turned this up, and it is the most substantive
  finding of the exercise. `wp_delete_user()` does not delete an account on
  multisite: core's own docblock states that "on a Multisite installation
  the user only gets removed from the site and does not get deleted from
  the database," and that is what happened live — the route returned
  `{"status":"deleted"}`, the site role was stripped, and the account
  remained intact network-wide. So an approval the human authorized as
  `delete_users` would have performed a *site removal*: the effect core
  governs with the separate `remove_users` capability, which this floor
  denies independently, reported back under the wrong name.

  Nothing about the approval machinery failed here — digest, binding,
  single-use consumption all behaved correctly. **The gap was between the
  effect the person approved and the effect the underlying function
  performs**, which is precisely the failure this design exists to prevent
  and which no amount of correctness in the approval layer detects. The
  route now refuses on multisite with 501 rather than performing the
  lesser action or renaming its response; implementing multisite removal
  honestly means its own approval under `remove_users`, which is not
  wired. The general lesson is uncomfortable for the whole approach: an
  approval is only as meaningful as the caller's understanding of what the
  function it guards actually does, and that understanding is not
  something the mechanism can check.

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
  re-check, and consuming at the real commit point: the upgrader pipeline
  (`install_plugins`, digesting uploaded bytes — but see above: this one
  installs nothing), user creation (`create_users`, digesting
  `username`+`email`+`role`), and user deletion (`delete_users`, digesting
  `user_id`+`reassign`). The third was chosen
  specifically because it is structurally unlike the first two — it targets
  an **existing** resource by ID rather than creating fresh content with a
  natural fingerprint — and it needed no change to the approval mechanism
  to work. Regression coverage exists for all three on **single-site**
  (tests 1–27, 47 assertions). On multisite the picture is thinner than
  the numbering suggests and an Opus-tier audit was right to flag the
  earlier phrasing as overstated: tests 24–27 are skipped (announced in
  the run footer), and tests 12–15 and 19–23 each collapse to a single
  refusal scenario, because both user effects are now 501 there. So
  multisite has **zero positive coverage** of either user effect —
  necessarily, given they are refused, but "coverage for all three" read
  as more than it was.
  Still not a demonstrated framework: every effect so far reduces to a
  single deterministic digest. An effect with no natural single digest, or
  one needing several related approvals at once, or a genuine bulk
  operation over many targets, has not been attempted. (An earlier version
  said `/delete-user` "explicitly refuses a multi-target batch." It does
  not: the REST route takes a scalar `user_id` and has no batch parameter
  to refuse, while the dispatch gate silently `return`s on a count
  mismatch and lets native code deny. The outcome is safe — an adversarial
  pass confirmed a two-target POST is refused with both users surviving
  and the approval unburned — but "explicitly refuses" described code that
  does not exist.)
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

**A fifth data point, and it is a different kind again.** The multisite
`delete_users`/`remove_users` mismatch above was not found by an agent,
not by clicking through wp-admin, and not by the suite — the suite in fact
*passed* that route on single-site and only failed on multisite for what
initially looked like a fixture bug. It was found by reading core's own
docblock for the function being called, after a test failure that could
easily have been "fixed" by relaxing the assertion instead. That is worth
naming precisely because the cheap repair was available and would have
buried a real finding: the test was asserting the right thing, and the
code was doing the wrong thing, in the direction where the response text
said "deleted" and the account still existed. The general form — *verify
what the guarded function actually does, in the context it will run in,
rather than what its name implies* — is not a check any of the four
review rounds performed, on any effect, and has still only been done for
this one function.

**A sixth round, and the first run at a model tier above the code's own
author.** Every prior review agent inherited the session model — so four
rounds of "independent fresh-context review" were the same tier reviewing
its own work. The independence was real; the capability ceiling was
shared. Two Opus-tier agents were then run concurrently with deliberately
different framings: one adversarial (excluded from this file and the
suite, as always), one auditing *this document's claims* against the code,
the tests, and core source. Neither framing had been used before.

What that produced, and why the split matters:

- **The adversarial pass found essentially nothing new.** One
  informational finding — the binding mint keying off `$_GET['page']`,
  which it correctly rated LOW because this document already conceded the
  threat — and a long list of attacks that correctly held: the dispatch
  gate's single-target binding, batch abuse, target mismatch without
  burning the approval, digest confusion via leading zeros, duplicate
  cookie smuggling, cross-admin theft, rate limiting under 12-way
  concurrency (exactly 3 of 12 evaluated, against a limit of 3).
- **The claims audit found eight false-or-stale claims and six
  overstatements in this file**, including two that were security-relevant
  rather than editorial: the REST route minting bindings, and
  `create_users` bypassing multisite's signup flow. It also found a
  vacuous regression test (test 2 would have passed with cross-user
  isolation deleted outright) and dead code in the suite's own multisite
  cleanup that had been silently failing.

**The lesson is not "Opus finds more."** It is that the *question asked*
dominated the tier. Five rounds of "find bugs in this code" had already
hit diminishing returns; the first round of "is this document telling the
truth" found a live security defect on its first pass, because the
security defect was hiding inside a claim nobody had been asked to check.
The mint hole in particular had survived four adversarial rounds *and* a
targeted fix for the exact same class of bug, because every round tested
the no-cookie case the document described rather than the wrong-cookie
case it did not.

The corollary for anything built on this: a claims audit is not
documentation hygiene, and should not be scheduled as if it were.
