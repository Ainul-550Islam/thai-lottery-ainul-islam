#!/usr/bin/env python3
"""Summarise PHPUnit lanes (JUnit XML + console logs) into one paste-able report.

usage: summarize_junit.py <results-dir>
"""
import collections
import json
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path


def clip(text, n=400):
    text = " ".join((text or "").split())
    return text if len(text) <= n else text[: n - 3] + "..."


def load_cases(xml_path):
    cases = []
    for tc in ET.parse(xml_path).getroot().iter("testcase"):
        status, message = "pass", ""
        for kind in ("error", "failure", "skipped"):
            node = tc.find(kind)
            if node is not None:
                status = kind
                message = ((node.get("message") or "") + "\n" + (node.text or "")).strip()
                break
        cases.append({
            "class": tc.get("class") or tc.get("classname") or "",
            "name": tc.get("name") or "",
            "file": tc.get("file") or "",
            "line": tc.get("line") or "",
            "status": status,
            "message": message,
        })
    return cases


SKIP_HEADER = re.compile(r"skipped[^\n:]*:\s*$", re.I)
ENTRY = re.compile(r"^\d+\) (.+)$")


def skip_reasons_from_log(log_path):
    """Best-effort parse of PHPUnit's --display-skipped section: reason -> count."""
    if not log_path.exists():
        return {}
    lines = log_path.read_text(errors="replace").splitlines()
    reasons = collections.Counter()
    i = 0
    while i < len(lines):
        if SKIP_HEADER.search(lines[i]) and not lines[i].lstrip().startswith(("Tests:", "OK")):
            i += 1
            while i < len(lines):
                if ENTRY.match(lines[i]):
                    j = i + 1
                    reason = []
                    while j < len(lines) and lines[j].strip() and not lines[j].lstrip().startswith("/"):
                        reason.append(lines[j].strip())
                        j += 1
                    reasons[clip(" ".join(reason), 200) or "(no reason given)"] += 1
                    i = j
                elif re.match(r"^(There (was|were)|\d+ tests?\b.*:)\s*", lines[i]) and not SKIP_HEADER.search(lines[i]):
                    break
                else:
                    i += 1
            continue
        i += 1
    return dict(reasons)


def report_lane(name, xml_path, log_path, out):
    cases = load_cases(xml_path)
    by = collections.Counter(c["status"] for c in cases)
    out.append(f"\n{'=' * 78}\nLANE {name}: {len(cases)} tests | pass {by['pass']} | "
               f"errors {by['error']} | failures {by['failure']} | skipped {by['skipped']}\n{'=' * 78}")

    bad = [c for c in cases if c["status"] in ("error", "failure")]
    if bad:
        out.append(f"\n-- {len(bad)} ERRORS/FAILURES --")
        for n, c in enumerate(bad, 1):
            loc = f"{c['file']}:{c['line']}" if c["file"] else ""
            out.append(f"\n[{n}] {c['status'].upper()}  {c['class']}::{c['name']}\n    {loc}")
            for ln in [l for l in c["message"].splitlines() if l.strip()][:6]:
                out.append("    | " + clip(ln, 300))

    skipped = [c for c in cases if c["status"] == "skipped"]
    if skipped:
        out.append(f"\n-- {len(skipped)} SKIPPED --")
        reasons = skip_reasons_from_log(log_path)
        if reasons:
            out.append("by reason (from console log):")
            for reason, count in sorted(reasons.items(), key=lambda kv: -kv[1]):
                out.append(f"  {count:>4}  {reason}")
        else:
            out.append("(no skip reasons found in the console log)")
        per_class = collections.Counter(c["class"] for c in skipped)
        out.append("by test class (top 25):")
        for cls, count in per_class.most_common(25):
            out.append(f"  {count:>4}  {cls}")
    return by


def main():
    results = Path(sys.argv[1] if len(sys.argv) > 1 else ".")
    out = ["Thai-lottery Docker test summary"]
    found = False
    for name in ("sqlite", "mysql"):
        xml_path = results / f"{name}.xml"
        if xml_path.exists():
            found = True
            try:
                report_lane(name, xml_path, results / f"{name}.log", out)
            except ET.ParseError as exc:
                out.append(f"\nLANE {name}: junit unreadable ({exc}); see {name}.log")
        elif (results / f"{name}.log").exists():
            found = True
            tail = (results / f"{name}.log").read_text(errors="replace").splitlines()[-25:]
            out.append(f"\nLANE {name}: no junit written (phpunit crashed early). Last log lines:")
            out += ["    " + l for l in tail]

    races = results / "races.json"
    if races.exists():
        found = True
        try:
            data = json.loads(races.read_text())
            out.append(f"\n{'=' * 78}\nLANE races: {clip(json.dumps(data), 1500)}\n{'=' * 78}")
        except ValueError:
            out.append("\nLANE races: races.json unreadable; see races.log")
    elif (results / "races.log").exists():
        found = True
        out.append("\nLANE races: failed before writing JSON. Last log lines:")
        out += ["    " + l for l in (results / "races.log").read_text(errors="replace").splitlines()[-25:]]

    if not found:
        out.append("No lane output found - the container failed before phpunit ran (check the console above).")
    print("\n".join(out))


if __name__ == "__main__":
    main()
