#!/usr/bin/env python3
"""Passive release/source inventory. No Laravel, DB, .env, network or command execution.

Page numbers are request slots, not proven deployed pages. The old recipe is
parsed as AST data, never imported/executed. No historical runtime claim is copied.
"""
from __future__ import annotations

import argparse
import ast
import hashlib
import json
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
SOURCES = (
    'tests/Feature/Finance/WalletWriteRefusalTest.php',
    'tests/Feature/Finance/LedgerPostingWriteRefusalTest.php',
    'tests/Feature/Finance/LedgerPostingCallbackJournalTest.php',
    'app/Services/Finance/LedgerPostingInputWitness.php',
    'tests/Unit/Services/LedgerPostingInputWitnessTest.php',
    'tests/Feature/Finance/LedgerPostingInputDriftTest.php',
    'app/Services/Finance/LedgerPostingInvocationLease.php',
    'tests/Unit/Services/LedgerPostingInvocationLeaseTest.php',
    'tests/Feature/Finance/LedgerPostingReentrancyTest.php',
    'app/Services/Finance/LedgerPostingWriteBoundary.php',
    'tests/Unit/Services/LedgerPostingWriteBoundaryTest.php',
    'tests/Feature/Finance/LedgerPostingWriteBoundaryTest.php',
    'app/Services/Finance/LedgerEntryPayloadPreflight.php',
    'tests/Unit/Services/LedgerEntryPayloadPreflightTest.php',
    'tests/Feature/Finance/LedgerPostingEntryPayloadTest.php',
    'app/Services/Finance/LedgerEntryMoneyPreflight.php',
    'tests/Unit/Services/LedgerEntryMoneyPreflightTest.php',
    'tests/Feature/Finance/LedgerPostingEntryMoneyTest.php',
    'app/Services/Finance/LedgerAccountBalanceProjector.php',
    'tests/Unit/Services/LedgerAccountBalanceProjectorTest.php',
    'tests/Feature/Finance/LedgerPostingBalanceProjectionTest.php',
    'tests/Feature/Finance/LedgerPostingDraftIdentityTest.php',
    'app/Services/Finance/LedgerPostingObserver.php',
    'tests/Feature/Finance/LedgerPostingObserverOwnershipTest.php',
    'tests/Feature/Finance/LedgerPostingCurrentReadTest.php',
    'tests/Feature/Finance/LedgerPostingCurrentReadServerTest.php',
    'tests/Support/LedgerPostingProbe.php',
    'tests/Feature/Finance/LedgerPostingAdmissionTest.php',
    'tests/Feature/Finance/FinancialModelRoutingContextTest.php',
    'app/Services/Finance/FinancialRecordIdentifier.php',
    'tests/Unit/FinancialRecordIdentifierTest.php',
    'tests/Feature/Finance/FinancialIdentifierRoutingTest.php',
    'tests/Feature/Finance/FinancialRetryOwnershipTest.php',
    'tests/Feature/Finance/FinancialRetryOwnershipServerTest.php',
    'tests/Support/FinancialRetryProbe.php',
    'scripts/verify_financial_retry_ci.py',
    'scripts/test_verify_financial_retry_ci.py',
    'app/Services/Finance/FinancialStateTransitionService.php',
    'tests/Support/LifecycleBoundaryOperation.php',
    'tests/Feature/Finance/LifecycleTransactionBoundaryTest.php',
    'tests/Feature/Finance/LifecycleTransactionBoundaryServerTest.php',
    'scripts/verify_lifecycle_boundary_ci.py',
    'scripts/test_verify_lifecycle_boundary_ci.py',
    'tests/Support/ReversalBoundaryOperation.php',
    'tests/Feature/Finance/ReversalTransactionBoundaryTest.php',
    'tests/Feature/Finance/ReversalTransactionBoundaryServerTest.php',
    'app/Services/Finance/WalletLockService.php',
    'app/Services/Finance/WalletHoldService.php',
    'app/Services/Finance/FinancialTransactionService.php',
    'tests/Support/WalletBoundaryOperation.php',
    'tests/Feature/Finance/WalletTransactionBoundaryTest.php',
    'tests/Feature/Finance/WalletTransactionBoundaryServerTest.php',
    'app/Services/Wallet/Conversion/ConversionWalletEligibility.php',
    'tests/Support/ConversionWalletEligibilityFixture.php',
    'tests/Feature/Wallet/Conversion/ConversionWalletEligibilityTest.php',
    'tests/Feature/Wallet/Conversion/ConversionWalletEligibilityServerTest.php',
    'app/Services/Wallet/Conversion/ConversionMemberEligibility.php',
    'app/Services/Wallet/Conversion/ConversionPolicyGuard.php',
    'tests/Support/ConversionMemberEligibilityFixture.php',
    'tests/Feature/Wallet/Conversion/ConversionMemberEligibilityTest.php',
    'tests/Feature/Wallet/Conversion/ConversionMemberEligibilityServerTest.php',
    'app/Repositories/Wallet/WalletConversionQuoteRepository.php',
    'app/Services/Wallet/Conversion/ConversionQuoteService.php',
    'tests/Support/ConversionQuoteSlotFixture.php',
    'tests/Feature/Wallet/Conversion/ConversionQuoteSlotTest.php',
    'tests/Feature/Wallet/Conversion/ConversionQuoteSlotServerTest.php',
    'app/Services/Wallet/Conversion/ConversionLimitService.php',
    'tests/Support/ConversionRollingLimitFixture.php',
    'tests/Feature/Wallet/Conversion/ConversionRollingLimitTest.php',
    'tests/Feature/Wallet/Conversion/ConversionRollingLimitServerTest.php',
    'app/Services/Wallet/Conversion/ConversionReversalAuthority.php',
    'tests/Support/ConversionReversalAuthorityFixture.php',
    'tests/Feature/Wallet/Conversion/ConversionReversalAuthorityTest.php',
    'tests/Feature/Wallet/Conversion/ConversionReversalAuthorityServerTest.php',

    'app/Services/Wallet/Conversion/ConversionIntegrityGuard.php',
    'app/Services/Finance/LedgerPostingService.php',
    'tests/Feature/Wallet/Conversion/ConversionOriginalProofTest.php',
    'tests/Feature/Wallet/Conversion/ConversionOriginalProofServerTest.php',
    'tests/Support/RunsServerFinancialRaces.php',
    'app/Services/Wallet/Conversion/StripeRefundConversionGuard.php',
    'app/Services/Wallet/Conversion/WalletConversionService.php',
    'tests/Support/StripeRefundConversionFixture.php',
    'tests/Feature/Wallet/Conversion/StripeRefundConversionGuardTest.php',
    'tests/Feature/Wallet/Conversion/StripeRefundConversionGuardServerTest.php',
    'app/Services/Finance/WithdrawalInitialHoldService.php',
    'database/migrations/2026_10_18_000700_create_withdrawal_initial_holds.php',
    'app/Services/Finance/WithdrawalRequestWithHoldService.php',
    'app/Services/Finance/WithdrawalReservationService.php',
    'app/Services/Finance/WithdrawalHoldFloor.php',
    'app/Services/Finance/WithdrawalService.php',
    'app/Services/Finance/WithdrawalApprovalService.php',
    'app/Services/Finance/WithdrawalIntegrityGuard.php',
    'app/Enums/WithdrawalStatus.php',
    'tests/Feature/Payment/WithdrawalInitialHoldHandoffTest.php',
    'tests/Feature/Payment/WithdrawalInitialHoldHandoffServerTest.php',
    'tests/Support/FinancialRaceProbe.php',
    '.github/workflows/financial-concurrency.yml',
    'scripts/release_source_inventory.py',

    'app/Services/Finance/StripeRefundWithdrawalGuard.php',
    'app/Http/Controllers/Api/V1/WithdrawalController.php',
    'tests/Feature/Payment/StripeRefundWithdrawalGuardTest.php',
    'tests/Feature/Payment/StripeRefundWithdrawalGuardServerTest.php',

    'app/Services/Betting/BetPurchaseTransactionService.php',
    'app/Services/Betting/StripeRefundWagerGuard.php',
    'app/Services/Betting/BetPurchaseAdmission.php',
    'app/Http/Support/BetPurchaseErrorMapper.php',
    'app/Services/Betting/BetAmendmentService.php',
    'tests/Support/StripeRefundWagerFixture.php',
    'tests/Feature/Betting/StripeRefundWagerGuardTest.php',
    'tests/Feature/Betting/StripeRefundWagerGuardServerTest.php',

    'database/migrations/2026_10_18_000600_create_stripe_refund_recoveries.php',
    'app/Services/Payment/StripeRefundRecoveryAccess.php',
    'app/Services/Payment/StripeRefundRecoveryLookup.php',
    'app/Services/Payment/StripeRefundRecoveryService.php',
    'app/Models/StripeRefundRecoveryIntent.php',
    'tests/Support/StripeRefundRecoveryFixture.php',
    'tests/Feature/Payment/StripeRefundRecoveryTest.php',
    'tests/Feature/Payment/StripeRefundRecoveryServerTest.php',

    'database/migrations/2026_10_18_000500_create_stripe_followup_sweep_work.php',
    'app/Services/Payment/StripeFollowupSweepService.php',
    'app/Console/Commands/StripeFollowupSweep.php',
    'app/Models/StripeFollowupSweepWork.php',
    'routes/console.php',
    'tests/Feature/Payment/StripeFollowupSweepTest.php',
    'tests/Feature/Payment/StripeFollowupSweepServerTest.php',

    'database/migrations/2026_10_18_000400_create_stripe_followup_route_decisions.php',
    'app/Services/Payment/StripeFollowupRoutingService.php',
    'app/Models/StripeDepositFollowup.php',
    'app/Models/StripeFollowupRouteDecision.php',
    'app/Filament/Resources/StripeDepositFollowupResource.php',
    'app/Filament/Resources/StripeDepositFollowupResource/Pages/ListStripeDepositFollowups.php',
    'tests/Feature/Payment/StripeFollowupRoutingTest.php',
    'tests/Feature/Payment/StripeFollowupRoutingServerTest.php',

    'database/factories/Support/LotteryFactoryDate.php',
    'database/factories/BingoLotteryDrawFactory.php',
    'database/factories/WeeklyLotteryDrawFactory.php',
    'database/factories/NationalLotteryDrawFactory.php',
    'tests/Feature/Database/LotteryFactoryDateTest.php',

    'database/migrations/2026_10_18_000300_create_stripe_deposit_review_cases.php',
    'app/Services/Payment/DepositReviewAccess.php',
    'app/Services/Payment/StripeDepositFollowupInbox.php',
    'app/Services/Payment/StripeDepositReviewService.php',
    'app/Http/Controllers/Api/V1/StripeDepositFollowupController.php',
    'app/Filament/Resources/DepositResource/StripeDepositReviewActions.php',
    'tests/Support/StripeDepositReviewFixture.php',
    'tests/Feature/Payment/StripeDepositReviewTest.php',
    'tests/Feature/Payment/StripeDepositReviewServerTest.php',

    'app/Services/Payment/DepositSettlementAccess.php',
    'app/Services/Payment/StripeDepositCaptureLookup.php',
    'app/Services/Payment/StripeDepositSettlementService.php',
    'database/migrations/2026_10_18_000200_create_stripe_deposit_settlement_receipts.php',
    'tests/Feature/Payment/StripeDepositSettlementTest.php',
    'tests/Feature/Payment/StripeDepositSettlementServerTest.php',

    'app/Services/Payment/DepositCheckoutAccess.php',
    'app/Services/Payment/StripeCheckoutLookup.php',
    'database/migrations/2026_10_18_000100_create_deposit_checkout_lookup_receipts_table.php',
    'tests/Feature/Payment/StripeCheckoutReconciliationTest.php',
    'tests/Feature/Payment/StripeCheckoutReconciliationServerTest.php',

    'app/Services/Payment/DepositCheckoutService.php',
    'database/migrations/2026_10_17_000500_create_deposit_checkout_attempts_table.php',
    'tests/Feature/Payment/DurableDepositCheckoutTest.php',
    'tests/Feature/Payment/DurableDepositCheckoutServerTest.php',
    'tests/Support/SyntheticDepositCheckoutGateway.php',

    'app/Services/Finance/DepositAdmission.php',
    'app/Services/Payment/PaymentInitiationService.php',
    'tests/Feature/Compliance/PlayerDepositAdmissionTest.php',
    'tests/Feature/Compliance/PlayerDepositAdmissionServerTest.php',

    'app/DTOs/ResponsibleGaming/ResponsibleGamingLimitData.php',
    'database/migrations/2026_10_17_000400_create_responsible_gaming_limit_ownerships_table.php',
    'app/Services/ResponsibleGaming/ResponsibleGamingLimitOwnership.php',
    'app/Services/Security/ResponsibleGamingService.php',
    'app/Http/Controllers/Api/V1/ResponsibleGamingController.php',
    'bootstrap/app.php',
    'tests/Feature/Compliance/ResponsibleGamingLimitLifecycleTest.php',
    'tests/Feature/Compliance/ResponsibleGamingLimitLifecycleServerTest.php',

    'app/Services/ResponsibleGaming/ResponsibleGamingPlayerLock.php',
    'app/Services/ResponsibleGaming/ResponsibleGamingEnforcementService.php',
    'app/Services/ResponsibleGaming/ResponsibleGamingLimitService.php',
    'app/Services/ResponsibleGaming/SelfExclusionService.php',
    'tests/Feature/Betting/PlayerWagerAdmissionTest.php',
    'tests/Feature/Betting/PlayerWagerAdmissionServerTest.php',

    'app/Services/Risk/DrawRiskAdmission.php',
    'app/Services/Risk/RiskAssessmentService.php',
    'tests/Feature/Risk/DrawRiskAdmissionTest.php',
    'tests/Feature/Risk/DrawRiskAdmissionServerTest.php',

    'tests/Feature/Filament/OwnedDrawCancellationActionTest.php',
    'database/migrations/2026_10_17_000300_create_draw_cancellation_ownerships_table.php',
    'app/Services/Draw/DrawCancellationService.php',
    'app/Services/Draw/DrawLifecycleService.php',
    'app/Filament/Resources/DrawResource/DrawLifecycleActions.php',
    'config/lottery.php',
    'tests/Feature/Draw/OwnedDrawCancellationTest.php',
    'tests/Feature/Draw/OwnedDrawCommissionCancellationTest.php',
    'tests/Feature/Draw/OwnedDrawCancellationServerRaceTest.php',


    'database/migrations/2026_10_17_000200_create_bet_purchase_receipts_table.php',
    'app/Services/Betting/BetPurchaseReceipt.php',
    'app/Services/Betting/BetPurchaseReplayService.php',
    'app/Services/Betting/BetPurchaseIdempotencyService.php',
    'tests/Feature/Betting/OwnedPurchaseReplayTest.php',
    'tests/Feature/Betting/OwnedPurchaseReplayServerRaceTest.php',

    'app/Services/Betting/BetAmendmentOwnership.php',
    'app/Services/Betting/BetAmendmentPermit.php',
    'app/Services/Betting/BetAmendmentReplacement.php',
    'database/migrations/2026_10_17_000100_create_bet_amendment_ownerships_table.php',
    'tests/Feature/Betting/Concerns/BuildsOwnedAmendment.php',
    'tests/Feature/Betting/OwnedBetAmendmentTest.php',
    'tests/Feature/Betting/OwnedBetAmendmentServerRaceTest.php',
    'app/Services/Betting/BetPurchaseService.php',
    'app/Providers/AppServiceProvider.php',
    'app/Http/Controllers/Api/V1/BetAmendmentController.php',

    'database/migrations/2026_10_16_000600_create_agent_commission_compensations_table.php',
    'app/Services/Agent/BetCommissionCompensation.php',
    'app/Services/Agent/AgentCommissionReversalService.php',
    'app/Services/Finance/FinancialReversalService.php',
    'config/agent.php',
    'tests/Feature/Agent/Concerns/BuildsPaidCommissionCancellation.php',
    'tests/Feature/Agent/PaidCommissionCancellationTest.php',
    'tests/Feature/Agent/PaidCommissionCurrentReadTest.php',
    'tests/Feature/Agent/CommissionRefundReversalTest.php',
    'tests/Feature/Agent/CommissionDrawCancellationTest.php',

    'database/migrations/2026_10_16_000500_create_agent_commission_payouts_table.php',
    'app/Services/Agent/AgentCommissionPayoutProof.php',
    'app/Services/Agent/AgentCommissionPayoutService.php',
    'app/Services/Agent/AgentCommissionSettlementService.php',
    'app/Services/Agent/AgentSettlementService.php',
    'app/Services/Agent/AgentCommissionService.php',
    'tests/Feature/Agent/AgentInactiveBehaviorTest.php',
    'tests/Feature/BusinessCriticalInvariantTest.php',
    'tests/Feature/FinalProductionReadinessTest.php',
    'tests/Feature/Agent/OwnedCommissionPayoutTest.php',
    'tests/Feature/Agent/CommissionPayoutCurrentReadTest.php',

    'database/migrations/2026_10_16_000400_create_bet_commission_ownerships_table.php',
    'app/Services/Agent/BetCommissionCancellation.php',
    'app/Services/Agent/AgentCommissionAccrualService.php',
    'tests/Feature/Agent/Concerns/BuildsCommissionCancellation.php',
    'tests/Feature/Agent/CoordinatedUnpaidCancellationTest.php',
    'tests/Feature/Agent/CommissionCancellationCurrentReadTest.php',

    'app/Http/Controllers/Api/V1/BetCancellationController.php',
    'app/Services/Betting/BetCancellationService.php',
    'app/Services/Betting/BetAccountingProof.php',
    'app/Services/Betting/BetPurchaseOwnership.php',
    'app/Services/Risk/BetRiskOwnershipFloor.php',
    'app/Services/Risk/NumberLimitEngine.php',
    'database/migrations/2026_10_16_000300_create_bet_purchase_ownerships_table.php',
    'tests/Feature/Betting/BetCancellationOwnershipTest.php',
    'tests/Feature/Betting/BetCancellationCurrentReadTest.php',
    'tests/Feature/Betting/Concerns/BuildsOwnedBetPurchase.php',

    'app/DTOs/Finance/FinancialHoldData.php',
    'app/Services/Finance/FinancialHoldService.php',
    'app/Services/Finance/FinancialHoldOwnership.php',
    'database/migrations/2026_10_16_000200_create_financial_hold_claims_table.php',
    'tests/Feature/Payment/FinancialHoldOwnershipTest.php',
    'tests/Feature/Payment/FinancialHoldCurrentReadTest.php',

    'package.json',
    'package-lock.json',
    'composer.lock',
    '.nvmrc',
    '.npmrc',
    'postcss.config.js',
    'tailwind.config.js',
    'resources/css/app.css',
    'resources/css/tailwind-compat.css',
    'resources/css/tailwind-legacy-theme.json',
    'scripts/dependency_security_gate.mjs',
    'tests/dependencies/security-gate.test.mjs',
    'tests/browser/frontend-build.helpers.mjs',
    'tests/browser/frontend-build.spec.mjs',
    'tests/browser/fixtures/tailwind-v3-compat.json',
    'tests/Unit/Production/DependencyAuditBoundaryTest.php',
    '.github/workflows/ci.yml',
    '.github/workflows/security.yml',

    'app/Services/Finance/ReversalIntegrityGuard.php',
    'app/Services/Finance/ExternalRefundPolicy.php',
    'app/Services/Finance/RefundService.php',
    'tests/Feature/Finance/ReversalIntegrityTest.php',
    'tests/Feature/Payment/ManagedRefundBoundaryTest.php',
    'tests/Feature/Payment/ReversalIntegrityServerRaceTest.php',
    'tests/Feature/Payment/PaymentLedgerBalanceTest.php',

    'app/Services/Finance/WalletService.php',
    'tests/Feature/Payment/WithdrawalHoldFloorTest.php',
    'tests/Feature/Payment/WithdrawalHoldFloorServerRaceTest.php',

    'app/Services/Payment/WithdrawalCallbackApplicationService.php',
    'database/migrations/2026_10_16_000200_create_withdrawal_reservations_table.php',
    'tests/Feature/Payment/WithdrawalOriginalProofTest.php',

    'app/Services/Finance/ExactReconciliationReader.php',
    'app/Services/Finance/FinancialReconciliationService.php',
    'app/Services/Finance/LedgerReconciliationService.php',
    'tests/Support/InjectsHistoricalAccountingFaults.php',
    'tests/Feature/Finance/FinancialReconciliationComprehensiveTest.php',
    'tests/Feature/Finance/ReconciliationOriginalEvidenceTest.php',

    'tests/Feature/Web/BrowserPaymentCallbackTest.php',
    'tests/Feature/FinalWholeSystemNoSkipTest.php',
    'app/Services/Finance/DepositIntegrityGuard.php',
    'app/Services/Payment/DepositProviderAccountService.php',
    'app/Services/Payment/FinancialProviderReferenceService.php',
    'app/Services/Payment/DepositCallbackApplicationService.php',
    'app/Console/Commands/Finance/DepositReferenceAuditCommand.php',
    'database/migrations/2026_10_16_000100_create_financial_provider_reference_owners_table.php',
    'tests/Feature/Payment/DepositOriginalIntegrityTest.php',
    'tests/Feature/Payment/DepositCallbackOriginalProofTest.php',
    'tests/Feature/Payment/DepositReferenceAuditTest.php',
    'tests/Feature/Payment/DepositReferenceServerRaceTest.php',
    'app/Filament/Resources/DepositResource/DepositDecisionActions.php',
    'app/Services/Finance/DepositCompletionService.php',
    'app/Services/Finance/DepositService.php',
    'app/Services/Payment/PaymentCallbackService.php',
    'app/Services/Payment/PaymentVerificationService.php',
    'tests/Feature/Filament/FinanceResourcesTest.php',
    'tests/Feature/Payment/PaymentCallbackServiceTest.php',

    'app/Services/Prize/PrizeClaimGuard.php',
    'app/Services/Prize/PrizeClaimService.php',
    'app/Services/Prize/ClaimWindowService.php',
    'app/Services/Prize/PrizeExpiryService.php',
    'app/Services/Prize/UnclaimedPrizeService.php',
    'app/Services/Prize/PrizeClaimCompletionService.php',
    'app/Services/Prize/PrizeClaimValidationService.php',
    'app/Enums/PrizeClaimStatus.php',
    'app/Enums/ClaimWindowStatus.php',
    'app/Http/Requests/Prize/CreatePrizeClaimRequest.php',
    'app/Http/Requests/Admin/ReviewPlayerPrizeClaimRequest.php',
    'app/Http/Controllers/Admin/PlayerPrizeClaimReviewController.php',
    'app/Http/Controllers/Api/V1/PrizeClaimController.php',
    'app/Http/Resources/PrizeClaimResource.php',
    'resources/views/admin/player-prize-claims.blade.php',
    'app/Jobs/ExpireUnclaimedPrizesJob.php',
    'app/Jobs/SweepUnclaimedPrizesJob.php',
    'app/Jobs/CloseExpiredClaimWindowsJob.php',
    'tests/Support/CreatesPlayerPrizeClaim.php',
    'tests/Feature/Payment/PlayerPrizeClaimLifecycleTest.php',
    'tests/Feature/Payment/PlayerPrizeClaimHttpTest.php',
    'tests/Feature/Payment/PlayerPrizeExpiryTest.php',
    'tests/Feature/Payment/PlayerPrizeClaimCompletionTest.php',
    'tests/Feature/Payment/PlayerPrizeClaimServerRaceTest.php',
    'tests/Feature/Api/V1/Batch7ApiSurfaceTest.php',

    'tests/Feature/Payment/PayoutEvidenceServerRaceTest.php',
    'database/migrations/2026_10_15_000600_create_payout_transfer_evidence_tables.php',
    'app/Services/Payment/PayoutEvidenceStore.php',
    'app/Services/Payment/PayoutEvidenceService.php',
    'app/Http/Requests/Admin/UploadPayoutEvidenceRequest.php',
    'app/Http/Controllers/Admin/PayoutEvidenceController.php',
    'app/Console/Commands/Finance/PayoutEvidenceAuditCommand.php',
    'tests/Feature/Payment/PayoutEvidenceWorkflowTest.php',
    'tests/Feature/Payment/PayoutEvidenceHttpTest.php',
    'tests/Feature/Payment/PayoutEvidenceRetentionTest.php',

    'database/migrations/2026_10_15_000500_create_outbound_provider_references_table.php',
    'app/Services/Payment/OutboundProviderReferenceService.php',
    'app/Services/Payment/OutboundReferenceHistoryService.php',
    'app/Services/Payment/OutboundReferenceAuditService.php',
    'app/Console/Commands/Finance/OutboundReferenceAuditCommand.php',
    'tests/Feature/Payment/OutboundReferenceCrossDomainTest.php',
    'tests/Feature/Payment/OutboundReferenceRegistryTest.php',
    'tests/Feature/Payment/OutboundReferenceAuditTest.php',
    'tests/Feature/Payment/OutboundReferenceServerRaceTest.php',

    'database/migrations/2026_10_15_000400_create_payout_batch_members_table.php',
    'app/Services/Finance/PayoutBatchMembershipService.php',
    'app/Services/Finance/PayoutBatchService.php',
    'app/Services/Finance/ApprovedWalletPayoutService.php',
    'app/Services/Finance/PayoutBatchRoutingService.php',
    'app/Services/Finance/PayoutApprovalIntakeService.php',
    'app/Jobs/ProcessPayoutBatchJob.php',
    'app/Jobs/GeneratePayoutBatchJob.php',
    'app/Http/Controllers/Admin/PayoutApprovalIntakeController.php',
    'app/Http/Requests/Admin/StorePayoutApprovalRequest.php',
    'app/Http/Requests/Admin/DecidePayoutApprovalRequest.php',
    'app/Http/Resources/PayoutApprovalResource.php',
    'resources/views/admin/payout-approvals.blade.php',
    'app/Services/Prize/PrizeDisbursementService.php',
    'tests/Feature/Payment/PayoutApprovalIntakeHttpTest.php',
    'tests/Feature/Payment/PayoutBatchRoutingTest.php',
    'tests/Feature/Payment/ApprovedWalletPayoutTest.php',
    'tests/Feature/Payment/PayoutBatchProjectionTest.php',
    'tests/Feature/Production/QueueDispatchContractTest.php',

    'database/migrations/2026_10_15_000300_create_payout_transfer_references_table.php',
    'app/Services/Finance/PayoutApprovalGuard.php',
    'app/Services/Finance/PayoutApprovalService.php',
    'app/Services/Payment/PayoutTransferAccess.php',
    'app/Services/Payment/PayoutTransferGuard.php',
    'app/Services/Payment/PayoutTransferReferenceService.php',
    'app/Services/Finance/ExternalPayoutCompletionService.php',
    'app/Services/Payment/PayoutTransferService.php',
    'app/Jobs/ExecutePayoutTransferJob.php',
    'app/Http/Requests/Admin/ReviewPayoutTransferRequest.php',
    'app/Http/Resources/PayoutTransferResource.php',
    'app/Http/Controllers/Admin/PayoutTransferReviewController.php',
    'resources/views/admin/payout-transfers.blade.php',
    'tests/Feature/Payment/PayoutApprovalBoundaryTest.php',
    'tests/Feature/Payment/PayoutTransferIdentityTest.php',
    'tests/Feature/Payment/PayoutTransferCompletionTest.php',
    'tests/Feature/Payment/PayoutTransferReferenceTest.php',
    'tests/Feature/Payment/PayoutTransferHttpTest.php',
    'tests/Feature/Payment/PayoutTransferJobTest.php',
    'tests/Support/CreatesExternalPayout.php',
    'database/migrations/2026_10_15_000200_create_withdrawal_provider_references_table.php',
    'app/Services/Payment/WithdrawalProviderAccountService.php',
    'app/Services/Payment/WithdrawalProviderReferenceService.php',
    'app/Console/Commands/Finance/WithdrawalReferenceAuditCommand.php',
    'tests/Feature/Payment/WithdrawalReferenceCrossLaneTest.php',
    'tests/Feature/Payment/WithdrawalProviderAccountTest.php',
    'tests/Feature/Payment/WithdrawalProviderReferenceTest.php',
    'tests/Feature/Payment/WithdrawalReferenceAuditTest.php',
    'tests/Feature/Payment/WithdrawalReferenceLegacyCompatibilityTest.php',
    'tests/Feature/Concurrency/WithdrawalReferenceServerRaceTest.php',
    'app/Services/Finance/WithdrawalCompletionService.php',
    'config/payment.php',
    'database/migrations/2026_10_15_000100_create_withdrawal_reconciliations_table.php',
    'app/Enums/WithdrawalReconciliationOutcome.php',
    'app/Models/WithdrawalReconciliation.php',
    'app/DTOs/Payment/WithdrawalReconciliationFacts.php',
    'app/Exceptions/WithdrawalReconciliationException.php',
    'app/Services/Payment/WithdrawalReconciliationAccess.php',
    'app/Services/Payment/WithdrawalReconciliationEvidenceStore.php',
    'app/Services/Payment/WithdrawalReconciliationService.php',
    'app/Http/Resources/WithdrawalReconciliationResource.php',
    'app/Http/Requests/Admin/StoreWithdrawalReconciliationRequest.php',
    'app/Http/Requests/Admin/DecideWithdrawalReconciliationRequest.php',
    'app/Http/Controllers/Admin/WithdrawalReconciliationController.php',
    'config/permission.php',
    'resources/views/admin/withdrawal-reconciliation.blade.php',
    'tests/Feature/Payment/WithdrawalReconciliationServiceTest.php',
    'tests/Feature/Payment/WithdrawalReconciliationHttpTest.php',
    'tests/Feature/Concurrency/WithdrawalReconciliationServerRaceTest.php',
    'app/DTOs/Payment/GatewayWithdrawalResponse.php',
    'app/Services/Payment/WithdrawalDisbursementService.php',
    'app/Services/Payment/Contracts/PaymentGatewayInterface.php',
    'app/Jobs/Payment/DisburseWithdrawalJob.php',
    'app/Console/Commands/Finance/WithdrawalDispatchStatusCommand.php',
    'tests/Feature/Payment/WithdrawalDispatchSafetyTest.php',
    'tests/Feature/Payment/WithdrawalDispatchStatusTest.php',
    'tests/Feature/Queue/ProductionQueueComprehensiveTest.php',
    'tests/Support/SyntheticWithdrawalGateway.php',
    'tests/Support/WithdrawalDispatchProbe.php',
    'tests/Feature/Concurrency/WithdrawalDispatchRecoveryTest.php',
    'tests/Feature/Concurrency/WithdrawalDispatchServerRaceTest.php',
    'scripts/run_withdrawal_dispatch_recovery.py',
    'scripts/test_run_withdrawal_dispatch_recovery.py',

    'tests/Unit/Payment/BkashGatewayTest.php',
    'tests/Unit/Payment/NagadGatewayTest.php',
    'tests/Unit/Payment/CryptoGatewayTest.php',
    'app/DTOs/Payment/WebhookMoneyFacts.php',
    'app/Services/Payment/PaymentWebhookBindingService.php',
    'app/Services/Payment/PaymentWebhookService.php',
    'app/Services/Payment/Drivers/StripeGateway.php',
    'app/Services/Payment/Drivers/BkashGateway.php',
    'app/Services/Payment/Drivers/NagadGateway.php',
    'app/Services/Payment/Drivers/CryptoGateway.php',
    'tests/Feature/Payment/WebhookObligationBindingTest.php',
    'tests/Feature/Payment/WebhookMoneyFactsTest.php',
    'tests/Feature/Payment/PaymentWebhookSignatureVerificationTest.php',
    'tests/Feature/Concurrency/WebhookObligationServerRaceTest.php',
    'scripts/test_run_financial_concurrency.py',
    'routes/web.php', 'routes/api.php',
    'app/Console/Commands/Lottery/SettleDrawChunkedCommand.php',
    'tests/Support/OwnsPrivateRedis.php',
    'tests/Support/RedisSettlementProbe.php',
    'tests/Feature/Concurrency/RedisSettlementRecoveryTest.php',
    'tests/Feature/Draw/SettlementQueueHandoffTest.php',
    'scripts/run_redis_settlement_recovery.py',
    'scripts/test_run_redis_settlement_recovery.py',
    'app/Jobs/Draw/Concerns/HasSettlementRecoveryWindow.php',
    'app/Jobs/Draw/ProcessPrizeSettlementJob.php',
    'app/Jobs/Draw/SettleDrawChunkJob.php',
    'app/Jobs/Draw/FinalizeDrawSettlementJob.php',
    'tests/Support/SettlementRecoveryProbe.php',
    'tests/Feature/Concurrency/SettlementWorkerRecoveryTest.php',
    'tests/Feature/Queue/SettlementRecoveryPolicyTest.php',
    'scripts/run_settlement_recovery.py',
    'scripts/test_run_settlement_recovery.py',
    'scripts/run_financial_concurrency.py',
    'app/Http/Controllers/Admin/LottoFinExecutiveDashboardController.php',
    'app/Http/Controllers/Admin/ReleaseOperationsController.php',
    'app/Console/Commands/GloImportResult.php',
    'app/Http/Controllers/Api/V1/DrawResultController.php',
    'app/Services/Lottery/GloResultImportService.php',
    'app/Services/Draw/DrawResultIngestionService.php',
    'app/Services/Draw/DrawResultConfirmationService.php',
    'app/Services/Draw/DrawResultPublicationService.php',
    'scripts/pages_251_350_audit_matrix.py',
    'security/weekly-result-integrity/Cargo.toml',
    'security/weekly-result-integrity/Cargo.lock',
    'security/weekly-result-integrity/src/main.rs',
    'security/weekly-result-integrity/src/lib.rs',
    'security/weekly-result-integrity/src/canonical.rs',
    'security/weekly-result-integrity/src/error.rs',
    'security/weekly-result-integrity/tests/strict_signature.rs',
    'security/weekly-result-integrity/tests/cli_boundary.rs',
    'app/Services/Lottery/WeeklyResultIntegrityService.php',
    'app/Services/Lottery/Support/AbstractLotteryImportService.php',
    'tests/Unit/Lottery/WeeklyIntegrityProtocolTest.php',
    'tests/Feature/WeeklyLottery/WeeklyNativeIntegrityBoundaryTest.php',
    'security/weekly-result-integrity/tests/integrity.rs',
)
OUTPUTS = ('audit.md', 'RUNTIME-VERIFICATION-REPORT.md',
           'FINANCIAL-INTEGRITY-REPORT.md', 'RUST-RUNTIME-REPORT.md',
           'evidence/release-source-inventory-part-13.json')


def safe_path(root: Path, relative: str) -> Path:
    path = root / relative
    if path.is_symlink() or not path.resolve().is_relative_to(root.resolve()):
        raise ValueError('Source/output must be inside the repository and not a symlink.')
    return path


def declared_recipes(text: str) -> dict[int, str]:
    """Extract titles only; never propagate asserted route/runtime/financial success."""
    result: dict[int, str] = {}
    for node in ast.walk(ast.parse(text)):
        if isinstance(node, ast.Assign) and any(isinstance(t, ast.Name) and t.id == 'pages' for t in node.targets):
            data = ast.literal_eval(node.value)
            for page, values in data.items():
                if not isinstance(page, int) or not 251 <= page <= 350 or not isinstance(values[0], str):
                    raise ValueError('Invalid declared page recipe.')
                result[page] = values[0]
        if isinstance(node, ast.Call) and isinstance(node.func, ast.Name) and node.func.id == 'runtime_row':
            if len(node.args) >= 2 and isinstance(node.args[0], ast.Constant) and isinstance(node.args[0].value, int):
                page, title = node.args[0].value, ast.literal_eval(node.args[1])
                if not 251 <= page <= 350 or not isinstance(title, str) or page in result:
                    raise ValueError('Invalid or duplicate declared page recipe.')
                result[page] = title
    if sorted(result) != list(range(251, 351)):
        raise ValueError('Expected the 100 declared recipe slots, not inferred runtime pages.')
    return result


def build_inventory(root: Path) -> dict:
    artifacts = []
    for relative in SOURCES:
        path = safe_path(root, relative)
        data = path.read_bytes() if path.is_file() else None
        artifacts.append({'path': relative, 'exists': data is not None,
                          'sha256': hashlib.sha256(data).hexdigest() if data is not None else None,
                          'bytes': len(data) if data is not None else None})
    recipe = safe_path(root, 'scripts/pages_251_350_audit_matrix.py')
    titles = declared_recipes(recipe.read_text()) if recipe.is_file() else {}
    pages = [{'page': page, 'declared_title': titles.get(page),
              'mapping_status': 'DECLARED_RECIPE_ONLY' if page in titles else 'NOT_CONFIGURED',
              'route_mapping_verified': False, 'runtime_verified': False,
              'financial_execution_verified': False}
             for page in range(77, 351)]
    return {'schema_version': 1, 'scope': 'STATIC_ONLY',
            'runtime_verified': False, 'production_verified': False,
            'live_payment_verified': False, 'official_source_verified': False,
            'page_mapping_verified': False, 'sources': artifacts, 'pages': pages,
            'note': 'Hashes prove source bytes only. Declared recipes are not deployed route/page mappings. Missing sources remain explicit.'}


def documents(inventory: dict) -> dict[str, str]:
    rows = ['# Release source audit — static inventory, not certification', '',
            'Scope: STATIC_ONLY. Runtime, production, live payments and official-source authenticity are NOT_VERIFIED.',
            'These page IDs preserve requested inventory slots, not a claim that all pages are implemented or dispatched.',
            'Pages 77–250 have no authoritative mapping established here: NOT_CONFIGURED. Pages 251–350 retain only titles statically extracted from the old recipe.',
            'The old recipe is not executed; its obsolete availability and financial/runtime success assertions are not adopted.', '',
            '## Source byte inventory', '', '| Source | Exists | SHA-256 |', '|---|---|---|']
    for source in inventory['sources']:
        rows.append(f"| `{source['path']}` | {source['exists']} | {source['sha256'] or 'MISSING'} |")
    rows += ['', '## Requested page-slot inventory', '',
             '| Page | Declared title | Mapping status | Runtime | Financial execution |', '|---|---|---|---|---|']
    for page in inventory['pages']:
        title = (page['declared_title'] or 'No authoritative page-to-route mapping').replace('|', '/')
        rows.append(f"| {page['page']} | {title} | {page['mapping_status']} | NOT_VERIFIED | NOT_VERIFIED |")
    rows += ['', '## Observed architecture and limits', '',
             'The dashboard has authorized KYC review and reconciliation delegates; it is not entirely read-only. Its result/import projections do not import or publish results.',
             'GloImportResult is the dedicated permission-checked CLI intake. DrawResultController delegates ingestion/confirmation/publication to the controlled domain workflow.',
             'Source presence and names are not evidence of successful route dispatch, permissions, money execution, official authenticity or browser behavior.',
             'No deployment, production migration, queue drain, merchant call or payout is executed by this inventory tool.', '']
    runtime = '\n'.join(['# RUNTIME verification report — evidence boundaries', '', 'Scope: STATIC_ONLY; runtime_verified=false; production_verified=false.',
                         'This inventory tool runs no PHP, Laravel, database, shell command, queue worker or browser. Availability is not inferred from source files.',
                         'Part70 adds a shared current write-PDO plus Laravel counter boundary for wallet row locks, low-level mutations, hold consumption, hold/admin-lock wrappers and canonical transaction execution. Divergence is refused before SQL, savepoints or callbacks without repairing counters or taking over raw caller transactions. Root/savepoint/direct-join ownership remains explicit. This is not original transaction identity attestation, arbitrary upstream-wrapper certification or a guarantee that direct low-level callers post a complete journal. Part71 adds non-owning shared transaction admission before reversal and proved-lookup routing SQL, then rechecks at their explicit transaction/savepoint boundary. New compensation, original-bound read-only replay and legitimate walletless journals retain accounting proof and caller ownership; routing loss cannot reopen a replacement SQLite transaction. This is not advisory eligibility authority, historical transaction-identity attestation or blanket upstream-wrapper adoption. Part72 extends the shared current physical boundary to deposit/withdrawal lifecycle locks and saves and guarded financial-transaction in-memory status preparation. The failure writer re-reads the current persisted financial row under its caller-owned lock instead of saving stale status, metadata or dirty money columns. Lost row/checkout-read boundaries fail closed. Six native two-child old-snapshot profiles cover the three actual aggregate tables. Preparation still does not save; advisory validation is separate. No generic Eloquent writer fence or original transaction identity certification is implied. Part73 fixes immutable entry ownership for financial retry, refuses divergent/unclean/changed-connection retry, requires canonical native driver codes and handles Laravel-wrapped query causes. Nested statement timeouts roll back only the owned savepoint; real 1213 aborts bypass savepoint SQL even when PDO reports a stale true flag. Two genuine two-child deadlock cycles and two simultaneous parent/two-child timeout profiles stage canonical independent THB/BDT journals and prove rollback, nonduplicate owned-root retry and preservation of committable caller work. Callback-owned complete SQL boundaries remain mandatory; there is no provider retry or historical transaction-identity attestation. Part74 rejects lossy wallet/user/financial aggregate identifiers before routing SQL: canonical positive integer IDs only, current raw model keys before Eloquent casts, full-set validation before multi-wallet locks, and guarded hold/reversal/lifecycle entry. This is identity fidelity, not authorization or historical transaction identity proof. Part75 binds integrated financial models to the current default writer Connection object and canonical table/id key before routing. Foreign/unknown aliases are refused without resolving them; direct wallet mutation, holds, lifecycle and reversal entry share this gate. Well-formed IDs do not attest store identity or authorization. Part76 extends current raw-key/table/key-name/writer-context admission to direct ledger posting and ledger lookup/balance-proof entry, and requires coherent managed write-PDO state before account writes. It does not attest caller authorization or arbitrary writers. Part77 serializes direct posting at the canonical financial-transaction root before ordered account locks, uses locked persisted defaults/status and own pending-row visibility plus a fresh nonpersistent same-physical-writer autocommit observation to reject duplicate disjoint-account postings despite an old RR snapshot without cross-transaction absent-journal gap locks. It does not authorize arbitrary drafts or new writers. Part78 admits cleanup ownership only for a distinct, unregistered, unsplit idle PDO; borrowed resources remain untouched and actual observer autocommit is checked without repair. Latest separate acceptance: docs/remaining-work-part-78.md and evidence/runtime-part-78.txt. Part67 adds current owner-serialized open-quote slot admission using at most the effective ceiling of locked current IDs rather than an old RR COUNT, physical transaction checks before decisive quote lookup/consumption and a shared stored-second issue/expiry cutoff. Four real native two-child old-count profiles cover parent/child filling, current consumption freeing a slot, and remaining-capacity refusal without resetting rolling allowance or changing original replay. Advisory counts are not admission authority. No money reservation, FX approval, total-query-work bound or arbitrary-writer certification is implied. Part66 adds owner-serialized transaction-held current reads of eligible rolling allowance, exact per-row BCMath before model rounding, shared inclusive second-resolution 24-hour/30-day cutoffs, and fail-closed malformed eligible amounts. Reversed originals count once; inverse children do not. Four native two-child old-empty-snapshot profiles post real parent originals and cover daily/monthly/reversed refusal plus observational original replay. This is not full historical accounting certification, bounded total scan work, arbitrary-writer or other-isolation proof. Part65 adds current transaction-held conversion reversal authority, sorted owner/operator principal locks and uncached web-role/direct-or-inherited permission graph proof before both new reversal and reversal replay. Existing super-admin/auditor policy semantics remain, except wrong-guard role names cannot bypass. Old member replay remains observational; this is not global staff-session or all-writer revocation certification. Part64 proves existing original conversion accounting before settled replay and separately authorized reversal, uses current locked reads under the owner mutex, and rejects hidden/extra/unposted journal legs and unreciprocated reversal children. Explicit NULL system-fee wallet/snapshot semantics are preserved by the posting service. No historical fee-leg repair or arbitrary-writer cryptographic guarantee is claimed. Part63 adds mandatory known-refund-source admission for NEW same-currency Cash/Win conversion authority inside its existing owner-mutex root. Exact settled replay precedes that admission; quotes and independently authorized original reversals are separate. Pending or unproved original/receipt/financial closure blocks new money movement and quote consumption without provider I/O. Four native opposite-source-wallet profiles include two old empty RR snapshots. This is not FX, cross-currency debt clearance, a generic debit freeze or global unknown-source certification. Part62 gives fresh member-API initial holds non-expiring encrypted-origin ownership, a terms-bound source marker, additive current-read wallet floors and an atomic approval handoff into one linked canonical reservation without another hold. Pending/approved cancellation, rejection and allowed failure close the same original; canonical settlement closes both histories with one debit. Replay never enrolls anonymous history. New approval still rechecks refund exposure while existing ownership can settle or release. No source expiry, historical adoption, provider invocation, live transfer or global arbitrary-writer certificate is implied. Part61 adds source-backed NEW withdrawal request and NEW owned reservation admission under selected-wallet/player serialization. Exact existing request replay and already-reserved settlement/release are separate; there is no generic debit veto, provider call, automatic collection or unproved expiry-based clearance. The common recovery source retains all original proof and bounded current-read semantics. Part60 adds a mandatory canonical NEW paid-wager guard for known pending authenticated Stripe full-refund recovery, serialized at the shared player mutex across same-currency wallets. A bounded current-read scan and exact encrypted original/receipt/financial closure proof prevent stale contexts, disabled review/recovery flags, expiry or completion labels from granting new wager authority. Safe member API422 and definitive declined amendment replacement are integrated; historical funded replay and cancellation remain separate. No provider call or financial recovery is performed by wager admission; this is not a global withdrawal/FX/risk fence or unknown-event absence proof. Part59 adds separately authorized, default-off confirmed zero-fee full Stripe refund recovery: original authenticated capture and signed succeeded refund, bounded fixed-endpoint GET proof outside SQL, immutable independent provider receipts, one original-wallet debit with cash/liability compensation, spendable/hold protection, exact proved financial replay and managed generic-reversal refusal. It does not create a provider refund request or support partial refunds, fees, disputes, negative-balance collection, risk clearance or worldwide live approval. Part58 adds an independently opt-in scheduled signed-inbox routing sweep with explicit current staff actor, protected operational work/event custody, committed expiring SQL leases, bounded oldest-due retries, stale-owner acknowledgement fencing and audited held-item retry. Routing is at least once and remains observational; lease expiry cannot undo an already committed route. No provider call, refund, credit, withdrawal release or financial clearance is authorized. Part57 adds a default-off current-account/configuration global staff inbox, bounded independently committed explicit routing batches, candidate ambiguity/staleness refusal, fresh original wallet/capture/credit proof, encrypted append-only routing decisions and read-only history. Unmatched remains investigable, not cleared. There is no automatic worker, high-water cursor, refund, reversal or money change. Part56 adds a dedicated raw-body signed Stripe refund/dispute inbox and separately authorized, encrypted, append-only operator review with version-bound immutable acknowledgements. It never fabricates Applied webhooks or executes a refund, wallet reversal, risk clearance or withdrawal block. Bounded complete history, original applied-capture proof and stale-screen refusal remain mandatory; new observed evidence opens a new review version without erasing older notes. These cases are not automatic live monitoring or a global ingress/writer fence. Part55 adds separately authorized original Stripe captured-funding recovery: authenticated session, PaymentIntent, Charge and refund-list GETs, exact original money/mode binding, permanent per-lane intent/charge ownership, independent encrypted receipts, fresh current-admission canonical net credit and read-only proved replay. Incomplete/partial/refunded/disputed evidence never grants credit or failure release. Pending evidence older than five minutes requires fresh GETs; historical applied receipts are not live refund monitoring or bank payout settlement. Part54 adds explicit current staff duty and an opt-in, positive-only bounded Stripe GET lookup, full original metadata/money/account binding, encrypted lookup receipts and SQL-only replay. Inconclusive or truncated searches never release or resend; complete/expired session labels never authorize credit or refunds. Current original-account configuration remains pinned and staff is rechecked before receipt persistence. Part53 adds durable committed checkout intents and dispatch fences, at most one automatic adapter invocation per original, independent encrypted successful-response custody, SQL-only recovery and original request/account/configuration binding. Unknown or merely dispatching outcomes never expire or auto-resend, even when no provider call can be proved; authoritative provider lookup remains separate work. Caller transactions are refused, Stripe receives a server-owned idempotency header and the owner-only status is read-only. This is not provider settlement, refund support, worldwide approval or merchant certification. Part52 adds mandatory current player and selected-currency gross deposit admission inside fresh request/credit roots; shared player mutex across distinct wallets, current locked hidden/source reservation and confirmation-time evidence, exact row-string arithmetic and own-reservation replacement. New credit rechecks old approvals/callbacks, historical proven replay does not grant new credit, expected policy refusals retain verified receipts for explicit retry, and proven original checkout replay makes no second provider request. Part52 alone did not provide durable provider dispatch; Part53 supplies the bounded durable orchestration described above, not provider lookup, refunds or universal all-writer/legal approval. Part51 adds fresh source-owned responsible-gaming limit requests with immutable payload/terms/basis and epoch/timezone facts, optional player-scoped request identity with changed-payload refusal, current safer choices that cancel pending increases, proven no-write historical request replay, guarded cooling-off/expiry sweeps and an atomic configured-currency summary bridge that never swallows a binding failure or labels a pending increase as active. Ownerless pending/expiry history is held without backfill; current-state setters are not durable client-request replay. Expected policy refusals are safe HTTP422/web errors. This is not universal legacy currency enrollment, all-writer/actor permission or live approval. Part50 adds current player/wallet ownership and responsible-gaming admission before every NEW paid wager, including prevalidated contexts and amendment replacements. Supported exclusion/versioned-limit writers share a player mutex with new wagers across distinct wallets; locked primary-server policy and exact all-wallet selected-currency ledger evidence prevent stale-snapshot and concurrent budget bypass. Daily wagering is gross stake, not net loss; currency-specific version chains are independent. Historical original funding replay remains separate. This is not universal actor/KYC/country/legal authorization or all-writer/global lock-order certification. Part49 adds mandatory current draw-first admission before every supported new risk reservation, including ordinary paid purchases, direct/batch engine and facade paths; closed/expired/settled/cancellation histories cannot be bypassed by a preview switch or old snapshot. Nested savepoints make single and batch risk reserve/release operations atomic even when their caller catches a later failure and commits unrelated work. Releases retain original ownership floors and remain possible after sales close. This is not durable bulk-slip ownership or worldwide authority. Part48 adds bounded atomic draw-wide cancellation with current explicit staff authority, original wallet-first membership revalidation, source-proven member refunds and existing policy-bound paid/unpaid agent closure before lifecycle cancellation; durable terminal replay re-proves every original and never reconstructs dispatch. Any legacy/history/settlement/capacity or accounting ambiguity refuses the entire draw without partial refunds. The existing admin action now uses that coordinator; generic lifecycle cancellation refuses any bet history without its live private dispatch. No historical adoption, large-volume certification or provider authorization is implied. Part47 adds fresh sealed original paid-purchase receipts, current wallet-first original-debit/journal/ticket proof for all ordinary purchase replay paths, strict decimal replay matching and ambient-root refusal; historical receipts are not enrolled. Part46 adds durable source-owned refund-and-replace amendments, fresh in-memory purchase capabilities, atomic funded-replacement receipts, owner/payload-bound proven replay and no resend or expiry after unknown outcomes; it adopts no history. Part45 coordinates policy-captured NEW original-specific agent compensation with the member refund, exact earned/paid counter closure, shared wallet floors and proved read-only terminal replay; mixed paid/unpaid direct/parent sources close together. Generic original/compensation reversals and commission-only reversal of owned sources are refused. No historical policy enrollment, new operator/payment ingress or live authorization exists. Earlier Part44 unifies internal draw/manual agent payouts behind current source/member/wallet/counter proof, permanent new original-credit receipts and canonical per-commission keys; proves paid replay, preserves other receivables and suspended earnings, repairs facade call/list contracts and refuses unbound historical disbursements or mixed-currency batch totals. At Part44, paid member compensation was not implemented; the scoped Part45 coordinator supersedes that boundary. Legacy/unbound writer, global actor integration and deployment remain separate. Part43 binds newly funded commission membership and exact terms, coordinates UNPAID direct/parent receivable cancellation with the member refund atomically, protects other owned/unowned book minima, uses current locked accrual arithmetic and refuses paid/historical compensation without adopting history. At Part43, paid compensation and other unintegrated writers remained distinct work. Part42 binds newly paid purchases to durable original-wallet, exact canonical-journal and risk ownership; locks and revalidates cancellation, protects shared risk minima, proves terminal refund replay, and refuses unowned history or uncoordinated commission refunds. Part41 gives local financial holds durable non-expiring ownership, aggregate current-read floors added to withdrawal ownership, wallet-first savepoint-atomic close/debit and verified terminal replay; legacy ownerless holds fail closed without backfill. Part40 removes the unpatched legacy npm compiler dependency chain, preserves measured legacy design semantics through a reviewed Tailwind4 migration, pins the Node/npm build policy, adds full-graph fail-closed audit gates, and patches the locked Symfony YAML parser without a major upgrade. Registry audit results are time-specific, not complete security or financial approval. Part39 validates exact original accounting, binds canonical reversal/replay to one original, preserves wallet-first locking, refuses unconfigured external refunds and prevents silent paid-commission bookkeeping without a proved compensation. Provider-specific refund policy remains disabled. Part38 enforces a current-read withdrawal floor at the shared wallet mutation mechanics, rejects generic release/spend or deficit camouflage, and atomically closes owned holds with canonical debit/proof. A dedicated stale-snapshot SQL race and expected-failure mutation control distinguish current-read proof from engine snapshot-abort protection. Part37 adds non-expiring withdrawal reservation ownership, original canonical debit/ledger proof, read-only terminal replay, wallet-first original Payment callbacks and evidence-gated failure release. Historical records are not backfilled. Parts35–36 add original inbound reference/credit proof and exact row-wise reconciliation. This passive inventory executes and certifies none of those runs.',
                         'Part34 adds strict original player-claim identity, current explicit independent staff review, safe terminal replay, locked annotation-only expiry/disposal, currency-grouped reporting and a balanced-canonical-completion-only Paid bridge. Managed financial obligations remain non-expiring; historical missing snapshots are held rather than manufactured. Generic paper-possession assertions are not falsely accepted as verified. Part33 adds encrypted private payout evidence archival, separate audited inert attachment access, independent uploader/reviewer separation, recent-read and immutable proof consumption before canonical finalization, terminal projection checks and non-destructive retention audits. Part32 integrates a common immutable outbound reference owner across covered prize and withdrawal finalization lanes, read-only historical cross-domain refusal scans, redacted cursor audits and savepoint-atomic local/common bindings. Part31 adds private SQL-derived maker/checker intake, immutable SQL batch membership, atomic same-connection database queue routing, currency-specific canonical wallet execution, derived terminal projections and legacy-journal isolation. Part30 hardens external-prize approval/transfer identity, active granular authority, immutable destination facts, prize reference ownership and independent final-result review with balanced external accounting and no wallet credit or provider invocation. Part29 shared withdrawal ownership remains. Private evidence, maker/checker and durable non-expiring claims remain. Evidence integrity does not prove provider authenticity; synthetic recovery is not a real transfer or multi-host certification.',
                         'Source inventory/page mapping is NOT_VERIFIED beyond static bytes; missing mappings are NOT_CONFIGURED.', ''])
    finance = '\n'.join(['# Financial integrity report — not live execution approval', '', 'Scope: STATIC_ONLY; financial_execution_verified=false; live_payment_verified=false.',
                         'Hashes and page-slot coverage do not prove balances, rollback, reconciliation, settlement finality or bank delivery.',
                         'See evidence/runtime-part-77.txt for current separately executed acceptance and its explicit limits; earlier reports remain historical. External-prize final confirmation posts through LedgerPostingService, debiting prize expense and crediting system cash without a wallet mutation. Common outbound ownership now covers the integrated prize/withdrawal lanes; deposit/provider-authenticated evidence and other unintegrated writers are not thereby certified. Broader Payment uniqueness remains conservative. Human attestation is not automatically authenticated provider proof. Overlapping matrices are not unique-coverage totals.',
                         'Legacy member transfer stays fail-closed. The alternate GLO direct wallet writer remains disabled.',
                         'Canonical proof, maker/checker and provenance controls remain authoritative. Approved/Paid labels do not prove external payment execution.',
                         'No credentials or customer datasets are read by this inventory tool; no funds are moved.', ''])
    rust = '\n'.join(['# Rust runtime report — Cargo not executed by this inventory', '', 'Scope: STATIC_ONLY; rust_runtime_verified=false.',
                      'Cargo.toml, Cargo.lock, all verifier modules, PHP boundary/import code and synthetic tests are hashed as source artifacts only.',
                      'The verifier is in security/weekly-result-integrity; the repository root is not assumed to be a Cargo workspace.',
                      'This inventory performs no Cargo command or binary execution. Separately executed local Cargo locked tests/release build status and its release scope are recorded in evidence/runtime-part-77.txt; earlier verifier protocol evidence remains in evidence/native-verifier-part-23.json. Scope and rollout boundaries are in docs/remaining-work-part-77.md.',
                      'The native executable is not packaged and must be rebuilt from its actual manifest. Synthetic signature agreement is not official-provider authentication or live-payment authorization.', ''])
    return {'audit.md': '\n'.join(rows), 'RUNTIME-VERIFICATION-REPORT.md': runtime,
            'FINANCIAL-INTEGRITY-REPORT.md': finance, 'RUST-RUNTIME-REPORT.md': rust,
            'evidence/release-source-inventory-part-13.json': json.dumps(inventory, indent=2) + '\n'}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--write', action='store_true', help='Explicitly write the five allow-listed inventory documents.')
    mode.add_argument('--check', action='store_true', help='Read-only check that generated inventory documents match current source bytes.')
    args = parser.parse_args()
    inventory = build_inventory(ROOT)
    if args.write:
        prepared = [(safe_path(ROOT, name), content) for name, content in documents(inventory).items()]
        for path, content in prepared:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content)
    stale = []
    if args.check:
        for name, expected in documents(inventory).items():
            path = safe_path(ROOT, name)
            if not path.is_file() or path.read_text() != expected:
                stale.append(name)
    print(json.dumps({'scope': inventory['scope'], 'page_slots': len(inventory['pages']),
                      'missing_sources': [s['path'] for s in inventory['sources'] if not s['exists']],
                      'documents_written': bool(args.write), 'stale_documents': stale, 'runtime_verified': False,
                      'production_verified': False, 'live_payment_verified': False}, indent=2))
    return 0 if not stale and all(s['exists'] for s in inventory['sources']) else 1


if __name__ == '__main__':
    raise SystemExit(main())
