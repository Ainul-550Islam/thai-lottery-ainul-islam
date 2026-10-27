<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\DTOs\Payment\WithdrawalReconciliationFacts;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\WithdrawalStatus;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\WithdrawalDisbursementService;
use App\Services\Payment\WithdrawalProviderReferenceService;
use App\Services\Payment\WithdrawalReconciliationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Payment\PaymentTestCase;
use Tests\Support\RunsServerFinancialRaces;

/** Different wallets, independent processes, same owned shared-reference lock. Synthetic facts only. */
final class WithdrawalReferenceServerRaceTest extends PaymentTestCase
{
    use RunsServerFinancialRaces;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireServerRace();
    }

    private function row()
    {
        $p = $this->createPlayer('1000.00', Currency::THB);
        $row = app(WithdrawalService::class)->request($p['wallet'], Money::of('400.00', Currency::THB), PaymentMethod::BankTransfer,
            options: ['payout_details' => ['account_name' => 'SYNTHETIC ONLY', 'account_number' => 'NOT-PAYABLE']]);
        app(WithdrawalApprovalService::class)->approve($row);

        return $row->fresh();
    }

    private function callbackCommand($row, string $reference): array
    {
        return ['action' => 'webhook', 'webhook' => ['gateway' => 'bank_transfer', 'event' => 'SYNTHETIC-REF-'.bin2hex(random_bytes(8)),
            'type' => 'withdrawal.success', 'provider' => $reference, 'internal' => $row->reference_number,
            'amount' => '400.00', 'currency' => 'THB', 'success' => true]];
    }

    public function test_concurrent_callback_and_reconciliation_share_owner_across_wallets(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $maker->assignRole('admin');
        $checker->assignRole('admin');
        $owner = $this->row();
        $foreign = $this->row();
        $this->assertNotSame($owner->wallet_id, $foreign->wallet_id);
        $driver = $this->createMock(PaymentGatewayInterface::class);
        $driver->method('name')->willReturn('bank_transfer');
        $driver->method('supportsCurrency')->willReturn(true);
        $driver->expects($this->once())->method('initiateWithdrawal')->willReturn(GatewayWithdrawalResponse::uncertain());
        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('withdrawalDriver')->willReturn($driver);
        $this->app->instance(PaymentGatewayManager::class, $manager);
        app(WithdrawalDisbursementService::class)->disburse($foreign);
        $foreign->refresh();
        $reference = 'SYNTHETIC-CROSS-LANE-REF';
        $case = app(WithdrawalReconciliationService::class)->request($maker, $foreign->id, WithdrawalReconciliationFacts::fromArray([
            'internal_reference' => $foreign->reference_number, 'provider' => 'bank_transfer', 'provider_reference' => $reference,
            'currency' => 'THB', 'gross_amount' => '400.00', 'net_amount' => (string) $foreign->net_amount,
            'outcome' => 'completed', 'observed_at' => now()->toIso8601String(),
        ]), UploadedFile::fake()->createWithContent('proof.txt', 'SYNTHETIC CROSS-LANE EVIDENCE, NOT A TRANSFER'));
        $id = DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($owner, 'bank_transfer', $reference));
        $out = $this->serverRace([$this->callbackCommand($owner, $reference),
            ['action' => 'reconcile', 'case_id' => (int) $case->id, 'checker_id' => (int) $checker->id]], 'withdrawal_provider_references', $id);
        $this->assertSame(['debited', 'refused'], array_column($out, 'outcome'), json_encode($out));
        $this->assertSame(WithdrawalStatus::Completed, $owner->fresh()->status);
        $this->assertSame(WithdrawalStatus::Processing, $foreign->fresh()->status);
        $this->assertSame('600.00', (string) $owner->wallet->fresh()->balance);
        $this->assertSame('1000.00', (string) $foreign->wallet->fresh()->balance);
        $this->assertSame('400.00', (string) $foreign->wallet->fresh()->locked_balance);
        $this->assertSame('pending', $case->fresh()->status);
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame(1, DB::table('withdrawal_provider_references')->count());
    }

    public function test_concurrent_dispatch_and_callback_share_owner_across_wallets(): void
    {
        $owner = $this->row();
        $foreign = $this->row();
        $this->assertNotSame($owner->wallet_id, $foreign->wallet_id);
        $reference = 'SYNTHETIC-DISPATCH-'.$owner->id;
        $id = DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($owner, 'bank_transfer', $reference));
        $out = $this->serverRace([['action' => 'dispatch_accepted', 'withdrawal_id' => (int) $owner->id, 'mode' => 'pending'],
            $this->callbackCommand($foreign, $reference)], 'withdrawal_provider_references', $id);
        $this->assertSame(['dispatched', 'failed'], array_column($out, 'outcome'), json_encode($out));
        $this->assertSame(1, $out[0]['synthetic_calls']);
        $this->assertTrue($out[0]['pending']);
        $this->assertFalse($out[1]['success']);
        $this->assertSame(WithdrawalStatus::Processing, $owner->fresh()->status);
        $this->assertSame(WithdrawalStatus::Approved, $foreign->fresh()->status);
        $this->assertSame('1000.00', (string) $owner->wallet->fresh()->balance);
        $this->assertSame('1000.00', (string) $foreign->wallet->fresh()->balance);
        $this->assertSame('400.00', (string) $owner->wallet->fresh()->locked_balance);
        $this->assertSame('400.00', (string) $foreign->wallet->fresh()->locked_balance);
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertSame(1, DB::table('withdrawal_provider_references')->count());
    }
}
