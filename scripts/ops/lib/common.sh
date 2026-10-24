#!/usr/bin/env bash
# =============================================================================
# Shared helpers for the platform operations scripts.
#
# SOURCED, NEVER EXECUTED. Every script under scripts/ops/ starts with:
#
#   set -Eeuo pipefail
#   SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
#   # shellcheck source=lib/common.sh
#   . "${SCRIPT_DIR}/lib/common.sh"
#
# WHAT THIS FILE GUARANTEES
#   1. A secret is never printed, logged, or passed on a command line.
#      `redact` exists so a caller that must show an error can do it safely.
#   2. A failure stops the script. `set -e` alone is not enough once anything
#      runs inside `if` or a pipeline, so `die` is explicit and the trap
#      reports the exact line.
#   3. Running the same script twice is not destructive by accident: anything
#      that writes requires an explicit flag or a confirmation environment
#      variable, never just "the operator ran it".
#
# DELIBERATELY NOT HERE
#   No secret loading into the environment beyond what Laravel already does,
#   no `eval`, no nested string command construction. Table and database names
#   come from operator input; they are validated, then passed as argv
#   elements.
# =============================================================================

# ---------------------------------------------------------------------------
# Output
# ---------------------------------------------------------------------------
if [[ -t 1 ]] && [[ "${NO_COLOR:-}" == "" ]]; then
    C_RESET=$'\033[0m'; C_RED=$'\033[31m'; C_GREEN=$'\033[32m'
    C_YELLOW=$'\033[33m'; C_BLUE=$'\033[34m'; C_DIM=$'\033[2m'
else
    C_RESET=""; C_RED=""; C_GREEN=""; C_YELLOW=""; C_BLUE=""; C_DIM=""
fi

log()   { printf '%s[%s]%s %s\n' "${C_DIM}" "$(date -u +%H:%M:%SZ)" "${C_RESET}" "$*"; }
info()  { printf '%s==>%s %s\n' "${C_BLUE}" "${C_RESET}" "$*"; }
ok()    { printf '%s ok %s %s\n' "${C_GREEN}" "${C_RESET}" "$*"; }
warn()  { printf '%sWARN%s %s\n' "${C_YELLOW}" "${C_RESET}" "$*" >&2; }
die()   { printf '%sFAIL%s %s\n' "${C_RED}" "${C_RESET}" "$*" >&2; exit 1; }
# A refusal is not a failure: nothing was attempted, nothing is broken.
# Keeping the codes distinct lets a scheduler alert on 1 (real breakage) without
# paging for 2 (a guard doing its job).
refuse() { printf '%sREFUSED%s %s\n' "${C_YELLOW}" "${C_RESET}" "$*" >&2; exit 2; }

# Anything that might echo a value from the environment goes through this.
# It deliberately keeps the *shape* of a value ("<set, 48 chars>") and drops
# the value, because the shape is what an operator actually needs to debug.
redact() {
    local value="${1:-}"
    if [[ -z "${value}" ]]; then
        printf '(unset)'
    else
        printf '(set, %d chars)' "${#value}"
    fi
}

# ---------------------------------------------------------------------------
# Failure reporting
# ---------------------------------------------------------------------------
_on_error() {
    local exit_code=$? line=${1:-?} command=${2:-?}
    printf '%sFAIL%s %s:%s exited %d while running: %s\n' \
        "${C_RED}" "${C_RESET}" "$(basename "${BASH_SOURCE[1]:-$0}")" "${line}" "${exit_code}" "${command}" >&2
    exit "${exit_code}"
}
trap '_on_error "${LINENO}" "${BASH_COMMAND}"' ERR

# ---------------------------------------------------------------------------
# Preconditions
# ---------------------------------------------------------------------------
require_command() {
    local command_name
    for command_name in "$@"; do
        command -v "${command_name}" >/dev/null 2>&1 \
            || die "Required command not found on PATH: ${command_name}"
    done
}

# Resolve the repository root from the script location, not from $PWD, so the
# scripts behave the same from cron, from CI and from a developer shell.
repo_root() {
    local script_dir="${1:?repo_root needs the calling script directory}"
    ( cd -- "${script_dir}/../.." && pwd )
}

require_repo_root() {
    local root="${1:?}"
    [[ -f "${root}/artisan" ]] || die "This does not look like the application root (no artisan): ${root}"
    [[ -f "${root}/composer.json" ]] || die "This does not look like the application root (no composer.json): ${root}"
    printf '%s' "${root}"
}

# Wrapper around artisan that always runs from the repository root and never
# needs a shell string.
artisan() {
    local root="${1:?}"
    shift
    ( cd -- "${root}" && php artisan "$@" )
}

# ---------------------------------------------------------------------------
# Environment inspection WITHOUT printing values
# ---------------------------------------------------------------------------
# Reads APP_ENV from .env without exporting anything. Used to decide whether a
# command is safe to run, never to display.
app_env() {
    local root="${1:?}"
    if [[ -n "${APP_ENV:-}" ]]; then
        printf '%s' "${APP_ENV}"
        return
    fi
    if [[ -f "${root}/.env" ]]; then
        # -m1 and no export: this is a read, not a load.
        sed -n 's/^APP_ENV=[[:space:]]*//p' "${root}/.env" | head -n1 | tr -d '"'\'''
        return
    fi
    printf 'production'
}

# config_value <dotted.key> <default>
#   Resolve a configuration value the way the scripts need it: from the
#   environment first, then the caller's default. It deliberately does NOT boot
#   Laravel — these scripts run during recovery, which is exactly when the
#   application may not be able to start. `backup.path` therefore reads
#   BACKUP_PATH, `backup.staging_path` reads BACKUP_STAGING_PATH, and so on.
config_value() {
    local key="${1:?config key required}"
    local fallback="${2:-}"
    local envname="${key^^}"
    envname="${envname//./_}"

    local value="${!envname:-}"

    if [[ -n "${value}" ]]; then
        printf '%s' "${value}"
        return 0
    fi

    printf '%s' "${fallback}"
}

is_production() {
    [[ "${1:-}" == "production" || "${1:-}" == "prod" ]]
}

# ---------------------------------------------------------------------------
# Confirmations
# ---------------------------------------------------------------------------
# The confirmation is an environment variable containing the exact name of the
# thing being changed. A bare `--yes` is too easy to leave in a shell history
# and too easy to add to a Makefile; naming the target forces the operator to
# read what they are about to overwrite.
require_confirmation() {
    local expected="${1:?}"
    local prompt="${2:-This is destructive.}"

    if [[ "${CONFIRM:-}" == "${expected}" ]]; then
        return 0
    fi

    printf '%s\n' "${prompt}" >&2
    printf 'Re-run with: CONFIRM=%s %s\n' "${expected}" "$(basename "$0")" >&2
    exit 2
}

# ---------------------------------------------------------------------------
# Misc
# ---------------------------------------------------------------------------
# Free-form argv validation for identifiers that reach a database client.
# Rejects anything that is not [A-Za-z0-9_]; a database name is not a place for
# creativity, and every client here accepts `--database=$(...)` style input.
assert_identifier() {
    local value="${1:?}" label="${2:-identifier}"
    [[ "${value}" =~ ^[A-Za-z0-9_]+$ ]] \
        || die "${label} must match [A-Za-z0-9_]+, refused: $(printf '%s' "${value}" | tr -cd '[:alnum:]_')"
}

# A single place for "is php available and does it have the extension we need".
require_php() {
    require_command php
    php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' \
        || die "PHP 8.4 or newer is required by this application (composer.json requires ^8.4)."
}

print_help_header() {
    printf '%s\n' "$1"
    printf '%s\n' "${C_DIM}$(printf '%.0s-' $(seq 1 ${#1}))${C_RESET}"
}
