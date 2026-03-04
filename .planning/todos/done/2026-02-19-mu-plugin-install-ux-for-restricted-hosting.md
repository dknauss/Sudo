---
created: 2026-02-19T22:02:19.667Z
title: MU-plugin install UX for restricted hosting
area: ui
files:
  - includes/class-admin.php
  - wp-sudo-mu.php
---

## Problem

The Settings > Sudo page has a one-click "Install" button to copy the mu-plugin (`wp-sudo-mu.php`) into `wp-content/mu-plugins/`. Most managed WordPress hosts (WP Engine, Kinsta, Flywheel, Pressable, WordPress.com Atomic, etc.) restrict or block PHP write access to `wp-content/mu-plugins/`. On these environments the install silently fails or throws an error with no guidance on what to do instead.

This affects the majority of WordPress hosting environments. Users on restricted hosts have no path to installing the mu-plugin without external guidance.

## Solution

1. **Writable detection** — On the settings page, check `is_writable( WPMU_PLUGIN_DIR )` before showing the Install button. If not writable, show manual instructions instead of the button.

2. **Manual copy instructions** — When mu-plugins is not writable, display clear instructions: "Copy `wp-content/plugins/wp-sudo/wp-sudo-mu.php` to `wp-content/mu-plugins/wp-sudo-mu.php` via SFTP, your host's file manager, or your deployment pipeline."

3. **Error handling** — If the write attempt fails (e.g., user clicks Install before detection runs, or permissions change), show a clear admin notice explaining why it failed and linking to docs with manual steps.

4. **Docs** — Add a "MU-Plugin Installation" section to the FAQ or developer reference covering the manual path.

Candidate for v2.5 or v2.4.x — outside current v2.4 milestone scope.
