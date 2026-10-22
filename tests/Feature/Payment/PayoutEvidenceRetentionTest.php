<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Services\Payment\PayoutEvidenceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesExternalPayout;

final class PayoutEvidenceRetentionTest extends PaymentTestCase
{
    use CreatesExternalPayout;

    public function test_read_only_retention_audit_preserves_hold_and_does_not_print_private_data(): void
    {
        $f = $this->externalFixture();
        $before = (array) DB::table('payout_transfer_evidence')->sole();
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $this->assertSame(0, Artisan::call('payments:payout-evidence-audit', ['--verify-bytes' => true]));
        $text = Artisan::output();
        $out = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($out['read_only']);
        $this->assertTrue($out['items'][0]['bytes_verified']);
        $this->assertTrue($out['items'][0]['legal_hold']);
        $this->assertFalse($out['items'][0]['automatic_deletion_allowed']);
        foreach (['SYNTHETIC FINAL-RESULT', 'blob_key', 'sha256', 'facts_hash'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        $this->assertFalse(array_any($queries, fn ($sql) => preg_match('/^\s*(insert|update|delete|replace|alter|drop|create)\b/i', $sql) === 1));
        $this->assertSame($before, (array) DB::table('payout_transfer_evidence')->sole());
    }

    public function test_integrity_audit_reports_corruption_without_deleting_it(): void
    {
        $this->externalFixture();
        $e = DB::table('payout_transfer_evidence')->sole();
        $path = storage_path('app/private/payout-evidence/'.$e->blob_key);
        file_put_contents($path, 'CORRUPT');
        $this->assertSame(0, Artisan::call('payments:payout-evidence-audit', ['--verify-bytes' => true]));
        $out = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertContains('private_object_missing_or_corrupt', $out['items'][0]['issues']);
        $this->assertFileExists($path);
        $this->assertDatabaseCount('payout_transfer_evidence', 1);
    }

    public function test_populated_evidence_migration_cannot_destroy_history(): void
    {
        $this->externalFixture();
        $migration = require base_path('database/migrations/2026_10_15_000600_create_payout_transfer_evidence_tables.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_retained_deleted_payout_evidence_can_be_read_by_current_authorized_staff(): void
    {
        $f = $this->externalFixture();
        $f['row']->delete();
        $bytes = app(PayoutEvidenceService::class)->read($f['reviewer'], $f['row']->id, $f['evidence_id']);
        $this->assertStringContainsString('SYNTHETIC FINAL-RESULT', $bytes);
        $this->assertDatabaseCount('payout_transfer_evidence', 1);
    }

    public function test_retention_cursor_paginates_without_resetting_or_deleting(): void
    {
        $this->externalFixture();
        $this->externalFixture();
        $this->assertSame(0, Artisan::call('payments:payout-evidence-audit', ['--limit' => '1']));
        $first = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($first['has_more']);
        $this->assertSame(0, Artisan::call('payments:payout-evidence-audit', ['--limit' => '1', '--after' => (string) $first['next_after']]));
        $second = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($second['has_more']);
        $this->assertNotSame($first['items'][0]['id'], $second['items'][0]['id']);
        $this->assertDatabaseCount('payout_transfer_evidence', 2);
    }
}
