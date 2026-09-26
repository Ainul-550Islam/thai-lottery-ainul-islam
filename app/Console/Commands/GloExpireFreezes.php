<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Lottery\GloTicketFreezeService;
use App\Support\Admin\AdminAccess;
use Illuminate\Console\Command;

/**
 * Sweep due freezes Frozen → Expired (audited; history retained; never pays).
 */
class GloExpireFreezes extends Command
{
    protected $signature = 'glo:expire-freezes
        {--actor= : Operator email for audit attribution (recommended)}
        {--json : JSON output}';

    protected $description = 'Expire GLO freeze cases whose expiry_at has passed (GLO-11 audited transition)';

    public function handle(GloTicketFreezeService $service): int
    {
        $email = (string) $this->option('actor');
        $actor = null;

        if ($email !== '') {
            $actor = User::query()->where('email', $email)->first();

            if ($actor === null || ! $actor->isActive()) {
                $this->error('Actor not found or inactive.');

                return self::FAILURE;
            }

            if (! AdminAccess::allows($actor, AdminAccess::REVIEW_GLO_FREEZES)) {
                $this->error('Actor lacks the "review glo freezes" permission.');

                return self::FAILURE;
            }
        }

        try {
            $result = $service->expireDueFreezes($actor);
        } catch (\Throwable $e) {
            $this->error('Expiry sweep failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        } else {
            $this->info(sprintf(
                'Scanned due freezes, expired %d (scanned %d).',
                $result['expired'],
                $result['scanned'],
            ));
        }

        return self::SUCCESS;
    }
}
