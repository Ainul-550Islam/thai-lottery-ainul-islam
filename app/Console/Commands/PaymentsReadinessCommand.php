<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Payment\PaymentReadinessService;
use Illuminate\Console\Command;

final class PaymentsReadinessCommand extends Command
{
    protected $signature = 'payments:readiness {--json : Emit machine-readable JSON} {--strict : Fail if a configured enabled gateway is not ready}';

    protected $description = 'Evaluate payment gateway configuration and market readiness without contacting providers.';

    public function handle(PaymentReadinessService $readiness): int
    {
        $results = $readiness->evaluateAll();
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

        $output = [
            'real_money_enabled' => $realMoneyEnabled,
            'ready' => ! $failed,
            'gateways' => array_values($results),
        ];
        if ($this->option('json')) {
            $this->line(json_encode($output, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            foreach ($results as $name => $result) {
                $this->line(__('payment_ops.readiness_gateway_status', [
                    'name' => $name,
                    'state' => trans($result->enabled ? 'payment_ops.readiness_enabled' : 'payment_ops.readiness_disabled'),
                    'status' => $result->status->value,
                    'issues' => $result->issues === [] ? '' : ' — '.implode(', ', $result->issues),
                ]));
            }
            $this->line(trans($failed ? 'payment_ops.readiness_failed_status' : 'payment_ops.readiness_passed_status'));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
