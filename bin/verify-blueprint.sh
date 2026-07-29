#!/usr/bin/env bash
#
# Verify that the stable Playground demo installs the published release asset.

set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
BLUEPRINT="${1:-$ROOT/blueprint.json}"

php -r '
$path = $argv[1];
$raw  = @file_get_contents( $path );
if ( false === $raw ) {
	fwrite( STDERR, "Blueprint is not readable: {$path}\n" );
	exit( 1 );
}

try {
	$blueprint = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
} catch ( JsonException $error ) {
	fwrite( STDERR, "Blueprint must be valid JSON: {$error->getMessage()}\n" );
	exit( 1 );
}

$first_party = array();
foreach ( $blueprint["steps"] ?? array() as $step ) {
	$url = $step["pluginData"]["url"] ?? "";
	if (
		"installPlugin" === ( $step["step"] ?? "" )
		&& is_string( $url )
		&& str_contains( $url, "github.com/dknauss/Sudo/" )
	) {
		$first_party[] = $step;
	}
}

if ( 1 !== count( $first_party ) ) {
	fwrite( STDERR, "blueprint.json must contain exactly one first-party dknauss/Sudo installPlugin step.\n" );
	exit( 1 );
}

$plugin_data = $first_party[0]["pluginData"] ?? array();
$url         = $plugin_data["url"] ?? "";
$proxy       = "https://wordpress-playground-cors-proxy.net/?";
$asset       = "#^https://github\\.com/dknauss/Sudo/releases/download/v[0-9]+\\.[0-9]+\\.[0-9]+/wp-sudo\\.zip$#";

if ( "url" !== ( $plugin_data["resource"] ?? "" ) ) {
	fwrite( STDERR, "The first-party install must use pluginData.resource=url.\n" );
	exit( 1 );
}
if ( ! str_starts_with( $url, $proxy ) ) {
	fwrite( STDERR, "The GitHub Release asset must use the WordPress Playground CORS proxy.\n" );
	exit( 1 );
}
if ( 1 !== preg_match( $asset, substr( $url, strlen( $proxy ) ) ) ) {
	fwrite( STDERR, "The stable demo must install the dknauss/Sudo GitHub Release asset releases/download/vX.Y.Z/wp-sudo.zip, not a source archive.\n" );
	exit( 1 );
}

echo "Stable blueprint install source verified: published GitHub Release asset.\n";
' "$BLUEPRINT"
