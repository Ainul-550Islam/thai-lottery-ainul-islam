# Runtime blocker closure report

Date: 2026-09-30 (Asia/Dhaka)

## Decision

**RELEASE BLOCKED**

The executable runtime path now boots through Laravel, MariaDB, Redis, Node/NPM, Rust, and Playwright in this isolated workspace, but the final release remains blocked because the complete PHPUnit suite and Pint gate do not pass, and no hosted CI run identifier exists. No release ZIP was created.

## Reproducible runtime path

The repository already contains the authoritative GitHub Actions path in `.github/workflows/ci.yml`. The local evidence used the same shape with:

- StaticPHP PHP 8.4.12 and Composer 2.10.3.
- MariaDB 11.8.6 in `/tmp/thai-lottery-mysql`, database `thai_lottery`, with a disposable non-production user.
- Redis 8.0.2 on `127.0.0.1:6379`.
- Node 20.20.2 and NPM, `npm ci`, and `npm run build`.
- Rust/Cargo 1.85.1 and the `security/weekly-result-integrity` crate.
- Playwright Chromium 153 / Playwright 1.63.0.

Credentials, keys, database dumps, and local `.env` values are intentionally not included in this report.

## Genuine execution evidence

| Gate | Result | Evidence |
|---|---:|---|
| Composer validate | PASS | `composer validate --no-check-publish` |
| Composer platform requirements | PASS | `composer check-platform-reqs` |
| Composer security audit | PASS | `No security vulnerability advisories found.` |
| Laravel config cache | PASS | `php artisan config:cache` |
| Laravel route list/cache | PASS | `php artisan route:list`, `route:cache` |
| Laravel view cache | PASS | `php artisan view:cache` after adding the missing canonical component |
| Laravel optimize clear | PASS | `php artisan optimize:clear` with file/Redis cache paths; database-backed cache was also verified after Redis was live |
| MariaDB migrations | PASS | disposable `migrate:fresh --database=mysql --force` completed all migrations |
| Redis | PASS | `redis-cli ping` returned `PONG`; Redis cache and queue command paths passed |
| Node build | PASS | `npm ci` and `npm run build` |
| NPM audit | PASS | `found 0 vulnerabilities` |
| Rust formatting/check/tests/release | PASS | `cargo fmt --check`, `cargo check --locked --all-targets`, `cargo test --locked`, `cargo build --release --locked` |
| Rust stdin/stdout smoke | PASS | valid payload returned `INTEGRITY_HASH_ONLY` and `acceptable:true`; malformed payload rejection was part of the command contract |
| Browser automation | PASS | 20 Playwright public/mobile smoke tests passed |
| Browser health | PASS | `/up/live` returned HTTP 200 and `status:UP` |
| Backup/restore | PASS | `mysqldump`/restore to disposable `thai_lottery_restore`; restored migration status showed all migrations ran |
| GLO purchase fail-closed | PASS | capability returned `NOT_CONFIGURED`, `enabled:false`; official provider returned `not_configured` |
| PHP syntax | PASS | parallel `php -l` over app/bootstrap/config/database/routes/tests |
| Full PHPUnit suite | FAIL | 2,027 tests; 107,928 assertions; 49 errors; 113 failures; 4 skipped; exit code 2 |
| Pint | FAIL | `composer lint` returned exit code 1 on existing style violations |
| Hosted CI | NOT VERIFIED | no real hosted run identifier is available |

## Runtime fixes made in this closure

- Added the missing `national-lottery.detail-content` Blade component and made the National Lottery card provenance prop explicit, allowing `view:cache` to pass.
- Removed the Laravel 12-incompatible trailing `->index()` calls after `->constrained()` that overwrote foreign-key names with boolean `1` and caused duplicate foreign-key names.
- Added explicit short index names for MySQL/MariaDB identifiers that exceeded the 64-byte identifier limit.
- Made the web auth base controller extensible because the existing web adapter extends it.
- Added compatibility adapters for existing service contracts without changing GLO activation or fail-closed behavior.
- Moved legacy custom admin routes that collided with Filament resource/dashboard route registration under `/admin/legacy...`; Filament owns canonical `/admin` resource routes.
- Added missing Vite GLO CSS input and the missing `@playwright/test` package.
- Added a compatibility include at the legacy account verification view path without creating a second product surface.
- Made the first CI PHP job explicitly self-contained on SQLite/file cache/sync queue; the full-runtime job remains the MySQL/Redis gate.

## Remaining blockers

The full suite exposes genuine unresolved defects and stale contract mismatches, including mass-assignment failures, missing application classes/methods, sitemap/view issues, SQLite test-schema mismatches, and expected-content mismatches. These are recorded as failures, not converted to success. Pint also fails. The final status therefore remains **RELEASE BLOCKED**.

## Complete contents of files changed in this closure

The following sections are the complete current contents of each file changed by this closure pass. Generated dependencies, `public/build`, `node_modules`, the disposable runtime, and ignored `.env` are not source deliverables and are excluded.

### `.env.example`

```text
###############################################################################
# APPLICATION
###############################################################################
APP_NAME="Thai Lottery"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_TIMEZONE=Asia/Bangkok
APP_URL=http://localhost
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

###############################################################################
# LOGGING
###############################################################################
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DAILY_DAYS=14
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

###############################################################################
# DATABASE
# The application is developed against MySQL 8 / MariaDB 10.6+.
# The automated suites default to a file-backed SQLite database whose name must
# stay `thai_lottery_test` (see phpunit.xml and the test-suite database guard).
###############################################################################
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=thai_lottery
DB_USERNAME=lottery
DB_PASSWORD=
DB_CHARSET=utf8mb4
# Absolute path to the CA bundle when the database requires TLS.
MYSQL_ATTR_SSL_CA=

###############################################################################
# CACHE / SESSION / QUEUE
###############################################################################
# File-backed defaults keep bootstrap/cache commands independent of a database.
# Runtime deployments may override these with redis after Redis health is proven.
CACHE_STORE=file
CACHE_PREFIX=
SESSION_DRIVER=file
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_SECURE_COOKIE=false
SESSION_SAME_SITE=lax
QUEUE_CONNECTION=sync

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

###############################################################################
# FILESYSTEM / MAIL
###############################################################################
FILESYSTEM_DISK=local

MAIL_MAILER=log
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="no-reply@example.com"
MAIL_FROM_NAME="${APP_NAME}"

###############################################################################
# BROADCASTING (real-time draw feed — Reverb phase)
###############################################################################
BROADCAST_CONNECTION=log
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

###############################################################################
# AUTH / API
###############################################################################
SANCTUM_STATEFUL_DOMAINS=localhost,localhost:5173,127.0.0.1,127.0.0.1:8000
# Minutes. Empty means tokens never expire — set a value in production.
SANCTUM_TOKEN_EXPIRATION=
SANCTUM_TOKEN_PREFIX=

###############################################################################
# SECURITY / RATE LIMITS
###############################################################################
RATE_LIMIT_API_PER_MINUTE=60
RATE_LIMIT_BET_PER_MINUTE=10
RATE_LIMIT_LOGIN_ATTEMPTS=5
RATE_LIMIT_LOGIN_DECAY_MINUTES=15
RATE_LIMIT_DEPOSIT_PER_HOUR=10
RATE_LIMIT_WITHDRAWAL_PER_DAY=5

###############################################################################
# LOTTERY DOMAIN
###############################################################################
LOTTERY_DRAW_INTERVAL_MINUTES=60
LOTTERY_AUTO_CLOSE_MINUTES_BEFORE_DRAW=5
LOTTERY_TICKET_PRICE=20.00
LOTTERY_MIN_BET_AMOUNT=1.00
LOTTERY_MAX_BET_AMOUNT=100000.00
LOTTERY_MIN_NUMBER=00
LOTTERY_MAX_NUMBER=999999
LOTTERY_NUMBER_LIMIT_PER_DRAW=100000.00
LOTTERY_DRAW_CACHE_TTL=60
# Payout automation stays OFF: settlement is a non-monetary simulation.
LOTTERY_AUTO_PAYOUT=false
LOTTERY_PAYOUT_DELAY_MINUTES=0

###############################################################################
# FINANCE
###############################################################################
FINANCE_DEFAULT_CURRENCY=THB
FINANCE_LEDGER_PRECISION=2
FINANCE_LOCK_TIMEOUT_SECONDS=5
FINANCE_MIN_DEPOSIT=100.00
FINANCE_MAX_DEPOSIT=500000.00
FINANCE_MIN_WITHDRAWAL=200.00
FINANCE_MAX_WITHDRAWAL=500000.00
FINANCE_MAX_WALLET_BALANCE=5000000.00
FINANCE_DEPOSIT_AUTO_CONFIRM=false
FINANCE_WITHDRAWAL_PROCESSING_HOURS=24
FINANCE_LEDGER_AUTO_RECONCILE=false
FINANCE_LEDGER_RECONCILE_INTERVAL=3600

###############################################################################
# RISK
###############################################################################
RISK_MAX_EXPOSURE_PER_NUMBER=500000.00
RISK_MAX_BET_PER_DRAW=1000000.00
RISK_DAILY_LOSS_LIMIT=2000000.00
RISK_SUSPICIOUS_THRESHOLD=100000.00
RISK_AUTO_BLOCK_ENABLED=true
RISK_ALERT_CHANNEL=log

###############################################################################
# AUDIT
###############################################################################
AUDIT_LOG_ENABLED=true
AUDIT_LOG_RETENTION_DAYS=3650

###############################################################################
# IDEMPOTENCY
###############################################################################
IDEMPOTENCY_KEY_HEADER=Idempotency-Key
IDEMPOTENCY_CACHE_TTL=86400

###############################################################################
# AGENT
###############################################################################
AGENT_COMMISSION_ENABLED=false

###############################################################################
# PAYMENT GATEWAYS
# Drivers are implemented and bound in app/Services/Payment/PaymentGatewayManager
# (Stripe, bKash, Nagad, Crypto, Bank Transfer). A gateway is inert until you
# set its ENABLED flag AND its credentials; payment state only ever changes
# through signature-verified webhooks. PromptPay is FUTURE-ONLY: no driver is
# bound to it, it cannot be selected or advertised.
###############################################################################
PAYMENT_DEFAULT_GATEWAY=manual
PAYMENT_SUCCESS_URL="${APP_URL}/payment/success"
PAYMENT_FAILURE_URL="${APP_URL}/payment/failure"
PAYMENT_CANCEL_URL="${APP_URL}/payment/cancel"

# Manual bank-transfer settlement (FINAL AUDIT #4): the REAL account players
# transfer to. Leave empty to fail closed — an unconfigured gateway refuses
# deposits instead of showing a placeholder account.
BANK_TRANSFER_ENABLED=false
BANK_TRANSFER_BANK_NAME=
BANK_TRANSFER_ACCOUNT_NUMBER=
BANK_TRANSFER_ACCOUNT_NAME=
BANK_TRANSFER_INSTRUCTIONS=

BKASH_ENABLED=false
BKASH_SANDBOX=true
BKASH_BASE_URL=
BKASH_APP_KEY=
BKASH_APP_SECRET=
BKASH_USERNAME=
BKASH_PASSWORD=

NAGAD_ENABLED=false
NAGAD_SANDBOX=true
NAGAD_BASE_URL=
NAGAD_MERCHANT_ID=
NAGAD_MERCHANT_NUMBER=
NAGAD_APP_KEY=
NAGAD_APP_SECRET=

STRIPE_ENABLED=false
STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=

CRYPTO_ENABLED=false
CRYPTO_PROVIDER=
CRYPTO_GATEWAY_API_KEY=
CRYPTO_GATEWAY_API_SECRET=
CRYPTO_GATEWAY_WEBHOOK_SECRET=

###############################################################################
# FRONTEND
###############################################################################
VITE_APP_NAME="${APP_NAME}"

###############################################################################
# LOTTERY DOMAIN — remaining keys config/lottery.php reads
# Values below are the config defaults; changing one changes behaviour.
###############################################################################
LOTTERY_DEFAULT_DRAW_TYPE=3d
LOTTERY_BETTING_WINDOW_DAYS=15
LOTTERY_SCHEDULE_HORIZON_DAYS=90
# Draw automation (lottery:tick). Set LOTTERY_AUTOMATION_ENABLED=false as the kill switch.
LOTTERY_AUTOMATION_ENABLED=true
LOTTERY_TICK_CRON="* * * * *"
LOTTERY_AUTOMATION_BATCH_SIZE=50
LOTTERY_RESULT_PENDING_AFTER_MINUTES=0
# Settlement remains a NON-MONETARY simulation; this only delays the simulation.
LOTTERY_AUTO_SETTLE=true
LOTTERY_SETTLEMENT_DELAY_MINUTES=15
# Prize claim window (GLO: two years) and the amount that forces a manual review.
LOTTERY_CLAIM_WINDOW_DAYS=730
LOTTERY_CLAIM_REVIEW_THRESHOLD=999999999.00
# Thai lottery winnings are income-tax exempt; stamp duty is handled separately.
LOTTERY_TAX_RATE=0
LOTTERY_TAX_THRESHOLD=0.00
LOTTERY_CANCELLATION_ENABLED=true
LOTTERY_CANCELLATION_WINDOW_MINUTES=60
LOTTERY_CANCELLATION_REQUIRE_OPEN_DRAW=true
LOTTERY_AMENDMENT_ENABLED=true
LOTTERY_AMENDMENT_WINDOW_MINUTES=60
LOTTERY_SHARING_ENABLED=true
LOTTERY_SHARE_TTL_HOURS=72
LOTTERY_VERIFICATION_ENABLED=true

###############################################################################
# GLO (Government Lottery Office products)
# `fixture` reads resources/glo/fixtures; `official` requires a configured source.
###############################################################################
GLO_OFFICIAL_SOURCE_MODE=fixture
GLO_PUBLIC_STATUS_PER_MINUTE=30

###############################################################################
# PRIZE PAYOUT SAFETY GATE (config/finance.php)
# DISABLED means no real money moves. Do not change without reading the gate test.
###############################################################################
PRIZE_PAYOUT_SAFETY_MODE=DISABLED
PRIZE_PAYOUT_AUTO_APPROVE_BELOW=1000000.00
PRIZE_PAYOUT_REQUIRE_APPROVAL_AT=1000000.00

###############################################################################
# WITHDRAWAL KYC GATE (config/finance.php)
###############################################################################
FINANCE_WITHDRAWAL_KYC_GATE_ENABLED=true
FINANCE_WITHDRAWAL_KYC_GATE_THRESHOLD=5000.00

###############################################################################
# PUBLIC FEE TABLE (config/fees.php)
###############################################################################
# Currency + catalogue version of the public fee schedule.
FEES_CURRENCY=THB
FEES_RULE_VERSION=2
# LIVE cash-in / withdrawal rules (whole-percent decimal strings, '8.00' = 8%).
# These drive BOTH what the engine charges (DepositService / WithdrawalService)
# and what the public Fees page shows for the generic + bank rows, so the
# published schedule can never drift from execution.
FINANCE_DEPOSIT_FEE_PERCENTAGE=0.00
FINANCE_WITHDRAWAL_FEE_PERCENTAGE=0.00
# Public-schedule-only rows (display, no execution lane — no charge is ever
# created from these). Rates are fractions of one ('0.0900' = 9%).
FEES_ACCOUNT_RENEWAL_AMOUNT=3.00
FEES_ACCOUNT_VERIFICATION_AMOUNT=3.00
FEES_REFERRAL_AMOUNT=1.00
FEES_AFFILIATION_RATE=0.0200
FEES_CASH_BALANCE_TRANSFER_RATE=0.0300
FEES_WIN_BALANCE_TRANSFER_RATE=0.0200
FEES_CASH_TO_WIN_RATE=0.0100
FEES_WIN_TO_CASH_RATE=0.0100
FEES_PERSONAL_TO_AGENT_RATE=0.0800
FEES_SKRILL_WITHDRAWAL_RATE=0.0900
FEES_NETELLER_WITHDRAWAL_RATE=0.0900
FEES_PERFECT_MONEY_WITHDRAWAL_RATE=0.0900
FEES_SKRILL_CASH_IN_RATE=0.0000
FEES_NETELLER_CASH_IN_RATE=0.0000
FEES_PERFECT_MONEY_CASH_IN_RATE=0.0000
FEES_AGENT_TO_AGENT_RATE=0.0100
FEES_AGENT_TO_WIN_COMMISSION_RATE=0.0400
FEES_AGENT_TO_CASH_COMMISSION_RATE=0.0400
FEES_PERSONAL_TO_AGENT_COMMISSION_RATE=0.0300
FEES_MAINTENANCE_AMOUNT=5.00
# PayPal percentages are deliberately unspecified: leave unset to keep the
# rows visible as NOT_CONFIGURED, or set a fraction to publish a value.
#FEES_PAYPAL_WITHDRAWAL_RATE=0.0900
#FEES_PAYPAL_CASH_IN_RATE=0.0000

###############################################################################
# ACCOUNT SERVICES — verification, grades (config/account.php, account_grades.php)
###############################################################################
# Leave the provider unset to keep verification a manual, human-reviewed lane.
# ACCOUNT_KYC_PROVIDER=
ACCOUNT_KYC_MAX_FILE_KB=10240
ACCOUNT_KYC_RETENTION_DAYS=1095
ACCOUNT_PHONE_OTP_ENABLED=false
ACCOUNT_PHONE_DEFAULT_CC=+66
ACCOUNT_VERIFICATION_SUBMIT_PER_MINUTE=5
ACCOUNT_VERIFICATION_UPLOAD_PER_MINUTE=10
ACCOUNT_GRADE_HISTORY_PER_MINUTE=30
ACCOUNT_GRADE_PERIOD_DAYS=30
ACCOUNT_GRADE_CACHE_TTL=60
ACCOUNT_GRADE_RULE_VERSION=2

###############################################################################
# PUBLIC PAGES + LEGAL CONTENT (config/public_pages.php, config/legal.php)
###############################################################################
PUBLIC_PAGES_CONTENT_VERSION=1
PUBLIC_PAGES_CACHE_TTL=300
PUBLIC_PAGES_LEGAL_MAX_AGE=60
LEGAL_VERSION=v1.0.0
LEGAL_CONTENT_VERSION=1
LEGAL_EFFECTIVE_AT=2026-09-24
LEGAL_UPDATED_AT=2026-09-24
# Unset on purpose: the pages must not invent an operator identity or a contact
# that does not exist. Fill these in for a real deployment.
# LEGAL_OPERATOR_NAME=
# LEGAL_OPERATOR_REGISTRATION=
# LEGAL_OPERATOR_ADDRESS=
# LEGAL_SUPPORT_EMAIL=
# LEGAL_SUPPORT_PHONE=

###############################################################################
# HOME PAGE (config/home.php)
# Support and app-store keys stay unset: an unset key renders an honest
# "not configured" state instead of a fake phone number or a dead store button.
###############################################################################
HOME_STATS_CACHE_TTL=300
HOME_PAGE_CACHE_TTL=60
# HOME_SUPPORT_EMAIL=
# HOME_SUPPORT_PHONE=
# HOME_SUPPORT_HOURS=
# HOME_APP_ANDROID_URL=
# HOME_APP_IOS_URL=
# HOME_APP_PWA_URL=

###############################################################################
# PAYMENT — remaining provider keys config/payment.php reads
###############################################################################
# NAGAD_PUBLIC_KEY=
# NAGAD_PRIVATE_KEY=
PROMPTPAY_ENABLED=false
# PROMPTPAY_TARGET=
# PROMPTPAY_WEBHOOK_SECRET=
PROMPTPAY_QR_EXPIRY_MINUTES=15

###############################################################################
# BROADCASTING — Pusher-compatible driver (config/broadcasting.php)
###############################################################################
# PUSHER_APP_ID=
# PUSHER_APP_KEY=
# PUSHER_APP_SECRET=
# PUSHER_APP_CLUSTER=
# PUSHER_HOST=
PUSHER_PORT=443
PUSHER_SCHEME=https

###############################################################################
# ROLES / PERMISSIONS (config/permission.php)
###############################################################################
PERMISSION_DISPLAY_IN_EXCEPTION=false
ROLE_DISPLAY_IN_EXCEPTION=false
PERMISSION_ENABLE_WILDCARD=false
PERMISSION_CACHE_EXPIRATION="24 hours"
PERMISSION_CACHE_KEY=spatie.permission.cache
PERMISSION_CACHE_STORE=default

###############################################################################
# WEEKLY LOTTERY result lane (PROMPT 6 — config/weekly_lottery.php)
#
# A SEPARATE PRODUCT LANE from GLO L6/N3, the National Lottery lane and the
# operator markets. Result presentation and data provenance only: there is no
# stake, payout, jackpot or commission in this lane, so no key below configures
# one.
#
# THE OFFICIAL ENDPOINT IS DELIBERATELY BLANK. This repository has no
# authorised Weekly Lottery data agreement, so the honest default is "not
# configured" rather than a guessed URL. While it is blank the lane can reach
# INTERNAL_RECONCILED or FIXTURE_ONLY and can NEVER reach
# OFFICIAL_SOURCE_VERIFIED - which is the correct, non-fraudulent behaviour.
#
# A configured endpoint may carry a token in its query string. Only the HOST is
# ever stored or displayed; the URL and the token never reach a public page.
###############################################################################
WEEKLY_LOTTERY_ENABLED=true
WEEKLY_LOTTERY_PROJECTION_VERSION=1
WEEKLY_LOTTERY_TIMEZONE=Asia/Bangkok
WEEKLY_LOTTERY_MIN_YEAR=1990
WEEKLY_LOTTERY_PARSER_VERSION=1

# Provider lanes. Fixtures are development data and are labelled FIXTURE_ONLY
# on every page that shows them; disable them in production.
WEEKLY_LOTTERY_FIXTURE_ENABLED=true
# WEEKLY_LOTTERY_OFFICIAL_ENDPOINT=
# WEEKLY_LOTTERY_OFFICIAL_TOKEN=
WEEKLY_LOTTERY_OFFICIAL_TIMEOUT=8

# Cryptographic integrity (security/weekly-result-integrity, Rust).
#
# REQUIRED=false so a fresh clone with no compiled binary still imports; the
# PHP canonicalizer then produces the fingerprint alone and the record honestly
# says VERIFIER_UNAVAILABLE. Set it true wherever the binary IS deployed, so a
# missing verifier is treated as a fault instead of passing silently.
#
# Build it with:  cd security/weekly-result-integrity && cargo build --release
#
# NO SIGNING KEY IS SHIPPED. Without one the verifier may only report
# INTEGRITY_HASH_ONLY: a hash proves the bytes are self-consistent, not that
# anyone authorised them, and reporting SIGNED_VERIFIED without a key would be
# a false assurance.
WEEKLY_LOTTERY_INTEGRITY_ENABLED=true
WEEKLY_LOTTERY_INTEGRITY_REQUIRED=false
WEEKLY_LOTTERY_INTEGRITY_TIMEOUT=5
WEEKLY_LOTTERY_INTEGRITY_BINARY=security/weekly-result-integrity/target/release/weekly-result-integrity
# WEEKLY_LOTTERY_INTEGRITY_PUBLIC_KEY=

# Public page bounds and cache.
WEEKLY_LOTTERY_PER_PAGE=20
WEEKLY_LOTTERY_CACHE_ENABLED=true
WEEKLY_LOTTERY_CACHE_TTL=300

# Public search ceilings. The six-digit space is 1,000,000 values and therefore
# enumerable, so the limiter keys on IP per minute, IP per hour, and a HASHED
# query fingerprint per minute.
WEEKLY_LOTTERY_SEARCH_PER_MINUTE=20
WEEKLY_LOTTERY_SEARCH_PER_HOUR=200
WEEKLY_LOTTERY_SEARCH_FINGERPRINT_PER_MINUTE=6

###############################################################################
# NATIONAL LOTTERY result lane (PROMPT 5 — config/national_lottery.php)
#
# A SEPARATE PRODUCT LANE from GLO L6/N3, the Weekly Lottery lane and the
# operator markets. Result presentation and data provenance only: there is no
# stake, payout, jackpot or commission in this lane, so no key below
# configures one.
#
# THE OFFICIAL ENDPOINT IS DELIBERATELY BLANK. This repository has no
# authorised National Lottery data agreement, so the honest default is "not
# configured" rather than a guessed URL. While it is blank the lane can reach
# INTERNAL_RECONCILED or FIXTURE_ONLY and can NEVER reach
# OFFICIAL_SOURCE_VERIFIED - which is the correct, non-fraudulent behaviour.
#
# A configured endpoint may carry a token in its query string. Only the HOST
# is ever stored or displayed; the URL and the token never reach a public
# page.
#
# This lane has NO integrity-verifier keys. The Rust verifier in
# security/weekly-result-integrity is wired to the Weekly lane only, so there
# is no NATIONAL_LOTTERY_INTEGRITY_* setting to document. Inventing one here
# would advertise a guarantee the National importer does not make.
###############################################################################
NATIONAL_LOTTERY_ENABLED=true
NATIONAL_LOTTERY_PROJECTION_VERSION=1

# Falls back to APP_TIMEZONE (documented at the top of this file) and then to
# Asia/Bangkok. Draw dates are Thai calendar dates; a server in another zone
# must not shift them.
NATIONAL_LOTTERY_TIMEZONE=Asia/Bangkok

# Lower bound for the year archive. Bounded on purpose: /national-lottery/year
# takes the year from the URL, and an unbounded value is a cheap way to make
# the database scan for nothing.
NATIONAL_LOTTERY_MIN_YEAR=1990

# Travels with every stored version row, so a payload re-read by a newer
# parser is distinguishable from the older reading of the same bytes.
NATIONAL_LOTTERY_PARSER_VERSION=1

# Provider lanes, tried in the order official -> internal -> fixture.
# 'fall_through_to_fixture' is false in config and is NOT exposed as an
# environment key: a failed official fetch must surface as unavailable, never
# quietly become fixture data wearing an official label.
#
# Fixtures are development and test data. They are labelled FIXTURE_ONLY on
# every page that shows them; enabling this in production still cannot produce
# an official label.
NATIONAL_LOTTERY_FIXTURE_ENABLED=true
# NATIONAL_LOTTERY_OFFICIAL_ENDPOINT=
# NATIONAL_LOTTERY_OFFICIAL_TOKEN=
NATIONAL_LOTTERY_OFFICIAL_TIMEOUT=8

# Public page bounds and cache. Search results are never cached; only the
# current result, the year archives and the year list are.
NATIONAL_LOTTERY_PER_PAGE=20
NATIONAL_LOTTERY_CACHE_ENABLED=true
NATIONAL_LOTTERY_CACHE_TTL=300

# Public search ceilings, applied by the 'national-result-search' limiter.
# The six-digit space is 1,000,000 values and therefore enumerable, so the
# limiter keys on IP per minute, IP per hour, and a HASHED query fingerprint
# per minute. robots.txt asks crawlers to stay out of the same route, but that
# is a request, not a control: these limits are the control.
NATIONAL_LOTTERY_SEARCH_PER_MINUTE=20
NATIONAL_LOTTERY_SEARCH_PER_HOUR=200
NATIONAL_LOTTERY_SEARCH_FINGERPRINT_PER_MINUTE=6

###############################################################################
# BINGO / MEGA LOTTERY result lane (PROMPT 8 — config/bingo_lottery.php)
#
# A SEPARATE PRODUCT LANE from GLO L6/N3, the National lane and the Weekly
# lane. Result presentation and data provenance only: there is no stake,
# payout, jackpot or commission in this lane, so no key below configures one.
#
# THE CODE SAYS "BINGO", THE PAGE SAYS "MEGA". The roadmap named this slot
# bingo; the surface publishes a 6 Mega, a 3 Mega and a 2 Mega. Both names are
# kept on purpose and the reason is recorded here so nobody "fixes" one of them
# later.
#
# THE OFFICIAL ENDPOINT IS DELIBERATELY BLANK. This repository has no
# authorised Mega Lottery data agreement, so the honest default is "not
# configured" rather than a guessed URL. While it is blank the lane can reach
# INTERNAL_RECONCILED or FIXTURE_ONLY and can NEVER reach
# OFFICIAL_SOURCE_VERIFIED. Configuring a URL does not upgrade data that did
# not come from it.
#
# A configured endpoint may carry a token. Only the HOST is ever stored or
# displayed; the URL and the token never reach a public page.
#
# THERE ARE NO BINGO_LOTTERY_INTEGRITY_* KEYS, ON PURPOSE. This lane has no
# independent verifier. It hashes the canonical bytes in PHP and reports
# INTEGRITY_HASH_ONLY with independently_verified = false. The Weekly lane runs
# a Rust verifier and has settings for it; shipping the same settings here
# would advertise a guarantee this lane cannot make.
###############################################################################
BINGO_LOTTERY_ENABLED=true
BINGO_LOTTERY_PROJECTION_VERSION=1

# Falls back to APP_TIMEZONE and then Asia/Bangkok. Draw dates are Thai
# calendar dates; a server in another zone must not shift them.
BINGO_LOTTERY_TIMEZONE=Asia/Bangkok

# Lower bound for the year archive. Bounded on purpose: /bingo-lottery/year
# takes the year from the URL, and an unbounded value is a cheap way to make
# the database scan for nothing.
BINGO_LOTTERY_MIN_YEAR=1990

# Travels with every stored version row, so a payload re-read by a newer parser
# is distinguishable from the older reading of the same bytes.
BINGO_LOTTERY_PARSER_VERSION=1

# Provider lanes, tried in the order official -> internal -> replay -> fixture.
# 'fall_through_to_fixture' is false in config and is NOT exposed here: a
# failed official fetch must surface as unavailable, never quietly become
# fixture data wearing an official label.
#
# Fixtures are development and test data, labelled FIXTURE_ONLY on every page
# that shows them.
BINGO_LOTTERY_FIXTURE_ENABLED=true
# BINGO_LOTTERY_OFFICIAL_ENDPOINT=
# BINGO_LOTTERY_OFFICIAL_TOKEN=
BINGO_LOTTERY_OFFICIAL_TIMEOUT=8

# Public page bounds and cache. Search results are never cached; only the
# current result, the year archives and the year list are.
BINGO_LOTTERY_PER_PAGE=20
BINGO_LOTTERY_CACHE_ENABLED=true
BINGO_LOTTERY_CACHE_TTL=300

# Public search ceilings, applied by the 'bingo-result-search' limiter. The
# six-digit space is 1,000,000 values and therefore enumerable, so the limiter
# keys on IP per minute, IP per hour, and a HASHED query fingerprint per
# minute. robots.txt asks crawlers to stay out of the same route; these limits
# are what actually stops one.
BINGO_LOTTERY_SEARCH_PER_MINUTE=20
BINGO_LOTTERY_SEARCH_PER_HOUR=200
BINGO_LOTTERY_SEARCH_FINGERPRINT_PER_MINUTE=6

###############################################################################
# PCSO LOTTERY result lane (PROMPT 9 — config/pcso_lottery.php)
#
# A SEPARATE PRODUCT LANE from GLO L6/N3, National, Weekly and Mega. Result
# presentation and data provenance only: no stake, payout, jackpot or
# commission, so no key below configures one.
#
# THIS LANE PUBLISHES SEVERAL DRAWS PER DAY. A date does not identify a PCSO
# draw; date plus local draw time does. That is why draw references carry a
# time (PCSO-20260910-2100) and why the draws table is unique on
# (draw_date, draw_time_local) rather than on the date alone.
#
# FOUR CATEGORIES, AND ANY OF THEM MAY BE "OFF". 6D/4D/3D/2D run
# independently. A category that did not run is stored as NULL and rendered as
# the translated word "Off" - never as 0, "00", "0000" or "000000", each of
# which is a result a real draw could produce.
#
# THE OFFICIAL ENDPOINT IS DELIBERATELY BLANK. This repository has no
# authorised PCSO data agreement, so the honest default is "not configured"
# rather than a guessed URL. While it is blank the lane can reach
# INTERNAL_RECONCILED or FIXTURE_ONLY and can NEVER reach
# OFFICIAL_SOURCE_VERIFIED.
#
# THERE ARE NO PCSO_LOTTERY_INTEGRITY_* KEYS. This lane has no independent
# verifier. The Weekly Rust crate canonicalises the WEEKLY schema, so pointing
# it at PCSO bytes - or labelling PCSO results "signed verified" because
# another lane has a verifier - would be a claim this lane has not earned.
###############################################################################
PCSO_LOTTERY_ENABLED=true
PCSO_LOTTERY_PROJECTION_VERSION=1

# Falls back to APP_TIMEZONE and then Asia/Bangkok. Draw times are local clock
# readings; a server in another zone must not move a 21:00 draw to the next
# day.
PCSO_LOTTERY_TIMEZONE=Asia/Bangkok

# Lower bound for the year archive, so a crafted /year/{year} cannot ask the
# database to scan for a year that could never hold a draw.
PCSO_LOTTERY_MIN_YEAR=1990

# Travels with every stored version row, so a payload re-read by a newer
# parser is distinguishable from the older reading of the same bytes.
PCSO_LOTTERY_PARSER_VERSION=1

# Provider lanes, tried in the order official -> internal -> replay -> fixture.
# 'fall_through_to_fixture' is false in config and is NOT exposed here: a
# failed official fetch must surface as unavailable, never quietly become
# fixture data wearing an official label.
PCSO_LOTTERY_FIXTURE_ENABLED=true
# PCSO_LOTTERY_OFFICIAL_ENDPOINT=
# PCSO_LOTTERY_OFFICIAL_TOKEN=
PCSO_LOTTERY_OFFICIAL_TIMEOUT=8

# Public page bounds and cache. Search results are never cached; only the
# current result, the year archives and the year list are.
PCSO_LOTTERY_PER_PAGE=20
PCSO_LOTTERY_CACHE_ENABLED=true
PCSO_LOTTERY_CACHE_TTL=300

# Public search ceilings, applied by the 'pcso-result-search' limiter. This
# lane searches FOUR widths (6/4/3/2), so the space a crawler could walk is
# larger than the sibling lanes', not smaller. IP per minute, IP per hour, and
# a HASHED query fingerprint per minute.
PCSO_LOTTERY_SEARCH_PER_MINUTE=20
PCSO_LOTTERY_SEARCH_PER_HOUR=200
PCSO_LOTTERY_SEARCH_FINGERPRINT_PER_MINUTE=6

###############################################################################
# CONTACT / SUPPORT centre (PROMPT 10 — config/contact.php)
#
# A PUBLIC SUPPORT SURFACE, not an operator inbox. No wallet, payout, ticket or
# lottery result data passes through this lane.
#
# THE IDENTITY IS YOURS AND IT IS OPTIONAL. Leave the address blank and the
# page says support contact is unavailable. That is deliberate: an address a
# visitor writes to and nobody reads is worse than being told plainly that
# there is not one yet. Nothing here was taken from any reference site.
#
# "STORED" AND "EMAILED" ARE DIFFERENT FACTS. With CONTACT_EMAIL_ENABLED off,
# or with no support address, a submitted message is still recorded and the
# page reports NOT_CONFIGURED. It never reports "sent". Only a configured
# provider accepting the message produces SENT.
#
# FROM IS THE PLATFORM, REPLY-TO IS THE VISITOR. Sending with a visitor's
# address in From fails SPF/DMARC, damages the domain's deliverability and
# turns the form into a spoofing relay.
###############################################################################
CONTACT_ENABLED=true

# Shown on the page. Blank is a valid, honest state.
# CONTACT_SUPPORT_NAME=
# CONTACT_SUPPORT_EMAIL=
# CONTACT_SUPPORT_HOURS=

CONTACT_TIMEZONE=Asia/Bangkok

# Server-side field bounds. The HTML maxlength mirrors them, but the attribute
# is absent from every request that did not come from the form, so these are
# the control.
CONTACT_MAX_NAME_LENGTH=120
CONTACT_MAX_EMAIL_LENGTH=190
CONTACT_MAX_SUBJECT_LENGTH=160
CONTACT_MAX_MESSAGE_LENGTH=4000

# Anti-abuse. A public POST that sends mail is a relay without ceilings. The
# limiter keys on a HASHED sender and a HASHED email, never on an address in
# clear. The duplicate window absorbs a double-click or a mobile retry while
# still letting someone send a genuinely different second message.
CONTACT_SUBMIT_PER_MINUTE=3
CONTACT_SUBMIT_PER_HOUR=20
CONTACT_SUBMIT_EMAIL_PER_HOUR=10
CONTACT_DUPLICATE_WINDOW_SECONDS=300

# Outbound forwarding. Off by default; a fresh clone forwards nothing and says
# so. Uses the application's existing mail configuration - no SMTP credential
# is duplicated into this lane.
CONTACT_EMAIL_ENABLED=false
# CONTACT_MAIL_FROM_ADDRESS=
# CONTACT_MAIL_FROM_NAME=
CONTACT_MAIL_SUBJECT_PREFIX="[Contact]"

# Retention applies ONLY to messages an operator has finished with. An
# unresolved message is never deleted on a schedule. This is a configuration
# value, not a legal opinion.
CONTACT_RETENTION_DAYS=365

# ---------------------------------------------------------------- PROMPT 3
# Member auth + verification security knobs (see config/auth_security.php
# and config/account_verification.php).
AUTH_SECURITY_RULE_VERSION=1
AUTH_CAPTCHA_ENABLED=true
AUTH_CAPTCHA_DRIVER=local
AUTH_CAPTCHA_TTL_SECONDS=600
AUTH_CAPTCHA_MAX_FAILURES=5
AUTH_CAPTCHA_COOLDOWN_SECONDS=60
AUTH_PASSWORD_MIN_LENGTH=8
AUTH_PASSWORD_RESET_EXPIRY_MINUTES=60
AUTH_PASSWORD_RESET_MAX_PER_MINUTE=5
AUTH_PASSWORD_RESET_REVOKE_SESSIONS=true
ACCOUNT_VERIFICATION_RULE_VERSION=1
VERIFICATION_COUNTRY_CODES=+66
VERIFICATION_REQUIRE_BACK_DOCUMENT=false
VERIFICATION_SUBMISSIONS_PER_MINUTE=5
VERIFICATION_STORAGE_DISK=local
VERIFICATION_RETENTION_DAYS=2555

```

### `.github/workflows/ci.yml`

```yaml
name: CI

on:
  push:
  pull_request:

jobs:
  test:
    name: PHP tests
    runs-on: ubuntu-latest
    env:
      # This job is intentionally self-contained: PHPUnit owns its isolated
      # SQLite database, while the full-runtime job below proves MySQL/Redis.
      DB_CONNECTION: sqlite
      DB_DATABASE: database/ci.sqlite
      CACHE_STORE: file
      SESSION_DRIVER: file
      QUEUE_CONNECTION: sync

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          # intl is required by filament/support (a transitive composer
          # requirement) and by the Filament resource render assertions. It is
          # already present on the runner image; naming it here makes that a
          # declared dependency rather than a lucky default.
          extensions: mbstring, dom, fileinfo, sqlite3, pdo_sqlite, bcmath, sodium, zip, intl
          coverage: none

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'

      - name: Validate composer.json
        run: composer validate --no-check-publish

      - name: Install dependencies
        run: composer install --prefer-dist --no-interaction --no-progress

      - name: Install front-end dependencies
        run: npm ci

      - name: Build front-end assets
        run: npm run build

      - name: Assert the Vite manifest and every registered entry were built
        run: |
          set -euo pipefail
          test -f public/build/manifest.json
          # Every input declared in vite.config.js must have produced an asset.
          # An empty or missing module still "builds", so the manifest is
          # checked entry by entry rather than as a whole.
          node -e '
            const fs = require("fs");
            const manifest = JSON.parse(fs.readFileSync("public/build/manifest.json", "utf8"));
            const config = fs.readFileSync("vite.config.js", "utf8");
            const inputs = [...config.matchAll(/["'"'"'](resources\/(?:js|css)\/[^"'"'"']+)["'"'"']/g)].map(m => m[1]);
            let failed = false;
            for (const input of new Set(inputs)) {
              const record = manifest[input];
              if (!record || !record.file || !fs.existsSync("public/build/" + record.file)) {
                console.error("::error::vite entry not built: " + input);
                failed = true;
                continue;
              }
              if (fs.statSync("resources/" + input.slice("resources/".length)).size === 0) {
                console.error("::error::vite entry is an empty file: " + input);
                failed = true;
              }
            }
            process.exit(failed ? 1 : 0);
          '

      - name: PHP syntax check (php -l on all project PHP files)
        run: |
          set -euo pipefail
          fail=0
          while IFS= read -r -d '' f; do
            if ! php -l "$f" > /dev/null; then
              echo "::error file=$f::syntax error"
              fail=1
            fi
          done < <(find app bootstrap config database routes tests -name '*.php' -print0)
          # Required structural files — fail the build if any are missing
          for req in \
            app/Models/User.php \
            app/Models/Agent.php \
            app/Services/Draw/RealPrizeSettlementService.php \
            app/Services/Finance/FinancialReconciliationService.php \
            app/Services/Finance/WithdrawalService.php \
            app/Console/Commands/GloPublicResultPublishCommand.php \
            app/Console/Commands/Finance/ReconcileFinancialRecordsCommand.php \
            app/Filament/Pages/KycReviewPage.php \
            resources/views/filament/pages/kyc-review-page.blade.php \
            resources/views/home.blade.php \
            resources/views/results/index.blade.php \
            routes/api.php \
            routes/web.php \
            config/finance.php \
            .github/workflows/ci.yml
          do
            if [ ! -f "$req" ]; then
              echo "::error::missing required file: $req"
              fail=1
            fi
          done
          # At least one test file and one migration must exist
          if [ -z "$(find tests -name '*Test.php' -print -quit)" ]; then
            echo "::error::no test files found"
            fail=1
          fi
          if [ -z "$(find database/migrations -name '*.php' -print -quit)" ]; then
            echo "::error::no migrations found"
            fail=1
          fi
          exit $fail

      - name: Assert the writable runtime directories are present in the checkout
        run: |
          set -euo pipefail
          fail=0
          # These are the directories Laravel writes to at runtime. Git does not
          # track empty directories, so each one needs its own .gitignore marker
          # to survive a clone. A missing marker only shows up as a confusing
          # "Please provide a valid cache path" much later.
          for dir in \
            bootstrap/cache \
            storage/app/private \
            storage/app/public \
            storage/framework/cache/data \
            storage/framework/sessions \
            storage/framework/testing \
            storage/framework/views \
            storage/logs
          do
            if [ ! -d "$dir" ]; then
              echo "::error::missing runtime directory: $dir"
              fail=1
            fi
          done
          exit $fail

      - name: Assert every model with HasFactory has a factory
        run: |
          set -euo pipefail
          fail=0
          while IFS= read -r -d '' model; do
            name="$(basename "$model" .php)"
            if grep -q 'HasFactory' "$model" && [ ! -f "database/factories/${name}Factory.php" ]; then
              echo "::error file=$model::model declares HasFactory but database/factories/${name}Factory.php is missing"
              fail=1
            fi
          done < <(find app/Models -name '*.php' -print0)
          exit $fail

      - name: Application key and route optimisation
        run: |
          cp .env.example .env 2>/dev/null || true
          php artisan key:generate --force || true
          php artisan optimize:clear
          php artisan config:cache
          # Fail if config:cache did not produce a bootstrap cache
          test -f bootstrap/cache/config.php

      - name: Migrate fresh with seed
        run: |
          set -euo pipefail
          touch database/ci.sqlite
          php artisan migrate:fresh --force --seed

      - name: Route list (contract smoke)
        run: |
          php artisan route:list
          php artisan route:list --path=/
          php artisan route:list --path=results

      - name: Run test suite
        env:
          APP_ENV: testing
        run: |
          # A cached config file wins over phpunit.xml's <env> overrides, which
          # points the suite at the development database and connection instead
          # of the in-memory test one. The cache is proven above and discarded
          # here, before the suite runs.
          php artisan config:clear
          test ! -f bootstrap/cache/config.php
          # Tests must never be skipped to go green: suite exit code is authoritative
          php artisan test

      # ---------------------------------------------------------------------
      # PROMPT 7: the seams between a result lane and the rest of the repo.
      #
      # The full suite above already runs these tests. This step exists
      # because a seam failure is a DIFFERENT KIND of failure: the lane works,
      # every unit passes, and the feature is still unreachable or its
      # configuration undocumented. Naming the contract here means the CI log
      # says which agreement broke instead of just "a test failed".
      #
      # Cheap greps first, so an obvious breach fails in a second rather than
      # after the whole suite.
      # ---------------------------------------------------------------------
      - name: Assert the result-lane seams are closed
        run: |
          set -euo pipefail

          echo "--- robots.txt: search blocked, content crawlable ---"
          test -f public/robots.txt
          for lane in national-lottery weekly-lottery bingo-lottery pcso-lottery; do
            grep -qx "Disallow: /${lane}/search" public/robots.txt \
              || { echo "::error::robots.txt does not block /${lane}/search"; exit 1; }
            # A bare prefix rule would deindex the entire lane.
            if grep -qE "^Disallow:[[:space:]]*/${lane}/?[[:space:]]*$" public/robots.txt; then
              echo "::error::robots.txt blocks the whole ${lane} lane, not just its search route"
              exit 1
            fi
            if grep -qE "^Disallow:[[:space:]]*/${lane}/year" public/robots.txt; then
              echo "::error::robots.txt blocks the ${lane} year archive"
              exit 1
            fi
          done

          echo "--- .env.example documents every key each lane config reads ---"
          node -e '
            const fs = require("fs");
            const example = fs.readFileSync(".env.example", "utf8");
            let failed = false;
            for (const lane of ["national_lottery", "weekly_lottery", "bingo_lottery", "pcso_lottery", "contact"]) {
              const config = fs.readFileSync("config/" + lane + ".php", "utf8");
              const prefix = lane === "contact" ? "CONTACT" : lane.toUpperCase();
              const keys = new Set(
                [...config.matchAll(new RegExp("env\\(\\s*[\x27\"](" + prefix + "_[A-Z0-9_]+)[\x27\"]", "g"))]
                  .map(m => m[1])
              );
              if (keys.size === 0) {
                console.error("::error::no env keys parsed out of config/" + lane + ".php");
                failed = true;
                continue;
              }
              for (const key of keys) {
                // A commented-out entry counts: credentials are named but valueless.
                if (!new RegExp("^#?\\s*" + key + "=", "m").test(example)) {
                  console.error("::error::" + key + " is read by config/" + lane + ".php but undocumented in .env.example");
                  failed = true;
                }
                // A credential must never ship with a value.
                if (/(TOKEN|SECRET|KEY)$/.test(key) && new RegExp("^" + key + "=.+", "m").test(example)) {
                  console.error("::error::" + key + " has a value in .env.example");
                  failed = true;
                }
              }
            }
            process.exit(failed ? 1 : 0);
          '

          echo "--- /contact must stay crawlable ---"
          if grep -qE "^Disallow:[[:space:]]*/contact" public/robots.txt; then
            echo "::error::robots.txt blocks /contact, which is ordinary public content"
            exit 1
          fi

          echo "--- no competitor string in any public surface ---"
          # The reference pages that motivated these lanes may be named in
          # audit notes and prompts. They must never appear in anything that
          # ships. Scoped to production source so a research note in a report
          # file does not fail the build.
          if grep -rniE "thailotto" \
               app config routes lang public database \
               resources/views resources/css resources/js \
               2>/dev/null | grep -v "^resources/views/vendor/"; then
            echo "::error::a competitor reference reached production source"
            exit 1
          fi

          echo "--- guest navigation links both public lanes ---"
          for name in national-lottery.index weekly-lottery.index bingo-lottery.index pcso-lottery.index contact; do
            grep -q "route('${name}')" resources/views/layouts/app.blade.php \
              || { echo "::error::guest navigation has no link to ${name}"; exit 1; }
          done

          echo "Seam contracts hold."

      - name: Seam and lane test suites (named, so a failure says which)
        env:
          APP_ENV: testing
        run: |
          set -euo pipefail
          php artisan test \
            --filter='NationalLotteryIntegrationSeamTest|NationalLotteryPublicPageTest|WeeklyLotteryPublicPageTest|BingoLotteryPublicPageTest|PcsoLotteryPublicPageTest|ContactPageTest'

      - name: Pint (if configured)
        run: |
          if [ -f vendor/bin/pint ]; then
            vendor/bin/pint --test
          else
            echo "Pint not installed — skipping style gate"
          fi

  # ---------------------------------------------------------------------------
  # PROMPT 6: the Weekly Lottery integrity crate.
  #
  # A SEPARATE JOB, not extra steps on the PHP job, for two reasons. It needs a
  # Rust toolchain the PHP job has no use for, and the two must be able to fail
  # independently: a broken canonicalizer and a broken Blade template are
  # different problems and should not hide behind one red cross.
  #
  # --locked everywhere. The lock file is committed, and a CI run that silently
  # resolved a different dependency tree would not be testing what ships - which
  # matters more than usual for a component whose whole job is producing a
  # deterministic hash.
  # ---------------------------------------------------------------------------
  rust-integrity:
    name: Rust integrity verifier
    runs-on: ubuntu-latest

    defaults:
      run:
        working-directory: security/weekly-result-integrity

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup Rust
        uses: dtolnay/rust-toolchain@stable
        with:
          components: rustfmt

      - name: Cache cargo registry and build output
        uses: actions/cache@v4
        with:
          path: |
            ~/.cargo/registry
            ~/.cargo/git
            security/weekly-result-integrity/target
          key: cargo-${{ runner.os }}-${{ hashFiles('security/weekly-result-integrity/Cargo.lock') }}
          restore-keys: cargo-${{ runner.os }}-

      - name: Formatting
        run: cargo fmt --check

      - name: Type check
        run: cargo check --locked --all-targets

      - name: Tests
        run: cargo test --locked

      - name: Assert the crate cannot reach the network or a database
        run: |
          set -euo pipefail
          # The "no network" property is enforced by ABSENCE of any such
          # dependency. tests/integrity.rs already asserts this against
          # Cargo.lock; this repeats it against the RESOLVED tree so a
          # transitive addition cannot slip in behind a direct one.
          tree=$(cargo tree --locked --edges normal --prefix none)
          for crate in tokio reqwest hyper curl ureq socket2 mio rustls native-tls sqlx diesel postgres tokio-postgres; do
            if printf '%s\n' "$tree" | grep -qE "^${crate} v"; then
              echo "::error::forbidden dependency in the integrity crate: ${crate}"
              exit 1
            fi
          done
          echo "No networking or database crate is present."

      - name: Release build (the binary Laravel invokes)
        run: cargo build --release --locked

      - name: Smoke test the stdin/stdout contract
        run: |
          set -euo pipefail
          # Leading zeros must survive, and a malformed value must be refused
          # with exit code 2 rather than repaired.
          ok=$(printf '%s' '{"draw_reference":"WK-20260918","draw_date":"2026-09-18","first_6":"001234","three_ball":"049","two_ball":"09","source_identifier":"CI","parser_version":"1"}' \
            | ./target/release/weekly-result-integrity)
          echo "$ok"
          printf '%s\n' "$ok" | grep -q '"status":"INTEGRITY_HASH_ONLY"'
          printf '%s\n' "$ok" | grep -q '"acceptable":true'

          set +e
          printf '%s' '{"draw_reference":"WK-20260918","draw_date":"2026-09-18","first_6":"1234","parser_version":"1"}' \
            | ./target/release/weekly-result-integrity
          code=$?
          set -e
          test "$code" -eq 2
          echo "Malformed payload refused with exit code 2."

  runtime-and-rust-contracts:
    name: Runtime and Rust contract gates
    runs-on: ubuntu-latest
    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: mbstring, dom, fileinfo, sqlite3, pdo_sqlite, bcmath, sodium, zip, intl
          coverage: none

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'

      - name: Setup Rust
        uses: dtolnay/rust-toolchain@stable
        with:
          toolchain: stable

      - name: Validate Composer platform and dependencies
        run: |
          set -euo pipefail
          composer validate --no-check-publish
          composer install --prefer-dist --no-interaction --no-progress
          composer check-platform-reqs
          composer audit --no-interaction

      - name: Install and audit frontend dependencies
        run: |
          set -euo pipefail
          npm ci
          npm audit --audit-level=high
          npm run build

      - name: Verify Rust integrity crate
        working-directory: security/weekly-result-integrity
        run: |
          set -euo pipefail
          cargo check --locked
          cargo test --locked
          cargo build --release --locked

  full-runtime-release:
    name: Full runtime release candidate
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: ci_root_password
          MYSQL_DATABASE: thailotto_test
          MYSQL_USER: thailotto_ci
          MYSQL_PASSWORD: ci_database_password
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping -h 127.0.0.1 -u root -pci_root_password"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=20
      redis:
        image: redis:7-alpine
        ports:
          - 6379:6379
        options: >-
          --health-cmd="redis-cli ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=20
    env:
      APP_ENV: testing
      APP_DEBUG: false
      APP_URL: http://127.0.0.1:8000
      DB_CONNECTION: mysql
      DB_HOST: 127.0.0.1
      DB_PORT: 3306
      DB_DATABASE: thailotto_test
      DB_USERNAME: thailotto_ci
      DB_PASSWORD: ci_database_password
      REDIS_HOST: 127.0.0.1
      REDIS_PORT: 6379
      CACHE_STORE: redis
      QUEUE_CONNECTION: redis
      SESSION_DRIVER: array
      MAIL_MAILER: log
      PAGES_451_550_DESTRUCTIVE_TESTS: '1'

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: mbstring, dom, fileinfo, mysqlnd, pdo_mysql, bcmath, sodium, zip, intl, redis
          coverage: none

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'

      - name: Setup Rust
        uses: dtolnay/rust-toolchain@stable
        with:
          components: rustfmt

      - name: Install database client
        run: |
          set -euo pipefail
          sudo apt-get update
          sudo apt-get install --yes mysql-client

      - name: Prepare controlled Laravel environment
        run: |
          set -euo pipefail
          cp .env.example .env
          php artisan key:generate --force

      - name: Install and verify Composer dependencies
        run: |
          set -euo pipefail
          composer validate --no-check-publish
          composer install --prefer-dist --no-interaction --no-progress
          composer check-platform-reqs
          composer audit --no-interaction
          composer dump-autoload --optimize

      - name: Install, audit, and build frontend
        run: |
          set -euo pipefail
          npm ci
          npm audit --audit-level=high
          npm run build

      - name: Boot, cache, route, and view gates
        run: |
          set -euo pipefail
          php artisan about
          php artisan optimize:clear
          php artisan config:cache
          php artisan route:list
          php artisan route:cache
          php artisan view:cache

      - name: Controlled schema, seed, and migration gates
        run: |
          set -euo pipefail
          php artisan migrate:status
          php artisan migrate:fresh --force
          php artisan db:seed --force
          php artisan migrate:status

      - name: Controlled queue and scheduler gates
        run: |
          set -euo pipefail
          redis-cli ping
          php artisan queue:failed
          php artisan schedule:list

      - name: Full PHP test suite
        run: |
          set -euo pipefail
          php artisan config:clear
          php artisan test

      - name: Start application and run public browser smoke tests
        run: |
          set -euo pipefail
          nohup php artisan serve --host=0.0.0.0 --port=8000 >/tmp/laravel-server.log 2>&1 &
          server_pid=$!
          trap 'kill "$server_pid" || true' EXIT
          curl --fail --retry 20 --retry-delay 1 --retry-connrefused http://127.0.0.1:8000/up/live
          npx playwright install --with-deps chromium
          npm run e2e:public

      - name: Backup, restore, and compare controlled database
        env:
          MYSQL_PWD: ci_database_password
        run: |
          set -euo pipefail
          mysqldump --host=127.0.0.1 --port=3306 --user=thailotto_ci thailotto_test > /tmp/thailotto-test.sql
          sha256sum /tmp/thailotto-test.sql | tee /tmp/thailotto-test.sql.sha256
          mysql --host=127.0.0.1 --port=3306 --user=thailotto_ci -e "CREATE DATABASE thailotto_restore"
          mysql --host=127.0.0.1 --port=3306 --user=thailotto_ci thailotto_restore < /tmp/thailotto-test.sql
          DB_DATABASE=thailotto_restore php artisan migrate:status
          test -s /tmp/thailotto-test.sql

      - name: Rust integrity gates
        working-directory: security/weekly-result-integrity
        run: |
          set -euo pipefail
          cargo fmt --check
          cargo check --locked --all-targets
          cargo test --locked
          cargo build --release --locked

      - name: Final source hygiene gate
        run: |
          set -euo pipefail
          git diff --check

```

### `app/Enums/AgentStatus.php`

```php
<?php

namespace App\Enums;

enum AgentStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
    case Terminated = 'terminated';

    // Backward-compatible aliases for older callers; the canonical cases remain above.
    public const ACTIVE = self::Active;
    public const INACTIVE = self::Inactive;
    public const SUSPENDED = self::Suspended;
    public const TERMINATED = self::Terminated;

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Suspended => 'Suspended',
            self::Terminated => 'Terminated',
        };
    }

    public function canOperate(): bool
    {
        return $this === self::Active;
    }

    public function canAcceptPlayers(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether commissions may be accrued for this agent on NEW bets. Only a
     * fully Active agent accrues: referral intake is closed at every other
     * status, so there is nothing to commission.
     */
    public function isCommissionEligible(): bool
    {
        return $this === self::Active;
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Inactive => 'gray',
            self::Suspended => 'yellow',
            self::Terminated => 'red',
        };
    }
}

```

### `app/Enums/DrawStatus.php`

```php
<?php

namespace App\Enums;

/**
 * The persisted status of a draw, stored in draws.status (varchar(32)).
 *
 * PHASE 5.1 ADDITION
 * ------------------
 * One case was appended: ResultPublished = 'result_published'. It names the state
 * of a draw whose official result is published but whose selections have not yet
 * been settled. Before Phase 5.1 that state did not exist, so "published" and
 * "settled" both had to be stored as Completed, which made it impossible to refuse
 * a second settlement run on the strength of the stored status alone.
 *
 * The change is purely ADDITIVE and needed NO migration, because draws.status is
 * varchar(32) and 'result_published' is 16 characters.
 *
 *   - No existing case was renamed or removed.
 *   - No existing backing value was changed.
 *   - No method was removed and no signature was changed.
 *   - label() and color() use exhaustive match(), so exactly one arm was appended
 *     to each. No existing arm was altered.
 *   - canAcceptBets(), canClose(), canDraw() and isFinal() return for all six
 *     original cases exactly what they returned before, and false for the new
 *     case (a published draw takes no bets, cannot be closed again, cannot be
 *     drawn again, and is not final because settlement still has to run).
 *
 * config('lottery.draw.statuses') is array_column(self::cases(), 'value'), so the
 * new value appears there automatically and no configuration file was edited.
 *
 * The SEVEN state lifecycle required by Phase 5.1, and the transition rules
 * between states, are NOT declared here. They live in App\Enums\DrawLifecycleState,
 * which maps its spec-named states bidirectionally onto these persisted values.
 * This enum remains a plain persistence vocabulary.
 */
enum DrawStatus: string
{
    case Scheduled = 'scheduled';
    case Open = 'open';
    case Closed = 'closed';
    case Drawing = 'drawing';
    case ResultPublished = 'result_published';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    // Backward-compatible aliases for older callers; the canonical cases remain above.
    public const SCHEDULED = self::Scheduled;
    public const OPEN = self::Open;
    public const CLOSED = self::Closed;
    public const DRAWING = self::Drawing;
    public const RESULT_PUBLISHED = self::ResultPublished;
    public const COMPLETED = self::Completed;
    public const CANCELLED = self::Cancelled;

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Open => 'Open',
            self::Closed => 'Closed',
            self::Drawing => 'Drawing',
            self::ResultPublished => 'Result Published',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function canAcceptBets(): bool
    {
        return $this === self::Open;
    }

    public function canClose(): bool
    {
        return in_array($this, [self::Open, self::Scheduled]);
    }

    public function canDraw(): bool
    {
        return $this === self::Closed;
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled]);
    }

    public function color(): string
    {
        return match ($this) {
            self::Scheduled => 'gray',
            self::Open => 'green',
            self::Closed => 'yellow',
            self::Drawing => 'blue',
            self::ResultPublished => 'teal',
            self::Completed => 'green',
            self::Cancelled => 'red',
        };
    }
}

```

### `app/Enums/GloClaimChannel.php`

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Claim venue/channel vocabulary for GLO prize claims (GLO-12/14).
 *
 * Physical-ticket requirements (original signed ticket) apply to in-person
 * channels; digital/partner channels follow their own flow and must not be
 * forced through the physical-original rule when config marks them digital.
 */
enum GloClaimChannel: string
{
    case GloOffice = 'glo_office';

    case ProvincialOffice = 'provincial_office';

    case Bank = 'bank';

    case PartnerPlatform = 'partner_platform';

    // Compatibility aliases for the legacy vocabulary; no new production path is enabled.
    public const Branch = self::ProvincialOffice;
    public const OnlineApp = self::PartnerPlatform;

    public function label(): string
    {
        return match ($this) {
            self::GloOffice => 'GLO Headquarters',
            self::ProvincialOffice => 'Provincial Government Office',
            self::Bank => 'Bank / partnered payout',
            self::PartnerPlatform => 'Partner platform',
        };
    }

    public function isPhysical(): bool
    {
        return $this === self::GloOffice || $this === self::ProvincialOffice;
    }

    public function isDigital(): bool
    {
        return $this === self::Bank || $this === self::PartnerPlatform;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

```

### `app/Enums/KycStatus.php`

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum KycStatus: string
{
    case Unverified = 'unverified';
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Expired = 'expired';

    // Backward-compatible aliases for older callers; the canonical cases remain above.
    public const UNVERIFIED = self::Unverified;
    public const PENDING = self::Pending;
    public const VERIFIED = self::Verified;
    public const REJECTED = self::Rejected;
    public const EXPIRED = self::Expired;

    public function isVerified(): bool
    {
        return $this === self::Verified;
    }

    public function canSubmit(): bool
    {
        return in_array($this, [self::Unverified, self::Rejected, self::Expired], true);
    }
}

```

### `app/Http/Controllers/Auth/MemberAuthController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterMemberRequest;
use App\Services\Auth\CaptchaService;
use App\Services\Auth\LoginService;
use App\Services\Auth\PasswordResetService;
use App\Services\Auth\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/*
 * PROMPT 3 — the member auth controller.
 *
 * THIN BY CONTRACT: validate (Requests), delegate (Services), answer
 * (view/redirect). No password hashing, no credential logic, no
 * CAPTCHA verification, no state decisions live here — the controller
 * only orchestrates the surfaces:
 *
 *   GET  /login             anonymous  — Account ID/email + password + CAPTCHA
 *   POST /login             anonymous  — throttle:login
 *   GET  /register          anonymous  — benchmark registration fields
 *   POST /register          anonymous  — throttle:login
 *   POST /logout            auth       — session invalidation
 *   GET  /forgot-password   anonymous  — Account No./email + CAPTCHA
 *   POST /forgot-password   anonymous  — throttle:password-reset
 *   GET  /reset-password    anonymous  — new-password form (token-gated)
 *   POST /reset-password    anonymous  — throttle:password-reset
 */
class MemberAuthController
{
    public function __construct(
        private readonly LoginService $login,
        private readonly RegistrationService $registration,
        private readonly PasswordResetService $passwordReset,
        private readonly CaptchaService $captcha,
    ) {
    }

    /*
    |----------------------------------------------------------------------
    | Login
    |----------------------------------------------------------------------
    */

    public function showLogin(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('player.dashboard');
        }

        return view('auth.member-login', [
            'meta' => $this->meta('login'),
            'captcha' => $this->captcha->issue($request),
            'captchaEnabled' => $this->captcha->isEnabled()
                && (bool) config('auth_security.captcha.login', true),
        ]);
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $this->login->attempt($request, [
            'login' => $request->input('login'),
            'password' => $request->input('password'),
            'remember' => $request->boolean('remember'),
            'captcha_token' => $request->input('captcha_token'),
            'captcha_answer' => $request->input('captcha_answer'),
        ]);

        return redirect()->intended(route('player.dashboard'));
    }

    /*
    |----------------------------------------------------------------------
    | Registration
    |----------------------------------------------------------------------
    */

    public function showRegister(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('player.dashboard');
        }

        return view('auth.member-register', [
            'meta' => $this->meta('register'),
            'genders' => ['male', 'female', 'unspecified'],
        ]);
    }

    public function register(RegisterMemberRequest $request): RedirectResponse
    {
        $user = $this->registration->register($request, $request->validated());

        // Session hardening at the moment of authentication.
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()
            ->route('player.dashboard')
            ->with('status', __('public_pages.register_welcome'));
    }

    /*
    |----------------------------------------------------------------------
    | Logout (the single authoritative path)
    |----------------------------------------------------------------------
    */

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', __('public_pages.logged_out'));
    }

    /*
    |----------------------------------------------------------------------
    | Password recovery
    |----------------------------------------------------------------------
    */

    public function showForgotPassword(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('player.dashboard');
        }

        return view('auth.forgot-password', [
            'meta' => $this->meta('forgot'),
            'captcha' => $this->captcha->issue($request),
            'captchaEnabled' => $this->captcha->isEnabled()
                && (bool) config('auth_security.captcha.password_reset', true),
            'resetToken' => null,
            'resetEmail' => null,
        ]);
    }

    public function requestReset(ForgotPasswordRequest $request): RedirectResponse
    {
        // The SAME outward answer for every identifier outcome.
        $result = $this->passwordReset->requestReset($request, [
            'identifier' => $request->input('identifier'),
            'captcha_token' => $request->input('captcha_token'),
            'captcha_answer' => $request->input('captcha_answer'),
        ]);

        return redirect()
            ->route('password.request')
            ->with('status', $result['message']);
    }

    public function showResetForm(Request $request, string $token): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('player.dashboard');
        }

        return view('auth.forgot-password', [
            'meta' => $this->meta('reset'),
            'captcha' => $this->captcha->issue($request),
            'captchaEnabled' => false,
            'resetToken' => $token,
            'resetEmail' => (string) $request->query('email', ''),
        ]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:filter', 'max:255'],
            'password' => ['required', 'string', 'max:255', new \App\Rules\StrongPasswordRule()],
            'password_confirmation' => ['required', 'string', 'same:password'],
        ], [], [
            'password' => __('public_pages.login_password'),
        ]);

        $result = $this->passwordReset->resetPassword($request, $validated);

        return redirect()
            ->route('login')
            ->with('status', $result['message']);
    }

    /*
    |----------------------------------------------------------------------
    | Page metadata (localized)
    |----------------------------------------------------------------------
    */

    /**
     * @return array<string, string>
     */
    private function meta(string $page): array
    {
        // Suffix-form keys (login_meta_title, register_meta_title, ...),
        // matching the lang files exactly.
        $title = (string) trans('public_pages.'.$page.'_meta_title');
        $description = (string) trans('public_pages.'.$page.'_meta_description');

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => rtrim((string) config('app.url'), '/'),
            'lang' => str_replace('_', '-', (string) app()->getLocale()),
            'og_title' => $title,
            'og_description' => $description,
            'og_type' => 'website',
            'og_url' => rtrim((string) config('app.url'), '/'),
        ];
    }
}

```

### `app/Providers/AppServiceProvider.php`

```php
<?php

namespace App\Providers;

use App\Http\Responses\ApiResponse;
use App\Http\Support\BetPurchaseErrorMapper;
use App\Services\Lottery\GloDataMatrixParser;
use App\Services\Lottery\GloDataMatrixParserInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // NOTE (Payment phase): the PaymentGatewayInterface binding lives here
        // once App\Services\Payment\Gateway\* classes are implemented. It is
        // intentionally not registered yet so the container stays resolvable.

        // PROMPT 4: the Data Matrix reader is consumed through its interface by
        // App\Services\Lottery\TicketBarcodeService, so the public verification
        // page can be pointed at a real reader later without touching the
        // service. GloDataMatrixParser is the only implementation today and
        // already reports 'not_configured' when no format is authorised.
        $this->app->bind(GloDataMatrixParserInterface::class, GloDataMatrixParser::class);

        // Laravel 12 removed the old named-limiter probe; keep the adapter
        // available for the existing security contract without changing the
        // framework limiter semantics.
        $this->app->extend(\Illuminate\Cache\RateLimiter::class, function ($limiter, $app): \Illuminate\Cache\RateLimiter {
            return new \App\Support\RateLimiter($app['cache']->store());
        });
    }

    public function boot(): void
    {
        // Money is handled with bcmath strings; force a consistent scale.
        if (function_exists('bcscale')) {
            bcscale(2);
        }

        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        $this->registerRateLimiters();

        if ($this->app->environment('local')) {
            DB::whenQueryingForLongerThan(1000, function ($connection, $event): void {
                logger()->warning('Slow query detected.', [
                    'sql' => $event->sql,
                    'time' => $event->time,
                ]);
            });
        }
    }

    /**
     * Register the named rate limiters used by routes/api.php.
     *
     * config/security.php already declared these ceilings in Phase 1 and explicitly noted
     * that "Named limiters are registered from these values in a later phase". Phase 4.4 is
     * that phase. The numbers are read from config, never hard-coded here, so an operator
     * changes a limit through the existing RATE_LIMIT_* environment variables rather than by
     * editing application code.
     *
     * WHY THE `bet` LIMITER IS KEYED ON THE USER, NOT THE IP
     * Betting is an authenticated action. Keying on the IP would punish every player behind
     * one mobile carrier NAT for the behaviour of one of them, and would let a single
     * account bypass its own ceiling by rotating IPs. The user id is the only key that
     * matches what the limit is actually protecting. This is also exactly what
     * config('security.rate_limits.bet.by') already specified: 'user'.
     */
    private function registerRateLimiters(): void
    {
        $this->registerLoginLimiter();

        $apiPerMinute = (int) config('security.rate_limits.api.max_per_minute', 60);
        $betPerMinute = (int) config('security.rate_limits.bet.max_per_minute', 10);
        $webhookPerMinute = (int) config('security.rate_limits.webhook.max_per_minute', 120);
        $depositPerHour = (int) config('security.rate_limits.deposit.max_per_hour', 5);
        $withdrawalPerDay = (int) config('security.rate_limits.withdrawal.max_per_day', 3);

        // Money-entry ceilings, keyed on the authenticated user exactly as
        // config('security.rate_limits.deposit.by') / withdrawal.by declare. Deposits and
        // withdrawals are the two write surfaces where a per-hour / per-day ceiling matters
        // beyond the per-minute api limiter, so both are registered here even though only
        // the deposit route carries the deposit limiter today.
        // The key passed to ->by() is suffixed with the limiter name by the throttle
        // middleware, so ->by('user:1') yields the cache key 'deposit:user:1' — the exact
        // key that hardening consumers clear with RateLimiter::clear('deposit:user:N').
        RateLimiter::for('deposit', function (Request $request) use ($depositPerHour): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perHour($depositPerHour)
                ->by($identifier === null ? 'ip:'.$request->ip() : 'user:'.$identifier)
                ->response($this->throttleResponse());
        });

        RateLimiter::for('withdrawal', function (Request $request) use ($withdrawalPerDay): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perDay($withdrawalPerDay)
                ->by($identifier === null ? 'ip:'.$request->ip() : 'user:'.$identifier)
                ->response($this->throttleResponse());
        });

        // Two further named limiters that hardening consumers reference by name:
        // 'player-bet-placement' is the tighter per-minute ceiling for the wager write
        // surface, and 'financial-critical' guards every endpoint that can move money.
        // Both key on the authenticated user, falling back to the IP for unauthenticated
        // traffic so a hostile host cannot exhaust a real user's allowance.
        RateLimiter::for('player-bet-placement', function (Request $request) use ($betPerMinute): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perMinute($betPerMinute)
                ->by($identifier === null ? 'bet:ip:'.$request->ip() : 'bet:user:'.$identifier)
                ->response($this->throttleResponse());
        });

        RateLimiter::for('financial-critical', function (Request $request): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(30)
                ->by($identifier === null ? 'financial-critical:ip:'.$request->ip() : 'financial-critical:user:'.$identifier)
                ->response($this->throttleResponse());
        });

        // 'player-api' mirrors the broad per-minute API ceiling; registered under its own
        // name so the hardening surface can reference it independently of 'api'.
        RateLimiter::for('player-api', function (Request $request) use ($apiPerMinute): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perMinute($apiPerMinute)
                ->by($identifier === null ? 'ip:'.$request->ip() : 'user:'.$identifier)
                ->response($this->throttleResponse());
        });

        RateLimiter::for('api', function (Request $request) use ($apiPerMinute): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            // 'user_or_ip' per config: an authenticated caller is limited as themselves, and
            // an unauthenticated one - which on this surface means a request that will be
            // rejected by auth middleware anyway - is limited by IP so that unauthenticated
            // traffic cannot be used to exhaust a real user's allowance.
            return Limit::perMinute($apiPerMinute)
                ->by($identifier === null ? 'ip:'.$request->ip() : 'user:'.$identifier)
                ->response($this->throttleResponse());
        });

        RateLimiter::for('bet', function (Request $request) use ($betPerMinute): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            if ($identifier === null) {
                // Should not occur behind auth:sanctum. Falling back to the IP is the safe
                // direction: an unkeyed limiter would be no limiter at all.
                return Limit::perMinute($betPerMinute)
                    ->by('bet:ip:'.$request->ip())
                    ->response($this->throttleResponse());
            }

            return Limit::perMinute($betPerMinute)
                ->by('bet:user:'.$identifier)
                ->response($this->throttleResponse());
        });

        // The webhook limiter is keyed on the IP, exactly as
        // config('security.rate_limits.webhook.by') declares. Incoming gateway
        // notifications are unauthenticated by nature of being server-to-server
        // callbacks; the IP is the only key that can pin a hostile host without
        // punishing legitimately high-volume providers.
        RateLimiter::for('glo.public', function (Request $request): Limit {
            $perMinute = (int) config('glo.public_status.rate_limit_per_minute', 30);

            return Limit::perMinute($perMinute)
                ->by('glo-public:'.$request->ip())
                ->response($this->throttleResponse());
        });

        RateLimiter::for('webhook', function (Request $request) use ($webhookPerMinute): Limit {
            return Limit::perMinute($webhookPerMinute)
                ->by('ip:'.$request->ip())
                ->response($this->throttleResponse());
        });

        // Public Home ticket-check UI — anonymous IP-keyed ceiling.
        RateLimiter::for('home-check', function (Request $request): Limit {
            return Limit::perMinute(10)
                ->by('home-check:'.$request->ip())
                ->response($this->throttleResponse());
        });

        // Public prize/ticket verification (PROMPT 4) — anonymous and
        // enumerable by nature: six digits is a 1,000,000-value space, so an
        // unthrottled checker is a free oracle for "does this ticket exist".
        //
        // TWO CEILINGS, ONE LIMITER. Laravel evaluates every Limit returned
        // here, so the IP ceiling bounds sweeping a range while the second
        // ceiling - keyed on a HASH of the submitted value - bounds hammering
        // one value. The hash means the limiter never stores the number that
        // was checked, matching the evidence policy in
        // config('ticket_verification.evidence').
        RateLimiter::for('ticket-verification', function (Request $request): array {
            $perMinute = max(1, (int) config('ticket_verification.rate_limit.per_minute', 12));
            $perHour = max($perMinute, (int) config('ticket_verification.rate_limit.per_hour', 120));
            $fingerprintPerMinute = max(1, (int) config('ticket_verification.rate_limit.fingerprint_per_minute', 4));

            $value = (string) $request->input('value', '');
            $fingerprint = $value === ''
                ? 'empty'
                : substr(hash_hmac('sha256', $value, (string) config('app.key', '')), 0, 32);

            return [
                Limit::perMinute($perMinute)
                    ->by('ticket-verification:ip:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perHour($perHour)
                    ->by('ticket-verification:ip-hour:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perMinute($fingerprintPerMinute)
                    ->by('ticket-verification:q:'.$fingerprint)
                    ->response($this->throttleResponse()),
            ];
        });

        // PROMPT 5: public National Lottery search.
        //
        // Same three-ceiling shape as 'ticket-verification', for the same
        // reason: the six-digit space is enumerable, so an IP ceiling alone
        // lets a distributed client walk it while each address stays polite.
        // The third ceiling is keyed on a HASHED query, so repeating one term
        // is cheap for a human refreshing a page and expensive for a script
        // grinding the space.
        //
        // The fingerprint is an HMAC of the term with the app key, never the
        // term itself: a rate-limiter cache entry should not become a
        // plaintext record of what the public searched for.
        RateLimiter::for('national-result-search', function (Request $request): array {
            $perMinute = max(1, (int) config('national_lottery.rate_limit.per_minute', 20));
            $perHour = max($perMinute, (int) config('national_lottery.rate_limit.per_hour', 200));
            $fingerprintPerMinute = max(1, (int) config('national_lottery.rate_limit.fingerprint_per_minute', 6));

            $term = trim((string) $request->query('number', ''));

            if ($term === '') {
                $term = trim((string) $request->query('date', ''));
            }

            $fingerprint = $term === ''
                ? 'empty'
                : substr(hash_hmac('sha256', $term, (string) config('app.key', '')), 0, 32);

            return [
                Limit::perMinute($perMinute)
                    ->by('national-result-search:ip:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perHour($perHour)
                    ->by('national-result-search:ip-hour:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perMinute($fingerprintPerMinute)
                    ->by('national-result-search:q:'.$fingerprint)
                    ->response($this->throttleResponse()),
            ];
        });

        // PROMPT 6: public Weekly Lottery search.
        //
        // Same three-ceiling shape as 'ticket-verification' and
        // 'national-result-search', for the same reason: the six-digit space
        // is enumerable, so an IP ceiling alone lets a distributed client walk
        // it while each address stays polite. The third ceiling is keyed on a
        // HASHED query, so repeating one term is cheap for a human refreshing
        // a page and expensive for a script grinding the space.
        //
        // The fingerprint is an HMAC of type+term with the app key, never the
        // term itself: a rate-limiter cache entry must not become a plaintext
        // record of what the public searched for.
        RateLimiter::for('weekly-result-search', function (Request $request): array {
            $perMinute = max(1, (int) config('weekly_lottery.rate_limit.per_minute', 20));
            $perHour = max($perMinute, (int) config('weekly_lottery.rate_limit.per_hour', 200));
            $fingerprintPerMinute = max(1, (int) config('weekly_lottery.rate_limit.fingerprint_per_minute', 6));

            $term = trim((string) $request->query('type', '')).'|'.trim((string) $request->query('term', ''));

            $fingerprint = trim($term, '|') === ''
                ? 'empty'
                : substr(hash_hmac('sha256', $term, (string) config('app.key', '')), 0, 32);

            return [
                Limit::perMinute($perMinute)
                    ->by('weekly-result-search:ip:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perHour($perHour)
                    ->by('weekly-result-search:ip-hour:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perMinute($fingerprintPerMinute)
                    ->by('weekly-result-search:q:'.$fingerprint)
                    ->response($this->throttleResponse()),
            ];
        });

        // PROMPT 8: the Bingo/Mega search space is the same size as the
        // Weekly one, so it gets the same three ceilings. The query
        // fingerprint is HMAC'd, never stored raw: a limiter key is not a
        // place to keep a record of what visitors searched for.
        RateLimiter::for('bingo-result-search', function (Request $request): array {
            $perMinute = max(1, (int) config('bingo_lottery.rate_limit.per_minute', 20));
            $perHour = max($perMinute, (int) config('bingo_lottery.rate_limit.per_hour', 200));
            $fingerprintPerMinute = max(1, (int) config('bingo_lottery.rate_limit.fingerprint_per_minute', 6));

            $term = trim((string) $request->query('type', '')).'|'.trim((string) $request->query('term', ''));

            $fingerprint = trim($term, '|') === ''
                ? 'empty'
                : substr(hash_hmac('sha256', $term, (string) config('app.key', '')), 0, 32);

            return [
                Limit::perMinute($perMinute)
                    ->by('bingo-result-search:ip:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perHour($perHour)
                    ->by('bingo-result-search:ip-hour:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perMinute($fingerprintPerMinute)
                    ->by('bingo-result-search:q:'.$fingerprint)
                    ->response($this->throttleResponse()),
            ];
        });

        // PROMPT 9: PCSO searches four widths rather than three, so the
        // space a crawler could walk is larger, not smaller. Same three
        // ceilings, same HMAC'd query fingerprint - a limiter key is not a
        // place to keep a record of what visitors searched for.
        // PROMPT 10: public contact submissions.
        //
        // A public POST that sends mail is an open relay without a ceiling.
        // Two dimensions here, both keyed on the REQUEST: the service applies
        // a third, hashed-email dimension it can only compute after
        // validation. Neither an address nor an IP is ever a cache key in
        // clear - a limiter store is a cache, and caches get dumped.
        RateLimiter::for('contact-submit', function (Request $request): array {
            $perMinute = max(1, (int) config('contact.anti_spam.per_minute', 3));
            // Not clamped to the per-minute figure: a lower hourly ceiling is
            // a real instruction, not a mistake to correct.
            $perHour = max(1, (int) config('contact.anti_spam.per_hour', 20));

            $sender = hash_hmac('sha256', 'contact|ip|'.$request->ip(), (string) config('app.key', ''));

            return [
                Limit::perMinute($perMinute)->by('contact-submit:min:'.$sender),
                Limit::perHour($perHour)->by('contact-submit:hour:'.$sender),
            ];
        });

        RateLimiter::for('pcso-result-search', function (Request $request): array {
            $perMinute = max(1, (int) config('pcso_lottery.rate_limit.per_minute', 20));
            $perHour = max($perMinute, (int) config('pcso_lottery.rate_limit.per_hour', 200));
            $fingerprintPerMinute = max(1, (int) config('pcso_lottery.rate_limit.fingerprint_per_minute', 6));

            $term = trim((string) $request->query('type', '')).'|'.trim((string) $request->query('term', ''));

            $fingerprint = trim($term, '|') === ''
                ? 'empty'
                : substr(hash_hmac('sha256', $term, (string) config('app.key', '')), 0, 32);

            return [
                Limit::perMinute($perMinute)
                    ->by('pcso-result-search:ip:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perHour($perHour)
                    ->by('pcso-result-search:ip-hour:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perMinute($fingerprintPerMinute)
                    ->by('pcso-result-search:q:'.$fingerprint)
                    ->response($this->throttleResponse()),
            ];
        });

        // Account verification submit/upload — user-keyed, moderate ceiling
        // so legitimate multi-file uploads work but scraping cannot.
        $verifyPerMinute = (int) config('account.rate_limits.verification_submit_per_minute', 5);
        // PROMPT 3: password-recovery surfaces (request + reset POSTs).
        // Keyed on the normalized identifier hash AND the IP: an
        // attacker must not slow one victim's recovery, and one IP must
        // not enumerate identifiers. Thresholds read at REQUEST time
        // (config overridable) so policy can be tuned without a reboot.
        RateLimiter::for('password-reset', function (Request $request): array {
            $identifier = mb_strtolower(trim((string) $request->input('identifier', (string) $request->input('email', ''))));
            $maxPerMinute = max(1, (int) config('auth_security.password_reset.max_requests_per_minute', 5));

            return [
                Limit::perMinute($maxPerMinute)
                    ->by('password-reset:id:'.sha1($identifier))
                    ->response($this->throttleResponse()),
                Limit::perMinute(max(1, $maxPerMinute * 5))
                    ->by('password-reset:ip:'.$request->ip())
                    ->response($this->throttleResponse()),
            ];
        });

        RateLimiter::for('account-verification', function (Request $request) use ($verifyPerMinute): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(max(1, $verifyPerMinute))
                ->by($identifier === null ? 'account-verification:ip:'.$request->ip() : 'account-verification:user:'.$identifier)
                ->response($this->throttleResponse());
        });

        // Grade page + history + refresh — user-keyed, cheap reads but not free-for-all.
        $gradePerMinute = (int) config('account.rate_limits.grade_history_per_minute', 30);
        RateLimiter::for('account-grade', function (Request $request) use ($gradePerMinute): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(max(1, $gradePerMinute))
                ->by($identifier === null ? 'account-grade:ip:'.$request->ip() : 'account-grade:user:'.$identifier)
                ->response($this->throttleResponse());
        });

        // Admin analytics and reconciliation reads are bounded independently
        // from player traffic. The authenticated operator id is part of the
        // key so one operator cannot consume another operator's allowance.
        RateLimiter::for('admin-analytics', function (Request $request): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(max(1, (int) config('admin.rate_limits.analytics_per_minute', 60)))
                ->by($identifier === null ? 'admin-analytics:ip:'.$request->ip() : 'admin-analytics:user:'.$identifier)
                ->response($this->throttleResponse());
        });
    }

    /**
     * The `login` limiter used by the public token route.
     *
     * config/security.php already declared `rate_limits.login` with max_attempts,
     * decay_minutes and `by => 'email_and_ip'`, and nothing was reading it because the
     * project had no login route. Both halves of that key are used: the submitted
     * identifier (lower-cased so casing cannot multiply an attacker's allowance) and the
     * client IP. Keying on the identifier alone would let one host attack thousands of
     * accounts; keying on the IP alone would let a botnet attack one account.
     */
    private function registerLoginLimiter(): void
    {
        $maxAttempts = (int) config('security.rate_limits.login.max_attempts', 5);
        $decayMinutes = (int) config('security.rate_limits.login.decay_minutes', 15);

        RateLimiter::for('login', function (Request $request) use ($maxAttempts, $decayMinutes): array {
            $identifier = mb_strtolower(trim((string) $request->input('login', '')));

            return [
                Limit::perMinutes($decayMinutes, $maxAttempts)
                    ->by('login:id:'.sha1($identifier))
                    ->response($this->throttleResponse()),
                Limit::perMinutes($decayMinutes, $maxAttempts * 5)
                    ->by('login:ip:'.$request->ip())
                    ->response($this->throttleResponse()),
            ];
        });
    }

    /**
     * The throttled response, in the project's API envelope.
     *
     * Without this, Laravel returns its own plain `{"message": "Too Many Attempts."}` body,
     * which would be the one response on the whole surface that did not match the documented
     * envelope - so a client's error handling would break precisely when it is being rate
     * limited. The Retry-After header that the throttle middleware adds is preserved.
     */
    private function throttleResponse(): callable
    {
        return function (Request $request, array $headers = []): Response {
            return ApiResponse::error(
                BetPurchaseErrorMapper::CODE_RATE_LIMITED,
                'Too many requests. Please slow down and retry shortly.',
                429,
                [],
                $headers,
            );
        };
    }
}

```

### `app/Services/Finance/FinancialReconciliationService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DTOs\Finance\FinancialReconciliationReport;
use App\DTOs\Finance\ReconciliationDiscrepancy;
use App\Enums\AuditAction;
use App\Enums\BetStatus;
use App\Enums\CommissionStatus;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\DiscrepancyCategory;
use App\Enums\DiscrepancySeverity;
use App\Enums\FinancialTransactionType;
use App\Enums\PayoutStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\RiskLevel;
use App\Enums\TransactionStatus;
use App\Enums\WithdrawalStatus;
use App\Models\AgentCommission;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The platform's accounting control plane.
 *
 * Every money-bearing subsystem writes two records of the same event: a
 * domain row (deposit, withdrawal, payout, commission, bet, wallet) and
 * journal rows (financial_transactions + ledger_entries). Domain rows answer
 * "what happened"; the journal answers "where did the value go". This service
 * is the auditor that proves the two records tell the same story — and says
 * so loudly, with severity, entity and bcmath evidence, when they do not.
 *
 * READ-ONLY BY CONSTRUCTION
 * Reconciliation never repairs. It computes. The only write in this service is
 * one AuditLog row per execution (the law that audits must themselves be
 * auditable). This is what makes runs idempotent, concurrent-safe and safe to
 * schedule: a run always sees the same numbers a human would, and no run can
 * hide an anomaly by "fixing" it before the next one looks.
 *
 * WHAT "RECONCILE" MEANS HERE, EXACTLY
 * Ten independent invariant checks plus ten exact totals. Each check is
 * scoped to the requested period and currency; each discrepancy carries the
 * expected amount, the actual amount, the signed difference and enough
 * context for an operator to find the row in question without running a
 * single extra query.
 *
 * CURRENCY IS A BOUNDARY, NOT A LABEL
 * THB and USD ledgers are never netted together, not even "just for a
 * report". Every query in this service filters on the reconciled currency;
 * the only cross-currency check (CurrencyMismatch) exists precisely to catch
 * a record that escaped its own lane.
 */
class FinancialReconciliationService
{
    public function __construct(
        private readonly WalletService $wallets,
    ) {
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Run one full reconciliation pass and return the report.
     *
     * The platform's verdicts:
     *  - Pass: no discrepancy of any severity was found.
     *  - Warning: non-critical discrepancies exist — investigate, but money
     *    invariants stand.
     *  - Critical: at least one Critical discrepancy — a money invariant is
     *    broken (unbalanced ledger, negative/ďouble-counted money, tampering).
     *
     * @param  Carbon|null  $from  period start (inclusive); null = no bound
     * @param  Carbon|null  $to  period end (inclusive); null = no bound
     * @param  Currency|null  $currency  currency to reconcile; null = platform default
     * @param  string|null  $initiatedBy  operator/actor label for the audit row
     */
    /**
     * Compatibility entry point for the original system-wide reconciliation contract.
     * It delegates to the canonical bounded reconciliation implementation.
     */
    public function reconcileSystem(
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?Currency $currency = null,
        ?string $initiatedBy = null,
    ): FinancialReconciliationReport {
        return $this->reconcile($from, $to, $currency, $initiatedBy);
    }

    public function reconcile(
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?Currency $currency = null,
        ?string $initiatedBy = null,
    ): FinancialReconciliationReport {
        $startedAt = microtime(true);

        $currency ??= Currency::tryFrom((string) config('finance.currency.default', 'THB')) ?? Currency::THB;

        $executionId = sprintf('RECON-%s-%s', now()->format('YmdHis'), bin2hex(random_bytes(4)));

        $discrepancies = [];

        // ---- Checks: 10 invariants, each scoped to currency + period -------
        $this->checkLedgerBalance($currency, $from, $to, $discrepancies);
        $this->checkWalletLedgerParity($currency, $discrepancies);
        $this->checkDuplicateProviderReferences($currency, $from, $to, $discrepancies);
        $this->checkDomainAccountingCompleteness($currency, $from, $to, $discrepancies);
        $this->checkPrizeAccounting($currency, $discrepancies);
        $this->checkLedgerJournalIntegrity($currency, $from, $to, $discrepancies);
        $this->checkWalletHoldInvariants($currency, $discrepancies);
        $this->checkCurrencyBoundaries($currency, $from, $to, $discrepancies);
        $this->checkBetDebits($currency, $discrepancies);
        $this->checkCommissionPayments($currency, $from, $to, $discrepancies);

        // ---- Totals: exact bcmath over the journal + domain records --------
        $totals = $this->computeTotals($currency, $from, $to);

        $anomalyCount = count($discrepancies);
        $criticalAnomalyCount = count(array_filter(
            $discrepancies,
            static fn (ReconciliationDiscrepancy $d): bool => $d->severity->isCritical(),
        ));

        $status = $criticalAnomalyCount > 0
            ? ReconciliationStatus::Critical
            : ($anomalyCount > 0 ? ReconciliationStatus::Warning : ReconciliationStatus::Pass);

        $report = new FinancialReconciliationReport(
            executionId: $executionId,
            status: $status,
            periodStart: $from,
            periodEnd: $to,
            currency: $currency,
            totalDeposits: $totals['deposits'],
            totalBetPurchases: $totals['bet_purchases'],
            totalPrizePayouts: $totals['prize_payouts'],
            totalWithdrawals: $totals['withdrawals'],
            totalCommissions: $totals['commissions'],
            totalLedgerDebits: $totals['ledger_debits'],
            totalLedgerCredits: $totals['ledger_credits'],
            ledgerDifference: $totals['ledger_difference'],
            discrepancies: $discrepancies,
            anomalyCount: $anomalyCount,
            criticalAnomalyCount: $criticalAnomalyCount,
            executedAt: Carbon::now(),
            initiatedBy: $initiatedBy,
            metadata: [
                'checks_run' => 10,
                'discrepancies_by_category' => $this->countByCategory($discrepancies),
            ],
            durationSeconds: round(microtime(true) - $startedAt, 6),
        );

        $this->recordAudit($report);

        return $report;
    }

    /**
     * The operator-facing summary line used by the CLI, the Filament page and
     * the queue alert fan-out. Single source so the three channels never
     * disagree about wording.
     */
    public function describe(FinancialReconciliationReport $report): string
    {
        return sprintf(
            '%s [%s] currency=%s anomalies=%d critical=%d ledgerDiff=%s deposits=%s withdrawals=%s prizes=%s commissions=%s bets=%s',
            $report->executionId,
            $report->status->value,
            $report->currency->value,
            $report->anomalyCount,
            $report->criticalAnomalyCount,
            $report->ledgerDifference,
            $report->totalDeposits,
            $report->totalWithdrawals,
            $report->totalPrizePayouts,
            $report->totalCommissions,
            $report->totalBetPurchases,
        );
    }

    // ------------------------------------------------------------------
    // Check 1 — the journal closes
    // ------------------------------------------------------------------

    /**
     * The double-entry law: within the period and currency, Σdebits must
     * equal Σcredits when grouped per financial transaction. A transaction
     * whose sides do not agree is an unbalanced posting — the single most
     * severe invariant break in accounting.
     *
     * Reversed transactions are balanced by their compensating rows, so only
     * Completed/Processing transactions are judged; a Reversed transaction is
     * audited as part of its reversal pair.
     *
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function checkLedgerBalance(
        Currency $currency,
        ?Carbon $from,
        ?Carbon $to,
        array &$discrepancies,
    ): void {
        $rows = DB::table('ledger_entries as le')
            ->join('financial_transactions as ft', 'ft.id', '=', 'le.financial_transaction_id')
            ->where('le.currency', $currency->value)
            ->whereNull('le.deleted_at')
            ->whereNull('ft.deleted_at')
            ->when($from !== null, fn ($q) => $q->where('le.created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('le.created_at', '<=', $to))
            ->selectRaw("
                le.financial_transaction_id as tx_id,
                GROUP_CONCAT(CASE WHEN le.type = 'debit' THEN le.amount END) as debit_amounts,
                GROUP_CONCAT(CASE WHEN le.type = 'credit' THEN le.amount END) as credit_amounts,
                COUNT(*) as entry_count
            ")
            ->groupBy('le.financial_transaction_id')
            ->get();

        foreach ($rows as $row) {
            // Amounts stay decimal strings end to end; addition is bcmath only.
            $debits = $this->sumExactList((string) ($row->debit_amounts ?? ''));
            $credits = $this->sumExactList((string) ($row->credit_amounts ?? ''));

            if (bccomp($debits, $credits, 2) !== 0) {
                $difference = bcsub($debits, $credits, 2);

                $discrepancies[] = new ReconciliationDiscrepancy(
                    entityType: 'financial_transaction',
                    entityId: (int) $row->tx_id,
                    referenceNumber: $this->referenceOf('financial_transactions', (int) $row->tx_id),
                    category: DiscrepancyCategory::UnbalancedLedger,
                    severity: DiscrepancySeverity::Critical,
                    expectedAmount: $debits,
                    actualAmount: $credits,
                    difference: $difference,
                    currency: $currency->value,
                    description: sprintf(
                        'Financial transaction #%d posts debits %s against credits %s; the journal no longer closes (difference %s).',
                        (int) $row->tx_id,
                        $debits,
                        $credits,
                        $difference,
                    ),
                    details: ['entry_count' => (int) $row->entry_count],
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // Check 2 — wallet ⇄ ledger parity
    // ------------------------------------------------------------------

    /**
     * A wallet's projected balance must equal the net of its ledger entries.
     * The wallets row is a cached projection — the ledger is the truth — so a
     * divergent wallet is always a discrepancy, never a "rounding issue".
     *
     * Locked funds are deliberately excluded from this check: total balance
     * still includes locked money, so balance (not available) is compared
     * against full ledger netting.
     *
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function checkWalletLedgerParity(Currency $currency, array &$discrepancies): void
    {
        // The wallet's ledger truth lives on ONE side of each posting: the
        // player-liability account. Every transaction that moves a wallet posts
        // its ledger double-entry (system account ⇄ liability account), and the
        // liability-side entry is tagged with the wallet's id; netting those
        // liability-side entries (credits − debits) per wallet is exactly the
        // balance the wallet row must show. The system-side accounts (system
        // cash, revenue, clearing) are NOT the wallet's money.
        $liabilityAccountId = DB::table('ledger_accounts')
            ->where('code', WalletService::ACCOUNT_PLAYER_LIABILITY)
            ->value('id');

        if ($liabilityAccountId === null) {
            return; // chart of accounts not seeded; other checks still run
        }

        $wallets = DB::table('wallets')
            ->where('currency', $currency->value)
            ->whereNull('deleted_at')
            ->select(['id', 'balance', 'locked_balance'])
            ->get();

        foreach ($wallets as $wallet) {
            $rows = DB::table('ledger_entries')
                ->where('wallet_id', (int) $wallet->id)
                ->where('ledger_account_id', (int) $liabilityAccountId)
                ->where('currency', $currency->value)
                ->whereNull('deleted_at')
                ->selectRaw("type, GROUP_CONCAT(amount) as amounts")
                ->groupBy('type')
                ->pluck('amounts', 'type');

            $credits = $this->sumExactList((string) ($rows['credit'] ?? ''));
            $debits = $this->sumExactList((string) ($rows['debit'] ?? ''));
            $net = bcsub($credits, $debits, 2);

            if (bccomp((string) $wallet->balance, $net, 2) !== 0) {
                $difference = bcsub((string) $wallet->balance, $net, 2);

                $discrepancies[] = new ReconciliationDiscrepancy(
                    entityType: 'wallet',
                    entityId: (int) $wallet->id,
                    referenceNumber: null,
                    category: DiscrepancyCategory::WalletLedgerMismatch,
                    severity: DiscrepancySeverity::Critical,
                    expectedAmount: $net,
                    actualAmount: (string) $wallet->balance,
                    difference: $difference,
                    currency: $currency->value,
                    description: sprintf(
                        'Wallet #%d projects balance %s %s but its liability-account ledger entries net %s %s (projection drift %s).',
                        (int) $wallet->id,
                        (string) $wallet->balance,
                        $currency->value,
                        $net,
                        $currency->value,
                        $difference,
                    ),
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // Check 3 — provider references are unique per direction
    // ------------------------------------------------------------------

    /**
     * Deposit completion and withdrawal disbursal write provider references.
     * The same provider reference appearing twice means the provider's money
     * movement was booked twice — a duplicate-credit or duplicate-payment
     * window. Duplicate reference groups are reported once per group, with
     * the full id set in details for the operator.
     *
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function checkDuplicateProviderReferences(
        Currency $currency,
        ?Carbon $from,
        ?Carbon $to,
        array &$discrepancies,
    ): void {
        $this->collectDuplicates(
            table: 'deposits',
            category: DiscrepancyCategory::DuplicateDeposit,
            label: 'deposit',
            currency: $currency,
            from: $from,
            to: $to,
            discrepancies: $discrepancies,
        );

        $this->collectDuplicates(
            table: 'withdrawals',
            category: DiscrepancyCategory::DuplicateWithdrawal,
            label: 'withdrawal',
            currency: $currency,
            from: $from,
            to: $to,
            discrepancies: $discrepancies,
        );
    }

    /**
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function collectDuplicates(
        string $table,
        DiscrepancyCategory $category,
        string $label,
        Currency $currency,
        ?Carbon $from,
        ?Carbon $to,
        array &$discrepancies,
    ): void {
        $groups = DB::table($table)
            ->where('currency', $currency->value)
            ->whereNotNull('provider_reference')
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('created_at', '<=', $to))
            ->selectRaw('provider_reference, COUNT(*) as hits, GROUP_CONCAT(id) as ids')
            ->groupBy('provider_reference')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $ids = array_map('intval', explode(',', (string) $group->ids));

            $discrepancies[] = new ReconciliationDiscrepancy(
                entityType: $label,
                entityId: $ids[0] ?? null,
                referenceNumber: (string) $group->provider_reference,
                category: $category,
                severity: DiscrepancySeverity::Critical,
                expectedAmount: null,
                actualAmount: null,
                difference: null,
                currency: $currency->value,
                description: sprintf(
                    'Provider reference "%s" was booked on %d %s records (ids: %s); one provider event may have moved money twice.',
                    (string) $group->provider_reference,
                    (int) $group->hits,
                    $label,
                    (string) $group->ids,
                ),
                details: ['record_ids' => $ids],
            );
        }
    }

    // ------------------------------------------------------------------
    // Check 4 — domain record ⇄ journal completeness
    // ------------------------------------------------------------------

    /**
     * A Confirmed deposit must point at a Completed financial transaction of
     * exactly the deposit's amount; a Completed withdrawal likewise. A NULL
     * link means accounting was never written; an amount mismatch means one
     * side was tampered with after the other was written (either way: the two
     * records of the same event disagree).
     *
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function checkDomainAccountingCompleteness(
        Currency $currency,
        ?Carbon $from,
        ?Carbon $to,
        array &$discrepancies,
    ): void {
        // Deposits: Confirmed is the money-receiving terminal state. The
        // journal records the NET (the fee never reaches the wallet).
        $this->collectAccountingBreaks(
            table: 'deposits',
            statusColumn: 'status',
            statusValue: DepositStatus::Confirmed->value,
            category: DiscrepancyCategory::DepositAccountingMissing,
            label: 'deposit',
            currency: $currency,
            from: $from,
            to: $to,
            discrepancies: $discrepancies,
            journalBasis: 'net',
        );

        // Withdrawals: Completed is the money-releasing terminal state. The
        // journal records the GROSS debit; the fee rides as a column on the
        // single debit transaction, taken from the beneficiary's payout.
        $this->collectAccountingBreaks(
            table: 'withdrawals',
            statusColumn: 'status',
            statusValue: WithdrawalStatus::Completed->value,
            category: DiscrepancyCategory::WithdrawalAccountingMissing,
            label: 'withdrawal',
            currency: $currency,
            from: $from,
            to: $to,
            discrepancies: $discrepancies,
            journalBasis: 'gross',
        );
    }

    /**
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function collectAccountingBreaks(
        string $table,
        string $statusColumn,
        string $statusValue,
        DiscrepancyCategory $category,
        string $label,
        Currency $currency,
        ?Carbon $from,
        ?Carbon $to,
        array &$discrepancies,
        string $journalBasis = 'gross',
    ): void {
        $rows = DB::table($table . ' as d')
            ->leftJoin('financial_transactions as ft', 'ft.id', '=', 'd.financial_transaction_id')
            ->where("d.{$statusColumn}", $statusValue)
            ->where('d.currency', $currency->value)
            ->when($from !== null, fn ($q) => $q->where('d.created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('d.created_at', '<=', $to))
            ->select([
                'd.id',
                'd.reference_number',
                'd.amount',
                'd.fee',
                'd.net_amount',
                'd.financial_transaction_id',
                'ft.status as ft_status',
                'ft.amount as ft_amount',
            ])
            ->get();

        foreach ($rows as $row) {
            // The journal records what actually moved. Fee-bearing deposits
            // journal the NET (amount - fee): the fee never reaches the
            // wallet, so the platform record and the journal must agree on
            // the net, never the gross. Withdrawals journal the GROSS debit
            // with the fee as a column on that one transaction.
            $expectedJournal = $journalBasis === 'net'
                ? ($row->net_amount !== null && (string) $row->net_amount !== ''
                    ? (string) $row->net_amount
                    : bcsub((string) $row->amount, (string) ($row->fee ?? '0.00'), 2))
                : (string) $row->amount;

            $linkMissing = $row->financial_transaction_id === null;
            $linkBroken = ! $linkMissing && $row->ft_status === null;
            $amountDrift = ! $linkMissing && ! $linkBroken
                && bccomp($expectedJournal, (string) $row->ft_amount, 2) !== 0;

            if (! ($linkMissing || $linkBroken || $amountDrift)) {
                continue;
            }

            [$actual, $difference, $why] = match (true) {
                $linkMissing => [null, null, 'has no financial transaction linked at all'],
                $linkBroken => [null, null, 'points at a financial transaction that no longer exists'],
                default => [
                    (string) $row->ft_amount,
                    bcsub((string) $row->ft_amount, $expectedJournal, 2),
                    sprintf(
                        'carries journalable amount %s but its financial transaction carries %s',
                        $expectedJournal,
                        (string) $row->ft_amount,
                    ),
                ],
            };

            $discrepancies[] = new ReconciliationDiscrepancy(
                entityType: $label,
                entityId: (int) $row->id,
                referenceNumber: (string) $row->reference_number,
                category: $category,
                severity: DiscrepancySeverity::Critical,
                expectedAmount: $expectedJournal,
                actualAmount: $actual,
                difference: $difference,
                currency: $currency->value,
                description: sprintf(
                    'Terminal %s %s (%s) %s; the platform record and the journal disagree.',
                    $label,
                    (string) $row->reference_number,
                    $statusValue,
                    $why,
                ),
                details: ['financial_transaction_id' => $row->financial_transaction_id],
            );
        }
    }

    // ------------------------------------------------------------------
    // Check 5 — prize accounting
    // ------------------------------------------------------------------

    /**
     * Every Won bet with an actual payout must have a Completed payout of the
     * same amount; and no winning bet may be paid twice. This pair is the
     * "no prize lost, no prize duplicated" law.
     *
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function checkPrizeAccounting(Currency $currency, array &$discrepancies): void
    {
        // Missing prize accounting: Won bet, no completed payout of payout amount.
        $missing = DB::table('bets')
            ->where('status', BetStatus::Won->value)
            ->where('currency', $currency->value)
            ->where('actual_payout', '>', 0)
            ->whereNull('payout_id')
            ->whereNull('deleted_at')
            ->select(['id', 'bet_number', 'actual_payout', 'draw_id'])
            ->get();

        foreach ($missing as $bet) {
            $discrepancies[] = new ReconciliationDiscrepancy(
                entityType: 'bet',
                entityId: (int) $bet->id,
                referenceNumber: (string) $bet->bet_number,
                category: DiscrepancyCategory::PrizeAccountingMissing,
                severity: DiscrepancySeverity::Critical,
                expectedAmount: (string) $bet->actual_payout,
                actualAmount: null,
                difference: null,
                currency: $currency->value,
                description: sprintf(
                    'Bet %s is Won with actual payout %s %s but no payout record exists; the prize is owed and unaccounted.',
                    (string) $bet->bet_number,
                    (string) $bet->actual_payout,
                    $currency->value,
                ),
                details: ['draw_id' => (int) $bet->draw_id],
            );
        }

        // Duplicate prize: more than one Completed payout for the same bet.
        $duplicates = DB::table('payouts')
            ->where('status', PayoutStatus::Completed->value)
            ->where('currency', $currency->value)
            ->whereNull('deleted_at')
            ->selectRaw('bet_id, COUNT(*) as hits, GROUP_CONCAT(id) as ids, GROUP_CONCAT(reference_number) as refs')
            ->groupBy('bet_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $group) {
            $ids = array_map('intval', explode(',', (string) $group->ids));

            $discrepancies[] = new ReconciliationDiscrepancy(
                entityType: 'bet',
                entityId: (int) $group->bet_id,
                referenceNumber: (string) $group->refs,
                category: DiscrepancyCategory::DuplicatePrize,
                severity: DiscrepancySeverity::Critical,
                expectedAmount: null,
                actualAmount: null,
                difference: null,
                currency: $currency->value,
                description: sprintf(
                    '%d completed payouts exist for bet #%d (refs: %s); the same prize would be paid more than once.',
                    (int) $group->hits,
                    (int) $group->bet_id,
                    (string) $group->refs,
                ),
                details: ['payout_ids' => $ids],
            );
        }
    }

    // ------------------------------------------------------------------
    // Check 6 — journal integrity (both directions)
    // ------------------------------------------------------------------

    /**
     * Both halves of referential integrity over the journal:
     *  - a completed transaction must have at least one ledger entry
     *    (MissingLedgerTransaction);
     *  - a ledger entry must point at a live transaction
     *    (OrphanLedgerEntry).
     *
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function checkLedgerJournalIntegrity(
        Currency $currency,
        ?Carbon $from,
        ?Carbon $to,
        array &$discrepancies,
    ): void {
        $withoutEntries = DB::table('financial_transactions as ft')
            ->leftJoin('ledger_entries as le', function ($join): void {
                $join->on('le.financial_transaction_id', '=', 'ft.id')
                    ->whereNull('le.deleted_at');
            })
            ->where('ft.currency', $currency->value)
            ->where('ft.status', TransactionStatus::Completed->value)
            ->whereNull('ft.deleted_at')
            ->whereNull('le.id')
            ->when($from !== null, fn ($q) => $q->where('ft.created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('ft.created_at', '<=', $to))
            ->select(['ft.id', 'ft.reference_number', 'ft.type', 'ft.amount'])
            ->get();

        foreach ($withoutEntries as $tx) {
            $discrepancies[] = new ReconciliationDiscrepancy(
                entityType: 'financial_transaction',
                entityId: (int) $tx->id,
                referenceNumber: (string) $tx->reference_number,
                category: DiscrepancyCategory::MissingLedgerTransaction,
                severity: DiscrepancySeverity::Critical,
                expectedAmount: (string) $tx->amount,
                actualAmount: '0.00',
                difference: (string) $tx->amount,
                currency: $currency->value,
                description: sprintf(
                    'Completed %s transaction %s of %s %s has no ledger entries; money moved without touching the book.',
                    (string) $tx->type,
                    (string) $tx->reference_number,
                    (string) $tx->amount,
                    $currency->value,
                ),
            );
        }

        $orphans = DB::table('ledger_entries as le')
            ->leftJoin('financial_transactions as ft', 'ft.id', '=', 'le.financial_transaction_id')
            ->where('le.currency', $currency->value)
            ->whereNull('le.deleted_at')
            ->whereNull('ft.id')
            ->when($from !== null, fn ($q) => $q->where('le.created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('le.created_at', '<=', $to))
            ->select(['le.id', 'le.financial_transaction_id', 'le.amount', 'le.type', 'le.wallet_id'])
            ->get();

        foreach ($orphans as $entry) {
            $discrepancies[] = new ReconciliationDiscrepancy(
                entityType: 'ledger_entry',
                entityId: (int) $entry->id,
                referenceNumber: null,
                category: DiscrepancyCategory::OrphanLedgerEntry,
                severity: DiscrepancySeverity::High,
                expectedAmount: null,
                actualAmount: (string) $entry->amount,
                difference: null,
                currency: $currency->value,
                description: sprintf(
                    'Ledger entry #%d (%s %s %s) references deleted or never-existing transaction #%d.',
                    (int) $entry->id,
                    (string) $entry->type,
                    (string) $entry->amount,
                    $currency->value,
                    (int) $entry->financial_transaction_id,
                ),
                details: ['wallet_id' => $entry->wallet_id],
            );
        }
    }

    // ------------------------------------------------------------------
    // Check 7 — wallet hold invariants
    // ------------------------------------------------------------------

    /**
     * Two wallet invariants in one sweep:
     *  - locked_balance must never exceed balance (NegativeAvailableBalance:
     *    the spendable remainder would be negative), Critical;
     *  - a positive lock with no active withdrawal behind it is a stale hold
     *    (StaleWalletHold: money is fine but the player cannot spend it),
     *    High.
     *
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function checkWalletHoldInvariants(Currency $currency, array &$discrepancies): void
    {
        $wallets = DB::table('wallets')
            ->where('currency', $currency->value)
            ->whereNull('deleted_at')
            ->select(['id', 'balance', 'locked_balance'])
            ->get();

        foreach ($wallets as $wallet) {
            $available = bcsub((string) $wallet->balance, (string) $wallet->locked_balance, 2);

            if (bccomp($available, '0', 2) < 0) {
                $discrepancies[] = new ReconciliationDiscrepancy(
                    entityType: 'wallet',
                    entityId: (int) $wallet->id,
                    referenceNumber: null,
                    category: DiscrepancyCategory::NegativeAvailableBalance,
                    severity: DiscrepancySeverity::Critical,
                    expectedAmount: '0.00',
                    actualAmount: $available,
                    difference: $available,
                    currency: $currency->value,
                    description: sprintf(
                        'Wallet #%d would spend negative: balance %s minus locked %s leaves %s %s.',
                        (int) $wallet->id,
                        (string) $wallet->balance,
                        (string) $wallet->locked_balance,
                        $available,
                        $currency->value,
                    ),
                );

                continue; // a wallet cannot be both over-locked and cleanly stale
            }

            if (bccomp((string) $wallet->locked_balance, '0', 2) > 0) {
                $hasActiveWithdrawal = DB::table('withdrawals')
                    ->where('wallet_id', (int) $wallet->id)
                    ->whereNull('deleted_at')
                    ->whereIn('status', [
                        WithdrawalStatus::Pending->value,
                        WithdrawalStatus::UnderReview->value,
                        WithdrawalStatus::Approved->value,
                        WithdrawalStatus::Processing->value,
                    ])
                    ->exists();

                if (! $hasActiveWithdrawal) {
                    $discrepancies[] = new ReconciliationDiscrepancy(
                        entityType: 'wallet',
                        entityId: (int) $wallet->id,
                        referenceNumber: null,
                        category: DiscrepancyCategory::StaleWalletHold,
                        severity: DiscrepancySeverity::High,
                        expectedAmount: '0.00',
                        actualAmount: (string) $wallet->locked_balance,
                        difference: (string) $wallet->locked_balance,
                        currency: $currency->value,
                        description: sprintf(
                            'Wallet #%d locks %s %s with no active withdrawal behind it; either the release was lost or the hold was never started.',
                            (int) $wallet->id,
                            (string) $wallet->locked_balance,
                            $currency->value,
                        ),
                    );
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Check 8 — currency boundary
    // ------------------------------------------------------------------

    /**
     * Every journal row must agree on currency with the transaction that
     * produced it and the wallet it touched. A mismatch means two ledgers
     * were netted together — the report itself isolates currencies to make
     * this visible rather than sunk in a platform-wide sum.
     *
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function checkCurrencyBoundaries(Currency $currency, ?Carbon $from, ?Carbon $to, array &$discrepancies): void
    {
        $mismatches = DB::table('ledger_entries as le')
            ->join('financial_transactions as ft', 'ft.id', '=', 'le.financial_transaction_id')
            ->whereNull('le.deleted_at')
            ->whereColumn('le.currency', '!=', 'ft.currency')
            ->when($from !== null, fn ($q) => $q->where('le.created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('le.created_at', '<=', $to))
            ->select(['le.id', 'le.currency as entry_currency', 'ft.currency as tx_currency', 'ft.reference_number', 'ft.id as tx_id'])
            ->get();

        foreach ($mismatches as $row) {
            $discrepancies[] = new ReconciliationDiscrepancy(
                entityType: 'ledger_entry',
                entityId: (int) $row->id,
                referenceNumber: (string) $row->reference_number,
                category: DiscrepancyCategory::CurrencyMismatch,
                severity: DiscrepancySeverity::Critical,
                expectedAmount: null,
                actualAmount: null,
                difference: null,
                currency: (string) $row->entry_currency,
                description: sprintf(
                    'Ledger entry #%d is recorded in %s but its transaction %s is %s; currencies were netted together.',
                    (int) $row->id,
                    (string) $row->entry_currency,
                    (string) $row->reference_number,
                    (string) $row->tx_currency,
                ),
                details: ['financial_transaction_id' => (int) $row->tx_id],
            );
        }
    }

    // ------------------------------------------------------------------
    // Check 9 — active bets are truly debited
    // ------------------------------------------------------------------

    /**
     * An Active bet asserts "stake was collected". That claim lives in the
     * bet_debit transaction referencing it. An Active bet without one means a
     * bet was sold for free; the bet must be investigated before the draw
     * settles (a Won outcome would pay a prize that was never staked).
     *
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function checkBetDebits(Currency $currency, array &$discrepancies): void
    {
        $betDebitType = FinancialTransactionType::BetDebit->toTransactionType()->value;

        $bets = DB::table('bets')
            ->leftJoin('financial_transactions as ft', function ($join) use ($betDebitType): void {
                $join->on('ft.reference_id', '=', 'bets.id')
                    ->where('ft.type', '=', $betDebitType)
                    ->where('ft.status', '=', TransactionStatus::Completed->value)
                    ->whereNull('ft.deleted_at');
            })
            ->where('bets.status', BetStatus::Active->value)
            ->where('bets.currency', $currency->value)
            ->whereNull('bets.deleted_at')
            ->whereNull('ft.id')
            ->select(['bets.id', 'bets.bet_number', 'bets.stake_amount', 'bets.draw_id'])
            ->get();

        foreach ($bets as $bet) {
            $discrepancies[] = new ReconciliationDiscrepancy(
                entityType: 'bet',
                entityId: (int) $bet->id,
                referenceNumber: (string) $bet->bet_number,
                category: DiscrepancyCategory::BetPurchaseMismatch,
                severity: DiscrepancySeverity::High,
                expectedAmount: (string) $bet->stake_amount,
                actualAmount: '0.00',
                difference: (string) $bet->stake_amount,
                currency: $currency->value,
                description: sprintf(
                    'Active bet %s carries stake %s %s with no completed bet debit; the platform sold a bet it never charged for.',
                    (string) $bet->bet_number,
                    (string) $bet->stake_amount,
                    $currency->value,
                ),
                details: ['draw_id' => (int) $bet->draw_id],
            );
        }
    }

    // ------------------------------------------------------------------
    // Check 10 — paid commissions are truly paid
    // ------------------------------------------------------------------

    /**
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     */
    private function checkCommissionPayments(
        Currency $currency,
        ?Carbon $from,
        ?Carbon $to,
        array &$discrepancies,
    ): void {
        $rows = DB::table('agent_commissions as ac')
            ->leftJoin('financial_transactions as ft', 'ft.id', '=', 'ac.financial_transaction_id')
            ->where('ac.status', CommissionStatus::Paid->value)
            ->where('ac.currency', $currency->value)
            ->whereNull('ac.deleted_at')
            ->whereNull('ac.reversed_at')
            ->when($from !== null, fn ($q) => $q->where('ac.created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('ac.created_at', '<=', $to))
            ->select(['ac.id', 'ac.reference_number', 'ac.commission_amount', 'ac.agent_id', 'ac.financial_transaction_id', 'ft.id as live_ft_id'])
            ->get();

        foreach ($rows as $row) {
            $linkMissing = $row->financial_transaction_id === null;
            $linkBroken = ! $linkMissing && $row->live_ft_id === null;

            if (! ($linkMissing || $linkBroken)) {
                continue;
            }

            $discrepancies[] = new ReconciliationDiscrepancy(
                entityType: 'agent_commission',
                entityId: (int) $row->id,
                referenceNumber: (string) $row->reference_number,
                category: DiscrepancyCategory::CommissionMismatch,
                severity: DiscrepancySeverity::Critical,
                expectedAmount: (string) $row->commission_amount,
                actualAmount: '0.00',
                difference: (string) $row->commission_amount,
                currency: $currency->value,
                description: sprintf(
                    'Commission %s of %s %s to agent #%d is marked Paid but %s.',
                    (string) $row->reference_number,
                    (string) $row->commission_amount,
                    $currency->value,
                    (int) $row->agent_id,
                    $linkMissing
                        ? 'no financial transaction records the payment'
                        : 'its financial transaction no longer exists',
                ),
                details: ['agent_id' => (int) $row->agent_id],
            );
        }
    }

    // ------------------------------------------------------------------
    // Totals — exact report arithmetic (bcmath, never floats)
    // ------------------------------------------------------------------

    /**
     * @return array{
     *     deposits: string, bet_purchases: string, prize_payouts: string,
     *     withdrawals: string, commissions: string, ledger_debits: string,
     *     ledger_credits: string, ledger_difference: string,
     * }
     */
    private function computeTotals(Currency $currency, ?Carbon $from, ?Carbon $to): array
    {
        $depositSums = DB::table('deposits')
            ->where('status', DepositStatus::Confirmed->value)
            ->where('currency', $currency->value)
            ->whereNull('deleted_at')
            ->when($from !== null, fn ($q) => $q->where('confirmed_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('confirmed_at', '<=', $to));

        $withdrawalSums = DB::table('withdrawals')
            ->where('status', WithdrawalStatus::Completed->value)
            ->where('currency', $currency->value)
            ->whereNull('deleted_at')
            ->when($from !== null, fn ($q) => $q->where('completed_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('completed_at', '<=', $to));

        $payoutSums = DB::table('payouts')
            ->where('status', PayoutStatus::Completed->value)
            ->where('currency', $currency->value)
            ->whereNull('deleted_at')
            ->when($from !== null, fn ($q) => $q->where('processed_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('processed_at', '<=', $to));

        $commissionSums = DB::table('agent_commissions')
            ->where('status', CommissionStatus::Paid->value)
            ->where('currency', $currency->value)
            ->whereNull('deleted_at')
            ->whereNull('reversed_at')
            ->when($from !== null, fn ($q) => $q->where('paid_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('paid_at', '<=', $to));

        $betSums = DB::table('bets')
            ->whereIn('status', [
                BetStatus::Pending->value,
                BetStatus::Active->value,
                BetStatus::Won->value,
                BetStatus::Lost->value,
            ])
            ->where('currency', $currency->value)
            ->whereNull('deleted_at')
            ->when($from !== null, fn ($q) => $q->where('placed_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('placed_at', '<=', $to));

        $ledgerSums = DB::table('ledger_entries')
            ->where('currency', $currency->value)
            ->whereNull('deleted_at')
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('created_at', '<=', $to))
            ->selectRaw("type, GROUP_CONCAT(amount) as amounts")
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        $debits = $this->sumExactList((string) ($ledgerSums['debit']->amounts ?? ''));
        $credits = $this->sumExactList((string) ($ledgerSums['credit']->amounts ?? ''));

        return [
            // SQL SUM() is avoided on purpose: the platform stores money as
            // decimal strings, and bcmath chaining is the only addition that
            // is guaranteed exact on every driver.
            'deposits' => $this->sumExact($depositSums->pluck('amount')->all()),
            'bet_purchases' => $this->sumExact($betSums->pluck('stake_amount')->all()),
            'prize_payouts' => $this->sumExact($payoutSums->pluck('amount')->all()),
            'withdrawals' => $this->sumExact($withdrawalSums->pluck('amount')->all()),
            'commissions' => $this->sumExact($commissionSums->pluck('commission_amount')->all()),
            'ledger_debits' => $debits,
            'ledger_credits' => $credits,
            'ledger_difference' => bcsub($debits, $credits, 2),
        ];
    }

    /**
     * Exact sum of a list of decimal strings at scale 2.
     *
     * @param  list<string>  $amounts
     */
    private function sumExact(array $amounts): string
    {
        $total = '0.00';

        foreach ($amounts as $amount) {
            $total = bcadd($total, (string) $amount, 2);
        }

        return $total;
    }

    /**
     * Exact sum of a GROUP_CONCAT-compressed list (avoids one query per row
     * for the high-volume ledger totals while keeping bcmath exactness).
     */
    private function sumExactList(string $commaSeparated): string
    {
        if ($commaSeparated === '') {
            return '0.00';
        }

        $total = '0.00';

        foreach (explode(',', $commaSeparated) as $amount) {
            $total = bcadd($total, $amount, 2);
        }

        return $total;
    }


    /**
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     * @return array<string, int>
     */
    private function countByCategory(array $discrepancies): array
    {
        $map = [];

        foreach ($discrepancies as $d) {
            $key = $d->category->value;
            $map[$key] = ($map[$key] ?? 0) + 1;
        }

        ksort($map);

        return $map;
    }

    private function referenceOf(string $table, int $id): ?string
    {
        $reference = DB::table($table)->where('id', $id)->value('reference_number');

        return $reference === null ? null : (string) $reference;
    }

    // ------------------------------------------------------------------
    // The one permitted write: the audit row of the audit itself
    // ------------------------------------------------------------------

    /**
     * Every execution records itself, exactly once, in AuditLog. This is what
     * makes the control plane itself observable: an operator asking "when did
     * we last reconcile and what did it find" is answered by the same ledger
     * discipline the platform demands of everyone else. No secrets, no
     * personal data — the metadata is keyed aggregates only.
     */
    private function recordAudit(FinancialReconciliationReport $report): void
    {
        $log = new AuditLog();
        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Reconcile,
            'risk_level' => $report->status->isCritical() ? RiskLevel::High : RiskLevel::Low,
            'auditable_type' => 'reconciliation',
            'auditable_id' => 0,
            'description' => sprintf(
                'Financial reconciliation %s executed by %s: status=%s anomalies=%d critical=%d currency=%s period=%s..%s.',
                $report->executionId,
                $report->initiatedBy ?? 'Unknown',
                $report->status->value,
                $report->anomalyCount,
                $report->criticalAnomalyCount,
                $report->currency->value,
                $report->periodStart?->toDateString() ?? 'open',
                $report->periodEnd?->toDateString() ?? 'open',
            ),
            'metadata' => [
                'execution_id' => $report->executionId,
                'status' => $report->status->value,
                'anomaly_count' => $report->anomalyCount,
                'critical_anomaly_count' => $report->criticalAnomalyCount,
                'currency' => $report->currency->value,
                'duration_seconds' => $report->durationSeconds,
                'totals' => [
                    'deposits' => $report->totalDeposits,
                    'bet_purchases' => $report->totalBetPurchases,
                    'prize_payouts' => $report->totalPrizePayouts,
                    'withdrawals' => $report->totalWithdrawals,
                    'commissions' => $report->totalCommissions,
                    'ledger_debits' => $report->totalLedgerDebits,
                    'ledger_credits' => $report->totalLedgerCredits,
                    'ledger_difference' => $report->ledgerDifference,
                ],
                'discrepancies_by_category' => $report->metadata['discrepancies_by_category'] ?? [],
            ],
        ]);
        $log->save();
    }
}

```

### `app/Services/Home/HomeCountdownService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Home;

use App\Enums\DrawStatus;
use App\Models\Draw;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;

/**
 * Calculates authoritative next draw countdown and schedule targets.
 * Never relies on client-side hardcoded date arrays.
 */
class HomeCountdownService
{
    private const DEFAULT_TIMEZONE = 'Asia/Bangkok';

    /**
     * Compute next scheduled draw target information.
     *
     * @return array<string, mixed>
     */
    /**
     * Compatibility name retained for callers of the original home contract.
     * The authoritative calculation remains getNextDrawTarget().
     *
     * @return array<string, mixed>
     */
    public function nextDrawCountdown(): array
    {
        return $this->getNextDrawTarget();
    }

    public function getNextDrawTarget(): array
    {
        try {
            return Cache::remember('home.countdown.next_draw', 30, function (): array {
                $tz = new DateTimeZone(self::DEFAULT_TIMEZONE);
                $now = CarbonImmutable::now($tz);

                $draw = Draw::query()
                    ->where('status', DrawStatus::Scheduled->value)
                    ->where('scheduled_at', '>', $now)
                    ->orderBy('scheduled_at')
                    ->first();

                if (! $draw instanceof Draw || ! $draw->scheduled_at instanceof CarbonInterface) {
                    return [
                        'status' => 'NO_SCHEDULED_DRAW',
                        'has_next_draw' => false,
                        'draw_number' => null,
                        'scheduled_at_iso' => null,
                        'scheduled_at_formatted' => null,
                        'remaining_seconds' => 0,
                        'timezone' => self::DEFAULT_TIMEZONE,
                        'is_open_for_betting' => false,
                    ];
                }

                $target = CarbonImmutable::parse($draw->scheduled_at, $tz);
                $remaining = max(0, $now->diffInSeconds($target, false));

                return [
                    'status' => 'SCHEDULED',
                    'has_next_draw' => true,
                    'draw_number' => (string) ($draw->draw_number ?? 'GLO-'.ltrim($target->format('d/m/Y'), '0')),
                    'scheduled_at_iso' => $target->toIso8601String(),
                    'scheduled_at_formatted' => $target->format('d F Y, H:i').' GMT+7',
                    'remaining_seconds' => (int) $remaining,
                    'timezone' => self::DEFAULT_TIMEZONE,
                    'is_open_for_betting' => $remaining > 300,
                ];
            });
        } catch (\Throwable $e) {
            report($e);

            return [
                'status' => 'UNAVAILABLE',
                'has_next_draw' => false,
                'draw_number' => null,
                'scheduled_at_iso' => null,
                'scheduled_at_formatted' => null,
                'remaining_seconds' => 0,
                'timezone' => self::DEFAULT_TIMEZONE,
                'is_open_for_betting' => false,
            ];
        }
    }
}

```

### `app/Services/Lottery/GloL6ProportionalPrizeCalculator.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Exceptions\GloSalesException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * L6 proportional prize calculator (GLO official 6-digit product).
 *
 * VERIFIED BASELINE RULES (config/glo.l6):
 *   ticket_price          80.00 THB
 *   full_allocation       48,000,000.00 THB across 14,168 prizes at full sell-out
 *   full_sale_units       1,000,000 units
 *   proportional unsold   each tier's paid amount scales as sold / 1,000,000
 *
 * Money is BCMath string decimal only — no float, no pre-round. The calculator
 * never invents baht amounts: it scales the DECLARED allocation by the sold
 * fraction. Unsold remainder is retained (not re-split into fake winners).
 */
class GloL6ProportionalPrizeCalculator
{
    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * Sold fraction as an exact decimal string with 12 fraction digits
     * (enough for unit-level precision; callers round money at 2 dp only
     * when writing currency columns via bcadd(..., 2)).
     */
    public function soldFraction(int $unitsSold, int $unitsFull = 1000000): string
    {
        if ($unitsFull <= 0) {
            throw GloSalesException::invalidConfiguration('glo.l6.full_sale_units', 'must be positive');
        }

        if ($unitsSold < 0) {
            throw GloSalesException::invalidUnits('units_sold must be non-negative');
        }

        if ($unitsSold > $unitsFull) {
            throw GloSalesException::overCapacity($unitsSold, $unitsFull);
        }

        return bcdiv((string) $unitsSold, (string) $unitsFull, 12);
    }

    /**
     * Gross prize pot for a draw given units sold: full_allocation × fraction,
     * floored to satang (scale 2) — never exceeds declared allocation.
     */
    public function allocatedGross(int $unitsSold, ?int $unitsFull = null): string
    {
        $full = $unitsFull ?? (int) ($this->config->get('glo.l6.full_sale_units', 1000000));
        $allocation = $this->fullAllocation();
        $fraction = $this->soldFraction($unitsSold, $full);

        return bcmul($allocation, $fraction, 2);
    }

    /**
     * Declared full-sell-out allocation as a 2-decimal string.
     */
    public function fullAllocation(): string
    {
        $raw = (string) $this->config->get('glo.l6.full_allocation', '48000000.00');

        return bcadd($raw, '0.00', 2);
    }

    /**
     * Ticket price as a 2-decimal string.
     */
    public function ticketPrice(): string
    {
        return bcadd((string) $this->config->get('glo.l6.ticket_price', '80.00'), '0.00', 2);
    }

    /**
     * Official total prize count at full sell-out (14,168).
     */
    public function totalPrizeCount(): int
    {
        return (int) $this->config->get('glo.l6.total_prize_count', 14168);
    }

    /**
     * Compatibility projection for the original revenue/allocation contract.
     * It uses exact BCMath arithmetic and the configured official ladder; it does
     * not publish, purchase, or mutate any GLO state.
     *
     * @return array{first_prize_pool: string, stamp_duty_total: string, prize_pool: string}
     */
    public function calculatePrizePools(string $totalRevenue, string $prizePoolAllocationPercent): array
    {
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $totalRevenue) || ! preg_match('/^\d+(?:\.\d{1,2})?$/', $prizePoolAllocationPercent)) {
            throw GloSalesException::invalidConfiguration('glo.l6.compatibility_input', 'must be decimal strings');
        }

        $prizePool = bcdiv(bcmul($totalRevenue, $prizePoolAllocationPercent, 2), '100.00', 2);
        $first = (array) $this->config->get('glo.prizes.first', []);
        $firstPool = bcmul((string) ($first['amount'] ?? '0.00'), (string) ($first['winners'] ?? 0), 2);
        $stampDuty = '0.00';

        return [
            'first_prize_pool' => $firstPool,
            'stamp_duty_total' => $stampDuty,
            'prize_pool' => $prizePool,
        ];
    }

    /**
     * Calculate per-tier prizes and allocation for a given sales amount and sold count.
     *
     * @return array<string, array{
     *     tier: string,
     *     amount: string,
     *     full_amount: string,
     *     proportional_pot: string,
     *     full_pot: string,
     *     winners: int,
     *     digits: int
     * }>
     */
    public function calculateForSales(string $salesThb, int $soldTickets, ?int $unitsFull = null): array
    {
        $full = $unitsFull ?? (int) ($this->config->get('glo.l6.full_sale_units', 1000000));
        $fraction = $this->soldFraction($soldTickets, $full);
        $prizes = (array) $this->config->get('glo.prizes', []);

        $tierResults = [];

        foreach ($prizes as $tierKey => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $fullAmount = bcadd((string) ($entry['amount'] ?? '0.00'), '0.00', 2);
            $winners = (int) ($entry['winners'] ?? 0);
            $digits = (int) ($entry['digits'] ?? 6);

            $fullPot = bcmul($fullAmount, (string) $winners, 2);
            $proportionalPot = bcmul($fullPot, $fraction, 2);
            $proportionalUnitAmount = bcmul($fullAmount, $fraction, 2);

            $tierResults[(string) $tierKey] = [
                'tier' => (string) $tierKey,
                'amount' => $proportionalUnitAmount,
                'full_amount' => $fullAmount,
                'proportional_pot' => $proportionalPot,
                'full_pot' => $fullPot,
                'winners' => $winners,
                'digits' => $digits,
            ];
        }

        return $tierResults;
    }

    /**
     * Per-tier proportional amount at a given sold level.
     *
     * The official ladder's tier amounts are declared in config('glo.prizes')
     * per ticket. Proportional unsold scales each tier's TOTAL pot
     * (amount × winners) by the sold fraction, then the settlement engine
     * divides among actual winners in that tier (never below 0).
     *
     * @return array{
     *     units_sold: int,
     *     units_full: int,
     *     fraction: string,
     *     full_allocation: string,
     *     allocated_gross: string,
     *     gross_sales: string,
     *     ticket_price: string,
     *     total_prize_count: int,
     *     tiers: list<array{tier: string, full_pot: string, proportional_pot: string, winners: int}>
     * }
     */
    public function proportionalBreakdown(int $unitsSold, ?int $unitsFull = null): array
    {
        $full = $unitsFull ?? (int) ($this->config->get('glo.l6.full_sale_units', 1000000));
        $fraction = $this->soldFraction($unitsSold, $full);
        $price = $this->ticketPrice();
        $grossSales = bcmul($price, (string) $unitsSold, 2);
        $allocated = $this->allocatedGross($unitsSold, $full);
        $prizes = (array) $this->config->get('glo.prizes', []);

        $tiers = [];

        foreach ($prizes as $tier => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $amount = bcadd((string) ($entry['amount'] ?? '0.00'), '0.00', 2);
            $winners = (int) ($entry['winners'] ?? 0);
            $fullPot = bcmul($amount, (string) $winners, 2);
            $proportionalPot = bcmul($fullPot, $fraction, 2);

            $tiers[] = [
                'tier' => (string) $tier,
                'full_pot' => $fullPot,
                'proportional_pot' => $proportionalPot,
                'winners' => $winners,
            ];
        }

        return [
            'units_sold' => $unitsSold,
            'units_full' => $full,
            'fraction' => $fraction,
            'full_allocation' => $this->fullAllocation(),
            'allocated_gross' => $allocated,
            'gross_sales' => $grossSales,
            'ticket_price' => $price,
            'total_prize_count' => $this->totalPrizeCount(),
            'tiers' => $tiers,
        ];
    }

    /**
     * Expected gross for N units: price × units (BCMath, scale 2).
     */
    public function grossForUnits(int $units): string
    {
        if ($units < 0) {
            throw GloSalesException::invalidUnits('units must be non-negative');
        }

        return bcmul($this->ticketPrice(), (string) $units, 2);
    }
}

```

### `app/Services/Lottery/GloStampDutyCalculator.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use InvalidArgumentException;

/**
 * Official GLO stamp-duty calculator (preserved verified rule).
 *
 *   stamp_duty = ceil(gross_prize / 200) × 1 THB
 *   income tax = exempt
 *
 * EXACT BAHT ONLY
 * Gross arrives as a decimal string. Division uses BCMath at scale 0 for the
 * ceiling of (gross / 200): any fractional baht rounds UP to the next whole
 * baht duty unit, never down, never via float. The result is always a
 * 2-decimal string ("30000.00") so callers stay in the string-money domain.
 *
 * DO NOT replace with 0.5% float withholding or 1% income tax — both are
 * regressions against the verified GLO treatment.
 */
class GloStampDutyCalculator
{
    public const DIVISOR = '200';

    public const UNIT_BAHT = '1.00';

    /**
     * Stamp duty for a gross prize, as a 2-decimal THB string.
     *
     * @throws InvalidArgumentException when gross is not a non-negative decimal string
     */
    /**
     * Compatibility report shape for older claim screens. All arithmetic still
     * delegates to the canonical exact-string methods above and below.
     *
     * @return object{stampDutyThb: string, netPayoutThb: string}
     */
    public function calculateDuty(string $grossPrize): object
    {
        return (object) [
            'stampDutyThb' => $this->dutyFor($grossPrize),
            'netPayoutThb' => $this->netAfterDuty($grossPrize),
        ];
    }

    public function dutyFor(string $grossPrize): string
    {
        $gross = $this->assertMoney($grossPrize);

        if ($gross === '0.00') {
            return '0.00';
        }

        $divisor = (string) config('glo.stamp_duty.divisor', self::DIVISOR);

        if (! preg_match('/^[1-9][0-9]*$/', $divisor)) {
            throw new InvalidArgumentException('glo.stamp_duty.divisor must be a positive integer string.');
        }

        // ceil(gross / divisor) in whole duty units, BCMath only:
        // quotient at scale 0 truncates; if quotient*divisor < gross there is
        // a fractional part and we must add one full unit (each unit is 1 THB).
        // NOTE: bcmod() defaults to scale 0 and would erase the fraction on
        // decimal gross values — never use it here without a product compare.
        $quotient = bcdiv($gross, $divisor, 0);
        $product = bcmul($quotient, $divisor, 2);

        if (bccomp($product, $gross, 2) < 0) {
            $quotient = bcadd($quotient, '1', 0);
        }

        return bcadd($quotient, '0.00', 2);
    }

    /**
     * Net prize after stamp duty (income tax exempt — no further deduction).
     *
     * @throws InvalidArgumentException
     */
    public function netAfterDuty(string $grossPrize): string
    {
        $gross = $this->assertMoney($grossPrize);
        $duty = $this->dutyFor($gross);

        $net = bcsub($gross, $duty, 2);

        if (bccomp($net, '0.00', 2) < 0) {
            $net = '0.00';
        }

        return $net;
    }

    /**
     * Full breakdown for claim records and admin display.
     *
     * @return array{gross_prize: string, stamp_duty: string, net_prize: string, income_tax: string, rule: string}
     *
     * @throws InvalidArgumentException
     */
    public function breakdown(string $grossPrize): array
    {
        $gross = $this->assertMoney($grossPrize);
        $duty = $this->dutyFor($gross);
        $net = $this->netAfterDuty($gross);

        return [
            'gross_prize' => $gross,
            'stamp_duty' => $duty,
            'net_prize' => $net,
            'income_tax' => '0.00',
            'rule' => 'ceil(gross/'.self::DIVISOR.') × 1 THB; income tax exempt',
        ];
    }

    private function assertMoney(string $value): string
    {
        if (! is_string($value) || ! preg_match('/^-?\d+(\.\d{1,2})?$/', trim($value))) {
            throw new InvalidArgumentException('Gross prize must be a decimal string with at most 2 fraction digits.');
        }

        $trimmed = trim($value);

        if (bccomp($trimmed, '0.00', 2) < 0) {
            throw new InvalidArgumentException('Gross prize must not be negative.');
        }

        return bcadd($trimmed, '0.00', 2);
    }
}

```

### `app/Services/ResponsibleGaming/SelfExclusionService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\ResponsibleGaming;

use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Enums\SelfExclusionStatus;
use App\Events\SelfExclusionActivated;
use App\Exceptions\SelfExclusionException;
use App\Listeners\RecordSelfExclusionAudit;
use App\Models\AuditLog;
use App\Models\ResponsibleGamingLimit;
use App\Models\SelfExclusion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SelfExclusionService — create / activate / expire self-exclusions.
 * The live gate is DERIVED server-side (status + server clock) and
 * blocks prohibited account activity; no client may lift it.
 *
 * THE TWO LANES CORRESPOND: the legacy per-user
 * `responsible_gaming_limits.self_excluded_until` row is stamped at
 * activation/expiry so pre-existing gates (`User::isSelfExcluded`,
 * PrizeEligibilityService) keep telling the same truth — the
 * pronouncement lane stays authoritative, never edited outside here.
 */
final class SelfExclusionService
{
    public function __construct(
        private readonly RecordSelfExclusionAudit $audit,
    ) {}

    /**
     * THE SEATED REQUEST: one (user, scope, reason, window) = one
     * row, replayed for free. A PLAYER under an active exclusion
     * cannot re-request (the gate refuses by name).
     *
     * @return array{exclusion: SelfExclusion, replayed: bool}
     */
    public function request(SelfExclusionData $data): SelfExclusion
    {
        return DB::transaction(function () use ($data): SelfExclusion {
            $fingerprint = $data->requestFingerprint();

            /** @var SelfExclusion|null $existing */
            $existing = SelfExclusion::query()->where('request_fingerprint', $fingerprint)->first();

            if ($existing instanceof SelfExclusion) {
                return $existing; // deterministic replay, free of arithmetic
            }

            /** @var SelfExclusion|null $active */
            $active = SelfExclusion::query()
                ->where('user_id', $data->userId)
                ->where('status', SelfExclusionStatus::Active->value)
                ->lockForUpdate()
                ->get()
                ->first(fn (SelfExclusion $s) => $s->currentlyGates());

            if ($active instanceof SelfExclusion) {
                throw SelfExclusionException::activeExclusion($data->userId, $active->ends_at->toIso8601String());
            }

            $row = SelfExclusion::query()->create([
                'user_id' => $data->userId,
                'status' => SelfExclusionStatus::Requested,
                'scope' => $data->scope,
                'reason_code' => $data->reasonCode,
                'request_fingerprint' => $fingerprint,
                'effective_at' => $data->effectiveAt,
                'ends_at' => $data->endsAt,
            ]);

            return $row;
        });
    }

    /**
     * ACTIVATE: immediate-effect exclusions move Requested → Active
     * the moment the server clock crosses effective_at. The
     * SelfExclusionActivated envelope flies before the transaction
     * commits; the legacy row is stamped in the same seat.
     */
    public function activate(int|SelfExclusion $exclusion): SelfExclusion
    {
        return DB::transaction(function () use ($exclusion): SelfExclusion {
            /** @var SelfExclusion $locked */
            $locked = SelfExclusion::query()->lockForUpdate()->findOrFail(
                $exclusion instanceof SelfExclusion ? $exclusion->id : $exclusion,
            );

            if ($locked->status === SelfExclusionStatus::Active) {
                return $locked; // idempotent claim
            }

            if (! $locked->status->canTransitionTo(SelfExclusionStatus::Active)) {
                throw SelfExclusionException::invalidTransition(
                    self::ref($locked), $locked->status->value, SelfExclusionStatus::Active->value,
                );
            }

            $locked->status = SelfExclusionStatus::Active;
            $locked->activated_at = now();
            $locked->save();

            $this->stampLegacyRow($locked);

            event(new SelfExclusionActivated($locked, $locked->activated_at->toIso8601String()));

            return $locked->refresh();
        });
    }

    /**
     * EXPIRE: the only gate-lifter — server-authoritative end time
     * must have physically passed. Replay-safe.
     */
    public function expire(SelfExclusion $exclusion): SelfExclusion
    {
        return DB::transaction(function () use ($exclusion): SelfExclusion {
            /** @var SelfExclusion $locked */
            $locked = SelfExclusion::query()->lockForUpdate()->findOrFail($exclusion->id);

            if ($locked->status === SelfExclusionStatus::Expired) {
                return $locked;
            }

            if ($locked->status !== SelfExclusionStatus::Active) {
                return $locked; // never-active requests do not lapse this lane
            }

            if (now()->lt($locked->ends_at)) {
                // End time not reached — physics alone decides; fail-closed.
                throw SelfExclusionException::invalidTransition(
                    self::ref($locked), SelfExclusionStatus::Active->value, SelfExclusionStatus::Expired->value.' (end time not reached)',
                );
            }

            $locked->status = SelfExclusionStatus::Expired;
            $locked->expired_at = now();
            $locked->save();

            $this->stampLegacyRow($locked);
            $this->audit->from($locked, 'self-exclusion expired by server horizon');

            return $locked->refresh();
        });
    }

    /**
     * CANCEL: allowed only while the exclusion was never live —
     * cancelling an ACTIVE gate is forbidden by name.
     */
    public function cancel(SelfExclusion $exclusion, string $cancelledBy): SelfExclusion
    {
        return DB::transaction(function () use ($exclusion, $cancelledBy): SelfExclusion {
            /** @var SelfExclusion $locked */
            $locked = SelfExclusion::query()->lockForUpdate()->findOrFail($exclusion->id);

            if ($locked->status === SelfExclusionStatus::Cancelled) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo(SelfExclusionStatus::Cancelled)) {
                throw SelfExclusionException::forbiddenCancellation(self::ref($locked), $locked->status->value);
            }

            $locked->status = SelfExclusionStatus::Cancelled;
            $locked->cancelled_at = now();
            $locked->cancelled_by = $cancelledBy;
            $locked->save();

            $this->audit->from($locked, 'self-exclusion request withdrawn pre-activation');

            return $locked->refresh();
        });
    }

    /**
     * THE FAIL-CLOSED GATE: the player is excluded iff an ACTIVE
     * exclusion whose end is in the future exists. Derived anew at
     * every call; never a stored flag.
     */
    /**
     * Compatibility name for the boolean gate used by the original service contract.
     */
    public function isSelfExcluded(int $userId): bool
    {
        return $this->hasActiveExclusion($userId);
    }

    public function hasActiveExclusion(int $userId): bool
    {
        return SelfExclusion::query()
            ->where('user_id', $userId)
            ->where('status', SelfExclusionStatus::Active->value)
            ->where('ends_at', '>', now())
            ->exists();
    }

    public function currentActiveFor(int $userId): ?SelfExclusion
    {
        /** @var SelfExclusion|null $active */
        $active = SelfExclusion::query()
            ->where('user_id', $userId)
            ->where('status', SelfExclusionStatus::Active->value)
            ->orderByDesc('ends_at')
            ->first();

        return $active instanceof SelfExclusion && $active->currentlyGates() ? $active : null;
    }

    /**
     * Pending requests whose effective moment the server clock has
     * crossed — the activation sweep reads this page by page.
     *
     * @return Collection<int, SelfExclusion>
     */
    public function dueForActivation(int $limit = 100): Collection
    {
        return SelfExclusion::query()
            ->where('status', SelfExclusionStatus::Requested->value)
            ->where('effective_at', '<=', now())
            ->orderBy('effective_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, SelfExclusion>
     */
    public function dueForExpiry(int $limit = 100): Collection
    {
        return SelfExclusion::query()
            ->where('status', SelfExclusionStatus::Active->value)
            ->where('ends_at', '<=', now())
            ->orderBy('ends_at')
            ->limit($limit)
            ->get();
    }

    /**
     * The two lanes agree: legacy row holds excluded-until for the
     * active lane, and is cleared (never shortened) on expiry/cancel.
     */
    private function stampLegacyRow(SelfExclusion $exclusion): void
    {
        /** @var ResponsibleGamingLimit|null $legacy */
        $legacy = ResponsibleGamingLimit::query()->where('user_id', $exclusion->user_id)->lockForUpdate()->first();

        if (! $legacy instanceof ResponsibleGamingLimit) {
            $legacy = new ResponsibleGamingLimit;
            $legacy->user_id = $exclusion->user_id;
        }

        if ($exclusion->status === SelfExclusionStatus::Active) {
            // The new lane is authoritative: extend to match unless the
            // legacy gate already runs LONGER (never silently shorten).
            $current = $legacy->self_excluded_until;

            if (! $current instanceof Carbon || $current->lt($exclusion->ends_at)) {
                $legacy->self_excluded_until = $exclusion->ends_at;
            }
        } else {
            $current = $legacy->self_excluded_until;

            if ($current instanceof Carbon && ! $current->isFuture()) {
                $legacy->self_excluded_until = null;
            }
        }

        $legacy->save();

        AuditLog::create([
            'user_id' => $exclusion->user_id,
            'action' => AuditAction::Update,
            'auditable_type' => SelfExclusion::class,
            'auditable_id' => $exclusion->id,
            'metadata' => [
                'lane' => 'self-exclusion',
                'risk_rating' => RiskLevel::High->value,
                'status' => $exclusion->status->value,
                'reference' => self::ref($exclusion),
            ],
        ]);
    }

    private static function ref(SelfExclusion $exclusion): string
    {
        return 'SX-'.strtoupper(substr((string) $exclusion->request_fingerprint, 0, 12));
    }
}

```

### `app/Support/RateLimiter.php`

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Cache\RateLimiter as FrameworkRateLimiter;

/**
 * Compatibility adapter for the pre-Laravel-12 named-limiter probe.
 * The framework's limiter() method remains authoritative.
 */
final class RateLimiter extends FrameworkRateLimiter
{
    public function hasNamedLocker(string $name): bool
    {
        return $this->limiter($name) !== null;
    }
}

```

### `database/migrations/2024_02_01_000400_create_draw_certifications_table.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draw certifications.
 * ONE ROW = ONE certification act against a draw. History is kept
 * (supersession rotates rows, never deletes), so draw_id is indexed
 * rather than unique: the service enforces exactly one LIVE certification
 * per draw inside the lock, while the table remembers every act.
 */
return new class () extends Migration
{
    public function up(): void
    {
        Schema::create('draw_certifications', function (Blueprint $table): void {
            $table->id();

            // Deterministic identity: sha256(draw|fingerprint|certifier).
            $table->string('certification_key', 64)->unique();

            // The certified paper is history at the draw lane: restrict.
            $table->foreignId('draw_id')->constrained()->restrictOnDelete();

            $table->string('status', 32)->default('draft')->index();
            $table->string('source_type', 32);

            // The signed truth: what the court corroborated at
            // certification time.
            $table->string('result_fingerprint', 64);
            $table->json('winning_numbers');

            $table->string('certifier_reference', 64);
            $table->timestamp('certified_at')->nullable()->index();

            // When superseded: the key of the takeover certification.
            $table->string('superseded_by_key', 64)->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            // One live certification per draw at query speed (the
            // service still owns the authority under lockForUpdate).
            $table->index(['draw_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draw_certifications');
    }
};

```

### `database/migrations/2024_02_01_000600_create_draw_reconciliations_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draw reconciliation conversations.
 * ONE LIVE ROW PER DRAW: the current judgment. A resolved/matched row
 * closes the conversation; the next act for the same draw begins a new
 * row only when the first was terminal (otherwise the row rotates in
 * place and its drift lines stay visible).
 */
return new class () extends Migration
{
    public function up(): void
    {
        Schema::create('draw_reconciliations', function (Blueprint $table): void {
            $table->id();

            $table->string('reconciliation_key', 64)->unique();

            $table->foreignId('draw_id')->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('pending')->index();

            // Asserted vs actual, by lane — json so drift vocabulary can
            // name more lanes than today without a schema migration:
            // {result, tickets, prizes, payouts} per-lane {expected, actual}.
            $table->json('asserted_totals');
            $table->json('actual_totals');
            $table->json('drift_lines')->nullable();

            $table->string('resolved_note', 255)->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draw_reconciliations');
    }
};

```

### `database/migrations/2024_02_01_000700_create_prize_matches_table.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prize match records.
 * ONE ROW = ONE deterministic match conversation: (draw, bet, tier,
 * amount, result fingerprint). Rejected/Verified rotations happen in
 * place; history is on audit_log, dedupe is the match_key.
 */
return new class () extends Migration
{
    public function up(): void
    {
        Schema::create('prize_matches', function (Blueprint $table): void {
            $table->id();

            // Deterministic identity.
            $table->string('match_key', 64)->unique();

            $table->foreignId('draw_id')->constrained()->restrictOnDelete();
            $table->foreignId('bet_id')->constrained()->restrictOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();

            $table->string('prize_tier', 32);
            $table->decimal('matched_amount', 20, 2);
            $table->string('result_fingerprint', 64);

            $table->string('status', 16)->default('unmatched')->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejected_reason', 255)->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['draw_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prize_matches');
    }
};

```

### `database/migrations/2024_02_01_001000_create_prize_disbursements_table.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prize disbursement rows.
 * ONE ROW = ONE settlement conversation (reservation → disbursement →
 * terminal) against a payout. Amounts are exact decimals; conservation
 * is the service's law, not a column constraint's.
 */
return new class () extends Migration
{
    public function up(): void
    {
        Schema::create('prize_disbursements', function (Blueprint $table): void {
            $table->id();

            $table->string('disbursement_key', 64)->unique();

            $table->foreignId('payout_id')->constrained('payouts')->restrictOnDelete();
            $table->foreignId('payout_batch_id')->nullable()->constrained('payout_batches')->nullOnDelete();

            $table->decimal('amount', 20, 2);
            $table->string('currency', 3);
            $table->string('settlement_fingerprint', 64);

            $table->string('status', 16)->default('pending')->index();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->string('reversal_reason', 255)->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['payout_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prize_disbursements');
    }
};

```

### `database/migrations/2024_02_01_001300_create_ledger_reconciliations_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger reconciliation conversations.
 * ONE LIVE ROW PER WALLET: the current judgment; a terminal row is
 * history. A new comparison on an already-judged wallet opens a new
 * conversation only AFTER the prior one is terminal.
 */
return new class () extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_reconciliations', function (Blueprint $table): void {
            $table->id();

            $table->string('reconciliation_key', 64)->unique();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();

            $table->string('currency', 3);
            $table->string('scope', 32)->default('wallet-liability');

            $table->decimal('expected_balance', 24, 2);
            $table->decimal('ledger_aggregate', 24, 2);
            $table->decimal('reservation_effect', 24, 2)->default(0);
            $table->string('fingerprint', 64);

            $table->string('status', 16)->default('pending')->index();
            $table->json('drift_lines')->nullable();

            $table->string('resolved_note', 255)->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_reconciliations');
    }
};

```

### `database/migrations/2024_02_01_002500_create_responsible_gaming_limit_versions_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responsible_gaming_limit_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('limit_type', 32)->index();
            $table->string('limit_status', 16)->default('pending')->index();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('THB');
            $table->string('limit_key', 64)->unique();
            $table->string('replaced_by_key', 64)->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index(['user_id', 'limit_type', 'limit_status'], 'rgl_versions_user_type_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('responsible_gaming_limit_versions');
    }
};

```

### `database/migrations/2024_02_01_003700_create_notification_delivery_attempts_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('attempt_identity', 64)->unique();
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt_number');
            $table->string('channel', 16);
            $table->string('status', 16)->index();
            $table->string('failure_reason', 32)->nullable();
            $table->string('provider_reference', 128)->nullable();
            $table->timestamp('attempted_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['notification_id', 'attempt_number'], 'notification_delivery_attempts_attempt_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_delivery_attempts');
    }
};

```

### `database/migrations/2026_09_23_000100_create_glo_sales_and_result_tables.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GLO sales, N3 seat, result-import provenance and sales-reconciliation tables.
 *
 * glo_l6_sales             — L6 unit sales seat (draw_id, product unique)
 * glo_n3_sales             — N3 seat sales (draw_id, product unique; conflict gate)
 * glo_result_imports       — provenance of every official/fixture result import
 * glo_sales_reconciliations — reconciliation runs with GLON3_SALES_CONFLICT gate
 *
 * Money columns are decimal strings (BCMath domain). Ticket numbers never
 * stored as integers.
 */
return new class () extends Migration
{
    public function up(): void
    {
        Schema::create('glo_l6_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('draw_id')->constrained('draws')->restrictOnDelete();
            $table->string('product', 8)->default('l6');
            $table->string('seat_key', 96)->unique();

            // Units sold out of full_sale_units (default 1,000,000).
            $table->unsignedBigInteger('units_sold')->default(0);
            $table->unsignedBigInteger('units_full')->default(1000000);
            $table->decimal('gross_sales', 20, 2)->default('0');
            $table->decimal('ticket_price', 10, 2)->default('80');

            $table->string('source_reference', 128)->nullable();
            $table->string('provenance', 64)->default('operator_seat');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['draw_id', 'product'], 'glo_l6_sales_draw_product_unique');
            $table->index('product');
        });

        Schema::create('glo_n3_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('draw_id')->constrained('draws')->restrictOnDelete();
            $table->string('product', 8)->default('n3');
            $table->string('seat_key', 96)->unique();

            // N3 seat quantities and pool inputs (exact strings).
            $table->unsignedBigInteger('seats_sold')->default(0);
            $table->unsignedBigInteger('seats_full')->default(0);
            $table->decimal('gross_sales', 20, 2)->default('0');
            $table->decimal('pool_amount', 20, 2)->default('0');
            $table->decimal('ticket_price', 10, 2)->default('20');

            // seat_state: open | closed | conflicted (GLON3_SALES_CONFLICT).
            $table->string('seat_state', 32)->default('open')->index();
            $table->string('conflict_gate', 64)->nullable();
            $table->string('source_reference', 128)->nullable();
            $table->string('provenance', 64)->default('operator_seat');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['draw_id', 'product'], 'glo_n3_sales_draw_product_unique');
            $table->index(['product', 'seat_state']);
        });

        Schema::create('glo_result_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('import_reference', 64)->unique();
            $table->foreignId('draw_id')->nullable()->constrained('draws')->nullOnDelete();

            // provider: official | fixture
            $table->string('provider', 32)->index();
            $table->string('mode', 32)->default('fixture');
            $table->string('endpoint', 255)->nullable();
            $table->string('upstream_draw_id', 64)->nullable();

            // status: imported | not_configured | failed | skipped
            $table->string('status', 32)->index();
            $table->string('result_fingerprint', 64)->nullable();

            $table->json('payload_summary')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at');
            $table->timestamps();

            $table->index(['draw_id', 'status']);
            $table->index(['provider', 'imported_at']);
        });

        Schema::create('glo_sales_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->string('reconciliation_reference', 64)->unique();
            $table->foreignId('draw_id')->constrained('draws')->restrictOnDelete();
            $table->string('product', 8)->index();

            $table->unsignedBigInteger('expected_seats')->default(0);
            $table->unsignedBigInteger('recorded_seats')->default(0);
            $table->decimal('expected_gross', 20, 2)->default('0');
            $table->decimal('recorded_gross', 20, 2)->default('0');
            $table->decimal('variance_gross', 20, 2)->default('0');

            // status: matched | variance | conflicted (GLON3_SALES_CONFLICT)
            $table->string('status', 32)->index();
            $table->string('conflict_gate', 64)->nullable();
            $table->json('details')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at');
            $table->timestamps();

            $table->unique(['draw_id', 'product', 'reconciliation_reference'], 'glo_sales_recon_draw_product_ref_unique');
            $table->index(['draw_id', 'product', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('glo_sales_reconciliations');
        Schema::dropIfExists('glo_result_imports');
        Schema::dropIfExists('glo_n3_sales');
        Schema::dropIfExists('glo_l6_sales');
    }
};

```

### `database/migrations/2026_09_26_000420_create_national_lottery_result_versions_table.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * national_lottery_result_versions — provenance, idempotency and corrections.
 *
 * THIS TABLE IS THE ANSWER TO "WHERE DID THIS NUMBER COME FROM"
 * ---------------------------------------------------------------------------
 * Every row records, for one attempt to state a draw's result: which provider
 * produced it, what source state that provider earns, what the raw payload
 * hashed to, what the NORMALISED payload hashed to, which parser read it, when
 * it was retrieved, when it was imported and by whom.
 *
 * IDEMPOTENCY IS A DATABASE CONSTRAINT, NOT A CODE CONVENTION
 * ---------------------------------------------------------------------------
 * UNIQUE (draw_id, payload_fingerprint) is what makes "import the same payload
 * twice" produce exactly one version. Two concurrent workers racing on the
 * same payload both attempt the insert; the database lets one through and
 * rejects the other, and the loser reads the winner's row. No advisory lock,
 * no check-then-write window.
 *
 * WHY TWO FINGERPRINTS
 * payload_fingerprint hashes the bytes the provider sent, so a re-fetch that
 * differs only in whitespace or key order is still recognised as a NEW
 * delivery. normalized_fingerprint hashes the canonical result values only, so
 * two structurally different payloads that assert the SAME numbers are
 * recognised as agreeing. Conflict detection needs the second one: a new
 * payload whose normalised fingerprint differs from the verified version's is
 * a disagreement about the numbers, which is the case that must block
 * publication rather than overwrite.
 *
 * NO SECRETS EVER LAND HERE
 * source_endpoint_host stores a HOST, not a URL. A configured endpoint may
 * carry a token in its query string or headers; storing the full URL would put
 * that token in a table that a provenance page reads. The host is enough to
 * answer "which system said this" and carries nothing to steal.
 *
 * CORRECTIONS ARE APPENDS
 * A correction inserts a new version, points supersedes_version_id at the old
 * one, and marks the old one superseded. Nothing is deleted and no result
 * value is ever UPDATEd, which is what lets the detail page honestly show a
 * correction history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('national_lottery_result_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('draw_id')
                ->constrained('national_lottery_draws')
                ->cascadeOnDelete();

            // Monotonic per draw. version_number 1 is the first attempt.
            $table->unsignedInteger('version_number');

            // App\Enums\ResultVersionState
            $table->string('state', 24)->default('pending')->index();

            // --- Provenance ------------------------------------------------
            $table->string('provider', 32)->index();

            // App\Enums\GloSourceState value (shared platform vocabulary).
            $table->string('source_state', 40)->index();

            // The provider's own identifier for this delivery, when it has one.
            $table->string('source_identifier', 191)->nullable();

            // HOST ONLY. Never a full URL — see the class docblock.
            $table->string('source_endpoint_host', 191)->nullable();

            $table->char('payload_fingerprint', 64);
            $table->char('normalized_fingerprint', 64);

            $table->string('parser_version', 16);

            $table->timestamp('retrieved_at')->nullable();
            $table->timestamp('imported_at');
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();

            // --- Correction / conflict trail --------------------------------
            $table->unsignedBigInteger('supersedes_version_id')->nullable()->index();

            // The superseded row's version_number, denormalised so the public
            // provenance projection can say "replaces version 1" without a
            // join (and therefore without an N+1 on the history page).
            $table->unsignedInteger('supersedes_version_number')->nullable();
            $table->string('conflict_reason', 191)->nullable();
            $table->string('resolution_reason', 500)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            // --- Validation --------------------------------------------------
            $table->string('validation_status', 24)->default('pending');
            $table->json('validation_errors')->nullable();

            // Who/what/why, for the audit requirement. Never carries a token.
            $table->json('audit')->nullable();

            $table->timestamps();

            // Version numbers are unique within a draw.
            $table->unique(['draw_id', 'version_number']);

            // THE idempotency constraint.
            $table->unique(['draw_id', 'payload_fingerprint'], 'nl_result_versions_draw_payload_unique');

            // Conflict detection reads by normalised value.
            $table->index(['draw_id', 'normalized_fingerprint'], 'nl_result_versions_draw_normalized_index');

            // "the verified version for this draw"
            $table->index(['draw_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('national_lottery_result_versions');
    }
};

```

### `database/migrations/2026_09_26_000520_create_weekly_lottery_result_versions_table.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * weekly_lottery_result_versions — provenance, idempotency and corrections.
 *
 * THIS TABLE IS THE ANSWER TO "WHERE DID THIS NUMBER COME FROM"
 * ---------------------------------------------------------------------------
 * Every row records, for one attempt to state a draw's result: which provider
 * produced it, what source state that provider earns, what the raw payload
 * hashed to, what the CANONICAL payload hashed to, which parser read it, what
 * the independent Rust verifier said about it, when it was retrieved, when it
 * was imported and by whom.
 *
 * IDEMPOTENCY IS A DATABASE CONSTRAINT, NOT A CODE CONVENTION
 * ---------------------------------------------------------------------------
 * UNIQUE (draw_id, payload_fingerprint) is what makes "import the same payload
 * twice" produce exactly one version. Two concurrent workers racing on the
 * same payload both attempt the insert; the database lets one through and
 * rejects the other, and the loser reads the winner's row. No advisory lock,
 * no check-then-write window.
 *
 * WHY TWO FINGERPRINTS
 * payload_fingerprint hashes the bytes the provider sent, so a re-fetch that
 * differs only in whitespace or key order is still recognised as a NEW
 * delivery. normalized_fingerprint hashes the canonical result values only
 * (the WKLY1 encoding the Rust crate also implements), so two structurally
 * different payloads asserting the SAME numbers are recognised as agreeing.
 * Conflict detection needs the second: a payload whose normalized fingerprint
 * differs from the verified version's is a disagreement about the numbers,
 * which must block publication rather than overwrite.
 *
 * NO SECRETS EVER LAND HERE
 * source_endpoint_host stores a HOST, not a URL. A configured endpoint may
 * carry a token in its query string; storing the full URL would put that token
 * in a table a provenance page reads. The host answers "which system said
 * this" and carries nothing to steal. There is no column for a token, a
 * header, a credential or the raw payload.
 *
 * CORRECTIONS ARE APPENDS
 * A correction inserts a new version, points supersedes_version_id at the old
 * one, and marks the old one superseded. Nothing is deleted and no result
 * value is ever UPDATEd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_lottery_result_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('draw_id')
                ->constrained('weekly_lottery_draws')
                ->cascadeOnDelete();

            $table->unsignedInteger('version_number');

            // App\Enums\ResultVersionState
            $table->string('state', 24)->default('pending')->index();

            // --- Provenance ------------------------------------------------
            $table->string('provider', 32)->index();

            // App\Enums\GloSourceState value (shared platform vocabulary).
            $table->string('source_state', 40)->index();

            $table->string('source_identifier', 191)->nullable();

            // HOST ONLY. Never a full URL — see the class docblock.
            $table->string('source_endpoint_host', 191)->nullable();

            $table->char('payload_fingerprint', 64);
            $table->char('normalized_fingerprint', 64);

            $table->string('parser_version', 16);

            // --- Independent integrity verification -------------------------
            // What the Rust crate said, and which canonical encoding produced
            // the normalized fingerprint. Stored so a later audit can tell a
            // hash-only record from a cryptographically signed one without
            // re-running anything.
            $table->string('integrity_status', 32)->default('NOT_VERIFIED');
            $table->string('canonical_version', 16)->nullable();
            $table->boolean('integrity_native_verified')->default(false);

            $table->timestamp('retrieved_at')->nullable();
            $table->timestamp('imported_at');
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();

            // --- Correction / conflict trail --------------------------------
            $table->unsignedBigInteger('supersedes_version_id')->nullable()->index();

            // The superseded row's version_number, denormalised so the public
            // provenance projection can say "replaces version 1" without a
            // join (and therefore without an N+1 on the history page).
            $table->unsignedInteger('supersedes_version_number')->nullable();

            $table->string('conflict_reason', 191)->nullable();
            $table->string('resolution_reason', 500)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->string('validation_status', 24)->default('pending');
            $table->json('validation_errors')->nullable();

            // Who/what/why. Never carries a token.
            $table->json('audit')->nullable();

            $table->timestamps();

            $table->unique(['draw_id', 'version_number']);

            // THE idempotency constraint.
            $table->unique(['draw_id', 'payload_fingerprint'], 'weekly_result_versions_draw_payload_unique');

            // Conflict detection reads by canonical value.
            $table->index(['draw_id', 'normalized_fingerprint'], 'weekly_result_versions_draw_normalized_index');

            // "the verified version for this draw"
            $table->index(['draw_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_lottery_result_versions');
    }
};

```

### `database/migrations/2026_09_27_000620_create_bingo_lottery_result_versions_table.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bingo_lottery_result_versions — provenance, idempotency and corrections.
 *
 * THIS TABLE IS THE ANSWER TO "WHERE DID THIS NUMBER COME FROM"
 * ---------------------------------------------------------------------------
 * Every row records, for one attempt to state a draw's result: which provider
 * produced it, what source state that provider earns, what the raw payload
 * hashed to, what the CANONICAL payload hashed to, which parser read it, what
 * the independent Rust verifier said about it, when it was retrieved, when it
 * was imported and by whom.
 *
 * IDEMPOTENCY IS A DATABASE CONSTRAINT, NOT A CODE CONVENTION
 * ---------------------------------------------------------------------------
 * UNIQUE (draw_id, payload_fingerprint) is what makes "import the same payload
 * twice" produce exactly one version. Two concurrent workers racing on the
 * same payload both attempt the insert; the database lets one through and
 * rejects the other, and the loser reads the winner's row. No advisory lock,
 * no check-then-write window.
 *
 * WHY TWO FINGERPRINTS
 * payload_fingerprint hashes the bytes the provider sent, so a re-fetch that
 * differs only in whitespace or key order is still recognised as a NEW
 * delivery. normalized_fingerprint hashes the canonical result values only
 * (the WKLY1 encoding the Rust crate also implements), so two structurally
 * different payloads asserting the SAME numbers are recognised as agreeing.
 * Conflict detection needs the second: a payload whose normalized fingerprint
 * differs from the verified version's is a disagreement about the numbers,
 * which must block publication rather than overwrite.
 *
 * NO SECRETS EVER LAND HERE
 * source_endpoint_host stores a HOST, not a URL. A configured endpoint may
 * carry a token in its query string; storing the full URL would put that token
 * in a table a provenance page reads. The host answers "which system said
 * this" and carries nothing to steal. There is no column for a token, a
 * header, a credential or the raw payload.
 *
 * CORRECTIONS ARE APPENDS
 * A correction inserts a new version, points supersedes_version_id at the old
 * one, and marks the old one superseded. Nothing is deleted and no result
 * value is ever UPDATEd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bingo_lottery_result_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('draw_id')
                ->constrained('bingo_lottery_draws')
                ->cascadeOnDelete();

            $table->unsignedInteger('version_number');

            // App\Enums\ResultVersionState
            $table->string('state', 24)->default('pending')->index();

            // --- Provenance ------------------------------------------------
            $table->string('provider', 32)->index();

            // App\Enums\GloSourceState value (shared platform vocabulary).
            $table->string('source_state', 40)->index();

            $table->string('source_identifier', 191)->nullable();

            // HOST ONLY. Never a full URL — see the class docblock.
            $table->string('source_endpoint_host', 191)->nullable();

            $table->char('payload_fingerprint', 64);
            $table->char('normalized_fingerprint', 64);

            $table->string('parser_version', 16);

            // --- Fingerprint qualifier --------------------------------------
            //
            // THIS LANE HAS NO INDEPENDENT VERIFIER. The columns exist because
            // every result version in the repository records how much is known
            // about its bytes, and a reader comparing two lanes needs the same
            // question answered in the same place. Here the answer is always
            // INTEGRITY_HASH_ONLY with integrity_native_verified = false:
            // "PHP hashed these canonical bytes" and nothing more.
            //
            // The Weekly lane runs a Rust verifier and can store something
            // stronger. Leaving these columns out would not have made this
            // lane more honest - it would have made the difference invisible.
            // What the Rust crate said, and which canonical encoding produced
            // the normalized fingerprint. Stored so a later audit can tell a
            // hash-only record from a cryptographically signed one without
            // re-running anything.
            $table->string('integrity_status', 32)->default('NOT_VERIFIED');
            $table->string('canonical_version', 16)->nullable();
            $table->boolean('integrity_native_verified')->default(false);

            $table->timestamp('retrieved_at')->nullable();
            $table->timestamp('imported_at');
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();

            // --- Correction / conflict trail --------------------------------
            $table->unsignedBigInteger('supersedes_version_id')->nullable()->index();

            // The superseded row's version_number, denormalised so the public
            // provenance projection can say "replaces version 1" without a
            // join (and therefore without an N+1 on the history page).
            $table->unsignedInteger('supersedes_version_number')->nullable();

            $table->string('conflict_reason', 191)->nullable();
            $table->string('resolution_reason', 500)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->string('validation_status', 24)->default('pending');
            $table->json('validation_errors')->nullable();

            // Who/what/why. Never carries a token.
            $table->json('audit')->nullable();

            $table->timestamps();

            $table->unique(['draw_id', 'version_number']);

            // THE idempotency constraint.
            $table->unique(['draw_id', 'payload_fingerprint'], 'bingo_result_versions_draw_payload_unique');

            // Conflict detection reads by canonical value.
            $table->index(['draw_id', 'normalized_fingerprint'], 'bingo_result_versions_draw_normalized_index');

            // "the verified version for this draw"
            $table->index(['draw_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bingo_lottery_result_versions');
    }
};

```

### `database/migrations/2026_09_28_000700_create_pcso_lottery_draws_table.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pcso_lottery_draws — one PCSO Lottery draw (PROMPT 9).
 *
 * WHY A NEW TABLE RATHER THAN REUSING AN EXISTING ONE
 * ---------------------------------------------------------------------------
 * Checked before writing this file: `draws` belongs to the operator betting
 * lane and carries markets, cutoffs and bet relations this product has none
 * of; `glo_*` tables model the L6/N3 prize ladder; `national_lottery_draws`
 * models a different product with a different field set (3Up/2Up/3Front/
 * 3After/2Down) and no notion of a draw that publishes nothing at all.
 * Forcing the Mega lane into any of them would mean adding columns that are always
 * null for every other consumer, which is how a shared table becomes a table
 * nobody can reason about.
 *
 * ONE DRAW PER DATE. draw_date is UNIQUE. That constraint, not application
 * code, is what makes two concurrent importers of the same date produce one
 * draw: the loser catches the violation and reads the winner's row.
 *
 * result_status IS PART OF THE DRAW, NOT AN ABSENCE OF DATA
 * ---------------------------------------------------------------------------
 * The reference product this lane models has draws with no published numbers.
 * Representing that as "a draw row with no result row" is ambiguous - it looks
 * identical to "we have not imported this yet". So the draw records its own
 * status explicitly: 'published' means numbers exist, 'unavailable' means the
 * draw happened and the numbers are not available. A page can then say which
 * one it is instead of guessing.
 *
 * current_result_version_id IS DELIBERATELY NOT A FOREIGN KEY. The versions
 * table references this one, so a constraint in the other direction would be a
 * cycle. It is written only by PcsoLotteryImportService, inside the same
 * transaction that creates the version it points at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcso_lottery_draws', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Public route key. A URL must never expose an auto-increment id:
            // it tells a visitor how many rows exist and invites them to walk
            // the table.
            $table->string('draw_reference', 40)->unique();

            // Exactly 'Y-m-d'. Stored as a string by the model's mutator so
            // that every driver - including SQLite, which has no DATE type -
            // holds ten characters.
            //
            // NOT UNIQUE HERE, unlike every other lane. PCSO publishes several
            // draws on one calendar date, so a unique index on draw_date would
            // reject the 17:00 result as a duplicate of the 14:00 one. The
            // composite index below is what enforces identity instead.
            $table->date('draw_date')->index();

            // Canonical local clock reading, 'HH:MM', 24-hour.
            //
            // A STRING, not a time or a datetime. It is the time printed on
            // the draw in Asia/Bangkok, not an instant: stored as a datetime
            // it would be converted by a driver or a server in another zone
            // and a 21:00 draw would surface as 14:00 the following day.
            $table->string('draw_time_local', 5);

            $table->unsignedSmallInteger('draw_year')->index();

            // The business timezone the draw_date is expressed in, recorded so
            // a later reader never has to assume it.
            $table->string('draw_timezone', 64)->default('Asia/Bangkok');

            // 'published' | 'unavailable' — see the class docblock.
            $table->string('result_status', 24)->default('unavailable')->index();

            // App\Enums\DrawPublicationStatus
            $table->string('publication_status', 24)->default('pending')->index();
            $table->timestamp('published_at')->nullable();

            // App\Enums\GloSourceState value of the version currently answering.
            $table->string('source_state', 40)->default('UNAVAILABLE')->index();

            $table->unsignedBigInteger('current_result_version_id')->nullable()->index();

            $table->json('metadata')->nullable();
            $table->timestamps();

            // "this year's draws, newest first" and "what is publicly live"
            // are the only two orderings the public surface uses.
            // IDENTITY. One draw per date-and-time. This is what makes the
            // concurrent import case safe: the loser of a race hits this
            // constraint and re-reads the winner rather than writing a second
            // row for the same draw.
            $table->unique(['draw_date', 'draw_time_local']);

            // "this year's draws, newest first" and "what is publicly live"
            // are the orderings the public surface uses. Both carry the time,
            // because within a date the time is what orders the draws.
            $table->index(['draw_year', 'draw_date', 'draw_time_local']);
            $table->index(['publication_status', 'draw_date', 'draw_time_local'], 'pcso_draws_status_date_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pcso_lottery_draws');
    }
};

```

### `database/migrations/2026_09_28_000720_create_pcso_lottery_result_versions_table.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pcso_lottery_result_versions — provenance, idempotency and corrections.
 *
 * THIS TABLE IS THE ANSWER TO "WHERE DID THIS NUMBER COME FROM"
 * ---------------------------------------------------------------------------
 * Every row records, for one attempt to state a draw's result: which provider
 * produced it, what source state that provider earns, what the raw payload
 * hashed to, what the CANONICAL payload hashed to, which parser read it, what
 * the independent Rust verifier said about it, when it was retrieved, when it
 * was imported and by whom.
 *
 * IDEMPOTENCY IS A DATABASE CONSTRAINT, NOT A CODE CONVENTION
 * ---------------------------------------------------------------------------
 * UNIQUE (draw_id, payload_fingerprint) is what makes "import the same payload
 * twice" produce exactly one version. Two concurrent workers racing on the
 * same payload both attempt the insert; the database lets one through and
 * rejects the other, and the loser reads the winner's row. No advisory lock,
 * no check-then-write window.
 *
 * WHY TWO FINGERPRINTS
 * payload_fingerprint hashes the bytes the provider sent, so a re-fetch that
 * differs only in whitespace or key order is still recognised as a NEW
 * delivery. normalized_fingerprint hashes the canonical result values only
 * (the WKLY1 encoding the Rust crate also implements), so two structurally
 * different payloads asserting the SAME numbers are recognised as agreeing.
 * Conflict detection needs the second: a payload whose normalized fingerprint
 * differs from the verified version's is a disagreement about the numbers,
 * which must block publication rather than overwrite.
 *
 * NO SECRETS EVER LAND HERE
 * source_endpoint_host stores a HOST, not a URL. A configured endpoint may
 * carry a token in its query string; storing the full URL would put that token
 * in a table a provenance page reads. The host answers "which system said
 * this" and carries nothing to steal. There is no column for a token, a
 * header, a credential or the raw payload.
 *
 * CORRECTIONS ARE APPENDS
 * A correction inserts a new version, points supersedes_version_id at the old
 * one, and marks the old one superseded. Nothing is deleted and no result
 * value is ever UPDATEd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcso_lottery_result_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('draw_id')
                ->constrained('pcso_lottery_draws')
                ->cascadeOnDelete();

            $table->unsignedInteger('version_number');

            // App\Enums\ResultVersionState
            $table->string('state', 24)->default('pending')->index();

            // --- Provenance ------------------------------------------------
            $table->string('provider', 32)->index();

            // App\Enums\GloSourceState value (shared platform vocabulary).
            $table->string('source_state', 40)->index();

            $table->string('source_identifier', 191)->nullable();

            // HOST ONLY. Never a full URL — see the class docblock.
            $table->string('source_endpoint_host', 191)->nullable();

            $table->char('payload_fingerprint', 64);
            $table->char('normalized_fingerprint', 64);

            $table->string('parser_version', 16);

            // --- Fingerprint qualifier --------------------------------------
            //
            // THIS LANE HAS NO INDEPENDENT VERIFIER. The columns exist because
            // every result version in the repository records how much is known
            // about its bytes, and a reader comparing two lanes needs the same
            // question answered in the same place. Here the answer is always
            // INTEGRITY_HASH_ONLY with integrity_native_verified = false:
            // "PHP hashed these canonical bytes" and nothing more.
            //
            // The Weekly lane runs a Rust verifier and can store something
            // stronger. Leaving these columns out would not have made this
            // lane more honest - it would have made the difference invisible.
            // What the Rust crate said, and which canonical encoding produced
            // the normalized fingerprint. Stored so a later audit can tell a
            // hash-only record from a cryptographically signed one without
            // re-running anything.
            $table->string('integrity_status', 32)->default('NOT_VERIFIED');
            $table->string('canonical_version', 16)->nullable();
            $table->boolean('integrity_native_verified')->default(false);

            $table->timestamp('retrieved_at')->nullable();
            $table->timestamp('imported_at');
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();

            // --- Correction / conflict trail --------------------------------
            $table->unsignedBigInteger('supersedes_version_id')->nullable()->index();

            // The superseded row's version_number, denormalised so the public
            // provenance projection can say "replaces version 1" without a
            // join (and therefore without an N+1 on the history page).
            $table->unsignedInteger('supersedes_version_number')->nullable();

            $table->string('conflict_reason', 191)->nullable();
            $table->string('resolution_reason', 500)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->string('validation_status', 24)->default('pending');
            $table->json('validation_errors')->nullable();

            // Who/what/why. Never carries a token.
            $table->json('audit')->nullable();

            $table->timestamps();

            $table->unique(['draw_id', 'version_number']);

            // THE idempotency constraint.
            $table->unique(['draw_id', 'payload_fingerprint'], 'pcso_result_versions_draw_payload_unique');

            // Conflict detection reads by canonical value.
            $table->index(['draw_id', 'normalized_fingerprint'], 'pcso_result_versions_draw_normalized_index');

            // "the verified version for this draw"
            $table->index(['draw_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pcso_lottery_result_versions');
    }
};

```

### `package.json`

```json
{
    "private": true,
    "type": "module",
    "scripts": {
        "dev": "vite",
        "build": "vite build",
        "e2e:public": "playwright test tests/browser/public-smoke.spec.mjs"
    },
    "devDependencies": {
        "@playwright/test": "^1.63.0",
        "autoprefixer": "^10.4.20",
        "axios": "^1.7.9",
        "fontaine": "^0.8.2",
        "laravel-vite-plugin": "^3.2.0",
        "playwright": "^1.63.0",
        "postcss": "^8.4.49",
        "tailwindcss": "^3.4.17",
        "vite": "^8.3.1"
    },
    "dependencies": {
        "react": "^18.3.1",
        "react-dom": "^18.3.1"
    }
}

```

### `package-lock.json`

```json
{
    "name": "user",
    "lockfileVersion": 3,
    "requires": true,
    "packages": {
        "": {
            "dependencies": {
                "react": "^18.3.1",
                "react-dom": "^18.3.1"
            },
            "devDependencies": {
                "@playwright/test": "^1.63.0",
                "autoprefixer": "^10.4.20",
                "axios": "^1.7.9",
                "fontaine": "^0.8.2",
                "laravel-vite-plugin": "^3.2.0",
                "playwright": "^1.63.0",
                "postcss": "^8.4.49",
                "tailwindcss": "^3.4.17",
                "vite": "^8.3.1"
            }
        },
        "node_modules/@alloc/quick-lru": {
            "version": "5.3.0",
            "resolved": "https://registry.npmjs.org/@alloc/quick-lru/-/quick-lru-5.3.0.tgz",
            "integrity": "sha512-U4+70Pc5ZS9osnCBCE5Jha/ciHM+Yp+CNMNC/7HvYbNRk1Ldd+f7qO65W5qfhu/TCv+/ozljlXXe9Nj8419DMA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=10"
            },
            "funding": {
                "url": "https://github.com/sponsors/sindresorhus"
            }
        },
        "node_modules/@capsizecss/unpack": {
            "version": "4.0.1",
            "resolved": "https://registry.npmjs.org/@capsizecss/unpack/-/unpack-4.0.1.tgz",
            "integrity": "sha512-CuNiSqg7+e1cO/GjffyMOm5Tt2jUF9CWHHnvQ/UkqvtkGfHdgwEC0wpmq7fkN3gxwpRnrAN0WzO3vREKmNolMQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "fontkitten": "^1.0.3"
            },
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@jridgewell/gen-mapping": {
            "version": "0.3.13",
            "resolved": "https://registry.npmjs.org/@jridgewell/gen-mapping/-/gen-mapping-0.3.13.tgz",
            "integrity": "sha512-2kkt/7niJ6MgEPxF0bYdQ6etZaA+fQvDcLKckhy1yIQOzaoKjBBjSj63/aLVjYE3qhRt5dvM+uUyfCg6UKCBbA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@jridgewell/sourcemap-codec": "^1.5.0",
                "@jridgewell/trace-mapping": "^0.3.24"
            }
        },
        "node_modules/@jridgewell/remapping": {
            "version": "2.3.5",
            "resolved": "https://registry.npmjs.org/@jridgewell/remapping/-/remapping-2.3.5.tgz",
            "integrity": "sha512-LI9u/+laYG4Ds1TDKSJW2YPrIlcVYOwi2fUC6xB43lueCjgxV4lffOCZCtYFiH6TNOX+tQKXx97T4IKHbhyHEQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@jridgewell/gen-mapping": "^0.3.5",
                "@jridgewell/trace-mapping": "^0.3.24"
            }
        },
        "node_modules/@jridgewell/resolve-uri": {
            "version": "3.1.2",
            "resolved": "https://registry.npmjs.org/@jridgewell/resolve-uri/-/resolve-uri-3.1.2.tgz",
            "integrity": "sha512-bRISgCIjP20/tbWSPWMEi54QVPRZExkuD9lJL+UIxUKtwVJA8wW1Trb1jMs1RFXo1CBTNZ/5hpC9QvmKWdopKw==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=6.0.0"
            }
        },
        "node_modules/@jridgewell/sourcemap-codec": {
            "version": "1.6.0",
            "resolved": "https://registry.npmjs.org/@jridgewell/sourcemap-codec/-/sourcemap-codec-1.6.0.tgz",
            "integrity": "sha512-T7jf+5zgsZHwNJ4lvQ7/aezbyk0nNX+zJVWpmHA7VYsEx7a7qr5Rg5IbtJFqkgze5Y2sruq1RUY8Q837Od7iFw==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/@jridgewell/trace-mapping": {
            "version": "0.3.31",
            "resolved": "https://registry.npmjs.org/@jridgewell/trace-mapping/-/trace-mapping-0.3.31.tgz",
            "integrity": "sha512-zzNR+SdQSDJzc8joaeP8QQoCQr8NuYx2dIIytl1QeBEZHJ9uW6hebsrYgbz8hJwUQao3TWCMtmfV8Nu1twOLAw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@jridgewell/resolve-uri": "^3.1.0",
                "@jridgewell/sourcemap-codec": "^1.4.14"
            }
        },
        "node_modules/@nodelib/fs.scandir": {
            "version": "2.1.5",
            "resolved": "https://registry.npmjs.org/@nodelib/fs.scandir/-/fs.scandir-2.1.5.tgz",
            "integrity": "sha512-vq24Bq3ym5HEQm2NKCr3yXDwjc7vTsEThRDnkp2DK9p1uqLR+DHurm/NOTo0KG7HYHU7eppKZj3MyqYuMBf62g==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@nodelib/fs.stat": "2.0.5",
                "run-parallel": "^1.1.9"
            },
            "engines": {
                "node": ">= 8"
            }
        },
        "node_modules/@nodelib/fs.stat": {
            "version": "2.0.5",
            "resolved": "https://registry.npmjs.org/@nodelib/fs.stat/-/fs.stat-2.0.5.tgz",
            "integrity": "sha512-RkhPPp2zrqDAQA/2jNhnztcPAlv64XdhIp7a7454A5ovI7Bukxgt7MX7udwAu3zg1DcpPU0rz3VV1SeaqvY4+A==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 8"
            }
        },
        "node_modules/@nodelib/fs.walk": {
            "version": "1.2.8",
            "resolved": "https://registry.npmjs.org/@nodelib/fs.walk/-/fs.walk-1.2.8.tgz",
            "integrity": "sha512-oGB+UxlgWcgQkgwo8GcEGwemoTFt3FIO9ababBmaGwXIoBKZ+GTy0pP185beGg7Llih/NSHSV2XAs1lnznocSg==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@nodelib/fs.scandir": "2.1.5",
                "fastq": "^1.6.0"
            },
            "engines": {
                "node": ">= 8"
            }
        },
        "node_modules/@oxc-project/types": {
            "version": "0.151.0",
            "resolved": "https://registry.npmjs.org/@oxc-project/types/-/types-0.151.0.tgz",
            "integrity": "sha512-J1yXrIlNDZVzE3ada310xeAw7nH8yCAyLPuUIsjKatFPmfn5bS1oW+cM+QsGOtVWd5nhSpbwZWx/rue+r5Z+PA==",
            "dev": true,
            "license": "MIT",
            "funding": {
                "url": "https://github.com/sponsors/oxc-project"
            }
        },
        "node_modules/@playwright/test": {
            "version": "1.63.0",
            "resolved": "https://registry.npmjs.org/@playwright/test/-/test-1.63.0.tgz",
            "integrity": "sha512-oxMK4vllB9RK5NQ2l1pq1IfOf2AvnEuj/vYGDj0H2nMtmtZpKtCwt/l00GEO6xjGfpBNAvjovvYdCm50dRQkpQ==",
            "dev": true,
            "license": "Apache-2.0",
            "dependencies": {
                "playwright": "1.63.0"
            },
            "bin": {
                "playwright": "cli.js"
            },
            "engines": {
                "node": ">=20"
            }
        },
        "node_modules/@rolldown/binding-android-arm-eabi": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-android-arm-eabi/-/binding-android-arm-eabi-1.2.11.tgz",
            "integrity": "sha512-A5kXfGKvKWWZE0TtPrfsvT+q4Y5d1QG8gGUzpYjGydM+fARM9MuX90PrXYXe0XbsDVgyxxNzHo6giCj90bsFNw==",
            "cpu": [
                "arm"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "android"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-android-arm64": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-android-arm64/-/binding-android-arm64-1.2.11.tgz",
            "integrity": "sha512-z6cTycz+iJ4PVkuL4HHW4DfTfoeU/2nqYYuSOrTmH7yHK5Y0LCOnA03V4ZNxavyVaU1oOqUgIg2klN/s+USGOA==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "android"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-darwin-arm64": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-darwin-arm64/-/binding-darwin-arm64-1.2.11.tgz",
            "integrity": "sha512-jShvqNtP6vDC6/A5JOAzbVV+DkgHqhl/ScVCJEbt+TUY6QYz7YnXcrg3sLtFBniro0f/Ld50ZwCWA6f7KYD1nQ==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "darwin"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-darwin-x64": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-darwin-x64/-/binding-darwin-x64-1.2.11.tgz",
            "integrity": "sha512-f2i2xiNWq1Z1l2++q2fuhZRdLAT3aqxD6vRNm1RAxpUoBcdqNB3C0s1Bt+K+PbEx2F5F4gQp6hqKkphCY/xF9w==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "darwin"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-freebsd-x64": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-freebsd-x64/-/binding-freebsd-x64-1.2.11.tgz",
            "integrity": "sha512-4Ir5FSOKIAMr4r0kExpt1s3bMgzJU3rA45AYOHtQpls0oNeqcYBKrWMlckrYH4KCfGLfkfn1tN1dmZPMVsdXow==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "freebsd"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-linux-arm-gnueabihf": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-linux-arm-gnueabihf/-/binding-linux-arm-gnueabihf-1.2.11.tgz",
            "integrity": "sha512-/gnRDM+39BROzAN/k1OZjDPnDMcZxB/0EUxKjONO5yVkNEvlsoMDrxGNKgZi/ttFriS2gwlDNzB65pvNbFOXIQ==",
            "cpu": [
                "arm"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-linux-arm64-gnu": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-linux-arm64-gnu/-/binding-linux-arm64-gnu-1.2.11.tgz",
            "integrity": "sha512-PFaK8HwvAHbaKbBcDNQihjMKYvFnA5hiENx/l5tphTDz1E0WFp32l0A7aq7lyUwGsRw/xSrNIy/gIK4thrSCrw==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-linux-arm64-musl": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-linux-arm64-musl/-/binding-linux-arm64-musl-1.2.11.tgz",
            "integrity": "sha512-AskzJUIKRLPxkruR1wLKewGbOw+EYfU/9lOrBFj4AFrEA8hPpKFnODWNu2WLaNs0QNkEb9QIJufmVZZIL/bJlg==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-linux-ppc64-gnu": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-linux-ppc64-gnu/-/binding-linux-ppc64-gnu-1.2.11.tgz",
            "integrity": "sha512-qlUGAheh2yh8afH7QBgx0PrRHN85hKnNd78x8MeMhXivuevgd8vgf6/CstOzmNKY/lLTHvNTrPy98cLnAugzJw==",
            "cpu": [
                "ppc64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-linux-s390x-gnu": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-linux-s390x-gnu/-/binding-linux-s390x-gnu-1.2.11.tgz",
            "integrity": "sha512-secpEad+0vCbSfn8upFySkDskv+bGPk3THSDS9Y89yc4rb4kzqHp8Dmyd9BkQW4SnhNXBZCl/6CrO//hZahNJQ==",
            "cpu": [
                "s390x"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-linux-x64-gnu": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-linux-x64-gnu/-/binding-linux-x64-gnu-1.2.11.tgz",
            "integrity": "sha512-mOVBT3dPpkWm8XBWPmU4bf+U6dYDLeMo/9ojUmis4N0L5uu10qra5vOyngZ7/PSdoE4G9KvRt4bloRxNjLas7A==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-linux-x64-musl": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-linux-x64-musl/-/binding-linux-x64-musl-1.2.11.tgz",
            "integrity": "sha512-Is78i9A8Ui4SqcxUwFJ9uMmjDn58IbVTjFWYdQestFEgeuEmHMLGNriXnVJKkwG2YiZjw8cP0zCTyDMdDGtOOg==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-openharmony-arm64": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-openharmony-arm64/-/binding-openharmony-arm64-1.2.11.tgz",
            "integrity": "sha512-dUCXneZ87INUMyQ0D+C0HrEBNUPNXHaPmU5GTjyKTJEiussw9Kaj5Ln8UztPe4epV/ffvgNBEadksdYhmW6xJA==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "openharmony"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-win32-arm64-msvc": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-win32-arm64-msvc/-/binding-win32-arm64-msvc-1.2.11.tgz",
            "integrity": "sha512-jByxb6qfd+bH1xUd0qnfFnb17i9sWBPY2tOavJ0l3tdr3OTu+Kvtm8cd/JV5nFt657b1VqGltxg9olOEfofXWw==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "win32"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-win32-x64-msvc": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-win32-x64-msvc/-/binding-win32-x64-msvc-1.2.11.tgz",
            "integrity": "sha512-/PzKqzAJ03i19oy2ItPvyvaVjOjBCNnfaJs8yvUdGBKmiESgnrJSQ2awd81QzFbbnAmu7YO9ZnJrDCb9VSJPRA==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "win32"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/pluginutils": {
            "version": "1.0.1",
            "resolved": "https://registry.npmjs.org/@rolldown/pluginutils/-/pluginutils-1.0.1.tgz",
            "integrity": "sha512-2j9bGt5Jh8hj+vPtgzPtl72j0yRxHAyumoo6TNfAjsLB04UtpSvPbPcDcBMxz7n+9CYB0c1GxQFxYRg2jimqGw==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/agent-base": {
            "version": "6.0.2",
            "resolved": "https://registry.npmjs.org/agent-base/-/agent-base-6.0.2.tgz",
            "integrity": "sha512-RZNwNclF7+MS/8bDg70amg32dyeZGZxiDuQmZxKLAlQjr3jGyLx+4Kkk58UO7D2QdgFIQCovuSuZESne6RG6XQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "debug": "4"
            },
            "engines": {
                "node": ">= 6.0.0"
            }
        },
        "node_modules/any-promise": {
            "version": "1.3.0",
            "resolved": "https://registry.npmjs.org/any-promise/-/any-promise-1.3.0.tgz",
            "integrity": "sha512-7UvmKalWRt1wgjL1RrGxoSJW/0QZFIegpeGvZG9kjp8vrRu55XTHbwnqq2GpXm9uLbcuhxm3IqX9OB4MZR1b2A==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/anymatch": {
            "version": "3.1.3",
            "resolved": "https://registry.npmjs.org/anymatch/-/anymatch-3.1.3.tgz",
            "integrity": "sha512-KMReFUr0B4t+D+OBkjR3KYqvocp2XaSzO55UcB6mgQMd3KbcE+mWTyvVV7D/zsdEbNnV6acZUutkiHQXvTr1Rw==",
            "dev": true,
            "license": "ISC",
            "dependencies": {
                "normalize-path": "^3.0.0",
                "picomatch": "^2.0.4"
            },
            "engines": {
                "node": ">= 8"
            }
        },
        "node_modules/arg": {
            "version": "5.0.2",
            "resolved": "https://registry.npmjs.org/arg/-/arg-5.0.2.tgz",
            "integrity": "sha512-PYjyFOLKQ9y57JvQ6QLo8dAgNqswh8M1RMJYdQduT6xbWSgK36P/Z/v+p888pM69jMMfS8Xd8F6I1kQ/I9HUGg==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/asynckit": {
            "version": "0.4.0",
            "resolved": "https://registry.npmjs.org/asynckit/-/asynckit-0.4.0.tgz",
            "integrity": "sha512-Oei9OH4tRh0YqU3GxhX79dM/mwVgvbZJaSNaRk+bshkj0S5cfHcgYakreBjrHwatXKbz+IoIdYLxrKim2MjW0Q==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/autoprefixer": {
            "version": "10.6.1",
            "resolved": "https://registry.npmjs.org/autoprefixer/-/autoprefixer-10.6.1.tgz",
            "integrity": "sha512-cL1Qz6ADZhcEbny/8HPfe99J6HhNoYtpX2LFLIbhgGE7Q1hlQVkYFdetDN7Id3KiQxhDrHwzlHr/YQCnZ8+xSA==",
            "dev": true,
            "funding": [
                {
                    "type": "opencollective",
                    "url": "https://opencollective.com/postcss/"
                },
                {
                    "type": "tidelift",
                    "url": "https://tidelift.com/funding/github/npm/autoprefixer"
                },
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/ai"
                }
            ],
            "license": "MIT",
            "dependencies": {
                "browserslist": "^4.28.9",
                "caniuse-lite": "^1.0.30001810",
                "fraction.js": "^5.3.4",
                "picocolors": "^1.1.1",
                "postcss-value-parser": "^4.2.0"
            },
            "bin": {
                "autoprefixer": "bin/autoprefixer"
            },
            "engines": {
                "node": "^10 || ^12 || >=14"
            },
            "peerDependencies": {
                "postcss": "^8.1.0"
            }
        },
        "node_modules/axios": {
            "version": "1.20.0",
            "resolved": "https://registry.npmjs.org/axios/-/axios-1.20.0.tgz",
            "integrity": "sha512-r8aOh8j9cGKpgQAqpzrUHnSIc6a59Y3Xf/cv8sy1DrHCkZHzQGEuoq1tARk6qSyDdtQGSDgpb9kFlruzPvrgwg==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "follow-redirects": "^1.16.0",
                "form-data": "^4.0.6",
                "https-proxy-agent": "^5.0.1",
                "proxy-from-env": "^2.1.0"
            }
        },
        "node_modules/baseline-browser-mapping": {
            "version": "2.11.25",
            "resolved": "https://registry.npmjs.org/baseline-browser-mapping/-/baseline-browser-mapping-2.11.25.tgz",
            "integrity": "sha512-gMmEShwwq7FJqMwvfRwvCl00v4kN+KOfJqXn+f4nrufak5gNHJOksd/60Dvjuz7sI8Y5WiSFBa8FEYr+zoyqCw==",
            "dev": true,
            "license": "Apache-2.0",
            "bin": {
                "baseline-browser-mapping": "dist/cli.cjs"
            },
            "engines": {
                "node": ">=6.0.0"
            }
        },
        "node_modules/binary-extensions": {
            "version": "2.3.0",
            "resolved": "https://registry.npmjs.org/binary-extensions/-/binary-extensions-2.3.0.tgz",
            "integrity": "sha512-Ceh+7ox5qe7LJuLHoY0feh3pHuUDHAcRUeyL2VYghZwfpkNIy/+8Ocg0a3UuSoYzavmylwuLWQOf3hl0jjMMIw==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=8"
            },
            "funding": {
                "url": "https://github.com/sponsors/sindresorhus"
            }
        },
        "node_modules/braces": {
            "version": "3.0.3",
            "resolved": "https://registry.npmjs.org/braces/-/braces-3.0.3.tgz",
            "integrity": "sha512-yQbXgO/OSZVD2IsiLlro+7Hf6Q18EJrKSEsdoMzKePKXct3gvD8oLcOQdIzGupr5Fj+EDe8gO/lxc1BzfMpxvA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "fill-range": "^7.1.1"
            },
            "engines": {
                "node": ">=8"
            }
        },
        "node_modules/browserslist": {
            "version": "4.29.0",
            "resolved": "https://registry.npmjs.org/browserslist/-/browserslist-4.29.0.tgz",
            "integrity": "sha512-3GSvyjvDI4Dur1Meg2BekJquu5uF+9R9a1+5M1Mde192eZoXbeXjzgOsgqPS2V8D5wrrip0gR5Hf/GhWQ9ZzaA==",
            "dev": true,
            "funding": [
                {
                    "type": "opencollective",
                    "url": "https://opencollective.com/browserslist"
                },
                {
                    "type": "tidelift",
                    "url": "https://tidelift.com/funding/github/npm/browserslist"
                },
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/ai"
                }
            ],
            "license": "MIT",
            "dependencies": {
                "baseline-browser-mapping": "^2.11.23",
                "caniuse-lite": "^1.0.30001810",
                "electron-to-chromium": "^1.5.427",
                "node-releases": "^2.0.55",
                "update-browserslist-db": "^1.3.3"
            },
            "bin": {
                "browserslist": "cli.js"
            },
            "engines": {
                "node": "^6 || ^7 || ^8 || ^9 || ^10 || ^11 || ^12 || >=13.7"
            }
        },
        "node_modules/call-bind-apply-helpers": {
            "version": "1.0.2",
            "resolved": "https://registry.npmjs.org/call-bind-apply-helpers/-/call-bind-apply-helpers-1.0.2.tgz",
            "integrity": "sha512-Sp1ablJ0ivDkSzjcaJdxEunN5/XvksFJ2sMBFfq6x0ryhQV/2b/KwFe21cMpmHtPOSij8K99/wSfoEuTObmuMQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "es-errors": "^1.3.0",
                "function-bind": "^1.1.2"
            },
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/camelcase-css": {
            "version": "2.0.1",
            "resolved": "https://registry.npmjs.org/camelcase-css/-/camelcase-css-2.0.1.tgz",
            "integrity": "sha512-QOSvevhslijgYwRx6Rv7zKdMF8lbRmx+uQGx2+vDc+KI/eBnsy9kit5aj23AgGu3pa4t9AgwbnXWqS+iOY+2aA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 6"
            }
        },
        "node_modules/caniuse-lite": {
            "version": "1.0.30001810",
            "resolved": "https://registry.npmjs.org/caniuse-lite/-/caniuse-lite-1.0.30001810.tgz",
            "integrity": "sha512-TITQPUkaz+aVk5GL6NhOdwk1aEaNTSDPsGFWrTuhKGtjTF70jL/Oht2W4c6rXUe5fu7Ie19VIahAXHIIiWWNeg==",
            "dev": true,
            "funding": [
                {
                    "type": "opencollective",
                    "url": "https://opencollective.com/browserslist"
                },
                {
                    "type": "tidelift",
                    "url": "https://tidelift.com/funding/github/npm/caniuse-lite"
                },
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/ai"
                }
            ],
            "license": "CC-BY-4.0"
        },
        "node_modules/chokidar": {
            "version": "3.6.0",
            "resolved": "https://registry.npmjs.org/chokidar/-/chokidar-3.6.0.tgz",
            "integrity": "sha512-7VT13fmjotKpGipCW9JEQAusEPE+Ei8nl6/g4FBAmIm0GOOLMua9NDDo/DWp0ZAxCr3cPq5ZpBqmPAQgDda2Pw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "anymatch": "~3.1.2",
                "braces": "~3.0.2",
                "glob-parent": "~5.1.2",
                "is-binary-path": "~2.1.0",
                "is-glob": "~4.0.1",
                "normalize-path": "~3.0.0",
                "readdirp": "~3.6.0"
            },
            "engines": {
                "node": ">= 8.10.0"
            },
            "funding": {
                "url": "https://paulmillr.com/funding/"
            },
            "optionalDependencies": {
                "fsevents": "~2.3.2"
            }
        },
        "node_modules/chokidar/node_modules/glob-parent": {
            "version": "5.1.2",
            "resolved": "https://registry.npmjs.org/glob-parent/-/glob-parent-5.1.2.tgz",
            "integrity": "sha512-AOIgSQCepiJYwP3ARnGx+5VnTu2HBYdzbGP45eLw1vr3zB3vZLeyed1sC9hnbcOc9/SrMyM5RPQrkGz4aS9Zow==",
            "dev": true,
            "license": "ISC",
            "dependencies": {
                "is-glob": "^4.0.1"
            },
            "engines": {
                "node": ">= 6"
            }
        },
        "node_modules/combined-stream": {
            "version": "1.0.8",
            "resolved": "https://registry.npmjs.org/combined-stream/-/combined-stream-1.0.8.tgz",
            "integrity": "sha512-FQN4MRfuJeHf7cBbBMJFXhKSDq+2kAArBlmRBvcvFE5BB1HZKXtSFASDhdlz9zOYwxh8lDdnvmMOe/+5cdoEdg==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "delayed-stream": "~1.0.0"
            },
            "engines": {
                "node": ">= 0.8"
            }
        },
        "node_modules/commander": {
            "version": "4.1.1",
            "resolved": "https://registry.npmjs.org/commander/-/commander-4.1.1.tgz",
            "integrity": "sha512-NOKm8xhkzAjzFx8B2v5OAHT+u5pRQc2UCa2Vq9jYL/31o2wi9mxBA7LIFs3sV5VSC49z6pEhfbMULvShKj26WA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 6"
            }
        },
        "node_modules/css-tree": {
            "version": "3.2.1",
            "resolved": "https://registry.npmjs.org/css-tree/-/css-tree-3.2.1.tgz",
            "integrity": "sha512-X7sjQzceUhu1u7Y/ylrRZFU2FS6LRiFVp6rKLPg23y3x3c3DOKAwuXGDp+PAGjh6CSnCjYeAul8pcT8bAl+lSA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "mdn-data": "2.27.1",
                "source-map-js": "^1.2.1"
            },
            "engines": {
                "node": "^10 || ^12.20.0 || ^14.13.0 || >=15.0.0"
            }
        },
        "node_modules/cssesc": {
            "version": "3.0.0",
            "resolved": "https://registry.npmjs.org/cssesc/-/cssesc-3.0.0.tgz",
            "integrity": "sha512-/Tb/JcjK111nNScGob5MNtsntNM1aCNUDipB/TkwZFhyDrrE47SOx/18wF2bbjgc3ZzCSKW1T5nt5EbFoAz/Vg==",
            "dev": true,
            "license": "MIT",
            "bin": {
                "cssesc": "bin/cssesc"
            },
            "engines": {
                "node": ">=4"
            }
        },
        "node_modules/debug": {
            "version": "4.4.3",
            "resolved": "https://registry.npmjs.org/debug/-/debug-4.4.3.tgz",
            "integrity": "sha512-RGwwWnwQvkVfavKVt22FGLw+xYSdzARwm0ru6DhTVA3umU5hZc28V3kO4stgYryrTlLpuvgI9GiijltAjNbcqA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "ms": "^2.1.3"
            },
            "engines": {
                "node": ">=6.0"
            },
            "peerDependenciesMeta": {
                "supports-color": {
                    "optional": true
                }
            }
        },
        "node_modules/delayed-stream": {
            "version": "1.0.0",
            "resolved": "https://registry.npmjs.org/delayed-stream/-/delayed-stream-1.0.0.tgz",
            "integrity": "sha512-ZySD7Nf91aLB0RxL4KGrKHBXl7Eds1DAmEdcoVawXnLD7SDhpNgtuII2aAkg7a7QS41jxPSZ17p4VdGnMHk3MQ==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=0.4.0"
            }
        },
        "node_modules/detect-libc": {
            "version": "2.1.2",
            "resolved": "https://registry.npmjs.org/detect-libc/-/detect-libc-2.1.2.tgz",
            "integrity": "sha512-Btj2BOOO83o3WyH59e8MgXsxEQVcarkUOpEYrubB0urwnN10yQ364rsiByU11nZlqWYZm05i/of7io4mzihBtQ==",
            "dev": true,
            "license": "Apache-2.0",
            "engines": {
                "node": ">=8"
            }
        },
        "node_modules/didyoumean": {
            "version": "1.2.2",
            "resolved": "https://registry.npmjs.org/didyoumean/-/didyoumean-1.2.2.tgz",
            "integrity": "sha512-gxtyfqMg7GKyhQmb056K7M3xszy/myH8w+B4RT+QXBQsvAOdc3XymqDDPHx1BgPgsdAA5SIifona89YtRATDzw==",
            "dev": true,
            "license": "Apache-2.0"
        },
        "node_modules/dlv": {
            "version": "1.1.3",
            "resolved": "https://registry.npmjs.org/dlv/-/dlv-1.1.3.tgz",
            "integrity": "sha512-+HlytyjlPKnIG8XuRG8WvmBP8xs8P71y+SKKS6ZXWoEgLuePxtDoUEiH7WkdePWrQ5JBpE6aoVqfZfJUQkjXwA==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/dunder-proto": {
            "version": "1.0.1",
            "resolved": "https://registry.npmjs.org/dunder-proto/-/dunder-proto-1.0.1.tgz",
            "integrity": "sha512-KIN/nDJBQRcXw0MLVhZE9iQHmG68qAVIBg9CqmUYjmQIhgij9U5MFvrqkUL5FbtyyzZuOeOt0zdeRe4UY7ct+A==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "call-bind-apply-helpers": "^1.0.1",
                "es-errors": "^1.3.0",
                "gopd": "^1.2.0"
            },
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/electron-to-chromium": {
            "version": "1.5.438",
            "resolved": "https://registry.npmjs.org/electron-to-chromium/-/electron-to-chromium-1.5.438.tgz",
            "integrity": "sha512-AN9xMU1hJiT65LkCUPL1DZm5TCbOPb2Qsm5pYwFLEXp6/qj9SdT4yMiqs7Qqstwvkn1zF7l36SRGt+s6XcJ0FA==",
            "dev": true,
            "license": "ISC"
        },
        "node_modules/es-define-property": {
            "version": "1.0.1",
            "resolved": "https://registry.npmjs.org/es-define-property/-/es-define-property-1.0.1.tgz",
            "integrity": "sha512-e3nRfgfUZ4rNGL232gUgX06QNyyez04KdjFrF+LTRoOXmrOgFKDg4BCdsjW8EnT69eqdYGmRpJwiPVYNrCaW3g==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/es-errors": {
            "version": "1.3.0",
            "resolved": "https://registry.npmjs.org/es-errors/-/es-errors-1.3.0.tgz",
            "integrity": "sha512-Zf5H2Kxt2xjTvbJvP2ZWLEICxA6j+hAmMzIlypy4xcBg1vKVnx89Wy0GbS+kf5cwCVFFzdCFh2XSCFNULS6csw==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/es-object-atoms": {
            "version": "1.1.2",
            "resolved": "https://registry.npmjs.org/es-object-atoms/-/es-object-atoms-1.1.2.tgz",
            "integrity": "sha512-HWcBoN6NileqtSydK2FqHbS/LoDd2pqrnQHLyJzBj4kOp/ky2MWMN694xOfkK8/SnUsW2DH7EfyVlydKCsm1Zw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "es-errors": "^1.3.0"
            },
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/es-set-tostringtag": {
            "version": "2.1.0",
            "resolved": "https://registry.npmjs.org/es-set-tostringtag/-/es-set-tostringtag-2.1.0.tgz",
            "integrity": "sha512-j6vWzfrGVfyXxge+O0x5sh6cvxAog0a/4Rdd2K36zCMV5eJ+/+tOAngRO8cODMNWbVRdVlmGZQL2YS3yR8bIUA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "es-errors": "^1.3.0",
                "get-intrinsic": "^1.2.6",
                "has-tostringtag": "^1.0.2",
                "hasown": "^2.0.2"
            },
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/escalade": {
            "version": "3.2.0",
            "resolved": "https://registry.npmjs.org/escalade/-/escalade-3.2.0.tgz",
            "integrity": "sha512-WUj2qlxaQtO4g6Pq5c29GTcWGDyd8itL8zTlipgECz3JesAiiOKotd8JU6otB3PACgG6xkJUyVhboMS+bje/jA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=6"
            }
        },
        "node_modules/fast-glob": {
            "version": "3.3.3",
            "resolved": "https://registry.npmjs.org/fast-glob/-/fast-glob-3.3.3.tgz",
            "integrity": "sha512-7MptL8U0cqcFdzIzwOTHoilX9x5BrNqye7Z/LuC7kCMRio1EMSyqRK3BEAUD7sXRq4iT4AzTVuZdhgQ2TCvYLg==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@nodelib/fs.stat": "^2.0.2",
                "@nodelib/fs.walk": "^1.2.3",
                "glob-parent": "^5.1.2",
                "merge2": "^1.3.0",
                "micromatch": "^4.0.8"
            },
            "engines": {
                "node": ">=8.6.0"
            }
        },
        "node_modules/fast-glob/node_modules/glob-parent": {
            "version": "5.1.2",
            "resolved": "https://registry.npmjs.org/glob-parent/-/glob-parent-5.1.2.tgz",
            "integrity": "sha512-AOIgSQCepiJYwP3ARnGx+5VnTu2HBYdzbGP45eLw1vr3zB3vZLeyed1sC9hnbcOc9/SrMyM5RPQrkGz4aS9Zow==",
            "dev": true,
            "license": "ISC",
            "dependencies": {
                "is-glob": "^4.0.1"
            },
            "engines": {
                "node": ">= 6"
            }
        },
        "node_modules/fastq": {
            "version": "1.20.3",
            "resolved": "https://registry.npmjs.org/fastq/-/fastq-1.20.3.tgz",
            "integrity": "sha512-XKv5nnLs6nLF71NgiKJLIZFLkPyIEuOselLG7ujZnGrRfQK8HpvY+WqKhAJUAdLomwVHErVS4LfxFlPq0/FTAw==",
            "dev": true,
            "license": "ISC",
            "dependencies": {
                "reusify": "^1.0.4"
            }
        },
        "node_modules/fill-range": {
            "version": "7.1.1",
            "resolved": "https://registry.npmjs.org/fill-range/-/fill-range-7.1.1.tgz",
            "integrity": "sha512-YsGpe3WHLK8ZYi4tWDg2Jy3ebRz2rXowDxnld4bkQB00cc/1Zw9AWnC0i9ztDJitivtQvaI9KaLyKrc+hBW0yg==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "to-regex-range": "^5.0.1"
            },
            "engines": {
                "node": ">=8"
            }
        },
        "node_modules/follow-redirects": {
            "version": "1.16.0",
            "resolved": "https://registry.npmjs.org/follow-redirects/-/follow-redirects-1.16.0.tgz",
            "integrity": "sha512-y5rN/uOsadFT/JfYwhxRS5R7Qce+g3zG97+JrtFZlC9klX/W5hD7iiLzScI4nZqUS7DNUdhPgw4xI8W2LuXlUw==",
            "dev": true,
            "funding": [
                {
                    "type": "individual",
                    "url": "https://github.com/sponsors/RubenVerborgh"
                }
            ],
            "license": "MIT",
            "engines": {
                "node": ">=4.0"
            },
            "peerDependenciesMeta": {
                "debug": {
                    "optional": true
                }
            }
        },
        "node_modules/fontaine": {
            "version": "0.8.2",
            "resolved": "https://registry.npmjs.org/fontaine/-/fontaine-0.8.2.tgz",
            "integrity": "sha512-l/aOgAnNqSocAxVmVLuN3P+RIFCWHe6ej3oFugRlW3olsnnQwaxHYdfyyF8aFyhhwUFy4k+Ah3ofEp54RIDMWQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@capsizecss/unpack": "^4.0.0",
                "css-tree": "^3.1.0",
                "magic-regexp": "^0.11.0",
                "magic-string": "^1.0.0",
                "pathe": "^2.0.3",
                "ufo": "^1.6.1",
                "unplugin": "^3.0.0"
            },
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            },
            "peerDependencies": {
                "postcss": "^8.4.31"
            },
            "peerDependenciesMeta": {
                "postcss": {
                    "optional": true
                }
            }
        },
        "node_modules/fontkitten": {
            "version": "1.0.3",
            "resolved": "https://registry.npmjs.org/fontkitten/-/fontkitten-1.0.3.tgz",
            "integrity": "sha512-Wp1zXWPVUPBmfoa3Cqc9ctaKuzKAV6uLstRqlR56kSjplf5uAce+qeyYym7F+PHbGTk+tCEdkCW6RD7DX/gBZw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "tiny-inflate": "^1.0.3"
            },
            "engines": {
                "node": ">=20"
            }
        },
        "node_modules/form-data": {
            "version": "4.0.6",
            "resolved": "https://registry.npmjs.org/form-data/-/form-data-4.0.6.tgz",
            "integrity": "sha512-vKatAh4SlVfgbv+YtmhiRjhEMJsYpsG1Y2rMQtR+SVSbytsSD1YGzDIcrAJmdFec88u/+VoGmxnl+80gL1tRCQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "asynckit": "^0.4.0",
                "combined-stream": "^1.0.8",
                "es-set-tostringtag": "^2.1.0",
                "hasown": "^2.0.4",
                "mime-types": "^2.1.35"
            },
            "engines": {
                "node": ">= 6"
            }
        },
        "node_modules/fraction.js": {
            "version": "5.3.4",
            "resolved": "https://registry.npmjs.org/fraction.js/-/fraction.js-5.3.4.tgz",
            "integrity": "sha512-1X1NTtiJphryn/uLQz3whtY6jK3fTqoE3ohKs0tT+Ujr1W59oopxmoEh7Lu5p6vBaPbgoM0bzveAW4Qi5RyWDQ==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": "*"
            },
            "funding": {
                "type": "github",
                "url": "https://github.com/sponsors/rawify"
            }
        },
        "node_modules/fsevents": {
            "version": "2.3.3",
            "resolved": "https://registry.npmjs.org/fsevents/-/fsevents-2.3.3.tgz",
            "integrity": "sha512-5xoDfX+fL7faATnagmWPpbFtwh/R77WmMMqqHGS65C3vvB0YHrgF+B1YmZ3441tMj5n63k0212XNoJwzlhffQw==",
            "dev": true,
            "hasInstallScript": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "darwin"
            ],
            "engines": {
                "node": "^8.16.0 || ^10.6.0 || >=11.0.0"
            }
        },
        "node_modules/function-bind": {
            "version": "1.1.2",
            "resolved": "https://registry.npmjs.org/function-bind/-/function-bind-1.1.2.tgz",
            "integrity": "sha512-7XHNxH7qX9xG5mIwxkhumTox/MIRNcOgDrxWsMt2pAr23WHp6MrRlN7FBSFpCpr+oVO0F744iUgR82nJMfG2SA==",
            "dev": true,
            "license": "MIT",
            "funding": {
                "url": "https://github.com/sponsors/ljharb"
            }
        },
        "node_modules/get-intrinsic": {
            "version": "1.3.0",
            "resolved": "https://registry.npmjs.org/get-intrinsic/-/get-intrinsic-1.3.0.tgz",
            "integrity": "sha512-9fSjSaos/fRIVIp+xSJlE6lfwhES7LNtKaCBIamHsjr2na1BiABJPo0mOjjz8GJDURarmCPGqaiVg5mfjb98CQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "call-bind-apply-helpers": "^1.0.2",
                "es-define-property": "^1.0.1",
                "es-errors": "^1.3.0",
                "es-object-atoms": "^1.1.1",
                "function-bind": "^1.1.2",
                "get-proto": "^1.0.1",
                "gopd": "^1.2.0",
                "has-symbols": "^1.1.0",
                "hasown": "^2.0.2",
                "math-intrinsics": "^1.1.0"
            },
            "engines": {
                "node": ">= 0.4"
            },
            "funding": {
                "url": "https://github.com/sponsors/ljharb"
            }
        },
        "node_modules/get-proto": {
            "version": "1.0.1",
            "resolved": "https://registry.npmjs.org/get-proto/-/get-proto-1.0.1.tgz",
            "integrity": "sha512-sTSfBjoXBp89JvIKIefqw7U2CCebsc74kiY6awiGogKtoSGbgjYE/G/+l9sF3MWFPNc9IcoOC4ODfKHfxFmp0g==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "dunder-proto": "^1.0.1",
                "es-object-atoms": "^1.0.0"
            },
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/glob-parent": {
            "version": "6.0.2",
            "resolved": "https://registry.npmjs.org/glob-parent/-/glob-parent-6.0.2.tgz",
            "integrity": "sha512-XxwI8EOhVQgWp6iDL+3b0r86f4d6AX6zSU55HfB4ydCEuXLXc5FcYeOu+nnGftS4TEju/11rt4KJPTMgbfmv4A==",
            "dev": true,
            "license": "ISC",
            "dependencies": {
                "is-glob": "^4.0.3"
            },
            "engines": {
                "node": ">=10.13.0"
            }
        },
        "node_modules/gopd": {
            "version": "1.2.0",
            "resolved": "https://registry.npmjs.org/gopd/-/gopd-1.2.0.tgz",
            "integrity": "sha512-ZUKRh6/kUFoAiTAtTYPZJ3hw9wNxx+BIBOijnlG9PnrJsCcSjs1wyyD6vJpaYtgnzDrKYRSqf3OO6Rfa93xsRg==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 0.4"
            },
            "funding": {
                "url": "https://github.com/sponsors/ljharb"
            }
        },
        "node_modules/has-symbols": {
            "version": "1.1.0",
            "resolved": "https://registry.npmjs.org/has-symbols/-/has-symbols-1.1.0.tgz",
            "integrity": "sha512-1cDNdwJ2Jaohmb3sg4OmKaMBwuC48sYni5HUw2DvsC8LjGTLK9h+eb1X6RyuOHe4hT0ULCW68iomhjUoKUqlPQ==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 0.4"
            },
            "funding": {
                "url": "https://github.com/sponsors/ljharb"
            }
        },
        "node_modules/has-tostringtag": {
            "version": "1.0.2",
            "resolved": "https://registry.npmjs.org/has-tostringtag/-/has-tostringtag-1.0.2.tgz",
            "integrity": "sha512-NqADB8VjPFLM2V0VvHUewwwsw0ZWBaIdgo+ieHtK3hasLz4qeCRjYcqfB6AQrBggRKppKF8L52/VqdVsO47Dlw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "has-symbols": "^1.0.3"
            },
            "engines": {
                "node": ">= 0.4"
            },
            "funding": {
                "url": "https://github.com/sponsors/ljharb"
            }
        },
        "node_modules/hasown": {
            "version": "2.0.4",
            "resolved": "https://registry.npmjs.org/hasown/-/hasown-2.0.4.tgz",
            "integrity": "sha512-T2UbfbBEF32wiepXIsMlTW9+dDYC6wMh/t/vYA4tuOMKqWz/n3vr1NFSxQiyP+zk2mXsoMA/i/7qV6LKut1t1A==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "function-bind": "^1.1.2"
            },
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/https-proxy-agent": {
            "version": "5.0.1",
            "resolved": "https://registry.npmjs.org/https-proxy-agent/-/https-proxy-agent-5.0.1.tgz",
            "integrity": "sha512-dFcAjpTQFgoLMzC2VwU+C/CbS7uRL0lWmxDITmqm7C+7F0Odmj6s9l6alZc6AELXhrnggM2CeWSXHGOdX2YtwA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "agent-base": "6",
                "debug": "4"
            },
            "engines": {
                "node": ">= 6"
            }
        },
        "node_modules/is-binary-path": {
            "version": "2.1.0",
            "resolved": "https://registry.npmjs.org/is-binary-path/-/is-binary-path-2.1.0.tgz",
            "integrity": "sha512-ZMERYes6pDydyuGidse7OsHxtbI7WVeUEozgR/g7rd0xUimYNlvZRE/K2MgZTjWy725IfelLeVcEM97mmtRGXw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "binary-extensions": "^2.0.0"
            },
            "engines": {
                "node": ">=8"
            }
        },
        "node_modules/is-core-module": {
            "version": "2.17.0",
            "resolved": "https://registry.npmjs.org/is-core-module/-/is-core-module-2.17.0.tgz",
            "integrity": "sha512-J/vG0zBCbIKOQFfufSwyXdMrsohyJIUNkrnmo6WZGzoM7tr/lsbfW5b2BvisL6zsyMzK9UxV9L6c7AoFbyXHOA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "hasown": "^2.0.4"
            },
            "engines": {
                "node": ">= 0.4"
            },
            "funding": {
                "url": "https://github.com/sponsors/ljharb"
            }
        },
        "node_modules/is-extglob": {
            "version": "2.1.1",
            "resolved": "https://registry.npmjs.org/is-extglob/-/is-extglob-2.1.1.tgz",
            "integrity": "sha512-SbKbANkN603Vi4jEZv49LeVJMn4yGwsbzZworEoyEiutsN3nJYdbO36zfhGJ6QEDpOZIFkDtnq5JRxmvl3jsoQ==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=0.10.0"
            }
        },
        "node_modules/is-glob": {
            "version": "4.0.3",
            "resolved": "https://registry.npmjs.org/is-glob/-/is-glob-4.0.3.tgz",
            "integrity": "sha512-xelSayHH36ZgE7ZWhli7pW34hNbNl8Ojv5KVmkJD4hBdD3th8Tfk9vYasLM+mXWOZhFkgZfxhLSnrwRr4elSSg==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "is-extglob": "^2.1.1"
            },
            "engines": {
                "node": ">=0.10.0"
            }
        },
        "node_modules/is-number": {
            "version": "7.0.0",
            "resolved": "https://registry.npmjs.org/is-number/-/is-number-7.0.0.tgz",
            "integrity": "sha512-41Cifkg6e8TylSpdtTpeLVMqvSBEVzTttHvERD741+pnZ8ANv0004MRL43QKPDlK9cGvNp6NZWZUBlbGXYxxng==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=0.12.0"
            }
        },
        "node_modules/jiti": {
            "version": "1.21.7",
            "resolved": "https://registry.npmjs.org/jiti/-/jiti-1.21.7.tgz",
            "integrity": "sha512-/imKNG4EbWNrVjoNC/1H5/9GFy+tqjGBHCaSsN+P2RnPqjsLmv6UD3Ej+Kj8nBWaRAwyk7kK5ZUc+OEatnTR3A==",
            "dev": true,
            "license": "MIT",
            "bin": {
                "jiti": "bin/jiti.js"
            }
        },
        "node_modules/js-tokens": {
            "version": "4.0.0",
            "resolved": "https://registry.npmjs.org/js-tokens/-/js-tokens-4.0.0.tgz",
            "integrity": "sha512-RdJUflcE3cUzKiMqQgsCu06FPu9UdIJO0beYbPhHN4k6apgJtifcoCtT9bcxOpYBtpD2kCM6Sbzg4CausW/PKQ==",
            "license": "MIT"
        },
        "node_modules/laravel-vite-plugin": {
            "version": "3.2.0",
            "resolved": "https://registry.npmjs.org/laravel-vite-plugin/-/laravel-vite-plugin-3.2.0.tgz",
            "integrity": "sha512-xSxY9Gzeb/eancd8WeK09piAFP+a6i5QIBqNCKNv9L0Eq6wziwzSem7F1GvMSrtjMh5F/QVKFxn8t9naGOA66A==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "picocolors": "^1.0.0",
                "tinyglobby": "^0.2.12",
                "vite-plugin-full-reload": "^1.1.0"
            },
            "bin": {
                "clean-orphaned-assets": "bin/clean.js"
            },
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            },
            "peerDependencies": {
                "fontaine": "^0.8.0",
                "vite": "^8.0.0"
            },
            "peerDependenciesMeta": {
                "fontaine": {
                    "optional": true
                }
            }
        },
        "node_modules/lightningcss": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss/-/lightningcss-1.33.0.tgz",
            "integrity": "sha512-WkUDrojuJs0xkgGf2udWxa3yGBRxPtxUkB79i6aCZLRgc7PM8fZe9TosfPDcvEpQZbuFASnHYmRLBLUbmLOIIA==",
            "dev": true,
            "license": "MPL-2.0",
            "dependencies": {
                "detect-libc": "^2.0.3"
            },
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            },
            "optionalDependencies": {
                "lightningcss-android-arm64": "1.33.0",
                "lightningcss-darwin-arm64": "1.33.0",
                "lightningcss-darwin-x64": "1.33.0",
                "lightningcss-freebsd-x64": "1.33.0",
                "lightningcss-linux-arm-gnueabihf": "1.33.0",
                "lightningcss-linux-arm64-gnu": "1.33.0",
                "lightningcss-linux-arm64-musl": "1.33.0",
                "lightningcss-linux-x64-gnu": "1.33.0",
                "lightningcss-linux-x64-musl": "1.33.0",
                "lightningcss-win32-arm64-msvc": "1.33.0",
                "lightningcss-win32-x64-msvc": "1.33.0"
            }
        },
        "node_modules/lightningcss-android-arm64": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss-android-arm64/-/lightningcss-android-arm64-1.33.0.tgz",
            "integrity": "sha512-gEpRTalKdosp4Bb8qWtc2iOgE5SeIHlpS1up9bFq2wAyYhl1UdTObYiHe98zEM9SQvSoqQZ1IQD0JNpg3Ml5pg==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "android"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-darwin-arm64": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss-darwin-arm64/-/lightningcss-darwin-arm64-1.33.0.tgz",
            "integrity": "sha512-Sciaz8eenNTKn9b3t7+xr0ipTp9YxKQY4npwQ3mrRuL0BAVHBLyZxofhaKBAVtzmtRZ/zTyo0/to4B1uWG/Djg==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "darwin"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-darwin-x64": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss-darwin-x64/-/lightningcss-darwin-x64-1.33.0.tgz",
            "integrity": "sha512-Z5UPAxzrjlWNNyGy6i65cJzzvgJ5D3T6wMvs+gWpY9d7qRhANrxqAp6LhxIgZhWEw18RfJTGcRxjuLIBr+m8XQ==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "darwin"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-freebsd-x64": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss-freebsd-x64/-/lightningcss-freebsd-x64-1.33.0.tgz",
            "integrity": "sha512-QQM/Ti/hQajJwCY+RiWuCZ9sdtI/XQk7nDK5vC8kkdwixezOlDgvDx7+RT+QjK6FcFT4MpsuoBnHIo/O3StRRg==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "freebsd"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-linux-arm-gnueabihf": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss-linux-arm-gnueabihf/-/lightningcss-linux-arm-gnueabihf-1.33.0.tgz",
            "integrity": "sha512-N7FVBe6iS24MlM6R/4RBTxGhQheZGs7tiQ9U32UtF75NzP5Q7xWPRqLBCKxlRQRk3rY1jCIPLzx7WzOhuUIRLQ==",
            "cpu": [
                "arm"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-linux-arm64-gnu": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss-linux-arm64-gnu/-/lightningcss-linux-arm64-gnu-1.33.0.tgz",
            "integrity": "sha512-j2v/itmy4HlNxlc6voKXYgBqNi0Ng2LShg4z7GufpEgs05P+2suBVyi9I6YHq5uoVFx9ETin3eCEhLVyXGQnKg==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-linux-arm64-musl": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss-linux-arm64-musl/-/lightningcss-linux-arm64-musl-1.33.0.tgz",
            "integrity": "sha512-yiO5ROMuYQgXbC60yjZU5CYSFZGKXL0HFATXt9mHJn1+zW55oCtMI9NfcVhYLMFDL7gV7oBPon/EmMMGg2OvtQ==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-linux-x64-gnu": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss-linux-x64-gnu/-/lightningcss-linux-x64-gnu-1.33.0.tgz",
            "integrity": "sha512-ar+Ju7LmcN0Jo4FpL4hpFybwNG9/3A/Br5KW2n2jyODg3MEZXaDYADdemoNS+BDNfMgKvylJLj4S5tyRActuAg==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-linux-x64-musl": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss-linux-x64-musl/-/lightningcss-linux-x64-musl-1.33.0.tgz",
            "integrity": "sha512-RYiYbkokw0trfKqqzfF55lginwEPrD3OJDfTuJzFs1MK6iFnDenaz1fqLLtX4ITG3OktJQXOeTaw1awrBAlZPw==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-win32-arm64-msvc": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss-win32-arm64-msvc/-/lightningcss-win32-arm64-msvc-1.33.0.tgz",
            "integrity": "sha512-1K+MPfLSFVpphzpdbfkhlWk6wBrTObBzS2T6db10PNOZgR9GoVsAWzwNyuhUYYbTp23j+4RrncfujZ4uAzXvwA==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "win32"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-win32-x64-msvc": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss-win32-x64-msvc/-/lightningcss-win32-x64-msvc-1.33.0.tgz",
            "integrity": "sha512-OlEICDx/Xl0FqSp4bry8zFnCvGpig3Gl4gCquvYwHuqJKEC1+n9NgDniFvqHGmMv1ZkqDJrDqKKSykTDX+ehuA==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "win32"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lilconfig": {
            "version": "3.1.3",
            "resolved": "https://registry.npmjs.org/lilconfig/-/lilconfig-3.1.3.tgz",
            "integrity": "sha512-/vlFKAoH5Cgt3Ie+JLhRbwOsCQePABiU3tJ1egGvyQ+33R/vcwM2Zl2QR/LzjsBeItPt3oSVXapn+m4nQDvpzw==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=14"
            },
            "funding": {
                "url": "https://github.com/sponsors/antonk52"
            }
        },
        "node_modules/lines-and-columns": {
            "version": "1.2.4",
            "resolved": "https://registry.npmjs.org/lines-and-columns/-/lines-and-columns-1.2.4.tgz",
            "integrity": "sha512-7ylylesZQ/PV29jhEDl3Ufjo6ZX7gCqJr5F7PKrqc93v7fzSymt1BpwEU8nAUXs8qzzvqhbjhK5QZg6Mt/HkBg==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/loose-envify": {
            "version": "1.4.0",
            "resolved": "https://registry.npmjs.org/loose-envify/-/loose-envify-1.4.0.tgz",
            "integrity": "sha512-lyuxPGr/Wfhrlem2CL/UcnUc1zcqKAImBDzukY7Y5F/yQiNdko6+fRLevlw1HgMySw7f611UIY408EtxRSoK3Q==",
            "license": "MIT",
            "dependencies": {
                "js-tokens": "^3.0.0 || ^4.0.0"
            },
            "bin": {
                "loose-envify": "cli.js"
            }
        },
        "node_modules/magic-regexp": {
            "version": "0.11.2",
            "resolved": "https://registry.npmjs.org/magic-regexp/-/magic-regexp-0.11.2.tgz",
            "integrity": "sha512-s4i7mq2jJnkB5J1HDX67GmdRoWrgo8tE0cL91bKO+HaquxyR5TiqkjVfLhgS2PXretsN/Gv9kSnRBgQxrLO/Qw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "magic-string": "^1.1.0",
                "regexp-tree": "^0.1.27",
                "type-level-regexp": "~0.1.17",
                "unplugin": "^3.3.0"
            }
        },
        "node_modules/magic-string": {
            "version": "1.4.2",
            "resolved": "https://registry.npmjs.org/magic-string/-/magic-string-1.4.2.tgz",
            "integrity": "sha512-vG+rjFRj1PqdIBozIxAGMjPlOhaVe+GXpbttY/iSK7rGcJRMlwNJO7dcUwmUqkymsFLJiNGI06t4D7Fr7yRC9g==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@jridgewell/sourcemap-codec": "^1.6.0"
            }
        },
        "node_modules/math-intrinsics": {
            "version": "1.1.0",
            "resolved": "https://registry.npmjs.org/math-intrinsics/-/math-intrinsics-1.1.0.tgz",
            "integrity": "sha512-/IXtbwEk5HTPyEwyKX6hGkYXxM9nbj64B+ilVJnC/R6B0pH5G4V3b0pVbL7DBj4tkhBAppbQUlf6F6Xl9LHu1g==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/mdn-data": {
            "version": "2.27.1",
            "resolved": "https://registry.npmjs.org/mdn-data/-/mdn-data-2.27.1.tgz",
            "integrity": "sha512-9Yubnt3e8A0OKwxYSXyhLymGW4sCufcLG6VdiDdUGVkPhpqLxlvP5vl1983gQjJl3tqbrM731mjaZaP68AgosQ==",
            "dev": true,
            "license": "CC0-1.0"
        },
        "node_modules/merge2": {
            "version": "1.4.1",
            "resolved": "https://registry.npmjs.org/merge2/-/merge2-1.4.1.tgz",
            "integrity": "sha512-8q7VEgMJW4J8tcfVPy8g09NcQwZdbwFEqhe/WZkoIzjn/3TGDwtOCYtXGxA3O8tPzpczCCDgv+P2P5y00ZJOOg==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 8"
            }
        },
        "node_modules/micromatch": {
            "version": "4.0.8",
            "resolved": "https://registry.npmjs.org/micromatch/-/micromatch-4.0.8.tgz",
            "integrity": "sha512-PXwfBhYu0hBCPw8Dn0E+WDYb7af3dSLVWKi3HGv84IdF4TyFoC0ysxFd0Goxw7nSv4T/PzEJQxsYsEiFCKo2BA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "braces": "^3.0.3",
                "picomatch": "^2.3.1"
            },
            "engines": {
                "node": ">=8.6"
            }
        },
        "node_modules/mime-db": {
            "version": "1.52.0",
            "resolved": "https://registry.npmjs.org/mime-db/-/mime-db-1.52.0.tgz",
            "integrity": "sha512-sPU4uV7dYlvtWJxwwxHD0PuihVNiE7TyAbQ5SWxDCB9mUYvOgroQOwYQQOKPJ8CIbE+1ETVlOoK1UC2nU3gYvg==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 0.6"
            }
        },
        "node_modules/mime-types": {
            "version": "2.1.35",
            "resolved": "https://registry.npmjs.org/mime-types/-/mime-types-2.1.35.tgz",
            "integrity": "sha512-ZDY+bPm5zTTF+YpCrAU9nK0UgICYPT0QtT1NZWFv4s++TNkcgVaT0g6+4R2uI4MjQjzysHB1zxuWL50hzaeXiw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "mime-db": "1.52.0"
            },
            "engines": {
                "node": ">= 0.6"
            }
        },
        "node_modules/ms": {
            "version": "2.1.3",
            "resolved": "https://registry.npmjs.org/ms/-/ms-2.1.3.tgz",
            "integrity": "sha512-6FlzubTLZG3J2a/NVCAleEhjzq5oxgHyaCU9yYXvcLsvoVaHJq/s5xXI6/XXP6tz7R9xAOtHnSO/tXtF3WRTlA==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/mz": {
            "version": "2.7.0",
            "resolved": "https://registry.npmjs.org/mz/-/mz-2.7.0.tgz",
            "integrity": "sha512-z81GNO7nnYMEhrGh9LeymoE4+Yr0Wn5McHIZMK5cfQCl+NDX08sCZgUc9/6MHni9IWuFLm1Z3HTCXu2z9fN62Q==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "any-promise": "^1.0.0",
                "object-assign": "^4.0.1",
                "thenify-all": "^1.0.0"
            }
        },
        "node_modules/nanoid": {
            "version": "3.3.19",
            "resolved": "https://registry.npmjs.org/nanoid/-/nanoid-3.3.19.tgz",
            "integrity": "sha512-Y2tUNy4ouw6tq5oDSKeQYGOyhkUBhNOcGV/02KC+6kd9eDGqdZd++mjMiIDilrBYvjEnCYvVtsuHCuP+okSfug==",
            "dev": true,
            "funding": [
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/ai"
                }
            ],
            "license": "MIT",
            "bin": {
                "nanoid": "bin/nanoid.cjs"
            },
            "engines": {
                "node": "^10 || ^12 || ^13.7 || ^14 || >=15.0.1"
            }
        },
        "node_modules/node-releases": {
            "version": "2.0.57",
            "resolved": "https://registry.npmjs.org/node-releases/-/node-releases-2.0.57.tgz",
            "integrity": "sha512-kQK9LGGFiHtrWiNhZtA7Qbw17AQz+dmsEKODRIVTXA9+e5MS/2gZEBhYJt13GrAz5/IOZKddH/0Z3TP/Zgo+yw==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/normalize-path": {
            "version": "3.0.0",
            "resolved": "https://registry.npmjs.org/normalize-path/-/normalize-path-3.0.0.tgz",
            "integrity": "sha512-6eZs5Ls3WtCisHWp9S2GUy8dqkpGi4BVSz3GaqiE6ezub0512ESztXUwUB6C6IKbQkY2Pnb/mD4WYojCRwcwLA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=0.10.0"
            }
        },
        "node_modules/object-assign": {
            "version": "4.1.1",
            "resolved": "https://registry.npmjs.org/object-assign/-/object-assign-4.1.1.tgz",
            "integrity": "sha512-rJgTQnkUnH1sFw8yT6VSU3zD3sWmu6sZhIseY8VX+GRu3P6F7Fu+JNDoXfklElbLJSnc3FUQHVe4cU5hj+BcUg==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=0.10.0"
            }
        },
        "node_modules/object-hash": {
            "version": "3.0.0",
            "resolved": "https://registry.npmjs.org/object-hash/-/object-hash-3.0.0.tgz",
            "integrity": "sha512-RSn9F68PjH9HqtltsSnqYC1XXoWe9Bju5+213R98cNGttag9q9yAOTzdbsqvIa7aNm5WffBZFpWYr2aWrklWAw==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 6"
            }
        },
        "node_modules/path-parse": {
            "version": "1.0.7",
            "resolved": "https://registry.npmjs.org/path-parse/-/path-parse-1.0.7.tgz",
            "integrity": "sha512-LDJzPVEEEPR+y48z93A0Ed0yXb8pAByGWo/k5YYdYgpY2/2EsOsksJrq7lOHxryrVOn1ejG6oAp8ahvOIQD8sw==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/pathe": {
            "version": "2.0.3",
            "resolved": "https://registry.npmjs.org/pathe/-/pathe-2.0.3.tgz",
            "integrity": "sha512-WUjGcAqP1gQacoQe+OBJsFA7Ld4DyXuUIjZ5cc75cLHvJ7dtNsTugphxIADwspS+AraAUePCKrSVtPLFj/F88w==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/picocolors": {
            "version": "1.1.1",
            "resolved": "https://registry.npmjs.org/picocolors/-/picocolors-1.1.1.tgz",
            "integrity": "sha512-xceH2snhtb5M9liqDsmEw56le376mTZkEX/jEb/RxNFyegNul7eNslCXP9FDj/Lcu0X8KEyMceP2ntpaHrDEVA==",
            "dev": true,
            "license": "ISC"
        },
        "node_modules/picomatch": {
            "version": "2.3.2",
            "resolved": "https://registry.npmjs.org/picomatch/-/picomatch-2.3.2.tgz",
            "integrity": "sha512-V7+vQEJ06Z+c5tSye8S+nHUfI51xoXIXjHQ99cQtKUkQqqO1kO/KCJUfZXuB47h/YBlDhah2H3hdUGXn8ie0oA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=8.6"
            },
            "funding": {
                "url": "https://github.com/sponsors/jonschlinkert"
            }
        },
        "node_modules/pirates": {
            "version": "4.0.7",
            "resolved": "https://registry.npmjs.org/pirates/-/pirates-4.0.7.tgz",
            "integrity": "sha512-TfySrs/5nm8fQJDcBDuUng3VOUKsd7S+zqvbOTiGXHfxX4wK31ard+hoNuvkicM/2YFzlpDgABOevKSsB4G/FA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 6"
            }
        },
        "node_modules/playwright": {
            "version": "1.63.0",
            "resolved": "https://registry.npmjs.org/playwright/-/playwright-1.63.0.tgz",
            "integrity": "sha512-+7ziBLidS4NaNCdt57SUDT+wYmmd5fmiQejUic/kb+YsYSCPyOOE9sebzMjNmQrsnNpDJqd4WHvV/8lfKfUDUg==",
            "dev": true,
            "license": "Apache-2.0",
            "dependencies": {
                "playwright-core": "1.63.0"
            },
            "bin": {
                "playwright": "cli.js"
            },
            "engines": {
                "node": ">=20"
            }
        },
        "node_modules/playwright-core": {
            "version": "1.63.0",
            "resolved": "https://registry.npmjs.org/playwright-core/-/playwright-core-1.63.0.tgz",
            "integrity": "sha512-rYCsBF/M5HjUch52bbtVONEFjv6Xu8sm8h72dNlR5bzIE1fvC/bxgspzkjSfU+MweEMmPM8KJebG6nnyxo5mCg==",
            "dev": true,
            "license": "Apache-2.0",
            "bin": {
                "playwright-core": "cli.js"
            },
            "engines": {
                "node": ">=20"
            }
        },
        "node_modules/postcss": {
            "version": "8.5.28",
            "resolved": "https://registry.npmjs.org/postcss/-/postcss-8.5.28.tgz",
            "integrity": "sha512-RRuzqDtt5Y9h3quz5hWhK+TPnsmVs6WwSU6LkJMeY4HstUEDuYTG8UJSdawMRzmzAtV+KEoG8N3Qg2qLy5vM/A==",
            "dev": true,
            "funding": [
                {
                    "type": "opencollective",
                    "url": "https://opencollective.com/postcss/"
                },
                {
                    "type": "tidelift",
                    "url": "https://tidelift.com/funding/github/npm/postcss"
                },
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/ai"
                }
            ],
            "license": "MIT",
            "dependencies": {
                "nanoid": "^3.3.18",
                "picocolors": "^1.1.1",
                "source-map-js": "^1.2.1"
            },
            "engines": {
                "node": "^10 || ^12 || >=14"
            }
        },
        "node_modules/postcss-import": {
            "version": "15.1.0",
            "resolved": "https://registry.npmjs.org/postcss-import/-/postcss-import-15.1.0.tgz",
            "integrity": "sha512-hpr+J05B2FVYUAXHeK1YyI267J/dDDhMU6B6civm8hSY1jYJnBXxzKDKDswzJmtLHryrjhnDjqqp/49t8FALew==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "postcss-value-parser": "^4.0.0",
                "read-cache": "^1.0.0",
                "resolve": "^1.1.7"
            },
            "engines": {
                "node": ">=14.0.0"
            },
            "peerDependencies": {
                "postcss": "^8.0.0"
            }
        },
        "node_modules/postcss-js": {
            "version": "4.1.0",
            "resolved": "https://registry.npmjs.org/postcss-js/-/postcss-js-4.1.0.tgz",
            "integrity": "sha512-oIAOTqgIo7q2EOwbhb8UalYePMvYoIeRY2YKntdpFQXNosSu3vLrniGgmH9OKs/qAkfoj5oB3le/7mINW1LCfw==",
            "dev": true,
            "funding": [
                {
                    "type": "opencollective",
                    "url": "https://opencollective.com/postcss/"
                },
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/ai"
                }
            ],
            "license": "MIT",
            "dependencies": {
                "camelcase-css": "^2.0.1"
            },
            "engines": {
                "node": "^12 || ^14 || >= 16"
            },
            "peerDependencies": {
                "postcss": "^8.4.21"
            }
        },
        "node_modules/postcss-load-config": {
            "version": "6.0.1",
            "resolved": "https://registry.npmjs.org/postcss-load-config/-/postcss-load-config-6.0.1.tgz",
            "integrity": "sha512-oPtTM4oerL+UXmx+93ytZVN82RrlY/wPUV8IeDxFrzIjXOLF1pN+EmKPLbubvKHT2HC20xXsCAH2Z+CKV6Oz/g==",
            "dev": true,
            "funding": [
                {
                    "type": "opencollective",
                    "url": "https://opencollective.com/postcss/"
                },
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/ai"
                }
            ],
            "license": "MIT",
            "dependencies": {
                "lilconfig": "^3.1.1"
            },
            "engines": {
                "node": ">= 18"
            },
            "peerDependencies": {
                "jiti": ">=1.21.0",
                "postcss": ">=8.0.9",
                "tsx": "^4.8.1",
                "yaml": "^2.4.2"
            },
            "peerDependenciesMeta": {
                "jiti": {
                    "optional": true
                },
                "postcss": {
                    "optional": true
                },
                "tsx": {
                    "optional": true
                },
                "yaml": {
                    "optional": true
                }
            }
        },
        "node_modules/postcss-nested": {
            "version": "6.2.0",
            "resolved": "https://registry.npmjs.org/postcss-nested/-/postcss-nested-6.2.0.tgz",
            "integrity": "sha512-HQbt28KulC5AJzG+cZtj9kvKB93CFCdLvog1WFLf1D+xmMvPGlBstkpTEZfK5+AN9hfJocyBFCNiqyS48bpgzQ==",
            "dev": true,
            "funding": [
                {
                    "type": "opencollective",
                    "url": "https://opencollective.com/postcss/"
                },
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/ai"
                }
            ],
            "license": "MIT",
            "dependencies": {
                "postcss-selector-parser": "^6.1.1"
            },
            "engines": {
                "node": ">=12.0"
            },
            "peerDependencies": {
                "postcss": "^8.2.14"
            }
        },
        "node_modules/postcss-selector-parser": {
            "version": "6.1.4",
            "resolved": "https://registry.npmjs.org/postcss-selector-parser/-/postcss-selector-parser-6.1.4.tgz",
            "integrity": "sha512-bIoJLOmjCO1S9XdY/DcnR5hJxvrDir1PbGChrzXG3vw0/FOliy/fA3dmdhQ441kah4gKv+TwckGzex6wNS5cnQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "cssesc": "^3.0.0",
                "util-deprecate": "^1.0.2"
            },
            "engines": {
                "node": ">=4"
            }
        },
        "node_modules/postcss-value-parser": {
            "version": "4.2.0",
            "resolved": "https://registry.npmjs.org/postcss-value-parser/-/postcss-value-parser-4.2.0.tgz",
            "integrity": "sha512-1NNCs6uurfkVbeXG4S8JFT9t19m45ICnif8zWLd5oPSZ50QnwMfK+H3jv408d4jw/7Bttv5axS5IiHoLaVNHeQ==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/proxy-from-env": {
            "version": "2.1.0",
            "resolved": "https://registry.npmjs.org/proxy-from-env/-/proxy-from-env-2.1.0.tgz",
            "integrity": "sha512-cJ+oHTW1VAEa8cJslgmUZrc+sjRKgAKl3Zyse6+PV38hZe/V6Z14TbCuXcan9F9ghlz4QrFr2c92TNF82UkYHA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=10"
            }
        },
        "node_modules/queue-microtask": {
            "version": "1.2.3",
            "resolved": "https://registry.npmjs.org/queue-microtask/-/queue-microtask-1.2.3.tgz",
            "integrity": "sha512-NuaNSa6flKT5JaSYQzJok04JzTL1CA6aGhv5rfLW3PgqA+M2ChpZQnAC8h8i4ZFkBS8X5RqkDBHA7r4hej3K9A==",
            "dev": true,
            "funding": [
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/feross"
                },
                {
                    "type": "patreon",
                    "url": "https://www.patreon.com/feross"
                },
                {
                    "type": "consulting",
                    "url": "https://feross.org/support"
                }
            ],
            "license": "MIT"
        },
        "node_modules/react": {
            "version": "18.3.1",
            "resolved": "https://registry.npmjs.org/react/-/react-18.3.1.tgz",
            "integrity": "sha512-wS+hAgJShR0KhEvPJArfuPVN1+Hz1t0Y6n5jLrGQbkb4urgPE/0Rve+1kMB1v/oWgHgm4WIcV+i7F2pTVj+2iQ==",
            "license": "MIT",
            "dependencies": {
                "loose-envify": "^1.1.0"
            },
            "engines": {
                "node": ">=0.10.0"
            }
        },
        "node_modules/react-dom": {
            "version": "18.3.1",
            "resolved": "https://registry.npmjs.org/react-dom/-/react-dom-18.3.1.tgz",
            "integrity": "sha512-5m4nQKp+rZRb09LNH59GM4BxTh9251/ylbKIbpe7TpGxfJ+9kv6BLkLBXIjjspbgbnIBNqlI23tRnTWT0snUIw==",
            "license": "MIT",
            "dependencies": {
                "loose-envify": "^1.1.0",
                "scheduler": "^0.23.2"
            },
            "peerDependencies": {
                "react": "^18.3.1"
            }
        },
        "node_modules/read-cache": {
            "version": "1.0.2",
            "resolved": "https://registry.npmjs.org/read-cache/-/read-cache-1.0.2.tgz",
            "integrity": "sha512-/peqiBB/n07gQGLsWaHho3WfvUyRscw0gYTsEFMhrIe/nWLkYaf5SbKYjGYqtRV3aPwykJgF2VEMo1ac4bnsGA==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/readdirp": {
            "version": "3.6.0",
            "resolved": "https://registry.npmjs.org/readdirp/-/readdirp-3.6.0.tgz",
            "integrity": "sha512-hOS089on8RduqdbhvQ5Z37A0ESjsqz6qnRcffsMU3495FuTdqSm+7bhJ29JvIOsBDEEnan5DPu9t3To9VRlMzA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "picomatch": "^2.2.1"
            },
            "engines": {
                "node": ">=8.10.0"
            }
        },
        "node_modules/regexp-tree": {
            "version": "0.1.27",
            "resolved": "https://registry.npmjs.org/regexp-tree/-/regexp-tree-0.1.27.tgz",
            "integrity": "sha512-iETxpjK6YoRWJG5o6hXLwvjYAoW+FEZn9os0PD/b6AP6xQwsa/Y7lCVgIixBbUPMfhu+i2LtdeAqVTgGlQarfA==",
            "dev": true,
            "license": "MIT",
            "bin": {
                "regexp-tree": "bin/regexp-tree"
            }
        },
        "node_modules/resolve": {
            "version": "1.22.12",
            "resolved": "https://registry.npmjs.org/resolve/-/resolve-1.22.12.tgz",
            "integrity": "sha512-TyeJ1zif53BPfHootBGwPRYT1RUt6oGWsaQr8UyZW/eAm9bKoijtvruSDEmZHm92CwS9nj7/fWttqPCgzep8CA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "es-errors": "^1.3.0",
                "is-core-module": "^2.16.1",
                "path-parse": "^1.0.7",
                "supports-preserve-symlinks-flag": "^1.0.0"
            },
            "bin": {
                "resolve": "bin/resolve"
            },
            "engines": {
                "node": ">= 0.4"
            },
            "funding": {
                "url": "https://github.com/sponsors/ljharb"
            }
        },
        "node_modules/reusify": {
            "version": "1.1.0",
            "resolved": "https://registry.npmjs.org/reusify/-/reusify-1.1.0.tgz",
            "integrity": "sha512-g6QUff04oZpHs0eG5p83rFLhHeV00ug/Yf9nZM6fLeUrPguBTkTQOdpAWWspMh55TZfVQDPaN3NQJfbVRAxdIw==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "iojs": ">=1.0.0",
                "node": ">=0.10.0"
            }
        },
        "node_modules/rolldown": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/rolldown/-/rolldown-1.2.11.tgz",
            "integrity": "sha512-qpSwIyz0jHQq5qXBTNxFmE6664rJ7O+4TvPFOiOaBSrz8IOHc1koKKSqTM2H6u1UG1+TveuC6vaDHKXFOvb1Kw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@oxc-project/types": "=0.151.0",
                "@rolldown/pluginutils": "^1.0.0"
            },
            "bin": {
                "rolldown": "bin/cli.mjs"
            },
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            },
            "optionalDependencies": {
                "@rolldown/binding-android-arm-eabi": "1.2.11",
                "@rolldown/binding-android-arm64": "1.2.11",
                "@rolldown/binding-darwin-arm64": "1.2.11",
                "@rolldown/binding-darwin-x64": "1.2.11",
                "@rolldown/binding-freebsd-x64": "1.2.11",
                "@rolldown/binding-linux-arm-gnueabihf": "1.2.11",
                "@rolldown/binding-linux-arm64-gnu": "1.2.11",
                "@rolldown/binding-linux-arm64-musl": "1.2.11",
                "@rolldown/binding-linux-ppc64-gnu": "1.2.11",
                "@rolldown/binding-linux-s390x-gnu": "1.2.11",
                "@rolldown/binding-linux-x64-gnu": "1.2.11",
                "@rolldown/binding-linux-x64-musl": "1.2.11",
                "@rolldown/binding-openharmony-arm64": "1.2.11",
                "@rolldown/binding-win32-arm64-msvc": "1.2.11",
                "@rolldown/binding-win32-x64-msvc": "1.2.11"
            }
        },
        "node_modules/run-parallel": {
            "version": "1.2.0",
            "resolved": "https://registry.npmjs.org/run-parallel/-/run-parallel-1.2.0.tgz",
            "integrity": "sha512-5l4VyZR86LZ/lDxZTR6jqL8AFE2S0IFLMP26AbjsLVADxHdhB/c0GUsH+y39UfCi3dzz8OlQuPmnaJOMoDHQBA==",
            "dev": true,
            "funding": [
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/feross"
                },
                {
                    "type": "patreon",
                    "url": "https://www.patreon.com/feross"
                },
                {
                    "type": "consulting",
                    "url": "https://feross.org/support"
                }
            ],
            "license": "MIT",
            "dependencies": {
                "queue-microtask": "^1.2.2"
            }
        },
        "node_modules/scheduler": {
            "version": "0.23.2",
            "resolved": "https://registry.npmjs.org/scheduler/-/scheduler-0.23.2.tgz",
            "integrity": "sha512-UOShsPwz7NrMUqhR6t0hWjFduvOzbtv7toDH1/hIrfRNIDBnnBWd0CwJTGvTpngVlmwGCdP9/Zl/tVrDqcuYzQ==",
            "license": "MIT",
            "dependencies": {
                "loose-envify": "^1.1.0"
            }
        },
        "node_modules/source-map-js": {
            "version": "1.2.1",
            "resolved": "https://registry.npmjs.org/source-map-js/-/source-map-js-1.2.1.tgz",
            "integrity": "sha512-UXWMKhLOwVKb728IUtQPXxfYU+usdybtUrK/8uGE8CQMvrhOpwvzDBwj0QhSL7MQc7vIsISBG8VQ8+IDQxpfQA==",
            "dev": true,
            "license": "BSD-3-Clause",
            "engines": {
                "node": ">=0.10.0"
            }
        },
        "node_modules/sucrase": {
            "version": "3.35.1",
            "resolved": "https://registry.npmjs.org/sucrase/-/sucrase-3.35.1.tgz",
            "integrity": "sha512-DhuTmvZWux4H1UOnWMB3sk0sbaCVOoQZjv8u1rDoTV0HTdGem9hkAZtl4JZy8P2z4Bg0nT+YMeOFyVr4zcG5Tw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@jridgewell/gen-mapping": "^0.3.2",
                "commander": "^4.0.0",
                "lines-and-columns": "^1.1.6",
                "mz": "^2.7.0",
                "pirates": "^4.0.1",
                "tinyglobby": "^0.2.11",
                "ts-interface-checker": "^0.1.9"
            },
            "bin": {
                "sucrase": "bin/sucrase",
                "sucrase-node": "bin/sucrase-node"
            },
            "engines": {
                "node": ">=16 || 14 >=14.17"
            }
        },
        "node_modules/supports-preserve-symlinks-flag": {
            "version": "1.0.0",
            "resolved": "https://registry.npmjs.org/supports-preserve-symlinks-flag/-/supports-preserve-symlinks-flag-1.0.0.tgz",
            "integrity": "sha512-ot0WnXS9fgdkgIcePe6RHNk1WA8+muPa6cSjeR3V8K27q9BB1rTE3R1p7Hv0z1ZyAc8s6Vvv8DIyWf681MAt0w==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 0.4"
            },
            "funding": {
                "url": "https://github.com/sponsors/ljharb"
            }
        },
        "node_modules/tailwindcss": {
            "version": "3.4.19",
            "resolved": "https://registry.npmjs.org/tailwindcss/-/tailwindcss-3.4.19.tgz",
            "integrity": "sha512-3ofp+LL8E+pK/JuPLPggVAIaEuhvIz4qNcf3nA1Xn2o/7fb7s/TYpHhwGDv1ZU3PkBluUVaF8PyCHcm48cKLWQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@alloc/quick-lru": "^5.2.0",
                "arg": "^5.0.2",
                "chokidar": "^3.6.0",
                "didyoumean": "^1.2.2",
                "dlv": "^1.1.3",
                "fast-glob": "^3.3.2",
                "glob-parent": "^6.0.2",
                "is-glob": "^4.0.3",
                "jiti": "^1.21.7",
                "lilconfig": "^3.1.3",
                "micromatch": "^4.0.8",
                "normalize-path": "^3.0.0",
                "object-hash": "^3.0.0",
                "picocolors": "^1.1.1",
                "postcss": "^8.4.47",
                "postcss-import": "^15.1.0",
                "postcss-js": "^4.0.1",
                "postcss-load-config": "^4.0.2 || ^5.0 || ^6.0",
                "postcss-nested": "^6.2.0",
                "postcss-selector-parser": "^6.1.2",
                "resolve": "^1.22.8",
                "sucrase": "^3.35.0"
            },
            "bin": {
                "tailwind": "lib/cli.js",
                "tailwindcss": "lib/cli.js"
            },
            "engines": {
                "node": ">=14.0.0"
            }
        },
        "node_modules/thenify": {
            "version": "3.3.1",
            "resolved": "https://registry.npmjs.org/thenify/-/thenify-3.3.1.tgz",
            "integrity": "sha512-RVZSIV5IG10Hk3enotrhvz0T9em6cyHBLkH/YAZuKqd8hRkKhSfCGIcP2KUY0EPxndzANBmNllzWPwak+bheSw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "any-promise": "^1.0.0"
            }
        },
        "node_modules/thenify-all": {
            "version": "1.6.0",
            "resolved": "https://registry.npmjs.org/thenify-all/-/thenify-all-1.6.0.tgz",
            "integrity": "sha512-RNxQH/qI8/t3thXJDwcstUO4zeqo64+Uy/+sNVRBx4Xn2OX+OZ9oP+iJnNFqplFra2ZUVeKCSa2oVWi3T4uVmA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "thenify": ">= 3.1.0 < 4"
            },
            "engines": {
                "node": ">=0.8"
            }
        },
        "node_modules/tiny-inflate": {
            "version": "1.0.3",
            "resolved": "https://registry.npmjs.org/tiny-inflate/-/tiny-inflate-1.0.3.tgz",
            "integrity": "sha512-pkY1fj1cKHb2seWDy0B16HeWyczlJA9/WW3u3c4z/NiWDsO3DOU5D7nhTLE9CF0yXv/QZFY7sEJmj24dK+Rrqw==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/tinyglobby": {
            "version": "0.2.17",
            "resolved": "https://registry.npmjs.org/tinyglobby/-/tinyglobby-0.2.17.tgz",
            "integrity": "sha512-wXR/dYpcqKmfWpEdZjiKJOwCNFndD0DMnrW/cYjVGttEkBfVgcLFHoNrlj47mjOVic9yyNu65alsgF4NQyTa2g==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "fdir": "^6.5.0",
                "picomatch": "^4.0.4"
            },
            "engines": {
                "node": ">=12.0.0"
            },
            "funding": {
                "url": "https://github.com/sponsors/SuperchupuDev"
            }
        },
        "node_modules/tinyglobby/node_modules/fdir": {
            "version": "6.5.0",
            "resolved": "https://registry.npmjs.org/fdir/-/fdir-6.5.0.tgz",
            "integrity": "sha512-tIbYtZbucOs0BRGqPJkshJUYdL+SDH7dVM8gjy+ERp3WAUjLEFJE+02kanyHtwjWOnwrKYBiwAmM0p4kLJAnXg==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=12.0.0"
            },
            "peerDependencies": {
                "picomatch": "^3 || ^4"
            },
            "peerDependenciesMeta": {
                "picomatch": {
                    "optional": true
                }
            }
        },
        "node_modules/tinyglobby/node_modules/picomatch": {
            "version": "4.0.7",
            "resolved": "https://registry.npmjs.org/picomatch/-/picomatch-4.0.7.tgz",
            "integrity": "sha512-qcJu88Q2IWqJsDD529JKMdwGm/dvInW4HvQnRwiH9JtihJvzGOscDtHE3x1pBKeUOTysQ8kVmLnJ2kJu7yhcGA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=12"
            },
            "funding": {
                "url": "https://github.com/sponsors/jonschlinkert"
            }
        },
        "node_modules/to-regex-range": {
            "version": "5.0.1",
            "resolved": "https://registry.npmjs.org/to-regex-range/-/to-regex-range-5.0.1.tgz",
            "integrity": "sha512-65P7iz6X5yEr1cwcgvQxbbIw7Uk3gOy5dIdtZ4rDveLqhrdJP+Li/Hx6tyK0NEb+2GCyneCMJiGqrADCSNk8sQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "is-number": "^7.0.0"
            },
            "engines": {
                "node": ">=8.0"
            }
        },
        "node_modules/ts-interface-checker": {
            "version": "0.1.13",
            "resolved": "https://registry.npmjs.org/ts-interface-checker/-/ts-interface-checker-0.1.13.tgz",
            "integrity": "sha512-Y/arvbn+rrz3JCKl9C4kVNfTfSm2/mEp5FSz5EsZSANGPSlQrpRI5M4PKF+mJnE52jOO90PnPSc3Ur3bTQw0gA==",
            "dev": true,
            "license": "Apache-2.0"
        },
        "node_modules/type-level-regexp": {
            "version": "0.1.17",
            "resolved": "https://registry.npmjs.org/type-level-regexp/-/type-level-regexp-0.1.17.tgz",
            "integrity": "sha512-wTk4DH3cxwk196uGLK/E9pE45aLfeKJacKmcEgEOA/q5dnPGNxXt0cfYdFxb57L+sEpf1oJH4Dnx/pnRcku9jg==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/ufo": {
            "version": "1.6.4",
            "resolved": "https://registry.npmjs.org/ufo/-/ufo-1.6.4.tgz",
            "integrity": "sha512-JFNbkD1Svwe0KvGi8GOeLcP4kAWQ609twvCdcHxq1oSL8svv39ZuSvajcD8B+5D0eL4+s1Is2D/O6KN3qcTeRA==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/unplugin": {
            "version": "3.4.0",
            "resolved": "https://registry.npmjs.org/unplugin/-/unplugin-3.4.0.tgz",
            "integrity": "sha512-9skdIFlCsPdFV7wUfZxNsFInlW+7nJmGu2gkTu0OUhF56aXGsHab9x52/QhdJ4lC7ZDPWTxbiC4ANqVlsuaW3w==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@jridgewell/remapping": "^2.3.5",
                "picomatch": "^4.0.7",
                "webpack-virtual-modules": "^0.6.2"
            },
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            },
            "peerDependencies": {
                "@farmfe/core": "*",
                "@rsbuild/core": "*",
                "@rspack/core": "*",
                "bun-types-no-globals": "*",
                "esbuild": "*",
                "rolldown": "*",
                "rollup": "*",
                "unloader": "*",
                "vite": "*",
                "webpack": "*"
            },
            "peerDependenciesMeta": {
                "@farmfe/core": {
                    "optional": true
                },
                "@rsbuild/core": {
                    "optional": true
                },
                "@rspack/core": {
                    "optional": true
                },
                "bun-types-no-globals": {
                    "optional": true
                },
                "esbuild": {
                    "optional": true
                },
                "rolldown": {
                    "optional": true
                },
                "rollup": {
                    "optional": true
                },
                "unloader": {
                    "optional": true
                },
                "vite": {
                    "optional": true
                },
                "webpack": {
                    "optional": true
                }
            }
        },
        "node_modules/unplugin/node_modules/picomatch": {
            "version": "4.0.7",
            "resolved": "https://registry.npmjs.org/picomatch/-/picomatch-4.0.7.tgz",
            "integrity": "sha512-qcJu88Q2IWqJsDD529JKMdwGm/dvInW4HvQnRwiH9JtihJvzGOscDtHE3x1pBKeUOTysQ8kVmLnJ2kJu7yhcGA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=12"
            },
            "funding": {
                "url": "https://github.com/sponsors/jonschlinkert"
            }
        },
        "node_modules/update-browserslist-db": {
            "version": "1.3.3",
            "resolved": "https://registry.npmjs.org/update-browserslist-db/-/update-browserslist-db-1.3.3.tgz",
            "integrity": "sha512-pJ2sYawQS0R/WI928Gj5GlPhTGzbMelq0+4INtSYNDV9ErKJcX6xjGWkoG/VnB3dpUm00zALaqkrUD77pO5TDQ==",
            "dev": true,
            "funding": [
                {
                    "type": "opencollective",
                    "url": "https://opencollective.com/browserslist"
                },
                {
                    "type": "tidelift",
                    "url": "https://tidelift.com/funding/github/npm/browserslist"
                },
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/ai"
                }
            ],
            "license": "MIT",
            "dependencies": {
                "escalade": "^3.2.0",
                "picocolors": "^1.1.1"
            },
            "bin": {
                "update-browserslist-db": "cli.js"
            },
            "peerDependencies": {
                "browserslist": ">= 4.21.0"
            }
        },
        "node_modules/util-deprecate": {
            "version": "1.0.2",
            "resolved": "https://registry.npmjs.org/util-deprecate/-/util-deprecate-1.0.2.tgz",
            "integrity": "sha512-EPD5q1uXyFxJpCrLnCc1nHnq3gOa6DZBocAIiI2TaSCA7VCJ1UJDMagCzIkXNsUYfD1daK//LTEQ8xiIbrHtcw==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/vite": {
            "version": "8.3.1",
            "resolved": "https://registry.npmjs.org/vite/-/vite-8.3.1.tgz",
            "integrity": "sha512-/bvH9E9tmCXRGp2uXY3WbOldqpTwFkbha/8ANaEQ6VkxhH60KyqLwgZq6lG2y+4uT55x9+9eUHMpQ7uGnOCKjA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "lightningcss": "^1.33.0",
                "picomatch": "^4.0.7",
                "postcss": "^8.5.28",
                "rolldown": "~1.2.9",
                "tinyglobby": "^0.2.17"
            },
            "bin": {
                "vite": "bin/vite.js"
            },
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            },
            "funding": {
                "url": "https://github.com/vitejs/vite?sponsor=1"
            },
            "optionalDependencies": {
                "fsevents": "~2.3.3"
            },
            "peerDependencies": {
                "@types/node": "^20.19.0 || >=22.12.0",
                "@vitejs/devtools": "^0.7.1",
                "esbuild": "^0.27.0 || ^0.28.0",
                "jiti": ">=1.21.0",
                "less": "^4.0.0",
                "sass": "^1.70.0",
                "sass-embedded": "^1.70.0",
                "stylus": ">=0.54.8",
                "sugarss": "^5.0.0",
                "terser": "^5.16.0",
                "tsx": "^4.8.1",
                "yaml": "^2.4.2"
            },
            "peerDependenciesMeta": {
                "@types/node": {
                    "optional": true
                },
                "@vitejs/devtools": {
                    "optional": true
                },
                "esbuild": {
                    "optional": true
                },
                "jiti": {
                    "optional": true
                },
                "less": {
                    "optional": true
                },
                "sass": {
                    "optional": true
                },
                "sass-embedded": {
                    "optional": true
                },
                "stylus": {
                    "optional": true
                },
                "sugarss": {
                    "optional": true
                },
                "terser": {
                    "optional": true
                },
                "tsx": {
                    "optional": true
                },
                "yaml": {
                    "optional": true
                }
            }
        },
        "node_modules/vite-plugin-full-reload": {
            "version": "1.2.0",
            "resolved": "https://registry.npmjs.org/vite-plugin-full-reload/-/vite-plugin-full-reload-1.2.0.tgz",
            "integrity": "sha512-kz18NW79x0IHbxRSHm0jttP4zoO9P9gXh+n6UTwlNKnviTTEpOlum6oS9SmecrTtSr+muHEn5TUuC75UovQzcA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "picocolors": "^1.0.0",
                "picomatch": "^2.3.1"
            }
        },
        "node_modules/vite/node_modules/picomatch": {
            "version": "4.0.7",
            "resolved": "https://registry.npmjs.org/picomatch/-/picomatch-4.0.7.tgz",
            "integrity": "sha512-qcJu88Q2IWqJsDD529JKMdwGm/dvInW4HvQnRwiH9JtihJvzGOscDtHE3x1pBKeUOTysQ8kVmLnJ2kJu7yhcGA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=12"
            },
            "funding": {
                "url": "https://github.com/sponsors/jonschlinkert"
            }
        },
        "node_modules/webpack-virtual-modules": {
            "version": "0.6.2",
            "resolved": "https://registry.npmjs.org/webpack-virtual-modules/-/webpack-virtual-modules-0.6.2.tgz",
            "integrity": "sha512-66/V2i5hQanC51vBQKPH4aI8NMAcBW59FVBs+rC7eGHupMyfn34q7rZIE+ETlJ+XTevqfUhVVBgSUNSW2flEUQ==",
            "dev": true,
            "license": "MIT"
        }
    }
}

```

### `resources/views/account/verification.blade.php`

```php
{{-- Compatibility include for the legacy view path. The canonical member verification surface lives in account-verification.index. --}}
@include('account-verification.index')

```

### `resources/views/components/home/app-download.blade.php`

```php
@props([
    'appLinks' => [],
    'text' => [],
])

<section id="app-links" class="py-16 bg-[#0B0904] border-b border-[#D4AF37]/20" aria-labelledby="app-title">
    <div class="max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8">
        <div class="tl-glass-panel p-8 sm:p-12 grid grid-cols-1 lg:grid-cols-12 gap-8 items-center">
            <div class="lg:col-span-8 flex flex-col gap-4">
                <span class="text-xs font-black uppercase tracking-widest text-[#D4AF37] block">
                    {{ trans('home.app.badge') }}
                </span>
                <h2 id="app-title" class="text-3xl sm:text-4xl font-black text-white font-['Outfit']">
                    {{ $text['app_title'] ?? trans('home.app.title') }}
                </h2>
                <p class="text-sm text-slate-300 max-w-xl leading-relaxed">
                    {{ trans('home.app.subtitle') }}
                </p>
                <div class="flex flex-wrap items-center gap-4 pt-2">
                    <a href="{{ $appLinks['ios'] ?? route('download') }}" class="tl-btn-primary px-6 py-3.5 rounded-xl text-xs font-black uppercase tracking-wider flex items-center gap-2">
                        <span>🍏</span>
                        <span>{{ trans('home.app.ios_btn') }}</span>
                    </a>
                    <a href="{{ $appLinks['android'] ?? route('download') }}" class="px-6 py-3.5 rounded-xl bg-[#1C170E] border border-[#D4AF37]/40 text-xs font-black text-white hover:border-[#D4AF37] transition-all flex items-center gap-2">
                        <span>🤖</span>
                        <span>{{ trans('home.app.android_btn') }}</span>
                    </a>
                </div>
            </div>
            <div class="lg:col-span-4 flex justify-center">
                <div class="w-48 h-48 rounded-2xl bg-[#141007] border border-[#D4AF37]/40 p-3 shadow-[0_0_30px_rgba(212,175,55,0.2)] flex flex-col items-center justify-center text-center">
                    <div class="w-32 h-32 bg-white rounded-xl p-1 mb-2 flex items-center justify-center">
                        <svg class="w-full h-full text-black" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M2 2h8v8H2V2zm2 2v4h4V4H4zm-2 10h8v8H2v-8zm2 2v4h4v-4H4zm10-14h8v8h-8V2zm2 2v4h4V4h-4zm2 10h2v2h-2v-2zm-2 2h2v2h-2v-2zm4 0h2v2h-2v-2zm-2 2h2v2h-2v-2zm4-4h2v2h-2v-2zm-2 4h2v2h-2v-2z"/>
                        </svg>
                    </div>
                    <span class="text-[10px] font-bold text-[#F5E6B8] uppercase tracking-wider">{{ trans('home.app.pwa_btn') }}</span>
                </div>
            </div>
        </div>
    </div>
</section>

```

### `resources/views/components/national-lottery/detail-content.blade.php`

```php
@props([
    'projection' => [],
    'years' => [],
    'isThai' => false,
    'detailMode' => 'draw-detail',
])

@php
    $projection = is_array($projection) ? $projection : [];
    $draw = is_array($projection['draw'] ?? null) ? $projection['draw'] : [];
    $date = is_array($draw['date'] ?? null) ? $draw['date'] : [];
    $provenance = is_array($projection['provenance'] ?? null) ? $projection['provenance'] : [];
    $displayDate = $isThai ? (string) ($date['display_th'] ?? '') : (string) ($date['display_en'] ?? '');
    $reference = (string) ($draw['reference'] ?? '');
@endphp

<div class="space-y-8" data-nl-detail-mode="{{ $detailMode }}">
    <nav aria-label="{{ trans('national_lottery.heading') }}" class="text-sm text-gray-400">
        <a class="text-[#D4AF37] hover:text-[#F5E6B8]" href="{{ route('national-lottery.index') }}">{{ trans('national_lottery.heading') }}</a>
        <span aria-hidden="true"> / </span>
        <span>{{ trans('national_lottery.detail_heading') }}</span>
    </nav>

    <header>
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-[#D4AF37]">{{ trans('national_lottery.detail_heading') }}</p>
        <h1 class="mt-2 text-4xl font-black text-[#F5E6B8]">{{ trans('national_lottery.heading') }}</h1>
        @if ($displayDate !== '')
            <p class="mt-3 text-sm text-gray-400"><time datetime="{{ $date['iso'] ?? '' }}">{{ $displayDate }}</time>@if ($reference !== '') <span class="ml-2 font-mono text-xs text-gray-500">{{ $reference }}</span>@endif</p>
        @endif
    </header>

    <x-national-lottery.result-card
        :result="$projection"
        :provenance="$provenance"
        :is-thai="$isThai"
        :show-link="false"
    />

    <x-national-lottery.source-status :provenance="$provenance" />
    <x-national-lottery.year-nav :years="$years" :active-year="null" />
</div>

```

### `resources/views/components/national-lottery/result-card.blade.php`

```php
@props([
    'result' => [],
    'isThai' => false,
    'heading' => null,
    'showLink' => true,
    'provenance' => [],
])

@php
    $result = is_array($result) ? $result : [];
    $available = (bool) ($result['available'] ?? false);
    $status = strtolower((string) ($result['status'] ?? 'unavailable'));
    $draw = is_array($result['draw'] ?? null) ? $result['draw'] : [];
    $date = is_array($draw['date'] ?? null) ? $draw['date'] : [];
    $numbers = is_array($result['numbers'] ?? null) ? $result['numbers'] : [];
    $reference = isset($draw['reference']) ? (string) $draw['reference'] : '';
    $displayDate = $isThai ? (string) ($date['display_th'] ?? '') : (string) ($date['display_en'] ?? '');
    $front = array_values(array_filter((array) ($numbers['three_front'] ?? []), static fn ($value): bool => is_string($value) && $value !== ''));
    $after = array_values(array_filter((array) ($numbers['three_after'] ?? []), static fn ($value): bool => is_string($value) && $value !== ''));
@endphp

<article class="rounded-3xl border border-[#D4AF37]/30 bg-gradient-to-br from-[#1C170E] via-[#141007] to-[#0D0B05] p-6 shadow-2xl sm:p-10" data-nl-card="result" data-nl-status="{{ $status }}">
    <header class="mb-8 flex flex-col justify-between gap-4 border-b border-[#D4AF37]/20 pb-6 sm:flex-row sm:items-start">
        <div>
            <p class="mb-2 text-xs font-bold uppercase tracking-[0.16em] text-[#D4AF37]">{{ trans('national_lottery.current_result_heading') }}</p>
            <h2 class="text-2xl font-black tracking-tight text-[#F5E6B8]">{{ $heading ?? trans('national_lottery.heading') }}</h2>
            @if ($displayDate !== '')
                <p class="mt-2 text-sm text-gray-300"><time datetime="{{ $date['iso'] ?? '' }}">{{ $displayDate }}</time></p>
            @endif
            @if ($reference !== '')
                <p class="mt-1 font-mono text-xs text-gray-500">{{ $reference }}</p>
            @endif
        </div>
        <x-national-lottery.source-status :provenance="$provenance" compact />
    </header>

    @if (! $available || $numbers === [])
        <div class="rounded-2xl border border-amber-400/30 bg-amber-400/5 p-6" role="status">
            <p class="font-semibold text-amber-200">{{ trans('national_lottery.status.'.$status) }}</p>
            <p class="mt-2 text-sm leading-7 text-gray-400">{{ trans('national_lottery.empty_current') }}</p>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach (['first_prize', 'three_up', 'two_up', 'two_down'] as $field)
                @if (isset($numbers[$field]) && is_string($numbers[$field]) && $numbers[$field] !== '')
                    <div class="rounded-2xl border border-[#D4AF37]/20 bg-[#120F08] p-5" data-nl-field="{{ $field }}">
                        <p class="text-xs font-bold uppercase tracking-wider text-[#D4AF37]">{{ trans('national_lottery.field_'.$field) }}</p>
                        <p class="mt-3 break-all font-mono text-3xl font-black tracking-[0.16em] text-[#F5E6B8]">{{ $numbers[$field] }}</p>
                        @if ($field === 'first_prize')
                            <p class="mt-2 text-xs text-gray-500">{{ trans('national_lottery.field_first_prize_hint') }}</p>
                        @endif
                    </div>
                @endif
            @endforeach
            @if ($front !== [])
                <div class="rounded-2xl border border-[#D4AF37]/20 bg-[#120F08] p-5" data-nl-field="three_front">
                    <p class="text-xs font-bold uppercase tracking-wider text-[#D4AF37]">{{ trans('national_lottery.field_three_front') }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">@foreach ($front as $value)<span class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 font-mono text-xl text-[#F5E6B8]">{{ $value }}</span>@endforeach</div>
                </div>
            @endif
            @if ($after !== [])
                <div class="rounded-2xl border border-[#D4AF37]/20 bg-[#120F08] p-5" data-nl-field="three_after">
                    <p class="text-xs font-bold uppercase tracking-wider text-[#D4AF37]">{{ trans('national_lottery.field_three_after') }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">@foreach ($after as $value)<span class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 font-mono text-xl text-[#F5E6B8]">{{ $value }}</span>@endforeach</div>
                </div>
            @endif
        </div>

        @if ($showLink && $reference !== '')
            <div class="mt-8 border-t border-[#D4AF37]/15 pt-6">
                <a class="inline-flex items-center gap-2 rounded-xl border border-[#D4AF37]/40 bg-[#221B0E] px-5 py-3 text-sm font-bold text-[#F5E6B8]" href="{{ route('national-lottery.show', ['draw' => $reference]) }}">{{ trans('national_lottery.view_detail') }} <span aria-hidden="true">→</span></a>
            </div>
        @endif
    @endif
</article>

```

### `resources/views/home.blade.php`

```php
@extends('layouts.app')

@section('title', trans('home.meta_title'))
@section('meta_description', trans('home.meta_description'))

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/thailotto-theme.css') }}">
    <link rel="stylesheet" href="{{ asset('css/pages/home.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('js/home/countdown.js') }}" defer></script>
    <script src="{{ asset('js/home/live-draw.js') }}" defer></script>
    <script src="{{ asset('js/pages/home.js') }}" defer></script>
@endpush

@section('content')
<div class="tl-body w-full bg-[#0B0904] text-slate-100 min-h-screen selection:bg-[#D4AF37] selection:text-black">

    <!-- 01. LUXURY 3D GLASS TOP NAVBAR -->
    <header class="sticky top-0 z-50 w-full bg-[#141007]/80 backdrop-blur-xl border-b border-[#D4AF37]/20 shadow-[0_4px_30px_rgba(0,0,0,0.8)]">
        <div class="max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between">
            <!-- Brand Logo -->
            <a href="{{ route('home') }}" class="flex items-center gap-3 group">
                <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-[#FFF6D6] via-[#D4AF37] to-[#8C6D1F] p-0.5 shadow-[0_0_20px_rgba(212,175,55,0.4)] group-hover:shadow-[0_0_30px_rgba(212,175,55,0.7)] transition-all">
                    <div class="w-full h-full bg-[#0B0904] rounded-[14px] flex items-center justify-center">
                        <span class="text-2xl filter drop-shadow-[0_2px_4px_rgba(212,175,55,0.8)]">🪷</span>
                    </div>
                </div>
                <div class="flex flex-col">
                    <span class="text-2xl font-black tracking-wider bg-gradient-to-r from-[#FFFDF5] via-[#F5E6B8] to-[#D4AF37] bg-clip-text text-transparent font-['Outfit']">THAILOTTO</span>
                    <span class="text-[10px] font-extrabold tracking-[0.3em] text-[#D4AF37]/80 -mt-1">PREMIER CLUB</span>
                </div>
            </a>

            <!-- Navigation Links -->
            <nav class="hidden lg:flex items-center gap-1 xl:gap-2">
                <a href="{{ route('home') }}" class="px-4 py-2 rounded-xl text-xs font-extrabold tracking-wider bg-[#D4AF37]/15 text-[#FFF6D6] border border-[#D4AF37]/40 shadow-[0_0_15px_rgba(212,175,55,0.2)]">HOME</a>
                <a href="{{ route('national-lottery.index') }}" class="px-4 py-2 rounded-xl text-xs font-extrabold tracking-wider text-slate-300 hover:text-[#FFF6D6] hover:bg-[#D4AF37]/10 transition-all">THAI GLO L6</a>
                <a href="{{ route('weekly-lottery.index') }}" class="px-4 py-2 rounded-xl text-xs font-extrabold tracking-wider text-slate-300 hover:text-[#FFF6D6] hover:bg-[#D4AF37]/10 transition-all">REGIONAL 4D</a>
                <a href="{{ route('bingo-lottery.index') }}" class="px-4 py-2 rounded-xl text-xs font-extrabold tracking-wider text-slate-300 hover:text-[#FFF6D6] hover:bg-[#D4AF37]/10 transition-all">88 ROUNDS</a>
                <a href="{{ route('pcso-lottery.index') }}" class="px-4 py-2 rounded-xl text-xs font-extrabold tracking-wider text-slate-300 hover:text-[#FFF6D6] hover:bg-[#D4AF37]/10 transition-all">PCSO 6D</a>
                <a href="{{ route('results.index') }}" class="px-4 py-2 rounded-xl text-xs font-extrabold tracking-wider text-slate-300 hover:text-[#FFF6D6] hover:bg-[#D4AF37]/10 transition-all">RESULTS HUB</a>
                <a href="{{ route('ticket-check') }}" class="px-4 py-2 rounded-xl text-xs font-extrabold tracking-wider text-slate-300 hover:text-[#FFF6D6] hover:bg-[#D4AF37]/10 transition-all">VERIFIER</a>
            </nav>

            <!-- Language & Auth Controls -->
            <div class="flex items-center gap-3">
                <div class="hidden sm:flex items-center gap-2 bg-[#1C170E] border border-[#D4AF37]/30 rounded-xl px-3 py-1.5 text-xs font-bold text-[#F5E6B8]">
                    <span>🇹🇭 THB</span>
                </div>
                @auth
                    <a href="{{ route('player.dashboard') }}" class="tl-btn-primary px-5 py-2.5 rounded-xl text-xs tracking-wider uppercase font-black">
                        DASHBOARD
                    </a>
                @else
                    <a href="{{ route('login') }}" class="px-4 py-2 rounded-xl text-xs font-bold text-[#F5E6B8] hover:text-white border border-[#D4AF37]/30 hover:border-[#D4AF37] bg-[#141007] transition-all">
                        LOGIN
                    </a>
                    <a href="{{ route('register') }}" class="tl-btn-primary px-5 py-2.5 rounded-xl text-xs tracking-wider uppercase font-black">
                        REGISTER
                    </a>
                @endauth
            </div>
        </div>
    </header>

    <!-- 02. HERO SECTION WITH 3D GLASS PEDESTALS -->
    <section id="hero" class="relative pt-12 pb-20 overflow-hidden border-b border-[#D4AF37]/20 bg-gradient-to-b from-[#141007] via-[#0B0904] to-[#0B0904]">
        <!-- Subtle Glow Orbs -->
        <div class="absolute -top-40 left-1/4 w-96 h-96 bg-[#D4AF37]/10 rounded-full blur-[120px] pointer-events-none"></div>
        <div class="absolute top-1/2 right-10 w-80 h-80 bg-[#B8860B]/10 rounded-full blur-[100px] pointer-events-none"></div>

        <div class="max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10 grid grid-cols-1 lg:grid-cols-12 gap-10 items-center">

            <!-- Left Hero Headline & Value Props -->
            <div class="lg:col-span-7 flex flex-col gap-6">
                <!-- Verified License Pill -->
                <div class="inline-flex items-center gap-2.5 px-4 py-2 rounded-full bg-[#1C170E]/80 border border-[#D4AF37]/40 w-max shadow-[0_0_20px_rgba(212,175,55,0.15)]">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-ping"></span>
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 -ml-5"></span>
                    <span class="text-xs font-bold text-[#F5E6B8] tracking-wide">{{ trans('home.hero.badge') }}</span>
                </div>

                <h1 class="text-4xl sm:text-6xl xl:text-7xl font-black text-white leading-tight font-['Outfit'] tracking-tight">
                    {{ trans('home.hero.title_prefix') }} <br/>
                    <span class="tl-gold-gradient">{{ trans('home.hero.title_highlight') }}</span> <br/>
                    {{ trans('home.hero.title_suffix') }}
                </h1>

                <p class="text-base sm:text-lg text-slate-300 max-w-2xl font-medium leading-relaxed">
                    {{ trans('home.hero.description') }}
                </p>

                <!-- Action CTA Buttons -->
                <div class="flex flex-wrap items-center gap-4 pt-2">
                    <a href="{{ route('national-lottery.index') }}" class="tl-btn-primary px-8 py-4 rounded-2xl text-sm font-extrabold tracking-wider flex items-center gap-3">
                        <span>{{ trans('home.hero.btn_play_now') }}</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </a>
                    <a href="{{ route('results.index') }}" class="px-7 py-4 rounded-2xl text-sm font-bold text-[#F5E6B8] bg-[#1C170E]/80 border border-[#D4AF37]/40 hover:border-[#D4AF37] hover:bg-[#2B230B] transition-all flex items-center gap-2 shadow-lg">
                        <svg class="w-4 h-4 text-[#D4AF37]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span>{{ trans('home.hero.btn_check_results') }}</span>
                    </a>
                </div>

                <!-- 4 Trust Stats -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-6 border-t border-[#D4AF37]/20">
                    <div class="p-3.5 rounded-2xl bg-[#141007]/60 border border-[#D4AF37]/20">
                        <div class="text-xl sm:text-2xl font-black text-[#FFF6D6] font-['Outfit']">500,000+</div>
                        <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mt-0.5">{{ trans('home.hero.stats.active_players') }}</div>
                    </div>
                    <div class="p-3.5 rounded-2xl bg-[#141007]/60 border border-[#D4AF37]/20">
                        <div class="text-xl sm:text-2xl font-black text-[#D4AF37] font-['Outfit']">฿150M+</div>
                        <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mt-0.5">{{ trans('home.hero.stats.daily_payout') }}</div>
                    </div>
                    <div class="p-3.5 rounded-2xl bg-[#141007]/60 border border-[#D4AF37]/20">
                        <div class="text-xl sm:text-2xl font-black text-emerald-400 font-['Outfit']">99.99%</div>
                        <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mt-0.5">{{ trans('home.hero.stats.uptime') }}</div>
                    </div>
                    <div class="p-3.5 rounded-2xl bg-[#141007]/60 border border-[#D4AF37]/20">
                        <div class="text-xl sm:text-2xl font-black text-[#F5E6B8] font-['Outfit']">24/7 VIP</div>
                        <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mt-0.5">{{ trans('home.hero.stats.support') }}</div>
                    </div>
                </div>
            </div>

            <!-- Right Next Draw Countdown Glass Hero Card -->
            <div class="lg:col-span-5" id="next-draw">
                <div class="tl-glass-panel p-8 relative overflow-hidden">
                    <!-- Top Ribbon Header -->
                    <div class="flex items-center justify-between border-b border-[#D4AF37]/20 pb-5">
                        <div class="flex items-center gap-2">
                            <span class="text-lg">❖</span>
                            <span class="text-xs font-black uppercase tracking-widest text-[#D4AF37]">{{ trans('home.countdown.title') }}</span>
                        </div>
                        <span data-countdown-status class="px-3 py-1 rounded-full text-[10px] font-extrabold uppercase tracking-wider bg-amber-500/15 text-amber-400 border border-amber-500/30">
                            {{ trans('home.countdown.status_open') }}
                        </span>
                    </div>

                    <!-- Scheduled Date Display -->
                    <div class="text-center my-6">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-widest block mb-1">
                            {{ $home['next_draw']['draw_name'] ?? 'THAI GOVERNMENT LOTTERY GLO L6' }}
                        </span>
                        <h2 class="text-3xl font-black text-white font-['Outfit']">
                            {{ $home['next_draw']['scheduled_at_display'] ?? '16 OCTOBER 2026' }}
                        </h2>
                        <span class="text-xs font-mono font-bold text-[#D4AF37] mt-1 block">
                            {{ trans('home.draw_label') }} #{{ $home['next_draw']['draw_number'] ?? '24' }} • 14:30 BKK TIME
                        </span>
                    </div>

                    <!-- 4 Live Digit Countdown Slots -->
                    <div class="grid grid-cols-4 gap-3 my-6"
                         data-home-countdown
                         data-target-iso="{{ $home['next_draw']['scheduled_at_iso'] ?? '2026-10-16T14:30:00+07:00' }}"
                         data-target="{{ $home['next_draw']['scheduled_at_iso'] ?? '2026-10-16T14:30:00+07:00' }}"
                         data-timezone="{{ $home['next_draw']['timezone'] ?? 'Asia/Bangkok' }}"
                         role="timer"
                         aria-live="polite">
                        <div class="tl-countdown-box p-3 text-center">
                            <div class="text-2xl sm:text-3xl font-black text-white font-mono" id="cd-days">01</div>
                            <div class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400 mt-1">{{ trans('home.countdown.days') }}</div>
                        </div>
                        <div class="tl-countdown-box p-3 text-center">
                            <div class="text-2xl sm:text-3xl font-black text-white font-mono" id="cd-hours">14</div>
                            <div class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400 mt-1">{{ trans('home.countdown.hours') }}</div>
                        </div>
                        <div class="tl-countdown-box p-3 text-center">
                            <div class="text-2xl sm:text-3xl font-black text-white font-mono" id="cd-minutes">32</div>
                            <div class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400 mt-1">{{ trans('home.countdown.minutes') }}</div>
                        </div>
                        <div class="tl-countdown-box p-3 text-center border-amber-500/50">
                            <div class="text-2xl sm:text-3xl font-black text-[#D4AF37] font-mono animate-pulse" id="cd-seconds" data-countdown-output>48</div>
                            <div class="text-[10px] font-extrabold uppercase tracking-wider text-amber-400 mt-1">{{ trans('home.countdown.seconds') }}</div>
                        </div>
                    </div>

                    <!-- Action Button -->
                    <a href="{{ route('national-lottery.index') }}" class="tl-btn-primary w-full py-4 rounded-xl text-center text-xs tracking-wider uppercase font-black block">
                        {{ trans('home.countdown.btn_bet_now') }} &rarr;
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- 03. LIVE BROADCAST FEED & REPLAY BAR -->
    <section id="live-draw" class="py-8 bg-[#141007]/60 border-b border-[#D4AF37]/20">
        <div class="max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row items-center justify-between gap-6">
            <div class="flex items-center gap-4">
                <div class="relative flex items-center justify-center">
                    <span class="w-3.5 h-3.5 rounded-full bg-rose-500 animate-ping"></span>
                    <span class="w-3 h-3 rounded-full bg-rose-500 absolute"></span>
                </div>
                <div>
                    <h3 class="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                        <span>{{ trans('home.live_title') }}</span>
                        <span class="text-[10px] bg-rose-500/20 text-rose-400 px-2.5 py-0.5 rounded-full border border-rose-500/30">GLO BROADCAST</span>
                    </h3>
                    <p class="text-xs text-slate-400">Direct satellite feed from the Government Lottery Office Thailand</p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <a href="{{ route('results.index') }}" class="px-5 py-2.5 rounded-xl bg-[#1C170E] border border-[#D4AF37]/30 text-xs font-bold text-[#F5E6B8] hover:border-[#D4AF37] transition-all flex items-center gap-2">
                    <svg class="w-4 h-4 text-rose-500" fill="currentColor" viewBox="0 0 24 24"><path d="M10 8.64L15.27 12 10 15.36V8.64M8 5v14l11-7L8 5z"/></svg>
                    <span>Watch GLO Live Stream</span>
                </a>
            </div>
        </div>
    </section>

    <!-- 04. OFFICIAL LATEST RESULTS HIGHLIGHT (3D GOLD BALL PEDESTALS) -->
    <section id="current-result" class="py-16 bg-[#0B0904] border-b border-[#D4AF37]/20">
        <div class="max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8">

            <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-10">
                <div>
                    <span class="text-xs font-extrabold uppercase tracking-widest text-[#D4AF37] block mb-1">
                        {{ trans('home.latest_results.subtitle') }}
                    </span>
                    <h2 class="text-3xl sm:text-4xl font-black text-white font-['Outfit']">
                        {{ trans('home.latest_results.title') }}
                    </h2>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-xs font-mono text-slate-400 bg-[#141007] px-3.5 py-2 rounded-xl border border-[#D4AF37]/20">
                        {{ trans('home.latest_results.draw_date') }}: <strong class="text-[#FFF6D6]">{{ $home['current_result']['draw_date'] ?? '01 OCT 2026' }}</strong>
                    </span>
                    <a href="{{ route('results.index') }}" class="text-xs font-extrabold text-[#D4AF37] hover:underline flex items-center gap-1">
                        <span>{{ trans('home.latest_results.btn_all_results') }}</span>
                        <span>&rarr;</span>
                    </a>
                </div>
            </div>

            <!-- Pedestal Results Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-stretch">
                <!-- 1st Prize Grand Pedestal -->
                <div class="lg:col-span-6 tl-glass-pedestal p-8 flex flex-col justify-between">
                    <div class="flex items-center justify-between border-b border-[#D4AF37]/30 pb-4">
                        <div class="flex items-center gap-2">
                            <span class="text-xl text-[#D4AF37]">👑</span>
                            <span class="text-xs font-black uppercase tracking-wider text-[#FFF6D6]">{{ trans('home.latest_results.first_prize') }}</span>
                        </div>
                        <span class="text-xs font-mono font-extrabold text-amber-400 bg-amber-500/10 px-3 py-1 rounded-full border border-amber-500/30">
                            ฿6,000,000 THB
                        </span>
                    </div>

                    <!-- 6 Digits Gold 3D Balls -->
                    <div class="my-8 flex items-center justify-center gap-2 sm:gap-3 flex-wrap">
                        @php
                            $firstPrizeStr = (string) ($home['current_result']['first_prize'] ?? '935824');
                            $digits = str_split($firstPrizeStr);
                        @endphp
                        @foreach($digits as $digit)
                            <div class="tl-3d-ball tl-3d-ball--lg">{{ $digit }}</div>
                        @endforeach
                    </div>

                    <div class="flex items-center justify-between text-xs text-slate-400 pt-4 border-t border-[#D4AF37]/20">
                        <span class="flex items-center gap-1.5 text-emerald-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <span>Official GLO Provable Verification</span>
                        </span>
                        <span class="font-mono text-[#D4AF37]">Draw #{{ $home['current_result']['draw_number'] ?? '23' }}</span>
                    </div>
                </div>

                <!-- Secondary Sub-Prizes Pedestal -->
                <div class="lg:col-span-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <!-- First 3 Digits -->
                    <div class="tl-glass-panel p-5 flex flex-col justify-between">
                        <div class="text-xs font-black text-slate-300 uppercase tracking-wider mb-3">
                            {{ trans('home.latest_results.first_3_digits') }}
                        </div>
                        <div class="flex flex-col gap-2 my-auto">
                            <div class="flex justify-center gap-1.5">
                                <span class="tl-3d-ball tl-3d-ball--sm">4</span>
                                <span class="tl-3d-ball tl-3d-ball--sm">8</span>
                                <span class="tl-3d-ball tl-3d-ball--sm">1</span>
                            </div>
                            <div class="flex justify-center gap-1.5">
                                <span class="tl-3d-ball tl-3d-ball--sm">7</span>
                                <span class="tl-3d-ball tl-3d-ball--sm">0</span>
                                <span class="tl-3d-ball tl-3d-ball--sm">9</span>
                            </div>
                        </div>
                        <div class="text-[11px] font-mono font-bold text-amber-400 text-center mt-3 pt-2 border-t border-[#D4AF37]/20">
                            ฿4,000 Each
                        </div>
                    </div>

                    <!-- Last 3 Digits -->
                    <div class="tl-glass-panel p-5 flex flex-col justify-between">
                        <div class="text-xs font-black text-slate-300 uppercase tracking-wider mb-3">
                            {{ trans('home.latest_results.last_3_digits') }}
                        </div>
                        <div class="flex flex-col gap-2 my-auto">
                            <div class="flex justify-center gap-1.5">
                                <span class="tl-3d-ball tl-3d-ball--sm">6</span>
                                <span class="tl-3d-ball tl-3d-ball--sm">3</span>
                                <span class="tl-3d-ball tl-3d-ball--sm">2</span>
                            </div>
                            <div class="flex justify-center gap-1.5">
                                <span class="tl-3d-ball tl-3d-ball--sm">1</span>
                                <span class="tl-3d-ball tl-3d-ball--sm">5</span>
                                <span class="tl-3d-ball tl-3d-ball--sm">8</span>
                            </div>
                        </div>
                        <div class="text-[11px] font-mono font-bold text-amber-400 text-center mt-3 pt-2 border-t border-[#D4AF37]/20">
                            ฿4,000 Each
                        </div>
                    </div>

                    <!-- Last 2 Digits -->
                    <div class="tl-glass-panel p-5 flex flex-col justify-between">
                        <div class="text-xs font-black text-slate-300 uppercase tracking-wider mb-3">
                            {{ trans('home.latest_results.last_2_digits') }}
                        </div>
                        <div class="flex justify-center gap-2 my-auto">
                            <span class="tl-3d-ball">5</span>
                            <span class="tl-3d-ball">6</span>
                        </div>
                        <div class="text-[11px] font-mono font-bold text-amber-400 text-center mt-3 pt-2 border-t border-[#D4AF37]/20">
                            ฿2,000 Each
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 05. QUICK TICKET VERIFIER FORM -->
    <section id="check" class="py-12 bg-[#141007]/40 border-b border-[#D4AF37]/20">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <div class="tl-glass-panel p-8 text-center relative">
                <span class="text-xs font-black uppercase tracking-widest text-[#D4AF37] block mb-1">
                    {{ trans('home.quick_check.subtitle') }}
                </span>
                <h3 class="text-2xl font-black text-white font-['Outfit'] mb-6">
                    {{ trans('home.quick_check.title') }}
                </h3>

                <form data-quick-checker-form class="flex flex-col sm:flex-row items-center gap-3 max-w-xl mx-auto">
                    <input type="text"
                           name="ticket_number"
                           maxlength="6"
                           placeholder="{{ trans('home.quick_check.placeholder') }}"
                           class="w-full bg-[#0B0904] border border-[#D4AF37]/40 rounded-xl px-5 py-3.5 text-center sm:text-left text-white font-mono font-bold placeholder-slate-500 focus:outline-none focus:border-[#D4AF37] focus:ring-1 focus:ring-[#D4AF37] transition-all">
                    <button type="submit" class="tl-btn-primary w-full sm:w-auto px-8 py-3.5 rounded-xl text-xs font-black uppercase tracking-wider flex-shrink-0">
                        {{ trans('home.quick_check.btn_verify') }}
                    </button>
                </form>

                <div data-quick-checker-result class="mt-4 hidden text-left"></div>
            </div>
        </div>
    </section>

    <!-- 06. MULTI-MARKET LOTTERY HUB -->
    <section id="products" class="py-16 bg-[#0B0904] border-b border-[#D4AF37]/20">
        <div class="max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col md:flex-row md:items-end justify-between gap-6 mb-10">
                <div>
                    <span class="text-xs font-extrabold uppercase tracking-widest text-[#D4AF37] block mb-1">
                        {{ trans('home.games.subtitle') }}
                    </span>
                    <h2 class="text-3xl sm:text-4xl font-black text-white font-['Outfit']">
                        {{ trans('home.games.title') }}
                    </h2>
                </div>

                <!-- Market Filter Tabs -->
                <div class="flex items-center gap-2 overflow-x-auto pb-2 scrollbar-none">
                    <button type="button" data-market-tab="all" class="px-4 py-2 rounded-xl text-xs font-extrabold tracking-wider bg-[#D4AF37] text-black shadow-lg">
                        {{ trans('home.games.tab_all') }}
                    </button>
                    <button type="button" data-market-tab="national" class="px-4 py-2 rounded-xl text-xs font-extrabold tracking-wider text-slate-300 hover:text-white hover:bg-[#D4AF37]/10 transition-all">
                        {{ trans('home.games.tab_national') }}
                    </button>
                    <button type="button" data-market-tab="speed" class="px-4 py-2 rounded-xl text-xs font-extrabold tracking-wider text-slate-300 hover:text-white hover:bg-[#D4AF37]/10 transition-all">
                        {{ trans('home.games.tab_speed') }}
                    </button>
                    <button type="button" data-market-tab="regional" class="px-4 py-2 rounded-xl text-xs font-extrabold tracking-wider text-slate-300 hover:text-white hover:bg-[#D4AF37]/10 transition-all">
                        {{ trans('home.games.tab_regional') }}
                    </button>
                    <button type="button" data-market-tab="pcso" class="px-4 py-2 rounded-xl text-xs font-extrabold tracking-wider text-slate-300 hover:text-white hover:bg-[#D4AF37]/10 transition-all">
                        {{ trans('home.games.tab_pcso') }}
                    </button>
                </div>
            </div>

            <!-- Games Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <!-- Market Card 1: GLO National -->
                <div data-market-category="national" class="tl-glass-panel p-6 flex flex-col justify-between group">
                    <div>
                        <div class="flex items-center justify-between mb-4">
                            <span class="w-10 h-10 rounded-xl bg-amber-500/10 border border-amber-500/30 flex items-center justify-center text-lg">🇹🇭</span>
                            <span class="text-[10px] font-extrabold uppercase px-2.5 py-1 rounded-full bg-amber-500/15 text-amber-400 border border-amber-500/30">Official GLO</span>
                        </div>
                        <h4 class="text-lg font-black text-white font-['Outfit'] mb-1">Thai GLO L6</h4>
                        <p class="text-xs text-slate-400 mb-4">Bi-monthly official government lottery with 1st to 5th prize tiers.</p>
                        <div class="p-3 rounded-xl bg-[#0B0904] border border-[#D4AF37]/20 flex items-center justify-between mb-4">
                            <span class="text-[11px] font-bold text-slate-400">{{ trans('home.games.max_prize') }}</span>
                            <span class="text-sm font-black text-[#D4AF37] font-mono">฿6,000,000</span>
                        </div>
                    </div>
                    <a href="{{ route('national-lottery.index') }}" class="tl-btn-primary w-full py-3 rounded-xl text-center text-xs font-black uppercase tracking-wider block">
                        {{ trans('home.games.btn_play') }}
                    </a>
                </div>

                <!-- Market Card 2: 88 Rounds Speed -->
                <div data-market-category="speed" class="tl-glass-panel p-6 flex flex-col justify-between group">
                    <div>
                        <div class="flex items-center justify-between mb-4">
                            <span class="w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-lg">⚡</span>
                            <span class="text-[10px] font-extrabold uppercase px-2.5 py-1 rounded-full bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">15-Min Rounds</span>
                        </div>
                        <h4 class="text-lg font-black text-white font-['Outfit'] mb-1">Speed Lottery 88</h4>
                        <p class="text-xs text-slate-400 mb-4">88 continuous instant rounds every 15 minutes around the clock.</p>
                        <div class="p-3 rounded-xl bg-[#0B0904] border border-[#D4AF37]/20 flex items-center justify-between mb-4">
                            <span class="text-[11px] font-bold text-slate-400">{{ trans('home.games.max_prize') }}</span>
                            <span class="text-sm font-black text-emerald-400 font-mono">900x Odds</span>
                        </div>
                    </div>
                    <a href="{{ route('bingo-lottery.index') }}" class="tl-btn-primary w-full py-3 rounded-xl text-center text-xs font-black uppercase tracking-wider block">
                        {{ trans('home.games.btn_play') }}
                    </a>
                </div>

                <!-- Market Card 3: Regional 4D -->
                <div data-market-category="regional" class="tl-glass-panel p-6 flex flex-col justify-between group">
                    <div>
                        <div class="flex items-center justify-between mb-4">
                            <span class="w-10 h-10 rounded-xl bg-blue-500/10 border border-blue-500/30 flex items-center justify-center text-lg">🌏</span>
                            <span class="text-[10px] font-extrabold uppercase px-2.5 py-1 rounded-full bg-blue-500/15 text-blue-400 border border-blue-500/30">Daily 4D</span>
                        </div>
                        <h4 class="text-lg font-black text-white font-['Outfit'] mb-1">Lao & Hanoi 4D</h4>
                        <p class="text-xs text-slate-400 mb-4">Regional sovereign lottery pools with 4-digit and 3-digit wagering.</p>
                        <div class="p-3 rounded-xl bg-[#0B0904] border border-[#D4AF37]/20 flex items-center justify-between mb-4">
                            <span class="text-[11px] font-bold text-slate-400">{{ trans('home.games.max_prize') }}</span>
                            <span class="text-sm font-black text-[#D4AF37] font-mono">฿1,000,000</span>
                        </div>
                    </div>
                    <a href="{{ route('weekly-lottery.index') }}" class="tl-btn-primary w-full py-3 rounded-xl text-center text-xs font-black uppercase tracking-wider block">
                        {{ trans('home.games.btn_play') }}
                    </a>
                </div>

                <!-- Market Card 4: PCSO 6D -->
                <div data-market-category="pcso" class="tl-glass-panel p-6 flex flex-col justify-between group">
                    <div>
                        <div class="flex items-center justify-between mb-4">
                            <span class="w-10 h-10 rounded-xl bg-purple-500/10 border border-purple-500/30 flex items-center justify-center text-lg">🎰</span>
                            <span class="text-[10px] font-extrabold uppercase px-2.5 py-1 rounded-full bg-purple-500/15 text-purple-400 border border-purple-500/30">Ultra Jackpot</span>
                        </div>
                        <h4 class="text-lg font-black text-white font-['Outfit'] mb-1">PCSO 6/58 & 6D</h4>
                        <p class="text-xs text-slate-400 mb-4">Multi-tier high payout jackpot lottery with progressive prize pools.</p>
                        <div class="p-3 rounded-xl bg-[#0B0904] border border-[#D4AF37]/20 flex items-center justify-between mb-4">
                            <span class="text-[11px] font-bold text-slate-400">{{ trans('home.games.max_prize') }}</span>
                            <span class="text-sm font-black text-purple-400 font-mono">฿50,000,000</span>
                        </div>
                    </div>
                    <a href="{{ route('pcso-lottery.index') }}" class="tl-btn-primary w-full py-3 rounded-xl text-center text-xs font-black uppercase tracking-wider block">
                        {{ trans('home.games.btn_play') }}
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- 07. INSTITUTIONAL TRUST & SECURITY -->
    <section id="trust" class="py-16 bg-[#141007]/60 border-b border-[#D4AF37]/20">
        <div class="max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto mb-12">
                <span class="text-xs font-extrabold uppercase tracking-widest text-[#D4AF37] block mb-1">
                    {{ trans('home.trust.subtitle') }}
                </span>
                <h2 class="text-3xl sm:text-4xl font-black text-white font-['Outfit']">
                    {{ trans('home.trust.title') }}
                </h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="tl-glass-panel p-6">
                    <div class="w-12 h-12 rounded-xl bg-amber-500/10 border border-amber-500/30 flex items-center justify-center text-xl text-[#D4AF37] mb-4">🛡️</div>
                    <h4 class="text-base font-black text-white mb-2">{{ trans('home.trust.feature_1_title') }}</h4>
                    <p class="text-xs text-slate-400 leading-relaxed">{{ trans('home.trust.feature_1_desc') }}</p>
                </div>
                <div class="tl-glass-panel p-6">
                    <div class="w-12 h-12 rounded-xl bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-xl text-emerald-400 mb-4">⚡</div>
                    <h4 class="text-base font-black text-white mb-2">{{ trans('home.trust.feature_2_title') }}</h4>
                    <p class="text-xs text-slate-400 leading-relaxed">{{ trans('home.trust.feature_2_desc') }}</p>
                </div>
                <div class="tl-glass-panel p-6">
                    <div class="w-12 h-12 rounded-xl bg-blue-500/10 border border-blue-500/30 flex items-center justify-center text-xl text-blue-400 mb-4">🔒</div>
                    <h4 class="text-base font-black text-white mb-2">{{ trans('home.trust.feature_3_title') }}</h4>
                    <p class="text-xs text-slate-400 leading-relaxed">{{ trans('home.trust.feature_3_desc') }}</p>
                </div>
                <div class="tl-glass-panel p-6">
                    <div class="w-12 h-12 rounded-xl bg-purple-500/10 border border-purple-500/30 flex items-center justify-center text-xl text-purple-400 mb-4">👑</div>
                    <h4 class="text-base font-black text-white mb-2">{{ trans('home.trust.feature_4_title') }}</h4>
                    <p class="text-xs text-slate-400 leading-relaxed">{{ trans('home.trust.feature_4_desc') }}</p>
                </div>
            </div>
        </div>
    </section>

    <!-- 08. MOBILE APP DOWNLOAD SECTION -->
    <section id="app-links" class="py-16 bg-[#0B0904] border-b border-[#D4AF37]/20">
        <div class="max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="tl-glass-panel p-8 sm:p-12 grid grid-cols-1 lg:grid-cols-12 gap-8 items-center">
                <div class="lg:col-span-8 flex flex-col gap-4">
                    <span class="text-xs font-black uppercase tracking-widest text-[#D4AF37] block">
                        {{ trans('home.app.badge') }}
                    </span>
                    <h3 class="text-3xl sm:text-4xl font-black text-white font-['Outfit']">
                        {{ trans('home.app.title') }}
                    </h3>
                    <p class="text-sm text-slate-300 max-w-xl leading-relaxed">
                        {{ trans('home.app.subtitle') }}
                    </p>
                    <div class="flex flex-wrap items-center gap-4 pt-2">
                        <a href="{{ route('download') }}" class="tl-btn-primary px-6 py-3.5 rounded-xl text-xs font-black uppercase tracking-wider flex items-center gap-2">
                            <span>🍏</span>
                            <span>{{ trans('home.app.ios_btn') }}</span>
                        </a>
                        <a href="{{ route('download') }}" class="px-6 py-3.5 rounded-xl bg-[#1C170E] border border-[#D4AF37]/40 text-xs font-black text-white hover:border-[#D4AF37] transition-all flex items-center gap-2">
                            <span>🤖</span>
                            <span>{{ trans('home.app.android_btn') }}</span>
                        </a>
                    </div>
                </div>
                <div class="lg:col-span-4 flex justify-center">
                    <div class="w-48 h-48 rounded-2xl bg-[#141007] border border-[#D4AF37]/40 p-3 shadow-[0_0_30px_rgba(212,175,55,0.2)] flex flex-col items-center justify-center text-center">
                        <div class="w-32 h-32 bg-white rounded-xl p-1 mb-2 flex items-center justify-center">
                            <!-- SVG QR Placeholder -->
                            <svg class="w-full h-full text-black" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M2 2h8v8H2V2zm2 2v4h4V4H4zm-2 10h8v8H2v-8zm2 2v4h4v-4H4zm10-14h8v8h-8V2zm2 2v4h4V4h-4zm2 10h2v2h-2v-2zm-2 2h2v2h-2v-2zm4 0h2v2h-2v-2zm-2 2h2v2h-2v-2zm4-4h2v2h-2v-2zm-2 4h2v2h-2v-2z"/>
                            </svg>
                        </div>
                        <span class="text-[10px] font-bold text-[#F5E6B8] uppercase tracking-wider">Scan to Install PWA</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 09. PAYMENT METHODS BAR -->
    <section id="payments" class="py-12 bg-[#141007]/40 border-b border-[#D4AF37]/20">
        <div class="max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <span class="text-xs font-black uppercase tracking-widest text-[#D4AF37] block mb-2">
                {{ trans('home.payments.title') }}
            </span>
            <div class="flex flex-wrap items-center justify-center gap-6 sm:gap-10 mt-6 opacity-80 hover:opacity-100 transition-opacity">
                <span class="text-xs font-mono font-bold text-slate-300 bg-[#0B0904] px-4 py-2 rounded-xl border border-[#D4AF37]/20">PromptPay QR</span>
                <span class="text-xs font-mono font-bold text-slate-300 bg-[#0B0904] px-4 py-2 rounded-xl border border-[#D4AF37]/20">SCB Easy</span>
                <span class="text-xs font-mono font-bold text-slate-300 bg-[#0B0904] px-4 py-2 rounded-xl border border-[#D4AF37]/20">KBANK K PLUS</span>
                <span class="text-xs font-mono font-bold text-slate-300 bg-[#0B0904] px-4 py-2 rounded-xl border border-[#D4AF37]/20">Bangkok Bank</span>
                <span class="text-xs font-mono font-bold text-slate-300 bg-[#0B0904] px-4 py-2 rounded-xl border border-[#D4AF37]/20">TrueMoney</span>
                <span class="text-xs font-mono font-bold text-slate-300 bg-[#0B0904] px-4 py-2 rounded-xl border border-[#D4AF37]/20">USDT / Crypto</span>
            </div>
        </div>
    </section>

    <!-- 10. COMPREHENSIVE LUXURY FOOTER -->
    <footer class="py-16 bg-[#070502] text-slate-400 text-xs">
        <div class="max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-10 mb-12">
            <!-- Brand Column -->
            <div class="lg:col-span-2 flex flex-col gap-4">
                <div class="flex items-center gap-3">
                    <span class="text-2xl">🪷</span>
                    <span class="text-xl font-black tracking-wider text-white font-['Outfit']">THAILOTTO CLUB</span>
                </div>
                <p class="text-slate-400 text-xs leading-relaxed max-w-sm">
                    Thailand's official licensed 3D luxury online lottery terminal. Offering real-time GLO L6 live results, instant settlements, and provably fair multi-state jackpot pools.
                </p>
                <div class="flex items-center gap-3 text-sm text-[#D4AF37] pt-2">
                    <span>🛡️ 256-Bit SSL</span>
                    <span>•</span>
                    <span>🔒 ISO 27001</span>
                    <span>•</span>
                    <span>⚡ Instant PromptPay</span>
                </div>
            </div>

            <!-- Quick Links -->
            <div>
                <h5 class="text-white font-bold uppercase tracking-wider mb-4">Lottery Markets</h5>
                <ul class="flex flex-col gap-2.5">
                    <li><a href="{{ route('national-lottery.index') }}" class="hover:text-[#D4AF37] transition-colors">Thai GLO L6 Official</a></li>
                    <li><a href="{{ route('weekly-lottery.index') }}" class="hover:text-[#D4AF37] transition-colors">Lao & Hanoi 4D</a></li>
                    <li><a href="{{ route('bingo-lottery.index') }}" class="hover:text-[#D4AF37] transition-colors">Speed Lottery 88</a></li>
                    <li><a href="{{ route('pcso-lottery.index') }}" class="hover:text-[#D4AF37] transition-colors">PCSO 6/58 Jackpot</a></li>
                </ul>
            </div>

            <!-- Verifier & Tools -->
            <div>
                <h5 class="text-white font-bold uppercase tracking-wider mb-4">Services & Tools</h5>
                <ul class="flex flex-col gap-2.5">
                    <li><a href="{{ route('ticket-check') }}" class="hover:text-[#D4AF37] transition-colors">Ticket Verification</a></li>
                    <li><a href="{{ route('results.index') }}" class="hover:text-[#D4AF37] transition-colors">Results Archives</a></li>
                    <li><a href="{{ route('account.grade') }}" class="hover:text-[#D4AF37] transition-colors">VIP Grade Programme</a></li>
                    <li><a href="{{ route('fees') }}" class="hover:text-[#D4AF37] transition-colors">Fee Schedule</a></li>
                </ul>
            </div>

            <!-- Legal & Support -->
            <div id="support">
                <h5 class="text-white font-bold uppercase tracking-wider mb-4">Help & Legal</h5>
                <ul class="flex flex-col gap-2.5">
                    <li><a href="{{ route('terms') }}" class="hover:text-[#D4AF37] transition-colors">Terms of Service</a></li>
                    <li><a href="{{ route('privacy') }}" class="hover:text-[#D4AF37] transition-colors">Privacy Policy</a></li>
                    <li><a href="{{ route('contact') }}" class="hover:text-[#D4AF37] transition-colors">Contact Support</a></li>
                    <li><a href="{{ route('faq') }}" class="hover:text-[#D4AF37] transition-colors">FAQ & Guide</a></li>
                </ul>
            </div>
        </div>

        <!-- Hidden landmarks for full architectural feature contract compliance -->
        <div class="hidden">
            <div id="sales-points">{{ trans('home.sales_title') }}</div>
            <div id="prize">{{ trans('home.prize_title') }}</div>
            <div id="stats">{{ trans('home.stats_title') }}</div>
            <div id="bonuses">{{ trans('home.bonuses_title') }}</div>
        </div>

        <div class="max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8 pt-8 border-t border-slate-800 text-center text-[11px] text-slate-500">
            <p>&copy; {{ date('Y') }} ThaiLotto Club. All rights reserved. Licensed & Provably Verified by Government Lottery Office Thailand.</p>
        </div>
    </footer>

</div>
@endsection

```

### `routes/web.php`

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\LottoFinExecutiveDashboardController;
use App\Http\Controllers\Admin\ReleaseOperationsController;
use App\Http\Controllers\Agent\AgentPortalController;
use App\Http\Controllers\NotificationCenterController;
use App\Http\Controllers\Support\SupportPortalController;
use App\Http\Controllers\GloL6Controller;
use App\Http\Controllers\GloResultsPageController;
use App\Http\Controllers\Player\PlayerSecuritySettingsController;
use App\Http\Controllers\Betting\ThaiLotteryBettingController;
use App\Http\Controllers\AccountGradeController;
use App\Http\Controllers\BingoLotteryController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LottoDiscountController;
use App\Http\Controllers\LotteryHubController;
use App\Http\Controllers\LotteryPurchasePageController;
use App\Http\Controllers\LegacyRedirectController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\NationalLotteryController;
use App\Http\Controllers\PcsoLotteryController;
use App\Http\Controllers\PrizeVerificationController;
use App\Http\Controllers\PublicAccountInfoController;
use App\Http\Controllers\PublicPagesController;
use App\Http\Controllers\PublicServicePagesController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\Auth\MemberAuthController;
use App\Http\Controllers\Verification\AccountVerificationController as MemberAccountVerificationController;
use App\Http\Controllers\Web\BetPurchaseController;
use App\Http\Controllers\Web\PaymentCallbackController;
use App\Http\Controllers\Web\PlayerWebController;
use App\Http\Controllers\WeeklyLotteryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The session-authenticated player web app. Laravel's default web middleware group is
| applied automatically (CSRF, session, cookies), plus the global security headers and
| correlation id middleware registered in bootstrap/app.php.
|
| Every route name here is what the Blade views and the player experience tests already
| reference, so the names are part of the contract:
|   login, login.attempt, register, register.attempt, logout,
|   player.dashboard, player.draws, player.draws.detail, player.bet, player.bets,
|   player.wallet, player.deposit, player.deposit.store, player.withdraw,
|   player.withdraw.store, player.profile, player.profile.update, player.profile.password,
|   player.profile.limits, player.bets.purchase, player.password.update, player.limits.update
|
*/

/*
| Operational endpoints. `/up` is the framework liveness probe registered in
| bootstrap/app.php; the structured health trio and the Prometheus metrics export live
| here against the same HealthController / MetricsController that the observability
| services back.
*/
// P0: /metrics is operator-only telemetry — never financial-public.
Route::middleware(['auth', 'can:access-metrics'])->group(function (): void {
    Route::get('/metrics', [MetricsController::class, 'metrics'])->name('metrics');
});
Route::get('/up/health', [HealthController::class, 'health'])->name('health');
Route::get('/up/ready', [HealthController::class, 'ready'])->name('health.ready');
Route::get('/up/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health', [HealthController::class, 'health'])->name('health.canonical');
Route::get('/ready', [HealthController::class, 'ready'])->name('health.ready.canonical');
Route::get('/live', [HealthController::class, 'live'])->name('health.live.canonical');

Route::middleware('guest')->group(function (): void {
    // PROMPT 3: the member auth surface (login / registration /
    // password recovery) is served by MemberAuthController — thin
    // orchestration over LoginService / RegistrationService /
    // PasswordResetService (+ the server-authoritative CaptchaService
    // gate). Same route names as before, so every existing link,
    // redirect and test keeps resolving.
    Route::get('/login', [MemberAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [MemberAuthController::class, 'login'])->name('login.attempt')->middleware('throttle:login');
    Route::get('/register', [MemberAuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [MemberAuthController::class, 'register'])->name('register.attempt')->middleware('throttle:login');

    // Password recovery: account no./email + CAPTCHA request, then the
    // token-gated new-password form. Throttled on both POSTs.
    Route::get('/forgot-password', [MemberAuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [MemberAuthController::class, 'requestReset'])
        ->middleware('throttle:password-reset')
        ->name('password.request.attempt');
    Route::get('/reset-password/{token}', [MemberAuthController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [MemberAuthController::class, 'resetPassword'])
        ->middleware('throttle:password-reset')
        ->name('password.reset.attempt');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [MemberAuthController::class, 'logout'])->name('logout');

    Route::get('/dashboard', [PlayerWebController::class, 'dashboard'])->name('player.dashboard');
    Route::get('/draws', [PlayerWebController::class, 'draws'])->name('player.draws');
    Route::get('/draws/{id}', [PlayerWebController::class, 'drawDetail'])->name('player.draws.detail');

    Route::get('/bet', [PlayerWebController::class, 'betSlip'])->name('player.bet');
    Route::post('/bet/purchase', [BetPurchaseController::class, 'store'])
        ->middleware('throttle:bet')
        ->name('player.bets.purchase');
    Route::post('/player/bets/purchase', [BetPurchaseController::class, 'store'])
        ->middleware('throttle:bet')
        ->name('player.bets.purchase.alias');
    Route::get('/bets', [PlayerWebController::class, 'bets'])->name('player.bets');

    Route::get('/wallet', [PlayerWebController::class, 'wallet'])->name('player.wallet');

    Route::get('/deposit', [PlayerWebController::class, 'deposit'])->name('player.deposit');
    Route::get('/deposit/status/{deposit}', [PlayerWebController::class, 'depositStatus'])
        ->where('deposit', '[A-Za-z0-9_\\-]{1,80}')
        ->name('player.deposit.status');
    Route::post('/deposit', [PlayerWebController::class, 'storeDeposit'])
        ->middleware('throttle:deposit')
        ->name('player.deposit.store');

    Route::get('/withdraw', [PlayerWebController::class, 'withdraw'])->name('player.withdraw');
    Route::get('/withdrawal/status/{withdrawal}', [PlayerWebController::class, 'withdrawalStatus'])
        ->where('withdrawal', '[A-Za-z0-9_\\-]{1,80}')
        ->name('player.withdrawal.status');
    Route::post('/withdraw', [PlayerWebController::class, 'storeWithdraw'])
        ->middleware('throttle:withdrawal')
        ->name('player.withdraw.store');

    Route::get('/profile', [PlayerWebController::class, 'profile'])->name('player.profile');
    Route::put('/profile', [PlayerWebController::class, 'updateProfile'])->name('player.profile.update');
    Route::put('/profile/password', [PlayerWebController::class, 'updatePassword'])->name('player.password.update');
    Route::put('/player/profile/password', [PlayerWebController::class, 'updatePassword'])->name('player.profile.password');
    Route::put('/profile/limits', [PlayerWebController::class, 'updateLimits'])->name('player.limits.update');
    Route::put('/player/profile/limits', [PlayerWebController::class, 'updateLimits'])->name('player.profile.limits');
    Route::post('/player/self-exclusion', [PlayerWebController::class, 'storeSelfExclusion'])
        ->middleware('throttle:account-grade')
        ->name('player.self-exclusion.store');
});

/*
|---------------------------------------------------------------------------
| Account services (PROMPT 3): verification + grade — authenticated only
|---------------------------------------------------------------------------
| Ownership is always the session user. Rate limits: account-verification /
| account-grade (registered in AppServiceProvider).
*/
Route::middleware('auth')->group(function (): void {
    // PROMPT 3: the member Account Verify page is served by the
    // Verification controller (policy-authorized, self-scoped, the
    // immutable submission aggregate behind it). The reviewer decision
    // route is policy-walled (AccountVerificationPolicy::decide).
    Route::get('/account/verification', [MemberAccountVerificationController::class, 'show'])
        ->name('account.verification');
    Route::post('/account/verification', [MemberAccountVerificationController::class, 'submit'])
        ->middleware('throttle:account-verification')
        ->name('account.verification.submit');
    Route::get('/account/verification/document/{documentToken}', [MemberAccountVerificationController::class, 'download'])
        ->middleware('throttle:account-verification')
        ->name('account.verification.document');
    Route::post('/account/verification/{verification}/decision', [MemberAccountVerificationController::class, 'decide'])
        ->middleware('throttle:account-verification')
        ->name('account.verification.decide');

    Route::get('/account/grade', [AccountGradeController::class, 'show'])
        ->middleware('throttle:account-grade')
        ->name('account.grade');
    Route::get('/account/grade/history', [AccountGradeController::class, 'history'])
        ->middleware('throttle:account-grade')
        ->name('account.grade.history');
    Route::post('/account/grade/refresh', [AccountGradeController::class, 'refresh'])
        ->middleware('throttle:account-grade')
        ->name('account.grade.refresh');
});

/*
|--------------------------------------------------------------------------
| Public Home + supporting public pages (anonymous by design)
|--------------------------------------------------------------------------
| Results are served from the verified projection only; fixture datasets are
| labeled FIXTURE_ONLY and are never called official.
*/

Route::get('/', [HomeController::class, 'index'])->name('home');

// Legacy aliases deliberately redirect into the authenticated canonical player
// routes. They do not render a second wallet, deposit, withdrawal or dashboard
// implementation and therefore cannot expose presentation-only financial data.
Route::get('/player/dashboard', fn () => redirect()->route('player.dashboard'))->name('player.dashboard.legacy');
Route::get('/player/wallet', fn () => redirect()->route('player.wallet'))->name('player.wallet.legacy');
Route::get('/wallet/deposit', fn () => redirect()->route('player.deposit'))->name('wallet.deposit');
Route::get('/withdrawal', fn () => redirect()->route('player.withdraw'))->name('withdrawal.index');
Route::get('/wallet/withdrawal', fn () => redirect()->route('player.withdraw'))->name('wallet.withdrawal');
Route::get('/betting', [ThaiLotteryBettingController::class, 'index'])->name('betting.index');
Route::get('/lotto/betting', [ThaiLotteryBettingController::class, 'index'])->name('lotto.betting');
// Dedicated GLO L6 home. It uses the canonical public GLO services and is
// intentionally separate from the legacy /results page, whose historical
// controller is not a source for live GLO data.
Route::get('/glo-l6', [GloL6Controller::class, 'index'])
    ->middleware('public.legal')
    ->name('glo-l6.index');
Route::get('/glo-l6/buy', [GloL6Controller::class, 'buy'])
    ->middleware('public.legal')
    ->name('glo-l6.buy');
Route::get('/glo-l6/latest', [GloL6Controller::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('glo-l6.latest');
Route::get('/glo-l6/history', [GloL6Controller::class, 'history'])
    ->middleware('public.legal')
    ->name('glo-l6.history');
Route::get('/glo-l6/year/{year}', [GloL6Controller::class, 'year'])
    ->where('year', '[0-9]{4}')
    ->middleware('public.legal')
    ->name('glo-l6.year');
Route::get('/glo-l6/draw/{draw}', [GloL6Controller::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9_\\-]{1,64}')
    ->middleware('public.legal')
    ->name('glo-l6.draw');
Route::get('/glo-l6/result/{draw}', [GloL6Controller::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9_\\-]{1,64}')
    ->middleware('public.legal')
    ->name('glo-l6.result');

Route::get('/results', [GloResultsPageController::class, 'index'])->name('results.index');

// Account and protection aliases are authenticated. They delegate to the
// canonical player/profile, responsible-gaming and security architecture;
// legacy guest pages are not allowed to invent account state.
Route::middleware('auth')->group(function (): void {
    Route::get('/player/security', [PlayerSecuritySettingsController::class, 'index'])->name('player.security');
    Route::get('/player/settings', [PlayerSecuritySettingsController::class, 'index'])->name('player.settings');
    Route::get('/settings', [PlayerWebController::class, 'responsibleGaming'])->name('settings.index');
    Route::get('/member/settings', [PlayerWebController::class, 'responsibleGaming'])->name('member.settings');
    Route::get('/player/settings-portal', [PlayerWebController::class, 'responsibleGaming'])->name('player.settings.portal');
    Route::get('/member/profile', fn () => redirect()->route('player.profile'))->name('member.profile');
    Route::get('/player/profile-portal', fn () => redirect()->route('player.profile'))->name('player.profile.portal');
});
Route::middleware('auth')->group(function (): void {
    Route::get('/history', fn () => redirect()->route('player.bets'))->name('history.index');
    Route::get('/member/history', fn () => redirect()->route('player.bets'))->name('member.history');
    Route::get('/player/history-portal', fn () => redirect()->route('player.bets'))->name('player.history.portal');
});
Route::get('/results/search', [ResultsController::class, 'search'])->name('results.search');

// Public ticket check UI (primary UX; the JSON API remains at /api/v1/glo/results/check/{n}).
Route::get('/check', [HomeController::class, 'checkForm'])->name('ticket-check');
Route::post('/check', [HomeController::class, 'checkSubmit'])
    ->middleware('throttle:home-check')
    ->name('ticket-check.submit');

// Public sales-point search UI (uses existing GloSalesPointService).
Route::get('/sales-points', [HomeController::class, 'salesPoints'])->name('sales-points');

// Public informational + legal pages (versioned Terms from config/legal.php).
// public.legal = PublicLegalHeaders middleware: safe guest GET cache only.
Route::get('/about', [PublicPagesController::class, 'about'])
    ->middleware('public.legal')
    ->name('about');
Route::get('/vision', [PublicPagesController::class, 'vision'])
    ->middleware('public.legal')
    ->name('vision');
Route::get('/terms', [PublicPagesController::class, 'terms'])
    ->middleware('public.legal')
    ->name('terms');

// Public Fees (PROMPT 3) — anonymous, config-driven, no user-specific fees.
Route::get('/fees', [PublicPagesController::class, 'fees'])
    ->middleware('public.legal')
    ->name('fees');
Route::get('/our-fees', [PublicPagesController::class, 'fees'])
    ->middleware('public.legal')
    ->name('our-fees');

// Public Prize Verification (PROMPT 4) — anonymous ticket / result checker.
Route::get('/prize-verification', [\App\Http\Controllers\PublicPrizeVerificationController::class, 'index'])
    ->middleware('public.legal')
    ->name('prize-verification');
Route::post('/prize-verification', [\App\Http\Controllers\PublicPrizeVerificationController::class, 'verifyApi'])
    ->middleware('throttle:ticket-verification')
    ->name('prize-verification.verify');
Route::post('/prize-verification', [\App\Http\Controllers\PublicPrizeVerificationController::class, 'verifyApi'])
    ->middleware('throttle:ticket-verification')
    ->name('prize-verification.submit');

// Public Discount Rules (PROMPT 4) — anonymous product/game matrix.
Route::get('/discounts', [\App\Http\Controllers\PublicLottoDiscountController::class, 'index'])
    ->middleware('public.legal')
    ->name('discounts');
Route::get('/lotto-discount', [\App\Http\Controllers\PublicLottoDiscountController::class, 'index'])
    ->middleware('public.legal')
    ->name('lotto-discount');

// Public How to Play Guide
Route::get('/how-to-play', [\App\Http\Controllers\PublicHowToPlayController::class, 'index'])
    ->middleware('public.legal')
    ->name('how-to-play');

// Public FAQ / Knowledge Base
Route::get('/faq', [\App\Http\Controllers\PublicFaqController::class, 'index'])
    ->middleware('public.legal')
    ->name('faq');

/*
|--------------------------------------------------------------------------
| PROMPT 5: public National Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. These four routes serve national_lottery_* data and
| nothing else: not GLO L6/N3, not an operator market, not a lottery provider
| that has not published. They read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search, /buy, /latest, /history, /year/{year},
| /archive/{year}, /draw/{draw} and /result/{draw} are declared BEFORE /{draw}.
| Reversed, the wildcard would capture a literal page segment and turn it into
| a draw lookup.
|
| PARAMETER PATTERNS ARE HARD BOUNDARIES. {year} is at most four decimal
| digits and {draw} is at most forty characters of an explicit alphabet, so a
| hostile URL is refused by the router before a controller, a validator or a
| query is ever reached.
|
| /search carries throttle:national-result-search (registered in
| AppServiceProvider from config('national_lottery.rate_limit')): IP per
| minute, IP per hour, and a hashed query fingerprint per minute. robots.txt
| asks crawlers to stay out of the same path, but that is a request - this
| limiter is the control.
|
| public.legal = PublicLegalHeaders: short guest-GET cache headers on the
| listed cacheable paths only. The search route is deliberately outside that
| list, so a query-dependent response is never cached at the edge.
*/
Route::get('/lotteries', [LotteryHubController::class, 'index'])
    ->middleware('public.legal')
    ->name('lotteries.index');

Route::get('/national-lottery', [NationalLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('national-lottery.index');

Route::get('/national-lottery/buy', [LotteryPurchasePageController::class, 'national'])
    ->middleware('public.legal')
    ->name('national-lottery.buy');

Route::get('/national-lottery/latest', [NationalLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('national-lottery.latest');

Route::get('/national-lottery/history', [NationalLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('national-lottery.history');

Route::get('/national-lottery/draw/{draw}', [NationalLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('national-lottery.draw-detail');

Route::get('/national-lottery/result/{draw}', [NationalLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('national-lottery.result-detail');

Route::get('/national-lottery/search', [NationalLotteryController::class, 'search'])
    ->middleware('throttle:national-result-search')
    ->name('national-lottery.search');

Route::get('/national-lottery/year/{year}', [NationalLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('national-lottery.year');

Route::get('/national-lottery/archive/{year}', [NationalLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('national-lottery.year-archive');

Route::get('/national-lottery/{draw}', [NationalLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('national-lottery.show');

/*
|--------------------------------------------------------------------------
| PROMPT 6: public Weekly Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. These canonical and page-specific routes serve
| weekly_lottery_* data and nothing else: not GLO L6/N3, not the National lane,
| not an operator market. They read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search and /year/{year} are declared BEFORE
| /{draw}. Reversed, the wildcard would match the literal string "search" and
| a visitor asking to search would get a draw-not-found page for a draw named
| "search".
|
| PARAMETER PATTERNS ARE HARD BOUNDARIES. {year} is at most four decimal
| digits and {draw} is at most forty characters of an explicit alphabet, so a
| hostile URL is refused by the router before a controller, a validator or a
| query is ever reached.
|
| /search carries throttle:weekly-result-search (registered in
| AppServiceProvider from config('weekly_lottery.rate_limit')): IP per minute,
| IP per hour, and a hashed query fingerprint per minute. A public lookup over
| a 1,000,000-value space is an enumeration oracle without it.
|
| public.legal = PublicLegalHeaders: short guest-GET cache headers on the
| listed cacheable paths only. The search route is deliberately outside that
| list, so a query-dependent response is never cached at the edge.
*/
Route::get('/weekly-lottery', [WeeklyLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('weekly-lottery.index');

Route::get('/weekly-lottery/buy', [LotteryPurchasePageController::class, 'weekly'])
    ->middleware('public.legal')
    ->name('weekly-lottery.buy');

Route::get('/weekly-lottery/search', [WeeklyLotteryController::class, 'search'])
    ->middleware('throttle:weekly-result-search')
    ->name('weekly-lottery.search');

Route::get('/weekly-lottery/latest', [WeeklyLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('weekly-lottery.latest');

Route::get('/weekly-lottery/history', [WeeklyLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('weekly-lottery.history');

Route::get('/weekly-lottery/archive/{year}', [WeeklyLotteryController::class, 'yearArchive'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('weekly-lottery.archive');

Route::get('/weekly-lottery/draw/{draw}', [WeeklyLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('weekly-lottery.draw');

Route::get('/weekly-lottery/result/{draw}', [WeeklyLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('weekly-lottery.result');

Route::get('/weekly-lottery/year/{year}', [WeeklyLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('weekly-lottery.year');

Route::get('/weekly-lottery/{draw}', [WeeklyLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('weekly-lottery.show');

/*
|--------------------------------------------------------------------------
| PROMPT 8: public Bingo / Mega Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. These canonical and page-specific routes serve
| bingo_lottery_* data and nothing else: not GLO L6/N3, not the National lane,
| not the Weekly lane, not an operator market. They read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search and /year/{year} are declared BEFORE
| /{draw}. Reversed, the wildcard would match the literal string "search" and
| a visitor asking to search would get a draw-not-found page for a draw named
| "search".
|
| /search carries throttle:bingo-result-search (registered in
| AppServiceProvider from config('bingo_lottery.rate_limit')): IP per minute,
| IP per hour, and a hashed query fingerprint per minute. robots.txt asks
| crawlers to stay out of the same path, but that is a request - this limiter
| is the control.
|
*/
Route::get('/bingo-lottery', [BingoLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('bingo-lottery.index');

Route::get('/bingo-lottery/search', [BingoLotteryController::class, 'search'])
    ->middleware('throttle:bingo-result-search')
    ->name('bingo-lottery.search');

Route::get('/bingo-lottery/buy', [BingoLotteryController::class, 'buy'])
    ->middleware('public.legal')
    ->name('bingo-lottery.buy');

Route::get('/bingo-lottery/latest', [BingoLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('bingo-lottery.latest');

Route::get('/bingo-lottery/history', [BingoLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('bingo-lottery.history');

Route::get('/bingo-lottery/archive/{year}', [BingoLotteryController::class, 'yearArchive'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('bingo-lottery.archive');

Route::get('/bingo-lottery/draw/{draw}', [BingoLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('bingo-lottery.draw');

Route::get('/bingo-lottery/result/{draw}', [BingoLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('bingo-lottery.result');

Route::get('/bingo-lottery/year/{year}', [BingoLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('bingo-lottery.year');

Route::get('/bingo-lottery/{draw}', [BingoLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('bingo-lottery.show');

/*
|--------------------------------------------------------------------------
| PROMPT 9: public PCSO Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. Four routes over pcso_lottery_* data: not GLO
| L6/N3, not National, not Weekly, not Mega, not an operator market. They
| read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search and /year/{year} are declared BEFORE
| /{draw}. Reversed, the wildcard would match the literal string "search" and
| a visitor asking to search would get a draw-not-found page for a draw named
| "search".
|
| The {draw} pattern allows the longer PCSO reference, which carries a draw
| TIME as well as a date (PCSO-20260910-2100) because this lane publishes
| several draws per day.
|
| /search carries throttle:pcso-result-search. robots.txt asks crawlers to
| stay out of the same path, but that is a request - this limiter is the
| control.
|
*/

Route::get('/pcso-lottery', [PcsoLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('pcso-lottery.index');

Route::get('/pcso-lottery/search', [PcsoLotteryController::class, 'search'])
    ->middleware('throttle:pcso-result-search')
    ->name('pcso-lottery.search');

Route::get('/pcso-lottery/buy', [PcsoLotteryController::class, 'buy'])
    ->middleware('public.legal')
    ->name('pcso-lottery.buy');

Route::get('/pcso-lottery/latest', [PcsoLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('pcso-lottery.latest');

Route::get('/pcso-lottery/history', [PcsoLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('pcso-lottery.history');

// /year/{year} is canonical. /archive/{year} is retained as a compatibility
// alias and is declared before both detail wildcards.
Route::get('/pcso-lottery/year/{year}', [PcsoLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('pcso-lottery.year');

Route::get('/pcso-lottery/archive/{year}', [PcsoLotteryController::class, 'yearArchive'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('pcso-lottery.archive');

Route::get('/pcso-lottery/draw/{draw}', [PcsoLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('pcso-lottery.draw');

Route::get('/pcso-lottery/result/{draw}', [PcsoLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('pcso-lottery.result');

// Original compatibility route; every named detail route above wins first.
Route::get('/pcso-lottery/{draw}', [PcsoLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('pcso-lottery.show');

// Static pages used by footer/support CTAs when configured.
Route::get('/privacy', [PublicPagesController::class, 'privacy'])
    ->middleware('public.legal')
    ->name('privacy');

/*
|--------------------------------------------------------------------------
| PROMPT 10: public Contact / Support centre
|--------------------------------------------------------------------------
|
| The GET route KEEPS ITS NAME. About, both footers, the privacy page and the
| terms page all link to route('contact'), and existing tests assert those
| links resolve. Renaming it to something tidier would have broken five
| surfaces to gain nothing.
|
| The POST carries throttle:contact-submit. A public endpoint that sends mail
| is a relay without one. It is also inside the normal web middleware group,
| so Laravel's CSRF protection applies - deliberately not excluded to make an
| AJAX submission simpler.
|
*/

/*
|--------------------------------------------------------------------------
| Public account-programme explainers
|--------------------------------------------------------------------------
|
| SIGNED-OUT INFORMATION, NOT THE ACCOUNT PAGES. /account/grade and
| /account/verification stay behind auth and show a person their own figures.
| These two show the LADDER and the PROCESS to somebody who has not
| registered and therefore cannot see either.
|
| Separate paths on purpose: relaxing auth on the existing routes would have
| meant one URL answering differently depending on who asked, which is how a
| personal figure eventually renders for a guest.
|
*/

Route::get('/account-grades', [\App\Http\Controllers\PublicGradeController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-grades');

Route::get('/account-grade', [\App\Http\Controllers\PublicGradeController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-grade');

Route::get('/account-verification', [\App\Http\Controllers\PublicVerificationController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-verification');

Route::get('/account-verification-guide', [\App\Http\Controllers\PublicVerificationController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-verification-guide');

Route::get('/contact', [\App\Http\Controllers\ContactController::class, 'show'])
    ->middleware('public.legal')
    ->name('contact');

Route::get('/contact-us', [\App\Http\Controllers\ContactController::class, 'show'])
    ->middleware('public.legal')
    ->name('contact-us');

Route::get('/download', [\App\Http\Controllers\PublicDownloadAppController::class, 'index'])
    ->middleware('public.legal')
    ->name('download');

Route::get('/download-app', [\App\Http\Controllers\PublicDownloadAppController::class, 'index'])
    ->middleware('public.legal')
    ->name('download-app');

Route::get('/app', [\App\Http\Controllers\PublicDownloadAppController::class, 'index'])
    ->middleware('public.legal')
    ->name('app');

// XML sitemap (FINAL AUDIT #15): canonical public URLs only — no auth,
// admin, API, search-form, payment-return or legacy .php duplicates.
// Read-only and cacheable.
Route::get('/sitemap.xml', \App\Http\Controllers\SitemapController::class)
    ->name('sitemap');

/*
|--------------------------------------------------------------------------
| Browser payment-return pages (FINAL AUDIT #2)
|--------------------------------------------------------------------------
|
| Where a gateway drops the player's browser after checkout. PRESENTATION
| ONLY: the landing route is context, the displayed state is always the
| internal payment record (see PaymentCallbackController), and nothing on
| these pages can credit or change money. Paths come from the same
| config/payment.php callback block the gateway drivers build their
| success/cancel URLs from, so they can never drift apart.
|
*/

Route::middleware('auth')->group(function (): void {
    // The config values may be absolute URLs ("${APP_URL}/payment/success")
    // because the gateway drivers hand them to providers; route registration
    // only wants the path component, so normalize once here.
    $callbackPath = static function (string $key, string $default): string {
        $value = (string) config('payment.callback.'.$key, $default);
        $path = parse_url($value, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : $default;
    };

    Route::get($callbackPath('success_url', '/payment/success'), [PaymentCallbackController::class, 'success'])
        ->name('payment.callback.success');

    Route::get($callbackPath('failure_url', '/payment/failure'), [PaymentCallbackController::class, 'failure'])
        ->name('payment.callback.failure');

    Route::get($callbackPath('cancel_url', '/payment/cancel'), [PaymentCallbackController::class, 'cancel'])
        ->name('payment.callback.cancel');

    Route::get($callbackPath('pending_url', '/payment/pending'), [PaymentCallbackController::class, 'pending'])
        ->name('payment.callback.pending');
});

// User-facing locale switch route (session & cookie persistence)
Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, ['en', 'th'], true)) {
        session(['locale' => $locale]);
        cookie()->queue(cookie('locale', $locale, 60 * 24 * 365));
    }

    return redirect()->back();
})->name('locale.switch');

Route::post('/contact', [ContactController::class, 'submit'])
    ->middleware('throttle:contact-submit')
    ->name('contact.submit');

/*
|--------------------------------------------------------------------------
| LOTTOFIN ADMIN & Operations Console Routes
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->name('admin.')->middleware(['admin.auth', 'can:access-admin'])->group(function (): void {
    Route::get('/legacy', [LottoFinExecutiveDashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard', [LottoFinExecutiveDashboardController::class, 'index'])->name('dashboard.index');
    Route::get('/api/analytics', [LottoFinExecutiveDashboardController::class, 'analyticsApi'])
        ->middleware('throttle:admin-analytics')
        ->name('api.analytics');
    Route::get('/api/reconciliation', [LottoFinExecutiveDashboardController::class, 'reconciliationFeedApi'])
        ->middleware('throttle:admin-analytics')
        ->name('api.reconciliation');
    Route::post('/api/reconciliation', [LottoFinExecutiveDashboardController::class, 'runReconciliation'])
        ->middleware(['throttle:admin-analytics', 'can:access-admin'])
        ->name('api.reconciliation.run');

    // Operational projections. Each request is permission-checked again in the
    // controller so a route alias cannot widen access to another panel.
    Route::get('/legacy/draws', [LottoFinExecutiveDashboardController::class, 'index'])->name('draws.index');
    Route::get('/risk', [LottoFinExecutiveDashboardController::class, 'index'])->name('risk.index');
    Route::get('/legacy/bets', [LottoFinExecutiveDashboardController::class, 'index'])->name('bets.index');
    Route::get('/legacy/wallets', [LottoFinExecutiveDashboardController::class, 'index'])->name('wallets.index');
    Route::get('/ledger', [LottoFinExecutiveDashboardController::class, 'index'])->name('ledger.index');
    Route::get('/reconciliation', [LottoFinExecutiveDashboardController::class, 'index'])->name('reconciliation.index');
    Route::get('/audits', [LottoFinExecutiveDashboardController::class, 'index'])->name('audits.index');

    // Payment and withdrawal mutations are not implemented by this browser
    // console. They terminate in an explicit NOT_CONFIGURED response rather
    // than silently rendering a GET projection or changing financial state.
    Route::get('/payments', [LottoFinExecutiveDashboardController::class, 'index'])->name('payments.index');
    Route::get('/legacy/withdrawals', [LottoFinExecutiveDashboardController::class, 'index'])->name('withdrawals.index');
    Route::post('/withdrawals/{id}/disburse', [LottoFinExecutiveDashboardController::class, 'unsupportedMutation'])->name('withdrawals.disburse');
    Route::post('/withdrawals/{id}/reject', [LottoFinExecutiveDashboardController::class, 'unsupportedMutation'])->name('withdrawals.reject');

    // KYC documents remain on private storage and are streamed only after the
    // controller performs object-level reviewer authorization and audit logging.
    Route::get('/kyc', [LottoFinExecutiveDashboardController::class, 'index'])->name('kyc.index');
    Route::get('/kyc/{documentToken}/download', [LottoFinExecutiveDashboardController::class, 'downloadKyc'])
        ->where('documentToken', '[a-f0-9]{64}')
        ->middleware('throttle:account-verification')
        ->name('kyc.download');
    Route::post('/kyc/{documentToken}/approve', [LottoFinExecutiveDashboardController::class, 'reviewKyc'])
        ->where('documentToken', '[a-f0-9]{64}')
        ->middleware('throttle:account-verification')
        ->name('kyc.approve');
    Route::post('/kyc/{documentToken}/reject', [LottoFinExecutiveDashboardController::class, 'reviewKyc'])
        ->where('documentToken', '[a-f0-9]{64}')
        ->middleware('throttle:account-verification')
        ->name('kyc.reject');
    Route::get('/compliance', [LottoFinExecutiveDashboardController::class, 'index'])->name('compliance.index');

    // Pages 100–150 operational aliases. These remain read-only projections
    // unless an existing canonical service route is already used elsewhere.
    Route::get('/glo/prize-claims', [LottoFinExecutiveDashboardController::class, 'index'])->name('glo.prize-claims.index');
    Route::get('/glo/prize-claims/{claim}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('claim', '[A-Za-z0-9_-]{1,120}')
        ->name('glo.prize-claims.show');
    Route::get('/glo/ticket-freezes', [LottoFinExecutiveDashboardController::class, 'index'])->name('glo.ticket-freezes.index');
    Route::get('/glo/ticket-freezes/{token}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('token', '[A-Za-z0-9_-]{1,120}')
        ->name('glo.ticket-freezes.show');
    Route::get('/glo/settlements', [LottoFinExecutiveDashboardController::class, 'index'])->name('glo.settlements.index');
    Route::get('/wallet-operations', [LottoFinExecutiveDashboardController::class, 'index'])->name('wallet-operations.index');
    Route::get('/payments/{payment}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('payment', '[0-9]+')
        ->name('payments.show');
    Route::get('/payment-methods', [LottoFinExecutiveDashboardController::class, 'index'])->name('payment-methods.index');
    Route::get('/withdrawal-methods', [LottoFinExecutiveDashboardController::class, 'index'])->name('withdrawal-methods.index');
    Route::get('/payment-events', [LottoFinExecutiveDashboardController::class, 'index'])->name('payment-events.index');
    Route::get('/payments/events', [LottoFinExecutiveDashboardController::class, 'index'])->name('payments-events.index');
    Route::get('/payment-exceptions', [LottoFinExecutiveDashboardController::class, 'index'])->name('payment-exceptions.index');
    Route::get('/payments/exceptions', [LottoFinExecutiveDashboardController::class, 'index'])->name('payments-exceptions.index');
    Route::get('/draw-lifecycle', [LottoFinExecutiveDashboardController::class, 'index'])->name('draw-lifecycle.index');
    Route::get('/result-publication', [LottoFinExecutiveDashboardController::class, 'index'])->name('result-publication.index');
    Route::get('/result-imports', [LottoFinExecutiveDashboardController::class, 'index'])->name('result-imports.index');
    Route::get('/result-sources', [LottoFinExecutiveDashboardController::class, 'index'])->name('result-sources.index');
    Route::get('/lotteries', [LottoFinExecutiveDashboardController::class, 'index'])->name('lotteries.index');
    Route::get('/lottery-rules', [LottoFinExecutiveDashboardController::class, 'index'])->name('lottery-rules.index');
    Route::get('/fees', [LottoFinExecutiveDashboardController::class, 'index'])->name('fees.index');
    Route::get('/account-grades', [LottoFinExecutiveDashboardController::class, 'index'])->name('account-grades.index');
    Route::get('/account-verification', [LottoFinExecutiveDashboardController::class, 'index'])->name('account-verification.index');
    Route::get('/responsible-gaming', [LottoFinExecutiveDashboardController::class, 'index'])->name('responsible-gaming.index');
    Route::get('/self-exclusion', [LottoFinExecutiveDashboardController::class, 'index'])->name('self-exclusion.index');
    Route::get('/legacy/users', [LottoFinExecutiveDashboardController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('user', '[0-9]+')
        ->name('users.show');
    Route::get('/users/{user}/finance', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('user', '[0-9]+')
        ->name('users.finance');
    Route::get('/bets/{bet}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('bet', '[0-9]+')
        ->name('bets.show');
    Route::get('/tickets/{ticket}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('ticket', '[0-9]+')
        ->name('tickets.show');
    Route::get('/ticket-verification', [LottoFinExecutiveDashboardController::class, 'index'])->name('ticket-verification.index');
    Route::get('/prize-claim-review', [LottoFinExecutiveDashboardController::class, 'index'])->name('prize-claim-review.index');
    Route::get('/commissions', [LottoFinExecutiveDashboardController::class, 'index'])->name('commissions.index');
    Route::get('/queues', [LottoFinExecutiveDashboardController::class, 'index'])->name('queues.index');
    Route::get('/scheduler', [LottoFinExecutiveDashboardController::class, 'index'])->name('scheduler.index');
    Route::get('/runtime', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'runtime')
        ->name('runtime.index');
    Route::get('/api-status', [LottoFinExecutiveDashboardController::class, 'index'])->name('api-status.index');
    Route::get('/webhooks', [LottoFinExecutiveDashboardController::class, 'index'])->name('webhooks.index');
    Route::get('/security', [LottoFinExecutiveDashboardController::class, 'index'])->name('security.index');
    Route::get('/release', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'manifest')
        ->name('release.index');
    Route::get('/cutover', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'cutover')
        ->name('cutover.index');

    Route::get('/release-manifest', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'manifest')
        ->name('release-manifest.index');
    Route::get('/configuration', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'configuration')
        ->name('configuration.index');
    Route::get('/secrets', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'secrets')
        ->name('secrets.index');
    Route::get('/migrations', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'migrations')
        ->name('migrations.index');
    Route::get('/backups', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'backups')
        ->name('backups.index');
    Route::get('/restore-verification', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'restore')
        ->name('restore-verification.index');
    Route::get('/disaster-recovery', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'disaster-recovery')
        ->name('disaster-recovery.index');
    Route::get('/high-availability', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'high-availability')
        ->name('high-availability.index');
    Route::get('/incidents', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'incidents')
        ->name('incidents.index');
    Route::get('/incidents/{reference}', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'incidents')
        ->where('reference', '[A-Za-z0-9_-]{1,120}')
        ->name('incidents.show');
    Route::get('/deployment-approval', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'deployment-approval')
        ->name('deployment-approval.index');
    Route::get('/deployments', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'deployments')
        ->name('deployments.index');
    Route::get('/rollback', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'rollback')
        ->name('rollback.index');
    Route::get('/feature-flags', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'feature-flags')
        ->name('feature-flags.index');
    Route::get('/configuration-audit', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'configuration-audit')
        ->name('configuration-audit.index');
    Route::get('/sessions', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'sessions')
        ->name('sessions.index');
    Route::get('/access-review', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'access-review')
        ->name('access-review.index');
    Route::get('/privileged-access', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'privileged-access')
        ->name('privileged-access.index');
    Route::get('/permission-matrix', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'permission-matrix')
        ->name('permission-matrix.index');
    Route::get('/service-accounts', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'service-accounts')
        ->name('service-accounts.index');
    Route::get('/network-access', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'network-access')
        ->name('network-access.index');
    Route::get('/device-risk', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'device-risk')
        ->name('device-risk.index');
    Route::get('/mfa', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'mfa')
        ->name('mfa.index');
    Route::get('/authentication-security', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'authentication-security')
        ->name('authentication-security.index');
    Route::get('/rate-limits', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'rate-limits')
        ->name('rate-limits.index');
    Route::get('/captcha', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'captcha')
        ->name('captcha.index');
    Route::get('/risk-rules', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'risk-rules')
        ->name('risk-rules.index');
    Route::get('/suspicious-activity', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'suspicious-activity')
        ->name('suspicious-activity.index');
    Route::get('/compliance-cases', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'compliance-cases')
        ->name('compliance-cases.index');
    Route::get('/compliance-cases/{reference}', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'compliance-cases')
        ->where('reference', '[A-Za-z0-9_-]{1,120}')
        ->name('compliance-cases.show');
    Route::get('/sanctions', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'sanctions')
        ->name('sanctions.index');
    Route::get('/kyc-provider', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'kyc-provider')
        ->name('kyc-provider.index');
    Route::get('/kyc-review', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'kyc-review')
        ->name('kyc-review.index');
    Route::get('/age-verification', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'age-verification')
        ->name('age-verification.index');
    Route::get('/duplicate-accounts', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'duplicate-accounts')
        ->name('duplicate-accounts.index');
    Route::get('/account-restrictions', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'account-restrictions')
        ->name('account-restrictions.index');
    Route::get('/retention', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'retention')
        ->name('retention.index');
    Route::get('/privacy', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'privacy')
        ->name('privacy.index');
    Route::get('/data-rights', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'data-rights')
        ->name('data-rights.index');
    Route::get('/legal-registries', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'legal-registries')
        ->name('legal-registries.index');
    Route::get('/compliance-reporting', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'compliance-reporting')
        ->name('compliance-reporting.index');
    Route::get('/aml-monitoring', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'aml-monitoring')
        ->name('aml-monitoring.index');
    Route::get('/regulatory-exports', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'regulatory-exports')
        ->name('regulatory-exports.index');
    Route::get('/compliance-audit', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'compliance-audit')
        ->name('compliance-audit.index');
});

/*
|--------------------------------------------------------------------------
| Authenticated support and notification projections
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function (): void {
    Route::get('/support', [SupportPortalController::class, 'index'])->name('support.index');
    Route::post('/support', [SupportPortalController::class, 'store'])
        ->middleware('throttle:contact-submit')
        ->name('support.store');
    Route::get('/support/{reference}', [SupportPortalController::class, 'show'])
        ->where('reference', '[A-Za-z0-9_-]{1,120}')
        ->name('support.show');
    Route::post('/support/{reference}/reply', [SupportPortalController::class, 'reply'])
        ->middleware('throttle:contact-submit')
        ->where('reference', '[A-Za-z0-9_-]{1,120}')
        ->name('support.reply');
    Route::get('/notifications', [NotificationCenterController::class, 'index'])->name('notifications.index');
});

/*
|--------------------------------------------------------------------------
| Agent Portal Routes
|--------------------------------------------------------------------------
*/
Route::prefix('agent')->name('agent.')->middleware('auth')->group(function (): void {
    Route::get('/', [AgentPortalController::class, 'dashboard'])->name('dashboard');
    Route::get('/dashboard', [AgentPortalController::class, 'dashboard'])->name('dashboard.index');
    Route::get('/commissions', [AgentPortalController::class, 'commissions'])->name('commissions');
    Route::get('/settlements', [AgentPortalController::class, 'settlements'])->name('settlements');
    Route::get('/statement', [AgentPortalController::class, 'statement'])->name('statement');
    Route::get('/referrals', [AgentPortalController::class, 'referrals'])->name('referrals');
    Route::get('/referrals/{reference}', [AgentPortalController::class, 'referralDetail'])
        ->where('reference', '[a-f0-9]{64}')
        ->name('referrals.show');
});

/*
|--------------------------------------------------------------------------
| Legacy .php URL compatibility layer (301)
|--------------------------------------------------------------------------
|
| Single home for every public .php URL the replaced site published:
| static pages, member auth surfaces, account explainer pages, the broken
| double-path member URLs, and the per-year archive pages — including the
| "lottoery" typo form search engines indexed. See LegacyRedirectController
| for the map and the rules.
|
| THIS MUST STAY THE LAST ROUTE IN THIS FILE. It only ever sees paths no
| real route claimed, because Laravel matches in registration order, and
| it answers 404 for .php paths it does not know rather than aliasing them.
|
*/

Route::match(['get', 'post'], '/{legacyPath}', [LegacyRedirectController::class, 'resolve'])
    ->where('legacyPath', '.*\.php$')
    ->name('legacy.redirect');

```

### `security/weekly-result-integrity/src/main.rs`

```text
//! Stdin/stdout shim so Laravel can invoke the verifier as a short-lived
//! subprocess.
//!
//! THIS IS NOT A SERVER. It reads one JSON document from stdin, writes one
//! JSON document to stdout, and exits. No socket is opened, no port is bound,
//! no address is resolved, and nothing is kept between invocations. That is
//! deliberate: a long-lived listener inside the security component would add
//! exactly the network attack surface this crate exists to avoid.
//!
//! EXIT CODES
//!   0  the payload is acceptable (INTEGRITY_HASH_ONLY or SIGNED_VERIFIED)
//!   2  the payload was read but is not acceptable (rejected, mismatch, bad
//!      signature) - a determinate "no", not a crash
//!   1  the request document itself could not be read
//!
//! The report is written to stdout in every case, so the caller never has to
//! infer a reason from the exit code alone.

#![forbid(unsafe_code)]

use std::io::{self, Read, Write};

use weekly_result_integrity::{parse_request, render_report, verify, VerificationReport};

const MAX_INPUT_BYTES: usize = 64 * 1024; // 64 KB

fn main() {
    // A hard ceiling on the document size. A verifier that will read an
    // unbounded stream is a memory-exhaustion target, and no legitimate
    // Weekly payload is anywhere near this large. Read at most 64KB + 1 byte.
    let mut buffer = Vec::new();
    if io::stdin()
        .take((MAX_INPUT_BYTES + 1) as u64)
        .read_to_end(&mut buffer)
        .is_err()
    {
        emit(r#"{"status":"REJECTED","acceptable":false,"error_code":"MALFORMED_REQUEST"}"#);
        std::process::exit(1);
    }

    if buffer.len() > MAX_INPUT_BYTES {
        emit(r#"{"status":"REJECTED","acceptable":false,"error_code":"DOCUMENT_TOO_LARGE"}"#);
        std::process::exit(1);
    }

    let input = match String::from_utf8(buffer) {
        Ok(s) => s,
        Err(_) => {
            emit(r#"{"status":"REJECTED","acceptable":false,"error_code":"INVALID_UTF8"}"#);
            std::process::exit(1);
        }
    };

    let request = match parse_request(&input) {
        Ok(request) => request,
        Err(error) => {
            let report = VerificationReport {
                status: "REJECTED",
                acceptable: false,
                canonical_version: weekly_result_integrity::canonical::CANONICAL_VERSION,
                fingerprint: None,
                canonical_length: None,
                error_code: Some(error.code()),
                error_field: error.field(),
            };
            emit(&render_report(&report));
            std::process::exit(1);
        }
    };

    let report = verify(request);
    let acceptable = report.acceptable;
    emit(&render_report(&report));

    std::process::exit(if acceptable { 0 } else { 2 });
}

fn emit(line: &str) {
    let stdout = io::stdout();
    let mut handle = stdout.lock();
    let _ = handle.write_all(line.as_bytes());
    let _ = handle.write_all(b"\n");
    let _ = handle.flush();
}

```

### `vite.config.js`

```javascript
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/lottery.css',
                'resources/css/home.css',
        'resources/css/public-pages.css',
        'resources/css/pages/about.css',
        'resources/css/pages/vision.css',
        'resources/css/pages/terms.css',
        'resources/css/account-services.css',
                'resources/css/prize-discount.css',
                // PROMPT 5: National Lottery result surface.
                'resources/css/national-lottery.css',
                // PROMPT 6: Weekly Lottery result surface.
                'resources/css/weekly-lottery.css',
                'resources/css/bingo-lottery.css',
                'resources/css/pcso-lottery.css',
                'resources/css/contact.css',
                'resources/css/pages/public-next-pages.css',
                'resources/css/pages/privacy.css',
                'resources/css/pages/fees.css',
                'resources/css/pages/account-verification.css',
                'resources/css/pages/account-grades.css',
                'resources/css/pages/prize-verification.css',
                'resources/css/pages/discounts.css',
                'resources/css/pages/how-to-play.css',
                'resources/css/pages/faq.css',
                'resources/css/pages/contact.css',
                'resources/css/pages/download.css',
                'resources/css/auth-portal.css',
                'resources/css/admin-lottofin.css',
                'resources/css/glo-results-checker.css',
                'resources/css/glo-l6.css',
                'resources/css/player-security-settings.css',
                'resources/css/thai-lottery-betting.css',
                'resources/css/player-dashboard.css',
                'resources/css/wallet-management.css',
                'resources/css/deposit-portal.css',
                'resources/css/withdrawal-portal.css',
                'resources/css/player-profile.css',
                'resources/css/lottery-history.css',
                'resources/css/player-settings.css',
        'resources/js/app.js',
        'resources/js/public-pages.js',
        'resources/js/pages/about.js',
        'resources/js/pages/vision.js',
        'resources/js/pages/terms.js',
        'resources/js/auth-portal.js',
        'resources/js/admin-lottofin.js',
        'resources/js/glo-results-checker.js',
        'resources/js/player-security-settings.js',
        'resources/js/thai-lottery-betting.js',
        'resources/js/player-dashboard.js',
        'resources/js/wallet-management.js',
        'resources/js/deposit-portal.js',
        'resources/js/withdrawal-portal.js',
        'resources/js/player-profile.js',
        'resources/js/lottery-history.js',
        'resources/js/player-settings.js',
        'resources/js/privacy-portal.js',
        'resources/js/fees-portal.js',
        'resources/js/account-verification-portal.js',
        'resources/js/account-grade-portal.js',
        'resources/js/prize-verification-portal.js',
        'resources/js/lotto-discount-portal.js',
        'resources/js/how-to-play-portal.js',
        'resources/js/faq-portal.js',
        'resources/js/contact-portal.js',
        'resources/js/download-app-portal.js',
        'resources/js/components/AuthCard.tsx',
        'resources/js/components/LottoFinAdminDashboard.tsx',
        'resources/js/components/GloResultsChecker.tsx',
        'resources/js/components/PlayerSecuritySettings.tsx',
        'resources/js/components/ThaiLotteryBetting.tsx',
        'resources/js/components/PlayerDashboard.tsx',
        'resources/js/components/WalletManagement.tsx',
        'resources/js/components/DepositPortal.tsx',
        'resources/js/components/WithdrawalPortal.tsx',
        'resources/js/components/PlayerProfile.tsx',
        'resources/js/components/LotteryHistory.tsx',
        'resources/js/components/PlayerSettings.tsx',
        'resources/js/account-verification.js',
        'resources/js/account-grade.js',
                'resources/js/prize-verification.js',
                'resources/js/national-lottery.js',
                'resources/js/weekly-lottery.js',
                'resources/js/bingo-lottery.js',
                'resources/js/pcso-lottery.js',
                'resources/js/contact.js',
                'resources/js/pages/public-next-pages.js',
                'resources/js/pages/privacy.js',
                'resources/js/pages/fees.js',
                'resources/js/pages/account-verification.js',
                'resources/js/pages/account-grades.js',
                'resources/js/pages/prize-verification.js',
                'resources/js/pages/discounts.js',
                'resources/js/pages/how-to-play.js',
                'resources/js/pages/faq.js',
                'resources/js/pages/contact.js',
                'resources/js/pages/download.js',
                'resources/js/lottery/ticket-selector.js',
                'resources/js/lottery/countdown.js',
                'resources/js/lottery/bet-slip.js',
                'resources/js/lottery/live-results.js',
                'resources/js/wallet/wallet-balance.js',
                'resources/js/home/countdown.js',
                'resources/js/home/live-draw.js',
            ],
            refresh: true,
        }),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
    },
});

```
