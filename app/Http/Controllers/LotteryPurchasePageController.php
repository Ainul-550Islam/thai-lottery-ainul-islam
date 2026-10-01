<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Read-only entry pages for product purchase surfaces.
 *
 * National and Weekly result models are deliberately separate from the
 * operator Draw/bet lane. The repository currently has no canonical purchase
 * contract that maps either result lane to the wallet, reservation, ledger,
 * ticket and idempotency pipeline. These pages therefore fail closed: they do
 * not accept a number, price or wallet instruction until that product-specific
 * contract exists.
 */
final class LotteryPurchasePageController
{
    public function national(): View
    {
        return $this->page('national_lottery', 'national-lottery.buy');
    }

    public function weekly(): View
    {
        return $this->page('weekly_lottery', 'weekly-lottery.buy');
    }

    private function page(string $productKey, string $routeName): View
    {
        return view($productKey === 'national_lottery' ? 'national-lottery.buy' : 'weekly-lottery.buy', [
            'product_key' => $productKey,
            'product_title' => (string) trans('lottery_hub.products.'.$productKey.'.title'),
            'product_description' => (string) trans('lottery_hub.products.'.$productKey.'.description'),
            'purchase_state' => 'NOT_CONFIGURED',
            'back_url' => route($productKey === 'national_lottery'
                ? 'national-lottery.index'
                : 'weekly-lottery.index'),
            'canonical' => rtrim((string) config('app.url'), '/').route($routeName, [], false),
        ]);
    }
}
