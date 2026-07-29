#!/usr/bin/env bash
#
# Black-box regression tests for bin/docs-lint.sh.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/bin/docs-lint.sh"

if [ ! -f "$SCRIPT" ]; then
	echo "FATAL: cannot find $SCRIPT" >&2
	exit 1
fi

TMP_ROOT="$(mktemp -d)"
trap 'rm -rf "$TMP_ROOT"' EXIT

pass=0
fail=0
CURRENT=""

new_repo() {
	REPO="$TMP_ROOT/$1"
	mkdir -p "$REPO/bin"
	cp "$SCRIPT" "$REPO/bin/docs-lint.sh"
	git -C "$REPO" init -q
}

track() {
	local path="$1" content="${2-plain prose}"
	mkdir -p "$(dirname "$REPO/$path")"
	printf '%s\n' "$content" > "$REPO/$path"
	git -C "$REPO" add "$path"
}

run_lint() {
	OUT="$(cd "$REPO/docs" 2>/dev/null || cd "$REPO"; bash "$REPO/bin/docs-lint.sh" 2>&1)"
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

CURRENT="clean tracked markdown passes from a subdirectory"
new_repo clean
track docs/guide.md "No volatile facts."
run_lint
expect_rc 0
expect_out "Documentation lint passed"

CURRENT="date and count failures are both diagnosed"
new_repo failures
track docs/guide.md "Checked 2026-07-29 with 42 tests."
run_lint
expect_rc 1
expect_out "Stale fixed-date references"
expect_out "Old count patterns"
expect_out "docs/guide.md"

CURRENT="date-only and count-only failures are independent"
new_repo independent
track docs/date.md "Checked 2026-07-29."
track docs/count.md "There are 42 assertions."
run_lint
expect_rc 1
expect_out "docs/date.md"
expect_out "docs/count.md"

CURRENT="canonical and path exclusions remain excluded"
new_repo excluded
track docs/current-metrics.md "2026-07-29 and 42 tests"
track docs/archive/history.md "2026-07-29 and 42 tests"
track tests/fixture.md "2026-07-29 and 42 tests"
track .planning/plan.md "2026-07-29 and 42 tests"
track .github/skills/example/fixture.md "2026-07-29 and 42 tests"
track .github/php82-tests/fixture.md "2026-07-29 and 42 tests"
run_lint
expect_rc 0

CURRENT="near-match exclusions are still scanned"
new_repo nearmatch
track docs/current-metrics-copy.md "Checked 2026-07-29."
run_lint
expect_rc 1
expect_out "docs/current-metrics-copy.md"

CURRENT="tracked working-tree edits are scanned"
new_repo modified
track docs/guide.md "No volatile facts."
printf '%s\n' "Now says 42 tests." > "$REPO/docs/guide.md"
run_lint
expect_rc 1
expect_out "docs/guide.md"

CURRENT="untracked markdown and tracked non-markdown are ignored"
new_repo ignored
track notes.txt "Checked 2026-07-29 with 42 tests."
printf '%s\n' "Checked 2026-07-29 with 42 tests." > "$REPO/untracked.md"
run_lint
expect_rc 0

CURRENT="empty eligible set succeeds"
new_repo empty
track docs/archive/history.md "Checked 2026-07-29."
run_lint
expect_rc 0
expect_out "No markdown files to lint"

CURRENT="tracked paths containing spaces are scanned"
new_repo spaces
track "docs/guide with spaces.md" "Checked 2026-07-29."
run_lint
expect_rc 1
expect_out "docs/guide with spaces.md"

printf 'docs-lint regression tests: %d passed, %d failed\n' "$pass" "$fail"
test "$fail" -eq 0
