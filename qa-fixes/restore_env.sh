#!/bin/sh
# Restores everything a sandbox reset removes: Composer, vendor/, frontend build,
# Rust verifier, MariaDB, and all idempotent round fixes. Safe to run repeatedly.
set -u
ROOT=/home/user/thai-lottery-ainul-islam
mkdir -p /home/user/.local/bin
[ -x /home/user/.local/bin/composer ] || { curl -sS https://getcomposer.org/installer -o /tmp/cs.php && php /tmp/cs.php --install-dir=/home/user/.local/bin --filename=composer >/dev/null 2>&1; }
cd "$ROOT" || exit 1
if [ ! -f vendor/composer/autoload_real.php ]; then
  for i in 1 2 3 4; do php /home/user/.local/bin/composer install --no-scripts --no-interaction >/tmp/restore_composer.log 2>&1 && break; sleep 15; done
fi
[ -f public/build/manifest.json ] || sh -c 'npm ci --no-audit --no-fund && npm run build' >/tmp/restore_npm.log 2>&1
[ -x security/weekly-result-integrity/target/release/weekly-result-integrity ] || (cd security/weekly-result-integrity && cargo build --release >/tmp/restore_cargo.log 2>&1)
sudo -n service mariadb status >/dev/null 2>&1 || sudo -n service mariadb start >/dev/null 2>&1
cd /home/user/qa-fixes && for s in apply_round7.py apply_round7b.py apply_round8.py apply_round9.py apply_round10.py apply_round11.py; do python3 "$s" >/dev/null 2>&1 || echo "round failed: $s"; done
cd "$ROOT"
for f in vendor/composer/autoload_real.php public/build/manifest.json security/weekly-result-integrity/target/release/weekly-result-integrity app/Services/Compliance/RealMoneyGate.php; do [ -e "$f" ] && echo "ok $f" || echo "MISSING $f"; done
