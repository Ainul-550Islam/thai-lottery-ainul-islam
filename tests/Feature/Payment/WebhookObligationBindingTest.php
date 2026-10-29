<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Enums\WebhookEventType;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Finance\WithdrawalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** Signed provider facts are not authority over another provider's obligation. */
final class WebhookObligationBindingTest extends PaymentTestCase
{
    private function withdrawal(bool $approved = true): array
    {
        $player = $this->createPlayer('1000.00', Currency::BDT);
        $row = app(WithdrawalService::class)->request($player['wallet'], Money::of('400.00', Currency::BDT), PaymentMethod::Bkash,
            options: ['payout_details' => ['phone' => '01711111111']]);
        if ($approved) {
            app(WithdrawalApprovalService::class)->approve($row);
        }

        return [$row->fresh(), $player['wallet']->fresh()];
    }

    private function payload(Deposit|Withdrawal $row, array $changes = []): WebhookPayload
    {
        return new WebhookPayload(...array_replace([
            'gateway' => $row->method->value, 'eventId' => 'binding-'.bin2hex(random_bytes(8)),
            'eventType' => $row instanceof Deposit ? WebhookEventType::DepositSuccess : WebhookEventType::WithdrawalSuccess,
            'providerReference' => $row->provider_reference ?: 'synthetic-provider-reference',
            'internalReference' => $row->reference_number, 'amount' => (string) $row->amount,
            'currency' => $row->currency, 'isSuccess' => true,
        ], $changes));
    }

    private function snapshot(Deposit|Withdrawal $row, Wallet $wallet): array
    {
        return [$row->fresh()->getRawOriginal(), $wallet->fresh()->getRawOriginal(),
            FinancialTransaction::query()->count(), LedgerEntry::query()->count(), Payment::query()->count()];
    }

    private function refuse(Deposit|Withdrawal $row, Wallet $wallet, array $changes): void
    {
        $before = $this->snapshot($row, $wallet);
        $result = $this->webhookService()->processWebhookPayload($this->payload($row, $changes));
        $this->assertFalse($result->success);
        $this->assertSame($before, $this->snapshot($row, $wallet), 'Rejected callback must change no obligation, hold, wallet, payment or ledger facts.');
    }

    public static function invalidFacts(): array
    {
        return ['foreign gateway' => [['gateway' => 'stripe']], 'missing currency' => [['currency' => null]],
            'wrong currency' => [['currency' => Currency::USD]], 'missing amount' => [['amount' => null]],
            'sub-cent amount' => [['amount' => '400.009']], 'scientific amount' => [['amount' => '4e2']],
            'negative amount' => [['amount' => '-400.00']], 'missing provider reference' => [['providerReference' => null]],
            'blank provider reference' => [['providerReference' => ' ']],
            'contradictory success flag' => [['eventType' => WebhookEventType::WithdrawalFailed]],
            'unknown internal reference' => [['internalReference' => 'not-the-recorded-reference']]];
    }

    #[DataProvider('invalidFacts')]
    public function test_invalid_withdrawal_facts_cannot_debit_or_release_a_hold(array $changes): void
    {
        [$row, $wallet] = $this->withdrawal();
        $row->update(['provider' => 'bkash', 'provider_reference' => 'synthetic-provider-reference']);
        $this->refuse($row, $wallet, $changes);
    }

    public function test_foreign_failure_cannot_release_another_providers_reservation(): void
    {
        [$row, $wallet] = $this->withdrawal();
        $this->refuse($row, $wallet, ['gateway' => 'stripe', 'eventType' => WebhookEventType::WithdrawalFailed, 'isSuccess' => false]);
    }

    public function test_success_callback_cannot_auto_approve_a_pending_withdrawal(): void
    {
        config(['finance.withdrawal.require_manual_approval' => true]);
        [$row, $wallet] = $this->withdrawal(false);
        $this->refuse($row, $wallet, []);
        $this->assertSame(WithdrawalStatus::Pending, $row->fresh()->status);
    }

    public function test_known_provider_reference_cannot_be_replaced_by_callback(): void
    {
        [$row, $wallet] = $this->withdrawal();
        $row->update(['provider' => 'bkash', 'provider_reference' => 'already-bound-reference']);
        $this->refuse($row, $wallet, ['providerReference' => 'different-reference']);
    }

    public function test_ambiguous_provider_reference_is_not_first_row_wins(): void
    {
        [$first, $wallet] = $this->withdrawal();
        [$second] = $this->withdrawal();
        foreach ([$first, $second] as $row) {
            $row->update(['provider' => 'bkash', 'provider_reference' => 'reused-provider-reference']);
        }
        $this->refuse($first, $wallet, ['internalReference' => null]);
        $this->assertSame(WithdrawalStatus::Approved, $second->fresh()->status);
    }

    public function test_wallet_owner_mismatch_is_refused_before_any_completion(): void
    {
        [$row, $wallet] = $this->withdrawal();
        $row->update(['user_id' => User::factory()->create()->id]);
        $this->refuse($row, $wallet, []);
    }

    public function test_foreign_deposit_callback_cannot_create_payment_or_credit_wallet(): void
    {
        $player = $this->createPlayer('100.00');
        $row = $this->createPendingDeposit($player['wallet'], '400.00');
        $this->refuse($row, $player['wallet'], ['gateway' => 'bkash']);
    }

    public function test_normalized_currency_is_authoritative_even_without_raw_currency_key(): void
    {
        $player = $this->createPlayer('100.00');
        $row = $this->createPendingDeposit($player['wallet'], '400.00');
        $this->refuse($row, $player['wallet'], ['currency' => Currency::USD]);
    }

    public function test_payment_gateway_fork_is_refused_without_mutating_existing_payment(): void
    {
        [$row, $wallet] = $this->withdrawal();
        $payment = Payment::factory()->create(['user_id' => $row->user_id, 'payable_type' => Withdrawal::class,
            'payable_id' => $row->id, 'method' => PaymentMethod::Stripe, 'gateway' => 'stripe',
            'currency' => Currency::BDT, 'amount' => '400.00', 'fee' => (string) $row->fee]);
        $before = $payment->fresh()->getRawOriginal();
        $this->refuse($row, $wallet, []);
        $this->assertSame($before, $payment->fresh()->getRawOriginal());
    }

    public function test_foreign_provider_cannot_reverse_completed_withdrawal(): void
    {
        [$row, $wallet] = $this->withdrawal();
        app(WithdrawalCompletionService::class)->complete($row);
        $this->refuse($row->fresh(), $wallet, ['gateway' => 'stripe', 'eventType' => WebhookEventType::ChargeRefunded, 'isSuccess' => false]);
    }

    public static function currencies(): array
    {
        // USD remains covered through Stripe; unsupported crypto cannot supply
        // settlement evidence merely to stand in for a currency test.
        return ['THB' => [Currency::THB, PaymentMethod::Stripe], 'USD' => [Currency::USD, PaymentMethod::Stripe],
            'BDT' => [Currency::BDT, PaymentMethod::Nagad]];
    }

    #[DataProvider('currencies')]
    public function test_valid_bound_deposit_credits_exactly_once_in_its_own_currency(Currency $currency, PaymentMethod $method): void
    {
        $player = $this->createPlayer('100.00', $currency);
        $row = $this->createPendingDeposit($player['wallet'], '400.00', $method);
        $first = $this->webhookService()->processWebhookPayload($this->payload($row));
        $this->assertTrue($first->success);
        $this->assertSame('500.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame(DepositStatus::Confirmed, $row->fresh()->status);
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame($currency, FinancialTransaction::query()->sole()->currency);
        $this->assertSame(2, LedgerEntry::query()->count());
        $this->assertTrue($this->webhookService()->processWebhookPayload($this->payload($row->fresh()))->success);
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame('500.00', (string) $player['wallet']->fresh()->balance);
    }

    public function test_confirmed_deposit_replay_refuses_corrupted_original_ledger_without_new_credit(): void
    {
        $player = $this->createPlayer('100.00', Currency::BDT);
        $row = $this->createPendingDeposit($player['wallet'], '400.00', PaymentMethod::Nagad);
        $this->assertTrue($this->webhookService()->processWebhookPayload($this->payload($row))->success);
        // Simulate a historical accounting contradiction, not a provider fact.
        $entry = LedgerEntry::query()->firstOrFail();
        DB::table('ledger_entries')->where('id', $entry->id)->update(['amount' => '399.00']);
        $before = $this->snapshot($row, $player['wallet']);
        try {
            $this->webhookService()->processWebhookPayload($this->payload($row->fresh()));
            $this->fail('A confirmed label cannot replace the original balanced-ledger proof.');
        } catch (FinancialException $exception) {
            $this->assertNotSame('', $exception->errorCode());
        }
        $this->assertSame($before, $this->snapshot($row, $player['wallet']));
        $this->assertSame('500.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame(1, FinancialTransaction::query()->count());
    }

    public function test_bare_bkash_deposit_without_original_checkout_cannot_be_credited(): void
    {
        $player = $this->createPlayer('100.00', Currency::BDT);
        $row = $this->createPendingDeposit($player['wallet'], '400.00', PaymentMethod::Bkash);
        $this->refuse($row, $player['wallet'], []);
        $this->assertSame('100.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame(DepositStatus::Pending, $row->fresh()->status);
    }

    public function test_valid_withdrawal_success_and_later_distinct_event_do_not_double_debit(): void
    {
        [$row, $wallet] = $this->withdrawal();
        $this->assertTrue($this->webhookService()->processWebhookPayload($this->payload($row))->success);
        $this->assertSame('600.00', (string) $wallet->fresh()->balance);
        $this->assertSame('0.00', (string) $wallet->fresh()->locked_balance);
        $this->assertSame(WithdrawalStatus::Completed, $row->fresh()->status);
        $before = $this->snapshot($row, $wallet);
        $this->assertTrue($this->webhookService()->processWebhookPayload($this->payload($row->fresh()))->success);
        $this->assertSame($before, $this->snapshot($row, $wallet));
    }

    public function test_valid_provider_reference_only_callback_settles_one_unambiguous_obligation(): void
    {
        [$row, $wallet] = $this->withdrawal();
        $row->update(['provider' => 'bkash', 'provider_reference' => 'unique-synthetic-reference']);
        $this->assertTrue($this->webhookService()->processWebhookPayload($this->payload($row, ['internalReference' => null]))->success);
        $this->assertSame('600.00', (string) $wallet->fresh()->balance);
        $this->assertSame(WithdrawalStatus::Completed, $row->fresh()->status);
    }

    public function test_valid_failure_releases_only_the_reserved_amount_without_refund_credit(): void
    {
        [$row, $wallet] = $this->withdrawal();
        $result = $this->webhookService()->processWebhookPayload($this->payload($row, [
            'eventType' => WebhookEventType::WithdrawalFailed, 'isSuccess' => false,
        ]));
        $this->assertFalse($result->success);
        $this->assertSame(WithdrawalStatus::Failed, $row->fresh()->status);
        $this->assertSame('1000.00', (string) $wallet->fresh()->balance);
        $this->assertSame('0.00', (string) $wallet->fresh()->locked_balance);
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_refused_pending_event_can_be_retried_after_explicit_approval(): void
    {
        [$row, $wallet] = $this->withdrawal(false);
        $payload = $this->payload($row);
        $this->assertFalse($this->webhookService()->processWebhookPayload($payload)->success);
        app(WithdrawalApprovalService::class)->approve($row);
        $this->assertTrue($this->webhookService()->processWebhookPayload($payload)->success);
        $this->assertSame('600.00', (string) $wallet->fresh()->balance);
        $this->assertSame(1, FinancialTransaction::query()->count());
    }

    public function test_bound_refund_word_cannot_recredit_a_completed_external_withdrawal(): void
    {
        [$row, $wallet] = $this->withdrawal();
        $this->assertTrue($this->webhookService()->processWebhookPayload($this->payload($row))->success);
        $before = $this->snapshot($row->fresh(), $wallet->fresh());
        $changes = ['eventType' => WebhookEventType::ChargeRefunded, 'isSuccess' => false];
        foreach (['refund-one', 'refund-one', 'refund-two'] as $event) {
            $result = $this->webhookService()->processWebhookPayload($this->payload($row->fresh(), $changes + ['eventId' => $event]));
            $this->assertFalse($result->success);
            $this->assertSame('failed', $result->actionTaken);
            $this->assertSame($before, $this->snapshot($row->fresh(), $wallet->fresh()));
        }
        $this->assertSame('600.00', (string) $wallet->fresh()->balance);
        $this->assertSame(WithdrawalStatus::Completed, $row->fresh()->status);
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame(2, LedgerEntry::query()->count());
    }

    public function test_signed_bkash_callback_cannot_credit_a_stripe_deposit(): void
    {
        $player = $this->createPlayer('100.00', Currency::BDT);
        $row = $this->createPendingDeposit($player['wallet'], '400.00', PaymentMethod::Stripe);
        $body = json_encode(['paymentID' => 'synthetic-payment', 'trxID' => 'synthetic-trx',
            'merchantInvoiceNumber' => $row->reference_number, 'amount' => '400.00', 'currency' => 'BDT',
            'transactionStatus' => 'COMPLETED'], JSON_THROW_ON_ERROR);
        $request = Request::create('/webhook/bkash', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $request->headers->set('X-Bkash-Signature', hash_hmac('sha256', $body, self::BKASH_WEBHOOK_SECRET));
        $this->assertTrue($this->gatewayManager()->driver('bkash')->verifyWebhookSignature($request));
        $before = $this->snapshot($row, $player['wallet']);
        $this->assertFalse($this->webhookService()->handleWebhookRequest('bkash', $request)->success);
        $this->assertSame($before, $this->snapshot($row, $player['wallet']));
    }
}
