# Proposal: consequential-effect authorization in WordPress Core

**Status:** research proposal with a working demonstrator. Not a patch
submitted for trunk, and not ready to be one — §8 says why.
**Against:** WordPress 7.0.2. **Date:** 2026-08-03.

Supporting documents: `FINDING.md` (why the plugin approach fails),
`CENSUS.md` (how many seams, counted), `BOUNDARY.md` (claim-by-claim
verification), `tests/effect-seam.sh` (36 assertions),
`core-patch/effect-authorization.diff` (the patch).

---

## 1. The problem

**A stolen WordPress admin session is total site compromise.** An attacker
holding the cookies — and *not* the password — can install a plugin and
execute arbitrary PHP, change another user's email and password, or set
`default_role` to `administrator`. WordPress asks for nothing at any point,
because a valid session cookie *is* the authorization.

There is no reauthentication anywhere in WordPress for any action. GitHub,
AWS, and Google all require re-proof before consequential operations.
Session theft is not exotic: cross-site scripting, unlocked machines,
malicious browser extensions, backups containing cookies.

## 2. What is proposed

One Core-owned function, called immediately before an irreversible change,
which may refuse:

```php
$decision = wp_authorize_consequential_effect( $effect );
if ( is_wp_error( $decision ) ) {
    return $decision;   // caller MUST NOT commit
}
```

Core supplies a **descriptor** built from what it is actually about to do —
never from what the request claimed:

```php
array(
    'version' => 1,
    'id'      => 'core.code.package_commit',
    'site_id' => 1,
    'target'  => array( 'type' => 'plugin', 'action' => 'install', … ),
    'payload' => array( 'sha256' => '…' ),   // real bytes, where bytes matter
    'actor'   => array( 'class' => 'interactive', 'user_id' => 1, 'session' => '…' ),
)
```

Core also supplies a **canonical serialization** and a digest over it, an
**actor taxonomy** (`interactive`, `cron`, `cli`, `remote`, `anonymous`),
and an **add-only** list of guarded options.

Core decides nothing else. Challenges, approvals, storage, UI, and policy
live in a plugin. **Default posture is allow**: with no policy attached,
patched Core behaves exactly as unpatched Core.

## 3. Why not the alternatives — each was tried and failed

**Request matching.** The predecessor project (WP Sudo) enumerated 36
request-shape rules and still leaked **seven verified bypasses across six
axes**: route-regex case, superglobal choice, HTTP method set, evaluation
order, action derivation, and an uncovered surface. The cause is
structural — two independently maintained predicates over the same request
drift apart, and nothing can detect the drift. Core has no obligation to
hold its dispatch predicate stable and the plugin has no way to observe it.

**Capability names.** The direct successor denied dangerous capabilities and
unlocked them per-action. It failed for a different reason: *capability
names are not stable referents for effects.* Of three effects examined, all
three were mismatched — one guarded an operation that performed nothing,
and two performed something other than what the capability authorised on
multisite (`create_users` bypassing the confirmable-signup flow;
`delete_users` performing the site removal Core governs with
`remove_users`). Each was discoverable only by reading Core source in both
site contexts, and none was detectable by the approval mechanism, which was
correct throughout.

**The Abilities API.** `wp_before_execute_ability` is a `do_action()`
(verified at `class-wp-ability.php:645`). An observer cannot refuse. It is
a useful vocabulary and a plausible adapter; it is not a boundary.

**The common lesson:** authorization must bind to the *effect*, at the point
the effect is committed, using values the committing code itself computed.

## 4. Evidence

Four seams built and tested against live single-site and multisite
WordPress 7.0.2 — **36 assertions, all passing** (`tests/effect-seam.sh`).

| Effect | Seam | What it demonstrates |
|---|---|---|
| `core.code.package_commit` | `WP_Upgrader::install_package()` | Byte digest over the *unpacked* tree; one insertion covers plugin, theme, and language packages through 9 subclass call sites |
| `core.identity.email_change` | `wp_insert_user()` update branch | `from`/`to` binding; covers self-service *and* admin-edits-another, closing an asymmetry in shipping WordPress |
| `core.identity.password_set` | same seam | An effect whose payload is a secret and cannot be digested |
| `core.config.option_write` | `update_option()` | A `bool`-only return contract; an add-only guarded list |

**Mutation test:** removing only the veto, changing nothing else, makes each
blocked attack succeed. The vetoes are load-bearing, not decorative.

**Against the seven bypasses that killed the predecessor: six of seven are
covered, and the mechanism that produced all seven is absent by
construction.** A seam parses no request, so route casing, superglobal
choice, method set, and evaluation order cannot produce a bypass. The
remaining entry is a partial (the 2FA lifecycle bridge).

**Cost: 120 added lines across four existing Core files, zero lines
removed, plus one new file.** Purely additive.

## 5. Scope

**Core owns:** the descriptor contract, canonical serialization, the actor
taxonomy, the guarded-option list, and one vetoable call per seam.

**A plugin owns:** whether to challenge, how to challenge, approval storage
and lifetime, single-use semantics, confirmation UI, audit.

This split is the whole point. `FINDING.md` establishes that a plugin cannot
create a trustworthy effect boundary from outside Core, because from outside
it can only infer effects from names and request shapes — which is exactly
what failed twice.

## 6. Actor policy

Automation must keep working. **A default that disables automatic security
updates in the name of security is not conservative, it is broken** — an
earlier version of this demonstrator refused all non-interactive actors and
was wrong to.

| Actor | Default | Why |
|---|---|---|
| `cron` | allow | Automatic updates must not break |
| `cli` | allow | Shell access can edit `mu-plugins` and remove the seam; gating buys nothing |
| `interactive` | **require approval** | The threat this exists for |
| `remote` (Application Password) | refuse | No session; cannot satisfy a control requiring a present human |
| `anonymous` | refuse | No legitimate unauthenticated path commits these effects |

Package provenance verification would *harden* the `cron` branch. It is not
a precondition for it.

## 7. Seams still needed

`CENSUS.md` counts roughly fourteen, enumerated forward from effect classes
and cross-checked against the seven bypasses — a cross-check that
immediately found two the enumeration had missed. The number should be
treated as an order of magnitude, never as complete.

Notable: `wp_set_password()` is **not** a viable seam. It is pluggable, and
it is called on rehash-at-login (`user.php:225`, `308`), so a veto there
would break ordinary authentication.

## 8. What this does not solve

- **A rogue administrator who knows the password.** Reauthentication proves
  the same person is present; a rogue admin *is* that person. Insider threat
  needs multi-party authorization or out-of-band victim consent — different
  controls, which WordPress does not have primitives for.
- **Active same-origin script.** Page-level script can keylog the password
  when typed and can control what the operator believes they are approving.
  WebAuthn bounds this to "user present and expecting a prompt" but does not
  close it: transaction-confirmation (`txAuthSimple`) is effectively
  unimplemented, so an authenticator shows "touch your key", not what is
  being signed.
- **Silent refusal at `update_option()`.** It returns `bool`, so the
  `WP_Error` is discarded and the operator sees nothing. That is a security
  problem, not merely usability — an admin who cannot see the control fire
  cannot notice it firing when they did nothing. Compensated in the
  demonstrator via `add_settings_error()`; **the Core contract should be
  fixed instead.**
- **Plugin-defined dangerous options.** A security plugin's "disable
  two-factor" switch is as dangerous as `default_role`, and Core cannot know
  it exists. The add-only filter lets plugins extend the list; nothing makes
  them.
- **Direct `$wpdb` and filesystem writes.** Partly the conceded boundary,
  but only partly: a plugin calling `update_user_meta()` on the capabilities
  key never touches `WP_User`, and that is not host compromise.
- **Ten of fourteen seams.** In its current state this closes four specific
  effects. It is a demonstration of a mechanism, not a defense anyone can
  deploy.

## 9. Honest maturity

This is a **research demonstrator**, not a submittable patch. It is applied
by a bespoke script to a running container, it has no coverage in
WordPress's own test suite, it has never run on a real site, and it has no
browser-level testing of any kind. `core-patch/effect-authorization.diff`
makes the change reviewable; it does not make it ready.

The claim being made is narrow and, within this repository, evidenced:
**effect-boundary authorization works where request-matching and
capability-name binding provably failed, at a cost of ~120 additive lines
for the four effects built so far.**

Everything beyond that is proposal.
