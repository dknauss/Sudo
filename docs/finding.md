# Finding: route enumeration and post-submission interception cannot provide ecosystem-wide action-gated reauthentication

**Status:** draft finding, not yet a core proposal
**Date:** 2026-07-29 (rev. 2)
**Source project:** WP Sudo (research prototype — see `PROJECT-STATUS.md`)

---

## 1. The claim

**A WordPress plugin cannot provide ecosystem-wide action-gated reauthentication
through route enumeration and post-submission interception.**

Read the scope precisely. This claim is about a *mechanism pair*, not about
plugins in general.

It does **not** claim:

- that a plugin cannot reliably gate effects **it owns**. Within its own code a
  plugin controls the call site and can demand proof at it;
- that effect-level gating never works. It demonstrably does, for effects whose
  hooks are unambiguous (§2.2);
- that no plugin architecture can improve on this one. §4 sketches what a
  different one would need, and §6 states what remains untested.

What it does claim is that *completeness across the ecosystem* is unreachable by
enumerating routes, and that intervening after a request is submitted forces a
choice among three unsatisfactory options (§2.3).

## 2. What the implementation established

### 2.1 Enumerated routes escape, because the matching predicate is not congruent with core's dispatch predicate

This is the strongest result and it is empirical, not argued.

The prototype gates 36 built-in rules (28 single-site, 8 multisite). Each is a
request-shape predicate — `pagenow`, `action`, HTTP method, REST route pattern,
POST field presence. An adversarial audit found **seven high-severity bypasses
across six independent axes**, every one of which is a *complete* defeat of the
gate for the operations it covers. All seven were independently verified against
WordPress 7.0 source; see `audit-verification-record.md` for the read-in-context
evidence on each.

| Axis | Core dispatches on | Plugin matched on |
|---|---|---|
| REST route case | `preg_match( '@^…$@i' )` (`class-wp-rest-server.php:1166`) | patterns with no `i` flag |
| File editor write | `'POST' === $_SERVER['REQUEST_METHOD']` (`plugin-editor.php:95`) | `action=update` required |
| `option_page` source | `$_REQUEST` (`options.php:27`) | `$_POST`, in the self-protection rules |
| Bulk promote | `isset( $_REQUEST['changeit'] )` (`class-wp-users-list-table.php`) | an `action`-name allowlist |
| REST method set | `EDITABLE = 'POST, PUT, PATCH'` | `array( 'PUT', 'PATCH' )` |
| Edited user | `$_REQUEST['user_id']` (`user-edit.php:16`) | `$_POST['user_id']` |

**The distinction that matters:** this is not incompleteness. Incompleteness
would mean *unenumerated* paths escape — the limit anyone expects from a rule
list. What these show is that **enumerated** paths escape, because two
independently maintained predicates over the same request drift apart, and
nothing in the architecture can detect the drift.

Congruence is not a property either side can enforce. Core has no obligation to
hold its dispatch predicate stable, and the plugin has no mechanism to observe
it. Each of the six was found by reading core and the matcher side by side —
which is precisely what a test suite cannot do, because every test asserts the
plugin against its own model of a request and is therefore wrong in exactly the
same way as the rule it exercises (§3 of the post-mortem develops this).

The remaining, weaker limit still holds and is documented in
`docs/security-model.md` §"What It Does Not Protect Against": third-party AJAX
and REST handlers, direct `$wpdb` writes to usermeta, capabilities conferred
through `user_has_cap` / `map_meta_cap`, and PHP-path execution via
`WP_Ability::execute()` are outside interception entirely. The
`wp_sudo_gated_actions` filter can cover a known plugin path but requires
explicit per-plugin integration — coverage achievable per-target, unachievable
ecosystem-wide.

### 2.2 Effect-level gating works where the hook is unambiguous, and only there

This section previously overgeneralized. Correction:

Version 4.1.0's effect-level backstop **does** gate a set of destructive core
effects — `delete_user`, `delete_plugin`, `delete_theme`, `activate_plugin`,
`upgrader_pre_install`, `export_wp` — even when reached through a non-enumerated
admin handler. The opt-in escalation guard likewise hooks the
`{prefix}capabilities` meta write and `grant_super_admin` directly, so it applies
on admin, REST, AJAX, and unauthenticated requests alike. Effect-bound gating is
therefore a working mechanism, not a failed one.

What fails is *generalizing* it. `docs/security-model.md` records why option
writes (`pre_update_option_*`) and role/user-creation hooks are excluded:
**WordPress core and legitimate plugins fire those same hooks incidentally
during ordinary admin loads**, so gating there would break non-gated workflows.

So the dividing line is not "effect layer vs route layer." It is whether a given
hook carries an unambiguous signal of actor intent. Some core hooks do; the ones
covering the largest attack surface — option writes and capability mutation —
do not. A plugin can gate the former set and cannot gate the latter without
false positives.

**The audit quantifies how much this costs.** Every one of the seven bypasses in
§2.1 lands inside the excluded set — option writes, or user create/promote. So
the effects that are gateable at the effect layer are **not the effects that
matter**, and the backstop cannot serve as the safety net for a route matcher
that has drifted. Two mechanisms, and the same class of operation falls through
both.

That is the transferable result, and it is exactly what §4.1 asks core for: the
hooks that need an intent signal are identified by which ones core fires
incidentally, and that set is where the attack surface concentrates.

### 2.3 Post-submission interception creates a three-way trap

Reauthentication as implemented intervenes **after** submission, when the user's
input is already in flight. From there only three options exist (framing due to
the Codex architecture review, 2026-07-29):

1. **Replay the request automatically.** Smooth, and unsafe — the server
   re-issues an action on the user's behalf from stored parameters. Version
   4.9.0 removed automatic stash/replay after it proved a confused deputy.
2. **Refuse to replay.** Secure, and lossy — typed input and navigation context
   are destroyed. This is current behaviour and the source of the usability
   defects tracked as #533–#537.
3. **Reconstruct each screen individually** so the refusal lands somewhere
   useful. Not generalizable: it requires per-screen knowledge of an admin UI
   the plugin does not own.

The prototype has now occupied all three positions. `Challenge::HANDLER_LANDINGS`
is option 3 attempted incrementally, at a single entry.

**#533–#537 are one missing contract surfacing repeatedly, not independent
usability bugs.** Pre-4.9 smoothness was automatic replay concealing it.

### 2.4 Session binding defends partial exfiltration only

Any successor architecture must preserve this boundary rather than silently
widen it, so it is stated here as part of the finding.

The sudo proof is a `wp_sudo_token` cookie whose hash is stored in an
HMAC-signed entry keyed by the login-session verifier. This defeats theft of the
WordPress **authentication cookie alone** — a value pulled from a log, a proxy,
or a backup disclosure.

It does **not** defeat a copy of complete cookie state, and
`docs/security-model.md` explains why the gap is structural rather than a
deferred fix: both values are bearer secrets of the same class and the same
browser exportability, so the binding narrows the attack *path*, not the
attacker's capability. A cloned jar carries both, and every check passes.

It also does not defend against script that *rides* the session rather than
stealing it: `HttpOnly` stops script reading the cookie, not causing the browser
to send it. An XSS active during the sudo window is undefended — the proof is
used, not stolen.

### 2.5 Security properties emerged from statement ordering

Issue #524: on fresh single-site activation, the four governance capabilities
were granted to *every* administrator, because `Plugin::activate()` ran the
upgrader before granting the activating admin — so `upgrade_3_3_0()`'s
zero-holder check found no holder and applied its broad bootstrap. The fix was
to reorder two statements.

This is not a gate defect. It shows that a bolt-on layer's security-relevant
behaviour depends on incidental ordering and on state it does not exclusively
own.

(An earlier draft also claimed the #404 replay guard admitted a re-granting
interleaving between two concurrent requests after a stamp loss. That claim was
withdrawn on review: both ordinary interleavings refuse, and no re-granting
interleaving was found. It is not evidence and does not belong in this finding.)

## 3. What this does not establish

- It does not quantify residual risk. Coverage claims here are about enumerated
  rules on enumerated surfaces; nothing measures what fraction of real-world
  consequential effects that represents.
- The hook-ambiguity division in §2.2 is documented from the prototype's own
  design rationale. It has **not** been re-derived independently against core,
  and that is the weakest link in the argument.
- It does not establish that the successor architecture works. See §6.

## 4. What a core primitive would need to provide

### 4.1 An intent signal at consequential effects

The blocker in §2.2. Core would need effects to carry a distinction between
*invoked as this actor's intended operation* and *fired incidentally during a
page load*. Without it, gating the high-surface hooks either over-blocks
legitimate workflows or under-blocks the attack.

This is unlikely to be solvable by adding more hooks; it probably requires
effects to declare intent at the call site.

### 4.2 A scoped, action-bound approval token

*Actor A may perform effect E, once, within window W.* Issued after a successful
credential check; consumed by the **effect**, not by a route.

**This is no longer hypothetical.** PR #470 (Phase 27) reconstructed and
exercised a server-held action-approval candidate across Node and real WordPress
transports, with adversarial fixtures and mutation guards. Its accepted boundary
is deliberately narrower than an XSS-proof claim: authorization remains
server-held and effect-bound, while **active same-origin script and full
cookie-state cloning remain explicit non-goals or concessions** — consistent
with §2.4. Multisite evidence is deferred under #490 and is not represented as
complete.

Design constraint, learned the hard way: the token must authorize a **specific
effect**, not "re-run this stored request." The latter is §2.3 option 1.

### 4.3 A disposition contract for non-interactive surfaces

**Not** a universal non-browser reauthentication mechanism. Reauthentication
presupposes a present human who can produce a credential. Cron, the auto-updater,
and scheduled or queued work have no such actor, so "reauthenticate" is
category-incoherent there rather than merely unimplemented.

What core would need instead is a single contract every surface answers, with
exactly three permitted dispositions:

1. **Present action-bound proof.** Available to any surface with a present actor
   able to produce a credential — interactive browser, and potentially an
   interactive CLI or API client.
2. **Refuse.** Fail closed. The correct answer wherever no proof can be produced
   and no explicit machine policy authorizes the effect.
3. **Follow an explicitly separate machine policy.** A declared, auditable
   authorization for unattended execution, evaluated on provenance rather than
   recency of human authentication.

The value is that disposition 3 is *named and separate* rather than smuggled in
as a permissive default. Today's prototype collapses this into a coarse
disabled / limited / unrestricted switch per entry point, which is why two
different guarantees ship under one name.

### 4.4 Recency as an axis distinct from capability

WordPress capabilities answer *may this actor do this*; they cannot express *and
did they prove it recently*. Every implementation today must simulate that
second axis outside the capability system.

## 5. Consequences for the prototype

- **Keep `HANDLER_LANDINGS` as a bounded fallback.** It is §2.3 option 3, and
  the refusal path it improves remains the fallback under any successor
  architecture. Bound it; do not grow it per screen.
- **Generic request stashing is not a foundation for transparent UX.** It may
  remain the mechanism for *naming* what is being authorised. It should not be
  extended toward restoring user work.
- **State enforcement coverage and lossless-UX coverage as separate claims,
  always.** Enforcement coverage can be broad, through server veto plus effect
  guards. Lossless UX coverage can be promised only for explicitly adapted
  surfaces. Conflating them is how this project's claims have historically
  inflated.
- **Client-side disabling requires a server-bypass regression test, without
  exception.** Where protected controls are disabled and explained in the
  browser, the disabling is presentation and the server veto is enforcement. The
  drift failure is that the former comes to be relied on as the latter — the same
  class as the replay confused deputy. Every adapted surface needs a test that
  exercises the veto with the UX bypassed entirely.

## 6. Next experiment

The approval protocol candidate is already tested (#470). The next experiment is
**integration**: the #470 candidate joined to **one real wp-admin preflight
adapter** and **one genuine effect veto**.

It must preserve the documented copied-cookie-only claim of §2.4 — not widen it
— and must test at least:

- **bypass** — the effect veto still refuses when the adapter is skipped entirely;
- **parameter mutation** — an approved token cannot be redirected to different
  arguments than those approved;
- **replay** — a consumed token cannot be reused, including against a different
  effect;
- **expiry** — behaviour at and past the window boundary, including any grace;
- **active-XSS boundary** — confirming the documented concession holds exactly
  where §2.4 and #470 say it does, and no further.

Success is not "it feels smooth." Until this runs, §4 is a design sketch.

---

## Provenance

Prototype behaviour was verified against the working tree at `origin/main`
`9d29946` on 2026-07-29; enclosing symbols named in §2 were read in context, not
grepped. The §2.3 framing is from the Codex architecture review of the same
date. §2.2's correction, the §1 scope narrowing, the §4.3 disposition contract,
and the §6 revision follow review feedback of the same date. Third-party claims
about WordPress core are registered in `docs/upstream-sources.md` and checked by
`composer verify:sources`; this document adds no unregistered core claim.
