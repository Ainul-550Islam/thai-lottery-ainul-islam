#!/usr/bin/env bash
#
# Batch 3 verification gates G1-G8. Prints REAL command output and saves it under
# _logs/gates/. It reports facts; it never edits code and never "fixes" a failure.
#
# USAGE (from the repository root, on a THROWAWAY database only):
#   CONFIRM_THROWAWAY_DB=yes BEFORE_TOTAL=8629 BASE_REF=98ef46e \
#   DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/gates.sqlite \
#   bash scripts/verify-batch3-gates.sh
#
#   BEFORE_TOTAL  total test count measured BEFORE Batch 3 (from the baseline run)
#   BASE_REF      git commit/tag of the baseline (for G8)
#   ONLY          optional, e.g. ONLY="G2 G5" to run a subset
#
# Gate numbering follows the task prompt (not the delivery report's own numbering).

set -u
cd "$(dirname "$0")/.." || exit 2

OUT="_logs/gates"
mkdir -p "$OUT"
PHP="${PHP_BIN:-php}"
PHPUNIT="./vendor/bin/phpunit"
RESULT=()

if [ "${CONFIRM_THROWAWAY_DB:-no}" != "yes" ]; then
  echo "Refusing to run: G1 uses migrate:fresh, which DROPS every table."
  echo "Point DB_DATABASE at a throwaway database and set CONFIRM_THROWAWAY_DB=yes."
  exit 2
fi

want() { [ -z "${ONLY:-}" ] || [[ " ${ONLY} " == *" $1 "* ]]; }
record() { RESULT+=("$1|$2"); }

# Files Batch 3 created (used by the G7 grep proof). Edited files are excluded on
# purpose: they contain pre-existing balance code that Batch 3 did not write.
new_code_files() {
  find app/Services/BetSlip app/Services/MyBets app/Services/Wallet app/Services/Fx \
       app/Services/Account app/Services/Security app/Services/Notifications \
       app/Services/Support app/Services/Promotions app/Services/Compliance \
       app/Services/Ops app/Services/Loyalty/PointsMultiplierService.php \
       app/Http/Controllers/Api/V1 app/Http/Resources app/Http/Requests/Api/V1 \
       app/Jobs/Wallet app/Jobs/Betting app/Console/Commands/Wallet \
       app/Console/Commands/Fx app/Console/Commands/Betting \
       -type f -name '*.php' 2>/dev/null | sort
}

# ---------------------------------------------------------------- G1
if want G1; then
  echo "=== G1  migrate:fresh --seed"
  "$PHP" artisan migrate:fresh --seed --force 2>&1 | tee "$OUT/G1-migrate.log"
  rc=${PIPESTATUS[0]}
  [ "$rc" -eq 0 ] && record G1 "PASS (exit 0)" || record G1 "FAIL (exit $rc)"
fi

# ---------------------------------------------------------------- G2
if want G2; then
  echo "=== G2  the 25 new routes, no duplicates"
  "$PHP" artisan route:list --json > "$OUT/G2-routes.json" 2>"$OUT/G2-routes.err"
  "$PHP" -r '
    $r = json_decode(file_get_contents($argv[1]), true);
    if (!is_array($r)) { fwrite(STDERR, "route:list --json unreadable\n"); exit(2); }
    $want = [
      "GET api/v1/me/bet-slip","POST api/v1/me/bet-slip/items","DELETE api/v1/me/bet-slip/items/{item}",
      "DELETE api/v1/me/bet-slip","POST api/v1/me/bet-slip/confirm","GET api/v1/me/bets",
      "GET api/v1/me/bets/counts","GET api/v1/me/bets/{bet}","GET api/v1/me/wallet/overview",
      "GET api/v1/me/wallet/transactions","GET api/v1/me/profile/overview","GET api/v1/me/security/overview",
      "GET api/v1/me/security/alerts","GET api/v1/me/notification-preferences","PUT api/v1/me/notification-preferences",
      "GET api/v1/me/support/conversations","POST api/v1/me/support/conversations",
      "GET api/v1/me/support/conversations/{id}/messages","POST api/v1/me/support/conversations/{id}/messages",
      "POST api/v1/me/support/conversations/{id}/rating","GET api/v1/me/promotions/active",
      "GET api/v1/me/responsible-gaming/summary","GET api/v1/public/compliance-badges",
      "GET health/live","GET health/ready"
    ];
    $have = []; $names = []; $dupNames = [];
    foreach ($r as $row) {
      foreach (explode("|", $row["method"]) as $m) {
        if ($m === "HEAD") continue;
        $have[$m . " " . $row["uri"]] = ($have[$m . " " . $row["uri"]] ?? 0) + 1;
      }
      if (!empty($row["name"])) {
        $names[$row["name"]] = ($names[$row["name"]] ?? 0) + 1;
        if ($names[$row["name"]] > 1) $dupNames[$row["name"]] = true;
      }
    }
    $missing = array_values(array_filter($want, fn($w) => !isset($have[$w])));
    $dupUris = array_keys(array_filter($have, fn($n) => $n > 1));
    printf("total routes: %d\nexpected new routes found: %d of %d\n", count($r), count($want) - count($missing), count($want));
    if ($missing)  echo "MISSING: " . implode("; ", $missing) . "\n";
    if ($dupUris)  echo "DUPLICATE METHOD+URI: " . implode("; ", $dupUris) . "\n";
    if ($dupNames) echo "DUPLICATE NAMES: " . implode("; ", array_keys($dupNames)) . "\n";
    exit(($missing || $dupUris || $dupNames) ? 1 : 0);
  ' "$OUT/G2-routes.json" 2>&1 | tee "$OUT/G2-routes.log"
  rc=${PIPESTATUS[0]}
  [ "$rc" -eq 0 ] && record G2 "PASS (25/25, no duplicates)" || record G2 "FAIL (see $OUT/G2-routes.log)"
fi

# ---------------------------------------------------------------- G3
if want G3; then
  echo "=== G3  full suite BEFORE vs AFTER"
  "$PHPUNIT" 2>&1 | tee "$OUT/G3-full.log" | tail -n 25
  rc=${PIPESTATUS[0]}
  summary=$(grep -E "^(Tests:|OK \()" "$OUT/G3-full.log" | tail -n 1)
  echo "BEFORE_TOTAL=${BEFORE_TOTAL:-unset}"
  echo "AFTER summary: ${summary:-not found}"
  if [ "$rc" -eq 0 ]; then
    record G3 "PASS (0 failures/errors). ${summary}"
  else
    record G3 "FAIL (exit $rc). ${summary}"
  fi
fi

# ---------------------------------------------------------------- G4
if want G4; then
  echo "=== G4  ForbiddenClaims + OpenApiCoverage + Batch3Ownership"
  # tests/Architecture is excluded from the default phpunit.xml suite, so the files are
  # named explicitly. Do not "fix" a failure by removing a file from this list.
  "$PHPUNIT" tests/Architecture/ForbiddenClaimsTest.php \
             tests/Feature/Api/OpenApiCoverageTest.php \
             tests/Architecture/Batch3OwnershipTest.php 2>&1 | tee "$OUT/G4.log" | tail -n 15
  rc=${PIPESTATUS[0]}
  [ "$rc" -eq 0 ] && record G4 "PASS" || record G4 "FAIL (exit $rc)"
fi

# ---------------------------------------------------------------- G5
if want G5; then
  echo "=== G5  ledger:verify clean, then with an injected mismatch"
  echo "--- clean run on the current (seeded) database"
  "$PHP" artisan ledger:verify 2>&1 | tee "$OUT/G5-clean.log"
  clean_rc=${PIPESTATUS[0]}
  echo "exit code: $clean_rc"

  echo "--- injected mismatch (THROWAWAY DATABASE ONLY)"
  inject=$("$PHP" artisan tinker --execute='
    $id = \Illuminate\Support\Facades\DB::table("wallets")->min("id");
    if ($id === null) { echo "NO_WALLET"; return; }
    \Illuminate\Support\Facades\DB::table("wallets")->where("id", $id)->update(["balance" => \Illuminate\Support\Facades\DB::raw("balance + 1.00")]);
    echo "INJECTED wallet " . $id;
  ' 2>&1 | tail -n 1)
  echo "$inject" | tee "$OUT/G5-inject.log"

  if [[ "$inject" == INJECTED* ]]; then
    "$PHP" artisan ledger:verify 2>&1 | tee "$OUT/G5-mismatch.log"
    bad_rc=${PIPESTATUS[0]}
    echo "exit code: $bad_rc"
    if [ "$clean_rc" -eq 0 ] && [ "$bad_rc" -eq 1 ]; then
      record G5 "PASS (clean exit 0, injected mismatch exit 1)"
    else
      record G5 "FAIL (clean=$clean_rc injected=$bad_rc; expected 0 and 1)"
    fi
  else
    echo "The seeded database has no wallet, so a manual injection is impossible."
    echo "Falling back to the test that injects a mismatch through the checker:"
    "$PHPUNIT" tests/Feature/Ops/LedgerIntegrityCheckerTest.php 2>&1 | tee "$OUT/G5-test.log" | tail -n 8
    t_rc=${PIPESTATUS[0]}
    if [ "$clean_rc" -eq 0 ] && [ "$t_rc" -eq 0 ]; then
      record G5 "PARTIAL (clean exit 0; mismatch proven by test only, no wallet to inject manually)"
    else
      record G5 "FAIL (clean=$clean_rc test=$t_rc)"
    fi
  fi
fi

# ---------------------------------------------------------------- G6
if want G6; then
  echo "=== G6  concurrency x20, loyalty regression"
  fails=0
  for i in $(seq 1 20); do
    "$PHPUNIT" tests/Feature/BetSlip/BetSlipConcurrencyTest.php > "$OUT/G6-concurrency-$i.log" 2>&1 || fails=$((fails + 1))
    printf "run %02d/20 %s\n" "$i" "$([ "$fails" -eq 0 ] && echo ok-so-far || echo "failures=$fails")"
  done
  "$PHPUNIT" tests/Feature/Loyalty/LoyaltyPointsRegressionTest.php 2>&1 | tee "$OUT/G6-loyalty.log" | tail -n 8
  l_rc=${PIPESTATUS[0]}
  if [ "$fails" -eq 0 ] && [ "$l_rc" -eq 0 ]; then
    record G6 "PASS (20/20 concurrency runs, loyalty regression green). NOTE: single-process surrogate; true parallel proof is the load-test gate"
  else
    record G6 "FAIL (concurrency failures=$fails/20, loyalty exit=$l_rc)"
  fi
fi

# ---------------------------------------------------------------- G7
if want G7; then
  echo "=== G7  static analysis + direct-balance-write grep proof"
  if [ -x ./vendor/bin/phpstan ]; then
    ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G 2>&1 | tee "$OUT/G7-phpstan.log" | tail -n 15
    s_rc=${PIPESTATUS[0]}
  else
    echo "phpstan is not installed in this checkout; static analysis NOT run."
    s_rc=99
  fi

  echo "--- grep: direct balance writes in NEW code (expect no output)"
  new_code_files > "$OUT/G7-new-files.txt"
  grep -nE '(->balance\s*=[^=]|->(increment|decrement)\(\s*.balance|(update|updateOrInsert|upsert)\(\s*\[[^]]*.balance.\s*=>|DB::table\(.wallets.\)->(update|increment|decrement))' \
       $(cat "$OUT/G7-new-files.txt") 2>/dev/null | tee "$OUT/G7-balance-writes.log"
  hits=$(wc -l < "$OUT/G7-balance-writes.log")
  echo "direct balance write hits: $hits"

  if [ "$s_rc" -eq 99 ]; then
    record G7 "NOT RUN: phpstan missing (grep proof: $hits hits)"
  elif [ "$s_rc" -eq 0 ] && [ "$hits" -eq 0 ]; then
    record G7 "PASS (static analysis clean, 0 direct balance writes). Compare with baseline error count if the repo keeps one"
  else
    record G7 "FAIL (phpstan exit $s_rc, grep hits $hits)"
  fi
fi

# ---------------------------------------------------------------- G8
if want G8; then
  echo "=== G8  every EDIT is additive"
  if [ -z "${BASE_REF:-}" ]; then
    record G8 "NOT RUN: set BASE_REF to the baseline commit"
  else
    git diff --numstat "$BASE_REF" -- . ':(exclude)*.bak' ':(exclude)*.bak-hardening' 2>/dev/null \
      | tee "$OUT/G8-numstat.log"
    echo "--- every REMOVED line in tracked files (review each one by hand):"
    git diff -U0 "$BASE_REF" -- app routes config bootstrap resources database \
         ':(exclude)*.bak' ':(exclude)*.bak-hardening' 2>/dev/null \
      | grep -E '^-[^-]' | tee "$OUT/G8-removed-lines.log"
    removed=$(wc -l < "$OUT/G8-removed-lines.log")
    deleted=$(git diff --name-status "$BASE_REF" 2>/dev/null | grep -c '^D')
    echo "removed lines: $removed   deleted files: $deleted"
    if [ "$removed" -eq 0 ] && [ "$deleted" -eq 0 ]; then
      record G8 "PASS (additions only)"
    else
      record G8 "REVIEW ($removed removed lines, $deleted deleted files; each needs a written justification)"
    fi
  fi
  echo "Reuse proof (new service -> existing class): docs/ops/BATCH3_REUSE_PROOF.md"
fi

echo
echo "================ GATE SUMMARY ================"
for r in "${RESULT[@]}"; do printf "%-4s %s\n" "${r%%|*}" "${r#*|}"; done
echo "Logs: $OUT/"

for r in "${RESULT[@]}"; do
  case "${r#*|}" in FAIL*|NOT\ RUN*|REVIEW*) exit 1 ;; esac
done
exit 0
