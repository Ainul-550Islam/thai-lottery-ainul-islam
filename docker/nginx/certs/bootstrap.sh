#!/usr/bin/env bash
#
# Generate a throwaway self-signed TLS pair so nginx can start on a fresh
# checkout or a staging box.
#
# This exists because nginx validates ssl_certificate paths at CONFIG LOAD TIME:
# with an empty certs/ directory the container cannot even boot, which makes
# first-run debugging unnecessarily confusing. Running this once gets the stack
# up so you can verify everything else.
#
# THIS IS NOT A PRODUCTION CERTIFICATE. Browsers will show a warning, and HSTS
# is already set to a year on this server block. Replace these files with a real
# certificate (see README.md) before any real user reaches the site.
#
# Usage:
#   ./bootstrap.sh [domain]        # default: localhost
#
set -euo pipefail

DOMAIN="${1:-localhost}"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

FULLCHAIN="${SCRIPT_DIR}/fullchain.pem"
PRIVKEY="${SCRIPT_DIR}/privkey.pem"

# A self-signed certificate IS its own chain, so "fullchain" is just the cert.
# Some clients still expect the file to contain a chain; a single-leaf
# "fullchain" is valid and is the standard self-signed layout.

if [[ -f "${FULLCHAIN}" || -f "${PRIVKEY}" ]]; then
    echo "Refusing to overwrite an existing certificate." >&2
    echo "  ${FULLCHAIN}: $([[ -f ${FULLCHAIN} ]] && echo present || echo absent)" >&2
    echo "  ${PRIVKEY}:   $([[ -f ${PRIVKEY} ]] && echo present || echo absent)" >&2
    echo "Delete them first if you really want to regenerate." >&2
    exit 1
fi

if ! command -v openssl >/dev/null 2>&1; then
    echo "openssl is not installed or not on PATH." >&2
    exit 1
fi

echo "Generating a self-signed certificate for ${DOMAIN} ..."

# -x509      : emit a self-signed certificate instead of a CSR
# -nodes     : do not encrypt the private key (nginx must read it unattended)
# -newkey    : RSA 2048 is the floor for a modern TLS config; 4096 is slower to
#              handshake for no practical gain on a cert this short-lived
# -sha256    : SHA-1 signatures are rejected by current browsers
# -days 30   : deliberately short. If this certificate is still in use in a
#              month, something has gone wrong and the failure should be loud.
# -addext    : CN alone is ignored by modern clients; SAN is what is checked.
openssl req -x509 -nodes \
    -newkey rsa:2048 \
    -sha256 \
    -days 30 \
    -keyout "${PRIVKEY}" \
    -out "${FULLCHAIN}" \
    -subj "/CN=${DOMAIN}" \
    -addext "subjectAltName=DNS:${DOMAIN},DNS:localhost,IP:127.0.0.1" \
    -addext "basicConstraints=critical,CA:FALSE" \
    -addext "keyUsage=critical,digitalSignature,keyEncipherment" \
    -addext "extendedKeyUsage=serverAuth" \
    2>/dev/null

chmod 644 "${FULLCHAIN}"
chmod 600 "${PRIVKEY}"

echo
echo "Wrote:"
echo "  ${FULLCHAIN}"
echo "  ${PRIVKEY}"
echo
echo "Subject : $(openssl x509 -in "${FULLCHAIN}" -noout -subject)"
echo "SANs    : $(openssl x509 -in "${FULLCHAIN}" -noout -ext subjectAltName | tail -n +2 | tr -d ' ')"
echo "Expires : $(openssl x509 -in "${FULLCHAIN}" -noout -enddate | cut -d= -f2)"
echo
echo "Start the stack with:"
echo "  cd ../../ && docker compose -f docker-compose.production.yml up -d"
echo
echo "REMINDER: replace this with a real certificate before production."
echo "          See README.md in this directory."
