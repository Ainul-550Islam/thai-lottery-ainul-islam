<?php

declare(strict_types=1);

namespace App\Filament\Resources\WalletResource;

use App\Enums\WalletStatus;
use App\Models\Wallet;
use App\Services\Finance\WalletService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminPermission;
use App\Support\Admin\OperatorActionFactory;
use Filament\Actions\Action;
use Filament\Forms;

/**
 * Locking and unlocking are the only direct wallet controls exposed by Filament.
 *
 * Balance correction is deliberately absent. A one-operator form that accepts an
 * amount and evidence string is still an arbitrary balance-editing surface. Any
 * correction must enter a separately reviewed reconciliation/reversal workflow and
 * ultimately post compensating entries through the canonical finance services.
 */
final class WalletControlActions
{
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
                'visible' => static fn (Wallet $record): bool => AdminAccess::permits(AdminPermission::WALLETS_ADJUST)
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
                'visible' => static fn (Wallet $record): bool => AdminAccess::permits(AdminPermission::WALLETS_ADJUST)
                    && $record->status === WalletStatus::Locked,
                'action' => static function (Wallet $record): void {
                    OperatorActionFactory::run(
                        static fn () => app(WalletService::class)->unlockWallet($record),
                        'Wallet #'.$record->getKey().' is active again.',
                    );
                },
            ],
        ];
    }
}
