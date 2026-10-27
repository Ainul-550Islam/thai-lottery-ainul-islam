<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Services\Payment\PayoutTransferService;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesExternalPayout;

final class PayoutEvidenceServerRaceTest extends PaymentTestCase
{
    use CreatesExternalPayout;

    public function test_server_conflicting_final_results_consume_original_archive_only_once(): void
    {
        $this->requirePayoutServerRace();
        $f = $this->externalFixture();
        app(PayoutTransferService::class)->transfer($f['data']);
        $base = ['payout_id' => (int) $f['row']->id, 'actor_id' => (int) $f['reviewer']->id, 'reference' => 'SYNTHETIC-ARCHIVE-RACE'];
        $out = $this->payoutServerRace([$base, array_merge($base, ['action' => 'refusal'])], 'payouts', (int) $f['row']->id);
        sort($out);
        $this->assertContains($out, [['completed', 'refused'], ['failed', 'refused']]);
        $row = $f['row']->fresh();
        $proof = DB::table('payout_transfer_evidence')->sole();
        $this->assertSame($row->status->value, $proof->outcome);
        $this->assertSame((int) $f['reviewer']->id, (int) $proof->consumed_by);
        $this->assertNotNull($proof->consumed_at);
        $this->assertSame($f['evidence_id'], $row->metadata['transfer']['evidence_id']);
        $this->assertDatabaseCount('financial_transactions', $row->status->value === 'completed' ? 1 : 0);
        $this->assertDatabaseCount('ledger_entries', $row->status->value === 'completed' ? 2 : 0);
        $this->assertSame('1000.00', (string) $f['owner']['wallet']->fresh()->balance);
        $this->assertDatabaseCount('payout_transfer_evidence', 1);
    }
}
