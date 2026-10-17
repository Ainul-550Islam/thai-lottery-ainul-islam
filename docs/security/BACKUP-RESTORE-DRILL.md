# 💾 Disaster Recovery & Ledger Backup Restore Drill Runbook

## 1. Immutable Backup Architecture
- **Continuous Write-Ahead Logging (WAL):** PostgreSQL / MySQL binary logs streamed to immutable AWS S3 bucket with Object Lock (WORM).
- **Hourly Point-In-Time Snapshots:** Automated encrypted volume snapshots retained for 365 days.

---

## 2. Restore Drill Verification Procedure

### Step 1: Spin up isolated staging restore sandbox
```bash
docker compose -f docker-compose.dr-sandbox.yml up -d db
```

### Step 2: Ingest point-in-time snapshot and replay binary logs
```bash
mysql -h dr-sandbox-db -u root -p lottery_ledger < backup_20261016_040000.sql
```

### Step 3: Run Ledger Cryptographic Hash & Balance Reconciliation Audit
```bash
php artisan ledger:verify-integrity
```
*Expected Output:*
```
[PASS] Ledger cryptographically verified. 0 hash-chain breaks detected across 1,000,000 journal lines.
[PASS] Total Debits equal Total Credits. Net delta = 0.00000000 THB.
```
