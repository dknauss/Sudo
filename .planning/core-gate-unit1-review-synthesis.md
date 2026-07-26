# Core-gate design-review synthesis — Unit-1 mechanism + #322 (2026-07-26)

Six-model panel (Opus + Fable + Sonnet on the plugin #322 brief and on the core-gate Unit-1 mechanism brief), run in-session. All findings verified against live source in the worktree and, for WP-core claims, against `wordpress-develop@trunk` (fetched 2026-07-26). This doc is the durable record; findings mirror to the referenced issues.

## Decisions taken this session

1. **#320 split — APPROVED, closed.** v1 = recent-auth primitive + provenance-blind package-write gate on browser/cookie-auth paths + identity pivots. #306/#307 → deferred milestone #4. #302 stays v1.
2. **#322 fix — "Both".** v1 ships the global fail-closed fix; origin-bound replay is a later UX enhancement. See `322-stash-confused-deputy-design-brief.md`.
3. **Unit-1 shape — B (per-action step-up).** Drop the reusable recent-auth *window* for consequential actions; each is its own challenge + one-time proof-bound intent token. Closes #308/#315 and most of #310/#319 by construction. (Shape A "windowed" drafted and rejected — it only narrows #308's cross-authorization to buckets.)
4. **Cron/auto-update — caveat the v1 headline.** v1 does NOT close the auto-update/cron code path; #307 is the real fix (deferred). Rewrite proposal §1 / spec §1 accordingly.
5. **#310 — the real fix is the required cache-bypass read, not a salt precondition** (see finding U-3).

## Verified fact: WordPress has no effective package signing (llm-lies-log #39)
- `Core_Upgrader::upgrade()` → `download_package( …, false )` — core updates never request signature verification (`class-core-upgrader.php:128`).
- `wp_trusted_keys()` returns `[]` on every current install: sole key gated `if ( time() < 1617235200 )` (before 2021-04-01); "// TODO: Add key #2" never done (`file.php:1544`).
- `verify_file_signature()` failures soft-fail by default (`wp_signature_softfail` = true; `file.php:1278-1355`).
- Package authenticity today rests on TLS to `*.wordpress.org` + mutable `update_*` transients. The shipped MD5s (`check_files()`) hash already-installed LOCAL files to choose partial-vs-full download — not package authentication.
- Corrects a design-review subagent's claim that "core updates are signature-checked." This falsifies the "narrow v1 ALLOW to core-signed packages" option.

## Unit-1 findings (deduped; U = mechanism, all verified)

- **U-1 (Opus, highest): the window doesn't compose with the closure.** #315 (per-action token, no reuse) + #308 (scoped freshness) both cover the *entire* minimal closure → no in-scope action benefits from window reuse. Honest v1 = per-action step-up. → **Shape B.**
- **U-2 (Opus/Fable): item-2 stash binding was incoherent.** Binding a *pre-challenge* stash to a *post-challenge* proof is impossible; binding to the verifier is useless (the clone shares it). The only coherent fix is *no auto-replay for consequential classes* — which Shape B adopts wholesale. Contract break to fix: §5.1/§8's "non-account actions replay normally" must change; request-stash (row 16) drops to low-consequence only.
- **U-3 (Opus/Fable/Sonnet): #310 salt precondition is self-defeating; promote the cache-bypass `$wpdb` read to REQUIRED.** wp2shell is a SQLi read → object-*cache* poison (not a DB-row write). A cache-bypassing enforcement read defeats it *regardless of salt placement*. HMAC-with-`wp_salt` only additionally defends the out-of-scope DB-*write* adversary. A salt-independent binding vs a DB-*read* attacker is impossible (any stored key is readable) — scope the claim, stop chasing it. wp-config salts → defense-in-depth + Site Health, NOT a hard precondition.
- **U-4 (Fable, verified vs trunk): landability correction.** `add_user_to_blog` ALREADY has `can_add_user_to_blog` (veto filter, since 4.9) and `grant_super_admin` writes via `pre_update_site_option_site_admins` — **no core patches needed** (brief was wrong). Still need core patches: `switch_theme` (partial veto corrupts state — see U-5), `wp_delete_user` (bool return), self-email admin path (`edit_user()`).
- **U-5 (Fable): every new veto seam needs a no-actor / self-heal carve-out doctrine.** `validate_current_theme()`→`switch_theme()` runs with no actor; multisite login-redirect calls `add_user_to_blog`; `wpmu_activate_signup` uses actor 0. A fail-closed veto without a `wp_installing()`-style carve-out bricks self-heal / login / signup. State the rule once and apply to all seams.
- **U-6 (Sonnet/Opus): #316 is an unfixed doc contradiction.** §4.1 still routes self-email to `personal_options_update` (do_action); §12 item 11 says move it. Fix: edit §4.1 to a veto seam; admin path needs `edit_user()` core patch; REST via `permission_callback`.
- **U-7 (Sonnet/Opus): #315's token needs a confirmation-page CONTENT contract (§7).** The digest binds to the stashed request, not the human's intent. §7 must require: render every gated field, no attacker-controlled chrome/labels, no auto-focus-submit, token bound to the shown-params digest. In Shape B this page is the sole gate → even more load-bearing. Also: in-origin XSS free-riding the httponly proof is a *separate* residual — do not describe it as fixed by #315.
- **U-8 (Opus): REST completion gap.** #315's browser-only confirmation page has no REST redemption path → breaks Gutenberg `POST /wp/v2/plugins`. Fix: challenge response returns the one-time token; REST client re-POSTs it. Or document browser-only degradation.
- **U-9 (Opus/Fable): #319 is deeper than "honor setcookie() return."** `setcookie()` returns false only on sent-headers, never on client rejection (Secure/SameSite/ITP) — the real loop cause. Fix = **issue-then-confirm ordering + previous-proof slot: never invalidate the prior proof until a later request presents the new one.** Also the proof cookie must mirror the **logged-in** cookie's paths (`COOKIEPATH`+`SITECOOKIEPATH`) and `secure_logged_in_cookie`, not `is_ssl()`/plugins-path (`wp_get_session_token()` reads the logged-in cookie).
- **U-10 (Fable, blocker context): the cron auto-update ALLOW branch is an unauthenticated code path.** `wp-cron.php` is anyone-triggerable; the same SQLi+cache-poison primitive poisons `update_plugins` → `system`-actor ALLOW → `install_package()` writes attacker PHP, no session. With signing dead (above), the only real control is a provenance primitive (#307). Resolution: caveat the headline in v1 (decided); #307 later.
- **U-11 (Fable): #308 spends `consequence.scope` twice.** If Shape A were chosen, the signed scope must be a compound versioned key `v1|{site_id}|{class}` to reserve the multisite dimension §4.2 promised. Moot under Shape B (no reusable scope), but note if scope resurfaces.

## Split for execution
- **Unit 1a (land-now, shape-independent):** #303 + #316 (veto seams + U-4 seam inventory + U-5 carve-out doctrine), #319 (U-9 ordering + cookie policy), #310 (U-3 required cache-bypass read). One reviewable spec-edit PR.
- **Unit 1b (Shape B rewrite):** replace §4.2 window with per-action step-up; §5.1 no consequential auto-replay + bulk-as-single-digest; §7 content contract; §8/§9 REST redemption + cron headline caveat; folds #315 + #308.
- **Cross-lane tie:** the lockout DoS escape hatch (§4.2, file-based) is unreachable for hosted installs = the SAME gap as plugin **#280** (no in-band lockout clear). Share a solution.

## Is the gate pointless without signed updates? (strategic — session Q)
No — the gate and signing defend **orthogonal** threat classes and are complementary, not substitutes:
- **Gate (proof-of-intent):** defends against an authenticated-but-illegitimate *actor* (stolen cookie, XSS-ridden session, walked-away device, hijacked admin) driving a consequential effect. Answers "is a human deliberately authorizing THIS effect now?"
- **Signing:** defends the *authenticity of code bytes* from the update channel (compromised update source, poisoned transient). Answers "are these bytes really from a trusted source?"

The empirically dominant WordPress attack class is the former (Patchstack 2026: broken access control is the #1 *exploited* category; "looks like normal authenticated traffic"). The gate addresses that regardless of signing, and de-escalates XSS/stolen-session from instant-RCE to needs-a-fresh-human-challenge.

**Where the worry is legitimate:** the two intersect on the **automated update path** (U-10). There is no actor to challenge (cron, actor 0), so the gate cannot help there — only provenance/signing can. That is why v1 caveats the headline and defers #307. The honest scoping: v1's guarantee is *actor-driven paths*, not code provenance in general.

**Attacker-capability stratification** (the precise answer): against the *common, cheap* primitive (stolen cookie / XSS, no DB access) the gate is fully effective. Against the *strong* primitive (SQLi + object-cache poison, the wp2shell class) the gate needs the required cache-bypass read (U-3) to not be trivially forged, and *still* cannot close the auto-update path (U-10) without provenance. So the gate's value is real but stratified by attacker capability — it raises the bar on the cheap, common attack; signing/provenance would raise it on the expensive, rarer one.

## Compensating-primitives for the missing signing (candidates for #307)
Goal: make "this package is trusted" non-forgeable by an attacker who can poison the update transient / influence the download, without full offline code signing. Increasing strength:
1. **Same-request offer binding (cheapest; #307's own direction).** A "trusted" flag set only inside `WP_Automatic_Updater` for a package whose download host matches the api.wordpress.org offer fetched *in the same request*, passed as a private arg — never derived from the persisted `update_plugins`/`update_core` transient. Narrows the forgeable surface from "poison a stored transient" to "MITM the live offer" (TLS covers that). No crypto infra.
2. **Transport-diverse checksum verification.** Verify the downloaded ZIP's hash against a per-package checksum fetched over an *independent* pinned-TLS channel (core already serves file checksums via `get_core_checksums`; plugin/theme checksum APIs exist). Not signing (no offline key), but forces an attacker to poison delivery AND the checksum API consistently. Buildable in core today.
3. **Source pinning / TOFU.** Pin the update host (and optionally a key) at install; refuse silent changes to the update source/URL host. Detects a repointed update source.
4. **Revive Ed25519 signing (the real fix, not a core-patch-only decision).** The machinery already exists (`verify_file_signature`, `wp_trusted_keys`) — dormant because .org never published key #2, never signs packages, and soft-fails. The actual answer is a WordPress.org *operational* decision: resume signing, populate trusted keys, flip `wp_signature_softfail` to hard-fail. The gate project can advocate (the proposal is the venue) but cannot ship it.

Framing for #307: 1–3 are stopgaps that meaningfully raise the bar without .org operational change; 4 is the correct end state but out of a core patch's control. None make gating redundant — they close the *one* path (automated updates) the gate structurally cannot.
