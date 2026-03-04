# Phase 5: WP 7.0 Readiness - Research

**Researched:** 2026-02-19
**Domain:** WordPress 7.0 compatibility, Abilities API assessment, admin visual refresh
**Confidence:** MEDIUM (WP 7.0 Beta 1 released today; changelog not yet fully published; CSS changes are architectural intent, not final spec)

---

## Summary

WordPress 7.0 Beta 1 shipped today (February 19, 2026) with GA targeting April 9, 2026. The plugin already runs against WP 7.0-alpha-61682 in both dev environments. Phase 5 has four work streams: manual test execution against 7.0 beta/RC, visual verification against the admin refresh, a version bump when GA ships, and an Abilities API assessment document.

The admin visual refresh ("coat-of-paint reskin," Trac #64308) introduces design tokens for colors, spacing, and typography, and brings admin tables into visual alignment with DataViews. The plugin uses standard WordPress admin markup — `.wrap`, `settings_fields()`, `do_settings_sections()`, `form-table`, `.notice`, `.widefat.striped` — all of which are existing WordPress CSS classes that the visual refresh does not remove, only reskins. The challenge page CSS uses plugin-scoped class names that won't be touched by WP core. The admin bar CSS targets `#wpadminbar` selectors that will not change. The primary risk is aesthetic: colors may shift slightly against the new design system palette, and form select elements may gain new styling. No functional CSS breakage is anticipated.

The Abilities API (introduced in WP 6.9, carried into 7.0) currently has exactly three core abilities, all read-only: `core/get-site-info`, `core/get-user-info`, and `core/get-environment-info`. All use `permission_callback` (typically `current_user_can( 'read' )` or `current_user_can( 'manage_options' )`), not reauthentication. No destructive abilities are registered in core as of 7.0 Beta 1. The Gate class currently has no `ability` surface type. The assessment document should document this gap and define the gating strategy for when destructive abilities appear.

**Primary recommendation:** Run the existing manual testing guide against 7.0 beta now; write the abilities assessment document based on the three existing read-only abilities; hold the version bump for April 9 GA.

---

## Standard Stack

### Core (no changes for this phase)
| Component | Version | Purpose | Notes |
|-----------|---------|---------|-------|
| wp-sudo plugin | 2.3.2 | The plugin under test | No library additions needed for this phase |
| WordPress | 7.0 (beta/RC → GA) | Test target | Already on alpha-61682 locally |
| PHPUnit | 9.6 (existing) | Unit test runner | No changes |

### Supporting Tools
| Tool | Purpose | Notes |
|------|---------|-------|
| WP Studio (localhost:8883) | Admin UI visual testing | Already on WP 7.0-alpha-61682 |
| Local by Flywheel (localhost:10045) | Multisite, app-password, CLI testing | Already on WP 7.0-alpha-61682 |
| Browser DevTools | CSS regression visual checks | Use to compare against 6.9 |

**No new dependencies required.** This phase is documentation + manual testing + version bumps.

---

## Architecture Patterns

### Recommended File Layout for Phase Deliverables

```
wp-sudo/
├── tests/
│   └── MANUAL-TESTING.md          # Add WP 7.0 section (new section 15 or appendix)
├── docs/
│   └── abilities-api-assessment.md # NEW — Abilities API evaluation document
├── readme.txt                      # Bump "Tested up to: 7.0"
└── wp-sudo.php                     # Bump "Tested up to" in plugin header
```

### Pattern 1: Manual Test Documentation Section

**What:** Add a "WP 7.0 Visual Compatibility" section to `tests/MANUAL-TESTING.md`
**When to use:** One-time addition; serves as the WP70-01 and WP70-02 record
**Structure:**

```markdown
## 15. WP 7.0 Visual Compatibility

Run against WP 7.0 beta/RC (Studio at localhost:8883 or Local at localhost:10045).
Document pass/fail and any visual regressions.

### 15.1 Settings Page (Settings > Sudo)
1. Load Settings > Sudo.
2. **Expected:** Settings render correctly under WP 7.0 admin chrome.
   - Form table rows, labels, select dropdowns align properly.
   - Policy dropdowns minimum width (140px) is respected.
   - Shield icon (dashicons) renders in the page title.
   - Help tabs open and display correctly.
   - Gated actions table (`.widefat.striped`) renders with correct spacing.
   - MU-plugin status section renders correctly.
3. **Result:** [PASS/FAIL — date tested]

### 15.2 Challenge Page
1. Trigger a gated action (e.g., Settings > General > Save Changes).
2. **Expected:** Challenge card renders correctly.
   - Card centered, max-width 420px, white background, border/shadow.
   - Password field fills card width.
   - "Confirm & Continue" and "Cancel" buttons render correctly.
   - No raw text or visible escape sequences.
3. **Result:** [PASS/FAIL — date tested]

### 15.3 Admin Bar Countdown
1. Activate a sudo session.
2. **Expected:** Green countdown node renders in admin bar.
   - Timer text is readable against green (#2e7d32) background.
   - Red state (#c62828) appears in final 60 seconds.
   - Admin bar node does not conflict with new WP 7.0 admin toolbar chrome.
3. **Result:** [PASS/FAIL — date tested]

### 15.4 Admin Notices (Gate Notice + Blocked Notice)
1. Go to Plugins page with no sudo session active.
2. **Expected:** Persistent gate notice renders with correct styling.
   - `.notice.notice-warning` class applies correctly.
   - Link to challenge page is visible and styled.
3. **Result:** [PASS/FAIL — date tested]

### 15.5 Disabled Action Links (Plugin/Theme rows)
1. Go to Plugins page with no sudo session active.
2. **Expected:** Activate/Deactivate/Delete links are replaced with gray spans.
   - Inline `color:#787c82; cursor:default` still renders correctly.
   - No conflict with new row-action hover styles from admin refresh.
3. **Result:** [PASS/FAIL — date tested]
```

### Pattern 2: Abilities API Assessment Document

**What:** `docs/abilities-api-assessment.md` — evaluates current surface, documents gating strategy
**Required sections per WP70-04:**
1. Current WP 7.0 abilities inventory (3 read-only abilities)
2. Analysis of `permission_callback` pattern vs. WP Sudo gating
3. WP Sudo Gate surface detection — no `ability` surface type currently
4. Gating strategy for future destructive abilities
5. When to add `ability` surface type to Gate

### Pattern 3: Version Bump

**Files to update for WP70-03:**

```
readme.txt
  Line 8:  Tested up to:      6.9
  →        Tested up to:      7.0

wp-sudo.php
  No "Tested up to" in plugin header (this is a readme.txt-only field)
  → Verify if plugin header has this; it does NOT in wp-sudo.php currently
```

**Verification:** The `wp-sudo.php` plugin header does NOT contain a "Tested up to" field — that field lives only in `readme.txt`. The planner should note that only `readme.txt` needs this bump.

### Anti-Patterns to Avoid

- **Bumping "Tested up to" before GA:** Must wait for April 9, 2026 (WP70-03 time gate). Do not bump against beta/RC.
- **Writing the Abilities assessment without verifying ability names against live source:** Check the actual registered abilities in the 7.0-alpha environment, not just training data.
- **Assuming the admin visual refresh breaks CSS:** The plugin uses standard WP admin classes. Test visually; don't preemptively rewrite CSS.
- **Treating MANUAL-TESTING.md WP 7.0 section as a new document:** It's an addition to the existing guide, not a replacement.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Listing registered abilities | Custom WP-CLI script | `wp ability list` (wp-cli/ability-command) | Official CLI command exists |
| Checking plugin CSS against WP 7.0 | CSS diffing scripts | Browser DevTools visual comparison | Manual visual check is correct approach |
| Version bump automation | Shell scripts | Direct file edit | Two-line change, no automation needed |

**Key insight:** This phase is almost entirely manual testing + documentation. The temptation to over-engineer tooling should be resisted.

---

## Common Pitfalls

### Pitfall 1: Confusing "Tested up to" location
**What goes wrong:** Searching `wp-sudo.php` for "Tested up to" and not finding it, then writing a plan that says to update `wp-sudo.php`.
**Why it happens:** Some plugins put this in the plugin header PHP file; WP Sudo does not.
**How to avoid:** The field is in `readme.txt` line 8 only. The plugin header in `wp-sudo.php` has no "Tested up to" field.
**Warning signs:** Any plan that mentions updating `wp-sudo.php` for a version bump.

### Pitfall 2: Assuming CI trunk matrix already covers WP 7.0
**What goes wrong:** The `phpunit.yml` matrix uses `wp: ['latest', 'trunk']`. "trunk" follows WordPress SVN trunk, which is the development branch for whatever WP version is in active development. As of 2026-02-19, trunk IS 7.0-alpha. So CI already tests against 7.0-alpha continuously.
**Why it matters:** The integration tests (WP_MULTISITE=1) already exercise 7.0 alpha code. Unit tests are independent of WP version.
**How to avoid:** No CI changes are needed for WP 7.0 compatibility testing. The `trunk` matrix entry handles it.
**Warning signs:** Any plan that proposes changing the CI matrix for WP 7.0.

### Pitfall 3: Over-scoping the Abilities API assessment
**What goes wrong:** Treating WP70-04 as requiring Gate code changes for WP 7.0.
**Why it happens:** The phase description says "evaluates current Abilities API surface, gating strategy for future destructive abilities." The word "strategy" can be misread as "implementation."
**How to avoid:** WP70-04 is a documentation deliverable only. The assessment document explains what exists, why it doesn't need gating now (read-only only), and what the Gate would need when destructive abilities appear. No Gate code changes.
**Warning signs:** Any plan that proposes adding `ability` surface type to `Gate` as a WP70 task.

### Pitfall 4: Missing the iframe editor change
**What goes wrong:** Forgetting that WP 7.0 makes the post editor always-iframed. The plugin does not touch the post editor, but the challenge page has iframe-breaking logic (v2.3.0 fix).
**Why it matters:** If the challenge page is ever reached from an iframed context, it breaks out. Test that this still works.
**How to avoid:** Test 14.3 in MANUAL-TESTING.md covers this. Include it in the WP 7.0 manual test run.

### Pitfall 5: Treating design tokens as breaking changes
**What goes wrong:** Assuming WP 7.0 removes existing CSS class names or fundamentally changes how admin pages render.
**Why it happens:** "Admin visual refresh" sounds disruptive.
**How to avoid:** The refresh explicitly preserves backward compatibility — all existing class names remain. It adds design tokens and reskins components. Plugin CSS using `.wrap`, `.form-table`, `.notice`, `.widefat` will inherit the new look automatically. Only custom color hardcodes could conflict.
**Warning signs:** Reviewing the plugin's CSS files — they use plugin-scoped selectors (`.wp-sudo-*`) and standard WP selectors. The risk of breakage is LOW.

---

## Code Examples

Verified patterns from official sources and codebase inspection:

### Abilities API: Core Ability Registration Pattern (WP 6.9+)

```php
// Source: developer.wordpress.org/apis/abilities-api/
// Registration must happen inside wp_abilities_api_init action
add_action( 'wp_abilities_api_init', function() {
    wp_register_ability(
        'core/get-site-info',  // namespace/name format
        array(
            'label'              => __( 'Get Site Information', 'wp-abilities-api' ),
            'description'        => __( 'Returns site information configured in WordPress', 'wp-abilities-api' ),
            'permission_callback' => function() {
                return current_user_can( 'read' );  // Read-only: any logged-in user
            },
            'execute_callback'   => function() { /* returns site data */ },
            'meta'               => array( 'show_in_rest' => true ),
        )
    );
} );
```

### Abilities API: REST Endpoints Available in WP 6.9+

```
GET  /wp-json/wp-abilities/v1/abilities           # List all registered abilities
GET  /wp-json/wp-abilities/v1/categories          # List categories
GET  /wp-json/wp-abilities/v1/{ns}/{name}         # Get single ability
GET|POST|DELETE /wp-json/wp-abilities/v1/{ns}/{name}/run  # Execute ability
```

HTTP method for `/run` is determined by the ability:
- Read-only operations → GET
- Operations requiring input → POST
- Destructive operations → DELETE (none registered in WP 7.0)

### Version Bump: Exact Edit in readme.txt

```
# readme.txt line 8 — before:
Tested up to:      6.9

# readme.txt line 8 — after (wait for WP 7.0 GA, April 9, 2026):
Tested up to:      7.0
```

### CI Matrix: trunk Already Covers WP 7.0 Alpha

```yaml
# Source: .github/workflows/phpunit.yml (existing)
matrix:
  php: ['8.1', '8.3']
  wp: ['latest', 'trunk']  # trunk = WP 7.0-alpha as of 2026-02-19
  multisite: [false, true]
```

No changes needed.

### abilities-api-assessment.md: Recommended Document Structure

```markdown
# Abilities API Assessment

**Date:** [when written]
**WP version evaluated:** 7.0 (beta/RC)
**Status:** No gating changes required for WP 7.0

## Current Abilities Surface in WP 7.0

| Ability ID | Label | Permission | Destructive? |
|------------|-------|------------|--------------|
| core/get-site-info | Get Site Information | current_user_can('read') | No |
| core/get-user-info | Get User Information | current_user_can('read') | No |
| core/get-environment-info | Get Environment Info | current_user_can('read') | No |

## Analysis: Does WP Sudo Need to Gate Abilities?

### Current state: No gating required
All three core abilities are read-only. They expose information but do not
modify site state. The permission_callback pattern they use (current_user_can)
is a capability check, not a reauthentication check.

WP Sudo's gating model intercepts operations that MODIFY or DESTROY state
(activate plugin, delete user, change settings). Read-only operations are
explicitly not gated.

### Future state: When to add ability gating

The Gate class currently recognizes these surfaces:
- admin, ajax (via admin_init at priority 1)
- rest (via rest_request_before_callbacks filter)
- cli, cron, xmlrpc (via init at priority 0)

An `ability` surface type would be needed when:
1. A destructive ability is registered (DELETE method in /run endpoint)
2. The ability can be triggered via browser (cookie-auth REST) or App Password

### Gating strategy for future destructive abilities

When a destructive ability appears (e.g., hypothetical core/delete-plugin),
the Gate should intercept at the REST layer via rest_request_before_callbacks,
matching the route pattern /wp-abilities/v1/.*/run with DELETE method.

The existing intercept_rest() method already handles this pattern. A new
rule in Action_Registry would suffice — no new surface type required for
REST-exposed abilities. For abilities executed via WP-CLI's `wp ability run`
command, the existing CLI surface gating via function hooks would apply.

## Recommendation

No Gate changes needed for WP 7.0. Monitor WordPress abilities-api
repository for destructive ability additions. When they appear, add
REST rules to Action_Registry matching the /wp-abilities/v1/.*/run
DELETE route.
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Admin pages with WP 5.x styling | Design tokens + DataViews aesthetic | WP 7.0 (April 2026) | Visual reskin; class names preserved |
| No machine-readable capability surface | Abilities API (`/wp-abilities/v1`) | WP 6.9 (Nov 2025) | New REST surface; read-only only so far |
| Post editor sometimes not iframed | Always-iframed post editor | WP 7.0 | wp-sudo challenge page iframe-break logic needed (already done in v2.3.0) |
| "Tested up to: 6.9" | "Tested up to: 7.0" | April 9, 2026 | readme.txt one-line change |

**Current as of 2026-02-19:**
- WP 7.0 Beta 1: shipped today
- WP 7.0 RC 1: expected mid-March (unconfirmed exact date)
- WP 7.0 GA: April 9, 2026 (WordCamp Asia)
- Beta changelog: NOT yet published (confirmed in phase context)

---

## Open Questions

1. **WP 7.0 Beta 1 full changelog**
   - What we know: Beta 1 shipped today; major features include admin refresh, always-iframed editor, Abilities API continuation
   - What's unclear: Exact CSS changes from the admin refresh are not published in a developer-facing diff yet
   - Recommendation: Run the manual test guide against Studio (localhost:8883) immediately; document what visual changes are observed rather than waiting for official changelog

2. **Admin visual refresh: specific CSS changes to admin color values**
   - What we know: Design tokens are being introduced; existing class names preserved; the refresh targets buttons, inputs, notices, tables
   - What's unclear: Whether the `#787c82` color used in disabled plugin action spans conflicts with new design token values
   - Recommendation: Test visually on Studio; if the gray disabled spans look off, verify against the new WP admin color palette

3. **Abilities API WP 7.0 additions**
   - What we know: 3 read-only core abilities exist in WP 6.9; MCP Adapter ships as companion
   - What's unclear: Whether WP 7.0 adds any new abilities beyond the 6.9 baseline
   - Recommendation: Run `wp ability list` on the local dev environment to get the authoritative current list; document in the assessment

4. **WP 7.0 RC timing**
   - What we know: GA is April 9, 2026; standard WP release cycle suggests RC1 ~3 weeks before GA (around March 19)
   - What's unclear: Exact RC1 date not published yet
   - Recommendation: WP70-03 (version bump) can only happen at GA. WP70-01 and WP70-02 should be completed on beta/RC when available.

---

## Sources

### Primary (HIGH confidence)
- Codebase inspection: `wp-sudo.php`, `readme.txt`, `admin/css/*.css`, `includes/class-gate.php`, `includes/class-admin.php` — verified facts about plugin files, CSS selectors, current "Tested up to" value, CI matrix
- `.github/workflows/phpunit.yml` — CI matrix uses `['latest', 'trunk']`; trunk = WP 7.0 alpha; verified no CI changes needed
- `docs/roadmap-2026-02.md` — existing analysis of WP 7.0 changes; WP70-01 through WP70-04 context

### Secondary (MEDIUM confidence)
- [Abilities API REST Endpoints — developer.wordpress.org](https://developer.wordpress.org/apis/abilities-api/rest-api-endpoints/) — REST route structure verified
- [Abilities API in WordPress 6.9 — Make WordPress Core](https://make.wordpress.org/core/2025/11/10/abilities-api-in-wordpress-6-9/) — 3 core abilities, permission_callback pattern
- [From Abilities to AI Agents: Introducing the WordPress MCP Adapter — developer.wordpress.org](https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/) — confirms 3 read-only abilities only as of 7.0 Beta 1
- [What's new for developers? (February 2026) — developer.wordpress.org](https://developer.wordpress.org/news/2026/02/whats-new-for-developers-february-2026/) — always-iframed editor, design token direction
- [Planning for 7.0 — Make WordPress Core](https://make.wordpress.org/core/2025/12/11/planning-for-7-0/) — feature list and release intent
- [WordPress 7.0 Admin Visual Refresh — Trac #64308](https://core.trac.wordpress.org/ticket/64308) — scope: backward-compatible "coat of paint"; existing class names preserved

### Tertiary (LOW confidence)
- [attowp.com WordPress 7.0 Complete Guide](https://attowp.com/trends-news/wordpress-7-0-complete-guide-2026/) — secondary source on admin refresh scope
- [wp-umbrella.com WordPress 7.0 timeline](https://wp-umbrella.com/blog/wordpress-7-0-release-status-and-timeline/) — release schedule (GA April 9 confirmed)

---

## Metadata

**Confidence breakdown:**
- Manual test scope: HIGH — the existing MANUAL-TESTING.md is comprehensive; WP 7.0 section is additive
- Admin visual refresh risk: MEDIUM-LOW — class names preserved per Trac #64308; visual impact is cosmetic
- Abilities API facts: HIGH — three read-only abilities confirmed via multiple official sources; no destructive abilities as of 7.0 Beta 1
- CI matrix: HIGH — verified directly in `phpunit.yml`; trunk already covers WP 7.0 alpha
- Version bump file location: HIGH — verified in `readme.txt` (line 8) and `wp-sudo.php` (no "Tested up to" in plugin header)
- RC/GA dates: MEDIUM — April 9 GA is confirmed; RC1 date not published yet

**Research date:** 2026-02-19
**Valid until:** 2026-03-15 (fast-moving; Beta 2 and RC1 may surface new information; reassess if admin visual refresh specifics are published)
