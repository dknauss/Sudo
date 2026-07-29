# WP Sudo Documentation Index

*Navigate by question. Each entry is a pointer only — the linked doc is the single
source of truth for its topic; this index never restates content (see
[`llm-lies-log.md`](llm-lies-log.md) for why that discipline exists).*

---

## Start here

| I want to… | Read |
|---|---|
| Understand why the project concluded and must not be installed | [`finding.md`](finding.md), [`audit-verification-record.md`](audit-verification-record.md), and [`post-mortem.md`](post-mortem.md) |
| Understand the final project status and why it must not be installed | [`../PROJECT-STATUS.md`](../PROJECT-STATUS.md) |

## Historical implementation documentation

The documents below describe the prototype before the final audit. They are
retained as evidence and implementation history, not current security or
operational guidance. Where they conflict with the finding, audit record,
post-mortem, or project status above, the final documents control.

| Historical question | Read |
|---|---|
| What did WP Sudo claim to do? | [`FAQ.md`](FAQ.md) |
| What threat model and boundaries did the prototype use? | [`security-model.md`](security-model.md) |
| What hooks, filters, and custom-rule API did it expose? | [`developer-reference.md`](developer-reference.md) |
| How was it compared with other reauth/sudo approaches? | [`sudo-architecture-comparison-matrix.md`](sudo-architecture-comparison-matrix.md) |

## Retained project records

| Question | Canonical source |
|---|---|
| How many surfaces / rules / hooks / fields are there right now? | [`current-metrics.md`](current-metrics.md) |
| What was the final release state before the project concluded? | [`release-status.md`](release-status.md) |
| What does WordPress/Gutenberg actually do at the line we cite? | [`upstream-sources.md`](upstream-sources.md) |

## Retained core-proposal lineage

| Question | Read |
|---|---|
| What architecture and phased evidence program preceded the conclusion? | [Action Gate Research Program charter](../.planning/action-gate-architecture-charter.md) and [GSD roadmap](../.planning/ROADMAP.md) |
| *Why* close the XSS→RCE route with a recent-auth gate, and how does it land? (start here — the security pitch is now merged in) | [`core-action-gate-proposal.md`](core-action-gate-proposal.md) |
| What did the broader implementation inventory identify? (superseded as an implementation plan; retained for seam/route evidence) | [`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md) |
| What did an early sketch of the chokepoint patches look like? ⚠️ **Superseded — known-vulnerable, do not implement** | [`archive/core-sudo-gate-poc-patches.md`](archive/core-sudo-gate-poc-patches.md) (retained for shape only; see the banner at the top of that file) |
| How does WordPress core authentication actually work? | [`wordpress-core-authentication.md`](wordpress-core-authentication.md) |
| Strategic context: which WordPress architectural future, and where the gate fits (not part of the proposal) | [`core-gate-architectural-context.md`](core-gate-architectural-context.md) |

*Archived core-proposal artifacts (see [`archive/`](archive/README.md)): the
superseded registry-vs-Abilities decision memo, the `consequential-actions`
MVP-vs-spec reconciliation, and password-change reauth research. A general
registry is possible future work, not part of Cut 1.*

> **Historical status:** the charter, roadmap, and phase language no longer
> control active work. They are retained to show how the proposal evolved.

## WordPress 7.0 & AI-adjacent surfaces

| Question | Read |
|---|---|
| How does WP Sudo treat the Abilities API at runtime? | [`abilities-api-assessment.md`](abilities-api-assessment.md) |
| How are AI-provider (Connectors) credential writes gated, and why? | [`connectors-api-reference.md`](connectors-api-reference.md) |
| How should AI/agentic tools integrate with WP Sudo? | [`ai-agentic-guidance.md`](ai-agentic-guidance.md) |

## Security analysis & threat modeling

| Question | Read |
|---|---|
| How does a stolen admin cookie become RCE, and where does the gate cut it? | [`stolen-cookie-rce-attack-tree.md`](stolen-cookie-rce-attack-tree.md) |
| What does the privilege-escalation guard block (and not block)? | [`admin-escalation-guard-analysis.md`](admin-escalation-guard-analysis.md) |
| What's the mandatory process for auditing the gate/session? | [`security-audit-methodology.md`](security-audit-methodology.md) |
| Is action-gating coverage complete? | [`security-report-2026-06-gate-completeness.md`](security-report-2026-06-gate-completeness.md) |
| How do I probe WP Sudo for vulnerabilities? | [`vulnerability-testing-guide.md`](vulnerability-testing-guide.md) |
| What is external audit mode? | [`external-audit-mode-spec.md`](external-audit-mode-spec.md) |

## Two-Factor & credential ecosystem

| Question | Read |
|---|---|
| How do I integrate a 2FA plugin with WP Sudo? | [`two-factor-integration.md`](two-factor-integration.md) |
| What does the 2FA reauth flow look like end to end? | [`two-factor-authentication-flow.md`](two-factor-authentication-flow.md) |
| What's the 2FA plugin landscape (for plugin developers)? | [`two-factor-ecosystem.md`](two-factor-ecosystem.md) |
| Do password managers / autofill work on the reauth screens? | [`password-manager-compatibility.md`](password-manager-compatibility.md) |

## Historical testing & release records

| Question | Read |
|---|---|
| What manual/live security tests must pass before release? | [`security-manual-test-checklist.md`](security-manual-test-checklist.md) |
| What are the structured UI/UX testing prompts? | [`ui-ux-testing-prompts.md`](ui-ux-testing-prompts.md) |
| How do the E2E suites behave at runtime / give release confidence? | [`e2e-runtime-review.md`](e2e-runtime-review.md) · [`release-e2e-confidence.md`](release-e2e-confidence.md) |
| What's the testing strategy (integration, coverage, mutation, exit-path, TDD)? | [`testing-strategy.md`](testing-strategy.md) |
| What were the live security-test results for 4.8.0? | [`security-test-results-4.8.0.md`](security-test-results-4.8.0.md) |
| Which session store should WP Sudo use? | [`session-store-evaluation.md`](session-store-evaluation.md) |
| What's the roadmap? | [`ROADMAP.md`](ROADMAP.md) |
| What were the former pre-tag and WordPress.org gates? | [`wporg-submission-checklist.md`](wporg-submission-checklist.md) |
| How do I run the Studio SQLite release? | [`studio-sqlite-release-runbook.md`](studio-sqlite-release-runbook.md) |
| What's the release environment history? | [`release-environment-log.md`](release-environment-log.md) |

## Design notes & analysis

| Question | Read |
|---|---|
| What's the rationale behind WP Sudo's design and deferred features? | [`sudo-design-notes.md`](sudo-design-notes.md) |
| How do multi-user / collaboration editing scenarios affect sudo? | [`collaboration-analysis.md`](collaboration-analysis.md) |

## Project governance

| Question | Read |
|---|---|
| What LLM confabulations have occurred, and what rules prevent recurrence? | [`llm-lies-log.md`](llm-lies-log.md) |
| How is AI authorship disclosed? | [`ai-authorship.md`](ai-authorship.md) |

---

*Adding a doc? Add a one-line pointer to the right section above — keep it a
question the doc answers, not a summary of its contents.*
