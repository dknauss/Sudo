#!/usr/bin/env bash
# Bring up the wp-env Docker stack for CI, resiliently.
#
# `wp-env start` boots a Docker WordPress+DB stack (image pulls, container boot,
# WP install). A transient Docker/registry stall can leave it hanging with no
# output; unbounded, that hang consumes the entire job timeout (~30 min) and the
# run dies with no useful signal. Playwright's own retries do not help — they
# re-run tests, not the environment bring-up.
#
# This wrapper:
#   1. bounds each `wp-env start` attempt with a hard timeout,
#   2. tears the partial stack down and retries from a clean slate on failure,
#   3. verifies the site actually answers HTTP 200 before returning,
# so a stalled boot fails fast and self-heals instead of eating the job, and
# downstream test steps never start against a half-booted stack.
#
# Usage: bin/wp-env-up.sh [config-path] [ready-url]
# Tunables (env): WP_ENV_BIN (default "npx wp-env"; set "wp-env" when the tool
#                 is installed globally), WP_ENV_START_ATTEMPTS,
#                 WP_ENV_START_TIMEOUT, WP_ENV_READY_TRIES, WP_ENV_READY_SLEEP
set -uo pipefail

CONFIG="${1:-.wp-env.e2e.json}"
READY_URL="${2:-http://localhost:8889/wp-login.php}"
ATTEMPTS="${WP_ENV_START_ATTEMPTS:-3}"
ATTEMPT_TIMEOUT="${WP_ENV_START_TIMEOUT:-360}"   # seconds per start attempt
READY_TRIES="${WP_ENV_READY_TRIES:-30}"          # readiness polls
READY_SLEEP="${WP_ENV_READY_SLEEP:-2}"           # seconds between polls

# wp-env invocation, split into an array so a two-word default ("npx wp-env")
# and a one-word global install ("wp-env") both expand safely.
read -r -a WP_ENV <<< "${WP_ENV_BIN:-npx wp-env}"

ready() {
	local i code
	for i in $(seq 1 "${READY_TRIES}"); do
		code="$(curl -s -o /dev/null -w '%{http_code}' "${READY_URL}" || true)"
		if [ "${code}" = "200" ]; then
			echo "WordPress is ready (${READY_URL})"
			return 0
		fi
		echo "Waiting for WordPress... (poll ${i}/${READY_TRIES}, last=${code:-none})"
		sleep "${READY_SLEEP}"
	done
	return 1
}

for attempt in $(seq 1 "${ATTEMPTS}"); do
	echo "::group::wp-env start (attempt ${attempt}/${ATTEMPTS}, ${ATTEMPT_TIMEOUT}s cap)"
	timeout -k 30 "${ATTEMPT_TIMEOUT}" "${WP_ENV[@]}" start --config "${CONFIG}"
	start_status=$?
	echo "::endgroup::"

	if [ "${start_status}" -eq 0 ] && ready; then
		echo "wp-env up on attempt ${attempt}"
		exit 0
	fi

	echo "::warning::wp-env not ready on attempt ${attempt} (start exit ${start_status})"
	if [ "${attempt}" -lt "${ATTEMPTS}" ]; then
		echo "::group::docker state + wp-env destroy (cleanup before retry)"
		docker ps -a || true
		printf 'y\n' | timeout 120 "${WP_ENV[@]}" destroy --config "${CONFIG}" || true
		echo "::endgroup::"
	fi
done

echo "::error::wp-env failed to come up after ${ATTEMPTS} attempts" >&2
exit 1
