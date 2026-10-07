<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Enums\BetType;
use App\Enums\LimitStatus;
use App\Exceptions\RiskConfigurationException;
use App\Models\Draw;
use App\Models\NumberLimit;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

/**
 * Creates the complete per-number risk-capacity space for a draw.
 *
 * Provisioning is deterministic and idempotent. Re-running updates definition
 * fields only; accumulated stake, payout exposure, status and exceeded time are
 * never reset. The database unique key on draw, bet type and number is the final
 * duplicate guard.
 */
final class NumberLimitProvisioningService
{
    private const MONEY_PATTERN = '/^(?:0|[1-9]\d{0,17})(?:\.\d{1,2})?$/';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @return array{
     *   draw_id:int,
     *   draw_number:string,
     *   written:array<string,int>,
     *   total:int,
     *   stake_ceiling:string,
     *   payout_ceiling:string
     * }
     */
    public function provision(Draw $draw): array
    {
        if (! $draw->exists) {
            throw RiskConfigurationException::invalidLimit('The draw must be persisted before number limits are provisioned.');
        }

        $stakeCeiling = $this->moneyConfig('risk.exposure.max_stake_per_number');
        $payoutCeiling = $this->moneyConfig('risk.exposure.max_per_number');
        $now = Carbon::now();
        $written = [];

        $this->database->connection()->transaction(function () use (
            $draw,
            $stakeCeiling,
            $payoutCeiling,
            $now,
            &$written,
        ): void {
            Draw::query()->whereKey($draw->getKey())->lockForUpdate()->firstOrFail();

            foreach (BetType::cases() as $betType) {
                $rows = $this->rowsFor(
                    drawId: (int) $draw->getKey(),
                    betType: $betType,
                    stakeCeiling: $stakeCeiling,
                    payoutCeiling: $payoutCeiling,
                    now: $now,
                );

                foreach (array_chunk($rows, 75) as $chunk) {
                    NumberLimit::query()->upsert(
                        $chunk,
                        ['draw_id', 'bet_type', 'number'],
                        ['max_amount', 'maximum_payout_exposure', 'metadata', 'updated_at'],
                    );
                }

                $written[$betType->value] = count($rows);
            }
        }, 3);

        return [
            'draw_id' => (int) $draw->getKey(),
            'draw_number' => (string) $draw->draw_number,
            'written' => $written,
            'total' => array_sum($written),
            'stake_ceiling' => $stakeCeiling,
            'payout_ceiling' => $payoutCeiling,
        ];
    }

    public function isFullyProvisioned(Draw $draw): bool
    {
        if (! $draw->exists) {
            return false;
        }

        $counts = NumberLimit::query()
            ->where('draw_id', (int) $draw->getKey())
            ->selectRaw('bet_type, COUNT(*) AS aggregate_count')
            ->groupBy('bet_type')
            ->pluck('aggregate_count', 'bet_type');

        foreach (BetType::cases() as $betType) {
            if ((int) ($counts[$betType->value] ?? 0) !== 10 ** $betType->digits()) {
                return false;
            }
        }

        return $counts->count() === count(BetType::cases());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsFor(
        int $drawId,
        BetType $betType,
        string $stakeCeiling,
        string $payoutCeiling,
        Carbon $now,
    ): array {
        $width = $betType->digits();
        $size = 10 ** $width;
        $rows = [];

        for ($value = 0; $value < $size; $value++) {
            $rows[] = [
                'draw_id' => $drawId,
                'bet_type' => $betType->value,
                'number' => str_pad((string) $value, $width, '0', STR_PAD_LEFT),
                'max_amount' => $stakeCeiling,
                'current_amount' => '0.00',
                'maximum_payout_exposure' => $payoutCeiling,
                'current_payout_exposure' => '0.00',
                'status' => LimitStatus::Active->value,
                'metadata' => json_encode([
                    'provisioner' => self::class,
                    'schema' => 1,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    private function moneyConfig(string $key): string
    {
        $value = $this->config->get($key);

        if (! is_string($value) && ! is_int($value)) {
            throw RiskConfigurationException::missingKey($key);
        }

        $value = trim((string) $value);

        if (preg_match(self::MONEY_PATTERN, $value) !== 1 || bccomp($value, '0.00', 2) <= 0) {
            throw RiskConfigurationException::invalidKey(
                $key,
                'expected a positive decimal string with at most two fractional digits',
                $value,
            );
        }

        return bcadd($value, '0.00', 2);
    }
}
