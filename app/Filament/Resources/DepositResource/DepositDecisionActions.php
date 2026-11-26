<?php

declare(strict_types=1);

namespace App\Filament\Resources\DepositResource;

use App\Enums\DepositStatus;
use App\Models\Deposit;
use App\Services\Finance\DepositApprovalService;
use App\Services\Payment\DepositCheckoutAccess;
use App\Services\Payment\DepositCheckoutService;
use App\Services\Payment\DepositSettlementAccess;
use App\Services\Payment\ManualWalletDepositProofService;
use App\Services\Payment\ManualWalletDepositSettlementService;
use App\Services\Payment\StripeDepositSettlementService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminPermission;
use App\Support\Admin\OperatorActionFactory;
use Filament\Actions\Action;
use Filament\Forms;
use Illuminate\Support\Facades\DB;

/**
 * Human review and independently verified provider actions for a deposit.
 *
 * WHY APPROVAL NEVER IMPLIES COMPLETION
 * The domain splits them deliberately and this screen must not paper over it. Approving a
 * deposit moves no money: it records that an operator believes the payment merits review.
 * Approval never credits money. Wallet credit is deliberately absent from the generic
 * admin action set: only authenticated provider settlement, verified slip, or reviewed
 * reconciliation evidence may call the completion service. A typed reference is not proof.
 *
 * The one wallet-crediting action kept here is settleManualWallet for the manual bKash/Nagad
 * rail: the operator types the TrxID read in the merchant app and
 * ManualWalletDepositSettlementService credits only if it equals the TrxID the player
 * submitted on file (plus a proven checkout record). The generic free-text `complete`
 * action stays removed.
 *
 * Visibility is asked of the relevant approval and provider services, so
 * the buttons on screen agree with what the domain would accept. The permission gate is
 * separate and stricter: reading a deposit needs 'view transaction history', but deciding
 * one needs 'manage payouts', which is why an auditor sees this screen and no buttons.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No status is written here. Every branch calls DepositApprovalService or
 *   authenticated provider settlement/reconciliation services and reports what they said.
 * - No un-approve and no un-complete. Taking back a credited deposit is a reversing
 *   transaction (FinancialReversalService), not a status edit, and that flow is not
 *   exposed in this phase - reported as a gap instead of half-built.
 * - markProcessing has no canX() guard on the service, so its visibility replicates the
 *   service's own precondition (pending or approved, nothing credited). The service still
 *   re-checks under a row lock; this is only about which button to offer.
 */
final class DepositDecisionActions
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
                'name' => 'approve',
                'label' => 'Approve',
                'icon' => 'heroicon-o-check-circle',
                'color' => 'success',
                'heading' => 'Approve this deposit',
                'description' => 'Records that the payment was verified. NO money moves and no wallet is credited by this step — completion does that, as a separate decision.',
                'submitLabel' => 'Approve',
                'form' => [
                    Forms\Components\Textarea::make('note')
                        ->label('Note (optional, kept on the deposit)')
                        ->maxLength(500)
                        ->helperText('What did you check? A provider reference or statement line makes the next reconciliation cheap.'),
                ],
                'visible' => static fn (Deposit $record): bool => AdminAccess::permits(AdminPermission::DEPOSITS_APPROVE)
                    && app(DepositApprovalService::class)->canApprove($record),
                'action' => static function (Deposit $record, array $data): void {
                    OperatorActionFactory::run(
                        static fn () => app(DepositApprovalService::class)->approve(
                            $record,
                            AdminAccess::operator()?->getKey() === null ? null : (int) AdminAccess::operator()->getKey(),
                            self::optionalString($data['note'] ?? null),
                        ),
                        $record->reference_number.' is approved. It is not credited yet.',
                    );
                },
            ],
            [
                'name' => 'reject',
                'label' => 'Reject',
                'icon' => 'heroicon-o-x-circle',
                'color' => 'danger',
                'heading' => 'Reject this deposit',
                'description' => 'The request is refused and no money ever moves. A deposit that has already been credited cannot be rejected — that would need a reversing transaction.',
                'submitLabel' => 'Reject this deposit',
                'form' => [
                    Forms\Components\Textarea::make('reason')
                        ->label('Reason (required, shown in the decision trail)')
                        ->required()
                        ->minLength(5)
                        ->maxLength(500),
                ],
                'visible' => static fn (Deposit $record): bool => AdminAccess::permits(AdminPermission::DEPOSITS_REJECT)
                    && app(DepositApprovalService::class)->canReject($record),
                'action' => static function (Deposit $record, array $data): void {
                    OperatorActionFactory::run(
                        static fn () => app(DepositApprovalService::class)->reject(
                            $record,
                            (string) $data['reason'],
                            AdminAccess::operator()?->getKey() === null ? null : (int) AdminAccess::operator()->getKey(),
                        ),
                        $record->reference_number.' is rejected.',
                    );
                },
            ],
            [
                'name' => 'markProcessing',
                'label' => 'Mark processing',
                'icon' => 'heroicon-o-arrow-path',
                'color' => 'warning',
                'confirm' => true,
                'heading' => 'Mark this deposit as being settled',
                'description' => 'Bookkeeping only: it makes a long-running settlement visible from the outside. No balance changes and completion still has to be run.',
                'visible' => static fn (Deposit $record): bool => AdminAccess::permits(AdminPermission::DEPOSITS_REVIEW)
                    && $record->financial_transaction_id === null
                    && in_array($record->status, [DepositStatus::Pending, DepositStatus::Approved], true),
                'action' => static function (Deposit $record): void {
                    OperatorActionFactory::run(
                        static fn () => app(DepositApprovalService::class)->markProcessing($record),
                        $record->reference_number.' is marked processing.',
                    );
                },
            ],
            [
                'name' => 'settleManualWallet',
                'label' => 'Verify TrxID & credit (bKash/Nagad)',
                'icon' => 'heroicon-o-check-badge',
                'color' => 'primary',
                'confirm' => true,
                'heading' => 'Verify the TrxID in the merchant app, then credit the wallet',
                'description' => 'Open the bKash/Nagad merchant app, find the payment from the sender number shown here, and confirm the amount and the TrxID. Typing that TrxID credits the wallet and cannot be undone from this panel.',
                'submitLabel' => 'Credit the wallet',
                'form' => [
                    Forms\Components\Placeholder::make('claim')
                        ->label('What the player submitted')
                        ->content(static function (Deposit $record): string {
                            $proof = DB::table(ManualWalletDepositProofService::TABLE)->where('deposit_id', $record->id)->first();

                            return $proof === null
                                ? 'No proof submitted yet.'
                                : sprintf('%s %s — sender %s — TrxID %s', (string) $record->amount, $record->currency->value, $proof->sender_number, $proof->trx_id);
                        }),
                    Forms\Components\TextInput::make('trx_id')
                        ->label('TrxID you verified in the merchant app')
                        ->required()
                        ->maxLength(32),
                ],
                'visible' => static fn (Deposit $record): bool => AdminAccess::permits(AdminPermission::DEPOSITS_APPROVE)
                    && ManualWalletDepositSettlementService::appliesTo($record),
                'action' => static function (Deposit $record, array $data): void {
                    abort_unless(AdminAccess::canAccessPanel(AdminAccess::operator()), 403);
                    OperatorActionFactory::run(
                        static fn () => app(ManualWalletDepositSettlementService::class)->settle($record, AdminAccess::operator(), (string) $data['trx_id']),
                        $record->reference_number.' credited after TrxID verification.',
                    );
                },
            ],
            [
                'name' => 'lookupCheckout',
                'label' => 'Lookup original Stripe checkout',
                'icon' => 'heroicon-o-magnifying-glass',
                'color' => 'gray',
                'confirm' => true,
                'heading' => 'Lookup original Stripe checkout session',
                'description' => 'Read-only bounded Stripe GET lookup for an owned checkout whose response was lost locally. Never creates a new checkout or credits balance.',
                'visible' => static fn (Deposit $record): bool => config('payment.checkout_lookup.stripe.enabled', false)
                    && $record->method->value === 'stripe'
                    && $record->financial_transaction_id === null
                    && in_array($record->status, [DepositStatus::Pending, DepositStatus::Approved], true)
                    && app(DepositCheckoutAccess::class)->can(AdminAccess::operator()),
                'action' => static function (Deposit $record): void {
                    abort_unless(AdminAccess::canAccessPanel(AdminAccess::operator()), 403);
                    OperatorActionFactory::run(
                        static fn () => app(DepositCheckoutService::class)->reconcileStripe($record, AdminAccess::operator()),
                        'Original Stripe checkout lookup recorded. No wallet balance was credited.',
                    );
                },
            ],
            [
                'name' => 'reconcileStripeSettlement',
                'label' => 'Verify & credit Stripe captured funding',
                'icon' => 'heroicon-o-check-badge',
                'color' => 'primary',
                'confirm' => true,
                'heading' => 'Verify captured Stripe funding and credit the wallet',
                'description' => 'Performs authenticated GET verification of the original Stripe session, PaymentIntent, Charge and refund list. Successful verification credits wallet balance.',
                'visible' => static fn (Deposit $record): bool => config('payment.deposit_settlement.stripe.enabled', false)
                    && $record->method->value === 'stripe'
                    && $record->financial_transaction_id === null
                    && in_array($record->status, [DepositStatus::Pending, DepositStatus::Approved], true)
                    && app(DepositSettlementAccess::class)->can(AdminAccess::operator()),
                'action' => static function (Deposit $record): void {
                    abort_unless(AdminAccess::canAccessPanel(AdminAccess::operator()), 403);
                    OperatorActionFactory::run(
                        static fn () => app(StripeDepositSettlementService::class)->reconcile($record, AdminAccess::operator()),
                        'Original Stripe captured-funding verification completed.',
                    );
                },
            ],
            ...StripeDepositReviewActions::specs(),
        ];
    }

    private static function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return trim($value) === '' ? null : $value;
    }
}
