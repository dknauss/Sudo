# Consequential-effect authorization for WordPress

**Steal a WordPress administrator's session cookie and you own the site.**
You can install a plugin — arbitrary PHP execution. You can change another
user's email, then their password. You can set `default_role` to
`administrator`. WordPress asks for nothing at any point, because a valid
session cookie *is* the authorization, permanently, for everything.

This is a research record of two failed attempts to fix that from a plugin,
one working demonstrator that fixes it from inside Core, and the finding
that explains why the first two failed.

**It is research, not a product, and it is not going to become one.**

---

## Start here

**→ [`WRITEUP.md`](WRITEUP.md)** — the finding, as prose. ~1,800 words.
Read this first; everything else is evidence for it.

Then, depending on what you want:

| Document | What it is |
|---|---|
| [`PROPOSAL.md`](PROPOSAL.md) | The Core proposal: one vetoable function, ~120 additive lines, what it does and does not solve |
| [`CENSUS.md`](CENSUS.md) | How many seams Core would need — counted against real source, cross-checked against a known attack list |
| [`FINDING.md`](FINDING.md) | Why the plugin approach cannot work, in detail |
| [`BOUNDARY.md`](BOUNDARY.md) | Claim-by-claim verification, including every claim in this project's history that turned out to be wrong |

## The finding, in one paragraph

WordPress capability names are not stable referents for effects. An
approval bound to a capability *name* is not bound to an *operation*. Of
three effects examined closely, all three were mismatched: one guarded an
operation that performed nothing, and two performed something other than
what the capability authorised when run on multisite. In every case the
authorization mechanism was working correctly — the gap was between the
effect a human approved and the effect the function actually performed, and
no approval layer can detect that. Authorization has to bind at the point
the effect is committed, using values the committing code itself computed.

## What is here

```
WRITEUP.md PROPOSAL.md CENSUS.md FINDING.md BOUNDARY.md
core-patch/
  effect-authorization.php      the Core-owned seam library
  effect-authorization.diff     unified diff vs pristine 7.0.2
  patch.php, apply.sh           applies/reverts it in a running wp-env
mu-plugins/
  effect-policy.php             the policy half (Core reports; this decides)
  option-churn-probe.php        the measurement that falsified one of my own claims
tests/
  effect-seam.sh                36 assertions defining what "working" means
  regression.sh                 the earlier capability-floor prototype's suite
```

**The tests are the most reusable artifact.** They encode the two cases
that are easy to get wrong: redirect resistance (an approval for A→B must
refuse A→C) and no false positive on an untouched setting. `apply.sh
<config> revert` is a mutation test — remove only the veto and the blocked
attack must succeed, which is what separates a real control from a
decorative one.

## Running it

Requires Docker and `@wordpress/env`.

```bash
npx @wordpress/env start
core-patch/apply.sh .wp-env.json apply
tests/effect-seam.sh http://localhost:8895 .wp-env.json password
```

The suite checks its own preconditions and aborts rather than reporting a
misleading count — it once reported 21 instead of 36 because an unrelated
plugin was active, which is how a wrong figure reaches a document.

## Status and honesty

This patches WordPress Core. It has no coverage in Core's own test suite,
has never run on a real site, and has no browser-level testing. Four of
roughly fourteen seams exist. It does not defend against an administrator
who knows the password, nor against active same-origin script.

`BOUNDARY.md` records what is verified and what is not, including the
claims I made confidently and then disproved by measuring. There are
several.

## Lineage

Successor to [WP Sudo](https://github.com/dknauss/Sudo) (archived), whose
§4.9 proposed the corrected model this tested. The same history is
preserved there on the `research/capability-floor` branch.

## Licence

GPL-2.0-or-later, matching WordPress.
