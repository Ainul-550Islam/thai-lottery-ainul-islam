<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Lottery\PublicLotteryCatalogService;
use Illuminate\Contracts\View\View;

final class LotteryHubController
{
    public function __construct(
        private readonly PublicLotteryCatalogService $hub,
    ) {
    }

    public function index(): View
    {
        $catalog = $this->hub->products();

        return view('lotteries.index', [
            'catalog' => $catalog,
            'meta' => [
                'title' => (string) trans('lottery_hub.meta_title'),
                'description' => (string) trans('lottery_hub.meta_description'),
                'canonical' => rtrim((string) config('app.url'), '/').'/lotteries',
                'robots' => 'index,follow',
            ],
        ]);
    }
}
