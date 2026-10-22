"""Omission controls for the evidence validator; no provider or database access."""
import copy
import json
from pathlib import Path
import tempfile
import unittest
import xml.etree.ElementTree as ET
from verify_lifecycle_boundary_ci import BOUNDARY, SERVER, verify


class LifecycleEvidenceTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.junit = Path(self.directory.name) / "cases.xml"
        self.witness = Path(self.directory.name) / "waits.jsonl"
        self.root = ET.Element("testsuites")
        for cls, count in ((BOUNDARY, 74), (SERVER, 6)):
            for number in range(count):
                ET.SubElement(self.root, "testcase", {"class": cls, "name": f"profile-{number}", "assertions": "1"})
        self.rows = [{"class": SERVER, "name": "test_two_old_snapshot_callers_use_current_lifecycle_row_after_real_parent_lock", "table": table,
                      "waiting_children": 2, "child_connections": [11, 12], "parent_blocker": 10, "blocker_row_id": 1, "lock_index": "PRIMARY",
                      "lifecycle_boundary_snapshots": [{"table": table, "status": "pending"}] * 2}
                     for table in ("deposits", "withdrawals", "financial_transactions") for _ in range(2)]

    def run_validator(self):
        ET.ElementTree(self.root).write(self.junit)
        self.witness.write_text("\n".join(json.dumps(row) for row in self.rows) + "\n")
        return verify(self.junit, self.witness)

    def test_accepts_complete_evidence(self):
        self.assertEqual(6, self.run_validator()["genuine_two_child_parent_waits"])

    def test_refuses_each_nonpassing_native_result(self):
        for tag in ("error", "failure", "skipped"):
            with self.subTest(tag=tag):
                child = ET.SubElement(self.root[0], tag)
                with self.assertRaises(ValueError):
                    self.run_validator()
                self.root[0].remove(child)

    def test_refuses_duplicate_or_missing_cases(self):
        original = copy.deepcopy(self.root)
        self.root[1].set("name", self.root[0].get("name"))
        with self.assertRaises(ValueError):
            self.run_validator()
        self.root = original
        self.root.remove(self.root[0])
        with self.assertRaises(ValueError):
            self.run_validator()

    def test_refuses_empty_assertions_and_foreign_classes(self):
        self.root[0].set("assertions", "0")
        with self.assertRaises(ValueError):
            self.run_validator()
        self.root[0].set("assertions", "1")
        self.root[0].set("class", "Foreign")
        with self.assertRaises(ValueError):
            self.run_validator()

    def test_refuses_missing_waits_or_aggregate_table_substitution(self):
        saved = copy.deepcopy(self.rows)
        self.rows.pop()
        with self.assertRaises(ValueError):
            self.run_validator()
        self.rows = saved
        self.rows[0]["table"] = "wallets"
        with self.assertRaises(ValueError):
            self.run_validator()

    def test_refuses_missing_or_wrong_actual_snapshot(self):
        for snapshot in (None, [], [{"table": "deposits", "status": "completed"}] * 2):
            self.rows[0]["lifecycle_boundary_snapshots"] = snapshot
            with self.assertRaises(ValueError):
                self.run_validator()

    def test_refuses_fake_session_or_wait_receipts(self):
        original = copy.deepcopy(self.rows)
        for change in ({"waiting_children": 1}, {"child_connections": [11, 11]}, {"parent_blocker": 11}, {"parent_blocker": "10"}, {"lock_index": "other"}, {"blocker_row_id": 0}, {"name": "another_profile"}):
            with self.subTest(change=change):
                self.rows = copy.deepcopy(original)
                self.rows[0].update(change)
                with self.assertRaises(ValueError):
                    self.run_validator()


if __name__ == "__main__":
    unittest.main()
