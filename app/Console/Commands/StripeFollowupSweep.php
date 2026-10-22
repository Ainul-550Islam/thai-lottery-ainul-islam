<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\FinancialException;
use App\Models\User;
use App\Services\Payment\StripeFollowupSweepService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;

final class StripeFollowupSweep extends Command
{
    protected $signature = 'stripe-followups:sweep {--actor= : Explicit currently authorized staff ID; defaults to configured service actor} {--limit=25 : Maximum independently committed work items, 1–25}';

    protected $description = 'Lease and route a bounded signed Stripe inbox batch; no financial application or provider HTTP';

    public function handle(StripeFollowupSweepService $service): int
    {
        $actorId = $this->option('actor') ?? config('payment.deposit_followup_sweep.stripe.actor_id');
        $limit = $this->option('limit');
        if (! is_scalar($actorId) || ! is_string($limit) || preg_match('/^[1-9][0-9]{0,17}$/D', (string) $actorId) !== 1
            || preg_match('/^[1-9][0-9]?$/D', $limit) !== 1 || (int) $limit > StripeFollowupSweepService::LIMIT) {
            $this->error('A positive approved actor ID and a limit from1 through25 are required.');

            return self::INVALID;
        }
        try {
            $out = $service->sweep(User::query()->find((int) $actorId), (int) $limit);
            $this->line(json_encode($out, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (AuthorizationException) {
            $this->error('Current explicitly permissioned staff authority is required. Earlier independently committed items remain durable.');
        } catch (FinancialException $e) {
            $this->error(($e->errorCode() ?? 'stripe_sweep_unproven').': sweep refused; earlier independently committed items may remain durable.');
        }

        return self::FAILURE;
    }
}
