#!/usr/bin/env bash
#
# recover-zeroed-files.sh
#
# THE BUG THIS REPAIRS
# --------------------
# Commit 10fd070 ("integrate final update bundle, social login, TOTP MFA web
# controller, ...") replaced 1,073 tracked files with the same number of NUL
# bytes. Every file kept its exact byte length, so nothing in git noticed: the
# tree is valid, `git fsck` is clean, and `git status` is clean. The damage is
# only visible when a file is READ.
#
# What was lost:
#   tests/TestCase.php        -> the whole PHPUnit suite cannot boot
#   tests/Feature/** (460)    -> the feature suite
#   tests/Unit/** (122)       -> the unit suite
#   resources/views/** (207)  -> Blade templates render NUL bytes
#   resources/js/** (88)      -> front-end sources
#   lang/** (31)              -> Thai and English translation files
#   security/weekly-result-integrity/** (12) -> the Rust integrity verifier
#   scripts/**, tools/**      -> CI and ops scripts
#
# All 1,073 are present, intact, in commit dd7a0a4 - the commit immediately
# before the corrupting one.
#
# USAGE
#   ./scripts/recover-zeroed-files.sh              # report only, changes nothing
#   ./scripts/recover-zeroed-files.sh --apply      # restore the files
#   ./scripts/recover-zeroed-files.sh --apply --source <rev>
#
# The script only ever restores a path whose CURRENT worktree copy is 100%
# NUL bytes, and only from a revision where that path holds real content. It
# never overwrites a file that has content.

set -uo pipefail

APPLY=0
SOURCE=""
while [ $# -gt 0 ]; do
    case "$1" in
        --apply) APPLY=1 ;;
        --source) shift; SOURCE="${1:-}" ;;
        -h|--help) sed -n '2,40p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "unknown argument: $1" >&2; exit 2 ;;
    esac
    shift
done

git rev-parse --git-dir >/dev/null 2>&1 || { echo "not a git repository" >&2; exit 1; }

ZEROED_LIST="$(mktemp)"
REPORT="$(mktemp)"
trap 'rm -f "$ZEROED_LIST" "$REPORT"' EXIT

echo "Scanning tracked files for NUL-filled content..."

# A file counts as zeroed when it is non-empty AND every byte is NUL.
git ls-files -z | while IFS= read -r -d '' path; do
    [ -f "$path" ] || continue
    size=$(wc -c < "$path" 2>/dev/null || echo 0)
    [ "$size" -gt 0 ] || continue
    nuls=$(tr -cd '\000' < "$path" 2>/dev/null | wc -c)
    [ "$nuls" = "$size" ] && printf '%s\n' "$path"
done > "$ZEROED_LIST"

total=$(wc -l < "$ZEROED_LIST")
echo "  zero-filled tracked files: $total"

[ "$total" -gt 0 ] || { echo "Nothing to repair."; exit 0; }

# Find the newest ancestor of HEAD where a path still has real content.
resolve_source() {
    local path="$1"
    local rev
    if [ -n "$SOURCE" ]; then
        echo "$SOURCE"
        return
    fi
    # Walk history newest-first; the first blob that is not all-NUL wins.
    while IFS= read -r rev; do
        git cat-file -e "$rev:$path" 2>/dev/null || continue
        local s n
        s=$(git cat-file -s "$rev:$path" 2>/dev/null)
        [ "${s:-0}" -gt 0 ] || continue
        n=$(git cat-file -p "$rev:$path" 2>/dev/null | tr -cd '\000' | wc -c)
        if [ "$n" != "$s" ]; then
            echo "$rev"
            return
        fi
    done < <(git log --format=%H -n 40 --follow -- "$path" 2>/dev/null)
    echo ""
}

recovered=0
unrecoverable=0

while IFS= read -r path; do
    rev=$(resolve_source "$path")
    if [ -z "$rev" ]; then
        printf 'UNRECOVERABLE  %s\n' "$path" >> "$REPORT"
        unrecoverable=$((unrecoverable + 1))
        continue
    fi
    printf 'OK  %s  <- %s\n' "$path" "${rev:0:7}" >> "$REPORT"
    recovered=$((recovered + 1))
    if [ "$APPLY" = "1" ]; then
        git checkout "$rev" -- "$path" 2>/dev/null || printf 'FAILED %s\n' "$path" >> "$REPORT"
    fi
done < "$ZEROED_LIST"

echo "  recoverable:   $recovered"
echo "  unrecoverable: $unrecoverable"
echo

if [ "$APPLY" = "1" ]; then
    echo "Restored $recovered path(s). Review before committing:"
    echo "  git diff --stat"
    echo
    echo "Then, if the restored content is what you want:"
    echo "  git add -A && git commit -m 'fix: restore 1,073 files zero-filled by 10fd070'"
else
    echo "Dry run. Nothing was changed. Re-run with --apply to restore."
    echo "Full plan: $(printf '%s' "$REPORT") (deleted on exit; re-run to see it again)"
fi

if [ "$unrecoverable" -gt 0 ]; then
    echo
    echo "WARNING: $unrecoverable file(s) have no non-NUL ancestor. They need to be"
    echo "rewritten by hand:"
    grep '^UNRECOVERABLE' "$REPORT" | sed 's/^UNRECOVERABLE  /  - /'
    exit 1
fi
