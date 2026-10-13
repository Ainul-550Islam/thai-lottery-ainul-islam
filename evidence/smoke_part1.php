<?php

declare(strict_types=1);

/**
 * Part 1 smoke proof — enums, value objects, DTOs and exceptions.
 *
 * This script boots the Composer autoloader only: it proves that the Part 1
 * classes are syntactically and semantically complete on their own, without a
 * Laravel application, database or HTTP stack. Every assertion prints PASS or
 * FAIL and the script exits non-zero on the first failure count > 0.
 */

require __DIR__.'/../repo/vendor/autoload.php';

use App\DTOs\Wallet\ConversionHistoryFilterData;
use App\DTOs\Wallet\ConversionPolicyDecisionData;
use App\DTOs\Wallet\ConversionRequestData;
use App\Enums\ConversionFeeBearer;
use App\Enums\Currency;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionRejectionReason;
use App\Enums\WalletConversionStatus;
use App\Enums\WalletType;
use App\Exceptions\Wallet\ConversionAccountRestrictedException;
use App\Exceptions\Wallet\ConversionDisabledException;
use App\Exceptions\Wallet\ConversionIdempotencyConflictException;
use App\Exceptions\Wallet\ConversionInsufficientBalanceException;
use App\Exceptions\Wallet\ConversionKycRequiredException;
use App\Exceptions\Wallet\ConversionLimitExceededException;
use App\Exceptions\Wallet\ConversionQuoteExpiredException;
use App\Exceptions\Wallet\ConversionSameWalletException;
use App\ValueObjects\ConversionLimits;
use App\ValueObjects\ConversionRate;

$passed = 0;
$failed = 0;

function check(string $label, mixed $actual, mixed $expected): void
{
    global $passed, $failed;

    if ($actual === $expected) {
        $passed++;
        echo "PASS  {$label}\n";

        return;
    }

    $failed++;
    echo "FAIL  {$label}\n      expected: ".var_export($expected, true)."\n      actual:   ".var_export($actual, true)."\n";
}

function checkThrows(string $label, string $expectedClass, callable $call): void
{
    global $passed, $failed;

    try {
        $call();
    } catch (Throwable $e) {
        if ($e instanceof $expectedClass) {
            $passed++;
            echo "PASS  {$label} (threw ".basename(str_replace('\\', '/', $e::class)).")\n";

            return;
        }

        $failed++;
        echo "FAIL  {$label}: threw ".$e::class.' instead of '.$expectedClass."\n";

        return;
    }

    $failed++;
    echo "FAIL  {$label}: nothing was thrown\n";
}

echo "==============================================================\n";
echo " PART 1 SMOKE PROOF — Cash <-> Win conversion foundation\n";
echo "==============================================================\n\n";

echo "-- 1. WalletConversionDirection ----------------------------------\n";
check('CashToWin debits the primary (Cash) wallet', WalletConversionDirection::CashToWin->fromType(), WalletType::Primary);
check('CashToWin credits the win wallet', WalletConversionDirection::CashToWin->toType(), WalletType::Win);
check('WinToCash debits the win wallet', WalletConversionDirection::WinToCash->fromType(), WalletType::Win);
check('WinToCash credits the primary (Cash) wallet', WalletConversionDirection::WinToCash->toType(), WalletType::Primary);
check('reverse() flips CashToWin', WalletConversionDirection::CashToWin->reverse(), WalletConversionDirection::WinToCash);
check('reverse() flips WinToCash', WalletConversionDirection::WinToCash->reverse(), WalletConversionDirection::CashToWin);
check('pair (primary, win) resolves to CashToWin', WalletConversionDirection::tryFromWalletPair(WalletType::Primary, WalletType::Win), WalletConversionDirection::CashToWin);
check('pair (win, primary) resolves to WinToCash', WalletConversionDirection::tryFromWalletPair(WalletType::Win, WalletType::Primary), WalletConversionDirection::WinToCash);
check('pair (primary, bonus) is NOT a conversion', WalletConversionDirection::tryFromWalletPair(WalletType::Primary, WalletType::Bonus), null);
check('pair (bonus, commission) is NOT a conversion', WalletConversionDirection::tryFromWalletPair(WalletType::Bonus, WalletType::Commission), null);
check('fee rule key of CashToWin is the published key', WalletConversionDirection::CashToWin->feeRuleKey(), 'cash_to_win');
check('fee config path of WinToCash', WalletConversionDirection::WinToCash->feeConfigPath(), 'fees.categories.win_to_cash');
checkThrows('same-wallet pair throws ConversionSameWalletException', ConversionSameWalletException::class, function (): void {
    WalletConversionDirection::fromWalletPair(WalletType::Primary, WalletType::Primary);
});
checkThrows('unsupported pair throws ConversionException', App\Exceptions\Wallet\ConversionException::class, function (): void {
    WalletConversionDirection::fromWalletPair(WalletType::Primary, WalletType::Bonus);
});
check('direction options list has both directions', array_keys(WalletConversionDirection::options()), ['cash_to_win', 'win_to_cash']);

echo "\n-- 2. WalletType (patched) ---------------------------------------\n";
check('Win wallet cannot be withdrawn from directly', WalletType::Win->canWithdraw(), false);
check('Primary (Cash) wallet can be withdrawn from', WalletType::Primary->canWithdraw(), true);
check('Win wallet cannot receive deposits directly', WalletType::Win->canDeposit(), false);
check('Primary (Cash) wallet receives deposits', WalletType::Primary->canDeposit(), true);
check('Win wallet can fund bets', WalletType::Win->canBet(), true);
check('Bonus wallet can still fund bets (unchanged)', WalletType::Bonus->canBet(), true);
check('Commission wallet is convertible', WalletType::Commission->isConvertible(), false);
check('Win counterpart is Primary', WalletType::Win->conversionCounterpart(), WalletType::Primary);
check('Primary counterpart is Win', WalletType::Primary->conversionCounterpart(), WalletType::Win);
check('Bonus has no conversion counterpart', WalletType::Bonus->conversionCounterpart(), null);

echo "\n-- 3. ConversionRate (bcmath, exact) -----------------------------\n";
$rate = ConversionRate::fromString('0.0100');
check('01% rate renders as 1.00%', $rate->percentage(), '1.00');
check('01% rate is 100 basis points', $rate->basisPoints(), 100);
check('rate string is normalised', $rate->toString(), '0.0100');
check('fee on 1000.00 is exactly 10.00', $rate->feeOn('1000.00'), '10.00');
check('net of 1000.00 is exactly 990.00', $rate->netOf('1000.00'), '990.00');
check('fee on 1.00 is 0.01 (no float drift)', $rate->feeOn('1.00'), '0.01');
check('fee on 0.50 rounds half-up to 0.01', $rate->feeOn('0.50'), '0.01');
check('fee on 0.49 rounds half-up to 0.00', $rate->feeOn('0.49'), '0.00');
check('gross-up of 990.00 restores 1000.00', $rate->grossUp('990.00'), '1000.00');
check('fromBasisPoints(100) equals 1%', ConversionRate::fromBasisPoints(100)->feeOn('1000.00'), '10.00');
$oddRate = ConversionRate::fromString('0.0125');
check('1.25% on 10.02 rounds to 0.13', $oddRate->feeOn('10.02'), '0.13');
check('zero rate charges nothing', ConversionRate::zero()->feeOn('999.99'), '0.00');
check('same rate compares equal', ConversionRate::fromString('0.0100')->equals(ConversionRate::fromBasisPoints(100)), true);
check('different rates compare unequal', ConversionRate::fromString('0.0100')->equals(ConversionRate::fromString('0.0200')), false);

echo "\n-- 4. ConversionLimits -------------------------------------------\n";
$limits = ConversionLimits::fromConfig([
    'min_amount' => '10.00',
    'max_amount' => '500000.00',
    'daily_limit' => '1000000.00',
    'monthly_limit' => '5000000.00',
], Currency::THB);
check('minimum parsed', $limits->minimum(), '10.00');
check('maximum parsed', $limits->maximum(), '500000.00');
check('amount at the minimum is allowed', $limits->allows('10.00'), true);
check('amount one cent below the minimum is refused', $limits->allows('9.99'), false);
check('first violation is BelowMinimum', $limits->transactionViolations('9.99'), [WalletConversionRejectionReason::BelowMinimum]);
check('amount one cent above the maximum is refused', $limits->allows('500000.01'), false);
check('first violation is AboveMaximum', $limits->transactionViolations('500000.01'), [WalletConversionRejectionReason::AboveMaximum]);
check('daily head-room computes', $limits->remainingDaily('999000.00'), '1000.00');
check('daily window blocks the overrun', $limits->rollingViolations('10.00', '999999.95'), [WalletConversionRejectionReason::DailyLimitExceeded]);
check('daily window allows a fitting amount', $limits->rollingViolations('10.00', '999000.00'), []);
check('monthly window blocks the overrun', $limits->rollingViolations('10.00', '0.00', '4999999.95'), [WalletConversionRejectionReason::MonthlyLimitExceeded]);

echo "\n-- 5. WalletConversionStatus state machine -----------------------\n";
check('Quoted -> Pending is legal', WalletConversionStatus::Quoted->canTransitionTo(WalletConversionStatus::Pending), true);
check('Quoted -> Completed is ILLEGAL', WalletConversionStatus::Quoted->canTransitionTo(WalletConversionStatus::Completed), false);
check('Completed -> Reversed is legal', WalletConversionStatus::Completed->canTransitionTo(WalletConversionStatus::Reversed), true);
check('Completed can be reversed', WalletConversionStatus::Completed->canBeReversed(), true);
check('Failed can never be reversed', WalletConversionStatus::Failed->canBeReversed(), false);
check('Processing is in flight', WalletConversionStatus::Processing->isInFlight(), true);
check('Completed is not in flight', WalletConversionStatus::Completed->isInFlight(), false);
check('Failed is terminal', WalletConversionStatus::Failed->isTerminal(), true);
check('Completed is not terminal (reversible)', WalletConversionStatus::Completed->isTerminal(), false);
check('Completed is successful', WalletConversionStatus::Completed->isSuccessful(), true);
check('in-flight states', WalletConversionStatus::inFlightStates(), [WalletConversionStatus::Pending, WalletConversionStatus::Processing]);

echo "\n-- 6. Rejection reasons and HTTP mapping ------------------------\n";
check('insufficient balance is 422', WalletConversionRejectionReason::InsufficientBalance->httpStatus(), 422);
check('feature disabled is 503', WalletConversionRejectionReason::FeatureDisabled->httpStatus(), 503);
check('direction disabled is 503', WalletConversionRejectionReason::DirectionDisabled->httpStatus(), 503);
check('idempotency conflict is 409', WalletConversionRejectionReason::IdempotencyConflict->httpStatus(), 409);
check('quote not found is 404', WalletConversionRejectionReason::QuoteNotFound->httpStatus(), 404);
check('rate limited is 429', WalletConversionRejectionReason::RateLimited->httpStatus(), 429);
check('translation key shape', WalletConversionRejectionReason::KycNotVerified->messageKey(), 'wallet_conversion.rejection_kyc_not_verified');
check('member can fix a KYC refusal', WalletConversionRejectionReason::KycNotVerified->isUserCorrectable(), true);
check('member cannot fix a suspension themselves', WalletConversionRejectionReason::AccountSuspended->isUserCorrectable(), false);

echo "\n-- 7. Exceptions --------------------------------------------------\n";
$disabled = ConversionDisabledException::feature();
check('feature-disabled maps to 503', $disabled->httpStatus(), 503);
check('feature-disabled error code', $disabled->errorCode(), 'feature_disabled');
check('feature-disabled names the config switch', $disabled->configKey(), 'conversions.enabled');
$dirDisabled = ConversionDisabledException::forDirection(WalletConversionDirection::WinToCash);
check('direction-disabled names the direction switch', $dirDisabled->configKey(), 'conversions.directions.win_to_cash.enabled');
check('direction-disabled reason', $dirDisabled->rejectionReason(), WalletConversionRejectionReason::DirectionDisabled);

$insufficient = ConversionInsufficientBalanceException::forAmount(41, WalletType::Primary, WalletConversionDirection::CashToWin, '250.00', '100.00', Currency::THB, '300.00');
check('shortfall is exact', $insufficient->shortfallAmount(), '150.00');
check('opposite wallet would cover it', $insufficient->oppositeWalletWouldSuffice(), true);
check('insufficient balance maps to 422', $insufficient->httpStatus(), 422);

$min = ConversionLimitExceededException::forMinimum('10.00', '5.00', 'THB');
check('minimum breach maps to 422', $min->httpStatus(), 422);
check('minimum breach is not a rolling window', $min->isRollingWindow(), false);
$daily = ConversionLimitExceededException::forDailyWindow('1000000.00', '1000005.00', 'THB');
check('daily breach IS a rolling window', $daily->isRollingWindow(), true);
check('daily breach limit key', $daily->limitKey(), 'conversions.limits.daily_limit');
checkThrows('a non-limit reason is rejected by the limit exception', InvalidArgumentException::class, function (): void {
    ConversionLimitExceededException::forReason(WalletConversionRejectionReason::KycNotVerified, '0.00', '0.00', 'THB');
});

$kyc = ConversionKycRequiredException::forUser(7, 'pending', ['national_id']);
check('pending review is recognised', $kyc->isPendingReview(), true);
check('pending review is not a rejection', $kyc->wasRejected(), false);
check('KYC remedy route', $kyc->remedyRoute(), 'account.verification');
check('KYC refusal maps to 422', $kyc->httpStatus(), 422);
check('rejected documents change the copy', ConversionKycRequiredException::forUser(7, 'rejected')->wasRejected(), true);

$selfExcluded = ConversionAccountRestrictedException::forSelfExclusion(9, '2026-11-01T00:00:00+07:00');
check('self-exclusion is flagged as self-imposed', $selfExcluded->isSelfImposed(), true);
check('self-exclusion refusal code', $selfExcluded->errorCode(), 'self_exclusion_active');
check('suspension is not self-imposed', ConversionAccountRestrictedException::forAccountStatus(9, 'suspended')->isSelfImposed(), false);
checkThrows('a non-restriction reason is rejected', InvalidArgumentException::class, function (): void {
    new ConversionAccountRestrictedException(WalletConversionRejectionReason::KycNotVerified, 9, null);
});

$conflict = ConversionIdempotencyConflictException::forKey('key-0000000000001', 'WIN-CONV-42', ['amount' => '100.00'], ['amount' => '500.00'], ['amount']);
check('idempotency conflict maps to 409', $conflict->httpStatus(), 409);
check('idempotency conflict reports the mismatched field', $conflict->toApiArray()['mismatched_fields'], ['amount']);
check('idempotency conflict carries the earlier reference', $conflict->existingReference(), 'WIN-CONV-42');

$expired = ConversionQuoteExpiredException::forReference('QT-0001', new DateTimeImmutable('-1 minute'));
check('expired quote maps to 422', $expired->httpStatus(), 422);
check('expired quote resolution reason', $expired->rejectionReason(), WalletConversionRejectionReason::QuoteExpired);
check('rule supersession is distinguished from expiry', ConversionQuoteExpiredException::forSupersededRule('QT-1', new DateTimeImmutable('-1 minute'), '2', '3')->isRuleSupersession(), true);

$same = ConversionSameWalletException::forType(WalletType::Win);
check('same-wallet exception has no member-facing reason', $same->rejectionReason(), null);
check('same-wallet exception still maps to 422', $same->httpStatus(), 422);

echo "\n-- 8. DTOs --------------------------------------------------------\n";
$request = ConversionRequestData::fromArray([
    'direction' => 'cash_to_win',
    'amount' => '1000.00',
    'user_id' => 12,
    'currency' => 'THB',
    'channel' => 'web',
    'idempotency_key' => 'key-0000000000001',
]);
check('direction parsed from a string', $request->direction, WalletConversionDirection::CashToWin);
check('from wallet type is primary', $request->fromWalletType(), WalletType::Primary);
check('to wallet type is win', $request->toWalletType(), WalletType::Win);
check('idempotency key detected', $request->hasIdempotencyKey(), true);
check('no quote yet', $request->hasQuote(), false);
check('binding a quote keeps the amount', $request->withQuoteReference('QT-9')->amount, '1000.00');
check('request array carries both wallet sides', $request->toArray()['from_wallet_type'], 'primary');
check('request array carries the win side', $request->toArray()['to_wallet_type'], 'win');

$filter = ConversionHistoryFilterData::fromArray(['per_page' => 9999, 'page' => -5, 'status' => ['completed', 'nonsense']], 12);
check('page size is clamped to the ceiling', $filter->perPage, 100);
check('page number is clamped to 1', $filter->page, 1);
check('unknown statuses are dropped', $filter->statusValues(), ['completed']);

$decision = ConversionPolicyDecisionData::deny(WalletConversionRejectionReason::InsufficientBalance, ['balance' => false]);
check('denial is denied', $decision->isDenied(), true);
check('denial status is 422', $decision->httpStatus(), 422);
check('denial has a non-empty label', $decision->reasonLabel() !== '', true);
check('allow is allowed', ConversionPolicyDecisionData::allow(['balance' => true])->httpStatus(), 200);

echo "\n-- 9. Fee bearer enum --------------------------------------------\n";
check('default bearer reduces the credited amount', ConversionFeeBearer::Source->reducesCreditedAmount(), true);
check('platform bearer is platform-absorbed', ConversionFeeBearer::Platform->isPlatformAbsorbed(), true);
check('destination bearer debits destination', ConversionFeeBearer::Destination->debitsDestination(), true);
check('bearer translation key', ConversionFeeBearer::Source->labelKey(), 'wallet_conversion.fee_bearer_source');

echo "\n-- 10. Channel enum ----------------------------------------------\n";
check('web channel is user initiated', WalletConversionChannel::Web->isUserInitiated(), true);
check('api channel is user initiated', WalletConversionChannel::Api->isUserInitiated(), true);
check('admin channel is privileged', WalletConversionChannel::Admin->isPrivileged(), true);
check('console channel is privileged', WalletConversionChannel::Console->isPrivileged(), true);
check('member channels share one rate-limit bucket', WalletConversionChannel::Web->rateLimitBucket(), 'conversion:user');
check('admin bucket is separate', WalletConversionChannel::Admin->rateLimitBucket(), 'conversion:admin');

echo "\n==============================================================\n";
echo ' RESULT: '.$passed.' passed, '.$failed.' failed'."\n";
echo "==============================================================\n";

exit($failed === 0 ? 0 : 1);
