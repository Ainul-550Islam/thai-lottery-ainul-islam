<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\BankAccountStatus;
use App\Filament\Resources\MemberBankAccountResource\Pages\ListMemberBankAccounts;
use App\Filament\Resources\MemberBankAccountResource\Pages\ViewMemberBankAccount;
use App\Exceptions\FinancialException;
use App\Models\MemberBankAccount;
use App\Models\User;
use App\Services\Account\MemberBankAccountService;
use App\Support\Admin\AdminAccess;
use Filament\Forms;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin verification resource for member bank accounts.
 * Displays masked account number (last4) ONLY — raw account_number is never rendered.
 */
final class MemberBankAccountResource extends Resource
{
    protected static ?string $model = MemberBankAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'Compliance & KYC';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && AdminAccess::allowsAny($user, [
            AdminAccess::MANAGE_USERS,
            AdminAccess::MANAGE_PAYOUTS,
            AdminAccess::VIEW_RISK_ALERTS,
        ]);
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny();
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

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('id'),
            TextEntry::make('member.username')->label('Member'),
            TextEntry::make('bank_code')->label('Bank Code'),
            TextEntry::make('account_name')->label('Account Holder Name'),
            TextEntry::make('masked_number')->label('Account Number (Masked)'),
            TextEntry::make('status')
                ->formatStateUsing(static fn ($state): string => $state instanceof BankAccountStatus ? $state->label() : (string) $state),
            IconEntry::make('name_mismatch')->boolean()->label('KYC Name Mismatch'),
            IconEntry::make('is_default')->boolean()->label('Default'),
            TextEntry::make('verification_notes')->placeholder('None'),
            TextEntry::make('verified_at')->dateTime()->placeholder('Unverified'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('member.username')->label('Member')->searchable(),
                Tables\Columns\TextColumn::make('bank_code')->sortable(),
                Tables\Columns\TextColumn::make('account_name')->searchable(),
                Tables\Columns\TextColumn::make('masked_number')->label('Masked Account'),
                Tables\Columns\TextColumn::make('status')
                    ->formatStateUsing(static fn ($state): string => $state instanceof BankAccountStatus ? $state->label() : (string) $state)
                    ->sortable(),
                Tables\Columns\IconColumn::make('name_mismatch')->boolean()->label('KYC Mismatch'),
                Tables\Columns\TextColumn::make('verified_at')->dateTime()->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('verify')
                    ->label('Verify')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(static function (MemberBankAccount $record): bool {
                        $operator = AdminAccess::operator();

                        return $operator instanceof User
                            && ! AdminAccess::isReadOnly($operator)
                            && AdminAccess::allowsAny($operator, [AdminAccess::MANAGE_USERS, AdminAccess::MANAGE_PAYOUTS])
                            && $record->status !== BankAccountStatus::Verified;
                    })
                    ->form([
                        Forms\Components\Textarea::make('notes')
                            ->label('Verification Notes')
                            ->maxLength(500),
                    ])
                    ->action(static function (MemberBankAccount $record, array $data): void {
                        /** @var User $operator */
                        $operator = auth()->user();
                        $notes = isset($data['notes']) && is_string($data['notes']) && trim($data['notes']) !== ''
                            ? trim($data['notes'])
                            : null;

                        try {
                            app(MemberBankAccountService::class)->verify($record, $operator, $notes);
                        } catch (FinancialException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(static function (MemberBankAccount $record): bool {
                        $operator = AdminAccess::operator();

                        return $operator instanceof User
                            && ! AdminAccess::isReadOnly($operator)
                            && AdminAccess::allowsAny($operator, [AdminAccess::MANAGE_USERS, AdminAccess::MANAGE_PAYOUTS])
                            && $record->status !== BankAccountStatus::Rejected;
                    })
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Rejection Reason')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(static function (MemberBankAccount $record, array $data): void {
                        /** @var User $operator */
                        $operator = auth()->user();
                        try {
                            app(MemberBankAccountService::class)->reject($record, $operator, (string) $data['reason']);
                        } catch (FinancialException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();
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
            'index' => ListMemberBankAccounts::route('/'),
            'view' => ViewMemberBankAccount::route('/{record}'),
        ];
    }
}
