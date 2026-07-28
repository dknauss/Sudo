# Phase 27 Plan 03 — Reconstructed Candidate Result

**Status:** Leading candidate for the narrow copied-auth-cookie-only upload
threat; reconstructed load-bearing tranche passes, full Phase 27 exit pending

**Supersedes:** The selection claims in `27-HANDOFF-DECISION.md`

## Result

**Key rule:** approve the bytes the effect will consume, not merely the upload
button, route, filename, or request envelope.

The editor-style preflight sequence remains viable after removing the singleton
state that invalidated the first experiment:

```text
Browser-local unsent bytes
  -> independently stored immutable intent
  -> fresh factor approval bound to login session + browser binding + intent
  -> one real multipart submission
  -> server recomputes the submitted-byte digest
  -> conditional approved-to-consumed transition
  -> effect
```

Two browser contexts carrying the same WordPress login session receive
different server-minted bindings and create different intent IDs. Browser B
cannot approve or consume Browser A's intent without Browser A's binding.
Creating B's intent does not overwrite A's record.

This is not device or tab binding. Copying the complete cookie state, including
the action-binding cookie, reproduces Browser A's ambient authority and remains
a demonstrated boundary failure. Active same-origin script and non-browser
callers also remain outside the accepted claim.

## Evidence

Run:

```bash
npm run verify:research:phase27
```

The current evidence consists of:

- a 12-test Chromium browser-relationship baseline;
- a machine-checked guard/manifest bijection for the implemented reconstruction
  tranche;
- ten focused mutations, each dying first at its named assertion;
- actual browser `FormData`, with the decoded file-part digest distinguished
  from the multipart-envelope digest;
- rejection of raw octet-stream and duplicate package parts in the Node
  multipart model; PHP's normalized `$_FILES` view cannot independently prove
  raw duplicate-part cardinality;
- `__Host-`, `Secure`, `HttpOnly`, host-only, and `Path=/` cookie behavior
  observed by Chromium;
- a test-only adapter running inside real WordPress and PHP;
- the complete WordPress browser flow running through a generated HTTPS
  endpoint rather than a localhost secure-cookie exception;
- an exact WordPress 7.0.2 check and a host/container adapter SHA-256 match;
- WordPress cookie authentication, capability checks, REST nonces, and
  `wp_get_session_token()` identity;
- PHP `$_FILES` parsing and `hash_file()` over the uploaded temporary file;
- copied-session browsers receiving independent server-registered bindings;
- raw duplicate binding-cookie rejection in PHP;
- account-scoped approval throttling that survives intent and binding rotation;
- the corresponding victim-lockout denial-of-service boundary;
- a minimal capturing file-write sink that copies the exact uploaded temporary
  file, then records the digest of the bytes written by that effect;
- MySQL conditional consumption with exactly one successful file-write effect
  at 2, 8, and 32 concurrent HTTP submissions; and
- three WordPress-side mutations showing that focused assertions fail when
  atomic consumption, effect recording, or the checked-file-to-effect handoff
  is disabled or substituted.

The WordPress adapter is research code under `tests/e2e/fixtures/`. Its effect
is a capturing file write, not plugin installation. It proves that the checked
temporary file can be the file consumed by an effect; it does not yet prove the
same handoff through `WP_Upgrader`. It is not plugin production code and does
not select a core storage API.

## Accepted claim

> A second browser holding a copied, still-valid WordPress login session, but
> not the account's reauthentication factor or the first browser's separately
> minted action-binding cookie, cannot approve or execute the demonstrated
> capturing file-write effect.

The tests also show that a copied-session browser with the correct factor can
approve its own independently created intent. That is expected: the factor,
not accidental singleton state or intent-ID secrecy, is the authorization
boundary.

## Remaining work before a core proposal

This result identifies a leading protocol shape; it does not satisfy the full
Phase 27 hardening ledger. Before calling the candidate selected or presenting
implementation details as core-ready:

- expand the executable mutation manifest from the reconstructed load-bearing
  tranche to every clause in `27-02-RECONSTRUCTION-PLAN.md`;
- add declared-length and streaming oversized-body measurements;
- add target-existence timing and resolver-call oracle measurements;
- exercise expiry, cancellation, logout, password reset, and session
  destruction against persistent records;
- inject cache and storage failures;
- repeat the WordPress adapter tests on multisite;
- repeat the pushed-SHA evidence in CI; and
- obtain two fresh-context reviews that reproduce both the candidate path and
  the documented full-cookie-state failure.

Those are hardening and reproducibility gates. None currently supplies evidence
that the reconstructed protocol is a dead end, but the current tranche must not
silently turn their absence into a broader security claim.

The current transition consumes approval before invoking the file-write effect.
That ordering guarantees at most one attempted effect but can lose the approved
action if the effect then fails. It does not make the database transition and
filesystem effect one transaction; retry and recovery semantics remain core
design work.
