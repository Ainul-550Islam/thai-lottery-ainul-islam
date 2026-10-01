# TYPE: Python runtime preflight
# PURPOSE: Produce timestamped, secret-free Page 351–450 production-runtime dependency evidence.

from __future__ import annotations

import json
import os
import shutil
import subprocess
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]


def command_status(command: str, arguments: list[str] | None = None) -> dict[str, Any]:
    executable = shutil.which(command)
    if executable is None:
        return {"status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE", "executable": None, "version": None}
    try:
        result = subprocess.run(
            [executable, *(arguments or ["--version"])],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=15,
            check=False,
        )
        output = (result.stdout or result.stderr).strip().splitlines()
        return {
            "status": "VERIFIED" if result.returncode == 0 else "FAILED",
            "executable": executable,
            "version": output[0][:200] if output else None,
            "exit_code": result.returncode,
        }
    except (OSError, subprocess.SubprocessError) as error:
        return {"status": "FAILED", "executable": executable, "version": None, "error_type": type(error).__name__}


def path_state(relative: str) -> dict[str, Any]:
    path = ROOT / relative
    return {"path": relative, "exists": path.exists(), "file": path.is_file(), "directory": path.is_dir()}


def secret_free_env_presence() -> dict[str, Any]:
    keys = [
        "APP_ENV", "APP_DEBUG", "APP_URL", "DB_CONNECTION", "DB_HOST", "DB_PORT", "DB_DATABASE",
        "QUEUE_CONNECTION", "CACHE_STORE", "REDIS_HOST", "REDIS_PORT", "PAYMENT_DEFAULT_GATEWAY",
        "PRIZE_PAYOUT_SAFETY_MODE", "GLO_OFFICIAL_SOURCE_MODE",
    ]
    env_file = ROOT / ".env"
    example_file = ROOT / ".env.example"
    env_text = env_file.read_text(encoding="utf-8", errors="replace") if env_file.is_file() else ""
    example_text = example_file.read_text(encoding="utf-8", errors="replace") if example_file.is_file() else ""
    result = {}
    for key in keys:
        result[key] = {
            "process_present": key in os.environ,
            "env_file_declared": any(line.strip().startswith(f"{key}=") for line in env_text.splitlines()),
            "env_example_declared": any(line.strip().startswith(f"{key}=") for line in example_text.splitlines()),
        }
    return result


def main() -> int:
    report = {
        "type": "pages_351_450_runtime_dependency_preflight",
        "purpose": "Timestamped production-runtime dependency evidence without secrets.",
        "observed_at_utc": datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
        "commands": {
            "php": command_status("php"),
            "composer": command_status("composer"),
            "node": command_status("node"),
            "npm": command_status("npm"),
            "mysql": command_status("mysql"),
            "mariadb": command_status("mariadb"),
            "redis-cli": command_status("redis-cli"),
            "supervisorctl": command_status("supervisorctl"),
            "playwright": command_status("playwright"),
            "chromium": command_status("chromium"),
            "cargo": command_status("cargo"),
            "rustc": command_status("rustc"),
            "curl": command_status("curl"),
        },
        "required_paths": [
            path_state("composer.json"),
            path_state("composer.lock"),
            path_state("package.json"),
            path_state("package-lock.json"),
            path_state("artisan"),
            path_state("vendor"),
            path_state("node_modules"),
            path_state("config/database.php"),
            path_state("config/queue.php"),
            path_state("config/cache.php"),
            path_state("security/weekly-result-integrity/Cargo.toml"),
            path_state(".github/workflows/ci.yml"),
            path_state(".github/workflows/security.yml"),
        ],
        "environment_presence": secret_free_env_presence(),
        "runtime_claims": {
            "php_extensions": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
            "composer_platform": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
            "laravel_container": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
            "database": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
            "redis": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
            "queue": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
            "scheduler": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
            "browser": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
            "providers": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
            "rust": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
        },
        "secrets_policy": "Only presence metadata is emitted; secret values are never read or printed.",
    }
    output = json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
    output_path = ROOT / "runtime" / "page-351-preflight.json"
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(output, encoding="utf-8")
    print(output, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
