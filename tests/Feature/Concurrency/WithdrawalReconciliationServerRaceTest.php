<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\DTOs\Payment\WithdrawalReconciliationFacts;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\WithdrawalStatus;
use App\Models\AuditLog;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\WithdrawalReconciliation;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\WithdrawalDisbursementService;
use App\Services\Payment\WithdrawalReconciliationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Payment\PaymentTestCase;
use Tests\Support\RunsServerFinancialRaces;

final class WithdrawalReconciliationServerRaceTest extends PaymentTestCase
{
    use RunsServerFinancialRaces;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireServerRace();
    }

    private function fixture(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $maker->assignRole('admin');
        $checker->assignRole('admin');
        $player = $this->createPlayer('1000.00', Currency::THB);
        $row = app(WithdrawalService::class)->request($player['wallet'], Money::of('400.00', Currency::THB), PaymentMethod::BankTransfer,
            options: ['payout_details' => ['account_name' => 'SYNTHETIC ONLY', 'account_number' => 'NOT-PAYABLE']]);
        app(WithdrawalApprovalService::class)->approve($row);
        $driver = $this->createMock(PaymentGatewayInterface::class);
        $driver->method('name')->willReturn('bank_transfer');
        $driver->method('supportsCurrency')->willReturn(true);
        $driver->expects($this->once())->method('initiateWithdrawal')->willReturn(GatewayWithdrawalResponse::uncertain());
        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('withdrawalDriver')->willReturn($driver);
        $this->app->instance(PaymentGatewayManager::class, $manager);
        app(WithdrawalDisbursementService::class)->disburse($row);
        $facts = ['internal_reference' => $row->reference_number, 'provider' => 'bank_transfer', 'provider_reference' => 'RACE-SYNTHETIC-'.$row->id,
            'currency' => 'THB', 'gross_amount' => '400.00', 'net_amount' => (string) $row->net_amount,
            'outcome' => 'completed', 'observed_at' => now()->toIso8601String()];
        $file = UploadedFile::fake()->createWithContent('proof.txt', 'RACE SYNTHETIC FINAL PROVIDER EVIDENCE');
        $case = app(WithdrawalReconciliationService::class)->request($maker, $row->id, WithdrawalReconciliationFacts::fromArray($facts), $file);

        return [$row, $player['wallet'], $maker, $checker, $case, $facts, $file];
    }

    private function command($case, $checker): array
    {
        return ['action' => 'reconcile', 'case_id' => (int) $case->id, 'checker_id' => (int) $checker->id];
    }

    public function test_concurrent_duplicate_review_debits_exactly_once(): void
    {
        [$row, $wallet, $maker, $checker, $case] = $this->fixture();
        $out = $this->serverRace(array_fill(0, 2, $this->command($case, $checker)), 'wallets', (int) $wallet->id);
        $this->assertSame(['applied', 'applied'], array_column($out, 'outcome'), json_encode($out));
        $this->assertSame(WithdrawalStatus::Completed, $row->fresh()->status);
        $this->assertSame('600.00', (string) $wallet->fresh()->balance);
        $this->assertSame('0.00', (string) $wallet->fresh()->locked_balance);
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertGreaterThanOrEqual(2, LedgerEntry::query()->count());
        $this->assertSame(2, AuditLog::query()->where('auditable_type', WithdrawalReconciliation::class)->count());
    }

    public function test_concurrent_conflicting_provider_evidence_has_one_terminal_result(): void
    {
        [$row, $wallet, $maker, $checker, $paid, $facts, $file] = $this->fixture();
        $facts['outcome'] = 'definitively_rejected';
        $failed = app(WithdrawalReconciliationService::class)->request($maker, $row->id, WithdrawalReconciliationFacts::fromArray($facts), $file);
        $out = $this->serverRace([$this->command($paid, $checker), $this->command($failed, $checker)], 'wallets', (int) $wallet->id);
        $states = array_column($out, 'outcome');
        sort($states);
        $this->assertSame(['applied', 'refused'], $states, json_encode($out));
        $completed = $row->fresh()->status === WithdrawalStatus::Completed;
        $this->assertContains($row->fresh()->status, [WithdrawalStatus::Completed, WithdrawalStatus::Failed]);
        $this->assertSame($completed ? '600.00' : '1000.00', (string) $wallet->fresh()->balance);
        $this->assertSame('0.00', (string) $wallet->fresh()->locked_balance);
        $this->assertSame($completed ? 1 : 0, FinancialTransaction::query()->count());
        $this->assertSame(1, WithdrawalReconciliation::query()->where('status', 'applied')->count());
        $this->assertSame(1, WithdrawalReconciliation::query()->where('status', 'pending')->count());
        $this->assertSame(3, AuditLog::query()->where('auditable_type', WithdrawalReconciliation::class)->count());
    }
}
