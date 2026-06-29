---
created: 2026-06-28T19:03:44.574Z
title: Add Patchstack 2FA compatibility target
area: docs
files:
  - docs/two-factor-ecosystem.md
  - docs/ROADMAP.md
---

## Problem

Patchstack Security includes a paid-feature TOTP 2FA flow outside WP Sudo's automatic WordPress/two-factor integration. The Phase 19 ecosystem matrix now tracks it conservatively as a second-tier/manual-test target so source-inspection findings do not become runtime support claims.

Refreshed evidence in `docs/two-factor-ecosystem.md` points to WordPress.org SVN [`includes/login.php`](https://plugins.svn.wordpress.org/patchstack/trunk/includes/login.php), repository revision `3590474`, file revision `3433693` dated 2026-01-06, checked 2026-06-29. The source still shows `patchstack_login_2fa`, `webarx_2fa_enabled`, `webarx_2fa_secretkey`, `webarx_2fa_secretkey_nonce`, `patchstack_2fa`, and `TokenAuth6238::verify()`. Free-license mode returns before meaningful 2FA hook registration, so runtime behavior remains unverified without a paid Patchstack-enabled fixture.

## Remaining Work

Keep this todo pending until both unresolved actions are complete:

1. Acquire or provision a paid Patchstack-enabled fixture where 2FA hooks actually register.
2. Manually record challenge validation and profile/WooCommerce 2FA lifecycle tests against that fixture before considering any Patchstack-specific bridge code or support wording.

Do not move this todo to completed based on source inspection alone.
