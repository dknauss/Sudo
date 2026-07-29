#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SBOM_FILE="${ROOT_DIR}/.sbom/bom.json"
BACKUP_FILE="$(mktemp)"
OUTPUT_FILE="$(mktemp)"
trap 'cp "${BACKUP_FILE}" "${SBOM_FILE}"; rm -f "${BACKUP_FILE}" "${OUTPUT_FILE}"' EXIT

cp "${SBOM_FILE}" "${BACKUP_FILE}"

if ! "${ROOT_DIR}/bin/verify-sbom.sh" >"${OUTPUT_FILE}" 2>&1; then
	printf 'FAIL: current SBOM should pass verification.\n' >&2
	cat "${OUTPUT_FILE}" >&2
	exit 1
fi

php -r '
	$data = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
	$data["metadata"]["tools"][0]["version"] = "different-composer-version";
	$root_reference = $data["metadata"]["component"]["bom-ref"];
	$data["metadata"]["component"]["bom-ref"] = "different-root-bom-reference";
	foreach ( $data["dependencies"] as &$dependency ) {
		if ( $dependency["ref"] === $root_reference ) {
			$dependency["ref"] = "different-root-bom-reference";
		}
	}
	unset( $dependency );
	foreach ( $data["metadata"]["component"]["properties"] as &$property ) {
		if ( in_array( $property["name"], array( "cdx:composer:package:distReference", "cdx:composer:package:sourceReference" ), true ) ) {
			$property["value"] = "different-checkout-reference";
		}
	}
	unset( $property );
	file_put_contents( $argv[1], json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL );
' "${SBOM_FILE}"

if ! "${ROOT_DIR}/bin/verify-sbom.sh" >"${OUTPUT_FILE}" 2>&1; then
	printf 'FAIL: environment-only SBOM differences should be normalized.\n' >&2
	cat "${OUTPUT_FILE}" >&2
	exit 1
fi

cp "${BACKUP_FILE}" "${SBOM_FILE}"

php -r '
	$data = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
	$data["components"][0]["version"] = "stale-test-version";
	file_put_contents( $argv[1], json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL );
' "${SBOM_FILE}"

if "${ROOT_DIR}/bin/verify-sbom.sh" >"${OUTPUT_FILE}" 2>&1; then
	printf 'FAIL: stale SBOM should fail verification.\n' >&2
	exit 1
fi

if ! grep -q 'composer sbom' "${OUTPUT_FILE}"; then
	printf 'FAIL: stale-SBOM error should name the regeneration command.\n' >&2
	cat "${OUTPUT_FILE}" >&2
	exit 1
fi

printf 'verify-sbom regression tests passed.\n'
