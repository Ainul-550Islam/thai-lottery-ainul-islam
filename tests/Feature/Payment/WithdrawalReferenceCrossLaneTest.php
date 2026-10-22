<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\DTOs\Payment\WebhookPayload;
use App\DTOs\Payment\WithdrawalReconciliationFacts;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\WebhookEventType;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\WithdrawalDisbursementService;
use App\Services\Payment\WithdrawalReconciliationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;

final class WithdrawalReferenceCrossLaneTest extends PaymentTestCase
{
    private function withdrawal(array $options = []): array
    {
        $p = $this->createPlayer('1000.00', Currency::THB);
        $row = app(WithdrawalService::class)->request($p['wallet'], Money::of('400.00', Currency::THB), PaymentMethod::BankTransfer,
            options: array_replace(['payout_details' => ['account_name' => 'SYNTHETIC ONLY', 'account_number' => 'NOT-PAYABLE']], $options));
        app(WithdrawalApprovalService::class)->approve($row);

        return [$row->fresh(), $p['wallet']];
    }

    private function send($row, GatewayWithdrawalResponse $response, array $options = []): void
    {
        $driver = $this->createMock(PaymentGatewayInterface::class);
        $driver->method('name')->willReturn('bank_transfer');
        $driver->method('supportsCurrency')->willReturn(true);
        $driver->expects($this->once())->method('initiateWithdrawal')->willReturnCallback(function ($sent, $actual) use ($row, $response) {
            $this->assertSame($row->metadata['outbound_provider_account'], $actual['account_scope']);

            return $response;
        });
        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('withdrawalDriver')->willReturn($driver);
        $this->app->instance(PaymentGatewayManager::class, $manager);
        app(WithdrawalDisbursementService::class)->disburse($row, $options);
    }

    private function providerCallback($row, string $reference, bool $success = true): bool
    {
        try {
            return $this->webhookService()->processWebhookPayload(new WebhookPayload(
                gateway: 'bank_transfer', eventId: 'SYNTHETIC-EVENT-'.bin2hex(random_bytes(6)),
                eventType: $success ? WebhookEventType::WithdrawalSuccess : WebhookEventType::WithdrawalFailed,
                providerReference: $reference, internalReference: $row->reference_number,
                amount: '400.00', currency: Currency::THB, isSuccess: $success,
            ))->success;
        } catch (FinancialException) {
            return false;
        }
    }

    public function test_two_callback_obligations_cannot_share_one_external_transfer(): void
    {
        [$a] = $this->withdrawal();
        [$b, $wallet] = $this->withdrawal();
        $this->assertTrue($this->providerCallback($a, 'SYNTHETIC-SHARED-REF'));
        $this->assertFalse($this->providerCallback($b, 'SYNTHETIC-SHARED-REF'));
        $this->assertSame(WithdrawalStatus::Approved, $b->fresh()->status);
        $this->assertSame('1000.00', (string) $wallet->fresh()->balance);
        $this->assertSame('400.00', (string) $wallet->fresh()->locked_balance);
        $this->assertSame(1, FinancialTransaction::query()->count());
    }

    public function test_pending_dispatch_reference_cannot_complete_another_withdrawal(): void
    {
        [$a] = $this->withdrawal();
        [$b, $wallet] = $this->withdrawal();
        $this->send($a, GatewayWithdrawalResponse::pending('SYNTHETIC-PENDING-OWNER'));
        $this->assertFalse($this->providerCallback($b, 'SYNTHETIC-PENDING-OWNER'));
        $this->assertSame('400.00', (string) $wallet->fresh()->locked_balance);
        $this->assertSame(0, FinancialTransaction::query()->count());
    }

    public function test_failed_callback_reference_cannot_release_another_hold(): void
    {
        [$a] = $this->withdrawal();
        [$b, $wallet] = $this->withdrawal();
        $this->assertTrue($this->providerCallback($a, 'SYNTHETIC-ORIGINAL-COMPLETION'));
        $this->assertFalse($this->providerCallback($b, 'SYNTHETIC-ORIGINAL-COMPLETION', false));
        $this->assertSame(WithdrawalStatus::Approved, $b->fresh()->status);
        $this->assertSame('400.00', (string) $wallet->fresh()->locked_balance);
    }

    public function test_manual_completion_cannot_reuse_a_callback_reference(): void
    {
        [$a] = $this->withdrawal();
        [$b, $wallet] = $this->withdrawal();
        $this->assertTrue($this->providerCallback($a, 'SYNTHETIC-CANONICAL-REF'));
        $this->expectException(FinancialException::class);
        app(WithdrawalCompletionService::class)->complete($b, options: ['provider' => 'bank_transfer', 'provider_reference' => 'SYNTHETIC-CANONICAL-REF']);
    }

    public function test_reconciliation_completion_is_reserved_against_other_callbacks(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $maker->assignRole('admin');
        $checker->assignRole('admin');
        [$a] = $this->withdrawal();
        [$b, $wallet] = $this->withdrawal();
        $this->send($a, GatewayWithdrawalResponse::uncertain());
        $a->refresh();
        $case = app(WithdrawalReconciliationService::class)->request($maker, $a->id, WithdrawalReconciliationFacts::fromArray([
            'internal_reference' => $a->reference_number, 'provider' => 'bank_transfer', 'provider_reference' => 'SYNTHETIC-HUMAN-REF',
            'currency' => 'THB', 'gross_amount' => '400.00', 'net_amount' => (string) $a->net_amount,
            'outcome' => 'completed', 'observed_at' => now()->toIso8601String(),
        ]), UploadedFile::fake()->createWithContent('evidence.txt', 'SYNTHETIC HUMAN FINAL PROVIDER EVIDENCE'));
        app(WithdrawalReconciliationService::class)->decide($checker, $case, 'apply', 'Independently verified original synthetic bank evidence.', true);
        $this->assertFalse($this->providerCallback($b, 'SYNTHETIC-HUMAN-REF'));
        $this->assertSame('400.00', (string) $wallet->fresh()->locked_balance);
        $this->assertSame(1, FinancialTransaction::query()->count());
    }

    public function test_operator_account_switch_cannot_reinterpret_an_old_obligation(): void
    {
        [$row, $wallet] = $this->withdrawal();
        config(['payment.withdrawal_reference_accounts.bank_transfer' => 'NEW-MERCHANT-ACCOUNT']);
        $this->assertFalse($this->providerCallback($row, 'SYNTHETIC-FOREIGN-ACCOUNT'));
        $this->assertSame('400.00', (string) $wallet->fresh()->locked_balance);
        $this->assertSame(0, FinancialTransaction::query()->count());
    }

    public function test_client_metadata_cannot_choose_or_fabricate_provider_context(): void
    {
        config(['payment.withdrawal_reference_accounts.bank_transfer' => 'OPERATOR-APPROVED-ACCOUNT']);
        [$row] = $this->withdrawal(['metadata' => ['outbound_provider_account' => 'CALLER-CHOSEN', 'outbound_dispatch' => ['state' => 'completed']]]);
        $this->assertSame('OPERATOR-APPROVED-ACCOUNT', $row->metadata['outbound_provider_account'] ?? null);
        $this->assertArrayNotHasKey('outbound_dispatch', $row->metadata);
    }

    public function test_uncertain_acknowledgement_with_known_reference_reserves_identity_but_not_money_finality(): void
    {
        [$a, $ownerWallet] = $this->withdrawal();
        [$b, $foreignWallet] = $this->withdrawal();
        $this->send($a, GatewayWithdrawalResponse::uncertain('SYNTHETIC-UNCERTAIN-KNOWN-REF'));
        $this->assertSame(WithdrawalStatus::Processing, $a->fresh()->status);
        $this->assertSame('unknown', $a->fresh()->metadata['outbound_dispatch']['state']);
        $this->assertSame('SYNTHETIC-UNCERTAIN-KNOWN-REF', $a->fresh()->provider_reference);
        $this->assertSame('400.00', (string) $ownerWallet->fresh()->locked_balance);
        $this->assertFalse($this->providerCallback($b, 'SYNTHETIC-UNCERTAIN-KNOWN-REF'));
        $this->assertSame('400.00', (string) $foreignWallet->fresh()->locked_balance);
        $this->assertSame(0, FinancialTransaction::query()->count());
    }

    public function test_driver_receives_only_the_server_owned_account_scope_option(): void
    {
        [$row] = $this->withdrawal();
        $this->send($row, GatewayWithdrawalResponse::pending('SYNTHETIC-ACCOUNT-OPTIONS'), ['account_scope' => 'CALLER-CANNOT-ROUTE-THIS']);
        $this->assertSame('legacy-primary', $row->fresh()->metadata['outbound_dispatch']['account_scope']);
    }
}
