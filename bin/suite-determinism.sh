#!/usr/bin/env bash
#
# Run the unit suite repeatedly and record what a bare pass/fail line does not.
#
# #460 reports three consecutive reds with three different assertion totals on a
# clean tree, read as evidence that identical tests took different paths. That
# inference does not hold on its own: a failed assertion throws, so the rest of
# that test method never runs, and *which* tests failed changes the total by
# itself. Without the failing test names the totals prove nothing.
#
# So this instruments before diagnosing. Per run it records:
#
#   - the failing test names, not just the counts        (the missing evidence)
#   - the resolved WP_Sudo autoload root                 (the known confounder)
#   - whether PHPUnit actually ran, via its exit status  (see below)
#   - git HEAD and whether the tree was dirty            (was the input identical?)
#   - PHP version
#
# The autoload root is recorded because a symlinked vendor/ makes PHP resolve
# __DIR__ into another checkout, so the suite runs green — or red — about source
# you did not change. That happened twice in this project on 2026-07-27 and is
# the mechanism that reproduces this issue's signature (findings below).
#
# **A run where PHPUnit did not execute is recorded as `error`, never as a pass.**
# An earlier cut of this script derived status purely from whether a failure line
# appeared, so a missing vendor/, a bootstrap fatal, or being run from the wrong
# directory all printed `ok` and summarised as "no failures". For a harness whose
# whole purpose is to break the rerun-until-green habit, manufacturing silent
# greens is the one failure mode it cannot have.
#
# --do-not-cache-result is passed so .phpunit.result.cache cannot influence
# ordering between runs. Note that phpunit.xml.dist sets no executionOrder, and
# the result cache feeds only ORDER_DEFECTS_FIRST, so PHPUnit runs in definition
# order regardless; the flag removes the question rather than answering it.
#
# Usage:
#   bash bin/suite-determinism.sh [runs]      # default 50
#
# Writes one JSON object per line to .tmp/suite-determinism.jsonl (truncated on
# each invocation — copy it if you need to keep a record) and prints a summary.
# Deliberately does NOT stop on first failure: the point is the distribution.

set -uo pipefail

RUNS="${1:-50}"
ROOT="$(git rev-parse --show-toplevel)"

# Everything below uses repo-relative paths. Without this the script "passes"
# from any subdirectory while PHPUnit never runs.
cd "$ROOT" || exit 1

OUT="$ROOT/.tmp/suite-determinism.jsonl"
mkdir -p "$(dirname "$OUT")"
: > "$OUT"

PHP_VERSION="$(php -r 'echo PHP_VERSION;')"
HEAD_SHA="$(git rev-parse HEAD)"

# Test names are namespaced (WP_Sudo\Tests\Unit\...), so backslashes are
# guaranteed, and data-provider keys can carry quotes. Both are invalid raw in
# JSON — and they appear on exactly the runs this harness exists to capture.
json_escape() {
	printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}

# PHP's CLI writes warnings and fatals to STDOUT, so 2>/dev/null does not keep a
# broken bootstrap out of this value — it would inject a stack trace into the
# field whose whole job is to detect that something is wrong.
autoload_root() {
	local out rc
	out="$(php -d display_errors=stderr -r \
		'require "tests/bootstrap.php"; $r = new ReflectionClass("WP_Sudo\Gate"); echo dirname(dirname($r->getFileName()));' \
		2>/dev/null)"
	rc=$?

	if [ "$rc" -ne 0 ] || [ -z "$out" ]; then
		printf '<unresolvable>'
		return
	fi

	printf '%s' "$out"
}

echo "runs=$RUNS  php=$PHP_VERSION  head=${HEAD_SHA:0:9}  root=$ROOT"
echo "writing $OUT"
echo ""

fails=0
errors=0

for i in $(seq 1 "$RUNS"); do
	root_now="$(autoload_root)"

	dirty="clean"
	git diff --quiet || dirty="dirty"
	git diff --cached --quiet || dirty="${dirty}+staged"

	raw="$(./vendor/bin/phpunit --do-not-cache-result --colors=never 2>&1)"
	rc=$?

	tests="$(printf '%s' "$raw" | grep -oE 'Tests: [0-9]+' | tail -1 | grep -oE '[0-9]+')"
	assertions="$(printf '%s' "$raw" | grep -oE 'Assertions: [0-9]+' | tail -1 | grep -oE '[0-9]+')"

	# A green run prints "OK (N tests, M assertions)" instead.
	if [ -z "${tests:-}" ]; then
		tests="$(printf '%s' "$raw" | grep -oE 'OK \([0-9]+ tests' | grep -oE '[0-9]+')"
		assertions="$(printf '%s' "$raw" | grep -oE '[0-9]+ assertions' | tail -1 | grep -oE '[0-9]+')"
	fi

	# The evidence #460 is missing: names, not counts.
	failed="$(printf '%s' "$raw" | grep -E '^[0-9]+\) ' | sed 's/^[0-9]*) //' | paste -sd'|' -)"

	# Status from the exit code first, names second. A zero exit means the run
	# completed, NOT that it ran anything — see the empty-suite branch below. A
	# non-zero code with no failure names means it did not run at all, which is
	# the case that used to read as green.
	if [ "$rc" -eq 0 ] && [ -n "${tests:-}" ]; then
		status="pass"
	elif [ "$rc" -eq 0 ]; then
		# PHPUnit 9.6 exits 0 on an EMPTY suite ("No tests executed!") and
		# failOnEmptyTestSuite is not set in phpunit.xml.dist. Without this branch
		# a suite that stopped matching *Test.php would record a pass with no test
		# count and print "No failures" — the same silent green this script exists
		# to make impossible, and it would falsify the "never a pass" above.
		status="error"
		errors=$((errors + 1))
	elif [ -n "$failed" ]; then
		status="fail"
		fails=$((fails + 1))
	else
		status="error"
		errors=$((errors + 1))
	fi

	printf '{"run":%d,"status":"%s","rc":%d,"tests":"%s","assertions":"%s","autoload_root":"%s","tree":"%s","php":"%s","head":"%s","failed":"%s"}\n' \
		"$i" "$status" "$rc" "${tests:-?}" "${assertions:-?}" \
		"$(json_escape "$root_now")" "$dirty" "$PHP_VERSION" "${HEAD_SHA:0:9}" \
		"$(json_escape "$failed")" >> "$OUT"

	case "$status" in
		pass)
			printf 'run %-3d ok     tests=%s assertions=%s\n' "$i" "${tests:-?}" "${assertions:-?}"
			;;
		fail)
			printf 'run %-3d FAIL   tests=%s assertions=%s\n' "$i" "${tests:-?}" "${assertions:-?}"
			printf '%s\n' "$failed" | tr '|' '\n' | sed 's/^/            /'
			;;
		error)
			printf 'run %-3d ERROR  phpunit exit=%d — it did not run; this is NOT a pass\n' "$i" "$rc"
			printf '%s\n' "$raw" | tail -3 | sed 's/^/            /'
			;;
	esac
done

echo ""
echo "── summary ─────────────────────────────────────────"
echo "runs:            $RUNS"
echo "failing runs:    $fails"
echo "errored runs:    $errors  (phpunit did not run — never counted as passing)"
echo "distinct results:"
grep -oE '"tests":"[^"]*","assertions":"[^"]*"' "$OUT" \
	| sed -e 's/"tests":"//' -e 's/","assertions":"/ tests \/ /' -e 's/"$/ assertions/' \
	| sort | uniq -c | sed 's/^/  /'
echo "distinct autoload roots (more than one, or <unresolvable> = contaminated):"
grep -oE '"autoload_root":"[^"]*"' "$OUT" | sed -e 's/"autoload_root":"//' -e 's/"$//' \
	| sort -u | sed 's/^/  /'

if [ "$fails" -eq 0 ] && [ "$errors" -eq 0 ]; then
	echo ""
	echo "No failures in $RUNS runs. That is evidence about THIS worktree and this"
	echo "machine's current load; it does not by itself refute the original report,"
	echo "which correlated with concurrent activity against a shared checkout."
fi

# ── Findings, 2026-07-27, measured at 4fb8a9f ────────────────────────────────
#
# 43 runs in an isolated worktree (own vendor/, verified autoload root): zero
# failures, 1273 tests / 3812 assertions every time, one autoload root. Those
# counts are as of 4fb8a9f; #471 and #472 have since moved them. The suite is
# deterministic under isolation.
#
# The reported signature was then reproduced deliberately, by pointing a
# worktree's vendor/ at a second checkout and changing that checkout's includes/
# while the suite ran. 8 runs produced 3 greens and 5 reds, with three different
# failure sets and two different assertion totals — on a tree `git status` calls
# clean. That is #460's report: stable test count, varying assertions,
# intermittent green, correlated with concurrent activity.
#
# The assertion totals are consistent with the same explanation. #460 recorded
# 3802 / 3804 / 3808 against a green 3812 — all BELOW it, which is what
# failure-abort produces, since a failed assertion throws and the rest of that
# method never runs. Uniformly-below is therefore expected under failure-abort
# and is not evidence for divergent execution paths. (It does not by itself rule
# divergence out: a skipped branch would also read uniformly-below.)
#
# This is a sufficient mechanism, not a proof of what happened at 4b4f268: a
# symlinked vendor/ leaves no trace after the fact. It is the only hypothesis
# found that produces varying failures on a clean tree, it matches the reported
# correlation, and the same trap caught two agents independently the same day.
