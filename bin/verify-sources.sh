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

# Sets FETCH_ERR to "http" (a permanent 404/410 — the file is gone), "network" (could
# not reach the host at all), or "unavailable" (a retryable answer: 429/5xx/403). See
# the classification block below for why those are three cases and not two: a 404 on a
# deleted upstream file is the PRIMARY drift this tool exists to catch, so conflating it
# with "no wifi" or a transient 503 would let --offline-ok swallow the one failure that
# matters — or, the other way, falsely brand a temporary outage a deleted file.
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
	local key
	key="$(printf '%s' "$url" | cksum | tr -d ' ')"
	local path="$CACHE_DIR/$key"
	FETCH_ERR=""
	FETCH_PATH=""
	FETCH_STATUS=""

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
	set -e

	# The HTTP status is authoritative whenever the server answered at all — branch on
	# it FIRST, before the curl exit code. curl can exit non-zero (18 for a truncated
	# body, 56 for a mid-transfer reset) while %{http_code} still holds the real status:
	# a local server that sends 404 headers and then cuts the body reproduces exit 18
	# with status 404. An earlier revision tested the exit code first and so filed that
	# 404 — a DELETED upstream file, the primary drift this tool exists to catch — as a
	# network outage, where --offline-ok would swallow it.
	#
	# Three outcomes, not two:
	#   - 2xx           the file is there; check the snippet.
	#   - 404 / 410     the file is gone for good. Permanent drift, a hard failure —
	#                   --offline-ok must NOT swallow a deleted upstream file.
	#   - anything else the server answered but not with the file (429, 5xx, a 403 rate
	#                   limit) or did not answer at all (000/empty). None of these prove
	#                   the file is gone, and calling a transient 503 "moved or deleted"
	#                   is exactly the kind of confabulated claim this tool exists to
	#                   prevent. Treated as an availability failure: the run still fails
	#                   without --offline-ok, but --offline-ok may skip it.
	# `curl` here omits --fail on purpose, so %{http_code} carries the real status
	# instead of being flattened to a generic error.
	case "$status" in
		2??)
			FETCH_PATH="$path"
			return 0
			;;
		404|410)
			rm -f "$path"
			FETCH_ERR="http"
			FETCH_STATUS="$status"
			return 1
			;;
		''|000)
			# No HTTP response: DNS, connect, TLS, timeout, proxy, reset.
			rm -f "$path"
			FETCH_ERR="network"
			return 1
			;;
		*)
			# The server answered, but not with the file (429/5xx/403…). Retryable, not
			# proof of deletion.
			rm -f "$path"
			FETCH_ERR="unavailable"
			FETCH_STATUS="$status"
			return 1
			;;
	esac
}

network_failures=0
hard_failures=0

# IDs seen so far, as a space-delimited string (associative arrays are bash 4+, and
# this must run on the macOS system bash 3.2 too).
seen_ids=" "

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

	# Registry IDs use a per-source-family prefix from a closed set. That is what makes
	# the dangling-reference
	# scan below provably complete: a reference outside the scanned prefix cannot exist,
	# because a row that would mint one is rejected here. To add another source family,
	# widen this list AND the scan's regex together (search: GB-).
	#   GB- Gutenberg / wordpress-develop     FT- Snicco Fortress
	case "$id" in
		GB-*|FT-*) ;;
		*)
			add_failure "$id: registry IDs must use a known prefix (GB-, FT-) — the dangling-reference scan looks for those and would never find a reference to this ID"
			continue
			;;
	esac

	# A duplicated ID gives one prose reference two competing canonical rows, and
	# --fix-lines would rewrite whichever row it reached first. Each ID must be unique.
	case "$seen_ids" in
		*" $id "*)
			add_failure "$id: duplicate registry ID — each ID must appear in exactly one row"
			continue
			;;
	esac
	seen_ids="$seen_ids$id "

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

	# Every field the registry contract promises must be present. An empty Line
	# silently disables drift detection (and --fix-lines) for the row; an empty Claim
	# leaves the canonical registry without the assertion it exists to record. The
	# enclosing symbol is required separately below, after the URL is known to be sane.
	if [ -z "$url" ] || [ -z "$snippet" ] || [ -z "$line" ] || [ -z "$claim" ]; then
		add_failure "$id: incomplete row — a row needs a URL, a line number, a snippet, and the claim it supports"
		continue
	fi

	# Raw-text hosts only. A github.com/.../blob/... page embeds the source inside
	# rendered HTML, so a snippet "match" there proves nothing about the file, and
	# --fix-lines would happily record the line number of the HTML — writing a
	# fabricated fact into the registry. Demonstrated during review.
	case "$url" in
		*/)
			# An SVN directory URL 200s with an HTML index listing, so a snippet can
			# "match" the rendered page exactly as it did for github.com/blob. Cite a file.
			add_failure "$id: URL points at a directory — cite a file, not a listing"
			continue
			;;
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
		case "$FETCH_ERR" in
			network)
				add_network_failure "$id: could not reach $url (network)"
				;;
			unavailable)
				# A retryable status (429/5xx/403 rate limit). Counted with the network
				# failures so --offline-ok can skip a temporary outage — but NOT called
				# drift, because the file may be perfectly fine.
				add_network_failure "$id: $url returned HTTP $FETCH_STATUS — upstream temporarily unavailable, not treated as drift"
				;;
			*)
				add_failure "$id: $url returned HTTP $FETCH_STATUS — the file has moved or been deleted upstream
        claim now unsupported: $claim"
				;;
		esac
		continue
	fi

	file_path="$FETCH_PATH"
	needle="$(printf '%s' "$snippet" | squeeze)"
	matches="$(squeeze < "$file_path" | grep -nF -- "$needle" || true)"
	match_count="$(printf '%s' "$matches" | grep -c . || true)"

	if [ "$match_count" -eq 0 ]; then
		add_failure "$id: snippet no longer present upstream — $url
        expected: $snippet
        claim now unsupported: $claim
        (snippets are matched on ONE line; if the construct wraps upstream, cite a single-line fragment of it)"
		continue
	fi

	# More than one line matches, so the recorded line number and enclosing symbol no
	# longer resolve to a single place: head -n1 would silently pick the first copy and
	# --fix-lines could repoint the registry at the wrong occurrence. Demand a unique
	# fragment instead of guessing.
	if [ "$match_count" -gt 1 ]; then
		add_failure "$id: snippet matches $match_count lines upstream — the citation is ambiguous, cite a longer fragment unique to $symbol — $url
        snippet: $snippet"
		continue
	fi

	hit="$matches"
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
# -I to match the orphan scan below: a binary containing these bytes would otherwise
# be a hard failure. Real prose is NOT excluded — an example ID written into a doc or a
# comment must be caught too (the registry's how-to section once pointed at an ID that
# did not exist, and an example there would have been just as invisible), which is why
# this comment names no ID. Two directories are excluded: .claude holds sibling
# worktree checkouts (a branch that added a GB-* row absent here would otherwise read
# as a dangling failure against a valid tree), and verify-sources holds this checker's
# own regression harness, whose fixtures necessarily contain example GB-* IDs — the
# same self-reference exemption bin/verify-sources.sh gets in the orphan scan below.
referenced="$(grep -rhoIE '\b(GB|FT)-[A-Z0-9][A-Z0-9-]+' \
	--exclude-dir={vendor,node_modules,.git,vendor_test,.tmp,.claude,verify-sources} \
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
# CLAUDE.md carry example URLs inside the rules themselves. `.claude` holds sibling
# worktree checkouts (see the dangling-ID scan above).
#
# The patterns capture the OWNER, so the self-link filter runs per matched URL, not
# per line: a comparison-table row that cites both this repo and a third-party file
# keeps the third-party citation instead of the whole line being dropped because
# `dknauss/Sudo` appears somewhere on it. `-o` emits one line per match, so filtering
# by URL and mapping back to the file are the same pipeline.
#
# Three things are not third-party claims and can never become registry rows:
#   - links to THIS repo (readme badges, the Playground blueprint, docs cross-links)
#   - a tool download in the test-environment installer
#   - this checker's own documentation, which necessarily contains the patterns
#   - this checker's regression harness (tests/verify-sources), whose fixtures cite
#     example raw URLs — excluded by directory, like the checker's own source
#
# A new orphan is a FAIL: the registry is mandatory for third-party citations, and a
# warning-only check leaves that rule unenforced while the scheduled job stays green.
# The existing corpus is grandfathered below (warn, not fail) so adopting the rule did
# not turn a migration backlog into a red build on day one; shrink that list as files
# migrate. A first cut matched per file and warned about 14, of which 6 were noise —
# including verify-sources.yml, i.e. the tool warning about itself.
# Shrinks as files are migrated. docs/sudo-architecture-comparison-matrix.md was migrated
# (FT-* rows). The two-factor files cite upstream at PINNED COMMIT SHAs
# (bea876d…, c515462…, abf9109…), which are immutable: a registry row cannot protect
# against drift that cannot happen. They stay listed so the scan does not fail on them,
# not because they are owed a migration.
GRANDFATHERED_ORPHANS="CHANGELOG.md
docs/abilities-api-assessment.md
docs/core-sudo-gate-implementation-spec.md
docs/two-factor-authentication-flow.md
docs/two-factor-ecosystem.md
docs/two-factor-integration.md
tests/Unit/RequestStashTest.php"

orphans="$(grep -roIE \
	-e 'raw\.githubusercontent\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+' \
	-e 'plugins\.svn\.wordpress\.org/[A-Za-z0-9_.-]+' \
	-e 'github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/blob/' \
	--exclude-dir={vendor,node_modules,.git,vendor_test,.tmp,.planning,.claude,verify-sources} \
	"$REPO_ROOT" 2>/dev/null \
	| grep -viE 'dknauss(/|%2f)sudo' \
	| grep -vE '^[^:]*/docs/archive/' \
	| grep -vE '^[^:]*/(docs/upstream-sources\.md|docs/llm-lies-log\.md|AGENTS\.md|CLAUDE\.md):' \
	| grep -vE '^[^:]*/(bin/verify-sources\.sh|bin/install-wp-tests\.sh):' \
	| grep -vE '^[^:]*/\.github/workflows/verify-sources\.yml:' \
	| cut -d: -f1 | sort -u || true)"
while IFS= read -r f; do
	[ -n "$f" ] || continue
	rel="${f#"$REPO_ROOT/"}"
	if printf '%s\n' "$GRANDFATHERED_ORPHANS" | grep -qxF -- "$rel"; then
		add_warning "$rel cites upstream code outside the registry (grandfathered — migrate as you touch it)"
	else
		add_failure "$rel cites upstream code outside the registry — add a row to docs/upstream-sources.md and reference its registry ID instead of the raw URL"
	fi
done <<< "$orphans"

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
