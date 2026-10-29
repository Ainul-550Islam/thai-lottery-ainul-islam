<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\PaymentsManualDisbursementSweepCommand;
use App\Console\Commands\PaymentsReadinessCommand;
use App\Exceptions\FinancialException;
use App\Support\Admin\AdminAccess;
use App\Support\Ops\ProductionPreflight;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class PaymentOpsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('payment_ops.php'), 'payment_ops');
    }

    public function boot(): void
    {
        Gate::define('manage-payment-disbursements', static fn ($user): bool => AdminAccess::canAccessPanel($user) && AdminAccess::allows($user, AdminAccess::MANAGE_PAYOUTS)
        );

        $this->loadRoutesFrom(base_path('routes/payment_ops.php'));
        if ($this->app->runningInConsole()) {
            $this->commands([PaymentsManualDisbursementSweepCommand::class, PaymentsReadinessCommand::class]);
        }

        /*
         * BOOT REFUSAL.
         *
         * The refusal asks ProductionPreflight, not a private evaluator: the
         * release gate an operator runs by hand and the gate the application
         * enforces on itself must be the same set of questions, or a deploy can
         * pass preflight and still refuse to boot (or the reverse).
         *
         * Connectivity probes are skipped here. Booting happens before the
         * process is serving, and a probe that blocks the boot it is supposed to
         * protect is a self-inflicted outage; the connectivity checks remain in
         * the standalone `ops:preflight` run.
         *
         * Only BLOCKING failures refuse the boot. Advisory findings — a dark
         * Thai rail, an unreachable probe — are reported by the command and
         * never stop the application from starting.
         */
        if ($this->app->environment('production') && (bool) config('real_money.enabled')
            && (bool) config('real_money.require_readiness_pass', true)
            && (bool) config('payment_ops.readiness.fail_boot_when_real_money_unready', true)) {
            $report = (new ProductionPreflight(production: true, withConnectivity: false))->run();

            $blocking = [];
            foreach ((array) ($report['checks'] ?? []) as $check) {
                if (($check['status'] ?? null) === 'FAIL' && ($check['severity'] ?? 'blocking') === 'blocking') {
                    $blocking[(string) ($check['id'] ?? 'unknown')] = (string) ($check['detail'] ?? '');
                }
            }

            if ($blocking !== []) {
                throw FinancialException::withCode(
                    'real_money_payment_readiness_failed',
                    trans('payment_ops.errors.real_money_payment_readiness_failed'),
                    ['checks' => $blocking],
                );
            }
        }
    }
}
