"""Offline recovery-evidence refusal checks; never boot PHP or contact SQL."""
import sys
from pathlib import Path
import unittest
import xml.etree.ElementTree as ET

sys.path.insert(0, str(Path(__file__).resolve().parent))
import run_settlement_recovery as runner


class RecoveryEvidenceGuards(unittest.TestCase):
    def witnesses(self):
        return [{"class": runner.CLASS, "name": name, "phase": phase, "signal": 9,
                 "cache": "database", "pid": 100 + index, "child_connection": 200 + index,
                 "transaction_level": 1 if phase == "money" else 0, "recovered": True}
                for index, (name, phase) in enumerate(runner.KILL_PHASES.items())]

    def test_exact_inventory_requires_nine_cases_and_seven_actual_kills(self):
        self.assertEqual(len(runner.EXPECTED_CASES), 9)
        self.assertEqual(len(runner.KILL_PHASES), 7)
        self.assertTrue(runner.validate_kills(self.witnesses()))

    def test_missing_duplicate_and_extra_witnesses_are_refused(self):
        values = self.witnesses()
        for changed in [values[:-1], values + values[:1], values[1:] + values[1:2]]:
            self.assertFalse(runner.validate_kills(changed))

    def test_wrong_test_or_phase_cannot_replace_a_required_fault_point(self):
        for change in [{"name": "sequential_not_killed"}, {"class": "OtherTest"}, {"phase": "none"}]:
            values = self.witnesses()
            values[0].update(change)
            self.assertFalse(runner.validate_kills(values))

    def test_signal_shared_cache_and_terminal_recovery_are_required(self):
        for change in [{"signal": 15}, {"signal": 0}, {"cache": "array"}, {"recovered": False}, {"recovered": "true"}]:
            values = self.witnesses()
            values[0].update(change)
            self.assertFalse(runner.validate_kills(values))

    def test_process_and_connection_identity_must_be_real_positive_integers(self):
        for change in [{"pid": 0}, {"pid": True}, {"child_connection": -1}, {"child_connection": "2"}]:
            values = self.witnesses()
            values[0].update(change)
            self.assertFalse(runner.validate_kills(values))
        values = self.witnesses()
        values[1]["pid"] = values[0]["pid"]
        self.assertFalse(runner.validate_kills(values))
        values = self.witnesses()
        values[1]["child_connection"] = values[0]["child_connection"]
        self.assertFalse(runner.validate_kills(values))

    def test_money_fault_is_inside_transaction_and_committed_fault_is_outside(self):
        values = self.witnesses()
        values[0]["transaction_level"] = 0
        self.assertFalse(runner.validate_kills(values))
        values = self.witnesses()
        values[1]["transaction_level"] = 1
        self.assertFalse(runner.validate_kills(values))

    def test_skipped_or_replaced_junit_cannot_claim_success(self):
        cases = [ET.Element("testcase", {"class": cls, "name": name}) for cls, name in sorted(runner.EXPECTED_CASES)]
        self.assertTrue(runner.classify_cases(cases, runner.EXPECTED_CASES, 0)[1])
        ET.SubElement(cases[0], "skipped")
        self.assertFalse(runner.classify_cases(cases, runner.EXPECTED_CASES, 0)[1])
        self.assertFalse(runner.classify_cases(cases[1:], runner.EXPECTED_CASES, 0)[1])

    def test_malformed_witness_shapes_fail_closed(self):
        for values in [None, {}, ["not-a-witness"], []]:
            self.assertFalse(runner.validate_kills(values))


if __name__ == "__main__":
    unittest.main()
