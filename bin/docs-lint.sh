#!/usr/bin/env bash
#
# Reject volatile dates and test/assertion counts outside canonical/history docs.

set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

EXCLUDED_FILES=(
	"CHANGELOG.md"
	"docs/ROADMAP.md"
	"docs/abilities-api-assessment.md"
	"docs/connectors-api-reference.md"
	"docs/core-action-gate-proposal.md"
	"docs/current-metrics.md"
	"docs/collaboration-analysis.md"
	"docs/sudo-design-notes.md"
	"docs/testing-strategy.md"
	"docs/e2e-runtime-review.md"
	"docs/llm-lies-log.md"
	"docs/password-change-reauth-research.md"
	"docs/password-manager-compatibility.md"
	"docs/release-status.md"
	"docs/security-test-results-4.8.0.md"
	"docs/release-environment-log.md"
	"docs/security-model.md"
	"docs/two-factor-authentication-flow.md"
	"docs/two-factor-ecosystem.md"
	"docs/two-factor-integration.md"
	"docs/vulnerability-testing-guide.md"
	"docs/wordpress-core-authentication.md"
)

EXCLUDED_PATHS='^(\.planning/|\.github/skills/|\.github/php[0-9]+-tests/|tests/|docs/archive/)'
EXCLUDE_FILE_REGEX="$(IFS='|'; echo "${EXCLUDED_FILES[*]}")"

MD_FILES="$(
	git ls-files -- '*.md' \
		| grep -Ev "$EXCLUDED_PATHS" \
		| grep -Ev "^(${EXCLUDE_FILE_REGEX})$" || true
)"

if [ -z "$MD_FILES" ]; then
	echo "No markdown files to lint."
	exit 0
fi

DATE_PATTERN='[0-9]{4}-[0-9]{2}-[0-9]{2}'
COUNT_PATTERN='[0-9]+ (tests|assertions)\b'

STALE_DATES=()
STALE_COUNTS=()
while IFS= read -r file; do
	[ -z "$file" ] && continue
	if grep -qE "$DATE_PATTERN" "$file"; then
		STALE_DATES+=("$file")
	fi
	if grep -qE "$COUNT_PATTERN" "$file"; then
		STALE_COUNTS+=("$file")
	fi
done <<< "$MD_FILES"

if [ ${#STALE_DATES[@]} -gt 0 ] || [ ${#STALE_COUNTS[@]} -gt 0 ]; then
	echo "::error ::Documentation lint failed:"
	if [ ${#STALE_DATES[@]} -gt 0 ]; then
		echo "Stale fixed-date references found in:"
		for file in "${STALE_DATES[@]}"; do
			echo "  - $file"
			grep -nE "$DATE_PATTERN" "$file" | head -3
		done
	fi
	if [ ${#STALE_COUNTS[@]} -gt 0 ]; then
		echo "Old count patterns found in:"
		for file in "${STALE_COUNTS[@]}"; do
			echo "  - $file"
			grep -nE "$COUNT_PATTERN" "$file" | head -3
		done
	fi
	exit 1
fi

echo "Documentation lint passed: no stale fixed-date references or old count patterns found outside canonical docs."
