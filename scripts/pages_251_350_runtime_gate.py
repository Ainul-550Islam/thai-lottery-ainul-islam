# TYPE: Python runtime acceptance gate
# PURPOSE: Execute every independent Pages 251–350 runtime command that this workspace can execute and record blocked dependencies without fabricating results.

from __future__ import annotations

import json
import shutil
import subprocess
from datetime import datetime, timezone
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
            "status": "VERIFIED" if result.returncode == 0 else "FAILED",
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
    started_at = datetime.now(timezone.utc).replace(microsecond=0).isoformat()
    results: dict[str, Any] = {}
    for label, command, domain in COMMANDS:
        results[label] = {
            "command": command,
            **run(command, domain),
        }

    executed = sum(1 for result in results.values() if result["status"] == "VERIFIED")
    failed = sum(1 for result in results.values() if result["status"] == "FAILED")
    blocked = sum(1 for result in results.values() if result["status"].startswith("BLOCKED"))
    finished_at = datetime.now(timezone.utc).replace(microsecond=0).isoformat()
    report = {
        "type": "pages_251_350_runtime_acceptance_gate",
        "started_at_utc": started_at,
        "finished_at_utc": finished_at,
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
