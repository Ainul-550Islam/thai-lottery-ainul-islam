#!/usr/bin/env bash
# =============================================================================
# check-local-edits.sh        (run AFTER apply-merge.sh, from the project root)
#
#   bash check-local-edits.sh base-app-files.tar.gz
#
# apply-merge.sh replaced files assuming your project still equals app.zip.
# If you kept working after app.zip was made, some replaced files may have lost
# your newer edits. This script finds exactly those files and re-applies your
# edits on top of the new code with a 3-way merge:
#
#     base   = the file as it is in app.zip         (inside base-app-files.tar.gz)
#     yours  = your file before the merge           (.merge-backup/<time>/...)
#     theirs = the file as the merge wrote it       (the working tree now)
#
# Clean merge  -> written straight into the file (your line endings kept).
# Real clash   -> the file is left as the merge wrote it and the clash is saved as
#                 <file>.CONFLICT for you to decide. Nothing is ever deleted.
# =============================================================================
set -uo pipefail

BASE_TAR="${1:-base-app-files.tar.gz}"
BK="${2:-$(ls -d .merge-backup/*/ 2>/dev/null | sort | tail -1)}"
BK="${BK%/}"

[ -f artisan ] && [ -d app ] || { echo "ERROR: run this from the Laravel project root" >&2; exit 1; }
[ -d "$BK" ]       || { echo "ERROR: no .merge-backup/<time> folder found - run apply-merge.sh first" >&2; exit 1; }
[ -f "$BASE_TAR" ] || { echo "ERROR: $BASE_TAR not found" >&2; exit 1; }
command -v git >/dev/null || { echo "ERROR: git is required (git merge-file)" >&2; exit 1; }

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
tar -xzf "$BASE_TAR" -C "$TMP/" 2>/dev/null || { echo "ERROR: could not extract $BASE_TAR" >&2; exit 1; }
mkdir -p "$TMP/work"

md5n() { tr -d '\r' < "$1" | md5sum | cut -d' ' -f1; }

CLEAN=0; MERGED=0; CLASH=0; NOBASE=0
echo "== app/ files the merge replaced (backup: $BK) =="
while IFS= read -r f; do
  mine="$BK/$f"; base="$TMP/$f"
  [ -f "$f" ] || continue
  if [ ! -f "$base" ]; then
    # It was not in app.zip, yet it existed in your repo before the merge.
    if [ "$(md5n "$mine")" != "$(md5n "$f")" ]; then
      echo "  ?   $f"
      echo "        not in app.zip, but present in your repo - now replaced by the new-set version."
      echo "        compare: diff -u \"$mine\" \"$f\""
      NOBASE=$((NOBASE + 1))
    fi
    continue
  fi
  if [ "$(md5n "$mine")" = "$(md5n "$base")" ]; then CLEAN=$((CLEAN + 1)); continue; fi   # untouched since app.zip

  # You changed this file after app.zip was made -> 3-way merge
  tr -d '\r' < "$mine" > "$TMP/work/yours"
  tr -d '\r' < "$base" > "$TMP/work/base"
  tr -d '\r' < "$f"    > "$TMP/work/theirs"
  if git merge-file -p "$TMP/work/yours" "$TMP/work/base" "$TMP/work/theirs" > "$TMP/work/merged" 2>/dev/null; then
    if grep -q $'\r' "$mine"; then sed 's/$/\r/' "$TMP/work/merged" > "$f"; else cp "$TMP/work/merged" "$f"; fi
    echo "  +   $f      your later edits re-applied on top of the new code"
    MERGED=$((MERGED + 1))
  else
    if grep -q $'\r' "$mine"; then sed 's/$/\r/' "$TMP/work/merged" > "$f.CONFLICT"; else cp "$TMP/work/merged" "$f.CONFLICT"; fi
    echo "  !!  $f      you and the new code changed the same lines"
    echo "        -> clash saved as $f.CONFLICT  (your version: $mine)"
    CLASH=$((CLASH + 1))
  fi
done < <(cd "$BK" && find app -type f | sort)

echo
echo "   untouched since app.zip: $CLEAN   re-applied: $MERGED   clashes: $CLASH   not-in-app.zip: $NOBASE"

echo
echo "== Files OUTSIDE app/ that the merge replaced: lines of YOURS that are no longer there =="
SHOWN=0
while IFS= read -r f; do
  [ -f "$f" ] || continue
  gone="$(diff <(tr -d '\r' < "$BK/$f") <(tr -d '\r' < "$f") | grep '^<' || true)"
  n="$(printf '%s' "$gone" | grep -c '^<' || true)"
  if [ "${n:-0}" -gt 0 ]; then
    SHOWN=$((SHOWN + 1))
    echo "  $f  - $n of your line(s) not present any more:"
    printf '%s\n' "$gone" | head -8 | cut -c1-130 | sed 's/^< /        /'
    [ "$n" -gt 8 ] && echo "        ... ($((n - 8)) more: diff -u \"$BK/$f\" \"$f\")"
  fi
done < <(cd "$BK" && find . -type f ! -path './app/*' | sed 's#^\./##' | sort)
[ "$SHOWN" -eq 0 ] && echo "  none - nothing of yours was dropped."

echo
if [ "$CLASH" -gt 0 ]; then
  echo "NEXT: open each *.CONFLICT file, keep the lines you want, save it over the original, delete the .CONFLICT file."
fi
echo "Then 'git diff' shows the final result. Your untouched pre-merge state is also in git (the 'before merge' commit)."
