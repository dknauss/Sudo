# Milestones


## v4.0.0 — Pre-Public Hardening Baseline (Archived)

**Shipped:** v4.0.0–v4.2.2
**Phases:** 6 (numbered 11–15, including inserted 13.1)
**Last phase number:** 15

### Phases

| # | Phase | Version / Status |
|---|-------|------------------|
| 11 | Connectors Registry-Aware Matcher | v4.0.0 |
| 12 | Breaking Changes and Floor Bump | v4.0.0 |
| 13 | Migration Safety and Governance Audit | v4.0.0–v4.1.0 |
| 13.1 | Access-tab UX Polish and CI Test-Speed | v4.2.2 |
| 14 | WordPress.org Readiness | v4.2.x |
| 15 | Manual Testing Environment Checklist | v4.2.x |

### Key Outcomes

- Registry-aware Connectors matcher with regex fallback.
- Removed legacy compatibility governance paths and raised floors to WordPress 6.4 / PHP 8.2.
- Hardened migration, recovery, and governance-capability behavior.
- Refreshed Access-tab UX, screenshots, release metadata, and WordPress.org readiness docs.
- Established manual environment matrix and kept WordPress.org submission delayed/on hold but ready.

## v2.13 — Security Hardening Sprint (Archived)

**Shipped:** v2.10.2–v2.13.0
**Phases:** 5 (numbered 01–05)
**Last phase number:** 05

### Phases

| # | Phase | Version |
|---|-------|---------|
| 01 | Request Stash Redaction and Upload Action Coverage | v2.10.2 |
| 02 | Non-Blocking Rate Limiting | v2.10.2 |
| 03 | Rule Schema Validation and MU Loader Resilience | v2.11.0 |
| 04 | WPGraphQL Persisted Query Strategy and WSAL Sensor | v2.11.0 |
| 05 | IP + User Multidimensional Rate Limiting | v2.13.0 |

### Key Outcomes

- Request stash redaction (passwords no longer stored in transients)
- Non-blocking rate limiting (no more PHP-FPM worker exhaustion)
- Rule-schema validation (malformed third-party rules fail closed)
- MU loader resilience (non-standard directory layouts supported)
- WPGraphQL persisted-query classification hook
- WSAL sensor bridge for enterprise audit
- IP + user multidimensional lockout policy
- 496 unit tests, 132 integration tests at completion
