# BATCH 05 — Payments, Support and Ops

Applied by `apply-batch-05.sh` from the repository root. See `DELETE-LIST.txt`
for the one deletion and its evidence.

```
./apply-batch-05.sh /path/to/thai-lottery-ainul-islam
```

The script copies only the files in this tree, applies the deletion, completes
the three `.env` templates, and prints the verification commands that must be
run before the result is a release. It refuses to run over modified tracked
files, so a failed apply can be reverted with one `git checkout -- .`.


## What this batch does

### 1. The Thai bank payout rail is routed, never chosen
`App\Enums\PaymentMethod` still has no `thai_bank` case, so no request body,
query string or header can name the automated payout rail. A player chooses
`bank_transfer` or `promptpay`; `WithdrawalDisbursementService` decides from
server-side configuration and the stored destination whether this rail can pay
it, and persists the key on the withdrawal **before** the external call so the
row that was paid is the row that is reconciled.

* `PaymentGatewayManager::driver('thai_bank')` and `withdrawalDriverByKey()`
  resolve the internal key back to the driver. `DepositRequest` rejects a
  client-submitted `thai_bank` explicitly (`not_in`), not just by omission.
* `ThaiBankPayoutPollDispatcher` is the scheduled sweep that dispatches the
  per-attempt reconciler for payouts still awaiting a provider outcome. It only
  dispatches; it never transmits a transfer. It exists because a queue that
  lost a delayed job would otherwise leave a hold stranded forever.
* `ThaiRailsServiceProvider::bootSchedule()` registers the two recurring
  entries through `callAfterResolving(Schedule::class, …)`, each guarded by its
  own lane flag, so a deployment that has not enabled a lane schedules nothing.

### 2. The deposit surface tells the truth about its rails
`GET /api/v1/deposit/methods` derives `enabled` and `state` from the same
gateway manager the initiation path uses, so a disabled or unconfigured rail is
reported `NOT_CONFIGURED` instead of rendering a button that only ever errors.
A bank-transfer intent returns the **configured** receiving accounts and the
reference the player must quote; with no receiving account configured it
refuses with `503 NOT_CONFIGURED` rather than inventing a bank account. The
deposit it creates is `PENDING` — nothing is credited by this route.

Slip verification is **delegated**: the compatibility route rebuilds the
canonical `VerifySlipRequest`, runs the same validation, and hands the same
request to the same `SlipVerificationController`. There is one implementation
of slip verification, not two.

### 3. The payout compliance gate exists, and refuses by name
`FinancialComplianceGateService` composes the lanes that already existed (the
real-money switch, KYC standing, compliance case/hold, protection windows, AML
band, configured bounds) in one fixed order. Both verbs throw by name, so a
caller cannot mistake "not checked" for "allowed", and every refusal writes one
audit row naming the rule, the operation, the amount and the currency — never a
name, an e-mail, a document number, a destination or a token.

### 4. One set of lane checks, three readers
`ThaiRailsChecks`, `RealtimeChecks` and `LaneFlagChecks` now hold the questions
the commands, `ProductionPreflight` and the tests used to ask separately. The
commands are thin wrappers over them: **the output lines and exit codes of
`thai-rails:preflight` and `realtime:check` are unchanged**, including the
`PASS [lane] disabled (skipped)` wording a deploy gate parses.

`ProductionPreflight` gained the Thai rails, the realtime lane, the optional
lanes, the payout compliance gate and the payout routing. A lane that is off is
advisory; a lane that is on and broken is blocking.

### 5. The environment surface is complete
All three `.env` templates were missing **557** keys that `config/*.php`
actually reads. They are now present, each with the same default its config file
already uses, so copying a template cannot change behaviour — an absent key and
a key set to its own default are identical to Laravel. `scripts/complete-env-templates.py`
regenerates the block when a new config key is added.


## Invariants this batch keeps

* A disabled lane is never reported as a working one, and never blocks a
  release.
* A rail that is switched on and misconfigured blocks the release.
* No scheduled task, check, log line or audit row carries a credential, an
  account number, a destination or a personal identifier.
* A refused money movement leaves balances, holds and ledgers exactly as it
  found them.
* Nothing a client sends chooses a payout rail, names an internal gateway key,
  or supplies a wallet.


## Files

| Path | Change |
| --- | --- |
| `app/Services/Compliance/FinancialComplianceGateService.php` | new |
| `app/Services/Payment/Drivers/ThaiBankPayoutGateway.php` | modified |
| `app/Services/Payment/PaymentGatewayManager.php` | modified |
| `app/Services/Payment/WithdrawalDisbursementService.php` | modified |
| `app/Services/Payment/WithdrawalReconciliationService.php` | modified |
| `app/Support/Ops/CheckResult.php` | new |
| `app/Support/Ops/ThaiRailsChecks.php` | new |
| `app/Support/Ops/RealtimeChecks.php` | new |
| `app/Support/Ops/LaneFlagChecks.php` | new |
| `app/Support/Ops/ThaiBankPayoutPollDispatcher.php` | new |
| `app/Support/Ops/ProductionPreflight.php` | modified |
| `app/Console/Commands/Ops/ThaiRailsPreflightCommand.php` | modified |
| `app/Console/Commands/Ops/RealtimeCheckCommand.php` | modified |
| `app/Providers/ThaiRailsServiceProvider.php` | modified |
| `app/Http/Controllers/Payment/DepositMethodsPageController.php` | modified |
| `app/Http/Controllers/Payment/WithdrawalMethodsPageController.php` | modified |
| `app/Http/Requests/Payment/DepositRequest.php` | modified |
| `config/payment.php` | modified |
| `.env.example`, `.env.production.example`, `.env.ci` | modified |
| `tests/Feature/Payment/ThaiBankRoutingTest.php` | new |
| `tests/Feature/Payment/DepositMethodsCompatCapabilityTest.php` | new |
| `tests/Feature/Payment/FinancialComplianceGateTest.php` | new |
| `tests/Feature/Payment/ProductionPreflightLaneChecksTest.php` | new |
| `scripts/complete-env-templates.py` | new |
| `app/Services/Payment/WithdrawalCompletionService.php` | **deleted** |


## Before this is a release

```
composer dump-autoload -o
find app tests -name '*.php' -print0 | xargs -0 -n1 php -l
php artisan config:clear && php artisan route:clear
vendor/bin/phpunit --testsuite Feature

php artisan thai-rails:preflight          # output must be unchanged
php artisan realtime:check --skip-network # output must be unchanged
php artisan schedule:list | grep -E 'thai|truemoney'
```
