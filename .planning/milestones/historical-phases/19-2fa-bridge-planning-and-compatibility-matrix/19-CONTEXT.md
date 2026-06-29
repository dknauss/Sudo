# Phase 19: 2FA Bridge Planning and Compatibility Matrix - Context

**Gathered:** 2026-06-29
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 19 makes the next 2FA bridge work implementation-ready without overcommitting to unvalidated third-party bridge code. The phase should convert existing 2FA research, the current upstream Two Factor lifecycle bridge, and the Patchstack compatibility todo into a clear compatibility matrix and execution plan.

This phase is planning/readiness work. It does **not** need to ship bundled production behavior by default, does not claim full Patchstack compatibility without a paid/manual fixture, and does not broaden WP Sudo into a general-purpose 2FA provider.

</domain>

<decisions>
## Implementation Decisions

### Recommended defaults from discussion

The user did not answer the non-blocking scope prompts before timeout, so planning should use these defaults:

- Treat `bridges/wp-sudo-two-factor-lifecycle-bridge.php` as a real starting point to promote/refine carefully, not as speculative greenfield work.
- Keep upstream WordPress/two-factor profile-provider changes as a separately planned/tested guard rather than pretending the existing REST-route bridge already covers them.
- Represent Patchstack Security as a second-tier/manual-test target with a paid-fixture caveat and no bundled bridge commitment until tested.
- Require live-source verification for third-party code details and a real/manual fixture before claiming compatibility support beyond design notes.

### Upstream Two Factor ship scope

The plan should distinguish three upstream `WordPress/two-factor` surfaces:

1. **REST factor-management routes already covered by the existing bridge**
   - `POST /two-factor/1.0/generate-backup-codes`
   - `POST /two-factor/1.0/totp`
   - `DELETE /two-factor/1.0/totp`
   - Current bridge mechanism: add `wp_sudo_gated_actions` REST rules when `Two_Factor_Core` is present.
   - Current tests: `tests/Unit/TwoFactorLifecycleBridgeTest.php` validates rule registration, class-gated no-op behavior, regex shape, labels/categories, and methods.

2. **Classic profile-form provider changes not yet covered**
   - Provider enablement/primary provider changes through `profile.php` / `user-edit.php` profile save hooks.
   - The existing bridge comments explicitly call this out as a known v1 limit.
   - Planning should require an idempotent, enrollment-aware change predicate to avoid gating harmless profile saves or legitimate first-time setup incorrectly.

3. **Challenge-time 2FA validation already supported separately**
   - `Sudo_Session::needs_two_factor()` detects the upstream Two Factor plugin via `Two_Factor_Core::is_user_using_two_factor()`.
   - `Challenge::render_page()` and `Challenge::handle_ajax_2fa()` delegate provider rendering/pre-processing/validation.
   - This is not the same problem as lifecycle/factor-management gating.

### Patchstack tiering

Patchstack should be added/kept in the compatibility matrix as:

- **Tier:** second-tier/manual-test target behind the upstream Two Factor lifecycle bridge.
- **Current posture:** bridgeable design target, not bundled/shipped compatibility.
- **Testing caveat:** end-to-end confidence likely requires a paid Patchstack-enabled fixture because the free-license path returns before registering the 2FA hooks.
- **Implementation caution:** any future bridge must be source-refreshed and tested before support claims. Do not turn the current source survey into a compatibility guarantee.

### Evidence bar

Before a plan claims third-party behavior:

- Re-verify live source for method names, option/meta keys, routes, hooks, and request field names.
- Record the source URL, revision/commit, and query date in the plan/commit message when technical details are added or changed.
- Use source-only findings for design notes and matrix tiering, but reserve “compatible,” “tested,” or “supported” wording for fixture-backed validation.
- If a fixture cannot be obtained, label the row/manual target explicitly as untested or fixture-blocked.

</decisions>

<specifics>
## Specific Ideas

### Compatibility matrix structure

Use columns that force honest support boundaries:

- Plugin / provider
- Primary purpose in WP Sudo (`challenge validation`, `factor lifecycle gating`, or both)
- Tier/status (`built-in`, `bridge exists`, `bridge candidate`, `manual-test target`, `docs-only`, `not practical`)
- Shippable code? (`yes`, `candidate`, `no`)
- Covered operations
- Known gaps
- Evidence source/date
- Fixture/test status
- Next action

Suggested rows for Phase 19 scope:

- WordPress/two-factor challenge validation — built-in, already shipped.
- WordPress/two-factor lifecycle REST routes — bridge exists; implementation-ready after source refresh and docs/test review.
- WordPress/two-factor profile provider changes — candidate guard; plan first because false positives/first-enrollment behavior are tricky.
- Patchstack Security — second-tier/manual-test target; source-verified design notes, fixture-blocked support claim.
- Existing example bridges such as WP 2FA/WebAuthn — keep as already-documented bridge examples unless the plan intentionally refreshes their evidence.

### Two Factor lifecycle implementation shape

Potential implementation-ready tasks for a later execution phase:

1. Refresh upstream Two Factor source verification.
2. Decide whether `bridges/wp-sudo-two-factor-lifecycle-bridge.php` remains an example mu-plugin or becomes documented as the recommended upstream lifecycle bridge.
3. Add/adjust docs so `docs/two-factor-integration.md` no longer says this is merely “Planned” if the bridge is treated as existing deliverable.
4. Define tests for profile-provider change detection before writing any guard:
   - no gate for unrelated profile updates;
   - gate when enabled providers change;
   - gate when primary provider changes;
   - gate when TOTP key is replaced/deleted through classic form;
   - clear behavior for first enrollment vs modifying/removing an existing factor.
5. Decide whether profile-provider change protection belongs in the bridge, in core backstops, or as a generic third-party effect guard pattern.

### Patchstack manual-test ideas

If/when a paid fixture exists, manual test cases should cover:

- user with Patchstack 2FA disabled does not see WP Sudo 2FA step from a Patchstack bridge;
- enabled user sees Patchstack-compatible 2FA step;
- valid Patchstack TOTP completes WP Sudo challenge;
- invalid TOTP is rejected and participates in WP Sudo lockout/rate-limiting behavior;
- profile enable/disable lifecycle changes require active sudo if a Patchstack bridge/guard is introduced;
- free-license mode remains documented as fixture-blocked/uncovered rather than silently skipped.

</specifics>

<code_context>
## Existing Code Insights

### Reusable Assets

- `bridges/wp-sudo-two-factor-lifecycle-bridge.php`: existing upstream Two Factor lifecycle REST-route bridge using `wp_sudo_gated_actions`.
- `tests/Unit/TwoFactorLifecycleBridgeTest.php`: current unit coverage for the lifecycle bridge rule shape.
- `docs/two-factor-integration.md`: core 2FA architecture, upstream Two Factor method delegation, WebAuthn bridge note, and planned lifecycle section.
- `docs/two-factor-ecosystem.md`: ecosystem survey with current Patchstack row and paid-fixture caveat.
- `.planning/todos/pending/2026-06-28-add-patchstack-2fa-compatibility-target.md`: Patchstack target todo with verified-context notes.
- `includes/class-sudo-session.php`: `wp_sudo_requires_two_factor` filter and built-in upstream Two Factor detection.
- `includes/class-challenge.php`: `wp_sudo_render_two_factor_fields` action and `wp_sudo_validate_two_factor` filter.
- `includes/class-action-registry.php` / `includes/class-gate.php`: custom gated-action REST rule surface consumed by bridge files.

### Live-source verification refreshed during context gathering

- `WordPress/two-factor` master commit `fb2671b46d7fad4ceb1962297bf02762e9547309` on 2026-06-29:
  - `providers/class-two-factor-backup-codes.php` registers `/generate-backup-codes` with `WP_REST_Server::CREATABLE` and uses `_two_factor_backup_codes`.
  - `providers/class-two-factor-totp.php` registers `/totp` with `WP_REST_Server::CREATABLE` and `WP_REST_Server::DELETABLE`, and uses `_two_factor_totp_key`.
  - `class-two-factor-core.php` defines `_two_factor_provider` and `_two_factor_enabled_providers`, and hooks profile updates via `personal_options_update` / `edit_user_profile_update`.
- Patchstack WordPress.org SVN `trunk/includes/login.php`, repository revision `3590358`, file last-changed revision `3433693`, checked 2026-06-29:
  - `patchstack_license_free` returns before 2FA hook registration.
  - `patchstack_login_2fa` enables 2FA hooks.
  - user-option keys include `webarx_2fa_enabled`, `webarx_2fa_secretkey`, and `webarx_2fa_secretkey_nonce`.
  - login field is `patchstack_2fa`.
  - validation calls `TokenAuth6238::verify()` after `tfa_get_secret()`.
  - profile update hooks include `personal_options_update` and `edit_user_profile_update`, with WooCommerce account form hooks also present.

### Established Patterns

- Bridge examples live in `bridges/` as drop-in mu-plugin files, not production dependencies.
- Custom third-party gating should use `wp_sudo_gated_actions` when a request can be matched by admin/AJAX/REST criteria.
- Effect-level guards are riskier and need tighter predicates because WordPress profile saves are broad (`action=update` can represent many unrelated changes).
- The project’s LLM-confabulation guard requires live-source verification before documenting third-party classes, methods, keys, routes, or hooks.

</code_context>

<deferred>
## Deferred Ideas

- Bundling Patchstack bridge code is deferred until there is fixture-backed validation or explicit maintainer demand.
- Browser/E2E testing of third-party 2FA plugins is deferred to a browser-capable session or CI/manual fixture flow; this discussion phase used source inspection and local code review only.
- Broad support-matrix refresh for every surveyed 2FA plugin is deferred unless Phase 19 planning explicitly scopes it; focus should stay on upstream Two Factor and Patchstack.
- Shipping an effect-level profile-provider guard without TDD is out of scope; profile lifecycle behavior must be planned/tested first.

</deferred>

---

*Phase: 19-2fa-bridge-planning-and-compatibility-matrix*
*Context gathered: 2026-06-29*
