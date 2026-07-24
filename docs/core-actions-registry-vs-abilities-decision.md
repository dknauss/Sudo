# Decision Memo: Consequential-Actions Registry vs. Abilities-API Metadata

**Status:** Draft decision memo, not adopted by WordPress core.
**Drafted:** July 2026
**Resolves:** open question #1 (a new registry vs. consequence-metadata on the Abilities API) in [`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md) §12 — the "one blocking question" that must be settled before the first patch.
**Companion to:** [`core-action-gate-proposal.md`](core-action-gate-proposal.md) (§6 the *why*), [`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md) (the *what to change*), [`abilities-api-assessment.md`](abilities-api-assessment.md) (runtime posture toward the Abilities API).
**Grounding for Abilities-API facts:** most are grounded in [`abilities-api-assessment.md`](abilities-api-assessment.md), which cites official make.wordpress.org / developer.wordpress.org sources. This memo adds **one** directly-verified claim of its own — the behavior of `WP_Ability::execute()` and its `wp_before_execute_ability` hook, verified against WordPress/abilities-api `class-wp-ability.php` and linked inline where used. Verify all of these against live source before quoting in a public post.

---

## The decision, up front

**Ship a standalone consequential-actions registry now — small, pure-data, backed by the plain core functions the gate already protects. Keep it Abilities-*aligned* (reuse the `namespace/name` ID convention) so that *if and when* core grows consequential abilities, the same getters can be extended to also read their `consequence` annotations. That union is a documented future extension, not part of the first patch: nothing populates the ability side today.**

This still rejects the binary the open questions pose. It is not "abilities-only" (Option B, which cannot work — see below), and it is not a registry deliberately *incompatible* with Abilities (a needlessly divergent Option A). It is **Option A done Abilities-aligned**, with union-readiness as a cheap forward option rather than upfront machinery — which is what actually makes the first patch small and landable.

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

The apparent best case for B is *enforcement economy*: hook one ability-execution seam instead of editing ~15 core functions (spec §6 change list). One hook vs. fifteen insertions would be a real architectural pull — except the seam does not exist. `WP_Ability::execute()` fires `wp_before_execute_ability` via a plain `do_action()` and then calls `$this->do_execute( $input )` on the very next line, discarding anything the hook returned (verified against WordPress/abilities-api `includes/abilities-api/class-wp-ability.php`, `execute()` — https://github.com/WordPress/abilities-api/blob/trunk/includes/abilities-api/class-wp-ability.php). So `wp_before_execute_ability` is **observational** — an audit/telemetry point, not a gate: a callback cannot return a `WP_Error` or a challenge to stop the ability. The only way to prevent `do_execute()` from that hook is to `wp_die()` or throw — a blunt request-kill, not the structured challenge-and-replay a reauth gate needs. B therefore buys *no* clean enforcement seam at all.

Two further objections hold independently, even setting the (non-)seam aside:

1. **B presupposes abilitization it can't cheaply deliver.** Any per-ability enforcement only materializes *after* every mutation is an ability — the expensive precondition above.
2. **The chokepoint model is strictly more complete.** The spec gates the **data-layer function** every surface funnels through (§5.1), so a *programmatic* caller — a plugin invoking `wp_update_user()` directly — is covered. Ability-execution hooks (even if they *could* gate) see only mutations routed through `WP_Ability::execute()`, which is almost none today.

## The decision: a standalone registry, Abilities-aligned, union-ready

Phase 1 is a standalone registry. This is essentially **Option A**, and it is the right starting point — not a compromise:

| Concern | Design |
|---|---|
| **Metadata schema** | A `consequence` block — `consequence_class`, `scope`, `annotations.requires_recent_auth`, etc. (spec §4.1) — defined once. |
| **What Phase 1 registers** | The plain-core-function catalog (spec §4.1) as standalone entries. This is the whole of Phase 1. |
| **Naming** | Reuse the Abilities `namespace/name` convention (proposal §6) — the one cheap thing that keeps a future union possible. |
| **The gate's view** | `wp_get_action( $id )` / `wp_get_actions()` over the standalone entries; the gate (spec §4.3) is written against that surface. |

The only thing separating this from a *needlessly* divergent second registry is the shared ID convention. That is the whole point of "Abilities-aligned": an operation that later becomes a consequential ability gets its `consequence` block on the ability, and the same getters can be taught to read it — instead of a duplicate standalone entry that could drift. Alignment is a one-line naming choice, not a subsystem.

### Future extension: reading consequence-annotated abilities (explicitly *not* Phase 1)

If core ever registers consequential abilities, `wp_get_actions()` can be extended to *also* return abilities that carry a `consequence` annotation — one source-blind lookup, no duplicate entries. This is deliberately deferred, for concrete reasons:

- **There is nothing to union today.** Core has three read-only abilities; none are consequential (§ above). The ability side of the union has zero members, so building it now is machinery ahead of need — exactly the over-engineering this repo's `Simplicity First` rule warns against.
- **It is not an enforcement mechanism.** Per the verified note above, the gate cannot enforce at `wp_before_execute_ability`; it enforces at the data-layer chokepoint / REST regardless of where a registry entry came from. So a consequence-annotated ability entry buys **naming, audit, and REST-layer gating — not PHP-path enforcement.** That keeps the extension firmly a Layer-1 (taxonomy) convenience, never load-bearing for the gate.
- **Its only hard rule is trivial and can wait.** If the extension is ever built: **one ID resolves to one record.** An operation is registered in exactly one place — standalone *or* ability, never both — and a duplicate ID is rejected at `init` with `_doing_it_wrong()` (ability authoritative if a host ever permits overlap). That one sentence is the entire collision contract, and it need not exist until the ability side does.

So the recommendation is Option A now, with the shared ID convention as the cheap hook that leaves this extension available later — not a two-source union stood up before it has a second source.

---

## What the first patch looks like under this decision

This is the point of settling the fork — the Phase 1 patch now has a definite shape:

1. **Define the `consequence` metadata schema** (pure data; the fields in spec §4.1).
2. **Ship `wp_register_action()` / `wp_get_action()` / `wp_get_actions()` / `wp_action_exists()`** over the standalone entries. (The getters are the seam where a future ability-reading extension would hook — but Phase 1 reads standalone entries only.)
3. **Register the small core catalog** as standalone entries (because none are abilities today).
4. **Ship the Site Health consumer** (spec §6 row 16) to demonstrate value before any enforcement.

Rows 1–4 are inert naming/observability — shippable alone, exactly the "Phase 1 lands without the gate" property proposal §5 depends on. No abilitization required, no second registry, and the shared ID convention leaves the ability-reading extension available as a cheap later addition.

### MVP status

The `dknauss/consequential-actions` demonstrator implements the **standalone (Option-A) shape**: an `actions()` catalog filtered by `consequential_actions`, with `namespace/action-name` IDs and **no** Abilities-API awareness (metadata was label-only through v0.2.0; enriched to the full `capabilities`/`category`/`consequence_class`/`scope`/`annotations` shape in v0.2.1, merged to `consequential-actions` `main` via PR #2 — a `v0.2.1` release tag is still pending). That is exactly the shape this memo recommends for core too — a standalone registry — so the MVP is a faithful preview, not a simplification of some richer target. The ability-reading extension is a deferred core option, not something the MVP is missing.

The canonical tracker for MVP-vs-design deltas is [`core-sudo-gate-vs-demo-reconciliation.md`](core-sudo-gate-vs-demo-reconciliation.md).

---

## Naming caveat (do not reopen the hook collision)

Proposal §4.0 already flags that "action" collides with `do_action()`/`add_action()`. This decision does not resolve the public name; it only fixes the *architecture*. When the name is chosen, prefer one that reads as "consequential operation" (e.g. `wp_register_consequential_action`, or a `consequence` sub-API of the Abilities registration) over a bare "Actions API," so the registry isn't mistaken for a third hook system. Track this as still-open; it is cosmetic relative to the architectural decision made here.

---

## What this change already reconciles

The same change that adds this memo also applied these reconciliations:

- **`core-sudo-gate-implementation-spec.md`** — §12 Q1 marked resolved (points here); §4.1 describes the standalone registry with the ability-reading extension noted as a deferred option.
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

Not "new registry vs. abilities metadata" — **a standalone, Abilities-*aligned* consequential-actions registry now, with reading consequence-annotated abilities left as a cheap, deferred extension (there is nothing to union yet, and the gate enforces at the chokepoint regardless of an entry's source).** It lands as pure naming in Phase 1 with no dependency on abilitizing core's write path.
