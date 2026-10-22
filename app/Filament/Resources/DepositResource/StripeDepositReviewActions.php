<?php

declare(strict_types=1);

namespace App\Filament\Resources\DepositResource;

use App\Enums\DepositStatus;
use App\Models\Deposit;
use App\Services\Payment\DepositReviewAccess;
use App\Services\Payment\StripeDepositReviewService;
use App\Services\Payment\StripeRefundRecoveryAccess;
use App\Services\Payment\StripeRefundRecoveryService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\OperatorActionFactory;
use Filament\Forms;
use Filament\Notifications\Notification;

/** Same service/permission boundary for list and detail actions. No raw provider payload rendered. */
final class StripeDepositReviewActions
{
    public static function specs(): array
    {
        $visible = static fn (Deposit $record): bool => config('payment.deposit_review.stripe.enabled', false)
            && $record->method->value === 'stripe' && $record->status === DepositStatus::Confirmed
            && app(DepositReviewAccess::class)->can(AdminAccess::operator());
        $current = static fn (Deposit $record): array => app(StripeDepositReviewService::class)->current($record, AdminAccess::operator()) ?? [];

        return [
            [
                'name' => 'recoverConfirmedStripeRefund', 'label' => 'Recover confirmed full Stripe refund',
                'icon' => 'heroicon-o-banknotes', 'color' => 'danger', 'confirm' => true,
                'heading' => 'Debit the original wallet for a proved completed provider refund',
                'description' => 'Separate financial duty required. Zero-fee full refunds only. Authenticated fixed-endpoint GETs prove the existing refund; no new Stripe refund is requested. One cash/liability compensation, subject to available balance and holds. Original capture and open review remain historical; no risk or withdrawal clearance.',
                'visible' => static fn (Deposit $record): bool => $visible($record)
                    && config('payment.refund_recovery.stripe.enabled', false)
                    && app(StripeRefundRecoveryAccess::class)->can(AdminAccess::operator()),
                'form' => [Forms\Components\TextInput::make('inbox_id')->label('Signature-verified succeeded refund inbox ID')
                    ->required()->numeric()->minValue(1)],
                'action' => static function (Deposit $record, array $data): void {
                    abort_unless(AdminAccess::canAccessPanel(AdminAccess::operator()), 403);
                    OperatorActionFactory::run(static fn () => app(StripeRefundRecoveryService::class)
                        ->recover($record, AdminAccess::operator(), (int) ($data['inbox_id'] ?? 0)),
                        'Local confirmed-refund recovery proved. No new provider refund or risk clearance.');
                },
            ],
            [
                'name' => 'inspectStripeFollowups', 'label' => 'Inspect Stripe refund/dispute follow-ups',
                'icon' => 'heroicon-o-exclamation-triangle', 'color' => 'warning', 'confirm' => true,
                'heading' => 'Record linked signed Stripe follow-ups',
                'description' => 'Reads the dedicated signature-verified inbox and appends linked refund/dispute evidence to an operator case. No Stripe request, refund, balance reversal, withdrawal restriction or risk clearance is performed.',
                'visible' => $visible,
                'action' => static function (Deposit $record): void {
                    abort_unless(AdminAccess::canAccessPanel(AdminAccess::operator()), 403);
                    OperatorActionFactory::run(static function () use ($record): void {
                        $case = app(StripeDepositReviewService::class)->details($record, AdminAccess::operator());
                        $text = 'No linked signed follow-up is in the current bounded inbox. This is not proof that no refund/dispute exists at Stripe.';
                        if ($case) {
                            $text = sprintf("Case %s, version %d, %d observation(s), state %s.\nAcknowledged never means financially resolved.\n",
                                $case['case_uuid'], $case['version'], $case['observation_count'], $case['state']);
                            foreach ($case['events'] as $event) {
                                $text .= sprintf("\n%s | %s | amount in minor units: %s %s | %s", $event['event_id'], $event['type'],
                                    $event['amount_minor'] ?? 'unknown', $event['currency'] ?? 'unknown', $event['status'] ?? 'unknown');
                            }
                            foreach ($case['acknowledgements'] as $note) {
                                $text .= sprintf("\nAcknowledgement v%d by staff#%d: %s", $note['version'], $note['actor_id'], $note['note']);
                            }
                        }
                        Notification::make()->success()->title('Stripe follow-up inspection — no money changed')
                            ->body('<pre>'.e($text).'</pre>')->persistent()->send();
                    });
                },
            ],
            [
                'name' => 'acknowledgeStripeFollowups', 'label' => 'Acknowledge current Stripe review evidence',
                'icon' => 'heroicon-o-clipboard-document-check', 'color' => 'warning', 'confirm' => true,
                'heading' => 'Record an acknowledgement — not a refund or financial resolution',
                'description' => 'The exact case version/evidence token is rechecked. New evidence reopens the case and refuses a stale acknowledgement. The note is encrypted and immutable. No balance, payment, ledger, provider or withdrawal state changes.',
                'visible' => static fn (Deposit $record): bool => $visible($record) && $current($record) !== [],
                'form' => [
                    Forms\Components\TextInput::make('case_uuid')->label('Case')->readOnly()->required()->default(static fn (Deposit $record) => $current($record)['case_uuid'] ?? ''),
                    Forms\Components\TextInput::make('version')->label('Evidence version')->readOnly()->required()->numeric()->default(static fn (Deposit $record) => $current($record)['version'] ?? 0),
                    Forms\Components\TextInput::make('evidence_hash')->label('Evidence SHA-256')->readOnly()->required()->default(static fn (Deposit $record) => $current($record)['evidence_hash'] ?? ''),
                    Forms\Components\Textarea::make('note')->label('Review findings / escalation reference')->required()->minLength(16)->maxLength(2000),
                ],
                'action' => static function (Deposit $record, array $data): void {
                    abort_unless(AdminAccess::canAccessPanel(AdminAccess::operator()), 403);
                    OperatorActionFactory::run(
                        static fn () => app(StripeDepositReviewService::class)->acknowledge($record, AdminAccess::operator(),
                            (string) ($data['case_uuid'] ?? ''), (int) ($data['version'] ?? 0), (string) ($data['evidence_hash'] ?? ''), (string) ($data['note'] ?? '')),
                        'Current evidence acknowledged. This is not refund execution, risk clearance or financial resolution.',
                    );
                },
            ],
        ];
    }
}
