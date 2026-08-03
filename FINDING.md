# The capability floor: what it proved, and why it does not ship

**Status:** research prototype, concluded 2026-08-03. Not a product, not a
proposal. This document states what was built, what survived adversarial
testing, what defeated it, and which parts are worth carrying forward.

Companion documents: `BOUNDARY.md` records claim-by-claim what is and is
not verified, including every claim in this file's history that turned out
to be wrong. `tests/regression.sh` is the executable record.

---

## 1. The question

WordPress administrators hold dangerous capabilities permanently. An
attacker who obtains an admin session obtains, with it, the ability to
install code, create accounts, and edit users — immediately and silently.

The predecessor work (WP Sudo, archived) tried to gate this by matching
*requests*: recognising a dangerous action by its URL, action name, and
parameters, then interposing reauthentication. That approach lost to
surface convergence — the same effect is reachable through admin POSTs,
AJAX, REST, XML-RPC, and CLI, and the matcher has to be right about all of
them, forever.

This prototype asked a different question:

> Instead of recognising dangerous *requests*, what if no account held
> dangerous *capabilities* at rest, and each dangerous action required its
> own fresh, single-use, cryptographically scoped approval?

## 2. What was built

Two layers, ~1,580 lines of PHP across five `mu-plugins` files (much of it
comments recording why each guard exists and which review round forced it).

**The floor** (`capability-floor-prototype.php`). A `map_meta_cap` filter
appends `do_not_allow` to a fixed list of 19 dangerous capabilities, for
every account, unconditionally. `map_meta_cap` rather than `user_has_cap`
is load-bearing: `WP_User::has_cap()` returns early for a multisite Super
Admin *before* the `user_has_cap` filter runs, and that early-return branch
checks the `$caps` array `map_meta_cap` produced. A `user_has_cap`-based
floor is silently bypassed for exactly the account that matters most.

**The approval** (`capability-floor-approvals.php`). A single-use grant
that lifts the denial for exactly one action. Each approval is bound to:

- the actor's own user ID — no identity switch, no ghost account
- their login session token (`wp_get_session_token()`)
- a second `__Host`-prefixed, `SameSite=Strict` binding cookie
- a **content digest** of the specific thing being authorised
- a 120-second expiry
- atomic single-use consumption at the real commit point

Requesting one costs a fresh password check, rate-limited to 3 failures per
rolling 5-minute window, serialised with `SELECT … FOR UPDATE`.

**Three effects** were wired through a REST harness: plugin install, user
creation, user deletion.

## 3. What held

The enforcement kernel is sound. This is not a hedge — it is the result of
six independent review rounds, the last two at a model tier above the
code's author, and it is the part of this work worth taking seriously.

Verified, empirically, against live single-site and multisite WordPress
7.0.2 (see `tests/regression.sh`, 47 assertions single-site / 27 multisite):

- **Denial reaches a multisite Super Admin**, where the discarded
  `user_has_cap` approach did not.
- **No untargeted approval can exist.** A bare `current_user_can( 'x' )`
  with no target argument — how essentially all native WordPress code
  checks capabilities — can never match any approval. This closed the
  single worst defect found (§6.1) and is load-bearing.
- **Cross-user isolation.** An approval requested under one session cannot
  be consumed under another, with the correct ID *and* a matching digest.
- **Digest binding resists confusion.** Leading zeros, altered reassignment
  targets, and mismatched content all refuse — and a mismatch does not burn
  the approval.
- **Exactly one winner** under concurrent redemption, every time.
- **The rate limiter is genuinely serialised**: 3 of 12 simultaneous
  wrong-password attempts evaluated, against a stated limit of 3.
- **Application Passwords cannot bootstrap an approval** — no session
  token, refused before any password check.
- **Duplicate-cookie smuggling, batch abuse, GET replay of a POST-only
  path, and core's own REST `DELETE /wp/v2/users/{id}`** all refuse, the
  last by construction (it passes an integer ID, never a string digest).

An Opus-tier adversarial pass, told explicitly to attack the newest and
least-reviewed code, found **one informational issue** and a long list of
attacks that correctly held.

## 4. What defeated it

Not the mechanism. The **referent**.

An approval is bound to a capability *name*. It is redeemed against an
*effect*. The prototype assumed those correspond. They do not — and the
gap is invisible to the approval layer by construction, because every
cryptographic property can be perfectly satisfied while the effect
performed is not the effect approved.

Three effects were examined closely. All three were wrong.

| Effect | Approved capability | What actually happened |
|---|---|---|
| Plugin install | `install_plugins` | **Installs nothing.** Runs the real `WP_Upgrader` pipeline into a throwaway directory under `uploads/`, deletes it. `wp-content/plugins/` byte-identical before and after. Reported `"installed"` for a full day. |
| User creation | `create_users` | On multisite, **performs more than the capability authorises.** Core's own path (`wpmu_validate_user_signup` → `wpmu_signup_user`) creates a *pending signup requiring email confirmation*; instant activation is gated on a second capability, `manage_network_users`. Calling `wp_insert_user()` directly produced a live network-wide administrator instantly, bypassing the username policy too. |
| User deletion | `delete_users` | On multisite, **performs a different capability's effect.** `wp_delete_user()` does not delete there — core's own docblock says it "only gets removed from the site and does not get deleted from the database." That is a *removal*, which core governs with the separate `remove_users` capability. Reported `"deleted"` while the account survived network-wide. |

Two of the three had to be refused outright on multisite. The third does
nothing anywhere.

**This is the finding.** Not a bug list — a structural property. WordPress
capability names are not stable referents for effects. The same name means
different things on single-site and multisite; it sometimes names an effect
the function does not perform; it sometimes under-describes what the
function will do. Discovering this for any given effect required reading
core's source in both contexts. Nothing in the approval mechanism can
detect it, and no amount of hardening the mechanism helps.

## 5. Why this does not scale into a product

Four costs, each structural:

1. **Every effect carries a permanent per-context audit obligation.** Three
   effects examined, three mismatches, each found only by reading core
   source in two contexts. The list holds 19 capabilities and the real
   admin surface is larger. This is not a framework you extend; it is an
   audit you never finish, against a codebase that changes under you.

2. **The floor deletes affordances, not just permissions.** Core builds its
   row-action links from the same capability checks the floor denies, so
   with `delete_user` denied, no Delete link renders at all — there is no
   button left to reauthenticate *through*. Every screen needs its
   affordance re-added by hand (this prototype does it for exactly one
   link, on one screen, single-site only).

3. **The second factor cannot be one.** The binding cookie is the same
   class of bearer secret as the login cookie. Anyone able to replay one
   can obtain the other. This was conceded early — and then the
   implementation failed to achieve even the modest version twice more
   (§6.2), which is itself evidence of how little the concept was buying.

4. **WP-CLI bypasses everything.** `wp user delete` removes an account with
   no `current_user_can()` consultation at all. Nothing implemented inside
   `map_meta_cap` can reach it.

## 6. Selected defects, for the record

Ten real defects were found across six review rounds. Two mattered most.

**6.1 The master key.** Requesting an approval with no `target_hash` minted
a grant that matched *any* bare `current_user_can( 'install_plugins' )` —
which is how every native admin screen checks it — unconsumed, reusable,
for the full window. One password check bought a blanket unlock of the real
plugin-install UI. This is the same standing-privilege problem the design
exists to remove, merely time-boxed. Fixed by making the target mandatory
on both sides.

**6.2 The route minted the factor it required.** `cap_floor_require_binding()`
— whose own docblock stated that minting there "would silently defeat the
whole fix" — called the minting variant. Presenting a *junk* binding cookie
fell through the validity check into the mint branch, so the `403` refusal
shipped a freshly registered binding in `Set-Cookie`. Harvest it, replay
with only a stolen login cookie, receive a real approval. Separately, the
mint hook keyed off `$_GET['page']` with no check on *which* admin script
was running, so minting worked from `admin-ajax.php` and the dashboard.

For most of this prototype's existence, the binding cookie provided no
meaningful protection against a login-cookie thief at all.

## 7. The method result, which may outlast the architecture

Six review rounds. Every prior round inherited the session model, so four
rounds of "independent fresh-context review" were **the same capability
tier reviewing its own work** — the independence was real, the ceiling was
shared. The final round ran two agents concurrently at a higher tier, with
deliberately different framings.

- The **adversarial** agent — "attack this code" — found one informational
  issue. Five rounds of that framing had exhausted it.
- The **claims audit** agent — "is this document telling the truth about
  the code?" — found two live security defects, eight false-or-stale
  claims, six overstatements, a vacuous regression test that would have
  passed with cross-user isolation deleted outright, and dead code in the
  suite's own cleanup.

**The question asked dominated the model tier.** The mint hole (§6.2)
survived four adversarial rounds *and* a targeted fix for its own bug
class, because every round tested the no-cookie case the documentation
described rather than the wrong-cookie case it did not. It was found by
someone checking whether a sentence was true.

Two transferable rules:

- **Audit claims, not just code.** Once code has been hammered, the
  remaining defects hide inside assertions nobody was assigned to verify.
  This is not documentation hygiene and should not be scheduled as if it
  were.
- **Verify what the guarded function actually does, in the context it will
  run in, rather than what its name implies.** This check, applied three
  times, found three defects. No adversarial round performed it.

## 8. Verdict

**As a security mechanism: sound, and genuinely further than the
request-matching approach it replaced.** The scoped, single-use,
digest-bound, session-and-binding-bound approval token held against
everything thrown at it by reviewers more capable than its author.

**As a WordPress product architecture: not viable.** Defeated not by any
weakness in the mechanism but by the absence of a stable mapping from
capability names to effects, plus three further structural costs (§5) that
engineering effort does not reduce.

**What is worth carrying forward** is the approval primitive itself, and
the observation that it needs to live where effects are actually committed
— where a grant could be bound to an operation rather than to a name.
Bolting it onto an existing capability vocabulary from the outside is what
failed here. Whether it would work *inside* that boundary is untested and
should be treated as an open question, not an implied next step.

---

## Appendix: reproducing

Two disposable instances, WordPress 7.0.2:

```
npx @wordpress/env start                                  # single-site :8895
npx @wordpress/env start --config .wp-env.multisite.json  # multisite  :8897
bash tests/regression.sh http://localhost:8895 .wp-env.json password
bash tests/regression.sh http://localhost:8897 .wp-env.multisite.json floortest123
```

Known untested, and named rather than implied: behaviour behind real TLS
on a non-`localhost` hostname (the `Secure` binding cookie survives here
only because browsers treat `localhost` as a trustworthy origin — a
plain-HTTP deployment on a real hostname would silently disable the
approval flow entirely); interaction with a genuine `DISALLOW_FILE_MODS`
lockdown (reasoned fail-closed, not exercised); the binding registry's
read-modify-write race under concurrent mints; and browser-level
same-origin script during an open approval window, for which this
prototype has no test coverage of any kind.
