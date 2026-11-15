<?php

declare(strict_types=1);

namespace App\Jobs\Rewards;

use App\Services\Cashback\CashbackAccrualService;
use App\Services\Rewards\FreeBetVoucherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

final class ExpireRewardArtifactsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 1800;

    public function uniqueId(): string
    {
        return 'expire-reward-artifacts:'.Carbon::now()->format('YmdH');
    }

    /**
     * @return array{expired_accruals: int, expired_vouchers: int}
     */
    public function handle(
        CashbackAccrualService $accruals,
        FreeBetVoucherService $vouchers,
    ): array {
        $now = Carbon::now();

        return [
            'expired_accruals' => $accruals->expireDueAccruals($now),
            'expired_vouchers' => $vouchers->expireDueVouchers($now),
        ];
    }
}
