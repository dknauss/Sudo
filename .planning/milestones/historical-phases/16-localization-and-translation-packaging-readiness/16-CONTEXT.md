# Phase 16: Localization and Translation Packaging Readiness - Context

**Gathered:** 2026-06-28
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 16 delivers release-ready localization packaging mechanics for Sudo: a repeatable POT generation command, a release-gate freshness check, targeted translator-comment cleanup, and documentation so maintainers can keep translation sources current before tags and eventual WordPress.org submission. It does **not** add actual translations, translation-service integration, Gutenberg UX work, or any new user-facing product capability.

</domain>

<decisions>
## Implementation Decisions

### Tooling command shape
- Provide a Composer-facing localization check command as the main release validation entry point.
- Use standard WordPress/WP-CLI i18n extraction underneath rather than introducing a JavaScript build process or custom extractor.
- The default check command should be **check-only**: it should fail when the committed POT is stale, not mutate files during validation.
- Provide a separate explicit generation/update command for maintainers to refresh the POT intentionally.
- Extraction scope should cover shipped PHP and admin/challenge JavaScript strings only; do not extract tests, docs, vendor, `.tmp`, or demo-only content.

### Translation artifact policy
- Commit a generated `languages/wp-sudo.pot` as the canonical translation template for release packaging.
- Keep `/languages` limited to the POT plus existing safety/placeholder files; do not add generated `.po` or `.mo` translations in this phase.
- POT headers should be release-grade, with appropriate project/textdomain/package metadata rather than generic tool defaults.
- Future string changes should update the POT through the explicit generation command, not hidden test/pre-commit mutation.

### Enforcement strictness
- Treat i18n freshness as a **release gate first**, not an always-required CI/pre-commit gate immediately.
- The hard failure condition for the check should be a stale POT diff: regenerating the POT from source differs from committed `languages/wp-sudo.pot`.
- Translator-comment coverage should be a targeted review of ambiguous or placeholder-heavy strings, not a blanket rule for every placeholder string.
- Initial invocation/documentation should live in Composer scripts plus release/.org readiness checklist docs; defer GitHub required-check or pre-commit enforcement until the workflow proves stable.

### Release documentation and framing
- Document the release execution path in the WordPress.org/submission readiness checklist.
- Add developer/contributor-facing notes where appropriate so future maintainers understand how to regenerate/check the POT and why the textdomain remains `wp-sudo`.
- Immediate audience is maintainers and WordPress.org reviewers, not active translation community management.
- Explicitly preserve the product-name/package split: UI/product name is “Sudo”; slug/textdomain remain `wp-sudo` intentionally.
- Do not create sample or seeded translations in this phase.

### Implementation Discretion
- Planner may choose exact Composer script names, provided one command is check-only and one command regenerates the POT explicitly.
- Planner may choose whether to implement the stale-POT check as a shell script, PHP script, or thin Composer wrapper around WP-CLI, provided no production dependency is added.
- Planner may choose the exact documentation location for contributor notes if an existing doc is a better fit than creating a new file.

</decisions>

<specifics>
## Specific Ideas

- Prefer command names that fit the existing Composer validation style (`composer test`, `composer lint`, `composer analyse`, `composer verify:metrics`), e.g. an i18n check and an i18n make/update command.
- Keep `.org` publication status clear: this phase improves readiness while submission remains delayed/on hold.
- Release-check docs should distinguish “required before tags” from “required before `.org` submission/upload” where relevant.

</specifics>

<code_context>
## Existing Code Insights

### Reusable Assets
- `composer.json` scripts already define release/validation commands (`test`, `lint`, `analyse`, `verify:metrics`, `sbom`); Phase 16 should add localization commands in this same surface.
- `languages/` exists but currently contains only `.gitkeep` and `index.php`; it is ready to receive `wp-sudo.pot`.
- Existing shell validation pattern: `bin/verify-metrics.sh` backs `composer verify:metrics`; an i18n check can follow this pattern if a wrapper script is useful.
- `docs/wporg-submission-checklist.md` is the operational WordPress.org readiness checklist and should reference the i18n release gate.

### Established Patterns
- The plugin has zero production dependencies; Phase 16 must keep localization tooling as dev/release tooling only.
- Admin JavaScript is shipped directly from `admin/js/` with no build step; extraction/checking should account for JS strings without introducing a build pipeline.
- Textdomain in source is `wp-sudo`; product/UI naming is “Sudo.” This split is already an intentional release/slug decision.
- Validation commands are expected to be deterministic and safe to run from the repo root.

### Integration Points
- Composer script additions in `composer.json`.
- Potential helper script under `bin/` if needed for stale-POT diff behavior.
- POT output under `languages/wp-sudo.pot`.
- Documentation touchpoints: `docs/wporg-submission-checklist.md` and an existing developer/contributor-facing doc if appropriate.

</code_context>

<deferred>
## Deferred Ideas

- Creating real locale translations or sample `.po`/`.mo` files — future/community translation work, not Phase 16.
- Making i18n checks a required GitHub Actions or pre-commit gate — consider later after the workflow proves stable.
- Translation-service integration or active translator contribution process — future project/process work.

</deferred>

---

*Phase: 16-localization-and-translation-packaging-readiness*
*Context gathered: 2026-06-28*
