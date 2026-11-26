<?php

declare(strict_types=1);

namespace App\Filament\Resources\MemberBankAccountResource\Pages;

use App\Enums\BankAccountStatus;
use App\Filament\Resources\MemberBankAccountResource;
use App\Exceptions\FinancialException;
use App\Models\MemberBankAccount;
use App\Models\User;
use App\Services\Account\MemberBankAccountService;
use App\Support\Admin\AdminAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

final class ViewMemberBankAccount extends ViewRecord
{
    protected static string $resource = MemberBankAccountResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('verify')
                ->label('Verify')
                ->color('success')
                ->requiresConfirmation()
                ->visible(function (): bool {
                    $operator = AdminAccess::operator();
                    /** @var MemberBankAccount $record */
                    $record = $this->getRecord();

                    return $operator instanceof User
                        && ! AdminAccess::isReadOnly($operator)
                        && AdminAccess::allowsAny($operator, [AdminAccess::MANAGE_USERS, AdminAccess::MANAGE_PAYOUTS])
                        && $record->status !== BankAccountStatus::Verified;
                })
                ->form([
                    Textarea::make('notes')
                        ->label('Verification Notes')
                        ->maxLength(500),
                ])
                ->action(function (array $data): void {
                    /** @var User $operator */
                    $operator = auth()->user();
                    /** @var MemberBankAccount $record */
                    $record = $this->getRecord();
                    $notes = isset($data['notes']) && is_string($data['notes']) && trim($data['notes']) !== ''
                        ? trim($data['notes'])
                        : null;

                    try {
                        app(MemberBankAccountService::class)->verify($record, $operator, $notes);
                    } catch (FinancialException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();

                        return;
                    }
                    $this->refreshFormData(['status', 'verification_notes', 'verified_at']);
                }),
            Action::make('reject')
                ->label('Reject')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(function (): bool {
                    $operator = AdminAccess::operator();
                    /** @var MemberBankAccount $record */
                    $record = $this->getRecord();

                    return $operator instanceof User
                        && ! AdminAccess::isReadOnly($operator)
                        && AdminAccess::allowsAny($operator, [AdminAccess::MANAGE_USERS, AdminAccess::MANAGE_PAYOUTS])
                        && $record->status !== BankAccountStatus::Rejected;
                })
                ->form([
                    Textarea::make('reason')
                        ->label('Rejection Reason')
                        ->required()
                        ->maxLength(500),
                ])
                ->action(function (array $data): void {
                    /** @var User $operator */
                    $operator = auth()->user();
                    /** @var MemberBankAccount $record */
                    $record = $this->getRecord();

                    try {
                        app(MemberBankAccountService::class)->reject($record, $operator, (string) $data['reason']);
                    } catch (FinancialException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();

                        return;
                    }
                    $this->refreshFormData(['status', 'verification_notes', 'verified_at', 'is_default']);
                }),
        ];
    }
}
