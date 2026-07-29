# Release Status (Canonical Current State)

Last verified: 2026-07-29

> **Release status is not production status.** WP Sudo is a research prototype.
> Tags are reproducible research snapshots for disposable local environments
> and WordPress Playground, not production-readiness declarations. See
> [`PROJECT-STATUS.md`](../PROJECT-STATUS.md).

This file is the canonical source for **current release state** in this repository:

- the latest GitHub/tagged WP Sudo version
- whether the plugin has been published to the WordPress.org plugin repository
- unreleased work already present on `main`
- the latest stable WordPress release WP Sudo package metadata should advertise
- the forward WordPress lane used in CI, Playground, and manual verification
- the current WordPress 7.0 GA posture and next forward-lane assumptions

## Latest GitHub/tagged release

- **Latest tagged research preview:** `4.9.2` (cut 2026-07-29).
- **Latest git tag observed:** `v4.9.2` (annotated, cut 2026-07-29, on `07ecefe`, the squash-merge of PR #518). Its GitHub release and install asset are classified as a prerelease/research snapshot for disposable evaluation only.
- **`4.9.2` payload (released):** the **reauthentication-usability release**. Two backward-compatible fixes paying down the parts of `4.9.0`'s replay removal that were defects rather than tradeoffs. **Fix:** `options.critical` gated on a critical field being *present* in the POST, and `options-general.php` posts those fields alongside ordinary ones, so editing only the site title, tagline or timezone raised a challenge — labelled "Change critical site setting" with no Target line. It now compares submitted against stored and challenges only on a difference, failing toward challenging on every uncertainty. **Behaviour change, disclosed:** such a save no longer fires `wp_sudo_action_gated`, so an audit subscriber using that hook as a settings-screen tripwire loses the signal (#445). **Fix:** the post-reauthentication notice carried three claims each false on some path — the "Reauthentication complete" opener holds only where a credential was verified, "review the form" is wrong for the link-driven GET that is now the commonest arrival, and the secrets variant named "password and secret fields", implying the rest were saved. One string now covers every path and carries `role="alert"`, which the prior notices never did (#463, #469, #436 face 2). `Challenge::REDACTED_REPLAY_QUERY_ARG` is retained and documented inert — by analogy with the *security-forced inertness* clause, not under it: that clause is a MINOR trigger for a *documented* parameter, argument or event, and this constant is documented nowhere in `developer-reference.md`. Also: `wp_sudo_require()` reports its inert `return_url` through `_deprecated_argument()` (#461), and the handler/usable-screen guard asserts its two classifications are disjoint (#434). **Disclosed, not fixed:** a gated form still returns with unsaved edits gone (#436 face 1); naming every matched rule (face 3) and gating before the form (face 4) remain open, face 4 being the most valuable. Also still open: the non-atomic marker/proof-map pair (#475), and an omitted `default_role` reachable through the unregistered-settings page (#506). **PATCH** under `VERSIONING.md` — nothing joins the declared public surface. The exact commit set is `git log v4.9.1..v4.9.2 --oneline`.
- **`4.9.1` payload (released):** a **maintenance release** on top of `4.9.0`. Two backward-compatible fixes, the completion of the `4.9.0` documentation sweep, and the explicit research-prototype classification (#466). **Security:** "revoke all sessions" could silently skip a live sudo session — `Sudo_Session::activate()` maintained the per-user expiry marker by reading it back through the object cache, so a stale-low read could write a marker sitting beneath another browser's live proof; because `revoke_all_active_sessions()` selects on that marker in SQL, the user was skipped, and `is_session_live()` hid the per-user revoke on the Users list for the same reason. The marker is now derived in `set_token()` from the merged proof map it already holds cache-bypassed. **Fix:** Site Health's stale-session sweep deleted live and grace-eligible proofs — it selected on the marker and re-classified with a *cached* `get_user_meta()` read while enforcement reads the proof record cache-bypassed, and it applied a bare "expired" test that ignored the 120 s grace window; selection and classification now happen in one SQL query, with the boundary derived from `Sudo_Session::GRACE_SECONDS`. Operator-visible: cleanup of an expired session is deferred by up to 120 s, and a cookie- and HMAC-bound proof is still accepted by gated paths during that window (`Gate` admits `is_active() || is_within_grace()`). **Docs:** the `#322` replay-removal sweep completed across `security-model.md`, `FAQ.md`, `developer-reference.md`, this file, `tests/MANUAL-TESTING.md` and roughly eighty code comments (#476, #481, #479), including two claims that were wrong rather than stale — a cleanup documented as guaranteed that is best effort, and a rationale written in the present tense for a retired mechanism. **Disclosed, not fixed:** browser-admin reauthentication is materially worse since `4.9.0` — a gated form returns to the correct screen with every field blank (#436), the notice implies only secrets were lost when everything was (#436, #469), a gated GET action is told to "review the form" when there is none (#463), and `options.critical` gates on the presence of a critical field rather than a change to it, so every Settings -> General save challenges (#445). The `4.9.0` claim that re-doing the action is "a re-click or a re-submit" was corrected in `readme.txt` — for a form it is a full retype. Also disclosed: the marker/proof-map pair is still two non-atomic `update_user_meta()` calls, so concurrent activations can still interleave to a too-low marker (#475). **PATCH** under `VERSIONING.md` — nothing joins the declared public surface. The exact commit set is `git log v4.9.0..v4.9.1 --oneline`.
- **`4.9.0` payload (released):** the **security release**. After reauthentication WP Sudo never automatically executes a previously intercepted **server-stashed** request — every method, every rule, every admin surface that stashes. Automatic replay is **removed rather than conditioned** (#322): an interim design authorised it per-request behind a `__Host-` binding cookie plus a confirmation naming the target, and #397/#412/#413/#429/#444 together showed that *which stashes may replay* was the wrong question to keep answering. No successful challenge navigates to any requester-supplied destination either. Also in the payload: forge-resistant per-login-session sudo assurance (#278, #279), the reauth-lockout out-of-band clear (#280), and a reachable settings-blanking route closed by making `options.critical` non-replayable (#444). Two documented behaviours change and are disclosed rather than smoothed over — `wp_sudo_action_replayed` is retained but dormant (read `wp_sudo_replay_refused` and its `reason`), and `wp_sudo_require()`'s `return_url` argument is inert. **MINOR** under the *Security-forced inertness* clause added to `VERSIONING.md` in this release. The exact commit set is `git log v4.8.0..v4.9.0 --oneline`.
- **Previous tag:** `v4.9.1` (annotated, cut 2026-07-28, on `336d095`, the squash-merge of PR #496).
- **Tag before that:** `v4.9.0` (annotated, cut 2026-07-27, on `82d4b23`, the squash-merge of PR #459).
- **Tag before that:** `v4.8.0` (annotated, cut 2026-07-23, on `10587a4`, version-sync commit for PR #221).
- **`4.8.0` payload (released):** a **security-hardening** release plus one new opt-in integrity feature. Security fixes (backward-compatible): the **pre-existing** `user.change_password` / `user.promote` REST rules matched only `PUT`/`PATCH`, so a `POST` to `/wp/v2/users/{id|me}` was ungated (core registers the route under `WP_REST_Server::EDITABLE`, which includes `POST`) — both rules now gate `POST` too (#213); a **new** `user.change_email` rule gates account email changes (the password-reset-takeover pivot) on the profile surfaces and the REST users routes for `POST`/`PUT`/`PATCH`, stashing profile account-mutation POSTs **non-replayably** (#214); REST writes to critical settings were ungated because the callback matched raw option names (`siteurl`/`admin_email`) while `/wp/v2/settings` keys by `show_in_rest` aliases (`url`/`email`) — now matches the aliases (#215). New (opt-in, default-off): a **role/capability lockdown audit MVP** that detects privileged-state drift from a file-backed trusted manifest — including direct `$wpdb` writes the escalation guard cannot see — via `wp sudo manifest generate|diff`, Site Health, or a scheduled sweep firing `wp_sudo_role_drift_detected` (#206). Plus readable governance capabilities on the user profile (#205). Live end-to-end security verification is recorded in [`docs/security-test-results-4.8.0.md`](security-test-results-4.8.0.md). The exact commit set is `git log v4.7.0..v4.8.0 --oneline`.
- **`4.7.0` payload (released):** completion of the in-editor reauthentication modal that `4.6.0` explicitly deferred — Milestone A (in-place password modal over the block editor with owner-scoped request re-dispatch, PR #178) and Milestone B (in-modal second factor via a server-rendered provider partial validated through the unchanged challenge validator, PRs #185/#186), plus the demo/docs follow-ons (in-editor 2FA blueprint #187/#189/#192, multisite scenario blueprint #196/#197, 2FA modal screenshot #193). Released as **MINOR** by maintainer product-signaling override: by the strict `VERSIONING.md` test the payload is patch-level (block-editor JS + internal `Challenge` AJAX endpoints; no *new* declared-public-API entry — the 2FA render hook is pre-existing and documented), but finishing a headline deferred capability warranted a minor. The override is documented in `VERSIONING.md`'s worked examples. The exact commit set is `git log v4.6.0..v4.7.0 --oneline`.
- **`4.6.0` payload (released):** the staged release was re-scoped `4.5.1` → `4.6.0` (MINOR) after the block-editor in-editor reauthentication work (PRs #165, #168) landed on top of the original admin-UI-only `4.5.1` payload — a new user-facing capability, plus the optional critical-event alert bridge's documented public extension filters (#166), is a backward-compatible **addition**, not a patch (see `VERSIONING.md`). The `4.6.0` payload is: block-editor in-editor reauth (link-out increment), the optional critical-event alert bridge + inline demo companion, the admin-surface user-identity harmonization (PR #154), and the alerting/roadmap docs. The exact commit set is `git log v4.5.0..v4.6.0 --oneline`.

### `v4.5.0` tag checklist (completed 2026-07-05)

All steps done; retained as the release record.

1. **Release-environment matrix sign-off — ✅ done.** Apache lane completed (real Apache 2.4.58 + mod_php 8.3.6 run, all six core sections pass, `Authorization`-header passthrough confirmed), minimum-WordPress (6.4) lane CI-covered, managed-host lane cleared by explicit maintainer waiver — all recorded in `docs/release-environment-log.md`.
2. **Re-confirm version sync — ✅ done.** All five points at `4.5.0`; no drift (re-verified with the full gate run: unit, PHPStan L6, Psalm, PHPCS, metrics, i18n).
3. **Bump `blueprint.json` — ✅ done** (PR #150): stable-demo install target now `archive/refs/tags/v4.5.0.zip`, merged after the tag was cut.
4. **Cut the annotated tag — ✅ done.** `v4.5.0` (annotated, signed) on `70cddfe`; GitHub Release published, release-ZIP CI attaches the install asset.
5. **Update this file — ✅ done** (this edit): `4.5.0` recorded as Latest tagged release / `v4.5.0` as latest git tag observed.
6. **wordpress.org submission** remains independently on hold and is not gated by the GitHub tag.

### `v4.6.0` tag checklist (completed 2026-07-06)

The tree was **code-complete and green in CI**, and the release-environment gate
(item 1) was **satisfied**: the full release-grade E2E was run and cleared, with
the new nginx-multisite *smoke* lane explicitly de-scoped as a tracked, non-regression
follow-up (details in item 1 and `docs/release-environment-log.md`). The annotated
tag was cut and the post-tag `blueprint.json` bump applied; all checklist items below
are ✅ done.

1. **Release-environment matrix sign-off — ✅ cleared, with the nginx-multisite *smoke* lane de-scoped.** The full release-grade E2E was run (2026-07-06, local + GitHub Release Confidence [run #28804948034](https://github.com/dknauss/Sudo/actions/runs/28804948034)): **Apache/wp-env full E2E, nginx + MariaDB smoke, and Playground SQLite smoke all pass**. The **nginx-multisite smoke** lane (MSTACK-01/02/03) failed on Playwright *element-not-stable / actionability* timeouts (`#submit`, `#wp-admin-bar-wp-sudo-active`) — a test-robustness/rendering issue on the heavier multisite stack, **not** a behavioral assertion failure and **not** a `4.6.0` regression (the lane is new — added by #155 after `v4.5.0` — and has never been green; nothing in the `4.6.0` payload touches `network/settings.php` or the admin-bar timer, and functional multisite behavior is covered by the CI Integration multisite lane). It is therefore **de-scoped** from the release-confidence gate (`continue-on-error` + excluded from the aggregate, both clearly marked in `release-confidence.yml`) and tracked for stabilization in `docs/ROADMAP.md` and `docs/release-environment-log.md`. The manual host/floor matrix (Apache / managed-host / min-WP) reuses the `4.5.0` matrix by conscious decision (recorded in the log): `4.6.0`'s new surface is admin-side JS + an `admin-ajax` endpoint, not server-floor-sensitive. **Storage note for the local matrix run:** the Docker / `wp-env` / Playwright + multisite lanes are disk-heavy; run them serially and prune stale `wp-env`/Docker volumes and images between lanes (`docker system prune`, `wp-env destroy`) so a full-matrix pass does not exhaust local storage.
2. **Version sync — ✅ done.** All five points at `4.6.0` (`wp-sudo.php` header + constant, `tests/bootstrap.php`, `phpstan-bootstrap.php`, `readme.txt` Stable tag).
3. **CHANGELOG + readme.txt — ✅ done.** `4.6.0` dated section in `CHANGELOG.md`; `readme.txt` Changelog and Upgrade Notice entries added.
4. **Cut the annotated tag — ✅ done.** `v4.6.0` (annotated) on `9ef1880`; GitHub Release published ([releases/tag/v4.6.0](https://github.com/dknauss/Sudo/releases/tag/v4.6.0)); the release-ZIP CI attached the `wp-sudo.zip` install asset.
5. **Bump `blueprint.json` — ✅ done (post-tag).** Stable-demo install target now `archive/refs/tags/v4.6.0.zip` (bumped after the tag was cut, so the public "Try latest release" demo — which loads `blueprint.json` from `main` — fetches a real ZIP).
6. **Update this file — ✅ done** (this edit): `4.6.0` recorded as Latest tagged release / `v4.6.0` as latest git tag observed.

### `v4.7.0` tag checklist (completed 2026-07-16)

The tree was **code-complete and green in CI** on the release commit. The tag was
cut on the merged version-sync commit and the post-tag `blueprint.json` bump +
this file update were applied as a phase-2 PR (matching the `v4.5.0`/`v4.6.0`
sequencing). All items below are ✅ done.

1. **CI gate — ✅ green on the release commit (PR #199).** Full required suite passed: Unit (PHP 8.2/8.3/8.4 + Coverage), Integration ×7 (incl. the `MS true` multisite lane), E2E Tests ×4, E2E Nginx Smoke, Psalm, Code Quality (PHPCS + PHPStan L6), and Plugin Check (PCP). Local pre-commit gate also green: `composer test:unit` (1028), PHPStan L6 no errors, Psalm 0, PHPCS 0.
2. **Release-environment matrix — ✅ reused from `4.6.0` by conscious decision.** `4.7.0`'s new surface is block-editor JS plus an internal `admin-ajax` 2FA-partial endpoint (`wp_sudo_challenge_2fa_partial`) — the same class of admin-side, non-server-floor-sensitive change as `4.6.0`, which cleared the Apache/managed-host/min-WP matrix. No new server-facing behavior, so the `4.6.0` matrix outcome applies; multisite behavior stays covered by the green CI Integration multisite lane. The nginx-multisite *smoke* lane, de-scoped at `4.6.0` tag time, was **re-hardened and restored to the release-confidence gate post-`4.6.0` (2026-07-06)** — see the `4.6.0` section of [`docs/release-environment-log.md`](release-environment-log.md); it is not de-scoped as of `4.7.0`.
3. **Version sync — ✅ done.** All five points at `4.7.0` (`wp-sudo.php` header + constant, `tests/bootstrap.php`, `phpstan-bootstrap.php`, `readme.txt` Stable tag). Pre-commit reviewer verified; a drafting confabulation in the `VERSIONING.md` worked example was caught and corrected before commit.
4. **CHANGELOG — ✅ done.** `4.7.0` dated section in `CHANGELOG.md`; all symbol references verified against live source.
5. **Cut the annotated tag — ✅ done.** `v4.7.0` (annotated) on `3cf7ee7`; `release.yml` verified tag==header version, built the ZIP, and published the GitHub Release with `wp-sudo.zip` attached ([releases/tag/v4.7.0](https://github.com/dknauss/Sudo/releases/tag/v4.7.0)).
6. **Bump `blueprint.json` — ✅ done (post-tag).** Stable-demo install target now `archive/refs/tags/v4.7.0.zip` (bumped after the tag was cut; the tag ZIP URL was confirmed to resolve, so the public "Try latest release" demo fetches a real ZIP).
7. **Update this file — ✅ done** (this edit): `4.7.0` recorded as Latest tagged release / `v4.7.0` as latest git tag observed.

### `v4.8.0` tag checklist (completed 2026-07-23)

The tree was **code-complete and green in CI** on the release commit. As with the
`4.5.0`–`4.7.0` sequence, the version-sync + `blueprint.json` bump landed with the
tag (PR #221); this file's update was applied as a follow-on reconciliation. All
items below are ✅ done, including item 2 (the release-environment-matrix decision),
which was a ⚠️ retroactive gap and is now **resolved** — see the `4.9.0` matrix in
[`release-environment-log.md`](release-environment-log.md) and issue #273.

1. **CI gate — ✅ green (PR #221 version-sync commit).** Full required suite passed; local pre-commit gate green: `composer test:unit` (1111 tests), PHPStan L6 no errors, Psalm 0, PHPCS 0.
2. **Release-environment matrix — ✅ resolved 2026-07-26 (was a retroactive gap).** The
   assertions this gap concerned are now permanent Playwright specs (`REST-01`..`REST-08`)
   running in the release-confidence Apache job, so the lane is covered durably rather than
   by a late manual run; the managed-host lane is waived by explicit maintainer approval
   with residual risk recorded, and the min-WP floor is CI-covered. Full reasoning and the
   condition attached to the Apache lane are in
   [`release-environment-log.md`](release-environment-log.md).

   *Historical context, retained as the record of why the gap existed:* unlike `4.6.0`/`4.7.0` (admin-side JS + `admin-ajax` only), `4.8.0` changed **server-facing REST routing/method matching** (the gates now match `POST` and `show_in_rest` aliases on `/wp/v2/users` and `/wp/v2/settings`) — the class of change the manual Apache/managed-host/min-WP matrix exists to catch, so a blind reuse of the `4.6.0`/`4.5.0` matrix is **not** obviously justified. Live end-to-end security verification of the new REST gates **was** performed and recorded ([`docs/security-test-results-4.8.0.md`](security-test-results-4.8.0.md): WordPress Studio / WP 7.0.2 / PHP 8.5.8 / SQLite, headless WP-CLI + App-Password/cookie REST, plus a Redis backend for the cache-bypass rows), but that is a **functional** security pass, **not** the Apache/managed-host/min-WP environment matrix. A conscious reuse-or-rerun decision for those lanes is flagged for the maintainer in [`docs/release-environment-log.md`](release-environment-log.md); the tag was already cut, so this is recorded as a retroactive reconciliation item, not a pre-tag block.
3. **Version sync — ✅ done** (PR #221). All five points at `4.8.0` (`wp-sudo.php` header + constant, `tests/bootstrap.php`, `phpstan-bootstrap.php`, `readme.txt` Stable tag), plus the `blueprint.json` install target.
4. **CHANGELOG + readme.txt — ✅ done** (PR #220). `4.8.0` dated section (`2026-07-23`) in `CHANGELOG.md`; matching `readme.txt` changelog entry. External/core symbol references cited to WordPress core source in the changelog.
5. **Cut the annotated tag — ✅ done.** `v4.8.0` (annotated) on `10587a4`; GitHub Release published with `wp-sudo.zip` attached.
6. **Bump `blueprint.json` — ✅ done** (with the tag, PR #221): stable-demo install target `archive/refs/tags/v4.8.0.zip`.
7. **Update this file — ✅ done** (this edit): `4.8.0` recorded as Latest tagged release / `v4.8.0` as latest git tag observed.

## Current `main` release state

- **Current `main` version:** `4.9.2` (runtime constant) — **released** on 2026-07-29 as `v4.9.2` (`07ecefe`, the squash-merge of PR #518). `main` and the latest tag agree on the version constant. `blueprint.json` was advanced to `tags/v4.9.2.zip` in this post-tag commit. The payload that was cut: `wp_sudo_require()` reports its inert `return_url` argument through `_deprecated_argument()` (#461), the handler/usable-screen guard now asserts its two classifications are disjoint (#434), `options.critical` gates on a change to a critical option rather than on the field's presence (#445) — a behaviour change that also stops firing `wp_sudo_action_gated` for a General Settings save touching nothing critical — and the post-reauthentication landing notice is replaced by one string that covers every path, carrying `role="alert"` (#463, #469, #436 face 2). The canonical drift source is `git log v4.9.2..main --oneline`, not this summary.
- **Prior `main` version:** `4.9.1` (runtime constant) — released on 2026-07-28 as `v4.9.1` (`336d095`), superseded by `4.9.2`. Before it, `4.9.0` (released 2026-07-27 as `v4.9.0`, `82d4b23`). Of `4.9.0`: it superseded a state in which the latest tag was `v4.8.0` (cut 2026-07-23, on `10587a4`); `main` was bumped off the released identity on 2026-07-26 so that an unreleased tree stops advertising a shipped version, a bump carrying no payload of its own; and at the time it was current, `main` had progressed **past** the tag with unreleased work, which shipped in `v4.9.0` — the bullet recording it is marked HISTORICAL below. (`4.8.0` is the REST-gate security-hardening release plus the opt-in role/capability lockdown audit MVP; see the payload note above.)
- **HISTORICAL — what was unreleased on `main` past `v4.8.0`:** superseded, and retained only as the record of what the `4.9.0` cut contained. Everything below shipped in `v4.9.0`; read the **`4.9.0` payload (released)** bullet above for the current description. Nothing here is outstanding.

  **Rule for editing this block, learned the hard way:** it is superseded but NOT frozen — its text has been rewritten across several releases, so "Original text" below names its origin, not its immutability. What must not appear inside it is a **release-volatile** claim — one whose truth changes when a release happens. Version values, PR open/closed state, "the next tag", "still-open", "still lives in X". Those belong in the canonical bullets above, and here only as pointers to them. The real test is simply whether the thing described **can still change**. Statements about what the `4.9.0` payload did are safe and belong here — the tag freezes them, and stripping them would cost the record this block exists to keep. But the tag freezes only the payload, NOT the files this block asserts about: "only the WP-CLI demo seeder still can", "the proof meta keys are not in `developer-reference.md`", "the docblocks name the format as 4.9.0" are present-tense claims about the live tree, checked against `main` and frozen by nothing. Six non-release commits have already edited exactly those artifacts since `v4.9.0` was tagged. Past-tense such claims to the tagged state ("at `4.9.0`, only the demo seeder fired it") or make them pointers, the same treatment release-volatile claims get. While a payload is still in flux on `main`, description of it rots exactly as fast as a version value: #481 (`65162b4`, 2026-07-28) rewrote two statements in this very block — an in-flight "replay" that had become a submission, and a "the v2 layer restored replay and with it the hook" claim describing a layer removed before release. The `4.9.1` payload bullet above calls those "two claims that were wrong rather than stale". So the boundary is shipped-and-tagged versus still-moving; release-volatile claims are simply the case that moves on every release, which is why they rot most often. Three release-volatile claims survived multiple passes here — a version value falsified by every release since it was written, a "still-open PR #258 / backlog still lives in `ROADMAP.md`" pair false since 2026-07-25, and "the next tag is MINOR", written when the next tag meant `v4.9.0`. All three were found by sweeping the whole file, never by reading the diff. If you catch yourself updating a value in this block, replace it with a pointer instead.
  Original text: one security-hardening change plus three small features, documentation, and CI tooling — none version-bumped. **Security (the largest unreleased change):** the sudo proof format is replaced by a **self-authenticating, per-login-session record** (`_wp_sudo_proofs`, keyed by `sha256(verifier)`, entries `{ token, expires, hmac }`). The enforcement path verifies an `hash_hmac('sha256', …, wp_salt('auth'))` over the record and reads it **cache-bypassed**, closing a forged-assurance path in which an object-cache-poisoning primitive could plant a token hash for an attacker-controlled cookie and obtain sudo with no challenge (#278); keying per login session additionally gives a user's concurrent browsers independent sudo sessions (#279), and a just-expired proof is retained through its 120 s grace window so one browser's reauth cannot cancel another's in-flight submission. Pre-4.9.0 sessions carry no proof entry and require one reauthentication after upgrade (no migration). Doc'd in `docs/security-model.md`; the docblocks name the format as **4.9.0**. `4.9.0` was classified **MINOR** — see *Why `4.9.0` and not `5.0.0`* below. Nothing documented is removed: `wp_sudo_action_replayed` is **retained but dormant** — the final payload removed automatic replay outright, so no shipped path fires the hook (only the WP-CLI demo seeder in `bin/demo-events.php` still can), but its name, signature, documentation and subscribers all remain (see *Why `4.9.0` and not `5.0.0`* for why a darkened event with a successor is MINOR). This assurance change is itself an internal storage format (the proof meta keys are not in `developer-reference.md`). **Features:** (1) the optional critical-event alert bridge now pushes a high-severity alert on role/capability drift (`wp_sudo_role_drift_detected` → bridge push), a backward-compatible addition in the optional companion bridge (PR #226); (2) **scoped break-glass recovery** — `WP_SUDO_RECOVERY_MODE` now accepts a user ID or login (grants break-glass to one named user instead of every `manage_options` holder), plus a pull-based Site Health critical status that flags active recovery mode and names its scope (PR #268, issue #240); (3) the **in-editor sudo session-status indicator** client UI — a build-free editor module surfacing session state via an announce-once snackbar (WP 6.4+) and a feature-detected countdown sidebar (WP 6.6+), with no read endpoint and no polling (PR #277 / #262). **Docs/CI:** the 4.8.0 live/manual security-test checklist + results (#223–#225); core-proposal, roadmap, and accuracy docs (#227–#234); the **ROADMAP restructure** — Pass 1/2 (forward-only Now/Next/Later + design essays promoted to standalone docs) merged as PR #236. (The feature backlog was migrated to GitHub issues **#238–#257**; for where it lives now, read `docs/ROADMAP.md` §Backlog rather than this line.) And the **persistent-options metrics gate** — a tokenizer-based dev/CI scanner (`bin/scan-persistent-options.php`) wired into `composer verify:metrics` (PR #237). None of those bumped `WP_SUDO_VERSION` when they landed; for what `main` carries now, read the version bullets above rather than this line — it is inside a superseded bullet and has been falsified by every release since. **This bullet is a convenience summary — the canonical, always-current drift source is `git log v4.8.0..main --oneline`.**
- **Late-freeze changes on `main` (2026-07-27), after the bullet above was written.** Recorded separately because they are security-relevant and the summary above predates them. **(1) `options.critical` was marked `stash_no_replay()`** (#444) — at the time that meant "not replay-eligible"; since the final payload removed replay outright, what the marker now controls is that the POST body is not **stored**. A replayed partial body does not save a subset of a Settings-API page — `wp-admin/options.php` initialises `$value = null` and calls `update_option()` unconditionally for every option in `$allowed_options[$option_page]`, so it **blanks** the ones the body omits (`GB-OPTIONS-NULL-WRITE`). A minimal same-origin POST satisfied `target_describes_payload()` and was replay-eligible, turning an administrator's reauthentication into a settings wipe. No allowlist narrower than the whole page can reproduce a save, and a wider one carries fields the confirmation cannot name, so the rule is reauthenticate-then-resubmit. **This path predates 4.9.0 and 4.9.0 already narrowed it** — 4.8.0 replayed unconditionally after reauth with neither binding nor confirmation; this closes it. Browser-flow behaviour was unchanged at the time (replay was already refused there), and the audit reason moved `incomplete_target` → `replay_blocked`. **Both reasons are unreachable in the released payload** — `Challenge::build_replay_response_data()` assigns only `replay_disabled` and `no_credential_this_request`, from its single `do_action( 'wp_sudo_replay_refused', … )` site. **(2) `new_admin_email` is now a recognised target** (#431/#444): `options-general.php` posts the administration email under that name, so a takeover previously rendered **no** `Target:` line (`GB-ADMIN-EMAIL-FIELD`), and a captured-but-unchanged option no longer displays as a change. **(3) A refused POST now returns to its originating screen** rather than the dashboard (#429/#433), with seven handler endpoints — `options.php`, `admin-post.php`, `admin.php`, `update.php`, `user-edit.php`, network `edit.php`, `admin-ajax.php` — routed to the neutral page because a bare GET of each renders nothing usable. **(4) A new `unnamed_target` reason** joined the `wp_sudo_replay_refused` enumeration; two guards previously returned without setting one, producing unauditable refusals. It is **also unreachable in the released payload**, for the same reason as the two above — the eligibility guards it belonged to are gone. Follow-ups deliberately **not** in this tag: #434, #442, #445, #446, #447.
- **Runtime version constant:** `4.9.2` on `main`. `WP_SUDO_VERSION` is set in `wp-sudo.php` (header + constant), `tests/bootstrap.php`, and `phpstan-bootstrap.php`; `readme.txt` Stable tag is `4.9.2`. All five version-sync points are in sync at `4.9.2`. **`blueprint.json` targets the `v4.9.2` tag ZIP** (advanced post-tag in this commit; the `v4.9.0` and `v4.9.1` targets were advanced the same way, in PR #464 and PR #497): per [`VERSIONING.md`](../VERSIONING.md) the Playground install target is bumped **after** the tag is cut, never before, because the public "Try latest release" badge loads `blueprint.json` from `main` and a pre-tag bump would point the demo at a ZIP that does not exist yet. The lag is the policy working, not a drift.
- **Current package metadata (on `main`):** `readme.txt` Stable tag `4.9.2` == header Version (no `stable_tag_mismatch`); `Requires at least 6.4`, `Requires PHP 8.2`, `Tested up to 7.0`. Package/listing name: **"Sudo – Research Prototype"** (UI brand "Sudo"; slug/text-domain stay `wp-sudo`). The prior name "Sudo – Admin Action Gating" was retired when the project was classified as a research prototype.
- **Last archived release checklist:** `docs/archive/release-3.0.0-checklist.md`

## Development lanes (established 2026-07-26)

Work in this repository runs in lanes that do **not** share a release clock. The lane
is carried by the GitHub milestone; an issue with no milestone is untriaged, not
"soon". This exists because the plugin, the core proposal, and the demonstrator have
different success criteria, and letting them share one clock is what produced the
post-`4.8.0` churn.

| Lane | Milestone | Clock | Admits |
|---|---|---|---|
| Plugin security release | `4.9.0 — security` | **Yes** (the `4.9.0` tag has since been cut; the lane's clock is spent) | The `4.9.0` payload plus its tag-time gates |
| Plugin, everything else | `post-4.9.0` | No | Features, non-blocking bugs, follow-ups deferred out of `4.9.0` |
| Plugin, next feature batch | `v5.0.0` | No | Curated multisite governance/break-glass batch — reclaimed `v5.0.0` when the security release rolled back to `4.9.0` and freed the number. All backward-compatible additions, so MINOR is the correct class. |
| Core proposal research | `core-gate proposal — Cut 1 readiness` | No | Proposal/spec findings; **no plugin release depends on these** |
| Core proposal, deferred | `core-gate: provenance/automation policy (deferred project)` | No | The automated-update provenance split (#320) |

**Why `4.9.0` and not `5.0.0`.** `main` briefly carried `5.0.0`, taken by the rule:
the `#322` **v1** fix removed the only call site of `wp_sudo_action_replayed`, and
[`VERSIONING.md`](../VERSIONING.md) classes removing a documented public hook as MAJOR.

**That premise was wrong then, and is wrong again now — for a different reason each
time.** It was wrong originally because PR #350 merged v1 *and* v2, and v2 restored
origin-bound replay, so the hook's call site was never removed.

It is wrong now because the final 4.9.0 payload removes automatic replay outright, so
`wp_sudo_action_replayed` has **no producer on any shipped path** — the only
`do_action()` for it left in the tree is `bin/demo-events.php`, a WP-CLI demo
seeder — and that still is not MAJOR,
because **the hook is not removed**. Its name, signature `(int $user_id, string
$rule_id)`, documentation, event code `1900009` and every subscriber remain. Nothing an
integrator wrote errors; on a shipped path it simply never runs.

What makes that MINOR is narrower than "it still exists", and the distinction will
recur: **the signal has a successor at the same lifecycle point.** `wp_sudo_replay_refused`
now fires for *every* consumed stash, carrying a `reason` the older hook never had. A
consumer reconciling an audit trail does a rename, not a recovery. An event made dark
with **no** successor would be MAJOR — silence is the failure mode hardest to notice,
and `VERSIONING.md` does not currently name retained-but-unreachable at all.

Two further checks, made against the source rather than assumed:

- The session meta keys (`_wp_sudo_token`, `_wp_sudo_expires`, `_wp_sudo_session_bind`,
  `_wp_sudo_proofs`) appear **nowhere** in `docs/developer-reference.md`. Replacing the
  proof format is therefore an internal storage change, not an API break —
  `VERSIONING.md` explicitly does not cover undocumented behaviour.
- `wp_sudo_action_replayed` keeps its signature `(int $user_id, string $rule_id)`. With
  automatic replay removed there is nothing left for it to announce, so no shipped
  path fires it (the WP-CLI demo seeder still can, deliberately):
  a **darkened** event, not a changed contract. That is MINOR only because of the
  successor argument above — `wp_sudo_replay_refused` occupies the same lifecycle point.
  (An earlier revision of this bullet said the hook "fires only for bound same-browser
  replays under v2". The v2 layer was removed before release; nothing fires it now.)

What the payload does add is **new** documented public entries — the
`wp_sudo_lockout_cleared` hook, the `wp sudo unlock` CLI command, and the scoped-recovery
helpers `wp_sudo_user_matches_recovery()`, `wp_sudo_recovery_mode_is_unscoped()` and
`wp_sudo_recovery_mode_user()` from #240. Additions are what MINOR means. So `4.9.0`, by
the rule — not by preference, and with no override needed.

This is the rule **re-applied to changed facts, not an override of it** — and it is exactly the
outcome this document anticipated when it deferred v2: *"it restores replay, and therefore
probably restores the removed hook — so its own version classification must be decided on its
merits, not inherited from this release."* It was, and the merged payload is additive plus
security fixes: **`4.9.0`**. (Preserved from the closed PR #390, which would otherwise have
taken it with it.)

**One documented behaviour DOES break, and it is not a removal.** Automatic replay is
gone. A custom rule that auto-replayed at `4.8.0` will not at `4.9.0`; after
reauthentication the user re-issues the action against the session they now hold. That
reaches a **documented** integration — `docs/developer-reference.md` shows a custom rule
allowlisting `array( '_wpnonce', '_wp_http_referer', 'action', 'item_id' )`, and such a
rule replayed at `4.8.0`.

This is a behaviour change to a documented **outcome**, not the removal of a documented
**symbol**. Every hook, filter, function, constant and CLI command survives with its
signature intact, which is what `VERSIONING.md` classes as MAJOR. What changes is
behaviour the plugin can no longer safely offer.

**Why it could not be kept, stated plainly.** Replay was a confused deputy, and the
interim attempt to make it *conditionally* safe produced #429 (a guard keyed on a value
that is always empty for self-posting forms), #431 (a confirmation naming an unchanged
field, and naming nothing at all for an administration-email takeover), #412 and #413
(GET rules whose effect rides an unrecognised parameter), and a route where replaying a
partial Settings-API body made core write `null` over every option the body omitted.
Each was individually fixable. Together they showed that *which stashes may replay* is
the wrong question to keep answering.

**#412 and #413 are moot by construction, not repaired** — and that distinction belongs
here rather than in a closing comment. The vulnerable capability was removed; the
target-naming gap it exposed was not fixed. If replay is ever reintroduced, those gaps
return with it. Expressing a rule's effect through a recognised parameter still matters,
because the challenge page still **names** what is being authorised — that naming is now
the whole control rather than a route to seamless resumption.

**A second documented behaviour changes with it: `wp_sudo_require()`.** The helper's
`return_url` argument is now inert — accepted, still emitted into the challenge URL,
consumed by nothing — and the user is landed on a neutral admin page rather than
returned to the guarded screen. Their next visit passes with no second challenge, so
the grant is unaffected; only the landing is. The parameter is retained rather than
removed, since removing it would break callers that pass it.

**Why that is MINOR, stated properly.** The first draft of this reasoned "removing a
documented parameter would be MAJOR, so keeping it makes this MINOR" — which is a
non-sequitur, and an external review said so. Keeping the parameter satisfies the
letter of the MAJOR clause while delivering the substance of a breaking change. None
of `VERSIONING.md`'s three rules covered "documented parameter made inert", so the
release was being classified by a rule that did not exist. That clause now exists —
see *Security-forced inertness* in `VERSIONING.md` — and this release is the worked
example under it. The distinction that keeps it MINOR rather than MAJOR: `return_url`
is a convenience affecting where a human lands, immediately visible and breaking
nothing programmatically, whereas an **event** made dark with no successor is
invisible to the consumer and would be MAJOR.

This surfaced late, from CI rather than from review: three E2E tests
(`PUB-01`, `STACK-05`, `STACK-06`) encoded the old contract in their titles —
*"respects an explicit return_url"* — and went red the moment the blunt rule landed.
That is the tests doing their job, and it is worth recording that the *name* of a test
is where a contract is most legible: the failures said exactly what was being broken.

**Disclosed rather than smoothed over**, because an integrator whose rule stopped
resuming deserves to find the reason here rather than infer it. `wp_sudo_replay_refused`
fires for every consumed stash, so the transition is observable rather than silent.


**The forced reauthentication is communicated in prose, not in the version digit.**
Every user must reauthenticate once after upgrading, because pre-`4.9.0` sessions carry
no proof entry and no migration runs. That is the strongest argument anyone will make
for a major, and it is a communication problem rather than a versioning one: it is
handled by the **Upgrade Notice** in `readme.txt`, which reaches operators far more
reliably than a version number. A MAJOR is a promise that integrators have work to do;
here every hook still fires and every signature holds, so spending it would be a false
alarm — and the next MAJOR is wanted for the core-gate work, which will genuinely break
contracts.

**Known artifact on sites that ran the unreleased `5.0.0` build.** Any dev or staging
install that served **any admin or WP-CLI request** while running that build carries
`wp_sudo_db_version` stamped `5.0.0`. Activation is not required and is not the usual
route: `Plugin::init()` calls `maybe_upgrade()` on every `is_admin()` or `WP_CLI`
request, so simply loading wp-admin once was enough. An operator who never activated
the build is not thereby unaffected.
`Upgrader::maybe_upgrade()` returns early whenever the stored version is `>=`
`WP_SUDO_VERSION`, so after the rollback it is never rewritten — and a future routine
registered at `4.9.x`, **or at the real `5.0.0` when it ships**, would be skipped there.
Nothing is skipped today: the highest entry in `Upgrader::UPGRADES` is `4.0.0`.

Scope, read from `Upgrader::get_db_version()`: it uses `get_site_option()` on multisite
and `get_option()` otherwise, so the stamp is **one network-wide value in `wp_sitemeta`**
on a network, and per-site only on single-site.

**Do not clear the stamp by deleting the option.** Deleting it replays every routine from
`0.0.0`, and two of those are destructive: `upgrade_2_0_0()` calls `remove_role(
'site_manager' )` unconditionally, destroying any role holding that slug whatever its
origin; and `upgrade_3_3_0()`, on a single site with no `manage_wp_sudo` holder, grants
the four governance capabilities to **every administrator** — silent privilege escalation
on an install that deliberately revoked governance and relies on scoped
`WP_SUDO_RECOVERY_MODE`, which this plugin documents as a supported configuration.

**Disposition is tracked in [#393](https://github.com/dknauss/Sudo/issues/393), not
here.** The approach under consideration needs no operator action at all: because the
guard is `>=`, a routine keyed at exactly `5.0.0` would never run on a poisoned install
even after `5.0.0` genuinely ships — so the durable fix is the rule *no future routine is
keyed at or below `5.0.0`*, enforced by a test and a note in the "HOW TO ADD A NEW
UPGRADE" docblock. That lets the defect expire on its own. The repo has learned this
before: `upgrade_3_3_0()`'s own HISTORY docblock records a routine keyed at `3.1.0` that
never ran for sites stamped `3.1.1`–`3.1.3`, and the fix was re-keying above every stamp
in the wild rather than rewriting stamps.

**`4.9.0` payload.** Landed: forge-resistant per-login-session assurance (#278, #279,
PR #348), the reauth-lockout out-of-band clear (#280, PR #343), and the stash
confused-deputy fix — **v1 fail-closed (PR #350) plus the outright removal of automatic
replay**, which supersedes the v2 origin-bound layer #350 also carried; the v1-only
split PR #368 is closed as superseded). #273 (release-environment
matrix) rides the milestone as a **tag-time gate**, not payload.

**The v2 layer is removed, which closes its open review gate.** The v1/v2 split was
meant to give origin-bound replay its own adversarial pass; #350 merged both before that
happened. That review ran and produced eight findings — the first (a stash whose
confirmation named nothing was replayed anyway) fixed in PR #397, the remainder triaged.
**The triage is what ended the layer:** each finding was individually fixable, and
together they showed the eligibility question was the wrong one to keep answering.
Removing the primitive retires the gate rather than satisfying it.


**Membership rule.** A review finding that is not a regression introduced by the PR
under review gets **filed and deferred**, never bolted onto the PR in flight. #354,
#355 and #356 are the worked examples — all three were split out of #348/#343 review
rather than absorbed, and all three sit in `post-4.9.0`.

## WordPress.org publication status

- **WordPress.org plugin repository:** not published, and **submission is not planned** (maintainer decision, 2026-07-26). This is **not** a delay awaiting approval: the repository carries no obligation to stay "submission-ready", and no WordPress.org rule is a release gate here. Distribution is the GitHub tag and its release ZIP.
- **Plugin Check (PCP) is not a gate.** It is used only for occasional local testing. It is **not** among `main`'s six required status checks (`PHPUnit`, `Psalm`, `CodeQL`, `E2E Tests`, `E2E Nginx Smoke`, `docs-lint`). A `plugin-check` CI lane still exists and still runs, but a finding there does not block a release.
- **Process reference (not a gate):** [`docs/wporg-submission-checklist.md`](wporg-submission-checklist.md) retains the submission process in case the decision is ever revisited. Its **§1 pre-tag/core gates remain live** — they protect the tag and the release ZIP; its §2 WordPress.org-only gates do not.
- **Release environment assurance source:** `docs/release-environment-log.md` records per-version manual environment outcomes and deferrals.
- **E2E runtime evidence source:** [`docs/e2e-runtime-review.md`](e2e-runtime-review.md) records refreshed post-`v4.2.2` GitHub Actions E2E job runtimes and the current CI tuning decision; it is release-readiness evidence, not a WordPress.org submission gate.
- **Release confidence E2E source:** [`docs/release-e2e-confidence.md`](release-e2e-confidence.md) documents the manual release-grade E2E workflow across Apache/wp-env, nginx, nginx multisite, and Playground SQLite smoke targets.
- **Sudo fundamentals source:** [`docs/sudo-lite/fundamentals-cross-check.md`](sudo-lite/fundamentals-cross-check.md) preserves the Psudo Lite/Sudo Lite baseline used to review WP Sudo changes for fidelity to the core reauthentication model.
- **`readme.txt` stable tag:** package/release metadata for generated plugin zips **only**. With submission not planned it carries **no WordPress.org contract** — it selects no served SVN tag, so it may legitimately lead the latest git tag while `main` is unreleased (it does not currently: Stable tag `4.9.2` on `main`, latest tag `v4.9.2` — they agree). It does not indicate that this plugin is live in the WordPress.org repository.

## Latest release contents

`4.5.0` (tagged 2026-07-05) is a recommended security update bundling the work since `v4.2.2`:

- **Security hardening:** escalation-guard authority (the opt-in guard now requires the actor's promoting capability, not just a sudo session) and session-revocation token-binding (a stolen cookie can't revoke sessions).
- **Session governance & admin UX:** native "Revoke sudo sessions" Users-list bulk action, dashboard revocation/escalation visibility, and an Access-tab capability-table readability/accessibility/i18n pass.
- **Two Factor lifecycle bridge:** gates classic profile/user-edit provider lifecycle changes behind an active sudo session.
- **Localization packaging:** WP-CLI-backed POT regenerate/verify commands and the committed release-grade `wp-sudo.pot`.

See the `CHANGELOG.md` `4.5.0` section for the full itemized list.

`4.2.2` was a release-readiness refresh after Phase 13.1 gap closure:

- **Access tab polish:** Grant Capability now has a searchable administrator picker with tests while preserving the numeric `user_id` grant contract.
- **Canonical metrics and screenshot refresh:** `docs/current-metrics.md` verifies cleanly and `.wordpress-org/screenshot-6.png` shows the searchable picker.
- **Planning status refresh:** release/planning docs now state that WordPress.org submission is intentionally delayed/on hold while the repo remains submission-ready.

`4.2.1` was a WordPress.org package-readiness release:

- **Plugin Check input cleanup:** request values are unslashed at the flagged sites before sanitization, and the 4.0.0 upgrade notice fits directory limits.
- **Submission warning triage:** Pressship verifies with one documented slug warning for the intended `wp-sudo` slug; bridge/core-hook/prepared-SQL false positives are documented or scoped in code.
- **Release hygiene:** current metrics and Psalm baseline entries were refreshed after the cleanup.

`4.2.0` was the post-4.1.0 hardening and integration release:

- **Two Factor bridge hardening:** REST factor-management operations in the optional Two Factor bridge are gated behind WP Sudo.
- **WSAL bridge expansion:** the optional WP Activity Log sensor bridge maps the newer security/governance audit hooks into WSAL events, including escalation blocks, session revocation, recovery-mode use, governance-capability changes, missing built-in rules, and regex-rule failures.
- **Gutenberg REST UX groundwork:** cookie-authenticated REST `sudo_required` responses include a `challenge_url` so editor clients can send the user to reauthenticate without using server-side Request_Stash replay; headless REST policy responses remain unchanged.
- **Test hardening:** integration coverage was added for activation/deactivation lifecycle behavior, `WP_Session_Tokens::destroy_all()` login-session-binding invariants, and live admin-escalation guard hooks.
- **Planning/reference updates:** the Gutenberg route inventory, build-free Phase 2 decision, API-only config surfaces, and accepted blog-invariant Connectors matcher cache behavior are documented.

`4.1.0` (tagged 2026-06-24) remains the security-hardening release that closed the coordinated-disclosure gate-completeness findings and introduced the opt-in admin-escalation guard.

Canonical source for post-tag drift after the latest tag (`v4.9.2`): `git log v4.9.2..main --oneline` (**one commit: this post-tag housekeeping commit itself**, which advances `blueprint.json` and this file after the tag. No payload drift. The note that follows described the `v4.8.0` era and is retained as history: it was **not empty** — `main` carries the unreleased alert-bridge role-drift push (#226), scoped break-glass recovery (#240), the in-editor session-status indicator (#262), plus docs; see "HISTORICAL — what was unreleased on `main` past `v4.8.0`" above).

## `4.5.0` release contents (shipped)

The `v4.5.0` tag (2026-07-05) shipped the **substantial** body of work
accumulated since `v4.2.2` — two completed GSD milestones (v4.4.0 Two Factor
Lifecycle Bridge and v4.5 Session Governance & Admin UX) plus security
hardening. `main` has since progressed through `v4.6.0` (2026-07-06),
`v4.7.0` (2026-07-16), and `v4.8.0` (2026-07-23) — all released — and then through
`v4.9.0` (2026-07-27), `v4.9.1` (2026-07-28) and `v4.9.2` (2026-07-29) (the canonical current state is "Latest
GitHub/tagged release" and "Current `main` release state" above, which are
authoritative; this section is only a historical record of the `4.5.0` payload). Drift source for this section:
`git log v4.2.2..v4.5.0 --oneline`; see the `CHANGELOG.md` `4.5.0` section for
the curated feature list.

**Security hardening (backward-compatible fixes):**

- **Escalation-guard authority hardening:** the opt-in admin-escalation guard now requires the acting user to hold the promoting authority (`promote_users` for administrator grants, checked on the target blog; existing super-admin for `grant_super_admin`) *in addition to* an active/in-grace sudo session — closing a gap where a low-privilege account holding a sudo session could pass the backstop on a broken-access-control route.
- **Session-revocation hardening:** the Users-list revocation paths now require the operator's token-bound `Sudo_Session::is_active()` (not the browser-independent expiry check), so a stolen auth cookie or a second session without its own sudo cannot revoke other users' sessions; the operator's cookie is preserved across consecutive revokes.

**Features / UX (v4.5 Session Governance & Admin UX):**

- **Users-list bulk revocation:** native "Revoke sudo sessions" bulk action (replacing the revoke-all button + unstyled interstitial), nonce-verified via a `load-users.php` interceptor, one rate slot per batch, self-skip, current-site membership guards; site-wide revoke stays CLI-only.
- **Dashboard widget revocation visibility:** the Session Activity widget records/renders `session_revoked` and `escalation_blocked` events with distinct pills and translatable labels.
- **Governance coverage panel fix (multisite):** the Access-tab coverage panel names the context-correct capability and excludes multisite super admins from false "cannot access" listings.
- **Access-tab capability table readability + accessibility + i18n:** one row per capability with friendly labels (slugs demoted to tooltip + screen-reader text), per-capability accessible names on the Revoke controls, and translatable capability labels.
- **Sudo Active badge-count invalidation** on session grant/teardown from every execution context; registry scrub of the stale `wp_sudo_revoke_session` AJAX reference.
- **Settings-tab preservation** across a sudo reauth (single-site and multisite network settings).

**Integration / infrastructure (v4.4.0 + follow-ups):**

- **Two Factor profile-provider lifecycle bridge:** the optional bridge (`bridges/wp-sudo-two-factor-lifecycle-bridge.php`) gates meaningful classic `profile.php` / `user-edit.php` provider lifecycle changes behind an active sudo session, alongside the existing REST factor-management gates. Completes the v4.4.0 milestone.
- **Playground demo fixes + release CI:** PR-preview installs fetch plugin archives via the CORS proxy; an install ZIP asset is built/attached on version tags.
- **Localization packaging readiness:** WP-CLI-backed Composer commands for POT generation/verification and a committed `languages/wp-sudo.pot`.

**Documentation (no runtime impact):** Patchstack 2FA compatibility runtime-validated offline against a licensed Pro 2.3.6 fixture and documented (not a shipped integration); SSO/passwordless auth boundary clarified; Two Factor ecosystem/integration docs and canonical metrics refreshed. Unit suite at `4.5.0`: **986 unit tests / 3,003 assertions** (for the current count see `docs/current-metrics.md`, the canonical source).

Canonical source for drift after the tag: `git log v4.5.0..main --oneline`.

### Release environment assurance

Phase 17 added [`docs/release-environment-log.md`](release-environment-log.md) as the record of record for release-grade manual environment matrix outcomes. The `v4.5.0` tag's environment-matrix gate was **cleared** (Apache lane completed, minimum-supported-WordPress (6.4) floor CI-covered, managed-host lane cleared by explicit maintainer waiver — see the log). For the staged **`4.6.0`** package the gate is **not auto-cleared**: because `4.6.0` adds the block-editor in-editor reauth capability, run the full release-grade E2E before tagging and record a **conscious** reuse-or-rerun decision for the host/floor matrix in the log (see the `v4.6.0` tag checklist above). The historical `v4.2.2` row remains **Deferred**.

Release readiness now distinguishes **Pre-tag/core** gates from **WordPress.org-only** gates. Pre-tag/core gates cover version sync, Composer validation commands, external-claim audit, changelog/readme/release-status sanity, package metadata, and the release environment matrix/log gate. WordPress.org-only gates cover the readme validator, clean-package Plugin Check, SVN layout/upload, listing assets, screenshot/caption parity, slug-lock decision, and final publication approval. **Those WordPress.org-only gates are dormant:** submission is not planned (see *WordPress.org publication status* above), so only the pre-tag/core gates apply to a release.

### E2E runtime evidence

Phase 18 added [`docs/e2e-runtime-review.md`](e2e-runtime-review.md) as the durable GitHub Actions E2E runtime review. Refreshed post-`v4.2.2` evidence found `E2E Tests 1/4 (challenge-basic-admin)` to be the repeatable long pole and records exactly one no-coverage-loss follow-up proposal: rebalance a small test slice within the existing four baseline groups. No workflow file was edited in Phase 18, and this review remains CI evidence rather than a WordPress.org publication gate.

**Pre-tag checklist reminder:** before tagging a future release, confirm the five version-sync points are still in sync, re-verify external claims added since the previous tag, ensure the CHANGELOG/`readme.txt` release entries are dated/current, and update this file's "Latest tagged release" once the tag is cut.

## WordPress release posture

### Latest stable WordPress release

- **Latest stable major/minor branch:** `7.0`
- **Latest stable patch release observed:** `7.0.2` (verified as the latest stable via `api.wordpress.org/core/version-check` on 2026-07-24; the `7.0` line went GA on May 20, 2026, with `7.0.2` the current patch — matching the WP 7.0.2 environment recorded in `docs/security-test-results-4.8.0.md`)

### Forward lane used by this repository

- **Forward WordPress lane in CI/local previews:** `7.0`
- CI and browser smoke lanes have been updated from `7.0-RC1` to the `7.0` GA release.

### WordPress 7.0 final release status

- WordPress 7.0 **shipped on May 20, 2026** per the updated Make/Core schedule published April 22, 2026.
- The `Tested up to` value in `readme.txt` and README support badges should now reflect `7.0`.

## Package metadata rule

WordPress 7.0 is now the latest stable release for package compatibility metadata:

- keep `readme.txt` **Tested up to** at `7.0` (this field tracks the major/minor branch, not the patch — the `7.0` line is currently patched to `7.0.2`)
- keep README support badges aligned with the `7.0` line
- CI forward lane is already `7.0` GA; no further lane change needed until 7.1 development opens

## Canonical sources

### Repository sources

- `wp-sudo.php`
- `readme.txt`
- `CHANGELOG.md`
- `docs/current-metrics.md`
- `tests/MANUAL-TESTING.md`
- `docs/release-environment-log.md`

### External sources

- WordPress release archive: <https://wordpress.org/download/releases/>
- WordPress download page: <https://wordpress.org/download/>
- WordPress 7.0 release page: <https://make.wordpress.org/core/7-0/>
- WordPress 7.0 updated release party schedule: <https://make.wordpress.org/core/2026/04/22/wordpress-7-0-release-party-updated-schedule/>
- Delay announcement: <https://make.wordpress.org/core/2026/03/31/extending-the-7-0-cycle/>

## Update procedure

Update this file whenever any of the following changes:

1. latest tagged release, WordPress.org publication status, or current `main` target version
2. `CHANGELOG.md` unreleased feature list in a way that changes current `main` status
3. latest stable WordPress release line
4. forward WordPress lane (current GA release or future RC/preview lane)
5. WordPress release-date posture (delays, final date publication, GA completion)
