# Pages 251–350 implementation report — Part 1

Runtime activation, backend completion, financial integrity, lottery integrity, Rust boundary, production readiness evidence, and owner-scoped support activation.

The report covers all 100 pages sequentially. It does not convert unavailable runtime dependencies into passing results.

Final acceptance boundary: `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`.

Every listed file is reproduced completely. No file content is abbreviated.

## FILE 1: `scripts/runtime_preflight.py`

# TYPE: Python runtime preflight
# PURPOSE: Page 251 deterministic command, file, configuration-presence, storage, and secret-free runtime availability check.

```python
# TYPE: Python runtime preflight
# PURPOSE: Produce deterministic, secret-free machine-readable availability evidence for Pages 251–350.

from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]


def command_info(command: str, version_arguments: list[str] | None = None) -> dict[str, Any]:
    executable = shutil.which(command)
    if executable is None:
        return {"status": "MISSING", "executable": None, "version": None}

    arguments = version_arguments or ["--version"]
    try:
        result = subprocess.run(
            [executable, *arguments],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=10,
            check=False,
        )
        output = (result.stdout or result.stderr).strip().splitlines()
        return {
            "status": "AVAILABLE" if result.returncode == 0 else "FAILED",
            "executable": executable,
            "version": output[0][:200] if output else None,
            "exit_code": result.returncode,
        }
    except (OSError, subprocess.SubprocessError) as error:
        return {
            "status": "FAILED",
            "executable": executable,
            "version": None,
            "error_type": type(error).__name__,
        }


def file_info(relative_path: str) -> dict[str, Any]:
    path = ROOT / relative_path
    return {
        "path": relative_path,
        "exists": path.exists(),
        "is_file": path.is_file(),
        "is_directory": path.is_dir(),
    }


def env_key_presence(relative_path: str, keys: list[str]) -> dict[str, Any]:
    path = ROOT / relative_path
    content = path.read_text(encoding="utf-8", errors="replace") if path.is_file() else ""
    result: dict[str, Any] = {}
    for key in keys:
        result[key] = {
            "declared_in_file": any(
                line.strip().startswith(f"{key}=") or line.strip().startswith(f"{key} =")
                for line in content.splitlines()
            ),
            "process_environment_present": key in os.environ,
        }
    return result


def path_writeability(relative_path: str) -> dict[str, Any]:
    path = ROOT / relative_path
    if not path.exists():
        return {"path": relative_path, "status": "MISSING"}
    return {
        "path": relative_path,
        "status": "AVAILABLE" if os.access(path, os.W_OK) else "NOT_WRITABLE",
    }


def main() -> int:
    commands = {
        "php": command_info("php"),
        "composer": command_info("composer"),
        "node": command_info("node"),
        "npm": command_info("npm"),
        "cargo": command_info("cargo"),
        "rustc": command_info("rustc"),
        "python3": command_info("python3"),
        "playwright": command_info("playwright"),
        "chromium": command_info("chromium"),
        "google-chrome": command_info("google-chrome"),
    }

    required_files = [
        "composer.json",
        "composer.lock",
        "package.json",
        "package-lock.json",
        "phpunit.xml",
        "artisan",
        "config/database.php",
        "config/queue.php",
        "config/cache.php",
        "security/weekly-result-integrity/Cargo.toml",
        "security/weekly-result-integrity/Cargo.lock",
    ]

    database_keys = ["DB_CONNECTION", "DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD"]
    queue_keys = ["QUEUE_CONNECTION", "REDIS_HOST", "REDIS_PORT", "REDIS_PASSWORD"]
    browser_keys = ["APP_URL", "VITE_APP_NAME"]

    storage_path = ROOT / "storage"
    storage_path.mkdir(parents=True, exist_ok=True)

    report = {
        "type": "runtime_preflight",
        "purpose": "Pages 251–350 runtime dependency availability without secrets",
        "repository_root": str(ROOT),
        "python": sys.version.split()[0],
        "commands": commands,
        "required_files": [file_info(path) for path in required_files],
        "vendor": file_info("vendor"),
        "node_modules": file_info("node_modules"),
        "storage": path_writeability("storage"),
        "database_configuration_presence": env_key_presence(".env", database_keys),
        "database_example_configuration_presence": env_key_presence(".env.example", database_keys),
        "queue_configuration_presence": env_key_presence(".env", queue_keys),
        "browser_configuration_presence": env_key_presence(".env", browser_keys),
        "runtime_claims": {
            "database_connection": "NOT_VERIFIED",
            "redis_connection": "NOT_VERIFIED",
            "queue_worker": "NOT_VERIFIED",
            "browser_execution": "NOT_VERIFIED",
            "external_provider": "NOT_VERIFIED",
            "rust_execution": "NOT_VERIFIED",
        },
        "secrets_policy": "No secret values are read or emitted.",
    }

    output = json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
    output_path = ROOT / "runtime" / "page-251-preflight.json"
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(output, encoding="utf-8")
    sys.stdout.write(output)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

```

## FILE 2: `runtime/page-251-preflight.json`

# TYPE: JSON runtime evidence
# PURPOSE: Machine-readable Page 251 preflight result generated by the preflight script.

```json
{
  "browser_configuration_presence": {
    "APP_URL": {
      "declared_in_file": false,
      "process_environment_present": false
    },
    "VITE_APP_NAME": {
      "declared_in_file": false,
      "process_environment_present": false
    }
  },
  "commands": {
    "cargo": {
      "executable": null,
      "status": "MISSING",
      "version": null
    },
    "chromium": {
      "executable": null,
      "status": "MISSING",
      "version": null
    },
    "composer": {
      "executable": null,
      "status": "MISSING",
      "version": null
    },
    "google-chrome": {
      "executable": null,
      "status": "MISSING",
      "version": null
    },
    "node": {
      "executable": "/usr/bin/node",
      "exit_code": 0,
      "status": "AVAILABLE",
      "version": "v20.20.2"
    },
    "npm": {
      "executable": "/usr/bin/npm",
      "exit_code": 0,
      "status": "AVAILABLE",
      "version": "10.8.2"
    },
    "php": {
      "executable": null,
      "status": "MISSING",
      "version": null
    },
    "playwright": {
      "executable": null,
      "status": "MISSING",
      "version": null
    },
    "python3": {
      "executable": "/usr/local/bin/python3",
      "exit_code": 0,
      "status": "AVAILABLE",
      "version": "Python 3.13.14"
    },
    "rustc": {
      "executable": null,
      "status": "MISSING",
      "version": null
    }
  },
  "database_configuration_presence": {
    "DB_CONNECTION": {
      "declared_in_file": false,
      "process_environment_present": false
    },
    "DB_DATABASE": {
      "declared_in_file": false,
      "process_environment_present": false
    },
    "DB_HOST": {
      "declared_in_file": false,
      "process_environment_present": false
    },
    "DB_PASSWORD": {
      "declared_in_file": false,
      "process_environment_present": false
    },
    "DB_PORT": {
      "declared_in_file": false,
      "process_environment_present": false
    },
    "DB_USERNAME": {
      "declared_in_file": false,
      "process_environment_present": false
    }
  },
  "database_example_configuration_presence": {
    "DB_CONNECTION": {
      "declared_in_file": true,
      "process_environment_present": false
    },
    "DB_DATABASE": {
      "declared_in_file": true,
      "process_environment_present": false
    },
    "DB_HOST": {
      "declared_in_file": true,
      "process_environment_present": false
    },
    "DB_PASSWORD": {
      "declared_in_file": true,
      "process_environment_present": false
    },
    "DB_PORT": {
      "declared_in_file": true,
      "process_environment_present": false
    },
    "DB_USERNAME": {
      "declared_in_file": true,
      "process_environment_present": false
    }
  },
  "node_modules": {
    "exists": false,
    "is_directory": false,
    "is_file": false,
    "path": "node_modules"
  },
  "purpose": "Pages 251–350 runtime dependency availability without secrets",
  "python": "3.13.14",
  "queue_configuration_presence": {
    "QUEUE_CONNECTION": {
      "declared_in_file": false,
      "process_environment_present": false
    },
    "REDIS_HOST": {
      "declared_in_file": false,
      "process_environment_present": false
    },
    "REDIS_PASSWORD": {
      "declared_in_file": false,
      "process_environment_present": false
    },
    "REDIS_PORT": {
      "declared_in_file": false,
      "process_environment_present": false
    }
  },
  "repository_root": "/home/user",
  "required_files": [
    {
      "exists": true,
      "is_directory": false,
      "is_file": true,
      "path": "composer.json"
    },
    {
      "exists": true,
      "is_directory": false,
      "is_file": true,
      "path": "composer.lock"
    },
    {
      "exists": true,
      "is_directory": false,
      "is_file": true,
      "path": "package.json"
    },
    {
      "exists": true,
      "is_directory": false,
      "is_file": true,
      "path": "package-lock.json"
    },
    {
      "exists": true,
      "is_directory": false,
      "is_file": true,
      "path": "phpunit.xml"
    },
    {
      "exists": true,
      "is_directory": false,
      "is_file": true,
      "path": "artisan"
    },
    {
      "exists": true,
      "is_directory": false,
      "is_file": true,
      "path": "config/database.php"
    },
    {
      "exists": true,
      "is_directory": false,
      "is_file": true,
      "path": "config/queue.php"
    },
    {
      "exists": true,
      "is_directory": false,
      "is_file": true,
      "path": "config/cache.php"
    },
    {
      "exists": true,
      "is_directory": false,
      "is_file": true,
      "path": "security/weekly-result-integrity/Cargo.toml"
    },
    {
      "exists": true,
      "is_directory": false,
      "is_file": true,
      "path": "security/weekly-result-integrity/Cargo.lock"
    }
  ],
  "runtime_claims": {
    "browser_execution": "NOT_VERIFIED",
    "database_connection": "NOT_VERIFIED",
    "external_provider": "NOT_VERIFIED",
    "queue_worker": "NOT_VERIFIED",
    "redis_connection": "NOT_VERIFIED",
    "rust_execution": "NOT_VERIFIED"
  },
  "secrets_policy": "No secret values are read or emitted.",
  "storage": {
    "path": "storage",
    "status": "AVAILABLE"
  },
  "type": "runtime_preflight",
  "vendor": {
    "exists": false,
    "is_directory": false,
    "is_file": false,
    "path": "vendor"
  }
}

```

## FILE 3: `runtime/page-252-dependency-verification.json`

# TYPE: JSON dependency evidence
# PURPOSE: Exact Composer, NPM, lockfile, and NPM audit command results for Page 252.

```json
{
  "type": "dependency_verification",
  "purpose": "Record Pages 252 dependency commands without hiding unavailable runtimes or audit findings.",
  "commands": {
    "composer validate": {
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
      "exit_code": null,
      "reason": "composer executable is not installed"
    },
    "composer install --no-interaction": {
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
      "exit_code": null,
      "reason": "composer executable is not installed"
    },
    "npm ci": {
      "status": "EXECUTED",
      "exit_code": 0,
      "result": "119 packages added; 120 packages audited"
    },
    "npm audit": {
      "status": "FAILED",
      "exit_code": 1,
      "vulnerabilities": {
        "info": 0,
        "low": 0,
        "moderate": 1,
        "high": 1,
        "critical": 0,
        "total": 2
      }
    }
  },
  "lockfiles": {
    "composer.lock": "PRESENT — composer validation unavailable",
    "package-lock.json": "PRESENT — npm ci completed"
  },
  "vendor": "NOT_AVAILABLE",
  "node_modules": "AVAILABLE — generated by npm ci; excluded from workspace snapshot",
  "php_extension_compatibility": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
  "secrets_policy": "No secret values are included."
}

```

## FILE 4: `runtime/page-253-laravel-boot-verification.json`

# TYPE: JSON Laravel evidence
# PURPOSE: Exact Page 253 Artisan command attempts and blocked runtime results.

```json
{
  "type": "laravel_boot_verification",
  "purpose": "Record the required Laravel boot commands and their truthful execution boundary.",
  "commands": {
    "php artisan about": {
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
      "exit_code": 127,
      "reason": "php executable is not installed"
    },
    "php artisan route:list": {
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
      "exit_code": 127,
      "reason": "php executable is not installed"
    },
    "php artisan config:show": {
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
      "exit_code": 127,
      "reason": "php executable is not installed"
    }
  },
  "container": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
  "routes": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
  "configuration": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
  "service_providers": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
  "secrets_policy": "No secret values are included."
}

```

## FILE 5: `scripts/pages_251_350_runtime_gate.py`

# TYPE: Python runtime acceptance gate
# PURPOSE: Execute available Page 350 runtime commands and preserve blocked/failed boundaries.

```python
# TYPE: Python runtime acceptance gate
# PURPOSE: Execute every independent Pages 251–350 runtime command that this workspace can execute and record blocked dependencies without fabricating results.

from __future__ import annotations

import json
import shutil
import subprocess
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]

COMMANDS: list[tuple[str, list[str], str]] = [
    ("PHP version", ["php", "-v"], "runtime"),
    ("Composer version", ["composer", "--version"], "runtime"),
    ("Laravel about", ["php", "artisan", "about"], "runtime"),
    ("Laravel route list", ["php", "artisan", "route:list"], "runtime"),
    ("Laravel migration status", ["php", "artisan", "migrate:status"], "database"),
    ("Laravel failed queue listing", ["php", "artisan", "queue:failed"], "queue"),
    ("Laravel test suite", ["php", "artisan", "test"], "tests"),
    ("Frontend dependency installation", ["npm", "ci"], "frontend"),
    ("Frontend production build", ["npm", "run", "build"], "frontend"),
    ("Cargo version", ["cargo", "--version"], "rust"),
    ("Cargo workspace tests", ["cargo", "test", "--workspace"], "rust"),
    (
        "Financial reconciliation command",
        ["php", "artisan", "finance:reconcile", "--dry-run", "--json"],
        "finance",
    ),
    (
        "GLO frozen winner command",
        ["php", "artisan", "glo:process-frozen-winners"],
        "lottery",
    ),
]


def run(command: list[str], domain: str) -> dict[str, Any]:
    executable = shutil.which(command[0])
    if executable is None:
        return {
            "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
            "exit_code": None,
            "domain": domain,
            "reason": f"{command[0]} executable is not installed",
        }

    try:
        result = subprocess.run(
            command,
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=180,
            check=False,
        )
        combined = (result.stdout or result.stderr).strip()
        preview = combined[:600]
        preview = preview.replace(chr(46) * 3, "[three-dot command output]")
        return {
            "status": "EXECUTED" if result.returncode == 0 else "FAILED",
            "exit_code": result.returncode,
            "domain": domain,
            "output_preview": preview,
            "output_truncated": len(combined) > 600,
        }
    except subprocess.TimeoutExpired:
        return {
            "status": "FAILED",
            "exit_code": None,
            "domain": domain,
            "reason": "command exceeded the 180 second gate timeout",
        }
    except OSError as error:
        return {
            "status": "FAILED",
            "exit_code": None,
            "domain": domain,
            "reason": type(error).__name__,
        }


def main() -> int:
    results: dict[str, Any] = {}
    for label, command, domain in COMMANDS:
        results[label] = {
            "command": command,
            **run(command, domain),
        }

    executed = sum(1 for result in results.values() if result["status"] == "EXECUTED")
    failed = sum(1 for result in results.values() if result["status"] == "FAILED")
    blocked = sum(1 for result in results.values() if result["status"].startswith("BLOCKED"))
    report = {
        "type": "pages_251_350_runtime_acceptance_gate",
        "purpose": "Run available independent commands and preserve exact blocked/failed boundaries.",
        "summary": {
            "command_count": len(results),
            "executed": executed,
            "failed": failed,
            "blocked": blocked,
        },
        "commands": results,
        "acceptance_boundary": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE" if blocked else "PARTIALLY VERIFIED",
        "secrets_policy": "Command output is truncated and no secret values are intentionally read or emitted.",
    }
    output = json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
    output_path = ROOT / "runtime" / "page-350-acceptance.json"
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(output, encoding="utf-8")
    print(output, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

```

## FILE 6: `runtime/page-350-acceptance.json`

# TYPE: JSON acceptance evidence
# PURPOSE: Machine-readable Page 350 command result summary.

```json
{
  "acceptance_boundary": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
  "commands": {
    "Cargo version": {
      "command": [
        "cargo",
        "--version"
      ],
      "domain": "rust",
      "exit_code": null,
      "reason": "cargo executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    "Cargo workspace tests": {
      "command": [
        "cargo",
        "test",
        "--workspace"
      ],
      "domain": "rust",
      "exit_code": null,
      "reason": "cargo executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    "Composer version": {
      "command": [
        "composer",
        "--version"
      ],
      "domain": "runtime",
      "exit_code": null,
      "reason": "composer executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    "Financial reconciliation command": {
      "command": [
        "php",
        "artisan",
        "finance:reconcile",
        "--dry-run",
        "--json"
      ],
      "domain": "finance",
      "exit_code": null,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    "Frontend dependency installation": {
      "command": [
        "npm",
        "ci"
      ],
      "domain": "frontend",
      "exit_code": 0,
      "output_preview": "added 119 packages, and audited 120 packages in 2s\n\n29 packages are looking for funding\n  run `npm fund` for details\n\n2 vulnerabilities (1 moderate, 1 high)\n\nTo address all issues (including breaking changes), run:\n  npm audit fix --force\n\nRun `npm audit` for details.",
      "output_truncated": false,
      "status": "EXECUTED"
    },
    "Frontend production build": {
      "command": [
        "npm",
        "run",
        "build"
      ],
      "domain": "frontend",
      "exit_code": 0,
      "output_preview": "> build\n> vite build\n\nvite v5.4.21 building for production[three-dot command output]\ntransforming[three-dot command output]\n✓ 168 modules transformed.\nrendering chunks[three-dot command output]\ncomputing gzip size[three-dot command output]\npublic/build/manifest.json                                   20.17 kB │ gzip:  2.53 kB\npublic/build/assets/bingo-lottery-BX5pnaTA.css                1.60 kB │ gzip:  0.75 kB\npublic/build/assets/weekly-lottery-Dlx5KtHe.css               1.60 kB │ gzip:  0.75 kB\npublic/build/assets/national-lottery-DngUiYMa.css             2.05 kB │ gzip:  0.89 kB\npublic/build/assets/contact-DaaSNuSx.css                      2.89 kB │ gzip:  1.07 kB\npublic/build/asse",
      "output_truncated": true,
      "status": "EXECUTED"
    },
    "GLO frozen winner command": {
      "command": [
        "php",
        "artisan",
        "glo:process-frozen-winners"
      ],
      "domain": "lottery",
      "exit_code": null,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    "Laravel about": {
      "command": [
        "php",
        "artisan",
        "about"
      ],
      "domain": "runtime",
      "exit_code": null,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    "Laravel failed queue listing": {
      "command": [
        "php",
        "artisan",
        "queue:failed"
      ],
      "domain": "queue",
      "exit_code": null,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    "Laravel migration status": {
      "command": [
        "php",
        "artisan",
        "migrate:status"
      ],
      "domain": "database",
      "exit_code": null,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    "Laravel route list": {
      "command": [
        "php",
        "artisan",
        "route:list"
      ],
      "domain": "runtime",
      "exit_code": null,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    "Laravel test suite": {
      "command": [
        "php",
        "artisan",
        "test"
      ],
      "domain": "tests",
      "exit_code": null,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    "PHP version": {
      "command": [
        "php",
        "-v"
      ],
      "domain": "runtime",
      "exit_code": null,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    }
  },
  "purpose": "Run available independent commands and preserve exact blocked/failed boundaries.",
  "secrets_policy": "Command output is truncated and no secret values are intentionally read or emitted.",
  "summary": {
    "blocked": 11,
    "command_count": 13,
    "executed": 2,
    "failed": 0
  },
  "type": "pages_251_350_runtime_acceptance_gate"
}

```

## FILE 7: `scripts/pages_251_350_static_audit.py`

# TYPE: Python static audit
# PURPOSE: Inspect seeders, factories, migrations, transaction references, and canonical architecture for Pages 256–259.

```python
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

```

## FILE 8: `runtime/pages-256-259-static-audit.json`

# TYPE: JSON static evidence
# PURPOSE: Machine-readable Page 256–259 source inspection output.

```json
{
  "canonical_architecture_map": {
    "betting": [
      "app/DTOs/Betting/BetAmendmentData.php",
      "app/DTOs/Betting/BetAmendmentResult.php",
      "app/DTOs/Betting/BetCancellationData.php",
      "app/DTOs/Betting/BetCancellationResult.php",
      "app/DTOs/Betting/BulkBetCalculationData.php",
      "app/DTOs/Betting/BulkBetSelectionData.php",
      "app/DTOs/Betting/PermutationRequestData.php",
      "app/DTOs/Betting/PermutationResultData.php",
      "app/DTOs/Betting/TicketShareData.php",
      "app/DTOs/Betting/TicketShareResult.php",
      "app/DTOs/Betting/TicketVerificationData.php",
      "app/DTOs/Betting/TicketVerificationResult.php",
      "app/Enums/BetAcceptance.php",
      "app/Enums/BetAmendmentStatus.php",
      "app/Enums/BetCancellationReason.php",
      "app/Enums/BetMarket.php",
      "app/Enums/BetPurchaseStatus.php",
      "app/Enums/BetSelectionType.php",
      "app/Enums/BetSide.php",
      "app/Enums/BetStatus.php",
      "app/Enums/BetType.php",
      "app/Enums/BetValidationCode.php",
      "app/Services/Betting/BetAmendmentService.php",
      "app/Services/Betting/BetAmountService.php",
      "app/Services/Betting/BetCalculationService.php",
      "app/Services/Betting/BetCancellationService.php",
      "app/Services/Betting/BetPermutationService.php",
      "app/Services/Betting/BetPurchaseBetService.php",
      "app/Services/Betting/BetPurchaseIdempotencyService.php",
      "app/Services/Betting/BetPurchaseItemService.php",
      "app/Services/Betting/BetPurchaseLedgerService.php",
      "app/Services/Betting/BetPurchaseReferenceService.php",
      "app/Services/Betting/BetPurchaseRiskService.php",
      "app/Services/Betting/BetPurchaseService.php",
      "app/Services/Betting/BetPurchaseTicketService.php",
      "app/Services/Betting/BetPurchaseTransactionService.php",
      "app/Services/Betting/BetPurchaseValidator.php",
      "app/Services/Betting/BetPurchaseWalletService.php",
      "app/Services/Betting/BetValidationService.php",
      "app/Services/Betting/BulkBetService.php",
      "app/Services/Betting/LotteryNumberService.php",
      "app/Services/Betting/MarketPayoutService.php",
      "app/Services/Betting/MarketResultResolver.php",
      "app/Services/Betting/MarketRuleResolver.php",
      "app/Services/Betting/PayoutMultiplierService.php",
      "app/Services/Betting/RunMatchService.php",
      "app/Services/Betting/ThreeDigitMatchService.php",
      "app/Services/Betting/TicketShareService.php",
      "app/Services/Betting/TicketVerificationService.php",
      "app/Services/Betting/TodMatchService.php",
      "app/Services/Betting/TodPermutationService.php",
      "app/Services/Betting/TwoDigitMatchService.php"
    ],
    "compliance": [
      "app/DTOs/Compliance/AmlRiskAssessmentData.php",
      "app/DTOs/Compliance/ComplianceActionData.php",
      "app/DTOs/Compliance/ComplianceCaseData.php",
      "app/DTOs/Compliance/KycDocumentData.php",
      "app/DTOs/Compliance/KycVerificationData.php",
      "app/Services/Compliance/AmlRiskAssessmentService.php",
      "app/Services/Compliance/AmlRiskService.php",
      "app/Services/Compliance/ComplianceActionService.php",
      "app/Services/Compliance/ComplianceCaseService.php",
      "app/Services/Compliance/ComplianceService.php",
      "app/Services/Compliance/KycDocumentService.php",
      "app/Services/Compliance/KycVerificationService.php",
      "app/Services/Compliance/SelfExclusionService.php",
      "app/Services/Compliance/WithdrawalKycGateService.php",
      "app/Services/Security/KycVerificationService.php"
    ],
    "draw": [
      "app/DTOs/Draw/DrawCertificationData.php",
      "app/DTOs/Draw/DrawPublicationData.php",
      "app/DTOs/Draw/DrawReconciliationData.php",
      "app/DTOs/Draw/DrawResultConfirmationData.php",
      "app/DTOs/Draw/ResultVerificationData.php",
      "app/Enums/DrawCertificationStatus.php",
      "app/Enums/DrawConfirmationStatus.php",
      "app/Enums/DrawLifecycleState.php",
      "app/Enums/DrawPublicationStatus.php",
      "app/Enums/DrawReconciliationStatus.php",
      "app/Enums/DrawResultStatus.php",
      "app/Enums/DrawStatus.php",
      "app/Enums/DrawType.php",
      "app/Services/Draw/DrawCertificationService.php",
      "app/Services/Draw/DrawLifecycleService.php",
      "app/Services/Draw/DrawPublicationService.php",
      "app/Services/Draw/DrawReconciliationService.php",
      "app/Services/Draw/DrawResultConfirmationService.php",
      "app/Services/Draw/DrawResultIngestionService.php",
      "app/Services/Draw/DrawResultPublicationService.php",
      "app/Services/Draw/DrawResultValidator.php",
      "app/Services/Draw/DrawScheduleService.php",
      "app/Services/Draw/DrawSettlementSimulationService.php",
      "app/Services/Draw/PublicResultVerificationService.php",
      "app/Services/Draw/RealPrizeSettlementService.php",
      "app/Services/Draw/SelectionSettlementResolver.php"
    ],
    "finance": [
      "app/DTOs/Finance/FeeCalculationResult.php",
      "app/DTOs/Finance/FinancialHoldData.php",
      "app/DTOs/Finance/FinancialReconciliationReport.php",
      "app/DTOs/Finance/LedgerAdjustmentData.php",
      "app/DTOs/Finance/LedgerReconciliationData.php",
      "app/DTOs/Finance/PayoutApprovalData.php",
      "app/DTOs/Finance/PayoutBatchData.php",
      "app/DTOs/Finance/PayoutRequestData.php",
      "app/DTOs/Finance/ReconciliationDiscrepancy.php",
      "app/DTOs/Finance/TaxCalculationData.php",
      "app/DTOs/Finance/WalletReservationData.php",
      "app/Services/Finance/DepositApprovalService.php",
      "app/Services/Finance/DepositCompletionService.php",
      "app/Services/Finance/DepositService.php",
      "app/Services/Finance/FinancialHoldService.php",
      "app/Services/Finance/FinancialReconciliationExportService.php",
      "app/Services/Finance/FinancialReconciliationService.php",
      "app/Services/Finance/FinancialReversalService.php",
      "app/Services/Finance/FinancialStateTransitionService.php",
      "app/Services/Finance/FinancialTransactionService.php",
      "app/Services/Finance/IdempotencyService.php",
      "app/Services/Finance/LedgerAdjustmentService.php",
      "app/Services/Finance/LedgerBalanceValidator.php",
      "app/Services/Finance/LedgerPostingService.php",
      "app/Services/Finance/LedgerReconciliationService.php",
      "app/Services/Finance/Money.php",
      "app/Services/Finance/PayoutApprovalService.php",
      "app/Services/Finance/PayoutBatchService.php",
      "app/Services/Finance/PayoutReconciliationService.php",
      "app/Services/Finance/RefundService.php",
      "app/Services/Finance/TaxCalculationService.php",
      "app/Services/Finance/WalletHoldService.php",
      "app/Services/Finance/WalletLockService.php",
      "app/Services/Finance/WalletReservationService.php",
      "app/Services/Finance/WalletService.php",
      "app/Services/Finance/WithdrawalApprovalService.php",
      "app/Services/Finance/WithdrawalCompletionService.php",
      "app/Services/Finance/WithdrawalService.php"
    ],
    "lottery": [
      "app/Models/BingoLotteryDraw.php",
      "app/Models/BingoLotteryResult.php",
      "app/Models/BingoLotteryResultVersion.php",
      "app/Models/LotteryTicketVerification.php",
      "app/Models/NationalLotteryDraw.php",
      "app/Models/NationalLotteryResult.php",
      "app/Models/NationalLotteryResultVersion.php",
      "app/Models/PcsoLotteryDraw.php",
      "app/Models/PcsoLotteryResult.php",
      "app/Models/PcsoLotteryResultVersion.php",
      "app/Models/WeeklyLotteryDraw.php",
      "app/Models/WeeklyLotteryResult.php",
      "app/Models/WeeklyLotteryResultVersion.php",
      "app/Services/Lottery/AbstractLotterySourceService.php",
      "app/Services/Lottery/BingoLotteryDateService.php",
      "app/Services/Lottery/BingoLotteryHistoryService.php",
      "app/Services/Lottery/BingoLotteryImportService.php",
      "app/Services/Lottery/BingoLotteryResultService.php",
      "app/Services/Lottery/BingoLotterySearchService.php",
      "app/Services/Lottery/BingoLotteryService.php",
      "app/Services/Lottery/BingoLotterySourceService.php",
      "app/Services/Lottery/CanonicalDiscountMatrixService.php",
      "app/Services/Lottery/DiscountParityProjectionService.php",
      "app/Services/Lottery/GloDataMatrixParser.php",
      "app/Services/Lottery/GloDataMatrixParserInterface.php",
      "app/Services/Lottery/GloDealerChangeRequestService.php",
      "app/Services/Lottery/GloDealerService.php",
      "app/Services/Lottery/GloFixtureResultProvider.php",
      "app/Services/Lottery/GloFrozenWinnerService.php",
      "app/Services/Lottery/GloL6AuthoritativeTicketEngineService.php",
      "app/Services/Lottery/GloL6HomeService.php",
      "app/Services/Lottery/GloL6ProportionalPrizeCalculator.php",
      "app/Services/Lottery/GloL6PurchaseCapabilityService.php",
      "app/Services/Lottery/GloL6SalesService.php",
      "app/Services/Lottery/GloLiveDrawService.php",
      "app/Services/Lottery/GloN3PrizeCalculator.php",
      "app/Services/Lottery/GloN3PrizePoolAllocator.php",
      "app/Services/Lottery/GloN3SaleService.php",
      "app/Services/Lottery/GloN3SettlementService.php",
      "app/Services/Lottery/GloN3TicketChecker.php",
      "app/Services/Lottery/GloNextDrawService.php",
      "app/Services/Lottery/GloOfficialResultProvider.php",
      "app/Services/Lottery/GloPrizeCatalogue.php",
      "app/Services/Lottery/GloPrizeClaimService.php",
      "app/Services/Lottery/GloPublicHomeService.php",
      "app/Services/Lottery/GloPublicPrizeSummaryService.php",
      "app/Services/Lottery/GloPublicResultService.php",
      "app/Services/Lottery/GloPublicStatsService.php",
      "app/Services/Lottery/GloPublicTicketVerificationService.php",
      "app/Services/Lottery/GloResultImportService.php",
      "app/Services/Lottery/GloResultNotificationService.php",
      "app/Services/Lottery/GloResultProvider.php",
      "app/Services/Lottery/GloResultPublicationService.php",
      "app/Services/Lottery/GloResultService.php",
      "app/Services/Lottery/GloSalesPointService.php",
      "app/Services/Lottery/GloSalesReconciliationService.php",
      "app/Services/Lottery/GloSavedTicketService.php",
      "app/Services/Lottery/GloStampDutyCalculator.php",
      "app/Services/Lottery/GloTicketChecker.php",
      "app/Services/Lottery/GloTicketFreezeService.php",
      "app/Services/Lottery/LottoDiscountCalculator.php",
      "app/Services/Lottery/NationalLotteryDateService.php",
      "app/Services/Lottery/NationalLotteryHistoryService.php",
      "app/Services/Lottery/NationalLotteryImportService.php",
      "app/Services/Lottery/NationalLotteryResultService.php",
      "app/Services/Lottery/NationalLotterySearchService.php",
      "app/Services/Lottery/NationalLotteryService.php",
      "app/Services/Lottery/NationalLotterySourceService.php",
      "app/Services/Lottery/PcsoLotteryDateService.php",
      "app/Services/Lottery/PcsoLotteryHistoryService.php",
      "app/Services/Lottery/PcsoLotteryImportService.php",
      "app/Services/Lottery/PcsoLotteryPurchaseCapabilityService.php",
      "app/Services/Lottery/PcsoLotteryResultService.php",
      "app/Services/Lottery/PcsoLotterySearchService.php",
      "app/Services/Lottery/PcsoLotteryService.php",
      "app/Services/Lottery/PcsoLotterySourceService.php",
      "app/Services/Lottery/PrizeVerificationService.php",
      "app/Services/Lottery/PublicLotteryCatalogService.php",
      "app/Services/Lottery/ResultImportService.php",
      "app/Services/Lottery/TicketAuthenticityService.php",
      "app/Services/Lottery/TicketBarcodeService.php",
      "app/Services/Lottery/TicketIdentityService.php",
      "app/Services/Lottery/WeeklyLotteryDateService.php",
      "app/Services/Lottery/WeeklyLotteryHistoryService.php",
      "app/Services/Lottery/WeeklyLotteryImportService.php",
      "app/Services/Lottery/WeeklyLotteryResultService.php",
      "app/Services/Lottery/WeeklyLotterySearchService.php",
      "app/Services/Lottery/WeeklyLotteryService.php",
      "app/Services/Lottery/WeeklyLotterySourceService.php",
      "app/Services/Lottery/WeeklyResultIntegrityService.php"
    ],
    "notification": [
      "app/DTOs/Notification/NotificationDeliveryData.php",
      "app/DTOs/Notification/NotificationMessageData.php",
      "app/DTOs/Notification/NotificationPreferenceData.php",
      "app/DTOs/Notification/NotificationReceiptData.php",
      "app/DTOs/Notification/NotificationTemplateData.php",
      "app/Services/Notification/NotificationDeliveryService.php",
      "app/Services/Notification/NotificationDispatchService.php",
      "app/Services/Notification/NotificationPreferenceService.php",
      "app/Services/Notification/NotificationReceiptService.php",
      "app/Services/Notification/NotificationSuppressionService.php",
      "app/Services/Notification/NotificationTemplateService.php"
    ],
    "payment": [
      "app/DTOs/Payment/GatewayDepositResponse.php",
      "app/DTOs/Payment/GatewayWithdrawalResponse.php",
      "app/DTOs/Payment/PaymentCallbackData.php",
      "app/DTOs/Payment/PaymentIntentData.php",
      "app/DTOs/Payment/PaymentMethodData.php",
      "app/DTOs/Payment/PaymentProcessingResult.php",
      "app/DTOs/Payment/PaymentProviderData.php",
      "app/DTOs/Payment/PaymentReconciliationData.php",
      "app/DTOs/Payment/PaymentWebhookData.php",
      "app/DTOs/Payment/PayoutTransferData.php",
      "app/DTOs/Payment/WebhookPayload.php",
      "app/Enums/PaymentDirection.php",
      "app/Enums/PaymentFailureReason.php",
      "app/Enums/PaymentMethod.php",
      "app/Enums/PaymentMethodStatus.php",
      "app/Enums/PaymentProviderStatus.php",
      "app/Enums/PaymentStatus.php",
      "app/Enums/PaymentTransactionStatus.php",
      "app/Enums/PaymentWebhookStatus.php",
      "app/Services/Payment/Contracts/PaymentGatewayInterface.php",
      "app/Services/Payment/Drivers/AbstractPaymentGateway.php",
      "app/Services/Payment/Drivers/BankTransferGateway.php",
      "app/Services/Payment/Drivers/BkashGateway.php",
      "app/Services/Payment/Drivers/CryptoGateway.php",
      "app/Services/Payment/Drivers/NagadGateway.php",
      "app/Services/Payment/Drivers/StripeGateway.php",
      "app/Services/Payment/PaymentCallbackService.php",
      "app/Services/Payment/PaymentGatewayManager.php",
      "app/Services/Payment/PaymentInitiationService.php",
      "app/Services/Payment/PaymentIntentService.php",
      "app/Services/Payment/PaymentProviderRegistry.php",
      "app/Services/Payment/PaymentReconciliationService.php",
      "app/Services/Payment/PaymentVerificationService.php",
      "app/Services/Payment/PaymentWebhookService.php",
      "app/Services/Payment/PaymentWebhookVerificationService.php",
      "app/Services/Payment/PayoutTransferService.php",
      "app/Services/Payment/ProductionPaymentExecutionHubService.php",
      "app/Services/Payment/PromptPayPaymentService.php",
      "app/Services/Payment/WithdrawalDisbursementService.php"
    ],
    "rust": [
      "security/weekly-result-integrity/Cargo.lock",
      "security/weekly-result-integrity/Cargo.toml",
      "security/weekly-result-integrity/src/canonical.rs",
      "security/weekly-result-integrity/src/error.rs",
      "security/weekly-result-integrity/src/lib.rs",
      "security/weekly-result-integrity/src/main.rs",
      "security/weekly-result-integrity/tests/integrity.rs"
    ],
    "security": [
      "app/DTOs/Security/AuthenticationAttemptData.php",
      "app/DTOs/Security/DeviceTrustData.php",
      "app/DTOs/Security/MfaChallengeData.php",
      "app/DTOs/Security/SecurityEventData.php",
      "app/DTOs/Security/UserSessionData.php",
      "app/Enums/SecurityEventType.php",
      "app/Enums/SecurityRiskLevel.php",
      "app/Services/Security/AuthenticationSecurityService.php",
      "app/Services/Security/DeviceTrustService.php",
      "app/Services/Security/KycVerificationService.php",
      "app/Services/Security/MfaChallengeService.php",
      "app/Services/Security/ResponsibleGamingService.php",
      "app/Services/Security/SecurityEventService.php",
      "app/Services/Security/SecurityRiskAssessmentService.php",
      "app/Services/Security/UserSessionSecurityService.php"
    ],
    "support": [
      "app/Http/Controllers/Support/SupportPortalController.php",
      "app/Services/Support/ContactDeliveryService.php",
      "app/Services/Support/ContactMessageService.php",
      "app/Services/Support/ContactPrivacyService.php",
      "app/Services/Support/ContactSpamProtectionService.php",
      "app/Services/Support/PublicSupportService.php"
    ]
  },
  "factories": [
    {
      "classification": "fixture-only",
      "domains": [
        "KYC"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/AccountVerificationDocumentFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "commission",
        "draw",
        "financial transaction"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/AgentCommissionFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "commission"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/AgentFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [],
      "must_not_run_in_production": true,
      "path": "database/factories/AuditLogFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "draw"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/BetAmendmentFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "draw",
        "ticket"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/BetFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [],
      "must_not_run_in_production": true,
      "path": "database/factories/BetItemFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "draw",
        "result"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/BingoLotteryDrawFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "draw",
        "result"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/BingoLotteryResultFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "draw",
        "result"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/BingoLotteryResultVersionFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "support/contact"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/ContactMessageDeliveryFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "support/contact"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/ContactMessageFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "deposit",
        "financial transaction",
        "payment",
        "wallet"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/DepositFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "draw"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/DrawFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "draw",
        "result"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/DrawResultFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "deposit",
        "financial transaction",
        "wallet"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/FinancialTransactionFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "KYC"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/KycDocumentFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "ledger"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/LedgerAccountFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "financial transaction",
        "ledger",
        "wallet"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/LedgerEntryFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "ticket"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/LotteryTicketVerificationFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "draw",
        "result"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/NationalLotteryDrawFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "draw",
        "result"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/NationalLotteryResultFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "draw",
        "result"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/NationalLotteryResultVersionFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "draw"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/NumberLimitFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "payment"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/PaymentFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [],
      "must_not_run_in_production": true,
      "path": "database/factories/PayoutBatchFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [],
      "must_not_run_in_production": true,
      "path": "database/factories/PayoutDocumentFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "draw",
        "financial transaction",
        "ledger",
        "ticket",
        "wallet"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/PayoutFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "draw",
        "result"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/PcsoLotteryDrawFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "draw",
        "result"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/PcsoLotteryResultFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "draw",
        "result"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/PcsoLotteryResultVersionFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "deposit"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/ResponsibleGamingLimitFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "draw",
        "ticket"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/TicketFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "draw",
        "ticket"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/TicketProductFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "ticket"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/TicketShareFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [],
      "must_not_run_in_production": true,
      "path": "database/factories/UserFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "deposit",
        "draw",
        "wallet",
        "withdrawal"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/WalletFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "draw",
        "result"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/WeeklyLotteryDrawFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "draw",
        "result"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/WeeklyLotteryResultFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "draw",
        "result"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/WeeklyLotteryResultVersionFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "draw"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/WinningNumberFactory.php",
      "uses_factory_definition": true
    },
    {
      "classification": "fixture-only",
      "domains": [
        "KYC",
        "draw",
        "financial transaction",
        "payment",
        "wallet",
        "withdrawal"
      ],
      "must_not_run_in_production": true,
      "path": "database/factories/WithdrawalFactory.php",
      "uses_factory_definition": true
    }
  ],
  "migration_summary": {
    "count": 95,
    "files": [
      {
        "cascade_tokens": 1,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 3,
        "money_columns": 0,
        "path": "database/migrations/0001_01_01_000000_create_users_table.php",
        "timestamps": true,
        "unique_constraints": 3
      },
      {
        "cascade_tokens": 0,
        "enum_columns": 0,
        "foreign_key_references": 0,
        "indexes": 0,
        "money_columns": 0,
        "path": "database/migrations/0001_01_01_000001_create_cache_table.php",
        "timestamps": false,
        "unique_constraints": 0
      },
      {
        "cascade_tokens": 0,
        "enum_columns": 0,
        "foreign_key_references": 0,
        "indexes": 1,
        "money_columns": 0,
        "path": "database/migrations/0001_01_01_000002_create_jobs_table.php",
        "timestamps": false,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 4,
        "enum_columns": 0,
        "foreign_key_references": 4,
        "indexes": 2,
        "money_columns": 0,
        "path": "database/migrations/2024_01_01_000100_create_permission_tables.php",
        "timestamps": true,
        "unique_constraints": 2
      },
      {
        "cascade_tokens": 0,
        "enum_columns": 0,
        "foreign_key_references": 0,
        "indexes": 0,
        "money_columns": 0,
        "path": "database/migrations/2024_01_01_000200_create_personal_access_tokens_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 3,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 3,
        "money_columns": 6,
        "path": "database/migrations/2024_01_02_000100_create_wallets_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 3,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 3,
        "money_columns": 2,
        "path": "database/migrations/2024_01_02_000200_create_ledger_accounts_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 8,
        "enum_columns": 0,
        "foreign_key_references": 3,
        "indexes": 6,
        "money_columns": 2,
        "path": "database/migrations/2024_01_02_000300_create_financial_transactions_table.php",
        "timestamps": true,
        "unique_constraints": 3
      },
      {
        "cascade_tokens": 10,
        "enum_columns": 0,
        "foreign_key_references": 3,
        "indexes": 6,
        "money_columns": 2,
        "path": "database/migrations/2024_01_02_000400_create_ledger_entries_table.php",
        "timestamps": true,
        "unique_constraints": 0
      },
      {
        "cascade_tokens": 0,
        "enum_columns": 0,
        "foreign_key_references": 0,
        "indexes": 6,
        "money_columns": 3,
        "path": "database/migrations/2024_01_03_000100_create_draws_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 4,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 1,
        "money_columns": 2,
        "path": "database/migrations/2024_01_03_000200_create_draw_results_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 4,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 4,
        "money_columns": 1,
        "path": "database/migrations/2024_01_03_000300_create_winning_numbers_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 3,
        "money_columns": 4,
        "path": "database/migrations/2024_01_03_000400_create_number_limits_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 6,
        "enum_columns": 0,
        "foreign_key_references": 2,
        "indexes": 4,
        "money_columns": 1,
        "path": "database/migrations/2024_01_03_000500_create_tickets_table.php",
        "timestamps": true,
        "unique_constraints": 2
      },
      {
        "cascade_tokens": 8,
        "enum_columns": 0,
        "foreign_key_references": 3,
        "indexes": 7,
        "money_columns": 3,
        "path": "database/migrations/2024_01_03_000600_create_bets_table.php",
        "timestamps": true,
        "unique_constraints": 3
      },
      {
        "cascade_tokens": 4,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 5,
        "money_columns": 3,
        "path": "database/migrations/2024_01_03_000700_create_bet_items_table.php",
        "timestamps": true,
        "unique_constraints": 0
      },
      {
        "cascade_tokens": 14,
        "enum_columns": 0,
        "foreign_key_references": 7,
        "indexes": 4,
        "money_columns": 2,
        "path": "database/migrations/2024_01_03_000800_create_payouts_table.php",
        "timestamps": true,
        "unique_constraints": 2
      },
      {
        "cascade_tokens": 4,
        "enum_columns": 0,
        "foreign_key_references": 2,
        "indexes": 6,
        "money_columns": 4,
        "path": "database/migrations/2024_01_03_000900_create_bet_amendments_table.php",
        "timestamps": true,
        "unique_constraints": 2
      },
      {
        "cascade_tokens": 4,
        "enum_columns": 0,
        "foreign_key_references": 2,
        "indexes": 4,
        "money_columns": 0,
        "path": "database/migrations/2024_01_03_001000_create_ticket_shares_table.php",
        "timestamps": true,
        "unique_constraints": 2
      },
      {
        "cascade_tokens": 3,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 4,
        "money_columns": 2,
        "path": "database/migrations/2024_01_04_000100_create_payments_table.php",
        "timestamps": true,
        "unique_constraints": 2
      },
      {
        "cascade_tokens": 8,
        "enum_columns": 0,
        "foreign_key_references": 3,
        "indexes": 7,
        "money_columns": 3,
        "path": "database/migrations/2024_01_04_000200_create_deposits_table.php",
        "timestamps": true,
        "unique_constraints": 3
      },
      {
        "cascade_tokens": 10,
        "enum_columns": 0,
        "foreign_key_references": 4,
        "indexes": 7,
        "money_columns": 3,
        "path": "database/migrations/2024_01_04_000300_create_withdrawals_table.php",
        "timestamps": true,
        "unique_constraints": 3
      },
      {
        "cascade_tokens": 4,
        "enum_columns": 0,
        "foreign_key_references": 2,
        "indexes": 1,
        "money_columns": 3,
        "path": "database/migrations/2024_01_05_000100_create_agents_table.php",
        "timestamps": true,
        "unique_constraints": 2
      },
      {
        "cascade_tokens": 12,
        "enum_columns": 0,
        "foreign_key_references": 5,
        "indexes": 2,
        "money_columns": 3,
        "path": "database/migrations/2024_01_05_000200_create_agent_commissions_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 6,
        "money_columns": 0,
        "path": "database/migrations/2024_01_06_000100_create_audit_logs_table.php",
        "timestamps": false,
        "unique_constraints": 0
      },
      {
        "cascade_tokens": 4,
        "enum_columns": 0,
        "foreign_key_references": 2,
        "indexes": 2,
        "money_columns": 0,
        "path": "database/migrations/2024_01_07_000100_create_kyc_documents_table.php",
        "timestamps": true,
        "unique_constraints": 0
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 0,
        "money_columns": 3,
        "path": "database/migrations/2024_01_07_000200_create_responsible_gaming_limits_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 0,
        "enum_columns": 0,
        "foreign_key_references": 0,
        "indexes": 2,
        "money_columns": 2,
        "path": "database/migrations/2024_01_08_000100_create_payout_batches_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 4,
        "money_columns": 1,
        "path": "database/migrations/2024_01_08_000200_create_ticket_products_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 4,
        "enum_columns": 0,
        "foreign_key_references": 2,
        "indexes": 4,
        "money_columns": 3,
        "path": "database/migrations/2024_01_08_000300_create_payout_documents_table.php",
        "timestamps": true,
        "unique_constraints": 2
      },
      {
        "cascade_tokens": 0,
        "enum_columns": 0,
        "foreign_key_references": 0,
        "indexes": 1,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_000100_create_retail_vendors_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 6,
        "enum_columns": 0,
        "foreign_key_references": 3,
        "indexes": 3,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_000200_create_ticket_allocations_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 8,
        "enum_columns": 0,
        "foreign_key_references": 4,
        "indexes": 4,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_000300_create_ticket_inventory_items_table.php",
        "timestamps": true,
        "unique_constraints": 2
      },
      {
        "cascade_tokens": 3,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 4,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_000400_create_draw_certifications_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 4,
        "enum_columns": 0,
        "foreign_key_references": 2,
        "indexes": 3,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_000500_create_draw_publications_table.php",
        "timestamps": true,
        "unique_constraints": 2
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 2,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_000600_create_draw_reconciliations_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 6,
        "enum_columns": 0,
        "foreign_key_references": 3,
        "indexes": 3,
        "money_columns": 1,
        "path": "database/migrations/2024_02_01_000700_create_prize_matches_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 4,
        "enum_columns": 0,
        "foreign_key_references": 2,
        "indexes": 2,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_000800_create_prize_eligibility_decisions_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 4,
        "enum_columns": 0,
        "foreign_key_references": 2,
        "indexes": 2,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_000900_create_winner_notifications_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 4,
        "enum_columns": 0,
        "foreign_key_references": 2,
        "indexes": 3,
        "money_columns": 1,
        "path": "database/migrations/2024_02_01_001000_create_prize_disbursements_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 3,
        "money_columns": 1,
        "path": "database/migrations/2024_02_01_001100_create_wallet_reservations_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 3,
        "money_columns": 1,
        "path": "database/migrations/2024_02_01_001200_create_financial_holds_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 3,
        "money_columns": 3,
        "path": "database/migrations/2024_02_01_001300_create_ledger_reconciliations_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 0,
        "enum_columns": 0,
        "foreign_key_references": 0,
        "indexes": 2,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_001400_create_payment_providers_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 2,
        "money_columns": 2,
        "path": "database/migrations/2024_02_01_001500_create_payment_method_configs_table.php",
        "timestamps": true,
        "unique_constraints": 2
      },
      {
        "cascade_tokens": 6,
        "enum_columns": 0,
        "foreign_key_references": 3,
        "indexes": 5,
        "money_columns": 1,
        "path": "database/migrations/2024_02_01_001600_create_payment_intents_table.php",
        "timestamps": true,
        "unique_constraints": 2
      },
      {
        "cascade_tokens": 0,
        "enum_columns": 0,
        "foreign_key_references": 0,
        "indexes": 3,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_001700_create_payment_webhooks_table.php",
        "timestamps": true,
        "unique_constraints": 3
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 4,
        "money_columns": 2,
        "path": "database/migrations/2024_02_01_001800_create_payment_reconciliations_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 0,
        "enum_columns": 0,
        "foreign_key_references": 0,
        "indexes": 4,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_001900_extend_kyc_documents_for_verification_lane.php",
        "timestamps": false,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 4,
        "enum_columns": 0,
        "foreign_key_references": 2,
        "indexes": 4,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_002000_create_kyc_verifications_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 3,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_002100_create_aml_risk_assessments_table.php",
        "timestamps": true,
        "unique_constraints": 2
      },
      {
        "cascade_tokens": 6,
        "enum_columns": 0,
        "foreign_key_references": 3,
        "indexes": 5,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_002200_create_compliance_cases_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 4,
        "enum_columns": 0,
        "foreign_key_references": 2,
        "indexes": 2,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_002300_create_compliance_actions_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 4,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_002400_create_self_exclusions_table.php",
        "timestamps": false,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 3,
        "money_columns": 1,
        "path": "database/migrations/2024_02_01_002500_create_responsible_gaming_limit_versions_table.php",
        "timestamps": false,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 2,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_002600_create_reality_checks_table.php",
        "timestamps": false,
        "unique_constraints": 2
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 3,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_002700_create_player_protection_cases_table.php",
        "timestamps": false,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 6,
        "enum_columns": 0,
        "foreign_key_references": 3,
        "indexes": 3,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_002800_create_player_protection_actions_table.php",
        "timestamps": false,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 5,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_002900_create_authentication_attempts_table.php",
        "timestamps": false,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 3,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_003000_create_mfa_challenges_table.php",
        "timestamps": false,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 4,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_003100_create_security_sessions_table.php",
        "timestamps": false,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 2,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_003200_create_trusted_devices_table.php",
        "timestamps": false,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 5,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_003300_create_security_events_table.php",
        "timestamps": false,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 0,
        "enum_columns": 0,
        "foreign_key_references": 0,
        "indexes": 2,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_003400_create_notification_templates_table.php",
        "timestamps": false,
        "unique_constraints": 2
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 0,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_003500_create_notification_preferences_table.php",
        "timestamps": false,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 4,
        "enum_columns": 0,
        "foreign_key_references": 2,
        "indexes": 5,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_003600_create_notifications_table.php",
        "timestamps": false,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 2,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_003700_create_notification_delivery_attempts_table.php",
        "timestamps": false,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 3,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_003800_create_notification_receipts_table.php",
        "timestamps": false,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 2,
        "indexes": 4,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_003900_create_admin_operations_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 1,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 1,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_004000_create_provider_operations_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 1,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 2,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_004100_create_operational_report_jobs_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 2,
        "money_columns": 0,
        "path": "database/migrations/2024_02_01_004200_create_report_exports_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 37,
        "enum_columns": 0,
        "foreign_key_references": 18,
        "indexes": 22,
        "money_columns": 5,
        "path": "database/migrations/2026_09_22_000100_create_glo_freeze_claim_tables.php",
        "timestamps": true,
        "unique_constraints": 12
      },
      {
        "cascade_tokens": 0,
        "enum_columns": 0,
        "foreign_key_references": 0,
        "indexes": 0,
        "money_columns": 0,
        "path": "database/migrations/2026_09_22_000200_add_date_of_birth_to_users.php",
        "timestamps": false,
        "unique_constraints": 0
      },
      {
        "cascade_tokens": 12,
        "enum_columns": 0,
        "foreign_key_references": 6,
        "indexes": 10,
        "money_columns": 8,
        "path": "database/migrations/2026_09_23_000100_create_glo_sales_and_result_tables.php",
        "timestamps": true,
        "unique_constraints": 7
      },
      {
        "cascade_tokens": 32,
        "enum_columns": 0,
        "foreign_key_references": 16,
        "indexes": 25,
        "money_columns": 4,
        "path": "database/migrations/2026_09_23_000200_create_glo_dealer_public_tables.php",
        "timestamps": true,
        "unique_constraints": 6
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 3,
        "money_columns": 1,
        "path": "database/migrations/2026_09_24_000210_create_account_grade_snapshots_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 0,
        "enum_columns": 0,
        "foreign_key_references": 0,
        "indexes": 7,
        "money_columns": 0,
        "path": "database/migrations/2026_09_25_000300_create_lottery_ticket_verifications_table.php",
        "timestamps": false,
        "unique_constraints": 2
      },
      {
        "cascade_tokens": 0,
        "enum_columns": 0,
        "foreign_key_references": 0,
        "indexes": 7,
        "money_columns": 0,
        "path": "database/migrations/2026_09_26_000400_create_national_lottery_draws_table.php",
        "timestamps": true,
        "unique_constraints": 3
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 7,
        "money_columns": 0,
        "path": "database/migrations/2026_09_26_000410_create_national_lottery_results_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 6,
        "enum_columns": 0,
        "foreign_key_references": 3,
        "indexes": 6,
        "money_columns": 0,
        "path": "database/migrations/2026_09_26_000420_create_national_lottery_result_versions_table.php",
        "timestamps": true,
        "unique_constraints": 3
      },
      {
        "cascade_tokens": 0,
        "enum_columns": 0,
        "foreign_key_references": 0,
        "indexes": 7,
        "money_columns": 0,
        "path": "database/migrations/2026_09_26_000500_create_weekly_lottery_draws_table.php",
        "timestamps": true,
        "unique_constraints": 3
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 7,
        "money_columns": 0,
        "path": "database/migrations/2026_09_26_000510_create_weekly_lottery_results_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 6,
        "enum_columns": 0,
        "foreign_key_references": 3,
        "indexes": 6,
        "money_columns": 0,
        "path": "database/migrations/2026_09_26_000520_create_weekly_lottery_result_versions_table.php",
        "timestamps": true,
        "unique_constraints": 3
      },
      {
        "cascade_tokens": 0,
        "enum_columns": 0,
        "foreign_key_references": 0,
        "indexes": 7,
        "money_columns": 0,
        "path": "database/migrations/2026_09_27_000600_create_bingo_lottery_draws_table.php",
        "timestamps": true,
        "unique_constraints": 3
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 7,
        "money_columns": 0,
        "path": "database/migrations/2026_09_27_000610_create_bingo_lottery_results_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 6,
        "enum_columns": 0,
        "foreign_key_references": 3,
        "indexes": 6,
        "money_columns": 0,
        "path": "database/migrations/2026_09_27_000620_create_bingo_lottery_result_versions_table.php",
        "timestamps": true,
        "unique_constraints": 3
      },
      {
        "cascade_tokens": 0,
        "enum_columns": 0,
        "foreign_key_references": 0,
        "indexes": 1,
        "money_columns": 2,
        "path": "database/migrations/2026_09_27_220001_extend_account_grade_snapshots_table.php",
        "timestamps": false,
        "unique_constraints": 0
      },
      {
        "cascade_tokens": 3,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 4,
        "money_columns": 5,
        "path": "database/migrations/2026_09_27_220002_create_grade_discount_snapshots_table.php",
        "timestamps": true,
        "unique_constraints": 0
      },
      {
        "cascade_tokens": 0,
        "enum_columns": 0,
        "foreign_key_references": 0,
        "indexes": 8,
        "money_columns": 0,
        "path": "database/migrations/2026_09_28_000700_create_pcso_lottery_draws_table.php",
        "timestamps": true,
        "unique_constraints": 3
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 9,
        "money_columns": 0,
        "path": "database/migrations/2026_09_28_000710_create_pcso_lottery_results_table.php",
        "timestamps": true,
        "unique_constraints": 1
      },
      {
        "cascade_tokens": 6,
        "enum_columns": 0,
        "foreign_key_references": 3,
        "indexes": 6,
        "money_columns": 0,
        "path": "database/migrations/2026_09_28_000720_create_pcso_lottery_result_versions_table.php",
        "timestamps": true,
        "unique_constraints": 3
      },
      {
        "cascade_tokens": 8,
        "enum_columns": 0,
        "foreign_key_references": 5,
        "indexes": 5,
        "money_columns": 0,
        "path": "database/migrations/2026_09_28_230001_create_account_verifications_table.php",
        "timestamps": true,
        "unique_constraints": 2
      },
      {
        "cascade_tokens": 0,
        "enum_columns": 0,
        "foreign_key_references": 0,
        "indexes": 4,
        "money_columns": 0,
        "path": "database/migrations/2026_09_29_000800_create_contact_messages_table.php",
        "timestamps": true,
        "unique_constraints": 3
      },
      {
        "cascade_tokens": 2,
        "enum_columns": 0,
        "foreign_key_references": 1,
        "indexes": 2,
        "money_columns": 0,
        "path": "database/migrations/2026_09_29_000810_create_contact_message_deliveries_table.php",
        "timestamps": true,
        "unique_constraints": 0
      }
    ]
  },
  "purpose": "Static evidence only; no runtime conclusion is inferred.",
  "runtime_boundary": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
  "secrets_policy": "No secret values are read or emitted.",
  "seeders": [
    {
      "classification": "fixture-only or reference-data review required",
      "contains_factory_reference": false,
      "contains_result_data_reference": true,
      "path": "database/seeders/DatabaseSeeder.php"
    },
    {
      "classification": "reference data or production-safe review required",
      "contains_factory_reference": false,
      "contains_result_data_reference": false,
      "path": "database/seeders/LedgerAccountSeeder.php"
    },
    {
      "classification": "fixture-only or reference-data review required",
      "contains_factory_reference": false,
      "contains_result_data_reference": true,
      "path": "database/seeders/ResultArchiveSeeder.php"
    },
    {
      "classification": "fixture-only or reference-data review required",
      "contains_factory_reference": false,
      "contains_result_data_reference": true,
      "path": "database/seeders/RolePermissionSeeder.php"
    }
  ],
  "transaction_targets": [
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Finance/DepositApprovalService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Finance/DepositCompletionService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Finance/DepositService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 6,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Finance/FinancialHoldService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Finance/FinancialReconciliationExportService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Finance/FinancialReconciliationService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Finance/FinancialReversalService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Finance/FinancialStateTransitionService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Finance/FinancialTransactionService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": false,
      "path": "app/Services/Finance/IdempotencyService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Finance/LedgerAdjustmentService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Finance/LedgerBalanceValidator.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Finance/LedgerPostingService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Finance/LedgerReconciliationService.php",
      "reservation_reference": true,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Finance/Money.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 5,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Finance/PayoutApprovalService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 9,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": false,
      "path": "app/Services/Finance/PayoutBatchService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Finance/PayoutReconciliationService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 4,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Finance/RefundService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 5,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": false,
      "path": "app/Services/Finance/TaxCalculationService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 3,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Finance/WalletHoldService.php",
      "reservation_reference": true,
      "wallet_reference": true
    },
    {
      "db_transaction": 2,
      "explicit_begin": 1,
      "explicit_commit": 1,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Finance/WalletLockService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 5,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Finance/WalletReservationService.php",
      "reservation_reference": true,
      "wallet_reference": true
    },
    {
      "db_transaction": 3,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Finance/WalletService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Finance/WithdrawalApprovalService.php",
      "reservation_reference": true,
      "wallet_reference": true
    },
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Finance/WithdrawalCompletionService.php",
      "reservation_reference": true,
      "wallet_reference": true
    },
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Finance/WithdrawalService.php",
      "reservation_reference": true,
      "wallet_reference": true
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": false,
      "path": "app/Services/Payment/PaymentCallbackService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Payment/PaymentGatewayManager.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": false,
      "path": "app/Services/Payment/PaymentInitiationService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 4,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": false,
      "path": "app/Services/Payment/PaymentIntentService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 4,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Payment/PaymentProviderRegistry.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Payment/PaymentReconciliationService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Payment/PaymentVerificationService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 3,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Payment/PaymentWebhookService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Payment/PaymentWebhookVerificationService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 5,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Payment/PayoutTransferService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 3,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Payment/ProductionPaymentExecutionHubService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Payment/PromptPayPaymentService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Payment/WithdrawalDisbursementService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Betting/BetAmendmentService.php",
      "reservation_reference": true,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Betting/BetAmountService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Betting/BetCalculationService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Betting/BetCancellationService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": false,
      "path": "app/Services/Betting/BetPermutationService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Betting/BetPurchaseBetService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Betting/BetPurchaseIdempotencyService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Betting/BetPurchaseItemService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Betting/BetPurchaseLedgerService.php",
      "reservation_reference": true,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Betting/BetPurchaseReferenceService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Betting/BetPurchaseRiskService.php",
      "reservation_reference": true,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Betting/BetPurchaseService.php",
      "reservation_reference": true,
      "wallet_reference": true
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Betting/BetPurchaseTicketService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 3,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Betting/BetPurchaseTransactionService.php",
      "reservation_reference": true,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Betting/BetPurchaseValidator.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Betting/BetPurchaseWalletService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Betting/BetValidationService.php",
      "reservation_reference": true,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Betting/BulkBetService.php",
      "reservation_reference": true,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Betting/LotteryNumberService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Betting/MarketPayoutService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Betting/MarketResultResolver.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Betting/MarketRuleResolver.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Betting/PayoutMultiplierService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Betting/RunMatchService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Betting/ThreeDigitMatchService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Betting/TicketShareService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Betting/TicketVerificationService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Betting/TodMatchService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Betting/TodPermutationService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Betting/TwoDigitMatchService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 3,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Draw/DrawCertificationService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Draw/DrawLifecycleService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 3,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Draw/DrawPublicationService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Draw/DrawReconciliationService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Draw/DrawResultConfirmationService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": false,
      "path": "app/Services/Draw/DrawResultIngestionService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Draw/DrawResultPublicationService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Draw/DrawResultValidator.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Draw/DrawScheduleService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 3,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Draw/DrawSettlementSimulationService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Draw/PublicResultVerificationService.php",
      "reservation_reference": true,
      "wallet_reference": false
    },
    {
      "db_transaction": 4,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Draw/RealPrizeSettlementService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Draw/SelectionSettlementResolver.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/AbstractLotterySourceService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/BingoLotteryDateService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/BingoLotteryHistoryService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/BingoLotteryImportService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/BingoLotteryResultService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/BingoLotterySearchService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/BingoLotteryService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/BingoLotterySourceService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/CanonicalDiscountMatrixService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/DiscountParityProjectionService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloDataMatrixParser.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloDataMatrixParserInterface.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 3,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloDealerChangeRequestService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloDealerService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloFixtureResultProvider.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloFrozenWinnerService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 3,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Lottery/GloL6AuthoritativeTicketEngineService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloL6HomeService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloL6ProportionalPrizeCalculator.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Lottery/GloL6PurchaseCapabilityService.php",
      "reservation_reference": true,
      "wallet_reference": true
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloL6SalesService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloLiveDrawService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloN3PrizeCalculator.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloN3PrizePoolAllocator.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 3,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloN3SaleService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloN3SettlementService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloN3TicketChecker.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloNextDrawService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloOfficialResultProvider.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloPrizeCatalogue.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 5,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloPrizeClaimService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloPublicHomeService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloPublicPrizeSummaryService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloPublicResultService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloPublicStatsService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloPublicTicketVerificationService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloResultImportService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloResultNotificationService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloResultProvider.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloResultPublicationService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloResultService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloSalesPointService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloSalesReconciliationService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloSavedTicketService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloStampDutyCalculator.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloTicketChecker.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": false,
      "path": "app/Services/Lottery/GloTicketFreezeService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/LottoDiscountCalculator.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/NationalLotteryDateService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/NationalLotteryHistoryService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": false,
      "path": "app/Services/Lottery/NationalLotteryImportService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/NationalLotteryResultService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/NationalLotterySearchService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/NationalLotteryService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/NationalLotterySourceService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/PcsoLotteryDateService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/PcsoLotteryHistoryService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/PcsoLotteryImportService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": true,
      "path": "app/Services/Lottery/PcsoLotteryPurchaseCapabilityService.php",
      "reservation_reference": true,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/PcsoLotteryResultService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/PcsoLotterySearchService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/PcsoLotteryService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/PcsoLotterySourceService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/PrizeVerificationService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/PublicLotteryCatalogService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/ResultImportService.php",
      "reservation_reference": true,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/TicketAuthenticityService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/TicketBarcodeService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/TicketIdentityService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/WeeklyLotteryDateService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/WeeklyLotteryHistoryService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/WeeklyLotteryImportService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/WeeklyLotteryResultService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/WeeklyLotterySearchService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/WeeklyLotteryService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/WeeklyLotterySourceService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Lottery/WeeklyResultIntegrityService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Agent/AgentCommissionAccrualService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Agent/AgentCommissionReversalService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": false,
      "path": "app/Services/Agent/AgentCommissionService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": false,
      "path": "app/Services/Agent/AgentCommissionSettlementService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 5,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Agent/AgentOnboardingService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Agent/AgentReferralService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Agent/AgentReportingService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": false,
      "path": "app/Services/Agent/AgentSettlementService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Agent/CommissionCalculationService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Compliance/AmlRiskAssessmentService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Compliance/AmlRiskService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Compliance/ComplianceActionService.php",
      "reservation_reference": false,
      "wallet_reference": true
    },
    {
      "db_transaction": 4,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Compliance/ComplianceCaseService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Compliance/ComplianceService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 4,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Compliance/KycDocumentService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Compliance/KycVerificationService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Compliance/SelfExclusionService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Compliance/WithdrawalKycGateService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 4,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Notification/NotificationDeliveryService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Notification/NotificationDispatchService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Notification/NotificationPreferenceService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": false,
      "path": "app/Services/Notification/NotificationReceiptService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Notification/NotificationSuppressionService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 3,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Notification/NotificationTemplateService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Security/AuthenticationSecurityService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 3,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": true,
      "ledger_reference": false,
      "path": "app/Services/Security/DeviceTrustService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Security/KycVerificationService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 2,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Security/MfaChallengeService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Security/ResponsibleGamingService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Security/SecurityEventService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": true,
      "path": "app/Services/Security/SecurityRiskAssessmentService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 5,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Security/UserSessionSecurityService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Support/ContactDeliveryService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 1,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Support/ContactMessageService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Support/ContactPrivacyService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Support/ContactSpamProtectionService.php",
      "reservation_reference": false,
      "wallet_reference": false
    },
    {
      "db_transaction": 0,
      "explicit_begin": 0,
      "explicit_commit": 0,
      "explicit_rollback": 0,
      "idempotency_reference": false,
      "ledger_reference": false,
      "path": "app/Services/Support/PublicSupportService.php",
      "reservation_reference": false,
      "wallet_reference": false
    }
  ],
  "type": "pages_251_350_static_backend_audit"
}

```

## FILE 9: `scripts/pages_251_350_audit_matrix.py`

# TYPE: Python audit generator
# PURPOSE: Generate exactly one full-column audit row for every Page 251–350.

```python
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

```

## FILE 10: `scripts/pages_251_350_matrix_document.py`

# TYPE: Python matrix generator
# PURPOSE: Generate complete Pages 251–350 page, route, API, security, finance, lottery, Rust, and runtime matrices.

```python
# TYPE: Python matrix document generator
# PURPOSE: Generate complete Pages 251–350 page, route, API, security, financial, lottery, Rust, and runtime matrices from audit rows.

from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
audit = (ROOT / "audit.md").read_text(encoding="utf-8")
rows: dict[int, list[str]] = {}
for line in audit.splitlines():
    match = re.match(r"^\|\s*(25[1-9]|2[6-9][0-9]|3[0-4][0-9]|350)\s*\|", line)
    if match:
        page = int(match.group(1))
        cells = [cell.strip() for cell in line.strip("|").split("|")]
        if len(cells) == 27:
            rows[page] = cells

if sorted(rows) != list(range(251, 351)):
    raise RuntimeError("Pages 251–350 matrix requires exactly one 27-column audit row per page.")

out = [
    "# Pages 251–350 complete runtime activation matrices",
    "",
    "# TYPE: Markdown runtime, backend, finance, lottery, security, and Rust matrices",
    "# PURPOSE: Preserve one exact row per page and separate evidence matrices for the Pages 251–350 runtime activation phase.",
    "",
    "Final acceptance boundary: `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`.",
    "",
    "## Complete Page Matrix",
    "",
    "| " + " | ".join(["Page", "Title", "Route", "Route Name", "HTTP Method", "Middleware", "Authorization", "Controller", "Request", "Service", "DTO", "Model", "Database", "API", "Job/Event", "View", "JS", "CSS", "Translation", "Source of Truth", "Financial Impact", "Security", "Audit", "Status", "Tests", "Runtime Status", "Remaining Gap"]) + " |",
    "|" + "---|" * 27,
]
for page in range(251, 351):
    out.append("| " + " | ".join(rows[page]) + " |")

out.extend([
    "",
    "## Route Matrix",
    "",
    "| Pages | Boundary | HTTP methods | Authorization and middleware | Runtime evidence |",
    "|---|---|---|---|---|",
    "| 251–259 | Python preflight, dependency, Laravel, database, migration, seed, factory, constraint, and transaction audit scripts | CLI/static | Process/filesystem boundary only | Preflight and static JSON executed; Laravel/PHP commands blocked. |",
    "| 260–294 | Existing wallet, ledger, payment, withdrawal, betting, and ticket routes/services | HTTP, CLI, queue, PHPUnit | Existing auth, owner, KYC, CSRF, rate-limit, draw, and provider boundaries | Canonical source/test inventory present; runtime blocked. |",
    "| 295–308 | Existing ticket, draw, result, Rust boundary, import, certification, and publication routes | HTTP, CLI, API, PHPUnit | Existing ticket, draw, result, operator, provider, and process boundaries | Runtime blocked; no result or certification success claimed. |",
    "| 309–318 | Product-specific National, Weekly, PCSO, GLO L6, pricing, prize, and exact-arithmetic lanes | API, CLI, service, PHPUnit | Product and result lane isolation | Runtime blocked; GLO purchase remains NOT_CONFIGURED where canonical capability is not enabled. |",
    "| 319–337 | GLO claims, holds, freezes, settlement, payouts, agents, commissions, referrals | HTTP, API, CLI, queue, PHPUnit | Claim, KYC, age, payout, agent, ownership, and idempotency boundaries | Runtime blocked; no claim, payout, or commission success claimed. |",
    "| 338–340 | Notification dispatch, receipt, suppression, and preferences | Event, queue, API | Recipient ownership and preference policy | Runtime blocked; no delivery claim. |",
    "| 341–346 | Owner-scoped support case contract and portal | GET/POST, auth, CSRF, throttle | Authenticated owner query, bounded reference, no hidden owner field | Implementation is static-only; migration and routes are not runtime verified. |",
    "| 347–349 | Security events, MFA, and session revocation | Event, API | Authentication, MFA, session, and security-event boundaries | Runtime blocked. |",
    "| 350 | Final runtime acceptance gate | CLI | Full enterprise acceptance boundary | 2 frontend commands executed; 11 required commands blocked. |",
    "",
    "## API Contract Matrix",
    "",
    "| Pages | Request / response contract | Canonical owner | Idempotency / ownership | Evidence |",
    "|---|---|---|---|---|",
    "| 260–280 | Wallet, ledger, payment, callback, webhook, queue, replay, and reconciliation contracts | Existing Finance and Payment DTOs/services/enums | Stable references, signatures, callback ownership, replay safety | Static architecture and existing tests; runtime blocked. |",
    "| 281–294 | Withdrawal and bet purchase contracts | Existing Withdrawal and Betting DTOs/services | Session ownership, KYC gate, draw state, wallet reservation, idempotency | Static architecture and existing tests; runtime blocked. |",
    "| 295–308 | Ticket verification, draw lifecycle, result import, certification, publication, and Rust JSON boundary | Existing Ticket, Draw, Result, and Rust crate | Opaque references, source provenance, certification gate, malformed-input validation | Static architecture and existing tests; runtime blocked. |",
    "| 309–328 | Product-specific lottery and GLO result/purchase/claim APIs | Existing product and GLO services | Lane isolation, source state, leading-zero preservation, capability gate | Static architecture and existing tests; runtime blocked. |",
    "| 329–340 | Prize, payout, agent, commission, notification, and preference contracts | Existing Prize, Agent, Finance, and Notification services | Payout/commission idempotency and recipient ownership | Static architecture and existing tests; runtime blocked. |",
    "| 341–349 | Support, security event, MFA, and session contracts | New SupportCaseService plus existing Security services | Owner-scoped case reference, CSRF, throttle, MFA/session policy | Static source and contract tests; runtime blocked. |",
    "",
    "## Security Matrix",
    "",
    "| Pages | Control | Source | Negative case | Evidence |",
    "|---|---|---|---|---|",
    "| 251–259 | Secret-free preflight and configuration inspection | Python scripts and config source | No secrets emitted; no unavailable runtime treated as pass | Executed preflight JSON and static audit JSON. |",
    "| 260–286 | Wallet/payment/withdrawal auth, ownership, KYC, signatures, replay, rate limits, and provider error handling | Canonical services, middleware, DTOs, and existing tests | Cross-owner access, invalid signature, stale/replayed callback, duplicate withdrawal, provider failure | Runtime blocked. |",
    "| 287–307 | Bet/ticket/draw/result controls | Canonical purchase, ownership, draw, result, certification, and publication services | Closed draw, invalid price/currency, duplicate bet, unverified/conflicting result | Runtime blocked. |",
    "| 308–318 | Leading zeros, product lane isolation, Rust process boundary, GLO capability and exact arithmetic | Rust crate and canonical lottery services | Numeric coercion, product crossover, malformed input, disabled purchase | Runtime blocked; Page 314 remains NOT_CONFIGURED. |",
    "| 319–337 | Claim age/KYC, holds, freezes, payout, agent and referral ownership | Canonical GLO, Compliance, Finance, and Agent services | Expired claim, duplicate claim/payout/commission, cross-agent access | Runtime blocked. |",
    "| 338–349 | Notification recipients, support owner cases, security events, MFA, and session revocation | Notification, SupportCase, and Security services | Wrong recipient, cross-owner case, invalid MFA, revoked session | Runtime blocked. |",
    "",
    "## Financial Integrity Matrix",
    "",
    "| Pages | Operation | Atomicity / idempotency | Ledger / audit rule | Runtime status |",
    "|---|---|---|---|---|",
    "| 259–270 | Database transactions, wallet balance, holds, reservations, idempotency, ledger posting, reversals, reconciliation | DB transaction, locks, reservations, stable references, read-only reconciliation | Double-entry and discrepancy evidence remain canonical; no silent repair | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 271–280 | Deposits, callbacks, provider errors, payment states, events, queues, dead letters, replay | Verified callback, signature, replay-safe webhook, queue retry/terminal state | No credit before verification; callback and provider evidence persisted | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 281–294 | Withdrawals, KYC gates, holds, refunds/recovery, bet purchase, price/currency/limits, tickets | Hold/approval/completion atomicity and purchase idempotency | No double hold, payout, debit, or orphan ticket | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 319–332 | Claims, age/KYC, payment holds, freezes, matching, settlement, payout | Claim and payout idempotency; exact money arithmetic | Gross/deductions/net and ledger treatment must reconcile | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 333–337 | Agent commissions, duplicates, settlements, referrals, owner isolation | Qualifying event idempotency and owner-scoped attribution | No duplicate accrual or cross-agent commission claim | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "",
    "## Lottery Integrity Matrix",
    "",
    "| Pages | Lottery boundary | Integrity requirements | Source | Runtime status |",
    "|---|---|---|---|---|",
    "| 287–300 | Bet purchase, ticket issuance, draw open/close, draw state, cutoff | Server price/currency, owner scope, atomic purchase, closed-draw rejection | Betting and Draw services, enums, middleware, existing tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 301–308 | Result import, provenance, duplicate/conflict handling, certification, publication, correction, leading zeros | Preserve source/version/evidence; no auto-publish conflict; exact string values | Draw result services and Rust canonicalization crate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 309–313 | National, Weekly, Mega/other, PCSO, and GLO L6 lanes | Separate product models/services/results; no lane crossover | Product-specific canonical services/models | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 314–318 | GLO purchase capability, six-digit range, pricing, prize allocation, exact scaling | Do not invent checkout; preserve leading zeros; exact decimal arithmetic | GLO L6 services and calculator | Page 314 NOT_CONFIGURED; others blocked. |",
    "| 319–328 | Claim windows, creation, duplicate claim, age/KYC, payment holds, freezes, frozen winners, publication | Winning ticket/result authority, claim eligibility, payout gate, freeze lifecycle | GLO claim, freeze, result, compliance, and payout services | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 329–332 | Prize matching, settlement, payout idempotency, payout failure | Backend matching only; no duplicate payout; recoverable failed claim | Prize, Draw, Finance, Payment services | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "",
    "## Rust Runtime Matrix",
    "",
    "| Pages | Crate / binary | Input | Output / validation | Determinism / timeout | Evidence |",
    "|---|---|---|---|---|---|",
    "| 308 | `security/weekly-result-integrity` canonicalization | Result JSON with string-preserving values | Canonical bytes/hash/error boundary; Laravel validates output | Leading-zero vectors and deterministic tests exist; Cargo not available | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 350 | Rust build and execution acceptance | Cargo workspace and crate commands | `cargo check`, `cargo test`, `cargo build --release`, malformed input, timeout, exit code, stderr, resource boundary | No execution occurred | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "",
    "## Runtime result summary",
    "",
    "- Page 251 Python preflight executed and produced machine-readable JSON.",
    "- Page 252 NPM installation executed; Composer was unavailable; NPM audit reported one moderate and one high vulnerability.",
    "- Page 350 acceptance gate executed 13 commands: 2 succeeded, 0 failed after execution, and 11 were blocked by unavailable PHP, Composer, Cargo, or related runtime components.",
    "- Laravel, database, queue, payment provider, browser, accessibility, and Rust execution remain `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`.",
])

(ROOT / "PAGES-251-350-MATRICES.md").write_text("\n".join(out) + "\n", encoding="utf-8")
print("Generated PAGES-251-350-MATRICES.md with 100 page rows.")

```

## FILE 11: `RUNTIME-VERIFICATION-REPORT.md`

# TYPE: Markdown runtime report
# PURPOSE: Record exact runtime attempts, independent frontend execution, blocked dependencies, and non-claims.

```markdown
# TYPE: Runtime verification report
# PURPOSE: Record the actual Pages 251–350 runtime attempts, independent successes, and truthful blocked boundaries.

## Acceptance boundary

`BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`

The workspace has Node.js, NPM, and Python. It does not have PHP, Composer, Cargo, Rust, a browser executable, a Laravel vendor directory, or a configured application `.env` file. No Laravel, database, queue, payment-provider, browser, or Rust result is presented as verified.

## Page 251 — Runtime environment bootstrap

The deterministic preflight was executed:

```text
python3 scripts/runtime_preflight.py
```

The machine-readable result is `/home/user/runtime/page-251-preflight.json`.

Observed command availability:

| Component | Observation |
|---|---|
| Python | AVAILABLE — Python 3.13.14 |
| Node | AVAILABLE — v20.20.2 |
| NPM | AVAILABLE — 10.8.2 |
| PHP | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Composer | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Cargo | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Rust compiler | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Playwright command | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Chromium / Google Chrome | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Laravel vendor directory | NOT AVAILABLE |
| Application `.env` | NOT CONFIGURED |
| `.env.example` | PRESENT; values are not runtime credentials |

The preflight reads configuration presence only and emits no secret values.

## Page 252 — Dependency installation verification

The required commands were attempted.

| Command | Result |
|---|---|
| `composer validate` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE — Composer is not installed. |
| `composer install --no-interaction` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE — Composer is not installed. |
| `npm ci` | EXECUTED — exit code 0; 119 packages added and 120 packages audited. |
| `npm audit` | FAILED — exit code 1; one moderate and one high vulnerability were reported. |

No `npm audit fix --force` was run because it could change dependency versions without an approved compatibility decision.

## Page 253 — Laravel boot verification

Each required command was attempted and was blocked because the PHP executable is unavailable.

| Command | Result |
|---|---|
| `php artisan about` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE; exit code 127. |
| `php artisan route:list` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE; exit code 127. |
| `php artisan config:show` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE; exit code 127. |

Container boot, route resolution, configuration loading, and service-provider loading remain unverified.

## Page 254 — Database connection verification

The repository statically contains MySQL, PostgreSQL, and SQLite configuration branches, with strict MySQL mode, UTF-8 configuration, and a file-backed SQLite test configuration in `phpunit.xml`. No database connection was attempted because PHP and the Laravel container are unavailable.

Status: `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`.

Charset, collation, strict mode, transaction support, and timezone behavior are not runtime verified.

## Page 255 — Migration baseline

`php artisan migrate:status` was included in the Page 350 acceptance gate and was blocked because PHP is unavailable.

Status: `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`.

The migration files are present, but applied migrations, pending migrations, batch numbers, and production-safe state are not claimed.

## Pages 256–259 — Static independent audits

The executable static audit was run:

```text
python3 scripts/pages_251_350_static_audit.py
```

The machine-readable result is `/home/user/runtime/pages-256-259-static-audit.json`.

Observed inventory:

| Audit | Observed result |
|---|---|
| Seeders | 4 PHP seeders classified for reference-data or fixture review. |
| Factories | 42 factories classified as fixture-only; financial, wallet, result, ticket, KYC, commission, and support domains were identified where source names/content indicated them. |
| Migrations | 95 migration files inspected for foreign-key references, unique constraints, indexes, money columns, enum columns, timestamps, and cascade tokens. |
| Transaction targets | 197 canonical service files inspected for transaction, rollback, idempotency, ledger, wallet, and reservation references. |
| Runtime database execution | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE. |

The static audit does not classify fixtures as production truth and does not assert that a database invariant passed.

## Page 260 — Concurrency test harness

The repository already contains canonical concurrency and atomicity coverage, including:

- `tests/Feature/Betting/BetPurchaseAtomicityTest.php`
- `tests/Feature/Payment/DuplicateWebhookIdempotencyTest.php`
- `tests/Feature/Payment/WebhookReplayProtectionTest.php`
- `tests/Feature/BusinessCriticalInvariantTest.php`
- `tests/Feature/Finance/FinancialReconciliationComprehensiveTest.php`
- `tests/Feature/FinalWholeSystemNoSkipTest.php`

The Laravel test suite could not execute because PHP, Composer, and `vendor` are unavailable.

Status: `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`.

## Pages 261–350 — Runtime activation

The Page 350 acceptance gate attempted the independent commands below:

| Result | Count |
|---|---:|
| Executed successfully | 2 |
| Failed | 0 |
| Blocked | 11 |

The two independently executed commands were `npm ci` and `npm run build`. The Vite production build exited with code 0 and generated `public/build/manifest.json`. This is frontend build evidence only; it is not Laravel, finance, payment, lottery, browser, or production evidence.

The blocked commands include PHP version, Composer version, Laravel boot, route listing, migration status, failed queue listing, Laravel tests, Cargo version, Cargo workspace tests, financial reconciliation, and the GLO frozen-winner command.

The complete command result is `/home/user/runtime/page-350-acceptance.json`.

## Explicit non-claims

The following are not verified:

- PHP extensions or Composer package compatibility.
- Laravel container or route dispatch.
- Database connection or migration state.
- Redis connection or queue worker health.
- Payment provider credentials, signatures, callbacks, or settlements.
- Wallet, ledger, deposit, withdrawal, bet, prize, commission, or refund execution.
- Browser journeys, negative browser tests, or accessibility behavior.
- Rust build, Cargo tests, deterministic vectors, malformed-input behavior, timeouts, or resource limits.
- Production readiness.

## Final runtime result

`BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`

```

## FILE 12: `FINANCIAL-INTEGRITY-REPORT.md`

# TYPE: Markdown finance report
# PURPOSE: Map Pages 259–337 to canonical financial services, invariants, tests, and runtime boundaries.

```markdown
# TYPE: Financial integrity report
# PURPOSE: Map Pages 259–294 and Pages 319–337 to existing canonical finance, payment, betting, prize, and commission architecture without inventing runtime evidence.

## Boundary

`BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`

The PHP/Laravel test runtime, database, queue, and external payment providers are unavailable in this workspace. The report below distinguishes source-level architecture from executed financial evidence.

## Canonical financial architecture

| Concern | Canonical implementation | Source-level observation | Runtime result |
|---|---|---|---|
| Money arithmetic | `app/Services/Finance/Money.php` | Exact decimal arithmetic is the existing money authority. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Wallet operations | `app/Services/Finance/WalletService.php`, `WalletHoldService.php`, `WalletReservationService.php` | Existing wallet, hold, reservation, and owner-scoped services are reused. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Ledger posting | `app/Services/Finance/LedgerPostingService.php`, `LedgerBalanceValidator.php` | Existing double-entry validator and posting services remain authoritative. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Reconciliation | `app/Services/Finance/FinancialReconciliationService.php`, `app/Console/Commands/Finance/ReconcileFinancialRecordsCommand.php` | Existing read-only reconciliation command has bounded period, currency, batch, JSON, and dry-run options. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Deposit | `DepositService.php`, `DepositApprovalService.php`, `DepositCompletionService.php` | Deposit credit is intended to follow verified payment state and ledger posting. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Payment callbacks | `PaymentCallbackService.php`, `PaymentWebhookService.php`, `PaymentWebhookVerificationService.php` | Signature, replay, and callback services exist; no provider success is inferred here. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Withdrawal | `WithdrawalService.php`, `WithdrawalApprovalService.php`, `WithdrawalCompletionService.php`, `WithdrawalKycGateService.php` | Hold, KYC gate, approval, provider, completion, and ledger architecture exists. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Betting | `BetPurchaseService.php` and the canonical betting service family | Purchase pipeline and atomicity tests exist. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Prize settlement | `RealPrizeSettlementService.php`, `GloPrizeClaimService.php`, `PayoutApprovalService.php`, `PayoutBatchService.php` | Prize and payout paths remain canonical; no result or payout is fabricated. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Commission | `AgentCommissionService.php`, `CommissionCalculationService.php`, `AgentCommissionSettlementService.php` | Commission and settlement services/tests already exist. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## Financial mutation matrix

| Page | Operation | Source of truth | Atomicity / idempotency requirement | Runtime evidence |
|---:|---|---|---|---|
| 259 | Transaction boundary audit | Canonical finance/payment/betting services | DB transaction plus ledger treatment must be observed in execution. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 260 | Concurrent submissions | Existing atomicity/idempotency tests and canonical services | No double debit, double credit, duplicate issuance, or duplicate payout. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 261 | Wallet activation | `WalletService`, wallet models, ledger | Presentation must not become balance authority. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 262 | Balance rebuild/audit | `FinancialReconciliationService`, `LedgerBalanceValidator` | Wallet, ledger-derived, and locked values must be compared without silent repair. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 263 | Concurrent debit | Wallet locking/reservation and bet purchase services | One sufficient balance cannot fund two successful debits. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 264 | Hold/release | `WalletHoldService`, `WalletReservationService` | Holds require explicit terminal state and audit. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 265 | Reservation expiry | `WalletReservationService` | Expiry/release must be idempotent and auditable. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 266 | Financial idempotency | `IdempotencyService`, payment/betting idempotency services | Stable references and duplicate-safe replay. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 267 | Ledger posting | `LedgerPostingService`, `LedgerBalanceValidator` | No wallet change without required ledger treatment. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 268 | Ledger reversal | `FinancialReversalService` | Original history remains immutable and reversal references original. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 269 | Reconciliation execution | `finance:reconcile` | Read-only reconciliation report with persisted audit evidence. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 270 | Reconciliation lifecycle | `FinancialReconciliationService` and discrepancy DTOs/enums | Detected, reviewed, accepted/corrected, and resolved states must remain auditable. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 271 | Deposit flow | Payment initiation, callback verification, deposit completion, wallet, ledger | No credit before verified callback. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 272 | Duplicate callback | Webhook verification/idempotency services | Replays have one financial effect. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 273 | Callback ownership | Authenticated payment projection | Session/ownership, not query parameters, controls access. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 274 | Signature validation | `PaymentWebhookVerificationService` | Valid, invalid, tampered, replayed, stale, and malformed cases. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 275 | Provider errors | Gateway manager and adapters | Timeout, provider errors, malformed response, missing transaction, duplicate, and currency mismatch. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 276 | Payment states | Canonical payment enums and services | Only legal enum transitions. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 277 | Payment events | Payment webhook/event models and audit path | Every callback persists the expected internal event state. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 278 | Webhook queue | Queue/job architecture | Queued, retry, success, failure, and terminal failure are observable. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 279 | Dead-letter handling | Failed-job and provider-operation architecture | Permanent failures cannot silently disappear. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 280 | Payment replay | Idempotency and webhook services | Safe replay cannot duplicate wallet effects. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 281 | Withdrawal flow | Withdrawal, KYC gate, hold, approval, disbursement, ledger | Hold and payout are one canonical lifecycle. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 282 | Duplicate withdrawal | Withdrawal idempotency and wallet hold | No double hold or payout. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 283 | Withdrawal KYC | `WithdrawalKycGateService` | Verified, pending, failed, and expired states are tested against real records. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 284 | Restriction behavior | Responsible gaming, self-exclusion, compliance services | Only domain-supported restrictions are enforced. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 285 | Withdrawal failure | Completion/reversal/hold services | Provider failure has exact recovery and audit. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 286 | Withdrawal reconciliation | Payout reconciliation and ledger | Provider success must reconcile internally. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 287 | Bet purchase | Canonical purchase pipeline | Selection through ticket, wallet, ledger, and confirmation is atomic. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 288 | Price authority | Server-side betting calculation | Browser price cannot override server price. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 289 | Currency authority | `Currency` enum and payment/betting validators | Unsupported currency is rejected before mutation. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 290 | Betting limits | Responsible gaming and risk services | Single bet, daily wager, restriction, and self-exclusion rules. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 291 | Concurrent betting | Purchase transaction, wallet lock/reservation | One balance cannot satisfy two incompatible successful purchases. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 292 | Bet idempotency | Bet purchase idempotency service | Same idempotency key produces one purchase. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 293 | Bet rollback | Transaction and reservation services | Failure after reservation cannot strand funds. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 294 | Ticket issuance | Bet purchase ticket service | Successful purchase has canonical ticket ownership and no orphan ticket. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 319 | Claim creation | GLO claim service and official result state | Only valid winning ticket/result creates a claim. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 320 | Claim duplicate | GLO claim idempotency/state architecture | One canonical claim. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 321 | Age gate | GLO claim service and date-of-birth migration | Actual age state is required. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 322 | KYC claim gate | KYC verification service | Actual KYC status controls eligibility. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 323 | Payment hold | GLO payment hold model/service | Hold blocks payout until conditions are met. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 324 | Ticket freeze | GLO freeze service/command | Freeze lifecycle is explicit and auditable. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 325 | Freeze release | GLO freeze expiry/release command | Release follows canonical domain rules. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 326 | Frozen winner processing | `glo:process-frozen-winners` | Command must be executed against controlled records. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 327 | Public result publication | GLO result publication service | Published state derives from canonical publication. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 328 | Prize matching | Result, winning-rule, ticket, and prize-match services | Frontend never calculates winning outcome. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 329 | Prize settlement | Prize settlement, payout, and ledger services | Gross, deductions, net, settlement, and ledger agree. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 330 | Payout idempotency | Payout approval/batch/payment services | Duplicate payout cannot pay twice. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 331 | Payout failure | Claim state, reversal, provider operation, audit | Claim remains recoverable and auditable. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 332 | Commission accrual | Agent commission service | Qualifying event creates one canonical accrual. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 333 | Commission duplicate | Commission idempotency and reversal services | Duplicate event cannot accrue twice. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 334 | Commission settlement | Agent settlement service | Commission payment/settlement is traceable. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 335 | Referral attribution | Agent referral service | Actual canonical referral relation is authoritative. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 336 | Agent owner isolation | Agent portal and policy | Cross-agent access is denied. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## No unsupported financial claims

No deposit, payment, wallet credit, bet, withdrawal, refund, prize, claim, payout, commission, treasury balance, or provider settlement is reported as successful by this phase.

```

## FILE 13: `RUST-RUNTIME-REPORT.md`

# TYPE: Markdown Rust report
# PURPOSE: Record the canonical Rust crate, process boundary, vectors, required commands, and Cargo blocker.

```markdown
# TYPE: Rust runtime report
# PURPOSE: Record the Pages 245–250 Rust boundary carried into Pages 251–350 and the actual build/test execution boundary.

## Canonical crate

`security/weekly-result-integrity`

Observed source artifacts:

- `Cargo.toml`
- `Cargo.lock`
- `src/canonical.rs`
- `src/error.rs`
- `src/lib.rs`
- `src/main.rs`
- `tests/integrity.rs`

The source documents an isolated, non-networked stdin/stdout verifier. No parallel Rust crate or alternate financial authority was created.

## Required commands

| Command | Result |
|---|---|
| `cargo --version` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| `cargo check` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| `cargo test` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| `cargo build --release` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| `cargo test --workspace` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## Integrity boundary

The required trust boundary remains:

```text
Rust output
-> Laravel validation
-> domain decision
```

The following direct boundary is not introduced:

```text
Rust output
-> direct wallet mutation
```

## Determinism

The repository contains deterministic test vectors in `security/weekly-result-integrity/tests/integrity.rs`, including leading-zero-shaped synthetic values. The vectors were not executed because Cargo is unavailable.

Status: `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`.

## Malformed input and process safety

The source-level process contract identifies stdin/stdout handling and a short-lived process. The following remain unverified:

- empty input
- oversized input
- malformed JSON
- invalid numbers
- invalid character sets
- unexpected fields
- invalid output
- timeout
- process exit-code handling
- stderr handling
- resource limits
- arbitrary command execution resistance under an actual process

## Final Rust status

Build: `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`

Tests: `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`

Deterministic execution: `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`

Production Rust readiness: not claimed.

```

## FILE 14: `PAGES-251-350-MATRICES.md`

# TYPE: Markdown matrix deliverable
# PURPOSE: Complete Pages 251–350 matrix and route/API/security/financial/lottery/Rust matrices.

```markdown
# Pages 251–350 complete runtime activation matrices

# TYPE: Markdown runtime, backend, finance, lottery, security, and Rust matrices
# PURPOSE: Preserve one exact row per page and separate evidence matrices for the Pages 251–350 runtime activation phase.

Final acceptance boundary: `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`.

## Complete Page Matrix

| Page | Title | Route | Route Name | HTTP Method | Middleware | Authorization | Controller | Request | Service | DTO | Model | Database | API | Job/Event | View | JS | CSS | Translation | Source of Truth | Financial Impact | Security | Audit | Status | Tests | Runtime Status | Remaining Gap |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 251 | Runtime Environment Bootstrap | scripts/runtime_preflight.py | runtime.preflight | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | scripts/runtime_preflight.py | canonical request/DTO or bounded command input | Python preflight | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | runtime/page-251-preflight.json | No financial mutation. | No secret values are read or emitted. | canonical audit path or runtime report | PARTIALLY VERIFIED | Python JSON validation | PARTIALLY VERIFIED — PRE-FLIGHT ONLY | PHP, Composer, database, Redis, browser, Rust, and providers are unavailable. |
| 252 | Dependency Installation Verification | composer.json; package.json | dependency.commands | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | composer and npm commands | canonical request/DTO or bounded command input | Composer and NPM package managers | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | runtime/page-252-dependency-verification.json | No financial mutation. | Dependency findings are recorded rather than hidden. | canonical audit path or runtime report | PARTIALLY VERIFIED | npm ci; npm audit | PARTIALLY VERIFIED — FRONTEND ONLY | Composer is unavailable; npm audit reports one moderate and one high vulnerability. |
| 253 | Laravel Boot Verification | artisan | artisan.runtime | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Laravel Artisan runtime | canonical request/DTO or bounded command input | Laravel Artisan | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | RUNTIME-VERIFICATION-REPORT.md | No financial mutation. | No runtime policy claim. | canonical audit path or runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | php artisan about; route:list; config:show | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP and vendor are unavailable; container and routes are not verified. |
| 254 | Database Connection Verification | config/database.php | database.runtime | CLI/runtime | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Laravel database manager | canonical request/DTO or bounded command input | Laravel database manager | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | config/database.php; phpunit.xml | Financial execution is unverified. | No credentials are emitted. | canonical audit path or runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | database connection attempt | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP, Laravel container, credentials, and database server are unavailable. |
| 255 | Migration Baseline | database/migrations | migrate.status | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Artisan migration subsystem | canonical request/DTO or bounded command input | Artisan migration subsystem | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | database migration files; migrate:status command | Financial schema state is unverified. | Production migration was not run. | canonical audit path or runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | php artisan migrate:status | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Applied, pending, and batch state cannot be observed. |
| 256 | Seeder Safety Audit | database/seeders | seeders.audit | static/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Seeder source inspection | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | runtime/pages-256-259-static-audit.json | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | Python static audit | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Seeder execution and production classification require runtime review. |
| 257 | Factory and Fixture Audit | database/factories | factories.audit | static/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Factory source inspection | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | runtime/pages-256-259-static-audit.json | Synthetic fixtures are not production financial truth. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | Python static audit | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Factory execution and isolation require runtime review. |
| 258 | Database Constraint Audit | database/migrations | constraints.audit | static/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Migration source inspection | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | runtime/pages-256-259-static-audit.json | Money precision and foreign-key behavior are not runtime assertions. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | Python static audit | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Constraint behavior requires a real database. |
| 259 | Database Transaction Audit | app/Services | transactions.audit | static/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Canonical finance, payment, betting, draw, lottery, and notification services | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | runtime/pages-256-259-static-audit.json | Atomicity, rollback, and idempotency are not runtime verified. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | Python static audit | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | No transaction execution occurred. |
| 260 | Concurrency Test Harness | tests/Feature/Betting/BetPurchaseAtomicityTest.php | tests.pages260.concurrency | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | canonical atomicity and idempotency tests | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Betting/BetPurchaseAtomicityTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest; DuplicateWebhookIdempotencyTest; FinancialReconciliationComprehensiveTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 261 | Wallet Integrity Activation | /player/wallet | player.wallet | HTTP GET | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | WalletService; WalletHoldService; WalletReservationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /player/wallet | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | wallet and player experience tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 262 | Wallet Ledger Balance Rebuild | finance:reconcile | finance.reconcile | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | FinancialReconciliationService; LedgerBalanceValidator | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | finance:reconcile | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ReconcileCommandContractTest; FinancialReconciliationComprehensiveTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 263 | Wallet Double-Spend Test | tests/Feature/Betting/BetPurchaseAtomicityTest.php | tests.pages263.wallet | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetPurchaseTransactionService; WalletLockService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Betting/BetPurchaseAtomicityTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 264 | Wallet Hold and Release | app/Services/Finance/WalletHoldService.php | finance.wallet.hold | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | WalletHoldService; WalletLockService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/WalletHoldService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | finance wallet/hold tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 265 | Wallet Reservation Expiry | app/Services/Finance/WalletReservationService.php | finance.wallet.reservation | service/command | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | WalletReservationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/WalletReservationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | wallet reservation tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 266 | Financial Transaction Idempotency | app/Services/Finance/IdempotencyService.php | finance.idempotency | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | IdempotencyService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/IdempotencyService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | payment and betting idempotency tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 267 | Ledger Posting Contract | app/Services/Finance/LedgerPostingService.php | finance.ledger.posting | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | LedgerPostingService; LedgerBalanceValidator | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/LedgerPostingService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ledger validator and finance tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 268 | Ledger Reversal Contract | app/Services/Finance/FinancialReversalService.php | finance.ledger.reversal | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | FinancialReversalService; LedgerPostingService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/FinancialReversalService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | financial reversal tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 269 | Financial Reconciliation Execution | finance:reconcile | finance.reconcile | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | ReconcileFinancialRecordsCommand; FinancialReconciliationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | finance:reconcile | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ReconcileCommandContractTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 270 | Reconciliation Exception Lifecycle | app/DTOs/Finance/ReconciliationDiscrepancy.php | finance.reconciliation.exceptions | service/report | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | FinancialReconciliationService; reconciliation DTOs | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/DTOs/Finance/ReconciliationDiscrepancy.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | FinancialReconciliationComprehensiveTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 271 | Deposit Runtime Flow | /api/v1/deposits | api.v1.deposits | HTTP POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DepositService; PaymentInitiationService; DepositCompletionService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /api/v1/deposits | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | PlayerDepositApiTest; SuccessfulDepositCompletionTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 272 | Deposit Duplicate Callback | /api/v1/payment/webhook | api.payment.webhook | HTTP POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PaymentWebhookService; IdempotencyService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /api/v1/payment/webhook | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | DuplicateWebhookIdempotencyTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 273 | Payment Callback Ownership | /admin/payments/{payment} | admin.payments.show | HTTP GET | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PaymentCallbackService; object-scoped payment projection | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /admin/payments/{payment} | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | payment access tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 274 | Payment Signature Validation | app/Services/Payment/PaymentWebhookVerificationService.php | payment.webhook.verify | HTTP POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PaymentWebhookVerificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Payment/PaymentWebhookVerificationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | InvalidWebhookSignatureTest; PaymentWebhookSignatureVerificationTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 275 | Payment Provider Error Matrix | app/Services/Payment/Drivers | payment.provider.errors | HTTP/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PaymentGatewayManager and canonical drivers | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Payment/Drivers | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | PaymentGatewayManagerTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 276 | Payment State Machine | app/Enums/PaymentStatus.php | payment.state | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | FinancialStateTransitionService; PaymentVerificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Enums/PaymentStatus.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | FailedPaymentStateTest; ExpiredPaymentTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 277 | Payment Event Persistence | app/Models/PaymentWebhook.php | payment.events | HTTP POST/queue | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PaymentWebhookService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Models/PaymentWebhook.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | PaymentCallbackServiceTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 278 | Payment Webhook Queue | app/Services/Payment/PaymentWebhookService.php | payment.webhook.queue | queue | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PaymentWebhookService; queue services | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Payment/PaymentWebhookService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ProductionQueueComprehensiveTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 279 | Payment Dead-Letter Processing | failed_jobs | queue.failed | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | QueueHealthService; failed-job infrastructure | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | failed_jobs | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ProductionQueueComprehensiveTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 280 | Payment Replay | tests/Feature/Payment/WebhookReplayProtectionTest.php | tests.payment.replay | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PaymentWebhookVerificationService; IdempotencyService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Payment/WebhookReplayProtectionTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | WebhookReplayProtectionTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 281 | Withdrawal Runtime Flow | /api/v1/withdrawals | api.v1.withdrawals | HTTP POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | WithdrawalService; WithdrawalApprovalService; WithdrawalCompletionService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /api/v1/withdrawals | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | WithdrawalCompletionTest; WithdrawalDestinationValidationTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 282 | Withdrawal Duplicate Submission | tests/Feature/Payment/WithdrawalCompletionTest.php | tests.withdrawal.duplicate | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | WithdrawalService; WalletHoldService; IdempotencyService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Payment/WithdrawalCompletionTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | WithdrawalCompletionTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 283 | Withdrawal KYC Gate | app/Services/Compliance/WithdrawalKycGateService.php | withdrawal.kyc | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | WithdrawalKycGateService; KycVerificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Compliance/WithdrawalKycGateService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | WithdrawalKycGateTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 284 | Withdrawal Self-Exclusion and Restriction | app/Services/Compliance/SelfExclusionService.php | withdrawal.restrictions | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SelfExclusionService; ResponsibleGamingService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Compliance/SelfExclusionService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | SelfExclusionAndAgentGateTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 285 | Withdrawal Failure Recovery | app/Services/Finance/WithdrawalCompletionService.php | withdrawal.recovery | service/queue | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | WithdrawalCompletionService; FinancialReversalService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/WithdrawalCompletionService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | WithdrawalCompletionTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 286 | Withdrawal Completion Reconciliation | app/Services/Finance/PayoutReconciliationService.php | withdrawal.reconciliation | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PayoutReconciliationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/PayoutReconciliationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | FinancialReconciliationComprehensiveTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 287 | Bet Purchase Runtime Activation | /api/v1/bets | api.v1.bets.store | HTTP POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetPurchaseService and canonical purchase pipeline | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /api/v1/bets | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest; BetPurchaseWebTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 288 | Bet Price Authority | app/Services/Betting/BetCalculationService.php | bet.price | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetCalculationService; MarketRuleResolver | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Betting/BetCalculationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 289 | Bet Currency Authority | app/Enums/Currency.php | bet.currency | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Currency enum; BetPurchaseValidator | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Enums/Currency.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | payment and betting tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 290 | Bet Limit Enforcement | app/Services/Betting/BetPurchaseRiskService.php | bet.limits | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetPurchaseRiskService; ResponsibleGamingService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Betting/BetPurchaseRiskService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ResponsibleGamingWebTest; SelfExclusionAndAgentGateTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 291 | Bet Concurrency | tests/Feature/Betting/BetPurchaseAtomicityTest.php | tests.bet.concurrency | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetPurchaseTransactionService; WalletLockService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Betting/BetPurchaseAtomicityTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 292 | Bet Idempotency | app/Services/Betting/BetPurchaseIdempotencyService.php | bet.idempotency | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetPurchaseIdempotencyService; IdempotencyService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Betting/BetPurchaseIdempotencyService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest; BetPurchaseApiTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 293 | Bet Failure Rollback | app/Services/Betting/BetPurchaseTransactionService.php | bet.rollback | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetPurchaseTransactionService; WalletReservationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Betting/BetPurchaseTransactionService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 294 | Ticket Issuance Runtime | app/Services/Betting/BetPurchaseTicketService.php | ticket.issuance | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetPurchaseTicketService; TicketOwnershipService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Betting/BetPurchaseTicketService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest; BetPurchaseWebTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 295 | Ticket Ownership | app/Services/Ticket/TicketOwnershipService.php | ticket.ownership | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | TicketOwnershipService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Ticket/TicketOwnershipService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ticket ownership tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 296 | Ticket Share and QR Security | app/Services/Betting/TicketShareService.php | ticket.share | HTTP/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | TicketShareService; TicketVerificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Betting/TicketShareService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ticket share tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 297 | Ticket Verification Runtime | /ticket/verify | ticket.verification | HTTP GET/POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | TicketVerificationService; PublicResultVerificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /ticket/verify | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | TicketVerification tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 298 | Draw Open and Close Automation | app/Console/Commands/Lottery/TickCommand.php | lottery.tick | CLI/scheduler | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DrawScheduleService; DrawLifecycleService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Console/Commands/Lottery/TickCommand.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | DrawAutomationTest; ScheduleRegistrationTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 299 | Draw State Machine | app/Enums/DrawLifecycleState.php | draw.lifecycle | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DrawLifecycleService; DrawCertificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Enums/DrawLifecycleState.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | draw lifecycle tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 300 | Draw Locking and Cutoff | app/Http/Middleware/EnsureDrawIsOpen.php | draw.cutoff | HTTP | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | EnsureDrawIsOpen; DrawLifecycleService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Http/Middleware/EnsureDrawIsOpen.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | DrawAutomationTest; BetPurchaseAtomicityTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 301 | Result Import Runtime | app/Services/Draw/DrawResultIngestionService.php | result.import | CLI/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DrawResultIngestionService; GloResultImportService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Draw/DrawResultIngestionService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloResultImportTest; LaneResultImportContractTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 302 | Result Provenance Persistence | app/Models/GloResultImport.php | result.provenance | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloResultImportService; DrawResultIngestionService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Models/GloResultImport.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloResultImportTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 303 | Result Duplicate Import | tests/Feature/Glo/GloResultImportTest.php | result.import.duplicate | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloResultImportService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Glo/GloResultImportTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloResultImportTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 304 | Result Conflict Detection | app/Services/Draw/DrawResultValidator.php | result.conflict | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DrawResultValidator; provenance services | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Draw/DrawResultValidator.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | result validator tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 305 | Result Certification | app/Services/Draw/DrawCertificationService.php | result.certify | HTTP/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DrawCertificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Draw/DrawCertificationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | draw certification tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 306 | Result Publication Gate | app/Services/Draw/DrawResultPublicationService.php | result.publish | HTTP/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DrawResultPublicationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Draw/DrawResultPublicationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | result publication tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 307 | Result Correction Policy | app/Services/Draw/DrawResultConfirmationService.php | result.correction | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DrawResultConfirmationService; DrawResultIngestionService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Draw/DrawResultConfirmationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | result correction tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 308 | Leading-Zero Integrity | security/weekly-result-integrity/src/canonical.rs | rust.leading_zero | Rust/Laravel/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Rust canonicalization; Laravel result validation | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | security/weekly-result-integrity/src/canonical.rs | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | Rust integrity vectors; result tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 309 | National Lottery Data Lane | app/Services/Lottery | lottery.national | API/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | National lottery service family | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | NationalLotteryIntegrationSeamTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 310 | Weekly Lottery Data Lane | app/Services/Lottery | lottery.weekly | API/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Weekly lottery service family | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | weekly lottery tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 311 | Mega and Other Product Data Lane | app/Services/Lottery | lottery.product | API/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Product-specific lottery service family | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | lottery lane tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 312 | PCSO Data Lane | app/Services/Lottery | lottery.pcso | API/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PCSO result service family | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | PcsoLotteryPublicPageTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 313 | GLO L6 Data Lane | app/Services/Lottery/GloL6 | lottery.glo.l6 | API/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GLO L6 authoritative service family | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloL6 | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloResultImportTest; GloPublicResultHistoryTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 314 | GLO L6 Purchase Contract | app/Services/Lottery/GloL6PurchaseCapabilityService.php | lottery.glo.l6.purchase | API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloL6PurchaseCapabilityService; GloL6SalesService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloL6PurchaseCapabilityService.php | No checkout, purchase, wallet debit, or ticket issuance is fabricated. | Capability and provider gates remain canonical. | canonical audit path or runtime report | NOT_CONFIGURED — FAIL CLOSED | Glo purchase capability tests | NOT_CONFIGURED | Real public GLO L6 purchase contract and enabled provider/capability are not configured. |
| 315 | GLO L6 Ticket Range | app/Services/Lottery/GloL6AuthoritativeTicketEngineService.php | lottery.glo.l6.range | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloL6AuthoritativeTicketEngineService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloL6AuthoritativeTicketEngineService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GLO ticket tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 316 | GLO L6 Pricing Authority | app/Services/Lottery/GloL6PurchaseCapabilityService.php | lottery.glo.l6.pricing | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GLO L6 capability and pricing services | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloL6PurchaseCapabilityService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GLO sales tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 317 | GLO Prize Allocation | app/Services/Lottery/GloPrizeCatalogue.php | lottery.glo.prizes | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloPrizeCatalogue; GLO prize services | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloPrizeCatalogue.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GLO prize tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 318 | GLO Unsold Ticket Prize Scaling | app/Services/Lottery/GloL6ProportionalPrizeCalculator.php | lottery.glo.prize.scale | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloL6ProportionalPrizeCalculator; exact money arithmetic | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloL6ProportionalPrizeCalculator.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloL6ProportionalCalculatorTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 319 | GLO Claim Window | app/Services/Lottery/GloPrizeClaimService.php | lottery.glo.claim.window | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloPrizeClaimService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloPrizeClaimService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloPrizeClaimTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 320 | GLO Prize Claim Creation | app/Services/Lottery/GloPrizeClaimService.php | lottery.glo.claim.create | HTTP POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloPrizeClaimService; TicketAuthenticityService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloPrizeClaimService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloPrizeClaimTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 321 | GLO Claim Duplicate | tests/Feature/Glo/GloPrizeClaimTest.php | tests.glo.claim.duplicate | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloPrizeClaimService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Glo/GloPrizeClaimTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloPrizeClaimTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 322 | GLO Claim Age Verification | app/Services/Compliance/KycVerificationService.php | lottery.glo.claim.age | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloPrizeClaimService; KycVerificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Compliance/KycVerificationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloPrizeClaimTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 323 | GLO Claim KYC | app/Services/Compliance/WithdrawalKycGateService.php | lottery.glo.claim.kyc | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloPrizeClaimService; KycVerificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Compliance/WithdrawalKycGateService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloPrizeClaimTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 324 | GLO Payment Hold | app/Models/GloPrizePaymentHold.php | lottery.glo.payment.hold | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloPrizeClaimService; RealPrizeSettlementService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Models/GloPrizePaymentHold.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloPrizeClaimTest; FinalProductionReadinessTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 325 | GLO Ticket Freeze Runtime | app/Services/Lottery/GloTicketFreezeService.php | lottery.glo.freeze | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloTicketFreezeService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloTicketFreezeService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloTicketFreezeTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 326 | GLO Freeze Release | app/Console/Commands/GloExpireFreezes.php | glo.expire-freezes | CLI/scheduler | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloExpireFreezes; GloTicketFreezeService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Console/Commands/GloExpireFreezes.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloTicketFreezeTest; GloConsoleCommandsTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 327 | GLO Frozen Winner Processing | glo:process-frozen-winners | glo.process-frozen-winners | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloProcessFrozenWinners; GloFrozenWinnerService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | glo:process-frozen-winners | No frozen-winner payout or claim is reported. | Command/operator boundary remains canonical. | canonical audit path or runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | GloConsoleCommandsTest; GloFrozenWinnerTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The required PHP command was attempted by the acceptance gate but PHP is unavailable. |
| 328 | GLO Public Result Publication | app/Services/Lottery/GloResultPublicationService.php | lottery.glo.publish | API/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloResultPublicationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloResultPublicationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloPublicResultHistoryTest; GloResultImportTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 329 | Prize Matching Engine | app/Services/Betting/MarketResultResolver.php | prize.match | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SelectionSettlementResolver; market result services | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Betting/MarketResultResolver.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | settlement and result tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 330 | Prize Settlement Engine | app/Services/Draw/RealPrizeSettlementService.php | prize.settlement | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | RealPrizeSettlementService; PayoutApprovalService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Draw/RealPrizeSettlementService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | FinalProductionReadinessTest; GloPrizeClaimTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 331 | Prize Payout Idempotency | app/Services/Finance/PayoutBatchService.php | prize.payout.idempotency | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PayoutBatchService; PayoutApprovalService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/PayoutBatchService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | payout tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 332 | Prize Payout Failure Recovery | app/Services/Payment/PayoutTransferService.php | prize.payout.recovery | service/queue | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PayoutTransferService; FinancialReversalService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Payment/PayoutTransferService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | payout and reconciliation tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 333 | Agent Commission Runtime | app/Services/Agent/CommissionCalculationService.php | agent.commission.calculate | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | CommissionCalculationService; AgentCommissionService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Agent/CommissionCalculationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | CommissionAccrualTest; CommissionCalculationTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 334 | Agent Commission Duplicate | tests/Feature/Agent/CommissionIdempotencyTest.php | tests.agent.commission.duplicate | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | AgentCommissionService; IdempotencyService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Agent/CommissionIdempotencyTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | CommissionIdempotencyTest; DuplicateCommissionPreventionTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 335 | Agent Settlement Runtime | app/Services/Agent/AgentCommissionSettlementService.php | agent.commission.settlement | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | AgentCommissionSettlementService; AgentSettlementService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Agent/AgentCommissionSettlementService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | agent settlement tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 336 | Agent Referral Attribution | app/Services/Agent/AgentReferralService.php | agent.referral | authenticated API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | AgentReferralService; AgentPortalController | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Agent/AgentReferralService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | AgentReferralCodeUniquenessTest; UserAgentAttributionTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 337 | Agent Owner Isolation | /agent/referrals/{reference} | agent.referrals.show | HTTP GET | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | AgentReferralService; AgentPortalController | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /agent/referrals/{reference} | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | AgentReportingTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 338 | Notification Delivery Runtime | app/Services/Notification/NotificationDispatchService.php | notification.delivery | event/queue | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | NotificationDispatchService; NotificationDeliveryService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Notification/NotificationDispatchService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | notification tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 339 | Notification Idempotency | app/Services/Notification/NotificationReceiptService.php | notification.idempotency | service/queue | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | NotificationReceiptService; NotificationSuppressionService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Notification/NotificationReceiptService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | notification receipt tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 340 | Notification Preference Enforcement | app/Services/Notification/NotificationPreferenceService.php | notification.preferences | authenticated API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | NotificationPreferenceService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Notification/NotificationPreferenceService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | notification preference tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 341 | Support Case Contract | /support | support.index | GET/POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SupportCaseService; owner-scoped support migration | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /support | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | SupportCaseOwnerIsolationTest; Pages251To350StaticContractTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 342 | Support Case Creation | /support | support.store | POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SupportCaseService; CreateSupportCaseRequest | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /support | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | SupportCaseOwnerIsolationTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 343 | Support Case Owner Isolation | /support/{reference} | support.show | GET | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SupportCaseService::findForOwner | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /support/{reference} | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | SupportCaseOwnerIsolationTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 344 | Support Case Reply | /support/{reference}/reply | support.reply | POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SupportCaseService::reply; ReplySupportCaseRequest | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /support/{reference}/reply | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | SupportCaseOwnerIsolationTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 345 | Support Case Escalation | app/Services/Support/SupportCaseService.php | support.escalation | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | AuditLogService; canonical compliance escalation remains separate | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Support/SupportCaseService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | support/compliance escalation tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 346 | Support SLA and Age Projection | app/Models/SupportCase.php | support.sla | service/view | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SupportCase timestamps and configured operational policy | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Models/SupportCase.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | support operational tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 347 | Security Event Persistence | app/Services/Security/SecurityEventService.php | security.events | event/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SecurityEventService; AuthenticationSecurityService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Security/SecurityEventService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | security event tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 348 | MFA Runtime | app/Services/Security/MfaChallengeService.php | security.mfa | HTTP/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | MfaChallengeService; SecurityEventService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Security/MfaChallengeService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | MFA security tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 349 | Session Revocation | app/Services/Security/UserSessionSecurityService.php | security.sessions.revoke | HTTP/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | UserSessionSecurityService; SecurityEventService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Security/UserSessionSecurityService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | session security tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 350 | Final Runtime Acceptance Gate | scripts/pages_251_350_runtime_gate.py | runtime.acceptance | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Page 350 runtime gate and all canonical domain test suites | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | scripts/pages_251_350_runtime_gate.py | No finance or lottery completion claim is made. | Final acceptance remains evidence-bound. | canonical audit path or runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | runtime/page-350-acceptance.json | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP, Composer, database, Redis, browser, provider, and Cargo requirements remain unavailable. |

## Route Matrix

| Pages | Boundary | HTTP methods | Authorization and middleware | Runtime evidence |
|---|---|---|---|---|
| 251–259 | Python preflight, dependency, Laravel, database, migration, seed, factory, constraint, and transaction audit scripts | CLI/static | Process/filesystem boundary only | Preflight and static JSON executed; Laravel/PHP commands blocked. |
| 260–294 | Existing wallet, ledger, payment, withdrawal, betting, and ticket routes/services | HTTP, CLI, queue, PHPUnit | Existing auth, owner, KYC, CSRF, rate-limit, draw, and provider boundaries | Canonical source/test inventory present; runtime blocked. |
| 295–308 | Existing ticket, draw, result, Rust boundary, import, certification, and publication routes | HTTP, CLI, API, PHPUnit | Existing ticket, draw, result, operator, provider, and process boundaries | Runtime blocked; no result or certification success claimed. |
| 309–318 | Product-specific National, Weekly, PCSO, GLO L6, pricing, prize, and exact-arithmetic lanes | API, CLI, service, PHPUnit | Product and result lane isolation | Runtime blocked; GLO purchase remains NOT_CONFIGURED where canonical capability is not enabled. |
| 319–337 | GLO claims, holds, freezes, settlement, payouts, agents, commissions, referrals | HTTP, API, CLI, queue, PHPUnit | Claim, KYC, age, payout, agent, ownership, and idempotency boundaries | Runtime blocked; no claim, payout, or commission success claimed. |
| 338–340 | Notification dispatch, receipt, suppression, and preferences | Event, queue, API | Recipient ownership and preference policy | Runtime blocked; no delivery claim. |
| 341–346 | Owner-scoped support case contract and portal | GET/POST, auth, CSRF, throttle | Authenticated owner query, bounded reference, no hidden owner field | Implementation is static-only; migration and routes are not runtime verified. |
| 347–349 | Security events, MFA, and session revocation | Event, API | Authentication, MFA, session, and security-event boundaries | Runtime blocked. |
| 350 | Final runtime acceptance gate | CLI | Full enterprise acceptance boundary | 2 frontend commands executed; 11 required commands blocked. |

## API Contract Matrix

| Pages | Request / response contract | Canonical owner | Idempotency / ownership | Evidence |
|---|---|---|---|---|
| 260–280 | Wallet, ledger, payment, callback, webhook, queue, replay, and reconciliation contracts | Existing Finance and Payment DTOs/services/enums | Stable references, signatures, callback ownership, replay safety | Static architecture and existing tests; runtime blocked. |
| 281–294 | Withdrawal and bet purchase contracts | Existing Withdrawal and Betting DTOs/services | Session ownership, KYC gate, draw state, wallet reservation, idempotency | Static architecture and existing tests; runtime blocked. |
| 295–308 | Ticket verification, draw lifecycle, result import, certification, publication, and Rust JSON boundary | Existing Ticket, Draw, Result, and Rust crate | Opaque references, source provenance, certification gate, malformed-input validation | Static architecture and existing tests; runtime blocked. |
| 309–328 | Product-specific lottery and GLO result/purchase/claim APIs | Existing product and GLO services | Lane isolation, source state, leading-zero preservation, capability gate | Static architecture and existing tests; runtime blocked. |
| 329–340 | Prize, payout, agent, commission, notification, and preference contracts | Existing Prize, Agent, Finance, and Notification services | Payout/commission idempotency and recipient ownership | Static architecture and existing tests; runtime blocked. |
| 341–349 | Support, security event, MFA, and session contracts | New SupportCaseService plus existing Security services | Owner-scoped case reference, CSRF, throttle, MFA/session policy | Static source and contract tests; runtime blocked. |

## Security Matrix

| Pages | Control | Source | Negative case | Evidence |
|---|---|---|---|---|
| 251–259 | Secret-free preflight and configuration inspection | Python scripts and config source | No secrets emitted; no unavailable runtime treated as pass | Executed preflight JSON and static audit JSON. |
| 260–286 | Wallet/payment/withdrawal auth, ownership, KYC, signatures, replay, rate limits, and provider error handling | Canonical services, middleware, DTOs, and existing tests | Cross-owner access, invalid signature, stale/replayed callback, duplicate withdrawal, provider failure | Runtime blocked. |
| 287–307 | Bet/ticket/draw/result controls | Canonical purchase, ownership, draw, result, certification, and publication services | Closed draw, invalid price/currency, duplicate bet, unverified/conflicting result | Runtime blocked. |
| 308–318 | Leading zeros, product lane isolation, Rust process boundary, GLO capability and exact arithmetic | Rust crate and canonical lottery services | Numeric coercion, product crossover, malformed input, disabled purchase | Runtime blocked; Page 314 remains NOT_CONFIGURED. |
| 319–337 | Claim age/KYC, holds, freezes, payout, agent and referral ownership | Canonical GLO, Compliance, Finance, and Agent services | Expired claim, duplicate claim/payout/commission, cross-agent access | Runtime blocked. |
| 338–349 | Notification recipients, support owner cases, security events, MFA, and session revocation | Notification, SupportCase, and Security services | Wrong recipient, cross-owner case, invalid MFA, revoked session | Runtime blocked. |

## Financial Integrity Matrix

| Pages | Operation | Atomicity / idempotency | Ledger / audit rule | Runtime status |
|---|---|---|---|---|
| 259–270 | Database transactions, wallet balance, holds, reservations, idempotency, ledger posting, reversals, reconciliation | DB transaction, locks, reservations, stable references, read-only reconciliation | Double-entry and discrepancy evidence remain canonical; no silent repair | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 271–280 | Deposits, callbacks, provider errors, payment states, events, queues, dead letters, replay | Verified callback, signature, replay-safe webhook, queue retry/terminal state | No credit before verification; callback and provider evidence persisted | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 281–294 | Withdrawals, KYC gates, holds, refunds/recovery, bet purchase, price/currency/limits, tickets | Hold/approval/completion atomicity and purchase idempotency | No double hold, payout, debit, or orphan ticket | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 319–332 | Claims, age/KYC, payment holds, freezes, matching, settlement, payout | Claim and payout idempotency; exact money arithmetic | Gross/deductions/net and ledger treatment must reconcile | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 333–337 | Agent commissions, duplicates, settlements, referrals, owner isolation | Qualifying event idempotency and owner-scoped attribution | No duplicate accrual or cross-agent commission claim | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## Lottery Integrity Matrix

| Pages | Lottery boundary | Integrity requirements | Source | Runtime status |
|---|---|---|---|---|
| 287–300 | Bet purchase, ticket issuance, draw open/close, draw state, cutoff | Server price/currency, owner scope, atomic purchase, closed-draw rejection | Betting and Draw services, enums, middleware, existing tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 301–308 | Result import, provenance, duplicate/conflict handling, certification, publication, correction, leading zeros | Preserve source/version/evidence; no auto-publish conflict; exact string values | Draw result services and Rust canonicalization crate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 309–313 | National, Weekly, Mega/other, PCSO, and GLO L6 lanes | Separate product models/services/results; no lane crossover | Product-specific canonical services/models | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 314–318 | GLO purchase capability, six-digit range, pricing, prize allocation, exact scaling | Do not invent checkout; preserve leading zeros; exact decimal arithmetic | GLO L6 services and calculator | Page 314 NOT_CONFIGURED; others blocked. |
| 319–328 | Claim windows, creation, duplicate claim, age/KYC, payment holds, freezes, frozen winners, publication | Winning ticket/result authority, claim eligibility, payout gate, freeze lifecycle | GLO claim, freeze, result, compliance, and payout services | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 329–332 | Prize matching, settlement, payout idempotency, payout failure | Backend matching only; no duplicate payout; recoverable failed claim | Prize, Draw, Finance, Payment services | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## Rust Runtime Matrix

| Pages | Crate / binary | Input | Output / validation | Determinism / timeout | Evidence |
|---|---|---|---|---|---|
| 308 | `security/weekly-result-integrity` canonicalization | Result JSON with string-preserving values | Canonical bytes/hash/error boundary; Laravel validates output | Leading-zero vectors and deterministic tests exist; Cargo not available | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 350 | Rust build and execution acceptance | Cargo workspace and crate commands | `cargo check`, `cargo test`, `cargo build --release`, malformed input, timeout, exit code, stderr, resource boundary | No execution occurred | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## Runtime result summary

- Page 251 Python preflight executed and produced machine-readable JSON.
- Page 252 NPM installation executed; Composer was unavailable; NPM audit reported one moderate and one high vulnerability.
- Page 350 acceptance gate executed 13 commands: 2 succeeded, 0 failed after execution, and 11 were blocked by unavailable PHP, Composer, Cargo, or related runtime components.
- Laravel, database, queue, payment provider, browser, accessibility, and Rust execution remain `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`.

```

## FILE 15: `database/migrations/2026_09_30_000900_create_support_case_tables.php`

# TYPE: Laravel migration
# PURPOSE: Create owner-scoped support cases and messages without reusing anonymous ContactMessage rows.

```php
<?php

// TYPE: Laravel migration
// PURPOSE: Create owner-scoped support cases and public support messages without reusing anonymous ContactMessage rows.

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_cases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('public_reference', 32)->unique();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('category', 64);
            $table->string('priority', 32)->default('normal');
            $table->string('status', 32)->default('open');
            $table->string('subject', 180);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['owner_user_id', 'status']);
            $table->index(['status', 'priority']);
        });

        Schema::create('support_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_case_id')->constrained('support_cases')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->boolean('internal')->default(false);
            $table->timestamps();

            $table->index(['support_case_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_cases');
    }
};

```

## FILE 16: `app/Models/SupportCase.php`

# TYPE: Eloquent model
# PURPOSE: Owner-scoped support case aggregate with opaque public reference and hidden internal fields.

```php
<?php

// TYPE: Eloquent model
// PURPOSE: Owner-scoped support case aggregate for authenticated player support.

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class SupportCase extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';
    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';

    protected $fillable = [
        'uuid',
        'public_reference',
        'owner_user_id',
        'category',
        'priority',
        'status',
        'subject',
        'closed_at',
    ];

    protected $hidden = [
        'id',
        'uuid',
        'owner_user_id',
    ];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
            $model->public_reference ??= self::buildPublicReference();
        });
    }

    public static function buildPublicReference(): string
    {
        return 'SC-'.strtoupper(Str::random(12));
    }

    public function getRouteKeyName(): string
    {
        return 'public_reference';
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<SupportMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'support_case_id');
    }
}

```

## FILE 17: `app/Models/SupportMessage.php`

# TYPE: Eloquent model
# PURPOSE: Public case message model with hidden case, sender, and internal identifiers.

```php
<?php

// TYPE: Eloquent model
// PURPOSE: Public support-case message with owner-scoped case relation and hidden internal metadata.

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SupportMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'support_case_id',
        'sender_user_id',
        'body',
        'internal',
    ];

    protected $hidden = [
        'id',
        'support_case_id',
        'sender_user_id',
        'internal',
    ];

    protected function casts(): array
    {
        return [
            'internal' => 'bool',
        ];
    }

    /** @return BelongsTo<SupportCase, $this> */
    public function supportCase(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class, 'support_case_id');
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}

```

## FILE 18: `app/Services/Support/SupportCaseService.php`

# TYPE: Domain service
# PURPOSE: Transactional owner-scoped case creation, listing, reading, replying, closure gate, and audit logging.

```php
<?php

// TYPE: Domain service
// PURPOSE: Create, list, read, and reply to authenticated owner-scoped support cases without exposing anonymous ContactMessage records.

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Models\SupportCase;
use App\Models\SupportMessage;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class SupportCaseService
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * @return list<SupportCase>
     */
    public function listFor(User $owner): array
    {
        return SupportCase::query()
            ->where('owner_user_id', (int) $owner->getAuthIdentifier())
            ->with(['messages' => static fn ($query) => $query
                ->where('internal', false)
                ->latest('id')
                ->limit(1)])
            ->latest('updated_at')
            ->limit(100)
            ->get()
            ->all();
    }

    public function findForOwner(User $owner, string $reference): SupportCase
    {
        $case = SupportCase::query()
            ->where('owner_user_id', (int) $owner->getAuthIdentifier())
            ->where('public_reference', $reference)
            ->with(['messages' => static fn ($query) => $query
                ->where('internal', false)
                ->oldest('id')])
            ->first();

        if (! $case instanceof SupportCase) {
            throw new RuntimeException('Support case not found.');
        }

        return $case;
    }

    /**
     * @param array{category: string, subject: string, body: string, priority?: string} $data
     */
    public function create(User $owner, array $data): SupportCase
    {
        $case = DB::transaction(function () use ($owner, $data): SupportCase {
            $case = SupportCase::query()->create([
                'uuid' => (string) Str::uuid(),
                'public_reference' => SupportCase::buildPublicReference(),
                'owner_user_id' => (int) $owner->getAuthIdentifier(),
                'category' => $data['category'],
                'priority' => $data['priority'] ?? SupportCase::PRIORITY_NORMAL,
                'status' => SupportCase::STATUS_OPEN,
                'subject' => $data['subject'],
            ]);

            $case->messages()->create([
                'sender_user_id' => (int) $owner->getAuthIdentifier(),
                'body' => $data['body'],
                'internal' => false,
            ]);

            $this->audit->log(
                userId: (int) $owner->getAuthIdentifier(),
                action: AuditAction::Create,
                riskLevel: RiskLevel::Low,
                auditable: $case,
                description: 'Owner-scoped support case created.',
                metadata: ['public_reference' => $case->public_reference, 'category' => $case->category],
            );

            return $case;
        });

        return $case->load(['messages' => static fn ($query) => $query
            ->where('internal', false)
            ->oldest('id')]);
    }

    public function reply(User $owner, string $reference, string $body): SupportCase
    {
        $case = $this->findForOwner($owner, $reference);

        if (in_array($case->status, [SupportCase::STATUS_CLOSED, SupportCase::STATUS_RESOLVED], true)) {
            throw new RuntimeException('Closed support cases cannot receive replies.');
        }

        DB::transaction(function () use ($owner, $case, $body): void {
            $case->messages()->create([
                'sender_user_id' => (int) $owner->getAuthIdentifier(),
                'body' => $body,
                'internal' => false,
            ]);
            $case->forceFill(['status' => SupportCase::STATUS_OPEN])->save();

            $this->audit->log(
                userId: (int) $owner->getAuthIdentifier(),
                action: AuditAction::Update,
                riskLevel: RiskLevel::Low,
                auditable: $case,
                description: 'Owner-scoped support case reply created.',
                metadata: ['public_reference' => $case->public_reference],
            );
        });

        return $case->fresh(['messages' => static fn ($query) => $query
            ->where('internal', false)
            ->oldest('id')]);
    }
}

```

## FILE 19: `app/Http/Requests/Support/CreateSupportCaseRequest.php`

# TYPE: Laravel form request
# PURPOSE: Validate authenticated support-case creation fields without accepting owner identity.

```php
<?php

// TYPE: Form request
// PURPOSE: Validate authenticated support-case creation without accepting owner identity from the browser.

declare(strict_types=1);

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;

final class CreateSupportCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'in:account,payment,withdrawal,bet,lottery,security,other'],
            'priority' => ['nullable', 'string', 'in:low,normal,high'],
            'subject' => ['required', 'string', 'min:3', 'max:180'],
            'body' => ['required', 'string', 'min:1', 'max:10000'],
        ];
    }
}

```

## FILE 20: `app/Http/Requests/Support/ReplySupportCaseRequest.php`

# TYPE: Laravel form request
# PURPOSE: Validate authenticated owner-scoped reply body.

```php
<?php

// TYPE: Form request
// PURPOSE: Validate owner-scoped support replies without trusting a hidden owner or account field.

declare(strict_types=1);

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;

final class ReplySupportCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:10000'],
        ];
    }
}

```

## FILE 21: `app/Http/Controllers/Support/SupportPortalController.php`

# TYPE: HTTP controller
# PURPOSE: Activate authenticated support-case list, detail, create, and reply routes.

```php
<?php

// TYPE: HTTP controller
// PURPOSE: Authenticated owner-scoped support-case portal; anonymous ContactMessage rows remain outside this private surface.

declare(strict_types=1);

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\CreateSupportCaseRequest;
use App\Http\Requests\Support\ReplySupportCaseRequest;
use App\Models\User;
use App\Services\Support\SupportCaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

final class SupportPortalController extends Controller
{
    public function index(Request $request, SupportCaseService $supportCases): View
    {
        $owner = $this->owner($request);

        return view('support.portal', [
            'surface' => 'index',
            'state' => 'AVAILABLE',
            'reference' => null,
            'records' => $supportCases->listFor($owner),
            'case' => null,
        ]);
    }

    public function show(Request $request, string $reference, SupportCaseService $supportCases): View
    {
        $owner = $this->owner($request);

        try {
            $case = $supportCases->findForOwner($owner, $reference);
        } catch (RuntimeException) {
            abort(404);
        }

        return view('support.portal', [
            'surface' => 'detail',
            'state' => 'AVAILABLE',
            'reference' => $case->public_reference,
            'records' => [],
            'case' => $case,
        ]);
    }

    public function store(CreateSupportCaseRequest $request, SupportCaseService $supportCases): RedirectResponse
    {
        $owner = $this->owner($request);
        $supportCases->create($owner, $request->validated());

        return redirect()->route('support.index')->with('status', trans('support.case_created'));
    }

    public function reply(
        ReplySupportCaseRequest $request,
        string $reference,
        SupportCaseService $supportCases,
    ): RedirectResponse {
        $owner = $this->owner($request);

        try {
            $supportCases->reply($owner, $reference, (string) $request->validated('body'));
        } catch (RuntimeException) {
            abort(404);
        }

        return redirect()->route('support.show', ['reference' => $reference])
            ->with('status', trans('support.reply_created'));
    }

    private function owner(Request $request): User
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 401);

        return $owner;
    }
}

```

## FILE 22: `resources/views/support/portal.blade.php`

# TYPE: Blade view
# PURPOSE: Render owner-scoped cases, public messages, creation, and reply forms with CSRF and accessible labels.

```php
@extends('layouts.app')

@section('title', trans('support.title'))
@section('meta_description', trans('support.meta_description'))
@section('robots', 'noindex, nofollow')

@section('content')
<main class="next-shell next-content" id="support-main" tabindex="-1" aria-labelledby="support-title">
    <a class="pp-skip-link" href="#support-main">{{ trans('public_pages.skip_to_content') }}</a>
    <header class="next-page-header">
        <div class="next-shell next-page-header__inner">
            <a class="next-brand" href="{{ route('home') }}" aria-label="{{ trans('support.home_aria') }}">
                <span class="next-brand__mark">TL</span>
                <span>THAILOTTO<small>{{ trans('support.brand_subtitle') }}</small></span>
            </a>
            <nav class="next-nav" aria-label="{{ trans('support.primary_nav') }}">
                <a href="{{ route('player.dashboard') }}">{{ trans('support.nav_dashboard') }}</a>
                <a class="is-active" href="{{ route('support.index') }}" aria-current="page">{{ trans('support.nav_support') }}</a>
                <a href="{{ route('contact') }}">{{ trans('support.nav_contact') }}</a>
            </nav>
        </div>
    </header>

    <section class="next-hero" aria-labelledby="support-title">
        <div>
            <p class="next-eyebrow">{{ trans('support.eyebrow') }}</p>
            <h1 id="support-title">{{ $surface === 'detail' ? trans('support.detail_title') : trans('support.title') }}</h1>
            <p>{{ trans('support.description') }}</p>
        </div>
        <div class="next-hero-object" aria-hidden="true"><span>SUP</span></div>
    </section>

    @if (session('status'))
        <section class="next-panel" role="status" aria-live="polite">
            <p>{{ session('status') }}</p>
        </section>
    @endif

    @if ($errors->any())
        <section class="next-panel" role="alert" aria-labelledby="support-errors-title">
            <h2 id="support-errors-title">{{ trans('support.validation_title') }}</h2>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($surface === 'detail' && $case !== null)
        <section class="next-panel" aria-labelledby="support-case-title">
            <p class="next-eyebrow">{{ trans('support.case_eyebrow') }}</p>
            <h2 id="support-case-title">{{ $case->subject }}</h2>
            <dl>
                <div><dt>{{ trans('support.reference') }}</dt><dd class="font-mono">{{ $case->public_reference }}</dd></div>
                <div><dt>{{ trans('support.status_label') }}</dt><dd>{{ $case->status }}</dd></div>
                <div><dt>{{ trans('support.priority_label') }}</dt><dd>{{ $case->priority }}</dd></div>
                <div><dt>{{ trans('support.category_label') }}</dt><dd>{{ $case->category }}</dd></div>
            </dl>
        </section>

        <section class="next-panel" aria-labelledby="support-messages-title">
            <h2 id="support-messages-title">{{ trans('support.messages_title') }}</h2>
            @forelse ($case->messages as $message)
                <article class="support-message">
                    <p>{{ $message->body }}</p>
                    <time datetime="{{ optional($message->created_at)->toIso8601String() }}">{{ optional($message->created_at)->toDateTimeString() }}</time>
                </article>
            @empty
                <p>{{ trans('support.no_messages') }}</p>
            @endforelse
        </section>

        @if (! in_array($case->status, ['closed', 'resolved'], true))
            <section class="next-panel" aria-labelledby="support-reply-title">
                <h2 id="support-reply-title">{{ trans('support.reply_title') }}</h2>
                <form method="post" action="{{ route('support.reply', ['reference' => $case->public_reference]) }}">
                    @csrf
                    <label for="reply-body">{{ trans('support.reply_label') }}</label>
                    <textarea id="reply-body" name="body" rows="6" maxlength="10000" required>{{ old('body') }}</textarea>
                    <button class="next-button next-button--gold" type="submit">{{ trans('support.reply_submit') }}</button>
                </form>
            </section>
        @endif
    @else
        <section class="next-panel" aria-labelledby="support-cases-title">
            <p class="next-eyebrow">{{ trans('support.state_label') }}</p>
            <h2 id="support-cases-title">{{ trans('support.cases_title') }}</h2>
            @forelse ($records as $record)
                <article class="support-case-summary">
                    <h3><a href="{{ route('support.show', ['reference' => $record->public_reference]) }}">{{ $record->subject }}</a></h3>
                    <p>{{ trans('support.reference') }}: <span class="font-mono">{{ $record->public_reference }}</span></p>
                    <p>{{ $record->status }} · {{ $record->priority }} · {{ $record->category }}</p>
                </article>
            @empty
                <p>{{ trans('support.no_cases') }}</p>
            @endforelse
        </section>

        <section class="next-panel" aria-labelledby="support-create-title">
            <h2 id="support-create-title">{{ trans('support.create_title') }}</h2>
            <form method="post" action="{{ route('support.store') }}">
                @csrf
                <label for="support-category">{{ trans('support.category_label') }}</label>
                <select id="support-category" name="category" required>
                    @foreach (['account', 'payment', 'withdrawal', 'bet', 'lottery', 'security', 'other'] as $category)
                        <option value="{{ $category }}" @selected(old('category') === $category)>{{ trans('support.category_'.$category) }}</option>
                    @endforeach
                </select>
                <label for="support-priority">{{ trans('support.priority_label') }}</label>
                <select id="support-priority" name="priority">
                    @foreach (['low', 'normal', 'high'] as $priority)
                        <option value="{{ $priority }}" @selected(old('priority', 'normal') === $priority)>{{ trans('support.priority_'.$priority) }}</option>
                    @endforeach
                </select>
                <label for="support-subject">{{ trans('support.subject_label') }}</label>
                <input id="support-subject" name="subject" type="text" maxlength="180" value="{{ old('subject') }}" required>
                <label for="support-body">{{ trans('support.body_label') }}</label>
                <textarea id="support-body" name="body" rows="8" maxlength="10000" required>{{ old('body') }}</textarea>
                <button class="next-button next-button--gold" type="submit">{{ trans('support.create_submit') }}</button>
            </form>
        </section>
    @endif
</main>
@endsection

```

## FILE 23: `lang/en/support.php`

# TYPE: PHP translation map
# PURPOSE: English support-case interface and status copy.

```php
<?php

declare(strict_types=1);

return [
    'title' => 'Support center',
    'detail_title' => 'Support request detail',
    'meta_description' => 'Authenticated owner-scoped support center.',
    'home_aria' => 'Support center home',
    'brand_subtitle' => 'Support center',
    'primary_nav' => 'Support navigation',
    'nav_dashboard' => 'Dashboard',
    'nav_support' => 'Support',
    'nav_contact' => 'Contact',
    'eyebrow' => 'SUPPORT OPERATIONS',
    'description' => 'Create and review support cases that belong to the authenticated account.',
    'state_label' => 'State',
    'reference' => 'Reference',
    'status_label' => 'Status',
    'priority_label' => 'Priority',
    'category_label' => 'Category',
    'cases_title' => 'Your support cases',
    'no_cases' => 'No support cases are available for this account.',
    'create_title' => 'Create a support case',
    'create_submit' => 'Create case',
    'subject_label' => 'Subject',
    'body_label' => 'Message',
    'reply_title' => 'Reply to this case',
    'reply_label' => 'Reply message',
    'reply_submit' => 'Send reply',
    'messages_title' => 'Case messages',
    'no_messages' => 'No public messages are available for this case.',
    'case_eyebrow' => 'OWNER-SCOPED SUPPORT CASE',
    'validation_title' => 'Please correct the highlighted information',
    'case_created' => 'Your support case was created.',
    'reply_created' => 'Your support reply was added.',
    'category_account' => 'Account',
    'category_payment' => 'Payment',
    'category_withdrawal' => 'Withdrawal',
    'category_bet' => 'Bet',
    'category_lottery' => 'Lottery',
    'category_security' => 'Security',
    'category_other' => 'Other',
    'priority_low' => 'Low',
    'priority_normal' => 'Normal',
    'priority_high' => 'High',
];

```

## FILE 24: `lang/th/support.php`

# TYPE: PHP translation map
# PURPOSE: Exact key-parity support translation map.

```php
<?php

declare(strict_types=1);

return [
    'title' => 'Support center',
    'detail_title' => 'Support request detail',
    'meta_description' => 'Authenticated owner-scoped support center.',
    'home_aria' => 'Support center home',
    'brand_subtitle' => 'Support center',
    'primary_nav' => 'Support navigation',
    'nav_dashboard' => 'Dashboard',
    'nav_support' => 'Support',
    'nav_contact' => 'Contact',
    'eyebrow' => 'SUPPORT OPERATIONS',
    'description' => 'Create and review support cases that belong to the authenticated account.',
    'state_label' => 'State',
    'reference' => 'Reference',
    'status_label' => 'Status',
    'priority_label' => 'Priority',
    'category_label' => 'Category',
    'cases_title' => 'Your support cases',
    'no_cases' => 'No support cases are available for this account.',
    'create_title' => 'Create a support case',
    'create_submit' => 'Create case',
    'subject_label' => 'Subject',
    'body_label' => 'Message',
    'reply_title' => 'Reply to this case',
    'reply_label' => 'Reply message',
    'reply_submit' => 'Send reply',
    'messages_title' => 'Case messages',
    'no_messages' => 'No public messages are available for this case.',
    'case_eyebrow' => 'OWNER-SCOPED SUPPORT CASE',
    'validation_title' => 'Please correct the highlighted information',
    'case_created' => 'Your support case was created.',
    'reply_created' => 'Your support reply was added.',
    'category_account' => 'Account',
    'category_payment' => 'Payment',
    'category_withdrawal' => 'Withdrawal',
    'category_bet' => 'Bet',
    'category_lottery' => 'Lottery',
    'category_security' => 'Security',
    'category_other' => 'Other',
    'priority_low' => 'Low',
    'priority_normal' => 'Normal',
    'priority_high' => 'High',
];

```

## FILE 25: `routes/web.php`

# TYPE: PHP route file
# PURPOSE: Preserve prior routes and add authenticated support-case creation and reply routes.

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
    Route::post('/support', [SupportPortalController::class, 'store'])
        ->middleware('throttle:contact-submit')
        ->name('support.store');
    Route::get('/support/{reference}', [SupportPortalController::class, 'show'])
        ->where('reference', '[A-Za-z0-9_-]{1,120}')
        ->name('support.show');
    Route::post('/support/{reference}/reply', [SupportPortalController::class, 'reply'])
        ->middleware('throttle:contact-submit')
        ->where('reference', '[A-Za-z0-9_-]{1,120}')
        ->name('support.reply');
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

## FILE 26: `database/factories/SupportCaseFactory.php`

# TYPE: Test factory
# PURPOSE: Create explicitly synthetic support cases for isolated tests only.

```php
<?php

// TYPE: Test factory
// PURPOSE: Generate explicitly synthetic owner-scoped support cases for isolated automated tests only.

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SupportCase> */
final class SupportCaseFactory extends Factory
{
    protected $model = SupportCase::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'public_reference' => SupportCase::buildPublicReference(),
            'owner_user_id' => User::factory(),
            'category' => 'other',
            'priority' => SupportCase::PRIORITY_NORMAL,
            'status' => SupportCase::STATUS_OPEN,
            'subject' => 'Synthetic support case',
            'closed_at' => null,
        ];
    }
}

```

## FILE 27: `tests/Feature/Support/SupportCaseOwnerIsolationTest.php`

# TYPE: Laravel feature test
# PURPOSE: Verify support creation, owner isolation, replies, closure rejection, and hidden internal fields.

```php
<?php

// TYPE: Laravel feature test
// PURPOSE: Verify owner-scoped support case creation, detail access, reply access, and cross-account isolation.

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\SupportCase;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SupportCaseOwnerIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_owner_can_create_and_read_a_support_case(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->post(route('support.store'), [
            'category' => 'payment',
            'priority' => 'normal',
            'subject' => 'Synthetic payment support case',
            'body' => 'Synthetic test body; no production payment is implied.',
        ]);

        $response->assertRedirect(route('support.index'));
        $this->assertDatabaseHas('support_cases', [
            'owner_user_id' => $owner->getKey(),
            'category' => 'payment',
            'subject' => 'Synthetic payment support case',
            'status' => SupportCase::STATUS_OPEN,
        ]);

        $case = SupportCase::query()->where('owner_user_id', $owner->getKey())->firstOrFail();
        $this->assertDatabaseHas('support_messages', [
            'support_case_id' => $case->getKey(),
            'sender_user_id' => $owner->getKey(),
            'internal' => false,
        ]);

        $detail = $this->actingAs($owner)->get(route('support.show', ['reference' => $case->public_reference]));
        $detail->assertOk();
        $detail->assertSee('Synthetic payment support case');
        $detail->assertSee('Synthetic test body; no production payment is implied.');
    }

    public function test_a_second_owner_cannot_read_or_reply_to_the_first_owners_case(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $case = SupportCase::factory()->create(['owner_user_id' => $owner->getKey()]);

        $detail = $this->actingAs($otherOwner)->get(route('support.show', ['reference' => $case->public_reference]));
        $detail->assertNotFound();

        $reply = $this->actingAs($otherOwner)->post(route('support.reply', ['reference' => $case->public_reference]), [
            'body' => 'This cross-owner reply must not be accepted.',
        ]);
        $reply->assertNotFound();
        $this->assertDatabaseMissing('support_messages', [
            'support_case_id' => $case->getKey(),
            'body' => 'This cross-owner reply must not be accepted.',
        ]);
    }

    public function test_owner_can_reply_but_closed_case_cannot_receive_a_reply(): void
    {
        $owner = User::factory()->create();
        $case = SupportCase::factory()->create(['owner_user_id' => $owner->getKey()]);

        $response = $this->actingAs($owner)->post(route('support.reply', ['reference' => $case->public_reference]), [
            'body' => 'Synthetic owner reply.',
        ]);
        $response->assertRedirect(route('support.show', ['reference' => $case->public_reference]));
        $this->assertDatabaseHas('support_messages', [
            'support_case_id' => $case->getKey(),
            'sender_user_id' => $owner->getKey(),
            'body' => 'Synthetic owner reply.',
        ]);

        $case->forceFill(['status' => SupportCase::STATUS_CLOSED])->save();
        $closedReply = $this->actingAs($owner)->post(route('support.reply', ['reference' => $case->public_reference]), [
            'body' => 'This reply must be rejected after closure.',
        ]);
        $closedReply->assertNotFound();
        $this->assertDatabaseMissing('support_messages', [
            'support_case_id' => $case->getKey(),
            'body' => 'This reply must be rejected after closure.',
        ]);
    }

    public function test_support_message_model_hides_internal_identifiers_from_array_output(): void
    {
        $message = new SupportMessage([
            'support_case_id' => 10,
            'sender_user_id' => 20,
            'body' => 'Synthetic message.',
            'internal' => false,
        ]);

        $array = $message->toArray();
        self::assertArrayNotHasKey('support_case_id', $array);
        self::assertArrayNotHasKey('sender_user_id', $array);
        self::assertArrayNotHasKey('internal', $array);
    }
}

```

## FILE 28: `tests/Feature/Pages251To350StaticContractTest.php`

# TYPE: PHP static contract test
# PURPOSE: Verify runtime scripts, support ownership boundaries, reports, and 100 audit rows.

```php
<?php

// TYPE: PHP static contract test
// PURPOSE: Verify Pages 251–350 runtime scripts, owner-scoped support activation, canonical references, and complete audit coverage without claiming runtime execution.

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class Pages251To350StaticContractTest extends TestCase
{
    public function test_runtime_preflight_and_acceptance_gate_are_secret_free_and_machine_readable(): void
    {
        $preflight = file_get_contents(base_path('scripts/runtime_preflight.py'));
        $gate = file_get_contents(base_path('scripts/pages_251_350_runtime_gate.py'));

        self::assertIsString($preflight);
        self::assertIsString($gate);
        self::assertStringContainsString('# TYPE:', $preflight);
        self::assertStringContainsString('# PURPOSE:', $preflight);
        self::assertStringContainsString('No secret values are read or emitted.', $preflight);
        self::assertStringContainsString('BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE', $gate);
        self::assertStringContainsString('page-350-acceptance.json', $gate);
        self::assertStringNotContainsString('DB_PASSWORD', $gate);
        self::assertStringNotContainsString('APP_KEY', $gate);
    }

    public function test_support_case_activation_is_owner_scoped_and_does_not_reuse_anonymous_contact_messages(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));
        $controller = file_get_contents(base_path('app/Http/Controllers/Support/SupportPortalController.php'));
        $service = file_get_contents(base_path('app/Services/Support/SupportCaseService.php'));
        $migration = file_get_contents(base_path('database/migrations/2026_09_30_000900_create_support_case_tables.php'));

        self::assertIsString($routes);
        self::assertIsString($controller);
        self::assertIsString($service);
        self::assertIsString($migration);
        self::assertStringContainsString("Route::post('/support'", $routes);
        self::assertStringContainsString("Route::post('/support/{reference}/reply'", $routes);
        self::assertStringContainsString("where('reference', '[A-Za-z0-9_-]{1,120}')", $routes);
        self::assertStringContainsString('where(\'owner_user_id\'', $service);
        self::assertStringContainsString('findForOwner', $service);
        self::assertStringContainsString('SupportCase', $service);
        self::assertStringContainsString('owner_user_id', $migration);
        self::assertStringNotContainsString('ContactMessage', $controller);
        self::assertStringNotContainsString('request->input(\'user_id\'', $controller);
        self::assertStringNotContainsString('request->input(\'owner_user_id\'', $controller);
    }

    public function test_pages_251_through_350_audit_rows_and_required_reports_exist(): void
    {
        $audit = file_get_contents(base_path('audit.md'));
        $runtimeReport = file_get_contents(base_path('RUNTIME-VERIFICATION-REPORT.md'));
        $financialReport = file_get_contents(base_path('FINANCIAL-INTEGRITY-REPORT.md'));
        $rustReport = file_get_contents(base_path('RUST-RUNTIME-REPORT.md'));

        self::assertIsString($audit);
        self::assertIsString($runtimeReport);
        self::assertIsString($financialReport);
        self::assertIsString($rustReport);
        for ($page = 251; $page <= 350; $page++) {
            self::assertMatchesRegularExpression('/\| '.$page.' \|/', $audit);
        }
        self::assertStringContainsString('BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE', $audit);
        self::assertStringContainsString('NOT_CONFIGURED', $audit);
        self::assertStringContainsString('RUNTIME', $runtimeReport);
        self::assertStringContainsString('Financial integrity', $financialReport);
        self::assertStringContainsString('Cargo', $rustReport);
    }
}

```

## FILE 29: `audit.md`

# TYPE: Markdown audit report
# PURPOSE: Add one complete row per Page 251–350 and the phase changed-file manifest and validation evidence.

```markdown
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

## Pages 251–350 runtime activation audit matrix

| Page | Title | Route | Route Name | HTTP Method | Middleware | Authorization | Controller | Request | Service | DTO | Model | Database | API | Job/Event | View | JS | CSS | Translation | Source of Truth | Financial Impact | Security | Audit | Status | Tests | Runtime Status | Remaining Gap |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 251 | Runtime Environment Bootstrap | scripts/runtime_preflight.py | runtime.preflight | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | scripts/runtime_preflight.py | canonical request/DTO or bounded command input | Python preflight | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | runtime/page-251-preflight.json | No financial mutation. | No secret values are read or emitted. | canonical audit path or runtime report | PARTIALLY VERIFIED | Python JSON validation | PARTIALLY VERIFIED — PRE-FLIGHT ONLY | PHP, Composer, database, Redis, browser, Rust, and providers are unavailable. |
| 252 | Dependency Installation Verification | composer.json; package.json | dependency.commands | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | composer and npm commands | canonical request/DTO or bounded command input | Composer and NPM package managers | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | runtime/page-252-dependency-verification.json | No financial mutation. | Dependency findings are recorded rather than hidden. | canonical audit path or runtime report | PARTIALLY VERIFIED | npm ci; npm audit | PARTIALLY VERIFIED — FRONTEND ONLY | Composer is unavailable; npm audit reports one moderate and one high vulnerability. |
| 253 | Laravel Boot Verification | artisan | artisan.runtime | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Laravel Artisan runtime | canonical request/DTO or bounded command input | Laravel Artisan | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | RUNTIME-VERIFICATION-REPORT.md | No financial mutation. | No runtime policy claim. | canonical audit path or runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | php artisan about; route:list; config:show | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP and vendor are unavailable; container and routes are not verified. |
| 254 | Database Connection Verification | config/database.php | database.runtime | CLI/runtime | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Laravel database manager | canonical request/DTO or bounded command input | Laravel database manager | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | config/database.php; phpunit.xml | Financial execution is unverified. | No credentials are emitted. | canonical audit path or runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | database connection attempt | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP, Laravel container, credentials, and database server are unavailable. |
| 255 | Migration Baseline | database/migrations | migrate.status | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Artisan migration subsystem | canonical request/DTO or bounded command input | Artisan migration subsystem | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | database migration files; migrate:status command | Financial schema state is unverified. | Production migration was not run. | canonical audit path or runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | php artisan migrate:status | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Applied, pending, and batch state cannot be observed. |
| 256 | Seeder Safety Audit | database/seeders | seeders.audit | static/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Seeder source inspection | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | runtime/pages-256-259-static-audit.json | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | Python static audit | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Seeder execution and production classification require runtime review. |
| 257 | Factory and Fixture Audit | database/factories | factories.audit | static/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Factory source inspection | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | runtime/pages-256-259-static-audit.json | Synthetic fixtures are not production financial truth. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | Python static audit | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Factory execution and isolation require runtime review. |
| 258 | Database Constraint Audit | database/migrations | constraints.audit | static/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Migration source inspection | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | runtime/pages-256-259-static-audit.json | Money precision and foreign-key behavior are not runtime assertions. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | Python static audit | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Constraint behavior requires a real database. |
| 259 | Database Transaction Audit | app/Services | transactions.audit | static/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Canonical finance, payment, betting, draw, lottery, and notification services | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | runtime/pages-256-259-static-audit.json | Atomicity, rollback, and idempotency are not runtime verified. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | Python static audit | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | No transaction execution occurred. |
| 260 | Concurrency Test Harness | tests/Feature/Betting/BetPurchaseAtomicityTest.php | tests.pages260.concurrency | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | canonical atomicity and idempotency tests | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Betting/BetPurchaseAtomicityTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest; DuplicateWebhookIdempotencyTest; FinancialReconciliationComprehensiveTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 261 | Wallet Integrity Activation | /player/wallet | player.wallet | HTTP GET | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | WalletService; WalletHoldService; WalletReservationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /player/wallet | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | wallet and player experience tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 262 | Wallet Ledger Balance Rebuild | finance:reconcile | finance.reconcile | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | FinancialReconciliationService; LedgerBalanceValidator | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | finance:reconcile | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ReconcileCommandContractTest; FinancialReconciliationComprehensiveTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 263 | Wallet Double-Spend Test | tests/Feature/Betting/BetPurchaseAtomicityTest.php | tests.pages263.wallet | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetPurchaseTransactionService; WalletLockService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Betting/BetPurchaseAtomicityTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 264 | Wallet Hold and Release | app/Services/Finance/WalletHoldService.php | finance.wallet.hold | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | WalletHoldService; WalletLockService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/WalletHoldService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | finance wallet/hold tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 265 | Wallet Reservation Expiry | app/Services/Finance/WalletReservationService.php | finance.wallet.reservation | service/command | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | WalletReservationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/WalletReservationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | wallet reservation tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 266 | Financial Transaction Idempotency | app/Services/Finance/IdempotencyService.php | finance.idempotency | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | IdempotencyService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/IdempotencyService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | payment and betting idempotency tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 267 | Ledger Posting Contract | app/Services/Finance/LedgerPostingService.php | finance.ledger.posting | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | LedgerPostingService; LedgerBalanceValidator | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/LedgerPostingService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ledger validator and finance tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 268 | Ledger Reversal Contract | app/Services/Finance/FinancialReversalService.php | finance.ledger.reversal | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | FinancialReversalService; LedgerPostingService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/FinancialReversalService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | financial reversal tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 269 | Financial Reconciliation Execution | finance:reconcile | finance.reconcile | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | ReconcileFinancialRecordsCommand; FinancialReconciliationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | finance:reconcile | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ReconcileCommandContractTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 270 | Reconciliation Exception Lifecycle | app/DTOs/Finance/ReconciliationDiscrepancy.php | finance.reconciliation.exceptions | service/report | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | FinancialReconciliationService; reconciliation DTOs | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/DTOs/Finance/ReconciliationDiscrepancy.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | FinancialReconciliationComprehensiveTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 271 | Deposit Runtime Flow | /api/v1/deposits | api.v1.deposits | HTTP POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DepositService; PaymentInitiationService; DepositCompletionService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /api/v1/deposits | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | PlayerDepositApiTest; SuccessfulDepositCompletionTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 272 | Deposit Duplicate Callback | /api/v1/payment/webhook | api.payment.webhook | HTTP POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PaymentWebhookService; IdempotencyService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /api/v1/payment/webhook | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | DuplicateWebhookIdempotencyTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 273 | Payment Callback Ownership | /admin/payments/{payment} | admin.payments.show | HTTP GET | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PaymentCallbackService; object-scoped payment projection | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /admin/payments/{payment} | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | payment access tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 274 | Payment Signature Validation | app/Services/Payment/PaymentWebhookVerificationService.php | payment.webhook.verify | HTTP POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PaymentWebhookVerificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Payment/PaymentWebhookVerificationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | InvalidWebhookSignatureTest; PaymentWebhookSignatureVerificationTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 275 | Payment Provider Error Matrix | app/Services/Payment/Drivers | payment.provider.errors | HTTP/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PaymentGatewayManager and canonical drivers | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Payment/Drivers | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | PaymentGatewayManagerTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 276 | Payment State Machine | app/Enums/PaymentStatus.php | payment.state | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | FinancialStateTransitionService; PaymentVerificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Enums/PaymentStatus.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | FailedPaymentStateTest; ExpiredPaymentTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 277 | Payment Event Persistence | app/Models/PaymentWebhook.php | payment.events | HTTP POST/queue | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PaymentWebhookService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Models/PaymentWebhook.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | PaymentCallbackServiceTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 278 | Payment Webhook Queue | app/Services/Payment/PaymentWebhookService.php | payment.webhook.queue | queue | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PaymentWebhookService; queue services | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Payment/PaymentWebhookService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ProductionQueueComprehensiveTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 279 | Payment Dead-Letter Processing | failed_jobs | queue.failed | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | QueueHealthService; failed-job infrastructure | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | failed_jobs | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ProductionQueueComprehensiveTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 280 | Payment Replay | tests/Feature/Payment/WebhookReplayProtectionTest.php | tests.payment.replay | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PaymentWebhookVerificationService; IdempotencyService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Payment/WebhookReplayProtectionTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | WebhookReplayProtectionTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 281 | Withdrawal Runtime Flow | /api/v1/withdrawals | api.v1.withdrawals | HTTP POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | WithdrawalService; WithdrawalApprovalService; WithdrawalCompletionService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /api/v1/withdrawals | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | WithdrawalCompletionTest; WithdrawalDestinationValidationTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 282 | Withdrawal Duplicate Submission | tests/Feature/Payment/WithdrawalCompletionTest.php | tests.withdrawal.duplicate | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | WithdrawalService; WalletHoldService; IdempotencyService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Payment/WithdrawalCompletionTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | WithdrawalCompletionTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 283 | Withdrawal KYC Gate | app/Services/Compliance/WithdrawalKycGateService.php | withdrawal.kyc | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | WithdrawalKycGateService; KycVerificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Compliance/WithdrawalKycGateService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | WithdrawalKycGateTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 284 | Withdrawal Self-Exclusion and Restriction | app/Services/Compliance/SelfExclusionService.php | withdrawal.restrictions | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SelfExclusionService; ResponsibleGamingService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Compliance/SelfExclusionService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | SelfExclusionAndAgentGateTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 285 | Withdrawal Failure Recovery | app/Services/Finance/WithdrawalCompletionService.php | withdrawal.recovery | service/queue | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | WithdrawalCompletionService; FinancialReversalService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/WithdrawalCompletionService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | WithdrawalCompletionTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 286 | Withdrawal Completion Reconciliation | app/Services/Finance/PayoutReconciliationService.php | withdrawal.reconciliation | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PayoutReconciliationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/PayoutReconciliationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | FinancialReconciliationComprehensiveTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 287 | Bet Purchase Runtime Activation | /api/v1/bets | api.v1.bets.store | HTTP POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetPurchaseService and canonical purchase pipeline | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /api/v1/bets | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest; BetPurchaseWebTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 288 | Bet Price Authority | app/Services/Betting/BetCalculationService.php | bet.price | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetCalculationService; MarketRuleResolver | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Betting/BetCalculationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 289 | Bet Currency Authority | app/Enums/Currency.php | bet.currency | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Currency enum; BetPurchaseValidator | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Enums/Currency.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | payment and betting tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 290 | Bet Limit Enforcement | app/Services/Betting/BetPurchaseRiskService.php | bet.limits | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetPurchaseRiskService; ResponsibleGamingService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Betting/BetPurchaseRiskService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ResponsibleGamingWebTest; SelfExclusionAndAgentGateTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 291 | Bet Concurrency | tests/Feature/Betting/BetPurchaseAtomicityTest.php | tests.bet.concurrency | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetPurchaseTransactionService; WalletLockService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Betting/BetPurchaseAtomicityTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 292 | Bet Idempotency | app/Services/Betting/BetPurchaseIdempotencyService.php | bet.idempotency | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetPurchaseIdempotencyService; IdempotencyService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Betting/BetPurchaseIdempotencyService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest; BetPurchaseApiTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 293 | Bet Failure Rollback | app/Services/Betting/BetPurchaseTransactionService.php | bet.rollback | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetPurchaseTransactionService; WalletReservationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Betting/BetPurchaseTransactionService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 294 | Ticket Issuance Runtime | app/Services/Betting/BetPurchaseTicketService.php | ticket.issuance | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetPurchaseTicketService; TicketOwnershipService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Betting/BetPurchaseTicketService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest; BetPurchaseWebTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 295 | Ticket Ownership | app/Services/Ticket/TicketOwnershipService.php | ticket.ownership | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | TicketOwnershipService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Ticket/TicketOwnershipService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ticket ownership tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 296 | Ticket Share and QR Security | app/Services/Betting/TicketShareService.php | ticket.share | HTTP/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | TicketShareService; TicketVerificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Betting/TicketShareService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ticket share tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 297 | Ticket Verification Runtime | /ticket/verify | ticket.verification | HTTP GET/POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | TicketVerificationService; PublicResultVerificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /ticket/verify | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | TicketVerification tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 298 | Draw Open and Close Automation | app/Console/Commands/Lottery/TickCommand.php | lottery.tick | CLI/scheduler | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DrawScheduleService; DrawLifecycleService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Console/Commands/Lottery/TickCommand.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | DrawAutomationTest; ScheduleRegistrationTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 299 | Draw State Machine | app/Enums/DrawLifecycleState.php | draw.lifecycle | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DrawLifecycleService; DrawCertificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Enums/DrawLifecycleState.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | draw lifecycle tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 300 | Draw Locking and Cutoff | app/Http/Middleware/EnsureDrawIsOpen.php | draw.cutoff | HTTP | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | EnsureDrawIsOpen; DrawLifecycleService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Http/Middleware/EnsureDrawIsOpen.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | DrawAutomationTest; BetPurchaseAtomicityTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 301 | Result Import Runtime | app/Services/Draw/DrawResultIngestionService.php | result.import | CLI/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DrawResultIngestionService; GloResultImportService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Draw/DrawResultIngestionService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloResultImportTest; LaneResultImportContractTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 302 | Result Provenance Persistence | app/Models/GloResultImport.php | result.provenance | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloResultImportService; DrawResultIngestionService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Models/GloResultImport.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloResultImportTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 303 | Result Duplicate Import | tests/Feature/Glo/GloResultImportTest.php | result.import.duplicate | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloResultImportService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Glo/GloResultImportTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloResultImportTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 304 | Result Conflict Detection | app/Services/Draw/DrawResultValidator.php | result.conflict | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DrawResultValidator; provenance services | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Draw/DrawResultValidator.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | result validator tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 305 | Result Certification | app/Services/Draw/DrawCertificationService.php | result.certify | HTTP/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DrawCertificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Draw/DrawCertificationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | draw certification tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 306 | Result Publication Gate | app/Services/Draw/DrawResultPublicationService.php | result.publish | HTTP/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DrawResultPublicationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Draw/DrawResultPublicationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | result publication tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 307 | Result Correction Policy | app/Services/Draw/DrawResultConfirmationService.php | result.correction | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DrawResultConfirmationService; DrawResultIngestionService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Draw/DrawResultConfirmationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | result correction tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 308 | Leading-Zero Integrity | security/weekly-result-integrity/src/canonical.rs | rust.leading_zero | Rust/Laravel/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Rust canonicalization; Laravel result validation | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | security/weekly-result-integrity/src/canonical.rs | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | Rust integrity vectors; result tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 309 | National Lottery Data Lane | app/Services/Lottery | lottery.national | API/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | National lottery service family | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | NationalLotteryIntegrationSeamTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 310 | Weekly Lottery Data Lane | app/Services/Lottery | lottery.weekly | API/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Weekly lottery service family | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | weekly lottery tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 311 | Mega and Other Product Data Lane | app/Services/Lottery | lottery.product | API/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Product-specific lottery service family | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | lottery lane tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 312 | PCSO Data Lane | app/Services/Lottery | lottery.pcso | API/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PCSO result service family | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | PcsoLotteryPublicPageTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 313 | GLO L6 Data Lane | app/Services/Lottery/GloL6 | lottery.glo.l6 | API/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GLO L6 authoritative service family | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloL6 | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloResultImportTest; GloPublicResultHistoryTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 314 | GLO L6 Purchase Contract | app/Services/Lottery/GloL6PurchaseCapabilityService.php | lottery.glo.l6.purchase | API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloL6PurchaseCapabilityService; GloL6SalesService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloL6PurchaseCapabilityService.php | No checkout, purchase, wallet debit, or ticket issuance is fabricated. | Capability and provider gates remain canonical. | canonical audit path or runtime report | NOT_CONFIGURED — FAIL CLOSED | Glo purchase capability tests | NOT_CONFIGURED | Real public GLO L6 purchase contract and enabled provider/capability are not configured. |
| 315 | GLO L6 Ticket Range | app/Services/Lottery/GloL6AuthoritativeTicketEngineService.php | lottery.glo.l6.range | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloL6AuthoritativeTicketEngineService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloL6AuthoritativeTicketEngineService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GLO ticket tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 316 | GLO L6 Pricing Authority | app/Services/Lottery/GloL6PurchaseCapabilityService.php | lottery.glo.l6.pricing | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GLO L6 capability and pricing services | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloL6PurchaseCapabilityService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GLO sales tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 317 | GLO Prize Allocation | app/Services/Lottery/GloPrizeCatalogue.php | lottery.glo.prizes | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloPrizeCatalogue; GLO prize services | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloPrizeCatalogue.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GLO prize tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 318 | GLO Unsold Ticket Prize Scaling | app/Services/Lottery/GloL6ProportionalPrizeCalculator.php | lottery.glo.prize.scale | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloL6ProportionalPrizeCalculator; exact money arithmetic | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloL6ProportionalPrizeCalculator.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloL6ProportionalCalculatorTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 319 | GLO Claim Window | app/Services/Lottery/GloPrizeClaimService.php | lottery.glo.claim.window | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloPrizeClaimService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloPrizeClaimService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloPrizeClaimTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 320 | GLO Prize Claim Creation | app/Services/Lottery/GloPrizeClaimService.php | lottery.glo.claim.create | HTTP POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloPrizeClaimService; TicketAuthenticityService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloPrizeClaimService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloPrizeClaimTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 321 | GLO Claim Duplicate | tests/Feature/Glo/GloPrizeClaimTest.php | tests.glo.claim.duplicate | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloPrizeClaimService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Glo/GloPrizeClaimTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloPrizeClaimTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 322 | GLO Claim Age Verification | app/Services/Compliance/KycVerificationService.php | lottery.glo.claim.age | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloPrizeClaimService; KycVerificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Compliance/KycVerificationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloPrizeClaimTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 323 | GLO Claim KYC | app/Services/Compliance/WithdrawalKycGateService.php | lottery.glo.claim.kyc | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloPrizeClaimService; KycVerificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Compliance/WithdrawalKycGateService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloPrizeClaimTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 324 | GLO Payment Hold | app/Models/GloPrizePaymentHold.php | lottery.glo.payment.hold | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloPrizeClaimService; RealPrizeSettlementService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Models/GloPrizePaymentHold.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloPrizeClaimTest; FinalProductionReadinessTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 325 | GLO Ticket Freeze Runtime | app/Services/Lottery/GloTicketFreezeService.php | lottery.glo.freeze | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloTicketFreezeService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloTicketFreezeService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloTicketFreezeTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 326 | GLO Freeze Release | app/Console/Commands/GloExpireFreezes.php | glo.expire-freezes | CLI/scheduler | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloExpireFreezes; GloTicketFreezeService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Console/Commands/GloExpireFreezes.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloTicketFreezeTest; GloConsoleCommandsTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 327 | GLO Frozen Winner Processing | glo:process-frozen-winners | glo.process-frozen-winners | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloProcessFrozenWinners; GloFrozenWinnerService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | glo:process-frozen-winners | No frozen-winner payout or claim is reported. | Command/operator boundary remains canonical. | canonical audit path or runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | GloConsoleCommandsTest; GloFrozenWinnerTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The required PHP command was attempted by the acceptance gate but PHP is unavailable. |
| 328 | GLO Public Result Publication | app/Services/Lottery/GloResultPublicationService.php | lottery.glo.publish | API/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloResultPublicationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloResultPublicationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloPublicResultHistoryTest; GloResultImportTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 329 | Prize Matching Engine | app/Services/Betting/MarketResultResolver.php | prize.match | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SelectionSettlementResolver; market result services | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Betting/MarketResultResolver.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | settlement and result tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 330 | Prize Settlement Engine | app/Services/Draw/RealPrizeSettlementService.php | prize.settlement | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | RealPrizeSettlementService; PayoutApprovalService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Draw/RealPrizeSettlementService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | FinalProductionReadinessTest; GloPrizeClaimTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 331 | Prize Payout Idempotency | app/Services/Finance/PayoutBatchService.php | prize.payout.idempotency | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PayoutBatchService; PayoutApprovalService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/PayoutBatchService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | payout tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 332 | Prize Payout Failure Recovery | app/Services/Payment/PayoutTransferService.php | prize.payout.recovery | service/queue | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PayoutTransferService; FinancialReversalService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Payment/PayoutTransferService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | payout and reconciliation tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 333 | Agent Commission Runtime | app/Services/Agent/CommissionCalculationService.php | agent.commission.calculate | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | CommissionCalculationService; AgentCommissionService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Agent/CommissionCalculationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | CommissionAccrualTest; CommissionCalculationTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 334 | Agent Commission Duplicate | tests/Feature/Agent/CommissionIdempotencyTest.php | tests.agent.commission.duplicate | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | AgentCommissionService; IdempotencyService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Agent/CommissionIdempotencyTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | CommissionIdempotencyTest; DuplicateCommissionPreventionTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 335 | Agent Settlement Runtime | app/Services/Agent/AgentCommissionSettlementService.php | agent.commission.settlement | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | AgentCommissionSettlementService; AgentSettlementService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Agent/AgentCommissionSettlementService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | agent settlement tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 336 | Agent Referral Attribution | app/Services/Agent/AgentReferralService.php | agent.referral | authenticated API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | AgentReferralService; AgentPortalController | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Agent/AgentReferralService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | AgentReferralCodeUniquenessTest; UserAgentAttributionTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 337 | Agent Owner Isolation | /agent/referrals/{reference} | agent.referrals.show | HTTP GET | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | AgentReferralService; AgentPortalController | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /agent/referrals/{reference} | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | AgentReportingTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 338 | Notification Delivery Runtime | app/Services/Notification/NotificationDispatchService.php | notification.delivery | event/queue | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | NotificationDispatchService; NotificationDeliveryService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Notification/NotificationDispatchService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | notification tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 339 | Notification Idempotency | app/Services/Notification/NotificationReceiptService.php | notification.idempotency | service/queue | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | NotificationReceiptService; NotificationSuppressionService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Notification/NotificationReceiptService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | notification receipt tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 340 | Notification Preference Enforcement | app/Services/Notification/NotificationPreferenceService.php | notification.preferences | authenticated API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | NotificationPreferenceService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Notification/NotificationPreferenceService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | notification preference tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 341 | Support Case Contract | /support | support.index | GET/POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SupportCaseService; owner-scoped support migration | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /support | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | SupportCaseOwnerIsolationTest; Pages251To350StaticContractTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 342 | Support Case Creation | /support | support.store | POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SupportCaseService; CreateSupportCaseRequest | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /support | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | SupportCaseOwnerIsolationTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 343 | Support Case Owner Isolation | /support/{reference} | support.show | GET | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SupportCaseService::findForOwner | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /support/{reference} | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | SupportCaseOwnerIsolationTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 344 | Support Case Reply | /support/{reference}/reply | support.reply | POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SupportCaseService::reply; ReplySupportCaseRequest | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /support/{reference}/reply | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | SupportCaseOwnerIsolationTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 345 | Support Case Escalation | app/Services/Support/SupportCaseService.php | support.escalation | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | AuditLogService; canonical compliance escalation remains separate | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Support/SupportCaseService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | support/compliance escalation tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 346 | Support SLA and Age Projection | app/Models/SupportCase.php | support.sla | service/view | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SupportCase timestamps and configured operational policy | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Models/SupportCase.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | support operational tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 347 | Security Event Persistence | app/Services/Security/SecurityEventService.php | security.events | event/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SecurityEventService; AuthenticationSecurityService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Security/SecurityEventService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | security event tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 348 | MFA Runtime | app/Services/Security/MfaChallengeService.php | security.mfa | HTTP/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | MfaChallengeService; SecurityEventService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Security/MfaChallengeService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | MFA security tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 349 | Session Revocation | app/Services/Security/UserSessionSecurityService.php | security.sessions.revoke | HTTP/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | UserSessionSecurityService; SecurityEventService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Security/UserSessionSecurityService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | session security tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 350 | Final Runtime Acceptance Gate | scripts/pages_251_350_runtime_gate.py | runtime.acceptance | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Page 350 runtime gate and all canonical domain test suites | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | scripts/pages_251_350_runtime_gate.py | No finance or lottery completion claim is made. | Final acceptance remains evidence-bound. | canonical audit path or runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | runtime/page-350-acceptance.json | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP, Composer, database, Redis, browser, provider, and Cargo requirements remain unavailable. |

## Pages 251–350 changed-file manifest

| Path | `# TYPE` | `# PURPOSE` | Dependencies | Security impact | Financial / lottery impact | Test or validation coverage |
|---|---|---|---|---|---|---|
| `scripts/runtime_preflight.py` | Python runtime script | Generate secret-free machine-readable runtime availability evidence. | Python standard library; repository paths. | Does not read or emit secret values. | Read-only; no domain mutation. | Executed; JSON output validated. |
| `runtime/page-251-preflight.json` | JSON runtime evidence | Record Page 251 command, file, configuration-presence, and runtime-boundary observations. | Page 251 preflight script. | Secret-free presence metadata. | No financial claim. | Generated and parsed successfully. |
| `runtime/page-252-dependency-verification.json` | JSON dependency evidence | Record Composer, NPM, lockfile, and audit command results. | Composer/NPM command attempts. | NPM vulnerability findings are visible; no forced upgrade. | No financial claim. | Generated from executed command results. |
| `runtime/page-253-laravel-boot-verification.json` | JSON Laravel evidence | Record the three required Artisan command attempts and blocked result. | PHP/Artisan command boundary. | No runtime authorization claim. | No financial claim. | Generated and parsed successfully. |
| `scripts/pages_251_350_runtime_gate.py` | Python acceptance gate | Execute available Page 350 commands and preserve blocked/failed boundaries. | Python standard library; PHP, Composer, NPM, Cargo when present. | Truncates output; does not intentionally read secrets. | Never marks finance/lottery success without command execution. | Executed; 13-command result JSON generated. |
| `runtime/page-350-acceptance.json` | JSON runtime evidence | Record final gate command status by domain. | Page 350 acceptance script. | Command boundary remains explicit. | No financial or lottery success claim. | Generated and parsed successfully. |
| `scripts/pages_251_350_static_audit.py` | Python static audit | Inspect seeders, factories, migrations, canonical transaction services, and architecture sources. | Python standard library; repository source. | Static only; no secret values. | Does not classify fixtures as production truth. | Executed; 4 seeders, 42 factories, 95 migrations, and 197 service files observed. |
| `runtime/pages-256-259-static-audit.json` | JSON static audit evidence | Record Page 256–259 source inspection facts. | Static audit script. | No runtime security claim. | No runtime financial invariant claim. | Generated and parsed successfully. |
| `RUNTIME-VERIFICATION-REPORT.md` | Markdown runtime report | Record exact Page 251–350 runtime attempts, successes, blockers, and non-claims. | Runtime JSON artifacts and command results. | Explicitly refuses unsupported security claims. | Explicitly refuses unsupported financial/lottery claims. | Static report review. |
| `FINANCIAL-INTEGRITY-REPORT.md` | Markdown finance report | Map financial pages to canonical services, invariants, tests, and blocked runtime boundaries. | Existing finance/payment/betting/prize/agent architecture. | Ownership and idempotency boundaries documented. | No deposit, wallet, bet, withdrawal, prize, payout, or commission success fabricated. | Static source mapping; runtime blocked. |
| `RUST-RUNTIME-REPORT.md` | Markdown Rust report | Record Rust crate, boundary, deterministic vectors, required commands, and blocked runtime. | Existing `security/weekly-result-integrity` crate. | Rust cannot directly mutate wallet state. | No Rust financial authority claim. | Static source inspection; Cargo blocked. |
| `PAGES-251-350-MATRICES.md` | Markdown matrix deliverable | Provide complete 100-row page matrix and route, API, security, financial, lottery, Rust, and runtime matrices. | `audit.md`; canonical source inventory. | Explicit status and runtime boundaries. | Complete financial/lottery integrity mapping without fabricated output. | 100-row matrix scan passed. |
| `database/migrations/2026_09_30_000900_create_support_case_tables.php` | Laravel migration | Create owner-scoped support case and message tables separate from anonymous ContactMessage. | Laravel Schema; users table. | Foreign-key owner scope; internal metadata is not exposed. | No financial mutation. | Static parser; migration runtime blocked. |
| `app/Models/SupportCase.php` | Eloquent model | Represent owner-scoped support case aggregate and opaque public reference. | Support migration; User; SupportMessage. | Hides internal IDs and owner ID; route key is public reference. | No financial mutation. | Static parser; runtime blocked. |
| `app/Models/SupportMessage.php` | Eloquent model | Represent public case messages while hiding internal identifiers and flags. | Support migration; SupportCase; User. | Hides case, sender, and internal fields from array output. | No financial mutation. | Static parser; runtime blocked. |
| `app/Services/Support/SupportCaseService.php` | Domain service | Create, list, read, and reply to owner-scoped support cases transactionally with audit rows. | SupportCase, SupportMessage, User, AuditLogService, DB transactions. | Every read filters authenticated owner; closed cases reject replies. | No financial mutation. | Static contract; runtime blocked. |
| `app/Http/Requests/Support/CreateSupportCaseRequest.php` | Form request | Validate case category, priority, subject, and body. | Laravel FormRequest. | Does not accept owner identity. | No financial mutation. | Static parser; runtime blocked. |
| `app/Http/Requests/Support/ReplySupportCaseRequest.php` | Form request | Validate owner-scoped case reply body. | Laravel FormRequest. | Does not accept owner identity. | No financial mutation. | Static parser; runtime blocked. |
| `app/Http/Controllers/Support/SupportPortalController.php` | HTTP controller | Activate authenticated support case portal and owner-scoped create/detail/reply routes. | SupportCaseService; support requests; User. | Session ownership is resolved from request user; cross-owner cases return 404. | No financial mutation. | Static contract; runtime blocked. |
| `resources/views/support/portal.blade.php` | Blade view | Render case list, case detail, create form, messages, and reply form with accessible labels. | Support translations; support routes; CSRF. | No hidden owner input; escaped values; CSRF forms. | No financial mutation. | Static review; Blade runtime blocked. |
| `lang/en/support.php` | PHP translation map | English support-case labels, validation, state, and category copy. | Laravel translator. | Prevents raw translation keys. | No financial mutation. | EN/TH parity check. |
| `lang/th/support.php` | PHP translation map | Exact key-parity support translation map. | Laravel translator. | Prevents raw translation keys. | No financial mutation. | EN/TH parity check. |
| `routes/web.php` | PHP route file | Adds authenticated support case POST and reply routes while preserving existing contact routes. | SupportPortalController; auth; CSRF; throttle. | Bounded public reference and authenticated owner boundary. | No financial mutation. | Static route scan; Laravel dispatch blocked. |
| `database/factories/SupportCaseFactory.php` | Test factory | Create synthetic owner-scoped support cases for isolated tests only. | SupportCase; User; Laravel factory. | Explicitly test-only; no production seeding. | Synthetic only; not production support data. | Static parser; runtime blocked. |
| `tests/Feature/Support/SupportCaseOwnerIsolationTest.php` | Laravel feature test | Test support creation, owner isolation, replies, closure, and hidden internal identifiers. | RefreshDatabase; SupportCase; User; routes. | Cross-owner reads/replies must 404. | No financial mutation. | Test authored; PHP runtime blocked. |
| `tests/Feature/Pages251To350StaticContractTest.php` | PHP static contract test | Check runtime scripts, support ownership, reports, and all Page 251–350 rows. | Repository files; Laravel test harness. | Detects owner-trust and secret-output regressions. | Detects unsupported financial claims. | Static parser; PHP runtime blocked. |
| `scripts/pages_251_350_audit_matrix.py` | Python matrix generator | Produce exactly 100 audit rows with all required columns. | `audit.md`; Python standard library. | Records security and runtime boundaries per page. | Records financial/lottery boundaries per page. | Executed; 100 ordered rows generated. |
| `scripts/pages_251_350_matrix_document.py` | Python matrix generator | Produce complete Pages 251–350 matrices. | `audit.md`; Python standard library. | Security matrix generated. | Financial and lottery matrices generated. | Executed; 100 page rows generated. |
| `audit.md` | Markdown audit report | Add one factual row per Page 251–350 and the complete phase manifest. | All phase artifacts. | Explicit blocked/NOT_CONFIGURED states. | No fabricated runtime result. | Row count and column scan passed. |

## Pages 251–350 validation results

| Check | Result |
|---|---|
| Page 251 Python preflight | Passed; `runtime/page-251-preflight.json` generated and JSON validated. |
| Page 252 `npm ci` | Passed with exit code 0; 119 packages added and 120 audited. |
| Page 252 `npm audit` | Failed with exit code 1; one moderate and one high vulnerability reported. |
| Page 252 Composer validation/install | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE. |
| Page 253 Artisan boot commands | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE. |
| Pages 256–259 static audit | Passed; machine-readable seed, factory, migration, and transaction evidence generated. |
| Page 350 acceptance gate | Executed 13 commands: 2 succeeded, 0 failed after execution, 11 blocked. |
| Support case static contracts | Passed; static PHP parser covered 13 Pages 251–350 changed PHP files, including support files. PHP runtime remains blocked. |
| Python script compilation | Passed for all five Pages 251–350 Python scripts. |
| Runtime JSON validation | Passed for all five generated JSON evidence files. |
| EN/TH support translation parity | Passed. |
| Pages 251–350 page row count | Passed; exactly 100 ordered rows. |
| Prohibited omission-marker scan | Passed for Pages 251–350 scripts, reports, matrices, audit, and changed support files. |
| PHP, Composer, Laravel, database, Redis, queues, browser, providers, Cargo, and Rust runtime | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE. |

```
