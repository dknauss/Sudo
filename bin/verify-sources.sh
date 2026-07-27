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

# The one definition of a registry ID: a per-source-family prefix from a closed set,
# then two or more upper-case letters, digits or hyphens.
#   GB- Gutenberg / wordpress-develop     FT- Snicco Fortress
# Used BOTH to validate each row's ID and to scan the repo for references, so the two
# can never drift apart — a row whose ID the scan cannot match is rejected, which is
# what makes the dangling-reference check complete. Registering another source family
# is one edit here, not two that can fall out of step.
ID_REGEX='(GB|FT)-[A-Z0-9][A-Z0-9-]+'

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
	local status code
	set +e
	status="$(curl -sSL --max-time 30 -o "$path" -w '%{http_code}' "$url" 2>/dev/null)"
	code=$?
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
	#   - 2xx           the file is there; check the snippet — but ONLY if the transfer
	#                   also completed (see below).
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
			# 200 headers do not mean the body arrived. A truncated or reset transfer
			# (curl exit 18/56) leaves a PARTIAL file on disk that can still contain the
			# snippet if it happens to sit before the cutoff — which would report the
			# citation verified, and cache that answer for the rest of the run, on
			# evidence that is only part of the file. A snippet's ABSENCE cannot be
			# concluded from a partial file either, so this is an availability failure
			# in both directions, not drift.
			if [ "$code" -ne 0 ]; then
				rm -f "$path"
				FETCH_ERR="unavailable"
				FETCH_STATUS="$status (incomplete transfer, curl exit $code)"
				return 1
			fi
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
	#
	# Scoping this to the `## Registry` section was tried and REVERTED. It would let the
	# document carry an ordinary markdown table (a prefix table parsed as three bogus
	# rows, which is how the constraint was found), but the obvious implementation fails
	# SILENTLY: a registry whose heading is renamed, or reorganized so rows precede it,
	# reports "0 citations checked, all snippets present" and exits 0 — a checker that
	# verifies nothing while manufacturing confidence, the exact pathology this repo has
	# been removing elsewhere (a visual job green over four failing baselines; a POT
	# check wired into no workflow). Parsing every `|`-leading line fails loudly instead.
	# Revisiting it needs a floor guard (fail when zero rows parse, and/or assert the
	# heading exists), a test pinning that guard, and a design review — this is an
	# enforcement path.
	case "$raw_row" in '|'*) ;; *) continue ;; esac

	row="${raw_row//\\|/$ESC}"
	IFS='|' read -r _ id url line snippet symbol claim _rest <<< "$row"

	id="$(clean_field "${id:-}")"
	[ -n "$id" ] || continue
	[ "$id" != "ID" ] || continue
	case "$id" in ---*|:--*) continue ;; esac

	# Every registry ID must match ID_REGEX — the SAME pattern the dangling-reference
	# scan below uses, which is what makes that scan provably complete: an ID the scan
	# could not find a reference to is rejected here, at the row. Validating a looser
	# syntax than the scan searches for (a bare prefix test accepts `GB-a` and the
	# one-character `GB-X`, neither of which the scan matches) reopens exactly the
	# dangling-reference hole the prefix rule exists to close. To register another
	# source family, widen ID_REGEX — one edit, both uses.
	if ! printf '%s' "$id" | grep -qE "^${ID_REGEX}$"; then
		add_failure "$id: invalid registry ID — must match ${ID_REGEX} (the same pattern the dangling-reference scan searches for; an ID outside it could be deleted while prose still cites it, undetected)"
		continue
	fi

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
	# Candidate search forms for a recorded symbol, most specific first.
#
# A symbol is written the way a human names it; a declaration is written the way the
# language requires. Two mismatches account for every false positive seen against the
# live registry:
#
#   `foo()`              — conventional shorthand. Upstream declares `foo( $args )`, so
#                          the literal `foo()` matches only prose (`@see foo()`) and
#                          never the declaration, anchoring the check to documentation.
#   `Class::method()`    — qualified name. The declaration line carries no class prefix
#                          (`public static function method( $args )`), so the qualified
#                          form never appears at all.
#
# An anchor absent from the file in every form still fails, and the at-or-before-line test
# still decides enclosure. How each form is derived is set out immediately below. The
# paren-stripped `foo(` fallback that earlier revisions of this comment described is gone:
# it was itself the prefix defect.
#
# The witness must be the SHAPE the anchor asserts. A `foo()` anchor asserts the
# DECLARATION, so a call site is a weaker witness than the claim: emitting a bare `foo(`
# accepted an earlier `foo( $arg );` and the check passed without ever seeing the
# function. Worse in practice, and verified against trunk: the bare `foo(` needle is a
# PREFIX, so it also matches a longer identifier merely ENDING in the anchor name. For
# GB-AUTOUPDATER-UPGRADE (anchor `WP_Automatic_Updater::update()`, declaration at L362) the
# old needle `update(` matched L195 — `public function should_update(`, a DIFFERENT method
# sitting above the snippet — so the row passed while the check had never seen the function
# it names. So a `()` anchor emits `function NAME(` and NOT the
# bare name: every one of the 16 `()` anchors in the registry has such a declaration at or
# before its cited line, so this tightens without reddening a row, and a row that genuinely
# has no declaration now fails loudly instead of matching prose.
#
# A `<Foo>` anchor is the opposite case and must NOT be read as the same leniency. It is a
# USAGE anchor — the row claims something is *rendered inside* `<Foo>` — so the render site
# IS the claim rather than a proxy for it, and the source writes the element open with
# props (`<Foo scope={ x }>`) while the row writes it closed. Matching `<Foo` is therefore
# exact for what is asserted, where matching `foo(` was not.
# A QUALIFIED anchor (`Class::method()`) names an identity, not just a method. Searching
# only for `function method(` throws away the half that does the identifying: another class
# in the same file declaring the same method name above the snippet would satisfy it, which
# is precisely the miscitation the qualification exists to prevent. So a qualified span is
# expanded into TWO required anchors — the class declaration and the method declaration —
# and the AND semantics of the anchor loop then require both. Verified against trunk: all
# five qualified anchors in the registry have a `class X` declaration at or before their
# cited line, so this tightens without reddening a row.
#
# That expansion IDENTIFIES the two symbols; it does not bind them. AND proves each exists
# above the snippet and nothing more, so the method moving to a different class in the same
# file still satisfies both conjuncts. Containment is established separately, by the
# class-extent check at the end of the anchor block — see the comment there. Read the two
# together: neither is the whole mechanism, and an earlier draft of this paragraph claimed
# the expansion alone was sufficient.
#
# The limit, stated rather than implied away: `class X` is a fixed-string PREFIX, so
# `class Foo` is also satisfied by `class Foo_Helper`. It is not bounded here because
# anchors are matched with `grep -F` deliberately — 26 of the 48 anchors carry regex
# metacharacters, and `grep -E` does not merely reinterpret them, it hard-errors:
# GB-META-SHORTCIRCUIT's anchor is the unbalanced `function update_metadata(`, which -E
# rejects outright. Bounding this needle is not a matter of flipping that flag for one
# form either: the needle loop runs a single shared `grep -nF` over every form a symbol
# produces, so it would mean branching the grep mode per needle. A trailing space
# (`class X `) would bound it without touching grep, but trades a false pass
# for a false fail on `class X{` formatting, which is its own decision with its own
# red-on-arrival question. The residual is strictly narrower than the hole it closes: the
# method name alone identified nothing, whereas this fails only when a longer class name
# shares the anchor's prefix.
expand_anchor_spans() {
	while IFS= read -r a; do
		[ -n "$a" ] || continue
		case "$a" in
			*'::'*'()')
				printf 'class %s\n' "${a%%::*}"
				m="${a##*::}"
				printf 'function %s(\n' "${m%()}"
				;;
			*) printf '%s\n' "$a" ;;
		esac
	done
}

symbol_needles() {
	local raw="$1"
	case "$raw" in
		*'()')
			# Declaration-shaped only. The raw `foo()` form is deliberately not emitted:
			# upstream declares `foo( $args )`, so a literal `foo()` matches only prose
			# (`@see foo()`), which is the documentation-anchoring failure this avoids.
			local fname="${raw%()}"
			fname="${fname##*::}"
			# PHP-shaped, which covers all 16 `()` anchors in the registry today. A
			# future JS/TS anchor written `foo()` for a class method or arrow function
			# has no `function foo(` line and will hard-fail here. Add that language's
			# declaration form as another needle — do NOT reinstate the bare `foo(`
			# fallback, which is what matched `should_update(` for an `update()` anchor.
			printf 'function %s(\n' "$fname"
			return
			;;
		'<'*'>')
			# Exact form first, so a literal `<Foo>` wins over the open-tag form. `<Foo`
			# is a prefix and would also match `<FooBar …>`; no live collision, but a row
			# whose point is distinguishing `Foo` from `FooBar` should say so in its
			# rationale column rather than rely on this ordering.
			printf '%s\n' "$raw"
			printf '%s\n' "${raw%>}"
			return
			;;
	esac
	printf '%s\n' "$raw"
	case "$raw" in
		*'::'*) printf '%s\n' "${raw##*::}" ;;
	esac
}

# Keep the symbol column's backticks: they delimit the machine-checkable anchor
	# tokens. clean_field strips the outer pair, which is right for the other columns
	# and wrong here.
	symbol_raw="${symbol//$ESC/|}"
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

	# The column has to be checkable, not just present. At least one `backticked` token
	# is required so there is something to look for upstream; the surrounding prose
	# ("… rendered inside …", "NOT the three-line Fill at L11-13") stays free-form.
# Rows whose enclosing context is not a named code symbol, and which therefore predate
# the anchor requirement. Three kinds, none of them a code identifier:
#   - prose documents, where the enclosing unit is a clause or a heading (the Fortress
#     rows, FT-*);
#   - a top-level statement, where there is no enclosing function at all
#     (GB-PROFILE-OBSERVE: user-edit.php:149 is a bare do_action, and that file has zero
#     function declarations above it);
#   - markup, where the "symbol" is a CSS class on an element (GB-PIN-STAR).
#
# Listed rows WARN; everything else fails. That is deliberate sequencing, not leniency:
# requiring anchors before the existing rows can carry them makes the checker red on
# arrival, and a gate that is red on arrival is one people learn to run with their eyes
# closed — the exact harm this checker exists to prevent. Whether the check should exempt
# non-code sources by kind, or those rows be restructured to carry an anchor, is an open
# design question; this list is what lets the requirement apply to NEW rows meanwhile.
UNANCHORED_LEGACY_IDS="$(cat <<'EOF'
FT-SESSION-DROPIN
FT-MU-LOADER
FT-PROTECTED-CAPS
FT-EULA
EOF
)"

is_unanchored_legacy() {
	printf '%s\n' "$UNANCHORED_LEGACY_IDS" | grep -qxF -- "$1"
}

# Rows whose cited line is TOP-LEVEL code: not inside a function, class, or control
# structure, so no enclosing symbol exists to name. Kept separate from the legacy list
# above rather than merged into it, because these rows do NOT predate the anchor
# requirement — they were written after it — and warning them with that message would
# put a false statement in the gate's own output.
#
# The criterion is strict and checkable by hand: the cited line must sit at file top
# level in a procedural script. Both current entries are `require_once`-and-statements
# admin scripts where the citation is ABOUT the top-level control flow:
#   - GB-NETWORK-EDIT-REDIRECT — network/edit.php:15 is `if ( empty( $action ) ) {`, the
#     guard the row's claim is about, with only comments, one require and the $action
#     assignment above it. (It cited the L13 assignment until #443 moved it; the reason
#     for the exemption is unchanged, since nothing encloses L15 either.)
#   - GB-UPDATE-NEEDS-ACTION — update.php:22 is itself the top-level conditional WRAPPING
#     THE FILE BODY; the claim is that the whole body sits inside it, so nothing can
#     enclose it by definition. Not the file's only top-level conditional — the
#     IFRAME_REQUEST guard at L9 is another; the qualifier is what makes this true, and
#     an earlier draft of this comment dropped it and asserted "sole".
#
# What is lost is nothing real: there is no enclosing symbol to rename or delete, so the
# anchor check has no drift to detect here. Snippet presence and line drift still run, and
# an anchor cell for these rows carries prose with NO backticked token — inventing one
# would be a needle that proves nothing, which is the failure this file exists to prevent.
TOP_LEVEL_STATEMENT_IDS="$(cat <<'EOF'
GB-NETWORK-EDIT-REDIRECT
GB-UPDATE-NEEDS-ACTION
EOF
)"

is_top_level_statement() {
	printf '%s\n' "$TOP_LEVEL_STATEMENT_IDS" | grep -qxF -- "$1"
}

	symbol_anchors="$(printf '%s' "$symbol_raw" | grep -oE '`[^`]+`' | tr -d '`' | expand_anchor_spans || true)"
	# Kept UNEXPANDED as well. expand_anchor_spans() turns `X::m()` into two independent
	# conjuncts, and AND proves only that each exists above the snippet — not that the
	# method belongs to that class. Binding them needs the qualified form back.
	qualified_anchors="$(printf '%s' "$symbol_raw" | grep -oE '`[^`]+`' | tr -d '`' | grep -E '::.+\(\)$' || true)"
	# Exempt rows skip the ANCHOR comparison only. Everything else — fetch, snippet
	# presence, line drift — still runs. `continue` here would turn a narrow exemption
	# into a total one, so a legacy row whose upstream file vanished would pass in
	# silence: a checker reporting success while verifying nothing, which is the failure
	# this file exists to prevent.
	anchor_check=1
	if [ -z "$symbol_anchors" ]; then
		if is_unanchored_legacy "$id"; then
			add_warning "$id: enclosing context is not a named code symbol, so it predates the anchor requirement — snippet and drift are still verified"
			anchor_check=0
		elif is_top_level_statement "$id"; then
			add_warning "$id: cited line is top-level code with no enclosing symbol — exempt from the anchor check by kind, not by age; snippet and drift are still verified"
			anchor_check=0
		else
			add_failure "$id: enclosing symbol has no \`backticked\` anchor token — wrap the symbol's upstream name in backticks so it can be checked against the file"
			continue
		fi
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

	# The recorded enclosing symbol must still be there, and must still open at or
	# before the matched line — an enclosing scope is always declared before the line it
	# encloses, in every language cited here. Until this check existed, $symbol was
	# used only inside error strings: upstream could rename or delete the component the
	# claim is *about* and the row stayed green because the snippet survived elsewhere
	# in the file.
	#
	# What this proves and what it does not. It catches the enclosing symbol being
	# renamed, removed, or moved below the cited line. It does NOT prove the match sits
	# inside that symbol's body: verifying containment needs a real parser for SCSS, JS
	# and TS, which is a different tool. The residual case — an identical unique line
	# relocating into a *different* symbol whose recorded anchor still appears earlier in
	# the file — shows up as a drift WARN, because such a move almost always changes the
	# line number.
	#
	# Every code span in the cell must resolve — AND, not OR. The rows express
	# containment ("`X` in `Y`"), so ORing them means either half alone satisfies the
	# check, and a row's own disambiguating prose then WEAKENS it: the more carefully it
	# is written, the more alternatives it offers. AND is also the safe direction — it can
	# only tighten, and a span that turns out absent goes visibly red rather than silently
	# absorbing the check.
	anchor_ok=1
	anchor_count=0
	anchor_missing=""
	anchor_seen=""
	while IFS= read -r anchor; do
		[ -n "$anchor" ] || continue
		# Which needle forms a recorded symbol produces, and why each is the shape the
		# anchor asserts, is documented once at symbol_needles(). That header is
		# authoritative; this comment deliberately does not restate it.
		#
		# Where more than one form is emitted, prefer whichever first occurrence
		# satisfies the at-or-before-line test rather than the first form matching
		# ANYTHING: a match far below the snippet would otherwise decide the check
		# against a declaration sitting right above it.
		anchor_line=""
		anchor_best=""
		# Read newline-delimited: anchors are frequently multiword — the registry holds
		# markdown headings like `## Relationship between the timeouts`. Iterating an
		# unquoted command substitution word-splits those, and the resulting generic `##`
		# needle matches ANY heading, so the check would pass vacuously for every
		# markdown source. That fails OPEN, which is worse than the false positives the
		# needle forms exist to fix.
		while IFS= read -r anchor_needle; do
			[ -n "$anchor_needle" ] || continue
			cand="$(grep -nF -- "$anchor_needle" "$file_path" | head -n1 || true)"
			cand="${cand%%:*}"
			[ -n "$cand" ] || continue
			[ -n "$anchor_best" ] || anchor_best="$cand"
			if [ "$cand" -le "$actual_line" ]; then
				anchor_best="$cand"
				break
			fi
		done <<< "$(symbol_needles "$anchor")"
		anchor_line="$anchor_best"
		anchor_count=$((anchor_count + 1))
		if [ -n "$anchor_line" ] && [ "$anchor_line" -le "$actual_line" ]; then
			# Bracketed, not parenthesised: an anchor may itself end in `(`
			# (`function foo(`), and `foo((L2)` reads as a typo on the one line a
			# reader lands on when the check fires.
			anchor_seen="$anchor_seen $anchor [L$anchor_line]"
		else
			anchor_ok=0
			anchor_missing="$anchor_missing $anchor${anchor_line:+ [L$anchor_line, after the snippet]}"
		fi
	done <<< "$symbol_anchors"
	# No span examined at all cannot count as satisfied.
	[ "$anchor_count" -gt 0 ] || anchor_ok=0

	# Bind a qualified anchor to its class. The two conjuncts above are checked
	# INDEPENDENTLY, so `class X` staying in the file while `m()` moves to a different
	# class earlier in it satisfies both and the row passes asserting something false.
	# The conjunction is necessary but not sufficient: it proves both symbols exist,
	# never that one contains the other.
	#
	# Approximated by ordering rather than parsed: PHP classes do not nest, so the method
	# is inside class X exactly when its declaration follows `class X` with no other
	# class/interface/trait/enum declaration in between. That is the whole check — no parser,
	# which the header is explicit about not attempting. It is deliberately narrower than
	# general containment: it does NOT prove the cited LINE sits in the method body, only
	# that the method belongs to the class the row names.
	#
	# All five qualified anchors in the registry were verified to bind before this landed
	# — Configurator::extract_assoc (class L13, method L274), Core_Upgrader::upgrade
	# (L25/L66), WP_Ability::execute (L21/L598), WP_Automatic_Updater::update (L17/L362),
	# WP_Upgrader::install_package (L52/L510) — so it reds nothing it ships with.
	while IFS= read -r qualified; do
		[ -n "$qualified" ] || continue
		q_class="${qualified%%::*}"
		q_method="${qualified##*::}"
		q_method="${q_method%'()'}"

		# $q_class / $q_method are interpolated into an ERE unescaped. Unreachable for a
		# namespaced anchor (`Foo\Bar::baz()` would inject `\B`): such a span fails the
		# conjunct loop above, so the `continue` below fires before this matters.
		q_class_line="$(grep -nE "(^|[[:space:]])class[[:space:]]+${q_class}([[:space:]]|\{|$)" "$file_path" | head -n1 | cut -d: -f1 || true)"

		# Searched from the class declaration DOWN, not from the top of the file. Taking
		# the first `function m(` anywhere reds a CORRECT citation whenever an earlier
		# class declares the same method name — the ordinary shape being an interface or
		# abstract base above its implementation in one file:
		#
		#     interface Ability_Interface { public function execute( $input ); }
		#     class WP_Ability implements Ability_Interface { public function execute() {} }
		#
		# The row is right, the first hit lands in the interface, and the reader is sent
		# to re-read a file where the reported fact is not what is wrong. This file's own
		# header argues a check that cries wolf gets switched off, so a false red is a
		# defect here and not a nit.
		q_method_line="$(awk -v a="${q_class_line:-0}" -v m="$q_method" '
			NR > a && $0 ~ ("function[[:space:]]+&?" m "[[:space:]]*\\(") { print NR; exit }
		' "$file_path" || true)"

		# A missing CLASS conjunct is already reported above with a better message, so
		# re-reporting it here would duplicate the finding.
		[ -n "$q_class_line" ] || continue

		# A missing method line now means something the conjunct loop cannot see: the
		# method exists in the file (or the loop would have failed) but not after the
		# class declaration, so it belongs to a different class.
		if [ -z "$q_method_line" ]; then
			anchor_ok=0
			anchor_missing="$anchor_missing $qualified [no $q_method() declaration after class $q_class at L$q_class_line]"
			continue
		fi

		q_boundary="$(awk -v a="$q_class_line" -v b="$q_method_line" '
			NR > a && NR < b && /^[[:space:]]*(final[[:space:]]+|abstract[[:space:]]+|readonly[[:space:]]+)*(class|interface|trait|enum)[[:space:]]/ { print NR; exit }
		' "$file_path" || true)"

		if [ -n "$q_boundary" ]; then
			anchor_ok=0
			anchor_missing="$anchor_missing $qualified [method at L$q_method_line is outside $q_class, which ends at the declaration on L$q_boundary]"
		fi
	done <<< "$qualified_anchors"

	if [ "$anchor_check" -eq 1 ] && [ "$anchor_ok" -eq 0 ]; then
		if is_unanchored_legacy "$id"; then
			add_warning "$id: recorded enclosing context is not a named code symbol and could not be located — predates the anchor requirement, exempt for now"
		else
			add_failure "$id: recorded enclosing symbol not found upstream at or before line $actual_line — $url
	        symbol: $symbol
	        resolved:${anchor_seen:- none}
	        unresolved:${anchor_missing:- none}
	        the snippet is still present, so either it moved into different code or the symbol was renamed — re-read the file and fix the row"
		fi
		continue
	fi

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
# worktree checkouts (a branch that added a registry row absent here would otherwise read
# as a dangling failure against a valid tree), and verify-sources holds this checker's
# own regression harness, whose fixtures necessarily contain example registry IDs — the
# same self-reference exemption bin/verify-sources.sh gets in the orphan scan below.
referenced="$(grep -rhoIE "\\b${ID_REGEX}" \
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
# The patterns capture the whole upstream path, and every filter below runs on the
# MATCHED URL or on the path RELATIVE to the repo root — never on the raw
# `/abs/path:match` grep record. Both distinctions are load-bearing:
#   - filtering the record would drop a third-party citation that merely shares a line
#     with a link to this repo (a comparison-table row);
#   - filtering on the absolute path would silently disable the whole check for anyone
#     whose checkout happens to live under a directory named `dknauss/Sudo`, because
#     the self-link test would then match every record's filename.
#
# Four things are not third-party claims and can never become registry rows:
#   - links to THIS repo (readme badges, the Playground blueprint, docs cross-links)
#   - a tool download in the test-environment installer
#   - this checker's own documentation, which necessarily contains the patterns
#   - this checker's regression harness (tests/verify-sources), whose fixtures cite
#     example raw URLs — excluded by directory, like the checker's own source
#
# A new orphan is a FAIL: the registry is mandatory for third-party citations, and a
# warning-only check leaves that rule unenforced while the scheduled job stays green.
# The pre-existing corpus is grandfathered (warn, not fail) so adopting the rule did not
# turn a migration backlog into a red build on day one.
#
# Grandfathering is keyed on `relative-path<TAB>upstream-URL<TAB>occurrence-count`, i.e.
# per CITATION, not per file and not per source. Keying it on the filename alone would
# have exempted the file forever. Keying it on path+URL alone was not enough either, and
# that was a real hole rather than a theoretical one: the scan dedupes, so a NEW claim
# reusing a URL the file already cites produced an unchanged pair and still exited 0.
# CHANGELOG.md is append-only by construction, so the rule went unenforced exactly where
# citations keep accruing. The count closes it — the N+1th occurrence fails.
#
# Migrating a citation means DECREMENTING the count here. Delete the row only when the
# last citation of that pair is gone: deleting it while other citations remain hard-fails
# them all. The `8` row below is the live case — migrating one of those eight is `8` -> `7`,
# not a deletion.
#
# Shrinks as citations are migrated. The Fortress docs were migrated to FT-* rows (#363),
# so docs/sudo-architecture-comparison-matrix.md (#363) and
# docs/core-sudo-gate-implementation-spec.md (#369) are gone from this list.
#
# The 13 entries split two ways, and the split matters — the immutability rationale
# covers only the first group:
#   - NINE pinned to an immutable commit SHA (bea876d…, abf9109…, c515462…). A registry
#     row cannot protect against drift that cannot happen, so these stay listed because
#     migrating them would buy nothing, not because they are owed a migration.
#   - FOUR that track a MUTABLE ref and are ordinary migration backlog: CHANGELOG.md and
#     tests/Unit/RequestStashTest.php (wordpress-develop `blob/trunk`),
#     docs/abilities-api-assessment.md (abilities-api `blob/trunk`), and
#     docs/two-factor-ecosystem.md (Subversion `/trunk/`). These CAN drift silently,
#     which is exactly what the registry exists to catch.
GRANDFATHERED_CITATIONS="$(cat <<'EOF'
CHANGELOG.md	github.com/WordPress/wordpress-develop/blob/trunk/src/wp-includes/option.php	1
docs/abilities-api-assessment.md	github.com/WordPress/abilities-api/blob/trunk/includes/abilities-api/class-wp-ability.php	1
docs/two-factor-authentication-flow.md	github.com/WordPress/two-factor/blob/bea876d72062626f830c6b39f5348836e58472da/class-two-factor-core.php	8
docs/two-factor-authentication-flow.md	github.com/WordPress/two-factor/blob/bea876d72062626f830c6b39f5348836e58472da/providers/class-two-factor-provider.php	1
docs/two-factor-authentication-flow.md	github.com/WordPress/wordpress-develop/blob/abf9109166099011904710d1e8c63f444d0b862a/src/wp-includes/class-wp-session-tokens.php	1
docs/two-factor-authentication-flow.md	github.com/WordPress/wordpress-develop/blob/abf9109166099011904710d1e8c63f444d0b862a/src/wp-includes/default-filters.php	1
docs/two-factor-authentication-flow.md	github.com/WordPress/wordpress-develop/blob/abf9109166099011904710d1e8c63f444d0b862a/src/wp-includes/pluggable.php	1
docs/two-factor-authentication-flow.md	github.com/WordPress/wordpress-develop/blob/abf9109166099011904710d1e8c63f444d0b862a/src/wp-includes/user.php	1
docs/two-factor-ecosystem.md	plugins.svn.wordpress.org/patchstack/trunk/includes/login.php	2
docs/two-factor-integration.md	github.com/WordPress/two-factor/blob/c515462d51ac92941685e39293673c08538e16c8/class-two-factor-core.php	1
docs/two-factor-integration.md	github.com/WordPress/two-factor/blob/c515462d51ac92941685e39293673c08538e16c8/providers/class-two-factor-backup-codes.php	1
docs/two-factor-integration.md	github.com/WordPress/two-factor/blob/c515462d51ac92941685e39293673c08538e16c8/providers/class-two-factor-totp.php	1
tests/Unit/RequestStashTest.php	github.com/WordPress/wordpress-develop/blob/trunk/src/wp-includes/formatting.php	1
EOF
)"

# Normalize: strip trailing sentence punctuation the URL pattern greedily absorbs, so a
# citation's identity does not change when the prose around it is reworded.
orphan_records="$(grep -roIE \
	-e 'raw\.githubusercontent\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/[A-Za-z0-9_./-]*' \
	-e 'plugins\.svn\.wordpress\.org/[A-Za-z0-9_./-]*' \
	-e 'github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/blob/[A-Za-z0-9_./-]*' \
	--exclude-dir={vendor,node_modules,.git,vendor_test,.tmp,.planning,.claude,verify-sources} \
	"$REPO_ROOT" 2>/dev/null || true)"

# Normalize each grep record to `relative-path<TAB>url`, then collapse to
# `count<TAB>relative-path<TAB>url`: a file that cites the same upstream file eight times
# is one citation to migrate, not eight findings — so it stays one finding — but the
# count is carried rather than discarded, because it is what distinguishes the
# grandfathered occurrences from a newly added one.
orphan_counts="$(while IFS= read -r record; do
	[ -n "$record" ] || continue

	# The matched URL never contains a colon (the patterns carry no scheme), so the
	# last colon splits path from match even if the checkout path itself has colons.
	orphan_url="${record##*:}"
	orphan_path="${record%:*}"
	rel="${orphan_path#"$REPO_ROOT/"}"

	# The scan walks the WORKING TREE, which holds files git does not: `reviewer-approved`,
	# the pre-commit reviewer agent's approval flag, is gitignored and routinely records the
	# upstream URLs the reviewer verified. It made `composer verify:sources` fail for any
	# developer holding a current approval flag while CI — a clean checkout with no flag —
	# stayed green. A checker that reds only on the developer's machine is a checker people
	# learn to run with their eyes closed, which costs more than the rule it enforces.
	# .github/workflows/docs-lint.yml scopes its own scan with `git ls-files` for this reason.
	#
	# The filter is "ignored", not "untracked", on purpose: a doc you have written but not
	# yet committed still has to satisfy the registry rule, or the check would arrive one
	# commit too late to stop the citation. `git check-ignore` consults the index first, so
	# a TRACKED file that also matches an ignore rule stays in scope. Outside a git work
	# tree git exits 128, nothing is skipped, and the scan behaves exactly as it did before
	# — the failure mode here is a false positive, so degrading toward loud is correct.
	#
	# Tests against $orphan_path (the absolute path split off the grep record) rather than
	# the $f in #374, because this loop normalizes records to path+URL pairs before
	# filtering. No apostrophes in this comment: bash 3.2 mis-parses a quote inside a
	# comment inside $( ), the same class of quirk as the leading-paren case patterns below.
	if git -C "$REPO_ROOT" check-ignore -q -- "$orphan_path" 2>/dev/null; then
		continue
	fi

	orphan_url="$(printf '%s' "$orphan_url" | sed -E 's/[).,;:>]+$//')"

	# Self-link: tested against the URL alone, never the filename.
	# The leading `(` on each pattern is required, not style: bash 3.2 mis-parses a
	# bare `pattern)` inside `$( … )` and dies with "syntax error near unexpected token".
	# Anchored to the host, because a bare *dknauss/sudo* substring also discards a
	# THIRD-PARTY url whose PATH happens to contain the repo name — silently dropping a real
	# unregistered citation, which is the failure this scan exists to catch. Every pattern in
	# this scan starts at the host, so matching from the start of the string is exact.
	# No apostrophes in this comment: bash 3.2 mis-parses a quote inside a comment inside $( ).
	case "$(printf '%s' "$orphan_url" | tr '[:upper:]' '[:lower:]')" in
		# The repo NAME must end at a slash or at the string end. A trailing * alone also
		# swallows github.com/dknauss/sudo-tools, a DIFFERENT repository, which would then
		# be dropped as our own instead of failing as an unregistered third-party source.
		(github.com/dknauss/sudo|github.com/dknauss/sudo/*) continue ;;
		(raw.githubusercontent.com/dknauss/sudo|raw.githubusercontent.com/dknauss/sudo/*) continue ;;
		(github.com/dknauss%2fsudo|github.com/dknauss%2fsudo/*) continue ;;
		(raw.githubusercontent.com/dknauss%2fsudo|raw.githubusercontent.com/dknauss%2fsudo/*) continue ;;
	esac

	# Files that carry citation patterns for reasons other than making a claim.
	case "$rel" in
		(docs/archive/*) continue ;;
		(docs/upstream-sources.md|docs/llm-lies-log.md|AGENTS.md|CLAUDE.md) continue ;;
		(bin/verify-sources.sh|bin/install-wp-tests.sh) continue ;;
		(.github/workflows/verify-sources.yml) continue ;;
	esac

	printf '%s\t%s\n' "$rel" "$orphan_url"
done <<< "$orphan_records" | sort | awk -F'\t' '{ n[$0]++ } END { for (k in n) print n[k] "\t" k }' | sort)"

while IFS= read -r counted; do
	[ -n "$counted" ] || continue
	seen="${counted%%	*}"
	pair="${counted#*	}"
	rel="${pair%%	*}"
	orphan_url="${pair#*	}"

	# The third field is how many times this pair is grandfathered. Without it the
	# exemption is keyed to path+URL alone, so a NEW claim reusing an ALREADY-exempt URL
	# in the same file inherits the exemption permanently — the rule goes unenforced in
	# the one file guaranteed to keep growing, since CHANGELOG.md is append-only by
	# construction. Counting rather than line-keying is deliberate: a line-keyed identity
	# would drift on every release and redden the gate for reasons unrelated to citations,
	# and a gate that reddens on a schedule gets re-baselined without being read.
	allowed="$(printf '%s\n' "$GRANDFATHERED_CITATIONS" | awk -F'\t' -v p="$pair" '$1 "\t" $2 == p { print $3 }')"

	if [ -z "$allowed" ]; then
		add_failure "$rel cites $orphan_url outside the registry — add a row to docs/upstream-sources.md and reference its registry ID instead of the raw URL"
	elif [ "$seen" -ne "$allowed" ]; then
		# EXACT, not a ceiling. A count that has FALLEN is not benign: it means a
		# citation was migrated without decrementing the row, leaving a stale allowance
		# that some later claim can spend, so the exemption ends up covering a citation
		# nobody granted it to. Requiring equality also makes the documented decrement
		# step enforced rather than merely recommended.
		#
		# Two limits, stated so neither is mistaken for closure.
		#
		# A true SUBSTITUTION — removing one citation and adding a different claim on the
		# same URL in the same file — leaves the count unchanged, and no count-based
		# scheme can see it.
		#
		# And a count that falls to ZERO is not a shortfall this check can see at all:
		# the loop is driven by OBSERVED pairs, so a pair with no occurrences is never
		# examined and its row survives as a full allowance. That composes back into the
		# hazard above — 1 to 0 (migrated, row not dropped), then a different claim in
		# that file cites that URL again, and equality holds with only a WARN. The
		# shortfall message below still tells the reader to drop the row when the last
		# citation goes, precisely because the tool cannot check that they did.
		#
		# Both close the same way: migrate the pair out of the exemption rather than
		# maintaining its count, which is the end state for every row in this list.
		if [ "$seen" -gt "$allowed" ]; then
			add_failure "$rel cites $orphan_url outside the registry — that pair is grandfathered for $allowed citation(s) but is now cited $seen times, so at least one is new; register it and reference the row ID. Do not raise the count to silence this."
		else
			add_failure "$rel cites $orphan_url $seen time(s) but is grandfathered for $allowed — a citation was migrated without decrementing the count, and the surplus allowance would silently cover a future claim. Decrement it to $seen, or drop the row if the last citation is gone."
		fi
	else
		add_warning "$rel cites $orphan_url outside the registry (grandfathered — migrate as you touch it)"
	fi
done <<< "$orphan_counts"

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
