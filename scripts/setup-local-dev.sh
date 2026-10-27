#!/usr/bin/env bash

set -euo pipefail

echo "================================================================================"
echo "🚀 THAI LOTTERY ENTERPRISE — LOCAL DEVELOPMENT & SECURITY INITIALIZATION"
echo "================================================================================"

# 1. Check Docker & Docker Compose
if ! command -v docker &> /dev/null; then
    echo "❌ Error: Docker is required but not installed."
    exit 1
fi

# 2. Boot Infrastructure Containers (PostgreSQL 16, Redis 7, ClamAV Socket)
echo "📦 Starting Docker containers (PostgreSQL 16, Redis 7, ClamAV Antivirus)..."
docker compose -f docker-compose.production.yml up -d db redis clamav

# 3. Wait for PostgreSQL Readiness
echo "⏳ Waiting for PostgreSQL database to accept connections..."
until docker compose -f docker-compose.production.yml exec -T db pg_isready -U lottery_user -d lottery_ledger > /dev/null 2>&1; do
    sleep 1
done
echo "✓ Database is online and responsive."

# 4. Run Migrations
echo "📜 Running database schema migrations..."
php artisan migrate --force

# 5. Seed Production Demonstration Data
echo "🌱 Seeding Chart of Accounts, Agent Hierarchy, and GLO Draws..."
php artisan db:seed --class=Database\\Seeders\\ProductionSimulationSeeder

# 6. Verify Production Security Readiness
echo "🛡️ Running Security Readiness Audit..."
php artisan security:readiness-check

# 7. Execute End-to-End Financial Lifecycle Proof
echo "💎 Executing Financial Double-Entry Invariant Proof..."
php artisan test:financial-lifecycle-proof

echo "================================================================================"
echo "🎉 SUCCESS: Local environment initialized and all security invariants verified!"
echo "   • SOC & Financial Dashboard : http://localhost:8000/admin/index.html"
echo "   • OpenAPI 3.1 Documentation : http://localhost:8000/docs/index.html"
echo "   • Prometheus Telemetry API  : http://localhost:8000/api/v1/metrics"
echo "================================================================================"
