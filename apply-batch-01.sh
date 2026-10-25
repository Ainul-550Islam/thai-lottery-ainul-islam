#!/bin/sh
set -e
cd -- "$(dirname -- "$0")"
while IFS= read -r path; do
    if [ -n "$path" ]; then
        git rm -q -f --ignore-unmatch -- "$path"
    fi
done < DELETE-LIST.txt
