<?php

declare(strict_types=1);

namespace Tests\Unit\Ledger;

use App\Models\DepositTransaction;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Services\Ledger\AutomatedBankReconciliationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutomatedBankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private AutomatedBankReconciliationService $reconciliationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reconciliationService = new AutomatedBankReconciliationService;
    }

    public function test_reconciles_matched_deposits_and_payouts_with_zero_variance(): void
    {
        $date = Carbon::create(2026, 10, 16, 12, 0, 0);

        // 1. Create a confirmed bank deposit of 1,000 THB
        $depTx = DepositTransaction::create([
            'id' => (string) Str::uuid(),
            'user_id' => 'user-recon-1',
            'deposit_number' => 'DEP-20261016-001',
            'gateway_provider' => 'SCB_PROMPTPAY',
            'gateway_reference_id' => 'SCB-RECON-1',
            'amount' => '1000.0000',
            'fee_amount' => '0.0000',
            'net_amount' => '1000.0000',
            'currency' => 'THB',
            'status' => 'COMPLETED',
            'completed_at' => $date,
        ]);

        // 2. Create the corresponding double-entry ledger transaction
        $ledgerTx = LedgerTransaction::create([
            'id' => (string) Str::uuid(),
            'transaction_number' => 'TXN-20261016-001',
            'reference_type' => 'DEPOSIT_SETTLEMENT',
            'reference_id' => $depTx->id,
            'currency' => 'THB',
            'status' => 'POSTED',
            'posted_at' => $date,
        ]);

        LedgerEntry::create([
            'id' => (string) Str::uuid(),
            'ledger_transaction_id' => $ledgerTx->id,
            'account_id' => (string) Str::uuid(),
            'direction' => 'DEBIT',
            'amount' => '1000.0000',
            'currency' => 'THB',
        ]);

        $result = $this->reconciliationService->reconcileDate($date);

        $this->assertTrue($result['is_balanced']);
        $this->assertSame('1000.0000', $result['bank_deposits_total']);
        $this->assertSame('1000.0000', $result['ledger_deposits_total']);
        $this->assertSame('0.0000', $result['deposits_variance']);
    }
}
