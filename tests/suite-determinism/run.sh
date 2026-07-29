#!/usr/bin/env bash
#
# Black-box regression tests for bin/suite-determinism.sh.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/bin/suite-determinism.sh"
TMP_ROOT="$(mktemp -d)"
trap 'rm -rf "$TMP_ROOT"' EXIT

pass=0
fail=0
CURRENT=""

new_repo() {
	REPO="$TMP_ROOT/$1"
	mkdir -p "$REPO/bin" "$REPO/includes" "$REPO/tests" "$REPO/vendor/bin"
	cp "$SCRIPT" "$REPO/bin/suite-determinism.sh"
	cat > "$REPO/includes/class-gate.php" <<'PHP'
<?php
namespace WP_Sudo;
class Gate {}
PHP
	cat > "$REPO/tests/bootstrap.php" <<'PHP'
<?php
require dirname( __DIR__ ) . '/includes/class-gate.php';
PHP
	cat > "$REPO/vendor/bin/phpunit" <<'FAKE'
#!/usr/bin/env bash
set -u
counter=".git/fake-count.${FAKE_COUNTER_ID:-shared}"
n=0
[ ! -f "$counter" ] || n="$(cat "$counter")"
n=$((n + 1))
printf '%s' "$n" > "$counter"

case "${FAKE_SCENARIO:-pass}" in
	pass)
		printf 'OK (2 tests, 3 assertions)\n'
		exit 0
		;;
	fail-first)
		if [ "$n" -eq 1 ]; then
			printf '1) WP_Sudo\\Tests\\Unit\\OddTest::test_\"quoted\"_café\nTests: 2, Assertions: 2, Failures: 1.\n'
			exit 1
		fi
		printf 'OK (2 tests, 3 assertions)\n'
		exit 0
		;;
	empty)
		printf 'No tests executed!\n'
		exit 0
		;;
	infra)
		printf 'bootstrap says \"broken\"\\path\nsecond diagnostic line\n'
		exit 2
		;;
	invalid-utf8)
		printf '1) WP_Sudo\\Tests\\Unit\\BinaryTest::test_invalid_'
		printf '\377'
		printf '\nTests: 2, Assertions: 1, Failures: 1.\n'
		exit 1
		;;
	change-head)
		printf '\n// changed\n' >> includes/class-gate.php
		git add includes/class-gate.php
		git commit -qm 'fixture mutation'
		printf 'OK (2 tests, 3 assertions)\n'
		exit 0
		;;
	dirty-after)
		printf '\n// dirty\n' >> includes/class-gate.php
		printf 'new input\n' > tests/NewTest.php
		printf 'OK (2 tests, 3 assertions)\n'
		exit 0
		;;
	dirty-existing)
		printf '\n// changed again\n' >> includes/class-gate.php
		printf 'changed again\n' >> tests/ExistingUntracked.php
		printf 'OK (2 tests, 3 assertions)\n'
		exit 0
		;;
esac
FAKE
	chmod +x "$REPO/vendor/bin/phpunit"
	git -C "$REPO" init -q
	git -C "$REPO" config user.name "Fixture"
	git -C "$REPO" config user.email "fixture@example.test"
	git -C "$REPO" add .
	git -C "$REPO" commit -qm baseline
}

run_harness() {
	local scenario="${1:-pass}"
	shift
	OUT="$(cd "$REPO" && FAKE_SCENARIO="$scenario" bash bin/suite-determinism.sh "$@" 2>&1)"
	RC=$?
	JSONL="$(printf '%s\n' "$OUT" | sed -n 's/^writing //p' | tail -1)"
}

expect_rc() {
	if [ "$RC" = "$1" ]; then
		pass=$((pass + 1))
	else
		fail=$((fail + 1))
		printf 'FAIL: %s (expected exit %s, got %s)\n%s\n' "$CURRENT" "$1" "$RC" "$OUT"
	fi
}

expect() {
	if "$@"; then
		pass=$((pass + 1))
	else
		fail=$((fail + 1))
		printf 'FAIL: %s (%s)\n%s\n' "$CURRENT" "$*" "$OUT"
	fi
}

json_assert() {
	php -r '$rows=file($argv[1], FILE_IGNORE_NEW_LINES); foreach($rows as $row){json_decode($row,true,512,JSON_THROW_ON_ERROR);} if(count($rows)!==(int)$argv[2]) exit(1);' "$JSONL" "$1"
}

CURRENT="invalid and impractical run counts fail before invoking PHPUnit"
for bad in '' 0 -1 nope ' 2' 1.5 10001 18446744073709551616; do
	new_repo "invalid-${bad//[^a-zA-Z0-9]/x}"
	run_harness pass "$bad"
	expect_rc 2
	expect test ! -f "$REPO/.git/fake-count.shared"
done

CURRENT="output initialization failure stops before PHPUnit"
new_repo nooutput
rm -rf "$REPO/.tmp"
printf 'not a directory\n' > "$REPO/.tmp"
run_harness pass 1
expect_rc 2
expect test ! -f "$REPO/.git/fake-count.shared"

CURRENT="all requested runs execute and failures make the command fail"
new_repo failures
run_harness fail-first 3
expect_rc 1
expect test "$(cat "$REPO/.git/fake-count.shared")" = 3
expect json_assert 3
expect grep -q '"status":"fail"' "$JSONL"
expect grep -q 'OddTest' "$JSONL"

CURRENT="empty and infrastructure outputs are errors"
new_repo empty
run_harness empty 2
expect_rc 1
expect json_assert 2
expect grep -q '"status":"error"' "$JSONL"
expect php -r '$r=json_decode(file($argv[1])[0],true); exit(null===$r["tests"]&&null===$r["assertions"]?0:1);' "$JSONL"
new_repo infra
run_harness infra 1
expect_rc 1
expect json_assert 1

CURRENT="foreign and unresolvable autoload roots fail"
new_repo foreign
foreign_root="$TMP_ROOT/foreign-source"
mkdir -p "$foreign_root/includes"
cp "$REPO/includes/class-gate.php" "$foreign_root/includes/class-gate.php"
printf "<?php\nrequire '%s/includes/class-gate.php';\n" "$foreign_root" > "$REPO/tests/bootstrap.php"
run_harness pass 1
expect_rc 1
new_repo local-wrong
mkdir -p "$REPO/alternate"
cp "$REPO/includes/class-gate.php" "$REPO/alternate/class-gate.php"
printf '%s\n' "<?php require dirname( __DIR__ ) . '/alternate/class-gate.php';" > "$REPO/tests/bootstrap.php"
run_harness pass 1
expect_rc 1
new_repo unresolved
printf '%s\n' '<?php exit(2);' > "$REPO/tests/bootstrap.php"
run_harness pass 1
expect_rc 1

CURRENT="a symlinked vendor fails before the sample even when Gate resolves locally"
new_repo symlinked-vendor
mv "$REPO/vendor" "$REPO/vendor-real"
ln -s vendor-real "$REPO/vendor"
run_harness pass 1
expect_rc 2
expect test ! -f "$REPO/.tmp/fake-count.shared"

CURRENT="malformed UTF-8 diagnostics remain valid JSON evidence"
new_repo invalid-utf8
run_harness invalid-utf8 2
expect_rc 1
expect json_assert 2
expect grep -q '"status":"fail"' "$JSONL"
expect php -r '$r=json_decode(file($argv[1])[0],true,512,JSON_THROW_ON_ERROR); exit(str_contains($r["diagnostic"], "\u{FFFD}")?0:1);' "$JSONL"

CURRENT="revision changes and post-run dirty state fail the sample"
new_repo changed
run_harness change-head 2
expect_rc 1
expect json_assert 2
expect php -r '$r=json_decode(file($argv[1])[0],true); exit($r["head_before"]!==$r["head_after"]?0:1);' "$JSONL"
new_repo dirty
run_harness dirty-after 1
expect_rc 1
expect php -r '$r=json_decode(file($argv[1])[0],true); exit($r["tracked_dirty_after"]&&$r["untracked_dirty_after"]&&$r["worktree_changed"]?0:1);' "$JSONL"
new_repo dirty-existing
printf '\n// already dirty\n' >> "$REPO/includes/class-gate.php"
printf 'already untracked\n' > "$REPO/tests/ExistingUntracked.php"
run_harness dirty-existing 1
expect_rc 1
expect php -r '$r=json_decode(file($argv[1])[0],true); exit($r["worktree_changed"]?0:1);' "$JSONL"

CURRENT="unreadable repository state fails before PHPUnit"
new_repo unreadable-status
real_git="$(command -v git)"
mkdir -p "$REPO/fake-bin"
cat > "$REPO/fake-bin/git" <<'FAKE_GIT'
#!/usr/bin/env bash
if [ "$1" = "status" ]; then
	exit 1
fi
exec "$REAL_GIT" "$@"
FAKE_GIT
chmod +x "$REPO/fake-bin/git"
set +e
OUT="$(
	cd "$REPO" &&
		REAL_GIT="$real_git" PATH="$REPO/fake-bin:$PATH" \
		FAKE_SCENARIO=pass bash bin/suite-determinism.sh 1 2>&1
)"
RC=$?
set -e
expect_rc 2
expect test ! -f "$REPO/.git/fake-count.shared"

CURRENT="all iterations are pinned to one baseline revision"
new_repo between-runs
real_git="$(command -v git)"
baseline_head="$(git -C "$REPO" rev-parse HEAD)"
mkdir -p "$REPO/fake-bin"
cat > "$REPO/fake-bin/git" <<'FAKE_GIT'
#!/usr/bin/env bash
if [ "$#" -eq 2 ] && [ "$1" = "rev-parse" ] && [ "$2" = "HEAD" ]; then
	count_file=".tmp/fake-git-head-count"
	count=0
	[ ! -f "$count_file" ] || count="$(cat "$count_file")"
	count=$((count + 1))
	printf '%s' "$count" > "$count_file"
	if [ "$count" -le 2 ]; then
		printf '%s\n' "$FAKE_BASELINE_HEAD"
	else
		printf '%040d\n' 0
	fi
	exit 0
fi
exec "$REAL_GIT" "$@"
FAKE_GIT
chmod +x "$REPO/fake-bin/git"
set +e
OUT="$(
	cd "$REPO" &&
		REAL_GIT="$real_git" FAKE_BASELINE_HEAD="$baseline_head" PATH="$REPO/fake-bin:$PATH" \
		FAKE_SCENARIO=pass bash bin/suite-determinism.sh 2 2>&1
)"
RC=$?
set -e
JSONL="$(printf '%s\n' "$OUT" | sed -n 's/^writing //p' | tail -1)"
expect_rc 1
expect json_assert 2
expect php -r '$r=json_decode(file($argv[1])[1],true); exit($r["revision_changed"]?0:1);' "$JSONL"

CURRENT="concurrent invocations use distinct complete output files"
new_repo concurrent
( cd "$REPO" && FAKE_SCENARIO=pass FAKE_COUNTER_ID=a bash bin/suite-determinism.sh 2 > "$TMP_ROOT/out-a" 2>&1 ) &
pid_a=$!
( cd "$REPO" && FAKE_SCENARIO=pass FAKE_COUNTER_ID=b bash bin/suite-determinism.sh 2 > "$TMP_ROOT/out-b" 2>&1 ) &
pid_b=$!
wait "$pid_a"; rc_a=$?
wait "$pid_b"; rc_b=$?
path_a="$(sed -n 's/^writing //p' "$TMP_ROOT/out-a" | tail -1)"
path_b="$(sed -n 's/^writing //p' "$TMP_ROOT/out-b" | tail -1)"
OUT="$(cat "$TMP_ROOT/out-a" "$TMP_ROOT/out-b")"
expect test "$rc_a" -eq 0
expect test "$rc_b" -eq 0
expect test "$path_a" != "$path_b"
JSONL="$path_a"; expect json_assert 2
JSONL="$path_b"; expect json_assert 2

CURRENT="successful sample wording stays bounded"
new_repo success
run_harness pass 2
expect_rc 0
expect php -r '$r=json_decode(file($argv[1])[0],true); exit(2===$r["tests"]&&3===$r["assertions"]?0:1);' "$JSONL"
expect grep -q "observed 2-run sample passed" <(printf '%s' "$OUT")
expect sh -c "! printf '%s' \"\$1\" | grep -qi 'suite is deterministic'" sh "$OUT"

CURRENT="the harness is registered in Composer and required CI"
output=""
status=0
grep -q '"test:suite-determinism": "bash tests/suite-determinism/run.sh"' "$ROOT/composer.json" || status=1
expect test "$status" -eq 0
status=0
grep -q 'composer test:suite-determinism' "$ROOT/.github/workflows/phpunit.yml" || status=1
expect test "$status" -eq 0

printf 'suite-determinism regression tests: %d passed, %d failed\n' "$pass" "$fail"
test "$fail" -eq 0
