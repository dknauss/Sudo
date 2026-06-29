# Phase 19: 2FA Bridge Planning and Compatibility Matrix - Research

**Researched:** 2026-06-29  
**Domain:** WordPress 2FA bridge planning, WP Sudo gated-action rules, compatibility evidence matrix  
**Confidence:** HIGH for upstream Two Factor REST lifecycle and local WP Sudo surfaces; MEDIUM for Patchstack bridge feasibility; LOW for Patchstack runtime support until paid/manual fixture exists

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

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

### Claude's Discretion

No explicit `## Claude's Discretion` section appears in `19-CONTEXT.md`.

### Deferred Ideas (OUT OF SCOPE)

## Deferred Ideas

- Bundling Patchstack bridge code is deferred until there is fixture-backed validation or explicit maintainer demand.
- Browser/E2E testing of third-party 2FA plugins is deferred to a browser-capable session or CI/manual fixture flow; this discussion phase used source inspection and local code review only.
- Broad support-matrix refresh for every surveyed 2FA plugin is deferred unless Phase 19 planning explicitly scopes it; focus should stay on upstream Two Factor and Patchstack.
- Shipping an effect-level profile-provider guard without TDD is out of scope; profile lifecycle behavior must be planned/tested first.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| 2FA-01 | The upstream WordPress/two-factor lifecycle bridge scope is converted into an implementation-ready plan covering recovery-code generation, TOTP setup/delete, and profile provider changes. | Verified current upstream REST routes/meta keys/hooks; mapped existing WP Sudo bridge/test surfaces; identified missing profile-provider predicate tests before any guard ships. |
| 2FA-02 | Patchstack Security remains tracked as a second-tier 2FA compatibility/manual-test target, including the paid-fixture caveat and verified SVN source details. | Refreshed Patchstack SVN info and login.php findings; documented paid/free-license caveat and manual-fixture status for matrix row language. |
| 2FA-03 | The 2FA bridge plan distinguishes compatibility testing, documentation-only targets, and bridge code that should actually ship. | Recommends matrix columns and tier taxonomy that separate built-in challenge validation, shippable upstream bridge code, candidate profile guard work, and fixture-blocked/manual-only Patchstack. |
</phase_requirements>

## Summary

Phase 19 should be planned as a readiness/documentation-and-design phase, not as a broad 2FA implementation sprint. The project already has a concrete upstream Two Factor lifecycle REST bridge in `bridges/wp-sudo-two-factor-lifecycle-bridge.php` plus unit coverage in `tests/Unit/TwoFactorLifecycleBridgeTest.php`. The bridge correctly uses WP Sudo’s existing `wp_sudo_gated_actions` REST rule surface for recovery-code generation and TOTP setup/delete, so the planner should treat this as an asset to refresh, document, and possibly promote—not rebuild.

The main unresolved upstream Two Factor gap is classic profile-form provider lifecycle changes. Live source confirms the upstream plugin updates `_two_factor_enabled_providers` and `_two_factor_provider` during `personal_options_update` / `edit_user_profile_update`; the current bridge comments already exclude that surface. Any profile guard must be TDD-first and narrowly predicate on actual Two Factor changes so ordinary profile saves, unrelated user edits, and first-enrollment edge cases are not accidentally blocked.

Patchstack should remain in the compatibility matrix as a second-tier/manual-test target only. Source inspection supports “bridgeable design target” language because Patchstack has local TOTP validation and clear user-option keys, but the current SVN source also confirms the free-license branch returns before 2FA hooks are registered. Do not plan bundled Patchstack bridge code or compatibility claims until a paid Patchstack-enabled fixture exists.

**Primary recommendation:** Plan one implementation-ready upstream Two Factor bridge track (REST bridge refresh + profile-provider predicate design/tests) and one compatibility-matrix/docs track (honest Patchstack row with paid-fixture caveat); do not ship Patchstack code in Phase 19.

## Standard Stack

### Core

| Library / Surface | Version / Revision | Purpose | Why Standard |
|-------------------|--------------------|---------|--------------|
| WP Sudo | 4.2.2 on `main` (`wp-sudo.php`, `docs/release-status.md`) | Security gate, sudo session, challenge, bridge hooks | Project under planning; no new production dependencies allowed. |
| WordPress | Requires 6.4+, tested up to 7.0 | Runtime, admin profile forms, REST API, hooks | Plugin support floor and current package metadata. |
| PHP | 8.2+ | Plugin runtime | Project requirement; composer platform currently `8.2.99`. |
| `wp_sudo_gated_actions` | WP Sudo filter | Register third-party REST/admin/AJAX rules | Existing documented extension point; used by bridge files and Action Registry normalization. |
| `Two_Factor_Core` | WordPress/two-factor master `fb2671b46d7fad4ceb1962297bf02762e9547309` (2026-06-20 commit; checked 2026-06-29) | Built-in challenge-time provider detection/rendering/validation; lifecycle source of truth | Official upstream source confirms methods, routes, meta keys, and profile hooks. |

### Supporting

| Library / Surface | Version / Revision | Purpose | When to Use |
|-------------------|--------------------|---------|-------------|
| PHPUnit | `^9.6` | Unit/integration tests | Use targeted unit tests for bridge rule/predicate shape; integration tests for real WP + Two Factor challenge behavior. |
| Brain\Monkey | `^2.7` | Mock WP hooks/functions for procedural bridge tests | Existing bridge tests already use it; keep bridge tests thin and deterministic. |
| Patchstack Security | WordPress.org plugin API version `2.3.6`; SVN repository rev `3590445`, file last-changed rev `3433693` for `trunk/includes/login.php` (checked 2026-06-29) | Compatibility matrix row and future manual fixture target | Use for documentation/matrix only until paid fixture validates runtime behavior. |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Existing `wp_sudo_gated_actions` REST rules | Custom REST middleware or direct route callback wrapping | Do not use. WP Sudo already intercepts `rest_request_before_callbacks` and applies cookie/app-password policy consistently. |
| Upstream Two Factor provider API | Custom TOTP/backup-code verification in WP Sudo | Do not use. WP Sudo challenge validation delegates to provider APIs and should not become a 2FA provider. |
| Matrix/manual-test row for Patchstack | Bundled Patchstack bridge now | Do not use until fixture-backed. Current source supports bridge feasibility, not support claims. |

**Installation:**

```bash
# No new production dependencies.
composer install
```

## Architecture Patterns

### Recommended Project Structure

```text
bridges/
├── wp-sudo-two-factor-lifecycle-bridge.php  # upstream Two Factor lifecycle REST rules; candidate place for profile guard if kept as bridge
├── wp-sudo-wp2fa-bridge.php                 # existing 2FA challenge bridge example
└── wp-sudo-webauthn-bridge.php              # existing factor-registration gating example

tests/
├── Unit/
│   ├── TwoFactorLifecycleBridgeTest.php     # existing REST bridge coverage; extend for profile predicate if bridge changes
│   └── *BridgeTest.php                      # established thin bridge-test pattern
└── Integration/
    └── TwoFactorTest.php                    # existing real WP challenge/session 2FA coverage

docs/
├── two-factor-integration.md                # lifecycle bridge documentation and implementation plan target
├── two-factor-ecosystem.md                  # compatibility matrix / Patchstack row target
└── developer-reference.md                   # custom gated-action patterns if docs need cross-linking
```

### Pattern 1: Separate Challenge Validation from Lifecycle Gating

**What:** Treat “can a user complete WP Sudo’s 2FA step?” and “can a user create/replace/delete 2FA credentials?” as separate security surfaces.  
**When to use:** Always in Phase 19. The former is already built in for WordPress/two-factor; the latter needs bridge planning.  
**Example:**

```php
// Local source: includes/class-sudo-session.php
if ( class_exists( '\\Two_Factor_Core' ) && \Two_Factor_Core::is_user_using_two_factor( $user_id ) ) {
	$needs = true;
}

return (bool) apply_filters( 'wp_sudo_requires_two_factor', $needs, $user_id );
```

**Planning implication:** Do not write docs that say “Two Factor is covered” without naming which surface is covered: challenge validation, REST lifecycle, profile lifecycle, or manual compatibility.

### Pattern 2: REST Lifecycle Bridge via `wp_sudo_gated_actions`

**What:** Add route/method rules to WP Sudo’s rule registry; let `Gate::intercept_rest()` enforce active sudo for cookie-authenticated browser REST requests and operator policy for Application Passwords.  
**When to use:** Upstream Two Factor recovery-code and TOTP REST lifecycle endpoints.  
**Example:**

```php
// Local source: bridges/wp-sudo-two-factor-lifecycle-bridge.php
$rules[] = array(
	'id'       => 'two_factor.totp_manage',
	'label'    => __( 'Set up or remove Two Factor TOTP', 'wp-sudo' ),
	'category' => 'users',
	'admin'    => null,
	'ajax'     => null,
	'rest'     => array(
		'route'   => '#^/two-factor/1\.0/totp$#',
		'methods' => array( 'POST', 'DELETE' ),
	),
);
```

**Planning implication:** The planner should refresh source evidence and decide whether this bridge remains an example mu-plugin or becomes the recommended upstream lifecycle bridge. Do not replace it with bespoke REST handling.

### Pattern 3: Profile Provider Guard Must Be a Narrow Predicate

**What:** If a profile-provider guard is planned, it should match broad admin profile saves only when the submitted Two Factor provider state would change existing lifecycle state.  
**When to use:** `profile.php` / `user-edit.php` `action=update` requests that include upstream Two Factor form fields.  
**Recommended predicate shape for planning (not implementation final):**

```php
// Source basis: WP Sudo Action_Registry admin callbacks + WordPress/two-factor core profile hooks.
// Plan tests before writing this.
$rules[] = array(
	'id'       => 'two_factor.profile_provider_change',
	'label'    => __( 'Change Two Factor providers', 'wp-sudo' ),
	'category' => 'users',
	'admin'    => array(
		'pagenow'  => array( 'profile.php', 'user-edit.php' ),
		'actions'  => array( 'update' ),
		'method'   => 'POST',
		'callback' => 'wp_sudo_two_factor_profile_change_requested',
	),
	'ajax'     => null,
	'rest'     => null,
);
```

**Planning implication:** The first task for this area must be tests for no-op saves, enabled-provider changes, primary-provider changes, TOTP key replacement/deletion implications, and first-enrollment policy. A non-trivial security guard also triggers the project’s pre-implementation design review before code.

### Pattern 4: Compatibility Matrix with Evidence and Fixture Columns

**What:** Matrix rows must distinguish support status from evidence type.  
**When to use:** `docs/two-factor-ecosystem.md` and any Phase 19 plan/doc output.  
**Recommended columns:**

| Column | Purpose |
|--------|---------|
| Plugin / provider | Identify target precisely. |
| WP Sudo purpose | `challenge validation`, `factor lifecycle gating`, or both. |
| Tier/status | `built-in`, `bridge exists`, `bridge candidate`, `manual-test target`, `docs-only`, `not practical`. |
| Shippable code? | `yes`, `candidate`, `no`. |
| Covered operations | Name exact routes/hooks/actions. |
| Known gaps | State profile/fixture/support limitations. |
| Evidence source/date | Commit/SVN/API and query date. |
| Fixture/test status | Automated, manual tested, fixture-blocked, or source-only. |
| Next action | Implementation/test/doc action. |

### Anti-Patterns to Avoid

- **Collapsing all Two Factor surfaces into “supported”:** Challenge validation, REST lifecycle gating, and profile-provider changes have different code paths and evidence levels.
- **Broad profile-save blocking:** A generic `profile.php?action=update` rule would block harmless profile edits and create user-facing false positives.
- **Patchstack support claims from source inspection:** Current evidence is enough for matrix tiering and design notes only.
- **New production dependency for 2FA:** Project policy forbids production dependencies; bridges are mu-plugin examples and hooks, not packages.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Upstream Two Factor challenge validation | Custom TOTP/backup-code verifier | `Two_Factor_Core::get_primary_provider_for_user()` and provider methods in `Challenge` | Provider supports TOTP, email, backup codes, WebAuthn, and resend/pre-processing; custom logic would be incomplete. |
| Upstream Two Factor REST lifecycle gating | Custom REST dispatcher/middleware | `wp_sudo_gated_actions` REST rules | WP Sudo already handles cookie-auth gating, app-password policy, audit hooks, and error format. |
| Profile lifecycle guard | Naive “all profile saves require sudo” rule | Narrow callback predicate with TDD | Profile updates are broad and frequent; false positives would degrade normal admin/user workflows. |
| Patchstack validation | Reimplementation of RFC6238 or secret decryption | Patchstack’s own verifier in a future bridge, only after fixture | Patchstack stores/encrypts secrets internally; source-only design is not runtime support. |
| Compatibility evidence | Prose-only “works with X” claims | Matrix row with source/date/fixture status | The project has an explicit anti-confabulation policy for third-party technical details. |

**Key insight:** This domain is mostly about honest boundaries and interception placement. WP Sudo should gate credential lifecycle actions it can see, delegate 2FA verification to providers, and clearly label fixture-blocked compatibility targets rather than over-building unvalidated bridge code.

## Common Pitfalls

### Pitfall 1: Confusing Challenge-Time Support with Factor-Lifecycle Protection

**What goes wrong:** Documentation says WordPress/two-factor is “supported,” and planners assume recovery-code/TOTP/profile changes are covered.  
**Why it happens:** `Sudo_Session` and `Challenge` support provider validation, but credential management routes/hooks are separate.  
**How to avoid:** Matrix every row by purpose: challenge validation vs factor lifecycle gating.  
**Warning signs:** A plan mentions `Two_Factor_Core::validate_authentication()` but not `/generate-backup-codes`, `/totp`, or profile hooks.

### Pitfall 2: Treating the Existing REST Bridge as Covering Profile Provider Changes

**What goes wrong:** Profile-form changes to `_two_factor_enabled_providers` or `_two_factor_provider` remain ungated.  
**Why it happens:** REST bridge route regexes only match `/two-factor/1.0/generate-backup-codes` and `/two-factor/1.0/totp`.  
**How to avoid:** Plan a separate profile-provider guard design/test task.  
**Warning signs:** No tests for `profile.php` / `user-edit.php` `action=update` with Two Factor POST fields.

### Pitfall 3: False Positives on Profile Saves

**What goes wrong:** Users are challenged when saving unrelated profile fields.  
**Why it happens:** WordPress profile save actions are broad; upstream Two Factor hooks run on the same save lifecycle.  
**How to avoid:** Predicate on verified Two Factor fields/nonces and compare desired state to existing user meta.  
**Warning signs:** A rule with `pagenow=profile.php` and `action=update` but no callback.

### Pitfall 4: Overclaiming Patchstack Compatibility

**What goes wrong:** Docs imply WP Sudo supports Patchstack 2FA without a paid fixture.  
**Why it happens:** Source inspection shows local TOTP validation, which is tempting to treat as enough.  
**How to avoid:** Use “bridgeable design target,” “manual-test target,” and “fixture-blocked” language until tested.  
**Warning signs:** A Patchstack row says `built-in`, `supported`, or `bridge exists`.

### Pitfall 5: Stale Third-Party Source Details

**What goes wrong:** Routes, meta keys, hook names, or field names drift and docs become wrong.  
**Why it happens:** Third-party code changes independently; project policy forbids relying on model memory.  
**How to avoid:** Re-run source checks before plan execution/commit and cite URL + commit/SVN revision + date.  
**Warning signs:** Technical claims cite “current source” without a SHA, SVN revision, or checked date.

## Code Examples

Verified patterns from local and official sources:

### Existing WP Sudo REST Bridge Rule

```php
// Source: bridges/wp-sudo-two-factor-lifecycle-bridge.php
'rest' => array(
	'route'   => '#^/two-factor/1\.0/generate-backup-codes$#',
	'methods' => array( 'POST' ),
),
```

### Action Registry Rule Shape

```php
// Source: includes/class-action-registry.php
`rest` => array(
	'route'    => string,
	'methods'  => string[],
	'callback' => callable( WP_REST_Request $request ): bool,
)
```

### Upstream Two Factor Route Evidence

```php
// Source: WordPress/two-factor providers/class-two-factor-totp.php
// Commit: fb2671b46d7fad4ceb1962297bf02762e9547309; checked 2026-06-29
register_rest_route(
	Two_Factor_Core::REST_NAMESPACE,
	'/totp',
	array(
		array( 'methods' => WP_REST_Server::DELETABLE ),
		array( 'methods' => WP_REST_Server::CREATABLE ),
	)
);
```

### Upstream Two Factor Profile Update Evidence

```php
// Source: WordPress/two-factor class-two-factor-core.php
// Commit: fb2671b46d7fad4ceb1962297bf02762e9547309; checked 2026-06-29
update_user_meta( $user_id, self::ENABLED_PROVIDERS_USER_META_KEY, array_keys( $enabled_providers ) );
update_user_meta( $user_id, self::PROVIDER_USER_META_KEY, $new_provider );
```

### Patchstack Evidence Caveat

```php
// Source: Patchstack SVN trunk/includes/login.php
// Repository rev 3590445; file last-changed rev 3433693; checked 2026-06-29
if ( $this->get_option( 'patchstack_license_free', 0 ) == 1 ) {
	return;
}
```

## State of the Art

| Old Approach | Current Approach | When Changed / Verified | Impact |
|--------------|------------------|-------------------------|--------|
| Docs called Two Factor lifecycle gating “Planned” against older commit `38cd183` | Existing `bridges/wp-sudo-two-factor-lifecycle-bridge.php` gates current REST lifecycle routes; docs need refresh/positioning | Current upstream master `fb2671b46d7fad4ceb1962297bf02762e9547309`, checked 2026-06-29 | Planner should not treat REST bridge as greenfield; plan refresh/promotion/docs. |
| “Two Factor support” as one row | Split into challenge validation, REST lifecycle, profile provider lifecycle | Phase 19 context + source verification, 2026-06-29 | Prevents overclaiming and highlights profile guard gap. |
| Patchstack absent or informal todo | Matrix row as second-tier/manual-test target with paid-fixture caveat | SVN rev `3590445`, checked 2026-06-29 | Phase can satisfy 2FA-02 without shipping untested code. |
| Browser/E2E manual testing assumed available | Current session cannot run browser automation; plan manual/browser fixture separately | Current session constraints | Do not write tasks that require in-session browser automation unless user restarts in browser-capable mode. |

**Deprecated/outdated:**

- `docs/two-factor-integration.md` lifecycle section still cites WordPress/two-factor commit `38cd183` from 2026-04-29. Refresh to `fb2671b46d7fad4ceb1962297bf02762e9547309` (checked 2026-06-29) if Phase 19 edits the section.
- Any Patchstack row based on SVN revision `3589135` should be refreshed to repository revision `3590445` with file last-changed revision `3433693` for `includes/login.php` (checked 2026-06-29). The relevant file content appears unchanged for the inspected 2FA details, but cite the current repository revision.

## Open Questions

1. **Should the upstream Two Factor lifecycle REST bridge remain an example mu-plugin or become “recommended” documentation?**
   - What we know: The bridge exists, is tested, and matches current upstream REST routes.
   - What's unclear: Whether maintainers want to position it as a supported bridge artifact or an optional sample.
   - Recommendation: Plan a docs decision task; if “recommended,” refresh `docs/two-factor-integration.md` wording and source citations.

2. **Where should the profile-provider guard live?**
   - What we know: It is not covered by REST rules and needs TDD. Bridge comments call it a follow-up.
   - What's unclear: Whether it belongs in the existing lifecycle bridge, a separate bridge file, or core backstop architecture.
   - Recommendation: Keep it in bridge scope unless design review shows a generic effect-guard pattern is safer.

3. **What is the first-enrollment policy for profile-provider changes?**
   - What we know: Current REST bridge comments note first-time enrollment is not blocked as a special risk because the user can get a password-only sudo session when no factor exists.
   - What's unclear: Whether profile-form first enrollment should be allowed without sudo, gated password-only, or documented as intentionally out of scope.
   - Recommendation: Make this an explicit test case before guard implementation.

4. **Can a paid Patchstack fixture be obtained?**
   - What we know: Source indicates free-license mode skips 2FA hook registration.
   - What's unclear: Runtime class availability, object ownership, and bridge viability in paid mode.
   - Recommendation: Keep Patchstack code out of Phase 19 unless a fixture is available before planning finishes.

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 9.6 (`phpunit/phpunit ^9.6`) with Brain\Monkey 2.7 for unit bridge tests; WP integration suite for real WordPress behavior |
| Config file | `phpunit.xml.dist` for unit; `phpunit-integration.xml.dist` for integration |
| Quick run command | `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/TwoFactorLifecycleBridgeTest.php` |
| Full suite command | `composer test:unit` |
| Pre-commit quality gates for code changes | `composer test:unit`, `composer analyse`, `composer lint` |
| Integration command when real WP behavior changes | `composer test:integration` and, when multisite risk exists, `WP_MULTISITE=1 composer test:integration` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|--------------|
| 2FA-01 | Existing upstream Two Factor REST lifecycle bridge registers recovery-code and TOTP REST rules with exact routes/methods | unit | `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/TwoFactorLifecycleBridgeTest.php` | ✅ |
| 2FA-01 | Profile-provider changes are detected narrowly and do not gate unrelated profile saves | unit first; integration if guard ships | `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/TwoFactorLifecycleBridgeTest.php --filter profile` | ❌ Wave 0 |
| 2FA-01 | Real Two Factor challenge validation remains separate from lifecycle gating | integration regression | `composer test:integration -- --filter TwoFactorTest` (or direct `./vendor/bin/phpunit --configuration phpunit-integration.xml.dist tests/Integration/TwoFactorTest.php`) | ✅ |
| 2FA-02 | Patchstack row records source URL/revision/date and paid-fixture caveat | docs/static review | Manual doc review plus source refresh commands; no automated fixture available | ❌ Wave 0 docs check only |
| 2FA-03 | Matrix distinguishes built-in, bridge exists, bridge candidate, manual-test target, docs-only, and shippable-code status | docs/static review | Manual review; optionally `composer lint` if PHP bridge docs examples change | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** For docs-only matrix/planning edits, run a targeted source-refresh check and `git diff --check`; for bridge code/tests, run `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/TwoFactorLifecycleBridgeTest.php`.
- **Per wave merge:** `composer test:unit`; add `composer analyse` and `composer lint` for any PHP changes.
- **Phase gate:** Full relevant suite green before `/gsd:verify-work`; if code changed, include `composer test:unit`, `composer analyse`, and `composer lint`. If profile guard reaches integration behavior, include `composer test:integration`.

### Wave 0 Gaps

- [ ] `tests/Unit/TwoFactorLifecycleBridgeTest.php` — add profile-provider predicate tests if any profile guard is planned (covers 2FA-01 profile lifecycle).
- [ ] `docs/two-factor-integration.md` — refresh lifecycle section from “Planned” to current bridge posture if Phase 19 edits docs (covers 2FA-01/2FA-03).
- [ ] `docs/two-factor-ecosystem.md` — ensure Patchstack row uses current SVN revision/date and fixture caveat (covers 2FA-02/2FA-03).
- [ ] Compatibility matrix review checklist — ensure every row has evidence/date/fixture/shippable-code status before claiming support (covers 2FA-03).

## Sources

### Primary (HIGH confidence)

- Local WP Sudo context: `.planning/phases/19-2fa-bridge-planning-and-compatibility-matrix/19-CONTEXT.md` — locked decisions, scope, source evidence bar.
- Local WP Sudo bridge: `bridges/wp-sudo-two-factor-lifecycle-bridge.php` — current upstream Two Factor REST lifecycle bridge.
- Local WP Sudo tests: `tests/Unit/TwoFactorLifecycleBridgeTest.php`, `tests/Integration/TwoFactorTest.php`, `tests/Unit/ChallengeTest.php` — existing validation surfaces.
- Local WP Sudo internals: `includes/class-sudo-session.php`, `includes/class-challenge.php`, `includes/class-action-registry.php`, `includes/class-gate.php` — 2FA challenge delegation, rule shape, and REST interception.
- WordPress/two-factor official GitHub source, master commit `fb2671b46d7fad4ceb1962297bf02762e9547309` (commit date 2026-06-20; checked 2026-06-29):
  - https://github.com/WordPress/two-factor/commit/fb2671b46d7fad4ceb1962297bf02762e9547309
  - https://raw.githubusercontent.com/WordPress/two-factor/fb2671b46d7fad4ceb1962297bf02762e9547309/providers/class-two-factor-backup-codes.php
  - https://raw.githubusercontent.com/WordPress/two-factor/fb2671b46d7fad4ceb1962297bf02762e9547309/providers/class-two-factor-totp.php
  - https://raw.githubusercontent.com/WordPress/two-factor/fb2671b46d7fad4ceb1962297bf02762e9547309/class-two-factor-core.php
- Patchstack official WordPress.org SVN source, repository revision `3590445`, `trunk/includes/login.php` last-changed revision `3433693` (checked 2026-06-29):
  - https://plugins.svn.wordpress.org/patchstack/trunk/includes/login.php

### Secondary (MEDIUM confidence)

- WordPress.org plugin API for Patchstack (queried 2026-06-29): slug `patchstack`, version `2.3.6`, active installs `40000`, last updated `2026-04-22 7:11am GMT`. Use only if a docs row needs current plugin metadata; avoid duplicating volatile counts unless necessary.
- Local docs: `docs/two-factor-integration.md`, `docs/two-factor-ecosystem.md`, `docs/security-model.md`, `CONTRIBUTING.md`, `docs/release-status.md` — project policy and current docs requiring refresh.

### Tertiary (LOW confidence)

- None used for technical claims. No unverified community/blog sources were used.

## Metadata

**Confidence breakdown:**

- Standard stack: HIGH — derived from local composer/project metadata, project instructions, and official upstream source.
- Architecture: HIGH for existing WP Sudo hooks/bridge surfaces; MEDIUM for profile-provider guard placement because it requires design review and tests before implementation.
- Pitfalls: HIGH for source-staleness, REST/profile distinction, and Patchstack fixture caveat; all are grounded in local comments/context and live source.
- Patchstack bridge feasibility: MEDIUM — source supports feasibility, but runtime behavior is LOW until a paid fixture is tested.

**Research date:** 2026-06-29  
**Valid until:** 2026-07-06 for third-party source details; 2026-07-29 for local architecture assumptions if no code changes land first.
