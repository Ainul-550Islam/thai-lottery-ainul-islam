"""Offline guard regressions: no server contact, migrations or financial writes."""
from __future__ import annotations

import argparse
import importlib.util
from pathlib import Path
import socket
import tempfile
import unittest
import xml.etree.ElementTree as ET

SPEC = importlib.util.spec_from_file_location("race_runner", Path(__file__).with_name("run_financial_concurrency.py"))
runner = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(runner)


class ConcurrencyRunnerGuards(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory()
        self.path = Path(self.scratch.name) / "test.sock"
        self.socket = socket.socket(socket.AF_UNIX)
        self.socket.bind(str(self.path))
        self.args = argparse.Namespace(acknowledge_disposable_database=True, database="thai_lottery_test",
                                       connection="mysql", repeats=3, socket=str(self.path), username="test_account")

    def tearDown(self):
        self.socket.close()
        self.scratch.cleanup()

    def test_reconciliation_profile_requires_both_owned_wallet_races(self):
        self.assertEqual(2, len(runner.RECONCILIATION_CASES))
        self.assertEqual(runner.RECONCILIATION_CASES, runner.SUITES["reconciliation"][1])
        self.assertTrue(runner.RECONCILIATION_CASES <= runner.SUITES["all"][1])
        self.assertFalse(runner.RECONCILIATION_CASES & (runner.MONEY_CASES | runner.WEBHOOK_CASES | runner.DISPATCH_CASES))

    def test_shared_reference_profile_requires_two_distinct_wallets_and_the_actual_identity_lock(self):
        self.assertEqual(2, len(runner.REFERENCE_CASES))
        self.assertEqual(runner.REFERENCE_CASES, runner.SUITES["references"][1])
        self.assertTrue(runner.REFERENCE_CASES <= runner.SUITES["all"][1])
        witnesses = [{"class": cls, "name": name, "table": "withdrawal_provider_references", "wallet_ids": [21, 22],
                      "blocker_row_id": 1, "lock_index": "identity_key", "accepted_before_barrier": [name.startswith("test_concurrent_dispatch"), False], "waiting_children": 2, "child_connections": [11, 12], "parent_blocker": 10}
                     for cls, name in runner.REFERENCE_CASES]
        self.assertTrue(runner.validate_witnesses(witnesses, runner.REFERENCE_CASES))
        for changed in [{"wallet_ids": []}, {"wallet_ids": [21, 21]}, {"wallet_ids": [0, 22]},
                        {"table": "wallets"}, {"blocker_row_id": 0}, {"lock_index": "PRIMARY"}]:
            copied = [dict(w) for w in witnesses]
            copied[0].update(changed)
            self.assertFalse(runner.validate_witnesses(copied, runner.REFERENCE_CASES))

    def test_shared_reference_dispatch_requires_real_prebarrier_acceptance_marker(self):
        cls, name = next(c for c in runner.REFERENCE_CASES if "dispatch_and_callback" in c[1])
        good = {"class": cls, "name": name, "table": "withdrawal_provider_references", "wallet_ids": [1, 2],
                "blocker_row_id": 1, "lock_index": "identity_key", "accepted_before_barrier": [True, False],
                "waiting_children": 2, "child_connections": [11, 12], "parent_blocker": 10}
        self.assertTrue(runner.validate_witnesses([good], {(cls, name)}))
        for bad in [[], [False, False], [True, True], [1, False]]:
            self.assertFalse(runner.validate_witnesses([dict(good, accepted_before_barrier=bad)], {(cls, name)}))

    def test_dispatch_profile_requires_both_owned_wallet_races(self):
        self.assertEqual(2, len(runner.DISPATCH_CASES))
        self.assertEqual(runner.DISPATCH_CASES, runner.SUITES["dispatch"][1])
        self.assertTrue(runner.DISPATCH_CASES <= runner.SUITES["all"][1])
        self.assertFalse(runner.DISPATCH_CASES & (runner.MONEY_CASES | runner.WEBHOOK_CASES))

    def test_webhook_profile_is_exact_and_included_in_all(self):
        self.assertEqual(4, len(runner.WEBHOOK_CASES))
        self.assertEqual(runner.WEBHOOK_CASES, runner.SUITES["webhooks"][1])
        self.assertTrue(runner.WEBHOOK_CASES <= runner.SUITES["all"][1])
        self.assertFalse(runner.WEBHOOK_CASES & runner.MONEY_CASES)

    def test_acknowledgement_is_mandatory(self):
        self.args.acknowledge_disposable_database = False
        with self.assertRaisesRegex(ValueError, "acknowledge"):
            runner.guarded_environment(self.args, {})

    def test_database_must_be_exact_test_name(self):
        for name in ["production", "other_test", "database/thai_lottery_test", "thai_lottery_test_backup"]:
            self.args.database = name
            with self.assertRaisesRegex(ValueError, "exact dedicated"):
                runner.guarded_environment(self.args, {})

    def test_sqlite_or_unknown_driver_is_refused(self):
        for driver in ["sqlite", "pgsql", "unknown"]:
            self.args.connection = driver
            with self.assertRaisesRegex(ValueError, "row-lock"):
                runner.guarded_environment(self.args, {})

    def test_repetition_budget_is_bounded(self):
        for count in [0, -1, 11]:
            self.args.repeats = count
            with self.assertRaisesRegex(ValueError, "between"):
                runner.guarded_environment(self.args, {})

    def test_regular_file_and_relative_path_are_not_database_sockets(self):
        regular = Path(self.scratch.name) / "not-a-socket"
        regular.write_text("fixture")
        for path in [str(regular), "relative.sock", str(regular) + "-missing"]:
            self.args.socket = path
            with self.assertRaisesRegex(ValueError, "Unix database socket"):
                runner.guarded_environment(self.args, {})

    def test_explicit_test_account_is_required(self):
        self.args.username = " "
        with self.assertRaisesRegex(ValueError, "dedicated test account"):
            runner.guarded_environment(self.args, {})

    def test_safe_environment_overrides_production_routing_without_printing_password(self):
        inherited = {"APP_ENV": "production", "DB_CONNECTION": "sqlite", "DB_DATABASE": "production",
                     "DB_URL": "do-not-use-production-url", "DB_PASSWORD": "secret-sentinel"}
        result = runner.guarded_environment(self.args, inherited)
        self.assertEqual(result["APP_ENV"], "testing")
        self.assertEqual(result["DB_CONNECTION"], "mysql")
        self.assertEqual(result["DB_DATABASE"], "thai_lottery_test")
        self.assertEqual(result["DB_URL"], "")
        self.assertEqual(result["DB_PASSWORD"], "secret-sentinel")
        self.assertEqual(inherited["DB_DATABASE"], "production")

    def test_supported_mariadb_connection_uses_same_explicit_database_guard(self):
        self.args.connection = "mariadb"
        result = runner.guarded_environment(self.args, {})
        self.assertEqual(result["DB_CONNECTION"], "mariadb")
        self.assertEqual(result["DB_PASSWORD"], "")


class RaceEvidenceGuards(unittest.TestCase):
    def cases(self, selected):
        return [ET.Element("testcase", {"class": cls, "name": name}) for cls, name in sorted(selected)]

    def witnesses(self):
        return [{"class": cls, "name": name, "table": "users", "waiting_children": 2,
                 "child_connections": [11, 12], "parent_blocker": 10} for cls, name in sorted(runner.MONEY_CASES)]

    def test_suite_inventory_is_exact_and_disjoint(self):
        self.assertEqual(len(runner.LEGACY_CASES), 4)
        self.assertEqual(len(runner.MONEY_CASES), 13)
        self.assertFalse(runner.LEGACY_CASES & runner.MONEY_CASES)
        self.assertEqual(len(runner.SUITES["all"][1]), 27)

    def test_all_exact_cases_are_required(self):
        expected = runner.SUITES["all"][1]
        cases = self.cases(expected)
        self.assertTrue(runner.classify_cases(cases, expected, 0)[1])
        self.assertFalse(runner.classify_cases(cases[:-1], expected, 0)[1])
        self.assertFalse(runner.classify_cases(cases + cases[:1], expected, 0)[1])

    def test_skip_error_failure_and_nonzero_exit_are_refused(self):
        for tag in ["skipped", "error", "failure"]:
            cases = self.cases(runner.LEGACY_CASES)
            ET.SubElement(cases[0], tag)
            self.assertFalse(runner.classify_cases(cases, runner.LEGACY_CASES, 0)[1])
        self.assertFalse(runner.classify_cases(self.cases(runner.LEGACY_CASES), runner.LEGACY_CASES, 1)[1])

    def test_wrong_test_identity_cannot_replace_a_required_race(self):
        cases = self.cases(runner.LEGACY_CASES)
        cases[0].set("name", "different_sequential_test")
        self.assertFalse(runner.classify_cases(cases, runner.LEGACY_CASES, 0)[1])

    def test_each_money_case_needs_its_own_wait_witness(self):
        witnesses = self.witnesses()
        self.assertTrue(runner.validate_witnesses(witnesses, runner.MONEY_CASES))
        self.assertFalse(runner.validate_witnesses(witnesses[:-1], runner.MONEY_CASES))
        self.assertFalse(runner.validate_witnesses(witnesses + witnesses[:1], runner.MONEY_CASES))
        self.assertTrue(runner.validate_witnesses([], set()))

    def test_wrong_lock_count_same_connection_and_parent_alias_are_refused(self):
        for changed in [{"waiting_children": 1}, {"child_connections": [11, 11]},
                        {"child_connections": [0, 12]}, {"parent_blocker": 11},
                        {"parent_blocker": 0}, {"table": "unrelated_table"}]:
            witnesses = self.witnesses()
            witnesses[0].update(changed)
            self.assertFalse(runner.validate_witnesses(witnesses, runner.MONEY_CASES))


if __name__ == "__main__":
    unittest.main()
