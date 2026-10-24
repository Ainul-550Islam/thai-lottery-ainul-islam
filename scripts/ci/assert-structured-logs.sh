#!/usr/bin/env bash
# =============================================================================
# assert-structured-logs.sh — production logging is JSON, on stderr, redacted.
#
# WHY THIS EXISTS
# ---------------
# Three separate failures were possible here and all three were real at some
# point in this repository's history:
#
#   1. `LOG_CHANNEL=stderr` resolved to a StreamHandler with no explicit stream,
#      so the channel wrote to storage/logs/laravel.log instead of stderr. A
#      container then has logs in a file nobody collects.
#   2. that resolved handler carried no redaction tap, so a context array with
#      `password` was written to disk in the clear.
#   3. `LOG_FORMAT=json` had no effect because no JSON formatter was configured,
#      so a log pipeline had prose to parse.
#
# This script boots the application the way a container does (LOG_CHANNEL=stderr,
# LOG_FORMAT=json), logs a record containing a fake credential, and asserts:
#
#   * exactly one line was written, and it is valid JSON;
#   * the credential is `[REDACTED]` and non-secret context survives;
#   * the line went to stderr, not to storage/logs/laravel.log.
#
# USAGE
#   scripts/ci/assert-structured-logs.sh
#
# EXIT
#   0 structured, redacted logging verified · 1 otherwise
# =============================================================================
set -Eeuo pipefail
IFS=$'\n\t'

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
cd -- "${ROOT}"

fail=0
ok()  { printf '  ok   %s\n' "$*"; }
bad() { printf '::error::%s\n' "$*"; fail=1; }

PROBE_SECRET='log-probe-do-not-leak-0001'
STDERR_FILE="$(mktemp)"
STDOUT_FILE="$(mktemp)"
LOG_FILE='storage/logs/laravel.log'
LOG_SIZE_BEFORE=0

[[ -f "${LOG_FILE}" ]] && LOG_SIZE_BEFORE="$(wc -c < "${LOG_FILE}")"

echo "--- structured logging (LOG_FORMAT=json, LOG_CHANNEL=stderr) ---"

# APP_ENV is pinned here rather than inherited. The application refuses to boot
# with a production environment that fails the safety guard (fixture GLO source,
# insecure session cookies), and that refusal is correct — this script is about
# log formatting, not about production readiness. ProductionPreflightTest covers
# the guard. Nothing below depends on the environment name.
if ! APP_ENV=testing LOG_CHANNEL=stderr LOG_FORMAT=json PROBE_SECRET="${PROBE_SECRET}" php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Log::info("structured-log-probe", [
    "password" => getenv("PROBE_SECRET"),
    "amount" => 12.5,
]);
' >"${STDOUT_FILE}" 2>"${STDERR_FILE}"; then
    bad "the probe could not boot the application (see stderr below)"
    sed -n '1,20p' "${STDERR_FILE}"
    rm -f "${STDERR_FILE}" "${STDOUT_FILE}"
    exit 1
fi

LINES="$(grep -c . "${STDERR_FILE}" || true)"

if [[ "${LINES}" -ne 1 ]]; then
    bad "expected exactly one log line on stderr, found ${LINES}"
    sed -n '1,10p' "${STDERR_FILE}"
fi

LINE="$(grep -m1 'structured-log-probe' "${STDERR_FILE}" || true)"

if [[ -z "${LINE}" ]]; then
    bad "no structured-log-probe line appeared on stderr — the channel is not writing to stderr"
fi

# 1. valid JSON, one object per line.
if ! printf '%s' "${LINE}" | python3 -c 'import json,sys; json.loads(sys.stdin.read())' 2>/dev/null; then
    bad "the log line is not valid JSON: ${LINE:0:200}"
else
    ok "the log line is a single valid JSON object"
fi

# 2. the credential is masked; non-secret context survives.
if printf '%s' "${LINE}" | grep -qF "${PROBE_SECRET}"; then
    bad "a credential reached the log line in the clear"
else
    ok "the credential value was redacted"
fi

if ! printf '%s' "${LINE}" | grep -q '\[REDACTED\]'; then
    bad "the redaction mask is missing from the log line"
fi

if ! printf '%s' "${LINE}" | grep -q '"amount"'; then
    bad "non-secret context was dropped from the log line"
else
    ok "non-secret context is preserved (the log is still useful)"
fi

# 3. nothing leaked into the application log file.
LOG_SIZE_AFTER=0
[[ -f "${LOG_FILE}" ]] && LOG_SIZE_AFTER="$(wc -c < "${LOG_FILE}")"

if [[ "${LOG_SIZE_AFTER}" -ne "${LOG_SIZE_BEFORE}" ]]; then
    if tail -c +$((LOG_SIZE_BEFORE + 1)) "${LOG_FILE}" | grep -q 'structured-log-probe'; then
        bad "the record was written to ${LOG_FILE}; LOG_CHANNEL=stderr must stream to stderr"
    fi
else
    ok "the record did not go to ${LOG_FILE}"
fi

rm -f "${STDERR_FILE}" "${STDOUT_FILE}"

echo
if [[ "${fail}" -ne 0 ]]; then
    echo "Structured logging assertions failed."
    exit 1
fi

echo "Structured logging is JSON, on stderr, and redacted."
