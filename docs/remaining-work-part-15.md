# Part 15 — actual browser wagering application fixes

Date: 2026-10-14. Pinned base HEAD: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.

Delivery: File139–File149, eleven complete files, no elision. Cumulative overlay: 146 unique paths. Next: File150. This part changes application behavior, not only expected test strings.

## Six actual application source fixes

### 1. Correct spendable wallet and amount

`PlayerWebController::betSlip` now resolves the authenticated owner's **Primary** wallet in the canonical lottery currency, not the first wallet row, which could be a Win/Bonus/foreign-currency wallet. The Blade card labelled Available Balance now displays `getAvailableBalance()`, not total balance including reservations. Formatting still uses the existing Money value object. There is no float monetary assignment or new financial authority.

A regression creates Win first, then Primary with 500.00 total and 475.25 reserved: the page must identify Primary and display **24.75**, not Win's 9999.00 or Primary's full 500.00.

### 2. Eligible draw, not arbitrary Open status

The page excludes Open-status draws whose betting window is in the future or already expired, then selects deterministically by schedule/ID. The canonical purchase service still validates draw state/time under its own transaction boundary; a page rendered earlier does not authorize a later closed-draw purchase. Existing null timestamp semantics remain unchanged.

The displayed item ceiling cannot exceed the two HTTP request surfaces' 50-item limit; a lower configured domain ceiling is reflected. Operator payout rates and limits are not re-priced.

### 3. Working authenticated JSON browser transport

`bet-slip.js` now uses the real existing `/bet/purchase` session-authenticated web route and the server-rendered CSRF token. It posts JSON and stays on the bet page. It does **not**:

- navigate to a raw JSON document with native `form.submit()`;
- append duplicate hidden selection fields on each retry;
- embed a bearer token or turn on Sanctum stateful authentication;
- transmit to a cross-origin destination;
- allow a second in-flight submission or edit while a submitted slip is unresolved.

Form submits/Enter and the button share the same guarded transport. Ownership is taken from the authenticated backend session, never the DOM or posted user ID.

### 4. Unknown outcomes and partial commits handled honestly

The canonical `BulkBetService` commits each selection independently; partial success is legitimate. The browser previously cleared the whole slip on any HTTP 2xx and announced acceptance, losing refusals. It also allowed selection changes after timeouts while claiming the same client key prevented duplicates. In fact, item keys include index/market/number/stake: changing the payload can create a different purchase even with the same slip key.

The fix:

- freezes the exact payload/key before transmission;
- saves it before sending in same-tab sessionStorage, scoped by authenticated user ID plus draw ID;
- preserves leading-zero numbers and decimal stake strings;
- recovers the unchanged pending payload after same-tab reload;
- locks add/remove/merge while pending or in flight;
- retries only the identical payload/key when the connection/report is unknown;
- treats missing/malformed/mismatched reports and unexpected statuses as unknown, not uncharged;
- requires a complete matching per-item report, draw ID, indices, identities, outcome counts and ticket/bet identifiers for accepted/replayed items before resolving;
- displays purchased/replayed/refused counts, actual newly charged amount and each refusal/ticket;
- clears pending state and rotates the key only after a complete recognized report;
- preserves a sticky betting-closed state across later UI interactions.

Storage failures/corruption disable new transmission rather than discard identity and generate a potentially duplicate purchase. A report whose stored pending record cannot be removed remains unresolved in the UI. This is deliberate conservative handling, not silent success.

This is **same-tab recovery**, not a server-side multi-device durable slip journal. Closing the tab, changing accounts/draws or manually clearing storage still requires checking My Bets/receipts before creating another slip. Such a journal and an actual network-crash Laravel/browser integration remain separate acceptance work.

### 5. Exact decimal preview

Browser stake sums and payout previews now use BigInt decimal scaling/truncation, not Number multiplication/Math.round/toFixed. The selector passes the configured multiplier as its original attribute string. The backend remains authoritative for actual charges/payouts.

A Chromium test verifies a 999999999999.99 stake at multiplier 900.12345678 previews **900123456779990.99** without binary-float drift. Merging beyond the request stake-width limit is rejected. No client-side payout/multiplier is sent to the purchase backend.

### 6. Browser input cannot supply financial authority

`BetPurchaseRequest` explicitly rejects owner/wallet IDs, payout/multiplier, status, transaction/ticket identifiers and other server-owned fields at the top level or within items. Supplying both draw ID and public reference must identify the same draw; an ambiguous pair is refused before money execution. Legacy single-identifier transport stays supported.

These are stricter checks at the **application write entry point**. No role bypass, monetary mass-assignment exception, direct wallet writer or replacement ledger engine was introduced. Canonical purchase rules, responsible-gaming limits, number-limit reservations, wallet locks and per-item idempotency remain the money execution authority.

## Actual money execution proof — what was run

`BrowserBetFinancialBoundaryTest` contains 20 collected cases in the guarded disposable SQLite database, with real Laravel routes and canonical purchase/ledger transactions, not a mocked money service:

- UI wallet/window/ceiling checks described above.
- Two-item partial purchase against a 50.00 fixture wallet: first selection charges 20.00, second is refused for insufficient funds; wallet ends at **30.00**.
- Exactly one bet, ticket and financial transaction; posted debit/credit amounts each total **20.00**.
- Reposting the exact request returns one replay/one refusal, charges **0.00** newly and leaves wallet/bet/ticket/transaction/ledger counts unchanged.
- Fourteen top-level/nested authority-field tampering cases refuse with validation errors and no bet/ledger/money change.
- Conflicting draw identifiers refuse before any transaction.
- Real CSRF middleware is exercised with Laravel's unit-test skip temporarily disabled while keeping the guarded test database: a missing token gets **419** and creates no bet/debit. The environment is restored in finally.

The initial balance is synthetic fixture funding. These tests prove actual canonical database mutations/rollback/replay behavior in that sandbox; they do **not** prove a bank deposit, merchant acceptance, production concurrency or customer funds.

## Actual browser proof — scope is explicit

`tests/browser/bet-slip-transport.spec.mjs` runs the actual JavaScript files in real desktop/mobile Chromium: **16 passes** across two projects. Its HTTP transport is deliberately intercepted, so these are not claimed as full Laravel/browser or bank E2E tests.

Eight journeys per project cover session JSON/CSRF request shape and no navigation, partial refusal display, lost-response exact retry across reload, malformed success handling, in-flight double-submit/edit prevention, sticky closure, large exact decimal preview/merge limit, and corrupt recovery refusal. The harness cannot charge a wallet. The separate PHP suite above executes the actual money service.

The existing eleven frontend tests are retained, including dashboard countdown. Their bet-form checks now supply an actually open fixture draw: no-open-draw pages are correctly empty and must not fabricate a purchasable draw just to render options.

## Validation

| Check | Result |
|---|---|
| Built original frontend + existing web purchase baseline | 17 tests / 127 assertions / three reproduced frontend fixture failures |
| Final Player suites: frontend, web purchase, new financial boundary, presentation, comprehensive | 92 tests / 583 assertions, pass |
| Desktop/mobile Chromium mock-transport DOM suite | 16 tests, pass |
| Standalone static page contracts | 15 tests / 991 assertions, pass |
| Inventory freshness / offline guards | Current generated inventory unchanged; 9 guards pass |
| Source scanner / offline SQL runner guards | 11 / 8 tests pass; no SQL server contacted |
| PHP lint/Pint and JavaScript syntax | Delivered application/test source files pass |
| Frontend production build | Pass; generated assets excluded from source overlay |
| Draw/GLO/operator write matrix | 111 tests / 683 assertions, pass |
| Console | 45 tests / 204 assertions, pass |
| Broad financial/Payment/Finance | 209 tests / 991 assertions, pass |
| Betting/Risk/SQLite retry | 63 tests / 300 assertions, pass |
| Wallet conversion | 71 tests / 259 assertions, pass |
| Current repository collection | 2,350 cases, not proof all executed |
| Authoritative final serial repository diagnostic | 1,746 tests / 119,870 assertions / one missing CI-environment failure; later cases unexecuted |

Database writers run serially. Scoped matrices overlap and are not unique coverage totals. Runtime evidence includes unbuilt-dependency baseline errors, intermediate new-test envelope/browser-dependency errors, and an interrupted earlier diagnostic; none are hidden or reported as final passes. The earlier diagnostic was stopped after detecting an accidental test-edit loss, restoring all eleven original frontend cases and rerunning. Only the separately labelled final serial diagnostic is authoritative.

## Next code blocker — File150 onward

The final serial run passed the repaired player boundaries and stopped at
`Tests\Feature\Production\ProductionConfigSafetyTest::the_ci_environment_never_enables_a_fixture_lottery_lane`:
the required secret-free committed CI environment template `.env.ci` is absent.

The entire unchanged production config class was then isolated: **20 tests / 46 assertions / one error and four failures**. It additionally reports a missing `.env.production.example`, a CI workflow copying the development environment, and the production guard failing to reject a GLO fixture entry in source priority.

Next fix the actual production provisioning/source guard, not its assertions: supply explicitly secret-free CI/production templates, remove development-template copying from CI, and enforce fixture-priority rejection at the production safety boundary. Keep real credentials out of source and preserve explicitly disabled live payout/provider flags until operator acceptance. No template should create a fake official-source certification.

There are 604 collected cases beyond the observed repository stop; they are not assumed passed. All eleven existing frontend cases are retained; the new 20 financial boundary cases and 14 earlier presentation cases were exercised both in the 92-case player matrix and before this repository stop.

## Concrete path to externally funded/live operation

The user requested real-money functionality, so the next activation dependency is a **specific payment rail and authorized merchant/operator configuration**, not another vague status sentence. Do not post secrets in chat or source files.

Repository candidates in `config/payment.php` and `app/Services/Payment/Drivers` include Stripe, bKash, Nagad, Crypto and Bank Transfer. Source presence is not proof those providers permit this business/country/currency. PromptPay configuration explicitly describes an incomplete contract; enabling a flag alone does not implement it. Manual bank instructions/approval are not automatic bank execution.

Required inputs and proofs, in execution order:

1. Choose the actual permitted operator jurisdiction/entity, settlement currency and payment provider or manual bank rail; confirm eligibility with the provider and qualified compliance review. A city/location or public reference site is not authority.
2. Obtain an authorized **sandbox** merchant account and keep keys/webhook secret in the operator's secret-management environment. Record the approved callback origins/provider API version. Keep live execution disabled during verification.
3. Test deposit initiation, signed webhook authenticity, duplicate/out-of-order callbacks, amount/currency/owner mismatch, replay, cancellation/expiry and actual provider-to-ledger reconciliation against that sandbox. No fixture response can stand in for this proof.
4. Verify withdrawal KYC/destination eligibility, reservation, separate authorized maker/checker approval, provider execution reference, failure/refund/reversal, callbacks and statement reconciliation. Do not reinterpret an Approved/Paid label as bank delivery.
5. Run actual authenticated browser → Laravel session/CSRF → canonical purchase/refusal/replay → ledger/receipt/history journeys in isolated staging, including crash/reload after real server commits. Current Chromium tests mock transport and do not close this gate.
6. Verify intended production SQL topology/isolation, queues/outbox/crash recovery, migrations/backups/restore, actor revocation, secrets/least privilege, monitoring and reproducible deployment. Historical MariaDB races do not certify a different topology.
7. Only after provider/compliance approval and measured staging evidence should an authorized operator execute a controlled live funding/payout/reconciliation acceptance procedure. This turn neither flips a live flag nor sends customer funds.

Choose the provider first so the next implementation can target a real contract rather than guessing merchant economics or copying branding from thailotto.club. Legacy member transfer and alternate GLO direct writer stay disabled; official draw authenticity and settlement proof gates remain authoritative.

## Delivery integrity

All prior 135 delivered paths remain byte-identical because these application source paths were not in earlier overlays. This is incidental archive integrity, **not** a refusal to change application code: five actual application files are now delivered across six fix areas with fixes above. The overlay has 146 unique paths, excludes secrets/dependencies/uploads/databases/generated assets, and all eleven File139–File149 bodies are complete in `Full-Code-Continuation-Files-139-149.md`. ZIP CRC, path uniqueness, byte equality and SHA-256 are checked. Next label: File150.
