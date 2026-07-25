#!/usr/bin/env bash

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
METRICS_FILE="$REPO_ROOT/docs/current-metrics.md"

if [ ! -f "$METRICS_FILE" ]; then
	echo "ERROR: Metrics file not found: $METRICS_FILE"
	exit 1
fi

metric_cell() {
	local metric="$1"

	awk -F'|' -v metric="$metric" '
		{
			key = $2
			gsub(/^[[:space:]]+|[[:space:]]+$/, "", key)
			if (key == metric) {
				val = $3
				gsub(/^[[:space:]]+|[[:space:]]+$/, "", val)
				print val
				exit
			}
		}
	' "$METRICS_FILE"
}

metric_number() {
	local metric="$1"
	local value

	value="$(metric_cell "$metric")"
	if [ -z "$value" ]; then
		echo "ERROR: Metric row not found in docs/current-metrics.md: $metric"
		exit 1
	fi

	value="${value//,/}"
	printf '%s\n' "$value" | grep -Eo '[0-9]+(\.[0-9]+)?' | head -n1
}

add_failure() {
	local label="$1"
	local expected="$2"
	local actual="$3"
	FAILURES="${FAILURES}${label}: docs=${expected}, actual=${actual}"$'\n'
}

UNIT_OUTPUT="$(cd "$REPO_ROOT" && composer test:unit)"
UNIT_SUMMARY="$(printf '%s\n' "$UNIT_OUTPUT" | grep -Eo 'OK \([0-9]+ tests, [0-9]+ assertions\)' | tail -n1 || true)"

if [ -z "$UNIT_SUMMARY" ]; then
	echo "ERROR: Could not parse unit test summary from composer test:unit output."
	echo "$UNIT_OUTPUT"
	exit 1
fi

ACTUAL_UNIT_TESTS="$(printf '%s\n' "$UNIT_SUMMARY" | sed -E 's/OK \(([0-9]+) tests, ([0-9]+) assertions\)/\1/')"
ACTUAL_UNIT_ASSERTIONS="$(printf '%s\n' "$UNIT_SUMMARY" | sed -E 's/OK \(([0-9]+) tests, ([0-9]+) assertions\)/\2/')"
ACTUAL_INTEGRATION_METHODS="$(find "$REPO_ROOT/tests/Integration" -type f -name '*Test.php' -exec grep -h -E 'function test' {} + | wc -l | tr -d ' ')"
ACTUAL_UNIT_FILES="$(find "$REPO_ROOT/tests/Unit" -type f -name '*Test.php' | wc -l | tr -d ' ')"
ACTUAL_INTEGRATION_FILES="$(find "$REPO_ROOT/tests/Integration" -type f -name '*.php' | wc -l | tr -d ' ')"
ACTUAL_PROD_LINES="$(find "$REPO_ROOT/includes" "$REPO_ROOT/wp-sudo.php" "$REPO_ROOT/uninstall.php" "$REPO_ROOT/mu-plugin" "$REPO_ROOT/bridges" -type f -name '*.php' -print0 | xargs -0 wc -l | tail -1 | awk '{print $1}')"
ACTUAL_TEST_LINES="$(find "$REPO_ROOT/tests" -type f -name '*.php' -print0 | xargs -0 wc -l | tail -1 | awk '{print $1}')"
ACTUAL_TOTAL_LINES="$(( ACTUAL_PROD_LINES + ACTUAL_TEST_LINES ))"
ACTUAL_RATIO="$(awk -v tests="$ACTUAL_TEST_LINES" -v prod="$ACTUAL_PROD_LINES" 'BEGIN { printf "%.2f", tests / prod }')"
# Relative `find .` (not absolute `find "$REPO_ROOT"`) so the `! -path '*/.claude/*'`
# exclusion anchors to the repo's own .claude/ directory. An absolute path would
# itself contain `/.claude/` whenever this script runs from a worktree checked out
# under .claude/worktrees/<name>/, which would exclude the entire tree. Excluding
# .claude/ prevents double-counting a full worktree checkout (~55k PHP lines) that
# lives there; CI runs on a clean checkout with no such worktree.
ACTUAL_REPO_PHP="$(cd "$REPO_ROOT" && find . -type f -name '*.php' ! -path '*/vendor/*' ! -path '*/vendor_test/*' ! -path '*/.tmp/*' ! -path '*/.git/*' ! -path '*/.claude/*' -print0 | xargs -0 wc -l | tail -1 | awk '{print $1}')"

# Architectural facts (security-relevant counts). These use the same commands
# documented in the metric rows, so the gate and the doc stay self-consistent.
REGISTRY_FILE="$REPO_ROOT/includes/class-action-registry.php"
ACTUAL_GATED_SINGLE="$(grep "'id'" "$REGISTRY_FILE" | grep -v network | grep -v "rule\[" | wc -l | tr -d ' ')"
ACTUAL_GATED_MULTI="$(grep "'id'" "$REGISTRY_FILE" | grep -c "network" || true)"
ACTUAL_GATED_TOTAL="$(grep "'id'" "$REGISTRY_FILE" | grep -v "rule\[" | wc -l | tr -d ' ')"
# Unique do_action() hook names across includes/class-*.php (multi-line aware),
# excluding the render-only two-factor fields hook — identical to the doc method.
ACTUAL_AUDIT_HOOKS="$(cd "$REPO_ROOT" && python3 - <<'PY'
import pathlib, re
hooks = set()
for path in pathlib.Path('includes').glob('class-*.php'):
    hooks.update(re.findall(r"do_action\(\s*'([^']+)'", path.read_text()))
hooks.discard('wp_sudo_render_two_factor_fields')
print(len(hooks))
PY
)"

# Persistent options — DISCOVER the option names the plugin actually writes rather
# than counting a hardcoded list (which would be self-confirming, the very defect this
# closes). Scans the full production tree for write-API first arguments
# (update_option / add_option / update_site_option / add_site_option), resolves
# Class::CONST / self::CONST to their string values, keeps wp_sudo_* names, and fails
# closed on any unresolvable constant or network-option write (whose option name is a
# different argument). The discovered SET is compared to the expected set below, so an
# added or removed option cannot pass silently at an unchanged count.
ACTUAL_OPTIONS="$(cd "$REPO_ROOT" && python3 - <<'PY'
import pathlib, re, sys

prod_files = []
for p in ('wp-sudo.php', 'uninstall.php'):
    fp = pathlib.Path(p)
    if fp.exists():
        prod_files.append(fp)
for d in ('includes', 'mu-plugin', 'bridges'):
    prod_files += sorted(pathlib.Path(d).rglob('*.php'))

texts = {f: f.read_text() for f in prod_files}

const_map = {}
for t in texts.values():
    for m in re.finditer(r"const\s+([A-Z0-9_]+)\s*=\s*'([^']*)'\s*;", t):
        const_map.setdefault(m.group(1), m.group(2))

for f, t in texts.items():
    if re.search(r"\b(?:update|add)_network_option\s*\(", t):
        sys.stderr.write("verify-metrics: network-option write in %s; option name is arg 2 there, so this gate needs extending.\n" % f)
        sys.exit(3)

names = set()
write_re = re.compile(r"\b(?:update|add)_(?:option|site_option)\s*\(\s*([^,]+?)\s*,")
for f, t in texts.items():
    for m in write_re.finditer(t):
        arg = m.group(1).strip()
        lit = re.match(r"^'([^']*)'$", arg)
        if lit:
            names.add(lit.group(1))
            continue
        cst = re.match(r"^(?:self|static|[A-Za-z_][A-Za-z0-9_]*)::([A-Z0-9_]+)$", arg)
        if cst:
            key = cst.group(1)
            if key not in const_map:
                sys.stderr.write("verify-metrics: unresolved option constant %s in %s\n" % (arg, f))
                sys.exit(3)
            names.add(const_map[key])
            continue
        sys.stderr.write("verify-metrics: unresolvable option-name argument '%s' in %s\n" % (arg, f))
        sys.exit(3)

print(' '.join(sorted(n for n in names if n.startswith('wp_sudo_'))))
PY
)"
# Expected live-option name set (the contract). Update this AND the "Persistent
# options" row in docs/current-metrics.md together when the plugin's persisted
# options genuinely change.
EXPECTED_OPTIONS="wp_sudo_activated wp_sudo_db_version wp_sudo_settings"
ACTUAL_OPTIONS_SORTED="$(printf '%s\n' $ACTUAL_OPTIONS | sort | tr '\n' ' ' | sed 's/ *$//')"
EXPECTED_OPTIONS_SORTED="$(printf '%s\n' $EXPECTED_OPTIONS | sort | tr '\n' ' ' | sed 's/ *$//')"
EXPECTED_OPTIONS_COUNT="$(printf '%s\n' $EXPECTED_OPTIONS | wc -w | tr -d ' ')"

DOC_UNIT_TESTS="$(metric_number 'Unit tests')"
DOC_UNIT_ASSERTIONS="$(metric_number 'Unit assertions')"
DOC_INTEGRATION_METHODS="$(metric_number 'Integration tests in suite')"
DOC_UNIT_FILES="$(metric_number 'Unit test files')"
DOC_INTEGRATION_FILES="$(metric_number 'Integration test files')"
DOC_PROD_LINES="$(metric_number 'Production PHP lines (`includes/`, `wp-sudo.php`, `uninstall.php`, `mu-plugin/`, `bridges/`)')"
DOC_TEST_LINES="$(metric_number 'Tests PHP lines (`tests/`)')"
DOC_TOTAL_LINES="$(metric_number 'Production + tests PHP lines')"
DOC_RATIO="$(metric_number 'Test-to-production ratio')"
DOC_REPO_PHP="$(metric_number 'Total repo PHP lines (excluding `vendor/`, `vendor_test/`, `.tmp/`, `.git/`, `.claude/`)')"
DOC_GATED_SINGLE="$(metric_number 'Gated rules (single-site)')"
DOC_GATED_MULTI="$(metric_number 'Gated rules (multisite)')"
DOC_GATED_TOTAL="$(metric_number 'Gated rules (total)')"
DOC_AUDIT_HOOKS="$(metric_number 'Audit hooks')"
DOC_OPTIONS_COUNT="$(metric_number 'Persistent options')"

FAILURES=""

[ "$DOC_UNIT_TESTS" = "$ACTUAL_UNIT_TESTS" ] || add_failure "Unit tests" "$DOC_UNIT_TESTS" "$ACTUAL_UNIT_TESTS"
[ "$DOC_UNIT_ASSERTIONS" = "$ACTUAL_UNIT_ASSERTIONS" ] || add_failure "Unit assertions" "$DOC_UNIT_ASSERTIONS" "$ACTUAL_UNIT_ASSERTIONS"
[ "$DOC_INTEGRATION_METHODS" = "$ACTUAL_INTEGRATION_METHODS" ] || add_failure "Integration test methods" "$DOC_INTEGRATION_METHODS" "$ACTUAL_INTEGRATION_METHODS"
[ "$DOC_UNIT_FILES" = "$ACTUAL_UNIT_FILES" ] || add_failure "Unit test files" "$DOC_UNIT_FILES" "$ACTUAL_UNIT_FILES"
[ "$DOC_INTEGRATION_FILES" = "$ACTUAL_INTEGRATION_FILES" ] || add_failure "Integration test files" "$DOC_INTEGRATION_FILES" "$ACTUAL_INTEGRATION_FILES"
[ "$DOC_PROD_LINES" = "$ACTUAL_PROD_LINES" ] || add_failure "Production PHP lines" "$DOC_PROD_LINES" "$ACTUAL_PROD_LINES"
[ "$DOC_TEST_LINES" = "$ACTUAL_TEST_LINES" ] || add_failure "Tests PHP lines" "$DOC_TEST_LINES" "$ACTUAL_TEST_LINES"
[ "$DOC_TOTAL_LINES" = "$ACTUAL_TOTAL_LINES" ] || add_failure "Production + tests PHP lines" "$DOC_TOTAL_LINES" "$ACTUAL_TOTAL_LINES"
[ "$DOC_RATIO" = "$ACTUAL_RATIO" ] || add_failure "Test-to-production ratio" "$DOC_RATIO" "$ACTUAL_RATIO"
[ "$DOC_REPO_PHP" = "$ACTUAL_REPO_PHP" ] || add_failure "Total repo PHP lines" "$DOC_REPO_PHP" "$ACTUAL_REPO_PHP"
[ "$DOC_GATED_SINGLE" = "$ACTUAL_GATED_SINGLE" ] || add_failure "Gated rules (single-site)" "$DOC_GATED_SINGLE" "$ACTUAL_GATED_SINGLE"
[ "$DOC_GATED_MULTI" = "$ACTUAL_GATED_MULTI" ] || add_failure "Gated rules (multisite)" "$DOC_GATED_MULTI" "$ACTUAL_GATED_MULTI"
[ "$DOC_GATED_TOTAL" = "$ACTUAL_GATED_TOTAL" ] || add_failure "Gated rules (total)" "$DOC_GATED_TOTAL" "$ACTUAL_GATED_TOTAL"
[ "$DOC_AUDIT_HOOKS" = "$ACTUAL_AUDIT_HOOKS" ] || add_failure "Audit hooks" "$DOC_AUDIT_HOOKS" "$ACTUAL_AUDIT_HOOKS"
[ "$ACTUAL_OPTIONS_SORTED" = "$EXPECTED_OPTIONS_SORTED" ] || add_failure "Persistent options (name set)" "$EXPECTED_OPTIONS_SORTED" "$ACTUAL_OPTIONS_SORTED"
[ "$DOC_OPTIONS_COUNT" = "$EXPECTED_OPTIONS_COUNT" ] || add_failure "Persistent options (count)" "$DOC_OPTIONS_COUNT" "$EXPECTED_OPTIONS_COUNT"

if [ -n "$FAILURES" ]; then
	echo "Metrics drift detected in docs/current-metrics.md:"
	printf '%s' "$FAILURES"
	echo
	echo "Update docs/current-metrics.md and re-run: composer verify:metrics"
	exit 1
fi

echo "Metrics verified: docs/current-metrics.md is in sync."
