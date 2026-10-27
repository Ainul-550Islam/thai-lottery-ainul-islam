# TYPE: Python command gate
# PURPOSE: Attempt the independent Pages 351–450 runtime commands and record exact command status without publishing command output or secrets.

from __future__ import annotations

import json
import shutil
import subprocess
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
COMMANDS: list[tuple[int, str, list[str], str]] = [
    (351, "php version", ["php", "-v"], "runtime"),
    (352, "php modules", ["php", "-m"], "runtime"),
    (352, "php ini", ["php", "--ini"], "runtime"),
    (353, "composer validate", ["composer", "validate", "--no-check-publish"], "composer"),
    (353, "composer install", ["composer", "install", "--no-interaction"], "composer"),
    (353, "composer platform requirements", ["composer", "check-platform-reqs"], "composer"),
    (354, "laravel about", ["php", "artisan", "about"], "laravel"),
    (355, "laravel route list", ["php", "artisan", "route:list"], "laravel"),
    (356, "laravel config show", ["php", "artisan", "config:show"], "laravel"),
    (357, "optimize clear", ["php", "artisan", "optimize:clear"], "cache"),
    (357, "config cache", ["php", "artisan", "config:cache"], "cache"),
    (357, "route cache", ["php", "artisan", "route:cache"], "cache"),
    (357, "view cache", ["php", "artisan", "view:cache"], "cache"),
    (358, "database probe through Laravel", ["php", "artisan", "about"], "database"),
    (359, "migration status", ["php", "artisan", "migrate:status"], "database"),
    (367, "redis version", ["redis-cli", "--version"], "redis"),
    (370, "queue failed listing", ["php", "artisan", "queue:failed"], "queue"),
    (375, "schedule list", ["php", "artisan", "schedule:list"], "scheduler"),
    (382, "controlled result import command inventory", ["php", "artisan", "list"], "lottery"),
    (420, "financial reconciliation dry run", ["php", "artisan", "finance:reconcile", "--dry-run", "--json"], "finance"),
    (447, "GLO frozen winner processor", ["php", "artisan", "glo:process-frozen-winners"], "glo"),
    (450, "Laravel test suite", ["php", "artisan", "test"], "tests"),
    (450, "frontend production build", ["npm", "run", "build"], "frontend"),
    (450, "cargo check", ["cargo", "check"], "rust"),
    (450, "cargo test", ["cargo", "test"], "rust"),
    (450, "cargo build release", ["cargo", "build", "--release"], "rust"),
]


def execute(page: int, label: str, command: list[str], domain: str) -> dict[str, Any]:
    observed_at = datetime.now(timezone.utc).replace(microsecond=0).isoformat()
    executable = shutil.which(command[0])
    base = {"page": page, "label": label, "command": command, "domain": domain, "observed_at_utc": observed_at}
    if executable is None:
        return {
            **base,
            "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
            "exit_code": None,
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
        return {
            **base,
            "status": "VERIFIED" if result.returncode == 0 else "FAILED",
            "exit_code": result.returncode,
            "output_bytes": len((result.stdout or "") + (result.stderr or "")),
        }
    except subprocess.TimeoutExpired:
        return {**base, "status": "FAILED", "exit_code": None, "reason": "180 second timeout"}
    except OSError as error:
        return {**base, "status": "FAILED", "exit_code": None, "reason": type(error).__name__}


def main() -> int:
    results = [execute(*command) for command in COMMANDS]
    report = {
        "type": "pages_351_450_command_gate",
        "purpose": "Command status evidence without command output or secret values.",
        "started_at_utc": results[0]["observed_at_utc"] if results else None,
        "finished_at_utc": datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
        "summary": {
            "command_count": len(results),
            "executed": sum(result["status"] == "VERIFIED" for result in results),
            "failed": sum(result["status"] == "FAILED" for result in results),
            "blocked": sum(result["status"].startswith("BLOCKED") for result in results),
        },
        "commands": results,
        "acceptance_boundary": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE" if any(result["status"].startswith("BLOCKED") for result in results) else "PARTIALLY VERIFIED",
        "secrets_policy": "Command output is not stored; no secret values are intentionally read or emitted.",
    }
    output = json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
    output_path = ROOT / "runtime" / "pages-351-450-command-results.json"
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(output, encoding="utf-8")
    print(output, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
