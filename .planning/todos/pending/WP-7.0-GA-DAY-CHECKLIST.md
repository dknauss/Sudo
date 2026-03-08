# WP 7.0 GA-Day Checklist

Last checked: March 8, 2026
Owner: wp-sudo maintainers

## Current RC status

- WordPress core API latest stable: `6.9.1`.
- Public pre-release builds listed: `7.0-beta1`, `7.0-beta2`, `7.0-beta3`.
- No `7.0-RC*` build is publicly listed yet.

## Pre-GA cadence (run on each RC)

1. Install the latest RC build in the existing manual-test environment.
2. Run `tests/MANUAL-TESTING.md` sections relevant to WP 7.0 compatibility.
3. Record execution date + RC build in the manual testing log.
4. Run repo quality gates:
   - `composer test:unit`
   - `composer analyse:phpstan`
   - `composer analyse:psalm`
   - `composer lint`
5. Open/track any regressions before GA release day.

## GA-day execution (target: April 9, 2026)

1. Re-run full manual verification on the final WordPress 7.0 GA build.
2. Update version-compatibility docs:
   - `readme.txt` (keep `Tested up to: 7.0` accurate)
   - `readme.md`
   - `docs/security-model.md` (if any release-version wording is stale)
3. Remove the temporary core workaround once WordPress 7.0 behavior is confirmed:
   - `Admin::handle_err_admin_role()` and related hook/tests.
4. Re-run release gates:
   - `composer test:unit`
   - `composer test:integration`
   - `WP_MULTISITE=1 composer test:integration`
   - `composer analyse:phpstan`
   - `composer analyse:psalm`
   - `composer lint`
5. Update changelog + release notes with WP 7.0 GA validation results.

## Post-GA follow-up

1. Open a short retrospective issue for any compatibility findings.
2. Confirm roadmap WP 7.0 section is marked complete with exact verification date.
