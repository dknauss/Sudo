# #478 — in-code replay-language inventory

Inventory **before** editing. Nothing in this commit changes a comment; it
establishes what has to change and, just as importantly, what must not.

Taken at `origin/main` = `5e0cd4e`.

## Method

```bash
grep -rniE 'replay|resum|re-dispatch|redispatch|eligib' includes admin/js bridges mu-plugin
```

Case-insensitive, **unfiltered**. Two deliberate choices:

- **`eligib` is in the pattern.** The retired design's vocabulary was "replay
  eligibility". Several stale claims use *eligible* without using *replay*, and a
  pattern of `replay` alone misses them.
- **Output is not filtered on `never` / `no longer` / `removed`.** A *conditional*
  claim — "a stash is **never** replayed **unless** …" — contains those words and
  is precisely the defect. Filtering them out is how two of #476's five review
  rounds missed sites they had been asked to find.

| | Count |
|---|---:|
| Total matches | 256 |
| On comment lines (`//`, `*`, `/*`) — candidate claims | **166** |
| Code identifiers only (method/constant/property names) | 90 |

The 90 identifier matches are names like `build_replay_response_data()`,
`REPLAY_BLOCKED_NO_ALLOWLIST`, `$post_replay_blocked`. **Renaming is out of scope**
— it is a behaviour-adjacent refactor, not a comment fix, and the names remain
accurate descriptions of what the code does (compute a replay *refusal*).

## Classification key

- **current** — accurate against shipped behaviour. Leave.
- **stale** — describes the retired design as live, or implies replay is
  conditional/restorable. Fix.
- **historical** — explicitly framed as "an earlier design did X". Leave; sharpen
  only where the framing is ambiguous.
- **false friend** — matches the pattern but is about something else entirely.
  **Must not be touched.** See below.

## False friends — the trap in this sweep

`replay` in this codebase means two unrelated things. A careless sweep would
"correct" security-critical comments into nonsense.

**`includes/class-sudo-session.php` — 10 matches, 0 stale, all leave-alone.** Nine
are **replay attack** resistance, not request replay: a *cookie* replayed under a
different login session, cross-browser 2FA replay, an HMAC that recomputes
differently on a replayed token. Two of those ten match only via `eligib`
(`no eligibility check` L536, `grace-eligible` L1022) and concern role-agnosticism
and the grace window.

The tenth, **L1015**, is leave-alone for a *different* reason and the distinction
matters: *"another concurrent browser's proof may … still be replaying an
in-flight gated form under `is_within_grace()`"* is neither replay-attack
semantics nor request replay — it is loose vocabulary for the user's own
in-flight submit. Correct to leave; do **not** justify it with the replay-attack
rationale.

Same shape: `includes/class-site-health.php:533` (`grace-eligible proofs`).

**`bridges/wp-sudo-wsal-sensor.php` L227–228 is NOT a false friend** — corrected
after review. *"Distinct ID so alerting can subscribe to a refused replay
separately from **a successful one**"* uses the request-replay sense and
presupposes a successful-replay event that has no producer. In scope,
**current-but-ambiguous**: sharpen rather than skip.

`includes/class-dashboard-widget.php` L1207–1208 is likewise ambiguous rather
than clean — the CSS comment presupposes both outcomes occur. Low severity.

**Rule for this PR: a match is only in scope if it describes the *request-replay*
mechanism.**

## Per-file classification

### `includes/class-challenge.php` — 52 claims

| Lines | Class | Note |
|---|---|---|
| **11** | current | File header — *"Automatic replay was removed in 4.9.0 (#322). Nothing here reconstructs or…"*. Listed explicitly because `class-request-stash.php`'s header **is** stale; do not sweep headers by analogy |
| 1045–1051, 1285–1291, 1296–1299, 1339–1372, 1389–1395, 1113, 1327 | current | Already correct, several emphatically so: *"Do NOT read its presence as evidence that replay still happens."* |
| 1303–1318, 1369–1370 | historical | The v2 eligibility design, explicitly past-tense |
| **314–315** | **stale** | *"most responses do not replay"* — implies some do |
| **730, 946** | **stale** | *"bound replay is eligible"* ×2, three lines above the call whose own docblock says nothing is replayed |
| **961–965, 980, 1003** | **stale** | `replay_stash()` docblock still documents the GET-redirect / POST-form engine — the block runs to **L965** (*"Builds and submits a hidden form for POST requests"*); *"Replay a still-valid stash when possible"* |
| **1232** | **stale** | *"Cleared on BOTH the replay and fail-closed paths"* — there is no replay path |
| **1401–1402** | **stale** | *"#322 v2 … **restores** seamless auto-replay"* — asserts restoration inside the method documented as the guarantee |
| 80, 88–89, 158, 175, 1180 | stale (minor) | *"not stored for replay"*, *"prevented automatic POST replay"* — per-rule taxonomy implying others replay |
| 272, 717, 1169, 1220, 1259 | current | Identifier references |

### `includes/class-request-stash.php` — 32 claims

| Lines | Class | Note |
|---|---|---|
| **3, 18–21** | **stale** | File header: *"serialize and **replay** intercepted requests"* … *"retrieved and replayed — via redirect for GET, or a self-submitting form"*. The file's stated purpose is the removed behaviour |
| **250** | **stale** | *"A replay may only run when the confirmation described the WHOLE effect"* — conditional claim |
| **468–469** | **stale** | *"not eligible for bound replay"* — conditional; documents `target_complete`, which has **no reader** |
| **534** | **stale** | *"never sufficient on its own to authorize a replay"* — implies replay authorisation exists |
| **901–902** | **stale** | *"The JS replay mechanism submits all … `post_data` fields verbatim as hidden form inputs"* — that mechanism is deleted; the sentence runs into L902 |
| **389, 578, 802, 845, 880, 958, 1001** | **stale (minor)** | *"the replay is refused"*, *"safe to store and replay"*, *"Resolve replay policy"*, *"not replayed"* |
| **103** | **stale** | `TARGET_PARAMS` docblock: *"the confirmation is blank while the **full payload replays**"* |
| **271** | **stale** | `capture_target()` `@param $complete`: *"so the caller can refuse to replay more than the confirmation described"* — conditional refusal stated as live |
| **281–283** | **stale** | `capture_target()`: *"Read the source that will actually be **REPLAYED** first. A POST replay submits the stashed body…"* |
| **349** | **stale** | `capture_target()`: *"must not name one value while another **is replayed**"* |
| **424–426** | **stale** | `target_value_echoes_stored_option()`: *"the unsanitized value is **what actually replays**"* |
| **360, 927** | **stale (minor)** | *"replaying the FULL value"*; *"not sent to JS replay"* — 927 is the same claim as 901, which is already marked stale |
| 111, 135, 267, 289, 396 | historical / current | Genuinely read as the *reason* replay was abandoned, or as storage/redaction rationale that still applies |

### `admin/js/wp-sudo-challenge.js` — 16 claims

| Lines | Class | Note |
|---|---|---|
| **5, 11** | **stale** | File header documents the replay engine the body says was deleted |
| **20, 146, 152, 248, 254** | **stale** | *"Stash mode: replay the stashed request"*, *"without replay data"* — implies replay data exists |
| **295, 298, 303** | **stale** | Section banner + orphaned docblock for *"Replaying your action…"*, a string that no longer exists (`handleReplay()` assigns `returningToPage`) |
| 324–325, 347, 354–366 | current | Emphatically correct; 354–366 explains the gadget's deliberate removal |

### `admin/js/wp-sudo-editor-reauth.js` — 17 claims

All **current**. This file describes the block-editor in-tab re-dispatch, which
genuinely exists and is owner-scoped. It is the one live re-dispatch path and the
comments state its bounds accurately (`isOwner`, single-flight, non-owner
rejections left rejected). **Do not "correct" this file.**

### `includes/class-gate.php` — 9 claims

| Lines | Class | Note |
|---|---|---|
| **1508–1519** | **stale — highest severity** | `Gate::evaluate_diagnostic_request()`: *"Since #322 replay **is** authorised by a conjunction this method cannot evaluate"*, naming the binding cookie and `Sec-Fetch-Site` as live conjuncts. Directly contradicts the corrected `docs/FAQ.md` |
| **147, 291, 1431, 2657–2659** | **stale** | *"challenge + stash/replay"*, *"cannot stash and replay the request the way `intercept_rest()` does"* — asserts `intercept_rest()` replays |

### `includes/class-event-recorder.php` — 9 claims

| Lines | Class | Note |
|---|---|---|
| **24, 338, 340** | **stale** | *"Fired when a stashed request is replayed after successful reauthentication."* `wp_sudo_action_replayed` has **no producer** in `includes/`, `bridges/`, `mu-plugin/` or `wp-sudo.php` — repo-wide the only `do_action` sites are `bin/demo-events.php` and three test files. **`CHANGELOG.md` L94** calls it retained but dormant. (Do **not** cite `readme.txt` for this: at baseline `5e0cd4e` its L218 says the opposite — *"unchanged and still fires"* — because the correction is in #476, which is unmerged.) |
| 25, 358–390 | current | The `replay_refused` counterpart is described correctly |

### `includes/class-action-registry.php` — 14 claims

| Lines | Class | Note |
|---|---|---|
| **352–355, 392, 444, 484–488, 568–590** | **stale (per-rule taxonomy)** | *"Gated profile saves are non-replayable"*, *"NOT replayable"*, *"before this rule became non-replayable"* — each true of the retired selective design, each now implying unmarked rules replay |
| 69, 849, 862, 944 | stale (minor) | *"replay-safe"*, *"replay allowlist"* in allowlist metadata docs |

### `includes/class-sudo-session.php` — 10 claims

**All false friends.** Replay-*attack* semantics. Zero in scope. See above.

### Remainder

| File | Claims | Class |
|---|---:|---|
| `includes/class-admin.php` | 2 | **stale** — *"the replayed `_wp_http_referer` POST field"* (fixed in #476, which stages this file) |
| `bridges/wp-sudo-two-factor-lifecycle-bridge.php` | 1 | **stale** — *"submission replays as a complete core profile save"* |
| `bridges/wp-sudo-wsal-sensor.php` | 2 | **in scope**, current-but-ambiguous — presupposes a successful-replay event with no producer |
| `includes/class-dashboard-widget.php` | 1 | ambiguous — CSS comment presupposes both outcomes occur |
| `includes/class-site-health.php` | 1 | false friend — `grace-eligible` |
| `bridges/wp-sudo-stream-bridge.php` | 8 (0 comment) | **no claims** — all 8 are code: `wp_sudo_action_replayed` / `wp_sudo_replay_refused` case labels, message strings, arg-count map. Nothing to edit, but listed because a file with matches must not be silently absent — and because it *consumes* the hook the Event_Recorder row calls producerless |

## Totals

| Class | Claims |
|---|---:|
| **stale — to fix** | **~85** |
| current | ~50 |
| historical | ~18 |
| false friend | ~13 |
| | **166** |

Revised after review: seven `class-request-stash.php` lines moved from a
leave-alone catch-all row into **stale**, and the WSAL sensor pair moved out of
**false friend** into scope. The comment/identifier split is heuristic — a second
pass counted 168/88 against 166/90, differing only on trailing comments. Per-file
*claim* counts are exact.

**Search space: production code only** — `includes/`, `admin/js/`, `bridges/`,
`mu-plugin/`. `tests/` is excluded and carries a further **148** comment-line
matches, including `tests/Unit/ChallengeTest.php` describing the deleted
`may_replay_bound_stash()` as live. Excluding tests is a deliberate scope call
for this issue, not an absence claim about the repository.

## Landing plan

The commit ceiling is 10 counted files / 1000 insertions, and the stale claims
cluster, so this lands as three commits, each reviewed against its own staged
tree:

1. `class-challenge.php`, `class-request-stash.php` — 84 claims, the bulk.
2. `class-gate.php`, `class-event-recorder.php`, `class-action-registry.php`,
   `wp-sudo-two-factor-lifecycle-bridge.php` — the contradictions against
   corrected public docs.
3. `admin/js/wp-sudo-challenge.js` — header, banner, orphaned docblock.

`class-admin.php` is **excluded**: #476 already stages it. `wp-sudo-editor-reauth.js`
and `class-sudo-session.php` are excluded as correct.

Every commit touching PHP must re-run `composer verify:metrics` (line counts are
tracked) and `bash bin/make-pot.sh` (the `.pot` carries line references) in the
same commit. Commit 3 is JS-only, so that instruction does not formally bind it —
run `verify:metrics` there anyway rather than reasoning about whether `admin/js`
line counts are tracked.

## Editing hazard — do not strip live rationale

`Action_Registry::stash_no_replay()` is **still live**. `Request_Stash::get_stash_policy()`
reads `post_mode => 'none'` and it genuinely controls whether the POST body is
**stored**. So the per-rule comments at L352–355, 392, 444, 484–488 and 568–590
are stale *about replay* while their storage-and-redaction rationale remains
accurate and load-bearing. Correct the replay framing; keep the reason the body
is not retained.

## Acceptance

Per #478. The load-bearing one: **no comment states or implies that automatic
replay exists, is conditional, or can be restored** — and no false friend is
touched while achieving it.
