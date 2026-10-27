#!/usr/bin/env python3
"""Finite, explicitly disposable real-worker SIGKILL/restart acceptance.

No production topology, credentials in output or live payment activation.
Tests truncate the acknowledged local test schema. Never target live data.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import subprocess
import tempfile
import xml.etree.ElementTree as ET

from run_financial_concurrency import guarded_environment, inspect_server, classify_cases

TEST_PATH = "tests/Feature/Concurrency/SettlementWorkerRecoveryTest.php"
CLASS = "Tests\\Feature\\Concurrency\\SettlementWorkerRecoveryTest"
KILL_PHASES = {
    "test_recovery_kill_before_money_commit_rolls_back_and_retries": "money",
    "test_recovery_kill_after_checkpoint_commit_repairs_missing_handoff": "committed",
    "test_recovery_kill_after_enqueue_replays_without_duplicate_money": "enqueued",
    "test_recovery_kill_after_finalization_before_ack_is_idempotent": "finalized",
    "test_recovery_manual_approval_survives_commit_and_restart": "committed",
    "test_recovery_stale_overlap_does_not_exhaust_delivery_attempts": "committed",
    "test_recovery_entry_run_commit_repairs_handoff_despite_stale_lock": "started",
}
EXPECTED_CASES = {(CLASS, name) for name in KILL_PHASES} | {
    (CLASS, "test_recovery_deadline_is_persisted_and_finite"),
    (CLASS, "test_recovery_real_exceptions_still_exhaust_a_finite_budget"),
}


def validate_kills(items):
    if not isinstance(items, list) or any(not isinstance(x, dict) for x in items):
        return False
    if len(items) != len(KILL_PHASES) or {(x.get("class"), x.get("name")) for x in items} != {(CLASS, n) for n in KILL_PHASES}:
        return False
    for item in items:
        name = item.get("name")
        level = item.get("transaction_level")
        if (item.get("phase") != KILL_PHASES[name] or item.get("signal") != 9
                or item.get("cache") != "database" or item.get("recovered") is not True
                or type(item.get("pid")) is not int or item["pid"] <= 0
                or type(item.get("child_connection")) is not int or item["child_connection"] <= 0
                or type(level) is not int or (level <= 0 if item["phase"] == "money" else level != 0)):
            return False
    return len({x["pid"] for x in items}) == len(items) and len({x["child_connection"] for x in items}) == len(items)


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
        env.update({"CACHE_STORE": "database", "QUEUE_CONNECTION": "database", "LOG_CHANNEL": "null"})
        root = args.root.resolve(strict=True)
        for path in [TEST_PATH, "tests/Support/SettlementRecoveryProbe.php", "tests/Support/RunsServerFinancialRaces.php", "vendor/phpunit/phpunit/phpunit"]:
            if not (root / path).is_file():
                raise ValueError("Refused: restore exact recovery sources and locked PHP dependencies.")
        if (root / "bootstrap/cache/config.php").exists():
            raise ValueError("Refused: cached configuration may bypass the explicit test connection.")
        output = args.json.resolve()
        if output.suffix != ".json" or not output.is_relative_to((root / "evidence").resolve()):
            raise ValueError("Refused: JSON output must be in the repository evidence directory.")
        signals = subprocess.run(["php", "-r", "exit(PHP_OS_FAMILY === 'Linux' && function_exists('posix_kill') && function_exists('pcntl_signal') ? 0 : 1);"], cwd=root, env=env, timeout=10)
        if signals.returncode:
            raise ValueError("Refused: Linux POSIX/pcntl runtime is required for real worker death.")
        server = inspect_server(root, env, False, extra_tables=("cache", "cache_locks"))
    except (ValueError, OSError, subprocess.TimeoutExpired, json.JSONDecodeError) as error:
        parser.error(str(error))
    report = {"scope": "local process death/recovery with shared database cache; not multi-host or production certification",
              "laravel_connection": args.connection, "server": server,
              "expected_cases_per_iteration": len(EXPECTED_CASES), "clock": "accelerated application queue/cache clock; SIGSTOP/SIGKILL and SQL durability are real",
              "production_verified": False, "live_payment_verified": False, "runs": [], "passed": False}
    output.parent.mkdir(parents=True, exist_ok=True)
    try:
        with tempfile.TemporaryDirectory(prefix="settlement-recovery-") as scratch:
            for index in range(args.repeats):
                junit = Path(scratch) / f"run-{index + 1}.xml"
                witness = Path(scratch) / f"kills-{index + 1}.jsonl"
                run_env = dict(env, SETTLEMENT_RECOVERY_WITNESS=str(witness))
                command = ["php", "vendor/bin/phpunit", TEST_PATH, "--log-junit", str(junit)]
                result = subprocess.run(command, cwd=root, env=run_env, text=True, capture_output=True, timeout=180)
                cases = list(ET.parse(junit).getroot().iter("testcase")) if junit.exists() else []
                outcomes, passed = classify_cases(cases, EXPECTED_CASES, result.returncode)
                kills = [json.loads(line) for line in witness.read_text().splitlines()] if witness.exists() else []
                kills_passed = validate_kills(kills)
                passed = passed and kills_passed
                report["runs"].append({"iteration": index + 1, "exit_code": result.returncode,
                                      "cases": outcomes, "kill_witnesses": kills, "kills_passed": kills_passed,
                                      "passed": passed, "stdout": result.stdout, "stderr": result.stderr})
                output.write_text(json.dumps(report, indent=2) + "\n")
                print(result.stdout, end="", flush=True)
                if result.stderr:
                    print(result.stderr, end="", flush=True)
                if not passed:
                    break
        report["passed"] = len(report["runs"]) == args.repeats and all(r["passed"] for r in report["runs"])
    except (OSError, subprocess.TimeoutExpired, ET.ParseError, json.JSONDecodeError) as error:
        report["failure"] = str(error)
    output.write_text(json.dumps(report, indent=2) + "\n")
    return 0 if report["passed"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
