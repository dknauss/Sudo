# Finding: route enumeration and post-submission interception cannot provide ecosystem-wide action-gated reauthentication

**Status:** final finding; constructive direction, not a Core patch proposal
**Date:** 2026-07-29, revised 2026-08-02 (rev. 7)
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
loosened, per that file's own comments), password re-verification with a real
account-scoped lockout, and revocation wired to `wp_logout` and
`after_password_reset`. This is real, substantial evidence for the recency
mechanism in §4.1–§4.7 and the corrected §4.9.

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
day** with three attacks reasoned from the mechanism itself, not copied from
`#470`'s own test titles, run against a live instance with a real
Application Password, a real logged-in session, and a real stolen cookie
value — not simulated:

- **Application-Password authentication combined with a binding cookie stolen
  from a real, separately-authenticated browser session.** Application
  Password auth resolves `current_user_can()` correctly (confirmed against
  `/evidence`, which succeeded) but never establishes a `LOGGED_IN_COOKIE`, so
  `wp_get_session_token()` returns an empty string regardless of which valid
  binding cookie is presented alongside it. Refused with `phase27_auth` /
  401 — confirmed via the one route (`/control`'s `restore-binding`
  operation) that returns this error unwrapped, since the routes an attacker
  would actually use mask it behind a generic 403.
- **A duplicate-named binding cookie** — the exact scenario the adapter's
  custom `HTTP_COOKIE` parser exists to catch, since PHP's `$_COOKIE`
  superglobal silently collapses repeated names to one value. Sending the
  real binding cookie's name twice, once with its genuine value and once with
  an arbitrary one, was refused with "Exactly one binding is required" — the
  parser's own duplicate check firing as its comment claims, not merely as it
  claims.
- **Skipping `/approve` entirely** — preflighting a real intent against a
  file's actual SHA-256 digest, then presenting that same file straight to
  `/effect-upload` without ever calling `/approve` or entering a password.
  Refused at the atomic `state = 'approved'` guard even though the digest
  matched exactly; `/evidence` confirmed `sink_count: 0` afterward — the real
  upgrader effect never ran.

A fourth request, sent as a positive control with the single genuine binding
cookie and no attempted attack, succeeded normally — confirming the three
refusals above were the mechanism holding under attack, not a broken test
setup producing errors regardless of input.

Three attacks is not exhaustive, and this remains short of the standard that
found the seven bypasses in §2.1 — those came from reading core's dispatch
code end to end and comparing it against the plugin's matcher line by line,
not from three attempts, however genuinely adversarial. But it is no longer
true that nobody has tried to break `#470` with something it did not already
write down. Someone has, three times, and it held each time.

The next experiment is **integration**: `#470`'s pattern — a session-bound,
digest-bound, single-use intent, consumed atomically at the actual effect —
joined to **one real wp-admin effect that is not already a research fixture**.
`#470` already exercises the real `WP_Upgrader` path; the natural next step is
attaching this pattern to that path (or a declared Abilities API equivalent,
gated by the new `wp_pre_execute_ability` filter proposed in §4.6, if plugin
install is ever expressed as an ability) as a **live** feature rather than a
test-only adapter — with **no identity switch anywhere in the design**, per
§4.9's correction.

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
  alone.

Success is not "it feels smooth," and it is not "#470's own tests still pass"
— though, as of 2026-08-02, independently confirmed rather than merely
claimed, they do. Until this runs as a live, production-wired feature rather
than a research fixture, and until someone tries to break it who did not
write it, §4 is a design sketch with unusually strong supporting evidence.

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
broken test setup. §6 records the three attempts and is explicit that three
is not exhaustive and does not meet the read-the-source-against-core standard
that found the seven bypasses in §2.1.
