#!/usr/bin/env bash
#
# Write the reviewer approval flag, bound to the exact staged content.
#
# Run this from the reviewer agent AFTER deciding APPROVE, instead of writing
# `reviewer-approved` by hand. The flag is two lines — a unix timestamp and the
# id of the staged tree — and the pre-commit hook rejects a flag whose tree does
# not match what is being committed.
#
# The binding is the point. A timestamp answers "was something approved
# recently"; it cannot answer "was THIS approved". Any edit between approval and
# commit previously rode in unreviewed behind a flag that still looked valid.
#
# Writing the file by hand with a bare timestamp is now rejected, deliberately:
# a silently-degraded binding would be worse than none, because it would read as
# enforcement.
#
# Usage:  bash bin/reviewer-approve.sh
# Exit:   0 written, 1 refused (with the reason on stderr)

set -euo pipefail

REPO_ROOT=$(git rev-parse --show-toplevel)
CONFIG_FILE="$REPO_ROOT/.reviewer-config.sh"

REVIEWER_APPROVAL_FILE="reviewer-approved"
if [ -f "$CONFIG_FILE" ]; then
	# shellcheck source=/dev/null
	source "$CONFIG_FILE"
fi

APPROVAL_FILE="$REPO_ROOT/$REVIEWER_APPROVAL_FILE"

# Refuse to approve nothing. An empty index means the reviewer looked at the
# working tree rather than at what would be committed — the two differ, and the
# hook compares against the index.
if git diff --cached --quiet; then
	echo "refusing to approve: nothing is staged." >&2
	echo "Stage the change first; the hook binds approval to the staged tree." >&2
	exit 1
fi

# `git write-tree` is the id of the current index. It does not move HEAD or touch
# the worktree. It does rewrite `.git/index` when the cache-tree extension is
# missing or stale — git persists that extension — but the staged content is
# unchanged and `git status` is unaffected. Stated to match the hook's own note
# rather than contradict it. Fails on unmerged entries, which is the correct time
# to refuse.
if ! STAGED_TREE=$(git write-tree 2>/dev/null); then
	echo "refusing to approve: git write-tree failed (unmerged index entries?)." >&2
	exit 1
fi

printf '%s\n%s\n' "$(date +%s)" "$STAGED_TREE" > "$APPROVAL_FILE"

echo "Approval written for staged tree $STAGED_TREE"
echo "Any change to the staged content invalidates it."
