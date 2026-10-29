<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Operations\ProviderReadinessService;
use App\Support\Ops\ThaiRailsChecks;
use Illuminate\Console\Command;

/**
 * `php artisan payments:readiness` — a thin alias.
 *
 * WHAT IT IS
 * A single console entry point over the two readiness owners that already
 * exist: ProviderReadinessService (per-gateway configuration) and
 * ThaiRailsChecks (the Thai rail lane checks, including the BDT-market guard,
 * crypto invisibility, empty base URLs and blank SCB paths). It owns no rule of
 * its own. Anything it could decide differently from `thai-rails:preflight`,
 * `ops:preflight` or the boot refusal would be a second opinion nobody asked
 * for, which is exactly what this command replaces.
 *
 * THE PUBLISHED CONTRACT
 * `--json` keeps emitting `real_money_enabled`, `ready` and `gateways`, so a
 * deploy script parsing this output keeps working; `thai_rails` is added
 * alongside them.
 */
final class PaymentsReadinessCommand extends Command
{
    protected $signature = 'payments:readiness {--json : Emit machine-readable JSON} {--strict : Fail if a configured enabled gateway is not ready}';

    protected $description = 'Evaluate payment gateway configuration and Thai rail readiness without contacting providers.';

    public function handle(ProviderReadinessService $readiness, ThaiRailsChecks $thaiRails): int
    {
        $results = $readiness->gateways();
        $enabledFailures = [];
        foreach ($results as $gateway => $result) {
            if ($result->enabled && ! $result->ready) {
                $enabledFailures[$gateway] = $result->issues;
            }
        }

        $realMoneyEnabled = (bool) config('real_money.enabled', false);
        $readyEnabled = array_filter($results, static fn ($result): bool => $result->enabled && $result->ready);
        $failed = $enabledFailures !== [] || ($realMoneyEnabled && $readyEnabled === []);
        if ($realMoneyEnabled && $readyEnabled === []) {
            $enabledFailures['no_ready_enabled_gateway'] = ['no_enabled_payment_gateway'];
        }
        if ($this->option('strict')) {
            $failed = $failed || count(array_filter($results, static fn ($result): bool => $result->enabled && ! $result->ready)) > 0;
        }

        // The Thai rail checks are the same object `thai-rails:preflight` runs,
        // so a FAIL here is the same FAIL an operator sees there.
        $thaiRailResults = $thaiRails->run();
        $thaiRailFailures = [];
        foreach ($thaiRailResults as $check) {
            if ($check->isFailure()) {
                $thaiRailFailures[$check->name] = $check->message;
            }
        }
        $failed = $failed || $thaiRailFailures !== [];

        if ($this->option('json')) {
            $this->line(json_encode([
                'real_money_enabled' => $realMoneyEnabled,
                'ready' => ! $failed,
                'gateways' => array_values($results),
                'thai_rails' => array_map(static fn ($check): array => $check->toArray(), $thaiRailResults),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            foreach ($results as $name => $result) {
                $this->line(__('payment_ops.readiness_gateway_status', [
                    'name' => $name,
                    'state' => trans($result->enabled ? 'payment_ops.readiness_enabled' : 'payment_ops.readiness_disabled'),
                    'status' => $result->status->value,
                    'issues' => $result->issues === [] ? '' : ' — '.implode(', ', $result->issues),
                ]));
            }
            foreach ($thaiRailResults as $check) {
                $this->line($check->line());
            }
            $this->line(trans($failed ? 'payment_ops.readiness_failed_status' : 'payment_ops.readiness_passed_status'));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
