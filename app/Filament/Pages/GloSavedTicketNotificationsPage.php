<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Draw;
use App\Models\GloNotificationDelivery;
use App\Models\GloSavedTicket;
use App\Support\Admin\AdminAccess;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * Filament page — GLO-17 saved-ticket result notification panel.
 *
 * Read-only operational view of glo_notification_deliveries: draw counts,
 * delivered/queued vs failed vs pending. Actions never re-fire settled rows;
 * retry of failed rows is the worker path (GloNotifySavedTickets), not a
 * button that could double-notify.
 */
class GloSavedTicketNotificationsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationGroup = 'Lottery';

    protected static ?string $navigationLabel = 'GLO Result Notifications';

    protected static ?string $title = 'GLO Saved-Ticket Result Notifications';

    protected static ?int $navigationSort = 9;

    protected static string $view = 'filament.pages.glo-saved-ticket-notifications-page';

    /** @var array<string, mixed> */
    public ?array $data = ['delivery_state' => 'all'];

    public static function canAccess(): bool
    {
        return AdminAccess::currentAny([
            AdminAccess::VIEW_AUDIT_LOGS,
            AdminAccess::REVIEW_GLO_DEALER_REQUESTS,
            AdminAccess::MANAGE_GLO_SALES_POINTS,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('delivery_state')
                    ->label('Delivery state filter')
                    ->options([
                        'all' => 'All',
                        'queued' => 'Queued / delivered in-app',
                        'failed' => 'Failed (retryable)',
                        'not_configured' => 'Provider not configured',
                    ])
                    ->default('all')
                    ->reactive()
                    ->afterStateUpdated(fn () => $this->dispatch('refresh')),
            ])
            ->statePath('data');
    }

    /**
     * Per-draw rollup: total, queued, failed, pending (saved tickets with no
     * delivery row yet for the latest attempt is approximated by active saves
     * without a delivery for that draw).
     *
     * @return list<array<string, mixed>>
     */
    public function getRows(): array
    {
        $state = (string) ($this->data['delivery_state'] ?? 'all');

        $deliveries = GloNotificationDelivery::query()
            ->orderByDesc('draw_id')
            ->limit(500)
            ->get();

        if ($state !== '' && $state !== 'all') {
            $deliveries = $deliveries->filter(
                static fn (GloNotificationDelivery $d): bool => $d->delivery_state === $state,
            );
        }

        /** @var array<int, array{total:int,queued:int,failed:int,not_configured:int}> $byDraw */
        $byDraw = [];
        foreach ($deliveries as $delivery) {
            $drawId = (int) $delivery->draw_id;
            if (! isset($byDraw[$drawId])) {
                $byDraw[$drawId] = [
                    'total' => 0,
                    'queued' => 0,
                    'failed' => 0,
                    'not_configured' => 0,
                ];
            }
            $byDraw[$drawId]['total']++;
            $bucket = $delivery->delivery_state;
            if ($bucket === 'queued' || $bucket === 'sent') {
                $byDraw[$drawId]['queued']++;
            } elseif ($bucket === 'failed') {
                $byDraw[$drawId]['failed']++;
            } elseif ($bucket === 'not_configured') {
                $byDraw[$drawId]['not_configured']++;
            }
        }

        $rows = [];
        foreach ($byDraw as $drawId => $counts) {
            $pending = (int) GloSavedTicket::query()
                ->where('draw_id', $drawId)
                ->where('status', 'active')
                ->where('notification_state', 'pending')
                ->count();

            $draw = Draw::query()->find($drawId);
            $rows[] = [
                'draw_id' => $drawId,
                'draw_number' => $draw?->draw_number ?? (string) $drawId,
                'notification_count' => $counts['total'],
                'delivered' => $counts['queued'],
                'failed' => $counts['failed'],
                'pending' => $pending,
                'provider_not_configured' => $counts['not_configured'],
            ];
        }

        return $rows;
    }
}
