<?php

declare(strict_types=1);

namespace App\Filament\Resources\WalletResource;

use App\DTOs\Finance\LedgerAdjustmentData;
use App\Enums\WalletStatus;
use App\Models\FinancialTransaction;
use App\Models\Wallet;
use App\Services\Finance\LedgerAdjustmentService;
use App\Services\Finance\WalletService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use App\Support\Admin\OperatorActionFactory;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;

/**
 * Locking, unlocking and the one manual balance correction the panel allows.
 *
 * WHY THE ADJUSTMENT AMOUNT IS VALIDATED WITH A REGEX AND NOT `numeric`
 * `numeric` accepts "1e3", " 12 ", "0x10" and "1.005", and Filament would hand the value
 * on as a float. Money in this application is a bcmath decimal string end to end: the
 * moment an amount becomes a float, 0.1 + 0.2 stops being 0.3 and the ledger acquires a
 * rounding artefact that no reconciliation can explain. So the field accepts exactly
 * digits with at most two decimal places, as a string, and hands that string to
 * Money::of() untouched. There is no cast, no round() and no number_format() on this path.
 *
 * WHY AN ADJUSTMENT IS A CREDIT OR DEBIT AND NOT A BALANCE EDIT
 * WalletService::credit/debit post a balanced double entry against the adjustment-equity
 * account, so a manual correction is visible in the ledger as a correction, attributable to
 * the operator who made it, and reversible by a further posting. Writing wallets.balance
 * directly would move money that no ledger entry explains — the exact defect an auditor
 * looks for first. The reason field is required for the same purpose: an adjustment with no
 * stated cause is indistinguishable from theft after the fact.
 *
 * WHY THE ADJUSTMENT GOES THROUGH LedgerAdjustmentService AND NOT WalletService
 * It used to call WalletService::credit/debit directly, with a five-character reason and a
 * null idempotency key. That bypassed a complete, hardened lane that already existed in
 * this codebase and had no callers anywhere: LedgerAdjustmentService enforces authority by
 * role name, locks the wallet row, cross-checks the currency, derives a deterministic
 * idempotency key so a retry re-serves instead of double-posting, refuses a DIFFERENT
 * correction under the SAME evidence token as a fork, maps conservation refusals into its
 * own vocabulary, and audits at RiskLevel::Critical. Every one of those was being skipped
 * by the only path an operator could actually reach.
 *
 * The most concrete consequence of the old wiring was that null idempotency key: a
 * double-click, a slow-network retry or a browser resubmit posted the adjustment twice,
 * because nothing identified the correction. Real money, created twice, with two audit
 * rows that each looked perfectly reasonable on their own.
 *
 * WHAT THE LANE ADDS TO THE FORM
 * An `evidence` field. The lane's law is that a correction cannot land without a canonical
 * evidence token - an internal ticket, a dispute id, a reconciliation key - because that
 * token is what ties the money to the paper. Synthesising one here would make the control
 * decorative, so the operator types it. It also joins the idempotency key, which is what
 * makes "the same correction asked twice" an answerable question.
 *
 * The reason floor rises from 5 to 8 characters and its ceiling falls from 500 to 255, to
 * match LedgerAdjustmentData exactly. Two different bounds for one field is how a form
 * accepts what the service then refuses, leaving the operator with a domain error instead
 * of a validation one.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No lockFunds/unlockFunds (partial reservations). Those belong to the withdrawal and bet
 *   flows that create the holds; a human moving a reservation by hand would desynchronise
 *   it from the request that owns it.
 * - No wallet creation, closure or currency change. Wallets are provisioned with the user.
 * - No reversal of an adjustment. FinancialReversalService owns reversals and is not
 *   exposed in this phase; a mistaken adjustment is corrected by the opposite adjustment,
 *   which is what the reason field is there to record.
 */
final class WalletControlActions
{
    /**
     * Digits, optionally with one or two decimal places. Nothing else.
     */
    public const AMOUNT_PATTERN = '/^\d{1,15}(\.\d{1,2})?$/';

    /**
     * Reason bounds, mirroring LedgerAdjustmentData::fromInput().
     *
     * Restated as constants rather than read off the DTO because the DTO enforces them
     * inline in a conditional instead of exposing them, and a Filament ->minLength() call
     * needs a value at build time. The lane's test suite asserts these two numbers against
     * the DTO's own refusals, so a drift fails a test rather than failing an operator.
     */
    public const REASON_MIN = 8;

    public const REASON_MAX = 255;

    /**
     * @return list<\Filament\Tables\Actions\Action>
     */
    public static function forTable(): array
    {
        return OperatorActionFactory::forTable(self::specs());
    }

    /**
     * @return list<Action>
     */
    public static function forPage(): array
    {
        return OperatorActionFactory::forPage(self::specs());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function specs(): array
    {
        return [
            [
                'name' => 'lockWallet',
                'label' => 'Lock wallet',
                'icon' => 'heroicon-o-lock-closed',
                'color' => 'danger',
                'heading' => 'Lock this wallet',
                'description' => 'A locked wallet accepts no credits and no debits: deposits, withdrawals, bets and payouts against it are all refused until it is unlocked. Existing balances are untouched.',
                'submitLabel' => 'Lock the wallet',
                'form' => [
                    Forms\Components\Textarea::make('reason')
                        ->label('Reason (required — WalletService refuses a lock without one)')
                        ->required()
                        ->minLength(5)
                        ->maxLength(500)
                        ->helperText('Stored on the wallet as locked_reason, so the next operator knows why it is frozen.'),
                ],
                'visible' => static fn (Wallet $record): bool => AdminAccess::current(AdminAccess::MANAGE_WALLET)
                    && ! in_array($record->status, [WalletStatus::Locked, WalletStatus::Closed], true),
                'action' => static function (Wallet $record, array $data): void {
                    OperatorActionFactory::run(
                        static fn () => app(WalletService::class)->lockWallet($record, (string) $data['reason']),
                        'Wallet #'.$record->getKey().' is locked.',
                    );
                },
            ],
            [
                'name' => 'unlockWallet',
                'label' => 'Unlock wallet',
                'icon' => 'heroicon-o-lock-open',
                'color' => 'success',
                'confirm' => true,
                'heading' => 'Return this wallet to active use',
                'description' => 'Money movement is allowed again immediately. Only a wallet in the locked state can be unlocked — a frozen or suspended wallet is a different decision and the service will say so.',
                'visible' => static fn (Wallet $record): bool => AdminAccess::current(AdminAccess::MANAGE_WALLET)
                    && $record->status === WalletStatus::Locked,
                'action' => static function (Wallet $record): void {
                    OperatorActionFactory::run(
                        static fn () => app(WalletService::class)->unlockWallet($record),
                        'Wallet #'.$record->getKey().' is active again.',
                    );
                },
            ],
            [
                'name' => 'adjustBalance',
                'label' => 'Adjust balance',
                'icon' => 'heroicon-o-scale',
                'color' => 'warning',
                'heading' => 'Post a manual adjustment',
                'description' => 'Moves money into or out of this wallet through LedgerAdjustmentService, posting a balanced double entry against the adjustment-equity account. This is real money in a real ledger: it is not a display correction.',
                'submitLabel' => 'Post the adjustment',
                'form' => [
                    Forms\Components\Select::make('direction')
                        ->label('Direction')
                        ->options([
                            'credit' => 'Credit — increase the balance',
                            'debit' => 'Debit — decrease the balance',
                        ])
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('amount')
                        ->label('Amount')
                        ->required()
                        ->rule('regex:'.self::AMOUNT_PATTERN)
                        ->validationMessages([
                            'regex' => 'Enter a plain decimal amount: digits, optionally with up to two decimal places (for example 1500 or 1500.25).',
                        ])
                        ->helperText('Kept as a string and handed to Money::of() exactly as typed. No float conversion happens anywhere on this path.'),

                    // The sign is NOT typed by the operator: direction is a separate select
                    // and the action composes the signed decimal string. Keeping the sign
                    // out of this field means it still refuses "-50", and a value whose sign
                    // contradicted the selected direction cannot become a second source of
                    // truth about which way the money went.
                    Forms\Components\TextInput::make('evidence')
                        ->label('Evidence token (required — the paper behind this correction)')
                        ->required()
                        ->rule('regex:/^[A-Za-z0-9:._-]{8,64}$/')
                        ->validationMessages([
                            'regex' => 'Enter a canonical token of 8-64 characters: letters, digits, colon, dash, dot or underscore (for example TICKET-4471 or REC-2026W41-004).',
                        ])
                        ->helperText('An internal ticket, dispute id or reconciliation key. Stored on the transaction forever, and part of the idempotency key — so the same correction asked twice is re-served rather than posted twice.'),

                    Forms\Components\Textarea::make('reason')
                        ->label('Reason (required — recorded on the transaction and the ledger entries)')
                        ->required()
                        ->minLength(self::REASON_MIN)
                        ->maxLength(self::REASON_MAX)
                        ->helperText('A full sentence. Bounds mirror LedgerAdjustmentData so this form cannot accept what the service will then refuse.'),
                ],
                'visible' => static fn (Wallet $record): bool => AdminAccess::current(AdminAccess::MANAGE_WALLET)
                    && $record->status !== WalletStatus::Closed,
                'action' => static function (Wallet $record, array $data): void {
                    OperatorActionFactory::run(static function () use ($record, $data): void {
                        $direction = (string) $data['direction'];

                        // fromInput() is the validator. It throws LedgerAdjustmentException
                        // for a malformed amount, a reason outside its bounds, a
                        // non-canonical evidence token or an absent operator, and
                        // OperatorActionFactory::run() turns that into a persistent danger
                        // notification - so a refusal is a message, never a 500.
                        $ask = LedgerAdjustmentData::fromInput(
                            walletId: (int) $record->getKey(),
                            amount: ($direction === 'debit' ? '-' : '+').(string) $data['amount'],
                            currency: $record->currency->value,
                            reason: (string) $data['reason'],
                            sourceEvidence: (string) $data['evidence'],
                            operatorUserId: (int) (AdminAccess::operator()?->getKey() ?? 0),
                        );

                        $result = app(LedgerAdjustmentService::class)->post($ask);

                        /** @var FinancialTransaction $transaction */
                        $transaction = $result['transaction'];
                        $replayed = (bool) $result['replayed'];

                        $service = app(WalletService::class);

                        // A replay is a success, not an error: the correction the operator
                        // asked for IS posted and the money moved exactly once. Saying
                        // "posted" without qualification would be true but incomplete, and
                        // an operator who double-clicked deserves to be told the second
                        // click did nothing.
                        Notification::make()
                            ->success()
                            ->title($replayed ? 'Adjustment already posted' : 'Adjustment posted')
                            ->body(sprintf(
                                '%sWallet #%d %sed %s. Transaction %s. Available balance is now %s.',
                                $replayed
                                    ? 'This correction was already posted under the same evidence token; no money moved again. '
                                    : '',
                                (int) $record->getKey(),
                                $direction,
                                AdminFormat::money((string) $transaction->amount, $record->currency->value),
                                (string) $transaction->reference_number,
                                AdminFormat::money(
                                    $service->availableBalance($record->fresh())->amount(),
                                    $record->currency->value,
                                ),
                            ))
                            ->send();
                    });
                },
            ],
        ];
    }
}
