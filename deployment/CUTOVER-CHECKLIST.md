# Domain Cutover Checklist — Legacy `.php` Site → Modern Application

Status legend: `[ ]` pending · `[x]` done · `[!]` blocked (note why)

This checklist records the **operational deployment order** for switching the
live domain from the legacy PHP site to this application. It is a runbook, not
a code change: every step must be executed by the operator with real
credentials, on the production host. Nothing in this repository contains
production secrets.

---

## 0. Pre-flight (before touching DNS)

- [ ] Full test suite green on the release commit (`php artisan test`), and
      the clean build ZIP for that commit exists and was extracted fresh.
- [ ] `php artisan config:cache` / `route:cache` / `view:cache` succeed on a
      staging host with production-like `.env` (never commit the real `.env`).
- [ ] `composer install --no-dev --optimize-autoloader` and
      `npm ci && npm run build` (or the pre-built `public/build` artifact)
      verified — page tests 500 without the Vite manifest.
- [ ] Legacy URL bridge verified on staging: every documented `.php` path
      answers 301 (`tests/Feature/LegacyRedirectTest.php` is the map), and
      unknown `.php` paths still 404.

## 1. Database

- [ ] **Backup** the production database (full dump + checksum) and store it
      off-host. Record the dump path and checksum in the release ticket.
- [ ] Provision the production database and run `php artisan migrate --force`
      on the release commit.
- [ ] Seed only reference data (chart of accounts, roles). **Do not seed
      fabricated lottery results** — the results import has an explicit
      no-fabrication contract (`database/seeders/data/results/README.md`).

## 2. Real result data import

- [ ] Obtain the approved historical result bundle for every lane
      (National, Weekly, Mega/Bingo, PCSO) with provenance and checksums.
- [ ] Import it via the import command for each lane; the import contract
      rejects malformed numbers/dates and duplicate draws.
- [ ] Spot-check published year pages and detail pages for every lane
      (leading zeros intact, dates canonical), and re-run the archive
      parity test.

## 3. Application configuration (production `.env`)

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<domain>`.
- [ ] `APP_KEY` generated fresh and stored in the secret manager.
- [ ] Session/cookie domain set to the live domain; secure cookie flags on.
- [ ] TLS certificates installed/renewed and HTTPS redirect enforced at the
      web-server layer.
- [ ] `php artisan storage:link` executed for public disk assets.

## 4. Payments (each gateway the operator actually launches)

- [ ] Gateway enabled flag + real credentials set in the production env
      (Stripe secret + webhook secret; bKash/Nagad keys; Crypto provider).
- [ ] **Webhook endpoints** registered with each provider pointing at
      `APP_URL/api/payment/webhooks/{gateway}` — payment state changes ONLY
      through signature-verified webhooks.
- [ ] Browser-return paths (`PAYMENT_SUCCESS_URL` / `PAYMENT_FAILURE_URL` /
      `PAYMENT_CANCEL_URL`) resolve to the live domain and the four
      `/payment/*` routes answer.
- [ ] **Manual bank transfer**: set the REAL settlement bank/account/name in
      env. With any of them missing the gateway fails closed — that is
      correct; do not work around it.
- [ ] A small live deposit + withdrawal smoke test per enabled gateway,
      then reconciliation against the ledger.

## 5. Legal / support / operator identity

- [ ] `LEGAL_OPERATOR_NAME`, `LEGAL_OPERATOR_REGISTRATION`,
      `LEGAL_SUPPORT_EMAIL`, `LEGAL_SUPPORT_PHONE`,
      `LEGAL_OPERATOR_ADDRESS` filled from the **approved** source of truth.
      Never invent ownership, endorsement or government relationship.
- [ ] Fees/discount/prize values reviewed and signed off (config-driven only).
- [ ] App links (`HOME_APP_ANDROID_URL` / `HOME_APP_IOS_URL` /
      `HOME_APP_PWA_URL`) either real or left empty — the card fails closed
      and never renders fake store buttons.

## 6. Workers, scheduler, cache

- [ ] Queue workers running (`php artisan queue:work` under a supervisor)
      with the production queue connection.
- [ ] Scheduler entry in cron (`php artisan schedule:run`) — deposit
      expiry, draw settlement and reconciliation jobs depend on it.
- [ ] `php artisan config:cache && php artisan route:cache && php artisan view:cache`
      executed **after** env values are final.

## 7. Cutover

- [ ] DNS/CDN switched to the new application host.
- [ ] Legacy `.php` requests continue to serve the 301 bridge from THIS
      application (no separate redirect server needed).
- [ ] `public/robots.txt` reviewed (admin/API/search disallows + sitemap
      line); sitemap route answers with canonical URLs only.
- [ ] HTTPS, HSTS, and secure-cookie spot checks from a clean browser.

## 8. Post-cutover smoke tests (record results in the release ticket)

- [ ] Home, all four lane indexes, a year archive and a draw detail page
      per lane render with real data.
- [ ] Register → login → deposit → (sandbox) gateway → browser-return page
      shows the AUTHORITATIVE internal state.
- [ ] Logout is POST-only; back-button after logout does not resurrect the
      session.
- [ ] One legacy `.php` URL per family spot-checked for 301 target.
- [ ] Webhook signature rejection verified (tampered signature → 422/400,
      no ledger movement).

## 9. Rollback plan

- [ ] DNS back to the legacy host (TTL documented beforehand).
- [ ] Database rollback decision recorded: migrations are NOT auto-reverted;
      if a rollback requires schema reversal, use the backup from step 1 and
      document data loss implications explicitly.
- [ ] The previous release ZIP + its commit hash kept warm for redeploy.

---

## Known live-side issues that resolve at cutover

Recorded by the final audit against the legacy live site — none of these are
code defects, they disappear when the legacy host stops serving traffic:

- stale home snapshot / outdated "last update" dates
- broken 2564 archive chains and corrupted 2563 entries (typo-form URLs)
- Results navigation misroute
- spelling defects on legacy pages
- member auth still on `secure.thailotto.club` host
