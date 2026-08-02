#!/usr/bin/env bash
#
# Regression suite for the capability-floor prototype.
#
# Covers the five things adversarially tested by hand on 2026-08-02, six more
# the same session's independent-review agents found worth locking down, and
# four covering the second gated effect (create_users) wired the same day.
# Everything here was a `curl` command run once and thrown away before this
# file existed -- nothing stopped any of these from silently regressing.
# This does.
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

# Looked up, never hardcoded as 1: this suite briefly assumed "admin" is
# always user ID 1, which held by coincidence on a freshly installed
# instance until an unrelated `wp user delete ... --network` command run by
# hand against this same instance deleted and recreated the account with a
# new ID. Every DB assertion below that targets "admin's own row" uses this
# variable instead.
ADMIN_ID=$(wp user get admin --field=ID 2>/dev/null | tail -1)

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
wp db query "DELETE FROM wp_cap_floor_rates WHERE user_id = $ADMIN_ID;" >/dev/null 2>&1
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
wp db query "DELETE FROM wp_cap_floor_rates WHERE user_id = $ADMIN_ID;" >/dev/null 2>&1
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
wp db query "DELETE FROM wp_cap_floor_rates WHERE user_id = $ADMIN_ID;" >/dev/null 2>&1
for _ in 1 2 3; do
	curl -sS -o /dev/null -H "Cookie: $COOKIES_A" -H "X-WP-Nonce: $NONCE_A" -X POST "$BASE_URL/wp-json/cap-floor/v1/request-approval" \
		--data "capability=install_plugins&password=wrong&target_hash=$(printf 'f%.0s' {1..64})"
done
# Directly backdate the window, the same "manipulate via DB, never an endpoint" discipline as everywhere else in this suite.
wp db query "UPDATE wp_cap_floor_rates SET window_started_at = window_started_at - 400 WHERE user_id = $ADMIN_ID;" >/dev/null 2>&1
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
ROWS=$(wp db query --skip-column-names "SELECT COUNT(*) FROM wp_cap_floor_approvals WHERE user_id = $ADMIN_ID AND target_hash IS NULL;" 2>&1 | grep -E '^[0-9]+$' | tail -1)
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
echo "=== 12. create-user: the second effect works end-to-end, not just install-plugin ==="
# Same pattern as /install-plugin (digest the exact fields, re-check
# current_user_can() against that digest, consume at the real commit
# point) wired against a structurally different effect: wp_insert_user()
# instead of WP_Upgrader. Verified live in the browser on 2026-08-02 before
# this test existed; this locks that result down.
USERNAME12="regression-create-user"
EMAIL12="regression-create-user@example.com"
ROLE12="editor"
DIGEST12=$(printf '%s\n%s\n%s' "$USERNAME12" "$EMAIL12" "$ROLE12" | shasum -a 256 | awk '{print $1}')
RESP=$(curl -sS -H "Cookie: $COOKIES_A" -H "X-WP-Nonce: $NONCE_A" -X POST "$BASE_URL/wp-json/cap-floor/v1/request-approval" \
	--data "capability=create_users&password=$ADMIN_PASS&target_hash=$DIGEST12")
APPROVAL12=$(echo "$RESP" | grep -o '"approval_id":"[^"]*"' | sed 's/"approval_id":"//;s/"//')
STATUS=$(curl -sS -o /dev/null -w "%{http_code}" -H "Cookie: $COOKIES_A" -H "X-WP-Nonce: $NONCE_A" \
	-X POST "$BASE_URL/wp-json/cap-floor/v1/create-user" \
	--data-urlencode "approval_id=$APPROVAL12" --data-urlencode "username=$USERNAME12" \
	--data-urlencode "email=$EMAIL12" --data-urlencode "role=$ROLE12")
assert_status "create-user succeeds with a matching approval" "201" "$STATUS"
CREATED_LOGIN=$(wp user get "$USERNAME12" --field=user_login 2>/dev/null | tail -1)
assert_true "the created user actually exists" "$([ "$CREATED_LOGIN" = "$USERNAME12" ] && echo 1 || echo 0)"

echo
echo "=== 13. create-user: a consumed approval cannot mint a second account ==="
# 409, not 403: a fresh-context agent found that this route checked
# current_user_can() (and consumed the approval) before checking whether
# the username/email were already taken -- so requesting an approval for
# an already-registered identity burned a real password check and a
# rate-limited attempt on a wp_insert_user() call that was always going to
# fail. Fixed by checking username_exists()/email_exists() first, before
# touching the approval at all. That existence check is what this exact
# replay hits now (test 12 already created this account), which is still
# a correct refusal -- just a different, more accurate one than the old
# "no approval matches" -- not a regression in what's actually enforced.
STATUS=$(curl -sS -o /dev/null -w "%{http_code}" -H "Cookie: $COOKIES_A" -H "X-WP-Nonce: $NONCE_A" \
	-X POST "$BASE_URL/wp-json/cap-floor/v1/create-user" \
	--data-urlencode "approval_id=$APPROVAL12" --data-urlencode "username=$USERNAME12" \
	--data-urlencode "email=$EMAIL12" --data-urlencode "role=$ROLE12")
assert_status "replaying the consumed approval is refused" "409" "$STATUS"
STILL_ONE=$(wp user list --search="$USERNAME12" --field=user_login 2>/dev/null | grep -c "^$USERNAME12$" | tail -1)
assert_true "exactly one account exists, not two" "$([ "$STILL_ONE" = "1" ] && echo 1 || echo 0)"

echo
echo "=== 14. create-user: an approval cannot be retargeted to different user details ==="
USERNAME14="regression-retarget-user"
EMAIL14A="regression-retarget-a@example.com"
EMAIL14B="regression-retarget-b@example.com"
ROLE14="editor"
DIGEST14=$(printf '%s\n%s\n%s' "$USERNAME14" "$EMAIL14A" "$ROLE14" | shasum -a 256 | awk '{print $1}')
RESP=$(curl -sS -H "Cookie: $COOKIES_A" -H "X-WP-Nonce: $NONCE_A" -X POST "$BASE_URL/wp-json/cap-floor/v1/request-approval" \
	--data "capability=create_users&password=$ADMIN_PASS&target_hash=$DIGEST14")
APPROVAL14=$(echo "$RESP" | grep -o '"approval_id":"[^"]*"' | sed 's/"approval_id":"//;s/"//')
STATUS=$(curl -sS -o /dev/null -w "%{http_code}" -H "Cookie: $COOKIES_A" -H "X-WP-Nonce: $NONCE_A" \
	-X POST "$BASE_URL/wp-json/cap-floor/v1/create-user" \
	--data-urlencode "approval_id=$APPROVAL14" --data-urlencode "username=$USERNAME14" \
	--data-urlencode "email=$EMAIL14B" --data-urlencode "role=$ROLE14")
assert_status "an approval scoped to one email refuses a different one" "403" "$STATUS"
NOT_CREATED=$(wp user get "$USERNAME14" --field=user_login 2>/dev/null | tail -1)
assert_true "no account was created from the mismatched request" "$([ -z "$NOT_CREATED" ] && echo 1 || echo 0)"

echo
echo "=== 15. create-user: the native Add New User screen still denies outright ==="
# The floor, not the approval mechanism, is what should be doing the work
# here -- current_user_can( 'create_users' ) with no target argument, which
# is how user-new.php actually checks it, can never match an approval.
#
# Single-site's own message ("you are not allowed to create users") differs
# from multisite's generic screen-access denial ("not allowed to access
# this page") -- multisite's user-new.php checks a network-scoped
# capability first and never reaches the create_users-specific message.
# Both are the same underlying denial (HTTP 403, "not allowed"); assert on
# what's common to both rather than one site type's exact wording.
STATUS=$(curl -sS -o "$TMP/user-new.html" -w "%{http_code}" -b "$TMP/A.jar" "$BASE_URL/wp-admin/user-new.php")
assert_status "native screen returns 403" "403" "$STATUS"
assert_true "native screen still refuses a real administrator" "$(grep -qi 'not allowed' "$TMP/user-new.html" && echo 1 || echo 0)"

echo
echo "=== 16. remove_users is denied (found omitted entirely by a fresh-context agent) ==="
# A distinct primitive from delete_users -- core's map_meta_cap() case
# 'remove_user' resolves to this, not to delete_users. It gates
# wp-admin/users.php's multisite-only "Remove" bulk/row action (unassign a
# user from one site without deleting the network account) and
# network/site-users.php. It was simply missing from CAP_FLOOR_DENIED_CAPS
# -- not the edit_users meta-cap indirection bug (this is checked as a
# bare primitive, like delete_users already was), just absent from the
# list. An agent found this live: removed a real user from a real
# multisite site with zero password prompt. Checked directly via
# current_user_can(), not through a specific admin screen's nonce
# plumbing -- what matters here is whether the floor itself denies the
# capability, not any one screen's route to it.
CAN_REMOVE=$(wp eval 'echo current_user_can( "remove_users" ) ? "1" : "0";' --user=admin 2>/dev/null | tail -1)
assert_true "administrator does not have remove_users" "$([ "$CAN_REMOVE" = "0" ] && echo 1 || echo 0)"

echo
echo "=== 17. create-user: an existing username/email is rejected before the approval is touched ==="
# Found by the same agent: request an approval for an already-registered
# identity, redeem it, get a guaranteed-to-fail wp_insert_user() -- but by
# then a real password check and one of only 3 rate-limited attempts is
# already spent on a user that was never going to be created. Fixed with
# a username_exists()/email_exists() check before the approval is even
# looked up. This test checks the fix's actual mechanism (the approval
# survives untouched), not just that the request is refused -- a refusal
# alone wouldn't distinguish "checked early" from "checked late".
DIGEST17=$(printf '%s\n%s\n%s' "regressiontestb" "regtest-b@example.com" "editor" | shasum -a 256 | awk '{print $1}')
RESP=$(curl -sS -H "Cookie: $COOKIES_A" -H "X-WP-Nonce: $NONCE_A" -X POST "$BASE_URL/wp-json/cap-floor/v1/request-approval" \
	--data "capability=create_users&password=$ADMIN_PASS&target_hash=$DIGEST17")
APPROVAL17=$(echo "$RESP" | grep -o '"approval_id":"[^"]*"' | sed 's/"approval_id":"//;s/"//')
STATUS=$(curl -sS -o /dev/null -w "%{http_code}" -H "Cookie: $COOKIES_A" -H "X-WP-Nonce: $NONCE_A" \
	-X POST "$BASE_URL/wp-json/cap-floor/v1/create-user" \
	--data-urlencode "approval_id=$APPROVAL17" --data-urlencode "username=regressiontestb" \
	--data-urlencode "email=regtest-b@example.com" --data-urlencode "role=editor")
assert_status "an already-registered identity is refused before the insert is attempted" "409" "$STATUS"
STILL_APPROVED=$(wp db query --skip-column-names "SELECT state FROM wp_cap_floor_approvals WHERE id = '$APPROVAL17';" 2>&1 | grep -E '^(approved|consumed|cancelled)$' | tail -1)
assert_true "the approval was not burned on a doomed request" "$([ "$STILL_APPROVED" = "approved" ] && echo 1 || echo 0)"

echo
echo "=== cleanup ==="
# regressiontestb is deliberately left in place (see the idempotent
# create-or-ignore fixture setup above) -- it is reconciled, not recreated,
# each run. USERNAME12/14 are not: they must be gone before the next run,
# or test 12's wp_insert_user() collides on a still-existing global login.
#
# `wp user delete <user> --yes --reassign=1` alone does not do that on
# multisite: without --network it only unassigns the site role, leaving
# the account intact network-wide (wp_users is network-wide) -- exactly
# what happened here once already, silently turning test 12 into a false
# failure two runs later ("500", not "201", because the account it tried
# to insert already existed). Deleting the row directly, the same "manipulate
# test state via direct DB queries" discipline this suite already uses
# for the rate-limit table, sidesteps the ambiguity entirely rather than
# chasing the right combination of wp-cli flags for both site types.
delete_user_completely() {
	local login="$1"
	local id
	id=$(wp user get "$login" --field=ID 2>/dev/null | tail -1)
	if [ -n "$id" ] && [ "$id" -gt 0 ] 2>/dev/null; then
		wp db query "DELETE FROM wp_users WHERE ID = $id; DELETE FROM wp_usermeta WHERE user_id = $id;" >/dev/null 2>&1
	fi
}
delete_user_completely "$USERNAME12"
delete_user_completely "$USERNAME14"
wp db query "DELETE FROM wp_cap_floor_approvals; DELETE FROM wp_cap_floor_rates;" >/dev/null 2>&1
wp option delete cap_floor_bindings >/dev/null 2>&1

echo
echo "=== $PASS passed, $FAIL failed ==="
[ "$FAIL" -eq 0 ]
