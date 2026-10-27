<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Exceptions\FinancialException;
use App\Exceptions\PayoutTransferException;
use App\Models\LedgerAccount;
use App\Services\Payment\PayoutEvidenceService;
use App\Services\Payment\PayoutEvidenceStore;
use App\Services\Payment\PayoutTransferService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesExternalPayout;

final class PayoutEvidenceWorkflowTest extends PaymentTestCase
{
    use CreatesExternalPayout;

    public function test_no_archive_is_a_domain_refusal_even_with_human_verified_boolean(): void
    {
        $f = $this->externalFixture();
        DB::table('payout_transfer_evidence_reads')->delete();
        DB::table('payout_transfer_evidence')->delete();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);
        $this->expectException(FinancialException::class);
        try {
            $service->complete($f['data'], 'SYNTHETIC-NO-ARCHIVE', $f['reviewer'], true, 'Human assertion alone is not an archive.');
        } finally {
            $this->assertDatabaseCount('financial_transactions', 0);
            $this->assertSame('processing', $f['row']->fresh()->status->value);
        }
    }

    public function test_reviewer_must_have_recent_audited_read_of_exact_file(): void
    {
        $f = $this->externalFixture();
        DB::table('payout_transfer_evidence_reads')->update(['created_at' => now()->subDays(2)]);
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);
        try {
            $service->complete($f['data'], 'SYNTHETIC-READ-REQUIRED', $f['reviewer'], true, 'A stale archive inspection is not sufficient.');
            $this->fail('Stale inspection must be refused.');
        } catch (FinancialException) {
            $this->assertDatabaseCount('financial_transactions', 0);
        }
        app(PayoutEvidenceService::class)->read($f['reviewer'], $f['row']->id, $f['evidence_id']);
        $service->complete($f['data'], 'SYNTHETIC-READ-REQUIRED', $f['reviewer'], true, 'Current independent archive inspection recorded.');
        $this->assertDatabaseCount('financial_transactions', 1);
    }

    public function test_uploader_cannot_review_their_own_archive(): void
    {
        $f = $this->externalFixture();
        $service = app(PayoutEvidenceService::class);
        $e = $service->upload($f['reviewer'], $f['row']->id, UploadedFile::fake()->createWithContent('note.txt', 'SYNTHETIC SELF-UPLOADED ARCHIVE'), 'Synthetic uploader independence test.');
        $service->read($f['reviewer'], $f['row']->id, $e['id']);
        app(PayoutTransferService::class)->transfer($f['data']);
        $this->expectException(FinancialException::class);
        app(PayoutTransferService::class)->complete($f['data'], 'SYNTHETIC-SELF-REVIEW', $f['reviewer'], true, 'Uploader cannot verify their own archive.', $e['id']);
    }

    public function test_corrupt_encrypted_bytes_hold_finalization(): void
    {
        $f = $this->externalFixture();
        $e = DB::table('payout_transfer_evidence')->find($f['evidence_id']);
        file_put_contents(storage_path('app/private/payout-evidence/'.$e->blob_key), 'CORRUPT SYNTHETIC CIPHERTEXT');
        app(PayoutTransferService::class)->transfer($f['data']);
        $this->expectException(FinancialException::class);
        try {
            app(PayoutTransferService::class)->complete($f['data'], 'SYNTHETIC-CORRUPT', $f['reviewer'], true, 'Corrupt archival bytes cannot authorize finality.');
        } finally {
            $this->assertDatabaseCount('financial_transactions', 0);
            $this->assertNull(DB::table('payout_transfer_evidence')->value('consumed_at'));
        }
    }

    public function test_accounting_failure_rolls_back_evidence_consumption_and_reference_ownership(): void
    {
        $f = $this->externalFixture();
        app(PayoutTransferService::class)->transfer($f['data']);
        LedgerAccount::query()->update(['is_active' => false]);
        $this->expectException(FinancialException::class);
        try {
            app(PayoutTransferService::class)->complete($f['data'], 'SYNTHETIC-ATOMIC-PROOF', $f['reviewer'], true, 'Proof consumption must share canonical transaction.');
        } finally {
            $this->assertNull(DB::table('payout_transfer_evidence')->value('consumed_at'));
            $this->assertDatabaseCount('outbound_provider_references', 0);
            $this->assertDatabaseCount('ledger_entries', 0);
        }
    }

    public function test_success_attaches_one_archive_and_replay_does_not_consume_again(): void
    {
        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);
        $first = $service->complete($f['data'], 'SYNTHETIC-ATTACHED', $f['reviewer'], true, 'Independent archived original result inspection.');
        $before = (array) DB::table('payout_transfer_evidence')->sole();
        $again = $service->complete($f['data'], 'SYNTHETIC-ATTACHED', $f['reviewer'], true, 'Independent archived original result inspection.');
        $this->assertSame($first, $again);
        $this->assertSame($f['evidence_id'], $first['evidence_id']);
        $this->assertSame($before, (array) DB::table('payout_transfer_evidence')->sole());
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertSame('1000.00', (string) $f['owner']['wallet']->fresh()->balance);
    }

    public function test_ambiguous_archive_selection_cannot_default_to_latest(): void
    {
        $f = $this->externalFixture();
        app(PayoutEvidenceService::class)->upload($f['maker'], $f['row']->id, UploadedFile::fake()->createWithContent('two.txt', 'SECOND SYNTHETIC DOCUMENT'), 'A second original archive is deliberately ambiguous.');
        app(PayoutTransferService::class)->transfer($f['data']);
        $this->expectException(FinancialException::class);
        app(PayoutTransferService::class)->complete($f['data'], 'SYNTHETIC-AMBIGUOUS', $f['reviewer'], true, 'An exact inspected archive must be selected.');
    }

    public function test_final_refusal_consumes_archive_without_ledger_or_fresh_attempt(): void
    {
        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);
        $out = $service->fail($f['data'], 'Independent definitive refusal with inspected evidence.', $f['reviewer'], true, $f['evidence_id']);
        $this->assertSame('failed', $out['lane_status']);
        $this->assertSame('failed', DB::table('payout_transfer_evidence')->value('outcome'));
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertSame($out, $service->transfer($f['data']));
    }

    public function test_final_replay_cannot_replace_evidence(): void
    {
        $f = $this->externalFixture();
        $other = $this->archiveSyntheticPayoutEvidence($f['row'], $f['maker'], $f['reviewer']);
        $s = app(PayoutTransferService::class);
        $s->transfer($f['data']);
        $s->complete($f['data'], 'SYNTHETIC-IMMUTABLE-ARCHIVE', $f['reviewer'], true, 'Independent original archive selected explicitly.', $f['evidence_id']);
        $this->expectException(PayoutTransferException::class);
        $s->complete($f['data'], 'SYNTHETIC-IMMUTABLE-ARCHIVE', $f['reviewer'], true, 'An archived final result cannot replace its source.', $other);
    }

    public function test_plaintext_is_not_stored_and_retained_objects_cannot_be_discarded(): void
    {
        $f = $this->externalFixture();
        $e = DB::table('payout_transfer_evidence')->sole();
        $path = storage_path('app/private/payout-evidence/'.$e->blob_key);
        $this->assertStringNotContainsString('SYNTHETIC FINAL-RESULT', file_get_contents($path));
        app(PayoutEvidenceStore::class)->discardUncommitted($e->blob_key);
        $this->assertFileExists($path);
        $this->assertStringContainsString('SYNTHETIC FINAL-RESULT', app(PayoutEvidenceService::class)->read($f['reviewer'], $f['row']->id, $e->id));
    }
}
