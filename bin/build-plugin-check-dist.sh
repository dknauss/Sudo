#!/usr/bin/env bash
set -euo pipefail

DEST="${1:-.tmp/plugin-check/wp-sudo}"

rm -rf "$DEST"
mkdir -p "$DEST"

for path in \
	admin \
	assets \
	bridges \
	includes \
	languages \
	mu-plugin \
	LICENSE \
	readme.txt \
	uninstall.php \
	wp-sudo.php; do
	if [ -e "$path" ]; then
		cp -R "$path" "$DEST/"
	fi
done

echo "Built Plugin Check dist at $DEST"
