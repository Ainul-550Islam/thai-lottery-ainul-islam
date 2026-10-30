#!/usr/bin/env bash
# =============================================================================
# backup.sh — take, verify and (optionally) ship an encrypted database backup.
#
#   scripts/ops/backup.sh                        # dump + encrypt + verify + prune
#   scripts/ops/backup.sh --dry-run              # show the plan, write nothing
#   scripts/ops/backup.sh --upload               # also copy to the backup disk
#   scripts/ops/backup.sh --output /srv/backups  # explicit destination
#
# WHAT MAKES THIS A BACKUP AND NOT A HOPE
#   1. `ops:preflight` runs first with connectivity probes, so a backup is
#      never taken from a host that cannot reach its own database.
#   2. The dump is produced by the real client (see DatabaseDumpService), and
#      its completeness marker is checked before encryption.
#   3. The archive is decrypted and re-authenticated immediately after it is
#      written. A backup that cannot be read back is deleted, not kept, because
#      a directory full of unreadable archives is worse than an empty one: the
#      retention pruner and the "last backup" panel both count them as good.
#   4. The key is read from the environment or a mounted file and is NEVER
#      echoed, never passed as argv, and never written into the archive.
#
# EXIT CODES
#   0 success · 1 failure · 2 refused (preconditions unmet) · 3 verification
# =============================================================================
set -Eeuo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
. "${SCRIPT_DIR}/lib/common.sh"

OUTPUT_DIR=""
UPLOAD=0
DRY_RUN=0
SKIP_PREFLIGHT=0
KEEP=""
JSON=0

usage() {
    cat <<'USAGE'
Usage: scripts/ops/backup.sh [options]

  --output DIR      Directory for the archive (default: config backup.path)
  --keep N          Minimum number of archives to retain regardless of age
  --upload          Ship the finished archive to the configured backup disk
  --dry-run         Print the plan and exit without writing anything
  --skip-preflight  Do not run ops:preflight (only for a documented emergency)
  --json            Machine-readable summary on stdout
  -h, --help        This text

Environment:
  BACKUP_ENCRYPTION_KEY        required unless BACKUP_ENCRYPTION_KEY_FILE is set
  BACKUP_ENCRYPTION_KEY_FILE   path to a mounted secret containing the key
  BACKUP_PATH                  default output directory
  BACKUP_RETENTION_DAYS        age after which archives are pruned
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --output)         OUTPUT_DIR="${2:?--output needs a value}"; shift 2 ;;
        --keep)           KEEP="${2:?--keep needs a value}"; shift 2 ;;
        --upload)         UPLOAD=1; shift ;;
        --dry-run)        DRY_RUN=1; shift ;;
        --skip-preflight) SKIP_PREFLIGHT=1; shift ;;
        --json)           JSON=1; shift ;;
        -h|--help)        usage; exit 0 ;;
        *)                die "Unknown option: $1 (try --help)" ;;
    esac
done

ROOT="$(repo_root "${SCRIPT_DIR}")"
require_repo_root "${ROOT}" >/dev/null
require_php

# ---------------------------------------------------------------------------
# 1. Key material
# ---------------------------------------------------------------------------
# Checked before anything else so the failure is "no key" rather than "dumped
# 4GB and then discovered there is no key". Neither branch prints the value.
if [[ -n "${BACKUP_ENCRYPTION_KEY_FILE:-}" ]]; then
    [[ -r "${BACKUP_ENCRYPTION_KEY_FILE}" ]] \
        || die "BACKUP_ENCRYPTION_KEY_FILE is set but not readable: ${BACKUP_ENCRYPTION_KEY_FILE}"
    info "Backup key source: file $(redact "$(cat "${BACKUP_ENCRYPTION_KEY_FILE}")")"
elif [[ -n "${BACKUP_ENCRYPTION_KEY:-}" ]]; then
    info "Backup key source: environment $(redact "${BACKUP_ENCRYPTION_KEY}")"
else
    die "No backup key. Set BACKUP_ENCRYPTION_KEY (>= 32 chars) or BACKUP_ENCRYPTION_KEY_FILE. Refusing to write an archive that cannot be protected."
fi

# ---------------------------------------------------------------------------
# 2. Preflight (configuration + connectivity, secret-free output)
# ---------------------------------------------------------------------------
if [[ "${SKIP_PREFLIGHT}" -eq 0 ]]; then
    info "Running deployment preflight"
    if ! artisan "${ROOT}" ops:preflight --json > /tmp/tl-preflight.$$.json 2>/dev/null; then
        printf '%s\n' "$(cat /tmp/tl-preflight.$$.json)" >&2
        rm -f /tmp/tl-preflight.$$.json
        die "Preflight failed; refusing to take a backup from a host whose configuration is broken."
    fi
    rm -f /tmp/tl-preflight.$$.json
    ok "Preflight passed"
fi

# ---------------------------------------------------------------------------
# 3. Take the backup
# ---------------------------------------------------------------------------
ARGS=()
[[ -n "${OUTPUT_DIR}" ]] && ARGS+=("--output=${OUTPUT_DIR}")
[[ -n "${KEEP}" ]]       && ARGS+=("--keep=${KEEP}")
[[ "${UPLOAD}" -eq 1 ]]  && ARGS+=("--upload")
[[ "${DRY_RUN}" -eq 1 ]] && ARGS+=("--dry-run")
[[ "${JSON}" -eq 1 ]]    && ARGS+=("--json")

info "Taking encrypted backup"
RESULT_FILE="$(mktemp)"
trap 'rm -f "${RESULT_FILE}"' EXIT

if ! artisan "${ROOT}" backup:execute-encrypted-worm "${ARGS[@]}" --json > "${RESULT_FILE}"; then
    cat "${RESULT_FILE}" >&2 || true
    die "The backup command failed. Nothing has been written that the retention pruner would count as a good archive."
fi

STATUS="$(php -r '$d=json_decode((string) file_get_contents($argv[1]), true); echo is_array($d) ? (string)($d["status"] ?? "UNKNOWN") : "UNKNOWN";' "${RESULT_FILE}")"

if [[ "${STATUS}" == "DRY_RUN" ]]; then
    cat "${RESULT_FILE}"
    ok "Dry run complete; nothing was written."
    exit 0
fi

if [[ "${STATUS}" != "VERIFIED" ]]; then
    cat "${RESULT_FILE}" >&2
    die "Backup finished without a VERIFIED status (got ${STATUS})."
fi

# The command reports the directory it actually wrote to, so this script never
# has to re-derive configuration (and cannot disagree with the command).
ARCHIVE="$(php -r '
    $d = json_decode((string) file_get_contents($argv[1]), true);
    echo rtrim((string) ($d["output_directory"] ?? ""), "/")."/".($d["archive"] ?? "");
' "${RESULT_FILE}")"
ARCHIVE_DIR="$(dirname "${ARCHIVE}")"

[[ -f "${ARCHIVE}" ]] || die "The command reported success but the archive is not on disk: $(basename "${ARCHIVE}")"

# ---------------------------------------------------------------------------
# 4. Independent verification pass
# ---------------------------------------------------------------------------
# The command verifies its own output on the hot path; this second pass runs
# through a different entrance (`backup:verify`) and is what a scheduled
# monitor and a restore drill will use. Two independent checks on the same
# artifact is the difference between a backup and a claim.
info "Verifying archive: $(basename "${ARCHIVE}")"
if ! artisan "${ROOT}" backup:verify "${ARCHIVE}" --json > "${RESULT_FILE}.verify"; then
    cat "${RESULT_FILE}.verify" >&2 || true
    find "${ARCHIVE_DIR}" -maxdepth 1 -name "$(basename "${ARCHIVE}")*" -delete 2>/dev/null || true
    die "Verification failed and the archive was removed. A backup that cannot be read back must never be counted as one."
fi

if [[ "${JSON}" -eq 1 ]]; then
    php -r '
        $manifest = json_decode((string) file_get_contents($argv[1]), true) ?: [];
        $verify = json_decode((string) file_get_contents($argv[2]), true) ?: [];
        echo json_encode([
            "status" => "VERIFIED",
            "archive" => $manifest["archive"] ?? null,
            "sha256" => $manifest["sha256"] ?? null,
            "bytes" => $manifest["bytes"] ?? null,
            "upload" => $manifest["upload"] ?? "NOT_REQUESTED",
            "pruned" => $manifest["pruned"] ?? 0,
            "verification" => $verify["checks"] ?? [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    ' "${RESULT_FILE}" "${RESULT_FILE}.verify"
else
    ok "Backup verified: $(basename "${ARCHIVE}")"
    printf '    SHA-256: %s\n' "$(php -r '$d=json_decode((string) file_get_contents($argv[1]),true); echo $d["sha256"] ?? "unknown";' "${RESULT_FILE}")"
fi

exit 0
