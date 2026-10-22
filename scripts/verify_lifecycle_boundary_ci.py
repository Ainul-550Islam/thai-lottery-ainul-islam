"""Validate native lifecycle regression and six genuine parent/two-child waits."""
import argparse
from collections import Counter
import json
from pathlib import Path
import xml.etree.ElementTree as ET

BOUNDARY = r"Tests\Feature\Finance\LifecycleTransactionBoundaryTest"
SERVER = r"Tests\Feature\Finance\LifecycleTransactionBoundaryServerTest"


def require(condition, message):
    if not condition:
        raise ValueError(message)


def verify(junit, witness):
    cases = list(ET.parse(junit).getroot().iter("testcase"))
    require(Counter(c.get("class") for c in cases) == {BOUNDARY: 74, SERVER: 6}, "Expected exactly 74 lifecycle and six server cases.")
    require(len({(c.get("class"), c.get("name")) for c in cases}) == 80, "Duplicate case identity.")
    require(all(c.get("name") and int(c.get("assertions", "0")) > 0 for c in cases), "Missing named assertions.")
    require(all(all(c.find(tag) is None for tag in ("error", "failure", "skipped")) for c in cases), "Error, failure or skipped native case.")
    waits = [json.loads(line) for line in Path(witness).read_text().splitlines() if line.strip()]
    waits = [row for row in waits if row.get("class") == SERVER]
    require(len(waits) == 6, "Six lifecycle parent/two-child waits required.")
    require(Counter(row.get("table") for row in waits) == {"deposits": 2, "withdrawals": 2, "financial_transactions": 2}, "Each real lifecycle table must have both lock and writer profiles.")
    require(len({row.get("name") for row in waits}) == 1 and all(row.get("name") == "test_two_old_snapshot_callers_use_current_lifecycle_row_after_real_parent_lock" for row in waits), "Wrong lifecycle witness method.")
    for row in waits:
        children = row.get("child_connections", [])
        parent = row.get("parent_blocker")
        require(row.get("waiting_children") == 2 and len(children) == 2 and len(set(children)) == 2, "Two distinct simultaneous child waits required.")
        require(type(parent) is int and parent > 0 and parent not in children and all(type(child) is int and child > 0 for child in children), "Distinct real parent and child session identities required.")
        require(row.get("lifecycle_boundary_snapshots") == [{"table": row["table"], "status": "pending"}] * 2, "Both actual old snapshots required before parent mutation.")
        require(row.get("lock_index") == "PRIMARY" and type(row.get("blocker_row_id")) is int and row["blocker_row_id"] > 0, "Actual primary-key blocker required.")
    return {"passed": True, "cases": 80, "assertions": sum(int(c.get("assertions")) for c in cases), "genuine_two_child_parent_waits": 6}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("junit", type=Path)
    parser.add_argument("witness", type=Path)
    args = parser.parse_args()
    print(json.dumps(verify(args.junit, args.witness), indent=2))


if __name__ == "__main__":
    main()
