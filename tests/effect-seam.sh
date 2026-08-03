#!/usr/bin/env bash
#
# Tests the Core-owned `core.code.package_commit` effect boundary.
#
#   tests/effect-seam.sh <base_url> <wp_env_config> <admin_pass>
#   tests/effect-seam.sh http://localhost:8895 .wp-env.json password
#
# Requires: the Core patch applied (core-patch/apply.sh <config> apply) and
# the capability-floor mu-plugins PARKED. The effect seam replaces
# capability binding rather than supplementing it; leaving the floor active
# denies install_plugins long before the upgrader runs, so the seam would
# never be reached and the test would pass for the wrong reason.

set -uo pipefail

BASE_URL="${1:?base URL required}"
WP_ENV_CONFIG="${2:?wp-env config required}"
ADMIN_PASS="${3:?admin password required}"

PASS=0; FAIL=0
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

wp() { npx @wordpress/env run cli wp "$@" --config "$WP_ENV_CONFIG" 2>/dev/null; }
ok()   { echo "PASS  $1"; PASS=$((PASS+1)); }
bad()  { echo "FAIL  $1"; FAIL=$((FAIL+1)); }
chk()  { [ "$2" = "$3" ] && ok "$1" || bad "$1 (expected $2, got $3)"; }

installed() { wp plugin list --field=name 2>/dev/null | LC_ALL=C grep -c '^effectdemo$' | tr -d ' '; }
cleanup_plugin() { wp plugin delete effectdemo >/dev/null 2>&1; }

python3 - "$TMP" <<'PY'
import sys, zipfile, os
with zipfile.ZipFile(os.path.join(sys.argv[1], "effectdemo.zip"), "w") as z:
    z.writestr("effectdemo/effectdemo.php", "<?php\n/*\nPlugin Name: Effect Demo\n*/\n")
PY

cleanup_plugin

curl -sS -c "$TMP/A.jar" "$BASE_URL/wp-login.php" \
	--data-urlencode "log=admin" --data-urlencode "pwd=$ADMIN_PASS" \
	--data-urlencode "wp-submit=Log In" --data-urlencode "redirect_to=$BASE_URL/wp-admin/" -L -o /dev/null
curl -sS -b "$TMP/A.jar" "$BASE_URL/wp-admin/plugin-install.php?tab=upload" -o "$TMP/up.html"
NONCE=$(LC_ALL=C grep -o 'name="_wpnonce" value="[a-f0-9]*"' "$TMP/up.html" | head -1 | LC_ALL=C sed 's/.*value="//;s/"//')

upload() {
	curl -sS -b "$TMP/A.jar" -X POST "$BASE_URL/wp-admin/update.php?action=upload-plugin" \
		-F "pluginzip=@$TMP/effectdemo.zip" -F "_wpnonce=$NONCE" \
		-F "install-plugin-submit=Install Now" -o "$1"
}

echo "=== 1. interactive install without an approval is refused at the Core seam ==="
upload "$TMP/r1.html"
LC_ALL=C grep -q "requires approval" "$TMP/r1.html" && ok "refused with an approval challenge" || bad "not refused"
chk "nothing was installed" "0" "$(installed)"

echo
echo "=== 2. the refusal carries the digest Core computed from the real bytes ==="
DIGEST=$(LC_ALL=C grep -oE "Approve digest [a-f0-9]{64}" "$TMP/r1.html" | head -1 | awk '{print $3}')
[ ${#DIGEST} -eq 64 ] && ok "refusal discloses a 64-char effect digest" || bad "no digest in refusal"

echo
echo "=== 3. an approval for that exact digest, bound to this session, permits it ==="
VAL=$(LC_ALL=C awk -F'\t' '$6 ~ /^wordpress_logged_in_/ {print $7}' "$TMP/A.jar")
TOKEN=$(python3 -c "import urllib.parse;print(urllib.parse.unquote('''$VAL''').split('|')[2])")
ADMIN_ID=$(wp user get admin --field=ID | LC_ALL=C grep -E '^[0-9]+$')
wp eval "effect_policy_grant('$DIGEST', $ADMIN_ID, '$TOKEN');" >/dev/null 2>&1
upload "$TMP/r2.html"
LC_ALL=C grep -q "Plugin installed successfully" "$TMP/r2.html" && ok "install proceeds with a matching approval" || bad "still refused"
chk "the plugin is really on disk" "1" "$(installed)"

echo
echo "=== 4. the approval is single-use ==="
cleanup_plugin
upload "$TMP/r3.html"
LC_ALL=C grep -q "requires approval" "$TMP/r3.html" && ok "replaying the consumed approval is refused" || bad "replay succeeded"
chk "nothing was installed on replay" "0" "$(installed)"

echo
echo "=== 5. a digest approved for OTHER bytes does not authorise these ==="
# Bind an approval to a digest that is valid in form but belongs to nothing.
wp eval "effect_policy_grant('$(printf 'a%.0s' {1..64})', $ADMIN_ID, '$TOKEN');" >/dev/null 2>&1
upload "$TMP/r4.html"
LC_ALL=C grep -q "requires approval" "$TMP/r4.html" && ok "an unrelated approval does not authorise this effect" || bad "wrong-digest approval was accepted"
chk "nothing was installed" "0" "$(installed)"

echo
echo "=== 6. WP-CLI is gated too — the surface that bypassed everything before ==="
# NOT via wp(), which discards stderr -- the Core refusal is emitted there,
# so routing through the helper silently hides the very message under test.
OUT=$(npx @wordpress/env run cli wp plugin install classic-editor --config "$WP_ENV_CONFIG" 2>&1)
echo "$OUT" | LC_ALL=C grep -q "verified package provenance" && ok "CLI refused as a machine actor" || bad "CLI not refused"
CLI_N=$(wp plugin list --field=name 2>/dev/null | LC_ALL=C grep -c '^classic-editor$' | tr -d ' ')
chk "CLI install did not land" "0" "$CLI_N"

cleanup_plugin

# ---------------------------------------------------------------------
# core.identity.email_change
# ---------------------------------------------------------------------
wp user delete victim --yes --network >/dev/null 2>&1
wp user delete victim --yes >/dev/null 2>&1
wp user create victim victim.old@example.com --role=author --user_pass=x >/dev/null 2>&1
VID=$(wp user get victim --field=ID | LC_ALL=C grep -E '^[0-9]+$')
curl -sS -b "$TMP/A.jar" "$BASE_URL/wp-admin/user-edit.php?user_id=$VID" -o "$TMP/f.html"
UNONCE=$(LC_ALL=C grep -o 'name="_wpnonce" value="[a-f0-9]*"' "$TMP/f.html" | head -1 | LC_ALL=C sed 's/.*value="//;s/"//')

edit_email() {
	curl -sS -b "$TMP/A.jar" -X POST "$BASE_URL/wp-admin/user-edit.php" \
		--data-urlencode "action=update" --data-urlencode "user_id=$VID" \
		--data-urlencode "_wpnonce=$UNONCE" --data-urlencode "email=$1" \
		--data-urlencode "nickname=victim" --data-urlencode "display_name=victim" \
		--data-urlencode "role=author" -o "$2"
}
email_now() { wp user get victim --field=user_email 2>/dev/null | LC_ALL=C grep -E '@'; }
digest_from() { LC_ALL=C grep -oE "Approve digest [a-f0-9]{64}" "$1" | head -1 | awk '{print $3}'; }
grant() { wp eval "effect_policy_grant('$1', $ADMIN_ID, '$TOKEN');" >/dev/null 2>&1; }

echo
echo "=== 7. an admin changing ANOTHER user's email is refused without approval ==="
edit_email "attacker@evil.test" "$TMP/e1.html"
LC_ALL=C grep -q "requires approval" "$TMP/e1.html" && ok "refused at the Core seam" || bad "not refused"
chk "the address is unchanged" "victim.old@example.com" "$(email_now)"

echo
echo "=== 8. the refusal names the exact change, from and to ==="
LC_ALL=C grep -q 'change user #.* email from .* to ' "$TMP/e1.html" \
	&& ok "refusal states the from->to transition" || bad "refusal does not name the transition"

echo
echo "=== 9. approving that exact digest permits it once ==="
grant "$(digest_from "$TMP/e1.html")"
edit_email "attacker@evil.test" "$TMP/e2.html"
chk "the change committed" "attacker@evil.test" "$(email_now)"

echo
echo "=== 10. REDIRECT RESISTANCE: an approval for A->B does not authorise A->C ==="
# The defect class this guards is the one that produced the master-key
# finding earlier in this project: an approval that names less than the
# effect can be spent on a different effect.
edit_email "third@evil.test" "$TMP/e3.html"
grant "$(digest_from "$TMP/e3.html")"      # approval bound to ->third
edit_email "fourth@evil.test" "$TMP/e4.html"   # but submit ->fourth
LC_ALL=C grep -q "requires approval" "$TMP/e4.html" && ok "the ->third approval refuses ->fourth" || bad "REDIRECT HOLE"
chk "no unapproved address was written" "attacker@evil.test" "$(email_now)"

echo
echo "=== 11. and the approved transition still works ==="
edit_email "third@evil.test" "$TMP/e5.html"
chk "the approved change committed" "third@evil.test" "$(email_now)"

echo
echo "=== 12. a machine actor cannot change an email either ==="
OUT2=$(npx @wordpress/env run cli wp user update victim --user_email=cli@evil.test --config "$WP_ENV_CONFIG" 2>&1)
echo "$OUT2" | LC_ALL=C grep -q "Refused: change user" && ok "CLI refused" || bad "CLI not refused"
chk "address unchanged by CLI" "third@evil.test" "$(email_now)"


echo
echo "=== 13. P1 — an admin setting ANOTHER user's password is refused ==="
# The most direct takeover: no email, no reset link, immediate.
set_pass() {
	curl -sS -b "$TMP/A.jar" -X POST "$BASE_URL/wp-admin/user-edit.php" \
		--data-urlencode "action=update" --data-urlencode "user_id=$VID" \
		--data-urlencode "_wpnonce=$UNONCE" --data-urlencode "email=$(email_now)" \
		--data-urlencode "nickname=victim" --data-urlencode "display_name=victim" \
		--data-urlencode "role=author" --data-urlencode "pass1=$1" \
		--data-urlencode "pass2=$1" -o "$2"
}
OLD_HASH=$(wp db query --skip-column-names "SELECT user_pass FROM wp_users WHERE ID = $VID;" 2>/dev/null | LC_ALL=C grep -E '^\$' | head -1)
set_pass "AttackerOwnsYou123!" "$TMP/p1.html"
LC_ALL=C grep -q "requires approval" "$TMP/p1.html" && ok "refused at the Core seam" || bad "not refused"
NEW_HASH=$(wp db query --skip-column-names "SELECT user_pass FROM wp_users WHERE ID = $VID;" 2>/dev/null | LC_ALL=C grep -E '^\$' | head -1)
[ "$OLD_HASH" = "$NEW_HASH" ] && ok "the stored password hash is unchanged" || bad "PASSWORD WAS CHANGED"

echo
echo "=== 14. the refusal names the target, and carries no password material ==="
LC_ALL=C grep -q 'set a new password for user #' "$TMP/p1.html" && ok "refusal names the target user" || bad "target not named"
LC_ALL=C grep -q "AttackerOwnsYou123" "$TMP/p1.html" && bad "PASSWORD LEAKED into the refusal" || ok "no password material in the descriptor or refusal"

echo
echo "=== 15. approving that digest permits the password set ==="
grant "$(digest_from "$TMP/p1.html")"
set_pass "AttackerOwnsYou123!" "$TMP/p2.html"
FINAL_HASH=$(wp db query --skip-column-names "SELECT user_pass FROM wp_users WHERE ID = $VID;" 2>/dev/null | LC_ALL=C grep -E '^\$' | head -1)
[ "$OLD_HASH" != "$FINAL_HASH" ] && ok "the password was set once approved" || bad "still not changed"

echo
echo "=== 16. self-service is NOT gated (or the control is unusable) ==="
# Changing your OWN password must not be challenged: an attacker holding
# the session can already act as that user, so gating it buys nothing and
# would challenge every routine rotation.
PNONCE=$(curl -sS -b "$TMP/A.jar" "$BASE_URL/wp-admin/profile.php" | LC_ALL=C grep -o 'name="_wpnonce" value="[a-f0-9]*"' | head -1 | LC_ALL=C sed 's/.*value="//;s/"//')
curl -sS -b "$TMP/A.jar" -X POST "$BASE_URL/wp-admin/profile.php" \
	--data-urlencode "action=update" --data-urlencode "user_id=$ADMIN_ID" \
	--data-urlencode "_wpnonce=$PNONCE" --data-urlencode "email=admin@example.test" \
	--data-urlencode "nickname=admin" --data-urlencode "display_name=admin" \
	--data-urlencode "pass1=$ADMIN_PASS" --data-urlencode "pass2=$ADMIN_PASS" -o "$TMP/self.html"
LC_ALL=C grep -q "requires approval" "$TMP/self.html" && bad "self-service password change was gated" || ok "self-service password change passes through"

echo
echo "=== 17. login still works — the seam did not break authentication ==="
# wp_set_password() is reached by reset_password() AND rehash-on-login, so
# a seam placed there would break logins. This confirms it was not.
rm -f "$TMP/relogin.jar"
curl -sS -c "$TMP/relogin.jar" "$BASE_URL/wp-login.php" \
	--data-urlencode "log=admin" --data-urlencode "pwd=$ADMIN_PASS" \
	--data-urlencode "wp-submit=Log In" --data-urlencode "redirect_to=$BASE_URL/wp-admin/" -L -o /dev/null
LC_ALL=C grep -q "wordpress_logged_in" "$TMP/relogin.jar" && ok "admin can still log in" || bad "LOGIN BROKEN"

wp user delete victim --yes >/dev/null 2>&1

echo
echo "=== $PASS passed, $FAIL failed ==="
[ "$FAIL" -eq 0 ]
