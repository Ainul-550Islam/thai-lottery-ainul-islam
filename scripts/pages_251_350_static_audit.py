# TYPE: Python static backend audit
# PURPOSE: Inspect seeders, factories, migrations, transaction boundaries, canonical tests, commands, and Rust artifacts for Pages 256–350 without claiming runtime success.

from __future__ import annotations

import json
import re
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]


def relative(path: Path) -> str:
    return str(path.relative_to(ROOT))


def source_text(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="replace")


def classify_seeder(path: Path, text: str) -> str:
    lowered = f"{path.name} {text}".lower()
    if "result" in lowered or "fixture" in lowered:
        return "fixture-only or reference-data review required"
    if "role" in lowered or "permission" in lowered or "ledgeraccount" in lowered:
        return "reference data or production-safe review required"
    return "development-only classification not proven"


def classify_factory(path: Path, text: str) -> dict[str, Any]:
    lowered = f"{path.name} {text}".lower()
    domains = []
    for keyword, domain in [
        ("wallet", "wallet"),
        ("payment", "payment"),
        ("deposit", "deposit"),
        ("withdraw", "withdrawal"),
        ("financial", "financial transaction"),
        ("ledger", "ledger"),
        ("ticket", "ticket"),
        ("draw", "draw"),
        ("result", "result"),
        ("commission", "commission"),
        ("kyc", "KYC"),
        ("contact", "support/contact"),
    ]:
        if keyword in lowered:
            domains.append(domain)
    return {
        "classification": "fixture-only",
        "domains": sorted(set(domains)),
        "uses_factory_definition": "Factory" in text or "factory" in text,
        "must_not_run_in_production": True,
    }


def migration_facts(path: Path, text: str) -> dict[str, Any]:
    return {
        "foreign_key_references": len(re.findall(r"foreignId|foreign\s*\(", text)),
        "unique_constraints": len(re.findall(r"->unique\(|unique\s*\(", text)),
        "indexes": len(re.findall(r"->index\(|index\s*\(", text)),
        "money_columns": len(re.findall(r"decimal\s*\(|unsignedDecimal\s*\(", text)),
        "enum_columns": len(re.findall(r"->enum\(", text)),
        "timestamps": "timestamps" in text,
        "cascade_tokens": len(re.findall(r"cascade|restrict|nullOnDelete|constrained", text, re.I)),
    }


def transaction_facts(path: Path, text: str) -> dict[str, Any]:
    return {
        "db_transaction": len(re.findall(r"DB::transaction|->transaction\(", text)),
        "explicit_begin": len(re.findall(r"beginTransaction\(", text)),
        "explicit_commit": len(re.findall(r"commit\(", text)),
        "explicit_rollback": len(re.findall(r"rollBack\(", text)),
        "idempotency_reference": "Idempotency" in text or "idempot" in text.lower(),
        "ledger_reference": "Ledger" in text or "ledger" in text,
        "wallet_reference": "Wallet" in text or "wallet" in text.lower(),
        "reservation_reference": "Reservation" in text or "reservation" in text.lower(),
    }


def existing_paths(patterns: list[str]) -> list[str]:
    found: list[str] = []
    for pattern in patterns:
        found.extend(relative(path) for path in ROOT.glob(pattern) if path.is_file())
    return sorted(set(found))


def main() -> int:
    seeders = []
    for path in sorted((ROOT / "database/seeders").rglob("*.php")):
        text = source_text(path)
        seeders.append({
            "path": relative(path),
            "classification": classify_seeder(path, text),
            "contains_result_data_reference": "result" in text.lower(),
            "contains_factory_reference": "factory" in text.lower(),
        })

    factories = []
    for path in sorted((ROOT / "database/factories").rglob("*.php")):
        factories.append({
            "path": relative(path),
            **classify_factory(path, source_text(path)),
        })

    migrations = []
    for path in sorted((ROOT / "database/migrations").rglob("*.php")):
        migrations.append({"path": relative(path), **migration_facts(path, source_text(path))})

    transaction_targets = [
        "app/Services/Finance/*.php",
        "app/Services/Payment/*.php",
        "app/Services/Betting/*.php",
        "app/Services/Draw/*.php",
        "app/Services/Lottery/*.php",
        "app/Services/Agent/*.php",
        "app/Services/Compliance/*.php",
        "app/Services/Notification/*.php",
        "app/Services/Security/*.php",
        "app/Services/Support/*.php",
    ]
    transactions = []
    for relative_pattern in transaction_targets:
        for path in sorted(ROOT.glob(relative_pattern)):
            if path.is_file():
                transactions.append({"path": relative(path), **transaction_facts(path, source_text(path))})

    canonical_map = {
        "finance": existing_paths(["app/Services/Finance/*.php", "app/DTOs/Finance/*.php", "app/Enums/*Finance*.php"]),
        "payment": existing_paths(["app/Services/Payment/**/*.php", "app/DTOs/Payment/*.php", "app/Enums/Payment*.php"]),
        "betting": existing_paths(["app/Services/Betting/*.php", "app/DTOs/Betting/*.php", "app/Enums/Bet*.php"]),
        "draw": existing_paths(["app/Services/Draw/*.php", "app/DTOs/Draw/*.php", "app/Enums/Draw*.php"]),
        "lottery": existing_paths(["app/Services/Lottery/*.php", "app/Models/*Lottery*.php"]),
        "compliance": existing_paths(["app/Services/Compliance/*.php", "app/Services/Security/*Kyc*.php", "app/DTOs/Compliance/*.php"]),
        "notification": existing_paths(["app/Services/Notification/*.php", "app/DTOs/Notification/*.php"]),
        "security": existing_paths(["app/Services/Security/*.php", "app/DTOs/Security/*.php", "app/Enums/Security*.php"]),
        "support": existing_paths(["app/Services/Support/*.php", "app/Http/Controllers/Support/*.php"]),
        "rust": existing_paths(["security/weekly-result-integrity/**/*.rs", "security/weekly-result-integrity/Cargo.*"]),
    }

    report = {
        "type": "pages_251_350_static_backend_audit",
        "purpose": "Static evidence only; no runtime conclusion is inferred.",
        "seeders": seeders,
        "factories": factories,
        "migration_summary": {
            "count": len(migrations),
            "files": migrations,
        },
        "transaction_targets": transactions,
        "canonical_architecture_map": canonical_map,
        "runtime_boundary": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
        "secrets_policy": "No secret values are read or emitted.",
    }
    output = json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
    output_path = ROOT / "runtime" / "pages-256-259-static-audit.json"
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(output, encoding="utf-8")
    print(output, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
