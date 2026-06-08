#!/usr/bin/env bash

set -eu

EXPECTED_VERSION="${1:-}"
WP_ENV_ASSERT_CONTAINERS="${WP_ENV_ASSERT_CONTAINERS:-cli tests-cli}"

if [ -z "$EXPECTED_VERSION" ]; then
	echo "usage: $0 <expected-version>"
	exit 1
fi

run_wp_env_version() {
	local container="$1"

	if command -v npx >/dev/null 2>&1; then
		if [ -n "${WP_ENV_CONFIG_PATH:-}" ]; then
			npx wp-env --config "$WP_ENV_CONFIG_PATH" run "$container" -- wp core version --allow-root
		else
			npx wp-env run "$container" -- wp core version --allow-root
		fi
		return
	fi

	if command -v wp-env >/dev/null 2>&1; then
		if [ -n "${WP_ENV_CONFIG_PATH:-}" ]; then
			wp-env --config "$WP_ENV_CONFIG_PATH" run "$container" -- wp core version --allow-root
		else
			wp-env run "$container" -- wp core version --allow-root
		fi
		return
	fi

	echo "ERROR: neither npx nor wp-env is available on PATH"
	exit 1
}

assert_version() {
	local container="$1"
	local actual_version=""

	actual_version="$(run_wp_env_version "$container" | tail -n 1 | tr -d '\r')"

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
