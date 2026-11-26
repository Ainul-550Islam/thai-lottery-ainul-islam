<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\PaymentProviderStatus;
use App\Models\PaymentProvider;
use App\Services\Payment\PaymentGatewayManager;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminPermission;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The three things that break silently.
 *
 * WHY THESE THREE
 *
 * A panel that shows only money is a panel that discovers its first failed job when a
 * player calls. This widget surfaces the failures that produce no error page:
 *
 *   - FAILED JOBS — work that was attempted and refused. One is noise; a rising count is
 *     a deploy that broke a queue worker.
 *   - QUEUE BACKLOG — work accepted and not yet done. A backlog that only grows means
 *     nothing is draining it, which is invisible from the dashboard otherwise.
 *   - PROVIDER READINESS — the gap between "the house marked this provider Active" and
 *     "the driver can actually authenticate". Payments fail into that gap.
 *
 * It reads the framework's own job tables rather than inventing a health table, and it
 * never reports a cheerful "healthy" it has not measured: an unreadable store is reported
 * as unknown, not as zero.
 */
class SystemHealthWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return AdminAccess::permitsAny([
            AdminPermission::AUDIT_LOGS_VIEW,
            AdminPermission::SETTINGS_VIEW,
            AdminPermission::PROVIDERS_VIEW,
        ]);
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $stats = [];

        if (AdminAccess::permitsAny([AdminPermission::AUDIT_LOGS_VIEW, AdminPermission::SETTINGS_VIEW])) {
            $failed = $this->safeCount('failed_jobs');
            $backlog = $this->safeCount('jobs');

            $stats[] = Stat::make('Failed jobs', $failed === null ? 'unknown' : (string) $failed)
                ->description($failed === null
                    ? 'The job store could not be read'
                    : ($failed > 0 ? 'Work was attempted and refused — read the log' : 'No failed jobs'))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color(match (true) {
                    $failed === null => 'gray',
                    $failed > 0 => 'danger',
                    default => 'success',
                });

            $stats[] = Stat::make('Queue backlog', $backlog === null ? 'unknown' : (string) $backlog)
                ->description($backlog === null
                    ? 'The job store could not be read'
                    : ($backlog > 100 ? 'Nothing is draining the queue fast enough' : 'Waiting to be worked'))
                ->descriptionIcon('heroicon-m-queue-list')
                ->color(match (true) {
                    $backlog === null => 'gray',
                    $backlog > 100 => 'warning',
                    default => 'success',
                });
        }

        if (AdminAccess::permitsAny([AdminPermission::PROVIDERS_VIEW, AdminPermission::SETTINGS_VIEW])) {
            $active = PaymentProvider::query()->where('status', PaymentProviderStatus::Active)->count();
            $total = PaymentProvider::query()->count();
            $unreadyActive = $this->activeButUnready();

            $stats[] = Stat::make('Providers serving', sprintf('%d / %d', $active, $total))
                ->description($unreadyActive > 0
                    ? sprintf('%d "Active" provider(s) whose driver is not ready', $unreadyActive)
                    : 'Every Active provider is ready')
                ->descriptionIcon('heroicon-m-credit-card')
                ->color($unreadyActive > 0 ? 'danger' : ($active > 0 ? 'success' : 'warning'));
        }

        return $stats;
    }

    /**
     * Count rows without turning a missing table into a 500 on the dashboard.
     *
     * The table is asked for by name because the panel must keep working when a
     * deployment has not run the queue migration yet — a dashboard that dies because it
     * asked a question about a table that does not exist is worse than a dashboard that
     * says "unknown".
     */
    private function safeCount(string $table): ?int
    {
        try {
            if (! Schema::hasTable($table)) {
                return null;
            }

            return (int) DB::table($table)->count();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Active providers whose driver the gateway manager cannot invoke.
     *
     * "Cannot invoke" is read from PaymentGatewayManager::availability(); a provider whose
     * driver name is not in that inventory counts as not ready, because nothing will
     * resolve through it however Active the row says it is.
     */
    private function activeButUnready(): int
    {
        try {
            $availability = app(PaymentGatewayManager::class)->availability();
        } catch (Throwable) {
            return 0;
        }

        return PaymentProvider::query()
            ->where('status', PaymentProviderStatus::Active)
            ->get()
            ->filter(function (PaymentProvider $provider) use ($availability): bool {
                $driver = (string) $provider->driver;

                return ! isset($availability[$driver]) || ! $availability[$driver]['enabled'];
            })
            ->count();
    }
}
