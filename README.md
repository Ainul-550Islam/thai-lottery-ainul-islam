# 🇹🇭 Thai Lottery Enterprise Financial Double-Entry Ledger & Security Suite

[![Security Rating](https://img.shields.io/badge/Security-Hardened%20(Phases%2001--05)-brightgreen.svg)]()
[![Ledger Invariant](https://img.shields.io/badge/Ledger%20Invariant-Zero%20Drift%20(Debits%20===%20Credits)-blue.svg)]()
[![PHPStan](https://img.shields.io/badge/PHPStan-Level%208%20Strict-success.svg)]()
[![Infrastructure](https://img.shields.io/badge/Kubernetes-EKS%201.29%20%7C%20HPA%2010--100%20Pods-blue.svg)]()

Production-grade, high-concurrency multi-tier agent lottery management and double-entry financial ledger platform built for high-throughput scalability (10,000 TPS) with a zero-trust enterprise security posture.

---

## 🏛️ Key System Architecture

```
 [ External Client / POS Terminal ] 
                │
                ▼
 [ PERIMETER LAYER ]
    ├──► TrustProxies (Explicit reverse-proxy CIDR resolution)
    ├──► IpBlocklistMiddleware (Bitwise IPv4/IPv6 CIDR matching via IpCidrMatcher)
    ├──► BotDetectionMiddleware (6-signal weighted scoring: UA, Timing, Velocity, Device HMAC)
    ├──► SecurityHeaders (HSTS, CSP, X-Frame-Options DENY, XSS-Protection)
    └──► SlidingWindowRateLimiter (Redis atomics for DDoS / brute-force protection)
                │
                ▼
 [ APPLICATION CONTROLLER & GATE LAYER ]
    ├──► OrderPlacementController (Batch ticket orders with IdempotencyLockMiddleware)
    ├──► GloResultIngestionController (4-Eyes Maker-Checker draw certification)
    ├──► PromptPay & Bank Webhooks (SCB / KBank HMAC-SHA256 signature verification)
    ├──► Agent POS Terminal (ESC/POS thermal receipt formatting & digital signatures)
    └──► Admin Security API (IP blocks, fraud case reviews, quarantine rescans)
                │
                ▼
 [ DOMAIN & FINANCIAL CORE SERVICES ]
    ├──► DoubleEntryLedgerService (Asset/Liability/Equity/Revenue/Expense invariant)
    ├──► WalletMutationService (Pessimistic locking + SHA-256 hash chaining)
    ├──► MultiTierCommissionDistributionService (Dynamic recursive agent hierarchy)
    ├──► DrawSettlementCoordinatorService (Chunked batch winning settlement)
    ├──► FraudDetectionService (Velocity, KYC mismatch, rapid deposit-to-withdrawal)
    └──► UploadScanService (Private disk quarantine + ClamAV socket streaming)
                │
                ▼
 [ STORAGE & INFRASTRUCTURE LAYER ]
    ├──► Amazon Aurora PostgreSQL 16 (Authoritative partitioned ledger, JSONB audit)
    ├──► Amazon ElastiCache Redis 7.1 Cluster (Redlock distributed locks, sliding rate limiters)
    ├──► ClamAV Antimalware Socket (TCP 3310 streaming INSTREAM scanner)
    └──► Laravel Horizon & Reverb (High-throughput async queues + WebSocket broadcast)
```

---

## 🚀 Quick Start Guide

### 1. Initialize Local Development Environment
```bash
./scripts/setup-local-dev.sh
```

### 2. Run Master Verification Suite (PHPStan + PHPUnit + Security + Invariants)
```bash
./scripts/run-all-verifications.sh
```

---

## 📑 Core Documentation & Consoles
- **Interactive SOC & Financial Dashboard:** Open `public/admin/index.html` in your browser.
- **OpenAPI 3.1 Swagger Specification:** Open `public/docs/index.html` in your browser.
- **Operational Security Runbooks:** Located in `docs/security/`:
  - `docs/security/PHASE-0-BASELINE-AUDIT.md`
  - `docs/security/PHASE-1-P0-HARDENING-REPORT.md`
  - `docs/security/IP-BLOCKLIST-RUNBOOK.md`
  - `docs/security/BOT-ABUSE-RUNBOOK.md`
  - `docs/security/FRAUD-RESPONSE-RUNBOOK.md`
  - `docs/security/MALWARE-UPLOAD-RUNBOOK.md`
  - `docs/security/INCIDENT-RESPONSE.md`
  - `docs/security/BACKUP-RESTORE-DRILL.md`
  - `docs/security/PHASE-1-5-IMPLEMENTATION-REPORT.md`
  - `docs/security/REMAINING-GAPS.md`
- **Stress & Load Testing Benchmarks:** `docs/benchmarks/HIGH-THROUGHPUT-PERFORMANCE-BENCHMARK.md`
