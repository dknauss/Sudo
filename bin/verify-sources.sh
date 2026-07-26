#!/usr/bin/env bash
#
# verify-sources.sh — check every third-party citation in docs/upstream-sources.md
# against the live upstream file.
#
# Two outcomes, deliberately different:
#
#   FAIL  the snippet is GONE from the upstream file (or the file 404s). The claim
#         built on it is now unsupported and the prose citing it is a lie until fixed.
#   WARN  the snippet moved to a different line. Upstream trunk moves constantly and a
#         drifted line number is not a false claim. Warning-only is a deliberate choice:
#         a check that cries wolf gets switched off, and this repo has already seen what
#         that looks like (e2e-visual.yml ran with continue-on-error and reported
#         "success" over four failing baselines for weeks).
#
# Usage:
#   bin/verify-sources.sh              # fail on missing snippets, warn on drift
#   bin/verify-sources.sh --fix-lines  # additionally rewrite drifted line numbers
#   bin/verify-sources.sh --offline-ok # exit 0 if upstream is unreachable
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCES_FILE="$REPO_ROOT/docs/upstream-sources.md"
CACHE_DIR="$(mktemp -d)"
trap 'rm -rf "$CACHE_DIR"' EXIT

FIX_LINES=0
OFFLINE_OK=0
for arg in "$@"; do
	case "$arg" in
		--fix-lines) FIX_LINES=1 ;;
		--offline-ok) OFFLINE_OK=1 ;;
		*) echo "ERROR: unknown option: $arg"; exit 1 ;;
	esac
done

if [ ! -f "$SOURCES_FILE" ]; then
	echo "ERROR: Source registry not found: $SOURCES_FILE"
	exit 1
fi

failures=()
warnings=()
checked=0

add_failure() { failures+=("$1"); }
add_warning() { warnings+=("$1"); }

# Strip markdown code fences and unescape table pipes.
clean_field() {
	local value="$1"
	value="${value//$'\001'/|}"
	value="${value#"${value%%[![:space:]]*}"}"
	value="${value%"${value##*[![:space:]]}"}"
	value="${value#\`}"
	value="${value%\`}"
	printf '%s' "$value"
}

# Squeeze runs of whitespace so indentation changes upstream do not read as drift.
squeeze() { tr '\t' ' ' | tr -s ' '; }

fetch() {
	local url="$1"
	local key
	key="$(printf '%s' "$url" | cksum | tr -d ' ')"
	local path="$CACHE_DIR/$key"

	if [ -f "$path" ]; then
		printf '%s' "$path"
		return 0
	fi
	if curl -fsSL --max-time 30 "$url" -o "$path" 2>/dev/null; then
		printf '%s' "$path"
		return 0
	fi
	return 1
}

network_ok=1

ESC=$'\001' # stand-in for an escaped pipe while we split on real ones

while IFS= read -r raw_row; do
	# Only real table rows. Prose in this file legitimately contains pipes (the
	# instruction about escaping them, for one), so leading `|` is the row test.
	case "$raw_row" in '|'*) ;; *) continue ;; esac

	row="${raw_row//\\|/$ESC}"
	IFS='|' read -r _ id url line snippet symbol claim _rest <<< "$row"

	id="$(clean_field "${id:-}")"
	[ -n "$id" ] || continue
	[ "$id" != "ID" ] || continue
	case "$id" in ---*|:--*) continue ;; esac

	url="$(clean_field "$url")"
	line="$(clean_field "$line")"
	snippet="$(clean_field "$snippet")"
	symbol="$(clean_field "$symbol")"
	claim="$(clean_field "${claim:-}")"

	if [ -z "$url" ] || [ -z "$snippet" ]; then
		add_failure "$id: row is missing a URL or a snippet"
		continue
	fi
	if [ -z "$symbol" ]; then
		# The whole point of the enclosing-symbol column: you cannot fill it in from a
		# grep hit, so requiring it forces the file to actually be read.
		add_failure "$id: no enclosing symbol recorded — cite what CONTAINS the line"
		continue
	fi

	checked=$((checked + 1))

	if ! file_path="$(fetch "$url")"; then
		network_ok=0
		add_failure "$id: could not fetch $url"
		continue
	fi

	needle="$(printf '%s' "$snippet" | squeeze)"
	hit="$(squeeze < "$file_path" | grep -nF -- "$needle" | head -n1 || true)"

	if [ -z "$hit" ]; then
		add_failure "$id: snippet no longer present upstream — $url
        expected: $snippet
        claim now unsupported: $claim"
		continue
	fi

	actual_line="${hit%%:*}"
	if [ -n "$line" ] && [ "$actual_line" != "$line" ]; then
		add_warning "$id: line drifted $line -> $actual_line (snippet still present)"
		if [ "$FIX_LINES" = "1" ]; then
			awk -v id="$id" -v old="$line" -v new="$actual_line" '
				index($0, "| " id " |") == 1 {
					sub("\\| " old " \\|", "| " new " |")
				}
				{ print }
			' "$SOURCES_FILE" > "$SOURCES_FILE.tmp" && mv "$SOURCES_FILE.tmp" "$SOURCES_FILE"
		fi
	fi
done < "$SOURCES_FILE"

# Orphan check: a raw upstream URL cited in LIVE prose is a citation that escaped the
# registry, which is how seven divergent copies of one claim start.
#
# Scoped deliberately. `.planning/` holds dated records of what was believed at the
# time — freezing those is correct, and demanding migration would be the same mistake as
# rewriting a superseded design brief instead of marking it historical. AGENTS.md and
# CLAUDE.md carry example URLs inside the rules themselves. Flagging any of that would
# make this check noise, and a noisy check gets switched off.
orphans="$(grep -rIl --exclude-dir={vendor,node_modules,.git,vendor_test,.tmp,.planning} \
	-e 'raw\.githubusercontent\.com/WordPress' "$REPO_ROOT" 2>/dev/null \
	| grep -v 'docs/upstream-sources.md' \
	| grep -v 'docs/llm-lies-log.md' \
	| grep -v 'bin/verify-sources.sh' \
	| grep -vE '/(AGENTS|CLAUDE)\.md$' || true)"
if [ -n "$orphans" ]; then
	while IFS= read -r f; do
		[ -n "$f" ] || continue
		add_warning "citation outside the registry: ${f#"$REPO_ROOT/"}"
	done <<< "$orphans"
fi

for w in "${warnings[@]:-}"; do
	[ -n "$w" ] && echo "WARN: $w"
done

if [ ${#failures[@]} -gt 0 ] && [ -n "${failures[0]:-}" ]; then
	if [ "$network_ok" = "0" ] && [ "$OFFLINE_OK" = "1" ]; then
		echo "SKIPPED: upstream unreachable and --offline-ok was passed."
		exit 0
	fi
	echo
	echo "Source drift detected in docs/upstream-sources.md:"
	for f in "${failures[@]}"; do
		echo "  - $f"
	done
	echo
	echo "A missing snippet means the claim built on it is no longer supported."
	echo "Re-read the upstream file, then fix BOTH the registry row and the prose citing it."
	exit 1
fi

echo "Sources verified: $checked citations checked, all snippets present."
