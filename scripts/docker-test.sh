#!/usr/bin/env bash
# Usage:  bash scripts/docker-test.sh [sqlite|mysql|races|all] [extra phpunit args...]
#   sqlite  default phpunit.xml lane (reproduces the 3 errors / 18 failures / skips)
#   mysql   phpunit.mysql.xml lane (the tests that are skipped on SQLite)
#   races   scripts/run_financial_concurrency.py --suite all (project's CI "no skips" lane, 1 repeat)
#   all     sqlite + mysql            (default)
# Examples:
#   bash scripts/docker-test.sh sqlite --filter WalletConversion
#   KEEP_DB=1 bash scripts/docker-test.sh mysql
set -Eeuo pipefail
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'   # Git Bash: do not rewrite /paths into C:/Program Files/Git/...
cd "$(dirname "${BASH_SOURCE[0]}")/.."

LANE="${1:-all}"; if [ $# -gt 0 ]; then shift; fi
case "$LANE" in sqlite|mysql|races|all) ;; *) echo "lane must be sqlite|mysql|races|all" >&2; exit 2 ;; esac

command -v docker >/dev/null 2>&1 || { echo "docker not found in PATH" >&2; exit 2; }
docker info >/dev/null 2>&1 || { echo "Docker daemon is not running - start Docker Desktop first" >&2; exit 2; }

C=(docker compose -f docker-compose.test-docker.yml)
mkdir -p .test-results
rm -f .test-results/*.xml .test-results/*.log .test-results/*.json .test-results/SUMMARY.txt

if [ -f .env ]; then
  echo "note: host .env is NOT copied into the test container (clean env, same as CI)."
fi

"${C[@]}" build app
"${C[@]}" up -d --wait db
"${C[@]}" exec -T db mariadb -uroot -proot_test_pw -e "GRANT PROCESS ON *.* TO 'lotto'@'%'; FLUSH PRIVILEGES;"

set +e
tr -d '\r' < scripts/docker-test-inner.sh | "${C[@]}" run --rm --no-deps -T app bash -s -- "$LANE" "$@"
rc=$?
set -e

if [ "${KEEP_DB:-0}" != "1" ]; then
  "${C[@]}" down --remove-orphans >/dev/null 2>&1 || true
fi
echo
echo "Results: $(pwd)/.test-results/  (paste SUMMARY.txt back to Claude)"
exit $rc
