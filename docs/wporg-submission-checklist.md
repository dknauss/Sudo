# WordPress.org Submission Checklist

A repeatable checklist for preparing and submitting **Sudo** to the WordPress.org
plugin directory. This document **describes the process — it does not perform the
submission.** Run it before each public release that targets WordPress.org.

> Canonical release-state source: [`docs/release-status.md`](release-status.md).
> Canonical counts source: [`docs/current-metrics.md`](current-metrics.md).
> Do not hardcode volatile versions/counts here — verify against those files.

---

## 1. Pre-submission validation (all must pass)

- [ ] **readme validator** — `readme.txt` passes the official WordPress.org readme
  parser: <https://wordpress.org/plugins/developers/readme-validator/>. Short
  description ≤ 150 characters; required headers present (`Contributors`,
  `Tags`, `Requires at least`, `Tested up to`, `Requires PHP`, `Stable tag`,
  `License`, `License URI`).
- [ ] **Plugin Check (PCP) green** — run the official Plugin Check plugin against a
  clean production build (the repo's `plugin-check` CI lane does this via `wp-env`).
  No `stable_tag_mismatch`, no `plugin_name_mismatch`, no errors.
- [ ] **Version-sync gate** — `WP_SUDO_VERSION` must be identical in all four code
  locations, and the `readme.txt` Stable tag must match the plugin header `Version`
  (per `CLAUDE.md` → *Version sync checklist*):
  1. `wp-sudo.php` — plugin header `Version:` line
  2. `wp-sudo.php` — `define( 'WP_SUDO_VERSION', … )` constant
  3. `phpstan-bootstrap.php` — `define( 'WP_SUDO_VERSION', … )` constant
  4. `tests/bootstrap.php` — `define( 'WP_SUDO_VERSION', … )` constant
  5. `readme.txt` — `Stable tag:` (must equal the header `Version`)
- [ ] **Test/analyse/lint green** — `composer test`, `composer analyse` (PHPStan
  level 6), and `composer lint` all pass.
- [ ] **Pre-release audit** — re-verify every external claim added/modified since
  the last tag against live sources; append findings to `docs/llm-lies-log.md`
  (per `CLAUDE.md` → *Pre-release audit*).
- [ ] **`Tested up to`** reflects the current latest stable WordPress release
  (see `docs/release-status.md` → *Package metadata rule*).
- [ ] **License agreement** — `License: GPLv2 or later` / `GPL-2.0-or-later` is
  consistent across `readme.txt`, the `wp-sudo.php` header, and `composer.json`.

## 2. Asset requirements

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
- [ ] **Caption parity** — the `readme.txt` `== Screenshots ==` list length and order
  exactly match the `.wordpress-org/screenshot-*.png` file set.

## 3. SVN repository layout

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

## 4. Brand / header note

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

## 5. Final "do not submit until" gate

Do **not** initiate the WordPress.org submission until **all** of the following hold:

- [ ] `docs/release-status.md` shows the intended version as the latest tagged release
  (i.e. the Git `vX.Y.Z` tag is cut, not just the runtime constant bumped).
- [ ] Sections 1–4 above are fully checked.
- [ ] The maintainer has explicitly decided to publish (publication is a one-way,
  outward-facing action: the slug is permanent and the listing is public).

---

*See also: [`docs/release-status.md`](release-status.md) (canonical release state)
and `CLAUDE.md` (version-sync + pre-release audit rules).*
