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

1. ~~Acquire or provision a paid Patchstack-enabled fixture where 2FA hooks actually register.~~ **Resolved 2026-07-05.** The maintainer supplied a legitimately licensed Patchstack Pro 2.3.6 copy, run in a local WP 7.0 + SQLite fixture with the plugin's TOTP 2FA enabled. With 2FA enabled on a licensed Pro install, the full 2FA hook stack registers and can be exercised offline (the fixture has no outbound network, so the plugin runs in its local mode).
2. Manually record challenge validation and profile/WooCommerce 2FA lifecycle tests against that fixture before considering any Patchstack-specific bridge code or support wording. **Partially done 2026-07-05:** runtime-verified offline that (a) the 2FA hooks register, (b) `TokenAuth6238::verify` accepts a generated TOTP and rejects a wrong one, and (c) the bridge path works end to end — detection via `webarx_2fa_enabled`, secret retrieval via the private `P_Login::tfa_get_secret()` (reflection), encrypted-secret storage + decrypt round trip, and validation of a code generated from that secret. See `docs/two-factor-ecosystem.md` → Patchstack "Runtime fixture (verified offline)". **Still not exercised:** the live `wp_authenticate` login-form challenge submission, the `profile.php` POST save flow, and the WooCommerce account-form lifecycle (WooCommerce not installed in the fixture).

## Status

Source inspection is confirmed first-hand against Pro 2.3.6, and the core runtime
path is validated offline — the target is **runtime-validated as bridgeable**, not
a shipped/supported integration. Remaining before this can close or turn into ship
code: the live challenge-form + profile-save + WooCommerce lifecycle runs, and a
decision on whether to write a dedicated WP Sudo↔Patchstack bridge (vs. keeping it
behind the upstream `WordPress/two-factor` lifecycle bridge). Do not claim shipped
Patchstack support until those land.
