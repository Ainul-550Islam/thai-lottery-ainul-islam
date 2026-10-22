import copy
import json
from pathlib import Path
import tempfile
import unittest
import xml.etree.ElementTree as ET
from verify_financial_retry_ci import BASE, SERVER, verify


class FinancialRetryEvidenceTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.junit = Path(self.tmp.name) / "native.xml"
        self.witness = Path(self.tmp.name) / "receipts.jsonl"
        self.xml = ET.Element("testsuites")
        for cls, count in ((BASE, 24), (SERVER, 4)):
            for n in range(count):
                ET.SubElement(self.xml, "testcase", {"class": cls, "name": str(n), "assertions": "1"})
        self.rows = []
        for fault in ("deadlock", "timeout"):
            for mode in ("root", "ambient"):
                root, timeout = mode == "root", fault == "timeout"
                wins = 2 if root else (0 if timeout else 1)
                results = [{"outcome": "completed" if i < wins else "refused", "attempts": (2 if timeout or i == 0 else 1) if root else 1,
                            "currency": c, "level": 0, "physical": False, "balance": "510.00" if i < wins else "500.00", "caller_marker": "committable caller marker"} for i, c in enumerate(("THB", "BDT"))]
                self.rows.append({"fault": fault, "mode": mode, "ready": [{"connection_id": i + 1, "balance_before": "500.00", "balance_staged": "510.00", "physical": True, "level": 1 if root else 2} for i in range(2)],
                                  "results": results, "actual_driver_faults": [{"code": 1205 if timeout else 1213, "attempt": 1, "physical": True}] * (2 if timeout else 1),
                                  "parent_two_child_waits": 2 if timeout else None, "financial_transactions": 2 + wins, "ledger_entries": 4 + 2 * wins})

    def run_gate(self):
        ET.ElementTree(self.xml).write(self.junit)
        self.witness.write_text("\n".join(json.dumps(row) for row in self.rows))
        return verify(self.junit, self.witness)

    def test_accepts_complete_profiles(self):
        self.assertTrue(self.run_gate()["passed"])

    def test_rejects_nonpassing_or_duplicate_cases(self):
        for tag in ("error", "failure", "skipped"):
            child = ET.SubElement(self.xml[0], tag)
            with self.assertRaises(ValueError): self.run_gate()
            self.xml[0].remove(child)
        self.xml[1].set("name", "0")
        with self.assertRaises(ValueError): self.run_gate()

    def test_rejects_missing_native_profile(self):
        self.rows.pop()
        with self.assertRaises(ValueError): self.run_gate()

    def test_rejects_fake_sessions_and_unstaged_money(self):
        original = copy.deepcopy(self.rows)
        for change in ({"connection_id": 2}, {"balance_staged": "500.00"}, {"physical": False}):
            self.rows = copy.deepcopy(original)
            self.rows[0]["ready"][0].update(change)
            with self.assertRaises(ValueError): self.run_gate()

    def test_rejects_caller_retry_and_partial_accounting(self):
        original = copy.deepcopy(self.rows)
        for change in ({"attempts": 2}, {"balance": "510.00"}, {"caller_marker": "lost"}):
            self.rows = copy.deepcopy(original)
            self.rows[3]["results"][0].update(change)
            with self.assertRaises(ValueError): self.run_gate()

    def test_rejects_false_parent_wait_or_driver_code(self):
        self.rows[0]["parent_two_child_waits"] = 2
        with self.assertRaises(ValueError): self.run_gate()
        self.rows[0]["parent_two_child_waits"] = None
        self.rows[2]["actual_driver_faults"][0]["code"] = 1062
        with self.assertRaises(ValueError): self.run_gate()

    def test_rejects_extra_journal_or_currency_substitution(self):
        self.rows[0]["ledger_entries"] += 1
        with self.assertRaises(ValueError): self.run_gate()
        self.rows[0]["ledger_entries"] -= 1
        self.rows[0]["results"][1]["currency"] = "THB"
        with self.assertRaises(ValueError): self.run_gate()


if __name__ == "__main__": unittest.main()
