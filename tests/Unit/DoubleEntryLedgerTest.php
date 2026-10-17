<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Services\Ledger\DoubleEntryLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class DoubleEntryLedgerTest extends TestCase
{
    use RefreshDatabase;

    private DoubleEntryLedgerService $ledgerService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerService = app(DoubleEntryLedgerService::class);
    }

    public function test_balanced_transaction_posts_successfully(): void
    {
        $assetAccount = $this->ledgerService->getSystemAccount('TEST-ASSET-01', 'Test Asset', 'ASSET');
        $liabilityAccount = $this->ledgerService->getSystemAccount('TEST-LIAB-01', 'Test Liability', 'LIABILITY');

        $tx = $this->ledgerService->postTransaction(
            referenceType: 'TEST_TRANSFER',
            referenceId: 'REF-12345',
            currency: 'THB',
            entries: [
                [
                    'account_id' => $assetAccount->id,
                    'direction' => 'DEBIT',
                    'amount' => '500.0000',
                ],
                [
                    'account_id' => $liabilityAccount->id,
                    'direction' => 'CREDIT',
                    'amount' => '500.0000',
                ],
            ],
            description: 'Balanced test transfer'
        );

        $this->assertDatabaseHas('ledger_transactions', [
            'id' => $tx->id,
            'total_amount' => '500.0000',
            'status' => 'POSTED',
        ]);

        $this->assertCount(2, $tx->entries);
        $this->assertEquals('500.0000', $assetAccount->fresh()->current_balance);
        $this->assertEquals('500.0000', $liabilityAccount->fresh()->current_balance);
    }

    public function test_unbalanced_transaction_throws_exception_and_writes_no_rows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unbalanced double-entry transaction');

        $assetAccount = $this->ledgerService->getSystemAccount('TEST-ASSET-02', 'Test Asset 2', 'ASSET');
        $liabilityAccount = $this->ledgerService->getSystemAccount('TEST-LIAB-02', 'Test Liability 2', 'LIABILITY');

        $this->ledgerService->postTransaction(
            referenceType: 'UNBALANCED_TRANSFER',
            referenceId: 'REF-FAIL',
            currency: 'THB',
            entries: [
                [
                    'account_id' => $assetAccount->id,
                    'direction' => 'DEBIT',
                    'amount' => '500.0000',
                ],
                [
                    'account_id' => $liabilityAccount->id,
                    'direction' => 'CREDIT',
                    'amount' => '400.0000', // Unbalanced: 500 != 400
                ],
            ]
        );

        $this->assertEquals(0, LedgerTransaction::count());
        $this->assertEquals(0, LedgerEntry::count());
    }

    public function test_cryptographic_sha256_hash_chain_is_linked_correctly(): void
    {
        $acc1 = $this->ledgerService->getSystemAccount('CHAIN-ACC-1', 'Chain 1', 'ASSET');
        $acc2 = $this->ledgerService->getSystemAccount('CHAIN-ACC-2', 'Chain 2', 'REVENUE');

        $tx1 = $this->ledgerService->postTransaction(
            referenceType: 'CHAIN_TX_1',
            referenceId: 'REF-001',
            currency: 'THB',
            entries: [
                ['account_id' => $acc1->id, 'direction' => 'DEBIT', 'amount' => '100.0000'],
                ['account_id' => $acc2->id, 'direction' => 'CREDIT', 'amount' => '100.0000'],
            ]
        );

        $tx2 = $this->ledgerService->postTransaction(
            referenceType: 'CHAIN_TX_2',
            referenceId: 'REF-002',
            currency: 'THB',
            entries: [
                ['account_id' => $acc1->id, 'direction' => 'DEBIT', 'amount' => '200.0000'],
                ['account_id' => $acc2->id, 'direction' => 'CREDIT', 'amount' => '200.0000'],
            ]
        );

        $this->assertEquals(str_repeat('0', 64), $tx1->previous_hash);
        $this->assertEquals($tx1->hash, $tx2->previous_hash);
        $this->assertNotEmpty($tx2->hash);
    }
}
