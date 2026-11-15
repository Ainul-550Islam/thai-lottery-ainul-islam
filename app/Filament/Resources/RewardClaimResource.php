<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\RewardClaimStatus;
use App\Exceptions\FinancialException;
use App\Filament\Resources\RewardClaimResource\Pages\ListRewardClaims;
use App\Models\RewardClaim;
use App\Models\User;
use App\Services\Rewards\RewardClaimReviewService;
use App\Support\Admin\AdminAccess;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin review queue for reward claims that need a human (VIP upgrade).
 * All state changes are delegated to RewardClaimReviewService (audited, atomic).
 */
final class RewardClaimResource extends Resource
{
    protected static ?string $model = RewardClaim::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'Rewards';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && AdminAccess::allowsAny($user, [
            AdminAccess::MANAGE_USERS,
            AdminAccess::MANAGE_PAYOUTS,
        ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $canReview = static function (RewardClaim $record): bool {
            $operator = AdminAccess::operator();

            return $operator instanceof User
                && ! AdminAccess::isReadOnly($operator)
                && AdminAccess::allowsAny($operator, [AdminAccess::MANAGE_USERS, AdminAccess::MANAGE_PAYOUTS])
                && $record->status === RewardClaimStatus::Pending;
        };

        return $table
            ->modifyQueryUsing(static fn ($query) => $query->with(['item', 'member']))
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('member.username')->label('Member')->searchable(),
                Tables\Columns\TextColumn::make('item.code')->label('Reward')->searchable(),
                Tables\Columns\TextColumn::make('points_spent')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->formatStateUsing(static fn ($state): string => $state instanceof RewardClaimStatus ? $state->label() : (string) $state)
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('completed_at')->dateTime()->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(
                    collect(RewardClaimStatus::cases())->mapWithKeys(
                        static fn (RewardClaimStatus $case): array => [$case->value => $case->label()],
                    )->all(),
                ),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible($canReview)
                    ->form([
                        Forms\Components\Textarea::make('notes')->label('Notes')->maxLength(500),
                    ])
                    ->action(static function (RewardClaim $record, array $data): void {
                        /** @var User $operator */
                        $operator = auth()->user();
                        $notes = isset($data['notes']) && is_string($data['notes']) && trim($data['notes']) !== ''
                            ? trim($data['notes'])
                            : null;

                        try {
                            app(RewardClaimReviewService::class)->approve($record, $operator, $notes);
                        } catch (FinancialException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Reject & refund points')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible($canReview)
                    ->form([
                        Forms\Components\Textarea::make('reason')->label('Reason')->required()->maxLength(500),
                    ])
                    ->action(static function (RewardClaim $record, array $data): void {
                        /** @var User $operator */
                        $operator = auth()->user();

                        try {
                            app(RewardClaimReviewService::class)->reject($record, $operator, (string) $data['reason']);
                        } catch (FinancialException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->bulkActions([]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListRewardClaims::route('/'),
        ];
    }
}
