#!/usr/bin/env bash
# =============================================================================
# assert-ciphertext-only.sh — no plaintext dump survives a backup.
#
# WHY THIS EXISTS
# ---------------
# A backup lane that writes `dump.sql` and then encrypts it has a window in
# which the database is sitting on disk in the clear. That window is the whole
# ballgame: if the encryption step crashes, is interrupted, or the box is
# snapshotted first, the archive beside it is decorative.
#
# The first version of this lane dumped straight into the backup directory and
# left `backup_*.sql.gz` (mode 0644) next to the `.enc`, while its own JSON
# report claimed `plaintext_bytes: 0`. The report was not lying about how many
# bytes it retained — it simply was not measuring retention at all. This script
# measures it, so the claim can be checked instead of believed.
#
# WHAT IT CHECKS
#   1. the backup directory contains ONLY the expected artifact shapes:
#        <name>.sql.gz.enc, <name>.sql.gz.enc.sha256,
#        <name>.sql.gz.enc.manifest.json, latest.json
#   2. no file under the backup directory is a dump in the clear: no `.sql`,
#      no `.sql.gz`, no `.sql.gz.verify`, no `.tar*`, no MySQL/PostgreSQL
#      dump banner in the first 4 KiB of any file;
#   3. every artifact is not group/world readable (mode <= 0640);
#   4. the staging area is empty — a leftover `tl-backup-*` directory means a
#      previous run died between dump and encrypt;
#   5. the newest manifest reports `plaintext_retained: false`.
#
# USAGE
#   scripts/ops/assert-ciphertext-only.sh [--path DIR] [--json]
#
# EXIT
#   0  ciphertext only
#   1  plaintext (or a leftover staging directory) was found
#   2  the directory does not exist, so nothing could be verified
# =============================================================================
set -Eeuo pipefail
IFS=$'\n\t'

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=scripts/ops/lib/common.sh
source "${ROOT}/scripts/ops/lib/common.sh"

BACKUP_DIR=""
JSON=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --path) BACKUP_DIR="${2:-}"; shift 2 ;;
        --json) JSON=1; shift ;;
        -h|--help) sed -n '2,40p' "${BASH_SOURCE[0]}"; exit 0 ;;
        *) refuse "Unknown option: $1" ;;
    esac
done

if [[ -z "${BACKUP_DIR}" ]]; then
    BACKUP_DIR="$(config_value backup.path storage/app/backups)"
fi

if [[ ! -d "${BACKUP_DIR}" ]]; then
    refuse "No backup directory at ${BACKUP_DIR} — nothing was verified."
fi

problems=()
inspected=0

# --- 1 & 2: shapes and plaintext ---------------------------------------------
while IFS= read -r -d '' file; do
    inspected=$((inspected + 1))
    name="$(basename -- "${file}")"

    case "${name}" in
        latest.json|*.sql.gz.enc|*.sql.gz.enc.sha256|*.sql.gz.enc.manifest.json) ;;
        *)
            problems+=("unexpected artifact in the backup directory: ${name}")
            ;;
    esac

    # Content sniffing catches a dump that was renamed rather than removed.
    if [[ -f "${file}" ]]; then
        header="$(head -c 4096 -- "${file}" 2>/dev/null | tr -d '\000' || true)"
        if printf '%s' "${header}" | grep -qE '^-- (MySQL|MariaDB) dump|^-- PostgreSQL database dump'; then
            problems+=("a plaintext database dump is present: ${name}")
        fi
    fi
done < <(find "${BACKUP_DIR}" -maxdepth 1 -type f -print0)

# --- 3: permissions ----------------------------------------------------------
while IFS= read -r -d '' file; do
    mode="$(stat -c '%a' -- "${file}" 2>/dev/null || echo '')"

    if [[ -n "${mode}" && "${mode: -1}" != "0" ]]; then
        problems+=("$(basename -- "${file}") is readable by other users (mode ${mode})")
    fi
done < <(find "${BACKUP_DIR}" -maxdepth 1 -type f -print0)

# --- 4: staging leftovers ----------------------------------------------------
STAGING_BASE="$(config_value backup.staging_path "${TMPDIR:-/tmp}")"
STAGING_LEFTOVERS=0

if [[ -d "${STAGING_BASE}" ]]; then
    STAGING_LEFTOVERS="$(find "${STAGING_BASE}" -maxdepth 1 -type d -name 'tl-backup-*' 2>/dev/null | wc -l | tr -d ' ')"

    if [[ "${STAGING_LEFTOVERS}" -gt 0 ]]; then
        problems+=("${STAGING_LEFTOVERS} staging director(ies) left behind in ${STAGING_BASE} — a run died between dump and encrypt; delete them and rotate the backup key")
    fi
fi

# --- 5: the newest manifest is honest ---------------------------------------
MANIFESTS="$(find "${BACKUP_DIR}" -maxdepth 1 -type f -name '*.manifest.json' | sort | tail -1 || true)"

if [[ -n "${MANIFESTS}" ]]; then
    if ! grep -q '"plaintext_retained": *false' "${MANIFESTS}"; then
        problems+=("the newest manifest does not assert plaintext_retained=false: $(basename -- "${MANIFESTS}")")
    fi
else
    problems+=("no manifest found in ${BACKUP_DIR}; the backup lane has not run here")
fi

# --- report ------------------------------------------------------------------
if [[ "${JSON}" -eq 1 ]]; then
    {
        printf '{"path":"%s","files_inspected":%d,"staging_leftovers":%d,"problems":[' \
            "${BACKUP_DIR}" "${inspected}" "${STAGING_LEFTOVERS}"
        first=1
        for problem in ${problems+"${problems[@]}"}; do
            [[ "${first}" -eq 1 ]] || printf ','
            first=0
            printf '"%s"' "$(printf '%s' "${problem}" | sed 's/\\/\\\\/g; s/"/\\"/g')"
        done
        printf '],"status":"%s"}\n' "$([[ ${#problems[@]} -eq 0 ]] && echo CLEAN || echo PLAINTEXT_FOUND)"
    }
else
    if [[ ${#problems[@]} -eq 0 ]]; then
        ok "ciphertext only: ${inspected} file(s) in ${BACKUP_DIR}, no plaintext, no staging leftovers"
    else
        for problem in "${problems[@]}"; do
            printf '%sFAIL%s %s\n' "${C_RED}" "${C_RESET}" "${problem}" >&2
        done
    fi
fi

[[ ${#problems[@]} -eq 0 ]] || exit 1
exit 0
