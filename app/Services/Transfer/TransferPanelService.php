<?php

declare(strict_types=1);

namespace App\Services\Transfer;

use App\Enums\Currency;
use App\Enums\TransferDirection;
use App\Models\BalanceTransfer;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\TransferFeeCalculator;
use Illuminate\Support\Facades\Route;

/**
 * Builds the read-only player transfer panel data (pockets, lane rules, allowance, recent history).
 */
final class TransferPanelService
{
    public function __construct(
        private readonly BalanceTransferService $transfers,
        private readonly TransferFeeCalculator $feeCalculator,
        private readonly TransferLimitService $limits,
    ) {}

    /**
     * Build the transfer panel state for an authenticated player.
     *
     * @return array<string, mixed>
     */
    public function panelData(User $user): array
    {
        $configuredCurrency = (string) config('transfer.currency', 'THB');
        $currency = Currency::tryFrom(strtoupper(trim($configuredCurrency))) ?? Currency::primary();
        $masterEnabled = (bool) config('transfer.enabled', true);

        $pockets = $this->transfers->ensureTransferPockets($user, $currency);
        $cashWallet = $pockets['cash'];
        $winWallet = $pockets['win'];

        $directions = [];
        foreach (TransferDirection::cases() as $direction) {
            $laneConfig = (array) config('transfer.directions.'.$direction->value, []);
            $laneConfigEnabled = (bool) ($laneConfig['enabled'] ?? true);
            $rate = $this->feeCalculator->configuredRateOrNull($direction);
            $ratePercent = $this->feeCalculator->configuredRatePercentOrNull($direction);
            $laneEnabled = $masterEnabled && $laneConfigEnabled && $rate !== null;

            $minRaw = (string) ($laneConfig['min_amount'] ?? '10.00');
            $maxRaw = (string) ($laneConfig['max_amount'] ?? '50000.00');
            $minimum = preg_match('/^\d+(?:\.\d{1,2})?$/', trim($minRaw)) ? bcadd(trim($minRaw), '0', 2) : '10.00';
            $maximum = preg_match('/^\d+(?:\.\d{1,2})?$/', trim($maxRaw)) ? bcadd(trim($maxRaw), '0', 2) : '50000.00';

            $directions[$direction->value] = [
                'direction' => $direction->value,
                'label' => $direction->label(),
                'enabled' => $laneEnabled,
                'from' => $direction->pocketNames()['from'],
                'to' => $direction->pocketNames()['to'],
                'fee_category' => $direction->feeCategory(),
                'fee_rate' => $rate,
                'fee_rate_percent' => $ratePercent,
                'minimum' => $minimum,
                'maximum' => $maximum,
                'allowance' => $this->limits->remainingAllowance((int) $user->id, $direction),
            ];
        }

        $recent = $this->transfers
            ->recentForUser((int) $user->id, 10)
            ->map(static fn (BalanceTransfer $transfer): array => $transfer->toPlayerArray())
            ->values()
            ->all();

        return [
            'enabled' => $masterEnabled,
            'currency' => $currency->value,
            'pockets' => [
                'cash' => $this->pocketSnapshot($cashWallet),
                'win' => $this->pocketSnapshot($winWallet),
            ],
            'directions' => $directions,
            'recent' => $recent,
            'quote_url' => Route::has('player.transfer.quote') ? route('player.transfer.quote') : '/account/transfer/quote',
            'submit_url' => Route::has('player.transfer.store') ? route('player.transfer.store') : '/account/transfer',
            'history_url' => Route::has('player.transfer.index') ? route('player.transfer.index') : '/account/transfers',
            'panel_url' => Route::has('player.transfer.panel') ? route('player.transfer.panel') : '/account/transfer/panel',
        ];
    }

    /**
     * Alias for callers expecting buildForUser().
     *
     * @return array<string, mixed>
     */
    public function buildForUser(User $user): array
    {
        return $this->panelData($user);
    }

    /**
     * @return array{balance: string, locked: string, available: string, currency: string}
     */
    private function pocketSnapshot(Wallet $wallet): array
    {
        $currency = $wallet->currency instanceof Currency
            ? $wallet->currency->value
            : (string) $wallet->currency;

        return [
            'balance' => bcadd((string) $wallet->balance, '0', 2),
            'locked' => bcadd((string) $wallet->locked_balance, '0', 2),
            'available' => $wallet->getAvailableBalance(),
            'currency' => $currency,
        ];
    }
}
