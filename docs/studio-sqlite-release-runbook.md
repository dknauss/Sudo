# Studio SQLite Release Runbook

This runbook is for release-time SQLite verification against a real WordPress Studio site.
It is intentionally manual and focused. The CI `E2E SQLite Smoke` workflow is useful breadth
signal, but Studio remains the better place to check SQLite-specific persistence and request
handling before a tag.

## When To Run It

Run this checklist before release when the touched code could plausibly affect:

- cookies or session persistence
- redirect and post-challenge landing behavior
- challenge rendering or browser-visible auth transitions
- request stash/landing behavior
- bootstrap timing or alternate persistence behavior under SQLite

## Preconditions

- A WordPress Studio site is running locally with SQLite enabled.
- The site is reachable on `http://localhost:<port>` or an equivalent Studio URL.
- The repo checkout is installed or synced into the Studio site's `wp-content/plugins/wp-sudo`.
- You know the admin username and password for the Studio site.

## Suggested Environment

Export the same variables the Playwright suite already expects:

```bash
export WP_BASE_URL="http://localhost:8881"
export WP_REQUEST_BASE_URL="$WP_BASE_URL"
export WP_USERNAME="admin"
export WP_PASSWORD="password"
```

If your Studio site uses a different port or credentials, change those values.

## Release Verification Steps

1. Confirm the plugin code on the Studio site matches the repo checkout.

```bash
SITE_PLUGIN="/absolute/path/to/studio-site/wp-content/plugins/wp-sudo" \
npm run local:plugin -- status
```

2. Run the alternate-stack smoke pack against the Studio site.

```bash
npm run test:e2e:stack-smoke
```

3. Run the default functional browser suite against the same Studio site if the change touches the challenge flow deeply.

```bash
npm run test:e2e
```

4. Spot-check the highest-risk manual flows in the browser:

- acquire a sudo session from the challenge page, then verify admin-bar
  deactivation clears it visibly before continuing
- with no active sudo session, submit a gated settings POST, pass the challenge,
  and confirm the save is **not** applied. Expect to land on the **dashboard**, not
  Settings: the intercepted URL is `options.php`, which
  `Challenge::HANDLER_ENDPOINTS` treats as a handler
  (`GB-OPTIONS-ALLSETTINGS`). Then navigate **explicitly** to Settings and
  re-submit — browser Back returns to the challenge page, not the form
- open a direct challenge URL carrying a `return_url` but no `stash_key`, complete
  the challenge, and expect the **dashboard**. With no server-recorded stash there
  is no originating-screen destination. `return_url` is **inert** since 4.9.0 (see
  `docs/release-status.md`), so treat navigation to its supplied destination as a
  regression rather than a pass

5. Record the Studio stack details used for the release check:

- WordPress version
- PHP version
- Studio site URL or port
- whether the plugin was synced or symlinked

## Notes

- Treat Studio as release-only assurance, not a merge gate.
- If `npm run test:e2e` is too heavy for the release window, `npm run test:e2e:stack-smoke`
  plus the manual spot checks above is the minimum SQLite-specific pass.
- If authentication looks stale, delete `tests/e2e/artifacts/storage-states/admin.json`
  and rerun the Playwright command.
