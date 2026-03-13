#!/usr/bin/env bash

set -eu

EXPECTED_VERSION="${1:-}"

if [ -z "$EXPECTED_VERSION" ]; then
	echo "usage: $0 <expected-version>"
	exit 1
fi

assert_version() {
	local environment="$1"
	local container="$2"
	local actual_version=""

	actual_version="$(npx wp-env run "$container" -- wp core version --allow-root | tail -n 1 | tr -d '\r')"

	if [ "$actual_version" != "$EXPECTED_VERSION" ]; then
		echo "ERROR: $environment wp-env version mismatch. Expected $EXPECTED_VERSION, got $actual_version"
		exit 1
	fi

	echo "$environment wp-env version: $actual_version"
}

assert_version "development" "cli"
assert_version "tests" "tests-cli"
