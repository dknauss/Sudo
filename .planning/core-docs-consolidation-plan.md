# Plan: consolidate the core-proposal doc set (12 → ~6)

**Goal.** Cut the redundancy in the core-gate proposal docs (~2,700 lines across 12 files) to a tight canonical set with one narrative. The single highest-value move — **merge the pitch into the proposal** — also fixes the two open alignment problems (the proposal is the stale "laggard"; the registry-first-vs-gate-first framing is inconsistent across docs). Do it as **one reviewed PR**.

Self-contained: an executor needs only this file + the repo. Verify every external code/CVE claim against live source per `AGENTS.md` before publishing.

---

## Current inventory (docs/, lines · role)

| Doc | Lines | Disposition |
|---|---:|---|
| `core-action-gate-proposal.md` | 786 | **KEEP → becomes the merged proposal** (pitch folded in) |
| `core-sudo-gate-security-pitch.md` | 131 | **MERGE into proposal**, then delete |
| `core-sudo-gate-implementation-spec.md` | 363 | **KEEP** (+ fold in the Abilities decision) |
| `core-sudo-gate-poc-patches.md` | 390 | **KEEP** |
| `stolen-cookie-rce-attack-tree.md` | 122 | **KEEP** — the one threat/closure source |
| `wordpress-core-authentication.md` | 232 | **KEEP** — core-auth reference |
| `abilities-api-assessment.md` | 383 | **KEEP** (12 inbound links; genuine runtime-posture reference — trim overlap only) |
| `core-actions-registry-vs-abilities-decision.md` | 120 | **FOLD into spec §4.1**, then archive |
| `core-sudo-gate-proposal-notes.md` | 86 | **ARCHIVE** (0 inbound links) |
| `core-sudo-gate-vs-demo-reconciliation.md` | 101 | **ARCHIVE** (3 inbound links) — but first preserve its one substantive fact: the flat-vs-nested `consequence`-block shape delta between the demo and the spec (fold that one line into the spec's Abilities section) |
| `password-change-reauth-research.md` | 259 | **ARCHIVE** (1 inbound link = docs-lint) |
| `connectors-api-reference.md` | 648 | **ARCHIVE** (7 inbound links — judgment call; 648 lines for one catalog rule) |

Result: ~6 canonical/reference docs (proposal, spec, poc, attack-tree, core-auth, abilities-assessment) + `docs/archive/` for the 5 research/process artifacts.

---

## The phasing decision to encode (resolve once, in the merged proposal)

**Lead gate-first. The consequential-actions registry is an independently-valuable but _optional_ companion, not a mandatory Phase 1.** Rationale: a threat-motivated recent-auth gate is more landable in core than a speculative registry, and the gate enforces at the chokepoint regardless of whether the registry ships. Reword the spec accordingly:
- Spec §3.4 ("Registry separate from gate. …Phase 1 …Phase 2") and §4.1 heading ("Consequential Actions registry (Phase 1)") and line ~272 ("Rows 1–2 are Phase 1 … Rows 3–16 are Phase 2"): keep the registry as a *cheap companion* but state the gate does **not** depend on it landing first. Don't call the registry "Phase 1" as if it's a prerequisite.
- The Abilities decision (once folded into the spec) keeps its stance verbatim — it's orthogonal and correct.

---

## Merged proposal structure (`core-action-gate-proposal.md` after merge)

Front the pitch, then the design/phasing/Abilities material. Suggested order:
1. **Overview / the ask** (from the pitch's header + §2) — gate-first, one paragraph.
2. **The problem** (pitch §1) — XSS→RCE, nonces-are-no-defense, HttpOnly/session-riding honesty.
3. **The invariant + minimal closure** (pitch §3–§4) — Group A (editor, `install_package`, `activate`, `switch_theme`) + Group B (own/other password, email→reset, create/promote, App Password both paths, `siteurl` critical-setting, registration-default-role invariant), code-capable-by-effective-capability.
4. **How it lands** (pitch §5) — shared effect sinks not `map_meta_cap`; actor-class policy; B′ session design (HMAC binds the proof hash; verifier = hash of `wp_get_session_token()`; no rotation; proof cookie on both WP paths).
5. **Non-goals / deferred vs excluded** (pitch §6).
6. **Registry & Abilities (the *why/phases* the old proposal carried)** — the Actions-API registry as the optional companion, Abilities-aligned per the decision (now in the spec); phasing stated per the decision above. Trim the old registry-first prose so it no longer contradicts §1–§5.
7. **Related: pre-auth chains (wp2shell) + WP_LANG_DIR** (pitch §9–§10).
8. Pointers (spec, PoC, attack-tree, core-auth reference).

Keep the merged doc's threat section thin and **link to `stolen-cookie-rce-attack-tree.md`** rather than restating (kills the 4-way duplication).

---

## Execution steps (ordered)

1. **Branch** off `main`: `docs/core-proposal-consolidation`.
2. **Merge pitch → proposal.** Rewrite `core-action-gate-proposal.md` per the structure above (gate-first, closure-synced, phasing decided). Preserve unique proposal material worth keeping (registry API sketch, Abilities-alignment rationale) in §6, trimmed to not contradict the gate-first lead.
3. **Fold the Abilities decision into the spec.** Move the operative decision (the "standalone, Abilities-aligned, union-deferred" conclusion) into `core-sudo-gate-implementation-spec.md` §4.1 as a subsection; keep it verbatim in substance.
4. **`mkdir docs/archive`** (if absent) and `git mv` these in: `core-sudo-gate-security-pitch.md` (post-merge — or just delete it if fully absorbed), `core-actions-registry-vs-abilities-decision.md`, `core-sudo-gate-proposal-notes.md`, `core-sudo-gate-vs-demo-reconciliation.md`, `password-change-reauth-research.md`, `connectors-api-reference.md`.
5. **Update inbound links** to the moved/merged docs (grep first: `grep -rl "<basename>" docs/ README.md CLAUDE.md`). Known consumers:
   - pitch → only `README.md`.
   - abilities-decision → `README.md`, `sudo-design-notes.md`, `llm-lies-log.md`, `core-sudo-gate-implementation-spec.md`, (+ the archived proposal-notes/vs-demo — no update needed). Point them at the spec §.
   - vs-demo → `README.md` (+ archived ones). 
   - connectors-api-reference → `README.md`, `developer-reference.md`, `security-model.md`, `abilities-api-assessment.md`, `core-action-gate-proposal.md`. Repoint to `docs/archive/connectors-api-reference.md` or replace with the one-line fact (`connectors_*_api_key` written via `POST /wp/v2/settings`).
   - password-change-reauth-research → only docs-lint (handled by step 7).
   - abilities-api-assessment (KEPT) → no move; just trim its overlap with the decision.
6. **Rewrite the README index** (`docs/README.md`, rows ~29–49): collapse the pitch/proposal rows into one "The core proposal (start here)" row; drop the folded/archived rows (abilities-decision, vs-demo); keep spec, poc, attack-tree, core-auth, abilities-assessment. Add an "Archived" note pointing to `docs/archive/`.
7. **docs-lint allowlist** (`.github/workflows/docs-lint.yml` `EXCLUDED_FILES`): **remove** the entries for the archived docs — `abilities-api-assessment.md` stays (kept); remove `connectors-api-reference.md`, `core-action-gate-proposal.md` is kept (still exists, keep its entry), `password-change-reauth-research.md` remove. Archived paths under `docs/archive/` are already covered by `EXCLUDED_PATHS` (`docs/archive/`), so their dated content needs no allowlist. Delete the pitch's absence-of-entry is moot (it had none). Net: remove `connectors-api-reference.md` and `password-change-reauth-research.md` lines; keep `core-action-gate-proposal.md`, `abilities-api-assessment.md`, `wordpress-core-authentication.md`.
8. **CLAUDE.md doc index** (project instructions list the docs): update any lines naming the moved docs (abilities-decision, connectors-ref, etc.).

---

## Verification (before PR merges)

- `git grep -nE "core-sudo-gate-security-pitch|core-actions-registry-vs-abilities-decision|core-sudo-gate-proposal-notes|core-sudo-gate-vs-demo|password-change-reauth|connectors-api-reference" -- docs README.md CLAUDE.md` → every remaining hit must be an intentional `docs/archive/…` link, not a dangling `docs/…` one.
- docs-lint (lychee link+anchor check + stale-date check) green — the CI job that failed earlier on stray dates/anchors.
- README index has a pointer for every kept doc (the AGENTS.md "index must point to every doc" rule) and none for archived ones except an Archived note.
- The merged proposal contains **one** phasing statement; spec §3.4/§4.1 no longer contradict it.
- No `YYYY-MM-DD` in the kept non-allowlisted docs.

---

## Judgment calls (flagged, decide at execution)

- **abilities-api-assessment.md** — kept (12 inbound links, real reference). If a leaner set is wanted, it could be trimmed to a short "runtime posture" note and the rest archived, but that's a bigger link cascade; default = keep + trim overlap with the folded decision.
- **connectors-api-reference.md** — archived here (648 lines for one rule). Alternative: keep as a reference. If kept, leave its allowlist entry and README row.
- **pitch** — plan says merge-then-delete; if you'd rather preserve its exact prose, `git mv` it to `docs/archive/` instead of deleting.
- **PoC (`core-sudo-gate-poc-patches.md`) — DECIDED: keep separate** (not archived, not folded into the spec). It's the most persuasive engineering artifact (actual patch sketches proving the "gate a handful of chokepoints" claim), and a core audience wants it as a distinct, citable doc. It stays out of the merge/fold/archive moves.
  - **Follow-up (do as part of, or alongside, the consolidation PR):** the PoC reads as confusing — it opens straight into dense code with no on-ramp. Add a short **plain-language intro summary** at the top: what these four patches are, the one idea they demonstrate (one data-layer guard per chokepoint covers admin UI + REST + programmatic, using the `WP_Error` paths those functions already return), what a reader should take away, and an explicit "these are illustrative sketches, not tested against a core checkout." Keep the body as technical as it needs to be — the intro is the gentle entry, not a dumbing-down.

## Related follow-ups this unblocks
- Closes the "proposal laggard" + "registry-vs-gate phasing" gaps from the alignment audit.
- Independent of the plugin hardening issues #278/#279/#280 (the B′ lessons flowing back into `class-sudo-session.php`).
