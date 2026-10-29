#!/usr/bin/env python3
"""Run exact allow-listed financial race suites on an explicitly disposable server SQL store.

No deployment, credentials in output, or live payment activation.
The script issues no direct DDL, but PHPUnit bootstrap may migrate, seed and
truncate the acknowledged disposable test database. Never target live data.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import xml.etree.ElementTree as ET

LEGACY_PATHS = (
    "tests/Feature/Betting/BetPurchaseAtomicityTest.php",
    "tests/Feature/Settlement/SettlementSimulationTest.php",
)
TABLES = ("wallets", "number_limits", "bets", "bet_items", "draws", "tickets", "financial_transactions", "ledger_entries", "ledger_accounts", "agents", "agent_commissions", "audit_logs")

LEGACY_CASES = {
    ("Tests\\Feature\\Betting\\BetPurchaseAtomicityTest", "aa_lets_only_one_of_two_concurrent_purchases_spend_the_last_balance"),
    ("Tests\\Feature\\Betting\\BetPurchaseAtomicityTest", "ab_lets_only_one_of_two_concurrent_purchases_consume_the_last_capacity"),
    ("Tests\\Feature\\Betting\\BetPurchaseAtomicityTest", "ab2_replays_rather_than_duplicates_two_concurrent_identical_requests"),
    ("Tests\\Feature\\Settlement\\SettlementSimulationTest", "point_20_lets_only_one_of_two_concurrent_settlements_write"),
}


MONEY_PATHS = (
    "tests/Feature/Concurrency/WalletConversionServerRaceTest.php",
    "tests/Feature/Concurrency/SettlementServerRaceTest.php",
)
MONEY_CASES = {
    ("Tests\\Feature\\Concurrency\\WalletConversionServerRaceTest", "test_concurrent_" + name)
    for name in (
        "last_balance_allows_only_one_debit", "identical_thb_requests_replay_once",
        "identical_usd_requests_replay_once", "identical_bdt_requests_replay_once",
        "quote_reuse_with_different_keys_is_refused", "rolling_limit_is_rechecked_after_the_owner_lock",
        "opposite_directions_preserve_both_balances_and_fees", "reversal_retries_restore_money_only_once",
        "same_key_for_different_intents_does_not_move_money_twice",
    )
} | {
    ("Tests\\Feature\\Concurrency\\SettlementServerRaceTest", "test_concurrent_" + name)
    for name in (
        "run_start_returns_one_durable_identity", "chunked_start_and_inline_settlement_cannot_overlap",
        "reopen_and_finalizer_cannot_restore_a_completed_run_to_open", "duplicate_checkpoint_credits_a_winner_only_once",
        "checkpoint_keeps_manual_approval_obligations_uncredited",
        "serialized_workers_consume_duplicate_envelopes_without_duplicate_payout",
    )
}
WEBHOOK_PATHS = ("tests/Feature/Concurrency/WebhookObligationServerRaceTest.php",)
WEBHOOK_CASES = {
    ("Tests\\Feature\\Concurrency\\WebhookObligationServerRaceTest", "test_concurrent_" + name)
    for name in ("deposit_success_credits_once", "bkash_success_without_original_checkout_proof_cannot_credit", "withdrawal_success_debits_once",
                 "withdrawal_success_and_failure_preserve_one_terminal_state", "foreign_provider_cannot_race_a_valid_credit")
}
DISPATCH_PATHS = ("tests/Feature/Concurrency/WithdrawalDispatchServerRaceTest.php",)
DISPATCH_CASES = {
    ("Tests\\Feature\\Concurrency\\WithdrawalDispatchServerRaceTest", "test_concurrent_" + name)
    for name in ("pending_dispatch_has_one_external_invocation", "uncertain_dispatch_preserves_hold_and_sends_once")
}
RECONCILIATION_PATHS = ("tests/Feature/Concurrency/WithdrawalReconciliationServerRaceTest.php",)
RECONCILIATION_CASES = {
    ("Tests\\Feature\\Concurrency\\WithdrawalReconciliationServerRaceTest", "test_concurrent_" + name)
    for name in ("duplicate_review_debits_exactly_once", "conflicting_provider_evidence_has_one_terminal_result")
}
REFERENCE_PATHS = ("tests/Feature/Concurrency/WithdrawalReferenceServerRaceTest.php",)
REFERENCE_CASES = {
    ("Tests\\Feature\\Concurrency\\WithdrawalReferenceServerRaceTest", "test_concurrent_" + name)
    for name in ("callback_and_reconciliation_share_owner_across_wallets", "dispatch_and_callback_share_owner_across_wallets")
}
SUITES = {
    "legacy": (LEGACY_PATHS, LEGACY_CASES),
    "money": (MONEY_PATHS, MONEY_CASES),
    "webhooks": (WEBHOOK_PATHS, WEBHOOK_CASES),
    "dispatch": (DISPATCH_PATHS, DISPATCH_CASES),
    "reconciliation": (RECONCILIATION_PATHS, RECONCILIATION_CASES),
    "references": (REFERENCE_PATHS, REFERENCE_CASES),
    "all": (LEGACY_PATHS + MONEY_PATHS + WEBHOOK_PATHS + DISPATCH_PATHS + RECONCILIATION_PATHS + REFERENCE_PATHS, LEGACY_CASES | MONEY_CASES | WEBHOOK_CASES | DISPATCH_CASES | RECONCILIATION_CASES | REFERENCE_CASES),
}
TABLES += ("users", "wallet_conversions", "wallet_conversion_quotes", "settlement_runs", "payouts", "jobs", "failed_jobs", "deposits", "payments", "withdrawal_reconciliations", "withdrawal_reconciliation_references", "withdrawal_provider_references")


def classify_cases(cases, expected_cases, exit_code):
    outcomes = [{"class": case.get("class"), "name": case.get("name"),
                 "failed_or_skipped": any(child.tag in {"failure", "error", "skipped"} for child in case)} for case in cases]
    observed = {(item["class"], item["name"]) for item in outcomes}
    passed = exit_code == 0 and len(cases) == len(expected_cases) and observed == expected_cases and not any(item["failed_or_skipped"] for item in outcomes)
    return outcomes, passed


def validate_witnesses(witnesses, expected):
    observed = {(item.get("class"), item.get("name")) for item in witnesses}
    if observed != expected or len(witnesses) != len(expected):
        return False
    for item in witnesses:
        children = item.get("child_connections", [])
        reference_case = (item.get("class"), item.get("name")) in REFERENCE_CASES
        settlement_case = item.get("class") == "Tests\\Feature\\Concurrency\\SettlementServerRaceTest"
        settlement_draw_claim = settlement_case and item.get("name") in {
            "test_concurrent_run_start_returns_one_durable_identity",
            "test_concurrent_chunked_start_and_inline_settlement_cannot_overlap",
        }
        allowed_tables = ({"withdrawal_provider_references"} if reference_case else
                          {"draws"} if settlement_draw_claim else
                          {"settlement_runs"} if settlement_case else {"users", "draws", "wallets"})
        if reference_case:
            wallets = item.get("wallet_ids", [])
            if (len(wallets) != 2 or len(set(wallets)) != 2 or not all(isinstance(w, int) and w > 0 for w in wallets)
                    or item.get("lock_index") != "identity_key"
                    or not isinstance(item.get("blocker_row_id"), int) or item["blocker_row_id"] <= 0):
                return False
            if item.get("name") == "test_concurrent_dispatch_and_callback_share_owner_across_wallets":
                accepted = item.get("accepted_before_barrier", [])
                if len(accepted) != 2 or not all(isinstance(v, bool) for v in accepted) or accepted.count(True) != 1:
                    return False
        if (item.get("waiting_children") != 2 or len(children) != 2 or len(set(children)) != 2
                or not all(isinstance(value, int) and value > 0 for value in children)
                or item.get("parent_blocker") in children
                or not isinstance(item.get("parent_blocker"), int) or item["parent_blocker"] <= 0
                or item.get("table") not in allowed_tables):
            return False
    return True


def guarded_environment(args: argparse.Namespace, inherited: dict[str, str]) -> dict[str, str]:
    if not args.acknowledge_disposable_database:
        raise ValueError("Refused: acknowledge that the selected test fixtures truncate a disposable database.")
    if args.database != "thai_lottery_test":
        raise ValueError("Refused: only the exact dedicated database thai_lottery_test is allowed.")
    if args.connection not in {"mysql", "mariadb"}:
        raise ValueError("Refused: server row-lock probes require mysql/mariadb, never SQLite.")
    if not 1 <= args.repeats <= 10:
        raise ValueError("Refused: repeats must be between 1 and 10.")
    socket = Path(args.socket)
    if not socket.is_absolute() or not socket.exists() or not stat.S_ISSOCK(socket.stat().st_mode):
        raise ValueError("Refused: provide an existing absolute local Unix database socket.")
    if not args.username.strip():
        raise ValueError("Refused: an explicit dedicated test account is required.")
    env = dict(inherited)
    env.update({"APP_ENV": "testing", "DB_CONNECTION": args.connection, "DB_DATABASE": args.database,
                "DB_SOCKET": str(socket), "DB_USERNAME": args.username, "DB_URL": "", "DB_HOST": "localhost"})
    # Password comes only from the process environment and is never printed.
    env.setdefault("DB_PASSWORD", "")
    return env


def inspect_server(root: Path, env: dict[str, str], require_lock_introspection: bool = True, extra_tables: tuple[str, ...] = ()) -> dict:
    # All SQL identifiers are fixed constants; no arbitrary query/DB name input.
    code = r'''
try {
    $pdo = new PDO('mysql:unix_socket='.getenv('DB_SOCKET').';dbname=thai_lottery_test;charset=utf8mb4',
        getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tables = $pdo->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='thai_lottery_test'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $introspection = false;
    $version = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    if (getenv('FINANCIAL_RACE_INTROSPECTION') === '1') {
        if (stripos($version, 'mariadb') !== false) {
            $pdo->query('SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS')->fetchColumn();
            $pdo->query('SELECT COUNT(*) FROM information_schema.INNODB_TRX')->fetchColumn();
            $pdo->query('SELECT COUNT(*) FROM information_schema.INNODB_LOCKS')->fetchColumn();
        } else {
            // MySQL 8 moved row-lock visibility to performance_schema.
            foreach (['data_locks', 'data_lock_waits', 'threads'] as $table) {
                $pdo->query('SELECT COUNT(*) FROM performance_schema.'.$table)->fetchColumn();
            }
        }
        $introspection = true;
    }
    echo json_encode(['lock_introspection' => $introspection, 'database' => $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'pdo_driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME), 'server_version' => $version,
        'table_engines' => $tables]);
} catch (Throwable $e) { fwrite(STDERR, 'Disposable server preflight failed. Check the local socket/account and prepared schema.'); exit(1); }
'''
    probe_env = dict(env, FINANCIAL_RACE_INTROSPECTION="1" if require_lock_introspection else "0")
    probe = subprocess.run(["php", "-r", code], cwd=root, env=probe_env, text=True, capture_output=True, timeout=20)
    if probe.returncode:
        raise ValueError("Disposable server preflight failed; no tests were launched.")
    details = json.loads(probe.stdout)
    if details.get("database") != "thai_lottery_test" or details.get("pdo_driver") != "mysql":
        raise ValueError("Refused: PDO did not confirm the dedicated server SQL database.")
    engines = details.get("table_engines", {})
    if any(str(engines.get(table, "")).lower() != "innodb" for table in TABLES + extra_tables):
        raise ValueError("Refused: prepare the dedicated test schema; all selected financial tables must use InnoDB.")
    return {"lock_introspection": details["lock_introspection"], "database": details["database"], "pdo_driver": details["pdo_driver"],
            "server_version": details["server_version"], "table_engines": {table: engines[table] for table in TABLES + extra_tables}}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument("--database", required=True)
    parser.add_argument("--connection", required=True)
    parser.add_argument("--socket", required=True)
    parser.add_argument("--username", required=True)
    parser.add_argument("--acknowledge-disposable-database", action="store_true")
    parser.add_argument("--suite", choices=tuple(SUITES), default="all")
    parser.add_argument("--repeats", type=int, default=3)
    parser.add_argument("--json", type=Path, required=True)
    args = parser.parse_args()
    try:
        env = guarded_environment(args, dict(os.environ))
        root = args.root.resolve(strict=True)
        paths, expected_cases = SUITES[args.suite]
        if args.suite != "legacy" and any(not (root / path).is_file() for path in ["tests/Support/FinancialRaceProbe.php", "tests/Support/RunsServerFinancialRaces.php"]):
            raise ValueError("Refused: restore the guarded money-race harness.")
        if not (root / "vendor/phpunit/phpunit/phpunit").is_file() or any(not (root / path).is_file() for path in paths):
            raise ValueError("Refused: restore the repository's locked dependencies and selected test sources.")
        if (root / "bootstrap/cache/config.php").exists():
            raise ValueError("Refused: cached application configuration could bypass the explicit test connection.")
        output = args.json.resolve()
        evidence = (root / 'evidence').resolve()
        if output.suffix != '.json' or not output.is_relative_to(evidence):
            raise ValueError('Refused: JSON output must be inside this repository evidence directory.')
        args.json = output
        server = inspect_server(root, env, args.suite != "legacy")
    except (ValueError, OSError, subprocess.TimeoutExpired, json.JSONDecodeError) as error:
        parser.error(str(error))
    report = {"scope": "selected multi-process races, not production certification", "suite": args.suite,
              "laravel_connection": args.connection,
              "expected_cases_per_iteration": len(expected_cases), "server": server,
              "production_verified": False, "live_payment_verified": False, "runs": [], "passed": False}
    args.json.parent.mkdir(parents=True, exist_ok=True)
    try:
        with tempfile.TemporaryDirectory(prefix="financial-concurrency-") as scratch:
            for index in range(args.repeats):
                junit = Path(scratch) / f"run-{index + 1}.xml"
                witness_file = Path(scratch) / f"witness-{index + 1}.jsonl"
                run_env = dict(env, FINANCIAL_RACE_WITNESS=str(witness_file))
                command = ["php", "vendor/bin/phpunit", *paths, "--filter", "concurrent", "--log-junit", str(junit)]
                result = subprocess.run(command, cwd=root, env=run_env, text=True, capture_output=True, timeout=180)
                cases = list(ET.parse(junit).getroot().iter("testcase")) if junit.exists() else []
                outcomes, passed = classify_cases(cases, expected_cases, result.returncode)
                witnesses = [json.loads(line) for line in witness_file.read_text().splitlines()] if witness_file.exists() else []
                witnesses_passed = validate_witnesses(witnesses, expected_cases & (MONEY_CASES | WEBHOOK_CASES | DISPATCH_CASES | RECONCILIATION_CASES | REFERENCE_CASES))
                passed = passed and witnesses_passed
                report["runs"].append({"iteration": index + 1, "exit_code": result.returncode,
                                       "cases": outcomes, "lock_wait_witnesses": witnesses, "witnesses_passed": witnesses_passed, "passed": passed, "stdout": result.stdout, "stderr": result.stderr})
                args.json.write_text(json.dumps(report, indent=2) + "\n")
                print(result.stdout, end="", flush=True)
                if result.stderr:
                    print(result.stderr, end="", flush=True)
                if not passed:
                    break
        report["passed"] = len(report["runs"]) == args.repeats and all(run["passed"] for run in report["runs"])
    except (OSError, subprocess.TimeoutExpired, ET.ParseError, json.JSONDecodeError) as error:
        report["failure"] = str(error)
    args.json.write_text(json.dumps(report, indent=2) + "\n")
    return 0 if report["passed"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
