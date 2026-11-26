<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentMethodConfigResource;

use App\Enums\PaymentMethodStatus;
use App\Models\PaymentMethodConfig;
use App\Services\Payment\PaymentProviderRegistry;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminPermission;
use App\Support\Admin\OperatorActionFactory;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Tables\Actions\Action as TableAction;

/**
 * The lifecycle verbs for a payment method offer, declared once and rendered twice.
 *
 * WHAT A "METHOD OFFER" IS
 *
 * One row of `payment_method_configs` is a promise to a payer: "on this provider, this method
 * code, in this currency, you may move between min_amount and max_amount for fee_bps basis
 * points". The registry's `resolveOffer()` refuses to return anything that is not Enabled, so
 * enabling this row is the moment the offer becomes reachable by a real payment intent —
 * that is why every verb here is a High-risk audited transition rather than a checkbox.
 *
 * WHAT THE SCREEN DECIDES AND WHAT THE SERVICE DECIDES
 *
 * The service (`PaymentProviderRegistry::transitionMethod()`) owns the transition map
 * (`PaymentMethodStatus::canTransitionTo()`), the row lock, `retired_at`, and the audit line.
 * The `visible` closures below only pre-filter on the same two facts — permission and the
 * map — so an operator is never offered a transition that is guaranteed to be refused. A
 * refusal still arrives from the service with its own wording, and it is reported verbatim.
 *
 * THE REASON IS REQUIRED HERE, ALTHOUGH THE SERVICE ONLY TRUNCATES IT
 *
 * `transitionMethod()` accepts any string and limits it to 255 characters for the audit line.
 * An empty reason therefore produces a High-risk audit row that names the mover but not the
 * motive. The screen requires a full phrase because "who" without "why" is the half of the
 * trail that gets read in an incident review; the service stays permissive so that console and
 * system lanes are not broken by a stricter contract.
 */
final class PaymentMethodConfigActions
{
    /**
     * @return list<TableAction>
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
                'name' => 'enable',
                'label' => 'Enable offer',
                'icon' => 'heroicon-o-check-circle',
                'color' => 'success',
                'heading' => 'Make this offer reachable',
                'description' => 'From this moment resolveOffer() will return this method for new payment intents. The provider itself must still be Active for the offer to be served.',
                'visible' => static fn (PaymentMethodConfig $record): bool => self::mayManage()
                    && $record->status->canTransitionTo(PaymentMethodStatus::Enabled),
                'form' => [self::reasonField('Why is this offer going live?')],
                'action' => static fn (PaymentMethodConfig $record, array $data) => self::transition($record, PaymentMethodStatus::Enabled, (string) $data['reason']),
            ],
            [
                'name' => 'disable',
                'label' => 'Disable offer',
                'icon' => 'heroicon-o-pause-circle',
                'color' => 'warning',
                'heading' => 'Take this offer off the menu',
                'description' => 'Existing payment records still resolve; new intents stop binding this method. This is the reversible move — retirement is not.',
                'visible' => static fn (PaymentMethodConfig $record): bool => self::mayManage()
                    && $record->status->canTransitionTo(PaymentMethodStatus::Disabled),
                'form' => [self::reasonField('Why is this offer being withdrawn?')],
                'action' => static fn (PaymentMethodConfig $record, array $data) => self::transition($record, PaymentMethodStatus::Disabled, (string) $data['reason']),
            ],
            [
                'name' => 'retire',
                'label' => 'Retire offer',
                'icon' => 'heroicon-o-archive-box-x-mark',
                'color' => 'danger',
                'heading' => 'Retire this offer permanently',
                'description' => 'Retired is one-way in the enum: not even resolveOffer() will return it afterwards. The house must take an offer off the shelf (Disable) before it can burn the shelf, unless it was never enabled.',
                'confirm' => true,
                'visible' => static fn (PaymentMethodConfig $record): bool => self::mayManage()
                    && $record->status->canTransitionTo(PaymentMethodStatus::Retired),
                'form' => [self::reasonField('Why is this offer being retired for good?')],
                'action' => static fn (PaymentMethodConfig $record, array $data) => self::transition($record, PaymentMethodStatus::Retired, (string) $data['reason']),
            ],
        ];
    }

    /**
     * One reason field, used by all three verbs, so the wording cannot drift between them.
     *
     * 8 characters is the floor rather than 255: the column limit is enforced by the service
     * (it truncates), and a form that silently cut an operator's sentence in half would be
     * worse than one that makes them write it shorter.
     */
    private static function reasonField(string $label): Forms\Components\Textarea
    {
        return Forms\Components\Textarea::make('reason')
            ->label($label)
            ->helperText('Written into the High-risk audit line. The service keeps 255 characters.')
            ->required()
            ->minLength(8)
            ->maxLength(255);
    }

    private static function transition(PaymentMethodConfig $record, PaymentMethodStatus $target, string $reason): void
    {
        OperatorActionFactory::run(
            static fn () => app(PaymentProviderRegistry::class)->transitionMethod($record, $target, $reason),
            sprintf('Offer moved to %s.', $target->value),
        );
    }

    private static function mayManage(): bool
    {
        return AdminAccess::permits(AdminPermission::PROVIDERS_MANAGE);
    }
}
