# Batch 1 reuse proof (G7) — generated from `use` statements in the code

| New service | Existing (outside Batch 1) classes it delegates to | Batch 1 classes it composes |
|---|---|---|
| app/Services/Account/MemberBankAccountService.php | AuditLog, KycDocument, KycVerification | DashboardCacheInvalidator |
| app/Services/Affiliate/ReferralCenterService.php | Agent, AgentCommission, CommissionRule, PublicReferralLinkService, ReferralLinkClick, WalletHoldService | WalletService |
| app/Services/Affiliate/ReferralPayoutRequestService.php | IdempotencyService, Money, PaymentGatewayManager, WithdrawalRequestWithHoldService | DashboardCacheInvalidator, MemberBankAccountService, WalletService |
| app/Services/Cashback/CashbackAccrualService.php | AccountGradeEvaluator, AuditLog, FinancialTransaction, IdempotencyService, Money, WalletLockService | DashboardCacheInvalidator, WalletService |
| app/Services/Cashback/CashbackBalanceReader.php | AccountGradeEvaluator | — |
| app/Services/Cashback/CashbackCalculator.php | Money | — |
| app/Services/Cashback/CashbackClaimService.php | AccountGradeEvaluator, AuditLog, FinancialTransaction, IdempotencyService, Money, RealMoneyGate, WalletLockService | DashboardCacheInvalidator, WalletService |
| app/Services/Home/DashboardCacheInvalidator.php | — | — |
| app/Services/Home/PlayerDashboardSummaryService.php | Draw, FinancialTransaction, WalletHoldService | CashbackBalanceReader, FreeBetVoucherService, LoginHistoryQueryService, LoyaltyPointsService, ReferralCenterService, VipProgressService, WalletService |
| app/Services/Rewards/FreeBetVoucherService.php | AuditLog, Money | — |
| app/Services/Rewards/Fulfillers/BonusCashFulfiller.php | IdempotencyService, Money, WalletLockService | WalletService |
| app/Services/Rewards/Fulfillers/CashbackBoostFulfiller.php | — | — |
| app/Services/Rewards/Fulfillers/FreeBetFulfiller.php | Money | FreeBetVoucherService |
| app/Services/Rewards/Fulfillers/RewardFulfiller.php | — | — |
| app/Services/Rewards/Fulfillers/VipUpgradeFulfiller.php | AccountGradeService | — |
| app/Services/Rewards/RewardCatalogService.php | AccountGradeEvaluator | LoyaltyPointsService |
| app/Services/Rewards/RewardClaimReviewService.php | AuditLog | DashboardCacheInvalidator, LoyaltyPointsService |
| app/Services/Rewards/RewardFulfillerRegistry.php | — | BonusCashFulfiller, CashbackBoostFulfiller, FreeBetFulfiller, VipUpgradeFulfiller |
| app/Services/Rewards/RewardRedemptionService.php | AccountGradeEvaluator, AuditLog, RealMoneyGate | DashboardCacheInvalidator, LoyaltyPointsService |
| app/Services/Rewards/VipProgressService.php | AccountGradeEvaluator | LoyaltyPointsService |
| app/Services/Security/LoginHistory/GeoIp/GeoIpResolver.php | — | — |
| app/Services/Security/LoginHistory/GeoIp/GeoIpResolverManager.php | — | — |
| app/Services/Security/LoginHistory/GeoIp/GeoIpResult.php | — | — |
| app/Services/Security/LoginHistory/GeoIp/NullGeoIpResolver.php | — | — |
| app/Services/Security/LoginHistory/LoginEventRecorder.php | — | DashboardCacheInvalidator, GeoIpResolver |
| app/Services/Security/LoginHistory/LoginHistoryQueryService.php | — | — |
| app/Services/Security/LoginHistory/NewDeviceDetector.php | TrustedDevice | — |
