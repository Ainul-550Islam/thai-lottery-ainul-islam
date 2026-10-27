<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;
use App\Services\Payment\FinancialProviderReferenceService;
use App\Services\Payment\PayoutTransferReferenceService;
use App\Services\Payment\PayoutTransferService;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesExternalPayout;

final class DepositReferenceServerRaceTest extends PaymentTestCase
{
    use CreatesExternalPayout;

    private function approvedInbound(): array
    {
        $f = $this->createPlayer('100.00');
        $f['row'] = app(DepositService::class)->request($f['wallet'], Money::of('400.00', Currency::THB), PaymentMethod::BankTransfer);
        app(DepositApprovalService::class)->approve($f['row']);
        $f['row']->refresh();

        return $f;
    }

    public function test_server_two_deposits_directly_wait_on_one_original_global_reference_identity(): void
    {
        $this->requirePayoutServerRace();
        $a = $this->approvedInbound();
        $b = $this->approvedInbound();
        $this->assertNotSame($a['wallet']->id, $b['wallet']->id);
        $f = $this->externalFixture();
        $reference = 'SYNTHETIC-INBOUND-ONLY-RACE';
        DB::transaction(fn () => app(FinancialProviderReferenceService::class)->bind($a['row'], 'bank_transfer', 'legacy-primary', $reference, null));
        $base = ['action' => 'deposit', 'payout_id' => $f['row']->id, 'actor_id' => $f['reviewer']->id, 'reference' => $reference];
        $out = $this->payoutServerRace([$base + ['deposit_id' => $a['row']->id], $base + ['deposit_id' => $b['row']->id]],
            FinancialProviderReferenceService::TABLE, (int) DB::table(FinancialProviderReferenceService::TABLE)->sole()->id);
        $this->assertSame(['credited', 'refused'], $out);
        $this->assertSame('500.00', $a['wallet']->fresh()->balance);
        $this->assertSame('100.00', $b['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertDatabaseCount(FinancialProviderReferenceService::TABLE, 1);
        $this->assertTrue(app(DepositCompletionService::class)->complete($a['row']->fresh())['replayed']);
    }

    public function test_server_inbound_owner_blocks_external_payout_on_the_same_decisive_global_identity(): void
    {
        $this->requirePayoutServerRace();
        $a = $this->approvedInbound();
        $f = $this->externalFixture();
        app(PayoutTransferService::class)->transfer($f['data']);
        $reference = 'SYNTHETIC-INBOUND-OUTBOUND-RACE';
        DB::transaction(fn () => app(FinancialProviderReferenceService::class)->bind($a['row'], 'bank_transfer', 'legacy-primary', $reference, null));
        $base = ['payout_id' => $f['row']->id, 'actor_id' => $f['reviewer']->id, 'reference' => $reference];
        $out = $this->payoutServerRace([$base + ['action' => 'deposit', 'deposit_id' => $a['row']->id], $base],
            FinancialProviderReferenceService::TABLE, (int) DB::table(FinancialProviderReferenceService::TABLE)->sole()->id);
        $this->assertSame(['credited', 'refused'], $out);
        $this->assertSame('500.00', $a['wallet']->fresh()->balance);
        $this->assertSame('processing', $f['row']->fresh()->status->value);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('outbound_provider_references', 0);
        $this->assertDatabaseCount(FinancialProviderReferenceService::TABLE, 1);
    }

    public function test_server_outbound_owner_blocks_inbound_credit_on_the_same_decisive_global_identity(): void
    {
        $this->requirePayoutServerRace();
        $a = $this->approvedInbound();
        $f = $this->externalFixture();
        app(PayoutTransferService::class)->transfer($f['data']);
        $reference = 'SYNTHETIC-OUTBOUND-INBOUND-RACE';
        DB::transaction(fn () => app(PayoutTransferReferenceService::class)->bind($f['row']->fresh(), Payment::query()->sole(), $reference));
        $base = ['payout_id' => $f['row']->id, 'actor_id' => $f['reviewer']->id, 'reference' => $reference];
        $out = $this->payoutServerRace([$base, $base + ['action' => 'deposit', 'deposit_id' => $a['row']->id]],
            FinancialProviderReferenceService::TABLE, (int) DB::table(FinancialProviderReferenceService::TABLE)->sole()->id);
        $this->assertSame(['completed', 'refused'], $out);
        $this->assertSame('100.00', $a['wallet']->fresh()->balance);
        $this->assertSame('completed', $f['row']->fresh()->status->value);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertDatabaseCount(FinancialProviderReferenceService::TABLE, 1);
    }
}
