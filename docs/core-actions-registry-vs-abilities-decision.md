# Decision Memo: Consequential-Actions Registry vs. Abilities-API Metadata

**Status:** Draft decision memo, not adopted by WordPress core.
**Drafted:** 2026-07-24
**Resolves:** open question #1 (a new registry vs. consequence-metadata on the Abilities API) in [`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md) §12 — the "one blocking question" that must be settled before the first patch.
**Companion to:** [`core-action-gate-proposal.md`](core-action-gate-proposal.md) (§6 the *why*), [`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md) (the *what to change*), [`abilities-api-assessment.md`](abilities-api-assessment.md) (runtime posture toward the Abilities API).
**Grounding for Abilities-API facts:** [`abilities-api-assessment.md`](abilities-api-assessment.md), which cites official make.wordpress.org / developer.wordpress.org sources. No new external claims are introduced here; verify against those before quoting in a public post.

---

## The decision, up front

**Build a thin, Abilities-aware consequence-annotation layer — not a second general-purpose registry, and not "abilities only."**

Concretely: Phase 1 ships a small consequence-metadata schema plus a query surface (`wp_get_actions()` / `wp_get_action()`) whose results are a **union** of (a) standalone entries for consequential operations backed by plain core functions, and (b) any registered ability that carries a `consequence` annotation. The gate (Phase 2) reads from that one query surface and never cares which source an entry came from.

This rejects the binary the open questions pose. It is the "align with Abilities but don't collapse into it" position of proposal §6, made concrete enough to write the first patch.

---

## The fork as currently posed

Both companion docs leave the same question open:

- **Option A — a new registry.** `wp_register_action()` in a new `wp-includes/actions-api.php`, a parallel catalog to the Abilities API (proposal §7, spec §4.1).
- **Option B — no new registry.** Express "this operation is consequential" as **metadata on abilities**, reusing the Abilities registry, its `namespace/name` convention, and its `wp_before_execute_ability` / `wp_after_execute_ability` hooks. Spec §12.1 calls this "the lighter landing."

The framing treats these as mutually exclusive. They are not, and picking either pure form is a mistake.

---

## Why pure Option B does not survive contact with the catalog

The decisive fact: **the operations the gate must protect are not abilities, and are not registered in the Abilities API.**

The Phase 1 core catalog (spec §4.1) is `wp_update_user()`, `wp_insert_user()`, `wp_delete_user()`, `activate_plugin()`, `delete_plugins()`, `Plugin_Upgrader::install()`, and role/super-admin changes. As of WP 7.0 the Abilities API registers **three read-only abilities** (`core/get-site-info`, `core/get-user-info`, `core/get-environment-info`) — none of them mutations (abilities-api-assessment.md). So "annotate the ability" has nothing to annotate for any catalog member.

To make Option B work, core would first have to **register the entire consequential mutation surface as abilities** — wrap `wp_update_user()`, `activate_plugin()`, and the rest as `WP_Ability` objects. That is a far larger, separately contested project than a proof-of-intent gate, and it would tie this proposal's landing to an "abilitize core's write path" effort that has no consensus and no timeline. Coupling a small, landable security primitive to that is exactly the entanglement proposal §4 and §6 warn against.

### The strongest argument *for* B — and why it still doesn't flip the decision

The best case for B is enforcement economy: `WP_Ability::execute()` already fires `wp_before_execute_ability` (abilities-api-assessment.md). *If* the catalog were abilities, the gate could hook that single seam instead of editing ~15 core functions (spec §6 change list). One hook vs. fifteen insertions is a real architectural pull.

It does not flip the decision, for two reasons:

1. **It presupposes the abilitization that B can't cheaply deliver.** The economy only materializes *after* every mutation is an ability — the expensive precondition above.
2. **Even then it would be less complete than the chokepoint model.** The spec deliberately gates the **data-layer function** every surface funnels through (§5.1), precisely so a *programmatic* caller — a plugin invoking `wp_update_user()` directly — is covered. Gating only `wp_before_execute_ability` would miss any mutation reached without going through the ability execution path, which is most of them today. The chokepoint model is strictly more complete regardless of whether the op is also an ability.

## Why pure Option A is also wrong

Two independent registries with overlapping purpose is the drift surface this repo's culture explicitly fights (`llm-lies-log.md`, the single-source `current-metrics.md` pattern). If some operation later becomes an ability *and* is consequential, a standalone-registry-only design forces a duplicate entry that can silently disagree with the ability. Proposal §6 already commits to reusing Abilities' namespacing and hook shape; a pure parallel registry that merely *looks* like Abilities without being able to consume ability annotations throws that alignment away.

## The recommended shape (Option C): one query surface, two sources

| Concern | Design |
|---|---|
| **Metadata schema** | A `consequence` block — `consequence_class`, `scope`, `annotations.requires_recent_auth`, etc. (spec §4.1) — defined **once**, usable in either place. |
| **Plain-core-function ops** (today's whole catalog) | Registered as standalone consequential-action entries. This is what Phase 1 actually ships. |
| **Ops that are (or become) abilities** | The same `consequence` block attaches to the ability registration; no duplicate action entry. |
| **The gate's view** | `wp_get_action( $id )` returns the merged record regardless of source; `wp_get_actions()` returns the union. The gate (spec §4.3) is written against this surface and is source-blind. |
| **Naming** | Keep the Abilities `namespace/name` convention (proposal §6) so an ability and a standalone action share an ID space and can never collide-yet-differ. |

This captures B's alignment benefit (one metadata vocabulary, one hook shape, one ID space, and automatic pickup of consequential abilities when they eventually exist) without paying B's precondition (no need to abilitize core's write path first), and without A's drift (no second source of truth for an operation that is an ability).

---

## What the first patch looks like under this decision

This is the point of settling the fork — the Phase 1 patch now has a definite shape:

1. **Define the `consequence` metadata schema** (pure data; the fields in spec §4.1).
2. **Ship `wp_register_action()` / `wp_get_action()` / `wp_get_actions()` / `wp_action_exists()`** where the getters return the **union** of standalone entries and consequence-annotated abilities. Reading abilities is a query against the existing Abilities registry — not a second copy.
3. **Register the small core catalog** as standalone entries (because none are abilities today).
4. **Ship the Site Health consumer** (spec §6 row 16) to demonstrate value before any enforcement.

Rows 1–4 are inert naming/observability — shippable alone, exactly the "Phase 1 lands without the gate" property proposal §5 depends on. No abilitization required, no second-registry drift introduced, and consequential abilities are picked up for free if and when core adds them.

### MVP status (verified 2026-07-24)

The `dknauss/consequential-actions` demonstrator implements the **standalone (Option-A) shape**: an `actions()` catalog filtered by `consequential_actions`, with `namespace/action-name` IDs and **no** Abilities-API awareness (metadata was label-only through v0.2.0; enriched to the full `capabilities`/`category`/`consequence_class`/`scope`/`annotations` shape in v0.2.1, merged to `consequential-actions` `main` on 2026-07-24 via PR #2 — a `v0.2.1` release tag is still pending). That is the right choice for a five-minute wedge — at MVP scale a union with abilities that have no consequential members would be pure overhead — so the recommendation here is **not** that the MVP model the union surface. It stays a faithful preview of a *standalone* Layer 1 entry, and the union-with-abilities query surface is the delta a core patch adds on top.

The canonical tracker for MVP-vs-design deltas is [`core-sudo-gate-vs-demo-reconciliation.md`](core-sudo-gate-vs-demo-reconciliation.md); this note only records the one thing that doc predates — the union refinement — and its conclusion (MVP stays standalone-shaped) so the two don't diverge.

---

## Naming caveat (do not reopen the hook collision)

Proposal §4.0 already flags that "action" collides with `do_action()`/`add_action()`. This decision does not resolve the public name; it only fixes the *architecture*. When the name is chosen, prefer one that reads as "consequential operation" (e.g. `wp_register_consequential_action`, or a `consequence` sub-API of the Abilities registration) over a bare "Actions API," so the union design above isn't mistaken for a third hook system. Track this as still-open; it is cosmetic relative to the architectural decision made here.

---

## What this change already reconciles

The same change that adds this memo also applied these reconciliations:

- **`core-sudo-gate-implementation-spec.md`** — §12 Q1 marked resolved (points here); §4.1 now describes the union-with-abilities query surface.
- **`core-action-gate-proposal.md`** — §6's "align but don't collapse" now cites this memo as the concrete resolution.

Remaining follow-up: the public *name* for the API (proposal §4.0 naming caveat) is still open; §18's open questions don't include the registry fork, so nothing there changes.

## Sub-questions this memo does *not* resolve (still for core review)

These are genuinely independent of the registry fork and remain open (spec §12):

1. `WP_Session_Tokens` extension vs. a dedicated store for the recent-auth window.
2. Flat recent-auth freshness vs. scope-bound windows in v1.
3. Whether `core/create-user` gates all inserts or only privileged-context ones.
4. Default-on vs. default-off for Phase 2 gating.
5. The public name for the annotation API (the naming caveat above).

---

## One-line summary

Not "new registry vs. abilities metadata" — **one consequence-metadata vocabulary and one source-blind query surface, populated by standalone entries today and by consequence-annotated abilities whenever core has them.** It lands as pure naming in Phase 1 with no dependency on abilitizing core's write path, and the gate never has to know the difference.
