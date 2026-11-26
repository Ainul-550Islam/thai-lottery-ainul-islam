#!/usr/bin/env bash
# =============================================================================
# verify-backup.sh — prove an archive is readable before you need it to be.
#
#   scripts/ops/verify-backup.sh                       # newest archive
#   scripts/ops/verify-backup.sh path/to/archive.enc   # a specific archive
#   scripts/ops/verify-backup.sh --all                 # every archive on disk
#   scripts/ops/verify-backup.sh --deep --scratch-database tl_restore_drill
#
# This is the script a nightly monitor should run. It never writes plaintext to
# a durable location and never touches the live database: --deep restores into
# a scratch database whose name the caller must supply.
#
# EXIT CODES
#   0 every checked archive verified · 1 at least one failed · 2 refused
# =============================================================================
set -Eeuo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
. "${SCRIPT_DIR}/lib/common.sh"

DEEP=0
SCRATCH_DB=""
CHECK_ALL=0
TARGET=""
JSON=0

usage() {
    cat <<'USAGE'
Usage: scripts/ops/verify-backup.sh [ARCHIVE] [options]

  ARCHIVE                  Archive to verify (default: newest in the backup dir)
  --all                    Verify every archive in the backup directory
  --deep                   Also restore into --scratch-database and compare
  --scratch-database NAME  Target for --deep (must not be the live database)
  --json                   Machine-readable output
  -h, --help               This text
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --all)              CHECK_ALL=1; shift ;;
        --deep)             DEEP=1; shift ;;
        --scratch-database) SCRATCH_DB="${2:?}"; shift 2 ;;
        --json)             JSON=1; shift ;;
        -h|--help)          usage; exit 0 ;;
        -*)                 die "Unknown option: $1" ;;
        *)                  TARGET="$1"; shift ;;
    esac
done

ROOT="$(repo_root "${SCRIPT_DIR}")"
require_repo_root "${ROOT}" >/dev/null
require_php

BACKUP_DIR="${BACKUP_PATH:-${ROOT}/storage/app/backups}"

if [[ "${DEEP}" -eq 1 && -z "${SCRATCH_DB}" ]]; then
    die "--deep requires --scratch-database NAME. Refusing to restore into an unnamed target."
fi

if [[ "${DEEP}" -eq 1 ]]; then
    assert_identifier "${SCRATCH_DB}" "scratch database"
fi

collect_archives() {
    if [[ -n "${TARGET}" ]]; then
        printf '%s' "${TARGET}"
        return
    fi

    if [[ "${CHECK_ALL}" -eq 1 ]]; then
        find "${BACKUP_DIR}" -maxdepth 1 -name 'backup_lottery_ledger_*.sql.gz.enc' -type f | sort
        return
    fi

    # Newest only: this is the one a monitor cares about.
    find "${BACKUP_DIR}" -maxdepth 1 -name 'backup_lottery_ledger_*.sql.gz.enc' -type f -printf '%T@ %p\n' 2>/dev/null \
        | sort -rn | head -n1 | cut -d' ' -f2-
}

ARCHIVES="$(collect_archives)"

if [[ -z "${ARCHIVES}" ]]; then
    die "No archives found in ${BACKUP_DIR}. If this host is supposed to have backups, that is the incident."
fi

FAILED=0
CHECKED=0
SUMMARY="[]"

while IFS= read -r archive; do
    [[ -z "${archive}" ]] && continue
    [[ -f "${archive}" ]] || die "Archive not found: ${archive}"

    CHECKED=$((CHECKED + 1))

    ARGS=("${archive}" --json)
    [[ "${DEEP}" -eq 1 ]] && ARGS+=(--deep "--scratch-database=${SCRATCH_DB}")

    if OUTPUT="$(artisan "${ROOT}" backup:verify "${ARGS[@]}" 2>&1)"; then
        ok "$(basename "${archive}")"
    else
        warn "verification FAILED: $(basename "${archive}")"
        printf '%s\n' "${OUTPUT}" >&2
        FAILED=$((FAILED + 1))
    fi

    SUMMARY="$(php -r '
        $summary = json_decode((string)($argv[1] ?? "[]"), true) ?: [];
        $result  = json_decode((string)($argv[2] ?? "{}"), true) ?: [];
        $summary[] = [
            "archive" => basename((string)($argv[3] ?? "")),
            "status"  => $result["status"] ?? "FAILED",
            "checks"  => $result["checks"] ?? [],
        ];
        echo json_encode($summary);
    ' "${SUMMARY}" "${OUTPUT}" "${archive}")"
done <<< "${ARCHIVES}"

if [[ "${JSON}" -eq 1 ]]; then
    printf '{"checked":%d,"failed":%d,"results":%s}\n' "${CHECKED}" "${FAILED}" "${SUMMARY}"
fi

if [[ "${FAILED}" -gt 0 ]]; then
    die "${FAILED} of ${CHECKED} archive(s) failed verification."
fi

ok "${CHECKED} archive(s) verified."
exit 0
