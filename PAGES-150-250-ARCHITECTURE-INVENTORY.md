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
