# TLS certificates — required before the stack will start

The 443 server block in `../nginx.conf` reads:

```nginx
ssl_certificate     /etc/nginx/certs/fullchain.pem;
ssl_certificate_key /etc/nginx/certs/privkey.pem;
```

nginx validates the certificate paths at **configuration load time**. If either
file is missing the container exits immediately with:

```
nginx: [emerg] cannot load certificate "/etc/nginx/certs/fullchain.pem":
              BIO_new_file() failed (SSL: error:80000002:system library::fopen:No such file or directory)
```

This directory is bind-mounted read-only into the container by
`docker-compose.production.yml`:

```yaml
- ./docker/nginx/certs:/etc/nginx/certs:ro
```

so whatever is here on the host is what nginx sees.

---

## What goes in this directory

| File | Purpose |
|---|---|
| `fullchain.pem` | your leaf certificate **followed by** the intermediate chain |
| `privkey.pem`   | the private key |

Order inside `fullchain.pem` matters: leaf first, then intermediates, then
optionally the root. Getting it backwards produces a browser-trusted leaf with
an incomplete chain, which fails on some Android and Java clients.

---

## Option 1 — a real certificate (recommended)

### Let's Encrypt via certbot, using the built-in ACME path

`nginx.conf` already serves `/.well-known/acme-challenge/` from
`/var/www/html/public` over plain HTTP, so webroot validation works with no
downtime:

```bash
export DOMAIN=lottery.example.com

# 1. First boot: get a certificate BEFORE nginx is listening on 443.
#    Start only the port-80 plane, or run certbot on the host against the
#    same webroot.
certbot certonly --webroot \
    --webroot-path "$(pwd)/../../public" \
    -d "$DOMAIN"

# 2. Copy (do not symlink — the container mount is read-only and certbot
#    renewals replace files atomically, so copying is intentional).
cp "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" fullchain.pem
cp "/etc/letsencrypt/live/$DOMAIN/privkey.pem"   privkey.pem
chmod 644 fullchain.pem
chmod 600 privkey.pem

# 3. Start the stack.
cd ../../ && docker compose -f docker-compose.production.yml up -d
```

### Renewal

`certbot renew` writes new files under `/etc/letsencrypt/live/$DOMAIN/`. Add a
deploy hook so the copies and the reload happen automatically:

```bash
cat > /etc/letsencrypt/renewal-hooks/deploy/thai-lottery.sh <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
DOMAIN="lottery.example.com"
CERTDIR="/srv/thai-lottery/docker/nginx/certs"
cp "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" "$CERTDIR/fullchain.pem"
cp "/etc/letsencrypt/live/$DOMAIN/privkey.pem"   "$CERTDIR/privkey.pem"
chmod 644 "$CERTDIR/fullchain.pem"
chmod 600 "$CERTDIR/privkey.pem"
docker compose -f /srv/thai-lottery/docker-compose.production.yml exec -T nginx \
    nginx -s reload
EOF
chmod +x /etc/letsencrypt/renewal-hooks/deploy/thai-lottery.sh
```

---

## Option 2 — self-signed, for a first boot or a staging box

Run the bootstrap script in this directory:

```bash
./bootstrap.sh lottery.example.com
```

It generates a throwaway self-signed pair so nginx can start and you can verify
the rest of the stack. **Browsers will warn; this is not a production
certificate.** Replace it with Option 1 before real users arrive.

---

## Security notes

* `privkey.pem` must never be committed. `.gitignore` in the repository root
  excludes `*.pem`; verify with `git status` before you push.
* The bind mount is `:ro`. nginx only ever reads these files.
* Private keys are not written to any log: `access_log` and `error_log` carry
  request metadata only.
* HSTS is set to `max-age=31536000; includeSubDomains`. Once a browser has seen
  that header, it will refuse plaintext HTTP to this host for a year. Deploy the
  real certificate **before** you rely on that, not after.
