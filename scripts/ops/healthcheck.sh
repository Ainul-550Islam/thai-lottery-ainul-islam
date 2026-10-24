#!/usr/bin/env bash
# =============================================================================
# healthcheck.sh — one command an external monitor, cron or on-call can run.
#
#   scripts/ops/healthcheck.sh                 # human-readable summary
#   scripts/ops/healthcheck.sh --json          # machine-readable
#   scripts/ops/healthcheck.sh --http URL      # also probe a live endpoint
#
# It wraps `php artisan ops:health`, which already answers: are the database,
# cache, queue and Redis reachable; is the scheduler still ticking; is the
# newest verified backup fresh; is the disk filling up; are failed jobs piling
# up. The exit code is the alerting signal:
#
#   0 OK · 1 DEGRADED · 2 CRITICAL
#
# WHY THE EXIT CODE MATTERS MORE THAN THE TEXT
#   Every monitoring system that exists can alert on a non-zero exit code, and
#   almost none of them reliably parse a human summary. Keeping the mapping in
#   one place means the runbook, the cron entry and the alert all agree.
# =============================================================================
set -Eeuo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
. "${SCRIPT_DIR}/lib/common.sh"

JSON=0
HTTP_URL=""

usage() {
    cat <<'USAGE'
Usage: scripts/ops/healthcheck.sh [options]

  --json        Machine-readable output
  --http URL    Also require this URL's /up/ready to answer 200
  -h, --help    This text

Exit codes: 0 OK, 1 DEGRADED, 2 CRITICAL
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --json)    JSON=1; shift ;;
        --http)    HTTP_URL="${2:?}"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *)         die "Unknown option: $1" ;;
    esac
done

ROOT="$(repo_root "${SCRIPT_DIR}")"
require_repo_root "${ROOT}" >/dev/null
require_php

ARGS=(--json)
[[ "${JSON}" -eq 1 ]] || ARGS=()

set +e
OUTPUT="$(artisan "${ROOT}" ops:health "${ARGS[@]}" 2>&1)"
STATUS_CODE=$?
set -e

if [[ "${JSON}" -eq 1 ]]; then
    printf '%s\n' "${OUTPUT}"
else
    printf '%s\n' "${OUTPUT}"
fi

# Optional HTTP layer: an application that is "healthy" internally but not
# answering HTTP is not healthy. This is checked after the internal probes so a
# hung web tier does not hide a database outage.
if [[ -n "${HTTP_URL}" ]]; then
    require_command curl
    code="$(curl --silent --output /dev/null --write-out '%{http_code}' --max-time 10 "${HTTP_URL%/}/up/ready" || echo 000)"

    if [[ "${code}" == "200" ]]; then
        ok "/up/ready -> 200"
    else
        warn "/up/ready -> ${code}"
        [[ "${STATUS_CODE}" -lt 2 ]] && STATUS_CODE=2
    fi
fi

case "${STATUS_CODE}" in
    0) exit 0 ;;
    1) exit 1 ;;
    *) exit 2 ;;
esac
