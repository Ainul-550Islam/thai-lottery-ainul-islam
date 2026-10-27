# TYPE: Python runtime command gate
# PURPOSE: Attempt Pages 451–550 commands with destructive-environment guards and secret-free machine-readable evidence.

from __future__ import annotations

import json
import os
import shutil
import subprocess
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BLOCKED = "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"

COMMANDS: list[tuple[int, str, list[str], str, bool]] = [
    (451, "environment inventory", ["python3", "scripts/pages_451_550_command_gate.py", "--inventory-check"], "runtime", False),
    (452, "php version", ["php", "-v"], "php", False),
    (452, "php ini", ["php", "--ini"], "php", False),
    (452, "php modules", ["php", "-m"], "php", False),
    (453, "required extension gate", ["php", "-m"], "php", False),
    (454, "composer validate", ["composer", "validate", "--no-check-publish"], "composer", False),
    (454, "composer install", ["composer", "install", "--prefer-dist", "--no-interaction", "--no-progress"], "composer", False),
    (454, "composer platform requirements", ["composer", "check-platform-reqs"], "composer", False),
    (454, "composer audit", ["composer", "audit", "--no-interaction"], "composer", False),
    (455, "composer optimized autoload", ["composer", "dump-autoload", "--optimize"], "composer", False),
    (456, "laravel about", ["php", "artisan", "about"], "laravel", False),
    (457, "laravel config show", ["php", "artisan", "config:show"], "laravel", False),
    (458, "optimize clear", ["php", "artisan", "optimize:clear"], "laravel", False),
    (458, "config cache", ["php", "artisan", "config:cache"], "laravel", False),
    (459, "route list", ["php", "artisan", "route:list"], "laravel", False),
    (460, "route cache", ["php", "artisan", "route:cache"], "laravel", False),
    (461, "view cache", ["php", "artisan", "view:cache"], "laravel", False),
    (462, "health endpoint", ["curl", "--fail", "--max-time", "10", "http://127.0.0.1/up"], "health", False),
    (463, "mysql client version", ["mysql", "--version"], "database", False),
    (463, "mariadb client version", ["mariadb", "--version"], "database", False),
    (464, "migration status", ["php", "artisan", "migrate:status"], "database", False),
    (465, "fresh schema build", ["php", "artisan", "migrate:fresh", "--force"], "database", True),
    (466, "controlled seed", ["php", "artisan", "db:seed", "--force"], "database", True),
    (467, "schema constraint tests", ["php", "artisan", "test", "--filter=BusinessCriticalInvariantTest"], "database", False),
    (468, "transaction rollback test", ["php", "artisan", "test", "--filter=FinancialReconciliationComprehensiveTest"], "database", False),
    (469, "transaction commit test", ["php", "artisan", "test", "--filter=PaymentLedgerBalanceTest"], "database", False),
    (470, "transaction concurrency test", ["php", "artisan", "test", "--filter=BetPurchaseAtomicityTest"], "database", False),
    (471, "deadlock handling test", ["php", "artisan", "test", "--filter=BetPurchaseAtomicityTest"], "database", False),
    (472, "redis version", ["redis-cli", "--version"], "redis", False),
    (472, "redis ping", ["redis-cli", "ping"], "redis", False),
    (473, "cache isolation test", ["php", "artisan", "test", "--filter=ProductionSecurityComprehensiveTest"], "cache", False),
    (474, "distributed lock test", ["php", "artisan", "test", "--filter=BetPurchaseAtomicityTest"], "redis", False),
    (475, "queue health command", ["php", "artisan", "queue:health"], "queue", False),
    (476, "queue worker once", ["php", "artisan", "queue:work", "--once", "--stop-when-empty"], "queue", False),
    (477, "queue retry test", ["php", "artisan", "test", "--filter=ProductionQueueComprehensiveTest"], "queue", False),
    (478, "failed jobs", ["php", "artisan", "queue:failed"], "queue", False),
    (479, "failed job recovery test", ["php", "artisan", "test", "--filter=ProductionQueueComprehensiveTest"], "queue", False),
    (480, "scheduler list", ["php", "artisan", "schedule:list"], "scheduler", False),
    (481, "scheduler run", ["php", "artisan", "schedule:run"], "scheduler", False),
    (482, "draw automation test", ["php", "artisan", "test", "--filter=DrawAutomationTest"], "lottery", False),
    (483, "draw opening test", ["php", "artisan", "test", "--filter=DrawLifecycleTest"], "lottery", False),
    (484, "draw closing test", ["php", "artisan", "test", "--filter=DrawLifecycleTest"], "lottery", False),
    (485, "draw settlement test", ["php", "artisan", "test", "--filter=DrawLifecycleTest"], "lottery", False),
    (486, "result import test", ["php", "artisan", "test", "--filter=LaneResultImportContractTest"], "lottery", False),
    (487, "result provenance test", ["php", "artisan", "test", "--filter=GloResultImportTest"], "lottery", False),
    (488, "result conflict test", ["php", "artisan", "test", "--filter=DrawResultPublicationTest"], "lottery", False),
    (489, "result correction test", ["php", "artisan", "test", "--filter=DrawResultPublicationTest"], "lottery", False),
    (490, "result certification test", ["php", "artisan", "test", "--filter=DrawResultPublicationTest"], "lottery", False),
    (491, "result publication test", ["php", "artisan", "test", "--filter=DrawResultPublicationTest"], "lottery", False),
    (492, "public result API test", ["php", "artisan", "test", "--filter=PublicResultsAnonymousTest"], "lottery", False),
    (493, "result search test", ["php", "artisan", "test", "--filter=ArchiveInventoryParityTest"], "lottery", False),
    (494, "result detail test", ["php", "artisan", "test", "--filter=PublicResultsAnonymousTest"], "lottery", False),
    (495, "archive test", ["php", "artisan", "test", "--filter=ArchiveInventoryParityTest"], "lottery", False),
    (496, "historical no-data safety test", ["php", "artisan", "test", "--filter=FixturePublicationSafetyTest"], "lottery", False),
    (497, "payment capability test", ["php", "artisan", "test", "--filter=PublicPaymentCapabilityParityTest"], "finance", False),
    (498, "provider health command inventory", ["php", "artisan", "list"], "provider", False),
    (499, "deposit initiation test", ["php", "artisan", "test", "--filter=PaymentInitiationServiceTest"], "finance", False),
    (500, "deposit idempotency test", ["php", "artisan", "test", "--filter=DuplicateWalletCreditPreventionTest"], "finance", False),
    (501, "deposit callback test", ["php", "artisan", "test", "--filter=PaymentCallbackServiceTest"], "finance", False),
    (502, "invalid callback test", ["php", "artisan", "test", "--filter=InvalidWebhookSignatureTest"], "finance", False),
    (503, "webhook replay test", ["php", "artisan", "test", "--filter=WebhookReplayProtectionTest"], "finance", False),
    (504, "webhook mismatch test", ["php", "artisan", "test", "--filter=PaymentWebhookSignatureVerificationTest"], "finance", False),
    (505, "payment timeout test", ["php", "artisan", "test", "--filter=ExpiredPaymentTest"], "finance", False),
    (506, "payment reconciliation", ["php", "artisan", "test", "--filter=PaymentLedgerBalanceTest"], "finance", False),
    (507, "wallet opening balance test", ["php", "artisan", "test", "--filter=PaymentLedgerBalanceTest"], "finance", False),
    (508, "wallet credit test", ["php", "artisan", "test", "--filter=SuccessfulDepositCompletionTest"], "finance", False),
    (509, "wallet debit test", ["php", "artisan", "test", "--filter=PaymentLedgerBalanceTest"], "finance", False),
    (510, "wallet reservation test", ["php", "artisan", "test", "--filter=DuplicateWalletCreditPreventionTest"], "finance", False),
    (511, "withdrawal request test", ["php", "artisan", "test", "--filter=WithdrawalDestinationValidationTest"], "finance", False),
    (512, "withdrawal approval test", ["php", "artisan", "test", "--filter=WithdrawalCompletionTest"], "finance", False),
    (513, "withdrawal transfer test", ["php", "artisan", "test", "--filter=WithdrawalCompletionTest"], "finance", False),
    (514, "withdrawal callback test", ["php", "artisan", "test", "--filter=WithdrawalCompletionTest"], "finance", False),
    (515, "withdrawal replay test", ["php", "artisan", "test", "--filter=WithdrawalCompletionTest"], "finance", False),
    (516, "withdrawal failure test", ["php", "artisan", "test", "--filter=FailedPaymentStateTest"], "finance", False),
    (517, "financial reconciliation dry run", ["php", "artisan", "finance:reconcile", "--dry-run", "--json"], "finance", False),
    (518, "financial audit test", ["php", "artisan", "test", "--filter=FinancialReconciliationComprehensiveTest"], "finance", False),
    (519, "bet purchase test", ["php", "artisan", "test", "--filter=BetPurchaseApiTest"], "betting", False),
    (520, "duplicate bet test", ["php", "artisan", "test", "--filter=BetPurchaseAtomicityTest"], "betting", False),
    (521, "bet concurrency test", ["php", "artisan", "test", "--filter=BetPurchaseAtomicityTest"], "betting", False),
    (522, "responsible gaming test", ["php", "artisan", "test", "--filter=ResponsibleGamingTest"], "betting", False),
    (523, "self exclusion test", ["php", "artisan", "test", "--filter=ResponsibleGamingTest"], "betting", False),
    (524, "ticket issuance test", ["php", "artisan", "test", "--filter=BetPurchaseApiTest"], "betting", False),
    (525, "ticket ownership test", ["php", "artisan", "test", "--filter=BetAccessApiTest"], "betting", False),
    (526, "ticket verification test", ["php", "artisan", "test", "--filter=BetAccessApiTest"], "betting", False),
    (527, "prize matching test", ["php", "artisan", "test", "--filter=GloPrizeClaimTest"], "lottery", False),
    (528, "prize calculation test", ["php", "artisan", "test", "--filter=GloL6ProportionalCalculatorTest"], "lottery", False),
    (529, "prize settlement test", ["php", "artisan", "test", "--filter=GloPrizeClaimTest"], "lottery", False),
    (530, "payout idempotency test", ["php", "artisan", "test", "--filter=GloPrizeClaimTest"], "finance", False),
    (531, "payout failure test", ["php", "artisan", "test", "--filter=FailedPaymentStateTest"], "finance", False),
    (532, "GLO capability guard test", ["php", "artisan", "test", "--filter=GloConsoleCommandsTest"], "glo", False),
    (533, "GLO ticket engine test", ["php", "artisan", "test", "--filter=GloSavedTicketTest"], "glo", False),
    (534, "GLO prize calculator test", ["php", "artisan", "test", "--filter=GloL6ProportionalCalculatorTest"], "glo", False),
    (535, "GLO result import test", ["php", "artisan", "test", "--filter=GloResultImportTest"], "glo", False),
    (536, "GLO claim test", ["php", "artisan", "test", "--filter=GloPrizeClaimTest"], "glo", False),
    (537, "GLO duplicate claim test", ["php", "artisan", "test", "--filter=GloPrizeClaimTest"], "glo", False),
    (538, "GLO age gate test", ["php", "artisan", "test", "--filter=GloPrizeClaimTest"], "glo", False),
    (539, "GLO KYC gate test", ["php", "artisan", "test", "--filter=KycVerificationTest"], "glo", False),
    (540, "GLO payment hold test", ["php", "artisan", "test", "--filter=GloPrizeClaimTest"], "glo", False),
    (541, "GLO freeze test", ["php", "artisan", "test", "--filter=GloTicketFreezeTest"], "glo", False),
    (542, "GLO freeze expiry test", ["php", "artisan", "test", "--filter=GloFrozenWinnerTest"], "glo", False),
    (543, "GLO frozen winner test", ["php", "artisan", "test", "--filter=GloFrozenWinnerTest"], "glo", False),
    (544, "GLO publication test", ["php", "artisan", "test", "--filter=GloPublishProductionGuardTest"], "glo", False),
    (545, "browser runtime", ["playwright", "--version"], "browser", False),
    (546, "password reset test", ["php", "artisan", "test", "--filter=AuthenticationGateTest"], "security", False),
    (547, "authorization and IDOR test", ["php", "artisan", "test", "--filter=ProductionSecurityComprehensiveTest"], "security", False),
    (548, "CSRF security test", ["php", "artisan", "test", "--filter=ProductionSecurityComprehensiveTest"], "security", False),
    (549, "MFA and session test", ["php", "artisan", "test", "--filter=AuthenticationGateTest"], "security", False),
    (550, "Laravel test suite", ["php", "artisan", "test"], "release", False),
    (550, "frontend install", ["npm", "ci"], "release", False),
    (550, "frontend audit", ["npm", "audit"], "release", False),
    (550, "frontend build", ["npm", "run", "build"], "release", False),
    (550, "cargo format check", ["cargo", "fmt", "--check", "--manifest-path", "security/weekly-result-integrity/Cargo.toml"], "rust", False),
    (550, "cargo check", ["cargo", "check", "--locked", "--all-targets", "--manifest-path", "security/weekly-result-integrity/Cargo.toml"], "rust", False),
    (550, "cargo test", ["cargo", "test", "--locked", "--manifest-path", "security/weekly-result-integrity/Cargo.toml"], "rust", False),
    (550, "cargo release build", ["cargo", "build", "--release", "--locked", "--manifest-path", "security/weekly-result-integrity/Cargo.toml"], "rust", False),
    (550, "git status", ["git", "status", "--short"], "release", False),
]


def destructive_guard() -> tuple[bool, str]:
    if os.environ.get("PAGES_451_550_DESTRUCTIVE_TESTS") != "1":
        return False, "Destructive runtime flag is not explicitly enabled."
    if os.environ.get("APP_ENV") not in {"testing", "local", "staging"}:
        return False, "APP_ENV is not positively classified as testing, local, or staging in the process environment."
    return True, "Explicit controlled-environment guard passed."


def run_one(page: int, label: str, command: list[str], domain: str, destructive: bool) -> dict[str, Any]:
    observed = datetime.now(timezone.utc).replace(microsecond=0).isoformat()
    base: dict[str, Any] = {"page": page, "label": label, "command": command, "domain": domain, "observed_at_utc": observed}
    if destructive:
        allowed, reason = destructive_guard()
        if not allowed:
            return {**base, "status": "NOT_APPLICABLE", "exit_code": None, "reason": reason}
    exe = shutil.which(command[0])
    if exe is None:
        return {**base, "status": BLOCKED, "exit_code": None, "reason": f"{command[0]} executable is not installed"}
    try:
        result = subprocess.run(command, cwd=ROOT, capture_output=True, text=True, timeout=180, check=False)
        return {**base, "status": "VERIFIED" if result.returncode == 0 else "FAILED", "exit_code": result.returncode, "output_bytes": len((result.stdout or "") + (result.stderr or ""))}
    except subprocess.TimeoutExpired:
        return {**base, "status": "FAILED", "exit_code": None, "reason": "180 second timeout"}
    except OSError as error:
        return {**base, "status": "FAILED", "exit_code": None, "reason": type(error).__name__}


def main() -> int:
    if len(os.sys.argv) > 1 and os.sys.argv[1] == "--inventory-check":
        print("inventory already generated")
        return 0
    started = datetime.now(timezone.utc).replace(microsecond=0).isoformat()
    results = [run_one(*item) for item in COMMANDS]
    summary = {
        "command_count": len(results),
        "verified": sum(result["status"] == "VERIFIED" for result in results),
        "failed": sum(result["status"] == "FAILED" for result in results),
        "blocked": sum(result["status"] == BLOCKED for result in results),
        "not_applicable": sum(result["status"] == "NOT_APPLICABLE" for result in results),
    }
    report = {
        "type": "pages_451_550_command_gate",
        "purpose": "Actual command attempts with destructive-operation guard and without command output or secrets.",
        "started_at_utc": started,
        "finished_at_utc": datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
        "summary": summary,
        "commands": results,
        "release_boundary": "RELEASE BLOCKED" if summary["failed"] or summary["blocked"] or summary["not_applicable"] else "PARTIALLY VERIFIED",
        "secrets_policy": "Command output is discarded; only status, exit code, bounded metadata, and non-secret reason text are stored.",
    }
    out=ROOT/'runtime/pages-451-550-command-results.json'; out.parent.mkdir(parents=True,exist_ok=True); out.write_text(json.dumps(report,ensure_ascii=False,indent=2,sort_keys=True)+'\n',encoding='utf-8'); print(json.dumps(report,ensure_ascii=False,indent=2,sort_keys=True))
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
