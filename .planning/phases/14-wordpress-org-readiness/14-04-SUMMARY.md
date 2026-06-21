---
phase: 14-wordpress-org-readiness
plan: 04
status: complete
completed: 2026-06-21
requirements: [ORG-02]
---

# Plan 14-04 Summary — WordPress.org listing screenshots (ORG-02)

Captured all seven `.wordpress-org/screenshot-{1..7}.png` from the live wp-env dev
site via the (reworked) deterministic Playwright spec — replacing the manual
browser-handoff step. Order matches readme.txt `== Screenshots ==` captions 1–7
exactly.

| # | Caption | Source |
|---|---------|--------|
| 1 | Challenge page | `admin.php?page=wp-sudo-challenge`, `#wp-sudo-challenge-card` |
| 2 | Settings tab | `options-general.php?page=wp-sudo-settings&tab=settings` |
| 3 | Gated Actions tab | `&tab=actions` |
| 4 | Rule Tester tab | `&tab=tester` |
| 5 | Access tab (post-13.1) | `&tab=access` — user-picker + grant/revoke UI |
| 6 | Dashboard widget | `index.php`, `#wp_sudo_activity` |
| 7 | Break-glass recovery notice | settings page with `WP_SUDO_RECOVERY_MODE` on |

## Spec rework (tests/e2e/specs/capture-screenshots.spec.ts)

The original spec produced **5** shots in the **wrong order** (would have corrupted
captions 1–4 and skipped 6–7). Reworked to all 7 in readme-caption order. Two
non-obvious fixes:

- **Recovery notice (#7):** toggles `WP_SUDO_RECOVERY_MODE` via `wp-env run cli wp config
  set … --raw` inside a `try/finally` so the constant is always removed (verified clean
  after the run). PHP **opcache** serves a stale wp-config for up to
  `opcache.revalidate_freq` seconds, so the notice misses the first load — added a
  reload-poll loop until `.wp-sudo-notice` (text "recovery mode is active") renders.
- Challenge page uses `admin.php?page=wp-sudo-challenge` (its slug), distinct from the
  settings page's `options-general.php?page=wp-sudo-settings` (`add_options_page`).

## Verification

- `npm run screenshots` → 1 passed (all 7 written); recovery mode confirmed removed
  (`wp config get WP_SUDO_RECOVERY_MODE` → not set).
- Visual check (read the PNGs): #1 challenge interstitial ✓, #5 post-13.1 Access tab
  (grant/revoke + capability dropdown) ✓, #7 recovery banner atop settings ✓.
- Sent all 7 to the maintainer for final sign-off.
- readme captions already match the order — no caption edits required.

## Environment notes (one-time)

- A different wp-env instance held ports 8888/8889; stopped it (with maintainer OK) so
  this repo's wp-env could bind. `wp-sudo` plugin auto-activated (`plugins: ["."]`).
- `npx playwright install chromium` was needed (browser binary missing).

## Result

ORG-02 satisfied. **All of Phase 14 (ORG-01…07) is now complete on the branch.**
