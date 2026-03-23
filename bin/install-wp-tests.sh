#!/usr/bin/env bash

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo $TMPDIR | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}
MYSQL_BIN=""
MYSQLADMIN_BIN=""
LOCAL_SOCKET_HOST_DETECTED=false

download() {
	local url="$1"
	local destination="$2"
	local tmp_destination="${destination}.tmp"
	local attempt=1
	local max_attempts=5
	local sleep_seconds=2

	while [ "$attempt" -le "$max_attempts" ]; do
		rm -f "$tmp_destination"

		if command -v curl > /dev/null 2>&1; then
			if curl -fsSL --connect-timeout 15 --max-time 300 "$url" -o "$tmp_destination"; then
				mv "$tmp_destination" "$destination"
				return 0
			fi
		elif command -v wget > /dev/null 2>&1; then
			if wget -q -T 30 -O "$tmp_destination" "$url"; then
				mv "$tmp_destination" "$destination"
				return 0
			fi
		else
			echo "Error: Neither curl nor wget is installed."
			exit 1
		fi

		if [ "$attempt" -lt "$max_attempts" ]; then
			echo "Download failed (attempt $attempt/$max_attempts): $url"
			sleep "$sleep_seconds"
			sleep_seconds=$(( sleep_seconds * 2 ))
		fi

		attempt=$(( attempt + 1 ))
	done

	echo "Error: failed to download $url after $max_attempts attempts."
	exit 1
}

# Check if svn is installed
check_svn_installed() {
    if ! command -v svn > /dev/null; then
        echo "Error: svn is not installed. Please install svn and try again."
        exit 1
    fi
}

resolve_mysql_bin() {
	local command_name="$1"

	if command -v "$command_name" > /dev/null 2>&1; then
		command -v "$command_name"
		return 0
	fi

	# Homebrew often installs mysql-client without linking it into PATH.
	for prefix in /opt/homebrew /usr/local; do
		for formula in mysql mysql-client; do
			local candidate="$prefix/opt/$formula/bin/$command_name"
			if [ -x "$candidate" ]; then
				echo "$candidate"
				return 0
			fi
		done
	done

	# Local by Flywheel bundles mysql/mysqladmin under lightning-services.
	local local_services_dir="$HOME/Library/Application Support/Local/lightning-services"
	if [ -d "$local_services_dir" ]; then
		while IFS= read -r candidate; do
			if [ -x "$candidate" ]; then
				echo "$candidate"
				return 0
			fi
		done < <(find "$local_services_dir" -path "*/bin/*/bin/$command_name" -type f 2>/dev/null | sort -r)
	fi

	return 1
}

check_mysql_tools_installed() {
	MYSQL_BIN=$(resolve_mysql_bin mysql || true)
	MYSQLADMIN_BIN=$(resolve_mysql_bin mysqladmin || true)

	if [ -z "$MYSQL_BIN" ] || [ -z "$MYSQLADMIN_BIN" ]; then
		echo "Error: mysql and mysqladmin are required for integration test setup."
		echo "Install MySQL tooling and make sure it is available on PATH."
		echo "macOS (Homebrew): brew install mysql-client"
		echo "Ubuntu/Debian: sudo apt install mysql-client"
		echo "If Homebrew mysql-client is not linked, add one of these paths to PATH:"
		echo "  /opt/homebrew/opt/mysql-client/bin"
		echo "  /usr/local/opt/mysql-client/bin"
		exit 1
	fi
}

find_local_mysql_socket() {
	local local_run_dir="$HOME/Library/Application Support/Local/run"
	local matches=()

	if [ ! -d "$local_run_dir" ]; then
		return 1
	fi

	while IFS= read -r socket_path; do
		[ -n "$socket_path" ] && matches+=("$socket_path")
	done < <(find "$local_run_dir" -name "mysqld.sock" -print 2>/dev/null)

	if [ "${#matches[@]}" -ne 1 ]; then
		return 1
	fi

	printf '%s\n' "${matches[0]}"
}

maybe_use_local_socket_host() {
	local original_host="$DB_HOST"
	local local_socket=""

	case "$DB_HOST" in
		localhost|127.0.0.1)
			;;
		*)
			return 0
			;;
	esac

	if "$MYSQLADMIN_BIN" ping --host="$DB_HOST" --protocol=tcp --user="$DB_USER" --password="$DB_PASS" > /dev/null 2>&1; then
		return 0
	fi

	local_socket="$(find_local_mysql_socket || true)"

	if [ -z "$local_socket" ]; then
		return 0
	fi

	DB_HOST="localhost:$local_socket"
	LOCAL_SOCKET_HOST_DETECTED=true
	echo "Detected Local by Flywheel MySQL socket; using DB host $DB_HOST instead of $original_host"
}

if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\-(beta|RC)[0-9]+$ ]]; then
	# Pre-release core tarballs exist, but the matching develop.svn test library
	# paths do not. During the beta/RC cycle, trunk is the canonical PHPUnit
	# library source for the upcoming major release.
	WP_TESTS_TAG="trunk"

elif [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
	WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then
	if [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0] ]]; then
		# version x.x.0 means the first release of the major version, so strip off the .0 and download version x.x
		WP_TESTS_TAG="tags/${WP_VERSION%??}"
	else
		WP_TESTS_TAG="tags/$WP_VERSION"
	fi
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
	WP_TESTS_TAG="trunk"
else
	# http serves a single offer, whereas https serves multiple. we only want one
	download https://api.wordpress.org/core/version-check/1.7/ /tmp/wp-latest.json
	grep '[0-9]+\.[0-9]+(\.[0-9]+)?' /tmp/wp-latest.json
	LATEST_VERSION=$(grep -o '"version":"[^"]*' /tmp/wp-latest.json | sed 's/"version":"//')
	if [[ -z "$LATEST_VERSION" ]]; then
		echo "Latest WordPress version could not be found"
		exit 1
	fi
	WP_TESTS_TAG="tags/$LATEST_VERSION"
fi
set -ex

install_wp() {

	if [ -d $WP_CORE_DIR ]; then
		return;
	fi

	mkdir -p $WP_CORE_DIR

	if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
		mkdir -p $TMPDIR/wordpress-trunk
		rm -rf $TMPDIR/wordpress-trunk/*
        check_svn_installed
		svn export --quiet https://core.svn.wordpress.org/trunk $TMPDIR/wordpress-trunk/wordpress
		mv $TMPDIR/wordpress-trunk/wordpress/* $WP_CORE_DIR
	else
		if [ $WP_VERSION == 'latest' ]; then
			local ARCHIVE_NAME='latest'
		elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+ ]]; then
			# https serves multiple offers, whereas http serves single.
			download https://api.wordpress.org/core/version-check/1.7/ $TMPDIR/wp-latest.json
			if [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0] ]]; then
				# version x.x.0 means the first release of the major version, so strip off the .0 and download version x.x
				LATEST_VERSION=${WP_VERSION%??}
			else
				# otherwise, scan the releases and get the most up to date minor version of the major release
				local VERSION_ESCAPED=`echo $WP_VERSION | sed 's/\./\\\\./g'`
				LATEST_VERSION=$(grep -o '"version":"'$VERSION_ESCAPED'[^"]*' $TMPDIR/wp-latest.json | sed 's/"version":"//' | head -1)
			fi
			if [[ -z "$LATEST_VERSION" ]]; then
				local ARCHIVE_NAME="wordpress-$WP_VERSION"
			else
				local ARCHIVE_NAME="wordpress-$LATEST_VERSION"
			fi
		else
			local ARCHIVE_NAME="wordpress-$WP_VERSION"
		fi
		download https://wordpress.org/${ARCHIVE_NAME}.tar.gz  $TMPDIR/wordpress.tar.gz
		tar --strip-components=1 -zxmf $TMPDIR/wordpress.tar.gz -C $WP_CORE_DIR
	fi

	download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php $WP_CORE_DIR/wp-content/db.php
}

install_test_suite() {
	# portable in-place argument for both GNU sed and Mac OSX sed
	if [[ $(uname -s) == 'Darwin' ]]; then
		local ioption='-i.bak'
	else
		local ioption='-i'
	fi

	# set up testing suite if it doesn't yet exist
	if [ ! -d $WP_TESTS_DIR ]; then
		# set up testing suite
		mkdir -p $WP_TESTS_DIR
		rm -rf $WP_TESTS_DIR/{includes,data}
        check_svn_installed
		svn export --quiet --ignore-externals https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/ $WP_TESTS_DIR/includes
		svn export --quiet --ignore-externals https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/ $WP_TESTS_DIR/data
	fi

	if [ ! -s "$WP_TESTS_DIR/wp-tests-config.php" ]; then
		download https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php "$WP_TESTS_DIR"/wp-tests-config.php
		# remove all forward slashes in the end
		WP_CORE_DIR=$(echo $WP_CORE_DIR | sed "s:/\+$::")
		sed $ioption "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s:__DIR__ . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR"/wp-tests-config.php
	fi

}

MYSQL_EXTRA_ARGS=()

recreate_db() {
	shopt -s nocasematch
	if [[ $1 =~ ^(y|yes)$ ]]
	then
		"$MYSQLADMIN_BIN" drop "$DB_NAME" -f --user="$DB_USER" --password="$DB_PASS" "${MYSQL_EXTRA_ARGS[@]}"
		create_db
		echo "Recreated the database ($DB_NAME)."
	else
		echo "Leaving the existing database ($DB_NAME) in place."
	fi
	shopt -u nocasematch
}

create_db() {
	"$MYSQLADMIN_BIN" create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" "${MYSQL_EXTRA_ARGS[@]}"
}

install_db() {

	if [ ${SKIP_DB_CREATE} = "true" ]; then
		return 0
	fi

	# parse DB_HOST for port or socket references while preserving socket paths with spaces
	local DB_HOSTNAME="$DB_HOST"
	local DB_SOCK_OR_PORT=""
	MYSQL_EXTRA_ARGS=()

	case "$DB_HOST" in
		*:* )
			DB_HOSTNAME=${DB_HOST%%:*}
			DB_SOCK_OR_PORT=${DB_HOST#*:}
			;;
	esac

	if ! [ -z "$DB_HOSTNAME" ] ; then
		if [ -n "$DB_SOCK_OR_PORT" ] && echo "$DB_SOCK_OR_PORT" | grep -Eq '^[0-9]+$'; then
			MYSQL_EXTRA_ARGS=( "--host=$DB_HOSTNAME" "--port=$DB_SOCK_OR_PORT" "--protocol=tcp" )
		elif ! [ -z "$DB_SOCK_OR_PORT" ] ; then
			MYSQL_EXTRA_ARGS=( "--socket=$DB_SOCK_OR_PORT" )
		elif ! [ -z "$DB_HOSTNAME" ] ; then
			MYSQL_EXTRA_ARGS=( "--host=$DB_HOSTNAME" "--protocol=tcp" )
		fi
	fi

	# create database
	if "$MYSQL_BIN" --user="$DB_USER" --password="$DB_PASS" "${MYSQL_EXTRA_ARGS[@]}" --execute='show databases;' 2> /dev/null | grep -q "^${DB_NAME}$"; then
		echo "Reinstalling will delete the existing test database ($DB_NAME)"
		if [ "${WP_SUDO_FORCE_DROP_DB:-}" = "1" ] || [ "${CI:-}" = "true" ]; then
			DELETE_EXISTING_DB='y'
		else
			read -p 'Are you sure you want to proceed? [y/N]: ' DELETE_EXISTING_DB
		fi
		recreate_db $DELETE_EXISTING_DB
	else
		create_db
	fi
}

install_plugins() {
	# Two Factor plugin — required by TwoFactorTest integration tests.
	local TF_DIR="$WP_CORE_DIR/wp-content/plugins/two-factor"
	if [ ! -d "$TF_DIR" ]; then
		download https://downloads.wordpress.org/plugin/two-factor.latest-stable.zip "$TMPDIR/two-factor.zip"
		mkdir -p "$WP_CORE_DIR/wp-content/plugins"
		unzip -qo "$TMPDIR/two-factor.zip" -d "$WP_CORE_DIR/wp-content/plugins"
	fi
}

check_mysql_tools_installed
maybe_use_local_socket_host
install_wp
install_test_suite
install_db
install_plugins
