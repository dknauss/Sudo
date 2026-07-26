# Core Recent-Auth Gate — Architectural Context (strategy)

**Status:** Strategic context, not part of the security proposal.
**Companion to:** [`core-action-gate-proposal.md`](core-action-gate-proposal.md) — the pressing, practical proposal for the security team. This document holds the broader *"which architectural future does WordPress take, and where does a proof-of-intent gate sit under each"* discussion, deliberately kept **out** of the proposal so the security ask stays focused.

**The one-line takeaway:** the security case in the proposal does not depend on this. Whether WordPress modernizes by split, by targeted refactor, or by slower incremental change, it still lacks a first-class proof-of-intent primitive for consequential operations — so the gate is worth landing under any of those futures.

---

## Three perspectives on WordPress's architectural future

This proposal lands in the middle of a broader debate about WordPress's future, and that debate affects how a proof-of-intent primitive should be *framed* (though not whether it is needed). Three perspectives triangulate the same reality:

**Malcolm Peralty — the strongest current split argument.** Between April and May 2026, Peralty published a six-part "What Might WP Next Look Like?" series proposing a split between a long-supported "WP Classic" and a modernized "WP Next." Part 4 ("Performance and Security") is blunt about the runtime: a plugin can `exec()` whatever it wants, read every other plugin's secrets, and exfiltrate anything, because, in Peralty's words, *"there is no permission model at the plugin boundary."* It proposes a four-phase manifest-enforcement strategy (declared-but-not-enforced → API-level enforcement → static analysis → eventual WASM isolation). Notably, across all six parts **proof of intent for consequential operations is never raised**: not in the kernel (Part 2 describes a PSR-15 middleware pipeline over a PSR-11 container with PSR-14 typed events), not in the admin (Part 3: "Next's admin is Classic's admin"), not in the manifest phasing, and Part 6's shared `wp-kernel` security services are CSRF, OAuth, and plugin manifests only. That cuts two ways. The kernel supplies exactly the seam the gate needs (request middleware and typed effect events). And the seam is left empty, which is the clearest evidence the primitive is orthogonal to the split rather than subsumed by it.

**Joost de Valk — the strongest refactor-without-split argument.** [*"WordPress needs to refactor, not redecorate"*](https://joost.blog/wordpress-refactor-not-redecorate/) makes many of the same architectural critiques but argues for targeted refactoring inside the existing project (citing Yoast's Indexables table and WooCommerce HPOS). A small, layered recent-auth primitive is exactly the kind of low-level thing core can introduce incrementally under this model.

**Brian Coords — the practitioner signal.** [*"EmDash: First thoughts and takeaways for WordPress"*](https://www.briancoords.com/emdash-first-thoughts-and-takeaways-for-wordpress/) does not propose a mechanism like this one, but it shows plugin-trust, developer-experience, and structured-content concerns are already active pressures in ordinary WordPress work — the strain is not confined to architecture commentators.

## Why the gate holds under either future

Whether WordPress modernizes by split, by targeted refactor, or by slower incremental change, it still lacks a first-class proof-of-intent primitive for consequential operations. Concretely: even WP Next's `wp-kernel` has no proof-of-intent layer, and the gate is exactly the PSR-15 middleware that fills it — manifests answer *"is this plugin allowed to do this?"*, the gate answers *"is a human intending this right now?"*

The proposal's closure carries the concrete, in-repo instance of that distinction: the WP 7.0 Connectors credential-write path (a single `POST /wp/v2/settings` swapping a `connectors_*_api_key`), which a manifest system authorizes as a *class* of operation while saying nothing about whether a human is intentionally replacing an API key *now*. That example lives in the proposal ([`core-action-gate-proposal.md`](core-action-gate-proposal.md) §3), because it is a concrete security threat inside the minimal closure — not architectural positioning.

---

## References — ecosystem commentary and structural-debate context

- Malcolm Peralty, "What Might WP Next Look Like?" six-part series (April – May 2026): [Part 1](https://peralty.com/2026/04/17/wp-next-part-1-the-case-for-the-split/) · [Part 2: The Kernel](https://peralty.com/2026/04/18/wp-next-part-2-the-kernel/) · [Part 3: The Admin and Editor](https://peralty.com/2026/05/25/what-might-wp-next-look-like-part-3-the-admin-and-editor/) · [Part 4: Performance and Security](https://peralty.com/2026/05/25/what-might-wp-next-look-like-part-4-performance-and-security/) · [Part 5: The Plugin Economy](https://peralty.com/2026/05/25/what-might-wp-next-look-like-part-5-the-plugin-economy/) · [Part 6: The Migration Plan](https://peralty.com/2026/05/26/what-might-wp-next-look-like-part-6-the-migration-plan/)
- Joost de Valk, [*"WordPress needs to refactor, not redecorate"*](https://joost.blog/wordpress-refactor-not-redecorate/) (April 2026).
- Brian Coords, [*"EmDash: First thoughts and takeaways for WordPress"*](https://www.briancoords.com/emdash-first-thoughts-and-takeaways-for-wordpress/) (April 2026).
