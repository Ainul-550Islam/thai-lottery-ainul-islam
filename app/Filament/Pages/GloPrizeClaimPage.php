<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\GloClaimStatus;
use App\Models\GloPrizeClaim;
use App\Services\Lottery\GloPrizeClaimService;
use App\Support\Admin\AdminAccess;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Filament page — GLO prize claims (GLO-12/14).
 *
 * Shows claim money as exact strings, freeze/hold banners, age-verification
 * result and status timeline. Actions (review/approve/pay/reject) call
 * GloPrizeClaimService only; there is no direct "mark paid" shortcut.
 * Pay action is gated on EXECUTE_GLO_PRIZE_PAYMENTS and will fail closed if
 * any freeze/hold/age/settlement condition is unmet.
 */
class GloPrizeClaimPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationGroup = 'Lottery';

    protected static ?string $navigationLabel = 'GLO Prize Claims';

    protected static ?string $title = 'GLO Prize Claims';

    protected static ?int $navigationSort = 7;

    protected static string $view = 'filament.pages.glo-prize-claim-page';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return AdminAccess::currentAny([
            AdminAccess::MANAGE_GLO_PRIZE_CLAIMS,
            AdminAccess::EXECUTE_GLO_PRIZE_PAYMENTS,
            AdminAccess::VIEW_AUDIT_LOGS,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('status')
                    ->label('Status filter')
                    ->options(array_column(GloClaimStatus::cases(), 'label', 'value'))
                    ->reactive(),
                TextInput::make('search')
                    ->label('Claim / ticket reference'),
            ])
            ->statePath('data');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getClaims(): array
    {
        $query = GloPrizeClaim::query()->orderByDesc('id')->limit(200);

        $status = (string) ($this->data['status'] ?? '');

        if ($status !== '') {
            $query->where('status', $status);
        }

        $search = trim((string) ($this->data['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('claim_reference', 'like', '%'.$search.'%')
                    ->orWhere('ticket_number', 'like', '%'.$search.'%');
            });
        }

        $canReview = AdminAccess::current(AdminAccess::MANAGE_GLO_PRIZE_CLAIMS);
        $canPay = AdminAccess::current(AdminAccess::EXECUTE_GLO_PRIZE_PAYMENTS);

        return $query->get()
            ->map(fn (GloPrizeClaim $claim): array => [
                'id' => $claim->getKey(),
                'claim_reference' => $claim->claim_reference,
                'product' => $claim->product,
                'prize_category' => $claim->prize_category,
                'ticket_number' => $claim->ticket_number,
                'gross_prize' => (string) $claim->gross_prize,
                'stamp_duty' => (string) $claim->stamp_duty,
                'net_prize' => (string) $claim->net_prize,
                'status' => $claim->status->value,
                'status_label' => $claim->status->label(),
                'color' => $claim->status->color(),
                'payment_status' => $claim->payment_status,
                'hold_status' => $claim->hold_status,
                'hold_reason' => $claim->hold_reason,
                'age_verification_result' => $claim->age_verification_result,
                'verified_age_years' => $claim->verified_age_years,
                'claim_channel' => $claim->claim_channel->value,
                'submitted_at' => $claim->submitted_at?->toDateTimeString(),
                'paid_at' => $claim->paid_at?->toDateTimeString(),
                'can_review' => $canReview && in_array($claim->status, [GloClaimStatus::Pending], true),
                'can_approve' => $canReview && in_array($claim->status, [GloClaimStatus::Pending, GloClaimStatus::Eligible], true),
                'can_pay' => $canPay && $claim->status === GloClaimStatus::Approved,
                'can_reject' => $canReview && ! $claim->status->isTerminal(),
            ])
            ->toArray();
    }

    public function act(string $claimReference, string $action, ?string $reason = null): void
    {
        $actor = AdminAccess::operator();
        $claim = GloPrizeClaim::query()->where('claim_reference', $claimReference)->first();

        if ($claim === null) {
            Notification::make()->title('Claim not found')->danger()->send();

            return;
        }

        $service = app(GloPrizeClaimService::class);

        try {
            match ($action) {
                'review' => $service->reviewEligible($this->authorize($actor, AdminAccess::MANAGE_GLO_PRIZE_CLAIMS), $actor),
                'approve' => $service->approve($this->assertGloClaimPermission($actor, $claim, AdminAccess::MANAGE_GLO_PRIZE_CLAIMS), $actor),
                'reject' => $service->reject(
                    $this->assertGloClaimPermission($actor, $claim, AdminAccess::MANAGE_GLO_PRIZE_CLAIMS),
                    $actor,
                    $reason ?? 'Rejected from panel',
                ),
                'pay' => $service->pay(
                    $this->assertGloClaimPermission($actor, $claim, AdminAccess::EXECUTE_GLO_PRIZE_PAYMENTS),
                    $actor,
                ),
                default => throw new \InvalidArgumentException('Unknown action'),
            };
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Action refused')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Claim updated')
            ->body($claimReference.' → '.strtoupper($action))
            ->success()
            ->send();
    }

    private function assertGloPermission(?\App\Models\User $actor, string $permission): \App\Models\User
    {
        if ($actor === null || ! AdminAccess::allows($actor, $permission)) {
            throw new \RuntimeException('Missing required GLO permission.');
        }

        return $actor;
    }

    private function assertGloClaimPermission(?\App\Models\User $actor, GloPrizeClaim $claim, string $permission): GloPrizeClaim
    {
        $this->assertGloPermission($actor, $permission);

        return $claim;
    }
}
