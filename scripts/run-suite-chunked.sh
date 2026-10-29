#!/usr/bin/env bash
# Run the complete PHPUnit tree in separate directory-sized processes.
# The loose tests/Feature/*Test.php files are a distinct chunk, not silently
# omitted. Override CHUNKED_OUTPUT_DIR to keep several censuses side by side.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1
OUT="${CHUNKED_OUTPUT_DIR:-/tmp/chunked}"
mkdir -p "$OUT"
: > "$OUT/summary.txt"
: > "$OUT/failures.txt"

chunks=()
for d in tests/Feature/*/; do
    [ -d "$d" ] && chunks+=("${d%/}")
done
shopt -s nullglob
feature_loose=(tests/Feature/*Test.php)
root_loose=(tests/*Test.php)
shopt -u nullglob
[ ${#feature_loose[@]} -eq 0 ] || chunks+=("__feature_loose__")
for d in tests/Unit tests/Integration tests/Security; do
    [ -d "$d" ] && chunks+=("$d")
done
[ ${#root_loose[@]} -eq 0 ] || chunks+=("__root_loose__")

total=${#chunks[@]}
i=0
fail_chunks=0
for chunk in "${chunks[@]}"; do
    i=$((i + 1))
    case "$chunk" in
        __feature_loose__) label='tests/Feature (loose)'; targets=("${feature_loose[@]}");;
        __root_loose__)    label='tests (loose)'; targets=("${root_loose[@]}");;
        *)                 label="$chunk"; targets=("$chunk");;
    esac
    name="$(printf '%s' "$label" | tr '/ *()' '_____')"
    log="$OUT/$name.log"
    printf '[%2d/%2d] %-42s ' "$i" "$total" "$label" >> "$OUT/summary.txt"
    start=$(date +%s)
    timeout 3600 vendor/bin/phpunit "${targets[@]}" --log-junit "$OUT/$name.xml" > "$log" 2>&1
    rc=$?
    elapsed=$(( $(date +%s) - start ))
    line=$(grep -E '^(Tests: |OK \(|No tests executed)' "$log" | tail -1)
    [ -n "$line" ] || line="(no summary line; exit $rc)"
    printf '%s  [exit=%d, %ds]\n' "$line" "$rc" "$elapsed" >> "$OUT/summary.txt"
    if [ "$rc" -ne 0 ]; then
        fail_chunks=$((fail_chunks + 1))
        grep -E '^[0-9]+\) Tests\\' "$log" | sed "s|^[0-9]*) |$label :: |" >> "$OUT/failures.txt" || :
    fi
done

echo >> "$OUT/summary.txt"
echo "chunks=$total  chunks_with_failures=$fail_chunks" >> "$OUT/summary.txt"
python3 scripts/aggregate-chunked-census.py "$OUT/summary.txt" | tee -a "$OUT/summary.txt"
aggregate_status=${PIPESTATUS[0]}
echo 'CHUNKED_RUN_DONE' >> "$OUT/summary.txt"
if [ "$aggregate_status" -ne 0 ] || [ "$fail_chunks" -ne 0 ]; then
    exit 1
fi
