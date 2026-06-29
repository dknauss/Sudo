# Phase 1 Context

## Decisions (Locked)

- **Redaction strategy:** Omit sensitive fields entirely from stored stash data — do NOT replace with sentinel values. The JS replay submits all `post_data` fields as hidden inputs; a sentinel like `__REDACTED__` would be sent as the actual password.
- **Sensitive field default list:** `password`, `user_pass`, `pass1`, `pass2`, `pass1-text`, `pwd`, `token`, `secret`, `api_key`, `api_secret`, `auth_key`, `auth_token`, `access_token`, `private_key`. Case-insensitive matching.
- **Filterable:** Expose sensitive field list via `wp_sudo_sensitive_stash_keys` filter.
- **Per-user stash cap:** Max 5 concurrent stashes per user, evict oldest at write time. Track via `_wp_sudo_stash_keys` user meta.
- **Stash cap NOT filterable:** Constant, not a filter — this is a security bound.
- **Upload rules:** Add `upload-plugin` and `upload-theme` to Action_Registry. POST-only, same pattern as `plugin.install`.
- **`delete()` signature:** Add optional `$user_id` parameter (default 0, skip index cleanup if 0) for backward compat.
- **Uninstall:** Add `_wp_sudo_stash_keys` to `uninstall.php` metadata cleanup.
- **TDD:** All changes test-first per CLAUDE.md.

## Claude's Discretion

- Implementation ordering within the phase (which workstream first)
- Test method naming and grouping
- Whether GET params also get redacted (research recommends yes, apply consistently)
- Internal method naming (`sensitive_field_keys()`, `redact_params()`, etc.)

## Deferred Ideas

- Modal challenge rewrite (design-heavy, separate milestone)
- Stash encryption at rest (overkill given short TTL + redaction)
- Async/cron-based stash cleanup (eviction at write time is sufficient)

## Delegation Notes

Plans may be structured for execution by Codex or Gemini agents, not only Claude. Each plan should be self-contained with explicit file paths, code locations, and verification commands.
