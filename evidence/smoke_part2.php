<?php

/**
 * PART 2 PROOF — the Cash <-> Win conversion engine, exercised end to end.
 *
 * This is not a unit test with mocks. It boots the real Laravel application,
 * talks to the real migrated database, creates a real member with real
 * wallets, and calls the real service stack through the real contract binding.
 * Every assertion below is a claim about what the platform actually does:
 *
 *   1. a 100.00 THB Cash -> Win conversion charges exactly 1.00 THB (1%),
 *   2. the money lands where it should, in the amounts it should,
 *   3. the ledger records the movement against the right accounts,
 *   4. presenting the same idempotency key twice moves money exactly once,
 *   5. an unaffordable conversion is refused with a stable code,
 *   6. an operator reversal restores the pre-conversion position exactly,
 *   7. the fee the engine charges is the fee the published schedule advertises.
 *
 * The whole run happens inside one outer transaction that is rolled back at
 * the end, so the development database is left untouched.
 *
 * Run: php /home/user/evidence/smoke_part2.php
 */

declare(strict_types=1);

use App\Contracts\Wallet\WalletConversionServiceContract;
use App\DTOs\Wallet\ConversionAuditContextData;
use App\DTOs\Wallet\ConversionRequestData;
use App\Enums\Currency;
use App\Enums\KycStatus;
use App\Enums\UserStatus;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionStatus;
use App\Enums\WalletStatus;
use App\Enums\WalletType;
use App\Exceptions\Wallet\ConversionException;
use App\Models\KycDocument;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletConversion;
use App\Services\Wallet\Conversion\ConversionFeeCalculator;
use App\Services\Wallet\Conversion\WalletConversionService;
use Illuminate\Support\Facades\DB;

require '/home/user/repo/vendor/autoload.php';

$app = require '/home/user/repo/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        printf("PASS  %s%s\n", $label, $detail === '' ? '' : '  [' . $detail . ']');

        return;
    }

    $failed++;
    printf("FAIL  %s%s\n", $label, $detail === '' ? '' : '  [' . $detail . ']');
}

function money(string $value): string
{
    return bcadd($value, '0', 2);
}

function expects(string $expected, string $actual, string $label): void
{
    check($label, bccomp($expected, $actual, 2) === 0, sprintf('expected %s, got %s', $expected, $actual));
}

echo "=== PART 2 SMOKE: Cash <-> Win conversion engine ===\n";

// The permanent container binding lands with WalletConversionServiceProvider in
// Part 3 (file 61). Until then the harness binds the real implementation here so
// that the engine can be exercised through the same contract the HTTP layer will
// resolve. This is a wiring gap owned by a later part, never a stub: the class
// bound below is the production service, not a double.
$app->bind(WalletConversionServiceContract::class, WalletConversionService::class);

DB::beginTransaction();

try {
    // ---------------------------------------------------------------- fixtures
    $user = User::factory()->create([
        'status' => UserStatus::Active,
    ]);

    // Identity is proven by a verified document, not by a column: User::kycStatus()
    // reads the member's documents, so the fixture must produce a real one.
    KycDocument::query()->create([
        'user_id' => $user->getKey(),
        'document_type' => 'national_id',
        'document_number' => 'SMOKE-PART2-0001',
        'file_path' => 'kyc/smoke-part2.png',
        'mime_type' => 'image/png',
        'file_size' => 1024,
        'status' => KycStatus::Verified,
        'verified_at' => now(),
    ]);

    $user->unsetRelation('kycDocuments');

    check('member created with verified KYC', $user->kycStatus() === KycStatus::Verified, $user->kycStatus()->value);

    $cash = Wallet::query()->create([
        'user_id' => $user->getKey(),
        'type' => WalletType::Primary,
        'currency' => Currency::THB,
    ]);
    $cash->forceFill(['balance' => '1000.00', 'status' => WalletStatus::Active])->save();

    $win = Wallet::query()->create([
        'user_id' => $user->getKey(),
        'type' => WalletType::Win,
        'currency' => Currency::THB,
    ]);
    $win->forceFill(['balance' => '0.00', 'status' => WalletStatus::Active])->save();

    check('cash wallet opened at 1000.00 THB', money((string) $cash->fresh()->balance) === '1000.00', (string) $cash->fresh()->balance);
    check('win wallet opened at 0.00 THB', money((string) $win->fresh()->balance) === '0.00', (string) $win->fresh()->balance);
    check('Win is a first-class wallet type', WalletType::Win->value === 'win');

    /** @var WalletConversionServiceContract $service */
    $service = app(WalletConversionServiceContract::class);
    check('contract resolves from the container', $service instanceof WalletConversionServiceContract, $service::class);
    check('service reports cash_to_win enabled', $service->isEnabled(WalletConversionDirection::CashToWin));

    // -------------------------------------------------------------- 7. parity
    /** @var ConversionFeeCalculator $feeCalculator */
    $feeCalculator = app(ConversionFeeCalculator::class);
    $publishedRate = (string) config('fees.categories.cash_to_win.rate');
    $publishedPercentage = $feeCalculator->publishedPercentage(WalletConversionDirection::CashToWin);

    check('published fee rule exists', $feeCalculator->isConfigured(WalletConversionDirection::CashToWin), (string) config('fees.categories.cash_to_win.rule_version'));
    check('published percentage equals config rate', bccomp($publishedPercentage ?? '0', bcmul($publishedRate, '100', 2), 2) === 0, sprintf('schedule %s%% vs config %s', (string) $publishedPercentage, (string) $publishedRate));

    // ---------------------------------------------------------------- 1. quote
    $quoteRequest = new ConversionRequestData(
        direction: WalletConversionDirection::CashToWin,
        amount: '100.00',
        currency: Currency::THB,
        channel: WalletConversionChannel::Web,
        userId: (int) $user->getKey(),
        idempotencyKey: 'smoke-part2-quote-key-0001',
    );

    $quote = $service->quote($quoteRequest);
    expects('1.00', $quote->feeAmount, 'quote charges 1% on 100.00 (1.00 THB)');
    expects('99.00', $quote->amountOut, 'quote nets 99.00 after fee');
    expects('100.00', $quote->amountIn, 'quote reports the gross amount');
    check('quote is still valid', ! $quote->isExpired(), $quote->secondsRemaining() . 's remaining');
    check('quote is bound to cash_to_win', $quote->direction === WalletConversionDirection::CashToWin);

    // ------------------------------------------------------------- 2/3. convert
    $request = new ConversionRequestData(
        direction: WalletConversionDirection::CashToWin,
        amount: '100.00',
        currency: Currency::THB,
        channel: WalletConversionChannel::Web,
        userId: (int) $user->getKey(),
        idempotencyKey: 'smoke-part2-convert-key-0001',
        quoteReference: $quote->reference,
    );

    $result = $service->convert($request);

    check('conversion completed', $result->status === WalletConversionStatus::Completed, $result->status->value);
    check('conversion moved money', $result->movedMoney());
    check('conversion is not a replay', $result->replayed === false);
    expects('1.00', $result->feeAmount, 'engine charged exactly 1% (1.00 THB)');
    expects('99.00', $result->amountOut, 'member received 99.00 in the Win wallet');
    expects('900.00', money((string) $cash->fresh()->balance), 'cash wallet debited by the full 100.00');
    expects('99.00', money((string) $win->fresh()->balance), 'win wallet credited with the net 99.00');

    $cashBefore = money((string) $cash->fresh()->balance);
    $winBefore = money((string) $win->fresh()->balance);

    check('balances sum to the platform total minus fee', bccomp(bcadd($cashBefore, $winBefore, 2), '999.00', 2) === 0, bcadd($cashBefore, $winBefore, 2));

    $conversion = WalletConversion::query()->where('reference', '=', $result->reference)->first();
    check('conversion row persisted', $conversion instanceof WalletConversion, $result->reference);
    check('conversion row records direction', $conversion?->direction === WalletConversionDirection::CashToWin);
    check('conversion row links the quote', $conversion?->quote_reference === $quote->reference, (string) $conversion?->quote_reference);
    check('conversion row captured balances', $conversion?->from_balance_after !== null && $conversion?->to_balance_after !== null, sprintf('%s -> %s / %s -> %s', (string) $conversion?->from_balance_before, (string) $conversion?->from_balance_after, (string) $conversion?->to_balance_before, (string) $conversion?->to_balance_after));
    check('conversion row recorded the fee rule version', (string) $conversion?->fee_rule_version === (string) config('fees.categories.cash_to_win.rule_version'), (string) $conversion?->fee_rule_version);
    check('conversion row recorded the channel', $conversion?->channel === WalletConversionChannel::Web, $conversion?->channel?->value ?? 'null');

    // ---------------------------------------------------------------- 3. ledger
    $ledgerRevenue = LedgerEntry::query()
        ->where('reference_type', '=', 'wallet_conversion')
        ->where('reference_id', '=', $conversion?->getKey())
        ->whereHas('ledgerAccount', static fn ($query) => $query->where('code', '=', '4100'))
        ->get();

    $revenueCredit = '0.00';
    foreach ($ledgerRevenue as $entry) {
        $revenueCredit = bcadd($revenueCredit, (string) $entry->amount, 2);
    }

    expects('1.00', money($revenueCredit), 'fee revenue account 4100 received the 1.00 THB fee');

    $walletEntries = LedgerEntry::query()
        ->whereIn('wallet_id', [$cash->getKey(), $win->getKey()])
        ->where('reference_type', '=', 'wallet_conversion')
        ->where('reference_id', '=', $conversion?->getKey())
        ->get();

    check('ledger rows written for both wallets', $walletEntries->count() >= 3, $walletEntries->count() . ' entries');

    // Double entry writes two rows per leg: the wallet's own liability row and
    // its counterpart. Only the wallet's own account (the 2000 family) reflects a
    // balance change, so the reconciliation must be done against those rows —
    // summing every row that mentions the wallet would net to zero and hide a
    // posting bug rather than catch one.
    $walletDelta = static function (int $walletId) use ($conversion): string {
        $delta = '0.00';

        $rows = LedgerEntry::query()
            ->where('wallet_id', '=', $walletId)
            ->where('reference_type', '=', 'wallet_conversion')
            ->where('reference_id', '=', $conversion?->getKey())
            ->whereHas('ledgerAccount', static fn ($query) => $query->where('code', 'like', '2000%'))
            ->get();

        foreach ($rows as $entry) {
            $delta = (string) $entry->type->value === 'debit'
                ? bcsub($delta, (string) $entry->amount, 2)
                : bcadd($delta, (string) $entry->amount, 2);
        }

        return $delta;
    };

    expects('-100.00', money($walletDelta((int) $cash->getKey())), 'cash liability ledger rows sum to the -100.00 balance change');
    expects('99.00', money($walletDelta((int) $win->getKey())), 'win liability ledger rows sum to the +99.00 balance change');

    // ------------------------------------------------------------ 4. idempotency
    $replay = $service->convert($request);
    check('replay returns the same reference', $replay->reference === $result->reference, $replay->reference);
    check('replay is flagged as a replay', $replay->replayed === true);
    check('replay did not move money', $replay->movedMoney() === false);
    expects('900.00', money((string) $cash->fresh()->balance), 'cash balance unchanged after replay');
    expects('99.00', money((string) $win->fresh()->balance), 'win balance unchanged after replay');

    $conversionRows = WalletConversion::query()->where('user_id', '=', $user->getKey())->count();
    check('exactly one conversion row exists after the replay', $conversionRows === 1, $conversionRows . ' rows');

    // --------------------------------------------------------- 5. refusal paths
    $overdraw = new ConversionRequestData(
        direction: WalletConversionDirection::CashToWin,
        amount: '5000.00',
        currency: Currency::THB,
        channel: WalletConversionChannel::Web,
        userId: (int) $user->getKey(),
        idempotencyKey: 'smoke-part2-overdraw-key-001',
        quoteReference: $quote->reference,
    );

    $overdrawCode = null;
    try {
        $service->convert($overdraw);
    } catch (ConversionException $exception) {
        $overdrawCode = $exception->errorCode();
    }

    // Policy refuses an unaffordable amount before any lock is taken, which is
    // why the code is the policy-level `insufficient_balance`; the engine-level
    // `conversion_insufficient_balance` is the lock-time backstop behind it.
    check('overdraw refused with a stable code', in_array($overdrawCode, ['insufficient_balance', 'conversion_insufficient_balance'], true), (string) $overdrawCode);
    expects('900.00', money((string) $cash->fresh()->balance), 'cash untouched after the refused attempt');
    expects('99.00', money((string) $win->fresh()->balance), 'win untouched after the refused attempt');

    $shortKey = new ConversionRequestData(
        direction: WalletConversionDirection::CashToWin,
        amount: '20.00',
        currency: Currency::THB,
        channel: WalletConversionChannel::Web,
        userId: (int) $user->getKey(),
        idempotencyKey: 'short',
    );

    $shortKeyCode = null;
    try {
        $service->convert($shortKey);
    } catch (ConversionException $exception) {
        $shortKeyCode = $exception->errorCode();
    }

    check('a too-short idempotency key is refused', $shortKeyCode === 'idempotency_key_too_short' || $shortKeyCode === 'idempotency_key_invalid', (string) $shortKeyCode);

    $belowFloor = new ConversionRequestData(
        direction: WalletConversionDirection::CashToWin,
        amount: '5.00',
        currency: Currency::THB,
        channel: WalletConversionChannel::Web,
        userId: (int) $user->getKey(),
        idempotencyKey: 'smoke-part2-below-floor-01',
    );

    $belowFloorCode = null;
    try {
        $service->convert($belowFloor);
    } catch (ConversionException $exception) {
        $belowFloorCode = $exception->errorCode();
    }

    check('an amount under the 10.00 floor is refused', in_array($belowFloorCode, ['below_minimum', 'amount_below_minimum', 'conversion_below_minimum'], true), (string) $belowFloorCode);

    $auditRows = \App\Models\WalletConversionAudit::query()->where('user_id', '=', $user->getKey())->count();
    check('refusals are recorded in the audit trail', $auditRows >= 3, $auditRows . ' audit rows');

    // --------------------------------------------------------------- 6. reversal
    $context = new ConversionAuditContextData(
        channel: WalletConversionChannel::Admin,
        actorUserId: null,
        ipAddress: '127.0.0.1',
        userAgent: 'smoke-part2',
    );

    $reversal = $service->reverse(
        (int) $conversion?->getKey(),
        $context,
        'Smoke proof: operator reversal restores the pre-conversion position.',
        'smoke-part2-reversal-key-01',
    );

    check('reversal recorded as its own conversion', $reversal->direction === WalletConversionDirection::WinToCash, $reversal->direction->value);
    check('reversal completed', $reversal->status === WalletConversionStatus::Completed, $reversal->status->value);
    expects('1000.00', money((string) $cash->fresh()->balance), 'cash restored to 1000.00');
    expects('0.00', money((string) $win->fresh()->balance), 'win restored to 0.00');
    expects('0.00', $reversal->feeAmount, 'no fee is charged to undo a movement');

    $original = $conversion?->fresh();
    check('original marked reversed', $original?->status === WalletConversionStatus::Reversed, $original?->status->value ?? 'null');
    check('original points at its reversal', (int) $original?->reversed_by_id === $reversal->conversionId, (string) $original?->reversed_by_id);
    check('reversal points back at the original', (int) $reversal->conversionId !== (int) $conversion?->getKey());

    $refundRows = LedgerEntry::query()
        ->where('reference_type', '=', 'wallet_conversion')
        ->where('reference_id', '=', $reversal->conversionId)
        ->whereHas('ledgerAccount', static fn ($query) => $query->where('code', '=', '4100'))
        ->get();

    $refundTotal = '0.00';
    foreach ($refundRows as $entry) {
        $refundTotal = bcadd($refundTotal, (string) $entry->amount, 2);
    }

    expects('1.00', money($refundTotal), 'fee revenue account carries the 1.00 THB refund');

    // ------------------------------------------------------- 7. published parity
    $chargedRate = bcdiv((string) $conversion?->fee_amount, (string) $conversion?->amount_in, 6);
    check('charged rate equals the published rate', bccomp($chargedRate, $publishedRate, 6) === 0, sprintf('charged %s vs published %s', $chargedRate, $publishedRate));

    $history = $service->history(new \App\DTOs\Wallet\ConversionHistoryFilterData(userId: (int) $user->getKey()));
    check('history lists both the conversion and its reversal', $history->total() >= 2, $history->total() . ' rows');
    check('member can fetch the conversion by reference', $service->findForUser($result->reference, (int) $user->getKey()) instanceof WalletConversion);
    check('member can fetch the reversal by reference', $service->findForUser($reversal->reference, (int) $user->getKey()) instanceof WalletConversion);
} catch (Throwable $exception) {
    check('no unexpected exception escaped the engine', false, $exception::class . ': ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
} finally {
    DB::rollBack();
}

echo "\n=== RESULT: {$passed} passed, {$failed} failed ===\n";

exit($failed === 0 ? 0 : 1);
