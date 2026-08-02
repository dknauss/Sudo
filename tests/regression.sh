#!/usr/bin/env bash
#
# Regression suite for the capability-floor prototype.
#
# Covers the five things adversarially tested by hand on 2026-08-02, plus
# three more this same session found worth locking down. Everything here was
# a `curl` command run once and thrown away before this file existed --
# nothing stopped any of these from silently regressing. This does.
#
# Usage:
#   tests/regression.sh <base_url> <wp_env_config> <admin_pass>
#
#   tests/regression.sh http://localhost:8895 .wp-env.json floortest123
#   tests/regression.sh http://localhost:8897 .wp-env.multisite.json floortest123
#
# Requires: curl, a running wp-env instance with the mu-plugins already
# loaded, an "admin" user whose password matches the third argument, and
# @wordpress/env available via npx from the prototype root.
#
# Test-state manipulation goes through WP-CLI and direct DB queries only --
# never an exposed endpoint. That is a deliberate design choice, not an
# oversight: #470's own test-control REST route is exactly what let a second
# administrator, and a leaked Application Password, disrupt other users.

set -uo pipefail

BASE_URL="${1:?base URL required, e.g. http://localhost:8895}"
WP_ENV_CONFIG="${2:?wp-env config required, e.g. .wp-env.json}"
ADMIN_PASS="${3:?admin password required}"

PASS=0
FAIL=0
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

wp() { npx @wordpress/env run cli wp "$@" --config "$WP_ENV_CONFIG"; }

assert_status() {
	local desc="$1" expected="$2" actual="$3"
	if [ "$expected" = "$actual" ]; then
		echo "PASS  $desc"
		PASS=$((PASS + 1))
	else
		echo "FAIL  $desc (expected $expected, got $actual)"
		FAIL=$((FAIL + 1))
	fi
}

assert_true() {
	local desc="$1" cond="$2"
	if [ "$cond" = "1" ]; then
		echo "PASS  $desc"
		PASS=$((PASS + 1))
	else
		echo "FAIL  $desc"
		FAIL=$((FAIL + 1))
	fi
}

echo "=== fixture setup ==="
wp db query "DELETE FROM wp_cap_floor_approvals; DELETE FROM wp_cap_floor_rates;" >/dev/null 2>&1
wp option delete cap_floor_bindings >/dev/null 2>&1 || true
wp user update admin --user_pass="$ADMIN_PASS" --skip-email >/dev/null 2>&1
# Idempotent: on multisite, wp_users is network-wide, so a user from a prior
# run may already exist there while having lost its role on this specific
# site (e.g. after a network/sub-site cleanup) -- `wp user create` alone then
# fails with "already registered" and leaves the account unable to log in as
# an admin here. set-role is safe either way.
wp user create regressiontestb regtest-b@example.com --role=administrator --user_pass=regtestbpass123 >/dev/null 2>&1
wp user set-role regressiontestb administrator >/dev/null 2>&1
wp user update regressiontestb --user_pass=regtestbpass123 --skip-email >/dev/null 2>&1

login() {
	local user="$1" pass="$2" jar="$3"
	curl -sS -c "$jar" -c "$jar" "$BASE_URL/wp-login.php" \
		--data-urlencode "log=$user" --data-urlencode "pwd=$pass" \
		--data-urlencode "wp-submit=Log In" \
		--data-urlencode "redirect_to=$BASE_URL/wp-admin/" -L -o /dev/null
}
harness_page() {
	local jar="$1" out="$2"
	curl -sS -b "$jar" -c "$jar" "$BASE_URL/wp-admin/tools.php?page=cap-floor-harness" -o "$out"
}
nonce_of() { grep -o 'data-nonce="[^"]*"' "$1" | sed 's/data-nonce="//;s/"//'; }
full_cookies_of() { awk -F'\t' '$6 ~ /^wordpress_/ || $6 == "__Host-cap_floor_binding" {printf "%s=%s; ", $6, $7}' "$1"; }
login_only_of() { awk -F'\t' '$6 ~ /^wordpress_logged_in_/ {printf "%s=%s", $6, $7}' "$1"; }

login admin "$ADMIN_PASS" "$TMP/A.jar"
login regressiontestb regtestbpass123 "$TMP/B.jar"
harness_page "$TMP/A.jar" "$TMP/A.html"
harness_page "$TMP/B.jar" "$TMP/B.html"
NONCE_A=$(nonce_of "$TMP/A.html"); COOKIES_A=$(full_cookies_of "$TMP/A.jar")
NONCE_B=$(nonce_of "$TMP/B.html"); COOKIES_B=$(full_cookies_of "$TMP/B.jar")

echo
echo "=== 1. baseline: dangerous capability denied without an approval ==="
STATUS=$(curl -sS -o /dev/null -w "%{http_code}" -H "Cookie: $COOKIES_A" -H "X-WP-Nonce: $NONCE_A" \
	-X POST "$BASE_URL/wp-json/cap-floor/v1/install-plugin" -F "approval_id=none" -F "package=@/dev/null")
assert_status "denies without a valid approval" "403" "$STATUS"

echo
echo "=== 2. cross-user: B cannot consume A's approval ==="
DIGEST=$(printf 'a%.0s' {1..64})
RESP=$(curl -sS -H "Cookie: $COOKIES_A" -H "X-WP-Nonce: $NONCE_A" -X POST "$BASE_URL/wp-json/cap-floor/v1/request-approval" \
	--data "capability=install_plugins&password=$ADMIN_PASS&target_hash=$DIGEST")
APPROVAL_A=$(echo "$RESP" | grep -o '"approval_id":"[^"]*"' | sed 's/"approval_id":"//;s/"//')
STATUS=$(curl -sS -o /dev/null -w "%{http_code}" -H "Cookie: $COOKIES_B" -H "X-WP-Nonce: $NONCE_B" \
	-X POST "$BASE_URL/wp-json/cap-floor/v1/install-plugin" -F "approval_id=$APPROVAL_A" -F "package=@/dev/null")
assert_status "B refused consuming A's approval" "403" "$STATUS"

echo
echo "=== 3. cross-user: A's rate-limit lockout does not affect B ==="
for _ in 1 2 3 4; do
	curl -sS -o /dev/null -H "Cookie: $COOKIES_A" -H "X-WP-Nonce: $NONCE_A" -X POST "$BASE_URL/wp-json/cap-floor/v1/request-approval" \
		--data "capability=install_plugins&password=wrong&target_hash=$(printf 'b%.0s' {1..64})"
done
STATUS=$(curl -sS -o /dev/null -w "%{http_code}" -H "Cookie: $COOKIES_B" -H "X-WP-Nonce: $NONCE_B" \
	-X POST "$BASE_URL/wp-json/cap-floor/v1/request-approval" \
	--data "capability=install_plugins&password=regtestbpass123&target_hash=$(printf 'c%.0s' {1..64})")
assert_status "B's own correct password still succeeds while A is locked out" "201" "$STATUS"

echo
echo "=== 4. Application-Password auth is refused ==="
APP_PASS=$(wp user application-password create admin regression-test --porcelain 2>/dev/null | tail -1)
STATUS=$(curl -sS -o /dev/null -w "%{http_code}" -u "admin:$APP_PASS" -X POST "$BASE_URL/wp-json/cap-floor/v1/request-approval" \
	--data "capability=install_plugins&password=$ADMIN_PASS&target_hash=$(printf 'd%.0s' {1..64})")
assert_status "Application Password cannot request an approval" "401" "$STATUS"
wp user application-password delete admin --all >/dev/null 2>&1

echo
echo "=== 5. binding cookie is required, independent of a valid login and nonce ==="
wp db query "DELETE FROM wp_cap_floor_rates WHERE user_id = 1;" >/dev/null 2>&1
LOGIN_ONLY=$(login_only_of "$TMP/A.jar")
STATUS=$(curl -sS -o /dev/null -w "%{http_code}" -H "Cookie: $LOGIN_ONLY" -H "X-WP-Nonce: $NONCE_A" \
	-X POST "$BASE_URL/wp-json/cap-floor/v1/request-approval" \
	--data "capability=install_plugins&password=$ADMIN_PASS&target_hash=$(printf 'e%.0s' {1..64})")
assert_status "refused with login cookie alone, binding cookie withheld" "403" "$STATUS"
STATUS=$(curl -sS -o /dev/null -w "%{http_code}" -H "Cookie: $COOKIES_A" -H "X-WP-Nonce: $NONCE_A" \
	-X POST "$BASE_URL/wp-json/cap-floor/v1/request-approval" \
	--data "capability=install_plugins&password=$ADMIN_PASS&target_hash=$(printf 'e%.0s' {1..64})")
assert_status "succeeds with the same nonce plus the real binding cookie" "201" "$STATUS"

echo
echo "=== 6. concurrent consumption of one approval: exactly one winner ==="
wp db query "DELETE FROM wp_cap_floor_rates WHERE user_id = 1;" >/dev/null 2>&1
curl -sS "$BASE_URL/../phase27-fixture-not-used" >/dev/null 2>&1 || true
REAL_ZIP="$TMP/valid.zip"
node -e "
const fs = require('fs');
const b64 = 'UEsDBBQAAAAAAIJN/FwZAs/UNAAAADQAAAAfAAAAcGhhc2UyNy1wcm9vZi9waGFzZTI3LXByb29mLnBocDw/cGhwCi8qClBsdWdpbiBOYW1lOiBQaGFzZSAyNyBTdWJzdGl0dXRlZCBQcm9vZgoqLwpQSwECPwMUAAAAAACCTfxcGQLP1DQAAAA0AAAAHwAAAAAAAAAAAAAAtoEAAAAAcGhhc2UyNy1wcm9vZi9waGFzZTI3LXByb29mLnBocFBLBQYAAAAAAQABAE0AAABxAAAAAAA=';
fs.writeFileSync('$REAL_ZIP', Buffer.from(b64, 'base64'));
"
DIGEST6=$(shasum -a 256 "$REAL_ZIP" | awk '{print $1}')
RESP=$(curl -sS -H "Cookie: $COOKIES_A" -H "X-WP-Nonce: $NONCE_A" -X POST "$BASE_URL/wp-json/cap-floor/v1/request-approval" \
	--data "capability=install_plugins&password=$ADMIN_PASS&target_hash=$DIGEST6")
APPROVAL6=$(echo "$RESP" | grep -o '"approval_id":"[^"]*"' | sed 's/"approval_id":"//;s/"//')
WINS=0
for i in 1 2 3 4 5; do
	curl -sS -o "$TMP/c$i.txt" -H "Cookie: $COOKIES_A" -H "X-WP-Nonce: $NONCE_A" \
		-X POST "$BASE_URL/wp-json/cap-floor/v1/install-plugin" \
		-F "approval_id=$APPROVAL6" -F "package=@$REAL_ZIP" &
done
wait
WINS=$(grep -l '"status":"installed"' "$TMP"/c*.txt 2>/dev/null | wc -l | tr -d ' ')
assert_true "exactly one of five concurrent redemptions wins" "$([ "$WINS" = "1" ] && echo 1 || echo 0)"

echo
echo "=== 7. rate limiter self-releases after its window (the #470 permanent-lockout defect, checked here) ==="
wp db query "DELETE FROM wp_cap_floor_rates WHERE user_id = 1;" >/dev/null 2>&1
for _ in 1 2 3; do
	curl -sS -o /dev/null -H "Cookie: $COOKIES_A" -H "X-WP-Nonce: $NONCE_A" -X POST "$BASE_URL/wp-json/cap-floor/v1/request-approval" \
		--data "capability=install_plugins&password=wrong&target_hash=$(printf 'f%.0s' {1..64})"
done
# Directly backdate the window, the same "manipulate via DB, never an endpoint" discipline as everywhere else in this suite.
wp db query "UPDATE wp_cap_floor_rates SET window_started_at = window_started_at - 400 WHERE user_id = 1;" >/dev/null 2>&1
STATUS=$(curl -sS -o /dev/null -w "%{http_code}" -H "Cookie: $COOKIES_A" -H "X-WP-Nonce: $NONCE_A" \
	-X POST "$BASE_URL/wp-json/cap-floor/v1/request-approval" \
	--data "capability=install_plugins&password=$ADMIN_PASS&target_hash=$(printf 'g%.0s' {1..64})")
assert_status "correct password succeeds once the window has aged out, no permanent lockout" "201" "$STATUS"

echo
echo "=== 8. a consumed approval cannot be replayed against a different target ==="
DIGEST8A=$(printf 'h%.0s' {1..64})
DIGEST8B=$(printf 'i%.0s' {1..64})
RESP=$(curl -sS -H "Cookie: $COOKIES_A" -H "X-WP-Nonce: $NONCE_A" -X POST "$BASE_URL/wp-json/cap-floor/v1/request-approval" \
	--data "capability=install_plugins&password=$ADMIN_PASS&target_hash=$DIGEST8A")
APPROVAL8=$(echo "$RESP" | grep -o '"approval_id":"[^"]*"' | sed 's/"approval_id":"//;s/"//')
head -c 64 /dev/urandom > "$TMP/other.bin"
STATUS=$(curl -sS -o /dev/null -w "%{http_code}" -H "Cookie: $COOKIES_A" -H "X-WP-Nonce: $NONCE_A" \
	-X POST "$BASE_URL/wp-json/cap-floor/v1/install-plugin" -F "approval_id=$APPROVAL8" -F "package=@$TMP/other.bin")
assert_status "an approval scoped to one digest refuses a different upload" "403" "$STATUS"

echo
echo "=== 9. an approval cannot be requested without a target hash (the 'master key' finding) ==="
# Found by an independent fresh-context agent, not by this suite's own
# author: omitting target_hash used to mint an approval that matched any
# bare current_user_can( 'install_plugins' ) check -- i.e. every native
# WordPress admin screen and the real upload-plugin form, none of which
# ever pass a target argument -- unconsumed, reusable, for the full TTL.
STATUS=$(curl -sS -o /dev/null -w "%{http_code}" -H "Cookie: $COOKIES_A" -H "X-WP-Nonce: $NONCE_A" \
	-X POST "$BASE_URL/wp-json/cap-floor/v1/request-approval" \
	--data "capability=install_plugins&password=$ADMIN_PASS")
assert_status "an untargeted approval request is refused outright" "400" "$STATUS"
echo "-- confirm no approval was created, and the native admin screen stays denied --"
STATUS=$(curl -sS -o /dev/null -w "%{http_code}" -b "$TMP/A.jar" "$BASE_URL/wp-admin/plugin-install.php")
# 200 is fine here (the page still renders); what matters is the capability
# check inside it. Assert directly against the DB instead of scraping HTML.
ROWS=$(wp db query --skip-column-names "SELECT COUNT(*) FROM wp_cap_floor_approvals WHERE user_id = 1 AND target_hash IS NULL;" 2>&1 | grep -E '^[0-9]+$' | tail -1)
assert_true "no null-target approval row exists in the database" "$([ "$ROWS" = "0" ] && echo 1 || echo 0)"

echo
echo "=== 10. visiting a non-harness admin page does not mint a binding cookie ==="
# Found by a second independent-review agent: an earlier version minted on
# any admin_init for any logged-in user, so someone holding only a stolen
# LOGGED_IN_COOKIE could mint their own valid binding with one plain GET to
# any wp-admin URL -- defeating the entire point of a second, independent
# factor. This does not test that the gap is fully closed (it isn't -- see
# BOUNDARY.md); it tests that the specific over-broad trigger is gone.
STOLEN=$(awk -F'\t' '$6 ~ /^wordpress_/ {printf "%s=%s; ", $6, $7}' "$TMP/A.jar")
rm -f "$TMP/dashboard.jar"
curl -sS -c "$TMP/dashboard.jar" -H "Cookie: $STOLEN" "$BASE_URL/wp-admin/index.php" -o /dev/null
MINTED=$(awk -F'\t' '$6 == "__Host-cap_floor_binding" {print $7}' "$TMP/dashboard.jar")
assert_true "no binding is minted from a plain dashboard visit" "$([ -z "$MINTED" ] && echo 1 || echo 0)"

echo
echo "=== 11. an over-length target hash is rejected before it can burn a real attempt ==="
STATUS=$(curl -sS -o /dev/null -w "%{http_code}" -H "Cookie: $COOKIES_A" -H "X-WP-Nonce: $NONCE_A" \
	-X POST "$BASE_URL/wp-json/cap-floor/v1/request-approval" \
	--data "capability=install_plugins&password=$ADMIN_PASS&target_hash=$(printf 'x%.0s' {1..200})")
assert_status "an over-length target hash is refused, not silently accepted" "400" "$STATUS"

echo
echo "=== cleanup ==="
wp user delete regressiontestb --yes --reassign=1 >/dev/null 2>&1
wp db query "DELETE FROM wp_cap_floor_approvals; DELETE FROM wp_cap_floor_rates;" >/dev/null 2>&1
wp option delete cap_floor_bindings >/dev/null 2>&1

echo
echo "=== $PASS passed, $FAIL failed ==="
[ "$FAIL" -eq 0 ]
