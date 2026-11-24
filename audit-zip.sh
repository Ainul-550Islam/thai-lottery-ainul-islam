#!/usr/bin/env bash
# audit-zip.sh <package.zip | already-extracted-folder>
#
# Compares EVERY file of the new package with (1) your tree now and (2) your pre-merge commit (HEAD).
# Your project is never changed; the only things written are under C:\temp\tl (the report, and the unzipped copy).
#
#   applied ........... tree == package                          (good)
#   NOT APPLIED ....... tree == HEAD, package differs            (the merge skipped a change the package carries)
#   ABSENT ............ the package file is not in your tree
#   edited / kept ..... tree differs from the package (and from HEAD): edited after the merge, or merged differently
# Line endings (CRLF/LF) are ignored. Takes a few minutes on Windows.
#
# Run from the project root (the folder with `artisan`) in Git Bash. QUOTE the path if it has spaces:
#     bash audit-zip.sh "/c/Users/Ainul Islam/Downloads/app.zip"
# Result: C:\temp\tl\audit-zip.txt   -> upload that one file to the chat.

set -u
[ -f artisan ] || { echo "Run this from the project root (no 'artisan' file here)."; exit 1; }
git rev-parse --verify HEAD >/dev/null 2>&1 || { echo "This folder is not a git repository with a commit."; exit 1; }
SRC="${1:-}"
[ -n "$SRC" ] || { echo "Usage: bash audit-zip.sh \"/path/to/package.zip\"   (or an already-extracted folder)"; exit 1; }
[ -e "$SRC" ] || { echo "Not found: $SRC"; exit 1; }

OUTDIR="${OUTDIR:-/c/temp/tl}"
mkdir -p "$OUTDIR"
OUT="$OUTDIR/audit-zip.txt"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

extract_zip() {   # zip dest
  if command -v unzip >/dev/null 2>&1 && unzip -q -o "$1" -d "$2"; then return 0; fi
  if command -v php >/dev/null 2>&1 && php -r '$z=new ZipArchive; if($z->open($argv[1])!==true){exit(1);} $z->extractTo($argv[2]); $z->close();' "$1" "$2" 2>/dev/null; then return 0; fi
  if command -v powershell >/dev/null 2>&1 && command -v cygpath >/dev/null 2>&1 \
     && powershell -NoProfile -Command "Expand-Archive -LiteralPath '$(cygpath -w "$1")' -DestinationPath '$(cygpath -w "$2")' -Force" >/dev/null 2>&1; then return 0; fi
  return 1
}

if [ -d "$SRC" ]; then
  EX="$SRC"
else
  EX="$OUTDIR/pkg-extract"
  rm -rf "$EX"; mkdir -p "$EX"
  echo "Unzipping to $EX ..."
  extract_zip "$SRC" "$EX" || { echo "Could not unzip. Unzip it yourself and pass the folder instead."; exit 1; }
fi

# package root = the folder that holds app/
ROOT="$EX"
if [ ! -d "$ROOT/app" ]; then
  d="$(find "$EX" -maxdepth 2 -type d -name app 2>/dev/null | head -n 1)"
  [ -n "$d" ] || d="$(find "$EX" -maxdepth 3 -type d -name app 2>/dev/null | head -n 1)"
  [ -n "$d" ] && ROOT="$(dirname "$d")"
fi
[ -d "$ROOT/app" ] || { echo "No app/ folder found inside the package (looked in $EX)."; exit 1; }

section() { printf '\n\n################ %s ################\n' "$1"; }
show() {
  local f="$1" max="${2:-300}"
  if [ -f "$f" ]; then printf '\n--- %s (%s lines total) ---\n' "$f" "$(wc -l < "$f")"; head -n "$max" "$f" | cat -n
  else printf '\n--- %s : NOT FOUND ---\n' "$f"; fi
}
nh()      { tr -d '\r' < "$1" | sha256sum | cut -d' ' -f1; }
nh_head() { git cat-file blob "HEAD:$1" 2>/dev/null | tr -d '\r' | sha256sum | cut -d' ' -f1; }
in_head() { git cat-file -e "HEAD:$1" 2>/dev/null; }

main() {
  echo "package root: $ROOT"
  echo "HEAD (your pre-merge commit): $(git log --oneline -n 1 HEAD)"

  # ---- 1. file list of the package, raw hashes of package and tree in bulk (fast) ----
  ( cd "$ROOT" && find . -type f ! -path './.git/*' ! -path './vendor/*' ! -path './node_modules/*' ! -path './storage/*' -print0 \
      | sort -z | tr '\0' '\n' | sed 's|^\./||' ) > "$TMP/files.txt"
  total="$(wc -l < "$TMP/files.txt")"
  ( cd "$ROOT" && tr '\n' '\0' < "$TMP/files.txt" | xargs -0 sha256sum 2>/dev/null ) > "$TMP/pkg.sums"
  ( tr '\n' '\0' < "$TMP/files.txt" | xargs -0 sha256sum 2>/dev/null ) > "$TMP/tree.sums"
  awk 'NR==FNR { p=substr($0,67); P[p]=substr($0,1,64); next }
       { p=substr($0,67); T[p]=substr($0,1,64) }
       END { for (p in P) { if (!(p in T)) print "ABSENT\t" p; else if (P[p]==T[p]) print "SAME\t" p; else print "DIFF\t" p } }' \
      "$TMP/pkg.sums" "$TMP/tree.sums" | sort -t$'\t' -k2 > "$TMP/cmp.tsv"
  # files the awk could not see because sha256sum failed on them are also ABSENT
  : > "$TMP/seen.txt"; cut -c67- "$TMP/pkg.sums" | sort > "$TMP/seen.txt"
  sort "$TMP/files.txt" | comm -23 - "$TMP/seen.txt" | sed 's/^/UNREADABLE\t/' >> "$TMP/cmp.tsv"

  # ---- 2. classify ----
  for f in applied crlf notapplied absent_new absent_head edited_pkg_eq_head edited_both newfile_differs; do : > "$TMP/$f.txt"; done
  while IFS=$'\t' read -r kind p; do
    case "$kind" in
      SAME) echo "$p" >> "$TMP/applied.txt" ;;
      ABSENT|UNREADABLE) if in_head "$p"; then echo "$p" >> "$TMP/absent_head.txt"; else echo "$p" >> "$TMP/absent_new.txt"; fi ;;
      DIFF)
        pn="$(nh "$ROOT/$p")"; tn="$(nh "$p")"
        if [ "$pn" = "$tn" ]; then echo "$p" >> "$TMP/crlf.txt"; continue; fi
        if ! in_head "$p"; then echo "$p" >> "$TMP/newfile_differs.txt"; continue; fi
        hn="$(nh_head "$p")"
        if   [ "$tn" = "$hn" ]; then echo "$p" >> "$TMP/notapplied.txt"
        elif [ "$pn" = "$hn" ]; then echo "$p" >> "$TMP/edited_pkg_eq_head.txt"
        else echo "$p" >> "$TMP/edited_both.txt"; fi ;;
    esac
  done < "$TMP/cmp.tsv"
  c() { wc -l < "$TMP/$1.txt" | tr -d ' '; }

  section "1. VERDICT"
  echo "package files checked ................................................ $total"
  echo "applied (tree == package) ............................................ $(c applied)"
  echo "same except line endings ............................................. $(c crlf)"
  echo "NOT APPLIED  (tree still == your pre-merge file, package differs) .... $(c notapplied)"
  echo "ABSENT, new in the package (never copied into your tree) ............. $(c absent_new)"
  echo "ABSENT, but the file exists in HEAD (removed from your tree) ......... $(c absent_head)"
  echo "package is NEW file, your tree has a DIFFERENT file at that path ..... $(c newfile_differs)"
  echo "edited / kept (tree != package, tree != HEAD, package == HEAD) ....... $(c edited_pkg_eq_head)"
  echo "edited / merged differently (tree differs from BOTH) ................. $(c edited_both)"

  section "2. NOT APPLIED  (the merge skipped these package changes)"
  cat "$TMP/notapplied.txt"
  section "3. ABSENT from your tree"
  echo "--- new in the package, never copied ---"; cat "$TMP/absent_new.txt" | head -n 300
  echo; echo "--- exist in HEAD but not in the tree now ---"; cat "$TMP/absent_head.txt" | head -n 100
  section "4. NEW package file, but your tree has different content at that path"
  cat "$TMP/newfile_differs.txt" | head -n 100
  section "5. EDITED / KEPT  (tree differs from the package AND from HEAD)"
  echo "--- package == HEAD: the package did not change it, your tree did ---"; cat "$TMP/edited_pkg_eq_head.txt" | head -n 150
  echo; echo "--- differs from both (modified time | path) ---"
  while IFS= read -r p; do [ -n "$p" ] && printf '%s | %s\n' "$(stat -c '%y' "$p" 2>/dev/null | cut -c1-16)" "$p"; done < "$TMP/edited_both.txt" | head -n 300

  section "6. WHAT THE PACKAGE WANTED: diffs for NOT APPLIED files (tree -  vs  package +, first 70 lines, max 30 files)"
  head -n 30 "$TMP/notapplied.txt" | while IFS= read -r p; do
    printf '\n=== %s ===\n' "$p"; diff -u --strip-trailing-cr "$p" "$ROOT/$p" | head -n 70
  done

  section "7. THE FILES WE ALREADY KNOW ABOUT"
  for f in database/seeders/ProductionSimulationSeeder.php app/Http/Middleware/FraudRiskGateMiddleware.php \
           tests/Feature/Payment/WithdrawalDestinationValidationTest.php phpunit.xml config/payment.php config/fraud.php \
           .github/workflows/ci.yml .github/workflows/security.yml; do
    if   [ ! -f "$ROOT/$f" ]; then pk="not in package"
    elif [ ! -f "$f" ];       then pk="package has it, tree does NOT"
    elif [ "$(nh "$ROOT/$f")" = "$(nh "$f")" ]; then pk="tree == package"
    elif in_head "$f" && [ "$(nh "$ROOT/$f")" = "$(nh_head "$f")" ]; then pk="package == HEAD (package did not change it)"
    elif in_head "$f" && [ "$(nh "$f")" = "$(nh_head "$f")" ]; then pk="NOT APPLIED (tree still == HEAD)"
    else pk="tree differs from package"; fi
    printf '%-62s | %s\n' "$f" "$pk"
  done
  for f in database/seeders/ProductionSimulationSeeder.php app/Http/Middleware/FraudRiskGateMiddleware.php; do
    if [ -f "$ROOT/$f" ] && [ -f "$f" ] && [ "$(nh "$ROOT/$f")" != "$(nh "$f")" ]; then
      printf '\n>>> the PACKAGE version of %s (it differs from your tree):\n' "$f"; show "$ROOT/$f" 300
    fi
  done

  section "8. WORKFLOWS THE MERGE HELD BACK"
  ls -la _held_back_by_merge 2>&1
  for w in ci.yml; do
    if [ -f "_held_back_by_merge/$w" ] && [ -f ".github/workflows/$w" ]; then
      printf '\n=== .github/workflows/%s (tree -)  vs  _held_back_by_merge/%s (+) ===\n' "$w" "$w"
      diff -u --strip-trailing-cr ".github/workflows/$w" "_held_back_by_merge/$w" | head -n 200
    fi
  done
  echo; echo "################ END ################"
}

main > "$OUT" 2>&1
echo "Done: $(wc -l < "$OUT") lines written to $OUT"
echo "Windows path: C:\\temp\\tl\\audit-zip.txt  (upload this one file to the chat)"
