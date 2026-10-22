<?php

declare(strict_types=1);

namespace App\Console\Commands\Security;

use App\Models\BlockedIp;
use App\Services\Network\IpBlocklistService;
use Illuminate\Console\Command;

class PruneExpiredIpBlocksCommand extends Command
{
    protected $signature = 'security:prune-expired-blocks';

    protected $description = 'Deactivates expired IP block rules and invalidates Redis cache.';

    public function handle(IpBlocklistService $blocklistService): int
    {
        $expiredCount = BlockedIp::where('is_active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update([
                'is_active' => false,
                'unblocked_at' => now(),
            ]);

        if ($expiredCount > 0) {
            $blocklistService->invalidateCache();
            $this->info("Successfully pruned {$expiredCount} expired IP rules.");
        } else {
            $this->info('No expired IP rules found.');
        }

        return self::SUCCESS;
    }
}
