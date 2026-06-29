# Phase 3 Context

## Decisions (Locked)

- **Validation boundary:** `Action_Registry::get_rules()` must normalize/validate filtered rule output before `Gate::match_request()` consumes it.
- **Fail closed per invalid rule:** malformed custom rules are dropped silently; valid rules (including built-ins) continue to work.
- **No fatal regressions from filter misuse:** non-array or malformed `wp_sudo_gated_actions` output must not crash runtime matching paths.
- **Preserve extension contract:** keep `wp_sudo_gated_actions` filter and per-request cache semantics (`$cached_rules`) intact.
- **Keep matcher behavior stable for valid input:** no behavior drift for existing built-in rule matches on admin/AJAX/REST surfaces.
- **MU loader path/basename resilience:** remove hardcoded slug dependency in the MU loader activation/path checks; support non-canonical plugin directory names.
- **Diagnosable MU failure mode:** unresolved loader path should fail safely (inert loader) and surface a clear operator signal (admin-facing notice or equivalent).
- **No new storage/services:** no new tables or external services for Phase 3.
- **TDD execution:** test-first for all code changes.

## Claude/Codex Discretion

- Exact normalization helper structure in `Action_Registry` (method decomposition and naming).
- Whether invalid-rule diagnostics are surfaced via a lightweight action hook, admin notice, or both.
- Fallback ordering for MU basename/path resolution.
- Whether to introduce a dedicated resolver class for MU-loader testability.

## Deferred Ideas (Out of Scope)

- REST fast-path performance rewrites in `Gate::intercept_rest()`.
- WPGraphQL persisted-query strategy (Phase 4 scope).
- Logging adapters (WSAL/Stream) (Phase 4 scope).
- Modal challenge architecture changes.

## Success Conditions for Phase 3

- Invalid filtered rule shapes cannot break rule matching at runtime.
- Built-in gating behavior remains unchanged for valid requests.
- MU loader remains reliable when plugin directory slug is non-standard.
- Failure states are diagnosable instead of silent.
- Unit + integration + static analysis + lint all pass.
