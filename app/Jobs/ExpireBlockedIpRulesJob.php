<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BlockedIp;
use App\Services\Network\IpBlocklistService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExpireBlockedIpRulesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(IpBlocklistService $blocklistService): void
    {
        $expiredCount = BlockedIp::where('is_active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update([
                'is_active' => false,
                'unblocked_at' => now(),
                'notes' => 'Auto-expired by scheduled cleanup.',
            ]);

        if ($expiredCount > 0) {
            $blocklistService->invalidateCache();
            Log::info("Expired {$expiredCount} stale IP block rules.");
        }
    }
}
