# TYPE: Python runtime preflight
# PURPOSE: Produce deterministic, secret-free machine-readable availability evidence for Pages 251–350.

from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]


def command_info(command: str, version_arguments: list[str] | None = None) -> dict[str, Any]:
    executable = shutil.which(command)
    if executable is None:
        return {"status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE", "executable": None, "version": None}

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
            "status": "VERIFIED" if result.returncode == 0 else "FAILED",
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
        return {"path": relative_path, "status": "NOT_VERIFIED"}
    return {
        "path": relative_path,
        "status": "VERIFIED" if os.access(path, os.W_OK) else "FAILED",
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
        "observed_at_utc": datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
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
