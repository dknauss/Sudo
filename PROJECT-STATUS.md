# Project Status: Concluded Research Prototype

> **Do not deploy or evaluate WP Sudo as a security control.** Do not install it
> on production, staging, WordPress Playground, or any interactive site.
> Running the retained automated tests in an isolated, disposable environment
> with synthetic fixtures is permitted solely to reproduce the research record.

WP Sudo investigated whether a regular WordPress plugin could provide
ecosystem-wide action-gated reauthentication. The program is complete. Seven
verified high-severity bypasses showed that route enumeration and
post-submission interception cannot sustain that claim.

The repository is retained as a read-only research record. Its implementation
and tests are evidence, not a supported security product or an evaluation
package. The test-only exception above does not restore an installation or
usage path.

## Final result

- [`docs/finding.md`](docs/finding.md) states the architectural result and the
  narrower primitive WordPress core could provide.
- [`docs/audit-verification-record.md`](docs/audit-verification-record.md)
  records independent verification of all seven bypasses against WordPress 7.0.
- [`docs/post-mortem.md`](docs/post-mortem.md) explains why extensive testing
  and review did not detect predicate drift.
- [`docs/security-model.md`](docs/security-model.md) preserves the prototype's
  threat model and boundaries.

Every bypass presupposes an already-authenticated administrator session. None
is a new unauthenticated or low-privilege path. They nevertheless defeat the
specific barrier the prototype claimed to place in front of a compromised
administrator session.

## What remains useful

The constructive result is a core-facing design: declared effect identity,
vetoable effect execution, and server-held action-bound approval consumed once
at the effect.

The separate
[`dknauss/consequential-actions`](https://github.com/dknauss/consequential-actions)
repository remains a historical demonstrator and compact attack narrative for
the WordPress core discussion. Its registry/window/modal design is not a
successor production architecture and must not be read as one.

The relevant upstream lineage remains
[Core Trac #20140](https://core.trac.wordpress.org/ticket/20140), together with
the narrower account-change history cited by the retained core-proposal
documents. WP Sudo's result strengthens the argument for a core primitive; it
does not supply a patch ready for WordPress core.

## Releases and reports

Existing tags and releases are frozen research snapshots. No further package,
demo, compatibility, or security releases are planned. Public installation
affordances and release workflows have been removed.

The issue backlog is closed because the program has concluded, not because each
finding was remediated. Historical issues, pull requests, branches, tests, and
source citations remain part of the record.
