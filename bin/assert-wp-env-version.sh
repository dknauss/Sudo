#!/usr/bin/env bash

set -eu

EXPECTED_VERSION="${1:-}"
WP_ENV_ARGS=()
WP_ENV_ASSERT_CONTAINERS="${WP_ENV_ASSERT_CONTAINERS:-cli tests-cli}"

if [ -z "$EXPECTED_VERSION" ]; then
	echo "usage: $0 <expected-version>"
	exit 1
fi

if [ -n "${WP_ENV_CONFIG_PATH:-}" ]; then
	WP_ENV_ARGS=( "--config" "$WP_ENV_CONFIG_PATH" )
fi

assert_version() {
	local container="$1"
	local actual_version=""

	actual_version="$(npx wp-env "${WP_ENV_ARGS[@]}" run "$container" -- wp core version --allow-root | tail -n 1 | tr -d '\r')"

	if [ "$actual_version" != "$EXPECTED_VERSION" ]; then
		echo "ERROR: $container wp-env version mismatch. Expected $EXPECTED_VERSION, got $actual_version"
		exit 1
	fi

	echo "$container wp-env version: $actual_version"
}

read -r -a containers <<< "$WP_ENV_ASSERT_CONTAINERS"

for container in "${containers[@]}"; do
	assert_version "$container"
done
