#!/usr/bin/env bash

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHPUNIT_CONFIG="${1:-phpunit-integration.xml.dist}"
WP_MULTISITE_VALUE="${WP_MULTISITE:-}"
MYSQLADMIN_BIN=""

resolve_wp_tests_config_path() {
	if [ -n "${WP_TESTS_CONFIG:-}" ]; then
		printf '%s\n' "$WP_TESTS_CONFIG"
		return 0
	fi

	if [ -n "${WP_TESTS_DIR:-}" ]; then
		printf '%s/wp-tests-config.php\n' "${WP_TESTS_DIR%/}"
		return 0
	fi

	printf '%s/.tmp/wordpress-tests-lib/wp-tests-config.php\n' "$REPO_ROOT"
}

find_wp_env_tests_container() {
	# Several wp-env environments can run side by side (one per project
	# path hash), so match the container that bind-mounts THIS repo rather
	# than taking the first tests-cli container on the machine.
	local candidate

	for candidate in $(docker ps --format '{{.Names}}' 2>/dev/null | grep -E -- '-tests-cli-1$' || true); do
		if docker inspect "$candidate" --format '{{range .Mounts}}{{.Source}}{{"\n"}}{{end}}' 2>/dev/null | grep -qx -- "$REPO_ROOT"; then
			printf '%s\n' "$candidate"
			return 0
		fi
	done

	return 0
}

extract_wp_env_version() {
	local version

	version="$(sed -n "s#.*wordpress-\\([0-9][^\"/]*\\)\\.zip.*#\\1#p" "$REPO_ROOT/.wp-env.json" | head -n 1)"

	if [ -n "$version" ]; then
		printf '%s\n' "$version"
		return 0
	fi

	printf 'latest\n'
}

extract_db_host() {
	local config_file="$1"
	local constant_name="$2"

	if [ ! -f "$config_file" ]; then
		return 1
	fi

	sed -n "s/^define( '$constant_name', '\\(.*\\)' );$/\\1/p" "$config_file" | head -n 1
}

resolve_mysqladmin_bin() {
	if command -v mysqladmin >/dev/null 2>&1; then
		command -v mysqladmin
		return 0
	fi

	for prefix in /opt/homebrew /usr/local; do
		for formula in mysql mysql-client; do
			local candidate="$prefix/opt/$formula/bin/mysqladmin"
			if [ -x "$candidate" ]; then
				printf '%s\n' "$candidate"
				return 0
			fi
		done
	done

	return 1
}

host_db_reachable() {
	local db_host="$1"
	local db_user="$2"
	local db_password="$3"
	local host_part=""
	local port_part=""
	local socket_part=""

	if [ -z "$db_host" ]; then
		return 1
	fi

	case "$db_host" in
		*:*/*)
			socket_part="${db_host#*:}"
			[ -S "$socket_part" ] || return 1
			if [ -n "$MYSQLADMIN_BIN" ] && [ -n "$db_user" ]; then
				"$MYSQLADMIN_BIN" --socket="$socket_part" --user="$db_user" --password="$db_password" ping --silent >/dev/null 2>&1
				return
			fi
			[ -S "$socket_part" ]
			return
			;;
		*:[0-9]*)
			host_part="${db_host%%:*}"
			port_part="${db_host##*:}"
			;;
		*)
			host_part="$db_host"
			port_part="3306"
			;;
	esac

	if [ -z "$host_part" ] || [ -z "$port_part" ]; then
		return 1
	fi

	if [ -n "$MYSQLADMIN_BIN" ] && [ -n "$db_user" ]; then
		"$MYSQLADMIN_BIN" --host="$host_part" --port="$port_part" --protocol=tcp --user="$db_user" --password="$db_password" ping --silent >/dev/null 2>&1
		return
	fi

	nc -z "$host_part" "$port_part" >/dev/null 2>&1
}

run_inside_wp_env_tests_container() {
	local container_name="$1"
	local wp_version="$2"
	local multisite_env=""
	local inner_command=""
	local exec_uid=""
	local exec_gid=""

	if [ -n "$WP_MULTISITE_VALUE" ]; then
		multisite_env="WP_MULTISITE=$WP_MULTISITE_VALUE "
	fi

	echo "Host DB connection is unavailable; running integration suite inside wp-env container $container_name"

	# The suite runs as the container's default exec user, so /tmp/wordpress
	# must not stay root-owned after the root mkdir below.
	exec_uid="$(docker exec "$container_name" id -u)"
	exec_gid="$(docker exec "$container_name" id -g)"
	docker exec -u root "$container_name" sh -lc "mkdir -p /tmp/wordpress/wp-content/uploads && chmod 0777 /tmp/wordpress/wp-content/uploads && chown -R ${exec_uid}:${exec_gid} /tmp/wordpress"

	# The wp-env cli image bakes WP_TESTS_DIR=/wordpress-phpunit into the
	# environment, so install-wp-tests.sh skips its test-suite step and only
	# provisions the wordpress_test database, WP core, and plugins. The
	# /wordpress-phpunit config reads DB_NAME and $table_prefix from
	# WORDPRESS_* env vars and otherwise defaults to the live tests site's
	# database and wp_ prefix — sharing tables with a site that auto-activates
	# this plugin. Point the suite at the dedicated database and the canonical
	# wptests_ prefix so test runs are isolated from the live site.
	inner_command="cd /var/www/html/wp-content/plugins/wp-sudo && "
	inner_command="${inner_command}WP_SUDO_FORCE_DROP_DB=1 bash bin/install-wp-tests.sh wordpress_test root password tests-mysql $wp_version >/dev/null && "
	inner_command="${inner_command}${multisite_env}WORDPRESS_DB_NAME=wordpress_test WORDPRESS_TABLE_PREFIX=wptests_ WP_TESTS_DIR=/wordpress-phpunit ./vendor/bin/phpunit --configuration $PHPUNIT_CONFIG"

	docker exec "$container_name" sh -lc "$inner_command"
}

main() {
	local db_host=""
	local db_user=""
	local db_password=""
	local tests_container=""
	local wp_version=""
	local wp_tests_config=""

	if [ -n "${CI:-}" ] && [ -n "${WP_TESTS_DIR:-}" ] && [ -f "${WP_TESTS_DIR%/}/wp-tests-config.php" ]; then
		exec ./vendor/bin/phpunit --configuration "$PHPUNIT_CONFIG"
	fi

	MYSQLADMIN_BIN="$(resolve_mysqladmin_bin || true)"
	wp_tests_config="$(resolve_wp_tests_config_path)"
	db_host="$(extract_db_host "$wp_tests_config" "DB_HOST" || true)"
	db_user="$(extract_db_host "$wp_tests_config" "DB_USER" || true)"
	db_password="$(extract_db_host "$wp_tests_config" "DB_PASSWORD" || true)"

	if [ -n "$db_host" ] && host_db_reachable "$db_host" "$db_user" "$db_password"; then
		exec ./vendor/bin/phpunit --configuration "$PHPUNIT_CONFIG"
	fi

	tests_container="$(find_wp_env_tests_container)"

	if [ -n "$tests_container" ]; then
		wp_version="$(extract_wp_env_version)"
		run_inside_wp_env_tests_container "$tests_container" "$wp_version"
		return 0
	fi

	if [ -n "$db_host" ]; then
		echo "Configured integration DB host is unreachable: $db_host" >&2
	else
		echo "No integration DB host could be read from $wp_tests_config" >&2
	fi

	echo "Start a reachable test database or a wp-env tests-cli container, then retry." >&2
	return 1
}

main "$@"
