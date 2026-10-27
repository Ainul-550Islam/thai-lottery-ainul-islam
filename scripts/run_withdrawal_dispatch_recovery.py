#!/usr/bin/env python3
"""Kill real job-handling PHP processes around a committed outbound claim.

The gateway writes a private synthetic acceptance journal, never a payment API.
Uses and truncates only an explicitly acknowledged disposable local SQL schema.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import subprocess
import tempfile
import xml.etree.ElementTree as ET

from run_financial_concurrency import classify_cases, guarded_environment, inspect_server

TEST_PATH = "tests/Feature/Concurrency/WithdrawalDispatchRecoveryTest.php"
CLASS = "Tests\\Feature\\Concurrency\\WithdrawalDispatchRecoveryTest"
PHASES = {
    "test_death_after_claim_commit_does_not_become_permission_to_send": "claimed",
    "test_death_after_synthetic_acceptance_does_not_send_again_and_reconciles_once": "accepted",
}
EXPECTED_CASES = {(CLASS, name) for name in PHASES}


def validate_kills(items):
    if not isinstance(items, list) or any(not isinstance(item, dict) for item in items):
        return False
    if any(not isinstance(item.get("class"), str) or not isinstance(item.get("name"), str) for item in items):
        return False
    if len(items) != len(PHASES) or {(item.get("class"), item.get("name")) for item in items} != EXPECTED_CASES:
        return False
    for item in items:
        if (item.get("phase") != PHASES[item["name"]] or type(item.get("signal")) is not int or item["signal"] != 9
                or type(item.get("pid")) is not int or item["pid"] <= 0
                or type(item.get("connection")) is not int or item["connection"] <= 0
                or type(item.get("transaction_level")) is not int or item["transaction_level"] != 0
                or type(item.get("synthetic_acceptances")) is not int
                or item["synthetic_acceptances"] != (1 if item["phase"] == "accepted" else 0)
                or item.get("replayed_without_resend") is not True or item.get("reserved_until_reconciled") is not True):
            return False
    return len({item["pid"] for item in items}) == len(items) and len({item["connection"] for item in items}) == len(items)


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
        env.update({"CACHE_STORE": "array", "QUEUE_CONNECTION": "database", "LOG_CHANNEL": "null"})
        root = args.root.resolve(strict=True)
        required = [TEST_PATH, "tests/Support/WithdrawalDispatchProbe.php", "tests/Support/SyntheticWithdrawalGateway.php", "vendor/bin/phpunit"]
        if any(not (root / path).is_file() for path in required):
            raise ValueError("Refused: restore exact dispatch recovery sources and locked dependencies.")
        if (root / "bootstrap/cache/config.php").exists() or (root / ".env").exists():
            raise ValueError("Refused: runtime .env or cached configuration may override test settings.")
        output = args.json.resolve()
        if output.suffix != ".json" or not output.is_relative_to((root / "evidence").resolve()):
            raise ValueError("Refused: JSON output must stay inside repository evidence.")
        runtime = subprocess.run(["php", "-r", "exit(PHP_OS_FAMILY === 'Linux' && function_exists('posix_kill') && function_exists('pcntl_signal') && function_exists('fsync') ? 0 : 1);"], cwd=root, env=env, timeout=10)
        if runtime.returncode:
            raise ValueError("Refused: Linux, POSIX, pcntl and fsync are required.")
        server = inspect_server(root, env, False)
    except (ValueError, OSError, subprocess.TimeoutExpired, json.JSONDecodeError) as error:
        parser.error(str(error))
    report = {"scope": "real PHP process death and SQL claim durability; synthetic acceptance only, no provider transfer",
              "laravel_connection": args.connection, "server": server, "expected_cases_per_iteration": len(EXPECTED_CASES),
              "clock": "no accelerated lease or TTL; committed claims never expire into permission to resend",
              "production_verified": False, "live_payment_verified": False, "runs": [], "passed": False}
    output.parent.mkdir(parents=True, exist_ok=True)
    try:
        with tempfile.TemporaryDirectory(prefix="withdrawal-dispatch-") as scratch:
            for index in range(args.repeats):
                junit = Path(scratch) / f"run-{index + 1}.xml"
                witness = Path(scratch) / f"kills-{index + 1}.jsonl"
                result = subprocess.run(["php", "vendor/bin/phpunit", TEST_PATH, "--log-junit", str(junit)], cwd=root,
                                        env=dict(env, WITHDRAWAL_DISPATCH_WITNESS=str(witness)), text=True, capture_output=True, timeout=90)
                cases = list(ET.parse(junit).getroot().iter("testcase")) if junit.exists() else []
                outcomes, passed = classify_cases(cases, EXPECTED_CASES, result.returncode)
                kills = [json.loads(line) for line in witness.read_text().splitlines()] if witness.exists() else []
                kills_passed = validate_kills(kills)
                passed = passed and kills_passed
                report["runs"].append({"iteration": index + 1, "exit_code": result.returncode, "cases": outcomes,
                                      "kill_witnesses": kills, "kills_passed": kills_passed, "passed": passed,
                                      "stdout": result.stdout, "stderr": result.stderr})
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
