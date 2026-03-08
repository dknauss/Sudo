#!/usr/bin/env bash

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
METRICS_FILE="$REPO_ROOT/docs/current-metrics.md"

if [ ! -f "$METRICS_FILE" ]; then
	echo "ERROR: Metrics file not found: $METRICS_FILE"
	exit 1
fi

metric_cell() {
	local metric="$1"

	awk -F'|' -v metric="$metric" '
		{
			key = $2
			gsub(/^[[:space:]]+|[[:space:]]+$/, "", key)
			if (key == metric) {
				val = $3
				gsub(/^[[:space:]]+|[[:space:]]+$/, "", val)
				print val
				exit
			}
		}
	' "$METRICS_FILE"
}

metric_number() {
	local metric="$1"
	local value

	value="$(metric_cell "$metric")"
	if [ -z "$value" ]; then
		echo "ERROR: Metric row not found in docs/current-metrics.md: $metric"
		exit 1
	fi

	value="${value//,/}"
	printf '%s\n' "$value" | grep -Eo '[0-9]+(\.[0-9]+)?' | head -n1
}

add_failure() {
	local label="$1"
	local expected="$2"
	local actual="$3"
	FAILURES="${FAILURES}${label}: docs=${expected}, actual=${actual}"$'\n'
}

UNIT_OUTPUT="$(cd "$REPO_ROOT" && composer test:unit)"
UNIT_SUMMARY="$(printf '%s\n' "$UNIT_OUTPUT" | grep -Eo 'OK \([0-9]+ tests, [0-9]+ assertions\)' | tail -n1 || true)"

if [ -z "$UNIT_SUMMARY" ]; then
	echo "ERROR: Could not parse unit test summary from composer test:unit output."
	echo "$UNIT_OUTPUT"
	exit 1
fi

ACTUAL_UNIT_TESTS="$(printf '%s\n' "$UNIT_SUMMARY" | sed -E 's/OK \(([0-9]+) tests, ([0-9]+) assertions\)/\1/')"
ACTUAL_UNIT_ASSERTIONS="$(printf '%s\n' "$UNIT_SUMMARY" | sed -E 's/OK \(([0-9]+) tests, ([0-9]+) assertions\)/\2/')"
ACTUAL_INTEGRATION_METHODS="$(find "$REPO_ROOT/tests/Integration" -type f -name '*Test.php' -exec grep -h -E 'function test' {} + | wc -l | tr -d ' ')"
ACTUAL_UNIT_FILES="$(find "$REPO_ROOT/tests/Unit" -type f -name '*Test.php' | wc -l | tr -d ' ')"
ACTUAL_INTEGRATION_FILES="$(find "$REPO_ROOT/tests/Integration" -type f -name '*.php' | wc -l | tr -d ' ')"
ACTUAL_PROD_LINES="$(find "$REPO_ROOT/includes" "$REPO_ROOT/wp-sudo.php" "$REPO_ROOT/uninstall.php" "$REPO_ROOT/mu-plugin" "$REPO_ROOT/bridges" -type f -name '*.php' -print0 | xargs -0 wc -l | tail -1 | awk '{print $1}')"
ACTUAL_TEST_LINES="$(find "$REPO_ROOT/tests" -type f -name '*.php' -print0 | xargs -0 wc -l | tail -1 | awk '{print $1}')"
ACTUAL_TOTAL_LINES="$(( ACTUAL_PROD_LINES + ACTUAL_TEST_LINES ))"
ACTUAL_RATIO="$(awk -v tests="$ACTUAL_TEST_LINES" -v prod="$ACTUAL_PROD_LINES" 'BEGIN { printf "%.2f", tests / prod }')"
ACTUAL_REPO_PHP="$(find "$REPO_ROOT" -type f -name '*.php' ! -path '*/vendor/*' ! -path '*/vendor_test/*' ! -path '*/.tmp/*' ! -path '*/.git/*' -print0 | xargs -0 wc -l | tail -1 | awk '{print $1}')"

DOC_UNIT_TESTS="$(metric_number 'Unit tests')"
DOC_UNIT_ASSERTIONS="$(metric_number 'Unit assertions')"
DOC_INTEGRATION_METHODS="$(metric_number 'Integration tests in suite')"
DOC_UNIT_FILES="$(metric_number 'Unit test files')"
DOC_INTEGRATION_FILES="$(metric_number 'Integration test files')"
DOC_PROD_LINES="$(metric_number 'Production PHP lines (`includes/`, `wp-sudo.php`, `uninstall.php`, `mu-plugin/`, `bridges/`)')"
DOC_TEST_LINES="$(metric_number 'Tests PHP lines (`tests/`)')"
DOC_TOTAL_LINES="$(metric_number 'Production + tests PHP lines')"
DOC_RATIO="$(metric_number 'Test-to-production ratio')"
DOC_REPO_PHP="$(metric_number 'Total repo PHP lines (excluding `vendor/`, `vendor_test/`, `.tmp/`, `.git/`)')"

FAILURES=""

[ "$DOC_UNIT_TESTS" = "$ACTUAL_UNIT_TESTS" ] || add_failure "Unit tests" "$DOC_UNIT_TESTS" "$ACTUAL_UNIT_TESTS"
[ "$DOC_UNIT_ASSERTIONS" = "$ACTUAL_UNIT_ASSERTIONS" ] || add_failure "Unit assertions" "$DOC_UNIT_ASSERTIONS" "$ACTUAL_UNIT_ASSERTIONS"
[ "$DOC_INTEGRATION_METHODS" = "$ACTUAL_INTEGRATION_METHODS" ] || add_failure "Integration test methods" "$DOC_INTEGRATION_METHODS" "$ACTUAL_INTEGRATION_METHODS"
[ "$DOC_UNIT_FILES" = "$ACTUAL_UNIT_FILES" ] || add_failure "Unit test files" "$DOC_UNIT_FILES" "$ACTUAL_UNIT_FILES"
[ "$DOC_INTEGRATION_FILES" = "$ACTUAL_INTEGRATION_FILES" ] || add_failure "Integration test files" "$DOC_INTEGRATION_FILES" "$ACTUAL_INTEGRATION_FILES"
[ "$DOC_PROD_LINES" = "$ACTUAL_PROD_LINES" ] || add_failure "Production PHP lines" "$DOC_PROD_LINES" "$ACTUAL_PROD_LINES"
[ "$DOC_TEST_LINES" = "$ACTUAL_TEST_LINES" ] || add_failure "Tests PHP lines" "$DOC_TEST_LINES" "$ACTUAL_TEST_LINES"
[ "$DOC_TOTAL_LINES" = "$ACTUAL_TOTAL_LINES" ] || add_failure "Production + tests PHP lines" "$DOC_TOTAL_LINES" "$ACTUAL_TOTAL_LINES"
[ "$DOC_RATIO" = "$ACTUAL_RATIO" ] || add_failure "Test-to-production ratio" "$DOC_RATIO" "$ACTUAL_RATIO"
[ "$DOC_REPO_PHP" = "$ACTUAL_REPO_PHP" ] || add_failure "Total repo PHP lines" "$DOC_REPO_PHP" "$ACTUAL_REPO_PHP"

if [ -n "$FAILURES" ]; then
	echo "Metrics drift detected in docs/current-metrics.md:"
	printf '%s' "$FAILURES"
	echo
	echo "Update docs/current-metrics.md and re-run: composer verify:metrics"
	exit 1
fi

echo "Metrics verified: docs/current-metrics.md is in sync."
