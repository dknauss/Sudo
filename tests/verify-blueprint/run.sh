#!/usr/bin/env bash
#
# Black-box regression tests for bin/verify-blueprint.sh.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/bin/verify-blueprint.sh"

if [ ! -f "$SCRIPT" ]; then
	echo "FATAL: cannot find $SCRIPT" >&2
	exit 1
fi

TMP_ROOT="$(mktemp -d)"
trap 'rm -rf "$TMP_ROOT"' EXIT

pass=0
fail=0
CURRENT=""

run_fixture() {
	local name="$1" json="$2"
	FIXTURE="$TMP_ROOT/$name.json"
	printf '%s\n' "$json" > "$FIXTURE"
	OUT="$(bash "$SCRIPT" "$FIXTURE" 2>&1)"
	RC=$?
}

expect_rc() {
	if [ "$RC" = "$1" ]; then
		pass=$((pass + 1))
	else
		fail=$((fail + 1))
		printf 'FAIL: %s (expected exit %s, got %s)\n%s\n' "$CURRENT" "$1" "$RC" "$OUT"
	fi
}

expect_out() {
	if printf '%s' "$OUT" | grep -qF -- "$1"; then
		pass=$((pass + 1))
	else
		fail=$((fail + 1))
		printf 'FAIL: %s (missing output: %s)\n%s\n' "$CURRENT" "$1" "$OUT"
	fi
}

valid='{"steps":[
  {"step":"installPlugin","pluginData":{"resource":"url","url":"https://wordpress-playground-cors-proxy.net/?https://github.com/dknauss/Sudo/releases/download/v4.9.2/wp-sudo.zip"}},
  {"step":"installPlugin","pluginData":{"resource":"wordpress.org/plugins","slug":"two-factor"}}
]}'

CURRENT="published release asset passes"
run_fixture valid "$valid"
expect_rc 0
expect_out "Stable blueprint install source verified"

CURRENT="tag source archive is rejected"
run_fixture tag '{"steps":[{"step":"installPlugin","pluginData":{"resource":"url","url":"https://wordpress-playground-cors-proxy.net/?https://github.com/dknauss/Sudo/archive/refs/tags/v4.9.2.zip"}}]}'
expect_rc 1
expect_out "GitHub Release asset"

CURRENT="direct release URL without the CORS proxy is rejected"
run_fixture proxy '{"steps":[{"step":"installPlugin","pluginData":{"resource":"url","url":"https://github.com/dknauss/Sudo/releases/download/v4.9.2/wp-sudo.zip"}}]}'
expect_rc 1
expect_out "CORS proxy"

CURRENT="first-party install must use the URL resource"
run_fixture resource '{"steps":[{"step":"installPlugin","pluginData":{"resource":"literal","url":"https://wordpress-playground-cors-proxy.net/?https://github.com/dknauss/Sudo/releases/download/v4.9.2/wp-sudo.zip"}}]}'
expect_rc 1
expect_out "resource=url"

CURRENT="another repository release asset is rejected"
run_fixture repo '{"steps":[{"step":"installPlugin","pluginData":{"resource":"url","url":"https://wordpress-playground-cors-proxy.net/?https://github.com/example/Sudo/releases/download/v4.9.2/wp-sudo.zip"}}]}'
expect_rc 1
expect_out "dknauss/Sudo"

CURRENT="missing first-party install step is rejected"
run_fixture missing '{"steps":[{"step":"installPlugin","pluginData":{"resource":"wordpress.org/plugins","slug":"two-factor"}}]}'
expect_rc 1
expect_out "exactly one first-party"

CURRENT="multiple first-party install steps are rejected"
run_fixture multiple '{"steps":[
  {"step":"installPlugin","pluginData":{"resource":"url","url":"https://wordpress-playground-cors-proxy.net/?https://github.com/dknauss/Sudo/releases/download/v4.9.2/wp-sudo.zip"}},
  {"step":"installPlugin","pluginData":{"resource":"url","url":"https://wordpress-playground-cors-proxy.net/?https://github.com/dknauss/Sudo/releases/download/v4.9.1/wp-sudo.zip"}}
]}'
expect_rc 1
expect_out "exactly one first-party"

CURRENT="malformed JSON is rejected"
run_fixture malformed '{"steps":['
expect_rc 1
expect_out "valid JSON"

printf 'blueprint verifier tests: %d passed, %d failed\n' "$pass" "$fail"
test "$fail" -eq 0
