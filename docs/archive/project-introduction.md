# Project Introduction

_This document preserves the longer conceptual introduction that originally opened the GitHub README. It is background and positioning, not the canonical security model or release-status document._

For the current product overview, see [../../readme.md](../../readme.md). For the canonical threat model and boundaries, see [../security-model.md](../security-model.md).

![Fuwa-no-seki barrier gate](../../assets/fuwa-no-seki-narrow.png)

> So full of cracks,  
> the barrier gatehouse of Fuwa  
> lets both rain and moonlight in —  
> quietly exposed, yet enduring.  
>  
> — [Abutsu-ni](https://en.wikipedia.org/wiki/Abutsu-ni), *Diary of the Waning Moon*

## Defense in depth needs a last layer

WP Sudo exists to govern what can happen **inside** an already-authenticated WordPress session.

Firewalls, malware scanners, patching, and hardening all matter. But they do not answer a narrower question:

> What happens after the attacker already has a valid session, a delegated request path, or some other way to attempt a high-consequence action?

WordPress has roles, capabilities, and authentication. It does not have a native way to say:

> this action is consequential enough that a valid session alone should not be enough.

WP Sudo is an attempt to fill that gap on the covered paths it intercepts.

## Why the gate metaphor

Inspired by the Linux command `sudo` (“superuser do”), WP Sudo uses the gate as its central metaphor: a threshold, barrier, checkpoint, and final moment of deliberate confirmation before crossing into a more dangerous action.

The visual symbol behind the project is 門, the gate radical and grapheme that appears across East Asian writing systems. It evokes the fortified pass, checkpoint, or barrier gate — a place where movement is not assumed, but examined.

That metaphor is what the plugin tries to express in WordPress terms:
- not a new role system,
- not a replacement for capabilities,
- not a site-wide lockdown,
- but a **threshold check** before selected consequential operations proceed.

## Why this matters in WordPress

A large share of real-world WordPress exploitation is about gaining, abusing, or extending privileged access.

That includes patterns such as:
- stolen browser sessions,
- unattended authenticated devices,
- delegated credentials reaching sensitive operations,
- broken access-control bugs that aim to create administrators, change roles, install plugins, or modify critical settings.

WP Sudo is designed for the cases where the attacker needs WordPress to do something consequential **now**, and an additional proof-of-intent step on that path can still matter.

It is strongest when an attacker has a valid session but **does not** already have an active sudo window and must cross one of the plugin's covered action paths.

## Barrier gate architecture 門

WordPress has rich access control over **who** may do **what**. WP Sudo adds a layer about **when** those actions may proceed within a live session.

In that model:
- WordPress capability checks still run,
- WP Sudo stays additive rather than substitutive,
- and selected high-consequence actions can require fresh confirmation before they proceed.

This is not role-based escalation. Every logged-in user is treated the same way on covered paths: if there is no active sudo window, the action is challenged.

That is why the project frames itself as a barrier gate rather than a permission system.

## Important limits

This framing is intentionally narrow.

WP Sudo is **not**:
- a replacement for WordPress capabilities,
- a firewall,
- a fix for arbitrary broken authorization in third-party plugin code,
- or a sandbox against malicious in-process code.

Its value is in being a last-layer threshold check on the operations it actually covers.

## Where to go next

- [../../readme.md](../../readme.md) — lean project overview
- [../security-model.md](../security-model.md) — threat model and explicit boundaries
- [../FAQ.md](../FAQ.md) — practical questions and caveats
- [../developer-reference.md](../developer-reference.md) — hooks, filters, and extension points
- [../core-action-gate-proposal.md](../core-action-gate-proposal.md) — longer-form design thinking and core proposal work
