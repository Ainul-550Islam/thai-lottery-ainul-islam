# TYPE: Python audit matrix generator
# PURPOSE: Generate one factual audit row for every Page 251–350 with the required runtime, financial, lottery, security, and Rust boundary columns.

from __future__ import annotations

from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
AUDIT = ROOT / "audit.md"
EXISTING_AUDIT = AUDIT.read_text(encoding="utf-8")

COLUMNS = [
    "Page", "Title", "Route", "Route Name", "HTTP Method", "Middleware", "Authorization",
    "Controller", "Request", "Service", "DTO", "Model", "Database", "API", "Job/Event",
    "View", "JS", "CSS", "Translation", "Source of Truth", "Financial Impact", "Security",
    "Audit", "Status", "Tests", "Runtime Status", "Remaining Gap",
]


def runtime_row(
    page: int,
    title: str,
    route: str,
    route_name: str,
    method: str,
    source: str,
    service: str,
    tests: str,
    status: str = "IMPLEMENTED + STATIC ONLY",
    runtime: str = "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
    gap: str = "Canonical runtime execution remains unavailable.",
    controller: str = "Existing canonical controller/service boundary",
    financial: str = "No financial success or mutation is claimed without execution.",
    security: str = "Existing authentication, authorization, ownership, and input boundaries remain authoritative.",
) -> list[str | int]:
    return [
        page,
        title,
        route,
        route_name,
        method,
        "canonical middleware or runtime gate",
        "canonical policy or authenticated owner boundary",
        controller,
        "canonical request/DTO or bounded command input",
        service,
        "canonical DTOs where present",
        "canonical models where present",
        "canonical database tables where present",
        "canonical API or CLI boundary where present",
        "canonical job/event where present",
        "existing canonical view or N/A",
        "existing frontend or N/A",
        "existing stylesheet or N/A",
        "existing translation namespace or N/A",
        source,
        financial,
        security,
        "canonical audit path or runtime report",
        status,
        tests,
        runtime,
        gap,
    ]


rows: list[list[str | int]] = []
rows.append(runtime_row(251, "Runtime Environment Bootstrap", "scripts/runtime_preflight.py", "runtime.preflight", "CLI", "runtime/page-251-preflight.json", "Python preflight", "Python JSON validation", "PARTIALLY VERIFIED", "PARTIALLY VERIFIED — PRE-FLIGHT ONLY", "PHP, Composer, database, Redis, browser, Rust, and providers are unavailable.", "scripts/runtime_preflight.py", "No financial mutation.", "No secret values are read or emitted."))
rows.append(runtime_row(252, "Dependency Installation Verification", "composer.json; package.json", "dependency.commands", "CLI", "runtime/page-252-dependency-verification.json", "Composer and NPM package managers", "npm ci; npm audit", "PARTIALLY VERIFIED", "PARTIALLY VERIFIED — FRONTEND ONLY", "Composer is unavailable; npm audit reports one moderate and one high vulnerability.", "composer and npm commands", "No financial mutation.", "Dependency findings are recorded rather than hidden."))
rows.append(runtime_row(253, "Laravel Boot Verification", "artisan", "artisan.runtime", "CLI", "RUNTIME-VERIFICATION-REPORT.md", "Laravel Artisan", "php artisan about; route:list; config:show", "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE", gap="PHP and vendor are unavailable; container and routes are not verified.", controller="Laravel Artisan runtime", financial="No financial mutation.", security="No runtime policy claim."))
rows.append(runtime_row(254, "Database Connection Verification", "config/database.php", "database.runtime", "CLI/runtime", "config/database.php; phpunit.xml", "Laravel database manager", "database connection attempt", "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE", gap="PHP, Laravel container, credentials, and database server are unavailable.", controller="Laravel database manager", financial="Financial execution is unverified.", security="No credentials are emitted."))
rows.append(runtime_row(255, "Migration Baseline", "database/migrations", "migrate.status", "CLI", "database migration files; migrate:status command", "Artisan migration subsystem", "php artisan migrate:status", "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE", gap="Applied, pending, and batch state cannot be observed.", controller="Artisan migration subsystem", financial="Financial schema state is unverified.", security="Production migration was not run."))
rows.append(runtime_row(256, "Seeder Safety Audit", "database/seeders", "seeders.audit", "static/CLI", "runtime/pages-256-259-static-audit.json", "Seeder source inspection", "Python static audit", gap="Seeder execution and production classification require runtime review."))
rows.append(runtime_row(257, "Factory and Fixture Audit", "database/factories", "factories.audit", "static/CLI", "runtime/pages-256-259-static-audit.json", "Factory source inspection", "Python static audit", gap="Factory execution and isolation require runtime review.", financial="Synthetic fixtures are not production financial truth."))
rows.append(runtime_row(258, "Database Constraint Audit", "database/migrations", "constraints.audit", "static/CLI", "runtime/pages-256-259-static-audit.json", "Migration source inspection", "Python static audit", gap="Constraint behavior requires a real database.", financial="Money precision and foreign-key behavior are not runtime assertions."))
rows.append(runtime_row(259, "Database Transaction Audit", "app/Services", "transactions.audit", "static/CLI", "runtime/pages-256-259-static-audit.json", "Canonical finance, payment, betting, draw, lottery, and notification services", "Python static audit", gap="No transaction execution occurred.", financial="Atomicity, rollback, and idempotency are not runtime verified."))

pages = {
260: ("Concurrency Test Harness", "tests/Feature/Betting/BetPurchaseAtomicityTest.php", "tests.pages260.concurrency", "PHPUnit", "canonical atomicity and idempotency tests"),
261: ("Wallet Integrity Activation", "/player/wallet", "player.wallet", "HTTP GET", "WalletService; WalletHoldService; WalletReservationService"),
262: ("Wallet Ledger Balance Rebuild", "finance:reconcile", "finance.reconcile", "CLI", "FinancialReconciliationService; LedgerBalanceValidator"),
263: ("Wallet Double-Spend Test", "tests/Feature/Betting/BetPurchaseAtomicityTest.php", "tests.pages263.wallet", "PHPUnit", "BetPurchaseTransactionService; WalletLockService"),
264: ("Wallet Hold and Release", "app/Services/Finance/WalletHoldService.php", "finance.wallet.hold", "service", "WalletHoldService; WalletLockService"),
265: ("Wallet Reservation Expiry", "app/Services/Finance/WalletReservationService.php", "finance.wallet.reservation", "service/command", "WalletReservationService"),
266: ("Financial Transaction Idempotency", "app/Services/Finance/IdempotencyService.php", "finance.idempotency", "service", "IdempotencyService"),
267: ("Ledger Posting Contract", "app/Services/Finance/LedgerPostingService.php", "finance.ledger.posting", "service", "LedgerPostingService; LedgerBalanceValidator"),
268: ("Ledger Reversal Contract", "app/Services/Finance/FinancialReversalService.php", "finance.ledger.reversal", "service", "FinancialReversalService; LedgerPostingService"),
269: ("Financial Reconciliation Execution", "finance:reconcile", "finance.reconcile", "CLI", "ReconcileFinancialRecordsCommand; FinancialReconciliationService"),
270: ("Reconciliation Exception Lifecycle", "app/DTOs/Finance/ReconciliationDiscrepancy.php", "finance.reconciliation.exceptions", "service/report", "FinancialReconciliationService; reconciliation DTOs"),
271: ("Deposit Runtime Flow", "/api/v1/deposits", "api.v1.deposits", "HTTP POST", "DepositService; PaymentInitiationService; DepositCompletionService"),
272: ("Deposit Duplicate Callback", "/api/v1/payment/webhook", "api.payment.webhook", "HTTP POST", "PaymentWebhookService; IdempotencyService"),
273: ("Payment Callback Ownership", "/admin/payments/{payment}", "admin.payments.show", "HTTP GET", "PaymentCallbackService; object-scoped payment projection"),
274: ("Payment Signature Validation", "app/Services/Payment/PaymentWebhookVerificationService.php", "payment.webhook.verify", "HTTP POST", "PaymentWebhookVerificationService"),
275: ("Payment Provider Error Matrix", "app/Services/Payment/Drivers", "payment.provider.errors", "HTTP/API", "PaymentGatewayManager and canonical drivers"),
276: ("Payment State Machine", "app/Enums/PaymentStatus.php", "payment.state", "service/API", "FinancialStateTransitionService; PaymentVerificationService"),
277: ("Payment Event Persistence", "app/Models/PaymentWebhook.php", "payment.events", "HTTP POST/queue", "PaymentWebhookService"),
278: ("Payment Webhook Queue", "app/Services/Payment/PaymentWebhookService.php", "payment.webhook.queue", "queue", "PaymentWebhookService; queue services"),
279: ("Payment Dead-Letter Processing", "failed_jobs", "queue.failed", "CLI", "QueueHealthService; failed-job infrastructure"),
280: ("Payment Replay", "tests/Feature/Payment/WebhookReplayProtectionTest.php", "tests.payment.replay", "PHPUnit", "PaymentWebhookVerificationService; IdempotencyService"),
281: ("Withdrawal Runtime Flow", "/api/v1/withdrawals", "api.v1.withdrawals", "HTTP POST", "WithdrawalService; WithdrawalApprovalService; WithdrawalCompletionService"),
282: ("Withdrawal Duplicate Submission", "tests/Feature/Payment/WithdrawalCompletionTest.php", "tests.withdrawal.duplicate", "PHPUnit", "WithdrawalService; WalletHoldService; IdempotencyService"),
283: ("Withdrawal KYC Gate", "app/Services/Compliance/WithdrawalKycGateService.php", "withdrawal.kyc", "service/API", "WithdrawalKycGateService; KycVerificationService"),
284: ("Withdrawal Self-Exclusion and Restriction", "app/Services/Compliance/SelfExclusionService.php", "withdrawal.restrictions", "service/API", "SelfExclusionService; ResponsibleGamingService"),
285: ("Withdrawal Failure Recovery", "app/Services/Finance/WithdrawalCompletionService.php", "withdrawal.recovery", "service/queue", "WithdrawalCompletionService; FinancialReversalService"),
286: ("Withdrawal Completion Reconciliation", "app/Services/Finance/PayoutReconciliationService.php", "withdrawal.reconciliation", "service", "PayoutReconciliationService"),
287: ("Bet Purchase Runtime Activation", "/api/v1/bets", "api.v1.bets.store", "HTTP POST", "BetPurchaseService and canonical purchase pipeline"),
288: ("Bet Price Authority", "app/Services/Betting/BetCalculationService.php", "bet.price", "service/API", "BetCalculationService; MarketRuleResolver"),
289: ("Bet Currency Authority", "app/Enums/Currency.php", "bet.currency", "service/API", "Currency enum; BetPurchaseValidator"),
290: ("Bet Limit Enforcement", "app/Services/Betting/BetPurchaseRiskService.php", "bet.limits", "service/API", "BetPurchaseRiskService; ResponsibleGamingService"),
291: ("Bet Concurrency", "tests/Feature/Betting/BetPurchaseAtomicityTest.php", "tests.bet.concurrency", "PHPUnit", "BetPurchaseTransactionService; WalletLockService"),
292: ("Bet Idempotency", "app/Services/Betting/BetPurchaseIdempotencyService.php", "bet.idempotency", "service/API", "BetPurchaseIdempotencyService; IdempotencyService"),
293: ("Bet Failure Rollback", "app/Services/Betting/BetPurchaseTransactionService.php", "bet.rollback", "service", "BetPurchaseTransactionService; WalletReservationService"),
294: ("Ticket Issuance Runtime", "app/Services/Betting/BetPurchaseTicketService.php", "ticket.issuance", "service/API", "BetPurchaseTicketService; TicketOwnershipService"),
295: ("Ticket Ownership", "app/Services/Ticket/TicketOwnershipService.php", "ticket.ownership", "service/API", "TicketOwnershipService"),
296: ("Ticket Share and QR Security", "app/Services/Betting/TicketShareService.php", "ticket.share", "HTTP/API", "TicketShareService; TicketVerificationService"),
297: ("Ticket Verification Runtime", "/ticket/verify", "ticket.verification", "HTTP GET/POST", "TicketVerificationService; PublicResultVerificationService"),
298: ("Draw Open and Close Automation", "app/Console/Commands/Lottery/TickCommand.php", "lottery.tick", "CLI/scheduler", "DrawScheduleService; DrawLifecycleService"),
299: ("Draw State Machine", "app/Enums/DrawLifecycleState.php", "draw.lifecycle", "service/API", "DrawLifecycleService; DrawCertificationService"),
300: ("Draw Locking and Cutoff", "app/Http/Middleware/EnsureDrawIsOpen.php", "draw.cutoff", "HTTP", "EnsureDrawIsOpen; DrawLifecycleService"),
301: ("Result Import Runtime", "app/Services/Draw/DrawResultIngestionService.php", "result.import", "CLI/API", "DrawResultIngestionService; GloResultImportService"),
302: ("Result Provenance Persistence", "app/Models/GloResultImport.php", "result.provenance", "service/API", "GloResultImportService; DrawResultIngestionService"),
303: ("Result Duplicate Import", "tests/Feature/Glo/GloResultImportTest.php", "result.import.duplicate", "PHPUnit", "GloResultImportService"),
304: ("Result Conflict Detection", "app/Services/Draw/DrawResultValidator.php", "result.conflict", "service/API", "DrawResultValidator; provenance services"),
305: ("Result Certification", "app/Services/Draw/DrawCertificationService.php", "result.certify", "HTTP/CLI", "DrawCertificationService"),
306: ("Result Publication Gate", "app/Services/Draw/DrawResultPublicationService.php", "result.publish", "HTTP/CLI", "DrawResultPublicationService"),
307: ("Result Correction Policy", "app/Services/Draw/DrawResultConfirmationService.php", "result.correction", "service/API", "DrawResultConfirmationService; DrawResultIngestionService"),
308: ("Leading-Zero Integrity", "security/weekly-result-integrity/src/canonical.rs", "rust.leading_zero", "Rust/Laravel/API", "Rust canonicalization; Laravel result validation"),
309: ("National Lottery Data Lane", "app/Services/Lottery", "lottery.national", "API/CLI", "National lottery service family"),
310: ("Weekly Lottery Data Lane", "app/Services/Lottery", "lottery.weekly", "API/CLI", "Weekly lottery service family"),
311: ("Mega and Other Product Data Lane", "app/Services/Lottery", "lottery.product", "API/CLI", "Product-specific lottery service family"),
312: ("PCSO Data Lane", "app/Services/Lottery", "lottery.pcso", "API/CLI", "PCSO result service family"),
313: ("GLO L6 Data Lane", "app/Services/Lottery/GloL6", "lottery.glo.l6", "API/CLI", "GLO L6 authoritative service family"),
314: ("GLO L6 Purchase Contract", "app/Services/Lottery/GloL6PurchaseCapabilityService.php", "lottery.glo.l6.purchase", "API", "GloL6PurchaseCapabilityService; GloL6SalesService"),
315: ("GLO L6 Ticket Range", "app/Services/Lottery/GloL6AuthoritativeTicketEngineService.php", "lottery.glo.l6.range", "service/API", "GloL6AuthoritativeTicketEngineService"),
316: ("GLO L6 Pricing Authority", "app/Services/Lottery/GloL6PurchaseCapabilityService.php", "lottery.glo.l6.pricing", "service/API", "GLO L6 capability and pricing services"),
317: ("GLO Prize Allocation", "app/Services/Lottery/GloPrizeCatalogue.php", "lottery.glo.prizes", "service/API", "GloPrizeCatalogue; GLO prize services"),
318: ("GLO Unsold Ticket Prize Scaling", "app/Services/Lottery/GloL6ProportionalPrizeCalculator.php", "lottery.glo.prize.scale", "service", "GloL6ProportionalPrizeCalculator; exact money arithmetic"),
319: ("GLO Claim Window", "app/Services/Lottery/GloPrizeClaimService.php", "lottery.glo.claim.window", "service/API", "GloPrizeClaimService"),
320: ("GLO Prize Claim Creation", "app/Services/Lottery/GloPrizeClaimService.php", "lottery.glo.claim.create", "HTTP POST", "GloPrizeClaimService; TicketAuthenticityService"),
321: ("GLO Claim Duplicate", "tests/Feature/Glo/GloPrizeClaimTest.php", "tests.glo.claim.duplicate", "PHPUnit", "GloPrizeClaimService"),
322: ("GLO Claim Age Verification", "app/Services/Compliance/KycVerificationService.php", "lottery.glo.claim.age", "service/API", "GloPrizeClaimService; KycVerificationService"),
323: ("GLO Claim KYC", "app/Services/Compliance/WithdrawalKycGateService.php", "lottery.glo.claim.kyc", "service/API", "GloPrizeClaimService; KycVerificationService"),
324: ("GLO Payment Hold", "app/Models/GloPrizePaymentHold.php", "lottery.glo.payment.hold", "service/API", "GloPrizeClaimService; RealPrizeSettlementService"),
325: ("GLO Ticket Freeze Runtime", "app/Services/Lottery/GloTicketFreezeService.php", "lottery.glo.freeze", "service/API", "GloTicketFreezeService"),
326: ("GLO Freeze Release", "app/Console/Commands/GloExpireFreezes.php", "glo.expire-freezes", "CLI/scheduler", "GloExpireFreezes; GloTicketFreezeService"),
327: ("GLO Frozen Winner Processing", "glo:process-frozen-winners", "glo.process-frozen-winners", "CLI", "GloProcessFrozenWinners; GloFrozenWinnerService"),
328: ("GLO Public Result Publication", "app/Services/Lottery/GloResultPublicationService.php", "lottery.glo.publish", "API/CLI", "GloResultPublicationService"),
329: ("Prize Matching Engine", "app/Services/Betting/MarketResultResolver.php", "prize.match", "service", "SelectionSettlementResolver; market result services"),
330: ("Prize Settlement Engine", "app/Services/Draw/RealPrizeSettlementService.php", "prize.settlement", "service/API", "RealPrizeSettlementService; PayoutApprovalService"),
331: ("Prize Payout Idempotency", "app/Services/Finance/PayoutBatchService.php", "prize.payout.idempotency", "service/API", "PayoutBatchService; PayoutApprovalService"),
332: ("Prize Payout Failure Recovery", "app/Services/Payment/PayoutTransferService.php", "prize.payout.recovery", "service/queue", "PayoutTransferService; FinancialReversalService"),
333: ("Agent Commission Runtime", "app/Services/Agent/CommissionCalculationService.php", "agent.commission.calculate", "service/API", "CommissionCalculationService; AgentCommissionService"),
334: ("Agent Commission Duplicate", "tests/Feature/Agent/CommissionIdempotencyTest.php", "tests.agent.commission.duplicate", "PHPUnit", "AgentCommissionService; IdempotencyService"),
335: ("Agent Settlement Runtime", "app/Services/Agent/AgentCommissionSettlementService.php", "agent.commission.settlement", "service/API", "AgentCommissionSettlementService; AgentSettlementService"),
336: ("Agent Referral Attribution", "app/Services/Agent/AgentReferralService.php", "agent.referral", "authenticated API", "AgentReferralService; AgentPortalController"),
337: ("Agent Owner Isolation", "/agent/referrals/{reference}", "agent.referrals.show", "HTTP GET", "AgentReferralService; AgentPortalController"),
338: ("Notification Delivery Runtime", "app/Services/Notification/NotificationDispatchService.php", "notification.delivery", "event/queue", "NotificationDispatchService; NotificationDeliveryService"),
339: ("Notification Idempotency", "app/Services/Notification/NotificationReceiptService.php", "notification.idempotency", "service/queue", "NotificationReceiptService; NotificationSuppressionService"),
340: ("Notification Preference Enforcement", "app/Services/Notification/NotificationPreferenceService.php", "notification.preferences", "authenticated API", "NotificationPreferenceService"),
341: ("Support Case Contract", "/support", "support.index", "GET/POST", "SupportCaseService; owner-scoped support migration"),
342: ("Support Case Creation", "/support", "support.store", "POST", "SupportCaseService; CreateSupportCaseRequest"),
343: ("Support Case Owner Isolation", "/support/{reference}", "support.show", "GET", "SupportCaseService::findForOwner"),
344: ("Support Case Reply", "/support/{reference}/reply", "support.reply", "POST", "SupportCaseService::reply; ReplySupportCaseRequest"),
345: ("Support Case Escalation", "app/Services/Support/SupportCaseService.php", "support.escalation", "service", "AuditLogService; canonical compliance escalation remains separate"),
346: ("Support SLA and Age Projection", "app/Models/SupportCase.php", "support.sla", "service/view", "SupportCase timestamps and configured operational policy"),
347: ("Security Event Persistence", "app/Services/Security/SecurityEventService.php", "security.events", "event/API", "SecurityEventService; AuthenticationSecurityService"),
348: ("MFA Runtime", "app/Services/Security/MfaChallengeService.php", "security.mfa", "HTTP/API", "MfaChallengeService; SecurityEventService"),
349: ("Session Revocation", "app/Services/Security/UserSessionSecurityService.php", "security.sessions.revoke", "HTTP/API", "UserSessionSecurityService; SecurityEventService"),
350: ("Final Runtime Acceptance Gate", "scripts/pages_251_350_runtime_gate.py", "runtime.acceptance", "CLI", "Page 350 runtime gate and all canonical domain test suites"),
}

for page in range(260, 351):
    title, source, route_name, method, service = pages[page]
    if page == 314:
        rows.append(runtime_row(page, title, source, route_name, method, source, service, "Glo purchase capability tests", "NOT_CONFIGURED — FAIL CLOSED", "NOT_CONFIGURED", "Real public GLO L6 purchase contract and enabled provider/capability are not configured.", financial="No checkout, purchase, wallet debit, or ticket issuance is fabricated.", security="Capability and provider gates remain canonical."))
        continue
    if page == 327:
        rows.append(runtime_row(page, title, source, route_name, method, source, service, "GloConsoleCommandsTest; GloFrozenWinnerTest", "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE", gap="The required PHP command was attempted by the acceptance gate but PHP is unavailable.", financial="No frozen-winner payout or claim is reported.", security="Command/operator boundary remains canonical."))
        continue
    if page == 350:
        rows.append(runtime_row(page, title, source, route_name, method, source, service, "runtime/page-350-acceptance.json", "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE", gap="PHP, Composer, database, Redis, browser, provider, and Cargo requirements remain unavailable.", financial="No finance or lottery completion claim is made.", security="Final acceptance remains evidence-bound."))
        continue
    tests = {
        260: "BetPurchaseAtomicityTest; DuplicateWebhookIdempotencyTest; FinancialReconciliationComprehensiveTest",
        261: "wallet and player experience tests",
        262: "ReconcileCommandContractTest; FinancialReconciliationComprehensiveTest",
        263: "BetPurchaseAtomicityTest",
        264: "finance wallet/hold tests",
        265: "wallet reservation tests",
        266: "payment and betting idempotency tests",
        267: "ledger validator and finance tests",
        268: "financial reversal tests",
        269: "ReconcileCommandContractTest",
        270: "FinancialReconciliationComprehensiveTest",
        271: "PlayerDepositApiTest; SuccessfulDepositCompletionTest",
        272: "DuplicateWebhookIdempotencyTest",
        273: "payment access tests",
        274: "InvalidWebhookSignatureTest; PaymentWebhookSignatureVerificationTest",
        275: "PaymentGatewayManagerTest",
        276: "FailedPaymentStateTest; ExpiredPaymentTest",
        277: "PaymentCallbackServiceTest",
        278: "ProductionQueueComprehensiveTest",
        279: "ProductionQueueComprehensiveTest",
        280: "WebhookReplayProtectionTest",
        281: "WithdrawalCompletionTest; WithdrawalDestinationValidationTest",
        282: "WithdrawalCompletionTest",
        283: "WithdrawalKycGateTest",
        284: "SelfExclusionAndAgentGateTest",
        285: "WithdrawalCompletionTest",
        286: "FinancialReconciliationComprehensiveTest",
        287: "BetPurchaseAtomicityTest; BetPurchaseWebTest",
        288: "BetPurchaseAtomicityTest",
        289: "payment and betting tests",
        290: "ResponsibleGamingWebTest; SelfExclusionAndAgentGateTest",
        291: "BetPurchaseAtomicityTest",
        292: "BetPurchaseAtomicityTest; BetPurchaseApiTest",
        293: "BetPurchaseAtomicityTest",
        294: "BetPurchaseAtomicityTest; BetPurchaseWebTest",
        295: "ticket ownership tests",
        296: "ticket share tests",
        297: "TicketVerification tests",
        298: "DrawAutomationTest; ScheduleRegistrationTest",
        299: "draw lifecycle tests",
        300: "DrawAutomationTest; BetPurchaseAtomicityTest",
        301: "GloResultImportTest; LaneResultImportContractTest",
        302: "GloResultImportTest",
        303: "GloResultImportTest",
        304: "result validator tests",
        305: "draw certification tests",
        306: "result publication tests",
        307: "result correction tests",
        308: "Rust integrity vectors; result tests",
        309: "NationalLotteryIntegrationSeamTest",
        310: "weekly lottery tests",
        311: "lottery lane tests",
        312: "PcsoLotteryPublicPageTest",
        313: "GloResultImportTest; GloPublicResultHistoryTest",
        315: "GLO ticket tests",
        316: "GLO sales tests",
        317: "GLO prize tests",
        318: "GloL6ProportionalCalculatorTest",
        319: "GloPrizeClaimTest",
        320: "GloPrizeClaimTest",
        321: "GloPrizeClaimTest",
        322: "GloPrizeClaimTest",
        323: "GloPrizeClaimTest",
        324: "GloPrizeClaimTest; FinalProductionReadinessTest",
        325: "GloTicketFreezeTest",
        326: "GloTicketFreezeTest; GloConsoleCommandsTest",
        328: "GloPublicResultHistoryTest; GloResultImportTest",
        329: "settlement and result tests",
        330: "FinalProductionReadinessTest; GloPrizeClaimTest",
        331: "payout tests",
        332: "payout and reconciliation tests",
        333: "CommissionAccrualTest; CommissionCalculationTest",
        334: "CommissionIdempotencyTest; DuplicateCommissionPreventionTest",
        335: "agent settlement tests",
        336: "AgentReferralCodeUniquenessTest; UserAgentAttributionTest",
        337: "AgentReportingTest",
        338: "notification tests",
        339: "notification receipt tests",
        340: "notification preference tests",
        341: "SupportCaseOwnerIsolationTest; Pages251To350StaticContractTest",
        342: "SupportCaseOwnerIsolationTest",
        343: "SupportCaseOwnerIsolationTest",
        344: "SupportCaseOwnerIsolationTest",
        345: "support/compliance escalation tests",
        346: "support operational tests",
        347: "security event tests",
        348: "MFA security tests",
        349: "session security tests",
    }.get(page, "canonical test inventory")
    rows.append(runtime_row(page, title, source, route_name, method, source, service, tests))

if len(rows) != 100 or [int(row[0]) for row in rows] != list(range(251, 351)):
    raise RuntimeError("Pages 251–350 matrix must contain exactly 100 ordered rows.")

lines = ["", "## Pages 251–350 runtime activation audit matrix", "", "| " + " | ".join(COLUMNS) + " |", "|" + "---|" * len(COLUMNS)]
for values in rows:
    safe = [str(value).replace("|", "/").replace("\n", " ") for value in values]
    lines.append("| " + " | ".join(safe) + " |")

AUDIT.write_text(EXISTING_AUDIT + "\n".join(lines) + "\n", encoding="utf-8")
print(f"Appended {len(rows)} Pages 251–350 audit rows.")
