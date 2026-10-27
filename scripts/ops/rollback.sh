#!/usr/bin/env bash
# =============================================================================
# rollback.sh — return the APPLICATION to the previous release.
#
#   scripts/ops/rollback.sh --list                 # what releases exist
#   scripts/ops/rollback.sh --dry-run              # show what would happen
#   scripts/ops/rollback.sh --yes                  # switch to the previous release
#   scripts/ops/rollback.sh --yes --to <release>   # switch to a named release
#
# WHAT IT RECOVERS, AND WHAT IT DOES NOT
#   It recovers code: the `current` symlink moves back, caches are rebuilt and
#   workers are restarted. That is safe, fast and reversible.
#
#   It does NOT restore the database. A migration has already run; pointing old
#   code at a migrated schema is usually fine and sometimes not, but restoring a
#   backup silently would discard every bet placed since it was taken. The data
#   decision is printed as an explicit next step with the exact command, and it
#   is a human's call — see docs/ops/OPS-RUNBOOK.md.
#
# EXPECTED LAYOUT (release-directory deploys)
#   /srv/thai-lottery/releases/20261024T021500Z/
#   /srv/thai-lottery/releases/20261023T021500Z/
#   /srv/thai-lottery/current -> releases/20261024T021500Z
#
# EXIT CODES: 0 rolled back · 1 failed · 2 refused
# =============================================================================
set -Eeuo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
. "${SCRIPT_DIR}/lib/common.sh"

RELEASES_DIR="${RELEASES_DIR:-}"
TARGET_RELEASE=""
LIST=0
DRY_RUN=0
YES=0

usage() {
    cat <<'USAGE'
Usage: scripts/ops/rollback.sh [options]

  --releases-dir DIR   Release root (default: $RELEASES_DIR or ../releases of the app)
  --list               List releases, newest first, and mark the current one
  --to RELEASE         Switch to this release instead of the previous one
  --dry-run            Show the plan only
  --yes                Required to change the symlink
  -h, --help           This text

Environment:
  RELEASES_DIR         Default release root
  SMOKE_BASE_URL       Used by the post-rollback smoke test
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --releases-dir) RELEASES_DIR="${2:?}"; shift 2 ;;
        --to)           TARGET_RELEASE="${2:?}"; shift 2 ;;
        --list)         LIST=1; shift ;;
        --dry-run)      DRY_RUN=1; shift ;;
        --yes)          YES=1; shift ;;
        -h|--help)      usage; exit 0 ;;
        *)              die "Unknown option: $1" ;;
    esac
done

ROOT="$(repo_root "${SCRIPT_DIR}")"
require_repo_root "${ROOT}" >/dev/null
require_php
require_command git

# Resolve the release root: explicit flag, environment, or the sibling
# `releases` directory of a standard release-directory layout.
if [[ -z "${RELEASES_DIR}" ]]; then
    if [[ -d "${ROOT}/../releases" ]]; then
        RELEASES_DIR="$(cd -- "${ROOT}/../releases" && pwd)"
    else
        RELEASES_DIR="${ROOT}"
    fi
fi

if [[ ! -d "${RELEASES_DIR}" ]]; then
    die "Release directory does not exist: ${RELEASES_DIR}"
fi

# A directory only counts as a release if it actually contains an application.
# Without this filter, invoking the script in a plain checkout walks the app's
# own subdirectories (vendor, storage, app, …) and offers `vendor` as a rollback
# target — which is worse than refusing, because it looks like a plan.
mapfile -t RELEASES < <(
    find "${RELEASES_DIR}" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null \
        | while IFS= read -r candidate; do
            # `if` rather than `&&` so a non-matching candidate does not make the
            # loop exit non-zero under `set -e`.
            if [[ -f "${RELEASES_DIR}/${candidate}/artisan" ]]; then
                printf '%s\n' "${candidate}"
            fi
        done \
        | sort -r
)

if [[ "${#RELEASES[@]}" -eq 0 ]]; then
    if [[ "${LIST}" -eq 1 ]]; then
        printf '  (none) %s is not a release-directory layout: no sibling directory contains artisan.\n' "${RELEASES_DIR}"
        exit 0
    fi

    die "No releases found in ${RELEASES_DIR} (a release is a directory containing artisan). This host does not look like it uses release-directory deploys; roll back with your deployment tooling and follow docs/ops/OPS-RUNBOOK.md."
fi

# The `current` symlink normally sits beside `releases/`, but some layouts keep
# it inside the release root. Resolve both, and fall back to the script's own
# directory only as a last resort.
resolve_current() {
    local candidate

    for candidate in "${RELEASES_DIR}/current" "${RELEASES_DIR}/../current" "${ROOT}"; do
        if [[ -e "${candidate}" ]]; then
            basename "$(readlink -f "${candidate}")"
            return 0
        fi
    done

    printf 'unknown'
}

CURRENT="$(resolve_current)"

if [[ "${LIST}" -eq 1 ]]; then
    info "Releases in ${RELEASES_DIR} (newest first):"
    for release in "${RELEASES[@]}"; do
        if [[ "${release}" == "${CURRENT}" ]]; then
            printf '  * %s  (current)\n' "${release}"
        else
            printf '    %s\n' "${release}"
        fi
    done
    exit 0
fi

if [[ -n "${TARGET_RELEASE}" ]]; then
    PREVIOUS="${TARGET_RELEASE}"
else
    PREVIOUS=""
    for release in "${RELEASES[@]}"; do
        if [[ "${release}" != "${CURRENT}" ]]; then
            PREVIOUS="${release}"
            break
        fi
    done
fi

if [[ -z "${PREVIOUS}" ]]; then
    die "There is no previous release to roll back to (current: ${CURRENT:-none})."
fi

if [[ ! -d "${RELEASES_DIR}/${PREVIOUS}" ]]; then
    die "Release does not exist: ${PREVIOUS}"
fi

if [[ ! -f "${RELEASES_DIR}/${PREVIOUS}/artisan" ]]; then
    die "${RELEASES_DIR}/${PREVIOUS} is not an application release (no artisan file). Refusing to point the deployment at it."
fi

if [[ "${PREVIOUS}" == "${CURRENT}" ]]; then
    die "The current release is already ${CURRENT}; choose a different release with --to <release>."
fi

info "Current release: ${CURRENT:-unknown}"
info "Target release:  ${PREVIOUS}"

if [[ -d "${RELEASES_DIR}/${PREVIOUS}/.git" ]] || git -C "${RELEASES_DIR}/${PREVIOUS}" rev-parse --git-dir >/dev/null 2>&1; then
    info "Target commit:  $(git -C "${RELEASES_DIR}/${PREVIOUS}" rev-parse --short HEAD 2>/dev/null || echo unknown)"
fi

if [[ "${DRY_RUN}" -eq 1 ]]; then
    cat <<PLAN
Plan (dry run):
  1. point ${RELEASES_DIR}/current at ${PREVIOUS}
  2. php artisan optimize:clear && config:cache && route:cache && view:cache
  3. php artisan queue:restart (+ horizon:terminate)
  4. scripts/ops/smoke-test.sh
  5. print the database decision (nothing is restored automatically)

Note: migrations are NOT reverted. If the failed release ran a destructive
migration, restoring the pre-release backup is a separate, manual decision:
  CONFIRM=<db> scripts/ops/restore.sh <archive> --database <db> --allow-live \\
      --i-understand-this-overwrites-data
PLAN
    exit 0
fi

if [[ "${YES}" -eq 0 ]]; then
    die "Refusing to switch releases without --yes."
fi

if is_production "$(app_env "${ROOT}")"; then
    require_confirmation "${PREVIOUS}" "Rolling production back to ${PREVIOUS}."
fi

info "Switching current -> ${PREVIOUS}"
ln -sfn "${RELEASES_DIR}/${PREVIOUS}" "${RELEASES_DIR}/current.tmp"
mv -Tf "${RELEASES_DIR}/current.tmp" "${RELEASES_DIR}/current"
ok "Symlink switched"

NEW_ROOT="${RELEASES_DIR}/${PREVIOUS}"

if [[ -f "${NEW_ROOT}/artisan" ]]; then
    artisan "${NEW_ROOT}" optimize:clear >/dev/null || warn "optimize:clear reported a problem."
    artisan "${NEW_ROOT}" config:cache >/dev/null || warn "config:cache failed."
    artisan "${NEW_ROOT}" route:cache >/dev/null || warn "route:cache failed."
    artisan "${NEW_ROOT}" view:cache >/dev/null || warn "view:cache failed."
    artisan "${NEW_ROOT}" queue:restart >/dev/null || warn "queue:restart failed."
    ok "Caches rebuilt and workers signalled"
fi

if [[ -x "${NEW_ROOT}/scripts/ops/smoke-test.sh" ]]; then
    if ! "${NEW_ROOT}/scripts/ops/smoke-test.sh"; then
        warn "Smoke test still failing after the rollback. The problem is probably not the release."
    fi
fi

cat <<'NEXT'

Rollback of the application is complete.

Next steps (manual, and deliberately not automated):
  1. Confirm the release is serving: scripts/ops/smoke-test.sh
  2. Decide about the database. Migrations from the failed release are still
     applied. If that release dropped or renamed anything, restore the backup
     taken by scripts/ops/deploy.sh (it is verified, encrypted, and its SHA-256
     is in the matching .manifest.json):
       scripts/ops/verify-backup.sh --deep --scratch-database tl_restore_drill
       CONFIRM=<db> scripts/ops/restore.sh <archive> --database <db> --allow-live \
           --i-understand-this-overwrites-data
  3. Record what happened, which release was bad, and which migration is
     implicated in docs/ops/INCIDENTS.md.
NEXT

exit 0
