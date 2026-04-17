# Action Gate for WordPress Core: An Actions API First, a Gate Second

**Status:** Draft proposal, not adopted by WordPress core.  
**Drafted:** 2026-04-17  
**Author context:** Derived from WP Sudo's production implementation and its comparative analysis in `sudo-architecture-comparison-matrix.md`.  
**Intended audience:** WordPress core contributors, plugin authors evaluating adoption, and WP Sudo operators assessing a plausible migration path.

---

## Executive Summary

WordPress has a mature capability system, a mature authentication system, and a mature hook system. What it does **not** have is a first-class registry of **consequential operations**: actions important enough that core, plugins, audit tools, UI surfaces, and policy systems may all want to identify them consistently.

That missing registry forces every protection layer to invent its own catalog. Plugins such as WP Sudo, Fortress, Wordfence, and Solid Security all identify overlapping sets of dangerous operations, but they do so with different identifiers, different semantics, different enforcement models, and no shared interoperability surface. The result is duplication, inconsistent operator experience, and no standard way for plugins to declare that an operation is consequential enough to observe, decorate, audit, or eventually gate.

This proposal argues that WordPress should solve that problem first.

Specifically, core should introduce a small **Actions API** for consequential operations. The API would let core and plugins register namespaced action identifiers with metadata such as labels, capability expectations, consequence classes, scopes, and annotations like “destructive” or “requires recent authentication.” Core and plugins could then query that registry, emit before/after execution hooks, surface UI affordances, and layer audit or policy tooling on top of it **without** requiring WordPress to standardize reauthentication, challenge UX, replay, or non-interactive surface policy in the same release.

Once such an Actions API exists, a second-layer **Action Gate** becomes much easier to reason about. At that point, WordPress could define a proof-of-intent primitive that consumes registered actions and, for selected operations, asks for fresh proof that a human user is intentionally performing the action now. That later gate layer could use browser reauthentication, passkeys, 2FA-aware handlers, or other challenge mechanisms, but those concerns would sit on top of the shared action vocabulary instead of being entangled with the registry from day one.

The proposal is therefore intentionally phased:

- **Phase 1:** a core **Actions API** that registers consequential operations with namespaced identifiers, metadata, and execution hooks.
- **Phase 2:** an **Action Gate** that consumes those registered actions and adds proof-of-intent requirements for selected operations.
- **Phase 3:** richer surface policy, challenge extensibility, and broader ecosystem adoption.

This proposal does **not** attempt to solve WordPress’s deeper runtime trust problem. Malcolm Peralty’s April 17, 2026 “WP Next” series opens with the argument that WordPress’s plugin contract may require a structural split to repair. Joost de Valk argues that many of the same architectural problems can still be addressed through targeted refactoring within the existing project. Brian Coords’ writing on EmDash reinforces that these concerns are not abstract architecture talk; they are increasingly visible in day-to-day WordPress development and product work. This proposal takes no position on whether WordPress ultimately modernizes by split or by refactor. It makes the narrower claim that **proof of intent for consequential operations remains a distinct and useful primitive under either future**.

---

## 1. The Problem: Capability Is Not Current Human Intent

WordPress’s capability system answers an important question:

> **Is this principal authorized in general to perform this kind of action?**

That question is necessary, but it is not always sufficient.

For some operations, what matters is not only whether a principal is generally authorized, but whether the principal is **currently, intentionally, and interactively** performing that operation now. WordPress has no first-class primitive for expressing that distinction. It has capabilities, role mappings, and permission callbacks, but no built-in way to say:

> “This operation is consequential enough that capability alone should not be the only authorization boundary.”

That gap matters in ordinary failure modes:

- **Stolen browser sessions.** If an attacker obtains a valid admin session cookie, WordPress treats the attacker as fully authorized until that session expires or is revoked.
- **Walk-away devices and inherited trust.** An unlocked laptop, shared workstation, or long-lived admin session can carry broad privileges well beyond the moment the legitimate user last made a conscious trust decision.
- **XSS in an authenticated origin.** If malicious JavaScript executes inside an authenticated admin session, it inherits the ambient authority of that origin.
- **Credential-integrity failures.** Some operations are dangerous not because they reveal secrets but because they replace trusted state—for example, rotating connector or provider API keys.
- **Delegated or long-lived API access.** Application passwords, automation scripts, and service credentials represent legitimate grants, but they do not themselves answer whether a specific consequential action should proceed without additional scrutiny.

These are not hypothetical edge cases. They are common enough that WordPress security plugins already compensate by maintaining their own catalogs of dangerous operations and their own enforcement logic. WP Sudo is one such implementation. Others address overlapping concerns differently. The important point is not that any one implementation should be lifted into core unchanged. It is that **the repeated emergence of these systems is evidence of a missing shared primitive.**

The deeper problem is that WordPress lacks a canonical way to talk about consequential operations at all.

Core has actions and filters, but those are lifecycle and extension hooks, not a formal catalog of “this is an operation with outsized consequence.” Core has capabilities, but capabilities describe broad categories of authorization, not specific high-impact operations as first-class objects. Core now also has the Abilities API, which is relevant because it provides a namespaced registry model and execution hooks for callable abilities. But abilities are not yet the same thing as a shared catalog of consequential operations across core and plugin behavior.

Without such a catalog:

1. **Security and policy plugins duplicate each other’s work.** Each plugin builds its own list of operations to watch or gate.
2. **Plugin interoperability is weak.** Plugins that want to declare “this refund,” “this credential rotation,” or “this user-role promotion” as consequential have no standard, core-defined vocabulary for doing so.
3. **Downstream systems have no common taxonomy.** Audit logging, Site Health diagnostics, admin indicators, plugin permission manifests, and AI-agent boundaries all need a stable language for high-consequence actions if they are to interoperate cleanly.

The missing primitive, then, is not “sudo” by itself. The missing primitive is:

> **a first-class registry of consequential operations that WordPress can name, describe, observe, and later gate consistently.**

That is why this proposal begins with an Actions API rather than jumping directly to a full gate framework.

---

## 2. Threat Model and Security Boundaries

This proposal is about **proof of human intent for consequential operations**. It is not a general security cure, and it should not be evaluated as one.

The proposal assumes that some actions are consequential enough that a normal capability check does not fully answer the security question being asked. The relevant question is not only “does this user have the capability?” but also “has this user or session recently and intentionally confirmed that they mean to do this now?”

### In scope

The proposal is designed to address the following kinds of risk:

#### 2.1 Stolen-session abuse of legitimate privileged operations

If a session cookie is stolen, inherited, or reused on an unlocked device, an attacker can often perform privileged actions that the user would ordinarily be allowed to perform. The problem here is not a missing capability check; it is the absence of a fresh proof-of-intent boundary for selected actions.

#### 2.2 High-consequence operations in authenticated browser contexts

Some operations are simply more dangerous than others even when performed by authorized users. Examples include:

- activating, installing, or deleting plugins
- promoting users to administrator
- deleting users
- editing code-bearing sources
- rotating external credentials

These operations are infrequent, high-impact, and amenable to a deliberate confirmation or recent-auth check.

#### 2.3 Credential replacement and integrity-sensitive state changes

Some admin operations are dangerous not because they reveal secrets but because they replace trusted state. Updating connector or provider API credentials is a good example. The risk is often an integrity failure—substituting attacker-controlled credentials or breaking the trust relationship to an external system—rather than a confidentiality failure alone.

#### 2.4 Shared taxonomy for downstream tooling

Even where no gate is enforced yet, a core registry of consequential actions provides immediate value to:

- audit logging systems
- diagnostics and Site Health-style reporting
- admin UI warning indicators
- plugin permission systems
- AI-agent execution boundaries
- policy and compliance tooling

This proposal therefore treats naming and observing consequential operations as a security primitive in its own right.

### Out of scope

The proposal does **not** solve the broader WordPress runtime trust problem.

#### 2.5 Plugin sandboxing and runtime isolation

Malcolm Peralty’s April 17, 2026 WP Next series opens with the argument that WordPress’s plugin contract—effectively “trust everybody with the whole process”—cannot be fully repaired inside the current backwards-compatibility envelope. Joost de Valk argues that at least some of the same structural deficits can still be addressed by targeted refactoring. This proposal does not attempt to resolve that debate. It assumes only that, whichever path WordPress takes, a proof-of-intent layer remains a distinct concern.

A gate or action registry running inside the same process as core does **not** prevent malicious or compromised plugin code from doing things that never pass through declared, registered, high-level actions.

#### 2.6 Missing authorization checks

If a plugin or core path fails to call `current_user_can()` where it should, this proposal does not fix that bug. The Actions API and any later Action Gate layer are additive to authorization, not replacements for it.

#### 2.7 WAF-style exploit detection

This proposal does not attempt to classify malicious requests, inspect payloads like a firewall, or detect exploit chains. Its purpose is to define and optionally gate **declared consequential operations**, not to detect arbitrary attack traffic.

#### 2.8 Authentication replacement

The proposal does not introduce a new login system. Any future gate layer would build on existing authenticated identities and existing session infrastructure, adding a fresh proof-of-intent requirement for selected operations rather than replacing WordPress login.

### The core boundary claim

The most important boundary claim in this proposal is this:

> A shared Actions API, and later an Action Gate built on top of it, can reduce the risk of stolen-session abuse and integrity-sensitive state changes for declared consequential operations. It cannot, by itself, solve WordPress’s full plugin-runtime trust problem.

That distinction is what keeps the proposal useful. It is not a substitute for structural modernization, but it is also not made irrelevant by structural modernization. Whether WordPress ultimately evolves by targeted refactor, by a split between Classic and Next, or by slower incremental change, **proof of intent for consequential operations remains a real and distinct security concern**.

---

## 3. Why This Matters Now in the Broader WordPress Architecture Debate

This proposal lands in the middle of a broader architectural debate about WordPress’s future. That debate is not just background context; it affects how a proof-of-intent primitive should be framed.

### Malcolm Peralty: the strongest current split argument

On April 17, 2026, Malcolm Peralty launched the first post in a new six-part WP Next series with [“A Letter to Matt on WP Next: Part 1 – The Case for the Split”](https://peralty.com/2026/04/17/a-letter-to-matt-on-wp-next-part-1-the-case-for-the-split/). Part 1 is not yet a complete architecture plan. It is the opening diagnosis in an unfolding series published today. Its value for this proposal is that it names the runtime problem directly: WordPress’s plugin contract is still effectively “trust everybody with the whole process,” and Peralty argues that a clean structural split may be the only honest way to repair that.

This proposal agrees with Peralty’s runtime diagnosis while stopping well short of claiming that an Action Gate primitive is a substitute for the split he is proposing. If Peralty is right, then this proposal is not the answer to WordPress’s deepest trust problem. It is, at best, a backward-compatible hardening layer for consequential operations in the existing runtime, and a conceptual model that could survive into a more modernized runtime later.

### Joost de Valk: the strongest current refactor-without-split argument

Joost de Valk’s [“WordPress needs to refactor, not redecorate”](https://joost.blog/wordpress-refactor-not-redecorate/) makes many of the same underlying architectural critiques as Peralty—especially around the plugin permission model, data model, and developer experience—but reaches a different conclusion. Instead of arguing for a split, de Valk argues for targeted, structural refactoring inside the existing project, citing precedents such as Yoast’s Indexables table and WooCommerce HPOS.

That view is especially relevant here because a small, layered primitive such as an Actions API is much more plausible under a refactor-in-place model than a much larger “WordPress must first split” frame. If de Valk is right, a shared registry of consequential actions is exactly the kind of low-level primitive core can introduce incrementally without resolving every other architectural question first.

### Brian Coords: the practitioner signal

Brian Coords’ [“EmDash: First thoughts and takeaways for WordPress”](https://www.briancoords.com/emdash-first-thoughts-and-takeaways-for-wordpress/) does not propose a mechanism like this one, but it matters because it shows that these concerns are already visible in ordinary WordPress product and development work. His observations about plugin trust, developer experience, decision fatigue, and the tradeoffs of WordPress’s current extensibility model are a practitioner signal: the architectural strain is not just theoretical, and it is not confined to a small number of architecture-focused commentators.

### Why this proposal still matters under either future

These three perspectives are useful because they triangulate the same reality from different angles:

- Peralty: the strongest argument that WordPress may need a structural split
- de Valk: the strongest argument that WordPress can still refactor its way toward a healthier architecture
- Coords: the strongest practitioner signal that these problems are already affecting real development and product work

This proposal does not require the WordPress project to choose among those futures before acting. Instead, it makes a narrower claim:

> Whether WordPress modernizes by split, by targeted refactor, or by slower incremental change, it still lacks a shared vocabulary for consequential operations, and it still lacks a first-class proof-of-intent primitive for those operations.

A split does not make that concern disappear. A refactor does not make it disappear either. In a more isolated future runtime, the gate becomes one layer above capability grants. In today’s runtime, it is a backward-compatible hardening measure. In both cases, the semantics of “this operation is consequential enough that it deserves first-class treatment” remain useful.

---

## 4. What This Proposal Is—and Is Not

The proposal is easier to evaluate when its layers are separated cleanly. One reason WordPress security discussions become muddled is that several different concerns are often collapsed into one argument: naming consequential operations, authorizing them, requiring fresh proof of intent, restricting plugin privileges, and isolating plugin runtime behavior. These are related, but they are not the same problem and they do not need to be solved in one proposal.

This document therefore treats the work as a stack:

1. **Actions API** — a shared registry and vocabulary for consequential operations.
2. **Action Gate** — a proof-of-intent layer that may consume that registry later.
3. **Future policy and manifest systems** — higher-level consumers that may use the same taxonomy.

Keeping those layers distinct is not just editorial hygiene. It is the main design discipline that makes the proposal plausibly landable in core.

### 4.1 Phase 1: Actions API

The Actions API is the smaller, more landable primitive. Its purpose is to let WordPress name consequential operations explicitly, attach metadata to them, and expose a stable registry that multiple systems can consume. In practical terms, it provides:

- a shared registry of consequential operations
- namespaced identifiers
- metadata and annotations
- before/after execution hooks
- queryability for core, plugins, UI, and tooling

Crucially, it does **not** require core to settle challenge UX, recent-auth semantics, replay behavior, non-interactive surface policy, or operator-facing configuration in the same release. That is what makes it useful as a first primitive rather than an all-at-once security framework.

### 4.2 Phase 2: Action Gate

The Action Gate is a consumer of the Actions API, not a replacement for it. It assumes that WordPress already has a stable way to say “this operation is consequential” and then asks a second question: should this request proceed immediately, should it require fresh proof of human intent, or should it be blocked by policy? At that point, the gate adds:

- a decision object
- a proof-of-intent requirement for selected registered actions
- a recent-auth or sudo-session model
- challenge transport and extensibility
- eventually, surface-specific policy

### 4.3 Not a capability overhaul

This proposal does not replace `current_user_can()` or change the role/capability model. Capability checks still answer whether a principal is authorized in general. The gate layer, if added later, answers whether that principal has freshly demonstrated human intent for a selected consequential operation. The proposal therefore sits *above* capabilities, not in place of them.

### 4.4 Not a plugin permission manifest

A future plugin permission or capability-manifest system could use the Actions API’s taxonomy, but this proposal does not attempt to design that system. It only tries to create a registry and vocabulary that such a system could consume later. This distinction matters because a manifest system is a much larger governance and compatibility problem than a registry of consequential actions.

### 4.5 Not runtime isolation

The proposal does not claim to sandbox plugin code, restrict filesystem access, or solve WordPress’s shared-process trust problem. It is intentionally weaker than that. A shared registry of consequential actions, and even a later proof-of-intent gate, can only constrain *declared operations that pass through known enforcement points*. They do not repair the fact that today’s plugin contract still grants code running inside WordPress the effective privileges of the WordPress process itself.

This explicit limitation is a strength. It keeps the proposal honest about what it can and cannot do, and it prevents the Action Gate layer from being misread as a substitute for broader runtime reform.

---

## 5. Phase 1: A Core Actions API for Consequential Operations

The first thing WordPress should standardize is **naming and observing consequential operations**, not universal reauthentication behavior. That ordering is deliberate. A registry of consequential actions is valuable even if WordPress never adopts a full core-managed gate, and it is much easier to introduce incrementally than a challenge, replay, and policy framework that tries to standardize every privileged surface at once.

Put differently: if WordPress cannot yet agree on how to challenge a user before a sensitive action, it can still agree that the action is sensitive and deserves a first-class name.

### Goals of Phase 1

- Give core and plugins a shared vocabulary for consequential operations.
- Let multiple systems consume that vocabulary without each inventing its own catalog.
- Support admin UI, audit logging, diagnostics, plugin interop, and future proof-of-intent tooling.
- Make the first primitive small enough that it could plausibly land in core without depending on challenge UX or non-interactive policy decisions.

### Why an Actions API first

An Actions API is valuable even if a universal gate never ships in core.

It gives the ecosystem:

- **a stable taxonomy** of consequential operations
- **execution hooks** for audit and observability
- **queryable metadata** for UI and diagnostics
- **an interoperability surface** for plugins that want to declare consequential operations
- **a foundation** for later gating, manifests, or AI-agent boundaries

This is the strongest wedge because even people who are skeptical of core-managed reauthentication may still agree that WordPress needs a first-class catalog of consequential operations. It is also the part of the proposal most compatible with both of the broader modernization paths now being argued in public:

- under a **refactor-in-place** model, it is exactly the kind of small, structural primitive core can introduce without waiting for total consensus on broader reform;
- under a **split / WP Next** model, it is a semantic layer that remains useful even if the underlying runtime, permission model, and enforcement mechanisms eventually change.

The Actions API is therefore the proposal’s lowest-risk, highest-leverage starting point.

---

## 6. Naming, Taxonomy, and Relationship to the Abilities API

The Actions API should use namespaced, action-oriented identifiers, but it should not misstate the Abilities API convention. That matters because one of the proposal’s goals is to reduce conceptual duplication inside WordPress, not create unnecessary vocabulary drift.

The official Abilities API convention is `namespace/ability-name`, using lowercase alphanumerics, hyphens, and one forward slash. This proposal should align with that general shape unless it has a compelling reason to diverge. It should not claim that dotted identifiers such as `core/plugins.activate` are themselves the Abilities convention, because they are not.

### Recommended naming convention

Use:

- `namespace/action-name`

Examples:

- `core/activate-plugin`
- `core/delete-user`
- `core/promote-user`
- `core/update-connector-credentials`
- `woocommerce/refund-order`
- `memberpress/manual-grant-membership`

This keeps the structure familiar, interoperable, and easy to explain. It also lets the proposal reuse the general namespacing discipline WordPress is already introducing elsewhere instead of inventing a second, near-but-not-quite-compatible pattern.

### Taxonomy fields

An action registration may carry multiple layers of meaning, and those layers should be named distinctly:

- **ID** — the namespaced identifier
- **label** — human-readable text
- **capabilities** — expected capability checks for the operation
- **category** — broad grouping such as plugin management or user management
- **consequence class** — a risk-oriented classification such as code execution, privilege escalation, destructive deletion, or external credential mutation
- **scope** — a grouping that a future gate layer may use when deciding how proof-of-intent is reused
- **annotations** — optional booleans or strings such as `destructive`, `requires_recent_auth`, or `consent_required`

One reason to be explicit here is that WordPress has historically overloaded terminology in security and permissions discussions. This proposal should avoid doing that again. “Category,” “consequence class,” “scope,” and “annotation” are not interchangeable and should not be treated as such.

The Abilities API remains relevant in two ways:

1. It demonstrates a useful registry pattern and namespacing discipline.
2. Some future actions may map directly to ability execution paths.

But actions and abilities should not be forced into one object model too early. An ability is an executable unit with input, permission, and output behavior. An action, in this proposal, is a consequential operation worth naming, observing, and potentially gating. Some abilities may correspond directly to actions; some actions may wrap non-ability code paths; some may eventually be backed by ability execution. The important thing is that the proposal acknowledges the relationship without pretending the two concepts are already identical.

---

## 7. Mock Actions API

### Registration

```php
wp_register_action(
	'core/activate-plugin',
	[
		'label'             => __( 'Activate a plugin' ),
		'description'       => __( 'Enable plugin code that runs with site privileges.' ),
		'capabilities'      => [ 'activate_plugins' ],
		'category'          => 'plugin-management',
		'consequence_class' => 'code-execution',
		'scope'             => 'plugins',
		'annotations'       => [
			'destructive'          => false,
			'requires_recent_auth' => true,
			'consent_required'     => false,
		],
	]
);
```

### Querying

```php
wp_get_action( 'core/activate-plugin' );    // array|null
wp_get_actions();                           // array<string, array>
wp_action_exists( 'core/activate-plugin' ); // bool
```

### Execution hooks

```php
do_action( 'wp_before_execute_action', 'core/activate-plugin', $context );
do_action( 'wp_after_execute_action', 'core/activate-plugin', $context, $result );
```

### Optional wrapper helper

```php
$result = wp_execute_action(
	'core/activate-plugin',
	[
		'plugin'       => $plugin,
		'network_wide' => $network_wide,
	],
	static function () use ( $plugin, $redirect, $network_wide, $silent ) {
		return activate_plugin_internal( $plugin, $redirect, $network_wide, $silent );
	}
);
```

### What Phase 1 should and should not do

**Phase 1 should:**

- register consequential operations
- expose metadata
- fire execution hooks
- support admin UI, diagnostics, manifests, and audit consumers

**Phase 1 should not require:**

- challenge UI
- stash/replay
- sudo sessions
- non-interactive policy
- universal redirect/403 behavior

---

## 8. Initial Core Catalog for Phase 1

The initial catalog should be small, explicit, and focused on clearly human-driven, high-consequence actions. It should avoid generic low-level setters and speculative catch-all entries.

### Recommended initial core catalog

| Action ID | Backing core function(s) or flow |
|---|---|
| `core/activate-plugin` | `activate_plugin()`, plugin activation flows |
| `core/install-plugin` | plugin upload and installer flows |
| `core/delete-plugin` | `delete_plugins()` |
| `core/delete-user` | `wp_delete_user()` |
| `core/promote-user` | role change to administrator or super-admin equivalent |
| `core/update-connector-credentials` | `/wp/v2/settings` writes containing `connectors_*_api_key` |

### Why keep it this small

A first catalog should avoid:

- generic `update_option()` mappings
- speculative “all destructive abilities” umbrella entries
- fuzzy export entries that are not clearly backed by one privileged function boundary
- broad multisite coverage before network-session semantics are thought through

The first goal is not catalog completeness. It is to establish a stable, credible pattern.

---

## 9. What an Actions API Enables Immediately

Even before any proof-of-intent enforcement exists, a shared action registry has immediate value.

### 9.1 Audit logging

Core and plugins can emit consistent before/after execution events using shared action IDs rather than plugin-specific guesses.

### 9.2 Admin UX indicators

Screens that invoke registered consequential operations can show warning markers, stronger affordances, or explanatory text.

### 9.3 Diagnostics and Site Health

Operator tooling can report which consequential operations exist, which plugins register their own actions, and where later gate policy would apply.

### 9.4 Plugin interoperability

Plugins with privileged operations can register them in a way that downstream tooling can understand without custom integrations.

### 9.5 Foundation for future manifests and AI boundaries

A future plugin capability-manifest system, or an AI-agent policy layer, needs a stable taxonomy of consequential operations. An Actions API provides it without forcing the full manifest or AI policy system to exist yet.

---

## 10. Phase 2: The Action Gate as a Consumer of the Actions API

Once WordPress has a shared registry of consequential operations, a second-layer proof-of-intent system becomes much easier to define.

The Action Gate is not the first primitive. It is a **consumer** of the Actions API.

Its job is to answer a narrower question:

> Given a registered consequential action, should this request be allowed to proceed immediately, should it require fresh proof of human intent, or should it be blocked by policy?

This means the gate layer should build on action metadata rather than introduce a second, unrelated registry.

---

## 11. Mock Gate API

### Enforcement

```php
$decision = wp_enforce_action_gate(
	'core/activate-plugin',
	[
		'context' => [
			'plugin'       => $plugin,
			'network_wide' => $network_wide,
		],
	]
);
```

### Decision object

```php
$decision->passed();           // bool
$decision->needs_challenge();  // bool
$decision->blocked();          // bool
$decision->reason();           // 'passed' | 'no_session' | 'expired' | 'policy_blocked' | 'rate_limited'
$decision->challenge_url();    // string|null
$decision->as_rest_error();    // WP_Error|WP_REST_Response
```

### Transport separation

A key design rule for the gate layer should be:

- business logic receives a decision object
- admin UI adapters decide redirect behavior
- REST adapters decide 403 response behavior
- AJAX adapters decide JSON error behavior

That keeps transport handling out of privileged business functions as much as possible.

---

## 12. Session Model Options for the Gate Layer

The current WP Sudo implementation uses user meta plus a browser-bound cookie to represent the sudo session. That is valid prior art, but core should not assume that the plugin’s storage choice is automatically the best core design.

### Option A: extend `WP_Session_Tokens`

Core already has a session-token abstraction in [`WP_Session_Tokens`](https://developer.wordpress.org/reference/classes/wp_session_tokens/). It:

- binds sessions to issued tokens
- supports revocation
- stores attached session information
- already participates in WordPress’s existing session lifecycle

Using that infrastructure for gate-related state may be a more core-native direction than inventing a fully parallel store.

### Option B: separate gate or sudo session store

A distinct store—whether user meta or a dedicated table—may still be justified if action-gate state needs to be modeled separately from normal login sessions. The tradeoff is that WordPress would then maintain two session-adjacent models.

### Open design choice

The proposal should not prematurely settle this question. It should present it as a genuine design decision for Phase 2.

---

## 13. Challenge Model and Extensibility

A future gate layer needs a challenge model, but Phase 2 should still start small.

### Recommended Phase 2 baseline

- browser-first
- dedicated challenge endpoint or page
- existing authenticated user context
- minimal recent-password or recent-auth verification
- redirect or restart path back to the interrupted action

### What should be deferred initially

The proposal should explicitly defer richer complexity until a later interface revision:

- WebAuthn ceremony details
- external IdP redirects
- multi-step TOTP and recovery-code flows
- asynchronous or pending challenges
- consent overlays layered on top of a successful challenge

### Why this matters

The current ecosystem absolutely includes 2FA plugins, passkey plugins, and enterprise identity providers. But that does not mean a first core gate interface needs to standardize their entire lifecycle in v1.

---

## 14. Surface Model: What Belongs in Early Phases and What Does Not

One of the main weaknesses of all-at-once gate proposals is that they try to unify browser, REST, application-password, WP-CLI, cron, XML-RPC, and WPGraphQL behavior immediately.

This proposal recommends a narrower approach.

### Early-phase surfaces

- browser-driven admin UI
- possibly cookie-authenticated REST requests where the transport model is well understood

### Later-phase surfaces

- application-password-authenticated API requests
- WP-CLI
- wp-cron
- XML-RPC
- WPGraphQL

### Why defer them

These surfaces have materially different semantics and operator expectations. They should not be bundled into the first core proof-of-intent primitive unless there is a fully sourced, well-defined implementation story for each. Application Passwords, in particular, are broader than a REST-only concept; they are API credentials used for REST and, where enabled, XML-RPC.

---

## 15. Rollout and Compatibility Strategy

This proposal should be framed in phases rather than tied too tightly to speculative future WordPress version numbers.

### Phase A

Ship the Actions API with a small initial core catalog and execution hooks.

### Phase B

Encourage core and plugin adoption of the catalog for UI, audit, and diagnostics.

### Phase C

Introduce a browser-first Action Gate for selected actions, with minimal challenge behavior.

### Phase D

Expand surface support, challenge extensibility, and richer policy only after the initial model is proven.

This makes the proposal more realistic than presenting a multi-release schedule with specific enforcement flips already mapped to future version numbers.

---

## 16. Relationship to WP Sudo

WP Sudo remains the most relevant production prior art for this proposal.

### What WP Sudo already proves

- a catalog of consequential operations is useful
- a browser-scoped proof-of-intent model is operationally viable
- request interruption and later resumption can be made usable
- audit hooks for gate outcomes are valuable
- per-surface policy is a real operator need

### Where this proposal intentionally diverges

This document is not a verbatim transliteration of WP Sudo into core. It intentionally diverges in several ways:

- it separates the **registry** from the **gate**
- it treats the Actions API as the first primitive, not the gate
- it does not assume WP Sudo’s storage model is automatically the right core choice
- it does not assume WP Sudo’s exact `Limited` policy semantics should become the core surface vocabulary
- it keeps early phases smaller and more browser-focused than WP Sudo’s full multi-surface implementation

### What WP Sudo would become if core shipped this

If WordPress eventually shipped an Actions API, and later a core Action Gate, WP Sudo would likely evolve from “full sudo implementation” into:

- opinionated policy defaults
- operator UI and diagnostics
- compatibility bridges
- richer multisite and advanced-policy tooling
- stricter defaults than core

---

## 17. How This Fits Under Refactor, Split, or No Structural Reform

This proposal is intentionally compatible with multiple possible futures for WordPress.

### If de Valk’s refactor path is right

A small Actions API is exactly the sort of primitive core can introduce incrementally while preserving compatibility.

### If Peralty’s split / WP Next path is right

The Actions API still matters as a semantic model. In a more isolated runtime, proof-of-intent remains relevant above capability grants, even if the implementation form changes.

### If WordPress changes more slowly than either proposal hopes

A shared registry of consequential actions is still a strict improvement over the current state. It gives the installed base a better vocabulary and better interoperability now, even if broader reform takes much longer or never fully arrives.

---

## 18. Open Questions

1. Should the first core primitive be browser-only?
2. Should a future gate layer build on `WP_Session_Tokens` or a separate store?
3. What is the correct integration point for cookie-authenticated REST gating, if core later adds it?
4. Is a scope-bound sudo session actually the right model, or would recent-auth freshness be a better fit for core?
5. What should replace the ambiguous `Disabled / Limited / Unrestricted` vocabulary if core later adds surface policy?
6. Which replay classes are explicitly supported in an early gate implementation, and which are deferred?
7. What is the minimal challenge-provider contract that core can support without overcommitting to every 2FA and passkey flow in v1?

---

## References and Source Notes

### Official WordPress references

- [Abilities API PHP reference](https://developer.wordpress.org/apis/abilities-api/php-reference/)
- [wp_register_ability()](https://developer.wordpress.org/reference/functions/wp_register_ability/)
- [wp_before_execute_ability](https://developer.wordpress.org/reference/hooks/wp_before_execute_ability/)
- [WP_Ability::execute()](https://developer.wordpress.org/reference/classes/wp_ability/execute/)
- [WP_REST_Server::dispatch()](https://developer.wordpress.org/reference/classes/wp_rest_server/dispatch/)
- [WP_REST_Server::respond_to_request()](https://developer.wordpress.org/reference/classes/wp_rest_server/respond_to_request/)
- [Application Passwords handbook](https://developer.wordpress.org/advanced-administration/security/application-passwords/)
- [WP_Session_Tokens](https://developer.wordpress.org/reference/classes/wp_session_tokens/)

### WP Sudo project references

- `includes/class-gate.php`
- `includes/class-sudo-session.php`
- `includes/class-action-registry.php`
- `includes/class-challenge.php`
- `docs/sudo-architecture-comparison-matrix.md`
- `docs/security-model.md`
- `docs/connectors-api-reference.md`
- `docs/abilities-api-assessment.md`
- `docs/wordpress-core-authentication.md`

### Ecosystem commentary and structural-debate context

- Malcolm Peralty, [“A Letter to Matt on WP Next: Part 1 – The Case for the Split”](https://peralty.com/2026/04/17/a-letter-to-matt-on-wp-next-part-1-the-case-for-the-split/) (2026-04-17). The opening post in a newly launched multi-part series arguing for a split between a long-supported “Classic” line and a modernized “Next” line.
- Joost de Valk, [“WordPress needs to refactor, not redecorate”](https://joost.blog/wordpress-refactor-not-redecorate/) (2026-04-03). Argues that WordPress’s architectural deficits are real but can still be addressed through targeted refactoring without a split.
- Brian Coords, [“EmDash: First thoughts and takeaways for WordPress”](https://www.briancoords.com/emdash-first-thoughts-and-takeaways-for-wordpress/) (2026-04-02). Practitioner commentary showing that plugin trust boundaries, developer experience, and structured-content concerns are already active pressures in real WordPress work.
