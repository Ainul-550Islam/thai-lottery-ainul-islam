#!/usr/bin/env bash
# =============================================================================
# restore.sh — restore an encrypted archive into a NAMED database.
#
#   # the safe shape: restore into a scratch database, verify, then swap
#   scripts/ops/restore.sh backup_lottery_ledger_....sql.gz.enc \
#       --database tl_restore_drill --recreate
#
#   # the dangerous shape: restore over the live database
#   CONFIRM=tl_live scripts/ops/restore.sh archive.enc \
#       --database tl_live --allow-live --i-understand-this-overwrites-data
#
# WHY THIS SCRIPT IS SO FUSSY
#   A restore is the only operation in this repository that destroys data by
#   design. Everything here is arranged so that the destructive path requires
#   four independent deliberate acts:
#
#     1. name the exact target database (there is no "current database" default);
#     2. pass --allow-live if the target is the database the application uses;
#     3. pass --i-understand-this-overwrites-data;
#     4. export CONFIRM=<database name> so the confirmation is specific and
#        appears in the shell history with the target attached.
#
#   And one thing that is not negotiable: the archive is authenticated and
#   extracted into a private temporary file BEFORE the database client is
#   invoked. A truncated or tampered archive is refused, not imported
#   half-way.
#
# EXIT CODES
#   0 restored · 1 failure · 2 refused (preconditions unmet)
# =============================================================================
set -Eeuo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
. "${SCRIPT_DIR}/lib/common.sh"

ARCHIVE=""
DATABASE=""
RECREATE=0
ALLOW_LIVE=0
UNDERSTAND=0
SKIP_VERIFY=0
KEEP_PLAINTEXT=0
CONNECTION=""

usage() {
    cat <<'USAGE'
Usage: scripts/ops/restore.sh ARCHIVE --database NAME [options]

  ARCHIVE                                Path to the .sql.gz.enc archive
  --database NAME                        Target database (required, explicit)
  --connection NAME                      Laravel connection to read host/port/user from
  --recreate                             DROP and CREATE the target first
  --allow-live                           Permit restoring over the app's own database
  --i-understand-this-overwrites-data    Second explicit acknowledgement
  --skip-verify                          Skip the pre-restore verification pass
  --keep-plaintext                       Do not shred the temporary SQL file
  -h, --help                             This text

Environment:
  CONFIRM=<database name>                Required for every restore
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --database)                          DATABASE="${2:?}"; shift 2 ;;
        --connection)                        CONNECTION="${2:?}"; shift 2 ;;
        --recreate)                          RECREATE=1; shift ;;
        --allow-live)                        ALLOW_LIVE=1; shift ;;
        --i-understand-this-overwrites-data) UNDERSTAND=1; shift ;;
        --skip-verify)                       SKIP_VERIFY=1; shift ;;
        --keep-plaintext)                    KEEP_PLAINTEXT=1; shift ;;
        -h|--help)                           usage; exit 0 ;;
        -*)                                  die "Unknown option: $1" ;;
        *)                                   ARCHIVE="$1"; shift ;;
    esac
done

ROOT="$(repo_root "${SCRIPT_DIR}")"
require_repo_root "${ROOT}" >/dev/null
require_php

[[ -n "${ARCHIVE}" ]] || refuse "An archive path is required (try --help)."
[[ -f "${ARCHIVE}" ]] || refuse "Archive not found: ${ARCHIVE}"
[[ -n "${DATABASE}" ]] || refuse "--database is required. There is deliberately no default target."
assert_identifier "${DATABASE}" "database name"

# ---------------------------------------------------------------------------
# Confirmation gate
# ---------------------------------------------------------------------------
require_confirmation "${DATABASE}" \
    "This will write into the database '${DATABASE}'. Every existing row in it is at risk."

# ---------------------------------------------------------------------------
# Production guard
# ---------------------------------------------------------------------------
APP_ENV_VALUE="$(app_env "${ROOT}")"
CONFIGURED_DB="$(php -r '
    $root = $argv[1];
    require $root."/vendor/autoload.php";
    $app = require $root."/bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    echo (string) config("database.connections.".($argv[2] ?: config("database.default")).".database");
' "${ROOT}" "${CONNECTION}" 2>/dev/null || true)"

if [[ -n "${CONFIGURED_DB}" && "${DATABASE}" == "${CONFIGURED_DB}" && "${ALLOW_LIVE}" -eq 0 ]]; then
    refuse "Refusing to restore into '${DATABASE}': it is the database this application is configured to use. Restore into a scratch database and swap; pass --allow-live if you really mean it."
fi

if is_production "${APP_ENV_VALUE}" && [[ "${ALLOW_LIVE}" -eq 1 ]]; then
    if [[ "${UNDERSTAND}" -eq 0 ]]; then
        refuse "This host is production and the target is the live database. Pass --i-understand-this-overwrites-data as well."
    fi
    warn "Restoring into the LIVE database on a production host. Stop the queue workers first (scripts/ops/deploy.sh handles this)."
fi

if [[ "${RECREATE}" -eq 1 && "${ALLOW_LIVE}" -eq 1 && "${APP_ENV_VALUE}" == "production" ]]; then
    refuse "--recreate on the live production database would destroy it before the first byte is imported. Use a scratch database."
fi

# ---------------------------------------------------------------------------
# Verify before touching a database
# ---------------------------------------------------------------------------
if [[ "${SKIP_VERIFY}" -eq 0 ]]; then
    info "Verifying the archive before restore"
    artisan "${ROOT}" backup:verify "${ARCHIVE}" --json >/dev/null \
        || die "Archive failed verification. A restore from an unverified archive is a guess, not a recovery."
    ok "Archive authenticated"
fi

PLAINTEXT="$(mktemp "${TMPDIR:-/tmp}/tl-restore-XXXXXXXX.sql")"
chmod 600 "${PLAINTEXT}"

cleanup() {
    if [[ "${KEEP_PLAINTEXT}" -eq 0 && -f "${PLAINTEXT}" ]]; then
        # shred if available, otherwise overwrite then unlink. A plaintext
        # ledger dump left in /tmp is exactly the artifact an attacker wants.
        if command -v shred >/dev/null 2>&1; then
            shred -u "${PLAINTEXT}" 2>/dev/null || rm -f "${PLAINTEXT}"
        else
            dd if=/dev/urandom of="${PLAINTEXT}" bs=4096 count=1 conv=notrunc 2>/dev/null || true
            rm -f "${PLAINTEXT}"
        fi
    fi
}
trap cleanup EXIT

info "Extracting (authenticated) archive"
artisan "${ROOT}" backup:extract "${ARCHIVE}" --output="${PLAINTEXT}" --force >/dev/null
ok "Extracted to a private temporary file"

# ---------------------------------------------------------------------------
# Restore
# ---------------------------------------------------------------------------
info "Restoring into database '${DATABASE}'"
RESTORE_ARGS=(--database="${DATABASE}")
[[ -n "${CONNECTION}" ]] && RESTORE_ARGS+=(--connection="${CONNECTION}")
[[ "${RECREATE}" -eq 1 ]] && RESTORE_ARGS+=(--recreate)
[[ "${ALLOW_LIVE}" -eq 1 ]] && RESTORE_ARGS+=(--allow-live-target)

if ! artisan "${ROOT}" db:restore-file "${PLAINTEXT}" "${RESTORE_ARGS[@]}"; then
    die "Restore failed. The database may be partially imported — do not start the application until it is reconciled."
fi

ok "Restore completed into '${DATABASE}'"
info "Next: run migrations for this release (php artisan migrate --force) and verify with scripts/ops/healthcheck.sh"
exit 0
