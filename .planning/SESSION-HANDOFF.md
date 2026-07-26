# Session handoff — 2026-07-26 (core-gate reconciliation + doc consolidation)

Working tree: clean on `main` (`e55351b`). Everything below is durable in files/issues, not this session — a fresh session can resume from here alone.

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
   - **Status: HANDED OFF, not built.** Editor/Playwright + wp-env + visual-snapshot + reviewer work is deliberately split to a `/Users/danknauss/bin/claude-playwright` session; do not attempt it headless. Turnkey kickoff: launch that session and tell it *"Implement #288 — read the committed brief + the issue's two comments, follow the brief (aria-controls selector, body-class approach, admin-bar tokens, omit M:SS), TDD + visual snapshots, wire any new spec into `e2e.yml`, `WP_ENV_TESTS_PORT=8891` if 8890 is held, reviewer gate, close #284."* Everything resolves from `main` + the issue.

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
