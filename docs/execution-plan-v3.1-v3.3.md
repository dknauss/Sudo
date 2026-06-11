# Execution Plan (v3.1–v3.3)

*Status: active planning, April 20, 2026. Revised April 20, 2026 to reflect the clean-launch framing for internal admin governance (no public install base, strict-from-day-one in v3.1 rather than three-phase migration). Revised June 7, 2026 to put post-v3.1.3 security-review remediation ahead of lower-priority feature work. Revised June 8, 2026: ultracode security audit complete — 27 confirmed findings (2 HIGH, 14 LOW, 11 INFO); 2 HIGH + A3 + C1 remediated in commit 3531e0d; full finding register appended below.*

This plan organizes open roadmap/backlog work into a prioritized sequence for
execution after v3.0.0.

---

## Security Audit Finding Register (June 8, 2026)

**Audit method:** 52-agent ultracode fan-out — Recon → Find (10 dimensions) →
Adversarial Verify → Harden (4 lenses) → Synthesize. 27 confirmed findings:
2 HIGH, 14 LOW, 11 INFO.

**Fixed in commit `3531e0d` (June 8, 2026):** F1, F2, F10 (persisted-op
fail-safe), F18c (BOM), plus two bonus fixes: block-string escape and
`safe_preg_match` fail-closed for built-in rules (C1).

Legend: ✅ Fixed | ⚠️ Open | ℹ️ Open / low priority

### HIGH — Active gate bypasses (exploitable without shell access)

**F1 — GraphQL CR-comment tokenizer bypass** ✅ Fixed `3531e0d`
`includes/class-gate.php` (line 1515 post-fix)
The `wpgraphql_document_contains_mutation()` comment-skip loop terminated
only at LF (`\n`). A bare carriage return (`\r`) is also a GraphQL spec
line terminator, so `# x\rmutation { deleteUser }` was invisible to the
scanner — `json_decode` accepted `\r` in a JSON string, the tokenizer
stopped the comment only at the next `\n`, and the mutation token at the
beginning of the following "line" was swallowed. **Fix:** loop also stops
at `\r`; CRLF handled naturally. Regression tests added for CR, CRLF, and
pass-through cases.

**F2 — REST plugin gate misses folder-based plugins** ✅ Fixed `3531e0d`
`includes/class-action-registry.php` (lines 84, 100, 118 post-fix)
The three plugin REST matchers (`plugin.activate`, `plugin.deactivate`,
`plugin.delete`) used `#^/wp/v2/plugins/[^/]+$#`. WordPress core's plugin
route parameter is `[^.\/]+(?:\/[^.\/]+)?` — a folder plugin like
`akismet/akismet` carries a literal `/` that `[^/]+` cannot match. The
route returned no match; `intercept_rest()` returned the original response
before any session or App-Password policy check. For the **majority of
real plugins** the REST reauth gate was silently off. **Fix:**
`#^/wp/v2/plugins/[^/]+(?:/[^/]+)?$#`. Folder-plugin tests added.

---

### LOW — Real security gaps, bounded exploitability

**F3 — WP Sudo's own settings option not gated on non-interactive surfaces** ⚠️ Open
`includes/class-gate.php:525-539`
`pre_update_option_wp_sudo_settings` is not registered; a CLI or Cron
actor can flip policies to Unrestricted with no sudo block and no audit
event. Remote path does not exist (XML-RPC whitelists exclude this option).
**Fix:** Register `pre_update_option_wp_sudo_settings` (and the multisite
`pre_update_site_option_*` path); fire the tamper/audit hook on block.
*Roadmap: B4 quick-win.*

**F4 — Stash secret redaction uses exact full-key match, misses compound names** ✅ Fixed (unreleased)
`includes/class-request-stash.php`
Redaction is opt-out by exact lowercased key (14 entries). Compound names
like `connectors_openai_api_key`, `smtp_password`, `stripe_secret_key`
are not redacted. The docs promise redaction of `connectors_*_api_key`
patterns (security-model.md:418) — a direct documentation inconsistency.
**Fix:** Field keys now redact on high-signal exact names and suffixes,
including snake_case, dashed, camelCase, nested, and array secret keys.
Automatic POST replay is blocked when any secret field was omitted.
Residual backlog: cancelled challenges still rely on the 5-minute stash TTL.

**F5 — `admin_email` change not challenge-gated on interactive/REST surfaces** ⚠️ Open
`includes/class-action-registry.php:407-431`
Core's General Settings submits as `new_admin_email`, not `admin_email`,
so the interactive `options.critical` matcher never fires. The actual
write via the `adminhash` GET confirmation link also fails the matcher
(requires `action=update` + POST). Only CLI/Cron/XML-RPC are gated.
`admin_email` retargeting is a recognized password-reset account-takeover
precursor. **Fix:** Add `new_admin_email` to critical names; and/or
register `pre_update_option_admin_email` on the interactive surface.
*Roadmap: B5 quick-win.*

**F6 — Per-IP lockout shared across all users, enabling authenticated DoS** ⚠️ Open
`includes/class-sudo-session.php:362-372, 852-863, 931-945`
IP lockout transient keyed solely on `hash('sha256', $ip)`. Any
logged-in user with 5 failed attempts locks every admin sharing the egress
IP (NAT/CGNAT/office/VPN) for 300 s, indefinitely sustainable. Fails
closed (never bypasses auth). **Fix:** Key lockout by
`hash(ip + '|' + user_id)`, or keep IP bucket for monitoring only.

**F7 — 2FA submission path does not check per-IP lockout** ⚠️ Open
`includes/class-challenge.php:506-536`
`attempt_activation()` checks both per-user and per-IP lockout;
`handle_ajax_2fa()` checks only per-user. An active IP lockout is honored
at the password step but ignored at 2FA entry — one extra attempt leaked
per already-pending account. **Fix:** Mirror the password path —
call `is_ip_locked_out()` at `handle_ajax_2fa()` entry.

**F8 — Session/2FA cookies use `is_ssl()` only, no FORCE_SSL fallback or filter** ⚠️ Open
`includes/class-sudo-session.php:449, 572, 733, 747, 811`
All five Set-Cookie sites gate the Secure flag on `is_ssl()`. Behind a
TLS-terminating proxy that speaks plain HTTP to PHP (without
`X-Forwarded-Proto` correction) the session-binding cookies are emitted
without Secure. No `FORCE_SSL_ADMIN` fallback and no override filter.
`HttpOnly` + `SameSite=Strict` are correctly set at all five sites.
**Fix:** Centralize cookie-args; default `secure` to
`is_ssl() || (defined('FORCE_SSL_ADMIN') && FORCE_SSL_ADMIN)`;
add `wp_sudo_cookie_secure` filter.

**F9 — Stash captures full `$_POST`/`$_GET` indiscriminately** ✅ Fixed (unreleased)
`includes/class-request-stash.php`
`save()` snapshots the complete superglobals and subtracts only the
exact-match secret list — structural root that makes F4 exploitable.
`$_GET` is persisted for POST-method actions too. `security-model.md:309`
claims the stash "stores only the request metadata needed for replay"
which overstates minimality. **Fix:** Request stashes no longer persist
`$_GET`; POST replay stores only rule allowlisted fields. Built-in unsafe
POST flows such as uploads, editors, and broad network settings are marked
non-replayable and return the operator to resubmit after reauthentication.

**F10 — Persisted-query/APQ mutations evade the tokenizer** ✅ Fixed `3531e0d`
`includes/class-gate.php` (`body_has_persisted_operation()` added)
A persisted-query body (`queryId` / `extensions.persistedQuery.sha256Hash`)
carries no inline text; the extractor yielded nothing; the request passed
ungated in Limited mode. **Fix:** Detect persisted-query indicators and
treat as mutations by default; classifier filter overrides for read-only
persisted ops.

**F11 — WPGraphQL gate reads only `php://input`, ignores GET `query` param** ⚠️ Open (partial)
`includes/class-gate.php:1493-1496`
`gate_wpgraphql()` classifies solely from the POST body. WPGraphQL accepts
the document via GET; `php://input` is empty for GET → no documents → not
a mutation. Mitigated today because webonyx's `readOnly=true` on GET
requests causes graphql-php to throw `GetMethodSupportsOnlyQueryOperation`
for mutations. Disabled policy blocks GET unconditionally. **Fix:** On GET,
also feed `$_GET['query']` into `check_wpgraphql()`; or assert POST before
trusting an empty body. Document the dependency.

**F14 — Per-App-Password policy override accepts unvalidated UUID keys** ✅ Fixed (unreleased)
`includes/class-admin.php`
`handle_app_password_policy_save()` writes an attacker-supplied `uuid` as
an array key after only `sanitize_text_field()` — no format check, no
existence check against `WP_Application_Passwords`, no per-key cap, no
cleanup on revocation. Fully gated (nonce + `manage_options` + active
sudo); real impact is option bloat / orphaned entries. **Fix:** Validate
UUID format and ownership/existence before persisting; require `edit_user`
for cross-user profile saves; clean per-App-Password overrides when the
password is deleted.

**F15 — Active-sessions edit link not gated by `edit_user` cap** ⚠️ Open
`includes/class-dashboard-widget.php:204-211`
Per-user profile-edit link emitted unconditionally; Recent Events table
checks `current_user_can('edit_user', $id)`. Not privilege escalation —
widget is `manage_options`-gated; matters on multisite where a site admin
holds `manage_options` but cannot edit arbitrary network users. **Fix:**
Only emit the `<a href>` when `current_user_can('edit_user', $user_id)`.

**F18a — 2FA OTP resend bypasses rate limiting (email provider only)** ⚠️ Open
`includes/class-challenge.php:544-546`
`handle_ajax_2fa()` returns `2fa_resent` before any throttle accounting,
enabling OTP email flooding via the Two Factor Email provider. WP2FA
bridge does not participate in this path. **Fix:** Write a ~30-60 s
`THROTTLE_UNTIL` transient on each resend.

---

### INFO — Hardening opportunities, no active exploitability

**F12 — CLI cron-policy guard keys on literal `cron` in argv** ℹ️ Open
`includes/class-gate.php:252-269`
`enforce_cron_policy_on_cli()` only fires when `'cron'` appears in argv.
`wp eval 'do_action("some_cron_hook")'` bypasses the coarse guard — but
all gated state changes inside that callback are still blocked by the
function-level hooks. No gated action escapes; the "cron is Disabled"
convenience guard is just coarse. CLI is an accepted trusted boundary.

**F13 — `wp sudo status` reports "active" via expiry-time, not token binding** ℹ️ Open
`includes/class-cli-command.php:45-58`
`status()` decides "active" from `time_remaining()` (reads only
`_wp_sudo_expires`, no token verification), whereas the real gate uses
`is_active()` → `verify_token()`. CLI can report "active" for a session
the gate would reject. No access-control impact. **Fix:** Reword output:
"expiry is in the future (NNs); token binding not checkable from CLI."

**F16 — Inline i18n JSON in `<script>` relies on incidental slash-escaping for XSS safety** ℹ️ Open
`includes/class-dashboard-widget.php:896-1337`
`wp_json_encode()` (flags=0) echoed raw inside `<script>`. `<`/`>` not
escaped (no `JSON_HEX_TAG`), but PHP's default `\/` escaping neutralizes
`</script>`. Safety is incidental, not explicit. Values are static `__()`
strings. **Fix:** Use `wp_add_inline_script()` or add
`JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`.

**F17 — `grant_session_on_login` grants sudo on every `wp_login`** ✅ Fixed (unreleased)
`includes/class-plugin.php:309-330`
Intentional design (a password challenge adds no barrier seconds after a
password login). Two claims in the original entry were wrong and are
corrected here: the behavior does **not** mirror Unix sudo (its credential
cache starts at the first `sudo` invocation, not at login), and `wp_login`
does **not** fire after 2FA — the Two Factor plugin hooks `wp_login` at
`PHP_INT_MAX` (verified against live source, class-two-factor-core.php
line 123), so the priority-10 grant runs before second-factor
verification and is password-strength only. Residual: any code firing
`do_action('wp_login')` (SSO plugins, programmatic logins) grants sudo —
for passwordless SSO users this is also what keeps gated actions reachable.
**Fixed:** `wp_sudo_grant_session_on_login` opt-out filter (name differs
from the `wp_sudo_grant_on_login` proposed above; chosen to match the
method name) with integrator documentation.

**F18b — Correct password during 2fa_pending orphans prior pending transients** ℹ️ Open
Each correct-password submission mints a new `2fa_pending` transient
without clearing the old; httponly nonce makes it non-exploitable.
**Fix:** Call `clear_2fa_pending()` at the start of the 2FA branch.

**F18c — UTF-8 BOM prefix defeats JSON extractor** ✅ Fixed `3531e0d`
`\xEF\xBB\xBF` prefix caused `json_decode()` failure; raw-scan fallback
missed mutations at depth ≥1. **Fix:** `strip_leading_bom()` called before
`json_decode()` in both `extract_wpgraphql_documents()` and
`body_has_persisted_operation()`.

**F18d — `wp_sudo_gated_actions` filter can wholesale-replace the rule set** ✅ Fixed (unreleased)
A buggy third-party callback that replaces instead of appends silently
removes all gating. **Fix:** Built-in removal remains supported for
compatibility, but WP Sudo now emits
`wp_sudo_gated_actions_missing_builtin_rules` and exposes a Site Health
warning when core rule IDs disappear post-filter.

**F18e — `uninstall.php` no explicit cap check (belt-and-suspenders)** ✅ Fixed (unreleased)
Not exploitable — core gates uninstall behind capability+nonce. Optional
`current_user_can()` assertion for defense-in-depth. **Fix:** Browser/admin
uninstall now requires `delete_plugins`; WP-CLI uninstall remains allowed
through the CLI/core authorization path.

**F18f — `Public_API::check()` relies on `verify_token()` internals for cross-user isolation** ℹ️ Open
Correct today; add an explicit `$user_id !== get_current_user_id()` guard
at the API boundary to harden against future fast-path refactors.

**F18g — Plain-HTTP installs apply policy without Secure cookies** ℹ️ Open (subsumed by F8)
Documented HTTP boundary. Add a non-SSL admin notice. Subsumed by the F8
centralized-cookie-args fix.

---

### Bonus fixes included in `3531e0d`

**Block-string escaped-triple-quote desync** ✅ Fixed `3531e0d`
The tokenizer ended a block string at the first `"""`, ignoring `\"""`
(escaped triple-quote continuation). A crafted `"""x \""" }}}}"""` block
string could mask a following top-level `mutation` token. The escaped
triple-quote is a real correctness gap even though graphql-php's
`LoneAnonymousOperation` rule makes the current bypass path impractical.
Fixed per spec; regression test added.

**`safe_preg_match` fail-closed for built-in rules (C1)** ✅ Fixed `3531e0d`
A PCRE error on a built-in rule silently returned `false` (not matched →
not gated). For built-in rules a broken pattern should gate the request
(fail-closed), not pass it. `Action_Registry::is_builtin_rule_id()` added;
`matches_rest()` passes the flag to `safe_preg_match()`; fires
`wp_sudo_rule_regex_error` audit action on error.

---

### Forward hardening roadmap (from audit section 4)

Items not yet implemented, organized by theme. Each maps to a phase in this
plan or is noted as backlog.

**A — Close remaining classified bypasses**
- A3: ✅ Done — persisted-op/BOM/unclassifiable fail-safe (3531e0d)
- F11 (GET query string): ⚠️ Open — open partial from A3; feed `$_GET['query']` on GET requests

**B — Surface-coverage completeness**
- B1: Pre-register Abilities API gate (`wp_before_execute_ability`) ahead of WP 7.0+. *Backlog.*
- B2: Audit REST `'rest' => null` rules for missing matchers (theme.switch, core.update, etc.) against live WP REST schema. *Backlog.*
- B3: Broaden non-interactive coverage — `grant_super_admin`, `core.update`, password change on CLI/Cron. *Backlog.*
- B4 = F3: Gate `wp_sudo_settings` writes on non-interactive surfaces. *Quick-win, backlog.*
- B5 = F5: Gate `admin_email` / `new_admin_email` at write time on interactive surface. *Quick-win, backlog.*

**C — Fail-safe mechanics**
- C1: ✅ Done — `safe_preg_match` fail-closed for built-in rules (3531e0d)
- C2: Harden REST cookie-vs-App-Password pivot — require `rest_get_authenticated_app_password()` to be falsy before trusting the cookie-auth branch. *Quick-win, backlog.*

**D — Session/token hardening**
- D1: Bind sudo token to active `WP_Session_Token` so logout revokes sudo. *Strategic; requires integration test.*
- D2: Rotate sudo token on each gated passage (one-generation grace overlap). *Strategic.*
- D3: Add short critical-action window distinct from global duration for highest-impact rules. *Strategic.*
- D4: Step-up re-auth on context change (UA hash default-on, IP-pin opt-in). *Strategic.*
- D5: Proxy-aware IP rate-limiting via opt-in `wp_sudo_request_ip` filter. *Quick-win, backlog.*

**E — Detection & response**
- E1: Hash-chain event log (HMAC, secret outside table); `verify_chain()` Site Health test. *Strategic; highest single detection impact.*
- E2: Record + alert on security-critical config/tamper events; add `wp_sudo_policy_changed` diff signal. *Strategic.*
- E3: Gate self-deactivation; write `plugin_deactivated` event + alert before teardown. *Strategic.*
- E4: Site Health warning when audit visibility is reduced. *Quick-win, backlog.*
- E5: `wp_sudo_event_recorded` hook from `Event_Store::insert()` for SIEM bridges. *Quick-win, backlog.*

**F (supply chain & CI)**
- F/SC1: Add PHP to CodeQL or wire `composer analyse`/lint into required PR check. Entire production language currently unscanned in CI. *Quick-win, highest operational gap.*
- F/SC2: Add `composer audit` + SBOM-freshness gate + `dependabot.yml`. *Quick-win.*
- F/SC3: Introduce `manage_wp_sudo` meta-cap (default-mapped to `manage_options`). *Strategic; see Phase 2 governance.*

---

### Residual-risk items flagged for manual verification

Items from the completeness-critic pass that were not settled by static
review and need runtime confirmation or WP 7.0 RC source validation:

1. **Multipart GraphQL uploads** (`operations`/`map` fields, `wp-graphql-upload`/Apollo) — falls through to raw scan; verify whether `mutation` lands at depth 0.
2. **`operationName`-selected multi-operation documents** — tokenizer fail-safes (any `mutation` at depth 0), but Unrestricted audit logging mis-attributes. Low priority.
3. **`wp_pre_insert_user_data` TOCTOU** (`class-gate.php:498-504`) — prefer the `$update` flag from the filter args over a `get_user_by()` DB lookup. Verify filter signature in WP 6.2–7.0.
4. **`deactivate_session_on_profile_update()` fail-open** (`class-plugin.php:354`) — if `$userdata['user_pass']` is absent on a genuine REST password change the session would not expire. Verify against real `wp_update_user()`/REST.
5. **Double-read of `php://input`** in `gate_wpgraphql()` — confirm FPM re-buffers input for the WPGraphQL body read that follows. Fail direction if SAPI returns empty: confirm closed.
6. **REST `rest_get_authenticated_app_password()` timing** — confirm populated at `rest_request_before_callbacks` time (used by per-app-password overrides at `:620`).
7. **REST cookie-auth without nonce** — a logged-in cookie request lacking `X-WP-Nonce` is judged under `rest_app_password_policy`; if Unrestricted, a cookie-session attacker omitting the nonce gets the Unrestricted verdict. Trace whether a genuine logged-in cookie request can ever reach the gate without a valid `wp_rest` nonce.
8. **Stash-index race condition** — `enforce_stash_cap()` + `add_to_stash_index()` do read-modify-write on `_wp_sudo_stash_keys` with no lock.
9. **Cross-site stash isolation on multisite** — `set_site_transient()` is network-wide; ownership verified by `user_id` only (no `site_id` binding).
10. **Nonce-in-stash** — the replayed POST carries the original `_wpnonce`; a CSRF-token-bearing object at rest for up to 5 min. Distinct from F4.
11. **Event-table insert amplification** — `check_wpgraphql()` records regardless of auth state; unauthenticated mutation flooding could inflate `wpsudo_events`.
12. **`connectors.update_credentials` gate** built on assumed WP 7.0 Connectors API shape — must be re-verified against WP 7.0 RC/final SVN before relying on it.

---

## Priority Stack

## P0 — Release/Timing-Critical (WordPress 7.0 GA window)

1. Post-v3.1.3 security remediation pack:
   - Fix 2FA failure-counter reset before second-factor success.
   - Harden WPGraphQL Limited-mode fallback classification for decoded,
     escaped, batched, and persisted-operation request shapes.
   - Update security documentation to name the exact WPGraphQL fallback limits.
   - Treat as patch-release eligible if tests validate the auth/surface fixes.
2. WordPress 7.0 GA cleanup pack:
   - `Tested up to` updates in readmes.
   - Connectors GA parity verification.
   - Connectors reference split (reference vs security analysis).
   - Registry-aware Connectors matcher follow-up.
   - Remove `handle_err_admin_role()` workaround once core fix is confirmed in GA.

## P1 — Highest Security + Operator Value

3. Gutenberg block-editor sudo UX design:
   - Treat as the most important next major feature track after immediate
     delivery-hygiene work.
   - Design challenge transport, editor-state preservation, snackbar/notices UX,
     replay/cancel semantics, and Playwright coverage before implementation.
4. Internal admin governance, Phase 1:
   - Ship strict-capability mode as the default.
   - Full capability surface: `manage_wp_sudo`, `view_wp_sudo_activity`,
     `export_wp_sudo_activity`, `revoke_wp_sudo_sessions`.
   - `sudo_can()` helper, `options.wp_sudo_access` gated rule, Access tab with
     drift detection, "last manager" guard, `WP_SUDO_RECOVERY_MODE`
     break-glass, audit hooks for all access-model transitions.
   - See [`docs/internal-admin-governance-spec.md`](internal-admin-governance-spec.md).
5. Request-stash replay/data-minimization follow-up:
   - Add pattern-based redaction for non-standard secret names.
   - Add custom-rule metadata for redacted, replay-safe, and non-replayable
     fields/actions.
6. Dedicated Sudo Activity screen (list-table MVP):
   - server-side pagination/filter/sort over `wpsudo_events`.
   - Keep it modest and Sudo-specific; do not compete with dedicated audit-log
     plugins for notifications, long-term retention, or compliance exports.
7. Audit-visibility integrity warnings:
   - explicit warnings when logging visibility is reduced by code-level overrides.

## P2 — Multisite Operator Controls

8. Super-admin widget visibility controls.
9. Network dashboard widget (cross-site aggregation).
10. Cross-site session revocation.

## P3 — Governance Polish (optional, v3.2)

11. Internal admin governance, Phase 2:
   - Integrity warnings when effective visibility is broader than intended.
   - Opt-in 2FA-enrollment requirement for `manage_wp_sudo` holders.
   - Audit visibility on governance-mode transitions (`strict` ↔ `compatibility`).
   - External Audit Mode: let operators route durable Sudo event persistence,
     retention, and notifications to Stream or WSAL bridges while Sudo keeps
     firing hooks and showing session/policy status. Suppress local
     `wpsudo_events` writes only when bridge-presence preflight passes and
     integrity warnings are active. See
     [`docs/external-audit-mode-spec.md`](external-audit-mode-spec.md).

   Phase 2 is scope-bounded and non-blocking; none of it is required to close
   the governance story, but real delegation patterns will surface edge cases
   that read-only drift detection misses.

## P4 — Architecture / Scale (conditional, not scheduled)

12. Session-store architecture implementation follow-up:
    - Execute recommended Option 1 (authoritative table + usermeta shadow)
      from [`docs/session-store-evaluation.md`](session-store-evaluation.md).
    - **Conditional on reaching Tier 2 in practice** (≥ ~1,000 concurrently
      sudo-active users per site; see the Scale and Load Analysis section of
      the session-store evaluation). Not scheduled for a specific version.

## P5 — UX / Platform Expansion

13. Network policy hierarchy (after multisite operator controls stabilize).

## P6 — Long-Horizon Design Backlog

14. Client-side modal challenge.
15. REST API sudo grant endpoint for headless clients.
16. Per-session sudo isolation (`WP_Session_Tokens` integration).
17. SSO/SAML/OIDC provider framework.

## Phased GSD Execution Plan

## Phase 0 — Backlog hygiene and execution baseline

- Reconcile roadmap sections that still describe already-shipped items as open.
- Establish one canonical execution tracker for v3.1–v3.3.
- Define acceptance criteria for each phase before implementation starts.

## Phase 1 — Post-v3.1.3 security remediation pack (P0.1)

Ship the newly found auth and surface hardening fixes before lower-priority
feature work.

- 2FA lockout fix:
  - Do not clear failed-attempt counters after password success when 2FA is
    still pending.
  - Reset attempts only after final sudo activation succeeds.
  - Add tests for repeated `password -> bad 2FA -> password` cycles reaching
    lockout, plus no-2FA success behavior.
- WPGraphQL fallback classifier fix:
  - Decode JSON/form-encoded request payloads before mutation detection.
  - Treat batched payloads conservatively: any mutation gates the whole request.
  - Define fail-closed behavior for unknown persisted operations where strict
    mutation blocking is required.
  - Update `docs/security-model.md` and tests for escaped `mutation`, batches,
    classifier precedence, and persisted-operation caveats.

Exit criteria:
- `composer test:unit`, `composer lint`, and `composer analyse` pass.
- Integration coverage exists for the cross-class auth path or a documented
  reason explains why a focused unit test is sufficient.
- No browser E2E required unless the implementation changes challenge/replay UI.

## Phase 2 — Governance foundation (P1.3)

Ship strict governance as the default in a single coordinated release.

- Capability constants: `manage_wp_sudo`, `view_wp_sudo_activity`,
  `export_wp_sudo_activity`, `revoke_wp_sudo_sessions`.
- Centralized `sudo_can()` helper (with super-admin short-circuit and
  compatibility-mode fallback).
- `wp_sudo_governance_mode` option (default `strict`; `compatibility`
  available as opt-in).
- `options.wp_sudo_access` gated rule covering grants, revokes, and session
  revocations.
- Access tab with drift detection panel and "last manager" guard.
- `WP_SUDO_RECOVERY_MODE` break-glass constant with logged usage.
- Audit hooks: `wp_sudo_capability_granted`, `wp_sudo_capability_revoked`,
  `wp_sudo_session_revoked`, `governance.recovery_mode` event type.
- Rate limit on session revocations (≤ 10/hour per user).
- Replace all direct `current_user_can('manage_options')` /
  `current_user_can('manage_network_options')` checks in Sudo governance
  surfaces with `sudo_can()`.

Exit criteria:
- Zero direct `manage_options` / `manage_network_options` checks remain in
  governance-sensitive surfaces.
- Unit + integration coverage for strict and compatibility paths, including
  multisite super-admin behavior and the "last manager" guard.
- Query-invariant test: site-scope `view_wp_sudo_activity` cannot read
  cross-site events from `wpsudo_events`.
- Recovery-mode path is documented in FAQ and surfaces a permanent admin
  notice while active.

## Phase 3 — Request-stash replay/data-minimization follow-up (P1.4) ✅ Closed locally

- Added pattern-based redaction for non-standard secret field names.
- Added custom-rule metadata for replay-safe fields and actions that should
  not replay POST bodies.
- Added explicit failure behavior when redaction or replay policy makes a POST
  replay unsafe.
- Promoted and closed release blockers F14, F18d, and F18e alongside Phase 3.

Exit criteria:
- Unit tests cover camelCase, snake_case, dashed, nested, and array secret keys.
- Unit and integration replay tests prove safe allowlisted forms still work and
  unsafe or unallowlisted POST bodies redirect with clear operator-facing
  notices instead of replaying partial data.
- Local validation passed: unit, integration, PHPStan, PHPCS, metrics
  verification, and Composer audit.

Residual follow-up:
- Cancelled challenges still rely on the 5-minute stash TTL; explicit cancel
  deletion remains a lower-risk follow-up.

## Immediate low-hanging lane — CI speed and public visuals

Before starting another large product feature, close the cheap work that improves
day-to-day delivery and public trust:

- Rebalance the Playwright E2E shards by splitting the current
  `challenge.spec.ts` long pole into smaller files or explicit CI groups.
- Refresh README/readme screenshots for the v3.2 UI, including the Access tab,
  Session Activity dashboard widget, Rule Tester, and new settings surfaces.
- Treat screenshots as a phased asset: refresh now for accuracy, then redo again
  after major UI changes such as Gutenberg reauth UX.

Exit criteria:
- E2E feedback is materially faster without reducing release-grade coverage.
- Public screenshots are accurate, consistent, and do not expose private data.

## Phase 4 — Gutenberg Block Editor reauth UX design (top major feature)

This is the highest-value next major product feature, but it should start as a
design phase because it touches editor state, autosave, notices, request replay,
and user trust.

- Define the desired editor experience for gated actions: snackbar/notices,
  modal or interstitial tradeoffs, retry/cancel behavior, and recovery if
  reauthentication fails.
- Specify transport and replay semantics for block-editor requests without
  losing unsaved editor state.
- Identify the minimum JavaScript/build tooling needed and keep the PHP boundary
  explicit.
- Define Playwright coverage before implementation, including failure and cancel
  flows.

Exit criteria:
- The implementation scope is clear enough to build with TDD/E2E coverage.
- The UX does not risk data loss, confusing redirects, or silent action drops in
  the editor.

## Phase 5 — Sudo Activity screen MVP (P1.5)

- Add a dedicated list-table activity screen with pagination/filter/sort.
- Keep dashboard widget as preview and link to full activity screen.
- Use lean query shape and capped page sizes for predictable performance.
- Keep scope deliberately modest: recent Sudo-specific events, short retention,
  support/debugging value, and no email notifications or compliance reporting.
- Prepare for External Audit Mode so sites using Stream, WP Activity Log, or
  similar tools can delegate persistence and alerting instead of duplicating it.

Exit criteria:
- Operators can review and triage full recent activity without dashboard limits.
- Performance remains acceptable on high-volume event tables.
- Sites that use external audit plugins have a clear path to reduce/disable local
  persistence while keeping Sudo status and audit hooks intact.

## Phase 6 — Audit-visibility integrity warnings (P1.6)

- Detect and surface reduced event visibility (e.g., passed-event logging disabled).
- Show warnings in settings and activity surfaces with clear remediation text.

Exit criteria:
- Visibility-reduction states are explicit and auditable, not implicit.

## Phase 7 — Multisite operator controls (P2)

- Add super-admin widget visibility modes.
- Add network dashboard aggregation for cross-site visibility.
- Add cross-site session revocation actions.

Exit criteria:
- Super admins can monitor and respond across network sites without per-site pivoting.

## Phase 8 — Governance polish (P3, optional)

Additive improvements that benefit from production field data from Phase 1.

- Integrity warnings when effective visibility is broader than intended.
- Opt-in 2FA-enrollment requirement for `manage_wp_sudo` holders.
- Audit visibility for governance-mode transitions (`strict` ↔ `compatibility`).
- External Audit Mode for Stream / WSAL operators. This means WP Sudo can treat
  a supported audit plugin as the durable event store and notification layer
  while Sudo keeps firing hooks, showing session/policy status, and warning if
  delegated coverage disappears. See
  [`docs/external-audit-mode-spec.md`](external-audit-mode-spec.md).
  - New `wp_sudo_external_audit` option (`off` default, `stream`, `wsal`).
  - Preflight: refuse activation when the chosen bridge is not loaded.
  - Event-store writes short-circuit while audit hooks keep firing.
  - Dashboard widget Recent Events panel swaps to a bridge-status tile;
    Active Sessions and Policy Summary panels unchanged.
  - Integrity warning when bridge deactivates while mode is active.
  - New audit hooks: `wp_sudo_external_audit_enabled`,
    `wp_sudo_external_audit_disabled`; event type
    `governance.external_audit_toggled`.
  - Gated by `options.wp_sudo_access` (changing audit destination is a
    privilege-sensitive operation).

Exit criteria:
- Drift-detection panel (Phase 1) can fire active warnings, not only passive
  display.
- Operators can opt in to 2FA requirements for management caps without
  breaking single-site installs that don't use a 2FA plugin.
- Stream and WSAL operators can fully delegate Sudo audit without losing
  session or policy visibility in the dashboard widget, and cannot silently
  lose audit coverage if the bridge is deactivated.

Phase 8 is non-blocking; the governance model is complete after Phase 2.

## Phase 9 — Session-store architecture (P4, conditional, not scheduled)

- Implement Option 1 from
  [`docs/session-store-evaluation.md`](session-store-evaluation.md)
  (authoritative session table + usermeta shadow).
- Migrate hot-path session reads to the table-backed model; keep usermeta
  shadow for one minor-version soak period before retiring.
- Validate rollback behavior and multisite semantics.

Exit criteria:
- Measurable hot-path read reduction on deployments at Tier 2+ scale.
- No regressions in session enforcement, audit hooks, or cleanup flows.

**Conditional execution.** This phase is not scheduled for a specific
version. The scale-tier analysis in the session-store evaluation places the
inflection point around ~1,000 concurrently sudo-active users per site.
Execute Phase 9 only when real deployments approach that threshold; ship
interim transient-cache mitigations (already done for widget and Users-list
count) if they arise sooner.

## Phase 10 — UX/platform expansions (P5)

- Re-evaluate and implement network policy hierarchy.

Exit criteria:
- Predictable multisite policy governance outcomes.

## Continuous quality lane (parallel)

Across all phases:

- keep CI green (`test`, `lint`, static analysis),
- expand Playwright coverage where transport/UI changes occur,
- run release-signoff checks for WP 7.0 GA milestones,
- defer mutation testing expansion until architecture refactors settle.

## Source mapping

- Primary roadmap source: [`docs/ROADMAP.md`](ROADMAP.md)
- Governance spec: [`docs/internal-admin-governance-spec.md`](internal-admin-governance-spec.md)
- Session-store options: [`docs/session-store-evaluation.md`](session-store-evaluation.md)
- External Audit Mode spec: [`docs/external-audit-mode-spec.md`](external-audit-mode-spec.md)
