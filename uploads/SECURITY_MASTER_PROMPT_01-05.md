# MASTER CODING-AGENT PROMPT — PHASES 01–05 COMBINED

**Project:** `thai-lottery-ainul-islam`  
**Target:** Implement and test a production-oriented Laravel security baseline, IP/CIDR protection, bot detection, fraud detection, and malware/phishing/ransomware resilience.  
**Execution order:** Phase 0 audit and baseline → Phase 1 P0 hardening → Phase 2 IP blocklist → Phase 3 bot detection → Phase 4 fraud detection → Phase 5 malware/phishing/ransomware protections → complete API wiring → full verification.

> **Important truth rule:** no system can honestly promise “100% block” or “impossible to bypass.” Build measurable, layered controls and prove their behavior with tests. Do not claim tests pass unless you actually ran them on the final working tree and captured the exit code/output.

---

## 0. PRIMARY INSTRUCTIONS — DO NOT SKIP

You are the Principal Laravel Security Engineer, Application Security Engineer, Financial Integrity Engineer, and Test/Release Engineer for this repository. Work inside the existing repository. Do not produce only a plan: inspect the actual code, implement the required fixes, connect them to real routes and transaction flows, add migrations/config/admin/API integrations, and run the required tests.

### 0.1 Repository-aware rules

1. Inspect the latest working tree before editing. The repository already contains many security-related components, including existing authentication/security/risk services, `SecurityEvent`, `AuthenticationAttempt`, KYC services, payment/webhook services, wallet services, GLO L6 services, security middleware, rate limiters, Filament admin resources, a Rust weekly-result-integrity verifier, and numerous tests.
2. **Reuse and extend existing code.** Do not create a second implementation of a service that already performs the same job. If a proposed path already exists, inspect it, document its current behavior, and extend it instead of overwriting it blindly.
3. Preserve valid existing functionality and route names unless a security defect requires a controlled, tested change. Do not delete business logic or unrelated code.
4. Search for duplicated classes/paths and route aliases before adding files. Existing duplicate webhook job classes must not be casually consolidated.
5. Treat earlier audit notes as leads, not unquestionable truth. Re-verify every finding against the actual current checkout before declaring it missing or fixed.
6. Never print `.env`, API keys, private keys, payment secrets, production personal data, or KYC document contents into reports, terminal logs, tests, or generated files. Check whether `.env` is Git-tracked; if it is, stop and handle secret removal/rotation safely without deleting the operator's local environment file.
7. Never point real traffic at fake scanners, fake payment providers, mocked risk services, hardcoded “clean” responses, or permissive fallbacks. Fakes are allowed only in isolated automated tests.
8. Do not make live production changes, block real users, alter production secrets, or enable destructive enforcement without an explicit deployment configuration and controlled rollout.
9. Do not weaken, remove, skip, or mark tests as skipped merely to make CI green. Fix the cause. If an external dependency/tool is unavailable, report `NOT RUN` with evidence; do not claim `PASS`.
10. Every new or changed class must have a focused test. Every security feature must be wired to its real request/transaction path; class existence alone is not completion.
11. Do not claim “100% secure,” “unbypassable,” or absolute malware/ransomware prevention. Report a threat model, limitations, false-positive handling, operational requirements, and verified test evidence.
12. Keep public lottery results and accessible public pages available where safe; do not block search engines indiscriminately. Bot controls must distinguish abusive automation from legitimate public indexing and accessibility use.
13. Avoid collecting invasive device fingerprints. If a device signal is needed, use a purpose-limited, server-keyed HMAC, rotate the key per policy, minimize retention, document privacy impact, and never use fingerprint alone as the only proof of identity.
14. Keep the database authoritative for wallet balances, tickets, payments, draw results, and settlement. A risk engine must never directly mutate the ledger or determine a winning result.
15. Make all new security configuration default to a documented safe mode. New detectors should start in `log_only` or `challenge` where that avoids blocking legitimate users; critical financial invariants must be enforced unconditionally by the business layer.

### 0.2 Status vocabulary

- `PASS`: implementation is connected to the actual route or transaction path and the relevant automated test(s) passed.
- `PARTIAL`: some code exists, but at least one integration, operational, or test requirement is absent.
- `FAIL`: test or code evidence demonstrates a defect.
- `NOT RUN`: a check could not be executed; state why.
- `UNVERIFIED`: insufficient evidence.

Do not use `PASS` based on a filename, a static source scan, or a mocked test alone.

---

## 1. REQUIRED EXECUTION ORDER

### Phase 0 — Audit the real code and establish baseline

Before making changes:

- Record `git status --short`, current commit, PHP/Laravel versions, Composer scripts, Node/Vite scripts, available services, and whether a Cargo toolchain is installed. Do not expose secret values.
- Inventory `routes/web.php`, `routes/api.php`, `routes/console.php`, `bootstrap/app.php`, middleware, service providers, auth policies, database migrations, jobs/queues, scheduler, Docker/Nginx, CI workflows, Filament resources, and existing tests.
- Trace the full paths for login, registration, password reset, KYC submission/review, wallet deposit/withdrawal, webhook callbacks, normal ticket purchase, GLO L6 ticket purchase, prize settlement, and payout.
- Verify whether `TrustProxies` trusts every proxy, whether registration uses a limiter keyed to a nonexistent field, whether CAPTCHA is only a local arithmetic challenge, whether registration has a bot gate, whether security event types include bot/fraud events, whether IP/CIDR blocklisting exists, whether risk config keys are actually read, and whether AML/risk gates are called by deposit/withdrawal/payout/purchase services. These are **checks to prove**, not permission to assume.
- Record current test baseline and pre-existing failures before touching files.
- Inspect current Rust `security/weekly-result-integrity` separately. Do not claim it implements bot detection, IP blocking, malware scanning, fraud prevention, or ransomware protection: it is a result-integrity verifier, not a general security engine.

Required report: `docs/security/PHASE-0-BASELINE-AUDIT.md`.

### Phase 1 — P0 Laravel baseline hardening

- Fix trusted-proxy trust boundaries from deployment configuration; never blindly trust client-supplied `X-Forwarded-For`.
- Correct login, registration, password reset and sensitive-operation rate limiter identifiers; cover IP and authenticated principal dimensions where appropriate.
- Review Security Headers, CSP, HSTS, CSRF, CORS, session invalidation, error/log redaction, authorization policies, secret handling, and upload privacy.
- Confirm payment webhook signature, timestamp/replay window, provider identity, deduplication, and state-transition validation.
- Protect wallet/ticket writes: atomic database transactions, locks/constraints, idempotency keys, exact arithmetic, append-only ledger/reversal model, and no trust in client-side balances or prices.
- Verify GLO L6 purchase and settlement invariants without replacing the authoritative source of truth. Preserve leading zeroes and versioned price/prize rules.
- Retest the existing write-boundary/four-eyes result-publication protection; a single actor must not ingest and publish an official result if the policy requires independent approval.

### Phase 2 — IP/CIDR blocklist and network trust

- Implement IPv4/IPv6 single-IP and CIDR matching, input canonicalization, TTL/expiry, source/reason, actor/audit evidence, manual unblock, safe allowlisting, and cleanup scheduling.
- Ensure untrusted forwarded headers cannot evade the blocklist or poison audit source IP.
- Add protected admin management and versioned API endpoints; all writes require appropriate admin permission, CSRF/API auth as applicable, validation, audit and rate limiting.
- Prevent administrator lockout: configured management allowlist and emergency recovery path must be documented, not hidden in hardcoded IPs.

### Phase 3 — Bot and automation abuse detection

- Implement a composable signal interface and stable decision DTO: score 0–100, decision `allow|challenge|throttle|review|block`, reason codes, policy version and correlation ID.
- Use signals such as user-agent anomalies, header consistency, request velocity, session-bound form timing, repeated failed authentication, device-token reuse and route/path patterns.
- Do not block based only on a weak header/User-Agent signal. Account for NAT/shared IPs, VPN/mobile IP churn, privacy tools, legitimate crawlers and assistive browser automation.
- Integrate with login, registration, password reset, public lookup, KYC submit, deposit/withdrawal requests, ticket purchase and relevant APIs. Use step-up/challenge where practical; hard business limits remain independently enforced.
- Use one shared detector/gate; do not copy/paste separate heuristics into every controller.

### Phase 4 — Fraud and financial-risk detection

- Create canonical risk events from real deposit, withdrawal, transfer, ticket purchase, account/KYC change, prize claim and payout paths.
- Implement versioned configurable rules for velocity, repeated failures, unusual transaction amounts, linked-account/device signals, rapid deposit-to-withdrawal patterns, duplicate references, impossible state transitions and suspicious payout changes. Rules must be explainable by stable reason codes.
- Connect existing AML/risk services instead of making a parallel “fraud engine” that is never called. Add missing gate calls only after verifying existing service contracts and transaction boundaries.
- Decision outcomes may be `allow`, `step_up`, `hold_for_review`, or `deny` according to risk and configured policy. Do not auto-freeze every account based on one weak signal. All holds/reviews need authorized release, reason, audit trail, expiry/escalation and customer-safe error messaging.
- Maintain double-entry ledger invariants irrespective of detector availability, retries, race conditions or duplicate events.
- Protect admin actions with least privilege and maker-checker/dual control where financial exposure requires it.

### Phase 5 — Malware, malicious uploads, phishing and ransomware resilience

**Malware / uploads**

- Add an upload quarantine lifecycle: `pending_scan`, `clean`, `rejected`, `error`, `expired` (or extend the existing equivalent enum/state machine).
- Validate size, extension allowlist, actual file signature/MIME, safe filename handling, archive expansion limits and parser risks. Keep KYC/private files outside public webroot on a private disk; serve only via authorized controller/policy with safe content headers.
- Integrate a maintained malware scanner (e.g. ClamAV via an isolated internal service) through an interface/adapter. Do not write an antivirus engine from scratch. Scanner unavailable, timeout, unknown result, malformed response or unsupported file must never be treated as clean; retain in quarantine or reject according to policy.
- Scan asynchronously with bounded timeout/retries, idempotent jobs, state transitions, event/audit trail and admin review. Never let a user download an unscanned file.

**Phishing / URL safety**

- Protect account recovery and privileged users with MFA/step-up, session revocation, credential-change notifications and suspicious-login alerts.
- If URL/reputation checks are needed, run them in a dedicated isolated worker with strict egress allowlists and SSRF defenses. Do not make Laravel fetch arbitrary user URLs. Block loopback, private, link-local, reserved and metadata-service addresses; validate DNS resolutions and re-check redirects/rebinding.
- Treat external threat-intelligence as advisory data with timestamps/expiry and source trust, not as an authorization source. Do not upload private KYC or payment data to public scanners without explicit legal/privacy approval.

**Ransomware**

- Do not claim a PHP middleware or Rust web API can guarantee prevention of ransomware on hosts/endpoints. Document and configure host/endpoint protection (EDR/antimalware), least privilege, non-root containers, read-only filesystem where practical, dropped Linux capabilities, no Docker socket mounts, network segmentation, restricted outbound access, patching and immutable/offline encrypted backups.
- Create tested backup/restore and incident-response runbooks covering containment, credential revocation, evidence preservation, clean rebuild, restore validation and post-incident review.
- Add alerting for suspicious mass-file changes, unauthorized backup deletion, unusual outbound connections and privilege escalation only when the telemetry source actually exists; otherwise mark it as deployment work.

---

## 2. TARGET FILE TREE — EVERY ENTRY EXPLAINED

This tree defines the expected final structure. The coding agent must first inspect each path. **`[EXTEND]` means reuse an existing file if present; `[NEW IF NEEDED]` means create only if no equivalent implementation exists; `[DEPLOYMENT]` means this requires environment/host configuration, not PHP alone.** Do not create duplicate implementations. Use migrations with unique timestamps generated by Laravel.

```text
thai-lottery-ainul-islam/
├── docs/
│   └── security/
│       ├── PHASE-0-BASELINE-AUDIT.md                  # [NEW] Initial routes, controls, baseline failures, threat model and verified gaps.
│       ├── PHASE-1-P0-HARDENING-REPORT.md              # [NEW] Proxy/auth/rate-limit/ledger/GLO/webhook fixes with exact evidence.
│       ├── IP-BLOCKLIST-RUNBOOK.md                     # [NEW] Block/unblock, TTL, allowlist, admin lockout recovery and incident steps.
│       ├── BOT-ABUSE-RUNBOOK.md                        # [NEW] Detector modes, reason codes, false-positive triage and rollout.
│       ├── FRAUD-RESPONSE-RUNBOOK.md                   # [NEW] Risk holds, manual review, maker-checker and release procedures.
│       ├── MALWARE-UPLOAD-RUNBOOK.md                   # [NEW] Quarantine, scan lifecycle, scanner outages and safe download policy.
│       ├── INCIDENT-RESPONSE.md                       # [NEW] Containment, evidence, credential revocation, escalation and recovery.
│       └── BACKUP-RESTORE-DRILL.md                     # [NEW] Immutable backup, restoration verification and recovery evidence.
├── app/
│   ├── Contracts/
│   │   └── Security/
│   │       ├── MalwareScanner.php                     # [NEW IF NEEDED] Scanner contract returning typed clean/infected/error/unknown result.
│   │       ├── SecurityRiskEngine.php                 # [NEW IF NEEDED] Contract for canonical risk decision; no money/ticket mutations.
│   │       └── ThreatReputationProvider.php            # [NEW IF NEEDED] Safe abstraction for URL/IP reputation lookups.
│   ├── DTOs/
│   │   └── Security/
│   │       ├── BotDecisionData.php                    # [NEW IF NEEDED] Immutable bot score, decision, reasons, policy version and correlation ID.
│   │       ├── FraudDecisionData.php                  # [NEW IF NEEDED] Typed fraud decision with risk level, reason codes and review requirement.
│   │       └── MalwareScanResult.php                  # [NEW IF NEEDED] Typed scan state, signatures/version, safe metadata and timestamp.
│   ├── Enums/
│   │   ├── SecurityEventType.php                      # [EXTEND] Add bot/IP/fraud/upload events only if equivalent cases are absent.
│   │   ├── BotDecision.php                            # [NEW IF NEEDED] Allow/challenge/throttle/review/block enum.
│   │   ├── FraudDecision.php                          # [NEW IF NEEDED] Allow/step-up/hold/deny enum.
│   │   └── MalwareScanStatus.php                      # [NEW IF NEEDED] Pending/clean/rejected/error/expired scan lifecycle.
│   ├── Events/
│   │   ├── BotSuspected.php                           # [NEW IF NEEDED] Published when bot risk threshold is reached.
│   │   ├── BotBlocked.php                             # [NEW IF NEEDED] Published only for an enforced block decision.
│   │   ├── FraudSuspected.php                         # [NEW IF NEEDED] Published for a suspicious, explainable financial event.
│   │   ├── FraudReviewRequired.php                    # [NEW IF NEEDED] Published when a protected transaction requires review.
│   │   └── MalwareScanCompleted.php                   # [NEW IF NEEDED] Published after a terminal scan result is persisted.
│   ├── Http/
│   │   ├── Middleware/
│   │   │   ├── TrustProxies.php                       # [EXTEND] Trust only configured proxy CIDRs; prevent spoofed forwarded-IP headers.
│   │   │   ├── IpBlocklistMiddleware.php              # [NEW IF NEEDED] Enforce IP/CIDR deny rules using trusted client-IP resolution.
│   │   │   ├── BotDetectionMiddleware.php             # [NEW IF NEEDED] Apply shared risk decisions to scoped routes with mode-aware behavior.
│   │   │   ├── FraudRiskGateMiddleware.php            # [NEW IF NEEDED] Use only where transaction service cannot safely own the gate.
│   │   │   └── SecurityHeaders.php                    # [EXTEND] CSP rollout, HSTS and other correct production headers without breaking app assets.
│   │   ├── Requests/
│   │   │   └── Admin/Security/
│   │   │       ├── StoreBlockedIpRequest.php          # [NEW IF NEEDED] Validate IP/CIDR, reason, expiry, source and permissions.
│   │   │       ├── UpdateBlockedIpRequest.php         # [NEW IF NEEDED] Validate allowed update fields and optimistic-concurrency assumptions.
│   │   │       ├── StoreSecurityRuleRequest.php       # [NEW IF NEEDED] Validate rule thresholds/mode; prevent invalid or permissive policies.
│   │   │       └── ReviewFraudAlertRequest.php        # [NEW IF NEEDED] Validate review decision, reason and authorized reviewer.
│   │   ├── Resources/Api/V1/
│   │   │   ├── BlockedIpResource.php                 # [NEW IF NEEDED] Redacted admin API projection for block rule.
│   │   │   ├── SecurityEventResource.php              # [EXTEND/NEW] Safe, redacted security event response; never exposes secrets/PII unnecessarily.
│   │   │   └── FraudAlertResource.php                 # [NEW IF NEEDED] Redacted fraud case projection and state.
│   │   └── Controllers/Api/V1/Admin/Security/
│   │       ├── BlockedIpController.php                # [NEW IF NEEDED] Authenticated admin list/create/update/delete-or-unblock API.
│   │       ├── SecurityOverviewController.php         # [NEW IF NEEDED] Permission-protected counters/status; no secrets in response.
│   │       ├── SecurityRuleController.php             # [NEW IF NEEDED] Read/update approved detector configuration with audit.
│   │       ├── FraudAlertController.php                # [NEW IF NEEDED] List, inspect, assign and review fraud alerts via policies.
│   │       └── MalwareReviewController.php             # [NEW IF NEEDED] Review quarantined upload scan state, not public file access.
│   ├── Models/
│   │   ├── BlockedIp.php                              # [NEW IF NEEDED] Canonical IP/CIDR, reason, source, expiry, actor and active status.
│   │   ├── FraudAlert.php                              # [NEW IF NEEDED] Risk event, severity, stable reason codes, state and owner.
│   │   ├── FraudCase.php                               # [NEW IF NEEDED] Case history, review decisions and maker-checker evidence if required.
│   │   └── UploadScanRecord.php                        # [NEW IF NEEDED] Private file identifier, scan state, scanner version/result and timestamps.
│   ├── Policies/
│   │   ├── BlockedIpPolicy.php                         # [NEW IF NEEDED] Authorize view/create/change/unblock by admin role/permission.
│   │   ├── FraudAlertPolicy.php                        # [NEW IF NEEDED] Authorize case assignment and review/release actions.
│   │   └── UploadScanRecordPolicy.php                  # [NEW IF NEEDED] Restrict KYC/upload scan metadata and private file access.
│   ├── Services/
│   │   ├── Network/
│   │   │   ├── IpBlocklistService.php                  # [NEW IF NEEDED] Cache-aware isBlocked/add/remove/list/expire logic backed by DB truth.
│   │   │   ├── IpCidrMatcher.php                       # [NEW IF NEEDED] Correct IPv4/IPv6 exact address and CIDR match with edge-case tests.
│   │   │   ├── TrustedClientIpResolver.php             # [NEW IF NEEDED] Derive client IP only through verified trusted proxy configuration.
│   │   │   └── GeoIpService.php                        # [OPTIONAL] Country signal via licensed local/provider data; unknown must not equal safe.
│   │   ├── Bot/
│   │   │   ├── BotDetectionService.php                 # [NEW IF NEEDED] Evaluate signals and return one canonical decision object.
│   │   │   ├── BotScoreCalculator.php                  # [NEW IF NEEDED] Weighted score and bounds using validated config.
│   │   │   ├── DeviceFingerprintService.php            # [NEW IF NEEDED] Optional privacy-minimized keyed HMAC token; no raw fingerprint logging.
│   │   │   ├── Contracts/BotSignal.php                 # [NEW IF NEEDED] Shared interface for one request-risk signal.
│   │   │   └── Signals/
│   │   │       ├── UserAgentSignal.php                 # [NEW IF NEEDED] Weak UA indicator, not sole block reason.
│   │   │       ├── HeaderConsistencySignal.php          # [NEW IF NEEDED] Evaluate header context while allowing non-browser clients as policy permits.
│   │   │       ├── RequestVelocitySignal.php           # [NEW IF NEEDED] Shared IP/account/session/device velocity signal.
│   │   │       ├── FormTimingSignal.php                # [NEW IF NEEDED] Verify signed, session-bound form timing token where applicable.
│   │   │       ├── FailedAuthenticationSignal.php       # [NEW IF NEEDED] Use persisted auth attempts without exposing account existence.
│   │   │       └── DeviceReuseSignal.php                # [NEW IF NEEDED] Privacy-minimized account/device correlation signal.
│   │   ├── Fraud/
│   │   │   ├── FraudDetectionService.php               # [NEW IF NEEDED] Orchestrates rules, persists risk evidence and returns a decision.
│   │   │   ├── FraudRuleEngine.php                     # [NEW IF NEEDED] Versioned, ordered and explainable rules; no arbitrary code evaluation.
│   │   │   ├── FraudEventRecorder.php                   # [NEW IF NEEDED] Idempotently records canonical deposit/withdrawal/purchase/payout signals.
│   │   │   └── Rules/
│   │   │       ├── TransactionVelocityRule.php         # [NEW IF NEEDED] Count/amount windows for money and purchase operations.
│   │   │       ├── RapidDepositWithdrawalRule.php      # [NEW IF NEEDED] Identify suspicious rapid funding/cash-out sequences.
│   │   │       ├── DuplicateReferenceRule.php           # [NEW IF NEEDED] Detect repeated provider/reference or idempotency collisions.
│   │   │       ├── AccountLinkageRule.php              # [NEW IF NEEDED] Use approved account/device/payment links with privacy controls.
│   │   │       ├── KycMismatchRule.php                 # [NEW IF NEEDED] Flag relevant identity/payment inconsistencies for review.
│   │   │       └── SuspiciousPayoutChangeRule.php       # [NEW IF NEEDED] Require step-up/dual approval for payout destination changes.
│   │   ├── Uploads/
│   │   │   ├── UploadQuarantineService.php             # [NEW IF NEEDED] Store privately and prevent access before scan approval.
│   │   │   ├── UploadScanService.php                   # [NEW IF NEEDED] State machine/orchestrator for upload validation and scanning.
│   │   │   ├── MalwareScannerManager.php               # [NEW IF NEEDED] Config-driven selection of real scanner adapter.
│   │   │   └── Scanners/ClamAvScanner.php              # [NEW IF NEEDED] Isolated ClamAV adapter with strict timeout and error handling.
│   │   ├── ThreatIntel/
│   │   │   ├── UrlReputationService.php                # [NEW IF NEEDED] Normalizes safe URL metadata and delegates lookups only to provider.
│   │   │   └── SafeUrlValidationService.php            # [NEW IF NEEDED] Blocks private/reserved addresses, unsafe redirects and SSRF/DNS-rebinding paths.
│   │   └── Security/
│   │       ├── SecurityEventService.php                # [EXTEND] Shared append-only/redacted event recording with correlation IDs.
│   │       ├── SecurityRiskAssessmentService.php       # [EXTEND] Reuse existing risk assessment; prevent a parallel unused engine.
│   │       ├── AuthenticationSecurityService.php       # [EXTEND] Reuse existing auth controls; wire bot gates and reliable identity dimensions.
│   │       └── RustRiskEngineClient.php                # [OPTIONAL FUTURE] Typed, authenticated client adapter only if Rust API is deployed and benchmark justifies it.
│   ├── Jobs/
│   │   ├── ExpireBlockedIpRulesJob.php                 # [NEW IF NEEDED] Idempotently expire/remove stale active blocks and record audit.
│   │   ├── ReassessFraudAlertJob.php                   # [NEW IF NEEDED] Re-run a versioned risk evaluation idempotently.
│   │   ├── ScanQuarantinedUploadJob.php                # [NEW IF NEEDED] Run scanner outside HTTP request with bounded timeout/retry.
│   │   └── RefreshThreatReputationJob.php              # [OPTIONAL] Refresh allowlisted reputation feeds with TTL and provenance.
│   ├── Console/Commands/Security/
│   │   ├── PruneExpiredIpBlocksCommand.php             # [NEW IF NEEDED] Scheduled expired-block cleanup and metrics.
│   │   ├── RetryStaleUploadScansCommand.php            # [NEW IF NEEDED] Requeue retryable scans without treating errors as clean.
│   │   └── SecurityReadinessCommand.php                # [NEW IF NEEDED] Check config/DB/Redis/scanner/alert readiness without printing secrets.
│   └── Filament/Resources/
│       ├── BlockedIpResource.php                       # [NEW IF NEEDED] Protected admin table/form/actions for IP/CIDR rules.
│       ├── BlockedIpResource/Pages/ListBlockedIps.php  # [NEW IF NEEDED] Paginated, searchable block list.
│       ├── BlockedIpResource/Pages/CreateBlockedIp.php # [NEW IF NEEDED] Validated creation UI and reason capture.
│       ├── BlockedIpResource/Pages/EditBlockedIp.php  # [NEW IF NEEDED] Controlled update/unblock UI with audit.
│       ├── FraudAlertResource.php                      # [NEW IF NEEDED] Risk triage with filters and controlled decisions.
│       ├── FraudAlertResource/Pages/ListFraudAlerts.php # [NEW IF NEEDED] Queue of pending fraud alerts.
│       ├── FraudAlertResource/Pages/ViewFraudAlert.php # [NEW IF NEEDED] Evidence timeline and authorized review action.
│       └── UploadScanResource.php                     # [NEW IF NEEDED] Scan status/review controls; does not expose raw private files.
├── config/
│   ├── network.php                                    # [NEW IF NEEDED] Trusted proxy CIDRs, admin allowlist, denylist settings and TTL policy.
│   ├── bot.php                                        # [NEW IF NEEDED] Signal weights, thresholds, modes, route scopes and safe crawler policy.
│   ├── fraud.php                                      # [NEW IF NEEDED] Versioned thresholds, rule modes, review limits and hold expiry.
│   ├── antimalware.php                                # [NEW IF NEEDED] Scanner endpoint/socket, timeout, size/archive bounds and fail-safe states.
│   ├── threat_intel.php                               # [OPTIONAL] Provider, timeout, cache TTL and privacy-safe lookup settings.
│   └── risk_engine.php                                # [OPTIONAL FUTURE] Local/Rust adapter mode, timeout, service identity and explicit failure policy.
├── database/migrations/
│   ├── YYYY_MM_DD_HHMMSS_create_blocked_ips_table.php # [NEW IF NEEDED] Unique normalized address/CIDR, active/expiry, reason/source/actor fields and indexes.
│   ├── YYYY_MM_DD_HHMMSS_create_fraud_alerts_table.php # [NEW IF NEEDED] Idempotent event key, risk level, reason codes, state and assignment indexes.
│   ├── YYYY_MM_DD_HHMMSS_create_fraud_cases_table.php # [NEW IF NEEDED] Durable review actions, reviewer, decision, reason and timestamps.
│   └── YYYY_MM_DD_HHMMSS_create_upload_scan_records_table.php # [NEW IF NEEDED] Private file key, scanner state/version/result hash and retry fields; no file bytes in DB.
├── routes/
│   ├── api.php                                        # [EXTEND] Versioned authenticated admin/security endpoints and existing security-sensitive route middleware.
│   ├── web.php                                        # [EXTEND] Protected Filament/admin routes and web auth/form bot controls without breaking route names.
│   └── console.php                                    # [EXTEND] Schedule expiry cleanup, stale scan retry and safe threat-feed refresh.
├── bootstrap/app.php                                  # [EXTEND] Register middleware/aliases and exception handling without double execution.
├── .github/workflows/security.yml                     # [EXTEND] Add PHP dependency/security/static checks; avoid leaking secrets in logs.
├── docker/nginx/nginx.conf                            # [EXTEND/DEPLOYMENT] Correct trusted proxy ranges, body/time limits and edge rate limits; never invent production CIDRs.
├── docker/security/clamav/README.md                   # [DEPLOYMENT] Document pinned supported scanner image/service, isolated network, non-public port and update policy.
├── docker/security/host-hardening.md                  # [DEPLOYMENT] Non-root container, read-only FS, capabilities, segmentation, EDR and backup expectations.
└── tests/
    ├── Unit/Security/Network/IpCidrMatcherTest.php     # [NEW IF NEEDED] IPv4/IPv6, /0, /32, /128, malformed CIDR and boundary matching.
    ├── Unit/Security/Bot/BotScoreCalculatorTest.php    # [NEW IF NEEDED] Weight bounds, threshold edges, unknown-signal behavior.
    ├── Unit/Security/Bot/Signals/RequestVelocitySignalTest.php # [NEW IF NEEDED] Window boundaries, key isolation and counter expiry.
    ├── Unit/Security/Fraud/FraudRuleEngineTest.php     # [NEW IF NEEDED] Rule ordering, reason codes, limits and idempotency.
    ├── Unit/Security/Uploads/UploadValidationTest.php  # [NEW IF NEEDED] Double-extension, MIME/signature mismatch, oversized/archive bomb files.
    ├── Unit/Security/ThreatIntel/SafeUrlValidationServiceTest.php # [NEW IF NEEDED] Private IPv4/IPv6, redirects, DNS rebinding and reserved address rejection.
    ├── Feature/Security/TrustedProxySpoofingTest.php   # [NEW IF NEEDED] Untrusted X-Forwarded-For cannot change client IP or evade a block.
    ├── Feature/Security/IpBlocklistApiTest.php         # [NEW IF NEEDED] Admin auth, policy, create/list/expire/unblock, validation and audit.
    ├── Feature/Security/BotDetectionMiddlewareTest.php # [NEW IF NEEDED] Real route wiring for allow/challenge/throttle/block modes.
    ├── Feature/Security/BotRouteCoverageTest.php       # [NEW IF NEEDED] Critical auth/account/purchase routes are gated exactly once.
    ├── Feature/Security/FraudRiskGateTest.php          # [NEW IF NEEDED] Deposit/withdrawal/purchase/payout integration and review lifecycle.
    ├── Feature/Security/ConcurrentFinancialSafetyTest.php # [NEW IF NEEDED] Duplicate/concurrent operations cannot double-spend or double-settle.
    ├── Feature/Security/UploadQuarantineAndScanTest.php # [NEW IF NEEDED] Private storage, scanner clean/infected/timeout/error, no download before clean.
    ├── Feature/Security/SafeUrlAndThreatFeedTest.php   # [NEW IF NEEDED] SSRF safety, stale feed and provider outage behavior.
    ├── Feature/Security/AdminSecurityAuthorizationTest.php # [NEW IF NEEDED] Non-admin cannot manage blocks, release cases or access private scan records.
    └── Feature/Api/V1/SecurityApiContractTest.php      # [NEW IF NEEDED] API auth, response schemas, rate limits, redaction and stable error codes.
```

### Tree rules

- For each `NEW IF NEEDED` path, search the complete project first; create it only if no equivalent is already present.
- Where an existing class serves the same purpose, update the tree/report to show the real existing path and explain why it is reused.
- Generate real, unique migration timestamps through the framework; do not create literal `YYYY` filenames.
- Do not create UI for internal-only service credentials, and do not expose private service endpoints publicly.
- If a required operational control cannot be implemented by application code alone (WAF, EDR, immutable backups, production trusted-proxy CIDRs), create the deployment runbook and mark it `DEPLOYMENT REQUIRED`, not `PASS`.

---

## 3. API CONTRACT AND REAL INTEGRATION REQUIREMENTS

All paths below are target semantics. Inspect `routes/api.php` and existing conventions first; preserve route conventions and avoid duplicate endpoints. Use existing authentication and authorization architecture (Sanctum/session/Filament as applicable) after verifying the repository configuration.

### Admin security API

- `GET /api/v1/admin/security/overview` — summary counters and readiness state; requires explicit security-admin permission.
- `GET /api/v1/admin/security/ip-blocks` — paginated active/inactive IP and CIDR rules; never expose internal secrets.
- `POST /api/v1/admin/security/ip-blocks` — add a validated block rule with reason, source, expiry and audit actor.
- `PATCH /api/v1/admin/security/ip-blocks/{blockedIp}` — update permitted fields only; audit before/after metadata.
- `POST /api/v1/admin/security/ip-blocks/{blockedIp}/unblock` — explicit, authorized unblock action with reason; do not silently delete history.
- `GET /api/v1/admin/security/events` — filtered, paginated, redacted events with time range, type, severity and correlation ID.
- `GET /api/v1/admin/security/fraud-alerts` — list/triage authorized fraud cases.
- `POST /api/v1/admin/security/fraud-alerts/{fraudAlert}/review` — assign/review/hold/release with permission, reason and immutable audit event.
- `GET /api/v1/admin/security/upload-scans` — list scan statuses and metadata; never public-download the quarantined file.
- `POST /api/v1/admin/security/upload-scans/{scan}/review` — authorized hold/reject/release action, subject to validated scan policy and audit.

### API behavior rules

- All admin endpoints require authentication, explicit policy/permission checks, validation, rate limits, audit logging and stable JSON error responses.
- Use API Resources/DTOs rather than exposing Eloquent models directly. Paginate lists. Redact IP/account/KYC details for users who do not need them.
- Add request IDs/correlation IDs and structured logs. Never log password, session/token, authorization header, payment secret, raw document, or full sensitive fingerprint.
- Validate all writes server-side; reject unknown mutable fields and malformed enums/thresholds/CIDRs. Avoid mass assignment.
- Use idempotency where clients may retry create/review operations. Apply optimistic locking or equivalent safeguards to avoid two reviewers making conflicting decisions.
- Return `401` for unauthenticated, `403` for unauthorized, `422` for invalid input, `409` for conflicting state, and safe `5xx` responses without internals.
- Ensure route names do not collide and middleware is not accidentally applied twice.
- Wire admin controls to real backend services, database persistence, audit events and tests. A frontend that only changes local component state is not API-integrated.

### Real application-path integration checklist

- **IP gate:** runs after trusted-client-IP resolution and before protected route work; uses DB-backed rules with a deliberate cache invalidation strategy.
- **Bot gate:** runs on login/register/password reset and sensitive route groups; decision reasons are persisted; decision mode is configurable per environment and route group.
- **Fraud gate:** is invoked inside the existing deposit/withdrawal/purchase/payout application service at the correct state/transaction boundary. It cannot be bypassed by calling an alternate controller/API.
- **Uploads:** KYC upload is staged in private quarantine; only a scanner-approved state can transition into the approved/usable state; the actual document remains private.
- **Webhook:** existing signature/replay/idempotency controls remain intact; bot detector or fraud detector cannot interfere with provider authentication or cause repeated ledger postings.
- **Results:** public result endpoints remain governed by the existing result-certification flow; security changes cannot auto-publish results.
- **Rust readiness:** implement a typed interface only if useful, but keep the local Laravel implementation authoritative and working. A future Rust adapter must authenticate service-to-service, version payloads, have strict connect/read timeouts, correlate requests, validate response schema, and follow an explicit fail-closed policy for critical transactions. No silent “allow” fallback when Rust times out. Do not add Rust network dependency to critical money paths until latency, availability and rollback have been tested.

---

## 4. CONFIGURATION AND OPERATING MODES

Provide documented environment-backed settings in `.env.example` only, never copy real `.env` secrets:

- `TRUSTED_PROXIES` / `TRUSTED_PROXY_CIDRS`: deployment-supplied trusted proxy list; empty/unset must not mean “trust every proxy.”
- `SECURITY_IP_BLOCKLIST_ENABLED`: explicit enablement; production readiness command warns/fails readiness if required values are absent.
- `SECURITY_IP_BLOCKLIST_CACHE_TTL`: bounded cache TTL with immediate invalidation on updates.
- `BOT_DETECTION_MODE=log_only|challenge|enforce`: default to safe staged rollout; production policy must be explicit.
- `BOT_SCORE_CHALLENGE_THRESHOLD` and `BOT_SCORE_BLOCK_THRESHOLD`: validated sensible bounds; block decision must consider strong composite signals.
- `BOT_FINGERPRINT_HMAC_KEY`: secret from deployment secret manager; never commit an actual key and never reuse APP_KEY without documented threat analysis.
- `FRAUD_DETECTION_MODE=log_only|review|enforce`: risky operations must still retain unconditional financial invariants.
- `FRAUD_REVIEW_THRESHOLD` and per-operation limits: validate all values and document owner/source.
- `ANTIMALWARE_DRIVER=clamav` (or an explicit supported value): production must not silently use a `fake` or “always clean” driver.
- `ANTIMALWARE_TIMEOUT_SECONDS`, `ANTIMALWARE_MAX_BYTES`, archive expansion/file-count limits and retry ceilings.
- `THREAT_REPUTATION_DRIVER`: default disabled unless a valid provider is configured; user-supplied URLs are not fetched by Laravel.
- `RISK_ENGINE_DRIVER=local|rust` only if a contract and deployable Rust API are truly implemented; use `local` until the Rust service has integration/contract tests and an operational readiness check.

Config validation must fail clearly for invalid settings. Do not print actual secret values in config errors, readiness output or exception traces.

---

## 5. DATA MODEL AND STATE TRANSITIONS

### IP/CIDR blocks

- Store normalized address/network, IP version, CIDR prefix, active state, reason code/free-text reason with bounds, source (`manual|bot|fraud|threat_feed`), start time, expiry, created/updated actor, optional reviewed actor, timestamps and version where needed.
- Add an appropriate unique/index strategy; expired entries remain auditable until retention policy removes them.
- Protect the admin allowlist from accidental lockout using deployment configuration and an audited break-glass procedure. Never hardcode one developer's IP as the only escape path.

### Fraud cases

- Persist idempotent event key, account/transaction references, risk score/level, rule IDs/version, reason codes, decision, state, assignment, hold expiry, reviewer, reviewer reason, and append-only decision history.
- Store only the minimum personal information needed; use references to existing records rather than duplicating identity/payment payloads.
- Use DB constraints to prevent duplicate alert creation and illegal review transitions.

### Upload scans

- Store private storage key, owning model/reference, original safe metadata only, computed checksum, detected MIME, scan status, scanner/version, result code, timestamps and retry count.
- Do not store file bytes in a database row or expose private storage keys through APIs.
- Only explicit `clean` state from a real configured scanner plus successful validation may release a file. `timeout`, `unknown`, `error`, unsupported format or unavailable scanner never mean clean.
- Re-scan policy for stale signatures must be explicit and must not make a previously rejected file public automatically.

---

## 6. MIDDLEWARE / TRANSACTION BOUNDARY REQUIREMENTS

- Register each middleware once in the correct Laravel 11/12 bootstrap configuration. Match existing project conventions rather than blindly following an older `Kernel.php` layout.
- Public informational/results pages may use low-friction abuse protection, not authentication requirements. Sensitive member and admin paths require their actual auth/KYC/role policies.
- Apply bot protection before expensive sensitive work where possible, but keep authoritative amount/ledger/draw checks inside the application service and database transaction.
- Do not rely on middleware as the sole fraud gate; an internal job, webhook, alternate API, or queued worker could bypass it. Put transaction-risk checks in shared domain/application services and test all entry points.
- Do not perform slow antivirus or threat-intelligence network work while holding wallet/ticket database locks.
- Use outbox/after-commit events for durable asynchronous risk/scan events where the database state and queue dispatch must remain consistent.
- All queue jobs need bounded retries/backoff, timeout, idempotency and explicit terminal states. Dead-letter/failure monitoring must be documented.
- Redis outage must not turn into unlimited unthrottled access to sensitive flows. Define route-specific safe fallback: hard financial controls remain in the database; security-sensitive operations may throttle/hold/reject when their required control state is unavailable.

---

## 7. REQUIRED TEST MATRIX — DO NOT SKIP

### A. Baseline/regression

- Run the same full test command(s) documented by the current README/CI; record original baseline before edits and final count afterward.
- Run affected feature test groups and full suite. Ensure pre-existing failures are separately identified; do not relabel them as passing.
- Test migrations both on a fresh test database and against migration ordering/schema constraints where supported.

### B. Trusted proxy and IP/CIDR

- IPv4 exact match and non-match; IPv4 `/0`, `/8`, `/24`, `/32`; IPv6 exact and CIDR `/32`, `/64`, `/128`; compressed/canonical IPv6; malformed address/prefix; mixed-case/whitespace normalization.
- Expiry boundary, already expired rule, update invalidation, manual unblock while preserving audit, duplicate CIDR and concurrent create/update.
- Spoofed forwarded IP from untrusted direct client is ignored. Forwarded chain from explicitly trusted proxy resolves correctly. Unknown proxy configuration never trusts all sources.
- Non-admin cannot create or remove blocks. Admin operation is audited. Break-glass allowlist works only when configured.

### C. Bot detection

- Every signal has unit tests and documented weights/reasons. Scores cannot fall outside the allowed range.
- Missing headers/UA alone do not overblock all API clients. Legitimate public crawlers and accessibility tools get explicit safe handling.
- `log_only` records a decision without denying; `challenge` challenges the correct paths; `enforce` blocks only policy-matching test cases.
- Verify login, registration, password reset, account lookup, KYC, deposits/withdrawals and ticket purchase are wired exactly once.
- Rate limits work with authenticated-user and IP dimensions; one dimension cannot be spoofed by changing forwarded headers.
- Fingerprint signal is privacy-minimized, keyed, redacted and not persisted longer than documented.

### D. Fraud and financial integrity

- Deposit, withdrawal, transfer, purchase, prize claim and payout produce idempotent canonical events.
- Duplicate webhook/event does not duplicate ledger postings, alert decisions or payout.
- High-risk transaction enters correct hold/review state; unauthorized actor cannot release it; authorized release records reason/actor/time.
- Test two-person approval if configured, expired holds, reviewer collision, retry, queue replay, timeout, Redis outage and database rollback.
- Race tests prove parallel purchases/withdrawals cannot overspend or issue duplicate tickets/payouts. Use a concurrency-capable test environment; if unavailable, mark the specific concurrent runtime test `NOT RUN` and add a deterministic database/locking test.
- Confirm existing GLO L6 rule, leading-zero behavior, cutoff, price/prize version, result certification and authoritative ledger assertions still pass.

### E. Malware / phishing / ransomware-resilience

- Upload clean file → scan approved; EICAR test file in a safe isolated integration environment → rejected/detected if scanner integration is available; mismatched extension/MIME/signature → rejected/quarantined; oversized and archive-expansion abuse → rejected.
- Scanner offline, timeout, unknown response, malformed response and retry exhaustion never become `clean`.
- Unauthenticated/unowned user cannot download a private KYC file; quarantined file is never served by a public disk/URL.
- Safe URL validator rejects loopback, RFC1918, IPv6 unique-local/link-local, metadata endpoints, reserved addresses, unsafe redirects and DNS changes to private IPs.
- Reputation service errors/stale feed do not silently authorize high-risk user-supplied URL access.
- Verify infrastructure runbook and backup restore drill instructions; mark actual EDR/WAF/immutable backup deployment `DEPLOYMENT REQUIRED` unless deployment evidence is provided.

### F. API/admin/UI integration

- API contract tests for each new/changed endpoint: auth, policy, validation, stable status codes, pagination, redaction, rate limit, idempotency and audit evidence.
- Filament forms/pages call real services and persist state; empty/loading/error/success states must be present where appropriate.
- Cross-account IDOR attempts, role escalation, mass-assignment, CSRF and replay must be tested.
- Assert no secret/token/full KYC payload appears in response or structured logs.

### G. Static analysis/security quality

Run all available project scripts, for example:

```bash
composer validate --no-check-publish
composer audit
php artisan route:list --except-vendor
php artisan migrate:status
php artisan test
npm ci
npm run build
```

Only run commands that are defined and safe for this repository, and record skipped/unavailable commands with why. Run existing configured PHPStan/Psalm/Pint/PHP-CS-Fixer/security workflow where available. If a Cargo workspace is actually involved in changed files and Cargo is installed, also run `cargo fmt --check`, `cargo test`, and `cargo clippy -- -D warnings` in the correct Rust directory. Do not claim the pre-existing Rust verifier passed merely because static tests can read its files.

---

## 8. IMPLEMENTATION DETAIL REQUIREMENTS

### Code quality

- Use dependency injection and contracts. Keep controllers thin and reuse existing services/models/enums/event patterns.
- Validate and normalize data before persistence. Use exact types and database constraints. Never use binary floating-point for monetary amounts.
- Make risk rules configuration-driven, bounded, explainable, versioned and unit-testable. Reject malformed thresholds at startup/readiness checks.
- Use constant-time comparison for secrets/signatures where relevant. Use `hash_equals`/standard cryptographic libraries instead of custom cryptography.
- Use structured logs with correlation IDs and redaction. Never use raw PII/token as the only cache key; use a keyed hash where identity material is required.
- Handle provider/scanner errors with typed results and safe error messages. Do not leak network topology or stack traces to public clients.
- Avoid regex-only malware/phishing claims; use maintained scanner/reputation services and clear operational constraints.
- Do not add unrelated new frontend styling or public pages in this task. Admin screens needed to operate the controls are in scope.

### Security policy principles

- A risk score is advisory input, not authentication. Authorization remains explicit.
- Financial invariants (no overspend, no duplicate debit, no duplicate payout, no purchase after cutoff, no double publication) must be enforced server-side even when all external scoring systems are offline.
- For noncritical detection, prefer staged rollout and false-positive review. For critical write integrity, reject/hold whenever mandatory verification cannot be established.
- Do not globally block a country, cloud provider, VPN, or browser based on a single signal without approved policy and regression coverage.
- Threat-feed data must have provenance, version, expiry, and a rollback strategy.

---

## 9. FINAL DELIVERABLES — MUST BE COMMITTED TO THE WORKTREE

Create/update these exact report files and fill them from actual evidence, not template-only claims:

1. `docs/security/PHASE-0-BASELINE-AUDIT.md` — repository evidence and before-test baseline.
2. `docs/security/PHASE-1-P0-HARDENING-REPORT.md` — proxy/auth/rate-limit/financial integrity changes.
3. `docs/security/IP-BLOCKLIST-RUNBOOK.md` — operator instructions and recovery.
4. `docs/security/BOT-ABUSE-RUNBOOK.md` — modes, route coverage and false-positive handling.
5. `docs/security/FRAUD-RESPONSE-RUNBOOK.md` — review/release controls and audit requirements.
6. `docs/security/MALWARE-UPLOAD-RUNBOOK.md` — scanner configuration, quarantine and failure behavior.
7. `docs/security/INCIDENT-RESPONSE.md` — incident triage/containment/recovery.
8. `docs/security/BACKUP-RESTORE-DRILL.md` — restoration procedure and proof capture.
9. `docs/security/PHASE-1-5-IMPLEMENTATION-REPORT.md` — completed files, routes/APIs, migrations, config, test commands, exact counts, failures/skips and deployment-only gaps.
10. `docs/security/REMAINING-GAPS.md` — all unimplemented/untested items with priority, reason, owner/action; no item silently omitted.

Also provide:

- A changed-file manifest with one-line role for every new/modified file.
- A route/API table with HTTP method, URI, auth middleware, permission/policy, controller/action, service called and test file.
- A security-event table with event type, origin path, persistence, severity and alert/review behavior.
- A feature-by-feature status table (`PASS|PARTIAL|FAIL|NOT RUN|UNVERIFIED`).
- Actual CI/test results for the **final** working tree and its commit/hash if available.

---

## 10. DEFINITION OF DONE — ALL GATES REQUIRED

The combined 01–05 task is done only when:

- Phase 0 audit has source/route/test evidence.
- P0 proxy/auth/rate-limit/security-header/webhook/ledger/ticket controls have been verified and relevant gaps fixed or explicitly blocked with evidence.
- IP/CIDR management is database-backed, authorized, audited, expiration-aware and wired into real traffic using a trusted client IP.
- Bot detection is wired to real sensitive request flows, has configurable safe rollout modes and signal/false-positive tests.
- Fraud detection is called inside the actual financial application services, persists idempotent cases and supports a controlled review lifecycle.
- KYC/upload content is private and quarantined until a real scanner approves it; scanner error never becomes “clean.”
- Phishing/SSRF controls and ransomware deployment/recovery requirements are documented and tested to the extent available in the environment.
- Admin APIs/UI perform real operations against the services/database with authorization, validation, audit and contract tests.
- Relevant tests pass on the final tree, and the full suite has been run or every unrun portion is transparently marked `NOT RUN` with cause.
- No secrets are leaked, no existing tests are removed/skipped to fake green, and no unrelated existing logic is silently deleted.
- External deployment-only controls (WAF, EDR, scanner service, immutable backups, trusted proxy CIDRs) are clearly marked `DEPLOYMENT REQUIRED` until actual environment evidence exists.
- All remaining gaps are in `docs/security/REMAINING-GAPS.md`.

**Final response format:** concise summary; `PASS/PARTIAL/FAIL/NOT RUN` table; exact test commands and observed counts; important changed-file list; API route table; deployment-only requirements; remaining P0/P1 gaps. Do not say “all done” while any required gate is unverified.

---

## 11. REFERENCE GUIDANCE

Use current official documentation where needed and confirm versions against this repository:

- Laravel security / middleware / rate limiting: https://laravel.com/docs
- OWASP API Security Top 10: https://owasp.org/www-project-api-security/
- OWASP File Upload Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html
- OWASP SSRF Prevention Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html
- CISA StopRansomware Guide: https://www.cisa.gov/stopransomware/ransomware-guide

**Begin with Phase 0 now. Continue through Phases 1–5 in order in this working session. Do not stop after generating another plan: implement the code, connect the routes/services/admin API, run tests, and report accurately. If time/environment limits prevent part of the implementation, complete the highest-risk safe subset, document exact unfinished files, and do not pretend skipped work was done.**
