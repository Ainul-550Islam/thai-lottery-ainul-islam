#!/usr/bin/env bash

set -euo pipefail

echo "================================================================================"
echo "🛡️ THAI LOTTERY ENTERPRISE — MASTER SECURITY & FINANCIAL VERIFICATION SUITE"
echo "================================================================================"

# 1. Static Analysis, Syntax, Style & Write-Boundary Gates
echo "[1/5] Running Static Analysis, Syntax, Style & Write-Boundary Gates..."
if [ -x vendor/bin/phpstan ]; then
    vendor/bin/phpstan analyse --memory-limit=2G
else
    find app bootstrap config database routes tests -name '*.php' -print0 | xargs -0 -n1 -P4 php -l > /dev/null
    vendor/bin/pint --test
fi
php scripts/check-env-parity.php --json > /dev/null
bash scripts/guard-write-boundary.sh
bash scripts/verify-result-write-boundary.sh

# 2. PHPUnit Full Test Suite
echo "[2/5] Running PHPUnit Test Matrix..."
vendor/bin/phpunit --colors=always

# 3. Security Readiness Audit
echo "[3/5] Running Production Security Readiness Check..."
php artisan security:readiness-check

# 4. Bank-to-Ledger 3-Way Reconciliation
echo "[4/5] Running Automated Financial Reconciliation..."
php artisan ledger:reconcile-bank

# 5. Stress Benchmark & Zero-Drift Invariant Proof
echo "[5/5] Running Financial Lifecycle Simulation Proof..."
php artisan test:financial-lifecycle-proof

echo "================================================================================"
echo "✅ ALL VERIFICATIONS PASSED WITH ZERO DRIFT AND ZERO SECURITY DEFICIENCIES."
echo "================================================================================"
