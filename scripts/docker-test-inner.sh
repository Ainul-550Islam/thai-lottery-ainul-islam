#!/usr/bin/env bash
# Runs INSIDE the app container (fed over stdin by scripts/docker-test.sh).
set -Eeuo pipefail
LANE="${1:-all}"; if [ $# -gt 0 ]; then shift; fi
EXTRA=("$@")
OUT=/out
SOCK=/run/mysqld/mysqld.sock

echo "==> syncing project into the container volume (host .env excluded)"
rsync -a --delete \
  --exclude=/vendor --exclude=/node_modules --exclude=/.git --exclude=/.phpunit.cache \
  --exclude=/.env --exclude=/.env.testing --exclude=/.env.local \
  --exclude='/bootstrap/cache/*.php' --exclude=/storage/framework/testing --exclude=/storage/logs \
  --exclude='/database/thai_lottery_test*' --exclude=/.test-results --exclude=/.mysql-test-socket \
  /host/ /app/
cd /app
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/framework/testing storage/logs bootstrap/cache
chmod -R a+rwX storage bootstrap/cache

php -v | head -1
echo "==> composer install (cached in a docker volume)"
composer install --no-interaction --no-progress --prefer-dist
bash scripts/build-weekly-native-verifier.sh

PHPUNIT_FLAGS=(--colors=never --do-not-cache-result --display-skipped --display-errors --display-warnings)

run_lane() {   # run_lane <name> [phpunit options...]
  local name="$1"; shift
  echo; echo "==> lane: $name"
  set +e
  php vendor/bin/phpunit "$@" "${PHPUNIT_FLAGS[@]}" --log-junit "$OUT/$name.xml" "${EXTRA[@]}" 2>&1 | tee "$OUT/$name.log"
  echo "phpunit exit code: ${PIPESTATUS[0]}" | tee -a "$OUT/$name.log"
  set -e
}

mysql_env() {
  export APP_ENV=testing APP_DEBUG=true APP_KEY='base64:ZdZRVoeGZh2IQXa67+qdQzaPbjnFovX4CCE0YgWHJI8=' \
         REAL_MONEY_ENABLED=false CACHE_STORE=array QUEUE_CONNECTION=sync \
         DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=thai_lottery_test \
         DB_USERNAME=lotto DB_PASSWORD=lotto_test_pw DB_CHARSET=utf8mb4 DB_SOCKET="$SOCK" DB_URL= \
         FINANCIAL_PAYOUT_RACE=1
  for _ in $(seq 1 60); do
    if [ -S "$SOCK" ] && mariadb-admin --socket="$SOCK" --user=lotto --password=lotto_test_pw ping --silent >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  echo "MariaDB socket $SOCK is not reachable" >&2
  return 1
}

lane_sqlite() {
  ( rm -f database/thai_lottery_test database/thai_lottery_test-journal
    run_lane sqlite ) || echo "lane sqlite aborted"
}
lane_mysql() {
  ( set -e
    mysql_env
    php artisan migrate:fresh --force --no-interaction
    run_lane mysql -c phpunit.mysql.xml ) || echo "lane mysql aborted"
}
lane_races() {
  ( set -e
    mysql_env
    php artisan migrate:fresh --force --no-interaction
    mkdir -p evidence
    python3 scripts/run_financial_concurrency.py --suite all --database thai_lottery_test --connection mysql \
      --socket "$SOCK" --username lotto --acknowledge-disposable-database --repeats 1 \
      --json evidence/docker-races.json 2>&1 | tee "$OUT/races.log"; cp evidence/docker-races.json "$OUT/races.json" 2>/dev/null || true ) || echo "lane races aborted"
}

case "$LANE" in
  sqlite) lane_sqlite ;;
  mysql)  lane_mysql ;;
  races)  lane_races ;;
  all)    lane_sqlite; lane_mysql ;;
esac

echo; echo "==> summary"
python3 scripts/summarize_junit.py "$OUT" | tee "$OUT/SUMMARY.txt"
if grep -qs "phpunit exit code: [1-9]" "$OUT"/*.log; then echo "TESTS FAILED" >&2; exit 1; fi
