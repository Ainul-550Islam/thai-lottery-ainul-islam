# File 245 — যা এখনো বাকি: remediation register after Part 50

**Overall status: PARTIAL / RELEASE NO-GO.** Part numbers are delivery batches, not completed checklist sections. Green targeted tests are not whole-application certification or a completion percentage.

এই register আগের delivery evidence এবং বর্তমান targeted source inspection মিলিয়ে লেখা। এটি নতুন করে পুরো repository-এর প্রতিটি line audit করার দাবি নয়। কোনো section-এর কিছু কাজ DONE মানে সেই পুরো section production-ready নয়। Source-এ কোনো class থাকা আর তার সব business flow-তে নিরাপদে ব্যবহার হওয়া আলাদা বিষয়।

## 1. এখন পর্যন্ত বাস্তবে যা হয়েছে

- Bot scoring/signals/fingerprint, CAPTCHA bindings, honeypot/auth observation ও IP-management foundations আছে; default-log/default-off controls বজায় আছে।
- TOTP enrollment/verification/replay budget/recovery/action-proof lifecycle তৈরি ও tested; **login MFA enforcement চালু নয়**। Legacy MFA quarantine আছে।
- Member password change/reset এবং admin password edit shared revocation ব্যবহার করে; persisted session/token/remember credential/proof ও বর্তমান default-broker reset links বাতিল হয়। Supported backend restrictions প্রযোজ্য।
- API login issuance এখন owner lock, fresh credential/status check ও owned SQL commit ব্যবহার করে। Part 47-এর inspected API token race-এর regression Part 49-এ প্রত্যাখ্যান নিশ্চিত করে।
- Part 50 explicit authenticated web routes-এ framework `auth.session` check যুক্ত করেছে। বর্তমান Laravel নিজেই login-এর সময় password binding রাখে; redundant listener/service যোগ করা হয়নি।
- Admin panel-এর নিজস্ব AuthenticateSession middleware আগে থেকেই ছিল। Routes/controllers/schema বা services না থাকার blanket claim করা হচ্ছে না।

## 2. সবচেয়ে গুরুত্বপূর্ণ বর্তমান blocker

### P0-A — Browser automatic-rehash race: REPRODUCED, NOT FIXED

`LoginService::authenticateOver()` uses `Auth::attempt`. Installed Laravel v12.69.3 SessionGuard validates credentials, may automatically rehash, then logs in. Its provider can save a new hash of the originally submitted password using the earlier User object.

A separate disposable diagnostic changes the stored password through the real PasswordChangeService at the Validated-event scheduling point while a hashing-cost upgrade is required. The framework's subsequent rehash writes the old submitted password back. The diagnostic confirms the old password works and the newly changed password no longer does.

**1 diagnostic test / 6 assertions reproduces the OPEN issue.** This is a deterministic local interleaving, not a parallel-worker production exploit demonstration. The unsafe assertion is sidecar evidence, not a desired permanent regression. The middleware change cannot detect a password change that was itself overwritten. Hash-cost behavior was changed only inside the diagnostic; production hashing config was not changed.

Next implementation should serialize browser credential validation/rehash with password-change/reset/admin-change and reject stale state without undoing legitimate lifecycle work. Review both member and Filament login paths. Do not simply disable hash upgrades without an explicit migration/security plan, and do not weaken existing current-password checks.

### Other release-critical boundaries still open

- Real MySQL/PostgreSQL multi-process ordering, isolation, deadlock and commit/response behavior: not certified by SQLite tests.
- Legacy sessions without a password binding: stock AuthenticateSession adopts them on first use rather than refusing them. Inventory/retire incompatible old sessions and restart old workers during rollout; no production cleanup was executed.
- Public personalized pages, broadcasting authorization and other routes that consult a guard without explicit `auth`/`admin.auth` middleware need their own inventory. Part 50's route-coverage test intentionally covers explicit web-auth routes, not every implicit use of an identity.
- A current password hash comparison is not a monotonic account/session epoch. Raw/noncooperating writers, backup restoration, status/permission races and arbitrary in-flight requests remain distinct problems.
- Result-source authority, payout/financial invariants, self-exclusion coverage and truthful provider readiness remain parallel release blockers. Completing an auth fix cannot waive these.

## 3. Twelve-section remaining-work register

### 1 — Bot / CAPTCHA / browser signals: PARTIAL

**Done, scoped:** BotDetectionService, BotScoreCalculator, DeviceFingerprintService and signal classes exist; authentication observation/adapters and provider verification bindings were delivered/tested. Config defaults remain log-oriented; HTTP observation has its own control.

**Still open:** complete browser/CSP integration, calibrated enforcement rollout, real distributed telemetry/storage validation, every required form/API adapter, operational false-positive/abuse handling. No live CAPTCHA provider certification is implied by adapter tests.

**Evidence:** `app/Services/Bot/`, `config/bot.php`, `app/Http/Middleware/BotDetectionMiddleware.php`, prior CAPTCHA delivery evidence. `SecurityHeaders.php` currently emits several security headers but does not itself implement CSP.

**Close when:** provider-backed browser tests, verified hostname/action/sitekey behavior, reviewed CSP and explicit log-to-enforce rollout with genuine telemetry pass.

### 2 — Shared honeypot: PARTIAL

**Done, scoped:** dynamic field/token helper and shared middleware with auth/contact wiring; bounded/session-bound form records and stable reasons.

**Still open:** remaining form coverage and concurrent single-use consumption. Current inspect reads session entries, unsets a token, then writes the array back; this is not distributed atomic consumption. A standalone validation Rule, if required by an actual caller, must reuse the helper rather than duplicate logic.

**Evidence:** `app/Services/Security/HoneypotService.php`, `app/Http/Middleware/HoneypotMiddleware.php`, `config/honeypot.php` defaults to log.

**Close when:** complete form inventory and parallel replay tests demonstrate the selected consumption policy without breaking legitimate multi-tab use.

### 3 — Fraud / cases / restrictions / alerts: PARTIAL, integration unverified

**Existing source:** authentication risk/gate services and ComplianceCaseService/ComplianceActionService are present. Do not label all case-management code missing.

**Still open:** a verified end-to-end fraud producer → evidence/case → reviewed restriction → real notification path across registration, deposits, bets and withdrawals; deduplication, reversible restrictions and rollout modes. Dedicated complete fraud detection coverage was not established by the targeted scan.

**Close when:** a flow-by-flow matrix and executable tests prove actual hooks and outcomes, initially in log mode where appropriate. Service names alone do not establish enforcement.

### 4 — AML / structuring / watchlists / SAR-STR / KYC: PARTIAL

**Existing source:** `AmlRiskService`, `AmlRiskAssessmentService`, KYC and compliance services exist. The inspected assessment computes versioned evidence including 90-day transaction facts and scores.

**Still open:** structuring patterns, approved watchlist data/update handling, SAR/STR review/export workflow, jurisdiction-approved thresholds, comprehensive money-flow hooks and operational KYC/provider evidence. The selected HTTP/finance/payment/betting scan did not establish complete use of the AML orchestrator; this is not proof that every possible caller is absent.

**Close when:** real review/approval and financial-path tests, privacy/retention controls and provider/operator readiness evidence exist. Do not invent regulatory thresholds or claim legal compliance from a score.

### 5 — Trusted proxies / IP blocklist management: PARTIAL

**Done, scoped:** explicit proxy trust, configured/database rule readers, audited permissioned management, inventory/capacity checks, HTTP/UI management and retry-safe creation foundations.

**Still open:** management MFA/approval activation, permission-grant rollout, repair/retention operations, GeoIP/autoblock policy and complete enforcement coverage. `config/ip_access.php` retains log mode and separately disabled management/database features by default.

**Close when:** proxy deployment settings, admin authorization/approval, all protected entry points and failure behavior are verified in the intended environment before enabling enforcement.

### 6 — Registration velocity / anomaly / betting hooks: PARTIAL

**Done, scoped:** registration throttling and shared VelocityWindow foundation.

**Still open:** integrated anomaly/multi-account and betting signals, distributed boundary tests, privacy-reviewed identifiers/retention and operator handling of false positives.

**Close when:** actual producers/callers and replay/concurrency tests demonstrate consistent limits across workers. A local cache test is not a distributed guarantee.

### 7 — Result integrity / P0 correctness / four-eyes / self-exclusion: PARTIAL / release-critical

**Existing source:** result import/publication/confirmation services, WeeklyResultIntegrityService and self-exclusion services exist. Weekly integrity distinguishes hash-only, signature-verified and verifier-unavailable states; hashing is not source authority.

**Still open:** prove authoritative sources/signatures and UNKNOWN/failure handling for every supported game; ensure no unsafe result reaches settlement; verify independent maker/checker behavior on every publication path; cover self-exclusion on every applicable betting route and alias.

**Close when:** end-to-end source→confirmation→publish→settle tests and operational evidence pass. Do not call an available importer a verified official feed or a fixture a production result.

### 8 — Producers / importers / schedules: PARTIAL

**Existing source:** import commands, lottery tick, capacity checks and retention scheduling exist. `bootstrap/app.php` explicitly gates scheduled lottery automation; not all desired producers are scheduled there.

**Still open:** fraud/AML producers, approved official import scheduling, idempotent retries/backoff, monitoring, dead-letter handling, retention and real scheduler/worker operation. Commands existing in source are not evidence they run in production.

**Close when:** configuration, executed schedules, monitored failures and repeat-safe effects are demonstrated against isolated production-like infrastructure.

### 9 — Security / MFA / UI / privacy / providers / operations: PARTIAL

**Done, scoped:** TOTP/recovery/proof backend lifecycle and quotas; member/admin password revocation; reset-link invalidation/cooperating issuance; locked API token issuance; Part 50 bound-session checks on explicit web-auth routes. See Parts 25–50 for exact limits.

**Still open:** P0 rehash race above; broader browser/implicit-guard coverage and real-engine concurrency; MFA login/step-up activation and recovery UX; verified CSP, SMS/push/KYC/payment delivery; privacy/data retention/key rotation/backup procedures; operational Horizon/Reverb/support/live-stream integrations where required. No provider is made READY by a mocked success.

**Close when:** authorized user-facing flows, stable backend restrictions, live-provider/operator verification and deployment/runbook evidence support each activation. Keep legacy MFA quarantine until its replacement is actually wired and verified.

### 10 — Genuine release/admin counters: PARTIAL

**Done, scoped:** ReleaseOperationsController exposes real-source state and retains NOT_CONFIGURED/NO_DATA states rather than substituting invented competitor figures.

**Still open:** actual environment/provider data sources, freshness, monitoring and complete operations evidence. Source-level availability does not prove a live worker/provider is healthy.

**Close when:** every counter's source/freshness is traceable and missing inputs stay visibly unknown/not configured. Do not fabricate business or competitor counters.

### 11 — Consolidation / DRY / removals: OPEN, approval-controlled

**Done, scoped:** new password flows reuse shared revocation; Part 50 reuses existing framework binding instead of inventing a duplicate listener.

**Still open:** deliberate consolidation of overlapping services/controllers/aliases after dependency and behavior review. No file removal or policy merging without explicit sign-off. Similar class names in KYC/compliance do not prove identical responsibilities.

**Close when:** approved change map, migration/compatibility plan and regression evidence exist. This register authorizes no deletion.

### 12 — Tests / browser / CI / release verification: PARTIAL

**Done, scoped:** cumulative local targeted PHP checks after Part 50: **1064 tests / 5505 assertions PASS**; new session suite: **11 / 238**. Existing member auth/admin/identity/API suites were separately rerun. CI workflow files exist.

**Still open:** whole-app verification/coverage measurement, actual CI execution, current frontend build verification, fresh browser automation, live-provider integration and production-engine concurrency/load/recovery tests. Earlier mocked/live HTTP browser evidence is historical, not a Part 50 browser run. The historical frontend build blocker requires retesting, not an assumption it is fixed.

**Close when:** reproducible CI/browser/build and production-like evidence meet an explicit release checklist. No honest overall completion percentage can be derived from targeted assertion counts.

## 4. Part 50 implementation boundary

Changed repository source: this register, `routes/web.php`, and `WebPasswordSessionBindingTest.php` only. Explicit `auth` groups/aliases, metrics authorization and legacy `admin.auth` group now include `auth.session`. The existing Filament panel middleware was not modified.

Installed lockfile versions inspected: Laravel v12.69.3, Sanctum v4.3.3, Filament v3.3.55. SessionGuard::login already stores `password_hash_web` using its cookie-hash binding. AuthenticateSession compares that against the current user password hash. No new listener, service, schema or configuration was necessary.

Tests verify current-session access, late-restored old rows, late login from a stale User, the non-rehash Validated-event interleave, blocked profile mutation, JSON refusal, fresh post-change access, explicit route coverage, existing Filament protection, guest redirects and legacy missing-binding adoption. Missing-binding adoption is a documented compatibility limit, not fail-closed coverage.

## 5. Ordered next work

1. **P0: serialize browser validation/automatic rehash and password lifecycle**, with a regression reversing the open sidecar diagnostic. Include member and admin guard paths; no automatic production activation.
2. Verify real-engine multi-process behavior, rollout/legacy-session handling and implicit identity consumers. Decide a complete session-generation policy before claiming universal revocation.
3. Move into the incomplete fraud/AML money-flow integration matrix and result/self-exclusion release gates. Implement actual hooks with tests, not only more isolated foundations.
4. Complete provider/UI/operations readiness and fresh browser/build/CI evidence; keep accurate NOT_CONFIGURED states.
5. Only then seek release sign-off; consolidation/deletion remains separately approved.

**No push/deploy. No new global enforcement/MFA activation. Release stays NO-GO.** Next numbered file after this batch: **248**.
