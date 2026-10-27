<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Security\FieldEncryptionService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ReencryptEncryptedFieldsCommandTest extends TestCase
{
    use DatabaseTruncation;

    private const OLD_KEY = 'legacy-application-key-for-rotation-tests';

    private const NEW_KEY = 'new-dedicated-field-key-for-rotation-tests';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => self::OLD_KEY,
            'app.field_encryption_key' => self::NEW_KEY,
            'app.field_encryption_previous_keys' => [],
        ]);
    }

    public function test_dry_run_changes_nothing_then_real_run_is_idempotent_and_audited(): void
    {
        $old = new FieldEncryptionService(self::OLD_KEY);
        $new = new FieldEncryptionService(self::NEW_KEY);
        $legacyPayload = $old->encrypt('1234567890123');
        $document = $this->document($legacyPayload);

        $this->assertSame(0, Artisan::call('security:reencrypt-fields', [
            '--from-app-key' => true,
            '--dry-run' => true,
            '--chunk' => 1,
        ]));
        $this->assertSame('scanned=1 rotated=1 skipped=0'.PHP_EOL, Artisan::output());
        $this->assertSame($legacyPayload, $document->fresh()->document_number);
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::ConfigChange)->count());

        $this->assertSame(0, Artisan::call('security:reencrypt-fields', [
            '--from-app-key' => true,
            '--chunk' => 1,
        ]));
        $this->assertSame('scanned=1 rotated=1 skipped=0'.PHP_EOL, Artisan::output());
        $rotated = $document->fresh()->document_number;
        $this->assertNotSame($legacyPayload, $rotated);
        $this->assertSame('1234567890123', $new->decrypt($rotated));
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::ConfigChange)->count());

        $this->assertSame(0, Artisan::call('security:reencrypt-fields', [
            '--from-app-key' => true,
            '--chunk' => 1,
        ]));
        $this->assertSame('scanned=1 rotated=0 skipped=1'.PHP_EOL, Artisan::output());
        $this->assertSame($rotated, $document->fresh()->document_number);
        $this->assertSame(2, AuditLog::query()->where('action', AuditAction::ConfigChange)->count());
    }

    public function test_rotation_is_chunked_resumable_and_skips_values_already_on_the_new_key(): void
    {
        $old = new FieldEncryptionService(self::OLD_KEY);
        $new = new FieldEncryptionService(self::NEW_KEY);
        $first = $this->document($old->encrypt('1234567890123'));
        $second = $this->document($old->encrypt('9876543210987'));
        $alreadyNewPayload = $new->encrypt('5555555555555');
        $alreadyNew = $this->document($alreadyNewPayload);

        $this->assertSame(0, Artisan::call('security:reencrypt-fields', [
            '--from-app-key' => true,
            '--chunk' => 1,
        ]));
        $this->assertSame('scanned=3 rotated=2 skipped=1'.PHP_EOL, Artisan::output());
        $this->assertSame('1234567890123', $new->decrypt($first->fresh()->document_number));
        $this->assertSame('9876543210987', $new->decrypt($second->fresh()->document_number));
        $this->assertSame($alreadyNewPayload, $alreadyNew->fresh()->document_number);

        $this->assertSame(0, Artisan::call('security:reencrypt-fields', [
            '--from-app-key' => true,
            '--chunk' => 1,
        ]));
        $this->assertSame('scanned=3 rotated=0 skipped=3'.PHP_EOL, Artisan::output());
        $this->assertSame(2, AuditLog::query()->where('action', AuditAction::ConfigChange)->count());
    }

    public function test_production_rotation_refuses_without_explicit_backup_confirmation(): void
    {
        $old = new FieldEncryptionService(self::OLD_KEY);
        $legacyPayload = $old->encrypt('1234567890123');
        $document = $this->document($legacyPayload);
        $this->app['env'] = 'production';

        $this->assertSame(1, Artisan::call('security:reencrypt-fields', ['--from-app-key' => true]));
        $this->assertSame('scanned=0 rotated=0 skipped=0'.PHP_EOL, Artisan::output());
        $this->assertSame($legacyPayload, $document->fresh()->document_number);
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::ConfigChange)->count());
    }

    private function document(string $payload): KycDocument
    {
        return KycDocument::query()->create([
            'user_id' => User::factory()->create()->id,
            'document_type' => 'national_id',
            'document_number' => $payload,
            'file_path' => 'kyc/rotation-test.png',
            'original_filename' => 'rotation-test.png',
            'mime_type' => 'image/png',
            'file_size' => 128,
            'status' => 'pending',
        ]);
    }
}
