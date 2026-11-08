#!/usr/bin/env bash
# Temporary CI diagnostic for the MySQL lane.
#
# Question: why does each server-race test take ~24 s on the Docker MySQL 8.0
# service when the same test file finishes in seconds on native MariaDB?
#
# It measures, on the live service container: the server durability settings,
# per-commit latency, a TRUNCATE sweep over every table, and one real test case.
# Then it relaxes durability (CI service only) and measures again. The relaxed
# settings stay in place for the lane that runs next, so that lane is also the
# end-to-end check of the fix.
set -uo pipefail

: "${MYSQL_CID:?MYSQL_CID (the MySQL service container id) is required}"

FAILED=0
RESULTS=()

root()  { docker exec    -e MYSQL_PWD=ci_root_password "$MYSQL_CID" mysql --user=root "$@"; }
rooti() { docker exec -i -e MYSQL_PWD=ci_root_password "$MYSQL_CID" mysql --user=root "$@"; }
now()   { date +%s.%N; }
secs()  { awk -v a="$1" -v b="$2" 'BEGIN { printf "%.2f", b - a }'; }

record() { RESULTS+=("$1"); echo "RESULT: $1"; }
fail()   { FAILED=1; echo "::error::$1"; }

show_settings() {
  echo "::group::server settings ($1)"
  root --batch -e "SELECT @@version AS version, @@innodb_flush_log_at_trx_commit AS flush_at_commit, @@sync_binlog AS sync_binlog, @@log_bin AS log_bin, @@innodb_flush_method AS flush_method, @@innodb_doublewrite AS doublewrite, @@innodb_file_per_table AS file_per_table, @@innodb_lock_wait_timeout AS lock_wait_timeout" \
    || fail "could not read the server settings ($1)"
  echo "::endgroup::"
}

commit_probe() {
  local label="$1" count=300 start end rc
  root -e "DROP DATABASE IF EXISTS diag_probe; CREATE DATABASE diag_probe; CREATE TABLE diag_probe.t (id INT AUTO_INCREMENT PRIMARY KEY, v INT) ENGINE=InnoDB" \
    || { fail "commit probe [$label]: could not create the probe schema"; return 1; }
  start=$(now)
  seq 1 "$count" | awk '{ printf "INSERT INTO diag_probe.t (v) VALUES (%d);\n", $1 }' | rooti
  rc=$?
  end=$(now)
  root -e "DROP DATABASE diag_probe" || fail "commit probe [$label]: could not drop the probe schema"
  if [ "$rc" -ne 0 ]; then
    fail "commit probe [$label]: the inserts failed with exit code $rc"
    return 1
  fi
  record "commit probe [$label]: $count autocommit inserts in $(secs "$start" "$end") s, $(awk -v a="$start" -v b="$end" -v n="$count" 'BEGIN { printf "%.1f", (b - a) * 1000 / n }') ms per commit"
}

truncate_probe() {
  local label="$1" tables count start end rc
  tables=$(root --batch --skip-column-names -e "SELECT table_name FROM information_schema.tables WHERE table_schema = 'thai_lottery_test' AND table_type = 'BASE TABLE' AND table_name <> 'migrations'") \
    || { fail "truncate probe [$label]: could not list the tables"; return 1; }
  count=$(printf '%s\n' "$tables" | grep -c .)
  if [ "$count" -eq 0 ]; then
    fail "truncate probe [$label]: thai_lottery_test has no tables, so the schema was never migrated"
    return 1
  fi
  start=$(now)
  { echo "SET FOREIGN_KEY_CHECKS=0;"; printf '%s\n' "$tables" | sed 's/.*/TRUNCATE TABLE `&`;/'; } | rooti thai_lottery_test
  rc=$?
  end=$(now)
  if [ "$rc" -ne 0 ]; then
    fail "truncate probe [$label]: the TRUNCATE sweep failed with exit code $rc"
    return 1
  fi
  record "truncate probe [$label]: TRUNCATE of $count tables in $(secs "$start" "$end") s"
}

observer_test() {
  local label="$1" start end rc
  start=$(now)
  php -d memory_limit=768M vendor/bin/phpunit --no-coverage --fail-on-empty-test-suite \
    --filter 'test_owned_observer_is_cleaned_once.*healthy-root' \
    tests/Feature/Finance/LedgerPostingObserverOwnershipTest.php
  rc=$?
  end=$(now)
  if [ "$rc" -ne 0 ]; then
    fail "observer test [$label]: phpunit exited with $rc"
  fi
  record "observer test [$label]: one case in $(secs "$start" "$end") s (phpunit exit $rc)"
}

# The same environment the lane runner (scripts/run_php_test_lane.py) gives phpunit.
export APP_BASE_PATH="$PWD" APP_ENV=testing LOG_CHANNEL=null DB_CONNECTION=mysql \
  FINANCIAL_PAYOUT_RACE=1 CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
  APP_COUNTRY=TH FINANCE_DEFAULT_CURRENCY=THB STRIPE_ENABLED=true \
  STRIPE_KEY=pk_test_mock_publishable_key_12345 \
  STRIPE_SECRET=sk_test_mock_secret_key_12345 \
  STRIPE_WEBHOOK_SECRET=whsec_mock_stripe_webhook_secret_67890
unset DB_URL
php artisan optimize:clear || fail "php artisan optimize:clear failed"

show_settings "before"
commit_probe "baseline"
observer_test "cold, includes the one-time migrate"
observer_test "warm baseline"
truncate_probe "baseline"

echo "Relaxing durability on the CI service container (CI only; the lane runs next)."
root -e "SET GLOBAL innodb_flush_log_at_trx_commit = 2; SET GLOBAL sync_binlog = 0" \
  || fail "could not relax the durability settings"

show_settings "after"
commit_probe "relaxed durability"
observer_test "relaxed durability"
truncate_probe "relaxed durability"

echo "::group::SUMMARY"
printf '%s\n' "${RESULTS[@]}"
echo "::endgroup::"

exit "$FAILED"
