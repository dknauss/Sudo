# #360 — scope: rebuild the PoC as a tested `wordpress-develop` patch branch

## Status: unblocked

#360 states it is **blocked on #358** (the chokepoint veto audit), on the reasoning that
patching against seams that cannot refuse is how the last sketch acquired eight of its nine
defects. **#358 is now closed** — §6.3 audits every §6 row and #326 closed the one deferred
row — so the seam per sink is settled and this can start.

## The finding that sets the size

**The vertical slice needs zero core-contract patches.** Verified against trunk:

| Slice seam | Returns | Core patch needed? |
|---|---|---|
| `activate_plugin()` | `WP_Error` (14 sites in `wp-admin/includes/plugin.php`) | **No** |
| `wp_update_user()` (own password) | `WP_Error` (propagates `wp_insert_user`'s) | **No** |
| `update_core()` — the #302 seam | `WP_Error` (6 returns, `update-core.php:1097`) | **No** |

All four core-patch asks in §6.1 — `grant_super_admin`, `wp_delete_user`, `switch_theme`,
`edit_user()` for self-email — are for effects **outside** the slice. So the first branch is
**purely additive**: new API plus guards at seams that already refuse. No return-contract
changes, no signature changes, nothing that requires arguing a core-API change before the
mechanism has been demonstrated. That is the cheapest possible first branch and it de-risks
the expensive part by deferring it.

## The other enabling fact

`@wordpress/env` accepts a local core checkout — `"core": "../wordpress-develop/build"` (and
`/src`), documented in its README. So **the Playwright + wp-env harness already in this repo
can drive patched core**, which is what makes the acceptance test affordable rather than a
new test platform.

## Deliverables

1. **Patch branch** on a `wordpress-develop` fork that applies to trunk and passes core's own
   PHPUnit suite (the regression bar — the patch must not break core).
2. **New core code**, the Cut 1 mechanism only:
   - `wp_issue_action_proof()` / `wp_redeem_action_proof()` / `wp_end_reauth_proofs()` (§4.2),
     per-session, HMAC-signed, cache-bypassed read, issue-then-confirm ordering, logged-in
     cookie paths.
   - `wp_check_action_gate()` + decision object (§4.3).
   - Minimal registry: enough rows for the slice, pure data (§4.1, registry track).
   - Challenge interstitial + **canonical confirmation page meeting §7.1's content contract**.
3. **Guards** at the three slice seams above, plus the cookie-REST adapter returning
   **403 + `sudo_reauth_required` + `challenge_url` + `digest`** and accepting the redeemed
   token (§5.2) — not a silent 200.
4. **Tests** (below).
5. **Retire the sketch**: delete or `docs/archive/` `core-sudo-gate-poc-patches.md`, repoint
   `docs/README.md`.

## Tests, in priority order

1. **The two-browser cloned-session test — the one that matters.** Browser A completes the
   challenge; Browser B holds a *copy of the same auth cookie* and must not inherit the
   elevation. Playwright supports two contexts sharing a cookie, so this is expressible in
   the existing harness. **This is the test that falsifies or confirms the proposal's central
   claim, and nothing in the current artifact set exercises it.** If it fails, the design is
   wrong and that is the most valuable outcome available here.
2. **Misclassification boundary (#357).** A plugin calling `activate_plugin()` from
   `admin_init` inside a cookie-authenticated request must be challenged, not exempted.
3. **No auto-replay (#315).** A pending action does not execute until the confirmation page
   is submitted and the one-time proof is redeemed.
4. **Pass-through (#320).** App-Password REST, WP-CLI and cron behave exactly as unpatched
   core — neither challenged nor blocked.
5. **Core's own suite** — unchanged behaviour with gating disabled (AC8's observable form).

## Phasing

- **P1 — mechanism, no seams.** The proof API + gate helper + tests. Provable in PHPUnit
  alone; no user-visible change. Establishes the primitive before anything depends on it.
- **P2 — one effect end to end.** `activate_plugin()` + challenge + confirmation + the
  two-browser test. This is where the central claim gets tested; everything before it is
  scaffolding and everything after is repetition.
- **P3 — the identity pivot and REST.** Own-password change, cookie-REST challenge shape and
  token redemption.
- **P4 — `update_core()` (#302)** and the pass-through verification.

Stop after P2 if the cloned-session test fails. There is no value in breadth on a mechanism
that does not hold.

## Risks

- **Drift — the risk that killed the sketch.** The fix is not discipline, it is direction of
  truth: once this branch exists, **the branch is authoritative for mechanism and the spec
  cites it**, rather than the two being maintained in parallel. Any spec claim about how the
  mechanism behaves should be traceable to a test on the branch.
- **Fork maintenance.** Trunk moves; the branch needs periodic rebase or it rots into the
  same drift by another route.
- **Scope creep into all of §6.** The slice is four seams. The remaining ~16 rows are
  deliberately out; adding them before P2 passes is building on an untested primitive.
- **Honest one:** this is the first time the design meets a compiler. Some of what the spec
  asserts will turn out wrong. That is the point of doing it, but it should be expected
  rather than treated as failure.

## Decisions needed

1. **Where the fork lives** — a `dknauss/wordpress-develop` fork, or a branch in a scratch
   clone not published? Affects whether CI can run on it.
2. **Is the branch a permanent artifact or a one-shot validation?** Permanent means ongoing
   rebase cost and an anti-drift benefit; one-shot means answer the question, record the
   result, and let it go stale deliberately.
3. **Does a failing cloned-session test block the proposal?** My view: it should — that test
   is the proposal's central claim — but that is the maintainer's call to make before the
   result is known rather than after.
