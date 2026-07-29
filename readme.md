# Sudo — concluded

![Fuwa-no-seki barrier gate](assets/fuwa-no-seki-narrow.png)

> So full of cracks,
> the barrier gatehouse of Fuwa
> lets both rain and moonlight in —
> quietly exposed, yet enduring.
>
> — [Abutsu-ni](https://en.wikipedia.org/wiki/Abutsu-ni), *Diary of the Waning Moon*

The verse was chosen at the start of this project, for the gate metaphor. It
turned out to be the finding: this barrier had cracks too — seven of them,
verified — and what endures is not the gate but the record of where the light
came through.

> [!CAUTION]
> **Do not install this plugin.** Not on production, not on staging, not on any
> site with real users, credentials, or data. It contains seven verified
> high-severity bypasses of its own central claim. They are documented rather
> than fixed, because they are the result.

WP Sudo was a research prototype investigating action-gated reauthentication in
WordPress — requiring a fresh proof of intent before consequential operations,
regardless of role. It took its name and its symbol from the gate: 門, the
radical that runs through East Asian writing, evoking the fortified pass where
movement is examined rather than assumed. It is finished. This repository is
archived and read-only.

## The result

**A WordPress plugin cannot provide ecosystem-wide action-gated
reauthentication through route enumeration and post-submission interception.**

Two mechanisms fail, and they fail on the same operations.

**Route matching drifts from core.** An adversarial audit found seven
high-severity bypasses across six independent axes — REST route case, HTTP
method set, `$_POST` versus `$_REQUEST`, action-name derivation, matcher
evaluation order, and surface coverage. Each is a total bypass. All seven were
independently verified against WordPress 7.0 source.

The defect is not an incomplete rule list. It is that the plugin's matching
predicate and the predicate WordPress core dispatches on are two independently
maintained things that drift, with nothing able to detect the drift.

| Axis | Core dispatches on | Plugin matched on |
|---|---|---|
| REST route case | `preg_match( '@^…$@i' )` | patterns with no `i` flag |
| File editor write | `'POST' === $_SERVER['REQUEST_METHOD']` | `action=update` required |
| `option_page` source | `$_REQUEST` | `$_POST`, in the self-protection rules |
| Bulk promote | `isset( $_REQUEST['changeit'] )` | an `action`-name allowlist |
| REST method set | `EDITABLE = 'POST, PUT, PATCH'` | `array( 'PUT', 'PATCH' )` |
| Edited user | `$_REQUEST['user_id']` | `$_POST['user_id']` |

**Effect vetoes work, but not where it matters.** Hooking the effect rather than
the route is sound, and does gate unambiguous destructive effects such as
`delete_user` and `activate_plugin`. It cannot be generalised to option writes or
capability mutation, because core and ordinary plugins fire those same hooks
incidentally during normal admin loads — there is no intent signal to key on.
Every one of the seven bypasses lands in that excluded set.

1,308 unit tests, 243 integration tests, 112 E2E tests, PHPStan level 6, Psalm,
and a mandatory adversarial review gate detected none of the seven. They could
not have: every test asserts the plugin against its own model of a request, so a
wrong predicate produces a wrong test that passes. All six axes were found by
reading core and the matcher side by side.

## What this argues for

A narrow WordPress core primitive:

1. **Explicit effect vetoes** — an intent signal at consequential effects, so a
   guard can distinguish an actor's intended operation from the same hook firing
   incidentally during a page load.
2. **Action-bound, single-use approval** — *actor A may perform effect E, once,
   within window W*, consumed by the effect rather than by a route, authorising a
   specific effect and never "re-run this stored request."
3. **A disposition contract for non-interactive surfaces** — present
   action-bound proof, refuse, or follow an explicitly separate and auditable
   machine policy. Not universal reauthentication: cron and the auto-updater have
   no present human, so reauthentication is category-incoherent there.

## Documents

| Document | Contents |
|---|---|
| [`docs/finding.md`](docs/finding.md) | The technical result and what a core primitive would need |
| [`docs/audit-verification-record.md`](docs/audit-verification-record.md) | Independent verification of all seven bypasses against WP 7.0 source |
| [`docs/post-mortem.md`](docs/post-mortem.md) | How a heavily tested project failed to see what it had already diagnosed |
| [`docs/security-model.md`](docs/security-model.md) | Threat model and the boundaries the prototype never claimed to cover |
| [`docs/upstream-sources.md`](docs/upstream-sources.md) | Every third-party claim, with enclosing symbol, machine-checked |
| [`PROJECT-STATUS.md`](PROJECT-STATUS.md) | The research-prototype classification and why it exists |

The implementation and test suites are retained, read-only, as the evidence the
findings rest on. Deleting them would leave assertions nobody could reproduce,
which is the failure mode this project exists to document.

## Honest scope

Every bypass presupposes an **already-authenticated administrator session**.
None is a privilege crossing by a low-privileged or unauthenticated actor. They
are complete defeats of what this plugin claimed to provide — reauthentication as
a barrier in front of a compromised admin session — and nothing more than that.

The findings concern **this plugin's architecture**. They do not establish that
no plugin can gate effects it owns, and they are not a vulnerability report
against WordPress core or any third-party plugin.

## Acknowledgements

Sudo's core design owes a debt to three people:

- **John Blackbourn**, for the action-gating concept — that consequential operations should require a fresh proof of intent, regardless of role. It is the single biggest conceptual contribution to the project.
- **Tim Nash**, for the idea of locking down roles and permissions, which shaped Sudo's opt-in admin-escalation guard and its opt-in role/capability lockdown audit.
- **Calvin Alkan** (Snicco / Fortress), for critical feedback on WP Sudo and for Fortress as inspiration — including the argument that a regular plugin cannot fully achieve this on its own (a motivation for proposing parts of it to WordPress core) and the "this is becoming a SIEM" critique that shaped Sudo's explicit not-a-SIEM boundary.

That third argument is the one this project ended up demonstrating. The finding
above is its evidence, arrived at independently and the long way round.

## License

GPL-2.0-or-later.
