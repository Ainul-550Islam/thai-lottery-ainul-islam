#!/usr/bin/env bash
# =============================================================================
# assert-ops-assets.sh — the platform/operations surface is complete and sound.
#
# Every check here exists because of a specific way the repository was broken
# or could regress silently:
#
#   * scripts/ops/*.sh must be executable and pass `bash -n`. A backup script
#     committed without +x is a backup script that fails at 2am.
#   * shellcheck must pass at error severity: `set -e` that does not actually
#     stop on the failure inside a pipeline is the classic way an ops script
#     reports success after doing nothing.
#   * the compose file must parse and must not run a development server, must
#     not publish the database port, and must declare healthchecks for the
#     services that can hang.
#   * the production Dockerfile must end as a non-root user and must not
#     contain a dev server or a package manager in the runtime stage.
#   * the scheduler and backup lanes must be wired (config/ops.php + routes/
#     console.php) — a lane that is implemented but unscheduled is a lane that
#     never runs.
#
# USAGE
#   scripts/ci/assert-ops-assets.sh
# =============================================================================
set -Eeuo pipefail
IFS=$'\n\t'

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
cd -- "${ROOT}"

fail=0
ok()  { printf '  ok   %s\n' "$*"; }
bad() { printf '::error::%s\n' "$*"; fail=1; }

echo "--- ops scripts present, executable and syntactically valid ---"

REQUIRED_SCRIPTS=(
    scripts/ops/preflight.sh
    scripts/ops/backup.sh
    scripts/ops/restore.sh
    scripts/ops/verify-backup.sh
    scripts/ops/deploy.sh
    scripts/ops/rollback.sh
    scripts/ops/smoke-test.sh
    scripts/ops/healthcheck.sh
    scripts/ops/assert-ciphertext-only.sh
    scripts/ops/migration-audit.php
    scripts/ops/lib/common.sh
)

for script in "${REQUIRED_SCRIPTS[@]}"; do
    if [[ ! -f "${script}" ]]; then
        bad "missing operations asset: ${script}"
        continue
    fi

    if [[ ! -x "${script}" ]]; then
        bad "${script} is not executable (chmod +x)"
    fi

    case "${script}" in
        *.sh) bash -n "${script}" && ok "${script}" || bad "${script} has a syntax error" ;;
        *.php) php -l "${script}" >/dev/null && ok "${script}" || bad "${script} has a syntax error" ;;
    esac
done

echo "--- shellcheck (error severity) ---"

if command -v shellcheck >/dev/null 2>&1; then
    # SC1091: sourced file paths are resolved at runtime.
    if shellcheck --severity=error --exclude=SC1091 scripts/ops/*.sh scripts/ops/lib/*.sh scripts/ci/*.sh; then
        ok "shellcheck found no errors"
    else
        bad "shellcheck reported errors in the ops scripts"
    fi
else
    printf '  skip shellcheck not installed\n'
fi

echo "--- scheduled lanes are wired ---"

if grep -q "ops:heartbeat" routes/console.php; then
    ok "scheduler heartbeat lane is scheduled"
else
    bad "routes/console.php does not schedule ops:heartbeat; a stopped scheduler would be invisible"
fi

if grep -q "backup:execute-encrypted-worm" routes/console.php; then
    ok "encrypted backup lane is scheduled"
else
    bad "routes/console.php does not schedule backup:execute-encrypted-worm"
fi

for command in ops:preflight ops:health backup:execute-encrypted-worm backup:verify backup:extract; do
    if grep -rq "protected \$signature = '${command%% *}" app/Console/Commands 2>/dev/null; then
        ok "command ${command} exists"
    else
        bad "command ${command} is referenced by the ops scripts but not implemented"
    fi
done

echo "--- backup encryption is fail-closed ---"

# A deny-list of known-weak keys is not the same thing as a usable default key,
# so match the *use* of a fallback key rather than any mention of one:
#   env('BACKUP_ENCRYPTION_KEY', 'something')   <- a key the app will happily use
#   config(...) ?? 'some literal'               <- the same defect in disguise
if grep -rnE "env\(\s*['\"]BACKUP_ENCRYPTION_KEY['\"]\s*," app/ config/ 2>/dev/null; then
    bad "BACKUP_ENCRYPTION_KEY is given a default value; a backup key must never have a fallback"
else
    ok "no default value for BACKUP_ENCRYPTION_KEY"
fi

if grep -rnE "(backup_key|BACKUP_ENCRYPTION_KEY)[^;\n]{0,40}(\?\?|=)\s*['\"][^'\"]{8,}" app/ config/ 2>/dev/null; then
    bad "a hardcoded backup key literal is assigned as a usable value"
else
    ok "no hardcoded backup key assigned to a usable value"
fi

# Authenticated encryption is the property that matters; assert both halves of
# the construction actually exist rather than one magic string.
CIPHER_FILE=app/Support/Backup/BackupCipher.php

if grep -q "aes-256-gcm" "${CIPHER_FILE}" \
    && grep -qE "HASH_HMAC|hash_hmac\(" "${CIPHER_FILE}" \
    && grep -qE "hash_equals" "${CIPHER_FILE}"; then
    ok "backup cipher uses authenticated encryption (AEAD envelope + HMAC, constant-time compare)"
else
    bad "backup cipher is not authenticated (need AEAD, HMAC and a constant-time compare)"
fi

echo "--- container definitions ---"

if [[ -f docker/php/Dockerfile ]]; then
    RUNTIME_STAGE="$(awk '/^FROM .* AS runtime/{flag=1} flag' docker/php/Dockerfile)"

    if grep -qE '^USER [^r]' docker/php/Dockerfile; then
        ok "production image declares a non-root USER"
    else
        bad "docker/php/Dockerfile never switches to a non-root user"
    fi

    if printf '%s' "${RUNTIME_STAGE}" | grep -qiE 'artisan serve|npm run dev|vite --host|composer install'; then
        bad "the runtime stage of docker/php/Dockerfile contains a development server or a package manager"
    else
        ok "runtime stage contains no development server or package manager"
    fi

    grep -q 'HEALTHCHECK' docker/php/Dockerfile && ok "production image has a HEALTHCHECK" \
        || bad "production image has no HEALTHCHECK"
else
    bad "docker/php/Dockerfile is missing"
fi

# The stale duplicate Dockerfile that could not build: it copied files that
# .dockerignore excludes and pinned PHP 8.3 against a ^8.4 requirement.
if [[ -f docker/php-fpm/Dockerfile ]]; then
    bad "docker/php-fpm/Dockerfile still exists (stale PHP 8.3 build whose own context is excluded by .dockerignore)"
else
    ok "no stale duplicate Dockerfile"
fi

if [[ -f docker-compose.production.yml ]]; then
    COMPOSE=docker-compose.production.yml

    if command -v docker >/dev/null 2>&1; then
        docker compose -f "${COMPOSE}" config --quiet 2>/dev/null && ok "compose file parses" || bad "compose file does not parse"
    elif command -v python3 >/dev/null 2>&1 && python3 -c 'import yaml' >/dev/null 2>&1; then
        # `docker compose config` also interpolates variables and checks the
        # schema; a YAML parse only proves the file is well formed. That is
        # strictly weaker, so the weaker result is labelled as such instead of
        # being reported as a full compose validation.
        python3 - "${COMPOSE}" <<'PYEOF' && ok "compose file is valid YAML (schema/interpolation NOT validated: docker absent)" || bad "compose file does not parse"
import sys, yaml
with open(sys.argv[1], encoding="utf-8") as handle:
    document = yaml.safe_load(handle)
if not isinstance(document, dict) or "services" not in document:
    sys.exit("no services block")
PYEOF
    elif php -r 'exit(function_exists("yaml_parse") ? 0 : 1);' >/dev/null 2>&1; then
        php -r '$d = yaml_parse_file("'"${COMPOSE}"'"); exit(is_array($d) && isset($d["services"]) ? 0 : 1);' \
            && ok "compose file is valid YAML (schema/interpolation NOT validated: docker absent)" \
            || bad "compose file does not parse"
    else
        printf '  skip compose parse (no docker, no python yaml, no php yaml)\n'
    fi

    grep -qiE 'artisan serve' "${COMPOSE}" && bad "compose runs a development server" || ok "no development server in compose"

    if grep -qE '^\s+-\s+"?\$\{?DB_PORT' "${COMPOSE}" || grep -qE '3306:3306' "${COMPOSE}"; then
        bad "compose publishes the database port on the host"
    else
        ok "database port is not published"
    fi

    for service in app nginx; do
        awk -v svc="  ${service}:" '$0==svc{f=1} f&&/^  [a-z]/&&$0!=svc{f=0} f&&/healthcheck:/{print "found"}' "${COMPOSE}" | grep -q found \
            && ok "compose service ${service} declares a healthcheck" \
            || bad "compose service ${service} has no healthcheck"
    done
else
    bad "docker-compose.production.yml is missing"
fi

echo "--- compose secret wiring has consumers ---"

# A `*_FILE` variable that no code reads is worse than no variable at all: an
# operator sets it, believes a secret is wired, and the service quietly falls
# back to a default or fails later. Every such variable must be consumed.
if [[ -f docker-compose.production.yml ]]; then
    unconsumed=0

    # Consumed by the official MySQL image itself, not by our code.
    CONSUMED_BY_BASE_IMAGE='^(MYSQL_PASSWORD_FILE|MYSQL_ROOT_PASSWORD_FILE|MYSQL_RANDOM_ROOT_PASSWORD)$'

    while IFS= read -r name; do
        [[ -n "${name}" ]] || continue

        if printf '%s' "${name}" | grep -qE "${CONSUMED_BY_BASE_IMAGE}"; then
            continue
        fi

        # Every place a variable can legitimately be read: PHP source, config,
        # the Rust worker, and the container entrypoint (which loads *_FILE
        # secrets generically before PHP starts).
        if ! grep -rqF "${name}" app/ config/ runtime/ bootstrap/ docker/ scripts/ .env.example 2>/dev/null; then
            bad "compose passes ${name}, but nothing in app/, config/, runtime/, docker/ or scripts/ reads it"
            unconsumed=1
        fi
    done < <(grep -oE '^[[:space:]]+[A-Z0-9_]+_FILE:' docker-compose.production.yml | tr -d ' :' | sort -u)

    [[ "${unconsumed}" -eq 0 ]] && ok "every *_FILE variable in compose is read by application code"
fi

echo "--- structured logging wiring ---"

if grep -q "LOG_FORMAT" config/logging.php && grep -q "php://stderr" config/logging.php; then
    ok "log channel declares an explicit stream and a format switch"
else
    bad "the stderr log channel does not pin its stream or expose LOG_FORMAT"
fi

if [[ -x scripts/ci/assert-structured-logs.sh ]]; then
    ok "structured-log assertion script is present and executable"
else
    bad "scripts/ci/assert-structured-logs.sh is missing or not executable"
fi

echo "--- preflight integration ---"

grep -q "ops:preflight" scripts/ops/deploy.sh && ok "deploy runs ops:preflight" || bad "deploy.sh does not run ops:preflight"
grep -q "migration-audit.php" scripts/ops/deploy.sh && ok "deploy runs the migration safety audit" || bad "deploy.sh does not audit migrations"
grep -q "backup.sh" scripts/ops/deploy.sh && ok "deploy takes a backup before migrating" || bad "deploy.sh does not back up before migrating"

echo
if [[ "${fail}" -ne 0 ]]; then
    echo "Operations asset assertions failed."
    exit 1
fi

echo "Operations assets are complete and sound."
