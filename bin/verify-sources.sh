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
#         a check that cries wolf gets switched off. The neighbouring risk is a check
#         that never cries at all: e2e-visual.yml marks its step continue-on-error by
#         design, and three consecutive nightly runs on `main` (2026-07-24/25/26)
#         reported "success" with four visual baselines failing.
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

add_failure() {
	failures+=("$1")
	hard_failures=$((hard_failures + 1))
}
add_network_failure() {
	failures+=("$1")
	network_failures=$((network_failures + 1))
}
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

# Sets FETCH_ERR to "network" (could not reach the host) or "http" (reached it, got
# an error status). Keeping those apart is the whole reason this is not a one-liner:
# a 404 on a deleted upstream file is the PRIMARY drift this tool exists to catch, and
# conflating it with "no wifi" lets --offline-ok swallow exactly the failure that
# matters.
# Results come back in FETCH_PATH / FETCH_ERR rather than on stdout: `$(fetch ...)`
# runs the function in a SUBSHELL, so anything it assigns is discarded when that
# subshell exits. An earlier revision returned the path on stdout and set FETCH_ERR as
# a side effect, which meant the caller always saw an empty FETCH_ERR and classified
# every failure as an HTTP error — including a plain unreachable network.
FETCH_ERR=""
FETCH_PATH=""
FETCH_STATUS=""
fetch() {
	local url="$1"
	local key code
	key="$(printf '%s' "$url" | cksum | tr -d ' ')"
	local path="$CACHE_DIR/$key"
	FETCH_ERR=""
	FETCH_PATH=""

	if [ -f "$path" ]; then
		FETCH_PATH="$path"
		return 0
	fi

	# Ask for the HTTP status explicitly instead of inferring it from curl's exit
	# code. Two earlier revisions got this wrong in opposite directions, because the
	# exit code does not mean what it looks like it means: an unreachable proxy
	# returns 97, and raw.githubusercontent.com answers a missing path with a
	# connection teardown that curl reports as 56, NOT the 22 a 404 would suggest.
	# Mapping exit codes therefore misfiled the single most important case — a
	# deleted upstream file — as "no network", where --offline-ok would swallow it.
	# The status line is unambiguous, so read that.
	local status
	set +e
	status="$(curl -sSL --max-time 30 -o "$path" -w '%{http_code}' "$url" 2>/dev/null)"
	code=$?
	set -e

	if [ "$code" -ne 0 ]; then
		# Transport failure: DNS, connect, TLS, timeout, proxy, reset. No answer at
		# all, so nothing can be concluded about the claim.
		rm -f "$path"
		FETCH_ERR="network"
		return 1
	fi

	case "$status" in
		2??)
			FETCH_PATH="$path"
			return 0
			;;
		*)
			# The server answered and said no. That is a finding, not an outage.
			rm -f "$path"
			FETCH_ERR="http"
			FETCH_STATUS="$status"
			return 1
			;;
	esac
}

network_failures=0
hard_failures=0

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

	# A markdown row ends with `|`, so one trailing empty field is expected. Anything
	# else means the row split into too many columns — almost always a literal pipe in
	# a snippet that was not escaped. Without this the fields shift left and the row
	# fails as "no enclosing symbol recorded", sending the reader after the wrong bug.
	if [ -n "$(clean_field "${_rest:-}")" ]; then
		add_failure "$id: row has more columns than the table defines — escape any literal | inside a snippet as \\|"
		continue
	fi

	if [ -z "$url" ] || [ -z "$snippet" ]; then
		add_failure "$id: row is missing a URL or a snippet"
		continue
	fi

	# Raw-text hosts only. A github.com/.../blob/... page embeds the source inside
	# rendered HTML, so a snippet "match" there proves nothing about the file, and
	# --fix-lines would happily record the line number of the HTML — writing a
	# fabricated fact into the registry. Demonstrated during review.
	case "$url" in
		https://raw.githubusercontent.com/* | https://plugins.svn.wordpress.org/*) ;;
		*)
			add_failure "$id: not a raw-text source URL — use raw.githubusercontent.com or plugins.svn.wordpress.org, not a rendered github.com/blob page"
			continue
			;;
	esac

	if [ -z "$symbol" ]; then
		# The whole point of the enclosing-symbol column: you cannot fill it in from a
		# grep hit, so requiring it forces the file to actually be read.
		add_failure "$id: no enclosing symbol recorded — cite what CONTAINS the line"
		continue
	fi

	checked=$((checked + 1))

	if ! fetch "$url"; then
		if [ "$FETCH_ERR" = "network" ]; then
			add_network_failure "$id: could not reach $url (network)"
		else
			add_failure "$id: $url returned HTTP $FETCH_STATUS — the file has moved or been deleted upstream
        claim now unsupported: $claim"
		fi
		continue
	fi

	file_path="$FETCH_PATH"
	needle="$(printf '%s' "$snippet" | squeeze)"
	hit="$(squeeze < "$file_path" | grep -nF -- "$needle" | head -n1 || true)"

	if [ -z "$hit" ]; then
		add_failure "$id: snippet no longer present upstream — $url
        expected: $snippet
        claim now unsupported: $claim
        (snippets are matched on ONE line; if the construct wraps upstream, cite a single-line fragment of it)"
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

# --- dangling registry IDs -------------------------------------------------------
# The registry only helps if the IDs prose points at exist. Replacing URLs with IDs
# creates this failure mode, so it ships with the check for it: a renamed or deleted
# row would otherwise leave a comment pointing at nothing, silently.
referenced="$(grep -rhoE '\bGB-[A-Z0-9][A-Z0-9-]{2,}' \
	--exclude-dir={vendor,node_modules,.git,vendor_test,.tmp} \
	--exclude='verify-sources.sh' \
	"$REPO_ROOT" 2>/dev/null | sort -u || true)"
while IFS= read -r ref; do
	[ -n "$ref" ] || continue
	if ! grep -q "^| $ref |" "$SOURCES_FILE"; then
		add_failure "$ref is referenced in the repo but has no row in docs/upstream-sources.md"
	fi
done <<< "$referenced"

# --- citations that escaped the registry -----------------------------------------
# Matches the citation styles this repo actually uses, not just one of them: raw
# GitHub, rendered GitHub blob links (the dominant style in docs/), and the
# WordPress.org plugin SVN that CLAUDE.md's own verification commands recommend.
# Scoping to raw.githubusercontent-only reported zero warnings while 20+ live
# citations sat unregistered — a false green, which is worse than noise.
#
# Excluded, deliberately: `.planning/` and `docs/archive/` hold dated records of what
# was believed at the time, and freezing those is correct — demanding migration would
# be the same mistake as rewriting a superseded design brief instead of marking it
# historical. `docs/llm-lies-log.md` records past errors verbatim. AGENTS.md and
# CLAUDE.md carry example URLs inside the rules themselves.
#
# WARN, not fail: migrating the existing corpus is incremental work, and turning a
# backlog into a red build on day one is how a check gets deleted instead of adopted.
orphans="$(grep -rIlE \
	-e 'raw\.githubusercontent\.com' \
	-e 'plugins\.svn\.wordpress\.org' \
	-e 'github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/blob/' \
	--exclude-dir={vendor,node_modules,.git,vendor_test,.tmp,.planning,archive} \
	"$REPO_ROOT" 2>/dev/null \
	| grep -v 'docs/upstream-sources.md' \
	| grep -v 'docs/llm-lies-log.md' \
	| grep -v 'bin/verify-sources.sh' \
	| grep -vE '/(AGENTS|CLAUDE)\.md$' || true)"
if [ -n "$orphans" ]; then
	orphan_count="$(printf '%s\n' "$orphans" | grep -c . || true)"
	add_warning "$orphan_count file(s) cite upstream code outside the registry (migrate as you touch them):"
	shown=0
	while IFS= read -r f; do
		[ -n "$f" ] || continue
		if [ "$shown" -lt 8 ]; then
			add_warning "    ${f#"$REPO_ROOT/"}"
			shown=$((shown + 1))
		fi
	done <<< "$orphans"
	if [ "$orphan_count" -gt 8 ]; then
		add_warning "    … and $((orphan_count - 8)) more"
	fi
fi

for w in "${warnings[@]:-}"; do
	[ -n "$w" ] && echo "WARN: $w"
done

if [ "$hard_failures" -gt 0 ] || [ "$network_failures" -gt 0 ]; then
	# --offline-ok covers "I am on a plane", nothing else. If ANY row failed for a
	# reason other than not reaching the host, this exits 1 regardless of the flag —
	# otherwise one unreachable row would mask every real finding in the table.
	if [ "$hard_failures" -eq 0 ] && [ "$OFFLINE_OK" = "1" ]; then
		echo "SKIPPED: $network_failures citation(s) unreachable, none failed for any other reason (--offline-ok)."
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
