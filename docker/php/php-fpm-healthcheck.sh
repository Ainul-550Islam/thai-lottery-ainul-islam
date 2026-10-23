#!/bin/sh
# php-fpm-healthcheck
# Hit the php-fpm /ping endpoint over FastCGI and verify "pong" is returned.
# Uses cgi-fcgi (from fcgi apk package) with no third-party scripts.
set -eu

FPM_LISTEN="${PHP_FPM_LISTEN:-127.0.0.1:9000}"

SCRIPT_FILENAME=/dev/null \
SCRIPT_NAME=/ping \
REQUEST_METHOD=GET \
QUERY_STRING= \
REQUEST_URI=/ping \
cgi-fcgi -bind -connect "${FPM_LISTEN}" 2>/dev/null | grep -q '^pong$'
