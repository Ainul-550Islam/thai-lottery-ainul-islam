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

        // 2. Post the corresponding balanced double-entry ledger transaction (Debit == Credit)
        $ledgerService = new \App\Services\Ledger\DoubleEntryLedgerService;
        $bankAssetAccount = $ledgerService->getSystemAccount('SYS-BANK-SCB-THB', 'SCB Bank Clearing THB', 'asset', 'THB');
        $userLiabilityAccount = $ledgerService->getOrCreateUserAccount('user-recon-1', 'THB');

        $ledgerService->postTransaction(
            referenceType: 'DEPOSIT_SETTLEMENT',
            referenceId: (string) $depTx->id,
            currency: 'THB',
            entries: [
                [
                    'account_id' => $bankAssetAccount->id,
                    'direction' => 'DEBIT',
                    'amount' => '1000.0000',
                ],
                [
                    'account_id' => $userLiabilityAccount->id,
                    'direction' => 'CREDIT',
                    'amount' => '1000.0000',
                ],
            ],
            idempotencyKey: 'RECON-DEP-20261016-001',
            description: 'Deposit settlement SCB-RECON-1'
        );

        LedgerTransaction::where('reference_id', (string) $depTx->id)->update(['posted_at' => $date]);
        LedgerEntry::whereNotNull('ledger_transaction_id')->update(['posted_at' => $date]);

        $result = $this->reconciliationService->reconcileDate($date);

        // These are internal records, not an independently verified bank statement.
        $this->assertTrue($result['internal_aggregate_balanced']);
        $this->assertFalse($result['external_evidence_verified']);
        $this->assertFalse($result['is_balanced']);
        $this->assertSame('THB', $result['currency']);
        $this->assertSame('1000.0000', $result['recorded_deposits_total']);
        $this->assertSame('1000.0000', $result['ledger_deposits_total']);
        $this->assertSame('0.0000', $result['deposits_variance']);
    }

    public function test_mismatches_in_different_currencies_cannot_cancel_each_other(): void
    {
        $date = Carbon::create(2026, 10, 16, 12, 0, 0);
        DepositTransaction::create([
            'id' => (string) Str::uuid(), 'user_id' => 'user-recon-2',
            'deposit_number' => 'DEP-THB-UNMATCHED', 'gateway_provider' => 'TEST',
            'gateway_reference_id' => 'THB-ONLY', 'amount' => '100.0000',
            'fee_amount' => '0.0000', 'net_amount' => '100.0000', 'currency' => 'THB',
            'status' => 'COMPLETED', 'completed_at' => $date,
        ]);
        $ledger = app(\App\Services\Ledger\DoubleEntryLedgerService::class);
        $asset = $ledger->getSystemAccount('RECON-USD-ASSET', 'USD asset', 'asset', 'USD');
        $liability = $ledger->getSystemAccount('RECON-USD-LIABILITY', 'USD liability', 'liability', 'USD');
        $tx = $ledger->postTransaction('DEPOSIT_SETTLEMENT', 'usd-unmatched', 'USD', [
            ['account_id' => $asset->id, 'direction' => 'DEBIT', 'amount' => '100.00'],
            ['account_id' => $liability->id, 'direction' => 'CREDIT', 'amount' => '100.00'],
        ], 'recon-usd-unmatched');
        $tx->update(['posted_at' => $date]);

        $thb = $this->reconciliationService->reconcileDate($date, 'THB');
        $usd = $this->reconciliationService->reconcileDate($date, 'USD');
        $this->assertSame('100.0000', $thb['deposits_variance']);
        $this->assertSame('-100.0000', $usd['deposits_variance']);
        $this->assertFalse($thb['internal_aggregate_balanced']);
        $this->assertFalse($usd['internal_aggregate_balanced']);
        $this->assertFalse($thb['is_balanced']);
        $this->assertFalse($usd['is_balanced']);
    }

    public function test_cli_cannot_report_a_verified_bank_reconciliation_without_external_evidence(): void
    {
        $this->artisan('ledger:reconcile-bank', ['date' => '2026-10-16', '--currency' => 'BDT'])
            ->expectsOutputToContain('External bank evidence is not verified')
            ->assertExitCode(1);
    }
}
