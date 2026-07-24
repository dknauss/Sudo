# WP Sudo Documentation Index

*Navigate by question. Each entry is a pointer only — the linked doc is the single
source of truth for its topic; this index never restates content (see
[`llm-lies-log.md`](llm-lies-log.md) for why that discipline exists).*

*Last updated: 2026-07-24.*

---

## Start here

| I want to… | Read |
|---|---|
| Understand what WP Sudo does and why | [`FAQ.md`](FAQ.md) |
| Know the threat model and security boundaries | [`security-model.md`](security-model.md) |
| Use the hooks, filters, and custom-rule API | [`developer-reference.md`](developer-reference.md) |
| See how WP Sudo compares to other reauth/sudo approaches | [`sudo-architecture-comparison-matrix.md`](sudo-architecture-comparison-matrix.md) |

## Canonical state — check these before writing any count or release claim

| Question | Canonical source |
|---|---|
| How many surfaces / rules / hooks / fields are there right now? | [`current-metrics.md`](current-metrics.md) |
| What's the stable tag / unreleased `main` work / forward-lane posture? | [`release-status.md`](release-status.md) |

## The core proposal — "propose this primitive to WordPress core"

| Question | Read |
|---|---|
| *Why* should core have an action gate, and in what phases? | [`core-action-gate-proposal.md`](core-action-gate-proposal.md) |
| *What* exactly would change in core (files, functions, APIs)? | [`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md) |
| What do the actual patches look like at the chokepoints? | [`core-sudo-gate-poc-patches.md`](core-sudo-gate-poc-patches.md) |
| How do we advance Trac #20140 / write the Make/Core post? | [`core-sudo-gate-proposal-notes.md`](core-sudo-gate-proposal-notes.md) |
| Should Layer 1 be a new registry or Abilities metadata? (decision) | [`core-actions-registry-vs-abilities-decision.md`](core-actions-registry-vs-abilities-decision.md) |
| Does the `consequential-actions` MVP still argue the spec's thesis? | [`core-sudo-gate-vs-demo-reconciliation.md`](core-sudo-gate-vs-demo-reconciliation.md) |
| How does WordPress core authentication actually work? | [`wordpress-core-authentication.md`](wordpress-core-authentication.md) |

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

## Testing & release

| Question | Read |
|---|---|
| What manual/live security tests must pass before release? | [`security-manual-test-checklist.md`](security-manual-test-checklist.md) |
| What are the structured UI/UX testing prompts? | [`ui-ux-testing-prompts.md`](ui-ux-testing-prompts.md) |
| How do the E2E suites behave at runtime / give release confidence? | [`e2e-runtime-review.md`](e2e-runtime-review.md) · [`release-e2e-confidence.md`](release-e2e-confidence.md) |
| What were the live security-test results for 4.8.0? | [`security-test-results-4.8.0.md`](security-test-results-4.8.0.md) |
| Which session store should WP Sudo use? | [`session-store-evaluation.md`](session-store-evaluation.md) |
| What's the roadmap? | [`ROADMAP.md`](ROADMAP.md) |
| How do I submit/update the plugin on WordPress.org? | [`wporg-submission-checklist.md`](wporg-submission-checklist.md) |
| How do I run the Studio SQLite release? | [`studio-sqlite-release-runbook.md`](studio-sqlite-release-runbook.md) |
| What's the release environment history? | [`release-environment-log.md`](release-environment-log.md) |

## Project governance

| Question | Read |
|---|---|
| What LLM confabulations have occurred, and what rules prevent recurrence? | [`llm-lies-log.md`](llm-lies-log.md) |
| How is AI authorship disclosed? | [`ai-authorship.md`](ai-authorship.md) |

---

*Adding a doc? Add a one-line pointer to the right section above — keep it a
question the doc answers, not a summary of its contents.*
