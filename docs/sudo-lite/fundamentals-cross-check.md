# Sudo fundamentals cross-check

Use this checklist when reviewing WP Sudo changes. The purpose is not to make WP Sudo smaller; it is to keep the larger implementation faithful to the simpler Psudo Lite and Sudo Lite fundamentals.

## Core invariant checks

For every material change to WP Sudo's gate, challenge, session, replay, or policy behavior, confirm:

- [ ] The change still requires fresh reauthentication before protected dangerous effects.
- [ ] A sudo grant is bound to the current user and current browser/session token.
- [ ] The sudo window is short, explicit, and expires predictably.
- [ ] Protected action matching is understandable from a small rule or category model.
- [ ] Interactive browser flows do not trap users in stale challenge states.
- [ ] Non-interactive surfaces fail clearly when reauthentication is required.
- [ ] Replay behavior is limited to requests that can be replayed safely.
- [ ] Redirects are same-site validated and never open-ended.
- [ ] Failure and lockout behavior is generic, rate-limited, and does not leak unnecessary detail.
- [ ] New settings or policy knobs do not obscure the default security posture.

## Psudo Lite comparison

Psudo Lite is the page-level baseline. Use it to ask:

- [ ] If this were only a page-level reauth nudge, would the user experience be obvious?
- [ ] Does WP Sudo still avoid surprise logouts and rely on explicit reauthentication instead?
- [ ] Does the challenge explain why reauthentication is happening?
- [ ] Is the protected area or action legible to a site owner?

## Sudo Lite comparison

Sudo Lite is the minimal action-gating baseline. Use it to ask:

- [ ] Can the protected behavior be explained as a small rule: surface + action + capability?
- [ ] If the request is AJAX or REST, is denial safer than replay?
- [ ] If replay is supported, what makes replay safe for this request?
- [ ] Does the rule guard the dangerous effect, not only the visible screen?
- [ ] Is the built-in default safer than the most permissive configuration?

## Complexity budget questions

Before adding a new option, integration, policy branch, or special case, answer:

1. What security invariant does this preserve or improve?
2. Could a Sudo Lite-style rule solve this instead?
3. Does this make the default behavior easier or harder to audit?
4. What happens on AJAX, REST, multisite, and stale-tab flows?
5. What test proves the simple invariant still holds?

## Review cadence

Run this cross-check:

- before tagging a release;
- after changes to `Gate`, `Challenge`, `Sudo_Session`, `Request_Stash`, or policy settings;
- after adding a new protected action category;
- after changing REST/AJAX behavior;
- when reviewing any security-audit finding or coordinated-disclosure response.

## Release note expectation

If a change intentionally diverges from the Sudo Lite baseline, document the reason in the PR or release notes. Acceptable reasons include broader surface coverage, safer replay handling, multisite-specific behavior, or compatibility with WordPress core behavior that the lite version intentionally ignores.
