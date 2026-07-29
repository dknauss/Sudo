# Planning Directory Status

This directory contains **working planning material and a small amount of historical context that still has live references**. Unless a file explicitly says it is current, treat `.planning/` as non-canonical context.

For current facts, use:

- `docs/current-metrics.md` — current counts and CI/test matrix snapshot
- `docs/release-status.md` — tagged/package release state, WordPress.org publication status, post-tag `main` drift, and WordPress forward-lane posture
- `docs/ROADMAP.md` — current public roadmap and forward planning

Completed execution records belong in Git history rather than the checked-out
tree. Retain historical files here only while another checked-in document still
references them; remove the reference or record before deleting the target.

Why this note exists: the repo has evolved faster than some milestone-era
planning documents, so older files may still mention stale counts, release
dates, or already-completed milestones. Do not rely on them for current release
or compatibility claims.
