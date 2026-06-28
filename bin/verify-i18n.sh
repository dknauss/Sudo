#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
POT_FILE="${ROOT_DIR}/languages/wp-sudo.pot"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT
TMP_POT="${TMP_DIR}/wp-sudo.pot"

if [[ ! -f "${POT_FILE}" ]]; then
	cat >&2 <<'MSG'
Error: languages/wp-sudo.pot is missing.
Run `composer i18n:make-pot` and commit the generated POT file.
MSG
	exit 1
fi

"${ROOT_DIR}/bin/make-pot.sh" "${TMP_POT}" >/dev/null

if ! diff -u "${POT_FILE}" "${TMP_POT}" >/dev/null; then
	cat >&2 <<'MSG'
Error: languages/wp-sudo.pot is stale.
Run `composer i18n:make-pot`, review the diff, and commit the updated POT file.

Diff:
MSG
	diff -u "${POT_FILE}" "${TMP_POT}" >&2 || true
	exit 1
fi

printf 'i18n verified: languages/wp-sudo.pot is in sync.\n'
