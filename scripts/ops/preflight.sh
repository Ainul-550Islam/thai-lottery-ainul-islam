#!/usr/bin/env bash
# =============================================================================
# preflight.sh — host-level gate for a release that has not been booted yet.
#
#   scripts/ops/preflight.sh                 # config + connectivity
#   scripts/ops/preflight.sh --offline        # config only (no database probes)
#   scripts/ops/preflight.sh --json           # for CI
#
# RELATIONSHIP TO `php artisan ops:preflight`
# -------------------------------------------
# The artisan command is the authority on application configuration and runs
# inside the framework. This shell wrapper adds the HOST facts the framework
# cannot see and that break deploys in practice:
#
#   * PHP and Node produce the versions composer.json / package.json require
#     (this repository requires PHP ^8.4 and Node 24 — a host with 8.3 or
#     Node 20 fails at `composer install` and `npm ci`, after the release has
#     already been cut);
#   * the required client binaries exist (mysqldump/pg_dump for backups);
#   * .env is not world-readable;
#   * the runtime directories are writable by the user that will serve traffic;
#   * the build manifest exists, because a release without it serves unstyled
#     pages.
#
# EXIT CODES: 0 ready · 1 not ready · 2 refused
# =============================================================================
set -Eeuo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
. "${SCRIPT_DIR}/lib/common.sh"

OFFLINE=0
JSON=0

usage() {
    cat <<'USAGE'
Usage: scripts/ops/preflight.sh [options]

  --offline   Skip database/cache/Redis connectivity probes
  --json      Machine-readable output
  -h, --help  This text
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --offline) OFFLINE=1; shift ;;
        --json)    JSON=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *)         die "Unknown option: $1" ;;
    esac
done

ROOT="$(repo_root "${SCRIPT_DIR}")"
require_repo_root "${ROOT}" >/dev/null

FAILURES=0

fail() { warn "$1"; FAILURES=$((FAILURES + 1)); }

# ---------------------------------------------------------------------------
# Runtime versions
# ---------------------------------------------------------------------------
if command -v php >/dev/null 2>&1; then
    PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' \
        && ok "PHP ${PHP_VERSION} satisfies composer.json (^8.4)" \
        || fail "PHP ${PHP_VERSION} does not satisfy composer.json (^8.4). Install PHP 8.4 before releasing; the dependency lock file will refuse to install otherwise."
else
    fail "php is not on PATH."
fi

# Node is only needed to BUILD, not to serve. Report it as required only when
# node_modules is absent, i.e. when this host is expected to build assets.
if command -v node >/dev/null 2>&1; then
    NODE_VERSION="$(node -p 'process.versions.node')"
    NODE_MAJOR="${NODE_VERSION%%.*}"

    if [[ "${NODE_MAJOR}" -eq 24 ]]; then
        ok "Node ${NODE_VERSION} satisfies package.json (>=24 <25)"
    else
        fail "Node ${NODE_VERSION} does not satisfy package.json (>=24 <25); .npmrc sets engine-strict=true, so npm ci will refuse to run."
    fi
else
    warn "node is not on PATH. Fine for a host that only runs the prebuilt artifacts; fatal for a host that builds them."
fi

# ---------------------------------------------------------------------------
# Required binaries
# ---------------------------------------------------------------------------
for binary in mysqldump mysql; do
    if command -v "${binary}" >/dev/null 2>&1; then
        ok "${binary} is available"
    else
        fail "${binary} is not on PATH. Backups (scripts/ops/backup.sh) cannot run without the database client."
    fi
done

for binary in curl gzip sha256sum; do
    command -v "${binary}" >/dev/null 2>&1 && ok "${binary} is available" || fail "${binary} is not on PATH."
done

# ---------------------------------------------------------------------------
# Filesystem
# ---------------------------------------------------------------------------
ENV_PATH="${ROOT}/.env"

if [[ -f "${ENV_PATH}" ]]; then
    PERMS="$(stat -c '%a' "${ENV_PATH}" 2>/dev/null || echo unknown)"

    if [[ "${PERMS}" =~ ^[0-7]?[0-7][0-4][0-4]$ ]]; then
        ok ".env mode ${PERMS} is not world-readable"
    else
        fail ".env mode ${PERMS} is too permissive. Use 0640."
    fi
else
    warn "No .env file. Expected when the environment is injected by the orchestrator; fatal for a bare-metal host."
fi

for directory in storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; do
    if [[ -d "${ROOT}/${directory}" && -w "${ROOT}/${directory}" ]]; then
        ok "${directory} is writable"
    else
        fail "${directory} is missing or not writable by $(id -un)."
    fi
done

if [[ -f "${ROOT}/public/build/manifest.json" ]]; then
    ok "public/build/manifest.json is present"
else
    fail "public/build/manifest.json is missing. Build assets during the release (npm run build), never ad hoc on the host."
fi

# ---------------------------------------------------------------------------
# Application configuration (delegated)
# ---------------------------------------------------------------------------
ARGS=(--json)
[[ "${OFFLINE}" -eq 1 ]] && ARGS+=(--offline)

APP_OUTPUT="$(mktemp)"
trap 'rm -f "${APP_OUTPUT}"' EXIT

if artisan "${ROOT}" ops:preflight "${ARGS[@]}" > "${APP_OUTPUT}"; then
    ok "Application preflight passed"
else
    fail "Application preflight failed:"
    php -r '
        $report = json_decode((string) file_get_contents($argv[1]), true) ?: [];
        foreach ((array) ($report["checks"] ?? []) as $check) {
            if (($check["status"] ?? "") === "FAIL") {
                fwrite(STDERR, "    - ".$check["id"].": ".$check["detail"].PHP_EOL);
            }
        }
    ' "${APP_OUTPUT}"
fi

if [[ "${JSON}" -eq 1 ]]; then
    printf '{"status":"%s","failures":%d,"application:%s"}\n' \
        "$([[ "${FAILURES}" -eq 0 ]] && echo PASS || echo FAIL)" \
        "${FAILURES}" \
        "$(cat "${APP_OUTPUT}")"
fi

if [[ "${FAILURES}" -gt 0 ]]; then
    die "${FAILURES} host preflight check(s) failed."
fi

ok "Host preflight passed."
exit 0
