# Part26 — Bind provider callbacks to the correct money obligation

Date: 2026-10-15. Base repository HEAD: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.
Full continuation: File323–File352, thirty complete bodies; next File353. Cumulative overlay: 292 unique paths. Of the earlier 272 delivered bodies, 262 remain identical and ten are intentionally revised. Twenty paths are first included in this overlay; several are revisions of files already present in the base repository, not twenty newly invented files.

## Why this is an application fix

A valid provider signature authenticates a sender. It does not give that sender authority to settle a different provider's deposit, debit an unrelated withdrawal, replace a known transaction reference, choose a wallet owner/currency, or bypass a human approval.

The previously delivered/base callback implementation was exercised before changing its behavior:

- New obligation-binding baseline: **25 tests, 57 assertions, 18 failures and one application error**. Foreign-provider callbacks could affect deposits/withdrawals/reversals; some missing/contradictory money facts were accepted; pending withdrawals were automatically approved; ambiguous or conflicting references were not rejected. The application error was BCMath receiving scientific notation (`4e2`) without prior decimal validation. The ValueError was in application code, not a test/bootstrap error.
- Parser baseline subset: **12 tests, 12 assertions, 12 failures**. Missing/invalid currency silently became THB, BDT or USD, and missing amounts became invented zero amounts.
- The obligation baseline also contained passing negative/positive controls. These are not claimed as newly fixed defects.

This part adds real service/driver changes, not only tests or documentation. No merchant call or live transfer was used to reproduce these failures.

## Production behavior now implemented

### Explicit provider money facts

`WebhookMoneyFacts` preserves absence as null. Unknown/missing currencies are not replaced by a country default. Decimal amounts accept exact string/integer representations within the supported two-decimal shape, without float conversion, exponent notation, negative values or sub-cent truncation. Stripe minor units must be an exact nonnegative integer before division by 100. Unsupported/malformed values return null instead of throwing BCMath errors or guessing.

Stripe, bKash, Nagad and Crypto parsers use these rules. This is about trustworthy callback facts, not approval to activate those providers. The legacy manual bank parser still produces an Unknown event and is not an authenticated bank callback rail.

### One locked, coherent obligation

`PaymentWebhookBindingService` is shared by the legacy normalized deposit, withdrawal and full-reversal paths. It requires an existing SQL transaction and an allow-listed model, and verifies:

1. Nonempty bounded event/provider reference, recognized provider method, explicit supported currency, positive exact decimal amount, and a success flag consistent with the event type.
2. A supplied internal reference identifies exactly one row. A wrong supplied internal reference never falls back to a different row by provider reference.
3. Provider-reference-only lookup is scoped to the recorded provider method and must be unambiguous. The existing `manual`/`bank_transfer` alias is canonicalized; arbitrary provider aliases are not invented.
4. The lock order is wallet, obligation, then existing payment. The obligation is re-read after waiting. Wallet owner, wallet identity, wallet currency, obligation currency, recorded method/provider and callback currency must agree.
5. A deposit amount must equal its recorded gross amount. For withdrawals the existing explicit gross-or-net acceptance is preserved; neither choice converts currencies or changes fees.
6. Existing obligation/payment provider references cannot be replaced by an unrelated callback reference. If an attached Payment exists, its owner, currency, method, gateway, gross amount and fee must agree. Multiple attached payments are refused rather than selected arbitrarily.

An unbound callback returns failure before creating a payment, approving a request, consuming/releasing a hold, changing an obligation, posting a ledger entry or reversing a transaction. Reversal is still full-obligation reversal only; partial amounts are not silently treated as full refunds.

### Approval is not a webhook side effect

A success callback may settle an **Approved** or **Processing** withdrawal through the existing completion service. It may not approve a Pending withdrawal. This remains true even if some other auto-approval mode is configured: approval/reservation must happen through the existing approval/disbursement boundary first. A success in an inadmissible review state also cannot fall through into the failure branch and release a hold.

A refused pending callback does not permanently poison the cache event key: after explicit approval the same event can be retried and settles once. A valid failure releases a reservation without creating a refund credit. A bound completed/reversed obligation has benign idempotent replay behavior.

### SQL concurrency recovery, not external transfer retry

The first real callback race run of the new lock implementation exposed MariaDB `HY000/1020` on a wallet locking read after a competing transaction changed the row. This intermediate implementation failure was not misreported as an original-application baseline defect. The diagnostic probe records only exception class, SQLSTATE, numeric driver code, allow-listed table and statement verb; it does not emit SQL, credentials or arbitrary exception messages.

The callback SQL transaction now has **three bounded Laravel concurrency retries**. Each failed attempt is rolled back; there is no external gateway call inside that closure. This is not a retry of an outbound bank transfer or an ambiguous gateway acknowledgement. Real races then passed repeatedly with the new implementation.

## Real InnoDB callback acceptance

Four new server-only cases use independent PHP processes and SQL connections. The parent holds the decisive wallet lock and verifies both specific child connections simultaneously wait on it before releasing them. Process-local array caches deliberately do not deduplicate the two processes: SQL identity/locks and existing financial idempotency must provide the guarantee.

| Case | Required terminal evidence |
|---|---|
| Concurrent duplicate deposit success | Exactly one 400.00 credit, one financial transaction, two ledger entries and one payment; the second event replays. |
| Concurrent duplicate withdrawal success | Exactly one 400.00 debit, one financial transaction, two ledger entries, completed withdrawal and zero remaining hold. |
| Concurrent withdrawal success and failure | Either completion wins (600.00 balance, one debit) or failure wins (1000.00 balance, no debit); no mixed terminal state, duplicate debit or lingering hold. |
| Valid credit versus foreign-provider callback | Only the correctly bound provider succeeds; the foreign callback changes no money/payment identity. |

The runner has an exact `--suite webhooks` profile (four cases) and includes it in `--suite all` (now **21 cases**). All-profile acceptance requires seventeen valid contention witnesses: thirteen existing cases plus four new callback cases. The exact case classifier and refusal guards still reject missing, skipped, duplicated or failed cases.

## Final executed checks

| Check | Result |
|---|---|
| Entire payment feature directory | **149 tests / 562 assertions PASS**, separately on SQLite and MariaDB; no skips. |
| Provider callback/financial races | **3 × 21 tests / 437 assertions PASS**, seventeen exact owner-specific lock-wait witnesses per run, no skips/errors/failures. Includes all four new callback races in each iteration. |
| Existing worker recovery refreshed | **3 × 9 tests / 444 assertions PASS**, seven killed-worker recovery witnesses each. |
| Existing private Redis recovery refreshed | **3 × 7 tests / 353 assertions PASS**, seven actual Redis hard-restart witnesses each. |
| Final full default regression | **2616 tests / 130757 assertions / 34 explicit server-only skips**, no errors/failures, actual process exit 0. |
| Offline Python guard suites | **70 PASS**. |
| PHP syntax / focused formatting | **1669 PHP files** lint pass; Pint **16 files** pass. |
| Native verifier | Unchanged Rust crate **27 tests PASS**; native release executable rebuilt before PHP execution. |
| Frontend | Locked Node dependencies restored and production asset build passes. |
| Source/CI checks | Static financial preflight, exact inventory, test inventory and whitespace pass. CI YAML and ten Bash blocks syntax-check; all server writers remain serial. Hosted CI was not executed here. |

The 34 default skips are exactly nine SQL worker recovery cases, nine conversion races, four settlement races, seven Redis cases, four new callback races and one settlement simulation race. The acknowledged server profiles cover them separately. Shared cases across matrices must not be added as independent unique test coverage.

The first complete regression found three old unit parser fixtures that expected a currency without supplying it. They were corrected to provide explicit BDT/BDT/USD in their signed positive payloads, without removing or weakening their signature/parsing assertions. Two existing HTTP signature fixtures likewise now use explicit BDT/USD and matching wallet currencies. New parser tests independently require missing/invalid currency to remain null. The **entire full suite was rerun** after the three unit fixture corrections and passed; a focused rerun is not being substituted for final complete acceptance.

Actual local runtime: PHP 8.4.26, MariaDB 11.8.6, PhpRedis 6.2.0, Redis 8.0.2, Unix-socket-only disposable services. One hundred eight drifted/missing earlier overlay paths were restored before implementation. All schema-writing suites were run serially against dedicated test stores.

## Multi-country and provider scope

Valid exact-once deposit settlement is exercised separately in THB/Stripe, USD/Crypto and BDT/bKash at the normalized service boundary. Existing signed HTTP deposit tests now carry the corresponding explicit currencies. A valid signed bKash request targeting a Stripe obligation is rejected with unchanged money/payment state.

These facts do not add universal currency support, make Thai bank names a worldwide routing standard, or authorize merchant/jurisdiction combinations. No country, provider, KYC, operator permission, wallet-transfer, approval or fee gate is bypassed. No foreign-exchange operation is inferred from a callback currency. Existing multi-country own-wallet architecture remains unchanged and unapproved combinations remain fail-closed.

The withdrawal races use trusted synthetic **normalized** callbacks and real financial services/SQL, not live provider APIs. Existing concrete drivers do not thereby acquire a production outbound payout/withdrawal webhook integration. Providers must supply or verifiably normalize explicit amount/currency and canonical transaction references before this boundary will accept them. If a provider uses different checkout/payment/refund IDs, an authenticated, reviewed mapping is needed; a mismatched reference is not automatically treated as equivalent.

## Reproduce on a disposable checkout only

Restore the pinned dependencies/assets/native executable. Prepare a dedicated socket-only InnoDB database named exactly `thai_lottery_test`, a dedicated test account with the existing inspection privileges, and no deployment `.env` or cached configuration. Fixtures migrate/truncate that schema and must never see customer data.

```bash
python3 scripts/run_financial_concurrency.py \
  --suite all \
  --database thai_lottery_test \
  --connection mariadb \
  --socket "$DB_SOCKET" \
  --username financial_test \
  --acknowledge-disposable-database \
  --repeats 3 \
  --json evidence/server-races-local.json
```

Use `--suite webhooks` instead of `all` to select just the four callback races. Supply any database password through the process environment, never a printed command/report. Run schema-writing profiles serially. The workflow already selects `all`, so it now includes the new cases without a second concurrent writer.

## Remaining operational work

This part hardens the legacy normalized callback path; it is not a universal merchant integration or a replacement for upstream signature verification. The direct `processWebhookPayload` API is an internal trusted-normalization boundary, not an unauthenticated HTTP interface. The additive envelope/reconciliation callback service remains a separate path with its own pre-existing controls and requires separate provider-specific acceptance.

Priority remaining work includes the **outbound sender's uncertain-result handling and durable dispatch claim**: an external timeout must not be assumed to mean money never moved, and worker retry must not re-send an uncertain transfer. Those are distinct from this part's SQL-only callback retries and are not certified by these results. Deployment-specific provider-reference mappings, controlled reconciliation, country/merchant authorization, actual provider contracts, managed Redis/remote failover, power/disk-loss acceptance, monitoring and operator sign-off also remain required.

No real transfer, live credentials, official result authentication or universal country approval was established. Do not activate a provider or manually repair financial records based only on this test package.

## Complete delivery and preservation

File323–352 contain every delivered body in full. Ten earlier paths are revised: shared race runner/test/probe/trait, CI workflow, passive inventory script and four generated inventory/report artifacts. Twenty paths are first delivered here: seven production source bodies, seven test source bodies, three server reports, one static preflight report, one complete runtime log and this document. All earlier money/fee/conversion sources and the three settlement jobs that were part of the 272-path overlay remain byte-identical. Dependencies, generated assets, native executables, customer data, runtime `.env`, SQL/Redis storage and sockets are excluded from the cumulative source ZIP.
