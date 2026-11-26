# Payment Provider and Financial Infrastructure Audit

**Audit date:** 2026-11-17  
**Audited checkout:** `98ef46e` on local branch `main`  
**Requested branch:** `feat/batch2-lottery-games` (not present in the supplied remote checkout)  
**Scope:** payment providers, deposits, withdrawals, payouts, wallets, ledger, webhooks, reconciliation, KYC/AML/fraud, admin controls, APIs, database constraints, tests, and deployment readiness.

## Audit rules

Status meanings:

- **PASS** — implemented and supported by source plus an executed test or static verification.
- **PARTIAL** — useful implementation exists, but a material external contract, operational control, or test lane is absent.
- **MISSING** — required capability is absent.
- **BROKEN** — implementation or regression test demonstrates a defect.
- **UNVERIFIED** — implementation exists but could not be established against a live/sandbox provider or required infrastructure.

No provider is classified as live merely because a class or configuration flag exists. No production payment was attempted.

## Executive findings

The repository already contains a mature financial core: exact-decimal money objects, pessimistic wallet locking, reservations/holds, double-entry ledger posting, immutable/reversal-oriented accounting, webhook replay ownership, external-reference ownership, payout maker/checker boundaries, reconciliation evidence, KYC/AML/fraud gates, and broad regression coverage.

The principal readiness conclusion is nevertheless **NOT PRODUCTION READY FOR REAL MONEY in this checkout**:

1. `REAL_MONEY_ENABLED` is off.
2. Every executable provider is disabled and lacks deployment credentials or settlement configuration.
3. bKash and Nagad callback authentication contracts are explicitly unverified; their readiness status is partial and fail-closed.
4. Thai bank payout adapters require bank-approved contract paths, credentials, source accounts, webhook secrets, and live/sandbox certification.
5. Crypto is unsupported and remains unavailable.
6. A transient MariaDB/InnoDB lane executed 30 high-value server financial/concurrency classes. It exposed and verified the fix for an unstable ledger chain-tip lock; after explicitly enabling the guarded payout-contention profile, the final batch passed all 123 tests with 3,065 assertions and no skips. Deployment-target MySQL 8 and Redis certification remain required.
7. A legacy destination fixture expected bKash withdrawal success on a THB/Thailand site. It was corrected to prove that valid destination syntax does not override BDT/Bangladesh market and unverified-provider capability gates; the final Payment suite is green.

## Provider matrix

| Provider / rail | Registration | Deposit | Withdrawal | Authentication / verification | Market | Status | Required external dependency |
|---|---:|---:|---:|---|---|---|---|
| Stripe | Yes | Implemented | No | Raw-body `Stripe-Signature`, timestamp tolerance, webhook secret, replay controls | THB/USD; TH/US | **UNVERIFIED** | Stripe account, API keys, endpoint secret, licensed market approval, sandbox/live certification |
| bKash | Yes | Tokenized checkout/query prototype | Manual/pending only; no proved B2C execution | Merchant API credentials exist in config; provider callback authentication contract is explicitly unverified and ingress remains closed | BDT/BD only | **PARTIAL** | Merchant agreement, official current callback/IPN contract, sandbox credentials, production approval, B2C agreement if payouts are required |
| Nagad | Yes | Adapter exists | Manual/pending architecture only | RSA/merchant configuration exists; callback contract remains unverified and fail-closed | BDT/BD only | **PARTIAL** | Merchant onboarding, official current PGW/disbursement specification, IP whitelist, keys, sandbox/live approval |
| Bank transfer | Yes | Manual instructions + slip/review lanes | Manual evidence/reconciliation | No fabricated automation; requires configured receiving/settlement account | THB/TH | **PARTIAL / CONFIGURED-ONLY** | Real bank account, operating procedure, approval roles, reconciliation feed |
| PromptPay | Yes | QR/deposit lane | Not a client payout rail | Bank-specific SCB/KBANK notification verification; merchant recipient must be operator configured | THB/TH | **UNVERIFIED** | Merchant proxy/biller identity, bank contract, webhook secrets, sandbox certification |
| Thai bank (SCB/KBANK) | Internal routing rail | No | Adapter, polling, webhook and reconciliation architecture | OAuth/client credentials, contract-confirmation switch, source account, signed callback configuration | THB/TH | **PARTIAL** | Bank-approved transfer/status paths, signed callback specification, source account, credentials, sandbox/live certification |
| TrueMoney voucher | Separate opt-in Thai rail | Provider voucher redemption architecture | No | Host allowlist, exact provider evidence, risk gate, once-only voucher hash, canonical ledger completion | THB/TH | **PARTIAL / DISABLED** | Explicit acceptance of unofficial API risk, merchant mobile, legal/operational approval; should not be enabled without provider authorization |
| EasySlip / SlipOK | Separate verification providers | Slip verification only | No | Provider token/API key, receiving-account match, evidence checks | THB/TH | **UNVERIFIED** | Provider account, API credentials, receiving account list, contractual approval |
| Crypto | Driver identity exists | Refused | Refused | No accepted production provider/authentication contract | None offered | **MISSING / UNSUPPORTED** | A licensed provider, official API/signing/reconciliation contract, legal approval; intentionally unavailable |
| Skrill / Neteller / PayPal / Perfect Money | Public fee rows only | No | No | N/A | N/A | **PASS (unavailable as designed)** | Must not be advertised as executable without a real adapter and contract |

### Documentation cross-check

- Stripe official documentation confirms server-side idempotency and raw-body webhook signature verification: <https://docs.stripe.com/api/idempotent_requests>, <https://docs.stripe.com/webhooks>.
- bKash's official developer portal is merchant/onboarding controlled: <https://developer.bka.sh/docs>. The checkout code must remain non-live until the merchant's current callback/IPN specification is supplied and certified.
- SCB's public developer documentation confirms OAuth-authenticated APIs but does not prove the repository's configurable payout paths are authorized for this merchant: <https://developer.scb.co.th/assets/documents/documentation/direct-debit-payment/direct-debit-registration-and-api-pay.html>.
- No reliable public official Nagad merchant specification sufficient to certify the implemented signing/callback contract was available during this audit. Production execution therefore remains disabled.

## Gap matrix

| Area | Feature | Existing | Missing / limitation | Security risk | Status | Required fix / disposition |
|---|---|---|---|---|---|---|
| Provider configuration | Environment-only credentials | All sensitive values read from environment | Deployment values absent | Accidental enablement if readiness is bypassed | PASS | Keep readiness boot gate and secret store provisioning |
| Provider configuration | Country/currency/capability filtering | `PaymentGatewayManager` + `ProviderReadinessService` | Legacy contradictory fixture corrected without changing production rules | Cross-currency payout | PASS | Preserve fail-closed market and capability gates |
| Provider adapters | Stripe checkout/webhooks | Checkout, signature, replay, settlement tests | No live account validation | False live claim | UNVERIFIED | Sandbox certification and provider test clocks/events |
| Provider adapters | bKash/Nagad | Adapter and query architecture | Official callback authentication and payout contracts not proved | Forged callback / false credit | PARTIAL | Keep disabled; implement only from merchant-issued current specs |
| Provider adapters | Thai bank payout | SCB/KBANK adapters, status polling, callback/reconciliation | Bank-approved endpoint paths and contracts absent | Duplicate/unknown payout | PARTIAL | Contract confirmation, credentials, sandbox race/reconciliation certification |
| Deposits | Server-authoritative lifecycle | Intent/checkout, pending state, callbacks, completion, ledger, history | Live provider validation absent | Double credit | PASS internally / UNVERIFIED externally | Preserve independent evidence and reference ownership |
| Deposits | Unsupported crypto cleanup | Credit refused | Terminal failures previously could not close pending legacy deposits | Stuck obligations | BROKEN → FIXED | Non-success terminal deposit events may close only; success/reversal still refused |
| Deposits | Manual deposit/slip | Slip providers, receiving-account match, admin resource | Credentials and live feed absent | Forged slip | PARTIAL | Enable only with provider account and receiving-account allowlist |
| Withdrawals | Request, holds, approval, dispatch | Atomic reservation, maker/checker, external-ref ownership, unknown-outcome preservation | Live payout contracts absent | Double payout / premature release | PASS internally / UNVERIFIED externally | MySQL race lane + provider sandbox certification |
| Withdrawals | Destination validation | Bank, PromptPay, bKash/Nagad; unsupported crypto now gets strict syntax refusal | No live beneficiary-name service | Destination substitution | PARTIAL | Add provider-supported account-name verification only when contract exists |
| Payouts | Manual evidence | Canonical payment queue, private evidence, independent reconciliation | Duplicate legacy namespace implementation existed | Divergent settlement path | BROKEN → FIXED | Removed duplicate class; legacy namespace resolves through canonical alias |
| Wallet/admin deposits | Balance mutation boundary | Static coverage, finance services, and Filament action regressions | One-operator wallet amount adjustment and reference-only deposit completion were exposed | Arbitrary balance creation without independent settlement evidence | BROKEN → FIXED | Both generic actions removed; retain provider-authenticated settlement and separately reviewed reversal/reconciliation lanes |
| Ledger | Double entry / reversals | Balanced posting, immutable correction, current-read and ownership proofs | Anti-join tip locking allowed a reproducible InnoDB stale-tip collision | Partial posting / rejected valid posting | BROKEN → FIXED | Singleton `ledger_chain_state` row serializes empty and populated chain appends; MariaDB race batch passes; still certify on deployment MySQL 8 |
| Webhooks | Signature absence semantics | Provider-specific verification | Missing bKash signature returned 422 rather than 403 | Authentication failure ambiguity | BROKEN → FIXED | Missing supported-provider signature now returns 403 before parsing |
| Webhooks | Replay protection | Durable/cache replay ownership and unique event/reference tests | Provider retry horizons must be confirmed per contract | Replay credit | PASS internally | Retention must exceed provider retry horizon |
| Reconciliation | Deposit/withdrawal/provider evidence | Services, commands, admin evidence, immutable cases | No live settlement files/feed | Orphaned transactions | PARTIAL | Configure provider exports/APIs and daily operational ownership |
| KYC | Withdrawal/deposit integration | KYC gate, status checks, tests | Provider/document expiry policy needs deployment governance | KYC bypass | PASS in tested flows | Configure retention/expiry and reviewer roles |
| AML | Money-flow observations and enforcement mode | Production defaults to enforce; tests cover gate | Sanctions/source-of-funds vendor not configured | Regulatory exposure | PARTIAL | Select lawful vendor/process and document case escalation |
| Fraud | Risk engine, idempotent alert recorder, route/service checks | Velocity, linkage, payout change, review/deny modes; replay now preserves first UUID/evidence and emits one event | Production signals/device intelligence are deployment-specific | Replay-mutated case identity/evidence | BROKEN → FIXED internally / PARTIAL externally | Configure lawful signals, thresholds, monitoring and review SLAs |
| Responsible gaming | Age, legal consent, self-exclusion, limits | Server-side middleware and service gates | Jurisdictional legal sign-off external | Alternate-route bypass | PASS technically / UNVERIFIED legally | Licensed-market legal review |
| Admin | Deposits, withdrawals, reconciliation, maker/checker | Filament/resources + controller permissions and audit tests | Provider configuration UI is primarily operational/readiness, not secret editing | Privilege escalation | PASS for tested actions | Keep credentials outside admin/database; retain least privilege |
| APIs | Ownership and auth | Sanctum, active-account middleware, policies, IDOR tests | Multiple compatibility routes increase review surface | Route drift | PARTIAL | Keep canonical services; remove compatibility routes only through versioned deprecation |
| Database | Unique identities and ownership | Provider reference, webhook guard, reservation, ledger constraints, and a migration-owned chain mutex | Full selected MariaDB profile passes; deployment target MySQL 8 remains outstanding | Duplicate reference under concurrency | PASS on MariaDB profile / UNVERIFIED on deployment target | Run the same migrations and race profile on MySQL 8/InnoDB |
| Configuration | Environment template parity | Generator and parity checker exist | 599 application/config keys were missing from each template | Unsafe undocumented defaults | BROKEN → FIXED | Templates regenerated; parity now 919 keys each and secret-shaped examples are blank |
| Upload security | ClamAV implementation | Canonical upload scanner implements both contracts | Duplicate legacy scanner existed | Divergent scan verdicts | BROKEN → FIXED | Removed duplicate implementation; unified protocol tests pass |
| Automated tests | Payment + server races | Final Payment 1,367/1,367 executed tests pass; selected MariaDB server batch 123/123 pass | 59 default Payment skips still require their declared infrastructure/profiles | False green if skipped/changed | PARTIAL | Preserve market gate; complete MySQL 8, Redis and provider lanes |

## Security boundary conclusions

### Verified controls

- Financial API and web mutations require authenticated, active users and route throttles.
- Age and legal-consent middleware protect deposit/withdrawal mutations.
- Wallet balances are prohibited request fields and static coverage rejects non-finance balance writers.
- Deposit credit requires independently verified evidence and canonical settlement; browser redirects are presentation-only.
- Webhook event identity, raw evidence, provider references and replay ownership are persisted and checked.
- Withdrawal funds are reserved before dispatch; uncertain external outcomes preserve the hold and are reconciled instead of blindly resent.
- Payout approval and reconciliation use separate permissions and maker/checker checks.
- Sensitive payout evidence uses private storage/access boundaries; logs use sanitization/redaction.
- Exact decimal strings and BCMath are used for money in the audited financial paths.

### Remaining security/operational risks

- bKash and Nagad must not be enabled until provider-issued callback/disbursement contracts are implemented and certified.
- No automatic failover may reroute an in-flight payout after an uncertain result; current unknown-outcome behavior must be preserved.
- Production provider health probes and daily reconciliation need real credentials and operator ownership.
- The selected MariaDB/InnoDB races passed after the ledger mutex fix; the complete deployment-target MySQL 8 and Redis concurrency lanes remain mandatory before real-money launch.
- Legal eligibility, licensing, sanctions, source-of-funds and data-processing requirements require jurisdiction-specific external approval.

## Database observations

Relevant migrations include durable webhook receipts/replay guards, settlement runs, withdrawal reservations, financial hold claims, provider-reference owners, payout evidence, reconciliation records, ledger posting identity, and unique hash/reference constraints. This work adds `ledger_chain_state`, a singleton transactional mutex and current-tip pointer initialized from the existing chain; initialization refuses multiple historical tips and does not rewrite financial history. All migrations report `Ran` on the disposable MariaDB test database.

## Audit evidence

- 717 payment/finance/provider/KYC/AML/fraud-related paths inventoried by filename.
- Final syntax sweep: 2,863 PHP files linted with no syntax errors.
- 727 routes loaded; no duplicate route name or identical method/URI pair reported by the route audit.
- Environment parity: 919 keys in each of `.env.example`, `.env.ci`, `.env.production.example`; parity checker reports `ok: true`.
- Payment readiness command reports real money disabled and every provider disabled/unconfigured.
- Final post-change Payment suite: 1,367 passed, 0 failed, 59 skipped, 16,196 assertions. The bKash destination fixture now proves fail-closed market/capability behavior and valid syntax independently.
- Final guarded 30-class MariaDB/InnoDB server batch: 123 passed, 0 skipped, 3,065 assertions. `DoubleEntryLedgerServerRaceTest` passed both isolated and in that batch after its mutex defect was corrected.
- Final Finance + Compliance + Security suite: 1,512 passed, 0 failed/errors, 56 skipped, 12,996 assertions. The ClamAV consolidation and current bot-observation middleware contract are green.
- Final Feature/Security suite after the fraud replay regression addition: 100 passed, 0 failed/errors, 2 skipped, 565 assertions (102 total tests).
- Post-fix Compliance + KYC + fraud + security-hardening selection: 211 passed, 0 failed/errors, 14 skipped, 902 assertions (225 total tests).
- Operations/readiness + Thai-bank adapter/webhook/poller selection: 140 passed, 0 failed/errors/skips, 776 assertions.
- Direct route-gate, provider-operation/health, and payout-statement coverage: 5 passed, 0 failed/errors/skips, 32 assertions.
- Part 4 HTTP boundary/security-hardening selection: 11 passed, 0 failed/errors/skips, 81 assertions.
- Part 4 controller/webhook/admin/KYC selection after fixes: 71 passed, 0 failed/errors/skips, 411 assertions.
- Part 5 policy/job focused regression selection: 59 passed, 0 failed/errors/skips, 317 assertions.
- A broader Part 5 run including `ProductionQueueComprehensiveTest` was non-passing (2 errors, 7 failures) because stale fixtures expect closed provider paths and superseded draw/disbursement prerequisites; no current security or provider gate was weakened to satisfy it.
- Part 6 broad UI/config/route/database selection before the final unsafe-action removals: 90 passed, 0 failed/errors/skips, 849 assertions; post-Pint changed-lane selection: 65 passed, 522 assertions.
- Final administrative-finance and cross-part regression lane after removing arbitrary wallet adjustment and reference-only deposit completion: 54 passed, 424 assertions.
- Final checkout-wide syntax lane: 2,926 PHP files, 0 syntax errors.
- Part 6 recursive infrastructure checks: 277 named/database PHP files syntax-pass, 727 routes loaded, all 194 SQLite migrations freshly executed and reported `Ran`, and environment parity remains 919/919/919.
