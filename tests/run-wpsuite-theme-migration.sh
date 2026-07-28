#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
FAMILY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
WORKSPACE_ROOT="$(cd -- "${FAMILY_ROOT}/.." && pwd)"
PLUGIN_ZIP="${1:-${FAMILY_ROOT}/wpsuite-plugins/dist/smartcloud-agent-composer-1.0.0.zip}"
THEME_SOURCE="${WORKSPACE_ROOT}/wpsuite-template/twentytwentyfive-child"
WP_VERSION="${WP_VERSION:-7.0.2}"
WP_CLI="${WP_CLI:-/usr/local/bin/wp}"

[[ -f "${PLUGIN_ZIP}" ]] || { echo "Plugin ZIP not found: ${PLUGIN_ZIP}" >&2; exit 66; }
[[ -d "${THEME_SOURCE}" ]] || { echo "Theme source not found: ${THEME_SOURCE}" >&2; exit 66; }

TEST_ROOT="$(mktemp -d /tmp/smartcloud-composer-c4.XXXXXX)"
DB_ROOT="${TEST_ROOT}/mariadb"
DB_SOCKET="${TEST_ROOT}/mariadb.sock"
DB_PID="${TEST_ROOT}/mariadb.pid"
DB_LOG="${TEST_ROOT}/mariadb.log"
WP_ROOT="${TEST_ROOT}/wordpress"
THEME_STAGE="${TEST_ROOT}/theme-stage"
THEME_ZIP="${TEST_ROOT}/twentytwentyfive-child.zip"

cleanup() {
	local status=$?
	if ((status != 0)) && [[ -f "${DB_LOG}" ]]; then
		sed -n '1,200p' "${DB_LOG}" >&2
	fi
	if [[ -s "${DB_PID}" ]]; then
		kill "$(<"${DB_PID}")" 2>/dev/null || true
		wait "$(<"${DB_PID}")" 2>/dev/null || true
	fi
	case "${TEST_ROOT}" in
		/tmp/smartcloud-composer-c4.*) rm -rf -- "${TEST_ROOT}" ;;
		*) echo "Refusing to remove unexpected test root: ${TEST_ROOT}" >&2 ;;
	esac
}
trap cleanup EXIT

mkdir -p "${DB_ROOT}" "${WP_ROOT}" "${THEME_STAGE}/twentytwentyfive-child"
cp -a "${THEME_SOURCE}/." "${THEME_STAGE}/twentytwentyfive-child/"
rm -rf -- "${THEME_STAGE}/twentytwentyfive-child/.git" "${THEME_STAGE}/twentytwentyfive-child/tests/node_modules"
(cd "${THEME_STAGE}" && zip -q -r "${THEME_ZIP}" twentytwentyfive-child)

mariadb-install-db --no-defaults --datadir="${DB_ROOT}" --auth-root-authentication-method=normal --skip-test-db >/dev/null
mariadbd --no-defaults --datadir="${DB_ROOT}" --socket="${DB_SOCKET}" --pid-file="${DB_PID}" --skip-networking --innodb-use-native-aio=0 --log-error="${DB_LOG}" &
for _attempt in {1..100}; do
	if mysqladmin --protocol=SOCKET --socket="${DB_SOCKET}" -uroot ping >/dev/null 2>&1; then break; fi
	sleep 0.1
done
mysqladmin --protocol=SOCKET --socket="${DB_SOCKET}" -uroot ping >/dev/null
mysql --protocol=SOCKET --socket="${DB_SOCKET}" -uroot -e 'CREATE DATABASE composer_theme CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'

php8.1 "${WP_CLI}" core download --path="${WP_ROOT}" --version="${WP_VERSION}" --locale=en_US
php8.1 "${WP_CLI}" config create --path="${WP_ROOT}" --dbname=composer_theme --dbuser=root --dbhost="localhost:${DB_SOCKET}" --skip-check
php8.1 "${WP_CLI}" core install --path="${WP_ROOT}" --url=composer-theme.test --title='Composer Theme Migration' --admin_user=theme-admin --admin_password='composer-theme-disposable-password' --admin_email=theme@example.test --skip-email
php8.1 "${WP_CLI}" plugin install --path="${WP_ROOT}" "${PLUGIN_ZIP}" --force --activate
php8.1 "${WP_CLI}" theme install --path="${WP_ROOT}" "${THEME_ZIP}" --force --activate
cp "${FAMILY_ROOT}/smartcloud-agent-composer/presets/wpsuite/wpsuite-site-contract.package.json" /tmp/wpsuite-site-contract.package.json
php8.1 "${WP_CLI}" eval-file "${SCRIPT_DIR}/wordpress-import-wpsuite-preset.php" --path="${WP_ROOT}"
php8.1 "${WP_CLI}" eval-file "${SCRIPT_DIR}/wordpress-verify-wpsuite-theme.php" --path="${WP_ROOT}"

echo "WP Suite child-theme migration integration passed."
