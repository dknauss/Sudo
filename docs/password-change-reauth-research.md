# Research Brief: Reauthentication for Password / Account Changes in WordPress Core

**Status:** internal research brief · **Date:** 2026-07-09
**Author context:** WP Sudo maintainer (dpknauss is the reporter of [#20140 comment:31](https://core.trac.wordpress.org/ticket/20140#comment:31))
**Companion doc:** [`core-action-gate-proposal.md`](core-action-gate-proposal.md)

All Trac quotes below were read directly from live Trac (via browser, 2026-07-09),
not from training data, per this repo's verification rules. Trac serves a JS
bot-challenge that blocks `curl`/WebFetch; a real browser is required to read it.

Direct Trac comment links cited in this brief:

- `#4444`: [ticket](https://core.trac.wordpress.org/ticket/4444), [comment:1](https://core.trac.wordpress.org/ticket/4444#comment:1)
- `#20140`: [ticket](https://core.trac.wordpress.org/ticket/20140), [comment:7](https://core.trac.wordpress.org/ticket/20140#comment:7), [comment:8](https://core.trac.wordpress.org/ticket/20140#comment:8), [comment:9](https://core.trac.wordpress.org/ticket/20140#comment:9), [comment:10](https://core.trac.wordpress.org/ticket/20140#comment:10), [comment:14](https://core.trac.wordpress.org/ticket/20140#comment:14), [comment:20](https://core.trac.wordpress.org/ticket/20140#comment:20), [comment:21](https://core.trac.wordpress.org/ticket/20140#comment:21), [comment:22](https://core.trac.wordpress.org/ticket/20140#comment:22), [comment:24](https://core.trac.wordpress.org/ticket/20140#comment:24), [comment:31](https://core.trac.wordpress.org/ticket/20140#comment:31)
- `#37593`: [ticket](https://core.trac.wordpress.org/ticket/37593), [comment:15](https://core.trac.wordpress.org/ticket/37593#comment:15), [comment:31](https://core.trac.wordpress.org/ticket/37593#comment:31), [comment:34](https://core.trac.wordpress.org/ticket/37593#comment:34), [comment:35](https://core.trac.wordpress.org/ticket/37593#comment:35), [comment:36](https://core.trac.wordpress.org/ticket/37593#comment:36)
- `#39174`: [ticket](https://core.trac.wordpress.org/ticket/39174), [comment:2](https://core.trac.wordpress.org/ticket/39174#comment:2)
- Dependency note: [`#37616`](https://core.trac.wordpress.org/ticket/37616)

---

## 1. The core issue

WordPress lets any holder of a *live authenticated session* change the account's
own password and email, and (with `edit_users`) other users' passwords, emails,
and roles — with **no fresh proof that the person at the keyboard is the account
owner**. The login password is checked once, at login; everything after rides the
auth cookie.

The failure mode this exposes is **not weak authorization** — the user genuinely
holds the capability — it is **stale authentication**: a walk-up to an unlocked
session, a "remember me" session on a shared machine, or (the modern dominant
case) a **stolen session cookie**. In each, the attacker inherits full session
authority and can perform account takeover (change password/email → lock out the
owner) or privilege persistence (create an admin, escalate a role) with no
additional challenge.

This is precisely the class of threat that a **sudo / step-up reauthentication**
primitive is designed for: *confirm it is really you, right now, before this
consequential action* — orthogonal to *who is allowed to do it*.

---

## 2. Trac ticket lineage (verified)

### #4444 — "Ask for current password when changing password" (2007) — closed `invalid`

- Reporter (Viper007Bond) framed the exact scenario: a "remember me" session left
  on a public PC.
- **markjaquith #1** (the recurring objection, first stated here):
  > "I don't think this buys us any additional security. Someone with such access
  > could install a backdoor, create a new user, or do any number of other things
  > to engineer future access."
- Reporter conceded on "create a new user" and closed it himself. **This is the
  origin of the "admin can already do anything, so why bother" objection** that
  reappears in every later ticket.

### #20140 — "Ask old password to change user password" (2012 → open, `Future Release`)

Component **Security**, Severity **major**, keywords `has-patch 2nd-opinion`.
33 comments; reopened 2018. The substantive thread:

- **#7 iandunn** — rebutted the #4444 objection: the "admin can do anything"
  argument *assumes the actor is an Admin*. "They could be an Editor or other
  role." Step-up auth meaningfully protects **sub-administrator** accounts.
- **#8 azaozz / #9–10 iandunn** — key design fork: when an **admin changes another
  user's** password, requiring the *target's* current password is unacceptable.
  The patch was refreshed so admins don't need the target's password. → The right
  secret is the **actor's** recent auth, not the target's old password.
- **#14 nacin** — accepted the sub-admin value, predicted the bypass report:
  > "an administrator can simply create a new user, then log in as that user to
  > change the first administrator's password … This is designed for better user
  > security for sub-administrator roles. Which is good, because they're commonly
  > being used in attacks."
- **#20 stephenharris** — the most complete framing. Enumerated the admin bypass
  avenues (create a new admin; change email + use "forgot password"; plugin/theme
  editor) and proposed requiring the **actor's password** for *create user /
  change role / change password / change email*. Explicitly cited Eric Mann's
  "sudo in WordPress" essay. Asked whether a **feature plugin** was the way in.
- **#21 johnbillion** — "A feature plugin would be an excellent way of beginning
  to address this."
- Prior-art plugins surfaced in-thread: **humanmade/hm-require-password** (#22),
  **stephenharris/password-confirm-action** (#24, modal-after-submit with a
  non-JS fallback; gates create-user / edit role / email / password).
- **#31 dpknauss (this project's maintainer)** — reframed for the modern threat
  model: a useful mitigation for a **hijacked session**, but to actually close the
  hole it must cover **password + email changes + new-user creation**; ideally the
  privileged action should **terminate the session and force reauthentication**;
  and because an admin with upload/install/activate/deactivate/delete can bypass
  everything, **those actions should also require reauthentication** to defend a
  hijacked *admin* session. Anchored to the current reality:
  > "Since 2021, stolen session cookies from compromised user devices have become
  > the most common effective attack (exceeding plugin vulnerabilities) on
  > WordPress sites."

### #37593 — "Replace 'Super Admin' with 'Network Administrator'" (2016, `Future Release`)

Terminology, not reauth — but directly relevant because it is where core
contributors first sketched **sudo mode as a role tier**. **jeremyfelt #15**
(endorsed by **johnjamesjacoby #34**) proposed the future hierarchy:

> Site administrator · Network administrator · Global administrator ·
> **Super Admin (sudo mode, emergency access controlled via global `$super_admins`)**

Stalled on backward-compat / documentation-churn worries (jorbin #31, johnbillion
#35–36: "The Super Admin terminology really is everywhere").

### #39174 — "Introduce network roles" (2016, `Future Release`)

Component **Role/Capability**. Establishes the canonical model:

> Site Administrator < Network Administrator < Global Administrator <
> **Super Admin** (special access via `$super_admins` global, has all capabilities
> automatically)

**johnjamesjacoby #2** ranks access and treats the top tier as "sudo mode …
unfettered access." Depends on **#37616** (replace `is_super_admin()` with real
capability checks) as its foundation. Stalled `needs-patch needs-unit-tests`.

**Takeaway from the lineage:** #4444/#20140 want a *reauthentication primitive*;
#37593/#39174 want a *role/capability model* — and both independently reach for
the word "sudo." The account-change tickets keep dying on the "admin can already
do anything" objection, which is only answerable if reauth is applied to the
*consequential effects* (install/activate/create-user/promote), not just the
password field — exactly the scope #20140:31 argues for.

---

## 3. Why it keeps stalling (recurring objections + rebuttals)

| Objection (Trac) | Rebuttal / current status |
|---|---|
| "Admin can already do anything" (#4444:1, #14, #20) | Protects **sub-admin** roles now (#7); protects **hijacked admin** sessions *if* reauth also gates install/activate/create-user (#20140:31). |
| Requiring the target's old password blocks legit admin edits (#8) | Resolved: gate on the **actor's** recent auth, not the target's secret (#9–10). |
| Terminology/BC churn (#37593:31, #35) | Real, but scoped to the naming ticket; does not block a reauth primitive. |
| "Feature plugin first" (#21) | Done repeatedly (hm-require-password, password-confirm-action, and now WP Sudo). Core adoption never followed. |
| Feature creep — password *and* email *and* user-create *and* plugin ops | This is the crux: partial coverage is theater; the honest scope is "consequential actions," which is large. |

The pattern: every attempt narrows to "just the password field," gets correctly
told that's insufficient, expands to "all consequential actions," and then stalls
on scope + missing primitives (session model, challenge UX, replay, non-interactive
surface policy). **The missing piece is not consensus that reauth is good — it is a
shared vocabulary of consequential actions to attach it to.**

---

## 4. Industry-standard patterns

> General-knowledge summary. Specific durations/scopes should be verified against
> each vendor's current docs before external citation.

- **"sudo mode" (GitHub)** — the canonical web analogue of Unix `sudo`. Sensitive
  actions (changing email, 2FA, SSH keys, deleting repos) re-prompt for password
  or passkey and open a **time-boxed elevated window** (~a few hours) rather than a
  permanent grant. This is essentially WP Sudo's model.
- **Step-up / reauthentication (Google, AWS, Microsoft, Okta)** — sensitive
  operations trigger a fresh auth challenge independent of session age; often
  bound to risk signals. AWS root/IAM sensitive changes and Google "confirm it's
  you" are the familiar examples.
- **Framework primitives** — **django-sudo** (originated at Sentry): a decorator +
  short-lived cookie that forces reauth for selected views; Rails' `sensitive`
  session patterns; Django's `sensitive_post_parameters` (adjacent — scrubbing, not
  reauth). These show the primitive is small and decorator-shaped.
- **WordPress ecosystem** — WP Sudo; hm-require-password; password-confirm-action;
  Two Factor (auth strength, not reauth-per-action); host-level controls. No core
  primitive exists.
- **Unix `sudo` itself** — the mental model everyone borrows: elevate *briefly*,
  for *specific* actions, with a *timestamp/cache*, re-prompting when it expires.

**Convergent design across all of them:** (1) reauth is **per-consequential-action**,
not per-session; (2) it yields a **short-lived elevated state**, not a permanent
grant; (3) the trigger is the **effect/route**, not a single form field; (4) the
secret is the **actor's** credential, decoupled from the target object.

WordPress core currently has **none** of these primitives.

---

## 5. What WP Sudo has learned (grounded in the implementation)

WP Sudo is a working existence proof of the #20140 direction. Concrete lessons:

- **Gate the effect, across every surface — not the form field.** WP Sudo's Gate
  matches admin UI, AJAX, REST (cookie-auth), and applies policy to
  CLI/Cron/XML-RPC/App-Passwords, plus **effect-level backstops** that hard-block
  destructive effects reached through non-enumerated handlers. This directly
  answers the "admin can do it another way" objection that killed #4444/#20140:
  covering only the password field is bypassable; covering the *effect* is not.
- **Bind reauth to the actor, per-user.** `Sudo_Session::verify_token()` requires
  `get_current_user_id() === $user_id` and a per-user token cookie. Holding a valid
  session for user A never grants sudo for user B — the User Switching demo proves
  an assumed session still cannot perform gated actions. This is the
  operationalization of the #8–10 "actor, not target" fork.
- **Short-lived, cryptographic elevated state.** Token in user-meta + httponly
  cookie, 1–15 min duration, rate-limited (5 attempts → 5-min lockout), with a
  grace window. This is "sudo mode" as #37593:15 imagined it — *temporary state*,
  not a role.
- **Password change should expire elevation.** WP Sudo expires the sudo session on
  `after_password_reset` / `profile_update` — the concrete version of #20140:31's
  "the privileged action should terminate the session."
- **The hard part is not the gate — it's the surrounding contract.** Session model,
  challenge UX, request stash/replay, and non-interactive surface policy are each
  substantial. This is why core can't "just add a password prompt," and why the
  companion proposal separates **naming consequential actions** (cheap, valuable
  alone) from **gating them** (the heavy framework).
- **Editors are the real near-term win.** Removing `unfiltered_html` from Editors
  and gating sub-admin actions is where reauth adds security *today*, matching
  #7/#14's point that sub-administrator roles are the common attack vector.

---

## 6. Recommendation

**Do not pursue "ask for the old password on the profile screen" (the literal
#20140 patch).** Twenty years of the thread show it is simultaneously too narrow
to be secure and too entangled to land. Instead:

**Primary recommendation — split the problem, lead with vocabulary.**
Pursue the two-layer path in [`core-action-gate-proposal.md`](core-action-gate-proposal.md):

1. **Layer 1 — a core "consequential actions" registry** (an Actions API): give
   core + plugins a stable way to *name and observe* actions like
   `core/change-own-password`, `core/change-user-password`, `core/change-own-email`,
   `core/create-user`, `core/promote-user`, `core/activate-plugin`, etc. Valuable
   even if core never ships a gate (audit, UI affordances, policy tooling). This is
   the wedge that dissolves the perennial scope fight, because the scope becomes a
   *catalog* rather than an all-or-nothing feature.
2. **Layer 2 — an optional proof-of-intent gate** that consumes the registry and,
   for selected actions, asks for fresh actor authentication (password / passkey /
   2FA-aware). WP Sudo is the reference implementation; core need not standardize
   challenge/replay/policy in the same release.

On terminology, follow #37593/#39174: **network administrator** for network
authority, **super admin** for the technical `$super_admins` concept, and
**"sudo mode" for the temporary reauthenticated state** — not a new role.

**Framing for the actual account-change tickets:** the security boundary is
**recent authentication by the actor**, never the target's old password. That one
reframing retires the #8 objection and unifies "change own password," "admin
changes another user's password," and "promote to admin" under shared action IDs.

## 7. Paths for further analysis (open questions)

1. **Minimum credible core catalog.** Which 8–12 action IDs are unambiguous enough
   to survive a Trac review? (Draft table exists in the companion doc.) Validate
   each maps to exactly one privileged function boundary.
2. **Non-interactive surfaces.** Password/email/user changes also arrive via REST +
   Application Passwords and WP-CLI. A reauth gate must have a *policy* answer
   there (deny / limited / unrestricted), or it's bypassable — WP Sudo already
   models this; does a core primitive adopt it or punt?
3. **Multisite session semantics.** #39174's hierarchy raises "what is a
   network-wide sudo session?" — untangle before promising multisite coverage.
4. **Passkeys / WebAuthn as the reauth factor.** The industry has moved past
   password re-entry; a core primitive should be factor-agnostic from day one.
5. **Session-termination-on-change UX.** #20140:31's "terminate session + reauth"
   is strong but disruptive; model the UX cost vs. the takeover it prevents.
6. **Upstream approach.** Per contribution etiquette: is the durable move a tight
   Trac comment linking the #4444→#20140→#37593→#39174 lineage and the two-layer
   framing, or a narrowly-scoped Actions-API patch? Avoid a large AI-authored doc
   dump on the tickets; keep it in the maintainer's voice.
