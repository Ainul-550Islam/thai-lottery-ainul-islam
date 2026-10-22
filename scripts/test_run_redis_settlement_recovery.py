#!/usr/bin/env python3
"""Offline refusal/evidence guards; deliberately never launches Redis or PHPUnit."""
import argparse
import copy
from pathlib import Path
import socket
import tempfile
import unittest
import xml.etree.ElementTree as ET

from run_redis_settlement_recovery import CLASS, EXPECTED_CASES, PHASES, validate_restarts
from run_financial_concurrency import classify_cases, guarded_environment


class RedisRecoveryEvidenceTest(unittest.TestCase):
    def valid(self):
        return [{"class": CLASS, "name": name, "phase": phase, "signal": 9,
                 "before": f"{index * 2 + 1:040x}", "after": f"{index * 2 + 2:040x}",
                 "version": "8.0.2", "recovered": True, "aof_fsync": "always", "cache_ttl": "real wall time"}
                for index, (name, phase) in enumerate(PHASES.items())]

    def test_exact_restarts_are_accepted(self):
        self.assertTrue(validate_restarts(self.valid()))

    def test_missing_restart_is_refused(self):
        self.assertFalse(validate_restarts(self.valid()[:-1]))

    def test_duplicate_restart_is_refused(self):
        values = self.valid()
        values[-1] = copy.deepcopy(values[0])
        self.assertFalse(validate_restarts(values))

    def test_unexpected_case_is_refused(self):
        values = self.valid()
        values[0]["name"] = "test_unreviewed_case"
        self.assertFalse(validate_restarts(values))

    def test_unchanged_server_incarnation_is_refused(self):
        values = self.valid()
        values[0]["after"] = values[0]["before"]
        self.assertFalse(validate_restarts(values))

    def test_reused_incarnation_between_cases_is_refused(self):
        values = self.valid()
        values[1]["after"] = values[0]["before"]
        self.assertFalse(validate_restarts(values))

    def test_each_fault_boundary_is_exact(self):
        values = self.valid()
        values[0]["phase"] = "committed"
        self.assertFalse(validate_restarts(values))

    def test_non_sigkill_is_refused(self):
        values = self.valid()
        values[0]["signal"] = 15
        self.assertFalse(validate_restarts(values))

    def test_unrecovered_restart_is_refused(self):
        values = self.valid()
        values[0]["recovered"] = False
        self.assertFalse(validate_restarts(values))

    def test_non_durable_aof_is_refused(self):
        values = self.valid()
        values[0]["aof_fsync"] = "everysec"
        self.assertFalse(validate_restarts(values))

    def test_accelerated_cache_claim_is_refused(self):
        values = self.valid()
        values[0]["cache_ttl"] = "accelerated"
        self.assertFalse(validate_restarts(values))

    def test_malformed_witness_fields_are_refused(self):
        for field, value in [("class", []), ("name", {}), ("signal", "9"), ("version", None),
                             ("before", "not-an-id"), ("after", None), ("recovered", 1)]:
            with self.subTest(field=field):
                values = self.valid()
                values[0][field] = value
                self.assertFalse(validate_restarts(values))

    def test_non_list_and_non_object_evidence_is_refused(self):
        for value in [None, {}, "", [None], ["invalid"]]:
            self.assertFalse(validate_restarts(value))

    def test_mixed_redis_versions_are_refused(self):
        values = self.valid()
        values[0]["version"] = "7.2.0"
        self.assertFalse(validate_restarts(values))

    def cases(self):
        return [ET.Element("testcase", {"class": class_name, "name": name}) for class_name, name in sorted(EXPECTED_CASES)]

    def test_exact_passing_junit_is_accepted(self):
        self.assertTrue(classify_cases(self.cases(), EXPECTED_CASES, 0)[1])

    def test_skipped_failed_or_errored_case_is_refused(self):
        for kind in ["skipped", "failure", "error"]:
            cases = self.cases()
            ET.SubElement(cases[0], kind)
            self.assertFalse(classify_cases(cases, EXPECTED_CASES, 0)[1])

    def test_duplicate_or_missing_junit_is_refused(self):
        cases = self.cases()
        self.assertFalse(classify_cases(cases[:-1], EXPECTED_CASES, 0)[1])
        cases[-1] = cases[0]
        self.assertFalse(classify_cases(cases, EXPECTED_CASES, 0)[1])

    def test_nonzero_exit_cannot_pass(self):
        self.assertFalse(classify_cases(self.cases(), EXPECTED_CASES, 1)[1])


class RedisRecoveryDatabaseGuardTest(unittest.TestCase):
    def test_requires_exact_disposable_database_and_acknowledgement(self):
        args = argparse.Namespace(acknowledge_disposable_database=False, database="thai_lottery_test",
                                  connection="mysql", repeats=3, socket="/not-used", username="test")
        with self.assertRaises(ValueError):
            guarded_environment(args, {})
        args.acknowledge_disposable_database = True
        args.database = "production"
        with self.assertRaises(ValueError):
            guarded_environment(args, {})

    def test_refuses_non_socket_test_path(self):
        with tempfile.TemporaryDirectory() as directory:
            args = argparse.Namespace(acknowledge_disposable_database=True, database="thai_lottery_test",
                                      connection="mysql", repeats=3, socket=directory, username="test")
            with self.assertRaises(ValueError):
                guarded_environment(args, {})

    def test_fixed_test_environment_overrides_inherited_production_values(self):
        with tempfile.TemporaryDirectory() as directory:
            path = str(Path(directory) / "db.sock")
            with socket.socket(socket.AF_UNIX) as listener:
                listener.bind(path)
                args = argparse.Namespace(acknowledge_disposable_database=True, database="thai_lottery_test",
                                          connection="mysql", repeats=3, socket=path, username="test")
                env = guarded_environment(args, {"APP_ENV": "production", "DB_DATABASE": "live", "DB_URL": "secret-url"})
                self.assertEqual("testing", env["APP_ENV"])
                self.assertEqual("thai_lottery_test", env["DB_DATABASE"])
                self.assertEqual("", env["DB_URL"])


if __name__ == "__main__":
    unittest.main()
