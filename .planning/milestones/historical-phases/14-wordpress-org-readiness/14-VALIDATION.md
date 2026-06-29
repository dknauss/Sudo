---
phase: 14
slug: wordpress-org-readiness
status: approved
nyquist_compliant: true
wave_0_complete: true
created: 2026-06-19
---

# Phase 14 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Derived from `14-RESEARCH.md` § Validation Architecture.

This phase is doc/asset editing (not TDD-able business logic). Validation is a mix of automated grep/tool assertions, Plugin Check CI, explicit human-review acceptance checks, and one hard manual/browser handoff (ORG-02). There is no new test framework and no Wave 0 test scaffolding — `nyquist_compliant: true` because the work is configuration/docs (CLAUDE.md TDD carve-out), not business logic; automated checks reuse existing infra.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Existing `composer test:unit` + `composer lint` (regression guard for PHP string edits) + Plugin Check (PCP) CI |
| **Config file** | `phpunit.xml.dist`, `.github/workflows/plugin-check.yml` (existing) |
| **Quick run command** | `grep -n "WP_SUDO_VERSION\|Stable tag\|Version:" wp-sudo.php phpstan-bootstrap.php tests/bootstrap.php readme.txt` (version-sync, <1s) |
| **Full suite command** | `composer test:unit && composer lint` |
| **Estimated runtime** | unit ~4s; lint ~20s; PCP in CI ~2m |

---

## Sampling Rate

- **After every task that touches a PHP file:** `composer test:unit` (no regression from string edits)
- **After every wave:** `composer test:unit && composer lint`; Plugin Check CI auto-runs on the PR
- **Phase gate:** Plugin Check CI green + all human-review acceptance checks confirmed + ORG-02 screenshots captured (browser handoff) before `/gsd:verify-work`

---

## Per-Requirement Verification Map

| Req | Behavior | Type | Acceptance check |
|-----|----------|------|------------------|
| ORG-01 | Short description ≤150 chars | automated | `awk 'NR==12{print length}' readme.txt` < 151 |
| ORG-01 | Required sections present; Stable tag matches header | automated | Plugin Check CI (`stable_tag_mismatch` + section checks) green |
| ORG-01 | Stale "What's new in 3.2.0?" removed; 4.0.0 changelog/upgrade-notice sane | human-review | `grep -n "What.s new in" readme.txt` → 0; `= 4.0.0 =` present or documented as tag-time |
| ORG-02 | Current screenshots of live UI (incl. Access tab) | **manual/browser** | Browser handoff; **GATED on Phase 13.1 execution**; recovery mode OFF; shot list captured as `screenshot-N.png` |
| ORG-02 | `== Screenshots ==` captions match the file set | human-review | caption count == screenshot file count, post-capture |
| ORG-03 | No README.md/readme.md case collision | automated | `git ls-files README.md readme.md` → only `readme.md` (confirmed same inode; no action) |
| ORG-03 | UI strings read "Sudo" not "WP Sudo"; plugin-header name reconciled | human-review | `grep -rn "WP Sudo" includes/ wp-sudo.php` reviewed; each disposition decided |
| ORG-03 | Product-name vs slug vs repo divergence documented | human-review | section added in readme and/or `docs/developer-reference.md` |
| ORG-04 | SECURITY.md: reporting channel, supported-versions, GitHub-issues stance, security-changelog convention | human-review | `grep` confirms each element present |
| ORG-05 | All 4 version-sync locations + Stable tag agree | automated | version-sync grep returns matching values (currently clean at 4.0.0) |
| ORG-05 | License / License URI consistent across header, readme, composer | automated | `grep -E "License" wp-sudo.php readme.txt composer.json` consistent |
| ORG-05 | Contributor username (`dpknauss`) valid | manual-only | verify `profiles.wordpress.org/dpknauss` before submission |
| ORG-06 | docs/ROADMAP.md no longer claims pattern-based redaction is open; cites the code | human-review | redaction status updated, references `SENSITIVE_KEY_SUFFIXES`/`class-request-stash.php`; logged in `docs/llm-lies-log.md` |
| ORG-07 | `docs/wporg-submission-checklist.md` exists, covers version-sync gate + SVN trunk/tags layout + asset specs | automated + human-review | file exists; contains the required sections |

---

## Wave 0 Requirements

- No new test files; no framework install. Existing Plugin Check CI + unit suite + the grep acceptance checks above cover all automated verification.
- **One execution prerequisite (not a test):** Phase 13.1 must fully **execute** (not just plan) before the ORG-02 browser/screenshot handoff begins — the Access-tab UI changes there.

---

## Manual-Only Verifications

| Behavior | Req | Why Manual | Instructions |
|----------|-----|------------|--------------|
| Capture current UI screenshots | ORG-02 | Needs a running site + browser; this session cannot | Fresh browser-capable session (`claude-playwright`); recovery mode OFF; capture Settings tabs, Access tab (post-13.1), Session Activity widget, Request/Rule Tester → `.wordpress-org/screenshot-N.png` |
| Contributor username valid on .org | ORG-05 | External account state | Visit `profiles.wordpress.org/dpknauss` |
| Short-description / changelog wording quality | ORG-01 | Editorial judgment | Reviewer confirms ≤150 chars reads well and changelog reflects 4.0.0 |

---

## Validation Sign-Off

- [x] Each ORG requirement has an automated check, a human-review acceptance check, or a flagged manual task
- [x] ORG-02 correctly flagged manual + gated on Phase 13.1 execution + browser handoff (not autonomous)
- [x] Automated checks reuse existing infra (Plugin Check CI, unit suite, grep assertions) — no Wave 0 scaffolding needed
- [x] Sampling continuity: PHP-touching tasks run `composer test:unit`
- [x] `nyquist_compliant: true` (docs/config carve-out per CLAUDE.md; not business logic)

**Approval:** approved 2026-06-19
