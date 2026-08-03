# Finding: route enumeration and post-submission interception cannot provide ecosystem-wide action-gated reauthentication

**Status:** final finding; constructive direction, not a Core patch proposal
**Date:** 2026-07-29, revised 2026-08-02 (rev. 9)
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

| Bypass | Core dispatches on | Plugin matched on | Axis |
|---|---|---|---|
| REST route capitalisation | case-insensitive route regex (GB-CORE70-REST-CASE) | patterns with no `i` flag | route-pattern case |
| File editor write | POST method alone (GB-CORE70-PLUGIN-EDITOR-POST, GB-CORE70-THEME-EDITOR-POST) | `action=update` required | action derivation |
| `options.php` self-protection | `option_page` from `$_REQUEST` (GB-OPTIONS-PAGE-REQUEST) | `$_POST`, in the self-protection rules | superglobal |
| Bulk promote | `changeit` presence (GB-CORE70-USERS-CHANGEIT) | an `action`-name allowlist, checked before the rule's callback | evaluation order |
| REST plugin deactivation | POST, PUT, and PATCH (GB-CORE70-REST-EDITABLE) | `array( 'PUT', 'PATCH' )` | method set |
| 2FA lifecycle bridge | edited user from `$_REQUEST` (GB-CORE70-USER-EDIT-REQUEST) | `$_POST['user_id']` | superglobal |
| `wp_ajax_add-user` | registered AJAX creation path (GB-CORE70-AJAX-ADD-USER, GB-CORE70-AJAX-ADD-USER-HANDLER) | `'ajax' => null` on the rule | surface coverage |

Seven bypasses, six axes: the superglobal axis accounts for two of them, and the
last row is an absent rule rather than a divergent predicate — the surface was
never covered at all. Do not read the table as one row per axis.

**The distinction that matters:** this is not incompleteness. Incompleteness
would mean *unenumerated* paths escape — the limit anyone expects from a rule
list. What these show is that **enumerated** paths escape, because two
independently maintained predicates over the same request drift apart, and
nothing in the architecture can detect the drift.

Congruence is not a property either side can enforce. Core has no obligation to
hold its dispatch predicate stable, and the plugin has no mechanism to observe
it. Each of the six was found by reading core and the matcher side by side —
which the retained suite did not do: its fixtures asserted the plugin against
the plugin's own request model. Adversarial integration or E2E cases derived
independently from core's dispatch predicates could have exposed the drift
(§3 of the post-mortem develops this).

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

**The audit quantifies how much this costs.** The bypasses reach effect classes
without a complete usable backstop: option writes; user creation, promotion,
and profile-meta mutation; file-editor writes; REST credential mutation and
application-password issuance; and plugin deactivation. The shipping backstop
covers only a narrower set of unambiguous destructive hooks, so it cannot serve
as the safety net for a route matcher that has drifted.

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

The prototype has occupied positions 1 and 2: automatic replay (removed at
4.9.0), then refuse-and-lose-context, which is the concluded, shipped state. A
fix attempting position 3 — `Challenge::HANDLER_LANDINGS`, a single-entry
bounded landing map — was built and independently reviewed twice, but the
project concluded before it merged. The shipped tree occupies position 2 only;
see `docs/sudo-architecture-history.md` for status.

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

## 4. A proposal to WordPress core

The negative result above is only half a finding. This section is the
constructive half.

Core has one decisive advantage a plugin can never have: **it owns both the
user-facing action and the consequential effect.** It can therefore establish an
explicit contract between them, instead of asking a third party to
reverse-engineer request shapes and hope its predicate stays congruent. Every
bypass in §2.1 exists because that reverse-engineering is the only option
available from outside.

This section owes its structure to the Codex architecture review of 2026-07-29,
whose specification of the approval primitive is sharper than the draft it
replaced.

### 4.1 Purpose-specific veto points carrying a normalized action descriptor

Core would identify consequential operations **at the point where their meaning
is unambiguous**: activate this plugin, promote this user, change this critical
option, write these bytes to this file.

Two requirements, and today's hooks satisfy neither.

**It cannot be a re-use of generic hooks.** `pre_update_option_{$option}`
describes a *database event*, not whether a human deliberately initiated a
dangerous action. That is exactly why the prototype's effect backstop had to
exclude option writes and role mutation (§2.2), and why every one of the seven
bypasses lands in that exclusion. A veto point needs to carry a **normalized
action descriptor** — `plugin.activate`, `user.promote`,
`option.critical.update`, `file.write` — issued by the dispatcher that already
knows.

**It must be able to refuse.** An observational `do_action` cannot. A guard
hooked on one can only `wp_die()`, which is the wrong answer for a REST or CLI
caller expecting a `WP_Error`, and it is a failure mode this project has already
hit (#316: a gate placed on an observational hook that could not veto).

### 4.2 Action-bound, single-use approval

After reauthentication, core creates a server-held authorization of the form:

> User 42 may perform `plugin.activate` on `akismet/akismet.php` once, before
> time T, under login session S.

The effect **consumes** that approval atomically at its commit point. It does
not authorize a browser generally, does not replay a stored request, and does
not depend on the request arriving through the same screen or the same parameter
spelling.

Required properties:

- server-issued identifier, with the authoritative state held server-side;
- bound to the actor **and** the login session;
- names the exact effect and the exact target;
- **a digest of the proposed content where bytes matter.** This is the property
  that would have prevented audit #2: the file editors write arbitrary PHP, and
  an approval bound to a screen rather than to the bytes cannot stop it;
- short expiry;
- atomic single consumption;
- lifecycle revocation on logout, password reset, role change, or session
  destruction;
- **fail-closed when the backing store is unavailable.** Empirically earned
  rather than defensive: this project has already shipped a swallowed transient
  read that made lockout checks fail open.

One refinement beyond revocation-on-event. Capabilities conferred at runtime
through `user_has_cap` / `map_meta_cap`, or written directly with `$wpdb`, never
fire the meta hooks a revocation list would watch — the documented blind spot of
the prototype's own escalation guard. So the stronger requirement is that **an
approval must not outlive the authorization decision under which it was issued**,
which means re-evaluating authorization at consumption rather than only revoking
on observed events.

The Phase 27 work in #470 reconstructed and exercised a candidate for this
primitive across Node and real WordPress transports, with adversarial fixtures
and mutation guards. It is useful evidence that the shape is implementable. It is
not a production implementation, and its accepted boundary is deliberately
narrower than an XSS-proof claim: active same-origin script and full cookie-state
cloning are explicit non-goals (§2.4).

### 4.3 An explicit non-interactive disposition

Every protected effect chooses exactly one of three outcomes:

1. **Present a valid action-bound approval.**
2. **Refuse the operation.**
3. **Follow a separately configured machine policy** based on authenticated
   provenance.

Cron and automatic updates cannot reauthenticate, because no human is present.
Treating them as weaker forms of browser requests was one of this project's
conceptual errors — it produced a coarse disabled/limited/unrestricted switch per
entry point, and shipped two different guarantees under one name. Unattended
execution needs its own auditable authorization model, named as such.

### 4.4 Recency as an axis distinct from capability

WordPress capabilities answer *may this actor do this*. They cannot express *and
did they prove it recently*. Every implementation today must simulate that second
axis outside the capability system. A core primitive should make it first-class,
attached at the veto point of §4.1 rather than reconstructed per plugin.

### 4.5 What core should not copy from this prototype

Each of these is a verified failure mode above, not a stylistic preference:

- **matching `$pagenow`, `$_REQUEST['action']`, HTTP methods, or REST route
  regexes** — the direct cause of all six divergence axes in §2.1;
- **reconstructing intent from arbitrary request parameters** — the same defect
  seen from the other side;
- **storing and replaying POST requests** — removed in 4.9.0 after proving a
  confused deputy (§2.3);
- **a reusable "sudo session" that broadly unlocks unrelated operations** —
  approval should be action-bound, not ambient;
- **client-side disabled controls as an authorization boundary** — presentation
  is not enforcement, and the drift toward relying on it is the same class as the
  replay defect;
- **expecting third-party plugins to inherit protection automatically** — §4.7.

### 4.6 The nearest existing mechanism, and the smallest possible ask

Core has already shipped part of §4.1 — the chokepoint and the descriptor,
though not the intent signal and not the veto.

`WP_Ability::execute()` (`wp-includes/abilities-api/class-wp-ability.php:612` in
WP 7.0) normalizes and validates input, runs `check_permissions()`, then fires
`do_action( 'wp_before_execute_ability', $this->name, $input )` at line 645
before dispatching to the execution callback.

That point supplies two of the three things §4.1 asks for:

- **a declared ability supplies an operation identity and validated input at the
  execution chokepoint** — `$this->name` is a stable, normalized operation name,
  and the input has already passed the ability's own schema validation. Note what
  this is *not*: it is **not** proof of human intent. Code may invoke an ability
  for incidental reasons exactly as it may call `update_option()`, so the
  Abilities API does not by itself solve §4.1's intent problem — it solves the
  *identity* half;
- **it is surface-independent** — PHP, REST, and WP-CLI callers all funnel
  through `execute()`, so one guard covers every entry point, which dissolves the
  browser-contract problem (§2.3) for anything invoked this way.

What it lacks is the veto. Line 645 is a `do_action`; it describes, it cannot
refuse. The minimal core revision is therefore additive and small:

```php
$gate = apply_filters( 'wp_pre_execute_ability', null, $this->name, $input );
if ( is_wp_error( $gate ) ) {
	return $gate;
}
```

placed immediately after the permission check. Defaulting to `null` means no
existing behaviour changes.

**What this removes, stated exactly:** a guard attached here keys on the
identifier *core itself declares*, so **request-shape divergence is eliminated
for operations executed through the ability**. There is no second predicate over
the request to drift from core's.

It is not a general guarantee. All of these remain possible: an **incorrect
ability declaration**; an **incomplete input descriptor** (below); code that
**bypasses the ability** and performs the effect directly; and **undeclared
legacy operations** that were never expressed as abilities at all.

**A hard implementation requirement.** The normalized ability input must
*completely describe the protected effect and its target*. If it does not, route
ambiguity has merely been traded for an under-specified operation descriptor —
an approval bound to `file.write` without the target path and content digest is
no better than one bound to a screen. This is the same failure as §2.1 relocated
one layer inward, and it is the thing to watch for when declaring abilities.

**Honest limit on coverage.** The core abilities reviewed for WP 7.0 are
read-only, so the immediate protective value is near zero. This is an argument
about shape, and a bet on consequential operations being expressed as abilities
over time. It does not retrofit `options.php`, `users.php`, or the file editors,
which would need §4.1 veto points of their own.

**The claim in one paragraph, with nothing rounded up:**

> For declared abilities, core already owns a surface-independent execution
> chokepoint carrying a stable operation name and validated input. Making that
> chokepoint vetoable would eliminate the request-shape divergence responsible
> for WP Sudo's seven bypasses, within that declared coverage. It would not
> establish human intent, complete ecosystem coverage, or action recency by
> itself.

### 4.7 Adoption path, and the limit that remains

Three tiers, smallest first, each independently useful and each shippable
without the next:

1. **Add a vetoable pre-execution filter returning `WP_Error`** at the ability
   chokepoint (§4.6). Additive, no behaviour change by default.
2. **Migrate core consequential operations to explicit operation declarations**,
   or adapt the legacy dispatchers (`options.php`, `users.php`, the file editors)
   to carry §4.1 veto points of their own. This is where real coverage arrives.
3. **Attach the approval primitive at that chokepoint** — #470's server-held,
   actor- and session-bound, effect-bound, expiring, atomically consumed
   authorization (§4.2). This is what adds *recency*, which tiers 1 and 2 do not
   provide.


Core can secure **core-owned effects** and provide a framework that cooperating
plugins adopt. It cannot infer the semantics of arbitrary plugin code. A
third-party plugin would have to declare an effect descriptor and consume core's
approval at its own commit point — deliberately, in its own source.

So protection is never automatically inherited, and ecosystem-wide completeness
is not reachable by this route either. What changes is that incompleteness
becomes **honest and declarable** — an operation is either covered by a declared
veto point or it is not — instead of being an unknowable function of whether two
independently maintained request predicates happen to agree today.

Stated constructively, the result of this project is therefore narrower than
"reauthentication cannot work in WordPress":

> **Ecosystem-wide action-gated reauthentication cannot be reliably bolted onto
> WordPress by independently pattern-matching web requests. It needs effect
> semantics and approval consumption inside core — or inside each component that
> owns the effect.**

The second clause is what makes this actionable for plugin authors rather than
merely discouraging: a component that owns its effect can gate that effect
correctly today, without core's help, by demanding proof at its own commit point
instead of inferring intent from a request.

### 4.8 Retained upstream and demonstrator lineage

The core-facing continuation is [Core Trac
#20140](https://core.trac.wordpress.org/ticket/20140), whose account-change
discussion supplies the direct recent-auth lineage for this work. The retained
core proposal and implementation inventory generalise that question to
consequential effects; they are research inputs, not ready-to-land patches.

The separate
[`dknauss/consequential-actions`](https://github.com/dknauss/consequential-actions)
repository remains a historical demonstrator and compact attack narrative. Its
registry, reusable window, and inline-modal architecture predate this finding
and are superseded as a production-security design. Preserve it for comparison;
do not present it as the successor to WP Sudo.

### 4.9 A second, orthogonal track: raising the capability floor (revised after review)

Everything in §4.1–§4.7 answers one question: *did this actor prove it
recently, for this specific action?* WordPress's own capability system already
answers a different question — *may this actor do this at all* — and §4.4
already treats these as separate axes. A second track, sketched in discussion
after this finding's initial drafting (2026-08-02), works entirely on the
second axis instead. **This section replaces an earlier version of itself**,
retracted the same day after an architecture review (Codex) and the source
audit §6 requires found it wrong in three independent, verified ways. The
original text and why each part failed are kept below rather than deleted,
consistent with how §2.5 records a withdrawn claim.

**What was originally proposed, and what was actually wrong with it.**

The original design routed the capability floor through a `user_has_cap`
filter, exempted exactly one "distinguished" account with unusable credentials,
and reached it by switching the session into that identity — `sudo -i`
opening a shell, rather than `sudo` wrapping one command. Three things were
checked and confirmed to be genuine errors, not stylistic quibbles:

1. **The floor is not universal — it silently fails on multisite.**
   `WP_User::has_cap()` calls `map_meta_cap()` first, and if the caller is a
   multisite Super Admin, returns `true` immediately — *before* the
   `user_has_cap` filter ever runs — unless `map_meta_cap()` has already added
   `do_not_allow` to the required capabilities
   (`wp-includes/class-wp-user.php`, verified against WordPress 7.0.1 source).
   `is_multisite()` is false on a single-site install, so this branch never
   triggers there and the original claim held for that case — but "universal"
   was stated without the carve-out, and on multisite every Super Admin
   bypasses the floor entirely. Reaching them at all requires denial inside
   `map_meta_cap()` itself, which is a different and larger mechanism than a
   single filter, with its own multisite test surface.
2. **Gating one "elevate" action does not gate the actions it was meant to
   protect.** §6's own "session-window independence" test demanded that every
   dangerous action, not just the act of switching identity, re-check a
   fresh single-use approval. But the design described only gated the
   elevation step itself — a declared Ability behind a vetoable filter. Once
   a session held the distinguished identity, `install_plugins`,
   `promote_user`, and every other capability check downstream would see an
   account that legitimately holds the capability and proceed exactly as
   normal, with no further check. The document had asked a test to verify a
   property its own described mechanism never supplied. Compounding this,
   the text named the wrong hook — "the vetoable `wp_before_execute_ability`
   filter" — when §4.6 proposes a *different*, new filter
   (`wp_pre_execute_ability`); `wp_before_execute_ability` is the existing
   action already established in §4.6 as unable to veto anything.
3. **The distinguished identity does not earn its complexity, and the
   evidence cited for it does not actually involve one.** Reading `#470`'s
   actual implementation (`tests/e2e/fixtures/phase27-wordpress-adapter.php`,
   on the preserved `research/action-gate-phase-27` branch) — the audit §6
   requires before trusting any recommendation built on it — shows every
   route gated by `current_user_can( 'install_plugins' )` against the
   ordinary, already-authenticated user. There is no identity switch, no
   distinguished account, anywhere in it. `#470` achieves recency and
   effect-binding entirely on the actor's own session. Citing it as evidence
   for a design built around switching identity was citing evidence for a
   different design than the one on the page — and the identity switch adds
   real cost without adding a property `#470` needed to work: it attributes
   dangerous actions to a synthetic account rather than the human who
   performed them, raises its own multisite Super Admin questions, and
   requires separately closing an Application Passwords path for an account
   that need not have existed.

**The corrected model** keeps the human actor's identity stable throughout —
no switch, no synthetic account:

1. Dangerous capabilities are denied by default through a Core-owned
   capability-assurance layer implemented in `map_meta_cap()`, not
   `user_has_cap` — reaching multisite Super Admins requires this, per point 1
   above.
2. Reauthentication adds a short-lived, server-held assurance claim to the
   actor's *existing* login session. Nothing switches; the same person, the
   same session, the same audit trail.
3. That claim does not itself authorize arbitrary work. Each consequential
   effect consumes its own action-bound, single-use approval naming the
   actor, the operation, the target, and — where bytes matter, as `#470`'s
   digest-bound intents demonstrate — a content digest.
4. Core-owned effect vetoes remain authoritative. Disabled UI controls are a
   rendering *consequence* of a real denial, never a substitute for one —
   otherwise it is exactly the client-side-authorization trap named in §4.5
   and §5.
5. Cron, CLI, and automated updates use the separately declared machine
   policy from §4.3, not simulated human reauthentication — unaffected by
   this correction.

This keeps what the original section got right — no standing dangerous
capability, disabling as a consequence rather than a boundary, composing
with rather than replacing §4.1–§4.7's recency axis — while dropping the one
piece (the distinguished identity) that added complexity without adding a
security property, and correcting the one piece (`user_has_cap`) that was
verified wrong outright. §6 is revised to match.

**This corrected model was subsequently built and tested. See the
`research/capability-floor` branch** (added 2026-08-03, preserved the same
way `research/action-gate-phase-27` preserves `#470`). It implements points
1–3 above against live single-site and multisite WordPress 7.0.2, with a
regression suite (47 assertions single-site, 27 multisite) and two
documents: `FINDING.md` for the result, `BOUNDARY.md` for claim-by-claim
verification including every claim that turned out to be wrong.

Three things it settles about this section:

- **Point 1 is confirmed empirically, not just by reading source.** Denial
  in `map_meta_cap()` reaches a multisite Super Admin; the discarded
  `user_has_cap` version does not. The correction §4.9 made was right.
- **Point 3 needs sharpening, and this is the branch's central finding.**
  An approval "naming the actor, the operation, the target" is not
  achievable by naming a *capability*. WordPress capability names are not
  stable referents for effects: of three effects wired and examined, all
  three were mismatched — one guarded an operation that performs nothing,
  and two performed something other than what the capability authorised on
  multisite (`create_users` bypassing the confirmable-signup flow that
  gates instant activation behind `manage_network_users`; `delete_users`
  performing the site-removal core governs with `remove_users`). Each was
  discoverable only by reading core's source in both site contexts, and
  none is detectable by the approval mechanism, which was working correctly
  in all three cases. Binding must happen at the *effect*, not the name.
- **Point 5 is supported from the other direction.** WP-CLI consults no
  capability check at all for user deletion, confirming that CLI needs the
  separately declared machine policy rather than any in-process gate.

The enforcement kernel itself held: single-use, digest-bound,
session-and-binding-bound approvals survived six review rounds, the last
two at a model tier above the code's author, including cross-user theft,
digest confusion, concurrent redemption, and rate-limiter races. What makes
it unshippable as a plugin is not the mechanism but the per-effect,
per-context audit obligation the second bullet implies — plus the floor
removing native UI affordances rather than merely denying them, so each
screen needs its controls restored by hand. `FINDING.md` §5 states the
scaling argument in full.

## 5. Consequences for the prototype

- **`HANDLER_LANDINGS` was built, reviewed, and never merged.** It implements
  §2.3 option 3 as a single-entry bounded fallback; the project concluded
  before the PR landed, so the shipped tree still sends every refused handler
  request to the neutral dashboard (option 2). If this work is ever finished,
  bound it at its current single entry rather than growing it per screen.
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

**The read-the-source audit this section called for has now run, on
`#470`'s actual implementation** (`tests/e2e/fixtures/phase27-wordpress-adapter.php`
and `phase27-real-upgrader.php`, on the preserved `research/action-gate-phase-27`
branch), independently of `#470`'s own fixtures. It found the ghost-identity
design in an earlier draft of §4.9 was evidence for a different mechanism than
the one on the page — see §4.9 for the correction — and it found what `#470`
actually validates: every route gated by the actor's ordinary
`current_user_can()`, a session-bound and `__Host`-prefixed binding cookie
independent of the login cookie, digest-bound single-use intents consumed
atomically against a dedicated table (with a genuine concurrency race in
`WP_Upgrader`'s shared unpack directory found and fixed honestly, not
loosened, per that file's own comments), password re-verification with an
account-scoped lockout (a concurrency race in the lockout's own gate was
found later the same day — see below), and revocation wired to `wp_logout`
and `after_password_reset`. This is real, substantial evidence for the
recency mechanism in §4.1–§4.7 and the corrected §4.9.

**That reading was extended into a full independent reproduction the same
day.** Every lane in `#470`'s own validation record was rerun, not read — from
a fresh clone, a fresh dependency install, and a real Docker-based WordPress
7.0.2 + PHP 8.2 instance, none of it inherited from `#470`'s own CI. All 1,308
PHP unit tests, PHPCS, PHPStan level 6, and Psalm passed. All 15 Node-side
mutation guards were killed. All 15 Node/Playwright handoff tests passed,
including explicit confirmation of both halves of the §2.4 boundary in the
same run — a cloned auth-cookie alone cannot redeem an approval, a full
cookie-jar clone can. Against real WordPress, all 6 adapter tests and all 6
WordPress-side mutation guards passed, including `ATOMIC_CONSUME` killed under
genuine concurrent load — the specific fix flagged as unverified above.
`multisite: false` was reported plainly in the output, matching the stated
deferral under `#490`.

That was reproduction, not extension, and the distinction mattered enough to
name precisely rather than let it blur the way "already tested" blurred into
"already trustworthy" earlier in this document. **It was extended the same
day, by two independent efforts** — this session, and a separate Codex
review — reasoning from the mechanism itself rather than from `#470`'s own
test titles, against a live instance with real Application Passwords, real
logged-in sessions, a real second administrator account, and real concurrent
requests. Both converged on the same shape of result: **the digest-bound,
single-use, session-and-binding-bound approval mechanism held against
everything thrown at it. Its bespoke rate limiter, and the test-only surfaces
around it, did not.**

**What held, confirmed independently by both efforts:**

- **Application-Password authentication combined with a binding cookie stolen
  from a real, separately-authenticated browser session** is refused. App
  Password auth resolves `current_user_can()` correctly but never establishes
  a `LOGGED_IN_COOKIE`, so `wp_get_session_token()` is empty regardless of
  which valid binding cookie accompanies it — `phase27_auth` / 401.
- **A duplicate-named binding cookie** — the exact scenario the adapter's
  custom `HTTP_COOKIE` parser exists to catch, since PHP's `$_COOKIE`
  superglobal silently collapses repeated names — is refused with "Exactly
  one binding is required."
- **Skipping `/approve` entirely** and presenting a digest-matching file
  straight to `/effect-upload` is refused at the atomic `state = 'approved'`
  guard; the sink stays at zero.
- **Concurrent `/approve` calls against one `prepared` intent** resolve to
  exactly one success — confirmed at the database level by both testers,
  independently.
- **A second administrator cannot read, approve, or cancel another user's
  intent.** Generic error, and the database confirms the target row is
  untouched — the `WHERE ... AND user_id = %d` scoping holds.
- **Storage failure remains fail-closed**, and a failed upgrader effect does
  not falsely record a successful capture.

**Confirmed defects, severity-ranked:**

1. **BLOCKER — the approval lockout is permanent, with no recovery path.**
   Three cumulative wrong passwords, even across unrelated intents, and every
   later *correct* password returns 429 forever. A successful approval
   between failures does not reset the counter; advancing the clock past any
   plausible window does not release it; the rate table
   (`user_id`, `failures`) has no timestamp column, so there is no
   time-based backoff to add without a schema change. The response claims
   "temporarily unavailable"; nothing about normal use makes that true.
   Related, found independently in this session by firing genuinely
   concurrent rather than sequential wrong-password attempts: the *same*
   check has a read-then-act race, letting 7 of 10 concurrent attempts be
   fully evaluated before the nominal 3-failure gate catches up (confirmed by
   `failures = 7` in the database). These are two distinct bugs in one
   broken component — one about the gate never releasing, one about the gate
   being racy under concurrency — not the same bug described twice. Fix:
   `phase27_verify_factor()`
   (`tests/e2e/fixtures/phase27-wordpress-adapter.php`) needs an explicit
   time window with backoff and a reset on successful authentication,
   collapsed into the same atomic-update pattern already used correctly
   elsewhere in this file, plus cross-intent tests — not a redesign.
2. **Critical if shipped — `/control` bypasses every boundary the real
   mechanism enforces.** `advance-clock` and `storage-fail` are global
   WordPress options, not scoped to the caller: a second administrator with
   no connection to another user's pending intent can expire it early or
   force it to fail with a 503, confirmed both directions at the HTTP layer
   and in the database. `/evidence` is likewise globally readable to any
   `install_plugins` holder. More seriously: **Application-Password
   authentication can call `operation=session-destroy` without ever
   presenting a browser binding or a REST nonce** — neither of which
   `/control` requires, unlike every route in the real mechanism. Returns
   200, and invalidates that same account's legitimate browser-based
   binding, so their in-progress browser approval then fails with 403. This
   is not cross-*user* — it is cross-*credential-class, same account*: a
   leaked Application Password alone, with no cookie or session access at
   all, is sufficient to deny an administrator their own legitimate approval
   flow. This is acceptable only if the entire `/control` route is provably
   absent from anything ever deployed, not merely undocumented.
3. **High if shipped — the GET-based reset is CSRF-capable.** A bare `GET`
   to `/wp-admin/tools.php?page=phase27-research&phase27_reset=1`, with
   valid login cookies and no nonce at all, deletes pending intents. `GET`
   requests are trivially triggered cross-site (an `<img>` tag is enough),
   unlike the `POST`-only REST routes, which at least require more attacker
   effort. `phase27_render_page()`'s reset branch must stay test-only or
   become a nonce-protected `POST`.
4. **Low — a misleading error message**, confirmed independently by both
   efforts. `/effect-upload` against an intent that was never approved
   (still `prepared`) returns `phase27_consumed` / "Approval already
   consumed" — the identical message and code used for a genuinely
   already-consumed intent. The refusal is correct either way; the message
   tells the caller the wrong reason.

Six named results — six held, five confirmed defects across two testers —
is still not exhaustive and remains short of the standard that found the
seven bypasses in §2.1, which came from reading core's dispatch code end to
end and comparing it against the plugin's matcher line by line. But it is no
longer true that nobody has tried to break `#470` with something it did not
already write down, and it is no longer true that everything tried has held.
Something was tried, from two independent directions, and something broke
in both. That is what this kind of testing is for, and a document that only
ever reported clean results would be less credible for it, not more.

**`#470`, as it stands, should not advance to live integration unchanged.**
The core digest-bound, single-use, session-and-binding-bound approval
mechanism is the part worth carrying forward, and it held under everything
above. The rate limiter needs the fix in finding 1 before anyone trusts it
for a real lockout, and packaging for any live version must **provably and
completely exclude** the `/control` route and the GET-based reset — not
"strip before shipping" as an intention, but a build step that fails closed
if either survives. Carrying a known-broken lockout or a test-only backdoor
into a live feature would be a worse mistake than either defect on its own.

The next experiment is still **integration**: `#470`'s pattern — a
session-bound, digest-bound, single-use intent, consumed atomically at the
actual effect — joined to **one real wp-admin effect that is not already a
research fixture**. `#470` already exercises the real `WP_Upgrader` path; the
natural next step is attaching this pattern to that path (or a declared
Abilities API equivalent, gated by the new `wp_pre_execute_ability` filter
proposed in §4.6, if plugin install is ever expressed as an ability) as a
**live** feature — with **no identity switch anywhere in the design**, per
§4.9's correction, and only after findings 1–3 above are closed, not
alongside them.

It must preserve the documented copied-cookie-only claim of §2.4 — not widen
it — and must test at least:

- **bypass** — the effect veto still refuses when the adapter is skipped entirely;
- **parameter mutation** — an approved token cannot be redirected to different
  arguments than those approved;
- **replay** — a consumed token cannot be reused, including against a different
  effect;
- **expiry** — behaviour at and past the window boundary, including any grace;
- **active-XSS boundary** — confirming the documented concession holds exactly
  where §2.4 and #470 say it does, and no further;
- **cross-effect independence** — a valid session and binding cookie, on their
  own, must not be sufficient for an effect the actor was never issued a
  matching intent for. This is the corrected form of what an earlier draft of
  this section called "session-window independence": there is no elevated
  session to protect, only individual approvals, and each one must stand
  alone;
- **exclusion of the test-only surfaces** — an automated build check, not a
  manual step, confirming `/control` and the GET-based reset are absent from
  whatever ships.

Success is not "it feels smooth," and it is not "#470's own tests still pass"
— though, as of 2026-08-02, independently confirmed rather than merely
claimed, they do, alongside the five defects above that its own tests did
not catch. Until this runs as a live, production-wired feature rather than a
research fixture, with those defects closed rather than carried forward, §4
is a design sketch with unusually strong — and now unusually honest —
supporting evidence.

---

## Provenance

Prototype behaviour was verified against the working tree at `origin/main`
`9d29946` on 2026-07-29; enclosing symbols named in §2 were read in context, not
grepped. The §2.3 framing is from the Codex architecture review of the same
date. §2.2's correction, the §1 scope narrowing, the §4.3 disposition contract,
and the §6 revision follow review feedback of the same date. Third-party claims
about WordPress core are registered in `docs/upstream-sources.md` and checked by
`composer verify:sources`; this document adds no unregistered core claim.

§4.9 and the corresponding §6 revision were added 2026-08-02, after this
project's conclusion and archival, from a design conversation — not from new
implementation or testing. Its original three security claims (server-enforced
versus client-side disabling; automatic coverage of REST-by-cookie and
XML-RPC; the Application Passwords residual and its close) were reasoned
through in that conversation against the mechanisms named, not verified by
running code.

§4.9 was revised the same day. An architecture review (Codex) identified three
errors in the original text: the `user_has_cap` floor does not reach multisite
Super Admins, gating one "elevate" action does not gate the effects it was
meant to protect, and the distinguished-identity mechanism was unsupported by
the evidence cited for it. The multisite claim was verified against
`wp-includes/class-wp-user.php` on WordPress 7.0.1. The third claim was
verified by the source audit §6 requires, run for the first time in this
revision, against `#470`'s actual implementation
(`tests/e2e/fixtures/phase27-wordpress-adapter.php`,
`phase27-real-upgrader.php`) rather than its own fixtures — the identity switch
does not appear anywhere in that code. The capability-floor idea, corrected,
remains unbuilt; see `docs/sudo-architecture-history.md` for the fuller,
plain-language account of where it fits among every approach this project
tried, itself corrected for the same errors.

`#470`'s test suite was independently reproduced the same day: `research/action-gate-phase-27`
checked out into a fresh worktree, dependencies installed from scratch
(`npm install`, `composer install`), every lane run rather than read —
`composer test` (1,308 tests, 3,907 assertions), `composer lint`, `composer
analyse` (PHPStan, Psalm), `npm run test:research:phase27:mutations` (15/15
guards killed), `npm run test:research:phase27:node` (15/15 Playwright tests),
and `npm run test:research:phase27:wordpress` against a real, freshly
provisioned WordPress 7.0.2 / PHP 8.2 instance (6/6 adapter tests, 6/6
mutation guards, `multisite: false` reported by the run itself). All passed.
This is reproduction of `#470`'s own claims by an independent run, not
independent adversarial extension — no attack was attempted that `#470`'s own
fixtures had not already encoded. §6 states this distinction and why it still
matters.

That extension followed, the same day, against a live instance stood up the
same way (`npx wp-env --config tests/e2e/phase27.wp-env.json start`, the
adapter copied in as an mu-plugin via `docker cp`, a real Application
Password minted with `wp user application-password create`). Three attacks —
Application-Password auth combined with a cookie stolen from a separately
authenticated real browser session, a duplicate-named binding cookie, and an
attempt to reach `/effect-upload` with a matching digest without ever calling
`/approve` — were constructed from reading the mechanism, not from `#470`'s
own test titles, and fired as real HTTP requests with `curl`. All three were
refused; a fourth request, a positive control with no attack, succeeded
normally, confirming the refusals were the mechanism holding rather than a
broken test setup.

A fifth and sixth test, the same day, fired genuinely concurrent requests
rather than sequential ones. Ten simultaneous `/approve` calls against one
`prepared` intent with the correct password: exactly one succeeded, confirmed
in the database. Ten simultaneous `/approve` calls with the wrong password
against a lockout reset to zero: seven were fully evaluated before the stated
three-failure throttle caught up, confirmed by `failures = 7` in the
database, not three. This is a read-then-act race in
`phase27_verify_factor()`'s own gate, not in the atomic increment that
follows it — the same rate-limiting bug class that has broken login
throttles elsewhere, found here by testing concurrency rather than assuming
an atomic write implies an atomic decision.

A seventh and eighth test, the same day, targeted the test-only `/control`
surface with a second, genuinely created administrator account rather than a
simulated one: `advance-clock` and `storage-fail` were confirmed to be global
WordPress options reaching a second administrator's unrelated, legitimately
owned intent, in both directions, at the HTTP layer and in the database;
`cancel`, by contrast, was confirmed correctly scoped by `user_id` — the
target row was untouched. The second administrator account was deleted after
testing; the live instance was left running.

**A separate, independent review by Codex ran concurrently against the same
live instance and its own copy of the same branch**, converging on the same
overall shape — the core mechanism held, the rate limiter and the test-only
surfaces did not — while finding two things this session's testing had not:
that the lockout has no reset or time-based recovery at all, not merely a
concurrency race in its gate (the more severe of the two related defects, and
the correct one to lead with); and that Application-Password authentication
can reach `/control`'s `session-destroy` operation without presenting the
browser binding or REST nonce every other route requires, letting a leaked
Application Password alone deny an administrator their own legitimate
browser-based approval. It also independently confirmed the cross-user
intent-ownership scoping this session tested, and independently noticed the
same misleading-error-message detail this session had filed as an aside
rather than a named finding. §6 records the combined result — six held
properties and five confirmed defects, severity-ranked, attributed to
whichever effort found each one — and states plainly that this remains
short of the read-the-source-against-core standard that found the seven
bypasses in §2.1, and that `#470` should not advance to live integration
until the rate limiter is fixed and the test-only surfaces are provably
excluded from whatever ships.
