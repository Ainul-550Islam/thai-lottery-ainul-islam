<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Throwable;

final class WebhookClaimMigrationRollbackTest extends FinancialMasterTestCase
{
    public function test_claim_index_is_removed_before_columns_and_recreated_without_rewriting_original_event(): void
    {
        $id = DB::table('payment_webhooks')->insertGetId([
            'webhook_key' => hash('sha256', 'SYNTHETIC-LEASE-ROLLBACK'), 'provider' => 'stripe',
            'event_id' => 'evt_SYNTHETICROLLBACK', 'event_type' => 'test.synthetic',
            'payload' => '{"synthetic":true}', 'payload_fingerprint' => hash('sha256', '{"synthetic":true}'),
            'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            'claimed_at' => now(), 'processing_attempts' => 2, 'last_error_at' => now(),
        ]);
        $before = (array) DB::table('payment_webhooks')->find($id);
        foreach (['claimed_at', 'processing_attempts', 'last_error_at'] as $c) {
            unset($before[$c]);
        }
        $migration = require base_path('database/migrations/2026_10_06_000300_add_payment_webhook_claim_state.php');
        $this->assertTrue(Schema::hasIndex('payment_webhooks', ['claimed_at']));
        $error = null;
        try {
            $migration->down();
        } catch (Throwable $e) {
            $error = $e::class;
        }
        $this->assertNull($error, 'A legitimate claim-state schema rollback must not fail on its own index.');
        foreach (['claimed_at', 'processing_attempts', 'last_error_at'] as $c) {
            $this->assertFalse(Schema::hasColumn('payment_webhooks', $c));
        }
        $this->assertFalse(Schema::hasIndex('payment_webhooks', ['claimed_at']));
        $this->assertSame($before, (array) DB::table('payment_webhooks')->find($id));
        $migration->up();
        $after = (array) DB::table('payment_webhooks')->find($id);
        $this->assertTrue(Schema::hasIndex('payment_webhooks', ['claimed_at']));
        $this->assertNull($after['claimed_at']);
        $this->assertSame(0, (int) $after['processing_attempts']);
        $this->assertNull($after['last_error_at']);
        foreach (['claimed_at', 'processing_attempts', 'last_error_at'] as $c) {
            unset($after[$c]);
        }
        $this->assertSame($before, $after);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_kyc_extension_indexes_are_removed_before_columns_and_original_document_is_preserved(): void
    {
        $user = User::factory()->create();
        $id = DB::table('kyc_documents')->insertGetId(['user_id' => $user->id, 'document_type' => 'passport',
            'document_number' => 'SYNTHETIC-INDEX-DOCUMENT', 'file_path' => 'private/synthetic-index-document',
            'original_filename' => 'SYNTHETIC.txt', 'mime_type' => 'text/plain', 'file_size' => 1,
            'document_fingerprint' => hash('sha256', 'SYNTHETIC-INDEX-DOCUMENT'), 'issuer_country' => 'TH',
            'verification_reference' => 'SYNTHETIC-INDEX-REFERENCE', 'expires_at' => now()->addYear(),
            'created_at' => now(), 'updated_at' => now()]);
        $columns = ['verification_status', 'issuer_country', 'document_fingerprint', 'verification_reference', 'expires_at'];
        $before = (array) DB::table('kyc_documents')->find($id);
        foreach ($columns as $c) {
            unset($before[$c]);
        }
        $migration = require base_path('database/migrations/2024_02_01_001900_extend_kyc_documents_for_verification_lane.php');
        $error = null;
        try {
            $migration->down();
        } catch (Throwable $e) {
            $error = $e::class;
        }
        $this->assertNull($error, 'KYC extension rollback must remove its own indexes before its columns.');
        foreach ($columns as $c) {
            $this->assertFalse(Schema::hasColumn('kyc_documents', $c));
        }
        $this->assertSame($before, (array) DB::table('kyc_documents')->find($id));
        $migration->up();
        $after = (array) DB::table('kyc_documents')->find($id);
        foreach (['verification_status', 'document_fingerprint', 'verification_reference', 'expires_at'] as $c) {
            $this->assertTrue(Schema::hasIndex('kyc_documents', [$c]));
        }
        $this->assertTrue(Schema::hasIndex('kyc_documents', ['user_id', 'document_fingerprint']));
        $this->assertSame('uploaded', $after['verification_status']);
        foreach (['issuer_country', 'document_fingerprint', 'verification_reference', 'expires_at'] as $c) {
            $this->assertNull($after[$c]);
        }
        foreach ($columns as $c) {
            unset($after[$c]);
        }
        $this->assertSame($before, $after);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }
}
