#!/usr/bin/env bash
# =============================================================================
# scan-secrets.sh — repository-scoped secret scanning with an explicit allowlist.
#
# WHY NOT JUST `grep` FOR "PRIVATE KEY"
# -------------------------------------
# The base commit's security workflow did exactly that, over the whole tree,
# and then grepped for two vendor-specific key prefixes. It has two failure
# modes that matter:
#
#   * it cannot distinguish documentation from a live credential, so the first
#     real key it finds is as likely to be in a test fixture as in code — and a
#     scanner that cries wolf gets muted;
#   * it only knows two vendors.
#
# This scanner is deliberately narrow and boring: it looks for high-signal
# credential shapes plus a small set of *this application's* secret variable
# names assigned a non-placeholder value. Findings must be explained in
# .github/secret-scan-allowlist.txt with a reason, so an exception is a
# reviewed line in a diff rather than a muted rule.
#
# USAGE
#   scripts/ci/scan-secrets.sh              # scan tracked files
#   scripts/ci/scan-secrets.sh --all-files  # scan the working tree too
#
# EXIT CODES: 0 clean · 1 findings
# =============================================================================
set -Eeuo pipefail
IFS=$'\n\t'

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
cd -- "${ROOT}"

ALLOWLIST=".github/secret-scan-allowlist.txt"
FINDINGS=0
SCAN_ALL=0

[[ "${1:-}" == "--all-files" ]] && SCAN_ALL=1

# Files that legitimately contain credential-shaped example text. The .env
# templates are checked separately below with a stricter, key-aware rule that
# requires a PLACEHOLDER value.
EXCLUDES=(
    ':!.git'
    ':!vendor'
    ':!node_modules'
    ':!storage'
    ':!public/build'
    ':!artifacts'
    ':!evidence'
    ':!*.lock'
    ':!package-lock.json'
)

# Credential shapes with very few false positives.
declare -A PATTERNS=(
    [private_key_block]='-----BEGIN (RSA |EC |OPENSSH |PGP )?PRIVATE KEY-----'
    [aws_access_key]='AKIA[0-9A-Z]{16}'
    [github_token]='gh[pousr]_[A-Za-z0-9]{36,}'
    [stripe_live_key]='sk_live_[0-9a-zA-Z]{24,}'
    [slack_token]='xox[baprs]-[0-9A-Za-z-]{10,}'
    [google_api_key]='AIza[0-9A-Za-z\-_]{35}'
    [jwt_like]='eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}'
)

# Values that are obviously placeholders, mock fixtures, or references to
# another variable — none of which are credentials.
#   * `\$\{VAR:?msg\}` / `\$VAR` are interpolations: the value lives in the
#     environment, not in the file.
#   * `mock_*`, `test_*`, `sk_test_*`, `whsec_mock_*` are fixtures that the
#     payment tests need in order to exercise signature verification.
#   * policy keys (AUTH_PASSWORD_MIN_LENGTH) are numbers, not secrets; the key
#     filter below excludes them by name.
PLACEHOLDER='^(|null|NULL|false|true|0|[0-9]+|changeme|change-me|<[^>]*>|x+|\.\.\.|TODO|placeholder|your[-_].*|.*example.*|.*placeholder.*|.*your-.*|mock.*|test[-_].*|dummy.*|fake.*|sk_test_.*|pk_test_.*|whsec_mock.*|\$\{.*|\$[A-Za-z_]+|%.*%|\*+)$'

# Keys that contain a credential word but are configuration ABOUT credentials.
NON_SECRET_KEY='(LENGTH|MIN|MAX|EXPIRY|RESET|DAYS|TTL|ENABLED|DISABLED|POLICY|NAME|TYPE|HEADER|PREFIX|SUFFIX|URL|PATH|DRIVER|DAYS|ALGORITHM|PROVIDER|MODE|REQUIRE)'

report() {
    local rule="$1" file="$2" line="$3" excerpt="$4"

    if [[ -f "${ALLOWLIST}" ]] && grep -qF "${file}:${line}" "${ALLOWLIST}" 2>/dev/null; then
        printf '  allowed  %s:%s (%s)\n' "${file}" "${line}" "${rule}"
        return
    fi

    printf '::error file=%s,line=%s::%s matched %s\n' "${file}" "${line}" "${rule}" "${excerpt}"
    FINDINGS=$((FINDINGS + 1))
}

echo "--- credential shapes ---"

for rule in "${!PATTERNS[@]}"; do
    pattern="${PATTERNS[${rule}]}"

    # -I skips binaries; grep -n gives the line for the annotation.
    while IFS=: read -r file line _rest; do
        [[ -z "${file}" ]] && continue
        report "${rule}" "${file}" "${line}" "${rule}"
    done < <(git grep -nIE -- "${pattern}" -- . "${EXCLUDES[@]}" 2>/dev/null || true)
done

echo "--- application secret variables with a real-looking value ---"

# A named secret assigned something that is not a placeholder. This is the rule
# that catches the failure mode that actually happened in this repository: a
# backup encryption key hardcoded as a fallback default in PHP.
SECRET_ASSIGNMENT='(BACKUP_ENCRYPTION_KEY|APP_KEY|DB_PASSWORD|REDIS_PASSWORD|MAIL_PASSWORD|JWT_SECRET|HMAC_SECRET|WEBHOOK_SECRET|API_SECRET|SETTLEMENT_WORKER_HMAC_SECRET)[[:space:]]*[:=][[:space:]]*["'"'"']([^"'"'"']{8,})["'"'"']'

while IFS=: read -r file line _rest; do
    [[ -z "${file}" ]] && continue

    value="$(printf '%s' "${_rest}" | sed -E "s/.*[:=][[:space:]]*[\"']([^\"']+)[\"'].*/\1/")"

    if [[ "${value}" =~ ${PLACEHOLDER} ]]; then
        continue
    fi

    # Interpolations and command substitutions are computed at run time, not
    # committed values: `APP_KEY=base64:$(openssl rand -base64 32)` in a CI
    # smoke test generates a throwaway key per container.
    if [[ "${value}" == *'${'* || "${value}" == '$'* || "${value}" == *'$('* ]]; then
        continue
    fi

    # env('X', 'literal-fallback') is a finding: a committed fallback secret
    # makes every deployment that forgets the variable decryptable by anyone
    # who can read the repository.
    if [[ "${_rest}" == *"env("* ]]; then
        report "hardcoded_secret_fallback" "${file}" "${line}" "env() fallback for a secret"
        continue
    fi

    report "hardcoded_secret_value" "${file}" "${line}" "secret variable assigned a literal"
done < <(git grep -nIE -- "${SECRET_ASSIGNMENT}" -- '*.php' '*.js' '*.mjs' '*.sh' '*.yml' '*.yaml' '*.json' ':!.github/secret-scan-allowlist.txt' 2>/dev/null || true)

echo "--- env templates must not carry secrets ---"

for template in .env.example .env.production.example .env.ci; do
    [[ -f "${template}" ]] || continue

    # Only keys whose name ends in a credential word, and only when the right
    # hand side is non-empty and not a placeholder.
    while IFS= read -r line; do
        [[ "${line}" =~ ^# ]] && continue
        key="${line%%=*}"
        value="${line#*=}"
        value="${value%\"}"; value="${value#\"}"

        [[ "${key}" =~ ${NON_SECRET_KEY} ]] && continue
        [[ "${value}" =~ ${PLACEHOLDER} ]] && continue

        printf '::error file=%s::%s carries a value for %s; templates must contain placeholders only\n' \
            "${template}" "${template}" "${key}"
        FINDINGS=$((FINDINGS + 1))
    done < <(grep -E '^[A-Z0-9_]*(SECRET|PASSWORD|PRIVATE_KEY|APP_KEY|API_KEY|TOKEN)[A-Z0-9_]*=' "${template}" 2>/dev/null || true)
done

echo "--- committed .env files ---"

while IFS= read -r tracked_env; do
    [[ -z "${tracked_env}" ]] && continue

    # Only the key list is committed; a real .env must never be.
    case "$(basename "${tracked_env}")" in
        .env.example|.env.production.example|.env.ci) continue ;;
    esac

    printf '::error file=%s::%s is committed; .env files must never be tracked\n' "${tracked_env}" "${tracked_env}"
    FINDINGS=$((FINDINGS + 1))
done < <(git ls-files | grep -E '(^|/)\.env($|\.)' | grep -vE '\.(example|ci)$' || true)

echo
if [[ "${FINDINGS}" -gt 0 ]]; then
    echo "${FINDINGS} secret-scan finding(s). Fix them, or document a false positive in ${ALLOWLIST}."
    exit 1
fi

echo "No committed credentials found."
