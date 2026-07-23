# Reconciliation: `consequential-actions` v0.1.6 ↔ Core Spec

**Status:** Alignment review. Companion to [`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md).
**Reviewed:** `github.com/dknauss/consequential-actions` @ `v0.1.6` (commit `b71c15d`).
**Purpose:** Make the public demo argue the spec's design, and surface the two places where it currently argues *against* it.

The demo is explicitly a "wedge, not a product." It should not grow toward WP Sudo. But it *is* the runnable artifact linked from Trac #32, so its design choices read as "this is the shape being proposed to core." Two of them currently contradict the spec and comment #32. Those are the ones to fix; everything else is fine as an intentional MVP simplification.

---

## Side-by-side

| Dimension | Demo v0.1.6 | Core spec | Verdict |
|---|---|---|---|
| **Enforcement seam** | `user_profile_update_errors` (admin profile/new-user **form** hook) | `wp_update_user()` / `wp_insert_user()` **chokepoint** | ⚠️ **Contradicts the thesis** — see §1 |
| **Surface coverage** | Admin UI only (README says so) | Admin UI + cookie-REST + programmatic via one chokepoint | Expected MVP gap; §1 fix narrows it |
| **Recent-auth store** | Per-user **transient** flag, not session-bound (README admits it) | `WP_Session_Tokens` record, revoked on logout | Expected MVP gap; acceptable for a demo |
| **Force-logout framing** | `CA_TERMINATE_SESSION` presented as "the **stronger** answer" | Window is the right primitive; forced re-login "heavier than the problem needs" | ⚠️ **Contradicts #32** — see §2 |
| **Registry** | `actions()` — 6 IDs, **label-only** | `wp_register_action()` — id, caps, category, consequence_class, scope, annotations | Align metadata shape; §3 |
| **Catalog** | 6 account actions | + `delete-user`, `activate/install/delete-plugin`, `update-connector-credentials` | Demo scope-limits to account-takeover on purpose; fine |
| **Promotion detection** | `role_change_escalates()` — by capability (fixed in `b71c15d`) | `wp_role_change_escalates()` — by capability delta | ✅ **Already aligned** |
| **Credential checked** | Always the actor's own password | Always the actor's own | ✅ Aligned (the #20140 correctness point) |
| **Stash/replay** | None (password submits *with* the form) | Port `class-request-stash.php` | Fine for form-hook MVP; needed once seam moves (§1) |
| **2FA** | None | `wp_reauth_second_factor` hook | Correctly deferred |

---

## 1. The enforcement seam contradicts the demo's own thesis

The demo's headline argument (README, and Trac #32) is *"protect the effect, not one field on one form."* But it enforces at `user_profile_update_errors` — which **is** the form. That hook fires only for `user-edit.php` / `profile.php` / `user-new.php` submissions. A REST `POST /wp/v2/users/<id>` password change, a WP-CLI `user update`, or any `wp_update_user()` call sails straight past it. The README is honest about this ("No REST / Application Password / WP-CLI"), but the effect is that **the artifact demonstrates the weaker design the spec argues against.**

For an MVP that's a defensible simplification — *except* that the whole point of this MVP is to argue the chokepoint thesis. A reviewer who reads the code sees a form-field gate.

**Recommended v0.2 change (small):** add a second guard at the mutation layer so the demo shows the thesis, without becoming WP Sudo:

```php
// In addition to the user_profile_update_errors hook, gate the chokepoint:
add_filter( 'wp_pre_insert_user_data', __NAMESPACE__ . '\\gate_chokepoint', 10, 4 );

// wp_pre_insert_user_data passes ( $data, $update, $user_id, $userdata ) since WP 4.9;
// $user_id is null on insert. Returning WP_Error is NOT supported here, so instead
// short-circuit with wp_die()/redirect for the demo, or (cleaner) hook the REST
// permission callback for the users controller to show REST coverage:
add_filter( 'rest_pre_dispatch', __NAMESPACE__ . '\\gate_rest_users', 10, 3 );
```

Even just adding the **REST path** (`rest_pre_dispatch` matching `/wp/v2/users` write methods, re-using `triggered_actions()`) would let the Playground demo show the *same* takeover blocked over REST as well as the form — which is exactly the "one guard, every surface" claim. That's the single highest-value demo upgrade.

> Note the core spec has an advantage the plugin lacks: core can return `WP_Error` from *inside* `wp_update_user()`, which the demo can't (it can only hook around it). So the demo will always be an approximation of the seam. Adding the REST hook is the closest a plugin can get to demonstrating chokepoint coverage, and it's enough to make the argument.

---

## 2. The force-logout framing contradicts comment #32

The demo README calls `CA_TERMINATE_SESSION` (force-logout) **"the stronger answer to the threat that motivates this project"** and lists four advantages. But Trac #32 explicitly **walks that back**:

> "I want to walk back something I floated earlier: fully terminating the session on sensitive changes. That amounts to forced re-login, and it's heavier than the problem needs. The lighter, well-understood primitive is step-up reauthentication into a short elevated window."

So the public demo and the public ticket comment now take **opposite editorial stances** on the same mechanism. A committer reading both will notice. This isn't a code bug — offering both modes is fine — it's a **messaging inconsistency** that undercuts the #32 argument.

**Recommended fix (docs only):** reframe force-logout in the README from "the stronger answer" to "a stricter opt-in for stolen-cookie-sensitive sites," and state plainly that the **window is the recommended primitive** and the one being proposed to core. Keep the mode; change the framing so it matches #32 and spec §3. One paragraph.

The technical point the README makes in force-logout's favor — that a fresh login inherits 2FA/passkeys/lockouts and produces an audited `wp_login` — is real and worth keeping. But the spec's answer is better: the *window* also runs its challenge through a 2FA hook and rate-limiting, **and** binds to the session token so logout/"log out everywhere" revoke it — capturing most of force-logout's assurance without the lost-work cost. Say that.

---

## 3. Registry metadata shape

The demo's `actions()` returns `[ 'id' => [ 'label' => ... ] ]`. The spec's registry carries `capabilities`, `category`, `consequence_class`, `scope`, and `annotations`. Aligning the demo's array shape to the spec's (even if most fields are unused in the MVP) makes the demo a faithful preview of the Layer 1 API and lets the same catalog literal be lifted toward a core proposal. Cheap, and it makes the "this is what registration looks like" story consistent across demo, spec, and any Make/Core post.

---

## Punch list for a v0.2 demo (in priority order)

1. **Add REST coverage** (`rest_pre_dispatch` on `/wp/v2/users` writes, re-using `triggered_actions()`), and update the Playground demo to block the takeover over REST too. Highest value — turns the demo from a form gate into a chokepoint demonstration. *(§1)*
2. **Reframe force-logout** in README/readme.txt from "stronger answer" to "stricter opt-in," and name the window as the recommended/proposed primitive. Aligns with Trac #32. *(§2)*
3. **Align the registry array shape** to the spec's metadata fields. *(§3)*
4. Optional: add `core/delete-user` to show the catalog isn't password-only, still within the account-takeover story.

Items 1–2 are the ones that matter: right now the artifact linked from the ticket argues a form-field gate and calls force-logout the stronger answer — the two positions #32 was written to move past. Fixing the framing (2) is a 20-minute docs edit; adding REST (1) is the demo change that actually proves the thesis.

## What to deliberately NOT change

- Don't add stash/replay, 2FA, multisite, or non-interactive surface policy — those are the "heavy framework pieces" the MVP's value proposition says core shouldn't have to standardize at once, and adding them makes it a worse wedge.
- Don't move the demo toward WP Sudo. Its job is to be readable in five minutes.
