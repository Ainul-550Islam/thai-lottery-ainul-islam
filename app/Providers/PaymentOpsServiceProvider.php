<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\PaymentsManualDisbursementSweepCommand;
use App\Console\Commands\PaymentsReadinessCommand;
use App\Exceptions\FinancialException;
use App\Services\Payment\PaymentReadinessService;
use App\Support\Admin\AdminAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class PaymentOpsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('payment_ops.php'), 'payment_ops');
        $this->app->singleton(PaymentReadinessService::class);
    }

    public function boot(): void
    {
        Gate::define('manage-payment-disbursements', static fn ($user): bool => AdminAccess::canAccessPanel($user) && AdminAccess::allows($user, AdminAccess::MANAGE_PAYOUTS)
        );

        $this->loadRoutesFrom(base_path('routes/payment_ops.php'));
        if ($this->app->runningInConsole()) {
            $this->commands([PaymentsManualDisbursementSweepCommand::class, PaymentsReadinessCommand::class]);
        }

        if ($this->app->environment('production') && (bool) config('real_money.enabled')
            && (bool) config('real_money.require_readiness_pass', true)
            && (bool) config('payment_ops.readiness.fail_boot_when_real_money_unready', true)) {
            $results = app(PaymentReadinessService::class)->evaluateAll();
            $failures = [];
            foreach ($results as $name => $result) {
                if ($result->enabled && ! $result->ready) {
                    $failures[$name] = $result->issues;
                }
            }
            $readyEnabled = array_filter($results, static fn ($result): bool => $result->enabled && $result->ready);
            if ($readyEnabled === []) {
                $failures['no_ready_enabled_gateway'] = ['no_enabled_payment_gateway'];
            }
            if ($failures !== []) {
                throw FinancialException::withCode('real_money_payment_readiness_failed', trans('payment_ops.errors.real_money_payment_readiness_failed'), ['gateways' => $failures]);
            }
        }

    }
}
