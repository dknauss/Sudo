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
MAX_RUNS=10000
if ! [[ "$RUNS" =~ ^[1-9][0-9]*$ ]] \
	|| [ "${#RUNS}" -gt "${#MAX_RUNS}" ] \
	|| { [ "${#RUNS}" -eq "${#MAX_RUNS}" ] && [[ "$RUNS" > "$MAX_RUNS" ]]; }; then
	echo "usage: bash bin/suite-determinism.sh [integer from 1 to $MAX_RUNS]" >&2
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

autoload_path() {
	php -d display_errors=stderr -r \
		'require "tests/bootstrap.php"; $r = new ReflectionClass("WP_Sudo\Gate"); echo realpath((string) $r->getFileName());' \
		2>/dev/null
}

# Prints three shell words: tracked-dirty untracked-dirty state-hash.
tree_state() {
	local state_file index_file worktree_file
	state_file="$(mktemp "${TMPDIR:-/tmp}/wp-sudo-tree-state.XXXXXX")" || return 1
	index_file="$(mktemp "${TMPDIR:-/tmp}/wp-sudo-index-state.XXXXXX")" || {
		rm -f "$state_file"
		return 1
	}
	worktree_file="$(mktemp "${TMPDIR:-/tmp}/wp-sudo-worktree-state.XXXXXX")" || {
		rm -f "$state_file" "$index_file"
		return 1
	}
	if ! git status --porcelain=v1 -z --untracked-files=all > "$state_file"; then
		rm -f "$state_file" "$index_file" "$worktree_file"
		return 1
	fi
	if ! git diff --cached --binary --no-ext-diff --no-renames > "$index_file"; then
		rm -f "$state_file" "$index_file" "$worktree_file"
		return 1
	fi
	if ! git diff --binary --no-ext-diff --no-renames > "$worktree_file"; then
		rm -f "$state_file" "$index_file" "$worktree_file"
		return 1
	fi
	php -r '
		$status = file_get_contents( $argv[1] );
		$index = file_get_contents( $argv[2] );
		$worktree = file_get_contents( $argv[3] );
		if ( false === $status || false === $index || false === $worktree ) {
			exit( 2 );
		}
		$entries = explode( "\0", $status );
		$tracked = false;
		$untracked = false;
		$hash = hash_init( "sha256" );
		hash_update( $hash, "index\0" . $index . "\0worktree\0" . $worktree );
		foreach ( $entries as $entry ) {
			if ( "" === $entry ) {
				continue;
			}
			if ( preg_match( "#^\?\? \.tmp/suite-determinism\.(?:jsonl|raw)\.#", $entry ) ) {
				continue;
			}
			hash_update( $hash, "\0status\0" . $entry );
			if ( str_starts_with( $entry, "??" ) ) {
				$untracked = true;
				$path = $argv[4] . "/" . substr( $entry, 3 );
				if ( is_link( $path ) ) {
					$contents = readlink( $path );
				} elseif ( is_file( $path ) ) {
					$contents = file_get_contents( $path );
				} else {
					$stat = @lstat( $path );
					$contents = false === $stat ? false : serialize( $stat );
				}
				if ( false === $contents ) {
					exit( 2 );
				}
				hash_update( $hash, "\0untracked\0" . $contents );
			} else {
				$tracked = true;
			}
		}
		printf( "%d %d %s\n", $tracked, $untracked, hash_final( $hash ) );
	' "$state_file" "$index_file" "$worktree_file" "$ROOT"
	local rc=$?
	rm -f "$state_file" "$index_file" "$worktree_file"
	return "$rc"
}

echo "runs=$RUNS  php=$PHP_VERSION  root=$ROOT"
echo "writing $OUT"
echo ""

fails=0
errors=0
contaminated=0
revision_changes=0
worktree_changes=0

for ((i = 1; i <= RUNS; i++)); do
	head_before="$(git rev-parse HEAD 2>/dev/null || printf '<unresolvable>')"
	state_before="$(tree_state)" || {
		echo "cannot read the repository state before run $i" >&2
		exit 2
	}
	read -r tracked_before untracked_before state_hash_before <<< "$state_before"

	path_now="$(autoload_path)"
	root_rc=$?
	autoload_ok=0
	if [ "$root_rc" -eq 0 ] \
		&& [ -n "$path_now" ] \
		&& [ "$path_now" = "$ROOT/includes/class-gate.php" ]; then
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
	state_after="$(tree_state)" || {
		echo "cannot read the repository state after run $i" >&2
		rm -f "$raw_file"
		exit 2
	}
	read -r tracked_after untracked_after state_hash_after <<< "$state_after"
	worktree_changed=0
	if [ "$state_hash_before" != "$state_hash_after" ]; then
		worktree_changed=1
		worktree_changes=$((worktree_changes + 1))
	fi
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
	RECORD_AUTOLOAD_PATH="$path_now" \
	RECORD_AUTOLOAD_OK="$autoload_ok" \
	RECORD_TRACKED_BEFORE="$tracked_before" \
	RECORD_UNTRACKED_BEFORE="$untracked_before" \
	RECORD_TRACKED_AFTER="$tracked_after" \
	RECORD_UNTRACKED_AFTER="$untracked_after" \
	RECORD_WORKTREE_CHANGED="$worktree_changed" \
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
			"autoload_path"          => getenv( "RECORD_AUTOLOAD_PATH" ) ?: null,
			"autoload_ok"            => $bool( "RECORD_AUTOLOAD_OK" ),
			"tracked_dirty_before"   => $bool( "RECORD_TRACKED_BEFORE" ),
			"untracked_dirty_before" => $bool( "RECORD_UNTRACKED_BEFORE" ),
			"tracked_dirty_after"    => $bool( "RECORD_TRACKED_AFTER" ),
			"untracked_dirty_after"  => $bool( "RECORD_UNTRACKED_AFTER" ),
			"worktree_changed"       => $bool( "RECORD_WORKTREE_CHANGED" ),
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
	[ "$autoload_ok" -eq 1 ] || printf ' AUTOLOAD=%s' "${path_now:-<unresolvable>}"
	[ "$revision_changed" -eq 0 ] || printf ' HEAD-CHANGED'
	[ "$worktree_changed" -eq 0 ] || printf ' WORKTREE-CHANGED'
	printf '\n'
done

echo ""
echo "── summary ─────────────────────────────────────────"
echo "runs:             $RUNS"
echo "failing runs:     $fails"
echo "errored runs:     $errors"
echo "foreign roots:    $contaminated"
echo "revision changes: $revision_changes"
echo "worktree changes: $worktree_changes"

if [ "$fails" -eq 0 ] \
	&& [ "$errors" -eq 0 ] \
	&& [ "$contaminated" -eq 0 ] \
	&& [ "$revision_changes" -eq 0 ] \
	&& [ "$worktree_changes" -eq 0 ]; then
	echo "The observed $RUNS-run sample passed under the environment recorded in $OUT."
	exit 0
fi

exit 1
