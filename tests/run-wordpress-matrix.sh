#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
FAMILY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
PLUGIN_ZIP="${1:-${FAMILY_ROOT}/wpsuite-plugins/dist/smartcloud-agent-composer-1.0.0.zip}"
WP_VERSION="${WP_VERSION:-7.0.2}"
WP_CLI="${WP_CLI:-/usr/local/bin/wp}"
PHP_BINARIES=(php8.1 php8.2 php8.3 php8.4)

[[ -f "${PLUGIN_ZIP}" ]] || {
    echo "Plugin ZIP not found: ${PLUGIN_ZIP}" >&2
    exit 66
}

TEST_ROOT="$(mktemp -d /tmp/smartcloud-composer-c4.XXXXXX)"
DB_ROOT="${TEST_ROOT}/mariadb"
DB_SOCKET="${TEST_ROOT}/mariadb.sock"
DB_PID="${TEST_ROOT}/mariadb.pid"
DB_LOG="${TEST_ROOT}/mariadb.log"
WP_ROOT="${TEST_ROOT}/wordpress"

cleanup() {
    local status=$?
    if ((status != 0)) && [[ -f "${DB_LOG}" ]]; then
        echo "MariaDB test log:" >&2
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

mkdir -p "${DB_ROOT}" "${WP_ROOT}"
mariadb-install-db --no-defaults --datadir="${DB_ROOT}" --auth-root-authentication-method=normal --skip-test-db >/dev/null
mariadbd --no-defaults --datadir="${DB_ROOT}" --socket="${DB_SOCKET}" --pid-file="${DB_PID}" --skip-networking --innodb-use-native-aio=0 --log-error="${DB_LOG}" &

for _attempt in {1..100}; do
    if mysqladmin --protocol=SOCKET --socket="${DB_SOCKET}" -uroot ping >/dev/null 2>&1; then
        break
    fi
    sleep 0.1
done
mysqladmin --protocol=SOCKET --socket="${DB_SOCKET}" -uroot ping >/dev/null
mysql --protocol=SOCKET --socket="${DB_SOCKET}" -uroot -e 'CREATE DATABASE composer_c4 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'

php8.1 "${WP_CLI}" core download --path="${WP_ROOT}" --version="${WP_VERSION}" --locale=en_US
php8.1 "${WP_CLI}" config create --path="${WP_ROOT}" --dbname=composer_c4 --dbuser=root --dbhost="localhost:${DB_SOCKET}" --skip-check
php8.1 "${WP_CLI}" core multisite-install --path="${WP_ROOT}" --url=composer-c4.test --title='Composer C4' --admin_user=c4-admin --admin_password='composer-c4-disposable-password' --admin_email=c4@example.test --skip-email
php8.1 "${WP_CLI}" site create --path="${WP_ROOT}" --slug=secondary --title='Composer C4 Secondary' --email=c4@example.test
php8.1 "${WP_CLI}" plugin install --path="${WP_ROOT}" "${PLUGIN_ZIP}" --force
php8.1 "${WP_CLI}" plugin activate --path="${WP_ROOT}" smartcloud-agent-composer --network

for php_binary in "${PHP_BINARIES[@]}"; do
    command -v "${php_binary}" >/dev/null
    echo "Running WordPress ${WP_VERSION} integration on ${php_binary}"
    "${php_binary}" "${WP_CLI}" eval-file "${SCRIPT_DIR}/wordpress-integration.php" --path="${WP_ROOT}" --url=composer-c4.test
    SMARTCLOUD_PRESET_STAGE=setup "${php_binary}" "${WP_CLI}" eval-file "${SCRIPT_DIR}/wordpress-preset-runtime.php" --path="${WP_ROOT}" --url=composer-c4.test
    SMARTCLOUD_PRESET_STAGE=universal "${php_binary}" "${WP_CLI}" eval-file "${SCRIPT_DIR}/wordpress-preset-runtime.php" --path="${WP_ROOT}" --url=composer-c4.test
    SMARTCLOUD_PRESET_STAGE=recommended "${php_binary}" "${WP_CLI}" eval-file "${SCRIPT_DIR}/wordpress-preset-runtime.php" --path="${WP_ROOT}" --url=composer-c4.test
    "${php_binary}" "${WP_CLI}" eval-file "${SCRIPT_DIR}/wordpress-multisite-integration.php" --path="${WP_ROOT}" --url=composer-c4.test
done

php8.1 "${WP_CLI}" eval-file "${SCRIPT_DIR}/wordpress-uninstall-fixture.php" --path="${WP_ROOT}" --url=composer-c4.test
php8.1 "${WP_CLI}" eval-file "${SCRIPT_DIR}/wordpress-uninstall-fixture.php" --path="${WP_ROOT}" --url=composer-c4.test/secondary
php8.1 "${WP_CLI}" plugin deactivate --path="${WP_ROOT}" smartcloud-agent-composer --network
php8.1 "${WP_CLI}" plugin uninstall --path="${WP_ROOT}" smartcloud-agent-composer
php8.1 "${WP_CLI}" eval-file "${SCRIPT_DIR}/wordpress-uninstall-verify.php" --path="${WP_ROOT}" --url=composer-c4.test
php8.1 "${WP_CLI}" eval-file "${SCRIPT_DIR}/wordpress-uninstall-verify.php" --path="${WP_ROOT}" --url=composer-c4.test/secondary

echo "Composer C4 WordPress/PHP/multisite matrix passed."
