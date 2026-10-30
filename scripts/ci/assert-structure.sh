#!/usr/bin/env bash
# =============================================================================
# assert-structure.sh — repository invariants that the test suite cannot see.
#
# Extracted from the inline YAML in the base commit's ci.yml so that it can be
# run before pushing, and so the workflow file stays readable. The checks are
# unchanged in intent; each one caught a real failure at some point:
#
#   php -l                 a parse error is a 500 on every route
#   required files         a refactor that deletes a service still "builds"
#   runtime directories    a missing storage/framework/views is a confusing
#                          "Please provide a valid cache path" much later
#   model/factory parity   a model with HasFactory and no factory fails only
#                          in the one test that calls ::factory()
#
# USAGE
#   scripts/ci/assert-structure.sh
# =============================================================================
set -Eeuo pipefail
IFS=$'\n\t'

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
cd -- "${ROOT}"

fail=0
ok()  { printf '  ok   %s\n' "$*"; }
bad() { printf '::error::%s\n' "$*"; fail=1; }

echo "--- php syntax ---"
COUNT=0

while IFS= read -r -d '' file; do
    if ! php -l "${file}" >/dev/null 2>&1; then
        printf '::error file=%s::syntax error\n' "${file}"
        fail=1
    fi
    COUNT=$((COUNT + 1))
done < <(find app bootstrap config database routes tests scripts -name '*.php' -print0 2>/dev/null)

ok "${COUNT} PHP files parse"

echo "--- required structural files ---"
REQUIRED=(
    app/Models/User.php
    app/Models/Agent.php
    app/Services/Finance/FinancialReconciliationService.php
    app/Services/Infrastructure/ImmutableBackupService.php
    app/Services/Infrastructure/DatabaseDumpService.php
    app/Support/Backup/BackupCipher.php
    app/Support/Ops/ProductionPreflight.php
    app/Logging/RedactSensitiveData.php
    app/Http/Controllers/HealthController.php
    app/Console/Commands/Ops/PreflightCommand.php
    app/Console/Commands/Ops/HealthCommand.php
    app/Console/Commands/Ops/HeartbeatCommand.php
    app/Console/Commands/Infrastructure/ExecuteEncryptedBackupCommand.php
    app/Console/Commands/Infrastructure/VerifyBackupCommand.php
    app/Console/Commands/Infrastructure/ExtractBackupCommand.php
    app/Console/Commands/Infrastructure/RestoreDatabaseCommand.php
    config/backup.php
    config/ops.php
    routes/api.php
    routes/web.php
    public/index.php
    .github/workflows/ci.yml
    .github/workflows/security.yml
    docker/php/Dockerfile
    docker-compose.production.yml
)

MISSING=0

for required in "${REQUIRED[@]}"; do
    if [[ ! -f "${required}" ]]; then
        bad "missing required file: ${required}"
        MISSING=$((MISSING + 1))
    fi
done

[[ "${MISSING}" -eq 0 ]] && ok "${#REQUIRED[@]} required files present"

echo "--- runtime directories ---"
for directory in \
    bootstrap/cache \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs
do
    [[ -d "${directory}" ]] && continue
    bad "missing runtime directory: ${directory}"
done
ok "runtime directories checked"

echo "--- migrations and tests exist ---"
[[ -n "$(find database/migrations -name '*.php' -print -quit)" ]] && ok "migrations present" || bad "no migrations found"
[[ -n "$(find tests -name '*Test.php' -print -quit)" ]] && ok "tests present" || bad "no tests found"

echo "--- HasFactory parity ---"
PARITY=0

while IFS= read -r -d '' model; do
    name="$(basename "${model}" .php)"

    # An abstract model cannot be instantiated, so `::factory()` on it is
    # meaningless: the base classes under app/Models/Support are shared parents,
    # not entities. Requiring a factory for them was a false positive in the
    # previous workflow's version of this check.
    if grep -qE '^\s*(abstract\s+)?(final\s+)?class ' "${model}" && grep -qE '^abstract\s+class ' "${model}"; then
        continue
    fi

    if grep -q 'HasFactory' "${model}" && [[ ! -f "database/factories/${name}Factory.php" ]]; then
        bad "model ${model} declares HasFactory but database/factories/${name}Factory.php is missing"
        PARITY=1
    fi
done < <(find app/Models -name '*.php' -print0 2>/dev/null)

[[ "${PARITY}" -eq 0 ]] && ok "every model with HasFactory has a factory"

echo
if [[ "${fail}" -ne 0 ]]; then
    echo "Structural assertions failed."
    exit 1
fi

echo "Structural assertions hold."
