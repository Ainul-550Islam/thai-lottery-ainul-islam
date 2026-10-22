<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Exceptions\Wallet\ConversionException;
use App\Models\User;
use App\Services\Payment\StripeRefundRecoveryService;
use Illuminate\Support\Facades\DB;

/** New conversion authority only; quotes, exact replay and reversal are separate contracts. */
final class StripeRefundConversionGuard
{
    public function assertAllowed(int $ownerId, Currency $currency): void
    {
        if (DB::transactionLevel() < 1 || ! DB::connection()->getPdo()->inTransaction()) {
            $this->refuse('conversion_refund_exposure_unproven');
        }
        // Reentrant conversion-owner mutex; never lock the original deposit wallet.
        $player = User::query()->whereKey($ownerId)->lockForUpdate()->first();
        if (! $player) {
            $this->refuse('conversion_refund_exposure_unproven');
        }
        try {
            $pending = app(StripeRefundRecoveryService::class)->hasRecoveryExposureInTransaction($player, $currency);
        } catch (FinancialException $error) {
            throw ConversionException::failure('conversion_refund_exposure_unproven',
                'New wallet conversions require review of the existing deposit-refund recovery evidence.',
                ['currency' => $currency->value], $error);
        }
        if ($pending) {
            $this->refuse('conversion_refund_exposure_pending');
        }
    }

    private function refuse(string $code): never
    {
        throw ConversionException::failure($code,
            'New wallet conversions require deposit-refund recovery review.');
    }
}
