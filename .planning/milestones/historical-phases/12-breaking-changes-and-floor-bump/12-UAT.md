---
status: complete
phase: 12-breaking-changes-and-floor-bump
source: [12-01-SUMMARY.md, 12-02-SUMMARY.md, 12-03-SUMMARY.md]
started: 2026-06-16T11:20:00Z
updated: 2026-06-16T21:30:00Z
---

## Current Test

[testing complete]

## Tests

### 1. Compatibility-mode migration notice appears
expected: With wp_sudo_governance_mode='compatibility' and a manager logged in, a persistent non-dismissible warning notice renders on admin screens describing the 4.0.0 removal and telling an admin to remove the option.
result: pass
note: Renders exactly as designed (verified on Studio concrete-light, WP 7.0). UX feedback (NOT a Phase 12 defect): the message uses the jargon term "governance mode" without explanation and gives no actionable path to remove the option. Routed to Phase 13 — where upgrade_4_0_0() auto-deletes the option, making the "admin should remove the option" wording obsolete and the notice self-clearing. Phase 13 should reword for plain language + reflect auto-cleanup.

### 2. Notice is silent when not applicable
expected: With the option removed/absent (or set to 'strict'), NO notice appears. Also, a non-manager (e.g. a Subscriber, or a user lacking manage_wp_sudo) loading wp-admin sees NO notice even while the stale 'compatibility' option is set.
result: pass
note: (a) Set option to 'strict' — notice gone. (b) Non-manager — no notice. Both verified on Studio concrete-light (and backed by AdminTest unit tests test_compatibility_notice_skips_when_option_is_not_compatibility / test_compatibility_notice_skips_when_user_lacks_authority).

### 3. Developer warning logged once per admin load (WP_DEBUG)
expected: With WP_DEBUG + WP_DEBUG_LOG enabled and the stale 'compatibility' option set, loading an admin page as a manager writes a single _doing_it_wrong notice to debug.log naming wp_sudo_governance_mode and version 4.0.0. It does NOT spam on front-end / REST / cron requests.
result: pass
note: Verified live on Studio concrete-light — exactly ONE debug.log entry per admin load (no spam), names wp_sudo_governance_mode + version 4.0.0. UX nit routed to Phase 13: _doing_it_wrong()'s fixed "Function %s was called incorrectly" prefix is a semantic mismatch — wp_sudo_governance_mode is an option, not a function, and nothing was called incorrectly. Phase 13 should pass a real function context, reword to fit the prefix, or use a different dev signal (error_log/trigger_error/audit hook).

### 4. sudo_can() no longer exists
expected: Run `wp eval 'var_dump(function_exists("sudo_can"), function_exists("wp_sudo_can"));'` → sudo_can is false, wp_sudo_can is true. Calling sudo_can() anywhere fatals with "undefined function"; wp_sudo_can('manage_wp_sudo') still works.
result: pass
note: Verified against the installed code (symlink → repo) on Studio concrete-light — sudo_can is defined nowhere (function_exists necessarily false at runtime), wp_sudo_can is defined, and integration test test_sudo_can_alias_was_removed asserts the absence.

### 5. Migration notes are present and accurate
expected: CHANGELOG.md has a 4.0.0 "Breaking changes" block (sudo_can removal, compatibility removal, WP 6.4 / PHP 8.2 floors); readme.txt has a "= 4.0.0 =" Upgrade Notice with action items; docs/developer-reference.md has a "Migrating to 4.0" section with the wp_sudo_can() swap and raised minimums. Wording matches what shipped.
result: pass
note: All three surfaces present and accurate. Enhanced this session (commit d87bedb) to explain WHY compatibility mode was added (3.2.0 transitional bridge for the dedicated-capability model) and removed (model now established; WP_SUDO_RECOVERY_MODE covers lockout recovery), per user request.

## Summary

total: 5
passed: 5
issues: 0
pending: 0
skipped: 0

## Gaps

[none — all tests passed]

## Follow-ups (enhancements routed to Phase 13, NOT Phase 12 defects)

- Migration-notice reword: plain language ("leftover permission-mode setting", drop
  "governance mode" jargon), report the issue as fixed with little explanation, and
  drop the manual-removal instruction. Tracked in 13-CONTEXT.
- Clear-on-detection cleanup so the notice's "clears automatically" promise is
  truthful (clears on the next admin page load), replacing the persistent notice
  with a one-time confirmation. Tracked in 13-CONTEXT.
- _doing_it_wrong() semantic mismatch (option name passed where a function name is
  expected); pass a real function context, reword, or use a different dev signal.
  Tracked in Test 3 note + 13-CONTEXT.
