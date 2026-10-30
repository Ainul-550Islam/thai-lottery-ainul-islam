#!/usr/bin/env bash
# =============================================================================
# deploy.sh — the release sequence, in the order that makes rollback possible.
#
#   scripts/ops/deploy.sh --preflight-only        # gate only, change nothing
#   scripts/ops/deploy.sh --dry-run               # print the plan
#   scripts/ops/deploy.sh --yes                   # actually deploy
#
# ORDER, AND WHY EACH STEP IS WHERE IT IS
#   1. preflight      refuse a misconfigured release before anything is cached
#                     or migrated (config/ops:preflight)
#   2. backup         take a verified encrypted backup BEFORE the migration, so
#                     the "restore to the last good state" option exists for the
#                     rest of the deploy
#   3. migration audit  flag pending migrations that drop or rename columns; a
#                     destructive migration stops the deploy unless it is
#                     explicitly approved (see docs/ops/OPS-RUNBOOK.md)
#   4. maintenance    put the app into maintenance mode (--maintenance/--no-maintenance)
#   5. migrate        php artisan migrate --force
#   6. cache          config/route/view/event caches, built artifacts verified
#   7. restart        queue workers (queue:restart + horizon:terminate)
#   8. smoke          HTTP probes; on failure the deploy fails and prints the
#                     exact rollback command
#
# WHAT THIS SCRIPT WILL NOT DO
#   It will not roll back the database automatically. A silent auto-restore is
#   how a bad deploy becomes a data-loss incident: the deploy is recoverable,
#   the overwritten ledger is not. Rollback guidance is printed instead, and
#   `scripts/ops/rollback.sh` performs the reversible half.
#
# EXIT CODES
#   0 deployed · 1 failed (deploy stopped; see output) · 2 refused
# =============================================================================
set -Eeuo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
. "${SCRIPT_DIR}/lib/common.sh"

YES=0
DRY_RUN=0
PREFLIGHT_ONLY=0
SKIP_BACKUP=0
SKIP_TESTS=0
SKIP_SMOKE=0
MAINTENANCE="auto"
ALLOW_DESTRUCTIVE=0
BASE_URL="${SMOKE_BASE_URL:-}"

usage() {
    cat <<'USAGE'
Usage: scripts/ops/deploy.sh [options]

  --yes                     Required to make any change (no prompt is more
                            dangerous than a prompt that defaults to yes)
  --dry-run                 Print the plan, change nothing
  --preflight-only          Run the preflight gate and stop
  --skip-backup             Do not take a backup first (discouraged)
  --skip-tests              Do not run the PHP test suite
  --skip-smoke              Do not run the HTTP smoke test
  --maintenance / --no-maintenance
                            Force maintenance mode on or off ("auto" enables it
                            in production only)
  --allow-destructive       Permit migrations that drop or rename columns
  --base-url URL            Base URL for the smoke test

Environment:
  SMOKE_BASE_URL            Default for --base-url
  CONFIRM=<APP_ENV>         Required in addition to --yes when APP_ENV=production
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --yes)                 YES=1; shift ;;
        --dry-run)             DRY_RUN=1; shift ;;
        --preflight-only)      PREFLIGHT_ONLY=1; shift ;;
        --skip-backup)         SKIP_BACKUP=1; shift ;;
        --skip-tests)          SKIP_TESTS=1; shift ;;
        --skip-smoke)          SKIP_SMOKE=1; shift ;;
        --maintenance)         MAINTENANCE="on"; shift ;;
        --no-maintenance)      MAINTENANCE="off"; shift ;;
        --allow-destructive)   ALLOW_DESTRUCTIVE=1; shift ;;
        --base-url)            BASE_URL="${2:?}"; shift 2 ;;
        -h|--help)             usage; exit 0 ;;
        *)                     die "Unknown option: $1" ;;
    esac
done

ROOT="$(repo_root "${SCRIPT_DIR}")"
require_repo_root "${ROOT}" >/dev/null
require_php
require_command git

APP_ENV_VALUE="$(app_env "${ROOT}")"
IS_PRODUCTION=0
is_production "${APP_ENV_VALUE}" && IS_PRODUCTION=1

if [[ "${MAINTENANCE}" == "auto" ]]; then
    [[ "${IS_PRODUCTION}" -eq 1 ]] && MAINTENANCE="on" || MAINTENANCE="off"
fi

# A dirty working tree means the release is not what the branch says it is.
if git -C "${ROOT}" rev-parse --git-dir >/dev/null 2>&1; then
    if [[ -n "$(git -C "${ROOT}" status --porcelain --untracked-files=no)" ]]; then
        warn "The working tree has uncommitted changes; the deploy will build something that is not a commit."
    fi
    info "Release commit: $(git -C "${ROOT}" rev-parse --short HEAD)"
fi

info "Environment: $(redact "${APP_ENV_VALUE}") maintenance=${MAINTENANCE}"

# ---------------------------------------------------------------------------
# 0. Consent
# ---------------------------------------------------------------------------
if [[ "${DRY_RUN}" -eq 0 && "${PREFLIGHT_ONLY}" -eq 0 ]]; then
    if [[ "${YES}" -eq 0 ]]; then
        die "Refusing to deploy without --yes. A deploy script that proceeds because it was invoked is a script that runs after a typo."
    fi

    if [[ "${IS_PRODUCTION}" -eq 1 ]]; then
        require_confirmation "${APP_ENV_VALUE}" \
            "This is a PRODUCTION deploy of $(git -C "${ROOT}" rev-parse --short HEAD) into ${APP_ENV_VALUE}."
    fi
fi

if [[ "${DRY_RUN}" -eq 1 ]]; then
    cat <<PLAN
Plan (dry run — nothing will be changed):
  1. php artisan ops:preflight --json
  2. $( [[ "${SKIP_BACKUP}" -eq 1 ]] && echo "SKIPPED backup" || echo "php artisan backup:execute-encrypted-worm + backup:verify" )
  3. php scripts/ops/migration-audit.php (destructive-change gate)
  4. $( [[ "${MAINTENANCE}" == "on" ]] && echo "php artisan down" || echo "no maintenance mode" )
  5. php artisan migrate --force
  6. php artisan config:cache / route:cache / view:cache / event:cache
  7. php artisan queue:restart (+ horizon:terminate when Horizon is installed)
  8. scripts/ops/smoke-test.sh
  9. php artisan up (when maintenance mode was enabled)
On failure: scripts/ops/rollback.sh (application) — the database is NOT rolled back
automatically; see docs/ops/OPS-RUNBOOK.md.
PLAN
    exit 0
fi

# ---------------------------------------------------------------------------
# 1. Preflight
# ---------------------------------------------------------------------------
info "1/9 Deployment preflight"
if ! artisan "${ROOT}" ops:preflight --json > /tmp/tl-deploy-preflight.$$.json; then
    cat /tmp/tl-deploy-preflight.$$.json >&2 || true
    rm -f /tmp/tl-deploy-preflight.$$.json
    die "Preflight failed. Nothing has been changed."
fi
rm -f /tmp/tl-deploy-preflight.$$.json
ok "Preflight passed"

if [[ "${PREFLIGHT_ONLY}" -eq 1 ]]; then
    ok "Preflight-only run complete."
    exit 0
fi

# ---------------------------------------------------------------------------
# 2. Backup
# ---------------------------------------------------------------------------
if [[ "${SKIP_BACKUP}" -eq 1 ]]; then
    warn "Skipping the pre-deploy backup (--skip-backup). The restore option for this deploy does not exist."
else
    info "2/9 Encrypted backup before migration"
    "${SCRIPT_DIR}/backup.sh" --json > /tmp/tl-deploy-backup.$$.json \
        || die "The pre-deploy backup failed. Deploying without a recoverable backup is the one risk this script will not take."
    ok "Backup verified: $(php -r '$d=json_decode((string)file_get_contents($argv[1]),true); echo $d["archive"] ?? "unknown";' /tmp/tl-deploy-backup.$$.json)"
    rm -f /tmp/tl-deploy-backup.$$.json
fi

# ---------------------------------------------------------------------------
# 3. Migration audit
# ---------------------------------------------------------------------------
info "3/9 Migration safety audit"
AUDIT_ARGS=(--json)
[[ "${ALLOW_DESTRUCTIVE}" -eq 1 ]] && AUDIT_ARGS+=(--allow-destructive)

if ! php "${SCRIPT_DIR}/migration-audit.php" "${AUDIT_ARGS[@]}" > /tmp/tl-deploy-audit.$$.json; then
    cat /tmp/tl-deploy-audit.$$.json >&2 || true
    rm -f /tmp/tl-deploy-audit.$$.json
    die "Pending migrations contain destructive operations. Review them, then re-run with --allow-destructive if that is intended."
fi
rm -f /tmp/tl-deploy-audit.$$.json
ok "Migration audit passed"

# ---------------------------------------------------------------------------
# 4. Tests (optional but on by default)
# ---------------------------------------------------------------------------
if [[ "${SKIP_TESTS}" -eq 0 ]]; then
    info "4/9 PHP test suite"
    ( cd -- "${ROOT}" && php artisan test ) || die "The test suite failed. A release that does not pass its own suite is not a release."
    ok "Test suite passed"
else
    warn "Skipping the test suite (--skip-tests)."
fi

# ---------------------------------------------------------------------------
# 5. Maintenance mode
# ---------------------------------------------------------------------------
if [[ "${MAINTENANCE}" == "on" ]]; then
    info "5/9 Enabling maintenance mode"
    artisan "${ROOT}" down --retry=30 --refresh=15 >/dev/null
    ok "Maintenance mode on (503 with Retry-After; queued jobs keep running)"
else
    info "5/9 Continuing without maintenance mode"
fi

# ---------------------------------------------------------------------------
# 6. Migrate + cache
# ---------------------------------------------------------------------------
info "6/9 Migrating and warming caches"
artisan "${ROOT}" migrate --force || {
    [[ "${MAINTENANCE}" == "on" ]] && artisan "${ROOT}" up >/dev/null 2>&1 || true
    die "Migration failed. The application is left in maintenance mode; follow docs/ops/OPS-RUNBOOK.md before retrying."
}

artisan "${ROOT}" config:cache >/dev/null
artisan "${ROOT}" route:cache >/dev/null
artisan "${ROOT}" view:cache >/dev/null
artisan "${ROOT}" event:cache >/dev/null
ok "Caches warmed"

# ---------------------------------------------------------------------------
# 7. Restart workers
# ---------------------------------------------------------------------------
info "7/9 Restarting queue workers"
artisan "${ROOT}" queue:restart >/dev/null || warn "queue:restart failed; workers may still hold the old code."

if artisan "${ROOT}" list --raw 2>/dev/null | awk '{print $1}' | grep -qx 'horizon:terminate'; then
    artisan "${ROOT}" horizon:terminate >/dev/null || warn "horizon:terminate failed."
    ok "Horizon termination signalled; supervisors will respawn with the new code"
else
    ok "queue:restart signalled"
fi

# ---------------------------------------------------------------------------
# 8. Smoke test
# ---------------------------------------------------------------------------
if [[ "${SKIP_SMOKE}" -eq 0 ]]; then
    info "8/9 HTTP smoke test"
    SMOKE_ARGS=()
    [[ -n "${BASE_URL}" ]] && SMOKE_ARGS+=(--base-url "${BASE_URL}")

    if ! "${SCRIPT_DIR}/smoke-test.sh" "${SMOKE_ARGS[@]}"; then
        die "Smoke test failed after deployment. Run scripts/ops/rollback.sh to return to the previous release; the database is not rolled back automatically."
    fi
    ok "Smoke test passed"
else
    warn "Skipping the smoke test (--skip-smoke)."
fi

# ---------------------------------------------------------------------------
# 9. Back out of maintenance mode
# ---------------------------------------------------------------------------
if [[ "${MAINTENANCE}" == "on" ]]; then
    info "9/9 Disabling maintenance mode"
    artisan "${ROOT}" up >/dev/null
    ok "Application is serving traffic again"
else
    info "9/9 Nothing to bring back up"
fi

ok "Deploy complete: $(git -C "${ROOT}" rev-parse --short HEAD 2>/dev/null || echo unknown)"
exit 0
