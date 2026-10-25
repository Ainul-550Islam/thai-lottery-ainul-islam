#!/bin/sh
set -e

# Apply the Batch 08 deletions. A ZIP cannot delete files, so this script is the
# only mechanism. Paths are repo-relative, one per line, from DELETE-LIST.txt.
while IFS= read -r path || [ -n "$path" ]; do
    case "$path" in
        ''|\#*) continue ;;
    esac
    git rm -q -f --ignore-unmatch -- "$path"
done < DELETE-LIST.txt
