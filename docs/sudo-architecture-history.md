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

The current design (item 5) was tested, not just theorized, in a testing
effort called Phase 27 — a mechanism that holds up under deliberate attack
attempts, including a genuine WordPress concurrency bug it found and fixed
along the way. It isn't flawless: firing genuinely concurrent password
attempts against it, rather than one at a time, found a real gap in its own
account lockout — more attempts got through in a burst than the stated limit,
because the check for "have they failed too many times" can read a stale
count before an earlier attempt's failure has finished being recorded. That
doesn't let anyone in without the real password; it just means the throttle
meant to slow down guessing is weaker than advertised under a concurrent
attack, and it's a narrow, fixable bug in this design's own rate limiter, not
in the approval mechanism itself. Found the same way as the two things it
already got right in this paragraph — by actually trying it, not by reading
the code and assuming. A separate demo project, `consequential-actions`, is not a
second, independent test of the same thing — it demonstrates item 3, the
earlier reusable-window design that item 4 replaced, and is preserved for
comparison rather than as supporting evidence for item 5. What's unsettled for
Phase 27's design is getting WordPress core to actually adopt anything like
it — that part is still a proposal, not a patch.

## The newest idea (not built yet, and revised once already)

Came up in conversation after the project concluded, and neither track above
tried it: instead of gating requests or gating individual core actions,
change who holds dangerous permissions *at all*. No real account holds
capabilities like "install a plugin" while just sitting there — they're
denied by default, and unlocked only by a deliberate, separate proof-of-
identity step.

**The first version of this idea was wrong in a specific, checkable way, and
got corrected the same day.** It proposed one special account with no working
password or email, reached by switching the session into it after reauth —
like `sudo -i` opening a root shell you then use freely. Three things turned
out to be wrong with that, found by an outside review and by actually reading
the code this idea leaned on for evidence, not by further discussion:

- The mechanism proposed for denying capabilities by default doesn't reach
  every account on a multisite install — a multisite Super Admin bypasses it
  entirely, for a specific, verifiable reason in how WordPress checks
  capabilities.
- Switching one dangerous action (the "become admin" step) behind a proof
  requirement doesn't put a proof requirement on the *other* dangerous
  actions. Once switched in, installing a plugin or promoting a user would
  proceed completely normally, with no further check — the design didn't
  actually deliver the "every action needs its own proof" property it was
  supposed to.
- The evidence cited for this design — Phase 27 — doesn't contain anything
  like a special account or a session switch when you actually read its code.
  It proves a *different, better* idea: that this can all be done to the
  same account, the site owner's own, without ever creating a synthetic
  identity.

**The corrected version keeps the site owner's own account throughout.**
Nothing switches. A short-lived proof of identity attaches to the person's
existing login. That proof doesn't authorize everything by itself — each
dangerous action still has to consume its own one-time approval, tied to
that specific action and, where it matters, to the exact bytes involved.
Disabling the button in the browser is still just a visible side effect of a
real, server-side "no" — never the "no" itself. This keeps everything the
first version got right and drops the one piece (a synthetic account) that
added complexity without adding any real protection.

**Checked, not assumed:** REST calls authenticated by the same login cookie
inherit the same protection automatically; XML-RPC is closed the same way
direct login already is; WP-CLI and cron are correctly out of scope rather
than insecurely uncovered, since CLI access already implies a level of trust
this can't add to, and cron has no logged-in human to check against in the
first place. One real, specific, closeable gap either version shares:
Application Passwords issued to whatever account holds the elevated proof
would bypass the whole thing, since that credential never touches the reauth
step at all.

It would not stop everything, either version. A vulnerable plugin that writes
a dangerous file without ever asking WordPress's permission system anything
would still get through — there's no check there to intercept. And anyone who
already has direct file access to the server could simply edit the rule
enforcing all of this. But within its scope, the corrected version is more
solid than either prior track, because it hooks the one place WordPress core
itself already checks "can this person really do this," instead of guessing
from outside — and it does so without inventing a second identity that never
needed to exist.

## Next best steps

**Track 1 — a working prototype of a real core change.**
WordPress 6.9/7.0 already added a small, relevant hook
(`wp_before_execute_ability`) at the point where a declared "ability" runs —
but it can only watch, not refuse. The concrete next step: a *new*, separate
filter at that same point that can actually say no, with the already-tested
proof mechanism (Phase 27) attached to it, proven as a live feature on one
real WordPress admin screen plus one real dangerous action — not the test-only
adapter Phase 27 already built, but the same pattern wired into something a
site actually runs. Before trusting the result, read Phase 27's own code
independently first, rather than trusting that it does what its own tests say
it does — that step is what caught the special-account mistake above, and
skipping it here would risk the same kind of error a second time.

**Track 2 — a new mu-plugin, built from scratch around "no standing admin."**
Never built, only sketched, and revised once already after the special-account
version above didn't hold up. The next step is a small, working version: a
denial layer written at the point WordPress actually resolves capabilities
(not a shortcut that skips multisite's Super Admins), a short-lived proof
attached to the site owner's own login rather than a second account, one
small "prove it's you" step that unlocks it, and one dangerous action wired
to require its own one-time approval before it runs. Before trusting it, test
it two ways: against the same seven bugs the plugin's own request-matching
failed on, and with a server-bypass check on every surface — submit the raw
request with the UI skipped entirely, on admin, REST-cookie, REST-app-password,
and XML-RPC, and confirm the server still refuses each one.
