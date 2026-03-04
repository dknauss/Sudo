# Proposed Next Steps: WP Sudo Hardening Sprint

## 1. Original Proposed Next Steps (DEPRECATED)
*These were my initial recommendations based purely on my standalone architectural assessment of the WP Sudo codebase. They have been deprecated in favor of the Unified Roadmap below after reviewing the Codex assessment.*

Based on the architectural assessment of WP Sudo, these are four actionable code-level fixes that should be implemented immediately to harden the system's performance, concurrency handling, and resilience to denial-of-service (DoS) vectors. These fixes do not require large architectural rewrites (such as implementing the modal challenge) and can follow standard TDD practices.

### 1.1. Optimize the `init` Tamper Network Canary (`class-plugin.php`)
**The Issue:** `Plugin::enforce_editor_unfiltered_html()` runs on **every single page load** at `init` priority 1, checking the database for the `editor` role's capabilities. This adds unnecessary overhead to all public frontend traffic and read-only API calls.
**The Fix:** 
* Restrict this check to run only when logically required.
* Hook it into `set_user_role`, `profile_update`, and WP admin initialization (`admin_init`), rather than blanket-firing on every frontend and backend request.

### 1.2. Optimize REST API Regex Execution (`class-gate.php`)
**The Issue:** `Gate::intercept_rest` runs every REST API request through a `preg_match` loop spanning the entire action registry (`Action_Registry`). As the rule registry grows and API traffic scales, this unnecessarily burns CPU cycles on completely harmless read-only requests.
**The Fix:** 
* Before evaluating the regex engine over every rule, add a lightweight schema/namespace condition.
* For example, if the request routing does not match a namespace or HTTP method we care about (like `DELETE`, `PUT`, `POST` on `/wp/v2/`), exit `intercept_rest` early.

### 1.3. Rate-Limit Transient Stash Generation (`class-request-stash.php`)
**The Issue:** The `Request_Stash::save()` method creates a new transient in the `wp_options` table *every time* a gated request is intercepted, before password rate-limiting logic ever applies. An attacker hitting a gated URL 10,000 times will bloat the database options table with 10,000 stale transients.
**The Fix:** 
* Append the `$user_id` to the transient naming schema or tracking set.
* Enforce a hard cap (e.g., maximum 5 concurrent active stashes per user).
* Actively delete the oldest stash prior to saving a new one when the cap is hit.

### 1.4. Mitigate TOCTOU in Rate Limiting (`class-sudo-session.php`)
**The Issue:** `Sudo_Session::record_failed_attempt()` reads a user's failure count, increments it in PHP, and writes it back. Under high concurrency, an automated script could bypass the 5-attempt limit by firing burst requests before the database value updates the static integer lock.
**The Fix:** 
* Shift from an integer counter to storing an array of collision-resistant timestamps in user meta.
* Calculate failures dynamically against the array length/recency, making asynchronous burst-bypassing substantially more difficult without native atomic integer locks.

---

## 2. The Codex Delta: How My Thinking Changed
After reviewing the `WORKING-ASSESSMENT-Codex.md` file, my strategic priorities shifted significantly from addressing *performance bottlenecks* to patching *immediate data-exposure and operational availability vectors*. 

Here is how Codex influenced my thinking and changed my recommendations:

1. **The Stash is a Vulnerability, Not Just a Flaw (P1 Security):** I noticed the stash triggered DoS database bloat and dropped file uploads. However, Codex correctly identified that the stash stores *raw passwords and sensitive payloads verbatim* (`Request_Stash->sanitize_params`). Saving raw `$_POST` data directly into standard database transients constitutes a severe data-exposure risk. **Change:** Creating a stash data-minimization (redaction) routine instantly became the top priority, with the caveat that **redaction must preserve replay fidelity** (meaning it cannot destroy necessary payload structure, even if it can't replay binaries).
2. **Rate Limiting is an Availability Threat (P1 Availability):** I focused on the integer-based TOCTOU race condition that allows attackers to brute-force past the lockout limit. Codex pointed out the other side of that coin: the use of `sleep($delay)` to slow attackers will tie up PHP-FPM workers under heavy load. **Change:** Replacing `sleep()` with non-blocking time-based throttling became a P1 priority, as it solves both my TOCTOU concern and Codex's availability concern simultaneously.
3. **Rule-Schema Validation (P2 Reliability):** I originally feared that a malformed rule injected via the `wp_sudo_gated_actions` filter would crash the `Gate`. Codex correctly pointed out that this fear was overstated: the `Gate` already uses `safe_preg_match()` to fail-close on invalid regex. **Change:** The true remaining risk is broader schema/type invalidity, which still warrants strict array schema validation, but isn't an immediate crash-threat.
4. **Stash-DoS is an Insider Threat:** Codex correctly pointed out that my framing of "anonymous internet abuse" spamming the Request Stash to bloat the database was wrong. The interception paths require a logged-in user to trigger the challenge stash. **Change:** The DoS risk is specifically an *authenticated/insider abuse* vector.
5. **Upload Action Coverage (New Finding):** Codex astutely noticed that the current `Action_Registry` covers `install-plugin` and `install-theme`, but misses the explicit `update.php?action=upload-plugin` (and theme) paths. **Change:** Adding explicit tests and rules for upload actions is now a required hardening step.
6. **Deprioritizing the Modal Challenge Rewrite:** I initially believed the "Client-Side Modal Challenge" was a high-priority structural fix necessary to repair the Stash flaws. Codex convinced me that building data redaction into the existing `Request_Stash` is a much faster, more pragmatic short-term patch, allowing the Modal Challenge to safely return to the "Later (design-heavy)" backlog.

---

## 3. Unified Proposed Next Steps (Active Roadmap)

Here are the immediate, prioritized code fixes incorporating both Gemini's and Codex's insights. These should be tackled sequentially as a dedicated **Hardening Sprint** using TDD.

### 3.1. Request Stash Data Minimization & Rate Limiting (P1 Security & Availability)
* **The Issue:** The `Request_Stash` stores verbatim `$_POST` payloads, meaning WP Sudo actively writes raw passwords, tokens, or malicious payloads into standard WordPress DB transients. Generating these transients before rate-limiting applies also introduces a Database DoS threat (specifically from **authenticated/insider** abuse).
* **The Fix:**
  * Implement explicit secret-key redaction (replacing `password`, `user_pass`, `token` fields with `[REDACTED]`) before transient serialization. This redaction design must meticulously preserve replay fidelity for non-secret form fields.
  * Append `$user_id` to the transient logic and enforce a hard cap (e.g., maximum 5 concurrent active stashes per user) to eliminate the DB bloat vector.

### 3.2. Non-Blocking Rate Limiters (P1 Availability & Concurrency)
* **The Issue:** `Sudo_Session::record_failed_attempt` enforces progressive delays via a blocking PHP `sleep($delay)`. Under heavy abuse, an automated script will hold PHP-FPM workers open, collapsing site throughput. Likewise, relying on a static integer counter exposes a TOCTOU (Time Of Check to Time Of Use) race condition.
* **The Fix:** Replace `sleep()` with non-blocking time-based throttling. Transition the integer failure counter into an array of collision-resistant timestamps to organically prevent burst-bypassing without consuming server resources.

### 3.3. Strict Rule-Schema Validation (P2 Reliability)
* **The Issue:** The `Action_Registry` exposes the `wp_sudo_gated_actions` filter to third-party developers, but does not sanitize or validate the schema of the returned array before the `Gate` processes it at runtime. While the `Gate` handles invalid regex gracefully (`safe_preg_match`), broader unvalidated schema/type invalidity remains a risk.
* **The Fix:** Add strict array shape validation and fail-closed normalization within `Action_Registry::get_rules()`. If a filtered rule lacks required keys (`id`, `admin`, `rest`, etc.) or presents invalid types, silently discard it.

### 3.4. GraphQL Persisted Query Detection (P2 Security Boundary)
* **The Issue:** WPGraphQL surface gating in "Limited mode" currently relies on a heuristic mutation detection strategy that does not account for persisted-query mutation pathways.
* **The Fix:** Update the GraphQL policy docs and enforcement logic to explicitly handle limited-mode headless requests utilizing persisted query IDs.

### 3.5. Upload Action Coverage (P2 Security Boundary)
* **The Issue:** The current action registry accurately gates `install-plugin` and `install-theme`, but it misses explicit gating for the manual `update.php?action=upload-plugin` (and `upload-theme`) HTTP vectors.
* **The Fix:** Explicitly include upload action coverage in the hardening scope. Write explicit tests and rules covering these specific direct-upload actions to confirm intended gating behavior and close any mismatched surface area.

### 3.6. Optimize `init` Tamper Network Canary (P3 Performance)
* **The Issue:** `Plugin::enforce_editor_unfiltered_html()` runs on every page load at global `init` priority 1, checking the database for the `editor` role's capabilities.
* **The Fix:** Hook the canary exclusively into appropriate lifecycle events (e.g., `set_user_role`, `profile_update`, and WP `admin_init`) to eliminate unnecessary processing overhead on front-end traffic.
