#!/usr/bin/env bash
#
# restore-nul-files.sh — recover source files that were committed as NUL bytes.
#
# WHAT HAPPENED
# Commit 10fd070 ("integrate final update bundle") wrote 1,073 tracked files as
# nothing but NUL bytes. The content of those files is intact in the preceding
# commit dd7a0a4, which is the last commit where they are real text. The damage
# is in the committed blobs, so checking the working tree out again does not fix
# it — the bytes have to be taken from dd7a0a4 explicitly.
#
# WHY IT MATTERED
# tests/TestCase.php was among the casualties, so PHPUnit died with
# `Class "Tests\TestCase" not found` before running a single test: the entire
# suite was unrunnable, and `routes/thai_rails.php`, most of tests/Feature,
# tests/Unit, resources/views and lang/th were unreadable too.
#
# WHAT THIS DOES
# For every tracked file that is currently all-NUL, restore the dd7a0a4 version
# into the working tree (git checkout, so the restore is staged). Files that are
# legitimately binary are left alone: the NUL check is "the whole file is NUL",
# not "the file contains NUL".
#
# Run from the repository root, on a clean or dirty tree — it only touches files
# that are currently all-NUL, so it will not overwrite real edits.
#
#   ./scripts/restore-nul-files.sh          # restore
#   ./scripts/restore-nul-files.sh --check  # report only, exit 1 if any remain
#
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

SOURCE_COMMIT="${SOURCE_COMMIT:-dd7a0a4}"
MODE="${1:-restore}"

python3 - "$SOURCE_COMMIT" "$MODE" <<'PYTHON'
import subprocess
import sys

source, mode = sys.argv[1], sys.argv[2]

def blob(rev, path):
    result = subprocess.run(['git', 'cat-file', '-p', f'{rev}:{path}'], capture_output=True)
    return result.stdout if result.returncode == 0 else None

tracked = [f for f in subprocess.run(
    ['git', 'ls-files'], capture_output=True, text=True, check=True
).stdout.split('\n') if f]

nulled = []
for path in tracked:
    # The WORKING TREE, not the committed blob: after a restore the blobs still
    # hold the damage, and --check must report the files an operator can see.
    try:
        with open(path, 'rb') as handle:
            content = handle.read()
    except OSError:
        continue
    # "all NUL" only: a JPEG or a compiled binary contains NULs but is not damage.
    if content and len(content) > 0 and content.count(b'\x00') == len(content):
        nulled.append(path)

if not nulled:
    print('No all-NUL files found; nothing to restore.')
    sys.exit(0)

print(f'{len(nulled)} tracked files are all NUL bytes.')

recoverable, unrecoverable = [], []
for path in nulled:
    original = blob(source, path)
    if original and len(original) > 0 and original.count(b'\x00') != len(original):
        recoverable.append(path)
    else:
        unrecoverable.append(path)

print(f'  recoverable from {source}: {len(recoverable)}')
if unrecoverable:
    print(f'  NOT recoverable from {source}: {len(unrecoverable)}')
    for path in unrecoverable:
        print(f'    {path}')

if mode == '--check':
    sys.exit(1 if nulled else 0)

if recoverable:
    subprocess.run('git checkout %s -- %s' % (source, ' '.join(f"'{p}'" for p in recoverable)),
                   shell=True, check=True)
    print(f'restored {len(recoverable)} files from {source}')

if unrecoverable:
    print('Some files could not be restored and need to be recreated by hand.', file=sys.stderr)
    sys.exit(1)
PYTHON
