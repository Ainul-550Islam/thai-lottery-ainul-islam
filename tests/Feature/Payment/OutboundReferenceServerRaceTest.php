<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Models\Payment;
use App\Services\Payment\PayoutTransferReferenceService;
use App\Services\Payment\PayoutTransferService;
use App\Services\Payment\WithdrawalProviderReferenceService;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesExternalPayout;

final class OutboundReferenceServerRaceTest extends PaymentTestCase
{
    use CreatesExternalPayout;

    public function test_server_prize_and_withdrawal_wait_on_the_same_common_prize_owner(): void
    {
        $this->requirePayoutServerRace();
        $f = $this->externalFixture();
        $w = $this->approvedReferenceWithdrawal();
        app(PayoutTransferService::class)->transfer($f['data']);
        $reference = 'SYNTHETIC-COMMON-PRIZE-RACE';
        DB::transaction(fn () => app(PayoutTransferReferenceService::class)->bind($f['row']->fresh(), Payment::query()->sole(), $reference));
        $commands = [['payout_id' => $f['row']->id, 'actor_id' => $f['reviewer']->id, 'reference' => $reference],
            ['action' => 'withdrawal', 'withdrawal_id' => $w['row']->id, 'payout_id' => $f['row']->id, 'actor_id' => $f['reviewer']->id, 'reference' => $reference]];
        $out = $this->payoutServerRace($commands, 'outbound_provider_references', (int) DB::table('outbound_provider_references')->sole()->id);
        $this->assertSame(['completed', 'refused'], $out);
        $this->assertSame('completed', $f['row']->fresh()->status->value);
        $this->assertSame('approved', $w['row']->fresh()->status->value);
        $this->assertSame('1000.00', (string) $w['wallet']->fresh()->balance);
        $this->assertSame('400.00', (string) $w['wallet']->fresh()->locked_balance);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertDatabaseCount('outbound_provider_references', 1);
    }

    public function test_server_withdrawal_and_prize_wait_on_the_same_common_withdrawal_owner(): void
    {
        $this->requirePayoutServerRace();
        $w = $this->approvedReferenceWithdrawal();
        $reference = 'SYNTHETIC-COMMON-WITHDRAWAL-RACE';
        DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($w['row'], 'bank_transfer', $reference));
        $f = $this->externalFixture();
        app(PayoutTransferService::class)->transfer($f['data']);
        $commands = [['action' => 'withdrawal', 'withdrawal_id' => $w['row']->id, 'payout_id' => $f['row']->id, 'actor_id' => $f['reviewer']->id, 'reference' => $reference],
            ['payout_id' => $f['row']->id, 'actor_id' => $f['reviewer']->id, 'reference' => $reference]];
        $out = $this->payoutServerRace($commands, 'outbound_provider_references', (int) DB::table('outbound_provider_references')->sole()->id);
        $this->assertSame(['completed', 'refused'], $out);
        $this->assertSame('processing', $f['row']->fresh()->status->value);
        $this->assertSame('completed', $w['row']->fresh()->status->value);
        $this->assertSame('600.00', (string) $w['wallet']->fresh()->balance);
        $this->assertSame('0.00', (string) $w['wallet']->fresh()->locked_balance);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertDatabaseCount('outbound_provider_references', 1);
    }
}
