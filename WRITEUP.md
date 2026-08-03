# Why a WordPress plugin cannot make you re-enter your password

*What two failed prototypes taught me about where authorization has to live.*

---

Steal a WordPress administrator's session cookie and you own the site.
Not "you have elevated access" — you own it. You can install a plugin,
which is arbitrary PHP execution. You can change another user's email and
then their password. You can set `default_role` to `administrator` and
walk in through the front door forever.

At no point does WordPress ask you for anything. There is no
reauthentication in WordPress for any action, anywhere. A valid session
cookie *is* the authorization, permanently, for everything.

Compare almost anything else you log into. GitHub asks again before you
change a deploy key. AWS asks again. Google asks again. WordPress runs a
very large share of the web and asks once, at login, and then never again.

I spent a long time trying to fix this from inside a plugin. Twice. Both
attempts failed, and they failed in different and instructive ways. This
is what they taught me.

---

## Attempt one: recognise the dangerous request

The obvious approach: watch incoming requests, notice the dangerous ones,
interpose a password prompt. Plugin activation is
`plugins.php?action=activate`. User deletion is
`users.php?action=dodelete`. Enumerate them, match them, gate them.

I built that. Thirty-six rules covering plugin, theme, user, and settings
operations across admin POSTs, AJAX, and REST.

An adversarial audit found **seven complete bypasses across six independent
axes**:

| What Core dispatched on | What the plugin matched on |
|---|---|
| Case-insensitive REST route regex | Patterns with no `i` flag |
| POST method alone | `action=update` required |
| `option_page` from `$_REQUEST` | `$_POST` |
| Presence of `changeit` | An action-name allowlist, checked first |
| POST, PUT, **and PATCH** | `array( 'PUT', 'PATCH' )` |
| Edited user from `$_REQUEST` | `$_POST['user_id']` |
| A registered AJAX creation path | No rule at all |

Look at what these have in common. Not one is an *unenumerated* path —
that's the limitation everyone expects from a rule list. Every one is an
**enumerated** path that escaped anyway, because two independently
maintained predicates over the same HTTP request drifted apart.

And congruence is not a property either side can enforce. WordPress has no
obligation to keep its dispatch behaviour stable, and a plugin has no
mechanism to observe when it changes. You cannot test your way out of this.
The bug isn't in any rule; it's in the idea that a second predicate can
track a first one it cannot see.

## Attempt two: take away the capabilities

So stop guessing at requests. Work on the capability layer instead, which
WordPress *does* expose.

Deny the dangerous capabilities to everybody, permanently. `install_plugins`,
`edit_users`, `delete_users`, `unfiltered_html` — nobody has them at rest,
not even a multisite Super Admin. Then let a fresh password check mint a
single-use, time-limited, session-bound approval that lifts exactly one
denial for exactly one action.

This worked much better. It survived six rounds of adversarial review,
including cross-user theft, digest confusion, concurrent redemption, and
rate-limiter races. The enforcement kernel was sound.

Then I checked what the guarded functions actually *did*.

**`install_plugins`, the flagship, installed nothing.** The route ran the
genuine `WP_Upgrader` pipeline into a throwaway directory under `uploads/`
and deleted it. `wp-content/plugins/` was byte-identical before and after a
`200` response that said `{"status":"installed"}`. Four rounds of review had
treated this as the proof the design worked.

**`create_users` on multisite did more than the capability allows.**
Core's own multisite path runs `wpmu_validate_user_signup()` then
`wpmu_signup_user()` — a *pending* signup the invitee confirms by email —
and activating without that confirmation is gated behind a second
capability, `manage_network_users`. Calling `wp_insert_user()` directly
skipped all of it: a live network-wide administrator, instantly.

**`delete_users` on multisite did something else entirely.** Core's own
docblock says it: on multisite, `wp_delete_user()` "only gets removed from
the site and does not get deleted from the database." That's a *removal* —
the effect Core governs with the separate `remove_users` capability. The
route reported `"deleted"` while the account survived network-wide.

Three effects examined. Three mismatches.

**And the approval mechanism was working perfectly in all three cases.**
Digest binding, session binding, single-use consumption — all correct. The
gap was between the effect a human approved and the effect the function
performed, and nothing in the authorization layer can detect that.

Here is the finding, stated plainly:

> **WordPress capability names are not stable referents for effects.** An
> approval bound to a capability name is not bound to an operation. The same
> name means different things on single-site and multisite; sometimes it
> names an effect the function does not perform; sometimes it
> under-describes what the function will do.

Each mismatch was discoverable only by reading Core's source in both site
contexts. That is a permanent, per-effect, per-context audit obligation
against a codebase that changes underneath you. It is not a framework you
extend.

## What actually works: bind at the effect

Both failures point the same direction. Authorization has to happen where
the effect is *committed*, using values the committing code itself computed
— not at a request predicate, and not against a capability name.

Concretely, one function called immediately before the irreversible change,
which may refuse:

```php
$decision = wp_authorize_consequential_effect( $effect );
if ( is_wp_error( $decision ) ) {
    return $decision;   // caller must not commit
}
```

I built four of these into a patched WordPress 7.0.2 and tested them:
plugin/theme/language package installs, email changes, password sets, and
security-relevant option writes. Thirty-six assertions, all passing, with a
mutation test proving each veto is load-bearing — remove only the veto, and
the blocked attack succeeds.

Two properties turned out to matter more than expected.

**Digest the real bytes, not the claim.** The package seam hashes the
*unpacked tree*, not the uploaded archive, with relative paths sorted so
filesystem iteration order can't leak in. The archive is what the caller
sent; the tree is what gets written.

**Bind the whole transition, not the target.** An approval for "change
bob's email" is redirectable. An approval for "change bob's email *from A
to B*" is not. In testing, an approval for `A→third` correctly refuses
`A→fourth` — and that's the direct regression for an earlier defect where
an approval naming less than the effect could be spent on a different one.

## How many of these would Core need?

This is the question that decides whether the idea is practical, and I
could find no evidence anyone had counted. So I counted, against real
source, in a running install.

**Roughly fourteen.** Code arrival is three (`install_package()`,
`update_core()`, the file editor). Privilege is two. Authentication
material is three. Destruction is two. Configuration and plugin activation
account for the rest.

Fourteen enumerable, stable insertion points, versus thirty-six request
predicates that provably drift. Core function signatures change slowly and
visibly; dispatch behaviour changes quietly.

The number started at ten. It moved when I used the seven bypasses as an
acceptance test — and that movement is more useful than either figure. An
enumeration built forward from effect classes had missed seams that an
independently derived attack list found immediately. Treat any such count
as an order of magnitude, never as complete.

**The seven bypasses turn out to be a good reusable test** for anyone
attempting this. Six of seven are now covered, and — more importantly — the
mechanism that produced all seven is *absent by construction*. A seam parses
no request, so route casing, superglobal choice, method set, and evaluation
order cannot produce a bypass. You cannot have a route-capitalisation
bypass of a check that never looks at routes.

## What this does not fix

**A rogue administrator who knows the password.** Reauthentication proves
the same person is still present. A rogue admin *is* that person. They will
pass the challenge. Insider threat needs multi-party authorization or
out-of-band victim consent, and WordPress has primitives for neither.

**Active same-origin script.** Page-level script can log the password as
it's typed and control what you believe you're approving. WebAuthn narrows
this to "user present and expecting a prompt" but does not close it —
transaction confirmation (`txAuthSimple`) is effectively unimplemented, so
your authenticator says "touch your key," not what you're signing.

**Anything below the API.** Direct `$wpdb` writes and filesystem access
bypass all of it. Partly that's the conceded boundary — code executing on
your host has already won — but only partly: a plugin calling
`update_user_meta()` on the capabilities key never touches `WP_User`, and
that isn't host compromise.

**And this is a research demonstrator, not software.** It patches Core. It
has no coverage in WordPress's own test suite, has never run on a real site,
and has no browser-level testing at all. Four of fourteen seams exist.

## A note on method, which may outlast the architecture

Six review rounds ran against this. The first five all asked the same
question — *find bugs in this code* — and by the fifth they were finding
almost nothing.

The sixth asked a different question: *is the documentation telling the
truth about the code?* It found two live security defects on its first pass,
including one that had survived four adversarial rounds **and** a targeted
fix for its own bug class. Every earlier round had tested the case the
documentation described, rather than the case it didn't.

Two rules I'd now apply anywhere:

**Audit the claims, not just the code.** Once code has been hammered, the
surviving defects hide inside assertions nobody was assigned to verify.

**Verify what a function actually does, in the context it will run in,
rather than what its name implies.** Applied three times here, it found
three defects. No adversarial round ever performed that check.

I'd add a third, which cost me the most: **measure before asserting a
limitation.** I claimed twice that option writes could not be gated, because
Core writes options constantly and deliberate change couldn't be told from
churn. Then I measured. Across twenty admin pages, the front page, a cron
run, thirty-four forced cron events, and cleared update transients:
**three option writes, none of them security-relevant.** The churn was
`_site_transient_update_core` and `cron`. The claim was wrong, and it was
wrong in the direction that let me stop working on a solvable problem.

---

*The prototype, the census, the patch, and the claim-by-claim verification
record are preserved on the `research/capability-floor` branch of the
archived [WP Sudo](https://github.com/dknauss/Sudo) repository. It is
research, not a product, and it is not going to become one.*
