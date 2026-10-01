# PROMPT 5 — FINAL PRODUCTION CUTOVER
## THAILOTTO ENTERPRISE WAGERING PLATFORM
## REAL MONEY / GLO DATA / PAYMENT / WALLET / SECURITY / LIVE OPERATIONS

You are the FINAL production-readiness implementation and verification agent.

Previous prompts handled application code, business logic, wallet, payment, GLO, results,
security, jobs, frontend, API, DB, compliance and supporting infrastructure.

THIS PROMPT IS THE FINAL PRODUCTION CUTOVER LAYER.

Do NOT assume the application is ready merely because previous reports say COMPLETE.

============================================================
# 0. ABSOLUTE NO-SKIP RULE
============================================================

1. DO NOT SKIP ANY SECTION OF THIS PROMPT.
2. DO NOT SKIP ANY COMMAND.
3. DO NOT SKIP ANY production prerequisite.
4. DO NOT skip a provider verification because credentials are unavailable.
5. DO NOT skip live smoke tests because deployment is not yet performed.
6. DO NOT convert an unavailable test into PASS.
7. DO NOT convert a checklist item into VERIFIED just because code exists.
8. DO NOT mark production-ready based only on PHPUnit.
9. DO NOT hide failed/blocked/skipped tests.
10. DO NOT fabricate provider responses.
11. DO NOT fabricate GLO historical data.
12. DO NOT fabricate legal/operator details.
13. DO NOT fabricate bank account details.
14. DO NOT fabricate production URLs.
15. DO NOT silently replace source-of-truth business data.
16. DO NOT remove an existing security control to make deployment easier.

============================================================
# 1. FULL-FILE CONTENT RULE
============================================================

For every file changed by this prompt:

- Read the ENTIRE existing file.
- Preserve all existing working logic.
- Provide COMPLETE final file content.
- Never provide partial snippets.
- Never use:
  # ... existing code ...
  // ... existing code ...
  /* existing code */
  ...
  omitted
  same as above

No placeholder implementation is allowed.

============================================================
# 2. AUTHORITATIVE FILE
============================================================

deployment/CUTOVER-CHECKLIST.md
# RELEASE AGENT:
# Make this the single authoritative executable production checklist.
# Remove every placeholder artifact such as:
# `svgBashsvgsvg`
# and replace example-only values such as:
# `https://thailotto.example.com`
# only when an actual approved production value exists.
# If the real production value is not available, mark the field:
# NOT VERIFIED — OPERATOR INPUT REQUIRED
# Do NOT invent a production value.

# Add exact:
# release commit SHA
# artifact SHA-256
# PHP version
# Laravel version
# Node/NPM version
# Rust/Cargo version
# database engine/version
# queue backend
# cache backend
# production APP_URL
# webhook URLs
# session domain
# CDN/load balancer
# rollback artifact
# backup ID
# backup checksum
# migration version
# deploy timestamp
# operator approval
# post-cutover verification timestamp

============================================================
# 3. RELEASE ARTIFACT
============================================================

# RELEASE AGENT — verify:

[ ] exact Git commit identified
[ ] exact release artifact identified
[ ] artifact SHA-256 recorded
[ ] composer.lock matches tested source
[ ] npm lockfile matches tested source
[ ] vendor installed reproducibly
[ ] public/build/manifest.json exists
[ ] all manifest assets exist
[ ] no .env secrets inside artifact
[ ] no debug/test-only files
[ ] no fixture publication mode
[ ] no fake bank identity
[ ] no fake user identity
[ ] no synthetic production payout success
[ ] no development credentials
[ ] no local URLs
[ ] no example domain
[ ] no placeholder deployment commands

Required commands:

composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build

Then verify artifact contents.

If any required artifact is missing:

BLOCKED / NOT VERIFIED

Do not deploy.

============================================================
# 4. ENVIRONMENT SECURITY
============================================================

# RELEASE/SECURITY AGENT:

Verify:

APP_ENV=production
APP_DEBUG=false
APP_URL=<real approved production URL>
APP_KEY=<fresh production key>
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

Also verify:

[ ] TLS active
[ ] HSTS configured
[ ] HTTPS redirect
[ ] secure cookies
[ ] trusted proxy configuration
[ ] production storage path
[ ] private KYC storage inaccessible publicly
[ ] secrets not written to logs
[ ] secrets not present in source/artifact
[ ] error pages do not expose stack traces

Do not print secret values in the report.

============================================================
# 5. DATABASE BACKUP / RESTORE
============================================================

# DATABASE AGENT:

Before deployment:

mysqldump --single-transaction --quick --lock-tables=false \
-u $DB_USER -p $DB_NAME |
gzip > /backups/db-pre-cutover-$(date +%s).sql.gz

Then:

sha256sum /backups/db-pre-cutover-*.sql.gz

Verify:

[ ] backup exists
[ ] checksum recorded
[ ] backup readable
[ ] restore has been tested
[ ] rollback target is known

A backup that has never been restore-tested is:

NOT VERIFIED

============================================================
# 6. DATABASE MIGRATION
============================================================

# DATABASE AGENT:

Run:

php artisan migrate --force

Then verify:

[ ] migration table consistent
[ ] no failed migrations
[ ] no destructive migration without rollback plan
[ ] indexes exist
[ ] unique financial constraints exist
[ ] wallet currency constraints exist
[ ] payment idempotency constraints exist
[ ] webhook uniqueness exists
[ ] ticket identity uniqueness exists
[ ] prize-claim uniqueness exists
[ ] agent commission uniqueness exists

If migration modifies financial tables:

perform post-migration invariant validation.

============================================================
# 7. REFERENCE DATA
============================================================

Only allowed reference seeders may run:

php artisan db:seed --class=RoleAndPermissionSeeder --force
php artisan db:seed --class=LedgerAccountSeeder --force

# DATA AGENT:
# Verify these seeders do NOT create:
# fake players
# fake wallet balances
# fake GLO results
# fake winning numbers
# fake provider transactions
# fake payouts

Never run fabricated-result seeders in production.

============================================================
# 8. REAL GLO DATA
============================================================

# GLO DATA AGENT:

For each lane:

National
Weekly
Mega/Bingo
PCSO

verify:

[ ] source-of-record identified
[ ] payload received
[ ] SHA-256 checksum recorded
[ ] import validation passed
[ ] provenance recorded
[ ] date/timezone validated
[ ] duplicate policy checked
[ ] conflicts reconciled
[ ] publication state correct
[ ] historical archive verified
[ ] no fixture data published
[ ] no fabricated result values

Historical data that is absent remains:

OPEN — OPERATIONAL

Do NOT fill missing history from guesses, scraping assumptions, or fixtures.

============================================================
# 9. GLO L6 BUSINESS VALIDATION
============================================================

# GLO BUSINESS AGENT:

Verify the configured GLO model against the approved source:

[ ] single ticket denomination
[ ] series size
[ ] prize count
[ ] total prize allocation
[ ] unsold-ticket proportional reduction
[ ] claim eligibility
[ ] stamp-duty handling
[ ] tax treatment
[ ] ticket identity
[ ] prize claim lifecycle

Trace:

ticket
→ sale
→ draw
→ result
→ prize match
→ proportional calculation
→ claim
→ approval
→ payout
→ ledger
→ reconciliation

There must be no fixed-prize fallback where proportional calculation is required.

============================================================
# 10. PAYMENT PROVIDERS
============================================================

For EVERY launched provider:

bKash
Nagad
Crypto
Bank Transfer

verify separately:

[ ] production credentials configured
[ ] enabled flag
[ ] supported currency
[ ] deposit capability
[ ] withdrawal capability
[ ] API endpoint correct
[ ] TLS verification active
[ ] timeout policy
[ ] retry policy
[ ] idempotency
[ ] webhook endpoint
[ ] webhook signature verification
[ ] replay protection
[ ] provider reference storage
[ ] reconciliation
[ ] failure reversal
[ ] monitoring
[ ] alerting
[ ] production smoke test

A driver existing in PHP is NOT provider verification.

============================================================
# 11. bKASH REAL-WORLD CHECK
============================================================

app/Services/Payment/Drivers/BkashGateway.php
# PAYMENT AGENT:
# Verify actual provider API path.
# Verify token acquisition.
# Verify request signing/authentication.
# Verify create-payment path.
# Verify callback/webhook path.
# Verify B2C payout path if enabled.
# Verify failed payout does not mark withdrawal completed.
# Verify provider reference is persisted.
# Verify duplicate callback is harmless.
# Use provider sandbox/live only according to actual environment.
# Never fabricate a successful response.

If the real provider cannot be contacted:

NOT VERIFIED — PROVIDER ACCESS REQUIRED

============================================================
# 12. NAGAD REAL-WORLD CHECK
============================================================

app/Services/Payment/Drivers/NagadGateway.php
# PAYMENT AGENT:
# Perform same complete verification as bKash.
# Verify challenge/response where applicable.
# Verify callback authenticity.
# Verify duplicate/replay handling.
# Verify real payout capability before enabling withdrawal.
# Manual-only paths must be explicitly marked manual.

============================================================
# 13. CRYPTO REAL-WORLD CHECK
============================================================

app/Services/Payment/Drivers/CryptoGateway.php
# CRYPTO AGENT:
# Verify whether the provider actually settles transactions.
# An internally generated invoice/reference is NOT blockchain confirmation.
# Verify address/network/amount.
# Verify webhook authenticity.
# Verify required confirmation depth.
# Verify payout/disbursement mechanism.
# Verify reconciliation.
# If provider settlement is not real:
# disable production crypto withdrawal.

============================================================
# 14. BANK TRANSFER
============================================================

app/Services/Payment/Drivers/BankTransferGateway.php
# PAYMENT AGENT:
# Deposit requires real operator settlement details.
# Withdrawal requires canonical beneficiary validation and explicit
# manual/automated state.
# No fabricated bank identity.
# Do not enable the lane until:
# bank name
# account number
# account name
# operational owner
# reconciliation procedure
# are all approved and configured.

============================================================
# 15. WEBHOOK PRODUCTION TEST
============================================================

# SECURITY AGENT:

For every payment provider:

send/receive a test event using the provider's legitimate test mechanism.

Verify:

event
→ signature verification
→ idempotency
→ payment state transition
→ wallet/ledger mutation
→ audit log
→ notification
→ reconciliation

Then replay the exact event.

Expected:

NO SECOND CREDIT
NO SECOND PAYOUT
NO SECOND LEDGER POSTING
NO DUPLICATE FINANCIAL NOTIFICATION

If provider test cannot be executed:

NOT VERIFIED

============================================================
# 16. WALLET PRODUCTION INVARIANTS
============================================================

# WALLET AGENT:

Verify for every currency:

available
+ locked/reserved
+ ledger history
= expected financial state

Test:

[ ] deposit
[ ] bet reservation
[ ] bet settlement
[ ] withdrawal reservation
[ ] withdrawal rejection
[ ] withdrawal completion
[ ] prize credit
[ ] reversal
[ ] duplicate request
[ ] concurrency
[ ] cross-currency isolation

No negative balance.

No duplicate money creation.

No silent correction.

============================================================
# 17. RESPONSIBLE GAMING
============================================================

# RG AGENT:

Verify:

[ ] self-exclusion blocks betting
[ ] deposit limits enforce
[ ] single-bet limits enforce
[ ] daily wagering limits enforce
[ ] increase cooling-off period works
[ ] decrease applies immediately
[ ] API cannot bypass
[ ] browser cannot bypass
[ ] concurrent request cannot bypass

Test boundary dates/times.

============================================================
# 18. AUTH/KYC/COMPLIANCE
============================================================

# SECURITY/COMPLIANCE AGENT:

Verify:

Register
→ Login
→ Email/identity verification
→ KYC
→ Approval
→ Bet
→ Deposit
→ Withdrawal

and negative:

Rejected KYC
→ restricted financial action

Self-excluded
→ restricted action

Suspended account
→ restricted action

Blocked compliance state
→ restricted action

No alternate route may bypass restrictions.

============================================================
# 19. QUEUE / SCHEDULER
============================================================

# OPS AGENT:

Verify:

php artisan queue:work \
--queue=high,default,low \
--tries=3 \
--timeout=90

Verify Supervisor/restart behavior.

Verify scheduler:

* * * * * cd /var/www/thailotto && php artisan schedule:run

Verify:

[ ] jobs execute
[ ] retry works
[ ] duplicate job safe
[ ] failed job visible
[ ] queue backlog monitored
[ ] scheduler heartbeat visible
[ ] financial jobs are not silently failing

============================================================
# 20. CACHE / OPTIMIZATION
============================================================

Run:

php artisan config:cache
php artisan route:cache
php artisan view:cache

Then verify:

[ ] cached config is correct
[ ] production APP_URL is correct
[ ] payment URLs are correct
[ ] legal configuration is correct
[ ] routes exist
[ ] views compile
[ ] no stale environment values remain

============================================================
# 21. STORAGE / KYC
============================================================

Run:

php artisan storage:link

Verify:

[ ] public storage works only for intended assets
[ ] KYC private storage cannot be downloaded directly
[ ] path traversal blocked
[ ] authorization enforced
[ ] logs contain no document contents
[ ] document retention policy active

============================================================
# 22. HEALTH / READINESS
============================================================

Verify:

GET /up
GET /health

Separate:

LIVENESS
READINESS

Readiness must fail when critical dependencies are unavailable.

Check:

database
cache
queue
storage
payment configuration
result freshness
reconciliation health

Do NOT expose:

database credentials
host secrets
provider keys
internal stack traces
private paths

============================================================
# 23. PUBLIC SITE SMOKE TEST
============================================================

Test ALL:

/
about
vision
terms
privacy
fees
discounts
account-verification-guide
account-grades
prize-verification
national-lottery
weekly-lottery
bingo-lottery
pcso-lottery
results
contact
sitemap.xml
robots.txt

For each:

[ ] HTTP status correct
[ ] title correct
[ ] canonical correct
[ ] EN works
[ ] TH works
[ ] no raw translation key
[ ] no fake data
[ ] no fake official claim
[ ] no internal error
[ ] no demo/test ID
[ ] no unsafe legacy copy

============================================================
# 24. PLAYER SMOKE TEST
============================================================

Test:

member-register
member-login
forgot-password
dashboard
draws
draw-detail
bets
wallet
deposit
withdraw
profile
responsible-gaming
verification
payment return
payment cancel
payment failure

Verify both successful and failure paths.

============================================================
# 25. LEGACY URL REDIRECT TEST
============================================================

# SEO/OPS AGENT:

Verify every known legacy `.php` route.

Expected:

301
→ canonical modern URL

Unknown `.php`:

404

Verify:

[ ] no redirect loop
[ ] no redirect to wrong lane
[ ] no redirect to payment callback
[ ] no redirect exposing legacy host
[ ] query strings handled safely
[ ] canonical HTTPS
[ ] canonical hostname

============================================================
# 26. DNS / CDN / TLS CUTOVER
============================================================

Before switching traffic:

[ ] DNS target recorded
[ ] old origin recorded
[ ] new origin recorded
[ ] TTL known
[ ] CDN config verified
[ ] TLS certificate valid
[ ] HSTS verified
[ ] HTTP→HTTPS
[ ] canonical hostname
[ ] IPv4/IPv6 tested
[ ] health checks green

Do NOT switch customer traffic until readiness is verified.

============================================================
# 27. POST-CUTOVER LIVE CRAWL
============================================================

Immediately after cutover:

crawl:

PUBLIC
AUTH
RESULT
PAYMENT RETURN
LEGACY
SITEMAP
ROBOTS

Verify:

[ ] no old `.php` HTML served from new canonical routes
[ ] no old government/legacy identity appears
[ ] no stale result snapshot
[ ] no old member-host redirect
[ ] no payment callback regression
[ ] no 5xx spike
[ ] no queue backlog
[ ] no reconciliation anomaly

============================================================
# 28. REAL CUSTOMER MONEY TEST
============================================================

# PAYMENT/WALLET AGENT:

Use ONLY an approved controlled production test account.

Perform:

Deposit
→ verify provider
→ verify payment
→ verify wallet
→ verify ledger

Place controlled bet
→ verify balance reservation
→ verify bet
→ verify settlement

Controlled withdrawal
→ verify KYC/compliance
→ verify reservation
→ verify provider/manual settlement
→ verify final state
→ verify ledger

Controlled prize/claim test where legally/operationally permitted.

Record transaction references.

Do NOT use fake success.

============================================================
# 29. ROLLBACK TEST
============================================================

Simulate/verify rollback readiness:

1. DNS revert
2. CDN origin revert
3. stop workers
4. preserve logs
5. restore database backup
6. restore previous artifact
7. restart workers
8. clear/rebuild caches
9. verify health
10. verify customer read/write safety

Rollback must have an owner and exact commands.

============================================================
# 30. INCIDENT / POST-MORTEM
============================================================

Collect:

- deployment timestamp
- incident timestamp
- release SHA
- artifact checksum
- affected services
- payment references
- wallet references
- audit correlation IDs
- queue failures
- database errors
- provider responses
- customer impact

Do not log secrets or KYC document contents.

============================================================
# 31. FINAL NO-SKIP TEST ACCOUNTING
============================================================

At final output:

TOTAL CHECKS:
PASS:
FAIL:
SKIPPED:
NOT VERIFIED:
BLOCKED:
OPEN:

A SKIPPED check is NOT PASS.

A NOT VERIFIED check is NOT PASS.

A provider test not executed is NOT PROVIDER VERIFIED.

A DNS step not executed is NOT PRODUCTION VERIFIED.

============================================================
# 32. FINAL STATUS VOCABULARY
============================================================

Use only:

CODE VERIFIED
TEST VERIFIED
RUNTIME VERIFIED
DATA VERIFIED
PROVIDER VERIFIED
PRODUCTION VERIFIED
NOT VERIFIED
BLOCKED
OPEN

Do NOT use:

100% DONE
FULLY READY
PRODUCTION READY

unless ALL production gates are actually VERIFIED.

============================================================
# 33. COMPLETE FILE OUTPUT
============================================================

For every file changed during this prompt:

Provide:

FILE:
<exact path>

STATUS:
<status>

COMPLETE FINAL CONTENT:
<entire file>

No snippets.
No omitted sections.
No placeholder.
No folded content.

============================================================
# 34. FINAL RELEASE GATE
============================================================

Release is allowed ONLY when:

[ ] artifact verified
[ ] dependencies verified
[ ] assets verified
[ ] environment verified
[ ] database backup verified
[ ] migrations verified
[ ] reference data verified
[ ] GLO data verified
[ ] GLO settlement verified
[ ] wallet invariants verified
[ ] payment providers verified
[ ] webhooks verified
[ ] RG verified
[ ] KYC verified
[ ] compliance verified
[ ] queues verified
[ ] scheduler verified
[ ] storage verified
[ ] health verified
[ ] public pages verified
[ ] player pages verified
[ ] legacy redirects verified
[ ] DNS verified
[ ] TLS verified
[ ] post-cutover crawl verified
[ ] rollback verified
[ ] monitoring verified
[ ] no unresolved P0/P1 production blocker
[ ] no hidden skipped tests
[ ] no NOT VERIFIED critical financial step

============================================================
# FINAL INSTRUCTION
============================================================

START BY INSPECTING THE CURRENT DEPLOYMENT STATE.

DO NOT TRUST PREVIOUS COMPLETION REPORTS.

DO NOT SKIP ANY RUNBOOK SECTION.

DO NOT SKIP ANY TEST.

DO NOT FABRICATE PROVIDER RESULTS.

DO NOT FABRICATE GLO DATA.

DO NOT FABRICATE LEGAL DATA.

DO NOT FABRICATE PAYMENT SUCCESS.

DO NOT FABRICATE WALLET BALANCES.

DO NOT USE PLACEHOLDERS.

DO NOT USE:
# ... existing code ...

PRESERVE ALL EXISTING LOGIC.

FIX ACTUAL GAPS.

EXECUTE REAL VERIFICATION WHERE POSSIBLE.

REPORT EXACTLY WHAT WAS VERIFIED.

REPORT EXACTLY WHAT WAS NOT VERIFIED.

STOP RELEASE IF ANY CRITICAL GATE REMAINS OPEN.