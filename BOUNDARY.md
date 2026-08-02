# What this prototype actually claims

Written 2026-08-02, after the binding-cookie fix and the first regression
suite, both same-day, and revised the same day twice more: once after an
independent fresh-context agent — given the live instances and the raw
source, but not this file or the regression suite — found a high-severity
defect in under ten minutes that the suite it's revising had not covered,
and again after a second, separately spawned fresh-context agent, given the
same access and the same exclusions, found four more defects in code that
had already been "fixed" and re-tested once. This is the claim as it stands
*today*, not aspirational, and not identical to what an earlier version of
this prototype — or an earlier version of this file — would have supported.

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

- **No account holds a dangerous capability at rest.** Denial happens in
  `map_meta_cap()`, confirmed to reach a multisite Super Admin, where an
  earlier, discarded `user_has_cap`-based version did not.
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

## Does not defend against — named, not merely implied

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
- **Code that never calls `current_user_can()`.** Same structural limit as
  every capability-layer defense discussed this session. Nothing here
  reaches a vulnerability that skips WordPress's permission system.
- **Filesystem-level compromise.** Anyone with write access to `mu-plugins`
  edits the rule enforcing all of this.
- **Generality across effects — narrowed, not closed.** A second effect,
  real `wp_insert_user()` user creation gated on `create_users`
  (`/wp-json/cap-floor/v1/create-user`), was wired the same day using the
  same pattern as `/install-plugin`: digest the exact fields the approval
  covers (here, `username`+`email`+`role`, newline-joined) before the
  matching `current_user_can( 'create_users', $target_hash )` re-check, and
  consume at the real commit point. It worked on the first attempt — no
  fix was needed to make it behave like the plugin-install effect — and the
  same negative checks held: the native "Add New User" screen still denies
  outright (no target argument to match), and replaying the consumed
  approval against the same user details returns `cap_floor_digest_mismatch`
  rather than creating a second account. That is **two** proofs of concept
  now, not a demonstrated framework — no regression-suite coverage exists
  for this second route yet (unlike plugin-install's tests 1–11), and every
  effect wired so far has been a single, simple, string- or byte-digestible
  target. A structurally different one — an effect with no natural single
  digest, or one requiring several related approvals at once — has not been
  attempted and may not fit this pattern as easily.
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
didn't, is the argument for that — not just an assertion of it. A third
fresh pass would very plausibly find a fifth thing.
