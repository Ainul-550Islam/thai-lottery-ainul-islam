#!/bin/sh
set -eu

load_secret_file() {
    variable_name="$1"
    file_path="$2"
    required="$3"

    if [ -n "$file_path" ]; then
        if [ ! -r "$file_path" ]; then
            printf '%s\n' "Required secret file for ${variable_name} is not readable: ${file_path}" >&2
            exit 1
        fi

        secret_value=$(cat "$file_path")
        if [ -z "$secret_value" ]; then
            printf '%s\n' "Secret file for ${variable_name} is empty." >&2
            exit 1
        fi

        export "${variable_name}=${secret_value}"
    elif [ "$required" = "yes" ]; then
        case "$variable_name" in
            APP_KEY) current_value=${APP_KEY:-} ;;
            DB_PASSWORD) current_value=${DB_PASSWORD:-} ;;
            REDIS_PASSWORD) current_value=${REDIS_PASSWORD:-} ;;
            *)
                printf '%s\n' "Unsupported secret variable: ${variable_name}" >&2
                exit 1
                ;;
        esac

        if [ -z "$current_value" ]; then
            printf '%s\n' "${variable_name} or its *_FILE setting is required in production." >&2
            exit 1
        fi
    fi
}

if [ "${APP_ENV:-production}" = "production" ]; then
    load_secret_file APP_KEY "${APP_KEY_FILE:-}" yes
    load_secret_file DB_PASSWORD "${DB_PASSWORD_FILE:-}" yes
    load_secret_file REDIS_PASSWORD "${REDIS_PASSWORD_FILE:-}" yes
else
    load_secret_file APP_KEY "${APP_KEY_FILE:-}" no
    load_secret_file DB_PASSWORD "${DB_PASSWORD_FILE:-}" no
    load_secret_file REDIS_PASSWORD "${REDIS_PASSWORD_FILE:-}" no
fi

# The settlement worker secret is optional for local/dev containers but loaded
# into PHP for the signed, read-only Rust parity request when mounted.
load_secret_file SETTLEMENT_WORKER_HMAC_SECRET "${SETTLEMENT_WORKER_HMAC_SECRET_FILE:-}" no

exec /usr/local/bin/docker-php-entrypoint "$@"
