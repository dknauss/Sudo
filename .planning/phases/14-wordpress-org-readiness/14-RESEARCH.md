# Phase 14: WordPress.org Readiness — Research

**Researched:** 2026-06-19
**Domain:** WordPress.org plugin submission compliance, readme.txt validation, asset management, brand consistency, security disclosure, documentation accuracy
**Confidence:** HIGH (all claims verified against live source files; validator rules verified against official parser source + community linters)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**ORG-01 — readme.txt passes the WordPress.org validator**
- Validate `readme.txt` against the official validator semantics (header fields present and well-formed; required sections `== Description ==`, `== Installation ==`, `== Frequently Asked Questions ==`, `== Screenshots ==`, `== Changelog ==`, `== Upgrade Notice ==` as applicable; `Stable tag` matches the released version; `Tested up to` and `Requires at least` consistent with the plugin header and current release).
- `Stable tag` must equal the canonical released version (see `docs/release-status.md` — the canonical source per CLAUDE.md; do NOT hardcode a version guessed from memory).
- Screenshot captions in `== Screenshots ==` must match the actual `.wordpress-org/screenshot-N.png` set and ordering (ORG-02 coupling).

**ORG-02 — assets/ complete and current (SEQUENCING + MANUAL)**
- Required assets: current screenshots of the live UI (Settings → Sudo tabs, Access tab, Session Activity dashboard widget, Request/Rule Tester), plus banner and icon.
- HARD SEQUENCING: the Access-tab screenshot (and any settings screenshots showing it) must be captured AFTER Phase 13.1 executes.
- BROWSER/MANUAL TASK: capturing screenshots requires a running site + browser. Per CLAUDE.md, browser/screenshot work needs a fresh browser-capable session (`claude-playwright` / `claude-browser-handoff`). The plan must mark ORG-02 screenshot capture as a manual/handoff deliverable with explicit shot list + naming and acceptance criteria. Restore Studio recovery mode backup before screenshots (WP_SUDO_RECOVERY_MODE should be OFF for representative UI).
- Audit existing screenshot-1..7.png for stale/removed UI (screenshot-1 is from Apr 20).

**ORG-03 — brand/identity consistency**
- Product name in UI strings reads "Sudo" (not "WP Sudo"). Audit user-facing strings.
- Package/slug/text-domain intentionally remain `wp-sudo`; GitHub URLs reflect `dknauss/Sudo`. Document the product-name vs. slug relationship as intentional.
- Investigate the `README.md` / `readme.md` pair.

**ORG-04 — SECURITY.md review/update**
- State the vulnerability reporting channel, the supported-versions policy, whether GitHub issues are acceptable for initial security contact, and the security-fix changelog-wording convention. Reconcile with existing `SECURITY.md` rather than rewriting wholesale.

**ORG-05 — plugin-header / license / stable-tag compliance**
- `Stable tag`, license declaration, and plugin-header fields must be WordPress.org-compliant and internally consistent across `wp-sudo.php` header, `readme.txt`, and `composer.json`.
- Respect the CLAUDE.md version sync checklist. This phase VERIFIES agreement; does not bump version unless mismatch found.

**ORG-06 — documentation-accuracy pass (request-stash redaction)**
- `docs/ROADMAP.md` lists request-stash pattern-based redaction as open/unbuilt, but suffix-based redaction shipped. Verify the true residual against the redaction code and correct the stale doc status. This is a doc reconciliation only.

**ORG-07 — submission checklist**
- Capture an initial WordPress.org submission checklist in the repo docs (e.g. `docs/wporg-submission-checklist.md`).

### Claude's Discretion
- Exact location of the submission checklist and the brand-divergence note (readme vs developer-reference).
- Whether the README.md/readme.md issue is a rename, a delete, or a deliberate two-file setup.
- Plan/wave decomposition — but ORG-02 screenshot capture MUST be isolated as a manual/handoff task gated on 13.1 execution.

### Deferred Ideas (OUT OF SCOPE)
- Actual WordPress.org submission / SVN deploy (this phase only prepares + checklists it).
- New pattern-based redaction build (explicitly out of scope per REQUIREMENTS; ORG-06 is doc reconciliation).
- Any feature/UI behavior changes.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| ORG-01 | `readme.txt` passes the WordPress.org readme validator | See §ORG-01 Findings: short description is 204 chars (over 150 limit); Changelog section starts at 3.1.3 (missing 4.0.0 entry for when released); "What's new in 3.2.0?" subsection is stale; Playground link references v3.4.0 tag; Plugin Check CI already validates some readme rules |
| ORG-02 | Plugin assets are complete and current — screenshots of live UI | See §ORG-02 Findings: screenshot-1 dated Apr 20 (stale pre-Access-tab), 2-7 dated Jun 14; all screenshots gated on Phase 13.1 execution; browser handoff required; Studio env needs recovery mode off |
| ORG-03 | Brand/identity consistency audit | See §ORG-03 Findings: 13+ user-facing "WP Sudo" strings in PHP across class-admin.php, class-gate.php, class-site-health.php; readme.txt uses "=== Sudo ===" but plugin header says "Plugin Name: WP Sudo"; README.md and readme.md are same inode (macOS case-fold, not two files) |
| ORG-04 | SECURITY.md reviewed/updated | See §ORG-04 Findings: reporting channel OK; GitHub Issues stance absent; supported-versions policy vague; security-fix changelog convention unwritten; scope section present but thin |
| ORG-05 | Stable tag, license, plugin-header fields are WordPress.org-compliant | See §ORG-05 Findings: all 4 version-sync locations agree on 4.0.0; license is GPL-2.0-or-later (valid); License URI points to SPDX (valid but non-standard); composer.json license matches; plugin name divergence (header "WP Sudo" vs readme "Sudo") is a PCP warning risk |
| ORG-06 | Documentation-accuracy pass — request-stash redaction status in docs/ROADMAP.md | See §ORG-06 Findings: ROADMAP line 42-44 says "exact-key based and misses provider-specific names" — this is stale; suffix-based redaction shipped in v2.11.0 (confirmed by SENSITIVE_KEY_SUFFIXES constant + @since 2.11.0 + phase3-stash-minimization-spec.md + CHANGELOG) |
| ORG-07 | Initial WordPress.org submission checklist in repo docs | See §ORG-07 Findings: no wporg-submission-checklist.md exists; docs/archive/release-3.0.0-checklist.md is the closest precedent (version-sync gate, CI gate, tag + publish steps); new file should be at docs/wporg-submission-checklist.md |
</phase_requirements>

## Summary

Phase 14 is an audit/validate/polish/reconcile phase over existing artifacts. The plugin is not yet published to WordPress.org. Most targets already exist and need specific corrections rather than creation from scratch.

The most consequential finding is the **short description length violation**: `readme.txt`'s short description (the single line after the header block) is 204 characters — exceeding the WordPress.org 150-character limit. This is the only outright validator-failing item. The description needs to be trimmed without losing key meaning.

The second critical finding is the **ORG-02 screenshot sequencing constraint**: screenshot-1 is from April 20 (predates the Access-tab grant UI), all screenshots may be stale after Phase 13.1 changes the Access tab UI, and screenshot capture is a browser/manual task that cannot be done from this Claude session. The plan must hard-gate screenshot capture on Phase 13.1 execution.

The third finding group covers brand/identity: 13+ user-facing PHP strings say "WP Sudo" while the product name is "Sudo"; the readme.txt plugin name (`=== Sudo ===`) and plugin header (`Plugin Name: WP Sudo`) diverge; and the README.md/readme.md "two files" situation is actually macOS case-folding of the single git-tracked `readme.md` — not a real duplicate or a risk on case-sensitive filesystems (git only tracks one).

**Primary recommendation:** ORG-01/03/04/05/06/07 can be executed as one pass of text edits and automation scripts. ORG-02 screenshot capture is a separate, manually-gated deliverable that must wait for Phase 13.1 to land.

## Standard Stack

### No new library dependencies

This phase is doc/asset editing only. No new PHP, npm, or composer packages are needed.

### Existing tooling available

| Tool | Where | What it provides |
|------|--------|-----------------|
| Plugin Check (PCP) | `.github/workflows/plugin-check.yml` (existing CI) | Validates plugin header fields, stable_tag_mismatch, mismatched plugin names, readme structure; runs on every PR to main |
| `bin/verify-metrics.sh` | `bin/` | Verifies counts in `docs/current-metrics.sh`; run after any metric changes |
| `composer lint` | existing | PHPCS — catches any PHP string issues from brand-string edits |
| `composer test:unit` | existing | Ensures no PHP regressions from any code-adjacent edits |
| `git ls-files` | CLI | Verify README.md/readme.md tracking situation |
| wporg-plugin-readme-linter | community GitHub Action (`thetwopct/wporg-plugin-readme-linter`) | Can validate readme.txt locally or in CI against WordPress.org standards; detects short-description length, section presence, tag limits, Stable tag format |

### Optional: readme validator CI step

The `thetwopct/wporg-plugin-readme-linter` GitHub Action can be added to an existing workflow or run locally via Node. It checks: plugin name present, required fields, short description ≤ 150 chars, Stable tag semantic version, License present, Changelog/Description/Installation sections. This is a LOW-effort addition that gives automated coverage for ORG-01. (MEDIUM confidence — community tool verified to exist and be actively maintained; behavior verified against repo README.)

## Architecture Patterns

### Recommended Wave Decomposition

```
Wave 1: ORG-01 readme.txt validator pass (text editing + automated check)
Wave 2: ORG-03 brand/identity audit (string grep + targeted edits)
Wave 3: ORG-04 SECURITY.md review/update (doc edit)
Wave 4: ORG-05 version-sync verification + license compliance (grep verification)
Wave 5: ORG-06 ROADMAP.md redaction doc correction (single targeted edit)
Wave 6: ORG-07 submission checklist creation (new file)
Wave 7 (GATED on Phase 13.1 + browser session): ORG-02 screenshot capture
```

Waves 1-6 are fully automated/text-based and can proceed immediately. Wave 7 is the manual/browser handoff and must wait for Phase 13.1 to fully execute (not just plan).

### Anti-Patterns to Avoid

- **Don't rewrite SECURITY.md wholesale:** The existing content is mostly correct. Add the missing items (GitHub issues stance, changelog convention) without removing what works.
- **Don't rename the PHP strings to "Sudo" everywhere indiscriminately:** The brand context matters. "WP Sudo policy" and "WP Sudo MU-Plugin" in technical/admin strings are acceptable because they disambiguate from "sudo" the Unix command. Review each string in context; the most user-facing (settings page headings, notices users see) should say "Sudo", technical descriptions may retain "WP Sudo" for clarity. The CONTEXT.md locked decision is to audit, not blanket-replace.
- **Don't capture screenshots before Phase 13.1 lands:** Any screenshot that shows the Access tab will go stale.
- **Don't delete README.md separately from readme.md:** They are the same inode. On macOS, deleting one path deletes the other. Git only tracks `readme.md`. No action is needed on this — it is not a two-file problem on case-sensitive systems.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| readme.txt validation | Custom regex parser | Plugin Check (PCP) already in CI + optional `thetwopct/wporg-plugin-readme-linter` | PCP already runs on every PR; handles stable_tag_mismatch, plugin-name checks |
| Counting string occurrences | Manual search | `grep -rn "WP Sudo" --include="*.php"` command | Already used in this research, reproducible, fast |
| Version sync check | Manual reading | `grep -n "WP_SUDO_VERSION\|Stable tag\|Version:" wp-sudo.php phpstan-bootstrap.php tests/bootstrap.php readme.txt` | One command covers all 4 sync points |

## Common Pitfalls

### Pitfall 1: Short Description Over-Trimming
**What goes wrong:** Trimming the 204-char description too aggressively loses the three-part value prop (guard door / govern inside / define audit surfaces).
**Why it happens:** The 150-char limit is tight; the current sentence encodes three ideas.
**How to avoid:** Draft variants. Current text is: "WordPress security plugins guard the door. Sudo governs what can happen inside the house. It helps site owners define, monitor, and audit the administrative attack surfaces where high-risk actions happen." (204 chars). A 150-char version might be: "WordPress security plugins guard the door. Sudo governs what happens inside — gating dangerous admin actions behind reauthentication." (135 chars). Verify length with `echo -n "..." | wc -c`.
**Warning signs:** New description > 150 chars after edit.

### Pitfall 2: Plugin Name Mismatch PCP Warning
**What goes wrong:** Plugin Check warns when `Plugin Name` in the file header differs from the name in `readme.txt`. The header says "WP Sudo" and readme.txt says "Sudo". This is intentional brand divergence but PCP doesn't know that.
**Why it happens:** The rename from "WP Sudo" to "Sudo" (product name) wasn't propagated to the plugin header.
**How to avoid:** Two options: (a) update `Plugin Name: WP Sudo` → `Plugin Name: Sudo` in `wp-sudo.php` header (the plugin header name is user-visible in the Plugins list) OR (b) accept the PCP warning and add it to the documented ignore list. Option (a) is cleaner for the .org listing. This is in Claude's discretion; flag for the planner.
**Warning signs:** PCP CI output shows `plugin_name_mismatch` error or warning.

### Pitfall 3: Screenshot Captions Uncoupled from Actual Assets
**What goes wrong:** readme.txt `== Screenshots ==` captions say "Access tab — manage dedicated Sudo governance capabilities and revoke sessions" (screenshot-5) but after Phase 13.1, the Access tab UI changes. If screenshots are updated but captions aren't (or vice versa), the listing is inaccurate.
**How to avoid:** Update captions and screenshots together in the same wave. The plan should include caption review as part of ORG-02.
**Warning signs:** Caption count ≠ .wordpress-org/screenshot-N.png count.

### Pitfall 4: "What's New in 3.2.0?" Section in readme.txt Description
**What goes wrong:** The readme.txt Description section contains `= What's new in 3.2.0? =` — a version-specific subsection that is now stale (current version is 4.0.0). On the .org listing, this looks like abandoned documentation.
**Why it happens:** Added during 3.2.0 development; not removed as the version advanced.
**How to avoid:** Replace or remove the "What's new in 3.2.0?" subsection. Either update it to cover 4.0.0's key changes or remove it entirely and rely on the Changelog section. The planner should make this a task in the readme pass.

### Pitfall 5: Playground Link References Stale Tag
**What goes wrong:** readme.txt contains `?blueprint-url=...v3.4.0/blueprint.json`. After 4.0.0 tags, this link will install the old version.
**Why it happens:** The link needs to be updated at release time but is currently pre-baked into readme.txt.
**How to avoid:** This should be part of the ORG-07 submission checklist (update Playground link at tag time). During Phase 14, the link correctly points to v3.4.0 (the current stable tag per the tagged release — docs/release-status.md says v3.4.0 is latest tag). Do NOT change it now; update at tag time. Document this as a release-time task in ORG-07.
**Warning signs:** Checking the release-status.md "Latest tagged release" field to know what the link should point to.

### Pitfall 6: Upgrade Notice Exceeds 300 Characters
**What goes wrong:** The `= 4.0.0 =` Upgrade Notice in readme.txt is 761 characters. WordPress.org/plugin directory may truncate this display.
**Why it happens:** The 4.0.0 breaking-change summary is genuinely long.
**How to avoid:** The limit is a display limit, not a hard validator error. The full text is preserved. However, the displayed excerpt may be misleading. Consider trimming the notice to ≤ 300 chars with a "See CHANGELOG for full details" link. This is in Claude's discretion.
**Warning signs:** Notice text > 300 chars.

### Pitfall 7: Screenshot-1 Is Stale
**What goes wrong:** `.wordpress-org/screenshot-1.png` (Challenge page) was last modified April 20, 2026 — before the Access tab was added. The actual challenge page UI may also have changed since then.
**Why it happens:** Screenshot-1 was not refreshed in the Jun 14 screenshot batch.
**How to avoid:** Include screenshot-1 in the full screenshot refresh. The challenge page is relatively stable but should still be verified current. Flag in ORG-02 handoff.

## Code Examples

### Version Sync Verification (run before every commit in this phase)
```bash
# Verify all 4 sync points agree
grep "Version:" wp-sudo.php | head -1
grep "define.*WP_SUDO_VERSION" wp-sudo.php
grep "define.*WP_SUDO_VERSION" phpstan-bootstrap.php
grep "define.*WP_SUDO_VERSION" tests/bootstrap.php
grep "^Stable tag:" readme.txt
# Cross-check against canonical source:
grep "Latest tagged release\|Next planned release" docs/release-status.md
```

### Brand String Audit (verified live against this codebase)
```bash
# Find all translated user-facing "WP Sudo" strings in PHP
grep -rn "WP Sudo" --include="*.php" includes/ wp-sudo.php \
  | grep -E "_e\(|__\(|esc_html_e\(|esc_html__\("
```
Confirmed live results (verified 2026-06-19):
- `includes/class-admin.php:1291` — "WP-CLI, Cron, and XML-RPC Limited mode use WP Sudo's built-in function-hook coverage" (technical description in help text)
- `includes/class-admin.php:2507` — "WP Sudo break-glass recovery mode is active" (admin notice)
- `includes/class-admin.php:2596` — "WP Sudo removed a leftover permission-mode setting" (admin notice)
- `includes/class-gate.php:224` — "WP-CLI is disabled by WP Sudo policy." (error message)
- `includes/class-gate.php:265` — "WP-Cron is disabled by WP Sudo policy." (error message)
- `includes/class-gate.php:829,865,872,1174,1239` — Rule Tester notes and REST/WPGraphQL error messages
- `includes/class-site-health.php:49,54,59,64,84,90,96,102,152,164,195,201,207,236` — Site Health test labels and descriptions (14 strings)

### Short Description Length Check
```bash
# Extract and measure readme.txt short description
awk 'NR==12' readme.txt | awk '{print "Length:", length, "Text:", $0}'
# Current result: Length: 204 — EXCEEDS the 150-char limit
```

### Screenshot Asset Inventory (verified 2026-06-19)
```bash
ls -la .wordpress-org/screenshot-*.png
```
Results:
- `screenshot-1.png` — 254631 bytes, **Apr 20 09:25** (STALE — predates Access tab addition)
- `screenshot-2.png` — 260419 bytes, Jun 14 08:02
- `screenshot-3.png` — 328592 bytes, Jun 14 08:02
- `screenshot-4.png` — 242419 bytes, Jun 14 08:02
- `screenshot-5.png` — 245418 bytes, Jun 14 08:02 (Access tab — will be stale after Phase 13.1)
- `screenshot-6.png` — 356611 bytes, Jun 14 08:02
- `screenshot-7.png` — 279331 bytes, Jun 14 08:02

## Detailed Requirement Findings

### ORG-01: readme.txt Validator Findings (Verified 2026-06-19)

**Source:** Live `readme.txt`, WordPress readme validator rules (verified via markjaquith/WordPress-Plugin-Readme-Parser validator.php source + thetwopct/wporg-plugin-readme-linter documentation), Plugin Check CI workflow.

**What the WordPress.org readme validator checks:**
- Plugin name in `=== Name ===` format — PASS (`=== Sudo ===`)
- `Contributors` field (WordPress.org usernames) — `dpknauss` present; manual verification that this username exists on WordPress.org is NEEDED before submission (cannot verify from this session)
- `Tags` — max 5 tags; current has 5 (`sudo, security, reauthentication, access control, admin protection`) — PASS
- `Requires at least` — PASS (`6.4`)
- `Tested up to` — PASS (`7.0`, which is current latest stable per docs/release-status.md)
- `Stable tag` — must equal the version being submitted; currently `4.0.0` — consistent with plugin header — PASS (note: `docs/release-status.md` says the latest tagged release is `3.4.0`, but the version on the current branch is `4.0.0`; readme.txt Stable tag should equal the actual tagged release at submission time)
- `License` — `GPL-2.0-or-later` — PASS (WordPress.org requires GPL-compatible)
- `License URI` — `https://spdx.org/licenses/GPL-2.0-or-later.html` — PASS (SPDX is accepted; `https://www.gnu.org/licenses/gpl-2.0.html` is more conventional but SPDX is valid)
- **Short description — FAIL:** 204 characters, exceeds 150-char limit. Must be trimmed.
- `== Description ==` section — PASS (present)
- `== Installation ==` section — PASS (present)
- `== Frequently Asked Questions ==` — PASS (present, correct heading)
- `== Screenshots ==` — PASS (present, 7 entries matching 7 .png files)
- `== Changelog ==` — PASS (present); Note: Changelog starts at `= 3.1.3 =`, NOT `= 4.0.0 =`. When 4.0.0 releases, a `= 4.0.0 =` entry must be added to the Changelog section in readme.txt. Current state is pre-release; this gap is expected but must be in the checklist.
- `== Upgrade Notice ==` — PASS (present); `= 4.0.0 =` entry is 761 chars; WordPress.org truncates display at ~300 chars but does not error. Consider trimming for display quality.
- `= What's new in 3.2.0? =` subsection in Description — STALE. This version-specific subsection should be removed or updated to reflect current version.
- Playground link in Description — `https://playground.wordpress.net/?blueprint-url=...v3.4.0/blueprint.json` — references v3.4.0 (current latest tag per docs/release-status.md); this is CORRECT now but must be updated at 4.0.0 tag time. Record in ORG-07 checklist.
- Donate link — `https://dan.knauss.ca` — present and syntactically valid.
- `== For Developers ==` section — this is a non-standard section name. WordPress.org won't error on custom sections; they're displayed but not specially handled. No action required.

**Plugin Check (PCP) in CI (verified 2026-06-19):**
The existing `plugin-check.yml` workflow runs PCP on every PR to main. It currently ignores `wp_function_not_compatible_with_requires_wp` (valid — guarded calls). PCP checks include:
- `stable_tag_mismatch` (already caught and fixed per STATE.md — readme Stable tag must match plugin header Version)
- Plugin name mismatch between plugin header and readme.txt header
- readme.txt presence and basic structure

**Risk items requiring action:**
1. SHORT DESCRIPTION: 204 chars → trim to ≤ 150 chars (REQUIRED)
2. STALE SUBSECTION: Remove/update "What's new in 3.2.0?" (RECOMMENDED)
3. CHANGELOG 4.0.0 ENTRY: Add when releasing (RELEASE-TIME TASK — document in ORG-07)
4. PLAYGROUND LINK UPDATE: Update at v4.0.0 tag time (RELEASE-TIME TASK — document in ORG-07)
5. CONTRIBUTOR VERIFICATION: Confirm `dpknauss` is the correct WordPress.org username (MANUAL — cannot verify from CLI)
6. UPGRADE NOTICE LENGTH: 761 chars; consider trimming to ~300 for display quality (OPTIONAL)

### ORG-02: Screenshot Asset Findings (Verified 2026-06-19)

**Screenshot-1 (Challenge page) — Apr 20, STALE**
Must be recaptured. The challenge page UI itself may be stable, but the screenshot predates multiple changes.

**Screenshots 2-7 (Jun 14) — may be stale after Phase 13.1**
- screenshot-2: Settings tab (relatively stable)
- screenshot-3: Gated Actions tab (stable)
- screenshot-4: Rule Tester tab (stable)
- screenshot-5: Access tab — **WILL BE STALE** after Phase 13.1 changes the grant UI (user picker + plain-English labels)
- screenshot-6: Dashboard widget (stable)
- screenshot-7: Break-glass recovery notice (stable)

**Required shot list for ORG-02 deliverable:**
| File | Caption (from readme.txt) | Status after 13.1 | Notes |
|------|---------------------------|-------------------|-------|
| screenshot-1.png | Challenge page — reauthentication interstitial with password field | Recapture needed | Apr 20 — stale |
| screenshot-2.png | Settings tab — policy presets, session settings, and active sudo timer | Verify only | Jun 14 — likely current |
| screenshot-3.png | Gated Actions tab — protected operations with rule IDs and covered surfaces | Verify only | Jun 14 — likely current |
| screenshot-4.png | Rule Tester tab — evaluate representative request shapes without executing them | Verify only | Jun 14 — likely current |
| screenshot-5.png | Access tab — manage dedicated Sudo governance capabilities and revoke sessions | Recapture required | Phase 13.1 changes this UI |
| screenshot-6.png | Dashboard widget — active sessions, policy summary, and recent privilege-action events | Verify only | Jun 14 — likely current |
| screenshot-7.png | Break-glass recovery notice — visible warning while WP_SUDO_RECOVERY_MODE is active | Verify only | Jun 14 — likely current |

**Browser handoff requirement:** A browser-capable Claude session (`claude-playwright` or `claude-browser-handoff`) is required. The current session CANNOT capture screenshots.

**Studio env setup for ORG-02:**
- Restore `wp-config.php.wpsudo-bak` before screenshots (WP_SUDO_RECOVERY_MODE must be OFF — otherwise screenshot-7 appears in contexts where it shouldn't)
- After Phase 13.1 Access-tab UAT, recovery mode restore is already planned per CONTEXT.md operational note

**Acceptance criteria:**
- All 7 screenshot files updated (at minimum screenshot-1 and screenshot-5 recaptured)
- Screenshots match the readme.txt captions (caption update if UI labels changed)
- No screenshot shows removed UI (old numeric user-ID Access field, compatibility mode)
- Banner and icon files remain current (no changes needed — Jun 14 dates, good condition)

### ORG-03: Brand/Identity Findings (Verified 2026-06-19)

**README.md / readme.md situation — RESOLVED (no action needed):**
- `git ls-files` returns only `readme.md` — git tracks one file.
- Both `README.md` and `readme.md` have the **same inode** (231944476 on this macOS filesystem) and same size (14,690 bytes).
- This is macOS case-insensitive filesystem presenting two paths for the same file. It is NOT two separate files. Case-sensitive CI (Linux) and case-sensitive git clones see only `readme.md`.
- **No file deletion or rename is needed.** The "both 14,690 bytes" observation is explained by this — they're literally identical because they're the same file.
- Decision for ORG-03: document this as "readme.md is the canonical casing; macOS users may see README.md as an alias." No git action needed.

**Plugin name divergence:**
- `wp-sudo.php` header: `Plugin Name: WP Sudo`
- `readme.txt` header: `=== Sudo ===`
- This will trigger a PCP `plugin_name_mismatch` warning. Resolution options:
  1. Change plugin header to `Plugin Name: Sudo` (aligns with product name; affects "Plugins" admin list display)
  2. Accept warning and document as intentional
- The planner should decide; this is within Claude's discretion. Recommendation: update header to `Plugin Name: Sudo` for listing consistency.

**User-facing "WP Sudo" strings in PHP (13+ instances, verified 2026-06-19):**

Categorized by action needed:

*KEEP as-is (technical/admin context where disambiguation from Unix `sudo` is needed):*
- `class-gate.php:224` — "WP-CLI is disabled by WP Sudo policy." (technical error, internal-facing)
- `class-gate.php:265` — "WP-Cron is disabled by WP Sudo policy." (same)
- `class-gate.php:829,865,872` — Rule Tester notes (developer-facing)
- `class-admin.php:1291` — Help text in Gated Actions tab (developer-facing technical description)

*REVIEW/POSSIBLY UPDATE (user-facing notices and labels):*
- `class-admin.php:2507` — "WP Sudo break-glass recovery mode is active" (admin notice, site admin visible) — consider "Sudo break-glass recovery mode"
- `class-admin.php:2596` — "WP Sudo removed a leftover permission-mode setting" (admin notice) — consider "Sudo removed..."
- `class-site-health.php:49,54,59,64,84,90,96,102,152,164,195,201,207,236` — All 14 Site Health test labels use "WP Sudo" prefix — these appear in WordPress Site Health and are user-visible. Consider updating to "Sudo" prefix.
- `class-gate.php:1174` — "This REST API operation is disabled by WP Sudo policy." — user-facing REST error
- `class-gate.php:1239` — "WPGraphQL is disabled by WP Sudo policy." — user-facing WPGraphQL error

**Brand divergence documentation:**
The product-name ("Sudo") vs slug (`wp-sudo`) vs repo (`dknauss/Sudo`) relationship should be documented in `docs/developer-reference.md` (a section already exists and is the natural location) or in the readme.txt `== For Developers ==` section. This explains the intentional divergence for integrators.

### ORG-04: SECURITY.md Findings (Verified 2026-06-19 against live file)

**What's present (KEEP):**
- Supported versions: main branch only (clear and appropriate)
- Reporting channel: GitHub private vulnerability reporting + personal contact fallback (adequate)
- "Do not open a public issue" stance — explicitly states this
- Response targets: 5 business days triage, 10 days status update (specific and appropriate)
- Scope statement: gating, challenge flows, integration points, build/release automation (good)
- Report format request: affected gate, reproduction steps, impact, mitigation (good)

**What's MISSING (add):**
1. **GitHub Issues stance for security:** SECURITY.md says "Do not open a public issue" but does not explicitly say "GitHub Issues are NOT acceptable for initial security contact." This should be stated clearly. (Currently implied; should be explicit.)
2. **Supported versions policy clarity:** "This repository is supported on the default branch only" covers the current model but doesn't say what happens to past releases after a new version ships. For an unpublished plugin, this is adequate. Add a note that once published, the most recently published version on WordPress.org is the supported version.
3. **Security-fix changelog convention:** No convention is documented for how security fixes appear in CHANGELOG.md. Looking at the CHANGELOG, security fixes use `**Security: ...` prefix within a release's bullet list, and separate `### Security hardening` subsection headers in some releases. This convention should be documented in SECURITY.md so reporters know what to expect and maintainers follow a consistent pattern.
4. **CVE assignment statement:** No mention of whether CVEs are requested. This is optional but common in security policies for WordPress plugins. Can note "CVEs are not currently requested but may be assigned by third-party researchers."

**What's fine as-is:**
- GitHub private vulnerability reporting is the primary channel
- Personal contact is a fallback
- Response timeline is documented
- Public disclosure timing is clear

### ORG-05: Version Sync and License Compliance (Verified 2026-06-19)

**Version sync — ALL AGREE (verified by grep against live files):**
```
wp-sudo.php header Version:        4.0.0
wp-sudo.php WP_SUDO_VERSION:       4.0.0
phpstan-bootstrap.php:             4.0.0
tests/bootstrap.php:               4.0.0
readme.txt Stable tag:             4.0.0
```
**Status: PASS — no mismatch found.**

Note per docs/release-status.md: the latest *tagged* release is `3.4.0`. The `4.0.0` version exists on the PR #86 branch (now merged per REQUIREMENTS traceability showing Phase 13 complete). The Stable tag of 4.0.0 is correct for the pre-release branch state; it must be verified again at actual 4.0.0 tag time.

**License compliance:**
- `wp-sudo.php` header: `License: GPL-2.0-or-later` — PASS
- `wp-sudo.php` header: `License URI: https://spdx.org/licenses/GPL-2.0-or-later.html` — PASS (SPDX is valid; conventional URL is `https://www.gnu.org/licenses/gpl-2.0.html` but SPDX is accepted)
- `composer.json`: `"license": "GPL-2.0-or-later"` — PASS (matches)
- WordPress.org requires GPL-compatible license. GPL-2.0-or-later satisfies this.

**Plugin header fields (verified 2026-06-19):**
- `Plugin Name: WP Sudo` — diverges from readme.txt `=== Sudo ===`; see ORG-03 for resolution
- `Plugin URI: https://github.com/dknauss/Sudo` — valid, points to correct repo
- `Description:` present — PASS
- `Author URI: https://profiles.wordpress.org/danknauss/` — points to WordPress.org profile — PASS
- `Text Domain: wp-sudo` — matches slug — PASS
- `Domain Path: /languages` — PASS

**One compliance item to verify:** The `dpknauss` contributor username in readme.txt vs `danknauss` in the Author URI (`profiles.wordpress.org/danknauss/`). These appear to be different slugs. If `dpknauss` is not a valid WordPress.org account, the Contributors field would be problematic. This requires manual verification on WordPress.org before submission. (Cannot verify from CLI.)

### ORG-06: Redaction Documentation Accuracy (Verified 2026-06-19)

**The stale claim in docs/ROADMAP.md (lines 42-44):**
```
- **Request stash pattern-based redaction:** Current stash redaction is exact-key based
  and misses provider-specific names (`clientSecret`, `refreshToken`, `authorization`,
  etc.). Add conservative pattern-based redaction and custom-rule metadata for
  non-replayable fields.
```
This appears under "Open: Security Review Remediation" as an unclosed item.

**What actually shipped (verified against live code):**
`includes/class-request-stash.php` contains `SENSITIVE_KEY_SUFFIXES` (lines 68-97) — a 28-item array of suffix patterns including `_password`, `_api_key`, `_secret`, `_access_token`, `-api-key`, `apikey`, `secretkey`, etc. The `is_sensitive_key()` method (lines 531-545) checks both exact-match keys AND suffix matching via `str_ends_with()`. This covers compound field names like `connectors_openai_api_key`, `stripe_secret_key`, `smtpPassword`, `oauthAccessToken`.

**@since annotation:** `@since 2.11.0` on `sensitive_field_keys()` (line 558) and `is_sensitive_key()` (line 501).

**Confirmed by:**
- `docs/archive/phase3-stash-minimization-spec.md` — explicitly documents suffix-based redaction as the implemented approach: "Redaction still begins with the existing exact-match sensitive key list. It now also redacts high-signal suffixes for compound field names..."
- `CHANGELOG.md` v2.11.0 entry: "Request stash minimization: ... redacts compound secret names by suffix"
- `REQUIREMENTS.md` Out of Scope table: "Suffix-based redaction already shipped (v2.10.2–v2.11.0); v4.0.0 only reconciles the stale doc status (ORG-06), not new code"

**Residual gap (what remains open after the suffix fix):**
The ROADMAP.md text mentions "provider-specific names (`clientSecret`, `refreshToken`, `authorization`)". The shipped SENSITIVE_KEY_SUFFIXES covers `_secret` and `secret` but NOT bare `clientSecret` if it's written as a camelCase key without any of the listed suffixes. Specifically, `authorization` (not `_authorization`) would NOT be matched by the current suffixes. This residual is small and the REQUIREMENTS explicitly mark new pattern-redaction build as out of scope. ORG-06 should update the ROADMAP to mark the suffix-based fix as complete while noting the camelCase residual.

**Corrected ROADMAP.md text should:**
1. Strike the "Request stash pattern-based redaction" item as completed (suffix-based shipped v2.11.0)
2. Note the residual gap (bare camelCase names without suffix separators) as a future enhancement if needed
3. Reference `class-request-stash.php` SENSITIVE_KEY_SUFFIXES as the canonical source

**Convention for llm-lies-log.md:** The ROADMAP claim that redaction "is exact-key based and misses provider-specific names" is stale documentation drift rather than a confabulation (the text predates the fix). Following the log's convention, this doesn't need a new entry — it's a status marker update, not a fabricated fact. However, if the correction surfaces any additional stale claims about redaction in other docs, those should be logged.

### ORG-07: Submission Checklist Findings

**What already exists:**
- `docs/archive/release-3.0.0-checklist.md` — a full release-execution checklist (version sync, CI gate, tag, publish, post-tag verification). Good precedent but scoped to git operations, not WordPress.org SVN.
- `docs/release-status.md` — canonical release state; pre-tag checklist reminder section at bottom (incomplete, references version sync)
- No `docs/wporg-submission-checklist.md` exists.

**Recommended home:** `docs/wporg-submission-checklist.md` — distinct from the existing release checklist because it covers WordPress.org-specific requirements (SVN layout, asset upload, readme validator, tag creation in .org SVN). The release checklist covers git tagging; the submission checklist covers the .org publication step.

**Content the checklist should cover:**
1. Pre-submission validation gate: readme.txt validator pass, Plugin Check CI green, version-sync verified (all 4 locations), screenshots current and matching captions
2. WordPress.org SVN layout: `trunk/` = current code, `assets/` = banner/icon/screenshots at repo root of SVN (NOT inside plugin folder), `tags/X.Y.Z/` = release snapshot
3. Asset specifications: banner-1544x500.png (max 1MB), banner-772x250.png (max 1MB), icon-128x128.png, icon-256x256.png, screenshot-N.png (max 10MB each, displayed at 850px wide)
4. First-submission requirements: plugin slug reservation, reviewer approval (can take days to weeks), no changes to .org SVN until approved
5. Version-sync gate (from CLAUDE.md): 4 locations must agree before tagging
6. Playground link update: update `readme.txt` blueprint link to reference new tag before first .org publication
7. 4.0.0 Changelog entry: add `= 4.0.0 =` section to readme.txt Changelog before submission
8. Post-submission: update `docs/release-status.md` publication status

## State of the Art

| Old Approach | Current Approach | Notes |
|--------------|------------------|-------|
| Manual readme.txt submission review | Plugin Check (PCP) CI + optional `thetwopct/wporg-plugin-readme-linter` | PCP is the official tool now; runs in this repo's CI already |
| Separate readme.txt and README.md | Single `readme.txt` for .org, `readme.md` for GitHub | This repo uses `readme.md` for GitHub; readme.txt for .org; appropriate split |
| SVN-only .org submission | Same SVN, but GitHub Actions can automate SVN deploy | Not in scope for Phase 14, but note for ORG-07 checklist |

## Open Questions

1. **Contributor username: `dpknauss` vs `danknauss`**
   - What we know: readme.txt `Contributors: dpknauss`; plugin header `Author URI: .../danknauss/`
   - What's unclear: Whether `dpknauss` is a valid, registered WordPress.org account
   - Recommendation: Verify manually at `https://profiles.wordpress.org/dpknauss/` before submission; update to `danknauss` if `dpknauss` is not a valid account

2. **Plugin Name header field**
   - What we know: `Plugin Name: WP Sudo` in header; `=== Sudo ===` in readme.txt; PCP warns on mismatch
   - What's unclear: Whether to update header to `Sudo` or document the divergence
   - Recommendation: Update `Plugin Name: WP Sudo` → `Plugin Name: Sudo` in `wp-sudo.php` for listing consistency. This appears in the WP admin Plugins list.

3. **Short description final text**
   - What we know: Must be ≤ 150 chars; current is 204 chars
   - Draft option: "WordPress security plugins guard the door. Sudo governs what happens inside — gating dangerous admin actions behind reauthentication." (135 chars)
   - Recommendation: Planner can specify the draft; executor trims and verifies with `echo -n "..." | wc -c`

4. **Site Health "WP Sudo" labels**
   - What we know: 14 Site Health strings use "WP Sudo" prefix; these appear in WordPress admin Site Health panel
   - What's unclear: Whether to update all to "Sudo" prefix (consistent branding) or keep "WP Sudo" for disambiguation
   - Recommendation: Update Site Health labels to use "Sudo" prefix since they're in a dedicated panel where context is clear

## Validation Architecture

> workflow.nyquist_validation is not explicitly set to false; treating as enabled.

This phase is primarily doc/asset editing (not TDD-able business logic). Verification strategy is a mix of automated grep assertions, tool-based validation, and explicit manual/human-review tasks.

### Test Framework

| Property | Value |
|----------|-------|
| Framework | No new test framework — uses existing `composer test:unit` + CLI greps + Plugin Check CI |
| Config file | `phpunit.xml` (existing), `.github/workflows/plugin-check.yml` (existing) |
| Quick run command | `grep -n "WP_SUDO_VERSION\|Stable tag\|Version:" wp-sudo.php phpstan-bootstrap.php tests/bootstrap.php readme.txt` (version sync check, < 1s) |
| Full suite command | `composer test:unit && composer lint` (confirms no PHP regressions from string edits) |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | Notes |
|--------|----------|-----------|-------------------|-------|
| ORG-01 | readme.txt short description ≤ 150 chars | automated | `awk 'NR==12' readme.txt \| awk '{print length}'` and assert < 151 | Wave 1 |
| ORG-01 | All required sections present | automated | Plugin Check CI (`plugin-check.yml`) runs on PR | Already in CI |
| ORG-01 | Stable tag consistent with plugin header | automated | Plugin Check CI (`stable_tag_mismatch` check) | Already in CI |
| ORG-01 | "What's new in X.Y.Z?" section removed/updated | human-review | `grep -n "What.s new in" readme.txt` returns 0 results (or updated) | Wave 1 |
| ORG-02 | Screenshots present and ≥ Jun 14 date (except screenshot-1) | manual-only | `ls -la .wordpress-org/screenshot-*.png` | Browser handoff required; gated on Phase 13.1 execution |
| ORG-02 | Screenshot captions match screenshot count | human-review | `grep -c "^\d\." readme.txt` matches screenshot file count | Post-capture check |
| ORG-03 | readme.md git status unchanged (no new case-collision) | automated | `git ls-files README.md readme.md` returns only `readme.md` | One-time verification |
| ORG-03 | Brand-string audit complete | human-review | `grep -rn "WP Sudo" --include="*.php" includes/ wp-sudo.php \| grep -E "_e\(|__\("` — reviewer confirms each string disposition | Wave 2 |
| ORG-03 | Product-name/slug divergence documented | human-review | `grep -n "wp-sudo\|product name\|text.domain" docs/developer-reference.md` shows added section | Wave 2 |
| ORG-04 | SECURITY.md states GitHub Issues stance explicitly | human-review | `grep -n "GitHub Issues\|public issue" SECURITY.md` shows both directives | Wave 3 |
| ORG-04 | SECURITY.md includes changelog convention | human-review | `grep -n "changelog\|Security:" SECURITY.md` shows convention section | Wave 3 |
| ORG-05 | All 4 version-sync locations agree | automated | Version sync grep command above returns 4 matching `4.0.0` values | Wave 4 |
| ORG-05 | License fields consistent across files | automated | `grep -E "License:" wp-sudo.php readme.txt composer.json` — all match | Wave 4 |
| ORG-05 | Contributor username verified | manual-only | Visit `https://profiles.wordpress.org/dpknauss/` to confirm account exists | Pre-submission |
| ORG-06 | docs/ROADMAP.md no longer lists pattern-based redaction as open | human-review | `grep -n "exact-key based\|pattern-based redaction" docs/ROADMAP.md` — line 42-44 updated or removed | Wave 5 |
| ORG-06 | ROADMAP.md cites class-request-stash.php as the verification source | human-review | Updated text references `SENSITIVE_KEY_SUFFIXES` or `class-request-stash.php` | Wave 5 |
| ORG-07 | docs/wporg-submission-checklist.md exists | automated | `ls docs/wporg-submission-checklist.md` returns the file | Wave 6 |
| ORG-07 | Checklist covers version-sync gate | human-review | Checklist file contains version-sync verification step | Wave 6 |
| ORG-07 | Checklist covers SVN trunk/tags layout | human-review | Checklist file contains SVN layout documentation | Wave 6 |

### Sampling Rate

- **Per task commit:** `composer test:unit` (confirms no PHP regressions) + the specific grep for the changed item
- **Per wave merge:** `composer test:unit && composer lint` + Plugin Check CI (auto-runs on PR)
- **Phase gate:** Plugin Check CI green + all human-review items checked by reviewer before `/gsd:verify-work`

### Wave 0 Gaps

No new test files required — this phase is doc/asset editing. The existing Plugin Check CI and unit test suite cover the automated checks. The specific grep commands in the test map above serve as acceptance checks; they are run by the implementer/reviewer, not as a new test file.

The one setup prerequisite: Phase 13.1 must fully execute (not just plan) before the ORG-02 browser/screenshot handoff begins.

## Sources

### Primary (HIGH confidence)
- Live `readme.txt` (root) — verified 2026-06-19: headers, sections, descriptions, screenshot captions, Changelog, Upgrade Notice
- Live `SECURITY.md` (root) — verified 2026-06-19: full file read
- Live `includes/class-request-stash.php` — verified 2026-06-19: SENSITIVE_KEY_SUFFIXES, is_sensitive_key(), @since annotations
- Live `docs/ROADMAP.md` lines 42-44 — verified 2026-06-19: the stale redaction claim
- Live `docs/archive/phase3-stash-minimization-spec.md` — verified 2026-06-19: shipped implementation documented
- Live `docs/release-status.md` — verified 2026-06-19: latest tagged release 3.4.0, current branch 4.0.0
- Live `wp-sudo.php` header — verified 2026-06-19: Plugin Name, Version, License
- Live `phpstan-bootstrap.php` and `tests/bootstrap.php` — verified 2026-06-19: WP_SUDO_VERSION
- `.github/workflows/plugin-check.yml` — verified 2026-06-19: PCP checks already in CI
- `git ls-files README.md readme.md` output — verified 2026-06-19: only readme.md tracked; same inode confirmed
- `ls -la .wordpress-org/` — verified 2026-06-19: screenshot dates confirmed
- `grep` output across `includes/` — verified 2026-06-19: all "WP Sudo" translated strings enumerated

### Secondary (MEDIUM confidence)
- [WordPress-Plugin-Readme-Parser/validator.php](https://github.com/markjaquith/WordPress-Plugin-Readme-Parser/blob/master/validator.php) — checked via WebFetch: fatal error conditions, warning conditions, character limits
- [thetwopct/wporg-plugin-readme-linter](https://github.com/thetwopct/wporg-plugin-readme-linter) — checked via WebFetch: validation rules, required sections, configurable options

### Tertiary (LOW confidence)
- WebSearch results re: WordPress.org readme.txt "150 characters" short description limit — confirmed by multiple sources including Gutenberg issue tracker #59494 and meta.trac.wordpress.org #7477; treat as HIGH given cross-source confirmation
- WebSearch results re: 5-tag limit — confirmed by multiple sources; treat as MEDIUM (couldn't access official handbook page directly)
- WebSearch results re: 300-char Upgrade Notice display limit — MEDIUM confidence (community-sourced, not verified against official validator source)

## Metadata

**Confidence breakdown:**
- ORG-01 findings: HIGH — all verified against live readme.txt + validator source code
- ORG-02 findings: HIGH — screenshot dates verified; sequencing constraint verified against CONTEXT.md
- ORG-03 findings: HIGH — git inode check confirmed; PHP string grep confirmed
- ORG-04 findings: HIGH — SECURITY.md read in full; gaps confirmed by absence
- ORG-05 findings: HIGH — all 4 version-sync locations checked by grep; license fields verified
- ORG-06 findings: HIGH — live code read; @since annotation; CHANGELOG confirmed; phase3-stash-minimization-spec.md confirmed
- ORG-07 findings: HIGH — directory listing confirmed no checklist exists; archive precedent read

**Research date:** 2026-06-19
**Valid until:** 2026-07-19 (stable domain; only changes if plugin code changes or WordPress.org updates validator rules)
