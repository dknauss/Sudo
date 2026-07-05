# Release E2E confidence

Before tagging a release, run the manual **Release Confidence E2E** GitHub Actions workflow.

This workflow is intentionally not part of the required pull request gate. It combines the existing high-confidence targets into one release-oriented entrypoint:

- Apache/wp-env full Playwright E2E suite.
- Nginx + MariaDB stack smoke.
- Nginx + MariaDB multisite stack smoke.
- WordPress Playground SQLite stack smoke.

Use this as the release-grade browser/runtime compatibility check after unit tests, linting, static analysis, and the normal PR E2E checks are already green.

## When to run

Run it before creating a release tag, and rerun it after any late change touching:

- challenge or replay flows;
- session cookies, expiry, or lockout behavior;
- admin, AJAX, REST, CLI, cron, XML-RPC, or multisite gating;
- wp-env, Playwright, Docker, nginx, or Playground infrastructure.

## Expected result

The final `Release Confidence E2E` job should pass. If one stack fails while the primary Apache/wp-env suite passes, treat it as a compatibility finding and decide explicitly whether to fix, document, or defer before tagging.
