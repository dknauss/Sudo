# Phase 14: WordPress.org Readiness — Context

**Gathered:** 2026-06-19
**Status:** Ready for planning
**Source:** Direct user direction + ROADMAP/REQUIREMENTS (ORG-01..07)

<domain>
## Phase Boundary

Make the plugin's repository entry and supporting assets accurately represent the **current** plugin, so the `.org` listing would be honest, validator-clean, and brand-consistent — and capture a submission checklist in-repo. The plugin is **not yet published** to the WordPress.org plugin repository (see STATE.md); this phase establishes the compliant baseline, it does NOT perform an actual submission.

User-stated order: **readme validator → assets/screenshots → SECURITY.md → submission checklist.**

**Key reframe from the live repo:** most targets already EXIST — this is an audit/validate/polish/reconcile phase, not create-from-scratch:
- `readme.txt` (36 KB, header already `=== Sudo ===`, Stable tag 4.0.0, Requires 6.4 / PHP 8.2 / Tested up to 7.0).
- `SECURITY.md` (~1 KB, exists — review/update, not create).
- `.wordpress-org/` has `banner-1544x500.png`, `banner-772x250.png`, `icon-128x128.png`, `icon-256x256.png`, and `screenshot-1..7.png` (screenshot-1 is stale, dated Apr 20; 2–7 from Jun 14).
- `class-request-stash.php` confirms **suffix-based** redaction shipped (`SENSITIVE_KEY_SUFFIXES`), validating the ORG-06 discrepancy (roadmap lists pattern-based redaction as open).

**In scope:** readme validation/cleanup; asset/screenshot currency; brand/identity consistency audit; SECURITY.md review; plugin-header/license/stable-tag compliance; the request-stash doc-accuracy reconciliation; an in-repo submission checklist.

**Out of scope:** actually submitting to .org; any new feature/behavior code; the Access-tab/CI work (that is Phase 13.1).
</domain>

<decisions>
## Implementation Decisions (locked)

### ORG-01 — readme.txt passes the WordPress.org validator
- Validate `readme.txt` against the official validator semantics (header fields present and well-formed; required sections `== Description ==`, `== Installation ==`, `== Frequently Asked Questions ==`, `== Screenshots ==`, `== Changelog ==`, `== Upgrade Notice ==` as applicable; `Stable tag` matches the released version; `Tested up to` and `Requires at least` consistent with the plugin header and current release).
- `Stable tag` must equal the canonical released version (see `docs/release-status.md` — the canonical source per CLAUDE.md; do NOT hardcode a version guessed from memory).
- Screenshot captions in `== Screenshots ==` must match the actual `.wordpress-org/screenshot-N.png` set and ordering (ORG-02 coupling).

### ORG-02 — assets/ complete and current  ⚠ SEQUENCING + MANUAL
- Required assets: current screenshots of the live UI (Settings → Sudo tabs, **Access tab**, Session Activity dashboard widget, Request/Rule Tester), plus banner and icon. Banners/icons already exist and are likely fine; the SCREENSHOTS are the work.
- **HARD SEQUENCING:** the Access-tab screenshot (and any settings screenshots showing it) must be captured **AFTER Phase 13.1 executes** — 13.1 changes the Access-tab grant UI (user picker + plain-English labels). Capturing now would immediately go stale. The plan must gate the screenshot task on 13.1 execution, not just 13.1 planning.
- **BROWSER/MANUAL TASK:** capturing screenshots requires a running site + browser. Per CLAUDE.md, browser/screenshot work needs a fresh browser-capable session (`claude-playwright` / `claude-browser-handoff`) — it CANNOT be done from this session. The plan must mark ORG-02 screenshot capture as a manual/handoff deliverable with explicit shot list + naming (`screenshot-N.png`) and acceptance criteria, not an autonomous task. Note the live test env is Studio (recovery mode currently ON — restore before screenshots so the UI is representative).
- Audit existing `screenshot-1..7.png` for stale/removed UI (screenshot-1 is from Apr 20 — verify it doesn't show removed UI such as the old numeric user-ID Access field or a removed governance "compatibility mode").

### ORG-03 — brand/identity consistency
- Product name in UI strings reads **"Sudo"** (not "WP Sudo"). Audit user-facing strings.
- The package/slug/text-domain intentionally remain `wp-sudo`; GitHub URLs reflect `dknauss/Sudo`. Document the product-name ("Sudo") vs. slug (`wp-sudo`) vs. repo (`dknauss/Sudo`) relationship as an intentional divergence in the readme and/or `docs/developer-reference.md`.
- Investigate the `README.md` / `readme.md` pair (both 14,690 bytes — possible case-duplicate on a case-insensitive FS, or two real files). Decide canonical casing; a case-collision is a repo-hygiene risk on case-sensitive clones/CI.

### ORG-04 — SECURITY.md review/update
- State the vulnerability reporting channel, the supported-versions policy, whether GitHub issues are acceptable for initial security contact, and the security-fix changelog-wording convention. Reconcile with the existing `SECURITY.md` rather than rewriting wholesale.

### ORG-05 — plugin-header / license / stable-tag compliance
- `Stable tag`, license declaration (`License`, `License URI`), and plugin-header fields must be WordPress.org-compliant and internally consistent across `wp-sudo.php` header, `readme.txt`, and `composer.json`.
- Respect the CLAUDE.md **version sync checklist**: `WP_SUDO_VERSION` in `wp-sudo.php` header + constant, `phpstan-bootstrap.php`, `tests/bootstrap.php`, and `readme.txt` Stable tag must all agree. This phase VERIFIES agreement; it does not bump the version unless a mismatch is found.

### ORG-06 — documentation-accuracy pass (request-stash redaction)
- `docs/ROADMAP.md` lists request-stash pattern-based redaction as open/unbuilt, but suffix-based redaction shipped (v2.10.2–v2.11.0; confirmed by `SENSITIVE_KEY_SUFFIXES` in `includes/class-request-stash.php`). Verify the true residual against the redaction code and correct the stale doc status. REQUIREMENTS already marks "new pattern-redaction build" as out of scope — this is a doc reconciliation only.

### ORG-07 — submission checklist
- Capture an initial WordPress.org submission checklist in the repo docs (e.g. `docs/wporg-submission-checklist.md`): pre-submission validation steps, asset requirements, trunk/tag layout, SVN deploy notes, and the version-sync gate. It documents the process; it does not execute a submission.

### Claude's Discretion
- Exact location of the submission checklist and the brand-divergence note (readme vs developer-reference).
- Whether the README.md/readme.md issue is a rename, a delete, or a deliberate two-file setup.
- Plan/wave decomposition — but ORG-02 screenshot capture MUST be isolated as a manual/handoff task gated on 13.1 execution.
</decisions>

<specifics>
## Specific References
- `readme.txt` (root) — header + sections; `Stable tag: 4.0.0` at time of context.
- `.wordpress-org/` — banner-1544x500.png, banner-772x250.png, icon-128x128.png, icon-256x256.png, screenshot-1..7.png.
- `SECURITY.md` (root, ~1 KB).
- `README.md` + `readme.md` (root, both 14,690 bytes — investigate).
- `includes/class-request-stash.php` — `SENSITIVE_KEY_SUFFIXES` (line ~68), redaction logic (lines ~135–170) for ORG-06.
- `docs/ROADMAP.md` — request-stash redaction status to correct.
- Canonical sources (CLAUDE.md — do NOT hardcode from memory): `docs/release-status.md` (stable tag / release state), `docs/current-metrics.md` (counts), `docs/llm-lies-log.md` (append any new findings).
- Version-sync targets: `wp-sudo.php` (header + `WP_SUDO_VERSION`), `phpstan-bootstrap.php`, `tests/bootstrap.php`, `readme.txt` Stable tag.
- Brand: product = "Sudo"; slug/text-domain = `wp-sudo`; repo = `dknauss/Sudo`.

## Cross-phase dependency
- **Phase 14 ORG-02 (and any readme screenshot captions) depend on Phase 13.1 EXECUTION**, not just planning — the Access-tab UI changes there. Plan accordingly: ORG-01/03/04/05/06/07 can proceed independently; ORG-02 screenshot capture waits for 13.1 to land and is a browser-session handoff.
</specifics>

<deferred>
## Deferred Ideas
- Actual WordPress.org submission / SVN deploy (this phase only prepares + checklists it).
- New pattern-based redaction build (explicitly out of scope per REQUIREMENTS; ORG-06 is doc reconciliation).
- Any feature/UI behavior changes.
</deferred>

---

*Phase: 14-wordpress-org-readiness*
*Context gathered: 2026-06-19 via direct user direction + ROADMAP/REQUIREMENTS*
