#!/usr/bin/env bash
# =============================================================================
# smoke-test.sh — the checks that decide whether a deployment is serving.
#
#   scripts/ops/smoke-test.sh                          # http://127.0.0.1:8000
#   scripts/ops/smoke-test.sh --base-url https://…     # a real deployment
#   scripts/ops/smoke-test.sh --json                   # for CI
#
# WHAT IT CHECKS, AND WHY EACH ONE
#   1. /up/live                 the process answers at all
#   2. /up/ready                database, cache and queue are reachable — this is
#                               the endpoint whose 503 means "do not route here"
#   3. /robots.txt              static serving works and the search directives
#                               shipped (a broken nginx root is invisible until
#                               someone notices every page is unstyled)
#   4. manifest asset           EVERY file the Vite manifest references is
#                               fetchable. A manifest that exists while its
#                               hashed assets 404 is the single most common
#                               "deployed but looks broken" failure
#   5. public pages             a 200 from the real application, not just the
#                               probe endpoints
#
# It is deliberately read-only: no POST, no login, no writes to production data.
# A smoke test that mutates state cannot be run against production, and the
# deploy needs to run it against production.
#
# EXIT CODES: 0 all checks passed · 1 at least one failed
# =============================================================================
set -Eeuo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
. "${SCRIPT_DIR}/lib/common.sh"

BASE_URL="${SMOKE_BASE_URL:-http://127.0.0.1:8000}"
TIMEOUT=10
RETRIES=3
JSON=0

usage() {
    cat <<'USAGE'
Usage: scripts/ops/smoke-test.sh [options]

  --base-url URL   Deployment to test (default: $SMOKE_BASE_URL or 127.0.0.1:8000)
  --timeout N      Per-request timeout in seconds (default 10)
  --retries N      Attempts per check before failing (default 3)
  --json           Machine-readable results
  -h, --help       This text
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --base-url) BASE_URL="${2:?}"; shift 2 ;;
        --timeout)  TIMEOUT="${2:?}"; shift 2 ;;
        --retries)  RETRIES="${2:?}"; shift 2 ;;
        --json)     JSON=1; shift ;;
        -h|--help)  usage; exit 0 ;;
        *)          die "Unknown option: $1" ;;
    esac
done

ROOT="$(repo_root "${SCRIPT_DIR}")"
BASE_URL="${BASE_URL%/}"
require_command curl

PASSED=0
FAILED=0
RESULTS="[]"

record() {
    local name="$1" status="$2" detail="$3"

    RESULTS="$(php -r '
        $results = json_decode((string)($argv[1] ?? "[]"), true) ?: [];
        $results[] = ["check" => $argv[2], "status" => $argv[3], "detail" => $argv[4]];
        echo json_encode($results);
    ' "${RESULTS}" "${name}" "${status}" "${detail}")"
}

# probe NAME PATH EXPECTED_STATUS_REGEX [extra curl args...]
probe() {
    local name="$1" path="$2" expected="$3"
    shift 3

    local attempt=1 code="000"

    while [[ "${attempt}" -le "${RETRIES}" ]]; do
        code="$(curl --silent --output /dev/null --write-out '%{http_code}' \
            --max-time "${TIMEOUT}" --location --max-redirs 3 \
            "${BASE_URL}${path}" "$@" 2>/dev/null || echo "000")"

        if [[ "${code}" =~ ^(${expected})$ ]]; then
            ok "${name} (${path}) -> ${code}"
            PASSED=$((PASSED + 1))
            record "${name}" "PASS" "HTTP ${code}"

            return 0
        fi

        sleep 1
        attempt=$((attempt + 1))
    done

    warn "${name} (${path}) -> ${code} (expected ${expected})"
    FAILED=$((FAILED + 1))
    record "${name}" "FAIL" "HTTP ${code}"

    return 1
}

info "Smoke test against ${BASE_URL}"

# 1-2. Probe endpoints. /up/ready is allowed to be 503 only if it says so — a
# 503 is a legitimate answer from a starting deployment, so it fails here and
# the deploy script decides what to do.
probe "liveness" "/up/live" "200" || true
probe "readiness" "/up/ready" "200" || true

# 3. Static serving.
probe "robots" "/robots.txt" "200" || true

# 4. Every asset the manifest references must be fetchable.
MANIFEST="${ROOT}/public/build/manifest.json"

if [[ -f "${MANIFEST}" ]]; then
    ASSETS="$(php -r '
        $manifest = json_decode((string) file_get_contents($argv[1]), true) ?: [];
        $files = [];
        foreach ($manifest as $entry) {
            if (is_array($entry) && isset($entry["file"])) { $files[] = $entry["file"]; }
            foreach ((array) ($entry["css"] ?? []) as $css) { $files[] = $css; }
        }
        foreach (array_unique($files) as $file) { echo $file, PHP_EOL; }
    ' "${MANIFEST}")"

    MISSING=0

    while IFS= read -r asset; do
        [[ -z "${asset}" ]] && continue

        code="$(curl --silent --output /dev/null --write-out '%{http_code}' --max-time "${TIMEOUT}" "${BASE_URL}/build/${asset}" || echo 000)"

        if [[ "${code}" != "200" ]]; then
            warn "manifest asset not served: /build/${asset} -> ${code}"
            MISSING=$((MISSING + 1))
        fi
    done <<< "${ASSETS}"

    if [[ "${MISSING}" -eq 0 ]]; then
        ok "every manifest asset is served"
        PASSED=$((PASSED + 1))
        record "build_assets" "PASS" "all manifest files fetched"
    else
        FAILED=$((FAILED + 1))
        record "build_assets" "FAIL" "${MISSING} manifest asset(s) not served"
    fi
else
    warn "public/build/manifest.json is absent on this host; skipping asset checks."
    $([ "${JSON}" -eq 1 ] || true)
    record "build_assets" "SKIP" "no manifest on this host"
fi

# 5. Real pages.
probe "home" "/" "200|302" || true
probe "results" "/results" "200|301|302" || true
probe "api_health" "/api/health" "200" || true

if [[ "${JSON}" -eq 1 ]]; then
    printf '{"base_url":"%s","passed":%d,"failed":%d,"results":%s}\n' \
        "${BASE_URL}" "${PASSED}" "${FAILED}" "${RESULTS}"
fi

if [[ "${FAILED}" -gt 0 ]]; then
    die "${FAILED} smoke check(s) failed against ${BASE_URL}"
fi

ok "All smoke checks passed (${PASSED})"
exit 0
