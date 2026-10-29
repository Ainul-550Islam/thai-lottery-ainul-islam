<?php

declare(strict_types=1);

namespace Tests\Feature\Payment\Slip;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Payment\PaymentTestCase;

/** The slip-to-scan foreign key must work on SQLite, MySQL and native MariaDB UUIDs. */
final class SlipScanRecordSchemaTest extends PaymentTestCase
{
    public function test_a_slip_references_a_real_scan_and_scan_deletion_nulls_the_reference(): void
    {
        $user = User::factory()->create();
        $scanId = (string) Str::uuid();
        DB::table('upload_scan_records')->insert([
            'id' => $scanId,
            'private_storage_key' => 'synthetic-slip/'.$scanId,
            'original_filename' => 'slip.jpg',
            'file_mime_type' => 'image/jpeg',
            'file_size_bytes' => 16,
            'file_sha256' => hash('sha256', 'synthetic-slip'),
            'scan_status' => 'clean',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $slipId = DB::table('deposit_slip_verifications')->insertGetId([
            'user_id' => $user->id,
            'provider' => 'fixture',
            'status' => 'pending',
            'upload_scan_record_id' => $scanId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame($scanId, (string) DB::table('deposit_slip_verifications')->where('id', $slipId)->value('upload_scan_record_id'));
        DB::table('upload_scan_records')->where('id', $scanId)->delete();
        $this->assertNull(DB::table('deposit_slip_verifications')->where('id', $slipId)->value('upload_scan_record_id'));
        $this->assertDatabaseHas('deposit_slip_verifications', ['id' => $slipId]);
    }

    public function test_a_slip_cannot_reference_a_missing_scan(): void
    {
        $user = User::factory()->create();
        try {
            DB::table('deposit_slip_verifications')->insert([
                'user_id' => $user->id,
                'provider' => 'fixture',
                'status' => 'pending',
                'upload_scan_record_id' => (string) Str::uuid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A nonexistent scan must not satisfy the foreign key.');
        } catch (QueryException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertDatabaseCount('deposit_slip_verifications', 0);
    }
}
