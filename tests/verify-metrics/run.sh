#!/usr/bin/env bash
#
# Regression tests for bin/verify-metrics.sh.
#
# Run: bash tests/verify-metrics/run.sh

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIXTURE_DIR="$REPO_ROOT/node_modules/wp-sudo-metrics-fixture"
FIXTURE_FILE="$FIXTURE_DIR/should-not-count.php"

if [ -e "$FIXTURE_DIR" ]; then
	echo "FATAL: refusing to replace existing fixture path: $FIXTURE_DIR" >&2
	exit 1
fi

cleanup() {
	rm -rf "$FIXTURE_DIR"
}
trap cleanup EXIT

baseline_output="$(cd "$REPO_ROOT" && bash bin/verify-metrics.sh 2>&1)"
baseline_rc=$?
if [ "$baseline_rc" -ne 0 ]; then
	echo "FAIL: baseline metrics verification must pass before fixture injection"
	echo "$baseline_output"
	exit 1
fi

if ! mkdir -p "$FIXTURE_DIR"; then
	echo "FATAL: could not create metrics fixture directory: $FIXTURE_DIR" >&2
	exit 1
fi

if ! cat > "$FIXTURE_FILE" <<'PHP'
<?php
// This PHP fixture represents a transitive npm dependency.
// Its lines must never contribute to the repository's canonical PHP count.
function wp_sudo_metrics_node_fixture(): void {
}
PHP
then
	echo "FATAL: could not write metrics fixture: $FIXTURE_FILE" >&2
	exit 1
fi

fixture_output="$(cd "$REPO_ROOT" && bash bin/verify-metrics.sh 2>&1)"
fixture_rc=$?
if [ "$fixture_rc" -ne 0 ]; then
	echo "FAIL: node_modules PHP fixture changed canonical metrics"
	echo "$fixture_output"
	exit 1
fi

echo "verify-metrics regression passed: node_modules PHP is excluded."
