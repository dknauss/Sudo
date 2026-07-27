# #360 Slice B — scope: the `wordpress-develop` patch branch

## Status: assigned, start gated on the 4.9.0 tag

Coordination assigned Slice B to this session on 2026-07-27 and set the start condition:
**after 4.9.0 is tagged.** #418 (the last release PR) is merged; the pre-release claim audit
runs against a frozen tree, and a `wordpress-develop` branch landing mid-freeze is the
"proposal work and release train sharing a merge train" failure the external review named.
This document exists so the first hour of work after the tag is not spent re-deciding.

Slice A landed as a plugin at `poc/install-package-gate/` (#380) and needed **no** core patch.
Slice B is the part that does. Demonstrator (CA) owns `poc/`; if Slice B needs anything inside
that directory, ask them — do not reshape it from here.

## Decisions — settled

Coordination answered all three on 2026-07-27. Recorded with the reasoning, because the
reasoning is what makes them re-derivable if they are ever revisited:

1. **Fork location: published `dknauss/wordpress-develop`.** The entire value Slice A
   delivered over the pseudocode was that it *ran*, and that a test caught `install_package()`
   being too late (#387) — which no amount of reading found. A local scratch clone with
   hand-reported results reproduces the failure mode #360 exists to end. If CI cannot execute
   it, it is a sketch with better syntax.
2. **One-shot validation; the *findings* are made permanent, not the branch.** Permanent
   branches against a moving trunk buy anti-drift and cost a rebase treadmill — and the
   treadmill is what killed the sketch, maintained in parallel with an evolving spec until it
   silently diverged. Slice A shows the better pattern: the branch answered a question, and
   its finding (#387) became normative in the spec while the code stayed put. So: run it,
   extract what it proves, **cite the commit SHA in the spec**, and let the branch go stale
   deliberately and visibly. A stale branch with a pinned SHA is honest; a half-maintained
   one is the thing that lies.
3. **A failing cloned-session test blocks the proposal.** That test *is* the central claim —
   browser-bound proof means a copied session cannot exercise it. If it fails, the mechanism
   does not do what the proposal says and no surrounding work rescues that. Deciding this
   before the result is the point: afterwards, the temptation to reclassify it as "a PoC
   limitation" would be considerable.

This retires the "fork maintenance" risk below (decision 2 accepts staleness rather than
fighting it) and converts the "drift" risk: the branch is authoritative for mechanism **as of
its pinned SHA**, not in perpetuity.

## What Slice B patches — reconciled against the spec

#360's Slice B table lists four sinks. Checked against spec §6.1/§6.3 and against trunk, that
table has **three** errors, and the corrected list is **six functions**:

| # | Function | Return today | Ask |
|---|---|---|---|
| 1 | `grant_super_admin()` | `bool` | Check the `update_site_option()` result (or add `pre_grant_super_admin`) so a refused write stops `granted_super_admin` firing and stops the unconditional `true` return (`GB-GRANTSA-NOCHECK`) |
| 2 | `wp_delete_user()` | `bool` | `bool\|WP_Error` return contract, or a short-circuiting `pre_delete_user`. A returned `WP_Error` is **truthy**, so today a refusal reads to `WP_REST_Users_Controller` as success (`GB-DELUSER-OBSERVE`) |
| 3 | `wpmu_delete_user()` | `bool` | **The same change.** Network-admin deletion reaches it without passing through #2's seam (spec §6.3 row 5b) |
| 4 | `WP_User::set_role()` / `add_role()` | `void` | An early check **before** `caps`/`roles` are mutated. Vetoing the `{prefix}capabilities` meta write is not enough: the object is elevated in memory first, the write's return is ignored, and the request continues with the new role in effect (spec §6.3 row 6c) |
| 5 | `switch_theme()` | `void` | An in-function pre-check. **Confirmed** not reachable by option veto — see below |
| 6 | self-email, admin path | n/a | A short-circuit in `edit_user()` before `send_confirmation_on_profile_email()`. The REST self-update can already veto in its `permission_callback`; the admin path cannot |

`add_user_to_blog` is the one contested seam that is **free** — return a `WP_Error` from the
existing `can_add_user_to_blog` filter (`ms-functions.php:183`), which the function propagates.

### The three corrections, and why the count was right for the wrong reason

- **`wp_set_password()` does not belong on the list.** #360 lists it as a `void`-returning sink
  needing a `pre_*` filter. Spec §6.2 resolved otherwise (#304): `reset_password()` reaches
  `wp_set_password()` at **actor 0** on the lost-password path, where the emailed key is the
  proof, so gating it under default-on fail-closed refuses every legitimate reset site-wide —
  the design's single most damaging false positive. Enforcement for the authenticated changes
  (`core/change-own-password`, `core/change-user-password`) sits at **`wp_update_user()`**,
  which already returns `WP_Error` and needs **no patch** (`GB-RESET-ACTORLESS`).
  *Residual, and it is a scoping question for P3, not a settled fact:* `wp_set_password()`
  stays directly callable, so the bypass persists. Closing it needs both a core patch and the
  positive-signal carve-out of §6.2 (a request-scoped flag set after `check_password_reset_key()`
  succeeds — never a test for actorlessness, which fails in both directions). Do not decide
  that here; surface it when P3 has the mechanism to test it against.
- **`switch_theme()` does belong, and it is no longer "unconfirmed".** #360 hedges the count as
  "four, or five if `switch_theme()` turns out not to be reachable via `pre_update_option_*`".
  It is not reachable: vetoing `pre_update_option_template`/`_stylesheet` leaves `current_theme`,
  the theme roots, menu locations and sidebar-widget state written, and `switch_theme()` returns
  `void`, so nothing surfaces an error — a half-switched site reporting the new theme while
  running the old one (`GB-SWITCHTHEME-WRITES`). That is materially worse than a clean refusal,
  which hardens the ask rather than inflating it.
- **`wpmu_delete_user()` is missing from every list, including spec §6.1's.** §6.3 row 5b flags
  it, but §6.1 — the itemised "what this costs in core patches, stated plainly" — names only
  `wp_delete_user`. A core committer working from §6.1 would patch single-site deletion and
  leave network-admin deletion ungated on multisite. **Verified against trunk directly**
  (fetched 2026-07-27): `wpmu_delete_user()` is at `wp-admin/includes/ms.php:145`, returns
  `false` on three guard paths and `true` at `:213`, and within the function body carries only
  `do_action( 'wpmu_delete_user' )` (`:174`) and `do_action( 'deleted_user' )` (`:211`) — no
  `apply_filters` at all, so there is nothing to veto through. **Spec §6 row 5b also cites the
  wrong file**: it says `wp-includes/ms-functions.php`, which does not contain the function.
  Both need fixing in the spec, and the row needs a registry entry before it is cited in prose.

The net is that #360's "four or five" and the "five" I reported to Coordination were both
*numerically* defensible and **derived wrongly**: one function on the list should not be there,
one absent function should be, and the tally happened to survive. Recording that rather than
quietly shipping the right number, because a count that is right by cancellation will not stay
right the next time either side moves.

## The enabling fact for the acceptance test

`@wordpress/env` accepts a local core checkout — `"core": "../wordpress-develop/build"` (and
`/src`), documented in its README — so **the Playwright + wp-env harness already in this repo
can drive patched core**. That is what makes the acceptance test affordable rather than a new
test platform.

This claim is **not registered in `docs/upstream-sources.md`** and is therefore unverified
against drift: a row needs a prefix the checker admits (`GB-`, `FT-`), neither of which fits
`@wordpress/env`, and widening the set means editing `bin/verify-sources.sh` while #377 is open
on that file. **Re-check the README before relying on it**, and register it once the prefix set
can take the entry.

## Tests, in priority order

1. **The two-browser cloned-session test — the one that matters.**
   - Browser A authenticates, triggers a gated identity pivot, completes the challenge, and the
     effect lands.
   - Browser B holds a **byte-for-byte copy of A's logged-in cookie** — same user, same session
     token — triggers the same pivot, and is **challenged, not elevated**.
   - **Differential control**, the shape Slice A used: the identical call with the patch and
     without it, so the refusal is demonstrably the gate's doing and not an unrelated failure.
   - **What this actually asserts, stated precisely so P2 does not overclaim:** the proof lives
     in a *separate* cookie, HMAC'd over the session token; B copied the login cookie and not
     the proof cookie, so B has no proof to present. The honest limit is therefore that an
     attacker who exfiltrates **all** cookies after elevation does inherit it for the proof's
     lifetime. The claim is "a stolen login cookie does not inherit elevation", not "a stolen
     browser state does not". Write the assertion to that claim.
2. **Misclassification boundary (#357).** A plugin calling a gated sink from `admin_init` inside
   a cookie-authenticated request must be challenged, not exempted for being a PHP call.
3. **No auto-replay (#315).** A pending action does not execute until the confirmation page is
   submitted and the one-time proof is redeemed.
4. **Pass-through (#320).** App-Password REST, WP-CLI and cron behave exactly as unpatched core
   — neither challenged nor blocked.
5. **Core's own PHPUnit suite** — the regression bar; unchanged behaviour with gating disabled
   (AC8's observable form).

## Block criterion

Per decision 3: **if test 1 fails, the proposal is blocked**, not the PoC. Stop after P2 —
there is no value in breadth on a mechanism that does not hold, and the correct output in that
case is a written finding that the central claim did not survive contact with a compiler.

## Phasing

- **P1 — mechanism, no seams.** `wp_issue_action_proof()` / `wp_redeem_action_proof()` /
  `wp_end_reauth_proofs()` (§4.2) — per-session, HMAC-signed, cache-bypassed read,
  issue-then-confirm ordering, logged-in cookie paths (**not** the auth cookie's paths, and not
  `is_ssl()` — §4.2/#319). Plus `wp_check_action_gate()` + decision object (§4.3) and a minimal
  pure-data registry. Provable in PHPUnit alone; no user-visible change.
- **P2 — one identity pivot end to end.** The cheapest of the six above, plus the challenge
  interstitial and a confirmation page meeting §7.1's content contract, plus test 1. This is
  where the central claim gets tested; everything before is scaffolding, everything after is
  repetition.
- **P3 — the rest of the six, and cookie-REST.** Including the `wp_set_password()` residual
  above, and the REST adapter returning **403 + `sudo_reauth_required` + `challenge_url` +
  `digest`** and accepting the redeemed token (§5.2) — not a silent 200.
- **P4 — pass-through verification** (#320) and core's own suite green.

Every seam patched here inherits §6.2's **no-actor and self-heal carve-outs** — the theme
self-heal (`validate_current_theme()` → `switch_theme( WP_DEFAULT_THEME )` with no actor,
`GB-THEME-SELFHEAL`), multisite lifecycle (`get_active_blog_for_user()`, `wpmu_activate_signup()`),
install and recovery mode. A fail-closed veto without them does not protect the path, it breaks
it.

## Risks

- **Scope creep into all of §6.** Six functions. The remaining §6 rows are deliberately out;
  adding them before P2 passes is building on an untested primitive.
- **`update_core()` (#302) is not in this slice.** It was in the pre-split framing. It needs the
  guard placed early in `Core_Upgrader::upgrade()`, **not** at `update_core()` — `unpack_package()`
  has already run by then (§6.3 row 4f, and the #387 finding generalised). Noted so it is not
  re-derived a third time.
- **Honest one:** this is the first time the design meets a compiler. Some of what the spec
  asserts will turn out wrong — `wpmu_delete_user()`'s wrong file citation was found by fetching
  one file. That is the point of doing it, and should be expected rather than treated as failure.
