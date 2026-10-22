#!/usr/bin/env python3
"""Offline source-completeness checks; never loads secrets or moves money."""
from __future__ import annotations

import argparse
import json
import re
import subprocess
from pathlib import Path
from typing import Any

IMPORT = re.compile(r"^\s*use\s+(App\\[^;]+);", re.MULTILINE)
REQUIRED = (
    "App\\Providers\\WalletConversionServiceProvider",
    "App\\Http\\Controllers\\Api\\V1\\WalletConversionController",
    "App\\Enums\\WalletConversionDirection",
    "App\\Enums\\WalletConversionStatus",
    "App\\Enums\\WalletConversionChannel",
    "App\\Enums\\WalletConversionRejectionReason",
    "App\\Enums\\ConversionFeeBearer",
    "App\\Models\\WalletConversion",
    "App\\Models\\WalletConversionQuote",
    "App\\Services\\Wallet\\Conversion\\WalletConversionService",
    "App\\Services\\Wallet\\Conversion\\ConversionFeeCalculator",
    "App\\Services\\Wallet\\Conversion\\ConversionQuoteService",
    "App\\Services\\Wallet\\Conversion\\ConversionLimitService",
    "App\\Services\\Wallet\\Conversion\\ConversionHistoryService",
)


def class_path(root: Path, name: str) -> Path:
    if not name.startswith("App\\"):
        raise ValueError("Only App namespace is supported")
    return root / "app" / (name[4:].replace("\\", "/") + ".php")


def imports(text: str) -> set[str]:
    names: set[str] = set()
    for match in IMPORT.finditer(text):
        declaration = match.group(1).strip()
        if "{" in declaration:
            prefix, members = declaration.split("{", 1)
            for member in members.rstrip("}").split(","):
                name = re.split(r"\s+as\s+", member.strip())[0].strip()
                if name:
                    names.add(prefix + name)
        else:
            names.add(re.split(r"\s+as\s+", declaration)[0].strip())
    return names


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="replace") if path.is_file() else ""


def source_missing(root: Path) -> list[dict[str, Any]]:
    references: dict[str, set[str]] = {}
    files: set[Path] = set()
    for directory in ("app", "bootstrap", "config", "routes", "tests"):
        files.update((root / directory).rglob("*.php"))
    for path in sorted(files):
        text = read(path)
        for name in imports(text):
            target = class_path(root, name)
            # A directory alone does not establish a namespace alias: a class and
            # its child namespace can share a name (for example a Filament Resource).
            declaration = re.search(r"use\s+" + re.escape(name) + r"(?:\s+as\s+(\w+))?\s*;", text)
            alias = (declaration.group(1) if declaration and declaration.group(1) else name.rsplit("\\", 1)[1])
            body = IMPORT.sub("", text)
            namespace_use = re.search(r"(?<![\w\\])" + re.escape(alias) + r"\\[A-Za-z_]", body)
            class_use = re.search(r"(?<![\w\\])" + re.escape(alias) + r"\s*::", body)
            namespace_alias = target.with_suffix("").is_dir() and namespace_use and not class_use
            if not target.is_file() and not namespace_alias:
                references.setdefault(name, set()).add(path.relative_to(root).as_posix())
    return [
        {"class": name, "expected_file": class_path(root, name).relative_to(root).as_posix(),
         "referenced_by": sorted(paths)}
        for name, paths in sorted(references.items())
    ]


def migration_mentions(root: Path, table: str) -> list[str]:
    # Evidence of a table declaration, not proof that it was migrated anywhere.
    pattern = re.compile(r"Schema::create\(\s*['\"]" + re.escape(table) + r"['\"]")
    return [path.relative_to(root).as_posix()
            for path in sorted((root / "database" / "migrations").glob("*.php"))
            if pattern.search(read(path))]


def audit(root: Path) -> dict[str, Any]:
    root = root.resolve()
    if not (root / "composer.json").is_file():
        raise ValueError("Repository root must contain composer.json")
    try:
        commit = subprocess.run(
            ["git", "rev-parse", "HEAD"], cwd=root, capture_output=True,
            text=True, check=True, timeout=10,
        ).stdout.strip()
    except (OSError, subprocess.SubprocessError):
        commit = None
    api = read(root / "routes/api.php")
    web = read(root / "routes/web.php")
    provider_file = read(root / "bootstrap/providers.php")
    profile = read(root / "app/Http/Controllers/Player/PlayerProfilePortalController.php")
    method = re.search(r"function transferBalanceApi\([^)]*\)[^{]*\{([^}]+)\}", profile)
    missing = source_missing(root)
    required = [{"class": name, "file": class_path(root, name).relative_to(root).as_posix(),
                 "present": class_path(root, name).is_file()} for name in REQUIRED]
    tables = {table: migration_mentions(root, table)
              for table in ("wallet_conversions", "wallet_conversion_quotes", "wallet_conversion_audits")}
    provider_registered = "WalletConversionServiceProvider::class" in provider_file
    return {
        "scope": "Static source audit only; no Laravel boot, database, .env, credentials or network access",
        "commit": commit,
        "provider_registered": provider_registered,
        "provider_present": class_path(root, REQUIRED[0]).is_file(),
        "conversion_routes_declared": {
            "api_quote": "'/wallet/conversions/quote'" in api,
            "api_execute": "'/wallet/conversions'" in api,
            "web_quote": "'/wallet/convert/quote'" in web,
            "web_execute": "'/wallet/convert'" in web,
        },
        "legacy_transfer_returns_not_configured": bool(method and "notConfigured(" in method.group(1)),
        "required_conversion_sources": required,
        "conversion_table_create_declarations": tables,
        "missing_imported_app_class_count": len(missing),
        "missing_imported_app_classes": missing,
        "source_preflight_passed": not missing and all(item["present"] for item in required)
                                   and all(tables.values()),
        "runtime_verified": False,
        "live_payment_verified": False,
        "note": "A passing static check is not production approval; dynamic references and runtime failures need separate testing.",
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument("--json", type=Path, help="Write full machine-readable findings")
    args = parser.parse_args()
    try:
        result = audit(args.root)
    except (OSError, ValueError) as error:
        parser.error(str(error))
    payload = json.dumps(result, ensure_ascii=False, indent=2) + "\n"
    if args.json:
        args.json.parent.mkdir(parents=True, exist_ok=True)
        args.json.write_text(payload, encoding="utf-8")
    print(payload, end="")
    return 0 if result["source_preflight_passed"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
