#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SBOM_FILE="${ROOT_DIR}/.sbom/bom.json"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT
TMP_SBOM="${TMP_DIR}/bom.json"
NORMALIZED_COMMITTED="${TMP_DIR}/committed.json"
NORMALIZED_GENERATED="${TMP_DIR}/generated.json"

if [[ ! -f "${SBOM_FILE}" ]]; then
	cat >&2 <<'MSG'
Error: .sbom/bom.json is missing.
Run `composer sbom` and commit the generated SBOM.
MSG
	exit 1
fi

(
	cd "${ROOT_DIR}"
	composer CycloneDX:make-sbom \
		--output-file="${TMP_SBOM}" \
		--output-format=JSON \
		--spec-version=1.6 \
		--output-reproducible \
		--mc-version=dev-main \
	>/dev/null
)

# The generator records the checkout's Git commit on the root component and the
# Composer executable version in its tool metadata. Neither describes a locked
# dependency: the commit necessarily changes when the SBOM is committed, PR CI
# checks out a synthetic merge commit, and the runner's Composer patch can move.
# Normalize only those environmental fields. Dependency components and references
# remain byte-for-byte verified.
normalize_environment_metadata() {
	php -r '
		$data = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
		foreach ( array_keys( $data["metadata"]["tools"] ?? array() ) as $index ) {
			$tool = &$data["metadata"]["tools"][ $index ];
			if ( ( $tool["name"] ?? "" ) === "composer" && ! isset( $tool["vendor"] ) ) {
				$tool["version"] = "<normalized-composer-version>";
			}
			unset( $tool );
		}
		foreach ( array_keys( $data["metadata"]["component"]["properties"] ?? array() ) as $index ) {
			$property = &$data["metadata"]["component"]["properties"][ $index ];
			if ( in_array( $property["name"] ?? "", array( "cdx:composer:package:distReference", "cdx:composer:package:sourceReference" ), true ) ) {
				$property["value"] = "<normalized-root-git-reference>";
			}
			unset( $property );
		}
		file_put_contents( $argv[2], json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL );
	' "${1}" "${2}"
}

normalize_environment_metadata "${SBOM_FILE}" "${NORMALIZED_COMMITTED}"
normalize_environment_metadata "${TMP_SBOM}" "${NORMALIZED_GENERATED}"

if ! diff -u "${NORMALIZED_COMMITTED}" "${NORMALIZED_GENERATED}" >/dev/null; then
	cat >&2 <<'MSG'
Error: .sbom/bom.json is stale.
Run `composer sbom`, review the diff, and commit the updated SBOM.

Diff (environment-derived metadata normalized):
MSG
	diff -u "${NORMALIZED_COMMITTED}" "${NORMALIZED_GENERATED}" >&2 || true
	exit 1
fi

printf 'SBOM verified: .sbom/bom.json is in sync.\n'
