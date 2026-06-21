---
phase: 14-wordpress-org-readiness
plan: 01
status: complete
completed: 2026-06-21
requirements: [ORG-01, ORG-05]
---

# Plan 14-01 Summary — readme.txt + plugin header (WordPress.org compliance)

## Tasks

| Task | Req | Result |
|------|-----|--------|
| 1. readme.txt | ORG-01 | Title `=== Sudo ===` → `=== Sudo – Admin Action Gating ===`; short description rewritten (130 bytes, < 150); stale `= What's new in 3.2.0? =` subsection removed; tags refreshed (`reauthentication, access control, admin protection, multisite, security`). |
| 2. wp-sudo.php | ORG-05 | `Plugin Name: WP Sudo` → `Plugin Name: Sudo – Admin Action Gating` (byte-identical to the readme title → no `plugin_name_mismatch`). Version-sync and license asserted, not modified. |

## Naming decision (DEVIATION from plan + supersedes 14-02 note)

The plan's `key_links` and tasks specified **"Sudo Security"** as the header/title. **User decided "Sudo – Admin Action Gating"** instead (2026-06-21): gating dangerous admin actions is the fundamental function; reauthentication is the mechanism. This supersedes both the plan's "Sudo Security" and 14-02-SUMMARY's "header stays WP Sudo".

Verified against the live Plugin Check source why the alternatives fail:
- Bare **"Sudo"** — 4 latin letters, fails `plugin_header_unsupported_plugin_name` (needs ≥5).
- **"Sudo for WordPress"** — `Trademarks_Check` lists `wordpress` as a trademark slug; `FOR_USE_EXCEPTIONS` allows only `woocommerce` in the `for-X` form, so "for WordPress" is blocked.
- **"Sudo – Admin Action Gating"** — passes both (≥5 letters, no trademark). Source: `WordPress/plugin-check` trunk `Plugin_Header_Fields_Check.php`, `Trademarks_Check.php` (verified 2026-06-21).

UI brand stays "Sudo" (14-02). Text-domain/slug stay `wp-sudo`.

## Verification

- Short desc: `awk 'NR==12' readme.txt | wc -c` = 130 (< 151). ✓
- No "What's new in 3.2.0?" subsection. ✓
- Header == readme title (byte-identical). ✓
- Version-sync: header `Version`, three `WP_SUDO_VERSION` constants, readme `Stable tag` all `4.0.0`. ✓ (asserted, not bumped)
- License `GPL-2.0-or-later` consistent across wp-sudo.php / readme.txt / composer.json. ✓
- Did NOT touch: Changelog (no fabricated `= 4.0.0 =` entry — release-time task), Playground v3.4.0 blueprint link (current tag), Contributors (`dpknauss` vs Author URI `danknauss` — manual .org item).

## Consistency updates (same commit)

- `docs/developer-reference.md` — divergence section updated: header/listing name is now "Sudo – Admin Action Gating"; brand "Sudo"; do-not-shorten-to-"Sudo" note.
- `docs/wporg-submission-checklist.md` — brand/header note updated from the stale "Sudo Security"; added a **slug-lock caveat**: the directory derives the slug from the Plugin Name (`sudo-admin-action-gating`), so the slug must be locked to `wp-sudo` at submission to match the text-domain, or translations break.

## Open / deferred
- **Slug lock at submission** (ORG-07 checklist) — ensure `wp-sudo` slug despite the longer header.
- Plugin Check on PR #99 CI is the automated gate for ORG-01/ORG-05.
