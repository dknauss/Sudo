# Security Policy

## Supported Versions

The default branch (`main`) is the only supported version. Security fixes are
applied to `main` and released as a new version. Older branches and released
tags do not receive backported security patches. Once the plugin is published to
the WordPress.org plugin directory, the most recently published version is the
supported version.

| Version | Supported |
|---|---|
| `main` / latest published release | Yes |
| Older branches and tags | No |

## Reporting a Vulnerability

**Do not open a public GitHub Issue for security problems.** GitHub Issues are
not an acceptable channel for initial security contact — reports there are
public and may expose details before a fix is available.

Use GitHub's private vulnerability reporting flow from the repository Security
tab when it is available. If that option is not visible, contact the maintainer
privately through the contact methods listed on [Dan Knauss's profile](https://github.com/dknauss)
or [dan.knauss.ca](https://dan.knauss.ca).

Include:

- Affected action gate, capability check, or workflow
- Reproduction steps or a proof of concept
- Impact assessment
- Suggested mitigation if you have one

## Response Targets

- Initial triage response: within 5 business days
- Status update after validation: within 10 business days
- Public disclosure: only after a fix or mitigation is available

## Security Fix Changelog Convention

Security fixes are described in `CHANGELOG.md` and `readme.txt` using a
`**Security:**` prefix in the release notes. The description states what
was hardened or corrected without disclosing the specific attack vector,
proof-of-concept details, or reproduction steps. Example format:

```
**Security:** Hardened challenge session binding to prevent token reuse
across user context switches.
```

CVEs are not proactively requested. Third-party researchers may request or
receive CVE assignment independently.

## Scope

Reports may cover privileged action gating, challenge flows, integration
points, or build and release automation.
