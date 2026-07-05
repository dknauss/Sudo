# Sudo Lite specification

> **Status: illustrative design reference — not a committed product roadmap.**
> This document describes what a minimal action-gating tier *would* look like, and
> exists to keep WP Sudo honest about its fundamentals (see
> [`fundamentals-cross-check.md`](fundamentals-cross-check.md)). It is **not** a plan
> to ship a separate "Sudo Lite" plugin, and the layouts/rule sets below are a design
> foil, not a build order. A security review of the accompanying Psudo Lite reference
> found that a minimal reauthentication model is structurally hard to make secure —
> proof of *completed* reauth, surviving session-token rotation, and effect-level
> gating are the hard parts — and hardening it converges on WP Sudo itself. If a
> lightweight on-ramp is ever wanted, the recommended path is a **minimal WP Sudo
> preset/config**, not a second reauthentication implementation.

Sudo Lite is a deliberately small action-gating design derived from WP Sudo's core security model. It is not a replacement for WP Sudo; it is a minimal reference implementation and a recurring cross-check for whether the main project is preserving its fundamentals.

## Conceptual tiers (illustrative, not a shipping plan)

| Tier | Purpose | Primary scope |
|---|---|---|
| Psudo Lite | Reauthentication nudge | Selected interactive `wp-admin` page loads |
| Sudo Lite | Minimal real action gating | Small fixed set of dangerous admin actions |
| WP Sudo | Comprehensive hardening | Multi-surface action gating, replay, policy, integrations, backstops |

## Sudo Lite goals

Sudo Lite should preserve the highest-value security properties from WP Sudo while omitting nonessential UI and integrations:

1. Require fresh password confirmation before dangerous effects.
2. Bind the sudo grant to the current WordPress session token.
3. Keep the trust window short and explicit.
4. Gate actions, not just menu screens.
5. Fail closed for the small built-in rule set.
6. Prefer clear denial over unsafe replay on non-interactive surfaces.

## Non-goals

Sudo Lite does not attempt to provide:

- broad settings UI;
- admin bar countdown;
- Site Health integration;
- 2FA provider bridges;
- activity-log provider bridges;
- comprehensive REST/AJAX replay;
- CLI/cron/XML-RPC/application-password policy matrix;
- exhaustive third-party handler coverage;
- effect-level destructive-operation backstops beyond its tiny built-in rule set.

## Snippet / mu-plugin form

A snippet form may exist, but it should be treated as developer-facing code rather than a generic snippets-plugin paste:

```text
sudo-lite-mu.php
```

### Configuration

Constants:

```php
define( 'SUDO_LITE_DURATION_SECONDS', 300 );
define( 'SUDO_LITE_REQUIRED_CAPABILITY', 'manage_options' );
```

Filters:

```php
sudo_lite_duration
sudo_lite_required_capability
sudo_lite_rules
sudo_lite_challenge_message
```

### Required components

- Session grant stored in user meta and bound to `wp_get_session_token()` hash.
- Custom password challenge screen.
- Minimal failed-password rate limiting.
- Small callback-based rule registry.
- Interactive admin redirects to challenge.
- AJAX/REST deny responses with `sudo_required` and a `challenge_url`; no replay.

## Plugin form

Recommended maintainable layout:

```text
sudo-lite/
  sudo-lite.php
  includes/
    class-session.php
    class-challenge.php
    class-gate.php
    class-rules.php
  uninstall.php
```

A single-file build can be generated later if portability matters.

## Minimal built-in rule groups

Sudo Lite's default rules should be intentionally small:

- plugin activate, deactivate, delete, update, install;
- theme switch, delete, update, install;
- user delete;
- user creation or promotion to administrator;
- high-risk options saves;
- core update.

Single-site should be v1. Multisite can be a later explicit expansion.

## Challenge behavior

- Admin-styled password form.
- Nonce-protected submit.
- Capability check before accepting challenge.
- `wp_check_password()` against the current user.
- Generic failure message.
- Short lockout after repeated failures.
- Safe redirect back to the original request only after a valid grant.

## Surface behavior

| Surface | Sudo Lite behavior |
|---|---|
| Interactive admin | Redirect to challenge, then resume via safe redirect |
| AJAX | Return `sudo_required` JSON/error; no replay |
| REST | Return `sudo_required` response with `challenge_url`; no replay |
| CLI, cron, XML-RPC, app passwords | Out of scope unless explicitly added later |

## Relationship to WP Sudo

Sudo Lite is the reference baseline. WP Sudo may be broader and more configurable, but it should never lose the simple invariants Sudo Lite makes obvious.
