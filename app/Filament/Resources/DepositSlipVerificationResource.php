<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\AuditAction;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Enums\RiskLevel;
use App\Enums\SlipVerificationStatus;
use App\Exceptions\FinancialException;
use App\Filament\Resources\DepositSlipVerificationResource\Pages;
use App\Models\Deposit;
use App\Models\DepositSlipVerification;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Payment\PaymentLogSanitizer;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/** Read-focused bank-slip queue; provider payloads and unmasked data are never rendered. */
final class DepositSlipVerificationResource extends Resource
{
    protected static ?string $model = DepositSlipVerification::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 12;

    protected static ?string $recordTitleAttribute = 'id';

    public static function canViewAny(): bool
    {
        $operator = AdminAccess::operator();

        return AdminAccess::canAccessPanel($operator) && AdminAccess::allowsAny($operator, [
            AdminAccess::VIEW_TRANSACTION_HISTORY,
            AdminAccess::MANAGE_PAYOUTS,
        ]);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof DepositSlipVerification && Gate::allows('view', $record);
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

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('Verification')->sortable(),
                Tables\Columns\TextColumn::make('user.username')->label('Player')->searchable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (SlipVerificationStatus $state): string => str($state->value)->replace('_', ' ')->title()->toString())
                    ->color(fn (SlipVerificationStatus $state): string => match ($state) {
                        SlipVerificationStatus::Credited => 'success',
                        SlipVerificationStatus::NeedsReview, SlipVerificationStatus::Pending, SlipVerificationStatus::Verified => 'warning',
                        SlipVerificationStatus::Duplicate, SlipVerificationStatus::Rejected, SlipVerificationStatus::Expired => 'danger',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->formatStateUsing(fn ($state, DepositSlipVerification $record): string => AdminFormat::money($state, $record->currency))
                    ->alignEnd()->sortable(),
                Tables\Columns\TextColumn::make('sending_bank')->label('Sending bank')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('receiving_account_masked')->label('Receiving account')->placeholder('—'),
                Tables\Columns\TextColumn::make('slip_datetime')->label('Transfer time')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('review_reason')->label('Reason')->placeholder('—')->limit(40),
                Tables\Columns\TextColumn::make('created_at')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(collect(SlipVerificationStatus::cases())
                    ->mapWithKeys(fn (SlipVerificationStatus $status): array => [$status->value => str($status->value)->replace('_', ' ')->title()->toString()])->all()),
                Tables\Filters\SelectFilter::make('sending_bank')->options(fn (): array => DepositSlipVerification::query()
                    ->whereNotNull('sending_bank')->distinct()->orderBy('sending_bank')->pluck('sending_bank', 'sending_bank')->all()),
                Tables\Filters\SelectFilter::make('user_id')->relationship('user', 'username')->searchable(),
                Tables\Filters\Filter::make('amount')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('minimum')->numeric()->minValue(0)->maxLength(24),
                        \Filament\Forms\Components\TextInput::make('maximum')->numeric()->minValue(0)->maxLength(24),
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        if (isset($data['minimum']) && is_numeric($data['minimum'])) {
                            $query->where('amount', '>=', (string) $data['minimum']);
                        }
                        if (isset($data['maximum']) && is_numeric($data['maximum'])) {
                            $query->where('amount', '<=', (string) $data['maximum']);
                        }

                        return $query;
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\ActionGroup::make([
                    self::approveAction(),
                    self::rejectAction(),
                ])->label('Review')->visible(fn (DepositSlipVerification $record): bool => $record->status === SlipVerificationStatus::NeedsReview
                    && AdminAccess::current(AdminAccess::MANAGE_PAYOUTS)),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No slip verifications');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            \Filament\Infolists\Components\Section::make('Slip verification')
                ->schema([
                    \Filament\Infolists\Components\TextEntry::make('id')->label('Verification'),
                    \Filament\Infolists\Components\TextEntry::make('user.username')->label('Player'),
                    \Filament\Infolists\Components\TextEntry::make('status')->badge(),
                    \Filament\Infolists\Components\TextEntry::make('amount')
                        ->formatStateUsing(fn ($state, DepositSlipVerification $record): string => AdminFormat::money($state, $record->currency)),
                    \Filament\Infolists\Components\TextEntry::make('currency'),
                    \Filament\Infolists\Components\TextEntry::make('sending_bank')->placeholder('—'),
                    \Filament\Infolists\Components\TextEntry::make('receiving_account_masked')->label('Masked receiving account')->placeholder('—'),
                    \Filament\Infolists\Components\TextEntry::make('slip_datetime')->dateTime(),
                    \Filament\Infolists\Components\TextEntry::make('review_reason')->label('Reason code / reviewer note')->placeholder('—')->columnSpanFull(),
                    \Filament\Infolists\Components\TextEntry::make('deposit.reference_number')->label('Deposit reference')->placeholder('—'),
                    \Filament\Infolists\Components\TextEntry::make('reviewer.username')->label('Reviewed by')->placeholder('—'),
                    \Filament\Infolists\Components\TextEntry::make('reviewed_at')->dateTime()->placeholder('—'),
                ])->columns(3),
        ]);
    }

    /** @return array<string, \Filament\Resources\Pages\PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDepositSlipVerifications::route('/'),
            'view' => Pages\ViewDepositSlipVerification::route('/{record}'),
        ];
    }

    public static function approveAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('approve')
            ->label('Approve & credit')
            ->color('success')
            ->icon('heroicon-o-check-circle')
            ->visible(fn (DepositSlipVerification $record): bool => $record->status === SlipVerificationStatus::NeedsReview
                && AdminAccess::current(AdminAccess::MANAGE_PAYOUTS))
            ->form([
                \Filament\Forms\Components\Textarea::make('note')->required()->maxLength(120)
                    ->label('Approval note (required)'),
            ])
            ->requiresConfirmation()
            ->action(function (DepositSlipVerification $record, array $data): void {
                self::approveAndCredit($record, (string) $data['note'], (int) (AdminAccess::operator()?->getKey() ?? 0));
            });
    }

    public static function rejectAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('reject')
            ->label('Reject')
            ->color('danger')
            ->icon('heroicon-o-x-circle')
            ->visible(fn (DepositSlipVerification $record): bool => $record->status === SlipVerificationStatus::NeedsReview
                && AdminAccess::current(AdminAccess::MANAGE_PAYOUTS))
            ->form([
                \Filament\Forms\Components\Textarea::make('reason')->required()->minLength(3)->maxLength(120)
                    ->label('Rejection reason (required)'),
            ])
            ->requiresConfirmation()
            ->action(function (DepositSlipVerification $record, array $data): void {
                self::rejectReview($record, (string) $data['reason'], (int) (AdminAccess::operator()?->getKey() ?? 0));
            });
    }

    public static function approvePageAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('approve')
            ->label('Approve & credit')
            ->color('success')
            ->icon('heroicon-o-check-circle')
            ->visible(fn (DepositSlipVerification $record): bool => $record->status === SlipVerificationStatus::NeedsReview
                && AdminAccess::current(AdminAccess::MANAGE_PAYOUTS))
            ->form([
                \Filament\Forms\Components\Textarea::make('note')->required()->maxLength(120)
                    ->label('Approval note (required)'),
            ])
            ->requiresConfirmation()
            ->action(function (DepositSlipVerification $record, array $data): void {
                self::approveAndCredit($record, (string) $data['note'], (int) (AdminAccess::operator()?->getKey() ?? 0));
            });
    }

    public static function rejectPageAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('reject')
            ->label('Reject')
            ->color('danger')
            ->icon('heroicon-o-x-circle')
            ->visible(fn (DepositSlipVerification $record): bool => $record->status === SlipVerificationStatus::NeedsReview
                && AdminAccess::current(AdminAccess::MANAGE_PAYOUTS))
            ->form([
                \Filament\Forms\Components\Textarea::make('reason')->required()->minLength(3)->maxLength(120)
                    ->label('Rejection reason (required)'),
            ])
            ->requiresConfirmation()
            ->action(function (DepositSlipVerification $record, array $data): void {
                self::rejectReview($record, (string) $data['reason'], (int) (AdminAccess::operator()?->getKey() ?? 0));
            });
    }

    public static function approveAndCredit(DepositSlipVerification $verification, string $note, int $reviewerId): DepositSlipVerification
    {
        if ($reviewerId < 1 || trim($note) === '' || strlen($note) > 120) {
            throw FinancialException::withCode('slip_review_note_required', 'A reviewer and a required approval note are needed.');
        }
        $operator = AdminAccess::operator();
        if (! AdminAccess::canAccessPanel($operator) || ! AdminAccess::allows($operator, AdminAccess::MANAGE_PAYOUTS)) {
            throw new AuthorizationException;
        }
        $verification = DepositSlipVerification::query()->findOrFail($verification->getKey());
        if ($verification->status === SlipVerificationStatus::Credited) {
            $operator = AdminAccess::operator();
            if (! AdminAccess::current(AdminAccess::MANAGE_PAYOUTS) || ! AdminAccess::canAccessPanel($operator)) {
                Gate::authorize('approve', $verification);
            }
            if (! ($operator instanceof User) || (int) $operator->getKey() !== $reviewerId) {
                throw FinancialException::withCode('slip_review_actor_mismatch', 'The reviewer must be the authenticated finance operator.');
            }
            $creditedDeposit = $verification->deposit()->first();
            if (! $creditedDeposit instanceof Deposit || ! is_string($verification->trans_ref) || $verification->trans_ref === '') {
                throw FinancialException::withCode('slip_credit_proof_missing', 'The credited slip has no verifiable deposit proof.');
            }
            app(DepositCompletionService::class)->complete($creditedDeposit, options: [
                'provider' => PaymentMethod::BankTransfer->value,
                'provider_reference' => $verification->trans_ref,
                'metadata' => ['payment_rail' => 'bank_slip_verification', 'verification_id' => (int) $verification->getKey()],
            ]);

            return $verification->fresh() ?? $verification;
        }
        if ($verification->status !== SlipVerificationStatus::NeedsReview) {
            $operator = AdminAccess::operator();
            if (! AdminAccess::current(AdminAccess::MANAGE_PAYOUTS) || ! AdminAccess::canAccessPanel($operator)) {
                Gate::authorize('approve', $verification);
            }
            throw FinancialException::withCode('slip_review_state_conflict', 'This slip is no longer eligible for approval.');
        }
        Gate::authorize('approve', $verification);
        $operator = AdminAccess::operator();
        if (! ($operator instanceof User) || (int) $operator->getKey() !== $reviewerId) {
            throw FinancialException::withCode('slip_review_actor_mismatch', 'The reviewer must be the authenticated finance operator.');
        }

        try {
            return DB::transaction(function () use ($verification, $note, $reviewerId): DepositSlipVerification {
                $current = DepositSlipVerification::query()->lockForUpdate()->findOrFail($verification->getKey());
                if ($current->status === SlipVerificationStatus::Credited) {
                    return $current;
                }
                if ($current->status !== SlipVerificationStatus::NeedsReview
                    || ! is_string($current->trans_ref) || $current->trans_ref === ''
                    || strtoupper((string) $current->currency) !== Currency::THB->value) {
                    throw FinancialException::withCode('slip_review_state_conflict', 'This slip is no longer eligible for approval.');
                }
                if (! self::hasCreditableProviderEvidence($current)) {
                    throw FinancialException::withCode('slip_credit_proof_missing', 'The stored slip evidence does not prove every required verification check.');
                }
                $deposit = null;
                if ($current->deposit_id !== null) {
                    $deposit = Deposit::query()->find($current->deposit_id);
                }
                if (! $deposit instanceof Deposit) {
                    $candidates = Deposit::query()
                        ->where('user_id', $current->user_id)
                        ->where('method', PaymentMethod::BankTransfer->value)
                        ->where('status', DepositStatus::Pending->value)
                        ->where('currency', Currency::THB->value)
                        ->where('amount', $current->amount)
                        ->limit(2)
                        ->get();
                    if ($candidates->count() !== 1) {
                        throw FinancialException::withCode('slip_matching_deposit_required', 'Exactly one matching pending bank-transfer deposit is required.');
                    }
                    $deposit = $candidates->first();
                }
                if (! $deposit instanceof Deposit || (int) $deposit->user_id !== (int) $current->user_id
                    || $deposit->method !== PaymentMethod::BankTransfer || $deposit->status !== DepositStatus::Pending
                    || $deposit->currency !== Currency::THB || bccomp((string) $deposit->amount, (string) $current->amount, 2) !== 0
                    || ($deposit->provider_reference !== null && $deposit->provider_reference !== $current->trans_ref)) {
                    throw FinancialException::withCode('slip_matching_deposit_required', 'A matching pending bank-transfer deposit is required.');
                }
                $deposit = app(DepositApprovalService::class)->approve($deposit, $reviewerId, $note)['deposit'];
                app(DepositCompletionService::class)->complete($deposit, options: [
                    'provider' => PaymentMethod::BankTransfer->value,
                    'provider_reference' => $current->trans_ref,
                    'description' => 'Bank slip deposit '.$deposit->reference_number,
                    'metadata' => ['payment_rail' => 'bank_slip_verification', 'verification_id' => (int) $current->getKey()],
                ]);
                $current->forceFill([
                    'deposit_id' => $deposit->getKey(),
                    'status' => SlipVerificationStatus::Credited,
                    'review_reason' => substr(trim($note), 0, 120),
                    'reviewed_by' => $reviewerId,
                    'reviewed_at' => now(),
                ])->save();
                app(AuditLogService::class)->log(
                    userId: $reviewerId,
                    action: AuditAction::Deposit,
                    riskLevel: RiskLevel::Medium,
                    auditable: $current,
                    description: 'Staff approved and credited a verified bank slip.',
                    metadata: PaymentLogSanitizer::sanitize([
                        'verification_id' => (int) $current->getKey(),
                        'deposit_id' => (int) $deposit->getKey(),
                        'trans_ref_hash' => hash('sha256', (string) $current->trans_ref),
                        'note' => $note,
                        'amount' => (string) $current->amount,
                    ]),
                );

                return $current;
            }, 3);
        } catch (Throwable $exception) {
            if ($exception instanceof FinancialException) {
                throw $exception;
            }
            throw FinancialException::withCode('slip_credit_unconfirmed', 'The slip could not be credited safely; review it again before taking action.', [], $exception);
        }
    }

    private static function hasCreditableProviderEvidence(DepositSlipVerification $verification): bool
    {
        $payload = is_array($verification->provider_payload) ? $verification->provider_payload : [];
        $facts = is_array($payload['verification_facts'] ?? null) ? $payload['verification_facts'] : [];
        $amount = $facts['provider_amount'] ?? null;
        $claimedAmount = $facts['claimed_amount'] ?? null;
        $decision = strtolower((string) ($facts['fraud_decision'] ?? ''));
        $storedReferenceHash = $facts['trans_ref_hash'] ?? $payload['trans_ref_hash'] ?? null;

        if (($facts['hard_checks_passed'] ?? false) !== true
            || ($facts['provider_valid'] ?? false) !== true
            || ($facts['provider_duplicate'] ?? true) !== false
            || ($facts['receiver_matches'] ?? false) !== true
            || ! is_string($amount) || ! is_string($claimedAmount)
            || strtoupper((string) ($facts['currency'] ?? '')) !== Currency::THB->value
            || strtoupper((string) $verification->currency) !== Currency::THB->value
            || ! in_array($decision, ['allow', 'hold_for_review'], true)
            || ! is_string($storedReferenceHash)
            || ! hash_equals(hash('sha256', (string) $verification->trans_ref), $storedReferenceHash)
            || preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/D', $amount) !== 1
            || preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/D', $claimedAmount) !== 1) {
            return false;
        }

        return bccomp($amount, $claimedAmount, 2) === 0
            && bccomp($amount, (string) $verification->amount, 2) === 0;
    }

    public static function rejectReview(DepositSlipVerification $verification, string $reason, int $reviewerId): DepositSlipVerification
    {
        if ($reviewerId < 1 || trim($reason) === '' || strlen($reason) > 120) {
            throw FinancialException::withCode('slip_review_reason_required', 'A reviewer and a required rejection reason are needed.');
        }
        $operator = AdminAccess::operator();
        if (! AdminAccess::canAccessPanel($operator) || ! AdminAccess::allows($operator, AdminAccess::MANAGE_PAYOUTS)) {
            throw new AuthorizationException;
        }
        Gate::authorize('reject', $verification);
        if (! ($operator instanceof User) || (int) $operator->getKey() !== $reviewerId) {
            throw FinancialException::withCode('slip_review_actor_mismatch', 'The reviewer must be the authenticated finance operator.');
        }

        return DB::transaction(function () use ($verification, $reason, $reviewerId): DepositSlipVerification {
            $current = DepositSlipVerification::query()->lockForUpdate()->findOrFail($verification->getKey());
            if ($current->status !== SlipVerificationStatus::NeedsReview) {
                throw FinancialException::withCode('slip_review_state_conflict', 'This slip is no longer eligible for rejection.');
            }
            $current->forceFill([
                'status' => SlipVerificationStatus::Rejected,
                'review_reason' => substr(trim($reason), 0, 120),
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ])->save();
            app(AuditLogService::class)->log(
                userId: $reviewerId,
                action: AuditAction::Update,
                riskLevel: RiskLevel::Medium,
                auditable: $current,
                description: 'Staff rejected a bank-slip verification.',
                metadata: PaymentLogSanitizer::sanitize([
                    'verification_id' => (int) $current->getKey(),
                    'trans_ref_hash' => is_string($current->trans_ref) ? hash('sha256', $current->trans_ref) : null,
                    'reason' => $reason,
                ]),
            );

            return $current;
        }, 3);
    }
}
