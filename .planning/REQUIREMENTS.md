# Requirements: WP Sudo v4.4.0 — Two Factor Lifecycle Bridge

**Defined:** 2026-06-30
**Core Value:** Every destructive WordPress admin action requires proof that the person at the keyboard is still the authenticated user — not a hijacked session, XSS payload, or unattended browser.

## v4.4.0 Requirements

Requirements for the Two Factor Lifecycle Bridge milestone. Each maps to roadmap phases.

### Source Verification

- [x] **TFA-SRC-01**: Maintainer can see refreshed upstream WordPress/two-factor source evidence before implementation changes rely on third-party route, form-field, hook, nonce, or meta-key details.
- [x] **TFA-SRC-02**: Maintainer can trace every added or changed third-party technical claim to an upstream source URL, commit or revision, and checked date.

### Profile Provider Guard

- [x] **TFA-GUARD-01**: User can save an unrelated classic profile update without WP Sudo gating when no upstream Two Factor lifecycle fields are present.
- [x] **TFA-GUARD-02**: User can resubmit unchanged upstream Two Factor provider settings without WP Sudo gating when the enabled-provider set and primary provider are unchanged.
- [x] **TFA-GUARD-03**: User must have an active WP Sudo session before a classic profile save enables or disables an upstream Two Factor provider.
- [x] **TFA-GUARD-04**: User must have an active WP Sudo session before a classic profile save changes the upstream Two Factor primary provider.
- [x] **TFA-GUARD-05**: User must have an active WP Sudo session before a classic profile save removes, replaces, or first enrolls a TOTP-backed factor that can satisfy future sudo challenges.
- [x] **TFA-GUARD-06**: Developer can review the guard predicate as an idempotent, enrollment-aware rule instead of a broad `profile.php` / `user-edit.php` save gate.

### Existing REST Bridge Assurance

- [x] **TFA-REST-01**: Existing bridge coverage for `POST /two-factor/1.0/generate-backup-codes` remains tested and unchanged unless refreshed upstream source requires a deliberate update.
- [x] **TFA-REST-02**: Existing bridge coverage for `POST` and `DELETE /two-factor/1.0/totp` remains tested and unchanged unless refreshed upstream source requires a deliberate update.

### Documentation and Release Posture

- [ ] **TFA-DOC-01**: Maintainer docs distinguish built-in challenge-time Two Factor validation, existing REST lifecycle bridge coverage, and the new classic profile-provider lifecycle guard.
- [ ] **TFA-DOC-02**: Release/readiness docs do not claim Patchstack Security runtime compatibility or ship Patchstack bridge behavior until a paid Patchstack-enabled fixture has been tested.
- [ ] **TFA-DOC-03**: Current metrics and changelog/release notes are updated only if implementation changes affect canonical counts or release-facing behavior.

## Future Requirements

Deferred to a later milestone. Tracked but not in current roadmap.

### Patchstack Runtime Compatibility

- **PATCH-01**: Maintainer can run WP Sudo against a paid Patchstack-enabled fixture and record manual challenge plus factor-lifecycle behavior before making runtime compatibility claims.
- **PATCH-02**: User can use Patchstack Security 2FA with WP Sudo through a verified bridge only after runtime behavior is proven against a paid fixture.

### Multisite Browser Coverage

- **MULTI-02**: Network operator can verify network theme enable/disable stash replay in a real multisite browser environment.
- **MULTI-03**: Network operator can verify `sites.php` confirm/action2 destructive flows in a real multisite browser environment.
- **MULTI-04**: Network operator can verify network settings POST replay in a real multisite browser environment.

## Out of Scope

Explicitly excluded. Documented to prevent scope creep.

| Feature | Reason |
|---------|--------|
| Patchstack bridge implementation | Requires a paid Patchstack-enabled runtime fixture; source inspection alone is insufficient for runtime claims. |
| Broad profile-save gating | Would block unrelated user profile edits and violates the Phase 19 design constraint. |
| Changing built-in challenge-time Two Factor validation | The milestone targets factor lifecycle management, not the existing challenge provider delegation flow. |
| New production dependency or JavaScript build step | WP Sudo’s zero-production-dependency and no-build-step posture remains a release constraint. |
| Product release tag/version bump | v4.4.0 is a GSD milestone name until release metadata is intentionally changed. |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| TFA-SRC-01 | Phase 21 | Complete |
| TFA-SRC-02 | Phase 21 | Complete |
| TFA-GUARD-01 | Phase 22 | Complete |
| TFA-GUARD-02 | Phase 22 | Complete |
| TFA-GUARD-03 | Phase 22 | Complete |
| TFA-GUARD-04 | Phase 22 | Complete |
| TFA-GUARD-05 | Phase 22 | Complete |
| TFA-GUARD-06 | Phase 21 | Complete |
| TFA-REST-01 | Phase 21 | Complete |
| TFA-REST-02 | Phase 21 | Complete |
| TFA-DOC-01 | Phase 23 | Pending |
| TFA-DOC-02 | Phase 23 | Pending |
| TFA-DOC-03 | Phase 23 | Pending |

**Coverage:**
- v4.4.0 requirements: 13 total
- Mapped to phases: 13
- Unmapped: 0 ✓

---
*Requirements defined: 2026-06-30*
*Last updated: 2026-06-30 after roadmap traceability mapping*
