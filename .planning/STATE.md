---
gsd_state_version: 1.0
milestone: post-v4.8.0 — documentation & roadmap restructure (docs/CI only, no version bump)
last_updated: "2026-07-25"
status: v4.8.0 released 2026-07-23. Post-release `main` adds one small feature (the role/capability-drift alert-bridge push) plus documentation and CI tooling; nothing is version-bumped. This file is a point-in-time pointer, not a second source of truth — use the canonical sources below.
---

# Planning State

> **Point-in-time snapshot, not authoritative.** Live state (release, branch, PRs,
> backlog) lives in the canonical sources below — consult them rather than trusting a
> hand-maintained snapshot here. A stale STATE file is worse than none, so this file is
> kept deliberately small and pointer-first.

## Canonical sources

- **Current release / unreleased-on-`main`:** `docs/release-status.md`
- **Forward roadmap (Now / Next / Later / Non-goals):** `docs/ROADMAP.md`
- **Backlog / open work:** GitHub issues — <https://github.com/dknauss/Sudo/issues>
- **Shipped history:** `CHANGELOG.md`
- **Archived GSD milestone history:** `.planning/MILESTONES.md`
- **Live facts:** `git log`, `gh pr list`, `gh issue list`

## Snapshot (2026-07-25)

- **Latest release:** `v4.8.0` (2026-07-23) — REST-gate security hardening plus the
  opt-in role/capability lockdown-audit MVP. Nothing past the tag is version-bumped.
- **Since v4.8.0 on `main` — one small feature plus documentation + CI tooling:** the
  alert-bridge role/capability-drift push (backward-compatible addition to the optional
  bridge, #226); the **ROADMAP restructure** (forward-only Now/Next/Later; design essays
  promoted to standalone docs; the feature backlog migrated to GitHub issues #238–#257);
  and the **persistent-options metrics gate** (`bin/scan-persistent-options.php` +
  `composer verify:metrics`). PRs #236/#237 merged; the Pass-3 roadmap trim (#258) and
  the accuracy fixes tracked here may still be open — check `gh pr list`.
- **No feature track is active.** The next candidate work is the filed backlog issues;
  `docs/ROADMAP.md` (Now/Next) lists the near-term ones — notably #219 (network-wide
  role/capability lockdown sweep).
- **WordPress.org submission** is intentionally delayed/on hold; keep
  `docs/wporg-submission-checklist.md` ready.
