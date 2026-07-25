---
status: current
applies_to: 4.8.x
last_verified: 2026-07-25
---

# Collaboration and Sudo — Multi-User Editing Analysis

> Relocated from `ROADMAP.md` §3 on 2026-07-25.

## Collaboration and Sudo — Multi-User Editing Scenarios

### What collaboration looks like in WP 7.0

**Notes (confirmed, shipping):** Asynchronous block-level and fragment-level comments.
These are post meta. They don't trigger admin actions. **No sudo impact.**

**Real-time co-editing (experimental/uncertain):** If it ships, multiple users edit
the same post simultaneously with presence cursors. WordPress VIP already has this
as a managed feature. For core, the technical challenge is making it work without
WebSocket infrastructure on shared hosting.

### The scenario: User A and User B editing the same content, User A activates sudo

WP Sudo's session is **per-user, per-browser**. It does not lock content or create
cross-user state. So:

1. **User A triggers a gated action** (e.g., activating a plugin from the Plugins page
   while User B is editing a post). User A gets the challenge interstitial. User B
   is completely unaffected — they're on a different admin page.

2. **Both users are in the post editor, User A triggers sudo from the admin bar
   shortcut (Ctrl+Shift+S).** User A is navigated to the full-page challenge (the
   admin-bar shortcut sets `window.location.href`; it does not open a modal). User B sees nothing.
   Sudo session tokens are bound to User A's user meta and browser cookie. No
   cross-user interference.

3. **The real edge case: collaborative editing of a gated resource.** Today, WP Sudo
   gates *admin actions* (plugin activate, user delete, settings changes), not
   *content saves*. Post editing is not a gated action. So even with real-time
   co-editing, WP Sudo doesn't intercept content saves and no conflict arises.

4. **Future edge case: if WP Sudo ever gates content actions** (e.g., publishing,
   trashing, or deleting posts). In a co-editing scenario, User A publishes →
   sudo challenge → User B sees... what? The post is in a "pending publish" state
   from User B's perspective. This is a UX design question, not a technical one.
   The answer is probably: don't gate content saves. Gate the *destructive* actions
   around content (trash, delete, capability changes) where concurrent editing isn't
   relevant.

### Bottom line

Collaboration features in WP 7.0 don't create conflicts with WP Sudo because:
- Notes are post meta, not admin actions
- Co-editing is content editing, not admin actions
- Sudo sessions are per-user, not per-resource
- The plugin gates admin operations, not content operations

Monitor for: destructive abilities exposed via REST, and any future core features
that let one user's admin action affect another user's in-progress work.

---

