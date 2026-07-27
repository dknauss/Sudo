# WP Sudo — Action Gate Research Program

> GSD routing context. The architectural source of truth for this program is
> [`action-gate-architecture-charter.md`](action-gate-architecture-charter.md).
> Product status remains canonical in [`../PROJECT-STATUS.md`](../PROJECT-STATUS.md).

## What this is now

WP Sudo is a research environment for developing a narrowly scoped WordPress core
proposal: early veto points plus an action-bound step-up approval flow for effects
that introduce executable plugin or theme code.

It is not being developed as a production security plugin. Earlier plugin
features remain useful evidence, test material, and records of failed approaches.

## Core value

Produce falsifiable evidence that WordPress can stop two high-risk code effects at
their true mutation boundary while giving integrated wp-admin screens a smooth
pause-before-send experience.

## Current milestone

**Action Gate Research Program**

The milestone is complete only when the core veto, proof protocol, trusted
confirmation model, client preflight, and demonstrator work as one bounded slice.
The general actions registry is not part of this milestone.

## Current state

- Version 4.9.0 removed automatic request execution after reauthentication.
- The public roadmap identifies pause-before-send as the replacement UX direction.
- Existing detailed core docs still contain an older server-carried request and
  registry-oriented design; they are inputs to reconcile, not implementation
  instructions.
- `poc/install-package-gate` and `wip/coregate-unit1` contain useful seam evidence.
- `consequential-actions` contains a useful demo shell but embodies superseded
  window/modal/registry assumptions.

## Constraints

- Research and disposable environments only.
- WordPress 6.4+ and PHP 8.2+ remain the plugin test floor; any core patch pins its
  own `wordpress-develop` base SHA.
- No request auto-replay.
- No reusable approval window for the in-scope effects.
- No registry dependency.
- No broad action catalog until the two-action slice passes.
- No claim that an ordinary same-origin modal is safe against active XSS.
- TDD and guard-specific mutation checks are mandatory for security boundaries.

## Canonical sources

- Program architecture:
  `.planning/action-gate-architecture-charter.md`
- Program requirements: `.planning/REQUIREMENTS.md`
- Program phases: `.planning/ROADMAP.md`
- Current position: `.planning/STATE.md`
- Public project roadmap: `docs/ROADMAP.md`
- Research-use boundary: `PROJECT-STATUS.md`
- Live repository facts: Git and GitHub
