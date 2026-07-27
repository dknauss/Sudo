# Archived Documentation

These documents are retained for auditability and project history. They are not
the current source of truth for roadmap, security model, release state, or
developer APIs.

Use these current docs first:

- [../release-status.md](../release-status.md) — current release state.
- [../ROADMAP.md](../ROADMAP.md) — current planning and priority order.
- [../security-model.md](../security-model.md) — current threat model and boundaries.
- [../developer-reference.md](../developer-reference.md) — current hooks, filters, and extension APIs.

Archived files:

- [accessibility-audit.md](accessibility-audit.md) — completed WCAG 2.1/2.2 AA audit record (was ROADMAP Appendix A).
- [core-actions-registry-vs-abilities-decision.md](core-actions-registry-vs-abilities-decision.md) — the registry-vs-Abilities decision memo; its operative conclusion is folded into [../core-sudo-gate-implementation-spec.md](../core-sudo-gate-implementation-spec.md) §4.1.1.
- [core-sudo-gate-vs-demo-reconciliation.md](core-sudo-gate-vs-demo-reconciliation.md) — MVP-vs-spec reconciliation for the `consequential-actions` demo; the one live delta (flat-vs-nested `consequence` block) is tracked in the spec §4.1.1.
- [password-change-reauth-research.md](password-change-reauth-research.md) — background research on password-change reauth (superseded by the core-gate proposal/spec).
- [core-sudo-gate-poc-patches.md](core-sudo-gate-poc-patches.md) — ⚠️ **superseded, known-vulnerable pseudocode sketch; do not implement.** Retained for the shape of a chokepoint insertion and as the record of why sketches were abandoned: it drifted silently alongside the spec and accumulated nine defects (see its own banner, and #360). Replaced by the runnable slice at [`../../poc/install-package-gate/`](../../poc/install-package-gate/), which found #387 precisely because it executes.
- [execution-plan-v3.1-v3.3.md](execution-plan-v3.1-v3.3.md) — historical security/governance execution record.
- [internal-admin-governance-spec.md](internal-admin-governance-spec.md) — implemented governance design spec.
- [phase3-stash-minimization-spec.md](phase3-stash-minimization-spec.md) — implemented Phase 3 request-stash minimization record.
- [project-introduction.md](project-introduction.md) — longer conceptual README introduction preserved as background.
- [release-3.0.0-checklist.md](release-3.0.0-checklist.md) — historical v3.0.0 release checklist.
- [wp-7.0-prep.md](wp-7.0-prep.md) — completed WordPress 7.0 preparation/verification record (was ROADMAP §2).
