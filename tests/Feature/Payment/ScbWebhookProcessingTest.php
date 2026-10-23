<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\WalletStatus;
use App\Enums\WalletType;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Broadcast\RealtimeSyncService;
use App\Services\Deposit\DepositProcessingService;
use App\Services\Fraud\FraudDetectionService;
use App\Services\Fraud\FraudEventRecorder;
use App\Services\Fraud\FraudRuleEngine;
use App\Services\Ledger\DoubleEntryLedgerService;
use App\Services\Lock\DistributedLockService;
use App\Services\Payment\ScbOpenApiWebhookService;
use App\Services\Wallet\WalletMutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ScbWebhookProcessingTest extends TestCase
{
    use RefreshDatabase;

    private ScbOpenApiWebhookService $webhookService;

    private DoubleEntryLedgerService $ledgerService;

    private WalletMutationService $walletService;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.scb.webhook_secret' => 'test-secret-key-123']);

        $lockService = Mockery::mock(DistributedLockService::class);
        $lockService->shouldReceive('acquireLock')->andReturn('mock-lock-handle');
        $lockService->shouldReceive('releaseLock')->andReturn(true);
        $lockService->shouldReceive('runWithLock')->andReturnUsing(fn ($k, $fn) => $fn());

        $realtime = Mockery::mock(RealtimeSyncService::class);
        $realtime->shouldReceive('broadcastWalletUpdate')->andReturnNull();
        $realtime->shouldReceive('syncWalletBalance')->andReturnNull();
        $this->app->instance(RealtimeSyncService::class, $realtime);

        $this->ledgerService = new DoubleEntryLedgerService;
        $this->walletService = new WalletMutationService($this->ledgerService, $lockService);

        $depositService = new DepositProcessingService(
            $lockService,
            $this->walletService,
            $this->ledgerService,
            $realtime
        );

        $fraudService = new FraudDetectionService(
            new FraudRuleEngine([]),
            new FraudEventRecorder
        );

        $this->webhookService = new ScbOpenApiWebhookService(
            $depositService,
            $fraudService,
            $lockService
        );
    }

    public function test_processes_valid_scb_webhook_and_credits_ledger_and_wallet(): void
    {
        $user = User::factory()->create();
        $userId = (string) $user->id;
        $wallet = Wallet::factory()->for($user)->create([
            'currency' => Currency::THB,
            'type' => WalletType::Primary,
            'balance' => '0.00',
            'locked_balance' => '0.00',
            'status' => WalletStatus::Active,
        ]);

        $payload = [
            'transactionId' => 'SCB-TX-99887711',
            'billerId' => '0105558099881',
            'amount' => 500.00,
            'billPaymentRef1' => $userId,
            'billPaymentRef2' => 'THAI-LOTTO',
        ];

        $rawJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        $signature = hash_hmac('sha256', $rawJson, 'test-secret-key-123');

        $result = $this->webhookService->processWebhook($payload, $signature, '203.0.113.10');

        $this->assertTrue($result['success']);
        $this->assertSame('COMPLETED', $result['status']);
        $this->assertSame('SCB-TX-99887711', $result['trans_ref']);

        $wallet->refresh();
        $this->assertSame('500.0000', number_format((float) $wallet->balance, 4, '.', ''));

        $this->assertDatabaseHas('deposit_transactions', [
            'user_id' => $userId,
            'gateway_reference_id' => 'SCB-TX-99887711',
            'status' => 'COMPLETED',
        ]);
    }

    public function test_rejects_invalid_scb_signature(): void
    {
        $payload = [
            'transactionId' => 'SCB-TX-INVALID-SIG',
            'amount' => 1000.00,
            'billPaymentRef1' => 'user-attacker',
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid SCB signature.');

        $this->webhookService->processWebhook($payload, 'invalid-signature-hash', '203.0.113.10');
    }
}
