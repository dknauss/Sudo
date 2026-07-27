#!/usr/bin/env bash
#
# Regression tests for bin/verify-sources.sh.
#
# The checker itself does live HTTP against raw.githubusercontent.com and enforces
# a host allowlist, so it cannot be unit-tested by pointing it at localhost. Instead
# each case builds a throwaway "repo" ($SANDBOX) whose bin/verify-sources.sh is a
# symlink to the real script — so REPO_ROOT and SOURCES_FILE resolve inside the
# sandbox — and puts a fake `curl` first on PATH. The fake curl is driven entirely by
# a per-case manifest, so every HTTP status, curl exit code, and body is deterministic
# and offline. No production code is modified to make this testable (per CLAUDE.md:
# "No Test-Environment Shims in Production").
#
# Run: bash tests/verify-sources/run.sh   (or: composer test:sources)

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REAL_SCRIPT="$(cd "$SELF_DIR/../../bin" && pwd)/verify-sources.sh"

if [ ! -f "$REAL_SCRIPT" ]; then
	echo "FATAL: cannot find $REAL_SCRIPT" >&2
	exit 1
fi

pass=0
fail=0
CURRENT=""

TMP_ROOT="$(mktemp -d)"
trap 'rm -rf "$TMP_ROOT"' EXIT

# --- fake curl -------------------------------------------------------------------
# Emulates `curl -sSL --max-time N -o <path> -w '%{http_code}' <url>`: writes the
# fixture body to the -o path, prints the fixture HTTP status on stdout, and exits
# with the fixture exit code. Driven by a tab-separated manifest:
#   <url>\t<http_status>\t<exit_code>\t<body_file_or_->
# A url with no manifest row behaves like an unreachable host: status 000, exit 6.
FAKE_BIN="$TMP_ROOT/fakebin"
mkdir -p "$FAKE_BIN"
cat > "$FAKE_BIN/curl" <<'FAKE'
#!/usr/bin/env bash
out=""
url=""
while [ $# -gt 0 ]; do
	case "$1" in
		-o) out="$2"; shift 2 ;;
		-w) shift 2 ;;
		--max-time) shift 2 ;;
		-sSL|-s|-S|-L) shift ;;
		http://*|https://*) url="$1"; shift ;;
		*) shift ;;
	esac
done
row="$(grep -F -- "$url	" "$FAKE_CURL_MANIFEST" 2>/dev/null | head -n1)"
if [ -z "$row" ]; then
	printf '000'
	exit 6
fi
status="$(printf '%s' "$row" | cut -f2)"
exitc="$(printf '%s' "$row" | cut -f3)"
body="$(printf '%s' "$row" | cut -f4)"
if [ -n "$out" ] && [ "$body" != "-" ] && [ -f "$body" ]; then
	cat "$body" > "$out"
fi
printf '%s' "$status"
exit "$exitc"
FAKE
chmod +x "$FAKE_BIN/curl"
export PATH="$FAKE_BIN:$PATH"

# --- harness helpers -------------------------------------------------------------

# new_sandbox NAME -> sets SANDBOX, wires bin/verify-sources.sh symlink + manifest.
# Test IO (manifest, fixture bodies) lives OUTSIDE the sandbox so it is never picked
# up by the checker's repo-wide orphan / dangling-id scans, which walk REPO_ROOT.
new_sandbox() {
	SANDBOX="$TMP_ROOT/sandbox/$1"
	IO_DIR="$TMP_ROOT/io/$1"
	rm -rf "$SANDBOX" "$IO_DIR"
	mkdir -p "$SANDBOX/bin" "$SANDBOX/docs" "$IO_DIR"
	ln -s "$REAL_SCRIPT" "$SANDBOX/bin/verify-sources.sh"
	: > "$IO_DIR/manifest.tsv"
	export FAKE_CURL_MANIFEST="$IO_DIR/manifest.tsv"
}

# fixture URL STATUS EXIT [BODY_TEXT]
fixture() {
	local url="$1" status="$2" exitc="$3" body="${4-}"
	local bodyfile="-"
	if [ -n "$body" ]; then
		bodyfile="$IO_DIR/body.$(printf '%s' "$url" | cksum | tr -d ' ')"
		printf '%s\n' "$body" > "$bodyfile"
	fi
	printf '%s\t%s\t%s\t%s\n' "$url" "$status" "$exitc" "$bodyfile" >> "$IO_DIR/manifest.tsv"
}

registry() { printf '%s\n' "$1" > "$SANDBOX/docs/upstream-sources.md"; }

# run [args...] -> sets RC and OUT
run() {
	OUT="$(bash "$SANDBOX/bin/verify-sources.sh" "$@" 2>&1)"
	RC=$?
}

start() { CURRENT="$1"; }

expect_rc() {
	if [ "$RC" = "$1" ]; then
		pass=$((pass + 1))
	else
		fail=$((fail + 1))
		echo "FAIL: $CURRENT"
		echo "  expected exit $1, got $RC"
		echo "  output: $OUT"
	fi
}

expect_out() {
	if printf '%s' "$OUT" | grep -qF -- "$1"; then
		pass=$((pass + 1))
	else
		fail=$((fail + 1))
		echo "FAIL: $CURRENT"
		echo "  output did not contain: $1"
		echo "  output: $OUT"
	fi
}

expect_not_out() {
	if printf '%s' "$OUT" | grep -qF -- "$1"; then
		fail=$((fail + 1))
		echo "FAIL: $CURRENT"
		echo "  output unexpectedly contained: $1"
		echo "  output: $OUT"
	else
		pass=$((pass + 1))
	fi
}

HDR='| ID | Raw URL | Line | Must contain | Enclosing symbol | Claim it supports |
|---|---|---|---|---|---|'
URL='https://raw.githubusercontent.com/WordPress/gutenberg/trunk/packages/x.js'
URL2='https://raw.githubusercontent.com/WordPress/gutenberg/trunk/packages/y.js'

# --- cases -----------------------------------------------------------------------

# 1. Happy path: snippet present at the recorded line.
start "happy path passes"
new_sandbox happy
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`first\` | it works |"
fixture "$URL" 200 0 $'first line\nneedle here\nthird line'
run
expect_rc 0
expect_out "all snippets present"

# 2. Duplicate registry IDs must fail.
start "duplicate IDs fail"
new_sandbox dup
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | claim one |
| GB-ONE | $URL2 | 2 | other thing | \`x\` | claim two |"
fixture "$URL" 200 0 $'x\nneedle here'
fixture "$URL2" 200 0 $'x\nother thing'
run
expect_rc 1
expect_out "GB-ONE"
expect_out "duplicate"

# 3a. Incomplete row: missing Line.
start "missing Line fails"
new_sandbox noline
registry "$HDR
| GB-ONE | $URL |  | needle here | \`x\` | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
run
expect_rc 1
expect_out "line"

# 3b. Incomplete row: missing claim.
start "missing claim fails"
new_sandbox noclaim
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` |  |"
fixture "$URL" 200 0 $'x\nneedle here'
run
expect_rc 1
expect_out "claim"

# 4. Ambiguous snippet match (>1 occurrence upstream) must fail.
start "ambiguous match fails"
new_sandbox ambig
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 200 0 $'needle here\nneedle here\nneedle here'
run
expect_rc 1
expect_out "ambiguous"

# 4b. The Enclosing symbol column must carry at least one backticked anchor token, and
#     that token must still exist upstream at or before the matched line. Without this
#     the column is decorative: it was used only in error messages, so an upstream
#     rename or deletion of the enclosing component left the row silently green.
start "symbol anchor missing upstream fails"
new_sandbox anchorgone
registry "$HDR
| GB-ONE | $URL | 3 | needle here | \`SomeComponent\` | a claim |"
fixture "$URL" 200 0 $'line one\nline two\nneedle here'
run
expect_rc 1
expect_out "enclosing symbol"

start "symbol anchor appearing only AFTER the match fails"
new_sandbox anchorafter
registry "$HDR
| GB-ONE | $URL | 1 | needle here | \`SomeComponent\` | a claim |"
fixture "$URL" 200 0 $'needle here\nfunction SomeComponent() {}'
run
expect_rc 1
expect_out "enclosing symbol"

start "symbol anchor before the match passes"
new_sandbox anchorok
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`SomeComponent\` | a claim |"
fixture "$URL" 200 0 $'function SomeComponent() {\nneedle here\n}'
run
expect_rc 0
expect_out "all snippets present"

start "symbol column with no backticked anchor token fails"
new_sandbox noanchor
registry "$HDR
| GB-ONE | $URL | 2 | needle here | the header area | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
run
expect_rc 1
expect_out "backtick"

# 5. Snippet vanished from upstream body must fail.
start "vanished snippet fails"
new_sandbox gone
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 200 0 $'nothing to see\nhere at all'
run
expect_rc 1
expect_out "no longer present"

# 6. HTTP 404 (clean curl exit) must fail as a finding, not offline.
start "http 404 fails"
new_sandbox http404
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 404 0 ""
run
expect_rc 1
expect_out "HTTP 404"

# 7. HTTP 404 with a NON-ZERO curl exit (truncated body) must still be a 404
#    finding, not misclassified as a network outage. This is the :118 regression.
start "http 404 with curl error still fails as http"
new_sandbox http404err
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 404 18 ""
run
expect_rc 1
expect_out "HTTP 404"

# 7b. Same case under --offline-ok: an HTTP 404 must NOT be suppressed.
start "http 404 with curl error is not suppressed by --offline-ok"
new_sandbox http404offline
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 404 18 ""
run --offline-ok
expect_rc 1
expect_out "HTTP 404"

# 7c. A transient upstream status (429/5xx) is an availability failure, not drift:
#     it must NOT be reported as a moved/deleted file, and --offline-ok may skip it.
start "transient 5xx is not reported as deleted"
new_sandbox http503
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 503 0 ""
run
expect_rc 1
expect_out "503"
expect_not_out "deleted"

start "transient 5xx suppressed by --offline-ok"
new_sandbox http503offline
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 503 0 ""
run --offline-ok
expect_rc 0
expect_out "SKIPPED"

start "rate-limit 429 suppressed by --offline-ok"
new_sandbox http429offline
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 429 0 ""
run --offline-ok
expect_rc 0

# 7d. A 404 stays permanent drift even under --offline-ok (already covered above,
#     asserted here alongside the 410 gone status).
start "410 Gone is permanent drift, not suppressed by --offline-ok"
new_sandbox http410
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 410 0 ""
run --offline-ok
expect_rc 1
expect_out "410"

# 7e. A 2xx whose body transfer was truncated/reset (nonzero curl exit) must NOT be
#     accepted: the partial body can still contain the snippet, falsely verifying the
#     citation. The anchor token is deliberately present in the partial body so this
#     case can ONLY fail via the truncation guard — otherwise it would pass for the
#     wrong reason (a missing anchor) and survive removal of the guard.
start "truncated 2xx body is not accepted as verified"
new_sandbox trunc200
registry "$HDR
| GB-ONE | $URL | 1 | needle here | \`needle\` | a claim |"
fixture "$URL" 200 18 $'needle here\npartial'
run
expect_rc 1
expect_out "incomplete transfer"
expect_not_out "all snippets present"

start "truncated 2xx body is an availability failure, suppressed by --offline-ok"
new_sandbox trunc200offline
registry "$HDR
| GB-ONE | $URL | 1 | needle here | \`needle\` | a claim |"
fixture "$URL" 200 18 $'needle here\npartial'
run --offline-ok
expect_rc 0
expect_out "SKIPPED"

# 7f. A partial body must not be CACHED. Two rows citing the same URL: if the truncated
#     fetch leaves its file in the cache dir, the second row reads that partial file as
#     a cache hit and verifies against it. Live analogue: three registry rows share
#     complementary-area/index.js, so one truncated fetch would silently "verify" two
#     other claims.
start "truncated 2xx body is not cached for a second row on the same URL"
new_sandbox trunc200cache
registry "$HDR
| GB-ONE | $URL | 1 | needle here | \`needle\` | claim one |
| GB-TWO | $URL | 1 | needle here | \`needle\` | claim two |"
fixture "$URL" 200 18 $'needle here\npartial'
run
expect_rc 1
expect_out "GB-ONE"
expect_out "GB-TWO"

# 8. True network outage: fails normally, but --offline-ok skips it.
start "network outage fails without flag"
new_sandbox net
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 000 6 ""
run
expect_rc 1

start "network outage skipped with --offline-ok"
new_sandbox netok
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 000 6 ""
run --offline-ok
expect_rc 0
expect_out "SKIPPED"

# 9. Content failure is preserved even under --offline-ok when another row is
#    genuinely unreachable. (One row 404s, one row is offline.)
start "content failure preserved under --offline-ok"
new_sandbox mixedoffline
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | claim a |
| GB-TWO | $URL2 | 2 | needle here | \`x\` | claim b |"
fixture "$URL" 404 0 ""
fixture "$URL2" 000 6 ""
run --offline-ok
expect_rc 1
expect_out "HTTP 404"

# 10. Dangling GB-* id referenced in prose but absent from the registry.
start "dangling GB id fails"
new_sandbox dangle
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
printf 'see GB-NOPE in docs/upstream-sources.md\n' > "$SANDBOX/docs/note.md"
run
expect_rc 1
expect_out "GB-NOPE"

# 11. Registry ID that does not use the GB- prefix must be rejected, so a
#     dangling reference cannot arise outside the scanned prefix.
start "registry id outside the known prefixes is rejected"
new_sandbox badprefix
registry "$HDR
| WPCORE-A | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
run
expect_rc 1
expect_out "invalid registry ID"

# 11a. Both prefixes in the closed set are accepted. FT- rows are live on main (the
#      Snicco Fortress docs), so a change that narrowed ID_REGEX back to GB- only would
#      break them; this pins the set rather than leaving it to review.
start "FT- prefix is accepted"
new_sandbox ftprefix
registry "$HDR
| FT-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
run
expect_rc 0
expect_out "all snippets present"

start "dangling FT- reference is detected"
new_sandbox ftdangle
registry "$HDR
| FT-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
printf 'see FT-NOPE in docs/upstream-sources.md\n' > "$SANDBOX/docs/note.md"
run
expect_rc 1
expect_out "FT-NOPE"

# 11b. An ID matching the GB- prefix but NOT the reference-scan regex (lowercase, or
#      too short) must be rejected: prose using it would never be scanned, so deleting
#      the row would leave an undetected dangling citation.
start "GB- id that the reference scan cannot match is rejected"
new_sandbox lowerid
registry "$HDR
| GB-a | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
run
expect_rc 1
expect_out "GB-a"

start "single-character GB- id is rejected"
new_sandbox shortid
registry "$HDR
| GB-X | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
run
expect_rc 1
expect_out "GB-X"

# 12. Self-link filtering: a line with BOTH a self-link and a third-party raw URL
#     must still be reported as an orphan (the third-party citation is not dropped).
start "self-link does not drop co-located third-party citation"
new_sandbox selflink
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
printf 'compare https://github.com/dknauss/Sudo/blob/main/x.php with https://raw.githubusercontent.com/WordPress/gutenberg/trunk/z.js\n' \
	> "$SANDBOX/docs/compare.md"
run
expect_rc 1
expect_out "outside the registry"
expect_out "compare.md"

# 12b. A line with ONLY a self-link is not reported.
start "pure self-link is not an orphan"
new_sandbox selfonly
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
printf 'see https://github.com/dknauss/Sudo/blob/main/x.php\n' > "$SANDBOX/docs/selfonly.md"
run
expect_rc 0

# 12c. The self-link filter must test the matched URL, not the whole `path:url` grep
#      record — otherwise a checkout whose own directory path contains `dknauss/Sudo`
#      (e.g. ~/dknauss/Sudo) silently drops every third-party orphan.
start "self-link filter is not fooled by a repo-named checkout path"
SANDBOX="$TMP_ROOT/sandbox/dknauss/Sudo"
IO_DIR="$TMP_ROOT/io/pathname"
rm -rf "$SANDBOX" "$IO_DIR"
mkdir -p "$SANDBOX/bin" "$SANDBOX/docs" "$IO_DIR"
ln -s "$REAL_SCRIPT" "$SANDBOX/bin/verify-sources.sh"
: > "$IO_DIR/manifest.tsv"
export FAKE_CURL_MANIFEST="$IO_DIR/manifest.tsv"
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
printf 'see https://raw.githubusercontent.com/WordPress/gutenberg/trunk/z.js\n' > "$SANDBOX/docs/newcite.md"
run
expect_rc 1
expect_out "newcite.md"

# The one genuinely grandfathered pair used by 12d-12f. It must stay identical to a row
# in GRANDFATHERED_CITATIONS, or these cases silently stop testing grandfathering at all —
# which is exactly the defect 12d carried: its "legacy" URL was
# raw.githubusercontent.com/WordPress/two-factor/master/a.php, matching no grandfathered
# pair, so both its lines were ordinary orphans and it exited 1 however grandfathering
# behaved. A test that cannot fail for its stated reason is indistinguishable from a
# passing one.
GF_URL="https://github.com/WordPress/two-factor/blob/c515462d51ac92941685e39293673c08538e16c8/class-two-factor-core.php"

# 12d. Grandfathering is per CITATION, not per file: a NEW upstream URL added to a
#      grandfathered file must still be a hard failure, while the legacy citation is
#      only warned about. Both halves are asserted — rc alone cannot tell them apart.
start "new citation in a grandfathered file still fails"
new_sandbox grandnew
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
mkdir -p "$SANDBOX/docs"
printf 'legacy %s\nbrand new https://raw.githubusercontent.com/WordPress/gutenberg/trunk/z.js\n' "$GF_URL" \
	> "$SANDBOX/docs/two-factor-integration.md"
run
expect_rc 1
expect_out "z.js"
expect_out "grandfathered"

# 12e. The exemption is keyed to path+URL, and the scan dedupes, so without a recorded
#      occurrence count a NEW claim reusing an ALREADY-grandfathered URL inherits the
#      exemption forever. CHANGELOG.md is append-only by construction, which is where
#      that bites. The N+1th citation of a grandfathered pair is a hard failure.
start "a second citation of a grandfathered url is a hard failure"
new_sandbox grandcount
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
mkdir -p "$SANDBOX/docs"
printf 'legacy %s\nnew claim reusing the same source %s\n' "$GF_URL" "$GF_URL" \
	> "$SANDBOX/docs/two-factor-integration.md"
run
expect_rc 1
expect_out "cited 2 times"

# 12f. The counterpart guard: the recorded number of citations must still only warn, or
#      the fix for 12e would redden every grandfathered file on arrival.
start "a grandfathered url within its recorded count still only warns"
new_sandbox grandok
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
mkdir -p "$SANDBOX/docs"
printf 'legacy %s\n' "$GF_URL" > "$SANDBOX/docs/two-factor-integration.md"
run
expect_rc 0
expect_out "grandfathered"

# 12g. The self-link filter is a substring test, so a THIRD-PARTY url whose path merely
#       contains the repo name is discarded as if it were our own — silently dropping a
#       real unregistered citation. Only the owner/repo segment of a known host counts.
start "third-party url containing the repo name is still an orphan"
new_sandbox selflinkpath
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
printf 'see https://raw.githubusercontent.com/WordPress/gutenberg/trunk/dknauss/Sudo/z.js\n' > "$SANDBOX/docs/thirdparty.md"
run
expect_rc 1
expect_out "thirdparty.md"

# 13. Non-raw host URL (rendered blob) is rejected.
start "rendered blob url rejected"
new_sandbox blob
registry "$HDR
| GB-ONE | https://github.com/WordPress/gutenberg/blob/trunk/x.js | 2 | needle here | \`x\` | a claim |"
run
expect_rc 1
expect_out "raw-text source"

# 14. Gitignored working-tree files are outside the orphan scan. The live case is
#     `reviewer-approved`, the pre-commit reviewer agent's flag: it is gitignored, never
#     committed, and routinely records the upstream URLs the reviewer verified. Scanning
#     it failed on every developer machine holding a current approval while CI, on a
#     clean checkout, passed. Needs a real git work tree, so this sandbox gets `git init`.
start "gitignored file is not an orphan"
new_sandbox gitignored
git -C "$SANDBOX" init -q
printf 'reviewer-approved\n' > "$SANDBOX/.gitignore"
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
printf 'verified https://raw.githubusercontent.com/WordPress/gutenberg/trunk/z.js\n' \
	> "$SANDBOX/reviewer-approved"
run
expect_rc 0

# 14b. The same git work tree, same URL, in a file nothing ignores: still a failure.
#      Without this, case 14 would also pass if the fix had disabled the scan outright.
#      Untracked and uncommitted on purpose — the rule has to bite before the commit,
#      not one commit later.
start "non-ignored file in a git work tree is still an orphan"
new_sandbox gitkept
git -C "$SANDBOX" init -q
printf 'reviewer-approved\n' > "$SANDBOX/.gitignore"
registry "$HDR
| GB-ONE | $URL | 2 | needle here | \`x\` | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
printf 'verified https://raw.githubusercontent.com/WordPress/gutenberg/trunk/z.js\n' \
	> "$SANDBOX/docs/new-note.md"
run
expect_rc 1
expect_out "outside the registry"
expect_out "new-note.md"

# 15. A symbol written `foo()` must match a definition written `foo( $args )`.
#     The registry records functions in the conventional shorthand with empty parens;
#     upstream defines them with parameters. A fixed-string search for the literal
#     `foo()` therefore matches only prose mentions (`@see foo()`) and never the
#     definition — which inverts the check: it passes on documentation and fails on
#     the code it is meant to anchor to. Real instance: GB-GRANTSA-NOCHECK recorded
#     `grant_super_admin()`, and `grep -F 'grant_super_admin()'` returns nothing
#     against wp-includes/capabilities.php while the definition sits at L1215.
start "symbol foo() matches a definition written foo( \$args )"
new_sandbox parenshorthand
registry "$HDR
| GB-FN | $URL | 4 | needle here | \`do_thing()\` | a claim |"
fixture "$URL" 200 0 $'x\nfunction do_thing( $arg ) {\ny\nneedle here'
run
expect_rc 0

# 15b. And the check still bites when the symbol genuinely is not in the file, so
#      15 cannot be satisfied by dropping the anchor check altogether.
start "symbol absent from the file is still a failure"
new_sandbox parenabsent
registry "$HDR
| GB-FN | $URL | 4 | needle here | \`missing_thing()\` | a claim |"
fixture "$URL" 200 0 $'x\nfunction do_thing( $arg ) {\ny\nneedle here'
run
expect_rc 1
expect_out "enclosing symbol"

# 15c. A `foo()` anchor asserts the DECLARATION. A call site is a weaker witness than
#      the claim, so an earlier call must not satisfy it. Live case: GB-AUTOUPDATER-UPGRADE,
#      whose declaration sits at L362. The old bare `foo(` needle is a PREFIX and matched
#      L195, `public function should_update(` — a DIFFERENT method ending in the anchor
#      name, above the snippet — so the row passed having never seen the function it names.
start "foo() anchor is not satisfied by a call site alone"
new_sandbox declcall
registry "$HDR
| GB-FN | $URL | 4 | needle here | \`do_thing()\` | a claim |"
fixture "$URL" 200 0 $'x\n\tdo_thing( $arg );\ny\nneedle here'
run
expect_rc 1
expect_out "enclosing symbol"

# 15d. The counterpart: a real declaration above the snippet still passes, so 15c is not
#      satisfied by refusing every function anchor. All 16 `()` anchors in the live
#      registry have a `function NAME(` declaration at or before their cited line, so
#      requiring one tightens without reddening any existing row.
start "foo() anchor is satisfied by a declaration even with a call site present"
new_sandbox declok
registry "$HDR
| GB-FN | $URL | 5 | needle here | \`do_thing()\` | a claim |"
fixture "$URL" 200 0 $'function do_thing( $arg ) {\n}\n\tdo_thing( 1 );\ny\nneedle here'
run
expect_rc 0

# 15e. Multiple code spans in an anchor cell are a CONJUNCTION, not alternatives. The
#      rows express containment — "`X` in `Y`" — so ORing them means either half alone
#      satisfies the check, and the more carefully a row is disambiguated the weaker its
#      check becomes. All spans must resolve.
start "every code span in an anchor cell must resolve"
new_sandbox anchorand
registry "$HDR
| GB-FN | $URL | 4 | needle here | \`present_thing\` in \`absent_thing\` | a claim |"
fixture "$URL" 200 0 $'x\npresent_thing\ny\nneedle here'
run
expect_rc 1
expect_out "enclosing symbol"

# 15f. And the conjunction passes when both halves are genuinely present, so 15e cannot
#      be satisfied by failing every multi-span row.
start "a conjunction anchor passes when all spans resolve"
new_sandbox anchorandok
registry "$HDR
| GB-FN | $URL | 5 | needle here | \`present_thing\` in \`outer_thing\` | a claim |"
fixture "$URL" 200 0 $'outer_thing\nx\npresent_thing\ny\nneedle here'
run
expect_rc 0

# 15g. A JSX element anchor is written closed (`<Foo>`) while the source carries an open
#      tag with props (`<Foo scope={ x }>`). This is a USAGE anchor — GB-ICON-SWAP claims
#      the toggle is "rendered inside `<PinnedItems>`" — so a usage-shaped witness IS the
#      claim, not a proxy for it. Contrast 15c, where a call site is a weaker witness than
#      the declaration being asserted.
start "JSX element anchor matches an open tag carrying props"
new_sandbox jsxopen
registry "$HDR
| GB-FN | $URL | 4 | needle here | \`<Foo>\` | a claim |"
fixture "$URL" 200 0 $'x\n<Foo scope={ scope }>\ny\nneedle here'
run
expect_rc 0

# 16. A multiword anchor must be searched whole. Unquoted command substitution
#     word-splits it, so `## Relationship between the timeouts` becomes five needles
#     and the generic `##` matches ANY heading — the check then passes vacuously for
#     every markdown source, which fails OPEN and is worse than the false positives
#     the needle forms were added to fix.
start "multiword anchor absent from the file still fails"
new_sandbox multiword
registry "$HDR
| GB-HDR | $URL | 3 | needle here | \`## Missing Heading\` | a claim |"
fixture "$URL" 200 0 $'## Other Heading\nx\nneedle here'
run
expect_rc 1
expect_out "enclosing symbol"

# 16b. And a multiword anchor that IS present still passes, so 16 cannot be satisfied
#      by refusing every anchor containing a space.
start "multiword anchor present in the file passes"
new_sandbox multiwordok
registry "$HDR
| GB-HDR | $URL | 3 | needle here | \`## Real Heading\` | a claim |"
fixture "$URL" 200 0 $'## Real Heading\nx\nneedle here'
run
expect_rc 0

# 17. A row listed as pre-dating the anchor requirement WARNS rather than failing, so
#     the requirement can ship before every existing row satisfies it. Without this the
#     check is red on arrival against the registry in the same commit — and a gate that
#     is red on arrival is one people learn to ignore, which is the harm the checker
#     exists to prevent.
start "unanchored row listed as legacy warns, does not fail"
new_sandbox unanchored_legacy
registry "$HDR
| FT-EULA | $URL | 2 | needle here | clause 3.1 of some document | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
run
expect_rc 0
expect_out "predates the anchor requirement"

# 17b. An unanchored row NOT on that list still fails, so 17 cannot be satisfied by
#      dropping the anchor requirement altogether.
start "unanchored row not listed still fails"
new_sandbox unanchored_new
registry "$HDR
| FT-BRANDNEW | $URL | 2 | needle here | clause 3.1 of some document | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
run
expect_rc 1
expect_out "anchor token"

# 18. A legacy-exempt row is exempt from the ANCHOR check only — its snippet must
#     still be verified. Skipping the rest of verification would turn a narrow
#     exemption into a total one, so a legacy row whose upstream snippet vanished
#     would pass silently. That is the same fail-open shape as the word-splitting bug.
start "legacy-exempt row still fails when its snippet is gone"
new_sandbox legacy_snippet_gone
registry "$HDR
| FT-EULA | $URL | 2 | needle here | clause 3.1 of some document | a claim |"
fixture "$URL" 200 0 $'x\nsomething else entirely'
run
expect_rc 1
expect_out "snippet no longer present upstream"

# 18b. And it still passes when the snippet IS present, so 18 cannot be satisfied by
#      failing every legacy row.
start "legacy-exempt row passes when its snippet is present"
new_sandbox legacy_snippet_ok
registry "$HDR
| FT-EULA | $URL | 2 | needle here | clause 3.1 of some document | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
run
expect_rc 0

# --- summary ---------------------------------------------------------------------
echo
echo "verify-sources tests: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
