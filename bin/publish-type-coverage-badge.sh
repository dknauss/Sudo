#!/usr/bin/env bash

# Computes the Psalm type-coverage percentage and publishes a self-hosted
# shields.io "endpoint" badge (coverage.json) to the dedicated `badges` branch.
#
# Psalm prints "Psalm was able to infer types for X% of the codebase" in its
# normal console output (Psalm 6.16: IssueBuffer.php prints the summary whenever
# timing is shown; Analyzer.php computes it as 100 * nonmixed / (mixed + nonmixed)
# — the same numerator/denominator the experimental Shepherd payload used). We
# grep that line rather than depend on shepherd.dev.
#
# Usage:
#   bin/publish-type-coverage-badge.sh                # write coverage.json + push to `badges`
#   bin/publish-type-coverage-badge.sh --no-publish   # write coverage.json only (no git push)
#   PSALM_OUTPUT_FILE=psalm.txt bin/publish-type-coverage-badge.sh
#       parse a captured Psalm log instead of re-running Psalm (for local testing)

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

BADGE_FILE="coverage.json"
BADGE_BRANCH="badges"
PUBLISH=1

for arg in "$@"; do
	case "$arg" in
		--no-publish) PUBLISH=0 ;;
		*) echo "Unknown argument: $arg" >&2; exit 2 ;;
	esac
done

# Obtain Psalm's console output. PSALM_OUTPUT_FILE lets a caller (or test) supply
# a pre-captured log; otherwise run the same command as the analyse:psalm gate.
# The summary line is printed regardless of exit status, so do not abort on a
# nonzero exit (errors above baseline are the gate's concern, not the badge's).
if [ -n "${PSALM_OUTPUT_FILE:-}" ]; then
	psalm_output="$(cat "$PSALM_OUTPUT_FILE")"
else
	psalm_output="$(composer analyse:psalm 2>&1 || true)"
fi

coverage="$(printf '%s\n' "$psalm_output" \
	| grep -oE 'infer types for [0-9]+(\.[0-9]+)?%' \
	| grep -oE '[0-9]+(\.[0-9]+)?' \
	| head -n1)"

if [ -z "$coverage" ]; then
	echo "ERROR: could not extract type-coverage percentage from Psalm output." >&2
	echo "--- Psalm output follows ---" >&2
	printf '%s\n' "$psalm_output" >&2
	exit 1
fi

# Round to one decimal for the badge message; band the color by the integer part.
display="$(printf '%.1f' "$coverage")"
int="${coverage%%.*}"

if   [ "$int" -ge 95 ]; then color="brightgreen"
elif [ "$int" -ge 90 ]; then color="green"
elif [ "$int" -ge 80 ]; then color="yellowgreen"
elif [ "$int" -ge 70 ]; then color="yellow"
else                         color="orange"
fi

cat > "$BADGE_FILE" <<JSON
{
  "schemaVersion": 1,
  "label": "type coverage",
  "message": "${display}%",
  "color": "${color}"
}
JSON

echo "Wrote ${BADGE_FILE}: ${display}% (${color})"

if [ "$PUBLISH" -eq 0 ]; then
	exit 0
fi

# Publish to the `badges` branch as a single-file commit using git plumbing.
# This touches no working-tree files and keeps the branch to exactly one file,
# so there is no `rm -rf` and no pollution of main's history. The `badges`
# branch is not watched by any workflow, so this push cannot trigger a CI loop.
blob="$(git hash-object -w "$BADGE_FILE")"
tree="$(printf '100644 blob %s\t%s\n' "$blob" "$BADGE_FILE" | git mktree)"
commit="$(git commit-tree "$tree" -m "chore(ci): update type-coverage badge (${display}%)")"
git push -f origin "${commit}:refs/heads/${BADGE_BRANCH}"

echo "Published ${BADGE_FILE} to ${BADGE_BRANCH} branch."
