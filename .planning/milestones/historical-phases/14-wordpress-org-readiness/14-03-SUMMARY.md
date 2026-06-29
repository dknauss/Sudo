---
phase: 14-wordpress-org-readiness
plan: 03
status: complete
completed: 2026-06-21
requirements: [ORG-04, ORG-06, ORG-07]
---

# Plan 14-03 Summary — WordPress.org Readiness: Security, Redaction Status, Submission Checklist

Three independent documentation deliverables, no code changes.

## Tasks

| Task | Req | Commit | Result |
|------|-----|--------|--------|
| 1. Complete SECURITY.md | ORG-04 | `df440a5` | Reporting channel, supported-versions, GitHub-issues stance (NOT acceptable for initial contact), security-changelog `**Security:**` convention, CVE note. Existing content preserved. |
| 2. Reconcile redaction status | ORG-06 | (ROADMAP commit) | Corrected two stale "exact-key based" claims (ROADMAP lines ~42 and ~1740). |
| 3. Submission checklist | ORG-07 | (checklist commit) | New `docs/wporg-submission-checklist.md`; linked from CLAUDE.md docs index. |

## ORG-06 verification (per CLAUDE.md confabulation rules)

Re-read `includes/class-request-stash.php` on the live tree:

- `SENSITIVE_KEY_SUFFIXES` — `private const`, lines 68–97, **28 entries** (`_`-prefixed,
  `-`-prefixed, and bare-compound variants: `password`, `apikey`, `secretkey`,
  `privatekey`, `accesstoken`, `authtoken`, `apitoken`, `apisecret`).
- `is_sensitive_key()` — lines 531–545: lowercases the key, exact-matches against
  `sensitive_field_keys()`, then suffix-matches via `str_ends_with` over the 28 suffixes.
- `@since 2.11.0` is on `sanitize_params()` / `sensitive_field_keys()` — **not** on
  `is_sensitive_key()` (corrects the RESEARCH note).

**Exact residual wording used:** "camelCase provider names whose lowercased form ends in
no listed suffix — e.g. `clientSecret`, `refreshToken`, `authorization` — are still not
redacted, because the list omits bare `secret`/`token` to avoid false positives (e.g.
matching `page` → `password`)." Verified these three names are genuinely unmatched (the
list has `_secret`/`-secret`/`apisecret`/`secretkey` but no bare `secret`; `accesstoken`/
`authtoken`/`apitoken` but no bare `token`). New pattern-based redaction marked OUT OF
SCOPE per REQUIREMENTS.

**llm-lies-log entry:** intentionally omitted — RESEARCH §ORG-06 judged this status-drift
(a stale roadmap marker), not a fabricated fact; the plan made the log entry optional.

## Verification

- Task 2 check: `grep SENSITIVE_KEY_SUFFIXES docs/ROADMAP.md` → PASS
- Task 3 check: checklist exists, covers Stable tag / version + SVN trunk/tags → PASS
- All three commits docs-only; pre-commit hook auto-skipped reviewer + tests.

## Deviations

- Recovered from a mid-run sandbox disk-full (`ENOSPC`): SECURITY.md was written by the
  first executor attempt but uncommitted; committed on resume. No content lost.
