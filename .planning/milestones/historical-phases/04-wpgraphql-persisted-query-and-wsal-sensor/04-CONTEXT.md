# Phase 4 Context

## Decisions (Locked)

- **Phase dependency:** Phase 4 execution is gated on Phase 3 completion.
- **Persisted-query strategy target:** improve WPGraphQL Limited-mode behavior for persisted queries without breaking current non-persisted mutation gating.
- **Default safety preserved:** if mutation classification is uncertain, behavior must remain fail-safe (do not silently permit destructive mutations).
- **Extension-first design:** persisted-query handling should be pluggable (filter/bridge strategy), not tightly coupled to one WPGraphQL extension implementation.
- **WSAL first:** ship WP Activity Log (WSAL) integration before Stream adapter work.
- **Audit hook source of truth:** adapters consume existing WP Sudo action hooks; no duplicate policy logic in adapters.
- **No regressions in hook contracts:** existing 9 audit hooks and argument shapes remain unchanged.
- **TDD execution:** all feature work starts with failing tests.

## Claude/Codex Discretion

- Exact persisted-query classification API (filter name/signature and precedence).
- Whether to keep core behavior docs-only for persisted queries or add optional bridge helper in this phase.
- WSAL integration packaging approach (bridge file vs optional plugin/module).
- Scope of Stream alignment notes (docs-only in this phase vs implementation deferral).

## Deferred Ideas (Out of Scope)

- Full GraphQL AST parsing pipeline inside core gate.
- New database-backed logging store in WP Sudo.
- Per-action GraphQL rule model replacing current surface policy.
- Modal challenge and broader UX redesign work.

## Success Conditions for Phase 4

- Persisted-query behavior in WPGraphQL Limited mode is explicit, test-covered, and operator-documented.
- WSAL sensor integration maps WP Sudo audit hooks into usable structured events.
- Existing policy/hook contracts remain stable.
- Unit + integration + static analysis + lint all pass.
