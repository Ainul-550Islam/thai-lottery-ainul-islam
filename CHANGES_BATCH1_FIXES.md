# Batch 1 fixes

NEW  app/Services/Home/DashboardCacheInvalidator.php  (single owner of dashboard cache key; afterCommit forget)
NEW  app/Services/Rewards/RewardClaimReviewService.php (approve / reject+refund pending claims)
NEW  app/Filament/Resources/RewardClaimResource.php (+ Pages/ListRewardClaims.php)
NEW  database/migrations/2026_11_13_000009_add_clawback_columns_to_cashback_accruals.php
NEW  database/migrations/2026_11_13_000010_add_active_hash_key_to_member_bank_accounts.php
NEW  tests/Feature/Cashback/CashbackReversalTest.php
NEW  tests/Feature/Rewards/RewardClaimReviewTest.php
NEW  tests/Feature/Account/BankAccountGuardsTest.php
EDIT CashbackAccrualService (reverse re-prices / claws back; grade at period end; lazyById)
EDIT VipUpgradeFulfiller (always pending, no method_exists hack, no hardcoded grade)
EDIT LoyaltyPointsService (+refundPoints, additive)
EDIT MemberBankAccountService (neutral duplicate, DB unique, verify/reject guards, name normalisation, lock, payout recheck, fail-closed key)
EDIT WithdrawalService (+lockForUpdate arg, +optional require_verified_bank_account flag)
EDIT LoginEventRecorder (fail-closed key, shared cache invalidator)
EDIT config/rewards.php (+withdrawals.require_verified_bank_account, bank mismatch note length, honorifics)
EDIT CashbackClaimService, RewardRedemptionService, ReferralPayoutRequestService, PlayerDashboardSummaryService, CashbackAccrual, MemberBankAccount, Batch1OwnershipTest

---
# Gap-closure pass (after prompt comparison)

NEW  REUSE_MAP_BATCH1.md (G7 reuse proof generated from real `use` statements)
NEW  tests/Unit/Cashback/CashbackPolicyConfigParityTest.php (config/rewards.php grades == AccountGradeLevel cases, rate_bps > 0)
EDIT app/Providers/RewardsServiceProvider.php (+bootLoginListeners, +listenOnce: explicit RecordLoginEvent / NotifyOnNewDeviceLogin binding, de-duplicated against auto-discovery)
EDIT tests/Feature/Batch1ActivationTest.php (route test now exact: 15 routes, not "14"; +test_login_listener_is_bound_exactly_once_per_event)
EDIT config/rewards.php (removed duplicate login_history.geoip; single rewards.geoip.driver)
EDIT app/Services/Security/LoginHistory/GeoIp/GeoIpResolverManager.php (dropped the duplicate-key fallback)
EDIT database/migrations/..._000010_add_active_hash_key_to_member_bank_accounts.php (backfill keeps oldest live row per hash; legacy duplicates cannot break the unique index)

Note: the prompt text says "14 routes" but lists 15; 15 is what is implemented.
Gate evidence produced here: G4 (php -l on all 130 PHP files: 0 failures), G5 (0 direct balance writes in Batch 1 code), G7 (REUSE_MAP_BATCH1.md).
Gates needing the full repo + DB (G1, G2 output, G3 counts, G6 x20) must be run on your machine.

---
# Full line-by-line review pass (second gap-closure)

EDIT app/Services/Security/LoginHistory/LoginEventRecorder.php (+recentDuplicateSuccess: same successful login arriving via Login event AND API token issuance is recorded once; Failed/Blocked never collapsed)
NEW  tests/Feature/Security/LoginEventDedupeTest.php (4 tests: dedupe, failures kept, other IP kept, window 0)
EDIT config/rewards.php (+login_history.dedupe_window_seconds, +cashback.accrual_chunk_size, +bank_accounts.account_number_min_digits/max_digits)
EDIT .env.ci .env.example .env.production.example (+REWARDS_LOGIN_DEDUPE_SECONDS, REWARDS_CASHBACK_ACCRUAL_CHUNK, REWARDS_BANK_ACCOUNT_MIN/MAX_DIGITS)
EDIT app/Services/Affiliate/ReferralCenterService.php (removed the fake `$_ = MultiTier::class` dependency; rate now openly from Agent / CommissionRule / AffiliateCommissionDisplayService)
EDIT app/Http/Requests/Api/V1/ReferralPayoutRequest.php (currency rule from Currency enum instead of hardcoded THB,USD,BDT)
EDIT app/Http/Requests/Api/V1/StoreBankAccountRequest.php + app/Services/Account/MemberBankAccountService.php (account-number digit bounds from config, one source)
EDIT app/Jobs/Cashback/AccrueCashbackJob.php + app/Console/Commands/Cashback/AccrueCashbackCommand.php (chunk size from config)
EDIT app/Filament/Resources/MemberBankAccountResource.php + Pages/ViewMemberBankAccount.php (FinancialException on verify/reject shown as a notification, not a 500)
EDIT Filament Create/Edit pages of CashbackPolicyResource and RewardCatalogItemResource (every admin create/update writes an AuditLog row)
EDIT tests/Architecture/Batch1OwnershipTest.php (+LoginEventDedupeTest in the scanned file list)
EDIT REUSE_MAP_BATCH1.md (regenerated)

Open item that needs YOUR class: the prompt asks for the commission rate "from the existing MultiTier service".
MultiTierCommissionDistributionService is not in the zip, so its method name is unknown; the rate is currently
read from Agent.commission_rate / CommissionRule / AffiliateCommissionDisplayService. Send that class and the
one-line swap will be made.

---
# Real-money hardening (final pass)

EDIT app/Services/Cashback/CashbackClaimService.php
     Lock order fixed to: member row -> accrual rows -> wallet.
     Before: claim() locked the wallet first and then the accrual rows, while
     CashbackAccrualService::reverse() locks accrual rows first and the wallet last
     (clawback). A claim and a bet-cancel on the same member could deadlock.
     Parallel claims still serialize on the member lock, so exactly one credit remains.

OPEN ITEMS THAT NEED INPUT FROM THE REPO OWNER (cannot be settled from this zip):
 1. Bet settlement timestamp. CashbackAccrualService::settledRealMoneyNetLoss and the accrual
    job window a settled bet by won_at, or by updated_at when won_at is null. If any later write
    touches a lost bet (updated_at moves), it can fall into a second period and be counted twice.
    Send app/Models/Bet.php (or the bets migration): if a dedicated settled_at column exists, the
    window should use it.
 2. MultiTierCommissionDistributionService (commission rate source) - see section above.
 3. Original WalletService / WithdrawalService / LoyaltyPointsService / User / Withdrawal /
    WithdrawalRequest / NotificationEventType to produce the exact additive diff (hard rule 1).
 4. Gates G1, G2, G3, G6 must be run against the full repository and database.

---
# Automated verifier

NEW tools/verify_batch1.php   (PHP only, no Laravel boot, no DB)
    php tools/verify_batch1.php          # zip mode: here -> 71 PASS, 0 FAIL, RESULT: CLEAN
    php tools/verify_batch1.php --repo   # run in your FULL repo: every App\ import must resolve
Checks: required tree (95 files + 8 migrations), php -l, class map / duplicate classes, config('rewards.*')
keys defined, env vars in all 3 env files, no float / balance writes / raw wallet SQL, money pattern
(idempotency + wallet lock + ledger type + Money) in every file that credits or debits, transaction + row
lock + audit on money services, RealMoneyGate, lock order member->accruals->wallet, calculator purity,
single table owners, 15 routes + controller methods, provider/listener/schedule wiring, every injected
service call resolves to a real method, Filament pages, migrations order + up/down, tests non-empty,
no TODO/bak leftovers.
It was mutation-tested: 7 injected defects (float cast, wrong lock order, deleted file, undocumented env,
removed route, TODO, missing controller method) were all caught.
tools/external_dependencies.txt lists the App\ classes that must exist in your repo.
