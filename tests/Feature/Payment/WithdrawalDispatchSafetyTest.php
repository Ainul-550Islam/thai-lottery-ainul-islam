<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\WebhookEventType;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Finance\FinancialStateTransitionService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletHoldService;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\WithdrawalDisbursementService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** A transport exception or duplicate delivery is never evidence of a rejected transfer. */
final class WithdrawalDispatchSafetyTest extends PaymentTestCase
{
    private int $calls = 0;

    private array $sentOptions = [];

    private function fixture(Currency $currency = Currency::THB): array
    {
        $player = $this->createPlayer('1000.00', $currency);
        $row = app(WithdrawalService::class)->request($player['wallet'], Money::of('400.00', $currency), PaymentMethod::BankTransfer,
            options: ['payout_details' => ['bank_name' => 'TEST ONLY', 'account_number' => 'NOT-PAYABLE', 'account_name' => 'TEST ONLY']]);
        app(WithdrawalApprovalService::class)->approve($row);

        return [$row->fresh(), $player['wallet']->fresh()];
    }

    private function sender(callable $respond): WithdrawalDisbursementService
    {
        $driver = $this->createMock(PaymentGatewayInterface::class);
        $driver->method('name')->willReturn('bank_transfer');
        $driver->method('supportsCurrency')->willReturn(true);
        $driver->method('initiateWithdrawal')->willReturnCallback(function ($row, array $options) use ($respond) {
            $this->calls++;
            $this->sentOptions[] = $options;

            return $respond($row, $options);
        });
        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('withdrawalDriver')->willReturn($driver);

        return new WithdrawalDisbursementService($manager, app(WalletLockService::class), app(WalletHoldService::class),
            app(FinancialStateTransitionService::class), app(WithdrawalApprovalService::class), app(WithdrawalCompletionService::class));
    }

    private function assertHeld($row, $wallet): void
    {
        $this->assertSame(WithdrawalStatus::Processing, $row->fresh()->status);
        $this->assertSame('1000.00', (string) $wallet->fresh()->balance);
        $this->assertSame('400.00', (string) $wallet->fresh()->locked_balance);
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertSame(PaymentStatus::Pending, Payment::query()->sole()->status);
    }

    public function test_transport_timeout_preserves_reservation_and_reports_uncertainty(): void
    {
        [$row, $wallet] = $this->fixture();
        $sender = $this->sender(fn () => throw new \RuntimeException('synthetic-private-token:do-not-log'));
        $response = $sender->disburse($row);
        $this->assertFalse($response->successful);
        $this->assertTrue($response->isPending);
        $this->assertHeld($row, $wallet);
        $this->assertStringNotContainsString('synthetic-private-token', json_encode([$response->toArray(), Payment::query()->sole()->toArray(), $row->fresh()->toArray()], JSON_THROW_ON_ERROR));
        $sender->disburse($row->fresh());
        $this->assertSame(1, $this->calls);
    }

    public function test_duplicate_pending_delivery_does_not_send_twice(): void
    {
        [$row, $wallet] = $this->fixture();
        $sender = $this->sender(fn () => GatewayWithdrawalResponse::pending('synthetic-pending'));
        $this->assertTrue($sender->disburse($row)->isPending);
        $this->assertTrue($sender->disburse($row->fresh())->isPending);
        $this->assertSame(1, $this->calls);
        $this->assertHeld($row, $wallet);
    }

    public function test_reentrant_delivery_during_external_call_cannot_send_again(): void
    {
        [$row, $wallet] = $this->fixture();
        $sender = null;
        $sender = $this->sender(function ($sent) use (&$sender) {
            $this->assertSame(0, DB::transactionLevel());
            if ($this->calls === 1) {
                $other = $sender->disburse($sent->fresh());
                $this->assertTrue($other->isPending);
                $this->assertFalse($other->successful);
            }

            return GatewayWithdrawalResponse::pending('synthetic-pending');
        });
        $sender->disburse($row);
        $this->assertSame(1, $this->calls);
        $this->assertHeld($row, $wallet);
    }

    public function test_sender_refuses_an_outer_transaction_before_any_external_call(): void
    {
        [$row] = $this->fixture();
        $sender = $this->sender(fn () => GatewayWithdrawalResponse::pending('synthetic-pending'));
        $refused = false;
        DB::beginTransaction();
        try {
            $sender->disburse($row);
        } catch (FinancialException) {
            $refused = true;
        } finally {
            DB::rollBack();
        }
        $this->assertTrue($refused);
        $this->assertSame(0, $this->calls);
    }

    public function test_legacy_processing_without_a_claim_cannot_be_automatically_resent(): void
    {
        [$row, $wallet] = $this->fixture();
        app(WithdrawalApprovalService::class)->markProcessing($row);
        $sender = $this->sender(fn () => GatewayWithdrawalResponse::pending('synthetic-pending'));
        $response = $sender->disburse($row->fresh());
        $this->assertFalse($response->successful);
        $this->assertTrue($response->isPending);
        $this->assertSame(0, $this->calls);
        $this->assertSame('400.00', (string) $wallet->fresh()->locked_balance);
    }

    public function test_pending_unsuccessful_response_is_not_a_definitive_rejection(): void
    {
        [$row, $wallet] = $this->fixture();
        $sender = $this->sender(fn () => new GatewayWithdrawalResponse(successful: false, isPending: true));
        $response = $sender->disburse($row);
        $this->assertTrue($response->isPending);
        $this->assertHeld($row, $wallet);
    }

    public function test_success_without_a_provider_reference_does_not_debit(): void
    {
        [$row, $wallet] = $this->fixture();
        $sender = $this->sender(fn () => GatewayWithdrawalResponse::completed(''));
        $response = $sender->disburse($row);
        $this->assertFalse($response->successful);
        $this->assertTrue($response->isPending);
        $this->assertHeld($row, $wallet);
    }

    public static function incoherentFacts(): array
    {
        return ['owner' => ['owner'], 'currency' => ['currency'], 'net' => ['net'], 'payment' => ['payment']];
    }

    #[DataProvider('incoherentFacts')]
    public function test_incoherent_obligation_is_refused_before_sending(string $fork): void
    {
        [$row] = $this->fixture();
        if ($fork === 'owner') {
            $row->update(['user_id' => User::factory()->create()->id]);
        } elseif ($fork === 'currency') {
            app(WithdrawalApprovalService::class)->markProcessing($row);
            $row->update(['currency' => Currency::USD]);
        } elseif ($fork === 'net') {
            $row->update(['net_amount' => '999.00']);
        } else {
            Payment::factory()->create(['user_id' => $row->user_id, 'payable_type' => $row::class, 'payable_id' => $row->id,
                'amount' => '400.00', 'fee' => (string) $row->fee, 'currency' => Currency::THB, 'gateway' => 'stripe', 'method' => PaymentMethod::Stripe]);
        }
        $sender = $this->sender(fn () => GatewayWithdrawalResponse::pending('synthetic-pending'));
        $refused = false;
        try {
            $sender->disburse($row->fresh());
        } catch (FinancialException) {
            $refused = true;
        }
        $this->assertTrue($refused);
        $this->assertSame(0, $this->calls);
    }

    public function test_server_owned_key_is_persisted_and_cannot_be_overridden_by_options(): void
    {
        [$row] = $this->fixture();
        $sender = $this->sender(fn () => GatewayWithdrawalResponse::pending('synthetic-pending'));
        $sender->disburse($row, ['idempotency_key' => 'caller-must-not-control-this']);
        $key = $this->sentOptions[0]['idempotency_key'] ?? '';
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $key);
        $this->assertSame($key, $row->fresh()->metadata['outbound_dispatch']['key']);
    }

    public function test_late_failure_cannot_misreport_a_callback_confirmed_completion(): void
    {
        [$row, $wallet] = $this->fixture();
        $sender = $this->sender(function ($sent) {
            $callback = $this->webhookService()->processWebhookPayload(new WebhookPayload(
                gateway: 'bank_transfer', eventId: 'synthetic-early-callback', eventType: WebhookEventType::WithdrawalSuccess,
                providerReference: 'synthetic-confirmed', internalReference: $sent->reference_number,
                amount: '400.00', currency: Currency::THB, isSuccess: true,
            ));
            $this->assertTrue($callback->success);

            return GatewayWithdrawalResponse::failed('late response must not override the confirmed callback');
        });
        $this->assertTrue($sender->disburse($row)->successful);
        $this->assertSame(WithdrawalStatus::Completed, $row->fresh()->status);
        $this->assertSame('600.00', (string) $wallet->fresh()->balance);
        $this->assertSame(PaymentStatus::Captured, Payment::query()->sole()->status);
    }

    public function test_result_persistence_failure_keeps_the_committed_claim_and_never_resends(): void
    {
        [$row, $wallet] = $this->fixture();
        $armed = true;
        Withdrawal::saving(function ($model) use (&$armed): void {
            if ($armed && ($model->metadata['outbound_dispatch']['state'] ?? null) === 'completed') {
                $armed = false;
                throw new \RuntimeException('Synthetic result persistence failure');
            }
        });
        $sender = $this->sender(fn () => GatewayWithdrawalResponse::completed('synthetic-confirmed'));
        try {
            $sender->disburse($row);
            $this->fail('The result transaction must have failed.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic result persistence failure', $error->getMessage());
        }
        $this->assertSame('sending', $row->fresh()->metadata['outbound_dispatch']['state']);
        $this->assertTrue($sender->disburse($row->fresh())->outcomeUnknown);
        $this->assertSame(1, $this->calls);
        $this->assertHeld($row, $wallet);
    }

    public function test_a_stale_claim_never_expires_into_permission_to_send_again(): void
    {
        [$row, $wallet] = $this->fixture();
        $sender = $this->sender(fn () => throw new \RuntimeException('Synthetic transport timeout'));
        $this->assertTrue($sender->disburse($row)->outcomeUnknown);
        $this->travel(2)->days();
        try {
            $this->assertTrue($sender->disburse($row->fresh(), ['force' => true, 'retry' => true])->outcomeUnknown);
            $this->assertSame(1, $this->calls);
            $this->assertHeld($row, $wallet);
        } finally {
            $this->travelBack();
        }
    }

    public function test_bound_callback_can_reconcile_an_unknown_result_without_sending_again(): void
    {
        [$row, $wallet] = $this->fixture();
        $sender = $this->sender(fn () => throw new \RuntimeException('Synthetic transport timeout'));
        $this->assertTrue($sender->disburse($row)->outcomeUnknown);
        $result = $this->webhookService()->processWebhookPayload(new WebhookPayload(
            gateway: 'bank_transfer', eventId: 'synthetic-reconciliation', eventType: WebhookEventType::WithdrawalSuccess,
            providerReference: 'synthetic-confirmed', internalReference: $row->reference_number,
            amount: '400.00', currency: Currency::THB, isSuccess: true,
        ));
        $this->assertTrue($result->success);
        $this->assertSame('600.00', (string) $wallet->fresh()->balance);
        $this->assertSame('0.00', (string) $wallet->fresh()->locked_balance);
        $this->assertSame(1, $this->calls);
    }

    public function test_destination_mutation_after_claim_is_refused_and_not_sent_again(): void
    {
        [$row] = $this->fixture();
        $sender = $this->sender(fn () => GatewayWithdrawalResponse::pending('synthetic-pending'));
        $sender->disburse($row);
        $row->fresh()->update(['payout_details' => ['account_number' => 'DIFFERENT-TEST-DESTINATION']]);
        $refused = false;
        try {
            $sender->disburse($row->fresh());
        } catch (FinancialException) {
            $refused = true;
        }
        $this->assertTrue($refused);
        $this->assertSame(1, $this->calls);
    }

    public static function currencies(): array
    {
        return [[Currency::THB], [Currency::USD], [Currency::BDT]];
    }

    #[DataProvider('currencies')]
    public function test_definitive_success_debits_once_in_the_wallet_currency(Currency $currency): void
    {
        [$row, $wallet] = $this->fixture($currency);
        $sender = $this->sender(fn () => GatewayWithdrawalResponse::completed('synthetic-confirmed'));
        $this->assertTrue($sender->disburse($row)->successful);
        $this->assertSame('600.00', (string) $wallet->fresh()->balance);
        $this->assertSame('0.00', (string) $wallet->fresh()->locked_balance);
        $this->assertSame($currency, FinancialTransaction::query()->sole()->currency);
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame(2, LedgerEntry::query()->count());
    }

    public function test_definitive_rejection_releases_hold_without_creating_refund_money(): void
    {
        [$row, $wallet] = $this->fixture();
        $sender = $this->sender(fn () => GatewayWithdrawalResponse::failed('Synthetic definitive rejection'));
        $this->assertFalse($sender->disburse($row)->successful);
        $this->assertSame(WithdrawalStatus::Failed, $row->fresh()->status);
        $this->assertSame('1000.00', (string) $wallet->fresh()->balance);
        $this->assertSame('0.00', (string) $wallet->fresh()->locked_balance);
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
    }
}
