#!/usr/bin/env bash
#
# Black-box regression tests for the staged-tree reviewer gate.

set -u

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
TMP_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/wp-sudo-reviewer-gate.XXXXXX")
trap 'rm -rf "$TMP_ROOT"' EXIT

passed=0
failed=0
case_number=0
output=""
status=0

pass() {
	passed=$((passed + 1))
}

fail() {
	printf 'not ok %d - %s\n' "$case_number" "$1"
	printf '%s\n' "$output" | sed 's/^/  /'
	failed=$((failed + 1))
}

expect_status() {
	local expected=$1
	local description=$2

	case_number=$((case_number + 1))
	if [ "$status" -eq "$expected" ]; then
		printf 'ok %d - %s\n' "$case_number" "$description"
		pass
	else
		fail "$description (expected status $expected, got $status)"
	fi
}

expect_output() {
	local needle=$1
	local description=$2

	case_number=$((case_number + 1))
	if printf '%s\n' "$output" | grep -qF -- "$needle"; then
		printf 'ok %d - %s\n' "$case_number" "$description"
		pass
	else
		fail "$description (missing: $needle)"
	fi
}

new_repo() {
	local name=$1
	local repo="$TMP_ROOT/$name"

	mkdir -p "$repo/.githooks" "$repo/bin"
	cp "$ROOT/.githooks/pre-commit" "$repo/.githooks/pre-commit"
	cp "$ROOT/bin/reviewer-approve.sh" "$repo/bin/reviewer-approve.sh"
	chmod +x "$repo/.githooks/pre-commit" "$repo/bin/reviewer-approve.sh"

	git -C "$repo" init -q
	git -C "$repo" config user.name "Reviewer Gate Test"
	git -C "$repo" config user.email "reviewer-gate@example.invalid"
	printf 'reviewer-approved\n' > "$repo/.gitignore"
	printf 'seed\n' > "$repo/README.md"
	git -C "$repo" add .gitignore README.md .githooks/pre-commit bin/reviewer-approve.sh
	git -C "$repo" commit -qm "test: seed repository"

	cat > "$repo/.reviewer-config.sh" <<'EOF'
REVIEWER_TEST_CMD=":"
REVIEWER_LINT_CMD=":"
REVIEWER_BUILD_CMD=":"
REVIEWER_E2E_CMD=""
REVIEWER_MAX_FILES=1
REVIEWER_MAX_INSERTIONS=20
REVIEWER_APPROVAL_TIMEOUT=1800
REVIEWER_APPROVAL_FILE="reviewer-approved"
REVIEWER_TEXT_ONLY_PATTERN='\.(md|txt|rst)$'
REVIEWER_EXCLUDED_FILES='^package-lock\.json$'
EOF

	printf '%s\n' "$repo"
}

run_in() {
	local repo=$1
	shift

	set +e
	output=$(cd "$repo" && "$@" 2>&1)
	status=$?
	set -e
}

set -e

mode=$(git -C "$ROOT" ls-files -s .githooks/pre-commit | awk '{print $1}')
status=0
output="index mode: $mode"
[ "$mode" = "100755" ] || status=1
expect_status 0 "pre-commit hook is executable in the index"

repo=$(new_repo empty-index)
run_in "$repo" bash bin/reviewer-approve.sh
expect_status 1 "approval refuses an empty index"
expect_output "nothing is staged" "empty-index refusal explains the problem"

repo=$(new_repo valid-approval)
printf '<?php\n' > "$repo/change.php"
git -C "$repo" add change.php
run_in "$repo" bash bin/reviewer-approve.sh
expect_status 0 "approval binds to a staged tree"
run_in "$repo" bash .githooks/pre-commit
expect_status 0 "matching staged-tree approval passes the hook"
status=0
output="approval flag still exists"
[ ! -e "$repo/reviewer-approved" ] || status=1
expect_status 0 "successful hook consumes the approval flag"

repo=$(new_repo changed-tree)
printf '<?php\n' > "$repo/change.php"
git -C "$repo" add change.php
(cd "$repo" && bash bin/reviewer-approve.sh >/dev/null)
printf '<?php\n// changed\n' > "$repo/change.php"
git -C "$repo" add change.php
run_in "$repo" bash .githooks/pre-commit
expect_status 1 "restaging changed content invalidates approval"
expect_output "Staged content changed since approval" "tree mismatch is reported"

repo=$(new_repo legacy-flag)
printf '<?php\n' > "$repo/change.php"
git -C "$repo" add change.php
date +%s > "$repo/reviewer-approved"
run_in "$repo" bash .githooks/pre-commit
expect_status 1 "legacy timestamp-only approval is rejected"
expect_output "no content binding" "legacy flag failure names the missing binding"

repo=$(new_repo docs-only)
printf 'docs\n' > "$repo/change.md"
git -C "$repo" add change.md
printf 'stale\nflag\n' > "$repo/reviewer-approved"
run_in "$repo" bash .githooks/pre-commit
expect_status 0 "docs-only staged content skips reviewer approval"
status=0
output="approval flag still exists"
[ ! -e "$repo/reviewer-approved" ] || status=1
expect_status 0 "docs-only path clears a leftover approval flag"

repo=$(new_repo user-bypass)
printf '<?php\n' > "$repo/change.php"
git -C "$repo" add change.php
run_in "$repo" env USER_COMMIT=1 bash .githooks/pre-commit
expect_status 0 "USER_COMMIT bypass remains available"
expect_output "User commit bypass enabled" "bypass is visible in hook output"

repo=$(new_repo vendor-symlink)
mkdir -p "$TMP_ROOT/foreign-vendor"
ln -s "$TMP_ROOT/foreign-vendor" "$repo/vendor"
printf '<?php\n' > "$repo/change.php"
git -C "$repo" add change.php
run_in "$repo" env USER_COMMIT=1 bash .githooks/pre-commit
expect_status 1 "foreign vendor symlink is rejected"
expect_output "vendor/ does not belong to this worktree" "vendor failure explains the isolation breach"

repo=$(new_repo merge-size)
printf '<?php\n' > "$repo/one.php"
printf '<?php\n' > "$repo/two.php"
git -C "$repo" add one.php two.php
(cd "$repo" && bash bin/reviewer-approve.sh >/dev/null)
git -C "$repo" rev-parse HEAD > "$repo/.git/MERGE_HEAD"
run_in "$repo" bash .githooks/pre-commit
expect_status 0 "merge path skips only the AI size limit"
expect_output "Merge commit" "merge-size exemption is reported"
expect_output "Reviewer agent approved" "merge path still requires staged-tree approval"

printf '\nreviewer-gate tests: %d passed, %d failed\n' "$passed" "$failed"
[ "$failed" -eq 0 ]
