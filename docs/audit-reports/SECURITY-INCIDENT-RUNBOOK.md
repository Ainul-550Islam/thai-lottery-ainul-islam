# SECURITY INCIDENT RUNBOOK — Thai Lottery (security-next.md scope)

> **Audience:** On-call engineer + security reviewer. **Never paste live secrets, OTPs, session IDs, webhook signatures, or PII into tickets, logs, or this runbook.**

---

## 0. General Principles

1. **Preserve evidence before you fix.** Snapshot logs, `audit_logs`, `fraud_alerts`, `webhook_replay_guards`, and object-storage access logs before deleting or rotating anything.
2. **Redact by default.** All incident comms use `[REDACTED]` for secrets; file path + secret type is enough (`config/payment.php — Stripe webhook secret`).
3. **Fail closed.** When in doubt, disable the affected lane (return 503/401) rather than allow through.
4. **Do not force-push, rewrite history, or `terraform apply` without review.**
5. **Maker-checker for recovery.** The person who caused the incident does not approve the recovery; a second reviewer signs the `fraud_alerts` → `fraud_cases` resolution.

---

## 1. Credential / Secret Exposure (app key, webhook secret, DB password, S3 credential)

**Detect:** Gitleaks CI gate failure (`gitleaks detect --redact` exit 1), `grep -rn "BEGIN RSA PRIVATE KEY"` alert, or manual report.

**Immediate (first 15 minutes):**
1. Do **not** echo the secret in Slack, Jira, or email. File a ticket with `file path + secret type` only.
2. Revoke/rotate the credential at the source — not in git:
   - `APP_KEY` (or `FIELD_ENCRYPTION_KEY`): generate new `base64:` key via `php artisan key:generate --show`; store in secret manager (Vault / AWS Secrets Manager / Doppler), never in `.env.example`.
   - Stripe `STRIPE_WEBHOOK_SECRET`: rotate in Stripe Dashboard → Webhooks → select endpoint → `Roll secret`; accept both old+new HMAC for 24h (`PaymentWebhookService` supports dual-secret window).
   - bKash/Nagad/Crypto API secrets: rotate in provider console; update `config/payment.php` env refs.
   - DB / Redis / S3: rotate in Terraform/RDS console; update `docker-compose.production.yml` and `terraform/variables.tf` via PR.
3. Invalidate existing sessions if session encryption key changed: `php artisan session:table` + `redis-cli FLUSHDB` for session store, or bump `SESSION_DRIVER` cookie name.
4. Purge secret from git history **only via authorized process** (BFG `bfg --replace-text passwords.txt` + force-push to a new commit, never rewrite `main` without approval). Locally delete the offending file and `git rm --cached` it.
5. Verify Gitleaks now passes: `gitleaks detect --source . --redact --config .gitleaks.toml`.

**Recovery:** Deploy new secret via secret manager; verify `FIELD_ENCRYPTION_KEY` rotation uses dual-write re-encryption job (read old key, write new key, verify `FieldEncryptionService` round-trip).

**Owner:** Security lead approves rotation PR; SRE executes.

---

## 2. Suspected Account Takeover (ATO) — brute-force, credential stuffing, session hijack

**Detect:** `audit_logs` spike on `auth.failed`, `auth.locked`, `security.audit.persistence_failed`; `authentication_attempts` rate limiter (`login` 5/15m) 429 surge; `SecurityEvent` flood.

**Immediate:**
1. Block IP at edge (Cloudflare WAF rule or `BlockedIpController` → `throttle:admin-analytics` 30/min) — never trust `X-Forwarded-For` unless `TrustProxies` CIDR matches.
2. Force password reset for affected `user_id`: `php artisan auth:reset-password {id}`; revoke sessions via `RevokeExpiredSessionsJob` + `php artisan session:flush {id}`.
3. Enable MFA step-up: set `fraud.mode=enforce` (already default) and confirm `FraudRiskGateMiddleware` STEP_UP → 403 `TRANSACTION_STEP_UP_REQUIRED` until `step_up_verified` TTL (30 min) proves possession.
4. Check `audit_logs` for `mfa.replay` or `totp.reused` → quarantine device fingerprint via `DeviceFingerprintService`.
5. If admin account affected: rotate `ADMIN_IP_ALLOWLIST`, audit `filament/admin/pages/*` policy checks.

**Evidence:** `audit_logs` (`user_id`, `action`, `risk_level`, `ip_xff`, `device_fingerprint`), but **never** include `password`, `totp_secret`, `recovery_code`, `session_id` (all redacted by `AuditLogService`).

---

## 3. Payment Webhook Compromise — forged signature, replay, amount tampering

**Detect:** `webhook_replay_guards` UNIQUE violation log, `VerifyWebhookSignature` 401 spike, `PaymentWebhookService` `amount/currency mismatch` exception, or provider alert.

**Immediate:**
1. Verify provider status page (Stripe/bKash/Nagad) — is it provider outage or active abuse? Do not change code during provider incident; set `PAYMENT_DEFAULT_GATEWAY=manual` to pause auto-capture (fail closed).
2. Confirm `payment_webhooks` replay guard is active: `UNIQUE (gateway, nonce)` + `expires_at` indexed range delete (`webhooks:purge-replay-guards` daily 03:20). A missing row means replay window is open — do not delete live `expires_at` rows.
3. Check `DepositIntegrityGuard`/`WithdrawalIntegrityGuard` → `manual:reference_number` fallback is not bypassed; amount/currency binding is server-authoritative (never trust payload amount without DB `payment_intents` match).
4. If signature bypass confirmed: rotate webhook secret immediately (dual-accept 24h), then block attacker IP at WAF; do not WAF-block legitimate provider callback IPs (verify against provider published IP ranges).
5. Reconcile `financial_transactions` ledger: `php artisan finance:reconcile --since=24h` (detective control, never auto-repairs).

**Recovery:** Redeploy with new secret; drain queue `payment:verify-pending-kyc-documents` to catch held deposits.

---

## 4. KYC / Identity Document Leak — private upload exposure

**Detect:** `storage/app/private` or `quarantine` object accessed via public URL (should 404), `KycDocument` download 403 bypass, or `VerifyPendingKycDocumentsJob` log with `file_mime_type=application/octet-stream`.

**Immediate:**
1. Confirm storage disks: `config/filesystems.php` `private` + `quarantine` are `local` with `visibility=private`; no `public` symlink exposes `storage/app/private`.
2. Audit `app/Http/Controllers/Api/V1/KycController.php` + `Verification/AccountVerificationController` → download authorizes `user_id === kyc_document.user_id` or `can('view', KycDocument)`; unrelated accounts get 403 (test `KycDocumentAccessTest`).
3. If public exposure confirmed: revoke pre-signed URLs, rotate S3 credentials, move affected `KycDocument` rows to new `private_storage_key`, mark old keys for deletion; notify DPO (GDPR/CCPA 72h if PII affected — not automated).
4. Scanner outage: `config/antimalware.php` `scanner_timeout` → quarantine lane returns 503 `SCANNER_UNAVAILABLE` rather than `CLEAN`; retry via `ScanQuarantinedUploadJob` with bounded retries (dead-letter after 5).

**Evidence:** `upload_scan_records` (`scan_status`, `file_sha256`, `private_storage_key`); never log `file` contents or PII fields (`national_id`, `document_number` redacted).

---

## 5. Fraud / Risk Rule Outage — engine down, stale AML assessment, false bans

**Detect:** `RiskAssessmentService` timeout, `aml.php` freshness alert (`AMLRiskAssessment stale > 24h`), `fraud_alerts` empty during high velocity, or support ticket “permanent ban after one weak signal”.

**Immediate:**
1. Check `fraud.mode`: `observe` (log only) vs `enforce` (block). During outage, switch to `observe` to avoid false allows: `php artisan config:cache --env=enforce` is wrong — set `FRAUD_MODE=observe` in env and deploy, so every `FraudRiskGateMiddleware` decision becomes telemetry + `Log::info('fraud.decision_observed')` rather than 403.
2. Never permanently ban on single untrusted signal — spec §3.5: risk rules must combine `device_reuse` + `request_rate` + `user_agent` + `header_consistency` + `form_timing` (weights 20/25/40/10/5 in `config/bot.php`). A weak signal alone may `STEP_UP`, not `BLOCK`.
3. Stale/missing `AmlRiskAssessment` → fail closed: `WithdrawalService` + `DepositService` check `aml.freshness_ttl` inside `withinTransaction` after `WalletLockService::lockMany()`; stale → `HOLD_FOR_REVIEW` not `ALLOW`.
4. Reviewer outage: `fraud_alerts` `OPEN` → assign via `FraudAlertController` → `ReviewFraudAlertRequest` (`can('review', FraudAlert)`) + maker-checker (`user_id !== reviewerId`) + terminal `RESOLVED_*` immutability; reason length 5-1000 logged via `AuditLogService` (redacted).

**Recovery:** Restore risk engine, set `FRAUD_MODE=enforce`, re-run `ReviewHighRiskSecurityEventsJob` for backlog.

---

## 6. Data Breach — DB exfiltration, unauthorized export, audit tampering

**Immediate:**
1. Isolate origin: enable Cloudflare “I’m Under Attack”, block direct origin IP (only Cloudflare CIDRs in `network.trusted_proxies` allowed; all other peers 503).
2. Freeze `audit_logs` table: `REVOKE INSERT ON audit_logs` for app user; switch to append-only `SecurityAuditLog` if primary is compromised; `Log::critical('security.audit.persistence_failed')` will fire if writes fail — monitor it.
3. Rotate DB credentials via Terraform; enable `DB_SSL_CA` TLS on MariaDB/MySQL; PostgreSQL `sslmode=require`.
4. Notify legal/DPO; do not delete evidence (`evidence/` directory is git-tracked for forensics).

---

## 7. Emergency Secret Rotation & Rollback

| Secret | Rotate Where | Downtime | Rollback |
|--------|--------------|----------|----------|
| `APP_KEY` | Secret manager → env → `php artisan config:cache` | Session invalidation (all users re-login) | Previous key in `APP_PREVIOUS_KEYS` for 48h (decrypt only) |
| `FIELD_ENCRYPTION_KEY` | Secret manager → `FIELD_ENCRYPTION_KEY` | None if dual-write job runs | Re-encrypt with previous key; keep `previous_keys` allowlist |
| Stripe webhook | Stripe Dashboard | 24h dual-accept | Re-add old secret to `payment.webhook_secrets.stripe` array |
| DB/Redis/S3 | Terraform/RDS console → `terraform apply` (reviewed) | 0 if replicas | Revert Terraform commit via `git revert -m 1` |

**Rollback of code:** `git revert -m 1 <merge-commit>` → `composer install --no-dev` → `php artisan migrate:rollback --step=1` only if migration safe → deploy previous image tag (immutable, non-root).

---

## 8. Contacts & Runbook Location

- **Runbook file:** `SECURITY-INCIDENT-RUNBOOK.md` (this file) — versioned in repo, never contains secrets.
- **Escalation:** Security lead (maker-checker reviewer) + SRE on-call + DPO.
- **Post-incident:** 48h blameless retro, update `SECURITY-NEXT-ACCEPTANCE-MATRIX.md` with actual `FAIL` → `PASS` transition and evidence, re-run `gitleaks detect --redact` + `composer audit` + `phpunit tests/Security/SecurityNextHardeningVerificationTest.php`.

---

*Every remediation here fails closed, never disables CSRF, MFA, webhook verification, rate limits, or audit logging to “get green”.*
