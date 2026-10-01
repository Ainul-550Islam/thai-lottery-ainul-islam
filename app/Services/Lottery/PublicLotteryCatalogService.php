<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Read-only registry for the public lottery hub.
 *
 * The hub does not invent schedules, prices, jackpots, odds or stock. Product
 * cards contain only a route, a translated product label, an enabled flag and
 * the state returned by that product's own public result service. Each lane
 * continues to own its result selection and provenance rules.
 */
final class PublicLotteryCatalogService
{
    public function __construct(
        private readonly NationalLotteryResultService $national,
        private readonly WeeklyLotteryResultService $weekly,
        private readonly BingoLotteryResultService $bingo,
        private readonly PcsoLotteryResultService $pcso,
    ) {
    }

    /**
     * @return array{status: string, products: list<array<string, mixed>>}
     */
    public function products(): array
    {
        $definitions = [
            [
                'key' => 'national_lottery',
                'route' => 'national-lottery.index',
                'buy_route' => 'national-lottery.buy',
                'config' => 'national_lottery.enabled',
                'service' => $this->national,
            ],
            [
                'key' => 'weekly_lottery',
                'route' => 'weekly-lottery.index',
                'buy_route' => 'weekly-lottery.buy',
                'config' => 'weekly_lottery.enabled',
                'service' => $this->weekly,
            ],
            [
                'key' => 'bingo_lottery',
                'route' => 'bingo-lottery.index',
                'buy_route' => null,
                'config' => 'bingo_lottery.enabled',
                'service' => $this->bingo,
            ],
            [
                'key' => 'pcso_lottery',
                'route' => 'pcso-lottery.index',
                'buy_route' => null,
                'config' => 'pcso_lottery.enabled',
                'service' => $this->pcso,
            ],
        ];

        $products = [];

        foreach ($definitions as $definition) {
            if (! Route::has($definition['route']) || ! (bool) config($definition['config'], false)) {
                continue;
            }

            $result = null;
            $state = 'UNAVAILABLE';

            try {
                $result = $definition['service']->currentResult();
                $hasPublicResult = (bool) ($result['available'] ?? false)
                    && (bool) ($result['has_numbers'] ?? true);
                $state = $hasPublicResult
                    ? 'RESULT_AVAILABLE'
                    : strtoupper((string) ($result['status'] ?? 'UNAVAILABLE'));
            } catch (Throwable $exception) {
                report($exception);
            }

            $products[] = [
                'key' => $definition['key'],
                'title_key' => 'lottery_hub.products.'.$definition['key'].'.title',
                'description_key' => 'lottery_hub.products.'.$definition['key'].'.description',
                'route' => route($definition['route']),
                'buy_route' => is_string($definition['buy_route'])
                    && Route::has($definition['buy_route'])
                    ? route($definition['buy_route'])
                    : null,
                'enabled' => true,
                'data_state' => $state,
                'has_result' => is_array($result)
                    && (bool) ($result['available'] ?? false)
                    && (bool) ($result['has_numbers'] ?? true),
            ];
        }

        return [
            'status' => $products === [] ? 'UNAVAILABLE' : 'OK',
            'products' => $products,
        ];
    }
}
