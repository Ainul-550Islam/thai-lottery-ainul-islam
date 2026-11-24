#!/usr/bin/env bash
# collect-for-claude.sh  --  READ-ONLY: it changes nothing in your project.
#
# Run it from the project root (the folder that contains `artisan`), in Git Bash:
#     bash collect-for-claude.sh
# Optional: pass the merge-backup folder yourself:
#     bash collect-for-claude.sh .merge-backup/20261121-150112
#
# It writes ONE text file (default C:\temp\tl\claude-context.txt) with the code and
# diffs needed to review the merge. Env files: only KEY NAMES are printed, never values.

set -u

[ -f artisan ] || { echo "Run this from the project root (no 'artisan' file here)."; exit 1; }

BK="${1:-$(ls -d .merge-backup/*/ 2>/dev/null | sort | tail -n 1)}"
BK="${BK%/}"
[ -d "$BK" ] || { echo "Merge backup folder not found. Pass it as an argument, e.g. .merge-backup/20261121-150112"; exit 1; }

OUTDIR="${OUTDIR:-/c/temp/tl}"
mkdir -p "$OUTDIR"
OUT="$OUTDIR/claude-context.txt"

section() { printf '\n\n################ %s ################\n' "$1"; }

# show FILE [MAX_LINES]  -> numbered listing of a file
show() {
  local f="$1" max="${2:-300}"
  if [ -f "$f" ]; then
    printf '\n--- %s (%s lines total) ---\n' "$f" "$(wc -l < "$f")"
    head -n "$max" "$f" | cat -n
  else
    printf '\n--- %s : NOT FOUND ---\n' "$f"
  fi
}

# dif FILE [MAX_LINES]  -> your pre-merge copy ("-") versus the file now ("+")
dif() {
  local f="$1" max="${2:-250}"
  printf '\n--- diff %s  (YOUR backup "-"  ->  NOW "+") ---\n' "$f"
  if   [ ! -f "$BK/$f" ]; then echo "(no backup copy in $BK)"
  elif [ ! -f "$f" ];     then echo "(file no longer exists)"
  else diff -u --strip-trailing-cr "$BK/$f" "$f" | head -n "$max"
  fi
}

# keys FILE -> sorted unique env KEY names (values are dropped on purpose)
keys() { sed 's/\r$//' "$1" | grep -E '^[A-Za-z_][A-Za-z0-9_]*=' | cut -d= -f1 | sort -u; }

# tests_in FILE -> sorted unique test method names
tests_in() { grep -oE 'function test_[A-Za-z0-9_]+' "$1" 2>/dev/null | sed 's/function //' | sort -u; }

BIG=(
  tests/Feature/Filament/FinanceResourcesTest.php
  tests/Feature/Payment/WithdrawalDestinationValidationTest.php
  tests/Feature/Security/BotDetectionMiddlewareTest.php
  tests/Unit/Accounting/ThaiRevenueDepartmentTaxReportServiceTest.php
)
SMALL=(
  tests/Feature/Filament/DepositSlipVerificationResourceTest.php
  tests/Feature/Filament/IdentityResourcesTest.php
  tests/Feature/Ledger/DoubleEntryLedgerServerRaceTest.php
  tests/Security/SecurityNextHardeningVerificationTest.php
  tests/Unit/Production/DependencyAuditBoundaryTest.php
)

main() {
  section "1. BASICS"
  echo "backup folder used: $BK"
  php -v 2>&1 | head -n 1
  php artisan --version 2>&1 | head -n 1
  echo "branch: $(git rev-parse --abbrev-ref HEAD 2>&1)"
  git log --oneline -n 5 2>&1
  echo; echo "--- git status (first 40) ---"
  git status --short 2>&1 | head -n 40
  echo; echo "--- changed files OUTSIDE app/ (git diff --stat) ---"
  git diff --stat=110 -- . ':!app' 2>&1 | tail -n 60

  section "2. FAILING TEST: production simulation seeder"
  T=tests/Feature/Security/PromptConfigurationRoutesAndSeederCoverageTest.php
  show "$T" 120
  echo; echo "--- every file that mentions ALLOW_PRODUCTION_SIMULATION_SEEDER ---"
  grep -rIl "ALLOW_PRODUCTION_SIMULATION_SEEDER" . \
    --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git \
    --exclude-dir=.merge-backup --exclude-dir=storage 2>/dev/null
  echo; echo "--- database/seeders ---"
  ls -la database/seeders 2>&1
  echo; echo "--- seeder history (git) ---"
  git log --oneline -n 3 -- database/seeders 2>&1
  # seeder candidates: any "...Seeder" class the test names, plus any file called *simulation*
  { grep -oE '[A-Za-z0-9_]*Seeder' "$T" 2>/dev/null | sort -u | while IFS= read -r n; do
      find database -type f -name "$n.php" 2>/dev/null
    done
    find database -type f -iname '*simulation*' 2>/dev/null
  } | sort -u | while IFS= read -r s; do
    show "$s" 400
    echo "--- the test's four text checks, run on $s (0 = not found) ---"
    echo "environment('production')                 : $(grep -cF "environment('production')" "$s")"
    echo "DB::transaction                           : $(grep -cF 'DB::transaction' "$s")"
    echo "ALLOW_PRODUCTION_SIMULATION_SEEDER        : $(grep -cF 'ALLOW_PRODUCTION_SIMULATION_SEEDER' "$s")"
    echo "'password' => 'password' (must be 0)      : $(grep -cF "'password' => 'password'" "$s")"
    echo "--- key lines in $s (line numbers; cat -A reveals hidden characters) ---"
    grep -nE "nvironment|isProduction|DB::|ALLOW_PRODUCTION|'password'|function run" "$s" | cat -A | head -n 40
    dif "$s" 150
  done

  section "3. MONEY / COMPLIANCE FILES (highest risk)"
  for f in config/real_money.php routes/web.php config/payment.php config/fraud.php; do
    dif "$f" 300
  done

  section "4. ENV FILES: KEY names only (values are never printed)"
  for f in .env.ci .env.example .env.production.example; do
    echo; echo "=== $f ==="
    if [ -f "$BK/$f" ] && [ -f "$f" ]; then
      echo "lines in your backup / now: $(wc -l < "$BK/$f") / $(wc -l < "$f")"
      echo "lines repeated more than once in YOUR backup (values hidden):"
      sed 's/\r$//' "$BK/$f" | sed -E 's/^([A-Za-z_][A-Za-z0-9_]*)=.*/\1=<hidden>/' \
        | sort | uniq -c | sort -rn | awk '$1>1' | head -n 4 | cut -c1-140
      echo "KEYS only in YOUR backup (missing now):"
      comm -23 <(keys "$BK/$f") <(keys "$f") | head -n 120
      echo "KEYS only in the NEW file:"
      comm -13 <(keys "$BK/$f") <(keys "$f") | head -n 120
    else
      echo "(missing copy)"
    fi
  done

  section "5. TESTS: methods that were in your version but are gone now"
  for f in "${BIG[@]}" "${SMALL[@]}"; do
    echo; echo "=== $f ==="
    if [ -f "$BK/$f" ] && [ -f "$f" ]; then
      echo "test methods only in YOUR backup:"
      comm -23 <(tests_in "$BK/$f") <(tests_in "$f")
      echo "test methods only in the NEW file:"
      comm -13 <(tests_in "$BK/$f") <(tests_in "$f")
    else
      echo "(missing copy)"
    fi
  done
  for f in "${SMALL[@]}"; do
    dif "$f" 120
  done

  section "6. TOOL VERSIONS (for phpstan.neon and the dependency test)"
  show phpstan.neon 80
  echo
  for p in laravel/framework phpunit/phpunit pestphp/pest phpstan/phpstan larastan/larastan; do
    v=$(grep -A1 "\"name\": \"$p\"" composer.lock 2>/dev/null | grep -m1 '"version"' | tr -d ' ,"')
    echo "$p -> ${v:-not in composer.lock}"
  done
  show tests/Unit/Production/DependencyAuditBoundaryTest.php 80

  echo; echo "################ END ################"
}

main > "$OUT" 2>&1
echo "Done: $(wc -l < "$OUT") lines written to $OUT"
echo "Windows path: C:\\temp\\tl\\claude-context.txt  (upload this one file to the chat)"
