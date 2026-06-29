# Plan 04-02 Summary: Optional WSAL Sensor Bridge

## Objective

Implement an optional WSAL bridge that consumes existing WP Sudo audit hooks and emits structured WSAL events without creating a hard dependency.

## Changes

### Production

- **`bridges/wp-sudo-wsal-sensor.php`** (new)
  - Added WSAL availability detection.
  - Added hook-to-event mapping for 9 WP Sudo audit hooks.
  - Added structured payload builder for each hook contract.
  - Added safe emit path that supports WSAL Alert Manager method variants.
  - Bridge is inert when WSAL APIs are unavailable.
- **`includes/class-admin.php`**
  - Added admin help-tab discoverability note pointing to:
    - `bridges/wp-sudo-wsal-sensor.php`.

### Tests

- **`tests/Unit/WsalSensorBridgeTest.php`** (new)
  - Verifies inert behavior when WSAL is absent.
  - Verifies listener registration when WSAL API is available.
  - Verifies payload mapping (`user_id`, `rule_id`, `surface`, etc.) into emitted event data.
  - Verifies pass-through callback behavior (no WP Sudo hook flow mutation).

## Verification Results

- ✅ `vendor/bin/phpunit --configuration phpunit.xml.dist --do-not-cache-result tests/Unit/WsalSensorBridgeTest.php`
  - Passed (`4 tests`, `22 assertions`).
- ✅ `vendor/bin/phpunit --configuration phpunit.xml.dist --do-not-cache-result tests/Unit/AdminTest.php --filter "help_tabs|recommended_plugins"`
  - Passed (`5 tests`, `17 assertions`).
- ✅ `php -l bridges/wp-sudo-wsal-sensor.php tests/Unit/WsalSensorBridgeTest.php includes/class-admin.php`
  - No syntax errors.

## Blockers / Environment Notes

- ⛔ `composer analyse:phpstan` and `composer lint` remain intermittently stalled in this runner; targeted unit coverage for touched scope is green.
