#!/usr/bin/env python3
"""Real private Redis AOF/SIGKILL recovery against an acknowledged disposable SQL schema.

Never accepts an operator Redis endpoint. Each PHP case owns a fresh socket-only
Redis instance, hard-restarts that instance, then removes its own files.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import xml.etree.ElementTree as ET

from run_financial_concurrency import classify_cases, guarded_environment, inspect_server

TEST_PATH = "tests/Feature/Concurrency/RedisSettlementRecoveryTest.php"
CLASS = "Tests\\Feature\\Concurrency\\RedisSettlementRecoveryTest"
PHASES = {
    "test_redis_restart_retains_queued_work": "queued",
    "test_redis_restart_after_commit_releases_stale_client_lock": "committed",
    "test_redis_restart_before_ack_reconnects_live_worker": "released",
    "test_redis_restart_manual_approval_remains_uncredited": "committed",
    "test_redis_restart_and_worker_death_replays_reserved_job": "worker-death",
    "test_redis_queue_loss_requires_operator_handoff_without_duplicate_credit": "explicit-data-loss",
    "test_redis_unavailable_handoff_is_a_controlled_failure": "broker-unavailable",
}
EXPECTED_CASES = {(CLASS, name) for name in PHASES}


def validate_restarts(items):
    if not isinstance(items, list) or any(not isinstance(item, dict) for item in items):
        return False
    if any(not isinstance(item.get("class"), str) or not isinstance(item.get("name"), str) for item in items):
        return False
    if len(items) != len(PHASES) or {(item.get("class"), item.get("name")) for item in items} != EXPECTED_CASES:
        return False
    incarnations = []
    versions = set()
    for item in items:
        before, after, version = item.get("before"), item.get("after"), item.get("version")
        if (not isinstance(before, str) or not re.fullmatch(r"[0-9a-f]{40}", before)
                or not isinstance(after, str) or not re.fullmatch(r"[0-9a-f]{40}", after)
                or not isinstance(version, str) or not re.fullmatch(r"\d+\.\d+\.\d+", version)
                or before == after or type(item.get("signal")) is not int or item["signal"] != 9
                or item.get("phase") != PHASES[item["name"]] or item.get("recovered") is not True
                or item.get("aof_fsync") != "always" or item.get("cache_ttl") != "real wall time"):
            return False
        incarnations.extend([before, after])
        versions.add(version)
    return len(set(incarnations)) == len(incarnations) and len(versions) == 1


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument("--database", required=True)
    parser.add_argument("--connection", required=True)
    parser.add_argument("--socket", required=True)
    parser.add_argument("--username", required=True)
    parser.add_argument("--acknowledge-disposable-database", action="store_true")
    parser.add_argument("--repeats", type=int, default=3)
    parser.add_argument("--json", type=Path, required=True)
    args = parser.parse_args()
    try:
        env = guarded_environment(args, dict(os.environ))
        env.update({"CACHE_STORE": "array", "QUEUE_CONNECTION": "database", "LOG_CHANNEL": "null", "REDIS_URL": ""})
        root = args.root.resolve(strict=True)
        required = [TEST_PATH, "tests/Support/RedisSettlementProbe.php", "tests/Support/OwnsPrivateRedis.php", "vendor/bin/phpunit"]
        if any(not (root / path).is_file() for path in required) or not shutil.which("redis-server"):
            raise ValueError("Refused: restore exact sources/dependencies and install the local Redis server binary.")
        if (root / "bootstrap/cache/config.php").exists() or (root / ".env").exists():
            raise ValueError("Refused: cached configuration or a runtime .env may bypass the explicit test settings.")
        output = args.json.resolve()
        if output.suffix != ".json" or not output.is_relative_to((root / "evidence").resolve()):
            raise ValueError("Refused: JSON output must be in the repository evidence directory.")
        runtime = subprocess.run(["php", "-r", "exit(PHP_OS_FAMILY === 'Linux' && extension_loaded('redis') && function_exists('posix_kill') && function_exists('pcntl_signal') ? 0 : 1);"], cwd=root, env=env, timeout=10)
        if runtime.returncode:
            raise ValueError("Refused: Linux, PhpRedis, POSIX and pcntl are required.")
        server = inspect_server(root, env, False)
    except (ValueError, OSError, subprocess.TimeoutExpired, json.JSONDecodeError) as error:
        parser.error(str(error))
    report = {"scope": "private single-node Redis AOF hard restart and operator handoff, not remote failover or live certification",
              "laravel_connection": args.connection, "server": server, "expected_cases_per_iteration": len(EXPECTED_CASES),
              "clock": "application queue visibility may be accelerated; Redis cache TTL and AOF restart are real wall time",
              "production_verified": False, "live_payment_verified": False, "runs": [], "passed": False}
    output.parent.mkdir(parents=True, exist_ok=True)
    try:
        with tempfile.TemporaryDirectory(prefix="redis-recovery-") as scratch:
            for index in range(args.repeats):
                junit = Path(scratch) / f"run-{index + 1}.xml"
                witness = Path(scratch) / f"restarts-{index + 1}.jsonl"
                result = subprocess.run(["php", "vendor/bin/phpunit", TEST_PATH, "--log-junit", str(junit)], cwd=root,
                                        env=dict(env, REDIS_RECOVERY_WITNESS=str(witness)), text=True, capture_output=True, timeout=180)
                cases = list(ET.parse(junit).getroot().iter("testcase")) if junit.exists() else []
                outcomes, passed = classify_cases(cases, EXPECTED_CASES, result.returncode)
                restarts = [json.loads(line) for line in witness.read_text().splitlines()] if witness.exists() else []
                restarts_passed = validate_restarts(restarts)
                passed = passed and restarts_passed
                report["runs"].append({"iteration": index + 1, "exit_code": result.returncode, "cases": outcomes,
                                      "restart_witnesses": restarts, "restarts_passed": restarts_passed,
                                      "passed": passed, "stdout": result.stdout, "stderr": result.stderr})
                output.write_text(json.dumps(report, indent=2) + "\n")
                print(result.stdout, end="", flush=True)
                if result.stderr:
                    print(result.stderr, end="", flush=True)
                if not passed:
                    break
        report["passed"] = len(report["runs"]) == args.repeats and all(run["passed"] for run in report["runs"])
    except (OSError, subprocess.TimeoutExpired, ET.ParseError, json.JSONDecodeError) as error:
        report["failure"] = str(error)
    output.write_text(json.dumps(report, indent=2) + "\n")
    return 0 if report["passed"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
