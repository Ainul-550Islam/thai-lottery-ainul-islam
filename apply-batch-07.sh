#!/bin/sh
set -e

while IFS= read -r path || [ -n "$path" ]; do
    [ -n "$path" ] || continue
    git rm -q -f --ignore-unmatch -- "$path"
done < DELETE-LIST.txt

while IFS= read -r path || [ -n "$path" ]; do
    [ -n "$path" ] || continue
    git rm -r -q --cached --ignore-unmatch -- "$path"
done < UNTRACK-LIST.txt
