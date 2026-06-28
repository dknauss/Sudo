---
created: 2026-06-28T19:03:44.574Z
title: Add Patchstack 2FA compatibility target
area: docs
files:
  - docs/two-factor-ecosystem.md
  - docs/ROADMAP.md
---

## Problem

Patchstack Security includes a paid-feature TOTP 2FA flow that is not covered by WP Sudo's built-in WordPress/two-factor integration and was not previously listed in the 2FA ecosystem compatibility survey. Compatibility planning should not lose this target now that the plugin source has been checked.

Verified context from WordPress.org SVN trunk revision 3589135 on 2026-06-28: Patchstack enables 2FA hooks with `patchstack_login_2fa`, stores enrollment/secret state in `webarx_2fa_enabled`, `webarx_2fa_secretkey`, and `webarx_2fa_secretkey_nonce`, accepts login codes from `patchstack_2fa`, and validates local TOTP codes via `TokenAuth6238::verify()` in `includes/login.php`. Free-license mode returns early before registering the 2FA hooks, so end-to-end testing likely needs a paid Patchstack-enabled fixture.

## Solution

Track Patchstack as a second-tier 2FA bridge/manual compatibility target behind the upstream Two Factor lifecycle bridge. When the 2FA bridge milestone is planned, include Patchstack enrollment detection, local TOTP validation through Patchstack's own verifier, and profile 2FA enable/disable lifecycle gating in the compatibility matrix if a paid test fixture is available.
