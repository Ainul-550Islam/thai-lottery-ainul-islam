<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Exceptions\GloDealerException;
use App\Models\GloSalesPoint;
use App\Services\Lottery\GloSalesPointService;
use App\Support\Admin\AdminAccess;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Filament page — GLO-16 sales-point management / verification.
 * Public projection stays privacy-safe; this admin view shows operational fields.
 */
class GloSalesPointsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = 'Lottery';

    protected static ?string $navigationLabel = 'GLO Sales Points';

    protected static ?string $title = 'GLO Sales Points';

    protected static ?int $navigationSort = 8;

    protected static string $view = 'filament.pages.glo-sales-points-page';

    /** @var array<string, mixed> */
    public ?array $data = ['verification_state' => 'all'];

    public static function canAccess(): bool
    {
        return AdminAccess::currentAny([
            AdminAccess::MANAGE_GLO_SALES_POINTS,
            AdminAccess::VIEW_AUDIT_LOGS,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('verification_state')
                    ->label('Verification filter')
                    ->options([
                        'all' => 'All',
                        'unverified' => 'Unverified',
                        'verified' => 'Verified',
                        'rejected' => 'Rejected',
                    ])
                    ->default('all')
                    ->reactive()
                    ->afterStateUpdated(fn () => $this->dispatch('refresh')),
            ])
            ->statePath('data');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getPoints(): array
    {
        $query = GloSalesPoint::query()->orderByDesc('id')->limit(200);
        $state = (string) ($this->data['verification_state'] ?? 'all');

        if ($state !== '' && $state !== 'all') {
            $query->where('verification_state', $state);
        }

        return $query->get()->map(static fn (GloSalesPoint $p): array => [
            'sales_point_code' => $p->sales_point_code,
            'dealer_id' => $p->dealer_id,
            'display_name' => $p->display_name,
            'province' => $p->province,
            'district' => $p->district,
            'latitude' => $p->latitude,
            'longitude' => $p->longitude,
            'verification_state' => $p->verification_state,
            'status' => $p->status,
            'source' => $p->source,
            'source_state' => $p->source_state,
        ])->all();
    }

    public function verify(string $code): void
    {
        $user = auth()->user();

        if (! AdminAccess::allows($user, AdminAccess::MANAGE_GLO_SALES_POINTS)
            && ! $user?->hasRole('super-admin')) {
            Notification::make()->title('Forbidden')->danger()->send();

            return;
        }

        $point = GloSalesPoint::query()->where('sales_point_code', $code)->first();

        if ($point === null) {
            Notification::make()->title('Sales point not found')->danger()->send();

            return;
        }

        try {
            app(GloSalesPointService::class)->verify($point, $user, 'verified');
            Notification::make()->title('Sales point verified')->success()->send();
        } catch (GloDealerException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
