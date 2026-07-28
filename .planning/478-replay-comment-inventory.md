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

**`includes/class-sudo-session.php` — 10 matches, 0 stale.** Every one is about
**replay attack** resistance, not request replay: a *cookie* replayed under a
different login session, cross-browser 2FA replay, an HMAC that recomputes
differently on a replayed token. All **current**, all must survive untouched.
Two more in the same file (`grace-eligible`, `no eligibility check`) match on
`eligib` and concern the grace window and role-agnosticism.

Same shape: `includes/class-site-health.php:533` (`grace-eligible proofs`) and the
`bridges/wp-sudo-wsal-sensor.php` pair, which correctly describe the
`action_replayed` → `replay_refused` event-ID split for alerting.

**Rule for this PR: a match is only in scope if it describes the *request-replay*
mechanism.**

## Per-file classification

### `includes/class-challenge.php` — 52 claims

| Lines | Class | Note |
|---|---|---|
| 1045–1051, 1285–1291, 1296–1299, 1339–1372, 1389–1395, 1113, 1327 | current | Already correct, several emphatically so: *"Do NOT read its presence as evidence that replay still happens."* |
| 1303–1318, 1369–1370 | historical | The v2 eligibility design, explicitly past-tense |
| **314–315** | **stale** | *"most responses do not replay"* — implies some do |
| **730, 946** | **stale** | *"bound replay is eligible"* ×2, three lines above the call whose own docblock says nothing is replayed |
| **961–963, 980, 1003** | **stale** | `replay_stash()` docblock still documents the GET-redirect / POST-form engine; *"Replay a still-valid stash when possible"* |
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
| **901** | **stale** | *"The JS replay mechanism submits all …"* — that mechanism is deleted |
| **389, 578, 802, 845, 880, 958, 1001** | **stale (minor)** | *"the replay is refused"*, *"safe to store and replay"*, *"Resolve replay policy"*, *"not replayed"* |
| 103, 111, 135, 267, 271, 281–289, 349, 360, 396, 424–426, 927 | historical / current | Describe why a value is captured or redacted; several read correctly as the *reason* replay was abandoned |

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
| **24, 338, 340** | **stale** | *"Fired when a stashed request is replayed after successful reauthentication."* `wp_sudo_action_replayed` has **no producer** in `includes/`, `bridges/`, `mu-plugin/` or `wp-sudo.php`. `readme.txt` calls it retained but dormant |
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
| `bridges/wp-sudo-wsal-sensor.php` | 2 | current — event-ID split |
| `includes/class-dashboard-widget.php` | 1 | current — CSS comment on the refusal colour |
| `includes/class-site-health.php` | 1 | false friend — `grace-eligible` |

## Totals

| Class | Claims |
|---|---:|
| **stale — to fix** | **~78** |
| current | ~55 |
| historical | ~19 |
| false friend | ~14 |
| | **166** |

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
same commit.

## Acceptance

Per #478. The load-bearing one: **no comment states or implies that automatic
replay exists, is conditional, or can be restored** — and no false friend is
touched while achieving it.
