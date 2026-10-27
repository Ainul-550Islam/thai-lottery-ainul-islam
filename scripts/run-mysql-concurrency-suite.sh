#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
COMPOSE_FILE="docker-compose.test-mysql.yml"
COMPOSE=(docker compose -f "$COMPOSE_FILE" -p thai-lottery-batch01-test)
SOCKET_DIR="$ROOT/.mysql-test-socket"
SOCKET_PATH="$SOCKET_DIR/mysqld.sock"

if [[ ! -x vendor/bin/phpunit ]]; then
    echo 'Composer dependencies are missing; run composer install before the MySQL lane.' >&2
    exit 2
fi
if ! command -v docker >/dev/null 2>&1; then
    echo 'Docker is required for the disposable MySQL concurrency lane.' >&2
    exit 2
fi

mkdir -p "$SOCKET_DIR"
chmod 0777 "$SOCKET_DIR"

cleanup() {
    "${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$SOCKET_DIR"
}
trap cleanup EXIT INT TERM

export APP_ENV=testing
export APP_DEBUG=true
export REAL_MONEY_ENABLED=false
export CACHE_STORE=array
export SESSION_DRIVER=array
export QUEUE_CONNECTION=sync
export DB_CONNECTION=mysql
export DB_HOST=127.0.0.1
export DB_PORT=3307
export DB_DATABASE=thai_lottery_test
export DB_USERNAME=lotto
export DB_PASSWORD=lotto_test_pw
export DB_CHARSET=utf8mb4
export DB_SOCKET="$SOCKET_PATH"
export DB_URL=''
export FINANCIAL_PAYOUT_RACE=1

"${COMPOSE[@]}" up -d --wait mysql-test
"${COMPOSE[@]}" exec -T mysql-test mariadb -uroot -proot_test_pw -e "GRANT PROCESS ON *.* TO 'lotto'@'%'; FLUSH PRIVILEGES;"
php artisan migrate:fresh --force --no-interaction

# The configured suite enumerates its test files explicitly. Keep this failure
# gate: environmental assumptions must be fixed, never hidden by skipped tests.
php vendor/bin/phpunit -c phpunit.mysql.xml --fail-on-skipped
