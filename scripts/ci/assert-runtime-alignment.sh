#!/usr/bin/env bash
# =============================================================================
# assert-runtime-alignment.sh — the CI runtime must be the runtime we ship.
#
# WHY THIS EXISTS
# ---------------
# The base commit's workflows installed PHP 8.3 and Node 20 while
# `composer.json` requires PHP ^8.4 (and `composer.lock` pins that platform) and
# `package.json` requires Node >=24 <25 with `.npmrc` setting
# `engine-strict=true`. Three consequences, all of them real:
#
#   * `composer install` refuses to resolve the lock file on PHP 8.3, so the CI
#     job fails before it can test anything;
#   * `npm ci` fails the same way on Node 20 because engine-strict turns the
#     engines field into a hard requirement;
#   * and if either had been downgraded to "make CI green", the pipeline would
#     have been validating a runtime the application cannot use in production.
#
# The fix is to make the alignment a checked invariant rather than a habit:
# this script fails when the workflow, the lock file, .nvmrc and package.json
# disagree. It is runnable locally:
#
#   scripts/ci/assert-runtime-alignment.sh
# =============================================================================
set -Eeuo pipefail
IFS=$'\n\t'

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
cd -- "${ROOT}"

fail=0
note() { printf '  %s\n' "$*"; }
bad()  { printf '::error::%s\n' "$*"; fail=1; }
good() { printf '  ok  %s\n' "$*"; }

echo "--- composer platform requirement ---"
COMPOSER_PHP="$(php -r '$c=json_decode(file_get_contents("composer.json"),true); echo $c["require"]["php"] ?? "";')"
LOCK_PHP="$(php -r '$c=json_decode(file_get_contents("composer.lock"),true); echo $c["platform"]["php"] ?? "";')"

if [[ -z "${COMPOSER_PHP}" ]]; then
    bad "composer.json does not declare a php requirement."
else
    good "composer.json requires php ${COMPOSER_PHP}"
fi

if [[ "${COMPOSER_PHP}" != "${LOCK_PHP}" ]]; then
    bad "composer.lock platform (${LOCK_PHP}) disagrees with composer.json (${COMPOSER_PHP}); the lock is stale. Run composer update --lock."
else
    good "composer.lock platform matches: ${LOCK_PHP}"
fi

# The minimum PHP of the declared constraint, e.g. ^8.4 -> 8.4.
REQUIRED_PHP="${COMPOSER_PHP#^}"
REQUIRED_PHP="${REQUIRED_PHP%%[., ]*}"

echo "--- package.json engines ---"
NODE_ENGINE="$(php -r '$c=json_decode(file_get_contents("package.json"),true); echo $c["engines"]["node"] ?? "";')"
NPM_ENGINE="$(php -r '$c=json_decode(file_get_contents("package.json"),true); echo $c["engines"]["npm"] ?? "";')"
PACKAGE_MANAGER="$(php -r '$c=json_decode(file_get_contents("package.json"),true); echo $c["packageManager"] ?? "";')"

if [[ -z "${NODE_ENGINE}" ]]; then
    bad "package.json does not declare an engines.node range."
else
    good "package.json requires node ${NODE_ENGINE}"
fi

echo "--- .nvmrc ---"
if [[ ! -f .nvmrc ]]; then
    bad ".nvmrc is missing; local and CI Node drift is then unavoidable."
else
    NVMRC="$(tr -d 'v \n' < .nvmrc)"
    good ".nvmrc pins node ${NVMRC}"

    # Extract the engine floor, e.g. ">=24 <25" -> 24.
    ENGINE_FLOOR="$(printf '%s' "${NODE_ENGINE}" | sed -E 's/^[^0-9]*([0-9]+).*/\1/')"
    NVMRC_MAJOR="${NVMRC%%.*}"

    if [[ -n "${ENGINE_FLOOR}" && "${NVMRC_MAJOR}" != "${ENGINE_FLOOR}" ]]; then
        bad ".nvmrc pins node ${NVMRC} but package.json requires ${NODE_ENGINE}."
    else
        good ".nvmrc major (${NVMRC_MAJOR}) satisfies package.json (${NODE_ENGINE})"
    fi
fi

if [[ ! -f .npmrc ]] || ! grep -q 'engine-strict=true' .npmrc; then
    bad ".npmrc must set engine-strict=true; without it the engines field is advisory and CI silently installs on the wrong Node."
else
    good ".npmrc sets engine-strict=true"
fi

if [[ -n "${PACKAGE_MANAGER}" ]]; then
    PM_NPM="${PACKAGE_MANAGER#npm@}"
    good "packageManager pins ${PACKAGE_MANAGER}"

    # The pinned npm must satisfy engines.npm, otherwise corepack installs a
    # package manager the package itself declares unsupported.
    if [[ -n "${NPM_ENGINE}" ]]; then
        FLOOR="$(printf '%s' "${NPM_ENGINE}" | sed -E 's/^[^0-9]*([0-9]+).*/\1/')"
        CEIL="$(printf '%s' "${NPM_ENGINE}" | sed -E 's/.*<([0-9]+).*/\1/')"
        PM_MAJOR="${PM_NPM%%.*}"

        if [[ "${PM_MAJOR}" -lt "${FLOOR}" ]] || { [[ "${CEIL}" =~ ^[0-9]+$ ]] && [[ "${PM_MAJOR}" -ge "${CEIL}" ]]; }; then
            bad "packageManager npm@${PM_NPM} does not satisfy engines.npm (${NPM_ENGINE})."
        else
            good "packageManager npm@${PM_NPM} satisfies engines.npm (${NPM_ENGINE})"
        fi
    fi
else
    bad "package.json does not declare packageManager; the npm version used by CI is then whatever the runner image happens to ship."
fi

echo "--- workflow runtime declarations ---"
shopt -s nullglob
WORKFLOWS=(.github/workflows/*.yml .github/workflows/*.yaml)
shopt -u nullglob

if [[ "${#WORKFLOWS[@]}" -eq 0 ]]; then
    bad "No workflows found under .github/workflows."
fi

for workflow in "${WORKFLOWS[@]}"; do
    # php-version: '8.x' entries
    while IFS= read -r line; do
        version="$(printf '%s' "${line}" | sed -E "s/.*php-version:[[:space:]]*'?([0-9]+\.[0-9]+)'?.*/\1/")"

        if [[ "${version}" < "${REQUIRED_PHP}" ]]; then
            bad "${workflow}: php-version ${version} is below the required ${REQUIRED_PHP}."
        else
            good "${workflow}: php-version ${version}"
        fi
    done < <(grep -nE "php-version:[[:space:]]*'?[0-9]+\.[0-9]+" "${workflow}" || true)

    # node-version: entries — either a quoted major or an .nvmrc reference.
    while IFS= read -r line; do
        value="$(printf '%s' "${line}" | sed -E "s/.*node-version:[[:space:]]*'?([^'\"#]+)'?.*/\1/" | tr -d ' ')"

        case "${value}" in
            *nvmrc*)
                good "${workflow}: node-version from .nvmrc"
                ;;
            *)
                major="${value%%.*}"
                floor="$(printf '%s' "${NODE_ENGINE}" | sed -E 's/^[^0-9]*([0-9]+).*/\1/')"

                if [[ "${major}" =~ ^[0-9]+$ ]] && [[ -n "${floor}" ]] && [[ "${major}" -lt "${floor}" ]]; then
                    bad "${workflow}: node-version ${value} is below the required ${NODE_ENGINE}."
                else
                    good "${workflow}: node-version ${value}"
                fi
                ;;
        esac
    done < <(grep -nE "node-version:" "${workflow}" || true)
done

if [[ "${fail}" -ne 0 ]]; then
    echo
    echo "Runtime alignment failed. The CI runtime must satisfy composer.json and package.json;"
    echo "downgrading the runtime to make a workflow green tests a runtime we do not ship."
    exit 1
fi

echo
echo "Runtime alignment holds: PHP ${REQUIRED_PHP}+, Node ${NODE_ENGINE}."
