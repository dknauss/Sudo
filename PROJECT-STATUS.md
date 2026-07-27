# Project Status: Research Prototype

> **Do not install WP Sudo on production, public staging, or any site
> containing real users, credentials, or data.**

WP Sudo is a conceptual research project and security-design demonstrator. It
exists to investigate action-gated reauthentication in WordPress, exercise
candidate designs, expose failure modes, and provide evidence for narrower
changes that may be appropriate for WordPress core. It is not a supported
production security plugin.

## Appropriate evaluation environments

Use WP Sudo only in:

- WordPress Playground;
- a disposable local WordPress installation; or
- an isolated automated test environment containing synthetic data and
  credentials.

Do not use it on:

- a production or publicly reachable site;
- a staging or development site cloned from production;
- a site containing real users, personal data, secrets, API credentials, or
  reusable passwords; or
- a site where WP Sudo is expected to provide a security boundary.

## Why this boundary exists

Action gating spans many WordPress execution surfaces, request shapes,
extensions, and authentication paths. The project has repeatedly found that a
design can look sound, pass broad automated test suites, and survive multiple
reviews while still containing a reachable path outside the reviewed model.
Version 4.9.0 removed server-side automatic stash/replay after it proved to be
a confused deputy. That discovery is useful research evidence, but it is also
evidence that this plugin must not be relied upon as a comprehensive or
non-bypassable control.

Known limitations include:

- interception covers enumerated WordPress paths and selected effect-level
  backstops, not every effect reachable through WordPress or third-party code;
- an extension can perform a consequential effect through a path the plugin
  does not observe;
- some features are deliberately default-off or demonstrations of possible
  policy rather than complete controls;
- UX and behavior can differ across classic admin, REST, AJAX, multisite,
  editors, command-line, scheduled, and third-party surfaces;
- tests establish the cases they exercise, not universal gate completeness;
  and
- open security, coverage, compatibility, and UX findings are expected in an
  active research codebase.

See [`docs/security-model.md`](docs/security-model.md) for the detailed threat
model and [GitHub Issues](https://github.com/dknauss/Sudo/issues) for current
findings.

## What releases mean

Tags and GitHub releases are reproducible research snapshots for demos,
comparison, and review. They are not production-readiness declarations and do
not carry production support or compatibility guarantees. Publish a future
snapshot only when it materially supports the conceptual project or an
upstream WordPress core proposal—not merely because repository work has
accumulated. Every such snapshot must be a prerelease named **Research
Preview** while this status remains in effect. Pushing a tag does not publish
a release; publication requires a separate, deliberate manual workflow run
for that existing tag.

## Security reports

Security reports are welcome because failures improve the research and its
upstream recommendations. Follow [`SECURITY.md`](SECURITY.md). A fix, green CI,
or closed report does not change this project status.

## Graduation

Production use is out of scope unless the maintainer explicitly changes this
document after defining and satisfying separate production-readiness criteria.
Version numbers, test counts, review volume, and the absence of a currently
known exploit do not imply graduation.
