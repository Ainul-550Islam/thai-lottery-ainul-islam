# TYPE: Python final acceptance aggregator
# PURPOSE: Aggregate actual Pages 451–550 command evidence into the final release decision without converting blocked or unconfigured gates into success.

from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BLOCKED = "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"


def aggregate(results: list[dict[str, Any]], pages: set[int]) -> str:
    selected = [result for result in results if result.get("page") in pages]
    statuses = [result.get("status") for result in selected]
    if "FAILED" in statuses:
        return "FAILED"
    if "NOT_APPLICABLE" in statuses:
        return "NOT_APPLICABLE"
    if BLOCKED in statuses:
        return BLOCKED
    if statuses and all(status == "VERIFIED" for status in statuses):
        return "VERIFIED"
    return "NOT_VERIFIED"


def main() -> int:
    command_report = json.loads((ROOT / "runtime/pages-451-550-command-results.json").read_text(encoding="utf-8"))
    results = command_report["commands"]
    inventory = json.loads((ROOT / "runtime/pages-451-550-inventory.json").read_text(encoding="utf-8"))
    audit = (ROOT / "audit.md").read_text(encoding="utf-8")
    audit_section = audit.split("## Pages 451–550 runtime activation audit matrix", 1)[1].split("## Cumulative coverage register through Pages 1–550", 1)[0]
    audit_rows = [line for line in audit_section.splitlines() if line.startswith("| ") and line.split("|")[1].strip().isdigit()]
    cumulative = audit.split("## Cumulative coverage register through Pages 1–550", 1)[1]
    cumulative_rows = [line for line in cumulative.splitlines() if line.startswith("| ") and line.split("|")[1].strip().isdigit()]

    gates: list[dict[str, Any]] = [
        {"name": "PHP", "pages": [452, 453], "status": aggregate(results, {452, 453}), "critical": True, "evidence": "runtime/page-452-php.json; runtime/page-453-php-extensions.json"},
        {"name": "Composer", "pages": [454, 455], "status": aggregate(results, {454, 455}), "critical": True, "evidence": "runtime/page-454-composer.json; runtime/page-455-vendor.json"},
        {"name": "dependencies", "pages": [550], "status": aggregate([r for r in results if r["label"] in {"frontend install", "frontend audit", "frontend build"}], {550}), "critical": True, "evidence": "runtime/page-550-release-commands.json"},
        {"name": "Laravel boot", "pages": [456], "status": aggregate(results, {456}), "critical": True, "evidence": "runtime/page-456-laravel-boot.json"},
        {"name": "config", "pages": [457, 458], "status": aggregate(results, {457, 458}), "critical": True, "evidence": "runtime/page-457-configuration.json; runtime/page-458-configuration-cache.json"},
        {"name": "routes", "pages": [459, 460], "status": aggregate(results, {459, 460}), "critical": True, "evidence": "runtime/page-459-routes.json; runtime/page-460-route-cache.json"},
        {"name": "views", "pages": [461], "status": aggregate(results, {461}), "critical": True, "evidence": "runtime/page-461-view-cache.json"},
        {"name": "database", "pages": [463], "status": aggregate(results, {463}), "critical": True, "evidence": "runtime/page-463-database.json"},
        {"name": "migrations", "pages": [464, 465, 466], "status": aggregate(results, {464, 465, 466}), "critical": True, "evidence": "runtime/page-464-migrations.json"},
        {"name": "constraints", "pages": [467], "status": aggregate(results, {467}), "critical": True, "evidence": "runtime/pages-451-550-command-results.json"},
        {"name": "transactions", "pages": [468, 469], "status": aggregate(results, {468, 469}), "critical": True, "evidence": "runtime/pages-451-550-command-results.json"},
        {"name": "concurrency", "pages": [470, 471], "status": aggregate(results, {470, 471}), "critical": True, "evidence": "runtime/pages-451-550-command-results.json"},
        {"name": "Redis", "pages": [472], "status": aggregate(results, {472}), "critical": True, "evidence": "runtime/page-472-redis.json"},
        {"name": "locks", "pages": [474], "status": aggregate(results, {474}), "critical": True, "evidence": "runtime/pages-451-550-command-results.json"},
        {"name": "queues", "pages": [475, 476], "status": aggregate(results, {475, 476}), "critical": True, "evidence": "runtime/page-475-queue.json"},
        {"name": "retries", "pages": [477, 478, 479], "status": aggregate(results, {477, 478, 479}), "critical": True, "evidence": "runtime/pages-451-550-command-results.json"},
        {"name": "scheduler", "pages": [480, 481], "status": aggregate(results, {480, 481}), "critical": True, "evidence": "runtime/page-480-scheduler.json"},
        {"name": "draw lifecycle", "pages": [482, 483, 484, 485], "status": aggregate(results, {482, 483, 484, 485}), "critical": True, "evidence": "runtime/pages-451-550-command-results.json"},
        {"name": "result lifecycle", "pages": list(range(486, 497)), "status": aggregate(results, set(range(486, 497))), "critical": True, "evidence": "runtime/pages-451-550-command-results.json"},
        {"name": "payment", "pages": [497, 498, 499, 500], "status": aggregate(results, {497, 498, 499, 500}), "critical": True, "evidence": "runtime/page-497-provider-configuration.json; runtime/pages-451-550-command-results.json"},
        {"name": "webhook", "pages": [501, 502, 503, 504, 505], "status": aggregate(results, {501, 502, 503, 504, 505}), "critical": True, "evidence": "runtime/pages-451-550-command-results.json"},
        {"name": "wallet", "pages": [507, 508, 509, 510], "status": aggregate(results, {507, 508, 509, 510}), "critical": True, "evidence": "runtime/pages-451-550-command-results.json"},
        {"name": "withdrawal", "pages": [511, 512, 513, 514, 515, 516], "status": aggregate(results, set(range(511, 517))), "critical": True, "evidence": "runtime/pages-451-550-command-results.json"},
        {"name": "reconciliation", "pages": [506, 517, 518], "status": aggregate(results, {506, 517, 518}), "critical": True, "evidence": "runtime/pages-451-550-command-results.json"},
        {"name": "betting", "pages": [519, 520, 521], "status": aggregate(results, {519, 520, 521}), "critical": True, "evidence": "runtime/pages-451-550-command-results.json"},
        {"name": "responsible gaming", "pages": [522, 523], "status": aggregate(results, {522, 523}), "critical": True, "evidence": "runtime/pages-451-550-command-results.json"},
        {"name": "ticket", "pages": [524, 525, 526], "status": aggregate(results, {524, 525, 526}), "critical": True, "evidence": "runtime/pages-451-550-command-results.json"},
        {"name": "prize", "pages": [527, 528, 529], "status": aggregate(results, {527, 528, 529}), "critical": True, "evidence": "runtime/pages-451-550-command-results.json"},
        {"name": "payout", "pages": [530, 531], "status": aggregate(results, {530, 531}), "critical": True, "evidence": "runtime/pages-451-550-command-results.json"},
        {"name": "GLO", "pages": list(range(532, 545)), "status": "NOT_CONFIGURED", "critical": True, "evidence": "runtime/page-532-glo-capability.json"},
        {"name": "authentication", "pages": [545, 546], "status": aggregate(results, {545, 546}), "critical": True, "evidence": "runtime/page-545-browser.json; runtime/pages-451-550-command-results.json"},
        {"name": "authorization", "pages": [547], "status": aggregate(results, {547}), "critical": True, "evidence": "runtime/pages-451-550-command-results.json"},
        {"name": "CSRF", "pages": [548], "status": aggregate(results, {548}), "critical": True, "evidence": "runtime/pages-451-550-command-results.json"},
        {"name": "MFA", "pages": [549], "status": aggregate(results, {549}), "critical": True, "evidence": "runtime/pages-451-550-command-results.json"},
        {"name": "session security", "pages": [545, 549], "status": aggregate(results, {545, 549}), "critical": True, "evidence": "runtime/page-545-browser.json; runtime/pages-451-550-command-results.json"},
        {"name": "browser E2E", "pages": [545], "status": aggregate(results, {545}), "critical": True, "evidence": "runtime/page-545-browser.json"},
        {"name": "accessibility smoke", "pages": [545], "status": aggregate(results, {545}), "critical": True, "evidence": "runtime/page-545-browser.json"},
        {"name": "Rust", "pages": [550], "status": aggregate([r for r in results if r["domain"] == "rust"], {550}), "critical": True, "evidence": "runtime/page-550-release-commands.json; RUST-RUNTIME-REPORT.md"},
        {"name": "backup", "pages": [], "status": BLOCKED, "critical": True, "evidence": "runtime/backup-restore-evidence.json"},
        {"name": "restore", "pages": [], "status": BLOCKED, "critical": True, "evidence": "runtime/backup-restore-evidence.json"},
        {"name": "CI/CD", "pages": [550], "status": "PARTIALLY VERIFIED", "critical": True, "evidence": "runtime/ci-release-evidence.json; CI-CD-VERIFICATION-REPORT.md"},
        {"name": "deployment configuration", "pages": [550], "status": BLOCKED, "critical": True, "evidence": "runtime/pages-451-550-command-results.json"},
        {"name": "monitoring", "pages": [462, 550], "status": aggregate(results, {462}), "critical": True, "evidence": "runtime/page-462-health.json"},
        {"name": "logging/redaction", "pages": [462, 497, 550], "status": BLOCKED, "critical": True, "evidence": "SECURITY-RUNTIME-REPORT.md"},
        {"name": "final audit", "pages": [550], "status": "VERIFIED" if len(audit_rows) == 100 and len(cumulative_rows) == 550 else "FAILED", "critical": True, "evidence": "audit.md; PAGES-451-550-MATRICES.md"},
    ]

    blockers = [gate["name"] for gate in gates if gate["critical"] and gate["status"] != "VERIFIED"]
    report = {
        "type": "pages_451_550_final_acceptance",
        "purpose": "Evidence-backed final release-candidate decision for Pages 451–550.",
        "observed_at_utc": datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
        "inventory": "runtime/pages-451-550-inventory.json",
        "command_results": "runtime/pages-451-550-command-results.json",
        "command_summary": command_report["summary"],
        "audit_summary": {"pages_451_550_rows": len(audit_rows), "cumulative_pages_1_550_rows": len(cumulative_rows)},
        "gates": gates,
        "blockers": blockers,
        "final_acceptance": "RELEASE BLOCKED" if blockers else "RELEASE READY",
        "zip_decision": "DO NOT CREATE PRODUCTION ZIP" if blockers else "CREATE PRODUCTION ZIP",
        "secrets_policy": "No secret values are recorded.",
    }
    out = ROOT / "runtime/pages-451-550-final-acceptance.json"
    out.write_text(json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
