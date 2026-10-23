#!/bin/sh
# ---------------------------------------------------------------------------
# Laravel Docker entrypoint.
#
# Responsibilities:
#   1. Load *_FILE Docker/Kubernetes secrets into environment variables.
#   2. Optionally export compiled frontend assets to a shared volume
#      (ASSET_EXPORT_DIR) so the Nginx sidecar serves exactly the same
#      public/build/ manifest + hashed chunks that this php-fpm image built.
#   3. Exec the real docker-php-entrypoint.
#
# Fail-closed: unreadable or empty required secrets abort boot.
# Secret values are never logged.
# ---------------------------------------------------------------------------
set -eu

log() {
    printf '[laravel-entrypoint] %s\n' "$*" >&2
}

load_secret_file() {
    variable_name="$1"
    file_path="$2"
    required="$3"

    file_var="${variable_name}_FILE"
    if [ -z "$file_path" ] && [ -n "$(eval printf '%s' "\"\${${file_var}:-}\"")" ]; then
        file_path="$(eval printf '%s' "\"\${${file_var}:-}\"")"
    fi

    if [ -n "$file_path" ]; then
        if [ ! -r "$file_path" ]; then
            log "Required secret file for ${variable_name} is not readable: ${file_path}"
            exit 1
        fi
        secret_value="$(tr -d '\r\n' < "$file_path")"
        if [ -z "$secret_value" ]; then
            log "Secret file for ${variable_name} is empty."
            exit 1
        fi
        export "${variable_name}=${secret_value}"
    elif [ "$required" = "yes" ]; then
        current_value="$(eval printf '%s' "\"\${${variable_name}:-}\"")"
        case "$variable_name" in
            APP_KEY|DB_PASSWORD|REDIS_PASSWORD) ;;
            *)
                log "Unsupported required secret variable: ${variable_name}"
                exit 1
                ;;
        esac
        if [ -z "$current_value" ]; then
            log "${variable_name} or its ${file_var} setting is required in production."
            exit 1
        fi
    fi
}

# --- secret loading ---
if [ "${APP_ENV:-production}" = "production" ]; then
    load_secret_file APP_KEY "${APP_KEY_FILE:-}" yes
    load_secret_file DB_PASSWORD "${DB_PASSWORD_FILE:-}" yes
    load_secret_file REDIS_PASSWORD "${REDIS_PASSWORD_FILE:-}" yes
else
    load_secret_file APP_KEY "${APP_KEY_FILE:-}" no
    load_secret_file DB_PASSWORD "${DB_PASSWORD_FILE:-}" no
    load_secret_file REDIS_PASSWORD "${REDIS_PASSWORD_FILE:-}" no
fi

# --- asset export (for shared nginx volume) ---
if [ -n "${ASSET_EXPORT_DIR:-}" ]; then
    if [ -d /var/www/html/public/build ]; then
        mkdir -p "${ASSET_EXPORT_DIR}"
        rm -rf "${ASSET_EXPORT_DIR}.new"
        cp -a /var/www/html/public/build "${ASSET_EXPORT_DIR}.new"
        rm -rf "${ASSET_EXPORT_DIR}"
        mv "${ASSET_EXPORT_DIR}.new" "${ASSET_EXPORT_DIR}"
        log "exported frontend assets to ${ASSET_EXPORT_DIR}"
    else
        log "WARNING: /var/www/html/public/build does not exist; cannot export assets"
    fi
fi

exec /usr/local/bin/docker-php-entrypoint "$@"
