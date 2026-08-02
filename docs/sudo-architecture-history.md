# How Sudo's architecture actually evolved, and what's next

WP Sudo tried more than one approach to "require fresh proof of identity
before dangerous actions." None of these was arbitrary — each ran into a
specific wall, and knowing which wall matters for judging what to try next.

Two efforts ran in parallel and failed differently: the plugin itself (what
actually shipped), and a separate research track aimed at changing WordPress
core.

## What the plugin tried

**1. Match the request, then interrupt it.** From early on, Sudo recognized
dangerous requests by their shape — this admin page, this action name, this
HTTP method — and interrupted them before they ran. This is still most of
how the plugin works.

**2. Auto-replay the request after reauth.** Early versions tried to be
invisible: interrupt, ask for a password, then quietly re-run the original
request once the user proved who they were. Removed in version 4.9.0 — it
turned out to be a "confused deputy": the plugin ends up re-executing an
action on the user's behalf that it can no longer fully verify, and an
attacker who can influence the stashed request gets it replayed with a fresh
credential attached.

**3. Add direct vetoes on a few destructive actions, as a backstop.** In
4.1.0, a handful of genuinely destructive actions (deleting a user,
activating a plugin) got a second check wired to the action itself, not to
the request that triggered it. This part worked, and still works today — it's
not something that broke later.

It was deliberately kept narrow, though, and stayed that way for a specific
reason: it was never extended to the two areas that actually needed it most —
changing settings and changing roles. Not from lack of effort. Those are the
areas WordPress can't tell apart at the signal available. The same internal
signal WordPress fires when a setting changes fires identically whether a site
owner is saving their homepage title or an attacker is quietly renaming who
holds a critical permission — there's no extra flag on that signal saying
"this one's dangerous." Wire a veto to it, and either it blocks harmless,
routine saves constantly (breaking the site for its own legitimate users), or
it has to guess which ones are dangerous — which puts you straight back into
guessing at intent from the outside, the exact problem approach 1 already
failed at.

So this approach didn't fail at what it covers. It hit a wall at the two areas
where the actual damage in the seven-bypass audit turned out to concentrate,
and the wall was structural — a limit in what WordPress itself tells you at
that point, not a limit of effort or design skill.

**4. Land refused requests somewhere useful, instead of the dashboard.**
After replay was removed, a refused request just dumped the user on the
dashboard, losing their place and typed input. A fix was built, reviewed
twice, and pushed — a small map sending specific refused actions back to a
related screen instead. **It was never merged.** The project concluded
before it landed, so the shipped, final state of the plugin still sends
refused requests to the dashboard.

**Where this ended:** an outside audit found seven ways past the plugin's
own request-matching rules, on top of everything above. Every one had the
same root cause — the plugin's rules for "is this a dangerous request" were
built by inspecting the request from the outside (a URL, an HTTP method,
which form field held what), and WordPress's actual processing code
sometimes disagreed with that guess in small ways: different capitalization,
a different superglobal, a slightly different method. The plugin was
maintaining its own copy of "what this request means," and that copy kept
drifting from what WordPress itself actually did.

## What the "fix WordPress core" track tried

Separately, a research effort asked what change to WordPress *itself* would
make this reliable, since a plugin bolted on from outside kept hitting the
same wall.

**1. A registry of dangerous actions first.** Build a list naming every
consequential thing WordPress can do, then require proof before each one.
Later reversed — a registry turned out to be a nice-to-have, not the
load-bearing piece.

**2. Gate the moment code runs, not the request that asked for it.** Put the
check as close as possible to the actual dangerous code, wherever it lives.

**3. A reusable "you proved who you are" window,** then **4. per-action
proof instead** — each dangerous action needing its own fresh proof, rather
than trusting a window opened minutes earlier for something unrelated.

**5. A signed, single-use proof** (the current design, "B′"), tied to the
specific login session so it can't be copied elsewhere or reused for a
different action.

This design was tested, not just theorized — in a separate demo project
(`consequential-actions`) and a testing effort called Phase 27, both of
which showed the mechanism holds up under deliberate attack attempts. What's
unsettled is getting WordPress core to actually adopt anything like it —
that part is still a proposal, not a patch.

## The newest idea (not built yet)

Came up in conversation after the project concluded, and neither track above
tried it: instead of gating requests or gating individual core actions,
change who holds dangerous permissions *at all*.

No real account — not even the site owner's everyday login — holds
capabilities like "install a plugin" while just sitting there. Those live on
one separate account with no working password or email (so it can never be
logged into directly). Using those capabilities requires a deliberate,
separate proof-of-identity step first, which then switches the session into
that account — like `sudo -i` on Unix giving you a root shell you then use
normally, instead of asking permission for every command.

This sidesteps the one problem neither prior track solved: what to do with
a dangerous request that's already in flight when it gets refused. If proof
happens *before* anyone attempts anything dangerous, there's no half-finished
request left to replay, refuse, or reconstruct a landing page for.

It also isn't fooled by the seven bugs that broke the plugin's own
request-matching, because it checks WordPress's actual permission system —
which fires the same way regardless of how the request arrived — instead of
guessing from the outside what a request means.

**Checked, not assumed, before trusting this:** disabling admin UI elements
until reauth is only secure if the disabling is a *consequence* of a real
server-side capability denial, not a substitute for one — otherwise it's
exactly the "client-side disabling as authorization" trap `docs/finding.md`
already warns against. Confirmed it holds across surfaces: REST calls
authenticated by cookie inherit the same protection automatically; XML-RPC
and direct login are closed the same way the special account is closed
everywhere else; WP-CLI and cron are correctly out of scope rather than
insecurely uncovered, since CLI access already implies filesystem trust this
design can't add to, and cron has no logged-in human to check in the first
place. One real, specific, closeable gap: Application Passwords issued to
the special account would bypass the whole thing, since that credential
never touches the reauth step at all.

It would not stop everything. A vulnerable plugin that writes a dangerous
file without ever asking WordPress's permission system anything would still
get through — there's no check there to intercept. And anyone who already
has direct file access to the server could simply edit the rule enforcing
all of this. But within its scope, it's more solid than either prior track,
because it hooks the one place WordPress core itself already checks
"can this person really do this," instead of guessing from outside.

## Next best steps

**Track 1 — a working prototype of a real core change.**
WordPress 6.9/7.0 already added a small, relevant hook
(`wp_before_execute_ability`) at the point where a declared "ability" runs —
but it can only watch, not refuse. The concrete next step: a working patch
that makes that hook able to say no, with the already-tested proof mechanism
(the B′ design) attached to it, proven on one real WordPress admin screen
plus one real dangerous action. Not a redesign — a small, working
demonstration that two already-proven pieces (a refusable hook, a working
proof mechanism) actually fit together in real WordPress.

**Track 2 — a new mu-plugin, built from scratch around "no standing admin."**
Never built, only sketched. The next step is a small, working version: one
rule file that strips dangerous capabilities from everyone, one special
account with no usable password or email (and Application Passwords
explicitly disabled for it), one small "prove it's you, then switch me in"
page, and a written list of what's deliberately left out (server-level
access, and non-capability-checked vulnerabilities in third-party code).
Before trusting it, test it two ways: against the same seven bugs the
plugin's own request-matching failed on, and with a server-bypass check on
every surface — submit the raw request with the UI skipped entirely, on
admin, REST-cookie, REST-app-password, and XML-RPC, and confirm the server
still refuses each one.
