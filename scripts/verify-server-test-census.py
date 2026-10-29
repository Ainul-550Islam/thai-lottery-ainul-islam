#!/usr/bin/env python3
"""Verify every *ServerTest.php and *ServerRaceTest.php contributed passing JUnit cases.

The test suite is intentionally discovered at runtime, not maintained as a list
of filenames. Adding a new server test must not silently leave it out of CI.
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path
from xml.etree import ElementTree


def verify(test_root: Path, report: Path) -> tuple[int, int]:
    expected: set[Path] = set()
    for pattern in ('*ServerTest.php', '*ServerRaceTest.php'):
        discovered = {path.resolve() for path in test_root.rglob(pattern) if path.is_file()}
        if not discovered:
            raise ValueError(f'No {pattern} files were found under {test_root}')
        expected.update(discovered)

    root = ElementTree.parse(report).getroot()
    seen: set[Path] = set()
    cases = list(root.iter('testcase'))
    if not cases:
        raise ValueError('The JUnit report contains no test cases')

    for suite in root.iter('testsuite'):
        filename = suite.get('file')
        if filename and list(suite.iter('testcase')):
            seen.add(Path(filename).resolve())
    for case in cases:
        filename = case.get('file')
        if filename:
            seen.add(Path(filename).resolve())
        for outcome in ('skipped', 'failure', 'error'):
            if case.find(outcome) is not None:
                raise ValueError(f'{outcome} in {case.get("class", "?")}::{case.get("name", "?")}')

    missing = sorted(str(path) for path in expected - seen)
    if missing:
        raise ValueError('No executed test cases were reported for:\n  ' + '\n  '.join(missing))
    return len(expected), len(cases)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--tests', type=Path, default=Path('tests'))
    parser.add_argument('--junit', type=Path, default=Path('evidence/all-server-tests-ci.xml'))
    args = parser.parse_args()
    try:
        files, cases = verify(args.tests, args.junit)
    except (OSError, ElementTree.ParseError, ValueError) as error:
        print(f'Server-test census failed: {error}', file=sys.stderr)
        return 1
    print(f'Server-test census passed: {files} files, {cases} cases; no skips, failures or errors.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
