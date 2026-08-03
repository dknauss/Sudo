#!/usr/bin/env bash
#
# Installs / reverts the `core.code.package_commit` effect boundary in a
# running wp-env instance.
#
#   core-patch/apply.sh <wp_env_config> apply
#   core-patch/apply.sh <wp_env_config> revert    # mutation test
#   core-patch/apply.sh <wp_env_config> status
#
# Thin wrapper only: everything with any logic in it lives in patch.php and
# runs inside the container, so there is no bash-inside-php-inside-docker
# escaping to get wrong.

set -uo pipefail

CONFIG="${1:?wp-env config required, e.g. .wp-env.json}"
MODE="${2:-status}"
HERE="$(cd "$(dirname "$0")" && pwd)"

# The wordpress container for THIS config. wp-env names containers after a
# hash of the project directory, so match on the mapped port rather than a
# name we would have to guess.
PORT=$(python3 -c "import json,sys;print(json.load(open('$HERE/../$CONFIG')).get('port',8888))")
CONTAINER=$(docker ps --format '{{.Names}}\t{{.Ports}}' | grep ":${PORT}->" | grep -- '-wordpress-1' | cut -f1 | head -1)

if [ -z "$CONTAINER" ]; then
	echo "FAIL: no running wordpress container publishing port $PORT (is wp-env started for $CONFIG?)" >&2
	exit 1
fi

echo "container: $CONTAINER (port $PORT)"

if [ "$MODE" = "apply" ]; then
	docker cp "$HERE/effect-authorization.php" "$CONTAINER:/var/www/html/wp-includes/effect-authorization.php" >/dev/null
	# Load it alongside the other wp-includes requires. Idempotent.
	docker exec "$CONTAINER" php -r '
		$f = "/var/www/html/wp-settings.php";
		$s = file_get_contents( $f );
		if ( strpos( $s, "effect-authorization" ) !== false ) { echo "wp-settings already wired\n"; exit; }
		$needle = "require ABSPATH . WPINC . \"/capabilities.php\";";
		$alt    = "require ABSPATH . WPINC . \x27/capabilities.php\x27;";
		$use    = strpos( $s, $alt ) !== false ? $alt : $needle;
		if ( strpos( $s, $use ) === false ) { fwrite(STDERR, "FAIL: capabilities.php require not found in wp-settings.php\n"); exit(1); }
		$s = str_replace( $use, $use . "\nrequire ABSPATH . WPINC . \x27/effect-authorization.php\x27;", $s );
		file_put_contents( $f, $s );
		echo "wp-settings wired\n";
	'
fi

docker cp "$HERE/patch.php" "$CONTAINER:/tmp/patch.php" >/dev/null
docker exec "$CONTAINER" php /tmp/patch.php "$MODE"
