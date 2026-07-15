# WordPress.org Submission Checklist

A repeatable checklist for preparing and submitting **Sudo** to the WordPress.org
plugin directory. This document **describes the process — it does not perform the
submission.** Submission is intentionally delayed/on hold, but this checklist should stay ready to execute at any time. Run the pre-tag/core release gates before any public release tag, and run the WordPress.org-only gates only when the maintainer explicitly approves publication.

> Canonical release-state source: [`docs/release-status.md`](release-status.md).
> Canonical counts source: [`docs/current-metrics.md`](current-metrics.md).
> Release environment evidence source: [`docs/release-environment-log.md`](release-environment-log.md).
> Do not hardcode volatile versions/counts here — verify against those files.

---

## 1. Pre-tag/core release gates

These gates protect the GitHub/package release itself. They are required before a public tag or publication decision, whether or not WordPress.org upload/submission happens later.

- [ ] **Version-sync gate** — `WP_SUDO_VERSION` must be identical in all four code
  locations, and the `readme.txt` Stable tag must match the plugin header `Version`
  (per `CLAUDE.md` → *Version sync checklist*):
  1. `wp-sudo.php` — plugin header `Version:` line
  2. `wp-sudo.php` — `define( 'WP_SUDO_VERSION', … )` constant
  3. `phpstan-bootstrap.php` — `define( 'WP_SUDO_VERSION', … )` constant
  4. `tests/bootstrap.php` — `define( 'WP_SUDO_VERSION', … )` constant
  5. `readme.txt` — `Stable tag:` (must equal the header `Version`)
- [ ] **Playground blueprint pinned tag bumped** — `blueprint.json` installs the
  release via a tag-pinned zip (`…/archive/refs/tags/vX.Y.Z.zip`). Bump that URL to
  the new release tag so the "latest release" demo link stays reproducible.
  (`blueprint-main.json` tracks `main` and is left as-is. Per
  [`docs/ROADMAP.md`](ROADMAP.md), also create tag-pinned copies of
  `blueprint-recovery-mode.json` / `blueprint-user-switching.json` if those
  scenarios should demo against the fixed release rather than a moving branch.)
- [ ] **Unit tests green** — `composer test` passes.
- [ ] **Static analysis green** — `composer analyse` (PHPStan level 6) passes.
- [ ] **Coding standards green** — `composer lint` passes.
- [ ] **Canonical metrics verified** — `composer verify:metrics` passes before any docs reference current repository counts.
- [ ] **Translation template current** — `composer verify:i18n` passes. If it fails,
  run `composer i18n:make-pot`, review `languages/wp-sudo.pot`, and commit the
  updated template. This is a release/.org readiness gate; it is not currently a
  required pre-commit or GitHub status check.
- [ ] **Pre-release external-claim audit** — re-verify every external claim added or
  modified since the last tag against live sources; append findings to
  `docs/llm-lies-log.md` (per `CLAUDE.md` → *Pre-release audit*).
- [ ] **Changelog/readme/release-status sanity** — `CHANGELOG.md`, `readme.txt`, and
  [`docs/release-status.md`](release-status.md) agree on the intended version,
  release posture, and whether work is tagged or still unreleased on `main`.
- [ ] **Public screenshot refresh gate** — if the release changes any public UI,
  refresh or deliberately re-approve the README/readme/WordPress.org screenshot
  set before tagging. The next pre-release pass must include a current screenshot
  of the block-editor reauthentication UI (password modal over the editor, plus
  the 2FA/link-out fallback if that path is materially visible). Run
  `npm run screenshots` for the `.wordpress-org/` listing set, add any new
  non-listing README assets deliberately, and update `readme.txt` screenshot
  captions when the numbered listing set changes.
- [ ] **Editor-triggered sudo coverage documented** — document which block/site
  editor actions can actually trip a sudo challenge in the current release. Keep
  the distinction explicit: the visible default demo path is Block Directory
  plugin install/activate via `/wp/v2/plugins`; ordinary content/design saves
  (posts, pages, templates, template parts, global styles, navigation, media,
  widgets, fonts) are intentionally not gated. If new editor-reachable gated
  routes are added, update the demo instructions and `docs/ui-ux-testing-prompts.md`
  in the same release.
- [ ] **Playground UI demo link checked** — provide or refresh a Playground link
  that demonstrates the current front-end/block-editor reauthentication UI. The
  demo must land on a screen where a reviewer can trigger the challenge without
  extra setup, or the docs must state the one required action (for example:
  open the block inserter, search a Block Directory block, then install/activate
  it). Verify whether the Playground environment permits installing blocks from
  the editor; if it does not, record the blocker and keep an alternate demo path
  or screenshot.
- [ ] **Multisite/network-admin demo posture decided** — because Playground can
  stage multisite, decide before release whether to ship a dedicated multisite
  Playground blueprint/link for the network-admin context. If shipped, it should
  demonstrate a network-admin sudo challenge (for example a network plugin,
  network theme, site-management, or network settings action) and be listed in
  `CONTRIBUTING.md` and `docs/ui-ux-testing-prompts.md`. If deferred, record
  the reason and rely on the multisite Playwright/integration release gates.
- [ ] **Package metadata sanity** — `Tested up to` reflects the current latest stable
  WordPress release (see `docs/release-status.md` → *Package metadata rule*), and
  `License: GPLv2 or later` / `GPL-2.0-or-later` is consistent across
  `readme.txt`, the `wp-sudo.php` header, and `composer.json`.
- [ ] **Release environment matrix/log gate** — the manual matrix in
  [`tests/MANUAL-TESTING.md`](../tests/MANUAL-TESTING.md) has current completed
  evidence in [`docs/release-environment-log.md`](release-environment-log.md), or
  every deferred lane has owner, timing, follow-up/waiver, and blocker posture.

## 2. WordPress.org-only submission/upload gates

These gates are required only when publication is explicitly approved. WordPress.org publication remains delayed/on hold until that approval exists; do not perform SVN upload, asset upload, or final submission as part of ordinary release-readiness work.

### 2.1 Validation specific to the directory

- [ ] **readme validator** — `readme.txt` passes the official WordPress.org readme
  parser: <https://wordpress.org/plugins/developers/readme-validator/>. Short
  description ≤ 150 characters; required headers present (`Contributors`,
  `Tags`, `Requires at least`, `Tested up to`, `Requires PHP`, `Stable tag`,
  `License`, `License URI`).
- [ ] **Plugin Check (PCP) green against a clean production package** — run the
  official Plugin Check plugin against the package/SVN candidate, not a dirty dev
  tree. The repo's `plugin-check` CI lane does this via `wp-env`. No
  `stable_tag_mismatch`, no `plugin_name_mismatch`, no errors.

### 2.2 Listing asset requirements

WordPress.org listing assets live in the repo under `.wordpress-org/` and are
mirrored to the SVN `assets/` directory (they are **not** shipped in the plugin
zip). Sizes per the WordPress.org Plugin Handbook:

- [ ] **Banner** — `banner-772x250.{png,jpg}` and (hi-DPI) `banner-1544x500.{png,jpg}`
- [ ] **Icon** — `icon-128x128.{png,jpg}` and (hi-DPI) `icon-256x256.{png,jpg}`
  (an animated `icon.svg` is also permitted)
- [ ] **Screenshots** — `screenshot-1.png` … `screenshot-N.png`, numbered to match
  the order of the captions under `== Screenshots ==` in `readme.txt`. Each
  screenshot must show **current** UI (see Plan 14-04 for the capture procedure
  and the "recovery mode OFF during capture" rule).
- [ ] **Screenshot/caption parity** — the `readme.txt` `== Screenshots ==` list
  length and order exactly match the `.wordpress-org/screenshot-*.png` file set.

### 2.3 SVN repository layout/upload

WordPress.org hosts plugins in Subversion, not Git. Standard layout:

```
/trunk/            ← latest development of the published version (the plugin code)
/tags/X.Y.Z/       ← an immutable snapshot per released version; Stable tag points here
/assets/           ← banners, icons, screenshots (listing chrome; NOT in the zip)
```

- [ ] **trunk** holds the current plugin source; the released version is whatever
  `Stable tag` names under `/tags/`.
- [ ] **tags/X.Y.Z** is created per release and must match the Git tag contents.
- [ ] **assets/** receives the `.wordpress-org/` images. In this repo, `.wordpress-org/`
  is the source of truth; copy its contents into SVN `assets/` at submission time.
- [ ] **Zip vs repo-only** — the distributed plugin zip contains only runtime files
  (PHP, JS/CSS, `readme.txt`, languages). Exclude dev-only paths: `tests/`,
  `.planning/`, `.github/`, `docs/`, `.wordpress-org/`, `vendor/` dev tooling,
  `composer.*`, `phpstan*`, `psalm*`, `phpunit*`, lint configs. Mirror the
  `.distignore` / build-dist rules used by the `plugin-check` CI lane.

### 2.4 Slug-lock, brand, and header decision

- Product name: **Sudo**.
- WordPress.org **Plugin Name** header + readme title: **"Sudo – Admin Action Gating"**
  (UI brand stays "Sudo"). Bare "Sudo" fails Plugin Check's ≥5-latin-letter rule
  (`plugin_header_unsupported_plugin_name`); "WordPress" / "Sudo for WordPress" is
  trademark-blocked (`Trademarks_Check` — only `for woocommerce` is exempt). "Sudo – Admin
  Action Gating" passes both.
- Slug / text-domain: **`wp-sudo`** (immutable once published). ⚠️ The directory derives the
  slug from the Plugin Name at submission, which would yield `sudo-admin-action-gating`.
  **Lock the slug to `wp-sudo`** (matching the text-domain) at submission — request it
  explicitly if needed — or translate.wordpress.org translations will break.
  Pressship/Plugin Check currently reports the expected `trademarked_term` warning because
  the intended slug contains `wp`; treat this as a documented submission decision unless
  the maintainer chooses to rename the slug before first WordPress.org publication.
- GitHub repo: **`dknauss/Sudo`**.
- The intentional divergence between product name, Plugin Name header, slug, and repo
  is documented in [`docs/developer-reference.md`](developer-reference.md) (Plan 14-02).

### 2.5 Final publication approval

Do **not** initiate the WordPress.org submission until publication is explicitly approved and **all** of the following hold:

- [ ] `docs/release-status.md` shows the intended version as the latest tagged release
  (i.e. the Git `vX.Y.Z` tag is cut, not just the runtime constant bumped).
- [ ] The pre-tag/core release gates and all WordPress.org-only submission/upload gates above are fully checked.
- [ ] The maintainer has explicitly decided to publish now (publication is delayed/on hold until then; once submitted, the slug is permanent and the listing is public).

---

*See also: [`docs/release-status.md`](release-status.md) (canonical release state),
[`docs/release-environment-log.md`](release-environment-log.md) (release environment evidence),
and `CLAUDE.md` (version-sync + pre-release audit rules).*
