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
| GB-A | $URL | 2 | needle here | someSymbol | it works |"
fixture "$URL" 200 0 $'first line\nneedle here\nthird line'
run
expect_rc 0
expect_out "all snippets present"

# 2. Duplicate registry IDs must fail.
start "duplicate IDs fail"
new_sandbox dup
registry "$HDR
| GB-A | $URL | 2 | needle here | sym | claim one |
| GB-A | $URL2 | 2 | other thing | sym | claim two |"
fixture "$URL" 200 0 $'x\nneedle here'
fixture "$URL2" 200 0 $'x\nother thing'
run
expect_rc 1
expect_out "GB-A"
expect_out "duplicate"

# 3a. Incomplete row: missing Line.
start "missing Line fails"
new_sandbox noline
registry "$HDR
| GB-A | $URL |  | needle here | sym | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
run
expect_rc 1
expect_out "line"

# 3b. Incomplete row: missing claim.
start "missing claim fails"
new_sandbox noclaim
registry "$HDR
| GB-A | $URL | 2 | needle here | sym |  |"
fixture "$URL" 200 0 $'x\nneedle here'
run
expect_rc 1
expect_out "claim"

# 4. Ambiguous snippet match (>1 occurrence upstream) must fail.
start "ambiguous match fails"
new_sandbox ambig
registry "$HDR
| GB-A | $URL | 2 | needle here | sym | a claim |"
fixture "$URL" 200 0 $'needle here\nneedle here\nneedle here'
run
expect_rc 1
expect_out "ambiguous"

# 5. Snippet vanished from upstream body must fail.
start "vanished snippet fails"
new_sandbox gone
registry "$HDR
| GB-A | $URL | 2 | needle here | sym | a claim |"
fixture "$URL" 200 0 $'nothing to see\nhere at all'
run
expect_rc 1
expect_out "no longer present"

# 6. HTTP 404 (clean curl exit) must fail as a finding, not offline.
start "http 404 fails"
new_sandbox http404
registry "$HDR
| GB-A | $URL | 2 | needle here | sym | a claim |"
fixture "$URL" 404 0 ""
run
expect_rc 1
expect_out "HTTP 404"

# 7. HTTP 404 with a NON-ZERO curl exit (truncated body) must still be a 404
#    finding, not misclassified as a network outage. This is the :118 regression.
start "http 404 with curl error still fails as http"
new_sandbox http404err
registry "$HDR
| GB-A | $URL | 2 | needle here | sym | a claim |"
fixture "$URL" 404 18 ""
run
expect_rc 1
expect_out "HTTP 404"

# 7b. Same case under --offline-ok: an HTTP 404 must NOT be suppressed.
start "http 404 with curl error is not suppressed by --offline-ok"
new_sandbox http404offline
registry "$HDR
| GB-A | $URL | 2 | needle here | sym | a claim |"
fixture "$URL" 404 18 ""
run --offline-ok
expect_rc 1
expect_out "HTTP 404"

# 7c. A transient upstream status (429/5xx) is an availability failure, not drift:
#     it must NOT be reported as a moved/deleted file, and --offline-ok may skip it.
start "transient 5xx is not reported as deleted"
new_sandbox http503
registry "$HDR
| GB-A | $URL | 2 | needle here | sym | a claim |"
fixture "$URL" 503 0 ""
run
expect_rc 1
expect_out "503"
expect_not_out "deleted"

start "transient 5xx suppressed by --offline-ok"
new_sandbox http503offline
registry "$HDR
| GB-A | $URL | 2 | needle here | sym | a claim |"
fixture "$URL" 503 0 ""
run --offline-ok
expect_rc 0
expect_out "SKIPPED"

start "rate-limit 429 suppressed by --offline-ok"
new_sandbox http429offline
registry "$HDR
| GB-A | $URL | 2 | needle here | sym | a claim |"
fixture "$URL" 429 0 ""
run --offline-ok
expect_rc 0

# 7d. A 404 stays permanent drift even under --offline-ok (already covered above,
#     asserted here alongside the 410 gone status).
start "410 Gone is permanent drift, not suppressed by --offline-ok"
new_sandbox http410
registry "$HDR
| GB-A | $URL | 2 | needle here | sym | a claim |"
fixture "$URL" 410 0 ""
run --offline-ok
expect_rc 1
expect_out "410"

# 8. True network outage: fails normally, but --offline-ok skips it.
start "network outage fails without flag"
new_sandbox net
registry "$HDR
| GB-A | $URL | 2 | needle here | sym | a claim |"
fixture "$URL" 000 6 ""
run
expect_rc 1

start "network outage skipped with --offline-ok"
new_sandbox netok
registry "$HDR
| GB-A | $URL | 2 | needle here | sym | a claim |"
fixture "$URL" 000 6 ""
run --offline-ok
expect_rc 0
expect_out "SKIPPED"

# 9. Content failure is preserved even under --offline-ok when another row is
#    genuinely unreachable. (One row 404s, one row is offline.)
start "content failure preserved under --offline-ok"
new_sandbox mixedoffline
registry "$HDR
| GB-A | $URL | 2 | needle here | sym | claim a |
| GB-B | $URL2 | 2 | needle here | sym | claim b |"
fixture "$URL" 404 0 ""
fixture "$URL2" 000 6 ""
run --offline-ok
expect_rc 1
expect_out "HTTP 404"

# 10. Dangling GB-* id referenced in prose but absent from the registry.
start "dangling GB id fails"
new_sandbox dangle
registry "$HDR
| GB-A | $URL | 2 | needle here | sym | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
printf 'see GB-NOPE in docs/upstream-sources.md\n' > "$SANDBOX/docs/note.md"
run
expect_rc 1
expect_out "GB-NOPE"

# 11. Registry ID that does not use the GB- prefix must be rejected, so a
#     dangling reference cannot arise outside the scanned prefix.
start "non-GB registry id rejected"
new_sandbox badprefix
registry "$HDR
| WPCORE-A | $URL | 2 | needle here | sym | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
run
expect_rc 1
expect_out "prefix"

# 12. Self-link filtering: a line with BOTH a self-link and a third-party raw URL
#     must still be reported as an orphan (the third-party citation is not dropped).
start "self-link does not drop co-located third-party citation"
new_sandbox selflink
registry "$HDR
| GB-A | $URL | 2 | needle here | sym | a claim |"
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
| GB-A | $URL | 2 | needle here | sym | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
printf 'see https://github.com/dknauss/Sudo/blob/main/x.php\n' > "$SANDBOX/docs/selfonly.md"
run
expect_rc 0

# 13. Non-raw host URL (rendered blob) is rejected.
start "rendered blob url rejected"
new_sandbox blob
registry "$HDR
| GB-A | https://github.com/WordPress/gutenberg/blob/trunk/x.js | 2 | needle here | sym | a claim |"
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
| GB-A | $URL | 2 | needle here | sym | a claim |"
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
| GB-A | $URL | 2 | needle here | sym | a claim |"
fixture "$URL" 200 0 $'x\nneedle here'
printf 'verified https://raw.githubusercontent.com/WordPress/gutenberg/trunk/z.js\n' \
	> "$SANDBOX/docs/new-note.md"
run
expect_rc 1
expect_out "outside the registry"
expect_out "new-note.md"

# --- summary ---------------------------------------------------------------------
echo
echo "verify-sources tests: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
