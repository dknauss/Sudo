#!/usr/bin/env bash
#
# Run the unit suite repeatedly and preserve enough evidence to distinguish a
# test failure from a changing checkout, foreign autoload root, or runner error.
#
# Usage:
#   bash bin/suite-determinism.sh [positive-run-count]
#
# Every invocation creates a separate JSONL file under .tmp/. It deliberately
# completes the requested sample after a red run, then exits nonzero if any run
# failed, errored, changed revision, or loaded WP Sudo from another checkout.

set -uo pipefail

RUNS="${1-50}"
if ! [[ "$RUNS" =~ ^[1-9][0-9]*$ ]]; then
	echo "usage: bash bin/suite-determinism.sh [positive integer]" >&2
	exit 2
fi

ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || {
	echo "cannot resolve the repository root" >&2
	exit 2
}
cd "$ROOT" || exit 2
ROOT="$(pwd -P)"

if [ ! -d "$ROOT/vendor" ] || [ -L "$ROOT/vendor" ]; then
	echo "vendor/ must be a real directory owned by this worktree; run composer install here" >&2
	exit 2
fi

mkdir -p "$ROOT/.tmp" || {
	echo "cannot create $ROOT/.tmp" >&2
	exit 2
}
OUT="$(mktemp "$ROOT/.tmp/suite-determinism.jsonl.XXXXXX")" || {
	echo "cannot create a unique JSONL output file" >&2
	exit 2
}

PHP_VERSION="$(php -r 'echo PHP_VERSION;' 2>/dev/null)" || {
	echo "cannot resolve the PHP version" >&2
	exit 2
}

SAMPLE_HEAD="$(git rev-parse HEAD 2>/dev/null)" || {
	echo "cannot resolve the sample revision" >&2
	exit 2
}

autoload_root() {
	php -d display_errors=stderr -r \
		'require "tests/bootstrap.php"; $r = new ReflectionClass("WP_Sudo\Gate"); echo dirname(dirname((string) $r->getFileName()));' \
		2>/dev/null
}

# Prints two shell words: tracked-dirty untracked-dirty.
tree_state() {
	local entry tracked=0 untracked=0
	while IFS= read -r -d '' entry; do
		if [ "${entry:0:2}" = "??" ]; then
			untracked=1
		else
			tracked=1
		fi
	done < <(git status --porcelain=v1 -z --untracked-files=all)
	printf '%d %d\n' "$tracked" "$untracked"
}

echo "runs=$RUNS  php=$PHP_VERSION  root=$ROOT"
echo "writing $OUT"
echo ""

fails=0
errors=0
contaminated=0
revision_changes=0

for ((i = 1; i <= RUNS; i++)); do
	head_before="$(git rev-parse HEAD 2>/dev/null || printf '<unresolvable>')"
	read -r tracked_before untracked_before <<< "$(tree_state)"

	root_now="$(autoload_root)"
	root_rc=$?
	autoload_ok=0
	if [ "$root_rc" -eq 0 ] && [ -n "$root_now" ] && [ "$root_now" = "$ROOT" ]; then
		autoload_ok=1
	else
		contaminated=$((contaminated + 1))
	fi

	raw_file="$(mktemp "$ROOT/.tmp/suite-determinism.raw.XXXXXX")" || {
		echo "cannot create a runner-output file" >&2
		exit 2
	}
	./vendor/bin/phpunit --do-not-cache-result --colors=never > "$raw_file" 2>&1
	rc=$?

	head_after="$(git rev-parse HEAD 2>/dev/null || printf '<unresolvable>')"
	read -r tracked_after untracked_after <<< "$(tree_state)"
	revision_changed=0
	if [ "$head_before" != "$SAMPLE_HEAD" ] || [ "$head_after" != "$SAMPLE_HEAD" ]; then
		revision_changed=1
		revision_changes=$((revision_changes + 1))
	fi

	summary="$(
		grep -Eo 'OK \([0-9]+ tests?, [0-9]+ assertions?\)|Tests: [0-9]+, Assertions: [0-9]+' "$raw_file" \
			| tail -1
	)"
	tests="$(printf '%s' "$summary" | grep -Eo '[0-9]+ tests?|Tests: [0-9]+' | grep -Eo '[0-9]+' | head -1)"
	assertions="$(printf '%s' "$summary" | grep -Eo '[0-9]+ assertions?|Assertions: [0-9]+' | grep -Eo '[0-9]+' | head -1)"
	failed="$(sed -nE 's/^[0-9]+\) //p' "$raw_file")"

	if [ "$rc" -eq 0 ] && [ -n "$tests" ] && [ "$tests" -gt 0 ]; then
		status="pass"
	elif [ "$rc" -ne 0 ] && [ -n "$failed" ] && [ -n "$tests" ]; then
		status="fail"
		fails=$((fails + 1))
	else
		status="error"
		errors=$((errors + 1))
	fi

	RECORD_RUN="$i" \
	RECORD_STATUS="$status" \
	RECORD_RC="$rc" \
	RECORD_TESTS="$tests" \
	RECORD_ASSERTIONS="$assertions" \
	RECORD_AUTOLOAD_ROOT="$root_now" \
	RECORD_AUTOLOAD_OK="$autoload_ok" \
	RECORD_TRACKED_BEFORE="$tracked_before" \
	RECORD_UNTRACKED_BEFORE="$untracked_before" \
	RECORD_TRACKED_AFTER="$tracked_after" \
	RECORD_UNTRACKED_AFTER="$untracked_after" \
	RECORD_HEAD_BEFORE="$head_before" \
	RECORD_HEAD_AFTER="$head_after" \
	RECORD_REVISION_CHANGED="$revision_changed" \
	RECORD_PHP="$PHP_VERSION" \
	RECORD_RAW_FILE="$raw_file" \
	php -r '
		$nullable_int = static function ( string $name ): ?int {
			$value = getenv( $name );
			return false === $value || "" === $value ? null : (int) $value;
		};
		$bool = static fn( string $name ): bool => "1" === getenv( $name );
		$diagnostic = file_get_contents( (string) getenv( "RECORD_RAW_FILE" ) );
		if ( false === $diagnostic ) {
			fwrite( STDERR, "cannot read the runner-output file\n" );
			exit( 2 );
		}
		preg_match_all( "/^[0-9]+\\) (.+)$/mu", $diagnostic, $matches );
		$record = array(
			"run"                    => (int) getenv( "RECORD_RUN" ),
			"status"                 => getenv( "RECORD_STATUS" ),
			"rc"                     => (int) getenv( "RECORD_RC" ),
			"tests"                  => $nullable_int( "RECORD_TESTS" ),
			"assertions"             => $nullable_int( "RECORD_ASSERTIONS" ),
			"autoload_root"          => getenv( "RECORD_AUTOLOAD_ROOT" ) ?: null,
			"autoload_ok"            => $bool( "RECORD_AUTOLOAD_OK" ),
			"tracked_dirty_before"   => $bool( "RECORD_TRACKED_BEFORE" ),
			"untracked_dirty_before" => $bool( "RECORD_UNTRACKED_BEFORE" ),
			"tracked_dirty_after"    => $bool( "RECORD_TRACKED_AFTER" ),
			"untracked_dirty_after"  => $bool( "RECORD_UNTRACKED_AFTER" ),
			"head_before"            => getenv( "RECORD_HEAD_BEFORE" ),
			"head_after"             => getenv( "RECORD_HEAD_AFTER" ),
			"revision_changed"       => $bool( "RECORD_REVISION_CHANGED" ),
			"php"                    => getenv( "RECORD_PHP" ),
			"failed"                 => $matches[1],
			"diagnostic"             => $diagnostic,
		);
		echo json_encode( $record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
	' >> "$OUT" || {
		echo "cannot append a valid record to $OUT" >&2
		rm -f "$raw_file"
		exit 2
	}
	rm -f "$raw_file"

	printf 'run %-3d %-5s tests=%s assertions=%s' \
		"$i" "$status" "${tests:-?}" "${assertions:-?}"
	[ "$autoload_ok" -eq 1 ] || printf ' AUTOLOAD=%s' "${root_now:-<unresolvable>}"
	[ "$revision_changed" -eq 0 ] || printf ' HEAD-CHANGED'
	printf '\n'
done

echo ""
echo "── summary ─────────────────────────────────────────"
echo "runs:             $RUNS"
echo "failing runs:     $fails"
echo "errored runs:     $errors"
echo "foreign roots:    $contaminated"
echo "revision changes: $revision_changes"

if [ "$fails" -eq 0 ] \
	&& [ "$errors" -eq 0 ] \
	&& [ "$contaminated" -eq 0 ] \
	&& [ "$revision_changes" -eq 0 ]; then
	echo "The observed $RUNS-run sample passed under the environment recorded in $OUT."
	exit 0
fi

exit 1
