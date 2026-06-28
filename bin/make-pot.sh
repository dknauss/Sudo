#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DESTINATION="${1:-languages/wp-sudo.pot}"
if [[ "${DESTINATION}" = /* ]]; then
	DESTINATION_PATH="${DESTINATION}"
else
	DESTINATION_PATH="${ROOT_DIR}/${DESTINATION}"
fi

if ! command -v wp >/dev/null 2>&1; then
	cat >&2 <<'MSG'
Error: WP-CLI is required to generate the translation template.
Install WP-CLI from https://wp-cli.org/ and retry.
MSG
	exit 127
fi

if ! wp i18n make-pot --help >/dev/null 2>&1; then
	cat >&2 <<'MSG'
Error: WP-CLI i18n commands are not available.
Install or update WP-CLI so `wp i18n make-pot` is available, then retry.
MSG
	exit 127
fi

mkdir -p "$(dirname "${DESTINATION_PATH}")"

wp i18n make-pot "${ROOT_DIR}" "${DESTINATION_PATH}" \
	--slug=wp-sudo \
	--domain=wp-sudo \
	--package-name='Sudo – Admin Action Gating' \
	--include='wp-sudo.php,uninstall.php,includes,bridges,mu-plugin,admin/js' \
	--exclude='vendor,node_modules,.git,.github,.tmp,tests,docs,.planning,.wordpress-org,assets,languages,coverage,coverage.xml,playwright-report,test-results' \
	--headers='{"Report-Msgid-Bugs-To":"https://github.com/dknauss/Sudo/issues","Language-Team":"LANGUAGE <LL@li.org>","POT-Creation-Date":""}' \
	--file-comment=$'Copyright (C) 2026 Dan Knauss\nThis file is distributed under the GPL-2.0-or-later.' \
	--quiet
