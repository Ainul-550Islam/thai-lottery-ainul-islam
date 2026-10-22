#!/usr/bin/env python3
"""Offline exact-evidence checks; no database, gateway or child execution."""
import copy
import unittest
import xml.etree.ElementTree as ET

from run_withdrawal_dispatch_recovery import CLASS, EXPECTED_CASES, PHASES, validate_kills
from run_financial_concurrency import classify_cases


class DispatchRecoveryEvidenceTest(unittest.TestCase):
    def valid(self):
        return [{"class": CLASS, "name": name, "phase": phase, "pid": index + 100, "connection": index + 200,
                 "transaction_level": 0, "signal": 9, "synthetic_acceptances": 1 if phase == "accepted" else 0,
                 "replayed_without_resend": True, "reserved_until_reconciled": True}
                for index, (name, phase) in enumerate(PHASES.items())]

    def test_exact_owned_faults_are_accepted(self):
        self.assertTrue(validate_kills(self.valid()))

    def test_missing_extra_and_duplicate_faults_are_refused(self):
        values = self.valid()
        for bad in [values[:-1], values + values[:1], [values[0], values[0]]]:
            self.assertFalse(validate_kills(bad))

    def test_malformed_evidence_is_refused(self):
        for bad in [None, {}, "", [None], ["not-an-object"]]:
            self.assertFalse(validate_kills(bad))

    def test_each_security_field_is_required(self):
        for field in self.valid()[0]:
            values = self.valid()
            del values[0][field]
            self.assertFalse(validate_kills(values), field)

    def test_wrong_fault_ownership_and_types_are_refused(self):
        for field, bad in [("class", []), ("name", {}), ("signal", "9"), ("signal", 15), ("pid", True),
                           ("pid", 0), ("connection", -1), ("transaction_level", 1), ("transaction_level", False),
                           ("replayed_without_resend", 1), ("reserved_until_reconciled", False)]:
            values = self.valid()
            values[0][field] = bad
            self.assertFalse(validate_kills(values), field)

    def test_synthetic_journal_cannot_claim_an_extra_external_invocation(self):
        values = self.valid()
        values[1]["synthetic_acceptances"] = 2
        self.assertFalse(validate_kills(values))

    def test_before_send_phase_cannot_claim_provider_acceptance(self):
        values = self.valid()
        values[0]["synthetic_acceptances"] = 1
        self.assertFalse(validate_kills(values))

    def test_boolean_is_not_an_acceptance_count(self):
        values = self.valid()
        values[1]["synthetic_acceptances"] = True
        self.assertFalse(validate_kills(values))

    def test_reused_process_or_connection_is_refused(self):
        for field in ["pid", "connection"]:
            values = self.valid()
            values[1][field] = values[0][field]
            self.assertFalse(validate_kills(values))

    def test_wrong_phase_cannot_replace_a_fault_boundary(self):
        values = self.valid()
        values[0]["phase"] = "accepted"
        self.assertFalse(validate_kills(values))

    def test_passing_junit_still_requires_exact_case_identity(self):
        cases = [ET.Element("testcase", {"class": cls, "name": name}) for cls, name in sorted(EXPECTED_CASES)]
        self.assertTrue(classify_cases(cases, EXPECTED_CASES, 0)[1])
        self.assertFalse(classify_cases(cases[:-1], EXPECTED_CASES, 0)[1])
        self.assertFalse(classify_cases(cases + cases[:1], EXPECTED_CASES, 0)[1])

    def test_skips_errors_failures_and_nonzero_exit_never_pass(self):
        original = [ET.Element("testcase", {"class": cls, "name": name}) for cls, name in sorted(EXPECTED_CASES)]
        self.assertFalse(classify_cases(original, EXPECTED_CASES, 1)[1])
        for tag in ["skipped", "failure", "error"]:
            cases = copy.deepcopy(original)
            ET.SubElement(cases[0], tag)
            self.assertFalse(classify_cases(cases, EXPECTED_CASES, 0)[1])


if __name__ == "__main__":
    unittest.main()
