"""Fail-closed local native retry-ownership receipt gate; counts are not additive."""
import argparse
from collections import Counter
import json
from pathlib import Path
import xml.etree.ElementTree as ET

BASE = r"Tests\Feature\Finance\FinancialRetryOwnershipTest"
SERVER = r"Tests\Feature\Finance\FinancialRetryOwnershipServerTest"


def require(condition, message):
    if not condition:
        raise ValueError(message)


def verify(junit, witness):
    cases = list(ET.parse(junit).getroot().iter("testcase"))
    require(Counter(c.get("class") for c in cases) == {BASE: 24, SERVER: 4}, "Expected 24 feature and four native race cases.")
    require(len({(c.get("class"), c.get("name")) for c in cases}) == 28, "Duplicate case identity.")
    require(all(c.get("name") and int(c.get("assertions", "0")) > 0 and all(c.find(tag) is None for tag in ("error", "failure", "skipped")) for c in cases), "Missing assertions or nonpassing native case.")
    rows = [json.loads(line) for line in Path(witness).read_text().splitlines() if line.strip()]
    require(Counter((r.get("fault"), r.get("mode")) for r in rows) == {(f, m): 1 for f in ("deadlock", "timeout") for m in ("root", "ambient")}, "Four distinct native profiles required.")
    for row in rows:
        ready, results, faults = row["ready"], row["results"], row["actual_driver_faults"]
        root, timeout = row["mode"] == "root", row["fault"] == "timeout"
        require(len(ready) == len(results) == 2 and len({r["connection_id"] for r in ready}) == 2, "Two independent actual child sessions required.")
        require(all(type(r["connection_id"]) is int and r["connection_id"] > 0 and r["balance_before"] == "500.00" and r["balance_staged"] == "510.00" and r["physical"] is True and r["level"] == (1 if root else 2) for r in ready), "Both children must stage canonical accounting before contention.")
        require(len(faults) == (2 if timeout else 1) and all(r["code"] == (1205 if timeout else 1213) and r["attempt"] == 1 for r in faults), "Real expected native driver failures required.")
        if timeout:
            require(row["parent_two_child_waits"] == 2 and all(r["physical"] is True for r in faults), "Observe both parent waits and surviving timeout transactions.")
        else:
            require(row["parent_two_child_waits"] is None, "A deadlock cycle is not a parent/two-child wait receipt.")
        wins = 2 if root else (0 if timeout else 1)
        require(sum(r["attempts"] for r in results) == ((4 if timeout else 3) if root else 2), "Only root owners may retry the whole unit.")
        require(sum(r["outcome"] == "completed" for r in results) == wins, "Wrong root/caller result count.")
        require([r["currency"] for r in results] == ["THB", "BDT"], "Independent currency ledgers are required; no FX or cross-currency money sum.")
        require(all(r["level"] == 0 and r["physical"] is False and r["balance"] == ("510.00" if r["outcome"] == "completed" else "500.00") for r in results), "Partial money or surviving transaction detected.")
        require(row["financial_transactions"] == 2 + wins and row["ledger_entries"] == 4 + 2 * wins, "Duplicate or surviving failed-attempt journal detected.")
        if timeout and not root:
            require(all(r["caller_marker"] == "committable caller marker" for r in results), "Unrelated caller work must survive savepoint cleanup.")
    return {"passed": True, "cases": 28, "assertions": sum(int(c.get("assertions")) for c in cases), "genuine_two_child_deadlock_cycles": 2, "genuine_parent_two_child_timeout_waits": 2}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("junit", type=Path)
    parser.add_argument("witness", type=Path)
    args = parser.parse_args()
    print(json.dumps(verify(args.junit, args.witness), indent=2))


if __name__ == "__main__":
    main()
