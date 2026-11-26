<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Enums\WalletType;
use App\Models\Wallet;
use App\Services\Fx\FxDisplayService;
use Illuminate\Support\Carbon;

/**
 * The four balances, a trend, and an optional display FX figure. ALL READ-ONLY.
 *
 * WHERE THE NUMBERS COME FROM
 *   main      wallets row of type primary (or main, its legacy name)
 *   win       wallets row of type win
 *   bonus     wallets row of type bonus
 *   cashback  the Batch-1 CashbackBalanceReader — NOT a wallet type, and NOT a
 *             second balance calculated here
 *
 * Nothing in this class computes a balance. A balance is a stored fact on the
 * wallets table, written by WalletService under a row lock. If this service ever
 * derived a balance it would be able to disagree with the ledger, and the whole
 * money lane is built to make that impossible.
 *
 * THE TREND
 *   0 snapshots at/before the horizon -> null  (unknown, not zero)
 *   baseline balance == 0             -> null  (percentage change is undefined)
 *   otherwise                         -> (current - baseline) / baseline * 100,
 *                                        computed in bcmath at 4dp and rounded to
 *                                        2dp for display
 * A null renders as "no history yet". It never renders as "+0.0%", because that
 * would assert a flat week the system cannot prove.
 *
 * THE FX FIGURE
 * DISPLAY ONLY, and null unless the operator switched it on AND a rate exists
 * AND it is fresh. No code in the money path reads this key; conversion keeps
 * using the audited WalletConversion quote service.
 */
final class WalletOverviewService
{
    /**
     * The Batch-1 cashback reader is OPTIONAL by design.
     *
     * Phase 0 of this batch found no CashbackBalanceReader in the tree (see the
     * gap table). Resolving it through the container with a nullable dependency
     * means: when Batch 1 is present the figure comes from it, and when it is
     * absent the key is null with a stated reason. What must NOT happen is a
     * cashback number computed here from a wallet total, which is why the class
     * name is the only thing this service will accept.
     */
    public function __construct(
        private readonly WalletBalanceSnapshotService $snapshots,
        private readonly FxDisplayService $fx,
        private readonly ?object $cashbackReader = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(int $memberId, ?Carbon $at = null): array
    {
        $now = $at ?? Carbon::now();

        $wallets = Wallet::query()->where('user_id', $memberId)->get()->keyBy(
            static fn (Wallet $w): string => $w->type instanceof WalletType
                ? $w->type->value
                : (string) $w->type
        );

        $main = $this->walletOf($wallets, [WalletType::Primary, WalletType::Main]);
        $win = $this->walletOf($wallets, [WalletType::Win]);
        $bonus = $this->walletOf($wallets, [WalletType::Bonus]);

        $mainBalance = $this->exact($main?->balance);
        $trendDays = max(1, (int) config('player_experience.wallet.trend_days', 7));
        $horizon = $now->copy()->subDays($trendDays);

        $baseline = $main instanceof Wallet
            ? $this->snapshots->baselineFor($memberId, WalletType::Primary, $horizon)
            : null;

        [$trendPercent, $trendAvailable] = $this->trend($mainBalance, $baseline?->exactBalance());

        return [
            'currency' => $main?->currency?->value ?? 'THB',
            'balances' => [
                'main' => $mainBalance,
                'win' => $this->exact($win?->balance),
                'bonus' => $this->exact($bonus?->balance),
                'cashback' => $this->cashback($memberId),
            ],
            'cashback_available' => $this->cashbackAvailable(),
            'trend' => [
                'days' => $trendDays,
                'percent' => $trendPercent,
                'available' => $trendAvailable,
                'baseline' => $baseline?->exactBalance(),
                'baseline_date' => $baseline?->snapshot_date?->toDateString(),
            ],
            'fx' => $this->fx->enabled()
                ? $this->fx->display($mainBalance, null, null)
                : null,
            'fx_enabled' => $this->fx->enabled(),
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /**
     * The trend arithmetic, isolated so the null cases are impossible to miss.
     *
     * @return array{0: string|null, 1: bool}
     */
    private function trend(string $current, ?string $baseline): array
    {
        if ($baseline === null) {
            return [null, false];
        }

        if (bccomp($baseline, '0', 2) === 0) {
            return [null, false];
        }

        $delta = bcsub($current, $baseline, 2);
        $percent = bcdiv(bcmul($delta, '100', 4), $baseline, 4);

        // Two decimals of percent, rounded half-up at the third place, as strings.
        $rounded = bcadd($percent, (bccomp($percent, '0', 4) >= 0 ? '0.005' : '-0.005'), 3);
        $display = bcadd($rounded, '0.00', 2);

        return [$display, true];
    }

    private function exact(mixed $balance): string
    {
        if ($balance === null) {
            return '0.00';
        }

        return bcadd((string) $balance, '0.00', 2);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Wallet>  $wallets
     * @param  list<WalletType>  $types
     */
    private function walletOf(\Illuminate\Support\Collection $wallets, array $types): ?Wallet
    {
        foreach ($types as $type) {
            $wallet = $wallets->get($type->value);

            if ($wallet instanceof Wallet) {
                return $wallet;
            }
        }

        return null;
    }

    /**
     * The cashback figure, from the Batch-1 reader ONLY.
     *
     * A missing reader means the key is null — never a number assembled from
     * wallet totals, which would be a second, unaudited cashback implementation.
     */
    private function cashback(int $memberId): ?string
    {
        if (! $this->cashbackAvailable()) {
            return null;
        }

        $reader = $this->cashbackReader;

        if (! method_exists($reader, 'balanceFor')) {
            return null;
        }

        try {
            $value = $reader->balanceFor($memberId);
        } catch (\Throwable) {
            return null;
        }

        if ($value === null) {
            return null;
        }

        return bcadd((string) $value, '0.00', 2);
    }

    public function cashbackAvailable(): bool
    {
        return $this->cashbackReader !== null && method_exists($this->cashbackReader, 'balanceFor');
    }
}
