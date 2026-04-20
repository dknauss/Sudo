# 3.0.0 Release Blocker Audit + Exact Checklist

Last updated: 2026-04-20
Owner: release lead

This is the canonical execution checklist for cutting `3.0.0`.

---

## Blocker audit (must be green before tagging)

- [x] **Version sync is complete**
  - `wp-sudo.php` header version = `3.0.0`
  - `wp-sudo.php` constant = `3.0.0`
  - `phpstan-bootstrap.php` constant = `3.0.0`
  - `tests/bootstrap.php` constant = `3.0.0`
  - `readme.txt` stable tag = `3.0.0`

- [x] **Release narrative/docs are aligned for 3.0.0**
  - `CHANGELOG.md` includes 3.0.0 headline sections.
  - `readme.md` + `readme.txt` describe 3.0.0 milestone scope.
  - No “exploratory / not production-ready” messaging in public-facing release copy.

- [x] **Metrics drift resolved locally**
  - `docs/current-metrics.md` updated to current counts.
  - `composer verify:metrics` passes.

- [ ] **CI is fully green on the release candidate commit**
  - Watch all required checks on `main`.
  - No failed required jobs (including aggregators) at merge/tag point.

---

## Exact pre-tag checklist

Run from clean `main`:

1. `git pull --ff-only origin main`
2. `composer test:unit`
3. `composer lint`
4. `composer analyse`
5. `composer verify:metrics`
6. `composer test:integration`
7. `WP_MULTISITE=1 composer test:integration`
8. Confirm release docs are current:
   - `CHANGELOG.md` (`## 3.0.0`)
   - `readme.md`
   - `readme.txt`
   - `docs/release-status.md`
9. Confirm CI status for `HEAD` is green on GitHub.

If any item fails, stop and fix before tagging.

---

## Tag and publish checklist

1. Create tag:
   - `git tag -a v3.0.0 -m "Release v3.0.0"`
2. Push branch and tag:
   - `git push origin main`
   - `git push origin v3.0.0`
3. Create GitHub Release from `v3.0.0` using 3.0.0 changelog headlines.
4. Verify release page links:
   - changelog section
   - compare links
   - assets (if attached)

---

## Post-tag verification checklist

1. `git fetch --tags --force`
2. `git describe --tags --abbrev=0` returns `v3.0.0`
3. `docs/release-status.md`:
   - “Latest tagged release” reflects `3.0.0`
   - remove/adjust “next planned release” language as needed
4. Start `3.1.0` dev version planning only after release tag is confirmed.

