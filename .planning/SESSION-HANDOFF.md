# Session handoff — 2026-07-26 (core-gate reconciliation + doc consolidation)

## ⇢⇢⇢ RESUME HERE (updated 2026-07-26 end of session — plugin security lane CLOSED + follow-ups)

**Scope of this block: the plugin lane only.** It closes out the "Plugin lane (parallel,
user launches separate sessions)" line in the core-gate block below — that lane is now
**done**, so ignore its kickoff instructions. Everything else in the blocks below
(core-gate, #322, process/CI) is untouched by this session and still current.

**Merged, and all three issues closed:**

| PR | Issue | What landed |
|---|---|---|
| **#348** | #278, #279 | Forge-resistant, **per-login-session** sudo proof. `_wp_sudo_proofs` is a map keyed by `sha256(verifier)`, entries `{token, expires, hmac}`, HMAC over `"$user_id\|$verifier\|$token\|$expires"` with `wp_salt('auth')`, read **cache-bypassed** on enforcement. Subsumes the old `_wp_sudo_session_bind`. `_wp_sudo_expires` demoted to a liveness/enumeration marker that no longer decides enforcement. Concurrent browsers now hold independent sudo sessions. |
| **#343** | #280 | `wp sudo unlock --user=<id\|login>`, plus the mechanism that makes it actually clear the **whole** episode (below). |

**The #280 mechanism, because it is non-obvious and easy to "simplify" wrongly.** A
lockout fires on `max(user_attempts, ip_attempts)`, but the stored pointers name only the
IP that submitted the *threshold* attempt — so 4 failures from IP A then the 5th from B
left A's rolling window intact and A re-locked on the next wrong password. `delete_transient()`
is also per-blog while user meta is network-global, so a lockout raised on subsite A could
not be cleared from B. Both are closed by a per-user **failure epoch**
(`_wp_sudo_ip_failure_epoch`) folded into both IP transient-key builders: one bump orphans
every key derived from the old value, across every IP and every blog, and orphans die on
their own TTL. **Epoch 0 omits the segment entirely, so keys are byte-identical to the
pre-epoch scheme — no migration.** A design review rejected the obvious alternative (a
roster of every IP touched): the serialized read-modify-write reintroduces a lost-update
race, and pruning a *sliding* 24 h window by first-seen can drop a still-live entry. Key
versioning has neither hazard — a doubled increment is harmless.

**Follow-ups from review** — everything the Codex passes surfaced that was not fixed inside #348/#343. All verified real before filing; the merged ones were done later in the same session:
- **#354 (open)** — Site Health `find_stale_sessions()` classifies from the *cached*
  `_wp_sudo_expires` and then deletes `PROOF_META_KEY`, so the same failed-invalidation
  scenario #278 exists to tolerate can delete every live proof for a user. Fail-closed, but
  an availability bug.
- **#355 (open)** — `Sudo_Session::activate()` called before the logged-in cookie exists
  (SSO / programmatic login) stores the proof under `sha256('')` and returns success; the
  next request looks under the real verifier and the grant is silently unusable. Relevant to
  the passwordless-SSO fallback in `docs/FAQ.md`.
- **#356 (closed)** — uninstall left the IP-scoped transients behind. Fixed in-flight by a
  concurrent session inside #343 (prefix `DELETE` on `wp_options`, since the keys are hashed
  and cannot be enumerated for a `delete_transient()` loop).
- **#374 (merged)** — `bin/verify-sources.sh` walked the working tree rather than tracked
  files, so a local (gitignored) `reviewer-approved` flag containing an upstream URL failed
  `composer verify:sources` while CI, on a clean checkout, passed. Now filters with
  `git check-ignore` — deliberately "ignored", not "untracked", so an uncommitted doc still
  has to satisfy the registry rule. **The spawned session that wrote it stopped without
  committing**; the work was sound but sat unpushed in its worktree until picked up. Worth
  assuming that failure mode rather than trusting a completion signal.
- **#375 (merged)** — follow-up review of #343 after it landed. The `wp sudo unlock`
  "nothing tracked" branch bumps the epoch (a real state write) but did not fire
  `wp_sudo_lockout_cleared`, contradicting the hook's own contract; it now fires with
  `$was_locked = false`. Also swept the stale "no epoch write" claims this session's own
  unconditional-bump change left behind.
- **#371 (open)** — the uninstall transient sweep reaches `wp_options` only. On a site with a
  persistent external object cache the transients live in the cache, so a reinstall inside
  that TTL can still revive a cleared window at epoch 0. Proposed fix is a
  per-installation-instance nonce in the key material; deferred because `ip_key_material()`
  is on the per-failed-attempt path and wants its own TDD pass.
- **#379 (open)** — the enforcement gap behind trap 1 below. `CHANGELOG.md` is still in
  `GRANDFATHERED_ORPHANS`, held there by a *single* line in the frozen `## 4.8.0` section, so
  a reverted citation only warns. Proposal: scope the scan to `## Unreleased` and ungrandfather
  the file, leaving released sections as frozen history. **Filed against the #377 lane**
  (`fix/verify-sources-hardening`) rather than implemented here, because that PR is rewriting
  the same script — editing it concurrently would reproduce the collision class the issue is
  about.

**Traps this session hit — read before merging `main` into any open branch:**

1. **The CHANGELOG conflict silently reverts #332's citation migration.** It happened on
   *both* branches: resolving in favour of the branch's older copy of the #288 in-editor entry
   swapped `GB-PRESSED-FILL` / `GB-ICON-SWAP` / `GB-SELECTED-ICON` / `GB-NO-TOOLTIP` back to
   duplicated Gutenberg paths and snippets. Both were caught by review, not by CI —
   `verify:sources` only *warns* on `CHANGELOG.md` (grandfathered). **Resolve the Unreleased
   list additively and then `grep -o "GB-[A-Z-]*" CHANGELOG.md` to confirm nothing was lost.**
   Tracked as #379. **Corollary, learned the hard way in #375:** a claim about behaviour
   usually exists in *three* places here — the inline docblock, `docs/developer-reference.md`,
   and `CHANGELOG.md`. Fixing two of three is the default failure. `grep -rn` the phrase before
   calling it done.
2. **`uninstall.php` can only be `require`d once per PHP process.** The two existing require
   sites never collide because one is single-site and the other multisite. A *second*
   single-site integration test that requires it fatals with "Cannot redeclare
   `wp_sudo_cleanup_governance_caps()`". Fold new uninstall assertions into
   `test_single_site_uninstall_cleans_all_data` rather than adding a test — `@runInSeparateProcess`
   re-bootstraps WordPress, and `function_exists()` guards in `uninstall.php` would be a
   production test-shim, which `CLAUDE.md` forbids.
3. **`composer verify:i18n` now runs in CI** (Code Quality job, first wired in #332) and the
   POT carries **line references**, so any edit that shifts lines in a file with translatable
   strings makes it stale. Regenerate with `composer i18n:make-pot` — check `wp --version`
   reports **WP-CLI 2.12.0**, matching the pin in `phpunit.yml`, or the output will not match CI.
4. **A `PHPUnit` red is often a cascade.** It is a summary job; when Code Quality fails
   (i18n, lint, static analysis), PHPUnit reports `dependency did not succeed`. Read the
   Code Quality log first.
6. **`git diff A B` does not answer "what would merging do".** It is a two-tree diff, so
   files that exist only on `main` read as *deletions* — one session concluded a branch
   would delete the ~900-line `poc/` tree from that output alone. Use
   `git merge-tree $(git merge-base A B) A B`, which shows those paths as **added in
   remote**. The wrong command here does not error; it answers a different question
   confidently.
7. **A closed PR's patch survives its branch being deleted.**
   `gh api repos/OWNER/REPO/pulls/N/files` still returns it. #390's branch was deleted as
   redundant and its best paragraph was recovered from the closed PR afterwards. Do not
   assume content is gone because the branch is.
8. **Verify the enclosing symbol even when the claim comes from a source you trust.** A
   hook was cited to `Challenge::handle_replay_response()` in cross-session messages and
   in a PR comment; that method does not exist — the hook is in
   `Challenge::build_replay_response_data()`. It survived because each reader verified the
   parts they doubted and passed the symbol through, so repetition made it look
   corroborated. `git grep -c "function <name>"` costs nothing and is the whole check.
   Related: the repo's own prose rule already says a symbol you cannot name is a symbol you
   have not read — that applies to symbols you were *handed*, not only ones you looked up.

5. **Branches here can be shared.** Another session pushed to `fix/280-lockout-clear` while
   this one was working it. **Merge their commits, never force-push** — and re-run the gates
   after, since their new integration test arrived red.

**Working alongside other sessions.** Four ran concurrently on 2026-07-26 and produced
roughly a dozen cross-session errors, every one of the same shape: **a stale or partial
answer, completed from expectation, carrying confidence borrowed from the fact that a tool
ran at all.** These are the structural fixes, not the diligence ones — diligence rules decay.

6. **Discovery beats broadcasting.** Three sessions opened the *same* version rollback
   (#389/#390/#391) within six minutes. No amount of announcing would have caught it; nobody
   ran `gh pr list` before branching. Check the repo, not your recollection of it.
7. **Name the SHA a review applies to.** Post-then-fix races are constant here. Two reviews
   of mine were called stale when both had in fact *preceded* the fix by 44 s and by 5 m 40 s;
   the genuine error in this class was different — reading another session's force-push as the
   branch owner's, which sent me rebasing an already-closed PR.
   `git fetch && git log --oneline -1 <branch>` immediately before posting settles it in the
   artifact instead of in anyone's memory.
   **Corollary — a claim about a session's conduct goes to that session, not only to the
   others.** The asymmetry is structural, not a matter of care: when a coordinating node is
   wrong *about source*, anyone can run the command, and tonight we caught every such case
   cheaply. When it is wrong *about a session's conduct*, only that session can check, and
   only if it hears the claim. Three were made about this session; two were heard. The third
   would have stood indefinitely — not through carelessness, but because its subject was never
   in the room. A node accumulates uncontested claims about participants in proportion to how
   well it routes, so the fix is not "assert less", it is **assert to the subject**.
8. **`git diff A B` is not a merge preview.** It reports files on `A` and absent on `B` as
   deletions, which reads exactly like `B` will delete them. It will not — a merge applies
   `B`'s diff *relative to its merge base*. Ask the real question with
   `git merge-tree $(git merge-base A B) A B`, or `git diff $(git merge-base A B) B -- <path>`
   (empty ⇒ never touched). Misreading this produced a false alarm that a branch would delete
   the whole `poc/` tree, which in turn sent a session rebasing an already-closed PR.
9. **A retraction never reaches whoever already acted on the claim.** Correcting the source
   does not recall the copies. So: **correct in place *and* announce** — a body edit serves
   future readers, a comment reaches existing subscribers, and doing only one is half a
   retraction. Say what was *wrong*, not only what is now right, or the reader who
   propagated the stale version stays confident and wrong.
10. **Cite the claim you acted on.** A citation is a back-edge a retraction can travel along.
    An uncited claim propagates as an orphan that no correction can ever reach.
    *Worked example, chosen because the propagator knew the rule at the time:* a false claim
    about a review's timing entered a broadcast roster, was repeated by a second session in an
    apology **whose own subject was failing to check things**, and reached a third hop before
    one `git show -s --format=%cI` falsified it. Nobody was careless; the claim simply had no
    citation to travel back along. This session then committed the same error in the opposite
    direction — handing another lane a derived contract instead of a citation, nearly
    producing a third copy of a section that already existed on an unmerged branch.
11. **A wrong justification for a correct test is more durable than a wrong test**, because
    nothing fails to reveal it. Two live examples this session: an invented enclosing symbol
    in the sentence carrying a release's whole version argument, and *"`sudo_required` …
    which only `Gate` emits (verified)"* in the comment explaining why an E2E suite is not
    vacuous — `Admin` emits it in three places. Both tests were fine; both explanations were
    the load-bearing part.
12. **Mutation-test a guard before believing its tests.** Delete the clause, run the filter.
    Half of a two-clause security guard in #397 was verified by nothing — the full 1228-test
    suite passed with it removed. Reading cannot answer "is this test doing work"; deleting
    can, in two minutes.
13. **Drive the entry point, not the sink.** A test that calls the guarded function directly
    proves the check works *when reached* and says nothing about *whether it is reached*.
14. **A command in a doc is not a claim, it is a change someone will execute.** Trace it on
    single-site *and* multisite. A `wp option delete wp_sudo_db_version` remedy shipped in a
    release doc would have replayed every migration from `0.0.0`, granting governance caps to
    every administrator — and on multisite silently done nothing, since it targets a blog
    option while the reader is a site option. Eight factual claims in that same section were
    verified; the executable one was not.

**Release-state note — the version is `4.9.0`. Do not re-derive it from this file.**
This note has now been wrong in both directions inside a single session: first "#364 will bump
to 4.9.0" (#364 was closed), then "5.0.0, not 4.9.0" (rolled back by #391). Treat
`docs/release-status.md` as canonical and this line as a pointer, not a source.

**Why it moved twice.** #372 took `5.0.0` **by the rule**, on the premise that #322 removed the
only call site of `wp_sudo_action_replayed` and `VERSIONING.md` classes removing a documented
public hook as MAJOR. That premise did not hold: PR #350 merged the #322 **v1 and v2** layers,
and v2 restores origin-bound replay — so the hook still fires (`includes/class-challenge.php`).
Nothing documented was removed, no MAJOR trigger fired, and the same rule yields a MINOR.
Verified in #392: no hook or public method removed since `v4.8.0` (`verify_token()` was
`private static`, which `VERSIONING.md` excludes), min WP/PHP unchanged, slug unchanged.
The rule was applied faithfully both times; only the facts about #350 changed.

**⚠ Known inconsistency on `main` as of this writing.** #391 rolled the version string back but
deliberately deferred the rationale, so `docs/release-status.md` still carries a
**`## Why 5.0.0 and not 4.9.0`** section built on the false premise, directly contradicting the
`4.9.0` in its own version bullets a few lines above. #389 rewrites it but is currently
**DIRTY**; #390, which had the best rewrite, is closed. Until one lands, do not read that
section as authority for anything — see #392.

**Upgrade artifact worth knowing** (caught in #389): any dev/staging site that activated a build
from the `5.0.0` window has `wp_sudo_db_version` stamped `5.0.0`. `Upgrader::maybe_upgrade()`
returns early whenever the stored version is `>=` `WP_SUDO_VERSION`, so that stamp is never
rewritten and a future routine at `4.9.x` — or at a real `5.0.0` — would be **skipped** on
exactly those sites. Nothing is skipped today (the highest `Upgrader::UPGRADES` entry is
`4.0.0`), and it is deliberately not fixed in production code; clear it by hand where it matters.

Counts (tests, LOC, hooks) move constantly; `docs/current-metrics.md` is the source of truth
and `composer verify:metrics` is the gate.

Main is at `ec0e0ec` (#391, the 4.9.0 rollback). Since this block was first written it has taken #369, #374, #372, #350 (#322 v1), #381, #375, #382, #380, and #391.

---

## ⇢⇢ RESUME HERE (updated 2026-07-27 — design-review panel + decisions + #322 lane)

**This block supersedes the older "⇢ RESUME HERE" below.** In particular it **overrides that block's #322 mitigation** ("add a `replay_mode: none` … method-independent"): the six-model panel proved a per-class `replay_mode` denylist **fails open** and misses GET replay — see the decided fix below.

**All merged to `main` this session:** #334 (this handoff's prior RESUME block), **#337** (panel synthesis `.planning/core-gate-unit1-review-synthesis.md` + `llm-lies-log #39` + rewritten `.planning/322-stash-confused-deputy-design-brief.md`), **#338** (headline caveat in proposal §1 + spec §1). #320 closed (split approved); milestone #4 created; #306/#307 relocated; decision/finding comments on #315/#308/#310/#319/#316/#303/#302/#307.

**Decisions taken (all durable):**
1. **#320 split — approved, closed.** v1 = recent-auth primitive + provenance-blind package-write gate on browser/cookie-auth paths + identity pivots. #306/#307 → deferred milestone #4. #302 stays v1.
2. **Unit-1 shape = B (per-action step-up).** Drop the reusable recent-auth *window* for consequential actions; each is its own challenge + one-time proof-bound intent token. Closes #308/#315 and most of #310/#319 by construction. Shape A (windowed) drafted and rejected. Draft deltas: scratchpad `unit1-shapeA/B-*.md` (this session).
3. **#322 = "Both"** — ship global fail-closed in v1; origin-bound replay is a *later* enhancement.
4. **Cron/auto-update = caveat the headline** (landed #338). WordPress has **no working package signing** (verified vs `wordpress-develop` trunk: `wp_trusted_keys()` empty since 2021-04-01, `Core_Upgrader` passes `check_signatures=false`, soft-fail default — `llm-lies-log #39`). #307 provenance is the only real control; compensating primitives listed in the synthesis doc.
5. **#310 fix = promote the cache-bypass `$wpdb` read to REQUIRED** (defeats the cache-poison forgery regardless of salt); wp-config salts = defense-in-depth, not a hard precondition.

**Panel findings** (verified; full text in `.planning/core-gate-unit1-review-synthesis.md`, U-1..U-11) — key: U-4 `add_user_to_blog`/`grant_super_admin` **already have veto filters** (no core patch); `switch_theme`/`wp_delete_user`/self-email admin path **do** need core patches; U-5 every veto seam needs a no-actor/self-heal carve-out; U-6 #316 is a doc contradiction (§4.1 vs §12); U-7 needs a §7 confirmation-page content contract; U-8 REST token-redemption path; U-9 #319 = issue-then-confirm + previous-proof slot + logged-in-cookie paths.

**Core-gate next actionable (both shape-independent):**
- **Unit 1a (land-now):** #303 + #316 (veto seams + U-4 inventory + U-5 doctrine), #319 (U-9), #310 (U-3 required cache-bypass). One spec-edit PR.
- **Unit 1b (Shape B rewrite):** replace spec §4.2 window with per-action step-up; §5.1 no consequential auto-replay + bulk-as-single-digest; §7 content contract; §8/§9 REST redemption + (headline already caveated). Folds #315 + #308.
- **Cross-lane tie:** the lockout DoS escape hatch (spec §4.2, file-based) = the SAME gap as plugin **#280** (no in-band lockout clear). Share a solution.

**#322 lane — IN PROGRESS (I was driving it):**
- Worktree `.claude/worktrees/sudo-322-stash`, branch **`fix/322-stash-confused-deputy`**, WIP commit **`6093f05`** pushed (no PR yet — **intentionally RED**).
- Two failing unit tests in `tests/Unit/ChallengeTest.php` reproduce the vuln at `build_replay_response_data()`: `test_gated_post_stash_does_not_auto_replay` (POST auto-replay) and `test_gated_get_stash_does_not_redirect_to_action_url` (GET redirect-to-action-URL).
- **Green-step plan (designed, not yet applied):**
  1. `build_replay_response_data()` (`class-challenge.php:1022`): after consuming the stash, **always** take a fail-closed redirect — never return `replay=true`, never redirect to `$safe_url` (the stashed action URL). Redirect to `return_url` (same-host validated) else the **neutral `$fallback_url`** (dashboard) with the `BLOCKED_REPLAY` notice (or `REDACTED` if `redacted_fields_omitted`). **The redirect fallback must be `$fallback_url`, never `$safe_url`** (else a GET stash with no `return_url` still hits the action URL). Fire `wp_sudo_action_replay_blocked` instead of `wp_sudo_action_replayed`. This one chokepoint covers all 3 callers (`replay_stash`, `render_resume_page`, `complete_active_session_request`).
  2. Drop `success_url`/`reject_url` from the `auth.app_password` stash allowlist (`class-action-registry.php:484`).
  3. **Tests to UPDATE deliberately** (they encode old replay): `test_replay_success_omits_remaining` (:634), `test_handle_ajax_2fa_replays_stash_on_success` (:1438), `test_render_page_resumes_when_session_is_already_active` (:1924). `test_redacted_secret_stash_...` (:1511) and `test_blocked_post_replay_...` (:1559) should still pass — verify.
  4. **E2E (needs wp-env):** `challenge.spec.ts` **CHAL-01** (asserts GET `plugin.activate` replays → must assert action NOT performed, neutral redirect) and **CHAL-04** (active-session resume). Wire into `e2e.yml`.
  5. Full `composer test` + `analyse` + `lint`; pre-commit reviewer agent; open the #322 PR (green commit on top of `6093f05`).
- **Coupling correction:** #322 does **NOT** share #279's key — the clone shares the login-session verifier, so #322's later origin-binding needs a *pre-challenge browser-instance secret*, distinct machinery. They can proceed independently.

**Plugin lane (parallel, user launches separate sessions):** ~~kickoff prompts drafted this session (in chat) — **Session 1 = #278 then #279**, **Session 2 = #280**.~~ **DONE — see the newest RESUME block at the top of this file.** #278/#279 shipped as #348, #280 as #343; all three issues closed. Follow-ups #354/#355 remain open.

---

Main was at `2c3b16c` when this block was written (it is `3ebd2e6` now — see the newest block at the top). A fresh session picking up the core-gate lane can resume from here alone. (Earlier session detail is in "Done this session" further down; the **Latest** section below has the core-gate design-review record.)

## ⇢ RESUME HERE — process/CI hygiene + editor-UX lane (2026-07-26)

**A separate lane from the core-gate work above.** No file overlap: this touched the
in-editor indicator, `AGENTS.md`/`CLAUDE.md`, `bin/`, `.github/workflows/`, and the
visual baselines. Read this only if you are picking up that lane.

**Merged:** **#317** — the in-editor header indicator's state moved from a colour chip
to the padlock glyph (closed padlock inactive / open active / warning in the final
60 s), with the green chip returning *only* under core's "Show button text labels"
preference, where core replaces our icon with its own `check` and suppresses the
tooltip, leaving colour as the only channel. E2E total 94.

**Open PRs, all independent of each other:**

| PR | What | State |
|---|---|---|
| #332 | Prose-discipline rules (`AGENTS.md`) + `docs/upstream-sources.md` citation registry + `bin/verify-sources.sh` + nightly workflow; also a stale POT fix and `composer verify:i18n` wired into CI for the first time | auto-merge armed |
| #336 | `docs/llm-lies-log.md` #40-#48 — this session's confabulations | auto-merge armed |
| #339 | `AGENTS.md` becomes canonical for Verification Requirements; closes #333 | open |
| #342 | VISN-01/02 baselines regenerated on Linux + an `update_snapshots` dispatch mode + `e2e-visual.yml` stops swallowing its exit code | open |
| #344 | Unbreaks `docs-lint`, red on `main` since #338 | open |

**Open issues:** **#341** — VISN-03/04 fail with a `toHaveScreenshot` timeout that
survives `--update-snapshots`; their baselines are byte-identical after regeneration,
so this is a test bug, not drift. Traces are in the run artifacts. The obvious cause
(`page.clock.install()`) was tested and refuted.

**The theme of the lane, and the thing worth carrying forward: three separate checks
had quietly stopped meaning anything.**

1. `e2e-visual.yml` marked its only test step `continue-on-error`, so it reported
   success while four baselines failed on every nightly run.
2. `composer verify:i18n` existed but was wired into no workflow at all — which is how
   a stale POT reached `main` in #317.
3. `docs-lint` had been red on `main` for two commits with nobody noticing, because it
   is not a required check.

None of these were caught by anyone reading code. Each was found by running the thing
and looking at the output.

**Next actionable, in order:**

1. **Land the five PRs.** They are blocked only by the merge race with the core-gate
   lane — every push to `main` puts them `BEHIND`. If both lanes are active, coordinate
   or merge them from the UI.
2. **Citation migration (blocked on #332).** `bin/verify-sources.sh` warns that 8 files
   cite upstream code outside the registry. Five are
   `raw.githubusercontent.com/snicco/fortress/**beta**/…` — a *moving branch*, so the
   highest drift risk in the repo. Deliberately left as a WARN backlog rather than a
   red build on day one. Do this on a branch stacked on `chore/prose-discipline`, not a
   fork of the script.
3. **#341** — needs a trace read, not a guess.

**Two traps this lane hit that will recur:**

- **Visual baselines must be generated on the Linux runner.** Regenerating on macOS
  produces PNGs that can never match: font metrics differ by a few pixels, and
  `threshold` is a per-pixel colour tolerance, not a pixel-count budget. #342 adds a
  dispatch mode for this. The trap has now caught the repo twice.
- **Regenerate the POT, never hand-patch its line references.** `bin/make-pot.sh`
  exists. Hand-patching five refs in #317 missed a sixth and shipped a stale POT.

---

## ⇢ RESUME HERE (2026-07-26, end of the docs/design-review session)

**Concurrent sessions — DO NOT disturb their worktrees; each merges its own branch:**
- `Sudo-288-glyphs` → `feat/288-glyph-state-vocabulary` — **editor padlock UX** (glyph state vocabulary). CI is running on `5790335`; 2 shells live there. This is a follow-up to the merged #288 base feature.
- `Sudo-deps-hardening` → `fix/npm-lockfile-security` — **CI/Dependabot security** (the GitHub-flagged npm-lockfile vulns).
- (This session used only ephemeral worktrees under `scratchpad/`, all removed.)

**Core-gate design review is DONE (Fable+Opus+Codex); the proposal is NOT ready-to-land.** All findings are GitHub issues, label `core-gate-review`, organized into two milestones + a decision anchor. See spec **§12** for the summary and memory [[sudo-core-gate-direction]].

**Triage (applied — Claude+Codex reconciled):**
- **Milestone "WP Sudo security"** (live plugin): **#322** (P1 stash confused-deputy — App-Password `success_url` exfil; priority: high), #278 (medium), #280 (medium), #279 (low). Implement #278/#279/#280 **independently** (not one PR). **#322 fast mitigation is method-INDEPENDENT** — `stash_no_replay`/`post_mode:none` is POST-only (verified: `class-request-stash.php:410` returns before the policy for GET), so add a `replay_mode: none` enforced before the GET/POST branch + stop `render_resume_page()` auto-executing on active sudo + drop `success_url` from persisted data. Keep #322 open until browser-binding + confirmation + proof-bound token. Code fix → design-review + TDD; ship in the next release or a focused security point-release.
- **Milestone "core-gate proposal — v1 readiness":** **#320 DECIDED & closed (2026-07-26) — split APPROVED.** v1 = the recent-auth primitive + **provenance-blind gating of the package write on browser/cookie-auth (direct-code) paths** + identity pivots. **#306/#307 moved out** to the new milestone **"core-gate: provenance/automation policy (deferred project)" (#4)** — #307 is the auto-updater provenance ALLOW branch (it *is* the §3-vs-§9 contradiction, so deferring it takes the contradiction with it); #306 is the automation-caller over-block. **#302 (core update) STAYS in v1** (browser-initiated direct-code effect). See the #320 decision comment for the full rationale. **Next actionable = the mechanism invariants** **#315, #303+#316, #308, #310, #319** (all blockers, not polish); then v1 closure **#302**, **#304**, + the #311 splits **#326–#329** (App-Password shared sink, option-write bypasses, pluggable session managers, fail-closed recovery); compat **#305, #309**. #311 remains the medium/low tracker. **Follow-on doc work created by #320:** reshape proposal **§8/§9** + actor-class policy scope down to v1's narrowed surface (automation/provenance narrative migrates to the #4 project's docs); coordinate with the in-flight local `docs/proposal-tone-pass` branch and land before the deferred Fable pass.
- **#315 (proposal) ≠ #322 (plugin)** though they share the invariant: *no proof authorizes an attacker-selected transaction without browser + action/target binding + deliberate confirmation.* Plugin (Strict cookies) proves the token/UX model; core (Lax + shared nonces) needs its own threat analysis.

**Also this session:** architectural material moved out of the proposal → `core-gate-architectural-context.md` (#312); Fable prose pass (#299); §12 known-gaps + Codex framing correction (#313/#318/#321); doc overclaims qualified in `security-model.md` + `FAQ.md` (#323). Auto-archive-after-PR-merge desktop setting caused the earlier session/worktree teardown — **Dan disabled it 2026-07-26** ([[claude-code-autoarchive-pr-merge-hazard]]); don't batch-auto-merge if it's ever re-enabled.

## Latest — continued session (core-gate docs polish + design review)

- **Fable docs pass (prose) — merged (#299, `7393fd8`).** Register/clarity pass on proposal, spec, PoC, attack-tree; verified substance-clean (headings, links, code, numbers byte-identical). README excluded (pointer index).
- **Architectural material relocated out of the proposal — merged (#312, `3e723e3`).** The Peralty/de Valk/Coords split-vs-refactor debate + WP-Next kernel framing moved to a **new strategy companion `docs/core-gate-architectural-context.md`**; proposal §7 is now a short pointer (no renumber). The **WP 7.0 Connectors credential-write example moved DOWN into the closure (§3)** as a concrete in-scope threat (kept, with citation).
- **Two-model design review of the proposal (Fable + Opus) — DONE.** Not fatal; both models validated the shape. Findings verified against source (doc-anchored claims by grep; WP-internals by knowledge, two flagged for a live-core re-check: `Core_Upgrader` call graph, `wp_salt()` options-fallback). Captured as:
  - **GitHub issues #302–#310** (nine HIGH, individually actionable) + **#311** (medium/low tracker), label `core-gate-review`.
  - **Spec §12 "Reviewer findings / known gaps" — merged (#313, `5867a1b`)** summarizing the HIGH gaps + issue links.
  - HIGH gaps: core-update ungated (#302); `do_action()` pre-op adapters can't block (#303); `wp_set_password()` reset-path lockout (#304); SSO/passkey mandatory-password lockout (#305); default-on App-Password/automation back-compat regression (#306); auto-updater provenance primitive (#307); flat-freshness cross-authorization (#308); `map_meta_cap` recursion/boundary (#309); AUTH_SALT-in-options HMAC forgery (#310). Most mechanical or already-levered; a handful are bounded policy decisions.
  - **Fable verdict:** strong at both jobs — prose pass substance-clean; on the design critique it was the broader of the two (caught the biggest omission, core-update), Opus contributed 3 distinct high-value items (SSO/passkey, AUTH_SALT, REST silent-200). Two-model run was worth it; neither a superset.
- **Codex design pass — DONE (verified against `origin/main`@`4ba9a6f`; WP Sudo unit suite green 1,151/3,396).** Two **P1**s + one medium + a strategic scoping call, all verified against the PoC/spec and folded into **spec §12** (items 10–12 + strategic note):
  - **#315 (P1) — the proof cookie is an ambient bearer.** Broader than auto-replay: the proof cookie is `SameSite=Lax` and the session token is shared, so a pre-reauth clone harvests a valid activation nonce, then navigates the *victim's* browser to a crafted action GET after reauth — victim supplies the fresh proof, attacker the nonce, gate passes. Breaks the "pre-reauth copied cookie cannot act" headline. Fix: no auto-replay for any action; canonical confirm page + a proof-bound intent token (POST), **not** a WP nonce.
  - **#316 (P1) — self-email gate on observational `do_action`** (`personal_options_update` can't veto) + a multisite ordering bug (signup row written before `wp_update_user()`).
  - **#319 (medium) — proof issuance not atomic with cookie delivery** (returns success even when the cookie isn't sent → reauth loop) + misses `PLUGINS_COOKIE_PATH`/`SITECOOKIEPATH`.
  - **#320 (strategic) — split the proposal:** v1 recent-auth primitive + browser/cookie direct-code effects + identity pivots; a *separate* provenance/policy project for auto-updates/API-creds/CLI/cron/programmatic (that breadth drives #302/#306/#307).
  - **§12 framing corrected per Codex:** dropped "none is fatal / shape validated" → "validated the problem framing and a candidate mechanism, **not** a ready-to-land security design" (P1s stand).
  - **#315 verified against the SHIPPING plugin (Codex final answer, independently confirmed by reading the code):** the request stash is bound to **`user_id`, not the originating browser** (`class-request-stash.php:148`/`:201`), so a cloned session can plant a stash and the victim's reauth replays it (`class-challenge.php:661`/`:944`). Sharpest exploit: the `auth.app_password` rule allowlists **`success_url`** (`class-action-registry.php:484`) → mint-and-exfil a durable credential. Sudo's cookie is **`SameSite=Strict`** (`class-sudo-session.php:891`) so #315's *direct* Lax cross-site-GET variant does **not** transfer; the **planted-stash** vector does. The **editor modal is unaffected** (owner-scoped JS closure, no server stash). Verdict (both Codex + me): **fatal to blind auto-replay as proof-of-intent; NOT fatal to Sudo mode or the modal.**
    - **Filed plugin P1 [#322]** (`security`/`bug`/`priority: high`) — shipping stash confused-deputy + the App-Password exfil route + fix directions (reauth-then-resubmit; browser-bound intent token). **Code fix deferred to design-review + TDD.**
    - **Corrected two doc overclaims (#323, merged):** `security-model.md` ("blocks downstream even when XSS succeeds" → qualified to the stolen-*session* case, not live same-origin XSS at reauth) and `FAQ.md` ("cannot perform any covered op" → "cannot **silently**" + two honest caveats).

### #288 padlock UX — status + an incident to be aware of
- **#288 base feature (padlock shows sudo state) already merged** (`776ccf1`). The **follow-up is `feat/288-glyph-state-vocabulary`**, in its own worktree at **`/Users/dan-knauss/Code/Sudo-288-glyphs`**.
- **That worktree's work is INTACT and staged (uncommitted):** the #288 design brief, `admin/css/wp-sudo-editor-indicator.css`, `admin/js/wp-sudo-session-indicator.js`, `CHANGELOG.md`, `readme.md`/`readme.txt`, `languages/wp-sudo.pot`, `docs/current-metrics.md`, the e2e specs, and a new `editor-indicator-inactive` visual snapshot. **To resume: open a new session in `/Users/dan-knauss/Code/Sudo-288-glyphs` and continue** (commit → PR).
- **Incident — ROOT CAUSE FOUND (memory [[claude-code-autoarchive-pr-merge-hazard]]).** The Claude Code **desktop** setting *"Auto-archive desktop sessions when the associated PR is merged or closed"* **removes the merged branch's worktree and deletes its branch** (unpushed work kept on disk, but the running session is killed). Batch-auto-merging the core-gate docs PRs this session was the trigger the app acted on — it archived a sibling session and tore down its worktree. **No work was lost** (files survive; changes were `git add`-ed). **Dan disabled the setting on 2026-07-26.** Lesson: do **not** batch-auto-merge PRs when concurrent sessions are live; if the setting is ever re-enabled, treat every `gh pr merge` as potentially deleting the merged branch's worktree/session. Not a `settings.json` option (verified) — desktop-app UI only.

## Done this session (in order)

1. **PR #286 — spec Acknowledgments fix (merged `f6affd5`).** Rebased `fix/spec-acknowledgments` onto main, resolved the `llm-lies-log.md` conflict by **renumbering its entry #35 → #36** (kept #285's #35). Main's core-gate spec Acknowledgments are now correct (Blackbourn = action-gating concept; Nash = roles/permissions lockdown; Alkan = feedback/Fortress/why-core/SIEM; "gate the effect, not the field" left UNCREDITED; Timothy Jacobs + JJJ/Felt removed — see memory `sudo-contributor-attributions`).

2. **PR #287 — core-docs consolidation (merged `e55351b`).** Executed `.planning/core-docs-consolidation-plan.md`, docs-only, net −621 lines:
   - Merged the security pitch into `core-action-gate-proposal.md`, rewritten **gate-first** (registry demoted to explicit optional companion; fixes the old "proposal laggard" + registry-vs-gate phasing tension). Pitch deleted.
   - Folded the registry-vs-Abilities decision into **spec §4.1.1** (incl. the verified non-enforcing `wp_before_execute_ability` seam + collision contract); preserved the flat-vs-nested `consequence`-block delta.
   - Reconciled the spec's Phase-1/Phase-2 framing so the registry is no longer a gate prerequisite (§3.4, §4.1 heading, change-list).
   - Archived `core-actions-registry-vs-abilities-decision.md`, `core-sudo-gate-vs-demo-reconciliation.md`, `password-change-reauth-research.md` (with `../` link fixes + archive index).
   - Added a plain-language on-ramp to the PoC. Repointed all inbound links.
   - **Judgment call: kept `connectors-api-reference.md` in place** (live WP-7.0 reference cited by 4 docs, not a proposal-narrative artifact — the plan sanctioned this).
   - **Known-untidy (harmless):** the `docs-lint.yml` allowlist still lists `docs/password-change-reauth-research.md` at its old path. Left in to keep #287 strictly docs-only (per Dan's choice); the archived file is covered by `EXCLUDED_PATHS` anyway, so it just matches nothing. Remove it whenever a non-docs commit is next touching that workflow.

3. **`proposal-notes.md` updated** (local, git-excluded, never committed). Fixed the two stale spots: union-first framing → **standalone-now / union-deferred**; #37593/#39174 → **multisite terminology only, cite #20140**. Its unique #20140 advocacy/venue/ticket-housekeeping playbook is intact.

4. **Direction question (Dan) — confirmed.** Effect-**chokepoint** enforcement is still the proposed direction; the docs explicitly *reject* the deeper primitives (the `map_meta_cap` capability layer; the Abilities execution hook — observational, not a gate). The only "deeper" seam endorsed is conditional/future (WP Next's PSR-15 typed-effect-event kernel → gate becomes middleware). Registry stays an optional naming companion; session side settled as **B′**.

5. **Issue #288 filed, designed, and staged for a claude-playwright session (NOT yet implemented).** In-editor session indicator: **drop the `shield` glyph; reuse the admin-bar `dashicons-unlock` padlock chip** — green `#2e7d32` active / red `#c62828` in the final 60 s (`remaining <= 60`), white glyph, reverting to no-chip when inactive. Closes the deferred #262 screenshot gap (#284). Labeled **`needs-playwright`** + `enhancement`.
   - **A prior session's DOM-verified design brief is now on `main`:** `.planning/288-editor-padlock-indicator-design-brief.md` (committed via PR #292). It **supersedes the vaguer contingencies** in the issue: use the stable `aria-controls="wp-sudo-session-indicator:wp-sudo-session-indicator"` selector (no `aria-label` fallback), and carry state via a `document.body` class (`wp-sudo-editor-session-active` / `-expiring`) toggled from a `useEffect` on a 3-value state string (transitions only) — **not** the button's own class (Gutenberg re-renders it). CSS in a new `admin/css/wp-sudo-editor-indicator.css` enqueued in `Plugin::enqueue_editor_reauth()`. Decision: **omit** the optional header `M:SS` (per-second churn + snapshot instability). 5 reviewer questions in the brief still to settle (expiring-state accessible name, WCAG 1.4.1 colour-only, contrast, `!important`, `M:SS` scope).
   - Issue #288 carries two comments: the implementation handoff brief and a reconciliation pointing at the committed brief.
   - **Status: designed, NOT built.** Needs the `playwright` MCP server (`mcp__playwright__*`) for browser inspection plus a working `wp-env` for the E2E/visual-regression suite. **There is no `claude-playwright` launcher** — that wrapper was removed (the old `/Users/danknauss/bin/...` path is dead). Do the work in any session with the `playwright` MCP connected + `wp-env` running (boot with `WP_ENV_TESTS_PORT=8891` if 8890 is held). Kickoff: *"Implement #288 — read the committed brief + the issue's two comments, follow the brief (aria-controls selector, body-class approach, admin-bar tokens, omit M:SS), TDD + visual snapshots, wire any new spec into `e2e.yml`, reviewer gate, close #284."* Everything resolves from `main` + the issue.

6. **Planning docs committed to `main` for the record** (PRs #289–#292): this handoff (#289), the executed consolidation plan (#290), the #240 + #246 design briefs (#291), and the #288 brief (#292). `main` HEAD after this session's doc work: `17b26b2` (before this handoff update). Working tree was clean.

## Durable plans & decisions
- **`.planning/core-docs-consolidation-plan.md`** — now **executed** via PR #287.
- **Memory `sudo-core-gate-direction.md`** — settled direction: **gate-first**; effect-chokepoint seams (`install_package`/`activate_plugin`/`wp_edit_theme_plugin_file`/`switch_theme`/`wp_update_user`); **B′** session design (per-verifier, HMAC binds the proof hash, no rotation); registry = optional companion. (Consolidation now landed.)

## Open issues (tracked, not started)
- **#288** — in-editor padlock indicator (needs-playwright; brief posted). ← next actionable UI item.
- #273 — release-environment matrix for 4.8.0 REST changes.
- #278 (HMAC-sign the plugin's session assurance meta), #279 (per-verifier proof key), #280 (lockout-as-DoS) — the **B′ lessons flowing back into `class-sudo-session.php`**.
- #284 — the deferred #262 screenshots (folded into #288's acceptance).

## consequential-actions (CA) repo — way forward (unchanged)
`dknauss/consequential-actions` stays THE Trac #20140 gate demonstrator. **Sync its catalog to the reconciled closure** (it carries only the account pivots — add the code effects: editor / `install_package` / `activate`, plus `siteurl`, App Password both-paths, registration-default-role) and align enforcement with B′. Not a stale registry PoC.

## Doc alignment status — now clean
All core-proposal docs aligned gate-first: proposal (merged pitch, gate-first, registry optional), spec (full closure + B′ + folded Abilities decision §4.1.1), PoC (+ plain-language intro), attack-tree, core-auth, abilities-assessment, README index. The old "proposal laggard" is resolved. Archived: abilities-decision, vs-demo, password-change-research, proposal-notes (local only).

## Doc editorial pass — in flight + Fable plan
- **`docs/proposal-tone-pass`** (LOCAL branch `0feaa4d`, **not pushed**): register/clarity pass on `core-action-gate-proposal.md` — "The ask"→"Summary"; dropped authorial asides ("Be precise…", "State it honestly:", "To avoid overstating it:"); "iff"→"if and only if"; dropped set-notation (∪) for a plain two-class sentence; removed orphaned **B′** review-shorthand; de-densified the worst em-dash/long sentences (§2, §4, §7 Peralty, §10). Under maintainer review — may get more edits; not yet a PR. **The #288 UX WIP is parked on top of this branch in the shared working tree** — move it to a `feat/288-*` branch before finalizing this one.
- **PR #295 (merged `72a19fd`)**: spec trim — removed spec §11 (dup of proposal §9), §12→§11 renumber, moved contributor credits to the readmes (added **Calvin Alkan**; Blackbourn + Nash already there), scrubbed **B′** from the spec §4.2 + `docs/README.md`. (B′ still survives in the **proposal**, fixed on the tone-pass branch above.)
- **Fable comprehensive docs pass — DEFERRED by design; run it AFTER content lock.** Trigger: once the tone-pass branch **and** any remaining maintainer content edits land on `main` (core-gate doc family stable). Then run **one** Fable pass over the settled set (proposal, spec, PoC, attack-tree, README) so the voice is consistent across them. **Guardrails (non-negotiable):** scope to register/clarity/flow ONLY — **no changing claims, code, citations, or numbers**; run a Fable subagent in an **isolated worktree**; **diff-verify no technical assertion moved** before merge; land as one reviewable PR. It can subsume the manual em-dash/tone work. Kick off with "run the Fable docs pass" when ready.
