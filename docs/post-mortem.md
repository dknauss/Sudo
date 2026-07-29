# Post-mortem: WP Sudo

**Date:** 2026-07-29
**Status:** final. The project is concluded, not paused.

This is the methodological record. The technical result is in
`finding.md`; the evidence for it is in
`audit-verification-record.md`. This document is about **how the project failed
to see what it had already found**, which is the more transferable outcome.

---

## 1. What was built and what happened to it

WP Sudo gated 36 enumerated WordPress operations behind password
reauthentication, across admin, AJAX, REST, CLI, cron, XML-RPC and WPGraphQL
surfaces, with effect-level backstops for a subset of destructive core effects.
It accumulated 1,308 unit tests, 243 integration tests, 112 E2E tests, a static
analysis gate at PHPStan level 6 plus Psalm, an upstream-source registry checked
against live third-party code, and a mandatory pre-commit review gate.

An adversarial audit then found **seven high-severity bypasses across six
independent axes**, each a complete defeat of the product's central claim. All
seven were independently verified against WordPress 7.0 source. None had been
caught by any of the above.

## 2. The single technical shape

Every one of the seven is the same defect: **the plugin's matching predicate is
not congruent with the predicate WordPress core dispatches on.**

| Axis | Core dispatches on | Plugin matched on |
|---|---|---|
| REST route case | `preg_match( '@^…$@i' )` | patterns with no `i` flag |
| File editor write | `'POST' === $_SERVER['REQUEST_METHOD']` | `action=update` required |
| `option_page` | `$_REQUEST` | `$_POST` (in the self-protection rules) |
| Bulk promote | `isset( $_REQUEST['changeit'] )` | an `action` name allowlist |
| REST method set | `EDITABLE = 'POST, PUT, PATCH'` | `array( 'PUT', 'PATCH' )` |
| Edited user | `$_REQUEST['user_id']` | `$_POST['user_id']` |

This is not incompleteness. Incompleteness would mean unenumerated paths escape.
These are **enumerated** paths escaping, because two independently maintained
predicates over the same request drifted, and nothing in the architecture could
detect the drift.

## 3. Why the test suite could not find any of it

The suite is large and it is green. It also could not, in principle, have caught
these.

Every test asserts the plugin against **its own model of a request**. A test
constructs a request shaped the way the rule expects, then asserts the rule
fires. When the rule's predicate is wrong, the test is wrong in exactly the same
way, and passes. Congruence with core is not a property the plugin can test
against itself.

All six axes were found by reading core and the matcher **side by side**. That is
a different activity from testing, it was never part of the process, and no
amount of coverage substitutes for it.

**Transferable rule:** when correctness is agreement with an external system, a
test that does not read the external system is measuring self-consistency and
reporting it as correctness.

## 4. The failure that matters most

The `options.php` bypass (audit #3) is the one to keep.

The author **correctly diagnosed the exact bug class**, wrote it into a code
comment — that `option_page` travels in the query string because
`wp_magic_quotes()` rebuilds `$_REQUEST` — and **fixed it**, at
`Action_Registry:605`.

Lines 699 and 718, the two rules that stop WP Sudo itself from being
reconfigured, kept `$_POST`.

The same pattern repeats at audit #5: the REST `EDITABLE` trap was identified and
fixed for the users route, with an explanatory comment, and never applied to the
plugins route — which is what permits `POST /wp/v2/plugins/wp-sudo/wp-sudo
{"status":"inactive"}`.

So the project's defining defect is **not ignorance of the bug class. It is that
diagnosing a class did not produce a sweep of its instances.** Understanding was
recorded as prose next to a single fix, and prose next to a fix looks
indistinguishable from a solved problem.

**This recurred during the final session, at small scale.** An overbroad claim
was narrowed in `CHANGELOG.md` while two copies of the same claim survived in
`ChallengeTest.php`. It was caught only because a reviewer was told to be
skeptical and went looking for other instances. Identical shape, four orders of
magnitude smaller, same day.

**Transferable rule:** a diagnosis is not done when the instance is fixed. It is
done when something mechanical enumerates the other instances. "I understand this
class now" is the moment of highest risk, not lowest.

## 5. What the guardrails actually did

Honest accounting, because several of these were expensive.

**Worked.**

- **The upstream-source registry** (`docs/upstream-sources.md` +
  `verify:sources`). It forces a claim about third-party code to name an
  enclosing symbol and be re-checked against live source. It is the only
  mechanism here that was pointed at the external system, and it is the only one
  whose subject matter overlaps the defect class. It caught real drift.
- **The adversarial review gate.** The final session's reviewer rejected a
  change, correctly, for exactly the incomplete-sweep failure in §4. Its value
  came from being told to disbelieve the author, not from running tests the
  author had already run.
- **`PROJECT-STATUS.md`.** Reclassifying to research prototype, with an explicit
  do-not-install warning, was correct and early. It is why seven high-severity
  bypasses are a research result rather than an incident.

**Did not work.**

- **Test count.** 1,663 tests across three suites detected none of the seven. See
  §3. Volume was mistaken for assurance.
- **Static analysis.** PHPStan level 6 and Psalm are type-correctness tools. Every
  bypass is type-correct.
- **The effect-level backstop.** Real, and scoped to six unambiguous destructive
  effects — which is a defensible design given that core fires option-write and
  role hooks incidentally. But all seven highs land inside the excluded set. The
  gateable effects were not the effects that mattered.

**Actively harmful.**

- **Release machinery on a prototype.** Four-place version synchronisation, a
  `Stable tag`, blueprint pinning, an SBOM drift gate, a wp.org submission
  checklist for a submission that was never planned. It generated per-PR work,
  produced merge conflicts in files that cannot be merged (line-count metrics
  must be re-measured by running the suite), and made a research artifact look
  like a shippable product.
- **Line-count metrics as tracked, gated values.** They changed on comment-only
  edits, conflicted in every parallel branch, and measured nothing anyone needed.

## 6. Process failures independent of the code

- **Concurrent agent sessions duplicated whole PRs.** Three independently built
  the same version rollback. The mitigation added — search by issue number before
  branching — treats the symptom. The cause was fourteen simultaneous branches
  over a shared tree.
- **Prose drifted from behaviour, repeatedly.** A dedicated log of confabulated
  claims exists because it kept happening. The final session produced two more
  instances, both caught in review.
- **Fixes outran evidence.** A concurrency defect was asserted twice with
  confidence in the final session and withdrawn on inspection: both ordinary
  interleavings refuse. Asserting a race is cheap; deriving one is not.

## 7. What we would tell someone starting this again

1. **If your correctness condition is agreement with an external system, build
   the differential harness first.** Not tests of your matcher — a harness that
   reads the other system's dispatch predicate and fails when yours diverges. If
   that is impossible, that impossibility *is* the finding, and it is available on
   day one rather than after 1,663 tests.
2. **Do not gate on routes.** Route enumeration cannot reach ecosystem-wide
   completeness, and worse, it silently fails on routes you did enumerate.
3. **Gate effects you own, or ask the platform for an intent signal.** Effect
   vetoes work where the hook is unambiguous. Where the platform fires the same
   hook for routine and consequential operations, no plugin can separate them —
   that is a platform gap, and naming it precisely is worth more than working
   around it.
4. **State enforcement coverage and lossless-UX coverage as separate claims.**
   Conflating them is how the central claim inflated.
5. **When you diagnose a bug class, enumerate its instances mechanically before
   you fix the one in front of you.**
6. **Classify honestly and early.** The do-not-install notice is what makes this
   document publishable.

## 8. Disposition

The project concluded. The implementation and tests are retained, read-only, as
the evidence the findings rest on — deleting them would leave assertions nobody
can reproduce, which is the failure mode this project exists to document.
Product and release machinery were removed. The repository is archived.

The forward-looking result is a narrow proposal to WordPress core: **explicit
effect vetoes plus action-bound, single-use approval.** Its argument is not that
WP Sudo should have been built better. It is that the seven bypasses in §2 are
what a competent, heavily tested, adversarially reviewed implementation of
route-based gating produces — and that a platform-level primitive is therefore
the only thing that can work.
