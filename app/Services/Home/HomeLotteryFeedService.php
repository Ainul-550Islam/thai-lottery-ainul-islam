<?php

declare(strict_types=1);

namespace App\Services\Home;

use App\Services\Lottery\GloPublicHomeService;
use Illuminate\Support\Facades\Cache;

/**
 * Aggregates public lottery product offerings and availability for Home cards.
 */
class HomeLotteryFeedService
{
    public function __construct(
        private readonly GloPublicHomeService $gloHome,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getLotteryProducts(): array
    {
        try {
            return Cache::remember('home.lottery_feed.products', 60, function (): array {
                $rawProducts = $this->gloHome->productSummary();

                $categories = [
                    [
                        'id' => 'glo_national',
                        'name' => 'Thai National Lottery (GLO L6)',
                        'badge' => 'Government Official',
                        'schedule' => '1st & 16th Bi-Monthly',
                        'top_prize' => '฿6,000,000',
                        'route' => 'national-lottery.index',
                        'status' => 'OPEN',
                        'is_active' => true,
                    ],
                    [
                        'id' => 'lao_development',
                        'name' => 'Lao Development (หวยลาว)',
                        'badge' => 'Regional Dev',
                        'schedule' => 'Mon, Wed, Fri • 20:30 GMT+7',
                        'top_prize' => '฿850 / ฿1',
                        'route' => 'weekly-lottery.index',
                        'status' => 'OPEN',
                        'is_active' => true,
                    ],
                    [
                        'id' => 'hanoi_vip',
                        'name' => 'Hanoi VIP (ฮานอย VIP)',
                        'badge' => 'Daily Fast',
                        'schedule' => 'Daily • 19:30 GMT+7',
                        'top_prize' => '฿920 / ฿1',
                        'route' => 'weekly-lottery.index',
                        'status' => 'OPEN',
                        'is_active' => true,
                    ],
                    [
                        'id' => 'bingo_speed',
                        'name' => 'Bingo Speed (88 Daily Rounds)',
                        'badge' => '15-Min Instant',
                        'schedule' => 'Continuous 06:00 - 03:45',
                        'top_prize' => '50,000x',
                        'route' => 'bingo-lottery.index',
                        'status' => 'OPEN',
                        'is_active' => true,
                    ],
                    [
                        'id' => 'pcso_ultra',
                        'name' => 'PCSO Ultra Lotto 6/58',
                        'badge' => 'Grand Jackpot',
                        'schedule' => 'Tue, Fri, Sun • 21:00 GMT+8',
                        'top_prize' => '฿94,820,000+',
                        'route' => 'pcso-lottery.index',
                        'status' => 'OPEN',
                        'is_active' => true,
                    ],
                ];

                return [
                    'status' => 'VERIFIED',
                    'products' => $categories,
                    'count' => count($categories),
                    'summary' => $rawProducts,
                ];
            });
        } catch (\Throwable $e) {
            report($e);

            return [
                'status' => 'UNAVAILABLE',
                'products' => [],
                'count' => 0,
                'summary' => null,
            ];
        }
    }
}
