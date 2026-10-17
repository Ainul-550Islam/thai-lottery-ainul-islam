#!/usr/bin/env bash

set -euo pipefail

echo "================================================================================"
echo "🛡️ THAI LOTTERY ENTERPRISE — MASTER SECURITY & FINANCIAL VERIFICATION SUITE"
echo "================================================================================"

# 1. PHPStan Static Analysis (Level 8)
echo "[1/5] Running PHPStan Static Analysis (Level 8 Strictness)..."
vendor/bin/phpstan analyse --memory-limit=2G

# 2. PHPUnit Full Test Suite
echo "[2/5] Running PHPUnit Test Matrix (24 Test Classes)..."
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
