#!/bin/sh
# Demo entrypoint for docker-compose.demo.yml.  NOT for production.
#
# First start : generates a throwaway APP_KEY, migrates, then runs a one-time
#               demo bootstrap (roles + ledger seed, upcoming draws, bet
#               capacity, demo admin).
# Every start : serves the app with `php artisan serve` and, unless DEMO_TICK=0,
#               advances draw lifecycles once a minute (the demo has no
#               scheduler, so nothing else would move a draw forward).
set -eu

state_dir=/var/www/html/storage/demo
mkdir -p "$state_dir"

# --- APP_KEY ---------------------------------------------------------------
if [ -z "${APP_KEY:-}" ]; then
    if [ ! -s "$state_dir/app_key" ]; then
        php artisan key:generate --show > "$state_dir/app_key"
    fi
    APP_KEY=$(cat "$state_dir/app_key")
    export APP_KEY
fi

case "$APP_KEY" in
    base64:*) ;;
    *)
        echo "Could not obtain a valid APP_KEY." >&2
        exit 1
        ;;
esac

# --- schema ----------------------------------------------------------------
php artisan migrate --force

# --- demo admin credentials ------------------------------------------------
# No password is committed anywhere: unless DEMO_ADMIN_PASSWORD is provided, a
# random one is generated once and kept in the demo storage volume.
if [ -z "${DEMO_ADMIN_PASSWORD:-}" ]; then
    if [ ! -s "$state_dir/admin_password" ]; then
        php -r 'echo bin2hex(random_bytes(9));' > "$state_dir/admin_password"
    fi
    DEMO_ADMIN_PASSWORD=$(cat "$state_dir/admin_password")
fi
export DEMO_ADMIN_PASSWORD

DEMO_ADMIN_EMAIL=${DEMO_ADMIN_EMAIL:-demo@example.test}
export DEMO_ADMIN_EMAIL

# --- one-time demo bootstrap -----------------------------------------------
# Every step is allowed to fail without stopping the server; the marker file is
# only written when all of them succeeded, so a failed bootstrap is retried on
# the next start.
if [ "${DEMO_BOOTSTRAP:-1}" = "1" ] && [ ! -f "$state_dir/bootstrapped" ]; then
    ok=1
    php artisan db:seed --force || ok=0
    php artisan lottery:schedule-draws || ok=0
    php artisan lottery:open-draws || ok=0
    php artisan risk:provision-number-limits --open || ok=0

    if [ "$ok" = "1" ]; then
        touch "$state_dir/bootstrapped"
    else
        echo "demo bootstrap: at least one step failed; it will be retried on the next start." >&2
    fi
fi

# --- demo admin (every start; idempotent) ----------------------------------
php /demo/create-admin.php || echo "demo admin: create-admin failed" >&2
# --- banner ----------------------------------------------------------------
port=${DEMO_PORT:-8080}
echo
echo "================================================================"
if [ "${REAL_MONEY_ENABLED:-false}" = "true" ]; then
    mode="REAL MONEY ENABLED"
else
    mode="real money disabled"
fi
echo " THAI LOTTERY DEMO   (local only, $mode)"
echo "   Site  : http://localhost:$port"
echo "   Admin : http://localhost:$port/admin/login"
echo "   Login : $DEMO_ADMIN_EMAIL"
echo "   Pass  : $DEMO_ADMIN_PASSWORD"
echo "================================================================"
echo

# --- draw lifecycle ticker (no scheduler in the demo) ----------------------
if [ "${DEMO_TICK:-1}" = "1" ]; then
    (
        while true; do
            sleep 60
            php artisan lottery:tick >/dev/null 2>&1 || true
        done
    ) &
fi

exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload
