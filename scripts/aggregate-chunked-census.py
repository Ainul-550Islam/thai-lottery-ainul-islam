#!/usr/bin/env python3
"""Aggregate the per-chunk PHPUnit summary lines into one census.

WHY THIS IS A SEPARATE FILE
It used to be an inline heredoc inside run-suite-chunked.sh. Nesting a Python
triple-quoted string inside a bash heredoc inside another heredoc is fragile
enough that editing it broke the script once, so it lives on its own where it
can be read and tested directly.

WHY IT PARSES TWO SHAPES
PHPUnit prints a different summary line depending on the outcome, and a census
that only understands one of them silently reports zero instead of failing
loudly - which is exactly what the first version did:

    all passed -> "OK (123 tests, 456 assertions)"
    otherwise  -> "Tests: 123, Assertions: 456, Failures: 7, Skipped: 2."

WHAT THE NUMBERS MEAN
Per-chunk counts are exact. Their sum is exact too. What chunking cannot
reproduce is cross-directory ordering, so a failure reported here should be
confirmed by re-running that one directory before it is treated as a regression.
"""

import re
import sys

OK_RE = re.compile(r'OK \((\d+) tests?, (\d+) assertions?\)')
SUMMARY_RE = re.compile(r'Tests: (\d+), Assertions: (\d+)')
COUNT_KEYS = ('Errors', 'Failures', 'Skipped', 'Risky')


def main(path: str) -> int:
    totals = {key: 0 for key in ('tests', 'assertions', 'errors', 'failures', 'skipped', 'risky')}
    green_chunks = 0
    unparsed = 0

    with open(path, encoding='utf-8', errors='replace') as handle:
        for line in handle:
            if line.startswith('[') is False:
                continue  # only per-chunk result lines carry counts

            match = OK_RE.search(line)
            if match:
                totals['tests'] += int(match.group(1))
                totals['assertions'] += int(match.group(2))
                green_chunks += 1
                continue

            match = SUMMARY_RE.search(line)
            if not match:
                unparsed += 1
                continue

            totals['tests'] += int(match.group(1))
            totals['assertions'] += int(match.group(2))
            for key in COUNT_KEYS:
                found = re.search(key + r': (\d+)', line)
                if found:
                    totals[key.lower()] += int(found.group(1))

    problems = totals['errors'] + totals['failures']
    print(
        'AGGREGATE  tests={tests}  assertions={assertions}  errors={errors}  '
        'failures={failures}  skipped={skipped}  risky={risky}  '
        'total_problems={problems}  fully_green_chunks={green}'.format(
            problems=problems, green=green_chunks, **totals
        )
    )
    if unparsed:
        # Never fail silently: a chunk whose summary line we could not read means
        # its tests are absent from the census.
        print(f'ERROR  {unparsed} chunk line(s) had no parsable summary', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1] if len(sys.argv) > 1 else '/tmp/chunked/summary.txt'))
