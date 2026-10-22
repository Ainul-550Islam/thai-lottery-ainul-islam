<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\WebhookEventType;
use App\Exceptions\FinancialException;
use App\Models\Bet;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\Payout;
use App\Models\WalletConversion;
use App\Models\Withdrawal;
use App\Services\Finance\ExternalRefundPolicy;
use App\Services\Finance\FinancialReversalService;
use App\Services\Finance\Money;
use App\Services\Finance\RefundService;
use App\Services\Finance\WalletService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Finance\WithdrawalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

final class ManagedRefundBoundaryTest extends PaymentTestCase
{
    private function snapshot(): array
    {
        $out = [];
        foreach (['wallets', 'deposits', 'withdrawals', 'payments', 'financial_transactions', 'ledger_entries', 'ledger_accounts', 'withdrawal_reservations', 'financial_provider_reference_owners', 'audit_logs'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $out;
    }

    private function refused(callable $work, string $code): void
    {
        $before = $this->snapshot();
        try {
            $work();
            $this->fail('A managed refund was accepted without its authenticated domain workflow.');
        } catch (FinancialException $error) {
            $this->assertSame($code, $error->errorCode());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public static function managedTypes(): array
    {
        return array_map(fn ($model): array => [$model], [Deposit::class, Withdrawal::class, Payout::class, WalletConversion::class, Bet::class]);
    }

    #[DataProvider('managedTypes')]
    public function test_generic_engine_cannot_compensate_a_managed_domain_label(string $model): void
    {
        $f = $this->createPlayer('0.00');
        $tx = app(WalletService::class)->credit($f['wallet'], Money::of('100.00', Currency::THB), FinancialTransactionType::Deposit,
            'synthetic-managed-original', ['reference_type' => $model, 'reference_id' => 1]);
        $this->assertFalse(app(FinancialReversalService::class)->canReverse($tx));
        $this->refused(fn () => app(FinancialReversalService::class)->reverse($tx), 'reversal_requires_domain_workflow');
    }

    public function test_removed_label_does_not_bypass_a_durable_deposit_link(): void
    {
        $f = $this->createPlayer('0.00');
        $deposit = $this->createPendingDeposit($f['wallet'], '100.00');
        $tx = app(WalletService::class)->credit($f['wallet'], Money::of('100.00', Currency::THB), FinancialTransactionType::Deposit, 'synthetic-foreign-link');
        DB::table('deposits')->where('id', $deposit->id)->update(['financial_transaction_id' => $tx->id]);
        $this->refused(fn () => app(FinancialReversalService::class)->reverse($tx), 'reversal_requires_domain_workflow');
    }

    public static function refundLabels(): array
    {
        return array_map(fn ($state): array => [$state], [DepositStatus::Pending, DepositStatus::Confirmed, DepositStatus::Refunded]);
    }

    #[DataProvider('refundLabels')]
    public function test_legacy_refund_cannot_use_label_or_foreign_transaction_as_authority(DepositStatus $state): void
    {
        $f = $this->createPlayer('0.00');
        $deposit = $this->createPendingDeposit($f['wallet'], '100.00');
        $foreign = app(WalletService::class)->credit($f['wallet'], Money::of('100.00', Currency::THB), FinancialTransactionType::Deposit, 'synthetic-foreign-original');
        DB::table('deposits')->where('id', $deposit->id)->update(['status' => $state->value, 'financial_transaction_id' => $foreign->id]);
        $this->assertFalse(app(RefundService::class)->canRefund($deposit));
        // Even a swallowed error inside an outer transaction leaves no partial refund or audit claim.
        DB::transaction(fn () => $this->refused(fn () => app(RefundService::class)->refundDeposit($deposit->id, $f['user']->id, 'Approved by operator'), ExternalRefundPolicy::CODE));
    }

    public function test_correct_signature_does_not_supply_a_refund_specific_policy_or_bank_outcome(): void
    {
        $f = $this->createPlayer('0.00');
        $deposit = $this->createPendingDeposit($f['wallet'], '100.00', PaymentMethod::Stripe);
        $success = new WebhookPayload(gateway: 'stripe', eventId: 'deposit-original-event', eventType: WebhookEventType::DepositSuccess,
            providerReference: 'stripe-original-reference', internalReference: $deposit->reference_number, amount: '100.00', currency: Currency::THB, isSuccess: true);
        $this->assertTrue($this->webhookService()->processWebhookPayload($success)->success);
        $before = $this->snapshot();
        $body = json_encode(['id' => 'evt_signed_refund', 'type' => 'charge.refunded', 'data' => ['object' => [
            'id' => 'stripe-original-reference', 'amount' => 10000, 'currency' => 'thb',
            'metadata' => ['deposit_reference' => $deposit->reference_number],
        ]]], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $request = Request::create('/webhook/stripe', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $request->headers->set('Stripe-Signature', 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, self::STRIPE_WEBHOOK_SECRET));
        $this->assertTrue($this->gatewayManager()->driver('stripe')->verifyWebhookSignature($request));
        $parsed = $this->gatewayManager()->driver('stripe')->parseWebhook($request);
        $this->assertSame(WebhookEventType::ChargeRefunded, $parsed->eventType);
        $this->assertSame($deposit->reference_number, $parsed->internalReference);
        $this->assertSame('100.00', $parsed->amount);
        foreach ([1, 2] as $attempt) {
            $result = $this->webhookService()->handleWebhookRequest('stripe', $request);
            $this->assertFalse($result->success);
            $this->assertSame(ExternalRefundPolicy::MESSAGE, $result->message);
            $this->assertSame('failed', $result->actionTaken);
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_completed_withdrawal_is_not_recredited_or_relabelled_by_generic_reversal(): void
    {
        $f = $this->createPlayer('1000.00');
        $row = app(WithdrawalService::class)->request($f['wallet'], Money::of('100.00', Currency::THB), PaymentMethod::BankTransfer);
        app(WithdrawalApprovalService::class)->approve($row);
        app(WithdrawalCompletionService::class)->complete($row);
        $tx = FinancialTransaction::query()->findOrFail($row->fresh()->financial_transaction_id);
        $this->refused(fn () => app(FinancialReversalService::class)->reverse($tx), 'reversal_requires_domain_workflow');
        app(WithdrawalCompletionService::class)->verifySettled($row->fresh());
        $this->assertSame('900.00', $f['wallet']->fresh()->balance);
    }
}
