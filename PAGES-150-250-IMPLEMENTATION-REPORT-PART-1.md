# Pages 150–250 implementation report — Part 1

Complete first-phase report for the Pages 150–250 implementation.

Covered pages: 150–250 static implementation and evidence matrix. Existing canonical finance, lottery, health, and Rust boundaries are referenced rather than duplicated.

Runtime status: `NOT VERIFIED — RUNTIME UNAVAILABLE`.

Every listed file is reproduced in full from its first line to its last line. No file content is omitted or shortened.

## FILE 1: `PAGES-150-250-ARCHITECTURE-INVENTORY.md`

# TYPE: Markdown architecture inventory
# PURPOSE: Required pre-work inventory of the repository paths relevant to Pages 150–250.

```text
# Pages 150–250 architecture inventory

This is the required pre-work inventory for the Pages 150–250 phase. It lists every file found under `app`, `bootstrap`, `config`, `database`, `resources`, `routes`, and `tests` to depth four at inventory time. No implementation file was shortened in this inventory.

## Annotation format

Every path uses:

`path # TYPE: file type | ROLE: architectural role | DOMAIN: domain names | USED BY: consuming routes, services, jobs, tests, or UI`

## Annotated implementation tree

`app/Console/Commands/ContactDeliveryHealth.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/Finance/ReconcileFinancialRecordsCommand.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands; Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/GloExpireFreezes.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/GloFreezeCase.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/GloFreezeTicket.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/GloImportResult.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/GloNotifySavedTickets.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/GloProcessFrozenWinners.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/GloProviderHealth.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/GloPublicResultPublishCommand.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/GloReconcileSales.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/GloStressHarness.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/GloSyncSalesPoints.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/ImportBingoLotteryResults.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/ImportLotteryResults.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/ImportNationalLotteryResults.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/ImportPcsoLotteryResults.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/ImportWeeklyLotteryResults.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/Lottery/CloseDrawsCommand.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands; Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/Lottery/LotteryAutomationCommand.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands; Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/Lottery/MarkResultsPendingCommand.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands; Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/Lottery/OpenDrawsCommand.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands; Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/Lottery/PublishDrawResultCommand.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands; Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/Lottery/ScheduleDrawsCommand.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands; Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/Lottery/SettleDrawsCommand.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands; Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/Lottery/TickCommand.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands; Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/Observability/EvaluateAlertsCommand.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands; Observability | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/Observability/MetricsExportCommand.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands; Observability | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/PurgeContactMessages.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/Queue/QueueHealthCommand.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands; Queue | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Console/Commands/RebuildAccountGradeSnapshots.php` # TYPE: PHP source | ROLE: Console | DOMAIN: Commands | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Contracts/Lottery/ProvidesCurrentResult.php` # TYPE: PHP source | ROLE: Contracts | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Contracts/Lottery/ProvidesResultProvenance.php` # TYPE: PHP source | ROLE: Contracts | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Account/GradeDiscountEntitlement.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Account | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Account/GradeEvaluationResult.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Account | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Account/GradeTier.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Account | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Agent/AgentOnboardingData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Agent | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Agent/AgentReportData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Agent | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Agent/CommissionCalculationResult.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Agent | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Agent/CommissionSettlementResult.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Agent | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/BetCalculationResult.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: DTOs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/BetPurchaseContext.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: DTOs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/BetPurchaseData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: DTOs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/BetPurchaseResult.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: DTOs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/BetSelectionData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: DTOs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/BetSlipPurchaseResult.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: DTOs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/BetValidationResult.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: DTOs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Betting/BetAmendmentData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Betting/BetAmendmentResult.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Betting/BetCancellationData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Betting/BetCancellationResult.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Betting/BulkBetCalculationData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Betting/BulkBetSelectionData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Betting/PermutationRequestData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Betting/PermutationResultData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Betting/TicketShareData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Betting/TicketShareResult.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Betting/TicketVerificationData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Betting/TicketVerificationResult.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Compliance/AmlRiskAssessmentData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Compliance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Compliance/ComplianceActionData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Compliance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Compliance/ComplianceCaseData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Compliance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Compliance/KycDocumentData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Compliance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Compliance/KycVerificationData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Compliance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Draw/DrawCertificationData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Draw | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Draw/DrawPublicationData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Draw | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Draw/DrawReconciliationData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Draw | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Draw/DrawResultConfirmationData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Draw | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Draw/ResultVerificationData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Draw | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/DrawRefundResult.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: DTOs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/DrawResultData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: DTOs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Finance/FeeCalculationResult.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Finance/FinancialHoldData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Finance/FinancialReconciliationReport.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Finance/LedgerAdjustmentData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Finance/LedgerReconciliationData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Finance/PayoutApprovalData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Finance/PayoutBatchData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Finance/PayoutRequestData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Finance/ReconciliationDiscrepancy.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Finance/TaxCalculationData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Finance/WalletReservationData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Lottery/DiscountMatrix.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Lottery/DiscountRule.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/MarketMatchResult.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: DTOs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/MarketRuleData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: DTOs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Notification/NotificationDeliveryData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Notification | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Notification/NotificationMessageData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Notification | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Notification/NotificationPreferenceData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Notification | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Notification/NotificationReceiptData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Notification | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Notification/NotificationTemplateData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Notification | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Operations/AdminOperationData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Operations | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Operations/OperationalReportData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Operations | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Operations/ProviderHealthData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Operations | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Operations/ProviderOperationData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Operations | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Operations/ReportExportData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Operations | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Payment/GatewayDepositResponse.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Payment/GatewayWithdrawalResponse.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Payment/PaymentCallbackData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Payment/PaymentIntentData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Payment/PaymentMethodData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Payment/PaymentProcessingResult.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Payment/PaymentProviderData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Payment/PaymentReconciliationData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Payment/PaymentWebhookData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Payment/PayoutTransferData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Payment/WebhookPayload.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Payout/PayoutStatementData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Payout | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Prize/PrizeClaimData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Prize | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Prize/PrizeClaimResultData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Prize | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Prize/PrizeDisbursementData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Prize | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Prize/PrizeEligibilityData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Prize | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Prize/PrizeMatchData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Prize | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Prize/UnclaimedPrizeData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Prize | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Prize/WinnerNotificationData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Prize | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Queue/QueueHealthReport.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Queue | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/ResponsibleGaming/PlayerProtectionActionData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: ResponsibleGaming | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/ResponsibleGaming/PlayerProtectionCaseData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: ResponsibleGaming | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/ResponsibleGaming/RealityCheckData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: ResponsibleGaming | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/ResponsibleGaming/ResponsibleGamingLimitData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: ResponsibleGaming | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/ResponsibleGaming/SelfExclusionData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: ResponsibleGaming | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Retail/RetailVendorData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Retail | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Retail/TicketAllocationData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Retail | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Retail/TicketInventoryData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Retail | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Security/AuthenticationAttemptData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Security | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Security/DeviceTrustData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Security | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Security/MfaChallengeData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Security | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Security/SecurityEventData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Security | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Security/UserSessionData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Security | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/SettlementResult.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: DTOs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/SettlementSelectionResult.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: DTOs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/SettlementSimulationResult.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: DTOs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Ticket/TicketOwnershipData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Ticket | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Ticket/TicketProductData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Ticket | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Ticket/TicketQrVerificationData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Ticket | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/TodPermutationResult.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: DTOs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/DTOs/Withdrawal/WithdrawalRequestData.php` # TYPE: PHP source | ROLE: DTOs | DOMAIN: Withdrawal | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/AccountGradeLevel.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/AccountVerificationStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/AdminOperationStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/AdminOperationType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/AgentStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/AmlRiskLevel.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/AuditAction.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/AuthLoginIdentifier.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/AuthenticationMethod.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/BetAcceptance.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/BetAmendmentStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/BetCancellationReason.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/BetMarket.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/BetPurchaseStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/BetSelectionType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/BetSide.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/BetStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/BetType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/BetValidationCode.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/ClaimWindowStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/CommissionStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/ComplianceActionType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/ComplianceCaseStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/Currency.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/DepositStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/DeviceTrustStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/DiscountGame.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/DiscountLottery.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/DiscrepancyCategory.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/DiscrepancySeverity.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/DrawCertificationStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/DrawConfirmationStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/DrawLifecycleState.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/DrawPublicationStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/DrawReconciliationStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/DrawResultStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/DrawStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/DrawType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/ExposureType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/FinancialHoldStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/FinancialReferenceType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/FinancialTransactionType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/GatewayIntegrationStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/GloClaimChannel.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/GloClaimStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/GloDealerRequestStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/GloDealerRequestType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/GloDealerStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/GloFreezeStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/GloPaymentHoldStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/GloPrizeTier.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/GloPublicStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/GloSavedTicketStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/GloSourceState.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/KycDocumentType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/KycDocumentVerificationStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/KycStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/KycVerificationStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/LedgerAccountStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/LedgerAccountType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/LedgerEntryPurpose.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/LedgerEntryType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/LedgerReconciliationStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/LimitStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/MarketResultType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/MfaChallengeStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/NotificationChannel.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/NotificationEventType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/NotificationFailureReason.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/NotificationPriority.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/NotificationStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/NotificationTemplateStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/NumberLimitStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/PaymentDirection.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/PaymentFailureReason.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/PaymentMethod.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/PaymentMethodStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/PaymentProviderStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/PaymentStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/PaymentTransactionStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/PaymentWebhookStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/PayoutApprovalStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/PayoutBatchStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/PayoutDocumentStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/PayoutStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/PlayerProtectionAction.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/PlayerProtectionCaseStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/PrizeClaimStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/PrizeDisbursementStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/PrizeEligibilityStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/PrizeMatchStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/PrizePayoutMethod.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/ProviderOperationStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/QueueName.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/RealityCheckStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/ReconciliationStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/ReportFormat.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/ReportJobStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/ReportType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/ResponsibleGamingLimitStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/ResponsibleGamingLimitType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/ResultSourceType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/ResultVersionState.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/RetailVendorStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/RiskAlertType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/RiskDecision.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/RiskLevel.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/SecurityEventType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/SecurityRiskLevel.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/SelfExclusionStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/SettlementSimulationStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/TaxCalculationStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/TaxDocumentStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/TicketAllocationStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/TicketInventoryStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/TicketOwnershipStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/TicketProductStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/TicketShareStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/TicketStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/TicketVerificationStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/TransactionStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/TransactionType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/UnclaimedPrizeStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/UserRole.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/UserSessionStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/UserStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/VerificationDocumentType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/WalletHoldType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/WalletReservationStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/WalletStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/WalletType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/WebhookEventType.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/WinnerNotificationStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/WithdrawalApprovalStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Enums/WithdrawalStatus.php` # TYPE: PHP source | ROLE: Enums | DOMAIN: Enums | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/AdminOperationCompleted.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/BetPlaced.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/ComplianceCaseEscalated.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/DepositStatusUpdated.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/DrawResultCertified.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/DrawResultPublished.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/DrawStatusUpdated.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/FinancialLedgerReconciled.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/MfaChallengeVerified.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/NotificationDelivered.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/NotificationDeliveryFailed.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/PaymentTransactionReconciled.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/PayoutBatchCompleted.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/PlayerProtectionActionApplied.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/PlayerProtectionCaseEscalated.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/PrizeClaimApproved.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/PrizeMatched.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/PrizePayoutCompleted.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/ProviderOperationalStateChanged.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/RetailTicketAllocated.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/SelfExclusionActivated.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/SuspiciousAuthenticationDetected.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/WalletBalanceUpdated.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/WithdrawalKycApproved.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Events/WithdrawalStatusUpdated.php` # TYPE: PHP source | ROLE: Events | DOMAIN: Events | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/AdminOperationException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/AmlRiskAssessmentException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/AuthenticationSecurityException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/BetAlreadySettledException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/BetAmendmentException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/BetCancellationException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/BetCancellationWindowExpiredException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/BetDomainException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/BetPurchaseConcurrencyException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/BetPurchaseException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/BetPurchaseIdempotencyException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/BetPurchaseValidationException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/ClaimWindowExpiredException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/ComplianceActionException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/ComplianceCaseException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/DepositException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/DeviceTrustException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/DrawCertificationException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/DrawLifecycleException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/DrawPublicationException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/DrawReconciliationException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/DrawResultException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/DrawResultValidationException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/DuplicatePrizeClaimException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/FinancialException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/FinancialHoldException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/GloClaimException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/GloDealerException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/GloFreezeException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/GloSalesException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/Handler.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/HotNumberException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/IdempotencyConflictException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/InsufficientBalanceException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/InvalidBetAmendmentException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/InvalidBetAmountException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/InvalidFinancialStateTransitionException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/InvalidLotteryNumberException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/InvalidMarketRuleException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/KycVerificationException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/LedgerAdjustmentException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/LedgerReconciliationException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/MarketResultUnavailableException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/MarketRuleException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/MfaChallengeException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/NotificationDeliveryException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/NotificationException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/NotificationPreferenceException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/NotificationTemplateException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/NumberLimitExceededException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/OperationalReportException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/PaymentIntentException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/PaymentProviderException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/PaymentReconciliationException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/PaymentVerificationException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/PaymentWebhookException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/PayoutApprovalException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/PayoutBatchException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/PayoutDocumentException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/PayoutException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/PayoutTransferException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/PlayerProtectionActionException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/PlayerProtectionCaseException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/PrizeClaimException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/PrizeDisbursementException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/PrizeEligibilityException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/PrizeMatchException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/ProviderOperationException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/RealityCheckException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/ReportExportException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/ResponsibleGamingLimitException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/RiskConfigurationException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/RiskException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/SelfExclusionException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/SettlementSimulationException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/TaxCalculationException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/TicketAllocationException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/TicketInventoryException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/TicketOwnershipException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/TicketQrVerificationException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/TicketShareException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/TicketVerificationException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/UnsupportedBetMarketException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/UserSessionException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/WalletReservationException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/WinnerNotificationException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/WithdrawalException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Exceptions/WithdrawalKycException.php` # TYPE: PHP source | ROLE: Exceptions | DOMAIN: Exceptions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Pages/FinancialReconciliationPage.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Pages | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Pages/GloDealerRequestsPage.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Pages | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Pages/GloPrizeClaimPage.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Pages | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Pages/GloPrizeStructurePage.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Pages | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Pages/GloResultDashboardPage.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Pages | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Pages/GloSalesPointsPage.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Pages | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Pages/GloSavedTicketNotificationsPage.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Pages | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Pages/GloTicketFreezePage.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Pages | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Pages/KycReviewPage.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Pages | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Pages/OperationsDashboard.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Pages | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Resources/AgentResource.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Resources/AuditLogResource.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Resources/BetResource.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Resources/DepositResource.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Resources/DepositResource/DepositDecisionActions.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Resources; DepositResource | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Resources/DrawResource.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Resources/DrawResource/DrawLifecycleActions.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Resources; DrawResource | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Resources/FinancialTransactionResource.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Resources/LedgerAccountResource.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Resources/NumberLimitResource.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Resources/TicketResource.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Resources/UserResource.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Resources/UserResource/UserAccountActions.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Resources; UserResource | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Resources/WalletResource.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Resources/WalletResource/WalletControlActions.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Resources; WalletResource | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Resources/WithdrawalResource.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Resources/WithdrawalResource/WithdrawalDecisionActions.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Resources; WithdrawalResource | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Widgets/DrawPipelineWidget.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Widgets | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Widgets/LedgerBalanceWidget.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Widgets | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Widgets/PendingApprovalsWidget.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Widgets | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Filament/Widgets/PlatformStatsWidget.php` # TYPE: PHP source | ROLE: Filament | DOMAIN: Widgets | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/AccountGradeController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/Admin/AdminController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers; Admin | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/Admin/LottoFinExecutiveDashboardController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers; Admin | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/Agent/AgentPortalController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers; Agent | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/Auth/MemberAuthController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers; Auth | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/Betting/ThaiLotteryBettingController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers; Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/BingoLotteryController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/ContactController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/Controller.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/GloL6Controller.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/GloResultsPageController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/HealthController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/HomeController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/LegacyRedirectController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/LotteryHubController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/LotteryPurchasePageController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/LottoDiscountController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/MetricsController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/NationalLotteryController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/NotificationCenterController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/Payment/DepositMethodsPageController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers; Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/Payment/WithdrawalMethodsPageController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers; Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/PcsoLotteryController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/Player/LotteryHistoryPortalController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers; Player | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/Player/PlayerDashboardController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers; Player | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/Player/PlayerProfilePortalController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers; Player | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/Player/PlayerSecuritySettingsController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers; Player | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/Player/PlayerSettingsPortalController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers; Player | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/PrizeVerificationController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/PublicAccountInfoController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/PublicContactController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/PublicDownloadAppController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/PublicFaqController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/PublicGradeController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/PublicHowToPlayController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/PublicLegalFeesController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/PublicLegalPrivacyController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/PublicLegalTermsController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/PublicLottoDiscountController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/PublicPagesController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/PublicPrizeVerificationController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/PublicServicePagesController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/PublicVerificationController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/ResultsController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/SitemapController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/Support/SupportPortalController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers; Support | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/Verification/AccountVerificationController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers; Verification | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/Wallet/WalletManagementPageController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers; Wallet | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/Web/AuthController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers; Web | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/Web/BetPurchaseController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers; Web | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/Web/MemberAuthController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers; Web | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/Web/PaymentCallbackController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers; Web | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/Web/PlayerWebController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers; Web | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Controllers/WeeklyLotteryController.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Controllers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Middleware/Authenticate.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Middleware | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Middleware/CorrelationIdMiddleware.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Middleware | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Middleware/EnsureDrawIsOpen.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Middleware | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Middleware/EnsureUserIsActive.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Middleware | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Middleware/EnsureWalletIsActive.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Middleware | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Middleware/GloEnsurePermission.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Middleware | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Middleware/PublicLegalHeaders.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Middleware | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Middleware/SecurityHeaders.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Middleware | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Middleware/SetLocale.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Middleware | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Middleware/ThrottleRequests.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Middleware | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Middleware/TrustProxies.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Middleware | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Middleware/VerifyCsrfToken.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Middleware | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Middleware/VerifyWebhookSignature.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Middleware | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Requests/Auth/ForgotPasswordRequest.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Requests; Auth | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Requests/Auth/LoginRequest.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Requests; Auth | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Requests/Auth/RegisterMemberRequest.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Requests; Auth | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Requests/ContactMessageRequest.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Requests | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Requests/Draw/ConfirmDrawResultRequest.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Requests; Draw | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Requests/Draw/IngestDrawResultRequest.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Requests; Draw | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Requests/Payout/CancelPayoutRequest.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Requests; Payout | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Requests/Payout/ShowPayoutRequest.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Requests; Payout | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Requests/Prize/CreatePrizeClaimRequest.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Requests; Prize | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Requests/Verification/SubmitAccountVerificationRequest.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Requests; Verification | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Requests/Web/AccountVerificationRequest.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Requests; Web | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Requests/Web/BetPurchaseRequest.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Requests; Web | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Requests/Web/ContactRequest.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Requests; Web | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Requests/Web/DepositRequest.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Requests; Web | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Requests/Web/RegisterRequest.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Requests; Web | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Requests/Web/UpdateResponsibleGamingLimitsRequest.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Requests; Web | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Requests/Web/WithdrawRequest.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Requests; Web | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Requests/Withdrawal/CancelWithdrawalRequest.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Requests; Withdrawal | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Requests/Withdrawal/CreateWithdrawalRequest.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Requests; Withdrawal | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/BetAmendmentResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/BetCancellationResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/BetItemResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/BetPurchaseResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/BetResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/BulkBetResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/DepositResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/DrawResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/DrawResultResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/FinancialTransactionResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/PaymentResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/PayoutResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/PrizeClaimResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/ResultResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/TicketOwnershipResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/TicketProductResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/TicketResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/TicketShareResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/TicketVerificationResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/UserResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/WalletResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/WinningNumberResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Resources/WithdrawalResource.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Resources | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Responses/ApiResponse.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Responses | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Support/AuthAuditRecorder.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Support | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Support/BetPurchaseAuditRecorder.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Support | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Http/Support/BetPurchaseErrorMapper.php` # TYPE: PHP source | ROLE: Http | DOMAIN: Support | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/AllocateRetailTicketQuotaJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/AllocateTicketInventoryJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/Betting/ExpireBetCancellationRequestsJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/CalculatePrizeTaxJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/CertifyDrawResultJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/CloseExpiredClaimWindowsJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/DeliverDueRealityChecksJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/DisburseApprovedPrizeJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/DispatchPendingNotificationsJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/Draw/ProcessPrizeSettlementJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Draw | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/EnforceResponsibleGamingLimitsJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/ExecuteAdminOperationJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/ExecutePayoutTransferJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/ExpireMfaChallengesJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/ExpireReportExportsJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/ExpireSelfExclusionsJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/ExpireStaleNotificationsJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/ExpireStalePaymentIntentsJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/ExpireUnclaimedPrizesJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/ExpireWalletReservationsJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/Finance/ProcessFinancialReconciliationJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/GenerateOperationalReportJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/GeneratePayoutBatchJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/GeneratePayoutStatementJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/MatchDrawPrizesJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/Notification/SendFinancialAlertJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Notification | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/Payment/DisburseWithdrawalJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/Payment/ProcessPaymentWebhookJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/ProcessPaymentWebhookJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/ProcessPayoutBatchJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/PublishCertifiedDrawJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/ReassessAmlRiskJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/ReconcileCompletedDrawJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/ReconcilePaymentProviderJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/ReconcileWalletLedgersJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/ReleaseExpiredTicketReservationsJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/RetryFailedNotificationsJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/ReviewFinancialHoldsJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/ReviewHighRiskSecurityEventsJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/ReviewOpenComplianceCasesJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/ReviewPlayerProtectionCasesJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/RevokeExpiredSessionsJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/SendWinnerNotificationJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/SweepUnclaimedPrizesJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/VerifyPendingKycDocumentsJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/VerifyRetailTicketInventoryJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Jobs/VerifyWithdrawalKycJob.php` # TYPE: PHP source | ROLE: Jobs | DOMAIN: Jobs | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Listeners/RecordAdminOperationAudit.php` # TYPE: PHP source | ROLE: Listeners | DOMAIN: Listeners | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Listeners/RecordComplianceActionAudit.php` # TYPE: PHP source | ROLE: Listeners | DOMAIN: Listeners | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Listeners/RecordDrawCertificationAudit.php` # TYPE: PHP source | ROLE: Listeners | DOMAIN: Listeners | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Listeners/RecordFinancialReconciliationAudit.php` # TYPE: PHP source | ROLE: Listeners | DOMAIN: Listeners | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Listeners/RecordMfaVerificationAudit.php` # TYPE: PHP source | ROLE: Listeners | DOMAIN: Listeners | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Listeners/RecordNotificationAudit.php` # TYPE: PHP source | ROLE: Listeners | DOMAIN: Listeners | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Listeners/RecordNotificationReceiptAudit.php` # TYPE: PHP source | ROLE: Listeners | DOMAIN: Listeners | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Listeners/RecordPaymentReconciliationAudit.php` # TYPE: PHP source | ROLE: Listeners | DOMAIN: Listeners | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Listeners/RecordPaymentWebhookAudit.php` # TYPE: PHP source | ROLE: Listeners | DOMAIN: Listeners | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Listeners/RecordPayoutBatchAudit.php` # TYPE: PHP source | ROLE: Listeners | DOMAIN: Listeners | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Listeners/RecordPlayerProtectionActionAudit.php` # TYPE: PHP source | ROLE: Listeners | DOMAIN: Listeners | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Listeners/RecordPrizeClaimAudit.php` # TYPE: PHP source | ROLE: Listeners | DOMAIN: Listeners | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Listeners/RecordPrizeDisbursementAudit.php` # TYPE: PHP source | ROLE: Listeners | DOMAIN: Listeners | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Listeners/RecordProviderOperationAudit.php` # TYPE: PHP source | ROLE: Listeners | DOMAIN: Listeners | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Listeners/RecordRetailTicketAllocationAudit.php` # TYPE: PHP source | ROLE: Listeners | DOMAIN: Listeners | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Listeners/RecordSecurityEventAudit.php` # TYPE: PHP source | ROLE: Listeners | DOMAIN: Listeners | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Listeners/RecordSelfExclusionAudit.php` # TYPE: PHP source | ROLE: Listeners | DOMAIN: Listeners | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Listeners/RecordWithdrawalKycAudit.php` # TYPE: PHP source | ROLE: Listeners | DOMAIN: Listeners | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Lottery/Schema/LaneSchema.php` # TYPE: PHP source | ROLE: Lottery | DOMAIN: Schema | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Lottery/Schema/LaneSchemaFactory.php` # TYPE: PHP source | ROLE: Lottery | DOMAIN: Schema | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Lottery/Schema/ResultFieldSchema.php` # TYPE: PHP source | ROLE: Lottery | DOMAIN: Schema | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/AccountGradeSnapshot.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/AccountVerification.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/AccountVerificationDocument.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/AdminOperation.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/Agent.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/AgentCommission.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/AmlRiskAssessment.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/AuditLog.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/AuthenticationAttempt.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/Bet.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/BetAmendment.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/BetItem.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/BingoLotteryDraw.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/BingoLotteryResult.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/BingoLotteryResultVersion.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/ComplianceAction.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/ComplianceCase.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/ContactMessage.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/ContactMessageDelivery.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/Deposit.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/Draw.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/DrawCertification.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/DrawPublication.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/DrawReconciliation.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/DrawResult.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/FinancialHold.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/FinancialTransaction.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/GloDealer.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/GloDealerChangeRequest.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/GloL6Sale.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/GloL6Ticket.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/GloN3Sale.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/GloNotificationDelivery.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/GloPrizeClaim.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/GloPrizePaymentHold.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/GloPublicTicketStatus.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/GloResultImport.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/GloSalesPoint.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/GloSalesPointHistory.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/GloSalesReconciliation.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/GloSavedTicket.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/GloTicket.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/GloTicketFreeze.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/GradeDiscountSnapshot.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/KycDocument.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/KycVerification.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/LedgerAccount.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/LedgerEntry.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/LedgerReconciliation.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/LotteryTicketVerification.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/MfaChallenge.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/NationalLotteryDraw.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/NationalLotteryResult.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/NationalLotteryResultVersion.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/Notification.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/NotificationDeliveryAttempt.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/NotificationPreference.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/NotificationReceipt.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/NotificationTemplate.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/NumberLimit.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/OperationalReportJob.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/Payment.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/PaymentIntent.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/PaymentMethodConfig.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/PaymentProvider.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/PaymentReconciliation.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/PaymentWebhook.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/Payout.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/PayoutBatch.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/PayoutDocument.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/PcsoLotteryDraw.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/PcsoLotteryResult.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/PcsoLotteryResultVersion.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/PlayerProtectionAct.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/PlayerProtectionCase.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/PrizeDisbursement.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/PrizeEligibilityDecision.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/PrizeMatch.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/ProviderOperation.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/RealityCheck.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/ReportExport.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/ResponsibleGamingLimit.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/ResponsibleGamingLimitVersion.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/RetailVendor.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/SecurityEvent.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/SecuritySession.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/SelfExclusion.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/Support/AbstractLotteryDraw.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Support | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/Support/AbstractLotteryResult.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Support | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/Support/AbstractLotteryResultVersion.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Support | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/Ticket.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/TicketAllocation.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/TicketInventoryItem.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/TicketProduct.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/TicketShare.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/Transaction.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/TrustedDevice.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/User.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/Wallet.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/WalletLedger.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/WalletReservation.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/WeeklyLotteryDraw.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/WeeklyLotteryResult.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/WeeklyLotteryResultVersion.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/WinnerNotification.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/WinningNumber.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Models/Withdrawal.php` # TYPE: PHP source | ROLE: Models | DOMAIN: Models | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Notifications/AuthPasswordResetNotification.php` # TYPE: PHP source | ROLE: Notifications | DOMAIN: Notifications | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Notifications/KycStatusNotification.php` # TYPE: PHP source | ROLE: Notifications | DOMAIN: Notifications | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Notifications/PaymentStatusNotification.php` # TYPE: PHP source | ROLE: Notifications | DOMAIN: Notifications | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Notifications/PrizeWonNotification.php` # TYPE: PHP source | ROLE: Notifications | DOMAIN: Notifications | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Notifications/WithdrawalStatusNotification.php` # TYPE: PHP source | ROLE: Notifications | DOMAIN: Notifications | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Policies/AccountGradePolicy.php` # TYPE: PHP source | ROLE: Policies | DOMAIN: Policies | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Policies/AccountVerificationPolicy.php` # TYPE: PHP source | ROLE: Policies | DOMAIN: Policies | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Policies/AgentCommissionPolicy.php` # TYPE: PHP source | ROLE: Policies | DOMAIN: Policies | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Policies/AgentPolicy.php` # TYPE: PHP source | ROLE: Policies | DOMAIN: Policies | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Policies/BasePolicy.php` # TYPE: PHP source | ROLE: Policies | DOMAIN: Policies | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Policies/BetAmendmentPolicy.php` # TYPE: PHP source | ROLE: Policies | DOMAIN: Policies | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Policies/BetCancellationPolicy.php` # TYPE: PHP source | ROLE: Policies | DOMAIN: Policies | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Policies/BetPolicy.php` # TYPE: PHP source | ROLE: Policies | DOMAIN: Policies | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Policies/DrawPolicy.php` # TYPE: PHP source | ROLE: Policies | DOMAIN: Policies | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Policies/GloPrizeClaimPolicy.php` # TYPE: PHP source | ROLE: Policies | DOMAIN: Policies | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Policies/GloTicketFreezePolicy.php` # TYPE: PHP source | ROLE: Policies | DOMAIN: Policies | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Policies/LedgerAccountPolicy.php` # TYPE: PHP source | ROLE: Policies | DOMAIN: Policies | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Policies/LedgerPolicy.php` # TYPE: PHP source | ROLE: Policies | DOMAIN: Policies | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Policies/PaymentPolicy.php` # TYPE: PHP source | ROLE: Policies | DOMAIN: Policies | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Policies/PayoutPolicy.php` # TYPE: PHP source | ROLE: Policies | DOMAIN: Policies | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Policies/TicketPolicy.php` # TYPE: PHP source | ROLE: Policies | DOMAIN: Policies | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Policies/TicketSharePolicy.php` # TYPE: PHP source | ROLE: Policies | DOMAIN: Policies | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Policies/WalletPolicy.php` # TYPE: PHP source | ROLE: Policies | DOMAIN: Policies | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Providers/AppServiceProvider.php` # TYPE: PHP source | ROLE: Providers | DOMAIN: Providers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Providers/AuthServiceProvider.php` # TYPE: PHP source | ROLE: Providers | DOMAIN: Providers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Providers/EventServiceProvider.php` # TYPE: PHP source | ROLE: Providers | DOMAIN: Providers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Providers/Filament/AdminPanelProvider.php` # TYPE: PHP source | ROLE: Providers | DOMAIN: Filament | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Rules/AccountIdentifierRule.php` # TYPE: PHP source | ROLE: Rules | DOMAIN: Rules | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Rules/DocumentUploadRule.php` # TYPE: PHP source | ROLE: Rules | DOMAIN: Rules | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Rules/StrictMoneyAmount.php` # TYPE: PHP source | ROLE: Rules | DOMAIN: Rules | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Rules/StrongPasswordRule.php` # TYPE: PHP source | ROLE: Rules | DOMAIN: Rules | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Rules/ValidDiscountPercentage.php` # TYPE: PHP source | ROLE: Rules | DOMAIN: Rules | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Account/AccountDiscountService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Account | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Account/AccountGradeEvaluator.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Account | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Account/AccountGradeService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Account | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Account/AccountVerificationDocumentService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Account | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Account/AccountVerificationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Account | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Account/GradeDiscountApplicationPolicy.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Account | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Account/GradeDiscountEntitlementService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Account | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Account/GradeTierCatalog.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Account | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Account/PublicAccountInfoService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Account | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Admin/AdminOperationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Admin | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Affiliate/AffiliateCommissionDisplayService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Affiliate | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Agent/AgentCommissionAccrualService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Agent | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Agent/AgentCommissionReversalService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Agent | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Agent/AgentCommissionService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Agent | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Agent/AgentCommissionSettlementService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Agent | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Agent/AgentOnboardingService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Agent | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Agent/AgentReferralService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Agent | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Agent/AgentReportingService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Agent | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Agent/AgentSettlementService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Agent | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Agent/CommissionCalculationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Agent | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Audit/AuditLogService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Audit | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Auth/CaptchaService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Auth | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Auth/LoginService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Auth | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Auth/PasswordResetService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Auth | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Auth/RegistrationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Auth | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/BetAmendmentService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/BetAmountService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/BetCalculationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/BetCancellationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/BetPermutationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/BetPurchaseBetService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/BetPurchaseIdempotencyService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/BetPurchaseItemService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/BetPurchaseLedgerService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/BetPurchaseReferenceService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/BetPurchaseRiskService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/BetPurchaseService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/BetPurchaseTicketService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/BetPurchaseTransactionService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/BetPurchaseValidator.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/BetPurchaseWalletService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/BetValidationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/BulkBetService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/LotteryNumberService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/MarketPayoutService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/MarketResultResolver.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/MarketRuleResolver.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/PayoutMultiplierService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/RunMatchService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/ThreeDigitMatchService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/TicketShareService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/TicketVerificationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/TodMatchService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/TodPermutationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Betting/TwoDigitMatchService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Betting | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Compliance/AmlRiskAssessmentService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Compliance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Compliance/AmlRiskService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Compliance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Compliance/ComplianceActionService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Compliance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Compliance/ComplianceCaseService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Compliance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Compliance/ComplianceService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Compliance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Compliance/KycDocumentService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Compliance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Compliance/KycVerificationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Compliance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Compliance/SelfExclusionService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Compliance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Compliance/WithdrawalKycGateService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Compliance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Draw/DrawCertificationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Draw | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Draw/DrawLifecycleService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Draw | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Draw/DrawPublicationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Draw | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Draw/DrawReconciliationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Draw | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Draw/DrawResultConfirmationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Draw | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Draw/DrawResultIngestionService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Draw | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Draw/DrawResultPublicationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Draw | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Draw/DrawResultValidator.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Draw | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Draw/DrawScheduleService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Draw | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Draw/DrawSettlementSimulationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Draw | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Draw/PublicResultVerificationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Draw | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Draw/RealPrizeSettlementService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Draw | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Draw/SelectionSettlementResolver.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Draw | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/DepositApprovalService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/DepositCompletionService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/DepositService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/FinancialHoldService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/FinancialReconciliationExportService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/FinancialReconciliationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/FinancialReversalService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/FinancialStateTransitionService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/FinancialTransactionService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/IdempotencyService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/LedgerAdjustmentService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/LedgerBalanceValidator.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/LedgerPostingService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/LedgerReconciliationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/Money.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/PayoutApprovalService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/PayoutBatchService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/PayoutReconciliationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/RefundService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/TaxCalculationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/WalletHoldService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/WalletLockService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/WalletReservationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/WalletService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/WithdrawalApprovalService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/WithdrawalCompletionService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Finance/WithdrawalService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Finance | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Home/HomeCountdownService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Home | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Home/HomeLotteryFeedService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Home | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Home/HomePageDataService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Home | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Home/HomeResultFeedService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Home | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Home/PublicLaneResultDigestService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Home | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/AbstractLotterySourceService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/BingoLotteryDateService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/BingoLotteryHistoryService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/BingoLotteryImportService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/BingoLotteryResultService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/BingoLotterySearchService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/BingoLotteryService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/BingoLotterySourceService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/CanonicalDiscountMatrixService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/DiscountParityProjectionService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloDataMatrixParser.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloDataMatrixParserInterface.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloDealerChangeRequestService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloDealerService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloFixtureResultProvider.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloFrozenWinnerService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloL6AuthoritativeTicketEngineService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloL6HomeService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloL6ProportionalPrizeCalculator.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloL6PurchaseCapabilityService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloL6SalesService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloLiveDrawService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloN3PrizeCalculator.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloN3PrizePoolAllocator.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloN3SaleService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloN3SettlementService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloN3TicketChecker.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloNextDrawService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloOfficialResultProvider.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloPrizeCatalogue.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloPrizeClaimService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloPublicHomeService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloPublicPrizeSummaryService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloPublicResultService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloPublicStatsService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloPublicTicketVerificationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloResultImportService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloResultNotificationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloResultProvider.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloResultPublicationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloResultService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloSalesPointService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloSalesReconciliationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloSavedTicketService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloStampDutyCalculator.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloTicketChecker.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/GloTicketFreezeService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/LottoDiscountCalculator.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/NationalLotteryDateService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/NationalLotteryHistoryService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/NationalLotteryImportService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/NationalLotteryResultService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/NationalLotterySearchService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/NationalLotteryService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/NationalLotterySourceService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/PcsoLotteryDateService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/PcsoLotteryHistoryService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/PcsoLotteryImportService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/PcsoLotteryPurchaseCapabilityService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/PcsoLotteryResultService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/PcsoLotterySearchService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/PcsoLotteryService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/PcsoLotterySourceService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/PrizeVerificationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/PublicLotteryCatalogService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/ResultImportService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/Support/AbstractLotteryCalendarService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery; Support | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/Support/AbstractLotteryHistoryService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery; Support | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/Support/AbstractLotteryImportService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery; Support | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/Support/AbstractLotteryResultService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery; Support | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/Support/AbstractLotterySearchService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery; Support | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/Support/AbstractLotterySourceService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery; Support | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/Support/AppliesLaneOrdering.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery; Support | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/TicketAuthenticityService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/TicketBarcodeService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/TicketIdentityService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/WeeklyLotteryDateService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/WeeklyLotteryHistoryService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/WeeklyLotteryImportService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/WeeklyLotteryResultService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/WeeklyLotterySearchService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/WeeklyLotteryService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/WeeklyLotterySourceService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Lottery/WeeklyResultIntegrityService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Lottery | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Media/PublicAppLinkService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Media | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Monitoring/HealthCheckService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Monitoring | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Notification/NotificationDeliveryService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Notification | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Notification/NotificationDispatchService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Notification | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Notification/NotificationPreferenceService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Notification | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Notification/NotificationReceiptService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Notification | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Notification/NotificationSuppressionService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Notification | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Notification/NotificationTemplateService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Notification | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Observability/CorrelationContext.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Observability | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Observability/FinancialMetricsCollector.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Observability | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Observability/OperationalAlertService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Observability | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Observability/PrometheusClient.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Observability | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Observability/StructuredLogger.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Observability | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Observability/SystemHealthService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Observability | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Operations/AdminAuditQueryService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Operations | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Operations/AdminOperationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Operations | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Operations/OperationalReportService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Operations | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Operations/ProviderHealthService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Operations | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Operations/ProviderOperationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Operations | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Operations/ReportExportService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Operations | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Payment/Contracts/PaymentGatewayInterface.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Payment; Contracts | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Payment/Drivers/AbstractPaymentGateway.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Payment; Drivers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Payment/Drivers/BankTransferGateway.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Payment; Drivers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Payment/Drivers/BkashGateway.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Payment; Drivers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Payment/Drivers/CryptoGateway.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Payment; Drivers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Payment/Drivers/NagadGateway.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Payment; Drivers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Payment/Drivers/StripeGateway.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Payment; Drivers | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Payment/PaymentCallbackService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Payment/PaymentGatewayManager.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Payment/PaymentInitiationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Payment/PaymentIntentService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Payment/PaymentProviderRegistry.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Payment/PaymentReconciliationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Payment/PaymentVerificationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Payment/PaymentWebhookService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Payment/PaymentWebhookVerificationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Payment/PayoutTransferService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Payment/ProductionPaymentExecutionHubService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Payment/PromptPayPaymentService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Payment/WithdrawalDisbursementService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Payment | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Payments/PublicPaymentMethodsService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Payments | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Payout/PayoutStatementService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Payout | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Pricing/LottoDiscountService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Pricing | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Pricing/LottoPayoutRuleService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Pricing | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Privacy/PrivacyPolicyService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Privacy | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Prize/ClaimWindowService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Prize | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Prize/PrizeClaimService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Prize | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Prize/PrizeClaimValidationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Prize | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Prize/PrizeDisbursementService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Prize | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Prize/PrizeEligibilityService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Prize | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Prize/PrizeMatchingService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Prize | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Prize/UnclaimedPrizeService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Prize | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Prize/WinnerNotificationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Prize | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Promotions/PublicBonusService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Promotions | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/PublicPages/AboutPageService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: PublicPages | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/PublicPages/AboutTimelineService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: PublicPages | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/PublicPages/FeesPageService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: PublicPages | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/PublicPages/PrivacyPageService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: PublicPages | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/PublicPages/PublicPageDataService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: PublicPages | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/PublicPages/PublicPageTextBag.php` # TYPE: PHP source | ROLE: Services | DOMAIN: PublicPages | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/PublicPages/ResultsPageService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: PublicPages | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/PublicPages/TermsPageService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: PublicPages | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/PublicPages/VisionMissionService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: PublicPages | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Queue/QueueHealthService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Queue | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/ResponsibleGaming/PlayerProtectionActionService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: ResponsibleGaming | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/ResponsibleGaming/PlayerProtectionCaseService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: ResponsibleGaming | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/ResponsibleGaming/RealityCheckService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: ResponsibleGaming | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/ResponsibleGaming/ResponsibleGamingEnforcementService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: ResponsibleGaming | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/ResponsibleGaming/ResponsibleGamingLimitService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: ResponsibleGaming | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/ResponsibleGaming/SelfExclusionService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: ResponsibleGaming | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/ResponsibleGamingLimitService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Services | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Retail/RetailVendorService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Retail | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Retail/TicketAllocationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Retail | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Retail/TicketInventoryService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Retail | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Risk/ExposureCalculator.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Risk | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Risk/HotNumberService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Risk | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Risk/MoneyExposureCalculator.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Risk | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Risk/NumberLimitEngine.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Risk | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Risk/NumberLimitLockService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Risk | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Risk/NumberLimitResolver.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Risk | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Risk/NumberNormalizationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Risk | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Risk/RiskAlertService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Risk | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Risk/RiskAssessmentService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Risk | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Risk/RiskDecisionService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Risk | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Risk/RiskLevelCalculator.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Risk | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Security/AuthenticationSecurityService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Security | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Security/DeviceTrustService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Security | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Security/KycVerificationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Security | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Security/MfaChallengeService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Security | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Security/ResponsibleGamingService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Security | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Security/SecurityEventService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Security | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Security/SecurityRiskAssessmentService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Security | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Security/UserSessionSecurityService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Security | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Support/ContactDeliveryService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Support | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Support/ContactMessageService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Support | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Support/ContactPrivacyService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Support | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Support/ContactSpamProtectionService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Support | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Support/PublicSupportService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Support | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Ticket/TicketOwnershipService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Ticket | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Ticket/TicketProductService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Ticket | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Ticket/TicketQrVerificationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Ticket | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Verification/AccountVerificationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Verification | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Verification/DocumentStorageService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Verification | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Wallet/WalletReservationService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Wallet | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Wallet/WalletService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Wallet | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Services/Withdrawal/WithdrawalKycGateService.php` # TYPE: PHP source | ROLE: Services | DOMAIN: Withdrawal | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Support/Admin/AdminAccess.php` # TYPE: PHP source | ROLE: Support | DOMAIN: Admin | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Support/Admin/AdminFormat.php` # TYPE: PHP source | ROLE: Support | DOMAIN: Admin | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/Support/Admin/OperatorActionFactory.php` # TYPE: PHP source | ROLE: Support | DOMAIN: Admin | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/ValueObjects/BetAmount.php` # TYPE: PHP source | ROLE: ValueObjects | DOMAIN: ValueObjects | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/ValueObjects/LotteryNumber.php` # TYPE: PHP source | ROLE: ValueObjects | DOMAIN: ValueObjects | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`app/ValueObjects/PayoutMultiplier.php` # TYPE: PHP source | ROLE: ValueObjects | DOMAIN: ValueObjects | USED BY: Laravel application runtime, service container, routes, jobs, tests, or UI depending on role
`bootstrap/app.php` # TYPE: PHP source | ROLE: application bootstrap | DOMAIN: middleware, exception, and framework bootstrap | USED BY: Laravel application startup
`bootstrap/cache/.gitignore` # TYPE: project file | ROLE: application bootstrap | DOMAIN: middleware, exception, and framework bootstrap | USED BY: Laravel application startup
`bootstrap/providers.php` # TYPE: PHP source | ROLE: application bootstrap | DOMAIN: middleware, exception, and framework bootstrap | USED BY: Laravel application startup
`config/account.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/account_grades.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/account_verification.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/agent.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/app.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/auth.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/auth_security.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/bingo_lottery.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/broadcasting.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/cache.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/contact.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/cors.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/database.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/discounts.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/fees.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/filesystems.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/finance.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/glo.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/home.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/legal.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/logging.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/lottery.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/lotto_discount_matrix.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/mail.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/national_lottery.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/payment.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/pcso_lottery.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/permission.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/public_pages.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/queue.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/risk.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/sanctum.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/security.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/services.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/session.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/ticket_verification.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/view.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`config/weekly_lottery.php` # TYPE: PHP source | ROLE: configuration | DOMAIN: runtime configuration | USED BY: Laravel configuration repository and services
`database/.gitkeep` # TYPE: project file | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/.gitkeep` # TYPE: project file | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/AccountVerificationDocumentFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/AgentCommissionFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/AgentFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/AuditLogFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/BetAmendmentFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/BetFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/BetItemFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/BingoLotteryDrawFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/BingoLotteryResultFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/BingoLotteryResultVersionFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/ContactMessageDeliveryFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/ContactMessageFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/DepositFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/DrawFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/DrawResultFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/FinancialTransactionFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/KycDocumentFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/LedgerAccountFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/LedgerEntryFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/LotteryTicketVerificationFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/NationalLotteryDrawFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/NationalLotteryResultFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/NationalLotteryResultVersionFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/NumberLimitFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/PaymentFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/PayoutBatchFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/PayoutDocumentFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/PayoutFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/PcsoLotteryDrawFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/PcsoLotteryResultFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/PcsoLotteryResultVersionFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/ResponsibleGamingLimitFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/TicketFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/TicketProductFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/TicketShareFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/UserFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/WalletFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/WeeklyLotteryDrawFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/WeeklyLotteryResultFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/WeeklyLotteryResultVersionFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/WinningNumberFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/factories/WithdrawalFactory.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/.gitkeep` # TYPE: project file | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/0001_01_01_000000_create_users_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/0001_01_01_000001_create_cache_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/0001_01_01_000002_create_jobs_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_01_000100_create_permission_tables.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_01_000200_create_personal_access_tokens_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_02_000100_create_wallets_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_02_000200_create_ledger_accounts_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_02_000300_create_financial_transactions_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_02_000400_create_ledger_entries_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_03_000100_create_draws_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_03_000200_create_draw_results_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_03_000300_create_winning_numbers_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_03_000400_create_number_limits_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_03_000500_create_tickets_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_03_000600_create_bets_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_03_000700_create_bet_items_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_03_000800_create_payouts_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_03_000900_create_bet_amendments_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_03_001000_create_ticket_shares_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_04_000100_create_payments_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_04_000200_create_deposits_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_04_000300_create_withdrawals_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_05_000100_create_agents_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_05_000200_create_agent_commissions_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_06_000100_create_audit_logs_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_07_000100_create_kyc_documents_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_07_000200_create_responsible_gaming_limits_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_08_000100_create_payout_batches_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_08_000200_create_ticket_products_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_01_08_000300_create_payout_documents_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_000100_create_retail_vendors_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_000200_create_ticket_allocations_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_000300_create_ticket_inventory_items_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_000400_create_draw_certifications_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_000500_create_draw_publications_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_000600_create_draw_reconciliations_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_000700_create_prize_matches_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_000800_create_prize_eligibility_decisions_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_000900_create_winner_notifications_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_001000_create_prize_disbursements_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_001100_create_wallet_reservations_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_001200_create_financial_holds_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_001300_create_ledger_reconciliations_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_001400_create_payment_providers_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_001500_create_payment_method_configs_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_001600_create_payment_intents_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_001700_create_payment_webhooks_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_001800_create_payment_reconciliations_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_001900_extend_kyc_documents_for_verification_lane.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_002000_create_kyc_verifications_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_002100_create_aml_risk_assessments_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_002200_create_compliance_cases_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_002300_create_compliance_actions_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_002400_create_self_exclusions_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_002500_create_responsible_gaming_limit_versions_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_002600_create_reality_checks_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_002700_create_player_protection_cases_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_002800_create_player_protection_actions_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_002900_create_authentication_attempts_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_003000_create_mfa_challenges_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_003100_create_security_sessions_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_003200_create_trusted_devices_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_003300_create_security_events_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_003400_create_notification_templates_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_003500_create_notification_preferences_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_003600_create_notifications_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_003700_create_notification_delivery_attempts_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_003800_create_notification_receipts_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_003900_create_admin_operations_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_004000_create_provider_operations_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_004100_create_operational_report_jobs_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2024_02_01_004200_create_report_exports_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_22_000100_create_glo_freeze_claim_tables.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_22_000200_add_date_of_birth_to_users.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_23_000100_create_glo_sales_and_result_tables.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_23_000200_create_glo_dealer_public_tables.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_24_000210_create_account_grade_snapshots_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_25_000300_create_lottery_ticket_verifications_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_26_000400_create_national_lottery_draws_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_26_000410_create_national_lottery_results_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_26_000420_create_national_lottery_result_versions_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_26_000500_create_weekly_lottery_draws_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_26_000510_create_weekly_lottery_results_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_26_000520_create_weekly_lottery_result_versions_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_27_000600_create_bingo_lottery_draws_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_27_000610_create_bingo_lottery_results_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_27_000620_create_bingo_lottery_result_versions_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_27_220001_extend_account_grade_snapshots_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_27_220002_create_grade_discount_snapshots_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_28_000700_create_pcso_lottery_draws_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_28_000710_create_pcso_lottery_results_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_28_000720_create_pcso_lottery_result_versions_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_28_230001_create_account_verifications_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_29_000800_create_contact_messages_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/migrations/2026_09_29_000810_create_contact_message_deliveries_table.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/seeders/.gitkeep` # TYPE: project file | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/seeders/DatabaseSeeder.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/seeders/LedgerAccountSeeder.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/seeders/ResultArchiveSeeder.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/seeders/RolePermissionSeeder.php` # TYPE: PHP source | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`database/seeders/data/results/README.md` # TYPE: project file | ROLE: database artifact | DOMAIN: migrations, seeders, factories, or database support | USED BY: database schema and test data lifecycle
`resources/css/.gitkeep` # TYPE: project file | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/accessibility.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/account-services.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/admin-lottofin.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/app.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/auth-portal.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/bingo-lottery.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/components/glass.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css; components | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/components/lottery-3d.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css; components | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/components/metrics.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css; components | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/contact.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/deposit-portal.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/glo-l6.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/glo-results-checker.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/home.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/lottery-history.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/lottery.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/national-lottery.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/pages/about.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/pages/account-grades.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/pages/account-verification.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/pages/contact.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/pages/discounts.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/pages/download.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/pages/faq.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/pages/fees.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/pages/home.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/pages/how-to-play.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/pages/privacy.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/pages/prize-verification.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/pages/public-next-pages.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/pages/terms.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/pages/vision.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/pcso-lottery.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/player-dashboard.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/player-profile.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/player-security-settings.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/player-settings.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/prize-discount.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/public-pages.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/thai-lottery-betting.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/thailotto-theme.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/theme.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/wallet-management.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/weekly-lottery.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/css/withdrawal-portal.css` # TYPE: stylesheet | ROLE: frontend resource | DOMAIN: css | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/glo/fixtures/lottery_result.json` # TYPE: configuration/data | ROLE: frontend resource | DOMAIN: glo; fixtures | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/.gitkeep` # TYPE: project file | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/accessibility.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/account-grade-portal.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/account-grade.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/account-verification-portal.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/account-verification.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/admin-lottofin.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/admin-lottofin.ts` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/app.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/auth-portal.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/auth-portal.ts` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/bet-slip.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/bingo-lottery.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/bootstrap.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/components/AuthCard.tsx` # TYPE: project file | ROLE: frontend resource | DOMAIN: js; components | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/components/DepositPortal.tsx` # TYPE: project file | ROLE: frontend resource | DOMAIN: js; components | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/components/GloResultsChecker.tsx` # TYPE: project file | ROLE: frontend resource | DOMAIN: js; components | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/components/LotteryHistory.tsx` # TYPE: project file | ROLE: frontend resource | DOMAIN: js; components | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/components/LottoFinAdminDashboard.tsx` # TYPE: project file | ROLE: frontend resource | DOMAIN: js; components | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/components/PlayerDashboard.tsx` # TYPE: project file | ROLE: frontend resource | DOMAIN: js; components | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/components/PlayerProfile.tsx` # TYPE: project file | ROLE: frontend resource | DOMAIN: js; components | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/components/PlayerSecuritySettings.tsx` # TYPE: project file | ROLE: frontend resource | DOMAIN: js; components | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/components/PlayerSettings.tsx` # TYPE: project file | ROLE: frontend resource | DOMAIN: js; components | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/components/ThaiLotteryBetting.tsx` # TYPE: project file | ROLE: frontend resource | DOMAIN: js; components | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/components/WalletManagement.tsx` # TYPE: project file | ROLE: frontend resource | DOMAIN: js; components | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/components/WithdrawalPortal.tsx` # TYPE: project file | ROLE: frontend resource | DOMAIN: js; components | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/contact-portal.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/contact.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/deposit-portal.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/deposit-portal.ts` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/deposit.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/download-app-portal.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/echo.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/faq-portal.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/fees-portal.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/glo-results-checker.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/glo-results-checker.ts` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/home-keypad.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/home/countdown.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/home/live-draw.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/how-to-play-portal.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/lottery-history.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/lottery-history.ts` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/lottery/bet-slip.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js; lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/lottery/countdown.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js; lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/lottery/live-results.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js; lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/lottery/ticket-selector.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js; lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/lotto-discount-portal.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/national-lottery.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/notifications.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/pages/about.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/pages/account-grades.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/pages/account-verification.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/pages/contact.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/pages/discounts.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/pages/download.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/pages/faq.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/pages/fees.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/pages/home.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/pages/how-to-play.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/pages/privacy.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/pages/prize-verification.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/pages/public-next-pages.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/pages/terms.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/pages/vision.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/pcso-lottery.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/player-dashboard.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/player-dashboard.ts` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/player-profile.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/player-profile.ts` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/player-security-settings.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/player-security-settings.ts` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/player-settings.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/player-settings.ts` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/privacy-portal.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/prize-verification-portal.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/prize-verification.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/public-pages.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/svgbet-slip.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/svgdeposit.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/svgwithdraw.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/terms-portal.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/thai-lottery-betting.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/thai-lottery-betting.ts` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/wallet-management.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/wallet-management.ts` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/wallet/wallet-balance.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js; wallet | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/weekly-lottery.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/withdraw.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/withdrawal-portal.js` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/js/withdrawal-portal.ts` # TYPE: frontend source | ROLE: frontend resource | DOMAIN: js | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/.gitkeep` # TYPE: project file | ROLE: frontend resource | DOMAIN: views | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/about/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; about | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/account-grade/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; account-grade | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/account-info/grades.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; account-info | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/account-info/verification.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; account-info | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/account-verification/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; account-verification | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/account/grade-history.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; account | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/account/grade.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; account | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/admin/compliance/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; admin; compliance | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/admin/dashboard.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; admin | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/admin/kyc/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; admin; kyc | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/admin/payments/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; admin; payments | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/admin/withdrawals/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; admin; withdrawals | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/agent/commissions.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; agent | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/agent/dashboard.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; agent | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/agent/portal.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; agent | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/agent/settlements.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; agent | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/auth/forgot-password.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; auth | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/auth/login.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; auth | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/auth/member-login.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; auth | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/auth/member-register.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; auth | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/auth/register.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; auth | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/betting/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; betting | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/bingo-lottery/buy.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; bingo-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/bingo-lottery/draw-detail.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; bingo-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/bingo-lottery/history.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; bingo-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/bingo-lottery/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; bingo-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/bingo-lottery/latest-result.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; bingo-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/bingo-lottery/result-detail.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; bingo-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/bingo-lottery/show.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; bingo-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/bingo-lottery/year.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; bingo-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/account-grade/discount-games.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; account-grade | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/account-grade/grade-tier.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; account-grade | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/account/document-upload.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; account | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/account/grade-card.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; account | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/account/verification-status.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; account | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/bingo-lottery/result-card.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; bingo-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/bingo-lottery/result-table.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; bingo-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/bingo-lottery/search-form.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; bingo-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/bingo-lottery/source-status.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; bingo-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/bingo-lottery/year-nav.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; bingo-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/contact/contact-form.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; contact | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/contact/delivery-status.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; contact | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/contact/support-details.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; contact | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/contact/useful-links.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; contact | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/discount/game-rule.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; discount | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/home/app-download.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/home/app-links.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/home/bonus-section.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/home/bonuses.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/home/current-result.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/home/footer.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/home/glo-products.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/home/hero.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/home/lane-results.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/home/latest-results.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/home/live-draw.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/home/lottery-cards.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/home/lottery-grid.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/home/next-draw.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/home/payment-methods.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/home/prize-highlight.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/home/quick-check.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/home/sales-points.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/home/stats.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/home/support.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/home/trust-security.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/home/trust.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/lottery-hub/category-filter.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; lottery-hub | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/lottery-hub/compare.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; lottery-hub | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/lottery-hub/featured-lotteries.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; lottery-hub | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/lottery-hub/hero.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; lottery-hub | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/lottery-hub/results-cta.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; lottery-hub | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/lottery-purchase/unavailable.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; lottery-purchase | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/national-lottery/result-card.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; national-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/national-lottery/result-table.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; national-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/national-lottery/search-form.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; national-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/national-lottery/source-status.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; national-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/national-lottery/year-nav.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; national-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/navigation/main-nav.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; navigation | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/navigation/mobile-nav.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; navigation | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/pcso-lottery/result-card.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; pcso-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/pcso-lottery/result-table.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; pcso-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/pcso-lottery/search-form.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; pcso-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/pcso-lottery/source-status.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; pcso-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/pcso-lottery/year-nav.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; pcso-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/public-page/contact.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; public-page | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/public-page/core-values.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; public-page | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/public-page/footer.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; public-page | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/public-page/governance.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; public-page | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/public-page/header.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; public-page | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/public-page/history.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; public-page | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/public-page/how-it-works.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; public-page | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/public-page/terms-sections.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; public-page | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/public-page/useful-links.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; public-page | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/public-page/vision-mission.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; public-page | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/public/discount-table.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; public | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/public/fee-table.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; public | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/public/verification-result.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; public | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/results/result-card.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; results | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/ui/button.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; ui | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/weekly-lottery/result-card.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; weekly-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/weekly-lottery/result-table.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; weekly-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/weekly-lottery/search-form.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; weekly-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/weekly-lottery/source-status.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; weekly-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/components/weekly-lottery/year-nav.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; components; weekly-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/contact-us/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; contact-us | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/contact/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; contact | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/dashboard/player.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; dashboard | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/deposit/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; deposit | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/discounts/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; discounts | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/download-app/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; download-app | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/download/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; download | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/faq/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; faq | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/fees/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; fees | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/filament/pages/financial-reconciliation-page.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; filament; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/filament/pages/glo-dealer-requests-page.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; filament; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/filament/pages/glo-prize-claim-page.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; filament; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/filament/pages/glo-prize-structure-page.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; filament; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/filament/pages/glo-result-dashboard-page.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; filament; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/filament/pages/glo-sales-points-page.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; filament; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/filament/pages/glo-saved-ticket-notifications-page.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; filament; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/filament/pages/glo-ticket-freeze-page.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; filament; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/filament/pages/kyc-review-page.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; filament; pages | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/glo-l6/buy.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; glo-l6 | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/glo-l6/history.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; glo-l6 | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/glo-l6/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; glo-l6 | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/glo-l6/result.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; glo-l6 | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/home.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/home/check.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/home/contact.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/home/sales-points.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; home | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/how-to-play/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; how-to-play | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/layouts/admin.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; layouts | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/layouts/app.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; layouts | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/lotteries/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; lotteries | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/lotto-discount/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; lotto-discount | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/national-lottery/buy.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; national-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/national-lottery/draw-detail.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; national-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/national-lottery/history.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; national-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/national-lottery/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; national-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/national-lottery/latest-result.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; national-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/national-lottery/partials/detail-content.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; national-lottery; partials | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/national-lottery/result-detail.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; national-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/national-lottery/show.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; national-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/national-lottery/year.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; national-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/notifications/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; notifications | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/notifications/kyc-status.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; notifications | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/notifications/payment-status.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; notifications | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/notifications/prize-won.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; notifications | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/notifications/withdrawal-status.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; notifications | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/payment/callback.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; payment | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/pcso-lottery/buy.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; pcso-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/pcso-lottery/history.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; pcso-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/pcso-lottery/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; pcso-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/pcso-lottery/latest-result.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; pcso-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/pcso-lottery/show.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; pcso-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/pcso-lottery/year.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; pcso-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/player/bet.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; player | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/player/bets.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; player | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/player/dashboard.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; player | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/player/deposit-status.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; player | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/player/deposit.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; player | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/player/draw-detail.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; player | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/player/draws.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; player | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/player/profile.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; player | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/player/responsible-gaming.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; player | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/player/security.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; player | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/player/wallet.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; player | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/player/withdraw.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; player | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/player/withdrawal-status.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; player | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/privacy/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; privacy | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/prize-verification/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; prize-verification | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/results/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; results | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/results/search.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; results | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/sitemap.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/static/privacy.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; static | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/static/terms.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; static | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/support/portal.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; support | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/terms/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; terms | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/vision/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; vision | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/wallet/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; wallet | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/weekly-lottery/buy.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; weekly-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/weekly-lottery/draw-detail.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; weekly-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/weekly-lottery/history.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; weekly-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/weekly-lottery/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; weekly-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/weekly-lottery/latest-result.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; weekly-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/weekly-lottery/result-detail.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; weekly-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/weekly-lottery/show.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; weekly-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/weekly-lottery/year.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; weekly-lottery | USED BY: Blade rendering or Vite frontend asset pipeline
`resources/views/withdrawal/index.blade.php` # TYPE: PHP source | ROLE: frontend resource | DOMAIN: views; withdrawal | USED BY: Blade rendering or Vite frontend asset pipeline
`routes/api.php` # TYPE: PHP source | ROLE: route registry | DOMAIN: HTTP, API, console, or channels routing | USED BY: Laravel route registration and middleware dispatch
`routes/channels.php` # TYPE: PHP source | ROLE: route registry | DOMAIN: HTTP, API, console, or channels routing | USED BY: Laravel route registration and middleware dispatch
`routes/console.php` # TYPE: PHP source | ROLE: route registry | DOMAIN: HTTP, API, console, or channels routing | USED BY: Laravel route registration and middleware dispatch
`routes/web.php` # TYPE: PHP source | ROLE: route registry | DOMAIN: HTTP, API, console, or channels routing | USED BY: Laravel route registration and middleware dispatch
`tests/Feature/.gitkeep` # TYPE: project file | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/About/AboutContentIntegrityTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/About/AboutLegacyRouteTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/About/AboutLocalizationTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/About/AboutPageTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Account/AccountGradeProgrammeTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Account/AccountServicesPagesTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Agent/AgentHierarchyTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Agent/AgentInactiveBehaviorTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Agent/AgentOnboardingTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Agent/AgentReferralCodeUniquenessTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Agent/AgentReportingTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Agent/AgentTestCase.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Agent/BetAgentAttributionTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Agent/CommissionAccrualTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Agent/CommissionCalculationTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Agent/CommissionDrawCancellationTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Agent/CommissionIdempotencyTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Agent/CommissionLedgerBalanceTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Agent/CommissionRefundReversalTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Agent/CommissionWalletCreditTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Agent/DuplicateCommissionPreventionTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Agent/UserAgentAttributionTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Api/V1/ApiPurchaseTestCase.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Api/V1/AuthApiTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Api/V1/Batch7ApiSurfaceTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Api/V1/BetAccessApiTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Api/V1/BetPurchaseApiTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Auth/MemberAuthParityTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Betting/BetPurchaseAtomicityTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/BingoLottery/BingoLotteryPublicPageTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/BusinessCriticalInvariantTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Compliance/SelfExclusionAndAgentGateTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Compliance/WithdrawalKycGateTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Console/DrawAutomationTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Console/GloPublishProductionGuardTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Console/GradesRebuildSnapshotsTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Console/ScheduleRegistrationTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Contact/ContactPageTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Database/ModelFactoryIntegrityTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Deployment/ArtifactCompletionManifestTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Filament/AdminPanelAccessTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Filament/DrawResourceTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Filament/FinanceResourcesTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Filament/IdentityResourcesTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Filament/LotteryOpsResourcesTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Filament/OperationsDashboardTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/FinalProductionReadinessTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/FinalWholeSystemNoSkipTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Finance/FinancialReconciliationComprehensiveTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Finance/ReconcileCommandContractTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Glo/GloConsoleCommandsTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Glo/GloDealerChangeRequestTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Glo/GloDealerServiceTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Glo/GloFrozenWinnerTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Glo/GloL6ProportionalCalculatorTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Glo/GloN3PrizePoolEngineTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Glo/GloN3SettlementAndCheckerTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Glo/GloPrizeClaimTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Glo/GloPublicResultHistoryTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Glo/GloPublicTicketVerificationTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Glo/GloResultImportTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Glo/GloResultNotificationTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Glo/GloSalesPointTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Glo/GloSalesSeatAndReconciliationTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Glo/GloSavedTicketTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Glo/GloTicketFreezeTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/HealthCheckTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Home/HomeApiTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Home/HomeDataIntegrityTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Home/HomeLaneResultsTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Home/HomeLocalizationTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Home/HomePagePublicExperienceTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Home/HomePageTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/LegacyRedirectTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Legal/PublicContentSourceTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Lottery/ArchiveInventoryParityTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Lottery/DiscountMatrixTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Lottery/LaneResultImportContractTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Lottery/Pages15To24Test.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Lottery/Pages25To34Test.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/NationalLottery/NationalLotteryIntegrationSeamTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/NationalLottery/NationalLotteryPublicPageTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Observability/HealthEndpointTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Observability/ProductionObservabilityComprehensiveTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/P0P1P2ComprehensiveEnterpriseSuiteTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Pages35To44/IndependentPublicPagesTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Pages77To100StaticContractTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Payment/BankTransferConfigurationTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Payment/DuplicateWalletCreditPreventionTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Payment/DuplicateWebhookIdempotencyTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Payment/ExpiredPaymentTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Payment/FailedPaymentStateTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Payment/FixturePublicationSafetyTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Payment/InvalidWebhookSignatureTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Payment/PaymentCallbackServiceTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Payment/PaymentGatewayManagerTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Payment/PaymentInitiationServiceTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Payment/PaymentLedgerBalanceTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Payment/PaymentTestCase.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Payment/PaymentWebhookSignatureVerificationTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Payment/PlayerDepositApiTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Payment/PromptPayIntegrationTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Payment/PublicPaymentCapabilityParityTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Payment/StripeGatewayCallbackUrlTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Payment/SuccessfulDepositCompletionTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Payment/WebhookReplayProtectionTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Payment/WithdrawalCompletionTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Payment/WithdrawalDestinationValidationTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/PcsoLottery/PcsoLotteryPublicPageTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Player/BetPurchaseWebTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Player/PlayerExperienceComprehensiveTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Player/PlayerFrontendModulesTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Player/ResponsibleGamingWebTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/PublicPages/LanePageParityTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/PublicPages/PublicAboutVisionTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/PublicPages/PublicAccountInfoTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/PublicPages/PublicPrivacyAndResultsTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/PublicPages/PublicTermsTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/PublicPages/PublicTermsUiTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/PublicServices/PrizeVerificationAndDiscountTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/PublicSite/PublicResultsAnonymousTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Queue/ProductionQueueComprehensiveTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/RealMoneyBusinessReadinessTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/SEO/SitemapPublicationFilterTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/SEO/SitemapTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/SafetyGate/PrizePayoutSafetyGateTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Security/AuthenticationGateTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Security/KycVerificationTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Security/NamedRateLimitersTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Security/ProductionSecurityComprehensiveTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Security/ResponsibleGamingTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Seeders/LedgerAccountSeederTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Settlement/DrawLifecycleTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Settlement/DrawResultPublicationTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Settlement/NonMonetarySettlementTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Settlement/Phase51FollowUpTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Settlement/SettlementSimulationTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Settlement/SettlementTestCase.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/TranslationKeyIntegrityTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/UI/DesignSystemRegressionTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/UI/PremiumExperienceTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Verification/AccountVerificationFlowTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Vision/VisionContentIntegrityTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Vision/VisionLegacyRouteTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Vision/VisionLocalizationTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Vision/VisionPageTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Web/AppLinksTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Web/BrowserPaymentCallbackTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/Web/PlayerWalletWiringTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Feature/WeeklyLottery/WeeklyLotteryPublicPageTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Integration/.gitkeep` # TYPE: project file | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Integration/Glo/GloDealerAndNotificationIntegrationTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Integration/Glo/GloFreezeClaimSettlementIntegrationTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Integration/MigrationSchemaTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Security/.gitkeep` # TYPE: project file | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Security/SecurityHeadersTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/TestCase.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Unit/.gitkeep` # TYPE: project file | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Unit/Api/ApplicationLayerSafetyTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Unit/Config/DatabaseDriverOptionsTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Unit/EnumIntegrityTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Unit/Payment/BkashGatewayTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Unit/Payment/CryptoGatewayTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Unit/Payment/NagadGatewayTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Unit/Payment/StripeGatewayTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Unit/Services/Draw/DrawScheduleServiceTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Unit/Services/FeesPageServiceTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Unit/Services/Home/HomeCountdownServiceTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Unit/Services/Home/HomePageDataServiceTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Unit/Services/PublicPages/AboutTimelineServiceTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Unit/Services/PublicPages/VisionMissionServiceTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation
`tests/Unit/Settlement/SettlementSafetyAuditTest.php` # TYPE: PHP source | ROLE: test or project support | DOMAIN: verification and delivery | USED BY: test runner, build runner, or operational documentation

```

## FILE 2: `app/Http/Controllers/Admin/ReleaseOperationsController.php`

# TYPE: PHP controller
# PURPOSE: Read-only evidence-based release, configuration, backup, disaster-recovery, incident, deployment, rollback, feature-flag, operator-access, authentication-security, fraud, KYC, privacy, compliance, and sanctions projection.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only release, configuration, disaster-recovery, and security
 * operations projection.
 *
 * This controller reports evidence that is available to the application. It
 * does not run shell commands, migrations, restores, cache flushes, key
 * rotations, deployment actions, or database mutations from a browser route.
 * Missing deployment metadata is reported explicitly instead of becoming a
 * fabricated green check.
 */
final class ReleaseOperationsController extends Controller
{
    public function show(Request $request, string $surface, ?string $reference = null): View
    {
        $this->authorizeSurface($request, $surface);

        return view('admin.release-operations', [
            'surface' => $surface,
            'reference' => $reference,
            'projection' => $this->projection($surface, $reference),
        ]);
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function projection(string $surface, ?string $reference): array
    {
        return match ($surface) {
            'cutover' => $this->cutoverProjection(),
            'manifest' => $this->manifestProjection(),
            'configuration' => $this->configurationProjection(),
            'secrets' => $this->secretsProjection(),
            'migrations' => $this->migrationProjection(),
            'backups' => $this->backupProjection(),
            'restore' => $this->restoreProjection(),
            'disaster-recovery' => $this->disasterRecoveryProjection(),
            'high-availability' => $this->highAvailabilityProjection(),
            'incidents' => $this->incidentProjection($reference),
            'deployment-approval' => $this->deploymentApprovalProjection(),
            'deployments' => $this->deploymentHistoryProjection(),
            'rollback' => $this->rollbackProjection(),
            'feature-flags' => $this->featureFlagProjection(),
            'configuration-audit' => $this->configurationAuditProjection(),
            'sessions' => $this->notConfiguredProjection([
                'current_sessions', 'last_activity', 'ip_representation', 'device_metadata', 'session_age', 'session_state',
            ], 'Session inventory requires the canonical secure session store.'),
            'access-review' => $this->notConfiguredProjection([
                'operator_account_status', 'role', 'assigned_permissions', 'last_login', 'mfa_state', 'suspicious_access_state',
            ], 'Operator access review requires the canonical operator identity and permission projection.'),
            'privileged-access' => $this->notConfiguredProjection([
                'wallet_management', 'payout_management', 'reconciliation', 'glo_claims', 'freeze_review', 'draw_management', 'system_settings',
            ], 'Privileged access review requires policy-backed operator records.'),
            'permission-matrix' => $this->permissionMatrixProjection(),
            'service-accounts' => $this->notConfiguredProjection([
                'service_identifier', 'environment', 'status', 'purpose', 'last_used', 'rotation_state',
            ], 'Service-account inventory is not configured.'),
            'network-access' => $this->notConfiguredProjection([
                'allowlist', 'denylist', 'trusted_proxy', 'admin_network_restriction',
            ], 'Network access evidence requires deployment-level trusted-proxy and network configuration.'),
            'device-risk' => $this->notConfiguredProjection([
                'unusual_device_count', 'simultaneous_sessions', 'session_changes', 'failed_authentication_indicators', 'revocation_state',
            ], 'Documented device-risk signals are not configured for this projection.'),
            'mfa' => $this->notConfiguredProjection([
                'enabled', 'disabled', 'enrollment_required', 'recovery_state', 'last_verification',
            ], 'MFA operational records are not configured for this projection.'),
            'authentication-security' => $this->notConfiguredProjection([
                'login_attempts', 'failed_login_counts', 'password_reset_attempts', 'account_lock_events', 'captcha_failures', 'authentication_anomalies',
            ], 'Authentication security aggregation requires canonical security-event telemetry.'),
            'rate-limits' => $this->rateLimitProjection(),
            'captcha' => $this->captchaProjection(),
            'risk-rules' => $this->notConfiguredProjection([
                'duplicate_account_rules', 'payment_anomaly_rules', 'rapid_deposit_rules', 'rapid_withdrawal_rules', 'failed_transaction_rules',
            ], 'Only explicit configured risk rules may be displayed; no opaque score is fabricated.'),
            'suspicious-activity' => $this->notConfiguredProjection([
                'case_id', 'reason', 'source_event', 'account_reference', 'state', 'assigned_operator', 'created_at', 'resolved_at',
            ], 'Suspicious-activity cases require a canonical case store.'),
            'compliance-cases' => $this->notConfiguredProjection([
                'evidence_references', 'payment_references', 'related_bets', 'kyc_state', 'account_restrictions', 'operator_actions', 'resolution',
            ], 'Compliance case detail requires a policy-protected case projection.'),
            'sanctions' => $this->notConfiguredProjection([
                'provider', 'configured', 'last_verification', 'api_health', 'failure_state',
            ], 'Sanctions/watchlist state is not claimed without a configured provider.'),
            'kyc' => $this->notConfiguredProjection([
                'identity_verification_state', 'document_state', 'provider_reference', 'review_state', 'verification_at',
            ], 'KYC state requires the canonical identity-verification service.'),
            'kyc-provider' => $this->notConfiguredProjection([
                'provider', 'configured', 'api_health', 'credential_presence', 'last_callback', 'failure_state',
            ], 'KYC provider state is not claimed without configured provider evidence.'),
            'kyc-review' => $this->notConfiguredProjection([
                'review_reference', 'queue_state', 'assigned_reviewer', 'evidence_state', 'decision_state', 'decision_at',
            ], 'KYC review queues require a policy-protected review store.'),
            'age-verification' => $this->notConfiguredProjection([
                'minimum_age_policy', 'verification_state', 'underage_restriction_state', 'evidence_state',
            ], 'Age-verification state requires the canonical account and compliance services.'),
            'duplicate-accounts' => $this->notConfiguredProjection([
                'rule_state', 'linked_account_count', 'review_state', 'restriction_state', 'false_positive_review',
            ], 'Duplicate-account detection requires canonical risk signals; no match result is fabricated.'),
            'account-restrictions' => $this->notConfiguredProjection([
                'restriction_type', 'scope', 'reason', 'effective_at', 'expires_at', 'review_state',
            ], 'Account restrictions require an authorized canonical restriction ledger.'),
            'retention' => $this->notConfiguredProjection([
                'record_class', 'retention_period', 'legal_hold_state', 'deletion_state', 'last_review',
            ], 'Retention schedules and deletion evidence are not connected to this projection.'),
            'privacy' => $this->notConfiguredProjection([
                'consent_state', 'privacy_policy_version', 'data_processing_basis', 'sharing_state', 'withdrawal_state',
            ], 'Privacy and consent records require the canonical privacy service.'),
            'data-rights' => $this->notConfiguredProjection([
                'request_reference', 'request_type', 'identity_verification_state', 'fulfillment_state', 'due_at', 'completed_at',
            ], 'Data-rights request evidence requires a protected privacy request store.'),
            'legal-registries' => $this->notConfiguredProjection([
                'registry_name', 'jurisdiction', 'registration_state', 'renewal_at', 'evidence_reference',
            ], 'Legal registry evidence must come from the canonical compliance registry.'),
            'compliance-reporting' => $this->notConfiguredProjection([
                'report_type', 'period', 'submission_state', 'submission_reference', 'accepted_at',
            ], 'Compliance submissions are not claimed without submission evidence.'),
            'aml-monitoring' => $this->notConfiguredProjection([
                'rule_state', 'alert_count', 'case_count', 'review_state', 'reporting_state',
            ], 'AML monitoring evidence requires canonical alerts and case data.'),
            'regulatory-exports' => $this->notConfiguredProjection([
                'export_type', 'period', 'row_count', 'integrity_hash', 'delivery_state',
            ], 'Regulatory export evidence is not available without a canonical export registry.'),
            'compliance-audit' => $this->notConfiguredProjection([
                'control', 'owner', 'evidence_state', 'exception_state', 'last_review', 'next_review',
            ], 'Compliance control evidence requires an immutable audit source.'),
            default => [
                'state' => 'NOT_CONFIGURED',
                'rows' => [],
                'note' => 'The requested operational projection is not configured.',
            ],
        };
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function cutoverProjection(): array
    {
        $checks = [
            $this->artifactCheck('release_artifact', base_path('composer.lock')),
            $this->artifactCheck('asset_manifest', public_path('build/manifest.json')),
            $this->artifactCheck('dependency_lock', base_path('package-lock.json')),
            $this->artifactCheck('rust_lock', base_path('security/weekly-result-integrity/Cargo.lock')),
            [
                'label' => 'application_environment',
                'value' => (string) config('app.env', 'NOT_CONFIGURED'),
                'state' => config('app.env') !== null ? 'AVAILABLE' : 'NOT_CONFIGURED',
            ],
            [
                'label' => 'application_url',
                'value' => (string) (config('app.url') ?: 'NOT_CONFIGURED'),
                'state' => config('app.url') ? 'AVAILABLE' : 'NOT_CONFIGURED',
            ],
            $this->unverified('database_backup'),
            $this->unverified('database_migration'),
            $this->unverified('payment_provider_configuration'),
            $this->unverified('webhook_verification'),
            $this->unverified('wallet_ledger_reconciliation'),
            $this->unverified('kyc_private_storage'),
            $this->unverified('queue_workers'),
            $this->unverified('scheduler'),
            $this->unverified('rollback_readiness'),
        ];

        return [
            'state' => $this->aggregateState($checks),
            'rows' => $checks,
            'note' => 'A release gate is not marked complete unless the application has direct evidence for that gate.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function manifestProjection(): array
    {
        $rows = [
            $this->configuredMetadata('commit_sha', config('app.release_commit')),
            $this->configuredMetadata('build_id', config('app.release_build_id')),
            $this->configuredMetadata('application_version', config('app.version')),
            $this->configuredMetadata('migration_version', config('app.migration_version')),
            $this->artifactCheck('asset_build_fingerprint', public_path('build/manifest.json')),
            $this->artifactCheck('composer_lock_hash', base_path('composer.lock')),
            $this->artifactCheck('dependency_lock_hash', base_path('package-lock.json')),
            $this->artifactCheck('rust_binary_hash', base_path('security/weekly-result-integrity/target/release/weekly-result-integrity')),
            $this->configuredMetadata('environment_identifier', config('app.env')),
            [
                'label' => 'generated_at',
                'value' => now()->toIso8601String(),
                'state' => 'AVAILABLE',
            ],
        ];

        return [
            'state' => $this->aggregateState($rows),
            'rows' => $rows,
            'note' => 'Hashes are calculated only for files that exist in the application filesystem. Missing deploy metadata is not inferred.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function configurationProjection(): array
    {
        $rows = [
            $this->configuredMetadata('application_environment', config('app.env')),
            $this->configuredMetadata('application_url', config('app.url')),
            $this->configuredMetadata('database_driver', config('database.default')),
            $this->configuredMetadata('queue_driver', config('queue.default')),
            $this->configuredMetadata('cache_driver', config('cache.default')),
            $this->configuredMetadata('mail_driver', config('mail.default')),
            $this->configuredMetadata('payment_configuration', $this->hasConfiguredArray(config('payment')) ? 'configured' : null),
            $this->configuredMetadata('kyc_configuration', $this->hasConfiguredArray(config('account_verification')) ? 'configured' : null),
            $this->configuredMetadata('captcha_configuration', $this->hasConfiguredArray(config('captcha')) ? 'configured' : null),
            $this->configuredMetadata('storage_driver', config('filesystems.default')),
            $this->unverified('cdn_reverse_proxy'),
            $this->unverified('rust_engine_mode'),
        ];

        return [
            'state' => $this->aggregateState($rows),
            'rows' => $rows,
            'note' => 'Secret values and provider credentials are never returned by this projection.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function secretsProjection(): array
    {
        $rows = [
            [
                'label' => 'application_key_exists',
                'value' => is_string(config('app.key')) && config('app.key') !== '' ? 'true' : 'false',
                'state' => is_string(config('app.key')) && config('app.key') !== '' ? 'AVAILABLE' : 'NOT_CONFIGURED',
            ],
            $this->configuredMetadata('payment_provider_key_presence', $this->hasConfiguredArray(config('payment')) ? 'configured' : null),
            $this->configuredMetadata('webhook_secret_presence', $this->hasConfiguredArray(config('payment.webhooks')) ? 'configured' : null),
            $this->unverified('key_age'),
            $this->unverified('rotation_status'),
            $this->unverified('last_key_verification'),
        ];

        return [
            'state' => $this->aggregateState($rows),
            'rows' => $rows,
            'note' => 'Only presence and verification metadata are displayed. Secret material is never exposed.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function migrationProjection(): array
    {
        $migrationFiles = glob(database_path('migrations/*.php')) ?: [];
        $rows = [
            [
                'label' => 'migration_files_present',
                'value' => (string) count($migrationFiles),
                'state' => $migrationFiles !== [] ? 'AVAILABLE' : 'NO_DATA',
            ],
            $this->unverified('current_schema_version'),
            $this->unverified('pending_migrations'),
            $this->unverified('last_migration'),
            $this->unverified('migration_batch'),
            $this->unverified('destructive_migration_warning'),
            $this->unverified('migration_lock'),
        ];

        return [
            'state' => $this->aggregateState($rows),
            'rows' => $rows,
            'note' => 'Migration execution is intentionally excluded from browser operations.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function backupProjection(): array
    {
        $directory = storage_path('backups');
        $files = is_dir($directory) ? (glob($directory.'/*') ?: []) : [];
        $latest = collect($files)
            ->filter(static fn (string $path): bool => is_file($path))
            ->sortByDesc(static fn (string $path): int => (int) @filemtime($path))
            ->first();

        $rows = [
            [
                'label' => 'backup_directory',
                'value' => is_dir($directory) ? 'configured' : 'NOT_CONFIGURED',
                'state' => is_dir($directory) ? 'AVAILABLE' : 'NOT_CONFIGURED',
            ],
            [
                'label' => 'latest_backup_reference',
                'value' => is_string($latest) ? basename($latest) : 'NO_DATA',
                'state' => is_string($latest) ? 'AVAILABLE' : 'NO_DATA',
            ],
            [
                'label' => 'latest_backup_timestamp',
                'value' => is_string($latest) && is_file($latest) && filemtime($latest) !== false
                    ? date(DATE_ATOM, (int) filemtime($latest))
                    : 'NO_DATA',
                'state' => is_string($latest) ? 'AVAILABLE' : 'NO_DATA',
            ],
            $this->unverified('backup_checksum'),
            $this->unverified('retention_policy'),
            $this->unverified('encryption_state'),
            $this->unverified('restore_verification'),
        ];

        return [
            'state' => $this->aggregateState($rows),
            'rows' => $rows,
            'note' => 'A filesystem entry is not treated as a verified, restorable, encrypted backup without backup evidence.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function restoreProjection(): array
    {
        return [
            'state' => 'NOT_CONFIGURED',
            'rows' => [
                $this->unverified('restore_request_record'),
                $this->unverified('target_environment_validation'),
                $this->unverified('restore_artifact'),
                $this->unverified('restore_checksum'),
                $this->unverified('restore_operator'),
                $this->unverified('restore_outcome'),
            ],
            'note' => 'Browser-triggered production restore is disabled. A controlled non-production restore service is required before this surface can execute anything.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function disasterRecoveryProjection(): array
    {
        return [
            'state' => 'NOT_VERIFIED',
            'rows' => [
                $this->unverified('rpo_target'),
                $this->unverified('rto_target'),
                $this->unverified('backup_age'),
                $this->unverified('restore_verification'),
                $this->unverified('database_replica_state'),
                $this->unverified('queue_recovery_state'),
                $this->unverified('payment_callback_recovery'),
                $this->unverified('dns_recovery_state'),
                $this->unverified('rust_engine_recovery_state'),
            ],
            'note' => 'Disaster-recovery claims require deployment and infrastructure evidence not available to this application projection.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function highAvailabilityProjection(): array
    {
        return [
            'state' => 'NOT_VERIFIED',
            'rows' => [
                $this->unverified('database_primary'),
                $this->unverified('database_replica'),
                $this->unverified('redis'),
                $this->unverified('queue_workers'),
                $this->unverified('application_nodes'),
                $this->unverified('cdn'),
                $this->unverified('external_integrations'),
                $this->unverified('rust_engine'),
            ],
            'note' => 'No node count or failover health is inferred from configuration presence.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function incidentProjection(?string $reference): array
    {
        return [
            'state' => 'NOT_CONFIGURED',
            'rows' => [
                [
                    'label' => 'incident_reference',
                    'value' => $reference ?? 'NO_DATA',
                    'state' => $reference !== null ? 'NOT_CONFIGURED' : 'NO_DATA',
                ],
                $this->unverified('active_incidents'),
                $this->unverified('incident_severity'),
                $this->unverified('incident_component'),
                $this->unverified('incident_timeline'),
                $this->unverified('incident_assignee'),
                $this->unverified('incident_resolution'),
            ],
            'note' => 'No incident record is fabricated. Configure the canonical incident store before displaying cases.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function deploymentApprovalProjection(): array
    {
        return [
            'state' => 'NOT_CONFIGURED',
            'rows' => [
                $this->unverified('release_reference'),
                $this->unverified('release_checks'),
                $this->unverified('approver'),
                $this->unverified('approval_time'),
                $this->unverified('rejection_reason'),
                $this->unverified('blocking_findings'),
                $this->unverified('approval_state'),
            ],
            'note' => 'Release approval requires a canonical deployment approval record and is not a client-side toggle.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function deploymentHistoryProjection(): array
    {
        return [
            'state' => 'NOT_CONFIGURED',
            'rows' => [
                $this->unverified('deployment_version'),
                $this->unverified('deployment_commit'),
                $this->unverified('deployed_at'),
                $this->unverified('deployment_actor'),
                $this->unverified('deployment_environment'),
                $this->unverified('deployment_status'),
                $this->unverified('rollback_reference'),
            ],
            'note' => 'Deployment history must come from deployment metadata, not from a browser request.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function rollbackProjection(): array
    {
        return [
            'state' => 'NOT_CONFIGURED',
            'rows' => [
                $this->unverified('target_release'),
                $this->unverified('operator_reason'),
                $this->unverified('impact_acknowledgement'),
                $this->unverified('authorization'),
                $this->unverified('confirmation'),
                $this->unverified('audit_reference'),
            ],
            'note' => 'Arbitrary shell execution and browser-triggered rollback are disabled.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function featureFlagProjection(): array
    {
        $flags = config('features');
        $rows = [];

        if (is_array($flags) && $flags !== []) {
            foreach ($flags as $key => $value) {
                $rows[] = [
                    'label' => (string) $key,
                    'value' => is_scalar($value) ? (string) $value : 'configured',
                    'state' => 'AVAILABLE',
                ];
            }
        }

        return [
            'state' => $rows === [] ? 'NOT_CONFIGURED' : 'AVAILABLE',
            'rows' => $rows,
            'note' => 'Only configured server-side flags are displayed. Financial authority never moves into client-side flag state.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function configurationAuditProjection(): array
    {
        return [
            'state' => 'NOT_CONFIGURED',
            'rows' => [
                $this->unverified('configuration_key'),
                $this->unverified('category'),
                $this->unverified('old_state_reference'),
                $this->unverified('new_state_reference'),
                $this->unverified('actor'),
                $this->unverified('reason'),
                $this->unverified('timestamp'),
            ],
            'note' => 'A dedicated immutable configuration-change history is required before this view can expose records.',
        ];
    }

    /**
     * @param list<string> $labels
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function notConfiguredProjection(array $labels, string $note): array
    {
        return [
            'state' => 'NOT_CONFIGURED',
            'rows' => array_map(fn (string $label): array => $this->unverified($label), $labels),
            'note' => $note,
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function permissionMatrixProjection(): array
    {
        $permissions = config('permission.permissions');
        $roles = AdminAccess::audit()['panel_roles'] ?? [];
        $rows = [];

        if (is_array($permissions) && $permissions !== []) {
            $rows[] = [
                'label' => 'configured_permission_count',
                'value' => (string) count($permissions),
                'state' => 'AVAILABLE',
            ];
        } else {
            $rows[] = $this->unverified('configured_permission_count');
        }

        $rows[] = [
            'label' => 'admin_panel_role_count',
            'value' => is_array($roles) && $roles !== [] ? (string) count($roles) : 'NOT_CONFIGURED',
            'state' => is_array($roles) && $roles !== [] ? 'AVAILABLE' : 'NOT_CONFIGURED',
        ];
        $rows[] = $this->unverified('role_permission_operation_matrix');

        return [
            'state' => $this->aggregateState($rows),
            'rows' => $rows,
            'note' => 'The permission catalogue is read from configuration; operation-level allowance still requires policy/runtime verification.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function rateLimitProjection(): array
    {
        $sources = [
            'security' => config('security.rate_limits'),
            'account' => config('account.rate_limits'),
            'admin' => config('admin.rate_limits'),
        ];
        $rows = [];

        foreach ($sources as $source => $limits) {
            if (! is_array($limits) || $limits === []) {
                continue;
            }

            foreach ($limits as $name => $limit) {
                $rows[] = [
                    'label' => $source.'.'.(string) $name,
                    'value' => is_scalar($limit) ? (string) $limit : 'configured',
                    'state' => 'AVAILABLE',
                ];
            }
        }

        return [
            'state' => $rows === [] ? 'NOT_CONFIGURED' : 'AVAILABLE',
            'rows' => $rows,
            'note' => 'These are configuration projections only; server middleware remains the rate-limit authority.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function captchaProjection(): array
    {
        $captcha = config('auth_security.captcha');
        $configured = is_array($captcha) && $captcha !== [];

        return [
            'state' => $configured ? 'AVAILABLE' : 'NOT_CONFIGURED',
            'rows' => [
                $this->configuredMetadata('provider', is_array($captcha) ? ($captcha['provider'] ?? null) : null),
                $this->configuredMetadata('site_key_presence', is_array($captcha) && ! empty($captcha['site_key']) ? 'present' : null),
                $this->configuredMetadata('secret_presence', is_array($captcha) && ! empty($captcha['secret']) ? 'present' : null),
                $this->unverified('verification_state'),
                $this->unverified('challenge_failures'),
            ],
            'note' => 'CAPTCHA secret material is never rendered. Presence does not prove provider reachability.',
        ];
    }

    private function authorizeSurface(Request $request, string $surface): void
    {
        $operator = $request->user();
        $permission = in_array($surface, [
            'incidents', 'deployment-approval', 'deployments', 'configuration-audit', 'sessions', 'access-review',
            'privileged-access', 'permission-matrix', 'service-accounts', 'network-access', 'device-risk', 'mfa',
            'authentication-security', 'rate-limits', 'captcha', 'risk-rules', 'suspicious-activity', 'compliance-cases', 'sanctions',
            'kyc', 'kyc-provider', 'kyc-review', 'age-verification', 'duplicate-accounts', 'account-restrictions', 'retention',
            'privacy', 'data-rights', 'legal-registries', 'compliance-reporting', 'aml-monitoring', 'regulatory-exports', 'compliance-audit',
        ], true) ? AdminAccess::VIEW_AUDIT_LOGS : AdminAccess::MANAGE_SYSTEM_SETTINGS;

        if (! AdminAccess::canAccessPanel($operator) || ! AdminAccess::allows($operator, $permission)) {
            abort(403, trans('admin.access_denied'));
        }
    }

    /**
     * @return array{label: string, value: string, state: string}
     */
    private function artifactCheck(string $label, string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [
                'label' => $label,
                'value' => 'NOT_CONFIGURED',
                'state' => 'NOT_CONFIGURED',
            ];
        }

        $hash = hash_file('sha256', $path);

        return [
            'label' => $label,
            'value' => is_string($hash) ? $hash : 'UNAVAILABLE',
            'state' => is_string($hash) ? 'AVAILABLE' : 'UNAVAILABLE',
        ];
    }

    /**
     * @return array{label: string, value: string, state: string}
     */
    private function configuredMetadata(string $label, mixed $value): array
    {
        $configured = is_scalar($value) && trim((string) $value) !== '';

        return [
            'label' => $label,
            'value' => $configured ? (string) $value : 'NOT_CONFIGURED',
            'state' => $configured ? 'AVAILABLE' : 'NOT_CONFIGURED',
        ];
    }

    /**
     * @return array{label: string, value: string, state: string}
     */
    private function unverified(string $label): array
    {
        return [
            'label' => $label,
            'value' => 'NOT_VERIFIED',
            'state' => 'NOT_VERIFIED',
        ];
    }

    /**
     * @param list<array{label: string, value: string, state: string}> $rows
     */
    private function aggregateState(array $rows): string
    {
        if ($rows === []) {
            return 'NO_DATA';
        }

        foreach ($rows as $row) {
            if (in_array($row['state'], ['NOT_VERIFIED', 'UNAVAILABLE'], true)) {
                return 'NOT_VERIFIED';
            }
        }

        foreach ($rows as $row) {
            if ($row['state'] === 'NOT_CONFIGURED') {
                return 'NOT_CONFIGURED';
            }
        }

        return 'AVAILABLE';
    }

    private function hasConfiguredArray(mixed $value): bool
    {
        return is_array($value) && $value !== [];
    }
}

```

## FILE 3: `resources/views/admin/release-operations.blade.php`

# TYPE: Blade view
# PURPOSE: Localized admin operational evidence table with truthful state rendering and no browser mutation controls.

```php
@extends('layouts.admin')

@section('title', trans('admin_release.title'))
@section('robots', 'noindex, nofollow')

@section('content')
<div class="flex w-full flex-col gap-6" aria-labelledby="release-operations-title">
    <header>
        <p class="text-xs uppercase tracking-[0.2em] text-emerald-400">{{ trans('admin_release.eyebrow') }}</p>
        <h1 id="release-operations-title" class="lf-page-title">{{ trans('admin_release.surface_'.$surface) }}</h1>
        <p class="mt-2 max-w-4xl text-sm text-slate-400">{{ $projection['note'] }}</p>
    </header>

    <section class="lf-panel-card" role="status" aria-live="polite" aria-labelledby="release-state-title">
        <div class="lf-panel-header">
            <h2 id="release-state-title" class="lf-panel-title">{{ trans('admin_release.state') }}</h2>
            <strong class="lf-kpi-value">{{ $projection['state'] }}</strong>
        </div>
        @if ($reference !== null)
            <p class="mt-2 text-sm text-slate-400"><span class="font-semibold">{{ trans('admin_release.reference') }}:</span> <span class="font-mono">{{ $reference }}</span></p>
        @endif
    </section>

    <section class="lf-panel-card" aria-labelledby="release-evidence-title">
        <div class="lf-panel-header">
            <h2 id="release-evidence-title" class="lf-panel-title">{{ trans('admin_release.evidence') }}</h2>
            <span class="text-xs text-slate-400">{{ count($projection['rows']) }} {{ trans('admin_release.records') }}</span>
        </div>
        <div class="lf-table-container">
            <table class="lf-table">
                <caption class="sr-only">{{ trans('admin_release.evidence') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ trans('admin_release.item') }}</th>
                        <th scope="col">{{ trans('admin_release.value') }}</th>
                        <th scope="col">{{ trans('admin_release.state') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($projection['rows'] as $row)
                        <tr>
                            <th scope="row" class="font-mono text-xs">{{ $row['label'] }}</th>
                            <td class="max-w-3xl break-all font-mono text-xs">{{ $row['value'] }}</td>
                            <td>{{ $row['state'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-8 text-center text-slate-400" role="status">{{ trans('admin_release.no_data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="lf-panel-card" aria-labelledby="release-safety-title">
        <h2 id="release-safety-title" class="lf-panel-title">{{ trans('admin_release.safety_title') }}</h2>
        <p class="mt-3 text-sm text-slate-400">{{ trans('admin_release.safety_body') }}</p>
    </section>
</div>
@endsection

```

## FILE 4: `lang/en/admin_release.php`

# TYPE: PHP translation map
# PURPOSE: English labels for the Pages 150–194 operations surface; exact key parity with Thai.

```php
<?php

declare(strict_types=1);

return [
    'title' => 'Release and operational controls',
    'eyebrow' => 'RELEASE OPERATIONS',
    'state' => 'State',
    'reference' => 'Reference',
    'evidence' => 'Evidence projection',
    'records' => 'records',
    'item' => 'Item',
    'value' => 'Value',
    'no_data' => 'NO_DATA',
    'safety_title' => 'Browser safety boundary',
    'safety_body' => 'This page is read-only. It does not run migrations, restore databases, flush caches, rotate keys, execute shell commands, or change release state from the browser.',
    'surface_cutover' => 'Production cutover control center',
    'surface_manifest' => 'Release manifest',
    'surface_configuration' => 'Environment and configuration matrix',
    'surface_secrets' => 'Secrets and key-management status',
    'surface_migrations' => 'Database migration control',
    'surface_backups' => 'Database backup control',
    'surface_restore' => 'Backup restore verification',
    'surface_disaster-recovery' => 'Disaster recovery center',
    'surface_high-availability' => 'Failover and high-availability status',
    'surface_incidents' => 'Incident command center',
    'surface_deployment-approval' => 'Deployment approval gate',
    'surface_deployments' => 'Deployment history',
    'surface_rollback' => 'Rollback control',
    'surface_feature-flags' => 'Feature flag operations',
    'surface_configuration-audit' => 'Configuration change audit',
    'surface_runtime' => 'Runtime operations',
    'surface_sessions' => 'Operator sessions',
    'surface_access-review' => 'Operator access review',
    'surface_privileged-access' => 'Privileged access',
    'surface_permission-matrix' => 'Permission matrix',
    'surface_service-accounts' => 'Service accounts',
    'surface_network-access' => 'Network access controls',
    'surface_device-risk' => 'Device and session risk',
    'surface_mfa' => 'Multi-factor authentication',
    'surface_authentication-security' => 'Authentication security',
    'surface_rate-limits' => 'Rate limits',
    'surface_captcha' => 'CAPTCHA controls',
    'surface_risk-rules' => 'Fraud and risk rules',
    'surface_suspicious-activity' => 'Suspicious activity',
    'surface_compliance-cases' => 'Compliance cases',
    'surface_sanctions' => 'Sanctions and watchlists',
    'surface_kyc' => 'KYC and identity verification',
    'surface_kyc-provider' => 'KYC provider status',
    'surface_kyc-review' => 'KYC review queue',
    'surface_age-verification' => 'Age verification',
    'surface_duplicate-accounts' => 'Duplicate account controls',
    'surface_account-restrictions' => 'Account restrictions',
    'surface_retention' => 'Retention controls',
    'surface_privacy' => 'Privacy and consent',
    'surface_data-rights' => 'Data rights requests',
    'surface_legal-registries' => 'Legal registries',
    'surface_compliance-reporting' => 'Compliance reporting',
    'surface_aml-monitoring' => 'AML monitoring',
    'surface_regulatory-exports' => 'Regulatory exports',
    'surface_compliance-audit' => 'Compliance audit',
];

```

## FILE 5: `lang/th/admin_release.php`

# TYPE: PHP translation map
# PURPOSE: Thai-locale map with exact key and placeholder parity with English.

```php
<?php

declare(strict_types=1);

return [
    'title' => 'Release and operational controls',
    'eyebrow' => 'RELEASE OPERATIONS',
    'state' => 'State',
    'reference' => 'Reference',
    'evidence' => 'Evidence projection',
    'records' => 'records',
    'item' => 'Item',
    'value' => 'Value',
    'no_data' => 'NO_DATA',
    'safety_title' => 'Browser safety boundary',
    'safety_body' => 'This page is read-only. It does not run migrations, restore databases, flush caches, rotate keys, execute shell commands, or change release state from the browser.',
    'surface_cutover' => 'Production cutover control center',
    'surface_manifest' => 'Release manifest',
    'surface_configuration' => 'Environment and configuration matrix',
    'surface_secrets' => 'Secrets and key-management status',
    'surface_migrations' => 'Database migration control',
    'surface_backups' => 'Database backup control',
    'surface_restore' => 'Backup restore verification',
    'surface_disaster-recovery' => 'Disaster recovery center',
    'surface_high-availability' => 'Failover and high-availability status',
    'surface_incidents' => 'Incident command center',
    'surface_deployment-approval' => 'Deployment approval gate',
    'surface_deployments' => 'Deployment history',
    'surface_rollback' => 'Rollback control',
    'surface_feature-flags' => 'Feature flag operations',
    'surface_configuration-audit' => 'Configuration change audit',
    'surface_runtime' => 'Runtime operations',
    'surface_sessions' => 'Operator sessions',
    'surface_access-review' => 'Operator access review',
    'surface_privileged-access' => 'Privileged access',
    'surface_permission-matrix' => 'Permission matrix',
    'surface_service-accounts' => 'Service accounts',
    'surface_network-access' => 'Network access controls',
    'surface_device-risk' => 'Device and session risk',
    'surface_mfa' => 'Multi-factor authentication',
    'surface_authentication-security' => 'Authentication security',
    'surface_rate-limits' => 'Rate limits',
    'surface_captcha' => 'CAPTCHA controls',
    'surface_risk-rules' => 'Fraud and risk rules',
    'surface_suspicious-activity' => 'Suspicious activity',
    'surface_compliance-cases' => 'Compliance cases',
    'surface_sanctions' => 'Sanctions and watchlists',
    'surface_kyc' => 'KYC and identity verification',
    'surface_kyc-provider' => 'KYC provider status',
    'surface_kyc-review' => 'KYC review queue',
    'surface_age-verification' => 'Age verification',
    'surface_duplicate-accounts' => 'Duplicate account controls',
    'surface_account-restrictions' => 'Account restrictions',
    'surface_retention' => 'Retention controls',
    'surface_privacy' => 'Privacy and consent',
    'surface_data-rights' => 'Data rights requests',
    'surface_legal-registries' => 'Legal registries',
    'surface_compliance-reporting' => 'Compliance reporting',
    'surface_aml-monitoring' => 'AML monitoring',
    'surface_regulatory-exports' => 'Regulatory exports',
    'surface_compliance-audit' => 'Compliance audit',
];

```

## FILE 6: `routes/web.php`

# TYPE: PHP route file
# PURPOSE: Preserves existing routes and adds named, admin-protected Pages 150–194 operational routes; existing canonical Pages 195–250 routes remain delegated to their controllers.

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\LottoFinExecutiveDashboardController;
use App\Http\Controllers\Admin\ReleaseOperationsController;
use App\Http\Controllers\Agent\AgentPortalController;
use App\Http\Controllers\NotificationCenterController;
use App\Http\Controllers\Support\SupportPortalController;
use App\Http\Controllers\GloL6Controller;
use App\Http\Controllers\GloResultsPageController;
use App\Http\Controllers\Player\PlayerSecuritySettingsController;
use App\Http\Controllers\Betting\ThaiLotteryBettingController;
use App\Http\Controllers\AccountGradeController;
use App\Http\Controllers\BingoLotteryController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LottoDiscountController;
use App\Http\Controllers\LotteryHubController;
use App\Http\Controllers\LotteryPurchasePageController;
use App\Http\Controllers\LegacyRedirectController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\NationalLotteryController;
use App\Http\Controllers\PcsoLotteryController;
use App\Http\Controllers\PrizeVerificationController;
use App\Http\Controllers\PublicAccountInfoController;
use App\Http\Controllers\PublicPagesController;
use App\Http\Controllers\PublicServicePagesController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\Auth\MemberAuthController;
use App\Http\Controllers\Verification\AccountVerificationController as MemberAccountVerificationController;
use App\Http\Controllers\Web\BetPurchaseController;
use App\Http\Controllers\Web\PaymentCallbackController;
use App\Http\Controllers\Web\PlayerWebController;
use App\Http\Controllers\WeeklyLotteryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The session-authenticated player web app. Laravel's default web middleware group is
| applied automatically (CSRF, session, cookies), plus the global security headers and
| correlation id middleware registered in bootstrap/app.php.
|
| Every route name here is what the Blade views and the player experience tests already
| reference, so the names are part of the contract:
|   login, login.attempt, register, register.attempt, logout,
|   player.dashboard, player.draws, player.draws.detail, player.bet, player.bets,
|   player.wallet, player.deposit, player.deposit.store, player.withdraw,
|   player.withdraw.store, player.profile, player.profile.update, player.profile.password,
|   player.profile.limits, player.bets.purchase, player.password.update, player.limits.update
|
*/

/*
| Operational endpoints. `/up` is the framework liveness probe registered in
| bootstrap/app.php; the structured health trio and the Prometheus metrics export live
| here against the same HealthController / MetricsController that the observability
| services back.
*/
// P0: /metrics is operator-only telemetry — never financial-public.
Route::middleware(['auth', 'can:access-metrics'])->group(function (): void {
    Route::get('/metrics', [MetricsController::class, 'metrics'])->name('metrics');
});
Route::get('/up/health', [HealthController::class, 'health'])->name('health');
Route::get('/up/ready', [HealthController::class, 'ready'])->name('health.ready');
Route::get('/up/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health', [HealthController::class, 'health'])->name('health.canonical');
Route::get('/ready', [HealthController::class, 'ready'])->name('health.ready.canonical');
Route::get('/live', [HealthController::class, 'live'])->name('health.live.canonical');

Route::middleware('guest')->group(function (): void {
    // PROMPT 3: the member auth surface (login / registration /
    // password recovery) is served by MemberAuthController — thin
    // orchestration over LoginService / RegistrationService /
    // PasswordResetService (+ the server-authoritative CaptchaService
    // gate). Same route names as before, so every existing link,
    // redirect and test keeps resolving.
    Route::get('/login', [MemberAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [MemberAuthController::class, 'login'])->name('login.attempt')->middleware('throttle:login');
    Route::get('/register', [MemberAuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [MemberAuthController::class, 'register'])->name('register.attempt')->middleware('throttle:login');

    // Password recovery: account no./email + CAPTCHA request, then the
    // token-gated new-password form. Throttled on both POSTs.
    Route::get('/forgot-password', [MemberAuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [MemberAuthController::class, 'requestReset'])
        ->middleware('throttle:password-reset')
        ->name('password.request.attempt');
    Route::get('/reset-password/{token}', [MemberAuthController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [MemberAuthController::class, 'resetPassword'])
        ->middleware('throttle:password-reset')
        ->name('password.reset.attempt');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [MemberAuthController::class, 'logout'])->name('logout');

    Route::get('/dashboard', [PlayerWebController::class, 'dashboard'])->name('player.dashboard');
    Route::get('/draws', [PlayerWebController::class, 'draws'])->name('player.draws');
    Route::get('/draws/{id}', [PlayerWebController::class, 'drawDetail'])->name('player.draws.detail');

    Route::get('/bet', [PlayerWebController::class, 'betSlip'])->name('player.bet');
    Route::post('/bet/purchase', [BetPurchaseController::class, 'store'])
        ->middleware('throttle:bet')
        ->name('player.bets.purchase');
    Route::post('/player/bets/purchase', [BetPurchaseController::class, 'store'])
        ->middleware('throttle:bet')
        ->name('player.bets.purchase.alias');
    Route::get('/bets', [PlayerWebController::class, 'bets'])->name('player.bets');

    Route::get('/wallet', [PlayerWebController::class, 'wallet'])->name('player.wallet');

    Route::get('/deposit', [PlayerWebController::class, 'deposit'])->name('player.deposit');
    Route::get('/deposit/status/{deposit}', [PlayerWebController::class, 'depositStatus'])
        ->where('deposit', '[A-Za-z0-9_\\-]{1,80}')
        ->name('player.deposit.status');
    Route::post('/deposit', [PlayerWebController::class, 'storeDeposit'])
        ->middleware('throttle:deposit')
        ->name('player.deposit.store');

    Route::get('/withdraw', [PlayerWebController::class, 'withdraw'])->name('player.withdraw');
    Route::get('/withdrawal/status/{withdrawal}', [PlayerWebController::class, 'withdrawalStatus'])
        ->where('withdrawal', '[A-Za-z0-9_\\-]{1,80}')
        ->name('player.withdrawal.status');
    Route::post('/withdraw', [PlayerWebController::class, 'storeWithdraw'])
        ->middleware('throttle:withdrawal')
        ->name('player.withdraw.store');

    Route::get('/profile', [PlayerWebController::class, 'profile'])->name('player.profile');
    Route::put('/profile', [PlayerWebController::class, 'updateProfile'])->name('player.profile.update');
    Route::put('/profile/password', [PlayerWebController::class, 'updatePassword'])->name('player.password.update');
    Route::put('/player/profile/password', [PlayerWebController::class, 'updatePassword'])->name('player.profile.password');
    Route::put('/profile/limits', [PlayerWebController::class, 'updateLimits'])->name('player.limits.update');
    Route::put('/player/profile/limits', [PlayerWebController::class, 'updateLimits'])->name('player.profile.limits');
    Route::post('/player/self-exclusion', [PlayerWebController::class, 'storeSelfExclusion'])
        ->middleware('throttle:account-grade')
        ->name('player.self-exclusion.store');
});

/*
|---------------------------------------------------------------------------
| Account services (PROMPT 3): verification + grade — authenticated only
|---------------------------------------------------------------------------
| Ownership is always the session user. Rate limits: account-verification /
| account-grade (registered in AppServiceProvider).
*/
Route::middleware('auth')->group(function (): void {
    // PROMPT 3: the member Account Verify page is served by the
    // Verification controller (policy-authorized, self-scoped, the
    // immutable submission aggregate behind it). The reviewer decision
    // route is policy-walled (AccountVerificationPolicy::decide).
    Route::get('/account/verification', [MemberAccountVerificationController::class, 'show'])
        ->name('account.verification');
    Route::post('/account/verification', [MemberAccountVerificationController::class, 'submit'])
        ->middleware('throttle:account-verification')
        ->name('account.verification.submit');
    Route::get('/account/verification/document/{documentToken}', [MemberAccountVerificationController::class, 'download'])
        ->middleware('throttle:account-verification')
        ->name('account.verification.document');
    Route::post('/account/verification/{verification}/decision', [MemberAccountVerificationController::class, 'decide'])
        ->middleware('throttle:account-verification')
        ->name('account.verification.decide');

    Route::get('/account/grade', [AccountGradeController::class, 'show'])
        ->middleware('throttle:account-grade')
        ->name('account.grade');
    Route::get('/account/grade/history', [AccountGradeController::class, 'history'])
        ->middleware('throttle:account-grade')
        ->name('account.grade.history');
    Route::post('/account/grade/refresh', [AccountGradeController::class, 'refresh'])
        ->middleware('throttle:account-grade')
        ->name('account.grade.refresh');
});

/*
|--------------------------------------------------------------------------
| Public Home + supporting public pages (anonymous by design)
|--------------------------------------------------------------------------
| Results are served from the verified projection only; fixture datasets are
| labeled FIXTURE_ONLY and are never called official.
*/

Route::get('/', [HomeController::class, 'index'])->name('home');

// Legacy aliases deliberately redirect into the authenticated canonical player
// routes. They do not render a second wallet, deposit, withdrawal or dashboard
// implementation and therefore cannot expose presentation-only financial data.
Route::get('/player/dashboard', fn () => redirect()->route('player.dashboard'))->name('player.dashboard.legacy');
Route::get('/player/wallet', fn () => redirect()->route('player.wallet'))->name('player.wallet.legacy');
Route::get('/wallet/deposit', fn () => redirect()->route('player.deposit'))->name('wallet.deposit');
Route::get('/withdrawal', fn () => redirect()->route('player.withdraw'))->name('withdrawal.index');
Route::get('/wallet/withdrawal', fn () => redirect()->route('player.withdraw'))->name('wallet.withdrawal');
Route::get('/betting', [ThaiLotteryBettingController::class, 'index'])->name('betting.index');
Route::get('/lotto/betting', [ThaiLotteryBettingController::class, 'index'])->name('lotto.betting');
// Dedicated GLO L6 home. It uses the canonical public GLO services and is
// intentionally separate from the legacy /results page, whose historical
// controller is not a source for live GLO data.
Route::get('/glo-l6', [GloL6Controller::class, 'index'])
    ->middleware('public.legal')
    ->name('glo-l6.index');
Route::get('/glo-l6/buy', [GloL6Controller::class, 'buy'])
    ->middleware('public.legal')
    ->name('glo-l6.buy');
Route::get('/glo-l6/latest', [GloL6Controller::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('glo-l6.latest');
Route::get('/glo-l6/history', [GloL6Controller::class, 'history'])
    ->middleware('public.legal')
    ->name('glo-l6.history');
Route::get('/glo-l6/year/{year}', [GloL6Controller::class, 'year'])
    ->where('year', '[0-9]{4}')
    ->middleware('public.legal')
    ->name('glo-l6.year');
Route::get('/glo-l6/draw/{draw}', [GloL6Controller::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9_\\-]{1,64}')
    ->middleware('public.legal')
    ->name('glo-l6.draw');
Route::get('/glo-l6/result/{draw}', [GloL6Controller::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9_\\-]{1,64}')
    ->middleware('public.legal')
    ->name('glo-l6.result');

Route::get('/results', [GloResultsPageController::class, 'index'])->name('results.index');

// Account and protection aliases are authenticated. They delegate to the
// canonical player/profile, responsible-gaming and security architecture;
// legacy guest pages are not allowed to invent account state.
Route::middleware('auth')->group(function (): void {
    Route::get('/player/security', [PlayerSecuritySettingsController::class, 'index'])->name('player.security');
    Route::get('/player/settings', [PlayerSecuritySettingsController::class, 'index'])->name('player.settings');
    Route::get('/settings', [PlayerWebController::class, 'responsibleGaming'])->name('settings.index');
    Route::get('/member/settings', [PlayerWebController::class, 'responsibleGaming'])->name('member.settings');
    Route::get('/player/settings-portal', [PlayerWebController::class, 'responsibleGaming'])->name('player.settings.portal');
    Route::get('/member/profile', fn () => redirect()->route('player.profile'))->name('member.profile');
    Route::get('/player/profile-portal', fn () => redirect()->route('player.profile'))->name('player.profile.portal');
});
Route::middleware('auth')->group(function (): void {
    Route::get('/history', fn () => redirect()->route('player.bets'))->name('history.index');
    Route::get('/member/history', fn () => redirect()->route('player.bets'))->name('member.history');
    Route::get('/player/history-portal', fn () => redirect()->route('player.bets'))->name('player.history.portal');
});
Route::get('/results/search', [ResultsController::class, 'search'])->name('results.search');

// Public ticket check UI (primary UX; the JSON API remains at /api/v1/glo/results/check/{n}).
Route::get('/check', [HomeController::class, 'checkForm'])->name('ticket-check');
Route::post('/check', [HomeController::class, 'checkSubmit'])
    ->middleware('throttle:home-check')
    ->name('ticket-check.submit');

// Public sales-point search UI (uses existing GloSalesPointService).
Route::get('/sales-points', [HomeController::class, 'salesPoints'])->name('sales-points');

// Public informational + legal pages (versioned Terms from config/legal.php).
// public.legal = PublicLegalHeaders middleware: safe guest GET cache only.
Route::get('/about', [PublicPagesController::class, 'about'])
    ->middleware('public.legal')
    ->name('about');
Route::get('/vision', [PublicPagesController::class, 'vision'])
    ->middleware('public.legal')
    ->name('vision');
Route::get('/terms', [PublicPagesController::class, 'terms'])
    ->middleware('public.legal')
    ->name('terms');

// Public Fees (PROMPT 3) — anonymous, config-driven, no user-specific fees.
Route::get('/fees', [PublicPagesController::class, 'fees'])
    ->middleware('public.legal')
    ->name('fees');
Route::get('/our-fees', [PublicPagesController::class, 'fees'])
    ->middleware('public.legal')
    ->name('our-fees');

// Public Prize Verification (PROMPT 4) — anonymous ticket / result checker.
Route::get('/prize-verification', [\App\Http\Controllers\PublicPrizeVerificationController::class, 'index'])
    ->middleware('public.legal')
    ->name('prize-verification');
Route::post('/prize-verification', [\App\Http\Controllers\PublicPrizeVerificationController::class, 'verifyApi'])
    ->middleware('throttle:ticket-verification')
    ->name('prize-verification.verify');
Route::post('/prize-verification', [\App\Http\Controllers\PublicPrizeVerificationController::class, 'verifyApi'])
    ->middleware('throttle:ticket-verification')
    ->name('prize-verification.submit');

// Public Discount Rules (PROMPT 4) — anonymous product/game matrix.
Route::get('/discounts', [\App\Http\Controllers\PublicLottoDiscountController::class, 'index'])
    ->middleware('public.legal')
    ->name('discounts');
Route::get('/lotto-discount', [\App\Http\Controllers\PublicLottoDiscountController::class, 'index'])
    ->middleware('public.legal')
    ->name('lotto-discount');

// Public How to Play Guide
Route::get('/how-to-play', [\App\Http\Controllers\PublicHowToPlayController::class, 'index'])
    ->middleware('public.legal')
    ->name('how-to-play');

// Public FAQ / Knowledge Base
Route::get('/faq', [\App\Http\Controllers\PublicFaqController::class, 'index'])
    ->middleware('public.legal')
    ->name('faq');

/*
|--------------------------------------------------------------------------
| PROMPT 5: public National Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. These four routes serve national_lottery_* data and
| nothing else: not GLO L6/N3, not an operator market, not a lottery provider
| that has not published. They read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search, /buy, /latest, /history, /year/{year},
| /archive/{year}, /draw/{draw} and /result/{draw} are declared BEFORE /{draw}.
| Reversed, the wildcard would capture a literal page segment and turn it into
| a draw lookup.
|
| PARAMETER PATTERNS ARE HARD BOUNDARIES. {year} is at most four decimal
| digits and {draw} is at most forty characters of an explicit alphabet, so a
| hostile URL is refused by the router before a controller, a validator or a
| query is ever reached.
|
| /search carries throttle:national-result-search (registered in
| AppServiceProvider from config('national_lottery.rate_limit')): IP per
| minute, IP per hour, and a hashed query fingerprint per minute. robots.txt
| asks crawlers to stay out of the same path, but that is a request - this
| limiter is the control.
|
| public.legal = PublicLegalHeaders: short guest-GET cache headers on the
| listed cacheable paths only. The search route is deliberately outside that
| list, so a query-dependent response is never cached at the edge.
*/
Route::get('/lotteries', [LotteryHubController::class, 'index'])
    ->middleware('public.legal')
    ->name('lotteries.index');

Route::get('/national-lottery', [NationalLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('national-lottery.index');

Route::get('/national-lottery/buy', [LotteryPurchasePageController::class, 'national'])
    ->middleware('public.legal')
    ->name('national-lottery.buy');

Route::get('/national-lottery/latest', [NationalLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('national-lottery.latest');

Route::get('/national-lottery/history', [NationalLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('national-lottery.history');

Route::get('/national-lottery/draw/{draw}', [NationalLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('national-lottery.draw-detail');

Route::get('/national-lottery/result/{draw}', [NationalLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('national-lottery.result-detail');

Route::get('/national-lottery/search', [NationalLotteryController::class, 'search'])
    ->middleware('throttle:national-result-search')
    ->name('national-lottery.search');

Route::get('/national-lottery/year/{year}', [NationalLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('national-lottery.year');

Route::get('/national-lottery/archive/{year}', [NationalLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('national-lottery.year-archive');

Route::get('/national-lottery/{draw}', [NationalLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('national-lottery.show');

/*
|--------------------------------------------------------------------------
| PROMPT 6: public Weekly Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. These canonical and page-specific routes serve
| weekly_lottery_* data and nothing else: not GLO L6/N3, not the National lane,
| not an operator market. They read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search and /year/{year} are declared BEFORE
| /{draw}. Reversed, the wildcard would match the literal string "search" and
| a visitor asking to search would get a draw-not-found page for a draw named
| "search".
|
| PARAMETER PATTERNS ARE HARD BOUNDARIES. {year} is at most four decimal
| digits and {draw} is at most forty characters of an explicit alphabet, so a
| hostile URL is refused by the router before a controller, a validator or a
| query is ever reached.
|
| /search carries throttle:weekly-result-search (registered in
| AppServiceProvider from config('weekly_lottery.rate_limit')): IP per minute,
| IP per hour, and a hashed query fingerprint per minute. A public lookup over
| a 1,000,000-value space is an enumeration oracle without it.
|
| public.legal = PublicLegalHeaders: short guest-GET cache headers on the
| listed cacheable paths only. The search route is deliberately outside that
| list, so a query-dependent response is never cached at the edge.
*/
Route::get('/weekly-lottery', [WeeklyLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('weekly-lottery.index');

Route::get('/weekly-lottery/buy', [LotteryPurchasePageController::class, 'weekly'])
    ->middleware('public.legal')
    ->name('weekly-lottery.buy');

Route::get('/weekly-lottery/search', [WeeklyLotteryController::class, 'search'])
    ->middleware('throttle:weekly-result-search')
    ->name('weekly-lottery.search');

Route::get('/weekly-lottery/latest', [WeeklyLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('weekly-lottery.latest');

Route::get('/weekly-lottery/history', [WeeklyLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('weekly-lottery.history');

Route::get('/weekly-lottery/archive/{year}', [WeeklyLotteryController::class, 'yearArchive'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('weekly-lottery.archive');

Route::get('/weekly-lottery/draw/{draw}', [WeeklyLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('weekly-lottery.draw');

Route::get('/weekly-lottery/result/{draw}', [WeeklyLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('weekly-lottery.result');

Route::get('/weekly-lottery/year/{year}', [WeeklyLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('weekly-lottery.year');

Route::get('/weekly-lottery/{draw}', [WeeklyLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('weekly-lottery.show');

/*
|--------------------------------------------------------------------------
| PROMPT 8: public Bingo / Mega Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. These canonical and page-specific routes serve
| bingo_lottery_* data and nothing else: not GLO L6/N3, not the National lane,
| not the Weekly lane, not an operator market. They read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search and /year/{year} are declared BEFORE
| /{draw}. Reversed, the wildcard would match the literal string "search" and
| a visitor asking to search would get a draw-not-found page for a draw named
| "search".
|
| /search carries throttle:bingo-result-search (registered in
| AppServiceProvider from config('bingo_lottery.rate_limit')): IP per minute,
| IP per hour, and a hashed query fingerprint per minute. robots.txt asks
| crawlers to stay out of the same path, but that is a request - this limiter
| is the control.
|
*/
Route::get('/bingo-lottery', [BingoLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('bingo-lottery.index');

Route::get('/bingo-lottery/search', [BingoLotteryController::class, 'search'])
    ->middleware('throttle:bingo-result-search')
    ->name('bingo-lottery.search');

Route::get('/bingo-lottery/buy', [BingoLotteryController::class, 'buy'])
    ->middleware('public.legal')
    ->name('bingo-lottery.buy');

Route::get('/bingo-lottery/latest', [BingoLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('bingo-lottery.latest');

Route::get('/bingo-lottery/history', [BingoLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('bingo-lottery.history');

Route::get('/bingo-lottery/archive/{year}', [BingoLotteryController::class, 'yearArchive'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('bingo-lottery.archive');

Route::get('/bingo-lottery/draw/{draw}', [BingoLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('bingo-lottery.draw');

Route::get('/bingo-lottery/result/{draw}', [BingoLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('bingo-lottery.result');

Route::get('/bingo-lottery/year/{year}', [BingoLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('bingo-lottery.year');

Route::get('/bingo-lottery/{draw}', [BingoLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('bingo-lottery.show');

/*
|--------------------------------------------------------------------------
| PROMPT 9: public PCSO Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. Four routes over pcso_lottery_* data: not GLO
| L6/N3, not National, not Weekly, not Mega, not an operator market. They
| read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search and /year/{year} are declared BEFORE
| /{draw}. Reversed, the wildcard would match the literal string "search" and
| a visitor asking to search would get a draw-not-found page for a draw named
| "search".
|
| The {draw} pattern allows the longer PCSO reference, which carries a draw
| TIME as well as a date (PCSO-20260910-2100) because this lane publishes
| several draws per day.
|
| /search carries throttle:pcso-result-search. robots.txt asks crawlers to
| stay out of the same path, but that is a request - this limiter is the
| control.
|
*/

Route::get('/pcso-lottery', [PcsoLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('pcso-lottery.index');

Route::get('/pcso-lottery/search', [PcsoLotteryController::class, 'search'])
    ->middleware('throttle:pcso-result-search')
    ->name('pcso-lottery.search');

Route::get('/pcso-lottery/buy', [PcsoLotteryController::class, 'buy'])
    ->middleware('public.legal')
    ->name('pcso-lottery.buy');

Route::get('/pcso-lottery/latest', [PcsoLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('pcso-lottery.latest');

Route::get('/pcso-lottery/history', [PcsoLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('pcso-lottery.history');

// /year/{year} is canonical. /archive/{year} is retained as a compatibility
// alias and is declared before both detail wildcards.
Route::get('/pcso-lottery/year/{year}', [PcsoLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('pcso-lottery.year');

Route::get('/pcso-lottery/archive/{year}', [PcsoLotteryController::class, 'yearArchive'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('pcso-lottery.archive');

Route::get('/pcso-lottery/draw/{draw}', [PcsoLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('pcso-lottery.draw');

Route::get('/pcso-lottery/result/{draw}', [PcsoLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('pcso-lottery.result');

// Original compatibility route; every named detail route above wins first.
Route::get('/pcso-lottery/{draw}', [PcsoLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('pcso-lottery.show');

// Static pages used by footer/support CTAs when configured.
Route::get('/privacy', [PublicPagesController::class, 'privacy'])
    ->middleware('public.legal')
    ->name('privacy');

/*
|--------------------------------------------------------------------------
| PROMPT 10: public Contact / Support centre
|--------------------------------------------------------------------------
|
| The GET route KEEPS ITS NAME. About, both footers, the privacy page and the
| terms page all link to route('contact'), and existing tests assert those
| links resolve. Renaming it to something tidier would have broken five
| surfaces to gain nothing.
|
| The POST carries throttle:contact-submit. A public endpoint that sends mail
| is a relay without one. It is also inside the normal web middleware group,
| so Laravel's CSRF protection applies - deliberately not excluded to make an
| AJAX submission simpler.
|
*/

/*
|--------------------------------------------------------------------------
| Public account-programme explainers
|--------------------------------------------------------------------------
|
| SIGNED-OUT INFORMATION, NOT THE ACCOUNT PAGES. /account/grade and
| /account/verification stay behind auth and show a person their own figures.
| These two show the LADDER and the PROCESS to somebody who has not
| registered and therefore cannot see either.
|
| Separate paths on purpose: relaxing auth on the existing routes would have
| meant one URL answering differently depending on who asked, which is how a
| personal figure eventually renders for a guest.
|
*/

Route::get('/account-grades', [\App\Http\Controllers\PublicGradeController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-grades');

Route::get('/account-grade', [\App\Http\Controllers\PublicGradeController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-grade');

Route::get('/account-verification', [\App\Http\Controllers\PublicVerificationController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-verification');

Route::get('/account-verification-guide', [\App\Http\Controllers\PublicVerificationController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-verification-guide');

Route::get('/contact', [\App\Http\Controllers\ContactController::class, 'show'])
    ->middleware('public.legal')
    ->name('contact');

Route::get('/contact-us', [\App\Http\Controllers\ContactController::class, 'show'])
    ->middleware('public.legal')
    ->name('contact-us');

Route::get('/download', [\App\Http\Controllers\PublicDownloadAppController::class, 'index'])
    ->middleware('public.legal')
    ->name('download');

Route::get('/download-app', [\App\Http\Controllers\PublicDownloadAppController::class, 'index'])
    ->middleware('public.legal')
    ->name('download-app');

Route::get('/app', [\App\Http\Controllers\PublicDownloadAppController::class, 'index'])
    ->middleware('public.legal')
    ->name('app');

// XML sitemap (FINAL AUDIT #15): canonical public URLs only — no auth,
// admin, API, search-form, payment-return or legacy .php duplicates.
// Read-only and cacheable.
Route::get('/sitemap.xml', \App\Http\Controllers\SitemapController::class)
    ->name('sitemap');

/*
|--------------------------------------------------------------------------
| Browser payment-return pages (FINAL AUDIT #2)
|--------------------------------------------------------------------------
|
| Where a gateway drops the player's browser after checkout. PRESENTATION
| ONLY: the landing route is context, the displayed state is always the
| internal payment record (see PaymentCallbackController), and nothing on
| these pages can credit or change money. Paths come from the same
| config/payment.php callback block the gateway drivers build their
| success/cancel URLs from, so they can never drift apart.
|
*/

Route::middleware('auth')->group(function (): void {
    // The config values may be absolute URLs ("${APP_URL}/payment/success")
    // because the gateway drivers hand them to providers; route registration
    // only wants the path component, so normalize once here.
    $callbackPath = static function (string $key, string $default): string {
        $value = (string) config('payment.callback.'.$key, $default);
        $path = parse_url($value, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : $default;
    };

    Route::get($callbackPath('success_url', '/payment/success'), [PaymentCallbackController::class, 'success'])
        ->name('payment.callback.success');

    Route::get($callbackPath('failure_url', '/payment/failure'), [PaymentCallbackController::class, 'failure'])
        ->name('payment.callback.failure');

    Route::get($callbackPath('cancel_url', '/payment/cancel'), [PaymentCallbackController::class, 'cancel'])
        ->name('payment.callback.cancel');

    Route::get($callbackPath('pending_url', '/payment/pending'), [PaymentCallbackController::class, 'pending'])
        ->name('payment.callback.pending');
});

// User-facing locale switch route (session & cookie persistence)
Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, ['en', 'th'], true)) {
        session(['locale' => $locale]);
        cookie()->queue(cookie('locale', $locale, 60 * 24 * 365));
    }

    return redirect()->back();
})->name('locale.switch');

Route::post('/contact', [ContactController::class, 'submit'])
    ->middleware('throttle:contact-submit')
    ->name('contact.submit');

/*
|--------------------------------------------------------------------------
| LOTTOFIN ADMIN & Operations Console Routes
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->name('admin.')->middleware(['admin.auth', 'can:access-admin'])->group(function (): void {
    Route::get('/', [LottoFinExecutiveDashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard', [LottoFinExecutiveDashboardController::class, 'index'])->name('dashboard.index');
    Route::get('/api/analytics', [LottoFinExecutiveDashboardController::class, 'analyticsApi'])
        ->middleware('throttle:admin-analytics')
        ->name('api.analytics');
    Route::get('/api/reconciliation', [LottoFinExecutiveDashboardController::class, 'reconciliationFeedApi'])
        ->middleware('throttle:admin-analytics')
        ->name('api.reconciliation');
    Route::post('/api/reconciliation', [LottoFinExecutiveDashboardController::class, 'runReconciliation'])
        ->middleware(['throttle:admin-analytics', 'can:access-admin'])
        ->name('api.reconciliation.run');

    // Operational projections. Each request is permission-checked again in the
    // controller so a route alias cannot widen access to another panel.
    Route::get('/draws', [LottoFinExecutiveDashboardController::class, 'index'])->name('draws.index');
    Route::get('/risk', [LottoFinExecutiveDashboardController::class, 'index'])->name('risk.index');
    Route::get('/bets', [LottoFinExecutiveDashboardController::class, 'index'])->name('bets.index');
    Route::get('/wallets', [LottoFinExecutiveDashboardController::class, 'index'])->name('wallets.index');
    Route::get('/ledger', [LottoFinExecutiveDashboardController::class, 'index'])->name('ledger.index');
    Route::get('/reconciliation', [LottoFinExecutiveDashboardController::class, 'index'])->name('reconciliation.index');
    Route::get('/audits', [LottoFinExecutiveDashboardController::class, 'index'])->name('audits.index');

    // Payment and withdrawal mutations are not implemented by this browser
    // console. They terminate in an explicit NOT_CONFIGURED response rather
    // than silently rendering a GET projection or changing financial state.
    Route::get('/payments', [LottoFinExecutiveDashboardController::class, 'index'])->name('payments.index');
    Route::get('/withdrawals', [LottoFinExecutiveDashboardController::class, 'index'])->name('withdrawals.index');
    Route::post('/withdrawals/{id}/disburse', [LottoFinExecutiveDashboardController::class, 'unsupportedMutation'])->name('withdrawals.disburse');
    Route::post('/withdrawals/{id}/reject', [LottoFinExecutiveDashboardController::class, 'unsupportedMutation'])->name('withdrawals.reject');

    // KYC documents remain on private storage and are streamed only after the
    // controller performs object-level reviewer authorization and audit logging.
    Route::get('/kyc', [LottoFinExecutiveDashboardController::class, 'index'])->name('kyc.index');
    Route::get('/kyc/{documentToken}/download', [LottoFinExecutiveDashboardController::class, 'downloadKyc'])
        ->where('documentToken', '[a-f0-9]{64}')
        ->middleware('throttle:account-verification')
        ->name('kyc.download');
    Route::post('/kyc/{documentToken}/approve', [LottoFinExecutiveDashboardController::class, 'reviewKyc'])
        ->where('documentToken', '[a-f0-9]{64}')
        ->middleware('throttle:account-verification')
        ->name('kyc.approve');
    Route::post('/kyc/{documentToken}/reject', [LottoFinExecutiveDashboardController::class, 'reviewKyc'])
        ->where('documentToken', '[a-f0-9]{64}')
        ->middleware('throttle:account-verification')
        ->name('kyc.reject');
    Route::get('/compliance', [LottoFinExecutiveDashboardController::class, 'index'])->name('compliance.index');

    // Pages 100–150 operational aliases. These remain read-only projections
    // unless an existing canonical service route is already used elsewhere.
    Route::get('/glo/prize-claims', [LottoFinExecutiveDashboardController::class, 'index'])->name('glo.prize-claims.index');
    Route::get('/glo/prize-claims/{claim}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('claim', '[A-Za-z0-9_-]{1,120}')
        ->name('glo.prize-claims.show');
    Route::get('/glo/ticket-freezes', [LottoFinExecutiveDashboardController::class, 'index'])->name('glo.ticket-freezes.index');
    Route::get('/glo/ticket-freezes/{token}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('token', '[A-Za-z0-9_-]{1,120}')
        ->name('glo.ticket-freezes.show');
    Route::get('/glo/settlements', [LottoFinExecutiveDashboardController::class, 'index'])->name('glo.settlements.index');
    Route::get('/wallet-operations', [LottoFinExecutiveDashboardController::class, 'index'])->name('wallet-operations.index');
    Route::get('/payments/{payment}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('payment', '[0-9]+')
        ->name('payments.show');
    Route::get('/payment-methods', [LottoFinExecutiveDashboardController::class, 'index'])->name('payment-methods.index');
    Route::get('/withdrawal-methods', [LottoFinExecutiveDashboardController::class, 'index'])->name('withdrawal-methods.index');
    Route::get('/payment-events', [LottoFinExecutiveDashboardController::class, 'index'])->name('payment-events.index');
    Route::get('/payments/events', [LottoFinExecutiveDashboardController::class, 'index'])->name('payments-events.index');
    Route::get('/payment-exceptions', [LottoFinExecutiveDashboardController::class, 'index'])->name('payment-exceptions.index');
    Route::get('/payments/exceptions', [LottoFinExecutiveDashboardController::class, 'index'])->name('payments-exceptions.index');
    Route::get('/draw-lifecycle', [LottoFinExecutiveDashboardController::class, 'index'])->name('draw-lifecycle.index');
    Route::get('/result-publication', [LottoFinExecutiveDashboardController::class, 'index'])->name('result-publication.index');
    Route::get('/result-imports', [LottoFinExecutiveDashboardController::class, 'index'])->name('result-imports.index');
    Route::get('/result-sources', [LottoFinExecutiveDashboardController::class, 'index'])->name('result-sources.index');
    Route::get('/lotteries', [LottoFinExecutiveDashboardController::class, 'index'])->name('lotteries.index');
    Route::get('/lottery-rules', [LottoFinExecutiveDashboardController::class, 'index'])->name('lottery-rules.index');
    Route::get('/fees', [LottoFinExecutiveDashboardController::class, 'index'])->name('fees.index');
    Route::get('/account-grades', [LottoFinExecutiveDashboardController::class, 'index'])->name('account-grades.index');
    Route::get('/account-verification', [LottoFinExecutiveDashboardController::class, 'index'])->name('account-verification.index');
    Route::get('/responsible-gaming', [LottoFinExecutiveDashboardController::class, 'index'])->name('responsible-gaming.index');
    Route::get('/self-exclusion', [LottoFinExecutiveDashboardController::class, 'index'])->name('self-exclusion.index');
    Route::get('/users', [LottoFinExecutiveDashboardController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('user', '[0-9]+')
        ->name('users.show');
    Route::get('/users/{user}/finance', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('user', '[0-9]+')
        ->name('users.finance');
    Route::get('/bets/{bet}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('bet', '[0-9]+')
        ->name('bets.show');
    Route::get('/tickets/{ticket}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('ticket', '[0-9]+')
        ->name('tickets.show');
    Route::get('/ticket-verification', [LottoFinExecutiveDashboardController::class, 'index'])->name('ticket-verification.index');
    Route::get('/prize-claim-review', [LottoFinExecutiveDashboardController::class, 'index'])->name('prize-claim-review.index');
    Route::get('/commissions', [LottoFinExecutiveDashboardController::class, 'index'])->name('commissions.index');
    Route::get('/queues', [LottoFinExecutiveDashboardController::class, 'index'])->name('queues.index');
    Route::get('/scheduler', [LottoFinExecutiveDashboardController::class, 'index'])->name('scheduler.index');
    Route::get('/runtime', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'runtime')
        ->name('runtime.index');
    Route::get('/api-status', [LottoFinExecutiveDashboardController::class, 'index'])->name('api-status.index');
    Route::get('/webhooks', [LottoFinExecutiveDashboardController::class, 'index'])->name('webhooks.index');
    Route::get('/security', [LottoFinExecutiveDashboardController::class, 'index'])->name('security.index');
    Route::get('/release', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'manifest')
        ->name('release.index');
    Route::get('/cutover', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'cutover')
        ->name('cutover.index');

    Route::get('/release-manifest', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'manifest')
        ->name('release-manifest.index');
    Route::get('/configuration', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'configuration')
        ->name('configuration.index');
    Route::get('/secrets', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'secrets')
        ->name('secrets.index');
    Route::get('/migrations', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'migrations')
        ->name('migrations.index');
    Route::get('/backups', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'backups')
        ->name('backups.index');
    Route::get('/restore-verification', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'restore')
        ->name('restore-verification.index');
    Route::get('/disaster-recovery', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'disaster-recovery')
        ->name('disaster-recovery.index');
    Route::get('/high-availability', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'high-availability')
        ->name('high-availability.index');
    Route::get('/incidents', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'incidents')
        ->name('incidents.index');
    Route::get('/incidents/{reference}', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'incidents')
        ->where('reference', '[A-Za-z0-9_-]{1,120}')
        ->name('incidents.show');
    Route::get('/deployment-approval', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'deployment-approval')
        ->name('deployment-approval.index');
    Route::get('/deployments', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'deployments')
        ->name('deployments.index');
    Route::get('/rollback', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'rollback')
        ->name('rollback.index');
    Route::get('/feature-flags', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'feature-flags')
        ->name('feature-flags.index');
    Route::get('/configuration-audit', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'configuration-audit')
        ->name('configuration-audit.index');
    Route::get('/sessions', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'sessions')
        ->name('sessions.index');
    Route::get('/access-review', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'access-review')
        ->name('access-review.index');
    Route::get('/privileged-access', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'privileged-access')
        ->name('privileged-access.index');
    Route::get('/permission-matrix', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'permission-matrix')
        ->name('permission-matrix.index');
    Route::get('/service-accounts', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'service-accounts')
        ->name('service-accounts.index');
    Route::get('/network-access', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'network-access')
        ->name('network-access.index');
    Route::get('/device-risk', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'device-risk')
        ->name('device-risk.index');
    Route::get('/mfa', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'mfa')
        ->name('mfa.index');
    Route::get('/authentication-security', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'authentication-security')
        ->name('authentication-security.index');
    Route::get('/rate-limits', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'rate-limits')
        ->name('rate-limits.index');
    Route::get('/captcha', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'captcha')
        ->name('captcha.index');
    Route::get('/risk-rules', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'risk-rules')
        ->name('risk-rules.index');
    Route::get('/suspicious-activity', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'suspicious-activity')
        ->name('suspicious-activity.index');
    Route::get('/compliance-cases', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'compliance-cases')
        ->name('compliance-cases.index');
    Route::get('/compliance-cases/{reference}', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'compliance-cases')
        ->where('reference', '[A-Za-z0-9_-]{1,120}')
        ->name('compliance-cases.show');
    Route::get('/sanctions', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'sanctions')
        ->name('sanctions.index');
    Route::get('/kyc-provider', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'kyc-provider')
        ->name('kyc-provider.index');
    Route::get('/kyc-review', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'kyc-review')
        ->name('kyc-review.index');
    Route::get('/age-verification', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'age-verification')
        ->name('age-verification.index');
    Route::get('/duplicate-accounts', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'duplicate-accounts')
        ->name('duplicate-accounts.index');
    Route::get('/account-restrictions', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'account-restrictions')
        ->name('account-restrictions.index');
    Route::get('/retention', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'retention')
        ->name('retention.index');
    Route::get('/privacy', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'privacy')
        ->name('privacy.index');
    Route::get('/data-rights', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'data-rights')
        ->name('data-rights.index');
    Route::get('/legal-registries', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'legal-registries')
        ->name('legal-registries.index');
    Route::get('/compliance-reporting', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'compliance-reporting')
        ->name('compliance-reporting.index');
    Route::get('/aml-monitoring', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'aml-monitoring')
        ->name('aml-monitoring.index');
    Route::get('/regulatory-exports', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'regulatory-exports')
        ->name('regulatory-exports.index');
    Route::get('/compliance-audit', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'compliance-audit')
        ->name('compliance-audit.index');
});

/*
|--------------------------------------------------------------------------
| Authenticated support and notification projections
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function (): void {
    Route::get('/support', [SupportPortalController::class, 'index'])->name('support.index');
    Route::get('/support/{reference}', [SupportPortalController::class, 'show'])
        ->where('reference', '[A-Za-z0-9_-]{1,120}')
        ->name('support.show');
    Route::get('/notifications', [NotificationCenterController::class, 'index'])->name('notifications.index');
});

/*
|--------------------------------------------------------------------------
| Agent Portal Routes
|--------------------------------------------------------------------------
*/
Route::prefix('agent')->name('agent.')->middleware('auth')->group(function (): void {
    Route::get('/', [AgentPortalController::class, 'dashboard'])->name('dashboard');
    Route::get('/dashboard', [AgentPortalController::class, 'dashboard'])->name('dashboard.index');
    Route::get('/commissions', [AgentPortalController::class, 'commissions'])->name('commissions');
    Route::get('/settlements', [AgentPortalController::class, 'settlements'])->name('settlements');
    Route::get('/statement', [AgentPortalController::class, 'statement'])->name('statement');
    Route::get('/referrals', [AgentPortalController::class, 'referrals'])->name('referrals');
    Route::get('/referrals/{reference}', [AgentPortalController::class, 'referralDetail'])
        ->where('reference', '[a-f0-9]{64}')
        ->name('referrals.show');
});

/*
|--------------------------------------------------------------------------
| Legacy .php URL compatibility layer (301)
|--------------------------------------------------------------------------
|
| Single home for every public .php URL the replaced site published:
| static pages, member auth surfaces, account explainer pages, the broken
| double-path member URLs, and the per-year archive pages — including the
| "lottoery" typo form search engines indexed. See LegacyRedirectController
| for the map and the rules.
|
| THIS MUST STAY THE LAST ROUTE IN THIS FILE. It only ever sees paths no
| real route claimed, because Laravel matches in registration order, and
| it answers 404 for .php paths it does not know rather than aliasing them.
|
*/

Route::match(['get', 'post'], '/{legacyPath}', [LegacyRedirectController::class, 'resolve'])
    ->where('legacyPath', '.*\.php$')
    ->name('legacy.redirect');

```

## FILE 7: `tests/Feature/Pages150To250StaticContractTest.php`

# TYPE: PHP static contract test
# PURPOSE: Checks Pages 150–250 route coverage, authorization boundary, read-only controller behavior, translation parity, canonical finance/lottery/Rust references, and audit rows.

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Static contracts for the first Pages 150–250 implementation batch.
 *
 * These checks do not claim Laravel runtime, database, browser, or deployment
 * verification. They verify that the new routes, translation maps, and
 * fail-closed operational controller remain structurally bounded.
 */
final class Pages150To250StaticContractTest extends TestCase
{
    public function test_release_operations_routes_are_named_and_admin_protected(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        self::assertIsString($routes);
        foreach ([
            "Route::get('/release-manifest'",
            "Route::get('/configuration'",
            "Route::get('/secrets'",
            "Route::get('/migrations'",
            "Route::get('/backups'",
            "Route::get('/restore-verification'",
            "Route::get('/disaster-recovery'",
            "Route::get('/high-availability'",
            "Route::get('/incidents'",
            "Route::get('/incidents/{reference}'",
            "Route::get('/deployment-approval'",
            "Route::get('/deployments'",
            "Route::get('/rollback'",
            "Route::get('/feature-flags'",
            "Route::get('/configuration-audit'",
            "Route::get('/sessions'",
            "Route::get('/access-review'",
            "Route::get('/privileged-access'",
            "Route::get('/permission-matrix'",
            "Route::get('/service-accounts'",
            "Route::get('/network-access'",
            "Route::get('/device-risk'",
            "Route::get('/mfa'",
            "Route::get('/authentication-security'",
            "Route::get('/rate-limits'",
            "Route::get('/captcha'",
            "Route::get('/risk-rules'",
            "Route::get('/suspicious-activity'",
            "Route::get('/compliance-cases'",
            "Route::get('/sanctions'",
            "Route::get('/kyc'",
            "Route::get('/kyc-provider'",
            "Route::get('/kyc-review'",
            "Route::get('/age-verification'",
            "Route::get('/duplicate-accounts'",
            "Route::get('/account-restrictions'",
            "Route::get('/retention'",
            "Route::get('/privacy'",
            "Route::get('/data-rights'",
            "Route::get('/legal-registries'",
            "Route::get('/compliance-reporting'",
            "Route::get('/aml-monitoring'",
            "Route::get('/regulatory-exports'",
            "Route::get('/compliance-audit'",
            'ReleaseOperationsController::class',
            "->middleware(['admin.auth', 'can:access-admin'])",
        ] as $contract) {
            self::assertStringContainsString($contract, $routes);
        }
    }

    public function test_release_controller_is_read_only_and_does_not_execute_shell_or_financial_mutations(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/Admin/ReleaseOperationsController.php'));

        self::assertIsString($controller);
        self::assertStringContainsString('AdminAccess::canAccessPanel', $controller);
        self::assertStringContainsString('AdminAccess::allows', $controller);
        self::assertStringContainsString('hash_file', $controller);
        self::assertStringContainsString("'NOT_VERIFIED'", $controller);
        self::assertStringContainsString("'NOT_CONFIGURED'", $controller);
        self::assertStringNotContainsString('Process::run', $controller);
        self::assertStringNotContainsString('shell_exec(', $controller);
        self::assertStringNotContainsString('exec(', $controller);
        self::assertStringNotContainsString('Artisan::call', $controller);
        self::assertStringNotContainsString('->save()', $controller);
        self::assertStringNotContainsString('->update(', $controller);
        self::assertStringNotContainsString('->delete(', $controller);
        self::assertStringNotContainsString('float', strtolower($controller));
        self::assertStringNotContainsString('double', strtolower($controller));
    }

    public function test_release_translation_maps_have_exact_key_and_placeholder_parity(): void
    {
        $english = require base_path('lang/en/admin_release.php');
        $thai = require base_path('lang/th/admin_release.php');

        self::assertSame(self::keys($english), self::keys($thai));
        self::assertSame(self::placeholders($english), self::placeholders($thai));
    }

    public function test_canonical_finance_lottery_and_rust_boundaries_are_present(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));
        $controller = file_get_contents(base_path('app/Http/Controllers/Admin/LottoFinExecutiveDashboardController.php'));
        $rust = file_get_contents(base_path('security/weekly-result-integrity/src/main.rs'));
        $rustTests = file_get_contents(base_path('security/weekly-result-integrity/tests/integrity.rs'));

        self::assertIsString($routes);
        self::assertIsString($controller);
        self::assertIsString($rust);
        self::assertIsString($rustTests);
        self::assertStringContainsString("Route::get('/ledger'", $routes);
        self::assertStringContainsString("Route::get('/draw-lifecycle'", $routes);
        self::assertStringContainsString('FinancialReconciliationService', $controller);
        self::assertStringContainsString('GloResultImportService', $controller);
        self::assertStringContainsString('single JSON document from stdin', $rust);
        self::assertStringContainsString('synthetic', $rustTests);
    }

    public function test_release_audit_contains_pages_150_through_250(): void
    {
        $audit = file_get_contents(base_path('audit.md'));

        self::assertIsString($audit);
        for ($page = 150; $page <= 250; $page++) {
            self::assertMatchesRegularExpression('/\| '.$page.' \|/', $audit);
        }
        self::assertStringContainsString('NOT VERIFIED — RUNTIME UNAVAILABLE', $audit);
    }

    /**
     * @param array<mixed> $value
     * @return list<string>
     */
    private static function keys(array $value, string $prefix = ''): array
    {
        $keys = [];
        foreach ($value as $key => $child) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $keys[] = $path;
            if (is_array($child)) {
                $keys = array_merge($keys, self::keys($child, $path));
            }
        }

        sort($keys);

        return $keys;
    }

    /**
     * @param array<mixed> $value
     * @return list<string>
     */
    private static function placeholders(array $value, string $prefix = ''): array
    {
        $placeholders = [];
        foreach ($value as $key => $child) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($child)) {
                $placeholders = array_merge($placeholders, self::placeholders($child, $path));
                continue;
            }

            if (is_string($child)) {
                preg_match_all('/\{[^}]+\}|:[A-Za-z_][A-Za-z0-9_]*/', $child, $matches);
                $placeholders[$path] = $matches[0];
            }
        }

        ksort($placeholders);

        return $placeholders;
    }
}

```

## FILE 8: `audit.md`

# TYPE: Markdown audit report
# PURPOSE: One factual audit row for every Page 150–250 plus complete changed-file manifest, matrices, and validation evidence.

```text
# Pages 44–70 Implementation and Hardening Audit

**Audit date:** 2026-09-30
**Local timezone:** Asia/Dhaka
**Scope:** GLO L6 Pages 44–50 and authenticated member Pages 51–70
**Runtime status:** `NOT VERIFIED — RUNTIME UNAVAILABLE`
**Production readiness:** Not declared

## Evidence boundary

The repository has no PHP interpreter, Composer vendor directory, Laravel application runtime, database connection, browser runner, or configured external payment provider in this workspace. PHP files were parsed with the installed JavaScript `php-parser` package as a static syntax aid. This is not a Laravel boot, dependency-resolution, migration, route-list, Blade compilation, database, browser, payment-provider, or production verification.

The final frontend asset build was executed after adding the existing React component dependencies required by the repository's Vite entry graph:

```text
npm run build
vite v5.4.21 building for production
✓ 168 modules transformed.
✓ built in 3.92s
```

`npm ci`/`npm install` reported two dependency audit findings: one moderate and one high. No automatic force upgrade was applied.

The first asset-build attempt failed because `react` was not resolvable from `resources/js/components/WalletManagement.tsx`. `react` and `react-dom` were added to `package.json` and `package-lock.json`; the subsequent build passed. Generated `public/build` output is excluded from the persisted workspace snapshot.

## Acceptance decision

The implementation is not production-ready. The exact runtime status is:

```text
NOT VERIFIED — RUNTIME UNAVAILABLE
```

This status applies to runtime behavior, authentication, authorization, CSRF, throttling, CAPTCHA, database ownership, payment initiation, gateway callbacks, wallet reservation, ledger posting, responsible-gaming enforcement, KYC gates, grade evaluation, accessibility behavior, responsive browser behavior, route listing, Blade compilation, Laravel service-container resolution, migrations, and automated PHP tests.

## Page matrix

| Page | Route | Controller and canonical source | Financial or identity behavior | Status and finding |
|---|---|---|---|---|
| 44 | `glo-l6.index` | `GloL6Controller::index`; `GloL6HomeService`, `GloPublicHomeService`, purchase capability service | Read-only canonical projections. No purchase mutation. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Existing page retained and hardened; no duplicate home was created. |
| 45 | `glo-l6.buy` | `GloL6Controller::buy`; `GloL6PurchaseCapabilityService` | Purchase remains disabled with `NOT_CONFIGURED`; no price, selection, wallet, ticket, ledger, or idempotency mutation is advertised. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Fail-closed behavior is statically present. |
| 46 | `glo-l6.latest` | `GloL6Controller::latestResult`; `GloPublicResultService` | Published result projection only; unavailable source returns an unavailable state. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. No fabricated result values were added. |
| 47 | `glo-l6.history` | `GloL6Controller::history`; bounded canonical history query | Read-only paginated result rows and provenance state. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. History is bounded by configured window and page size. |
| 48 | `glo-l6.year` | `GloL6Controller::year`; canonical history service | Year is accepted only inside configured history window and route is constrained to four digits. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Runtime boundary and data query remain unverified. |
| 49 | `glo-l6.draw` | `GloL6Controller::drawDetail`; canonical draw/result projection | Read-only draw detail. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Collision-safe route pattern is present. |
| 50 | `glo-l6.result` | `GloL6Controller::resultDetail`; canonical result projection | Read-only result detail with provenance. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. No result is claimed when the source is unavailable. |
| 51 | `login` | `MemberAuthController`; canonical login service | Session authentication, CAPTCHA/throttle contract remains delegated to existing auth architecture. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. No duplicate auth surface created. |
| 52 | `register` | `MemberAuthController`; canonical registration service | Authenticated identity is created only through existing registration flow. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Runtime and CAPTCHA gates not executable. |
| 53 | `password.request` | `MemberAuthController`; canonical password-reset request service | Reset-token flow remains canonical and throttled. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Token security and mail delivery not runtime-tested. |
| 54 | `password.reset` | `MemberAuthController`; canonical password-reset service | Token-gated reset remains canonical. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Runtime not available. |
| 55 | `player.dashboard` | `PlayerWebController::dashboard`; `User`, `Wallet`, `Draw`, `Bet`, `FinancialTransaction` | Owner-scoped records only. Exact `Money` formatting is used for wallet and wager amounts. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. No fabricated player, wallet, draw, or wager rows are inserted by the page. |
| 56 | `player.draws` | `PlayerWebController::draws`; `Draw` and result relations | Real draw schedule and published result fields. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Draw fields and pagination require Laravel runtime verification. |
| 57 | `player.draws.detail` | `PlayerWebController::drawDetail`; owner-independent public draw read model | Real draw/result relation. Missing result displays a translated pending state. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Runtime and view compilation unverified. |
| 58 | `player.bet` and `player.bets.purchase` | `PlayerWebController::betSlip`, `BetPurchaseController`; `BulkBetService` | Purchase submits a public draw reference, resolves the canonical draw server-side, validates decimal stakes without floating-point parsing, and delegates to the canonical bulk betting service. The endpoint does not fabricate a success response when all items are refused. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Complete product, price, wallet, reservation, ledger, RG, and idempotency contract is not runtime-verified. |
| 59 | `player.bets` | `PlayerWebController::bets`; owner-scoped `Bet` query | Uses authenticated user ownership and canonical ticket/draw/item relations. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Presentation no longer invents ticket or draw references. |
| 60 | `player.wallet` | `PlayerWebController::wallet`; `Wallet`, `FinancialTransaction`, `Money` | Owner-scoped wallet and transaction journal. Decimal aggregates are reduced through `Money` rather than a floating-point PHP aggregate. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Database and ledger state not executable. |
| 61 | `player.deposit`, `player.deposit.store` | `PlayerWebController`; `PaymentInitiationService` and its canonical `DepositService::request` orchestration | Gateway-capable configured methods only. Deposit initiation now calls `PaymentInitiationService::initiateDeposit(Wallet, Money, PaymentMethod, key, options)` using the configured finance currency, exact decimal validation, and a constrained idempotency key. Wallet credit still requires canonical callback/completion. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Provider capability, gateway callback, and transaction behavior remain unverified. |
| 62 | `player.deposit.status` | `PlayerWebController::depositStatus`; owner-scoped `Deposit` query | Reads only the authenticated owner's deposit by reference or UUID. Status view distinguishes pending/provider state from wallet credit. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. New route/view is statically present; runtime ownership and model resolution are unverified. |
| 63 | `player.withdraw`, `player.withdraw.store` | `PlayerWebController`; canonical `WithdrawalService` and `WalletHoldService` | Gateway-capable payout methods only. Exact configured-currency validation and available-balance arithmetic use `Money`. Requests remain pending without a browser-side hold; canonical approval owns reservation and downstream payout/ledger transitions. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. KYC, RG, balance, hold, approval, payout, and ledger behavior remain unverified. |
| 64 | `player.withdrawal.status` | `PlayerWebController::withdrawalStatus`; owner-scoped `Withdrawal` query | Reads only the authenticated owner's request and does not expose encrypted payout details. Recent history links to the owner-scoped status route. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. New route/view is statically present; runtime not available. |
| 65 | `player.profile` | `PlayerWebController`; authenticated `User` and responsible-gaming limit record | Profile update derives ownership from session and preserves password and responsible-gaming routes. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. User model, validation and CSRF are not runtime-tested. |
| 66 | `player.security`, `player.settings` | `PlayerSecuritySettingsController`; canonical account verification service, security session records, responsible-gaming service | KYC status is read through `AccountVerificationService::publicStatus`; active sessions are owner-scoped, active, and unexpired; self-exclusion reads the canonical self-exclusion service. Unsupported compatibility mutations return `NOT_CONFIGURED`. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Container resolution and security-session schema are not executable. |
| 67 | `settings.index`, `member.settings`, `player.settings.portal` | `PlayerWebController::responsibleGaming`; canonical responsible-gaming and self-exclusion services | Limit updates use canonical responsible-gaming service. Self-exclusion now requests and activates a canonical `SelfExclusion` record and stamps the legacy limit lane through the existing engine. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Server clock, database transition, and enforcement gates are unverified. |
| 68 | `account.verification` | `MemberAccountVerificationController`; canonical `AccountVerificationService`, private document services, and opaque owner-scoped download tokens | Owner-scoped KYC status and document metadata; internal user/document numeric IDs are not rendered or placed in download URLs; document downloads remain owner-authorized and private. The retired duplicate root controller, alias, and view were removed from the active architecture. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. No private document or KYC runtime test can run. |
| 69 | `account.grade` | `AccountGradeController`; `AccountGradeService`, evaluator and discount projection | Uses server-computed grade, qualifying spend, entitlement projection, and canonical history. Monetary spend is formatted with `Money`; no hardcoded ticket price fallback remains in the view. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Grade calculations and database snapshots are unverified. |
| 70 | `account.grade.history` | `AccountGradeController::history`; canonical `AccountGradeService::history` | Browser request renders the authenticated user's canonical history view; JSON clients retain the JSON response when `expectsJson()` is true. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Runtime, route, and JSON negotiation not executable. |

## Finance and responsible-gaming findings

1. The Page 61 defect was corrected. `PlayerWebController::storeDeposit()` no longer calls the nonexistent `DepositService::initiate()` method. It now calls the inspected canonical `PaymentInitiationService::initiateDeposit()` contract and reads its array return values.
2. The deposit flow does not treat a redirect, provider reference, pending state, or manual instruction as proof of wallet credit. The wallet changes only through the canonical completion/callback path.
3. Deposit and withdrawal payment-method projections reject enum values without a configured, enabled, capability-backed gateway. Unsupported configured methods are not advertised.
4. Withdrawal balance display and configured-currency amount validation use exact `Money` arithmetic; the withdrawal form has no fabricated monetary default and no duplicate browser-side reservation.
5. The account-verification surface no longer exposes internal numeric user/document IDs. Owner download URLs use opaque HMAC tokens and the controller resolves them only within the authenticated owner scope.
6. Player self-exclusion was aligned to the canonical `Compliance\SelfExclusionService` bridge and `ResponsibleGaming\SelfExclusionService` engine. The security/API, settings compatibility, and browser form paths now use `SelfExclusionData`, request the canonical row, and activate it through the engine.
7. Unsupported settings mutations remain fail-closed with `NOT_CONFIGURED`; no MFA, notification, LINE, PIN, or security preference mutation claims success without an inspected backend contract.
8. Pages 62 and 64 are owner-scoped status views. They do not reveal another user's records and do not expose encrypted withdrawal payout details.
9. Public GLO L6 purchase remains `NOT_CONFIGURED`; no checkout, wallet debit, ticket issuance, reservation, or ledger mutation was invented.

## Localization and UI checks

| Resource | EN keys | TH keys | Result |
|---|---:|---:|---|
| `lang/en/player.php` / `lang/th/player.php` | 277 | 277 | Exact key and placeholder parity confirmed by a repository script. |
| `lang/en/glo_l6.php` / `lang/th/glo_l6.php` | 107 | 107 | Exact key and placeholder parity confirmed by a repository script. |
| `lang/en/account_services.php` / `lang/th/account_services.php` | 207 | 207 | Exact key and placeholder parity confirmed by a repository script. |
| `lang/en/account_info.php` / `lang/th/account_info.php` | 76 | 76 | Exact key and placeholder parity confirmed by a repository script. |

Changed player and account views use the dark/gold/glass classes and translated labels. Financial values use the existing exact-money value object. The browser accessibility gate, reduced-motion behavior, focus rendering, small-mobile layout, and assistive-technology output remain `NOT VERIFIED — RUNTIME UNAVAILABLE`.

## Static and build evidence

| Gate | Evidence | Result |
|---|---|---|
| PHP parser pass | 319 existing tracked/untracked PHP files parsed with `php-parser` after removing the retired duplicate account-verification controller/view | Static parser pass; not a PHP runtime check |
| Vite asset build | `npm run build` after the final Pages 44–70 edits | Passed |
| Translation parity | EN/TH key-set and placeholder comparison for player, GLO L6, account services, and account-info resources | Passed |
| `git diff --check` | Executed after the final whitespace cleanup | Passed |
| Static route/deletion scan | No active route references the retired root verification controller/view; the member verification route uses the canonical Verification controller and opaque document-token parameter | Passed |
| Fixture/fallback scan | No known fixture identity/financial markers, `number_format()` money output, or internal account/document IDs were found in the hardened owner-facing projections/responses | Passed |
| Laravel route list | PHP runtime unavailable | `NOT VERIFIED — RUNTIME UNAVAILABLE` |
| Blade compilation | PHP runtime and Composer vendor unavailable | `NOT VERIFIED — RUNTIME UNAVAILABLE` |
| PHPUnit/Pest | PHP runtime and Composer vendor unavailable | `NOT VERIFIED — RUNTIME UNAVAILABLE` |
| Database migrations and ownership tests | Database/runtime unavailable | `NOT VERIFIED — RUNTIME UNAVAILABLE` |
| Browser and accessibility audit | Browser runner unavailable | `NOT VERIFIED — RUNTIME UNAVAILABLE` |
| Payment-provider tests | No configured provider/runtime | `NOT VERIFIED — RUNTIME UNAVAILABLE` |

## Changed-file manifest for this Pages 44–70 hardening pass

Each entry includes the path, file type, and purpose. Full file contents remain in the workspace at these exact paths; no implementation body is omitted from the repository deliverable.

| Path | `# TYPE` | `# PURPOSE` |
|---|---|---|
| `app/Http/Controllers/Web/PlayerWebController.php` | PHP controller | Canonical owner-scoped player pages; corrected deposit orchestration; added deposit and withdrawal status views; exact configured-currency validation and wallet aggregation; canonical self-exclusion. |
| `app/Http/Controllers/Web/BetPurchaseController.php` | PHP controller | Resolves a public draw reference to the canonical draw server-side and delegates exact-decimal bet selections to `BulkBetService`; no internal draw ID is accepted from the browser. |
| `app/Http/Requests/Web/BetPurchaseRequest.php` | PHP form request | Retained compatibility validation with public draw references and exact decimal stake strings. |
| `app/Http/Requests/Web/DepositRequest.php` | PHP form request | Retained compatibility validation with configured payment currency/limits and exact decimal deposit strings. |
| `app/Http/Requests/Web/WithdrawRequest.php` | PHP form request | Retained compatibility validation with configured payment currency/limits and exact decimal withdrawal strings. |
| `app/Http/Controllers/Verification/AccountVerificationController.php` | PHP controller | Canonical member verification orchestration; owner-scoped opaque document-token downloads; reviewer decisions remain policy-walled. |
| `app/Http/Controllers/Api/V1/AuthController.php` | PHP controller | Authenticated API identity projection without exposing the internal numeric user key. |
| `app/Http/Controllers/Api/V1/MeController.php` | PHP controller | Authenticated account projection without exposing the internal numeric user key. |
| `app/Http/Controllers/Api/V1/ProfileController.php` | PHP controller | Authenticated profile projection and mutation responses without exposing the internal numeric user key; translated API messages. |
| `app/Http/Resources/UserResource.php` | PHP API resource | Authenticated/public-safe user projection without exposing the internal numeric user key. |
| `app/Http/Controllers/Player/PlayerSecuritySettingsController.php` | PHP controller | Authenticated security, KYC, session, responsible-gaming limit, and canonical self-exclusion adapter. |
| `app/Http/Controllers/Player/LotteryHistoryPortalController.php` | PHP controller | Replaced fixture history/slip behavior with owner-scoped canonical Bet/Draw/Ticket/BetItem projections, canonical cancellation, and fail-closed re-bet. |
| `app/Http/Controllers/Player/PlayerDashboardController.php` | PHP controller | Compatibility dashboard projection without internal numeric draw/bet IDs and with translated fail-closed messages. |
| `app/Http/Controllers/Player/PlayerProfilePortalController.php` | PHP controller | Compatibility profile adapter with translated fail-closed unsupported mutations and session-owned canonical profile delegation. |
| `app/Http/Controllers/Player/PlayerSettingsPortalController.php` | PHP controller | Compatibility settings adapter with translated API messages and canonical responsible-gaming/self-exclusion transitions. |
| `resources/views/player/history-portal.blade.php` | Deleted Blade view | Removed the fixture-based duplicate history portal; `/history` compatibility paths now redirect to canonical `player.bets`. |
| `app/Http/Controllers/AccountVerificationController.php` | Deleted PHP controller | Removed the unrouted duplicate root verification controller; the Verification namespace controller is the sole active member path. |
| `app/Http/Controllers/Web/AccountVerificationController.php` | Deleted PHP controller alias | Removed the unrouted duplicate web verification alias. |
| `resources/views/account/verification.blade.php` | Deleted Blade view | Removed the unrouted duplicate hardcoded verification page; the canonical `account-verification.index` view is the sole active member surface. |
| `resources/views/player/profile-portal.blade.php` | Deleted Blade view | Removed an unused duplicate profile portal view; profile compatibility is API-only and browser paths redirect to canonical profile. |
| `resources/views/player/settings-portal.blade.php` | Deleted Blade view | Removed an unused duplicate settings portal view; browser paths use canonical security/responsible-gaming surfaces. |
| `resources/views/player/verification.blade.php` | Deleted Blade view | Removed an unused duplicate verification view; authenticated verification uses the canonical account verification controller. |
| `app/Http/Controllers/AccountGradeController.php` | PHP controller | Canonical account-grade browser history view with JSON compatibility for JSON clients. |
| `app/Models/AccountVerificationDocument.php` | PHP model projection | Owner-safe KYC document metadata projection with no exposed internal document ID. |
| `app/Services/Account/AccountVerificationService.php` | PHP service | Canonical owner KYC facade; removes internal account-number output and produces/validates opaque owner-scoped document download tokens. |
| `app/Services/Account/AccountVerificationDocumentService.php` | PHP service | Private KYC document storage/read projection with a generic download filename that does not reveal an internal document ID. |
| `app/Services/Verification/AccountVerificationService.php` | PHP service | Canonical member verification aggregate wrapper; exposes the owner-token lookup while preserving KYC state transitions and audit behavior. |
| `app/Services/Verification/DocumentStorageService.php` | PHP service | Private document storage/read contract with a generic content-disposition filename and no numeric ID disclosure. |
| `app/DTOs/ResponsibleGaming/SelfExclusionData.php` | Existing canonical PHP DTO | Server-pronounced self-exclusion request data; consumed by the hardened player paths. |
| `app/Services/Compliance/SelfExclusionService.php` | Existing canonical PHP service | Owner-scoped bridge used for current/active self-exclusion and transitions. |
| `app/Services/ResponsibleGaming/SelfExclusionService.php` | Existing canonical PHP service | Existing request/activation engine used by the new adapters; no duplicate engine created. |
| `app/Services/Payment/PaymentInitiationService.php` | Existing canonical PHP service | Inspected deposit orchestration contract reached by Page 61. |
| `app/Services/Finance/DepositService.php` | Existing canonical PHP service | Inspected request/create deposit contract; nonexistent `initiate()` call removed. |
| `resources/views/glo-l6/index.blade.php` | Blade view | Existing Page 44 home hardening; translated fail-closed purchase reason. |
| `resources/views/glo-l6/buy.blade.php` | Blade view | Page 45 fail-closed ticket-selection boundary with translated missing-contract states. |
| `resources/views/glo-l6/result.blade.php` | Blade view | Pages 46, 49, and 50 canonical result projection with translated unavailable messaging. |
| `resources/views/glo-l6/history.blade.php` | Blade view | Pages 47 and 48 bounded history/archive presentation. |
| `resources/views/player/dashboard.blade.php` | Blade view | Page 55 authenticated dashboard; exact money formatting and translated state fallback. |
| `resources/views/player/draws.blade.php` | Blade view | Page 56 real draw schedule/results view; corrected canonical close field and translated empty states. |
| `resources/views/player/draw-detail.blade.php` | Blade view | Page 57 real draw detail using actual result arrays and pending state. |
| `resources/views/player/bets.blade.php` | Blade view | Page 59 owner history without fabricated ticket/draw references. |
| `resources/views/player/wallet.blade.php` | Blade view | Page 60 exact wallet/ledger display and enum-safe transaction type projection. |
| `resources/views/player/deposit.blade.php` | Blade view | Page 61 capability-backed deposit form, exact limits, and status links. |
| `resources/views/player/withdraw.blade.php` | Blade view | Page 63 capability-backed withdrawal form, exact available balance, and history links. |
| `resources/views/player/withdrawal-status.blade.php` | Blade view | Page 64 owner-scoped withdrawal status/history detail without payout secrets, stored currency fallback refusal, and translated status/method labels. |
| `resources/views/player/profile.blade.php` | Blade view | Page 65 translated profile, password, and limit forms without fabricated limit placeholders. |
| `resources/views/account-verification/index.blade.php` | Blade view | Canonical Page 68 authenticated/public verification surface with translated public guide copy, owner-safe status/document projections, and opaque download-token links. |
| `resources/views/player/bet.blade.php` | Blade view | Page 58 fail-closed bet slip using a public draw reference rather than an internal draw ID and exact client-side cent totals. |
| `resources/views/components/account/verification-status.blade.php` | Blade component | Owner-safe verification summary with translated unavailable identity fields. |
| `resources/views/components/account/document-upload.blade.php` | Blade component | Canonical document-upload placeholder using translated unavailable state. |
| `resources/views/player/deposit-status.blade.php` | Blade view | Page 62 owner-scoped deposit/payment-intent status with stored currency, exact Money formatting, and translated status/method labels. |
| `resources/views/player/security.blade.php` | Blade view | Page 66 translated KYC, active session, self-exclusion, and security action view. |
| `resources/views/player/responsible-gaming.blade.php` | Blade view | Page 67 canonical limits and self-exclusion form without fabricated input defaults. |
| `resources/views/account/grade.blade.php` | Blade view | Page 69 exact grade/spend display and full-history link; removed fallback ticket prices. |
| `resources/views/account/grade-history.blade.php` | Blade view | Page 70 canonical owner grade history view with exact-money qualifying spend. |
| `resources/views/components/account/grade-card.blade.php` | Blade component | Exact-money grade spend/progress presentation and no fabricated Bronze/zero fallback labels. |
| `routes/web.php` | PHP route file | Added owner-scoped Page 62/64 status routes, switched member verification downloads to opaque token parameters, removed the unrouted duplicate verification-controller import, and retained auth/throttle/legacy route boundaries. |
| `lang/en/player.php` | PHP translation map | English player/status/security/deposit/withdrawal/self-exclusion keys. |
| `lang/th/player.php` | PHP translation map | Thai parity for the same player/status/security/deposit/withdrawal/self-exclusion keys. |
| `lang/en/glo_l6.php` | PHP translation map | English GLO L6 fail-closed contract labels. |
| `lang/th/glo_l6.php` | PHP translation map | Thai parity for GLO L6 fail-closed contract labels. |
| `lang/en/account_services.php` | PHP translation map | English grade-history and grade display keys; removed hardcoded price claims. |
| `lang/th/account_services.php` | PHP translation map | Thai parity for grade-history and grade display keys. |
| `lang/en/account_info.php` | PHP translation map | English public verification-guide and navigation copy with exact placeholder parity. |
| `lang/th/account_info.php` | PHP translation map | Thai parity for public verification-guide and navigation copy. |
| `package.json` | JSON dependency manifest | Added React runtime dependencies required by the existing Vite WalletManagement component. |
| `package-lock.json` | JSON lockfile | Locked React runtime dependencies and retained the project lockfile name. |
| `audit.md` | Markdown audit report | This page matrix, evidence boundary, findings, status ledger, and changed-file manifest. |

## Limitations and remaining findings

- The PHP runtime and Composer dependencies are unavailable, so no Laravel route list, Blade compiler, service-container resolution, migration, controller test, or browser request was executed.
- The existing repository contains a broad set of prior changes outside the focused files above. This audit does not convert those unrelated historical changes into new architecture.
- The payment providers, database, queue workers, callback signing keys, mail transport, CAPTCHA provider, and browser session are unavailable in the workspace.
- The Vite dependency audit still reports one moderate and one high vulnerability. No force upgrade was applied because the compatible remediation was not runtime-tested.
- Production readiness remains prohibited until the runtime, finance, security, localization, accessibility, build, and complete test gates are executed in an environment with PHP, Composer, database, and configured services.
## Pages 77–100 independent audit matrix

The following rows are independent page records. Static source review and edits are recorded; no Laravel, PHP, database, browser, provider, or full-test runtime gate is claimed.

| PAGE | ROUTE | ROUTE NAME | CONTROLLER | SERVICE | REQUEST | MODEL | DATABASE | API | VIEW | JS | CSS | TRANSLATION | SECURITY | DATA SOURCE | AUTHORIZATION | STATUS | TESTS | RUNTIME STATUS | REMAINING GAP |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 77 | `/results` | `results.index` | `GloResultsPageController` | `ResultsPageService` | none | `Draw`, `DrawResult` | published draw/result projection | `/api/v1/glo/latest-draw` | `results/index.blade.php` | none required | existing app/theme styles | `results.php` EN/TH | public-safe source state; no fixture claims | canonical published rows | anonymous public projection | IMPLEMENTED — STATIC ONLY | static inspection; runtime test not executed | NOT VERIFIED — RUNTIME UNAVAILABLE | verify route, Blade, query, and accessibility behavior with Laravel/browser |
| 78 | `/check` | `ticket-check`, `ticket-check.submit` | `HomeController` | `GloPublicResultService` | CSRF; six digits; throttled POST | `Draw`, `DrawResult` through service | canonical public result/check data | existing GLO check APIs | `home/check.blade.php` | none required | existing home styles | `home.php` EN/TH | server-side bounded input and rate limit | canonical GLO ticket checker | anonymous; no client identity accepted | REVIEWED — STATIC ONLY | existing check flow inspected | NOT VERIFIED — RUNTIME UNAVAILABLE | execute throttling and no-data behavior |
| 79 | `/sales-points` | `sales-points` | `HomeController` | `GloSalesPointService` | bounded query/page filters | service-owned public point projection | configured/public sales-point data | existing GLO sales-point API | `home/sales-points.blade.php` | none required | existing home styles | home text bag EN/TH | bounded search and explicit unavailable state | canonical published sales points | anonymous public projection | REVIEWED — STATIC ONLY | existing controller/view inspected | NOT VERIFIED — RUNTIME UNAVAILABLE | verify paginator and public data-state behavior |
| 80 | `/privacy` | `privacy` | `PublicPagesController` | existing public legal page service | none | legal content projection | configured legal content | existing privacy API | `static/privacy.blade.php` | none required | existing legal styles | `public_pages.php` EN/TH | public legal headers; no unsupported claims intended | configured legal source | anonymous | REVIEWED — STATIC ONLY | existing route/controller/view inspected | NOT VERIFIED — RUNTIME UNAVAILABLE | verify canonical metadata and legal content parity |
| 81 | `/contact` | `contact`, `contact.submit` | `ContactController` | existing contact service/mail/storage lane | CSRF; validation; spam controls; throttle | contact submission model if configured | canonical contact configuration and sanitized submission | none | `contact/index.blade.php` | none required | existing contact styles | `contact.php` EN/TH | throttling, validation, truthful success | configured contact channels | anonymous GET/POST | REVIEWED — STATIC ONLY | existing route/controller/view inspected | NOT VERIFIED — RUNTIME UNAVAILABLE | verify mail/storage failure states |
| 82 | `/download`, `/download-app`, `/app` | `download`, `download-app`, `app` | `PublicDownloadAppController` | `PublicAppLinkService` | none | none | configured app-link data | existing download API | `download/index.blade.php` | none required | existing app styles | public page resources EN/TH | no fabricated URLs | canonical configured links only | anonymous | REVIEWED — STATIC ONLY | existing service/controller inspected | NOT VERIFIED — RUNTIME UNAVAILABLE | verify unavailable/not-configured rendering |
| 83 | `/account-grades`, `/account-grade` | existing named routes | `PublicGradeController` | existing public grade service | none | public grade configuration | configured grade rules only | none | existing grade public view | none required | existing public styles | account services/info EN/TH | no authenticated account data | configured explainer only | anonymous | REVIEWED — STATIC ONLY | existing route/controller inspected | NOT VERIFIED — RUNTIME UNAVAILABLE | verify exact EN/TH key parity in runtime |
| 84 | `/account-verification`, `/account-verification-guide` | existing named routes | `PublicVerificationController` | existing public verification service | none | public verification configuration | configured guide data | none | existing verification public view | none required | existing public styles | account services/info EN/TH | no user/KYC records on public page | configured guide only | anonymous | REVIEWED — STATIC ONLY | existing route/controller inspected | NOT VERIFIED — RUNTIME UNAVAILABLE | verify guide state and metadata |
| 85 | `/sitemap.xml`, robots/indexation surfaces | `sitemap` | `SitemapController` | existing sitemap/public-page services | none | public route registry | configured canonical URL source | XML sitemap | sitemap response | none | response headers | public page translations | excludes auth/admin/API/payment returns | canonical public routes only | anonymous | REVIEWED — STATIC ONLY | existing sitemap/security tests present but not run | NOT VERIFIED — RUNTIME UNAVAILABLE | execute sitemap and robots assertions |
| 86 | `/payment/success` | `payment.callback.success` | `PaymentCallbackController` | `PaymentCallbackService::browserReturnProjection` | authenticated query references; read-only | `Payment` plus payable projection | payments paper only | provider callback architecture remains separate | `payment/callback.blade.php` | none required | existing app styles | `account_services.php` EN/TH | owner check; safe reference bounds; no state mutation | verified internal payment status | session owner | REVIEWED — STATIC ONLY | existing BrowserPaymentCallback tests not run | NOT VERIFIED — RUNTIME UNAVAILABLE | verify confirmed/pending/not-found page states |
| 87 | `/payment/failure` | `payment.callback.failure` | `PaymentCallbackController` | same read-only projection | same bounded references | `Payment` | payments paper | no browser mutation | shared callback view | none required | existing app styles | account services EN/TH | authoritative failed status only | internal payment status | session owner | REVIEWED — STATIC ONLY | existing callback tests not run | NOT VERIFIED — RUNTIME UNAVAILABLE | verify failure cannot be forged by route |
| 88 | `/payment/cancel` | `payment.callback.cancel` | `PaymentCallbackController` | same read-only projection | same bounded references | `Payment` | payments paper | no browser mutation | shared callback view | none required | existing app styles | account services EN/TH | authoritative cancelled status only | internal payment status | session owner | REVIEWED — STATIC ONLY | existing callback tests not run | NOT VERIFIED — RUNTIME UNAVAILABLE | verify cancel context does not override state |
| 89 | `/payment/pending` | `payment.callback.pending` | `PaymentCallbackController` | same read-only projection | same bounded references | `Payment` | payments paper | no browser mutation | shared callback view | none required | existing app styles | account services EN/TH | authoritative pending status only | internal payment status | session owner | REVIEWED — STATIC ONLY | existing callback tests not run | NOT VERIFIED — RUNTIME UNAVAILABLE | verify pending remains pending until verified transition |
| 90 | `/admin`, `/admin/dashboard` | `admin.dashboard`, `admin.dashboard.index` | `LottoFinExecutiveDashboardController` | canonical model projections | authenticated; admin gate | `Bet`, `Withdrawal`, `FinancialTransaction` | live aggregate queries only | bounded analytics companion | `admin/dashboard.blade.php` | none required | existing admin styles | `admin.php` EN/TH | auth; access-admin gate; panel permission | canonical aggregates; no fallbacks | admin panel permission | HARDENED — STATIC ONLY | new route/controller/view static review | NOT VERIFIED — RUNTIME UNAVAILABLE | verify roles, empty DB, and Blade compilation |
| 91 | `/admin/api/analytics` | `admin.api.analytics` | `LottoFinExecutiveDashboardController` | canonical aggregate projections | bounded 0–31 day date range; throttled | `Bet`, `Withdrawal` | bounded aggregate queries | safe KPI JSON | none | none | none | admin EN/TH keys for labels | auth; access-admin; rate protection; no raw models | canonical aggregate data; unavailable trend/profit state | dashboard permission | HARDENED — STATIC ONLY | static route/controller review | NOT VERIFIED — RUNTIME UNAVAILABLE | execute JSON and range-limit tests |
| 92 | `/admin/draws` | `admin.draws.index` | `LottoFinExecutiveDashboardController` | existing draw lifecycle services remain authoritative | bounded projection page | `Draw` | latest 50 draw projection | none | shared admin projection view | none required | existing admin styles | admin EN/TH | auth; draw permission; no browser mutation | canonical draw records | `view draws` permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | verify draw policy and pagination behavior |
| 93 | `/admin/risk` | `admin.risk.index` | `LottoFinExecutiveDashboardController` | existing risk services remain authoritative | none | no fabricated risk model rows | no fabricated records | none | shared explicit unavailable state | none required | existing admin styles | admin EN/TH | auth; risk permission | explicit `UNAVAILABLE` until canonical projection supplied | risk permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | connect canonical risk alert projection without duplication |
| 94 | `/admin/bets` | `admin.bets.index` | `LottoFinExecutiveDashboardController` | existing betting services remain authoritative | latest 100 safe records | `Bet` | bounded latest bet projection | none | shared admin projection view | none required | existing admin styles | admin EN/TH | auth; transaction permission; no mutation controls | canonical bet rows | transaction-history permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | verify pagination and object-level policy expectations |
| 95 | `/admin/wallets` | `admin.wallets.index` | `LottoFinExecutiveDashboardController` | `WalletService` remains canonical for mutations | latest 100 safe projection | `Wallet` | canonical wallet rows | none | shared admin projection view | none required | existing admin styles | admin EN/TH | auth; wallet permission; no browser financial mutations | canonical wallet balances | wallet permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | verify masking/least privilege for deployed roles |
| 96 | `/admin/ledger` | `admin.ledger.index` | `LottoFinExecutiveDashboardController` | finance/ledger services remain canonical | latest 100 safe records | `FinancialTransaction` | canonical financial transaction projection | none | shared admin projection view | none required | existing admin styles | admin EN/TH | auth; finance permission; no adjustment UI | canonical financial transactions | financial-reports permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | verify ledger entry object policy and pagination |
| 97 | `/admin/reconciliation`, `/admin/api/reconciliation` | `admin.reconciliation.index`, `admin.api.reconciliation` | `LottoFinExecutiveDashboardController` | `FinancialReconciliationService` | bounded 0–31 day POST run; throttled | reconciliation DTOs and ledger models | canonical reconciliation service | explicit `NOT_CONFIGURED` GET; canonical report POST | shared explicit state | none required | existing admin styles | admin EN/TH | auth; reconcile permission; GET has no side effect | service report or explicit no bank feed | reconcile-ledger permission | HARDENED — STATIC ONLY | static route/controller review | NOT VERIFIED — RUNTIME UNAVAILABLE | verify report DTO serialization and audit write |
| 98 | `/admin/audits` | `admin.audits.index` | `LottoFinExecutiveDashboardController` | existing audit query service architecture | bounded latest 100 projection | `AuditLog` | canonical immutable audit rows | none | shared admin projection view | none required | existing admin styles | admin EN/TH | auth; audit permission; no raw metadata exposure | canonical audit log safe fields | view-audit-logs permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | bind `AdminAuditQueryService` filters/pagination in runtime |
| 99 | `/admin/kyc` and secured document/action routes | `admin.kyc.index`, `admin.kyc.download`, `admin.kyc.approve`, `admin.kyc.reject` | `LottoFinExecutiveDashboardController` | `AccountVerificationService`, `AccountVerificationDocumentService` | CSRF review form; throttled document/action routes | `KycDocument` | private KYC storage and KYC tables | no public document API | shared admin projection view; private streamed response | none required | existing admin styles | admin EN/TH | auth; KYC permission; object-level document load; private stream; audit | canonical KYC document/service | manage-users permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | verify policy/four-eyes decision and private storage headers |
| 100 | `/admin/compliance` | `admin.compliance.index` | `LottoFinExecutiveDashboardController` | existing compliance/AML services remain authoritative | none | no fabricated compliance rows | no fabricated records | none | shared explicit unavailable state | none required | existing admin styles | admin EN/TH | auth; risk permission; no browser-only mutation | explicit `UNAVAILABLE` until canonical projection supplied | risk permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | connect canonical compliance case projection without duplication |

**Runtime boundary for every row above:** `NOT VERIFIED — RUNTIME UNAVAILABLE`.

## Pages 100–150 independent audit matrix

Every page from 100 through 150 is independently represented. Existing canonical services remain authoritative; unconnected surfaces fail closed rather than fabricate state.

| Page | Title | Route | Route Name | Middleware | Authorization | Controller | Request | Service | Model | Database | API | View | JS | CSS | Translation | Data Source | Financial Impact | Security | Audit Log | Status | Tests | Runtime Status | Remaining Gap |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 100 | Admin Compliance Center | /admin/compliance | admin.compliance.index | `admin.auth` + `access-admin` | view risk alerts | LottoFinExecutiveDashboardController | none | existing compliance/AML services | ComplianceCase, AmlRiskAssessment | canonical compliance tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical compliance projection or explicit unavailable state | read-only unless canonical service is invoked | admin.auth; access-admin; risk permission | canonical AuditLog where mutation exists | HARDENED — STATIC ONLY | static route/controller review | NOT VERIFIED — RUNTIME UNAVAILABLE | bind ComplianceCase/AmlRisk projections without duplicating engines |
| 101 | GLO Prize Claims | /admin/glo/prize-claims | admin.glo.prize-claims.index | `admin.auth` + `access-admin` | manage glo prize claims | LottoFinExecutiveDashboardController | none | GloPrizeClaimService | GloPrizeClaim | canonical GLO claim tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | GloPrizeClaim projection | read-only unless canonical service is invoked | admin.auth; access-admin; manage GLO claims | canonical AuditLog where mutation exists | IMPLEMENTED + HARDENED — STATIC ONLY | parser and static route checks | NOT VERIFIED — RUNTIME UNAVAILABLE | verify policy, exact currency, claim lifecycle and Blade runtime |
| 102 | GLO Prize Claim Detail | /admin/glo/prize-claims/{claim} | admin.glo.prize-claims.show | `admin.auth` + `access-admin` | manage glo prize claims | LottoFinExecutiveDashboardController | opaque/bounded claim reference | GloPrizeClaimService | GloPrizeClaim, Draw, GloTicket | canonical claim/ticket/draw relations | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | authoritative claim projection | read-only unless canonical service is invoked | admin.auth; object-safe bounded reference; claim permission | canonical AuditLog where mutation exists | IMPLEMENTED + HARDENED — STATIC ONLY | static route and parser checks | NOT VERIFIED — RUNTIME UNAVAILABLE | verify detail projection, proportional settlement and audit history |
| 103 | GLO Ticket Freeze Console | /admin/glo/ticket-freezes | admin.glo.ticket-freezes.index | `admin.auth` + `access-admin` | review glo freezes | LottoFinExecutiveDashboardController | none | GloTicketFreezeService | GloTicketFreeze, GloTicket | canonical freeze tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical freeze projection | read-only unless canonical service is invoked | admin.auth; review freeze permission; no browser mutation | canonical AuditLog where mutation exists | IMPLEMENTED + HARDENED — STATIC ONLY | static route and parser checks | NOT VERIFIED — RUNTIME UNAVAILABLE | wire existing freeze state machine into this URL without duplicate actions |
| 104 | GLO Ticket Freeze Detail | /admin/glo/ticket-freezes/{token} | admin.glo.ticket-freezes.show | `admin.auth` + `access-admin` | review glo freezes | LottoFinExecutiveDashboardController | bounded opaque case token | GloTicketFreezeService | GloTicketFreeze, GloTicket | canonical freeze/ticket relations | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical freeze projection | read-only unless canonical service is invoked | admin.auth; object authorization; no raw ticket ID exposure | canonical AuditLog where mutation exists | IMPLEMENTED + HARDENED — STATIC ONLY | static route and parser checks | NOT VERIFIED — RUNTIME UNAVAILABLE | verify opaque reference and policy behavior |
| 105 | Prize Settlement Review | /admin/glo/settlements | admin.glo.settlements.index | `admin.auth` + `access-admin` | process settlements | LottoFinExecutiveDashboardController | none | FinancialReconciliationService; GloPrizeClaimService | GloPrizeClaim, LedgerEntry | canonical settlement and ledger data | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED until canonical settlement projection is connected | read-only unless canonical service is invoked | admin.auth; process-settlement permission; read-only route | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect canonical settlement review projection; no PAY NOW shortcut |
| 106 | Wallet Operations Center | /admin/wallet-operations | admin.wallet-operations.index | `admin.auth` + `access-admin` | manage wallet | LottoFinExecutiveDashboardController | none | WalletService; FinancialReconciliationService | Wallet, WalletLedger | canonical wallet/ledger data | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; wallet permission; no balance edit | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | add currency-grouped canonical projection |
| 107 | Payment Methods Management | /admin/payment-methods | admin.payment-methods.index | `admin.auth` + `access-admin` | manage payouts | LottoFinExecutiveDashboardController | none | PaymentGatewayManager | PaymentMethodConfig, PaymentProvider | payment configuration tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; payout permission; secrets excluded | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | bind safe provider capability projection |
| 108 | Withdrawal Methods Management | /admin/withdrawal-methods | admin.withdrawal-methods.index | `admin.auth` + `access-admin` | manage payouts | LottoFinExecutiveDashboardController | none | PaymentGatewayManager | PaymentMethodConfig, PaymentProvider | payment configuration tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; payout permission; secrets excluded | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | bind capability-aware withdrawal projection |
| 109 | Payment Operations | /admin/payments | admin.payments.index | `admin.auth` + `access-admin` | manage payouts | LottoFinExecutiveDashboardController | bounded latest projection | Payment model/services | Payment | payment table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical payment rows where existing projection permits | read-only unless canonical service is invoked | admin.auth; payout permission; read-only default | canonical AuditLog where mutation exists | HARDENED — STATIC ONLY | static route/controller review | NOT VERIFIED — RUNTIME UNAVAILABLE | verify safe references and provider-state normalization |
| 110 | Payment Detail | /admin/payments/{payment} | admin.payments.show | `admin.auth` + `access-admin` | manage payouts | LottoFinExecutiveDashboardController | bounded payment reference | PaymentCallbackService; payment services | Payment, PaymentReconciliation | payment/reconciliation tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED detail state | read-only unless canonical service is invoked | admin.auth; object authorization; no webhook secrets | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | add policy-protected detail projection |
| 111 | Payment Event / Webhook Audit | /admin/payment-events | admin.payment-events.index | `admin.auth` + `access-admin` | manage payouts | LottoFinExecutiveDashboardController | none | PaymentWebhookService | PaymentWebhook | webhook evidence table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; safe metadata only; no replay | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect verified-event safe projection |
| 112 | Payment Exceptions | /admin/payment-exceptions | admin.payment-exceptions.index | `admin.auth` + `access-admin` | manage payouts | LottoFinExecutiveDashboardController | none | PaymentReconciliationService | PaymentReconciliation | reconciliation table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; canonical evidence only | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect canonical exception query |
| 113 | Draw Lifecycle Operations | /admin/draw-lifecycle | admin.draw-lifecycle.index | `admin.auth` + `access-admin` | manage draws | LottoFinExecutiveDashboardController | bounded latest projection | draw lifecycle services | Draw | draw tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; manage draws; no browser transition | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | bind actual lifecycle state service |
| 114 | Result Publication Control | /admin/result-publication | admin.result-publication.index | `admin.auth` + `access-admin` | view results | LottoFinExecutiveDashboardController | none | GloResultPublicationService | DrawPublication, DrawResult | publication/result tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; view results; no browser publication | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect verified publication projection |
| 115 | Result Import / Provenance | /admin/result-imports | admin.result-imports.index | `admin.auth` + `access-admin` | view results | LottoFinExecutiveDashboardController | bounded latest projection | GloResultImportService; ResultImportService | GloResultImport | import/provenance tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; view results; no fixture activation | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect import health snapshot |
| 116 | Result Source Health | /admin/result-sources | admin.result-sources.index | `admin.auth` + `access-admin` | view results | LottoFinExecutiveDashboardController | none | ProviderHealthService; result source services | provider/result source records | configured source state | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; bounded backend health only | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect backend health snapshot |
| 117 | Lottery Product Catalog | /admin/lotteries | admin.lotteries.index | `admin.auth` + `access-admin` | manage system settings | LottoFinExecutiveDashboardController | none | PublicLotteryCatalogService | TicketProduct | configured catalogue | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; no inactive product purchase controls | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect canonical catalogue projection |
| 118 | Lottery Rules / Pricing | /admin/lottery-rules | admin.lottery-rules.index | `admin.auth` + `access-admin` | manage system settings | LottoFinExecutiveDashboardController | none | canonical pricing/rule services | TicketProduct, configuration | versioned configured rules | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; no silent economic mutation | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect versioned rules projection |
| 119 | Fee Schedule Management | /admin/fees | admin.fees.index | `admin.auth` + `access-admin` | manage system settings | LottoFinExecutiveDashboardController | none | canonical fee services | configuration/fee projection | configured fee source | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; no presentation/economics drift | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect fee version projection |
| 120 | Account Grade Admin | /admin/account-grades | admin.account-grades.index | `admin.auth` + `access-admin` | manage system settings | LottoFinExecutiveDashboardController | none | AccountGradeService | AccountGradeSnapshot, GradeDiscountSnapshot | grade tables/configuration | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; no direct user grade edit | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect authoritative grade projection |
| 121 | Account Verification Operations | /admin/account-verification | admin.account-verification.index | `admin.auth` + `access-admin` | manage users | LottoFinExecutiveDashboardController | bounded latest projection | AccountVerificationService | KycDocument, KycVerification | private KYC data | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; object-level KYC policy | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect queue projection without bypassing KYC service |
| 122 | Responsible Gaming Operations | /admin/responsible-gaming | admin.responsible-gaming.index | `admin.auth` + `access-admin` | manage users | LottoFinExecutiveDashboardController | none | ResponsibleGamingService | ResponsibleGamingLimit, PlayerProtectionCase | RG tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; no browser bypass | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect aggregated RG operational projection |
| 123 | Self-Exclusion Operations | /admin/self-exclusion | admin.self-exclusion.index | `admin.auth` + `access-admin` | manage users | LottoFinExecutiveDashboardController | none | SelfExclusionService | SelfExclusion | self-exclusion table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; all actions remain canonical service actions | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect safe status projection |
| 124 | User Operations | /admin/users | admin.users.index | `admin.auth` + `access-admin` | manage users | LottoFinExecutiveDashboardController | bounded latest projection | UserResource/AdminAccess | User | users table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; masked PII; no generic mutation | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | use existing UserResource route or safe list projection |
| 125 | User Detail | /admin/users/{user} | admin.users.show | `admin.auth` + `access-admin` | manage users | LottoFinExecutiveDashboardController | bounded user reference | UserResource/policies | User and authorized relations | canonical user relations | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; object policy; no secret fields | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | bind object-level detail projection |
| 126 | User Financial Profile | /admin/users/{user}/finance | admin.users.finance | `admin.auth` + `access-admin` | financial reports | LottoFinExecutiveDashboardController | bounded user reference | WalletService; reconciliation services | Wallet, LedgerEntry, Payment | canonical financial tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; currency-separated exact money; read-only | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | bind currency-grouped financial projection |
| 127 | Bet Detail | /admin/bets/{bet} | admin.bets.show | `admin.auth` + `access-admin` | transaction history | LottoFinExecutiveDashboardController | bounded bet reference | betting services | Bet, BetItem | bet tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; object authorization; no payout edit | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | bind safe bet detail projection |
| 128 | Ticket Detail | /admin/tickets/{ticket} | admin.tickets.show | `admin.auth` + `access-admin` | manage users | LottoFinExecutiveDashboardController | bounded ticket reference | ticket services | Ticket, GloTicket | ticket tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; no unnecessary raw ID exposure | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | bind policy-protected ticket projection |
| 129 | Ticket Verification Operations | /admin/ticket-verification | admin.ticket-verification.index | `admin.auth` + `access-admin` | manage users | LottoFinExecutiveDashboardController | bounded verification request | TicketBarcodeService; TicketAuthenticityService | LotteryTicketVerification | verification table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; approved parser authority | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect canonical verification records |
| 130 | Prize Claim Review Queue | /admin/prize-claim-review | admin.prize-claim-review.index | `admin.auth` + `access-admin` | manage glo prize claims | LottoFinExecutiveDashboardController | bounded claim queue | GloPrizeClaimService | GloPrizeClaim | claim tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | claim projection available through Page 101 lane | read-only unless canonical service is invoked | admin.auth; claim permission; no browser final approval | canonical AuditLog where mutation exists | HARDENED — STATIC ONLY | static route/controller review | NOT VERIFIED — RUNTIME UNAVAILABLE | bind dedicated queue filters and policy checks |
| 131 | Commission Operations | /admin/commissions | admin.commissions.index | `admin.auth` + `access-admin` | view commissions | LottoFinExecutiveDashboardController | bounded latest projection | AgentCommissionService | AgentCommission | commission tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; commission permission; exact money | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect canonical commission projection |
| 132 | Agent Dashboard | /agent | agent.dashboard | `auth` | agent owner | AgentPortalController | authenticated session only | AgentReportingService | Agent, AgentCommission, Bet | agent/referral/commission data | none | agent/portal.blade.php | none | existing app styles | agent.php EN/TH | server-calculated report | read-only unless canonical service is invoked | auth; active agent owner scope | canonical AuditLog where mutation exists | IMPLEMENTED + HARDENED — STATIC ONLY | parser and static route checks | NOT VERIFIED — RUNTIME UNAVAILABLE | verify agent status and report DTO runtime |
| 133 | Agent Commissions | /agent/commissions | agent.commissions | `auth` | agent owner | AgentPortalController | authenticated session only | AgentCommissionService | AgentCommission | commission table | none | agent/portal.blade.php | none | existing app styles | agent.php EN/TH | canonical commission rows | read-only unless canonical service is invoked | auth; agent owner scope; exact strings | canonical AuditLog where mutation exists | IMPLEMENTED + HARDENED — STATIC ONLY | parser and static route checks | NOT VERIFIED — RUNTIME UNAVAILABLE | execute owner-scope and currency tests |
| 134 | Agent Settlements | /agent/settlements | agent.settlements | `auth` | agent owner | AgentPortalController | authenticated session only | AgentSettlementService | AgentCommission | commission/financial tables | none | agent/portal.blade.php | none | existing app styles | agent.php EN/TH | paid commission evidence only | read-only unless canonical service is invoked | auth; read-only web route; no payout mutation | canonical AuditLog where mutation exists | IMPLEMENTED + HARDENED — STATIC ONLY | parser and static route checks | NOT VERIFIED — RUNTIME UNAVAILABLE | verify settlement history projection |
| 135 | Agent Statement | /agent/statement | agent.statement | `auth` | agent owner | AgentPortalController | authenticated session only | AgentCommissionService | AgentCommission | commission table | none | agent/portal.blade.php | none | existing app styles | agent.php EN/TH | canonical commission rows | read-only unless canonical service is invoked | auth; agent owner; no cross-currency aggregation | canonical AuditLog where mutation exists | IMPLEMENTED + HARDENED — STATIC ONLY | static route/controller review | NOT VERIFIED — RUNTIME UNAVAILABLE | verify running-balance semantics if later configured |
| 136 | Referral Overview | /agent/referrals | agent.referrals | `auth` | agent owner | AgentPortalController | authenticated session only | AgentReferralService | User preferences/referral attribution | user/referral data | none | agent/portal.blade.php | none | existing app styles | agent.php EN/TH | owner-scoped referral projection | read-only unless canonical service is invoked | auth; approved referral projection; masked reference | canonical AuditLog where mutation exists | IMPLEMENTED + HARDENED — STATIC ONLY | static route/controller review | NOT VERIFIED — RUNTIME UNAVAILABLE | verify referral privacy projection |
| 137 | Referral Detail | /agent/referrals/{referral} | agent.referrals.show | `auth` | agent owner | AgentPortalController | opaque hashed referral reference | AgentReferralService | User | user/referral data | none | agent/portal.blade.php | none | existing app styles | agent.php EN/TH | owner-scoped referral row | read-only unless canonical service is invoked | auth; owner-scoped opaque reference | canonical AuditLog where mutation exists | IMPLEMENTED + HARDENED — STATIC ONLY | static route/controller review | NOT VERIFIED — RUNTIME UNAVAILABLE | verify no private referred-user leakage |
| 138 | Support Center / Inbox | /support | support.index | `auth` | authenticated player | SupportPortalController | authenticated session only | PublicSupportService; ContactMessageService | ContactMessage has no owner binding | contact table | none | support/portal.blade.php | none | existing app styles | support.php EN/TH | explicit NOT_CONFIGURED — no anonymous message leakage | read-only unless canonical service is invoked | auth; fail closed because owner scope absent | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route/controller review | NOT VERIFIED — RUNTIME UNAVAILABLE | add owner-scoped support case contract before showing inbox |
| 139 | Support Request Detail | /support/{reference} | support.show | `auth` | authenticated player | SupportPortalController | bounded public reference | ContactMessageService | ContactMessage | contact table | none | support/portal.blade.php | none | existing app styles | support.php EN/TH | explicit NOT_CONFIGURED | read-only unless canonical service is invoked | auth; no owner contract, no record lookup | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route/controller review | NOT VERIFIED — RUNTIME UNAVAILABLE | bind object ownership before detail reads |
| 140 | Notification Center | /notifications | notifications.index | `auth` | authenticated player | notifications.index | authenticated session only | Notification API/model | Notification | notification table | existing notification API remains canonical | notifications/index.blade.php | existing notification JS/API | existing app styles | notifications.php EN/TH | owner-scoped notification rows | read-only unless canonical service is invoked | auth; user_id from session only | canonical AuditLog where mutation exists | IMPLEMENTED + HARDENED — STATIC ONLY | parser and static route checks | NOT VERIFIED — RUNTIME UNAVAILABLE | verify notification cast/runtime and owner scope |
| 141 | System Health | /health | health.canonical | public health route | public health projection | HealthController | none | SystemHealthService | health DTOs | backend dependencies | health JSON | JSON response | none | none | existing observability translations | database/cache/storage/queue checks | read-only unless canonical service is invoked | public-safe dependency state | canonical AuditLog where mutation exists | EXISTING + HARDEN | existing source inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | execute readiness/dependency failure matrix |
| 142 | Operator Metrics | /metrics | metrics | auth + access-metrics | access metrics | MetricsController | operator auth | FinancialMetricsCollector | metrics DTOs | backend telemetry | Prometheus/JSON | text/JSON response | none | none | none | telemetry collector | read-only unless canonical service is invoked | auth; access-metrics gate | canonical AuditLog where mutation exists | EXISTING + HARDEN | existing source inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | verify no public exposure and safe labels |
| 143 | Queue / Worker Health | /admin/queues | admin.queues.index | `admin.auth` + `access-admin` | view audit logs | LottoFinExecutiveDashboardController | none | Queue health services | QueueHealthReport | backend telemetry | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; audit/operations permission | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect QueueHealthReport projection |
| 144 | Scheduled Tasks | /admin/scheduler | admin.scheduler.index | `admin.auth` + `access-admin` | view audit logs | LottoFinExecutiveDashboardController | none | scheduler metadata | scheduler metadata | scheduler backend | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; no arbitrary command execution | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect safe scheduler snapshot |
| 145 | Cache / Session Operations | /admin/runtime | admin.runtime.index | `admin.auth` + `access-admin` | view audit logs | LottoFinExecutiveDashboardController | none | SystemHealthService | health/runtime DTOs | backend dependencies | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; no secrets or flush controls | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect safe runtime status |
| 146 | API Status Center | /admin/api-status | admin.api-status.index | `admin.auth` + `access-admin` | view audit logs | LottoFinExecutiveDashboardController | none | ProviderHealthService | ProviderHealthData, PaymentProvider | provider tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; no credentials; backend snapshots | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect provider health sheet |
| 147 | Webhook Audit Center | /admin/webhooks | admin.webhooks.index | `admin.auth` + `access-admin` | view audit logs | LottoFinExecutiveDashboardController | none | PaymentWebhookService | PaymentWebhook | webhook evidence table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; raw payload/signature excluded | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect safe webhook audit projection |
| 148 | Security Audit Center | /admin/security | admin.security.index | `admin.auth` + `access-admin` | view audit logs | LottoFinExecutiveDashboardController | bounded latest projection | AdminAuditQueryService | AuditLog, SecurityEvent | audit/security tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; no secrets/tokens | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect security event projection |
| 149 | Release / Deployment Status | /admin/release | admin.release.index | `admin.auth` + `access-admin` | view audit logs | LottoFinExecutiveDashboardController | none | release/readiness services | OperationalReportJob and build metadata | runtime/build state | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; secrets/paths excluded | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect real release evidence |
| 150 | Production Cutover Control Center | /admin/cutover | admin.cutover.index | `admin.auth` + `access-admin` | manage system settings | ReleaseOperationsController | bounded admin GET | release/readiness evidence projection | none | filesystem/configuration/deployment evidence | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | actual artifact evidence or NOT_VERIFIED | read-only; no browser shell execution | admin.auth; access-admin; system-settings permission | no mutation | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | external deployment, backup, queue, provider, and rollback evidence remain required |

## Pages 77–100 changed-file manifest

| Path | `# TYPE` | `# PURPOSE` |
|---|---|---|
| `app/Http/Controllers/GloResultsPageController.php` | PHP controller | Replaced fabricated results and ticket-check payloads with the existing canonical public result and ticket-check projections. |
| `app/Http/Controllers/Admin/LottoFinExecutiveDashboardController.php` | PHP controller | Added authorized, bounded, canonical admin projections; removed fabricated KPI/trend/reconciliation values; uses configured-currency exact Money formatting with explicit UNAVAILABLE fallback; secured private KYC streaming and canonical KYC review delegation; made unsupported browser mutations explicit. |
| `app/Providers/AuthServiceProvider.php` | PHP provider | Added the `access-admin` gate backed by `AdminAccess` panel authorization. |
| `app/Http/Middleware/Authenticate.php` | PHP middleware | Keeps existing authentication behavior and redirects unauthenticated `/admin/*` requests to the Filament login boundary. |
| `bootstrap/app.php` | PHP bootstrap | Registers the explicit `admin.auth` middleware alias without changing the global authentication alias. |
| `app/Providers/AppServiceProvider.php` | PHP provider | Added authenticated operator rate protection for admin analytics and reconciliation endpoints. |
| `app/Services/Payment/PaymentCallbackService.php` | PHP service | Bounded browser-return references and preserved owner-scoped, read-only authoritative payment-state projection. |
| `app/Services/PublicPages/ResultsPageService.php` | PHP service | Public results rows are limited to published/completed draws whose scheduled time has passed. |
| `routes/web.php` | PHP route file | Resolved `/results` to the canonical public controller and added authentication, authorization, throttling, explicit reconciliation POST, secured KYC document/action routes, and non-mutating unsupported withdrawal responses. |
| `resources/views/results/index.blade.php` | Blade view | Public results hub using only canonical published rows, explicit source states, safe table overflow, status text, and translated copy. |
| `resources/views/admin/dashboard.blade.php` | Blade view | Shared authorized admin projection view with no fabricated financial values, explicit unavailable states, semantic tables, and translated labels. |
| `lang/en/results.php` | PHP translation map | English results-hub labels and explicit public-data states. |
| `lang/th/results.php` | PHP translation map | Exact Thai-locale key parity for the results-hub map. |
| `lang/en/admin.php` | PHP translation map | English admin labels and explicit operational states. |
| `lang/th/admin.php` | PHP translation map | Exact Thai-locale key parity for the admin map. |
| `tests/Feature/Pages77To100StaticContractTest.php` | PHPUnit feature/static contract test | Checks admin route boundary, known fixture removal, read-only payment-return lane, translation parity, and one audit row per page. |
| `app/Services/Account/AccountVerificationService.php` | PHP service | Added bounded, reviewer-bound opaque document-token resolution so admin KYC routes do not expose numeric document IDs while reusing the canonical KYC service. |
| `audit.md` | Markdown audit report | Added independent Page 77–100 audit matrix, runtime boundary, and changed-file manifest. |
| `PAGES-77-100-IMPLEMENTATION-REPORT.md` | Markdown delivery report | Complete contents, `# TYPE`, and `# PURPOSE` for every implementation file changed in this pass. |

| `resources/views/home/check.blade.php` | Blade view | Page 78 translated ticket-check labels while retaining CSRF, six-digit validation, server-side result state, and status messaging. |
| `resources/views/home/sales-points.blade.php` | Blade view | Page 79 translated bounded sales-point search, pagination, empty, and unavailable states. |
| `resources/views/privacy/index.blade.php` | Blade view | Page 80 policy surface with translated navigation, metadata, search, unavailable, and support labels. |
| `resources/views/download/index.blade.php` | Blade view | Page 82 configured app-destination surface with translated safety, integrity, and unavailable states. |
| `resources/views/account-grade/index.blade.php` | Blade view | Page 83 public grade explainer with translated navigation, configured-tier labels, private-state copy, and no fabricated account state. |
| `lang/en/public_pages.php` | PHP translation map | Added Page 80 and Page 82 visible interface labels. |
| `lang/th/public_pages.php` | PHP translation map | Exact EN/TH key and placeholder parity for Page 80 and Page 82 labels. |
| `lang/en/account_info.php` | PHP translation map | Added Page 83 visible interface labels. |
| `lang/th/account_info.php` | PHP translation map | Exact EN/TH key and placeholder parity for Page 83 labels. |
| `lang/en/home.php` | PHP translation map | Added Page 78–79 labels and count/page placeholders. |
| `lang/th/home.php` | PHP translation map | Exact EN/TH key and placeholder parity for Page 78–79 labels. |

All runtime-dependent rows and checks remain exactly: `NOT VERIFIED — RUNTIME UNAVAILABLE`.

## Pages 77–100 validation evidence

| Check | Result |
|---|---|
| PHP parser for changed PHP and translation files | Passed with `php-parser` static parser; this is not a PHP runtime check. |
| `git diff --check` | Passed. |
| Vite asset build | Passed with `npm run build`. |
| Static fixture-marker scan for Pages 77, 90, and admin view | Passed; known fabricated values are absent from the changed projections/views. |
| Static route, audit-row, and translation-map checks | Passed, including exact EN/TH keys and placeholders for results, admin, public-pages, account-info, and home maps. |
| Pages 80, 82, and 83 visible-label review | Passed static review after moving remaining visible interface labels into translation maps. |
| Laravel route list | NOT VERIFIED — RUNTIME UNAVAILABLE |
| Blade compilation | NOT VERIFIED — RUNTIME UNAVAILABLE |
| PHPUnit/Pest | NOT VERIFIED — RUNTIME UNAVAILABLE |
| Database, browser, payment-provider, queue, and accessibility checks | NOT VERIFIED — RUNTIME UNAVAILABLE |

## Pages 150–165 continuation audit matrix

Page 150 is re-audited above through `ReleaseOperationsController`; Pages 151–165 are listed here as independent rows.

| Page | Title | Route | Route Name | Middleware | Authorization | Controller | Request | Service | Model | Database | API | View | JS | CSS | Translation | Data Source | Financial Impact | Security | Audit Log | Status | Tests | Runtime Status | Remaining Gap |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 151 | Release Manifest | /admin/release-manifest | admin.release-manifest.index | `admin.auth` + `access-admin` | admin.auth; access-admin; system-settings | ReleaseOperationsController | bounded admin GET | filesystem/config evidence projection | none | composer/package/Rust/build artifacts where present | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | real artifact hashes or NOT_CONFIGURED | read-only | admin.auth; access-admin; system-settings | no mutation | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | deploy metadata and runtime artifact verification remain external |
| 152 | Environment / Configuration Matrix | /admin/configuration | admin.configuration.index | `admin.auth` + `access-admin` | admin.auth; access-admin; system-settings; secret values excluded | ReleaseOperationsController | bounded admin GET | configuration repository presence projection | none | runtime configuration | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | configured/missing metadata only | read-only | admin.auth; access-admin; system-settings; secret values excluded | no mutation | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | external provider health and secret validation remain unverified |
| 153 | Secrets and Key Management Status | /admin/secrets | admin.secrets.index | `admin.auth` + `access-admin` | admin.auth; access-admin; system-settings | ReleaseOperationsController | bounded admin GET | secret-presence metadata only | none | configuration presence | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | presence only; no secret material | read-only | admin.auth; access-admin; system-settings | no mutation | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | key age/rotation verification requires deployment evidence |
| 154 | Database Migration Control | /admin/migrations | admin.migrations.index | `admin.auth` + `access-admin` | admin.auth; access-admin; system-settings | ReleaseOperationsController | bounded admin GET | migration filesystem evidence plus NOT_VERIFIED runtime fields | none | migration files | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | filesystem count only; no migration execution | read-only | admin.auth; access-admin; system-settings | no mutation | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | schema status requires Laravel/database runtime |
| 155 | Database Backup Control | /admin/backups | admin.backups.index | `admin.auth` + `access-admin` | admin.auth; access-admin; system-settings | ReleaseOperationsController | bounded admin GET | backup filesystem evidence plus NOT_VERIFIED fields | none | storage/backups if present | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | actual file evidence only | read-only | admin.auth; access-admin; system-settings | no mutation | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | backup integrity/encryption/restore evidence required |
| 156 | Backup Restore Verification | /admin/restore-verification | admin.restore-verification.index | `admin.auth` + `access-admin` | admin.auth; access-admin; system-settings; no shell execution | ReleaseOperationsController | bounded admin GET | fail-closed restore projection | none | none | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | no financial mutation | admin.auth; access-admin; system-settings; no shell execution | no mutation | NOT_CONFIGURED — FAIL CLOSED | static route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | controlled non-production restore service required |
| 157 | Disaster Recovery Center | /admin/disaster-recovery | admin.disaster-recovery.index | `admin.auth` + `access-admin` | admin.auth; access-admin; system-settings | ReleaseOperationsController | bounded admin GET | fail-closed DR evidence projection | none | external infrastructure evidence | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_VERIFIED fields | read-only | admin.auth; access-admin; system-settings | no mutation | NOT_VERIFIED — STATIC ONLY | static route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | RPO/RTO, replica, queue, DNS and Rust evidence external |
| 158 | Failover / High Availability Status | /admin/high-availability | admin.high-availability.index | `admin.auth` + `access-admin` | admin.auth; access-admin; system-settings | ReleaseOperationsController | bounded admin GET | fail-closed infrastructure projection | none | external infrastructure evidence | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_VERIFIED fields | read-only | admin.auth; access-admin; system-settings | no mutation | NOT_VERIFIED — STATIC ONLY | static route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | node and failover health require infrastructure telemetry |
| 159 | Incident Command Center | /admin/incidents | admin.incidents.index | `admin.auth` + `access-admin` | admin.auth; access-admin; audit permission | ReleaseOperationsController | bounded admin GET | fail-closed incident projection | none | none | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | canonical incident store required |
| 160 | Incident Detail | /admin/incidents/{reference} | admin.incidents.show | `admin.auth` + `access-admin` | admin.auth; access-admin; audit permission; bounded reference | ReleaseOperationsController | bounded opaque reference | fail-closed incident projection | none | none | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission; bounded reference | no mutation | NOT_CONFIGURED — FAIL CLOSED | static route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | incident object authorization requires canonical store |
| 161 | Deployment Approval Gate | /admin/deployment-approval | admin.deployment-approval.index | `admin.auth` + `access-admin` | admin.auth; access-admin; audit permission | ReleaseOperationsController | bounded admin GET | fail-closed approval projection | none | none | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | no browser approval mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | canonical release approval service required |
| 162 | Deployment History | /admin/deployments | admin.deployments.index | `admin.auth` + `access-admin` | admin.auth; access-admin; audit permission | ReleaseOperationsController | bounded admin GET | fail-closed deployment history projection | none | none | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | deployment metadata source required |
| 163 | Rollback Control | /admin/rollback | admin.rollback.index | `admin.auth` + `access-admin` | admin.auth; access-admin; system-settings | ReleaseOperationsController | bounded admin GET | fail-closed rollback request projection | none | none | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | no shell/deployment mutation | admin.auth; access-admin; system-settings | no mutation | NOT_CONFIGURED — FAIL CLOSED | static route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | controlled rollback service and approval workflow required |
| 164 | Feature Flag Operations | /admin/feature-flags | admin.feature-flags.index | `admin.auth` + `access-admin` | admin.auth; access-admin; system-settings | ReleaseOperationsController | bounded admin GET | server-side config flags only | none | config/features if present | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | actual configured flags or NOT_CONFIGURED | read-only | admin.auth; access-admin; system-settings | no mutation | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | flag mutation service not configured |
| 165 | Configuration Change Audit | /admin/configuration-audit | admin.configuration-audit.index | `admin.auth` + `access-admin` | admin.auth; access-admin; audit permission | ReleaseOperationsController | bounded admin GET | fail-closed immutable audit projection | none | none | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | dedicated configuration audit source required |
## Pages 100–150 changed-file manifest

The following files were changed or created for the Pages 100–150 continuation. Complete contents are provided in the sequential implementation reports in the workspace.

| Path | `# TYPE` | `# PURPOSE` |
|---|---|---|
| `app/Http/Controllers/Admin/LottoFinExecutiveDashboardController.php` | PHP controller | Extends the existing authorized admin projection lane with GLO claim/freeze rows and explicit fail-closed states for new operational routes. |
| `app/Http/Controllers/Agent/AgentPortalController.php` | PHP controller | Authenticated owner-scoped agent dashboard, commission, settlement, statement, referral, and referral-detail projections. |
| `app/Http/Controllers/NotificationCenterController.php` | PHP controller | Read-only authenticated owner-scoped notification center using the existing notification model/API architecture. |
| `app/Http/Controllers/Support/SupportPortalController.php` | PHP controller | Authenticated support boundary that fails closed because anonymous ContactMessage rows have no owner contract. |
| `routes/web.php` | PHP route file | Adds Pages 100–150 operational routes, authenticated agent routes, support routes, and notification center routes without removing existing endpoints. |
| `resources/views/admin/dashboard.blade.php` | Blade view | Extends the existing admin projection view with GLO claim/freeze detail columns and truthful state messaging. |
| `resources/views/agent/portal.blade.php` | Blade view | Localized responsive agent portal projection with no private player data or browser-side financial mutation. |
| `resources/views/support/portal.blade.php` | Blade view | Localized support center fail-closed state and safe public contact handoff. |
| `resources/views/notifications/index.blade.php` | Blade view | Localized owner-scoped notification projection with empty/state messaging. |
| `lang/en/admin.php` | PHP translation map | English Page 100–150 admin panel names, GLO fields, and operational labels. |
| `lang/th/admin.php` | PHP translation map | Matching Thai-locale admin key set for Page 100–150 operational labels. |
| `lang/en/agent.php` | PHP translation map | English agent portal labels and explicit states. |
| `lang/th/agent.php` | PHP translation map | Matching Thai-locale agent portal key set. |
| `lang/en/support.php` | PHP translation map | English support center fail-closed labels. |
| `lang/th/support.php` | PHP translation map | Matching Thai-locale support center key set. |
| `lang/en/notifications.php` | PHP translation map | English notification center labels and states. |
| `lang/th/notifications.php` | PHP translation map | Matching Thai-locale notification center key set. |
| `tests/Feature/Pages77To100StaticContractTest.php` | PHP static contract test | Extends static route coverage to Pages 100–150 and checks new translation namespaces. |
| `audit.md` | Markdown audit report | Adds independent Page 100–150 matrix, changed-file manifest, status summary, and runtime boundary. |
| `PAGES-100-150-IMPLEMENTATION-REPORT-PART-1.md` | Markdown implementation report | Complete contents for files 1–15 in sequential output order. |
| `PAGES-100-150-IMPLEMENTATION-REPORT-PART-2.md` | Markdown implementation report | Complete contents for the remaining files in sequential output order. |

## Pages 100–150 validation evidence

| Check | Result |
|---|---|
| Static PHP parser | Passed for 14 changed PHP files. |
| EN/TH key parity | Passed for `admin`, `agent`, `support`, and `notifications`. |
| Pages 100–150 route contract scan | Passed for 43 route contracts. |
| Page 100–150 audit row scan | Passed for all rows 100 through 150. |
| Three-dot shortening marker scan on newly created files | Passed. |
| `git diff --check` | Passed. |
| Laravel route listing | NOT VERIFIED — RUNTIME UNAVAILABLE |
| Blade compilation | NOT VERIFIED — RUNTIME UNAVAILABLE |
| PHPUnit/Pest | NOT VERIFIED — RUNTIME UNAVAILABLE |
| Database, browser, payment-provider, queue, storage, and accessibility checks | NOT VERIFIED — RUNTIME UNAVAILABLE |

## Pages 100–150 implementation summary

```text
PAGES 100–150

TOTAL PAGES: 51
IMPLEMENTED: 14
HARDENED: 9
NOT_CONFIGURED: 28
DATA IMPORT REQUIRED: 0
EXTERNAL VERIFICATION REQUIRED: 0
ACCESS CONTROL VERIFICATION REQUIRED: 51
RUNTIME UNAVAILABLE: 51
BLOCKED: 0

FINANCIAL FINDINGS: New financial/admin operational aliases fail closed unless an existing canonical projection is connected; no browser-only money mutation was added.
KYC FINDINGS: Existing canonical KYC service and reviewer-bound document-token lane remain authoritative; new account-verification operations route is fail closed.
RESPONSIBLE GAMING FINDINGS: Existing responsible-gaming and self-exclusion services remain authoritative; new browser mutation bypasses were not added.
GLO CLAIM FINDINGS: GLO claims and ticket freezes reuse existing models/services for bounded read projections; settlement review remains fail closed until a canonical projection is connected.
AGENT FINDINGS: Agent routes now require authentication and resolve the agent from the authenticated session; commission and referral data are owner-scoped.
SUPPORT FINDINGS: Support inbox/detail fail closed because the existing anonymous ContactMessage model has no owner-scoped case contract.
OBSERVABILITY FINDINGS: Existing health and metrics routes are preserved; new admin operational health surfaces remain fail closed until backend snapshots are connected.
DEPLOYMENT FINDINGS: Release and cutover pages are explicit NOT_CONFIGURED projections; no browser shell or deployment mutation was added.
SECURITY FINDINGS: Admin routes retain `admin.auth` and `access-admin`; panel permission checks remain in the controller; support and agent routes use authenticated sessions.
REMAINING GAPS: Runtime, route dispatch, Blade, database, authorization, payment-provider, queue, storage, browser, accessibility, and full test gates remain unverified.
```

## Pages 150–165 phase changed-file manifest

| Path | `# TYPE` | `# ROLE` | `# DOMAIN` | `# WHY CHANGED` | `# DEPENDENCIES` | `# SECURITY IMPACT` | `# TEST COVERAGE` |
|---|---|---|---|---|---|---|---|
| `app/Http/Controllers/Admin/ReleaseOperationsController.php` | PHP controller | Read-only operational projection | release, configuration, backup, DR, deployment | Adds evidence-based Pages 150–165 operations surfaces without browser shell or deployment mutation. | `AdminAccess`, Laravel config/filesystem helpers | Admin authentication and panel-specific permission; secrets and infrastructure details are not exposed. | Static parser, route scan, and diff check; runtime unverified. |
| `resources/views/admin/release-operations.blade.php` | Blade view | Operations evidence table | release operations UI | Adds truthful state rendering for release, configuration, backup, DR, incident, deployment, rollback, and feature-flag surfaces. | `admin_release` translations, existing admin layout/styles | `noindex`; escaped values; no mutation controls. | Static source review; Blade runtime unverified. |
| `lang/en/admin_release.php` | PHP translation map | English operator copy | release operations localization | Adds all new operator-facing labels and states. | Laravel translation loader | Prevents raw translation keys in the new view. | PHP parser and EN/TH key parity. |
| `lang/th/admin_release.php` | PHP translation map | Thai-locale operator copy | release operations localization | Maintains exact key parity with English. | Laravel translation loader | Prevents raw translation keys in the new view. | PHP parser and EN/TH key parity. |
| `routes/web.php` | PHP route file | Named admin routes | Pages 150–165 HTTP surface | Adds release-manifest, configuration, secrets, migrations, backup, restore, DR, HA, incident, deployment, rollback, feature-flag, and configuration-audit routes while preserving existing route names. | `ReleaseOperationsController`, existing `admin.auth`, `access-admin` | Strict incident-reference constraint; admin authentication and authorization group. | Static route scan and PHP parser; Laravel dispatch unverified. |
| `audit.md` | Markdown audit report | Phase audit matrix | Pages 150–165 | Adds Page 150 re-audit and independent rows for Pages 151–165. | repository evidence | Records fail-closed and runtime boundaries. | Row scan and static review. |
| `PAGES-150-250-ARCHITECTURE-INVENTORY.md` | Markdown inventory | Pre-work architecture tree | repository inventory | Records the complete depth-four inventory required before the Pages 150–250 phase. | filesystem inventory | Documents canonical architecture before changes. | File count and static generation. |
| `tests/Feature/Pages150To250StaticContractTest.php` | PHP static contract test | Static structural contracts | Pages 150–250 route/security/matrix contracts | Verifies route coverage, translation parity, read-only controller boundaries, canonical finance/lottery/Rust references, and every audit row. | PHPUnit/Laravel test harness; repository files | Detects route drift, raw secret/shell mutation patterns, and missing page coverage. | Static parser and direct contract scan passed; PHPUnit runtime unverified. |
| `PAGES-150-250-MATRICES.md` | Markdown matrix deliverable | Complete architecture matrices | Pages 150–250 reporting | Records the complete page, route, API, security, financial-integrity, Rust, and changed-file matrices. | `audit.md`; canonical repository routes/services | Records fail-closed states and exact runtime boundary. | Matrix row scan passed; runtime unverified. |

## Pages 150–165 phase validation evidence

| Check | Result |
|---|---|
| Architecture inventory | Generated from `app`, `bootstrap`, `config`, `database`, `resources`, `routes`, and `tests` at depth four; 1,734 paths recorded. |
| Static PHP parser | Passed for 5 Pages 150–165 PHP files. |
| EN/TH translation parity for `admin_release` | Passed. |
| Route contract scan for Pages 150–165 | Passed. |
| `git diff --check` | Passed. |
| Laravel route listing | NOT VERIFIED — RUNTIME UNAVAILABLE |
| Blade compilation | NOT VERIFIED — RUNTIME UNAVAILABLE |
| PHPUnit/Pest | NOT VERIFIED — RUNTIME UNAVAILABLE |
| Database, backup, restore, deployment, provider, browser, accessibility, and Rust runtime checks | NOT VERIFIED — RUNTIME UNAVAILABLE |

## Pages 166–180 continuation audit matrix

| Page | Title | Route | Route Name | Middleware | Authorization | Controller | Request | Service | Model | Database | API | View | JS | CSS | Translation | Data Source | Financial Impact | Security | Audit Log | Status | Tests | Runtime Status | Remaining Gap |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 166 | Operator Sessions | /admin/sessions | admin.sessions.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed session projection | none | canonical session store required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | session inventory requires canonical secure store |
| 167 | Operator Access Review | /admin/access-review | admin.access-review.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed operator access projection | none | canonical identity/permission store required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | identity, access-review, and last-login evidence not connected |
| 168 | Privileged Access | /admin/privileged-access | admin.privileged-access.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed privileged-access projection | none | canonical policy-backed records required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | no financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | wallet, payout, reconciliation, GLO, draw, and settings privileges require policy evidence |
| 169 | Permission Matrix | /admin/permission-matrix | admin.permission-matrix.index | bounded admin GET | audit permission | ReleaseOperationsController | none | configuration permission count plus fail-closed operation matrix | none | permission config if present | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | configured permission count only | read-only | admin.auth; access-admin; audit permission | no mutation | IMPLEMENTED + HARDENED — STATIC ONLY | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | effective role-to-permission evaluation requires runtime policy |
| 170 | Service Accounts | /admin/service-accounts | admin.service-accounts.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed service account projection | none | deployment secret/account registry required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | service account lifecycle and rotation source required |
| 171 | Network Access Controls | /admin/network-access | admin.network-access.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed network projection | none | deployment trusted-proxy/network evidence required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | allowlist, denylist, proxy, and admin network restrictions not connected |
| 172 | Device and Session Risk | /admin/device-risk | admin.device-risk.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed device-risk projection | none | security event store required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | device and revocation telemetry not connected |
| 173 | Multi-factor Authentication | /admin/mfa | admin.mfa.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed MFA projection | none | MFA enrollment/verification source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | MFA lifecycle evidence not connected |
| 174 | Authentication Security | /admin/authentication-security | admin.authentication-security.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed authentication telemetry projection | none | canonical security-event telemetry required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | login, reset, lock, CAPTCHA, and anomaly aggregation not connected |
| 175 | Rate Limits | /admin/rate-limits | admin.rate-limits.index | bounded admin GET | audit permission | ReleaseOperationsController | none | server configuration rate-limit projection | none | security/account/admin config if present | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | configured values only | read-only | admin.auth; access-admin; audit permission | no mutation | IMPLEMENTED + HARDENED — STATIC ONLY | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | middleware runtime behavior remains unverified |
| 176 | CAPTCHA Controls | /admin/captcha | admin.captcha.index | bounded admin GET | audit permission | ReleaseOperationsController | none | CAPTCHA provider/presence metadata projection | none | auth security config if present | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | presence only; secret material excluded | read-only | admin.auth; access-admin; audit permission | no mutation | IMPLEMENTED + HARDENED — STATIC ONLY | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | provider reachability and challenge results remain unverified |
| 177 | Fraud and Risk Rules | /admin/risk-rules | admin.risk-rules.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed risk-rule projection | none | canonical rule registry required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | no opaque fraud score fabricated |
| 178 | Suspicious Activity | /admin/suspicious-activity | admin.suspicious-activity.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed suspicious case projection | none | canonical case store required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | case evidence, assignment, and resolution store required |
| 179 | Compliance Cases | /admin/compliance-cases | admin.compliance-cases.index | bounded admin GET | audit permission | ReleaseOperationsController | reference optional | fail-closed compliance case projection | none | canonical compliance store required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission; bounded reference | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | protected case detail and evidence source required |
| 180 | Sanctions and Watchlists | /admin/sanctions | admin.sanctions.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed sanctions provider projection | none | configured provider evidence required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | provider configuration and health must be connected |

## Pages 166–180 phase validation evidence

| Check | Result |
|---|---|
| Static PHP parser | Passed for 5 changed PHP files covering Pages 150–180 routes, controller, translations, and static contracts. |
| EN/TH translation parity for `admin_release` | Passed after Pages 166–180 labels were added. |
| Route contract scan for Pages 166–180 | Passed. |
| Audit row scan for Pages 150–180 | Passed. |
| `git diff --check` | Passed. |
| Laravel route listing, middleware dispatch, policy evaluation, database, security telemetry, provider, browser, accessibility, and Rust runtime checks | NOT VERIFIED — RUNTIME UNAVAILABLE |

## Pages 181–194 continuation audit matrix

| Page | Title | Route | Route Name | Middleware | Authorization | Controller | Request | Service | Model | Database | API | View | JS | CSS | Translation | Data Source | Financial Impact | Security | Audit Log | Status | Tests | Runtime Status | Remaining Gap |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 181 | KYC and Identity Verification | /admin/kyc | admin.kyc.index | bounded admin GET | canonical KYC policy and reviewer authorization | LottoFinExecutiveDashboardController | document token for reviewer operations | AccountVerificationService and AccountVerificationDocumentService | KycDocument, KycVerification | canonical KYC tables; private document storage | no public document API | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical KYC projection; no fabricated document state | read-only GET projection; review mutations remain existing canonical POST services | admin.auth; access-admin; reviewer authorization; token constraint | review/download actions use existing audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | live KYC records, provider health, and browser dispatch remain unverified |
| 182 | KYC Provider Status | /admin/kyc-provider | admin.kyc-provider.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | provider evidence is not configured |
| 183 | KYC Review Queue | /admin/kyc-review | admin.kyc-review.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | policy-protected review store required |
| 184 | Age Verification | /admin/age-verification | admin.age-verification.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | age evidence is not configured |
| 185 | Duplicate Account Controls | /admin/duplicate-accounts | admin.duplicate-accounts.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | no match result is fabricated |
| 186 | Account Restrictions | /admin/account-restrictions | admin.account-restrictions.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | restriction ledger required |
| 187 | Retention Controls | /admin/retention | admin.retention.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | retention and deletion evidence not connected |
| 188 | Privacy and Consent | /admin/privacy | admin.privacy.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | privacy service not connected |
| 189 | Data Rights Requests | /admin/data-rights | admin.data-rights.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | protected privacy request store required |
| 190 | Legal Registries | /admin/legal-registries | admin.legal-registries.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | canonical compliance registry required |
| 191 | Compliance Reporting | /admin/compliance-reporting | admin.compliance-reporting.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | submission evidence is not available |
| 192 | AML Monitoring | /admin/aml-monitoring | admin.aml-monitoring.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | canonical alerts and case data required |
| 193 | Regulatory Exports | /admin/regulatory-exports | admin.regulatory-exports.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | canonical export registry required |
| 194 | Compliance Audit | /admin/compliance-audit | admin.compliance-audit.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | immutable compliance control source required |

## Pages 181–194 phase validation evidence

| Check | Result |
|---|---|
| Static PHP parser | Passed for 5 changed PHP files covering the Pages 181–194 extension. |
| EN/TH translation parity for `admin_release` | Passed after Pages 181–194 labels were added. |
| Route contract scan for Pages 181–194 | Passed. |
| Audit row scan for Pages 150–194 | Passed. |
| Laravel route listing, authorization evaluation, KYC, privacy, compliance, provider, browser, accessibility, and Rust runtime checks | NOT VERIFIED — RUNTIME UNAVAILABLE |

## Pages 195–250 continuation audit matrix

| Page | Title | Route | Route Name | Middleware | Authorization | Controller | Request | Service | Model | Database | API | View | JS | CSS | Translation | Data Source | Financial Impact | Security | Audit Log | Status | Tests | Runtime Status | Remaining Gap |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 195 | Acceptance and Terms | /admin/compliance | admin.compliance.index | bounded admin GET | risk/compliance permission | LottoFinExecutiveDashboardController | none | canonical compliance projection | ComplianceCase, ComplianceAction | canonical compliance records | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical data or NOT_CONFIGURED | read-only | admin.auth; access-admin; risk permission | canonical audit where service writes | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | live acceptance/version evidence remains runtime-dependent |
| 196 | Responsible Gaming | /admin/responsible-gaming | admin.responsible-gaming.index | bounded admin GET | manage users permission | LottoFinExecutiveDashboardController | none | canonical responsible-gaming projection | ResponsibleGamingLimit, ResponsibleGamingLimitVersion | canonical responsible-gaming tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical data or NOT_CONFIGURED | no browser financial mutation | admin.auth; access-admin; manage-users | canonical service audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | live limits and interventions not runtime verified |
| 197 | Self-exclusion | /admin/self-exclusion | admin.self-exclusion.index | bounded admin GET | manage users permission | LottoFinExecutiveDashboardController | none | SelfExclusionService-backed surface | SelfExclusion | canonical self-exclusion table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NOT_CONFIGURED | no purchase/payout mutation | admin.auth; access-admin; manage-users | canonical self-exclusion audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | active records require runtime query verification |
| 198 | Responsible Gaming Limits | /admin/responsible-gaming | admin.responsible-gaming.index | bounded admin GET | manage users permission | LottoFinExecutiveDashboardController | none | canonical limit projection | ResponsibleGamingLimit, ResponsibleGamingLimitVersion | canonical limit tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NOT_CONFIGURED | no financial mutation | admin.auth; access-admin; manage-users | canonical service audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | limit enforcement requires runtime and policy checks |
| 199 | Responsible Gaming Interventions | /admin/risk | admin.risk.index | bounded admin GET | risk permission | LottoFinExecutiveDashboardController | none | canonical risk projection | AmlRiskAssessment, ComplianceCase | canonical risk/compliance tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NOT_CONFIGURED | read-only | admin.auth; access-admin; risk permission | canonical compliance audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | intervention evidence and active restrictions unverified |
| 200 | Wallet Integrity | /admin/wallets | admin.wallets.index | bounded admin GET | manage wallet permission | LottoFinExecutiveDashboardController | none | WalletService and wallet projection | Wallet, WalletLedger | canonical wallet tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NO_DATA | no browser debit/credit | admin.auth; access-admin; manage-wallet | ledger writes remain canonical-service only | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | balances and invariants require database runtime |
| 201 | Ledger Integrity | /admin/ledger | admin.ledger.index | bounded admin GET | financial-report permission | LottoFinExecutiveDashboardController | none | LedgerBalanceValidator, LedgerReconciliationService | LedgerAccount, LedgerEntry, LedgerReconciliation | canonical ledger tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical entries or NO_DATA | read-only; no adjustment route here | admin.auth; access-admin; financial-report | canonical ledger audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | reconciliation result requires runtime service invocation |
| 202 | Payment Methods | /admin/payment-methods | admin.payment-methods.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | none | canonical payment capability projection | PaymentMethodConfig, PaymentProvider | canonical payment configuration | provider health API is separate | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | configured capabilities only | no checkout mutation | admin.auth; access-admin; manage-payouts | canonical config audit where available | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | provider capabilities require runtime configuration |
| 203 | Payment Intent State | /admin/payments/{payment} | admin.payments.show | bounded numeric payment reference | manage payouts permission | LottoFinExecutiveDashboardController | numeric payment reference | canonical payment projection | Payment, PaymentIntent | canonical payment tables | existing payment APIs remain authoritative | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical state or NOT_FOUND | no mutation | admin.auth; access-admin; manage-payouts; object authorization | canonical payment audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | object-level runtime authorization remains unverified |
| 204 | Payment Webhooks | /admin/payment-events | admin.payment-events.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | none | VerifyWebhookSignature and callback architecture | PaymentWebhook | canonical webhook table | signed webhook endpoints remain separate | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical webhook state or NO_DATA | no webhook replay mutation | admin.auth; access-admin; manage-payouts | webhook audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | provider callback and signature verification runtime unverified |
| 205 | Retry and Replay Protection | /admin/payment-exceptions | admin.payment-exceptions.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | none | IdempotencyService and exception projection | PaymentWebhook, PaymentIntent | canonical idempotency/provider records | signed API boundary remains authoritative | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical exceptions or NO_DATA | no replay mutation | admin.auth; access-admin; manage-payouts | canonical exception audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | replay evidence requires runtime records |
| 206 | Deposits | /admin/payments | admin.payments.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | none | DepositService, DepositApprovalService, DepositCompletionService | Deposit, Payment | canonical deposit/payment tables | existing deposit API remains authoritative | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical state or NO_DATA | no fabricated deposit success | admin.auth; access-admin; manage-payouts | canonical financial audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | provider and ledger settlement runtime unverified |
| 207 | Disputes | /admin/payment-exceptions | admin.payment-exceptions.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | none | financial exception projection | Payment, PaymentReconciliation | canonical payment records | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical exceptions or NO_DATA | read-only | admin.auth; access-admin; manage-payouts | canonical payment audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | dispute provider data is not connected |
| 208 | Chargebacks | /admin/payment-exceptions | admin.payment-exceptions.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | none | FinancialReversalService and reconciliation architecture | Payment, PaymentReconciliation | canonical payment records | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical exceptions or NO_DATA | no reversal mutation here | admin.auth; access-admin; manage-payouts | canonical reversal audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | chargeback provider and case records not connected |
| 209 | Withdrawals | /admin/withdrawals | admin.withdrawals.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | none | WithdrawalService, WithdrawalCompletionService | Withdrawal | canonical withdrawal table | existing withdrawal API remains authoritative | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical state or NO_DATA | no withdrawal success claim | admin.auth; access-admin; manage-payouts | canonical payout audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | provider and KYC gate runtime unverified |
| 210 | Withdrawal Approval | /admin/withdrawals | admin.withdrawals.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | POST mutations are explicit unsupportedMutation | WithdrawalApprovalService | Withdrawal | canonical withdrawal table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical state or NO_DATA | browser approval does not silently mutate | admin.auth; access-admin; manage-payouts | canonical approval audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | controlled approval workflow remains external to projection |
| 211 | Treasury and Payouts | /admin/settlements | admin.settlements.index | bounded admin GET | process settlements permission | LottoFinExecutiveDashboardController | none | PayoutBatchService, PayoutReconciliationService | PrizeDisbursement, Withdrawal | canonical payout/settlement tables | provider balance source not configured | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical settlement records or NO_DATA | read-only projection | admin.auth; access-admin; process-settlements | canonical settlement audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | treasury bank/provider evidence not connected |
| 212 | Financial Reconciliation | /admin/reconciliation | admin.reconciliation.index | bounded admin GET/POST existing service route | reconcile ledger permission | LottoFinExecutiveDashboardController | bounded period request for existing service | FinancialReconciliationService | FinancialTransaction, PaymentReconciliation, LedgerReconciliation | canonical finance tables | reconciliation API remains authoritative | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical report or NOT_CONFIGURED feed | reconciliation POST uses canonical service; not fabricated | admin.auth; access-admin; reconcile-ledger | service audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | POST service execution was not runtime tested |
| 213 | Financial Reporting | /admin/ledger | admin.ledger.index | bounded admin GET | financial-report permission | LottoFinExecutiveDashboardController | none | FinancialReconciliationExportService | FinancialTransaction, LedgerEntry | canonical ledger tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical entries or NO_DATA | read-only | admin.auth; access-admin; financial-report | canonical financial audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | report generation and export runtime unverified |
| 214 | Tax | /admin/fees | admin.fees.index | bounded admin GET | system-settings permission | LottoFinExecutiveDashboardController | none | TaxCalculationService via canonical finance architecture | Fee/configuration models where present | canonical configuration/finance tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | configured values or NOT_CONFIGURED | no tax mutation | admin.auth; access-admin; system-settings | canonical config audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | jurisdiction and tax reporting evidence not connected |
| 215 | Commissions | /admin/commissions | admin.commissions.index | bounded admin GET | view commissions permission | LottoFinExecutiveDashboardController | none | AgentCommissionService, CommissionCalculationService | AgentCommission | canonical commission table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical commissions or NO_DATA | read-only | admin.auth; access-admin; view-commissions | canonical commission audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | commission records require runtime query |
| 216 | Commission Reconciliation | /admin/reconciliation | admin.reconciliation.index | bounded admin GET | reconcile ledger permission | LottoFinExecutiveDashboardController | bounded period request | AgentCommissionSettlementService and reconciliation architecture | AgentCommission, LedgerReconciliation | canonical records | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical report or NO_DATA | no settlement mutation from GET | admin.auth; access-admin; reconcile-ledger | canonical reconciliation audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | commission-to-ledger runtime evidence unverified |
| 217 | Agents | /agent | agent.dashboard | authenticated agent portal | agent authorization | AgentPortalController | authenticated session only | AgentOnboardingService, AgentReportingService | Agent | canonical agent tables | agent APIs are canonical | agent portal views | none | agent CSS | agent translations | owner-scoped canonical records | no admin financial mutation | auth; agent policy; ownership scope | canonical agent audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | agent runtime and role evaluation unverified |
| 218 | Agent Settlements | /agent/settlements | agent.settlements | authenticated agent portal | agent authorization | AgentPortalController | authenticated session only | AgentSettlementService | Agent, AgentCommission | canonical agent settlement tables | none | agent portal views | none | agent CSS | agent translations | owner-scoped canonical records | read-only projection | auth; agent policy; ownership scope | canonical settlement audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | settlement runtime unverified |
| 219 | Referrals | /agent/referrals | agent.referrals | authenticated agent portal | agent authorization | AgentPortalController | authenticated session and bounded reference | AgentReferralService | Agent | canonical referral records | none | agent portal views | none | agent CSS | agent translations | owner-scoped canonical records | no fabricated commission | auth; agent policy; ownership scope; reference constraint | canonical referral audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | referral runtime unverified |
| 220 | Bonuses | /admin/commissions | admin.commissions.index | bounded admin GET | view commissions permission | LottoFinExecutiveDashboardController | none | canonical commission/promotion architecture | AgentCommission and configured bonus models | canonical records or NO_DATA | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | NO_DATA where no canonical bonus source | no bonus grant mutation | admin.auth; access-admin; view-commissions | canonical audit path | NOT_CONFIGURED — FAIL CLOSED | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | bonus product source is not connected |
| 221 | Promotions and Fees | /admin/fees | admin.fees.index | bounded admin GET | system-settings permission | LottoFinExecutiveDashboardController | none | canonical fee/configuration projection | configuration models | canonical config or NOT_CONFIGURED | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | configured values only | no price mutation | admin.auth; access-admin; system-settings | canonical config audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | promotion catalogue evidence not connected |
| 222 | Payment Exceptions | /admin/payment-exceptions | admin.payment-exceptions.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | none | FinancialReversalService and exception projection | Payment, PaymentWebhook, PaymentReconciliation | canonical exception records | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NO_DATA | read-only | admin.auth; access-admin; manage-payouts | canonical exception audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | live provider exceptions unverified |
| 223 | Financial Holds | /admin/wallet-operations | admin.wallet-operations.index | bounded admin GET | manage wallet permission | LottoFinExecutiveDashboardController | none | FinancialHoldService, WalletHoldService | Wallet, WalletReservation | canonical wallet/hold tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NO_DATA | no browser hold mutation | admin.auth; access-admin; manage-wallet | canonical hold audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | active holds require runtime records |
| 224 | Refunds | /admin/payments | admin.payments.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | none | RefundService, FinancialReversalService | Payment, FinancialTransaction | canonical finance tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NO_DATA | no refund mutation from projection | admin.auth; access-admin; manage-payouts | canonical reversal audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | refund provider and ledger runtime unverified |
| 225 | Payouts | /admin/withdrawals | admin.withdrawals.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | none | PayoutApprovalService, PayoutBatchService | Withdrawal, PrizeDisbursement | canonical payout tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NO_DATA | no payout success claim | admin.auth; access-admin; manage-payouts | canonical payout audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | payout provider and approval runtime unverified |
| 226 | Account Finance Detail | /admin/users/{user}/finance | admin.users.finance | numeric user reference and object-scoped projection | manage users permission | LottoFinExecutiveDashboardController | numeric user reference | canonical owner-scoped finance projection | User, Wallet, FinancialTransaction | canonical user finance tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NO_DATA | read-only | admin.auth; access-admin; manage-users; object authorization | canonical audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | object scope and balances require runtime verification |
| 227 | Financial Audit | /admin/audits | admin.audits.index | bounded admin GET | audit permission | LottoFinExecutiveDashboardController | bounded audit filters | AuditLog projection | AuditLog | canonical audit table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical audit rows or NO_DATA | read-only | admin.auth; access-admin; audit permission | canonical AuditLog | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | live audit query unverified |
| 228 | Lottery Product Catalogue | /admin/lotteries | admin.lotteries.index | bounded admin GET | system-settings permission | LottoFinExecutiveDashboardController | none | canonical lottery catalogue projection | TicketProduct, LotteryProduct if present | canonical lottery tables/config | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical product data or NO_DATA | no product mutation | admin.auth; access-admin; system-settings | canonical catalogue audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | product runtime query unverified |
| 229 | Draw Lifecycle | /admin/draw-lifecycle | admin.draw-lifecycle.index | bounded admin GET | manage draws permission | LottoFinExecutiveDashboardController | none | DrawLifecycleService, DrawScheduleService | Draw, NationalLotteryDraw, WeeklyLotteryDraw | canonical draw tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical draw data or NO_DATA | no draw mutation from GET | admin.auth; access-admin; manage-draws | canonical draw audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | live draw state unverified |
| 230 | Sales Windows | /admin/draw-lifecycle | admin.draw-lifecycle.index | bounded admin GET | manage draws permission | LottoFinExecutiveDashboardController | none | DrawScheduleService, EnsureDrawIsOpen | Draw, TicketProduct | canonical draw/sales configuration | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical data or NO_DATA | no sales-opening mutation | admin.auth; access-admin; manage-draws | canonical draw audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | sales-window enforcement runtime unverified |
| 231 | Reservations | /admin/wallet-operations | admin.wallet-operations.index | bounded admin GET | manage wallet permission | LottoFinExecutiveDashboardController | none | WalletReservationService | WalletReservation, TicketAllocation | canonical reservation tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NO_DATA | no reservation mutation | admin.auth; access-admin; manage-wallet | canonical wallet audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | reservation expiry and locking runtime unverified |
| 232 | Ticket Issuance and Inventory | /admin/lotteries | admin.lotteries.index | bounded admin GET | system-settings permission | LottoFinExecutiveDashboardController | none | GloL6SalesService, GloN3SaleService | Ticket, TicketInventoryItem, TicketAllocation | canonical ticket tables | canonical purchase APIs remain authoritative | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical inventory or NO_DATA | no ticket issuance mutation | admin.auth; access-admin; system-settings | canonical issuance audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | inventory runtime unverified |
| 233 | Bet Validation | /admin/bets | admin.bets.index | bounded admin GET | transaction-history permission | LottoFinExecutiveDashboardController | none | BetPurchaseRiskService, ticket verification architecture | Bet, Ticket | canonical bet/ticket tables | bet APIs remain authoritative | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NO_DATA | read-only | admin.auth; access-admin; transaction-history | canonical bet audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | validation runtime unverified |
| 234 | Bet State | /admin/bets/{bet} | admin.bets.show | numeric bet reference | transaction-history permission | LottoFinExecutiveDashboardController | numeric bet reference | canonical bet projection | Bet | canonical bet table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical record or NOT_FOUND | read-only | admin.auth; access-admin; transaction-history; object authorization | canonical bet audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | object authorization runtime unverified |
| 235 | Bet Refunds | /admin/payment-exceptions | admin.payment-exceptions.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | none | RefundService and financial exception projection | Bet, Payment, FinancialTransaction | canonical finance/bet tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NO_DATA | no refund mutation | admin.auth; access-admin; manage-payouts | canonical refund audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | refund eligibility and state runtime unverified |
| 236 | Winning Calculations | /admin/draws | admin.draws.index | bounded admin GET | view draws permission | LottoFinExecutiveDashboardController | none | SelectionSettlementResolver, result calculators | DrawResult, PrizeMatch | canonical result/prize tables | official result APIs remain authoritative | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical result data or NO_DATA | no fabricated winners/prizes | admin.auth; access-admin; view-draws | canonical result audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | winning calculation runtime unverified |
| 237 | Prize Liability | /admin/settlements | admin.settlements.index | bounded admin GET | process settlements permission | LottoFinExecutiveDashboardController | none | RealPrizeSettlementService, PayoutReconciliationService | PrizeDisbursement, GloPrizeClaim, DrawReconciliation | canonical prize/settlement tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NO_DATA | no prize amount fabricated | admin.auth; access-admin; process-settlements | canonical settlement audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | liability calculation runtime unverified |
| 238 | Prize Payouts | /admin/glo/prize-claims | admin.glo.prize-claims.index | bounded admin GET | GLO claim permission | LottoFinExecutiveDashboardController | none | GloPrizeClaimService, RealPrizeSettlementService | GloPrizeClaim, PrizeDisbursement | canonical GLO prize tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical claims or NO_DATA | no payout success claim | admin.auth; access-admin; manage-GLO-claims | canonical claim audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | claim/payout runtime unverified |
| 239 | Prize Evidence | /admin/glo/prize-claims | admin.glo.prize-claims.index | bounded admin GET | GLO claim permission | LottoFinExecutiveDashboardController | bounded claim reference | GloPrizeClaimService | GloPrizeClaim, PrizeEligibilityDecision | canonical evidence tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical evidence or NO_DATA | private evidence not exposed by projection | admin.auth; access-admin; object authorization | canonical claim audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | claim evidence authorization runtime unverified |
| 240 | Ticket Freezes | /admin/glo/ticket-freezes | admin.glo.ticket-freezes.index | bounded admin GET | review GLO freezes permission | LottoFinExecutiveDashboardController | bounded freeze reference | GloFrozenWinnerService | GloTicketFreeze | canonical freeze table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical freeze records or NO_DATA | no freeze mutation from GET | admin.auth; access-admin; review-freezes | canonical freeze audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | freeze review runtime unverified |
| 241 | Result Imports | /admin/result-imports | admin.result-imports.index | bounded admin GET | view results permission | LottoFinExecutiveDashboardController | none | GloResultImportService, DrawResultIngestionService | GloResultImport, DrawResult | canonical result import tables | provider import APIs remain authoritative | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical imports or NO_DATA | no imported result fabricated | admin.auth; access-admin; view-results | canonical import audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | provider import and signature runtime unverified |
| 242 | Result Provenance | /admin/result-sources | admin.result-sources.index | bounded admin GET | view results permission | LottoFinExecutiveDashboardController | none | GloOfficialResultProvider, result provenance architecture | GloResultImport, DrawResult | canonical source/import tables | provider APIs remain authoritative | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical provenance or NO_DATA | no source claim fabricated | admin.auth; access-admin; view-results | canonical provenance audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | provider provenance runtime unverified |
| 243 | Result Publication | /admin/result-publication | admin.result-publication.index | bounded admin GET | view results permission | LottoFinExecutiveDashboardController | none | DrawResultPublicationService, GloResultPublicationService | DrawPublication, DrawResult | canonical publication tables | public result APIs remain authoritative | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical publication or NO_DATA | no publication mutation | admin.auth; access-admin; view-results | canonical publication audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | publication runtime unverified |
| 244 | Draw Reconciliation and Certification | /admin/reconciliation | admin.reconciliation.index | bounded admin GET | reconcile ledger permission | LottoFinExecutiveDashboardController | bounded period request | DrawReconciliationService, DrawCertificationService | DrawReconciliation, DrawCertification | canonical draw reconciliation tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical reconciliation or NO_DATA | read-only projection | admin.auth; access-admin; reconcile-ledger | canonical draw audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | certification runtime unverified |
| 245 | Rust Integrity Health | /health | health.canonical | public health endpoint | HealthController health contract | HealthController | none | HealthCheckService, SystemHealthService | none | health dependencies are canonical | health API is canonical | health response | none | global CSS | system translations if used | actual health checks or failure state | no financial mutation | health endpoint security contract | health logs where configured | IMPLEMENTED + HARDENED — STATIC ONLY | existing health tests; runtime unverified | NOT VERIFIED — RUNTIME UNAVAILABLE | Rust subprocess health is not runtime proven |
| 246 | Rust Contract Boundary | /api/v1/health | api.v1.health | API health contract | API auth/health boundary | HealthController | none | health and Rust boundary architecture | none | none | canonical API endpoint | JSON response | none | none | API translations not browser-visible | actual API state or failure | no financial mutation | API boundary and no secret exposure | service logs where configured | IMPLEMENTED + HARDENED — STATIC ONLY | route scan; runtime unverified | NOT VERIFIED — RUNTIME UNAVAILABLE | Laravel-to-Rust invocation contract requires runtime test |
| 247 | Rust Deterministic Vectors | security/weekly-result-integrity/tests/integrity.rs | Cargo test target | isolated Rust test target | fixture-only deterministic verifier | Rust integrity crate | synthetic fixtures only | canonical Rust boundary artifact | none | Cargo lockfile and test fixtures | stdin/stdout contract is bounded | none | none | none | Rust source comments/tests | synthetic vectors; no production result claim | no financial mutation | no socket/network dependency documented | test evidence is local only | IMPLEMENTED + HARDENED — STATIC ONLY | Rust test command not executed | NOT VERIFIED — RUNTIME UNAVAILABLE | Cargo toolchain and vectors require runtime execution |
| 248 | FFI/API Security | security/weekly-result-integrity/src/main.rs | Rust stdin/stdout shim | short-lived subprocess boundary | no socket; bounded JSON boundary | Rust integrity crate | single JSON document stdin/stdout | canonical isolated verifier | none | Cargo artifact | API boundary is stdin/stdout, not public network | none | none | none | Rust source | no financial mutation | no socket, no token, no raw secret exposure by design | process audit evidence unavailable | IMPLEMENTED + HARDENED — STATIC ONLY | static source inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | process sandbox and malformed-input runtime tests remain |
| 249 | Rust Performance Evidence | /admin/runtime | admin.runtime.index | admin protected read-only projection | audit permission | ReleaseOperationsController | none | artifact/config evidence only | none | Cargo manifest/lockfile if present | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | artifact presence only; no benchmark claim | no financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_VERIFIED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | benchmark, memory, timeout, and throughput evidence not present |
| 250 | Final Enterprise Integrity Audit | /admin/audits | admin.audits.index | bounded admin GET | audit permission | LottoFinExecutiveDashboardController | bounded audit filters | canonical audit projection plus Pages 150–249 matrix | AuditLog and domain audit models | canonical audit tables | health and API contracts remain separate | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | actual audit rows or NO_DATA | read-only; no financial mutation | admin.auth; access-admin; audit permission | AuditLog canonical source | IMPLEMENTED + HARDENED — STATIC ONLY | parser, route, matrix scans; runtime unverified | NOT VERIFIED — RUNTIME UNAVAILABLE | final production/infrastructure/provider/Rust evidence remains external |

## Pages 195–250 phase validation evidence

| Check | Result |
|---|---|
| Static PHP parser | Passed for 5 changed PHP files. |
| EN/TH translation parity for `admin_release` | Passed. |
| Route and canonical-architecture scan | Passed; existing finance, lottery, health, and Rust boundaries remain referenced rather than duplicated. |
| Audit row scan for Pages 150–250 | Passed; one row is present for every page 150 through 250. |
| Matrix row scan for Pages 150–250 | Passed; one row is present for every page 150 through 250. |
| `git diff --check` | Passed. |
| Rust Cargo tests, Laravel route listing, Blade compilation, database, provider, browser, accessibility, performance, and infrastructure checks | NOT VERIFIED — RUNTIME UNAVAILABLE |

## Final runtime boundary

| Runtime check | Result |
|---|---|
| PHP CLI | `NOT VERIFIED — RUNTIME UNAVAILABLE` — the `php` executable is not installed in the workspace. |
| PHPUnit | `NOT VERIFIED — RUNTIME UNAVAILABLE` — `vendor/bin/phpunit` is not available. |
| Laravel route dispatch, container resolution, policy evaluation, Blade compilation, database, queues, providers, browser, and accessibility | `NOT VERIFIED — RUNTIME UNAVAILABLE`. |
| Rust Cargo test | `NOT VERIFIED — RUNTIME UNAVAILABLE` — the `cargo` executable is not installed in the workspace. |
| Production deployment, backup/restore, DR/HA, payment, KYC, compliance, lottery-provider, and infrastructure evidence | `NOT VERIFIED — RUNTIME UNAVAILABLE`. |

```

## FILE 9: `PAGES-150-250-MATRICES.md`

# TYPE: Markdown matrix deliverable
# PURPOSE: Complete Pages 150–250 page, route, API, security, financial-integrity, Rust, and changed-file matrices.

```text
# Pages 150–250 complete architecture matrices

# TYPE: Markdown matrices and coverage register
# PURPOSE: Complete page, route, API, security, financial-integrity, and Rust boundary matrices for Pages 150–250. The repository audit and changed-file manifest remain authoritative in `audit.md`.

Runtime status: `NOT VERIFIED — RUNTIME UNAVAILABLE`.

## Complete Pages 150–250 page matrix

| Page | Title | Route | Controller | Service / boundary | Data source | Status | Runtime status |
|---|---|---|---|---|---|---|---|
| 150 | Production Cutover Control Center | /admin/cutover | ReleaseOperationsController | release/readiness evidence projection | actual artifact evidence or NOT_VERIFIED | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 151 | Release Manifest | /admin/release-manifest | ReleaseOperationsController | filesystem/config evidence projection | real artifact hashes or NOT_CONFIGURED | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 152 | Environment / Configuration Matrix | /admin/configuration | ReleaseOperationsController | configuration repository presence projection | configured/missing metadata only | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 153 | Secrets and Key Management Status | /admin/secrets | ReleaseOperationsController | secret-presence metadata only | presence only; no secret material | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 154 | Database Migration Control | /admin/migrations | ReleaseOperationsController | migration filesystem evidence plus NOT_VERIFIED runtime fields | filesystem count only; no migration execution | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 155 | Database Backup Control | /admin/backups | ReleaseOperationsController | backup filesystem evidence plus NOT_VERIFIED fields | actual file evidence only | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 156 | Backup Restore Verification | /admin/restore-verification | ReleaseOperationsController | fail-closed restore projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 157 | Disaster Recovery Center | /admin/disaster-recovery | ReleaseOperationsController | fail-closed DR evidence projection | NOT_VERIFIED fields | NOT_VERIFIED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 158 | Failover / High Availability Status | /admin/high-availability | ReleaseOperationsController | fail-closed infrastructure projection | NOT_VERIFIED fields | NOT_VERIFIED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 159 | Incident Command Center | /admin/incidents | ReleaseOperationsController | fail-closed incident projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 160 | Incident Detail | /admin/incidents/{reference} | ReleaseOperationsController | fail-closed incident projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 161 | Deployment Approval Gate | /admin/deployment-approval | ReleaseOperationsController | fail-closed approval projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 162 | Deployment History | /admin/deployments | ReleaseOperationsController | fail-closed deployment history projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 163 | Rollback Control | /admin/rollback | ReleaseOperationsController | fail-closed rollback request projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 164 | Feature Flag Operations | /admin/feature-flags | ReleaseOperationsController | server-side config flags only | actual configured flags or NOT_CONFIGURED | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 165 | Configuration Change Audit | /admin/configuration-audit | ReleaseOperationsController | fail-closed immutable audit projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 166 | Operator Sessions | /admin/sessions | ReleaseOperationsController | fail-closed session projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 167 | Operator Access Review | /admin/access-review | ReleaseOperationsController | fail-closed operator access projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 168 | Privileged Access | /admin/privileged-access | ReleaseOperationsController | fail-closed privileged-access projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 169 | Permission Matrix | /admin/permission-matrix | ReleaseOperationsController | configuration permission count plus fail-closed operation matrix | configured permission count only | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 170 | Service Accounts | /admin/service-accounts | ReleaseOperationsController | fail-closed service account projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 171 | Network Access Controls | /admin/network-access | ReleaseOperationsController | fail-closed network projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 172 | Device and Session Risk | /admin/device-risk | ReleaseOperationsController | fail-closed device-risk projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 173 | Multi-factor Authentication | /admin/mfa | ReleaseOperationsController | fail-closed MFA projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 174 | Authentication Security | /admin/authentication-security | ReleaseOperationsController | fail-closed authentication telemetry projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 175 | Rate Limits | /admin/rate-limits | ReleaseOperationsController | server configuration rate-limit projection | configured values only | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 176 | CAPTCHA Controls | /admin/captcha | ReleaseOperationsController | CAPTCHA provider/presence metadata projection | presence only; secret material excluded | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 177 | Fraud and Risk Rules | /admin/risk-rules | ReleaseOperationsController | fail-closed risk-rule projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 178 | Suspicious Activity | /admin/suspicious-activity | ReleaseOperationsController | fail-closed suspicious case projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 179 | Compliance Cases | /admin/compliance-cases | ReleaseOperationsController | fail-closed compliance case projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 180 | Sanctions and Watchlists | /admin/sanctions | ReleaseOperationsController | fail-closed sanctions provider projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 181 | KYC and Identity Verification | /admin/kyc | LottoFinExecutiveDashboardController | AccountVerificationService and AccountVerificationDocumentService | canonical KYC projection; no fabricated document state | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 182 | KYC Provider Status | /admin/kyc-provider | ReleaseOperationsController | fail-closed compliance projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 183 | KYC Review Queue | /admin/kyc-review | ReleaseOperationsController | fail-closed compliance projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 184 | Age Verification | /admin/age-verification | ReleaseOperationsController | fail-closed compliance projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 185 | Duplicate Account Controls | /admin/duplicate-accounts | ReleaseOperationsController | fail-closed compliance projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 186 | Account Restrictions | /admin/account-restrictions | ReleaseOperationsController | fail-closed compliance projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 187 | Retention Controls | /admin/retention | ReleaseOperationsController | fail-closed compliance projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 188 | Privacy and Consent | /admin/privacy | ReleaseOperationsController | fail-closed compliance projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 189 | Data Rights Requests | /admin/data-rights | ReleaseOperationsController | fail-closed compliance projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 190 | Legal Registries | /admin/legal-registries | ReleaseOperationsController | fail-closed compliance projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 191 | Compliance Reporting | /admin/compliance-reporting | ReleaseOperationsController | fail-closed compliance projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 192 | AML Monitoring | /admin/aml-monitoring | ReleaseOperationsController | fail-closed compliance projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 193 | Regulatory Exports | /admin/regulatory-exports | ReleaseOperationsController | fail-closed compliance projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 194 | Compliance Audit | /admin/compliance-audit | ReleaseOperationsController | fail-closed compliance projection | NOT_CONFIGURED | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 195 | Acceptance and Terms | /admin/compliance | LottoFinExecutiveDashboardController | canonical compliance projection | canonical data or NOT_CONFIGURED | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 196 | Responsible Gaming | /admin/responsible-gaming | LottoFinExecutiveDashboardController | canonical responsible-gaming projection | canonical data or NOT_CONFIGURED | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 197 | Self-exclusion | /admin/self-exclusion | LottoFinExecutiveDashboardController | SelfExclusionService-backed surface | canonical records or NOT_CONFIGURED | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 198 | Responsible Gaming Limits | /admin/responsible-gaming | LottoFinExecutiveDashboardController | canonical limit projection | canonical records or NOT_CONFIGURED | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 199 | Responsible Gaming Interventions | /admin/risk | LottoFinExecutiveDashboardController | canonical risk projection | canonical records or NOT_CONFIGURED | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 200 | Wallet Integrity | /admin/wallets | LottoFinExecutiveDashboardController | WalletService and wallet projection | canonical records or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 201 | Ledger Integrity | /admin/ledger | LottoFinExecutiveDashboardController | LedgerBalanceValidator, LedgerReconciliationService | canonical entries or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 202 | Payment Methods | /admin/payment-methods | LottoFinExecutiveDashboardController | canonical payment capability projection | configured capabilities only | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 203 | Payment Intent State | /admin/payments/{payment} | LottoFinExecutiveDashboardController | canonical payment projection | canonical state or NOT_FOUND | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 204 | Payment Webhooks | /admin/payment-events | LottoFinExecutiveDashboardController | VerifyWebhookSignature and callback architecture | canonical webhook state or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 205 | Retry and Replay Protection | /admin/payment-exceptions | LottoFinExecutiveDashboardController | IdempotencyService and exception projection | canonical exceptions or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 206 | Deposits | /admin/payments | LottoFinExecutiveDashboardController | DepositService, DepositApprovalService, DepositCompletionService | canonical state or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 207 | Disputes | /admin/payment-exceptions | LottoFinExecutiveDashboardController | financial exception projection | canonical exceptions or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 208 | Chargebacks | /admin/payment-exceptions | LottoFinExecutiveDashboardController | FinancialReversalService and reconciliation architecture | canonical exceptions or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 209 | Withdrawals | /admin/withdrawals | LottoFinExecutiveDashboardController | WithdrawalService, WithdrawalCompletionService | canonical state or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 210 | Withdrawal Approval | /admin/withdrawals | LottoFinExecutiveDashboardController | WithdrawalApprovalService | canonical state or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 211 | Treasury and Payouts | /admin/settlements | LottoFinExecutiveDashboardController | PayoutBatchService, PayoutReconciliationService | canonical settlement records or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 212 | Financial Reconciliation | /admin/reconciliation | LottoFinExecutiveDashboardController | FinancialReconciliationService | canonical report or NOT_CONFIGURED feed | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 213 | Financial Reporting | /admin/ledger | LottoFinExecutiveDashboardController | FinancialReconciliationExportService | canonical entries or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 214 | Tax | /admin/fees | LottoFinExecutiveDashboardController | TaxCalculationService via canonical finance architecture | configured values or NOT_CONFIGURED | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 215 | Commissions | /admin/commissions | LottoFinExecutiveDashboardController | AgentCommissionService, CommissionCalculationService | canonical commissions or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 216 | Commission Reconciliation | /admin/reconciliation | LottoFinExecutiveDashboardController | AgentCommissionSettlementService and reconciliation architecture | canonical report or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 217 | Agents | /agent | AgentPortalController | AgentOnboardingService, AgentReportingService | owner-scoped canonical records | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 218 | Agent Settlements | /agent/settlements | AgentPortalController | AgentSettlementService | owner-scoped canonical records | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 219 | Referrals | /agent/referrals | AgentPortalController | AgentReferralService | owner-scoped canonical records | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 220 | Bonuses | /admin/commissions | LottoFinExecutiveDashboardController | canonical commission/promotion architecture | NO_DATA where no canonical bonus source | NOT_CONFIGURED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 221 | Promotions and Fees | /admin/fees | LottoFinExecutiveDashboardController | canonical fee/configuration projection | configured values only | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 222 | Payment Exceptions | /admin/payment-exceptions | LottoFinExecutiveDashboardController | FinancialReversalService and exception projection | canonical records or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 223 | Financial Holds | /admin/wallet-operations | LottoFinExecutiveDashboardController | FinancialHoldService, WalletHoldService | canonical records or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 224 | Refunds | /admin/payments | LottoFinExecutiveDashboardController | RefundService, FinancialReversalService | canonical records or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 225 | Payouts | /admin/withdrawals | LottoFinExecutiveDashboardController | PayoutApprovalService, PayoutBatchService | canonical records or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 226 | Account Finance Detail | /admin/users/{user}/finance | LottoFinExecutiveDashboardController | canonical owner-scoped finance projection | canonical records or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 227 | Financial Audit | /admin/audits | LottoFinExecutiveDashboardController | AuditLog projection | canonical audit rows or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 228 | Lottery Product Catalogue | /admin/lotteries | LottoFinExecutiveDashboardController | canonical lottery catalogue projection | canonical product data or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 229 | Draw Lifecycle | /admin/draw-lifecycle | LottoFinExecutiveDashboardController | DrawLifecycleService, DrawScheduleService | canonical draw data or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 230 | Sales Windows | /admin/draw-lifecycle | LottoFinExecutiveDashboardController | DrawScheduleService, EnsureDrawIsOpen | canonical data or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 231 | Reservations | /admin/wallet-operations | LottoFinExecutiveDashboardController | WalletReservationService | canonical records or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 232 | Ticket Issuance and Inventory | /admin/lotteries | LottoFinExecutiveDashboardController | GloL6SalesService, GloN3SaleService | canonical inventory or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 233 | Bet Validation | /admin/bets | LottoFinExecutiveDashboardController | BetPurchaseRiskService, ticket verification architecture | canonical records or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 234 | Bet State | /admin/bets/{bet} | LottoFinExecutiveDashboardController | canonical bet projection | canonical record or NOT_FOUND | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 235 | Bet Refunds | /admin/payment-exceptions | LottoFinExecutiveDashboardController | RefundService and financial exception projection | canonical records or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 236 | Winning Calculations | /admin/draws | LottoFinExecutiveDashboardController | SelectionSettlementResolver, result calculators | canonical result data or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 237 | Prize Liability | /admin/settlements | LottoFinExecutiveDashboardController | RealPrizeSettlementService, PayoutReconciliationService | canonical records or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 238 | Prize Payouts | /admin/glo/prize-claims | LottoFinExecutiveDashboardController | GloPrizeClaimService, RealPrizeSettlementService | canonical claims or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 239 | Prize Evidence | /admin/glo/prize-claims | LottoFinExecutiveDashboardController | GloPrizeClaimService | canonical evidence or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 240 | Ticket Freezes | /admin/glo/ticket-freezes | LottoFinExecutiveDashboardController | GloFrozenWinnerService | canonical freeze records or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 241 | Result Imports | /admin/result-imports | LottoFinExecutiveDashboardController | GloResultImportService, DrawResultIngestionService | canonical imports or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 242 | Result Provenance | /admin/result-sources | LottoFinExecutiveDashboardController | GloOfficialResultProvider, result provenance architecture | canonical provenance or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 243 | Result Publication | /admin/result-publication | LottoFinExecutiveDashboardController | DrawResultPublicationService, GloResultPublicationService | canonical publication or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 244 | Draw Reconciliation and Certification | /admin/reconciliation | LottoFinExecutiveDashboardController | DrawReconciliationService, DrawCertificationService | canonical reconciliation or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 245 | Rust Integrity Health | /health | HealthController | HealthCheckService, SystemHealthService | actual health checks or failure state | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 246 | Rust Contract Boundary | /api/v1/health | HealthController | health and Rust boundary architecture | actual API state or failure | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 247 | Rust Deterministic Vectors | security/weekly-result-integrity/tests/integrity.rs | Rust integrity crate | canonical Rust boundary artifact | synthetic vectors; no production result claim | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 248 | FFI/API Security | security/weekly-result-integrity/src/main.rs | Rust integrity crate | canonical isolated verifier | no financial mutation | static source inspection | process sandbox and malformed-input runtime tests remain |
| 249 | Rust Performance Evidence | /admin/runtime | ReleaseOperationsController | artifact/config evidence only | artifact presence only; no benchmark claim | NOT_VERIFIED — FAIL CLOSED | NOT VERIFIED — RUNTIME UNAVAILABLE |
| 250 | Final Enterprise Integrity Audit | /admin/audits | LottoFinExecutiveDashboardController | canonical audit projection plus Pages 150–249 matrix | actual audit rows or NO_DATA | IMPLEMENTED + HARDENED — STATIC ONLY | NOT VERIFIED — RUNTIME UNAVAILABLE |

## Route matrix

| Pages | Route(s) | Route ownership | Middleware / boundary |
|---|---|---|---|
| 150 | `/admin/cutover` | ReleaseOperationsController; preserved named admin route | admin.auth; can:access-admin; controller permission |
| 151–165 | `/admin/release-manifest`, `/admin/configuration`, `/admin/secrets`, `/admin/migrations`, `/admin/backups`, `/admin/restore-verification`, `/admin/disaster-recovery`, `/admin/high-availability`, `/admin/incidents`, `/admin/incidents/{reference}`, `/admin/deployment-approval`, `/admin/deployments`, `/admin/rollback`, `/admin/feature-flags`, `/admin/configuration-audit` | ReleaseOperationsController | admin.auth; can:access-admin; bounded reference constraints |
| 166–180 | `/admin/sessions`, `/admin/access-review`, `/admin/privileged-access`, `/admin/permission-matrix`, `/admin/service-accounts`, `/admin/network-access`, `/admin/device-risk`, `/admin/mfa`, `/admin/authentication-security`, `/admin/rate-limits`, `/admin/captcha`, `/admin/risk-rules`, `/admin/suspicious-activity`, `/admin/compliance-cases`, `/admin/compliance-cases/{reference}`, `/admin/sanctions` | ReleaseOperationsController | admin.auth; can:access-admin; audit permission; bounded reference constraints |
| 181–194 | `/admin/kyc`, `/admin/kyc-provider`, `/admin/kyc-review`, `/admin/age-verification`, `/admin/duplicate-accounts`, `/admin/account-restrictions`, `/admin/retention`, `/admin/privacy`, `/admin/data-rights`, `/admin/legal-registries`, `/admin/compliance-reporting`, `/admin/aml-monitoring`, `/admin/regulatory-exports`, `/admin/compliance-audit` | LottoFinExecutiveDashboardController for canonical `/admin/kyc`; ReleaseOperationsController for the additional fail-closed operations projections | admin.auth; can:access-admin; canonical policy or audit permission |
| 195–227 | Existing canonical admin and agent routes: `/admin/compliance`, `/admin/responsible-gaming`, `/admin/self-exclusion`, `/admin/risk`, `/admin/wallets`, `/admin/ledger`, `/admin/payment-methods`, `/admin/payments`, `/admin/payments/{payment}`, `/admin/payment-events`, `/admin/payment-exceptions`, `/admin/withdrawals`, `/admin/settlements`, `/admin/reconciliation`, `/admin/fees`, `/admin/commissions`, `/admin/wallet-operations`, `/admin/users/{user}/finance`, `/admin/audits`, `/agent`, `/agent/settlements`, `/agent/referrals` | LottoFinExecutiveDashboardController and AgentPortalController | auth/admin.auth; canonical controller and object-scope checks |
| 228–244 | Existing canonical lottery routes: `/admin/lotteries`, `/admin/draw-lifecycle`, `/admin/bets`, `/admin/bets/{bet}`, `/admin/wallet-operations`, `/admin/result-imports`, `/admin/result-sources`, `/admin/result-publication`, `/admin/draws`, `/admin/settlements`, `/admin/glo/prize-claims`, `/admin/glo/ticket-freezes`, `/admin/reconciliation` | LottoFinExecutiveDashboardController | admin.auth; draw, result, GLO, transaction, settlement, and reconciliation permissions |
| 245–250 | `/health`, `/api/v1/health`, `/admin/runtime`, `/admin/audits`, `security/weekly-result-integrity` Cargo targets | HealthController; ReleaseOperationsController; LottoFinExecutiveDashboardController; isolated Rust crate | health/API boundary; admin audit boundary; no public Rust socket |

## API boundary matrix

| Pages | API / boundary | Canonical owner | Integrity statement |
|---|---|---|---|
| 150–194 | Admin GET projections and bounded reference routes | ReleaseOperationsController | No browser mutation; missing evidence remains NOT_CONFIGURED or NOT_VERIFIED. |
| 195–227 | Existing payment, wallet, ledger, reconciliation, agent, and authenticated portal APIs | canonical finance services, LottoFinExecutiveDashboardController, AgentPortalController | No new deposit, withdrawal, payout, wallet debit, ledger adjustment, commission grant, or bonus architecture is introduced. |
| 228–244 | Existing lottery draw, ticket, result, GLO, prize, freeze, and reconciliation APIs | canonical draw/lottery/GLO/result services | No result, draw date, prize, ticket, or winning value is fabricated. |
| 245–246 | `/health`, `/ready`, `/live`, `/api/health`, `/api/v1/health` | HealthController and canonical health services | Health output is evidence of the executed health boundary only; runtime is unverified here. |
| 247–248 | Rust stdin/stdout integrity verifier and Cargo test boundary | `security/weekly-result-integrity` crate | The crate is isolated and documented as non-networked; no public socket or secret-bearing API is added. |
| 249–250 | Admin runtime evidence and audit projection | ReleaseOperationsController and LottoFinExecutiveDashboardController | Artifact presence and audit rows are not performance, production, or Rust success claims. |

## Security matrix

| Pages | Authentication / authorization | Input boundary | Disclosure boundary | Mutation boundary |
|---|---|---|---|---|
| 150–165 | admin.auth, access-admin, system settings or audit permission | route surface defaults; incident reference `[A-Za-z0-9_-]{1,120}` | escaped evidence rows; secrets are presence-only | read-only; no shell, migration, restore, cache, key, deploy, or rollback action |
| 166–180 | admin.auth, access-admin, audit permission | bounded references for incident/compliance cases | no session tokens, MFA secrets, provider secrets, KYC metadata, or internal IDs | read-only; fail-closed case/security projections |
| 181–194 | canonical KYC reviewer authorization for `/admin/kyc`; audit permission for additional projections | existing KYC token constraints and bounded references | private KYC document stream remains existing authorized service path | no new KYC, restriction, privacy, sanctions, or compliance mutation |
| 195–227 | canonical panel permissions; authenticated agent ownership | numeric/object references and authenticated session | dashboard and agent projections use canonical scoped fields | existing mutation lanes only; unsupported admin withdrawal mutations return NOT_CONFIGURED |
| 228–244 | draw, result, GLO, transaction, settlement, and reconciliation permissions | bounded draw/bet/ticket/claim/freeze references | no provider secrets or private claim evidence in projections | no draw, result, issuance, freeze, payout, or refund mutation from added projections |
| 245–250 | health/API boundary and admin audit boundary | JSON/stdin contract remains bounded | no tokens or raw secret material | no financial/security mutation |

## Financial-integrity matrix

| Pages | Canonical financial boundary | Invariant / evidence rule | Forbidden claim |
|---|---|---|---|
| 195–199 | responsible gaming, self-exclusion, risk, compliance | only canonical account/restriction/risk records or explicit NO_DATA | no active restriction, intervention, KYC, or age state without records |
| 200–206 | WalletService, wallet ledger, LedgerBalanceValidator, payment/deposit services, IdempotencyService | ledger/payment state is canonical; provider settlement is not inferred | no balance, deposit success, payment success, or replay completion is fabricated |
| 207–214 | payment exceptions, reversals, withdrawals, payout batches, reconciliation, tax/configuration | exception and reconciliation evidence must be canonical | no chargeback, withdrawal, treasury, tax, or refund success claim without provider/ledger evidence |
| 215–227 | commission, agent, referral, bonus, holds, refunds, user finance, AuditLog | owner-scoped agent data and canonical financial audit paths | no commission, bonus, hold, payout, or balance mutation from a projection |
| 228–244 | ticket issuance, bet validation, draw results, prize calculation, claims, payouts, freezes, imports, publication | canonical draw/ticket/result/GLO services remain the only authorities | no lottery result, draw date, jackpot, prize amount, ticket, freeze, or payout is invented |
| 250 | final audit | every financial claim is traceable to a canonical source or explicitly unverified | no production integrity conclusion while runtime is unavailable |

## Rust matrix

| Pages | Artifact | Boundary | Evidence available in repository | Runtime boundary |
|---|---|---|---|---|
| 245 | Health | HealthController and health services | health routes, HealthCheckService, SystemHealthService | `NOT VERIFIED — RUNTIME UNAVAILABLE` |
| 246 | Contract boundary | API health routes and Laravel/Rust integration boundary | API health route and Rust crate source | `NOT VERIFIED — RUNTIME UNAVAILABLE` |
| 247 | Deterministic vectors | `security/weekly-result-integrity/tests/integrity.rs` | local synthetic fixture tests and canonicalization source | Cargo execution not performed |
| 248 | FFI/API security | `security/weekly-result-integrity/src/main.rs` | source documents stdin/stdout shim, no socket, short-lived process | malformed-input and process sandbox execution not verified |
| 249 | Performance evidence | `/admin/runtime` artifact projection | Cargo metadata/artifact presence only where available | no benchmark, memory, timeout, or throughput claim |
| 250 | Final integrity audit | audit.md plus canonical services/controllers | complete Page 150–250 static matrix and changed-file manifest | production and runtime conclusion unavailable |

## Changed-file manifest

The complete changed-file manifest, with `# TYPE`, `# PURPOSE`, dependencies, security impact, and test coverage, is maintained in `audit.md` under `Pages 150–165 phase changed-file manifest`. The changed files are:

| Path | Type | Purpose |
|---|---|---|
| `app/Http/Controllers/Admin/ReleaseOperationsController.php` | PHP controller | Evidence-based read-only operational and security/compliance projection. |
| `resources/views/admin/release-operations.blade.php` | Blade view | Localized evidence table. |
| `lang/en/admin_release.php` | PHP translation map | English labels. |
| `lang/th/admin_release.php` | PHP translation map | Exact Thai key parity. |
| `routes/web.php` | PHP routes | Named admin routes and compatibility-preserving aliases. |
| `tests/Feature/Pages150To250StaticContractTest.php` | PHP static contract test | Route, security, translation, canonical-boundary, and audit checks. |
| `audit.md` | Markdown audit | One factual row for each Page 150–250 plus validation evidence. |
| `PAGES-150-250-ARCHITECTURE-INVENTORY.md` | Markdown inventory | Complete 1,734-path pre-work inventory. |
| `PAGES-150-250-MATRICES.md` | Markdown matrices | This complete route/API/security/financial/Rust matrix deliverable. |

```
