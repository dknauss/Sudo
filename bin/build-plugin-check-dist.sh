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
	mu-plugin \
	LICENSE \
	readme.txt \
	uninstall.php \
	wp-sudo.php; do
	if [ -e "$path" ]; then
		cp -R "$path" "$DEST/"
	fi
done

if [ -d languages ]; then
	while IFS= read -r file; do
		relative="${file#languages/}"
		mkdir -p "$DEST/languages/$(dirname "$relative")"
		cp "$file" "$DEST/languages/$relative"
	done < <(find languages -type f ! -name '.*' -print)
fi

echo "Built Plugin Check dist at $DEST"
