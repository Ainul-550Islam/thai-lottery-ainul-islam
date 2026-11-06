#!/usr/bin/env bash
# Run from the project root (the folder that contains artisan):  bash setup-docker-test-lane.sh
set -Eeuo pipefail
[ -f artisan ] && [ -f composer.json ] || { echo "Run this from the Laravel project root (artisan not found)." >&2; exit 2; }
mkdir -p docker/php scripts
cat > 'docker/php/Dockerfile.test' <<'__LANE_EOF_1__'
# Test-only image (NOT the production image). PHP 8.4 CLI + every extension the suite needs,
# MariaDB client, Redis server (settlement-recovery tests) and python3 (race runners).
FROM php:8.4-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS git unzip rsync procps python3 \
        libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev \
        mariadb-client redis-server redis-tools \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" bcmath gd intl pcntl pdo_mysql zip \
    && pecl install redis && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN printf 'memory_limit=-1\nerror_reporting=E_ALL\n' > /usr/local/etc/php/conf.d/zz-tests.ini

WORKDIR /app
__LANE_EOF_1__
cat > 'docker-compose.test-docker.yml' <<'__LANE_EOF_2__'
# Whole test stack inside Docker (PHP + MariaDB on one Linux kernel).
# WHY: the 290 skipped tests need MariaDB reached through a REAL Unix socket
# (tests/Support/RunsServerFinancialRaces.php asserts filetype($socket) === 'socket').
# A socket cannot cross from Windows into a container via a bind mount, so PHP itself
# runs in a container and shares /run/mysqld with MariaDB through a named volume.
name: thai-lottery-test

services:
  sockperm:
    image: alpine:3.20
    command: ["sh", "-c", "rm -f /run/mysqld/mysqld.sock; chmod 0777 /run/mysqld"]
    volumes:
      - dbsock:/run/mysqld
    restart: "no"

  db:
    image: mariadb:11.4
    depends_on:
      sockperm:
        condition: service_completed_successfully
    environment:
      MARIADB_DATABASE: thai_lottery_test
      MARIADB_USER: lotto
      MARIADB_PASSWORD: lotto_test_pw
      MARIADB_ROOT_PASSWORD: root_test_pw
    command:
      - --character-set-server=utf8mb4
      - --collation-server=utf8mb4_unicode_ci
      - --innodb-buffer-pool-size=256M
      - --transaction-isolation=READ-COMMITTED
      - --innodb-lock-wait-timeout=15
      - --max-connections=300
    volumes:
      - dbsock:/run/mysqld
    tmpfs:
      - /var/lib/mysql:rw,size=1g
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 2s
      timeout: 5s
      retries: 45
      start_period: 10s
    restart: "no"

  app:
    build:
      context: ./docker/php
      dockerfile: Dockerfile.test
    depends_on:
      db:
        condition: service_healthy
    working_dir: /app
    environment:
      COMPOSER_ALLOW_SUPERUSER: "1"
      COMPOSER_MEMORY_LIMIT: "-1"
    volumes:
      - ./:/host:ro                 # your project, read-only (synced into /app on every run)
      - ./.test-results:/out        # logs + junit + SUMMARY.txt come back here
      - src:/app                    # Linux filesystem copy: fast, correct perms, no CRLF/NTFS quirks
      - vendor:/app/vendor          # composer install cached between runs
      - composer-cache:/root/.cache/composer
      - dbsock:/run/mysqld
    restart: "no"

volumes:
  dbsock:
  src:
  vendor:
  composer-cache:
__LANE_EOF_2__
cat > 'scripts/docker-test.sh' <<'__LANE_EOF_3__'
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
tr -d '\r' < scripts/docker-test-inner.sh | "${C[@]}" run --rm -T app bash -s -- "$LANE" "$@"
rc=$?
set -e

if [ "${KEEP_DB:-0}" != "1" ]; then
  "${C[@]}" down --remove-orphans >/dev/null 2>&1 || true
fi
echo
echo "Results: $(pwd)/.test-results/  (paste SUMMARY.txt back to Claude)"
exit $rc
__LANE_EOF_3__
cat > 'scripts/docker-test-inner.sh' <<'__LANE_EOF_4__'
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
    python3 scripts/run_financial_concurrency.py --suite all --database thai_lottery_test --connection mysql \
      --socket "$SOCK" --username lotto --acknowledge-disposable-database --repeats 1 \
      --json "$OUT/races.json" 2>&1 | tee "$OUT/races.log" ) || echo "lane races aborted"
}

case "$LANE" in
  sqlite) lane_sqlite ;;
  mysql)  lane_mysql ;;
  races)  lane_races ;;
  all)    lane_sqlite; lane_mysql ;;
esac

echo; echo "==> summary"
python3 scripts/summarize_junit.py "$OUT" | tee "$OUT/SUMMARY.txt"
__LANE_EOF_4__
cat > 'scripts/summarize_junit.py' <<'__LANE_EOF_5__'
#!/usr/bin/env python3
"""Summarise PHPUnit lanes (JUnit XML + console logs) into one paste-able report.

usage: summarize_junit.py <results-dir>
"""
import collections
import json
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path


def clip(text, n=400):
    text = " ".join((text or "").split())
    return text if len(text) <= n else text[: n - 3] + "..."


def load_cases(xml_path):
    cases = []
    for tc in ET.parse(xml_path).getroot().iter("testcase"):
        status, message = "pass", ""
        for kind in ("error", "failure", "skipped"):
            node = tc.find(kind)
            if node is not None:
                status = kind
                message = ((node.get("message") or "") + "\n" + (node.text or "")).strip()
                break
        cases.append({
            "class": tc.get("class") or tc.get("classname") or "",
            "name": tc.get("name") or "",
            "file": tc.get("file") or "",
            "line": tc.get("line") or "",
            "status": status,
            "message": message,
        })
    return cases


SKIP_HEADER = re.compile(r"skipped[^\n:]*:\s*$", re.I)
ENTRY = re.compile(r"^\d+\) (.+)$")


def skip_reasons_from_log(log_path):
    """Best-effort parse of PHPUnit's --display-skipped section: reason -> count."""
    if not log_path.exists():
        return {}
    lines = log_path.read_text(errors="replace").splitlines()
    reasons = collections.Counter()
    i = 0
    while i < len(lines):
        if SKIP_HEADER.search(lines[i]) and not lines[i].lstrip().startswith(("Tests:", "OK")):
            i += 1
            while i < len(lines):
                if ENTRY.match(lines[i]):
                    j = i + 1
                    reason = []
                    while j < len(lines) and lines[j].strip() and not lines[j].lstrip().startswith("/"):
                        reason.append(lines[j].strip())
                        j += 1
                    reasons[clip(" ".join(reason), 200) or "(no reason given)"] += 1
                    i = j
                elif re.match(r"^(There (was|were)|\d+ tests?\b.*:)\s*", lines[i]) and not SKIP_HEADER.search(lines[i]):
                    break
                else:
                    i += 1
            continue
        i += 1
    return dict(reasons)


def report_lane(name, xml_path, log_path, out):
    cases = load_cases(xml_path)
    by = collections.Counter(c["status"] for c in cases)
    out.append(f"\n{'=' * 78}\nLANE {name}: {len(cases)} tests | pass {by['pass']} | "
               f"errors {by['error']} | failures {by['failure']} | skipped {by['skipped']}\n{'=' * 78}")

    bad = [c for c in cases if c["status"] in ("error", "failure")]
    if bad:
        out.append(f"\n-- {len(bad)} ERRORS/FAILURES --")
        for n, c in enumerate(bad, 1):
            loc = f"{c['file']}:{c['line']}" if c["file"] else ""
            out.append(f"\n[{n}] {c['status'].upper()}  {c['class']}::{c['name']}\n    {loc}")
            for ln in [l for l in c["message"].splitlines() if l.strip()][:6]:
                out.append("    | " + clip(ln, 300))

    skipped = [c for c in cases if c["status"] == "skipped"]
    if skipped:
        out.append(f"\n-- {len(skipped)} SKIPPED --")
        reasons = skip_reasons_from_log(log_path)
        if reasons:
            out.append("by reason (from console log):")
            for reason, count in sorted(reasons.items(), key=lambda kv: -kv[1]):
                out.append(f"  {count:>4}  {reason}")
        else:
            out.append("(no skip reasons found in the console log)")
        per_class = collections.Counter(c["class"] for c in skipped)
        out.append("by test class (top 25):")
        for cls, count in per_class.most_common(25):
            out.append(f"  {count:>4}  {cls}")
    return by


def main():
    results = Path(sys.argv[1] if len(sys.argv) > 1 else ".")
    out = ["Thai-lottery Docker test summary"]
    found = False
    for name in ("sqlite", "mysql"):
        xml_path = results / f"{name}.xml"
        if xml_path.exists():
            found = True
            try:
                report_lane(name, xml_path, results / f"{name}.log", out)
            except ET.ParseError as exc:
                out.append(f"\nLANE {name}: junit unreadable ({exc}); see {name}.log")
        elif (results / f"{name}.log").exists():
            found = True
            tail = (results / f"{name}.log").read_text(errors="replace").splitlines()[-25:]
            out.append(f"\nLANE {name}: no junit written (phpunit crashed early). Last log lines:")
            out += ["    " + l for l in tail]

    races = results / "races.json"
    if races.exists():
        found = True
        try:
            data = json.loads(races.read_text())
            out.append(f"\n{'=' * 78}\nLANE races: {clip(json.dumps(data), 1500)}\n{'=' * 78}")
        except ValueError:
            out.append("\nLANE races: races.json unreadable; see races.log")
    elif (results / "races.log").exists():
        found = True
        out.append("\nLANE races: failed before writing JSON. Last log lines:")
        out += ["    " + l for l in (results / "races.log").read_text(errors="replace").splitlines()[-25:]]

    if not found:
        out.append("No lane output found - the container failed before phpunit ran (check the console above).")
    print("\n".join(out))


if __name__ == "__main__":
    main()
__LANE_EOF_5__
chmod +x scripts/docker-test.sh scripts/docker-test-inner.sh scripts/summarize_junit.py 2>/dev/null || true
# LF enforcement for scripts (only created if you have no .gitattributes yet)
if [ ! -f .gitattributes ]; then
cat > .gitattributes <<'__LANE_EOF_GA__'
# Keep shell/python/Docker files LF so they run inside Linux containers even when the
# Windows working tree uses core.autocrlf=true.
*.sh        text eol=lf
*.py        text eol=lf
Dockerfile* text eol=lf
artisan     text eol=lf
__LANE_EOF_GA__
fi
touch .gitignore
grep -qxF '.test-results/' .gitignore || printf '\n.test-results/\n' >> .gitignore
echo "Installed. Now run:  bash scripts/docker-test.sh sqlite"
