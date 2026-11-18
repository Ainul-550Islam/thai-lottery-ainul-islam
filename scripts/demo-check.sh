#!/usr/bin/env bash
# Checks a running demo stack (docker-compose.demo.yml) and prints PASS/FAIL per check.
# Run from anywhere:  bash scripts/demo-check.sh
# Read-only: it changes nothing.
export MSYS_NO_PATHCONV=1
cd "$(dirname "$0")/.." || exit 2

C="docker compose -f docker-compose.demo.yml"
PORT="${DEMO_PORT:-8080}"
DBPW="${DEMO_DB_PASSWORD:-demo-only-change-me}"
ADMIN_EMAIL="${DEMO_ADMIN_EMAIL:-demo@example.test}"
pass=0
fail=0

ok()  { printf '  PASS  %s\n' "$1"; pass=$((pass + 1)); }
bad() { printf '  FAIL  %s\n' "$1"; fail=$((fail + 1)); }
eq()  { if [ "$3" = "$2" ]; then ok "$1"; else bad "$1 (expected '$2', got '${3:-<empty>}')"; fi; }
gt0() { if [ "${2:-0}" -gt 0 ] 2>/dev/null; then ok "$1 ($2)"; else bad "$1 (got '${2:-<empty>}')"; fi; }

sql()  { $C exec -T db mysql -ulottery -p"$DBPW" thai_lottery -N -B -e "$1" 2>/dev/null | tr -d '\r'; }
http() { curl -s -o /dev/null -m 20 -w '%{http_code}' "http://localhost:$PORT$1" 2>/dev/null; }

echo "Containers"
running=$($C ps --status running --services 2>/dev/null | sort | tr '\n' ' ')
eq "app and db are running" "app db " "$running"
app_id=$($C ps -q app 2>/dev/null)
health=$(docker inspect --format '{{.State.Health.Status}}' "$app_id" 2>/dev/null)
eq "app healthcheck" "healthy" "$health"

echo "HTTP (http://localhost:$PORT)"
live=$(curl -s -m 20 "http://localhost:$PORT/api/live" 2>/dev/null)
case "$live" in
    *'"UP"'*) ok "/api/live reports UP" ;;
    *) bad "/api/live reports UP (got '${live:-<empty>}')" ;;
esac
for p in / /login /register /national-lottery /lotteries /weekly-lottery /results /glo-l6 /about /contact /services /lotto-discount /admin/login; do
    eq "GET $p" 200 "$(http "$p")"
done
eq "GET /api/v1/draws requires auth" 401 "$(http /api/v1/draws)"

echo "Database"
gt0 "migrations applied" "$(sql 'select count(*) from migrations')"
eq  "seeded roles" 5 "$(sql 'select count(*) from roles')"
gt0 "draws exist" "$(sql 'select count(*) from draws')"
gt0 "bet capacity rows (number_limits)" "$(sql 'select count(*) from number_limits')"
eq  "demo admin account is active" active "$(sql "select status from users where email='$ADMIN_EMAIL'")"

echo "Bootstrap and logs"
marker=$($C exec -T app sh -c 'test -f storage/demo/bootstrapped && echo yes || echo no' 2>/dev/null | tr -d '\r')
eq "bootstrap finished (marker present)" yes "$marker"
logs=$($C logs app 2>&1)
gt0 "demo banner printed" "$(printf '%s\n' "$logs" | grep -c 'THAI LOTTERY DEMO')"
gt0 "admin panel access confirmed in log" "$(printf '%s\n' "$logs" | grep -c 'panel access: yes')"
eq  "no local.ERROR in app log" 0 "$(printf '%s\n' "$logs" | grep -c 'local.ERROR')"

echo "Safety"
eq "REAL_MONEY_ENABLED is false" false "$($C exec -T app printenv REAL_MONEY_ENABLED 2>/dev/null | tr -d '\r')"
eq "app is published on 127.0.0.1 only" "127.0.0.1:$PORT" "$($C port app 8000 2>/dev/null | tr -d '\r')"

echo "Info (read-only, not scored)"
$C exec -T app php artisan risk:provision-number-limits --open --check 2>&1 | head -12
sql 'select id, draw_number from draws order by id'

echo
echo "Result: $pass passed, $fail failed"
echo "Not covered here: admin login in a browser, placing a bet, wallet funding."
[ "$fail" -eq 0 ]
